<?php

class BookingController extends Controller
{
    private function validDate(string $d): bool
    {
        $x = DateTime::createFromFormat('Y-m-d', $d);
        return $x && $x->format('Y-m-d') === $d && $d >= date('Y-m-d') && $d <= date('Y-m-d', strtotime('+13 days'));
    }

    private function bookingSelection(array $body): array
    {
        $date = (string)($body['date'] ?? '');
        $rawHours = $body['hours'] ?? null;
        if (!is_array($rawHours) || !$rawHours || count($rawHours) > 6) {
            Http::error('Chọn từ 1 đến 6 giờ liên tiếp.', 422);
        }
        $hours = [];
        foreach ($rawHours as $rawHour) {
            if ((!is_int($rawHour) && !is_string($rawHour)) || filter_var($rawHour, FILTER_VALIDATE_INT) === false) {
                Http::error('Khung giờ phải là số nguyên.', 422);
            }
            $hours[] = (int)$rawHour;
        }
        if (count(array_unique($hours)) !== count($hours)) {
            Http::error('Không chọn lặp khung giờ.', 422);
        }
        sort($hours);
        if (!$this->validDate($date)) {
            Http::error('Ngày không hợp lệ.', 422);
        }
        if (!$hours || count($hours) > 6) {
            Http::error('Chọn từ 1 đến 6 giờ liên tiếp.', 422);
        }
        for ($i = 1; $i < count($hours); $i++) {
            if ($hours[$i] !== $hours[$i - 1] + 1) {
                Http::error('Các khung giờ phải liền nhau.', 422);
            }
        }
        foreach ($hours as $hour) {
            if ($hour < OPEN_HOUR || $hour >= CLOSE_HOUR) {
                Http::error('Khung giờ không hợp lệ.', 422);
            }
            if ($date === date('Y-m-d') && $hour <= (int)date('G')) {
                Http::error('Khung giờ này đã qua.', 422);
            }
        }
        $pitch = Pitch::bookable((int)($body['pitch_id'] ?? 0));
        if (!$pitch) {
            Http::error('Sân không khả dụng.', 404);
        }
        return [$pitch, $date, $hours];
    }

