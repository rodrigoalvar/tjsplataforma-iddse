<?php
/**
 * Cliente para interactuar con whisper.cpp REST API
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Funcionalidades:
 * - Transcripción de audio usando whisper.cpp
 */

/**
 * Formatear duración en segundos a formato legible (MM:SS o HH:MM:SS)
 */
function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = floor($seconds % 60);
    
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        return sprintf('%d:%02d', $minutes, $secs);
    }
}

class WhisperClient {
    private $apiUrl;
    private $timeout;
    private $model;
    private $language;
    private $ffmpegRestUrl;
    private $method;  // 'whisper-server' o 'whisper-cli'
    private $cliApiUrl;  // URL para whisper-cli
    
    public function __construct($config = []) {
        $this->apiUrl = $config['whisper_api_url'] ?? 'http://localhost:8080';
        // Timeout más largo por defecto (600 segundos = 10 minutos) para archivos grandes
        $this->timeout = $config['whisper_timeout'] ?? 600;
        $this->model = $config['whisper_model'] ?? 'base';
        $this->language = $config['whisper_language'] ?? 'es';
        $this->ffmpegRestUrl = $config['ffmpeg_rest_url'] ?? null;
        $this->method = $config['whisper_method'] ?? 'whisper-server';  // Por defecto usa whisper-server
        $this->cliApiUrl = $config['whisper_cli_api_url'] ?? 'http://localhost:3001';
        
        // Asegurar que la URL no termine en /
        $this->apiUrl = rtrim($this->apiUrl, '/');
        if ($this->ffmpegRestUrl) {
            $this->ffmpegRestUrl = rtrim($this->ffmpegRestUrl, '/');
        }
        $this->cliApiUrl = rtrim($this->cliApiUrl, '/');
    }
    
    /**
     * Convertir archivo de audio remotamente usando ffmpeg-rest API
     * 
     * @param string $audioFilePath Ruta al archivo de audio original
     * @return array ['success' => bool, 'converted_path' => string, 'temp_file' => bool, 'error' => string]
     */
    private function convertAudioRemotely($audioFilePath) {
        // Verificar que ffmpeg-rest esté configurado
        if (!$this->ffmpegRestUrl) {
            return [
                'success' => false,
                'error' => 'ffmpeg-rest no está configurado. Configura la URL de ffmpeg-rest en Configuración → AI Informes.'
            ];
        }
        
        $fileName = basename($audioFilePath);
        $mimeType = mime_content_type($audioFilePath) ?: 'audio/mp4';
        
        // Endpoint de ffmpeg-rest para convertir a MP3
        // Según la documentación: POST /audio/mp3 devuelve archivo binario directamente
        $convertUrl = $this->ffmpegRestUrl . '/audio/mp3';
        
        error_log("WhisperClient: Intentando conversión con ffmpeg-rest: $convertUrl");
        
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
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("WhisperClient: Error cURL con ffmpeg-rest: $error");
            return [
                'success' => false,
                'error' => "Error al conectar con ffmpeg-rest: $error. Verifica que ffmpeg-rest esté corriendo en {$this->ffmpegRestUrl}"
            ];
        }
        
