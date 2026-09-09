<?php
/**
 * Script para debuggear la solicitud GET que está fallando
 */

require_once 'classes/User.php';
require_once 'config/database.php';

// Simular la solicitud que está fallando
$informeId = 35; // ID del informe que está fallando
$sessionToken = 'tu_token_aqui'; // Necesitaremos obtener un token válido

echo "=== DEBUG GET REQUEST ===\n";
echo "Informe ID: $informeId\n";

try {
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Primero, verificar si el informe existe
    $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
              FROM informes i
              LEFT JOIN usuarios u ON i.usuario_id = u.id
              WHERE i.id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId]);
    $informe = $stmt->fetch();
    
    if ($informe) {
        echo "✓ Informe encontrado:\n";
        echo "  - ID: {$informe['id']}\n";
        echo "  - Título: {$informe['titulo']}\n";
        echo "  - Usuario ID: {$informe['usuario_id']}\n";
        echo "  - Usuario Nombre: {$informe['usuario_nombre']}\n";
        echo "  - Fecha: {$informe['fecha_creacion']}\n";
    } else {
        echo "✗ Informe NO encontrado en la base de datos\n";
    }
    
    // Verificar todos los informes disponibles
    $query = "SELECT id, titulo, usuario_id FROM informes ORDER BY id DESC LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $informes = $stmt->fetchAll();
    
    echo "\n=== ÚLTIMOS 10 INFORMES ===\n";
    foreach($informes as $inf) {
        echo "ID: {$inf['id']} - Título: {$inf['titulo']} - Usuario: {$inf['usuario_id']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>