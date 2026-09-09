<?php
/**
 * Script para agregar los permisos de Gestión Mensajes a la base de datos
 * Permisos: gui_gestion_mensajes (GUI) y administracion_email (acceso)
 * Ejecutar una vez para agregar los permisos al sistema
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    echo "=== AGREGANDO PERMISOS DE GESTIÓN MENSAJES ===\n\n";
    
    // Verificar si los permisos ya existen
    $checkStmt = $pdo->prepare("SELECT id, permission_key, permission_name FROM system_permissions WHERE permission_key IN ('gui_gestion_mensajes', 'administracion_email')");
    $checkStmt->execute();
    $existing = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $existingKeys = array_column($existing, 'permission_key');
    
    // Agregar permiso GUI para mostrar/ocultar Gestión Mensajes en el sidebar
    if (in_array('gui_gestion_mensajes', $existingKeys)) {
        echo "✅ El permiso 'gui_gestion_mensajes' ya existe:\n";
        $existingPerm = array_filter($existing, function($p) { return $p['permission_key'] === 'gui_gestion_mensajes'; });
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
            'gui_gestion_mensajes',
            'Gestión Mensajes Visible',
            'Controla la visibilidad y estado activo del acceso Gestión Mensajes en el sidebar',
            'interfaz'
        ]);
        
        echo "✅ Permiso 'gui_gestion_mensajes' agregado exitosamente.\n\n";
    }
    
    // Agregar permiso de acceso para usar la sección Administración de Email
    if (in_array('administracion_email', $existingKeys)) {
        echo "✅ El permiso 'administracion_email' ya existe:\n";
        $existingPerm = array_filter($existing, function($p) { return $p['permission_key'] === 'administracion_email'; });
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
            'administracion_email',
            'Administración de Email',
            'Permite acceder y usar la sección de Administración de Email (Gestión Mensajes)',
            'admin'
        ]);
        
        echo "✅ Permiso 'administracion_email' agregado exitosamente.\n\n";
    }
    
    // Mostrar todos los permisos de Gestión Mensajes
    echo "=== PERMISOS DE GESTIÓN MENSAJES EN EL SISTEMA ===\n";
    $listStmt = $pdo->prepare("
        SELECT id, permission_key, permission_name, description, category 
        FROM system_permissions 
        WHERE permission_key IN ('gui_gestion_mensajes', 'administracion_email')
        ORDER BY permission_key
    ");
    $listStmt->execute();
    $permissions = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($permissions)) {
        echo "⚠️ No se encontraron permisos de Gestión Mensajes.\n";
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
