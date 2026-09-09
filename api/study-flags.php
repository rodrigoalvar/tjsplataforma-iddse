<?php
/**
 * API Endpoint para gestionar flags de estudios (informes incompletos)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoints:
 * - GET: Obtener flag de un estudio para un usuario
 * - POST: Crear o actualizar flag
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

/**
 * Detecta el tipo de ID del estudio basándose en su formato
 */
function detectStudyIdType($id) {
    if (strpos($id, '-') !== false) {
        return ['type' => 'orthanc', 'value' => $id];
    } elseif (strpos($id, '.') !== false) {
        return ['type' => 'studyInstanceUID', 'value' => $id];
    } else {
        return ['type' => 'orthanc', 'value' => $id];
    }
}

try {
    // Verificar sesión
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
            if (strpos($auth_header, 'Bearer ') === 0) {
                $session_token = substr($auth_header, 7);
            }
        }
    }
    
    if (!$session_token) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
        exit();
    }
    
    $user = new User();
    $user_data = $user->validateSession($session_token);
    if (!$user_data) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión expirada o inválida']);
        exit();
    }
    
    $current_user_id = $user_data['id'];
    $db = getDBConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            // GET: Obtener flag de un estudio/informe para un usuario
            $study_id = $_GET['study_id'] ?? null;
            $informe_id = $_GET['informe_id'] ?? null;
            $user_id = $_GET['user_id'] ?? $current_user_id;
            
            if (!$study_id) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'study_id es requerido']);
                exit();
            }
            
            // Buscar flag del estudio/informe
            // REGLA: Para informes-manager, cualquier usuario con permiso marcar_incompletos debe ver
            // el estado actual del flag (incompleto/nota), independientemente de quién lo creó
            // Si hay informe_id, buscar flag específico del informe (sin filtrar por user_id)
            // Si no hay informe_id, buscar flag del estudio (comportamiento anterior)
            if ($informe_id) {
                // IMPORTANTE: Buscar SOLO por informe_id específico, SIN filtrar por user_id
                // Esto permite que cualquier usuario vea el estado del flag aunque otro lo haya creado
                $stmt = $db->prepare("
                    SELECT * FROM study_flags 
                    WHERE study_id = ?
                    AND informe_id = ?
                    ORDER BY updated_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$study_id, $informe_id]);
                error_log("[STUDY_FLAGS GET] Buscando flag para study_id=$study_id, informe_id=$informe_id (sin filtrar por user_id)");
            } else {
                // Para flags generales del estudio, primero buscar del usuario actual
                $stmt = $db->prepare("
                    SELECT * FROM study_flags 
                    WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                    AND user_id = ? 
                    AND informe_id IS NULL
                ");
                $stmt->execute([$study_id, $study_id, $study_id, $user_id]);
                error_log("[STUDY_FLAGS GET] Buscando flag para study_id=$study_id, user_id=$user_id, sin informe_id");
            }
            $flag = $stmt->fetch(PDO::FETCH_ASSOC);
            error_log("[STUDY_FLAGS GET] Flag encontrado: " . ($flag ? json_encode($flag) : 'NULL'));
            
            if ($flag) {
                $flag['informes_incompletos'] = (bool)$flag['informes_incompletos'];
                $flag['prioridad'] = $flag['prioridad'] ?? 'normal';
            }
            
            // Si no hay flag O si la prioridad es 'normal', buscar prioridad de cualquier usuario
            // para este estudio (la prioridad puede haber sido establecida por otro usuario)
            // IMPORTANTE: Si estamos buscando un informe específico, NO buscar flags de otros informes
            if ((!$flag || $flag['prioridad'] === 'normal') && !$informe_id) {
                $stmt = $db->prepare("
                    SELECT prioridad FROM study_flags 
                    WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                    AND prioridad IS NOT NULL AND prioridad != 'normal'
                    AND informe_id IS NULL
                    ORDER BY
                        CASE
                            WHEN prioridad = 'urgente' THEN 1
                            WHEN prioridad = 'promesa' THEN 2
                            WHEN prioridad = 'pendiente' THEN 3
                            ELSE 4
                        END
                    LIMIT 1
                ");
                $stmt->execute([$study_id, $study_id, $study_id]);
                $priorityFlag = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($priorityFlag && $priorityFlag['prioridad']) {
                    // Si no había flag, crear uno básico con la prioridad encontrada
                    if (!$flag) {
                        $flag = [
                            'informes_incompletos' => false,
                            'prioridad' => $priorityFlag['prioridad'],
                            'nota' => null
                        ];
                    } else {
                        // Si había flag pero con prioridad normal, actualizar la prioridad
                        $flag['prioridad'] = $priorityFlag['prioridad'];
                    }
                }
            }
            
            // Si no hay flag O si informes_incompletos es false/0, buscar informes_incompletos de cualquier usuario
            // para este estudio (puede haber sido marcado por otro usuario)
            // IMPORTANTE: Si estamos buscando un informe específico, NO buscar flags de otros informes
            if ((!$flag || !$flag['informes_incompletos']) && !$informe_id) {
                $stmt = $db->prepare("
                    SELECT informes_incompletos, nota FROM study_flags 
                    WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                    AND informes_incompletos = 1
                    AND informe_id IS NULL
                    LIMIT 1
                ");
                $stmt->execute([$study_id, $study_id, $study_id]);
                $incompletosFlag = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($incompletosFlag) {
                    if (!$flag) {
                        $flag = [
                            'informes_incompletos' => true,
                            'prioridad' => 'normal',
                            'nota' => $incompletosFlag['nota']
                        ];
                    } else {
                        $flag['informes_incompletos'] = true;
                        $flag['nota'] = $incompletosFlag['nota'];
                    }
                }
            }
            
            // Asegurar que siempre se devuelva un objeto con prioridad
            if (!$flag) {
                $flag = [
                    'informes_incompletos' => false,
                    'prioridad' => 'normal',
                    'nota' => null
                ];
            }
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'data' => $flag
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'POST':
            // POST: Crear o actualizar flag
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['study_id'])) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'study_id es requerido']);
                exit();
            }
            
            $study_id = $data['study_id'];
            $informe_id = isset($data['informe_id']) && $data['informe_id'] ? (int)$data['informe_id'] : null;
            // IMPORTANTE: Si no se envía informes_incompletos, debe ser NULL (no false)
            // para que la lógica de preservación funcione correctamente
            $informes_incompletos = isset($data['informes_incompletos']) ? (bool)$data['informes_incompletos'] : null;
            $nota = $data['nota'] ?? null;
            $prioridad = isset($data['prioridad']) && in_array($data['prioridad'], ['normal', 'promesa', 'urgente', 'pendiente'], true)
                        ? $data['prioridad']
                        : null;
            $orthanc_id = $data['orthanc_id'] ?? null;
            $study_instance_uid = $data['study_instance_uid'] ?? null;
            
            // Verificar permiso para marcar/desmarcar informes incompletos
            if ($informes_incompletos !== null) {
                $permisos = json_decode($user_data['permisos'] ?? '[]', true);
                $esRoot = ($user_data['nivel'] ?? '') === 'root';
                
                if (!$esRoot && !in_array('marcar_incompletos', $permisos)) {
                    ob_end_clean();
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'message' => 'No tienes permiso para marcar informes como incompletos'
                    ]);
                    exit();
                }
            }
            
            // Verificar permiso para asignar/cambiar prioridad
            if ($prioridad !== null && $prioridad !== 'normal') {
                $permisos = json_decode($user_data['permisos'] ?? '[]', true);
                $esRoot = ($user_data['nivel'] ?? '') === 'root';
                
                if (!$esRoot && !in_array('asignar_prioridad', $permisos)) {
                    ob_end_clean();
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'message' => 'No tienes permiso para asignar prioridad a estudios'
                    ]);
                    exit();
                }
            }
            
            // Detectar IDs automáticamente si no se enviaron
            if (!$orthanc_id && !$study_instance_uid) {
                $detected = detectStudyIdType($study_id);
                if ($detected['type'] === 'orthanc') {
                    $orthanc_id = $study_id;
                } else {
                    $study_instance_uid = $study_id;
                }
            }
            
            $save_study_id = $orthanc_id ?? $study_id;
            
            // Determinar usuarios para los cuales crear/actualizar flags
            $user_ids_to_flag = [];
            
            // IMPORTANTE: Si hay informe_id, el flag es individual del usuario actual
            // No debe aplicarse a otros usuarios asignados/derivados
            if ($informe_id) {
                $user_ids_to_flag[] = $current_user_id;
                error_log("[STUDY_FLAGS POST] Flag individual para informe_id=$informe_id - solo para usuario actual: $current_user_id");
            }
            // IMPORTANTE: Si se está cambiando la prioridad (incluyendo a "normal"), 
            // actualizar TODOS los flags existentes del estudio
            // La prioridad es una propiedad del ESTUDIO, no del usuario
            elseif ($prioridad !== null) {
                $isChangingPriority = true;
                // Buscar TODOS los flags existentes para este estudio (sin importar user_id)
                // Esto asegura que cuando se cambia la prioridad, se actualicen TODOS los flags
                $stmt = $db->prepare("
                    SELECT DISTINCT user_id FROM study_flags 
                    WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?)
                ");
                $stmt->execute([$save_study_id, $orthanc_id ?: $save_study_id, $study_instance_uid ?: $save_study_id]);
                $existingFlags = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($existingFlags as $flag) {
                    if (!in_array($flag['user_id'], $user_ids_to_flag)) {
                        $user_ids_to_flag[] = $flag['user_id'];
                    }
                }
                
                // Si no hay flags existentes, agregar usuario actual
                if (empty($user_ids_to_flag)) {
                    $user_ids_to_flag[] = $current_user_id;
                }
            }
            
            // Si no hay flags existentes (o no se está cambiando prioridad), determinar usuarios normalmente
            if (empty($user_ids_to_flag)) {
                // Verificar si el estudio está asignado
                $stmt = $db->prepare("
                    SELECT user_id FROM study_assignments 
                    WHERE study_id = ? AND status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$save_study_id]);
                $assigned_user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($assigned_user) {
                    $user_ids_to_flag[] = $assigned_user['user_id'];
                    
                    // Si se marca como incompleto, también verificar derivaciones
                    if ($informes_incompletos) {
                        $stmt = $db->prepare("
                            SELECT subassigned_to_user_id 
                            FROM study_subassignments 
                            WHERE study_id = ? AND main_user_id = ? AND status = 'active'
                        ");
                        $stmt->execute([$save_study_id, $assigned_user['user_id']]);
                        $subassignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($subassignments as $sub) {
                            if (!in_array($sub['subassigned_to_user_id'], $user_ids_to_flag)) {
                                $user_ids_to_flag[] = $sub['subassigned_to_user_id'];
                            }
                        }
                    }
                } else {
                    // Verificar derivaciones
                    $stmt = $db->prepare("
                        SELECT main_user_id, subassigned_to_user_id 
                        FROM study_subassignments 
                        WHERE study_id = ? AND status = 'active'
                    ");
                    $stmt->execute([$save_study_id]);
                    $subassignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($subassignments)) {
                        foreach ($subassignments as $sub) {
                            if (!in_array($sub['main_user_id'], $user_ids_to_flag)) {
                                $user_ids_to_flag[] = $sub['main_user_id'];
                            }
                            if (!in_array($sub['subassigned_to_user_id'], $user_ids_to_flag)) {
                                $user_ids_to_flag[] = $sub['subassigned_to_user_id'];
                            }
                        }
                    } else {
                        // No asignado ni derivado: flag solo para usuario actual
                        $user_ids_to_flag[] = $current_user_id;
                    }
                }
                
                if (empty($user_ids_to_flag)) {
                    $user_ids_to_flag[] = $current_user_id;
                }
            }
            
            // Crear o actualizar flags para todos los usuarios relevantes
            $results = [];
            
            // IMPORTANTE: Si se está desmarcando informes incompletos (informes_incompletos = false)
            // Si hay informe_id, solo eliminar el flag específico de ese informe
            // Si no hay informe_id, eliminar TODOS los flags del estudio (comportamiento anterior)
            if ($informes_incompletos === false) {
                if ($informe_id) {
                    // Eliminar solo el flag específico del informe
                    $stmt = $db->prepare("
                        DELETE FROM study_flags 
                        WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                        AND informe_id = ?
                        AND informes_incompletos = 1
                    ");
                    $stmt->execute([$save_study_id, $orthanc_id ?: $save_study_id, $study_instance_uid ?: $save_study_id, $informe_id]);
                    $deletedCount = $stmt->rowCount();
                    error_log("[STUDY_FLAGS] Eliminado flag de informes incompletos para informe $informe_id del estudio $save_study_id");
                } else {
                    // Eliminar TODOS los flags del estudio (comportamiento anterior)
                    $stmt = $db->prepare("
                        DELETE FROM study_flags 
                        WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                        AND informe_id IS NULL
                        AND informes_incompletos = 1
                    ");
                    $stmt->execute([$save_study_id, $orthanc_id ?: $save_study_id, $study_instance_uid ?: $save_study_id]);
                    $deletedCount = $stmt->rowCount();
                    error_log("[STUDY_FLAGS] Eliminados $deletedCount flags de informes incompletos para estudio $save_study_id");
                }
                
                // Si se eliminaron flags, retornar éxito sin procesar usuarios individuales
                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'message' => 'Flag de informes incompletos removido',
                    'data' => []
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            foreach ($user_ids_to_flag as $user_id) {
                // Verificar si ya existe un flag
                // Si hay informe_id, buscar flag específico del informe
                // Si no hay informe_id, buscar flag del estudio (comportamiento anterior)
                if ($informe_id) {
                    // IMPORTANTE: Buscar SOLO por informe_id específico, no usar OR
                    // porque eso puede traer flags de otros informes del mismo estudio
                    $stmt = $db->prepare("
                        SELECT id FROM study_flags 
                        WHERE study_id = ?
                        AND user_id = ? 
                        AND informe_id = ?
                    ");
                    $stmt->execute([$save_study_id, $user_id, $informe_id]);
                    error_log("[STUDY_FLAGS POST] Buscando flag existente para study_id=$save_study_id, user_id=$user_id, informe_id=$informe_id");
                } else {
                    $stmt = $db->prepare("
                        SELECT id FROM study_flags 
                        WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                        AND user_id = ? 
                        AND informe_id IS NULL
                    ");
                    $stmt->execute([$save_study_id, $orthanc_id, $study_instance_uid, $user_id]);
                    error_log("[STUDY_FLAGS POST] Buscando flag existente para study_id=$save_study_id, user_id=$user_id, sin informe_id");
                }
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                error_log("[STUDY_FLAGS POST] Flag existente encontrado: " . ($existing ? json_encode($existing) : 'NULL'));
                
                if ($existing) {
                    // Actualizar flag existente
                    // Construir query de actualización dinámicamente
                    $updateFields = [];
                    $updateValues = [];
                    
                    // Actualizar informes_incompletos solo si se envía explícitamente
                    if ($informes_incompletos !== null) {
                        if ($informes_incompletos) {
                            $updateFields[] = "informes_incompletos = 1";
                            if ($nota !== null) {
                                $updateFields[] = "nota = ?";
                                $updateValues[] = $nota;
                            }
                        } else {
                            $updateFields[] = "informes_incompletos = 0";
                            $updateFields[] = "nota = NULL";
                        }
                    }
                    
                    // Actualizar prioridad solo si se envía explícitamente
                    if ($prioridad !== null) {
                        $updateFields[] = "prioridad = ?";
                        $updateValues[] = $prioridad;
                    }
                    
                    // Actualizar IDs alternativos
                    if ($orthanc_id !== null) {
                        $updateFields[] = "orthanc_id = COALESCE(orthanc_id, ?)";
                        $updateValues[] = $orthanc_id;
                    }
                    if ($study_instance_uid !== null) {
                        $updateFields[] = "study_instance_uid = COALESCE(study_instance_uid, ?)";
                        $updateValues[] = $study_instance_uid;
                    }
                    
                    $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
                    $updateValues[] = $existing['id'];
                    
                    if (!empty($updateFields)) {
                        $stmt = $db->prepare("
                            UPDATE study_flags 
                            SET " . implode(', ', $updateFields) . "
                            WHERE id = ?
                        ");
                        $stmt->execute($updateValues);
                    }
                    
                    // Verificar si el flag debe eliminarse (solo si ambos campos están vacíos)
                    $stmt = $db->prepare("
                        SELECT informes_incompletos, prioridad FROM study_flags WHERE id = ?
                    ");
                    $stmt->execute([$existing['id']]);
                    $updatedFlagState = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($updatedFlagState) {
                        $hasInformesIncompletos = (bool)$updatedFlagState['informes_incompletos'];
                        $hasPrioridad = ($updatedFlagState['prioridad'] ?? 'normal') !== 'normal';
                        
                        if (!$hasInformesIncompletos && !$hasPrioridad) {
                            // Eliminar flag si no tiene nada
                            $stmt = $db->prepare("DELETE FROM study_flags WHERE id = ?");
                            $stmt->execute([$existing['id']]);
                        }
                    }
                } else {
                    // Crear nuevo flag si hay algo que guardar
                    $shouldCreate = false;
                    $createInformesIncompletos = 0;
                    $createPrioridad = 'normal';
                    
                    if ($informes_incompletos) {
                        $shouldCreate = true;
                        $createInformesIncompletos = 1;
                    }
                    
                    if ($prioridad && $prioridad !== 'normal') {
                        $shouldCreate = true;
                        $createPrioridad = $prioridad;
                    }
                    
                    if ($shouldCreate) {
                        $stmt = $db->prepare("
                            INSERT INTO study_flags 
                            (study_id, orthanc_id, study_instance_uid, user_id, informe_id, informes_incompletos, nota, prioridad, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $save_study_id, 
                            $orthanc_id, 
                            $study_instance_uid, 
                            $user_id, 
                            $informe_id,
                            $createInformesIncompletos, 
                            $nota, 
                            $createPrioridad, 
                            $current_user_id
                        ]);
                    }
                }
                
                // Obtener flag actualizado para respuesta
                // Si hay informe_id, buscar flag específico del informe
                // Si no hay informe_id, buscar flag del estudio (comportamiento anterior)
                if ($informe_id) {
                    $stmt = $db->prepare("
                        SELECT * FROM study_flags 
                        WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                        AND user_id = ? 
                        AND informe_id = ?
                    ");
                    $stmt->execute([$save_study_id, $orthanc_id, $study_instance_uid, $user_id, $informe_id]);
                } else {
                    $stmt = $db->prepare("
                        SELECT * FROM study_flags 
                        WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                        AND user_id = ? 
                        AND informe_id IS NULL
                    ");
                    $stmt->execute([$save_study_id, $orthanc_id, $study_instance_uid, $user_id]);
                }
                $updatedFlag = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($updatedFlag) {
                    $results[] = [
                        'user_id' => $user_id,
                        'informes_incompletos' => (bool)$updatedFlag['informes_incompletos'],
                        'prioridad' => $updatedFlag['prioridad'] ?? 'normal'
                    ];
                }
            }
            
            // Determinar mensaje según lo que se actualizó
            if ($prioridad !== null) {
                $message = "Prioridad establecida como: " . ucfirst($prioridad);
            } elseif ($informes_incompletos !== null) {
                $message = $informes_incompletos 
                    ? 'Estudio marcado como informes incompletos' 
                    : 'Flag de informes incompletos removido';
            } else {
                $message = 'Flag actualizado';
            }
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => $message,
                'data' => $results
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            ob_end_clean();
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
            break;
    }
    
} catch (Exception $e) {
    ob_end_clean();
    error_log('[STUDY_FLAGS] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

