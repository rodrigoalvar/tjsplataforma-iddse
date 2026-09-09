<?php
/**
 * Script de prueba directa para verificar permiso Antecedentes
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== VERIFICACIÓN DE PERMISO ANTECEDENTES ===\n\n";
    
    // Verificar que existe en la base de datos
    $stmt = $pdo->prepare("SELECT * FROM system_permissions WHERE permission_key = 'antecedentes'");
    $stmt->execute();
    $permiso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($permiso) {
        echo "✅ Antecedentes existe en la base de datos:\n";
        echo "   ID: {$permiso['id']}\n";
        echo "   Key: {$permiso['permission_key']}\n";
        echo "   Name: {$permiso['permission_name']}\n";
        echo "   Category: {$permiso['category']}\n";
        echo "   Description: {$permiso['description']}\n\n";
    } else {
        echo "❌ Antecedentes NO existe en la base de datos\n\n";
        exit(1);
    }
    
    // Simular cómo la API devuelve los datos
    $query = "SELECT permission_key, permission_name, description, category 
              FROM system_permissions 
              WHERE category = 'estudios'
              ORDER BY category, permission_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 PERMISOS EN CATEGORÍA 'ESTUDIOS':\n";
    $encontrado = false;
    
    foreach ($permissions as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
        if ($perm['permission_key'] === 'antecedentes') {
            echo "   ✅ ANTECEDENTES ENCONTRADO!\n";
            $encontrado = true;
        }
    }
    
    if (!$encontrado) {
        echo "\n❌ ERROR: Antecedentes no se encontró en la consulta\n";
    } else {
        echo "\n✅ LA CONSULTA DEVUELVE ANTECEDENTES CORRECTAMENTE\n";
    }
    
    // Simular cómo se agrupa por categoría
    $permissionsByCategory = [];
    foreach ($permissions as $permission) {
        $category = $permission['category'];
        if (!isset($permissionsByCategory[$category])) {
            $permissionsByCategory[$category] = [];
        }
        $permissionsByCategory[$category][] = $permission;
    }
    
    echo "\n📋 ESTRUCTURA JSON (como lo devuelve la API):\n";
    echo json_encode([
        'success' => true,
        'data' => $permissionsByCategory
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    echo "\n\n✅ VERIFICACIÓN COMPLETADA\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

