<?php
class Auth {
    private static $cache = false;

    static function boot(): void {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' https://img.vietqr.io data:; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
             . "script-src 'self'; frame-ancestors 'none'; form-action 'self'");
    }
    static function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }
    static function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
    static function csrfOk(): bool {
        $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($t) && hash_equals($_SESSION['csrf'] ?? '', $t);
    }
    static function home(string $role): string { return ['admin' => 'admin', 'owner' => 'chu-san', 'customer' => 'dat-san'][$role]; }

    // Luôn đọc lại DB: khóa tài khoản / đổi quyền có hiệu lực ngay
    static function user(): ?array {
        if (self::$cache !== false) return self::$cache;
        if (empty($_SESSION['uid'])) return self::$cache = null;
        $u = User::find((int)$_SESSION['uid']);
        if (!$u || $u['status'] !== 'active') { $_SESSION = []; return self::$cache = null; }
        return self::$cache = $u;
    }
    static function login(int $id): void {
        session_regenerate_id(true);
        $_SESSION = ['uid' => $id, 'csrf' => bin2hex(random_bytes(32))];
        self::$cache = false;
    }
    static function logout(): void {
        $_SESSION = []; session_destroy(); self::$cache = null;
    }
}
