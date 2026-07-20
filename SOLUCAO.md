# Solução — Desafio Técnico Esferas Software

Esse é o resumo que o enunciado pede: o que causava cada problema, o que eu fiz
e por quê, os números de antes/depois, e os trade-offs que assumi no caminho.
Escrevi ele curto de propósito — o raciocínio completo, com evidência de
`EXPLAIN ANALYZE`, código antes/depois comentado e a investigação de cada
decisão, está em dois documentos de apoio que fui construindo durante o
trabalho:

- [`analise-problema01.md`](analise-problema01.md) — Relatório de Clientes
- [`analise-problema02.md`](analise-problema02.md) — Catálogo de Produtos
- [`analise-roadmap.md`](analise-roadmap.md) — o roadmap de execução completo, fase a fase

Se algo aqui parecer resumido demais, é porque tem uma seção inteira te
esperando num desses três arquivos.

---

## Problema 1 — Relatório de Clientes

**O que estava causando a lentidão**: não era uma consulta lenta, era uma
consulta **repetida 5.001 vezes**. O `ReportController` buscava os 5.000
clientes numa query e, dentro de um loop em PHP, disparava uma consulta nova
pra cada um só pra somar o gasto e contar pedidos — e ainda calculava isso
pra todos os 5.000, mesmo só 20 aparecendo na tela (o `usort`/corte pro top
20 acontecia depois, em PHP). Sem nenhum índice em `orders.customer_id` nem
`order_items.order_id`, cada uma dessas 5.000 consultas varria as tabelas
inteiras (200 mil e ~500 mil linhas) pra achar as poucas linhas do cliente em
questão. Medi uma dessas consultas isolada via `EXPLAIN ANALYZE`: ~48,6ms.
Multiplicado por 5.000, bate exatamente com os 193 segundos que a página
mostrava.

**O que fez** (`src/app/Controllers/ReportController.php`): troquei o loop
por uma única query com duas CTEs — uma soma o total gasto (`JOIN` entre
`orders`/`order_items`), outra conta pedidos direto em `orders`, sem
`COUNT(DISTINCT)` (que, sozinho, empurrava o Postgres pra um plano baseado em
sort em vez de hash aggregate — foi a virada de chave da otimização).
Adicionei também `db/indexes.sql` com dois índices de suporte a FK
(`orders.customer_id`, `order_items.order_id`) — achado honesto: eles não são
usados pela query final (o filtro de 12 meses pega ~50% da tabela, e nessa
proporção o Postgres prefere Seq Scan), mas mantive por boa prática e porque
ajudam outros acessos pontuais.

**Antes/depois**:

| | Antes | Depois |
|---|---|---|
| Tempo na página | 193.403 ms | ~235-350ms na maioria das execuções (picos ocasionais até ~500ms) |
| Consultas por requisição | 5.001 | 1 |
| Plano de execução | `Seq Scan` repetido 5.000x | `HashAggregate` paralelo (2 workers), sempre `Buffers: shared hit` (zero leitura de disco) |

Isso é uma melhora de **mais de 500x**, sem mudar nenhum cliente, valor ou
contagem exibida (validei comparando o top 20 novo com a lógica antiga,
cliente a cliente).

**Sobre os picos ocasionais acima de 300ms**: são reais, e investiguei a
fundo antes de aceitar — descartei índice ausente, leitura de disco,
execução serial vs. paralela, limite de workers do Postgres e overhead de
PHP/Apache, um por um. A causa é contenção de CPU do ambiente Docker/Windows
local (o container do banco dividindo processador com o resto da máquina),
não um problema da query. Toda essa investigação, com os testes um a um, está
em `analise-problema01.md`.

---

## Problema 2 — Catálogo de Produtos

**O que estava causando o retrabalho**: `CatalogController::fetchCatalog()`
recalculava, em **todo** `GET /catalogo`, duas agregações sobre a base
inteira (`product_reviews` pra nota média, `order_items` pra quantidade
vendida) — ~91ms de banco a cada visita, mesmo que ninguém tivesse avaliado
ou comprado nada nos últimos segundos. O `RedisClient` já existia no projeto,
mas não era usado em lugar nenhum da leitura. Achado que moldou a estratégia:
o filtro de categoria **não reduz o custo** dessa query — as agregações
rodam sobre os 3.000 produtos de qualquer jeito, o filtro só corta o
resultado exibido.

**Estratégia de cache (Cache-Aside)**:

- **Chave**: `catalog:v1:products:{categoria|all}` — uma entrada por filtro
  possível (8 categorias + "todas" = no máximo 9 chaves), mapeando 1:1 com a
  função que já existia. O `v1` é um prefixo de versão estático, só uma
  válvula de escape manual pra caso o formato do valor cacheado mude no
  futuro.
- **TTL**: 300 segundos (5 minutos). O único dado que muda (`price`/`stock`)
  é coberto por invalidação ativa, não pelo TTL — então o TTL é rede de
  segurança, não o mecanismo principal. Não precisava ser curto.
- **Invalidação**: no `POST /produtos/{id}`, troquei o `UPDATE` por
  `UPDATE ... RETURNING category, price, stock` — numa única ida ao banco,
  descubro a categoria do produto e devolvo os valores confirmados (não os
  que vieram do formulário, que podem estar parcialmente em branco). Com a
  categoria em mãos, invalido só as **2 chaves realmente afetadas**
  (`all` + a categoria do produto), em vez de apagar as 9 combinações ou
  manter um contador de versão global.
- **Serialização**: JSON — mapeia direto do array que o PDO já retorna, e dá
  pra inspecionar via `redis-cli` durante o desenvolvimento.

