-- Crear tabla de log de estados de audios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Base de datos: tjsmedical_iddse o TJSMEDICAL

USE tjsmedical_iddse;

CREATE TABLE IF NOT EXISTS audios_estado_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    audio_id INT NOT NULL,
    estado_anterior ENUM('en_papelera', 'enviado_ftp', 'enviado_transcripcion', 'guardado_informe', 'eliminado') NULL,
    estado_nuevo ENUM('en_papelera', 'enviado_ftp', 'enviado_transcripcion', 'guardado_informe', 'eliminado') NOT NULL,
    accion VARCHAR(100) NOT NULL COMMENT 'Acción realizada (crear, enviar_ftp, enviar_transcripcion, finalizar_informe, eliminar, recuperar)',
    usuario_id INT NOT NULL,
    estudio_id VARCHAR(255),
    workspace_panel_id VARCHAR(100),
    metadata JSON COMMENT 'Metadatos adicionales de la acción',
    fecha_accion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (audio_id) REFERENCES audios_informe(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    INDEX idx_audio_id (audio_id),
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_estudio_id (estudio_id),
    INDEX idx_fecha_accion (fecha_accion),
    INDEX idx_accion (accion),
    INDEX idx_estado_nuevo (estado_nuevo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Tabla audios_estado_log creada exitosamente' AS resultado;
