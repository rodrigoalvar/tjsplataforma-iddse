<?php
/**
 * Script para normalizar todas las categorías de permisos a minúsculas
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== NORMALIZANDO CATEGORÍAS DE PERMISOS ===\n\n";
    
    // Verificar categorías actuales
    $stmt = $pdo->query("SELECT DISTINCT category FROM system_permissions ORDER BY category");
    $categorias = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📋 CATEGORÍAS ANTES DE NORMALIZAR:\n";
    foreach ($categorias as $cat) {
        echo "   • '$cat'\n";
    }
    
    // Normalizar todas las categorías a minúsculas
    $stmt = $pdo->prepare("UPDATE system_permissions SET category = LOWER(category)");
    $stmt->execute();
    $affected = $stmt->rowCount();
    
    echo "\n✅ Categorías normalizadas. Filas afectadas: $affected\n\n";
    
    // Verificar categorías después de normalizar
    $stmt = $pdo->query("SELECT DISTINCT category FROM system_permissions ORDER BY category");
    $categorias = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📋 CATEGORÍAS DESPUÉS DE NORMALIZAR:\n";
    foreach ($categorias as $cat) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_permissions WHERE category = ?");
        $stmt->execute([$cat]);
        $count = $stmt->fetchColumn();
        echo "   • '$cat': $count permisos\n";
    }
    
    // Verificar específicamente la categoría estudios
    echo "\n🔍 VERIFICANDO CATEGORÍA 'estudios':\n";
    $stmt = $pdo->prepare("SELECT permission_key, permission_name FROM system_permissions WHERE category = 'estudios' ORDER BY permission_name");
    $stmt->execute();
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    
    echo "\n✅ NORMALIZACIÓN COMPLETADA\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

