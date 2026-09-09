<?php
/**
 * Endpoint de conversión de audio remoto
 * Este archivo debe estar en el servidor de Whisper (192.168.0.33)
 * Convierte archivos M4A/AAC a MP3 usando ffmpeg local
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibió archivo']);
    exit();
}

$audioFile = $_FILES['file'];
$outputFormat = $_POST['output_format'] ?? 'mp3';

// Validar formato de salida
if (!in_array($outputFormat, ['mp3', 'wav'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de salida no válido. Solo MP3 o WAV']);
    exit();
}

// Verificar que ffmpeg esté disponible
$ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
if (empty($ffmpegPath)) {
    // Probar rutas comunes
    $commonPaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/bin/ffmpeg'];
    foreach ($commonPaths as $path) {
        $testCmd = @shell_exec(escapeshellarg($path) . ' -version 2>&1');
        if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
            $ffmpegPath = $path;
            break;
        }
    }
}

if (empty($ffmpegPath)) {
    $testCmd = @shell_exec('ffmpeg -version 2>&1');
    if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
        $ffmpegPath = 'ffmpeg';
    }
}

if (empty($ffmpegPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'ffmpeg no está disponible en este servidor']);
    exit();
}

// Crear directorio temporal para conversión
$tempDir = sys_get_temp_dir() . '/whisper_convert_' . uniqid();
if (!mkdir($tempDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo crear directorio temporal']);
    exit();
}

try {
    // Guardar archivo subido
    $inputFile = $tempDir . '/input_' . basename($audioFile['name']);
    if (!move_uploaded_file($audioFile['tmp_name'], $inputFile)) {
        throw new Exception('Error al guardar archivo subido');
    }
    
    // Crear archivo de salida
    $outputFile = $tempDir . '/output.' . $outputFormat;
    
    // Comando de conversión
    $command = escapeshellarg($ffmpegPath) . 
               ' -y -i ' . escapeshellarg($inputFile) . 
               ' -acodec libmp3lame -ar 16000 -ac 1 -b:a 64k' . 
               ' ' . escapeshellarg($outputFile) . 
               ' 2>&1';
    
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0 || !file_exists($outputFile) || filesize($outputFile) === 0) {
        $errorOutput = implode("\n", $output);
        throw new Exception("Error en conversión: " . substr($errorOutput, 0, 200));
    }
    
    // Leer archivo convertido y devolverlo en base64
    $convertedData = file_get_contents($outputFile);
    if ($convertedData === false) {
        throw new Exception('Error al leer archivo convertido');
    }
    
    // Limpiar archivos temporales
    @unlink($inputFile);
    @unlink($outputFile);
    @rmdir($tempDir);
    
    // Devolver archivo convertido en base64
    echo json_encode([
        'success' => true,
        'converted_data' => base64_encode($convertedData),
        'format' => $outputFormat,
        'size' => strlen($convertedData)
    ]);
    
} catch (Exception $e) {
    // Limpiar en caso de error
    @unlink($inputFile ?? '');
    @unlink($outputFile ?? '');
    @rmdir($tempDir);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
