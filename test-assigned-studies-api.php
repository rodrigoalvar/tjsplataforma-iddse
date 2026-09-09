<?php
echo "=== PRUEBA DE API DE ESTUDIOS ASIGNADOS ===\n\n";

// Configuración de base de datos
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexión a base de datos exitosa\n\n";
    
    // Para testing, usar usuario ID 2 (admin)
    $userId = 2;
    
    // Obtener información del usuario actual
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, nivel, permisos FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Usuario no encontrado');
    }
    
    echo "👤 Usuario encontrado: {$user['nombre']} {$user['apellido']} ({$user['nivel']})\n\n";
    
    // Verificar si existe la tabla study_assignments
    $stmt = $pdo->query("SHOW TABLES LIKE 'study_assignments'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "✅ Tabla study_assignments existe\n";
        
        // Contar asignaciones
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM study_assignments WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo "📊 Asignaciones activas para usuario $userId: $count\n\n";
        
        if ($count > 0) {
            // Obtener estudios asignados
            $query = "
                SELECT 
                    sa.id as assignment_id,
                    sa.study_id,
                    sa.assigned_at,
                    sa.patient_name,
                    sa.patient_id,
                    sa.study_date,
                    sa.modality,
                    sa.study_description,
                    sa.orthanc_study_id,
                    sa.study_status,
                    a.notes as antecedents_notes,
                    a.has_images,
                    a.has_files,
                    a.has_camera_captures
                FROM study_assignments sa
                LEFT JOIN study_antecedents a ON sa.study_id = a.study_id
                WHERE sa.user_id = ? 
                AND sa.status = 'active'
                ORDER BY sa.assigned_at DESC
                LIMIT 5
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "📋 Estudios asignados:\n";
            foreach ($assignments as $assignment) {
                echo "   • {$assignment['patient_name']} ({$assignment['patient_id']}) - {$assignment['modality']}\n";
                echo "     Estudio: {$assignment['study_description']}\n";
                echo "     Asignado: {$assignment['assigned_at']}\n";
                
                $antecedentsCount = 0;
                if (!empty($assignment['antecedents_notes'])) $antecedentsCount++;
                if ($assignment['has_images']) $antecedentsCount++;
                if ($assignment['has_files']) $antecedentsCount++;
                if ($assignment['has_camera_captures']) $antecedentsCount++;
                
                echo "     Antecedentes: $antecedentsCount elementos\n\n";
            }
        } else {
            echo "⚠️ No hay asignaciones activas para este usuario\n";
        }
        
    } else {
        echo "❌ Tabla study_assignments NO existe\n";
        echo "   La funcionalidad de estudios asignados requiere esta tabla\n";
    }
    
    // Verificar tabla study_antecedents
    $stmt = $pdo->query("SHOW TABLES LIKE 'study_antecedents'");
    $antecedentsTableExists = $stmt->rowCount() > 0;
    
    if ($antecedentsTableExists) {
        echo "✅ Tabla study_antecedents existe\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM study_antecedents");
        $antecedentsCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "📊 Total de antecedentes: $antecedentsCount\n";
    } else {
        echo "❌ Tabla study_antecedents NO existe\n";
    }
    
    echo "\n✅ Prueba completada\n";
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
