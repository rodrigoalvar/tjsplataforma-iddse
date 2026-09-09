<?php
/**
 * Script para agregar el permiso "Adjuntar Audios" a la categoría Audio
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/database/agregar_permiso_adjuntar_audios.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Ejecutar el SQL
    $db->exec($sql);
    
    echo "✅ Permiso 'Adjuntar Audios' agregado exitosamente a la categoría Audio.\n";
    
    // Verificar que se agregó correctamente
    $stmt = $db->query("SELECT * FROM system_permissions WHERE permission_key = 'adjuntar_audios' AND category = 'audio'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "\n📋 Permiso encontrado en la base de datos:\n";
        echo "   - Clave: " . $result['permission_key'] . "\n";
        echo "   - Nombre: " . $result['permission_name'] . "\n";
        echo "   - Descripción: " . $result['description'] . "\n";
        echo "   - Categoría: " . $result['category'] . "\n";
    } else {
        echo "⚠️ Advertencia: El permiso no se encontró después de la inserción.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error de base de datos: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

