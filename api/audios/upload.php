<?php
/**
 * API Endpoint para subir archivos de audio
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Aumentar límites de PHP para subida de archivos grandes (hasta 50MB)
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('memory_limit', '128M');
ini_set('max_execution_time', '300'); // 5 minutos para archivos grandes

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../classes/User.php';
require_once '../../config/database.php';

/**
 * Duración vía ffprobe (format=duration). Más fiable que parsear stderr de ffmpeg -i para WebM/Opus.
 *
 * @param string $filePath Ruta absoluta o resoluble al archivo
 * @return float|null Segundos (> 0) o null
 */
function getAudioDurationWithFfprobe($filePath) {
    if (!is_string($filePath) || $filePath === '' || !is_readable($filePath)) {
        return null;
    }

    $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null || which ffprobe 2>/dev/null'));
    if ($ffprobe === '') {
        return null;
    }

    $cmd = escapeshellarg($ffprobe)
        . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
        . escapeshellarg($filePath)
        . ' 2>/dev/null';

    $out = trim((string) shell_exec($cmd));
    if ($out === '' || strcasecmp($out, 'N/A') === 0) {
        return null;
    }

    if (!is_numeric($out)) {
        return null;
    }

    $sec = (float) $out;
    if (!is_finite($sec) || $sec <= 0) {
        return null;
    }

    return round($sec, 2);
}

/**
 * Duración enviada por el cliente (p. ej. HTML5 Audio sobre el blob WebM). Solo se usa si el servidor no obtuvo duración.
 * Límite superior para evitar valores absurdos.
 */
function uploadParseClientDurationHint() {
    $raw = $_POST['client_duration'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }

    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
    }

    $v = (float) $raw;
    if (!is_finite($v) || $v <= 0) {
        return null;
    }

    $maxSeconds = 6 * 3600;

    if ($v > $maxSeconds) {
        error_log('upload.php: client_duration rechazado (excede máximo): ' . $v);

        return null;
    }

    return round($v, 2);
}

/**
 * Obtener duración del audio usando ffmpeg como fallback
 * @param string $filePath Ruta completa al archivo de audio
 * @return int|null Duración en segundos o null si no se puede obtener
 */
function getAudioDurationWithFfmpeg($filePath) {
    // Verificar que ffmpeg esté disponible
    $ffmpegPath = shell_exec('which ffmpeg 2>&1');
    if (empty(trim($ffmpegPath))) {
        error_log("ffmpeg no está disponible para obtener duración del audio");
        return null;
    }
    
    // Ejecutar ffmpeg para obtener información del archivo
    // ffmpeg -i archivo.webm 2>&1 | grep Duration
    $command = "ffmpeg -i " . escapeshellarg($filePath) . " 2>&1 | grep 'Duration' | head -1";
    $output = shell_exec($command);
    
    if (empty($output)) {
        error_log("No se pudo obtener duración con ffmpeg para: " . basename($filePath));
        return null;
    }
    
    // Parsear salida: "Duration: 00:03:45.67, start: 0.000000, bitrate: 64 kb/s"
    // Extraer la parte de duración (HH:MM:SS.mmm)
    if (preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}\.?\d*)/', $output, $matches)) {
        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];
        $seconds = (float)$matches[3];
        
        $totalSeconds = (int)($hours * 3600 + $minutes * 60 + $seconds);
        
        if ($totalSeconds > 0) {
            error_log("Duración obtenida con ffmpeg: {$totalSeconds} segundos para " . basename($filePath));
            return $totalSeconds;
        }
    }
    
    error_log("No se pudo parsear duración de ffmpeg para: " . basename($filePath) . " | Output: " . substr($output, 0, 100));
    return null;
}

