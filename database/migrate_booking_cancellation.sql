-- Run once on existing installations: mysql -u root -p san_bong < migrate_booking_cancellation.sql
USE san_bong;

ALTER TABLE bookings
  MODIFY status ENUM('pending','confirmed','cancel_fee_pending','paid','cancelled','expired') NOT NULL DEFAULT 'confirmed';

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='refund_amount') = 0,
  'ALTER TABLE bookings ADD COLUMN refund_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_status',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='cancellation_fee') = 0,
  'ALTER TABLE bookings ADD COLUMN cancellation_fee INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_amount',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='cancel_requested_at') = 0,
  'ALTER TABLE bookings ADD COLUMN cancel_requested_at DATETIME NULL AFTER cancellation_fee',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='cancelled_by') = 0,
  'ALTER TABLE bookings ADD COLUMN cancelled_by ENUM(''customer'',''owner'') NULL AFTER cancelled_at',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

ALTER TABLE payments MODIFY confirmed_by INT UNSIGNED NULL;
