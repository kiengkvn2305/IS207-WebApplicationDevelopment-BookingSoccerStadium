<?php
class NotificationController extends Controller {
    function list(): void {
        $user = $this->guard(['customer', 'owner']);
        Http::json(Notification::listForUser((int)$user['id']));
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
