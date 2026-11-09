-- Run once on existing installations: mysql -u root -p san_bong < migrate_venue_features.sql
CREATE TABLE IF NOT EXISTS venue_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_venue_images_owner (owner_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS venue_amenities (
  owner_id INT UNSIGNED NOT NULL,
  amenity VARCHAR(40) NOT NULL,
  PRIMARY KEY (owner_id,amenity),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
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

ALTER TABLE venue_reviews MODIFY booking_id INT UNSIGNED NULL;

ALTER TABLE bookings
  MODIFY status ENUM('pending','confirmed','cancel_fee_pending','paid','cancelled','expired') NOT NULL DEFAULT 'confirmed',
  ADD COLUMN cancellation_fee INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_status,
  ADD COLUMN cancel_requested_at DATETIME NULL AFTER cancellation_fee,
  ADD COLUMN cancelled_by ENUM('customer','owner') NULL AFTER cancelled_at;

CREATE TABLE IF NOT EXISTS match_challenges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL UNIQUE,
  creator_id INT UNSIGNED NOT NULL,
  team_name VARCHAR(80) NOT NULL,
  contact_phone VARCHAR(15) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('open','matched','cancelled') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_match_challenges_status (status,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS match_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  challenge_id INT UNSIGNED NOT NULL,
  applicant_id INT UNSIGNED NOT NULL,
  team_name VARCHAR(80) NOT NULL,
  contact_phone VARCHAR(15) NOT NULL,
  message VARCHAR(300) NOT NULL DEFAULT '',
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_match_request_applicant (challenge_id,applicant_id),
  FOREIGN KEY (challenge_id) REFERENCES match_challenges(id) ON DELETE CASCADE,
  FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_match_requests_challenge (challenge_id,status,created_at)
) ENGINE=InnoDB;
