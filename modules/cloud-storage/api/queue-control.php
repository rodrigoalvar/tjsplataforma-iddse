<?php
/**
 * API para controlar la cola de subida R2
 * Sistema TJSMEDICAL - Cloud Storage Module
 */

// Configurar headers antes de cualquier salida
header('Content-Type: application/json; charset=utf-8');

// Función para enviar respuesta JSON y terminar
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../config/cloud_storage_config.php';
    require_once __DIR__ . '/../../../config/database.php';
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if ($method !== 'POST') {
        sendJsonResponse([
            'success' => false,
            'error' => 'Método no permitido'
        ], 405);
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    switch ($action) {
        case 'stop_worker':
            // Crear archivo de señal para detener el worker
            $lockFile = __DIR__ . '/../../workers/.worker_stop';
            if (touch($lockFile)) {
                sendJsonResponse([
                    'success' => true,
                    'message' => 'Señal de detención enviada al worker'
                ]);
            } else {
                throw new Exception('No se pudo crear archivo de señal');
            }
            break;
            
        case 'cancel_pending':
            // Cancelar trabajos en estado 'uploading' o 'pending'
            $input = json_decode(file_get_contents('php://input'), true);
            $studyIds = $input['study_ids'] ?? [];
            
            if (empty($studyIds)) {
                // Cancelar todos los trabajos en progreso
                $stmt = $db->prepare("
                    UPDATE r2_queue 
                    SET status = 'cancelled', 
                        updated_at = NOW(),
                        last_error = 'Cancelado por el usuario'
                    WHERE status IN ('pending', 'uploading', 'preparando_estudio', 'generando_zip', 'generando_manifest', 'guardando_manifest_en_zip', 'subiendo', 'enviando_a_r2', 'guardado_en_r2')
                ");
                if (!$stmt) {
                    throw new Exception('Error preparando consulta: ' . implode(', ', $db->errorInfo()));
                }
                
                $result = $stmt->execute();
                
                // Verificar errores (incluyendo warnings de MySQL)
                $errorInfo = $stmt->errorInfo();
                if ($errorInfo[0] !== '00000' && $errorInfo[0] !== null) {
                    // Si es un warning de truncamiento de datos, es porque 'cancelled' no está en el ENUM
                    if ($errorInfo[0] === '01000' && strpos($errorInfo[2], 'Data truncated') !== false) {
                        throw new Exception('El estado "cancelled" no está permitido en la columna status. Ejecuta el script SQL: add_cancelled_status_safe.sql');
                    }
                    throw new Exception('Error ejecutando consulta: ' . implode(', ', $errorInfo));
                }
                
                $affected = $stmt->rowCount();
                
                sendJsonResponse([
                    'success' => true,
                    'message' => "Se cancelaron $affected trabajos",
                    'cancelled' => $affected
                ]);
            } else {
                // Validar que todos los IDs sean numéricos
                foreach ($studyIds as $id) {
                    if (!is_numeric($id)) {
                        throw new Exception('ID de cola inválido: ' . $id);
                    }
                }
                
                // Cancelar estudios específicos
                $placeholders = implode(',', array_fill(0, count($studyIds), '?'));
                $stmt = $db->prepare("
                    UPDATE r2_queue 
                    SET status = 'cancelled', 
                        updated_at = NOW(),
                        last_error = 'Cancelado por el usuario'
                    WHERE id IN ($placeholders) AND status IN ('pending', 'uploading', 'preparando_estudio', 'generando_zip', 'generando_manifest', 'guardando_manifest_en_zip', 'subiendo', 'enviando_a_r2', 'guardado_en_r2')
                ");
                if (!$stmt) {
                    throw new Exception('Error preparando consulta: ' . implode(', ', $db->errorInfo()));
                }
                
                $result = $stmt->execute($studyIds);
                
                // Verificar errores (incluyendo warnings de MySQL)
                $errorInfo = $stmt->errorInfo();
                if ($errorInfo[0] !== '00000' && $errorInfo[0] !== null) {
                    // Si es un warning de truncamiento de datos, es porque 'cancelled' no está en el ENUM
                    if ($errorInfo[0] === '01000' && strpos($errorInfo[2], 'Data truncated') !== false) {
                        throw new Exception('El estado "cancelled" no está permitido en la columna status. Ejecuta el script SQL: add_cancelled_status_safe.sql');
                    }
                    throw new Exception('Error ejecutando consulta: ' . implode(', ', $errorInfo));
                }
                
                $affected = $stmt->rowCount();
                
                sendJsonResponse([
                    'success' => true,
                    'message' => "Se cancelaron $affected trabajos",
                    'cancelled' => $affected
                ]);
            }
            break;
            
        case 'clear_queue':
            // Limpiar toda la cola (solo trabajos completados, errores y cancelados)
            $input = json_decode(file_get_contents('php://input'), true);
            $includeActive = $input['include_active'] ?? false;
            
            if ($includeActive) {
                // Eliminar toda la cola
                $stmt = $db->prepare("DELETE FROM r2_queue");
                if (!$stmt) {
                    throw new Exception('Error preparando consulta: ' . implode(', ', $db->errorInfo()));
                }
                
                $result = $stmt->execute();
                if (!$result) {
                    throw new Exception('Error ejecutando consulta: ' . implode(', ', $stmt->errorInfo()));
                }
                
                $deleted = $stmt->rowCount();
                
                sendJsonResponse([
                    'success' => true,
                    'message' => "Se eliminaron $deleted registros de la cola",
                    'deleted' => $deleted
                ]);
            } else {
                // Solo eliminar trabajos finalizados
                $stmt = $db->prepare("
                    DELETE FROM r2_queue 
                    WHERE status IN ('done', 'error', 'cancelled')
                ");
                if (!$stmt) {
                    throw new Exception('Error preparando consulta: ' . implode(', ', $db->errorInfo()));
                }
                
                $result = $stmt->execute();
                if (!$result) {
                    throw new Exception('Error ejecutando consulta: ' . implode(', ', $stmt->errorInfo()));
                }
                
                $deleted = $stmt->rowCount();
                
                sendJsonResponse([
                    'success' => true,
                    'message' => "Se eliminaron $deleted trabajos finalizados",
                    'deleted' => $deleted
                ]);
            }
            break;
            
        case 'delete_study':
            // Eliminar un estudio específico de la cola
            $input = json_decode(file_get_contents('php://input'), true);
            $queueId = $input['queue_id'] ?? null;
            
            if (!$queueId) {
                throw new Exception('ID de cola no especificado');
            }
            
            // Validar que el ID sea numérico
            if (!is_numeric($queueId)) {
                throw new Exception('ID de cola inválido');
            }
            
            $stmt = $db->prepare("DELETE FROM r2_queue WHERE id = ?");
            if (!$stmt) {
                throw new Exception('Error preparando consulta: ' . implode(', ', $db->errorInfo()));
            }
            
            $result = $stmt->execute([$queueId]);
            if (!$result) {
                throw new Exception('Error ejecutando consulta: ' . implode(', ', $stmt->errorInfo()));
            }
            
            sendJsonResponse([
                'success' => true,
                'message' => 'Estudio eliminado de la cola',
                'deleted' => $stmt->rowCount()
            ]);
            break;
            
        case 'retry_study':
            // Reintentar un estudio con error
            $input = json_decode(file_get_contents('php://input'), true);
            $queueId = $input['queue_id'] ?? null;
            
            if (!$queueId) {
                throw new Exception('ID de cola no especificado');
            }
            
            if (!is_numeric($queueId)) {
                throw new Exception('ID de cola inválido');
            }
            
            $stmt = $db->prepare("
                UPDATE r2_queue 
                SET status = 'pending',
                    retry_count = 0,
                    last_error = NULL,
                    updated_at = NOW()
                WHERE id = ? AND status = 'error'
            ");
            if (!$stmt) {
                throw new Exception('Error preparando consulta: ' . implode(', ', $db->errorInfo()));
            }
            
            $result = $stmt->execute([$queueId]);
            if (!$result) {
                throw new Exception('Error ejecutando consulta: ' . implode(', ', $stmt->errorInfo()));
            }
            
            if ($stmt->rowCount() > 0) {
                sendJsonResponse([
                    'success' => true,
                    'message' => 'Estudio marcado para reintento'
                ]);
            } else {
                throw new Exception('No se pudo marcar el estudio para reintento (puede que no esté en estado error)');
            }
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][QUEUE_CONTROL] Error: ' . $e->getMessage());
    error_log('[CLOUD_STORAGE][QUEUE_CONTROL] Trace: ' . $e->getTraceAsString());
    sendJsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
} catch (Error $e) {
    error_log('[CLOUD_STORAGE][QUEUE_CONTROL] Fatal Error: ' . $e->getMessage());
    error_log('[CLOUD_STORAGE][QUEUE_CONTROL] Trace: ' . $e->getTraceAsString());
    sendJsonResponse([
        'success' => false,
        'error' => 'Error interno del servidor: ' . $e->getMessage()
    ], 500);
}
