-- Estructura de base de datos para informes y audios vinculados a estudios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Tabla para informes médicos
CREATE TABLE IF NOT EXISTS informes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estudio_id VARCHAR(255) NOT NULL COMMENT 'ID del estudio en Orthanc',
    usuario_id INT NOT NULL COMMENT 'ID del médico que crea el informe',
    patient_id VARCHAR(255) COMMENT 'ID del paciente',
    patient_name VARCHAR(255) COMMENT 'Nombre del paciente',
    modality VARCHAR(10) COMMENT 'Modalidad del estudio (CT, MR, etc.)',
    study_description TEXT COMMENT 'Descripción del estudio',
    titulo VARCHAR(255) COMMENT 'Título del informe',
    contenido_html LONGTEXT COMMENT 'Contenido HTML del informe desde TinyMCE',
    contenido_texto TEXT COMMENT 'Contenido en texto plano para búsquedas',
    estado ENUM('borrador', 'finalizado', 'revisado', 'firmado') DEFAULT 'borrador',
    version INT DEFAULT 1 COMMENT 'Versión del informe para control de cambios',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fecha_finalizacion TIMESTAMP NULL COMMENT 'Fecha cuando se finalizó el informe',
    notas_revision TEXT COMMENT 'Notas de revisión o comentarios',
    
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    INDEX idx_estudio_id (estudio_id),
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_patient_id (patient_id),
    INDEX idx_estado (estado),
    INDEX idx_fecha_creacion (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para archivos de audio vinculados a informes
CREATE TABLE IF NOT EXISTS audios_informe (
    id INT PRIMARY KEY AUTO_INCREMENT,
    informe_id INT NULL COMMENT 'ID del informe asociado (puede ser NULL si es audio independiente)',
    estudio_id VARCHAR(255) NOT NULL COMMENT 'ID del estudio en Orthanc',
    usuario_id INT NOT NULL COMMENT 'ID del usuario que grabó el audio',
    tipo_grabacion ENUM('simple', 'sincronizada') NOT NULL COMMENT 'Tipo de grabación utilizada',
    nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre del archivo de audio',
    nombre_original VARCHAR(255) COMMENT 'Nombre original del archivo',
    ruta_archivo VARCHAR(500) NOT NULL COMMENT 'Ruta relativa del archivo en el servidor',
    duracion_segundos DECIMAL(10,2) COMMENT 'Duración del audio en segundos',
    tamano_bytes BIGINT COMMENT 'Tamaño del archivo en bytes',
    tipo_mime VARCHAR(100) DEFAULT 'audio/webm' COMMENT 'Tipo MIME del archivo',
    
    -- Datos específicos para grabación sincronizada
    datos_sincronizacion JSON COMMENT 'Datos de sincronización palabra-tiempo del módulo sync-editor',
    transcripcion_texto LONGTEXT COMMENT 'Transcripción completa del audio',
    
    -- Metadatos adicionales
    calidad_audio VARCHAR(50) COMMENT 'Calidad del audio (alta, media, baja)',
    dispositivo_grabacion VARCHAR(255) COMMENT 'Información del dispositivo de grabación',
    
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    activo BOOLEAN DEFAULT TRUE COMMENT 'Indica si el audio está activo o fue eliminado',
    
    FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    INDEX idx_informe_id (informe_id),
    INDEX idx_estudio_id (estudio_id),
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_tipo_grabacion (tipo_grabacion),
    INDEX idx_fecha_creacion (fecha_creacion),
    INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para historial de versiones de informes (opcional)
CREATE TABLE IF NOT EXISTS informes_historial (
    id INT PRIMARY KEY AUTO_INCREMENT,
    informe_id INT NOT NULL,
    version_anterior INT NOT NULL,
    contenido_html_anterior LONGTEXT,
    estado_anterior ENUM('borrador', 'finalizado', 'revisado', 'firmado'),
    usuario_modificacion INT NOT NULL,
    fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    motivo_cambio TEXT COMMENT 'Razón del cambio de versión',
    
    FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_modificacion) REFERENCES usuarios(id) ON DELETE RESTRICT,
    INDEX idx_informe_id (informe_id),
    INDEX idx_fecha_cambio (fecha_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar datos de ejemplo (opcional)
-- INSERT INTO informes (estudio_id, usuario_id, patient_id, patient_name, modality, titulo, contenido_html, estado)
-- VALUES 
-- ('study_123', 1, 'PAT001', 'Juan Pérez', 'CT', 'Informe de TC de Tórax', '<h3>TÉCNICA:</h3><p>TC de tórax con contraste</p>', 'borrador');

-- Comentarios sobre el diseño:
-- 1. La tabla 'informes' almacena el contenido completo del informe TinyMCE
-- 2. La tabla 'audios_informe' puede vincularse a un informe específico o existir independientemente
-- 3. Se incluye soporte para ambos tipos de grabación (simple y sincronizada)
-- 4. Los datos de sincronización se almacenan como JSON para flexibilidad
-- 5. Se incluye control de versiones básico
-- 6. Los índices optimizan las consultas más comunes
-- 7. Las claves foráneas mantienen la integridad referencial