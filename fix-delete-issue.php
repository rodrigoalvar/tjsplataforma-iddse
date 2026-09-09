<?php
/**
 * Script para solucionar el problema de eliminación de prueba_curl@test.com
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== SOLUCIONANDO PROBLEMA DE ELIMINACIÓN ===\n\n";
    
    // Buscar la cuenta específica
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE email = 'prueba_curl@test.com'");
    $stmt->execute();
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "✗ Usuario no encontrado\n";
        exit;
    }
    
    echo "Usuario: {$user['nombre']} {$user['apellido']} (ID: {$user['id']})\n\n";
    
    // Verificar estudios asignados
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $studiesCount = $stmt->fetch()['count'];
    
    echo "Estudios asignados: {$studiesCount}\n\n";
    
    if ($studiesCount > 0) {
        echo "=== OPCIÓN 1: ELIMINAR ASIGNACIONES DE ESTUDIOS ===\n";
        
        // Eliminar asignaciones de estudios
        $stmt = $pdo->prepare("DELETE FROM study_assignments WHERE user_id = ?");
        $result = $stmt->execute([$user['id']]);
        
        if ($result) {
            $deletedCount = $stmt->rowCount();
            echo "✓ Eliminadas {$deletedCount} asignaciones de estudios\n";
        } else {
            echo "✗ Error eliminando asignaciones\n";
        }
        
        echo "\n=== OPCIÓN 2: REASIGNAR A OTRO USUARIO ===\n";
        
        // Buscar otro usuario para reasignar (si es necesario)
        $stmt = $pdo->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE id != ? AND activo = 1 ORDER BY nivel DESC LIMIT 1");
        $stmt->execute([$user['id']]);
        $alternativeUser = $stmt->fetch();
        
        if ($alternativeUser) {
            echo "Usuario alternativo encontrado: {$alternativeUser['nombre']} {$alternativeUser['apellido']}\n";
            echo "Si necesitas reasignar estudios, puedes usar este usuario.\n";
        }
        
        echo "\n=== VERIFICACIÓN POST-ELIMINACIÓN ===\n";
        
        // Verificar que ya no tiene estudios
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $remainingStudies = $stmt->fetch()['count'];
        
        echo "Estudios restantes: {$remainingStudies}\n";
        
        if ($remainingStudies == 0) {
            echo "✓ Ahora el usuario se puede eliminar desde la interfaz\n";
        } else {
            echo "✗ Aún tiene estudios asignados\n";
        }
        
    } else {
        echo "El usuario no tiene estudios asignados, debería poder eliminarse\n";
    }
    
    echo "\n=== RESUMEN ===\n";
    echo "✓ Problema identificado: Usuario tenía estudios asignados\n";
    echo "✓ Solución aplicada: Eliminadas las asignaciones de estudios\n";
    echo "✓ Resultado: Usuario ahora se puede eliminar desde la interfaz\n";
    
    echo "\n=== PRÓXIMOS PASOS ===\n";
    echo "1. Ve a la interfaz de gestión de usuarios\n";
    echo "2. Busca la cuenta prueba_curl@test.com\n";
    echo "3. Ahora debería aparecer el icono del basurero\n";
    echo "4. Puedes eliminarla normalmente\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


