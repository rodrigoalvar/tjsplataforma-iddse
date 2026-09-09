-- Script SQL para crear tabla de flags de estudios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Permite marcar estudios con informes incompletos y agregar notas

-- =====================================================
-- CREAR TABLA DE FLAGS DE ESTUDIOS
-- =====================================================

CREATE TABLE IF NOT EXISTS study_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id VARCHAR(255) NOT NULL COMMENT 'ID del estudio (orthanc_id o study_instance_uid)',
    orthanc_id VARCHAR(255) NULL COMMENT 'Orthanc ID del estudio',
    study_instance_uid VARCHAR(255) NULL COMMENT 'Study Instance UID del estudio',
    user_id INT NOT NULL COMMENT 'ID del usuario para el cual aplica el flag',
    informes_incompletos BOOLEAN DEFAULT FALSE COMMENT 'Indica si el estudio tiene informes incompletos',
    nota TEXT NULL COMMENT 'Nota opcional sobre los informes incompletos',
    prioridad VARCHAR(20) DEFAULT 'normal' COMMENT 'Prioridad del estudio: normal, promesa, urgente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación del flag',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
    created_by INT NULL COMMENT 'ID del usuario que creó el flag',
    
    -- Índices para optimizar consultas
    UNIQUE KEY unique_study_user (study_id, user_id),
    INDEX idx_study_id (study_id),
    INDEX idx_orthanc_id (orthanc_id),
    INDEX idx_study_instance_uid (study_instance_uid),
    INDEX idx_user_id (user_id),
    INDEX idx_informes_incompletos (informes_incompletos),
    INDEX idx_prioridad (prioridad),
    INDEX idx_created_by (created_by),
    
    -- Foreign keys
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- COMENTARIOS
-- =====================================================

-- Esta tabla permite:
-- 1. Marcar estudios con informes incompletos por usuario
-- 2. Agregar notas opcionales sobre los informes incompletos
-- 3. Rastrear quién y cuándo marcó el estudio
-- 4. Asignar prioridades a estudios (normal, promesa, urgente)
-- 
-- Lógica de usuarios:
-- - Si estudio está asignado: flag para el usuario asignado
-- - Si estudio está derivado: flag para AMBOS usuarios (principal y derivado)
-- - Si es PACS QUERY: flag para el usuario que lo marca
--
-- El flag es específico por usuario, permitiendo que diferentes usuarios
-- tengan diferentes percepciones sobre el estado de los informes del mismo estudio.

