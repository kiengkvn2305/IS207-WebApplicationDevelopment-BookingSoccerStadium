-- Sân Nhà – MySQL 5.7+/8.0 / MariaDB 10.3+
-- Nhập file:  mysql -u root -p < schema.sql
CREATE DATABASE IF NOT EXISTS san_bong CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE san_bong;

-- Tài khoản: customer (người đặt), owner (chủ sân), admin
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone VARCHAR(15) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','owner','admin') NOT NULL DEFAULT 'customer',
  status ENUM('active','pending','locked') NOT NULL DEFAULT 'active', -- owner mới đăng ký = pending, chờ admin duyệt
  profile_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_role_status (role, status)
) ENGINE=InnoDB;

-- Thông tin cụm sân + tài khoản ngân hàng nhận tiền (do chủ sân tự nhập)
CREATE TABLE owner_profiles (
  user_id INT UNSIGNED PRIMARY KEY,
  venue_name VARCHAR(100) NOT NULL,
  address VARCHAR(200) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  bank_bin CHAR(6) NULL,
  account_no VARCHAR(19) NULL,
  account_name VARCHAR(60) NULL,
  profile_version INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pitches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  type VARCHAR(30) NOT NULL,
  price INT UNSIGNED NOT NULL,
  peak_price INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(8) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  pitch_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  start_hour TINYINT UNSIGNED NOT NULL,
  end_hour TINYINT UNSIGNED NOT NULL,
  total INT UNSIGNED NOT NULL,
  subtotal INT UNSIGNED NOT NULL DEFAULT 0,
  discount INT UNSIGNED NOT NULL DEFAULT 0,
  voucher_id INT UNSIGNED NULL,
  status ENUM('pending','confirmed','cancel_fee_pending','paid','cancelled','expired') NOT NULL DEFAULT 'confirmed',
  refund_status ENUM('none','pending','done') NOT NULL DEFAULT 'none',
  refund_amount INT UNSIGNED NOT NULL DEFAULT 0,
  cancellation_fee INT UNSIGNED NOT NULL DEFAULT 0,
  cancel_requested_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  confirmed_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancelled_by ENUM('customer','owner') NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (pitch_id) REFERENCES pitches(id),
  FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL,
  INDEX idx_user (user_id), INDEX idx_pitch_date (pitch_id, date), INDEX idx_status_exp (status, expires_at)
) ENGINE=InnoDB;

