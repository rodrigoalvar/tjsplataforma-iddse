<?php
/**
 * Script de prueba para verificar la configuración del visor del usuario
 * Ejecutar desde el navegador o línea de comandos
 */

require_once 'config/database.php';
require_once 'middleware/auth.php';

// Obtener token de sesión
$token = null;
if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
    $token = $_COOKIE['session_token'];
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

if (!$token) {
    die("❌ Token de sesión no encontrado. Debes estar autenticado.");
}

// Validar sesión y obtener usuario
$userData = getUserFromToken($token);
if (!$userData) {
    die("❌ Sesión inválida");
}

$userId = $userData['id'];
$userName = $userData['nombre'] . ' ' . $userData['apellido'];

echo "<h2>🔍 Test de Configuración del Visor DICOM</h2>";
echo "<p><strong>Usuario:</strong> $userName (ID: $userId)</p>";

// Conectar a la base de datos
$pdo = getDBConnection();

// Obtener configuración del visor del usuario
$query = "SELECT dicom_viewer FROM usuarios WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$userId]);
$userConfig = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h3>📋 Configuración del Usuario en BD:</h3>";
echo "<pre>";
print_r($userConfig);
echo "</pre>";

$rawViewerValue = $userConfig['dicom_viewer'] ?? null;
echo "<p><strong>Valor crudo:</strong> " . ($rawViewerValue ?: 'NULL o vacío') . "</p>";

// Normalizar
$viewerType = 'UDV';
if ($rawViewerValue) {
    $normalized = strtolower(trim($rawViewerValue));
    echo "<p><strong>Valor normalizado (lowercase):</strong> '$normalized'</p>";
    
    if (strpos($normalized, 'stone') !== false) {
        $viewerType = 'StoneViewer';
    } elseif (strpos($normalized, 'udv') !== false) {
        $viewerType = 'UDV';
    } elseif (strpos($normalized, 'oviyam') !== false) {
        $viewerType = 'Oviyam';
    }
}

echo "<p><strong>Tipo de visor detectado:</strong> $viewerType</p>";

// Obtener configuración del visor desde OrthancConfig
require_once 'api/config/orthanc_config.php';
OrthancConfig::clearCache();
$orthancConfig = OrthancConfig::getConfig(); // Usar getConfig() en lugar de loadConfig()
$viewerConfig = $orthancConfig['viewer'];

echo "<h3>📋 Configuración de Visores Disponibles:</h3>";
echo "<pre>";
print_r($viewerConfig['viewers']);
echo "</pre>";

if (isset($viewerConfig['viewers'][$viewerType])) {
    $viewerInfo = $viewerConfig['viewers'][$viewerType];
    echo "<h3>✅ Configuración del Visor Seleccionado ($viewerType):</h3>";
    echo "<pre>";
    print_r($viewerInfo);
    echo "</pre>";
    
    // Construir URL
    $viewerUrl = $viewerInfo['url'];
    $viewerFormat = $viewerInfo['format'] ?? 'pacs_studyid';
    
    if ($viewerFormat === 'pacs_studyid') {
        $pacsName = $viewerInfo['pacs_name'] ?? 'LOSALISOS';
        $finalUrl = $viewerUrl . '?pacs=' . urlencode($pacsName);
    } else {
        $finalUrl = $viewerUrl;
    }
    
    echo "<h3>🔗 URL Final del Visor:</h3>";
    echo "<p><a href='$finalUrl' target='_blank'>$finalUrl</a></p>";
} else {
    echo "<h3>❌ ERROR: No se encontró configuración para '$viewerType</h3>";
    echo "<p>Visores disponibles: " . implode(', ', array_keys($viewerConfig['viewers'] ?? [])) . "</p>";
}

echo "<hr>";
echo "<h3>🔧 Debug Info:</h3>";
echo "<ul>";
echo "<li>Usuario ID: $userId</li>";
echo "<li>Valor en BD: " . ($rawViewerValue ?: 'NULL') . "</li>";
echo "<li>Tipo detectado: $viewerType</li>";
echo "<li>Configuración encontrada: " . (isset($viewerConfig['viewers'][$viewerType]) ? 'SÍ' : 'NO') . "</li>";
echo "</ul>";
?>
