-- Add optimistic versions to editable profiles to prevent stale-form overwrites.
USE san_bong;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='users' AND column_name='profile_version') = 0,
  'ALTER TABLE users ADD COLUMN profile_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='owner_profiles' AND column_name='profile_version') = 0,
  'ALTER TABLE owner_profiles ADD COLUMN profile_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER account_name',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
