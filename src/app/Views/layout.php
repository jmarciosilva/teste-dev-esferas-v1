<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teste Técnico Esferas Software</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="topbar">
        <a href="/" class="brand">
            <span class="brand-mark" aria-hidden="true"></span>
            Esferas <span class="brand-sep">&middot;</span> Teste Técnico
        </a>
        <nav>
            <a href="/relatorio/top-clientes">Relatório de Clientes</a>
            <a href="/catalogo">Catálogo de Produtos</a>
            <a href="/performance">Performance</a>
        </nav>
    </header>

    <main class="container">
        <?= $content ?>
    </main>

    <div class="modal-overlay" id="product-modal" hidden>
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
            <div class="modal-icon" id="product-modal-icon">&check;</div>
            <h2 class="modal-title" id="product-modal-title">Produto atualizado</h2>
            <p class="modal-message" id="product-modal-message"></p>
            <button type="button" class="modal-close" id="product-modal-close">Fechar</button>
        </div>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>
