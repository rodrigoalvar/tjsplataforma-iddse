-- Agregar campo dicom_viewer a la tabla usuarios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical;

-- Agregar columna dicom_viewer si no existe
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS dicom_viewer VARCHAR(20) NULL DEFAULT 'UDV' 
COMMENT 'Visor DICOM preferido para Desktop: UDV, StoneViewer, Oviyam';

-- Verificar que la columna se agregó correctamente
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'tjsmedical' 
  AND TABLE_NAME = 'usuarios' 
  AND COLUMN_NAME = 'dicom_viewer';

