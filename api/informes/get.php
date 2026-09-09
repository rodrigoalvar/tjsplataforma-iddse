<?php
/**
 * API Endpoint para obtener informes médicos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../classes/User.php';
require_once '../../config/database.php';
require_once __DIR__ . '/transcription_status_helpers.php';

try {
    // Validar sesión - Obtener token de múltiples fuentes
    $sessionToken = null;
    
    // Intentar obtener de getallheaders() si está disponible
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    // Fallback a $_SERVER
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    // Fallback a parámetros GET
    if (!$sessionToken) {
        $sessionToken = $_GET['token'] ?? $_GET['session_token'] ?? null;
    }
    
    // Fallback a cookies
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    
    // Limpiar Bearer prefix si existe
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de autorización requerido']);
        exit();
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        error_log("Token validation failed for: " . substr($sessionToken, 0, 20) . "...");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida', 'debug' => 'Token: ' . substr($sessionToken, 0, 20) . '...']);
        exit();
    }
    
    error_log("Token validation successful for user: " . $userData['id']);
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Obtener parámetros de consulta
    $estudioId = $_GET['estudio_id'] ?? null;
    $studyInstanceUID = $_GET['study_instance_uid'] ?? null;
    $informeId = $_GET['informe_id'] ?? null;
    $incluirAudios = isset($_GET['incluir_audios']) ? (bool)$_GET['incluir_audios'] : false;
    $soloUltimaVersion = isset($_GET['solo_ultima_version']) ? (bool)$_GET['solo_ultima_version'] : true;
    
    $informes = [];
    
    if ($informeId) {
        // Verificar permisos del usuario
        $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
        $canManageAllReports = in_array('all', $userPermissions) || in_array('gestionInformes', $userPermissions);
        
        // Obtener informe específico por ID
        if ($canManageAllReports) {
            // Usuario con permisos de gestión puede ver cualquier informe
            $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id
                      WHERE i.id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$informeId]);
        } else {
            // Usuario normal solo puede ver sus propios informes
            $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id
                      WHERE i.id = ? AND i.usuario_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$informeId, $userData['id']]);
        }
        
        $informe = $stmt->fetch();
        
        if ($informe) {
            $informes[] = $informe;
            // Para informe específico, incluir audios automáticamente
            $incluirAudios = true;
        } else {
            // Informe no encontrado para este usuario
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Informe no encontrado',
                'error_code' => 'REPORT_NOT_FOUND',
                'informe_id' => $informeId
            ]);
            exit();
        }
        
    } elseif ($estudioId || $studyInstanceUID) {
        // Verificar permisos del usuario
        $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
        $canManageAllReports = in_array('all', $userPermissions) || in_array('gestionInformes', $userPermissions);
        
        // Obtener informes por estudio_id o study_instance_uid
        $searchValue = $estudioId ?: $studyInstanceUID;
        
        if ($canManageAllReports) {
            // Usuario con permisos de gestión puede ver cualquier informe
            $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id
                      WHERE i.estudio_id = ?";
        } else {
            // Usuario normal solo puede ver sus propios informes
            $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id
                      WHERE i.estudio_id = ? AND i.usuario_id = ?";
        }
        
        if ($soloUltimaVersion) {
            if ($canManageAllReports) {
                $query .= " AND i.version = (
                            SELECT MAX(version) FROM informes i2 
                            WHERE i2.estudio_id = i.estudio_id
                          )";
            } else {
                $query .= " AND i.version = (
                            SELECT MAX(version) FROM informes i2 
                            WHERE i2.estudio_id = i.estudio_id AND i2.usuario_id = i.usuario_id
                          )";
            }
        }
        
        $query .= " ORDER BY i.version DESC, i.fecha_creacion DESC";
        
        $stmt = $db->prepare($query);
        if ($canManageAllReports) {
            $stmt->execute([$searchValue]);
        } else {
            $stmt->execute([$searchValue, $userData['id']]);
        }
        $informes = $stmt->fetchAll();
        
    } else {
        // Verificar permisos del usuario
        $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
        $canManageAllReports = in_array('all', $userPermissions) || in_array('gestionInformes', $userPermissions);
        
        // Obtener todos los informes del usuario o todos si tiene permisos de gestión
        if ($canManageAllReports) {
            // Usuario con permisos de gestión puede ver todos los informes
            $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id";
        } else {
            // Usuario normal solo puede ver sus propios informes
            $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id
                      WHERE i.usuario_id = ?";
        }
        
        if ($soloUltimaVersion) {
            if ($canManageAllReports) {
                $query .= " WHERE i.version = (
                            SELECT MAX(version) FROM informes i2 
                            WHERE i2.estudio_id = i.estudio_id
                          )";
            } else {
                $query .= " AND i.version = (
                            SELECT MAX(version) FROM informes i2 
                            WHERE i2.estudio_id = i.estudio_id AND i2.usuario_id = i.usuario_id
                          )";
            }
        }
        
        $query .= " ORDER BY i.fecha_creacion DESC";
        
        $stmt = $db->prepare($query);
        if ($canManageAllReports) {
            $stmt->execute();
        } else {
            $stmt->execute([$userData['id']]);
        }
        $informes = $stmt->fetchAll();
    }
    
    // Si se solicita incluir audios, obtenerlos para cada informe
    if ($incluirAudios && !empty($informes)) {
        $informeIds = array_column($informes, 'id');
        $placeholders = str_repeat('?,', count($informeIds) - 1) . '?';
        $hasAudioEstado = informesAudioHasEstadoColumn($db);
        $estadoSelect = $hasAudioEstado ? 'ai.estado,' : '';
        
        $audioQuery = "SELECT ai.id, ai.informe_id, ai.estudio_id, ai.ruta_archivo,
                              ai.duracion_segundos, ai.tamano_bytes, ai.tipo_mime,
                              ai.calidad_audio, ai.nombre_archivo, ai.nombre_original,
                              ai.datos_sincronizacion, ai.fecha_creacion, ai.fecha_modificacion,
                              ai.transcripcion_texto, {$estadoSelect}
                              (SELECT at2.transcription_text
                               FROM ai_transcriptions at2
                               WHERE at2.audio_id = ai.id AND at2.status = 'completed'
                               ORDER BY at2.created_at DESC LIMIT 1) AS transcription_from_ai,
                              (SELECT at2.whisper_response
                               FROM ai_transcriptions at2
                               WHERE at2.audio_id = ai.id AND at2.status = 'completed'
                               ORDER BY at2.created_at DESC LIMIT 1) AS whisper_response
                       FROM audios_informe ai
                       WHERE ai.informe_id IN ({$placeholders})
                       ORDER BY ai.fecha_creacion ASC, ai.id ASC";
        $audioStmt = $db->prepare($audioQuery);
        $audioStmt->execute($informeIds);
        $audios = $audioStmt->fetchAll();
        
        // Agrupar audios por informe_id
        $audiosPorInforme = [];
        foreach ($audios as $audio) {
            // Priorizar transcripción de ai_transcriptions, luego audios_informe
            $transcripcion = $audio['transcription_from_ai'] ?? $audio['transcripcion_texto'] ?? null;
            $audio['transcripcion'] = $transcripcion; // campo esperado por JS
            
            // Procesar whisper_response para extraer segments
            $whisperResponse = null;
            $segments = null;
            if (!empty($audio['whisper_response'])) {
                $whisperResponse = json_decode($audio['whisper_response'], true);
                if ($whisperResponse && isset($whisperResponse['segments'])) {
                    $segments = $whisperResponse['segments'];
                }
            }
            $audio['whisper_response'] = $whisperResponse;
            $audio['segments'] = $segments;

            enrichAudioWithTxStatus($db, $audio);
            
            $audiosPorInforme[$audio['informe_id']][] = $audio;
        }
        
        // Agregar audios a cada informe
        foreach ($informes as &$informe) {
            $informe['audios'] = $audiosPorInforme[$informe['id']] ?? [];
        }
    }
    
    // Formatear fechas y datos adicionales
    foreach ($informes as &$informe) {
        // Convertir fechas a formato ISO
        if ($informe['fecha_creacion']) {
            $informe['fecha_creacion_iso'] = date('c', strtotime($informe['fecha_creacion']));
        }
        if ($informe['fecha_modificacion']) {
            $informe['fecha_modificacion_iso'] = date('c', strtotime($informe['fecha_modificacion']));
            $informe['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($informe['fecha_modificacion']));
        }
        if ($informe['fecha_finalizacion']) {
            $informe['fecha_finalizacion_iso'] = date('c', strtotime($informe['fecha_finalizacion']));
        }
        
        // Agregar badge de estado
        $estadoBadges = [
            'borrador' => 'secondary',
            'transcripto' => 'info',
            'revision' => 'warning',
            'revisado' => 'warning',
            'finalizado' => 'success',
            'firmado' => 'primary'
        ];
        $informe['estado_badge'] = $estadoBadges[$informe['estado']] ?? 'secondary';
        $informe['origen'] = $informe['origen'] ?? 'plataforma';
        $informe['editable'] = !(($informe['origen'] ?? '') === 'externo'
            || (!empty($informe['pdf_path']) && (
                strpos((string)($informe['contenido_html'] ?? ''), 'informe-adjunto') !== false
                || strpos((string)($informe['contenido_html'] ?? ''), 'pdf-viewer-btn') !== false
            )));
        $informe['sin_medico_asignado'] = empty($informe['usuario_id']);
        $informe['tiene_firma'] = !empty($informe['firmado_en']);
        
        // Agregar estadísticas básicas
        $informe['estadisticas'] = [
            'caracteres_html' => strlen($informe['contenido_html'] ?? ''),
            'caracteres_texto' => strlen($informe['contenido_texto'] ?? ''),
            'palabras_aproximadas' => str_word_count($informe['contenido_texto'] ?? ''),
            'tiene_audios' => isset($informe['audios']) ? count($informe['audios']) > 0 : null
        ];
    }
    
    // Respuesta exitosa
    if ($informeId && count($informes) === 1) {
        // Para informe específico, devolver el informe directamente
        echo json_encode([
            'success' => true,
            'data' => $informes[0]
        ]);
    } else {
        // Para múltiples informes, devolver la estructura completa
        echo json_encode([
            'success' => true,
            'data' => [
                'informes' => $informes,
                'total' => count($informes),
                'filtros_aplicados' => [
                    'estudio_id' => $estudioId,
                    'informe_id' => $informeId,
                    'incluir_audios' => $incluirAudios,
                    'solo_ultima_version' => $soloUltimaVersion
                ]
            ]
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'GET_REPORT_ERROR'
    ]);
} catch (PDOException $e) {
    error_log("Error de base de datos en get.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_code' => 'DATABASE_ERROR'
    ]);
}
?>