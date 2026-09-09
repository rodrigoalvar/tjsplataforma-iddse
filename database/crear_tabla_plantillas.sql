-- Tabla para almacenar plantillas de informes médicos
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Tabla para plantillas de informes
CREATE TABLE IF NOT EXISTS plantillas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'ID único de la plantilla (ej: rx_torax)',
    nombre VARCHAR(255) NOT NULL COMMENT 'Nombre descriptivo de la plantilla',
    contenido_html LONGTEXT NOT NULL COMMENT 'Contenido HTML de la plantilla',
    usuario_id INT COMMENT 'ID del usuario que creó la plantilla (NULL para plantillas del sistema)',
    activo BOOLEAN DEFAULT TRUE COMMENT 'Indica si la plantilla está activa',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última modificación',
    
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_template_id (template_id),
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_activo (activo),
    INDEX idx_fecha_creacion (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar plantillas predeterminadas del sistema (si no existen)
INSERT INTO plantillas (template_id, nombre, contenido_html, usuario_id, activo) VALUES
('rx', 'Radiografía Simple', 
'<h2>INFORME RADIOGRÁFICO</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>', 
NULL, TRUE)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plantillas (template_id, nombre, contenido_html, usuario_id, activo) VALUES
('ct', 'Tomografía Computada', 
'<h2>INFORME TOMOGRÁFICO</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>', 
NULL, TRUE)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- Verificar estructura de la tabla
DESCRIBE plantillas;

-- Verificar plantillas insertadas
SELECT template_id, nombre, usuario_id, activo, fecha_creacion 
FROM plantillas 
ORDER BY fecha_creacion;

