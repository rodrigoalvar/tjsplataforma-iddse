<?php
/**
 * Script para agregar el permiso "Ver Todos" en la tabla system_permissions
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Verificar si el permiso ya existe
    $checkQuery = "SELECT COUNT(*) as count FROM system_permissions WHERE permission_key = 'verTodosInformes'";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute();
    $exists = $checkStmt->fetch()['count'] > 0;
    
    if ($exists) {
        echo json_encode([
            'success' => true,
            'message' => 'El permiso "verTodosInformes" ya existe en la base de datos',
            'action' => 'already_exists'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Insertar el nuevo permiso
    $insertQuery = "INSERT INTO system_permissions (permission_key, permission_name, description, category, created_at) 
                    VALUES (?, ?, ?, ?, NOW())";
    
    $insertStmt = $pdo->prepare($insertQuery);
    $result = $insertStmt->execute([
        'verTodosInformes',
        'Ver Todos',
        'Permite ver todos los informes del sistema, no solo los creados por el usuario',
        'informes'
    ]);
    
    if ($result) {
        // Verificar que se insertó correctamente
        $verifyQuery = "SELECT * FROM system_permissions WHERE permission_key = 'verTodosInformes'";
        $verifyStmt = $pdo->prepare($verifyQuery);
        $verifyStmt->execute();
        $permission = $verifyStmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Permiso "verTodosInformes" agregado exitosamente',
            'action' => 'inserted',
            'permission' => $permission
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Error al insertar el permiso en la base de datos');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
