<?php
/**
 * Script de instalación para módulo Worklist
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Ejecuta el SQL para crear las tablas necesarias
 */

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <title>Instalación Worklist</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #667eea; }
        .success { color: #198754; padding: 10px; background: #d1e7dd; border-radius: 5px; margin: 10px 0; }
        .error { color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 5px; margin: 10px 0; }
        .info { color: #0dcaf0; padding: 10px; background: #cff4fc; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <h1><i class='fas fa-list-alt'></i> Instalación del Módulo Worklist</h1>
";

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    echo "<div class='info'>✓ Conexión a la base de datos establecida</div>";
    
    // Leer y ejecutar el SQL
    $sqlFile = __DIR__ . '/database/create_worklist_tables.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Dividir en sentencias individuales
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    $executed = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            // Ignorar errores de "ya existe" o "IF NOT EXISTS"
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate') === false) {
                $errors[] = $e->getMessage();
            }
        }
    }
    
    echo "<div class='success'>✓ Instalación completada</div>";
    echo "<div class='info'>Sentencias ejecutadas: $executed</div>";
    
    if (!empty($errors)) {
        echo "<div class='error'><strong>Advertencias:</strong><ul>";
        foreach ($errors as $error) {
            echo "<li>$error</li>";
        }
        echo "</ul></div>";
    }
    
    // Verificar que las tablas existen
    $tables = ['worklist', 'worklist_logs', 'worklist_config'];
    echo "<h2>Verificación de Tablas</h2>";
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "<div class='success'>✓ Tabla '$table' existe</div>";
        } else {
            echo "<div class='error'>✗ Tabla '$table' NO existe</div>";
        }
    }
    
    echo "<h2>Próximos Pasos</h2>";
    echo "<ol>
        <li>Configurar la ruta de Orthanc en <a href='configuracion.html'>Configuración → Worklist</a></li>
        <li>Acceder a <a href='worklist.html'>Worklist</a> para comenzar a usar el módulo</li>
        <li>Subir archivos TXT o crear worklist manualmente</li>
    </ol>";
    
} catch (Exception $e) {
    echo "<div class='error'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "
    </div>
</body>
</html>
";
