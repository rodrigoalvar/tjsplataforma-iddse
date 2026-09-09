<?php
/**
 * Script de prueba para verificar si whisper-server devuelve segments
 * 
 * Uso: php test-whisper-segments.php <ruta_al_audio>
 */

if ($argc < 2) {
    echo "Uso: php test-whisper-segments.php <ruta_al_audio>\n";
    exit(1);
}

$audioFile = $argv[1];
if (!file_exists($audioFile)) {
    echo "Error: Archivo no encontrado: $audioFile\n";
    exit(1);
}

$whisperUrl = 'http://192.168.0.33:9090'; // Ajustar según tu configuración

echo "Probando whisper-server en: $whisperUrl\n";
echo "Archivo de audio: $audioFile\n\n";

// Probar diferentes formatos de petición
$formats = [
    'json' => ['response_format' => 'json'],
    'json_full' => ['response_format' => 'json_full'],
    'output_json' => ['output_format' => 'json'],
    'output_json_full' => ['output_format' => 'json_full'],
];

foreach ($formats as $name => $params) {
    echo "=== Probando formato: $name ===\n";
    
    $cfile = new CURLFile($audioFile, mime_content_type($audioFile), basename($audioFile));
    $postData = array_merge(['file' => $cfile], $params);
    
    $ch = curl_init($whisperUrl . '/api/transcribe');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if ($result) {
            echo "Claves en la respuesta: " . json_encode(array_keys($result)) . "\n";
            if (isset($result['segments'])) {
                echo "✓ SEGMENTS ENCONTRADOS: " . count($result['segments']) . " segmentos\n";
                if (!empty($result['segments'])) {
                    echo "Primer segmento: " . json_encode($result['segments'][0], JSON_PRETTY_PRINT) . "\n";
                }
            } else {
                echo "✗ No se encontraron segments\n";
            }
        } else {
            echo "Respuesta no es JSON válido\n";
        }
    } else {
        echo "Error HTTP: $httpCode\n";
    }
    echo "\n";
}

echo "=== Prueba completada ===\n";
echo "\nNOTA: Si ningún formato devolvió segments, el servidor whisper-server puede necesitar:\n";
echo "1. Estar configurado con -ml (max-len) para generar segments\n";
echo "2. Estar configurado con -sow (split-on-word) para timestamps detallados\n";
echo "3. Usar una versión del servidor que soporte segments en JSON\n";
echo "\nComando sugerido para el servidor:\n";
echo "./whisper-server -m <modelo> --port 9090 --host 0.0.0.0 --language es -ml 10 -sow\n";
