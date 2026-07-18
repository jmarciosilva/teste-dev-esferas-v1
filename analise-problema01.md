# Problema 1 — Relatório de Top Clientes: causa raiz, solução e decisões técnicas

Esse documento nasceu como um diário de bordo da minha investigação com o
Claude (Anthropic), mas resolvi reorganizar ele pra virar material de
consulta de verdade — tanto pra eu conseguir explicar a solução numa
conversa técnica sem precisar reconstruir o raciocínio do zero, quanto pra
servir de referência didática (se um dev júnior ler isso, quero que dê pra
entender o "porquê" de cada decisão, não só o "o quê").

A estrutura é: contexto → causa raiz explicada a fundo → código antes/depois
→ cada decisão de reescrita com o conceito por trás → validação → testes e
resultados → trade-offs assumidos.

---

## 1. Contexto

**Página:** `/relatorio/top-clientes` — lista os 20 clientes que mais
gastaram nos últimos 12 meses (nome, e-mail, cidade, total gasto, contagem
de pedidos).

**Massa de dados do seed padrão:**

| Tabela | Linhas |
|---|---|
| `customers` | 5.000 |
| `products` | 3.000 |
| `orders` | 200.000 |
| `order_items` | 499.524 |
| `product_reviews` | 60.000 |

**Sintoma:** ao acessar a página, o tempo mostrado no topo foi
**193.403 ms** (quase 3 minutos e meio). O enunciado do desafio já avisava
que isso ia ser perceptível ("pode levar mais de 30 segundos"), mas na minha
máquina foi bem além disso.

**Meta:** menos de 300ms, sem mudar nenhum valor exibido (mesmos clientes,
mesmos totais, mesma contagem de pedidos).

---

## 2. Causa raiz, explicada em profundidade

### 2.1 O conceito: o que é "N+1 queries"

Antes de entrar no código, vale explicar o padrão em si, porque é um dos
erros de performance mais comuns em qualquer aplicação que fala com banco
(PHP puro, Laravel, Doctrine, Rails, não importa o framework).

**N+1** acontece quando você busca uma lista de registros (1 query) e depois,
pra cada item dessa lista, dispara **uma nova query** pra buscar dado
relacionado — ao invés de pedir tudo de uma vez com um `JOIN`/`GROUP BY`. O
nome vem da conta: 1 query pra lista + N queries (uma por item da lista).

O problema não é que cada query individual seja necessariamente lenta — é
que o **custo se multiplica pelo tamanho da lista**. Cada ida ao banco paga
um pedágio fixo (latência de rede, parsing da query, planejamento de
execução) **além** do tempo de execução em si. Com 10 itens, ninguém nota.
Com 5.000, o pedágio sozinho já explica minutos de espera.

### 2.2 Onde isso acontecia no código

```php
// src/app/Controllers/ReportController.php (ANTES)
public function topClientes(): void
{
    $pdo = Database::connection();
    $start = microtime(true);

    $customers = $pdo->query('SELECT id, name, email, city FROM customers')->fetchAll();
    // (1) busca os 5.000 clientes de uma vez — até aqui, ok.

    foreach ($customers as &$customer) {
        // (2) só que aqui, PARA CADA um dos 5.000, dispara uma query nova:
        $stmt = $pdo->prepare('
            SELECT
                COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_spent,
                COUNT(DISTINCT o.id) AS orders_count
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.customer_id = :customer_id
              AND o.created_at >= now() - interval '12 months'
        ');
        $stmt->execute(['customer_id' => $customer['id']]);
        $totals = $stmt->fetch();

        $customer['total_spent'] = (float) $totals['total_spent'];
        $customer['orders_count'] = (int) $totals['orders_count'];
    }
    unset($customer);

    // (3) o TOP 20 só é decidido AQUI, em PHP, depois de já ter
    // calculado o total gasto de TODOS os 5.000 clientes:
    usort($customers, fn ($a, $b) => $b['total_spent'] <=> $a['total_spent']);
    $topCustomers = array_slice($customers, 0, 20);

    $elapsedMs = round((microtime(true) - $start) * 1000);

    render('report', ['customers' => $topCustomers, 'elapsedMs' => $elapsedMs]);
}
```

