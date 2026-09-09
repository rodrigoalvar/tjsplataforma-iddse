-- Agregar columna study_instance_uid para vincular informes con estudios DICOM
-- Este campo almacena el StudyInstanceUID del estudio original para garantizar
-- que el informe PDF se asocie correctamente con el estudio en PACS

USE TJSMEDICAL;

-- Agregar columna study_instance_uid si no existe
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS study_instance_uid VARCHAR(255) NULL 
COMMENT 'StudyInstanceUID del estudio DICOM original en Orthanc. Usado para vincular el informe PDF con el estudio existente en PACS.';

-- Crear índice para búsquedas rápidas
CREATE INDEX IF NOT EXISTS idx_study_instance_uid ON informes(study_instance_uid);

-- Actualizar informes existentes que tienen estudio_id
-- Intentar obtener StudyInstanceUID desde Orthanc para informes ya creados
-- NOTA: Este es un proceso manual que requiere ejecutar un script PHP separado
-- o actualizarse automáticamente al enviar a PACS

-- Comentarios:
-- - study_instance_uid debe coincidir con el StudyInstanceUID del estudio original
-- - Si los StudyInstanceUID coinciden, Orthanc asociará el PDF como nueva serie del estudio
-- - Si son diferentes, Orthanc creará el PDF como un estudio completamente separado
-- - Este campo es crítico para la vinculación correcta en PACS

