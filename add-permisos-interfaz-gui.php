<?php
/**
 * Script para agregar permisos de INTERFAZ/GUI al sistema
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== AGREGANDO PERMISOS DE INTERFAZ/GUI ===\n\n";
    
    $permisos = [
        ['gui_dashboard', 'Dashboard Visible', 'Controla la visibilidad y estado activo del acceso Dashboard en el sidebar', 'interfaz'],
        ['gui_estudios', 'Estudios Visible', 'Controla la visibilidad y estado activo del acceso Estudios en el sidebar', 'interfaz'],
        ['gui_informes', 'Informes Visible', 'Controla la visibilidad y estado activo del acceso Informes en el sidebar', 'interfaz'],
        ['gui_gestion_informes', 'Gestión Informes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Informes en el sidebar', 'interfaz'],
        ['gui_gestion_estudios', 'Gestión Estudios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Estudios en el sidebar', 'interfaz'],
        ['gui_gestion_pacientes', 'Gestión Pacientes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Pacientes en el sidebar', 'interfaz'],
        ['gui_grabacion', 'Grabación Visible', 'Controla la visibilidad y estado activo del acceso Grabación en el sidebar', 'interfaz'],
        ['gui_visor_dicom', 'Visor DICOM Visible', 'Controla la visibilidad y estado activo del acceso Visor DICOM en el sidebar', 'interfaz'],
        ['gui_workspace', 'WorkSpace Visible', 'Controla la visibilidad y estado activo del acceso WorkSpace en el sidebar', 'interfaz'],
        ['gui_gestion_usuarios', 'Gestión Usuarios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Usuarios en el sidebar', 'interfaz']
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
    
    // Verificar permisos de interfaz
    echo "\n📋 PERMISOS DE INTERFAZ/GUI EN LA BASE DE DATOS:\n";
    $stmt = $pdo->query("SELECT permission_key, permission_name FROM system_permissions WHERE category = 'interfaz' ORDER BY permission_key");
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