        if ($httpCode === 200 && !empty($response)) {
            // ffmpeg-rest devuelve el archivo MP3 directamente como binario
            // Content-Type debería ser audio/mpeg
            $tempFile = sys_get_temp_dir() . '/whisper_convert_' . uniqid() . '.mp3';
            
            if (file_put_contents($tempFile, $response) !== false && filesize($tempFile) > 0) {
                error_log("WhisperClient: Conversión exitosa con ffmpeg-rest. Archivo guardado: " . basename($tempFile) . " (" . round(filesize($tempFile) / 1024, 2) . " KB)");
                
                // Obtener duración del MP3 convertido
                $duracionMp3 = $this->getAudioDurationFromFile($tempFile);
                
                $result = [
                    'success' => true,
                    'converted_path' => $tempFile,
                    'temp_file' => true
                ];
                
                if ($duracionMp3 && $duracionMp3 > 0) {
                    $result['converted_audio_duration'] = $duracionMp3;
                    error_log("WhisperClient: Duración del MP3 convertido: {$duracionMp3} segundos");
                }
                
                return $result;
            } else {
                error_log("WhisperClient: Error al guardar archivo convertido");
                return [
                    'success' => false,
                    'error' => 'Error al guardar el archivo convertido desde ffmpeg-rest'
                ];
            }
        } elseif ($httpCode === 400 || $httpCode === 500) {
            // Error del servidor - puede ser JSON con detalles
            $errorData = json_decode($response, true);
            $errorMsg = isset($errorData['error']) ? $errorData['error'] : 'Error desconocido';
            $message = isset($errorData['message']) ? $errorData['message'] : '';
            
            error_log("WhisperClient: Error de ffmpeg-rest (HTTP $httpCode): $errorMsg - $message");
            return [
                'success' => false,
                'error' => "Error de ffmpeg-rest: $errorMsg" . ($message ? " - $message" : "")
            ];
        } else {
            error_log("WhisperClient: Respuesta inesperada de ffmpeg-rest: HTTP $httpCode, Content-Type: $contentType");
            return [
                'success' => false,
                'error' => "Respuesta inesperada de ffmpeg-rest (HTTP $httpCode). Verifica la configuración de ffmpeg-rest."
            ];
        }
    }
    
    /**
     * Obtener duración del audio usando ffmpeg o getID3
     * @param string $filePath Ruta completa al archivo de audio
     * @return float|null Duración en segundos o null si no se puede obtener
     */
    private function getAudioDurationFromFile($filePath) {
        // Intentar primero con getID3 si está disponible
        if (function_exists('getid3_analyze')) {
            try {
                require_once __DIR__ . '/../vendor/getid3/getid3.php';
                $getID3 = new getID3;
                $fileInfo = $getID3->analyze($filePath);
                if (isset($fileInfo['playtime_seconds'])) {
                    return (float)$fileInfo['playtime_seconds'];
                }
            } catch (Exception $e) {
                // Continuar con ffmpeg si getID3 falla
            }
        }
        
        // Fallback: usar ffmpeg
        $ffmpegPath = shell_exec('which ffmpeg 2>&1');
        if (empty(trim($ffmpegPath))) {
            return null;
        }
        
        $command = "ffmpeg -i " . escapeshellarg($filePath) . " 2>&1 | grep 'Duration' | head -1";
        $output = shell_exec($command);
        
        if (empty($output)) {
            return null;
        }
        
        // Parsear salida: "Duration: 00:03:45.67, start: 0.000000, bitrate: 64 kb/s"
        if (preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}\.?\d*)/', $output, $matches)) {
            $hours = (int)$matches[1];
            $minutes = (int)$matches[2];
            $seconds = (float)$matches[3];
            
            return (float)($hours * 3600 + $minutes * 60 + $seconds);
        }
        
        return null;
    }
    
    /**
     * Convertir archivo de audio usando SSH para ejecutar ffmpeg en el servidor remoto
     * 
     * @param string $audioFilePath Ruta al archivo de audio original
     * @param string $remoteHost IP o hostname del servidor remoto
     * @return array ['success' => bool, 'converted_path' => string, 'temp_file' => bool, 'error' => string]
     */
    private function convertAudioViaSSH($audioFilePath, $remoteHost) {
        // Verificar si ssh2 está disponible
        if (!function_exists('ssh2_connect')) {
            return [
                'success' => false,
                'error' => 'La extensión SSH2 de PHP no está instalada. ' .
                           'Instala con: sudo apt-get install php-ssh2 (Ubuntu/Debian) ' .
                           'O configura un endpoint de conversión en el servidor de Whisper.'
            ];
        }
        
        // Intentar conexión SSH (requiere configuración de credenciales)
        // Por ahora, retornar error indicando que se necesita configuración
        return [
            'success' => false,
            'error' => 'Conversión remota no configurada. ' .
                       'Opciones: 1) Crear endpoint de conversión en servidor Whisper, ' .
                       '2) Instalar php-ssh2 y configurar credenciales SSH, ' .
                       '3) Instalar ffmpeg en este servidor.'
        ];
    }
    
    /**
     * Convertir archivo de audio a formato soportado por whisper.cpp (MP3 o WAV)
     * Intenta conversión remota primero, luego local como fallback
     * 
     * @param string $audioFilePath Ruta al archivo de audio original
     * @return array ['success' => bool, 'converted_path' => string, 'temp_file' => bool, 'error' => string]
     */
    private function convertAudioToSupportedFormat($audioFilePath) {
        $ext = strtolower(pathinfo($audioFilePath, PATHINFO_EXTENSION));
        
        // Verificar el tipo MIME real del archivo (más confiable que la extensión)
        $mimeType = mime_content_type($audioFilePath);
        $isWebM = false;
        $isM4A = false;
        
        if ($mimeType) {
            if (strpos($mimeType, 'webm') !== false) {
                $isWebM = true;
                error_log("WhisperClient: Archivo detectado como WebM por MIME type: $mimeType (extensión: $ext)");
            } elseif (strpos($mimeType, 'mp4') !== false || strpos($mimeType, 'm4a') !== false) {
                $isM4A = true;
                error_log("WhisperClient: Archivo detectado como M4A/MP4 por MIME type: $mimeType (extensión: $ext)");
            }
        }
        
        // Formatos soportados directamente por whisper.cpp (sin conversión)
        $supportedFormats = ['mp3', 'wav', 'ogg', 'flac'];
        
        // Si la extensión es .wav pero el MIME type es WebM, necesita conversión
        if (in_array($ext, $supportedFormats) && !$isWebM && !$isM4A) {
            // Ya está en formato soportado y el MIME type coincide
            return [
                'success' => true,
                'converted_path' => $audioFilePath,
                'temp_file' => false
            ];
        }
        
        // Formatos que necesitan conversión a MP3/WAV
        // WebM se convierte porque whisper.cpp puede tener problemas leyendo algunos codecs WebM
        // También convertir si la extensión es .wav pero el contenido es WebM
        $needsConversion = ['m4a', 'aac', 'mp4', 'm4v', 'webm'];
        
        if ($isWebM || $isM4A || in_array($ext, $needsConversion)) {
            // Necesita conversión
            error_log("WhisperClient: Archivo necesita conversión. Ext: $ext, MIME: $mimeType, isWebM: " . ($isWebM ? 'sí' : 'no') . ", isM4A: " . ($isM4A ? 'sí' : 'no'));
        } else {
            return [
                'success' => false,
                'error' => "Formato de audio no soportado: $ext (MIME: $mimeType). Formatos soportados: MP3, WAV, OGG, FLAC, WebM, M4A, AAC, MP4"
            ];
        }
        
        // Si ffmpeg-rest está configurado, intentar conversión remota primero
        if ($this->ffmpegRestUrl) {
            error_log("WhisperClient: ffmpeg-rest configurado ({$this->ffmpegRestUrl}). Intentando conversión remota de $ext...");
            $remoteResult = $this->convertAudioRemotely($audioFilePath);
            if ($remoteResult['success']) {
                return $remoteResult;
            }
            // Si ffmpeg-rest está configurado pero falla, intentar fallback local
            error_log("WhisperClient: Conversión remota con ffmpeg-rest falló: " . ($remoteResult['error'] ?? 'desconocido') . ". Intentando conversión local como fallback...");
            // Continuar con conversión local (no retornar error aquí)
        }
        
        // Si ffmpeg-rest NO está configurado, intentar conversión local como fallback
        error_log("WhisperClient: ffmpeg-rest no está configurado. Intentando conversión local como fallback...");
        
        // Verificar si ffmpeg está disponible localmente
        // Intentar múltiples métodos para encontrar ffmpeg
        $ffmpegPath = null;
        
        // Método 1: Probar comando directo primero (más confiable si PATH está configurado)
        // Esto funciona incluso si file_exists() falla por restricciones de open_basedir
        $testCmd = @shell_exec('ffmpeg -version 2>&1');
        if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
            $ffmpegPath = 'ffmpeg';
            error_log("WhisperClient: ffmpeg encontrado por comando directo (funciona desde PATH)");
        }
        
        // Método 2: which ffmpeg (puede funcionar aunque file_exists falle)
        if (!$ffmpegPath) {
            $whichResult = @shell_exec('which ffmpeg 2>/dev/null');
            if ($whichResult && trim($whichResult)) {
                $testCmd = @shell_exec(escapeshellarg(trim($whichResult)) . ' -version 2>&1');
                if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
                    $ffmpegPath = trim($whichResult);
                    error_log("WhisperClient: ffmpeg encontrado con 'which': $ffmpegPath");
                }
            }
        }
        
        // Método 3: Buscar en PATH usando command -v
        if (!$ffmpegPath) {
            $output = [];
            $returnCode = 0;
            @exec('command -v ffmpeg 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                $testCmd = @shell_exec(escapeshellarg(trim($output[0])) . ' -version 2>&1');
                if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
                    $ffmpegPath = trim($output[0]);
                    error_log("WhisperClient: ffmpeg encontrado con 'command -v': $ffmpegPath");
                }
            }
        }
        
        // Método 4: Probar rutas comunes (último recurso)
        // Nota: file_exists() puede fallar si open_basedir está restringido, así que probamos ejecución directa
        if (!$ffmpegPath) {
            $commonPaths = [
                '/usr/bin/ffmpeg',  // Prioridad: ruta más común en Ubuntu/Debian
                '/usr/local/bin/ffmpeg',
                '/bin/ffmpeg',
                '/opt/ffmpeg/bin/ffmpeg'
            ];
            foreach ($commonPaths as $path) {
                // Intentar ejecutar directamente (más confiable que file_exists si hay restricciones)
                $testCmd = @shell_exec(escapeshellarg($path) . ' -version 2>&1');
                if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
                    $ffmpegPath = $path;
                    error_log("WhisperClient: ffmpeg encontrado por ejecución directa en: $path");
                    break;
                }
            }
        }
        
        if (!$ffmpegPath) {
            // Log detallado para debugging
            $pathEnv = getenv('PATH') ?: 'no definido';
            error_log("WhisperClient: No se pudo encontrar ffmpeg. Métodos probados:");
            error_log("  - PATH del sistema: $pathEnv");
            error_log("  - Comando directo 'ffmpeg': " . (isset($testCmd) && $testCmd ? substr($testCmd, 0, 50) : 'no encontrado'));
            error_log("  - which ffmpeg: " . ($whichResult ?: 'falló'));
            
            return [
                'success' => false,
                'error' => "El archivo M4A/AAC necesita conversión pero ffmpeg no se encontró en el sistema. " .
                           "SOLUCIÓN: Ejecuta el script para configurar PATH en PHP-FPM: " .
                           "sudo bash /var/www/tjsiddse/configurar-path-php-fpm.sh " .
                           "O verifica que ffmpeg esté instalado: sudo apt-get install ffmpeg"
            ];
        }
        
        error_log("WhisperClient: ffmpeg encontrado en: $ffmpegPath");
        
        // Crear archivo temporal para la conversión
        $tempDir = dirname($audioFilePath);
        $tempFile = $tempDir . '/whisper_convert_' . uniqid() . '.mp3';
        
        // Comando de conversión: convertir a MP3 con calidad razonable
        // -y: sobrescribir archivo de salida si existe
        // -i: archivo de entrada
        // -acodec libmp3lame: usar codec MP3
        // -ar 16000: frecuencia de muestreo 16kHz (suficiente para voz)
        // -ac 1: mono (recomendado para transcripción)
        // -b:a 64k: bitrate 64kbps (suficiente para voz)
        $command = escapeshellarg($ffmpegPath) . 
                   ' -y -i ' . escapeshellarg($audioFilePath) . 
                   ' -acodec libmp3lame -ar 16000 -ac 1 -b:a 64k' . 
                   ' ' . escapeshellarg($tempFile) . 
                   ' 2>&1';
        
        error_log("WhisperClient: Convirtiendo $ext a MP3: $command");
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($tempFile) || filesize($tempFile) === 0) {
            $errorOutput = implode("\n", $output);
            error_log("WhisperClient: Error en conversión: $errorOutput");
            
            // Limpiar archivo temporal si se creó pero está vacío
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            
            return [
                'success' => false,
                'error' => "Error al convertir el archivo de audio: " . substr($errorOutput, 0, 200)
            ];
        }
        
        error_log("WhisperClient: Conversión exitosa. Archivo convertido: " . basename($tempFile) . " (" . round(filesize($tempFile) / 1024, 2) . " KB)");
        
        return [
            'success' => true,
            'converted_path' => $tempFile,
            'temp_file' => true
        ];
    }
    
    /**
     * Transcribir audio usando whisper.cpp
     * 
     * @param string $audioFilePath Ruta al archivo de audio
     * @return array ['success' => bool, 'text' => string, 'error' => string]
     */
    public function transcribeAudio($audioFilePath) {
        // Detectar método y usar el apropiado
        error_log("WhisperClient: Método configurado: {$this->method}, API URL: {$this->apiUrl}, CLI API URL: {$this->cliApiUrl}");
        if ($this->method === 'whisper-cli') {
            error_log("WhisperClient: Usando método whisper-cli");
            return $this->transcribeWithWhisperCli($audioFilePath);
        }
        
        error_log("WhisperClient: Usando método whisper-server (por defecto)");
        
        // Método por defecto: whisper-server (código existente)
        if (!file_exists($audioFilePath)) {
            return [
                'success' => false,
                'error' => 'Archivo de audio no encontrado: ' . $audioFilePath
            ];
        }
        
        $ext = strtolower(pathinfo($audioFilePath, PATHINFO_EXTENSION));
        $fileSize = filesize($audioFilePath);
        error_log("WhisperClient: transcribeAudio iniciado. Archivo: " . basename($audioFilePath) . " (ext: $ext, tamaño: " . round($fileSize / 1024, 2) . " KB)");
        
        // Convertir a formato soportado si es necesario (ej: M4A -> MP3, WebM -> MP3)
        $conversionResult = $this->convertAudioToSupportedFormat($audioFilePath);
        if (!$conversionResult['success']) {
            error_log("WhisperClient: Error en conversión: " . ($conversionResult['error'] ?? 'desconocido'));
            return [
                'success' => false,
                'error' => $conversionResult['error']
            ];
        }
        
        $fileToTranscribe = $conversionResult['converted_path'];
        $isTempFile = $conversionResult['temp_file'];
        
        // Verificar que el archivo convertido existe y tiene contenido
        if (!file_exists($fileToTranscribe)) {
            error_log("WhisperClient: ERROR - Archivo convertido no existe: $fileToTranscribe");
            return [
                'success' => false,
                'error' => 'Error: El archivo convertido no existe. La conversión puede haber fallado.'
            ];
        }
        
        $convertedFileSize = filesize($fileToTranscribe);
        if ($convertedFileSize === 0) {
            error_log("WhisperClient: ERROR - Archivo convertido está vacío: $fileToTranscribe");
            return [
                'success' => false,
                'error' => 'Error: El archivo convertido está vacío. La conversión puede haber fallado.'
            ];
        }
        
        $convertedExt = strtolower(pathinfo($fileToTranscribe, PATHINFO_EXTENSION));
        error_log("WhisperClient: Archivo a transcribir: " . basename($fileToTranscribe) . " (ext: $convertedExt, tamaño: " . round($convertedFileSize / 1024, 2) . " KB, temporal: " . ($isTempFile ? 'sí' : 'no') . ")");
        
        // Limpiar archivo temporal al finalizar (usar try-finally implícito con unregister_shutdown_function)
        $cleanupTempFile = function() use ($fileToTranscribe, $isTempFile) {
            if ($isTempFile && file_exists($fileToTranscribe)) {
                @unlink($fileToTranscribe);
            }
        };
        
        // Registrar función de limpieza
        register_shutdown_function($cleanupTempFile);
        
        // La API de whisper.cpp generalmente usa /v1/audio/transcriptions (similar a OpenAI)
        // Intentaremos los endpoints comunes en orden de prioridad
        $endpoints = [
            '/api/transcribe',           // Endpoint principal de whisper.cpp (prioridad)
            '/v1/audio/transcriptions', // Endpoint estándar (similar a OpenAI Whisper API)
            '/inference',
            '/transcribe'
        ];
        
        $errors = []; // Guardar todos los errores para mejor diagnóstico
        
        foreach ($endpoints as $endpoint) {
            $url = $this->apiUrl . $endpoint;
            
            // Preparar el archivo para multipart/form-data (usar archivo convertido)
            $mimeType = mime_content_type($fileToTranscribe);
            if (!$mimeType) {
                // Fallback para tipos MIME comunes (usar archivo convertido)
                $ext = strtolower(pathinfo($fileToTranscribe, PATHINFO_EXTENSION));
                $mimeTypes = [
                    'mp3' => 'audio/mpeg',
                    'wav' => 'audio/wav',
                    'ogg' => 'audio/ogg',
                    'm4a' => 'audio/mp4',
                    'flac' => 'audio/flac',
                    'webm' => 'audio/webm'
                ];
                $mimeType = $mimeTypes[$ext] ?? 'audio/mpeg';
            }
            
            // Usar el nombre del archivo original para el nombre en la petición, pero el archivo convertido para el contenido
            $originalFileName = basename($audioFilePath);
            // Si es MP3 convertido, mantener extensión .mp3 en el nombre
            if ($isTempFile) {
                $originalFileName = pathinfo($originalFileName, PATHINFO_FILENAME) . '.mp3';
            }
            
            $cfile = new CURLFile($fileToTranscribe, $mimeType, $originalFileName);
            
            // Diferentes formatos según el endpoint
            if ($endpoint === '/api/transcribe') {
                // Endpoint principal de whisper.cpp
                // Formato exacto que funciona por CLI: curl -X POST ... -F "file=@audio.mp3" -F "language=es" -F "response_format=json"
                $postData = [
                    'file' => $cfile
                ];
                if ($this->language && $this->language !== 'auto') {
                    $postData['language'] = $this->language;
                }
                // Usar JSON como formato de respuesta para obtener segments con timestamps
                // Si el servidor no soporta 'json', intentar 'text' como fallback
                $postData['response_format'] = 'json';
                
                // Log para debugging
                error_log("WhisperClient: Intentando /api/transcribe con response_format=json, language={$this->language}, file=" . basename($fileToTranscribe) . ($isTempFile ? " (convertido desde " . basename($audioFilePath) . ")" : ""));
            } elseif ($endpoint === '/v1/audio/transcriptions') {
                // Formato OpenAI Whisper API
                $postData = [
                    'file' => $cfile,
                    'model' => $this->model
                ];
                if ($this->language && $this->language !== 'auto') {
                    $postData['language'] = $this->language;
                }
            } elseif ($endpoint === '/inference') {
                // Formato whisper.cpp inference endpoint
                // El servidor espera el campo 'file' según el error "no 'file' field in the request"
                $postData = [
                    'file' => $cfile,
                    'model' => $this->model
                ];
                // Algunas implementaciones no usan 'language' en inference
                // if ($this->language && $this->language !== 'auto') {
                //     $postData['language'] = $this->language;
                // }
                error_log("WhisperClient: Intentando /inference con campo 'file' (archivo convertido: " . basename($fileToTranscribe) . ", ext: $convertedExt)");
            } else {
                // Formato genérico
                $postData = [
                    'file' => $cfile,
                    'model' => $this->model
                ];
                if ($this->language && $this->language !== 'auto') {
                    $postData['language'] = $this->language;
                }
            }
            
            // Para /inference, aumentar el timeout significativamente ya que el procesamiento puede tardar mucho
            // El procesamiento de audio puede tomar varios minutos, especialmente para archivos largos
            $requestTimeout = $this->timeout;
            if ($endpoint === '/inference') {
                // Calcular timeout basado en el tamaño del archivo (usar archivo convertido)
                // Estimación: ~2-3 minutos por minuto de audio para modelos grandes
                $fileSize = filesize($fileToTranscribe);
                $estimatedMinutes = max(1, ($fileSize / (16000 * 60)) * 3); // Estimación conservadora
                $requestTimeout = max($this->timeout, (int)($estimatedMinutes * 60) + 60); // Al menos 1 minuto extra
            }
            
            // Medir tiempo real de la petición
            $startTime = microtime(true);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_CONNECTTIMEOUT => 10, // Timeout de conexión más corto
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ],
                // No seguir redirects automáticamente
                CURLOPT_FOLLOWLOCATION => false,
                // Verificar que la respuesta completa se recibió
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            
            // Calcular tiempo real transcurrido
            $elapsedTime = microtime(true) - $startTime;
            $elapsedSeconds = round($elapsedTime, 2);
            
            // Log detallado para debugging
            error_log("WhisperClient: Endpoint $endpoint - HTTP $httpCode, URL: $url, Tiempo transcurrido: {$elapsedSeconds}s");
            if ($error) {
                error_log("WhisperClient: Error cURL: $error (tiempo transcurrido: {$elapsedSeconds}s)");
                $errors[] = "$endpoint: Error de conexión - $error (tiempo transcurrido: {$elapsedSeconds}s)";
                continue; // Intentar siguiente endpoint
            }
            
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                
                // Debug: Log de la estructura de respuesta para /api/transcribe
                if ($endpoint === '/api/transcribe') {
                    error_log("Whisper /api/transcribe response keys: " . json_encode(array_keys($result ?? [])));
                    if (isset($result['segments'])) {
                        error_log("Segments encontrados: " . count($result['segments']) . " segmentos");
                        // Log del primer segmento para ver su estructura
                        if (!empty($result['segments']) && isset($result['segments'][0])) {
                            error_log("Estructura del primer segmento: " . json_encode(array_keys($result['segments'][0])));
                        }
                    } else {
                        error_log("No se encontraron 'segments'. Claves disponibles: " . json_encode(array_keys($result ?? [])));
                        // Verificar variantes comunes
                        if (isset($result['transcription'])) {
                            error_log("Se encontró 'transcription' (puede contener segments)");
                        }
                        if (isset($result['result'])) {
                            error_log("Se encontró 'result' (puede contener segments)");
                        }
                    }
                }
                
                // Verificar si hay un error en la respuesta JSON
                if (is_array($result) && isset($result['error'])) {
                    $errorMsg = $result['error'];
                    $errors[] = "$endpoint: Error del servidor - $errorMsg (tiempo transcurrido: {$elapsedSeconds}s)";
                    
                    // Si el error es "failed to read audio data" o "no 'file' field", puede ser un problema de formato
                    if (strpos(strtolower($errorMsg), 'failed to read audio') !== false || 
                        strpos(strtolower($errorMsg), 'read audio') !== false ||
                        strpos(strtolower($errorMsg), 'audio data') !== false ||
                        strpos(strtolower($errorMsg), "no 'file' field") !== false) {
                        $errors[] = "$endpoint: El servidor no pudo leer el archivo de audio. Verifica el formato del archivo (MP3, WAV, etc.) y que el archivo no esté corrupto.";
                    }
                    continue; // Intentar siguiente endpoint
                }
                
                // Log completo de la respuesta para debugging cuando el texto está vacío
                if ($endpoint === '/inference' && isset($result['text']) && empty(trim($result['text']))) {
                    error_log("WhisperClient: /inference respondió con texto vacío. Respuesta completa: " . json_encode($result));
                    error_log("WhisperClient: Tiempo transcurrido: {$elapsedSeconds}s, Timeout configurado: {$requestTimeout}s");
                }
                
                // Para /api/transcribe, puede devolver texto plano si response_format=text
                // o JSON si response_format=json
                if ($endpoint === '/api/transcribe' && $result === null && json_last_error() !== JSON_ERROR_NONE) {
                    // Respuesta es texto plano
                    $text = trim($response);
                    if (!empty($text)) {
                        // Limpiar archivo temporal antes de retornar
                        if ($isTempFile && file_exists($fileToTranscribe)) {
                            @unlink($fileToTranscribe);
                        }
                        return [
                            'success' => true,
                            'text' => $text,
                            'model' => $this->model,
                            'language' => $this->language,
                            'raw_response' => $response,
                            'stats' => [] // Sin estadísticas para texto plano
                        ];
                    }
                }
                
                // Si la respuesta no es JSON, puede ser texto plano (otros endpoints)
                if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
                    // Intentar como texto plano
                    $text = trim($response);
                    if (!empty($text)) {
                        // Limpiar archivo temporal antes de retornar
                        if ($isTempFile && file_exists($fileToTranscribe)) {
                            @unlink($fileToTranscribe);
                        }
                        return [
                            'success' => true,
                            'text' => $text,
                            'model' => $this->model,
                            'language' => $this->language,
                            'raw_response' => $response
                        ];
                    }
                }
                
                // Diferentes formatos de respuesta según la implementación de whisper.cpp
                $text = null;
                
                // Log de la estructura completa de la respuesta para debugging (especialmente para /inference)
                if ($endpoint === '/inference') {
                    error_log("WhisperClient: /inference respuesta - Claves: " . json_encode(array_keys($result ?? [])));
                    if (isset($result['text'])) {
                        error_log("WhisperClient: /inference - text presente, longitud: " . strlen($result['text']));
                    }
                    if (isset($result['status'])) {
                        error_log("WhisperClient: /inference - status: " . $result['status']);
                    }
                    if (isset($result['message'])) {
                        error_log("WhisperClient: /inference - message: " . $result['message']);
                    }
                    error_log("WhisperClient: /inference - Respuesta completa (primeros 500 chars): " . substr(json_encode($result), 0, 500));
                }
                
                // Formato OpenAI Whisper API: {"text": "transcripción"}
                if (isset($result['text'])) {
                    $text = $result['text'];
                } 
                // Otros formatos comunes
                elseif (isset($result['transcription'])) {
                    $text = $result['transcription'];
                } elseif (isset($result['result'])) {
                    $text = $result['result'];
                } elseif (is_string($result)) {
                    $text = $result;
                } elseif (isset($result['data']['text'])) {
                    $text = $result['data']['text'];
                }
                
                // Si no hay texto directo pero hay segments, construir el texto desde los segments
                if (($text === null || empty(trim($text))) && isset($result['segments']) && is_array($result['segments']) && !empty($result['segments'])) {
                    $textParts = [];
                    foreach ($result['segments'] as $segment) {
                        if (isset($segment['text'])) {
                            $textParts[] = trim($segment['text']);
                        }
                    }
                    if (!empty($textParts)) {
                        $text = implode(' ', $textParts);
                        error_log("Texto construido desde segments: " . strlen($text) . " caracteres");
                    }
                }
                
                // Extraer estadísticas de la respuesta
                $stats = [];
                
                // Duración del audio (del último segmento)
                if (isset($result['segments']) && is_array($result['segments']) && !empty($result['segments'])) {
                    $lastSegment = end($result['segments']);
                    if (isset($lastSegment['end'])) {
                        $stats['audio_duration_s'] = round($lastSegment['end'], 2);
                        $stats['audio_duration_formatted'] = formatDuration($lastSegment['end']);
                    }
                }
                
                // Timings de procesamiento
                if (isset($result['timings'])) {
                    $stats['timings'] = $result['timings'];
                }
                
                if ($text !== null) {
                    $text = trim($text);
                    
                    // Verificar que el texto no esté vacío
                    if (empty($text)) {
                        // El texto está vacío - puede ser que el proceso aún esté corriendo
                        // Para /inference, si responde muy rápido (< 1 segundo) con texto vacío,
                        // puede ser que el servidor esté respondiendo antes de procesar
                        if ($endpoint === '/inference' && $elapsedSeconds < 1.0) {
                            // El servidor respondió muy rápido con texto vacío - puede ser que esté procesando de forma asíncrona
                            // o que haya un error. Verificar si hay indicadores de procesamiento asíncrono
                            error_log("WhisperClient: /inference respondió muy rápido ({$elapsedSeconds}s) con texto vacío. Puede estar procesando de forma asíncrona.");
                            
                            // Si la respuesta tiene un campo 'status' o 'job_id', puede ser procesamiento asíncrono
                            if (isset($result['status']) || isset($result['job_id']) || isset($result['id'])) {
                                $errors[] = "$endpoint: El servidor está procesando de forma asíncrona. " .
                                           "Se requiere polling o espera adicional. " .
                                           "Respuesta: " . substr($response, 0, 300);
                            } else {
                                // Si no hay indicadores de procesamiento asíncrono, puede ser un error
                                $errors[] = "$endpoint: El servidor respondió con texto vacío muy rápido ({$elapsedSeconds}s). " .
                                           "Esto puede indicar: " .
                                           "1) El servidor no está procesando correctamente el audio, " .
                                           "2) Hay un error no reportado, o " .
                                           "3) El formato del audio no es compatible. " .
                                           "Verifica los logs del servidor whisper-server y que el archivo de audio sea válido. " .
                                           "Respuesta recibida: " . substr($response, 0, 300);
                            }
                        } elseif ($endpoint === '/inference') {
                            // Para /inference que tardó más, el timeout puede no ser suficiente
                            $errors[] = "$endpoint: El servidor respondió con texto vacío después de " . $elapsedSeconds . " segundos (timeout configurado: " . $requestTimeout . "s). " .
                                       "Esto puede indicar que: " .
                                       "1) El servidor whisper está procesando pero aún no ha terminado (el procesamiento puede tardar varios minutos para archivos largos), " .
                                       "2) El servidor respondió antes de completar el procesamiento, o " .
                                       "3) Hubo un error en el procesamiento. " .
                                       "Verifica los logs del servidor whisper-server. " .
                                       "Respuesta recibida: " . substr($response, 0, 200);
                        } else {
                            // Para otros endpoints, solo reportar el error
                            $errors[] = "$endpoint: Respuesta 200 OK pero el texto de transcripción está vacío. " .
                                       "Esto puede indicar que el proceso aún está corriendo o hubo un error. " .
                                       "Respuesta completa: " . substr($response, 0, 500);
                        }
                        continue; // Intentar siguiente endpoint
                    }
                    
                    $response = [
                        'success' => true,
                        'text' => $text,
                        'model' => $this->model,
                        'language' => $result['language'] ?? $this->language,
                        'raw_response' => $result
                    ];
                    
                    // Agregar duración del MP3 convertido si está disponible (más precisa que la de los segments)
                    if (isset($conversionResult['converted_audio_duration']) && $conversionResult['converted_audio_duration'] > 0) {
                        if (!isset($stats)) {
                            $stats = [];
                        }
                        // Usar la duración del MP3 convertido si no hay duración de segments o si es más precisa
                        if (!isset($stats['audio_duration_s']) || abs($stats['audio_duration_s'] - $conversionResult['converted_audio_duration']) > 1) {
                            $stats['audio_duration_s'] = $conversionResult['converted_audio_duration'];
                            $stats['audio_duration_formatted'] = formatDuration($conversionResult['converted_audio_duration']);
                            error_log("WhisperClient: Usando duración del MP3 convertido: {$conversionResult['converted_audio_duration']} segundos");
                        }
                    }
                    
                    // Agregar estadísticas si están disponibles
                    if (!empty($stats)) {
                        $response['stats'] = $stats;
                    }
                    
                    // Agregar segments con timestamps si están disponibles
                    // Verificar múltiples posibles ubicaciones según la implementación de whisper-server
                    $segmentsFound = false;
                    
                    if (isset($result['segments']) && is_array($result['segments']) && !empty($result['segments'])) {
                        $response['segments'] = $result['segments'];
                        error_log("WhisperClient: Segments agregados desde result['segments']: " . count($result['segments']) . " segmentos");
                        $segmentsFound = true;
                    } elseif (isset($result['transcription']['segments']) && is_array($result['transcription']['segments']) && !empty($result['transcription']['segments'])) {
                        // Algunas implementaciones anidan segments en transcription
                        $response['segments'] = $result['transcription']['segments'];
                        error_log("WhisperClient: Segments encontrados en result['transcription']['segments']: " . count($result['transcription']['segments']) . " segmentos");
                        $segmentsFound = true;
                    } elseif (isset($result['result']['segments']) && is_array($result['result']['segments']) && !empty($result['result']['segments'])) {
                        // Otras implementaciones usan result.segments
                        $response['segments'] = $result['result']['segments'];
                        error_log("WhisperClient: Segments encontrados en result['result']['segments']: " . count($result['result']['segments']) . " segmentos");
                        $segmentsFound = true;
                    }
                    
                    if (!$segmentsFound) {
                        error_log("WhisperClient: No se encontraron segments. Claves en result: " . json_encode(array_keys($result ?? [])));
                        // Guardar raw_response completo para debugging
                        if (isset($response['raw_response'])) {
                            error_log("WhisperClient: raw_response keys: " . json_encode(array_keys($response['raw_response'] ?? [])));
                        }
                    }
                    
                    return $response;
                } else {
                    // Respuesta 200 pero formato no reconocido - log completo para debugging
                    $errors[] = "$endpoint: Respuesta 200 OK pero formato no reconocido. " .
                               "Respuesta completa: " . substr($response, 0, 500) . 
                               " | Estructura JSON: " . json_encode(array_keys($result ?? []));
                }
            } elseif ($httpCode === 404) {
                // Endpoint no existe, intentar siguiente
                $errors[] = "$endpoint: Endpoint no encontrado (404)";
                error_log("WhisperClient: 404 en $endpoint - El servidor puede no tener este endpoint o puede estar escuchando solo en localhost");
                // Si es /api/transcribe y da 404, puede ser que el servidor no esté accesible desde la red
                // o que el endpoint tenga un nombre diferente
                if ($endpoint === '/api/transcribe') {
                    error_log("WhisperClient: Sugerencia: Verifica que whisper-server esté configurado con --host 0.0.0.0 para aceptar conexiones de red");
                }
                continue;
            } else {
                $errors[] = "$endpoint: Error HTTP $httpCode - " . substr($response, 0, 100);
            }
        }
        
        // Limpiar archivo temporal si se creó
        if ($isTempFile && file_exists($fileToTranscribe)) {
            @unlink($fileToTranscribe);
        }
        
        // Si llegamos aquí, todos los endpoints fallaron
        $errorMessage = 'No se pudo transcribir el audio usando whisper.cpp. ';
        
        // Verificar si todos los endpoints dieron 404
        $all404 = true;
        foreach ($errors as $error) {
            if (strpos($error, '404') === false && strpos($error, 'Error de conexión') === false) {
                $all404 = false;
                break;
            }
        }
        
        if ($all404 && count($errors) === count($endpoints)) {
            $errorMessage = "Whisper-server usa /v1/audio/transcriptions, pero ninguno de los endpoints intentados funcionó.\n" .
                           "Endpoints intentados: " . implode(', ', $endpoints) . "\n" .
                           "URL base: {$this->apiUrl}\n\n" .
                           "Verifica que:\n" .
                           "1. El servidor whisper.cpp esté corriendo en {$this->apiUrl}\n" .
                           "2. El endpoint /v1/audio/transcriptions esté disponible\n" .
                           "3. El formato de la petición sea correcto (multipart/form-data con 'file' y 'model')";
        } else {
            $errorMessage .= "Endpoints intentados: " . implode(', ', $endpoints) . ". ";
            if (!empty($errors)) {
                $errorMessage .= "Errores: " . implode('; ', $errors);
            } else {
                $errorMessage .= "No se recibió respuesta del servidor.";
            }
        }
        
        // Mensajes específicos para errores de conexión
        $allErrors = implode(' ', $errors);
        if (strpos($allErrors, 'Connection refused') !== false) {
            $errorMessage = "No se pudo conectar a whisper.cpp en {$this->apiUrl}. Verifica que:\n" .
                           "1. El servidor whisper.cpp esté corriendo\n" .
                           "2. La IP y puerto sean correctos ({$this->apiUrl})\n" .
                           "3. No haya un firewall bloqueando la conexión";
        } elseif (strpos($allErrors, 'Connection timed out') !== false) {
            $errorMessage = "Timeout al conectar con whisper.cpp en {$this->apiUrl}. El servidor puede estar sobrecargado.";
        } elseif (strpos($allErrors, 'Failed to connect') !== false) {
            $errorMessage = "No se pudo establecer conexión con whisper.cpp en {$this->apiUrl}. Verifica la configuración.";
        }
        
        return [
            'success' => false,
            'error' => $errorMessage
        ];
    }
    
    /**
     * Transcribir usando whisper-cli API (NUEVO MÉTODO)
     * 
     * @param string $audioFilePath Ruta al archivo de audio
     * @return array ['success' => bool, 'text' => string, 'error' => string]
     */
    private function transcribeWithWhisperCli($audioFilePath) {
        if (!file_exists($audioFilePath)) {
            return [
                'success' => false,
                'error' => 'Archivo de audio no encontrado: ' . $audioFilePath
            ];
        }
        
        $ext = strtolower(pathinfo($audioFilePath, PATHINFO_EXTENSION));
        $fileSize = filesize($audioFilePath);
        error_log("WhisperClient: transcribeAudio (whisper-cli) iniciado. Archivo: " . basename($audioFilePath) . " (ext: $ext, tamaño: " . round($fileSize / 1024, 2) . " KB)");
        
        // Para whisper-cli: convertir a MP3 primero si no es MP3
        // Formatos que NO necesitan conversión (se envían directamente)
        // Nota: WAV también se convierte porque algunos formatos WAV internos pueden ser incompatibles
        // Nota: OGG se convierte porque whisper-cli puede tener problemas con ciertos codecs OGG (Opus, Vorbis)
        $supportedFormats = ['mp3', 'flac'];
        $needsConversion = !in_array($ext, $supportedFormats);
        
        $fileToTranscribe = $audioFilePath;
        $isTempFile = false;
        
        if ($needsConversion) {
            error_log("WhisperClient: Archivo $ext necesita conversión a MP3.");
            
            // Intentar convertir con ffmpeg-rest si está configurado
            if ($this->ffmpegRestUrl) {
                error_log("WhisperClient: Usando ffmpeg-rest para conversión...");
                $conversionResult = $this->convertAudioRemotely($audioFilePath);
                if ($conversionResult['success']) {
                    $fileToTranscribe = $conversionResult['converted_path'];
                    $isTempFile = $conversionResult['temp_file'] ?? true;
                    error_log("WhisperClient: Archivo convertido exitosamente a MP3: " . basename($fileToTranscribe));
                } else {
                    // Si ffmpeg-rest falla, enviar archivo original a whisper-cli-api.js
                    // que tiene su propia lógica de conversión con fallback local
                    error_log("WhisperClient: Conversión con ffmpeg-rest falló: " . ($conversionResult['error'] ?? 'desconocido') . ". Enviando archivo original a whisper-cli-api.js para conversión.");
                    $fileToTranscribe = $audioFilePath;
                    $needsConversion = false; // whisper-cli-api.js se encargará de la conversión
                }
            } else {
                // Si ffmpeg-rest no está configurado, enviar archivo original a whisper-cli-api.js
                // que tiene su propia lógica de conversión con fallback local
                error_log("WhisperClient: ffmpeg-rest no está configurado. Enviando archivo original a whisper-cli-api.js para conversión.");
                $fileToTranscribe = $audioFilePath;
                $needsConversion = false; // whisper-cli-api.js se encargará de la conversión
            }
        } else {
            error_log("WhisperClient: Archivo ya está en formato compatible ($ext). Enviando directamente a whisper-cli.");
        }
        
        // Verificar que el archivo a transcribir existe y tiene contenido
        if (!file_exists($fileToTranscribe) || filesize($fileToTranscribe) === 0) {
            if ($isTempFile && file_exists($fileToTranscribe)) {
                @unlink($fileToTranscribe);
            }
            return [
                'success' => false,
                'error' => 'Error: El archivo a transcribir no existe o está vacío.'
            ];
        }
        
        $cleanupTempFile = function() use ($fileToTranscribe, $isTempFile) {
            if ($isTempFile && file_exists($fileToTranscribe)) {
                @unlink($fileToTranscribe);
            }
        };
        register_shutdown_function($cleanupTempFile);
        
        // Preparar archivo para multipart/form-data
        // Si se convirtió a MP3, usar audio/mpeg
        if ($isTempFile || $needsConversion) {
            $mimeType = 'audio/mpeg'; // Archivo convertido a MP3
        } else {
            $mimeType = mime_content_type($fileToTranscribe);
            if (!$mimeType) {
                $ext = strtolower(pathinfo($fileToTranscribe, PATHINFO_EXTENSION));
                $mimeTypes = [
                    'mp3' => 'audio/mpeg',
                    'wav' => 'audio/wav',
                    'flac' => 'audio/flac',
                    'ogg' => 'audio/ogg'
                ];
                $mimeType = $mimeTypes[$ext] ?? 'audio/mpeg';
            }
        }
        
        // Usar nombre del archivo convertido si se hizo conversión
        if ($isTempFile || $needsConversion) {
            $originalFileName = pathinfo(basename($audioFilePath), PATHINFO_FILENAME) . '.mp3';
        } else {
            $originalFileName = basename($audioFilePath);
        }
        
        $cfile = new CURLFile($fileToTranscribe, $mimeType, $originalFileName);
        
        // Endpoint de whisper-cli API
        $url = $this->cliApiUrl . '/api/transcribe';
        
        // Parámetros según la API whisper-cli proporcionada
        // Pasar ffmpeg_rest_url si está configurado, para que whisper-cli-api.js pueda usarlo para conversión
        $postData = [
            'file' => $cfile,
            'model' => $this->model,
            'language' => $this->language,
            'threads' => 8,
            'dev' => 0,  // Dispositivo GPU (0 = primera GPU)
            'ml' => 10,  // Max length
            'timestamps' => true
        ];
        
        // Si ffmpeg-rest está configurado, pasarlo para que whisper-cli-api.js pueda usarlo
        if ($this->ffmpegRestUrl) {
            $postData['ffmpeg_rest_url'] = $this->ffmpegRestUrl;
        }
        
        error_log("WhisperClient: Enviando a whisper-cli API: $url");
        
        $startTime = microtime(true);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $elapsedTime = microtime(true) - $startTime;
        $elapsedSeconds = round($elapsedTime, 2);
        
        error_log("WhisperClient: whisper-cli API - HTTP $httpCode, Tiempo: {$elapsedSeconds}s");
        
        if ($error) {
            if ($isTempFile && file_exists($fileToTranscribe)) {
                @unlink($fileToTranscribe);
            }
            return [
                'success' => false,
                'error' => "Error de conexión con whisper-cli: $error"
            ];
        }
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            
            if (is_array($result) && isset($result['error'])) {
                if ($isTempFile && file_exists($fileToTranscribe)) {
                    @unlink($fileToTranscribe);
                }
                return [
                    'success' => false,
                    'error' => $result['error']
                ];
            }
            
            // Extraer texto de la respuesta
            $text = null;
            if (isset($result['text'])) {
                $text = $result['text'];
            } elseif (isset($result['transcription'])) {
                $text = $result['transcription'];
            }
            
            // Si hay segments, construir texto
            if (($text === null || empty(trim($text))) && isset($result['segments']) && is_array($result['segments'])) {
                $textParts = [];
                foreach ($result['segments'] as $segment) {
                    if (isset($segment['text'])) {
                        $textParts[] = trim($segment['text']);
                    }
                }
                if (!empty($textParts)) {
                    $text = implode(' ', $textParts);
                }
            }
            
            if ($text !== null && !empty(trim($text))) {
                $response = [
                    'success' => true,
                    'text' => trim($text),
                    'model' => $result['model'] ?? $this->model,
                    'language' => $result['language'] ?? $this->language,
                    'raw_response' => $result,
                    'mode' => 'cli'
                ];
                
                // Agregar segments si están disponibles
                if (isset($result['segments']) && is_array($result['segments'])) {
                    $response['segments'] = $result['segments'];
                }
                
                // Agregar estadísticas (timings de whisper-cli)
                $stats = [];
                if (isset($result['duration'])) {
                    $stats['processing_time'] = $result['duration'];
                } elseif (isset($result['timings']['total_time'])) {
                    $stats['processing_time'] = $result['timings']['total_time'] / 1000; // Convertir ms a segundos
                } else {
                    $stats['processing_time'] = $elapsedSeconds;
                }
                
                // Agregar timings detallados si están disponibles
                if (isset($result['timings']) && is_array($result['timings'])) {
                    $stats['timings'] = $result['timings'];
                }
                
                // Agregar información de uso si está disponible
                if (isset($result['usage'])) {
                    $stats['usage'] = $result['usage'];
                }
                
                if (!empty($stats)) {
                    $response['stats'] = $stats;
                }
                
                return $response;
            } else {
                if ($isTempFile && file_exists($fileToTranscribe)) {
                    @unlink($fileToTranscribe);
                }
                return [
                    'success' => false,
                    'error' => 'La transcripción se completó pero el texto está vacío.'
                ];
            }
        } else {
            if ($isTempFile && file_exists($fileToTranscribe)) {
                @unlink($fileToTranscribe);
            }
            $errorMsg = "Error HTTP $httpCode";
            if ($response) {
                $errorData = json_decode($response, true);
                if ($errorData && isset($errorData['error'])) {
                    $errorMsg = $errorData['error'];
                } else {
                    $errorMsg .= ": " . substr($response, 0, 200);
                }
            }
            return [
                'success' => false,
                'error' => $errorMsg
            ];
        }
    }
    
    /**
     * Health check detallado (ready / status ok|degraded|down).
     *
     * @return array{success:bool,ready:bool,status:string,message:string,http_code:int|null,url:string,raw:mixed}
     */
    public function checkHealth(int $timeoutSeconds = 4): array
    {
        if ($this->method === 'whisper-cli') {
            $url = rtrim($this->cliApiUrl, '/') . '/health';
            return $this->probeHealthUrl($url, $timeoutSeconds, true);
        }

        $base = rtrim($this->apiUrl, '/');
        foreach (['/health', '/status', '/'] as $endpoint) {
            $result = $this->probeHealthUrl($base . $endpoint, $timeoutSeconds, $endpoint === '/health');
            if (!empty($result['success']) || ($result['http_code'] ?? 0) > 0) {
                return $result;
            }
        }

        return [
            'success' => false,
            'ready' => false,
            'status' => 'down',
            'message' => 'No se pudo conectar a whisper.cpp en ' . $this->apiUrl,
            'http_code' => null,
            'url' => $this->apiUrl,
            'raw' => null,
        ];
    }

    /**
     * @param bool $parseReadyJson Si true, interpreta JSON ready/status del whisper-cli
     */
    private function probeHealthUrl(string $url, int $timeoutSeconds, bool $parseReadyJson): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => min(3, max(1, $timeoutSeconds)),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'ready' => false,
                'status' => 'down',
                'message' => 'Timeout o error de red al consultar health: ' . $error,
                'http_code' => $httpCode ?: null,
                'url' => $url,
                'raw' => null,
            ];
        }

        $raw = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            }
        }

        // whisper-cli contrato: ready + status
        if ($parseReadyJson && is_array($raw)) {
            $status = strtolower((string) ($raw['status'] ?? ''));
            $ready = array_key_exists('ready', $raw)
                ? (bool) $raw['ready']
                : ($httpCode === 200 && $status !== 'down');

            if ($status === '') {
                if ($httpCode === 503 || !$ready) {
                    $status = 'down';
                } elseif ($ready) {
                    $status = 'ok';
                } else {
                    $status = 'down';
                }
            }

            if ($httpCode === 503) {
                $status = 'down';
                $ready = false;
            }

            $messages = [
                'ok' => 'Servidor de transcripción OK',
                'degraded' => 'Servidor de transcripción degradado (GPU caída, ffmpeg o cola larga). Puede ir más lento.',
                'down' => 'Servidor de transcripción no disponible (no enviar audios)',
            ];
            $message = $raw['message'] ?? ($messages[$status] ?? 'Estado: ' . $status);
            if (!empty($raw['detail'])) {
                $message .= ' — ' . (is_string($raw['detail']) ? $raw['detail'] : json_encode($raw['detail']));
            }

            return [
                'success' => $httpCode >= 200 && $httpCode < 500 && $status !== 'down',
                'ready' => $ready && $status !== 'down',
                'status' => $status,
                'message' => $message,
                'http_code' => $httpCode,
                'url' => $url,
                'raw' => $raw,
            ];
        }

        // whisper-server / fallback: HTTP vivo
        if ($httpCode === 200 || $httpCode === 404) {
            return [
                'success' => true,
                'ready' => true,
                'status' => 'ok',
                'message' => 'Conexión exitosa con el servidor de transcripción',
                'http_code' => $httpCode,
                'url' => $url,
                'raw' => $raw,
            ];
        }

        if ($httpCode === 503) {
            return [
                'success' => false,
                'ready' => false,
                'status' => 'down',
                'message' => 'Servidor de transcripción respondió 503 (no listo)',
                'http_code' => $httpCode,
                'url' => $url,
                'raw' => $raw,
            ];
        }

        return [
            'success' => false,
            'ready' => false,
            'status' => 'down',
            'message' => 'No se pudo verificar health (HTTP ' . $httpCode . ')',
            'http_code' => $httpCode,
            'url' => $url,
            'raw' => $raw,
        ];
    }

    /**
     * Verificar conexión con whisper.cpp / whisper-cli
     *
     * @return array ['success' => bool, 'message' => string, 'ready' => bool, 'status' => string, ...]
     */
    public function testConnection() {
        $health = $this->checkHealth(5);
        return [
            'success' => !empty($health['ready']) && in_array($health['status'] ?? '', ['ok', 'degraded'], true),
            'message' => $health['message'] ?? '',
            'ready' => !empty($health['ready']),
            'status' => $health['status'] ?? 'down',
            'http_code' => $health['http_code'] ?? null,
            'url' => $health['url'] ?? null,
            'raw' => $health['raw'] ?? null,
            'degraded' => (($health['status'] ?? '') === 'degraded'),
        ];
    }
}
