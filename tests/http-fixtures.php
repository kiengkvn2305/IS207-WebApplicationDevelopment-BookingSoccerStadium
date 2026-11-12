<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? '') === 'seed') {
    $ids = [];
    foreach ([3, 3, 2] as $userId) {
        Notification::create($userId, 'Kiểm tra thông báo', 'Thông báo dùng riêng cho kiểm thử đọc/chưa đọc.', 'dat-san');
        $ids[] = (int)Database::pdo()->lastInsertId();
    }
    echo json_encode($ids);
} elseif (($argv[1] ?? '') === 'cleanup') {
    $data = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    foreach ($data['notifications'] ?? [] as $id) {
        Database::q('DELETE FROM notifications WHERE id=?', [(int)$id]);
    }
    foreach ($data['emails'] ?? [] as $email) {
        if (!preg_match('/^http-audit-[a-z0-9-]+@example\.test$/', $email)) {
            throw new InvalidArgumentException('Unexpected fixture email.');
        }
        $user = User::byEmail($email);
        if ($user) {
            Database::q('DELETE FROM audit_logs WHERE user_id=?', [$user['id']]);
            Database::q('DELETE FROM users WHERE id=?', [$user['id']]);
        }
    }
}
