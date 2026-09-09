-- Flags opcionales en tabla configuracion (el código asume valores por defecto si faltan):
--   pacs_cloner_worker_v1_enabled  → 1 = cloner-worker.php procesa (default implícito: sí)
--   pacs_cloner_worker_v2_enabled  → 1 = cloner-worker-v2.php procesa (default implícito: no)
--
-- No es obligatorio ejecutar este archivo: la API y los workers funcionan sin filas previas.

INSERT INTO configuracion (clave, valor, descripcion) VALUES
('pacs_cloner_worker_v1_enabled', '1', 'PACS Cloner worker v1 (bin/cloner-worker.php)'),
('pacs_cloner_worker_v2_enabled', '0', 'PACS Cloner worker v2 (bin/cloner-worker-v2.php)')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
