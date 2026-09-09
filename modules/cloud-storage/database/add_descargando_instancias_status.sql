-- Agregar estado 'descargando_instancias' al ENUM de status en r2_queue
-- Ejecutar: mysql -uroot -p tjsmedical_iddse < add_descargando_instancias_status.sql

USE tjsmedical_iddse;

-- Modificar el ENUM para incluir 'descargando_instancias'
ALTER TABLE r2_queue 
MODIFY COLUMN status ENUM(
    'pending', 
    'preparando_estudio', 
    'descargando_instancias',
    'generando_zip', 
    'generando_manifest', 
    'guardando_manifest_en_zip', 
    'subiendo', 
    'enviando_a_r2', 
    'guardado_en_r2', 
    'uploading', 
    'done', 
    'error', 
    'cancelled', 
    'pending_extraction', 
    'extraccion_en_curso', 
    'estudio_online'
) DEFAULT 'pending' COMMENT 'Estado del procesamiento';
