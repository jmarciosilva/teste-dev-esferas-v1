# Problema 2 — Catálogo de Produtos sem cache: causa raiz e ecossistema do problema

Esse documento começa do mesmo jeito que o `analise-problema01.md` terminou:
como material de consulta, não como diário solto. A diferença é que aqui
estou escrevendo **antes** de implementar qualquer coisa — assim como fiz
com o `analise-roadmap.md` lá no início do Problema 1. A estrutura já nasce
organizada (contexto → causa raiz → o que o enunciado pede → decisões a
tomar), e as seções de implementação/validação/testes/trade-offs vão sendo
preenchidas conforme a Fase 2 avança, exatamente como aconteceu no Problema
1 (que começou como diário e só ganhou a forma final depois de tudo pronto).

---

## 1. Contexto

**Página:** `/catalogo` — lista até 200 produtos, com nome, categoria,
preço, estoque, avaliação média (com contagem de reviews) e quantidade
vendida. Tem um filtro de categoria (`?category=`) e, em cada linha, um
formulário "Salvar" que atualiza preço/estoque daquele produto.

**Endpoint de escrita:** `POST /produtos/{id}` — atualiza `price`/`stock` de
um produto específico no Postgres.

**Massa de dados envolvida (seed padrão):**

| Tabela | Linhas | Papel no catálogo |
|---|---|---|
| `products` | 3.000 | listagem base (nome, categoria, preço, estoque) |
| `product_reviews` | 60.000 | origem de `avg_rating` / `reviews_count` |
| `order_items` | 499.524 | origem de `total_sold` |

**Categorias existentes:** Beleza, Brinquedos, Casa, Eletrônicos, Esporte,
Livros, Mercado, Moda (8 no total).

**O que já existe pronto no projeto:**

- `src/app/RedisClient.php` — uma classe de conexão com o Redis (via
  extensão `redis` do PHP), **já configurada e disponível**, mas **não
  usada em lugar nenhum** do fluxo de leitura ainda.
- O botão "Salvar" do catálogo **não recarrega a página**. Olhando
  `src/public/assets/app.js`, o clique dispara um `fetch()` via AJAX pro
  `POST /produtos/{id}` e só troca um texto de feedback (“Produto
  atualizado...”) — a tabela do catálogo em si não é atualizada na tela.
  Isso importa pra estratégia de invalidação: o requisito não é "a resposta
  do POST tem que trazer o catálogo atualizado", é "a **próxima vez que a
  página `/catalogo` for carregada** (F5, navegação, nova aba) tem que
  refletir a mudança, sem esperar o TTL do cache expirar".

**Ferramentas usadas nesta investigação e na implementação:**

- **`psql`** — rodar a query de agregação isolada com `EXPLAIN ANALYZE` pra
  medir o custo real, com e sem filtro de categoria.
- **`redis-cli`** (`GET`, `KEYS`, `TTL`, `DEL`, `FLUSHALL`, `INFO`) —
  inspecionar o conteúdo cacheado durante o desenvolvimento, confirmar
  hit/miss, medir TTL restante, e forçar cenários de teste (cache vazio,
  chave específica ausente).
- **`curl`** — medir tempo de resposta do `GET /catalogo` (com e sem
  cache) e testar o `POST /produtos/{id}` diretamente, sem depender do
  formulário na tela.
- **`docker compose stop redis` / `start redis`** — simular queda do Redis
  de propósito, pra testar se a aplicação degrada graciosamente ou quebra.
- **Playwright** (headless Chromium via Node.js) — automatizar o fluxo real
  no navegador (editar produto, abrir modal, fechar modal, conferir se a
  linha da tabela atualizou), capturar screenshots pra validação visual, e
  checar o console por erros de JS.
- **Navegador manual (Firefox)** — o mesmo fluxo, mas testado à mão, pra
  confirmar que bate com o que a automação mostrou.
- **`php -l`** — lint de sintaxe em todo arquivo PHP alterado.

---

## 2. Causa raiz, explicada em profundidade

### 2.1 O conceito: computação redundante numa leitura que não muda a cada request

