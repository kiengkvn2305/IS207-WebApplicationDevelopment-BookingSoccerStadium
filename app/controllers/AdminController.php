<?php
class AdminController extends Controller {
    function overview(): void {
        $this->guard(['admin']); Booking::expireHolds();
        Http::json(['stats' => User::stats(), 'users' => User::all(), 'bookings' => array_map(fn($b) => $b + ['status_text' => STATUS_VI[$b['status']]], Booking::recentAll()), 'logs' => Audit::recent(), 'reviews' => Venue::adminReviews()]);
    }
    function userAction(): void {
        $this->guard(['admin']); $b = $this->body(); $action = (string)($b['action'] ?? ''); $id = (int)($b['id'] ?? 0);
        if (!User::applyAction($action, $id)) Http::error('Không thể thực hiện thao tác này.', 422);
        $account = User::find($id);
        if ($account && $account['role'] !== 'admin') {
            $updates = [
                'approve' => ['Tài khoản chủ sân đã được duyệt', 'Quản trị viên đã duyệt tài khoản của bạn. Bạn có thể bắt đầu sử dụng hệ thống.'],
                'lock' => ['Tài khoản đã bị khóa', 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên nếu cần hỗ trợ.'],
                'unlock' => ['Tài khoản đã được mở khóa', 'Quản trị viên đã mở khóa tài khoản của bạn.']
            ];
            if (isset($updates[$action])) Notification::create($id, $updates[$action][0], $updates[$action][1], $account['role'] === 'owner' ? 'chu-san' : 'dat-san');
        }
        Audit::log("admin_$action", "user #$id"); Http::json(['ok' => true]);
    }
    function reviewAction(): void {
        $this->guard(['admin']); $body = $this->body();
        $id = (int)($body['id'] ?? 0); $visible = ($body['action'] ?? '') === 'show';
        $customerId = Notification::reviewCustomer($id);
        if (!in_array($body['action'] ?? '', ['hide', 'show'], true) || !Venue::setReviewVisibility($id, $visible)) {
            Http::error('Không thể cập nhật đánh giá này.', 422);
        }
        if ($customerId !== null) Notification::create($customerId,
            $visible ? 'Đánh giá đã được hiển thị' : 'Đánh giá đã bị ẩn',
            $visible ? 'Quản trị viên đã hiển thị lại đánh giá của bạn.' : 'Quản trị viên đã ẩn đánh giá của bạn khỏi trang công khai.',
            'dat-san');
        Audit::log('admin_review_' . ($visible ? 'show' : 'hide'), "review #$id");
        Http::json(['ok' => true]);
    }
}
