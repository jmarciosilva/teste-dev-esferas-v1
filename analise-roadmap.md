# Análise e Roadmap — Desafio Técnico Esferas Software

> Documento de trabalho (não faz parte da entrega final). Serve para registrar o
> diagnóstico técnico e o plano de execução antes de mexer no código. O resumo
> final para quem avalia vai para `SOLUCAO.md`.

## Ambiente observado

- App: http://localhost:8090 (rodando via `docker compose up -d --build`)
- Banco populado com o seed padrão: **5.000 clientes, 3.000 produtos, 200.000
  pedidos, 499.524 itens de pedido, 60.000 avaliações**.
- Execução real do relatório: **193.403 ms** (~193s) — confirma o enunciado
  ("pode levar mais de 30s").

---

## Problema 1 — Relatório de Top Clientes (`/relatorio/top-clientes`)

### Causa raiz

Duas causas independentes, que se multiplicam:

**a) N+1 queries.** `ReportController::topClientes()` (src/app/Controllers/ReportController.php:16-34)
faz:
1. `SELECT id, name, email, city FROM customers` → 5.000 linhas.
2. Um **loop PHP** que, para *cada* cliente, dispara uma query separada
   agregando `orders` + `order_items` filtrando os últimos 12 meses.

Ou seja, **5.001 queries** por requisição. Pior: a agregação e o `usort()` +
`array_slice()` para pegar o top 20 são feitos **em PHP**, depois de já ter
buscado e somado o total de todos os 5.000 clientes — o banco nunca faz o
trabalho de ordenar/limitar, o PHP que faz.

**b) Nenhum índice além das PKs.** `db/schema.sql` só declara `PRIMARY KEY` em
cada tabela (comentário no próprio arquivo confirma isso é proposital). Não
existe índice em `orders.customer_id` nem em `order_items.order_id`. Resultado
confirmado via `\d orders` / `\d order_items`: só `orders_pkey` e
`order_items_pkey`.

Isso força cada uma das 5.000 queries do loop a fazer **sequential scan
paralelo** nas duas tabelas (200k linhas em `orders`, ~500k em `order_items`)
para achar as poucas linhas do cliente em questão.

### Evidência (`EXPLAIN ANALYZE` real, cliente id=42)

```
Aggregate (actual time=45.621..48.549 rows=1 loops=1)
  -> Gather (actual time=13.007..48.281 rows=65 loops=1)
        Workers Launched: 2
        -> Parallel Hash Join (actual time=10.096..41.716 rows=22 loops=3)
              -> Parallel Seq Scan on order_items oi (rows=166508 loops=3)   -- 499k linhas total
              -> Parallel Hash
                    -> Parallel Seq Scan on orders o (rows=8 loops=3)         -- filtro customer_id + data
                          Filter: (customer_id = 42 AND created_at >= now() - 1 year)
                          Rows Removed by Filter: 66658
Execution Time: 48.632 ms
```

**~48,6ms por cliente × 5.000 clientes ≈ 243s** — consistente com os 193s
observados na página (a variação vem de cache de buffer do Postgres entre
execuções e concorrência dos workers paralelos).

Mesmo com o join paralelizado pelo planner, o Postgres não tem escolha: sem
índice em `customer_id`, a única forma de achar os pedidos de um cliente é
varrer a tabela inteira. Rodar isso 5.000 vezes é o verdadeiro custo — o
problema não é uma query lenta, é uma query razoavelmente rápida **executada
5.000 vezes a mais do que precisa**.

### Correção proposta

1. **Eliminar o N+1**: substituir o loop PHP por **uma única query SQL** que
   já faz `JOIN` + `WHERE` (janela de 12 meses) + `GROUP BY customer_id` +
   `ORDER BY total_spent DESC` + `LIMIT 20`. O banco faz a agregação e a
   ordenação — PHP só formata a saída. Isso também elimina o `usort`/`array_slice`
   em memória sobre os 5.000 clientes.
   - Atenção ao **resultado não pode mudar**: a query atual conta `orders`
     mesmo que não tenham `order_items`? Não — o `JOIN` (não `LEFT JOIN`) já
     exclui clientes sem pedido no período, e o `COALESCE` nunca entra em jogo
     porque a agregação por `customer_id` só existe se há ao menos 1 order_item.
     A reescrita precisa preservar esse comportamento (clientes sem pedidos no
     período somem do ranking, como hoje).
2. **Adicionar índices** (via `db/indexes.sql`, script versionado):
   - `orders (customer_id, created_at)` — cobre o filtro por cliente + data,
     e ajuda tanto a query nova quanto qualquer filtro futuro por período.
   - `order_items (order_id)` — acelera o join com `orders` (hoje é seq scan
     nas ~500k linhas).
   - Avaliar se um índice composto/covering em `orders` também ajuda a
     query agregada final a evitar acessar a tabela toda — validar com
     `EXPLAIN ANALYZE` depois de escrita a query definitiva.
