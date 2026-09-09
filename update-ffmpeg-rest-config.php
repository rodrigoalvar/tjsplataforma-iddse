<?php
/**
 * Script de actualización para agregar columna ffmpeg_rest_url a ai_config
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Uso: php update-ffmpeg-rest-config.php
 */

require_once __DIR__ . '/config/database.php';

echo "=== Actualización de Configuración de FFmpeg REST ===\n\n";

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
        echo "⚠ La tabla ai_config no existe. Debe ejecutarse primero el script de creación de tablas.\n";
        exit(1);
    } else {
        echo "✓ Tabla ai_config existe\n\n";
    }
    
    echo "2. Verificando columna ffmpeg_rest_url...\n";
    
    // Verificar si la columna ya existe
    $checkColumn = $db->query("SHOW COLUMNS FROM ai_config LIKE 'ffmpeg_rest_url'");
    if ($checkColumn->rowCount() > 0) {
        echo "✓ La columna ffmpeg_rest_url ya existe\n\n";
        
        // Verificar el valor actual
        $stmt = $db->prepare("SELECT ffmpeg_rest_url FROM ai_config WHERE id = 1");
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config) {
            $currentValue = $config['ffmpeg_rest_url'] ?? 'NULL';
            echo "Valor actual de ffmpeg_rest_url: " . ($currentValue ?: 'NULL') . "\n";
        }
        
        echo "\n¿Deseas continuar de todas formas? (s/n): ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($line) !== 's' && strtolower($line) !== 'y') {
            echo "Operación cancelada.\n";
            exit(0);
        }
    } else {
        echo "⚠ La columna ffmpeg_rest_url no existe. Agregándola...\n";
        
        // Agregar la columna
        try {
            $db->exec("ALTER TABLE ai_config ADD COLUMN ffmpeg_rest_url VARCHAR(255) DEFAULT 'http://localhost:3000' AFTER whisper_timeout");
            echo "✓ Columna ffmpeg_rest_url agregada exitosamente\n\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⚠ La columna ya existe (puede haber sido agregada por otro proceso)\n\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "3. Verificando configuración actual...\n";
    $stmt = $db->prepare("SELECT * FROM ai_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config) {
        echo "✓ Configuración encontrada\n";
        echo "  - ffmpeg_rest_url: " . ($config['ffmpeg_rest_url'] ?? 'NULL') . "\n";
        echo "  - whisper_api_url: " . ($config['whisper_api_url'] ?? 'NULL') . "\n";
        echo "  - ollama_base_url: " . ($config['ollama_base_url'] ?? 'NULL') . "\n";
    } else {
        echo "⚠ No se encontró configuración (id=1). Creando registro por defecto...\n";
        
        // Crear registro por defecto
        $stmt = $db->prepare("
            INSERT INTO ai_config (
                id, 
                ollama_base_url, 
                whisper_api_url, 
                whisper_model, 
                whisper_language, 
                whisper_timeout,
                ffmpeg_rest_url,
                medgemma_model, 
                timeout, 
                max_audio_size_mb, 
                enabled
            ) VALUES (
                1,
                'http://localhost:11434',
                'http://localhost:8080',
                'base',
                'es',
                300,
                'http://localhost:3000',
                'medgemma',
                300,
                25,
                1
            )
        ");
        $stmt->execute();
        echo "✓ Registro por defecto creado\n";
    }
    
    echo "\n=== Actualización completada exitosamente ===\n";
    echo "\nPróximos pasos:\n";
    echo "1. Configura ffmpeg-rest en el servidor de Whisper (192.168.0.33)\n";
    echo "2. Ve a Configuración → AI Informes en la aplicación\n";
    echo "3. Ingresa la URL de ffmpeg-rest (ej: http://192.168.0.33:3000)\n";
    echo "4. Haz clic en 'Probar' para verificar la conexión\n";
    echo "5. Guarda la configuración\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "\nDetalles del error:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
