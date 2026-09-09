<?php
/**
 * Script para corregir rutas inconsistentes en study_antecedents_files
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== CORRECCIÓN DE RUTAS INCONSISTENTES ===\n\n";
    
    // Buscar archivos con rutas que empiecen con ../
    $stmt = $pdo->prepare("SELECT id, file_name, file_path FROM study_antecedents_files WHERE file_path LIKE '../%'");
    $stmt->execute();
    $incorrectFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Archivos con rutas incorrectas encontrados: " . count($incorrectFiles) . "\n\n";
    
    if (count($incorrectFiles) === 0) {
        echo "✅ No hay archivos con rutas incorrectas.\n";
        exit;
    }
    
    $correctedCount = 0;
    $errorCount = 0;
    
    foreach ($incorrectFiles as $file) {
        $oldPath = $file['file_path'];
        $newPath = str_replace('../', '', $oldPath); // Remover ../ del inicio
        
        echo "Corrigiendo archivo ID {$file['id']}:\n";
        echo "  Ruta anterior: $oldPath\n";
        echo "  Ruta nueva: $newPath\n";
        
        // Verificar que el archivo existe en la nueva ruta
        if (file_exists($newPath)) {
            // Actualizar la ruta en la BD
            $updateStmt = $pdo->prepare("UPDATE study_antecedents_files SET file_path = ? WHERE id = ?");
            if ($updateStmt->execute([$newPath, $file['id']])) {
                echo "  ✅ CORREGIDO\n";
                $correctedCount++;
            } else {
                echo "  ❌ ERROR AL ACTUALIZAR BD\n";
                $errorCount++;
            }
        } else {
            echo "  ⚠️ ARCHIVO NO EXISTE EN NUEVA RUTA\n";
            $errorCount++;
        }
        echo "---\n";
    }
    
    echo "\n=== RESUMEN ===\n";
    echo "Archivos corregidos: $correctedCount\n";
    echo "Errores: $errorCount\n";
    
    if ($correctedCount > 0) {
        echo "\n✅ Corrección completada. Los archivos ahora deberían cargar correctamente.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>