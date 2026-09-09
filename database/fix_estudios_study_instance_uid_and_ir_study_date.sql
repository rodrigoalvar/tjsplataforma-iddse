-- ─────────────────────────────────────────────────────────────────────────────
-- 1) Poblar estudios.study_instance_uid desde orthanc_study_id cuando
--    orthanc_study_id contiene un DICOM UID (empieza con dígito y tiene puntos)
--    y study_instance_uid está vacío / NULL.
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE `estudios`
SET `study_instance_uid` = `orthanc_study_id`
WHERE (`study_instance_uid` IS NULL OR `study_instance_uid` = '')
  AND `orthanc_study_id` IS NOT NULL
  AND `orthanc_study_id` REGEXP '^[0-9]+\\.[0-9]';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2) Añadir columna study_date_dicom a informes_recibidos para guardar la fecha
--    real del estudio DICOM (tag 0008.0020) separada de la fecha programada.
--    Ignorar si ya existe.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `informes_recibidos`
  ADD COLUMN `study_date_dicom` DATE DEFAULT NULL COMMENT 'Fecha real del estudio DICOM (0008.0020)';
