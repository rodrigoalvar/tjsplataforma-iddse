-- Agregar campos de papelera y estados a audios_informe
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Base de datos: tjsmedical_iddse o TJSMEDICAL

USE tjsmedical_iddse;

-- Verificar y agregar campo estado si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'estado');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN estado ENUM(
        ''en_papelera'',
        ''enviado_ftp'',
        ''enviado_transcripcion'',
        ''guardado_informe'',
        ''eliminado''
    ) DEFAULT ''en_papelera'' AFTER activo',
    'SELECT ''Campo estado ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo backup_path si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'backup_path');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN backup_path VARCHAR(500) COMMENT ''Ruta del archivo de respaldo en servidor'' AFTER ruta_archivo',
    'SELECT ''Campo backup_path ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo local_saved si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'local_saved');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN local_saved BOOLEAN DEFAULT FALSE COMMENT ''Indica si se guardó localmente'' AFTER backup_path',
    'SELECT ''Campo local_saved ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo local_file_name si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'local_file_name');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN local_file_name VARCHAR(255) COMMENT ''Nombre del archivo guardado localmente'' AFTER local_saved',
    'SELECT ''Campo local_file_name ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo workspace_panel_id si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'workspace_panel_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN workspace_panel_id VARCHAR(100) COMMENT ''ID del panel de workspace donde se creó'' AFTER local_file_name',
    'SELECT ''Campo workspace_panel_id ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo recording_id si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'recording_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN recording_id VARCHAR(100) COMMENT ''ID temporal del recording en workspace'' AFTER workspace_panel_id',
    'SELECT ''Campo recording_id ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo recovered si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'recovered');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN recovered BOOLEAN DEFAULT FALSE COMMENT ''Indica si fue recuperado desde papelera'' AFTER recording_id',
    'SELECT ''Campo recovered ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo fecha_eliminacion si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'fecha_eliminacion');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN fecha_eliminacion TIMESTAMP NULL COMMENT ''Fecha cuando fue eliminado de workspace'' AFTER fecha_modificacion',
    'SELECT ''Campo fecha_eliminacion ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo fecha_envio_ftp si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'fecha_envio_ftp');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN fecha_envio_ftp TIMESTAMP NULL COMMENT ''Fecha cuando fue enviado por FTP'' AFTER fecha_eliminacion',
    'SELECT ''Campo fecha_envio_ftp ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo fecha_envio_transcripcion si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'fecha_envio_transcripcion');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN fecha_envio_transcripcion TIMESTAMP NULL COMMENT ''Fecha cuando fue enviado para transcripción'' AFTER fecha_envio_ftp',
    'SELECT ''Campo fecha_envio_transcripcion ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar campo fecha_guardado_informe si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND COLUMN_NAME = 'fecha_guardado_informe');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE audios_informe ADD COLUMN fecha_guardado_informe TIMESTAMP NULL COMMENT ''Fecha cuando se guardó con informe finalizado'' AFTER fecha_envio_transcripcion',
    'SELECT ''Campo fecha_guardado_informe ya existe en audios_informe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar índices si no existen
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND INDEX_NAME = 'idx_estado');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE audios_informe ADD INDEX idx_estado (estado)',
    'SELECT ''Índice idx_estado ya existe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND INDEX_NAME = 'idx_workspace_panel_id');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE audios_informe ADD INDEX idx_workspace_panel_id (workspace_panel_id)',
    'SELECT ''Índice idx_workspace_panel_id ya existe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND INDEX_NAME = 'idx_recording_id');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE audios_informe ADD INDEX idx_recording_id (recording_id)',
    'SELECT ''Índice idx_recording_id ya existe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audios_informe' 
    AND INDEX_NAME = 'idx_fecha_eliminacion');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE audios_informe ADD INDEX idx_fecha_eliminacion (fecha_eliminacion)',
    'SELECT ''Índice idx_fecha_eliminacion ya existe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Actualizar audios existentes sin estado a 'en_papelera'
UPDATE audios_informe 
SET estado = 'en_papelera' 
WHERE estado IS NULL;

-- Actualizar audios con informe_id a 'guardado_informe'
UPDATE audios_informe 
SET estado = 'guardado_informe',
    fecha_guardado_informe = fecha_modificacion
WHERE informe_id IS NOT NULL 
AND estado = 'en_papelera';

SELECT 'Campos de papelera agregados exitosamente a audios_informe' AS resultado;
