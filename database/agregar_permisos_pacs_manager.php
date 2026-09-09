<?php
/**
 * Script para agregar los permisos de PACS Manager
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "🔄 Agregando permisos de PACS Manager...\n\n";
    
    // Permisos de PACS Manager
    $permisosPacsManager = [
        [
            'permission_key' => 'pacs_manager',
            'permission_name' => 'Gestión PACS',
            'description' => 'Permite editar y eliminar estudios en el servidor PACS (Orthanc)',
            'category' => 'admin'
        ],
        [
            'permission_key' => 'gui_pacs_manager',
            'permission_name' => 'PACS Manager Visible',
            'description' => 'Controla la visibilidad y estado activo del acceso PACS Manager en el sidebar',
            'category' => 'interfaz'
        ]
    ];
    
    $agregados = 0;
    $actualizados = 0;
    
    foreach ($permisosPacsManager as $permiso) {
        // Verificar si existe
        $checkQuery = "SELECT permission_key, category FROM system_permissions WHERE permission_key = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$permiso['permission_key']]);
        $existe = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existe) {
            // Actualizar si cambió la categoría o descripción
            $updateQuery = "UPDATE system_permissions 
                            SET permission_name = ?,
                                description = ?,
                                category = ?
                            WHERE permission_key = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([
                $permiso['permission_name'],
                $permiso['description'],
                $permiso['category'],
                $permiso['permission_key']
            ]);
            if ($updateStmt->rowCount() > 0) {
                echo "   🔄 Actualizado: {$permiso['permission_name']} ({$permiso['category']})\n";
                $actualizados++;
            } else {
                echo "   ✅ Ya existe: {$permiso['permission_name']}\n";
            }
        } else {
            // Insertar nuevo
            $insertQuery = "INSERT INTO system_permissions (permission_key, permission_name, description, category) 
                           VALUES (?, ?, ?, ?)";
            $insertStmt = $pdo->prepare($insertQuery);
            $insertStmt->execute([
                $permiso['permission_key'],
                $permiso['permission_name'],
                $permiso['description'],
                $permiso['category']
            ]);
            echo "   ➕ Agregado: {$permiso['permission_name']} ({$permiso['category']})\n";
            $agregados++;
        }
    }
    
    echo "\n✅ Proceso completado:\n";
    echo "   • Agregados: {$agregados}\n";
    echo "   • Actualizados: {$actualizados}\n\n";
    
    // Verificar resultado final
    echo "📋 Permisos de PACS Manager:\n";
    $query = "SELECT permission_key, permission_name, category, description
              FROM system_permissions 
              WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager')
              ORDER BY category, permission_key";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        $categoriaDisplay = $perm['category'] === 'admin' ? 'Administración' : 'Interfaz/GUI';
        echo "   • {$perm['permission_name']} ({$perm['permission_key']}) - {$categoriaDisplay}\n";
        echo "     {$perm['description']}\n\n";
    }
    
    echo "✅ Permisos de PACS Manager agregados correctamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
