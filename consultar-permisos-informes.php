<?php
/**
 * Script para consultar permisos de informes
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== PERMISOS RELACIONADOS CON INFORMES ===\n\n";
    
    // Buscar permisos de informes
    $stmt = $pdo->query("SELECT permission_key, permission_name, description, category 
                         FROM system_permissions 
                         WHERE category = 'informes' OR permission_key LIKE '%informe%'
                         ORDER BY category, permission_name");
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 PERMISOS DE INFORMES EN EL SISTEMA:\n\n";
    foreach ($permisos as $perm) {
        echo "   🔑 Clave: {$perm['permission_key']}\n";
        echo "   📝 Nombre: {$perm['permission_name']}\n";
        echo "   📄 Descripción: {$perm['description']}\n";
        echo "   📂 Categoría: {$perm['category']}\n";
        echo "   " . str_repeat("-", 60) . "\n\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

