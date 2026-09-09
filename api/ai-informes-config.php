<?php
/**
 * API para configuración de AI Informes
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/OllamaAiClient.php';
require_once __DIR__ . '/../utils/WhisperClient.php';

if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        $database = new Database();
        return $database->getConnection();
    }
}

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
    
    // Verificar permisos de administración
    if (!in_array($userData['nivel'], ['root', 'admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
        exit();
    }
    
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Asegurar que las tablas existen
    ensureAiTables($db);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? null;
    
    if ($method === 'GET') {
        if ($action === 'list-models') {
            handleListModels($db);
        } elseif ($action === 'list-whisper-cli-models') {
            handleListWhisperCliModels($db);
        } else {
            handleGet($db);
        }
    } elseif ($method === 'POST') {
        if ($action === 'test') {
            handleTest($db);
        } elseif ($action === 'test-whisper') {
            handleTestWhisper($db);
        } elseif ($action === 'test-whisper-cli') {
            handleTestWhisperCli($db);
        } elseif ($action === 'test-ffmpeg-rest') {
            handleTestFfmpegRest($db);
        } else {
            handleSave($db);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
    
} catch (Exception $e) {
    error_log("Error en ai-informes-config.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

function handleGet($db) {
    $stmt = $db->prepare("SELECT * FROM ai_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // Configuración por defecto
        $config = [
            'id' => 1,
            'ollama_base_url' => 'http://localhost:11434',
            'whisper_api_url' => 'http://localhost:8080',
            'whisper_model' => 'base',
            'whisper_language' => 'es',
            'whisper_timeout' => 300,
            'ffmpeg_rest_url' => 'http://localhost:3000',
            'medgemma_model' => 'medgemma',
            'timeout' => 300,
            'max_audio_size_mb' => 25,
            'default_prompt' => null,
            'enabled' => 1
        ];
    }
    
    // Asegurar que los campos de whisper existan (compatibilidad con versiones anteriores)
    if (!isset($config['whisper_api_url'])) {
        $config['whisper_api_url'] = 'http://localhost:8080';
    }
    if (!isset($config['whisper_language'])) {
        $config['whisper_language'] = 'es';
    }
    if (!isset($config['whisper_timeout'])) {
        $config['whisper_timeout'] = $config['timeout'] ?? 300;
    }
    if (!isset($config['ffmpeg_rest_url'])) {
        $config['ffmpeg_rest_url'] = 'http://localhost:3000';
    }
    
    // Asegurar que los campos de cola existan
    if (!isset($config['auto_transcribe_enabled'])) {
        $config['auto_transcribe_enabled'] = 0;
    }
    if (!isset($config['max_concurrent_transcriptions'])) {
        $config['max_concurrent_transcriptions'] = 1;
    }
    
    // Asegurar que los campos nuevos existan
    if (!isset($config['whisper_method'])) {
        $config['whisper_method'] = 'whisper-server';
    }
    if (!isset($config['whisper_cli_api_url'])) {
        $config['whisper_cli_api_url'] = 'http://localhost:3001';
    }
    
    // Asegurar que los campos de resaltado de transcripción existan
    if (!isset($config['transcription_highlight_throttle_ms'])) {
        $config['transcription_highlight_throttle_ms'] = 30;
    }
    if (!isset($config['transcription_highlight_audio_offset'])) {
        $config['transcription_highlight_audio_offset'] = -0.1;
    }
    if (!isset($config['transcription_highlight_scroll_behavior'])) {
        $config['transcription_highlight_scroll_behavior'] = 'smooth';
    }
    if (!isset($config['transcription_highlight_enable_auto_scroll'])) {
        $config['transcription_highlight_enable_auto_scroll'] = 1;
    }
    if (!isset($config['transcription_highlight_active_color'])) {
        $config['transcription_highlight_active_color'] = '#ffeb3b';
    }
    if (!isset($config['transcription_highlight_active_bg'])) {
        $config['transcription_highlight_active_bg'] = '#ffeb3b';
    }
    if (!isset($config['transcription_highlight_hover_color'])) {
        $config['transcription_highlight_hover_color'] = '#1976d2';
    }
    if (!isset($config['transcription_highlight_hover_bg'])) {
        $config['transcription_highlight_hover_bg'] = '#e3f2fd';
    }
    if (!isset($config['transcription_highlight_font_weight'])) {
        $config['transcription_highlight_font_weight'] = 600;
    }
    if (!isset($config['transcription_highlight_transition_duration'])) {
        $config['transcription_highlight_transition_duration'] = 0.2;
    }
    if (!isset($config['transcription_highlight_config_ui_type'])) {
        $config['transcription_highlight_config_ui_type'] = 'modal'; // 'modal' | 'offcanvas'
    }
    if (!isset($config['transcription_highlight_enable_preview'])) {
        $config['transcription_highlight_enable_preview'] = 1;
    }
    
    echo json_encode([
        'success' => true,
        'config' => $config
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Función auxiliar para agregar campos de resaltado de transcripción
 */
