-- Script para crear la tabla pacientes si no existe
-- Base de datos TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Crear tabla pacientes con campos estándar
CREATE TABLE IF NOT EXISTS pacientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id VARCHAR(100) UNIQUE NOT NULL COMMENT 'ID único del paciente (puede ser DNI, número de historia clínica, etc.)',
    nombre VARCHAR(255) NOT NULL COMMENT 'Nombre del paciente',
    apellido VARCHAR(255) NOT NULL COMMENT 'Apellido del paciente',
    fecha_nacimiento DATE DEFAULT NULL COMMENT 'Fecha de nacimiento',
    sexo CHAR(1) DEFAULT NULL COMMENT 'Sexo: M (Masculino), F (Femenino), O (Otro)',
    tipo_documento VARCHAR(20) DEFAULT 'DNI' COMMENT 'Tipo de documento: DNI, Pasaporte, etc.',
    numero_documento VARCHAR(50) DEFAULT NULL COMMENT 'Número de documento',
    telefono VARCHAR(20) DEFAULT NULL COMMENT 'Teléfono de contacto',
    email VARCHAR(255) DEFAULT NULL COMMENT 'Email de contacto',
    direccion TEXT DEFAULT NULL COMMENT 'Dirección completa',
    ciudad VARCHAR(100) DEFAULT NULL COMMENT 'Ciudad',
    provincia VARCHAR(100) DEFAULT NULL COMMENT 'Provincia',
    codigo_postal VARCHAR(20) DEFAULT NULL COMMENT 'Código postal',
    obra_social VARCHAR(255) DEFAULT NULL COMMENT 'Obra social o seguro médico',
    numero_afiliado VARCHAR(100) DEFAULT NULL COMMENT 'Número de afiliado a obra social',
    contacto_emergencia VARCHAR(255) DEFAULT NULL COMMENT 'Contacto de emergencia',
    telefono_emergencia VARCHAR(20) DEFAULT NULL COMMENT 'Teléfono de contacto de emergencia',
    alergias TEXT DEFAULT NULL COMMENT 'Alergias conocidas',
    medicamentos_actuales TEXT DEFAULT NULL COMMENT 'Medicamentos que toma actualmente',
    antecedentes TEXT DEFAULT NULL COMMENT 'Antecedentes médicos relevantes',
    notas TEXT DEFAULT NULL COMMENT 'Notas adicionales sobre el paciente',
    activo BOOLEAN DEFAULT TRUE COMMENT 'Indica si el paciente está activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación del registro',
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
    INDEX idx_paciente_id (paciente_id),
    INDEX idx_nombre_apellido (nombre, apellido),
    INDEX idx_numero_documento (numero_documento),
    INDEX idx_activo (activo),
    INDEX idx_fecha_creacion (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de pacientes del sistema';

-- Verificar estructura de la tabla
SHOW COLUMNS FROM pacientes;


