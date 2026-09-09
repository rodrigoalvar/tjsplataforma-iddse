-- Agregar campos DICOM a la tabla usuarios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Agregar columnas DICOM si no existen
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS dicom_aetitle VARCHAR(50) NULL COMMENT 'Application Entity Title (AE Title) para DICOM',
ADD COLUMN IF NOT EXISTS dicom_puerto INT NULL COMMENT 'Puerto para conexión DICOM',
ADD COLUMN IF NOT EXISTS dicom_ip VARCHAR(45) NULL COMMENT 'Dirección IP para conexión DICOM';

-- Verificar que las columnas se agregaron correctamente
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_COMMENT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'tjsmedical' 
  AND TABLE_NAME = 'usuarios' 
  AND COLUMN_NAME IN ('dicom_aetitle', 'dicom_puerto', 'dicom_ip');

