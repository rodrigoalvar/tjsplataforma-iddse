<?php
/**
 * Script para corregir la categoría del permiso "Enviar a PACS"
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "🔄 Actualizando permiso 'enviar_pacs' a la categoría 'informes'...\n\n";
    
    // Actualizar el permiso si existe
    $updateQuery = "UPDATE system_permissions 
                    SET category = 'informes',
                        permission_name = 'Enviar a PACS',
                        description = 'Permite enviar informes médicos a PACS'
                    WHERE permission_key = 'enviar_pacs'";
    
    $stmt = $pdo->prepare($updateQuery);
    $stmt->execute();
    $updated = $stmt->rowCount();
    
    if ($updated > 0) {
        echo "✅ Permiso actualizado: {$updated} fila(s) modificada(s)\n\n";
    } else {
        echo "⚠️ No se encontró el permiso para actualizar, creándolo...\n\n";
    }
    
    // Si el permiso no existe, crearlo
    $insertQuery = "INSERT INTO system_permissions (permission_key, permission_name, description, category) 
                    VALUES 
                    ('enviar_pacs', 'Enviar a PACS', 'Permite enviar informes médicos a PACS', 'informes')
                    ON DUPLICATE KEY UPDATE 
                        permission_name = VALUES(permission_name),
                        description = VALUES(description),
                        category = VALUES(category)";
    
    $stmt = $pdo->prepare($insertQuery);
    $stmt->execute();
    
    echo "✅ Permiso creado/actualizado correctamente\n\n";
    
    // Verificar que el permiso esté en la categoría correcta
    echo "🔍 Verificando permiso 'enviar_pacs':\n";
    $checkQuery = "SELECT permission_key, permission_name, category, description
                   FROM system_permissions 
                   WHERE permission_key = 'enviar_pacs'";
    
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute();
    $permiso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($permiso) {
        echo "   🔑 Clave: {$permiso['permission_key']}\n";
        echo "   📝 Nombre: {$permiso['permission_name']}\n";
        echo "   📂 Categoría: {$permiso['category']}\n";
        echo "   📄 Descripción: {$permiso['description']}\n\n";
        
        if ($permiso['category'] === 'informes') {
            echo "✅ ¡Éxito! El permiso está en la categoría correcta 'informes'\n\n";
        } else {
            echo "❌ Error: El permiso está en la categoría '{$permiso['category']}' en lugar de 'informes'\n\n";
        }
    } else {
        echo "❌ Error: No se encontró el permiso 'enviar_pacs'\n\n";
    }
    
    // Mostrar todos los permisos de la categoría "informes"
    echo "📋 Permisos en la categoría 'informes':\n";
    $listQuery = "SELECT permission_key, permission_name, category, description
                  FROM system_permissions 
                  WHERE category = 'informes'
                  ORDER BY permission_name";
    
    $stmt = $pdo->prepare($listQuery);
    $stmt->execute();
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    
    echo "\n✅ Script ejecutado correctamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
