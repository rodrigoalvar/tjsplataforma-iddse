<?php
/**
 * API Ultra Simplificada para Pruebas
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Desactivar display de errores
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');

// Función para enviar respuesta JSON
function sendJsonResponse($success, $data = null, $error = null) {
    $response = ['success' => $success];
    
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Obtener método HTTP
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    switch ($method) {
        case 'GET':
            // Datos de prueba realistas
            $testUsers = [
                [
                    'id' => 1,
                    'nombre' => 'Root',
                    'apellido' => 'Administrator',
                    'email' => 'root@portal.com',
                    'telefono' => '1234567890',
                    'matricula_profesional' => 'ROOT001',
                    'nivel' => 'root',
                    'padre_id' => null,
                    'especialidad' => 'Administración',
                    'activo' => 1,
                    'permisos' => ['all'],
                    'ultimo_acceso' => '2025-10-22 11:00:00',
                    'created_at' => '2025-10-22 00:00:00',
                    'padre_nombre' => null,
                    'padre_apellido' => null,
                    'padre_email' => null,
                    'hijos_count' => 2
                ],
                [
                    'id' => 2,
                    'nombre' => 'Admin',
                    'apellido' => 'Principal',
                    'email' => 'admin@portal.com',
                    'telefono' => '0987654321',
                    'matricula_profesional' => 'ADMIN001',
                    'nivel' => 'admin',
                    'padre_id' => 1,
                    'especialidad' => 'Radiología',
                    'activo' => 1,
                    'permisos' => ['dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'],
                    'ultimo_acceso' => '2025-10-22 10:30:00',
                    'created_at' => '2025-10-22 01:00:00',
                    'padre_nombre' => 'Root',
                    'padre_apellido' => 'Administrator',
                    'padre_email' => 'root@portal.com',
                    'hijos_count' => 1
                ],
                [
                    'id' => 3,
                    'nombre' => 'Usuario',
                    'apellido' => 'Prueba',
                    'email' => 'usuario@portal.com',
                    'telefono' => '5555555555',
                    'matricula_profesional' => 'USER001',
                    'nivel' => 'user',
                    'padre_id' => 2,
                    'especialidad' => 'Medicina General',
                    'activo' => 1,
                    'permisos' => ['dashboard', 'informes', 'grabacion'],
                    'ultimo_acceso' => '2025-10-22 09:15:00',
                    'created_at' => '2025-10-22 02:00:00',
                    'padre_nombre' => 'Admin',
                    'padre_apellido' => 'Principal',
                    'padre_email' => 'admin@portal.com',
                    'hijos_count' => 0
                ]
            ];
            
            // Construir jerarquía de prueba
            $hierarchy = [
                [
                    'id' => 1,
                    'nombre' => 'Root',
                    'apellido' => 'Administrator',
                    'nivel' => 'root',
                    'children' => [
                        [
                            'id' => 2,
                            'nombre' => 'Admin',
                            'apellido' => 'Principal',
                            'nivel' => 'admin',
                            'children' => [
                                [
                                    'id' => 3,
                                    'nombre' => 'Usuario',
                                    'apellido' => 'Prueba',
                                    'nivel' => 'user',
                                    'children' => []
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            
            sendJsonResponse(true, [
                'users' => $testUsers,
                'hierarchy' => $hierarchy
            ]);
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            sendJsonResponse(true, ['message' => 'API POST funciona correctamente', 'data' => $input]);
            break;
            
        case 'PUT':
            $userId = $_GET['id'] ?? null;
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$userId) {
                sendJsonResponse(false, null, 'ID de usuario requerido');
            }
            
            if (!$input) {
                sendJsonResponse(false, null, 'Datos inválidos');
            }
            
            sendJsonResponse(true, [
                'message' => 'API PUT funciona correctamente',
                'user_id' => $userId,
                'data' => $input
            ]);
            break;
            
        case 'DELETE':
            $userId = $_GET['id'] ?? null;
            
            if (!$userId) {
                sendJsonResponse(false, null, 'ID de usuario requerido');
            }
            
            sendJsonResponse(true, ['message' => 'API DELETE funciona correctamente', 'user_id' => $userId]);
            break;
            
        default:
            sendJsonResponse(false, null, 'Método no permitido');
    }
    
} catch (Exception $e) {
    sendJsonResponse(false, null, $e->getMessage());
}
?>
