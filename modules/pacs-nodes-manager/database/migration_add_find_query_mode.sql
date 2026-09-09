-- Migración: Modo de búsqueda para nodos híbridos (QIDO-RS vs DIMSE)
-- Fecha: 2026-03-26

SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'find_query_mode';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN find_query_mode ENUM(''dicomweb'',''dimse'') DEFAULT NULL COMMENT ''Solo híbrido: protocolo para búsquedas (QIDO vs C-FIND). NULL = automático'' AFTER node_type',
    'SELECT ''Column find_query_mode already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
