<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

require_once '../../config/database.php';
require_once '../../middleware/auth.php';

try {
    // Validar token de sesión desde múltiples fuentes (cabeceras, server vars, query, cookies)
    $sessionToken = null;
    
    // Intentar obtener de getallheaders()
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    // Fallback a $_SERVER
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    // Fallback a parámetros GET (compatibilidad cuando el servidor no pasa Authorization a PHP)
    if (!$sessionToken) {
        $sessionToken = $_GET['token'] ?? $_GET['session_token'] ?? null;
    }
    
    // Fallback a cookies
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    
    // Remover 'Bearer ' si está presente
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de autorización requerido']);
        exit;
    }
    
    if (!validateSessionToken($sessionToken)) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido o expirado']);
        exit;
    }
    
    // Obtener datos del cuerpo de la petición
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON inválido']);
        exit;
    }
    
    // Validar campos requeridos
    if (!isset($data['id']) || empty($data['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID del informe es requerido']);
        exit;
    }
    
    $informe_id = intval($data['id']);
    
    if ($informe_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID del informe inválido']);
        exit;
    }
    
    // Conectar a la base de datos
    $pdo = getDBConnection();
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    try {
        // Verificar qué columnas PACS existen en la tabla
        try {
            $checkColumnsStmt = $pdo->query("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'informes'
                  AND COLUMN_NAME IN ('pacs_series_id', 'pacs_instance_id', 'pacs_study_id', 'fecha_enviado_pacs')
            ");
            $existingPacsColumns = $checkColumnsStmt ? $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Exception $e) {
            // Si falla la verificación de columnas, asumir que no existen
            error_log('Error verificando columnas PACS: ' . $e->getMessage());
            $existingPacsColumns = [];
        }
        
        // Construir SELECT dinámicamente según columnas disponibles
        $selectFields = ['id', 'titulo', 'patient_name', 'estado', 'usuario_id', 'fecha_creacion'];
        $pacsFields = [];
        
        // Agregar campos de estudio si existen (verificar todas las columnas de una vez)
        try {
            $checkAllColsStmt = $pdo->query("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'informes'
                  AND COLUMN_NAME IN ('study_id', 'study_instance_uid', 'estudio_id', 'orthanc_id')
            ");
            $existingStudyColumns = $checkAllColsStmt ? $checkAllColsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            foreach ($existingStudyColumns as $col) {
                $selectFields[] = $col;
            }
        } catch (Exception $e) {
            // Si falla, intentar agregar los campos más comunes
            $selectFields[] = 'estudio_id';
            $selectFields[] = 'study_instance_uid';
            $selectFields[] = 'study_id';
        }
        
        foreach (['pacs_series_id', 'pacs_instance_id', 'pacs_study_id', 'fecha_enviado_pacs'] as $col) {
            if (in_array($col, $existingPacsColumns)) {
                $selectFields[] = $col;
                $pacsFields[] = $col;
            }
        }
        
        // Verificar que el informe existe y obtener información
        $checkStmt = $pdo->prepare("
            SELECT " . implode(', ', $selectFields) . "
            FROM informes 
            WHERE id = ?
        ");
        $checkStmt->execute([$informe_id]);
        $informe = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$informe) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Informe no encontrado']);
            exit;
        }
        
        // Validar si el informe está en PACS (solo si las columnas existen)
        $isInPacs = false;
        if (!empty($pacsFields)) {
            foreach ($pacsFields as $field) {
                if (!empty($informe[$field])) {
                    $isInPacs = true;
                    break;
                }
            }
        }
        
        if ($isInPacs) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode([
                'error' => 'No se puede eliminar este informe',
                'details' => 'El informe está enviado a PACS. Primero debe eliminarlo de PACS antes de poder eliminarlo del sistema.'
            ]);
            exit;
        }
        
        // Validaciones de seguridad adicionales
        // Solo permitir eliminar informes en estado 'borrador' o muy recientes
        $fecha_creacion = new DateTime($informe['fecha_creacion']);
        $ahora = new DateTime();
        $diferencia_horas = $ahora->diff($fecha_creacion)->h + ($ahora->diff($fecha_creacion)->days * 24);
        
        // Permitir eliminación solo si:
        // 1. El informe está en borrador, O
        // 2. El informe fue creado hace menos de 24 horas y no está finalizado
        $puede_eliminar = (
            $informe['estado'] === 'borrador' || 
            ($diferencia_horas < 24 && $informe['estado'] !== 'finalizado')
        );
        
        if (!$puede_eliminar) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode([
                'error' => 'No se puede eliminar este informe',
                'details' => 'Solo se pueden eliminar informes en borrador o creados hace menos de 24 horas que no estén finalizados'
            ]);
            exit;
        }
        
        // Obtener información de audios asociados para mover archivos físicos a carpeta DELETED
        $audioStmt = $pdo->prepare("
            SELECT id, ruta_archivo 
            FROM audios_informe 
            WHERE informe_id = ?
        ");
        $audioStmt->execute([$informe_id]);
        $audios = $audioStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Inicializar contadores
        $movedFiles = 0;
        $failedFiles = 0;
        
        // Eliminar audios asociados de la base de datos
        if (!empty($audios)) {
            $deleteAudiosStmt = $pdo->prepare("
                DELETE FROM audios_informe 
                WHERE informe_id = ?
            ");
            $deleteAudiosStmt->execute([$informe_id]);
            
            // Mover archivos físicos de audio a carpeta DELETED en lugar de eliminarlos
            $deletedDir = '../../uploads/audios/DELETED/';
            
            // Crear carpeta DELETED si no existe
            if (!is_dir($deletedDir)) {
                if (!mkdir($deletedDir, 0755, true)) {
                    error_log('Error: No se pudo crear la carpeta DELETED: ' . $deletedDir);
                }
            }
            
            foreach ($audios as $audio) {
                $archivo_path = $audio['ruta_archivo'];
                
                // Si la ruta es relativa, convertir a absoluta
                if (strpos($archivo_path, 'uploads/audios/') === 0) {
                    $archivo_path = '../../' . $archivo_path;
                } elseif (strpos($archivo_path, '/') !== 0 && strpos($archivo_path, 'uploads/') !== 0) {
                    // Si no tiene prefijo, asumir que está en uploads/audios/
                    $archivo_path = '../../uploads/audios/' . basename($archivo_path);
                }
                
                // Normalizar separadores de ruta
                $archivo_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $archivo_path);
                
                if (file_exists($archivo_path)) {
                    try {
                        // Generar nombre único para el archivo movido (incluir ID del informe y timestamp)
                        $fileName = basename($archivo_path);
                        $fileInfo = pathinfo($fileName);
                        $newFileName = 'deleted_' . $informe_id . '_' . time() . '_' . uniqid() . 
                                      (isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '');
                        $newPath = $deletedDir . $newFileName;
                        
                        // Mover archivo a carpeta DELETED
                        if (rename($archivo_path, $newPath)) {
                            $movedFiles++;
                            error_log("✅ Audio movido a DELETED: {$archivo_path} -> {$newPath}");
                        } else {
                            $failedFiles++;
                            error_log("⚠️ Error moviendo archivo de audio: {$archivo_path} -> {$newPath}");
                        }
                    } catch (Exception $e) {
                        $failedFiles++;
                        // Log del error pero no detener el proceso
                        error_log('Error moviendo archivo de audio: ' . $archivo_path . ' - ' . $e->getMessage());
                    }
                } else {
                    // Archivo no encontrado - registrar pero no fallar
                    error_log("⚠️ Archivo de audio no encontrado (ya eliminado?): {$archivo_path}");
                }
            }
            
            // Log del resultado
            if ($movedFiles > 0 || $failedFiles > 0) {
                error_log(sprintf(
                    'Archivos de audio procesados al eliminar informe %d: %d movidos, %d fallidos',
                    $informe_id,
                    $movedFiles,
                    $failedFiles
                ));
            }
        }
        
        // Eliminar entradas del historial del informe
        $deleteHistorialStmt = $pdo->prepare("
            DELETE FROM informes_historial 
            WHERE informe_id = ?
        ");
        $deleteHistorialStmt->execute([$informe_id]);
        
        // Eliminar el informe principal
        $deleteInformeStmt = $pdo->prepare("
            DELETE FROM informes 
            WHERE id = ?
        ");
        $deleteInformeStmt->execute([$informe_id]);
        
        // Verificar que se eliminó correctamente
        if ($deleteInformeStmt->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'No se pudo eliminar el informe']);
            exit;
        }
        
        // Obtener IDs del estudio antes de confirmar la transacción
        $study_id = $informe['study_id'] ?? null;
        $study_instance_uid = $informe['study_instance_uid'] ?? null;
        $estudio_id = $informe['estudio_id'] ?? null;
        $orthanc_id = $informe['orthanc_id'] ?? null;
        
        // Confirmar transacción
        $pdo->commit();
        
        // Después de eliminar el informe, verificar si hay otros informes para este estudio
        // Si no hay otros informes, eliminar o actualizar el flag de informes_incompletos
        if ($study_id || $study_instance_uid || $estudio_id || $orthanc_id) {
            try {
                // Buscar otros informes para este estudio
                $otherReportsConditions = [];
                $otherReportsParams = [];
                
                if ($study_id) {
                    $otherReportsConditions[] = "study_id = ?";
                    $otherReportsParams[] = $study_id;
                }
                if ($study_instance_uid) {
                    $otherReportsConditions[] = "study_instance_uid = ?";
                    $otherReportsParams[] = $study_instance_uid;
                }
                if ($estudio_id) {
                    $otherReportsConditions[] = "estudio_id = ?";
                    $otherReportsParams[] = $estudio_id;
                }
                if ($orthanc_id) {
                    $otherReportsConditions[] = "orthanc_id = ?";
                    $otherReportsParams[] = $orthanc_id;
                }
                
                if (!empty($otherReportsConditions)) {
                    $otherReportsQuery = "
                        SELECT COUNT(*) as count 
                        FROM informes 
                        WHERE id != ? AND (" . implode(' OR ', $otherReportsConditions) . ")
                    ";
                    $otherReportsParams = array_merge([$informe_id], $otherReportsParams);
                    
                    $otherReportsStmt = $pdo->prepare($otherReportsQuery);
                    $otherReportsStmt->execute($otherReportsParams);
                    $otherReportsResult = $otherReportsStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Si no hay otros informes para este estudio, eliminar o actualizar el flag de informes_incompletos
                    if ($otherReportsResult && $otherReportsResult['count'] == 0) {
                        // Buscar flags para este estudio
                        $flagConditions = [];
                        $flagParams = [];
                        
                        if ($study_id) {
                            $flagConditions[] = "study_id = ?";
                            $flagParams[] = $study_id;
                        }
                        if ($orthanc_id) {
                            $flagConditions[] = "orthanc_id = ?";
                            $flagParams[] = $orthanc_id;
                        }
                        if ($study_instance_uid) {
                            $flagConditions[] = "study_instance_uid = ?";
                            $flagParams[] = $study_instance_uid;
                        }
                        
                        // También agregar estudio_id si existe (puede ser el ID principal)
                        if ($estudio_id) {
                            $flagConditions[] = "study_id = ?";
                            $flagParams[] = $estudio_id;
                        }
                        
                        if (!empty($flagConditions)) {
                            // Actualizar flags: quitar informes_incompletos ya que no hay más informes
                            $updateFlagQuery = "
                                UPDATE study_flags 
                                SET informes_incompletos = 0 
                                WHERE (" . implode(' OR ', $flagConditions) . ") AND informes_incompletos = 1
                            ";
                            $updateFlagStmt = $pdo->prepare($updateFlagQuery);
                            $updateFlagStmt->execute($flagParams);
                            
                            // Si el flag solo tenía informes_incompletos y no tiene prioridad, eliminarlo
                            $deleteFlagQuery = "
                                DELETE FROM study_flags 
                                WHERE (" . implode(' OR ', $flagConditions) . ") 
                                AND informes_incompletos = 0 
                                AND (prioridad IS NULL OR prioridad = 'normal')
                            ";
                            $deleteFlagStmt = $pdo->prepare($deleteFlagQuery);
                            $deleteFlagStmt->execute($flagParams);
                            
                            error_log(sprintf(
                                'Flags actualizados después de eliminar informe - Study IDs: %s, Flags actualizados: %d, Flags eliminados: %d',
                                implode(', ', array_filter([$study_id, $orthanc_id, $study_instance_uid])),
                                $updateFlagStmt->rowCount(),
                                $deleteFlagStmt->rowCount()
                            ));
                        }
                    }
                }
            } catch (Exception $e) {
                // Log del error pero no fallar la eliminación del informe
                error_log('Error actualizando flags después de eliminar informe: ' . $e->getMessage());
            }
        }
        
        // Log de la eliminación para auditoría
        error_log(sprintf(
            'Informe eliminado - ID: %d, Título: %s, Paciente: %s, Usuario: %d, Audios movidos a DELETED: %d',
            $informe_id,
            $informe['titulo'] ?? 'Sin título',
            $informe['patient_name'] ?? 'N/A',
            $informe['usuario_id'],
            $movedFiles
        ));
        
        // Respuesta exitosa
        echo json_encode([
            'success' => true,
            'message' => 'Informe eliminado correctamente',
            'data' => [
                'deleted_report_id' => $informe_id,
                'moved_audios_count' => $movedFiles,
                'failed_audios_count' => $failedFiles,
                'report_title' => $informe['titulo'] ?? 'Sin título',
                'patient_name' => $informe['patient_name'] ?? 'N/A',
                'audios_location' => 'Los archivos de audio fueron movidos a /uploads/audios/DELETED/',
                'study_id' => $study_id,
                'study_instance_uid' => $study_instance_uid,
                'orthanc_id' => $orthanc_id
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback en caso de error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Re-lanzar la excepción para que sea capturada por el catch externo
        throw $e;
    }
    
} catch (PDOException $e) {
    // Rollback si hay una transacción activa
    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollbackEx) {
            error_log('Error en rollback: ' . $rollbackEx->getMessage());
        }
    }
    error_log('Error de base de datos en eliminación de informe: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error en la base de datos',
        'details' => $e->getMessage(),
        'debug' => [
            'code' => $e->getCode(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
} catch (Exception $e) {
    // Rollback si hay una transacción activa
    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollbackEx) {
            error_log('Error en rollback: ' . $rollbackEx->getMessage());
        }
    }
    error_log('Error general en eliminación de informe: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'details' => $e->getMessage(),
        'debug' => [
            'code' => $e->getCode(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}
?>