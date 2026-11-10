<?php
// Đọc file .env đơn giản (KEY=VALUE), không cần Composer.
class Env {
    private static array $vars = [];
    static function load(string $file): void {
        if (!is_file($file)) {
            http_response_code(500);
            exit('Thiếu file .env. Hãy copy .env.example thành .env rồi điền thông tin database.');
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
            self::$vars[trim($k)] = $v;
        }
    }
    static function get(string $key, string $default = ''): string {
        return self::$vars[$key] ?? (getenv($key) !== false ? getenv($key) : $default);
    }
}
