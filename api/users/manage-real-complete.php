<?php
/**
 * API Completa con Datos Reales
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Desactivar display de errores
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

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
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Obtener método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGetUsers($pdo);
            break;
        case 'POST':
            handleCreateUser($pdo);
            break;
        case 'PUT':
            handleUpdateUser($pdo);
            break;
        case 'DELETE':
            handleDeleteUser($pdo);
            break;
        default:
            sendJsonResponse(false, null, 'Método no permitido');
    }
    
} catch (Exception $e) {
    sendJsonResponse(false, null, $e->getMessage());
}

function handleGetUsers($pdo) {
    try {
        // Verificar si se solicitan todos los usuarios (incluyendo inactivos)
        $includeInactive = isset($_GET['all']) && $_GET['all'] === 'true';
        
        // Verificar qué columnas existen en la tabla usuarios
        $columnsQuery = "SHOW COLUMNS FROM usuarios";
        $columnsStmt = $pdo->prepare($columnsQuery);
        $columnsStmt->execute();
        $existingColumns = array_column($columnsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        
        // Construir SELECT dinámicamente basado en columnas existentes
        $selectFields = [
            'u.id',
            'u.nombre',
            'u.apellido',
            'u.email',
            'u.telefono',
            'u.matricula_profesional',
            'u.nivel',
            'u.rol',
            'u.padre_id',
            'u.especialidad',
            'u.activo',
            'u.permisos',
            'u.created_at',
            'u.updated_at',
            'p.nombre as padre_nombre',
            'p.apellido as padre_apellido',
            'p.email as padre_email',
            '(SELECT COUNT(*) FROM usuarios h WHERE h.padre_id = u.id AND h.activo = 1) as dependientes_count'
        ];
        
        // Agregar columnas DICOM solo si existen
        $dicomColumns = ['dicom_aetitle', 'dicom_ip', 'dicom_puerto', 'dicom_viewer', 'study_routing_mode'];
        foreach ($dicomColumns as $col) {
            if (in_array($col, $existingColumns)) {
                $selectFields[] = "u.$col";
            }
        }
        
        // Agregar instituciones_permitidas solo si existe
        if (in_array('instituciones_permitidas', $existingColumns)) {
            $selectFields[] = 'u.instituciones_permitidas';
        }

        // Agregar session_timeout_hours solo si existe (migración opcional)
        if (in_array('session_timeout_hours', $existingColumns)) {
            $selectFields[] = 'u.session_timeout_hours';
        }
        
        // Construir la consulta
        $query = "SELECT " . implode(",\n                    ", $selectFields) . "
                  FROM usuarios u
                  LEFT JOIN usuarios p ON u.padre_id = p.id";
        
        // Agregar condición WHERE según si se incluyen inactivos
        if (!$includeInactive) {
            $query .= " WHERE u.activo = 1";
        }
        
        $query .= " ORDER BY u.activo DESC, u.nivel DESC, u.nombre ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        // Procesar permisos
        foreach ($users as &$user) {
            if ($user['permisos']) {
                try {
                    $user['permisos'] = json_decode($user['permisos'], true);
                } catch (Exception $e) {
                    $user['permisos'] = [];
                }
            } else {
                $user['permisos'] = [];
            }
        }
        
        sendJsonResponse(true, [
            'users' => $users,
            'hierarchy' => [] // Simplificado por ahora
        ]);
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error obteniendo usuarios: ' . $e->getMessage());
    }
}

function handleCreateUser($pdo) {
    try {
        // Obtener datos del JSON
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendJsonResponse(false, null, 'Datos inválidos');
        }
        
        // Validar campos requeridos
        $requiredFields = ['nombre', 'apellido', 'email', 'nivel'];
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                sendJsonResponse(false, null, "Campo requerido: {$field}");
            }
        }
        
        // Verificar si el email existe (activo o inactivo)
        $email = strtolower(trim($input['email']));
        $query = "SELECT id, activo FROM usuarios WHERE email = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();
        
        if ($existingUser) {
            if ($existingUser['activo'] == 1) {
                // El email ya está registrado y activo
                sendJsonResponse(false, null, 'El email ya está registrado');
            } else {
                // El email existe pero está inactivo - reactivaremos el usuario existente
                // Continuar con el proceso de actualización en lugar de creación
                $existingUserId = $existingUser['id'];
            }
        }
        
        // Verificar que la matrícula no existe (si se proporciona) - solo para usuarios activos
        if (!empty($input['matricula_profesional'])) {
            $matricula = strtoupper(trim($input['matricula_profesional']));
            $query = "SELECT id FROM usuarios WHERE matricula_profesional = ? AND activo = 1";
            if (isset($existingUserId)) {
                // Si estamos reactivando, excluir el usuario actual
                $query .= " AND id != ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$matricula, $existingUserId]);
            } else {
                $stmt = $pdo->prepare($query);
                $stmt->execute([$matricula]);
            }
            if ($stmt->fetch()) {
                sendJsonResponse(false, null, 'La matrícula profesional ya está registrada');
            }
        }
        
        // Preparar datos para inserción/actualización
        $nombre = trim($input['nombre']);
        $apellido = trim($input['apellido']);
        $telefono = trim($input['telefono'] ?? '');
        $matricula_profesional = trim($input['matricula_profesional'] ?? '');
        $especialidad = trim($input['especialidad'] ?? '');
        $rol = !empty($input['rol']) ? trim($input['rol']) : null;
        $nivel = trim($input['nivel']);
        $padre_id = !empty($input['padre_id']) ? intval($input['padre_id']) : null;
        $permisos = $input['permisos'] ?? null;
        $dicom_aetitle = !empty($input['dicom_aetitle']) ? trim($input['dicom_aetitle']) : null;
        $dicom_ip = !empty($input['dicom_ip']) ? trim($input['dicom_ip']) : null;
        $dicom_puerto = !empty($input['dicom_puerto']) ? intval($input['dicom_puerto']) : null;
        $dicom_viewer = !empty($input['dicom_viewer']) ? trim($input['dicom_viewer']) : 'UDV';
        $instituciones_permitidas = !empty($input['instituciones_permitidas']) ? $input['instituciones_permitidas'] : null;
        $session_timeout_hours = isset($input['session_timeout_hours']) && $input['session_timeout_hours'] !== ''
            ? (int) $input['session_timeout_hours']
            : null;
        
        // Procesar instituciones_permitidas si viene como string JSON
        if ($instituciones_permitidas && is_string($instituciones_permitidas)) {
            $decoded = json_decode($instituciones_permitidas, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $instituciones_permitidas = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        } elseif ($instituciones_permitidas && is_array($instituciones_permitidas)) {
            $instituciones_permitidas = json_encode($instituciones_permitidas, JSON_UNESCAPED_UNICODE);
        } else {
            $instituciones_permitidas = null;
        }
        
        // Generar contraseña temporal si no se proporciona
        $password = $input['password'] ?? null;
        if (!$password) {
            $password = 'TempPass' . rand(1000, 9999);
        }
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Procesar permisos: convertir a JSON válido
        if (is_array($permisos)) {
            // Si es un array, convertirlo a JSON
            $permisos = json_encode($permisos, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($permisos)) {
            // Si es string, verificar si es JSON válido
            $decoded = json_decode($permisos, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $permisos = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            } else {
                // Si no es JSON válido, usar permisos por defecto
                $permisos = json_encode(getDefaultPermissions($nivel));
            }
        } else {
            // Si está vacío o es null, usar permisos por defecto
            $permisos = json_encode(getDefaultPermissions($nivel));
        }
        
        // Si el usuario existe pero está inactivo, reactivarlo (UPDATE)
        if (isset($existingUserId)) {
            $query = "UPDATE usuarios SET 
                      nombre = ?, apellido = ?, telefono = ?, matricula_profesional = ?, 
                      especialidad = ?, rol = ?, nivel = ?, padre_id = ?, password_hash = ?, permisos = ?, 
                      dicom_aetitle = ?, dicom_ip = ?, dicom_puerto = ?, dicom_viewer = ?,
                      instituciones_permitidas = ?, session_timeout_hours = ?, activo = 1, updated_at = NOW()
                      WHERE id = ?";
            
            $stmt = $pdo->prepare($query);
            $result = $stmt->execute([
                $nombre,
                $apellido,
                $telefono,
                $matricula_profesional,
                $especialidad,
                $rol,
                $nivel,
                $padre_id,
                $password_hash,
                $permisos,
                $dicom_aetitle,
                $dicom_ip,
                $dicom_puerto,
                $dicom_viewer,
                $instituciones_permitidas,
                $session_timeout_hours,
                $existingUserId
            ]);
            
            if ($result) {
                sendJsonResponse(true, [
                    'message' => 'Usuario reactivado exitosamente',
                    'user_id' => $existingUserId,
                    'password' => $password, // Devolver contraseña temporal
                    'reactivated' => true
                ]);
            } else {
                sendJsonResponse(false, null, 'Error al reactivar usuario');
            }
        } else {
            // Crear nuevo usuario (INSERT)
            $query = "INSERT INTO usuarios 
                      (nombre, apellido, email, telefono, matricula_profesional, 
                       especialidad, rol, nivel, padre_id, password_hash, permisos, 
                       dicom_aetitle, dicom_ip, dicom_puerto, dicom_viewer,
                       instituciones_permitidas, session_timeout_hours, activo, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            
            $stmt = $pdo->prepare($query);
            $result = $stmt->execute([
                $nombre,
                $apellido,
                $email,
                $telefono,
                $matricula_profesional,
                $especialidad,
                $rol,
                $nivel,
                $padre_id,
                $password_hash,
                $permisos,
                $dicom_aetitle,
                $dicom_ip,
                $dicom_puerto,
                $dicom_viewer,
                $instituciones_permitidas,
                $session_timeout_hours
            ]);
            
            if ($result) {
                $userId = $pdo->lastInsertId();
                sendJsonResponse(true, [
                    'message' => 'Usuario creado exitosamente',
                    'user_id' => $userId,
                    'password' => $password // Devolver contraseña temporal
                ]);
            } else {
                sendJsonResponse(false, null, 'Error al crear usuario');
            }
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error creando usuario: ' . $e->getMessage());
    }
}

function getDefaultPermissions($nivel) {
    switch ($nivel) {
        case 'root':
            return ['all'];
        case 'admin':
            return ['dashboard', 'estudios', 'pacs_query', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'];
        case 'user':
            return ['dashboard', 'informes', 'grabacion'];
        default:
            return ['dashboard'];
    }
}

function handleUpdateUser($pdo) {
    try {
        // Obtener ID desde parámetro de URL
        $userId = $_GET['id'] ?? null;
        
        if (!$userId) {
            sendJsonResponse(false, null, 'ID de usuario requerido');
        }
        
        // Obtener datos del JSON
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendJsonResponse(false, null, 'Datos inválidos');
        }
        
        // Verificar que el usuario existe (sin restricción de activo para permitir reactivación)
        // Esto permite actualizar usuarios inactivos, necesario para reactivación
        $query = "SELECT id, activo FROM usuarios WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJsonResponse(false, null, 'Usuario no encontrado');
        }
        
        // Construir query de actualización
        $fields = [];
        $values = [];
        
        $allowedFields = ['nombre', 'apellido', 'email', 'telefono', 'matricula_profesional', 'nivel', 'rol', 'padre_id', 'especialidad', 'permisos', 'dicom_aetitle', 'dicom_ip', 'dicom_puerto', 'dicom_viewer', 'study_routing_mode', 'instituciones_permitidas', 'activo', 'session_timeout_hours'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $fields[] = "{$field} = ?";
                // Convertir dicom_puerto a entero si existe
                if ($field === 'dicom_puerto' && !empty($input[$field])) {
                    $values[] = intval($input[$field]);
                } elseif ($field === 'activo') {
                    // Asegurar que activo sea 0 o 1
                    $values[] = $input[$field] ? 1 : 0;
                } elseif ($field === 'permisos') {
                    // Asegurar que permisos sea un JSON válido
                    if (is_array($input[$field])) {
                        $values[] = json_encode($input[$field], JSON_UNESCAPED_UNICODE);
                    } elseif (is_string($input[$field])) {
                        // Si ya es string, verificar si es JSON válido
                        $decoded = json_decode($input[$field], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $values[] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                        } else {
                            $values[] = $input[$field]; // Usar tal cual si no es JSON válido
                        }
                    } else {
                        $values[] = json_encode([], JSON_UNESCAPED_UNICODE);
                    }
                } elseif ($field === 'instituciones_permitidas') {
                    // Manejar instituciones_permitidas: puede ser null, string JSON, o array
                    if ($input[$field] === null || $input[$field] === '') {
                        $values[] = null;
                    } elseif (is_string($input[$field])) {
                        // Si ya es string JSON, verificar si es válido
                        $decoded = json_decode($input[$field], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $values[] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                        } else {
                            // Si no es JSON válido, intentar tratarlo como string y convertirlo a array
                            $values[] = json_encode([$input[$field]], JSON_UNESCAPED_UNICODE);
                        }
                    } elseif (is_array($input[$field])) {
                        $values[] = json_encode($input[$field], JSON_UNESCAPED_UNICODE);
                    } else {
                        $values[] = null;
                    }
                } elseif ($field === 'dicom_viewer') {
                    // Validar y limpiar dicom_viewer
                    $viewerValue = !empty($input[$field]) ? trim($input[$field]) : 'UDV';
                    // Validar que sea uno de los valores permitidos
                    if (!in_array($viewerValue, ['UDV', 'StoneViewer', 'Oviyam'])) {
                        $viewerValue = 'UDV';
                    }
                    $values[] = $viewerValue;
                } elseif ($field === 'study_routing_mode') {
                    $raw = isset($input[$field]) ? trim((string)$input[$field]) : '';
                    if ($raw === '' || strtolower($raw) === 'inherit') {
                        $values[] = null;
                    } elseif (in_array(strtolower($raw), ['local', 'r2'], true)) {
                        $values[] = strtolower($raw);
                    } else {
                        $values[] = null;
                    }
                } elseif ($field === 'session_timeout_hours') {
                    // null / '' → NULL (usar default global); 0 → sin timeout; N → N horas
                    $val = $input[$field];
                    $values[] = ($val === null || $val === '') ? null : (int) $val;
                } else {
                    $values[] = $input[$field];
                }
            }
        }
        
        // Contraseña nueva (plain text en JSON; se guarda solo como hash)
        if (!empty($input['password']) && is_string($input['password'])) {
            $plain = trim($input['password']);
            if (strlen($plain) < 6) {
                sendJsonResponse(false, null, 'La contraseña debe tener al menos 6 caracteres');
            }
            $fields[] = 'password_hash = ?';
            $values[] = password_hash($plain, PASSWORD_DEFAULT);
        }
        
        if (empty($fields)) {
            sendJsonResponse(false, null, 'No hay campos para actualizar');
        }
        
        $values[] = $userId;
        
        $query = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute($values);
        
        if ($result) {
            sendJsonResponse(true, [
                'message' => 'Usuario actualizado exitosamente',
                'user_id' => $userId
            ]);
        } else {
            sendJsonResponse(false, null, 'Error actualizando usuario');
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error actualizando usuario: ' . $e->getMessage());
    }
}

function handleDeleteUser($pdo) {
    try {
        // Obtener ID desde parámetro de URL
        $userId = $_GET['id'] ?? null;
        
        if (!$userId) {
            sendJsonResponse(false, null, 'ID de usuario requerido');
        }
        
        // Verificar si es eliminación permanente
        $permanent = isset($_GET['permanent']) && $_GET['permanent'] === 'true';
        
        // Verificar que el usuario existe
        $query = "SELECT id, nivel, activo FROM usuarios WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJsonResponse(false, null, 'Usuario no encontrado');
        }
        
        if ($permanent) {
            // Eliminación permanente (hard delete)
            // Verificar que no sea el último usuario ROOT activo
            if ($user['nivel'] === 'root') {
                $rootCountQuery = "SELECT COUNT(*) as count FROM usuarios WHERE nivel = 'root' AND activo = 1";
                $rootCountStmt = $pdo->prepare($rootCountQuery);
                $rootCountStmt->execute();
                $rootCount = $rootCountStmt->fetch()['count'];
                
                if ($rootCount <= 1) {
                    sendJsonResponse(false, null, 'No se puede eliminar el último usuario ROOT activo del sistema');
                }
            }
            
            // Verificar si tiene dependientes activos
            $dependentsQuery = "SELECT COUNT(*) as count FROM usuarios WHERE padre_id = ? AND activo = 1";
            $dependentsStmt = $pdo->prepare($dependentsQuery);
            $dependentsStmt->execute([$userId]);
            $dependentsCount = $dependentsStmt->fetch()['count'];
            
            if ($dependentsCount > 0) {
                sendJsonResponse(false, null, 'No se puede eliminar permanentemente un usuario que tiene usuarios dependientes activos. Primero elimine o reasigne los dependientes.');
            }
            
            // Eliminar permanentemente
            $query = "DELETE FROM usuarios WHERE id = ?";
            $stmt = $pdo->prepare($query);
            $result = $stmt->execute([$userId]);
            
            if ($result) {
                sendJsonResponse(true, [
                    'message' => 'Usuario eliminado permanentemente de la base de datos',
                    'user_id' => $userId
                ]);
            } else {
                sendJsonResponse(false, null, 'Error eliminando usuario permanentemente');
            }
        } else {
            // Eliminación suave (soft delete) - solo si está activo
            if (!isset($user['activo']) || $user['activo'] != 1) {
                sendJsonResponse(false, null, 'El usuario ya está inactivo. Use la eliminación permanente si desea eliminarlo completamente de la base de datos.');
            }
            
            // Desactivar usuario (soft delete)
            $query = "UPDATE usuarios SET activo = 0 WHERE id = ?";
            $stmt = $pdo->prepare($query);
            $result = $stmt->execute([$userId]);
            
            if ($result) {
                sendJsonResponse(true, [
                    'message' => 'Usuario eliminado exitosamente',
                    'user_id' => $userId
                ]);
            } else {
                sendJsonResponse(false, null, 'Error eliminando usuario');
            }
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error eliminando usuario: ' . $e->getMessage());
    }
}
?>
