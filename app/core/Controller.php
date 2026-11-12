<?php

abstract class Controller
{
    // Bắt buộc đăng nhập + đúng vai trò + CSRF (với POST). Dùng cho mọi API cần bảo vệ.
    protected function guard(array $roles): array
    {
        $user = Auth::user();
        if (!$user) {
            Http::error('Vui lòng đăng nhập.', 401);
        }
        if (!in_array($user['role'], $roles, true)) {
            Http::error('Bạn không có quyền thực hiện thao tác này.', 403);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::csrfOk()) {
            Http::error('Phiên không hợp lệ, hãy tải lại trang.', 419);
        }
        return $user;
    }
    protected function body(): array
    {
        static $body = null;
        if ($body !== null) {
            return $body;
        }
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw);
        if (!is_object($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            Http::error('Nội dung yêu cầu phải là một đối tượng JSON hợp lệ.', 400);
        }
        foreach (get_object_vars($decoded) as $field => $value) {
            if (is_object($value) || (is_array($value) && !in_array($field, ['hours', 'amenities'], true))) {
                Http::error('Kiểu dữ liệu của trường ' . $field . ' không hợp lệ.', 422);
            }
            if ($field === 'id' || str_ends_with($field, '_id')) {
                if ((!is_int($value) && !is_string($value))
                    || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    Http::error('Mã định danh không hợp lệ.', 422);
                }
            }
        }
        return $body = json_decode($raw, true);
    }
}
