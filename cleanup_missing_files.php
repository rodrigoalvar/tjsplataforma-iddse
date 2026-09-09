<?php
/**
 * Script de limpieza para eliminar registros de archivos faltantes
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== SCRIPT DE LIMPIEZA DE ARCHIVOS FALTANTES ===\n\n";
    
    // Obtener todos los archivos de la BD
    $stmt = $pdo->query("SELECT id, file_name, file_path FROM study_antecedents_files ORDER BY id");
    $allFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total de archivos en BD: " . count($allFiles) . "\n\n";
    
    $missingFiles = [];
    $existingFiles = [];
    
    // Verificar cada archivo
    foreach ($allFiles as $file) {
        if (file_exists($file['file_path'])) {
            $existingFiles[] = $file;
        } else {
            $missingFiles[] = $file;
        }
    }
    
    echo "Archivos existentes: " . count($existingFiles) . "\n";
    echo "Archivos faltantes: " . count($missingFiles) . "\n\n";
    
    if (count($missingFiles) === 0) {
        echo "✅ No hay archivos faltantes para limpiar.\n";
        exit;
    }
    
    echo "=== ARCHIVOS FALTANTES ===\n";
    foreach ($missingFiles as $file) {
        echo "ID: {$file['id']} | {$file['file_name']} | {$file['file_path']}\n";
    }
    
    echo "\n¿Desea eliminar estos registros de la BD? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim($line) === 'y' || trim($line) === 'Y') {
        $deletedCount = 0;
        
        foreach ($missingFiles as $file) {
            $deleteStmt = $pdo->prepare("DELETE FROM study_antecedents_files WHERE id = ?");
            if ($deleteStmt->execute([$file['id']])) {
                echo "✅ Eliminado registro ID {$file['id']}: {$file['file_name']}\n";
                $deletedCount++;
            } else {
                echo "❌ Error eliminando registro ID {$file['id']}\n";
            }
        }
        
        echo "\n=== RESUMEN ===\n";
        echo "Registros eliminados: $deletedCount\n";
        echo "✅ Limpieza completada.\n";
        
    } else {
        echo "❌ Operación cancelada.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>