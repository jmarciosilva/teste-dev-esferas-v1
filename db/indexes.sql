-- Índices adicionais para o Problema 1 (Relatório de Top Clientes).
--
-- Schema original só tem as PRIMARY KEYs (nenhuma FK tem índice de suporte).
-- Estes dois índices cobrem os padrões de acesso por cliente/pedido
-- (ex.: "pedidos de um cliente específico", usada para validar o resultado
-- da query final e presente na implementação original ingênua do relatório).
--
-- Nota honesta: medimos com EXPLAIN ANALYZE e, para a query final do
-- relatório (agregação em massa, filtro de 12 meses cobrindo ~50% de
-- `orders`), o planner do Postgres opta por sequential scan de qualquer
-- forma — a seletividade é baixa demais para compensar um index scan, com
-- ou sem estes índices. A causa raiz da lentidão do relatório era o N+1 de
-- queries no PHP, não a ausência de índice (ver analise-problema01.md).
-- Mesmo assim, mantemos os índices por boa prática (suporte de FK) e porque
-- seguem úteis para outras consultas pontuais por cliente/pedido.
--
-- Uso (dentro do container app, ou via psql direto no serviço db):
--   docker compose exec -T db psql -U teste_esferas -d teste_esferas -f db/indexes.sql

CREATE INDEX IF NOT EXISTS idx_orders_customer_id_created_at
    ON orders (customer_id, created_at);

CREATE INDEX IF NOT EXISTS idx_order_items_order_id
    ON order_items (order_id);

-- Atualiza as estatísticas do planner. Depois de uma carga em massa
-- (db/seed.php) ou da criação de índices, o autovacuum pode demorar a
-- rodar sozinho; um ANALYZE explícito garante que o planner já enxergue
-- as estatísticas corretas (cardinalidade, distribuição) na primeira
-- consulta, sem depender do agendamento do autovacuum.
ANALYZE customers;
ANALYZE orders;
ANALYZE order_items;
