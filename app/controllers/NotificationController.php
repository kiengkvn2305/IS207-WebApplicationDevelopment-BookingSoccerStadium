<?php

class NotificationController extends Controller
{
    public function list(): void
    {
        $user = $this->guard(['customer', 'owner']);
        Http::json(Notification::listForUser((int)$user['id']));
    }

    public function markRead(): void
    {
        $this->updateReadState(true);
    }

    public function markUnread(): void
    {
        $this->updateReadState(false);
    }

    private function updateReadState(bool $isRead): void
    {
        $user = $this->guard(['customer', 'owner']);
        $id = filter_var($this->body()['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            Http::error('Mã thông báo không hợp lệ.', 422);
        }

        $updated = $isRead
            ? Notification::markRead($id, (int)$user['id'])
            : Notification::markUnread($id, (int)$user['id']);
        if (!$updated) {
            Http::error('Không tìm thấy thông báo.', 404);
        }

        Http::json(['ok' => true, 'is_read' => $isRead] + Notification::listForUser((int)$user['id']));
    }

    public function markAllRead(): void
    {
        $user = $this->guard(['customer', 'owner']);
        Notification::markAllRead((int)$user['id']);
        Http::json(['ok' => true] + Notification::listForUser((int)$user['id']));
    }

    public function markAllUnread(): void
    {
        $user = $this->guard(['customer', 'owner']);
        Notification::markAllUnread((int)$user['id']);
        Http::json(['ok' => true] + Notification::listForUser((int)$user['id']));
    }
}