function addHighlightFields(&$updateFields, &$updateValues, $input, $hasHighlightColumns) {
    if ($hasHighlightColumns) {
        $updateFields[] = 'transcription_highlight_throttle_ms = ?';
        $updateValues[] = isset($input['transcription_highlight_throttle_ms']) ? (int)$input['transcription_highlight_throttle_ms'] : 30;
        
        $updateFields[] = 'transcription_highlight_audio_offset = ?';
        $updateValues[] = isset($input['transcription_highlight_audio_offset']) ? (float)$input['transcription_highlight_audio_offset'] : -0.1;
        
        $updateFields[] = 'transcription_highlight_scroll_behavior = ?';
        $updateValues[] = $input['transcription_highlight_scroll_behavior'] ?? 'smooth';
        
        $updateFields[] = 'transcription_highlight_enable_auto_scroll = ?';
        $updateValues[] = isset($input['transcription_highlight_enable_auto_scroll']) ? (int)$input['transcription_highlight_enable_auto_scroll'] : 1;
        
        $updateFields[] = 'transcription_highlight_active_color = ?';
        $updateValues[] = $input['transcription_highlight_active_color'] ?? '#ffeb3b';
        
        $updateFields[] = 'transcription_highlight_active_bg = ?';
        $updateValues[] = $input['transcription_highlight_active_bg'] ?? '#ffeb3b';
        
        $updateFields[] = 'transcription_highlight_hover_color = ?';
        $updateValues[] = $input['transcription_highlight_hover_color'] ?? '#1976d2';
        
        $updateFields[] = 'transcription_highlight_hover_bg = ?';
        $updateValues[] = $input['transcription_highlight_hover_bg'] ?? '#e3f2fd';
        
        $updateFields[] = 'transcription_highlight_font_weight = ?';
        $updateValues[] = isset($input['transcription_highlight_font_weight']) ? (int)$input['transcription_highlight_font_weight'] : 600;
        
        $updateFields[] = 'transcription_highlight_transition_duration = ?';
        $updateValues[] = isset($input['transcription_highlight_transition_duration']) ? (float)$input['transcription_highlight_transition_duration'] : 0.2;
        
        $updateFields[] = 'transcription_highlight_config_ui_type = ?';
        $updateValues[] = $input['transcription_highlight_config_ui_type'] ?? 'modal';
        
        $updateFields[] = 'transcription_highlight_enable_preview = ?';
        $updateValues[] = isset($input['transcription_highlight_enable_preview']) ? (int)$input['transcription_highlight_enable_preview'] : 1;
    }
}

