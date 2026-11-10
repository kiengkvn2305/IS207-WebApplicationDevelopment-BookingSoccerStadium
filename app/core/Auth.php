<?php
class Auth {
    private static $cache = false;

    static function boot(): void {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('sannha_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => self::isSecureRequest()]);
        session_start();
        $ws = parse_url(NOTIFICATION_WS_URL);
        $wsSource = $ws && in_array($ws['scheme'] ?? '', ['ws', 'wss'], true) && !empty($ws['host'])
            ? $ws['scheme'] . '://' . (strpos($ws['host'], ':') === false ? $ws['host'] : '[' . $ws['host'] . ']')
                . (isset($ws['port']) ? ':' . $ws['port'] : '')
            : '';
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' https://img.vietqr.io data:; "
             . "connect-src 'self' " . $wsSource . "; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
             . "script-src 'self'; frame-ancestors 'none'; form-action 'self'");
    }
    private static function isSecureRequest(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return in_array($remote, ['127.0.0.1', '::1'], true)
            && strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
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
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$cache = null;
    }
}
