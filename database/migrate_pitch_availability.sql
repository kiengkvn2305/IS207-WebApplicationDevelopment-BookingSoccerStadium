-- Chạy một lần trên database đã được tạo bằng schema.sql phiên bản cũ.
USE san_bong;

ALTER TABLE pitches ADD COLUMN image_path VARCHAR(255) NULL AFTER peak_price;

CREATE TABLE unavailable_slots (
  pitch_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  hour TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (pitch_id, date, hour),
  FOREIGN KEY (pitch_id) REFERENCES pitches(id) ON DELETE CASCADE
) ENGINE=InnoDB;
