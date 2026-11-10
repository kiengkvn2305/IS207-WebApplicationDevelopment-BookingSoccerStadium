<?php
class User {
    static function find(int $id): ?array {
        return Database::q("SELECT id,name,email,phone,role,status,profile_version,created_at FROM users WHERE id=?", [$id])->fetch() ?: null;
    }
    static function byEmail(string $email): ?array {
        return Database::q("SELECT * FROM users WHERE email=?", [$email])->fetch() ?: null;
    }
    static function passwordOk(int $id, string $pw): bool {
        return password_verify($pw, (string)Database::q("SELECT password_hash FROM users WHERE id=?", [$id])->fetchColumn());
    }
    static function emailTaken(string $email, int $exceptId): bool {
        return (bool)Database::q("SELECT 1 FROM users WHERE email=? AND id<>? LIMIT 1", [$email, $exceptId])->fetchColumn();
    }
    static function updateProfile(int $id, string $name, string $email, string $phone, int $version, ?string $password = null): bool {
        if ($password === null) {
            return Database::q("UPDATE users SET name=?,email=?,phone=?,profile_version=profile_version+1
                WHERE id=? AND profile_version=?", [$name, $email, $phone, $id, $version])->rowCount() > 0;
        }
        return Database::q("UPDATE users SET name=?,email=?,phone=?,password_hash=?,profile_version=profile_version+1
            WHERE id=? AND profile_version=?",
            [$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $id, $version])->rowCount() > 0;
    }
    static function create(string $name, string $email, string $phone, string $pw, string $role, string $venue): bool {
        $pdo = Database::pdo();
        try {
            Database::beginTransaction();
            Database::q("INSERT INTO users(name,email,phone,password_hash,role,status) VALUES(?,?,?,?,?,?)",
                [$name, $email, $phone, password_hash($pw, PASSWORD_DEFAULT), $role, $role === 'owner' ? 'pending' : 'active']);
            $id = (int)$pdo->lastInsertId();
            if ($role === 'owner') Database::q("INSERT INTO owner_profiles(user_id,venue_name) VALUES(?,?)", [$id, mb_substr($venue, 0, 100)]);
            Database::commit(); Audit::log('register', $role, $id);
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) Database::rollBack();
            if ((string)$e->getCode() === '23000') return false;
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) Database::rollBack();
            throw $e;
        }
    }
    static function failedAttempts(string $email, string $ip): int {
        return (int)Database::q("SELECT COUNT(*) FROM login_attempts WHERE created_at > NOW() - INTERVAL 15 MINUTE AND (email=? OR ip=?)", [$email, $ip])->fetchColumn();
    }
    static function logFail(string $email, string $ip): void { Database::q("INSERT INTO login_attempts(email,ip) VALUES(?,?)", [$email, $ip]); }
    static function clearFails(string $email): void { Database::q("DELETE FROM login_attempts WHERE email=?", [$email]); }

    // Admin
    static function all(): array {
        return Database::q("SELECT u.id,u.name,u.email,u.phone,u.role,u.status,o.venue_name FROM users u
            LEFT JOIN owner_profiles o ON o.user_id=u.id ORDER BY (u.status='pending') DESC, u.id DESC LIMIT 100")->fetchAll();
    }
    static function applyAction(string $action, int $id): bool {
        $map = ['approve' => ["active", "role='owner' AND status='pending'"],
                'lock'    => ["locked", "role<>'admin' AND status='active'"],
                'unlock'  => ["active", "role<>'admin' AND status='locked'"]];
        if (!isset($map[$action])) return false;
        return Database::q("UPDATE users SET status=? WHERE id=? AND {$map[$action][1]}", [$map[$action][0], $id])->rowCount() > 0;
    }
    static function stats(): array {
        return Database::q("SELECT (SELECT COUNT(*) FROM users WHERE role='customer') customers,
            (SELECT COUNT(*) FROM users WHERE role='owner' AND status='active') owners,
            (SELECT COUNT(*) FROM users WHERE role='owner' AND status='pending') pending_owners,
            (SELECT COUNT(*) FROM bookings WHERE status='paid') paid,
            (SELECT COALESCE(SUM(total),0) FROM bookings WHERE status='paid') revenue")->fetch();
    }
}
