-- Migración: SLA estudios recibidos (publicación de informe)
-- Feature off por defecto: sla_activo=0

USE tjsmedical_iddse;

SET @db := DATABASE();

-- 1) Columnas SLA en estudios
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='estudios' AND COLUMN_NAME='local_arrived_at');
SET @sql := IF(@exists=0,
  'ALTER TABLE estudios ADD COLUMN local_arrived_at DATETIME NULL DEFAULT NULL COMMENT ''Primera aparición en PACS local (ancla SLA)'' AFTER fecha_actualizacion',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='estudios' AND COLUMN_NAME='sla_override_horas');
SET @sql := IF(@exists=0,
  'ALTER TABLE estudios ADD COLUMN sla_override_horas INT NULL DEFAULT NULL COMMENT ''Override horas SLA; NULL = plantilla/default'' AFTER local_arrived_at',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='estudios' AND COLUMN_NAME='sla_excluido');
SET @sql := IF(@exists=0,
  'ALTER TABLE estudios ADD COLUMN sla_excluido TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = excluido del monitoreo SLA'' AFTER sla_override_horas',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='estudios' AND COLUMN_NAME='sla_excluido_motivo');
SET @sql := IF(@exists=0,
  'ALTER TABLE estudios ADD COLUMN sla_excluido_motivo VARCHAR(255) NULL DEFAULT NULL AFTER sla_excluido',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='estudios' AND INDEX_NAME='idx_estudios_local_arrived');
SET @sql := IF(@idx=0, 'ALTER TABLE estudios ADD INDEX idx_estudios_local_arrived (local_arrived_at)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Plantillas SLA
CREATE TABLE IF NOT EXISTS sla_plantillas (
  id INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(120) NOT NULL,
  horas INT NOT NULL DEFAULT 72,
  modalidades VARCHAR(255) NULL DEFAULT NULL COMMENT 'CSV modalidades; vacío = todas',
  prioridad INT NOT NULL DEFAULT 100,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_modificacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sla_plantillas_activo_prio (activo, prioridad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Timeline de hitos
CREATE TABLE IF NOT EXISTS study_informe_timeline (
  id BIGINT NOT NULL AUTO_INCREMENT,
  estudios_id INT NOT NULL,
  study_instance_uid VARCHAR(255) NULL DEFAULT NULL,
  orthanc_study_id VARCHAR(100) NULL DEFAULT NULL,
  evento VARCHAR(32) NOT NULL COMMENT 'arrived|assigned|dictated|transcripto|firmado|publicado',
  occurred_at DATETIME NOT NULL,
  source VARCHAR(32) NULL DEFAULT NULL COMMENT 'audio|informe|assignment|sign|pacs|system|webhook|cloner',
  ref_id VARCHAR(64) NULL DEFAULT NULL,
  meta_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_timeline_estudio_evento (estudios_id, evento),
  KEY idx_timeline_evento_at (evento, occurred_at),
  KEY idx_timeline_orthanc (orthanc_study_id),
  KEY idx_timeline_uid (study_instance_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Config (feature OFF)
INSERT INTO configuracion (clave, valor, descripcion) VALUES
  ('sla_activo', '0', 'SLA estudios recibidos: 1=activo (monitoreo UI/API), 0=apagado'),
  ('sla_default_horas', '72', 'Horas SLA por defecto desde llegada a PACS local hasta publicación del informe'),
  ('sla_warning_horas', '24', 'Horas antes del vencimiento para aviso «por vencer»'),
  ('sla_webhook_secret', '', 'Token Bearer para webhook Orthanc OnStableStudy (local-arrived)')
ON DUPLICATE KEY UPDATE
  descripcion = VALUES(descripcion);

-- 5) Permiso
INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES
  ('monitorearSlaEstudios', 'Monitorear SLA estudios', 'Permite ver contador/modal de estudios sin informe publicado dentro del plazo SLA', 'informes')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  description = VALUES(description),
  category = VALUES(category);

-- 6) Backfill ancla (no inventa publicación)
UPDATE estudios
SET local_arrived_at = COALESCE(fecha_creacion, IF(study_date IS NOT NULL, TIMESTAMP(study_date), NULL))
WHERE local_arrived_at IS NULL
  AND (fecha_creacion IS NOT NULL OR study_date IS NOT NULL);

SELECT 'migration_sla_estudios_recibidos OK' AS resultado;
