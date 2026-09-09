<?php
/**
 * API Endpoint para actualizar estado de audio
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Actualiza el estado de un audio y crea entrada en audios_estado_log
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../classes/User.php';
require_once '../../config/database.php';

try {
    // Validar sesión
    $sessionToken = null;
    if (isset($_COOKIE["session_token"]) && !empty($_COOKIE["session_token"])) {
        $sessionToken = trim($_COOKIE["session_token"]);
    } elseif (isset($_COOKIE["sessionToken"]) && !empty($_COOKIE["sessionToken"])) {
        $sessionToken = trim($_COOKIE["sessionToken"]);
    } elseif (isset($_POST["session_token"]) && !empty($_POST["session_token"])) {
        $sessionToken = trim($_POST["session_token"]);
    } elseif (isset($_GET["session_token"]) && !empty($_GET["session_token"])) {
        $sessionToken = trim($_GET["session_token"]);
    } elseif (isset($_SERVER["HTTP_AUTHORIZATION"])) {
        $auth = $_SERVER["HTTP_AUTHORIZATION"];
        $sessionToken = strpos($auth, "Bearer ") === 0 ? trim(substr($auth, 7)) : trim($auth);
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de sesión requerido']);
        exit();
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validar datos requeridos
    $audioId = $input['audio_id'] ?? null;
    $nuevoEstado = $input['estado'] ?? null;
    $accion = $input['accion'] ?? null;
    $workspacePanelId = $input['workspace_panel_id'] ?? null;
    $metadata = $input['metadata'] ?? null;
    
    if (!$audioId) {
        throw new Exception('audio_id es requerido');
    }
    
    if (!$nuevoEstado) {
        throw new Exception('estado es requerido');
    }
    
    // Validar estado válido
    $estadosValidos = ['en_papelera', 'listo_workspace', 'enviado_ftp', 'enviado_transcripcion', 'guardado_informe', 'eliminado'];
    if (!in_array($nuevoEstado, $estadosValidos)) {
        throw new Exception('Estado inválido: ' . $nuevoEstado);
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Obtener audio actual
    $audioQuery = "SELECT id, estado, estudio_id, usuario_id FROM audios_informe WHERE id = ?";
    $audioStmt = $db->prepare($audioQuery);
    $audioStmt->execute([$audioId]);
    $audioData = $audioStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$audioData) {
        throw new Exception('Audio no encontrado');
    }
    
    // Verificar que el usuario es el propietario del audio (root puede modificar cualquier audio)
    $isRoot = ($userData['nivel'] ?? 'user') === 'root';
    if (!$isRoot && $audioData['usuario_id'] != $userData['id']) {
        http_response_code(403);
        throw new Exception('No tiene permiso para modificar este audio');
    }
    
    // Normalizar estado anterior: NULL, cadena vacía o 'NULL' se tratan como NULL
    $estadoAnterior = $audioData['estado'];
    if ($estadoAnterior === null || $estadoAnterior === '' || $estadoAnterior === 'NULL') {
        $estadoAnterior = null;
    }
    $estudioId = $audioData['estudio_id'];
    
    // Validar transición de estado válida
    $transicionesValidas = [
        null               => ['en_papelera', 'listo_workspace', 'enviado_ftp', 'enviado_transcripcion', 'guardado_informe', 'eliminado'],
        'en_papelera'      => ['listo_workspace', 'enviado_ftp', 'enviado_transcripcion', 'guardado_informe', 'eliminado'],
        'listo_workspace'  => ['guardado_informe', 'enviado_ftp', 'enviado_transcripcion', 'eliminado', 'en_papelera'],
        'enviado_ftp'      => ['guardado_informe', 'enviado_transcripcion', 'eliminado'],
        'enviado_transcripcion' => ['guardado_informe', 'enviado_ftp', 'eliminado'],
        'eliminado'        => ['en_papelera'], // Recuperación desde papelera
        'guardado_informe' => ['enviado_ftp', 'enviado_transcripcion', 'eliminado'] // Permitir envío posterior
    ];
    
    // Nota: las transiciones permitidas desde 'guardado_informe' están definidas en $transicionesValidas
    
    // Si el estado anterior es NULL, permitir cualquier transición
    if ($estadoAnterior === null) {
        // Permitir cualquier transición desde NULL
    } elseif (isset($transicionesValidas[$estadoAnterior]) && 
        !in_array($nuevoEstado, $transicionesValidas[$estadoAnterior])) {
        throw new Exception("Transición inválida: de '" . ($estadoAnterior ?? 'NULL') . "' a '$nuevoEstado'");
    }
    
    // ── Paso 1: Actualizar el campo 'estado' (siempre existe) ──
    $updateStateStmt = $db->prepare("UPDATE audios_informe SET estado = ? WHERE id = ?");
    $updateStateStmt->execute([$nuevoEstado, $audioId]);
    
    // ── Paso 2: Actualizar campos auxiliares (activo, fechas) de forma defensiva ──
    // Cada update adicional se hace por separado para no fallar si la columna no existe.
    
    // Mapeo estado → campo de fecha
    $fechaCampoMap = [
        'eliminado'            => 'fecha_eliminacion',
        'enviado_ftp'          => 'fecha_envio_ftp',
        'enviado_transcripcion'=> 'fecha_envio_transcripcion',
        'guardado_informe'     => 'fecha_guardado_informe',
    ];
    
    if (isset($fechaCampoMap[$nuevoEstado])) {
        $fechaCampo = $fechaCampoMap[$nuevoEstado];
        try {
            $fechaStmt = $db->prepare("UPDATE audios_informe SET $fechaCampo = ? WHERE id = ? AND $fechaCampo IS NULL");
            $fechaStmt->execute([date('Y-m-d H:i:s'), $audioId]);
        } catch (PDOException $e) {
            // La columna puede no existir en tablas antiguas; ignorar silenciosamente
            error_log("update-estado.php: Columna $fechaCampo no disponible (continuando): " . $e->getMessage());
        }
    }
    
    // Actualizar campo 'activo' si existe
    try {
        if ($nuevoEstado === 'eliminado') {
            $activoStmt = $db->prepare("UPDATE audios_informe SET activo = 0 WHERE id = ?");
            $activoStmt->execute([$audioId]);
        } elseif ($nuevoEstado === 'en_papelera' && $estadoAnterior === 'eliminado') {
            // Recuperación: reactivar y limpiar campos
            $activoStmt = $db->prepare("UPDATE audios_informe SET activo = 1, recovered = 1, fecha_eliminacion = NULL WHERE id = ?");
            $activoStmt->execute([$audioId]);
        }
    } catch (PDOException $e) {
        // Las columnas activo/recovered pueden no existir; ignorar silenciosamente
        error_log("update-estado.php: Columna activo/recovered no disponible (continuando): " . $e->getMessage());
    }
    
    // Crear entrada en audios_estado_log
    try {
        $logQuery = "INSERT INTO audios_estado_log (
            audio_id, estado_anterior, estado_nuevo, accion, usuario_id, estudio_id, workspace_panel_id, metadata
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $logStmt = $db->prepare($logQuery);
        $logStmt->execute([
            $audioId,
            $estadoAnterior,
            $nuevoEstado,
            $accion ?? $nuevoEstado,
            $userData['id'],
            $estudioId,
            $workspacePanelId,
            $metadata ? json_encode($metadata) : null
        ]);
    } catch (Exception $e) {
        error_log('Error creando log de estado (continuando): ' . $e->getMessage());
        // No fallar si el log falla
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Estado actualizado correctamente',
        'data' => [
            'audio_id' => $audioId,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $nuevoEstado
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/audios/update-estado.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
