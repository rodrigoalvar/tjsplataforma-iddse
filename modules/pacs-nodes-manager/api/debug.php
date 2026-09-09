<?php
/**
 * Script de depuración para identificar problemas
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: text/plain');

echo "=== DEBUG PACS NODES MANAGER ===\n\n";

// 1. Verificar rutas
echo "1. Verificando rutas de archivos...\n";
echo "   __DIR__: " . __DIR__ . "\n";
echo "   _auth.php: " . (file_exists(__DIR__ . '/_auth.php') ? '✓ Existe' : '✗ No existe') . "\n";
echo "   database.php: " . (file_exists(__DIR__ . '/../../../config/database.php') ? '✓ Existe' : '✗ No existe') . "\n";
echo "   PacsNodeConfig.php: " . (file_exists(__DIR__ . '/../../PacsNodeConfig.php') ? '✓ Existe' : '✗ No existe') . "\n\n";

// 2. Cargar archivos
echo "2. Cargando archivos...\n";
try {
    require_once __DIR__ . '/_auth.php';
    echo "   ✓ _auth.php cargado\n";
} catch (Exception $e) {
    echo "   ✗ Error cargando _auth.php: " . $e->getMessage() . "\n";
    exit;
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    echo "   ✓ database.php cargado\n";
} catch (Exception $e) {
    echo "   ✗ Error cargando database.php: " . $e->getMessage() . "\n";
    exit;
}

try {
    require_once __DIR__ . '/../PacsNodeConfig.php';
    echo "   ✓ PacsNodeConfig.php cargado\n";
} catch (Exception $e) {
    echo "   ✗ Error cargando PacsNodeConfig.php: " . $e->getMessage() . "\n";
    exit;
}

// 3. Verificar conexión BD
echo "\n3. Verificando conexión a BD...\n";
try {
    $db = getDBConnection();
    if ($db) {
        echo "   ✓ Conexión exitosa\n";
        
        // Verificar tablas
        $tables = ['pacs_nodes', 'pacs_node_jobs', 'pacs_node_queries', 'pacs_node_statistics'];
        foreach ($tables as $table) {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->rowCount() > 0;
            echo "   " . ($exists ? '✓' : '✗') . " Tabla $table: " . ($exists ? 'Existe' : 'NO EXISTE') . "\n";
        }
    } else {
        echo "   ✗ No se pudo conectar\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 4. Verificar autenticación (simulada)
echo "\n4. Verificando función de autenticación...\n";
if (function_exists('requirePacsNodesAuth')) {
    echo "   ✓ Función requirePacsNodesAuth existe\n";
} else {
    echo "   ✗ Función requirePacsNodesAuth NO existe\n";
}

if (function_exists('sendErrorResponse')) {
    echo "   ✓ Función sendErrorResponse existe\n";
} else {
    echo "   ✗ Función sendErrorResponse NO existe\n";
}

if (function_exists('sendSuccessResponse')) {
    echo "   ✓ Función sendSuccessResponse existe\n";
} else {
    echo "   ✗ Función sendSuccessResponse NO existe\n";
}

// 5. Verificar clases
echo "\n5. Verificando clases...\n";
if (class_exists('Database')) {
    echo "   ✓ Clase Database existe\n";
} else {
    echo "   ✗ Clase Database NO existe\n";
}

if (class_exists('PacsNodeConfig')) {
    echo "   ✓ Clase PacsNodeConfig existe\n";
} else {
    echo "   ✗ Clase PacsNodeConfig NO existe\n";
}

if (class_exists('User')) {
    echo "   ✓ Clase User existe\n";
} else {
    echo "   ✗ Clase User NO existe\n";
}

echo "\n=== FIN DEBUG ===\n";
