<?php

/**
 * Relatório "Top clientes" (últimos 12 meses).
 */
class ReportController
{
    public function topClientes(): void
    {
        $start = microtime(true);

        $topCustomers = $this->fetchTopClientes();

        $elapsedMs = round((microtime(true) - $start) * 1000);

        render('report', [
            'customers' => $topCustomers,
            'elapsedMs' => $elapsedMs,
        ]);
    }

    /**
     * Pública para ser reaproveitada pela página de performance
     * (PerformanceController), que mede ao vivo o tempo desta mesma query
     * sem duplicá-la.
     */
    public function fetchTopClientes(): array
    {
        $pdo = Database::connection();

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

        return $topCustomers;
    }
}
