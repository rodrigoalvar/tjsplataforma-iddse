-- Tabla para registrar informes PDF recibidos desde otros sistemas
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

CREATE TABLE IF NOT EXISTS `informes_recibidos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `accession_number` VARCHAR(100) NOT NULL COMMENT 'Número de acceso DICOM (0008.0050)',
  `pdf_path` VARCHAR(500) NOT NULL COMMENT 'Ruta del archivo PDF recibido',
  `txt_path` VARCHAR(500) NOT NULL COMMENT 'Ruta del archivo TXT con datos DICOM',
  `estudio_id` INT DEFAULT NULL COMMENT 'ID del estudio vinculado (tabla estudios)',
  `patient_name` VARCHAR(255) DEFAULT NULL COMMENT 'Nombre del paciente',
  `patient_id` VARCHAR(100) DEFAULT NULL COMMENT 'ID del paciente',
  `patient_birth_date` DATE DEFAULT NULL COMMENT 'Fecha de nacimiento',
  `patient_sex` CHAR(1) DEFAULT NULL COMMENT 'Sexo (M/F/O)',
  `modality` VARCHAR(10) DEFAULT NULL COMMENT 'Modalidad',
  `referring_physician` VARCHAR(200) DEFAULT NULL COMMENT 'Médico referente',
  `equipment_name` VARCHAR(255) DEFAULT NULL COMMENT 'Nombre del equipo',
  `procedure_date` DATE DEFAULT NULL COMMENT 'Fecha del procedimiento',
  `procedure_time` TIME DEFAULT NULL COMMENT 'Hora del procedimiento',
  `procedure_description` TEXT DEFAULT NULL COMMENT 'Descripción del procedimiento',
  `reason_for_study` TEXT DEFAULT NULL COMMENT 'Razón del estudio',
  `estado` ENUM('recibido', 'procesado', 'vinculado', 'error', 'descartado') DEFAULT 'recibido',
  `error_message` TEXT DEFAULT NULL COMMENT 'Mensaje de error si hay problemas',
  `motivo_descarte` TEXT DEFAULT NULL COMMENT 'Motivo del descarte cuando estado=descartado',
  `descartado_por_usuario_id` INT DEFAULT NULL COMMENT 'Usuario que descarta el informe',
  `fecha_descarte` TIMESTAMP NULL DEFAULT NULL COMMENT 'Fecha/hora del descarte',
  `fecha_recepcion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `fecha_procesamiento` TIMESTAMP NULL DEFAULT NULL,
  `fecha_vinculacion` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_accession` (`accession_number`),
  INDEX `idx_estudio_id` (`estudio_id`),
  INDEX `idx_estado` (`estado`),
  INDEX `idx_fecha_recepcion` (`fecha_recepcion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
