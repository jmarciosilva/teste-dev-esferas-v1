<?php

/**
 * Catálogo de produtos, com cache-aside no Redis.
 *
 * Estratégia (detalhada em analise-problema02.md):
 * - Chave: catalog:v1:products:{categoria|all} — uma entrada por filtro.
 * - TTL: 5 minutos, rede de segurança (o único dado mutável é price/stock,
 *   coberto por invalidação ativa em update()).
 * - Invalidação: update() usa RETURNING category pra descobrir, numa única
 *   ida ao banco, quais das duas chaves afetadas (a categoria do produto
 *   + "all") precisam ser removidas do cache.
 */
class CatalogController
{
    private const CACHE_PREFIX = 'catalog:v1:products:';
    private const CACHE_TTL = 300;

    public function index(): void
    {
        $category = !empty($_GET['category']) ? $_GET['category'] : null;
        $start = microtime(true);

        $products = $this->cachedCatalog($category);

        $elapsedMs = round((microtime(true) - $start) * 1000);

        render('catalog', [
            'products' => $products,
            'category' => $category,
            'elapsedMs' => $elapsedMs,
            'categories' => $this->categories(),
        ]);
    }

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
        $category = $stmt->fetchColumn();

        if ($category !== false) {
            $this->invalidateCache($category);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'message' => 'Produto atualizado. Se o catálogo estiver em cache, ele precisa refletir esta mudança.',
        ]);
    }

    /**
     * Cache-aside com fallback: se o Redis estiver indisponível em qualquer
     * ponto (conexão, leitura ou escrita), a função degrada para servir
     * direto do banco em vez de derrubar a página inteira. O cache é uma
     * otimização de performance, não deveria ser um ponto único de falha
     * para o catálogo funcionar.
     */
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
                $redis = null; // Redis falhou no meio da leitura — segue sem cache.
            }
        }

        $products = $this->fetchCatalog($category);

        if ($redis !== null) {
            try {
                $redis->setex($key, self::CACHE_TTL, json_encode($products));
            } catch (Throwable $e) {
                // Ignora falha ao popular o cache — a leitura já foi resolvida pelo banco.
            }
        }

        return $products;
    }

    private function invalidateCache(string $productCategory): void
    {
        $redis = $this->redisOrNull();
        if ($redis === null) {
            return;
        }

        try {
            $redis->del($this->cacheKey(null));
            $redis->del($this->cacheKey($productCategory));
        } catch (Throwable $e) {
            // Ignora falha ao invalidar — a escrita no Postgres já foi concluída;
            // o TTL (seção 4.2 do analise-problema02.md) é a rede de segurança
            // pra esse cenário.
        }
    }

    private function redisOrNull(): ?Redis
    {
        try {
            return RedisClient::connection();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function cacheKey(?string $category): string
    {
        return self::CACHE_PREFIX . ($category ?: 'all');
    }

    private function fetchCatalog(?string $category): array
    {
        $pdo = Database::connection();

        $sql = '
            SELECT
                p.id,
                p.name,
                p.category,
                p.price,
                p.stock,
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

    private function categories(): array
    {
        $pdo = Database::connection();

        return $pdo->query('SELECT DISTINCT category FROM products ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
    }
}
