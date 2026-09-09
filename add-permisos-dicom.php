<?php
/**
 * Script para agregar permisos DICOM al sistema
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== AGREGANDO PERMISOS DICOM ===\n\n";
    
    $permisos = [
        ['dicom_query_retrieve', 'QUERY/RETRIEVE', 'Permite realizar consultas y recuperación de estudios DICOM desde el servidor PACS', 'dicom'],
        ['dicom_web', 'DICOMWeb', 'Permite acceder a funcionalidades DICOMWeb para consulta y recuperación de estudios', 'dicom']
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
    
    echo "\n✅ PERMISOS DICOM AGREGADOS CORRECTAMENTE\n";
    
    // Verificar permisos DICOM
    echo "\n📋 PERMISOS DICOM EN LA BASE DE DATOS:\n";
    $stmt = $pdo->query("SELECT permission_key, permission_name FROM system_permissions WHERE category = 'dicom' ORDER BY permission_key");
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

