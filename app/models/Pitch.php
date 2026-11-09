<?php
class Pitch {
    // Chỉ sân đang mở, của chủ sân đã duyệt và đã khai báo tài khoản nhận tiền
    private const BOOKABLE = "FROM pitches p JOIN users w ON w.id=p.owner_id AND w.status='active'
        JOIN owner_profiles o ON o.user_id=p.owner_id AND o.account_no IS NOT NULL AND o.account_no<>'' WHERE p.active=1";
    static function listBookable(): array {
        return Database::q("SELECT p.*, o.venue_name, o.address, w.name owner_name, w.phone owner_phone "
            . self::BOOKABLE . " ORDER BY p.owner_id, p.id")->fetchAll();
    }
    static function bookable(int $id): ?array {
        return Database::q("SELECT p.* " . self::BOOKABLE . " AND p.id=?", [$id])->fetch() ?: null;
    }
    static function price(array $p, int $hour): int { return $hour >= PEAK_FROM ? (int)$p['peak_price'] : (int)$p['price']; }
    static function byOwner(int $uid): array { return Database::q("SELECT * FROM pitches WHERE owner_id=? ORDER BY id", [$uid])->fetchAll(); }
    static function unavailableMap(string $date): array {
        $m = [];
        foreach (Database::q("SELECT pitch_id,hour FROM unavailable_slots WHERE date=?", [$date]) as $r) {
            $m[(int)$r['pitch_id']][(int)$r['hour']] = true;
        }
        return $m;
    }
    static function setAvailable(int $id, int $uid, string $date, int $hour): bool {
        return Database::q("DELETE u FROM unavailable_slots u JOIN pitches p ON p.id=u.pitch_id
            WHERE u.pitch_id=? AND p.owner_id=? AND u.date=? AND u.hour=?", [$id, $uid, $date, $hour])->rowCount() > 0;
    }
    static function add(int $uid, string $name, string $type, int $price, int $peak): void {
        Database::q("INSERT INTO pitches(owner_id,name,type,price,peak_price) VALUES(?,?,?,?,?)", [$uid, $name, $type, $price, $peak]);
    }
    static function toggle(int $id, int $uid): bool {
        return Database::q("UPDATE pitches SET active=1-active WHERE id=? AND owner_id=?", [$id, $uid])->rowCount() > 0;
    }
    static function setUnavailable(int $id, int $uid, string $date, int $hour): bool {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $pitch = Database::q("SELECT id FROM pitches WHERE id=? AND owner_id=? FOR UPDATE", [$id, $uid])->fetch();
        if (!$pitch) { $pdo->rollBack(); return false; }
        if (Database::q("SELECT 1 FROM booking_slots WHERE pitch_id=? AND date=? AND hour=?", [$id, $date, $hour])->fetchColumn()) {
            $pdo->rollBack(); return false;
        }
        Database::q("INSERT IGNORE INTO unavailable_slots(pitch_id,date,hour) VALUES(?,?,?)", [$id, $date, $hour]);
        $pdo->commit();
        return true;
    }
    static function saveImage(int $id, int $uid, string $path): bool {
        return Database::q("UPDATE pitches SET image_path=? WHERE id=? AND owner_id=?", [$path, $id, $uid])->rowCount() > 0;
    }
}
