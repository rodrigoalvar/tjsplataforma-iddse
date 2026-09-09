<?php
/**
 * Script para verificar plantillas en la base de datos
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== VERIFICANDO PLANTILLAS EN LA BASE DE DATOS ===\n\n";
    
    // Verificar estructura de la tabla
    echo "📋 ESTRUCTURA DE LA TABLA plantillas:\n";
    $stmt = $pdo->query("DESCRIBE plantillas");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "   • {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\n📋 TODAS LAS PLANTILLAS EN LA BASE DE DATOS:\n";
    $stmt = $pdo->query("SELECT id, template_id, nombre, usuario_id, activo, fecha_creacion, fecha_modificacion 
                         FROM plantillas 
                         ORDER BY fecha_creacion DESC");
    $plantillas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($plantillas) === 0) {
        echo "   ⚠️ No hay plantillas en la base de datos\n";
    } else {
        foreach ($plantillas as $plantilla) {
            echo "   ID: {$plantilla['id']}, Template ID: {$plantilla['template_id']}, Nombre: {$plantilla['nombre']}\n";
            echo "      Usuario ID: " . ($plantilla['usuario_id'] ?? 'NULL (Sistema)') . "\n";
            echo "      Activo: " . ($plantilla['activo'] ? 'SÍ' : 'NO') . "\n";
            echo "      Creada: {$plantilla['fecha_creacion']}\n";
            echo "      Modificada: {$plantilla['fecha_modificacion']}\n\n";
        }
    }
    
    echo "\n📊 RESUMEN:\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM plantillas");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   Total de plantillas: $total\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM plantillas WHERE usuario_id IS NULL");
    $sistema = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   Plantillas del sistema: $sistema\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM plantillas WHERE usuario_id IS NOT NULL");
    $usuarios = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   Plantillas de usuarios: $usuarios\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

