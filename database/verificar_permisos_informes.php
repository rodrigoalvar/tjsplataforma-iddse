<?php
/**
 * Script para verificar los permisos de la categoría "informes"
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "🔍 Verificando permisos en la categoría 'informes':\n\n";
    
    // Ver todos los permisos de informes
    $query = "SELECT permission_key, permission_name, category, description
              FROM system_permissions 
              WHERE category = 'informes'
              ORDER BY permission_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 Permisos encontrados en la categoría 'informes': " . count($permisos) . "\n\n";
    
    foreach ($permisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
        echo "     Descripción: {$perm['description']}\n\n";
    }
    
    // Verificar qué permisos deberían estar según el código
    echo "\n📋 Permisos que DEBERÍAN estar en 'informes' según permissions-simple.php:\n";
    $permisosEsperados = [
        'informes' => 'Creación de Informes',
        'gestionInformes' => 'Gestión de Informes',
        'verTodosInformes' => 'Ver Todos',
        'enviar_pacs' => 'Enviar a PACS',
        'marcar_incompletos' => 'Marcar Incompletos'
    ];
    
    foreach ($permisosEsperados as $key => $name) {
        $existe = false;
        foreach ($permisos as $perm) {
            if ($perm['permission_key'] === $key) {
                $existe = true;
                break;
            }
        }
        $status = $existe ? '✅' : '❌';
        echo "   {$status} {$name} ({$key})\n";
    }
    
    // Buscar permisos que podrían estar en otra categoría
    echo "\n🔍 Buscando permisos relacionados con informes en otras categorías:\n";
    $query2 = "SELECT permission_key, permission_name, category, description
               FROM system_permissions 
               WHERE permission_key IN ('informes', 'gestionInformes', 'verTodosInformes', 'marcar_incompletos')
               ORDER BY category, permission_key";
    
    $stmt2 = $pdo->prepare($query2);
    $stmt2->execute();
    $otrosPermisos = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($otrosPermisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']}) - Categoría: {$perm['category']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
