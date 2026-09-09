-- Crear tabla de sesiones de workspace para recuperación de audios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Base de datos: tjsmedical_iddse o TJSMEDICAL

USE tjsmedical_iddse;

CREATE TABLE IF NOT EXISTS audios_workspace_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    estudio_id VARCHAR(255) NOT NULL,
    workspace_panel_id VARCHAR(100) NOT NULL,
    session_token VARCHAR(255) COMMENT 'Token de sesión del navegador',
    recordings_data JSON COMMENT 'Datos de recordings del workspace (backup)',
    fecha_ultima_actividad TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activa BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_usuario_estudio (usuario_id, estudio_id),
    INDEX idx_workspace_panel_id (workspace_panel_id),
    INDEX idx_session_token (session_token),
    INDEX idx_fecha_ultima_actividad (fecha_ultima_actividad),
    INDEX idx_activa (activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Tabla audios_workspace_sessions creada exitosamente' AS resultado;
