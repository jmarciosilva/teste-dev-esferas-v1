# Problema 1 — Relatório de Top Clientes: minha conversa com a IA e o que ficou decidido

Esse arquivo é meio um diário técnico. A ideia é registrar, com as minhas
palavras, como foi o processo de investigar o Problema 1 do desafio técnico da
Esferas Software junto com o Claude (Anthropic), antes de eu sair alterando
código. 

## O ponto de partida

Depois de subir o ambiente local (docker compose, seed padrão rodado) e
acessar `http://localhost:8090/relatorio/top-clientes`, o tempo que apareceu
no topo da página foi **193.403 ms** — quase 3 minutos e meio pra listar os 20
clientes que mais gastaram nos últimos 12 meses. O enunciado já avisava que
isso ia ser perceptível ("pode levar mais de 30 segundos"), mas na prática foi
bem mais que isso na minha máquina.

Antes de sair aplicando índice ou reescrevendo query no impulso, preferi
sentar com a IA, mostrar o código do `ReportController` e pedir pra
investigarmos juntos a causa raiz — inclusive rodando `EXPLAIN ANALYZE` de
verdade no banco populado, não um chute teórico.

## O que eu pedi

Pedi pra documentarmos a análise antes de mexer em qualquer linha de código,
justamente pra não cair na armadilha de "sair trocando coisa" sem entender o
motivo real da lentidão. Isso virou o `analise-roadmap.md`. Só depois disso é
que a gente parte pra implementação de fato — e é isso que este arquivo aqui
introduz.

## O que a IA encontrou

Resumindo o que o Claude levantou (o detalhamento técnico completo, com o
plano do `EXPLAIN ANALYZE`, está no `analise-roadmap.md`):

1. **O problema não é "uma query lenta"**. É um clássico **N+1**: o
   `ReportController::topClientes()` busca os 5.000 clientes numa query, e
   depois, dentro de um `foreach` em PHP, dispara **uma query nova pra cada
   cliente** pra somar os pedidos dele nos últimos 12 meses. Ou seja, 5.001
   idas ao banco numa única requisição.
2. **Não existe nenhum índice além das chaves primárias** em `orders` e
   `order_items` (o próprio `db/schema.sql` deixa isso proposital, inclusive
   com comentário no arquivo). Então cada uma dessas 5.000 queries do loop
   precisa varrer a tabela inteira pra achar as linhas do cliente.
3. O agravante: o topo-20 é calculado **em PHP** (`usort` + `array_slice`)
   depois de já ter somado o total de todos os 5.000 clientes — o banco nunca
   chega a ordenar ou limitar nada, essa responsabilidade foi parar na
   aplicação.
4. Pra não ficar no achismo, rodamos `EXPLAIN ANALYZE` de uma dessas
   sub-queries isolada (cliente id=42) direto no Postgres do container: deu
   ~48,6ms, fazendo sequential scan paralelo em ~500 mil linhas de
   `order_items`. Multiplicando por 5.000 clientes bate exatamente com a
   ordem de grandeza dos 193s que eu vi na tela.

Ou seja: **índice sozinho não ia resolver** (ainda seriam 5.000 idas ao banco,
só que mais rápidas cada uma) e **reescrever a query sozinha, sem índice,
também não seria suficiente** pra bater a meta de 300ms. As duas coisas
precisam andar juntas.

## O que ficou decidido pra implementação

A partir do roadmap que o Claude estruturou, a Fase 1 vai mexer nestes
arquivos — e o motivo de cada um:

| Arquivo | O que muda | Por quê |
|---|---|---|
| `db/indexes.sql` (novo) | Criação de índice em `orders (customer_id, created_at)` e em `order_items (order_id)` | O enunciado pede que qualquer índice novo seja versionado num script SQL em `db/`, não aplicado manualmente só no meu ambiente. Sem esses índices, tanto a query antiga quanto a nova continuam varrendo a tabela inteira. |
| `src/app/Controllers/ReportController.php` | Troca do loop de 5.001 queries por **uma única query SQL** agregada (`JOIN` + filtro de 12 meses + `GROUP BY` + `ORDER BY` + `LIMIT 20`), removendo o `usort`/`array_slice` em PHP | É a causa raiz de verdade. Sem isso, não tem índice que resolva — o problema é a quantidade de idas ao banco, não a velocidade de cada uma. |
| `db/schema.sql` | **Não muda** | O schema em si (tabelas, PK) está correto pro desafio — a falta de índice é proposital, então a correção entra via `db/indexes.sql`, não alterando o schema base. |
| `src/app/Views/report.php` | Não deve precisar mudar | O resultado exibido (clientes, valores, contagem de pedidos) tem que continuar idêntico — só a forma de buscar o dado muda, não o formato de saída. Vou confirmar isso comparando a tela antes/depois. |

Depois de aplicar isso, o plano é medir de novo com `EXPLAIN ANALYZE` e com o
tempo que a própria página mostra, validar que os números batem com a versão
antiga (mesmos 20 clientes, mesmos valores) e só então documentar o
antes/depois no `SOLUCAO.md` que vai pra entrega final do desafio.

## Como foi a implementação, na prática

Aqui é onde a coisa ficou interessante — não foi só "criar índice e trocar a
query", teve umas voltas no meio do caminho que valem registrar.

**1. Índice sozinho, como eu esperava, não fez diferença nenhuma.** Criei o
`db/indexes.sql` com índice composto em `orders (customer_id, created_at)` e
outro em `order_items (order_id)`, apliquei, e rodei de novo a query agregada
única (já sem o N+1) via `EXPLAIN ANALYZE`. Tempo praticamente igual:
~475-483ms antes e depois do índice. Faz sentido — a nova query não faz mais
busca pontual por cliente, ela varre boa parte da tabela de uma vez (o filtro
de 12 meses pega uns 50% das linhas de `orders`), e pra esse volume o
Postgres prefere sequential scan mesmo. O índice em `customer_id` só ajudava
o padrão antigo (ponto a ponto por cliente), que não existe mais depois da
reescrita.

**2. A primeira versão da query única (483ms) ainda tinha um gargalo escondido.**
O `EXPLAIN ANALYZE` mostrou um **sort externo em disco de ~250 mil linhas**
(`Sort Method: external merge`) só pra conseguir fazer um `merge join` entre o
resultado agregado e a tabela `customers`. Reestruturei pra agregar por
`customer_id` primeiro e só aplicar `LIMIT 20` **antes** de tocar em
`customers` — caiu pra ~368ms.

**3. O verdadeiro vilão escondido era o `COUNT(DISTINCT o.id)`.** Esse
`DISTINCT` dentro do agregado obriga o Postgres a ordenar as linhas de cada
grupo pra poder deduplicar — ele não consegue usar hash aggregate (que é bem
mais rápido) quando tem um `COUNT(DISTINCT ...)` no meio. A saída foi separar
o cálculo em duas agregações independentes: uma soma `total_spent` (join
`orders` + `order_items`, sem DISTINCT nenhum) e outra conta `orders_count`
direto na tabela `orders` (nem precisa tocar em `order_items` pra isso). Duas
CTEs simples, cada uma virando um `HashAggregate` (inclusive paralelo, com
2 workers), juntadas só no final por `customer_id`. Resultado: **~206-330ms**
no banco, dependendo da execução.

**4. Validação de resultado.** Antes de trocar o `ReportController`, comparei
o top 20 gerado pela query nova com o resultado da query **antiga**, rodada
individualmente pra cada um desses 20 clientes (a mesma lógica de
`COUNT(DISTINCT o.id)` que tava no código original). Bateu valor por valor —
nome, total gasto, contagem de pedidos. Só depois disso troquei o código do
`ReportController::topClientes()`.

## Números — antes e depois