Três problemas empilhados nesse trecho:

1. **N+1 literal**: 1 query pra listar clientes + 5.000 queries no loop =
   **5.001 idas ao banco** numa única requisição HTTP.
2. **Trabalho desperdiçado**: o total gasto é calculado pra **todos** os
   5.000 clientes, mesmo que só 20 apareçam na tela. O banco nunca teve
   chance de filtrar/ordenar/limitar — isso virou responsabilidade do PHP
   (`usort` + `array_slice` rodando sobre um array de 5.000 posições).
3. **Nenhum índice de suporte**: `db/schema.sql` só declara as `PRIMARY KEY`
   (o próprio arquivo tem um comentário avisando que isso é proposital).
   Não existe índice em `orders.customer_id` nem em `order_items.order_id`
   — então cada uma das 5.000 queries do loop precisa varrer as tabelas
   inteiras (200 mil linhas em `orders`, ~500 mil em `order_items`) até
   achar as poucas linhas daquele cliente específico.

### 2.3 Evidência real (não achismo): `EXPLAIN ANALYZE` de uma única iteração do loop

Rodei a query exata que fica dentro do `foreach`, isolada, pra um cliente
qualquer (`id = 42`), direto no Postgres do container:

<details>
<summary>Ver plano de execução completo (clique pra expandir)</summary>

```
Aggregate  (cost=10639.31..10639.32 rows=1 width=40) (actual time=45.621..48.549 rows=1 loops=1)
   ->  Gather  (cost=4824.09..10638.81 rows=50 width=14) (actual time=13.007..48.281 rows=65 loops=1)
         Workers Planned: 2
         Workers Launched: 2
         ->  Parallel Hash Join  (cost=3824.09..9633.81 rows=21 width=14) (actual time=10.096..41.716 rows=22 loops=3)
               Hash Cond: (oi.order_id = o.id)
               ->  Parallel Seq Scan on order_items oi  (cost=0.00..5263.35 rows=208135 width=14) (actual time=0.014..14.292 rows=166508 loops=3)
               ->  Parallel Hash  (cost=3823.94..3823.94 rows=12 width=4) (actual time=6.994..6.995 rows=8 loops=3)
                     Buckets: 1024  Batches: 1  Memory Usage: 104kB
                     ->  Parallel Seq Scan on orders o  (cost=0.00..3823.94 rows=12 width=4) (actual time=0.806..6.882 rows=8 loops=3)
                           Filter: ((customer_id = 42) AND (created_at >= (now() - '1 year'::interval)))
                           Rows Removed by Filter: 66658
 Planning Time: 1.638 ms
 Execution Time: 48.632 ms
```

</details>

**Pra quem não lê `EXPLAIN` no dia a dia, o que isso diz:**

- `Seq Scan` = sequential scan = o Postgres leu a tabela **inteira**, linha
  por linha, em vez de usar um índice pra pular direto pras linhas
  relevantes. `Rows Removed by Filter: 66658` mostra o tamanho do
  desperdício: leu ~66,7 mil linhas de `orders` só pra descartar quase todas
  e ficar com 8.
- `Parallel` / `Gather` / `Workers Launched: 2` = o Postgres percebeu que a
  tabela era grande o suficiente pra valer a pena dividir o trabalho em 2
  processos paralelos. Ajuda, mas não resolve o problema de fundo (ainda tem
  que ler a tabela toda).
- `Execution Time: 48.632 ms` = o tempo real dessa única consulta.

**A conta que fecha o problema**: ~48,6ms × 5.000 clientes ≈ **243
segundos** — bate exatamente com a ordem de grandeza dos 193 segundos que
apareceram na tela (a diferença vem de cache de buffer do Postgres entre
execuções seguidas e concorrência entre os workers paralelos).

### 2.4 Por que "só adicionar índice" não resolvia sozinho

Esse é o ponto que eu queria deixar bem claro, porque é uma armadilha comum:
dá pra pensar "a tabela não tem índice, então é só criar um índice que
resolve". Não nesse caso.

