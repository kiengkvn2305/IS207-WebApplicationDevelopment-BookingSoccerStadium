<?php

// Test server only: php -S 127.0.0.1:8089 -t public tests/http-router.php
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(dirname(__DIR__) . '/public' . $path)) {
    return false;
}
require __DIR__ . '/bootstrap.php';
Auth::boot();
(require dirname(__DIR__) . '/app/routes.php')->dispatch();
