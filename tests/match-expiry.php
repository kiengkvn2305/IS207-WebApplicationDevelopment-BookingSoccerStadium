<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$checks = 0;
$bookings = [];
$applicants = [];
function verifyMatch(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo "PASS: $message\n";
}
function challengeFixture(int $hour, string $status = 'open'): array
{
    global $bookings;
    Database::q(
        "INSERT INTO bookings(code,user_id,pitch_id,date,start_hour,end_hour,total,status,expires_at)
        VALUES(?,3,3,'2030-01-02',?,?,350000,'confirmed','2030-01-03 00:00:00')",
        [strtoupper(bin2hex(random_bytes(4))), $hour, $hour + 1]
    );
    $bookingId = (int)Database::pdo()->lastInsertId();
    $bookings[] = $bookingId;
    Database::q("INSERT INTO match_challenges(booking_id,creator_id,team_name,contact_phone,players_needed,join_open,status)
        VALUES(?,3,'Expiry test','0900000003',4,1,?)", [$bookingId, $status]);
    return [$bookingId, (int)Database::pdo()->lastInsertId()];
}
function invitationFixture(int $challenge, int $applicant, string $type, string $status = 'pending'): int
{
    Database::q("INSERT INTO match_requests(challenge_id,applicant_id,request_type,team_name,contact_phone,status)
        VALUES(?,?,?,'Test applicant','0900000004',?)", [$challenge, $applicant, $type, $status]);
    return (int)Database::pdo()->lastInsertId();
}
try {
    // Freeze only this DB connection's clock. No sleeps or real bookings are involved.
    Database::q("SET timestamp=UNIX_TIMESTAMP('2030-01-02 10:00:00')");
    for ($i = 0; $i < 3; $i++) {
        User::create('Expiry applicant', 'expiry-' . bin2hex(random_bytes(6)) . '@example.test', '0900000004', 'Test@12345', 'customer', '');
        $applicants[] = (int)Database::q('SELECT id FROM users ORDER BY id DESC LIMIT 1')->fetchColumn();
    }
    [$pastBooking, $past] = challengeFixture(9);
    $opponent = invitationFixture($past, $applicants[0], 'opponent');
    $player = invitationFixture($past, $applicants[1], 'player');
    verifyMatch(!MatchChallenge::decide($opponent, 3, 'accept'), 'stale opponent acceptance denied without loading list first');
    $closed = Database::q('SELECT status,join_open FROM match_challenges WHERE id=?', [$past])->fetch();
    verifyMatch($closed['status'] === 'cancelled' && (int)$closed['join_open'] === 0, 'past open challenge automatically closed in database');
    verifyMatch((int)Database::q("SELECT COUNT(*) FROM match_requests WHERE challenge_id=? AND status='pending'", [$past])->fetchColumn() === 0, 'pending opponent and player invitations closed');
    verifyMatch(!MatchChallenge::request($past, $applicants[2], 'Test', '0900000004', ''), 'past challenge rejects new opponent');
    verifyMatch(!MatchChallenge::request($past, $applicants[2], 'Test', '0900000004', '', true), 'past challenge rejects new player');
    verifyMatch(!MatchChallenge::decidePlayerRequest($player, 3, 'accept'), 'past pending player cannot be accepted');
    verifyMatch(!MatchChallenge::create($pastBooking, 3, 'Test', '0900000004', '', 2), 'expired challenge cannot be reposted');

    [$boundaryBooking, $boundary] = challengeFixture(10, 'matched');
    $accepted = invitationFixture($boundary, $applicants[0], 'opponent', 'accepted');
    $acceptedPlayer = invitationFixture($boundary, $applicants[1], 'player', 'accepted');
    $pendingPlayer = invitationFixture($boundary, $applicants[2], 'player');
    verifyMatch(!MatchChallenge::decidePlayerRequest($pendingPlayer, 3, 'accept'), 'player acceptance denied at exact kickoff');
    $matched = Database::q('SELECT status,join_open FROM match_challenges WHERE id=?', [$boundary])->fetch();
    verifyMatch($matched['status'] === 'matched' && (int)$matched['join_open'] === 0, 'matched history retained but recruitment closed at kickoff');
    verifyMatch((int)Database::q("SELECT COUNT(*) FROM match_requests WHERE challenge_id=? AND status='accepted'", [$boundary])->fetchColumn() === 2, 'accepted opponent and player history retained');
    verifyMatch(!MatchChallenge::manageGuestPlayers($boundary, 3, 'open'), 'cannot reopen player recruitment after kickoff');
    verifyMatch(!MatchChallenge::manageGuestPlayers($boundary, 3, 'capacity', 5), 'cannot edit recruitment capacity after kickoff');
    verifyMatch(!MatchChallenge::decidePlayerRequest($acceptedPlayer, 3, 'remove'), 'historical accepted player cannot be edited after kickoff');
    verifyMatch(Database::q('SELECT status FROM bookings WHERE id=?', [$boundaryBooking])->fetchColumn() === 'confirmed', 'closing recruitment preserves booking status');

    [$futureBooking, $future] = challengeFixture(11);
    verifyMatch(MatchChallenge::request($future, $applicants[0], 'Test', '0900000004', ''), 'future challenge accepts opponent request');
    $futureOpponent = (int)Database::q("SELECT id FROM match_requests WHERE challenge_id=? AND request_type='opponent'", [$future])->fetchColumn();
    verifyMatch(MatchChallenge::decide($futureOpponent, 3, 'accept'), 'future opponent can be accepted');
    verifyMatch(MatchChallenge::request($future, $applicants[1], 'Test', '0900000004', '', true), 'matched future challenge accepts player request');
    $futurePlayer = (int)Database::q("SELECT id FROM match_requests WHERE challenge_id=? AND request_type='player'", [$future])->fetchColumn();
    verifyMatch(MatchChallenge::decidePlayerRequest($futurePlayer, 3, 'accept'), 'future player can be accepted');
    verifyMatch(MatchChallenge::manageGuestPlayers($future, 3, 'open'), 'future recruitment can be opened');
    $visibleIds = array_column(MatchChallenge::listOpen(null), 'id');
    verifyMatch(in_array($future, array_map('intval', $visibleIds), true) && !in_array($past, array_map('intval', $visibleIds), true), 'public list retains future and hides past challenges');
    Database::q("SET timestamp=UNIX_TIMESTAMP('2030-01-02 11:00:00')");
    MatchChallenge::listOpen(null);
    $mine = array_column(MatchChallenge::myChallenges(3), null, 'id');
    verifyMatch((int)$mine[$future]['is_past'] === 1 && (int)$mine[$future]['can_manage'] === 0, 'owner view marks kickoff as closed and non-editable');
    MatchChallenge::closeExpired();
    verifyMatch((int)Database::q("SELECT COUNT(*) FROM match_requests WHERE challenge_id=? AND status='accepted'", [$future])->fetchColumn() === 2, 'repeated expiration preserves accepted history');
    echo "$checks match expiry checks passed.\n";
} finally {
    foreach ($bookings as $id) {
        Database::q('DELETE FROM bookings WHERE id=?', [$id]);
    }
    foreach ($applicants as $id) {
        Database::q('DELETE FROM users WHERE id=?', [$id]);
    }
    Database::q('SET timestamp=0');
}
