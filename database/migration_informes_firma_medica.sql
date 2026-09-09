-- Migración: Circuito de firma médica de informes (v1.4.0)
-- Estados: transcripto; metadatos de firma; origen; usuarios_firmas; session_type firma; permisos

USE tjsmedical_iddse;

-- 1) ENUM estado + transcripto
ALTER TABLE informes
  MODIFY COLUMN estado ENUM('borrador','transcripto','revisado','firmado','finalizado')
  COLLATE utf8mb4_unicode_ci DEFAULT 'borrador';

ALTER TABLE informes_historial
  MODIFY COLUMN estado_anterior ENUM('borrador','transcripto','revisado','firmado','finalizado')
  COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- 2) Metadatos de firma y origen
SET @db := DATABASE();

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='informes' AND COLUMN_NAME='firmado_por');
SET @sql := IF(@exists=0, 'ALTER TABLE informes ADD COLUMN firmado_por INT NULL DEFAULT NULL COMMENT ''Usuario que firmó'' AFTER fecha_finalizacion', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='informes' AND COLUMN_NAME='firmado_en');
SET @sql := IF(@exists=0, 'ALTER TABLE informes ADD COLUMN firmado_en DATETIME NULL DEFAULT NULL COMMENT ''Fecha/hora de firma'' AFTER firmado_por', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='informes' AND COLUMN_NAME='origen');
SET @sql := IF(@exists=0, 'ALTER TABLE informes ADD COLUMN origen ENUM(''plataforma'',''externo'') NOT NULL DEFAULT ''plataforma'' COMMENT ''Origen del informe'' AFTER estado', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Marcar externos por pdf_path / contenido (es_adjunto si existe se aplica aparte en PHP backfill)
UPDATE informes
SET origen = 'externo'
WHERE origen = 'plataforma'
  AND pdf_path IS NOT NULL AND pdf_path <> ''
  AND (
    contenido_html LIKE '%informe-adjunto%'
    OR contenido_html LIKE '%pdf-viewer-btn%'
    OR contenido_html LIKE '%Informe PDF%'
  );

SET @has_adj := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='informes' AND COLUMN_NAME='es_adjunto');
SET @sql := IF(@has_adj>0,
  'UPDATE informes SET origen = ''externo'' WHERE origen = ''plataforma'' AND es_adjunto = 1',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Tabla de rúbricas / sellos por usuario
CREATE TABLE IF NOT EXISTS usuarios_firmas (
  usuario_id INT NOT NULL,
  imagen_path VARCHAR(512) NULL DEFAULT NULL,
  sello_texto TEXT NULL,
  posicion_json TEXT NULL COMMENT 'JSON: mode, x, y, width',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) mobile_sessions.session_type + firma
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='mobile_sessions' AND COLUMN_NAME='session_type');
SET @sql := IF(@exists>0,
  'ALTER TABLE mobile_sessions MODIFY COLUMN session_type ENUM(''study'',''workspace'',''firma'') DEFAULT ''study''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5) Permisos
INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES
  ('firmarInformes', 'Firmar informes', 'Permite revisar y firmar informes en estado transcripto', 'informes'),
  ('gestionarFirmaPropia', 'Gestionar firma propia', 'Permite capturar/editar rúbrica y sello del usuario', 'informes')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  description = VALUES(description),
  category = VALUES(category);

SELECT 'migration_informes_firma_medica OK' AS resultado;
