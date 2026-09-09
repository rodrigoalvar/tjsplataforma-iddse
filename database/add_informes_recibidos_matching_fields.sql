-- Campos opcionales para trazabilidad de sugerencias/matching en informes_recibidos
-- Compatible con MySQL 5.7+ (sin IF NOT EXISTS nativo en ADD COLUMN/INDEX)

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_add_informes_recibidos_matching_fields$$

CREATE PROCEDURE sp_add_informes_recibidos_matching_fields()
BEGIN
    DECLARE dbname VARCHAR(64);
    SET dbname = DATABASE();

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND COLUMN_NAME = 'matching_score'
    ) THEN
        ALTER TABLE informes_recibidos
            ADD COLUMN matching_score INT NULL
            COMMENT 'Puntaje de sugerencia usado al vincular manualmente';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND COLUMN_NAME = 'matching_reasons'
    ) THEN
        ALTER TABLE informes_recibidos
            ADD COLUMN matching_reasons TEXT NULL
            COMMENT 'Motivos del matching (texto o JSON)';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = dbname
          AND TABLE_NAME = 'informes_recibidos'
          AND INDEX_NAME = 'idx_informes_recibidos_matching_score'
    ) THEN
        CREATE INDEX idx_informes_recibidos_matching_score ON informes_recibidos (matching_score);
    END IF;
END$$

DELIMITER ;

CALL sp_add_informes_recibidos_matching_fields();

DROP PROCEDURE IF EXISTS sp_add_informes_recibidos_matching_fields;
