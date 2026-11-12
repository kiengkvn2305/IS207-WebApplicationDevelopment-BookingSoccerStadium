<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$date = date('Y-m-d', strtotime('+2 days'));
if (($argv[1] ?? '') === 'race') {
    echo json_encode(Booking::create(3, Pitch::bookable(2), $date, [12], 'QA_RACE'));
    exit;
}
$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo "PASS: $label\n";
}
// Run against a freshly imported schema on the isolated instance.
check((int)Database::q('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'fresh schema preserves seed accounts');
foreach (['venue_images','venue_reviews','sepay_webhook_events','vouchers','booking_slots'] as $table) {
    Database::q("SELECT 1 FROM $table LIMIT 1");
}
check(true, 'fresh schema contains all runtime tables');
Database::q("UPDATE owner_profiles SET bank_bin='970436',account_no='123456789',account_name='TEST OWNER' WHERE user_id=2");
$voucher = ['code' => 'QA_ONCE','discount_type' => 'percent','discount_value' => 25,'min_total' => 0,
    'starts_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'ends_at' => date('Y-m-d H:i:s', strtotime('+3 days')), 'usage_limit' => 1];
Voucher::create($voucher, 1);
check(Voucher::quote(' qa_once ', 200000)['discount'] === 50000, 'voucher normalization and percent calculation');
check(isset(Voucher::quote('QA_ONCE', -1)['error']), 'negative subtotal rejected');
check(isset(Voucher::quote('bad code', 200000)['error']), 'invalid voucher format rejected');
try {
    Voucher::quote('QA_ONCE', 200000, true);
    check(false, 'lock requires transaction');
} catch (LogicException $e) {
    check(true, 'lock requires transaction');
}
$voucher['code'] = 'QA_FIXED';
$voucher['discount_type'] = 'fixed';
$voucher['discount_value'] = 500000;
Voucher::create($voucher, 1);
check(Voucher::quote('QA_FIXED', 200000)['total'] === 0, 'fixed discount capped at subtotal');
Database::q("UPDATE vouchers SET discount_value=101,discount_type='percent' WHERE code='QA_FIXED'");
check(isset(Voucher::quote('QA_FIXED', 200000)['error']), 'invalid stored voucher configuration rejected');
$pitch = Pitch::bookable(1);
Database::q('UPDATE pitches SET price=250000 WHERE id=1');
$booking = Booking::create(3, $pitch, $date, [10], 'QA_ONCE');
check($booking['id'] > 0 && $booking['subtotal'] === 250000 && $booking['total'] === 187500, 'locked current price used with voucher');
check(isset(Voucher::quote('QA_ONCE', 250000)['error']), 'last voucher use exhausted');
$voucher['code'] = 'QA_ROLLBACK';
$voucher['discount_type'] = 'percent';
$voucher['discount_value'] = 10;
Voucher::create($voucher, 1);
$duplicate = Booking::create(3, Pitch::bookable(1), $date, [10], 'QA_ROLLBACK');
check($duplicate['id'] === 0, 'overlapping booking rejected');
check((int)Database::q("SELECT usage_count FROM vouchers WHERE code='QA_ROLLBACK'")->fetchColumn() === 0, 'failed booking rolls back voucher use');
check((int)Database::q("SELECT COUNT(*) FROM voucher_redemptions WHERE voucher_id=(SELECT id FROM vouchers WHERE code='QA_ROLLBACK')")->fetchColumn() === 0, 'failed booking leaves no redemption');
$pitch = Pitch::bookable(1);
Pitch::toggle(1, 2);
check(Booking::create(3, $pitch, $date, [11])['id'] === 0, 'pitch disabled after selection cannot be booked');
Pitch::toggle(1, 2);
check(Pitch::setUnavailable(1, 2, $date, 11, 'Maintenance'), 'owner blocks free slot');
check(Booking::create(3, Pitch::bookable(1), $date, [11])['id'] === 0, 'owner blocked slot cannot be booked');
check(!Pitch::setUnavailable(1, 2, $date, 10, 'Maintenance'), 'owner cannot block booked slot');
check(User::updateProfile(3, 'QA Customer', 'khach@sannha.vn', '0900000003', 1), 'profile version update accepted');
check(!User::updateProfile(3, 'Stale Customer', 'khach@sannha.vn', '0900000003', 1), 'stale profile update rejected');
check((int)User::find(3)['profile_version'] === 2, 'updated profile version available');
$token = Booking::startCancel($booking['id'], 3);
check(Booking::confirmCancel($booking['id'], 3, $token)['status'] === 'cancelled', 'advance cancellation succeeds');
check(Booking::confirmCancel($booking['id'], 3, $token) === null, 'cancellation token cannot be replayed');
check(Booking::create(3, Pitch::bookable(1), $date, [10])['id'] > 0, 'cancelled slot can be rebooked');
$voucher['code'] = 'QA_RACE';
Voucher::create($voucher, 1);
$processes = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'race'], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    $processes[] = [$process, $pipes];
}
$successes = 0;
foreach ($processes as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'concurrent worker completed: ' . $error);
    $successes += !empty(json_decode($output, true)['id']) ? 1 : 0;
}
check($successes === 1, 'two concurrent requests produce exactly one booking');
check((int)Database::q("SELECT usage_count FROM vouchers WHERE code='QA_RACE'")->fetchColumn() === 1, 'concurrent requests consume one voucher use');
check((int)Database::q('SELECT COUNT(*) FROM booking_slots WHERE pitch_id=2 AND date=? AND hour=12', [$date])->fetchColumn() === 1, 'database retains one slot reservation');
echo "$checks checks passed.\n";