try {
    // Validar sesión
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_POST['session_token'] ?? null;
    $mobileSessionId = $_POST['session_id'] ?? null;
    
    $userData = null;
    
    // Si hay token de sesión, validarlo normalmente
    if ($sessionToken) {
        if (strpos($sessionToken, 'Bearer ') === 0) {
            $sessionToken = substr($sessionToken, 7);
        }
        
        $user = new User();
        $userData = $user->validateSession($sessionToken);
    }
    
    // Si no hay userData pero hay session_id móvil, validar desde sesión móvil
    if (!$userData && $mobileSessionId) {
        $db = getDBConnection();
        $sessionQuery = "SELECT created_by FROM mobile_sessions WHERE session_id = ? AND expires_at > NOW()";
        $sessionStmt = $db->prepare($sessionQuery);
        $sessionStmt->execute([$mobileSessionId]);
        $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session && $session['created_by']) {
            // Obtener datos del usuario desde la BD
            $userQuery = "SELECT id, nombre, email FROM usuarios WHERE id = ?";
            $userStmt = $db->prepare($userQuery);
            $userStmt->execute([$session['created_by']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $userData = [
                    'id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'email' => $user['email']
                ];
            }
        }
    }
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    // Validar que se haya subido un archivo
    if (!isset($_FILES['audio'])) {
        error_log('DEBUG: $_FILES no contiene "audio". $_FILES = ' . print_r($_FILES, true));
        error_log('DEBUG: $_POST = ' . print_r($_POST, true));
        throw new Exception('No se recibió archivo de audio válido: $_FILES["audio"] no está presente');
    }
    
    if ($_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por PHP (actualmente: ' . ini_get('upload_max_filesize') . '). Por favor, contacte al administrador para aumentar el límite.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida'
        ];
        $errorMsg = $errorMessages[$_FILES['audio']['error']] ?? 'Error desconocido al subir archivo';
        $fileSizeMB = isset($_FILES['audio']['size']) ? round($_FILES['audio']['size'] / 1024 / 1024, 2) : 0;
        error_log('DEBUG: Error al subir archivo: ' . $_FILES['audio']['error'] . ' - ' . $errorMsg . ' - Tamaño del archivo: ' . $fileSizeMB . ' MB');
        throw new Exception('No se recibió archivo de audio válido: ' . $errorMsg . ' (Tamaño del archivo: ' . $fileSizeMB . ' MB)');
    }
    
    // Validar campos requeridos
    $informeId = $_POST['informe_id'] ?? null;
    $workspaceId = $_POST['workspace_id'] ?? null;
    $studyId = $_POST['study_id'] ?? null;
    $sessionId = $_POST['session_id'] ?? null;
    $mobileSessionId = $sessionId; // Para vincular audios a la sesión móvil
    
    // Detectar si esta es una subida desde la grabadora móvil
    // Queremos tratar estos audios como "temporales" hasta que el workspace
    // finalice el informe (no crear informes por adelantado aquí).
    $isMobileRequest = (($_POST['tipo_grabacion'] ?? '') === 'mobile') || !empty($mobileSessionId);
    
    // Conectar a la base de datos
    $db = getDBConnection();

    // Auto-migración: agregar columna mobile_session_id si no existe
    try {
        $checkCol = $db->query("SHOW COLUMNS FROM audios_informe LIKE 'mobile_session_id'");
        if ($checkCol->rowCount() === 0) {
            $db->exec("ALTER TABLE audios_informe ADD COLUMN mobile_session_id VARCHAR(100) NULL DEFAULT NULL");
            error_log("✅ Columna mobile_session_id agregada a audios_informe");
        }
    } catch (Exception $e) {
        error_log("Error en auto-migración mobile_session_id: " . $e->getMessage());
    }

    // Datos del paciente/estudio para poblar el informe si se crea automáticamente
    $mobilePatientName = null;
    $mobilePatientId   = null;
    $mobileModality    = null;
    $mobileStudyDate   = null;
    $mobileStudyDesc   = null;

    // Si no hay informe_id pero hay session_id, obtener TODOS los datos de la sesión móvil
    // Esto se usa para rellenar datos de paciente/estudio, pero NO para crear informes
    // por adelantado cuando se trata de una subida móvil.
    if (!$informeId && $sessionId) {
        try {
            // Intentar primero con sesión activa (no expirada)
            $sessionQuery = "SELECT workspace_id, study_id, patient_name, patient_id,
                                    modality, study_date, study_description
                             FROM mobile_sessions WHERE session_id = ?
                             ORDER BY expires_at DESC LIMIT 1";
            $sessionStmt = $db->prepare($sessionQuery);
            $sessionStmt->execute([$sessionId]);
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

            if ($session) {
                if (!$workspaceId && $session['workspace_id']) $workspaceId = $session['workspace_id'];
                if (!$studyId    && $session['study_id'])     $studyId     = $session['study_id'];
                // Guardar datos del paciente para usar al crear/actualizar el informe
                $mobilePatientName = $session['patient_name']      ?? null;
                $mobilePatientId   = $session['patient_id']        ?? null;
                $mobileModality    = $session['modality']          ?? null;
                $mobileStudyDate   = $session['study_date']        ?? null;
                $mobileStudyDesc   = $session['study_description'] ?? null;
                error_log("upload.php: datos de sesión móvil obtenidos — paciente: $mobilePatientName, estudio: $studyId");
            }
        } catch (Exception $e) {
            error_log('Error obteniendo datos de sesión móvil: ' . $e->getMessage());
        }
    }
    
    // Si hay workspace_id o study_id pero no informe_id, crear/obtener informe automáticamente
    // IMPORTANTE: esto NO debe hacerse para subidas móviles; los audios del móvil
    // se consideran temporales hasta que el workspace finalice el informe.
    if (!$isMobileRequest && !$informeId && ($workspaceId || $studyId)) {
        try {
            // ── Paso 1: buscar el informe directamente por estudio_id en la tabla informes
            // informes.estudio_id es varchar(255) que almacena el Orthanc Study ID
            if ($studyId) {
                $findByEstudioQuery = "SELECT id FROM informes 
                                       WHERE estudio_id = ? 
                                       ORDER BY fecha_creacion DESC LIMIT 1";
                $findByEstudioStmt = $db->prepare($findByEstudioQuery);
                $findByEstudioStmt->execute([$studyId]);
                $existingByEstudio = $findByEstudioStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingByEstudio) {
                    $informeId = $existingByEstudio['id'];
                    error_log("upload.php: informe encontrado por estudio_id='$studyId': $informeId");
                }
            }

            // ── Paso 2: buscar también por study_id o pacs_study_id como fallback
            if (!$informeId && $studyId) {
                $findByStudyQuery = "SELECT id FROM informes 
                                     WHERE study_id = ? OR pacs_study_id = ? 
                                     ORDER BY fecha_creacion DESC LIMIT 1";
                $findByStudyStmt = $db->prepare($findByStudyQuery);
                $findByStudyStmt->execute([$studyId, $studyId]);
                $existingByStudy = $findByStudyStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingByStudy) {
                    $informeId = $existingByStudy['id'];
                    error_log("upload.php: informe encontrado por study_id='$studyId': $informeId");
                }
            }

            // ── Paso 3: no existe informe → crear uno nuevo con datos del paciente
            // estudio_id es varchar NOT NULL: usamos studyId (Orthanc ID)
            if (!$informeId && $studyId) {
                $createQuery = "INSERT INTO informes 
                                (estudio_id, study_id, usuario_id,
                                 patient_name, patient_id, modality, study_description,
                                 contenido_html, fecha_creacion, fecha_modificacion) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, '', NOW(), NOW())";
                $createStmt = $db->prepare($createQuery);
                $createStmt->execute([
                    $studyId, $studyId, $userData['id'],
                    $mobilePatientName, $mobilePatientId,
                    $mobileModality,    $mobileStudyDesc
                ]);
                $informeId = $db->lastInsertId();
                error_log("upload.php: informe creado con paciente='$mobilePatientName', estudio='$studyId': id=$informeId");
            }

        } catch (Exception $e) {
            error_log('Error creando/obteniendo informe automático: ' . $e->getMessage());
            throw new Exception('No se pudo crear o obtener un informe. Detalle: ' . $e->getMessage());
        }
    }
    
    // Determinar estudio_id para audios_informe:
    //  - Para flujos normales (no móviles) requerimos informe_id válido y usamos su estudio_id
    //  - Para subidas móviles sin informe_id, usamos directamente $studyId (Orthanc Study ID)
    $informeEstudioId = null;
    $informe = null;
    
    if ($informeId) {
        // Verificar que el informe existe
        // (puede haber sido creado por otro usuario en el workspace; la sesión móvil tiene acceso delegado)
        $checkQuery = "SELECT id, estudio_id, usuario_id FROM informes WHERE id = ?";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([$informeId]);
        $informe = $checkStmt->fetch();
        
        if (!$informe) {
            throw new Exception('Informe no encontrado');
        }
        
        $informeEstudioId = $informe['estudio_id'];
    } else {
        // No hay informeId:
        //  - Si es una subida móvil y tenemos studyId, guardar el audio como temporal
        //    (sin informe_id, pero con estudio_id para poder enlazarlo luego desde el workspace)
        if ($isMobileRequest && $studyId) {
            $informeEstudioId = $studyId;
        } else {
            // Flujos no móviles SIN informe_id siguen siendo inválidos (comportamiento original)
            throw new Exception('ID de informe requerido o datos de workspace/estudio inválidos');
        }
    }
    
    $audioFile = $_FILES['audio'];
    
    // Validar tipo de archivo
    $allowedTypes = [
        'audio/wav', 'audio/x-wav', 'audio/wave',
        'audio/mp3', 'audio/mpeg', 'audio/mpeg3', 'audio/x-mpeg-3',
        'audio/ogg', 'audio/vorbis',
        'audio/webm',
        'audio/mp4', 'audio/m4a', 'audio/x-m4a',
        'audio/aac', 'audio/x-aac',
        'audio/flac', 'audio/x-flac'
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $audioFile['tmp_name']);
    finfo_close($finfo);
    
    // También verificar por extensión como fallback
    $extension = strtolower(pathinfo($audioFile['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['wav', 'mp3', 'm4a', 'webm', 'mp4', 'ogg', 'aac', 'flac'];
    
    if (!in_array($mimeType, $allowedTypes) && !in_array($extension, $allowedExtensions)) {
        throw new Exception('Tipo de archivo no permitido. Solo se permiten archivos de audio (MP3, WAV, M4A, WebM, MP4, OGG, AAC, FLAC).');
    }
    
    // Validar tamaño (máximo 50MB)
    $maxSize = 50 * 1024 * 1024; // 50MB
    if ($audioFile['size'] > $maxSize) {
        throw new Exception('El archivo es demasiado grande. Máximo 50MB.');
    }
    
    // Generar nombre único para el archivo
    $extension = strtolower(pathinfo($audioFile['name'], PATHINFO_EXTENSION));
    if (!$extension) {
        // Determinar extensión por MIME type
        $extensionMap = [
            'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
            'audio/mp3' => 'mp3', 'audio/mpeg' => 'mp3', 'audio/mpeg3' => 'mp3', 'audio/x-mpeg-3' => 'mp3',
            'audio/ogg' => 'ogg', 'audio/vorbis' => 'ogg',
            'audio/webm' => 'webm',
            'audio/mp4' => 'mp4', 'audio/m4a' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac', 'audio/x-aac' => 'aac',
            'audio/flac' => 'flac', 'audio/x-flac' => 'flac'
        ];
        $extension = $extensionMap[$mimeType] ?? 'mp3';
    }
    
    $fileName = 'audio_' . $informeId . '_' . time() . '_' . uniqid() . '.' . $extension;
    $uploadDir = '../../uploads/audios/';
    $filePath = $uploadDir . $fileName;
    
    // Crear directorio si no existe
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true)) {
            throw new Exception('No se pudo crear el directorio de audios');
        }
        // Intentar cambiar el grupo a www-data si es posible
        @chgrp($uploadDir, 'www-data');
    }
    
    // Verificar que el directorio es escribible
    if (!is_writable($uploadDir)) {
        throw new Exception('El directorio de audios no tiene permisos de escritura. Verifique permisos del directorio: ' . $uploadDir);
    }
    
    // Mover archivo subido
    if (!move_uploaded_file($audioFile['tmp_name'], $filePath)) {
        $lastError = error_get_last();
        $errorDetails = $lastError ? $lastError['message'] : 'Error desconocido';
        throw new Exception('Error al guardar el archivo de audio: ' . $errorDetails);
    }
    
    // Obtener duración del audio si es posible (mismo archivo WebM/otros guardado en disco)
    $duracion = null;

    $pathForProbe = realpath($filePath);
    if ($pathForProbe === false) {
        $pathForProbe = realpath(__DIR__ . '/' . $filePath) ?: $filePath;
    }

    // 1) getID3
    if (function_exists('getid3_analyze')) {
        try {
            require_once '../../vendor/getid3/getid3.php';
            $getID3 = new getID3;
            $fileInfo = $getID3->analyze($filePath);
            if (isset($fileInfo['playtime_seconds'])) {
                $ps = (float) $fileInfo['playtime_seconds'];
                if ($ps > 0 && is_finite($ps)) {
                    $duracion = round($ps, 2);
                }
            }
        } catch (Exception $e) {
            error_log("getID3 falló para obtener duración: " . $e->getMessage());
        }
    }

    // 2) ffprobe (recomendado para WebM)
    if (!$duracion || $duracion <= 0) {
        $ffp = getAudioDurationWithFfprobe($pathForProbe);
        if ($ffp !== null && $ffp > 0) {
            $duracion = $ffp;
            error_log('upload.php: duración obtenida con ffprobe: ' . $duracion . 's — ' . basename($filePath));
        }
    }

    // 3) ffmpeg -i (stderr) — compatibilidad con despliegues sin ffprobe
    if (!$duracion || $duracion <= 0) {
        $ff = getAudioDurationWithFfmpeg($filePath);
        if ($ff && $ff > 0) {
            $duracion = round((float) $ff, 2);
        }
    }

    // 4) Cliente (blob ya medido en el navegador)
    if (!$duracion || $duracion <= 0) {
        $hint = uploadParseClientDurationHint();
        if ($hint !== null) {
            $duracion = $hint;
            error_log('upload.php: duración desde client_duration: ' . $duracion . 's — ' . basename($filePath));
        }
    }
    
    // Normalizar tipo de grabación (BD espera 'simple' o 'sincronizada')
    // Para archivos adjuntos, usar 'simple' por defecto
    $tipoGrabacionInput = $_POST['tipo_grabacion'] ?? 'simple';
    $tipoGrabacion = ($tipoGrabacionInput === 'sincronizada') ? 'sincronizada' : 'simple';
    
    // Preparar datos de sincronización
    $datosSincronizacion = null;
    if (isset($_POST['datos_sincronizacion'])) {
        $datosSincronizacion = $_POST['datos_sincronizacion'];
        // Asegurar que sea JSON válido o null
        if (is_array($datosSincronizacion)) {
            $datosSincronizacion = json_encode($datosSincronizacion);
        }
    }
    
    // Estado inicial:
    // - from_finalize + informe_id → guardado_informe (audio confirmado al finalizar informe)
    // - informe_id sin finalizar → listo_workspace (evita envío FTP/transcripción prematuro)
    // - sin informe_id → en_papelera
    $fromFinalize = isset($_POST['from_finalize']) && in_array((string)$_POST['from_finalize'], ['1', 'true', 'yes'], true);
    if ($fromFinalize && $informeId) {
        $estadoInicial = 'guardado_informe';
    } elseif ($informeId) {
        $estadoInicial = 'listo_workspace';
    } else {
        $estadoInicial = 'en_papelera';
    }
    
    // Guardar información del audio en la base de datos (incluye estudio_id y usuario_id)
    // Verificar si existe columna estado antes de insertar
    $columnsCheck = $db->query("SHOW COLUMNS FROM audios_informe LIKE 'estado'");
    $hasEstadoColumn = $columnsCheck->rowCount() > 0;
    
    if ($hasEstadoColumn) {
        $insertQuery = "INSERT INTO audios_informe (
                        informe_id, estudio_id, usuario_id, nombre_archivo, ruta_archivo, nombre_original,
                        tipo_mime, tamano_bytes, duracion_segundos, tipo_grabacion,
                        datos_sincronizacion, transcripcion_texto, mobile_session_id, estado
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            $informeId,
            $informeEstudioId,
            $userData['id'],
            $fileName,
            'uploads/audios/' . $fileName, // Ruta relativa
            $audioFile['name'],
            $mimeType,
            $audioFile['size'],
            $duracion,
            $tipoGrabacion,
            $datosSincronizacion,
            $_POST['transcripcion'] ?? $_POST['transcripcion_texto'] ?? null,
            $mobileSessionId ?? null,
            $estadoInicial
        ]);
    } else {
        // Fallback si la columna estado no existe aún
        $insertQuery = "INSERT INTO audios_informe (
                        informe_id, estudio_id, usuario_id, nombre_archivo, ruta_archivo, nombre_original,
                        tipo_mime, tamano_bytes, duracion_segundos, tipo_grabacion,
                        datos_sincronizacion, transcripcion_texto, mobile_session_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            $informeId,
            $informeEstudioId,
            $userData['id'],
            $fileName,
            'uploads/audios/' . $fileName, // Ruta relativa
            $audioFile['name'],
            $mimeType,
            $audioFile['size'],
            $duracion,
            $tipoGrabacion,
            $datosSincronizacion,
            $_POST['transcripcion'] ?? $_POST['transcripcion_texto'] ?? null,
            $mobileSessionId ?? null
        ]);
    }
    
    $audioId = $db->lastInsertId();
    
    // Verificar si la transcripción automática está activada y agregar a la cola
    // Los audios de grabadora móvil NO se auto-transcriben: deben pasar primero
    // por la lista de grabaciones del workspace y ser enviados desde allí.
    $isMobileUpload = (($_POST['tipo_grabacion'] ?? '') === 'mobile') || !empty($mobileSessionId);
    if ($isMobileUpload) {
        error_log("upload.php: Audio móvil ID $audioId — omitiendo auto-transcripción (flujo: grabadora → workspace → finalizar)");
    }
    try {
        error_log("DEBUG upload.php: Verificando si se debe agregar audio ID $audioId a la cola de transcripción");
        
        // Verificar si la tabla ai_config existe
        $configCheck = $db->query("SHOW TABLES LIKE 'ai_config'");
        if ($configCheck->rowCount() > 0) {
            error_log("DEBUG upload.php: Tabla ai_config existe");
            
            // Obtener configuración
            $configStmt = $db->prepare("SELECT auto_transcribe_enabled FROM ai_config WHERE id = 1");
            $configStmt->execute();
            $config = $configStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$isMobileUpload && $config && isset($config['auto_transcribe_enabled']) && $config['auto_transcribe_enabled']) {
                error_log("DEBUG upload.php: auto_transcribe_enabled está activado");

                require_once __DIR__ . '/../transcription_health.php';
                $txHealth = transcriptionCheckHealth($db, null, 4);
                if (empty($txHealth['allow_enqueue'])) {
                    error_log("upload.php: No se encola audio ID $audioId — Whisper no listo: " . ($txHealth['message'] ?? 'down'));
                } else {
                // Verificar si la tabla de cola existe
                $queueCheck = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
                if ($queueCheck->rowCount() > 0) {
                    error_log("DEBUG upload.php: Tabla ai_transcription_queue existe");
                    
                    // Obtener study_id si está disponible
                    $studyId = null;
                    $orthancStudyId = $informe['estudio_id'] ?? null;
                    
                    error_log("DEBUG upload.php: orthancStudyId desde informe: " . ($orthancStudyId ?? 'NULL'));
                    
                    if ($orthancStudyId) {
                        $studyStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                        $studyStmt->execute([$orthancStudyId]);
                        $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
                        if ($study) {
                            $studyId = $study['id'];
                            error_log("DEBUG upload.php: study_id local encontrado: " . $studyId);
                        } else {
                            error_log("DEBUG upload.php: No se encontró estudio local para orthanc_study_id: $orthancStudyId");
                        }
                    } else {
                        error_log("DEBUG upload.php: No hay orthancStudyId disponible");
                    }
                    
                    // Verificar si ya está en la cola o tiene transcripción completada
                    $checkQueueStmt = $db->prepare("
                        SELECT id FROM ai_transcription_queue 
                        WHERE audio_id = ? AND status IN ('pending', 'processing')
                        LIMIT 1
                    ");
                    $checkQueueStmt->execute([$audioId]);
                    $existingInQueue = $checkQueueStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $checkTranscriptionStmt = $db->prepare("
                        SELECT id FROM ai_transcriptions 
                        WHERE audio_id = ? AND status = 'completed'
                        LIMIT 1
                    ");
                    $checkTranscriptionStmt->execute([$audioId]);
                    $existingTranscription = $checkTranscriptionStmt->fetch(PDO::FETCH_ASSOC);
                    
                    error_log("DEBUG upload.php: existingInQueue: " . ($existingInQueue ? 'SÍ' : 'NO') . ", existingTranscription: " . ($existingTranscription ? 'SÍ' : 'NO'));
                    
                    // Solo agregar a la cola si no está ya en cola y no tiene transcripción completada
                    if (!$existingInQueue && !$existingTranscription) {
                        $insertQueueStmt = $db->prepare("
                            INSERT INTO ai_transcription_queue 
                            (audio_id, study_id, orthanc_study_id, status, priority, created_by, created_at)
                            VALUES (?, ?, ?, 'pending', 0, ?, NOW())
                        ");
                        $insertQueueStmt->execute([$audioId, $studyId, $orthancStudyId, $userData['id']]);
                        $queueId = $db->lastInsertId();
                        error_log("✅ Audio ID $audioId agregado automáticamente a la cola de transcripción (queue_id: $queueId, study_id: " . ($studyId ?? 'NULL') . ", orthanc_study_id: " . ($orthancStudyId ?? 'NULL') . ")");
                    } else {
                        if ($existingInQueue) {
                            error_log("DEBUG upload.php: Audio ID $audioId ya está en la cola (ID: " . $existingInQueue['id'] . ")");
                        }
                        if ($existingTranscription) {
                            error_log("DEBUG upload.php: Audio ID $audioId ya tiene transcripción completada (ID: " . $existingTranscription['id'] . ")");
                        }
                    }
                } else {
                    error_log("DEBUG upload.php: Tabla ai_transcription_queue NO existe");
                }
                } // end allow_enqueue
            } else {
                error_log("DEBUG upload.php: auto_transcribe_enabled está desactivado o no configurado");
            }
        } else {
            error_log("DEBUG upload.php: Tabla ai_config NO existe");
        }
    } catch (Exception $e) {
        // No fallar la subida si hay error al agregar a la cola
        error_log("❌ Error al agregar audio a la cola de transcripción: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    }
    
    // FTP: no se envía en upload. El envío ocurre al finalizar informe (workspace)
    // mediante api/audios/send-to-ftp.php, igual que la cola de transcripción.
    
    // Respuesta exitosa
    $responseData = [
        'success' => true,
        'message' => 'Audio subido exitosamente',
        'data' => [
            'audio_id' => $audioId,
            'informe_id' => $informeId,
            // Usar siempre $informeEstudioId (para móviles es el Orthanc Study ID aunque no haya informe)
            'estudio_id' => $informeEstudioId,
            'nombre_archivo' => $fileName,
            'ruta_archivo' => 'uploads/audios/' . $fileName,
            'nombre_original' => $audioFile['name'],
            'tipo_mime' => $mimeType,
            'tamano_bytes' => $audioFile['size'],
            'duracion_segundos' => $duracion,
            'tipo_grabacion' => $tipoGrabacion,
            'url_reproduccion' => '/uploads/audios/' . $fileName
        ]
    ];
    
    echo json_encode($responseData);
    
} catch (Exception $e) {
    // Limpiar archivo si se subió pero hubo error
    if (isset($filePath) && file_exists($filePath)) {
        unlink($filePath);
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'UPLOAD_AUDIO_ERROR'
    ]);
} catch (PDOException $e) {
    // Limpiar archivo si se subió pero hubo error de BD
    if (isset($filePath) && file_exists($filePath)) {
        unlink($filePath);
    }
    
    error_log("Error de base de datos en upload.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_code' => 'DATABASE_ERROR'
    ]);
}
?>