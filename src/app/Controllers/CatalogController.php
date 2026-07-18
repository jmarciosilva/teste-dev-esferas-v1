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
    private const PER_PAGE = 50;

    public function index(): void
    {
        $category = !empty($_GET['category']) ? $_GET['category'] : null;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $start = microtime(true);

        // cachedCatalog() traz a categoria inteira (sem LIMIT) — a
        // agregação já roda sobre a base toda de qualquer forma (seção 2.4
        // do analise-problema02.md), então paginar é só uma fatia em
        // memória por cima do que já está cacheado, sem custo extra de
        // banco e sem precisar de chave de cache por página.
        $allProducts = $this->cachedCatalog($category);

        $totalItems = count($allProducts);
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page = min($page, $totalPages);

        $products = array_slice($allProducts, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $elapsedMs = round((microtime(true) - $start) * 1000);

        render('catalog', [
            'products' => $products,
            'category' => $category,
            'elapsedMs' => $elapsedMs,
            'categories' => $this->categories(),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
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
            RETURNING category, price, stock
        ');
        $stmt->execute(['price' => $price, 'stock' => $stock, 'id' => $id]);
        $updated = $stmt->fetch();

        header('Content-Type: application/json');

        if ($updated === false) {
            http_response_code(404);
            echo json_encode(['message' => 'Produto não encontrado.']);
            return;
        }

        $this->invalidateCache($updated['category']);

        // Devolve os valores confirmados pelo banco (não os que vieram do
        // formulário) — se o usuário deixar um campo em branco, o COALESCE
        // mantém o valor antigo, e é esse valor real que a tela precisa
        // refletir, não o que foi (ou não foi) digitado.
        echo json_encode([
            'message' => 'Produto atualizado com sucesso.',
            'price' => money((float) $updated['price']),
            'stock' => (int) $updated['stock'],
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
        $redis = RedisClient::tryConnection();

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
        $redis = RedisClient::tryConnection();
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

    /**
     * Pública para ser reaproveitada pela página de performance
     * (PerformanceController), que precisa montar a mesma chave pra medir
     * miss/hit no cache real do catálogo, sem duplicar o formato aqui.
     */
    public function cacheKey(?string $category): string
    {
        return self::CACHE_PREFIX . ($category ?: 'all');
    }

    /**
     * Pública para ser reaproveitada pela página de performance, que
     * precisa medir o tempo da mesma consulta usada em produção sem
     * duplicar a query de agregação aqui.
     */
    public function fetchCatalog(?string $category): array
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

        $sql .= ' ORDER BY p.name';

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
