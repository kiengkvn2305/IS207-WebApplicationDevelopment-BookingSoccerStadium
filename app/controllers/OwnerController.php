<?php
class OwnerController extends Controller {
    function overview(): void {
        $u = $this->guard(['owner']); Booking::expireHolds(); $id = (int)$u['id'];
        $bookings = array_map(fn($b) => $b + ['status_text' => STATUS_VI[$b['status']]], Booking::listByOwner($id));
        $pitches = array_map(fn($p) => $p + ['image_url' => $p['image_path'] ? BASE . '/' . $p['image_path'] : null], Pitch::byOwner($id));
        $images = array_map(fn($image) => ['id' => (int)$image['id'], 'url' => BASE . '/' . $image['image_path']], Venue::images($id));
        Http::json(['banks' => BANKS, 'profile' => OwnerProfile::get($id), 'pitches' => $pitches, 'bookings' => $bookings,
            'venue_images' => $images, 'amenities' => Venue::amenities($id), 'amenity_options' => Venue::AMENITIES,
            'sepay_secret_configured' => SEPAY_WEBHOOK_SECRET !== '',
            'match_alerts' => MatchChallenge::ownerAlerts($id)]);
    }
    function saveVenueDetails(): void {
        $u = $this->guard(['owner']); $body = $this->body();
        $amenities = $body['amenities'] ?? [];
        if (!is_array($amenities) || count($amenities) > count(Venue::AMENITIES)) Http::error('Danh sách tiện ích không hợp lệ.', 422);
        foreach ($amenities as $amenity) {
            if (!is_string($amenity) || !isset(Venue::AMENITIES[$amenity])) Http::error('Danh sách tiện ích không hợp lệ.', 422);
        }
        Venue::saveAmenities((int)$u['id'], $amenities);
        Audit::log('owner_amenities_update');
        Http::json(['ok' => true]);
    }
    function venueImage(): void {
        $u = $this->guard(['owner']);
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) Http::error('Vui lòng chọn ảnh để tải lên.', 422);
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) Http::error('Ảnh vượt quá giới hạn tải lên của máy chủ.', 422);
        if ($file['error'] !== UPLOAD_ERR_OK) Http::error('Tải ảnh thất bại. Vui lòng thử lại.', 422);
        if ($file['size'] > 5 * 1024 * 1024) Http::error('Ảnh không được vượt quá 5 MB.', 422);
        $info = @getimagesize($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$info || !isset($extensions[$info['mime']])) Http::error('Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.', 422);
        $directory = dirname(__DIR__, 2) . '/public/uploads/venues';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) Http::error('Không thể tạo thư mục lưu ảnh.', 500);
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$info['mime']];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) Http::error('Không thể lưu ảnh cụm sân.', 500);
        $path = 'uploads/venues/' . $filename;
        if (!Venue::addImage((int)$u['id'], $path)) {
            unlink($directory . '/' . $filename);
            Http::error('Mỗi cụm sân được tải tối đa 8 ảnh.', 422);
        }
        Audit::log('venue_image_upload', $filename);
        Http::json(['ok' => true, 'image_url' => BASE . '/' . $path]);
    }
    function deleteVenueImage(): void {
        $u = $this->guard(['owner']); $id = (int)($this->body()['id'] ?? 0);
        $path = Venue::deleteImage($id, (int)$u['id']);
        if ($path === null) Http::error('Không tìm thấy ảnh.', 404);
        $fullPath = dirname(__DIR__, 2) . '/public/' . $path;
        if (is_file($fullPath) && !unlink($fullPath)) Http::error('Không thể xóa tệp ảnh trên máy chủ.', 500);
        Audit::log('venue_image_delete', (string)$id);
        Http::json(['ok' => true]);
    }
    function saveBank(): void {
        $u = $this->guard(['owner']); $b = $this->body();
        $bin = (string)($b['bank_bin'] ?? ''); $no = preg_replace('/\s+/', '', (string)($b['account_no'] ?? ''));
        $nm = strtoupper(trim((string)($b['account_name'] ?? ''))); $venue = trim((string)($b['venue_name'] ?? ''));
        if (!isset(BANKS[$bin])) Http::error('Chọn ngân hàng hợp lệ.', 422);
        if (!preg_match('/^\d{6,19}$/', $no)) Http::error('Số tài khoản gồm 6–19 chữ số.', 422);
        if (!preg_match('/^[A-Z ]{3,60}$/', $nm)) Http::error('Tên chủ tài khoản viết HOA, không dấu.', 422);
        if (mb_strlen($venue) < 2) Http::error('Nhập tên cụm sân.', 422);
        OwnerProfile::update((int)$u['id'], $venue, trim((string)($b['address'] ?? '')), $bin, $no, $nm);
        Audit::log('owner_bank_update', BANKS[$bin] . ' ****' . substr($no, -4));
        Http::json(['ok' => true]);
    }
    function addPitch(): void {
        $u = $this->guard(['owner']); $b = $this->body();
        $name = trim((string)($b['name'] ?? '')); $type = (string)($b['type'] ?? '');
        $price = (int)($b['price'] ?? 0); $peak = (int)($b['peak_price'] ?? 0);
        if ($name === '' || mb_strlen($name) > 60 || !in_array($type, ['Sân 5 người', 'Sân 7 người', 'Sân 11 người'], true)
            || $price < 10000 || $peak < 10000 || $price > 5000000 || $peak > 5000000) Http::error('Thông tin sân không hợp lệ (giá 10.000–5.000.000đ).', 422);
        Pitch::add((int)$u['id'], $name, $type, $price, $peak); Http::json(['ok' => true]);
    }
    function unavailableSlot(): void {
        $u = $this->guard(['owner']); $b = $this->body();
        $date = (string)($b['date'] ?? ''); $hour = (int)($b['hour'] ?? -1);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+13 days'))
            || $hour < OPEN_HOUR || $hour >= CLOSE_HOUR || ($date === date('Y-m-d') && $hour <= (int)date('G'))) {
            Http::error('Ngày hoặc khung giờ không hợp lệ.', 422);
        }
        if (!Pitch::setUnavailable((int)($b['pitch_id'] ?? 0), (int)$u['id'], $date, $hour)) {
            Http::error('Không tìm thấy sân hoặc khung giờ đã có người đặt.', 409);
        }
        Http::json(['ok' => true]);
    }
    function availableSlot(): void {
        $u = $this->guard(['owner']); $b = $this->body();
        $date = (string)($b['date'] ?? ''); $hour = (int)($b['hour'] ?? -1);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+13 days'))
            || $hour < OPEN_HOUR || $hour >= CLOSE_HOUR || ($date === date('Y-m-d') && $hour <= (int)date('G'))) {
            Http::error('Ngày hoặc khung giờ không hợp lệ.', 422);
        }
        if (!Pitch::setAvailable((int)($b['pitch_id'] ?? 0), (int)$u['id'], $date, $hour)) {
            Http::error('Khung giờ không tìm thấy hoặc đã được gỡ trước đó.', 404);
        }
        Http::json(['ok' => true]);
    }
    function pitchSlots(): void {
        $u = $this->guard(['owner']); Booking::expireHolds();
        $id = (int)($_GET['pitch_id'] ?? 0); $date = (string)($_GET['date'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+13 days'))) {
            Http::error('Ngày không hợp lệ.', 422);
        }
        $pitch = null;
        foreach (Pitch::byOwner((int)$u['id']) as $p) if ((int)$p['id'] === $id) { $pitch = $p; break; }
        if (!$pitch) Http::error('Không tìm thấy sân.', 404);
        $booked = Booking::bookedMap($date)[$id] ?? [];
        $unavailable = Pitch::unavailableMap($date)[$id] ?? [];
        $slots = []; $now = (int)date('G'); $today = $date === date('Y-m-d');
        for ($hour = OPEN_HOUR; $hour < CLOSE_HOUR; $hour++) {
            $status = !empty($booked[$hour]) ? 'booked' : (!empty($unavailable[$hour]) ? 'unavailable' : (($today && $hour <= $now) ? 'past' : 'free'));
            $slots[] = ['hour' => $hour, 'status' => $status];
        }
        Http::json(['slots' => $slots]);
    }
    function pitchImage(): void {
        $u = $this->guard(['owner']);
        $id = (int)($_POST['pitch_id'] ?? 0);
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) Http::error('Vui lòng chọn ảnh để tải lên.', 422);
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) Http::error('Ảnh vượt quá giới hạn tải lên của máy chủ.', 422);
        if ($file['error'] !== UPLOAD_ERR_OK) Http::error('Tải ảnh thất bại. Vui lòng thử lại.', 422);
        if ($file['size'] > 5 * 1024 * 1024) Http::error('Ảnh không được vượt quá 5 MB.', 422);
        $info = @getimagesize($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$info || !isset($extensions[$info['mime']])) Http::error('Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.', 422);
        $ownsPitch = false;
        foreach (Pitch::byOwner((int)$u['id']) as $pitch) if ((int)$pitch['id'] === $id) { $ownsPitch = true; break; }
        if (!$ownsPitch) {
            Http::error('Không tìm thấy sân.', 404);
        }
        $directory = dirname(__DIR__, 2) . '/public/uploads/pitches';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) Http::error('Không thể tạo thư mục lưu ảnh.', 500);
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$info['mime']];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) Http::error('Không thể lưu ảnh sân.', 500);
        $path = 'uploads/pitches/' . $filename;
        if (!Pitch::saveImage($id, (int)$u['id'], $path)) {
            unlink($directory . '/' . $filename);
            Http::error('Không thể cập nhật ảnh sân.', 500);
        }
        Http::json(['ok' => true, 'image_url' => BASE . '/' . $path]);
    }
    function togglePitch(): void {
        $u = $this->guard(['owner']);
        if (!Pitch::toggle((int)($this->body()['id'] ?? 0), (int)$u['id'])) Http::error('Không tìm thấy sân.', 404);
        Http::json(['ok' => true]);
    }
    function confirmPaid(): void {
        $u = $this->guard(['owner']); $id = (int)($this->body()['id'] ?? 0);
        if (!Booking::confirmPaid($id, (int)$u['id'])) Http::error('Đơn không còn chờ thanh toán (có thể đã hết hạn giữ chỗ).', 422);
        Notification::notifyBookingCustomer($id, 'Đã xác nhận thanh toán', 'Chủ sân đã xác nhận khoản thanh toán cho đơn đặt sân của bạn.');
        Audit::log('confirm_paid', (string)$id); Http::json(['ok' => true]);
    }
    function confirmCancellationFee(): void {
        $u = $this->guard(['owner']); $id = (int)($this->body()['id'] ?? 0);
        if (!Booking::confirmCancellationFee($id, (int)$u['id'])) Http::error('Đơn không còn chờ xác nhận phí hủy.', 422);
        Notification::notifyBookingCustomer($id, 'Đã xác nhận phí hủy', 'Chủ sân đã xác nhận phí hủy. Lịch đặt sân đã được hủy.');
        Notification::notifyBookingChallenges($id);
        Audit::log('confirm_cancel_fee', (string)$id); Http::json(['ok' => true]);
    }
    function refundDone(): void {
        $u = $this->guard(['owner']); $id = (int)($this->body()['id'] ?? 0);
        if (!Booking::refundDone($id, (int)$u['id'])) Http::error('Không thể cập nhật đơn này.', 422);
        Notification::notifyBookingCustomer($id, 'Đã hoàn tiền', 'Chủ sân xác nhận đã hoàn tiền cho lịch đặt bị hủy.');
        Audit::log('refund_done', (string)$id); Http::json(['ok' => true]);
    }
    function cancelBooking(): void {
        $u = $this->guard(['owner']); $id = (int)($this->body()['id'] ?? 0);
        $booking = Booking::cancelByOwner($id, (int)$u['id']);
        if (!$booking) Http::error('Không thể hủy đơn này (đơn đã xử lý hoặc giờ đá đã bắt đầu).', 422);
        $refundMessage = $booking['refund_amount'] > 0
            ? 'Chủ sân đã hủy lịch; bạn sẽ được hoàn đủ tiền theo quy định.'
            : 'Chủ sân đã hủy lịch đặt sân của bạn. Bạn không phải trả phí hủy.';
        Notification::notifyBookingParties($id, 'Chủ sân đã hủy lịch', $refundMessage,
            'Đã hủy lịch đặt sân', 'Bạn đã hủy lịch đặt sân của khách hàng.');
        Notification::notifyBookingChallenges($id);
        Audit::log('owner_cancel_booking', (string)$id);
        Http::json(['ok' => true, 'refund_amount' => (int)$booking['refund_amount']]);
    }
}
