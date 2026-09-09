<?php
/**
 * Script para verificar y eliminar permiso duplicado "Dictado por Voz"
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== VERIFICANDO PERMISOS DUPLICADOS DE DICTADO ===\n\n";
    
    // Buscar permisos relacionados con dictado
    $stmt = $pdo->query("SELECT id, permission_key, permission_name, category 
                         FROM system_permissions 
                         WHERE permission_name LIKE '%Dictado%' OR permission_key LIKE '%dictado%' 
                         ORDER BY id");
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 PERMISOS ENCONTRADOS:\n";
    foreach ($permisos as $perm) {
        echo "   ID: {$perm['id']}, Key: {$perm['permission_key']}, Name: {$perm['permission_name']}, Category: {$perm['category']}\n";
    }
    
    if (count($permisos) > 1) {
        echo "\n⚠️ SE ENCONTRARON PERMISOS DUPLICADOS\n";
        
        // Eliminar el permiso con key 'dictado_voz' (mantener 'dictado')
        $stmt = $pdo->prepare("DELETE FROM system_permissions WHERE permission_key = 'dictado_voz'");
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            echo "✅ Permiso duplicado 'dictado_voz' eliminado\n";
        } else {
            echo "ℹ️ No se encontró 'dictado_voz' para eliminar\n";
        }
        
        // Verificar después de eliminar
        echo "\n📋 PERMISOS DESPUÉS DE LIMPIAR:\n";
        $stmt = $pdo->query("SELECT id, permission_key, permission_name, category 
                             FROM system_permissions 
                             WHERE permission_name LIKE '%Dictado%' OR permission_key LIKE '%dictado%' 
                             ORDER BY id");
        $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($permisos as $perm) {
            echo "   ID: {$perm['id']}, Key: {$perm['permission_key']}, Name: {$perm['permission_name']}\n";
        }
    } else {
        echo "\n✅ No hay duplicados.\n";
    }
    
    // Verificar todos los permisos de audio
    echo "\n📋 TODOS LOS PERMISOS DE AUDIO:\n";
    $stmt = $pdo->query("SELECT permission_key, permission_name FROM system_permissions WHERE category = 'audio' ORDER BY permission_key");
    $audioPermisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($audioPermisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    
    echo "\n✅ VERIFICACIÓN COMPLETADA\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