Mesmo que um índice perfeito reduzisse cada uma das 5.000 consultas de
~48ms pra, digamos, 2ms (uma melhora de 24x só na consulta individual), a
conta final seria **5.000 × 2ms = 10 segundos**. Ainda 33x mais lento que a
meta de 300ms. O gargalo não é "a consulta é lenta", é **a quantidade de
consultas** — cada ida ao banco paga um custo fixo de rede/parsing que
índice nenhum reduz. Por isso a correção **tinha que** começar pela
eliminação do N+1; o índice, na melhor das hipóteses, seria um complemento.
(Spoiler da seção 4.2: nem chegou a ser necessário pra bater a meta.)

---

## 3. Código antes e depois

### 3.1 Antes (código original)

Já mostrado inteiro na seção 2.2 — resumindo a "forma": 1 query de listagem
+ loop de 5.000 queries + ordenação/corte em PHP.

### 3.2 Depois (código atual)

```php
// src/app/Controllers/ReportController.php (DEPOIS)
public function topClientes(): void
{
    $pdo = Database::connection();
    $start = microtime(true);

    $stmt = $pdo->query('
        WITH spent AS (
            SELECT o.customer_id, SUM(oi.quantity * oi.unit_price) AS total_spent
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.created_at >= now() - interval \'12 months\'
            GROUP BY o.customer_id
        ),
        counts AS (
            SELECT customer_id, COUNT(*) AS orders_count
            FROM orders
            WHERE created_at >= now() - interval \'12 months\'
            GROUP BY customer_id
        )
        SELECT c.id, c.name, c.email, c.city, s.total_spent, co.orders_count
        FROM spent s
        JOIN counts co ON co.customer_id = s.customer_id
        JOIN customers c ON c.id = s.customer_id
        ORDER BY s.total_spent DESC
        LIMIT 20
    ');
    $topCustomers = $stmt->fetchAll();

    foreach ($topCustomers as &$customer) {
        $customer['total_spent'] = (float) $customer['total_spent'];
        $customer['orders_count'] = (int) $customer['orders_count'];
    }
    unset($customer);

    $elapsedMs = round((microtime(true) - $start) * 1000);

    render('report', ['customers' => $topCustomers, 'elapsedMs' => $elapsedMs]);
}
```

**O que mudou estruturalmente:**

- **1 query no total**, não 5.001. O `foreach` que sobrou no PHP roda só
  sobre os **20 resultados finais** (pra normalizar tipo de dado de string
  pra float/int, nada de custo perceptível) — não é mais o mesmo tipo de
  problema, porque não dispara nova consulta a cada volta.
- A query tem duas CTEs (`WITH ... AS (...)`, "Common Table Expressions" —
  basicamente subconsultas nomeadas que você pode referenciar depois como se
  fossem tabelas temporárias):
  - `spent`: soma o valor gasto por cliente, juntando `orders` e
    `order_items`, filtrando os últimos 12 meses.
  - `counts`: conta quantos pedidos cada cliente fez no período — **sem**
    precisar tocar em `order_items` pra isso.
- O `SELECT` final junta as duas CTEs com `customers`, ordena por total
  gasto e corta em 20 — tudo dentro do banco, nada em PHP.

O motivo de ter duas CTEs em vez de uma query simples com `JOIN` +
`GROUP BY` direto não é estético — é o resultado de uma investigação de
performance real, detalhada na seção 4.

---

## 4. Cada decisão de reescrita, explicada com o conceito por trás

Reescrever a query não foi "trocar de uma vez" — foram quatro iterações,
cada uma resolvendo um gargalo diferente que só apareceu depois que o
anterior foi resolvido. Vale entender cada etapa porque os conceitos se
aplicam a qualquer query de agregação pesada, não só a essa.

### 4.1 Decisão 1 — Matar o N+1 primeiro (uma query com `JOIN` + `GROUP BY`)

A primeira reescrita, ainda simples, foi trocar o loop por uma única query
"óbvia":

