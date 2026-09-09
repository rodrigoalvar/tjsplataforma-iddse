<?php
/**
 * Script para verificar TODOS los permisos del sistema
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "🔍 Verificando TODOS los permisos del sistema...\n\n";
    
    // Ver todos los permisos agrupados por categoría
    $query = "SELECT permission_key, permission_name, category, description
              FROM system_permissions 
              ORDER BY category, permission_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 Total de permisos encontrados: " . count($permisos) . "\n\n";
    
    // Agrupar por categoría
    $porCategoria = [];
    foreach ($permisos as $perm) {
        $cat = $perm['category'];
        if (!isset($porCategoria[$cat])) {
            $porCategoria[$cat] = [];
        }
        $porCategoria[$cat][] = $perm;
    }
    
    // Mostrar por categoría
    foreach ($porCategoria as $categoria => $perms) {
        echo "📂 Categoría: {$categoria} (" . count($perms) . " permisos)\n";
        foreach ($perms as $perm) {
            echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
        }
        echo "\n";
    }
    
    // Verificar si hay categorías que deberían tener permisos pero no los tienen
    echo "🔍 Verificando categorías esperadas:\n";
    $categoriasEsperadas = [
        'general', 'estudios', 'informes', 'audio', 'plantillas', 
        'visor', 'dicom', 'antecedentes', 'interfaz', 'gui', 'admin'
    ];
    
    foreach ($categoriasEsperadas as $cat) {
        $tiene = isset($porCategoria[$cat]) ? count($porCategoria[$cat]) : 0;
        $status = $tiene > 0 ? '✅' : '❌';
        echo "   {$status} {$cat}: {$tiene} permisos\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
