<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

$checks = 0;
$userIds = [];
$bookingIds = [];
$eventIds = [];
$voucherIds = [];
$prefix = 'WF_' . strtoupper(bin2hex(random_bytes(4)));

function verifyWorkflow(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo "PASS: $message\n";
}

function workflowUser(string $role): int
{
    global $userIds, $prefix;
    $email = strtolower($prefix . '-' . count($userIds)) . '@example.test';
    verifyWorkflow(User::create('Workflow ' . $role, $email, '0900000004', 'Test@12345', $role, 'Workflow venue'), 'create ' . $role . ' account');
    $id = (int)User::byEmail($email)['id'];
    $userIds[] = $id;
    return $id;
}

function workflowBooking(int $customer, int $pitch, int $days, array $hours = [10], string $voucher = ''): array
{
    global $bookingIds;
    $result = Booking::create($customer, Pitch::bookable($pitch), date('Y-m-d', strtotime("+$days days")), $hours, $voucher);
    if (!$result['id']) {
        throw new RuntimeException('Cannot create workflow booking: ' . json_encode($result));
    }
    $bookingIds[] = $result['id'];
    return $result;
}

function workflowEvent(array $booking, int $amount, string $type = 'in'): string
{
    global $eventIds;
    $id = (string)random_int(100000000, 2000000000);
    $eventIds[] = $id;
    return Booking::confirmFromSepay($id, $amount, $type, date('Y-m-d H:i:s'), 'SAN ' . $booking['code'], 'test');
}

