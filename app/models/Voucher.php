<?php
class Voucher {
    static function quote(string $code, int $subtotal, bool $lock = false): array {
        $code = strtoupper(trim($code));
        if ($code === '') return ['error' => 'Nhập mã giảm giá.'];
        $suffix = $lock ? ' FOR UPDATE' : '';
        $voucher = Database::q("SELECT * FROM vouchers WHERE code=?$suffix", [$code])->fetch();
        if (!$voucher || !(int)$voucher['active']) return ['error' => 'Mã giảm giá không tồn tại hoặc đã bị tắt.'];
        if (strtotime($voucher['starts_at']) > time()) return ['error' => 'Mã giảm giá chưa đến thời gian áp dụng.'];
        if (strtotime($voucher['ends_at']) < time()) return ['error' => 'Mã giảm giá đã hết hạn.'];
        if ($voucher['usage_limit'] !== null && (int)$voucher['usage_count'] >= (int)$voucher['usage_limit']) {
            return ['error' => 'Mã giảm giá đã hết lượt sử dụng.'];
        }
        if ($subtotal < (int)$voucher['min_total']) return ['error' => 'Đơn chưa đạt giá trị tối thiểu của mã giảm giá.'];
        $discount = $voucher['discount_type'] === 'percent'
            ? (int)floor($subtotal * (int)$voucher['discount_value'] / 100)
            : (int)$voucher['discount_value'];
        $discount = min($subtotal, $discount);
        return [
            'id' => (int)$voucher['id'],
            'code' => $voucher['code'],
            'discount_type' => $voucher['discount_type'],
            'discount_value' => (int)$voucher['discount_value'],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $subtotal - $discount,
        ];
    }

    static function all(): array {
        return Database::q("SELECT v.*,u.name creator_name FROM vouchers v
            JOIN users u ON u.id=v.created_by ORDER BY v.id DESC LIMIT 100")->fetchAll();
    }

    static function create(array $data, int $adminId): int {
        Database::q("INSERT INTO vouchers(code,discount_type,discount_value,min_total,starts_at,ends_at,usage_limit,created_by)
            VALUES(?,?,?,?,?,?,?,?)", [
            $data['code'], $data['discount_type'], $data['discount_value'], $data['min_total'],
            $data['starts_at'], $data['ends_at'], $data['usage_limit'], $adminId
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    static function setActive(int $id, bool $active): bool {
        return Database::q("UPDATE vouchers SET active=? WHERE id=?", [$active ? 1 : 0, $id])->rowCount() > 0;
    }
}