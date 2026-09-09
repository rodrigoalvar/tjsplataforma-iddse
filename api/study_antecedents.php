<?php
/**
 * API para gestión de antecedentes médicos
 * Maneja la creación, lectura, actualización y eliminación de antecedentes
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Incluir configuración de base de datos
require_once '../config/database.php';

try {
    // Crear conexión a la base de datos
    $pdo = getDBConnection();
    
    // Crear tabla de antecedentes si no existe
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS study_antecedents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            study_id VARCHAR(255) NOT NULL,
            notes TEXT COMMENT 'Notas y antecedentes médicos',
            created_by INT NOT NULL,
            created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_study_id (study_id),
            INDEX idx_created_by (created_by),
            FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createTableSQL);
    
    // Crear tabla de archivos adjuntos si no existe
    $createFilesTableSQL = "
        CREATE TABLE IF NOT EXISTS study_antecedents_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            antecedent_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type ENUM('image', 'document', 'camera_capture') NOT NULL,
            file_size INT NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            uploaded_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_antecedent_id (antecedent_id),
            INDEX idx_file_type (file_type),
            FOREIGN KEY (antecedent_id) REFERENCES study_antecedents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createFilesTableSQL);
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGetRequest($pdo);
            break;
        case 'POST':
            handlePostRequest($pdo);
            break;
        case 'PUT':
            handlePutRequest($pdo);
            break;
        case 'DELETE':
            handleDeleteRequest($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
}

function handleGetRequest($pdo) {
    $studyId = $_GET['study_id'] ?? null;
    $studyIds = $_GET['study_ids'] ?? null;
    
    // Si hay muchos study_ids, usar POST en su lugar para evitar error 414
    // Pero mantener compatibilidad con GET para casos pequeños
    if ($studyIds) {
        $studyIdArray = explode(',', $studyIds);
        
        // Si hay más de 50 estudios, recomendar usar POST
        if (count($studyIdArray) > 50) {
            http_response_code(414);
            echo json_encode([
                'success' => false,
                'error' => 'Demasiados estudios en la URL. Use POST con study_ids en el body',
                'max_recommended' => 50
            ]);
            return;
        }
        
        // Consulta múltiple para obtener estado de antecedentes
        $placeholders = str_repeat('?,', count($studyIdArray) - 1) . '?';
        
        $sql = "SELECT 
                    sa.study_id,
                    COUNT(saf.id) as files_count,
                    CASE 
                        WHEN MAX(sa.notes) IS NOT NULL AND TRIM(MAX(sa.notes)) != '' THEN 1 
                        ELSE 0 
                    END as has_notes,
                    (COUNT(saf.id) + CASE 
                        WHEN MAX(sa.notes) IS NOT NULL AND TRIM(MAX(sa.notes)) != '' THEN 1 
                        ELSE 0 
                    END) as total_antecedents,
                    MAX(sa.notes) as notes,
                    MAX(sa.created_date) as created_date,
                    MAX(u.nombre) as created_by_name,
                    MAX(u.apellido) as created_by_surname
                FROM study_antecedents sa
                LEFT JOIN study_antecedents_files saf ON sa.id = saf.antecedent_id
                LEFT JOIN usuarios u ON sa.created_by = u.id
                WHERE sa.study_id IN ($placeholders)
                GROUP BY sa.study_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($studyIdArray);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $results
        ]);
        return;
    }
    
    if (!$studyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'study_id es requerido']);
        return;
    }
    
    // Obtener antecedentes del estudio
    $sql = "SELECT 
                sa.*,
                u.nombre as created_by_name,
                u.apellido as created_by_surname
            FROM study_antecedents sa
            INNER JOIN usuarios u ON sa.created_by = u.id
            WHERE sa.study_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$studyId]);
    $antecedents = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener archivos adjuntos
    $files = [];
    if ($antecedents) {
        $filesSql = "SELECT * FROM study_antecedents_files WHERE antecedent_id = ? ORDER BY uploaded_date ASC";
        $filesStmt = $pdo->prepare($filesSql);
        $filesStmt->execute([$antecedents['id']]);
        $files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Corregir las rutas de los archivos para que sean accesibles desde el frontend
        foreach ($files as &$file) {
            if ($file['file_path']) {
                // Si la ruta contiene ../uploads/, convertirla a una ruta accesible
                if (strpos($file['file_path'], '../uploads/') === 0) {
                    $file['file_path'] = str_replace('../uploads/', 'uploads/', $file['file_path']);
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'antecedents' => $antecedents,
            'files' => $files
        ]
    ]);
}

function handlePostRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        return;
    }
    
    // Si se envía study_ids, es una consulta de estado (no creación)
    if (isset($input['study_ids']) && is_array($input['study_ids'])) {
        $studyIdArray = $input['study_ids'];
        
        if (empty($studyIdArray)) {
            echo json_encode([
                'success' => true,
                'data' => []
            ]);
            return;
        }
        
        $placeholders = str_repeat('?,', count($studyIdArray) - 1) . '?';
        
        $sql = "SELECT 
                    sa.study_id,
                    COUNT(saf.id) as files_count,
                    CASE 
                        WHEN MAX(sa.notes) IS NOT NULL AND TRIM(MAX(sa.notes)) != '' THEN 1 
                        ELSE 0 
                    END as has_notes,
                    (COUNT(saf.id) + CASE 
                        WHEN MAX(sa.notes) IS NOT NULL AND TRIM(MAX(sa.notes)) != '' THEN 1 
                        ELSE 0 
                    END) as total_antecedents,
                    MAX(sa.notes) as notes,
                    MAX(sa.created_date) as created_date,
                    MAX(u.nombre) as created_by_name,
                    MAX(u.apellido) as created_by_surname
                FROM study_antecedents sa
                LEFT JOIN study_antecedents_files saf ON sa.id = saf.antecedent_id
                LEFT JOIN usuarios u ON sa.created_by = u.id
                WHERE sa.study_id IN ($placeholders)
                GROUP BY sa.study_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($studyIdArray);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $results
        ]);
        return;
    }
    
    // Si no es consulta de estado, es creación/actualización de antecedentes
    $studyId = $input['study_id'] ?? null;
    $notes = $input['notes'] ?? '';
    $createdBy = $input['created_by'] ?? null;
    
    if (!$studyId || !$createdBy) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'study_id y created_by son requeridos']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Verificar si ya existen antecedentes para este estudio
        $checkSql = "SELECT id FROM study_antecedents WHERE study_id = ?";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([$studyId]);
        $existingId = $checkStmt->fetchColumn();
        
        if ($existingId) {
            // Actualizar antecedentes existentes
            $updateSql = "UPDATE study_antecedents 
                         SET notes = ?, updated_date = CURRENT_TIMESTAMP 
                         WHERE study_id = ?";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([$notes, $studyId]);
            $antecedentId = $existingId;
        } else {
            // Insertar nuevos antecedentes
            $insertSql = "INSERT INTO study_antecedents (study_id, notes, created_by) 
                         VALUES (?, ?, ?)";
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([$studyId, $notes, $createdBy]);
            $antecedentId = $pdo->lastInsertId();
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Antecedentes guardados exitosamente',
            'data' => ['antecedent_id' => $antecedentId]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handlePutRequest($pdo) {
    // Implementar actualización si es necesario
    http_response_code(501);
    echo json_encode(['success' => false, 'error' => 'Método PUT no implementado']);
}

function handleDeleteRequest($pdo) {
    // Implementar eliminación si es necesario
    http_response_code(501);
    echo json_encode(['success' => false, 'error' => 'Método DELETE no implementado']);
}
?>
