<?php
/**
 * Script de Instalación Compatible con MySQL Antiguo
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script funciona con versiones antiguas de MySQL que no soportan IF NOT EXISTS en ALTER TABLE
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

echo "<h1>Instalación Compatible del Sistema de Gestión de Usuarios</h1>";
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
    
    // Función para verificar si una columna existe
    function columnExists($pdo, $table, $column) {
        try {
            $query = "SHOW COLUMNS FROM {$table} LIKE ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$column]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // Función para verificar si un índice existe
    function indexExists($pdo, $table, $index) {
        try {
            $query = "SHOW INDEX FROM {$table} WHERE Key_name = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$index]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // Función para verificar si una tabla existe
    function tableExists($pdo, $table) {
        try {
            $query = "SHOW TABLES LIKE ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$table]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    echo "<h3>Paso 1: Actualizando tabla usuarios</h3>";
    
    // Agregar campos a la tabla usuarios si no existen
    $fieldsToAdd = [
        'nivel' => "ALTER TABLE usuarios ADD COLUMN nivel ENUM('root', 'admin', 'user') NOT NULL DEFAULT 'user'",
        'padre_id' => "ALTER TABLE usuarios ADD COLUMN padre_id INT NULL",
        'especialidad' => "ALTER TABLE usuarios ADD COLUMN especialidad VARCHAR(100) NULL",
        'permisos' => "ALTER TABLE usuarios ADD COLUMN permisos JSON NULL",
        'ultimo_acceso' => "ALTER TABLE usuarios ADD COLUMN ultimo_acceso DATETIME NULL",
        'created_at' => "ALTER TABLE usuarios ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE usuarios ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];
    
    foreach ($fieldsToAdd as $field => $query) {
        if (!columnExists($pdo, 'usuarios', $field)) {
            try {
                $pdo->exec($query);
                echo "<div style='color: green;'>✓ Campo '{$field}' agregado</div>";
            } catch (PDOException $e) {
                echo "<div style='color: orange;'>⚠ Error agregando campo '{$field}': " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            echo "<div style='color: blue;'>ℹ Campo '{$field}' ya existe</div>";
        }
    }
    
    echo "<h3>Paso 2: Creando índices</h3>";
    
    // Agregar índices si no existen
    $indexesToAdd = [
        'idx_nivel' => "ALTER TABLE usuarios ADD INDEX idx_nivel (nivel)",
        'idx_padre_id' => "ALTER TABLE usuarios ADD INDEX idx_padre_id (padre_id)",
        'idx_activo' => "ALTER TABLE usuarios ADD INDEX idx_activo (activo)"
    ];
    
    foreach ($indexesToAdd as $index => $query) {
        if (!indexExists($pdo, 'usuarios', $index)) {
            try {
                $pdo->exec($query);
                echo "<div style='color: green;'>✓ Índice '{$index}' creado</div>";
            } catch (PDOException $e) {
                echo "<div style='color: orange;'>⚠ Error creando índice '{$index}': " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            echo "<div style='color: blue;'>ℹ Índice '{$index}' ya existe</div>";
        }
    }
    
    echo "<h3>Paso 3: Creando tablas del sistema</h3>";
    
    // Crear tabla de permisos
    if (!tableExists($pdo, 'system_permissions')) {
        try {
            $query = "CREATE TABLE system_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                permission_key VARCHAR(100) UNIQUE NOT NULL,
                permission_name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                category VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_category (category),
                INDEX idx_permission_key (permission_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($query);
            echo "<div style='color: green;'>✓ Tabla 'system_permissions' creada</div>";
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error creando tabla 'system_permissions': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div style='color: blue;'>ℹ Tabla 'system_permissions' ya existe</div>";
    }
    
    // Crear tabla de auditoría
    if (!tableExists($pdo, 'user_audit_logs')) {
        try {
            $query = "CREATE TABLE user_audit_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($query);
            echo "<div style='color: green;'>✓ Tabla 'user_audit_logs' creada</div>";
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error creando tabla 'user_audit_logs': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div style='color: blue;'>ℹ Tabla 'user_audit_logs' ya existe</div>";
    }
    
    // Crear tabla de sesiones
    if (!tableExists($pdo, 'user_sessions')) {
        try {
            $query = "CREATE TABLE user_sessions (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($query);
            echo "<div style='color: green;'>✓ Tabla 'user_sessions' creada</div>";
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error creando tabla 'user_sessions': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div style='color: blue;'>ℹ Tabla 'user_sessions' ya existe</div>";
    }
    
    echo "<h3>Paso 4: Insertando permisos del sistema</h3>";
    
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
            $query = "INSERT IGNORE INTO system_permissions (permission_key, permission_name, description, category) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($query);
            $stmt->execute($permission);
            if ($stmt->rowCount() > 0) {
                $permissionsInserted++;
            }
        } catch (PDOException $e) {
            // Ignorar si ya existe
        }
    }
    
    echo "<div style='color: green;'>✓ Permisos insertados: {$permissionsInserted}</div>";
    
    echo "<h3>Paso 5: Creando usuario ROOT</h3>";
    
    // Verificar si ya existe un usuario ROOT
    try {
        $query = "SELECT id FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $existingRoot = $stmt->fetch();
        
        if (!$existingRoot) {
            // Crear usuario ROOT
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
            
        } else {
            echo "<div style='color: blue;'>ℹ Usuario ROOT ya existe</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error creando usuario ROOT: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    echo "<h3>Paso 6: Actualizando usuarios existentes</h3>";
    
    // Actualizar usuarios existentes que no tienen nivel definido
    try {
        $query = "UPDATE usuarios SET nivel = 'user', permisos = '[\"dashboard\", \"informes\", \"grabacion\"]' WHERE nivel IS NULL OR nivel = ''";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $updated = $stmt->rowCount();
        
        if ($updated > 0) {
            echo "<div style='color: green;'>✓ Usuarios existentes actualizados: {$updated}</div>";
        } else {
            echo "<div style='color: blue;'>ℹ No hay usuarios existentes para actualizar</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: orange;'>⚠ No se pudieron actualizar usuarios existentes: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    echo "<h3>Paso 7: Verificación final</h3>";
    
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
        
        $query = "SELECT COUNT(*) as total FROM usuarios WHERE nivel = 'root' AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $rootUsers = $stmt->fetch()['total'];
        
        echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>Verificación de Instalación:</h4>";
        echo "<p><strong>Total usuarios:</strong> {$totalUsers}</p>";
        echo "<p><strong>Total permisos:</strong> {$totalPermissions}</p>";
        echo "<p><strong>Usuarios ROOT:</strong> {$rootUsers}</p>";
        echo "</div>";
        
        if ($rootUsers > 0 && $totalPermissions > 0) {
            echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
            echo "<h4 style='color: #155724;'>¡Instalación Completada Exitosamente!</h4>";
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
        } else {
            echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
            echo "<h4 style='color: #721c24;'>⚠ Problemas en la Instalación</h4>";
            echo "<p style='color: #721c24;'>Algunos componentes no se instalaron correctamente. Revisa los errores arriba.</p>";
            echo "</div>";
        }
        
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error en verificación final: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
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


