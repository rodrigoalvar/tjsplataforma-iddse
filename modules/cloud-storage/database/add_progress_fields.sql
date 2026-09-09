-- Agregar campos de progreso a r2_queue para mostrar barra de progreso
-- Ejecutar: mysql -u root -p tjsiddse < add_progress_fields.sql

ALTER TABLE `r2_queue` 
ADD COLUMN `total_instances` INT(11) DEFAULT 0 COMMENT 'Total de instancias a subir' AFTER `status`,
ADD COLUMN `instances_uploaded` INT(11) DEFAULT 0 COMMENT 'Instancias subidas hasta ahora' AFTER `total_instances`,
ADD COLUMN `total_bytes` BIGINT(20) DEFAULT 0 COMMENT 'Total de bytes a subir' AFTER `instances_uploaded`,
ADD COLUMN `bytes_uploaded` BIGINT(20) DEFAULT 0 COMMENT 'Bytes subidos hasta ahora' AFTER `total_bytes`,
ADD COLUMN `upload_started_at` TIMESTAMP NULL COMMENT 'Momento en que comenzó el upload' AFTER `bytes_uploaded`,
ADD COLUMN `upload_finished_at` TIMESTAMP NULL COMMENT 'Momento en que terminó el upload' AFTER `upload_started_at`,
ADD COLUMN `upload_duration_seconds` INT(11) DEFAULT NULL COMMENT 'Duración total del upload en segundos' AFTER `upload_finished_at`,
ADD COLUMN `upload_speed_mbps` DECIMAL(10,2) DEFAULT NULL COMMENT 'Velocidad de upload actual en MB/s' AFTER `upload_duration_seconds`,
ADD COLUMN `upload_speed_min_mbps` DECIMAL(10,2) DEFAULT NULL COMMENT 'Velocidad mínima detectada en MB/s' AFTER `upload_speed_mbps`,
ADD COLUMN `upload_speed_max_mbps` DECIMAL(10,2) DEFAULT NULL COMMENT 'Velocidad máxima detectada en MB/s' AFTER `upload_speed_min_mbps`,
ADD COLUMN `upload_speed_avg_mbps` DECIMAL(10,2) DEFAULT NULL COMMENT 'Velocidad promedio en MB/s' AFTER `upload_speed_max_mbps`,
ADD COLUMN `upload_ip` VARCHAR(45) DEFAULT NULL COMMENT 'IP usada para el upload' AFTER `upload_speed_avg_mbps`;
