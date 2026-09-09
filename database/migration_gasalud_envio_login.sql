-- Envío Gasalud: login + endpoints + credenciales (idempotente)
INSERT INTO configuracion (clave, valor, descripcion) VALUES
  ('gasalud_envio_activo', '0', 'Envío PDF a API Gasalud: 1=activo, 0=apagado'),
  ('gasalud_api_url', 'http://192.168.0.149:8325/api/v2/Informes', 'POST multipart Informes'),
  ('gasalud_login_url', 'http://192.168.0.149:8325/api/v2/Usuarios/Login', 'POST login JSON → token'),
  ('gasalud_http_method', 'POST', 'Método HTTP Informes'),
  ('gasalud_auth_mode', 'login', 'login|bearer|api_key|basic|none'),
  ('gasalud_auth_token', '', 'Bearer cacheado (auto login)'),
  ('gasalud_token_expires_at', '', 'Expiración token login'),
  ('gasalud_api_key_header', 'X-API-Key', 'Header api_key'),
  ('gasalud_auth_username', 'ApiInformes', 'Usuario Login Gasalud'),
  ('gasalud_auth_password', '4p1iNf0rM3S', 'Password Login Gasalud'),
  ('gasalud_tipo_default', 'pdf', 'Campo Tipo (solo pdf)'),
  ('gasalud_prestador_modo', 'worklist', 'worklist=matrícula PV1 en worklist|matricula|user_id'),
  ('gasalud_trigger', 'al_pacs', 'manual|al_finalizado|al_pacs'),
  ('gasalud_timeout_sec', '60', 'Timeout HTTP segundos'),
  ('gasalud_verify_ssl', '0', 'Verificar SSL (0 típico en LAN http)')
ON DUPLICATE KEY UPDATE
  descripcion = VALUES(descripcion);

-- Si ya existían vacíos, rellenar URL/login/user sin pisar password custom no vacío de forma agresiva:
UPDATE configuracion SET valor = 'http://192.168.0.149:8325/api/v2/Informes'
  WHERE clave = 'gasalud_api_url' AND (valor IS NULL OR valor = '');
UPDATE configuracion SET valor = 'http://192.168.0.149:8325/api/v2/Usuarios/Login'
  WHERE clave = 'gasalud_login_url' AND (valor IS NULL OR valor = '');
UPDATE configuracion SET valor = 'login'
  WHERE clave = 'gasalud_auth_mode' AND (valor IS NULL OR valor = '' OR valor = 'none');
UPDATE configuracion SET valor = 'ApiInformes'
  WHERE clave = 'gasalud_auth_username' AND (valor IS NULL OR valor = '');
UPDATE configuracion SET valor = '4p1iNf0rM3S'
  WHERE clave = 'gasalud_auth_password' AND (valor IS NULL OR valor = '');
UPDATE configuracion SET valor = 'worklist'
  WHERE clave = 'gasalud_prestador_modo' AND (valor IS NULL OR valor = '' OR valor = 'user_id');
UPDATE configuracion SET valor = 'al_pacs'
  WHERE clave = 'gasalud_trigger' AND (valor IS NULL OR valor = '' OR valor = 'manual');
UPDATE configuracion SET valor = 'pdf'
  WHERE clave = 'gasalud_tipo_default' AND (valor IS NULL OR valor = '' OR valor = 'PDF');
UPDATE configuracion SET valor = '0'
  WHERE clave = 'gasalud_verify_ssl' AND valor = '1'
    AND EXISTS (
      SELECT 1 FROM (SELECT valor AS u FROM configuracion WHERE clave = 'gasalud_api_url') t
      WHERE t.u LIKE 'http://%'
    );

SELECT 'migration_gasalud_envio_login OK' AS resultado;
