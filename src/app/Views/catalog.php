<?php
function catalog_page_url(int $page, ?string $category): string
{
    $params = ['page' => $page];
    if ($category) {
        $params['category'] = $category;
    }

    return '/catalogo?' . http_build_query($params);
}
?>
<div class="card">
    <h1>Catálogo de Produtos</h1>
    <p>
        Tempo de geração:
        <span class="badge timing <?= $elapsedMs < 100 ? 'fast' : '' ?>"><?= $elapsedMs ?> ms</span>
        <span class="muted">(cache-aside no Redis; recalcula só em cache miss ou após uma atualização)</span>
    </p>

    <form class="filters" method="get" action="/catalogo">
        <label for="category">Categoria:</label>
        <select name="category" id="category" onchange="this.form.submit()">
            <option value="">Todas</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Produto</th>
                <th>Categoria</th>
                <th>Preço</th>
                <th>Estoque</th>
                <th>Avaliação</th>
                <th>Vendidos</th>
                <th>Atualizar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <tr data-product-row="<?= $product['id'] ?>" data-product-name="<?= htmlspecialchars($product['name']) ?>">
                    <td><?= htmlspecialchars($product['name']) ?></td>
                    <td><?= htmlspecialchars($product['category']) ?></td>
                    <td data-field="price"><?= money((float) $product['price']) ?></td>
                    <td data-field="stock"><?= $product['stock'] ?></td>
                    <td><?= number_format((float) $product['avg_rating'], 1) ?> (<?= $product['reviews_count'] ?>)</td>
                    <td><?= $product['total_sold'] ?></td>
                    <td>
                        <form class="product-actions" data-product-update method="post" action="/produtos/<?= $product['id'] ?>">
                            <input type="number" step="0.01" name="price" placeholder="Preço">
                            <input type="number" name="stock" placeholder="Estoque">
                            <button type="submit">Salvar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Paginação do catálogo">
            <?php if ($page > 1): ?>
                <a class="pagination-link" href="<?= catalog_page_url($page - 1, $category) ?>">&larr; Anterior</a>
            <?php else: ?>
                <span class="pagination-link pagination-link--disabled">&larr; Anterior</span>
            <?php endif; ?>

            <span class="pagination-status">Página <?= $page ?> de <?= $totalPages ?> (<?= $totalItems ?> produtos)</span>

            <?php if ($page < $totalPages): ?>
                <a class="pagination-link" href="<?= catalog_page_url($page + 1, $category) ?>">Próxima &rarr;</a>
            <?php else: ?>
                <span class="pagination-link pagination-link--disabled">Próxima &rarr;</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
