<?php
// Phục vụ các trang HTML tĩnh (app/views) – chỉ trả về nếu đã đăng nhập đúng vai trò.
// Thư mục views nằm NGOÀI web root nên không thể mở trực tiếp bằng đường dẫn file.
class PageController extends Controller {
    private function send(string $file, ?array $roles): void {
        if ($roles !== null) {
            $u = Auth::user();
            if (!$u) Http::redirect('login');
            if (!in_array($u['role'], $roles, true)) Http::redirect(Auth::home($u['role']));
        }
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
        readfile(__DIR__ . '/../views/' . $file . '.html'); exit;
    }
    function home(): void { $u = Auth::user(); Http::redirect($u ? Auth::home($u['role']) : 'dat-san'); }
    function login(): void { $u = Auth::user(); if ($u) Http::redirect(Auth::home($u['role'])); $this->send('login', null); }
    function booking(): void { $this->send('booking', null); }
    function owner(): void { $this->send('owner', ['owner']); }
    function admin(): void { $this->send('admin', ['admin']); }
}
