<?php
/**
 * API para obtener la configuración del visor DICOM del usuario actual
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/workspace/get-user-viewer-config.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once '../../config/database.php';
    require_once '../../middleware/auth.php';
    
    // Solo permitir método GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }
    
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
        throw new Exception('Token de sesión requerido');
    }
    
    // Validar sesión y obtener usuario
    $userData = getUserFromToken($token);
    if (!$userData) {
        throw new Exception('Sesión inválida');
    }
    
    $userId = $userData['id'];
    
    // Conectar a la base de datos
    $pdo = getDBConnection();
    
    // Obtener configuración del visor del usuario
    $query = "SELECT dicom_viewer FROM usuarios WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $userConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log para debugging
    error_log("🔍 [get-user-viewer-config] Usuario ID: $userId");
    error_log("🔍 [get-user-viewer-config] Configuración del usuario desde BD: " . json_encode($userConfig));
    
    // Obtener configuración del visor desde la configuración del sistema (desde BD)
    require_once '../config/orthanc_config.php';
    OrthancConfig::clearCache(); // Limpiar cache para obtener valores actualizados
    $orthancConfig = OrthancConfig::getConfig(); // Usar getConfig() que es público
    $viewerConfig = $orthancConfig['viewer'];
    
    error_log("🔍 [get-user-viewer-config] Configuración completa de visores cargada desde BD:");
    error_log("   - UDV URL: " . ($viewerConfig['viewers']['UDV']['url'] ?? 'NO CONFIGURADO'));
    error_log("   - StoneViewer URL: " . ($viewerConfig['viewers']['StoneViewer']['url'] ?? 'NO CONFIGURADO'));
    error_log("   - Oviyam URL: " . ($viewerConfig['viewers']['Oviyam']['url'] ?? 'NO CONFIGURADO'));
    
    error_log("🔍 [get-user-viewer-config] Visores disponibles: " . json_encode(array_keys($viewerConfig['viewers'] ?? [])));
    
    // Determinar qué visor usar
    $viewerType = 'UDV'; // Valor por defecto
    $rawViewerValue = null;
    
    if ($userConfig && !empty($userConfig['dicom_viewer'])) {
        $rawViewerValue = trim($userConfig['dicom_viewer']);
        error_log("🔍 [get-user-viewer-config] Valor crudo de BD: '$rawViewerValue'");
        
        // Normalizar: convertir a minúsculas para comparación
        $normalized = strtolower($rawViewerValue);
        
        // Mapear variaciones de nombres a valores estándar
        if (strpos($normalized, 'stone') !== false) {
            // Cualquier variación de Stone (StoneViewer, StoneWebViewer, etc.) -> StoneViewer
            $viewerType = 'StoneViewer';
            error_log("✅ [get-user-viewer-config] Detectado visor Stone (normalizado a StoneViewer)");
        } elseif (strpos($normalized, 'udv') !== false || strpos($normalized, 'u-dicom') !== false || strpos($normalized, 'universal') !== false) {
            $viewerType = 'UDV';
            error_log("✅ [get-user-viewer-config] Detectado visor UDV");
        } elseif (strpos($normalized, 'oviyam') !== false) {
            $viewerType = 'Oviyam';
            error_log("✅ [get-user-viewer-config] Detectado visor Oviyam");
        } else {
            // Si no coincide con ninguno, usar el valor tal cual pero validar
            $viewerType = $rawViewerValue;
            error_log("⚠️ [get-user-viewer-config] Valor no reconocido, usando tal cual: '$viewerType'");
        }
        
        // Validar que sea uno de los valores permitidos
        if (!in_array($viewerType, ['UDV', 'StoneViewer', 'Oviyam'])) {
            error_log("⚠️ [get-user-viewer-config] Tipo de visor inválido después de normalizar: '$viewerType' (original: '$rawViewerValue'), usando UDV por defecto");
            $viewerType = 'UDV';
        } else {
            error_log("✅ [get-user-viewer-config] Visor normalizado: '$rawViewerValue' -> '$viewerType'");
        }
    } else {
        error_log("⚠️ [get-user-viewer-config] No se encontró configuración del usuario (dicom_viewer está vacío o null), usando UDV por defecto");
    }
    
    error_log("✅ [get-user-viewer-config] Tipo de visor final seleccionado: $viewerType");
    
    // Obtener configuración del visor específico
    $viewerInfo = null;
    if (isset($viewerConfig['viewers'][$viewerType])) {
        $viewerInfo = $viewerConfig['viewers'][$viewerType];
        
        // Validar que la URL esté configurada
        if (empty($viewerInfo['url']) || trim($viewerInfo['url']) === '') {
            error_log("❌ [get-user-viewer-config] ERROR: La URL del visor $viewerType está vacía en la configuración");
            error_log("   Por favor, configure la URL en Configuración > Servidor PACS");
            // Intentar usar la URL general como fallback
            if (!empty($viewerConfig['url'])) {
                $viewerInfo['url'] = $viewerConfig['url'];
                error_log("⚠️ [get-user-viewer-config] Usando URL general como fallback: " . $viewerInfo['url']);
            }
        }
        
        error_log("✅ [get-user-viewer-config] Configuración encontrada para $viewerType: " . json_encode($viewerInfo));
    } else {
        error_log("❌ [get-user-viewer-config] ERROR: No se encontró configuración para '$viewerType' en viewers");
        error_log("🔍 [get-user-viewer-config] Visores disponibles: " . implode(', ', array_keys($viewerConfig['viewers'] ?? [])));
        
        // Fallback a configuración por defecto (usar valores de la configuración general)
        $viewerInfo = [
            'url' => $viewerConfig['url'] ?? '',
            'format' => 'pacs_studyid',
            'study_id_param' => $viewerConfig['study_id_param'] ?? 'studyId',
            'pacs_name' => $viewerConfig['pacs_name'] ?? ''
        ];
        
        if (empty($viewerInfo['url'])) {
            error_log("❌ [get-user-viewer-config] ERROR CRÍTICO: No hay URL configurada ni para el visor específico ni para el visor general");
            error_log("   Por favor, configure las URLs en Configuración > Servidor PACS");
        }
        
        error_log("⚠️ [get-user-viewer-config] No se encontró configuración específica para '$viewerType', usando valores generales: " . json_encode($viewerInfo));
    }
    
    $response = [
        'success' => true,
        'viewer_type' => $viewerType,
        'viewer_config' => $viewerInfo,
        'user_id' => $userId,
        'debug' => [
            'user_config_from_db' => $userConfig['dicom_viewer'] ?? null,
            'normalized_viewer_type' => $viewerType,
            'available_viewers' => array_keys($viewerConfig['viewers'] ?? [])
        ]
    ];
    
    error_log("📤 [get-user-viewer-config] Respuesta final: " . json_encode($response));
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
