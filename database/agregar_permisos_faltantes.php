<?php
/**
 * Script para agregar los permisos faltantes:
 * - antecedentes (categoría: estudios)
 * - plantillas_globales (categoría: plantillas)
 * - ver_todas_plantillas (categoría: plantillas)
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "🔄 Agregando permisos faltantes...\n\n";
    
    // Permisos faltantes
    $permisosFaltantes = [
        [
            'permission_key' => 'antecedentes',
            'permission_name' => 'Antecedentes',
            'description' => 'Permite gestionar antecedentes de pacientes',
            'category' => 'estudios'
        ],
        [
            'permission_key' => 'plantillas_globales',
            'permission_name' => 'Plantillas Globales',
            'description' => 'Permite crear plantillas globales del sistema que todos los usuarios pueden usar',
            'category' => 'plantillas'
        ],
        [
            'permission_key' => 'ver_todas_plantillas',
            'permission_name' => 'Ver Todas',
            'description' => 'Permite ver las plantillas de cualquier usuario en el sistema',
            'category' => 'plantillas'
        ]
    ];
    
    $agregados = 0;
    $actualizados = 0;
    
    foreach ($permisosFaltantes as $permiso) {
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
    echo "📋 Permisos agregados:\n";
    $query = "SELECT permission_key, permission_name, category, description
              FROM system_permissions 
              WHERE permission_key IN ('antecedentes', 'plantillas_globales', 'ver_todas_plantillas')
              ORDER BY category, permission_key";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        $categoriaDisplay = $perm['category'] === 'estudios' ? 'Estudios' : 'Plantillas';
        echo "   • {$perm['permission_name']} ({$perm['permission_key']}) - {$categoriaDisplay}\n";
        echo "     {$perm['description']}\n\n";
    }
    
    echo "✅ Permisos faltantes agregados correctamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
