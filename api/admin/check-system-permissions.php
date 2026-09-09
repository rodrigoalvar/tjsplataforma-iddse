<?php
/**
 * Script para verificar la estructura de la tabla system_permissions
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

try {
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Verificar la estructura de la tabla
    $describeQuery = "DESCRIBE system_permissions";
    $describeStmt = $pdo->prepare($describeQuery);
    $describeStmt->execute();
    $columns = $describeStmt->fetchAll();
    
    echo "Estructura de la tabla system_permissions:\n";
    foreach ($columns as $column) {
        echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    // Verificar si ya existe el permiso
    $checkQuery = "SELECT COUNT(*) as count FROM system_permissions WHERE permission_key = 'verTodosInformes'";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute();
    $exists = $checkStmt->fetch()['count'] > 0;
    
    echo "\n¿Existe el permiso 'verTodosInformes'? " . ($exists ? "SÍ" : "NO") . "\n";
    
    // Mostrar permisos existentes de la categoría informes
    $informesQuery = "SELECT permission_key, permission_name, description FROM system_permissions WHERE category = 'informes'";
    $informesStmt = $pdo->prepare($informesQuery);
    $informesStmt->execute();
    $informesPermissions = $informesStmt->fetchAll();
    
    echo "\nPermisos existentes en la categoría 'informes':\n";
    foreach ($informesPermissions as $perm) {
        echo "- " . $perm['permission_key'] . ": " . $perm['permission_name'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
