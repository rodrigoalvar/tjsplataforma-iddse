<?php
/**
 * API para gestión de sesiones móviles
 * Maneja la creación, validación y eliminación de sesiones para captura móvil
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
// Forzar que las respuestas de estado de sesión NO se cacheen (para que el móvil vea siempre el último estudio)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Incluir configuración de base de datos
require_once '../config/database.php';

try {
    // Crear conexión a la base de datos
    $pdo = getDBConnection();
    
    // Crear tabla de sesiones móviles si no existe
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS mobile_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(255) NOT NULL UNIQUE,
            study_id VARCHAR(255),
            workspace_id VARCHAR(255),
            session_type ENUM('study', 'workspace') DEFAULT 'study',
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            status ENUM('active', 'connected', 'expired') DEFAULT 'active',
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            patient_name VARCHAR(255),
            patient_id VARCHAR(255),
            modality VARCHAR(50),
            study_date VARCHAR(50),
            study_description TEXT,
            
            INDEX idx_session_id (session_id),
            INDEX idx_study_id (study_id),
            INDEX idx_workspace_id (workspace_id),
            INDEX idx_expires_at (expires_at),
            FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createTableSQL);
    
    // Agregar columnas workspace_id y session_type si no existen (migración)
    try {
        $pdo->exec("ALTER TABLE mobile_sessions ADD COLUMN workspace_id VARCHAR(255) AFTER study_id");
    } catch (PDOException $e) {
        // Columna ya existe, ignorar
    }
    
    try {
        $pdo->exec("ALTER TABLE mobile_sessions MODIFY COLUMN session_type ENUM('study', 'workspace', 'firma') DEFAULT 'study'");
    } catch (PDOException $e) {
        // Columna ya existe / ya migrada
    }
    
    // Modificar study_id para que sea nullable
    try {
        $pdo->exec("ALTER TABLE mobile_sessions MODIFY study_id VARCHAR(255)");
    } catch (PDOException $e) {
        // Ya es nullable o error, ignorar
    }
    
    // Agregar columna study_updated_at para notificar al móvil cuando el workspace actualiza el estudio
    try {
        $pdo->exec("ALTER TABLE mobile_sessions ADD COLUMN study_updated_at TIMESTAMP NULL DEFAULT NULL AFTER last_activity");
    } catch (PDOException $e) {
        // Columna ya existe, ignorar
    }

    // Agregar columna mobile_last_seen para detectar desconexión del móvil por timeout de polling
    try {
        $pdo->exec("ALTER TABLE mobile_sessions ADD COLUMN mobile_last_seen TIMESTAMP NULL DEFAULT NULL AFTER study_updated_at");
    } catch (PDOException $e) {
        // Columna ya existe, ignorar
    }

    // Agregar columna mobile_recording_status para push de estado desde el móvil
    try {
        $pdo->exec("ALTER TABLE mobile_sessions ADD COLUMN mobile_recording_status VARCHAR(20) NOT NULL DEFAULT 'idle' AFTER mobile_last_seen");
    } catch (PDOException $e) {
        // Columna ya existe, ignorar
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Manejar preflight OPTIONS
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
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
    $sessionId = $_GET['session_id'] ?? null;
    $checkWorkspace = isset($_GET['check_workspace']) && $_GET['check_workspace'] === '1';
    
    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'session_id es requerido']);
        return;
    }
    
    // PRIMERO: Leer la sesión COMPLETA antes de actualizar nada
    // Esto es crítico para verificar si el workspace está activo ANTES de actualizar last_activity
    $readSql = "SELECT *, TIMESTAMPDIFF(SECOND, mobile_last_seen, NOW()) AS seconds_since_mobile_poll FROM mobile_sessions WHERE session_id = ?";
    $readStmt = $pdo->prepare($readSql);
    $readStmt->execute([$sessionId]);
    $session = $readStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Sesión no encontrada']);
        return;
    }
    
    // Verificar si el workspace tiene la sesión activa ANTES de actualizar last_activity
    // Esto es crítico: si el workspace cerró sesión, last_activity será antiguo
    // y NO debemos actualizarlo para que el móvil pueda detectar la desconexión
    $workspaceHasSession = null;
    $workspaceLastActivity = null;
    if ($checkWorkspace && $session['workspace_id']) {
        $workspaceLastActivity = $session['last_activity'];
        
        // El workspace está activo si last_activity es reciente (< 2 minutos)
        // Esto indica que el workspace está haciendo updateMobileSessionStudyData periódicamente
        if ($workspaceLastActivity) {
            $lastActivityTime = strtotime($workspaceLastActivity);
            $now = time();
            $secondsSinceActivity = $now - $lastActivityTime;
            // Si la última actividad fue hace menos de 2 minutos, el workspace está activo
            $workspaceHasSession = ($secondsSinceActivity < 120);
        } else {
            $workspaceHasSession = false;
        }
    }
    
    // IMPORTANTE: El polling del móvil (GET) NUNCA debe actualizar last_activity.
    // last_activity solo debe actualizarlo el workspace (via PUT update_study).
    // Si el móvil actualizara last_activity, el servidor siempre vería actividad reciente
    // y nunca detectaría que el workspace se desconectó (bug circular).
    //
    // La columna tiene ON UPDATE CURRENT_TIMESTAMP, por eso debemos pasar
    // last_activity = last_activity explícitamente para evitar la actualización automática.
    $isSessionExpired = ($session['status'] === 'expired');

    // Solo renovar expires_at para que la sesión no expire mientras el móvil verifica.
    // Preservar last_activity explícitamente para evitar que ON UPDATE lo sobreescriba.
    // Si viene con check_workspace=1 (poll periódico del móvil), actualizar mobile_last_seen
    // para que el workspace pueda detectar cuándo el móvil dejó de responder.
    if ($checkWorkspace) {
        $updateSql = "UPDATE mobile_sessions
                      SET expires_at       = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                          last_activity    = last_activity,
                          mobile_last_seen = NOW()
                      WHERE session_id = ?";
    } else {
        $updateSql = "UPDATE mobile_sessions 
                      SET expires_at    = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                          last_activity = last_activity
                      WHERE session_id = ?";
    }
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([$sessionId]);

    if ($isSessionExpired) {
        error_log("⚠️ Sesión expirada $sessionId - solo renovando expires_at, preservando last_activity antiguo para detección de desconexión");
    }
    
    // Verificar si existe la columna report_finished
    $reportFinished = isset($session['report_finished']) ? (bool)$session['report_finished'] : false;
    
    $response = [
        'success' => true,
        'data' => [
            'session_id' => $session['session_id'],
            'study_id' => $session['study_id'] ?? null,
            'workspace_id' => $session['workspace_id'] ?? null,
            'session_type' => $session['session_type'] ?? 'study',
            'created_by' => $session['created_by'],
            'expires_at' => $session['expires_at'],
            'status' => $session['status'],
            'last_activity' => $session['last_activity'],
            'study_updated_at' => $session['study_updated_at'] ?? null,
            'mobile_last_seen' => $session['mobile_last_seen'] ?? null,
            'seconds_since_mobile_poll' => isset($session['seconds_since_mobile_poll']) ? (int)$session['seconds_since_mobile_poll'] : null,
            'mobile_recording_status' => $session['mobile_recording_status'] ?? 'idle',
            'patient_name' => $session['patient_name'],
            'patient_id' => $session['patient_id'],
            'modality' => $session['modality'],
            'study_date' => $session['study_date'],
            'study_description' => $session['study_description'],
            'report_finished' => $reportFinished
        ]
    ];
    
    if ($checkWorkspace) {
        $response['data']['workspace_has_session'] = $workspaceHasSession;
    }
    
    echo json_encode($response);
}

function handlePostRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        return;
    }
    
    // Acción: cierre explícito de sesión desde el móvil (beforeunload / sendBeacon)
    if (isset($input['action']) && $input['action'] === 'close') {
        $sessionId = $input['session_id'] ?? null;
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'session_id requerido']);
            return;
        }
        // Marcar como expirada en lugar de borrar, para que el workspace detecte el cierre
        $sql = "UPDATE mobile_sessions SET status = 'expired', expires_at = NOW() WHERE session_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sessionId]);
        echo json_encode(['success' => true, 'message' => 'Sesión cerrada']);
        return;
    }

    // Acción: desconectar workspace (cuando se cierra la pestaña del workspace)
    if (isset($input['action']) && $input['action'] === 'disconnect_workspace') {
        $sessionId = $input['session_id'] ?? null;
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'session_id requerido']);
            return;
        }
        // Establecer last_activity a hace 5 minutos para que el móvil detecte la desconexión
        // El móvil verifica si last_activity es < 2 minutos, así que con 5 minutos detectará la desconexión
        $sql = "UPDATE mobile_sessions 
                SET last_activity = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                WHERE session_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sessionId]);
        
        if ($stmt->rowCount() > 0) {
            error_log("✅ Workspace desconectado para sesión móvil $sessionId (pestaña cerrada/oculta)");
            echo json_encode(['success' => true, 'message' => 'Workspace desconectado']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada']);
        }
        return;
    }

    // Manejar acción de actualización de estado (alternativa al PUT para mayor compatibilidad)
    if (isset($input['action']) && $input['action'] === 'update_status') {
        $sessionId = $input['session_id'] ?? null;
        $status = $input['status'] ?? null;
        if (!$sessionId || !$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'session_id y status son requeridos']);
            return;
        }
        
        // Verificar primero si la sesión existe (sin condición de estado)
        $checkStmt = $pdo->prepare("SELECT status FROM mobile_sessions WHERE session_id = ? AND expires_at > NOW()");
        $checkStmt->execute([$sessionId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada o expirada']);
            return;
        }
        
        // Si ya tiene el estado solicitado, responder con éxito (no es error)
        if ($existing['status'] === $status) {
            echo json_encode(['success' => true, 'message' => 'Estado ya es ' . $status]);
            return;
        }
        
        // Actualizar el estado (forzar cambio usando last_activity para asegurar rowCount > 0)
        $sql = "UPDATE mobile_sessions SET status = ?, last_activity = NOW() WHERE session_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$status, $sessionId]);
        echo json_encode(['success' => true, 'message' => 'Estado actualizado a ' . $status]);
        return;
    }
    
    // Intentar obtener usuario desde token de sesión si está disponible
    $userData = null;
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_POST['session_token'] ?? $input['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if ($sessionToken) {
        try {
            require_once '../classes/User.php';
            $user = new User();
            $userData = $user->validateSession($sessionToken);
        } catch (Exception $e) {
            // Ignorar errores de validación de sesión
            error_log('Error validando sesión en mobile_session: ' . $e->getMessage());
        }
    }
    
    $sessionId = $input['session_id'] ?? null;
    $studyId = $input['study_id'] ?? null;
    $workspaceId = $input['workspace_id'] ?? null;
    $sessionType = $input['session_type'] ?? ($workspaceId ? 'workspace' : 'study');
    $createdBy = $input['created_by'] ?? null;
    $expiresAt = $input['expires_at'] ?? null;
    
    // Si tenemos datos del usuario desde el token, usar ese ID (prioridad más alta)
    if ($userData && isset($userData['id'])) {
        $createdBy = $userData['id'];
    }

    // Sesión de captura de firma: generar IDs si faltan; no requiere estudio
    if ($sessionType === 'firma') {
        if (!$sessionId) {
            $sessionId = 'firma_' . bin2hex(random_bytes(8));
        }
        if (!$expiresAt) {
            $expiresAt = date('Y-m-d H:i:s', time() + 900);
        }
        if (!$studyId) {
            $studyId = 'firma-user-' . (int)($createdBy ?: 0);
        }
    }
    
    // Datos del estudio (opcionales)
    $patientName = $input['patient_name'] ?? null;
    $patientId = $input['patient_id'] ?? null;
    $modality = $input['modality'] ?? null;
    $studyDate = $input['study_date'] ?? null;
    $studyDescription = $input['study_description'] ?? null;
    
    if (!$sessionId || !$expiresAt) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'session_id y expires_at son requeridos']);
        return;
    }
    
    // Validar que al menos uno de study_id o workspace_id esté presente (firma ya setea study_id)
    if (!$studyId && !$workspaceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'study_id o workspace_id debe ser proporcionado']);
        return;
    }
    
    // Si no hay created_by, intentar obtenerlo de otra forma
    if (!$createdBy) {
        // Intentar obtener desde cookies
        if (isset($_COOKIE['user_id'])) {
            $createdBy = intval($_COOKIE['user_id']);
        }
    }
    
    // Validar que el usuario existe en la base de datos
    if ($createdBy) {
        $userCheckQuery = "SELECT id FROM usuarios WHERE id = ?";
        $userCheckStmt = $pdo->prepare($userCheckQuery);
        $userCheckStmt->execute([$createdBy]);
        $userExists = $userCheckStmt->fetch();
        
        if (!$userExists) {
            // Si el usuario no existe, intentar usar el primer usuario disponible o NULL
            $firstUserQuery = "SELECT id FROM usuarios ORDER BY id ASC LIMIT 1";
            $firstUserStmt = $pdo->query($firstUserQuery);
            $firstUser = $firstUserStmt->fetch();
            
            if ($firstUser) {
                $createdBy = $firstUser['id'];
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No hay usuarios disponibles en el sistema']);
                return;
            }
        }
    }
    
    // Convertir formato ISO a MySQL DATETIME si es necesario
    // Acepta tanto formato ISO (2025-12-14T01:01:18.957Z) como MySQL (2025-12-14 01:01:18)
    if (strpos($expiresAt, 'T') !== false) {
        // Formato ISO: convertir a MySQL DATETIME
        $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
    }
    
    // Limpiar sesiones expiradas
    $cleanupSql = "DELETE FROM mobile_sessions WHERE expires_at < NOW()";
    $pdo->exec($cleanupSql);
    
    // Crear nueva sesión con datos del estudio o workspace
    $sql = "INSERT INTO mobile_sessions (session_id, study_id, workspace_id, session_type, created_by, expires_at, patient_name, patient_id, modality, study_date, study_description) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $studyId, $workspaceId, $sessionType, $createdBy, $expiresAt, $patientName, $patientId, $modality, $studyDate, $studyDescription]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Sesión móvil creada exitosamente',
        'data' => ['session_id' => $sessionId]
    ]);
}

function handlePutRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        return;
    }
    
    $sessionId = $input['session_id'] ?? null;
    $action = $input['action'] ?? null;
    $status = $input['status'] ?? null;
    
    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'session_id es requerido']);
        return;
    }
    
    // Manejar renovación de sesión
    if ($action === 'renew') {
        // Extender la sesión por 24 horas más
        $newExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $sql = "UPDATE mobile_sessions SET expires_at = ?, last_activity = NOW() WHERE session_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$newExpiresAt, $sessionId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'Sesión renovada exitosamente',
                'data' => ['expires_at' => $newExpiresAt]
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada']);
        }
        return;
    }
    
    // Manejar actualización de datos del estudio
    if ($action === 'update_study') {
        error_log("🔄 UPDATE_STUDY recibido para sesión: $sessionId");
        
        $studyId = $input['study_id'] ?? null;
        $workspaceId = $input['workspace_id'] ?? null;
        $patientName = $input['patient_name'] ?? null;
        $patientId = $input['patient_id'] ?? null;
        $modality = $input['modality'] ?? null;
        $studyDate = $input['study_date'] ?? null;
        $studyDescription = $input['study_description'] ?? null;
        $reportFinished = isset($input['report_finished']) ? (bool)$input['report_finished'] : null;
        
        error_log("📋 Datos recibidos: studyId=$studyId, workspaceId=$workspaceId, patientName=$patientName, patientId=$patientId");
        
        // Construir query dinámicamente - incluir todos los campos proporcionados (incluso null para limpiar)
        $updates = [];
        $params = [];
        
        // Siempre actualizar estos campos si están en el input (incluso si son null)
        if (array_key_exists('study_id', $input)) {
            $updates[] = "study_id = ?";
            $params[] = $studyId;
        }
        if (array_key_exists('workspace_id', $input)) {
            $updates[] = "workspace_id = ?";
            $params[] = $workspaceId;
        }
        if (array_key_exists('patient_name', $input)) {
            $updates[] = "patient_name = ?";
            $params[] = $patientName;
        }
        if (array_key_exists('patient_id', $input)) {
            $updates[] = "patient_id = ?";
            $params[] = $patientId;
        }
        if (array_key_exists('modality', $input)) {
            $updates[] = "modality = ?";
            $params[] = $modality;
        }
        if (array_key_exists('study_date', $input)) {
            $updates[] = "study_date = ?";
            $params[] = $studyDate;
        }
        if (array_key_exists('study_description', $input)) {
            $updates[] = "study_description = ?";
            $params[] = $studyDescription;
        }
        if (array_key_exists('report_finished', $input)) {
            // Agregar columna report_finished si no existe
            try {
                $pdo->exec("ALTER TABLE mobile_sessions ADD COLUMN report_finished BOOLEAN DEFAULT FALSE");
            } catch (PDOException $e) {
                // Columna ya existe, ignorar
            }
            $updates[] = "report_finished = ?";
            $params[] = $reportFinished ? 1 : 0;
        }
        
        if (empty($updates)) {
            error_log("❌ No se proporcionaron datos para actualizar");
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No se proporcionaron datos para actualizar']);
            return;
        }
        
        // Siempre actualizar last_activity y study_updated_at cuando el workspace actualiza el estudio
        // study_updated_at es la "señal" que el móvil usa para detectar cambios
        $updates[] = "last_activity = NOW()";
        $updates[] = "study_updated_at = NOW()";
        
        // PRIMERO: Verificar si la sesión existe (incluso si está expirada)
        // Si existe pero está expirada, la renovamos automáticamente
        $checkStmt = $pdo->prepare("SELECT expires_at FROM mobile_sessions WHERE session_id = ?");
        $checkStmt->execute([$sessionId]);
        $existingSession = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingSession) {
            error_log("❌ Sesión $sessionId no encontrada en la base de datos");
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada']);
            return;
        }
        
        // Si la sesión existe pero está expirada, renovarla automáticamente
        // Esto evita que sesiones activas (que el móvil está usando) se eliminen prematuramente
        $expiresAt = strtotime($existingSession['expires_at']);
        $now = time();
        if ($expiresAt <= $now) {
            error_log("⚠️ Sesión $sessionId expirada (expires_at: {$existingSession['expires_at']}), renovando automáticamente...");
            // Renovar por 24 horas más
            $updates[] = "expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)";
        }
        
        // Ahora actualizar sin la condición de expiración (ya verificamos que existe)
        // Esto asegura que siempre se actualice, incluso si hubo un pequeño desfase de tiempo
        $sql = "UPDATE mobile_sessions SET " . implode(', ', $updates) . " WHERE session_id = ?";
        
        error_log("📝 SQL: $sql");
        error_log("📦 Parámetros antes de agregar sessionId: " . json_encode($params));
        
        // Agregar sessionId al final
        $params[] = $sessionId;
        
        error_log("📦 Parámetros: " . json_encode($params));
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $rowCount = $stmt->rowCount();
        error_log("✅ Filas actualizadas: $rowCount");
        
        // Verificar que la sesión existe después del UPDATE (más confiable que rowCount)
        // rowCount puede ser 0 si los valores no cambiaron, pero la sesión sigue existiendo
        $verifyStmt = $pdo->prepare("SELECT patient_name, patient_id, study_id, workspace_id, study_updated_at, status FROM mobile_sessions WHERE session_id = ?");
        $verifyStmt->execute([$sessionId]);
        $updated = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($updated) {
            // La sesión existe y se actualizó (o ya tenía esos valores)
            error_log("✅ Verificación post-update: " . json_encode($updated));
            error_log("✅ study_updated_at actualizado a: " . ($updated['study_updated_at'] ?? 'NULL'));
            
            echo json_encode([
                'success' => true,
                'message' => 'Datos del estudio actualizados exitosamente',
                'updated_data' => $updated
            ]);
        } else {
            // La sesión no existe (fue eliminada o nunca existió)
            error_log("❌ Sesión $sessionId no encontrada después del UPDATE");
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada']);
        }
        return;
    }
    
    // Manejar push de estado de grabación desde el móvil
    if ($action === 'update_mobile_status') {
        $mobileStatus = $input['mobile_recording_status'] ?? null;
        $allowed = ['idle', 'recording', 'uploading', 'has_unsent'];
        if (!$mobileStatus || !in_array($mobileStatus, $allowed)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'mobile_recording_status inválido. Valores permitidos: idle, recording, uploading, has_unsent']);
            return;
        }
        // Actualizamos solo mobile_recording_status y mobile_last_seen (no last_activity — eso es del workspace)
        $sql = "UPDATE mobile_sessions
                SET mobile_recording_status = ?,
                    mobile_last_seen        = NOW(),
                    expires_at              = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                    last_activity           = last_activity
                WHERE session_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$mobileStatus, $sessionId]);
        echo json_encode(['success' => true, 'message' => "Estado del móvil actualizado a: $mobileStatus"]);
        return;
    }

    // Manejar actualización de estado (comportamiento original)
    if (!$status) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'status es requerido para actualización de estado']);
        return;
    }
    
    // Actualizar estado de sesión
    $sql = "UPDATE mobile_sessions SET status = ?, last_activity = NOW() WHERE session_id = ? AND expires_at > NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $sessionId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Estado de sesión actualizado'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Sesión no encontrada o expirada']);
    }
}

function handleDeleteRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        return;
    }
    
    $sessionId = $input['session_id'] ?? null;
    
    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'session_id es requerido']);
        return;
    }
    
    // Eliminar sesión
    $sql = "DELETE FROM mobile_sessions WHERE session_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Sesión eliminada exitosamente'
    ]);
}
?>
