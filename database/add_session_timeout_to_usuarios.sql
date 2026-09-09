-- ============================================================
-- Migración: timeout de sesión individual por usuario
-- Tabla: usuarios
-- Columna: session_timeout_hours
--   NULL  → usar configuración global (default: 24 h)
--   0     → sin timeout (sesión permanente)
--   N > 0 → sesión de N horas (sliding: se renueva con actividad)
-- ============================================================

ALTER TABLE `usuarios`
  ADD COLUMN IF NOT EXISTS `session_timeout_hours` SMALLINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Timeout de sesión en horas. NULL = default global. 0 = sin timeout (permanente). N = N horas con renovación por actividad.';

-- Valor global por defecto (24 horas)
-- Usa INSERT IGNORE para ser idempotente
INSERT IGNORE INTO `configuracion` (`clave`, `valor`, `descripcion`)
VALUES (
  'session_timeout_horas_default',
  '24',
  'Duración de sesión por defecto en horas (0 = sin timeout). Se aplica cuando el usuario no tiene timeout individual configurado.'
);
