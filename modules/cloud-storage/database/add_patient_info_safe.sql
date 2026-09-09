-- =====================================================
-- Agregar columnas de información del paciente/estudio a r2_queue
-- Safe version: verifica si las columnas existen antes de agregar
-- =====================================================

-- patient_name
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_queue' AND COLUMN_NAME = 'patient_name');
SET @sql = IF(@col = 0, 
    'ALTER TABLE r2_queue ADD COLUMN patient_name VARCHAR(255) NULL COMMENT ''Nombre del paciente'' AFTER study_instance_uid',
    'SELECT ''patient_name ya existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- patient_id
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_queue' AND COLUMN_NAME = 'patient_id');
SET @sql = IF(@col = 0, 
    'ALTER TABLE r2_queue ADD COLUMN patient_id VARCHAR(100) NULL COMMENT ''ID del paciente'' AFTER patient_name',
    'SELECT ''patient_id ya existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- study_date
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_queue' AND COLUMN_NAME = 'study_date');
SET @sql = IF(@col = 0, 
    'ALTER TABLE r2_queue ADD COLUMN study_date VARCHAR(20) NULL COMMENT ''Fecha del estudio (YYYYMMDD)'' AFTER patient_id',
    'SELECT ''study_date ya existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- modality
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_queue' AND COLUMN_NAME = 'modality');
SET @sql = IF(@col = 0, 
    'ALTER TABLE r2_queue ADD COLUMN modality VARCHAR(50) NULL COMMENT ''Modalidad del estudio (CT, MR, etc.)'' AFTER study_date',
    'SELECT ''modality ya existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- study_description
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_queue' AND COLUMN_NAME = 'study_description');
SET @sql = IF(@col = 0, 
    'ALTER TABLE r2_queue ADD COLUMN study_description VARCHAR(500) NULL COMMENT ''Descripción del estudio'' AFTER modality',
    'SELECT ''study_description ya existe'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Columnas de información del paciente verificadas/agregadas correctamente' AS resultado;