function handleSave($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos');
    }
    
    // Verificar si las columnas de whisper existen
    $columnsCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'whisper_api_url'");
    $hasWhisperColumns = $columnsCheck->rowCount() > 0;
    
    if ($hasWhisperColumns) {
        // Verificar si existe la columna default_prompt
        $promptColumnCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'default_prompt'");
        $hasPromptColumn = $promptColumnCheck->rowCount() > 0;
        
        // Verificar si existe la columna ffmpeg_rest_url
        $ffmpegRestColumnCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'ffmpeg_rest_url'");
        $hasFfmpegRestColumn = $ffmpegRestColumnCheck->rowCount() > 0;
        
        // Verificar si existen las columnas de cola
        $autoTranscribeColumnCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'auto_transcribe_enabled'");
        $hasAutoTranscribeColumn = $autoTranscribeColumnCheck->rowCount() > 0;
        
        $maxConcurrentColumnCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'max_concurrent_transcriptions'");
        $hasMaxConcurrentColumn = $maxConcurrentColumnCheck->rowCount() > 0;
        
        // Verificar columnas nuevas para whisper-cli
        $methodColumnCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'whisper_method'");
        $hasMethodColumn = $methodColumnCheck->rowCount() > 0;
        
        $cliUrlColumnCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'whisper_cli_api_url'");
        $hasCliUrlColumn = $cliUrlColumnCheck->rowCount() > 0;
        
        // Verificar columnas de resaltado de transcripción
        $highlightThrottleCheck = $db->query("SHOW COLUMNS FROM ai_config LIKE 'transcription_highlight_throttle_ms'");
        $hasHighlightColumns = $highlightThrottleCheck->rowCount() > 0;
        
        if ($hasPromptColumn) {
            if ($hasFfmpegRestColumn) {
                // Construir query dinámicamente según qué columnas existen
                $updateFields = [
                    'ollama_base_url = ?',
                    'whisper_api_url = ?',
                    'whisper_model = ?',
                    'whisper_language = ?',
                    'whisper_timeout = ?',
                    'ffmpeg_rest_url = ?',
                    'medgemma_model = ?',
                    'timeout = ?',
                    'max_audio_size_mb = ?',
                    'default_prompt = ?'
                ];
                $updateValues = [
                    $input['ollama_base_url'] ?? 'http://localhost:11434',
                    $input['whisper_api_url'] ?? 'http://localhost:8080',
                    $input['whisper_model'] ?? 'base',
                    $input['whisper_language'] ?? 'es',
                    $input['whisper_timeout'] ?? 300,
                    $input['ffmpeg_rest_url'] ?? 'http://localhost:3000',
                    $input['medgemma_model'] ?? 'medgemma',
                    $input['timeout'] ?? 300,
                    $input['max_audio_size_mb'] ?? 25,
                    $input['custom_prompt'] ?? $input['default_prompt'] ?? null
                ];
                
                if ($hasAutoTranscribeColumn) {
                    $updateFields[] = 'auto_transcribe_enabled = ?';
                    $updateValues[] = isset($input['auto_transcribe_enabled']) ? (int)$input['auto_transcribe_enabled'] : 0;
                }
                if ($hasMaxConcurrentColumn) {
                    $updateFields[] = 'max_concurrent_transcriptions = ?';
                    $updateValues[] = isset($input['max_concurrent_transcriptions']) ? (int)$input['max_concurrent_transcriptions'] : 1;
                }
                if ($hasMethodColumn) {
                    $updateFields[] = 'whisper_method = ?';
                    $updateValues[] = $input['whisper_method'] ?? 'whisper-server';
                }
                if ($hasCliUrlColumn) {
                    $updateFields[] = 'whisper_cli_api_url = ?';
                    $updateValues[] = $input['whisper_cli_api_url'] ?? 'http://localhost:3001';
                }
                
                // Agregar campos de resaltado de transcripción
                addHighlightFields($updateFields, $updateValues, $input, $hasHighlightColumns);
                
                $updateFields[] = 'updated_at = NOW()';
                
                $stmt = $db->prepare("
                    UPDATE ai_config SET
                        " . implode(",\n                        ", $updateFields) . "
                    WHERE id = 1
                ");
                
                $stmt->execute($updateValues);
            } else {
                $updateFields = [
                    'ollama_base_url = ?',
                    'whisper_api_url = ?',
                    'whisper_model = ?',
                    'whisper_language = ?',
                    'whisper_timeout = ?',
                    'medgemma_model = ?',
                    'timeout = ?',
                    'max_audio_size_mb = ?',
                    'default_prompt = ?'
                ];
                $updateValues = [
                    $input['ollama_base_url'] ?? 'http://localhost:11434',
                    $input['whisper_api_url'] ?? 'http://localhost:8080',
                    $input['whisper_model'] ?? 'base',
                    $input['whisper_language'] ?? 'es',
                    $input['whisper_timeout'] ?? 300,
                    $input['medgemma_model'] ?? 'medgemma',
                    $input['timeout'] ?? 300,
                    $input['max_audio_size_mb'] ?? 25,
                    $input['custom_prompt'] ?? $input['default_prompt'] ?? null
                ];
                
                if ($hasAutoTranscribeColumn) {
                    $updateFields[] = 'auto_transcribe_enabled = ?';
                    $updateValues[] = isset($input['auto_transcribe_enabled']) ? (int)$input['auto_transcribe_enabled'] : 0;
                }
                if ($hasMaxConcurrentColumn) {
                    $updateFields[] = 'max_concurrent_transcriptions = ?';
                    $updateValues[] = isset($input['max_concurrent_transcriptions']) ? (int)$input['max_concurrent_transcriptions'] : 1;
                }
                if ($hasMethodColumn) {
                    $updateFields[] = 'whisper_method = ?';
                    $updateValues[] = $input['whisper_method'] ?? 'whisper-server';
                }
                if ($hasCliUrlColumn) {
                    $updateFields[] = 'whisper_cli_api_url = ?';
                    $updateValues[] = $input['whisper_cli_api_url'] ?? 'http://localhost:3001';
                }
                
                // Agregar campos de resaltado de transcripción
                addHighlightFields($updateFields, $updateValues, $input, $hasHighlightColumns);
                
                $updateFields[] = 'updated_at = NOW()';
                
                $stmt = $db->prepare("
                    UPDATE ai_config SET
                        " . implode(",\n                        ", $updateFields) . "
                    WHERE id = 1
                ");
                
                $stmt->execute($updateValues);
            }
        } else {
            if ($hasFfmpegRestColumn) {
                $updateFields = [
                    'ollama_base_url = ?',
                    'whisper_api_url = ?',
                    'whisper_model = ?',
                    'whisper_language = ?',
                    'whisper_timeout = ?',
                    'ffmpeg_rest_url = ?',
                    'medgemma_model = ?',
                    'timeout = ?',
                    'max_audio_size_mb = ?'
                ];
                $updateValues = [
                    $input['ollama_base_url'] ?? 'http://localhost:11434',
                    $input['whisper_api_url'] ?? 'http://localhost:8080',
                    $input['whisper_model'] ?? 'base',
                    $input['whisper_language'] ?? 'es',
                    $input['whisper_timeout'] ?? 300,
                    $input['ffmpeg_rest_url'] ?? 'http://localhost:3000',
                    $input['medgemma_model'] ?? 'medgemma',
                    $input['timeout'] ?? 300,
                    $input['max_audio_size_mb'] ?? 25
                ];
                
                if ($hasAutoTranscribeColumn) {
                    $updateFields[] = 'auto_transcribe_enabled = ?';
                    $updateValues[] = isset($input['auto_transcribe_enabled']) ? (int)$input['auto_transcribe_enabled'] : 0;
                }
                if ($hasMaxConcurrentColumn) {
                    $updateFields[] = 'max_concurrent_transcriptions = ?';
                    $updateValues[] = isset($input['max_concurrent_transcriptions']) ? (int)$input['max_concurrent_transcriptions'] : 1;
                }
                if ($hasMethodColumn) {
                    $updateFields[] = 'whisper_method = ?';
                    $updateValues[] = $input['whisper_method'] ?? 'whisper-server';
                }
                if ($hasCliUrlColumn) {
                    $updateFields[] = 'whisper_cli_api_url = ?';
                    $updateValues[] = $input['whisper_cli_api_url'] ?? 'http://localhost:3001';
                }
                
                $updateFields[] = 'updated_at = NOW()';
                
                $stmt = $db->prepare("
                    UPDATE ai_config SET
                        " . implode(",\n                        ", $updateFields) . "
                    WHERE id = 1
                ");
                
                $stmt->execute($updateValues);
            } else {
                $updateFields = [
                    'ollama_base_url = ?',
                    'whisper_api_url = ?',
                    'whisper_model = ?',
                    'whisper_language = ?',
                    'whisper_timeout = ?',
                    'medgemma_model = ?',
                    'timeout = ?',
                    'max_audio_size_mb = ?'
                ];
                $updateValues = [
                    $input['ollama_base_url'] ?? 'http://localhost:11434',
                    $input['whisper_api_url'] ?? 'http://localhost:8080',
                    $input['whisper_model'] ?? 'base',
                    $input['whisper_language'] ?? 'es',
                    $input['whisper_timeout'] ?? 300,
                    $input['medgemma_model'] ?? 'medgemma',
                    $input['timeout'] ?? 300,
                    $input['max_audio_size_mb'] ?? 25
                ];
                
                if ($hasAutoTranscribeColumn) {
                    $updateFields[] = 'auto_transcribe_enabled = ?';
                    $updateValues[] = isset($input['auto_transcribe_enabled']) ? (int)$input['auto_transcribe_enabled'] : 0;
                }
                if ($hasMaxConcurrentColumn) {
                    $updateFields[] = 'max_concurrent_transcriptions = ?';
                    $updateValues[] = isset($input['max_concurrent_transcriptions']) ? (int)$input['max_concurrent_transcriptions'] : 1;
                }
                if ($hasMethodColumn) {
                    $updateFields[] = 'whisper_method = ?';
                    $updateValues[] = $input['whisper_method'] ?? 'whisper-server';
                }
                if ($hasCliUrlColumn) {
                    $updateFields[] = 'whisper_cli_api_url = ?';
                    $updateValues[] = $input['whisper_cli_api_url'] ?? 'http://localhost:3001';
                }
                
                // Agregar campos de resaltado de transcripción
                addHighlightFields($updateFields, $updateValues, $input, $hasHighlightColumns);
                
                $updateFields[] = 'updated_at = NOW()';
                
                $stmt = $db->prepare("
                    UPDATE ai_config SET
                        " . implode(",\n                        ", $updateFields) . "
                    WHERE id = 1
                ");
                
                $stmt->execute($updateValues);
            }
        }
    } else {
        // Fallback para versiones anteriores sin columnas de whisper
        $stmt = $db->prepare("
            UPDATE ai_config SET
                ollama_base_url = ?,
                medgemma_model = ?,
                timeout = ?,
                max_audio_size_mb = ?,
                updated_at = NOW()
            WHERE id = 1
        ");
        
        $stmt->execute([
            $input['ollama_base_url'] ?? 'http://localhost:11434',
            $input['medgemma_model'] ?? 'medgemma',
            $input['timeout'] ?? 300,
            $input['max_audio_size_mb'] ?? 25
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuración guardada exitosamente'
    ]);
}

function handleTest($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['ollama_base_url'])) {
        throw new Exception('URL de Ollama requerida');
    }
    
    $client = new OllamaAiClient([
        'ollama_base_url' => $input['ollama_base_url']
    ]);
    
    $result = $client->testConnection();
    
    echo json_encode($result);
}

function handleListModels($db) {
    // Obtener configuración actual para usar la URL de Ollama
    $stmt = $db->prepare("SELECT ollama_base_url FROM ai_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $ollamaUrl = $config['ollama_base_url'] ?? 'http://localhost:11434';
    
    // También permitir recibir la URL como parámetro
    $ollamaUrl = $_GET['ollama_url'] ?? $ollamaUrl;
    
    $client = new OllamaAiClient([
        'ollama_base_url' => $ollamaUrl
    ]);
    
    $result = $client->listModels();
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

function handleTestWhisper($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['whisper_api_url'])) {
        throw new Exception('URL de whisper.cpp requerida');
    }
    
    $client = new WhisperClient([
        'whisper_api_url' => $input['whisper_api_url'],
        'whisper_timeout' => $input['whisper_timeout'] ?? 10
    ]);
    
    $result = $client->testConnection();
    
    echo json_encode($result);
}

function handleTestFfmpegRest($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['ffmpeg_rest_url'])) {
        throw new Exception('URL de ffmpeg-rest requerida');
    }
    
    $ffmpegRestUrl = rtrim($input['ffmpeg_rest_url'], '/');
    
    // Probar endpoint /endpoints que devuelve lista de endpoints disponibles
    $ch = curl_init($ffmpegRestUrl . '/endpoints');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo json_encode([
            'success' => false,
            'message' => 'Error de conexión: ' . $error
        ]);
        return;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if ($data && isset($data['endpoints'])) {
            echo json_encode([
                'success' => true,
                'message' => 'Conexión exitosa con ffmpeg-rest',
                'endpoints' => count($data['endpoints']) . ' endpoints disponibles'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Conexión exitosa con ffmpeg-rest'
            ]);
        }
    } else {
        // Si /endpoints no funciona, probar el endpoint raíz
        $ch2 = curl_init($ffmpegRestUrl);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        
        $response2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        
        if ($httpCode2 === 200 || $httpCode2 === 301 || $httpCode2 === 302) {
            echo json_encode([
                'success' => true,
                'message' => 'Conexión exitosa con ffmpeg-rest'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Error HTTP ' . $httpCode2 . '. Verifica la URL de ffmpeg-rest.'
            ]);
        }
    }
}

