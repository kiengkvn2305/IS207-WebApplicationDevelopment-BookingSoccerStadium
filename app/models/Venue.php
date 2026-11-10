<?php
class Venue {
    const AMENITIES = [
        'parking' => 'Bãi đỗ xe',
        'toilet' => 'Nhà vệ sinh',
        'changing_room' => 'Phòng thay đồ',
        'showers' => 'Phòng tắm',
        'drinks' => 'Nước uống',
        'lighting' => 'Đèn chiếu sáng',
        'seating' => 'Khán đài',
        'wifi' => 'Wi-Fi',
    ];

    static function imagesForOwners(array $ownerIds): array {
        if (!$ownerIds) return [];
        $marks = implode(',', array_fill(0, count($ownerIds), '?'));
        $result = [];
        foreach (Database::q("SELECT id,owner_id,image_path FROM venue_images WHERE owner_id IN ($marks) ORDER BY id", $ownerIds) as $row) {
            $result[(int)$row['owner_id']][] = [
                'id' => (int)$row['id'],
                'url' => BASE . '/' . $row['image_path'],
            ];
        }
        return $result;
    }

    static function amenitiesForOwners(array $ownerIds): array {
        if (!$ownerIds) return [];
        $marks = implode(',', array_fill(0, count($ownerIds), '?'));
        $result = [];
        foreach (Database::q("SELECT owner_id,amenity FROM venue_amenities WHERE owner_id IN ($marks) ORDER BY amenity", $ownerIds) as $row) {
            $result[(int)$row['owner_id']][] = self::AMENITIES[$row['amenity']] ?? $row['amenity'];
        }
        return $result;
    }