    public function slots(): void
    {
        Booking::expireHolds();
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!$this->validDate($date)) {
            Http::error('Ngày không hợp lệ.', 422);
        }
        $booked = Booking::bookedMap($date);
        $unavailable = Pitch::unavailableMap($date);
        $venues = [];   // gom các sân theo cụm sân (chủ sân)
        foreach (Pitch::listBookable() as $p) {
            $vid = (int)$p['owner_id'];
            $venues[$vid] ??= [
                'id' => $vid,
                'name' => $p['venue_name'],
                'address' => (string)$p['address'],
                'latitude' => $p['latitude'] === null ? null : (float)$p['latitude'],
                'longitude' => $p['longitude'] === null ? null : (float)$p['longitude'],
                'owner_name' => $p['owner_name'],
                'owner_phone' => $p['owner_phone'],
                'pitches' => [],
            ];
            $venues[$vid]['pitches'][] = ['id' => (int)$p['id'], 'name' => $p['name'], 'type' => $p['type'],
                'image_url' => $p['image_path'] ? BASE . '/' . $p['image_path'] : null,
                'slots' => $this->buildSlots($p, $date, $booked[$p['id']] ?? [], $unavailable[(int)$p['id']] ?? [])];
        }
        $ids = array_keys($venues);
        $images = Venue::imagesForOwners($ids);
        $amenities = Venue::amenitiesForOwners($ids);
        $ratings = Venue::summariesForOwners($ids);
        foreach ($venues as $id => &$venue) {
            $venue['images'] = $images[$id] ?? [];
            $venue['amenities'] = $amenities[$id] ?? [];
            $venue['rating_average'] = $ratings[$id]['average'] ?? null;
            $venue['rating_count'] = $ratings[$id]['count'] ?? 0;
        }
        unset($venue);
        Http::json(['venues' => array_values($venues)]);
    }

    public function reviews(): void
    {
        $ownerId = (int)($_GET['owner_id'] ?? 0);
        if ($ownerId < 1) {
            Http::error('Cụm sân không hợp lệ.', 422);
        }
        $user = Auth::user();
        $data = Venue::reviews($ownerId, $user && $user['role'] === 'customer' ? (int)$user['id'] : null);
        Http::json($data);
    }

    public function topRated(): void
    {
        Http::json(['venues' => Venue::topRated()]);
    }

    public function saveReview(): void
    {
        $user = $this->guard(['customer']);
        $body = $this->body();
        $ownerId = (int)($body['owner_id'] ?? 0);
        $rating = filter_var($body['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string)($body['comment'] ?? ''));
        if ($ownerId < 1 || $rating === false || $rating < 1 || $rating > 5
            || $comment === '' || mb_strlen($comment) > 1000) {
            Http::error('Chọn số sao từ 1 đến 5 và nhập bình luận tối đa 1.000 ký tự.', 422);
        }
        if (!Venue::saveReview($ownerId, (int)$user['id'], $rating, $comment)) {
            Http::error('Không tìm thấy cụm sân.', 404);
        }
        Notification::notifyReviewOwner($ownerId, (string)$user['name'], (int)$rating);
        Audit::log('venue_review', "venue #$ownerId");
        Http::json(['ok' => true]);
    }

    public function matches(): void
    {
        $user = Auth::user();
        $customerId = $user && $user['role'] === 'customer' ? (int)$user['id'] : null;
        Http::json([
            'server_time' => microtime(true),
            'matches' => MatchChallenge::listOpen($customerId),
            'my_matches' => $customerId ? MatchChallenge::myChallenges($customerId) : [],
            'applications' => $customerId ? MatchChallenge::requestsForCreator($customerId) : [],
            'my_applications' => $customerId ? MatchChallenge::myRequests($customerId) : [],
            'player_requests' => $customerId ? MatchChallenge::playerRequestsForCreator($customerId) : [],
            'my_player_requests' => $customerId ? MatchChallenge::myPlayerRequests($customerId) : [],
        ]);
    }

    public function createMatch(): void
    {
        $user = $this->guard(['customer']);
        $body = $this->body();
        $bookingId = (int)($body['booking_id'] ?? 0);
        $teamName = trim((string)($body['team_name'] ?? ''));
        $phone = trim((string)($body['contact_phone'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $playersNeeded = filter_var($body['players_needed'] ?? null, FILTER_VALIDATE_INT);
        if ($bookingId < 1 || mb_strlen($teamName) < 2 || mb_strlen($teamName) > 80
            || !preg_match('/^(0|\\+84)\\d{9}$/', $phone) || mb_strlen($description) > 500
            || $playersNeeded === false || $playersNeeded < 0 || $playersNeeded > 30) {
            Http::error('Kiểm tra thông tin đội và số cầu thủ cần tìm (0–30).', 422);
        }
        if (!MatchChallenge::create($bookingId, (int)$user['id'], $teamName, $phone, $description, $playersNeeded)) {
            Http::error('Chỉ có thể đăng kèo từ lịch còn hiệu lực; kèo đang mở hoặc đã ghép không thể đăng lại.', 409);
        }
        Notification::notifyBookingCustomer($bookingId, 'Đã đăng kèo giao hữu', 'Kèo tìm đối thủ/đá vãng lai của bạn đã được đăng.');
        Notification::notifyBookingOwner(
            $bookingId,
            'Khách đã đăng kèo giao hữu',
            'Khách hàng đăng kèo tìm đối thủ hoặc nhận người đá vãng lai từ lịch sân.'
        );
        Audit::log('match_create', "booking #$bookingId");
        Http::json(['ok' => true]);
    }

    public function requestMatch(): void
    {
        $user = $this->guard(['customer']);
        $body = $this->body();
        $challengeId = (int)($body['challenge_id'] ?? 0);
        $teamName = trim((string)($body['team_name'] ?? ''));
        $phone = trim((string)($body['contact_phone'] ?? ''));
        $message = trim((string)($body['message'] ?? ''));
        if ($challengeId < 1 || mb_strlen($teamName) < 2 || mb_strlen($teamName) > 80
            || !preg_match('/^(0|\\+84)\\d{9}$/', $phone) || mb_strlen($message) > 300) {
            Http::error('Kiểm tra tên đội, số điện thoại và lời nhắn.', 422);
        }
        if (!MatchChallenge::request($challengeId, (int)$user['id'], $teamName, $phone, $message)) {
            Http::error('Kèo đã đóng, không thể tự gửi lời thách đấu hoặc bạn đã gửi lời mời trước đó.', 409);
        }
        $creatorId = Notification::challengeCreator($challengeId);
        if ($creatorId !== null) {
            Notification::create($creatorId, 'Có đội gửi lời thách đấu', "$teamName muốn ghép đội với kèo của bạn.", 'dat-san?show=matches');
        }
        Audit::log('match_request', "challenge #$challengeId");
        Http::json(['ok' => true]);
    }

    public function decideMatchRequest(): void
    {
        $user = $this->guard(['customer']);
        $body = $this->body();
        $requestId = (int)($body['request_id'] ?? 0);
        $action = (string)($body['action'] ?? '');
        if ($requestId < 1 || !in_array($action, ['accept', 'decline'], true)) {
            Http::error('Yêu cầu không hợp lệ.', 422);
        }
        $request = Notification::matchRequestContext($requestId);
        $otherApplicants = $action === 'accept' && $request
            ? Notification::pendingMatchApplicants((int)$request['challenge_id'], 'opponent') : [];
        if (!MatchChallenge::decide($requestId, (int)$user['id'], $action)) {
            Http::error('Kèo đã đóng, đã đến giờ đá hoặc lời thách đấu không còn hợp lệ.', 409);
        }
        if ($request) {
            $accepted = $action === 'accept';
            Notification::create(
                (int)$request['applicant_id'],
                $accepted ? 'Lời thách đấu được chấp nhận' : 'Lời thách đấu chưa được chấp nhận',
                $accepted ? 'Đội ' . $request['team_name'] . ' đã được ghép với kèo.' : 'Đội tạo kèo đã từ chối lời thách đấu của bạn.',
                'dat-san?show=matches'
            );
            if ($accepted) {
                foreach ($otherApplicants as $applicantId) {
                    if ((int)$applicantId !== (int)$request['applicant_id']) {
                        Notification::create(
                            (int)$applicantId,
                            'Kèo đã ghép với đội khác',
                            'Lời thách đấu của bạn đã đóng vì đội tạo kèo chọn đối thủ khác.',
                            'dat-san?show=matches'
                        );
                    }
                }
            }
            if ($accepted) {
                $ownerId = Notification::challengeOwner((int)$request['challenge_id']);
                if ($ownerId !== null) {
                    Notification::create(
                        $ownerId,
                        'Kèo giao hữu đã ghép đội',
                        'Hai đội đã được ghép với nhau.',
                        'chu-san'
                    );
                }
            }
        }
        Audit::log('match_request_' . $action, "request #$requestId");
        Http::json(['ok' => true]);
    }

    public function requestGuestPlayer(): void
    {
        $user = $this->guard(['customer']);
        $body = $this->body();
        $challengeId = (int)($body['challenge_id'] ?? 0);
        $teamName = trim((string)($body['team_name'] ?? ''));
        $phone = trim((string)($body['contact_phone'] ?? ''));
        $message = trim((string)($body['message'] ?? ''));
        if ($challengeId < 1 || mb_strlen($teamName) < 2 || mb_strlen($teamName) > 80
            || !preg_match('/^(0|\\+84)\\d{9}$/', $phone) || mb_strlen($message) > 300) {
            Http::error('Kiểm tra tên, số điện thoại và lời nhắn của bạn.', 422);
        }
        if (!MatchChallenge::request($challengeId, (int)$user['id'], $teamName, $phone, $message, true)) {
            Http::error('Đội đã ngừng nhận người, đã đủ chỗ, hoặc bạn đã gửi yêu cầu trước đó.', 409);
        }
        $creatorId = Notification::challengeCreator($challengeId);
        if ($creatorId !== null) {
            Notification::create($creatorId, 'Có người xin đá vãng lai', "$teamName gửi yêu cầu tham gia đội của bạn.", 'dat-san?show=matches');
        }
        Audit::log('guest_player_request', "challenge #$challengeId");
        Http::json(['ok' => true]);
    }

    public function decideGuestPlayer(): void
    {
        $user = $this->guard(['customer']);
        $body = $this->body();
        $requestId = (int)($body['request_id'] ?? 0);
        $action = (string)($body['action'] ?? '');
        if ($requestId < 1 || !in_array($action, ['accept', 'decline', 'remove'], true)) {
            Http::error('Yêu cầu không hợp lệ.', 422);
        }
        $request = Notification::matchRequestContext($requestId);
        if (!MatchChallenge::decidePlayerRequest($requestId, (int)$user['id'], $action)) {
            Http::error('Kèo đã đóng, đã đến giờ đá hoặc không còn chỗ để nhận người.', 409);
        }
        if ($request) {
            $title = $action === 'accept' ? 'Yêu cầu đá vãng lai được duyệt'
                : ($action === 'remove' ? 'Bạn đã được xóa khỏi đội' : 'Yêu cầu đá vãng lai bị từ chối');
            $message = $action === 'accept' ? 'Đội trưởng đã nhận bạn vào đội ' . $request['team_name'] . '.'
                : ($action === 'remove' ? 'Đội trưởng đã cập nhật danh sách và bạn không còn trong đội ' . $request['team_name'] . '.'
                    : 'Đội trưởng chưa thể nhận bạn vào đội ' . $request['team_name'] . '.');
            Notification::create((int)$request['applicant_id'], $title, $message, 'dat-san?show=matches');
            if ($action === 'accept') {
                $ownerId = Notification::challengeOwner((int)$request['challenge_id']);
                if ($ownerId !== null) {
                    Notification::create(
                        $ownerId,
                        'Đội đã nhận cầu thủ đá vãng lai',
                        'Đội trưởng đã duyệt thêm người tham gia.',
                        'chu-san'
                    );
                }
            }
        }
        Audit::log('guest_player_' . $action, "request #$requestId");
        Http::json(['ok' => true]);
    }

    public function manageGuestPlayers(): void
    {
        $user = $this->guard(['customer']);
        $body = $this->body();
        $challengeId = (int)($body['challenge_id'] ?? 0);
        $action = (string)($body['action'] ?? '');
        $playersNeeded = null;
        if ($action === 'capacity') {
            $playersNeeded = filter_var($body['players_needed'] ?? null, FILTER_VALIDATE_INT);
            if ($playersNeeded === false || $playersNeeded < 0 || $playersNeeded > 30) {
                Http::error('Số cầu thủ cần tìm phải từ 0 đến 30.', 422);
            }
        }
        if ($challengeId < 1 || !in_array($action, ['capacity', 'open', 'close'], true)) {
            Http::error('Yêu cầu không hợp lệ.', 422);
        }
        if (!MatchChallenge::manageGuestPlayers($challengeId, (int)$user['id'], $action, $playersNeeded)) {
            Http::error('Không thể cập nhật danh sách nhận người; số lượng mới không được ít hơn số người đã duyệt.', 409);
        }
        $messages = [
            'capacity' => 'Đội đã cập nhật số lượng cầu thủ đá vãng lai cần tìm.',
            'open' => 'Đội đã mở lại đăng ký đá vãng lai.',
            'close' => 'Đội đã tạm ngừng nhận yêu cầu đá vãng lai.'
        ];
        Notification::notifyChallengeApplicants($challengeId, 'Cập nhật kèo đá vãng lai', $messages[$action]);
        Audit::log('guest_player_manage_' . $action, "challenge #$challengeId");
        Http::json(['ok' => true]);
    }

    public function cancelMatch(): void
    {
        $user = $this->guard(['customer']);
        $id = (int)($this->body()['challenge_id'] ?? 0);
        if (!MatchChallenge::cancel($id, (int)$user['id'])) {
            Http::error('Chỉ người tạo mới có thể đóng kèo đang mở.', 409);
        }
        Audit::log('match_cancel', "challenge #$id");
        Http::json(['ok' => true]);
    }

    // Keep blocked hours visible so customers can see availability and the owner's reason.
    private function buildSlots(array $pitch, string $date, array $bookedHours, array $unavailableHours): array
    {
        $slots = [];
        $now = (int)date('G');
        $today = $date === date('Y-m-d');
        for ($h = OPEN_HOUR; $h < CLOSE_HOUR; $h++) {
            $status = isset($unavailableHours[$h]) ? 'unavailable' : (!empty($bookedHours[$h]) ? 'booked' : (($today && $h <= $now) ? 'past' : 'free'));
            $slots[] = ['hour' => $h, 'price' => Pitch::price($pitch, $h), 'status' => $status,
                'reason' => $status === 'unavailable' ? (string)$unavailableHours[$h] : ''];
        }
        return $slots;
    }

    public function book(): void
    {
        $u = $this->guard(['customer']);
        Booking::expireHolds();
        $b = $this->body();
        [$pitch, $date, $hours] = $this->bookingSelection($b);
        $voucherCode = strtoupper(trim((string)($b['voucher_code'] ?? '')));
        if (mb_strlen($voucherCode) > 32) {
            Http::error('Mã giảm giá không hợp lệ.', 422);
        }
        $result = Booking::create((int)$u['id'], $pitch, $date, $hours, $voucherCode);
        if ($result['error'] !== null) {
            Http::error($result['error'], 422);
        }
        $id = (int)$result['id'];
        if (!$id) {
            Http::error('Khung giờ vừa được đặt hoặc chủ sân đã gỡ khỏi lịch. Vui lòng chọn lại.', 409);
        }
        $bk = Booking::mine($id, (int)$u['id']);
        Audit::log('book', $bk['code']);
        Notification::notifyBookingParties(
            $id,
            'Đặt sân thành công',
            'Lịch đặt sân của bạn đã được xác nhận.',
            'Có lịch đặt sân mới',
            'Khách hàng vừa đặt sân của bạn.'
        );
        Http::json(['booking' => [
            'id' => (int)$bk['id'], 'code' => $bk['code'], 'date' => $bk['date'],
            'start_hour' => (int)$bk['start_hour'], 'end_hour' => (int)$bk['end_hour'],
            'subtotal' => (int)$bk['subtotal'], 'discount' => (int)$bk['discount'],
            'total' => (int)$bk['total'], 'status' => $bk['status']
        ]]);
    }

    public function quoteVoucher(): void
    {
        $this->guard(['customer']);
        [$pitch, , $hours] = $this->bookingSelection($this->body());
        $body = $this->body();
        $code = strtoupper(trim((string)($body['voucher_code'] ?? '')));
        if (mb_strlen($code) > 32) {
            Http::error('Mã giảm giá không hợp lệ.', 422);
        }
        $subtotal = array_sum(array_map(fn ($hour) => Pitch::price($pitch, $hour), $hours));
        $quote = Voucher::quote($code, $subtotal);
        if (isset($quote['error'])) {
            Http::error($quote['error'], 422);
        }
        Http::json(['quote' => $quote]);
    }

    public function pay(): void
    {
        $u = $this->guard(['customer']);
        Booking::expireHolds();
        $b = Booking::mine((int)($_GET['id'] ?? 0), (int)$u['id']);
        if (!$b || $b['status'] !== 'pending') {
            Http::error('Đơn không còn chờ thanh toán.', 404);
        }
        Http::json(['pay' => Booking::payInfo($b)]);
    }
    public function status(): void
    {
        $u = $this->guard(['customer']);
        Booking::expireHolds();
        $b = Booking::mine((int)($_GET['id'] ?? 0), (int)$u['id']);
        if (!$b) {
            Http::error('Không tìm thấy đơn.', 404);
        }
        Http::json(['status' => $b['status'], 'cancelled_by' => $b['cancelled_by']]);
    }
    public function cancelFeePay(): void
    {
        $u = $this->guard(['customer']);
        $booking = Booking::mine((int)($_GET['id'] ?? 0), (int)$u['id']);
        if (!$booking || $booking['status'] !== 'cancel_fee_pending') {
            Http::error('Đơn không chờ thanh toán phí hủy.', 404);
        }
        Http::json(['pay' => Booking::cancellationPayInfo($booking)]);
    }
    public function sepayWebhook(): void
    {
        if (SEPAY_WEBHOOK_SECRET === '') {
            Http::error('Webhook chưa được cấu hình.', 503);
        }
        $rawBody = file_get_contents('php://input');
        $signature = (string)($_SERVER['HTTP_X_SEPAY_SIGNATURE'] ?? '');
        $timestamp = (string)($_SERVER['HTTP_X_SEPAY_TIMESTAMP'] ?? '');
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
            Http::error('Webhook hết hạn hoặc thiếu timestamp.', 401);
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, SEPAY_WEBHOOK_SECRET);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            Http::error('Chữ ký webhook không hợp lệ.', 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['id'], $payload['transferType'], $payload['transferAmount'], $payload['transactionDate'])
            || !is_scalar($payload['id']) || !ctype_digit((string)$payload['id'])
            || (!is_int($payload['transferAmount']) && !is_string($payload['transferAmount']))
            || filter_var($payload['transferAmount'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !is_string($payload['transactionDate'])
            || !in_array($payload['transferType'], ['in', 'out'], true)) {
            Http::error('Dữ liệu giao dịch không hợp lệ.', 400);
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string)$payload['transactionDate'],
            new DateTimeZone('Asia/Ho_Chi_Minh')
        );
        if (!$date || $date->format('Y-m-d H:i:s') !== (string)$payload['transactionDate']) {
            Http::error('Ngày giao dịch không hợp lệ.', 400);
        }

        // SePay dùng id=0 cho webhook gửi thử; chỉ xác nhận giao dịch thật có ID dương.
        $transactionId = ltrim((string)$payload['id'], '0');
        if ($transactionId !== '') {
            if (isset($payload['content']) && !is_string($payload['content'])) {
                Http::error('Nội dung giao dịch không hợp lệ.', 400);
            }
            if (isset($payload['referenceCode']) && !is_scalar($payload['referenceCode'])) {
                Http::error('Mã tham chiếu không hợp lệ.', 400);
            }
            $result = Booking::confirmFromSepay(
                $transactionId,
                (int)$payload['transferAmount'],
                (string)$payload['transferType'],
                $date->format('Y-m-d H:i:s'),
                (string)($payload['content'] ?? ''),
                (string)($payload['referenceCode'] ?? '')
            );
            if ($result === 'paid') {
                Audit::log('sepay_payment', (string)$payload['id']);
            }
            if ($result === 'paid' && preg_match('/\\b(?:HUY\\s+)?SAN\\s+([A-Z0-9]{8})\\b/i', (string)($payload['content'] ?? ''), $matches)) {
                $bookingId = Notification::bookingIdByCode(strtoupper($matches[1]));
                if ($bookingId !== null) {
                    $isCancellationFee = preg_match('/\\bHUY\\s+SAN\\b/i', (string)($payload['content'] ?? '')) === 1;
                    $customerMessage = $isCancellationFee
                        ? 'Đã nhận và xác nhận tự động phí hủy sân của bạn.'
                        : 'Giao dịch thanh toán sân của bạn đã được xác nhận tự động.';
                    $ownerMessage = $isCancellationFee
                        ? 'Đã nhận phí hủy sân qua chuyển khoản.'
                        : 'Đã nhận thanh toán sân qua chuyển khoản.';
                    Notification::notifyBookingParties(
                        $bookingId,
                        'Đã xác nhận giao dịch',
                        $customerMessage,
                        'Đã nhận thanh toán',
                        $ownerMessage
                    );
                    if ($isCancellationFee) {
                        Notification::notifyBookingChallenges($bookingId);
                    }
                }
            }
        }
        Http::json(['success' => true]);
    }
    public function mine(): void
    {
        $u = $this->guard(['customer']);
        Booking::expireHolds();
        $list = [];
        foreach (Booking::listByUser((int)$u['id']) as $b) {
            $list[] = [
                'id' => (int)$b['id'], 'code' => $b['code'], 'pitch' => $b['pitch'], 'date' => $b['date'],
                'from' => (int)$b['start_hour'], 'to' => (int)$b['end_hour'], 'total' => (int)$b['total'],
                'status' => $b['status'], 'status_text' => STATUS_VI[$b['status']], 'refund' => $b['refund_status'],
                'refund_amount' => (int)$b['refund_amount'], 'cancellation_fee' => (int)$b['cancellation_fee'],
                'can_cancel' => Booking::canCancel($b), 'pitch_type' => $b['pitch_type'],
                'venue' => $b['venue'] ?: 'Cụm sân', 'venue_address' => $b['venue_address'] ?? '',
                'owner_name' => $b['owner_name'], 'owner_phone' => $b['owner_phone'],
                'created_at' => $b['created_at'], 'paid_at' => $b['paid_at'],
                'cancelled_at' => $b['cancelled_at'], 'expires_at' => $b['expires_at'],
                'cancelled_by' => $b['cancelled_by']];
        }
        Http::json(['bookings' => $list]);
    }

    // Bước 1: cấp token dùng 1 lần (5 phút)
    public function cancelStart(): void
    {
        $u = $this->guard(['customer']);
        $b = Booking::mine((int)($this->body()['booking_id'] ?? 0), (int)$u['id']);
        if (!$b || !Booking::canCancel($b)) {
            Http::error('Đơn này không thể hủy (đã hủy, đã hết hạn hoặc sát giờ đá).', 422);
        }
        Http::json(['token' => Booking::startCancel((int)$b['id'], (int)$u['id'])]);
    }
    // Bước 2: token + mật khẩu + gõ "HUY"
    public function cancelConfirm(): void
    {
        $u = $this->guard(['customer']);
        $b = $this->body();
        $bid = (int)($b['booking_id'] ?? 0);
        if (strtoupper(trim((string)($b['phrase'] ?? ''))) !== 'HUY') {
            Http::error('Vui lòng gõ HUY để xác nhận.', 422);
        }
        if (!User::passwordOk((int)$u['id'], (string)($b['password'] ?? ''))) {
            Audit::log('cancel_bad_password', (string)$bid);
            Http::error('Mật khẩu không đúng.', 403);
        }
        $done = Booking::confirmCancel($bid, (int)$u['id'], (string)($b['token'] ?? ''));
        if (!$done) {
            Http::error('Yêu cầu hủy đã hết hạn hoặc không hợp lệ. Hãy thử lại từ đầu.', 422);
        }
        if ($done['status'] === 'cancel_fee_pending') {
            $fee = number_format((int)$done['cancellation_fee'], 0, ',', '.') . 'đ';
            Notification::notifyBookingParties(
                $bid,
                'Cần thanh toán phí hủy sân',
                "Yêu cầu hủy đã ghi nhận; cần thanh toán $fee để hoàn tất hủy sân.",
                'Khách yêu cầu hủy sân',
                "Khách đang chờ thanh toán phí hủy $fee; sân vẫn được giữ cho đến khi xác nhận thanh toán."
            );
        } else {
            $refundMessage = ($done['refund_status'] ?? 'none') === 'pending'
                ? 'Lịch đã hủy; chủ sân cần hoàn tiền theo quy định.'
                : 'Lịch đặt sân đã được hủy.';
            Notification::notifyBookingParties(
                $bid,
                'Lịch đặt sân đã hủy',
                $refundMessage,
                'Khách đã hủy lịch sân',
                'Khách hàng đã hủy lịch đặt sân.'
            );
            Notification::notifyBookingChallenges($bid);
        }
        Audit::log('cancel', $done['code']);
        Http::json([
            'ok' => true,
            'cancellation_fee_due' => $done['status'] === 'cancel_fee_pending',
            'pay' => $done['status'] === 'cancel_fee_pending' ? Booking::cancellationPayInfo(Booking::mine($bid, (int)$u['id'])) : null,
            'refund' => $done['refund_status'] === 'pending',
            'refund_amount' => (int)($done['refund_amount'] ?? 0)
        ]);
    }
}
