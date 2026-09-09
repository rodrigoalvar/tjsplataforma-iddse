-- Recepción y Turnero — esquema base (plugin)
-- Catálogos sin prefijo rt_; tablas operativas con prefijo rt_.
-- No altera tablas del núcleo (pacientes, worklist).

CREATE TABLE IF NOT EXISTS `ref_physicians` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(32) NOT NULL,
  `nombre` VARCHAR(200) NOT NULL,
  `telefono` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `matricula` VARCHAR(50) DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ref_physicians_codigo` (`codigo`),
  KEY `idx_ref_physicians_nombre` (`nombre`),
  KEY `idx_ref_physicians_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equipos_imagen` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(32) NOT NULL,
  `nombre` VARCHAR(200) NOT NULL,
  `modalidad` VARCHAR(16) DEFAULT NULL,
  `color` VARCHAR(16) DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_equipos_codigo` (`codigo`),
  KEY `idx_equipos_modalidad` (`modalidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nomencladores` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(64) NOT NULL,
  `nombre` VARCHAR(200) NOT NULL,
  `descripcion` TEXT DEFAULT NULL,
  `tipo` ENUM('base','derivado','obra_social') NOT NULL DEFAULT 'base',
  `nomenclador_origen_id` INT DEFAULT NULL,
  `obra_social_id` INT DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nomencladores_codigo` (`codigo`),
  KEY `idx_nomencladores_tipo` (`tipo`),
  KEY `idx_nomencladores_origen` (`nomenclador_origen_id`),
  KEY `idx_nomencladores_obra_social` (`obra_social_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `obras_sociales` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(32) NOT NULL,
  `nombre` VARCHAR(200) NOT NULL,
  `nomenclador_id` INT DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_obras_sociales_codigo` (`codigo`),
  KEY `idx_obras_sociales_nombre` (`nombre`),
  KEY `idx_obras_sociales_nomenclador` (`nomenclador_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nomenclador_practicas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nomenclador_id` INT NOT NULL,
  `codigo_practica` CHAR(6) NOT NULL,
  `nombre_practica` VARCHAR(200) NOT NULL,
  `importe` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `modalidad_default` VARCHAR(16) DEFAULT NULL,
  `duracion_minutos` INT DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nom_practica` (`nomenclador_id`,`codigo_practica`),
  KEY `idx_nom_practica_codigo` (`codigo_practica`),
  KEY `idx_nom_practica_nombre` (`nombre_practica`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rt_pacientes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `idpaciente` VARCHAR(50) NOT NULL,
  `nombre` VARCHAR(200) NOT NULL,
  `fecha_nacimiento` DATE DEFAULT NULL,
  `sexo` ENUM('M','F','O') DEFAULT NULL,
  `domicilio` TEXT DEFAULT NULL,
  `telefono` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `pacientes_id` INT DEFAULT NULL,
  `vinculo_estado` ENUM('sin_vincular','vinculado','sync_pendiente') NOT NULL DEFAULT 'sin_vincular',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rt_pacientes_idpaciente` (`idpaciente`),
  KEY `idx_rt_pacientes_pacientes_id` (`pacientes_id`),
  KEY `idx_rt_pacientes_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rt_paciente_obras_sociales` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rt_paciente_id` INT NOT NULL,
  `obra_social_id` INT NOT NULL,
  `numero_afiliado` VARCHAR(64) DEFAULT NULL,
  `es_principal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rt_paciente_os` (`rt_paciente_id`,`obra_social_id`),
  KEY `idx_rt_paciente_os_obra` (`obra_social_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equipo_horarios` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `equipo_id` INT NOT NULL,
  `dia_semana` TINYINT NOT NULL,
  `hora_inicio` TIME NOT NULL,
  `hora_fin` TIME NOT NULL,
  `duracion_slot_minutos` INT NOT NULL DEFAULT 30,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_equipo_horario` (`equipo_id`,`dia_semana`),
  KEY `idx_equipo_horario_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equipo_excepciones` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `equipo_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `hora_inicio` TIME DEFAULT NULL,
  `hora_fin` TIME DEFAULT NULL,
  `tipo` ENUM('bloqueo','extra') NOT NULL DEFAULT 'bloqueo',
  `motivo` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_equipo_exc_fecha` (`equipo_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equipo_secuencias` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `equipo_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `ultimo_numero` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_equipo_secuencia` (`equipo_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rt_turnos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rt_paciente_id` INT DEFAULT NULL,
  `equipo_id` INT DEFAULT NULL,
  `fecha` DATE NOT NULL,
  `hora_inicio` TIME NOT NULL,
  `hora_fin` TIME DEFAULT NULL,
  `obra_social_id` INT DEFAULT NULL,
  `numero_afiliado` VARCHAR(64) DEFAULT NULL,
  `canal_origen` ENUM('telefono','whatsapp','presencial','otro') NOT NULL DEFAULT 'presencial',
  `tipo_atencion` ENUM('ambulatorio','internado','particular') DEFAULT NULL,
  `tipo` ENUM('provisorio','confirmado') NOT NULL DEFAULT 'provisorio',
  `estado` ENUM('reservado','confirmado','en_recepcion','en_estudio','completado','cancelado','no_presentado') NOT NULL DEFAULT 'reservado',
  `documentacion_completa` TINYINT(1) NOT NULL DEFAULT 0,
  `documentacion_tipo` JSON DEFAULT NULL,
  `ref_physician_id` INT DEFAULT NULL,
  `ref_physician_codigo_snapshot` VARCHAR(32) DEFAULT NULL,
  `ref_physician_nombre_snapshot` VARCHAR(200) DEFAULT NULL,
  `ref_physician_telefono_snapshot` VARCHAR(30) DEFAULT NULL,
  `ref_physician_email_snapshot` VARCHAR(255) DEFAULT NULL,
  `observaciones` TEXT DEFAULT NULL,
  `accession_number` VARCHAR(64) DEFAULT NULL,
  `worklist_id` INT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rt_turnos_fecha` (`fecha`),
  KEY `idx_rt_turnos_estado` (`estado`),
  KEY `idx_rt_turnos_paciente` (`rt_paciente_id`),
  KEY `idx_rt_turnos_equipo_fecha` (`equipo_id`,`fecha`),
  KEY `idx_rt_turnos_obra_social` (`obra_social_id`),
  KEY `idx_rt_turnos_accession` (`accession_number`),
  KEY `idx_rt_turnos_worklist` (`worklist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rt_turno_practicas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rt_turno_id` INT NOT NULL,
  `orden` INT NOT NULL DEFAULT 1,
  `nomenclador_practica_id` INT NOT NULL,
  `codigo_practica_snapshot` CHAR(6) NOT NULL,
  `nombre_practica_snapshot` VARCHAR(200) NOT NULL,
  `importe_snapshot` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rt_turno_practica_orden` (`rt_turno_id`,`orden`),
  KEY `idx_rt_turno_practica_nom` (`nomenclador_practica_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nomenclador_import_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nomenclador_id` INT NOT NULL,
  `user_id` INT DEFAULT NULL,
  `archivo` VARCHAR(255) DEFAULT NULL,
  `modo` ENUM('upsert','reemplazar') NOT NULL DEFAULT 'upsert',
  `importadas` INT NOT NULL DEFAULT 0,
  `actualizadas` INT NOT NULL DEFAULT 0,
  `desactivadas` INT NOT NULL DEFAULT 0,
  `errores_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nom_import_nom` (`nomenclador_id`),
  KEY `idx_nom_import_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rt_module_meta` (
  `id` TINYINT NOT NULL DEFAULT 1,
  `version` VARCHAR(20) NOT NULL,
  `installed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
