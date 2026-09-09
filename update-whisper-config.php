<?php
/**
 * Script de actualización para agregar campos de whisper.cpp a ai_config
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Uso: php update-whisper-config.php
 */

require_once __DIR__ . '/config/database.php';

echo "=== Actualización de Configuración de Whisper.cpp ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    echo "1. Verificando tabla ai_config...\n";
    
    // Verificar que la tabla existe
    $checkTable = $db->query("SHOW TABLES LIKE 'ai_config'");
    if ($checkTable->rowCount() === 0) {
        echo "⚠ La tabla ai_config no existe. Creándola...\n";
        // Crear tabla básica si no existe
        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_config (
                id INT PRIMARY KEY DEFAULT 1,
                ollama_base_url VARCHAR(255) DEFAULT 'http://localhost:11434',
                medgemma_model VARCHAR(100) DEFAULT 'medgemma',
                timeout INT DEFAULT 300,
                max_audio_size_mb INT DEFAULT 25,
                enabled TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Insertar registro por defecto
        $db->exec("
            INSERT INTO ai_config (id) VALUES (1)
            ON DUPLICATE KEY UPDATE id = id
        ");
        echo "✓ Tabla ai_config creada\n\n";
    } else {
        echo "✓ Tabla ai_config existe\n\n";
    }
    
    echo "2. Agregando columnas de whisper.cpp...\n";
    
    // Columnas a agregar
    $columns = [
        'whisper_api_url' => [
            'type' => "VARCHAR(255) DEFAULT 'http://localhost:8080'",
            'after' => 'ollama_base_url',
            'description' => 'URL API de whisper.cpp'
        ],
        'whisper_model' => [
            'type' => "VARCHAR(100) DEFAULT 'base'",
            'after' => 'whisper_api_url',
            'description' => 'Modelo de whisper.cpp'
        ],
        'whisper_language' => [
            'type' => "VARCHAR(10) DEFAULT 'es'",
            'after' => 'whisper_model',
            'description' => 'Idioma para transcripción'
        ],
        'whisper_timeout' => [
            'type' => 'INT DEFAULT 300',
            'after' => 'whisper_language',
            'description' => 'Timeout para transcripciones'
        ]
    ];
    
    $added = 0;
    $skipped = 0;
    
    foreach ($columns as $colName => $colDef) {
        // Verificar si la columna ya existe
        $check = $db->query("
            SELECT COUNT(*) as cnt 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'ai_config' 
            AND COLUMN_NAME = '$colName'
        ");
        $exists = $check->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
        
        if (!$exists) {
            try {
                $afterClause = isset($colDef['after']) ? " AFTER {$colDef['after']}" : '';
                $sql = "ALTER TABLE ai_config ADD COLUMN $colName {$colDef['type']}$afterClause";
                $db->exec($sql);
                echo "  ✓ Columna '$colName' agregada ({$colDef['description']})\n";
                $added++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column') === false) {
                    echo "  ⚠ Error agregando '$colName': " . $e->getMessage() . "\n";
                } else {
                    echo "  ✓ Columna '$colName' ya existe\n";
                    $skipped++;
                }
            }
        } else {
            echo "  ✓ Columna '$colName' ya existe\n";
            $skipped++;
        }
    }
    
    echo "\n3. Actualizando valores por defecto...\n";
    
    // Actualizar valores por defecto si no existen
    try {
        $updateSql = "
            UPDATE ai_config 
            SET 
                whisper_api_url = COALESCE(whisper_api_url, 'http://localhost:8080'),
                whisper_model = COALESCE(whisper_model, 'base'),
                whisper_language = COALESCE(whisper_language, 'es'),
                whisper_timeout = COALESCE(whisper_timeout, 300)
            WHERE id = 1
        ";
        $db->exec($updateSql);
        echo "  ✓ Valores por defecto actualizados\n";
    } catch (PDOException $e) {
        echo "  ⚠ Error actualizando valores: " . $e->getMessage() . "\n";
    }
    
    echo "\n4. Verificando configuración actual...\n";
    
    $stmt = $db->prepare("SELECT * FROM ai_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config) {
        echo "  ✓ Configuración encontrada:\n";
        echo "    - URL Whisper.cpp: " . ($config['whisper_api_url'] ?? 'No configurado') . "\n";
        echo "    - Modelo Whisper: " . ($config['whisper_model'] ?? 'No configurado') . "\n";
        echo "    - Idioma Whisper: " . ($config['whisper_language'] ?? 'No configurado') . "\n";
        echo "    - Timeout Whisper: " . ($config['whisper_timeout'] ?? 'No configurado') . " segundos\n";
        echo "    - URL Ollama: " . ($config['ollama_base_url'] ?? 'No configurado') . "\n";
        echo "    - Modelo Medgemma: " . ($config['medgemma_model'] ?? 'No configurado') . "\n";
    } else {
        echo "  ⚠ No se encontró configuración. Se creará al guardar desde la interfaz.\n";
    }
    
    echo "\n=== Resumen ===\n";
    echo "  Columnas agregadas: $added\n";
    echo "  Columnas existentes: $skipped\n";
    echo "\n✅ Actualización completada exitosamente\n";
    echo "\nPróximos pasos:\n";
    echo "  1. Configura la URL de whisper.cpp en: Configuración → AI Informes\n";
    echo "  2. Configura la URL de Ollama para Medgemma\n";
    echo "  3. Selecciona los modelos apropiados\n";
    echo "  4. Prueba las conexiones usando los botones de prueba\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
