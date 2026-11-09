<?php
class OwnerProfile {
    static function get(int $uid): array {
        return Database::q("SELECT venue_name,address,bank_bin,account_no,account_name FROM owner_profiles WHERE user_id=?", [$uid])->fetch() ?: [];
    }
    static function update(int $uid, string $venue, string $addr, string $bin, string $no, string $name): void {
        Database::q("UPDATE owner_profiles SET venue_name=?,address=?,bank_bin=?,account_no=?,account_name=? WHERE user_id=?",
            [mb_substr($venue, 0, 100), mb_substr($addr, 0, 200), $bin, $no, $name, $uid]);
    }
}
