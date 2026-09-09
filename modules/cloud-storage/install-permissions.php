<?php
/**
 * Script de instalación de permisos para Cloud Storage
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Instalación de Permisos - Cloud Storage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Instalación de Permisos - Cloud Storage</h1>
        
<?php

$errors = [];
$success = [];

try {
    // Cargar configuración de base de datos
    require_once __DIR__ . '/../../config/database.php';
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    $success[] = "Conexión a BD establecida.";
    
    // Verificar si los permisos ya existen
    $checkStmt = $db->prepare("SELECT * FROM system_permissions WHERE permission_key IN ('cloud_storage', 'gui_cloud_storage')");
    $checkStmt->execute();
    $existing = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($existing)) {
        $success[] = "Los permisos ya existen en la base de datos:";
        foreach ($existing as $perm) {
            $success[] = "  - {$perm['permission_key']}: {$perm['permission_name']}";
        }
    } else {
        // Insertar permisos
        $permissions = [
            [
                'permission_key' => 'cloud_storage',
                'permission_name' => 'Cloud Storage',
                'description' => 'Permite acceder y gestionar el almacenamiento en Cloudflare R2',
                'category' => 'pacs'
            ],
            [
                'permission_key' => 'gui_cloud_storage',
                'permission_name' => 'Cloud Storage (GUI)',
                'description' => 'Muestra el enlace de Cloud Storage en el sidebar',
                'category' => 'gui'
            ]
        ];
        
        $insertStmt = $db->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category)
            VALUES (:permission_key, :permission_name, :description, :category)
            ON DUPLICATE KEY UPDATE
                permission_name = VALUES(permission_name),
                description = VALUES(description),
                category = VALUES(category)
        ");
        
        foreach ($permissions as $perm) {
            $insertStmt->execute($perm);
            $success[] = "Permiso agregado: {$perm['permission_key']} - {$perm['permission_name']}";
        }
    }
    
    // Verificar que se insertaron correctamente
    $finalCheck = $db->prepare("SELECT * FROM system_permissions WHERE permission_key IN ('cloud_storage', 'gui_cloud_storage') ORDER BY permission_key");
    $finalCheck->execute();
    $final = $finalCheck->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($final) >= 2) {
        $success[] = "✅ Permisos instalados correctamente.";
    } else {
        $errors[] = "⚠️ No se pudieron verificar todos los permisos.";
    }
    
} catch (Exception $e) {
    $errors[] = "Error: " . $e->getMessage();
}

// Mostrar resultados
echo "<h2>📊 Resultado</h2>";

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
}

?>

        <hr>
        <h3>📝 Información</h3>
        <ul>
            <li>Se han creado dos permisos:
                <ul>
                    <li><code>cloud_storage</code> - Permiso funcional para acceder a Cloud Storage</li>
                    <li><code>gui_cloud_storage</code> - Permiso GUI para mostrar el enlace en el sidebar</li>
                </ul>
            </li>
            <li>El enlace "Cloud Storage" aparecerá en el sidebar cuando el usuario tenga el permiso <code>gui_cloud_storage</code></li>
            <li>Para acceder a Cloud Storage, el usuario necesita el permiso <code>cloud_storage</code> o <code>gui_cloud_storage</code></li>
            <li>Los permisos se pueden asignar desde <a href="../../user-management.html">Gestión de Usuarios</a></li>
        </ul>
        
        <h3>🔧 Próximos Pasos</h3>
        <ol>
            <li>Asignar el permiso <code>gui_cloud_storage</code> a los usuarios que necesiten ver el enlace en el sidebar</li>
            <li>Asignar el permiso <code>cloud_storage</code> a los usuarios que necesiten acceder a Cloud Storage</li>
            <li>Verificar que el permiso funciona accediendo a <a href="../../cloud-storage.html">Cloud Storage</a></li>
        </ol>
    </div>
</body>
</html>
