-- Run once on existing installations: mysql -u root -p san_bong < migrate_match_guest_players.sql
USE san_bong;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='match_challenges' AND column_name='players_needed') = 0,
  'ALTER TABLE match_challenges ADD COLUMN players_needed TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER description',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='match_challenges' AND column_name='join_open') = 0,
  'ALTER TABLE match_challenges ADD COLUMN join_open TINYINT(1) NOT NULL DEFAULT 1 AFTER players_needed',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='match_requests' AND column_name='request_type') = 0,
  'ALTER TABLE match_requests ADD COLUMN request_type ENUM(''opponent'',''player'') NOT NULL DEFAULT ''opponent'' AFTER applicant_id',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='match_requests' AND index_name='uq_match_request_applicant') > 0,
  'ALTER TABLE match_requests DROP INDEX uq_match_request_applicant',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='match_requests' AND index_name='uq_match_request_applicant_type') = 0,
  'ALTER TABLE match_requests ADD UNIQUE KEY uq_match_request_applicant_type (challenge_id,applicant_id,request_type)',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
