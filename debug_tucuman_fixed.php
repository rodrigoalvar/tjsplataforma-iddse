<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN USUARIO TUCUMAN INFORMANTES ===\n\n";
    
    // Buscar usuario TUCUMAN INFORMANTES
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE nombre LIKE ? OR apellido LIKE ? OR email LIKE ?');
    $stmt->execute(['%TUCUMAN%', '%TUCUMAN%', '%TUCUMAN%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "❌ No se encontró usuario TUCUMAN\n";
        // Mostrar todos los usuarios para verificar
        echo "\nTodos los usuarios:\n";
        $stmt = $pdo->query('SELECT id, nombre, apellido, email FROM usuarios');
        $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allUsers as $user) {
            echo "ID: {$user['id']}, Nombre: {$user['nombre']} {$user['apellido']}, Email: {$user['email']}\n";
        }
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
            $antecedents = $stmt3->fetchAll(PDO::FETCH_ASSOC); // fetchAll para ver todos los registros
            
            if (!empty($antecedents)) {
                echo "     ✅ Tiene " . count($antecedents) . " registro(s) de antecedentes:\n";
                
                foreach ($antecedents as $ant) {
                    echo "        - ID: {$ant['id']}\n";
                    echo "        - Notas: " . (!empty($ant['notes']) ? 'SÍ (' . strlen($ant['notes']) . ' chars)' : 'NO') . "\n";
                    echo "        - Creado: {$ant['created_date']}\n";
                    
                    // Verificar archivos de antecedentes
                    $stmt4 = $pdo->prepare('SELECT COUNT(*) as count FROM study_antecedents_files WHERE antecedent_id = ?');
                    $stmt4->execute([$ant['id']]);
                    $fileCount = $stmt4->fetchColumn();
                    echo "        - Archivos: $fileCount\n";
                    
                    // Calcular total_count como lo hace la API
                    $totalCount = 0;
                    if (!empty($ant['notes'])) $totalCount++;
                    if ($fileCount > 0) $totalCount++;
                    echo "        - Total Count (calculado): $totalCount\n";
                    echo "        ---\n";
                }
                
            } else {
                echo "     ❌ Sin antecedentes\n";
            }
            echo "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}