-- Agrega soporte para descartar informes recibidos con trazabilidad de auditoría.
-- Compatible con MySQL sin soporte para "ADD COLUMN IF NOT EXISTS".
-- Ejecutar una vez en entornos existentes.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_add_informes_recibidos_descartado_fields $$
CREATE PROCEDURE sp_add_informes_recibidos_descartado_fields()
BEGIN
    DECLARE v_estado_type TEXT;

    -- 1) Asegurar enum estado con valor 'descartado'
    SELECT COLUMN_TYPE
      INTO v_estado_type
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'informes_recibidos'
       AND COLUMN_NAME = 'estado'
     LIMIT 1;

    IF v_estado_type IS NOT NULL AND LOCATE('descartado', v_estado_type) = 0 THEN
        SET @sql = "ALTER TABLE informes_recibidos MODIFY COLUMN estado ENUM('recibido','procesado','vinculado','error','descartado') DEFAULT 'recibido'";
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- 2) motivo_descarte
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'informes_recibidos'
           AND COLUMN_NAME = 'motivo_descarte'
    ) THEN
        SET @sql = "ALTER TABLE informes_recibidos ADD COLUMN motivo_descarte TEXT NULL COMMENT 'Motivo del descarte cuando estado=descartado'";
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- 3) descartado_por_usuario_id
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'informes_recibidos'
           AND COLUMN_NAME = 'descartado_por_usuario_id'
    ) THEN
        SET @sql = "ALTER TABLE informes_recibidos ADD COLUMN descartado_por_usuario_id INT NULL COMMENT 'Usuario que descarta el informe'";
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- 4) fecha_descarte
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'informes_recibidos'
           AND COLUMN_NAME = 'fecha_descarte'
    ) THEN
        SET @sql = "ALTER TABLE informes_recibidos ADD COLUMN fecha_descarte TIMESTAMP NULL DEFAULT NULL COMMENT 'Fecha/hora del descarte'";
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- 5) Índices (si no existen)
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'informes_recibidos'
           AND INDEX_NAME = 'idx_informes_recibidos_fecha_descarte'
    ) THEN
        SET @sql = "CREATE INDEX idx_informes_recibidos_fecha_descarte ON informes_recibidos (fecha_descarte)";
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'informes_recibidos'
           AND INDEX_NAME = 'idx_informes_recibidos_usuario_descarte'
    ) THEN
        SET @sql = "CREATE INDEX idx_informes_recibidos_usuario_descarte ON informes_recibidos (descartado_por_usuario_id)";
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

CALL sp_add_informes_recibidos_descartado_fields() $$
DROP PROCEDURE IF EXISTS sp_add_informes_recibidos_descartado_fields $$

DELIMITER ;
