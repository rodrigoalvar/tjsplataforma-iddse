-- Script SQL para Sistema de Gestión de Usuarios Profesionales
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Ejecutar este script para crear/actualizar la estructura de gestión de usuarios

-- =====================================================
-- 1. ACTUALIZAR TABLA USUARIOS EXISTENTE
-- =====================================================

-- Agregar campos necesarios para jerarquías y permisos
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS nivel ENUM('root', 'admin', 'user') NOT NULL DEFAULT 'user',
ADD COLUMN IF NOT EXISTS padre_id INT NULL,
ADD COLUMN IF NOT EXISTS especialidad VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS permisos JSON NULL,
ADD COLUMN IF NOT EXISTS ultimo_acceso DATETIME NULL,
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Agregar índices para optimización
ALTER TABLE usuarios 
ADD INDEX IF NOT EXISTS idx_nivel (nivel),
ADD INDEX IF NOT EXISTS idx_padre_id (padre_id),
ADD INDEX IF NOT EXISTS idx_activo (activo),
ADD INDEX IF NOT EXISTS idx_nivel_activo (nivel, activo),
ADD INDEX IF NOT EXISTS idx_padre_nivel (padre_id, nivel);

-- Agregar foreign key constraint para jerarquías
ALTER TABLE usuarios 
ADD CONSTRAINT IF NOT EXISTS fk_usuarios_padre 
FOREIGN KEY (padre_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- =====================================================
-- 2. CREAR TABLA DE PERMISOS DEL SISTEMA
-- =====================================================

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
('plantillas', 'Gestión de Plantillas', 'Permite gestionar plantillas de informes', 'plantillas'),
('visor', 'Visor DICOM', 'Permite acceder al visor de imágenes DICOM', 'visor'),
('configuracion', 'Configuración del Sistema', 'Permite acceder a la configuración del sistema', 'admin'),
('usuarios', 'Gestión de Usuarios', 'Permite gestionar usuarios del sistema', 'admin'),
('pacientes', 'Gestión Pacientes', 'Permite gestionar pacientes del sistema', 'admin'),
('all', 'Acceso Completo', 'Acceso completo a todas las funcionalidades', 'admin')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- =====================================================
-- 3. CREAR TABLA DE LOGS DE AUDITORÍA
-- =====================================================

CREATE TABLE IF NOT EXISTS user_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_user_id INT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_target_user_id (target_user_id),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. CREAR TABLA DE SESIONES DE USUARIOS
-- =====================================================

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
    INDEX idx_user_expires (user_id, expires_at),
    
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. CREAR VISTA PARA USUARIOS CON INFORMACIÓN DE PADRE
-- =====================================================

CREATE OR REPLACE VIEW usuarios_con_padre AS
SELECT 
    u.id,
    u.nombre,
    u.apellido,
    u.email,
    u.telefono,
    u.matricula_profesional,
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
    p.email as padre_email,
    p.nivel as padre_nivel
FROM usuarios u
LEFT JOIN usuarios p ON u.padre_id = p.id;

-- =====================================================
-- 6. CREAR PROCEDIMIENTO PARA OBTENER JERARQUÍA DE USUARIOS
-- =====================================================

DELIMITER //
CREATE PROCEDURE IF NOT EXISTS GetUserHierarchy()
BEGIN
    WITH RECURSIVE user_hierarchy AS (
        -- Usuarios raíz (sin padre)
        SELECT 
            id, nombre, apellido, email, nivel, padre_id, especialidad, activo, permisos,
            0 as level,
            CAST(CONCAT(nombre, ' ', apellido) AS CHAR(500)) as path
        FROM usuarios 
        WHERE padre_id IS NULL AND activo = 1
        
        UNION ALL
        
        -- Usuarios hijos
        SELECT 
            u.id, u.nombre, u.apellido, u.email, u.nivel, u.padre_id, u.especialidad, u.activo, u.permisos,
            uh.level + 1,
            CAST(CONCAT(uh.path, ' > ', u.nombre, ' ', u.apellido) AS CHAR(500))
        FROM usuarios u
        INNER JOIN user_hierarchy uh ON u.padre_id = uh.id
        WHERE u.activo = 1
    )
    SELECT * FROM user_hierarchy ORDER BY level, nombre;
END //
DELIMITER ;

-- =====================================================
-- 7. CREAR FUNCIÓN PARA VERIFICAR PERMISOS DE USUARIO
-- =====================================================

DELIMITER //
CREATE FUNCTION IF NOT EXISTS CheckUserPermission(user_id INT, permission_key VARCHAR(100))
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

-- =====================================================
-- 8. CREAR FUNCIÓN PARA OBTENER USUARIOS ASIGNABLES
-- =====================================================

DELIMITER //
CREATE FUNCTION IF NOT EXISTS GetAssignableUsers(current_user_id INT)
RETURNS TEXT
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE current_user_level VARCHAR(20);
    DECLARE user_list TEXT DEFAULT '';
    
    -- Obtener nivel del usuario actual
    SELECT nivel INTO current_user_level
    FROM usuarios 
    WHERE id = current_user_id AND activo = TRUE;
    
    -- Según el nivel, determinar usuarios asignables
    CASE current_user_level
        WHEN 'root' THEN
            -- ROOT puede asignar a todos
            SELECT GROUP_CONCAT(id) INTO user_list
            FROM usuarios 
            WHERE activo = TRUE;
            
        WHEN 'admin' THEN
            -- ADMIN puede asignar a todos
            SELECT GROUP_CONCAT(id) INTO user_list
            FROM usuarios 
            WHERE activo = TRUE;
            
        WHEN 'user' THEN
            -- USER solo puede asignar a sus hijos directos
            SELECT GROUP_CONCAT(id) INTO user_list
            FROM usuarios 
            WHERE padre_id = current_user_id AND activo = TRUE;
            
        ELSE
            SET user_list = '';
    END CASE;
    
    RETURN user_list;
END //
DELIMITER ;

-- =====================================================
-- 9. CREAR TRIGGER PARA ACTUALIZAR ÚLTIMO ACCESO
-- =====================================================

DELIMITER //
CREATE TRIGGER IF NOT EXISTS update_last_access
AFTER UPDATE ON user_sessions
FOR EACH ROW
BEGIN
    UPDATE usuarios 
    SET ultimo_acceso = NOW() 
    WHERE id = NEW.user_id;
END //
DELIMITER ;

-- =====================================================
-- 10. INSERTAR USUARIO ROOT INICIAL
-- =====================================================

-- Insertar usuario root inicial si no existe
INSERT INTO usuarios (
    nombre, apellido, email, telefono, matricula_profesional, 
    password_hash, nivel, padre_id, especialidad, activo, permisos
) VALUES (
    'Admin', 
    'Root', 
    'root@portal.com', 
    '+54 11 0000-0000',
    'ROOT001',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'root', 
    NULL, 
    'Sistema', 
    TRUE, 
    '["all"]'
) ON DUPLICATE KEY UPDATE email = email;

-- =====================================================
-- 11. ACTUALIZAR USUARIOS EXISTENTES
-- =====================================================

-- Actualizar usuarios existentes que no tienen nivel definido
UPDATE usuarios 
SET nivel = 'user', 
    permisos = '["dashboard", "informes", "grabacion"]'
WHERE nivel IS NULL OR nivel = '';

-- =====================================================
-- 12. CREAR ÍNDICES ADICIONALES PARA OPTIMIZACIÓN
-- =====================================================

CREATE INDEX IF NOT EXISTS idx_usuarios_email_nivel ON usuarios(email, nivel);
CREATE INDEX IF NOT EXISTS idx_usuarios_matricula_nivel ON usuarios(matricula_profesional, nivel);
CREATE INDEX IF NOT EXISTS idx_user_sessions_user_expires ON user_sessions(user_id, expires_at);
CREATE INDEX IF NOT EXISTS idx_audit_logs_user_action ON user_audit_logs(user_id, action);

-- =====================================================
-- COMENTARIOS SOBRE LA ESTRUCTURA
-- =====================================================

-- La tabla usuarios permite jerarquías mediante padre_id
-- Los permisos se almacenan como JSON para flexibilidad
-- Se incluyen logs de auditoría y gestión de sesiones
-- Se proporcionan vistas y procedimientos para facilitar consultas
-- El usuario ROOT está protegido contra modificaciones por ADMIN
-- Los usuarios se registran como USER por defecto
-- Solo ADMIN/ROOT pueden gestionar jerarquías

-- =====================================================
-- VERIFICACIÓN DE INSTALACIÓN
-- =====================================================

-- Verificar que las tablas se crearon correctamente
SELECT 'Verificación de instalación:' as status;
SELECT COUNT(*) as usuarios_totales FROM usuarios;
SELECT COUNT(*) as permisos_sistema FROM system_permissions;
SELECT COUNT(*) as logs_auditoria FROM user_audit_logs;
SELECT COUNT(*) as sesiones_usuarios FROM user_sessions;

-- Mostrar estructura de usuarios
SELECT 
    nivel,
    COUNT(*) as cantidad,
    GROUP_CONCAT(CONCAT(nombre, ' ', apellido) SEPARATOR ', ') as usuarios
FROM usuarios 
GROUP BY nivel;