Esse problema é de uma família diferente do Problema 1. Lá, o gargalo era um
**padrão de acesso errado** (N+1 — muitas idas ao banco desnecessárias).
Aqui, a query em si **não está mal escrita** — ela faz o que precisa fazer,
uma única vez, com `JOIN`s e `GROUP BY` corretos. O problema é **outro**:
ela roda **a cada requisição**, recalculando do zero agregados que não
mudam de um segundo pro outro (nota média de um produto e quantidade
vendida não mudam a cada clique de usuário — mudam ao longo de horas/dias,
via novas avaliações e novos pedidos).

Isso é um caso clássico pra uma estratégia de **cache de leitura**: se o
dado é caro de calcular e não muda com frequência, calcula uma vez, guarda o
resultado em algum lugar rápido de ler (Redis, nesse caso), e serve as
próximas requisições a partir dali — só recalculando quando o dado
realmente mudar ou quando um tempo razoável passar.

### 2.2 Onde isso acontece no código

```php
// src/app/Controllers/CatalogController.php — fetchCatalog() (ATUAL, sem cache)
private function fetchCatalog(?string $category): array
{
    $pdo = Database::connection();

    $sql = '
        SELECT
            p.id, p.name, p.category, p.price, p.stock,
            COALESCE(rv.avg_rating, 0) AS avg_rating,
            COALESCE(rv.reviews_count, 0) AS reviews_count,
            COALESCE(sales.total_sold, 0) AS total_sold
        FROM products p
        LEFT JOIN (
            SELECT product_id, AVG(rating) AS avg_rating, COUNT(*) AS reviews_count
            FROM product_reviews
            GROUP BY product_id
        ) rv ON rv.product_id = p.id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS total_sold
            FROM order_items
            GROUP BY product_id
        ) sales ON sales.product_id = p.id
    ';

    $params = [];
    if ($category) {
        $sql .= ' WHERE p.category = :category';
        $params['category'] = $category;
    }

    $sql .= ' ORDER BY p.name LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}
```

Essa função é chamada em **todo** `GET /catalogo`, sem exceção — não existe
nenhuma camada entre a requisição HTTP e o banco.

### 2.3 Evidência real: `EXPLAIN ANALYZE` da query atual

<details>
<summary>Ver plano de execução completo — sem filtro de categoria (clique pra expandir)</summary>

```
Limit  (cost=9900.96..9901.46 rows=200 width=84) (actual time=90.386..90.519 rows=200 loops=1)
   ->  Sort  (cost=9900.96..9908.46 rows=3000 width=84) (actual time=90.384..90.508 rows=200 loops=1)
         Sort Key: p.name
         ->  Hash Left Join  (cost=9688.02..9771.30 rows=3000 width=84) (actual time=86.698..89.037 rows=3000 loops=1)
               Hash Cond: (p.id = sales.product_id)
               ->  Hash Right Join  (cost=1626.50..1701.89 rows=3000 width=76) (actual time=14.824..16.556 rows=3000 loops=1)
                     Hash Cond: (product_reviews.product_id = p.id)
                     ->  HashAggregate  (cost=1530.00..1567.50 rows=3000 width=44) (actual time=13.931..15.031 rows=3000 loops=1)
                           Group Key: product_reviews.product_id
                           ->  Seq Scan on product_reviews  (cost=0.00..1080.00 rows=60000 width=6) (actual time=0.004..2.826 rows=60000 loops=1)
                     ->  Hash  (cost=59.00..59.00 rows=3000 width=36) (actual time=0.869..0.869 rows=3000 loops=1)
                           ->  Seq Scan on products p  (cost=0.00..59.00 rows=3000 width=36) (actual time=0.009..0.311 rows=3000 loops=1)
               ->  Hash  (cost=8024.02..8024.02 rows=3000 width=12) (actual time=71.839..71.953 rows=3000 loops=1)
                     ->  Subquery Scan on sales  (cost=7964.02..8024.02 rows=3000 width=12) (actual time=70.874..71.546 rows=3000 loops=1)
                           ->  Finalize HashAggregate  (cost=7964.02..7994.02 rows=3000 width=12) (actual time=70.872..71.331 rows=3000 loops=1)
                                 Group Key: order_items.product_id
                                 ->  Gather  (cost=7304.02..7934.02 rows=6000 width=12) (actual time=66.905..68.283 rows=9000 loops=1)
                                       Workers Planned: 2
                                       Workers Launched: 2
                                       ->  Partial HashAggregate (actual time=60.943..61.409 rows=3000 loops=3)
                                             Group Key: order_items.product_id
                                             ->  Parallel Seq Scan on order_items (actual time=0.016..14.879 rows=166508 loops=3)
 Planning Time: 1.543 ms
 Execution Time: 91.242 ms
```

