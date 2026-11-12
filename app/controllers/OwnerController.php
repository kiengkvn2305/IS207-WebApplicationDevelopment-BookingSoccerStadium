<?php

class OwnerController extends Controller
{
    public function overview(): void
    {
        $u = $this->guard(['owner']);
        Booking::expireHolds();
        $id = (int)$u['id'];
        $bookings = array_map(fn ($b) => $b + ['status_text' => STATUS_VI[$b['status']]], Booking::listByOwner($id));
        $pitches = array_map(fn ($p) => $p + ['image_url' => $p['image_path'] ? BASE . '/' . $p['image_path'] : null], Pitch::byOwner($id));
        $images = array_map(fn ($image) => ['id' => (int)$image['id'], 'url' => BASE . '/' . $image['image_path']], Venue::images($id));
        Http::json(['banks' => BANKS, 'profile' => OwnerProfile::get($id), 'pitches' => $pitches, 'bookings' => $bookings,
            'venue_images' => $images, 'amenities' => Venue::amenities($id),
            'sepay_secret_configured' => SEPAY_WEBHOOK_SECRET !== '',
            'match_alerts' => MatchChallenge::ownerAlerts($id)]);
    }
    public function saveVenueDetails(): void
    {
        $u = $this->guard(['owner']);
        $body = $this->body();
        $amenities = $body['amenities'] ?? [];
        if (!is_array($amenities) || count($amenities) > 20) {
            Http::error('Tối đa 20 tiện ích.', 422);
        }
        foreach ($amenities as $amenity) {
            if (!is_string($amenity) || mb_strlen(trim($amenity)) > 80) {
                Http::error('Mỗi tiện ích không được vượt quá 80 ký tự.', 422);
            }
        }
        Venue::saveAmenities((int)$u['id'], $amenities);
        Audit::log('owner_amenities_update');
        Http::json(['ok' => true]);
    }
    public function venueImage(): void
    {
        $u = $this->guard(['owner']);
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            Http::error('Vui lòng chọn ảnh để tải lên.', 422);
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            Http::error('Ảnh vượt quá giới hạn tải lên của máy chủ.', 422);
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Http::error('Tải ảnh thất bại. Vui lòng thử lại.', 422);
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            Http::error('Ảnh không được vượt quá 5 MB.', 422);
        }
        $info = @getimagesize($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$info || !isset($extensions[$info['mime']])) {
            Http::error('Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.', 422);
        }
        $directory = dirname(__DIR__, 2) . '/public/uploads/venues';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            Http::error('Không thể tạo thư mục lưu ảnh.', 500);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$info['mime']];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
            Http::error('Không thể lưu ảnh cụm sân.', 500);
        }
        $path = 'uploads/venues/' . $filename;
        if (!Venue::addImage((int)$u['id'], $path)) {
            unlink($directory . '/' . $filename);
            Http::error('Mỗi cụm sân được tải tối đa 8 ảnh.', 422);
        }
        Audit::log('venue_image_upload', $filename);
        Http::json(['ok' => true, 'image_url' => BASE . '/' . $path]);
    }
    public function deleteVenueImage(): void
    {
        $u = $this->guard(['owner']);
        $id = (int)($this->body()['id'] ?? 0);
        $path = Venue::deleteImage($id, (int)$u['id']);
        if ($path === null) {
            Http::error('Không tìm thấy ảnh.', 404);
        }
        $fullPath = dirname(__DIR__, 2) . '/public/' . $path;
        if (is_file($fullPath) && !unlink($fullPath)) {
            Http::error('Không thể xóa tệp ảnh trên máy chủ.', 500);
        }
        Audit::log('venue_image_delete', (string)$id);
        Http::json(['ok' => true]);
    }
    public function saveBank(): void
    {
        $u = $this->guard(['owner']);
        $b = $this->body();
        $bin = (string)($b['bank_bin'] ?? '');
        $no = preg_replace('/\s+/', '', (string)($b['account_no'] ?? ''));
        $nm = strtoupper(trim((string)($b['account_name'] ?? '')));
        $venue = trim((string)($b['venue_name'] ?? ''));
        $latitudeRaw = trim((string)($b['latitude'] ?? ''));
        $longitudeRaw = trim((string)($b['longitude'] ?? ''));
        $latitude = $latitudeRaw === '' ? null : filter_var($latitudeRaw, FILTER_VALIDATE_FLOAT);
        $longitude = $longitudeRaw === '' ? null : filter_var($longitudeRaw, FILTER_VALIDATE_FLOAT);
        if (!isset(BANKS[$bin])) {
            Http::error('Chọn ngân hàng hợp lệ.', 422);
        }
        if (!preg_match('/^\d{6,19}$/', $no)) {
            Http::error('Số tài khoản gồm 6–19 chữ số.', 422);
        }
        if (!preg_match('/^[A-Z ]{3,60}$/', $nm)) {
            Http::error('Tên chủ tài khoản viết HOA, không dấu.', 422);
        }
        if (mb_strlen($venue) < 2 || mb_strlen($venue) > 100) {
            Http::error('Tên cụm sân từ 2–100 ký tự.', 422);
        }
        if (($latitudeRaw === '') !== ($longitudeRaw === '') || $latitude === false || $longitude === false
            || ($latitude !== null && ($latitude < -90 || $latitude > 90))
            || ($longitude !== null && ($longitude < -180 || $longitude > 180))) {
            Http::error('Vị trí phải có đủ vĩ độ (-90 đến 90) và kinh độ (-180 đến 180).', 422);
        }
        $address = trim((string)($b['address'] ?? ''));
        if (mb_strlen($address) > 200) {
            Http::error('Địa chỉ không được vượt quá 200 ký tự.', 422);
        }
        $version = filter_var($b['profile_version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version < 1) {
            Http::error('Thông tin cụm sân đã cũ. Hãy tải lại trang rồi thử lại.', 409);
        }
        if (!OwnerProfile::update((int)$u['id'], $venue, $address, $latitude, $longitude, $bin, $no, $nm, $version)) {
            Http::error('Thông tin cụm sân đã được cập nhật ở nơi khác. Tải lại trang để xem thay đổi rồi thử lại.', 409);
        }
        Audit::log('owner_bank_update', BANKS[$bin] . ' ****' . substr($no, -4));
        Http::json(['ok' => true, 'profile_version' => $version + 1]);
    }
    public function addPitch(): void
    {
        $u = $this->guard(['owner']);
        $b = $this->body();
        $name = trim((string)($b['name'] ?? ''));
        $type = (string)($b['type'] ?? '');
        $price = filter_var($b['price'] ?? null, FILTER_VALIDATE_INT);
        $peak = filter_var($b['peak_price'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 60 || !in_array($type, ['Sân 5 người', 'Sân 7 người', 'Sân 11 người'], true)
            || $price === false || $peak === false
            || $price < 10000 || $peak < 10000 || $price > 5000000 || $peak > 5000000) {
            Http::error('Thông tin sân không hợp lệ (giá 10.000–5.000.000đ).', 422);
        }
        Pitch::add((int)$u['id'], $name, $type, $price, $peak);
        Http::json(['ok' => true]);
    }
    public function unavailableSlot(): void
    {
        $u = $this->guard(['owner']);
        $b = $this->body();
        $date = (string)($b['date'] ?? '');
        $hour = (int)($b['hour'] ?? -1);
        $reason = trim((string)($b['reason'] ?? ''));
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+13 days'))
            || $hour < OPEN_HOUR || $hour >= CLOSE_HOUR || ($date === date('Y-m-d') && $hour <= (int)date('G'))) {
            Http::error('Ngày hoặc khung giờ không hợp lệ.', 422);
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            Http::error('Nhập lý do khóa sân (tối đa 255 ký tự).', 422);
        }
        $pitchId = (int)($b['pitch_id'] ?? 0);
        if (!Pitch::setUnavailable($pitchId, (int)$u['id'], $date, $hour, $reason)) {
            Http::error('Không tìm thấy sân hoặc khung giờ đã có người đặt.', 409);
        }
        Audit::log('owner_slot_block', "pitch #$pitchId $date $hour:00 · $reason");
        Http::json(['ok' => true]);
    }
    public function availableSlot(): void
    {
        $u = $this->guard(['owner']);
        $b = $this->body();
        $date = (string)($b['date'] ?? '');
        $hour = (int)($b['hour'] ?? -1);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+13 days'))
            || $hour < OPEN_HOUR || $hour >= CLOSE_HOUR || ($date === date('Y-m-d') && $hour <= (int)date('G'))) {
            Http::error('Ngày hoặc khung giờ không hợp lệ.', 422);
        }
        $pitchId = (int)($b['pitch_id'] ?? 0);
        if (!Pitch::setAvailable($pitchId, (int)$u['id'], $date, $hour)) {
            Http::error('Khung giờ không tìm thấy hoặc đã được gỡ trước đó.', 404);
        }
        Audit::log('owner_slot_open', "pitch #$pitchId $date $hour:00");
        Http::json(['ok' => true]);
    }
    public function pitchSlots(): void
    {
        $u = $this->guard(['owner']);
        Booking::expireHolds();
        $id = (int)($_GET['pitch_id'] ?? 0);
        $date = (string)($_GET['date'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+13 days'))) {
            Http::error('Ngày không hợp lệ.', 422);
        }
        $pitch = null;
        foreach (Pitch::byOwner((int)$u['id']) as $p) {
            if ((int)$p['id'] === $id) {
                $pitch = $p;
                break;
            }
        }
        if (!$pitch) {
            Http::error('Không tìm thấy sân.', 404);
        }
        $booked = Booking::bookedMap($date)[$id] ?? [];
        $unavailable = Pitch::unavailableMap($date)[$id] ?? [];
        $slots = [];
        $now = (int)date('G');
        $today = $date === date('Y-m-d');
        for ($hour = OPEN_HOUR; $hour < CLOSE_HOUR; $hour++) {
            $status = !empty($booked[$hour]) ? 'booked' : (array_key_exists($hour, $unavailable) ? 'unavailable' : (($today && $hour <= $now) ? 'past' : 'free'));
            $slots[] = ['hour' => $hour, 'status' => $status, 'reason' => (string)($unavailable[$hour] ?? '')];
        }
        Http::json(['slots' => $slots]);
    }
    public function pitchImage(): void
    {
        $u = $this->guard(['owner']);
        $id = (int)($_POST['pitch_id'] ?? 0);
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            Http::error('Vui lòng chọn ảnh để tải lên.', 422);
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            Http::error('Ảnh vượt quá giới hạn tải lên của máy chủ.', 422);
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Http::error('Tải ảnh thất bại. Vui lòng thử lại.', 422);
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            Http::error('Ảnh không được vượt quá 5 MB.', 422);
        }
        $info = @getimagesize($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$info || !isset($extensions[$info['mime']])) {
            Http::error('Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.', 422);
        }
        $ownsPitch = false;
        foreach (Pitch::byOwner((int)$u['id']) as $pitch) {
            if ((int)$pitch['id'] === $id) {
                $ownsPitch = true;
                break;
            }
        }
        if (!$ownsPitch) {
            Http::error('Không tìm thấy sân.', 404);
        }
        $directory = dirname(__DIR__, 2) . '/public/uploads/pitches';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            Http::error('Không thể tạo thư mục lưu ảnh.', 500);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$info['mime']];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
            Http::error('Không thể lưu ảnh sân.', 500);
        }
        $path = 'uploads/pitches/' . $filename;
        if (!Pitch::saveImage($id, (int)$u['id'], $path)) {
            unlink($directory . '/' . $filename);
            Http::error('Không thể cập nhật ảnh sân.', 500);
        }
        Http::json(['ok' => true, 'image_url' => BASE . '/' . $path]);
    }
    public function togglePitch(): void
    {
        $u = $this->guard(['owner']);
        if (!Pitch::toggle((int)($this->body()['id'] ?? 0), (int)$u['id'])) {
            Http::error('Không tìm thấy sân.', 404);
        }
        Http::json(['ok' => true]);
    }
    public function confirmPaid(): void
    {
        $u = $this->guard(['owner']);
        $id = (int)($this->body()['id'] ?? 0);
        if (!Booking::confirmPaid($id, (int)$u['id'])) {
            Http::error('Đơn không còn ở trạng thái có thể xác nhận thanh toán.', 422);
        }
        Notification::notifyBookingCustomer($id, 'Đã xác nhận thanh toán', 'Chủ sân đã xác nhận khoản thanh toán cho đơn đặt sân của bạn.');
        Audit::log('confirm_paid', (string)$id);
        Http::json(['ok' => true]);
    }
    public function confirmCancellationFee(): void
    {
        $u = $this->guard(['owner']);
        $id = (int)($this->body()['id'] ?? 0);
        if (!Booking::confirmCancellationFee($id, (int)$u['id'])) {
            Http::error('Đơn không còn chờ xác nhận phí hủy.', 422);
        }
        Notification::notifyBookingCustomer($id, 'Đã xác nhận phí hủy', 'Chủ sân đã xác nhận phí hủy. Lịch đặt sân đã được hủy.');
        Notification::notifyBookingChallenges($id);
        Audit::log('confirm_cancel_fee', (string)$id);
        Http::json(['ok' => true]);
    }
    public function refundDone(): void
    {
        $u = $this->guard(['owner']);
        $id = (int)($this->body()['id'] ?? 0);
        if (!Booking::refundDone($id, (int)$u['id'])) {
            Http::error('Không thể cập nhật đơn này.', 422);
        }
        Notification::notifyBookingCustomer($id, 'Đã hoàn tiền', 'Chủ sân xác nhận đã hoàn tiền cho lịch đặt bị hủy.');
        Audit::log('refund_done', (string)$id);
        Http::json(['ok' => true]);
    }
    public function cancelBooking(): void
    {
        $u = $this->guard(['owner']);
        $id = (int)($this->body()['id'] ?? 0);
        $booking = Booking::cancelByOwner($id, (int)$u['id']);
        if (!$booking) {
            Http::error('Không thể hủy đơn này (đơn đã xử lý hoặc giờ đá đã bắt đầu).', 422);
        }
        $refundMessage = $booking['refund_amount'] > 0
            ? 'Chủ sân đã hủy lịch; bạn sẽ được hoàn đủ tiền theo quy định.'
            : 'Chủ sân đã hủy lịch đặt sân của bạn. Bạn không phải trả phí hủy.';
        Notification::notifyBookingParties(
            $id,
            'Chủ sân đã hủy lịch',
            $refundMessage,
            'Đã hủy lịch đặt sân',
            'Bạn đã hủy lịch đặt sân của khách hàng.'
        );
        Notification::notifyBookingChallenges($id);
        Audit::log('owner_cancel_booking', (string)$id);
        Http::json(['ok' => true, 'refund_amount' => (int)$booking['refund_amount']]);
    }
}
