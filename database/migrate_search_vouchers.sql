-- Run once on existing installations:
-- mysql -u root -p san_bong < migrate_search_vouchers.sql
USE san_bong;

ALTER TABLE owner_profiles
  ADD COLUMN latitude DECIMAL(10,7) NULL,
  ADD COLUMN longitude DECIMAL(10,7) NULL;

ALTER TABLE bookings
  ADD COLUMN subtotal INT UNSIGNED NOT NULL DEFAULT 0 AFTER total,
  ADD COLUMN discount INT UNSIGNED NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN voucher_id INT UNSIGNED NULL AFTER discount;

UPDATE bookings SET subtotal=total WHERE subtotal=0;

CREATE TABLE vouchers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  discount_type ENUM('percent','fixed') NOT NULL,
  discount_value INT UNSIGNED NOT NULL,
  min_total INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  usage_limit INT UNSIGNED NULL,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_vouchers_active_dates (active,starts_at,ends_at)
) ENGINE=InnoDB;

ALTER TABLE bookings
  ADD CONSTRAINT fk_bookings_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL;

CREATE TABLE voucher_redemptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id INT UNSIGNED NOT NULL,
  booking_id INT UNSIGNED NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  discount INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_voucher_redemptions_user (voucher_id,user_id)
) ENGINE=InnoDB;

ALTER TABLE venue_amenities MODIFY amenity VARCHAR(80) NOT NULL;
ALTER TABLE unavailable_slots ADD COLUMN reason VARCHAR(255) NULL;
