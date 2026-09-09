<?php
/**
 * Script de Instalación Simplificado del Sistema de Gestión de Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script ejecuta solo las consultas esenciales paso a paso
 */

// Configuración
$config = [
    'database' => [
        'host' => 'localhost',
        'dbname' => 'tjsmedical',
        'username' => 'root',
        'password' => ''
    ],
    'root_user' => [
        'nombre' => 'Admin',
        'apellido' => 'Root',
        'email' => 'root@portal.com',
        'password' => 'admin123', // Cambiar después de la instalación
        'telefono' => '+54 11 0000-0000',
        'matricula_profesional' => 'ROOT001'
    ]
];

echo "<h1>Instalación Simplificada del Sistema de Gestión de Usuarios</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

try {
    // Conectar a la base de datos
    $dsn = "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['database']['username'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
    ]);
    
    echo "<div style='color: green;'>✓ Conexión a la base de datos exitosa</div>";
    
    // Lista de consultas esenciales
    $queries = [
        // 1. Actualizar tabla usuarios
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS nivel ENUM('root', 'admin', 'user') NOT NULL DEFAULT 'user'",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS padre_id INT NULL",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS especialidad VARCHAR(100) NULL",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS permisos JSON NULL",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS ultimo_acceso DATETIME NULL",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        
        // 2. Crear índices
        "ALTER TABLE usuarios ADD INDEX IF NOT EXISTS idx_nivel (nivel)",
        "ALTER TABLE usuarios ADD INDEX IF NOT EXISTS idx_padre_id (padre_id)",
        "ALTER TABLE usuarios ADD INDEX IF NOT EXISTS idx_activo (activo)",
        
        // 3. Crear tabla de permisos
        "CREATE TABLE IF NOT EXISTS system_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(100) UNIQUE NOT NULL,
            permission_name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            category VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_permission_key (permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // 4. Crear tabla de auditoría
        "CREATE TABLE IF NOT EXISTS user_audit_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // 5. Crear tabla de sesiones
        "CREATE TABLE IF NOT EXISTS user_sessions (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    
    $executed = 0;
    $errors = 0;
    
    // Ejecutar consultas una por una
    foreach ($queries as $query) {
        try {
            $pdo->exec($query);
            $executed++;
            echo "<div style='color: green;'>✓ Consulta ejecutada: " . substr($query, 0, 50) . "...</div>";
        } catch (PDOException $e) {
            $errorMessage = $e->getMessage();
            // Ignorar errores de "ya existe"
            if (strpos($errorMessage, 'already exists') === false && 
                strpos($errorMessage, 'Duplicate') === false) {
                echo "<div style='color: orange;'>⚠ Advertencia: " . htmlspecialchars($errorMessage) . "</div>";
                $errors++;
            }
        }
    }
    
    echo "<div style='color: green;'>✓ Consultas ejecutadas: {$executed}</div>";
    
    // Insertar permisos del sistema
    $permissions = [
        ['dashboard', 'Acceso al Dashboard', 'Permite acceder al panel principal del sistema', 'general'],
        ['estudios', 'Gestión de Estudios', 'Permite gestionar y asignar estudios médicos', 'estudios'],
        ['informes', 'Creación de Informes', 'Permite crear informes médicos', 'informes'],
        ['gestionInformes', 'Gestión de Informes', 'Permite gestionar todos los informes del sistema', 'informes'],
        ['grabacion', 'Grabación de Audio', 'Permite grabar audios para informes', 'audio'],
        ['plantillas', 'Gestión de Plantillas', 'Permite gestionar plantillas de informes', 'plantillas'],
        ['visor', 'Visor DICOM', 'Permite acceder al visor de imágenes DICOM', 'visor'],
        ['configuracion', 'Configuración del Sistema', 'Permite acceder a la configuración del sistema', 'admin'],
        ['usuarios', 'Gestión de Usuarios', 'Permite gestionar usuarios del sistema', 'admin'],
        ['pacientes', 'Gestión Pacientes', 'Permite gestionar pacientes del sistema', 'admin'],
        ['all', 'Acceso Completo', 'Acceso completo a todas las funcionalidades', 'admin']
    ];
    
    $permissionsInserted = 0;
    foreach ($permissions as $permission) {
        try {
            $query = "INSERT INTO system_permissions (permission_key, permission_name, description, category) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($query);
            $stmt->execute($permission);
            $permissionsInserted++;
        } catch (PDOException $e) {
            // Ignorar si ya existe
        }
    }
    
    echo "<div style='color: green;'>✓ Permisos insertados: {$permissionsInserted}</div>";
    
    // Insertar usuario ROOT
    try {
        $query = "INSERT INTO usuarios (
            nombre, apellido, email, telefono, matricula_profesional, 
            password_hash, nivel, padre_id, especialidad, activo, permisos
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $config['root_user']['nombre'],
            $config['root_user']['apellido'],
            $config['root_user']['email'],
            $config['root_user']['telefono'],
            $config['root_user']['matricula_profesional'],
            password_hash($config['root_user']['password'], PASSWORD_DEFAULT),
            'root',
            NULL,
            'Sistema',
            1,
            '["all"]'
        ]);
        
        echo "<div style='color: green;'>✓ Usuario ROOT creado exitosamente</div>";
        
        // Mostrar credenciales
        echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>Credenciales del Usuario ROOT:</h4>";
        echo "<p><strong>Email:</strong> {$config['root_user']['email']}</p>";
        echo "<p><strong>Contraseña:</strong> {$config['root_user']['password']}</p>";
        echo "<p><strong>Nivel:</strong> ROOT</p>";
        echo "<p style='color: red;'><strong>IMPORTANTE:</strong> Cambia la contraseña después del primer login por seguridad.</p>";
        echo "</div>";
        
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            echo "<div style='color: orange;'>⚠ Usuario ROOT ya existe</div>";
        } else {
            echo "<div style='color: red;'>✗ Error creando usuario ROOT: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Actualizar usuarios existentes
    try {
        $query = "UPDATE usuarios SET nivel = 'user', permisos = '[\"dashboard\", \"informes\", \"grabacion\"]' WHERE nivel IS NULL OR nivel = ''";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $updated = $stmt->rowCount();
        
        if ($updated > 0) {
            echo "<div style='color: green;'>✓ Usuarios existentes actualizados: {$updated}</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: orange;'>⚠ No se pudieron actualizar usuarios existentes: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Verificar instalación
    try {
        $query = "SELECT COUNT(*) as total FROM usuarios WHERE activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $totalUsers = $stmt->fetch()['total'];
        
        $query = "SELECT COUNT(*) as total FROM system_permissions";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $totalPermissions = $stmt->fetch()['total'];
        
        echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>Verificación de Instalación:</h4>";
        echo "<p><strong>Total usuarios:</strong> {$totalUsers}</p>";
        echo "<p><strong>Total permisos:</strong> {$totalPermissions}</p>";
        echo "</div>";
        
    } catch (PDOException $e) {
        echo "<div style='color: orange;'>⚠ No se pudieron obtener estadísticas: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4 style='color: #155724;'>¡Instalación Completada!</h4>";
    echo "<p style='color: #155724;'>El sistema de gestión de usuarios está listo para usar.</p>";
    echo "<p style='color: #155724;'><strong>Próximos pasos:</strong></p>";
    echo "<ul style='color: #155724;'>";
    echo "<li>Inicia sesión con las credenciales ROOT</li>";
    echo "<li>Cambia la contraseña del usuario ROOT</li>";
    echo "<li>Crea usuarios ADMIN según sea necesario</li>";
    echo "<li>Asigna usuarios USER a jerarquías</li>";
    echo "<li>Configura permisos específicos para cada usuario</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4 style='color: #856404;'>Seguridad:</h4>";
    echo "<p style='color: #856404;'>• Elimina este archivo de instalación después de completar la configuración</p>";
    echo "<p style='color: #856404;'>• Cambia las credenciales por defecto</p>";
    echo "<p style='color: #856404;'>• Configura HTTPS en producción</p>";
    echo "<p style='color: #856404;'>• Revisa los logs de auditoría regularmente</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error durante la instalación: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>Soluciones Comunes:</h4>";
    echo "<ul>";
    echo "<li>Verifica que la base de datos '{$config['database']['dbname']}' existe</li>";
    echo "<li>Verifica las credenciales de la base de datos</li>";
    echo "<li>Asegúrate de que el usuario de MySQL tiene permisos para crear tablas</li>";
    echo "<li>Verifica que el archivo SQL existe en la ruta correcta</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>Instalación completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


