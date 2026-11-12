<?php

class AdminController extends Controller
{
    public function overview(): void
    {
        $this->guard(['admin']);
        Booking::expireHolds();
        Http::json(['stats' => User::stats(), 'users' => User::all(), 'bookings' => array_map(fn ($b) => $b + ['status_text' => STATUS_VI[$b['status']]], Booking::recentAll()), 'logs' => Audit::recent(), 'reviews' => Venue::adminReviews(), 'vouchers' => Voucher::all()]);
    }
    public function userAction(): void
    {
        $this->guard(['admin']);
        $b = $this->body();
        $action = (string)($b['action'] ?? '');
        $id = (int)($b['id'] ?? 0);
        if (!User::applyAction($action, $id)) {
            Http::error('Không thể thực hiện thao tác này.', 422);
        }
        $account = User::find($id);
        if ($account && $account['role'] !== 'admin') {
            $updates = [
                'approve' => ['Tài khoản chủ sân đã được duyệt', 'Quản trị viên đã duyệt tài khoản của bạn. Bạn có thể bắt đầu sử dụng hệ thống.'],
                'lock' => ['Tài khoản đã bị khóa', 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên nếu cần hỗ trợ.'],
                'unlock' => ['Tài khoản đã được mở khóa', 'Quản trị viên đã mở khóa tài khoản của bạn.']
            ];
            if (isset($updates[$action])) {
                Notification::create($id, $updates[$action][0], $updates[$action][1], $account['role'] === 'owner' ? 'chu-san' : 'dat-san');
            }
        }
        Audit::log("admin_$action", "user #$id");
        Http::json(['ok' => true]);
    }
    public function reviewAction(): void
    {
        $this->guard(['admin']);
        $body = $this->body();
        $id = (int)($body['id'] ?? 0);
        $visible = ($body['action'] ?? '') === 'show';
        $customerId = Notification::reviewCustomer($id);
        if (!in_array($body['action'] ?? '', ['hide', 'show'], true) || !Venue::setReviewVisibility($id, $visible)) {
            Http::error('Không thể cập nhật đánh giá này.', 422);
        }
        if ($customerId !== null) {
            Notification::create(
                $customerId,
                $visible ? 'Đánh giá đã được hiển thị' : 'Đánh giá đã bị ẩn',
                $visible ? 'Quản trị viên đã hiển thị lại đánh giá của bạn.' : 'Quản trị viên đã ẩn đánh giá của bạn khỏi trang công khai.',
                'dat-san'
            );
        }
        Audit::log('admin_review_' . ($visible ? 'show' : 'hide'), "review #$id");
        Http::json(['ok' => true]);
    }

    public function createVoucher(): void
    {
        $admin = $this->guard(['admin']);
        $body = $this->body();
        $code = strtoupper(trim((string)($body['code'] ?? '')));
        $type = (string)($body['discount_type'] ?? '');
        $value = filter_var($body['discount_value'] ?? null, FILTER_VALIDATE_INT);
        $minimum = filter_var($body['min_total'] ?? null, FILTER_VALIDATE_INT);
        $limitRaw = trim((string)($body['usage_limit'] ?? ''));
        $limit = $limitRaw === '' ? null : filter_var($limitRaw, FILTER_VALIDATE_INT);
        $startRaw = (string)($body['starts_at'] ?? '');
        $endRaw = (string)($body['ends_at'] ?? '');
        $start = DateTime::createFromFormat('Y-m-d\\TH:i', $startRaw);
        $end = DateTime::createFromFormat('Y-m-d\\TH:i', $endRaw);
        if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)
            || !in_array($type, ['percent', 'fixed'], true)
            || $value === false || $value < 1 || ($type === 'percent' && $value > 100) || ($type === 'fixed' && $value > 5000000)
            || $minimum === false || $minimum < 0 || $minimum > 100000000
            || ($limitRaw !== '' && ($limit === false || $limit < 1 || $limit > 1000000))
            || !$start || !$end || $start->format('Y-m-d\\TH:i') !== $startRaw || $end->format('Y-m-d\\TH:i') !== $endRaw
            || $end <= $start) {
            Http::error('Thông tin voucher không hợp lệ. Kiểm tra mã, mức giảm, giới hạn và thời hạn.', 422);
        }
        try {
            $id = Voucher::create([
                'code' => $code, 'discount_type' => $type, 'discount_value' => $value,
                'min_total' => $minimum, 'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'), 'usage_limit' => $limit,
            ], (int)$admin['id']);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                Http::error('Mã voucher đã tồn tại.', 409);
            }
            throw $error;
        }
        Audit::log('admin_voucher_create', $code);
        Http::json(['ok' => true, 'id' => $id]);
    }

    public function toggleVoucher(): void
    {
        $this->guard(['admin']);
        $body = $this->body();
        $id = (int)($body['id'] ?? 0);
        $active = filter_var($body['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!array_key_exists('active', $body) || $body['active'] === null
            || $id < 1 || $active === null || !Voucher::setActive($id, $active)) {
            Http::error('Không thể cập nhật voucher.', 422);
        }
        Audit::log('admin_voucher_' . ($active ? 'enable' : 'disable'), "voucher #$id");
        Http::json(['ok' => true]);
    }
}
