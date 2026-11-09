<?php
class Router {
    private array $routes = [];
    function add(string $method, string $path, string $class, string $fn): void { $this->routes[$method][$path] = [$class, $fn]; }
    function dispatch(): void {
        $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = '/' . trim(substr($path, strlen(BASE)), '/');
        $h = $this->routes[$_SERVER['REQUEST_METHOD']][$path] ?? null;
        if (!$h) {
            if (strpos($path, '/api/') === 0) Http::error('Không tìm thấy.', 404);
            http_response_code(404); exit('Không tìm thấy trang.');
        }
        try { (new $h[0])->{$h[1]}(); }
        catch (Throwable $e) {
            if (strpos($path, '/api/') === 0) Http::error('Lỗi máy chủ.', 500);
            http_response_code(500); exit('Lỗi máy chủ.');
        }
    }
}