try {
    $customer = workflowUser('customer');
    $otherCustomer = workflowUser('customer');
    $owner = workflowUser('owner');
    $otherOwner = workflowUser('owner');
    verifyWorkflow(User::find($owner)['status'] === 'pending', 'owner registration needs approval');
    verifyWorkflow(User::passwordOk($customer, 'Test@12345') && !User::passwordOk($customer, 'bad'), 'password hash verification');
    $loginEmail = User::find($customer)['email'];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        User::logFail($loginEmail, '198.51.100.1');
    }
    verifyWorkflow(User::failedAttempts($loginEmail, '198.51.100.1') >= 5, 'failed login attempts reach throttling threshold');
    User::clearFails($loginEmail);
    verifyWorkflow(User::failedAttempts($loginEmail, '198.51.100.1') === 0, 'successful login clears account failure history');
    verifyWorkflow(!User::create('Duplicate', User::find($customer)['email'], '0900000004', 'Test@12345', 'owner', 'Duplicate'), 'duplicate email rejected atomically');
    verifyWorkflow(User::applyAction('approve', $owner) && User::applyAction('approve', $otherOwner), 'approve pending owners');
    verifyWorkflow(!User::applyAction('lock', 1), 'admin account cannot be locked');
    verifyWorkflow(OwnerProfile::update($owner, 'Workflow venue', 'Real test address', 10.7, 106.6, '970436', '123456789', 'TEST OWNER', 1), 'save versioned bank profile');
    verifyWorkflow(!OwnerProfile::update($owner, 'Stale', '', null, null, '970436', '123456789', 'TEST OWNER', 1), 'stale bank profile rejected');
    Pitch::add($owner, 'Workflow pitch', 'Sân 5 người', 100001, 150001);
    $pitch = (int)Pitch::byOwner($owner)[0]['id'];
    verifyWorkflow(Pitch::bookable($pitch) !== null, 'approved configured owner can publish pitch');
    User::applyAction('lock', $owner);
    verifyWorkflow(Pitch::bookable($pitch) === null, 'locked owner pitch hidden');
    User::applyAction('unlock', $owner);
    verifyWorkflow(!Pitch::toggle($pitch, $otherOwner), 'owner cannot toggle another owner pitch');

    Notification::create($customer, 'First', 'First notice', 'dat-san');
    Notification::create($customer, 'Second', 'Second notice', 'dat-san');
    Notification::create($otherCustomer, 'Private', 'Other account notice', 'dat-san');
    $notice = (int)Notification::listForUser($customer)['items'][0]['id'];
    verifyWorkflow(Notification::listForUser($customer)['unread_count'] === 2, 'notification unread count');
    verifyWorkflow(!Notification::markRead($notice, $otherCustomer), 'cannot read another account notification');
    verifyWorkflow(Notification::markRead($notice, $customer), 'mark one notice read');
    verifyWorkflow(Notification::listForUser($customer)['unread_count'] === 1, 'read decreases unread counter');
    verifyWorkflow(Notification::markUnread($notice, $customer) && Notification::markUnread($notice, $customer), 'mark unread is repeatable');
    verifyWorkflow(!Notification::markUnread($notice, $otherCustomer), 'cannot mark another account notification unread');
    Notification::markAllRead($customer);
    verifyWorkflow(Notification::listForUser($customer)['unread_count'] === 0, 'mark all read');
    verifyWorkflow(Notification::listForUser($otherCustomer)['unread_count'] === 1, 'bulk read isolated by user');
    Notification::markAllUnread($customer);
    verifyWorkflow(Notification::listForUser($customer)['unread_count'] === 2, 'bulk unread restores unread notices');

    $voucherData = [
        'code' => $prefix . '_FREE', 'discount_type' => 'percent', 'discount_value' => 100,
        'min_total' => 0, 'starts_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        'ends_at' => date('Y-m-d H:i:s', strtotime('+10 days')), 'usage_limit' => null,
    ];
    $freeVoucher = Voucher::create($voucherData, 1);
    $voucherIds[] = $freeVoucher;
    verifyWorkflow(Voucher::setActive($freeVoucher, false) && Voucher::setActive($freeVoucher, false), 'voucher toggle is idempotent');
    verifyWorkflow(isset(Voucher::quote($voucherData['code'], 100001)['error']), 'inactive voucher rejected');
    Voucher::setActive($freeVoucher, true);
    Database::q('UPDATE vouchers SET min_total=200000 WHERE id=?', [$freeVoucher]);
    verifyWorkflow(isset(Voucher::quote($voucherData['code'], 100001)['error']), 'voucher minimum checked');
    Database::q('UPDATE vouchers SET min_total=0,starts_at=NOW()+INTERVAL 1 DAY WHERE id=?', [$freeVoucher]);
    verifyWorkflow(isset(Voucher::quote($voucherData['code'], 100001)['error']), 'future voucher rejected');
    Database::q('UPDATE vouchers SET starts_at=NOW()-INTERVAL 2 DAY,ends_at=NOW()-INTERVAL 1 DAY WHERE id=?', [$freeVoucher]);
    verifyWorkflow(isset(Voucher::quote($voucherData['code'], 100001)['error']), 'expired voucher rejected');
    Database::q('UPDATE vouchers SET ends_at=NOW()+INTERVAL 10 DAY WHERE id=?', [$freeVoucher]);

    $paid = workflowBooking($customer, $pitch, 3, [16, 17]);
    verifyWorkflow($paid['total'] === 250002, 'mixed standard and peak prices calculated on server');
    verifyWorkflow(Booking::mine($paid['id'], $otherCustomer) === null, 'booking history isolated by customer');
    verifyWorkflow(!Booking::confirmPaid($paid['id'], $otherOwner), 'foreign owner cannot confirm payment');
    verifyWorkflow(Booking::confirmPaid($paid['id'], $owner), 'new confirmed booking accepts onsite payment');
    verifyWorkflow(!Booking::confirmPaid($paid['id'], $owner), 'payment cannot be confirmed twice');
    verifyWorkflow((int)Database::q('SELECT COUNT(*) FROM payments WHERE booking_id=?', [$paid['id']])->fetchColumn() === 1, 'payment persisted once');
    $token = Booking::startCancel($paid['id'], $customer);
    $cancelled = Booking::confirmCancel($paid['id'], $customer, $token);
    verifyWorkflow($cancelled['refund_status'] === 'pending' && (int)$cancelled['refund_amount'] === 250002, 'paid advance cancellation requests full refund');
    verifyWorkflow(!Booking::refundDone($paid['id'], $otherOwner), 'foreign owner cannot confirm refund');
    verifyWorkflow(Booking::refundDone($paid['id'], $owner) && !Booking::refundDone($paid['id'], $owner), 'refund completed exactly once');

    $ownerCancelled = workflowBooking($customer, $pitch, 4);
    Booking::confirmPaid($ownerCancelled['id'], $owner);
    verifyWorkflow(Booking::cancelByOwner($ownerCancelled['id'], $otherOwner) === null, 'foreign owner cannot cancel booking');
    verifyWorkflow(Booking::cancelByOwner($ownerCancelled['id'], $owner)['refund_amount'] === 100001, 'owner cancellation refunds paid booking');
    verifyWorkflow(Booking::cancelByOwner($ownerCancelled['id'], $owner) === null, 'owner cancellation not repeated');

    $feeBooking = workflowBooking($customer, $pitch, 5);
    $feeDate = date('Y-m-d', strtotime('+5 days'));
    Database::q("SET timestamp=UNIX_TIMESTAMP(?)", ["$feeDate 08:00:00"]);
    $feeToken = Booking::startCancel($feeBooking['id'], $customer);
    $feeResult = Booking::confirmCancel($feeBooking['id'], $customer, $feeToken);
    verifyWorkflow($feeResult['status'] === 'cancel_fee_pending' && $feeResult['cancellation_fee'] === 70001, 'exact two-hour cancellation fee rounded correctly');
    verifyWorkflow((int)Database::q('SELECT COUNT(*) FROM booking_slots WHERE booking_id=?', [$feeBooking['id']])->fetchColumn() === 1, 'slot stays reserved while cancellation fee pending');
    verifyWorkflow(!Booking::confirmCancellationFee($feeBooking['id'], $otherOwner), 'foreign owner cannot confirm cancellation fee');
    verifyWorkflow(Booking::confirmCancellationFee($feeBooking['id'], $owner), 'cancellation fee confirms cancellation');
    verifyWorkflow(!Booking::confirmCancellationFee($feeBooking['id'], $owner), 'cancellation fee cannot be confirmed twice');
    verifyWorkflow((int)Database::q('SELECT COUNT(*) FROM booking_slots WHERE booking_id=?', [$feeBooking['id']])->fetchColumn() === 0, 'fee settlement releases slot');
    Database::q('SET timestamp=0');

    $free = workflowBooking($customer, $pitch, 6, [10], $voucherData['code']);
    $freeDate = date('Y-m-d', strtotime('+6 days'));
    Database::q('SET timestamp=UNIX_TIMESTAMP(?)', ["$freeDate 09:00:00"]);
    $freeToken = Booking::startCancel($free['id'], $customer);
    verifyWorkflow(Booking::confirmCancel($free['id'], $customer, $freeToken)['status'] === 'cancelled', 'zero-value voucher booking cancels without zero-fee payment dead end');
    Database::q('SET timestamp=0');

    $matchBooking = workflowBooking($customer, $pitch, 7);
    verifyWorkflow(MatchChallenge::create($matchBooking['id'], $customer, 'Workflow team', '0900000004', '', 1), 'create challenge from own booking');
    $challenge = (int)Database::q('SELECT id FROM match_challenges WHERE booking_id=?', [$matchBooking['id']])->fetchColumn();
    verifyWorkflow(!MatchChallenge::request($challenge, $customer, 'Self', '0900000004', ''), 'cannot challenge own team');
    verifyWorkflow(MatchChallenge::request($challenge, $otherCustomer, 'Opponent', '0900000004', ''), 'send opponent request');
    verifyWorkflow(!MatchChallenge::request($challenge, $otherCustomer, 'Opponent', '0900000004', ''), 'duplicate opponent request rejected');
    $request = (int)Database::q("SELECT id FROM match_requests WHERE challenge_id=? AND request_type='opponent'", [$challenge])->fetchColumn();
    verifyWorkflow(!MatchChallenge::decide($request, $otherCustomer, 'accept'), 'only creator decides opponent request');
    verifyWorkflow(MatchChallenge::decide($request, $customer, 'decline'), 'creator declines opponent');
    verifyWorkflow(MatchChallenge::request($challenge, $otherCustomer, 'Opponent', '0900000004', ''), 'declined opponent can request again');
    verifyWorkflow(MatchChallenge::decide($request, $customer, 'accept'), 'accept opponent and mark matched');
    verifyWorkflow(MatchChallenge::request($challenge, $otherCustomer, 'Player', '0900000004', '', true), 'matched team can recruit guest player');
    $playerRequest = (int)Database::q("SELECT id FROM match_requests WHERE challenge_id=? AND request_type='player'", [$challenge])->fetchColumn();
    verifyWorkflow(MatchChallenge::decidePlayerRequest($playerRequest, $customer, 'accept'), 'accept guest player');
    verifyWorkflow(!MatchChallenge::manageGuestPlayers($challenge, $customer, 'open'), 'full team cannot reopen recruitment');
    verifyWorkflow(!MatchChallenge::manageGuestPlayers($challenge, $customer, 'capacity', 0), 'cannot reduce capacity below accepted players');
    verifyWorkflow(MatchChallenge::decidePlayerRequest($playerRequest, $customer, 'remove'), 'creator removes guest player');

    verifyWorkflow(Venue::saveReview($owner, $customer, 5, '<script>test</script>'), 'logged customer review stored as text');
    $review = Venue::reviews($owner, $customer);
    verifyWorkflow($review['count'] === 1 && $review['my_review']['comment'] === '<script>test</script>', 'review data preserved for escaped rendering');
    $reviewId = (int)$review['my_review']['id'];
    verifyWorkflow(Venue::setReviewVisibility($reviewId, false), 'admin moderation hides review');
    verifyWorkflow(Venue::reviews($owner, $customer)['count'] === 0, 'hidden review omitted publicly');
    Venue::saveReview($owner, $customer, 4, 'Updated text');
    verifyWorkflow(Venue::reviews($owner, $customer)['count'] === 0, 'editing hidden review cannot bypass moderation');
    Venue::setReviewVisibility($reviewId, true);
    verifyWorkflow(Venue::reviews($owner, null)['average'] === 4.0, 'restored review updates rating');
    Venue::saveAmenities($owner, ['Parking', 'Parking', 'Water']);
    verifyWorkflow(count(Venue::amenities($owner)) === 2, 'amenities deduplicated');
    for ($i = 0; $i < 8; $i++) {
        verifyWorkflow(Venue::addImage($owner, "uploads/venues/workflow-$i.jpg"), 'gallery image within limit');
    }
    verifyWorkflow(!Venue::addImage($owner, 'uploads/venues/overflow.jpg'), 'ninth gallery image rejected');
    $imageId = (int)Venue::images($owner)[0]['id'];
    verifyWorkflow(Venue::deleteImage($imageId, $otherOwner) === null, 'foreign owner cannot delete venue photo');

    $legacy = workflowBooking($customer, $pitch, 8);
    Database::q("UPDATE bookings SET status='pending',expires_at=NOW()+INTERVAL 1 HOUR WHERE id=?", [$legacy['id']]);
    $legacyRow = Booking::mine($legacy['id'], $customer);
    verifyWorkflow(workflowEvent($legacyRow, $legacy['total'] + 1) === 'amount_mismatch', 'webhook amount mismatch rejected');
    verifyWorkflow(workflowEvent($legacyRow, $legacy['total'], 'out') === 'ignored', 'outgoing webhook ignored');
    verifyWorkflow(workflowEvent($legacyRow, $legacy['total']) === 'paid', 'valid webhook confirms legacy payment');
    $lastEvent = $eventIds[count($eventIds) - 1];
    verifyWorkflow(Booking::confirmFromSepay($lastEvent, $legacy['total'], 'in', date('Y-m-d H:i:s'), 'SAN ' . $legacyRow['code'], 'test') === 'duplicate', 'same bank transaction is idempotent');
    verifyWorkflow(workflowEvent($legacyRow, $legacy['total']) === 'already_paid', 'second transfer cannot duplicate booking payment');
    verifyWorkflow((int)Database::q('SELECT COUNT(*) FROM payments WHERE booking_id=?', [$legacy['id']])->fetchColumn() === 1, 'one payment row after webhook retries');

    echo "$checks workflow checks passed.\n";
} finally {
    Database::q('SET timestamp=0');
    foreach ($eventIds as $id) {
        Database::q('DELETE FROM sepay_webhook_events WHERE transaction_id=?', [$id]);
    }
    foreach ($bookingIds as $id) {
        Database::q('DELETE FROM payments WHERE booking_id=?', [$id]);
        Database::q('DELETE FROM bookings WHERE id=?', [$id]);
    }
    foreach ($voucherIds as $id) {
        Database::q('DELETE FROM vouchers WHERE id=?', [$id]);
    }
    foreach (array_reverse($userIds) as $id) {
        Database::q('DELETE FROM pitches WHERE owner_id=?', [$id]);
        Database::q('DELETE FROM audit_logs WHERE user_id=?', [$id]);
        Database::q('DELETE FROM users WHERE id=?', [$id]);
    }
}
