<?php
class AuthController extends Controller {
    function me(): void {
        $u = Auth::user();
        Http::json(['csrf' => Auth::csrf(), 'user' => $u ? [
            'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'],
            'role' => $u['role'], 'created_at' => $u['created_at']] : null]);
    }
    function login(): void {
        if (!Auth::csrfOk()) Http::error('Phiên không hợp lệ, hãy tải lại trang.', 419);
        $b = $this->body(); $email = strtolower(trim((string)($b['email'] ?? ''))); $pw = (string)($b['password'] ?? '');
        if (User::failedAttempts($email, Auth::ip()) >= 5) Http::error('Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.', 429);
        $u = User::byEmail($email);
        if (!$u || !password_verify($pw, $u['password_hash'])) { User::logFail($email, Auth::ip()); Http::error('Email hoặc mật khẩu không đúng.', 422); }
        if ($u['status'] === 'pending') Http::error('Tài khoản chủ sân đang chờ admin duyệt.', 403);
        if ($u['status'] === 'locked') Http::error('Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên.', 403);
        Auth::login((int)$u['id']); User::clearFails($email); Audit::log('login', '', (int)$u['id']);
        Http::json(['redirect' => Auth::home($u['role'])]);
    }
    function register(): void {
        if (!Auth::csrfOk()) Http::error('Phiên không hợp lệ, hãy tải lại trang.', 419);
        $b = $this->body(); $name = trim((string)($b['name'] ?? '')); $email = strtolower(trim((string)($b['email'] ?? '')));
        $phone = preg_replace('/\s+/', '', (string)($b['phone'] ?? '')); $pw = (string)($b['password'] ?? '');
        $role = ($b['role'] ?? '') === 'owner' ? 'owner' : 'customer'; $venue = trim((string)($b['venue'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) Http::error('Họ tên từ 2–60 ký tự.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::error('Email không hợp lệ.', 422);
        if (!preg_match('/^(0|\+84)\d{9}$/', $phone)) Http::error('Số điện thoại không hợp lệ.', 422);
        if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) Http::error('Mật khẩu tối thiểu 8 ký tự, gồm cả chữ và số.', 422);
        if ($role === 'owner' && mb_strlen($venue) < 2) Http::error('Vui lòng nhập tên cụm sân.', 422);
        if (!User::create($name, $email, $phone, $pw, $role, $venue)) Http::error('Email này đã được sử dụng.', 409);
        Http::json(['message' => $role === 'owner' ? 'Đã đăng ký. Vui lòng chờ admin duyệt tài khoản chủ sân.' : 'Đăng ký thành công. Mời bạn đăng nhập.']);
    }
    function updateProfile(): void {
        $u = $this->guard(['customer', 'owner', 'admin']);
        $b = $this->body();
        $name = trim((string)($b['name'] ?? ''));
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $phone = preg_replace('/\s+/', '', (string)($b['phone'] ?? ''));
        $currentPassword = (string)($b['current_password'] ?? '');
        $newPassword = (string)($b['new_password'] ?? '');
        $confirmPassword = (string)($b['confirm_password'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) Http::error('Họ tên từ 2–60 ký tự.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) Http::error('Email không hợp lệ.', 422);
        if (!preg_match('/^(0|\+84)\d{9}$/', $phone)) Http::error('Số điện thoại không hợp lệ.', 422);
        if (User::emailTaken($email, (int)$u['id'])) Http::error('Email này đã được tài khoản khác sử dụng.', 409);

        $changingPassword = $currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '';
        if ($changingPassword) {
            if ($currentPassword === '') Http::error('Vui lòng nhập mật khẩu hiện tại để đổi mật khẩu.', 422);
            if (!User::passwordOk((int)$u['id'], $currentPassword)) Http::error('Mật khẩu hiện tại không đúng.', 403);
            if (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
                Http::error('Mật khẩu mới tối thiểu 8 ký tự, gồm cả chữ và số.', 422);
            }
            if ($newPassword !== $confirmPassword) Http::error('Mật khẩu mới nhập lại không khớp.', 422);
        }

        try {
            User::updateProfile((int)$u['id'], $name, $email, $phone, $changingPassword ? $newPassword : null);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') Http::error('Email này đã được tài khoản khác sử dụng.', 409);
            throw $e;
        }
        Audit::log($changingPassword ? 'profile_password_update' : 'profile_update', '', (int)$u['id']);
        Http::json(['user' => ['name' => $name, 'email' => $email, 'phone' => $phone]]);
    }
    function logout(): void {
        if (!Auth::csrfOk()) Http::error('Phiên không hợp lệ.', 419);
        Auth::logout(); Http::json(['ok' => true]);
    }
}
