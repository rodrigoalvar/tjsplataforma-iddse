-- needs_resync / seguimiento de re-sync sin jobs duplicados (r2_studies + r2_queue)
-- Ejecutar en la BD del proyecto.

SET @dbname = DATABASE();

-- r2_studies.needs_resync
SET @tablename = 'r2_studies';
SET @columnname = 'needs_resync';
SET @exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
);
SET @sql = IF(@exists = 0,
    'ALTER TABLE r2_studies ADD COLUMN needs_resync TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=Orthanc supera R2; procesar al terminar job activo'' AFTER r2_status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @columnname = 'resync_requested_at';
SET @exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
);
SET @sql = IF(@exists = 0,
    'ALTER TABLE r2_studies ADD COLUMN resync_requested_at DATETIME NULL DEFAULT NULL COMMENT ''Última petición de re-sync diferida'' AFTER needs_resync',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @columnname = 'last_synced_at';
SET @exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
);
SET @sql = IF(@exists = 0,
    'ALTER TABLE r2_studies ADD COLUMN last_synced_at DATETIME NULL DEFAULT NULL COMMENT ''Última escritura manifest/R2 completada'' AFTER resync_requested_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- r2_queue.needs_followup_sync (mientras primer subida corre, sin fila r2_studies online)
SET @tablename = 'r2_queue';
SET @columnname = 'needs_followup_sync';
SET @exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
);
SET @sql = IF(@exists = 0,
    'ALTER TABLE r2_queue ADD COLUMN needs_followup_sync TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=re-ejecutar sync incremental al terminar este job'' AFTER upload_method',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
