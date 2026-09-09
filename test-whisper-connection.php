<?php
/**
 * Script de diagnóstico para probar conexión con whisper-server
 * Uso: php test-whisper-connection.php [ruta_al_audio.mp3]
 */

require_once __DIR__ . '/config/database.php';

// Obtener configuración de whisper
$database = Database::getInstance();
$db = $database->getConnection();
$stmt = $db->prepare("SELECT whisper_api_url, whisper_language FROM ai_config WHERE id = 1");
$stmt->execute();
$config = $stmt->fetch(PDO::FETCH_ASSOC);

$whisperUrl = $config['whisper_api_url'] ?? 'http://192.168.0.33:9090';
$language = $config['whisper_language'] ?? 'es';

echo "=== Diagnóstico de Conexión Whisper ===\n";
echo "URL configurada: $whisperUrl\n";
echo "Idioma: $language\n\n";

// Probar endpoints
$endpoints = [
    '/api/transcribe',
    '/v1/audio/transcriptions',
    '/inference',
    '/transcribe',
    '/health',
    '/status',
    '/'
];

echo "1. Probando endpoints disponibles:\n";
foreach ($endpoints as $endpoint) {
    $url = rtrim($whisperUrl, '/') . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "  $endpoint: ERROR - $error\n";
    } else {
        echo "  $endpoint: HTTP $httpCode";
        if ($httpCode === 200) {
            echo " ✓";
        }
        echo "\n";
        if ($httpCode === 200 && !empty($response)) {
            echo "    Respuesta: " . substr($response, 0, 200) . "\n";
        }
    }
}

// Si se proporciona un archivo de audio, probar transcripción
if (isset($argv[1]) && file_exists($argv[1])) {
    $audioFile = $argv[1];
    echo "\n2. Probando transcripción con archivo: $audioFile\n";
    
    $mimeType = mime_content_type($audioFile) ?: 'audio/mpeg';
    $cfile = new CURLFile($audioFile, $mimeType, basename($audioFile));
    
    // Probar /api/transcribe primero
    $url = rtrim($whisperUrl, '/') . '/api/transcribe';
    $postData = [
        'file' => $cfile,
        'language' => $language,
        'response_format' => 'json'
    ];
    
    echo "  Probando /api/transcribe con response_format=json...\n";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "  ERROR: $error\n";
    } else {
        echo "  HTTP Code: $httpCode\n";
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result) {
                echo "  ✓ Respuesta JSON válida\n";
                echo "  Claves en respuesta: " . implode(', ', array_keys($result)) . "\n";
                if (isset($result['text'])) {
                    echo "  Texto: " . substr($result['text'], 0, 200) . "...\n";
                }
                if (isset($result['segments'])) {
                    echo "  ✓ Segments encontrados: " . count($result['segments']) . "\n";
                } else {
                    echo "  ✗ No hay segments en la respuesta\n";
                }
            } else {
                echo "  Respuesta (primeros 500 chars): " . substr($response, 0, 500) . "\n";
            }
        } else {
            echo "  Respuesta: " . substr($response, 0, 500) . "\n";
        }
    }
    
    // Probar también con response_format=text
    echo "\n  Probando /api/transcribe con response_format=text...\n";
    $postData['response_format'] = 'text';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Accept: text/plain']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "  ERROR: $error\n";
    } else {
        echo "  HTTP Code: $httpCode\n";
        if ($httpCode === 200) {
            echo "  ✓ Respuesta recibida\n";
            echo "  Texto (primeros 200 chars): " . substr($response, 0, 200) . "...\n";
        } else {
            echo "  Respuesta: " . substr($response, 0, 500) . "\n";
        }
    }
} else {
    echo "\n2. Para probar transcripción, proporciona un archivo de audio:\n";
    echo "   php test-whisper-connection.php /ruta/al/audio.mp3\n";
}

echo "\n=== Fin del diagnóstico ===\n";
