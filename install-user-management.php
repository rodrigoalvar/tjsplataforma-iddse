<?php
/**
 * Script de Instalación del Sistema de Gestión de Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script configura automáticamente la base de datos y crea el usuario ROOT inicial
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

echo "<h1>Instalación del Sistema de Gestión de Usuarios</h1>";
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
    
    // Leer y ejecutar el script SQL simplificado
    $sqlFile = __DIR__ . '/database/user_management_system_simple.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Dividir el SQL en statements individuales, manejando mejor los delimitadores
    $statements = [];
    $currentStatement = '';
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Saltar comentarios y líneas vacías
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // Si la línea termina con punto y coma, es el final de un statement
        if (substr(rtrim($line), -1) === ';') {
            $statements[] = trim($currentStatement);
            $currentStatement = '';
        }
    }
    
    // Agregar el último statement si no terminó con punto y coma
    if (!empty(trim($currentStatement))) {
        $statements[] = trim($currentStatement);
    }
    
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement)) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            // Ignorar errores comunes que no son críticos
            $errorMessage = $e->getMessage();
            $ignoreErrors = [
                'already exists',
                'Duplicate entry',
                'Duplicate key',
                'Table already exists',
                'Index already exists',
                'Constraint already exists'
            ];
            
            $shouldIgnore = false;
            foreach ($ignoreErrors as $ignoreError) {
                if (strpos($errorMessage, $ignoreError) !== false) {
                    $shouldIgnore = true;
                    break;
                }
            }
            
            if (!$shouldIgnore) {
                echo "<div style='color: orange;'>⚠ Advertencia: " . htmlspecialchars($errorMessage) . "</div>";
                $errors++;
            }
        }
    }
    
    echo "<div style='color: green;'>✓ Script SQL ejecutado: {$executed} statements ejecutados</div>";
    
    if ($errors > 0) {
        echo "<div style='color: orange;'>⚠ {$errors} advertencias durante la ejecución</div>";
    }
    
    // Verificar que el usuario ROOT se creó correctamente
    $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE nivel = 'root' AND activo = 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $rootUser = $stmt->fetch();
    
    if ($rootUser) {
        echo "<div style='color: green;'>✓ Usuario ROOT creado exitosamente</div>";
        echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>Credenciales del Usuario ROOT:</h4>";
        echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
        echo "<p><strong>Contraseña:</strong> {$config['root_user']['password']}</p>";
        echo "<p><strong>Nivel:</strong> {$rootUser['nivel']}</p>";
        echo "<p style='color: red;'><strong>IMPORTANTE:</strong> Cambia la contraseña después del primer login por seguridad.</p>";
        echo "</div>";
    } else {
        echo "<div style='color: red;'>✗ Error: Usuario ROOT no se creó correctamente</div>";
    }
    
    // Verificar permisos del sistema
    $query = "SELECT COUNT(*) as count FROM system_permissions";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permissionsCount = $stmt->fetch()['count'];
    
    echo "<div style='color: green;'>✓ Permisos del sistema creados: {$permissionsCount} permisos</div>";
    
    // Verificar tablas creadas
    $tables = ['usuarios', 'system_permissions', 'user_audit_logs', 'user_sessions'];
    foreach ($tables as $table) {
        try {
            $query = "SHOW TABLES LIKE ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$table]);
            $result = $stmt->fetchAll();
            
            if (count($result) > 0) {
                echo "<div style='color: green;'>✓ Tabla '{$table}' creada correctamente</div>";
            } else {
                echo "<div style='color: red;'>✗ Error: Tabla '{$table}' no se creó</div>";
            }
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error verificando tabla '{$table}': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Mostrar estadísticas finales
    try {
        $query = "SELECT nivel, COUNT(*) as count FROM usuarios WHERE activo = 1 GROUP BY nivel";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $stats = $stmt->fetchAll();
        
        echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>Estadísticas de Usuarios:</h4>";
        foreach ($stats as $stat) {
            echo "<p><strong>{$stat['nivel']}:</strong> {$stat['count']} usuarios</p>";
        }
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
    echo "<div style='color: red;'>✗ Error durante la instalación: " . $e->getMessage() . "</div>";
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
