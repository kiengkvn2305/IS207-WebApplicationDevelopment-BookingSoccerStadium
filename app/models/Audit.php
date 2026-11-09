<?php
class Audit {
    static function log(string $action, string $detail = '', ?int $uid = null): void {
        Database::q("INSERT INTO audit_logs(user_id,action,detail,ip) VALUES(?,?,?,?)",
            [$uid ?? ($_SESSION['uid'] ?? null), $action, mb_substr($detail, 0, 255), Auth::ip()]);
    }
    static function recent(int $n = 30): array {
        return Database::q("SELECT l.created_at,l.action,l.detail,l.ip,u.email FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT " . (int)$n)->fetchAll();
    }
}
