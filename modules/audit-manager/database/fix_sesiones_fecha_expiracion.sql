-- Ejecutar si install.php no puede añadir columnas (error 1067 en fecha_expiracion).
-- Convierte fecha_expiracion a DATETIME y corrige fechas inválidas.
-- Hacer backup antes en producción.

UPDATE `sesiones`
SET `fecha_expiracion` = DATE_ADD(COALESCE(`fecha_creacion`, NOW()), INTERVAL 24 HOUR)
WHERE `fecha_expiracion` < '2001-01-01';

ALTER TABLE `sesiones`
  MODIFY COLUMN `fecha_expiracion` DATETIME NOT NULL;