/**
 * Listar modelos disponibles en whisper-cli API
 */
function handleListWhisperCliModels($db) {
    // Obtener configuración actual para usar la URL de whisper-cli
    $stmt = $db->prepare("SELECT whisper_cli_api_url FROM ai_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $cliApiUrl = $config['whisper_cli_api_url'] ?? 'http://localhost:3001';
    
    // También permitir recibir la URL como parámetro
    $cliApiUrl = $_GET['whisper_cli_api_url'] ?? $cliApiUrl;
    
    if (empty($cliApiUrl)) {
        echo json_encode([
            'success' => false,
            'message' => 'URL de whisper-cli API no configurada',
            'models' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Hacer petición a la API de whisper-cli desde el backend
    $url = rtrim($cliApiUrl, '/') . '/api/models';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo json_encode([
            'success' => false,
            'message' => 'Error al conectar con whisper-cli API: ' . $error,
            'models' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($httpCode !== 200) {
        echo json_encode([
            'success' => false,
            'message' => "Error HTTP $httpCode al obtener modelos de whisper-cli",
            'models' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $models = json_decode($response, true);
    
    if (!is_array($models)) {
        echo json_encode([
            'success' => false,
            'message' => 'Respuesta inválida de whisper-cli API',
            'models' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'models' => $models,
        'count' => count($models)
    ], JSON_UNESCAPED_UNICODE);
}

function handleTestWhisperCli($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['whisper_cli_api_url'])) {
        throw new Exception('URL de whisper-cli API requerida');
    }

    require_once __DIR__ . '/transcription_health.php';
    $health = transcriptionCheckHealth(null, [
        'whisper_method' => 'whisper-cli',
        'whisper_cli_api_url' => $input['whisper_cli_api_url'],
        'whisper_timeout' => $input['whisper_timeout'] ?? 10,
    ], 4);

    echo json_encode([
        'success' => !empty($health['allow_enqueue']),
        'message' => $health['message'] ?? '',
        'ready' => !empty($health['ready']),
        'status' => $health['status'] ?? 'down',
        'degraded' => !empty($health['degraded']),
        'allow_enqueue' => !empty($health['allow_enqueue']),
        'http_code' => $health['http_code'] ?? null,
        'url' => $health['url'] ?? null,
        'raw' => $health['raw'] ?? null,
    ]);
}

function ensureAiTables($db) {
    $sqlFile = __DIR__ . '/../database/create_ai_tables.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/i', $sql, $matches);
        foreach ($matches[0] as $createTableSql) {
            try {
                $db->exec($createTableSql);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    error_log("Error creando tabla AI: " . $e->getMessage());
                }
            }
        }
    }
}
