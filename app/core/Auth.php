<?php

class Auth
{
    private static $cache = false;

    public static function boot(): void
    {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off']);
        ini_set('session.use_strict_mode', '1');
        session_start();
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' https://img.vietqr.io data:; "
             . "style-src 'self' 'unsafe-inline'; font-src 'self'; "
             . "script-src 'self'; frame-ancestors 'none'; form-action 'self'");
    }
    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }
    public static function csrfOk(): bool
    {
        $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($t) && $t !== '' && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
    }
    public static function home(string $role): string
    {
        return ['admin' => 'admin', 'owner' => 'chu-san', 'customer' => 'dat-san'][$role];
    }

    // Luôn đọc lại DB: khóa tài khoản / đổi quyền có hiệu lực ngay
    public static function user(): ?array
    {
        if (self::$cache !== false) {
            return self::$cache;
        }
        if (empty($_SESSION['uid'])) {
            return self::$cache = null;
        }
        $u = User::find((int)$_SESSION['uid']);
        if (!$u || $u['status'] !== 'active') {
            $_SESSION = [];
            return self::$cache = null;
        }
        return self::$cache = $u;
    }
    public static function login(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION = ['uid' => $id, 'csrf' => bin2hex(random_bytes(32))];
        self::$cache = false;
    }
    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        self::$cache = null;
    }
}
