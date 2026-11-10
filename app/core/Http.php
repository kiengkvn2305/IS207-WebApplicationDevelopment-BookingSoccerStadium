<?php
class Http {
    static function json(array $d, int $code = 200): void {
        http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
    }
    static function error(string $m, int $code = 400): void { self::json(['error' => $m], $code); }
    static function redirect(string $path): void { header('Location: ' . BASE . '/' . ltrim($path, '/')); exit; }
}
