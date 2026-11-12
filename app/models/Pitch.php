<?php

class Pitch
{
    // Chỉ sân đang mở, của chủ sân đã duyệt và đã khai báo tài khoản nhận tiền
    private const BOOKABLE = "FROM pitches p JOIN users w ON w.id=p.owner_id AND w.status='active'
        JOIN owner_profiles o ON o.user_id=p.owner_id AND o.account_no IS NOT NULL AND o.account_no<>'' WHERE p.active=1";
    public static function listBookable(): array
    {
        return Database::q("SELECT p.*, o.venue_name, o.address, o.latitude, o.longitude, w.name owner_name, w.phone owner_phone "
            . self::BOOKABLE . " ORDER BY p.owner_id, p.id")->fetchAll();
    }
    public static function bookable(int $id, bool $lock = false): ?array
    {
        return Database::q("SELECT p.* " . self::BOOKABLE . " AND p.id=?" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch() ?: null;
    }
    public static function price(array $p, int $hour): int
    {
        return $hour >= PEAK_FROM ? (int)$p['peak_price'] : (int)$p['price'];
    }
    public static function byOwner(int $uid): array
    {
        return Database::q("SELECT * FROM pitches WHERE owner_id=? ORDER BY id", [$uid])->fetchAll();
    }
    public static function unavailableMap(string $date): array
    {
        $m = [];
        foreach (Database::q("SELECT pitch_id,hour,reason FROM unavailable_slots WHERE date=?", [$date]) as $r) {
            $m[(int)$r['pitch_id']][(int)$r['hour']] = (string)($r['reason'] ?? '');
        }
        return $m;
    }
    public static function setAvailable(int $id, int $uid, string $date, int $hour): bool
    {
        return Database::q("DELETE u FROM unavailable_slots u JOIN pitches p ON p.id=u.pitch_id
            WHERE u.pitch_id=? AND p.owner_id=? AND u.date=? AND u.hour=?", [$id, $uid, $date, $hour])->rowCount() > 0;
    }
    public static function add(int $uid, string $name, string $type, int $price, int $peak): void
    {
        Database::q("INSERT INTO pitches(owner_id,name,type,price,peak_price) VALUES(?,?,?,?,?)", [$uid, $name, $type, $price, $peak]);
    }
    public static function toggle(int $id, int $uid): bool
    {
        return Database::q("UPDATE pitches SET active=1-active WHERE id=? AND owner_id=?", [$id, $uid])->rowCount() > 0;
    }
    public static function setUnavailable(int $id, int $uid, string $date, int $hour, string $reason): bool
    {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $pitch = Database::q("SELECT id FROM pitches WHERE id=? AND owner_id=? FOR UPDATE", [$id, $uid])->fetch();
            if (!$pitch) {
                Database::rollBack();
                return false;
            }
            if (Database::q("SELECT 1 FROM booking_slots WHERE pitch_id=? AND date=? AND hour=?", [$id, $date, $hour])->fetchColumn()) {
                Database::rollBack();
                return false;
            }
            Database::q("INSERT IGNORE INTO unavailable_slots(pitch_id,date,hour,reason) VALUES(?,?,?,?)", [$id, $date, $hour, $reason]);
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    public static function saveImage(int $id, int $uid, string $path): bool
    {
        return Database::q("UPDATE pitches SET image_path=? WHERE id=? AND owner_id=?", [$path, $id, $uid])->rowCount() > 0;
    }
}
