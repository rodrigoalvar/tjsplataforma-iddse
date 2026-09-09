-- Script SQL para crear tabla de usuarios con jerarquías
-- Ejecutar este script en la base de datos para crear la estructura necesaria

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    nivel ENUM('root', 'admin', 'usuario') NOT NULL DEFAULT 'usuario',
    padre_id INT NULL,
    especialidad VARCHAR(100) NULL,
    activo BOOLEAN DEFAULT TRUE,
    permisos JSON NULL,
    ultimo_acceso DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Índices
    INDEX idx_email (email),
    INDEX idx_nivel (nivel),
    INDEX idx_padre_id (padre_id),
    INDEX idx_activo (activo),
    
    -- Foreign key constraint
    FOREIGN KEY (padre_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar usuario root inicial
INSERT INTO usuarios (nombre, apellido, email, password, nivel, padre_id, especialidad, activo, permisos) 
VALUES (
    'Admin', 
    'Root', 
    'root@portal.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'root', 
    NULL, 
    'Sistema', 
    TRUE, 
    '["all"]'
) ON DUPLICATE KEY UPDATE email = email;

-- Insertar algunos usuarios de ejemplo
INSERT INTO usuarios (nombre, apellido, email, password, nivel, padre_id, especialidad, activo, permisos) 
VALUES 
(
    'Dr. Juan', 
    'Pérez', 
    'jperez@portal.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'admin', 
    1, 
    'Radiología', 
    TRUE, 
    '["users", "config", "reports", "studies"]'
),
(
    'Dr. María', 
    'González', 
    'mgonzalez@portal.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'usuario', 
    2, 
    'Radiología', 
    TRUE, 
    '["reports", "studies"]'
),
(
    'Dr. Carlos', 
    'López', 
    'clopez@portal.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'usuario', 
    2, 
    'Radiología', 
    FALSE, 
    '["reports"]'
),
(
    'Dr. Ana', 
    'Martín', 
    'amartin@portal.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'admin', 
    1, 
    'Cardiología', 
    TRUE, 
    '["users", "reports", "studies"]'
) ON DUPLICATE KEY UPDATE email = email;

-- Crear tabla para logs de actividad de usuarios
CREATE TABLE IF NOT EXISTS user_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Crear tabla para sesiones de usuarios
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_session_token (session_token),
    INDEX idx_expires_at (expires_at),
    
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Crear tabla para permisos del sistema
CREATE TABLE IF NOT EXISTS system_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) UNIQUE NOT NULL,
    permission_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    category VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_category (category),
    INDEX idx_permission_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar permisos del sistema
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('dashboard', 'Acceso al Dashboard', 'Permite acceder al panel principal del sistema', 'general'),
('estudios', 'Gestión de Estudios', 'Permite gestionar y asignar estudios médicos', 'estudios'),
('informes', 'Creación de Informes', 'Permite crear informes médicos', 'informes'),
('gestionInformes', 'Gestión de Informes', 'Permite gestionar todos los informes del sistema', 'informes'),
('grabacion', 'Grabación de Audio', 'Permite grabar audios para informes', 'audio'),
('configuracion', 'Configuración del Sistema', 'Permite acceder a la configuración del sistema', 'admin'),
('usuarios', 'Gestión de Usuarios', 'Permite gestionar usuarios del sistema', 'admin'),
('pacientes', 'Gestión Pacientes', 'Permite gestionar pacientes del sistema', 'admin'),
('all', 'Acceso Completo', 'Acceso completo a todas las funcionalidades', 'admin')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- Crear vista para usuarios con información de padre
CREATE OR REPLACE VIEW usuarios_con_padre AS
SELECT 
    u.id,
    u.nombre,
    u.apellido,
    u.email,
    u.nivel,
    u.padre_id,
    u.especialidad,
    u.activo,
    u.permisos,
    u.ultimo_acceso,
    u.created_at,
    u.updated_at,
    p.nombre as padre_nombre,
    p.apellido as padre_apellido,
    p.email as padre_email
FROM usuarios u
LEFT JOIN usuarios p ON u.padre_id = p.id;

-- Crear procedimiento para obtener jerarquía de usuarios
DELIMITER //
CREATE PROCEDURE GetUserHierarchy()
BEGIN
    WITH RECURSIVE user_hierarchy AS (
        -- Usuarios raíz (sin padre)
        SELECT 
            id, nombre, apellido, email, nivel, padre_id, especialidad, activo, permisos,
            0 as level,
            CAST(CONCAT(nombre, ' ', apellido) AS CHAR(500)) as path
        FROM usuarios 
        WHERE padre_id IS NULL
        
        UNION ALL
        
        -- Usuarios hijos
        SELECT 
            u.id, u.nombre, u.apellido, u.email, u.nivel, u.padre_id, u.especialidad, u.activo, u.permisos,
            uh.level + 1,
            CAST(CONCAT(uh.path, ' > ', u.nombre, ' ', u.apellido) AS CHAR(500))
        FROM usuarios u
        INNER JOIN user_hierarchy uh ON u.padre_id = uh.id
    )
    SELECT * FROM user_hierarchy ORDER BY level, nombre;
END //
DELIMITER ;

-- Crear función para verificar permisos de usuario
DELIMITER //
CREATE FUNCTION CheckUserPermission(user_id INT, permission_key VARCHAR(100))
RETURNS BOOLEAN
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE user_level VARCHAR(20);
    DECLARE user_permissions JSON;
    DECLARE has_permission BOOLEAN DEFAULT FALSE;
    
    -- Obtener nivel y permisos del usuario
    SELECT nivel, permisos INTO user_level, user_permissions
    FROM usuarios 
    WHERE id = user_id AND activo = TRUE;
    
    -- Verificar si es root (tiene todos los permisos)
    IF user_level = 'root' THEN
        RETURN TRUE;
    END IF;
    
    -- Verificar si tiene el permiso específico
    IF JSON_CONTAINS(user_permissions, JSON_QUOTE(permission_key)) THEN
        RETURN TRUE;
    END IF;
    
    -- Verificar si tiene permiso 'all'
    IF JSON_CONTAINS(user_permissions, JSON_QUOTE('all')) THEN
        RETURN TRUE;
    END IF;
    
    RETURN FALSE;
END //
DELIMITER ;

-- Crear trigger para actualizar último acceso
DELIMITER //
CREATE TRIGGER update_last_access
AFTER UPDATE ON user_sessions
FOR EACH ROW
BEGIN
    UPDATE usuarios 
    SET ultimo_acceso = NOW() 
    WHERE id = NEW.user_id;
END //
DELIMITER ;

-- Crear índices adicionales para optimización
CREATE INDEX idx_usuarios_nivel_activo ON usuarios(nivel, activo);
CREATE INDEX idx_usuarios_padre_nivel ON usuarios(padre_id, nivel);
CREATE INDEX idx_user_sessions_user_expires ON user_sessions(user_id, expires_at);

-- Comentarios sobre la estructura
-- La tabla usuarios permite jerarquías mediante padre_id
-- Los permisos se almacenan como JSON para flexibilidad
-- Se incluyen logs de actividad y gestión de sesiones
-- Se proporcionan vistas y procedimientos para facilitar consultas

