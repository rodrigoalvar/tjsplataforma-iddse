<?php
/**
 * Script de Instalación del Módulo PACS Manager
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script configura automáticamente los permisos necesarios para el módulo
 */

// Configuración
$config = [
    'database' => [
        'host' => 'localhost',
        'dbname' => 'tjsmedical',
        'username' => 'root',
        'password' => ''
    ]
];

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Instalación PACS Manager</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        .success {
            color: #27ae60;
            background: #d5f4e6;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .error {
            color: #e74c3c;
            background: #fadbd8;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .warning {
            color: #f39c12;
            background: #fef5e7;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .info {
            background: #ebf5fb;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #3498db;
        }
        code {
            background: #ecf0f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        ul {
            line-height: 1.8;
        }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>Instalación del Módulo PACS Manager</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

try {
    // Conectar a la base de datos
    $dsn = "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['database']['username'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
    ]);
    
    echo "<div class='success'>✓ Conexión a la base de datos exitosa</div>";
    
    // Leer y ejecutar el script SQL
    $sqlFile = __DIR__ . '/database/pacs_manager_install.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Dividir el SQL en statements individuales
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
                echo "<div class='warning'>⚠ Advertencia: " . htmlspecialchars($errorMessage) . "</div>";
                $errors++;
            }
        }
    }
    
    echo "<div class='success'>✓ Script SQL ejecutado: {$executed} statements ejecutados</div>";
    
    if ($errors > 0) {
        echo "<div class='warning'>⚠ {$errors} advertencias durante la ejecución</div>";
    }
    
    // Verificar que los permisos se crearon correctamente
    $query = "SELECT permission_key, permission_name, description, category 
              FROM system_permissions 
              WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager')
              ORDER BY permission_key";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permissions = $stmt->fetchAll();
    
    if (count($permissions) >= 2) {
        echo "<div class='success'>✓ Permisos del sistema creados correctamente</div>";
        echo "<div class='info'>";
        echo "<h4>Permisos instalados:</h4>";
        echo "<ul>";
        foreach ($permissions as $perm) {
            echo "<li><strong>{$perm['permission_key']}</strong>: {$perm['permission_name']}</li>";
        }
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div class='error'>✗ Error: No se pudieron crear todos los permisos</div>";
    }
    
    // Verificar archivos del módulo
    $requiredFiles = [
        'pacs-manager.html' => 'Interfaz principal',
        'assets/js/pacs-manager.js' => 'Lógica JavaScript',
        'api/pacs-manager/list.php' => 'API listar estudios',
        'api/pacs-manager/edit.php' => 'API editar estudios',
        'api/pacs-manager/delete.php' => 'API eliminar estudios'
    ];
    
    $filesOk = true;
    echo "<div class='info'>";
    echo "<h4>Verificación de archivos:</h4>";
    echo "<ul>";
    foreach ($requiredFiles as $file => $description) {
        $filePath = __DIR__ . '/' . $file;
        if (file_exists($filePath)) {
            echo "<li class='success'>✓ {$description} ({$file})</li>";
        } else {
            echo "<li class='error'>✗ {$description} ({$file}) - NO ENCONTRADO</li>";
            $filesOk = false;
        }
    }
    echo "</ul>";
    echo "</div>";
    
    if ($filesOk) {
        echo "<div class='success' style='margin-top: 20px;'>";
        echo "<h4 style='color: #27ae60; margin-top: 0;'>¡Instalación Completada!</h4>";
        echo "<p>El módulo PACS Manager está listo para usar.</p>";
        echo "<p><strong>Próximos pasos:</strong></p>";
        echo "<ul>";
        echo "<li>Asigna el permiso <code>pacs_manager</code> a los usuarios que necesiten gestionar estudios PACS</li>";
        echo "<li>Asigna el permiso <code>gui_pacs_manager</code> para que aparezca en el sidebar</li>";
        echo "<li>Accede a <code>pacs-manager.html</code> para gestionar estudios</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div class='warning' style='margin-top: 20px;'>";
        echo "<h4 style='color: #f39c12; margin-top: 0;'>Instalación Parcial</h4>";
        echo "<p>Los permisos se instalaron correctamente, pero faltan algunos archivos del módulo.</p>";
        echo "<p>Por favor, asegúrate de que todos los archivos estén presentes.</p>";
        echo "</div>";
    }
    
    echo "<div class='info' style='margin-top: 20px;'>";
    echo "<h4>Seguridad:</h4>";
    echo "<ul>";
    echo "<li>Elimina este archivo de instalación después de completar la configuración</li>";
    echo "<li>El módulo requiere permisos específicos - solo usuarios con <code>pacs_manager</code> pueden usarlo</li>";
    echo "<li>Las operaciones de edición/eliminación son permanentes - usa con precaución</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>✗ Error durante la instalación: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='info'>";
    echo "<h4>Soluciones Comunes:</h4>";
    echo "<ul>";
    echo "<li>Verifica que la base de datos '{$config['database']['dbname']}' existe</li>";
    echo "<li>Verifica las credenciales de la base de datos</li>";
    echo "<li>Asegúrate de que el usuario de MySQL tiene permisos para modificar tablas</li>";
    echo "<li>Verifica que el archivo SQL existe en la ruta correcta</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>Instalación completada el " . date('Y-m-d H:i:s') . "</small></p>";
echo "</div></body></html>";
?>



