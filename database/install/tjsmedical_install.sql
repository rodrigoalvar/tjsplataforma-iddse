-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3306
-- Tiempo de generación: 07-11-2025 a las 13:13:02
-- Versión del servidor: 8.3.0
-- Versión de PHP: 8.2.18

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `tjsmedical`
--

DELIMITER $$
--
-- Funciones
--
DROP FUNCTION IF EXISTS `GetUserHierarchy`$$
CREATE DEFINER=`root`@`localhost` FUNCTION `GetUserHierarchy` (`userId` INT) RETURNS TEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci DETERMINISTIC READS SQL DATA BEGIN
        DECLARE hierarchy TEXT DEFAULT '';
        DECLARE currentUserId INT DEFAULT userId;
        DECLARE parentId INT DEFAULT NULL;
        DECLARE userName VARCHAR(200) DEFAULT '';
        
        WHILE currentUserId IS NOT NULL DO
            SELECT padre_id, CONCAT(nombre, ' ', apellido) 
            INTO parentId, userName
            FROM usuarios 
            WHERE id = currentUserId AND activo = 1;
            
            IF hierarchy = '' THEN
                SET hierarchy = userName;
            ELSE
                SET hierarchy = CONCAT(userName, ' > ', hierarchy);
            END IF;
            
            SET currentUserId = parentId;
        END WHILE;
        
        RETURN hierarchy;
    END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `audios_informe`
--

