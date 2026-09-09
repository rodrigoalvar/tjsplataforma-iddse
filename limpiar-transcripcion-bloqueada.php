<?php
/**
 * Script para limpiar transcripciones bloqueadas
 * Ejecutar desde navegador: http://plataforma.iddse.com.ar/limpiar-transcripcion-bloqueada.php?audio_id=35
 * O desde línea de comandos: php limpiar-transcripcion-bloqueada.php 35
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

$audioId = isset($_GET['audio_id']) ? intval($_GET['audio_id']) : (isset($argv[1]) ? intval($argv[1]) : null);

if (!$audioId) {
    echo "<h2>Limpiar Transcripción Bloqueada</h2>";
    echo "<p>Uso:</p>";
    echo "<ul>";
    echo "<li>Desde navegador: <code>?audio_id=35</code></li>";
    echo "<li>Desde línea de comandos: <code>php limpiar-transcripcion-bloqueada.php 35</code></li>";
    echo "</ul>";
    exit;
}

try {
    $db = getDBConnection();
    
    echo "<h2>Limpiar Transcripción Bloqueada - Audio ID: $audioId</h2>";
    echo "<pre>";
    
    // Ver transcripciones en proceso
    $checkStmt = $db->prepare("
        SELECT 
            id, 
            audio_id, 
            status, 
            updated_at, 
            TIMESTAMPDIFF(MINUTE, updated_at, NOW()) as minutes_ago,
            error_message,
            created_at
        FROM ai_transcriptions 
        WHERE audio_id = ? AND status = 'processing'
        ORDER BY created_at DESC
    ");
    $checkStmt->execute([$audioId]);
    $blockedTranscriptions = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($blockedTranscriptions)) {
        echo "✅ No hay transcripciones bloqueadas para el audio ID $audioId\n";
        echo "\nTodas las transcripciones para este audio:\n";
        $allStmt = $db->prepare("
            SELECT id, status, updated_at, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) as minutes_ago
            FROM ai_transcriptions 
            WHERE audio_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $allStmt->execute([$audioId]);
        $all = $allStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all as $t) {
            echo "  ID: {$t['id']}, Status: {$t['status']}, Hace: {$t['minutes_ago']} minutos\n";
        }
        exit;
    }
    
    echo "🔍 Transcripciones bloqueadas encontradas:\n\n";
    foreach ($blockedTranscriptions as $t) {
        echo "  ID: {$t['id']}\n";
        echo "  Status: {$t['status']}\n";
        echo "  Última actualización: {$t['updated_at']}\n";
        echo "  Hace: {$t['minutes_ago']} minutos\n";
        echo "  Error: " . ($t['error_message'] ?: 'N/A') . "\n";
        echo "  ---\n";
    }
    
    // Marcar como fallida
    $updateStmt = $db->prepare("
        UPDATE ai_transcriptions 
        SET status = 'failed', 
            error_message = 'Transcripción bloqueada - limpiada manualmente',
            updated_at = NOW()
        WHERE audio_id = ? 
          AND status = 'processing'
    ");
    $updateStmt->execute([$audioId]);
    $updated = $updateStmt->rowCount();
    
    echo "\n✅ Transcripciones limpiadas: $updated\n";
    echo "\nAhora puedes intentar transcribir nuevamente desde AI Informes.\n";
    
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>";
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "</pre>";
}
?>
