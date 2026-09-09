<?php
// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Registrar errores en log

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/User.php';

try {
    // Validar token de sesión
    $token = null;
    
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? null;
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = $_SERVER['HTTP_AUTHORIZATION'];
    }
    
    if (!$token) {
        $token = $_GET['token'] ?? $_COOKIE['session_token'] ?? null;
    }
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de autorización requerido']);
        exit;
    }
    
    $token = str_replace('Bearer ', '', $token);
    
    $user = new User();
    $userData = $user->validateSession($token);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de sesión inválido']);
        exit;
    }
    
    // Obtener ID del informe
    $informeId = $_GET['informe_id'] ?? null;
    if (!$informeId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID del informe requerido']);
        exit;
    }
    
    // Conectar a la base de datos
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que el informe existe
    $checkInforme = "SELECT id, version, contenido_html, estado FROM informes WHERE id = ?";
    $checkStmt = $db->prepare($checkInforme);
    $checkStmt->execute([$informeId]);
    $informeExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$informeExists) {
        error_log("⚠️ history.php: Informe ID {$informeId} no existe");
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Informe no encontrado'
        ]);
        exit;
    }
    
    error_log("📋 history.php: Informe ID {$informeId} - Versión actual: {$informeExists['version']}");
    
    // Verificar registros en historial
    $checkHistorial = "SELECT COUNT(*) as total FROM informes_historial WHERE informe_id = ?";
    $checkHistStmt = $db->prepare($checkHistorial);
    $checkHistStmt->execute([$informeId]);
    $historialCount = $checkHistStmt->fetch(PDO::FETCH_ASSOC);
    error_log("📋 history.php: Historial encontrado para informe ID {$informeId}: {$historialCount['total']} registros");
    
    // Obtener todas las versiones del informe (incluyendo la actual y las del historial)
    // El médico informante se obtiene del primer informe del estudio mediante subconsultas
    $query = "
        SELECT 
            i.id,
            i.version as version_numero,
            i.contenido_html,
            i.estado,
            i.fecha_modificacion as fecha_cambio,
            'Versión actual' as motivo_cambio,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido,
            u.rol as usuario_rol,
            i.medico_informante_rol,
            -- Datos del médico informante (del primer informe del estudio)
            COALESCE(
                (SELECT i2.usuario_id 
                 FROM informes i2 
                 WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
                 ORDER BY i2.fecha_creacion ASC, i2.id ASC 
                 LIMIT 1),
                i.usuario_id
            ) as medico_informante_id,
            COALESCE(
                (SELECT u2.nombre 
                 FROM informes i2 
                 LEFT JOIN usuarios u2 ON i2.usuario_id = u2.id
                 WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
                 ORDER BY i2.fecha_creacion ASC, i2.id ASC 
                 LIMIT 1),
                u.nombre
            ) as medico_informante_nombre,
            COALESCE(
                (SELECT u2.apellido 
                 FROM informes i2 
                 LEFT JOIN usuarios u2 ON i2.usuario_id = u2.id
                 WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
                 ORDER BY i2.fecha_creacion ASC, i2.id ASC 
                 LIMIT 1),
                u.apellido
            ) as medico_informante_apellido,
            COALESCE(
                (SELECT i2.medico_informante_rol 
                 FROM informes i2 
                 WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
                 ORDER BY i2.fecha_creacion ASC, i2.id ASC 
                 LIMIT 1),
                i.medico_informante_rol,
                (SELECT u2.rol 
                 FROM informes i2 
                 LEFT JOIN usuarios u2 ON i2.usuario_id = u2.id
                 WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
                 ORDER BY i2.fecha_creacion ASC, i2.id ASC 
                 LIMIT 1),
                u.rol
            ) as medico_informante_rol_actual,
            -- El transcriptor de la versión actual es el usuario actual (quien creó/modificó)
            u.id as transcriptor_id,
            u.nombre as transcriptor_nombre,
            u.apellido as transcriptor_apellido,
            u.rol as transcriptor_rol,
            'actual' as tipo_version
        FROM informes i
        LEFT JOIN usuarios u ON i.usuario_id = u.id
        WHERE i.id = ?
        
        UNION ALL
        
        SELECT 
            ih.informe_id as id,
            ih.version_anterior as version_numero,
            ih.contenido_html_anterior as contenido_html,
            ih.estado_anterior as estado,
            ih.fecha_cambio,
            ih.motivo_cambio,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido,
            u.rol as usuario_rol,
            NULL as medico_informante_rol,
            -- Datos del médico informante (desde historial)
            ih.medico_informante_id,
            ih.medico_informante_nombre,
            ih.medico_informante_apellido,
            ih.medico_informante_rol as medico_informante_rol_actual,
            -- Datos del transcriptor (desde historial)
            ih.transcriptor_id,
            ih.transcriptor_nombre,
            ih.transcriptor_apellido,
            ih.transcriptor_rol,
            'historial' as tipo_version
        FROM informes_historial ih
        LEFT JOIN usuarios u ON ih.usuario_modificacion = u.id
        WHERE ih.informe_id = ?
        
        ORDER BY version_numero DESC, fecha_cambio DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId, $informeId]);
    $todasLasVersiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("📋 history.php: Total de versiones encontradas: " . count($todasLasVersiones));
    
    // Separar versión actual del historial
    $versionActual = null;
    $historial = [];
    
    foreach ($todasLasVersiones as $version) {
        if ($version['tipo_version'] === 'actual') {
            $versionActual = $version;
            error_log("✅ Versión actual encontrada: versión {$version['version_numero']}");
        } else {
            $historial[] = $version;
            error_log("✅ Versión histórica encontrada: versión {$version['version_numero']}, motivo: {$version['motivo_cambio']}");
        }
    }
    
    error_log("📊 history.php: Resumen - Actual: " . ($versionActual ? "Sí (v{$versionActual['version_numero']})" : "No") . ", Historial: " . count($historial) . " versiones");
    
    // Formatear fechas
    if ($versionActual) {
        $versionActual['fecha_cambio_formatted'] = date('d/m/Y H:i', strtotime($versionActual['fecha_cambio']));
        $versionActual['usuario_completo'] = trim($versionActual['usuario_nombre'] . ' ' . $versionActual['usuario_apellido']);
        $versionActual['medico_informante_completo'] = trim(($versionActual['medico_informante_nombre'] ?? '') . ' ' . ($versionActual['medico_informante_apellido'] ?? ''));
        $versionActual['transcriptor_completo'] = trim(($versionActual['transcriptor_nombre'] ?? '') . ' ' . ($versionActual['transcriptor_apellido'] ?? ''));
        $versionActual['contenido_preview'] = mb_substr(strip_tags($versionActual['contenido_html']), 0, 200) . '...';
    }
    
    foreach ($historial as &$version) {
        $version['fecha_cambio_formatted'] = date('d/m/Y H:i', strtotime($version['fecha_cambio']));
        $version['usuario_completo'] = trim($version['usuario_nombre'] . ' ' . $version['usuario_apellido']);
        $version['medico_informante_completo'] = trim(($version['medico_informante_nombre'] ?? '') . ' ' . ($version['medico_informante_apellido'] ?? ''));
        $version['transcriptor_completo'] = trim(($version['transcriptor_nombre'] ?? '') . ' ' . ($version['transcriptor_apellido'] ?? ''));
        $version['contenido_preview'] = mb_substr(strip_tags($version['contenido_html']), 0, 200) . '...';
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'informe_actual' => $versionActual,
            'historial' => $historial,
            'total_versiones' => count($historial) + ($versionActual ? 1 : 0)
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('Error obteniendo historial: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error en la base de datos',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Error general obteniendo historial: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'details' => $e->getMessage()
    ]);
}

// Asegurar que se envíe la respuesta
if (ob_get_level()) {
    ob_end_flush();
}
?>
