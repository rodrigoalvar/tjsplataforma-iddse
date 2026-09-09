-- =====================================================
-- Agregar campos de bloqueo a r2_studies
-- Safe version: verifica si las columnas existen antes de agregar
-- =====================================================

-- is_locked
SET @col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'r2_studies' 
      AND COLUMN_NAME = 'is_locked'
);
SET @sql = IF(@col = 0,
    'ALTER TABLE r2_studies ADD COLUMN is_locked TINYINT(1) DEFAULT 0 COMMENT ''Si está bloqueado para no eliminar/reciclar'' AFTER r2_manifest_url',
    'SELECT ''is_locked ya existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- lock_reason
SET @col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'r2_studies' 
      AND COLUMN_NAME = 'lock_reason'
);
SET @sql = IF(@col = 0,
    'ALTER TABLE r2_studies ADD COLUMN lock_reason VARCHAR(500) NULL COMMENT ''Motivo del bloqueo'' AFTER is_locked',
    'SELECT ''lock_reason ya existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- locked_at
SET @col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'r2_studies' 
      AND COLUMN_NAME = 'locked_at'
);
SET @sql = IF(@col = 0,
    'ALTER TABLE r2_studies ADD COLUMN locked_at TIMESTAMP NULL COMMENT ''Fecha/hora de bloqueo'' AFTER lock_reason',
    'SELECT ''locked_at ya existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Campos de bloqueo de r2_studies verificadas/agregadas correctamente' AS resultado;

