<?php

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/RedisClient.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/Controllers/HomeController.php';
require __DIR__ . '/../app/Controllers/ReportController.php';
require __DIR__ . '/../app/Controllers/CatalogController.php';
require __DIR__ . '/../app/Controllers/PerformanceController.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

try {
    if ($method === 'GET' && $path === '/') {
        (new HomeController())->index();
        return;
    }

    if ($method === 'GET' && $path === '/relatorio/top-clientes') {
        (new ReportController())->topClientes();
        return;
    }

    if ($method === 'GET' && $path === '/catalogo') {
        (new CatalogController())->index();
        return;
    }

    if ($method === 'POST' && preg_match('#^/produtos/(\d+)$#', $path, $m)) {
        (new CatalogController())->update((int) $m[1]);
        return;
    }

    if ($method === 'GET' && $path === '/performance') {
        (new PerformanceController())->index();
        return;
    }

    http_response_code(404);
    echo '404 - Página não encontrada';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
}
