-- Agregar 'pre-download' al ENUM de upload_method en r2_queue
-- Ejecutar: mysql -uroot -p tjsmedical_iddse < add_pre_download_method.sql

USE tjsmedical_iddse;

-- Modificar el ENUM para incluir 'pre-download'
ALTER TABLE r2_queue 
MODIFY COLUMN upload_method ENUM('instance', 'zip', 'pre-download') DEFAULT 'instance';
