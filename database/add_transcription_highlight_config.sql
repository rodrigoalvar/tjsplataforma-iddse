-- Script SQL para agregar columnas de configuración de resaltado de transcripción
-- Ejecutar este script en la base de datos para agregar los nuevos campos
-- Compatible con MySQL estándar (sin IF NOT EXISTS)

-- Agregar columnas una por una para evitar errores si ya existen
-- Si una columna ya existe, se mostrará un error pero el script continuará

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_throttle_ms INT DEFAULT 30 COMMENT 'Throttle de actualización en ms (10-100)';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_audio_offset DECIMAL(3,2) DEFAULT -0.1 COMMENT 'Offset de audio en segundos (-0.5 a 0.5)';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_scroll_behavior VARCHAR(10) DEFAULT 'smooth' COMMENT 'Comportamiento de scroll: smooth, auto, instant';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_enable_auto_scroll TINYINT(1) DEFAULT 1 COMMENT 'Activar scroll automático';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_active_color VARCHAR(7) DEFAULT '#ffeb3b' COMMENT 'Color de texto activo';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_active_bg VARCHAR(7) DEFAULT '#ffeb3b' COMMENT 'Color de fondo activo';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_hover_color VARCHAR(7) DEFAULT '#1976d2' COMMENT 'Color de texto hover';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_hover_bg VARCHAR(7) DEFAULT '#e3f2fd' COMMENT 'Color de fondo hover';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_font_weight INT DEFAULT 600 COMMENT 'Peso de fuente (400-900)';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_transition_duration DECIMAL(2,1) DEFAULT 0.2 COMMENT 'Duración de transición en segundos (0-1)';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_config_ui_type VARCHAR(10) DEFAULT 'modal' COMMENT 'Tipo de UI para configuración rápida: modal, offcanvas';

ALTER TABLE ai_config
ADD COLUMN transcription_highlight_enable_preview TINYINT(1) DEFAULT 1 COMMENT 'Activar preview en tiempo real';

-- Nota: Si alguna columna ya existe, MySQL mostrará un error pero continuará con las siguientes.
-- Para evitar errores, puedes usar este script alternativo que verifica antes de agregar:

-- DELIMITER $$
-- 
-- CREATE PROCEDURE IF NOT EXISTS add_transcription_highlight_columns()
-- BEGIN
--     DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- Error: Duplicate column name
--     
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_throttle_ms INT DEFAULT 30 COMMENT 'Throttle de actualización en ms (10-100)';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_audio_offset DECIMAL(3,2) DEFAULT -0.1 COMMENT 'Offset de audio en segundos (-0.5 a 0.5)';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_scroll_behavior VARCHAR(10) DEFAULT 'smooth' COMMENT 'Comportamiento de scroll: smooth, auto, instant';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_enable_auto_scroll TINYINT(1) DEFAULT 1 COMMENT 'Activar scroll automático';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_active_color VARCHAR(7) DEFAULT '#ffeb3b' COMMENT 'Color de texto activo';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_active_bg VARCHAR(7) DEFAULT '#ffeb3b' COMMENT 'Color de fondo activo';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_hover_color VARCHAR(7) DEFAULT '#1976d2' COMMENT 'Color de texto hover';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_hover_bg VARCHAR(7) DEFAULT '#e3f2fd' COMMENT 'Color de fondo hover';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_font_weight INT DEFAULT 600 COMMENT 'Peso de fuente (400-900)';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_transition_duration DECIMAL(2,1) DEFAULT 0.2 COMMENT 'Duración de transición en segundos (0-1)';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_config_ui_type VARCHAR(10) DEFAULT 'modal' COMMENT 'Tipo de UI para configuración rápida: modal, offcanvas';
--     ALTER TABLE ai_config ADD COLUMN transcription_highlight_enable_preview TINYINT(1) DEFAULT 1 COMMENT 'Activar preview en tiempo real';
-- END$$
-- 
-- DELIMITER ;
-- 
-- CALL add_transcription_highlight_columns();
-- 
-- DROP PROCEDURE IF EXISTS add_transcription_highlight_columns;
