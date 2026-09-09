-- =====================================================
-- Script de Instalación - Cloud Storage Module
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Versión: 1.0.0
-- Fecha: 2026-03-06
-- =====================================================

-- Tabla: r2_queue
-- Cola de estudios pendientes de subir a R2
CREATE TABLE IF NOT EXISTS `r2_queue` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `orthanc_study_id` VARCHAR(255) NOT NULL COMMENT 'ID del estudio en Orthanc',
  `study_instance_uid` VARCHAR(255) NULL COMMENT 'StudyInstanceUID (opcional, se llena durante procesamiento)',
  `status` ENUM('pending', 'uploading', 'done', 'error') DEFAULT 'pending' COMMENT 'Estado del procesamiento',
  `last_error` TEXT NULL COMMENT 'Último error si status=error',
  `retry_count` INT(11) DEFAULT 0 COMMENT 'Número de reintentos',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_orthanc_study_id` (`orthanc_study_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: r2_studies
-- Metadatos de estudios almacenados en R2
CREATE TABLE IF NOT EXISTS `r2_studies` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `orthanc_study_id` VARCHAR(255) NOT NULL UNIQUE COMMENT 'ID del estudio en Orthanc',
  `study_instance_uid` VARCHAR(255) NOT NULL COMMENT 'StudyInstanceUID',
  `r2_status` ENUM('online', 'pending', 'none') DEFAULT 'none' COMMENT 'Estado en R2',
  `r2_manifest_path` VARCHAR(500) NULL COMMENT 'Ruta del manifest.json en R2',
  `r2_manifest_url` TEXT NULL COMMENT 'URL del manifest (opcional, se genera dinámicamente)',
  `is_locked` TINYINT(1) DEFAULT 0 COMMENT 'Si está bloqueado para no eliminar/reciclar',
  `lock_reason` VARCHAR(500) NULL COMMENT 'Motivo del bloqueo',
  `locked_at` TIMESTAMP NULL COMMENT 'Fecha/hora de bloqueo',
  `total_instances` INT(11) DEFAULT 0 COMMENT 'Número total de instancias',
  `total_size_bytes` BIGINT(20) DEFAULT 0 COMMENT 'Tamaño total en bytes',
  `uploaded_at` TIMESTAMP NULL COMMENT 'Fecha de subida a R2',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_orthanc_study_id` (`orthanc_study_id`),
  INDEX `idx_study_instance_uid` (`study_instance_uid`),
  INDEX `idx_r2_status` (`r2_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: cloud_storage_config
-- Configuración de drivers de cloud storage (para futuros drivers)
CREATE TABLE IF NOT EXISTS `cloud_storage_config` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_name` VARCHAR(50) NOT NULL DEFAULT 'r2' COMMENT 'Nombre del driver (r2, s3, azure, etc.)',
  `is_enabled` TINYINT(1) DEFAULT 1 COMMENT 'Driver habilitado',
  `config_json` TEXT NULL COMMENT 'Configuración en JSON',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_driver_name` (`driver_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
