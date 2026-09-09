<?php
/**
 * Script para agregar permisos de audio específicos para editor.html
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== AGREGANDO PERMISOS DE AUDIO ===\n\n";
    
    $permisos = [
        ['grabacion', 'Grabación de Audio', 'Permite grabar audios para informes', 'audio'],
        ['dictado', 'Dictado por Voz', 'Permite usar dictado por voz para informes', 'audio'],
        ['grabacion_sincronizada', 'Grabación Sincronizada', 'Permite usar grabación sincronizada con transcripción', 'audio'],
        ['transcripcion_audio', 'Transcripción de Archivos de Audio', 'Permite transcribir archivos de audio', 'audio']
    ];
    
    foreach ($permisos as $permiso) {
        $stmt = $pdo->prepare("INSERT INTO system_permissions (permission_key, permission_name, description, category) 
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE 
                                  permission_name = VALUES(permission_name),
                                  description = VALUES(description),
                                  category = VALUES(category)");
        
        $stmt->execute($permiso);
        echo "✅ {$permiso[1]} ({$permiso[0]}) - " . ($stmt->rowCount() > 0 ? 'Agregado' : 'Actualizado') . "\n";
    }
    
    echo "\n✅ PERMISOS AGREGADOS CORRECTAMENTE\n";
    
    // Verificar permisos de audio
    echo "\n📋 PERMISOS DE AUDIO EN LA BASE DE DATOS:\n";
    $stmt = $pdo->query("SELECT permission_key, permission_name FROM system_permissions WHERE category = 'audio' ORDER BY permission_key");
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

