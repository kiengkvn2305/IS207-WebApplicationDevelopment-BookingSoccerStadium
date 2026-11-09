-- Chạy một lần trên database hiện có để bật xác nhận chuyển khoản tự động qua SePay.
USE san_bong;

ALTER TABLE payments MODIFY confirmed_by INT UNSIGNED NULL;

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
