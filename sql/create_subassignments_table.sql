-- Script SQL para crear tabla de subasignaciones de estudios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Ejecutar este script para crear la estructura de derivaciones

-- =====================================================
-- CREAR TABLA DE SUBASIGNACIONES
-- =====================================================

CREATE TABLE IF NOT EXISTS study_subassignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id VARCHAR(255) NOT NULL,
    main_user_id INT NOT NULL COMMENT 'Usuario principal al que se asignó originalmente el estudio',
    subassigned_to_user_id INT NOT NULL COMMENT 'Usuario hijo al que se derivó el estudio',
    assigned_by_user_id INT NOT NULL COMMENT 'Usuario que realizó la derivación',
    subassigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha y hora de la derivación',
    status ENUM('active', 'inactive') DEFAULT 'active',
    
    -- Índices para optimizar consultas
    INDEX idx_study_main (study_id, main_user_id),
    INDEX idx_subassigned_to (subassigned_to_user_id),
    INDEX idx_main_user (main_user_id),
    INDEX idx_assigned_by (assigned_by_user_id),
    INDEX idx_status (status),
    
    -- Foreign keys
    FOREIGN KEY (main_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (subassigned_to_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by_user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- COMENTARIOS
-- =====================================================

-- Esta tabla almacena las derivaciones de estudios desde cuentas principales a cuentas hijas
-- No modifica la asignación original en study_assignments
-- Permite rastrear:
--   1. Qué estudios fueron derivados
--   2. De qué cuenta principal fueron derivados
--   3. A qué cuenta hija se derivaron
--   4. Quién realizó la derivación
--   5. Cuándo se realizó la derivación

