-- =====================================================
-- Script de Instalación - PACS NODES MANAGER
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Versión: 1.0.0
-- Fecha: 2026-03-06
-- =====================================================

-- Tabla: pacs_nodes
-- Almacena la configuración de nodos PACS remotos
CREATE TABLE IF NOT EXISTS `pacs_nodes` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL COMMENT 'Nombre descriptivo del nodo',
  
  -- Configuración DIMSE (para nodos legacy)
  `aet` VARCHAR(16) DEFAULT NULL COMMENT 'Application Entity Title (DIMSE)',
  `host` VARCHAR(255) DEFAULT NULL COMMENT 'IP o hostname (DIMSE)',
  `port` INT(5) UNSIGNED DEFAULT NULL COMMENT 'Puerto DICOM (típicamente 104 para DIMSE)',
  
  -- Configuración DICOMweb (para nodos modernos)
  `dicomweb_url` VARCHAR(500) DEFAULT NULL COMMENT 'URL base DICOMweb (ej: https://pacs.example.com/dicomweb)',
  `dicomweb_username` VARCHAR(255) DEFAULT NULL COMMENT 'Usuario DICOMweb (si requiere autenticación)',
  `dicomweb_password` VARCHAR(255) DEFAULT NULL COMMENT 'Contraseña DICOMweb (encriptada)',
  `dicomweb_auth_type` ENUM('none', 'basic', 'bearer', 'oauth2') DEFAULT 'none' COMMENT 'Tipo de autenticación DICOMweb',
  
  -- Configuración general
  `node_type` ENUM('dimse', 'dicomweb', 'local', 'hybrid') DEFAULT 'dimse' COMMENT 'Tipo de nodo',
  `username` VARCHAR(255) DEFAULT NULL COMMENT 'Usuario (DIMSE, si requiere autenticación)',
  `password` VARCHAR(255) DEFAULT NULL COMMENT 'Contraseña (DIMSE, encriptada)',
  `description` TEXT DEFAULT NULL COMMENT 'Descripción del nodo',
  
  -- Estado y monitoreo
  `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Nodo activo/inactivo',
  `last_ping` DATETIME DEFAULT NULL COMMENT 'Última prueba de conectividad',
  `last_ping_status` ENUM('success', 'failed', 'timeout') DEFAULT NULL,
  `last_ping_latency_ms` INT(11) DEFAULT NULL COMMENT 'Latencia en milisegundos',
  `cache_size_mb` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Tamaño de cache local (MB)',
  `cache_studies_count` INT(11) DEFAULT 0 COMMENT 'Número de estudios en cache',
  `last_sync` DATETIME DEFAULT NULL COMMENT 'Última sincronización',
  
  -- Metadata
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Usuario que creó el nodo',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_aet` (`aet`),
  KEY `idx_active` (`is_active`),
  KEY `idx_node_type` (`node_type`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Nodos PACS remotos';

-- Tabla: pacs_node_queries
-- Cache de consultas C-FIND realizadas
CREATE TABLE IF NOT EXISTS `pacs_node_queries` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `query_hash` VARCHAR(64) NOT NULL COMMENT 'Hash MD5 de la query para cache',
  `query_params` JSON NOT NULL COMMENT 'Parámetros de la búsqueda',
  `results_count` INT(11) DEFAULT 0 COMMENT 'Número de resultados',
  `results_data` JSON DEFAULT NULL COMMENT 'Resultados cacheados (opcional)',
  `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL COMMENT 'Expiración del cache',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_query` (`node_id`, `query_hash`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_expires_at` (`expires_at`),
  FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache de consultas C-FIND';

-- Tabla: pacs_node_jobs
-- Monitoreo de jobs asincrónicos (C-MOVE/C-GET)
CREATE TABLE IF NOT EXISTS `pacs_node_jobs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `job_type` ENUM('c_move', 'c_get') NOT NULL,
  `orthanc_job_id` VARCHAR(255) DEFAULT NULL COMMENT 'ID del job en Orthanc',
  `study_instance_uids` JSON NOT NULL COMMENT 'UIDs de estudios a recuperar',
  `status` ENUM('pending', 'running', 'success', 'failed', 'cancelled') DEFAULT 'pending',
  `progress` INT(3) DEFAULT 0 COMMENT 'Progreso 0-100',
  `callback_url` VARCHAR(500) DEFAULT NULL COMMENT 'Webhook para notificar',
  `error_message` TEXT DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_status` (`status`),
  KEY `idx_orthanc_job_id` (`orthanc_job_id`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Jobs asincrónicos de recuperación';

-- Tabla: pacs_cloner_policies (réplica programada / PACS Cloner)
CREATE TABLE IF NOT EXISTS `pacs_cloner_policies` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT 'Nombre descriptivo',
  `node_id` INT(11) UNSIGNED NOT NULL COMMENT 'Nodo origen (remoto)',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Si el daemon futuro debe usar esta política',
  `mode` ENUM('paused','assisted','automatic') NOT NULL DEFAULT 'paused' COMMENT 'paused=solo manual; assisted/automatic reservado',
  `scan_window_hours` INT(11) UNSIGNED NOT NULL DEFAULT 24 COMMENT 'Ventana de descubrimiento (horas); futuro daemon',
  `date_from` DATE DEFAULT NULL,
  `date_to` DATE DEFAULT NULL,
  `max_concurrent` TINYINT(3) UNSIGNED NOT NULL DEFAULT 2,
  `modality_filter` VARCHAR(32) DEFAULT NULL COMMENT 'Ej. CT,MR o vacío=todas',
  `modality_priority` VARCHAR(128) DEFAULT NULL COMMENT 'Orden de prioridad para encolado (ej. CR,DX,CT,MR)',
  `alignment_strategy` ENUM('study','series','instance') NOT NULL DEFAULT 'study' COMMENT 'Granularidad C-FIND/C-MOVE para reanudar',
  `discovery_sort` ENUM('modality','oldest_study','newest_study') NOT NULL DEFAULT 'modality' COMMENT 'Orden C-FIND antes de encolar (worker)',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_enabled` (`is_enabled`),
  CONSTRAINT `fk_cloner_policy_node` FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Políticas PACS Cloner';

-- Tabla: pacs_cloner_orders
CREATE TABLE IF NOT EXISTS `pacs_cloner_orders` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `policy_id` INT(11) UNSIGNED DEFAULT NULL,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `trigger_type` ENUM('ui','scheduled','worker') NOT NULL DEFAULT 'ui',
  `label` VARCHAR(255) DEFAULT NULL,
  `study_instance_uids` JSON NOT NULL,
  `pacs_node_job_id` INT(11) UNSIGNED DEFAULT NULL,
  `status` ENUM('pending','running','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_by` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_policy` (`policy_id`),
  KEY `idx_pacs_node_job` (`pacs_node_job_id`),
  CONSTRAINT `fk_cloner_order_policy` FOREIGN KEY (`policy_id`) REFERENCES `pacs_cloner_policies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cloner_order_node` FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cloner_order_job` FOREIGN KEY (`pacs_node_job_id`) REFERENCES `pacs_node_jobs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Órdenes de clonado (trazabilidad)';

-- Tabla: pacs_cloner_worker_runs
CREATE TABLE IF NOT EXISTS `pacs_cloner_worker_runs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME DEFAULT NULL,
  `policies_touched` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `studies_discovered` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `studies_skipped_local` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `studies_queued` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial ejecuciones worker cloner';

-- Tabla: pacs_cloner_study_snapshot (conteos remotos entre pasadas del worker)
CREATE TABLE IF NOT EXISTS `pacs_cloner_study_snapshot` (
  `node_id` INT(11) UNSIGNED NOT NULL,
  `study_instance_uid` VARCHAR(255) NOT NULL,
  `last_remote_instance_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`node_id`, `study_instance_uid`),
  KEY `idx_updated` (`updated_at`),
  CONSTRAINT `fk_cloner_snapshot_node` FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Último conteo remoto visto por el worker (estabilidad C-FIND)';

-- Tabla: pacs_node_statistics
-- Estadísticas de uso por nodo
CREATE TABLE IF NOT EXISTS `pacs_node_statistics` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `queries_count` INT(11) DEFAULT 0 COMMENT 'Número de C-FIND realizados',
  `retrieves_count` INT(11) DEFAULT 0 COMMENT 'Número de C-MOVE/C-GET realizados',
  `studies_retrieved` INT(11) DEFAULT 0 COMMENT 'Total de estudios recuperados',
  `success_rate` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Tasa de éxito %',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_node_date` (`node_id`, `date`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_date` (`date`),
  FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Estadísticas de uso por nodo';

-- =====================================================
-- Permisos del Sistema
-- =====================================================

-- Insertar permisos si no existen
INSERT IGNORE INTO `system_permissions` (`permission_key`, `permission_name`, `description`, `category`, `created_at`) VALUES
('pacs_nodes_manager', 'Gestionar Nodos PACS', 'Permite gestionar nodos PACS remotos y realizar operaciones C-FIND, C-MOVE, C-GET', 'pacs', NOW()),
('gui_pacs_nodes_manager', 'Ver PACS Nodes Manager', 'Permite ver y acceder al módulo PACS Nodes Manager en el sidebar', 'interfaz', NOW());

-- =====================================================
-- Fin del Script de Instalación
-- =====================================================
