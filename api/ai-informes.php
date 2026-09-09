<?php
/**
 * API REST para AI Informes (Whisper + Medgemma)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoints:
 * POST api/ai-informes.php?action=transcribe (file=audio)
 * POST api/ai-informes.php?action=generate-report (study_id, template_id, transcription_id)
 * GET  api/ai-informes.php?study_id=XXX
 * GET  api/ai-informes.php (listar todos)
 */

// Aumentar límites de PHP para subida de archivos grandes de audio (hasta 500MB)
// Nota: Estos valores deben configurarse en php.ini o .htaccess (no funcionan con ini_set)
// Los límites reales se configuran en api/.htaccess o php.ini del servidor
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '1800'); // 30 minutos para archivos grandes
ini_set('max_input_time', '1800'); // 30 minutos para procesamiento de entrada

// Solo ejecutar código web si no estamos en CLI (cuando se incluye desde worker)
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Manejar preflight OPTIONS
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/OllamaAiClient.php';
require_once __DIR__ . '/../utils/WhisperClient.php';
require_once __DIR__ . '/../middleware/permissions.php';

// Asegurar que getDBConnection esté disponible
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        $database = new Database();
        return $database->getConnection();
    }
}

// Solo ejecutar código de autenticación y routing si no estamos en CLI
if (php_sapi_name() !== 'cli') {
    try {
        // Validar sesión
        $sessionToken = null;
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $sessionToken = $headers['Authorization'] ?? null;
        }
        
        if (!$sessionToken) {
            $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        }
        
        if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
            $sessionToken = substr($sessionToken, 7);
        }
        
        if (!$sessionToken) {
            $sessionToken = $_COOKIE['session_token'] ?? null;
        }
        
        if (!$sessionToken) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token de autorización requerido']);
            exit();
        }
        
        $user = new User();
        $userData = $user->validateSession($sessionToken);
        
        if (!$userData) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
            exit();
        }
        
        $db = getDBConnection();
        
        if (!$db) {
            throw new Exception('No se pudo conectar a la base de datos');
        }
        
        // Asegurar que las tablas existen
        ensureAiTables($db);
        
        // Obtener acción
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        $studyId = $_GET['study_id'] ?? $_POST['study_id'] ?? null;
        
        // Si no hay acción pero hay study_id, listar transcripciones/informes del estudio
        if (!$action && $studyId) {
            $action = 'list';
        }
        
        // Si no hay acción ni study_id, listar todos
        if (!$action && !$studyId) {
            $action = 'list';
        }
        
        // Procesar acción
        switch ($action) {
        case 'transcribe':
            handleTranscribe($db, $userData['id']);
            break;
            
        case 'generate-report':
            handleGenerateReport($db, $userData['id']);
            break;
            
        case 'list':
            handleList($db, $studyId);
            break;
            
        case 'test-transcribe':
            // Solo ROOT puede usar modo prueba
            if ($userData['nivel'] !== 'root') {
                http_response_code(403);
                throw new Exception('Solo los usuarios ROOT pueden acceder al Modo Prueba.');
            }
            handleTestTranscribe($db);
            break;
            
        case 'test-generate-report':
            // Solo ROOT puede usar modo prueba
            if ($userData['nivel'] !== 'root') {
                http_response_code(403);
                throw new Exception('Solo los usuarios ROOT pueden acceder al Modo Prueba.');
            }
            handleTestGenerateReport($db);
            break;
            
        case 'list-audios':
            handleListAudios($db);
            break;
            
        case 'transcribe-audio':
            handleTranscribeAudio($db, $userData['id']);
            break;
            
        case 'generate-report-from-audio':
            handleGenerateReportFromAudio($db, $userData['id']);
            break;
            
        case 'get-transcription':
            handleGetTranscription($db);
            break;
            
        case 'queue-status':
            handleQueueStatus($db);
            break;
            
        case 'download-audio-mp3':
            handleDownloadAudioMp3($db, $userData);
            break;
            
        case 'queue-list':
            handleQueueList($db);
            break;
            
        case 'queue-batch':
            handleQueueBatch($db, $userData['id']);
            break;
            
        case 'add-to-queue':
            handleAddToQueue($db, $userData['id']);
            break;
            
        case 'process-queue':
            // Permitir llamada desde worker (sin autenticación de sesión web)
            // pero verificar que viene del servidor local
            $isLocalRequest = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) 
                           || (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] === '127.0.0.1');
            
            if (!$isLocalRequest && php_sapi_name() !== 'cli') {
                // Si no es local ni CLI, requiere autenticación normal
                // (para permitir llamadas manuales desde el frontend con permisos)
            }
            handleProcessQueue($db);
            break;
            
        case 'check-cron':
            handleCheckCron();
            break;
            
        case 'list-blocked-transcriptions':
            handleListBlockedTranscriptions($db);
            break;
            
        case 'informe-transcription-status':
            handleInformeTranscriptionStatus($db);
            break;

        case 'transcription-health':
            handleTranscriptionHealth($db);
            break;
            
        case 'cleanup-blocked-transcriptions':
            handleCleanupBlockedTranscriptions($db, $userData['id']);
            break;
            
        case 'update-audio-duration':
            handleUpdateAudioDuration($db);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Acción no válida: ' . ($action ?? 'ninguna')]);
            break;
        }
        
    } catch (Exception $e) {
        error_log("Error en ai-informes.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    
    $code = 500;
    $message = $e->getMessage();
    
    if (strpos($message, 'requerido') !== false || 
        strpos($message, 'inválid') !== false ||
        strpos($message, 'no encontrado') !== false) {
        $code = 400;
    } elseif (strpos($message, 'autorización') !== false || 
              strpos($message, 'sesión') !== false ||
              strpos($message, 'permisos') !== false) {
        $code = 401;
    }
    
        if (!headers_sent()) {
            http_response_code($code);
            echo json_encode([
                'success' => false,
                'message' => $message
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}

/**
 * Transcribir audio
 */
function handleTranscribe($db, $userId) {
    // Verificar errores de subida
    if (!isset($_FILES['file'])) {
        throw new Exception('No se recibió ningún archivo. Verifica que el archivo no exceda el límite del servidor.');
    }
    
    $uploadError = $_FILES['file']['error'];
    if ($uploadError !== UPLOAD_ERR_OK) {
        // Cuando el archivo excede post_max_size, $_FILES puede estar vacío o el tamaño puede ser 0
        $fileSize = 'desconocido';
        if (isset($_FILES['file']['size']) && $_FILES['file']['size'] > 0) {
            $fileSize = round($_FILES['file']['size'] / 1024 / 1024, 2) . ' MB';
        } elseif (isset($_SERVER['CONTENT_LENGTH'])) {
            // Intentar obtener el tamaño desde CONTENT_LENGTH si está disponible
            $contentLength = (int)$_SERVER['CONTENT_LENGTH'];
            if ($contentLength > 0) {
                $fileSize = round($contentLength / 1024 / 1024, 2) . ' MB (aproximado desde CONTENT_LENGTH)';
            }
        }
        
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => "El archivo ({$fileSize}) excede el límite de upload_max_filesize ({$uploadMaxFilesize}) configurado en php.ini. " .
                                   "Para aumentar el límite: " .
                                   "1) Si usas Nginx con PHP-FPM: edita php.ini o la configuración de PHP-FPM y establece upload_max_filesize y post_max_size a 500M. " .
                                   "2) Si usas Apache: edita php.ini o api/.htaccess (verifica que AllowOverride esté habilitado). " .
                                   "3) También verifica la configuración de client_max_body_size en Nginx (debe ser al menos 500M). " .
                                   "Valores actuales: upload_max_filesize={$uploadMaxFilesize}, post_max_size={$postMaxSize}. " .
                                   "Ver documentación en: docs/configurar-php-limites-nginx.md",
            UPLOAD_ERR_FORM_SIZE => "El archivo ({$fileSize}) excede el límite MAX_FILE_SIZE especificado en el formulario",
            UPLOAD_ERR_PARTIAL => 'El archivo fue subido parcialmente. Intenta nuevamente.',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal. Contacta al administrador del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco. Verifica los permisos del directorio temporal.',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo'
        ];
        $errorMsg = $errorMessages[$uploadError] ?? "Error desconocido al subir el archivo (código: $uploadError)";
        throw new Exception($errorMsg);
    }
    
    $studyId = $_POST['study_id'] ?? null;
    if (!$studyId) {
        throw new Exception('study_id es requerido');
    }
    
    // Verificar que el estudio existe
    $stmt = $db->prepare("SELECT id, patient_name_pacs, patient_id_pacs, modality, study_description FROM estudios WHERE id = ?");
    $stmt->execute([$studyId]);
    $study = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$study) {
        throw new Exception('Estudio no encontrado');
    }
    
    // Guardar archivo temporalmente
    $uploadDir = __DIR__ . '/../uploads/audio/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileName = uniqid('audio_') . '_' . basename($_FILES['file']['name']);
    $filePath = $uploadDir . $fileName;
    
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
        throw new Exception('Error al guardar el archivo');
    }
    
    // Obtener configuración de AI
    $config = getAiConfig($db);
    
        // Crear cliente Whisper (whisper.cpp)
        $whisperConfig = [
            'whisper_method' => $config['whisper_method'] ?? 'whisper-server',
            'whisper_api_url' => $config['whisper_api_url'] ?? 'http://localhost:8080',
            'whisper_cli_api_url' => $config['whisper_cli_api_url'] ?? 'http://localhost:3001',
            'whisper_model' => $config['whisper_model'] ?? 'base',
            'whisper_language' => $config['whisper_language'] ?? 'es',
            'whisper_timeout' => 600,  // 10 minutos por defecto
            'ffmpeg_rest_url' => $config['ffmpeg_rest_url'] ?? null,
        ];
        $client = new WhisperClient($whisperConfig);
    
    // Crear registro de transcripción
    $stmt = $db->prepare("
        INSERT INTO ai_transcriptions (study_id, audio_file_path, status, created_by, created_at)
        VALUES (?, ?, 'processing', ?, NOW())
    ");
    $stmt->execute([$studyId, $filePath, $userId]);
    $transcriptionId = $db->lastInsertId();
    
    try {
        // Transcribir
        $startTime = microtime(true);
        $result = $client->transcribeAudio($filePath);
        $processingTime = microtime(true) - $startTime;
        
        if (!$result['success']) {
            // Actualizar estado a failed
            $stmt = $db->prepare("
                UPDATE ai_transcriptions 
                SET status = 'failed', error_message = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$result['error'], $transcriptionId]);
            
            throw new Exception($result['error']);
        }
        
        // Preparar respuesta completa de whisper para debug
        $whisperResponse = json_encode([
            'model'     => $result['model'] ?? null,
            'language'  => $result['language'] ?? null,
            'mode'      => $result['mode'] ?? null,
            'segments'  => $result['segments'] ?? null,
            'stats'     => $result['stats'] ?? null,
            'raw'       => $result['raw_response'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        // Actualizar transcripción
        $stmt = $db->prepare("
            UPDATE ai_transcriptions 
            SET transcription_text = ?, status = 'completed', 
                model_used = ?, processing_time = ?, whisper_response = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $result['text'],
            $result['model'] ?? $config['whisper_model'] ?? 'base',
            round($processingTime, 2),
            $whisperResponse,
            $transcriptionId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Transcripción completada',
            'data' => [
                'transcription_id' => $transcriptionId,
                'text' => $result['text'],
                'processing_time' => round($processingTime, 2)
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        // Actualizar estado a failed
        $stmt = $db->prepare("
            UPDATE ai_transcriptions 
            SET status = 'failed', error_message = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $transcriptionId]);
        
        throw $e;
    }
}

/**
 * Generar informe con Medgemma
 */
function handleGenerateReport($db, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $studyId = $input['study_id'] ?? null;
    $templateId = $input['template_id'] ?? null;
    $transcriptionId = $input['transcription_id'] ?? null;
    
    if (!$studyId) {
        throw new Exception('study_id es requerido');
    }
    
    // Obtener datos del estudio
    $stmt = $db->prepare("
        SELECT id, patient_name_pacs, patient_id_pacs, modality, study_description 
        FROM estudios 
        WHERE id = ?
    ");
    $stmt->execute([$studyId]);
    $study = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$study) {
        throw new Exception('Estudio no encontrado');
    }
    
    // Obtener transcripción si se proporciona
    $transcription = '';
    if ($transcriptionId) {
        $stmt = $db->prepare("SELECT transcription_text FROM ai_transcriptions WHERE id = ? AND study_id = ?");
        $stmt->execute([$transcriptionId, $studyId]);
        $transcriptionData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($transcriptionData) {
            $transcription = $transcriptionData['transcription_text'];
        }
    }
    
    // Obtener plantilla si se proporciona
    $template = '';
    if ($templateId) {
        $stmt = $db->prepare("SELECT contenido FROM plantillas WHERE id = ?");
        $stmt->execute([$templateId]);
        $templateData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($templateData) {
            $template = $templateData['contenido'];
        }
    }
    
    // Obtener configuración de AI
    $config = getAiConfig($db);
    
    // Crear cliente Ollama
    $client = new OllamaAiClient($config);
    
    // Preparar datos para el prompt
    $patientData = trim(($study['patient_name_pacs'] ?? '') . ' ' . ($study['patient_id_pacs'] ?? ''));
    $studyData = trim(($study['modality'] ?? '') . ' - ' . ($study['study_description'] ?? ''));
    
    $promptData = [
        'patient' => $patientData ?: 'No especificado',
        'study' => $studyData ?: 'No especificado',
        'transcription' => $transcription ?: 'No hay transcripción disponible',
        'template' => $template
    ];
    
    // Generar informe
    $startTime = microtime(true);
    $result = $client->generateReport($promptData);
    $processingTime = microtime(true) - $startTime;
    
    if (!$result['success']) {
        throw new Exception($result['error']);
    }
    
    // Guardar informe en base de datos
    $stmt = $db->prepare("
        INSERT INTO ai_reports (study_id, transcription_id, template_id, report_content, 
                               status, model_used, prompt_used, processing_time, created_by)
        VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?)
    ");
    $stmt->execute([
        $studyId,
        $transcriptionId,
        $templateId,
        $result['content'],
        $result['model'] ?? 'medgemma',
        $result['prompt_used'] ?? '',
        round($processingTime, 2),
        $userId
    ]);
    
    $reportId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Informe generado exitosamente',
        'data' => [
            'report_id' => $reportId,
            'content' => $result['content'],
            'text' => $result['content'],
            'processing_time' => round($processingTime, 2),
            'processing_time_formatted' => formatDuration($processingTime),
            'model' => $result['model'] ?? $config['medgemma_model'] ?? 'medgemma'
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Listar transcripciones e informes
 */
function handleList($db, $studyId = null) {
    $results = [
        'transcriptions' => [],
        'reports' => []
    ];
    
    if ($studyId) {
        // Listar transcripciones del estudio
        $stmt = $db->prepare("
            SELECT * FROM ai_transcriptions 
            WHERE study_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$studyId]);
        $results['transcriptions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Listar informes del estudio
        $stmt = $db->prepare("
            SELECT * FROM ai_reports 
            WHERE study_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$studyId]);
        $results['reports'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Listar todos (últimos 50)
        $stmt = $db->prepare("
            SELECT * FROM ai_transcriptions 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmt->execute();
        $results['transcriptions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("
            SELECT * FROM ai_reports 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmt->execute();
        $results['reports'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $results
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Transcribir audio en modo prueba (sin guardar en BD)
 */
function handleTestTranscribe($db) {
    // Verificar errores de subida
    if (!isset($_FILES['file'])) {
        throw new Exception('No se recibió ningún archivo. Verifica que el archivo no exceda el límite del servidor.');
    }
    
    $uploadError = $_FILES['file']['error'];
    if ($uploadError !== UPLOAD_ERR_OK) {
        // Cuando el archivo excede post_max_size, $_FILES puede estar vacío o el tamaño puede ser 0
        $fileSize = 'desconocido';
        if (isset($_FILES['file']['size']) && $_FILES['file']['size'] > 0) {
            $fileSize = round($_FILES['file']['size'] / 1024 / 1024, 2) . ' MB';
        } elseif (isset($_SERVER['CONTENT_LENGTH'])) {
            // Intentar obtener el tamaño desde CONTENT_LENGTH si está disponible
            $contentLength = (int)$_SERVER['CONTENT_LENGTH'];
            if ($contentLength > 0) {
                $fileSize = round($contentLength / 1024 / 1024, 2) . ' MB (aproximado desde CONTENT_LENGTH)';
            }
        }
        
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => "El archivo ({$fileSize}) excede el límite de upload_max_filesize ({$uploadMaxFilesize}) configurado en php.ini. " .
                                   "Para aumentar el límite: " .
                                   "1) Si usas Nginx con PHP-FPM: edita php.ini o la configuración de PHP-FPM y establece upload_max_filesize y post_max_size a 500M. " .
                                   "2) Si usas Apache: edita php.ini o api/.htaccess (verifica que AllowOverride esté habilitado). " .
                                   "3) También verifica la configuración de client_max_body_size en Nginx (debe ser al menos 500M). " .
                                   "Valores actuales: upload_max_filesize={$uploadMaxFilesize}, post_max_size={$postMaxSize}. " .
                                   "Ver documentación en: docs/configurar-php-limites-nginx.md",
            UPLOAD_ERR_FORM_SIZE => "El archivo ({$fileSize}) excede el límite MAX_FILE_SIZE especificado en el formulario",
            UPLOAD_ERR_PARTIAL => 'El archivo fue subido parcialmente. Intenta nuevamente.',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal. Contacta al administrador del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco. Verifica los permisos del directorio temporal.',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo'
        ];
        $errorMsg = $errorMessages[$uploadError] ?? "Error desconocido al subir el archivo (código: $uploadError)";
        throw new Exception($errorMsg);
    }
    
    // Guardar archivo temporalmente (solo para procesamiento)
    $uploadDir = __DIR__ . '/../uploads/audio/temp/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileName = uniqid('test_audio_') . '_' . basename($_FILES['file']['name']);
    $filePath = $uploadDir . $fileName;
    
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
        throw new Exception('Error al guardar el archivo temporal');
    }
    
    try {
        // Obtener configuración de AI
        $config = getAiConfig($db);
        error_log("handleTestTranscribe: Configuración obtenida - whisper_method: " . ($config['whisper_method'] ?? 'no definido') . ", whisper_api_url: " . ($config['whisper_api_url'] ?? 'no definido') . ", whisper_cli_api_url: " . ($config['whisper_cli_api_url'] ?? 'no definido'));
        
        // Crear cliente Whisper (whisper.cpp)
        $whisperConfig = [
            'whisper_method' => $config['whisper_method'] ?? 'whisper-server',
            'whisper_api_url' => $config['whisper_api_url'] ?? 'http://localhost:8080',
            'whisper_cli_api_url' => $config['whisper_cli_api_url'] ?? 'http://localhost:3001',
            'whisper_model' => $config['whisper_model'] ?? 'base',
            'whisper_language' => $config['whisper_language'] ?? 'es',
            'whisper_timeout' => 600,  // 10 minutos por defecto
            'ffmpeg_rest_url' => $config['ffmpeg_rest_url'] ?? null,
        ];
        error_log("handleTestTranscribe: Configuración final para WhisperClient - whisper_method: {$whisperConfig['whisper_method']}, whisper_cli_api_url: {$whisperConfig['whisper_cli_api_url']}");
        $client = new WhisperClient($whisperConfig);
        
        // Transcribir
        $startTime = microtime(true);
        $result = $client->transcribeAudio($filePath);
        $processingTime = microtime(true) - $startTime;
        
        // Eliminar archivo temporal
        @unlink($filePath);
        
        if (!$result['success']) {
            $errorMsg = $result['error'] ?? 'Error desconocido en la transcripción';
            // Incluir información de la URL configurada según el método
            $configuredUrl = ($whisperConfig['whisper_method'] === 'whisper-cli') 
                ? $whisperConfig['whisper_cli_api_url'] 
                : $whisperConfig['whisper_api_url'];
            $errorMsg .= " (Método: {$whisperConfig['whisper_method']}, URL: {$configuredUrl})";
            throw new Exception($errorMsg);
        }
        
        // Verificar que el texto no esté vacío
        $transcriptionText = $result['text'] ?? '';
        if (empty(trim($transcriptionText))) {
            throw new Exception('La transcripción se completó pero el texto está vacío. Esto puede indicar que el proceso aún está corriendo o hubo un error en el servidor whisper.cpp. Verifica los logs del servidor.');
        }
        
        // Preparar datos de respuesta con estadísticas
        $responseData = [
            'text' => $transcriptionText,
            'processing_time' => round($processingTime, 2),
            'processing_time_formatted' => formatDuration($processingTime),
            'model' => $result['model'] ?? $config['whisper_model'] ?? 'base'
        ];
        
        // Agregar estadísticas si están disponibles
        if (isset($result['stats']) && !empty($result['stats'])) {
            $responseData['stats'] = $result['stats'];
            if (isset($result['stats']['audio_duration_s'])) {
                $responseData['audio_duration'] = $result['stats']['audio_duration_s'];
                $responseData['audio_duration_formatted'] = $result['stats']['audio_duration_formatted'] ?? formatDuration($result['stats']['audio_duration_s']);
            }
        }
        
        // Incluir segments con timestamps si están disponibles
        // Verificar múltiples ubicaciones posibles
        $segments = null;
        
        // Debug: Log completo de la estructura de result
        error_log("API handleTestTranscribe: Estructura completa de result: " . json_encode(array_keys($result ?? [])));
        if (isset($result['raw_response'])) {
            error_log("API handleTestTranscribe: raw_response keys: " . json_encode(array_keys($result['raw_response'] ?? [])));
            if (isset($result['raw_response']['segments'])) {
                error_log("API handleTestTranscribe: raw_response['segments'] encontrado: " . count($result['raw_response']['segments']) . " segmentos");
            }
        }
        
        // Buscar segments en múltiples ubicaciones
        if (isset($result['segments']) && is_array($result['segments']) && !empty($result['segments'])) {
            $segments = $result['segments'];
            error_log("API: Segments encontrados en result['segments']: " . count($segments) . " segmentos");
        } elseif (isset($result['raw_response']['segments']) && is_array($result['raw_response']['segments']) && !empty($result['raw_response']['segments'])) {
            $segments = $result['raw_response']['segments'];
            error_log("API: Segments encontrados en result['raw_response']['segments']: " . count($segments) . " segmentos");
        } elseif (isset($result['raw_response']['transcription']['segments']) && is_array($result['raw_response']['transcription']['segments']) && !empty($result['raw_response']['transcription']['segments'])) {
            $segments = $result['raw_response']['transcription']['segments'];
            error_log("API: Segments encontrados en result['raw_response']['transcription']['segments']: " . count($segments) . " segmentos");
        } else {
            error_log("API: No se encontraron segments. Claves en result: " . json_encode(array_keys($result ?? [])));
            if (isset($result['raw_response'])) {
                error_log("API: Claves en raw_response: " . json_encode(array_keys($result['raw_response'] ?? [])));
                // Log de muestra del raw_response para debugging (primeros 500 caracteres)
                error_log("API: Muestra de raw_response: " . substr(json_encode($result['raw_response']), 0, 500));
            }
        }
        
        if ($segments) {
            $responseData['segments'] = $segments;
            error_log("API: Segments incluidos en responseData: " . count($segments) . " segmentos");
        } else {
            error_log("API: No se incluyeron segments en responseData porque no se encontraron");
            // No es un error crítico - la transcripción funciona sin segments
            // Solo no se podrán mostrar timestamps
        }
        
        // La transcripción es exitosa incluso sin segments
        echo json_encode([
            'success' => true,
            'message' => 'Transcripción completada (modo prueba)',
            'data' => $responseData
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        // Asegurar que el archivo temporal se elimine incluso si hay error
        @unlink($filePath);
        
        // Log del error
        error_log("Error en handleTestTranscribe: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // Devolver error en formato JSON
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

/**
 * Generar informe en modo prueba (sin guardar en BD)
 */
function handleTestGenerateReport($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos');
    }
    
    $transcription = $input['transcription'] ?? '';
    $templateId = $input['template_id'] ?? null;
    
    if (empty($transcription)) {
        throw new Exception('Transcripción requerida');
    }
    
    // Obtener plantilla si se proporciona
    $template = '';
    if ($templateId) {
        $stmt = $db->prepare("SELECT contenido FROM plantillas WHERE id = ?");
        $stmt->execute([$templateId]);
        $templateData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($templateData) {
            $template = $templateData['contenido'];
        }
    }
    
    // Obtener configuración de AI
    $config = getAiConfig($db);
    
    // Crear cliente Ollama
    $client = new OllamaAiClient($config);
    
    // Preparar datos para el prompt (modo prueba - datos ficticios)
    $promptData = [
        'patient' => 'Paciente de Prueba',
        'study' => 'Estudio de Prueba',
        'transcription' => $transcription,
        'template' => $template ?: 'Sin plantilla'
    ];
    
    // Generar informe
    $startTime = microtime(true);
    $result = $client->generateReport($promptData);
    $processingTime = microtime(true) - $startTime;
    
    if (!$result['success']) {
        throw new Exception($result['error'] ?? 'Error desconocido al generar el informe');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Informe generado (modo prueba)',
        'data' => [
            'text' => $result['content'] ?? $result['text'] ?? '',
            'processing_time' => round($processingTime, 2),
            'processing_time_formatted' => formatDuration($processingTime),
            'model' => $result['model'] ?? $config['medgemma_model'] ?? 'medgemma'
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Obtener configuración de AI
 */
function getAiConfig($db) {
    $stmt = $db->prepare("SELECT * FROM ai_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // Configuración por defecto
        return [
            'ollama_base_url' => 'http://localhost:11434',
            'whisper_api_url' => 'http://localhost:8080',
            'whisper_method' => 'whisper-server',
            'whisper_cli_api_url' => 'http://localhost:3001',
            'whisper_model' => 'base',
            'whisper_language' => 'es',
            'whisper_timeout' => 600,  // 10 minutos por defecto,
            'ffmpeg_rest_url' => null,
            'medgemma_model' => 'medgemma',
            'timeout' => 600  // 10 minutos por defecto para generación de informes
        ];
    }
    
    // Asegurar que los campos de whisper existan (compatibilidad)
    if (!isset($config['whisper_api_url'])) {
        $config['whisper_api_url'] = 'http://localhost:8080';
    }
    if (!isset($config['whisper_language'])) {
        $config['whisper_language'] = 'es';
    }
    if (!isset($config['whisper_timeout'])) {
        $config['whisper_timeout'] = 600;  // 10 minutos por defecto
    }
    if (!isset($config['whisper_method'])) {
        $config['whisper_method'] = 'whisper-server';
    }
    if (!isset($config['whisper_cli_api_url'])) {
        $config['whisper_cli_api_url'] = 'http://localhost:3001';
    }
    
    return [
        'ollama_base_url' => $config['ollama_base_url'],
        'whisper_api_url' => $config['whisper_api_url'] ?? 'http://localhost:8080',
        'whisper_method' => $config['whisper_method'] ?? 'whisper-server',
        'whisper_cli_api_url' => $config['whisper_cli_api_url'] ?? 'http://localhost:3001',
        'whisper_model' => $config['whisper_model'] ?? 'base',
        'whisper_language' => $config['whisper_language'] ?? 'es',
        'whisper_timeout' => 600,  // 10 minutos por defecto,
        'ffmpeg_rest_url' => $config['ffmpeg_rest_url'] ?? null,
        'medgemma_model' => $config['medgemma_model'],
        'timeout' => isset($config['timeout']) && $config['timeout'] > 0 ? $config['timeout'] : 600,  // Mínimo 10 minutos para generación de informes
        'custom_prompt' => $config['default_prompt'] ?? $config['custom_prompt'] ?? null,
        'auto_transcribe_enabled' => isset($config['auto_transcribe_enabled']) ? (bool)$config['auto_transcribe_enabled'] : false,
        'max_concurrent_transcriptions' => isset($config['max_concurrent_transcriptions']) ? intval($config['max_concurrent_transcriptions']) : 1
    ];
}

/**
 * Listar audios agrupados por estudio
 */
function handleListAudios($db) {
    try {
        // Verificar que las tablas existan
        $checkTables = $db->query("SHOW TABLES LIKE 'audios_informe'");
        if ($checkTables->rowCount() === 0) {
            echo json_encode([
                'success' => true,
                'data' => [],
                'message' => 'La tabla audios_informe no existe. No hay audios en el sistema.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Obtener todos los audios activos agrupados por estudio
        // Buscar estudio directamente o a través del informe si existe
        // Usar subconsultas para obtener la transcripción e informe más recientes de cada audio
        // Usar funciones de agregación para compatibilidad con sql_mode=only_full_group_by
        $query = "
            SELECT 
                ai.id as audio_id,
                ai.estudio_id as orthanc_study_id,
                ai.informe_id,
                ai.usuario_id as audio_usuario_id,
                ai.nombre_archivo,
                ai.nombre_original,
                ai.ruta_archivo,
                ai.duracion_segundos,
                ai.tamano_bytes,
                ai.tipo_mime,
                ai.fecha_creacion as audio_fecha_creacion,
                ai.transcripcion_texto as audio_transcripcion_texto,
                COALESCE(MAX(e.id), MAX(e2.id)) as estudio_id,
                COALESCE(MAX(e.patient_id_pacs), MAX(i.patient_id)) as patient_id_pacs,
                COALESCE(MAX(e.patient_name_pacs), MAX(i.patient_name)) as patient_name_pacs,
                COALESCE(MAX(e.modality), MAX(i.modality)) as modality,
                COALESCE(MAX(e.study_description), MAX(i.study_description)) as study_description,
                COALESCE(MAX(e.study_date), DATE(MAX(i.fecha_creacion))) as study_date,
                COALESCE(MAX(e.orthanc_study_id), MAX(ai.estudio_id)) as estudio_orthanc_id,
                -- Datos del usuario que dictó el audio
                MAX(u.nombre) as usuario_nombre,
                MAX(u.apellido) as usuario_apellido,
                -- Obtener la transcripción más reciente para este audio
                (SELECT at.id FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                 ORDER BY at.created_at DESC LIMIT 1) as transcription_id,
                (SELECT at.transcription_text FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                 ORDER BY at.created_at DESC LIMIT 1) as transcription_text,
                (SELECT at.status FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                 ORDER BY at.created_at DESC LIMIT 1) as transcription_status,
                (SELECT at.processing_time FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                 ORDER BY at.created_at DESC LIMIT 1) as transcription_processing_time,
                (SELECT at.model_used FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                 ORDER BY at.created_at DESC LIMIT 1) as transcription_model,
                (SELECT at.created_at FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                 ORDER BY at.created_at DESC LIMIT 1) as transcription_created_at,
                -- Obtener el informe más reciente para este audio
                (SELECT ar.id FROM ai_reports ar 
                 WHERE ar.audio_id = ai.id 
                 ORDER BY ar.created_at DESC LIMIT 1) as report_id,
                (SELECT ar.report_content FROM ai_reports ar 
                 WHERE ar.audio_id = ai.id 
                 ORDER BY ar.created_at DESC LIMIT 1) as report_content,
                (SELECT ar.status FROM ai_reports ar 
                 WHERE ar.audio_id = ai.id 
                 ORDER BY ar.created_at DESC LIMIT 1) as report_status,
                (SELECT ar.processing_time FROM ai_reports ar 
                 WHERE ar.audio_id = ai.id 
                 ORDER BY ar.created_at DESC LIMIT 1) as report_processing_time,
                (SELECT ar.model_used FROM ai_reports ar 
                 WHERE ar.audio_id = ai.id 
                 ORDER BY ar.created_at DESC LIMIT 1) as report_model,
                (SELECT ar.created_at FROM ai_reports ar 
                 WHERE ar.audio_id = ai.id 
                 ORDER BY ar.created_at DESC LIMIT 1) as report_created_at
            FROM audios_informe ai
            LEFT JOIN estudios e ON ai.estudio_id = e.orthanc_study_id
            LEFT JOIN informes i ON ai.informe_id = i.id
            LEFT JOIN estudios e2 ON i.estudio_id = e2.orthanc_study_id
            LEFT JOIN usuarios u ON ai.usuario_id = u.id
            WHERE ai.activo = 1
            GROUP BY ai.id, ai.estudio_id, ai.informe_id, ai.usuario_id, ai.nombre_archivo, ai.nombre_original, 
                     ai.ruta_archivo, ai.duracion_segundos, ai.tamano_bytes, ai.tipo_mime, 
                     ai.fecha_creacion, ai.transcripcion_texto
            ORDER BY COALESCE(MAX(e.id), MAX(e2.id), 0), ai.fecha_creacion DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error en handleListAudios: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error al consultar audios: ' . $e->getMessage(),
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Agrupar por estudio
    $estudiosMap = [];
    foreach ($audios as $audio) {
        // Determinar el estudio_id a usar para agrupar
        // Prioridad: 1) estudio_id de la tabla estudios, 2) buscar por orthanc_study_id
        $estudioId = $audio['estudio_id'];
        $orthancId = $audio['estudio_orthanc_id'] ?: $audio['orthanc_study_id'];
        
        // Si no hay estudio_id pero hay orthanc_study_id, buscar el estudio por orthanc_study_id
        if (!$estudioId && $orthancId) {
            try {
                $findStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                $findStmt->execute([$orthancId]);
                $foundStudy = $findStmt->fetch(PDO::FETCH_ASSOC);
                if ($foundStudy) {
                    $estudioId = $foundStudy['id'];
                } else {
                    // Si no se encuentra, usar el orthanc_study_id como clave temporal
                    $estudioId = 'orthanc_' . md5($orthancId);
                }
            } catch (PDOException $e) {
                // Si falla la búsqueda, usar el orthanc_study_id como clave temporal
                $estudioId = 'orthanc_' . md5($orthancId);
            }
        }
        
        // Si aún no hay estudio_id, usar el informe_id como último recurso
        if (!$estudioId && $audio['informe_id']) {
            $estudioId = 'informe_' . $audio['informe_id'];
        }
        
        // Si aún no hay nada, usar el audio_id
        if (!$estudioId) {
            $estudioId = 'audio_' . $audio['audio_id'];
        }
        
        if (!isset($estudiosMap[$estudioId])) {
            // Si el estudio_id es numérico y no es una clave temporal, es un ID real de la tabla estudios
            $isRealStudyId = is_numeric($estudioId) && 
                            $estudioId > 0 && 
                            !str_starts_with((string)$estudioId, 'orthanc_') && 
                            !str_starts_with((string)$estudioId, 'informe_') && 
                            !str_starts_with((string)$estudioId, 'audio_');
            
            $estudiosMap[$estudioId] = [
                'estudio' => [
                    'id' => $isRealStudyId ? intval($estudioId) : null,
                    'orthanc_study_id' => $orthancId,
                    'patient_id_pacs' => $audio['patient_id_pacs'],
                    'patient_name_pacs' => $audio['patient_name_pacs'] ?: 'Sin paciente',
                    'modality' => $audio['modality'] ?: 'N/A',
                    'study_description' => $audio['study_description'] ?: 'Sin descripción',
                    'study_date' => $audio['study_date']
                ],
                'audios' => []
            ];
        }
        
        // Preparar datos del audio
        $audioData = [
            'id' => $audio['audio_id'],
            'nombre_archivo' => $audio['nombre_archivo'],
            'nombre_original' => $audio['nombre_original'],
            'ruta_archivo' => $audio['ruta_archivo'],
            'duracion_segundos' => $audio['duracion_segundos'],
            'tamano_bytes' => $audio['tamano_bytes'],
            'tipo_mime' => $audio['tipo_mime'],
            'fecha_creacion' => $audio['audio_fecha_creacion'],
            'estudio_id' => $audio['estudio_id'], // ID numérico del estudio (puede ser null)
            'orthanc_study_id' => $audio['estudio_orthanc_id'], // ID de Orthanc del estudio
            'informe_id' => $audio['informe_id'], // ID del informe asociado (puede ser null)
            'usuario_id' => $audio['audio_usuario_id'] ?? null, // ID del usuario que dictó el audio
            'usuario_nombre' => $audio['usuario_nombre'] ?? null, // Nombre del usuario
            'usuario_apellido' => $audio['usuario_apellido'] ?? null, // Apellido del usuario
            'transcription' => null,
            'report' => null
        ];
        
        // Agregar transcripción si existe
        if ($audio['transcription_id']) {
            $audioData['transcription'] = [
                'id' => $audio['transcription_id'],
                'transcription_text' => $audio['transcription_text'],
                'status' => $audio['transcription_status'],
                'processing_time' => $audio['transcription_processing_time'],
                'model_used' => $audio['transcription_model'],
                'created_at' => $audio['transcription_created_at']
            ];
        }
        
        // Agregar informe si existe
        if ($audio['report_id']) {
            $audioData['report'] = [
                'id' => $audio['report_id'],
                'report_content' => $audio['report_content'],
                'status' => $audio['report_status'],
                'processing_time' => $audio['report_processing_time'],
                'model_used' => $audio['report_model'],
                'created_at' => $audio['report_created_at']
            ];
        }
        
        $estudiosMap[$estudioId]['audios'][] = $audioData;
    }
    
    // Convertir a array indexado y ordenar por estudio_id (numérico primero)
    $result = array_values($estudiosMap);
    
    // Ordenar: estudios con ID numérico primero, luego los temporales
    usort($result, function($a, $b) {
        $idA = $a['estudio']['id'];
        $idB = $b['estudio']['id'];
        
        // Si ambos son numéricos, ordenar numéricamente
        if (is_numeric($idA) && is_numeric($idB)) {
            return intval($idA) - intval($idB);
        }
        // Si solo A es numérico, A va primero
        if (is_numeric($idA)) return -1;
        // Si solo B es numérico, B va primero
        if (is_numeric($idB)) return 1;
        // Si ninguno es numérico, ordenar alfabéticamente
        return strcmp($idA ?? '', $idB ?? '');
    });
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Obtener transcripción completa por ID
 */
function handleGetTranscription($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['transcription_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de transcripción requerido'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $transcriptionId = intval($input['transcription_id']);
    
    try {
        $stmt = $db->prepare("
            SELECT 
                at.*,
                ai.id as audio_id,
                ai.nombre_original,
                ai.nombre_archivo,
                ai.ruta_archivo,
                ai.duracion_segundos,
                at.updated_at
            FROM ai_transcriptions at
            LEFT JOIN audios_informe ai ON at.audio_id = ai.id
            WHERE at.id = ?
        ");
        $stmt->execute([$transcriptionId]);
        $transcription = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transcription) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Transcripción no encontrada'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Decodificar whisper_response si existe
        $whisperResponse = null;
        if (!empty($transcription['whisper_response'])) {
            $whisperResponse = json_decode($transcription['whisper_response'], true);
        }

        // Construir URL del audio
        $audioUrl = null;
        if (!empty($transcription['ruta_archivo'])) {
            $audioUrl = $transcription['ruta_archivo'];
        } elseif (!empty($transcription['nombre_archivo'])) {
            $audioUrl = 'uploads/audios/' . $transcription['nombre_archivo'];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id'               => $transcription['id'],
                'transcription_text' => $transcription['transcription_text'],
                'status'           => $transcription['status'],
                'processing_time'  => $transcription['processing_time'],
                'model_used'       => $transcription['model_used'],
                'created_at'       => $transcription['created_at'],
                'updated_at'       => $transcription['updated_at'] ?? $transcription['created_at'],
                'audio_id'         => $transcription['audio_id'],
                'audio_name'       => $transcription['nombre_original'] ?? $transcription['nombre_archivo'] ?? 'Sin nombre',
                'audio_url'        => $audioUrl,
                'audio_duration'   => $transcription['duracion_segundos'],
                'segments'         => $whisperResponse['segments'] ?? null,
                'whisper_response' => $whisperResponse,
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (PDOException $e) {
        error_log("Error en handleGetTranscription: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener transcripción: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Transcribir audio específico desde audios_informe
 */
function handleTranscribeAudio($db, $userId) {
    // Verificar permiso para transcribir con AI
    $permissionManager = new PermissionManager();
    if (!$permissionManager->hasPermission('transcribir_ai', $userId)) {
        http_response_code(403);
        throw new Exception('No tienes permisos para transcribir audios con AI. Contacta al administrador para habilitar el permiso "Transcribir con AI".');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['audio_id'])) {
        throw new Exception('audio_id es requerido');
    }
    
    $audioId = intval($input['audio_id']);
    $studyId = isset($input['study_id']) ? intval($input['study_id']) : null;
    $orthancStudyId = isset($input['orthanc_study_id']) ? $input['orthanc_study_id'] : null;
    
    // Validar retranscribe de forma más robusta
    $retranscribe = false;
    if (isset($input['retranscribe'])) {
        $retranscribeValue = $input['retranscribe'];
        $retranscribe = (
            $retranscribeValue === true || 
            $retranscribeValue === 'true' || 
            $retranscribeValue === 1 || 
            $retranscribeValue === '1' ||
            $retranscribeValue === 'yes' ||
            $retranscribeValue === 'YES'
        );
    }
    
    // Log para depuración
    error_log("handleTranscribeAudio - audio_id: {$audioId}, retranscribe: " . ($retranscribe ? 'true' : 'false') . ", input retranscribe: " . (isset($input['retranscribe']) ? var_export($input['retranscribe'], true) : 'no definido'));
    
    // Primero verificar si el audio existe y está activo
    $checkAudioStmt = $db->prepare("
        SELECT id, activo, estudio_id, ruta_archivo
        FROM audios_informe
        WHERE id = ?
    ");
    $checkAudioStmt->execute([$audioId]);
    $audioCheck = $checkAudioStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$audioCheck) {
        throw new Exception('Audio no encontrado (ID: ' . $audioId . ')');
    }
    
    if ($audioCheck['activo'] != 1) {
        throw new Exception('El audio está inactivo (ID: ' . $audioId . ')');
    }
    
    // Buscar audio con información del estudio y del informe (usando LEFT JOIN para no fallar si no hay estudio)
    $stmt = $db->prepare("
        SELECT 
            ai.*, 
            e.id as estudio_id_int, 
            e.study_instance_uid,
            i.estudio_id as informe_estudio_id,
            i.patient_id as informe_patient_id,
            i.patient_name as informe_patient_name,
            i.modality as informe_modality,
            i.study_description as informe_study_description,
            i.study_instance_uid as informe_study_instance_uid,
            i.accession_number as informe_accession_number,
            i.fecha_creacion as informe_fecha_creacion
        FROM audios_informe ai
        LEFT JOIN estudios e ON ai.estudio_id = e.orthanc_study_id
        LEFT JOIN informes i ON ai.informe_id = i.id
        WHERE ai.id = ? AND ai.activo = 1
    ");
    $stmt->execute([$audioId]);
    $audio = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$audio) {
        throw new Exception('Error al obtener información del audio (ID: ' . $audioId . ')');
    }
    
    // Determinar el orthanc_study_id a usar (prioridad: el que viene del frontend, luego el del audio)
    $orthancIdToSearch = $orthancStudyId ? $orthancStudyId : ($audio['estudio_id'] ?? null);
    
    // Buscar el estudio usando el orthanc_study_id
    // Prioridad 1: Usar study_id proporcionado desde el frontend (si es válido)
    if ($studyId && $studyId > 0) {
        // Verificar que el study_id corresponde al orthanc_study_id
        $verifyStmt = $db->prepare("
            SELECT id FROM estudios WHERE id = ? AND orthanc_study_id = ? LIMIT 1
        ");
        $verifyStmt->execute([$studyId, $orthancIdToSearch]);
        $verified = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$verified) {
            // El study_id no coincide, buscar de nuevo
            $studyId = null;
        }
    }
    
    // Prioridad 2: Usar study_id del JOIN si está disponible
    if (!$studyId && isset($audio['estudio_id_int']) && $audio['estudio_id_int']) {
        $studyId = $audio['estudio_id_int'];
    }
    
    // Prioridad 3: Buscar por orthanc_study_id
    if ((!$studyId || $studyId <= 0) && $orthancIdToSearch) {
        $studyStmt = $db->prepare("
            SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1
        ");
        $studyStmt->execute([$orthancIdToSearch]);
        $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
        if ($study) {
            $studyId = $study['id'];
        }
    }
    
    // Prioridad 4: Buscar por informe_id si el audio tiene un informe asociado
    if ((!$studyId || $studyId <= 0) && isset($audio['informe_id']) && $audio['informe_id']) {
        $informeStmt = $db->prepare("
            SELECT estudio_id FROM informes WHERE id = ? LIMIT 1
        ");
        $informeStmt->execute([$audio['informe_id']]);
        $informe = $informeStmt->fetch(PDO::FETCH_ASSOC);
        if ($informe && $informe['estudio_id']) {
            $studyStmt2 = $db->prepare("
                SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1
            ");
            $studyStmt2->execute([$informe['estudio_id']]);
            $study2 = $studyStmt2->fetch(PDO::FETCH_ASSOC);
            if ($study2) {
                $studyId = $study2['id'];
            }
        }
    }
    
    // Si aún no encontramos un study_id válido, crear el estudio usando datos del informe
    if (!$studyId || $studyId <= 0) {
        if ($orthancIdToSearch) {
            // Usar datos del informe si está disponible
            $informeEstudioId = $audio['informe_estudio_id'] ?? null;
            $informePatientId = $audio['informe_patient_id'] ?? null;
            $informePatientName = $audio['informe_patient_name'] ?? null;
            $informeModality = $audio['informe_modality'] ?? null;
            $informeStudyDescription = $audio['informe_study_description'] ?? null;
            $informeStudyInstanceUID = $audio['informe_study_instance_uid'] ?? null;
            $informeAccessionNumber = $audio['informe_accession_number'] ?? null;
            $informeFechaCreacion = $audio['informe_fecha_creacion'] ?? null;
            
            // Si el informe tiene estudio_id, usar ese como orthanc_study_id
            if ($informeEstudioId && $informeEstudioId !== $orthancIdToSearch) {
                // Verificar si el estudio del informe existe en la tabla estudios
                $checkInformeStudyStmt = $db->prepare("
                    SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1
                ");
                $checkInformeStudyStmt->execute([$informeEstudioId]);
                $informeStudy = $checkInformeStudyStmt->fetch(PDO::FETCH_ASSOC);
                if ($informeStudy) {
                    $studyId = $informeStudy['id'];
                } else {
                    // Usar el estudio_id del informe como orthanc_study_id para crear el estudio
                    $orthancIdToSearch = $informeEstudioId;
                }
            }
            
            // Si aún no hay study_id, crear el estudio usando datos del informe
            if (!$studyId || $studyId <= 0) {
                try {
                    // Parsear fecha del informe si está disponible
                    $studyDate = null;
                    if ($informeFechaCreacion) {
                        $studyDate = date('Y-m-d', strtotime($informeFechaCreacion));
                    }
                    
                    // Crear el estudio en la base de datos usando datos del informe
                    $createStudyStmt = $db->prepare("
                        INSERT INTO estudios 
                        (orthanc_study_id, patient_id_pacs, patient_name_pacs, modality, study_description, 
                         study_date, study_instance_uid, accession_number, status, fecha_creacion)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETADO', NOW())
                    ");
                    
                    $createStudyStmt->execute([
                        $orthancIdToSearch,
                        $informePatientId,
                        $informePatientName,
                        $informeModality ?: 'UNKNOWN',
                        $informeStudyDescription,
                        $studyDate,
                        $informeStudyInstanceUID,
                        $informeAccessionNumber,
                    ]);
                    
                    $studyId = $db->lastInsertId();
                    error_log("Estudio creado automáticamente desde datos del informe: ID={$studyId}, orthanc_study_id={$orthancIdToSearch}, informe_id={$audio['informe_id']}");
                } catch (Exception $e) {
                    // Si falla la creación, lanzar error descriptivo
                    $errorMsg = 'No se encontró un estudio válido en la base de datos y no se pudo crear desde los datos del informe. ';
                    $errorMsg .= 'El audio está asociado al estudio Orthanc: "' . $orthancIdToSearch . '". ';
                    if ($audio['informe_id']) {
                        $errorMsg .= 'Informe ID: ' . $audio['informe_id'] . '. ';
                    }
                    $errorMsg .= 'Error: ' . $e->getMessage();
                    throw new Exception($errorMsg);
                }
            }
        } else {
            throw new Exception('No se puede transcribir el audio: falta el identificador del estudio (orthanc_study_id). El audio no tiene un estudio asociado válido.');
        }
    }
    
    // Verificar si ya existe una transcripción en proceso o completada para este audio
    $checkStmt = $db->prepare("
        SELECT id, status, updated_at, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) as minutes_ago
        FROM ai_transcriptions 
        WHERE audio_id = ? AND status IN ('pending', 'processing', 'completed')
        ORDER BY created_at DESC LIMIT 1
    ");
    $checkStmt->execute([$audioId]);
    $existingTranscription = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingTranscription) {
        if ($existingTranscription['status'] === 'processing') {
            // Verificar si la transcripción está bloqueada (>3 minutos sin actualizar)
            $minutesAgo = intval($existingTranscription['minutes_ago'] ?? 0);
            
            if ($minutesAgo >= 3) {
                // Transcripción bloqueada: marcarla como fallida automáticamente
                error_log("Transcripción ID {$existingTranscription['id']} parece estar bloqueada (más de 3 minutos sin actualizar). Marcando como fallida automáticamente.");
                $updateStmt = $db->prepare("
                    UPDATE ai_transcriptions 
                    SET status = 'failed', 
                        error_message = 'Transcripción bloqueada o interrumpida (más de 3 minutos sin actualizar)',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$existingTranscription['id']]);
                // Continuar con la nueva transcripción (no lanzar error)
            } elseif (!$retranscribe) {
                // Transcripción en proceso y tiene menos de 3 minutos: lanzar error
                throw new Exception('Ya hay una transcripción en proceso para este audio. Si lleva más de 3 minutos, intenta retranscribir.');
            } else {
                // Es retranscripción, actualizar el registro existente
                $transcriptionId = $existingTranscription['id'];
            }
        } elseif ($existingTranscription['status'] === 'completed' && !$retranscribe) {
            throw new Exception('Este audio ya tiene una transcripción completada');
        }
        // Si es retranscripción y hay una transcripción completada, se actualizará más adelante
    }
    
    // Obtener configuración de AI
    $config = getAiConfig($db);
    
    // Verificar si la transcripción automática está habilitada
    // Si está habilitada, agregar a la cola en lugar de transcribir directamente
    // PERO: si es retranscripción, forzar transcripción directa para procesar inmediatamente
    $autoTranscribeEnabled = isset($config['auto_transcribe_enabled']) && $config['auto_transcribe_enabled'];
    $forceDirect = isset($input['force_direct']) && $input['force_direct']; // Permitir forzar transcripción directa
    $shouldForceDirect = $forceDirect || $retranscribe; // Forzar directa si es retranscripción o si se solicita explícitamente
    
    error_log("handleTranscribeAudio - auto_transcribe_enabled: " . ($autoTranscribeEnabled ? 'true' : 'false') . ", retranscribe: " . ($retranscribe ? 'true' : 'false') . ", shouldForceDirect: " . ($shouldForceDirect ? 'true' : 'false'));
    
    require_once __DIR__ . '/transcription_health.php';
    $txHealth = transcriptionAssertReadyToEnqueue($db);

    if ($autoTranscribeEnabled && !$shouldForceDirect) {
        // Verificar si ya está en la cola
        $checkQueueStmt = $db->prepare("
            SELECT id FROM ai_transcription_queue 
            WHERE audio_id = ? AND status IN ('pending', 'processing')
            LIMIT 1
        ");
        $checkQueueStmt->execute([$audioId]);
        $existingInQueue = $checkQueueStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingInQueue) {
            throw new Exception('Este audio ya está en la cola de transcripción');
        }
        
        // Agregar a la cola
        $priority = isset($input['priority']) ? intval($input['priority']) : 0;
        $insertQueueStmt = $db->prepare("
            INSERT INTO ai_transcription_queue 
            (audio_id, study_id, orthanc_study_id, status, priority, created_by, created_at)
            VALUES (?, ?, ?, 'pending', ?, ?, NOW())
        ");
        $insertQueueStmt->execute([$audioId, $studyId, $orthancStudyId, $priority, $userId]);
        $queueId = $db->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Audio agregado a la cola de transcripción automática. Se procesará en breve.',
            'queue_id' => $queueId,
            'queued' => true,
            'transcription_health' => [
                'status' => $txHealth['status'] ?? null,
                'degraded' => !empty($txHealth['degraded']),
                'message' => $txHealth['message'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Si no está habilitada la auto-transcripción o se fuerza directa, transcribir inmediatamente
    // Obtener ruta física del archivo
    $audioFilePath = __DIR__ . '/../' . $audio['ruta_archivo'];
    if (!file_exists($audioFilePath)) {
        throw new Exception('Archivo de audio no encontrado en el servidor: ' . $audio['ruta_archivo']);
    }
    
    // Crear cliente Whisper
    $whisperConfig = [
        'whisper_method' => $config['whisper_method'] ?? 'whisper-server',
        'whisper_api_url' => $config['whisper_api_url'] ?? 'http://localhost:8080',
        'whisper_cli_api_url' => $config['whisper_cli_api_url'] ?? 'http://localhost:3001',
        'whisper_model' => $config['whisper_model'] ?? 'base',
        'whisper_language' => $config['whisper_language'] ?? 'es',
        'whisper_timeout' => 600,
        'ffmpeg_rest_url' => $config['ffmpeg_rest_url'] ?? null,
    ];
    $client = new WhisperClient($whisperConfig);
    
    // Si es retranscripción y ya existe una transcripción (completada o en proceso), actualizarla
    // De lo contrario, crear una nueva
    if ($retranscribe && $existingTranscription && isset($transcriptionId)) {
        // Ya tenemos el transcriptionId de la lógica anterior (transcripción en proceso que se actualizará)
        // Actualizar el registro existente a processing y actualizar study_id si es necesario
        $updateStmt = $db->prepare("
            UPDATE ai_transcriptions 
            SET status = 'processing', 
                study_id = ?,
                error_message = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$studyId, $transcriptionId]);
        error_log("Retranscripción: Actualizando transcripción existente ID={$transcriptionId} para audio_id={$audioId}");
    } elseif ($retranscribe && $existingTranscription && $existingTranscription['status'] === 'completed') {
        $transcriptionId = $existingTranscription['id'];
        // Actualizar el registro existente a processing y actualizar study_id si es necesario
        $updateStmt = $db->prepare("
            UPDATE ai_transcriptions 
            SET status = 'processing', 
                study_id = ?,
                error_message = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$studyId, $transcriptionId]);
        error_log("Retranscripción: Actualizando transcripción existente ID={$transcriptionId} para audio_id={$audioId}");
    } else {
        // Crear registro de transcripción
        $insertStmt = $db->prepare("
            INSERT INTO ai_transcriptions 
            (study_id, audio_id, audio_file_path, status, created_by, created_at)
            VALUES (?, ?, ?, 'processing', ?, NOW())
        ");
        $insertStmt->execute([
            $studyId,
            $audioId,
            $audio['ruta_archivo'],
            $userId
        ]);
        $transcriptionId = $db->lastInsertId();
    }
    
    try {
        // Transcribir
        error_log("Iniciando transcripción directa - audio_id: {$audioId}, transcription_id: {$transcriptionId}, file: {$audioFilePath}");
        $startTime = microtime(true);
        $result = $client->transcribeAudio($audioFilePath);
        $processingTime = microtime(true) - $startTime;
        error_log("Transcripción completada - audio_id: {$audioId}, success: " . ($result['success'] ? 'true' : 'false'));
        
        if (!$result['success']) {
            // Actualizar estado a failed
            $updateStmt = $db->prepare("
                UPDATE ai_transcriptions 
                SET status = 'failed', 
                    error_message = ?, 
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$result['error'], $transcriptionId]);
            
            throw new Exception($result['error']);
        }
        
        // Preparar respuesta completa de whisper para debug
        $whisperResponse = json_encode([
            'model'     => $result['model'] ?? null,
            'language'  => $result['language'] ?? null,
            'mode'      => $result['mode'] ?? null,
            'segments'  => $result['segments'] ?? null,
            'stats'     => $result['stats'] ?? null,
            'raw'       => $result['raw_response'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        // Actualizar transcripción
        // Si es retranscripción, también actualizar created_at para reflejar que es una nueva transcripción
        if ($retranscribe && $existingTranscription && $existingTranscription['status'] === 'completed') {
            // Para retranscripciones, actualizar created_at para que se muestre como nueva
            $updateStmt = $db->prepare("
                UPDATE ai_transcriptions 
                SET transcription_text = ?, 
                    status = 'completed', 
                    model_used = ?, 
                    processing_time = ?, 
                    whisper_response = ?,
                    created_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            error_log("Retranscripción: Actualizando texto y fecha de transcripción ID={$transcriptionId}");
            $updateStmt->execute([
                $result['text'],
                $result['model'] ?? $config['whisper_model'] ?? 'base',
                round($processingTime, 2),
                $whisperResponse,
                $transcriptionId
            ]);
        } else {
            // Para transcripciones nuevas, solo actualizar updated_at
            $updateStmt = $db->prepare("
                UPDATE ai_transcriptions 
                SET transcription_text = ?, 
                    status = 'completed', 
                    model_used = ?, 
                    processing_time = ?, 
                    whisper_response = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $result['text'],
                $result['model'] ?? $config['whisper_model'] ?? 'base',
                round($processingTime, 2),
                $whisperResponse,
                $transcriptionId
            ]);
        }
        
        // Opcional: Actualizar transcripción en audios_informe
        $updateAudioStmt = $db->prepare("
            UPDATE audios_informe 
            SET transcripcion_texto = ? 
            WHERE id = ?
        ");
        $updateAudioStmt->execute([$result['text'], $audioId]);
        
        // Actualizar duración del audio si Whisper la devolvió y no existe o es diferente
        // La duración viene de result['stats']['audio_duration_s'] o result['audio_duration_s']
        // También puede venir de result['converted_audio_duration'] (duración del MP3 convertido)
        $audioDuration = null;
        if (isset($result['stats']['audio_duration_s'])) {
            $audioDuration = $result['stats']['audio_duration_s'];
        } elseif (isset($result['audio_duration_s'])) {
            $audioDuration = $result['audio_duration_s'];
        } elseif (isset($result['converted_audio_duration'])) {
            // Usar duración del MP3 convertido si no hay duración de Whisper
            $audioDuration = $result['converted_audio_duration'];
        }
        
        // Debug: Log de la estructura de result para ver qué datos tenemos
        error_log("handleTranscribeAudio - audio_id={$audioId}: result keys: " . json_encode(array_keys($result ?? [])));
        if (isset($result['stats'])) {
            error_log("handleTranscribeAudio - audio_id={$audioId}: stats keys: " . json_encode(array_keys($result['stats'] ?? [])));
        }
        if (isset($result['converted_audio_duration'])) {
            error_log("handleTranscribeAudio - audio_id={$audioId}: converted_audio_duration={$result['converted_audio_duration']}");
        }
        
        if ($audioDuration && $audioDuration > 0) {
            // Obtener duración actual para comparar
            $currentDurationStmt = $db->prepare("SELECT duracion_segundos FROM audios_informe WHERE id = ?");
            $currentDurationStmt->execute([$audioId]);
            $currentDuration = $currentDurationStmt->fetchColumn();
            
            // Actualizar duración siempre que esté disponible (del MP3 convertido o de Whisper)
            // Solo evitar actualizar si la diferencia es muy pequeña (< 0.5 segundos) para evitar actualizaciones por redondeo
            $updateDurationStmt = $db->prepare("
                UPDATE audios_informe 
                SET duracion_segundos = ? 
                WHERE id = ? AND (duracion_segundos IS NULL OR ABS(COALESCE(duracion_segundos, 0) - ?) > 0.5)
            ");
            $updateDurationStmt->execute([$audioDuration, $audioId, $audioDuration]);
            
            if ($updateDurationStmt->rowCount() > 0) {
                error_log("Duración actualizada para audio_id={$audioId}: {$currentDuration} -> {$audioDuration} segundos (desde MP3 convertido/Whisper)");
            } else {
                error_log("Duración no actualizada para audio_id={$audioId}: actual={$currentDuration}, nueva={$audioDuration}, diferencia=" . abs(($currentDuration ?? 0) - $audioDuration) . "s");
            }
        } else {
            error_log("handleTranscribeAudio - audio_id={$audioId}: No se obtuvo duración (audioDuration={$audioDuration})");
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Transcripción completada',
            'data' => [
                'transcription_id' => $transcriptionId,
                'audio_id' => $audioId,
                'transcription_text' => $result['text'],
                'processing_time' => round($processingTime, 2),
                'model_used' => $result['model'] ?? $config['whisper_model'] ?? 'base',
                'audio_duration' => $audioDuration ?? null,
                'audio_duration_formatted' => $result['audio_duration_formatted'] ?? ($audioDuration ? formatDuration($audioDuration) : null),
                'segments' => $result['segments'] ?? null
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        // Actualizar estado a failed
        $updateStmt = $db->prepare("
            UPDATE ai_transcriptions 
            SET status = 'failed', 
                error_message = ?, 
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$e->getMessage(), $transcriptionId]);
        
        throw $e;
    }
}

/**
 * Generar informe desde audio (usando transcripción existente)
 */
function handleGenerateReportFromAudio($db, $userId) {
    // Verificar permiso para generar informe con AI
    $permissionManager = new PermissionManager();
    if (!$permissionManager->hasPermission('generar_informe_ai', $userId)) {
        http_response_code(403);
        throw new Exception('No tienes permisos para generar informes con AI. Contacta al administrador para habilitar el permiso "Generar Informe con AI".');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    if (!isset($input['audio_id'])) {
        throw new Exception('audio_id es requerido');
    }
    
    $audioId = intval($input['audio_id']);
    $transcriptionId = isset($input['transcription_id']) ? intval($input['transcription_id']) : null;
    $templateId = isset($input['template_id']) ? intval($input['template_id']) : null;
    
    // Buscar audio
    $stmt = $db->prepare("
        SELECT ai.*, e.id as estudio_id_int, e.patient_id_pacs, e.patient_name_pacs, e.modality, e.study_description
        FROM audios_informe ai
        INNER JOIN estudios e ON ai.estudio_id = e.orthanc_study_id
        WHERE ai.id = ? AND ai.activo = 1
    ");
    $stmt->execute([$audioId]);
    $audio = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$audio) {
        throw new Exception('Audio no encontrado o inactivo');
    }
    
    $studyId = $audio['estudio_id_int'];
    
    // Si no se proporcionó transcription_id, buscar la más reciente para este audio
    if (!$transcriptionId) {
        $transStmt = $db->prepare("
            SELECT id, transcription_text 
            FROM ai_transcriptions 
            WHERE audio_id = ? AND status = 'completed'
            ORDER BY created_at DESC LIMIT 1
        ");
        $transStmt->execute([$audioId]);
        $transcription = $transStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transcription) {
            throw new Exception('No hay transcripción completada para este audio. Transcribe el audio primero.');
        }
        
        $transcriptionId = $transcription['id'];
        $transcriptionText = $transcription['transcription_text'];
    } else {
        // Verificar que la transcripción existe y pertenece al audio
        $transStmt = $db->prepare("
            SELECT transcription_text 
            FROM ai_transcriptions 
            WHERE id = ? AND audio_id = ? AND status = 'completed'
        ");
        $transStmt->execute([$transcriptionId, $audioId]);
        $transcription = $transStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transcription) {
            throw new Exception('Transcripción no encontrada o no completada para este audio');
        }
        
        $transcriptionText = $transcription['transcription_text'];
    }
    
    // Obtener plantilla si se proporcionó template_id
    $template = null;
    if ($templateId) {
        $templateStmt = $db->prepare("SELECT * FROM plantillas WHERE id = ?");
        $templateStmt->execute([$templateId]);
        $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Obtener configuración de AI
    $config = getAiConfig($db);
    
    // Crear cliente Ollama
    $client = new OllamaAiClient($config);
    
    // Preparar datos para el prompt
    $patientData = trim(($audio['patient_name_pacs'] ?? '') . ' ' . ($audio['patient_id_pacs'] ?? ''));
    $studyData = trim(($audio['modality'] ?? '') . ' - ' . ($audio['study_description'] ?? ''));
    
    $promptData = [
        'patient' => $patientData ?: 'No especificado',
        'study' => $studyData ?: 'No especificado',
        'transcription' => $transcriptionText ?: 'No hay transcripción disponible',
        'template' => $template ? $template['contenido'] : 'Sin plantilla'
    ];
    
    // Crear registro de informe
    $insertStmt = $db->prepare("
        INSERT INTO ai_reports 
        (study_id, audio_id, transcription_id, template_id, status, created_by, created_at)
        VALUES (?, ?, ?, ?, 'draft', ?, NOW())
    ");
    $insertStmt->execute([
        $studyId,
        $audioId,
        $transcriptionId,
        $templateId,
        $userId
    ]);
    $reportId = $db->lastInsertId();
    
    try {
        // Generar informe
        $startTime = microtime(true);
        $result = $client->generateReport($promptData);
        $processingTime = microtime(true) - $startTime;
        
        if (!$result['success']) {
            // Actualizar estado a failed (podríamos agregar columna error_message a ai_reports)
            $updateStmt = $db->prepare("
                UPDATE ai_reports 
                SET status = 'draft', 
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$reportId]);
            
            throw new Exception($result['error'] ?? 'Error desconocido al generar el informe');
        }
        
        // Actualizar informe
        $updateStmt = $db->prepare("
            UPDATE ai_reports 
            SET report_content = ?, 
                status = 'completed', 
                model_used = ?, 
                prompt_used = ?,
                processing_time = ?, 
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([
            $result['content'] ?? $result['text'] ?? '',
            $result['model'] ?? $config['medgemma_model'] ?? 'medgemma',
            json_encode($promptData),
            round($processingTime, 2),
            $reportId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Informe generado exitosamente',
            'data' => [
                'report_id' => $reportId,
                'audio_id' => $audioId,
                'transcription_id' => $transcriptionId,
                'report_content' => $result['content'] ?? $result['text'] ?? '',
                'processing_time' => round($processingTime, 2),
                'processing_time_formatted' => formatDuration($processingTime),
                'model_used' => $result['model'] ?? $config['medgemma_model'] ?? 'medgemma'
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        // Actualizar estado (mantener como draft si falla)
        $updateStmt = $db->prepare("
            UPDATE ai_reports 
            SET status = 'draft', 
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$reportId]);
        
        throw $e;
    }
}

/**
 * Obtener estado de la cola de transcripciones
 */
function handleQueueStatus($db) {
    try {
        // Verificar si la tabla existe
        $checkTable = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
        if ($checkTable->rowCount() === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'La tabla ai_transcription_queue no existe. Ejecuta el script SQL: database/create_transcription_queue.sql',
                'queue' => null
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Obtener estadísticas de la cola
        $stats = $db->query("
            SELECT 
                status,
                COUNT(*) as count
            FROM ai_transcription_queue
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $statusCounts = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0
        ];
        
        foreach ($stats as $stat) {
            $statusCounts[$stat['status']] = intval($stat['count']);
        }
        
        // Obtener configuración
        $config = getAiConfig($db);
        $autoTranscribeEnabled = isset($config['auto_transcribe_enabled']) && $config['auto_transcribe_enabled'];
        
        // Obtener transcripciones completadas en los últimos 30 segundos (para actualizar la interfaz)
        $recentCompleted = [];
        try {
            $recentStmt = $db->prepare("
                SELECT 
                    at.audio_id,
                    at.id as transcription_id,
                    at.updated_at,
                    ai.fecha_modificacion as audio_updated_at
                FROM ai_transcriptions at
                INNER JOIN audios_informe ai ON at.audio_id = ai.id
                WHERE at.status = 'completed' 
                AND at.updated_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
                ORDER BY at.updated_at DESC
                LIMIT 10
            ");
            $recentStmt->execute();
            $recentCompleted = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Ignorar errores en esta consulta opcional
            error_log("Error obteniendo transcripciones recientes: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'queue' => [
                'pending' => $statusCounts['pending'],
                'processing' => $statusCounts['processing'],
                'completed' => $statusCounts['completed'],
                'failed' => $statusCounts['failed'],
                'cancelled' => $statusCounts['cancelled'],
                'total' => array_sum($statusCounts),
                'auto_transcribe_enabled' => $autoTranscribeEnabled
            ],
            'recent_completed' => $recentCompleted
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        throw new Exception('Error obteniendo estado de la cola: ' . $e->getMessage());
    }
}

/**
 * Listar items de la cola
 */
function handleQueueList($db) {
    try {
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        
        $query = "
            SELECT 
                q.*,
                ai.nombre_archivo,
                ai.nombre_original,
                ai.ruta_archivo,
                ai.fecha_creacion as audio_fecha_creacion
            FROM ai_transcription_queue q
            LEFT JOIN audios_informe ai ON q.audio_id = ai.id
            WHERE 1=1
        ";
        
        $params = [];
        if ($status) {
            $query .= " AND q.status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY q.priority DESC, q.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'items' => $items,
            'count' => count($items)
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        throw new Exception('Error listando cola: ' . $e->getMessage());
    }
}

/**
 * Agregar audio a la cola
 */
function handleAddToQueue($db, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['audio_id'])) {
        throw new Exception('audio_id es requerido');
    }

    require_once __DIR__ . '/transcription_health.php';
    $txHealth = transcriptionAssertReadyToEnqueue($db);
    
    $audioId = intval($input['audio_id']);
    $studyId = isset($input['study_id']) ? intval($input['study_id']) : null;
    $orthancStudyId = isset($input['orthanc_study_id']) ? $input['orthanc_study_id'] : null;
    $priority = isset($input['priority']) ? intval($input['priority']) : 0;
    
    // Verificar que el audio existe
    $audioStmt = $db->prepare("SELECT id, activo FROM audios_informe WHERE id = ?");
    $audioStmt->execute([$audioId]);
    $audio = $audioStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$audio) {
        throw new Exception("Audio no encontrado (ID: $audioId)");
    }
    
    if ($audio['activo'] != 1) {
        throw new Exception("El audio está inactivo (ID: $audioId)");
    }
    
    // Verificar si ya está en la cola con estado pending o processing
    $checkStmt = $db->prepare("
        SELECT id FROM ai_transcription_queue 
        WHERE audio_id = ? AND status IN ('pending', 'processing')
        LIMIT 1
    ");
    $checkStmt->execute([$audioId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        throw new Exception('Este audio ya está en la cola de transcripción');
    }
    
    // Agregar a la cola
    $insertStmt = $db->prepare("
        INSERT INTO ai_transcription_queue 
        (audio_id, study_id, orthanc_study_id, status, priority, created_by, created_at)
        VALUES (?, ?, ?, 'pending', ?, ?, NOW())
    ");
    $insertStmt->execute([$audioId, $studyId, $orthancStudyId, $priority, $userId]);
    $queueId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Audio agregado a la cola de transcripción',
        'queue_id' => $queueId,
        'transcription_health' => [
            'status' => $txHealth['status'] ?? null,
            'degraded' => !empty($txHealth['degraded']),
            'message' => $txHealth['message'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Agregar múltiples audios a la cola (batch)
 */
function handleQueueBatch($db, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['audio_ids']) || !is_array($input['audio_ids'])) {
        throw new Exception('audio_ids debe ser un array de IDs de audios');
    }

    require_once __DIR__ . '/transcription_health.php';
    $txHealth = transcriptionAssertReadyToEnqueue($db);
    
    $audioIds = array_map('intval', $input['audio_ids']);
    $priority = isset($input['priority']) ? intval($input['priority']) : 0;
    
    if (empty($audioIds)) {
        throw new Exception('Debes proporcionar al menos un audio_id');
    }
    
    $added = 0;
    $skipped = 0;
    $errors = [];
    
    foreach ($audioIds as $audioId) {
        try {
            // Verificar que el audio existe
            $audioStmt = $db->prepare("SELECT id, activo, estudio_id, informe_id FROM audios_informe WHERE id = ?");
            $audioStmt->execute([$audioId]);
            $audio = $audioStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$audio) {
                $errors[] = "Audio ID $audioId: no encontrado";
                $skipped++;
                continue;
            }
            
            if ($audio['activo'] != 1) {
                $errors[] = "Audio ID $audioId: está inactivo";
                $skipped++;
                continue;
            }
            
            // Verificar si ya está en la cola
            $checkStmt = $db->prepare("
                SELECT id FROM ai_transcription_queue 
                WHERE audio_id = ? AND status IN ('pending', 'processing')
                LIMIT 1
            ");
            $checkStmt->execute([$audioId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $skipped++;
                continue;
            }
            
            // Obtener study_id si está disponible
            $studyId = null;
            $orthancStudyId = $audio['estudio_id'] ?? null;
            
            if ($orthancStudyId) {
                $studyStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                $studyStmt->execute([$orthancStudyId]);
                $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
                if ($study) {
                    $studyId = $study['id'];
                }
            }
            
            // Agregar a la cola
            $insertStmt = $db->prepare("
                INSERT INTO ai_transcription_queue 
                (audio_id, study_id, orthanc_study_id, status, priority, created_by, created_at)
                VALUES (?, ?, ?, 'pending', ?, ?, NOW())
            ");
            $insertStmt->execute([$audioId, $studyId, $orthancStudyId, $priority, $userId]);
            $added++;
            
        } catch (Exception $e) {
            $errors[] = "Audio ID $audioId: " . $e->getMessage();
            $skipped++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Procesados: $added agregados, $skipped omitidos",
        'added' => $added,
        'skipped' => $skipped,
        'errors' => $errors,
        'transcription_health' => [
            'status' => $txHealth['status'] ?? null,
            'degraded' => !empty($txHealth['degraded']),
            'message' => $txHealth['message'] ?? null,
        ],
        'warning' => !empty($txHealth['degraded'])
            ? ($txHealth['message'] ?? 'Servidor de transcripción degradado')
            : null,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Procesar siguiente item de la cola (llamado por worker o manualmente)
 */
function handleProcessQueue($db) {
    try {
        // Verificar si la tabla existe
        $checkTable = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
        if ($checkTable->rowCount() === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'La tabla ai_transcription_queue no existe'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Obtener configuración
        $config = getAiConfig($db);
        
        // Verificar si la transcripción automática está habilitada
        if (!isset($config['auto_transcribe_enabled']) || !$config['auto_transcribe_enabled']) {
            echo json_encode([
                'success' => false,
                'message' => 'Transcripción automática deshabilitada'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        require_once __DIR__ . '/transcription_health.php';
        $txHealth = transcriptionCheckHealth($db, null, 4);
        if (empty($txHealth['allow_enqueue'])) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => $txHealth['message'] ?? 'Servidor de transcripción no disponible',
                'transcription_health' => $txHealth,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Obtener máximo de transcripciones concurrentes
        $maxConcurrent = isset($config['max_concurrent_transcriptions']) ? intval($config['max_concurrent_transcriptions']) : 1;
        
        // Verificar cuántas transcripciones están en proceso
        $checkProcessing = $db->prepare("
            SELECT COUNT(*) as count 
            FROM ai_transcription_queue 
            WHERE status = 'processing'
        ");
        $checkProcessing->execute();
        $processingCount = $checkProcessing->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($processingCount >= $maxConcurrent) {
            echo json_encode([
                'success' => false,
                'message' => "Ya hay $processingCount transcripciones en proceso (máximo: $maxConcurrent)"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Obtener siguiente item pendiente (FIFO con prioridad)
        // Nota: SKIP LOCKED solo está disponible en MySQL 8.0.1+, usar FOR UPDATE estándar
        $stmt = $db->prepare("
            SELECT * FROM ai_transcription_queue 
            WHERE status = 'pending' 
            ORDER BY priority DESC, created_at ASC 
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute();
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            echo json_encode([
                'success' => true,
                'message' => 'No hay items pendientes en la cola',
                'processed' => false
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Retornar información del item (el worker lo procesará)
        echo json_encode([
            'success' => true,
            'message' => 'Item encontrado en la cola',
            'item' => $item,
            'processed' => false
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        throw new Exception('Error procesando cola: ' . $e->getMessage());
    }
}

/**
 * Verificar si existe el cron job para el worker
 */
function handleCheckCron() {
    try {
        $workerPath = __DIR__ . '/../workers/transcription-queue-worker.php';
        $workerPathAbsolute = realpath($workerPath);
        
        if (!$workerPathAbsolute) {
            echo json_encode([
                'success' => false,
                'cron_installed' => false,
                'message' => 'El archivo worker no existe',
                'worker_path' => $workerPath
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Intentar leer el crontab del usuario actual
        $cronCommand = "php $workerPathAbsolute";
        $cronPattern = preg_quote($cronCommand, '/');
        
        // Ejecutar crontab -l para ver si existe
        $output = [];
        $returnVar = 0;
        exec('crontab -l 2>/dev/null', $output, $returnVar);
        
        $cronInstalled = false;
        $cronLine = null;
        
        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/' . preg_quote($workerPathAbsolute, '/') . '/', $line)) {
                    $cronInstalled = true;
                    $cronLine = trim($line);
                    break;
                }
            }
        }
        
        // Generar comando sugerido
        $suggestedCron = "* * * * * php $workerPathAbsolute >> /dev/null 2>&1";
        
        echo json_encode([
            'success' => true,
            'cron_installed' => $cronInstalled,
            'cron_line' => $cronLine,
            'worker_path' => $workerPathAbsolute,
            'suggested_cron' => $suggestedCron,
            'message' => $cronInstalled 
                ? 'Cron job está instalado correctamente' 
                : 'Cron job no está instalado. Ejecuta: crontab -e y agrega: ' . $suggestedCron
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error verificando cron: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Listar transcripciones bloqueadas (más de 3 minutos en estado 'processing')
 */
function handleListBlockedTranscriptions($db) {
    try {
        $stmt = $db->prepare("
            SELECT 
                t.id as transcription_id,
                t.audio_id,
                t.status,
                t.created_at,
                t.updated_at,
                TIMESTAMPDIFF(MINUTE, t.updated_at, NOW()) as minutes_blocked,
                a.ruta_archivo,
                a.estudio_id as orthanc_study_id,
                COALESCE(s.paciente_nombre, i.patient_name) as paciente_nombre,
                COALESCE(s.paciente_apellido, '') as paciente_apellido,
                COALESCE(s.modalidad, i.modality) as modalidad,
                q.id as queue_id,
                q.status as queue_status
            FROM ai_transcriptions t
            LEFT JOIN audios_informe a ON t.audio_id = a.id
            LEFT JOIN estudios s ON a.estudio_id = s.orthanc_study_id
            LEFT JOIN informes i ON a.informe_id = i.id
            LEFT JOIN estudios s2 ON i.estudio_id = s2.id
            LEFT JOIN ai_transcription_queue q ON q.audio_id = t.audio_id AND q.status IN ('pending', 'processing')
            WHERE t.status = 'processing'
            AND TIMESTAMPDIFF(MINUTE, t.updated_at, NOW()) >= 3
            ORDER BY t.updated_at ASC
        ");
        $stmt->execute();
        $blocked = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'blocked_transcriptions' => $blocked,
            'count' => count($blocked)
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("Error en handleListBlockedTranscriptions: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        throw new Exception('Error listando transcripciones bloqueadas: ' . $e->getMessage());
    }
}

/**
 * Health del servidor Whisper (URL desde ai_config). Proxy para el frontend.
 */
function handleTranscriptionHealth($db) {
    require_once __DIR__ . '/transcription_health.php';
    $health = transcriptionCheckHealth($db, null, 4);
    if (empty($health['allow_enqueue'])) {
        http_response_code(503);
    } elseif (!empty($health['degraded'])) {
        http_response_code(200);
    }
    echo json_encode(array_merge(['success' => true], $health), JSON_UNESCAPED_UNICODE);
}

/**
 * Resumen de transcripción por informe(s) para polling en informes-manager.
 */
function handleInformeTranscriptionStatus($db) {
    require_once __DIR__ . '/informes/transcription_status_helpers.php';

    $idsParam = $_GET['informe_ids'] ?? '';
    if ($idsParam === '') {
        throw new Exception('informe_ids es requerido');
    }

    $informeIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $idsParam)))));
    if (empty($informeIds)) {
        throw new Exception('informe_ids inválido');
    }
    if (count($informeIds) > 50) {
        $informeIds = array_slice($informeIds, 0, 50);
    }

    $hasAudioActivo = false;
    try {
        $checkActivoCol = $db->query("SHOW COLUMNS FROM audios_informe LIKE 'activo'");
        $hasAudioActivo = ($checkActivoCol && $checkActivoCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasAudioActivo = false;
    }

    $statusByInforme = getInformeTranscriptionStatusByIds($db, $informeIds, $hasAudioActivo);

    echo json_encode([
        'success' => true,
        'status_by_informe' => $statusByInforme,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Limpiar transcripciones bloqueadas y eliminarlas de la cola
 */
function handleCleanupBlockedTranscriptions($db, $userId) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $transcriptionIds = $input['transcription_ids'] ?? [];
        
        if (empty($transcriptionIds)) {
            throw new Exception('No se especificaron transcripciones para limpiar');
        }
        
        // Convertir a enteros
        $transcriptionIds = array_map('intval', $transcriptionIds);
        $placeholders = implode(',', array_fill(0, count($transcriptionIds), '?'));
        
        // Obtener los audio_ids de las transcripciones bloqueadas
        $stmt = $db->prepare("
            SELECT DISTINCT audio_id 
            FROM ai_transcriptions 
            WHERE id IN ($placeholders) AND status = 'processing'
        ");
        $stmt->execute($transcriptionIds);
        $audioIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($audioIds)) {
            throw new Exception('No se encontraron transcripciones bloqueadas para limpiar');
        }
        
        // Marcar transcripciones como fallidas
        $updateStmt = $db->prepare("
            UPDATE ai_transcriptions 
            SET status = 'failed', 
                error_message = 'Transcripción bloqueada - limpiada manualmente',
                updated_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $updateStmt->execute($transcriptionIds);
        $cleanedCount = $updateStmt->rowCount();
        
        // Eliminar de la cola si están ahí
        $audioPlaceholders = implode(',', array_fill(0, count($audioIds), '?'));
        $deleteQueueStmt = $db->prepare("
            DELETE FROM ai_transcription_queue 
            WHERE audio_id IN ($audioPlaceholders) AND status IN ('pending', 'processing')
        ");
        $deleteQueueStmt->execute($audioIds);
        $deletedFromQueue = $deleteQueueStmt->rowCount();
        
        error_log("Limpieza de transcripciones bloqueadas: {$cleanedCount} transcripciones marcadas como fallidas, {$deletedFromQueue} items eliminados de la cola");
        
        echo json_encode([
            'success' => true,
            'message' => "Se limpiaron {$cleanedCount} transcripciones bloqueadas y se eliminaron {$deletedFromQueue} items de la cola",
            'cleaned_transcriptions' => $cleanedCount,
            'deleted_from_queue' => $deletedFromQueue
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        throw new Exception('Error limpiando transcripciones bloqueadas: ' . $e->getMessage());
    }
}

/**
 * Descargar audio convertido a MP3
 *
 * @param PDO   $db
 * @param array $userData Fila de usuario de validateSession (id, nivel, permisos, …)
 */
function handleDownloadAudioMp3($db, $userData) {
    require_once __DIR__ . '/informes/informes_list_common.php';

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['audio_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'audio_id es requerido'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $audioId = intval($input['audio_id']);
    $uid = (int) ($userData['id'] ?? 0);
    if ($uid <= 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Sesión inválida'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $rawPermisos = $userData['permisos'] ?? [];
    if (is_string($rawPermisos)) {
        $decoded = json_decode($rawPermisos, true);
        $rawPermisos = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($rawPermisos)) {
        $rawPermisos = [];
    }
    $nivel = strtolower(trim((string) ($userData['nivel'] ?? '')));

    $canUseEndpoint = $nivel === 'root'
        || listInformes_userHasPermission($rawPermisos, 'all')
        || listInformes_userHasPermission($rawPermisos, 'descargar_audios_informe')
        || listInformes_userHasPermission($rawPermisos, 'ai_informes');
    if (!$canUseEndpoint) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No tiene permiso para descargar audios de informes'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    try {
        // Obtener información del audio (informe y dueño para autorización)
        $stmt = $db->prepare("
            SELECT a.id, a.informe_id, a.usuario_id, a.nombre_original, a.nombre_archivo, a.ruta_archivo
            FROM audios_informe a
            WHERE a.id = ? AND a.activo = 1
        ");
        $stmt->execute([$audioId]);
        $audio = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$audio) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Audio no encontrado'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $padreStmt = $db->prepare('SELECT padre_id FROM usuarios WHERE id = ? AND activo = 1 LIMIT 1');
        $padreStmt->execute([$uid]);
        $padreRow = $padreStmt->fetch(PDO::FETCH_ASSOC);
        $userPadreId = $padreRow ? $padreRow['padre_id'] : null;

        $informeId = isset($audio['informe_id']) && $audio['informe_id'] !== null && $audio['informe_id'] !== ''
            ? (int) $audio['informe_id']
            : 0;

        if ($informeId > 0) {
            $allowed = listInformes_canUserAccessInformeById($db, $uid, $userPadreId, $rawPermisos, $informeId);
        } else {
            $allowed = ((int) ($audio['usuario_id'] ?? 0) === $uid)
                || listInformes_userHasPermission($rawPermisos, 'all')
                || listInformes_userHasPermission($rawPermisos, 'audios_ver_todos_workspace')
                || $nivel === 'root';
        }
        if (!$allowed) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'No tiene acceso a este audio'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Obtener ruta física del archivo
        $audioFilePath = __DIR__ . '/../' . $audio['ruta_archivo'];
        if (!file_exists($audioFilePath)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Archivo de audio no encontrado en el servidor'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Obtener configuración de AI para ffmpeg-rest
        $config = getAiConfig($db);
        $ffmpegRestUrl = $config['ffmpeg_rest_url'] ?? null;
        
        if (!$ffmpegRestUrl) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'ffmpeg-rest no está configurado. Configura la URL de ffmpeg-rest en Configuración → AI Informes.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Verificar si el archivo ya es MP3
        $fileExt = strtolower(pathinfo($audioFilePath, PATHINFO_EXTENSION));
        if ($fileExt === 'mp3') {
            // Si ya es MP3, servir directamente
            $fileName = $audio['nombre_original'] ?? $audio['nombre_archivo'] ?? 'audio.mp3';
            if (pathinfo($fileName, PATHINFO_EXTENSION) !== 'mp3') {
                $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.mp3';
            }
            
            // Cambiar headers para descarga de archivo
            header_remove('Content-Type');
            header('Content-Type: audio/mpeg');
            header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
            header('Content-Length: ' . filesize($audioFilePath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            readfile($audioFilePath);
            exit();
        }
        
        // Convertir a MP3 usando ffmpeg-rest
        $fileName = basename($audioFilePath);
        $mimeType = mime_content_type($audioFilePath) ?: 'audio/webm';
        $convertUrl = $ffmpegRestUrl . '/audio/mp3';
        
        error_log("Descarga MP3: Convirtiendo audio ID={$audioId} con ffmpeg-rest: {$convertUrl}");
        
        $ch = curl_init($convertUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300, // 5 minutos para conversión
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($audioFilePath, $mimeType, $fileName)
            ],
            CURLOPT_HTTPHEADER => [
                'Accept: audio/mpeg, audio/*, */*'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Error cURL con ffmpeg-rest: $error");
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => "Error al conectar con ffmpeg-rest: $error"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        if ($httpCode !== 200 || empty($response)) {
            error_log("ffmpeg-rest retornó código HTTP: $httpCode");
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => "Error al convertir audio. Código HTTP: $httpCode"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Generar nombre de archivo para descarga
        $downloadFileName = $audio['nombre_original'] ?? $audio['nombre_archivo'] ?? 'audio.mp3';
        $downloadFileName = pathinfo($downloadFileName, PATHINFO_FILENAME) . '.mp3';
        
        // Cambiar headers para descarga de archivo
        header_remove('Content-Type');
        header('Content-Type: audio/mpeg');
        header('Content-Disposition: attachment; filename="' . addslashes($downloadFileName) . '"');
        header('Content-Length: ' . strlen($response));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $response;
        exit();
        
    } catch (Exception $e) {
        error_log("Error en handleDownloadAudioMp3: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al descargar audio: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Actualizar duración de un audio desde el frontend
 */
function handleUpdateAudioDuration($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['audio_id']) || !isset($input['duration'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'audio_id y duration son requeridos'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $audioId = intval($input['audio_id']);
    $duration = floatval($input['duration']);
    
    if ($audioId <= 0 || $duration <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'audio_id y duration deben ser valores positivos'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    try {
        // Verificar que el audio existe
        $checkStmt = $db->prepare("SELECT id FROM audios_informe WHERE id = ? AND activo = 1");
        $checkStmt->execute([$audioId]);
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Audio no encontrado'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Actualizar duración si no existe o si la diferencia es mayor a 0.5 segundos
        $updateStmt = $db->prepare("
            UPDATE audios_informe 
            SET duracion_segundos = ? 
            WHERE id = ? AND (duracion_segundos IS NULL OR ABS(COALESCE(duracion_segundos, 0) - ?) > 0.5)
        ");
        $updateStmt->execute([$duration, $audioId, $duration]);
        
        if ($updateStmt->rowCount() > 0) {
            error_log("Duración actualizada desde frontend para audio_id={$audioId}: {$duration} segundos");
            echo json_encode([
                'success' => true,
                'message' => 'Duración actualizada correctamente',
                'data' => [
                    'audio_id' => $audioId,
                    'duration' => $duration
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // Ya existe y la diferencia es pequeña, pero devolvemos éxito
            echo json_encode([
                'success' => true,
                'message' => 'Duración ya existe y es similar',
                'data' => [
                    'audio_id' => $audioId,
                    'duration' => $duration
                ]
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } catch (PDOException $e) {
        error_log("Error actualizando duración de audio: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al actualizar duración: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Asegurar que las tablas existen
 */
function ensureAiTables($db) {
    // Cargar y ejecutar SQL de creación de tablas
    $sqlFile = __DIR__ . '/../database/create_ai_tables.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        // Extraer solo las sentencias CREATE TABLE
        preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/i', $sql, $matches);
        foreach ($matches[0] as $createTableSql) {
            try {
                $db->exec($createTableSql);
            } catch (PDOException $e) {
                // Ignorar errores si la tabla ya existe
                if (strpos($e->getMessage(), 'already exists') === false) {
                    error_log("Error creando tabla AI: " . $e->getMessage());
                }
            }
        }
    }
}
