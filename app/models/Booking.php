<?php

class Booking
{
    public const SEL = "SELECT b.*, v.code voucher_code, p.name pitch, p.type pitch_type, o.venue_name venue,
        o.address venue_address, owner.name owner_name, owner.phone owner_phone,
        TIMESTAMPDIFF(SECOND,NOW(),b.expires_at) left_s,
        TIMESTAMPDIFF(MINUTE,NOW(),TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))) mins_to_start,
        TIMESTAMPDIFF(SECOND,NOW(),TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))) seconds_to_start
        FROM bookings b JOIN pitches p ON p.id=b.pitch_id
        JOIN users owner ON owner.id=p.owner_id LEFT JOIN owner_profiles o ON o.user_id=owner.id
        LEFT JOIN vouchers v ON v.id=b.voucher_id ";

    // Nhả sân với các đơn chờ thanh toán đã quá hạn
    public static function expireHolds(): void
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $expired = $pdo->query("SELECT b.id,b.user_id,p.owner_id,b.code,b.date,b.start_hour,b.end_hour,p.name pitch,o.venue_name
                FROM bookings b JOIN pitches p ON p.id=b.pitch_id
                LEFT JOIN owner_profiles o ON o.user_id=p.owner_id
                WHERE b.status='pending' AND b.expires_at<NOW() FOR UPDATE")->fetchAll();
            if ($expired) {
                $ids = array_map(fn ($booking) => (int)$booking['id'], $expired);
                $in = implode(',', $ids);
                $pdo->exec("DELETE FROM booking_slots WHERE booking_id IN ($in)");
                $pdo->exec("UPDATE bookings SET status='expired' WHERE id IN ($in)");
                foreach ($expired as $booking) {
                    $details = sprintf(
                        '%s · %s · %s %02d:00–%02d:00 · Mã %s',
                        $booking['venue_name'] ?: 'Cụm sân',
                        $booking['pitch'],
                        $booking['date'],
                        $booking['start_hour'],
                        $booking['end_hour'],
                        $booking['code']
                    );
                    Notification::create(
                        (int)$booking['user_id'],
                        'Đơn đặt sân đã hết hạn',
                        'Đơn chờ thanh toán đã hết hạn và khung giờ được mở lại. ' . $details,
                        'dat-san'
                    );
                    Notification::create(
                        (int)$booking['owner_id'],
                        'Đơn chờ thanh toán đã hết hạn',
                        'Một đơn chờ thanh toán đã hết hạn; khung giờ được mở lại. ' . $details,
                        'chu-san'
                    );
                }
            }
            Database::commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    public static function bookedMap(string $date): array
    {
        $m = [];
        foreach (Database::q("SELECT pitch_id,hour FROM booking_slots WHERE date=?", [$date]) as $r) {
            $m[$r['pitch_id']][$r['hour']] = true;
        }
        return $m;
    }
    public static function pendingCount(int $uid): int
    {
        return (int)Database::q("SELECT COUNT(*) FROM bookings WHERE user_id=? AND status='pending'", [$uid])->fetchColumn();
    }
    // Returns the booking id, applied discount, or an error when the voucher is no longer usable.
    public static function create(int $uid, array $pitch, string $date, array $hours, string $voucherCode = ''): array
    {
        $pdo = Database::pdo();
        $code = strtoupper(bin2hex(random_bytes(4)));
        Database::beginTransaction();
        try {
            $pitch = Pitch::bookable((int)$pitch['id'], true);
            if (!$pitch || ($date === date('Y-m-d') && min($hours) <= (int)date('G'))) {
                Database::rollBack();
                return ['id' => 0, 'error' => 'Sân hoặc khung giờ không còn khả dụng. Vui lòng chọn lại.'];
            }
            $total = array_sum(array_map(fn ($h) => Pitch::price($pitch, $h), $hours));
            $voucher = null;
            $discount = 0;
            if ($voucherCode !== '') {
                $voucher = Voucher::quote($voucherCode, $total, true);
                if (isset($voucher['error'])) {
                    Database::rollBack();
                    return ['id' => 0, 'error' => $voucher['error']];
                }
                $discount = $voucher['discount'];
            }
            foreach ($hours as $h) {
                if (Database::q("SELECT 1 FROM unavailable_slots WHERE pitch_id=? AND date=? AND hour=?", [$pitch['id'], $date, $h])->fetchColumn()) {
                    Database::rollBack();
                    return ['id' => 0, 'error' => null];
                }
            }
            Database::q(
                "INSERT INTO bookings(code,user_id,pitch_id,date,start_hour,end_hour,total,subtotal,discount,voucher_id,status,expires_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,'confirmed',TIMESTAMP(?,MAKETIME(?,0,0)))",
                [$code, $uid, $pitch['id'], $date, $hours[0], end($hours) + 1, $total - $discount, $total,
                    $discount, $voucher['id'] ?? null, $date, end($hours) + 1]
            );
            $id = (int)$pdo->lastInsertId();
            foreach ($hours as $h) {
                Database::q("INSERT INTO booking_slots(booking_id,pitch_id,date,hour) VALUES(?,?,?,?)", [$id, $pitch['id'], $date, $h]);
            }
            if ($voucher) {
                Database::q("UPDATE vouchers SET usage_count=usage_count+1 WHERE id=?", [$voucher['id']]);
                Database::q(
                    "INSERT INTO voucher_redemptions(voucher_id,booking_id,user_id,discount) VALUES(?,?,?,?)",
                    [$voucher['id'], $id, $uid, $discount]
                );
            }
            Database::commit();
            return ['id' => $id, 'error' => null, 'subtotal' => $total, 'discount' => $discount, 'total' => $total - $discount];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return ['id' => 0, 'error' => null];
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    // Chỉ lấy đơn của chính người dùng
    public static function mine(int $id, int $uid): ?array
    {
        return Database::q(self::SEL . "WHERE b.id=? AND b.user_id=?", [$id, $uid])->fetch() ?: null;
    }
    public static function listByUser(int $uid): array
    {
        return Database::q(self::SEL . "WHERE b.user_id=? ORDER BY b.id DESC LIMIT 30", [$uid])->fetchAll();
    }
    public static function canCancel(array $b): bool
    {
        if ($b['status'] === 'pending') {
            return strtotime((string)$b['expires_at']) > time();
        }
        if ($b['status'] === 'confirmed') {
            return (int)$b['seconds_to_start'] > 0;
        }
        return $b['status'] === 'paid' && (int)$b['seconds_to_start'] >= CANCEL_BEFORE_HOURS * 3600;
    }
    // QR VietQR chuyển tiền vào đúng tài khoản chủ sân đã khai báo
    public static function payInfo(array $b): array
    {
        $o = Database::q("SELECT o.* FROM pitches p JOIN owner_profiles o ON o.user_id=p.owner_id WHERE p.id=?", [$b['pitch_id']])->fetch();
        $content = 'SAN ' . $b['code'];
        $qr = 'https://img.vietqr.io/image/' . $o['bank_bin'] . '-' . $o['account_no'] . '-compact2.png?' .
              http_build_query(['amount' => $b['total'], 'addInfo' => $content, 'accountName' => $o['account_name']]);
        return ['booking_id' => (int)$b['id'], 'code' => $b['code'], 'qr' => $qr, 'bank' => BANKS[$o['bank_bin']] ?? '',
                'account_no' => $o['account_no'], 'account_name' => $o['account_name'], 'amount' => (int)$b['total'],
                'content' => $content, 'seconds_left' => max(0, (int)$b['left_s']),
                'sepay_secret_configured' => SEPAY_WEBHOOK_SECRET !== ''];
    }

    public static function cancellationPayInfo(array $b): array
    {
        $o = Database::q("SELECT o.* FROM pitches p JOIN owner_profiles o ON o.user_id=p.owner_id WHERE p.id=?", [$b['pitch_id']])->fetch();
        if (!$o || !$o['account_no']) {
            Http::error('Chủ sân chưa cấu hình tài khoản nhận tiền.', 409);
        }
        $amount = (int)$b['cancellation_fee'];
        $content = 'HUY SAN ' . $b['code'];
        $qr = 'https://img.vietqr.io/image/' . $o['bank_bin'] . '-' . $o['account_no'] . '-compact2.png?' .
              http_build_query(['amount' => $amount, 'addInfo' => $content, 'accountName' => $o['account_name']]);
        return ['booking_id' => (int)$b['id'], 'code' => $b['code'], 'qr' => $qr,
            'bank' => BANKS[$o['bank_bin']] ?? '', 'account_no' => $o['account_no'],
            'account_name' => $o['account_name'], 'amount' => $amount, 'content' => $content,
            'sepay_secret_configured' => SEPAY_WEBHOOK_SECRET !== ''];
    }

    // ---- Hủy 2 bước ----
    public static function startCancel(int $bid, int $uid): string
    {
        $token = bin2hex(random_bytes(16));
        Database::q(
            "INSERT INTO cancel_requests(booking_id,user_id,token_hash,expires_at) VALUES(?,?,?,NOW() + INTERVAL 5 MINUTE)",
            [$bid, $uid, hash('sha256', $token)]
        );
        return $token;
    }
    // Trả về đơn đã hủy hoặc null nếu token sai/hết hạn/không còn hủy được
    public static function confirmCancel(int $bid, int $uid, string $token): ?array
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $lockedBooking = Database::q("SELECT id FROM bookings WHERE id=? AND user_id=? FOR UPDATE", [$bid, $uid])->fetchColumn();
            $rid = Database::q(
                "SELECT id FROM cancel_requests WHERE booking_id=? AND user_id=? AND token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE",
                [$bid, $uid, hash('sha256', $token)]
            )->fetchColumn();
            $b = self::mine($bid, $uid);
            if (!$lockedBooking || !$rid || !$b || !self::canCancel($b)) {
                Database::rollBack();
                return null;
            }
            Database::q("UPDATE cancel_requests SET used_at=NOW() WHERE id=?", [$rid]);
            $withinFeeWindow = $b['status'] === 'confirmed' && (int)$b['seconds_to_start'] <= CANCEL_BEFORE_HOURS * 3600;
            $fee = $withinFeeWindow
                ? (int)Database::q("SELECT sannha_cancel_fee(?)", [(int)$b['total']])->fetchColumn()
                : 0;
            if ($fee > 0) {
                Database::q(
                    "UPDATE bookings SET status='cancel_fee_pending',cancellation_fee=?,cancel_requested_at=NOW() WHERE id=?",
                    [$fee, $bid]
                );
                $b['status'] = 'cancel_fee_pending';
                $b['cancellation_fee'] = $fee;
                Database::commit();
                return $b;
            }
            $refundAmount = $b['status'] === 'paid' ? (int)$b['total'] : 0;
            Database::q(
                "UPDATE bookings SET status='cancelled', cancelled_at=NOW(),cancelled_by='customer',
                refund_status=?,refund_amount=? WHERE id=?",
                [$refundAmount > 0 ? 'pending' : 'none', $refundAmount, $bid]
            );
            self::releaseBookingSlotsAndCloseMatches($bid);
            $b['refund_status'] = $refundAmount > 0 ? 'pending' : 'none';
            $b['refund_amount'] = $refundAmount;
            $b['status'] = 'cancelled';
            Database::commit();
            return $b;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private static function releaseBookingSlotsAndCloseMatches(int $bookingId): void
    {
        Database::q("UPDATE match_challenges SET status='cancelled' WHERE booking_id=? AND status IN ('open','matched')", [$bookingId]);
        Database::q("UPDATE match_requests SET status='declined'
            WHERE challenge_id IN (SELECT id FROM match_challenges WHERE booking_id=?) AND status IN ('pending','accepted')", [$bookingId]);
        Database::q("DELETE FROM booking_slots WHERE booking_id=?", [$bookingId]);
    }

    // ---- Chủ sân ----
    public static function listByOwner(int $owner): array
    {
        return Database::q("SELECT b.id,b.code,b.date,b.start_hour,b.end_hour,b.total,b.subtotal,b.discount,v.code voucher_code,b.status,b.refund_status,b.refund_amount,
            b.cancellation_fee,b.cancelled_by,
            TIMESTAMPDIFF(SECOND,NOW(),TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))) owner_seconds_to_start,
            (b.expires_at>NOW()) live,
            p.name pitch, c.name cname, c.phone cphone FROM bookings b JOIN pitches p ON p.id=b.pitch_id JOIN users c ON c.id=b.user_id
            LEFT JOIN vouchers v ON v.id=b.voucher_id
            WHERE p.owner_id=? ORDER BY b.id DESC LIMIT 50", [$owner])->fetchAll();
    }
    public static function cancelByOwner(int $id, int $owner): ?array
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $b = Database::q("SELECT b.id,b.user_id,b.status,b.total,b.cancellation_fee,b.refund_status,
                TIMESTAMPDIFF(SECOND,NOW(),TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))) seconds_to_start
                FROM bookings b JOIN pitches p ON p.id=b.pitch_id
                WHERE b.id=? AND p.owner_id=? FOR UPDATE", [$id, $owner])->fetch();
            if (!$b || !in_array($b['status'], ['confirmed','pending','paid','cancel_fee_pending'], true)
                || (int)$b['seconds_to_start'] <= 0) {
                Database::rollBack();
                return null;
            }
            $refundAmount = $b['status'] === 'paid' ? (int)$b['total'] : 0;
            Database::q("UPDATE bookings SET status='cancelled',cancelled_at=NOW(),cancelled_by='owner',
                refund_status=?,refund_amount=? WHERE id=?", [$refundAmount ? 'pending' : 'none', $refundAmount, $id]);
            self::releaseBookingSlotsAndCloseMatches($id);
            Database::commit();
            return ['user_id' => (int)$b['user_id'], 'refund_amount' => $refundAmount];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    public static function confirmPaid(int $id, int $owner): bool
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $b = Database::q("SELECT b.id,b.total FROM bookings b JOIN pitches p ON p.id=b.pitch_id
                WHERE b.id=? AND p.owner_id=?
                    AND (b.status='confirmed' OR (b.status='pending' AND b.expires_at>NOW())) FOR UPDATE", [$id, $owner])->fetch();
            if (!$b) {
                Database::rollBack();
                return false;
            }
            Database::q("UPDATE bookings SET status='paid', paid_at=NOW(), confirmed_by=? WHERE id=?", [$owner, $id]);
            Database::q("INSERT INTO payments(booking_id,amount,confirmed_by) VALUES(?,?,?)", [$id, $b['total'], $owner]);
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    public static function confirmCancellationFee(int $id, int $owner): bool
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $b = Database::q("SELECT b.id,b.cancellation_fee
                FROM bookings b JOIN pitches p ON p.id=b.pitch_id
                WHERE b.id=? AND p.owner_id=? AND b.status='cancel_fee_pending' FOR UPDATE", [$id, $owner])->fetch();
            if (!$b) {
                Database::rollBack();
                return false;
            }
            Database::q("UPDATE bookings SET status='cancelled',cancelled_at=NOW(),cancelled_by='customer',
                refund_status='none',refund_amount=0 WHERE id=?", [$id]);
            Database::q(
                "INSERT INTO payments(booking_id,amount,confirmed_by) VALUES(?,?,?)",
                [$id, $b['cancellation_fee'], $owner]
            );
            self::releaseBookingSlotsAndCloseMatches($id);
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    public static function confirmFromSepay(string $transactionId, int $amount, string $type, string $date, string $content, string $referenceCode): string
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $event = Database::q(
                "INSERT IGNORE INTO sepay_webhook_events
                (transaction_id,transfer_amount,transfer_type,transaction_date,reference_code,content,status)
                VALUES(?,?,?,?,?,?, 'received')",
                [$transactionId, $amount, $type, $date, mb_substr($referenceCode, 0, 100), mb_substr($content, 0, 500)]
            );
            if ($event->rowCount() === 0) {
                Database::commit();
                return 'duplicate';
            }

            if ($type !== 'in') {
                Database::q("UPDATE sepay_webhook_events SET status='ignored' WHERE transaction_id=?", [$transactionId]);
                Database::commit();
                return 'ignored';
            }

            if (preg_match('/\\bHUY\\s+SAN\\s+([A-Z0-9]{8})\\b/i', $content, $matches)) {
                $booking = Database::q("SELECT id,status,cancellation_fee,cancel_requested_at,cancelled_by,date,start_hour
                    FROM bookings WHERE code=? FOR UPDATE", [strtoupper($matches[1])])->fetch();
                if (!$booking) {
                    Database::q("UPDATE sepay_webhook_events SET status='unmatched' WHERE transaction_id=?", [$transactionId]);
                    Database::commit();
                    return 'unmatched';
                }
                if ($booking['status'] !== 'cancel_fee_pending') {
                    if ($booking['status'] === 'cancelled' && $booking['cancelled_by'] === 'owner'
                        && $booking['cancel_requested_at'] && strtotime($date) >= strtotime($booking['cancel_requested_at'])
                        && $amount === (int)$booking['cancellation_fee']) {
                        Database::q("INSERT INTO payments(booking_id,amount,confirmed_by) VALUES(?,?,NULL)", [$booking['id'], $amount]);
                        Database::q("UPDATE bookings SET refund_status='pending',refund_amount=? WHERE id=?", [$amount, $booking['id']]);
                        Database::q(
                            "UPDATE sepay_webhook_events SET booking_id=?,status='paid' WHERE transaction_id=?",
                            [$booking['id'], $transactionId]
                        );
                        Database::commit();
                        return 'paid';
                    }
                    Database::q(
                        "UPDATE sepay_webhook_events SET booking_id=?,status='not_pending' WHERE transaction_id=?",
                        [$booking['id'], $transactionId]
                    );
                    Database::commit();
                    return 'not_pending';
                }
                if (!$booking['cancel_requested_at'] || strtotime($date) < strtotime($booking['cancel_requested_at'])) {
                    Database::q(
                        "UPDATE sepay_webhook_events SET booking_id=?,status='late' WHERE transaction_id=?",
                        [$booking['id'], $transactionId]
                    );
                    Database::commit();
                    return 'late';
                }
                if ($amount !== (int)$booking['cancellation_fee']) {
                    Database::q(
                        "UPDATE sepay_webhook_events SET booking_id=?,status='amount_mismatch' WHERE transaction_id=?",
                        [$booking['id'], $transactionId]
                    );
                    Database::commit();
                    return 'amount_mismatch';
                }
                $updated = Database::q("UPDATE bookings SET status='cancelled',cancelled_at=NOW(),cancelled_by='customer',
                    refund_status='none',refund_amount=0 WHERE id=? AND status='cancel_fee_pending'", [$booking['id']]);
                if ($updated->rowCount() === 0) {
                    Database::q(
                        "UPDATE sepay_webhook_events SET booking_id=?,status='late' WHERE transaction_id=?",
                        [$booking['id'], $transactionId]
                    );
                    Database::commit();
                    return 'late';
                }
                Database::q("INSERT INTO payments(booking_id,amount,confirmed_by) VALUES(?,?,NULL)", [$booking['id'], $amount]);
                self::releaseBookingSlotsAndCloseMatches((int)$booking['id']);
                Database::q(
                    "UPDATE sepay_webhook_events SET booking_id=?,status='paid' WHERE transaction_id=?",
                    [$booking['id'], $transactionId]
                );
                Database::commit();
                return 'paid';
            }

            if (!preg_match('/\\bSAN\\s+([A-Z0-9]{8})\\b/i', $content, $matches)) {
                Database::q("UPDATE sepay_webhook_events SET status='ignored' WHERE transaction_id=?", [$transactionId]);
                Database::commit();
                return 'ignored';
            }

            $booking = Database::q(
                "SELECT id,total,status,expires_at FROM bookings WHERE code=? FOR UPDATE",
                [strtoupper($matches[1])]
            )->fetch();
            if (!$booking) {
                Database::q("UPDATE sepay_webhook_events SET status='unmatched' WHERE transaction_id=?", [$transactionId]);
                Database::commit();
                return 'unmatched';
            }
            if ($booking['status'] !== 'pending') {
                $status = $booking['status'] === 'paid' ? 'already_paid' : 'not_pending';
                Database::q(
                    "UPDATE sepay_webhook_events SET booking_id=?,status=? WHERE transaction_id=?",
                    [$booking['id'], $status, $transactionId]
                );
                Database::commit();
                return $status;
            }
            if ($amount !== (int)$booking['total']) {
                Database::q(
                    "UPDATE sepay_webhook_events SET booking_id=?,status='amount_mismatch' WHERE transaction_id=?",
                    [$booking['id'], $transactionId]
                );
                Database::commit();
                return 'amount_mismatch';
            }
            if (strtotime($booking['expires_at']) <= time() || strtotime($date) > strtotime($booking['expires_at'])) {
                Database::q(
                    "UPDATE sepay_webhook_events SET booking_id=?,status='late' WHERE transaction_id=?",
                    [$booking['id'], $transactionId]
                );
                Database::commit();
                return 'late';
            }

            $updated = Database::q("UPDATE bookings SET status='paid',paid_at=NOW(),confirmed_by=NULL
                WHERE id=? AND status='pending' AND expires_at>NOW()", [$booking['id']]);
            if ($updated->rowCount() === 0) {
                Database::q(
                    "UPDATE sepay_webhook_events SET booking_id=?,status='late' WHERE transaction_id=?",
                    [$booking['id'], $transactionId]
                );
                Database::commit();
                return 'late';
            }
            Database::q(
                "INSERT INTO payments(booking_id,amount,confirmed_by) VALUES(?,?,NULL)",
                [$booking['id'], $amount]
            );
            Database::q(
                "UPDATE sepay_webhook_events SET booking_id=?,status='paid' WHERE transaction_id=?",
                [$booking['id'], $transactionId]
            );
            Database::commit();
            return 'paid';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    public static function refundDone(int $id, int $owner): bool
    {
        return Database::q("UPDATE bookings b JOIN pitches p ON p.id=b.pitch_id SET b.refund_status='done'
            WHERE b.id=? AND p.owner_id=? AND b.refund_status='pending' AND b.refund_amount>0", [$id, $owner])->rowCount() > 0;
    }

    // ---- Admin ----
    public static function recentAll(): array
    {
        return Database::q("SELECT b.code,b.date,b.start_hour,b.total,b.subtotal,b.discount,v.code voucher_code,b.status,p.name pitch,c.name cname,w.name wname FROM bookings b
            LEFT JOIN vouchers v ON v.id=b.voucher_id
            JOIN pitches p ON p.id=b.pitch_id JOIN users c ON c.id=b.user_id JOIN users w ON w.id=p.owner_id ORDER BY b.id DESC LIMIT 30")->fetchAll();
    }
}