</details>

**O que isso mostra:** ~91ms de execução, e o grosso do custo
(`Gather`/`Parallel Seq Scan on order_items`, ~66-68ms dos 91ms) é varrer as
**499.524 linhas** de `order_items` **inteiras**, toda vez, só pra somar
`quantity` agrupado por produto — mesmo que a página só mostre 200 produtos
e mesmo que ninguém tenha comprado nada nos últimos 5 segundos.

### 2.4 Achado importante: o filtro de categoria não reduz o custo

Testei a mesma query filtrando por categoria (`WHERE p.category =
'Eletrônicos'`) — resultado: **~93ms, praticamente igual** à consulta sem
filtro. Olhando o plano, dá pra ver por quê: as duas subconsultas de
agregação (`product_reviews GROUP BY product_id` e `order_items GROUP BY
product_id`) **não recebem o filtro de categoria** — elas calculam pra
**todos os 3.000 produtos**, e só depois o `JOIN` final descarta as linhas
que não batem com a categoria pedida. O filtro reduz o resultado exibido,
não o trabalho de agregação.

**Por que isso importa pra fase de implementação:** do ponto de vista de
custo de banco, cachear "todas as categorias" ou cachear "uma categoria
específica" custa **a mesma coisa** pra gerar (a query inteira roda de
qualquer jeito). Isso deixa em aberto duas estratégias de chave de cache
igualmente viáveis — decisão que vou detalhar na seção 4.

### 2.5 Medição na página real (sem cache, como está hoje)

| Cenário | Tempo mostrado na página |
|---|---|
| Sem filtro (`/catalogo`) | 102-180 ms (5 execuções) |
| Com filtro (`/catalogo?category=Eletrônicos`) | 111-191 ms (3 execuções) |

Diferente do Problema 1, aqui o tempo **já está tecnicamente "aceitável"**
em termos absolutos (a própria view usa o limiar `elapsedMs < 100` pra
marcar como "fast", e a maioria das execuções fica pouco acima disso). O
problema que o desafio pede pra resolver **não é uma meta de tempo em ms**
— é a **falta de necessidade de recalcular isso a cada request**, com
Redis disponível e não utilizado. Ou seja, aqui o critério de sucesso é
mais sobre **estratégia** (chave, TTL, invalidação) do que sobre baixar um
número de milissegundos.

### 2.6 O endpoint de invalidação e o fluxo real do botão "Salvar"

```php
// src/app/Controllers/CatalogController.php — update() (ATUAL)
public function update(int $id): void
{
    $pdo = Database::connection();

    $price = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null;
    $stock = isset($_POST['stock']) && $_POST['stock'] !== '' ? (int) $_POST['stock'] : null;

    $stmt = $pdo->prepare('
        UPDATE products
        SET price = COALESCE(:price, price),
            stock = COALESCE(:stock, stock)
        WHERE id = :id
    ');
    $stmt->execute(['price' => $price, 'stock' => $stock, 'id' => $id]);

    header('Content-Type: application/json');
    echo json_encode(['message' => 'Produto atualizado...']);
}
```

Atualiza direto no Postgres, sem nenhuma referência ao cache — é aqui que
vai entrar a invalidação. E, como registrei na seção 1, o JS do botão
"Salvar" (`src/public/assets/app.js`) faz um `fetch()` que só troca uma
mensagem de feedback, **sem recarregar a tabela**. Então o requisito prático
é: assim que esse `POST` terminar, o **próximo** `GET /catalogo` (recarga de
página, nova aba, etc.) precisa vir com o preço/estoque atualizado — não
pode servir a versão em cache antiga até o TTL expirar.

