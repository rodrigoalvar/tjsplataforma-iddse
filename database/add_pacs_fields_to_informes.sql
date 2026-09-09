-- Agregar campos opcionales para sincronización con PACS
-- Estos campos almacenan las referencias cuando un informe se envía a Orthanc

USE TJSMEDICAL;

-- Agregar campos de PACS si no existen
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS fecha_enviado_pacs TIMESTAMP NULL COMMENT 'Fecha y hora cuando el informe fue enviado a PACS',
ADD COLUMN IF NOT EXISTS pacs_instance_id VARCHAR(255) NULL COMMENT 'ID del objeto DICOM en Orthanc (Instance ID)',
ADD COLUMN IF NOT EXISTS pacs_study_id VARCHAR(255) NULL COMMENT 'ID del estudio en Orthanc (Study ID)';

-- Crear índices para búsquedas rápidas
CREATE INDEX IF NOT EXISTS idx_fecha_enviado_pacs ON informes(fecha_enviado_pacs);
CREATE INDEX IF NOT EXISTS idx_pacs_instance_id ON informes(pacs_instance_id);
CREATE INDEX IF NOT EXISTS idx_pacs_study_id ON informes(pacs_study_id);

-- Comentarios adicionales sobre el uso:
-- - fecha_enviado_pacs: Permite saber cuándo se envió el informe a PACS
-- - pacs_instance_id: Referencia al objeto DICOM específico en Orthanc
-- - pacs_study_id: Referencia al estudio completo en Orthanc (puede incluir múltiples objetos)

