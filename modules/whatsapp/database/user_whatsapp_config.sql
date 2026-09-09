-- Tabla para almacenar configuración de WAHA por usuario
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Permite que cada usuario con permiso administracion_whatsapp configure su propia instancia de WAHA

CREATE TABLE IF NOT EXISTS `user_whatsapp_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `waha_base_url` varchar(255) NOT NULL,
  `waha_api_key` varchar(255) DEFAULT NULL,
  `waha_timeout` int DEFAULT 30,
  `waha_default_session` varchar(100) DEFAULT 'default',
  `waha_default_country_code` varchar(5) DEFAULT '54',
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario_id` (`usuario_id`),
  KEY `idx_usuario_activo` (`usuario_id`, `activo`),
  CONSTRAINT `fk_user_whatsapp_config_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

