-- Agregar 'cancelled' al ENUM de status en r2_queue
-- Compatible con MySQL 5.7 y 8.0

ALTER TABLE `r2_queue` 
MODIFY COLUMN `status` ENUM('pending', 'uploading', 'done', 'error', 'cancelled', 'pending_extraction') DEFAULT 'pending' 
COMMENT 'Estado del procesamiento';
