<?php

class AuthController extends Controller
{
    public function me(): void
    {
        $u = Auth::user();
        Http::json(['csrf' => Auth::csrf(), 'user' => $u ? [
            'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'],
            'role' => $u['role'], 'profile_version' => (int)$u['profile_version'], 'created_at' => $u['created_at']] : null]);
    }
    public function login(): void
    {
        if (!Auth::csrfOk()) {
            Http::error('Phiên không hợp lệ, hãy tải lại trang.', 419);
        }
        $b = $this->body();
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $pw = (string)($b['password'] ?? '');
        if (User::failedAttempts($email, Auth::ip()) >= 5) {
            Http::error('Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.', 429);
        }
        $u = User::byEmail($email);
        if (!$u || !password_verify($pw, $u['password_hash'])) {
            User::logFail($email, Auth::ip());
            Http::error('Email hoặc mật khẩu không đúng.', 422);
        }
        if ($u['status'] === 'pending') {
            Http::error('Tài khoản chủ sân đang chờ admin duyệt.', 403);
        }
        if ($u['status'] === 'locked') {
            Http::error('Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên.', 403);
        }
        Auth::login((int)$u['id']);
        User::clearFails($email);
        Audit::log('login', '', (int)$u['id']);
        Http::json(['redirect' => Auth::home($u['role'])]);
    }
    public function register(): void
    {
        if (!Auth::csrfOk()) {
            Http::error('Phiên không hợp lệ, hãy tải lại trang.', 419);
        }
        $b = $this->body();
        $name = trim((string)($b['name'] ?? ''));
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $phone = preg_replace('/\s+/', '', (string)($b['phone'] ?? ''));
        $pw = (string)($b['password'] ?? '');
        $role = ($b['role'] ?? '') === 'owner' ? 'owner' : 'customer';
        $venue = trim((string)($b['venue'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            Http::error('Họ tên từ 2–60 ký tự.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            Http::error('Email không hợp lệ.', 422);
        }
        if (!preg_match('/^(0|\+84)\d{9}$/', $phone)) {
            Http::error('Số điện thoại không hợp lệ.', 422);
        }
        if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            Http::error('Mật khẩu tối thiểu 8 ký tự, gồm cả chữ và số.', 422);
        }
        if (strlen($pw) > 72) {
            Http::error('Mật khẩu tối đa 72 byte.', 422);
        }
        if ($role === 'owner' && (mb_strlen($venue) < 2 || mb_strlen($venue) > 100)) {
            Http::error('Tên cụm sân từ 2–100 ký tự.', 422);
        }
        if (!User::create($name, $email, $phone, $pw, $role, $venue)) {
            Http::error('Email này đã được sử dụng.', 409);
        }
        Http::json(['message' => $role === 'owner' ? 'Đã đăng ký. Vui lòng chờ admin duyệt tài khoản chủ sân.' : 'Đăng ký thành công. Mời bạn đăng nhập.']);
    }
    public function updateProfile(): void
    {
        $u = $this->guard(['customer', 'owner', 'admin']);
        $b = $this->body();
        $name = trim((string)($b['name'] ?? ''));
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $phone = preg_replace('/\s+/', '', (string)($b['phone'] ?? ''));
        $currentPassword = (string)($b['current_password'] ?? '');
        $newPassword = (string)($b['new_password'] ?? '');
        $confirmPassword = (string)($b['confirm_password'] ?? '');
        $version = filter_var($b['profile_version'] ?? null, FILTER_VALIDATE_INT);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            Http::error('Họ tên từ 2–60 ký tự.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            Http::error('Email không hợp lệ.', 422);
        }
        if (!preg_match('/^(0|\+84)\d{9}$/', $phone)) {
            Http::error('Số điện thoại không hợp lệ.', 422);
        }
        if ($version === false || $version < 1) {
            Http::error('Dữ liệu hồ sơ đã cũ. Hãy tải lại trang rồi thử lại.', 409);
        }
        if (User::emailTaken($email, (int)$u['id'])) {
            Http::error('Email này đã được tài khoản khác sử dụng.', 409);
        }

        $changingPassword = $currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '';
        if ($changingPassword) {
            if ($currentPassword === '') {
                Http::error('Vui lòng nhập mật khẩu hiện tại để đổi mật khẩu.', 422);
            }
            if (!User::passwordOk((int)$u['id'], $currentPassword)) {
                Http::error('Mật khẩu hiện tại không đúng.', 403);
            }
            if (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
                Http::error('Mật khẩu mới tối thiểu 8 ký tự, gồm cả chữ và số.', 422);
            }
            if (strlen($newPassword) > 72) {
                Http::error('Mật khẩu mới tối đa 72 byte.', 422);
            }
            if ($newPassword !== $confirmPassword) {
                Http::error('Mật khẩu mới nhập lại không khớp.', 422);
            }
        }

        try {
            $updated = User::updateProfile((int)$u['id'], $name, $email, $phone, $version, $changingPassword ? $newPassword : null);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Http::error('Email này đã được tài khoản khác sử dụng.', 409);
            }
            throw $e;
        }
        if (!$updated) {
            Http::error('Hồ sơ đã được cập nhật ở nơi khác. Tải lại trang để xem thay đổi rồi thử lại.', 409);
        }
        Audit::log($changingPassword ? 'profile_password_update' : 'profile_update', '', (int)$u['id']);
        Http::json(['user' => ['name' => $name, 'email' => $email, 'phone' => $phone, 'profile_version' => $version + 1]]);
    }
    public function logout(): void
    {
        if (!Auth::csrfOk()) {
            Http::error('Phiên không hợp lệ.', 419);
        }
        Auth::logout();
        Http::json(['ok' => true]);
    }
}
