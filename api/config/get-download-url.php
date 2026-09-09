<?php
/**
 * API para obtener la URL de descargas configurada
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . '/orthanc_config.php';
    
    $downloadUrl = OrthancConfig::getDownloadUrl();
    
    echo json_encode([
        'success' => true,
        'download_url' => $downloadUrl
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'download_url' => 'https://demoportal.tanjousoft.com.ar/visorweb' // Fallback
    ], JSON_UNESCAPED_UNICODE);
}
?>
