<?php
/**
 * Script para revisar las rutas de archivos en la BD
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== REVISIÓN DE RUTAS DE ARCHIVOS EN BD ===\n\n";
    
    // Obtener archivos de antecedentes
    $stmt = $pdo->query('SELECT id, file_name, file_path FROM study_antecedents_files ORDER BY id DESC LIMIT 20');
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total de archivos encontrados: " . count($files) . "\n\n";
    
    foreach($files as $file) {
        echo "ID: " . $file['id'] . "\n";
        echo "Nombre: " . $file['file_name'] . "\n";
        echo "Ruta BD: " . $file['file_path'] . "\n";
        
        // Verificar si el archivo existe
        $fullPath = $file['file_path'];
        if (file_exists($fullPath)) {
            echo "Estado: ✅ EXISTE\n";
        } else {
            echo "Estado: ❌ NO EXISTE\n";
            
            // Intentar rutas alternativas
            $altPath1 = 'uploads/antecedents/' . basename($file['file_path']);
            $altPath2 = '../uploads/antecedents/' . basename($file['file_path']);
            
            if (file_exists($altPath1)) {
                echo "Alternativa 1: ✅ EXISTE en $altPath1\n";
            } elseif (file_exists($altPath2)) {
                echo "Alternativa 2: ✅ EXISTE en $altPath2\n";
            } else {
                echo "Alternativas: ❌ NO EXISTE en ninguna ruta\n";
            }
        }
        echo "---\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>