3. Validar meta de **< 300ms** com `EXPLAIN ANALYZE` da query final e com o
   tempo que a própria página já exibe.
4. Confirmar que os valores (clientes, total gasto, contagem de pedidos)
   batem exatamente com a versão antiga antes da correção (comparar
   top 20 e valores um a um, ou script de diff).

### Trade-offs / decisões a documentar depois

- Reescrever a query é obrigatório (o N+1 sozinho já inviabiliza qualquer
  meta de 300ms, independente de índice). Índice sozinho não resolve — ajuda
  a query individual, mas 5.000 execuções de 5-10ms ainda dariam ~30-50s.
- Considerar se `COUNT(DISTINCT o.id)` na versão agregada continua necessário
  ou se dá para usar `COUNT(o.id)` com uma subquery/CTE que já agrega por
  pedido antes de somar itens (evita duplicar `orders_count` quando um pedido
  tem múltiplos itens). Precisa ser validado com dado real antes de trocar.

---

## Roadmap de execução

### Fase 0 — Diagnóstico (concluída)
- [x] Rodar a aplicação, observar tempo real do relatório (193.403 ms).
- [x] Ler `ReportController`, `CatalogController`, `schema.sql`, `seed.php`.
- [x] Confirmar ausência de índices (`\d orders`, `\d order_items`).
- [x] Rodar `EXPLAIN ANALYZE` da query por-cliente e validar a causa raiz
      (N+1 + seq scan) com números reais.
- [x] Registrar este documento.

### Fase 1 — Problema 1 (Relatório de Top Clientes) — concluída
- [x] Criar `db/indexes.sql` com os índices em `orders(customer_id, created_at)`
      e `order_items(order_id)`.
- [x] Aplicar o script no ambiente local e medir o `EXPLAIN ANALYZE` da
      query agregada isolada (ainda sem reescrever o controller) para
      isolar o ganho vindo só do índice.
- [x] Reescrever `ReportController::topClientes()` para uma única query
      SQL, removendo o loop PHP e o `usort`/`array_slice`.
- [x] Validar que o resultado (clientes, total gasto, contagem de pedidos)
      é idêntico ao comportamento atual.
- [x] Medir tempo final na página (< 300ms) e capturar antes/depois
      (tempo + plano de execução) para o `SOLUCAO.md`.

**Resultado**: ver detalhamento completo (evolução da query, números e
trade-offs) em `analise-problema01.md`. Resumo: 193.403ms → ~200-330ms na
página (média ~250ms), query no banco medida via `EXPLAIN ANALYZE` em
~210-310ms, sem leitura de disco (`Buffers: shared hit` só, sem `read`) —
a variação restante é contenção de CPU do ambiente Docker local, não do
plano de execução.

**Trade-off assumido / picos ocasionais acima de 300ms**: tanto em testes
manuais no navegador (Firefox e Edge, inclusive depois de recriar o ambiente
do zero com `docker compose down` + `up -d --build`) quanto em testes
automatizados (`curl` + `EXPLAIN ANALYZE`), a grande maioria das execuções
fica entre 200-330ms, mas surgem picos isolados (452ms num teste manual,
437ms/470ms em testes via terminal). Investigação dedicada descartou, um a
um: índice ausente (query final não usa os índices novos — confirmado
dropando-os e comparando), leitura de disco (sempre `Buffers: shared hit`),
execução serial vs. paralela (paralelo é mais rápido, não a causa), limite de
workers do Postgres (4 CPUs disponíveis, `max_parallel_workers=8`), e
overhead de PHP/Apache/OPcache (medido isoladamente, estável em ~30-75ms em
toda execução, inclusive nas lentas). Conclusão: contenção de CPU do
ambiente Docker/Windows local, não um problema arquitetural da query. Aceito
como trade-off documentado em vez de perseguir solução mais invasiva (ex.:
view materializada), que traria de volta risco de dado desatualizado — fora
do escopo do Problema 1. Detalhamento completo dos testes (manuais e da IA)
em `analise-problema01.md`.

### Fase 2 — Problema 2 (Catálogo com Redis)
- [ ] Analisar o código do `/catalogo` (`CatalogController`) e a causa raiz
      da falta de cache — a ser feito depois de fechar a Fase 1.
- [ ] Definir estratégia de cache (chave, TTL, invalidação) com base nessa
      análise.
- [ ] Detalhar as tarefas desta fase no roadmap assim que a análise estiver
      pronta.

### Fase 3 — Fechamento
- [ ] Revisar diffs, rodar a aplicação ponta a ponta (relatório + catálogo)
      no browser.
- [ ] Escrever `SOLUCAO.md` com antes/depois de performance, estratégia de
      cache e trade-offs/suposições assumidas.
- [ ] Conferir se algo do enunciado ficou pendente.
