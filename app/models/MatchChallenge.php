<?php

class MatchChallenge
{
    // One atomic statement closes recruitment and pending invitations at kickoff.
    // Keep accepted opponents/players and matched status as historical records.
    public static function closeExpired(): void
    {
        Database::q("UPDATE match_challenges c JOIN bookings b ON b.id=c.booking_id
            LEFT JOIN match_requests r ON r.challenge_id=c.id AND r.status='pending'
            SET c.status=IF(c.status='open','cancelled',c.status),c.join_open=0,r.status='declined'
            WHERE TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))<=NOW()
                AND (c.status='open' OR c.join_open<>0 OR r.id IS NOT NULL)");
    }

    public static function listOpen(?int $viewerId): array
    {
        self::closeExpired();
        $viewerJoin = $viewerId ? "LEFT JOIN match_requests mine ON mine.challenge_id=c.id AND mine.applicant_id=" . (int)$viewerId . " AND mine.request_type='opponent'
            LEFT JOIN match_requests player_mine ON player_mine.challenge_id=c.id AND player_mine.applicant_id=" . (int)$viewerId . " AND player_mine.request_type='player'" : "";
        $selectRequest = $viewerId ? "mine.status request_status,player_mine.status player_request_status" : "NULL request_status,NULL player_request_status";
        $hideOwnChallenges = $viewerId === null ? "" : "AND c.creator_id<>?";
        $params = $viewerId === null ? [] : [$viewerId];
        return Database::q("SELECT c.id,c.creator_id,c.team_name,c.contact_phone,c.description,c.created_at,c.status,
            b.date,b.start_hour,b.end_hour,p.name pitch,p.type pitch_type,o.venue_name,o.address,
            $selectRequest,(SELECT COUNT(*) FROM match_requests mr WHERE mr.challenge_id=c.id
                AND mr.request_type='opponent' AND mr.status='pending') request_count,
            c.players_needed,c.join_open,
            (SELECT COUNT(*) FROM match_requests mr WHERE mr.challenge_id=c.id
                AND mr.request_type='player' AND mr.status='accepted') accepted_players,
            GREATEST(0,c.players_needed-(SELECT COUNT(*) FROM match_requests mr WHERE mr.challenge_id=c.id
                AND mr.request_type='player' AND mr.status='accepted')) players_needed_remaining
            FROM match_challenges c JOIN bookings b ON b.id=c.booking_id
            JOIN pitches p ON p.id=b.pitch_id JOIN owner_profiles o ON o.user_id=p.owner_id
            $viewerJoin
            WHERE (c.status='open' OR (c.status='matched' AND c.join_open=1
                AND c.players_needed>(SELECT COUNT(*) FROM match_requests mr WHERE mr.challenge_id=c.id
                    AND mr.request_type='player' AND mr.status='accepted')))
            AND b.status IN ('confirmed','paid')
            $hideOwnChallenges
            AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW()
            ORDER BY b.date,b.start_hour,c.created_at DESC LIMIT 50", $params)->fetchAll();
    }

    public static function ownerAlerts(int $ownerId): array
    {
        self::closeExpired();
        return Database::q("SELECT c.id,c.team_name,c.contact_phone,c.description,b.date,b.start_hour,b.end_hour,
                p.name pitch,p.type pitch_type,o.venue_name,o.address,
                CEIL(TIMESTAMPDIFF(SECOND,NOW(),TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0)))/60) minutes_remaining
            FROM match_challenges c JOIN bookings b ON b.id=c.booking_id
            JOIN pitches p ON p.id=b.pitch_id JOIN owner_profiles o ON o.user_id=p.owner_id
            WHERE p.owner_id=? AND c.status='open' AND b.status IN ('confirmed','paid')
                AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW()
                AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))<=NOW()+INTERVAL 2 HOUR
            ORDER BY b.date,b.start_hour,c.created_at", [$ownerId])->fetchAll();
    }

    public static function myChallenges(int $userId): array
    {
        return Database::q("SELECT c.id,c.booking_id,c.team_name,c.contact_phone,c.description,c.status,c.created_at,
            c.players_needed,c.join_open,
            (SELECT COUNT(*) FROM match_requests mr WHERE mr.challenge_id=c.id
                AND mr.request_type='player' AND mr.status='accepted') accepted_players,
            b.date,b.start_hour,b.end_hour,p.name pitch,p.type pitch_type,o.venue_name,o.address,
            (TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))<=NOW()) is_past,
            (b.status IN ('confirmed','paid') AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW()) can_manage
            FROM match_challenges c JOIN bookings b ON b.id=c.booking_id
            JOIN pitches p ON p.id=b.pitch_id JOIN owner_profiles o ON o.user_id=p.owner_id
            WHERE c.creator_id=? ORDER BY c.created_at DESC LIMIT 30", [$userId])->fetchAll();
    }

    public static function requestsForCreator(int $userId): array
    {
        return Database::q("SELECT r.id request_id,r.challenge_id,r.applicant_id,r.team_name,r.contact_phone,
            r.message,r.status,r.created_at,c.team_name creator_team,c.status challenge_status
            FROM match_requests r JOIN match_challenges c ON c.id=r.challenge_id
            WHERE c.creator_id=? AND c.status IN ('open','matched') AND r.request_type='opponent'
            ORDER BY r.created_at DESC LIMIT 100", [$userId])->fetchAll();
    }

    public static function playerRequestsForCreator(int $userId): array
    {
        return Database::q("SELECT r.id request_id,r.challenge_id,r.applicant_id,r.team_name,r.contact_phone,
                r.message,r.status,r.created_at
            FROM match_requests r JOIN match_challenges c ON c.id=r.challenge_id
            WHERE c.creator_id=? AND c.status IN ('open','matched') AND r.request_type='player'
            ORDER BY r.created_at DESC LIMIT 100", [$userId])->fetchAll();
    }

    public static function myRequests(int $userId): array
    {
        return Database::q("SELECT r.id request_id,r.status request_status,r.created_at,
            r.team_name,r.contact_phone,r.message,c.id challenge_id,c.status challenge_status,c.team_name opponent_team,
            c.contact_phone opponent_phone,b.date,b.start_hour,b.end_hour,p.name pitch,o.venue_name,o.address
            FROM match_requests r JOIN match_challenges c ON c.id=r.challenge_id
            JOIN bookings b ON b.id=c.booking_id JOIN pitches p ON p.id=b.pitch_id
            JOIN owner_profiles o ON o.user_id=p.owner_id
            WHERE r.applicant_id=? AND r.request_type='opponent' ORDER BY r.created_at DESC LIMIT 30", [$userId])->fetchAll();
    }

    public static function myPlayerRequests(int $userId): array
    {
        return Database::q("SELECT r.id request_id,r.status request_status,r.created_at,r.team_name,r.contact_phone,r.message,
                c.id challenge_id,c.team_name host_team,c.contact_phone host_phone,c.players_needed,c.join_open,
                (SELECT COUNT(*) FROM match_requests accepted WHERE accepted.challenge_id=c.id
                    AND accepted.request_type='player' AND accepted.status='accepted') accepted_players,
                b.date,b.start_hour,b.end_hour,p.name pitch,o.venue_name,o.address
            FROM match_requests r JOIN match_challenges c ON c.id=r.challenge_id
            JOIN bookings b ON b.id=c.booking_id JOIN pitches p ON p.id=b.pitch_id
            JOIN owner_profiles o ON o.user_id=p.owner_id
            WHERE r.applicant_id=? AND r.request_type='player' ORDER BY r.created_at DESC LIMIT 30", [$userId])->fetchAll();
    }

    public static function create(int $bookingId, int $userId, string $teamName, string $phone, string $description, int $playersNeeded): bool
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $booking = Database::q("SELECT id FROM bookings
                WHERE id=? AND user_id=? AND status IN ('confirmed','paid')
                AND TIMESTAMP(date,MAKETIME(start_hour,0,0))>NOW() FOR UPDATE", [$bookingId, $userId])->fetchColumn();
            if (!$booking) {
                Database::rollBack();
                return false;
            }
            $existing = Database::q("SELECT id,status FROM match_challenges WHERE booking_id=? FOR UPDATE", [$bookingId])->fetch();
            if ($existing) {
                if ($existing['status'] !== 'cancelled') {
                    Database::rollBack();
                    return false;
                }
                Database::q("UPDATE match_requests SET status='declined'
                    WHERE challenge_id=? AND status IN ('pending','accepted')", [$existing['id']]);
                Database::q(
                    "UPDATE match_challenges SET creator_id=?,team_name=?,contact_phone=?,description=?,
                    players_needed=?,join_open=?,status='open' WHERE id=?",
                    [$userId, $teamName, $phone, $description, $playersNeeded, $playersNeeded > 0 ? 1 : 0, $existing['id']]
                );
            } else {
                Database::q("INSERT INTO match_challenges(booking_id,creator_id,team_name,contact_phone,description,players_needed,join_open)
                    VALUES(?,?,?,?,?,?,?)", [$bookingId, $userId, $teamName, $phone, $description, $playersNeeded, $playersNeeded > 0 ? 1 : 0]);
            }
            Database::commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                return false;
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public static function request(int $challengeId, int $userId, string $teamName, string $phone, string $message, bool $guestPlayer = false): bool
    {
        self::closeExpired();
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $challengeStatus = $guestPlayer ? "c.status IN ('open','matched')" : "c.status='open'";
            $challenge = Database::q("SELECT c.id,c.creator_id,c.join_open,c.players_needed
                FROM match_challenges c
                JOIN bookings b ON b.id=c.booking_id
                WHERE c.id=? AND $challengeStatus AND b.status IN ('confirmed','paid')
                AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW() FOR UPDATE", [$challengeId])->fetch();
            if ($challenge) {
                $challenge['accepted_players'] = self::acceptedPlayerCount((int)$challenge['id']);
            }
            if (!$challenge || (int)$challenge['creator_id'] === $userId
                || ($guestPlayer && (!(int)$challenge['join_open']
                    || (int)$challenge['accepted_players'] >= (int)$challenge['players_needed']))) {
                Database::rollBack();
                return false;
            }
            $requestType = $guestPlayer ? 'player' : 'opponent';
            $existing = Database::q(
                "SELECT id,status FROM match_requests
                WHERE challenge_id=? AND applicant_id=? AND request_type=? FOR UPDATE",
                [$challengeId, $userId, $requestType]
            )->fetch();
            if ($existing) {
                if ($existing['status'] !== 'declined') {
                    Database::rollBack();
                    return false;
                }
                Database::q(
                    "UPDATE match_requests SET request_type=?,team_name=?,contact_phone=?,message=?,
                    status='pending',created_at=NOW() WHERE id=?",
                    [$requestType, $teamName, $phone, $message, $existing['id']]
                );
            } else {
                Database::q("INSERT INTO match_requests(challenge_id,applicant_id,request_type,team_name,contact_phone,message)
                    VALUES(?,?,?,?,?,?)", [$challengeId, $userId, $requestType, $teamName, $phone, $message]);
            }
            Database::commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                return false;
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public static function decide(int $requestId, int $userId, string $action): bool
    {
        self::closeExpired();
        $challengeId = self::requestChallengeId($requestId, 'opponent');
        if ($challengeId === null) {
            return false;
        }
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            if (!self::lockActiveChallenge($challengeId, $userId)) {
                Database::rollBack();
                return false;
            }
            $request = Database::q("SELECT r.id,r.challenge_id,r.status,c.status challenge_status
                FROM match_requests r JOIN match_challenges c ON c.id=r.challenge_id
                JOIN bookings b ON b.id=c.booking_id
                WHERE r.id=? AND c.creator_id=? AND r.request_type='opponent'
                    AND b.status IN ('confirmed','paid')
                    AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW() FOR UPDATE", [$requestId, $userId])->fetch();
            if (!$request || $request['challenge_status'] !== 'open' || $request['status'] !== 'pending') {
                Database::rollBack();
                return false;
            }
            if ($action === 'accept') {
                Database::q("UPDATE match_requests SET status='declined'
                    WHERE challenge_id=? AND id<>? AND request_type='opponent' AND status='pending'", [$request['challenge_id'], $requestId]);
                Database::q("UPDATE match_requests SET status='accepted' WHERE id=?", [$requestId]);
                Database::q("UPDATE match_challenges SET status='matched' WHERE id=?", [$request['challenge_id']]);
            } else {
                Database::q("UPDATE match_requests SET status='declined' WHERE id=?", [$requestId]);
            }
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public static function decidePlayerRequest(int $requestId, int $userId, string $action): bool
    {
        self::closeExpired();
        $challengeId = self::requestChallengeId($requestId, 'player');
        if ($challengeId === null) {
            return false;
        }
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            if (!self::lockActiveChallenge($challengeId, $userId)) {
                Database::rollBack();
                return false;
            }
            $request = Database::q("SELECT r.id,r.challenge_id,r.status,c.status challenge_status,
                    c.players_needed
                FROM match_requests r JOIN match_challenges c ON c.id=r.challenge_id
                JOIN bookings b ON b.id=c.booking_id
                WHERE r.id=? AND c.creator_id=? AND r.request_type='player'
                    AND b.status IN ('confirmed','paid')
                    AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW() FOR UPDATE", [$requestId, $userId])->fetch();
            if (!$request || !in_array($request['challenge_status'], ['open','matched'], true)) {
                Database::rollBack();
                return false;
            }
            $request['accepted_players'] = self::acceptedPlayerCount($challengeId);
            if ($action === 'accept' && $request['status'] === 'pending'
                && (int)$request['accepted_players'] < (int)$request['players_needed']) {
                Database::q("UPDATE match_requests SET status='accepted' WHERE id=?", [$requestId]);
                if ((int)$request['accepted_players'] + 1 >= (int)$request['players_needed']) {
                    Database::q("UPDATE match_challenges SET join_open=0 WHERE id=?", [$request['challenge_id']]);
                }
            } elseif ($action === 'decline' && $request['status'] === 'pending') {
                Database::q("UPDATE match_requests SET status='declined' WHERE id=?", [$requestId]);
            } elseif ($action === 'remove' && $request['status'] === 'accepted') {
                Database::q("UPDATE match_requests SET status='declined' WHERE id=?", [$requestId]);
            } else {
                Database::rollBack();
                return false;
            }
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public static function manageGuestPlayers(int $challengeId, int $userId, string $action, ?int $playersNeeded = null): bool
    {
        self::closeExpired();
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $challenge = Database::q(
                "SELECT c.id,c.players_needed,c.join_open
                FROM match_challenges c JOIN bookings b ON b.id=c.booking_id
                WHERE c.id=? AND c.creator_id=? AND c.status IN ('open','matched')
                    AND b.status IN ('confirmed','paid')
                    AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW() FOR UPDATE",
                [$challengeId, $userId]
            )->fetch();
            if (!$challenge) {
                Database::rollBack();
                return false;
            }
            $challenge['accepted_players'] = self::acceptedPlayerCount($challengeId);
            if ($action === 'capacity') {
                if ($playersNeeded === null || $playersNeeded < 0 || $playersNeeded > 30
                    || $playersNeeded < (int)$challenge['accepted_players']) {
                    Database::rollBack();
                    return false;
                }
                Database::q(
                    "UPDATE match_challenges SET players_needed=?,
                    join_open=IF(? > ?,join_open,0) WHERE id=?",
                    [$playersNeeded, $playersNeeded, (int)$challenge['accepted_players'], $challengeId]
                );
            } elseif ($action === 'close') {
                Database::q("UPDATE match_challenges SET join_open=0 WHERE id=?", [$challengeId]);
            } elseif ($action === 'open') {
                if ((int)$challenge['accepted_players'] >= (int)$challenge['players_needed']) {
                    Database::rollBack();
                    return false;
                }
                Database::q("UPDATE match_challenges SET join_open=1 WHERE id=?", [$challengeId]);
            } else {
                Database::rollBack();
                return false;
            }
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private static function requestChallengeId(int $requestId, string $type): ?int
    {
        // Resolve before beginning the transaction so no stale snapshot precedes the lock.
        $id = Database::q('SELECT challenge_id FROM match_requests WHERE id=? AND request_type=?', [$requestId, $type])->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private static function lockActiveChallenge(int $challengeId, int $creatorId): bool
    {
        // Always lock the shared challenge before an individual request.
        // Competing approvals must not each hold a request while waiting for one another.
        return (bool)Database::q("SELECT c.id FROM match_challenges c
            JOIN bookings b ON b.id=c.booking_id
            WHERE c.id=? AND c.creator_id=? AND c.status IN ('open','matched')
                AND b.status IN ('confirmed','paid')
                AND TIMESTAMP(b.date,MAKETIME(b.start_hour,0,0))>NOW() FOR UPDATE", [$challengeId, $creatorId])->fetchColumn();
    }

    private static function acceptedPlayerCount(int $challengeId): int
    {
        // Read after acquiring the challenge lock to see the preceding approval's commit.
        return (int)Database::q("SELECT COUNT(*) FROM match_requests
            WHERE challenge_id=? AND request_type='player' AND status='accepted'", [$challengeId])->fetchColumn();
    }

    public static function cancel(int $challengeId, int $userId): bool
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $challenge = Database::q("SELECT id FROM match_challenges
                WHERE id=? AND creator_id=? AND status='open' FOR UPDATE", [$challengeId, $userId])->fetch();
            if (!$challenge) {
                Database::rollBack();
                return false;
            }
            Database::q("UPDATE match_challenges SET status='cancelled' WHERE id=?", [$challengeId]);
            Notification::notifyChallengeApplicants($challengeId, 'Kèo giao hữu đã đóng', 'Đội tạo kèo đã đóng kèo này.');
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
}
