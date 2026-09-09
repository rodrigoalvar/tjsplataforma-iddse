-- Campos opcionales para trazabilidad de vinculación entre informes_recibidos e informes
-- Compatible con MySQL 5.7+ (no usa ADD COLUMN IF NOT EXISTS ni CREATE INDEX IF NOT EXISTS)

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_add_informes_recibidos_linking_fields$$

CREATE PROCEDURE sp_add_informes_recibidos_linking_fields()
BEGIN
    DECLARE dbname VARCHAR(64);
    SET dbname = DATABASE();

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND COLUMN_NAME = 'informe_id'
    ) THEN
        ALTER TABLE informes_recibidos
            ADD COLUMN informe_id INT NULL
            COMMENT 'ID en tabla informes si fue integrado al flujo principal';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND COLUMN_NAME = 'metodo_vinculacion'
    ) THEN
        ALTER TABLE informes_recibidos
            ADD COLUMN metodo_vinculacion VARCHAR(30) NULL
            COMMENT 'auto_accno, manual';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND COLUMN_NAME = 'vinculado_por_usuario_id'
    ) THEN
        ALTER TABLE informes_recibidos
            ADD COLUMN vinculado_por_usuario_id INT NULL
            COMMENT 'Usuario que realizó vinculación manual';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND INDEX_NAME = 'idx_informes_recibidos_informe_id'
    ) THEN
        CREATE INDEX idx_informes_recibidos_informe_id ON informes_recibidos (informe_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND INDEX_NAME = 'idx_informes_recibidos_metodo_vinculacion'
    ) THEN
        CREATE INDEX idx_informes_recibidos_metodo_vinculacion ON informes_recibidos (metodo_vinculacion);
    END IF;
END$$

DELIMITER ;

CALL sp_add_informes_recibidos_linking_fields();

DROP PROCEDURE IF EXISTS sp_add_informes_recibidos_linking_fields;