-- Mỗi giờ được giữ = 1 dòng. PRIMARY KEY chống đặt trùng. Hủy/hết hạn thì xóa dòng để nhả sân.
CREATE TABLE booking_slots (
  booking_id INT UNSIGNED NOT NULL,
  pitch_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  hour TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (pitch_id, date, hour),
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE unavailable_slots (
  pitch_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  hour TINYINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NULL,
  PRIMARY KEY (pitch_id, date, hour),
  FOREIGN KEY (pitch_id) REFERENCES pitches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE venue_amenities (
  owner_id INT UNSIGNED NOT NULL,
  amenity VARCHAR(80) NOT NULL,
  PRIMARY KEY (owner_id,amenity),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

-- Lịch sử xác nhận thanh toán (chủ sân bấm xác nhận đã nhận tiền)
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  amount INT UNSIGNED NOT NULL,
  method ENUM('vietqr') NOT NULL DEFAULT 'vietqr',
  confirmed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB;

-- Hủy lịch 2 bước: bước 1 cấp token dùng 1 lần (5 phút), bước 2 phải nộp token + mật khẩu
CREATE TABLE cancel_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email, created_at), INDEX idx_ip (ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  message VARCHAR(500) NOT NULL,
  link VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notifications_user_read_id (user_id,read_at,id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE match_challenges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL UNIQUE,
  creator_id INT UNSIGNED NOT NULL,
  team_name VARCHAR(80) NOT NULL,
  contact_phone VARCHAR(15) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  players_needed TINYINT UNSIGNED NOT NULL DEFAULT 1,
  join_open TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('open','matched','cancelled') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_match_challenges_status (status,created_at)
) ENGINE=InnoDB;

CREATE TABLE match_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  challenge_id INT UNSIGNED NOT NULL,
  applicant_id INT UNSIGNED NOT NULL,
  request_type ENUM('opponent','player') NOT NULL DEFAULT 'opponent',
  team_name VARCHAR(80) NOT NULL,
  contact_phone VARCHAR(15) NOT NULL,
  message VARCHAR(300) NOT NULL DEFAULT '',
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_match_request_applicant_type (challenge_id,applicant_id,request_type),
  FOREIGN KEY (challenge_id) REFERENCES match_challenges(id) ON DELETE CASCADE,
  FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_match_requests_challenge (challenge_id,status,created_at)
) ENGINE=InnoDB;

-- Dữ liệu mẫu (ĐỔI MẬT KHẨU NGAY sau khi chạy thử)
-- admin@sannha.vn / Admin@12345   |   chusan@sannha.vn, khach@sannha.vn / Demo@12345
INSERT INTO users (id,name,email,phone,password_hash,role,status) VALUES
 (1,'Quản trị viên','admin@sannha.vn','0900000001','$2y$10$gYCfqmLuVJuCq3wrnxUXfOJA7wyiGyRUHhNqRMoZxxqhZGZFvZv5u','admin','active'),
 (2,'Chủ sân Demo','chusan@sannha.vn','0900000002','$2y$10$oqYakmiaV4eqnwjWtyNCqOpEMy0JBRJ1vSEAci8UoQrJ.XMZWzWBS','owner','active'),
 (3,'Khách Demo','khach@sannha.vn','0900000003','$2y$10$oqYakmiaV4eqnwjWtyNCqOpEMy0JBRJ1vSEAci8UoQrJ.XMZWzWBS','customer','active');
INSERT INTO owner_profiles (user_id,venue_name) VALUES (2,'Cụm sân Demo');  -- chủ sân phải tự nhập tài khoản ngân hàng thì sân mới hiện ra
INSERT INTO pitches (owner_id,name,type,price,peak_price) VALUES
 (2,'Sân A','Sân 5 người',200000,300000),(2,'Sân B','Sân 5 người',200000,300000),(2,'Sân C','Sân 7 người',350000,500000);
 

CREATE TABLE IF NOT EXISTS venue_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_venue_images_owner (owner_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS venue_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  booking_id INT UNSIGNED NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(1000) NOT NULL,
  visible TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_venue_review_user (owner_id,user_id),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  INDEX idx_venue_reviews_public (owner_id,visible,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sepay_webhook_events (
  transaction_id BIGINT UNSIGNED PRIMARY KEY,
  booking_id INT UNSIGNED NULL,
  transfer_amount BIGINT UNSIGNED NOT NULL,
  transfer_type ENUM('in','out') NOT NULL,
  transaction_date DATETIME NOT NULL,
  reference_code VARCHAR(100) NOT NULL DEFAULT '',
  content VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('received','paid','unmatched','ignored','amount_mismatch','late','already_paid','not_pending') NOT NULL DEFAULT 'received',
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  INDEX idx_sepay_booking_status (booking_id,status)
) ENGINE=InnoDB;

-- MariaDB/MySQL procedures centralize transaction boundaries used by the PHP models.
-- Re-runnable: these routines are replaced on every migration run.
DROP PROCEDURE IF EXISTS sannha_tx_begin;
DROP PROCEDURE IF EXISTS sannha_tx_commit;
DROP PROCEDURE IF EXISTS sannha_tx_rollback;
DROP FUNCTION IF EXISTS sannha_cancel_fee;

DELIMITER //

CREATE PROCEDURE sannha_tx_begin()
BEGIN
  IF @@session.in_transaction <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A transaction is already active.';
  END IF;
  START TRANSACTION;
END//

CREATE PROCEDURE sannha_tx_commit()
BEGIN
  IF @@session.in_transaction = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active transaction to commit.';
  END IF;
  COMMIT;
END//

CREATE PROCEDURE sannha_tx_rollback()
BEGIN
  IF @@session.in_transaction <> 0 THEN
    ROLLBACK;
  END IF;
END//

CREATE FUNCTION sannha_cancel_fee(total_amount INT UNSIGNED)
RETURNS INT UNSIGNED
DETERMINISTIC
NO SQL
RETURN FLOOR((CAST(total_amount AS DECIMAL(20,0)) * 70 + 50) / 100)//

DELIMITER ;
