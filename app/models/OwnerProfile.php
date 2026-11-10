<?php
class OwnerProfile {
    static function get(int $uid): array {
        return Database::q("SELECT venue_name,address,latitude,longitude,bank_bin,account_no,account_name,profile_version FROM owner_profiles WHERE user_id=?", [$uid])->fetch() ?: [];
    }
    static function update(int $uid, string $venue, string $addr, ?float $latitude, ?float $longitude, string $bin, string $no, string $name, int $version): bool {
        return Database::q("UPDATE owner_profiles SET venue_name=?,address=?,latitude=?,longitude=?,bank_bin=?,account_no=?,account_name=?,
                profile_version=profile_version+1 WHERE user_id=? AND profile_version=?",
            [mb_substr($venue, 0, 100), mb_substr($addr, 0, 200), $latitude, $longitude, $bin, $no, $name, $uid, $version])->rowCount() > 0;
    }
}