    static function summariesForOwners(array $ownerIds): array {
        if (!$ownerIds) return [];
        $marks = implode(',', array_fill(0, count($ownerIds), '?'));
        $result = [];
        foreach (Database::q("SELECT owner_id,ROUND(AVG(rating),1) average,COUNT(*) count
            FROM venue_reviews WHERE visible=1 AND owner_id IN ($marks) GROUP BY owner_id", $ownerIds) as $row) {
            $result[(int)$row['owner_id']] = ['average' => (float)$row['average'], 'count' => (int)$row['count']];
        }
        return $result;
    }

    static function topRated(int $limit = 6): array {
        $rows = Database::q("SELECT o.user_id id,o.venue_name name,o.address,u.name owner_name,u.phone owner_phone,
            ROUND(r.average,1) rating_average,r.count rating_count,
            GROUP_CONCAT(DISTINCT p.type ORDER BY p.type SEPARATOR ', ') pitch_types
            FROM owner_profiles o
            JOIN users u ON u.id=o.user_id AND u.role='owner' AND u.status='active'
            JOIN pitches p ON p.owner_id=o.user_id AND p.active=1
            JOIN (SELECT owner_id,AVG(rating) average,COUNT(*) count FROM venue_reviews
                WHERE visible=1 GROUP BY owner_id) r ON r.owner_id=o.user_id
            WHERE o.account_no IS NOT NULL AND o.account_no<>''
            GROUP BY o.user_id,o.venue_name,o.address,u.name,u.phone,r.average,r.count
            ORDER BY r.average DESC,r.count DESC,o.venue_name LIMIT " . max(1, min($limit, 20)))->fetchAll();
        $ids = array_map(fn($row) => (int)$row['id'], $rows);
        $images = self::imagesForOwners($ids);
        $amenities = self::amenitiesForOwners($ids);
        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            $row['id'] = $id;
            $row['rating_average'] = (float)$row['rating_average'];
            $row['rating_count'] = (int)$row['rating_count'];
            $row['images'] = $images[$id] ?? [];
            $row['amenities'] = $amenities[$id] ?? [];
        }
        unset($row);
        return $rows;
    }

    static function images(int $ownerId): array {
        return Database::q("SELECT id,image_path FROM venue_images WHERE owner_id=? ORDER BY id", [$ownerId])->fetchAll();
    }

    static function addImage(int $ownerId, string $path): bool {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            if (!Database::q("SELECT user_id FROM owner_profiles WHERE user_id=? FOR UPDATE", [$ownerId])->fetch()) {
                Database::rollBack();
                return false;
            }
            $count = (int)Database::q("SELECT COUNT(*) FROM venue_images WHERE owner_id=?", [$ownerId])->fetchColumn();
            if ($count >= 8) { Database::rollBack(); return false; }
            Database::q("INSERT INTO venue_images(owner_id,image_path) VALUES(?,?)", [$ownerId, $path]);
            Database::commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) Database::rollBack();
            throw $e;
        }
    }

    static function deleteImage(int $imageId, int $ownerId): ?string {
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            if (!Database::q("SELECT user_id FROM owner_profiles WHERE user_id=? FOR UPDATE", [$ownerId])->fetch()) {
                Database::rollBack();
                return null;
            }
            $row = Database::q("SELECT image_path FROM venue_images WHERE id=? AND owner_id=? FOR UPDATE",
                [$imageId, $ownerId])->fetch();
            if (!$row) {
                Database::rollBack();
                return null;
            }
            Database::q("DELETE FROM venue_images WHERE id=? AND owner_id=?", [$imageId, $ownerId]);
            Database::commit();
            return $row['image_path'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) Database::rollBack();
            throw $e;
        }
    }

    static function saveAmenities(int $ownerId, array $amenities): void {
        $amenities = array_values(array_unique(array_filter(array_map('trim', $amenities), fn($amenity) => $amenity !== '')));
        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            if (!Database::q("SELECT user_id FROM owner_profiles WHERE user_id=? FOR UPDATE", [$ownerId])->fetch()) {
                Database::rollBack();
                throw new RuntimeException('Không tìm thấy hồ sơ cụm sân.');
            }
            Database::q("DELETE FROM venue_amenities WHERE owner_id=?", [$ownerId]);
            foreach ($amenities as $amenity) Database::q("INSERT INTO venue_amenities(owner_id,amenity) VALUES(?,?)", [$ownerId, $amenity]);
            Database::commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) Database::rollBack();
            throw $e;
        }
    }

    static function amenities(int $ownerId): array {
        $rows = Database::q("SELECT amenity FROM venue_amenities WHERE owner_id=? ORDER BY amenity", [$ownerId])->fetchAll();
        return array_map(fn($row) => self::AMENITIES[$row['amenity']] ?? $row['amenity'], $rows);
    }

    static function reviews(int $ownerId, ?int $userId): array {
        $reviews = Database::q("SELECT r.id,r.rating,r.comment,r.created_at,u.name
            FROM venue_reviews r JOIN users u ON u.id=r.user_id WHERE r.owner_id=? AND r.visible=1
            ORDER BY r.created_at DESC,r.id DESC LIMIT 30", [$ownerId])->fetchAll();
        $summary = Database::q("SELECT ROUND(AVG(rating),1) average,COUNT(*) count
            FROM venue_reviews WHERE owner_id=? AND visible=1", [$ownerId])->fetch();
        $mine = $userId ? Database::q("SELECT id,rating,comment FROM venue_reviews WHERE owner_id=? AND user_id=?", [$ownerId, $userId])->fetch() ?: null : null;
        return [
            'reviews' => $reviews,
            'average' => $summary['average'] === null ? null : (float)$summary['average'],
            'count' => (int)$summary['count'],
            'my_review' => $mine,
            'can_review' => $userId !== null,
        ];
    }

    static function saveReview(int $ownerId, int $userId, int $rating, string $comment): bool {
        if (!Database::q("SELECT user_id FROM owner_profiles WHERE user_id=?", [$ownerId])->fetchColumn()) return false;
        Database::q("INSERT INTO venue_reviews(owner_id,user_id,booking_id,rating,comment)
            VALUES(?,?,NULL,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment),updated_at=CURRENT_TIMESTAMP",
            [$ownerId, $userId, $rating, $comment]);
        return true;
    }

    static function adminReviews(): array {
        return Database::q("SELECT r.id,r.owner_id,r.rating,r.comment,r.visible,r.created_at,r.updated_at,
            c.name customer_name,o.venue_name
            FROM venue_reviews r JOIN users c ON c.id=r.user_id
            JOIN owner_profiles o ON o.user_id=r.owner_id
            ORDER BY r.created_at DESC,r.id DESC LIMIT 100")->fetchAll();
    }

    static function setReviewVisibility(int $id, bool $visible): bool {
        return Database::q("UPDATE venue_reviews SET visible=? WHERE id=?", [$visible ? 1 : 0, $id])->rowCount() > 0;
    }
}
