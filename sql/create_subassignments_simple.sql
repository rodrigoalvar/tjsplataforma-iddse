-- Crear tabla de subasignaciones (versión simplificada)
CREATE TABLE IF NOT EXISTS study_subassignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id VARCHAR(255) NOT NULL,
    main_user_id INT NOT NULL,
    subassigned_to_user_id INT NOT NULL,
    assigned_by_user_id INT NOT NULL,
    subassigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    
    INDEX idx_study_main (study_id, main_user_id),
    INDEX idx_subassigned_to (subassigned_to_user_id),
    INDEX idx_main_user (main_user_id),
    INDEX idx_assigned_by (assigned_by_user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agregar foreign keys (ejecutar solo si no dan error por duplicados)
ALTER TABLE study_subassignments ADD CONSTRAINT fk_main_user FOREIGN KEY (main_user_id) REFERENCES usuarios(id) ON DELETE CASCADE;
ALTER TABLE study_subassignments ADD CONSTRAINT fk_subassigned_user FOREIGN KEY (subassigned_to_user_id) REFERENCES usuarios(id) ON DELETE CASCADE;
ALTER TABLE study_subassignments ADD CONSTRAINT fk_assigned_by_user FOREIGN KEY (assigned_by_user_id) REFERENCES usuarios(id) ON DELETE CASCADE;

