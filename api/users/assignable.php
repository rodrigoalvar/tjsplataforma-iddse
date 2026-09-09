<?php
/**
 * API de Usuarios Asignables
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/assignable.php
 * 
 * NOTA: Esta API es solo para pruebas. En producción debe tener autenticación.
 */

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Obtener acción solicitada
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'possible_parents':
            handlePossibleParents($pdo);
            break;
        case 'assignable_users':
            handleAssignableUsers($pdo);
            break;
        default:
            handleListUsers($pdo);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

function handlePossibleParents($pdo) {
    try {
        $excludeUserId = $_GET['user_id'] ?? null;
        
        // Obtener usuarios que pueden ser padres (ROOT y ADMIN)
        $query = "SELECT id, nombre, apellido, email, nivel 
                  FROM usuarios 
                  WHERE activo = 1 
                  AND nivel IN ('root', 'admin')";
        
        if ($excludeUserId) {
            $query .= " AND id != ?";
        }
        
        $query .= " ORDER BY nivel DESC, nombre ASC";
        
        $stmt = $pdo->prepare($query);
        if ($excludeUserId) {
            $stmt->execute([$excludeUserId]);
        } else {
            $stmt->execute();
        }
        
        $users = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $users
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        throw new Exception('Error obteniendo posibles padres: ' . $e->getMessage());
    }
}

function handleAssignableUsers($pdo) {
    try {
        // Para la API de prueba, devolver todos los usuarios activos
        $query = "SELECT id, nombre, apellido, email, nivel 
                  FROM usuarios 
                  WHERE activo = 1 
                  ORDER BY nivel DESC, nombre ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $users
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        throw new Exception('Error obteniendo usuarios asignables: ' . $e->getMessage());
    }
}

function handleListUsers($pdo) {
    try {
        // Lista básica de usuarios
        $query = "SELECT id, nombre, apellido, email, nivel, activo 
                  FROM usuarios 
                  ORDER BY nivel DESC, nombre ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $users
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        throw new Exception('Error obteniendo usuarios: ' . $e->getMessage());
    }
}
?>