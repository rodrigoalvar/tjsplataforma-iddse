<?php
/**
 * Script de actualización para agregar columnas audio_id a tablas AI
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Uso: php update-ai-audio-relations.php
 */

require_once __DIR__ . '/config/database.php';

/**
 * Asegurar que las tablas AI existen
 */
function ensureAiTables($db) {
    // Cargar y ejecutar SQL de creación de tablas
    $sqlFile = __DIR__ . '/database/create_ai_tables.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Crear tablas sin foreign keys primero
    $createAiTranscriptions = "
        CREATE TABLE IF NOT EXISTS `ai_transcriptions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `study_id` INT NOT NULL,
          `audio_id` INT NULL,
          `audio_file_path` VARCHAR(500) NOT NULL,
          `transcription_text` TEXT,
          `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
          `error_message` TEXT,
          `model_used` VARCHAR(50) DEFAULT 'whisper',
          `processing_time` DECIMAL(10,2),
          `created_by` INT,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_study_id` (`study_id`),
          INDEX `idx_audio_id` (`audio_id`),
          INDEX `idx_status` (`status`),
          INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $createAiReports = "
        CREATE TABLE IF NOT EXISTS `ai_reports` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `study_id` INT NOT NULL,
          `audio_id` INT NULL,
          `transcription_id` INT,
          `template_id` INT,
          `report_content` TEXT NOT NULL,
          `status` ENUM('draft', 'completed', 'approved', 'rejected') DEFAULT 'draft',
          `model_used` VARCHAR(50) DEFAULT 'medgemma',
          `prompt_used` TEXT,
          `processing_time` DECIMAL(10,2),
          `created_by` INT,
          `approved_by` INT,
          `approved_at` TIMESTAMP NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_study_id` (`study_id`),
          INDEX `idx_audio_id` (`audio_id`),
          INDEX `idx_transcription_id` (`transcription_id`),
          INDEX `idx_template_id` (`template_id`),
          INDEX `idx_status` (`status`),
          INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $createAiConfig = "
        CREATE TABLE IF NOT EXISTS `ai_config` (
          `id` INT PRIMARY KEY DEFAULT 1,
          `ollama_base_url` VARCHAR(255) DEFAULT 'http://localhost:11434',
          `whisper_api_url` VARCHAR(255) DEFAULT 'http://localhost:8080',
          `whisper_model` VARCHAR(100) DEFAULT 'base',
          `whisper_language` VARCHAR(10) DEFAULT 'es',
          `whisper_timeout` INT DEFAULT 600,
          `ffmpeg_rest_url` VARCHAR(255) DEFAULT NULL,
          `medgemma_model` VARCHAR(100) DEFAULT 'medgemma',
          `timeout` INT DEFAULT 300,
          `max_audio_size_mb` INT DEFAULT 25,
          `default_prompt` TEXT,
          `enabled` TINYINT(1) DEFAULT 1,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    // Ejecutar CREATE TABLE statements
    try {
        $db->exec($createAiTranscriptions);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
    }
    
    try {
        $db->exec($createAiReports);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
    }
    
    try {
        $db->exec($createAiConfig);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
    }
    
    // Insertar configuración por defecto si no existe
    try {
        $db->exec("
            INSERT INTO `ai_config` (`id`, `ollama_base_url`, `whisper_model`, `medgemma_model`, `timeout`, `max_audio_size_mb`, `default_prompt`, `enabled`)
            VALUES (1, 'http://localhost:11434', 'base', 'medgemma', 300, 25, 'ROL: Radiólogo. PACIENTE: {patient} ESTUDIO: {study} TRANSCRIPCIÓN: {transcription} PLANTILLA: {template}\nInforme médico completo listo para firmar.', 1)
            ON DUPLICATE KEY UPDATE id = id
        ");
    } catch (PDOException $e) {
        // Ignorar errores de INSERT duplicado
    }
}

echo "=== Actualización de Relaciones AI-Audio ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    echo "1. Verificando y creando tablas AI si no existen...\n";
    
    // Asegurar que las tablas existan primero
    ensureAiTables($db);
    echo "✓ Tablas AI verificadas/creadas\n";
    
    echo "\n2. Verificando tabla ai_transcriptions...\n";
    
    // Verificar si la tabla existe
    $checkTable = $db->query("SHOW TABLES LIKE 'ai_transcriptions'");
    if ($checkTable->rowCount() === 0) {
        throw new Exception('La tabla ai_transcriptions no existe y no se pudo crear');
    }
    
    // Verificar si la columna audio_id ya existe en ai_transcriptions
    $checkColumn = $db->query("SHOW COLUMNS FROM ai_transcriptions LIKE 'audio_id'");
    if ($checkColumn->rowCount() > 0) {
        echo "✓ La columna audio_id ya existe en ai_transcriptions\n";
    } else {
        echo "⚠ La columna audio_id no existe. Agregándola...\n";
        try {
            $db->exec("ALTER TABLE ai_transcriptions ADD COLUMN audio_id INT NULL AFTER study_id");
            $db->exec("ALTER TABLE ai_transcriptions ADD INDEX idx_audio_id (audio_id)");
            echo "✓ Columna audio_id agregada a ai_transcriptions\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⚠ La columna ya existe (puede haber sido agregada por otro proceso)\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n3. Verificando tabla ai_reports...\n";
    
    // Verificar si la columna audio_id ya existe en ai_reports
    $checkColumn2 = $db->query("SHOW COLUMNS FROM ai_reports LIKE 'audio_id'");
    if ($checkColumn2->rowCount() > 0) {
        echo "✓ La columna audio_id ya existe en ai_reports\n";
    } else {
        echo "⚠ La columna audio_id no existe. Agregándola...\n";
        try {
            $db->exec("ALTER TABLE ai_reports ADD COLUMN audio_id INT NULL AFTER study_id");
            $db->exec("ALTER TABLE ai_reports ADD INDEX idx_audio_id (audio_id)");
            echo "✓ Columna audio_id agregada a ai_reports\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⚠ La columna ya existe (puede haber sido agregada por otro proceso)\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n4. Verificando estructura final...\n";
    
    // Verificar estructura de ai_transcriptions
    $stmt = $db->query("SHOW COLUMNS FROM ai_transcriptions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasAudioId = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'audio_id') {
            $hasAudioId = true;
            break;
        }
    }
    
    if ($hasAudioId) {
        echo "✓ ai_transcriptions tiene columna audio_id\n";
    } else {
        echo "⚠ ai_transcriptions NO tiene columna audio_id\n";
    }
    
    // Verificar estructura de ai_reports
    $stmt2 = $db->query("SHOW COLUMNS FROM ai_reports");
    $columns2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    $hasAudioId2 = false;
    foreach ($columns2 as $col) {
        if ($col['Field'] === 'audio_id') {
            $hasAudioId2 = true;
            break;
        }
    }
    
    if ($hasAudioId2) {
        echo "✓ ai_reports tiene columna audio_id\n";
    } else {
        echo "⚠ ai_reports NO tiene columna audio_id\n";
    }
    
    echo "\n=== Actualización completada exitosamente ===\n";
    echo "\nPróximos pasos:\n";
    echo "1. Los endpoints API ahora pueden vincular transcripciones e informes con audios\n";
    echo "2. La interfaz mostrará audios agrupados por estudio\n";
    echo "3. Se podrán transcribir y generar informes desde los audios existentes\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "\nDetalles del error:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
