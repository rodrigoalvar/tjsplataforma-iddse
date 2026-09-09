-- Re-sync R2: columna is_resync en cola y r2_sync_status en r2_studies
-- Ejecutar en la BD del proyecto (ajustar nombre de BD si aplica).

-- r2_queue.is_resync
SET @dbname = DATABASE();
SET @tablename = 'r2_queue';
SET @columnname = 'is_resync';

SET @exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
);

SET @sql = IF(@exists = 0,
    'ALTER TABLE r2_queue ADD COLUMN is_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=job de sincronización incremental (nuevas instancias)'' AFTER upload_method',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- r2_studies.r2_sync_status
SET @tablename2 = 'r2_studies';
SET @columnname2 = 'r2_sync_status';

SET @exists2 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename2 AND COLUMN_NAME = @columnname2
);

SET @sql2 = IF(@exists2 = 0,
    'ALTER TABLE r2_studies ADD COLUMN r2_sync_status ENUM(''idle'',''pending'',''syncing'') NOT NULL DEFAULT ''idle'' COMMENT ''Estado de sync incremental vs Orthanc'' AFTER r2_status',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
