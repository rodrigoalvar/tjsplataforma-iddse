<?php
/**
 * Script para agregar audios existentes sin transcripción a la cola
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Uso: php add-existing-audios-to-queue.php
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = getDBConnection();
    
    // Verificar que las tablas existen
    $configCheck = $db->query("SHOW TABLES LIKE 'ai_config'");
    if ($configCheck->rowCount() === 0) {
        echo "❌ La tabla ai_config no existe. Ejecuta primero el script SQL.\n";
        exit(1);
    }
    
    $queueCheck = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
    if ($queueCheck->rowCount() === 0) {
        echo "❌ La tabla ai_transcription_queue no existe. Ejecuta primero el script SQL.\n";
        exit(1);
    }
    
    // Verificar si la transcripción automática está activada
    $configStmt = $db->prepare("SELECT auto_transcribe_enabled FROM ai_config WHERE id = 1");
    $configStmt->execute();
    $config = $configStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config || !isset($config['auto_transcribe_enabled']) || !$config['auto_transcribe_enabled']) {
        echo "⚠️  La transcripción automática no está activada en la configuración.\n";
        echo "   Actívala en configuracion.html → AI Informes → Transcripción Automática\n";
        exit(1);
    }
    
    echo "🔍 Buscando audios sin transcripción...\n\n";
    
    // Buscar audios activos que no tienen transcripción completada y no están en la cola
    $query = "
        SELECT 
            ai.id as audio_id,
            ai.estudio_id as orthanc_study_id,
            ai.informe_id,
            ai.nombre_archivo,
            ai.fecha_creacion
        FROM audios_informe ai
        WHERE ai.activo = 1
        AND ai.id NOT IN (
            SELECT DISTINCT audio_id 
            FROM ai_transcriptions 
            WHERE status = 'completed'
        )
        AND ai.id NOT IN (
            SELECT DISTINCT audio_id 
            FROM ai_transcription_queue 
            WHERE status IN ('pending', 'processing')
        )
        ORDER BY ai.fecha_creacion DESC
    ";
    
    $stmt = $db->query($query);
    $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($audios)) {
        echo "✅ No hay audios pendientes de agregar a la cola.\n";
        exit(0);
    }
    
    echo "📋 Encontrados " . count($audios) . " audio(s) sin transcripción:\n\n";
    
    $added = 0;
    $skipped = 0;
    
    foreach ($audios as $audio) {
        $audioId = $audio['audio_id'];
        $orthancStudyId = $audio['orthanc_study_id'];
        
        // Obtener study_id si está disponible
        $studyId = null;
        if ($orthancStudyId) {
            $studyStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
            $studyStmt->execute([$orthancStudyId]);
            $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
            if ($study) {
                $studyId = $study['id'];
            }
        }
        
        // Si no hay study_id, intentar obtenerlo del informe
        if (!$studyId && $audio['informe_id']) {
            $informeStmt = $db->prepare("SELECT estudio_id FROM informes WHERE id = ?");
            $informeStmt->execute([$audio['informe_id']]);
            $informe = $informeStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($informe && $informe['estudio_id']) {
                $studyStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                $studyStmt->execute([$informe['estudio_id']]);
                $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
                if ($study) {
                    $studyId = $study['id'];
                }
            }
        }
        
        try {
            // Agregar a la cola
            $insertStmt = $db->prepare("
                INSERT INTO ai_transcription_queue 
                (audio_id, study_id, orthanc_study_id, status, priority, created_at)
                VALUES (?, ?, ?, 'pending', 0, NOW())
            ");
            $insertStmt->execute([$audioId, $studyId, $orthancStudyId]);
            
            echo "   ✅ Audio ID {$audioId} ({$audio['nombre_archivo']}) agregado a la cola\n";
            $added++;
        } catch (Exception $e) {
            echo "   ❌ Error agregando Audio ID {$audioId}: " . $e->getMessage() . "\n";
            $skipped++;
        }
    }
    
    echo "\n📊 Resumen:\n";
    echo "   • Agregados: {$added}\n";
    echo "   • Omitidos: {$skipped}\n";
    echo "\n✅ Proceso completado. Los audios se procesarán automáticamente por el worker.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