---

## 3. O que o enunciado pede (recapitulando antes de decidir)

1. Cache-aside: leitura tenta o cache primeiro; em *miss*, consulta o banco
   e popula o cache com TTL razoável.
2. Invalidação correta no `POST /produtos/{id}` — sem esperar TTL expirar,
   sem servir dado desatualizado.
3. Pensar em como as chaves lidam com o filtro de categoria (`?category=`).
4. Não existe "resposta única certa" — o desafio avalia a **estratégia**
   (chave, TTL, invalidação) e a justificativa por trás dela.

---

## 4. Decisões de implementação — proposta concreta

Fechando as 4 perguntas da seção anterior, com o raciocínio por trás de cada
escolha. Isso ainda não foi implementado — é a proposta antes de mexer em
código, pro mesmo espírito de "documentar antes de codar" que guiou o
Problema 1.

### 4.1 Formato da chave de cache

**Proposta**: uma entrada por combinação de filtro, no formato

```
catalog:v1:products:{slug}
```

onde `{slug}` é o valor exato do parâmetro `category` recebido (o mesmo
texto que já vai pro `WHERE p.category = :category` hoje), ou o literal
`all` quando não há filtro. Ou seja, no máximo **9 chaves** (8 categorias +
"todas"), previsível e enumerável.

O `v1` no meio é um **prefixo de versão estático** (não dinâmico) — não tem
relação com a invalidação de conteúdo (isso é resolvido na seção 4.3), serve
só como válvula de escape manual: se um dia o **formato** do valor cacheado
mudar (por exemplo, eu adicionar um campo novo no array retornado), basta
trocar pra `v2` no código e as chaves antigas (com o shape velho) ficam
automaticamente órfãs, sem precisar limpar o Redis na mão.

**Por que não cachear um único blob "tudo" e filtrar em PHP?** Cheguei a
considerar — já que a seção 2.4 mostrou que filtrar por categoria não muda o
custo da query, dava pra cachear os 3.000 produtos agregados numa chave só e
filtrar por categoria em PHP (`array_filter` + `array_slice`), reduzindo pra
1 chave em vez de 9. Descartei por dois motivos: (a) o ganho é irrelevante
— 9 chaves de um catálogo de 3.000 produtos é uma fração mínima de memória
no Redis — e (b) essa abordagem move a responsabilidade de
filtrar/ordenar/limitar do SQL (onde já está testado e correto) pro PHP,
criando risco de sutil divergência de comportamento (ex.: empate na
ordenação por nome) só pra economizar uma otimização que não estava sendo
pedida. Prefiro a opção mais simples e com menor superfície de bug: uma
chave por combinação de filtro, mapeando 1:1 com a assinatura da função
`fetchCatalog(?string $category)` que já existe.

### 4.2 TTL

**Proposta**: **300 segundos (5 minutos)**.

O raciocínio: nesta aplicação, os dados agregados do catálogo
(`avg_rating`, `reviews_count`, `total_sold`) não têm **nenhum** caminho de
escrita — não existe endpoint que crie avaliação ou pedido. O único dado que
muda é `price`/`stock`, via `POST /produtos/{id}`, e isso vai ser resolvido
por **invalidação ativa** (seção 4.3), não por expiração de TTL. Ou seja, em
condições normais, o TTL quase nunca vai ser o mecanismo que "flagra" uma
mudança — ele é essencialmente uma **rede de segurança**, não o mecanismo
principal de atualização.

Por isso o TTL não precisa ser curto (tipo 30s, que forçaria recálculo
constante sem necessidade real). 5 minutos é um valor que:

- Reduz a carga no banco na prática (qualquer sequência de acessos dentro
  dessa janela usa o cache).
- Limita o "pior caso": se por qualquer motivo a invalidação ativa falhar
  (bug futuro, alguém mexer direto no banco fora da aplicação, etc.), o
  catálogo nunca fica desatualizado por mais que 5 minutos — um tempo
  razoável pra um catálogo de produtos (não é um preço de bolsa de valores
  mudando a cada segundo).

