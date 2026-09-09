<?php
/**
 * Script para verificar estructura de tabla sesiones
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "=== ESTRUCTURA TABLA SESIONES ===\n";
    $query = 'DESCRIBE sesiones';
    $stmt = $db->prepare($query);
    $stmt->execute();
    $columns = $stmt->fetchAll();
    
    foreach($columns as $col) {
        echo "{$col['Field']} - {$col['Type']}\n";
    }
    
    echo "\n=== CONTENIDO TABLA SESIONES ===\n";
    $query = 'SELECT * FROM sesiones LIMIT 5';
    $stmt = $db->prepare($query);
    $stmt->execute();
    $sessions = $stmt->fetchAll();
    
    foreach($sessions as $session) {
        echo "ID: {$session['id']} - Usuario: {$session['usuario_id']} - Expira: {$session['expira_en']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>