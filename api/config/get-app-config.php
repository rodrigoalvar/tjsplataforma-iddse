<?php
/**
 * API pública para obtener configuración de la aplicación (título y logo)
 * No requiere autenticación ya que es para la pantalla de inicio
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

try {
    $db = getDBConnection();
    
    if (!$db) {
        // Si no hay conexión, devolver valores por defecto
        echo json_encode([
            'success' => true,
            'titulo' => 'GESTION DE ESTUDIOS',
            'logo' => null
        ]);
        exit();
    }
    
    // Asegurar que la tabla configuracion existe
    $db->exec("
        CREATE TABLE IF NOT EXISTS configuracion (
            clave VARCHAR(100) PRIMARY KEY,
            valor TEXT,
            descripcion TEXT,
            fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Obtener título
    $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'app_titulo'");
    $stmt->execute();
    $tituloResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $titulo = $tituloResult ? $tituloResult['valor'] : 'GESTION DE ESTUDIOS';
    
    // Obtener logo
    $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'app_logo'");
    $stmt->execute();
    $logoResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $logo = null;
    
    if ($logoResult && $logoResult['valor']) {
        $logoPath = $logoResult['valor'];
        // Verificar que el archivo existe
        $fullPath = __DIR__ . '/../../' . $logoPath;
        if (file_exists($fullPath)) {
            $logo = $logoPath;
        }
    }
    
    echo json_encode([
        'success' => true,
        'titulo' => $titulo,
        'logo' => $logo
    ]);
    
} catch (Exception $e) {
    error_log("Error en config/get-app-config.php: " . $e->getMessage());
    // En caso de error, devolver valores por defecto
    echo json_encode([
        'success' => true,
        'titulo' => 'GESTION DE ESTUDIOS',
        'logo' => null
    ]);
}
