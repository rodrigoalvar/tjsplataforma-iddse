<?php
/**
 * Script para verificar permisos del usuario root
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    // Buscar usuario root
    $query = "SELECT id, nombre, email, nivel, permisos FROM usuarios WHERE nivel = 'root' OR id = 1 LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "=== USUARIOS ROOT/ADMIN ENCONTRADOS ===\n";
    foreach($users as $user) {
        echo "ID: {$user['id']} - Nombre: {$user['nombre']} - Email: {$user['email']} - Nivel: {$user['nivel']} - Permisos: {$user['permisos']}\n";
        
        $permissions = json_decode($user['permisos'], true) ?: [];
        echo "Permisos decodificados: " . implode(', ', $permissions) . "\n";
        echo "Tiene 'all': " . (in_array('all', $permissions) ? 'SÍ' : 'NO') . "\n";
        echo "Tiene 'gestionInformes': " . (in_array('gestionInformes', $permissions) ? 'SÍ' : 'NO') . "\n";
        echo "---\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>