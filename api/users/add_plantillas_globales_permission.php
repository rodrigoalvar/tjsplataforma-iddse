<?php
/**
 * Script para agregar el permiso "Plantillas Globales" a la base de datos
 * Ejecutar una vez para agregar el permiso al sistema
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== AGREGANDO PERMISO 'Plantillas Globales' ===\n\n";
    
    // Verificar si el permiso ya existe
    $checkStmt = $pdo->prepare("SELECT id, permission_key, permission_name FROM system_permissions WHERE permission_key = 'plantillas_globales'");
    $checkStmt->execute();
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        echo "✅ El permiso 'plantillas_globales' ya existe:\n";
        echo "   ID: {$existing['id']}\n";
        echo "   Clave: {$existing['permission_key']}\n";
        echo "   Nombre: {$existing['permission_name']}\n\n";
    } else {
        // Insertar el nuevo permiso
        $insertStmt = $pdo->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category) 
            VALUES (?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            'plantillas_globales',
            'Plantillas Globales',
            'Permite crear plantillas globales del sistema que todos los usuarios pueden usar',
            'plantillas'
        ]);
        
        echo "✅ Permiso 'plantillas_globales' agregado exitosamente.\n\n";
    }
    
    // Mostrar todos los permisos de la categoría 'plantillas'
    echo "=== PERMISOS EN CATEGORÍA 'plantillas' ===\n";
    $listStmt = $pdo->prepare("
        SELECT id, permission_key, permission_name, description 
        FROM system_permissions 
        WHERE category = 'plantillas' 
        ORDER BY 
            CASE permission_key
                WHEN 'plantillas' THEN 1
                WHEN 'ver_todas_plantillas' THEN 2
                WHEN 'plantillas_globales' THEN 3
                ELSE 4
            END
    ");
    $listStmt->execute();
    $permissions = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permissions as $perm) {
        echo sprintf(
            "- %s (%s): %s\n",
            $perm['permission_name'],
            $perm['permission_key'],
            $perm['description'] ?? 'Sin descripción'
        );
    }
    
    echo "\n✅ Proceso completado.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>





