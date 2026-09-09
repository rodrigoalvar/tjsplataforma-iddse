<?php
/**
 * Script para agregar los permisos de Worklist a la base de datos
 * Permisos: gui_worklist (GUI) y worklist (acceso)
 * Ejecutar una vez para agregar los permisos al sistema
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    echo "=== AGREGANDO PERMISOS DE WORKLIST ===\n\n";
    
    // Verificar si los permisos ya existen
    $checkStmt = $pdo->prepare("SELECT id, permission_key, permission_name FROM system_permissions WHERE permission_key IN ('gui_worklist', 'worklist')");
    $checkStmt->execute();
    $existing = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $existingKeys = array_column($existing, 'permission_key');
    
    // Agregar permiso GUI para mostrar/ocultar Worklist en el sidebar
    if (in_array('gui_worklist', $existingKeys)) {
        echo "✅ El permiso 'gui_worklist' ya existe:\n";
        $existingPerm = array_filter($existing, function($p) { return $p['permission_key'] === 'gui_worklist'; });
        $perm = reset($existingPerm);
        echo "   ID: {$perm['id']}\n";
        echo "   Clave: {$perm['permission_key']}\n";
        echo "   Nombre: {$perm['permission_name']}\n\n";
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category) 
            VALUES (?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            'gui_worklist',
            'Worklist Visible',
            'Controla la visibilidad y estado activo del acceso Worklist en el sidebar',
            'interfaz'
        ]);
        
        echo "✅ Permiso 'gui_worklist' agregado exitosamente.\n\n";
    }
    
    // Agregar permiso de acceso para usar la sección Worklist
    if (in_array('worklist', $existingKeys)) {
        echo "✅ El permiso 'worklist' ya existe:\n";
        $existingPerm = array_filter($existing, function($p) { return $p['permission_key'] === 'worklist'; });
        $perm = reset($existingPerm);
        echo "   ID: {$perm['id']}\n";
        echo "   Clave: {$perm['permission_key']}\n";
        echo "   Nombre: {$perm['permission_name']}\n\n";
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category) 
            VALUES (?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            'worklist',
            'Acceso a Worklist',
            'Permite acceder y usar la sección de Worklist',
            'estudios'
        ]);
        
        echo "✅ Permiso 'worklist' agregado exitosamente.\n\n";
    }
    
    // Mostrar todos los permisos de Worklist
    echo "=== PERMISOS DE WORKLIST EN EL SISTEMA ===\n";
    $listStmt = $pdo->prepare("
        SELECT id, permission_key, permission_name, description, category 
        FROM system_permissions 
        WHERE permission_key IN ('gui_worklist', 'worklist')
        ORDER BY permission_key
    ");
    $listStmt->execute();
    $permissions = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($permissions)) {
        echo "⚠️ No se encontraron permisos de Worklist.\n";
    } else {
        foreach ($permissions as $perm) {
            echo "   ID: {$perm['id']}\n";
            echo "   Clave: {$perm['permission_key']}\n";
            echo "   Nombre: {$perm['permission_name']}\n";
            echo "   Descripción: {$perm['description']}\n";
            echo "   Categoría: {$perm['category']}\n";
            echo "   ---\n";
        }
    }
    
    echo "\n✅ Proceso completado exitosamente.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