| Medição | Antes | Depois |
|---|---|---|
| Tempo mostrado na página (`/relatorio/top-clientes`) | 193.403 ms | ~200-330ms na página, média por volta de 250ms em 5 execuções (246, 245, 332, 220, 235 ms) |
| Queries executadas por requisição | 5.001 (1 + 5.000 no loop) | 1 (com duas CTEs internas) |
| `EXPLAIN ANALYZE` da query final no Postgres | — (N+1 não permite medir "uma" query) | ~206-310ms, sempre com `Buffers: shared hit` (zero leitura de disco) |

Fiquei de olho na meta de menos de 300ms: a maioria das execuções fica bem
abaixo, mas peguei alguns picos (309ms, 332ms) que passam um pouco do limite.
Investiguei via `EXPLAIN (ANALYZE, BUFFERS)` e confirmei que **não é o plano
de execução que piora** — os buffers batem sempre `shared hit` (dado já em
memória, sem I/O de disco) nas execuções lentas e rápidas. A variação parece
ser contenção de CPU do meu ambiente Docker local (workers paralelos
competindo por núcleo), não um problema de índice ou de query mal escrita.
Registro isso como trade-off/limitação honesta ao invés de forçar um número
"bonito" — em produção, com recursos dedicados, a tendência é essa variância
ficar bem menor.

De qualquer forma: **de ~193 segundos pra uma faixa de 200-330 milissegundos
é uma melhora de mais de 600x**, e isso sem mudar uma vírgula do resultado
exibido.

## Arquivos efetivamente alterados nesta fase

- `db/indexes.sql` (novo) — os dois índices.
- `src/app/Controllers/ReportController.php` — query única no lugar do loop.
- `db/schema.sql` e `src/app/Views/report.php` — confirmado que **não**
  precisaram mudar, como já era a expectativa.

## Os testes que eu rodei manualmente no navegador

Antes de fechar a fase, testei na unha, sem script, só recarregando a página
mesmo — queria ver com meus próprios olhos se o número se sustentava fora do
terminal.

**Sequência de testes (Firefox, indo em `http://localhost:8090/`, voltando e
gerando o relatório de novo a cada rodada):**

| # | Tempo mostrado na página |
|---|---|
| 1 | 196 ms |
| 2 | 235 ms |
| 3 | 209 ms |
| 4 | 196 ms |
| 5 | **452 ms** (pico) |

Deu exatamente o padrão que a IA também via pelo terminal: a maioria fica bem
abaixo de 300ms, mas de vez em quando aparece um pico.

**Depois derrubei o ambiente inteiro e subi de novo** (`docker compose down`
+ `docker compose up -d --build`) pra simular um restart do zero, e testei em
dois navegadores diferentes:

- Firefox: 230 ms, 230 ms
- Edge: 237 ms, 199 ms

Ou seja, o resultado não é fruto de cache do navegador nem de estado
acumulado do container — depois de reiniciar tudo, o comportamento (rápido na
maioria das vezes, com picos ocasionais) se repete.

## Os testes que a IA rodou pra investigar os picos

Quando relatei o pico de 452ms, pedi pra investigar antes de simplesmente
aceitar o resultado ou sair fazendo mudança maior de arquitetura. A IA rodou
uma bateria de testes direto no Postgres e na aplicação:

1. **Índice sozinho x banco sem índice nenhum, testado com a query final já
   reescrita**: dropou os dois índices do `db/indexes.sql` e rodou
   `EXPLAIN ANALYZE` 5x — deu 208-310ms, estatisticamente igual a com os
   índices (206-330ms). Conferindo o plano de execução, confirmou que a
   query final **nunca usa nenhum dos dois índices** (sempre `Seq Scan` em
   `orders` e `order_items`) — o filtro de 12 meses pega ~50% da tabela, e
   nessa proporção o Postgres sempre prefere varrer tudo a usar índice.
   Achado honesto: quem resolveu o Problema 1 foi a reescrita da query
   (eliminar o N+1), não o índice. Mantivemos os índices mesmo assim porque
   são boas práticas de suporte a FK e continuam úteis pra outros acessos
   pontuais (ex.: a query antiga usada na validação de resultado).
