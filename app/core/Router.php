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
            $requestId = bin2hex(random_bytes(8));
            $details = [
                'request_id' => $requestId,
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
            error_log('[request_id=' . $requestId . '] Unhandled exception: ' . json_encode([
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'path' => $path,
                ...$details,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            header('X-Request-ID: ' . $requestId);
            http_response_code(500);
            if (strpos($path, '/api/') === 0) {
                Http::json(APP_DEBUG
                    ? ['error' => 'Lỗi máy chủ.', 'details' => $details]
                    : ['error' => 'Lỗi máy chủ.', 'request_id' => $requestId], 500);
            }
            header('Content-Type: text/plain; charset=utf-8');
            exit(APP_DEBUG
                ? "Lỗi máy chủ.\n" . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : "Lỗi máy chủ. Mã tra cứu: $requestId");
        }
    }
}
