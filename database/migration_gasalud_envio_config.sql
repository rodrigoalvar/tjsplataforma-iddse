-- Seed configuración envío PDF Gasalud (feature off)
USE tjsmedical_iddse;

INSERT INTO configuracion (clave, valor, descripcion) VALUES
  ('gasalud_envio_activo', '0', 'Envío PDF a API Gasalud: 1=activo, 0=apagado'),
  ('gasalud_api_url', '', 'URL endpoint multipart PDF Gasalud'),
  ('gasalud_http_method', 'POST', 'Método HTTP'),
  ('gasalud_auth_mode', 'none', 'none|bearer|api_key|basic'),
  ('gasalud_auth_token', '', 'Bearer o API key'),
  ('gasalud_api_key_header', 'X-API-Key', 'Header para api_key'),
  ('gasalud_auth_username', '', 'Usuario Basic'),
  ('gasalud_auth_password', '', 'Password Basic'),
  ('gasalud_tipo_default', 'PDF', 'Campo Tipo'),
  ('gasalud_prestador_modo', 'user_id', 'user_id|matricula'),
  ('gasalud_trigger', 'manual', 'manual|al_finalizado|al_pacs'),
  ('gasalud_timeout_sec', '60', 'Timeout HTTP segundos'),
  ('gasalud_verify_ssl', '1', 'Verificar SSL')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

SELECT 'migration_gasalud_envio_config OK' AS resultado;