DROP TABLE IF EXISTS `audios_informe`;
CREATE TABLE IF NOT EXISTS `audios_informe` (
  `id` int NOT NULL AUTO_INCREMENT,
  `informe_id` int DEFAULT NULL COMMENT 'ID del informe asociado (puede ser NULL si es audio independiente)',
  `estudio_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID del estudio en Orthanc',
  `usuario_id` int NOT NULL COMMENT 'ID del usuario que grabó el audio',
  `tipo_grabacion` enum('simple','sincronizada') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tipo de grabación utilizada',
  `nombre_archivo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nombre del archivo de audio',
  `nombre_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre original del archivo',
  `ruta_archivo` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ruta relativa del archivo en el servidor',
  `duracion_segundos` decimal(10,2) DEFAULT NULL COMMENT 'Duración del audio en segundos',
  `tamano_bytes` bigint DEFAULT NULL COMMENT 'Tamaño del archivo en bytes',
  `tipo_mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'audio/webm' COMMENT 'Tipo MIME del archivo',
  `datos_sincronizacion` json DEFAULT NULL COMMENT 'Datos de sincronización palabra-tiempo del módulo sync-editor',
  `transcripcion_texto` longtext COLLATE utf8mb4_unicode_ci COMMENT 'Transcripción completa del audio',
  `calidad_audio` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Calidad del audio (alta, media, baja)',
  `dispositivo_grabacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Información del dispositivo de grabación',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `activo` tinyint(1) DEFAULT '1' COMMENT 'Indica si el audio está activo o fue eliminado',
  PRIMARY KEY (`id`),
  KEY `idx_informe_id` (`informe_id`),
  KEY `idx_estudio_id` (`estudio_id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_tipo_grabacion` (`tipo_grabacion`),
  KEY `idx_fecha_creacion` (`fecha_creacion`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion`
--

DROP TABLE IF EXISTS `configuracion`;
CREATE TABLE IF NOT EXISTS `configuracion` (
  `clave` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` text COLLATE utf8mb4_unicode_ci,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `fecha_modificacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `configuracion`
--

INSERT INTO `configuracion` (`clave`, `valor`, `descripcion`, `fecha_modificacion`) VALUES
('pacs_formato_defecto', 'jpg', 'Formato por defecto para envío a PACS: pdf o jpg', '2025-10-30 17:43:21'),
('paciente_search_type', 'idpaciente', 'Tipo de búsqueda por defecto en paciente.html: idpaciente o id_interno', '2025-11-05 03:12:59');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estudios`
--

DROP TABLE IF EXISTS `estudios`;
CREATE TABLE IF NOT EXISTS `estudios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `paciente_id` int DEFAULT NULL,
  `orthanc_study_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_id_pacs` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_name_pacs` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `study_date` date DEFAULT NULL,
  `study_time` time DEFAULT NULL,
  `modality` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `study_description` text COLLATE utf8mb4_unicode_ci,
  `accession_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referring_physician` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `series_count` int DEFAULT '0',
  `instances_count` int DEFAULT '0',
  `study_instance_uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `viewer_url` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'COMPLETADO',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estudios_paciente` (`paciente_id`),
  KEY `idx_estudios_orthanc` (`orthanc_study_id`),
  KEY `idx_estudios_pacs_patient` (`patient_id_pacs`),
  KEY `idx_estudios_fecha` (`study_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `informes`
--

DROP TABLE IF EXISTS `informes`;
CREATE TABLE IF NOT EXISTS `informes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `estudio_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID del estudio en Orthanc',
  `usuario_id` int NOT NULL COMMENT 'ID del médico que crea el informe',
  `patient_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ID del paciente',
  `patient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre del paciente',
  `modality` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Modalidad del estudio (CT, MR, etc.)',
  `study_description` text COLLATE utf8mb4_unicode_ci COMMENT 'Descripción del estudio',
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Título del informe',
  `contenido_html` longtext COLLATE utf8mb4_unicode_ci COMMENT 'Contenido HTML del informe desde TinyMCE',
  `contenido_texto` text COLLATE utf8mb4_unicode_ci COMMENT 'Contenido en texto plano para búsquedas',
  `estado` enum('borrador','finalizado','revisado','firmado') COLLATE utf8mb4_unicode_ci DEFAULT 'borrador',
  `version` int DEFAULT '1' COMMENT 'Versión del informe para control de cambios',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fecha_finalizacion` timestamp NULL DEFAULT NULL COMMENT 'Fecha cuando se finalizó el informe',
  `notas_revision` text COLLATE utf8mb4_unicode_ci COMMENT 'Notas de revisión o comentarios',
  `study_instance_uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Study Instance UID del estudio DICOM',
  `study_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Study ID del estudio DICOM',
  `series_instance_uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Series Instance UID (opcional)',
  `accession_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Accession Number del estudio',
  `pacs_series_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ID de la serie en Orthanc donde se almacenó el PDF. Usado para eliminar la serie completa al actualizar el informe.',
  `pdf_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Ruta relativa del PDF del informe para visualización/descarga desde portal del paciente',
  `pacs_instance_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pacs_study_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_estudio_id` (`estudio_id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_patient_id` (`patient_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_fecha_creacion` (`fecha_creacion`),
  KEY `idx_informes_estudio` (`estudio_id`),
  KEY `idx_informes_patient` (`patient_id`),
  KEY `idx_informes_modality` (`modality`),
  KEY `idx_informes_estado` (`estado`),
  KEY `idx_informes_fecha` (`fecha_creacion`),
  KEY `idx_informes_study_instance_uid` (`study_instance_uid`),
  KEY `idx_informes_study_id` (`study_id`),
  KEY `idx_informes_accession_number` (`accession_number`),
  KEY `idx_pacs_series_id` (`pacs_series_id`)
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `informes`
--

INSERT INTO `informes` (`id`, `estudio_id`, `usuario_id`, `patient_id`, `patient_name`, `modality`, `study_description`, `titulo`, `contenido_html`, `contenido_texto`, `estado`, `version`, `fecha_creacion`, `fecha_modificacion`, `fecha_finalizacion`, `notas_revision`, `study_instance_uid`, `study_id`, `series_instance_uid`, `accession_number`, `pacs_series_id`, `pdf_path`, `pacs_instance_id`, `pacs_study_id`) VALUES
(84, '1.2.840.113619.2.190.3596.13616334.5932.1744100243.190', 11, '8510279', 'NISTA PABLO OSVALDO', 'MR', 'CEREBRO', 'MR - NISTA PABLO OSVALDO - 7/11/2025', '<p><span style=\"font-size: 18pt;\"><strong>INFORME RX TORAX</strong></span></p>\n<p><strong>Fecha:</strong> 6/11/2025</p>\n<p><strong>Paciente:</strong> NISTA PABLO OSVALDO</p>\n<p><strong>ID:</strong> 8510279</p>', 'INFORME RX TORAX Fecha: 6/11/2025 Paciente: NISTA PABLO OSVALDO ID: 8510279', 'borrador', 1, '2025-11-07 04:46:37', '2025-11-07 04:46:37', NULL, NULL, '1.2.840.113619.2.190.3596.13616334.5932.1744100243.190', '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', NULL, NULL, NULL, NULL, NULL, NULL),
(85, '1.2.840.113619.2.190.3596.13616334.5932.1744100243.190', 10, '8510279', 'NISTA PABLO OSVALDO', 'MR', 'CEREBRO', '', '<p><span style=\"font-size: 18pt;\"><strong>INFORME RX TORAX</strong></span></p>\n<p><strong>Fecha:</strong> 6/11/2025</p>\n<p><strong>Paciente:</strong> NISTA PABLO OSVALDO</p>\n<p><strong>ID:</strong> 8510279</p>', 'INFORME RX TORAX Fecha: 6/11/2025 Paciente: NISTA PABLO OSVALDO ID: 8510279', 'borrador', 1, '2025-11-07 04:47:33', '2025-11-07 04:47:33', NULL, NULL, '1.2.840.113619.2.190.3596.13616334.5932.1744100243.190', '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', NULL, NULL, NULL, NULL, NULL, NULL),
(86, '1.2.840.113619.2.190.3596.13616334.5932.1744100243.190', 12, '8510279', 'NISTA PABLO OSVALDO', 'MR', 'CEREBRO', '', '<h2>INFORME TOMOGR&Aacute;FICO</h2>\n<p><strong>Fecha:</strong> 7/11/2025</p>\n<p><strong>Paciente:</strong> NISTA PABLO OSVALDO</p>\n<p><strong>ID:</strong> 8510279</p>\n<hr>\n<h3>T&Eacute;CNICA</h3>\n<p>[DESCRIBIR_T&Eacute;CNICA]</p>\n<h3>HALLAZGOS</h3>\n<p>[DESCRIBIR_HALLAZGOS]</p>\n<h3>IMPRESI&Oacute;N</h3>\n<p>[CONCLUSIONES]</p>', 'INFORME TOMOGRÁFICO Fecha: 7/11/2025 Paciente: NISTA PABLO OSVALDO ID: 8510279 TÉCNICA [DESCRIBIR_TÉCNICA] HALLAZGOS [DESCRIBIR_HALLAZGOS] IMPRESIÓN [CONCLUSIONES]', 'borrador', 1, '2025-11-07 05:13:07', '2025-11-07 05:13:07', NULL, NULL, '1.2.840.113619.2.190.3596.13616334.5932.1744100243.190', '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `informes_historial`
--

DROP TABLE IF EXISTS `informes_historial`;
CREATE TABLE IF NOT EXISTS `informes_historial` (
  `id` int NOT NULL AUTO_INCREMENT,
  `informe_id` int NOT NULL,
  `version_anterior` int NOT NULL,
  `contenido_html_anterior` longtext COLLATE utf8mb4_unicode_ci,
  `estado_anterior` enum('borrador','finalizado','revisado','firmado') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario_modificacion` int NOT NULL,
  `fecha_cambio` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `motivo_cambio` text COLLATE utf8mb4_unicode_ci COMMENT 'Razón del cambio de versión',
  PRIMARY KEY (`id`),
  KEY `usuario_modificacion` (`usuario_modificacion`),
  KEY `idx_informe_id` (`informe_id`),
  KEY `idx_fecha_cambio` (`fecha_cambio`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `informe_audios`
--

DROP TABLE IF EXISTS `informe_audios`;
CREATE TABLE IF NOT EXISTS `informe_audios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `informe_id` int NOT NULL,
  `archivo_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duracion` decimal(10,2) DEFAULT NULL,
  `sync_data` json DEFAULT NULL,
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audios_informe` (`informe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mobile_sessions`
--

DROP TABLE IF EXISTS `mobile_sessions`;
CREATE TABLE IF NOT EXISTS `mobile_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `study_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `status` enum('active','connected','expired') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `last_activity` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `patient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modality` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `study_date` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `study_description` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_study_id` (`study_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mobile_sessions`
--

INSERT INTO `mobile_sessions` (`id`, `session_id`, `study_id`, `created_by`, `created_at`, `expires_at`, `status`, `last_activity`, `patient_name`, `patient_id`, `modality`, `study_date`, `study_description`) VALUES
(172, 'mobile_1761955558128_o5qvb01r8', 'e093e622-ccbfe230-fcd23d61-43a59705-29414ee6', 6, '2025-11-01 00:05:58', '2025-11-02 03:05:58', 'active', '2025-11-01 00:05:58', 'ACOSTA AYDES DEL VALLE 55A', '21769635', 'MR', '20251022', 'IDDSE MMSS'),
(173, 'mobile_1761955560482_w9my2yzft', 'e093e622-ccbfe230-fcd23d61-43a59705-29414ee6', 6, '2025-11-01 00:06:00', '2025-11-02 03:06:00', 'active', '2025-11-01 00:06:00', 'ACOSTA AYDES DEL VALLE 55A', '21769635', 'MR', '20251022', 'IDDSE MMSS');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mobile_temp_images`
--

DROP TABLE IF EXISTS `mobile_temp_images`;
CREATE TABLE IF NOT EXISTS `mobile_temp_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `temp_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','accepted','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mobile_temp_images`
--

INSERT INTO `mobile_temp_images` (`id`, `session_id`, `temp_path`, `file_name`, `file_size`, `uploaded_at`, `status`) VALUES
(1, 'mobile_1760766497488_gy78fhd2u', 'uploads/temp_mobile/68f32a3ee23eb_1760766526.jpg', '68f32a3ee23eb_1760766526.jpg', 117453, '2025-10-18 05:48:46', 'pending'),
(2, 'mobile_1760766549393_dykvjwcu5', 'uploads/temp_mobile/68f32a6dbb33a_1760766573.jpg', '68f32a6dbb33a_1760766573.jpg', 82072, '2025-10-18 05:49:33', 'pending'),
(3, 'mobile_1760766811195_f7zmgpcuu', 'uploads/temp_mobile/68f32b7fcb9dd_1760766847.jpg', '68f32b7fcb9dd_1760766847.jpg', 95255, '2025-10-18 05:54:07', 'pending'),
(4, 'mobile_1760766811195_f7zmgpcuu', 'uploads/temp_mobile/68f32b91a3d22_1760766865.jpg', '68f32b91a3d22_1760766865.jpg', 79480, '2025-10-18 05:54:25', 'pending'),
(5, 'mobile_1760767041944_56pne8k9i', 'uploads/temp_mobile/68f32c612971d_1760767073.jpg', '68f32c612971d_1760767073.jpg', 128708, '2025-10-18 05:57:53', 'pending'),
(6, 'mobile_1760767336451_72p2emtdv', 'uploads/temp_mobile/68f32d84dd785_1760767364.jpg', '68f32d84dd785_1760767364.jpg', 92280, '2025-10-18 06:02:44', 'accepted'),
(7, 'mobile_1760767388415_dp6zbngbq', 'uploads/temp_mobile/68f32dc5c4daa_1760767429.jpg', '68f32dc5c4daa_1760767429.jpg', 77498, '2025-10-18 06:03:49', 'accepted'),
(8, 'mobile_1760767388415_dp6zbngbq', 'uploads/temp_mobile/68f32dc876e85_1760767432.jpg', '68f32dc876e85_1760767432.jpg', 132703, '2025-10-18 06:03:52', 'accepted'),
(9, 'mobile_1760767388415_dp6zbngbq', 'uploads/temp_mobile/68f32dca3bd1b_1760767434.jpg', '68f32dca3bd1b_1760767434.jpg', 125519, '2025-10-18 06:03:54', 'accepted'),
(10, 'mobile_1760767388415_dp6zbngbq', 'uploads/temp_mobile/68f32dcbd978a_1760767435.jpg', '68f32dcbd978a_1760767435.jpg', 91249, '2025-10-18 06:03:55', 'accepted'),
(11, 'mobile_1760767618367_kpvtp4rn2', 'uploads/temp_mobile/68f32e96e2b1e_1760767638.jpg', '68f32e96e2b1e_1760767638.jpg', 80641, '2025-10-18 06:07:18', 'rejected'),
(12, 'mobile_1760767618367_kpvtp4rn2', 'uploads/temp_mobile/68f32eb2e7127_1760767666.jpg', '68f32eb2e7127_1760767666.jpg', 108891, '2025-10-18 06:07:46', 'rejected'),
(13, 'mobile_1760767618367_kpvtp4rn2', 'uploads/temp_mobile/68f32eb3d492d_1760767667.jpg', '68f32eb3d492d_1760767667.jpg', 72620, '2025-10-18 06:07:47', 'accepted'),
(14, 'mobile_1760767618367_kpvtp4rn2', 'uploads/temp_mobile/68f32efe0ef1d_1760767742.jpg', '68f32efe0ef1d_1760767742.jpg', 121866, '2025-10-18 06:09:02', 'accepted'),
(15, 'mobile_1760767844875_dxjlu162a', 'uploads/temp_mobile/68f32f7c34565_1760767868.jpg', '68f32f7c34565_1760767868.jpg', 129341, '2025-10-18 06:11:08', 'accepted'),
(16, 'mobile_1760767844875_dxjlu162a', 'uploads/temp_mobile/68f32f7d22f09_1760767869.jpg', '68f32f7d22f09_1760767869.jpg', 124540, '2025-10-18 06:11:09', 'accepted'),
(17, 'mobile_1760767844875_dxjlu162a', 'uploads/temp_mobile/68f32f7e0d233_1760767870.jpg', '68f32f7e0d233_1760767870.jpg', 127376, '2025-10-18 06:11:10', 'accepted'),
(18, 'mobile_1760768095997_rcr2tt56t', 'uploads/temp_mobile/68f3307bca593_1760768123.jpg', '68f3307bca593_1760768123.jpg', 44460, '2025-10-18 06:15:23', 'accepted'),
(19, 'mobile_1760768095997_rcr2tt56t', 'uploads/temp_mobile/68f3307ca6542_1760768124.jpg', '68f3307ca6542_1760768124.jpg', 86155, '2025-10-18 06:15:24', 'accepted'),
(20, 'mobile_1760796525725_5zgyb12uh', 'uploads/temp_mobile/68f39fd388ba0_1760796627.jpg', '68f39fd388ba0_1760796627.jpg', 73841, '2025-10-18 14:10:27', 'rejected'),
(21, 'mobile_1760796525725_5zgyb12uh', 'uploads/temp_mobile/68f39febc4fce_1760796651.jpg', '68f39febc4fce_1760796651.jpg', 74882, '2025-10-18 14:10:51', 'pending'),
(22, 'mobile_1760796803678_slvqyztw1', 'uploads/temp_mobile/68f3a094ab414_1760796820.jpg', '68f3a094ab414_1760796820.jpg', 79878, '2025-10-18 14:13:40', 'rejected'),
(23, 'mobile_1760796803678_slvqyztw1', 'uploads/temp_mobile/68f3a0ce1d1a7_1760796878.jpg', '68f3a0ce1d1a7_1760796878.jpg', 105882, '2025-10-18 14:14:38', 'rejected'),
(24, 'mobile_1760797015124_m7ppj8xu3', 'uploads/temp_mobile/68f3a172af3a4_1760797042.jpg', '68f3a172af3a4_1760797042.jpg', 105789, '2025-10-18 14:17:22', 'accepted'),
(25, 'mobile_1760993212390_uuiuahx9n', 'uploads/temp_mobile/68f6a003cb85e_1760993283.jpg', '68f6a003cb85e_1760993283.jpg', 101755, '2025-10-20 20:48:03', 'accepted'),
(26, 'mobile_1760993212390_uuiuahx9n', 'uploads/temp_mobile/68f6a00511125_1760993285.jpg', '68f6a00511125_1760993285.jpg', 114893, '2025-10-20 20:48:05', 'accepted'),
(27, 'mobile_1761242367118_5x5m3vr2a', 'uploads/temp_mobile/68fa6d4711f48_1761242439.jpg', '68fa6d4711f48_1761242439.jpg', 115173, '2025-10-23 18:00:39', 'accepted'),
(28, 'mobile_1761933629421_6oq5p9jut', 'uploads/temp_mobile/6904f9806f71a_1761933696.jpg', '6904f9806f71a_1761933696.jpg', 104320, '2025-10-31 18:01:36', 'accepted'),
(29, 'mobile_1761955560479_coi1pjyx9', 'uploads/temp_mobile/69054f4907ca6_1761955657.jpg', '69054f4907ca6_1761955657.jpg', 129391, '2025-11-01 00:07:37', 'accepted');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pacientes`
--

DROP TABLE IF EXISTS `pacientes`;
CREATE TABLE IF NOT EXISTS `pacientes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_interno` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `idpaciente` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domicilio` text COLLATE utf8mb4_unicode_ci,
  `search_enabled_types` enum('idpaciente','id_interno','ambos') COLLATE utf8mb4_unicode_ci DEFAULT 'idpaciente' COMMENT 'Tipos de ID habilitados para búsqueda: idpaciente (solo PACS), id_interno (solo interno), ambos (cualquiera)',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `activo` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_interno` (`id_interno`),
  KEY `idx_pacientes_search_types` (`search_enabled_types`),
  KEY `idx_pacientes_idpaciente` (`idpaciente`),
  KEY `idx_pacientes_nombre` (`nombre`),
  KEY `idx_pacientes_email` (`email`(250))
) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `pacientes`
--

INSERT INTO `pacientes` (`id`, `id_interno`, `idpaciente`, `nombre`, `telefono`, `email`, `domicilio`, `search_enabled_types`, `fecha_creacion`, `fecha_actualizacion`, `activo`) VALUES
(1, 'PAC-20251004-0001', '8134697', 'PACIENTE ANONIMIZADO', NULL, NULL, NULL, 'ambos', '2025-10-04 06:29:56', '2025-11-05 03:12:10', 1),
(9, 'PAC-20251105-001', '21769635', 'ACOSTA AYDES DEL VALLE', '543815883234', NULL, NULL, 'idpaciente', '2025-11-05 03:17:00', '2025-11-05 04:08:34', 1),
(4, 'PAC-20251009-0002', '3056421620301', 'VICENTE^SARA', NULL, NULL, NULL, 'idpaciente', '2025-10-09 15:04:49', '2025-11-05 00:21:56', 1),
(5, 'PAC-20251009-0003', '1235564789', 'POCON^WENDY', NULL, NULL, NULL, 'idpaciente', '2025-10-09 21:30:20', '2025-10-09 21:30:20', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `plantillas`
--

DROP TABLE IF EXISTS `plantillas`;
CREATE TABLE IF NOT EXISTS `plantillas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID único de la plantilla (ej: rx_torax)',
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nombre descriptivo de la plantilla',
  `contenido_html` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Contenido HTML de la plantilla',
  `usuario_id` int DEFAULT NULL COMMENT 'ID del usuario que creó la plantilla (NULL para plantillas del sistema)',
  `activo` tinyint(1) DEFAULT '1' COMMENT 'Indica si la plantilla está activa',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
  `fecha_modificacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última modificación',
  PRIMARY KEY (`id`),
  UNIQUE KEY `template_id` (`template_id`),
  KEY `idx_template_id` (`template_id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_activo` (`activo`),
  KEY `idx_fecha_creacion` (`fecha_creacion`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `plantillas`
--

INSERT INTO `plantillas` (`id`, `template_id`, `nombre`, `contenido_html`, `usuario_id`, `activo`, `fecha_creacion`, `fecha_modificacion`) VALUES
(1, 'rx', 'Radiografía Simple', '<h2>INFORME RADIOGRÁFICO</h2>\r\n<p><strong>Fecha:</strong> [FECHA]</p>\r\n<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>\r\n<p><strong>ID:</strong> [ID_PACIENTE]</p>\r\n<hr>\r\n<h3>TÉCNICA</h3>\r\n<p>[DESCRIBIR_TÉCNICA]</p>\r\n<h3>HALLAZGOS</h3>\r\n<p>[DESCRIBIR_HALLAZGOS]</p>\r\n<h3>IMPRESIÓN</h3>\r\n<p>[CONCLUSIONES]</p>', NULL, 1, '2025-10-31 23:44:19', '2025-10-31 23:44:19'),
(2, 'ct', 'Tomografía Computada', '<h2>INFORME TOMOGRÁFICO</h2>\r\n<p><strong>Fecha:</strong> [FECHA]</p>\r\n<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>\r\n<p><strong>ID:</strong> [ID_PACIENTE]</p>\r\n<hr>\r\n<h3>TÉCNICA</h3>\r\n<p>[DESCRIBIR_TÉCNICA]</p>\r\n<h3>HALLAZGOS</h3>\r\n<p>[DESCRIBIR_HALLAZGOS]</p>\r\n<h3>IMPRESIÓN</h3>\r\n<p>[CONCLUSIONES]</p>', NULL, 1, '2025-10-31 23:44:19', '2025-10-31 23:44:19'),
(3, 'RX TORAX NORMAL', 'RX TORAX NORMAL', '<p><span style=\"font-size: 18pt;\"><strong>INFORME RX TORAX</strong></span></p>\n<p><strong>Fecha:</strong> 6/11/2025</p>\n<p><strong>Paciente:</strong> NISTA PABLO OSVALDO</p>\n<p><strong>ID:</strong> 8510279</p>', 10, 1, '2025-11-06 05:06:13', '2025-11-07 01:51:37'),
(4, 'RX CABEZA', 'RX CABEZA Y CUELLO', '<p><span style=\"font-size: 18pt;\"><strong>INFORME DE RX CABEZA Y CUELLO</strong></span></p>\n<p><strong>Fecha:</strong> [FECHA]</p>\n<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>\n<p><strong>ID:</strong> [ID_PACIENTE]</p>', 11, 1, '2025-11-06 05:10:24', '2025-11-07 01:00:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sesiones`
--

DROP TABLE IF EXISTS `sesiones`;
CREATE TABLE IF NOT EXISTS `sesiones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `token_sesion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_expiracion` timestamp NOT NULL,
  `activa` tinyint(1) DEFAULT '1',
  `ultima_actividad` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_sesion` (`token_sesion`),
  KEY `idx_sesiones_token` (`token_sesion`),
  KEY `idx_sesiones_usuario` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=839 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `sesiones`
--

INSERT INTO `sesiones` (`id`, `usuario_id`, `token_sesion`, `fecha_creacion`, `fecha_expiracion`, `activa`, `ultima_actividad`) VALUES
(1, 2, 'f00c650759907ae91c2e8106fef7d3a1467a1c44e6f29485c4d4a921cb754fd2', '2025-10-03 00:49:06', '2025-10-04 03:49:06', 0, '2025-10-04 03:53:52'),
(2, 2, 'f2cb9cd14eb2acd2fcab982010f3671efec67b6bdcb64166653d5b56edf14ce6', '2025-10-03 01:20:43', '2025-10-04 04:20:43', 0, '2025-10-04 04:28:04'),
(3, 2, '5ba446a3961e88d26fbf709e1c5ca19a38cfe3c8a690e50ebdc3c60ac11497a9', '2025-10-03 01:25:20', '2025-10-04 04:25:20', 0, '2025-10-04 04:28:04'),
(4, 2, '44dc60db27e85e53e483a8315af780c46b27cbce7e6e11c2907588c5b1dba054', '2025-10-03 01:51:02', '2025-10-04 04:51:02', 0, '2025-10-04 05:09:16'),
(5, 2, '9e7a6b04f991ca0f0298a97b37862e499fdd6807549acbd405e3610216a63f0a', '2025-10-03 01:58:47', '2025-10-04 04:58:47', 0, '2025-10-04 05:09:16'),
(6, 2, '37f00fc564c14d2d41859932b2530fa8c616783b0b759c7e6abca9ae59c7f2ab', '2025-10-03 02:30:33', '2025-10-04 05:30:33', 0, '2025-10-04 06:28:53'),
(7, 2, 'd9f9ce68a0493830eb397b0022e08fecb00102c901bd7bcd6d7ac7ec134ab694', '2025-10-03 11:43:11', '2025-10-04 14:43:11', 0, '2025-10-08 18:42:36'),
(8, 2, '91e87610e677bd0a9c3d4ca616892d1cf5376191273f98dc494be5a8cb76e114', '2025-10-03 11:43:23', '2025-10-04 14:43:23', 0, '2025-10-08 18:42:36'),
(9, 2, 'a969abcbd37380bea594356cd043d8d601b6987111789ae3b7e94427734153fe', '2025-10-03 11:43:37', '2025-10-04 14:43:37', 0, '2025-10-08 18:42:36'),
(10, 2, '4ba09b1c0ed415df745d6f696bea4d8f8a9ae5485d5812f21a7592e22ad0968b', '2025-10-03 11:44:34', '2025-10-04 14:44:34', 0, '2025-10-08 18:42:36'),
(11, 2, '01b5626d14405713798496275c74c4fcc61e7552426f74d652ab177eb96f0674', '2025-10-03 11:44:54', '2025-10-04 14:44:54', 0, '2025-10-08 18:42:36'),
(14, 2, 'ec3ae9882ab0368464112ff71444479529ccb0433c0293dbfbdee481d7b5947f', '2025-10-03 14:49:58', '2025-10-04 17:49:58', 0, '2025-10-08 18:42:36'),
(15, 2, 'e3f0038a1c84b042a381638929f787501d6c59cc4528288e98e407dcfa282ee5', '2025-10-03 14:50:31', '2025-10-04 17:50:31', 0, '2025-10-08 18:42:36'),
(16, 2, 'ad79fc51c8aefe84cf0643a8ed66de6552edadb6e9ae753879b385e3080294ad', '2025-10-03 14:54:37', '2025-10-04 17:54:37', 0, '2025-10-08 18:42:36'),
(18, 2, '1a022acc0ae4b9452796035a7002d9bc36c4a8b3292afad90b8698a492da6c94', '2025-10-03 16:30:18', '2025-10-04 19:30:18', 0, '2025-10-08 18:42:36'),
(19, 2, '8ea4cf5b3b5e12886e27f546799a61ad6565fbd23e519b194e52e311435579ae', '2025-10-03 16:32:32', '2025-10-04 19:32:32', 0, '2025-10-08 18:42:36'),
(20, 2, '049af9cead53207c5234447f58f0f2dc7a5df1fd02de4e7c6866862cf97cb1ce', '2025-10-03 16:52:48', '2025-10-04 19:52:48', 0, '2025-10-08 18:42:36'),
(21, 2, '052ef3b75ca5e0b1646586caa6b882c18adb8f95ccb2319497dbb372fd3496b8', '2025-10-03 16:53:33', '2025-10-04 19:53:33', 0, '2025-10-08 18:42:36'),
(22, 2, 'bb6c02728c081f417c1087311e3b101f0a81bc713f4088e086ac7d08d84a8c1d', '2025-10-03 17:48:52', '2025-10-04 20:48:52', 0, '2025-10-08 18:42:36'),
(23, 2, 'b5b0ad4058062c11b4ada7f92d6f97552d47fa9e9dab230b76588a343122323f', '2025-10-03 18:36:36', '2025-10-04 21:36:36', 0, '2025-10-08 18:42:36'),
(25, 2, '75d278bfcb9274220426cf5cba24ea91d5870871bfc551c02c297a760a70b3d6', '2025-10-03 19:48:19', '2025-10-04 22:48:19', 0, '2025-10-03 19:58:56'),
(26, 2, 'fa006610445ffe287e5dbd4b2ed369f9accdc018104edee7113c8205ccad92b9', '2025-10-03 19:59:15', '2025-10-04 22:59:15', 0, '2025-10-03 20:00:18'),
(27, 2, '256f5cab95edfc38956e0634a59af9c512ff3f9355fa1ba2bb2198b73e30baca', '2025-10-03 20:00:28', '2025-10-04 23:00:28', 0, '2025-10-03 20:00:41'),
(28, 2, 'a74d14a4c3e40e66983b2c2c1903d1b6535176c73c86fe14b58b1afa6bfe5655', '2025-10-03 20:32:48', '2025-10-04 23:32:48', 0, '2025-10-03 20:41:29'),
(29, 2, '244b54f00adbc5bb1e5b993c64792af5805fc45504ee5dc30b066d3ccb218d52', '2025-10-03 20:41:36', '2025-10-04 23:41:36', 0, '2025-10-08 18:42:36'),
(30, 2, 'fbe0d998a94aa92778a14167febb4c6b72f766b5309d9eb4fc2187f3a53fe848', '2025-10-03 20:42:20', '2025-10-04 23:42:20', 0, '2025-10-08 18:42:36'),
(31, 2, 'df16520dd43f62de5469f0842d3509b6359810c5ad4dcdc656b0c5a0161547cd', '2025-10-03 20:50:26', '2025-10-04 23:50:26', 0, '2025-10-03 20:52:45'),
(32, 2, '9984f9ca7dccc8c9b8ffd5b2798a76a0ca3775b528b0fa37274c4c248d107d67', '2025-10-03 21:52:17', '2025-10-05 00:52:17', 0, '2025-10-03 21:53:00'),
(33, 2, 'a9567438896bb40c09bf09b35ee50599604b2e4ad7e624c296f6ed0750b932ef', '2025-10-03 21:53:24', '2025-10-05 00:53:24', 0, '2025-10-03 22:29:41'),
(34, 2, '572a9a07d4e8a70b64c96fe889aa1a5999618b9d2d5cf8ac5552992d6f68558b', '2025-10-03 23:10:03', '2025-10-05 02:10:03', 0, '2025-10-03 23:11:04'),
(35, 2, 'c11dceff265893bdef5151b69471fe104ed7257f2d3c8377ae8ecf9fbb23f6e4', '2025-10-03 23:11:37', '2025-10-05 02:11:37', 0, '2025-10-03 23:36:07'),
(36, 2, '32e390dfee0fd73d9613f6eab77017dbe39027f289f05bb8521e145f5a7615ee', '2025-10-03 23:36:14', '2025-10-05 02:36:14', 0, '2025-10-03 23:36:57'),
(37, 2, '9bdda7ff9515d980f0904ed28739d8603402eee0eba2bc0e67d5d6eee048e0f0', '2025-10-03 23:39:49', '2025-10-05 02:39:49', 0, '2025-10-08 18:42:36'),
(38, 2, 'fd45220de0945f272c954aec7b66ba58ab31fb3aca443978ab03f9faaaf1aa80', '2025-10-03 23:40:44', '2025-10-05 02:40:44', 0, '2025-10-03 23:41:13'),
(39, 2, '594034b69e0d3bce1062477ecaa196c057d6f64ac866d9392575dbf336c4f8b4', '2025-10-03 23:47:59', '2025-10-05 02:47:59', 0, '2025-10-03 23:55:20'),
(40, 2, '97b1bfc84a1ab3abf9f156c0a28a3b524274a181343f494f09d6efc05a224646', '2025-10-03 23:55:44', '2025-10-05 02:55:44', 0, '2025-10-08 18:42:36'),
(41, 2, '540c196ee576c70f4f21f89a6ba4416ef60a437b9f57b0efa44241b9b6bc0217', '2025-10-04 01:25:36', '2025-10-05 04:25:36', 0, '2025-10-08 18:42:36'),
(42, 2, '691d3baffa2afb6f815cba0cb29b2d9a89f0d8543114bce9c984491b4af19288', '2025-10-04 01:30:48', '2025-10-05 04:30:48', 0, '2025-10-08 18:42:36'),
(43, 2, '09435a8df3dec2916079d2f375583e9c29df5d44dae98920eeeefb85c19d3d77', '2025-10-04 01:32:14', '2025-10-05 04:32:14', 0, '2025-10-08 18:42:36'),
(44, 2, 'a9c07912ba44b440f16780554b4af98db99d4349fc6c0049603331692eea8d5c', '2025-10-04 01:43:20', '2025-10-05 04:43:20', 0, '2025-10-08 18:42:36'),
(45, 2, '4817ecc5042dcfccd8eb6220a644dbc31937ab7da3d9e5d5412bd418d78a784e', '2025-10-04 02:06:43', '2025-10-05 05:06:43', 0, '2025-10-08 18:42:36'),
(46, 2, 'b94aabba94c0a86df76f580e319cd22268e58b955a11a3750a8433cdb86cd1ff', '2025-10-04 02:07:57', '2025-10-05 05:07:57', 0, '2025-10-04 03:16:13'),
(47, 2, 'a1e48dcee64a53875d9e955a9378ff122c3592862d429b4c9ddcd018441c5b77', '2025-10-04 03:16:21', '2025-10-05 06:16:21', 0, '2025-10-04 03:31:13'),
(48, 2, 'e34f996f43f8f5a632686e9916ad6c7e54d7591f354145b3cd18fc47841c47ec', '2025-10-04 03:31:20', '2025-10-05 06:31:20', 0, '2025-10-04 03:53:19'),
(49, 2, '4a24d832f0c48372220c6e1c68be786fc04780e0abf3a53c9df453d0553304ca', '2025-10-04 03:53:52', '2025-10-05 06:53:52', 0, '2025-10-04 04:01:35'),
(50, 2, '0802e8627e0c270af09ff8b01eaec802395be772488fe76d645be6c93de51e80', '2025-10-04 04:01:56', '2025-10-05 07:01:56', 0, '2025-10-04 04:11:09'),
(51, 2, '017523ce02cc92f357946de0c143f0da647cd95dfc4298cabec9fa46db1bc3e1', '2025-10-04 04:11:17', '2025-10-05 07:11:17', 0, '2025-10-08 18:42:36'),
(52, 2, 'ff8efac3f132d4fd10f095b69647a5f588226d523f67c39e95ad199bfb2d38ee', '2025-10-04 04:18:06', '2025-10-05 07:18:06', 0, '2025-10-04 04:25:53'),
(53, 2, '3d42cbb1849aa5898d834a55257d479a46bcf1665c3330e948c2063acbab0254', '2025-10-04 04:28:04', '2025-10-05 07:28:04', 0, '2025-10-04 04:30:38'),
(54, 2, 'a5862f1ce31ecd675d7cd727000b7d36a701e5bf9f834a3d6959e639b29599fe', '2025-10-04 04:30:58', '2025-10-05 07:30:58', 0, '2025-10-04 05:08:56'),
(55, 2, '34895945bba561e6ea748bcea8ca4d5600499b877ca5f8f469ebbe1eb5f46982', '2025-10-04 05:09:16', '2025-10-05 08:09:16', 0, '2025-10-04 05:14:33'),
(56, 2, '251d3a743003b38f8647293d6315242176bea4c65d715461923a6c0e54a2b20c', '2025-10-04 05:14:52', '2025-10-05 08:14:52', 0, '2025-10-04 06:28:47'),
(57, 2, '3c71f27cd51757cf44ea75b8170de8df5b09edacdfa6b1772d8550f572e08eb2', '2025-10-04 06:28:53', '2025-10-05 09:28:53', 0, '2025-10-04 06:29:12'),
(58, 2, '714987d7ee7330bd0537d07447969d29199d41d7343a7a2854bc277519a55d6f', '2025-10-04 06:29:28', '2025-10-05 09:29:28', 0, '2025-10-04 06:31:42'),
(59, 2, '31347a5bda4b8c4192191fa61019f0de4fd56f2b9e826241c35383b61ee54c64', '2025-10-04 06:32:01', '2025-10-05 09:32:01', 0, '2025-10-04 06:34:26'),
(60, 2, 'f81d8164e52a425ee70a2e373b99346fea1953a4c1cf271dedd741ec5eb7d1e5', '2025-10-04 06:34:51', '2025-10-05 09:34:51', 0, '2025-10-08 18:42:36'),
(61, 2, 'fd357b04673055f5ad852cb4f7f22c81e46ba379084e2d43b7a1e15ca44b733a', '2025-10-04 06:35:39', '2025-10-05 09:35:39', 0, '2025-10-04 06:36:48'),
(62, 2, '8790d3dedcf035f6ee7298ba85380337a510dc4eb5a382aee9952dc4e140a1f0', '2025-10-04 06:37:11', '2025-10-05 09:37:11', 0, '2025-10-04 07:06:36'),
(63, 2, '4b6e29a8d48b43c2b3d80ad0eb65d1502b977df071b832dc4b6a02a12634c48c', '2025-10-04 07:06:42', '2025-10-05 10:06:42', 0, '2025-10-04 11:46:01'),
(64, 2, '9872797b45d8154016760914a569f312923bd137558bdb4df5313ac27d6d0696', '2025-10-04 11:46:23', '2025-10-05 14:46:23', 0, '2025-10-08 18:42:36'),
(65, 2, '5bdeb8b744037dc10f66f3d80f80b1fb9323fd548e3d361fe1d08d450ca45d9e', '2025-10-08 18:42:36', '2025-10-09 21:42:36', 0, '2025-10-09 21:43:34'),
(66, 2, '5a86fdb74eeec028916946b1cbc5650cc894e5e7f7b517762029243b402ee785', '2025-10-09 11:23:37', '2025-10-10 14:23:37', 0, '2025-10-10 14:24:57'),
(67, 2, '06bfbb57f212d2dcc91ccd3167010839bd81706dabfed17734a4d220754ead0d', '2025-10-09 12:16:44', '2025-10-10 15:16:44', 0, '2025-10-10 15:21:48'),
(68, 2, '75b37a2cf01f2fc72b0d0de9ce843ba8b08609dc9b972e923a90aa7202a76dbd', '2025-10-09 15:22:40', '2025-10-10 18:22:40', 0, '2025-10-10 18:40:44'),
(69, 2, '7fc073dde43e1d4946fc498da9743f7f99089abbccd0d5483d925423c8cafed6', '2025-10-09 15:34:28', '2025-10-10 18:34:28', 0, '2025-10-10 18:40:44'),
(70, 2, '32414c4952e782ecfa5fb78c78b6f21f81080832a9a2bee51ad5ebdde1d8783a', '2025-10-09 21:28:36', '2025-10-11 00:28:36', 0, '2025-10-11 00:31:59'),
(71, 2, 'bb58be5ba817a97842f4649e4aabcd893ab1f4ddfcd408512519975f41c50aff', '2025-10-09 21:43:34', '2025-10-11 00:43:34', 0, '2025-10-11 01:14:54'),
(72, 2, 'c66bf7d53b5f6f68f69d2aa4d7fbbdb0a41a52d970e3664bb595f2182ac98f8f', '2025-10-09 21:45:41', '2025-10-11 00:45:41', 0, '2025-10-11 01:14:54'),
(73, 2, '2ca14be61205698836a7cc98fa303e82ae214b075db774dd0bdcdc38cfdccf1e', '2025-10-09 21:49:59', '2025-10-11 00:49:59', 0, '2025-10-11 01:14:54'),
(74, 2, '149cd7caffaa5d7da4e545b8e8d1cca8849d868bd38d20fe2a738356f1e3ea15', '2025-10-09 21:53:45', '2025-10-11 00:53:45', 0, '2025-10-11 01:14:54'),
(75, 2, '8c3ff415d5f7917b8968465ca8f582461aeaaad9af92284f6d4cb055cc0d22c9', '2025-10-09 21:55:41', '2025-10-11 00:55:41', 0, '2025-10-11 01:14:54'),
(76, 2, 'c1dc82ed3e56fc5109524f0cf97e11219cd84d258ac5371aa30b830f35bfe0df', '2025-10-09 23:05:42', '2025-10-11 02:05:42', 0, '2025-10-11 13:22:28'),
(77, 2, '546d2e348f713264cbcc7f92ae452d8a9fe5f0982d33a10ab152421956049aee', '2025-10-10 04:17:49', '2025-10-11 07:17:49', 0, '2025-10-11 13:22:28'),
(78, 2, '17cc2fc4f15b80f696d2fd3e3b26746ea28149818662f409638813e91c8077a1', '2025-10-10 04:25:55', '2025-10-11 07:25:55', 0, '2025-10-11 13:22:28'),
(79, 2, '8d4cae9b4d1a7f4c2afca53cb56a8598396c8fec1224730cee5ac41d80a32c7c', '2025-10-10 04:41:57', '2025-10-11 07:41:57', 0, '2025-10-11 13:22:28'),
(80, 2, 'a2d40e3eb5d1266e3e96af4990bca89ec2141b72a45390f10d02ad541cb8a496', '2025-10-10 04:49:42', '2025-10-11 07:49:42', 0, '2025-10-11 13:22:28'),
(81, 2, '9b548c32894f8d8c5c82d6e757cdf70993dc0f1cdcf6d2c8bd3ae325fbca4343', '2025-10-10 05:06:57', '2025-10-11 08:06:57', 0, '2025-10-11 13:22:28'),
(82, 2, '84f5c399b63d0d3497092a9f1cd3eef8567c86de47c851be92c5f2b6ef2f4a06', '2025-10-10 11:48:19', '2025-10-11 14:48:19', 0, '2025-10-10 11:51:50'),
(83, 2, 'd8354d42b4b1df0223bbe62dfe02bd05a28ae3c4d088bcf9ef9913a88336f487', '2025-10-10 11:51:57', '2025-10-11 14:51:57', 0, '2025-10-10 12:23:22'),
(84, 2, '049491edc29f2041ffda32fc2e8d838c1e83276c242dafcef0d5bc8e6684b3ba', '2025-10-10 12:23:44', '2025-10-11 15:23:44', 0, '2025-10-10 12:34:32'),
(85, 2, '961119144bfe3505b3966e499e0ba31f740944780c20e8940f5ba85bf55db9bd', '2025-10-10 12:34:50', '2025-10-11 15:34:50', 0, '2025-10-13 12:14:44'),
(86, 2, '87d7bdb86c76feb1a6f3235749c06f36cb9ac17c2e4083511baec209dd4d4790', '2025-10-10 13:08:12', '2025-10-11 16:08:12', 0, '2025-10-13 12:14:44'),
(87, 2, '5b8aca68eb6f2d2d9b86000c62e4d100764b9ea373ce3b058bcacbbf91bb493a', '2025-10-10 13:28:30', '2025-10-11 16:28:30', 0, '2025-10-10 13:32:12'),
(88, 2, '352fbcc6893844333a166603885657a48daad878a44113d65831c6316ecfcc44', '2025-10-10 13:45:39', '2025-10-11 16:45:39', 0, '2025-10-10 13:49:07'),
(89, 2, '139b1e90501d9ad9c6df503c8059dce4e8227a5cd5dcc4480c8b497814e884b3', '2025-10-10 13:49:13', '2025-10-11 16:49:13', 0, '2025-10-13 12:14:44'),
(90, 2, 'd77fb789729dbc4462955dde1b4ea735eb4f569cf9cffb7d38f253833847e18c', '2025-10-10 13:50:09', '2025-10-11 16:50:09', 0, '2025-10-13 12:14:44'),
(91, 2, '615fc900c9774e3f5a7217358ca9e51aea39c10be873fc930006de15e9fde24c', '2025-10-10 14:21:10', '2025-10-11 17:21:10', 0, '2025-10-10 14:23:14'),
(92, 2, 'd30bb99fb51a0ea08832248c4ea3ba9fdf0314d4957f121ad4bd8e6078df9571', '2025-10-10 14:24:57', '2025-10-11 17:24:57', 0, '2025-10-10 14:27:38'),
(93, 2, '78105f3c6f3a1dd9821f379c0ce7a547cc9691896e80865c3dba8ab31a7c1b20', '2025-10-10 14:27:59', '2025-10-11 17:27:59', 0, '2025-10-10 14:30:06'),
(94, 2, '5a371efff78a458b4a104086a593a69db4ebd5476ff389cf488350fb102b27ee', '2025-10-10 14:30:40', '2025-10-11 17:30:40', 0, '2025-10-13 12:14:44'),
(95, 2, 'a23fb53f57ed9fc3b18edcf803cfde89ed409907d9dab1aea9cac7a8bded9016', '2025-10-10 14:36:51', '2025-10-11 17:36:51', 0, '2025-10-10 14:40:22'),
(96, 2, '10200e7c737f580ed09d3b86f5a32a425468d82f333a506f2c08faab4c938228', '2025-10-10 14:43:23', '2025-10-11 17:43:23', 0, '2025-10-10 14:52:48'),
(97, 2, '7bb42293167434d6ffd04a4244b6eecba3f152a3e2cf1f028a107e5dd4bc9b9a', '2025-10-10 14:57:10', '2025-10-11 17:57:10', 0, '2025-10-10 15:03:27'),
(98, 2, 'dba4aa3b0772c9dd1c5869412e3db9018939042ba833fef84671466888218634', '2025-10-10 15:04:00', '2025-10-11 18:04:00', 0, '2025-10-10 15:06:42'),
(99, 2, '22e8185db964b12b5b467b2f46de23dbe5f0e528dbdf4200fcfce25ccbfbfe36', '2025-10-10 15:06:49', '2025-10-11 18:06:49', 0, '2025-10-10 15:21:25'),
(100, 2, '270311767d47826bdcf7d8c638e8d0ee15b48c3bf86fca5fd54fffd7e7ac2621', '2025-10-10 15:21:48', '2025-10-11 18:21:48', 0, '2025-10-10 15:23:57'),
(101, 2, '3a5f0b93bcdee5ec0431f337337588bffb642d24f52e3cbe3325fa6289405ad8', '2025-10-10 15:24:03', '2025-10-11 18:24:03', 0, '2025-10-10 15:36:57'),
(102, 2, '4dfb0468eb9ec63b1dcd20bd3d916b8830ee6cab14dca348344e2ce303d41e31', '2025-10-10 15:39:58', '2025-10-11 18:39:58', 0, '2025-10-10 15:43:43'),
(103, 2, '55b0a715642538b6f879eb315aad2f339ab5e8259acf5b276d8aa46bfdc0885c', '2025-10-10 15:44:09', '2025-10-11 18:44:09', 0, '2025-10-10 15:45:48'),
(104, 2, 'bf6fac50c2a1e341764164f243a62c2b88732ba0ed4c437df895f4ba553f413b', '2025-10-10 15:45:53', '2025-10-11 18:45:53', 0, '2025-10-10 15:47:29'),
(105, 2, '01d0b7fc1841d2bdfd5949d730fdf6dc50de590dfaaac2fe9b6e0fbadd4eeb72', '2025-10-10 15:49:43', '2025-10-11 18:49:43', 0, '2025-10-10 15:49:54'),
(106, 2, '03350335fb181201a9039b25b1ac53f57e8355a2d0847cd020a261888a3a8457', '2025-10-10 15:50:11', '2025-10-11 18:50:11', 0, '2025-10-10 15:50:40'),
(107, 2, '568b2cea966926ddbf6748b643c7cba983ae62fea069c6289d83f56bbcf507c3', '2025-10-10 15:50:47', '2025-10-11 18:50:47', 0, '2025-10-10 16:04:53'),
(108, 2, '43fa93464448c77903573303ffb2cb57bdfef459e86f1e0e79dd73fcb602c3b6', '2025-10-10 16:05:10', '2025-10-11 19:05:10', 0, '2025-10-10 16:05:54'),
(109, 2, '7b2b7b82e4c8f51dcd675d98a293ce3b7588e3bdbcb182eb7f7bd836975e3f86', '2025-10-10 16:06:00', '2025-10-11 19:06:00', 0, '2025-10-10 16:06:45'),
(110, 2, '6b699be7fbdfec0328e2a1eb8f784c453eadca5fe2400d1cb39d91c2644d5999', '2025-10-10 16:07:02', '2025-10-11 19:07:02', 0, '2025-10-10 16:23:59'),
(111, 2, '5b1952739dcc11b2dce7e116a051cd997a4644415bed55c12d5c1753995a3508', '2025-10-10 16:24:17', '2025-10-11 19:24:17', 0, '2025-10-10 16:25:27'),
(112, 2, '0e80802faede4f90dd8f738f7f1838cf0d6613eef0136f405e65f1c0a16c3446', '2025-10-10 16:25:38', '2025-10-11 19:25:38', 0, '2025-10-13 12:14:44'),
(113, 2, '5135576c37ae2c08d1f2edbd3a1f0fc83bed80b47e02e184ea4e5ebf0b2f59e9', '2025-10-10 16:29:39', '2025-10-11 19:29:39', 0, '2025-10-10 16:30:16'),
(114, 2, 'c483f4d63ca6e2c00506774ef947943185cfdb9cc3aec8235a0c92187c46a53e', '2025-10-10 16:30:23', '2025-10-11 19:30:23', 0, '2025-10-13 12:14:44'),
(115, 2, 'fcd2b6c6419469bc1913081d5e6f061662fde03d7aa57aaaa74354f9bd8b361b', '2025-10-10 16:43:56', '2025-10-11 19:43:56', 0, '2025-10-10 16:44:27'),
(116, 2, '734f66633b8d64bf466287b215e30b8ed5f777246dfcb149ac31348ab27053a6', '2025-10-10 16:44:33', '2025-10-11 19:44:33', 0, '2025-10-10 16:45:06'),
(117, 2, '4e62217759c62530a002801f1861c90874c5111e2df6631bdabdfd624b0c798f', '2025-10-10 16:45:12', '2025-10-11 19:45:12', 0, '2025-10-13 12:14:44'),
(118, 2, 'b7438ed4e7ae76105b9957b6160ecf051778192dd68f59338a2f1c93528946b7', '2025-10-10 16:47:42', '2025-10-11 19:47:42', 0, '2025-10-10 16:48:03'),
(119, 2, '611147bc5a3df3efe41c98d554fadf49fe9e5d02c0d5927161fcbc77dea99670', '2025-10-10 16:48:12', '2025-10-11 19:48:12', 0, '2025-10-13 12:14:44'),
(120, 2, '9c3cf73724d7598d41602130758bb8cf5b83644279c78fc71cd457cb59d075d2', '2025-10-10 16:48:47', '2025-10-11 19:48:47', 0, '2025-10-10 16:49:02'),
(121, 2, '4fe692f0554179475412254ba06f90c9ef955d0a52cf3bbc2b5de1bf587a08c5', '2025-10-10 16:49:13', '2025-10-11 19:49:13', 0, '2025-10-10 16:51:35'),
(122, 2, 'dbf6c7f1529ca142e49154d59b307a22b81539524cc40578530efb0ce92e9ab6', '2025-10-10 16:51:40', '2025-10-11 19:51:40', 0, '2025-10-13 12:14:44'),
(123, 2, 'd51e4e7553118ae493f30ef2cc10fb9d288fb3fc3e11e7b4705b1672c1690a11', '2025-10-10 18:40:44', '2025-10-11 21:40:44', 0, '2025-10-10 18:42:01'),
(124, 2, '16e28e18d898f940289aad576d1924b757c8504b354c3086d0a2600df2dd4048', '2025-10-10 18:42:09', '2025-10-11 21:42:09', 0, '2025-10-10 18:42:44'),
(125, 2, '43733c854af7fd0f9afd91f7f8641841b87661b78e8b54e1e45221b0a77efd98', '2025-10-10 18:42:51', '2025-10-11 21:42:51', 0, '2025-10-10 18:44:54'),
(126, 2, '7450dba7a70dff2f8e4ec38062da9835ea117ee662bd5d321537b9a53e0339e8', '2025-10-10 18:45:00', '2025-10-11 21:45:00', 0, '2025-10-10 18:46:53'),
(127, 2, '1e129a81276f2621a6de1a9578647f874a28d853b68a51162a833560cb8eb771', '2025-10-10 18:50:12', '2025-10-11 21:50:12', 0, '2025-10-10 18:50:42'),
(128, 2, '9c2fc6d95db346a612c09ec3feff6e3fcbcfdaba7d205e5e8ff64b0c103199a2', '2025-10-10 18:50:56', '2025-10-11 21:50:56', 0, '2025-10-10 18:52:21'),
(129, 2, '15eaac44ea4f31d872226459981cdf1cd61b88b751e641b33a3258967c0f20f3', '2025-10-10 18:52:27', '2025-10-11 21:52:27', 0, '2025-10-10 18:54:17'),
(130, 2, '7cd4750fbce21152431830ebfa720897e78bd0c87dfa944c6283a957f3a29300', '2025-10-10 19:00:13', '2025-10-11 22:00:13', 0, '2025-10-10 19:00:41'),
(131, 2, 'e8a24ca48e575525804d0fee7bfbfe17829c767cd8959ee9370403a9824fc317', '2025-10-10 19:00:50', '2025-10-11 22:00:50', 0, '2025-10-10 19:02:37'),
(132, 2, '7a3035b7dad38ab9efbfe28af69eb5b5e04e1e7d1789345257144ab1502b8b9f', '2025-10-10 19:02:42', '2025-10-11 22:02:42', 0, '2025-10-10 19:03:49'),
(133, 2, 'e3f19d18a225af8ed27955a42c8dd28aac3e0a13c9ae75e1cb5f323a87cfb19b', '2025-10-10 19:03:55', '2025-10-11 22:03:55', 0, '2025-10-10 19:08:44'),
(134, 2, '644875a1ae2513915e87157d6ef307253a0ea058a6213a6d630aa75270c63a51', '2025-10-10 19:13:27', '2025-10-11 22:13:27', 0, '2025-10-10 19:20:00'),
(135, 2, '68bc55489e2ee682a23ccd9bf002268e1c57cdea7d87aaa915ad14fc915b2479', '2025-10-10 19:34:47', '2025-10-11 22:34:47', 0, '2025-10-10 19:41:05'),
(136, 2, '68d3852a91d0ace77ad75c34dc8d855a951cd827193091caa9a684d43857a86c', '2025-10-10 19:55:29', '2025-10-11 22:55:29', 0, '2025-10-10 19:57:50'),
(137, 2, '9b1587a9752c1e73874fa223dfdc7016166aed7774bfd0158c6d5e158b58030c', '2025-10-10 19:59:12', '2025-10-11 22:59:12', 0, '2025-10-10 20:01:37'),
(138, 2, 'c13693f58db6b66fe79b530927d1539042b8916e5f6da9aa247ea1a80a3be556', '2025-10-10 20:01:55', '2025-10-11 23:01:55', 0, '2025-10-10 20:09:59'),
(139, 2, 'ba50f967621963a2f887461950d91d37e3483a4d8133e5795baae7e2f18281fe', '2025-10-10 20:10:45', '2025-10-11 23:10:45', 0, '2025-10-10 20:15:07'),
(140, 2, 'a7dfc9cc8d9b22724cc1b2af3ac7e4d27800de06d58c94c4f339036d9d526970', '2025-10-10 20:16:20', '2025-10-11 23:16:20', 0, '2025-10-10 20:20:04'),
(141, 2, '2abcfd01e3829eb0de88acabb008eca2238df423c1fabde93054ec9b495b85a6', '2025-10-10 20:20:26', '2025-10-11 23:20:26', 0, '2025-10-13 12:14:44'),
(142, 2, '9f3ea8d8b28aff0eec53cb2880c0cbaf407b8a307a8b379cd2b531407db9e160', '2025-10-10 20:21:25', '2025-10-11 23:21:25', 0, '2025-10-13 12:14:44'),
(143, 2, 'e48e088656a8f01d8d803a76c4317e2da6cf1c887825e9b638655ff3561f647d', '2025-10-10 20:29:49', '2025-10-11 23:29:49', 0, '2025-10-13 12:14:44'),
(144, 2, 'eba8039c638e18101ab8bf217ca5329bafee9728d57fcf20bf26ff0af302c271', '2025-10-10 20:33:00', '2025-10-11 23:33:00', 0, '2025-10-13 12:14:44'),
(145, 2, 'd37ecbf033893fe51faab65f7df8418a3408c45b90abd056a6dd1befe2341d85', '2025-10-10 22:43:41', '2025-10-12 01:43:41', 0, '2025-10-13 12:14:44'),
(146, 2, '7935211a326a1aa22ff91e34030ca337f66c84f8164156c2874d42577b96894d', '2025-10-10 22:48:04', '2025-10-12 01:48:04', 0, '2025-10-10 23:05:34'),
(147, 2, 'fb6332c7da6f5af7d4fa8ffe94623db87559016f0bc4f843897746f91b1efffb', '2025-10-10 23:05:53', '2025-10-12 02:05:53', 0, '2025-10-13 12:14:44'),
(148, 2, '2fbe6fd00cda0ac500f9633dd9a6954d4daaad316736182a2e86bd789137e3cc', '2025-10-10 23:53:34', '2025-10-12 02:53:34', 0, '2025-10-13 12:14:44'),
(149, 2, '1c17e1815ab15387a262936a3a6dc406076230d65ea861c2f686d1d674f32041', '2025-10-10 23:57:32', '2025-10-12 02:57:32', 0, '2025-10-13 12:14:44'),
(150, 2, 'aa727714dabe3e9af882e7169752ce3fc1192964cb9a90060999714f8f2cd261', '2025-10-11 00:05:25', '2025-10-12 03:05:25', 0, '2025-10-11 00:11:18'),
(151, 2, 'b94a81a3b4061236083965ffa78053d23b96ae0b0fa0094ced13e49dc52103e0', '2025-10-11 00:31:59', '2025-10-12 03:31:59', 0, '2025-10-11 00:33:50'),
(152, 2, 'ab265dc45ff7e50ca2b9fe5368bf6c54fb60b4239224f5e97ae7c0143e8d2d14', '2025-10-11 00:34:05', '2025-10-12 03:34:05', 0, '2025-10-11 00:34:38'),
(153, 2, '87e63a2c6ce6da6a6d4eb2eb1a9e3970ffefce88d17ec485687754a705bacccf', '2025-10-11 00:35:01', '2025-10-12 03:35:01', 0, '2025-10-11 01:14:01'),
(154, 2, 'edb47b756a02cbc17c0f10e4a9242ceee3811c8baf72958ab5ab650df250106c', '2025-10-11 01:14:54', '2025-10-12 04:14:54', 0, '2025-10-13 12:14:44'),
(155, 2, 'ef36a8859518badef1eadeabc50d571bea10e90f405596deada0bd8489d574cd', '2025-10-11 01:19:01', '2025-10-12 04:19:01', 0, '2025-10-13 12:14:44'),
(156, 2, 'f383c695018eb9d965c14d051e0224150e39c211888cd7465a29935916f25f3a', '2025-10-11 01:39:12', '2025-10-12 04:39:12', 0, '2025-10-11 01:40:44'),
(157, 2, '91d44061a43abe898d586f4284f729a779e53cb474ea4123629191737f619848', '2025-10-11 01:40:56', '2025-10-12 04:40:56', 0, '2025-10-13 12:14:44'),
(158, 2, 'e20ce7bc21fc4bfded740497a6d74ef4708da2545b9b2e1675383fc1e2fa3134', '2025-10-11 13:22:28', '2025-10-12 16:22:28', 0, '2025-10-11 13:34:12'),
(159, 2, '9e086b5abb833516bb48891d9f09d7149ba1ecac3be353495677f9c63b313ad8', '2025-10-11 13:34:17', '2025-10-12 16:34:17', 0, '2025-10-13 12:14:44'),
(160, 2, 'ff86d09e3af1b9bf69cacf9976b5c300867127092e6fae2a613a8054070c0235', '2025-10-11 14:00:21', '2025-10-12 17:00:21', 0, '2025-10-13 12:14:44'),
(161, 2, 'b9caf5fe182d466d508a28499b893493bad2aac01d0d8142f1f8a6682b750f63', '2025-10-11 15:01:48', '2025-10-12 18:01:48', 0, '2025-10-13 12:14:44'),
(162, 3, '236ea116c67528122759afa2718688a41a7a56596767cd7048f8a748211b2685', '2025-10-11 15:10:18', '2025-10-12 18:10:18', 0, '2025-10-13 18:05:33'),
(163, 2, '83a51edbe1702887da548040cc35ca235733f75cca4a8bba831d5198b6f39e27', '2025-10-13 12:14:44', '2025-10-14 15:14:44', 0, '2025-10-14 16:07:40'),
(164, 2, 'a429fb8fdf310e288f683a4d6519cc10d5db5c0acdd2526e36e90842f8f0890d', '2025-10-13 12:18:45', '2025-10-14 15:18:45', 0, '2025-10-14 16:07:40'),
(165, 2, '4a14b0c8a7d458c17a9be3fb032462f30f4f6f8545283d3240fe27b8c5d64ad8', '2025-10-13 12:35:16', '2025-10-14 15:35:16', 0, '2025-10-14 16:07:40'),
(166, 2, 'c82d5937319abdac5b850d7e43c687498b0b2b224729210f0814102a2a0cde2f', '2025-10-13 13:12:14', '2025-10-14 16:12:14', 0, '2025-10-13 13:22:09'),
(167, 2, '8968eb51ce2ba8fa8dc198a5971f5532d7e2c258d78dd63c847c5ffa32f3ee2b', '2025-10-13 13:22:33', '2025-10-14 16:22:33', 0, '2025-10-14 21:32:25'),
(168, 2, 'd4acadfe6843baeb49ea09da57507c660dd90168d2ade0f4ee70d614af3b06f8', '2025-10-13 13:29:01', '2025-10-14 16:29:01', 0, '2025-10-14 21:32:25'),
(169, 2, '1ec32eeb92cf951292d39c548ccb626a3d03c93202bef257b4f42dbcec725102', '2025-10-13 13:55:21', '2025-10-14 16:55:21', 0, '2025-10-14 21:32:25'),
(170, 2, 'ba6b75e6c40308686192816eda762b69f9eb87e8204b0bdd6aab7df34b72b7f9', '2025-10-13 15:42:07', '2025-10-14 18:42:07', 0, '2025-10-14 21:32:25'),
(174, 2, '753bb878532e3fb8508e019d368ef76fa796a5a929caa351ddec36e8d71c6c8c', '2025-10-13 15:52:40', '2025-10-14 18:52:40', 0, '2025-10-14 21:32:25'),
(175, 2, 'b9eee11b728763ecfc44d4561ebe76c33d6b46e694b74a8f4838f691e6879676', '2025-10-13 16:01:12', '2025-10-14 19:01:12', 0, '2025-10-14 21:32:25'),
(176, 2, 'a6ee31be025903c15ddb9f810dcbd5ff6c169f3100675869104c05d4199fa9bd', '2025-10-13 16:11:54', '2025-10-14 19:11:54', 0, '2025-10-14 21:32:25'),
(177, 2, '2bf5355fe694371f3f5ae1b07caab463ec040c0c0ca4277beaf5624c706c8ee6', '2025-10-13 16:18:42', '2025-10-14 19:18:42', 0, '2025-10-14 21:32:25'),
(178, 2, '4ce31179457b36190dc740b14406bdd475ac008530cf71c5bfd3e0761f7beb3b', '2025-10-13 17:16:21', '2025-10-14 20:16:21', 0, '2025-10-13 17:22:49'),
(179, 2, '6f8fc6db5ac0433ab02097ca0beaa7e22f188f286fe4fbf4a37f11ec423ee934', '2025-10-13 17:23:17', '2025-10-14 20:23:17', 0, '2025-10-14 21:32:25'),
(180, 2, 'f52a954810bd115404f4176b7f359dc1bb7738924b99bd9ec5efef21bfd26027', '2025-10-13 17:29:21', '2025-10-14 20:29:21', 0, '2025-10-14 21:32:25'),
(181, 2, 'b87199a3d7a5ce2893d1ad2921500a83d72b1b13bf4c7e0724a85cf914f07448', '2025-10-13 17:37:12', '2025-10-14 20:37:12', 0, '2025-10-14 21:32:25'),
(182, 2, 'dc7621e9b092455d4e4f275e79718f57e2262323e9bb3248801a2fba765b38aa', '2025-10-13 17:41:47', '2025-10-14 20:41:47', 0, '2025-10-14 21:32:25'),
(183, 2, '44682388a898572ee199f09938feb98ac796ba78ab131d3c611e705e44158f9d', '2025-10-13 18:03:51', '2025-10-14 21:03:51', 0, '2025-10-14 21:32:25'),
(184, 3, '137ff5dc6b69b7ba3a522d06dce3f529477d2df885aa1ff6b1d8d53330052c7b', '2025-10-13 18:05:33', '2025-10-14 21:05:33', 1, '2025-10-13 18:05:33'),
(185, 2, 'e1cd973a820b2a14825c9f700b799df82189fa512d8a83b977a6cb369dd1054d', '2025-10-13 18:06:14', '2025-10-14 21:06:14', 0, '2025-10-14 21:32:25'),
(186, 2, '26b5681523c952e4eb9df591e5f509f7ff7fa1487887821cb063091f8af31554', '2025-10-13 19:01:26', '2025-10-14 22:01:26', 0, '2025-10-15 01:16:52'),
(187, 2, '2f398ee9a3c9a21ca0a98f0efae0d3008887660d79cbe73c684900035577155f', '2025-10-13 19:46:29', '2025-10-14 22:46:29', 0, '2025-10-13 19:52:49'),
(188, 4, '702ba893b2cfa12daaa16e8e3291470d40289e43ca86595dbf1c8216bbde02b5', '2025-10-13 19:54:32', '2025-10-14 22:54:32', 1, '2025-10-13 19:54:34'),
(189, 2, '4682a275168d0caa112dd9d871e6885063e5c2b2d9365f1feb5953af79ebdbee', '2025-10-13 20:30:39', '2025-10-14 23:30:39', 0, '2025-10-15 01:16:52'),
(190, 2, 'bbc0892ff761432324fefd9d663f096e35dc6a38ac05d0058534046ba657f9f0', '2025-10-13 20:38:43', '2025-10-14 23:38:43', 0, '2025-10-15 01:16:52'),
(191, 2, '607395d589cfbd5baace14f6994ed43d935cf9269e2b263f9359e45d206dac97', '2025-10-13 20:43:26', '2025-10-14 23:43:26', 0, '2025-10-15 01:16:52'),
(192, 2, '5ce431779833ce458958a2731d3a56c4ad57881a494c15919e798c1831c1f27c', '2025-10-13 22:03:49', '2025-10-15 01:03:49', 0, '2025-10-15 01:16:52'),
(193, 2, '621262864b4d946bd9258178f82196e8881f8d186b8111839c4f7627a86f6619', '2025-10-13 22:29:10', '2025-10-15 01:29:10', 0, '2025-10-15 01:46:55'),
(194, 2, '2a8528f69265f2dc04a19ab31666fa0dab962cbddae9a4e9e6328f4c0a89b6d8', '2025-10-14 01:55:09', '2025-10-15 04:55:09', 0, '2025-10-15 11:18:48'),
(195, 2, 'e2d43e1e11dfaa8c4b5e5d76b387f97d707f4b915e4140b4b6ee31e81ed07d18', '2025-10-14 02:21:17', '2025-10-15 05:21:17', 0, '2025-10-15 11:18:48'),
(196, 2, '471d11e213aad3442ded1eaf134e0cc75b4a474c0b9daad368e2248e7307f7c1', '2025-10-14 11:59:48', '2025-10-15 14:59:48', 0, '2025-10-15 20:38:25'),
(198, 2, 'f4de953c71087ba0be5db5ecc4f32026d76bfe8799379616f842037b3ca020c3', '2025-10-14 13:42:18', '2025-10-15 16:42:18', 0, '2025-10-15 20:38:25'),
(199, 2, 'da0483d1ace0f0ebff716ce86bae0a48fa8f162861b6f9819d439ee5f8d97f01', '2025-10-14 13:51:14', '2025-10-15 16:51:14', 0, '2025-10-15 20:38:25'),
(200, 2, '6a7abf2b255fa2c560cf3b177d1d317c6a33b59462661ca7940523cfddc425dd', '2025-10-14 13:58:55', '2025-10-15 16:58:55', 0, '2025-10-15 20:38:25'),
(201, 2, '56cf3b095deeb27efa5ed32ea6d12c341cc0b8de17926954cfacc40a28cfd68f', '2025-10-14 16:07:40', '2025-10-15 19:07:40', 0, '2025-10-15 20:38:25'),
(202, 2, '89c1bfd1d712dca379f90112259e02a399105cd64ff06233bdff71cfed8443f3', '2025-10-14 21:32:25', '2025-10-16 00:32:25', 0, '2025-10-16 00:34:23'),
(203, 2, '147e6f394a41610273ca0b0f7edd3b04a9768135c51dc0803b821494642c7718', '2025-10-15 01:16:52', '2025-10-16 04:16:52', 0, '2025-10-16 10:13:44'),
(204, 2, '5e56cf2ef05185d3655cfe92e691e554029cf4d4aefd2bdd49a8334320032ec5', '2025-10-15 01:46:56', '2025-10-16 04:46:56', 0, '2025-10-16 10:13:44'),
(205, 2, 'e91453ec605150fcd013798f124e3fb0c63cef74b260c8872d802e85cae157e2', '2025-10-15 01:59:58', '2025-10-16 04:59:58', 0, '2025-10-16 10:13:44'),
(206, 2, 'd439badeeabd5cef55ebdc04dd7ecb4563bbc09b2542ae84a352a392d4d2c59f', '2025-10-15 02:28:58', '2025-10-16 05:28:58', 0, '2025-10-16 10:13:44'),
(207, 2, '29919ca8908e275c7d63d1d3a31b1b4ae6abf3f628a811cff9226dcf72270ac9', '2025-10-15 02:43:51', '2025-10-16 05:43:51', 0, '2025-10-16 10:13:44'),
(208, 2, 'f5350e72e5607da3f198485d82b0ea39cd3d89d99c4e58e4d174d8d7676e8f7b', '2025-10-15 11:18:48', '2025-10-16 14:18:48', 0, '2025-10-16 14:36:26'),
(209, 2, 'ccf808bb0755d174791df504b40ad1be8b3ad30e8d22f418fc5cc5e0e23c44d7', '2025-10-15 13:33:23', '2025-10-16 16:33:23', 0, '2025-10-16 17:43:28'),
(210, 2, '45d13643160385e63205bb5952ca6fa3c6cbcdc998c2b081d53ab273a9b035a5', '2025-10-15 13:38:07', '2025-10-16 16:38:07', 0, '2025-10-16 17:43:28'),
(211, 2, 'f5fa376068694d177c4175dc418a249ad4ea09a5a937604c2aa6e810eb14a5cb', '2025-10-15 14:22:15', '2025-10-16 17:22:15', 0, '2025-10-16 17:43:28'),
(212, 2, '48168023833dc87827b4b634dc54919c18280f8b10b75f01554d0bc5e229c011', '2025-10-15 20:38:25', '2025-10-16 23:38:25', 0, '2025-10-17 01:05:45'),
(213, 2, 'cd539a9d462984a6d1444f838879a5195cc93cc5553fa00689451aabf200f3b0', '2025-10-15 21:54:00', '2025-10-17 00:54:00', 0, '2025-10-17 01:05:45'),
(214, 2, '08ef0e4874dd82d11e9198d4a0934a814e68d26dc8152e938181cf11d799cb00', '2025-10-15 22:00:32', '2025-10-17 01:00:32', 0, '2025-10-17 01:05:45'),
(215, 2, 'd3af723ab6d5eafe5f0ef3d74152cbe69f73d861900880c40c9d32f9da3e1887', '2025-10-15 22:20:13', '2025-10-17 01:20:13', 0, '2025-10-17 12:27:49'),
(216, 5, 'test_session_801dc80898606a858fdcc554124e7f21', '2025-10-15 22:50:17', '2025-10-16 22:50:17', 1, '2025-10-16 12:50:00'),
(217, 2, '3543066d30f254ba2065f962a5401f25ab7c91052afd0450949a1b77976f2ab7', '2025-10-15 23:01:37', '2025-10-17 02:01:37', 0, '2025-10-17 12:27:49'),
(218, 2, 'ba9ff8504c806e3fae49f4c043df5ece53a61109c85e84489b5b804513abc81d', '2025-10-16 00:34:23', '2025-10-17 03:34:23', 0, '2025-10-17 12:27:49'),
(219, 2, '2cce9e43a35aa54a01d7c7479e4dca7b4e0ea0e5ef474dc70edcce49696e576a', '2025-10-16 00:40:10', '2025-10-17 03:40:10', 0, '2025-10-17 12:27:49'),
(220, 2, '435c1fc21c1f947bd9eba4c2405d1520a18cd8cac00aaea643a2c9944cffaa5d', '2025-10-16 00:45:10', '2025-10-17 03:45:10', 0, '2025-10-17 12:27:49'),
(221, 2, '280f0e69ed4066e8bc259404f40bded5eea45dde35a53b5196e4c0d8ae395881', '2025-10-16 00:47:45', '2025-10-17 03:47:45', 0, '2025-10-17 12:27:49'),
(222, 2, 'f874c004b928fde77733e8a4b0bfc247899937072260edd281d39a064eeabece', '2025-10-16 01:20:14', '2025-10-17 04:20:14', 0, '2025-10-17 12:27:49'),
(223, 2, '326d9348aecbfe476f8bf5c088b0124abfd06e09d20cba415e0e1592756eb306', '2025-10-16 01:25:52', '2025-10-17 04:25:52', 0, '2025-10-17 12:27:49'),
(224, 2, '398873e1121049da490da11b9b518ec76baf2a27710dbc3254e540e0c497a440', '2025-10-16 01:30:13', '2025-10-17 04:30:13', 0, '2025-10-17 12:27:49'),
(225, 2, '9fa036f70470e6db3b43a0050123eb31185e50af789bd533acfec331caf9ae2a', '2025-10-16 01:31:55', '2025-10-17 04:31:55', 0, '2025-10-17 12:27:49'),
(226, 2, '12b74a640842c5cded7fc5e4a518cd11a16e5c44fc99ff45a4420588fccfdf6d', '2025-10-16 01:33:24', '2025-10-17 04:33:24', 0, '2025-10-17 12:27:49'),
(227, 2, 'e9a73e75594f76fa98faa058d9540c4c6848a2ec8e9b51141ee87d5efce375f0', '2025-10-16 01:35:00', '2025-10-17 04:35:00', 0, '2025-10-17 12:27:49'),
(228, 2, '4561dbd548d89b59ed39a083009ca3f6c98ca43c83cb4d0e4e28216c32155b38', '2025-10-16 01:42:02', '2025-10-17 04:42:02', 0, '2025-10-17 12:27:49'),
(229, 2, '189ff73624892c1b1354322089f7a43c493fc8b6c354ee0433d204c3bd34fe56', '2025-10-16 02:44:55', '2025-10-17 05:44:55', 0, '2025-10-17 12:27:49'),
(230, 2, '314378ae51dec23244c469f965403753e375aa90d4f9c84e305669003668b2a5', '2025-10-16 10:13:44', '2025-10-17 13:13:44', 0, '2025-10-17 13:35:10'),
(231, 2, '01b0f33335ad50251d39e6948105c95bff3828c495b28c60fd5e490476479cfb', '2025-10-16 11:58:32', '2025-10-17 14:58:32', 0, '2025-10-17 15:15:18'),
(232, 2, 'd015aae9db57a31b95a3cb0d7b8fe99ccf0e52023cc9e90f7d34cea2213e05ee', '2025-10-16 12:34:08', '2025-10-17 15:34:08', 0, '2025-10-17 15:49:56'),
(233, 2, '5c652e2d5bf1663ba8a76591daecc8894909f17ce89c9102deb94cf36e32a2bc', '2025-10-16 12:36:30', '2025-10-17 15:36:30', 0, '2025-10-17 15:49:56'),
(234, 2, '94368301ef98e9de87eb2e8af1a3fdee7cd9e3e2eebca8021953ba3c21fdcc98', '2025-10-16 12:40:06', '2025-10-17 15:40:06', 0, '2025-10-17 15:49:56'),
(235, 2, 'cd5ff61fc3f93c9671fe14ac7784e19de6ce13ee2d88e9358d1e67377dab4c79', '2025-10-16 13:14:41', '2025-10-17 16:14:41', 0, '2025-10-17 18:39:20'),
(236, 2, 'fd2a511020933e1b683e267fc32ee698b1ae1e66ddff8225c1e17c0dd5411cf4', '2025-10-16 13:41:00', '2025-10-17 16:41:00', 0, '2025-10-17 18:39:20'),
(237, 2, '5034092397f95b41ea29273c4afe247e811557c23a0dafae5cd25b083a00e164', '2025-10-16 13:45:05', '2025-10-17 16:45:05', 0, '2025-10-17 18:39:20'),
(238, 2, 'b461024e668527f3aa8c8810fc2620ee1eb85630c60257a6d3cfd19675a9c10b', '2025-10-16 14:11:32', '2025-10-17 17:11:32', 0, '2025-10-17 18:39:20'),
(239, 2, 'e73d6730e339b4913c17ec094609f86427065446e13d15c0c52f92c3e889e448', '2025-10-16 14:14:45', '2025-10-17 17:14:45', 0, '2025-10-17 18:39:20'),
(240, 2, '8b7972c008f8b63cff4073d21f6da924bf6ad5db7d539503b066453093b0dc4b', '2025-10-16 14:36:26', '2025-10-17 17:36:26', 0, '2025-10-17 18:39:20'),
(241, 2, '56e1122ba28d731f7bb826ab119c55be0a332c9b26b2604fda7ed60ad3221aee', '2025-10-16 14:45:27', '2025-10-17 17:45:27', 0, '2025-10-17 18:39:20'),
(242, 2, '6c9457c71e3dc25ee160beef2854f08ce6469a076f3121a206fe20b1c7e972d7', '2025-10-16 15:30:20', '2025-10-17 18:30:20', 0, '2025-10-17 18:39:20'),
(243, 2, '1166ceee53b09613f1b706807260ef1901268d49e7446a08a291f0e2cc8c6eb4', '2025-10-16 15:46:28', '2025-10-17 18:46:28', 0, '2025-10-17 19:23:49'),
(244, 2, '042f6b641a1f0c7c0b263cf277a27fe0c0cdb723e26595d0cd642b66d11eb1c7', '2025-10-16 17:43:28', '2025-10-17 20:43:28', 0, '2025-10-18 00:26:05'),
(245, 2, '732b5027636c27a1e20814006ee84ec475a4753ddbdaf4f9d3705b07cf646c1c', '2025-10-16 17:53:46', '2025-10-17 20:53:46', 0, '2025-10-18 00:26:05'),
(246, 2, '17d97db17439917aec5ed7a6f2f1e8b3206602d175ab0a7b2de597ef487380e0', '2025-10-16 17:56:16', '2025-10-17 20:56:16', 0, '2025-10-18 00:26:05'),
(247, 2, 'e8ba6569bc762ac7ec1ff2345ee92c5f89e756dc64ab98913940861bef5a194c', '2025-10-16 18:12:40', '2025-10-17 21:12:40', 0, '2025-10-18 00:26:05'),
(248, 2, '6cf70642c0761837779a17396b5a040559ad03898712283aadc5761264f77af0', '2025-10-16 18:18:39', '2025-10-17 21:18:39', 0, '2025-10-18 00:26:05'),
(249, 2, '54ffbf58bc3cac6726b731b03e4f84e6bf62f000afa6502a5c5ce2fe9c94ed6a', '2025-10-16 18:32:08', '2025-10-17 21:32:08', 0, '2025-10-18 00:26:05'),
(250, 2, 'afc3f743fa469fb284795a4687c9f8556a5ffa8f8851355465aacb5477f6283c', '2025-10-16 18:35:36', '2025-10-17 21:35:36', 0, '2025-10-18 00:26:05'),
(251, 2, 'f1df8ab6bbb33bf4ecfc3bece55d9f5caaf5bd5c0f1d2d1fba9f821c2e2c9ec7', '2025-10-16 18:37:51', '2025-10-17 21:37:51', 0, '2025-10-18 00:26:05'),
(252, 2, '8f7b3b004833e4d2a49935de0f14509aaf0aafb3d326a2ff368f295fe1a3ea07', '2025-10-16 18:45:00', '2025-10-17 21:45:00', 0, '2025-10-18 00:26:05'),
(253, 2, 'efa887bb73c69d41a132055a8c8e5ddec212f8491e62aaa343f0847948d37dd1', '2025-10-16 18:49:57', '2025-10-17 21:49:57', 0, '2025-10-18 00:26:05'),
(254, 2, '52a48e1888f5000bbe8695d12f11791127d6f970429d851b00f2e57c3578caee', '2025-10-16 19:08:15', '2025-10-17 22:08:15', 0, '2025-10-18 00:26:05'),
(255, 2, '028788f9c19e3a8e6ce78bd48545623e76228290951ed6f8cbdf36b9457f7242', '2025-10-16 19:26:08', '2025-10-17 22:26:08', 0, '2025-10-18 00:26:05'),
(256, 2, 'fd15508be8fefe437915b6338f03fe9c9ec66f4504f938e4068887cc144900e8', '2025-10-16 19:54:59', '2025-10-17 22:54:59', 0, '2025-10-18 00:26:05'),
(257, 2, '01fca7a9833741d9a4555b89952fa11843182b5d9255a619a900a17049d6e01b', '2025-10-16 20:06:51', '2025-10-17 23:06:51', 0, '2025-10-18 00:26:05'),
(258, 2, '837afcefa9c5c40236a30619b4f1b1baab693399be042f862ce6ac5394511972', '2025-10-16 20:44:07', '2025-10-17 23:44:07', 0, '2025-10-18 00:26:05'),
(259, 2, '0313cd9c2bf517a92186a81615a866b0146a8e456aa0a5d3590b38ecf2d85f83', '2025-10-16 20:55:26', '2025-10-17 23:55:26', 0, '2025-10-18 00:26:05'),
(260, 2, '35e6b2c14690062eb4b3a08cd23f1bc106a30d5da3a12f760ca3f03d2a9a1230', '2025-10-16 21:15:39', '2025-10-18 00:15:39', 0, '2025-10-18 00:26:05'),
(261, 2, 'ca08719d851ff55c26eea0d72591fec58ce9c788c44e00079e223026dd686f95', '2025-10-16 22:58:37', '2025-10-18 01:58:37', 0, '2025-10-18 02:19:55'),
(262, 2, '50b0717a547d5fe7898b81b188135f922b926fd1f1ab79e60b07bba8f873b709', '2025-10-16 23:12:28', '2025-10-18 02:12:28', 0, '2025-10-18 02:19:55'),
(263, 2, '44127e60444f45b47ab357797a6852223ec5fa68b407e2a8a7f5f150410f9e69', '2025-10-16 23:18:24', '2025-10-18 02:18:24', 0, '2025-10-18 02:19:55'),
(264, 2, 'd55779da79e9daef13d83a69f8794a06ce6156f5f08fb634f4373df2f09156dc', '2025-10-17 01:05:45', '2025-10-18 04:05:45', 0, '2025-10-18 15:26:56'),
(265, 2, '1743f23e56e9c166b2d07506249e8ff6595e72a26951c9dcb04e4731fa435da7', '2025-10-17 12:27:49', '2025-10-18 15:27:49', 0, '2025-10-20 18:07:34'),
(266, 2, '631a445bebf8ca5356575e0844e2ceb02dac9db5b444565b7b836ca24a8a9d76', '2025-10-17 12:33:59', '2025-10-18 15:33:59', 0, '2025-10-20 18:07:34'),
(267, 2, '3f89b60b32f5d33a82cb02a2961de24221f4755b1bda99cecc1844ff0f397efc', '2025-10-17 13:35:10', '2025-10-18 16:35:10', 0, '2025-10-20 18:07:34'),
(268, 2, '92a4f7dcacf96e32dd34254a0889f2b7f371671db44eb43f4abeb37fa05b2a1f', '2025-10-17 15:15:18', '2025-10-18 18:15:18', 0, '2025-10-20 18:07:34'),
(269, 2, '5ddbc61e902218b39700ca01ee134d626c9f0728ce9a46939acd230f311304ba', '2025-10-17 15:49:56', '2025-10-18 18:49:56', 0, '2025-10-20 18:07:34'),
(270, 2, '73f308f98ee68e8f0096a80f4785283a2b91a78da21bded71f14eefa1f5ca856', '2025-10-17 16:13:12', '2025-10-18 19:13:12', 0, '2025-10-20 18:07:34'),
(271, 2, '1fa922c3e1c7fc43116798be5694011a4bf1e4d426da9664d61e2689ae3155cc', '2025-10-17 18:39:20', '2025-10-18 21:39:20', 0, '2025-10-20 18:07:34'),
(272, 2, 'f30607f363769772dc7e2b60ab97be41ccfedfa8dd0ce13078b12caa5a6fd39b', '2025-10-17 19:23:49', '2025-10-18 22:23:49', 0, '2025-10-20 18:07:34'),
(273, 2, '55e7c8f598b6189cca329e92a4bc649237a0e3b4c1d4462181e2fae0f9697f3b', '2025-10-17 19:32:11', '2025-10-18 22:32:11', 0, '2025-10-20 18:07:34'),
(274, 2, '761ab4d01186a775896eaf8c433901dd57149e464f2df932d6983ee335fe0c0f', '2025-10-17 19:38:01', '2025-10-18 22:38:01', 0, '2025-10-20 18:07:34'),
(275, 2, 'f22f3a572075cb1a8533b0ac4eea861b5c7371fc65386bc9a9ddab6d78a1d25d', '2025-10-17 19:44:43', '2025-10-18 22:44:43', 0, '2025-10-20 18:07:34'),
(276, 2, '7abb1fe254f61eebc1f578d0fbf68436857d458871dc6cc11d8522a029640322', '2025-10-17 19:56:09', '2025-10-18 22:56:09', 0, '2025-10-20 18:07:34'),
(277, 2, '7ecda5de7b0cd4c2df916f41c219f523ea0d5f03b9e2fade1b84f6acd856314c', '2025-10-18 00:26:05', '2025-10-19 03:26:05', 0, '2025-10-20 18:07:34'),
(278, 2, '8b72b92db741c86571be32b061cb58fb5abba1d1281bb0fbe89af32fe83bc801', '2025-10-18 02:19:55', '2025-10-19 05:19:55', 0, '2025-10-20 18:07:34'),
(279, 2, '82434f3374127cabeaea10c3ebe95f4baa86a469d8ca4ad4058c8c80957425c4', '2025-10-18 03:12:28', '2025-10-19 06:12:28', 0, '2025-10-20 18:07:34'),
(280, 2, '6c7433821df893c348cbaddd4437b66457f6372df660c64278f1d52ccde311e0', '2025-10-18 04:05:34', '2025-10-19 07:05:34', 0, '2025-10-20 18:07:34'),
(281, 2, '471d6e7d116bed3b3489d9118053bacc0b7afa2d6e5141d661b2b1348c4011d6', '2025-10-18 15:26:56', '2025-10-19 18:26:56', 0, '2025-10-20 18:07:34'),
(282, 2, '21f308ddfa9d34169481aa80316fd33b0df743c2fbd3b33fb0a701a746638e07', '2025-10-20 18:07:34', '2025-10-21 21:07:34', 0, '2025-10-22 00:14:18'),
(283, 2, '17e112bcef54319931399441703babe12f7c035da99528ad7e7502f7d9a0d992', '2025-10-20 18:26:40', '2025-10-21 21:26:40', 0, '2025-10-22 00:14:18'),
(284, 2, '4947b229ebd569c56240ffe943b24ee8647f6090b0cca4f5e8a8fb17cb708a3f', '2025-10-20 20:11:21', '2025-10-21 23:11:21', 0, '2025-10-22 00:14:18'),
(285, 2, '871e18223a954114b28d6119426f9be09c7fe19798f35c07f8a89aebc66e06cf', '2025-10-20 20:13:23', '2025-10-21 23:13:23', 0, '2025-10-22 00:14:18'),
(286, 2, '257672e8a478deb5794f223cd13de5311fb7011972fcc5533d930c9bfbb91b3c', '2025-10-20 20:16:18', '2025-10-21 23:16:18', 0, '2025-10-22 00:14:18'),
(287, 2, 'ee53aa74d1c821a915ba1a5f7b360ac6c7b8589d3d7b316f603af2ccd4e86281', '2025-10-20 20:44:24', '2025-10-21 23:44:24', 0, '2025-10-20 20:49:48'),
(288, 2, '404dc54b39abf5bbc705c2268b89d420bb15c85e9338ac23d2d183ea8e310180', '2025-10-20 20:50:57', '2025-10-21 23:50:57', 0, '2025-10-20 20:53:39'),
(289, 2, '5fbb7a66c626610dad85bcccb716b710d4efe477e3cb36b70ae8ac97e908d240', '2025-10-20 20:54:02', '2025-10-21 23:54:02', 0, '2025-10-22 00:14:18'),
(290, 2, '46f57d83b668a189888351829e71a78a3262dace17ecbc05dca51a06a9bfcb02', '2025-10-20 21:17:43', '2025-10-22 00:17:43', 0, '2025-10-22 01:39:53'),
(291, 2, '6aefc5f10c279b8e48e1b08837ac5c8504baacf8c15876f3a74358a92937d1d1', '2025-10-22 00:14:18', '2025-10-23 03:14:18', 0, '2025-10-22 01:39:09'),
(292, 6, '6a201fcceb6c3f8630ec39ef8fca29e61b470a3a40d73cfd24f48d6423447121', '2025-10-22 01:39:21', '2025-10-23 04:39:21', 0, '2025-10-22 01:39:43'),
(293, 2, 'c122d78f58b0b1a6a158d39e0efde88bb48da34c7efb395cf59048762a58f39b', '2025-10-22 01:39:53', '2025-10-23 04:39:53', 0, '2025-10-22 01:40:27'),
(294, 6, '04dfadf423e284dd098ec7861a228c415c385c7c145295eed13d2de1ac19c6e1', '2025-10-22 01:40:38', '2025-10-23 04:40:38', 0, '2025-10-22 01:47:04'),
(295, 6, '054d455b9b05ea40baf1cf20abdf19337f490c46e7ec55b7ec65c891fb11f25e', '2025-10-22 01:47:51', '2025-10-23 04:47:51', 0, '2025-10-23 11:58:16'),
(296, 6, 'b2f0da761bb5dabc8ca1a05042b18947fa6d83545e94df5fc23d844fa18b7a10', '2025-10-22 03:11:15', '2025-10-23 06:11:15', 0, '2025-10-23 11:58:16'),
(297, 6, '10a75bb32060e90f85cf4aaad8f5715bcef64d5211230d3871bdd96ba0a701a3', '2025-10-22 03:19:47', '2025-10-23 06:19:47', 0, '2025-10-23 11:58:16'),
(298, 6, 'ceb52da14ffe2932b632f1ebe6d49f3056af2a146751953bbab98baf88811885', '2025-10-22 03:22:28', '2025-10-23 06:22:28', 0, '2025-10-23 11:58:16'),
(299, 6, 'ea41aeb3c0c16fec87d5a6adeb13030321e7924a732d38611e0f9799a6c864a8', '2025-10-22 03:25:03', '2025-10-23 06:25:03', 0, '2025-10-23 11:58:16'),
(300, 6, 'd01a168208a40d57bd2926bd440f32caa45026ac9b694d59c3499cbc6e402b88', '2025-10-22 03:29:13', '2025-10-23 06:29:13', 0, '2025-10-23 11:58:16'),
(301, 6, '12437c795507ca133f446037dc20c4d5d4a72bf9069570254912edf82168c156', '2025-10-22 03:33:10', '2025-10-23 06:33:10', 0, '2025-10-23 11:58:16'),
(302, 6, 'a860f0c16a3434d40ee2ec8d6aed5fffd8baf8a135ce916aa21b686f7e4f6e7b', '2025-10-22 03:34:12', '2025-10-23 06:34:12', 0, '2025-10-23 11:58:16'),
(303, 6, '07efb200ebd6d71a9f78079b8865d68295f0c6e3b9455a8bb3cb9abcf2705262', '2025-10-22 03:39:31', '2025-10-23 06:39:31', 0, '2025-10-23 11:58:16'),
(304, 6, 'de56f2fbf255e88f07108488dcaf6d8468ec4db6800364e35b435ce9c146ef7c', '2025-10-22 03:45:11', '2025-10-23 06:45:11', 0, '2025-10-23 11:58:16'),
(305, 6, '5a65edf94ee6de0cc09be1d34cb215c5b36f377b1b1737bbea2e0052f0db9186', '2025-10-22 05:28:08', '2025-10-23 08:28:08', 0, '2025-10-23 11:58:16'),
(306, 6, '3a2cb8f4d00a9f445559ebef04f62a0544505571021a4fd6935d913d5cd8f37a', '2025-10-22 11:13:44', '2025-10-23 14:13:44', 0, '2025-10-23 14:35:33'),
(307, 6, '8cfba4b1402a5d2ce1fc7cb5bf4050bb5d08ba800736c02224549e3a0affed06', '2025-10-22 11:16:42', '2025-10-23 14:16:42', 0, '2025-10-23 14:35:33'),
(308, 6, '65766063b3bad393e6eda5ea49019fcdce797ac3556d3b90a008728f2bc10914', '2025-10-22 11:17:54', '2025-10-23 14:17:54', 0, '2025-10-23 14:35:33'),
(309, 6, '9484f65b3f48c57b0b2b6225e69436120a4df8d0d00ab6e2d8cb03eacb06377c', '2025-10-22 11:20:20', '2025-10-23 14:20:20', 0, '2025-10-23 14:35:33'),
(310, 6, '808c32f01246f15975f700aae94733746f3d991e5ffa333d33d05e8e71e07ac3', '2025-10-22 11:45:23', '2025-10-23 14:45:23', 0, '2025-10-22 11:57:22'),
(311, 2, 'ebf262d522e0f6f4bcf6ee32473f414c8fae37251e0b5a595823f8f11aaa2656', '2025-10-22 11:57:32', '2025-10-23 14:57:32', 0, '2025-10-22 11:57:38'),
(312, 6, 'c24e2d5634692351b14df4df114aecc36494333914f17ded36350ecacc130a8c', '2025-10-22 12:02:46', '2025-10-23 15:02:46', 0, '2025-10-24 00:29:33'),
(313, 6, '1eb815e5efede2ccc5ed4ddcbb714e7d336efed392ecf99a1e919500f1e5ee82', '2025-10-22 17:15:31', '2025-10-23 20:15:31', 0, '2025-10-24 00:29:33'),
(314, 6, '0ad3b849b5cacd2b8eddd42336c122db2665ede45b935e5fa14648253fa1aaaf', '2025-10-22 21:23:04', '2025-10-24 00:23:04', 0, '2025-10-24 00:29:33'),
(315, 10, '2aa6076483d14fa42a299a812b56a45b3c445b17e27c8af59c6b624250794540', '2025-10-22 22:09:42', '2025-10-24 01:09:42', 0, '2025-10-24 03:07:21'),
(316, 6, '1afc4e67118d12e3529e7b463fb73b738dc8b6bb2c99bc3c81eac03fbeaf4f50', '2025-10-22 22:13:27', '2025-10-24 01:13:27', 0, '2025-10-24 02:27:57'),
(317, 6, 'e1c0bacfef9eb661c619dcbdf3fde2260b09cf69c4cfd926c2b56d76b06c9ee1', '2025-10-22 22:20:42', '2025-10-24 01:20:42', 0, '2025-10-24 02:27:57'),
(318, 6, '70552d0ba5a546ae05d65ecb1ef7047053fabbd6166712dbe6faf592125db671', '2025-10-22 22:23:33', '2025-10-24 01:23:33', 0, '2025-10-24 02:27:57'),
(319, 6, 'd8d38c19b21182ec60743fcaf64163d64a6b6f3f5ac8d3f69f7da20ce1cd73c5', '2025-10-22 22:26:50', '2025-10-24 01:26:50', 0, '2025-10-24 02:27:57'),
(320, 6, 'c5a58081764a13b5e53780a02cc41fddd7db1ca62a7dc9e5c7ec521cd8529651', '2025-10-22 22:53:16', '2025-10-24 01:53:16', 0, '2025-10-24 02:27:57'),
(321, 6, '26998408a951fc009b11d6d170bbd565672675fad5f8e4beacc913e7ddd6ff2b', '2025-10-22 23:23:43', '2025-10-24 02:23:43', 0, '2025-10-24 02:27:57'),
(322, 6, '2c13c737c6afeaa11794c2164373a1dbf6acc091fb01ee13987d21dae6801810', '2025-10-23 00:12:04', '2025-10-24 03:12:04', 0, '2025-10-24 03:20:58'),
(323, 6, '29343637f663351aad32ecb9b8f1283b81f9ba463efc5482de04457d9f49625b', '2025-10-23 00:13:43', '2025-10-24 03:13:43', 0, '2025-10-24 03:20:58'),
(324, 6, 'e32d63215629ef5fb29eb23249e2da27ccd882efc915ff4fcac4ba3d350b3c6f', '2025-10-23 00:17:38', '2025-10-24 03:17:38', 0, '2025-10-23 00:19:05'),
(325, 10, '93eac8cd047c5fae83978237274b52e6d160f4fa1af8285deeccc4b399e9a8d8', '2025-10-23 00:19:12', '2025-10-24 03:19:12', 0, '2025-10-23 00:19:47'),
(326, 6, '97d7cf497203df0dc203233f6fb15af54b07efb194982c90ba9898fd5c287fdf', '2025-10-23 00:19:57', '2025-10-24 03:19:57', 0, '2025-10-24 03:20:58'),
(327, 6, '8fb30f1196e21844673db44c084f66a5e8a1a2793e199e8070d7c016c8f76967', '2025-10-23 00:20:19', '2025-10-24 03:20:19', 0, '2025-10-24 03:20:58'),
(328, 6, '2cd1adcbec8be5394f8b19ea0549118e7f6bc64a28ac24533d852aaa5c532af1', '2025-10-23 00:21:17', '2025-10-24 03:21:17', 0, '2025-10-24 03:27:38'),
(329, 6, '072b98802ffb74dc6b046ecc932a41b63b687736990156b303653d1c8bd80869', '2025-10-23 00:21:51', '2025-10-24 03:21:51', 0, '2025-10-24 03:27:38'),
(330, 6, 'd592cf70c5f036b7eb7bbf1d76afe2b436398688ca27b179537ca23a663f4a9e', '2025-10-23 00:22:15', '2025-10-24 03:22:15', 0, '2025-10-24 03:27:38'),
(331, 6, '8c374ed43cef51dc2d72cec422b03f3926bc68e248c2d25a805332697d5ca6cb', '2025-10-23 00:22:32', '2025-10-24 03:22:32', 0, '2025-10-24 03:27:38'),
(332, 10, '0f6ca19c6bd2578d5d47c9b5a77e64f54070c9a92215b8e7ee3f5073c734a646', '2025-10-23 00:22:55', '2025-10-24 03:22:55', 0, '2025-10-24 03:26:27'),
(333, 6, '5aa0aff98c26559c6509b29533cdbdf7fe5f8c0abbee1b07ae345dfa31ef7cc2', '2025-10-23 00:25:15', '2025-10-24 03:25:15', 0, '2025-10-24 03:27:38'),
(334, 6, 'a27d2d37dd03a287cbc4c879922753060f97621e5f2c1a2f02e2f79ed80ec1d6', '2025-10-23 00:26:11', '2025-10-24 03:26:11', 0, '2025-10-24 03:27:38'),
(335, 6, 'a7a0ef6caefabcc513b9994c5774f72a03faee3a677dff958a583356fe986d98', '2025-10-23 00:26:23', '2025-10-24 03:26:23', 0, '2025-10-24 03:27:38'),
(336, 6, 'de4b7c17a221ccde314ab5f58215ae821f24e74a816777185fdb771953e0877d', '2025-10-23 00:27:53', '2025-10-24 03:27:53', 0, '2025-10-24 04:04:04'),
(337, 6, 'd32c18017c43c1b9ca5b55a741b7fbd6c4f21db22595af3f20972dc56b61d748', '2025-10-23 00:28:15', '2025-10-24 03:28:15', 0, '2025-10-24 04:04:04'),
(338, 6, '2ff2fe6e63ae84b98c1094191d8e1cf0800d47f4b6c807b064d39a8f40607e42', '2025-10-23 00:28:51', '2025-10-24 03:28:51', 0, '2025-10-24 04:04:04'),
(339, 6, 'c7617b31eae9fc3c5fa8a0f079b6fb792815318ac66585de8d59147da446b0ac', '2025-10-23 00:35:53', '2025-10-24 03:35:53', 0, '2025-10-24 04:04:04'),
(340, 6, '2ec11bc9ae97a8fb6d4e5101598307c02434b316ee2b59ff548cce7694d65874', '2025-10-23 00:36:19', '2025-10-24 03:36:19', 0, '2025-10-24 04:04:04'),
(341, 6, '7ac59c0538a64cce8a720794ce3ac5d8a8f2e698eddcbe090f33fb67ff925697', '2025-10-23 00:36:41', '2025-10-24 03:36:41', 0, '2025-10-24 04:04:04'),
(342, 6, '23f3f88ac052ebb9900c2d3ceddf5791986ab5e323b579716b10ec2a1c9c917d', '2025-10-23 00:37:01', '2025-10-24 03:37:01', 0, '2025-10-24 04:04:04'),
(343, 6, '86a81494d84029300960b4b8f4969aac13f78022a9448e53df4f4d44f68ef4e4', '2025-10-23 00:37:14', '2025-10-24 03:37:14', 0, '2025-10-24 04:04:04'),
(344, 6, '3e18184b991020d6f8767c4df75f528eabf969f823f5d85edd213d51313904e4', '2025-10-23 00:38:49', '2025-10-24 03:38:49', 0, '2025-10-24 04:04:04'),
(345, 6, 'a2181f96a6466c399c4a9c47bb624fadb60e6d14a555ea5de074e45eede4103e', '2025-10-23 00:41:15', '2025-10-24 03:41:15', 0, '2025-10-23 00:50:03');
INSERT INTO `sesiones` (`id`, `usuario_id`, `token_sesion`, `fecha_creacion`, `fecha_expiracion`, `activa`, `ultima_actividad`) VALUES
(346, 6, '5db119fe54d0861a62de411c34f18cb69a1965c1cbd705325985959eb5e70644', '2025-10-23 00:50:21', '2025-10-24 03:50:21', 0, '2025-10-23 00:53:45'),
(347, 6, 'e2e00daa1b35fc267df9c76de25be742d021f8e9454d6831c45de6aeb0c19c15', '2025-10-23 00:54:02', '2025-10-24 03:54:02', 0, '2025-10-24 04:04:04'),
(348, 6, '866219a14f3b03e3e904556c7521c2814c87197ef0ebb99a173cddbd7e753b2a', '2025-10-23 01:05:09', '2025-10-24 04:05:09', 0, '2025-10-24 11:27:22'),
(349, 6, '93b2cde115c1a19285078a9857a41455acb85d6799f6aba009a60599d25f1f8e', '2025-10-23 01:09:28', '2025-10-24 04:09:28', 0, '2025-10-24 11:27:22'),
(350, 6, '578166de8fb8d8ca696b87a790c59fadaa5205c5d3e6e66941b1927d9c5e00b4', '2025-10-23 01:16:31', '2025-10-24 04:16:31', 0, '2025-10-24 11:27:22'),
(351, 6, '9d427ab03968b2c287dc8748f2f1a1085075acfdc30d71b82ed88e32d0420be7', '2025-10-23 01:25:16', '2025-10-24 04:25:16', 0, '2025-10-24 11:27:22'),
(352, 6, '7e54e9f3c5f5d8884ae93ff63b9843f0bbcc2aed3874b5a72667d98679dfaaee', '2025-10-23 01:35:47', '2025-10-24 04:35:47', 0, '2025-10-24 11:27:22'),
(353, 6, '031423438467855ae4b58b54fe115f14dfd3744c23a8e591fd01b9321d7b7d9f', '2025-10-23 01:45:15', '2025-10-24 04:45:15', 0, '2025-10-24 11:27:22'),
(354, 6, '3ad28835ef274e76fbbd76c5f033cfdc3aaa9162eeac84f46da9e7e29d248a9a', '2025-10-23 01:49:24', '2025-10-24 04:49:24', 0, '2025-10-24 11:27:22'),
(355, 6, '96b03e4776376c38c87bf626052b4200594dece488d794a8d01462a97784e4c6', '2025-10-23 01:53:33', '2025-10-24 04:53:33', 0, '2025-10-24 11:27:22'),
(356, 6, '25dceda93b1fa2788987328a20728d2244762a5422f9785d4cd2cbbed57a6680', '2025-10-23 02:03:05', '2025-10-24 05:03:05', 0, '2025-10-24 11:27:22'),
(357, 6, '232e961a8fd3904439e50a831c8b4b1033fa2ba009e39af9a249f2049323a441', '2025-10-23 02:07:55', '2025-10-24 05:07:55', 0, '2025-10-24 11:27:22'),
(358, 6, '9ef75c811917afc32da74f564921e9f70ee03fdf72453737a7efb97997047366', '2025-10-23 02:16:32', '2025-10-24 05:16:32', 0, '2025-10-24 11:27:22'),
(359, 6, '33bf0646e25d5f150341e015ec84ac87d0ee3ec8d0dd0bb1dc7c5804359059b3', '2025-10-23 02:22:24', '2025-10-24 05:22:24', 0, '2025-10-24 11:27:22'),
(360, 6, 'cfb1558d63c68513020f9d15fcaf16a39c8851e6cc10d6b31000d82d941c3d32', '2025-10-23 02:28:02', '2025-10-24 05:28:02', 0, '2025-10-24 11:27:22'),
(361, 6, '8dfc1cc259ff1f10c35aab587b0ea69757b61938476cba76ebe9853d660ee98f', '2025-10-23 02:52:29', '2025-10-24 05:52:29', 0, '2025-10-24 11:27:22'),
(362, 6, 'ea9f994ab60f69816eca46c81108f5f781e5c49c7a55d38c1fc12c4a8c4b8be8', '2025-10-23 03:02:13', '2025-10-24 06:02:13', 0, '2025-10-24 11:27:22'),
(363, 6, 'e2b6f023041c4db6afdd0a349f0c5ba4e4ddd05872ab953d3cf2b5d40f4552c8', '2025-10-23 11:58:16', '2025-10-24 14:58:16', 0, '2025-10-23 12:23:11'),
(364, 6, '93aa68e9d35b523659d263b554241a9a1b8b2f8152440845f56de14a729db81e', '2025-10-23 12:23:24', '2025-10-24 15:23:24', 0, '2025-10-24 21:48:48'),
(365, 6, 'd3f146a08daec5a0dbd22420c684c2335fd0b2c821dbbc6b7299deb5779abc49', '2025-10-23 12:24:44', '2025-10-24 15:24:44', 0, '2025-10-24 21:48:48'),
(366, 6, '5185862f3766d70bd9508286181fdea85b244176a97f12f2f21c191736868c8a', '2025-10-23 12:26:27', '2025-10-24 15:26:27', 0, '2025-10-23 12:26:39'),
(367, 2, '64386aaca6585e0305a8d6571554db8e430d0014df58d35f125ce558b3061b37', '2025-10-23 12:26:50', '2025-10-24 15:26:50', 0, '2025-10-24 17:32:39'),
(368, 2, '7df608144469729056f36974c5e9310a57fe9f8e5bcc952f8e4fa746fd34e472', '2025-10-23 12:28:32', '2025-10-24 15:28:32', 0, '2025-10-24 17:32:39'),
(369, 2, 'dff0edefb68e5e4225b7e84ba9649cfe2f756802d2b2765b5eafc0ef9a8e84c5', '2025-10-23 12:30:43', '2025-10-24 15:30:43', 0, '2025-10-24 17:32:39'),
(370, 2, '078323d7bc0674ec6aa839d9453dbf8d8d197c7014c35356b50c594e4b63043c', '2025-10-23 12:56:31', '2025-10-24 15:56:31', 0, '2025-10-23 13:00:41'),
(371, 6, 'a44a049b7e87883672eb4ab222be62b8e8ebbadabab7be6f6ba3cb48d12e98e4', '2025-10-23 13:00:58', '2025-10-24 16:00:58', 0, '2025-10-24 21:48:48'),
(372, 2, 'cf503ddfdecdb9c88e2727e5d2ed1e0a5942a3405762f3124fe063fafe346094', '2025-10-23 13:01:45', '2025-10-24 16:01:45', 0, '2025-10-24 17:32:39'),
(373, 2, '114d016e5a2819efeb89a600726be8431473e970561dded260c3ee4e1b111f30', '2025-10-23 13:12:18', '2025-10-24 16:12:18', 0, '2025-10-24 17:32:39'),
(374, 6, 'ba22f765b6b44fe4a76d65cf1c957dd8b1e59f98bcff611e39c8669adcd94e32', '2025-10-23 13:24:39', '2025-10-24 16:24:39', 0, '2025-10-23 13:34:26'),
(375, 6, '01746ec7ddf58c1ec67d9d09b0af52e61aee3eca3b95b9674bd12016bc1833cf', '2025-10-23 13:38:21', '2025-10-24 16:38:21', 0, '2025-10-24 21:48:48'),
(376, 6, '8e9b3d300c7a8adb8e45b0bef7833c56f61b403ea32c57f55316f2b638584eee', '2025-10-23 13:47:56', '2025-10-24 16:47:56', 0, '2025-10-24 21:48:48'),
(377, 6, '1fc27166fe14fe52180e7ebd08cd70fbf69027aacb2c362c233e569553c16c21', '2025-10-23 14:35:33', '2025-10-24 17:35:33', 0, '2025-10-24 21:48:48'),
(378, 6, '19e8ee3fdc930e331163062cc00ec79cae15c9823d5be7b95d74cfb74682160c', '2025-10-23 14:45:03', '2025-10-24 17:45:03', 0, '2025-10-24 21:48:48'),
(379, 6, '4c2952a87e7270c85ba94081b47dd616b7d54b2212726403d82186aafcaeef52', '2025-10-23 14:46:46', '2025-10-24 17:46:46', 0, '2025-10-23 14:47:31'),
(380, 2, '6e4dca33ec1d1ebf28e4e1a9c17ccbbfd3e0a9ece3b9b53dc618417a26c16049', '2025-10-23 14:47:54', '2025-10-24 17:47:54', 0, '2025-10-23 14:48:15'),
(381, 2, 'f7c1e774e00bbcb72abe7fc40f863ac9bedf9f920d4cb93affa293838ecff073', '2025-10-23 14:48:32', '2025-10-24 17:48:32', 0, '2025-10-24 18:26:04'),
(382, 2, 'a8578465a8d457078ea741233bb571eb9af1b9f56f55168ec97b9f7f20a23128', '2025-10-23 17:28:57', '2025-10-24 20:28:57', 0, '2025-10-24 22:12:34'),
(383, 2, '64783be4aa44169824943920225086ba6ed370d2a699c4f5a2cdd00ec1734c9c', '2025-10-23 18:24:41', '2025-10-24 21:24:41', 0, '2025-10-24 22:12:34'),
(384, 2, '6a479434340982fab23d00388389a4c5b714b6da28605a6c1443b7a9df928005', '2025-10-23 18:54:59', '2025-10-24 21:54:59', 0, '2025-10-24 22:12:34'),
(385, 2, '2aac5880f200596d76fdd97354f381a7107d2e35c9ae4f5f0515462c3d975e87', '2025-10-23 18:58:13', '2025-10-24 21:58:13', 0, '2025-10-24 22:12:34'),
(386, 2, '6c9c834d50d4721339c1502740f903c2a99bc4ebf4ec23fdbf0c6c23bafea5d6', '2025-10-23 19:09:01', '2025-10-24 22:09:01', 0, '2025-10-24 22:12:34'),
(387, 2, '6789e3c443b15e4ba1ef8bb6842679fa48677382ca9263fde8ba8a1174d7f225', '2025-10-23 19:18:40', '2025-10-24 22:18:40', 0, '2025-10-24 22:18:41'),
(388, 2, 'efa8983ee2ecf6db91f133aaaf10b27bcb4ed3513e7301d998d7a61a98261e10', '2025-10-23 21:25:23', '2025-10-25 00:25:23', 0, '2025-10-25 00:39:53'),
(389, 2, 'dcbe00165d188ed0ef4ffae5d2f24486025d23c8614d5b00790d3bc17c174138', '2025-10-23 21:30:49', '2025-10-25 00:30:49', 0, '2025-10-25 00:39:53'),
(390, 2, 'bacabba582caaf85690e5d4a32a0b62c212eff46e0fc8196bd23f1e9c2809abb', '2025-10-23 21:42:31', '2025-10-25 00:42:31', 0, '2025-10-25 03:15:27'),
(391, 2, '1032194db2366787dff75165022665cfdcf5a804d01ed6ca041d93a086895459', '2025-10-23 22:40:57', '2025-10-25 01:40:57', 0, '2025-10-24 00:28:12'),
(392, 2, '7f4762f1729a6edd7dccc6f67cdd093a39a56e5590f953f9ba5a86d43d27e0f6', '2025-10-23 22:41:39', '2025-10-25 01:41:39', 0, '2025-10-25 03:15:27'),
(393, 10, '47e263eb49fcd0e1a34c3bc431aca0da07bf28f2a1d4e7187ba9c049227366bc', '2025-10-24 00:28:22', '2025-10-25 03:28:22', 0, '2025-10-24 00:29:24'),
(394, 6, '2d9f5cf7e27c834f12a7b8689caa04096acf549b63e82e5a4c5747903bfce902', '2025-10-24 00:29:33', '2025-10-25 03:29:33', 0, '2025-10-24 00:29:51'),
(395, 2, '1fc78e6fbc87478b4b8a74c486df77a98d3f5eaa5e86c26dfe775bccb348416a', '2025-10-24 00:29:59', '2025-10-25 03:29:59', 0, '2025-10-25 03:39:34'),
(396, 2, 'c6cda5fd4f45503ef2d185e304acc026734d54594fc36673468a0574b6c39ed7', '2025-10-24 01:04:44', '2025-10-25 04:04:44', 0, '2025-10-24 02:27:49'),
(397, 6, '564af51565943bad582fd824df4c2b609cd24faf6e1694f3217e4ca677363a3b', '2025-10-24 02:27:57', '2025-10-25 05:27:57', 0, '2025-10-28 15:20:55'),
(398, 6, '2c47cb052a56d9e17b330f02064215c48dfc79452a417bff8b2f9bbd37b766c3', '2025-10-24 02:40:12', '2025-10-25 05:40:12', 0, '2025-10-24 02:40:25'),
(399, 2, '6366075d1b1de28ee5ab24ea3459bbf722878ee5ac3af91c28d36220a53fe70d', '2025-10-24 02:40:36', '2025-10-25 05:40:36', 0, '2025-10-24 02:40:49'),
(400, 6, '27d3cb6becb03c5907ac4c12c1534d6ff5f15c65ebf7773f272b3907dfd1dc70', '2025-10-24 02:40:58', '2025-10-25 05:40:58', 0, '2025-10-28 15:20:55'),
(401, 6, 'e617604feec54bde1cb55e08479be018a2854e7bc8be75ac21941792e7edfd3c', '2025-10-24 02:49:59', '2025-10-25 05:49:59', 0, '2025-10-24 02:50:46'),
(402, 2, '602f614ee1e298e8c0e1e6c6ff40a4a59eaf9cb4d7df68124017f761b5e85e7f', '2025-10-24 02:50:53', '2025-10-25 05:50:53', 0, '2025-10-29 06:04:42'),
(403, 2, '20eee9611ebf9394898afbbbf7d466e36a313f9c435fe22a1bda7a99d037be11', '2025-10-24 02:50:54', '2025-10-25 05:50:54', 0, '2025-10-24 03:06:00'),
(405, 6, 'e9c4055ff3e083f83845c79a8f81c4c0751996631844833a173c15c961e5e509', '2025-10-24 03:06:08', '2025-10-25 06:06:08', 0, '2025-10-24 03:07:12'),
(406, 10, '7d717ad9aa9efdb062f5b332faecb77a5ed7b6d73a1a8189b4a543f3602b70e4', '2025-10-24 03:07:21', '2025-10-25 06:07:21', 0, '2025-10-24 03:07:35'),
(407, 2, '99fac5e715caeb33f6cda514866218de0dd6b08a0945658db78042d1f31be0ea', '2025-10-24 03:07:44', '2025-10-25 06:07:44', 0, '2025-10-24 03:08:21'),
(408, 10, 'd5984df07c056ee3d9b4dd54e3d2d40daff7b5c6f6e54e3b7cba46ecc250d0ce', '2025-10-24 03:08:30', '2025-10-25 06:08:30', 0, '2025-10-24 03:08:42'),
(409, 6, 'cb136b0df618f7b251532feb1c03947746cceeabf853982d6cead52755282b21', '2025-10-24 03:08:54', '2025-10-25 06:08:54', 0, '2025-10-24 03:19:17'),
(410, 10, '2eef5653f3cbc35be1ef62ad71e454c6461725137522bdae89b711bcd65b7b6a', '2025-10-24 03:19:25', '2025-10-25 06:19:25', 0, '2025-10-28 15:22:44'),
(411, 10, '857c409d5c86e9fda9d81e31a72f34f64af961fd90a845ef99612c81a846a14a', '2025-10-24 03:19:26', '2025-10-25 06:19:26', 0, '2025-10-28 15:22:44'),
(412, 10, 'c226c0c158fe34c1acf8411a24ba505c425b7e4630151d1acd8b2b64ae4c8156', '2025-10-24 03:20:14', '2025-10-25 06:20:14', 0, '2025-10-24 03:20:50'),
(413, 6, '4a4d51d4472107820b85d34d2c4b4842d518abf751a67e1c4caeefdc5592fb77', '2025-10-24 03:20:58', '2025-10-25 06:20:58', 0, '2025-10-24 03:21:24'),
(414, 10, '7260e7d45917c418f2dd2100ab96e37de4824bfe9e0b72347a02823f7a323a04', '2025-10-24 03:21:35', '2025-10-25 06:21:35', 0, '2025-10-28 15:22:44'),
(415, 10, 'c98262799f3f51a3b5ec7a1a6de63354e7c0bcb602356fc5ac474c376ae94fe5', '2025-10-24 03:22:03', '2025-10-25 06:22:03', 0, '2025-10-24 03:26:03'),
(416, 10, 'bf394f689f820e0e3ee88938093ab1777a7565f7e733d4f6d7e3977fba23c2ac', '2025-10-24 03:26:27', '2025-10-25 06:26:27', 0, '2025-10-24 03:26:58'),
(417, 2, '947f20feacf34fe3e885687597906c8dc1ee96366e94a47a98a4dd2c78f22680', '2025-10-24 03:27:09', '2025-10-25 06:27:09', 0, '2025-10-24 03:27:30'),
(418, 6, 'e4f2b3b6bad2a9fa76ec25427fb1f59d62d0b083658076d9903a138cc15705c9', '2025-10-24 03:27:38', '2025-10-25 06:27:38', 0, '2025-10-24 03:27:48'),
(419, 10, 'a56404fc4f249ca120ed0f524ee5477735eebbfd58e2a2d160be003744dbee1a', '2025-10-24 03:31:36', '2025-10-25 06:31:36', 0, '2025-10-24 03:31:43'),
(420, 10, '5eab02b286be6afb0f71338dea681f01bed027a6fcc047309d0a45b9f284be3c', '2025-10-24 03:32:09', '2025-10-25 06:32:09', 0, '2025-10-24 04:03:57'),
(421, 6, 'c0ccd224006fd7702c9eb104854eca996e3c3be6c9236364d68f67b0403ee699', '2025-10-24 04:04:04', '2025-10-25 07:04:04', 0, '2025-10-24 04:15:50'),
(422, 10, 'e53d991314b8c42c60a9f8ab5fac59484cdb128eb188ac83ae65c47ba777efb7', '2025-10-24 04:15:59', '2025-10-25 07:15:59', 0, '2025-10-28 15:22:44'),
(423, 10, '94babebe769efb7102c7d6d970befc89d791bf898145f9a55911d44e4cec2ada', '2025-10-24 04:26:56', '2025-10-25 07:26:56', 0, '2025-10-24 04:28:34'),
(424, 2, '60c12c08c5e8277db8d1b82831be7a9dc65125c2963c8bcedf175993d4d069be', '2025-10-24 04:28:44', '2025-10-25 07:28:44', 0, '2025-10-24 04:29:03'),
(425, 10, 'ab80ed0a78770c2db30b1073f5fef2e0bb34d71431a94ddc7e497b7472473bd7', '2025-10-24 04:29:13', '2025-10-25 07:29:13', 0, '2025-10-28 15:22:44'),
(426, 2, '3b33f473768b236f0ea1a65a34c5b4c543211e13a94f80a619f35e2f344aa270', '2025-10-24 11:21:50', '2025-10-25 14:21:50', 0, '2025-10-24 11:22:26'),
(427, 2, '1f2cdffcd37d3331cab4958ec4b94346c2d3c46b2b7da6e09200b6b134967554', '2025-10-24 11:22:35', '2025-10-25 14:22:35', 0, '2025-10-24 11:24:03'),
(428, 2, 'acb4591b8c094acc4034434c3ef9c12671580dbee6a84b77a643b19e2f1e61f2', '2025-10-24 11:24:11', '2025-10-25 14:24:11', 0, '2025-10-24 11:25:03'),
(429, 2, '6f5babcb8de61f3cb7106041574b92907d3bca5b931ce995be263aac4b4f2c0c', '2025-10-24 11:25:28', '2025-10-25 14:25:28', 0, '2025-10-24 11:27:15'),
(430, 6, 'f52c34b24acd5adac9df4b630d0d70fe3d60891d6698307f20dcdd36f3e109bc', '2025-10-24 11:27:22', '2025-10-25 14:27:22', 0, '2025-10-24 11:31:38'),
(431, 2, 'edd4a3edb5ec9782fc4caa2a4d1f5721b175e97543933fd08c339f02d114b1c7', '2025-10-24 11:31:48', '2025-10-25 14:31:48', 0, '2025-10-24 11:32:12'),
(432, 2, '91d5fba5f8b05b7ca321cd86f9a75f3a3fa7f3ca84060c20b3fabecaea1033d6', '2025-10-24 11:32:20', '2025-10-25 14:32:20', 0, '2025-10-24 11:32:25'),
(433, 6, '1cfe2515f1ed60d3440149bea30455213de3cf70f23b7f70c01a91147cd69920', '2025-10-24 11:35:11', '2025-10-25 14:35:11', 0, '2025-10-24 11:38:28'),
(434, 2, '0fc351bf844c2a4086440235e9ae58b602fbd6c1d13e19e1400beaa161755135', '2025-10-24 11:38:36', '2025-10-25 14:38:36', 0, '2025-10-24 11:38:45'),
(435, 10, '895d40321770590ec13c3ca14f0b1caf1b7af4ef0f4336f06f1d06b5cbe5ca3b', '2025-10-24 11:38:53', '2025-10-25 14:38:53', 0, '2025-10-28 15:22:44'),
(436, 6, 'ba66706630a43a9c8af44358dbbf135aec885ac63cd2b976b5261c7c0aa7a2fb', '2025-10-24 12:08:40', '2025-10-25 15:08:40', 0, '2025-10-24 12:09:11'),
(437, 10, 'edd3ab575bc20ba9fb8b64377d26141a93cb281d5e492f19f5c4cb664984617a', '2025-10-24 12:09:20', '2025-10-25 15:09:20', 0, '2025-10-24 12:10:22'),
(438, 10, 'c24716daf9e713c49e41d55ecce953110889a65cf0dc670ac327ca63c3816fde', '2025-10-24 12:12:32', '2025-10-25 15:12:32', 0, '2025-10-24 12:17:58'),
(439, 10, 'ae933675f63ac35fc7672a7333e6a9b22dbba04ab13670b45e72e0b49f5c0715', '2025-10-24 12:18:06', '2025-10-25 15:18:06', 0, '2025-10-24 12:36:26'),
(440, 10, '64c669551aa6909a12ad082d540342a5472f4c29e466e0486a322c5081855be7', '2025-10-24 12:36:33', '2025-10-25 15:36:33', 0, '2025-10-24 13:26:25'),
(441, 2, '74e020d027b01f3af2c531df51f011139e5c2320f85e1ed728eacc0d25effabd', '2025-10-24 13:26:34', '2025-10-25 16:26:34', 0, '2025-10-24 13:38:27'),
(442, 10, 'b213573af08f42a835c734834ad77590f0e740537e223ad90c98f6ffc761bec3', '2025-10-24 13:38:36', '2025-10-25 16:38:36', 0, '2025-10-24 14:01:06'),
(443, 2, 'c78bd87658b8e22dd6e474f96e8ddde56bc54b4f7b99dc9b8a4f13183c2b55f0', '2025-10-24 14:01:18', '2025-10-25 17:01:18', 0, '2025-10-24 14:01:41'),
(444, 10, '359681331d7d8a4739a17c91bde5b0d4dc2fd5ba817a4fc9427ba6e5821e26ce', '2025-10-24 14:01:51', '2025-10-25 17:01:51', 0, '2025-10-24 14:35:16'),
(445, 2, '7e62e0ac68ad2866aab3fee350b08bf08c9f1d9b38420db85d1503483096db92', '2025-10-24 14:35:24', '2025-10-25 17:35:24', 0, '2025-10-29 06:04:42'),
(446, 2, 'd88fb8fd4e4a35dd0b63562594bae93bcb958108117217a0ff198e0bf352ad20', '2025-10-24 17:32:39', '2025-10-25 20:32:39', 0, '2025-10-29 06:04:42'),
(447, 2, '59666a665ab49dc5b6552fb3858aa9d6b6afc85c7c287af3ab899fd72a838199', '2025-10-24 17:48:17', '2025-10-25 20:48:17', 0, '2025-10-29 06:04:42'),
(448, 2, 'b0ea2442a1a2949dab0921295ddc8330e6aa6bbc1a8ff622caee4343c5e46abc', '2025-10-24 18:26:04', '2025-10-25 21:26:04', 0, '2025-10-29 06:04:42'),
(449, 2, '8fb80eaa36ef9eb1bc8a7e0d874e2c019305324eb47eb9597fdb24230ca4343a', '2025-10-24 18:33:22', '2025-10-25 21:33:22', 0, '2025-10-29 06:04:42'),
(450, 2, 'ba1524e414293e8fd7a49ad1d72b52a8a5d77abf932b67c9225fbbdfd1aabf22', '2025-10-24 18:35:10', '2025-10-25 21:35:10', 0, '2025-10-29 06:04:42'),
(451, 2, '1304fe0b491048a60526045d9f83382f88ccc4700fe0f4386e0d5920f234f59c', '2025-10-24 18:36:48', '2025-10-25 21:36:48', 0, '2025-10-29 06:04:42'),
(452, 2, '4debcf708bb9888affd0a53ad3264e1dfb8cc94c67aee203ea161faff5decfde', '2025-10-24 18:48:36', '2025-10-25 21:48:36', 0, '2025-10-29 06:04:42'),
(453, 2, 'e8ac4f69eb9e991e1bc854e7ca5e82e0ff7f48afee449bc2f35c6d7dfb2e17e3', '2025-10-24 19:24:44', '2025-10-25 22:24:44', 0, '2025-10-29 06:04:42'),
(454, 2, 'e47bf7992a4f2be72476dcb47a184104b79242a8192f4d14a7a7243d424fc38d', '2025-10-24 20:05:30', '2025-10-25 23:05:30', 0, '2025-10-24 21:48:13'),
(455, 6, '078836260462c3a6a4b016134b0f304deee7d8062d274f1ef566d7bb2e346d81', '2025-10-24 21:48:48', '2025-10-26 00:48:48', 0, '2025-10-24 22:12:09'),
(456, 6, 'eeb3a2502da5b24b4a16b48952495613e2ccbe596243c98c1c4699dc48ab246e', '2025-10-24 22:12:16', '2025-10-26 01:12:16', 0, '2025-10-24 22:12:25'),
(457, 2, 'fd6782287da605a2be3201e1913b1a13d31fd93009467514e7939f0daadbd317', '2025-10-24 22:12:34', '2025-10-26 01:12:34', 0, '2025-10-24 22:13:21'),
(458, 2, 'c3817c8b9257ff9870a3107d1f8179554c74c6c9c7c9e9e12de2f22a3bc6d20f', '2025-10-24 22:13:29', '2025-10-26 01:13:29', 0, '2025-10-24 22:18:33'),
(459, 2, '8e435654ab8021b39f056289723f0f3c1ee07374fc30329cc19cee5602ad96cd', '2025-10-24 22:18:41', '2025-10-26 01:18:41', 0, '2025-10-24 22:19:35'),
(460, 6, 'f27bc06411e6e7c10da827b9de30742d0924278c6b428aba0479d817d7dabfe9', '2025-10-24 22:19:42', '2025-10-26 01:19:42', 0, '2025-10-24 22:19:51'),
(461, 2, 'e388b80640ff23b4e6b49abc2a64f1b004fb3547b1c21715698e18c3e3c0844a', '2025-10-24 22:20:02', '2025-10-26 01:20:02', 0, '2025-10-24 22:30:59'),
(462, 6, '6d4a01eba2fa3ad08a068248c6fd7023fbd984340f1a10a5e4d3e37d1a47a512', '2025-10-24 22:31:06', '2025-10-26 01:31:06', 0, '2025-10-24 22:31:28'),
(463, 10, '46129c2bade88f79e2138fa183dce89a9d4045c1c623db62f89d020f783d46c5', '2025-10-24 22:31:40', '2025-10-26 01:31:40', 0, '2025-10-24 22:31:51'),
(464, 6, '9e182cf2577dd3f0734063ab4d40b57aead79e7e8b7bee173092445852ebadc2', '2025-10-24 22:32:01', '2025-10-26 01:32:01', 0, '2025-10-28 15:20:55'),
(465, 2, '5701a2c1ff79b17b1c9baeb5b144bc3a215cce8bb26ce041113433bfd091905b', '2025-10-24 22:59:28', '2025-10-26 01:59:28', 0, '2025-10-29 06:04:42'),
(466, 2, '09d4b8948f9857892d759321773c266fb12d80672566c125bf10a5f668de63ae', '2025-10-24 23:02:23', '2025-10-26 02:02:23', 0, '2025-10-24 23:39:11'),
(467, 2, 'a50ccebced50e8d9ca107726af290c763b9c46a6d5fe9030e4d6ea906d87647c', '2025-10-24 23:39:21', '2025-10-26 02:39:21', 0, '2025-10-24 23:40:12'),
(468, 2, '727520abb04be4e6e3f0fa1dd8283189561bd812d964d80a32ef87911dd8837d', '2025-10-24 23:40:23', '2025-10-26 02:40:23', 0, '2025-10-24 23:42:35'),
(469, 2, '2cf810ee7b6a361381aca59990b2b8909c7ab055fe773f61a5795cde3b7ad51f', '2025-10-24 23:42:44', '2025-10-26 02:42:44', 0, '2025-10-25 00:08:21'),
(470, 10, 'c3248513971f82c1c528c8d51068366a7ae7b59c3cad56a3e0c0f4fc88156396', '2025-10-25 00:08:27', '2025-10-26 03:08:27', 0, '2025-10-25 00:19:33'),
(471, 10, '3165ce95fabf9dcfbc2a094ed2037f8b580a1d2e8c74bf9aeac6bf24d06147ec', '2025-10-25 00:19:54', '2025-10-26 03:19:54', 0, '2025-10-25 00:20:15'),
(472, 2, '576980bde487d7a2b9cd56e4824cfebaa33c01df132f113b814c811b5d2bae0c', '2025-10-25 00:39:53', '2025-10-26 03:39:53', 0, '2025-10-25 03:04:54'),
(473, 10, 'f799902bcfe4d3528737bd0a04e7ff0ecb8485ceb49f5a76f92a81e769f8ef09', '2025-10-25 03:05:04', '2025-10-26 06:05:04', 0, '2025-10-28 15:22:44'),
(474, 10, 'b2b149d49107440bb268eee1d0cb3ab6abc2eabc2efb95370136f64ee5b24427', '2025-10-25 03:13:52', '2025-10-26 06:13:52', 0, '2025-10-28 15:22:44'),
(475, 2, 'b210270bd96d7555029307799cc9148ec083c28cf73b8d2520d6a8f0f16dc5d5', '2025-10-25 03:15:27', '2025-10-26 06:15:27', 0, '2025-10-29 06:04:42'),
(476, 2, 'a8e64eea495aae83f2b065e7ebcd8a2c7af6da11f16b0fd36a0610e7d6edbf29', '2025-10-25 03:18:44', '2025-10-26 06:18:44', 0, '2025-10-29 06:04:42'),
(477, 6, '0c4805684d3b844f395cd1f7457145104f518bfc2e2d09a86e310dedd086401f', '2025-10-25 03:19:05', '2025-10-26 06:19:05', 0, '2025-10-25 03:39:18'),
(478, 2, 'e2f5331e2eb4247149ca0a9c1bac380d54a6b3e7c04c8a7aace3d52f652cced8', '2025-10-25 03:39:34', '2025-10-26 06:39:34', 0, '2025-10-25 03:39:42'),
(479, 10, '38165da7c04c0e10ab2542f510c85cf0a320264cf91850b75041a17f09676cce', '2025-10-25 03:39:50', '2025-10-26 06:39:50', 0, '2025-10-28 15:22:44'),
(480, 10, '6b000428f54e9e8628e69e25eaf7852f33cdfb6894d47d5473c84d02ec17cdbc', '2025-10-25 03:58:28', '2025-10-26 06:58:28', 0, '2025-10-25 04:00:25'),
(481, 6, 'f77a6df68e2e99fc1cce1bcdfe6877d856f76f6887fdc6110b4184b496cf9cb4', '2025-10-25 04:00:32', '2025-10-26 07:00:32', 0, '2025-10-25 04:00:41'),
(482, 10, 'e0e28b050265c01be0d4dc86f0b53774cb6ee42c04475576b735e1866887be41', '2025-10-25 04:01:00', '2025-10-26 07:01:00', 0, '2025-10-25 04:01:21'),
(483, 6, 'd02e5bd58e2a731090cb61913fb70ef1f1f51c1d10dad443601ad3fbfb7586e9', '2025-10-25 04:01:31', '2025-10-26 07:01:31', 0, '2025-10-25 04:01:50'),
(484, 10, '00cc4c9c962ae239d68b67f337466d27ea3f45a1998aea284969cc14ea346f44', '2025-10-25 04:02:00', '2025-10-26 07:02:00', 0, '2025-10-25 04:07:03'),
(485, 2, '0f02e8915121ee261b887fe8b56bc6014f12e926a2f45719a7a9d90ed924ed23', '2025-10-25 04:07:11', '2025-10-26 07:07:11', 0, '2025-10-25 04:11:46'),
(486, 10, '85fb379966cd85059b0ab5eb081016c343b0a212c93de3bbfb8832088593fa27', '2025-10-25 04:11:54', '2025-10-26 07:11:54', 0, '2025-10-28 15:22:44'),
(487, 10, '8905ad29414f732967aa0a17c91f3e24169d153166b97ab971020ab35213eba3', '2025-10-25 04:12:37', '2025-10-26 07:12:37', 0, '2025-10-25 04:14:34'),
(488, 6, '958095576ad2ce2ba7bf99d4ac7b91c07300530be8a878c2687658e4aa284b11', '2025-10-25 04:14:46', '2025-10-26 07:14:46', 0, '2025-10-28 15:20:55'),
(489, 2, '5e649c77f3cc8b0267c7acee9df713815900cb23d1dc98e04746518418320297', '2025-10-25 04:45:47', '2025-10-26 07:45:47', 0, '2025-10-25 04:52:47'),
(490, 10, 'd9eb150e63f5d238cb49759155282fd8444fa2fee1e3ee9b8c2b9715a8636dff', '2025-10-25 04:52:56', '2025-10-26 07:52:56', 0, '2025-10-28 15:22:44'),
(491, 6, '7827133fc4f1d4605e7dba196dbc35ad409b58bcd27d99d9b640a8f97db01df7', '2025-10-25 04:53:19', '2025-10-26 07:53:19', 0, '2025-10-25 04:53:43'),
(492, 10, '6cf8b7167b9934e91ff1bf7db1e809672d32b0845c830fa690a6b1ae681cf8de', '2025-10-25 04:53:51', '2025-10-26 07:53:51', 0, '2025-10-28 15:22:44'),
(493, 6, 'c49dc339b827fce4935529a8275f4434580019c7a53a2609a8cc7bbe180b15af', '2025-10-25 05:00:54', '2025-10-26 08:00:54', 0, '2025-10-25 05:04:11'),
(494, 2, 'ecafccc7ab4c22d72bdb97e36209be791704f2083ac870fba88cc40a8685b0eb', '2025-10-25 05:04:30', '2025-10-26 08:04:30', 0, '2025-10-25 05:07:17'),
(495, 10, '824a01e494bfc949cd225fdebb9095f5b8560999e4b732c6c6d3f2ac2684d1e0', '2025-10-25 05:07:24', '2025-10-26 08:07:24', 0, '2025-10-25 05:07:51'),
(496, 6, '9094c90303fc1e5d7048f14c97d9a4a3c9a4463993eea6176416d4621087fc21', '2025-10-25 05:07:59', '2025-10-26 08:07:59', 0, '2025-10-25 05:08:15'),
(497, 2, 'db2ed81f19d2bb871aa5b6ae897f267710df47b4da8762cb5c6e19f4336e64cb', '2025-10-25 05:08:24', '2025-10-26 08:08:24', 0, '2025-10-29 06:04:42'),
(498, 2, '176134f2c9a7623db9af714fc663b50e353ba67a4b635b106f5c433ce7563ef5', '2025-10-25 05:08:56', '2025-10-26 08:08:56', 0, '2025-10-29 06:04:42'),
(499, 2, '7b4e3c7ebdc803bc80db8cf04558a1737122aeff4525f2df14944d95d266d1d2', '2025-10-25 05:20:17', '2025-10-26 08:20:17', 0, '2025-10-25 05:20:31'),
(500, 10, '5625c87fc4d4eea0bd47f72e92bdc13dd736a66baf95357ddfb8b67c37d85107', '2025-10-25 05:20:39', '2025-10-26 08:20:39', 0, '2025-10-25 05:21:49'),
(501, 2, '548d191552dd2ff38f9092302bfb68853223de0cd4bf6854d15f32ab90a55df3', '2025-10-25 05:22:13', '2025-10-26 08:22:13', 0, '2025-10-29 06:04:42'),
(502, 6, '7eb2a2fb2000e3a08b4685a9a5d6ac363b361f01b7ee8067d9f0f01706a7cb5b', '2025-10-28 15:20:55', '2025-10-29 18:20:55', 0, '2025-10-28 15:21:53'),
(503, 6, '51788661a75b4fc6824d67bd289839ae4bcaa8f4b4a11d381002f84796975436', '2025-10-28 15:22:00', '2025-10-29 18:22:00', 0, '2025-10-28 16:37:44'),
(504, 10, 'e3bd37603459ea3382b160a3548f11d467bf154e97ff188578bcf2c908ed4cb9', '2025-10-28 15:22:44', '2025-10-29 18:22:44', 0, '2025-10-28 15:27:00'),
(505, 11, '4d5e5a8ac529e94098b9cdde4e003ac675bcc42edbe396edb602dbe5aba94c9e', '2025-10-28 15:27:10', '2025-10-29 18:27:10', 0, '2025-10-28 15:28:03'),
(506, 10, '2cfaae9da27f5713de0abcac9666220a54d69a96ac0261e936d3e6f3f21d8a40', '2025-10-28 15:28:13', '2025-10-29 18:28:13', 0, '2025-10-28 15:28:33'),
(507, 11, 'f341ed12bc2c5c178578949657be49e2601ea194d6a1a008d17e65128220e0e5', '2025-10-28 15:28:42', '2025-10-29 18:28:42', 0, '2025-10-28 15:29:50'),
(508, 10, 'c822a0e7262aa42273041aaa6de1d21a439693bafa45241701422985d13b7b57', '2025-10-28 15:30:05', '2025-10-29 18:30:05', 0, '2025-10-28 15:53:57'),
(509, 11, 'a1d306bb0363114e9ec12279e82284421c906bd21cca325b5465e2c9c6b3472f', '2025-10-28 15:54:34', '2025-10-29 18:54:34', 0, '2025-10-31 18:15:29'),
(510, 10, '251ce186352e8efdb9a315b5dbddb0560b6a7ed5177d07741e2a6551b2f07f01', '2025-10-28 16:37:52', '2025-10-29 19:37:52', 0, '2025-10-28 16:38:10'),
(511, 11, '17cf9a6bf16d062ef7431c4ab6fede61235636724bce2ab8d595a287096c3068', '2025-10-28 16:38:19', '2025-10-29 19:38:19', 0, '2025-10-28 16:43:26'),
(512, 11, 'f15a1aab83582b78f17bd27595e161c2625f7d87bd3c367c17e58f727a19cb23', '2025-10-28 16:43:35', '2025-10-29 19:43:35', 0, '2025-10-28 16:43:58'),
(513, 10, 'ec49a4501df3baf93b01a28858b3bf7143c92174e5734ed654c7b1612e8025ca', '2025-10-28 16:44:06', '2025-10-29 19:44:06', 0, '2025-10-28 16:44:50'),
(514, 11, 'e3400a8a4a6afe980245fb68e9464953fde71cc9f9f71897ba57eaec502c1d39', '2025-10-28 16:45:00', '2025-10-29 19:45:00', 0, '2025-10-28 19:43:42'),
(515, 6, '2c716579c02cfa0d8e794f1934566bf5cef36653d0e1d7ade7e7b02062b9a1c6', '2025-10-28 19:44:30', '2025-10-29 22:44:30', 0, '2025-10-28 19:45:11'),
(516, 11, '2791b4c6b3d5e521618de55b146bf72dd2c4cfd53fce49d45a2425eb335a0053', '2025-10-28 19:45:19', '2025-10-29 22:45:19', 0, '2025-10-28 19:49:20'),
(517, 10, 'ee2be91da7adfaaec1225c2c2efb8774385a447df5cca22e5c3c2110d7a6cf84', '2025-10-28 19:49:33', '2025-10-29 22:49:33', 0, '2025-10-28 19:50:02'),
(518, 6, '6b3f15476975e580d741f5d9d3943a15863083e8416c94a020d42d0d5c642e13', '2025-10-28 19:50:10', '2025-10-29 22:50:10', 0, '2025-10-28 19:50:54'),
(519, 10, 'c4298c070b708bd84553f4964e1de5b8715221e9dcf3577dd5c30613014a2478', '2025-10-28 19:51:05', '2025-10-29 22:51:05', 0, '2025-10-28 21:27:41'),
(520, 10, 'd1b6114d5989f2e181d0d07243dad822b56f0dedd49cdbb3f3b94ed2beb880e0', '2025-10-28 21:27:49', '2025-10-30 00:27:49', 0, '2025-10-28 21:43:40'),
(521, 10, 'e14fb2875acefbb2ffb919c56df08d34b359d02c1e08fe9bf1031a84692b7f51', '2025-10-28 21:43:50', '2025-10-30 00:43:50', 0, '2025-10-30 02:25:24'),
(522, 6, '6d8ada5d2c83a8fca4fdcf9b685f8b1a06c683c68d32806b24e92db0923f00b1', '2025-10-28 21:45:12', '2025-10-30 00:45:12', 0, '2025-10-28 21:45:56'),
(523, 10, 'd9c69b4e1c39c9733b3decdbd8c54035b056a048df7b2a5b6630de3a5d7334be', '2025-10-28 21:46:04', '2025-10-30 00:46:04', 0, '2025-10-28 21:46:53'),
(524, 6, 'c41b75dbfbc27c6566552808c3f3dc8e61ed5ec762a9ff31cbde9befa3236c51', '2025-10-28 21:47:01', '2025-10-30 00:47:01', 0, '2025-10-28 21:57:54'),
(525, 10, '5af731c748a887a7f072ab831f7c91060c407fa1d29a82af568066656b766e46', '2025-10-28 21:58:11', '2025-10-30 00:58:11', 0, '2025-10-28 22:09:42'),
(526, 6, 'd8657a192391a1e47da57f14b02683b46ffb9a5f6a50bba29d4d858c1ec840e9', '2025-10-28 22:09:50', '2025-10-30 01:09:50', 0, '2025-10-28 22:13:06'),
(527, 10, '38e38758cc4e57e4da72418671e8726eb7d8af35247b5514b9ea4b7453e69883', '2025-10-28 22:13:14', '2025-10-30 01:13:14', 0, '2025-10-30 02:25:24'),
(528, 10, 'e4c8fc046558cd7ec066dd9364cd1bf0197bee979d88d49767936fdcfcab4068', '2025-10-28 22:14:34', '2025-10-30 01:14:34', 0, '2025-10-30 02:25:24'),
(529, 10, '974ad60dcd621829f4280ce8472a8b48103e0dbc0c80c4b6b62c4d12c5ac2ab6', '2025-10-28 22:19:03', '2025-10-30 01:19:03', 0, '2025-10-28 22:32:21'),
(530, 6, 'cc840209ef882640526a13c6f13bc37ac6a0e50c4467e4c02987d0b5a5930fb3', '2025-10-28 22:32:31', '2025-10-30 01:32:31', 0, '2025-10-28 22:33:00'),
(531, 11, 'af7f74b6f8f51b97181927842200f1065e219c9886ee54ce2d7fa4d3a12f7c41', '2025-10-28 22:33:13', '2025-10-30 01:33:13', 0, '2025-10-28 22:33:51'),
(532, 10, '380a9276e3f85dc915af2f09bb238ed5246ca85559f9eb26c8ebb14f61a69d9e', '2025-10-28 22:34:02', '2025-10-30 01:34:02', 0, '2025-10-28 22:37:59'),
(533, 6, '64466add68bdf5126144e2a816cd91aed2b49796689a2ae844fcea65f357537c', '2025-10-28 22:38:07', '2025-10-30 01:38:07', 0, '2025-10-28 22:38:59'),
(534, 6, 'b4934a2cccf0a87fe094eb538cb86cb0b5206ee7db77e2c404d12997ca9f7971', '2025-10-28 22:39:10', '2025-10-30 01:39:10', 0, '2025-10-28 22:40:10'),
(535, 11, 'b8c5aed82ab1d5befe0d628265077a0b2c1e02d717ac988524baee28c45427d7', '2025-10-28 22:40:20', '2025-10-30 01:40:20', 0, '2025-10-28 22:40:54'),
(536, 10, 'bcdc39530242a54299f1446f203afa08529874d7b84c0d2ed9945ae08e72fa68', '2025-10-28 22:41:01', '2025-10-30 01:41:01', 0, '2025-10-30 02:25:24'),
(537, 6, '6da825fbfa6623e11c299c362c374e5ca76def6802fb6f3f1561d477904c51e5', '2025-10-29 01:04:12', '2025-10-30 04:04:12', 0, '2025-10-29 01:04:53'),
(538, 10, '4970f392dfdad9322151b38e3de9ad0fde0c83b3425651317473daee82254703', '2025-10-29 01:05:00', '2025-10-30 04:05:00', 0, '2025-10-29 01:05:51'),
(539, 6, '7dd3697c36bdba1bb2299ea71350a8ab0bffffc2fd018e94c59df1d12365bd25', '2025-10-29 01:06:00', '2025-10-30 04:06:00', 0, '2025-10-29 01:09:39'),
(540, 10, 'b9f19a1d65979d3dc7fd463de3ea296605bcbf7d9348e9289b1b312c94fb30a2', '2025-10-29 01:09:48', '2025-10-30 04:09:48', 0, '2025-10-29 01:10:38'),
(541, 6, 'f241e9c4010b9efca5b78a17e2ce14c20c00b7ca6bbea10e837f9d11a33420bd', '2025-10-29 01:10:53', '2025-10-30 04:10:53', 0, '2025-10-29 01:11:18'),
(542, 10, '0c553e2f37ec792af3640756bf9ee3f0d5e1871d4eeb8c30b85ea99ad019c941', '2025-10-29 01:11:27', '2025-10-30 04:11:27', 0, '2025-10-29 01:14:33'),
(543, 11, 'f869ec3e0df21eba25ec6ec614a64943e502f71f92db3c73eaaf391377c5a8fb', '2025-10-29 01:14:42', '2025-10-30 04:14:42', 0, '2025-10-31 18:15:29'),
(544, 6, '8d7db18afb2951941d21cbab3dccb6d5e945f1b2487065a593734c6a932db85c', '2025-10-29 01:18:02', '2025-10-30 04:18:02', 0, '2025-10-29 01:26:41'),
(545, 10, 'b42a4b536881669d88a23dc4c1831ccbf0a41af8e4c6189016e37742f7a3591f', '2025-10-29 01:26:51', '2025-10-30 04:26:51', 0, '2025-10-29 01:31:07'),
(546, 6, 'e46aad775dfa15468e8a4adac41012c06de53667732f40e2f00682e9c6ea3e42', '2025-10-29 01:31:25', '2025-10-30 04:31:25', 0, '2025-10-29 01:48:25'),
(547, 10, '079739d2e83a9828f52fef329d4a76b088ce0d3ac38d644f16b3fb3875fb848c', '2025-10-29 01:48:33', '2025-10-30 04:48:33', 0, '2025-10-29 01:49:15'),
(548, 10, '2942813de350c50632b96d2e1e81f570f1430bbf02f1741f47cad085d66f0b44', '2025-10-29 01:51:22', '2025-10-30 04:51:22', 0, '2025-10-29 01:51:40'),
(549, 11, 'a894f451b9cee3df2a6f5939a2d882cb0c3a524745180b494cc165399f74bf9c', '2025-10-29 01:51:49', '2025-10-30 04:51:49', 0, '2025-10-31 18:15:29'),
(550, 11, '5e7ec81b8df831232d2b908340eaff002f9a8a93d99172eba46e8f317a76c2a8', '2025-10-29 01:52:25', '2025-10-30 04:52:25', 0, '2025-10-29 01:52:55'),
(551, 6, 'c27a99ddbafd5848327f211a456c54114de0ad3abcb7cb0ff083faa558e201ff', '2025-10-29 01:53:05', '2025-10-30 04:53:05', 0, '2025-10-29 01:58:04'),
(552, 10, 'ed30477282b6d736ce20d691548cb706f4c14a3bc90340292e615fa761241cc4', '2025-10-29 01:58:12', '2025-10-30 04:58:12', 0, '2025-10-29 01:58:56'),
(553, 10, 'f21d6840b156c892525042f59bd02f646afa5d9a094e8f13aed418c5fb659958', '2025-10-29 01:59:05', '2025-10-30 04:59:05', 0, '2025-10-29 01:59:54'),
(554, 6, '57c59ab014fb4ad992d513555f507572ee37522bd4b0bd0d9ee65894f440db6f', '2025-10-29 02:00:04', '2025-10-30 05:00:04', 0, '2025-10-29 03:48:15'),
(555, 10, '8d241c3f31423a2475e61ec59232da5267cb85f4ea3ce3270db98fe0c0490d85', '2025-10-29 03:48:23', '2025-10-30 06:48:23', 0, '2025-10-29 03:49:55'),
(556, 10, '8473c5bd1f43c657c103679ddd712f7ac2dc73893eee0342d514200e3e6fc2e2', '2025-10-29 03:50:03', '2025-10-30 06:50:03', 0, '2025-10-29 03:50:19'),
(557, 6, '6f389aa18aa2f7b17140f2071522995b83b0fb39b2122322bd1960c9038dd00b', '2025-10-29 03:50:30', '2025-10-30 06:50:30', 0, '2025-10-29 03:51:27'),
(558, 10, 'dbb94f7d662f8c92018e4258b0fe4d64a7d7c2f31996cda66087474801570a9d', '2025-10-29 03:51:40', '2025-10-30 06:51:40', 0, '2025-10-29 03:53:35'),
(559, 6, 'f1f84cdd2a5e58032e59070c8d2e25c4813e96d34d8d9a1fec29d9605d99a55c', '2025-10-29 03:53:51', '2025-10-30 06:53:51', 0, '2025-10-29 03:55:21'),
(560, 11, '4f23182b622f833b4482d6219048b836c10b7cff970e23cefa9d3738a12d895a', '2025-10-29 03:55:28', '2025-10-30 06:55:28', 0, '2025-10-29 03:55:52'),
(561, 10, '25234d7eb2bcb0a78be5a2fe4a056211a0d5920eb6a4b7f274e2654ee3624063', '2025-10-29 03:55:58', '2025-10-30 06:55:58', 0, '2025-10-29 03:57:21'),
(562, 6, 'd029ddc56fb1d47d30ec7f10aef6aef06be7116f73d0f2fad2c6a9393eaa8d56', '2025-10-29 03:57:31', '2025-10-30 06:57:31', 0, '2025-10-29 03:58:00'),
(563, 10, '69d97f628a24bc994461193692e9a6ff82d5fc857055545f01115b71f4017970', '2025-10-29 03:58:11', '2025-10-30 06:58:11', 0, '2025-10-29 04:02:06'),
(564, 6, '9cc9ada687f66cc2116b04a9c8f7ce4f1a0d3d50870dc474e06e8a61d432d1c0', '2025-10-29 04:07:17', '2025-10-30 07:07:17', 0, '2025-10-29 04:08:19'),
(565, 10, '07dbb243391eae97ec18000e26802fced1ff4dc938e82375f64beba7eec70b96', '2025-10-29 04:08:27', '2025-10-30 07:08:27', 0, '2025-10-29 04:13:28'),
(566, 6, 'b65f2749c3138f6f781750c0f088403794fdb594fcfe510aaf901821cc8d5dad', '2025-10-29 04:13:59', '2025-10-30 07:13:59', 0, '2025-10-29 04:14:31'),
(567, 11, '41961dfaa62484497ef91cca45d2155502f703b4bbd094dccd03004c67fbad8d', '2025-10-29 04:14:39', '2025-10-30 07:14:39', 0, '2025-10-29 04:14:59'),
(568, 10, '52bb1170800324adb9068badd4c6ba2b7a1f3eae481e753ab84ecc9a64d00dd7', '2025-10-29 04:15:06', '2025-10-30 07:15:06', 0, '2025-10-29 04:18:51'),
(569, 6, 'd8f971418b5dfaba24e3a3fc40626afc755874dbe988069c84ec4c4b1c93c0b6', '2025-10-29 04:19:00', '2025-10-30 07:19:00', 0, '2025-10-29 04:20:11'),
(570, 10, 'accad42f44ce08976d73e43868f5dd1cdfa8d8c693b425b60c55b487654d548a', '2025-10-29 04:20:20', '2025-10-30 07:20:20', 0, '2025-10-30 13:21:24'),
(571, 6, '1380d7d7656f57be7402b474ac69f8006956f81c379498e7d45f89d97a6e797b', '2025-10-29 04:23:11', '2025-10-30 07:23:11', 0, '2025-10-29 04:35:11'),
(572, 6, '3d6af6bd1d15bbeb65baeae00715c5a290a9537cbad1cf443f5149d583d67071', '2025-10-29 04:35:49', '2025-10-30 07:35:49', 0, '2025-10-29 04:39:09'),
(573, 10, '8061afcb903ac987cdae224b34519b53573b9057196a00cf523b17a315039246', '2025-10-29 04:39:16', '2025-10-30 07:39:16', 0, '2025-10-29 04:40:12'),
(574, 6, '327133c50af74534fd035b1134e72f4aa806023950f13cdab72556badc823890', '2025-10-29 04:40:21', '2025-10-30 07:40:21', 0, '2025-10-29 04:40:45'),
(575, 10, '4d3d1c1d81c6327fd3868143a427ab0ce9ddf5ce003947bad4f4b8f8b01b05dc', '2025-10-29 04:40:53', '2025-10-30 07:40:53', 0, '2025-10-29 04:43:33'),
(576, 6, '8df07ac30cd8a1c775f7e843feb2d2e2005323eeb66ad833026f88609e18e733', '2025-10-29 04:43:43', '2025-10-30 07:43:43', 0, '2025-10-29 04:47:41'),
(577, 10, '5fff438b3f8e8d2e1049cdf92c75834bd7cd7aff43a2faf1641d1c63d5118548', '2025-10-29 04:47:51', '2025-10-30 07:47:51', 0, '2025-10-29 04:48:50'),
(578, 10, '113f0aee90e180b59d9731b32329c6e61d263f34cb3d7657f3c8e64d62eba0ec', '2025-10-29 04:48:59', '2025-10-30 07:48:59', 0, '2025-10-29 04:53:36'),
(579, 6, '6522ba05f237c72af1546a178f5df24092b762bb0621b9b36695d2caa1613417', '2025-10-29 04:53:43', '2025-10-30 07:53:43', 0, '2025-10-29 04:54:13'),
(580, 10, 'f25252088e125c0bc54120793b60932e2270b4a8b5ea8295264eba8264f416f7', '2025-10-29 04:54:20', '2025-10-30 07:54:20', 0, '2025-10-30 13:21:24'),
(581, 10, '94e8ad28e38f94f24ceac64e58e0837fc31083d2bd004124d92e922a3355ace6', '2025-10-29 04:57:52', '2025-10-30 07:57:52', 0, '2025-10-29 04:59:56'),
(582, 6, '5bf9d26bcd89cfbb24e7082e28cbb7c855a4f4d8f8da97d6d8b009cb649bd82d', '2025-10-29 05:00:04', '2025-10-30 08:00:04', 0, '2025-10-29 05:01:41'),
(583, 10, 'c30d44cfb55de344e802b0daa5ef92832337e85d1aeab2a56e10707d569d383b', '2025-10-29 05:01:56', '2025-10-30 08:01:56', 0, '2025-10-29 05:03:22'),
(584, 11, 'ae883365c5a76e2d30648e9e32bb150cf74116a1dd2e585181ee7f7e0268c1f2', '2025-10-29 05:03:30', '2025-10-30 08:03:30', 0, '2025-10-29 05:04:23'),
(585, 6, '2e7f7181281036ce073ee554afde47a25c90eddf3fe21feec82d568a1b68a913', '2025-10-29 05:04:33', '2025-10-30 08:04:33', 0, '2025-10-29 05:05:22'),
(586, 10, 'b0de3fad991300ff7c6fb69e8f2aea7c58b3f92c9a41a094b2b908c5d28608ef', '2025-10-29 05:05:32', '2025-10-30 08:05:32', 0, '2025-10-29 05:05:49'),
(587, 11, '8a28e4b56dae10577d30e067ee76ab5212be0c35c1d50575f0f17b0e436d306e', '2025-10-29 05:05:56', '2025-10-30 08:05:56', 0, '2025-10-29 05:07:25'),
(588, 6, '4b858503db5624b5da708d515f3ddd7befbdd7fdeb3463ac02382e5f88f32124', '2025-10-29 05:07:54', '2025-10-30 08:07:54', 0, '2025-10-29 05:08:56'),
(589, 11, '0a507b9832dd37b66f1ede8f110b0f63f96f204072ec1b8d9d16a036fd868f6e', '2025-10-29 05:09:03', '2025-10-30 08:09:03', 0, '2025-10-29 05:09:31'),
(590, 6, '674df96a4285cc13661ef24e965c0de4703ed81f9fc3d2eb0fd2279e72e79739', '2025-10-29 05:09:39', '2025-10-30 08:09:39', 0, '2025-10-29 05:10:06'),
(591, 10, 'c21665dfca66c346082cfa5d47fe73b0ce17b7df222ef846c0008ffd88fc5001', '2025-10-29 05:10:14', '2025-10-30 08:10:14', 0, '2025-10-29 05:10:48'),
(592, 11, '99ebe359672a8bbdfa100d6e73aed45563f617300b626556cee72e0e6b68da73', '2025-10-29 05:10:58', '2025-10-30 08:10:58', 0, '2025-10-31 18:15:29'),
(593, 11, '513d95bc4175cf8cc05b2c38108f877d866a8169dbf03a0df2a46a9cf89728bd', '2025-10-29 05:16:09', '2025-10-30 08:16:09', 0, '2025-10-29 05:16:30'),
(594, 10, '4f46e9b6e985805d828233eaa552f807e67d9b75085a0987a29ab67b678a2efc', '2025-10-29 05:16:37', '2025-10-30 08:16:37', 0, '2025-10-29 05:16:45'),
(595, 6, '9e879a8ae8f2842ec43410b69add2cb942fa34f2413d95fbd92e68455e2f7e08', '2025-10-29 05:16:57', '2025-10-30 08:16:57', 0, '2025-10-29 05:17:36'),
(596, 11, '57eda7b3ce752f35f05d632eede89ed73342eedfb9b12138bccd0cca89934d26', '2025-10-29 05:17:43', '2025-10-30 08:17:43', 0, '2025-10-29 05:18:03'),
(597, 10, '61f87853e1a58400cb90bdc13228958c107e090f3366832d21efe7bb547d41e0', '2025-10-29 05:18:11', '2025-10-30 08:18:11', 0, '2025-10-29 05:19:03'),
(598, 12, '290c67c358321f2fd54b12f440269953f34760f9357799a5040df150e83d9f0d', '2025-10-29 05:19:12', '2025-10-30 08:19:12', 0, '2025-10-29 05:21:14'),
(599, 10, '2912f6e7479f8da41a34eae5caa0275013b8f2cd99a988304b609d682b1391de', '2025-10-29 05:22:05', '2025-10-30 08:22:05', 0, '2025-10-29 05:30:42'),
(600, 11, 'fb35deb0d1c58b32d6492d2f90fa8b546d175ede1069e7b5079765006f787853', '2025-10-29 05:30:50', '2025-10-30 08:30:50', 0, '2025-10-29 05:31:17'),
(601, 12, '5a89efbfae2a5315ec047c411673fcb6371d8b50c99161a20ff7ca7d6b724421', '2025-10-29 05:31:25', '2025-10-30 08:31:25', 0, '2025-10-29 05:36:27'),
(602, 10, 'e642aa2d275d42a9f50d9513502de6cdc69cf2626c5b66dfa14abe8df82cac7b', '2025-10-29 05:36:36', '2025-10-30 08:36:36', 0, '2025-10-29 05:37:21'),
(603, 11, '3eea11a791a3b92d8d2299a03b452aefedea9ea7e73822f16ed686e01eddd328', '2025-10-29 05:37:28', '2025-10-30 08:37:28', 0, '2025-10-29 05:37:43'),
(604, 12, 'a5bf087616e2714652d79018a7e29ffde8f198eb1bab25a80e0d08c878e9cb7b', '2025-10-29 05:37:51', '2025-10-30 08:37:51', 0, '2025-10-29 05:38:09'),
(605, 6, 'cc5cb0bdcb4c18d6af2d4a0a736bcd67bcd17323fdb55145c6b195828ec3d35b', '2025-10-29 05:38:16', '2025-10-30 08:38:16', 0, '2025-10-29 05:38:35'),
(606, 10, 'b71ad5acb2c5fdcb90d55ff0c4fb9342d4036746a2a83ae186b8aa81fded048d', '2025-10-29 05:38:42', '2025-10-30 08:38:42', 0, '2025-10-29 05:39:04'),
(607, 6, '853db9169826cac23adf8e18365aed58bd72ebb13dc5f2c7253af9e27329e847', '2025-10-29 05:39:12', '2025-10-30 08:39:12', 0, '2025-10-29 05:42:34'),
(608, 10, 'e98611bf3dfe24757d8378c07fead8b6ca8e87fa1e142364311336b3cee90ab7', '2025-10-29 05:42:41', '2025-10-30 08:42:41', 0, '2025-10-29 05:43:42'),
(609, 10, 'd022b73b392056588c1482b17d30a451b19f5994bb2647f50cb2cd17a2357bc8', '2025-10-29 05:43:49', '2025-10-30 08:43:49', 0, '2025-10-29 05:49:42'),
(610, 6, '44e00c8871886a514b775754457d62d90dfc66747d94956295bb2a16a3c48fc5', '2025-10-29 05:49:50', '2025-10-30 08:49:50', 0, '2025-10-29 05:50:29'),
(611, 10, 'c24b382ad27d3c7c02cda3625587ee77972160b5b873a334496a46d6be07f293', '2025-10-29 05:50:36', '2025-10-30 08:50:36', 0, '2025-10-29 05:56:37'),
(612, 12, '238afe176cfa49684f1ebb613eef81c07326d92ecad2264c85bb4803009b2c8d', '2025-10-29 05:56:45', '2025-10-30 08:56:45', 0, '2025-10-29 05:58:38'),
(613, 10, 'f93d4b954b89162aded25f12ba0a5ffd3f446159ab8b11c27f4f5f2b60d11b30', '2025-10-29 05:58:45', '2025-10-30 08:58:45', 0, '2025-10-29 05:59:03'),
(614, 6, 'dce14811033c97d4d5ea4ca0cd717377c7097af38c12fb47ea191ed3c38308cc', '2025-10-29 05:59:15', '2025-10-30 08:59:15', 0, '2025-10-29 06:00:25'),
(615, 10, '51a3a8adbd21b2fd6edaffedd8eee52e5ae0b5e767b4992253cba26bfc722dec', '2025-10-29 06:00:32', '2025-10-30 09:00:32', 0, '2025-10-29 06:01:52'),
(616, 6, 'a384801f856b0fca2024055a8836f9a9829f361a356365550155afefc9c8dc8a', '2025-10-29 06:02:01', '2025-10-30 09:02:01', 0, '2025-10-29 06:04:31'),
(617, 2, 'c6c30e9018107f3ea3fcb100baf2b4c385c20f0d2ab7f6eb8dc8430e43e2b192', '2025-10-29 06:04:42', '2025-10-30 09:04:42', 0, '2025-10-29 06:05:11'),
(618, 6, 'b5933b36654e4cfcdd420814b21a07d1b234cc73221480323219f932c794f355', '2025-10-29 06:05:18', '2025-10-30 09:05:18', 0, '2025-10-29 06:17:22'),
(619, 2, 'b9cffc8dd94011fd452f0b6507deac86e88ffbeb0b31a41771ab6da859fa16eb', '2025-10-29 06:17:38', '2025-10-30 09:17:38', 0, '2025-10-29 06:18:00'),
(620, 6, '662f34ea6933b53c3e2ae9d38344e8d42aebfa9a132462516ebc7c310334c610', '2025-10-29 06:18:09', '2025-10-30 09:18:09', 0, '2025-10-29 06:18:57'),
(621, 2, '78ee36af3d97475aa4074f192224f96282aa024de82edeeb276fa9add0b22a40', '2025-10-29 06:19:07', '2025-10-30 09:19:07', 1, '2025-10-29 06:20:17'),
(622, 2, '60d7b7eb4097f3a470281a7e736d7418a245e9f245be59c66b2de71e592c9e88', '2025-10-29 06:20:50', '2025-10-30 09:20:50', 0, '2025-10-29 06:21:03'),
(623, 2, '33ffb4d9968373e9a388fe5bc9b5840183095fde921a3971da141ddfe379e374', '2025-10-29 06:21:17', '2025-10-30 09:21:17', 0, '2025-10-29 06:22:51'),
(624, 6, '091f804a41a4dfca1b8422d3f324307f96f0eb308cb65161c57c7093401ddb1b', '2025-10-29 06:22:59', '2025-10-30 09:22:59', 0, '2025-10-29 06:25:39'),
(625, 10, '379a07b88bbc7a74d5f40f65fc1c4083c14a157a10eb76710271541dec7419a3', '2025-10-29 06:25:46', '2025-10-30 09:25:46', 0, '2025-10-29 06:26:57'),
(626, 11, '7d04d9cb922400866afc1b48427c16206a3b88111ce264338bbe46f69bb6f050', '2025-10-29 06:27:04', '2025-10-30 09:27:04', 0, '2025-10-29 06:28:35'),
(627, 11, '5e5b7473ac82ccf64caf33d0d8992c850cbb8d4629798e408947df6809b2da26', '2025-10-29 06:28:43', '2025-10-30 09:28:43', 0, '2025-10-29 06:28:49'),
(628, 10, '817aaf6b4af6bdae9a93722bf4f009a7d11f9adb31ea7f3f9a349e2d03b6dc3f', '2025-10-29 06:29:03', '2025-10-30 09:29:03', 0, '2025-10-29 06:29:12'),
(629, 6, 'c144279acf39f50dd75d2ec00bab4627e7401055f214d408d09900837cedaef6', '2025-10-29 06:29:27', '2025-10-30 09:29:27', 0, '2025-10-29 06:31:26'),
(630, 12, 'f5d1474618973c6ac39fa6d752c7b10d8d0d2468fbe514a8ac04f1f410a71d31', '2025-10-29 06:31:40', '2025-10-30 09:31:40', 0, '2025-10-29 06:31:47'),
(631, 6, '2055cd5bcd001a08afe856fdfe9d3bc38670c55a76a2ef85c75cd39d29127bd2', '2025-10-29 06:31:55', '2025-10-30 09:31:55', 0, '2025-10-29 06:32:22'),
(632, 12, 'd69a2dd624f99b932090d4a732d2db220ced92288362d87427031bc8baa081ca', '2025-10-29 06:32:29', '2025-10-30 09:32:29', 0, '2025-10-29 06:33:14'),
(633, 6, 'd0803ebd9a7115b86e92166098cc874f72c96a5c4234d9d8851d4fea4a4c9322', '2025-10-29 06:33:22', '2025-10-30 09:33:22', 0, '2025-10-29 06:34:27'),
(634, 12, 'da5ab73cb60002244fa526f43db7f8c75a0c3c52030a14d5d14b91a630502cdb', '2025-10-29 06:34:35', '2025-10-30 09:34:35', 0, '2025-10-29 06:35:12'),
(635, 6, '2335251c4293d234a9e5b6476f87728f9967df211c0f6675e74560dcf9ebb8f0', '2025-10-29 06:35:20', '2025-10-30 09:35:20', 0, '2025-10-31 16:54:32'),
(636, 10, '966f62abf5d5b40ef42db2f0526870394e354a54e95391a00da7d4fb341ab209', '2025-10-29 12:29:19', '2025-10-30 15:29:19', 0, '2025-10-29 12:32:21'),
(637, 11, 'efd12833194982d771d0e5278178d826ec399d8cd1d607c13b04acc258254a77', '2025-10-29 12:32:29', '2025-10-30 15:32:29', 0, '2025-10-31 18:15:29'),
(638, 11, 'dced09b7567928eb9ea35bee9c3e6080553bb3f72c68c69f720a9302b47e3c24', '2025-10-29 13:27:48', '2025-10-30 16:27:48', 0, '2025-10-29 13:39:19'),
(639, 12, '391157b0e64de633857172a271a7d389de51face94eee2ea6254c266d19d3596', '2025-10-29 13:39:34', '2025-10-30 16:39:34', 0, '2025-11-07 04:57:44'),
(640, 12, '62373fe070de059d39893dc6fd53af3e92add20494a785e161c97913707dbbf1', '2025-10-29 13:54:50', '2025-10-30 16:54:50', 0, '2025-10-29 13:55:02'),
(641, 10, '7a3a68d7dbef999d55735a35b457f704f02875b2a3da2a7f7f724669feb548d6', '2025-10-29 13:55:09', '2025-10-30 16:55:09', 0, '2025-10-29 13:59:31'),
(642, 10, 'cb61296b3ea57b387afa41bf68e5d981b14c3a58e4ce2ac8423d3e6e7a6c1805', '2025-10-29 13:59:39', '2025-10-30 16:59:39', 0, '2025-10-30 22:43:50'),
(643, 10, '23ffe71c2fb149301caf6879d4d3e15f89b5a0029094573edcd3dcafc1553376', '2025-10-29 14:10:49', '2025-10-30 17:10:49', 0, '2025-10-29 14:13:00'),
(644, 10, '356329f23a56a4d26727a65a71e225bebbc3b895b601c5e68be9a28101aeb86b', '2025-10-29 14:15:03', '2025-10-30 17:15:03', 0, '2025-10-30 22:43:50'),
(645, 10, '052a806822ffef42ab35038193af282e378877981e6d4b3ad83df81b092c714a', '2025-10-29 14:33:31', '2025-10-30 17:33:31', 0, '2025-10-30 22:43:50'),
(646, 10, 'b39cbae023f32efedc0a574861deece196af73a2d2447f5896ef22bd968b7349', '2025-10-29 15:06:10', '2025-10-30 18:06:10', 0, '2025-10-30 22:43:50'),
(647, 10, 'bd086443d9721b8196f97f74b8be9657bb0dbe2a487ff8980c2e644e5fa350ed', '2025-10-29 15:17:08', '2025-10-30 18:17:08', 0, '2025-10-30 22:43:50'),
(648, 10, '5bff99b7d1ea836ed2fa983a97e9e6e19295be25f27f8a28faf12078d0fb7f6d', '2025-10-29 15:42:44', '2025-10-30 18:42:44', 0, '2025-10-30 22:43:50'),
(649, 10, 'bb64bcd5025cfd72671cc6a8d839c7d45a1ec5a4f6dc6d629ae1e8271e74fb2c', '2025-10-29 16:02:56', '2025-10-30 19:02:56', 0, '2025-10-30 22:43:50'),
(650, 10, '97fe79a4de76fe3c2afc66f6252350153b890c1a2ecc744c86f9985ae5e34fd6', '2025-10-29 16:15:47', '2025-10-30 19:15:47', 0, '2025-10-30 22:43:50'),
(651, 10, '5727757362a083a92ac851dbcfe3a1a4551a28472c6838f8548800d07585736f', '2025-10-29 17:02:37', '2025-10-30 20:02:37', 0, '2025-10-30 22:43:50'),
(652, 10, '6472fb2ab4863e4f8ad6d39ed1ea0cfcf3059513d333e0d45971591a50e79714', '2025-10-29 18:20:22', '2025-10-30 21:20:22', 0, '2025-10-30 22:43:50'),
(653, 10, '8e9a21d2abb5b2923f3f8a5722874ae4b4c45a838b63161199d9dc9bf6d29386', '2025-10-29 18:42:58', '2025-10-30 21:42:58', 0, '2025-10-30 22:43:50'),
(654, 10, '3e30107f42e417b568de4bf8478c0e51d494e54101fb7b67b71644257ef628db', '2025-10-30 02:25:24', '2025-10-31 05:25:24', 0, '2025-10-31 18:05:33'),
(655, 10, 'f8506bae1642937a37e6bb07c51d5bbbfad2b999f31c923782762982bc7d1675', '2025-10-30 02:46:42', '2025-10-31 05:46:42', 0, '2025-10-31 18:05:33'),
(656, 10, '1ca1cf64e44ea2b1f9cd3387300af474248b8a6ff474952bd05a27f7514f4c1c', '2025-10-30 02:51:56', '2025-10-31 05:51:56', 0, '2025-10-31 18:05:33'),
(657, 10, '032cffeef456cc8245d07df9abf1c6256c3c10b6308880558553f7f14ba75f77', '2025-10-30 03:10:16', '2025-10-31 06:10:16', 0, '2025-10-31 18:05:33'),
(658, 10, '9c366baf5115ba486cbabbbd56e5f1d2c6525cab8fe2c9f1d5edf76c6474bc66', '2025-10-30 03:18:04', '2025-10-31 06:18:04', 0, '2025-10-31 18:05:33'),
(659, 10, '8c01d643bc8ecd1f1e98bea3b2d5551ce1961cdd98322b0c2f06f862fe9d48db', '2025-10-30 03:45:19', '2025-10-31 06:45:19', 0, '2025-10-31 18:05:33'),
(660, 10, '07a25b7ac632e4fbe2076305d5e2d41ec8b2f421a5b0924c1ac2b82a6cc3f8e2', '2025-10-30 04:08:47', '2025-10-31 07:08:47', 0, '2025-10-31 18:05:33'),
(661, 10, '536ae6d8a8fb9f999a5c924490c7979e1ea98bd3e86445218e9eae16305815be', '2025-10-30 04:14:21', '2025-10-31 07:14:21', 0, '2025-10-31 18:05:33'),
(662, 10, 'b46e045d2118ea681b7d7eb9743960fea0e70421ae8579a02407ad875482f02e', '2025-10-30 04:15:02', '2025-10-31 07:15:02', 0, '2025-10-31 18:05:33'),
(663, 10, '35ec02f68a342c945770a44482cc5fe7476b91b4c672e797f048f0784f7eacd7', '2025-10-30 13:21:24', '2025-10-31 16:21:24', 0, '2025-10-31 18:05:33'),
(664, 10, 'bd508692ab9a55bb05c68b6fd3ad87058f5bbb33b881293d78a05333b70df937', '2025-10-30 13:29:49', '2025-10-31 16:29:49', 0, '2025-10-31 18:05:33'),
(665, 10, '690243e6f0831e3fdccee4d2af01d11450871dced0998d3d322b275d40afd208', '2025-10-30 13:33:45', '2025-10-31 16:33:45', 0, '2025-10-31 18:05:33'),
(666, 10, 'e4a574005e592a16feb8e016418daedbc7fd43acd6ac76f3bad39c43b822ddcb', '2025-10-30 14:13:30', '2025-10-31 17:13:30', 0, '2025-10-31 18:05:33'),
(667, 10, 'df1029773c6ff1a3eb32ad89906ca57c0b7745d835d0af3ff7582a522f1520b1', '2025-10-30 22:43:50', '2025-11-01 01:43:50', 0, '2025-11-01 02:45:38'),
(668, 10, '2dc694bede3f928a56882441bc82b1f0df21c34e3c9e2f0e83144c7a24ca92b7', '2025-10-30 23:09:15', '2025-11-01 02:09:15', 0, '2025-11-01 02:45:38'),
(669, 10, 'abb6af35e5d5a88b51bb1581572c45524f5d5fad4c15e2b637e1bec98aa87979', '2025-10-31 03:57:07', '2025-11-01 06:57:07', 0, '2025-11-05 00:07:23'),
(670, 10, '6e70dd8ce67d6b41450700ea4c2ecdbefb294a029abfc5588fd0dfde1af0ba92', '2025-10-31 04:38:53', '2025-11-01 07:38:53', 0, '2025-10-31 16:54:24'),
(671, 6, 'a06abfcff4a3949aa8bebae7432d52eca01e96de4df115409b7ef7b14c78d961', '2025-10-31 16:54:32', '2025-11-01 19:54:32', 0, '2025-10-31 17:06:35'),
(672, 6, '948b3c18b7f62311bde2c13b679bbbf5c5e08eea3a5c74278b08bd39dda2e794', '2025-10-31 17:10:16', '2025-11-01 20:10:16', 0, '2025-10-31 18:16:52'),
(673, 6, '6a7774d9b8b333c6fac81b0ca48ff52aecb321cc65e5eb3e71208eaaf7d090f3', '2025-10-31 17:19:01', '2025-11-01 20:19:01', 0, '2025-10-31 18:05:04'),
(674, 10, '08edfa18ba002f6072cc02b74dcedea9e8de6b345bd2a881f7b9360c9ae5f954', '2025-10-31 18:05:33', '2025-11-01 21:05:33', 0, '2025-10-31 18:15:10'),
(675, 11, '302f330ff3c557ac74cd149e91438cac59d2aab2a719b2ae656dbf01f5a9aceb', '2025-10-31 18:15:29', '2025-11-01 21:15:29', 0, '2025-11-06 05:00:05'),
(676, 6, '7ef1d1e87d386d3f41418c36d28c44bdfc7215f73bd3f94151826f6736555632', '2025-10-31 18:17:05', '2025-11-01 21:17:05', 0, '2025-10-31 18:17:10'),
(677, 10, 'aeedf72d4d7297c325e0eb07108b170fa6bbdab633a27fe7d722323bf4e15dd8', '2025-10-31 18:17:18', '2025-11-01 21:17:18', 0, '2025-10-31 18:18:44'),
(678, 6, 'ee1dcaf5f109d3a1a12b950dc35a4fbfa0421b2f44fad93ca3f1800efd461959', '2025-10-31 18:19:01', '2025-11-01 21:19:01', 0, '2025-10-31 19:14:18'),
(679, 6, 'a3fa243c56dd8372c3b73c63f3ad6475a79773d5eadf56e22a05c1520ecc2bc4', '2025-10-31 19:17:18', '2025-11-01 22:17:18', 0, '2025-10-31 19:37:53'),
(680, 11, '96ae8875e5ddd500d8c5e542091fd693256b262c2fd7492ebc0b0389ae34c333', '2025-10-31 19:38:12', '2025-11-01 22:38:12', 0, '2025-11-06 05:00:05'),
(681, 11, '26bb056e6a1da2f85f011267a99af597cfc5041e2fad6cc2c27a85c92a4356d4', '2025-10-31 22:25:30', '2025-11-02 01:25:30', 0, '2025-11-06 05:00:05');
INSERT INTO `sesiones` (`id`, `usuario_id`, `token_sesion`, `fecha_creacion`, `fecha_expiracion`, `activa`, `ultima_actividad`) VALUES
(682, 6, '470ddfef1685cdd32e140cd93907f6288482102c02706419b0df5da955087be3', '2025-10-31 22:39:45', '2025-11-02 01:39:45', 0, '2025-10-31 23:53:42'),
(683, 11, '03adce0e6c55f8a04d002a81f4235b863266fea637ce5d8b6419f45a34cbe66f', '2025-10-31 23:53:51', '2025-11-02 02:53:51', 0, '2025-10-31 23:54:34'),
(684, 6, '0ed5d137b26c9ef1c17de923d8975650dece5e796b0177fbcd8d048a9659e3bb', '2025-10-31 23:54:45', '2025-11-02 02:54:45', 0, '2025-11-01 00:02:00'),
(685, 11, '45a9ad984de23f93da04994d19bc387e75d2af6780b0178b880c69e7ea7bde28', '2025-11-01 00:02:09', '2025-11-02 03:02:09', 0, '2025-11-01 00:02:30'),
(686, 6, 'f8c8bad538af2e444a6504848bcee0ae7a05c7490a83fb68e3c6f0239bcf90ed', '2025-11-01 00:02:39', '2025-11-02 03:02:39', 0, '2025-11-01 00:03:26'),
(687, 11, '28310ad940f0abf35e5a08ba2ec8cf7054489aca9a447d7f1bb22ee5e6d81605', '2025-11-01 00:03:34', '2025-11-02 03:03:34', 0, '2025-11-06 05:00:05'),
(688, 11, 'ba8c8d584dc9bace6a40153f564378ad7a0af4371c7cd790ef0432a87040fbb1', '2025-11-01 00:03:55', '2025-11-02 03:03:55', 0, '2025-11-01 00:04:08'),
(689, 11, '2c94dc04f36d6a01f8b3ad5de63b7423f930b4524aaf44c66a29ed783435f5a9', '2025-11-01 00:04:20', '2025-11-02 03:04:20', 0, '2025-11-01 00:04:56'),
(690, 6, 'b6814fc60017b7f0c4218dcd816f8104a0658ed1cfc815c0d130eaa8a52dacfb', '2025-11-01 00:05:05', '2025-11-02 03:05:05', 0, '2025-11-01 00:08:03'),
(691, 11, '1e51bf537af4406438199ea5f6d2b6d42b18b403a1b9ceae9bd00d0dcac93e5d', '2025-11-01 00:08:12', '2025-11-02 03:08:12', 0, '2025-11-01 00:09:27'),
(692, 11, 'c745da8fc9bb98b583e2f935185a8ac3b0485cfacfc32e99e2280cbf90985db5', '2025-11-01 00:09:39', '2025-11-02 03:09:39', 0, '2025-11-01 00:11:15'),
(693, 6, '0d213512c366e6a0e763d076099c68ae17b8e8de5273d1312ad7238f9b6abbcd', '2025-11-01 00:11:22', '2025-11-02 03:11:22', 0, '2025-11-01 00:12:33'),
(694, 11, '6877754e157468aee3295644adc8b43d15b69d43ede5d80ec18a3147dc085fd5', '2025-11-01 00:12:44', '2025-11-02 03:12:44', 0, '2025-11-01 00:13:11'),
(695, 6, '176d26b0dac3b7bebc41776d0dac73c951d519b5dc26942e3899fe1e2ed09df9', '2025-11-01 00:13:19', '2025-11-02 03:13:19', 0, '2025-11-01 00:32:43'),
(696, 11, '4e9230dbcf1e6e3e976c1532eeacda079caeaf0c764d167d44304a3ed07e1dbc', '2025-11-01 00:32:50', '2025-11-02 03:32:50', 0, '2025-11-01 00:34:26'),
(697, 6, 'b9e1be53d9ce2dbd049a30838a2c596dafcbd776f9f3371fb792b30e6a0475bf', '2025-11-01 00:34:34', '2025-11-02 03:34:34', 0, '2025-11-01 00:35:10'),
(698, 11, '191fcdb988e06c70f5894836bde0d7fb8dfdfb7a00ef6b0a8ede3a3aaf2c2f7a', '2025-11-01 00:35:17', '2025-11-02 03:35:17', 0, '2025-11-01 00:44:06'),
(699, 11, '54cac456de4006d65cc7219dbae89a45d34c767df742d068ad418b92f6a57871', '2025-11-01 00:44:27', '2025-11-02 03:44:27', 0, '2025-11-01 00:44:36'),
(700, 6, '05a7ffb057c1f9a541d7c8814420f21ffeba9406b3d939f46d26338274b05017', '2025-11-01 00:44:47', '2025-11-02 03:44:47', 0, '2025-11-01 01:07:07'),
(701, 11, '535a9870be41879e54661a629a1b079e4e55b06074f644927eea6b53ce61afa9', '2025-11-01 01:07:14', '2025-11-02 04:07:14', 0, '2025-11-01 01:09:14'),
(702, 10, '5439048f027c290bfeafa7be51c9832e0433f9f48a0d7194266ec7638dbb59c4', '2025-11-01 01:09:32', '2025-11-02 04:09:32', 0, '2025-11-01 01:27:03'),
(703, 6, '84e8dbf6fad5133458e2f26c970334eafcc368b3debebbf6985d010c397f92ff', '2025-11-01 01:27:14', '2025-11-02 04:27:14', 0, '2025-11-01 02:45:28'),
(704, 10, '6ac7766ce6d4d80a8850b7067804772359f6c779168bc3869bc62afc32eb7940', '2025-11-01 02:45:38', '2025-11-02 05:45:38', 0, '2025-11-01 02:46:35'),
(705, 6, '796af670864638af76c03dd28b4e6144dbe7d557dea2efc5159d43530ce89a90', '2025-11-01 02:46:42', '2025-11-02 05:46:42', 0, '2025-11-01 02:47:27'),
(706, 10, '8a61c3c17af190e4acf617ecbd9288fe1da4f0fe9edd50cc863a986d506cf0e6', '2025-11-01 02:47:37', '2025-11-02 05:47:37', 0, '2025-11-01 02:48:14'),
(707, 11, '1313fa78b2bd47115321e1097fb17c84207037042e1741a8448948969d02c1f3', '2025-11-01 02:48:22', '2025-11-02 05:48:22', 0, '2025-11-01 02:48:44'),
(708, 6, '0965e21ed9b433f121178192c01b32fadee0f06d98fa448ea04b5619e9a3779a', '2025-11-01 02:48:53', '2025-11-02 05:48:53', 0, '2025-11-01 02:49:13'),
(709, 10, 'f25a3349fd4a6ee9bd79b6dd48a36a07c6f4cd913630e2ffa7deaf34f5dacfb6', '2025-11-01 02:49:21', '2025-11-02 05:49:21', 0, '2025-11-01 02:50:05'),
(710, 11, 'd9103be3cd8a64bc263d720dfac919c944559f0f81fc93bc0f1067d760d4eed2', '2025-11-01 02:50:12', '2025-11-02 05:50:12', 0, '2025-11-01 02:51:28'),
(711, 6, 'e481ab539e592ff833aaf7b1998808266a4176a8b33ca70e07afa1603c2502d1', '2025-11-01 02:51:36', '2025-11-02 05:51:36', 0, '2025-11-03 21:55:33'),
(712, 6, '6a4ec3256e9ce5dd91d427a15f20cb4ba15e49206a81abf66d206427441d3c94', '2025-11-03 21:55:33', '2025-11-05 00:55:33', 0, '2025-11-05 02:01:39'),
(713, 6, '8aabc871a30f5647e4bc42f7f281792b29d7f1e86b40b0807a286298fbf936c4', '2025-11-03 23:58:09', '2025-11-05 02:58:09', 0, '2025-11-05 13:25:20'),
(714, 6, '24934db609f9a51bcd1874713f9db4d55755dfeba51a2fb312baee46c1bff44a', '2025-11-04 00:06:43', '2025-11-05 03:06:43', 0, '2025-11-05 13:25:20'),
(715, 6, '6e067fbddfec3fec728474f1e535de74862f3bed038b8a34e0adc6ab00b68791', '2025-11-04 23:26:02', '2025-11-06 02:26:02', 0, '2025-11-05 00:07:16'),
(716, 10, 'e53ad6af7b56469e739da7461095b86e570c9c0f3423b4d08e6f6e709b60633b', '2025-11-05 00:07:23', '2025-11-06 03:07:23', 0, '2025-11-06 03:19:31'),
(717, 10, 'eb4797571aed17f6e114d2e44bc59276bcb1e8c11aa5dd89e60614992c9b260e', '2025-11-05 00:10:08', '2025-11-06 03:10:08', 0, '2025-11-06 03:19:31'),
(718, 10, '969c9029c1b4f6a7ce6e9da431e9f9e963698f2c5bcc6d4c4d9ff61cabce6a28', '2025-11-05 00:11:35', '2025-11-06 03:11:35', 0, '2025-11-05 00:13:22'),
(719, 6, '309489cffcaddc8ca367b84b3a8f71a3c8dd27e56a7ec1bc2cfedcb993874ebb', '2025-11-05 00:13:29', '2025-11-06 03:13:29', 0, '2025-11-05 00:32:13'),
(720, 10, '9ffeb0bcd15013dfec8a5501f88dfd2d12ffb722d00c198b45b437537a6cc121', '2025-11-05 00:32:19', '2025-11-06 03:32:19', 0, '2025-11-05 00:32:26'),
(721, 6, 'a52a773f98f9fe4193005f7b4212bcfe1d3d4809dd68aabfdd9b07a03e21620b', '2025-11-05 00:32:33', '2025-11-06 03:32:33', 0, '2025-11-06 03:34:25'),
(722, 6, '7c4c1a6db7154b293515c7fc31dfe9124ce478958f8bd47b606d273b05281489', '2025-11-05 02:01:39', '2025-11-06 05:01:39', 0, '2025-11-06 05:15:32'),
(723, 6, 'c7bc03739be7cf4790bf001606c1a4915820749eda0f02740e39da79bdcf9ac1', '2025-11-05 13:25:20', '2025-11-06 16:25:20', 0, '2025-11-06 22:14:04'),
(724, 6, '4bde05858e7dcc4376e968119fb7f2342bf34f9f741dce0b67b99167c489ec27', '2025-11-06 03:12:40', '2025-11-07 06:12:40', 0, '2025-11-06 03:19:03'),
(725, 10, 'eafe81cf928adde1cfc9156fef69ae2143d4b90b036d80c5c34ff352efe46115', '2025-11-06 03:19:31', '2025-11-07 06:19:31', 0, '2025-11-06 03:19:57'),
(726, 6, '547eedbb372528e664b61f4ea3f0a7482fc87c50c943e69b6ae07b35bac33481', '2025-11-06 03:20:04', '2025-11-07 06:20:04', 0, '2025-11-06 03:20:48'),
(727, 10, '5b2d215e846b88e22a5b278e2e9cdaee2d315c2696b32c61931476575f5b92f5', '2025-11-06 03:20:57', '2025-11-07 06:20:57', 0, '2025-11-06 03:34:19'),
(728, 6, '78e6798694e9ed57702e77cfac8a402fedc6e89934c9e57a0d640adb883a9cb4', '2025-11-06 03:34:25', '2025-11-07 06:34:25', 0, '2025-11-06 03:36:11'),
(729, 10, '18e7b5ac6e910d5bb9eecce6f2578929b067f4bc47c6c6a7ac24b2680f74e54c', '2025-11-06 03:36:19', '2025-11-07 06:36:19', 0, '2025-11-06 03:37:07'),
(730, 6, 'e2a53b1f497a2afe09c9b6f82c9f6bc464a2fb59c47d49a8db3f5abc3e1c2467', '2025-11-06 03:37:14', '2025-11-07 06:37:14', 0, '2025-11-06 03:38:33'),
(731, 10, '38081274711d58da0e8974b17c59fa9aab6054c704b84b7138fc43d40e0dea11', '2025-11-06 03:38:40', '2025-11-07 06:38:40', 0, '2025-11-06 03:42:40'),
(732, 6, '7e1758186798d333abc3f4f7567d1ea42fb68e4cbbc893d7d646cb02504ebe53', '2025-11-06 03:42:50', '2025-11-07 06:42:50', 0, '2025-11-06 04:54:54'),
(733, 10, '10648328ab2854fd45cd89545e14fe5a0fc5ec4f9063a6fc77d9c4f8aa3320e4', '2025-11-06 04:55:01', '2025-11-07 07:55:01', 0, '2025-11-06 04:55:27'),
(734, 6, 'f3210ec058a74878d6bc271865db359a5d5906a41c139159a596e71024755463', '2025-11-06 04:55:33', '2025-11-07 07:55:33', 0, '2025-11-06 04:57:39'),
(735, 10, '37d2e26294d93f2b9c4e416a9fbd2fbaa4c7e89599d6a1e285baf273f6f82fe3', '2025-11-06 04:57:45', '2025-11-07 07:57:45', 0, '2025-11-06 04:59:57'),
(736, 11, '9ed79077acb191c2f4c1ac87cf8bfe5050c91b7d2b470436e0ffa0bdef2cdd51', '2025-11-06 05:00:05', '2025-11-07 08:00:05', 0, '2025-11-06 05:00:20'),
(737, 6, 'a6536885c941957df5a0f8b013a72b0d944cff459cf41835943c2a927e9f0264', '2025-11-06 05:00:39', '2025-11-07 08:00:39', 0, '2025-11-06 05:01:13'),
(738, 13, '506991836c4da5ad859a85919672625939b7822984f4a0d80360dbd1b4882ddd', '2025-11-06 05:01:22', '2025-11-07 08:01:22', 1, '2025-11-06 05:04:40'),
(739, 10, '4cda99420fae29863826cf50e115cb0ddbcef6c9ed9898f87b76bfcbab92707e', '2025-11-06 05:05:08', '2025-11-07 08:05:08', 0, '2025-11-06 05:06:23'),
(740, 11, 'd1589265277e2f7c18823a23269e5ba6c7c59cd7a4a814d2e9b50f7b9800dd95', '2025-11-06 05:06:31', '2025-11-07 08:06:31', 0, '2025-11-06 05:07:31'),
(741, 13, '918885202c6b3c37ac15524a0affe1f7389d37cabeabc66e560bae0213c07c1d', '2025-11-06 05:07:39', '2025-11-07 08:07:39', 0, '2025-11-06 05:07:59'),
(742, 10, '5ec895877fcc63984863422cfbaaa5b36bd5d1628205875125f3a401180bfcb2', '2025-11-06 05:08:33', '2025-11-07 08:08:33', 0, '2025-11-06 05:08:59'),
(743, 11, '33bb6e0214335959ab1f9557a1c32aab5f74c5e615549e999a213f8ab5131e36', '2025-11-06 05:09:07', '2025-11-07 08:09:07', 0, '2025-11-06 05:14:37'),
(744, 13, '244290bb94f042de95be05ccc26d7d963e5f7677f7415cb125ddd6a44b2bb8c0', '2025-11-06 05:14:45', '2025-11-07 08:14:45', 0, '2025-11-06 05:15:24'),
(745, 6, '0c43dc31ccced032d0aef9ca945bb16c0c86dc63dc33023141704a23d9e76ae8', '2025-11-06 05:15:32', '2025-11-07 08:15:32', 0, '2025-11-06 05:17:05'),
(746, 11, '3081441bf7d87e7f9dd6c99463c7fb4c0b78c3d596a4605a9fd808b1a8806945', '2025-11-06 05:17:14', '2025-11-07 08:17:14', 0, '2025-11-06 05:18:02'),
(747, 13, '6cf29f805480c846ae9d4c6805d08d956beea45c8094926d81dba3ed5c95d89e', '2025-11-06 05:18:09', '2025-11-07 08:18:09', 0, '2025-11-06 05:18:44'),
(748, 6, 'c72229b3ca9d5f424da2f32b88b0cee979ce3d9b0ef91fa584c3dc1704d86ee5', '2025-11-06 05:18:51', '2025-11-07 08:18:51', 0, '2025-11-06 05:25:39'),
(749, 11, 'f79363d64d8237677b8e3fc5191191c24fca5cdb22bf39a3a40f905b960957ce', '2025-11-06 05:25:47', '2025-11-07 08:25:47', 0, '2025-11-06 05:26:28'),
(750, 6, 'a7ac4de18750123e35cf0c4eeae4c88dcf63941a8341d6f1d06da33465c11337', '2025-11-06 05:26:43', '2025-11-07 08:26:43', 0, '2025-11-06 05:28:15'),
(751, 11, '312cd8ff0ac5aa561efbe18133b07d1ff70d83854b2de94523a93e6382cc343f', '2025-11-06 05:28:26', '2025-11-07 08:28:26', 0, '2025-11-06 05:28:39'),
(752, 13, 'e156df36b14f75c774531b7f6c6282b3377ee04dbbc863a918cfd6ff9bb6ac61', '2025-11-06 05:28:45', '2025-11-07 08:28:45', 0, '2025-11-06 05:31:51'),
(753, 6, 'a411d61adbdf2e5b8e14bcd97547d1ff2a7e898632ce47229f42d90f2db2172c', '2025-11-06 05:32:08', '2025-11-07 08:32:08', 0, '2025-11-06 05:32:31'),
(754, 13, '7422633859c5a80a12b40b40781a12b550754d9347e253b268b090d11787e2d8', '2025-11-06 05:32:38', '2025-11-07 08:32:38', 0, '2025-11-06 05:34:58'),
(755, 6, '7fbee940845f95f9ec41d7e3ff89091d9e94e626d902f612b886568d26ca42eb', '2025-11-06 05:35:12', '2025-11-07 08:35:12', 0, '2025-11-06 05:36:03'),
(756, 13, 'c3e68d0236e2686d3d60d8c3a3c5418a03d7650e8b409832bcfa5823a42670f3', '2025-11-06 05:36:13', '2025-11-07 08:36:13', 0, '2025-11-06 05:42:39'),
(757, 6, '2dad6ac3521588bc699e12a515aec3c53964d99e3833bac7be51e384ba1d8266', '2025-11-06 05:42:52', '2025-11-07 08:42:52', 0, '2025-11-06 05:50:28'),
(758, 10, '134c4ebbbefd5705800caae6115cf056a3502f4c51d2002424578fc9e839a3d9', '2025-11-06 05:50:35', '2025-11-07 08:50:35', 0, '2025-11-06 05:50:46'),
(759, 6, 'ab4fbda17cc1bf2a493a8630ed8e49d9cd44dd1603ee560c1ff41c8b58afff83', '2025-11-06 05:50:53', '2025-11-07 08:50:53', 0, '2025-11-06 05:52:20'),
(760, 10, 'a8a8cac9e670933435b897ede01074541098f1fa3a56bcff83d2ab13a8aa9952', '2025-11-06 05:52:26', '2025-11-07 08:52:26', 1, '2025-11-06 05:55:10'),
(761, 10, '2bab41139b27854eed5252ec8f597cacf8e0896c0530ed4de7ac5eed20cbeb12', '2025-11-06 05:55:35', '2025-11-07 08:55:35', 1, '2025-11-06 05:55:44'),
(762, 10, 'b57428d95613be3ce667f232219ad2af8ab21c4e03b1e9190358cf1cefe95060', '2025-11-06 05:59:30', '2025-11-07 08:59:30', 0, '2025-11-06 06:04:49'),
(763, 6, '0c3d453096d447886d988a121e9c978064010fe79d4c06e939ffdb2b204895df', '2025-11-06 06:04:58', '2025-11-07 09:04:58', 0, '2025-11-06 06:06:13'),
(764, 10, '86e30db6756ff2f86e6ebd1f9a3244509529758ad9ff489dd2224d6faa91900e', '2025-11-06 06:06:21', '2025-11-07 09:06:21', 0, '2025-11-06 06:08:49'),
(765, 6, '4daab935fdaaf1e5e854111ab62fa85f938a83df5e2cae389fae08a847559763', '2025-11-06 06:09:08', '2025-11-07 09:09:08', 0, '2025-11-07 12:26:21'),
(766, 6, 'a12807302b992557f3390caa1543878908b0aea5dc283e93fa119a492a2d1c7c', '2025-11-06 06:32:12', '2025-11-07 09:32:12', 0, '2025-11-07 12:26:21'),
(767, 10, 'efeed7277adeed05a3ea534cf06c65e91ea53bdecf89cb6437234db2db504ce0', '2025-11-06 06:38:18', '2025-11-07 09:38:18', 0, '2025-11-06 06:38:24'),
(768, 6, 'b4fd45c8dab210e6b383841ed693446f0fb3e08b6f90a3d62dae5eebf583a2cf', '2025-11-06 06:38:32', '2025-11-07 09:38:32', 0, '2025-11-07 12:26:21'),
(769, 6, '71af1773beecde2b9364b8259061deea51c58da74665c75b55c586da77f45e3c', '2025-11-06 11:59:00', '2025-11-07 14:59:00', 1, '2025-11-06 11:59:15'),
(770, 6, 'ea712bc8cfa2dc8c551a814ebfd98032b9fa35116395caf3d07a484927b30beb', '2025-11-06 12:07:24', '2025-11-07 15:07:24', 1, '2025-11-06 12:08:39'),
(771, 6, '9cf0a7b395ad7f036e1e0c41801e9a59d63fd0a47aefc7ab29ca89c02d564683', '2025-11-06 12:12:26', '2025-11-07 15:12:26', 1, '2025-11-06 12:58:52'),
(772, 6, '6e9e84e504069cfb103ad8cae8e047359a0d3bf3dabb9131e5b7e3a66fe8caba', '2025-11-06 12:13:22', '2025-11-07 15:13:22', 1, '2025-11-06 12:19:21'),
(773, 6, '6e692c61d1a95f78f1be591ffdf66d7406bd2b1f98c9f2ebb45ce6cdb5af4757', '2025-11-06 13:02:24', '2025-11-07 16:02:24', 1, '2025-11-06 13:17:00'),
(774, 6, 'cdc17dfe2485e8cb0932eeeaaef829b6bc6ef524da33226c23063eafa592a1b4', '2025-11-06 13:17:30', '2025-11-07 16:17:30', 1, '2025-11-06 19:09:21'),
(775, 6, '795757b026811bc99b50199405c00ef87a1c2b447eab88205708466062f302bd', '2025-11-06 22:14:04', '2025-11-08 01:14:04', 0, '2025-11-06 22:22:17'),
(776, 6, 'cd088218301796d31ef5d6856a22500dbe91ed6cfe0219d976ea70bdd3bd3ec4', '2025-11-06 22:22:24', '2025-11-08 01:22:24', 0, '2025-11-06 22:36:07'),
(777, 6, '7d89d189fff8cd3cf3ea56f0305af0215e740e313d2d067cd3b6977bad7dac3b', '2025-11-06 22:36:22', '2025-11-08 01:36:22', 1, '2025-11-06 22:37:07'),
(778, 6, 'ca8836cd33189355a05ec3ab59d4f3225ca11446787e8d5208bdb01b46e6ad6a', '2025-11-06 22:37:48', '2025-11-08 01:37:48', 1, '2025-11-06 22:42:19'),
(779, 6, 'c0cdf32892eecc8575a91aff33588715f3e2ad568bd128c9a62cf0d8070ca8a9', '2025-11-06 22:44:51', '2025-11-08 01:44:51', 1, '2025-11-06 22:44:53'),
(780, 6, '70da56af7b881f53bf3074cff3e8990708a80bff89e871951f1a6c0d52a7ea04', '2025-11-06 22:45:53', '2025-11-08 01:45:53', 1, '2025-11-06 23:56:08'),
(781, 6, '5ae9625f719950cfc479d3986834275781112be385e78e8845b3ee8b5e948ff8', '2025-11-07 00:03:38', '2025-11-08 03:03:38', 1, '2025-11-07 00:04:15'),
(782, 6, 'ef515c000b972a9ed6d0b93c5869fc066011d3c85d5716f555c381822f494cca', '2025-11-07 00:08:35', '2025-11-08 03:08:35', 1, '2025-11-07 00:17:16'),
(783, 6, '18e313bbc5cb4fad4bb6f070ffddc86e30113376c572c15e58615bfc483c87d6', '2025-11-07 00:18:10', '2025-11-08 03:18:10', 1, '2025-11-07 00:22:57'),
(784, 6, '573271dd2842d42ec7975fd34e65ad533c3add798f5ebc79c4dc819df783731f', '2025-11-07 00:25:38', '2025-11-08 03:25:38', 1, '2025-11-07 00:36:32'),
(785, 6, 'fde7099710704925e8a1bdd3c5ae7daaae0e1aede5bc74bcdccf0c7dab7b3fc2', '2025-11-07 00:36:55', '2025-11-08 03:36:55', 1, '2025-11-07 00:47:53'),
(786, 6, '3ac575823aeccdfa4fd98b73db59f01e0c261870a458bdde5d548bf502b46da8', '2025-11-07 00:53:28', '2025-11-08 03:53:28', 1, '2025-11-07 00:54:27'),
(787, 6, '24b22f099742d3d80933878529da7a23a930454c3f339a7961cae46563f6c10d', '2025-11-07 00:57:16', '2025-11-08 03:57:16', 1, '2025-11-07 01:00:34'),
(788, 6, 'e2cb6d66c65108b941520bbda33ec529ac8d4cdb29e4d0df8f8da5add935c075', '2025-11-07 01:01:46', '2025-11-08 04:01:46', 1, '2025-11-07 01:06:01'),
(789, 6, '41f4aeb108e26568e714671145844f9c33f18eb48f3050e11d3be85a6c1ad065', '2025-11-07 01:06:57', '2025-11-08 04:06:57', 1, '2025-11-07 02:03:14'),
(790, 6, '9506b6f6751f8fbd10e248e30d3654ac546352d26ddece68d7dfe29fc8e051d3', '2025-11-07 02:03:57', '2025-11-08 05:03:57', 1, '2025-11-07 02:43:59'),
(791, 6, '1a14ee1741999ad2b860160ecdac174c986d63379c3218473539f12c77484648', '2025-11-07 02:44:25', '2025-11-08 05:44:25', 1, '2025-11-07 02:49:33'),
(792, 6, '2177c9d5d1b1400e29b725e9c653819762996127b4c57eebd64d33466822955c', '2025-11-07 02:50:11', '2025-11-08 05:50:11', 0, '2025-11-07 03:39:18'),
(793, 10, '2b000fbe1c76bedabb65c3a4ca178111457510b154aae9bc64f311070afe6e00', '2025-11-07 03:39:25', '2025-11-08 06:39:25', 0, '2025-11-07 03:39:38'),
(794, 11, 'b22a9d77b55603dba91f62084b109dc4075cf84ba6d2ee7ee93247180e2766d2', '2025-11-07 03:39:45', '2025-11-08 06:39:45', 0, '2025-11-07 03:39:53'),
(795, 6, 'b030e3437b7717217eda1647ba31636a6781ad5c6a3bbb7fc5bd0602d6005be1', '2025-11-07 03:40:02', '2025-11-08 06:40:02', 1, '2025-11-07 04:13:24'),
(796, 11, 'a1fa445e3f1868b8656a769ec8c3d5d33c2da2b405f17ee3a893f2e4ee302aee', '2025-11-07 03:41:24', '2025-11-08 06:41:24', 1, '2025-11-07 03:46:47'),
(797, 11, '6d33e252d72138469bad3b012fbbd089ebf2ec4f0ccd1aa70380af959020401d', '2025-11-07 03:47:22', '2025-11-08 06:47:22', 0, '2025-11-07 03:51:27'),
(798, 10, 'c394c364325939d1fe1f823821de1f50d56ea10fe454a479d6273062818fc3c3', '2025-11-07 03:51:34', '2025-11-08 06:51:34', 0, '2025-11-07 03:55:24'),
(799, 11, '3467913857fcfca49d9f2e946ba3d7b2457094ac468eb29c16b6ee9ccadda4e2', '2025-11-07 03:55:31', '2025-11-08 06:55:31', 0, '2025-11-07 03:59:37'),
(800, 10, '9e09c152fc378e6466fe8878420b31769588a9aeae3db9fe4e9b3ff098a9590e', '2025-11-07 03:59:45', '2025-11-08 06:59:45', 0, '2025-11-07 04:00:16'),
(801, 11, '1f6bcf13b9d3c2682152ad1166b5f92e3996b1de870159fa2f11018f05e8a776', '2025-11-07 04:01:24', '2025-11-08 07:01:24', 0, '2025-11-07 04:04:02'),
(802, 10, '629bde167c8da498e154cbccc3933a3c9b0444eb34c10991b4fb69218cadc4c6', '2025-11-07 04:04:18', '2025-11-08 07:04:18', 0, '2025-11-07 04:08:41'),
(803, 11, '4d4f4f028659f71f9b5e7f18a2ed0d34453e4f58648160daf322735f00446191', '2025-11-07 04:08:49', '2025-11-08 07:08:49', 0, '2025-11-07 04:14:13'),
(804, 10, 'f978fd054d1146384cbe21e38edb4a7ad41b08df463dab1889a3d4846d81e42c', '2025-11-07 04:14:25', '2025-11-08 07:14:25', 0, '2025-11-07 04:21:58'),
(805, 10, 'd48169d08a8ca2d38df1fcede17be098aff5f4cf7ed39da80de6c3d7ebf41d0e', '2025-11-07 04:22:05', '2025-11-08 07:22:05', 0, '2025-11-07 04:27:09'),
(806, 11, '250e9d0e28cc98d204c77afd9cbfdb345c622b694c1c5996a0d98787918b0a91', '2025-11-07 04:27:18', '2025-11-08 07:27:18', 0, '2025-11-07 04:29:58'),
(807, 10, 'a6ce5c8dab6991c92b0ad91995751ef524f22c1e508857444d64cc815b323b6d', '2025-11-07 04:30:06', '2025-11-08 07:30:06', 1, '2025-11-07 04:32:03'),
(808, 10, '49c4d5b60b99e67b5f8aa33b460b667de3b137030d6781c991436d86cd553157', '2025-11-07 04:32:42', '2025-11-08 07:32:42', 0, '2025-11-07 04:33:01'),
(809, 11, 'e3402b449b3d6cb654bb7fcbfd91d5b8e68142cbe23de6c83f3a7e501de52b0c', '2025-11-07 04:33:09', '2025-11-08 07:33:09', 1, '2025-11-07 04:33:15'),
(810, 11, '9da61ee9828bd208f390450e17794a934ab447680870e5b03c3bdba6d29650dd', '2025-11-07 04:34:20', '2025-11-08 07:34:20', 0, '2025-11-07 04:41:46'),
(811, 10, 'bfee23a1939cb982cac35b0fdc5f95abaaf2a01e4bf01f1f60523465d70af5ce', '2025-11-07 04:35:50', '2025-11-08 07:35:50', 0, '2025-11-07 04:40:51'),
(812, 6, '0a37fda892b47e830ff59479b98d7237fb8577e039043e9e891262d0875300c7', '2025-11-07 04:41:00', '2025-11-08 07:41:00', 0, '2025-11-07 04:48:26'),
(813, 10, 'c438cb573b7028d6f81b3c4276f387fadc25cdf37bf9fe60324e6bc4b8e3ac1b', '2025-11-07 04:41:54', '2025-11-08 07:41:54', 0, '2025-11-07 04:44:08'),
(814, 11, '99239b5ee78c1a8945ee7dea55b1a783c6bc4c5422835c70e04ce1fdaf176a65', '2025-11-07 04:44:15', '2025-11-08 07:44:15', 0, '2025-11-07 04:46:59'),
(815, 10, 'b9f3d98c47b92cda3e38e99f39d93bb9cdd6690bf21f6070d797a5f440ec8078', '2025-11-07 04:47:05', '2025-11-08 07:47:05', 0, '2025-11-07 04:49:58'),
(816, 10, '574c42f81c1662f9a8200ce24438839089b738ba3af84b15728bbf445bdeb3d5', '2025-11-07 04:48:33', '2025-11-08 07:48:33', 0, '2025-11-07 04:51:19'),
(817, 11, '1824bb9bf094e8f4b29be3355b37942a147ce4b3ec61b72fef59f80674143666', '2025-11-07 04:50:17', '2025-11-08 07:50:17', 0, '2025-11-07 04:53:15'),
(818, 6, '9c119e05b12658fc99059a01c11d50aea96d2749ff1714f7b4fb5607d6e22d99', '2025-11-07 04:51:27', '2025-11-08 07:51:27', 1, '2025-11-07 05:23:49'),
(819, 10, '12772003468a2f348d11ed0cff10962df8e66efa02949f49c1cd45d04d3f7d45', '2025-11-07 04:53:22', '2025-11-08 07:53:22', 0, '2025-11-07 04:56:07'),
(820, 11, '127ff9a7dc3ef0d59488ab8c0eb28092ca0efe27e516e9162847b35577d02b37', '2025-11-07 04:56:14', '2025-11-08 07:56:14', 0, '2025-11-07 04:57:36'),
(821, 12, '497dca52e26adb3f5e4c8e48523790a4e700c45856d2e6e5d6c3ff2d54caef0f', '2025-11-07 04:57:44', '2025-11-08 07:57:44', 0, '2025-11-07 04:58:11'),
(822, 10, '14e2ebcc1c1a4b5222a31997e184922b9af4116fe1f1862ea5a4c1757c220dd4', '2025-11-07 04:59:04', '2025-11-08 07:59:04', 0, '2025-11-07 05:01:28'),
(823, 11, 'aff1793ccbdeec616f975d5667707b07caf35831e17ecf483df9f336f8640b83', '2025-11-07 05:01:36', '2025-11-08 08:01:36', 0, '2025-11-07 05:01:59'),
(824, 12, 'e64eed046a10b46d99279b35cff989d16e6099d60b0d6006c6e239324c9b60e9', '2025-11-07 05:02:07', '2025-11-08 08:02:07', 1, '2025-11-07 05:10:34'),
(825, 10, '906d5ad4a52b78f395c4be924c95897a68dfb067dbed7223502e6979de9178d6', '2025-11-07 05:11:21', '2025-11-08 08:11:21', 0, '2025-11-07 05:11:31'),
(826, 11, '76806e40d8c97a9f995e7aa93d54ad434c1b30e87396b2a0261eb2791d20ff48', '2025-11-07 05:11:42', '2025-11-08 08:11:42', 0, '2025-11-07 05:11:52'),
(827, 12, '408a9b946f2d8a409c7b1adeccc69eee5a08808e3f12d1d83dbf67ed955690fb', '2025-11-07 05:11:59', '2025-11-08 08:11:59', 0, '2025-11-07 05:13:26'),
(828, 11, '145a73292727aa8d6cabb3d4c47a2f53ba5a7e13ff23ff00c80642d2faccae5c', '2025-11-07 05:13:33', '2025-11-08 08:13:33', 0, '2025-11-07 05:13:50'),
(829, 10, '9984b65522f41ec30764c577fa357ec443dc4b07094784d0adaec81def8ebefd', '2025-11-07 05:13:58', '2025-11-08 08:13:58', 1, '2025-11-07 05:14:02'),
(830, 11, 'e8017a0f4dbf20b90202b7516f1a9d874a3ba441782cd651766f1f7d8843f101', '2025-11-07 05:14:30', '2025-11-08 08:14:30', 0, '2025-11-07 05:15:36'),
(831, 12, 'ea29fc86b039b749593a110de5d3d6b7de71f1b7a239a36b363ee46be33ddfd1', '2025-11-07 05:15:44', '2025-11-08 08:15:44', 0, '2025-11-07 05:17:27'),
(832, 11, 'a229e736ab36c40730bed4ff9550d0d038060ae4be5860a58797cf193b2a21af', '2025-11-07 05:17:33', '2025-11-08 08:17:33', 1, '2025-11-07 05:22:41'),
(833, 12, 'b5c85d4b5c94859ea91dfd8c7cab51c31bbccb535ede99447a7e5db56fcc19e4', '2025-11-07 05:23:15', '2025-11-08 08:23:15', 0, '2025-11-07 05:23:27'),
(834, 11, 'eefc9369adc11bbb12b61e715b0fc1d82951ae3e89bca7ca0bffdb2a6a21f821', '2025-11-07 05:23:34', '2025-11-08 08:23:34', 0, '2025-11-07 05:25:57'),
(835, 11, 'a5bb6b6ab0390a432c3c080e8f8b3b94c2ba7c79b275e41e6ba0fa89584111c9', '2025-11-07 05:24:09', '2025-11-08 08:24:09', 0, '2025-11-07 12:26:00'),
(836, 12, '2995dbba8100d9524772b6fa68b82b2467f626a48d991c67260fdb1d91ed7329', '2025-11-07 05:26:06', '2025-11-08 08:26:06', 0, '2025-11-07 05:30:30'),
(837, 10, 'f3e448b551e519e69fb5b17ef5ff25c5802631856e9e6c47e608ed353cece680', '2025-11-07 05:30:37', '2025-11-08 08:30:37', 1, '2025-11-07 05:30:53'),
(838, 6, '8b0a9eeb8c3a77bc6280cbfec9644c32f8da8dc243c024db26670043c418b241', '2025-11-07 12:26:21', '2025-11-08 15:26:21', 1, '2025-11-07 12:27:20');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `study_antecedents`
--

DROP TABLE IF EXISTS `study_antecedents`;
CREATE TABLE IF NOT EXISTS `study_antecedents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `study_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Notas y antecedentes médicos',
  `created_by` int NOT NULL,
  `created_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_study_id` (`study_id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `study_antecedents`
--

INSERT INTO `study_antecedents` (`id`, `study_id`, `notes`, `created_by`, `created_date`, `updated_date`) VALUES
(1, 'ae09efb2-3b595e90-b4bc3156-d3721dd6-267e9ed5', 'otra nota guardada', 2, '2025-10-18 00:01:10', '2025-10-18 02:06:12'),
(2, 'b79dea2d-29c52f51-6e7a1dc9-b65d88dc-39e77223', 'nota de prueba', 2, '2025-10-18 00:26:36', '2025-10-18 02:32:18'),
(4, 'ae09efb2-3b595e90-b4bc3156-d3721dd6-267e9ed5', 'otra nota guardada', 2, '2025-10-18 01:50:29', '2025-10-18 02:06:12'),
(5, 'ae09efb2-3b595e90-b4bc3156-d3721dd6-267e9ed5', 'otra nota guardada', 2, '2025-10-18 01:53:52', '2025-10-18 02:06:12'),
(6, '2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', '', 2, '2025-10-18 05:25:31', '2025-10-18 05:25:31'),
(7, 'a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26', '', 2, '2025-10-18 06:02:52', '2025-10-18 06:02:52'),
(8, '97dac478-b59e9b72-70b0d132-31bcb574-59cb1753', '', 2, '2025-10-18 06:07:55', '2025-10-18 06:07:55'),
(10, 'e093e622-ccbfe230-fcd23d61-43a59705-29414ee6', 'NOTA DE ANTECEDENTES PARA EL ESTUDIO', 6, '2025-11-01 00:07:42', '2025-11-01 00:07:44'),
(11, '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', '', 6, '2025-11-07 03:09:26', '2025-11-07 03:11:35');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `study_antecedents_files`
--

DROP TABLE IF EXISTS `study_antecedents_files`;
CREATE TABLE IF NOT EXISTS `study_antecedents_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `antecedent_id` int NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` enum('image','document','camera_capture','image_upload','file_upload') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_antecedent_id` (`antecedent_id`),
  KEY `idx_file_type` (`file_type`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `study_antecedents_files`
--

INSERT INTO `study_antecedents_files` (`id`, `antecedent_id`, `file_name`, `file_path`, `file_type`, `file_size`, `mime_type`, `uploaded_date`) VALUES
(7, 1, '2024-10-17 18.29.52 www.dazn.com adcfc7d5efa8.png', 'uploads/antecedents/68f2f5640ed60_1760752996.png', 'image_upload', 929878, 'image/png', '2025-10-18 02:03:16'),
(8, 1, '23042900.pdf', 'uploads/antecedents/68f2f589324cf_1760753033.pdf', 'file_upload', 270648, 'application/pdf', '2025-10-18 02:03:53'),
(16, 6, 'mobile_capture_1760765296684.jpg', 'uploads/antecedents/68f3258188503_1760765313.jpg', '', 83583, 'image/jpeg', '2025-10-18 05:28:33'),
(17, 6, 'mobile_capture_1760765303978.jpg', 'uploads/antecedents/68f3258238894_1760765314.jpg', '', 69641, 'image/jpeg', '2025-10-18 05:28:34'),
(19, 7, '68f32d84dd785_1760767364.jpg', 'uploads/antecedents/68f32d84dd785_1760767364.jpg', '', 92280, 'image/jpeg', '2025-10-18 06:02:52'),
(20, 7, '68f32dc5c4daa_1760767429.jpg', 'uploads/antecedents/68f32dc5c4daa_1760767429.jpg', '', 77498, 'image/jpeg', '2025-10-18 06:04:35'),
(21, 7, '68f32dc876e85_1760767432.jpg', 'uploads/antecedents/68f32dc876e85_1760767432.jpg', '', 132703, 'image/jpeg', '2025-10-18 06:04:35'),
(22, 7, '68f32dca3bd1b_1760767434.jpg', 'uploads/antecedents/68f32dca3bd1b_1760767434.jpg', '', 125519, 'image/jpeg', '2025-10-18 06:04:35'),
(24, 8, '68f32eb3d492d_1760767667.jpg', 'uploads/antecedents/68f32eb3d492d_1760767667.jpg', '', 72620, 'image/jpeg', '2025-10-18 06:07:55'),
(25, 8, '68f32efe0ef1d_1760767742.jpg', 'uploads/antecedents/68f32efe0ef1d_1760767742.jpg', '', 121866, 'image/jpeg', '2025-10-18 06:09:41'),
(30, 7, '68f3307ca6542_1760768124.jpg', 'uploads/antecedents/68f3307ca6542_1760768124.jpg', '', 86155, 'image/jpeg', '2025-10-18 06:15:30'),
(32, 8, '68f6a003cb85e_1760993283.jpg', 'uploads/antecedents/68f6a003cb85e_1760993283.jpg', '', 101755, 'image/jpeg', '2025-10-20 20:48:18'),
(33, 8, '68f6a00511125_1760993285.jpg', 'uploads/antecedents/68f6a00511125_1760993285.jpg', '', 114893, 'image/jpeg', '2025-10-20 20:48:19'),
(34, 1, '68fa6d4711f48_1761242439.jpg', 'uploads/antecedents/68fa6d4711f48_1761242439.jpg', '', 115173, 'image/jpeg', '2025-10-23 18:00:51'),
(38, 10, '69054f4907ca6_1761955657.jpg', 'uploads/antecedents/69054f4907ca6_1761955657.jpg', '', 129391, 'image/jpeg', '2025-11-01 00:07:42'),
(40, 11, 'TS_logo.png', '../uploads/antecedents/690d63220cf35_1762485026.png', 'image_upload', 68409, 'image/png', '2025-11-07 03:10:26');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `study_assignments`
--

DROP TABLE IF EXISTS `study_assignments`;
CREATE TABLE IF NOT EXISTS `study_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `study_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `assigned_by` int NOT NULL,
  `assigned_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `patient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `study_date` date DEFAULT NULL,
  `study_time` time DEFAULT NULL,
  `modality` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `study_description` text COLLATE utf8mb4_unicode_ci,
  `accession_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referring_physician` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `study_instance_uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `series_count` int DEFAULT '0',
  `instances_count` int DEFAULT '0',
  `viewer_url` text COLLATE utf8mb4_unicode_ci,
  `orthanc_study_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_birth_date` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_sex` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_study_user` (`study_id`,`user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_assigned_by` (`assigned_by`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `study_assignments`
--

INSERT INTO `study_assignments` (`id`, `study_id`, `user_id`, `assigned_by`, `assigned_date`, `status`, `patient_name`, `patient_id`, `study_date`, `study_time`, `modality`, `study_description`, `accession_number`, `referring_physician`, `study_instance_uid`, `series_count`, `instances_count`, `viewer_url`, `orthanc_study_id`, `patient_birth_date`, `patient_sex`) VALUES
(46, 'e093e622-ccbfe230-fcd23d61-43a59705-29414ee6', 10, 6, '2025-11-01 02:47:21', 'active', 'ACOSTA AYDES DEL VALLE 55A', '21769635', '2025-10-22', '18:33:37', 'MR', 'IDDSE MMSS', '', 'New^Physician', '1.2.826.0.1.3680043.8.852.35000910190095.11863', 6, 66, 'https://demoportal.tanjousoft.com.ar/u-dicom-viewer/?studyId=e093e622-ccbfe230-fcd23d61-43a59705-29414ee6', 'e093e622-ccbfe230-fcd23d61-43a59705-29414ee6', '', 'M'),
(47, '2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', 10, 6, '2025-11-01 02:47:21', 'active', 'POCON^WENDY', '1235564789', '2025-09-25', '09:24:11', 'MR', '', '', '', '1.3.76.2.1.1.4.1.2.4169.809429051', 10, 715, 'https://demoportal.tanjousoft.com.ar/u-dicom-viewer/?studyId=2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', '2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', '19960629', 'F'),
(48, '97dac478-b59e9b72-70b0d132-31bcb574-59cb1753', 10, 6, '2025-11-01 02:47:21', 'active', 'VICENTE^SARA', '3056421620301', '2025-09-25', '12:11:26', 'MR', '', '', '', '1.3.76.2.1.1.4.1.2.4169.809439060', 8, 185, 'https://demoportal.tanjousoft.com.ar/u-dicom-viewer/?studyId=97dac478-b59e9b72-70b0d132-31bcb574-59cb1753', '97dac478-b59e9b72-70b0d132-31bcb574-59cb1753', '19750723', 'F'),
(49, 'a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26', 10, 6, '2025-11-01 02:47:21', 'active', 'HERNANDEZ^KEVIN', '4169.809449218', '2025-09-25', '15:00:18', 'MR', '', '', '', '1.3.76.2.1.1.4.1.2.4169.809449209', 7, 166, 'https://demoportal.tanjousoft.com.ar/u-dicom-viewer/?studyId=a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26', 'a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26', '20070101', 'M'),
(51, '52fc1c54-ae594283-52ea30f8-907e9071-225c21ef', 10, 6, '2025-11-07 03:54:17', 'active', 'HERRERA^ADELAIDA MARIA C.', '16771523', '2025-10-03', '08:32:37', 'DX', '', '562237', '', '562237', 2, 2, 'https://demoportal.tanjousoft.com.ar/u-dicom-viewer/?studyId=52fc1c54-ae594283-52ea30f8-907e9071-225c21ef', '52fc1c54-ae594283-52ea30f8-907e9071-225c21ef', '19640508', 'F'),
(52, '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', 10, 6, '2025-11-07 05:00:50', 'active', 'NISTA PABLO OSVALDO', '8510279', '2025-04-29', '07:29:28', 'MR', 'CEREBRO', '', '', '1.2.840.113619.2.190.3596.13616334.5932.1744100243.190', 5, 84, 'https://demoportal.tanjousoft.com.ar/u-dicom-viewer/?studyId=33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', '', 'M');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `study_subassignments`
--

DROP TABLE IF EXISTS `study_subassignments`;
CREATE TABLE IF NOT EXISTS `study_subassignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `study_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `main_user_id` int NOT NULL COMMENT 'Usuario principal al que se asignó originalmente el estudio',
  `subassigned_to_user_id` int NOT NULL COMMENT 'Usuario hijo al que se derivó el estudio',
  `assigned_by_user_id` int NOT NULL COMMENT 'Usuario que realizó la derivación',
  `subassigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha y hora de la derivación',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_study_main` (`study_id`,`main_user_id`),
  KEY `idx_subassigned_to` (`subassigned_to_user_id`),
  KEY `idx_main_user` (`main_user_id`),
  KEY `idx_assigned_by` (`assigned_by_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `study_subassignments`
--

INSERT INTO `study_subassignments` (`id`, `study_id`, `main_user_id`, `subassigned_to_user_id`, `assigned_by_user_id`, `subassigned_at`, `status`) VALUES
(19, 'b79dea2d-29c52f51-6e7a1dc9-b65d88dc-39e77223', 10, 2, 10, '2025-10-29 04:03:21', 'inactive'),
(20, '2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', 11, 3, 11, '2025-10-29 04:03:21', 'active'),
(21, '2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', 11, 4, 11, '2025-10-29 04:03:21', 'active'),
(22, '97dac478-b59e9b72-70b0d132-31bcb574-59cb1753', 11, 5, 11, '2025-10-29 04:03:21', 'active'),
(24, '97dac478-b59e9b72-70b0d132-31bcb574-59cb1753', 10, 11, 10, '2025-10-29 05:02:43', 'active'),
(25, 'a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26', 10, 11, 10, '2025-10-29 05:03:11', 'inactive'),
(26, 'a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26', 10, 12, 10, '2025-10-29 05:18:46', 'active'),
(27, '2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', 10, 12, 10, '2025-10-29 05:56:29', 'inactive'),
(28, 'b79dea2d-29c52f51-6e7a1dc9-b65d88dc-39e77223', 10, 11, 10, '2025-10-31 18:10:32', 'active'),
(29, '2dbd2d1c-39530617-2cf97923-4169c649-4a9068ce', 10, 11, 10, '2025-10-31 18:17:49', 'active'),
(30, 'e093e622-ccbfe230-fcd23d61-43a59705-29414ee6', 10, 11, 10, '2025-11-01 02:49:55', 'active'),
(31, '52fc1c54-ae594283-52ea30f8-907e9071-225c21ef', 10, 11, 10, '2025-11-07 03:55:17', 'active'),
(32, '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', 10, 12, 10, '2025-11-07 05:01:24', 'active'),
(33, '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', 10, 11, 10, '2025-11-07 05:31:05', 'inactive'),
(34, '33c55ccf-3bcbdba6-cc0b68f9-d222754a-2f1091e8', 10, 11, 10, '2025-11-07 05:31:31', 'active');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `system_permissions`
--

DROP TABLE IF EXISTS `system_permissions`;
CREATE TABLE IF NOT EXISTS `system_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permission_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_key` (`permission_key`),
  KEY `idx_category` (`category`),
  KEY `idx_permission_key` (`permission_key`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `system_permissions`
--

INSERT INTO `system_permissions` (`id`, `permission_key`, `permission_name`, `description`, `category`, `created_at`) VALUES
(1, 'dashboard', 'Acceso al Dashboard', 'Permite acceder al panel principal del sistema', 'general', '2025-10-22 01:33:50'),
(2, 'estudios', 'Gestión de Estudios', 'Permite gestionar y asignar estudios médicos', 'estudios', '2025-10-22 01:33:50'),
(3, 'informes', 'Creación de Informes', 'Permite crear informes médicos', 'informes', '2025-10-22 01:33:50'),
(4, 'gestionInformes', 'Gestión de Informes', 'Permite gestionar todos los informes del sistema', 'informes', '2025-10-22 01:33:50'),
(5, 'grabacion', 'Grabación de Audio', 'Permite grabar audios para informes', 'audio', '2025-10-22 01:33:50'),
(6, 'plantillas', 'Gestión de Plantillas', 'Permite gestionar plantillas de informes', 'plantillas', '2025-10-22 01:33:50'),
(7, 'visor', 'Visor DICOM', 'Permite acceder al visor de imágenes DICOM', 'visor', '2025-10-22 01:33:50'),
(8, 'configuracion', 'Configuración del Sistema', 'Permite acceder a la configuración del sistema', 'admin', '2025-10-22 01:33:50'),
(9, 'usuarios', 'Gestión de Usuarios', 'Permite gestionar usuarios del sistema', 'admin', '2025-10-22 01:33:50'),
(10, 'all', 'Acceso Completo', 'Acceso completo a todas las funcionalidades', 'admin', '2025-10-22 01:33:50'),
(31, 'pacs_query', 'PACS Query', 'Permite consultar el PACS directamente', 'estudios', '2025-10-22 23:33:01'),
(32, 'verTodosInformes', 'Ver Todos', 'Permite ver todos los informes del sistema, no solo los creados por el usuario', 'informes', '2025-10-25 04:50:14'),
(33, 'asignaciones', 'Asignaciones', 'Permite asignar, reasignar y desasignar estudios a usuarios', 'estudios', '2025-10-29 04:32:24'),
(34, 'derivaciones', 'Derivaciones', 'Permite derivar estudios asignados a cuentas hijas', 'estudios', '2025-10-29 04:32:24'),
(35, 'enviar_pacs', 'Enviar a PACS', 'Permite enviar informes médicos a PACS', 'estudios', '2025-11-01 00:25:51'),
(36, 'ver_todas_plantillas', 'Ver Todas', 'Permite ver las plantillas de cualquier usuario en el sistema', 'plantillas', '2025-11-01 01:00:11'),
(37, 'pacientes', 'Gestión Pacientes', 'Permite gestionar pacientes del sistema', 'admin', '2025-11-05 00:03:47'),
(38, 'antecedentes', 'Antecedentes', 'Permite gestionar antecedentes de pacientes', 'estudios', '2025-11-06 03:13:24'),
(40, 'grabacion_sincronizada', 'Grabación Sincronizada', 'Permite usar grabación sincronizada con transcripción', 'audio', '2025-11-06 03:45:08'),
(41, 'transcripcion_audio', 'Transcripción de Archivos de Audio', 'Permite transcribir archivos de audio', 'audio', '2025-11-06 03:45:08'),
(43, 'dictado', 'Dictado por Voz', 'Permite usar dictado por voz para informes', 'audio', '2025-11-06 04:54:14'),
(46, 'gui_dashboard', 'Dashboard Visible', 'Controla la visibilidad y estado activo del acceso Dashboard en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(47, 'gui_estudios', 'Estudios Visible', 'Controla la visibilidad y estado activo del acceso Estudios en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(48, 'gui_informes', 'Informes Visible', 'Controla la visibilidad y estado activo del acceso Informes en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(49, 'gui_gestion_informes', 'Gestión Informes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Informes en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(50, 'gui_gestion_estudios', 'Gestión Estudios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Estudios en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(51, 'gui_gestion_pacientes', 'Gestión Pacientes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Pacientes en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(52, 'gui_grabacion', 'Grabación Visible', 'Controla la visibilidad y estado activo del acceso Grabación en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(53, 'gui_visor_dicom', 'Visor DICOM Visible', 'Controla la visibilidad y estado activo del acceso Visor DICOM en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(54, 'gui_gestion_usuarios', 'Gestión Usuarios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Usuarios en el sidebar', 'interfaz', '2025-11-06 05:47:06'),
(55, 'dicom_query_retrieve', 'QUERY/RETRIEVE', 'Permite realizar consultas y recuperación de estudios DICOM desde el servidor PACS', 'dicom', '2025-11-06 15:52:08'),
(56, 'dicom_web', 'DICOMWeb', 'Permite acceder a funcionalidades DICOMWeb para consulta y recuperación de estudios', 'dicom', '2025-11-06 15:52:08'),
(57, 'adjuntar_audios', 'Adjuntar Audios', 'Permite adjuntar archivos de audio a informes', 'audio', '2025-11-07 03:38:25');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_audit_logs`
--

DROP TABLE IF EXISTS `user_audit_logs`;
CREATE TABLE IF NOT EXISTS `user_audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_user_id` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_target_user_id` (`target_user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_sessions`
--

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `session_token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_session_token` (`session_token`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_user_expires` (`user_id`,`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `session_token`, `ip_address`, `user_agent`, `expires_at`, `created_at`) VALUES
(1, 6, 'beb7b100c7ed398860ed5c611509d86598ec04f633a94228a18ab80802568193', NULL, NULL, '2025-10-23 06:32:41', '2025-10-22 03:32:41'),
(2, 6, 'e96ad1f8a9c3257f0ff430c4d65db6c24caffbab0303d347eb5a3bfbf7ef590d', NULL, NULL, '2025-10-23 06:33:45', '2025-10-22 03:33:45');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `matricula_profesional` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verificado` tinyint(1) DEFAULT '0',
  `token_verificacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `activo` tinyint(1) DEFAULT '1',
  `nivel` enum('root','admin','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `padre_id` int DEFAULT NULL,
  `especialidad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permisos` json DEFAULT NULL,
  `ultimo_acceso` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `dicom_aetitle` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Application Entity Title (AE Title) para DICOM',
  `dicom_puerto` int DEFAULT NULL COMMENT 'Puerto para conexión DICOM',
  `dicom_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Dirección IP para conexión DICOM',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_usuarios_email` (`email`),
  KEY `idx_usuarios_matricula` (`matricula_profesional`),
  KEY `idx_nivel` (`nivel`),
  KEY `idx_padre_id` (`padre_id`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre`, `apellido`, `email`, `telefono`, `matricula_profesional`, `password_hash`, `email_verificado`, `token_verificacion`, `fecha_creacion`, `fecha_actualizacion`, `activo`, `nivel`, `padre_id`, `especialidad`, `permisos`, `ultimo_acceso`, `created_at`, `updated_at`, `dicom_aetitle`, `dicom_puerto`, `dicom_ip`) VALUES
(2, 'Rodrigo', 'Alvar', 'rodrigoalvar@gmail.com', '1234567890', 'MP12345', '$2y$10$knvKpIJeqmPtKq6.wHcRI.gpbRHBmAu2eLe3kW58q87bSU0eDu6ky', 0, 'a8f903f8184b5c5071ab6d5cf7b2fd21b6be0435fd8bc9c81563ddee510f7b3e', '2025-10-03 00:42:50', '2025-11-01 00:32:00', 1, 'admin', NULL, '', '[\"all\", \"configuracion\", \"usuarios\", \"grabacion\", \"asignaciones\", \"derivaciones\", \"enviar_pacs\", \"estudios\", \"pacs_query\", \"dashboard\", \"informes\", \"gestionInformes\", \"verTodosInformes\", \"plantillas\", \"visor\"]', NULL, '2025-10-22 01:38:38', '2025-11-01 00:32:00', NULL, NULL, NULL),
(3, 'Usuario', 'Prueba', 'test@tjsmedical.com', '123456789', 'MP12345', '$2y$10$NWAno2r/jiSd/O4vUyHvXOPGppyhWX7BiMummkeFzb6iDeomP3sKC', 1, NULL, '2025-10-11 15:10:18', '2025-10-29 06:03:07', 0, 'user', 2, NULL, '[\"dashboard\", \"informes\", \"grabacion\"]', NULL, '2025-10-22 01:38:38', '2025-10-29 06:03:07', NULL, NULL, NULL),
(4, 'Eugenio', 'Castiglione', 'ecastiglione@iddse.com.ar', '+543854885441', '11111', '$2y$10$/Mn8lrNtrhOOEj.a//E8luJ9LmeberHfuhGZDna2lplUHyPLd5BzW', 0, NULL, '2025-10-13 19:54:08', '2025-10-23 00:16:12', 1, 'user', NULL, NULL, '[\"dashboard\", \"informes\", \"grabacion\"]', NULL, '2025-10-22 01:38:38', '2025-10-23 00:16:12', NULL, NULL, NULL),
(5, 'Usuario', 'Prueba', 'test@example.com', '', '', '$2y$10$uO2NMHVbxIogxFrWBezH1OmPh.VLRxaznDpcxVzlMGSSw1MXN2yDC', 0, NULL, '2025-10-15 22:50:17', '2025-10-29 06:02:46', 0, 'user', 2, NULL, '[\"dashboard\", \"informes\", \"grabacion\"]', NULL, '2025-10-22 01:38:38', '2025-10-29 06:02:46', NULL, NULL, NULL),
(6, 'Admin', 'Root', 'root@portal.com', '+54 11 0000-0000', 'ROOT001', '$2y$10$lZXyNa4dJDXgbnBJLOh4I.H4hpkWi1r/MnKlEAmpRdcIaiIJVnYrC', 0, NULL, '2025-10-22 01:38:38', '2025-10-31 22:46:35', 1, 'root', NULL, 'Sistema', '[\"all\", \"configuracion\", \"usuarios\", \"grabacion\", \"asignaciones\", \"derivaciones\", \"estudios\", \"pacs_query\", \"dashboard\", \"informes\", \"gestionInformes\", \"verTodosInformes\", \"plantillas\", \"visor\"]', NULL, '2025-10-22 01:38:38', '2025-10-31 22:46:35', NULL, NULL, NULL),
(10, 'TUCUMAN', 'INFORMANTES', 'tucuman@iddse.com.ar', '', '', '$2y$10$EE0amnxq7WUcsNB0CR.iYu4V1xWlviJLwOBflnHgFJukN297NCrGy', 0, NULL, '2025-10-22 17:59:55', '2025-11-07 04:55:47', 1, 'user', NULL, '', '[\"antecedentes\", \"derivaciones\", \"estudios\", \"gestionInformes\", \"gui_gestion_estudios\", \"gui_gestion_informes\", \"plantillas\"]', NULL, '2025-10-22 17:59:55', '2025-11-07 04:55:47', NULL, NULL, NULL),
(11, 'LUIS', 'FAJRE', 'luisfajre@mail.com', '', '', '$2y$10$qLIxHk1FdoiZEj8HdcVsUOhTuzAoBw7pvR5G2cR5Va83wRjL7ab/W', 0, NULL, '2025-10-22 18:00:34', '2025-11-07 04:45:35', 1, 'user', 10, '', '[\"grabacion\", \"antecedentes\", \"dashboard\", \"informes\", \"gestionInformes\", \"gui_dashboard\", \"gui_gestion_informes\", \"gui_informes\", \"plantillas\"]', NULL, '2025-10-22 18:00:34', '2025-11-07 04:45:35', NULL, NULL, NULL),
(12, 'SOLANA', 'MEDICA', 'solanamedica@mail.com', '', '', '$2y$10$y7dNOrGbbbDaTprCrWJaZODHKSs8SfH.mxu8P/IaIzHpzSAMMw4ES', 0, NULL, '2025-10-22 18:01:08', '2025-11-07 04:58:55', 1, 'user', 10, '', '[\"grabacion\", \"antecedentes\", \"dashboard\", \"informes\", \"gestionInformes\", \"gui_dashboard\", \"gui_gestion_informes\", \"gui_informes\", \"plantillas\"]', NULL, '2025-10-22 18:01:08', '2025-11-07 04:58:55', NULL, NULL, NULL),
(13, 'NATALE', 'MEDICO', 'natalemedico@mail.com', '', '', '$2y$10$o//IcUXGV8FEjpeqer8mNucI6xHRzJ/4N2aSUpnkkUV3hVtU1sPb.', 0, NULL, '2025-10-22 18:01:44', '2025-11-06 05:35:54', 1, 'user', 14, '', '[\"grabacion\", \"dashboard\", \"informes\", \"gestionInformes\", \"plantillas\"]', NULL, '2025-10-22 18:01:44', '2025-11-06 05:35:54', NULL, NULL, NULL),
(14, 'BAIRES', 'INFORMANTES', 'bairesinformes@mail.com', '', '', '$2y$10$jqWiMwf7bGzwRgJBNJrtTeXqfHGCYcE7wyd4OmEu/RzfHQ3eUIHy.', 0, NULL, '2025-10-22 18:15:35', '2025-10-22 18:15:35', 1, 'user', NULL, '', '[\"dashboard\", \"informes\", \"grabacion\"]', NULL, '2025-10-22 18:15:35', '2025-10-22 18:15:35', NULL, NULL, NULL),
(15, 'baires1', 'medico', 'baires1@mail.com', '', '', '$2y$10$JMBHu/WChpdqhxahJ/VeR.9h88ppZ0yKCe8NS260lzlvMorGCMxAW', 0, NULL, '2025-10-22 18:17:00', '2025-10-22 18:17:23', 1, 'user', 14, '', '[\"dashboard\", \"informes\", \"grabacion\"]', NULL, '2025-10-22 18:17:00', '2025-10-22 18:17:23', NULL, NULL, NULL),
(16, 'root', 'prueba', 'pruebaroot@mail.com', '', '', '$2y$10$xz6wj1HMy5nvJRnPuK.M8uNP0jtRjgA6qmSauE9ci56ZtTQ4O4TIC', 0, NULL, '2025-10-29 06:18:47', '2025-10-29 06:19:21', 0, 'root', NULL, '', '[\"all\"]', NULL, '2025-10-29 06:18:47', '2025-10-29 06:19:21', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `usuarios_con_jerarquia`
-- (Véase abajo para la vista actual)
--
DROP VIEW IF EXISTS `usuarios_con_jerarquia`;
CREATE TABLE IF NOT EXISTS `usuarios_con_jerarquia` (
`activo` tinyint(1)
,`apellido` varchar(100)
,`created_at` timestamp
,`dependientes_count` bigint
,`email` varchar(255)
,`especialidad` varchar(100)
,`hijos_count` bigint
,`id` int
,`jerarquia_completa` text
,`matricula_profesional` varchar(50)
,`nivel` enum('root','admin','user')
,`nombre` varchar(100)
,`padre_apellido` varchar(100)
,`padre_email` varchar(255)
,`padre_id` int
,`padre_nivel` enum('root','admin','user')
,`padre_nombre` varchar(100)
,`permisos` json
,`telefono` varchar(20)
);

-- --------------------------------------------------------

--
-- Estructura para la vista `usuarios_con_jerarquia`
--
DROP TABLE IF EXISTS `usuarios_con_jerarquia`;

DROP VIEW IF EXISTS `usuarios_con_jerarquia`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `usuarios_con_jerarquia`  AS SELECT `u`.`id` AS `id`, `u`.`nombre` AS `nombre`, `u`.`apellido` AS `apellido`, `u`.`email` AS `email`, `u`.`telefono` AS `telefono`, `u`.`matricula_profesional` AS `matricula_profesional`, `u`.`nivel` AS `nivel`, `u`.`padre_id` AS `padre_id`, `u`.`especialidad` AS `especialidad`, `u`.`activo` AS `activo`, `u`.`permisos` AS `permisos`, `u`.`created_at` AS `created_at`, `p`.`nombre` AS `padre_nombre`, `p`.`apellido` AS `padre_apellido`, `p`.`email` AS `padre_email`, `p`.`nivel` AS `padre_nivel`, `GetUserHierarchy`(`u`.`id`) AS `jerarquia_completa`, (select count(0) from `usuarios` `h` where ((`h`.`padre_id` = `u`.`id`) and (`h`.`activo` = 1))) AS `hijos_count`, (select count(0) from `usuarios` `d` where ((`d`.`padre_id` = `u`.`id`) and (`d`.`activo` = 1))) AS `dependientes_count` FROM (`usuarios` `u` left join `usuarios` `p` on((`u`.`padre_id` = `p`.`id`))) WHERE (`u`.`activo` = 1) ORDER BY `u`.`nivel` DESC, `u`.`nombre` ASC ;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `audios_informe`
--
ALTER TABLE `audios_informe`
  ADD CONSTRAINT `audios_informe_ibfk_1` FOREIGN KEY (`informe_id`) REFERENCES `informes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `audios_informe_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `informes`
--
ALTER TABLE `informes`
  ADD CONSTRAINT `informes_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `informes_historial`
--
ALTER TABLE `informes_historial`
  ADD CONSTRAINT `informes_historial_ibfk_1` FOREIGN KEY (`informe_id`) REFERENCES `informes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `informes_historial_ibfk_2` FOREIGN KEY (`usuario_modificacion`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `informe_audios`
--
ALTER TABLE `informe_audios`
  ADD CONSTRAINT `informe_audios_ibfk_1` FOREIGN KEY (`informe_id`) REFERENCES `informes` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `mobile_sessions`
--
ALTER TABLE `mobile_sessions`
  ADD CONSTRAINT `mobile_sessions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `plantillas`
--
ALTER TABLE `plantillas`
  ADD CONSTRAINT `plantillas_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `sesiones`
--
ALTER TABLE `sesiones`
  ADD CONSTRAINT `sesiones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `study_antecedents`
--
ALTER TABLE `study_antecedents`
  ADD CONSTRAINT `study_antecedents_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `study_antecedents_files`
--
ALTER TABLE `study_antecedents_files`
  ADD CONSTRAINT `study_antecedents_files_ibfk_1` FOREIGN KEY (`antecedent_id`) REFERENCES `study_antecedents` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `study_assignments`
--
ALTER TABLE `study_assignments`
  ADD CONSTRAINT `study_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `study_assignments_ibfk_2` FOREIGN KEY (`assigned_by`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `study_subassignments`
--
ALTER TABLE `study_subassignments`
  ADD CONSTRAINT `fk_assigned_by_user` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_main_user` FOREIGN KEY (`main_user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subassigned_user` FOREIGN KEY (`subassigned_to_user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `study_subassignments_ibfk_1` FOREIGN KEY (`main_user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `study_subassignments_ibfk_2` FOREIGN KEY (`subassigned_to_user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `study_subassignments_ibfk_3` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `user_audit_logs`
--
ALTER TABLE `user_audit_logs`
  ADD CONSTRAINT `user_audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_audit_logs_ibfk_2` FOREIGN KEY (`target_user_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
