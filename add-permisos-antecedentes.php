<?php
/**
 * Script para agregar permisos de antecedentes al sistema
 * Permisos para controlar acceso a cada sección de antecedentes
 */

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    echo "=== AGREGANDO PERMISOS DE ANTECEDENTES ===\n\n";
    
    $permisos = [
        ['antecedentes_notas', 'Antecedentes - Notas y Texto', 'Permite acceder a la sección de notas y texto en antecedentes médicos', 'antecedentes'],
        ['antecedentes_imagenes', 'Antecedentes - Imágenes', 'Permite acceder a la sección de imágenes en antecedentes médicos', 'antecedentes'],
        ['antecedentes_camara', 'Antecedentes - Cámara', 'Permite acceder a la sección de cámara en antecedentes médicos', 'antecedentes'],
        ['antecedentes_archivos', 'Antecedentes - Archivos', 'Permite acceder a la sección de archivos en antecedentes médicos', 'antecedentes']
    ];
    
    $agregados = 0;
    $actualizados = 0;
    
    foreach ($permisos as $permiso) {
        try {
            $stmt = $pdo->prepare("INSERT INTO system_permissions (permission_key, permission_name, description, category) 
                                  VALUES (?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE 
                                      permission_name = VALUES(permission_name),
                                      description = VALUES(description),
                                      category = VALUES(category)");
            
            $stmt->execute($permiso);
            
            if ($stmt->rowCount() > 0) {
                // Verificar si fue insert o update
                $checkStmt = $pdo->prepare("SELECT id FROM system_permissions WHERE permission_key = ?");
                $checkStmt->execute([$permiso[0]]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    $actualizados++;
                    echo "✅ {$permiso[1]} ({$permiso[0]}) - Actualizado\n";
                } else {
                    $agregados++;
                    echo "✅ {$permiso[1]} ({$permiso[0]}) - Agregado\n";
                }
            } else {
                $actualizados++;
                echo "ℹ️  {$permiso[1]} ({$permiso[0]}) - Ya existía\n";
            }
        } catch (PDOException $e) {
            echo "❌ Error agregando {$permiso[1]}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✅ PERMISOS DE ANTECEDENTES PROCESADOS\n";
    echo "   • Agregados: {$agregados}\n";
    echo "   • Actualizados: {$actualizados}\n";
    
    // Verificar permisos de antecedentes
    echo "\n📋 PERMISOS DE ANTECEDENTES EN LA BASE DE DATOS:\n";
    $stmt = $pdo->query("SELECT permission_key, permission_name FROM system_permissions WHERE category = 'antecedentes' ORDER BY permission_key");
    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($permisos)) {
        echo "   ⚠️  No se encontraron permisos de antecedentes\n";
    } else {
        foreach ($permisos as $perm) {
            echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>



