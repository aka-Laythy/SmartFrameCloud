-- SmartFrameCloud
-- MySQL 5.7 safe migration:
-- add dynamic bind code timing columns and lookup index to `devices`.

SET @db_name := DATABASE();

SET @issued_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'devices'
      AND COLUMN_NAME = 'dyn_bound_code_issued_at'
);

SET @sql_issued := IF(
    @issued_exists = 0,
    'ALTER TABLE `devices` ADD COLUMN `dyn_bound_code_issued_at` timestamp NULL DEFAULT NULL COMMENT ''动态绑定码下发时间'' AFTER `dyn_bound_code`',
    'SELECT ''Column dyn_bound_code_issued_at already exists on devices'' AS message'
);

PREPARE stmt_issued FROM @sql_issued;
EXECUTE stmt_issued;
DEALLOCATE PREPARE stmt_issued;

SET @expires_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'devices'
      AND COLUMN_NAME = 'dyn_bound_code_expires_at'
);

SET @sql_expires := IF(
    @expires_exists = 0,
    'ALTER TABLE `devices` ADD COLUMN `dyn_bound_code_expires_at` timestamp NULL DEFAULT NULL COMMENT ''动态绑定码过期时间'' AFTER `dyn_bound_code_issued_at`',
    'SELECT ''Column dyn_bound_code_expires_at already exists on devices'' AS message'
);

PREPARE stmt_expires FROM @sql_expires;
EXECUTE stmt_expires;
DEALLOCATE PREPARE stmt_expires;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'devices'
      AND INDEX_NAME = 'idx_dyn_bound_code'
);

SET @sql_index := IF(
    @index_exists = 0,
    'ALTER TABLE `devices` ADD INDEX `idx_dyn_bound_code` (`dyn_bound_code`)',
    'SELECT ''Index idx_dyn_bound_code already exists on devices'' AS message'
);

PREPARE stmt_index FROM @sql_index;
EXECUTE stmt_index;
DEALLOCATE PREPARE stmt_index;
