-- Tabla para almacenar configuraciones FTP por usuario
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

CREATE TABLE IF NOT EXISTS `usuarios_ftp_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` INT(11) NOT NULL COMMENT 'ID del usuario del sistema',
  `nombre_config` VARCHAR(100) NOT NULL COMMENT 'Nombre descriptivo de la configuración',
  `ftp_host` VARCHAR(255) NOT NULL COMMENT 'Servidor FTP',
  `ftp_port` INT(11) NOT NULL DEFAULT 21 COMMENT 'Puerto FTP',
  `ftp_username` VARCHAR(255) NOT NULL COMMENT 'Usuario FTP',
  `ftp_password` TEXT NOT NULL COMMENT 'Contraseña FTP (encriptada)',
  `ftp_remote_path` VARCHAR(500) NOT NULL DEFAULT '/audios/' COMMENT 'Ruta remota donde guardar archivos',
  `ftp_passive_mode` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Modo pasivo (1=activado, 0=desactivado)',
  `ftp_timeout` INT(11) NOT NULL DEFAULT 30 COMMENT 'Timeout en segundos',
  `ftp_retry_attempts` INT(11) NOT NULL DEFAULT 3 COMMENT 'Número de reintentos',
  `ftp_auto_send` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Envío automático (1=activado, 0=desactivado)',
  `activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Configuración activa',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
  PRIMARY KEY (`id`),
  INDEX `idx_usuario_id` (`usuario_id`),
  INDEX `idx_activo` (`activo`),
  CONSTRAINT `fk_usuarios_ftp_config_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configuraciones FTP por usuario del sistema';
