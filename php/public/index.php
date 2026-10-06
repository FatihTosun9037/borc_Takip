<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $publicRoot = realpath(__DIR__);
    $requestedFile = realpath($publicRoot . $requested);
    if ($publicRoot !== false && $requestedFile !== false && str_starts_with($requestedFile, $publicRoot . DIRECTORY_SEPARATOR)) {
        if ($requested !== '/' && $requestedFile !== $publicRoot) {
            return false;
        }
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/domain.php';
require dirname(__DIR__) . '/src/store.php';
require dirname(__DIR__) . '/src/routes.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = rtrim($path, '/') ?: '/';

try {
    dispatch($method, $path);
} catch (Throwable $error) {
    error_log($error->getMessage());
    if (str_starts_with($path, '/api/')) {
        json_out(['error' => 'Bir sorun oluştu.'], 500);
    }
    render('status', [
        'title' => 'Bir sorun oluştu',
        'body' => 'Uygulama beklenmeyen bir hatayla durdu.',
        'href' => '/',
        'action' => 'Başlangıca dön',
        'status' => 500,
    ]);
}
