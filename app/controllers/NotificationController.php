<?php
class NotificationController extends Controller {
    function list(): void {
        $user = $this->guard(['customer', 'owner']);
        Http::json(Notification::listForUser((int)$user['id']));
    }

    function wsTicket(): void {
        $user = $this->guard(['customer', 'owner']);
        if (NOTIFICATION_WS_URL === '' || strlen(NOTIFICATION_WS_SECRET) < 32) {
            Http::error('Thông báo thời gian thực chưa được cấu hình; chế độ cập nhật định kỳ vẫn hoạt động.', 503);
        }
        $wsUrl = parse_url(NOTIFICATION_WS_URL);
        if (!$wsUrl || !in_array($wsUrl['scheme'] ?? '', ['ws', 'wss'], true)
            || empty($wsUrl['host']) || isset($wsUrl['user']) || isset($wsUrl['pass'])) {
            Http::error('Địa chỉ WebSocket chưa được cấu hình hợp lệ.', 503);
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::\d{1,5})?$/', $host)) {
            Http::error('Không xác định được nguồn trang an toàn cho WebSocket.', 400);
        }
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
                && strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https');
        $origin = ($secure ? 'https://' : 'http://') . strtolower($host);
        $sinceId = filter_var($_GET['since_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($sinceId === false || $sinceId < 0) Http::error('Mốc thông báo không hợp lệ.', 422);
        $payload = json_encode([
            'sub' => (int)$user['id'],
            'since' => $sinceId,
            'exp' => time() + 60,
            'nonce' => bin2hex(random_bytes(16)),
            'origin' => $origin
        ], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, NOTIFICATION_WS_SECRET, true);
        $ticket = $encoded . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        Http::json(['url' => NOTIFICATION_WS_URL, 'ticket' => $ticket]);
    }

    function markRead(): void {
        $user = $this->guard(['customer', 'owner']);
        $id = (int)($this->body()['id'] ?? 0);
        if ($id < 1 || !Notification::markRead($id, (int)$user['id'])) {
            Http::error('Không tìm thấy thông báo.', 404);
        }
        Http::json(['ok' => true]);
    }

    function markAllRead(): void {
        $user = $this->guard(['customer', 'owner']);
        Notification::markAllRead((int)$user['id']);
        Http::json(['ok' => true]);
    }
}
