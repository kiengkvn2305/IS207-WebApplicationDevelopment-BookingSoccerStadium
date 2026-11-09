<?php
class Notification {
    public static function create(int $userId, string $title, string $message, ?string $link = null): void {
        Database::q("INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)",
            [$userId, $title, $message, $link]);
    }

    public static function forBooking(int $bookingId): ?array {
        return Database::q("SELECT b.id,b.user_id customer_id,p.owner_id,b.code,b.date,b.start_hour,b.end_hour,
                p.name pitch,o.venue_name
            FROM bookings b JOIN pitches p ON p.id=b.pitch_id
            LEFT JOIN owner_profiles o ON o.user_id=p.owner_id WHERE b.id=?", [$bookingId])->fetch() ?: null;
    }

    public static function bookingIdByCode(string $code): ?int {
        $id = Database::q("SELECT id FROM bookings WHERE code=?", [$code])->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function notifyBookingParties(
        int $bookingId,
        string $customerTitle,
        string $customerMessage,
        string $ownerTitle,
        string $ownerMessage
    ): void {
        $booking = self::forBooking($bookingId);
        if (!$booking) throw new RuntimeException('Không tìm thấy đơn đặt sân để gửi thông báo.');
        $details = sprintf('%s · %s · %s %02d:00–%02d:00 · Mã %s',
            $booking['venue_name'] ?: 'Cụm sân', $booking['pitch'], $booking['date'],
            $booking['start_hour'], $booking['end_hour'], $booking['code']);
        self::create((int)$booking['customer_id'], $customerTitle, $customerMessage . ' ' . $details, 'dat-san');
        self::create((int)$booking['owner_id'], $ownerTitle, $ownerMessage . ' ' . $details, 'chu-san');
    }

    public static function notifyBookingCustomer(int $bookingId, string $title, string $message): void {
        $booking = self::forBooking($bookingId);
        if (!$booking) throw new RuntimeException('Không tìm thấy đơn đặt sân để gửi thông báo.');
        $details = sprintf('%s · %s · %s %02d:00–%02d:00 · Mã %s',
            $booking['venue_name'] ?: 'Cụm sân', $booking['pitch'], $booking['date'],
            $booking['start_hour'], $booking['end_hour'], $booking['code']);
        self::create((int)$booking['customer_id'], $title, $message . ' ' . $details, 'dat-san');
    }

    public static function notifyBookingOwner(int $bookingId, string $title, string $message): void {
        $booking = self::forBooking($bookingId);
        if (!$booking) throw new RuntimeException('Không tìm thấy đơn đặt sân để gửi thông báo.');
        $details = sprintf('%s · %s · %s %02d:00–%02d:00 · Mã %s',
            $booking['venue_name'] ?: 'Cụm sân', $booking['pitch'], $booking['date'],
            $booking['start_hour'], $booking['end_hour'], $booking['code']);
        self::create((int)$booking['owner_id'], $title, $message . ' ' . $details, 'chu-san');
    }

    public static function challengeCreator(int $challengeId): ?int {
        $id = Database::q("SELECT creator_id FROM match_challenges WHERE id=?", [$challengeId])->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function challengeOwner(int $challengeId): ?int {
        $id = Database::q("SELECT p.owner_id FROM match_challenges c
            JOIN bookings b ON b.id=c.booking_id JOIN pitches p ON p.id=b.pitch_id WHERE c.id=?",
            [$challengeId])->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function matchRequestContext(int $requestId): ?array {
        return Database::q("SELECT r.applicant_id,c.id challenge_id,c.creator_id,c.team_name
            FROM match_requests r JOIN match_challenges c ON c.id=r.challenge_id WHERE r.id=?", [$requestId])->fetch() ?: null;
    }

    public static function pendingMatchApplicants(int $challengeId, string $requestType): array {
        if (!in_array($requestType, ['opponent', 'player'], true)) {
            throw new InvalidArgumentException('Loại yêu cầu kèo không hợp lệ.');
        }
        return Database::q("SELECT applicant_id FROM match_requests
            WHERE challenge_id=? AND request_type=? AND status='pending'", [$challengeId, $requestType])->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function notifyChallengeApplicants(int $challengeId, string $title, string $message): void {
        $context = Database::q("SELECT c.creator_id,p.owner_id FROM match_challenges c
            JOIN bookings b ON b.id=c.booking_id JOIN pitches p ON p.id=b.pitch_id WHERE c.id=?",
            [$challengeId])->fetch();
        $recipients = [];
        if ($context) {
            $recipients[(int)$context['creator_id']] = ['id' => (int)$context['creator_id'], 'link' => 'dat-san?show=matches'];
            $recipients[(int)$context['owner_id']] = ['id' => (int)$context['owner_id'], 'link' => 'chu-san'];
        }
        $applicants = Database::q("SELECT applicant_id FROM match_requests WHERE challenge_id=? AND status IN ('pending','accepted')",
            [$challengeId])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($applicants as $applicantId) {
            $recipients[(int)$applicantId] = ['id' => (int)$applicantId, 'link' => 'dat-san?show=matches'];
        }
        foreach ($recipients as $recipient) {
            self::create($recipient['id'], $title, $message, $recipient['link']);
        }
    }

    public static function notifyBookingChallenges(int $bookingId): void {
        $challenges = Database::q("SELECT c.id FROM match_challenges c WHERE c.booking_id=?", [$bookingId])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($challenges as $challengeId) {
            $context = Database::q("SELECT c.creator_id,p.owner_id FROM match_challenges c
                JOIN bookings b ON b.id=c.booking_id JOIN pitches p ON p.id=b.pitch_id WHERE c.id=?",
                [$challengeId])->fetch();
            if (!$context) continue;
            $recipients = [
                (int)$context['creator_id'] => ['id' => (int)$context['creator_id'], 'link' => 'dat-san?show=matches'],
                (int)$context['owner_id'] => ['id' => (int)$context['owner_id'], 'link' => 'chu-san']
            ];
            foreach (Database::q("SELECT applicant_id FROM match_requests WHERE challenge_id=?", [$challengeId])->fetchAll(PDO::FETCH_COLUMN) as $applicantId) {
                $recipients[(int)$applicantId] = ['id' => (int)$applicantId, 'link' => 'dat-san?show=matches'];
            }
            foreach ($recipients as $recipient) {
                self::create($recipient['id'], 'Kèo giao hữu đã bị hủy',
                    'Lịch sân của kèo không còn hiệu lực nên yêu cầu tham gia đã đóng.', $recipient['link']);
            }
        }
    }

    public static function notifyReviewOwner(int $ownerId, string $customerName, int $rating): void {
        $owner = Database::q("SELECT user_id,venue_name FROM owner_profiles WHERE user_id=?", [$ownerId])->fetch();
        if ($owner) self::create((int)$owner['user_id'], 'Cụm sân có đánh giá mới',
            "$customerName vừa gửi đánh giá $rating sao cho " . $owner['venue_name'] . '.', 'chu-san');
    }

    public static function reviewCustomer(int $reviewId): ?int {
        $id = Database::q("SELECT user_id FROM venue_reviews WHERE id=?", [$reviewId])->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function listForUser(int $userId): array {
        return [
            'items' => Database::q("SELECT id,title,message,link,created_at,read_at FROM notifications
                WHERE user_id=? ORDER BY id DESC LIMIT 50", [$userId])->fetchAll(),
            'unread_count' => (int)Database::q("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL",
                [$userId])->fetchColumn()
        ];
    }

    public static function markRead(int $id, int $userId): bool {
        Database::q("UPDATE notifications SET read_at=COALESCE(read_at,NOW())
            WHERE id=? AND user_id=?", [$id, $userId]);
        return (bool)Database::q("SELECT 1 FROM notifications WHERE id=? AND user_id=?", [$id, $userId])->fetchColumn();
    }

    public static function markAllRead(int $userId): void {
        Database::q("UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL", [$userId]);
    }
}
