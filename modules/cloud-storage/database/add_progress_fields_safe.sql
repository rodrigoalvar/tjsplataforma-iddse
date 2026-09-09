-- Agregar campos de progreso a r2_queue (solo si no existen)
-- Ejecutar: mysql -u root -p tjsmedical_iddse < add_progress_fields_safe.sql

-- Verificar y agregar columnas solo si no existen
SET @dbname = DATABASE();
SET @tablename = 'r2_queue';

-- total_instances
SET @colname = 'total_instances';
SET @coltype = 'INT(11) DEFAULT 0 COMMENT \'Total de instancias a subir\'';
SET @colposition = 'AFTER status';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column total_instances already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- instances_uploaded
SET @colname = 'instances_uploaded';
SET @coltype = 'INT(11) DEFAULT 0 COMMENT \'Instancias subidas hasta ahora\'';
SET @colposition = 'AFTER total_instances';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column instances_uploaded already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- total_bytes
SET @colname = 'total_bytes';
SET @coltype = 'BIGINT(20) DEFAULT 0 COMMENT \'Total de bytes a subir\'';
SET @colposition = 'AFTER instances_uploaded';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column total_bytes already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- bytes_uploaded
SET @colname = 'bytes_uploaded';
SET @coltype = 'BIGINT(20) DEFAULT 0 COMMENT \'Bytes subidos hasta ahora\'';
SET @colposition = 'AFTER total_bytes';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column bytes_uploaded already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_started_at
SET @colname = 'upload_started_at';
SET @coltype = 'TIMESTAMP NULL COMMENT \'Momento en que comenzó el upload\'';
SET @colposition = 'AFTER bytes_uploaded';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_started_at already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_finished_at
SET @colname = 'upload_finished_at';
SET @coltype = 'TIMESTAMP NULL COMMENT \'Momento en que terminó el upload\'';
SET @colposition = 'AFTER upload_started_at';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_finished_at already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_duration_seconds
SET @colname = 'upload_duration_seconds';
SET @coltype = 'INT(11) DEFAULT NULL COMMENT \'Duración total del upload en segundos\'';
SET @colposition = 'AFTER upload_finished_at';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_duration_seconds already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_speed_mbps
SET @colname = 'upload_speed_mbps';
SET @coltype = 'DECIMAL(10,2) DEFAULT NULL COMMENT \'Velocidad de upload actual en MB/s\'';
SET @colposition = 'AFTER upload_duration_seconds';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_speed_mbps already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_speed_min_mbps
SET @colname = 'upload_speed_min_mbps';
SET @coltype = 'DECIMAL(10,2) DEFAULT NULL COMMENT \'Velocidad mínima detectada en MB/s\'';
SET @colposition = 'AFTER upload_speed_mbps';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_speed_min_mbps already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_speed_max_mbps
SET @colname = 'upload_speed_max_mbps';
SET @coltype = 'DECIMAL(10,2) DEFAULT NULL COMMENT \'Velocidad máxima detectada en MB/s\'';
SET @colposition = 'AFTER upload_speed_min_mbps';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_speed_max_mbps already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_speed_avg_mbps
SET @colname = 'upload_speed_avg_mbps';
SET @coltype = 'DECIMAL(10,2) DEFAULT NULL COMMENT \'Velocidad promedio en MB/s\'';
SET @colposition = 'AFTER upload_speed_max_mbps';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_speed_avg_mbps already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_ip (IP local)
SET @colname = 'upload_ip';
SET @coltype = 'VARCHAR(45) DEFAULT NULL COMMENT \'IP local usada para el upload\'';
SET @colposition = 'AFTER upload_speed_avg_mbps';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_ip already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- upload_ip_wan (IP pública/WAN)
SET @colname = 'upload_ip_wan';
SET @coltype = 'VARCHAR(45) DEFAULT NULL COMMENT \'IP pública (WAN) usada para el upload\'';
SET @colposition = 'AFTER upload_ip';
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname;
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @colname, '` ', @coltype, ' ', @colposition), 'SELECT "Column upload_ip_wan already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Script ejecutado correctamente. Columnas faltantes agregadas.' AS Resultado;
