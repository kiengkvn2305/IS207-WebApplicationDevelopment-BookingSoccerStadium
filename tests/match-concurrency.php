<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? '') === 'worker') {
    $success = $argv[2] === 'player'
        ? MatchChallenge::decidePlayerRequest((int)$argv[3], 3, 'accept')
        : MatchChallenge::decide((int)$argv[3], 3, 'accept');
    echo $success ? '1' : '0';
    exit;
}

$users = [];
$bookings = [];
try {
    for ($i = 0; $i < 2; $i++) {
        $email = 'race-' . bin2hex(random_bytes(6)) . '@example.test';
        User::create('Race applicant', $email, '0900000005', 'Test@12345', 'customer', '');
        $users[] = (int)User::byEmail($email)['id'];
    }

    foreach (['player', 'opponent'] as $index => $type) {
        $booking = Booking::create(3, Pitch::bookable(2), date('Y-m-d', strtotime('+' . (9 + $index) . ' days')), [10]);
        $bookings[] = $booking['id'];
        MatchChallenge::create($booking['id'], 3, 'Race team', '0900000003', '', 1);
        $challenge = (int)Database::q('SELECT id FROM match_challenges WHERE booking_id=?', [$booking['id']])->fetchColumn();
        $requests = [];
        foreach ($users as $user) {
            MatchChallenge::request($challenge, $user, 'Applicant', '0900000005', '', $type === 'player');
            $requests[] = (int)Database::q('SELECT id FROM match_requests WHERE challenge_id=? AND applicant_id=?', [$challenge, $user])->fetchColumn();
        }

        Database::beginTransaction();
        Database::q('SELECT id FROM match_challenges WHERE id=? FOR UPDATE', [$challenge]);
        $workers = [];
        foreach ($requests as $request) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $type, (string)$request], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        usleep(300000);
        Database::commit();

        $successes = 0;
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException("$type worker failed: $error");
            }
            $successes += trim($output) === '1' ? 1 : 0;
        }
        $accepted = (int)Database::q("SELECT COUNT(*) FROM match_requests WHERE challenge_id=? AND status='accepted'", [$challenge])->fetchColumn();
        if ($successes !== 1 || $accepted !== 1) {
            throw new RuntimeException("$type acceptance race: $successes succeeded, $accepted accepted; expected one");
        }
        echo "PASS: concurrent $type decisions accept exactly one applicant\n";
    }
} finally {
    if (Database::pdo()->inTransaction()) {
        Database::rollBack();
    }
    foreach ($bookings as $id) {
        Database::q('DELETE FROM bookings WHERE id=?', [$id]);
    }
    foreach ($users as $id) {
        Database::q('DELETE FROM audit_logs WHERE user_id=?', [$id]);
        Database::q('DELETE FROM users WHERE id=?', [$id]);
    }
}
