<?php
abstract class Controller {
    // Bắt buộc đăng nhập + đúng vai trò + CSRF (với POST). Dùng cho mọi API cần bảo vệ.
    protected function guard(array $roles): array {
        $u = Auth::user();
        if (!$u) Http::error('Vui lòng đăng nhập.', 401);
        if (!in_array($u['role'], $roles, true)) Http::error('Bạn không có quyền thực hiện thao tác này.', 403);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::csrfOk()) Http::error('Phiên không hợp lệ, hãy tải lại trang.', 419);
        return $u;
    }
    protected function body(): array {
        static $b = null;
        return $b ??= (json_decode(file_get_contents('php://input'), true) ?: []);
    }
}
