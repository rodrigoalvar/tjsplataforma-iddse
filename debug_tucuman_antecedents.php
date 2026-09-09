<?php
/**
 * Verificación de antecedentes para usuario TUCUMAN INFORMANTES
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN USUARIO TUCUMAN INFORMANTES ===\n\n";
    
    // Buscar usuario TUCUMAN INFORMANTES
    $stmt = $pdo->prepare('SELECT * FROM users WHERE nombre LIKE ? OR apellido LIKE ? OR email LIKE ?');
    $stmt->execute(['%TUCUMAN%', '%TUCUMAN%', '%TUCUMAN%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "❌ No se encontró usuario TUCUMAN\n";
        exit;
    }
    
    foreach ($users as $user) {
        echo "👤 Usuario encontrado:\n";
        echo "   ID: {$user['id']}\n";
        echo "   Nombre: {$user['nombre']} {$user['apellido']}\n";
        echo "   Email: {$user['email']}\n\n";
        
        // Buscar estudios asignados
        $stmt2 = $pdo->prepare('SELECT * FROM study_assignments WHERE user_id = ?');
        $stmt2->execute([$user['id']]);
        $assignments = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        echo "📋 Estudios asignados: " . count($assignments) . "\n";
        
        foreach ($assignments as $assignment) {
            echo "   - Study ID: {$assignment['study_id']}\n";
            
            // Verificar antecedentes para este estudio
            $stmt3 = $pdo->prepare('SELECT * FROM study_antecedents WHERE study_id = ?');
            $stmt3->execute([$assignment['study_id']]);
            $antecedents = $stmt3->fetch(PDO::FETCH_ASSOC);
            
            if ($antecedents) {
                echo "     ✅ Tiene antecedentes:\n";
                echo "        - Notas: " . (!empty($antecedents['notes']) ? 'SÍ (' . strlen($antecedents['notes']) . ' chars)' : 'NO') . "\n";
                echo "        - Creado: {$antecedents['created_date']}\n";
                
                // Verificar archivos de antecedentes
                $stmt4 = $pdo->prepare('SELECT COUNT(*) as count FROM study_antecedents_files WHERE antecedent_id = ?');
                $stmt4->execute([$antecedents['id']]);
                $fileCount = $stmt4->fetchColumn();
                echo "        - Archivos: $fileCount\n";
                
                // Calcular total_count como lo hace la API
                $totalCount = 0;
                if (!empty($antecedents['notes'])) $totalCount++;
                if ($fileCount > 0) $totalCount++;
                echo "        - Total Count (calculado): $totalCount\n";
                
            } else {
                echo "     ❌ Sin antecedents\n";
            }
            echo "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}