### 4.3 Estratégia de invalidação

Esse foi o ponto que mais valeu a pena pensar com calma, porque a primeira
ideia que veio à cabeça (invalidar as 9 chaves sempre que qualquer produto
for atualizado) é **desnecessariamente agressiva**.

**Insight**: atualizar o produto `X`, que pertence à categoria `C`, só pode
afetar **duas** visões cacheadas — a de "todas as categorias" (`all`, onde
`X` aparece) e a da própria categoria `C` (onde `X` também aparece). As
outras 7 categorias não têm `X` na lista delas, então invalidar essas chaves
seria desperdício (forçaria um recálculo completo de ~91ms pra um dado que
nem mudou).

**Proposta concreta**: no `CatalogController::update()`, trocar o
`UPDATE ... WHERE id = :id` por `UPDATE ... WHERE id = :id RETURNING
category` — o Postgres já devolve a categoria do produto atualizado na
mesma ida ao banco, sem precisar de um `SELECT` extra antes ou depois.  Com
isso em mãos, invalido só duas chaves:

```
DEL catalog:v1:products:all
DEL catalog:v1:products:{categoria-do-produto}
```

Vantagens dessa abordagem sobre as duas alternativas que eu tinha cogitado
antes (rastrear chaves ativas num `SET` à parte, ou usar um contador de
versão global):

- **Não precisa de estado extra no Redis** (nem `SET` de chaves ativas, nem
  contador) — a categoria do produto já diz exatamente quais chaves invalidar.
  Menos peças móveis, menos chance de a estrutura de rastreamento ficar
  dessincronizada do cache real.
  - Um contador de versão global, por comparação, invalidaria **todas** as
    9 combinações a cada update — igual de simples de implementar, porém
    mais agressivo do que o necessário.
- **Uma única ida ao banco** pro `UPDATE` (graças ao `RETURNING`), sem round
  trip adicional só pra descobrir a categoria.
- Só **2 comandos `DEL`** no Redis por atualização — custo desprezível.

**Suposição assumida (documentando com a mesma honestidade do Problema
1)**: essa invalidação de 2 chaves só é correta enquanto o `update()`
**não mudar a categoria do produto** — hoje ele só altera `price`/`stock`,
então a categoria de um produto nunca muda depois de criado, e a suposição
se sustenta. Se um dia existir um endpoint que também mude a categoria, a
invalidação precisaria contemplar **duas** categorias (a antiga e a nova),
não só uma — registro isso aqui pra não esquecer caso o escopo mude no
futuro.

### 4.4 Formato de serialização

**Proposta**: JSON, via `json_encode()`/`json_decode($json, true)`.

É o formato mais direto pro caso — o retorno de `fetchCatalog()` já é um
array de arrays associativos (exatamente o que `PDO::fetchAll()` devolve),
que mapeia 1:1 pra JSON sem transformação nenhuma. Vantagem extra: dá pra
inspecionar o conteúdo cacheado direto pelo `redis-cli` (`GET
catalog:v1:products:all`) durante o desenvolvimento/depuração, o que não
seria tão direto com `serialize()` binário do PHP.

**Ponto de atenção que já vale registrar**: diferente do
`ReportController`, o `CatalogController` **não faz cast manual** de
`price`/`avg_rating`/etc. pra `float` — esses valores saem do PDO como
string (comportamento padrão do driver `pgsql` pra colunas `NUMERIC`). Como
`json_encode`/`json_decode` preservam o valor exatamente como ele chega (uma
string continua string do outro lado), isso **não introduz nenhuma
divergência** entre servir do banco ou do cache — mas é um comportamento que
quero validar na prática assim que implementar, não só assumir no papel.

---

## 5. Implementação — código antes e depois

As 4 decisões da seção 4 foram implementadas em
`src/app/Controllers/CatalogController.php`.

### 5.1 Leitura: cache-aside em `index()`