2. **`VACUUM ANALYZE` nas três tabelas**: depois da carga em massa do seed,
   as estatísticas do planner podem estar desatualizadas até o autovacuum
   rodar sozinho. Isso melhorou a consistência (201-283ms num teste, depois
   confirmado com ~216-245ms em 6 execuções). Por causa disso, o
   `db/indexes.sql` passou a terminar com `ANALYZE` nas três tabelas.
3. **Execução serial x paralela**: desabilitou os workers paralelos do
   Postgres (`max_parallel_workers_per_gather = 0`) pra ver se o paralelismo
   era parte do problema. Resultado: piorou bastante (345-534ms) — o
   paralelismo ajuda, não atrapalha.
4. **Checou limites de worker**: `max_worker_processes=8`,
   `max_parallel_workers=8`, e o container do banco tem 4 CPUs disponíveis
   (`nproc`). Ou seja, não é falta de capacidade configurada pro Postgres
   lançar os workers.
5. **Descartou causas na camada PHP/Apache**: confirmou que o OPcache está
   habilitado (então não é recompilação de script a cada request) e mediu a
   home page (`/`, sem query pesada) — ficou estável em 16-64ms, o que
   descarta lentidão de I/O do bind mount (`./src` montado do Windows pro
   container) como fator dominante.
6. **Correlacionou tempo total do `curl` com o tempo que a página reporta**
   (que só cobre a query PDO, sem contar `render()`/Apache): em 10
   execuções, o overhead fora da query ficou estável em ~30-75ms em todas as
   rodadas — inclusive nas lentas. Ou seja, quando o tempo total explode
   (ex.: 480ms de `curl`), é porque a query em si demorou mais (437ms), não
   porque o Apache ou o `render()` pioraram.

**Conclusão da investigação**: os picos ocasionais nascem dentro da execução
da query no Postgres (sempre com `Buffers: shared hit`, ou seja, sem leitura
de disco — não é I/O). A explicação mais plausível é contenção de CPU no meu
ambiente local (Docker Desktop no Windows dividindo núcleo com IDE, navegador
etc. enquanto os 2 workers paralelos do Postgres tentam rodar). Não é um
problema de índice, de plano de execução ruim, nem de arquitetura da query —
é o tipo de variância que se espera de um ambiente de desenvolvimento
compartilhando CPU, e que tende a ser bem menor num servidor dedicado.

## Trade-off assumido (fechando a Fase 1)

Decidi **aceitar esse resultado e documentar a limitação com transparência**,
em vez de perseguir soluções mais invasivas (como view materializada) que
fugiriam do escopo pedido no desafio ("reescrever a consulta, adicionar
índice(s), ou ambos") e trariam de volta problema de dado desatualizado — que
é exatamente o tipo de trade-off que o desafio pede pra resolver no Problema
2, não no 1.

- **Resultado típico**: ~200-330ms na grande maioria das execuções (bem
  dentro da meta de 300ms), tanto nos meus testes manuais no navegador
  (Firefox e Edge, antes e depois de recriar o ambiente do zero) quanto nos
  testes da IA via `curl`/`EXPLAIN ANALYZE`.
- **Picos ocasionais** (452ms no meu teste manual; 437ms e 470ms nos testes
  da IA) atribuídos a contenção de CPU do ambiente Docker local, com
  evidência de que não é problema de plano de execução, índice ausente, I/O
  de disco ou overhead de PHP/Apache — foi investigado e descartado item por
  item.
- **Ganho real**: de 193.403ms (baseline) pra essa faixa é uma melhora de
  **mais de 400-800x**, dependendo da execução usada como referência, sem
  mudar nenhum valor exibido no relatório.

## Próximo passo

Fase 1 encerrada, com o trade-off acima documentado também no
`analise-roadmap.md`. Agora sim, começar a analisar o Problema 2 (catálogo
sem cache) do zero, seguindo a mesma lógica: entender a causa raiz antes de
implementar.
