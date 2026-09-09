<?php
/**
 * API para crear y actualizar pacientes
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Activar output buffering para prevenir salida accidental
ob_start();

// Headers JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

// Función para enviar respuesta JSON
function sendJsonResponse($success, $data = null, $error = null) {
    // Limpiar cualquier salida previa
    while (ob_get_level()) {
        ob_end_clean();
    }
    
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

// Función para generar ID interno automático
function generarIdInterno($pdo) {
    try {
        $fecha = date('Ymd'); // Formato AAAAMMDD
        $prefijo = 'PAC-' . $fecha . '-';
        
        // Buscar el último número del día
        $query = "SELECT id_interno FROM pacientes 
                  WHERE id_interno LIKE :prefijo 
                  ORDER BY id_interno DESC 
                  LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':prefijo', $prefijo . '%');
        $stmt->execute();
        $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ultimo && isset($ultimo['id_interno'])) {
            // Extraer el número del último ID
            $ultimoId = $ultimo['id_interno'];
            $prefijoLen = strlen($prefijo);
            if (strlen($ultimoId) > $prefijoLen) {
                $ultimoNumero = intval(substr($ultimoId, $prefijoLen));
                $nuevoNumero = $ultimoNumero + 1;
            } else {
                $nuevoNumero = 1;
            }
        } else {
            // Es el primero del día
            $nuevoNumero = 1;
        }
        
        // Formatear con ceros a la izquierda (ej: 001, 002, 003)
        $numeroFormateado = str_pad($nuevoNumero, 3, '0', STR_PAD_LEFT);
        
        return $prefijo . $numeroFormateado;
    } catch (Exception $e) {
        error_log('Error en generarIdInterno: ' . $e->getMessage());
        // Fallback: generar ID con timestamp
        $fecha = date('Ymd');
        $timestamp = time();
        return 'PAC-' . $fecha . '-' . str_pad(substr($timestamp, -3), 3, '0', STR_PAD_LEFT);
    }
}

try {
    // Conectar a la base de datos
    require_once __DIR__ . '/../../config/database.php';
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    // Verificar sesión y permisos del usuario
    $user_id = null;
    $has_permission = false;
    
    // Verificar si hay token de autenticación
    $headers = getallheaders();
    $token = null;
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token && isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    
    if ($token) {
        try {
            require_once __DIR__ . '/../../classes/User.php';
            $user = new User();
            $user_data = $user->validateSession($token);
            
            if ($user_data && is_array($user_data)) {
                $user_id = $user_data['id'];
                
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                $has_permission = in_array('all', $user_permisos) || 
                                 in_array('pacientes', $user_permisos);
            }
        } catch (Exception $e) {
            error_log('Error validando sesión en pacientes/save.php: ' . $e->getMessage());
        }
    }
    
    // Verificar que el usuario tenga permiso para acceder
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para gestionar pacientes');
    }
    
    // Obtener datos del JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendJsonResponse(false, null, 'Datos inválidos');
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $isUpdate = $method === 'PUT' || isset($input['id']);
    
    // Validar campos requeridos
    if (empty($input['nombre'])) {
        sendJsonResponse(false, null, 'Campo requerido: nombre');
    }
    
    // Mapear paciente_id a id_interno (si viene del frontend)
    $id_interno = !empty($input['paciente_id']) ? trim($input['paciente_id']) : (!empty($input['id_interno']) ? trim($input['id_interno']) : '');
    
    // En creación, si no se proporciona id_interno, generarlo automáticamente
    if (!$isUpdate && empty($id_interno)) {
        $id_interno = generarIdInterno($pdo);
    }
    
    // Verificar que id_interno no existe (solo en creación)
    if (!$isUpdate) {
        if (!empty($id_interno)) {
            $checkQuery = "SELECT id FROM pacientes WHERE id_interno = :id_interno";
            $checkStmt = $pdo->prepare($checkQuery);
            $checkStmt->bindValue(':id_interno', $id_interno);
            $checkStmt->execute();
            if ($checkStmt->fetch()) {
                // Si existe, generar uno nuevo
                $id_interno = generarIdInterno($pdo);
            }
        }
    } else {
        // En actualización, verificar que el paciente existe
        if (empty($input['id'])) {
            sendJsonResponse(false, null, 'ID requerido para actualizar');
        }
        
        $checkQuery = "SELECT id FROM pacientes WHERE id = :id";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->bindValue(':id', $input['id'], PDO::PARAM_INT);
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            sendJsonResponse(false, null, 'Paciente no encontrado');
        }
        
        // Verificar que id_interno no existe en otro registro (si se está cambiando)
        if (!empty($id_interno)) {
            $checkQuery = "SELECT id FROM pacientes WHERE id_interno = :id_interno AND id != :id";
            $checkStmt = $pdo->prepare($checkQuery);
            $checkStmt->bindValue(':id_interno', $id_interno);
            $checkStmt->bindValue(':id', $input['id'], PDO::PARAM_INT);
            $checkStmt->execute();
            if ($checkStmt->fetch()) {
                sendJsonResponse(false, null, 'El ID interno ya existe en otro registro');
            }
        }
    }
    
    // Preparar datos para inserción/actualización según estructura real de la tabla
    $fields = [];
    
    // id_interno es requerido en creación, opcional en actualización
    if (!$isUpdate) {
        $fields['id_interno'] = $id_interno;
    } else if (!empty($id_interno)) {
        $fields['id_interno'] = $id_interno;
    }
    
    $fields['nombre'] = trim($input['nombre']);
    
    if (isset($input['idpaciente'])) {
        $fields['idpaciente'] = !empty($input['idpaciente']) ? trim($input['idpaciente']) : null;
    }
    
    if (isset($input['telefono'])) {
        $fields['telefono'] = !empty($input['telefono']) ? trim($input['telefono']) : null;
    }
    
    if (isset($input['email'])) {
        $fields['email'] = !empty($input['email']) ? trim(strtolower($input['email'])) : null;
    }
    
    if (isset($input['direccion']) || isset($input['domicilio'])) {
        $fields['domicilio'] = !empty($input['direccion']) ? trim($input['direccion']) : (!empty($input['domicilio']) ? trim($input['domicilio']) : null);
    }
    
    if (isset($input['search_enabled_types'])) {
        $fields['search_enabled_types'] = $input['search_enabled_types'];
    }
    
    if (isset($input['activo'])) {
        $fields['activo'] = $input['activo'] ? 1 : 0;
    } else if (!$isUpdate) {
        $fields['activo'] = 1;
    }
    
    if ($isUpdate) {
        // Actualizar
        $updateFields = [];
        $updateParams = [];
        
        foreach ($fields as $key => $value) {
            if ($key !== 'id') {
                $updateFields[] = "$key = :$key";
                $updateParams[":$key"] = $value;
            }
        }
        
        $updateParams[':id'] = $input['id'];
        
        $query = "UPDATE pacientes SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $pdo->prepare($query);
        
        foreach ($updateParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        // Obtener el paciente actualizado
        $getQuery = "SELECT * FROM pacientes WHERE id = :id";
        $getStmt = $pdo->prepare($getQuery);
        $getStmt->bindValue(':id', $input['id'], PDO::PARAM_INT);
        $getStmt->execute();
        $paciente = $getStmt->fetch(PDO::FETCH_ASSOC);
        
        // Formatear datos para compatibilidad con frontend
        $paciente['paciente_id'] = $paciente['id_interno'] ?? '';
        $paciente['direccion'] = $paciente['domicilio'] ?? '';
        
        sendJsonResponse(true, $paciente, null);
        
    } else {
        // Crear
        $insertFields = array_keys($fields);
        $insertPlaceholders = ':' . implode(', :', $insertFields);
        
        $query = "INSERT INTO pacientes (" . implode(', ', $insertFields) . ") VALUES ($insertPlaceholders)";
        
        try {
            $stmt = $pdo->prepare($query);
            
            foreach ($fields as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            
            $stmt->execute();
            
            $newId = $pdo->lastInsertId();
            
            if (!$newId) {
                throw new Exception('No se pudo obtener el ID del paciente creado');
            }
            
            // Obtener el paciente creado
            $getQuery = "SELECT * FROM pacientes WHERE id = :id";
            $getStmt = $pdo->prepare($getQuery);
            $getStmt->bindValue(':id', $newId, PDO::PARAM_INT);
            $getStmt->execute();
            $paciente = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$paciente) {
                throw new Exception('No se pudo obtener el paciente creado');
            }
            
            // Formatear datos para compatibilidad con frontend
            $paciente['paciente_id'] = $paciente['id_interno'] ?? '';
            $paciente['direccion'] = $paciente['domicilio'] ?? '';
            
            sendJsonResponse(true, $paciente, null);
            
        } catch (PDOException $e) {
            error_log('Error PDO en save.php (crear paciente): ' . $e->getMessage());
            error_log('Query: ' . $query);
            error_log('Fields: ' . print_r($fields, true));
            $errorInfo = $e->errorInfo ?? [];
            sendJsonResponse(false, null, 'Error al crear paciente: ' . ($errorInfo[2] ?? $e->getMessage()));
        }
    }
    
} catch (PDOException $e) {
    error_log('Error PDO en save.php (pacientes): ' . $e->getMessage());
    $errorInfo = $e->errorInfo ?? [];
    sendJsonResponse(false, null, 'Error de base de datos: ' . ($errorInfo[2] ?? $e->getMessage()));
} catch (Exception $e) {
    error_log('Error en save.php (pacientes): ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(false, null, 'Error al guardar paciente: ' . $e->getMessage());
}