**Resiliência**: testei derrubar o Redis (`docker compose stop redis`) e a
primeira versão quebrava a página inteira. Corrigido: leitura e invalidação
agora toleram Redis indisponível (`try/catch` com fallback pro banco direto);
se o cache cair, o catálogo continua funcionando, só mais lento — não é mais
um ponto único de falha.

O raciocínio completo (incluindo as alternativas de invalidação que descartei
e por quê) está em `analise-problema02.md`.

---

## Além do pedido

O enunciado permite ajustes de frontend como diferencial, então usei parte do
tempo pra deixar a aplicação com cara de produto de verdade:

- **Estilização** com a paleta real da Esferas Software, levantada a partir
  do CSS do site institucional (indigo `#6278df`, navy `#1f2233`), e a
  mesma tipografia (Work Sans).
- **Modal de confirmação** no lugar da mensagem de feedback que não
  aparecia (era um bug pré-existente — a `div` de feedback ficava fora do
  `<form>` no HTML original, então o JS nunca a encontrava). A tabela só
  reflete o valor novo quando a modal é fechada.
- **Paginação** no catálogo (50 produtos por página) — implementada sem
  tocar na estratégia de cache: `fetchCatalog()` deixou de ter `LIMIT`,
  `cachedCatalog()` passou a cachear a categoria inteira, e a paginação é só
  um corte em PHP por cima do array já cacheado.
- **Página `/performance`** (`/performance`, fora do fluxo de produção) —
  mede ao vivo, a cada carregamento, o miss/hit do cache do catálogo e o
  tempo da query otimizada do relatório (lado a lado com o baseline
  documentado), com histórico persistido no Redis. Serve pra acompanhar a
  performance ao longo de várias execuções, não só um número isolado.

---

## Trade-offs e suposições assumidas

- **Índices do Problema 1 não são usados pela query final** (seletividade
  baixa — filtro pega ~50% da tabela). Mantidos por boa prática de suporte a
  FK, não porque resolvem o gargalo — quem resolveu foi a reescrita da
  query.
- **`orders_count` assume que todo pedido tem pelo menos 1 item** — verdade
  no seed atual e no comportamento da aplicação (não existe endpoint que crie
  pedido vazio), mas é uma suposição de dado, não uma garantia de schema.
  Documentei a alternativa mais lenta que não depende disso.
- **Invalidação de cache do catálogo assume que a categoria de um produto
  nunca muda** via `update()` — verdade hoje (só mexe em `price`/`stock`).
  Se um dia existir edição de categoria, a invalidação precisaria contemplar
  categoria antiga **e** nova.
- **Picos ocasionais acima de 300ms no relatório** são aceitos e documentados
  como variância do ambiente local (Docker/Windows dividindo CPU), não uma
  falha da correção — investiguei e descartei outras causas antes de chegar
  nessa conclusão.
- **Sem retry/circuit breaker pra falha do Redis** — um Redis fora do ar
  significa "sem cache até voltar", o que é aceitável pro escopo deste
  desafio; não implementei reconexão automática nem métricas de
  disponibilidade.
- **`db/indexes.sql` não é aplicado automaticamente** — precisa ser rodado
  manualmente depois do seed (documentado no `README.md`), já que o
  `docker-entrypoint-initdb.d` do Postgres só roda scripts na primeira
  inicialização do volume, e o `schema.sql` já ocupa esse lugar.

---

## Ferramentas usadas pra diagnosticar e validar

Nada dos números acima é achismo — foram medidos com:

- **`EXPLAIN ANALYZE` / `EXPLAIN (ANALYZE, BUFFERS)`** via `psql` — plano de
  execução real do Postgres, pra ver onde o tempo ia (`Seq Scan`, sort em
  disco, etc.) e se a leitura vinha de memória ou disco.
- **`curl`** — tempo de resposta HTTP de verdade, comparado com o tempo que
  a própria página reporta, pra isolar query de overhead de Apache/PHP.
- **`redis-cli`** — inspecionar chaves, TTL e conteúdo cacheado durante o
  desenvolvimento, e confirmar hit/miss na prática.
- **`docker compose`** (incluindo `down -v` + rebuild completo) — recriar o
  ambiente do zero pra testar reprodutibilidade, e `stop`/`start` do Redis
  pra testar resiliência de propósito.
- **Playwright** (Chromium headless) — automação de navegador pra validar
  fluxos reais (modal, paginação) com screenshots e checagem de erros de
  console.
- **PowerShell** (`Get-CimInstance Win32_Processor`) — checar carga de CPU
  do host quando os tempos do relatório variavam, pra confirmar que era
  contenção de máquina e não regressão de código.
- Navegador manual (Firefox, Edge) e `php -l` pra lint.

Detalhamento de qual ferramenta foi usada em qual teste, com os comandos
exatos, está nos dois documentos de apoio.

## Como rodar e verificar

```bash
docker compose up -d --build
docker compose exec app php db/seed.php
docker compose exec -T db psql -U teste_esferas -d teste_esferas < db/indexes.sql
```

No Windows, se estiver usando **PowerShell** (não bash), o operador `<` não é
suportado (`RedirectionNotSupported`) — troque a última linha por uma destas:

```powershell
Get-Content db/indexes.sql | docker compose exec -T db psql -U teste_esferas -d teste_esferas
```

```powershell
cmd /c "docker compose exec -T db psql -U teste_esferas -d teste_esferas < db/indexes.sql"
```

Depois:

- `/relatorio/top-clientes` — tempo de geração no topo da página.
- `/catalogo` — tempo de geração + filtro de categoria + paginação.
- `/performance` — painel de diagnóstico com os números ao vivo dos dois
  problemas, histórico incluso.

Testei o ambiente inteiro do zero (`docker compose down -v` + rebuild + seed
+ índices) antes de fechar essa entrega, pra garantir que os passos acima são
exatamente o que reproduz o resultado descrito aqui.