```php
public function index(): void
{
    $category = !empty($_GET['category']) ? $_GET['category'] : null;
    $start = microtime(true);

    $products = $this->cachedCatalog($category); // antes: $this->fetchCatalog($category)

    $elapsedMs = round((microtime(true) - $start) * 1000);

    render('catalog', [
        'products' => $products,
        'category' => $category,
        'elapsedMs' => $elapsedMs,
        'categories' => $this->categories(),
    ]);
}

private function cachedCatalog(?string $category): array
{
    $redis = RedisClient::connection();
    $key = $this->cacheKey($category);

    $cached = $redis->get($key);
    if ($cached !== false) {
        return json_decode($cached, true); // HIT
    }

    $products = $this->fetchCatalog($category); // MISS — query original, sem alteração
    $redis->setex($key, self::CACHE_TTL, json_encode($products));

    return $products;
}

private function cacheKey(?string $category): string
{
    return self::CACHE_PREFIX . ($category ?: 'all'); // catalog:v1:products:{slug}
}
```

`fetchCatalog()` (a query original, com o `JOIN`/`GROUP BY` de agregação)
**não foi alterada** — o cache só envolve essa função por fora, no clássico
padrão *cache-aside*: tenta o Redis, em *miss* consulta o banco e popula o
cache antes de retornar.

### 5.2 Escrita: `update()` com invalidação seletiva

```php
public function update(int $id): void
{
    $pdo = Database::connection();

    $price = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null;
    $stock = isset($_POST['stock']) && $_POST['stock'] !== '' ? (int) $_POST['stock'] : null;

    $stmt = $pdo->prepare('
        UPDATE products
        SET price = COALESCE(:price, price),
            stock = COALESCE(:stock, stock)
        WHERE id = :id
        RETURNING category
    ');
    $stmt->execute(['price' => $price, 'stock' => $stock, 'id' => $id]);
    $category = $stmt->fetchColumn(); // categoria do produto, sem SELECT extra

    if ($category !== false) {
        $this->invalidateCache($category);
    }

    header('Content-Type: application/json');
    echo json_encode(['message' => 'Produto atualizado...']);
}

private function invalidateCache(string $productCategory): void
{
    $redis = RedisClient::connection();
    $redis->del($this->cacheKey(null));           // catalog:v1:products:all
    $redis->del($this->cacheKey($productCategory)); // catalog:v1:products:{categoria}
}
```

A única mudança na query de escrita foi acrescentar `RETURNING category`
— continua **uma única ida ao banco**, só que agora ela também informa qual
chave de categoria precisa cair.

---

## 6. Bug encontrado durante os testes manuais (fora do escopo do cache, mas registrado)

Ao testar no navegador, notei que a mensagem de feedback do botão "Salvar"
("Produto atualizado...") nunca aparecia, mesmo com a atualização
funcionando corretamente no banco. Investigando, o bug é **pré-existente**
no HTML/JS original do desafio — não foi introduzido pela minha
implementação de cache, só ficou visível agora porque testamos esse fluxo
específico pela primeira vez.

**Causa**: em `src/app/Views/catalog.php`, a `<div class="update-feedback">`
fica **fora** do `<form>` (é irmã dele, não filha):

```html
<td>
    <form class="product-actions" data-product-update method="post" action="/produtos/...">
        ...
        <button type="submit">Salvar</button>
    </form>
    <div class="muted update-feedback"></div>  <!-- fora do <form> -->
</td>
```

E em `src/public/assets/app.js`, a busca pelo elemento de feedback partia do
próprio `form`:

```js
const feedback = form.querySelector('.update-feedback'); // nunca encontra — está fora do form
```

`querySelector` só procura **descendentes**, então `feedback` era sempre
`null`, e o `if (feedback)` silenciosamente não fazia nada — a requisição
`POST` funcionava (o preço/estoque mudavam no banco), só a mensagem visual
nunca tinha chance de aparecer.

**Correção aplicada** (`src/public/assets/app.js`):

```js
// .update-feedback é irmã do <form> (fica fora dele no HTML), então a
// busca precisa partir do elemento pai, não do próprio form.
const feedback = form.parentElement.querySelector('.update-feedback');
```

Optei por corrigir no JS (buscar a partir do `<td>` pai) em vez de mexer no
HTML/`catalog.php`, por ser a mudança de menor risco — não altera estrutura
nem estilo da tabela, só onde o JS procura o elemento.

