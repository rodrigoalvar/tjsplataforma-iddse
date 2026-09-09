<?php
/**
 * Script para corregir datos de audio y crear datos de prueba
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "<h2>Corrigiendo datos de audio</h2>";
    
    // 1. Verificar estructura de tablas
    echo "<h3>1. Verificando estructura de tablas...</h3>";
    
    // Verificar si existe la tabla audios_informe
    $tables = $db->query("SHOW TABLES LIKE 'audios_informe'")->fetchAll();
    if (empty($tables)) {
        echo "<p style='color: red;'>❌ Tabla audios_informe no existe</p>";
        
        // Crear tabla si no existe
        $createTable = "
        CREATE TABLE IF NOT EXISTS `audios_informe` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `informe_id` int(11) NOT NULL,
            `estudio_id` varchar(255) DEFAULT NULL,
            `nombre_archivo` varchar(255) NOT NULL,
            `ruta_archivo` varchar(500) DEFAULT NULL,
            `nombre_original` varchar(255) DEFAULT NULL,
            `duracion_segundos` int(11) DEFAULT NULL,
            `tamano_bytes` bigint(20) DEFAULT NULL,
            `tipo_grabacion` varchar(50) DEFAULT 'manual',
            `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `transcripcion_texto` text,
            `activo` tinyint(1) DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_informe_id` (`informe_id`),
            KEY `idx_estudio_id` (`estudio_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        $db->exec($createTable);
        echo "<p style='color: green;'>✅ Tabla audios_informe creada</p>";
    } else {
        echo "<p style='color: green;'>✅ Tabla audios_informe existe</p>";
    }
    
    // 2. Verificar si hay informes
    $informes = $db->query("SELECT COUNT(*) as count FROM informes")->fetch();
    if ($informes['count'] == 0) {
        echo "<p style='color: orange;'>⚠️ No hay informes, creando datos de prueba...</p>";
        
        // Crear informe de prueba
        $insertInforme = "
        INSERT INTO informes (estudio_id, patient_id, patient_name, titulo, contenido, version, fecha_creacion) 
        VALUES ('EST001', 'PAT001', 'Paciente de Prueba', 'Informe de Prueba', 'Contenido del informe de prueba', 1, NOW())
        ";
        $db->exec($insertInforme);
        $informeId = $db->lastInsertId();
        echo "<p style='color: green;'>✅ Informe de prueba creado con ID: $informeId</p>";
    } else {
        echo "<p style='color: green;'>✅ Encontrados {$informes['count']} informes</p>";
        // Usar el primer informe disponible
        $informe = $db->query("SELECT * FROM informes ORDER BY id ASC LIMIT 1")->fetch();
        $informeId = $informe['id'];
        echo "<p>Usando informe ID: $informeId</p>";
    }
    
    // 3. Verificar archivos físicos y crear registros
    echo "<h3>3. Procesando archivos físicos...</h3>";
    
    $audioDir = 'uploads/audios/';
    if (is_dir($audioDir)) {
        $files = scandir($audioDir);
        $audioFiles = array_filter($files, function($file) use ($audioDir) {
            return !in_array($file, ['.', '..']) && is_file($audioDir . $file);
        });
        
        foreach ($audioFiles as $file) {
            $filePath = $audioDir . $file;
            $fileSize = filesize($filePath);
            
            // Verificar si ya existe en la base de datos
            $existing = $db->prepare("SELECT id FROM audios_informe WHERE nombre_archivo = ?");
            $existing->execute([$file]);
            
            if (!$existing->fetch()) {
                // Insertar nuevo registro
                $insertAudio = "
                INSERT INTO audios_informe 
                (informe_id, estudio_id, nombre_archivo, ruta_archivo, nombre_original, tamano_bytes, tipo_grabacion, activo) 
                VALUES (?, 'EST001', ?, ?, ?, ?, 'manual', 1)
                ";
                
                $stmt = $db->prepare($insertAudio);
                $stmt->execute([
                    $informeId,
                    $file,
                    'uploads/audios/' . $file,
                    $file,
                    $fileSize
                ]);
                
                echo "<p style='color: green;'>✅ Registro creado para: $file</p>";
            } else {
                echo "<p style='color: blue;'>ℹ️ Ya existe registro para: $file</p>";
            }
        }
    }
    
    // 4. Verificar resultado final
    echo "<h3>4. Verificación final...</h3>";
    
    $finalCheck = $db->query("
        SELECT ai.*, i.titulo, i.patient_name 
        FROM audios_informe ai 
        LEFT JOIN informes i ON ai.informe_id = i.id 
        WHERE ai.activo = 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($finalCheck)) {
        echo "<p style='color: red;'>❌ Aún no hay audios vinculados</p>";
    } else {
        echo "<p style='color: green;'>✅ Encontrados " . count($finalCheck) . " audios vinculados:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID Audio</th><th>Informe</th><th>Paciente</th><th>Archivo</th><th>Tamaño</th></tr>";
        foreach ($finalCheck as $audio) {
            echo "<tr>";
            echo "<td>" . $audio['id'] . "</td>";
            echo "<td>" . htmlspecialchars($audio['titulo']) . "</td>";
            echo "<td>" . htmlspecialchars($audio['patient_name']) . "</td>";
            echo "<td>" . $audio['nombre_archivo'] . "</td>";
            echo "<td>" . number_format($audio['tamano_bytes']) . " bytes</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr><h3>✅ Proceso completado</h3>";
    echo "<p><a href='components/informes-manager.html'>Probar modal de audio</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>