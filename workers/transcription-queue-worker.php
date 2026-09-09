<?php
/**
 * Worker para procesar la cola de transcripciones automáticas
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script debe ejecutarse periódicamente mediante cron:
 * * * * * * php /var/www/tjsiddse/workers/transcription-queue-worker.php
 * (cada minuto)
 */

// Configurar límites de tiempo y memoria
set_time_limit(300); // 5 minutos máximo
ini_set('memory_limit', '512M');

// Cambiar al directorio del script
chdir(__DIR__);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/WhisperClient.php';
require_once __DIR__ . '/../api/ai-informes.php';

function txRetryDelaySeconds(int $retryCount): int {
    // Backoff simple y estable: 0s, 60s, 180s, 600s, 1800s
    if ($retryCount <= 0) return 0;
    if ($retryCount === 1) return 60;
    if ($retryCount === 2) return 180;
    if ($retryCount === 3) return 600;
    return 1800;
}

// Función para logging
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/transcription-queue-worker.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // También mostrar en stdout si se ejecuta manualmente
    if (php_sapi_name() === 'cli') {
        echo $logMessage;
    }
}

try {
    logMessage('Iniciando worker de cola de transcripciones');
    
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Asegurar que las tablas existen
    ensureAiTables($db);
    
    // Verificar si la tabla de cola existe
    $checkTable = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
    if ($checkTable->rowCount() === 0) {
        logMessage('La tabla ai_transcription_queue no existe. Ejecuta el script SQL: database/create_transcription_queue.sql', 'ERROR');
        exit(1);
    }
    
    // Obtener configuración
    $config = getAiConfig($db);
    
    // Verificar si la transcripción automática está habilitada
    if (!isset($config['auto_transcribe_enabled']) || !$config['auto_transcribe_enabled']) {
        logMessage('Transcripción automática deshabilitada. Worker finalizado.');
        exit(0);
    }

    require_once __DIR__ . '/../api/transcription_health.php';
    $txHealth = transcriptionCheckHealth($db, null, 4);
    if (empty($txHealth['allow_enqueue'])) {
        logMessage('Whisper no listo (' . ($txHealth['status'] ?? 'down') . '): ' . ($txHealth['message'] ?? '') . ' — no se procesa la cola.');
        exit(0);
    }
    if (!empty($txHealth['degraded'])) {
        logMessage('Whisper degradado: ' . ($txHealth['message'] ?? '') . ' — se continúa con cautela.');
    }
    
    // Obtener máximo de transcripciones concurrentes
    $maxConcurrent = isset($config['max_concurrent_transcriptions']) ? intval($config['max_concurrent_transcriptions']) : 1;
    
    // Verificar cuántas transcripciones están en proceso
    $checkProcessing = $db->prepare("
        SELECT COUNT(*) as count 
        FROM ai_transcription_queue 
        WHERE status = 'processing'
    ");
    $checkProcessing->execute();
    $processingCount = $checkProcessing->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($processingCount >= $maxConcurrent) {
        logMessage("Ya hay $processingCount transcripciones en proceso (máximo: $maxConcurrent). Esperando...");
        exit(0);
    }
    
    // Recuperar trabajos "processing" que quedaron colgados (p.ej. crash/kill worker)
    $processingTimeoutSeconds = 20 * 60; // 20 minutos
    $staleStmt = $db->prepare("
        SELECT id, retry_count, max_retries
        FROM ai_transcription_queue
        WHERE status = 'processing'
          AND started_at IS NOT NULL
          AND TIMESTAMPDIFF(SECOND, started_at, NOW()) >= ?
        LIMIT 20
    ");
    $staleStmt->execute([$processingTimeoutSeconds]);
    $staleJobs = $staleStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($staleJobs as $stale) {
        $qid = (int)$stale['id'];
        $retry = ((int)($stale['retry_count'] ?? 0)) + 1;
        $maxRetry = (int)($stale['max_retries'] ?? 3);
        if ($retry < $maxRetry) {
            $backoff = txRetryDelaySeconds($retry);
            $db->prepare("
                UPDATE ai_transcription_queue
                SET status = 'pending',
                    retry_count = ?,
                    error_message = ?,
                    started_at = NOW()
                WHERE id = ? AND status = 'processing'
            ")->execute([$retry, 'Recuperado por timeout de processing', $qid]);
            logMessage("Job colgado recuperado a pending: queue_id=$qid retry=$retry backoff={$backoff}s");
        } else {
            $db->prepare("
                UPDATE ai_transcription_queue
                SET status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ? AND status = 'processing'
            ")->execute(['Timeout en processing (max_retries alcanzado)', $qid]);
            logMessage("Job colgado marcado failed: queue_id=$qid", 'ERROR');
        }
    }

    // Obtener siguiente item pendiente elegible según backoff (FIFO con prioridad)
    // Usar un enfoque más seguro para evitar deadlocks:
    // 1. Seleccionar sin FOR UPDATE primero
    // 2. Intentar actualizar el estado a 'processing' con una condición que solo permita actualizar si está en 'pending'
    // 3. Si la actualización afecta 0 filas, significa que otro worker ya lo tomó
    
    $stmt = $db->prepare("
        SELECT *
        FROM ai_transcription_queue
        WHERE status = 'pending'
          AND (
              started_at IS NULL
              OR TIMESTAMPDIFF(SECOND, started_at, NOW()) >=
                  CASE
                      WHEN retry_count <= 0 THEN 0
                      WHEN retry_count = 1 THEN 60
                      WHEN retry_count = 2 THEN 180
                      WHEN retry_count = 3 THEN 600
                      ELSE 1800
                  END
          )
        ORDER BY priority DESC, created_at ASC
        LIMIT 1
    ");
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        logMessage('No hay items pendientes en la cola');
        exit(0);
    }
    
    $queueId = $item['id'];
    $audioId = $item['audio_id'];
    $studyId = $item['study_id'];
    $orthancStudyId = $item['orthanc_study_id'];
    
    // Intentar actualizar el estado a 'processing' solo si aún está en 'pending'
    // Esto evita que múltiples workers procesen el mismo item
    $updateStmt = $db->prepare("
        UPDATE ai_transcription_queue 
        SET status = 'processing', 
            started_at = NOW() 
        WHERE id = ? AND status = 'pending'
    ");
    $updateStmt->execute([$queueId]);
    $rowsAffected = $updateStmt->rowCount();
    
    if ($rowsAffected === 0) {
        // Otro worker ya tomó este item, buscar otro
        logMessage("Item de cola ID $queueId ya fue tomado por otro worker. Buscando siguiente...");
        exit(0);
    }
    
    logMessage("Procesando item de cola ID: $queueId, Audio ID: $audioId");
    
    try {
        // Obtener información del audio
        $audioStmt = $db->prepare("
            SELECT id, activo, estudio_id, ruta_archivo, informe_id
            FROM audios_informe
            WHERE id = ?
        ");
        $audioStmt->execute([$audioId]);
        $audio = $audioStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$audio) {
            throw new Exception("Audio no encontrado (ID: $audioId)");
        }
        
        if ($audio['activo'] != 1) {
            throw new Exception("El audio está inactivo (ID: $audioId)");
        }
        
        // Obtener ruta física del archivo
        $audioFilePath = __DIR__ . '/../' . $audio['ruta_archivo'];
        if (!file_exists($audioFilePath)) {
            throw new Exception('Archivo de audio no encontrado: ' . $audio['ruta_archivo']);
        }
        
        // Si no hay study_id, intentar encontrarlo o crearlo
        if (!$studyId || $studyId <= 0) {
            // Buscar estudio por orthanc_study_id
            if ($orthancStudyId) {
                $studyStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                $studyStmt->execute([$orthancStudyId]);
                $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
                if ($study) {
                    $studyId = $study['id'];
                }
            }
            
            // Si aún no hay study_id, buscar por informe_id
            if ((!$studyId || $studyId <= 0) && $audio['informe_id']) {
                $informeStmt = $db->prepare("
                    SELECT estudio_id, patient_id, patient_name, modality, study_description
                    FROM informes
                    WHERE id = ?
                ");
                $informeStmt->execute([$audio['informe_id']]);
                $informe = $informeStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($informe && $informe['estudio_id']) {
                    // Buscar estudio local
                    $studyStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                    $studyStmt->execute([$informe['estudio_id']]);
                    $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($study) {
                        $studyId = $study['id'];
                    } else {
                        // Crear estudio desde datos del informe
                        $createStudyStmt = $db->prepare("
                            INSERT INTO estudios 
                            (orthanc_study_id, patient_id_pacs, patient_name_pacs, modality, study_description, 
                             study_date, status, fecha_creacion)
                            VALUES (?, ?, ?, ?, ?, CURDATE(), 'COMPLETADO', NOW())
                        ");
                        $createStudyStmt->execute([
                            $informe['estudio_id'],
                            $informe['patient_id'],
                            $informe['patient_name'],
                            $informe['modality'] ?: 'UNKNOWN',
                            $informe['study_description']
                        ]);
                        $studyId = $db->lastInsertId();
                        logMessage("Estudio creado automáticamente: ID=$studyId");
                    }
                }
            }
        }
        
        if (!$studyId || $studyId <= 0) {
            throw new Exception("No se pudo determinar el study_id para el audio ID: $audioId");
        }
        
        // Crear cliente Whisper
        $whisperConfig = [
            'whisper_method' => $config['whisper_method'] ?? 'whisper-server',
            'whisper_api_url' => $config['whisper_api_url'] ?? 'http://localhost:8080',
            'whisper_cli_api_url' => $config['whisper_cli_api_url'] ?? 'http://localhost:3001',
            'whisper_model' => $config['whisper_model'] ?? 'base',
            'whisper_language' => $config['whisper_language'] ?? 'es',
            'whisper_timeout' => 600,
            'ffmpeg_rest_url' => $config['ffmpeg_rest_url'] ?? null,
        ];
        $client = new WhisperClient($whisperConfig);
        
        // Verificar si ya existe una transcripción completada
        $checkTranscription = $db->prepare("
            SELECT id FROM ai_transcriptions 
            WHERE audio_id = ? AND status = 'completed'
            LIMIT 1
        ");
        $checkTranscription->execute([$audioId]);
        $existingTranscription = $checkTranscription->fetch(PDO::FETCH_ASSOC);
        
        if ($existingTranscription) {
            logMessage("Audio ID $audioId ya tiene transcripción completada. Marcando cola como completada.");
            $updateStmt = $db->prepare("
                UPDATE ai_transcription_queue 
                SET status = 'completed', 
                    completed_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$queueId]);
            exit(0);
        }
        
        // Crear registro de transcripción
        $insertStmt = $db->prepare("
            INSERT INTO ai_transcriptions 
            (study_id, audio_id, audio_file_path, status, created_by, created_at)
            VALUES (?, ?, ?, 'processing', ?, NOW())
        ");
        $insertStmt->execute([
            $studyId,
            $audioId,
            $audio['ruta_archivo'],
            $item['created_by'] ?? null
        ]);
        $transcriptionId = $db->lastInsertId();
        
        logMessage("Iniciando transcripción. Transcription ID: $transcriptionId");
        
        // Transcribir
        $startTime = microtime(true);
        $result = $client->transcribeAudio($audioFilePath);
        $processingTime = microtime(true) - $startTime;
        
        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Error desconocido en la transcripción');
        }
        
        // Preparar respuesta completa de whisper para debug
        $whisperResponse = json_encode([
            'model'    => $result['model'] ?? null,
            'language' => $result['language'] ?? null,
            'mode'     => $result['mode'] ?? null,
            'segments' => $result['segments'] ?? null,
            'stats'    => $result['stats'] ?? null,
            'raw'      => $result['raw_response'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        // Actualizar transcripción
        $updateTranscriptionStmt = $db->prepare("
            UPDATE ai_transcriptions 
            SET transcription_text = ?, 
                status = 'completed', 
                model_used = ?, 
                processing_time = ?, 
                whisper_response = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateTranscriptionStmt->execute([
            $result['text'],
            $result['model'] ?? $config['whisper_model'] ?? 'base',
            round($processingTime, 2),
            $whisperResponse,
            $transcriptionId
        ]);
        
        // Actualizar transcripción en audios_informe
        // Actualizar el campo transcripcion_texto y fecha_modificacion para que la interfaz detecte el cambio
        $updateAudioStmt = $db->prepare("
            UPDATE audios_informe 
            SET transcripcion_texto = ?, 
                fecha_modificacion = NOW()
            WHERE id = ?
        ");
        $updateAudioStmt->execute([$result['text'], $audioId]);
        
        // Actualizar duración del audio si Whisper la devolvió y no existe o es diferente
        // La duración puede venir de result['stats']['audio_duration_s'], result['audio_duration_s']
        // o result['converted_audio_duration'] (duración del MP3 convertido)
        $audioDuration = null;
        if (isset($result['stats']['audio_duration_s'])) {
            $audioDuration = $result['stats']['audio_duration_s'];
        } elseif (isset($result['audio_duration_s'])) {
            $audioDuration = $result['audio_duration_s'];
        } elseif (isset($result['converted_audio_duration'])) {
            // Usar duración del MP3 convertido si no hay duración de Whisper
            $audioDuration = $result['converted_audio_duration'];
        }
        
        if ($audioDuration && $audioDuration > 0) {
            // Actualizar duración si no existe o si la diferencia es mayor a 0.5 segundos
            $updateDurationStmt = $db->prepare("
                UPDATE audios_informe 
                SET duracion_segundos = ? 
                WHERE id = ? AND (duracion_segundos IS NULL OR ABS(COALESCE(duracion_segundos, 0) - ?) > 0.5)
            ");
            $updateDurationStmt->execute([$audioDuration, $audioId, $audioDuration]);
            
            if ($updateDurationStmt->rowCount() > 0) {
                logMessage("Duración actualizada para audio_id={$audioId}: {$audioDuration} segundos (desde MP3 convertido/Whisper)", 'INFO');
            } else {
                logMessage("Duración no actualizada para audio_id={$audioId}: ya existe y diferencia < 0.5s", 'INFO');
            }
        } else {
            logMessage("No se obtuvo duración para audio_id={$audioId} (audioDuration={$audioDuration})", 'WARNING');
        }
        
        // Marcar cola como completada
        $updateStmt = $db->prepare("
            UPDATE ai_transcription_queue 
            SET status = 'completed', 
                completed_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$queueId]);
        
        logMessage("Transcripción completada exitosamente. Queue ID: $queueId, Transcription ID: $transcriptionId, Tiempo: " . round($processingTime, 2) . "s");
        
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        logMessage("Error procesando cola ID $queueId: $errorMessage", 'ERROR');
        
        // Actualizar estado a failed
        $retryCount = $item['retry_count'] + 1;
        $maxRetries = $item['max_retries'] ?? 3;
        
        if ($retryCount < $maxRetries) {
            // Reintentar
            $updateStmt = $db->prepare("
                UPDATE ai_transcription_queue
                SET status = 'pending',
                    retry_count = ?,
                    error_message = ?,
                    started_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$retryCount, $errorMessage, $queueId]);
            logMessage("Reintentando cola ID $queueId (intento $retryCount de $maxRetries)");
        } else {
            // Marcar como fallido definitivamente
            $updateStmt = $db->prepare("
                UPDATE ai_transcription_queue 
                SET status = 'failed', 
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$errorMessage, $queueId]);
            
            // También marcar transcripción como fallida si existe
            if (isset($transcriptionId)) {
                $updateTranscriptionStmt = $db->prepare("
                    UPDATE ai_transcriptions 
                    SET status = 'failed', 
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateTranscriptionStmt->execute([$errorMessage, $transcriptionId]);
            }
            
            logMessage("Cola ID $queueId marcada como fallida después de $maxRetries intentos", 'ERROR');
        }
        
        exit(1);
    }
    
} catch (Exception $e) {
    logMessage("Error fatal en worker: " . $e->getMessage(), 'ERROR');
    exit(1);
}
