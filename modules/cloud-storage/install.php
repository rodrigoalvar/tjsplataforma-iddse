<?php
/**
 * Script de instalación del módulo Cloud Storage
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Habilitar reporte de errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar en producción, solo log
ini_set('log_errors', 1);

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Instalación - Cloud Storage Module</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>📦 Instalación del Módulo Cloud Storage</h1>
    
<?php

$errors = [];
$success = [];

try {
    // 1. Verificar dependencias
    echo "<h2>1. Verificando dependencias...</h2>";
    
    if (!extension_loaded('curl')) {
        $errors[] = "Extensión cURL no está instalada";
    } else {
        $success[] = "cURL está disponible";
    }
    
    if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
        $errors[] = "PDO MySQL no está instalado";
    } else {
        $success[] = "PDO MySQL está disponible";
    }
    
    // 2. Verificar AWS SDK
    echo "<h2>2. Verificando AWS SDK PHP...</h2>";
    
    $awsSdkPaths = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php'
    ];
    
    $awsSdkFound = false;
    foreach ($awsSdkPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            if (class_exists('Aws\S3\S3Client')) {
                $awsSdkFound = true;
                $success[] = "AWS SDK PHP encontrado en: " . $path;
                break;
            }
        }
    }
    
    if (!$awsSdkFound) {
        $errors[] = "AWS SDK PHP no encontrado. Ejecuta: <pre>cd " . __DIR__ . " && composer require aws/aws-sdk-php</pre>";
    }
    
    // Verificar .env
    echo "<h2>2.1. Verificando archivo .env...</h2>";
    $envFiles = [
        __DIR__ . '/.env' => 'Módulo (recomendado)',
        __DIR__ . '/../../.env' => 'Raíz del proyecto (fallback)'
    ];
    
    $envFound = false;
    foreach ($envFiles as $envPath => $location) {
        if (file_exists($envPath)) {
            $envFound = true;
            $success[] = "Archivo .env encontrado en: $location ($envPath)";
            break;
        }
    }
    
    if (!$envFound) {
        $errors[] = "Archivo .env no encontrado. Crea uno desde .env.example: <pre>cd " . __DIR__ . " && cp .env.example .env</pre>";
    }
    
    // 3. Crear tablas en BD
    echo "<h2>3. Creando tablas en base de datos...</h2>";
    
    $dbConfigPath = __DIR__ . '/../../config/database.php';
    if (!file_exists($dbConfigPath)) {
        throw new Exception("No se encontró database.php en: $dbConfigPath");
    }
    
    require_once $dbConfigPath;
    
    if (!class_exists('Database')) {
        throw new Exception("La clase Database no está disponible después de incluir database.php");
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        $errors[] = "No se pudo conectar a la base de datos";
    } else {
        $success[] = "Conexión a BD establecida";
        
        $sqlFile = __DIR__ . '/database/install.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            
            // Ejecutar sentencias SQL
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt) && !preg_match('/^--/', $stmt) && !preg_match('/^\/\*/', $stmt);
                }
            );
            
            $executed = 0;
            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    try {
                        $db->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'already exists') === false) {
                            $errors[] = "Error ejecutando SQL: " . $e->getMessage();
                        }
                    }
                }
            }
            
            $success[] = "Tablas creadas/verificadas ($executed sentencias ejecutadas)";
        } else {
            $errors[] = "Archivo SQL no encontrado: $sqlFile";
        }
    }
    
    // 4. Verificar configuración
    echo "<h2>4. Verificando configuración...</h2>";
    
    $configPath = __DIR__ . '/config/cloud_storage_config.php';
    if (!file_exists($configPath)) {
        throw new Exception("No se encontró cloud_storage_config.php en: $configPath");
    }
    
    require_once $configPath;
    
    if (!class_exists('CloudStorageConfig')) {
        throw new Exception("La clase CloudStorageConfig no está disponible después de incluir cloud_storage_config.php");
    }
    
    $config = CloudStorageConfig::load();
    
    if ($config['r2_enabled'] ?? false) {
        $success[] = "R2 está habilitado";
        
        if (empty($config['r2_account_id'])) {
            $errors[] = "R2_ACCOUNT_ID no está configurado";
        } else {
            $success[] = "R2_ACCOUNT_ID configurado";
        }
        
        if (empty($config['r2_access_key'])) {
            $errors[] = "R2_ACCESS_KEY no está configurado";
        } else {
            $success[] = "R2_ACCESS_KEY configurado";
        }
        
        if (empty($config['r2_secret_key'])) {
            $errors[] = "R2_SECRET_KEY no está configurado";
        } else {
            $success[] = "R2_SECRET_KEY configurado";
        }
        
        if (empty($config['r2_bucket_name'])) {
            $errors[] = "R2_BUCKET_NAME no está configurado";
        } else {
            $success[] = "R2_BUCKET_NAME: " . $config['r2_bucket_name'];
        }
    } else {
        $errors[] = "R2 no está habilitado. Configura R2_ENABLED=true en el archivo .env del módulo";
    }
    
    // Resumen
    echo "<h2>📊 Resumen</h2>";
    
    if (!empty($success)) {
        echo "<div class='success'><strong>Éxitos:</strong><ul>";
        foreach ($success as $msg) {
            echo "<li>$msg</li>";
        }
        echo "</ul></div>";
    }
    
    if (!empty($errors)) {
        echo "<div class='error'><strong>Errores:</strong><ul>";
        foreach ($errors as $msg) {
            echo "<li>$msg</li>";
        }
        echo "</ul></div>";
    } else {
        echo "<div class='success'><strong>✅ Instalación completada correctamente</strong></div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'><strong>Error fatal:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    error_log('[CLOUD_STORAGE_INSTALL] Error: ' . $e->getMessage());
    error_log('[CLOUD_STORAGE_INSTALL] Trace: ' . $e->getTraceAsString());
} catch (Error $e) {
    echo "<div class='error'><strong>Error fatal (PHP Error):</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    error_log('[CLOUD_STORAGE_INSTALL] PHP Error: ' . $e->getMessage());
    error_log('[CLOUD_STORAGE_INSTALL] Trace: ' . $e->getTraceAsString());
}

?>

    <hr>
    <h3>📝 Próximos pasos:</h3>
    <ol>
        <li>Crear archivo <code>.env</code> en la carpeta del módulo: <code>cp .env.example .env</code></li>
        <li>Configurar variables en <code>.env</code> con tus credenciales R2 (ver README.md)</li>
        <li>Instalar AWS SDK: <code>composer require aws/aws-sdk-php</code></li>
        <li>Configurar cron para el worker (opcional)</li>
        <li>Probar encolando un estudio: <code>POST /api/cloud-storage/enqueue</code></li>
    </ol>
</body>
</html>