---

## 7. Validação: conteúdo do cache idêntico ao do banco

Mesmo tipo de checagem feita no Problema 1: com uma chave já populada,
capturei a resposta servida do cache, depois apaguei a chave (forçando
*miss*) e comparei o HTML das duas respostas.

```
DEL catalog:v1:products:all
GET /catalogo  (miss, 117ms)
GET /catalogo  (hit, 1ms — já visto antes)

diff <(resposta do cache) <(resposta direto do banco)
→ idêntico, fora da linha do "Tempo de geração"
```

**Teste de invalidação seletiva**: populei o cache de 3 chaves
(`all`, `Eletrônicos`, `Moda`), atualizei um produto da categoria
Eletrônicos (id 2, "Arroz C81E7", preço `2717.76→999.99`, estoque
`81→42`) e confirmei via `redis-cli KEYS "catalog:*"`:

| Antes do update | Depois do update |
|---|---|
| `catalog:v1:products:all` | ~~`catalog:v1:products:all`~~ (removida) |
| `catalog:v1:products:Eletrônicos` | ~~`catalog:v1:products:Eletrônicos`~~ (removida) |
| `catalog:v1:products:Moda` | `catalog:v1:products:Moda` (**intacta**) |

Confirma a seção 4.3 na prática: só as 2 chaves realmente afetadas somem;
`Moda` (categoria não relacionada ao produto atualizado) permanece em cache,
sem recálculo desnecessário. A próxima carga de `/catalogo` (com ou sem
filtro `Eletrônicos`) já veio com `R$ 999,99` / estoque `42`, sem esperar o
TTL de 5 minutos.

---

## 8. Testes manuais no navegador

Sequência real testada por mim (Firefox), depois de `docker compose exec
redis redis-cli FLUSHALL` pra garantir estado limpo:

| Ação | Tempo mostrado |
|---|---|
| `/catalogo` (1ª carga) | 101 ms (*miss*) |
| F5 | 1 ms (*hit*) |
| Ctrl+F5 (recarga forçada) | 1 ms (*hit* — confirma que não é cache do navegador, é o Redis) |
| Filtro categoria "Esportes" (1ª vez) | 85 ms (*miss* — chave nova) |
| Filtro categoria "Casa" (1ª vez) | 80 ms (*miss* — chave nova) |

Depois, editei o primeiro produto da tabela (preço `R$ 2.534,74 → 5.000,32`,
estoque `119 → 250`) e cliquei em "Salvar":

- **1ª tentativa** (antes da correção do bug da seção 6): nenhuma mensagem
  de feedback apareceu; precisei dar F5 pra confirmar visualmente que o
  preço/estoque tinham mudado — o que confirmou que a **invalidação
  funcionou** (dado correto após F5), mesmo com o bug de UI escondendo a
  confirmação.
- **2ª tentativa** (depois da correção, com hard refresh da página pra
  carregar o `app.js` corrigido): a mensagem "Produto atualizado..."
  apareceu corretamente ao lado do botão.
- Em ambos os casos, a **tabela em si só reflete a mudança após F5** — isso
  é esperado e não é bug: como registrado na seção 1, o clique em "Salvar"
  só faz um `POST` assíncrono, sem recarregar a tabela; quem garante que o
  F5 traga o dado certo é a invalidação de cache, não o JS da requisição.

---

## 9. Resiliência: o que acontece se o Redis cair

Não estava nas 4 decisões originais, mas testei deliberadamente (`docker
compose stop redis`) porque é uma pergunta natural pra qualquer camada de
cache: **o recurso principal deveria depender da disponibilidade do
cache?**

**Antes da correção**: não. `RedisClient::connection()` chamava
`$redis->connect($host, $port)` sem tratamento — com o Redis fora do ar,
isso disparava um **warning nativo do driver** (`php_network_getaddresses`)
e a página `/catalogo` quebrava inteira (nem chegava a mostrar os produtos),
porque nada no `CatalogController` tratava a falha.

**Correção aplicada:**

