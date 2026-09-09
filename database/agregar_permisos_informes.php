<?php
/**
 * Script para agregar todos los permisos de la categoría "informes"
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "🔄 Agregando permisos de la categoría 'informes'...\n\n";
    
    // Lista de permisos que deben estar en la categoría "informes"
    $permisosInformes = [
        [
            'permission_key' => 'informes',
            'permission_name' => 'Creación de Informes',
            'description' => 'Permite crear informes médicos',
            'category' => 'informes'
        ],
        [
            'permission_key' => 'gestionInformes',
            'permission_name' => 'Gestión de Informes',
            'description' => 'Permite gestionar todos los informes del sistema',
            'category' => 'informes'
        ],
        [
            'permission_key' => 'verTodosInformes',
            'permission_name' => 'Ver Todos',
            'description' => 'Permite ver todos los informes del sistema',
            'category' => 'informes'
        ],
        [
            'permission_key' => 'enviar_pacs',
            'permission_name' => 'Enviar a PACS',
            'description' => 'Permite enviar informes médicos a PACS',
            'category' => 'informes'
        ],
        [
            'permission_key' => 'marcar_incompletos',
            'permission_name' => 'Marcar Incompletos',
            'description' => 'Permite marcar y desmarcar estudios como informes incompletos',
            'category' => 'informes'
        ]
    ];
    
    $agregados = 0;
    $actualizados = 0;
    
    foreach ($permisosInformes as $permiso) {
        // Verificar si existe
        $checkQuery = "SELECT permission_key, category FROM system_permissions WHERE permission_key = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$permiso['permission_key']]);
        $existe = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existe) {
            // Actualizar si está en otra categoría
            if ($existe['category'] !== $permiso['category']) {
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
                echo "   🔄 Actualizado: {$permiso['permission_name']} (categoría cambiada a 'informes')\n";
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
            echo "   ➕ Agregado: {$permiso['permission_name']}\n";
            $agregados++;
        }
    }
    
    echo "\n✅ Proceso completado:\n";
    echo "   • Agregados: {$agregados}\n";
    echo "   • Actualizados: {$actualizados}\n\n";
    
    // Verificar resultado final
    echo "📋 Permisos finales en la categoría 'informes':\n";
    $query = "SELECT permission_key, permission_name, category, description
              FROM system_permissions 
              WHERE category = 'informes'
              ORDER BY permission_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    
    echo "\n✅ Script ejecutado correctamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