```sql
SELECT c.id, c.name, c.email, c.city,
       COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_spent,
       COUNT(DISTINCT o.id) AS orders_count
FROM customers c
JOIN orders o ON o.customer_id = c.id AND o.created_at >= now() - interval '12 months'
JOIN order_items oi ON oi.order_id = o.id
GROUP BY c.id, c.name, c.email, c.city
ORDER BY total_spent DESC
LIMIT 20;
```

**Conceito**: deixar o banco fazer o trabalho de conjunto (`JOIN` +
`GROUP BY` + `ORDER BY` + `LIMIT`) de uma vez, que é exatamente pra isso que
um SGBD relacional foi desenhado, em vez de fazer "ping-pong" entre
aplicação e banco.

**Resultado**: de ~193.000ms pra **483ms**. Um salto gigante (>99,7% de
redução) só de eliminar o N+1 — mas ainda acima da meta de 300ms, e ainda
tinha gordura pra cortar, como as próximas duas decisões mostram.

### 4.2 Decisão 2 — Por que os índices, sozinhos, não mudaram nada (seletividade)

Criei o `db/indexes.sql` com dois índices:

```sql
CREATE INDEX idx_orders_customer_id_created_at ON orders (customer_id, created_at);
CREATE INDEX idx_order_items_order_id ON order_items (order_id);
```

Apliquei e rodei a mesma query da seção 4.1 de novo. Tempo: **475-483ms** —
praticamente idêntico. Por quê?

**Conceito: seletividade.** Um índice compensa quando a consulta busca uma
**fração pequena** da tabela (poucas linhas em relação ao total) — aí vale a
pena o Postgres "pular" direto pras linhas certas via uma árvore B-tree. Mas
o filtro "últimos 12 meses" sobre um dataset que cobre ~24 meses pega
**cerca de metade das linhas de `orders`** (confirmado no plano:
`rows=100213` de um total de ~200.000). Pra ler ~50% de uma tabela, varrer
tudo sequencialmente (`Seq Scan`) é **mais barato** do que ficar saltando
entre índice e tabela linha por linha — é por isso que o próprio planner do
Postgres escolhe ignorar o índice, mesmo ele existindo.