1. `RedisClient::connection()` passou a usar timeout curto de conexão
   (1 segundo) e converter falha de conexão numa exceção única e
   previsível (`RuntimeException`), em vez de deixar o driver emitir um
   warning solto:

   ```php
   $connected = @$redis->connect($host, $port, 1.0);
   if (!$connected) {
       throw new RuntimeException("Não foi possível conectar ao Redis em {$host}:{$port}.");
   }
   ```

2. `CatalogController` passou a tratar o Redis como **opcional**: tanto a
   leitura (`cachedCatalog()`) quanto a invalidação (`invalidateCache()`)
   envolvem as chamadas ao Redis em `try/catch`, com fallback pra servir
   direto do banco quando o cache não responde:

   ```php
   private function cachedCatalog(?string $category): array
   {
       $key = $this->cacheKey($category);
       $redis = $this->redisOrNull();

       if ($redis !== null) {
           try {
               $cached = $redis->get($key);
               if ($cached !== false) {
                   return json_decode($cached, true);
               }
           } catch (Throwable $e) {
               $redis = null;
           }
       }

       $products = $this->fetchCatalog($category); // sempre funciona, com ou sem Redis

       if ($redis !== null) {
           try {
               $redis->setex($key, self::CACHE_TTL, json_encode($products));
           } catch (Throwable $e) {
               // ignora — leitura já resolvida pelo banco
           }
       }

       return $products;
   }
   ```

   Em `invalidateCache()`, a mesma lógica: se o Redis não responder, a
   função simplesmente retorna sem lançar erro — a escrita em `products`
   já foi confirmada no Postgres **antes** da tentativa de invalidação, então
   uma falha no Redis nunca desfaz nem impede a atualização de
   preço/estoque.

**Teste de verificação** (`docker compose stop redis`):

| Ação com Redis parado | Resultado |
|---|---|
| `GET /catalogo` | 200 OK, 200 produtos renderizados normalmente (mais lento, direto do banco) |
| `POST /produtos/2` (novo preço/estoque) | 200 OK, gravado no Postgres normalmente |

Religuei o Redis (`docker compose start redis`) depois e confirmei: cache
voltou a funcionar (miss→hit) e o produto atualizado **durante a queda**
apareceu corretamente na tela — nada ficou inconsistente pela falha
temporária.

---

## 10. Trade-offs e decisões assumidas

- **Chave por combinação de filtro (9 no máximo)** em vez de um blob único
  filtrado em PHP — trade-off de simplicidade/segurança de comportamento
  contra uma economia de memória irrelevante no Redis (seção 4.1).
- **TTL de 5 minutos como rede de segurança**, não como mecanismo principal
  de atualização — a atualização real acontece via invalidação ativa no
  `update()` (seção 4.2).
- **Invalidação seletiva (2 chaves) assume que a categoria do produto nunca
  muda** via `update()` — verdadeiro hoje (só mexe em `price`/`stock`), mas
  registrado como suposição a revisitar se o escopo mudar (seção 4.3).
- **Bug de feedback do botão "Salvar" (seção 6)**: pré-existente, não
  relacionado ao cache, corrigido por ser uma mudança de baixo risco que
  melhora a demonstração da solução (o enunciado permite ajustes de
  frontend como diferencial).
- **Sem invalidação por TTL observada nos testes** — não cheguei a esperar
  5 minutos pra validar a expiração natural; a confiança no TTL vem da
  configuração explícita (`SETEX`) e da checagem de `TTL` via `redis-cli`
  (seção 4.2 / 7), não de uma espera cronometrada.
- **Resiliência a falha do Redis (seção 9)**: não estava nas 4 decisões
  originais — surgiu de uma pergunta que fiz depois de "terminar": *e se o
  Redis cair?* Testei (`docker compose stop redis`) e descobri que sem
  tratamento a página quebrava inteira. Corrigido com fallback via
  `try/catch`: leitura degrada pra consulta direta ao banco, e a
  invalidação falha silenciosamente sem impedir a escrita em `products`
  (que já foi confirmada no Postgres antes da tentativa de invalidar).
  Trade-off consciente: **não fiz retry nem circuit breaker** — um Redis
  fora do ar significa "sem cache até voltar", o que é aceitável pro escopo
  deste desafio.
