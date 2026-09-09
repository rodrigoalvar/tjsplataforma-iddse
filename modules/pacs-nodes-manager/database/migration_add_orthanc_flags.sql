-- =====================================================
-- Migración: Agregar campos para flags de Orthanc
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Versión: 1.1.0
-- Fecha: 2026-03-06
-- =====================================================

-- Agregar campos para configuración de Orthanc DicomModalities
-- Verificar si cada columna existe antes de agregarla

-- allow_find
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'allow_find');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN allow_find TINYINT(1) DEFAULT 1 COMMENT ''Permitir operaciones C-FIND''',
    'SELECT ''Column allow_find already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- allow_move
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'allow_move');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN allow_move TINYINT(1) DEFAULT 1 COMMENT ''Permitir operaciones C-MOVE''',
    'SELECT ''Column allow_move already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- allow_get
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'allow_get');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN allow_get TINYINT(1) DEFAULT 1 COMMENT ''Permitir operaciones C-GET''',
    'SELECT ''Column allow_get already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- allow_store
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'allow_store');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN allow_store TINYINT(1) DEFAULT 0 COMMENT ''Permitir operaciones C-STORE''',
    'SELECT ''Column allow_store already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- allow_transcoding
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'allow_transcoding');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN allow_transcoding TINYINT(1) DEFAULT 0 COMMENT ''Permitir transcodificación''',
    'SELECT ''Column allow_transcoding already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- manufacturer
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'manufacturer');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN manufacturer VARCHAR(100) DEFAULT ''Generic'' COMMENT ''Fabricante del PACS (Generic, GE, Siemens, etc.)''',
    'SELECT ''Column manufacturer already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- timeout
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'timeout');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN timeout INT(5) DEFAULT 30 COMMENT ''Timeout en segundos para operaciones DICOM''',
    'SELECT ''Column timeout already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- use_dicom_tls
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'use_dicom_tls');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN use_dicom_tls TINYINT(1) DEFAULT 0 COMMENT ''Usar DICOM TLS (seguridad)''',
    'SELECT ''Column use_dicom_tls already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- orthanc_node_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND COLUMN_NAME = 'orthanc_node_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE pacs_nodes ADD COLUMN orthanc_node_id VARCHAR(255) DEFAULT NULL COMMENT ''ID del nodo en Orthanc (para DicomModalitiesInDatabase)''',
    'SELECT ''Column orthanc_node_id already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índice para orthanc_node_id
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pacs_nodes' 
    AND INDEX_NAME = 'idx_orthanc_node_id');
SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE pacs_nodes ADD KEY idx_orthanc_node_id (orthanc_node_id)',
    'SELECT ''Index idx_orthanc_node_id already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