Isso é uma lição de performance que vai além desse desafio: **índice não é
bala de prata**. Ele ajuda buscas seletivas (poucas linhas), não buscas que
tocam em boa parte da tabela. O padrão de acesso da query mudou (deixou de
ser "busca pontual por 1 cliente" e virou "agregação em massa sobre ~metade
da tabela"), e o índice pensado pro padrão antigo não serve pro novo.

### 4.3 Decisão 3 — Empurrar o `LIMIT` pra antes do `JOIN` com `customers`

O `EXPLAIN ANALYZE` da query da seção 4.1 revelou um gargalo escondido:

```
Sort Method: external merge  Disk: 7128kB
```

**Conceito**: pra juntar (`JOIN`) o resultado já agregado com a tabela
`customers`, o planner escolheu um `Merge Join` — uma estratégia que exige
as duas entradas **ordenadas pela chave do join**. Como o resultado
agregado (~250 mil linhas antes de virar 5.000 grupos) não estava ordenado
por `customer_id`, o Postgres precisou **ordenar tudo isso em disco**
(`external merge` = não coube em memória) só pra poder fazer o join — um
trabalho gigante pra, no final, descartar 4.980 das 5.000 linhas (já que só
os top 20 aparecem na tela).

**Solução**: reestruturei a query pra agregar por `customer_id` **primeiro**
(sem envolver `customers` ainda), aplicar `ORDER BY` + `LIMIT 20` **nessa
subconsulta**, e só **depois** juntar com `customers` — que nesse ponto já
são só 20 linhas, um join trivial via chave primária.

```sql
SELECT c.id, c.name, c.email, c.city, agg.total_spent, agg.orders_count
FROM (
    SELECT o.customer_id,
           SUM(oi.quantity * oi.unit_price) AS total_spent,
           COUNT(DISTINCT o.id) AS orders_count
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.created_at >= now() - interval '12 months'
    GROUP BY o.customer_id
    ORDER BY total_spent DESC
    LIMIT 20
) agg
JOIN customers c ON c.id = agg.customer_id
ORDER BY agg.total_spent DESC;
```

**Resultado**: 483ms → **368ms**. Lição geral: **filtre e corte o quanto
antes na pipeline de dados**; não carregue colunas ou faça joins que só
importam no resultado final através de todo o processamento pesado.

### 4.4 Decisão 4 — Eliminar o `COUNT(DISTINCT)` pra habilitar `HashAggregate` (a virada de chave)

Ainda restava gordura. O `EXPLAIN ANALYZE` da query da seção 4.3 mostrava
`GroupAggregate` com mais um `Sort Method: external merge` por baixo dele —
o gargalo tinha só mudado de lugar, não sumido.

**Conceito: `HashAggregate` x `GroupAggregate`.** O Postgres tem duas
estratégias pra calcular `GROUP BY`:

- **`GroupAggregate`**: exige os dados **ordenados** pela chave de
  agrupamento; percorre linha a linha e fecha um grupo toda vez que a chave
  muda. Precisa de `Sort` antes — caro quando não cabe em memória (daí o
  `external merge`).
- **`HashAggregate`**: monta uma **tabela hash** em memória, uma entrada por
  valor distinto da chave; não precisa de dado ordenado. Muito mais rápido
  quando o número de grupos é pequeno (aqui, no máximo 5.000 clientes).

O motivo de o Postgres **não** escolher `HashAggregate` na query da seção
4.3, mesmo sendo mais rápido: ela tinha `COUNT(DISTINCT o.id)` misturado no
`GROUP BY customer_id`. Pra deduplicar `o.id` **dentro de cada grupo**, o
planner recorre a ordenar as linhas (cliente, pedido) e comparar vizinhas
pra descartar repetições — e essa necessidade de ordenação "contamina" o
plano inteiro, empurrando tudo de volta pro caminho baseado em `Sort`.

**Solução**: separar o cálculo em duas agregações independentes, nenhuma
delas com `DISTINCT`:

```sql
WITH spent AS (
    SELECT o.customer_id, SUM(oi.quantity * oi.unit_price) AS total_spent
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.created_at >= now() - interval '12 months'
    GROUP BY o.customer_id
),
counts AS (
    SELECT customer_id, COUNT(*) AS orders_count
    FROM orders
    WHERE created_at >= now() - interval '12 months'
    GROUP BY customer_id
)
SELECT c.id, c.name, c.email, c.city, s.total_spent, co.orders_count
FROM spent s
JOIN counts co ON co.customer_id = s.customer_id
JOIN customers c ON c.id = s.customer_id
ORDER BY s.total_spent DESC
LIMIT 20;
```

- `spent`: soma direto, sem `DISTINCT` — vira `HashAggregate`.
- `counts`: `COUNT(*)` **sem envolver `order_items`** — cada pedido já é uma
  linha única em `orders`, então contar linhas é equivalente a contar
  pedidos distintos, sem precisar de `DISTINCT` nem de fazer o `JOIN`
  pesado com `order_items` só pra essa métrica.

**Resultado**: 368ms → **206-330ms**, e o `EXPLAIN` passou a mostrar
`Finalize HashAggregate` / `Partial HashAggregate` rodando em **2 workers
paralelos** — o Postgres divide a tabela em pedaços, cada worker monta sua
própria tabela hash parcial, e um passo final combina os resultados. Essa é
a versão que virou o `ReportController` atual.

> **Suposição assumida aqui — vale registrar com honestidade**: a `counts`
> CTE conta `COUNT(*)` direto em `orders`, sem exigir que o pedido tenha
> algum item em `order_items`. Isso só é equivalente à contagem original
> (que fazia `JOIN order_items` + `COUNT(DISTINCT o.id)`, ou seja, só
> contava pedidos **com pelo menos um item**) **porque, no seed atual
> (`db/seed.php`), todo pedido é gerado com pelo menos 1 item**
> (`itemsCount = random_int(1, MAX_ITEMS_PER_ORDER)`, nunca zero) — e não
> existe, hoje, nenhum caminho na aplicação que crie um pedido sem item. Se
> isso mudar no futuro (ex.: um pedido puder ficar "vazio" temporariamente),
> as duas contagens divergiriam. Uma versão 100% equivalente sem essa
> suposição existe (agregar por `customer_id, order_id` antes de contar —
> testei, ficou em ~358ms), mas escolhi a versão mais rápida e documentei a
> suposição, porque ela reflete a regra de negócio observada no sistema
> atual.

---

## 5. Validação: garantindo que o resultado não mudou

Regra do desafio: performance pode mudar, **o resultado não**. Antes de
trocar o código do controller, comparei o top 20 gerado pela query nova com
o resultado calculado pela lógica **antiga** (a mesma do código original,
com `JOIN order_items` + `COUNT(DISTINCT o.id)`), rodada manualmente só para
os 20 `customer_id` retornados pela query nova.

Bateu **valor a valor** — nome, total gasto, contagem de pedidos idênticos
para os 20 clientes (ex.: `Paula Nascimento`, id 2495, `R$ 784.003,01`, 32
pedidos, em ambas as versões).

Também considerei um caso de borda: a query nova usa `JOIN` (não
`LEFT JOIN`), então um cliente sem nenhum pedido no período simplesmente não
aparece no resultado — igual ao comportamento antigo, onde esse cliente
ficaria com `total_spent = 0` e nunca entraria no top 20 por causa do
`usort` descendente. Com ~100.213 pedidos distribuídos entre 5.000 clientes
nesse período (média de ~20 pedidos/cliente), a chance de um cliente zerado
aparecer no top 20 é essencialmente nula neste dataset — mas é uma suposição
que dependeu do volume de dados do seed, não uma garantia matemática
universal, e por isso está registrada aqui.

---

## 6. Testes e resultados

### 6.1 Evolução da query (recapitulando a seção 4 em números)

| Etapa | O que mudou | Tempo medido |
|---|---|---|
| Original (N+1) | 5.001 queries, ordenação em PHP | 193.403 ms |
| Decisão 1 | 1 query com `JOIN`+`GROUP BY`, sem índice novo | 483 ms |
| + índices novos | mesma query, com `db/indexes.sql` aplicado | 475-483 ms (sem diferença — ver 4.2) |
| Decisão 3 | agregação + `LIMIT 20` antes do `JOIN` com `customers` | 368 ms |
| Decisão 4 | `COUNT(DISTINCT)` eliminado, duas CTEs com `HashAggregate` | 206-330 ms |

### 6.2 Antes/depois na página real

| Medição | Antes | Depois |
|---|---|---|
| Tempo mostrado na página | 193.403 ms | ~200-330ms na maioria das execuções |
| Queries por requisição | 5.001 | 1 |
| Leitura de disco (`EXPLAIN ANALYZE, BUFFERS`) | — | Nenhuma (`Buffers: shared hit`, dado em memória) |

### 6.3 Testes manuais no navegador

Depois de fechar o código, testei na unha, sem script — só recarregando a
página mesmo:

**Sequência 1 (Firefox, indo em `/`, voltando e gerando o relatório de novo
a cada rodada):**

| # | Tempo |
|---|---|
| 1 | 196 ms |
| 2 | 235 ms |
| 3 | 209 ms |
| 4 | 196 ms |
| 5 | **452 ms** (pico) |

**Sequência 2 (depois de `docker compose down` + `docker compose up -d
--build`, simulando um restart completo do zero):**

- Firefox: 230 ms, 230 ms
- Edge: 237 ms, 199 ms

O padrão se repetiu mesmo depois de recriar o ambiente do zero: maioria bem
abaixo de 300ms, com picos ocasionais.

### 6.4 Investigação dos picos (testes que a IA rodou)

Quando relatei o pico de 452ms, pedi pra investigar a causa antes de aceitar
o número ou de sair fazendo mudança de arquitetura maior. Bateria de testes,
cada um eliminando uma hipótese:

| # | Teste | Resultado | Conclusão |
|---|---|---|---|
| 1 | Dropar os dois índices novos e comparar | 208-310ms sem índice vs 206-330ms com índice — igual | A query final **nunca usa** os índices (confirmado no plano: sempre `Seq Scan`). Quem resolveu foi a reescrita, não o índice (ver 4.2). |
| 2 | `VACUUM ANALYZE` nas 3 tabelas | Consistência melhorou (201-283ms, depois 216-245ms) | Estatísticas do planner ficam desatualizadas após carga em massa até o autovacuum rodar sozinho. Passei a incluir `ANALYZE` no `db/indexes.sql`. |
| 3 | Desabilitar paralelismo (`max_parallel_workers_per_gather = 0`) | Piorou bastante: 345-534ms | Paralelismo ajuda, não é a causa dos picos. |
| 4 | Checar limites de workers (`max_worker_processes`, `max_parallel_workers`) e CPUs do container | 8 workers permitidos, 4 CPUs disponíveis | Não é falta de capacidade configurada. |
| 5 | Checar OPcache e medir a home page (sem query pesada) | OPcache habilitado; home estável em 16-64ms | Descarta recompilação de script e lentidão de I/O do bind mount como causa dominante. |
| 6 | Correlacionar tempo total do `curl` com o tempo que a página reporta (só a query PDO) | Overhead fora da query estável em ~30-75ms em toda execução, inclusive nas lentas | Quando o tempo total explode, é a **query** que demorou mais, não o Apache/render. |

**Conclusão**: os picos nascem dentro da execução da query no Postgres,
sempre com `Buffers: shared hit` (sem leitura de disco — não é I/O). A
explicação mais plausível é contenção de CPU no ambiente Docker local
(Windows dividindo núcleo entre o container, IDE, navegador etc. enquanto os
2 workers paralelos do Postgres tentam rodar) — não é um problema de índice,
plano de execução ruim ou arquitetura da query.

---

## 7. Trade-offs e decisões assumidas

- **Reescrever a query era obrigatório; o índice, não.** A causa raiz real
  era o N+1 (seção 2.4). Testei e confirmei que os índices criados não são
  usados pela query final (seção 4.2, teste #1 da seção 6.4) — mantive-os
  mesmo assim por boa prática (nenhuma FK tinha índice de suporte) e porque
  seguem úteis pra outros acessos pontuais por cliente/pedido (inclusive foi
  a mesma query que usei pra validar o resultado na seção 5).
- **`orders_count` assume que todo pedido tem pelo menos 1 item** (detalhado
  na seção 4.4) — verdadeiro no seed atual e no comportamento atual da
  aplicação, mas é uma suposição, não uma garantia de schema. Documentei a
  alternativa mais lenta (~358ms) que não depende dessa suposição, caso o
  requisito mude no futuro.
- **Picos ocasionais acima de 300ms** (452ms no teste manual; 437ms/470ms
  nos testes via terminal) foram investigados e atribuídos a contenção de
  CPU do ambiente Docker/Windows local — não a um problema arquitetural.
  Decidi **aceitar e documentar** esse resultado em vez de perseguir uma
  solução mais invasiva (como view materializada), que reintroduziria risco
  de dado desatualizado — um trade-off que pertence ao Problema 2, não ao
  Problema 1.
- **Ganho final**: de 193.403ms para uma faixa de ~200-330ms na grande
  maioria das execuções — uma melhora de **mais de 400-800x**, dependendo da
  execução usada como referência, sem alterar nenhum valor exibido no
  relatório.

---

## Arquivos alterados nesta fase

- `db/indexes.sql` (novo) — os dois índices + `ANALYZE` das tabelas.
- `src/app/Controllers/ReportController.php` — query única no lugar do loop.
- `db/schema.sql` e `src/app/Views/report.php` — confirmado que **não**
  precisaram mudar.

## Próximo passo

Fase 1 encerrada. Próximo: analisar o Problema 2 (catálogo sem cache) do
zero, seguindo a mesma lógica — entender a causa raiz antes de implementar.
