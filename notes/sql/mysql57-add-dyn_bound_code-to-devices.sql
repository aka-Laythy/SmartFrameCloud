-- SmartFrameCloud
-- MySQL 5.7 safe migration:
-- add `dyn_bound_code` to `devices` only if it does not already exist.

SET @db_name := DATABASE();

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'devices'
      AND COLUMN_NAME = 'dyn_bound_code'
);

SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE `devices` ADD COLUMN `dyn_bound_code` varchar(16) NULL DEFAULT NULL COMMENT ''动态绑定码，未绑定设备待展示，已绑定后置空'' AFTER `last_online_at`',
    'SELECT ''Column dyn_bound_code already exists on devices'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
