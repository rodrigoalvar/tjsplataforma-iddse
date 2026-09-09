-- Script simplificado para agregar columna pacs_series_id
-- Si la columna ya existe, MySQL mostrará un error que puedes ignorar

USE TJSMEDICAL;

-- Agregar columna pacs_series_id
ALTER TABLE informes 
ADD COLUMN pacs_series_id VARCHAR(255) NULL 
COMMENT 'ID de la serie en Orthanc donde se almacenó el PDF. Usado para eliminar la serie completa al actualizar el informe.';

-- Crear índice
CREATE INDEX idx_pacs_series_id ON informes(pacs_series_id);

