<?php
/**
 * Script de instalación para AI Informes
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script:
 * 1. Crea las tablas necesarias
 * 2. Agrega los permisos al sistema
 * 3. Verifica la configuración
 */

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    echo "<h2>Instalación de AI Informes</h2>";
    echo "<div style='font-family: monospace; padding: 20px;'>";
    
    // 1. Crear tablas
    echo "<h3>1. Creando tablas...</h3>";
    $sqlFile = __DIR__ . '/database/create_ai_tables.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        // Ejecutar cada sentencia SQL
        $statements = explode(';', $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !preg_match('/^(USE|--)/i', $statement)) {
                try {
                    $db->exec($statement);
                    echo "✓ Tabla creada/verificada<br>";
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        echo "⚠ Error: " . $e->getMessage() . "<br>";
                    } else {
                        echo "✓ Tabla ya existe<br>";
                    }
                }
            }
        }
    } else {
        echo "⚠ Archivo SQL no encontrado: $sqlFile<br>";
    }
    
    // 2. Agregar permisos
    echo "<h3>2. Agregando permisos...</h3>";
    
    // Agregar permisos directamente (más confiable que parsear SQL)
    try {
        // Permiso GUI
        $stmt = $db->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category) 
            VALUES 
            ('gui_ai_informes', 'AI Informes Visible', 'Controla la visibilidad y estado activo del acceso AI Informes en el sidebar', 'interfaz')
            ON DUPLICATE KEY UPDATE 
                permission_name = VALUES(permission_name),
                description = VALUES(description),
                category = VALUES(category)
        ");
        $stmt->execute();
        echo "✓ Permiso gui_ai_informes agregado/actualizado<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "✓ Permiso gui_ai_informes ya existe<br>";
        } else {
            echo "⚠ Error agregando gui_ai_informes: " . $e->getMessage() . "<br>";
        }
    }
    
    try {
        // Permiso de acceso
        $stmt = $db->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category) 
            VALUES 
            ('ai_informes', 'Acceso a AI Informes', 'Permite acceder y usar la sección de AI Informes (Whisper + Medgemma)', 'informes')
            ON DUPLICATE KEY UPDATE 
                permission_name = VALUES(permission_name),
                description = VALUES(description),
                category = VALUES(category)
        ");
        $stmt->execute();
        echo "✓ Permiso ai_informes agregado/actualizado<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "✓ Permiso ai_informes ya existe<br>";
        } else {
            echo "⚠ Error agregando ai_informes: " . $e->getMessage() . "<br>";
        }
    }
    
    // 3. Verificar permisos
    echo "<h3>3. Verificando permisos...</h3>";
    
    // Verificar base de datos actual
    $stmt = $db->query("SELECT DATABASE() as db_name");
    $dbInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Base de datos actual: <strong>" . ($dbInfo['db_name'] ?? 'No detectada') . "</strong><br><br>";
    
    $stmt = $db->prepare("SELECT permission_key, permission_name, category FROM system_permissions WHERE permission_key IN ('gui_ai_informes', 'ai_informes') ORDER BY permission_key");
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($permissions) === 2) {
        echo "✓ Permisos encontrados:<br>";
        foreach ($permissions as $perm) {
            echo "  - <strong>{$perm['permission_key']}</strong>: {$perm['permission_name']} ({$perm['category']})<br>";
        }
    } else {
        echo "⚠ Solo se encontraron " . count($permissions) . " permisos (esperados 2)<br>";
        if (count($permissions) > 0) {
            echo "Permisos encontrados:<br>";
            foreach ($permissions as $perm) {
                echo "  - {$perm['permission_key']}: {$perm['permission_name']}<br>";
            }
        } else {
            echo "⚠ No se encontraron permisos. Intentando agregarlos nuevamente...<br>";
            // Intentar agregar nuevamente
            try {
                $stmt = $db->prepare("
                    INSERT IGNORE INTO system_permissions (permission_key, permission_name, description, category) 
                    VALUES 
                    ('gui_ai_informes', 'AI Informes Visible', 'Controla la visibilidad y estado activo del acceso AI Informes en el sidebar', 'interfaz'),
                    ('ai_informes', 'Acceso a AI Informes', 'Permite acceder y usar la sección de AI Informes (Whisper + Medgemma)', 'informes')
                ");
                $stmt->execute();
                echo "✓ Permisos agregados con INSERT IGNORE<br>";
                
                // Verificar nuevamente
                $stmt = $db->prepare("SELECT permission_key, permission_name, category FROM system_permissions WHERE permission_key IN ('gui_ai_informes', 'ai_informes')");
                $stmt->execute();
                $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($permissions) === 2) {
                    echo "✓ Permisos verificados correctamente después del re-intento<br>";
                }
            } catch (PDOException $e) {
                echo "⚠ Error al re-agregar permisos: " . $e->getMessage() . "<br>";
            }
        }
    }
    
    // 4. Verificar configuración
    echo "<h3>4. Verificando configuración...</h3>";
    $stmt = $db->prepare("SELECT * FROM ai_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config) {
        echo "✓ Configuración encontrada:<br>";
        echo "  - URL Ollama: {$config['ollama_base_url']}<br>";
        echo "  - Modelo Whisper: {$config['whisper_model']}<br>";
        echo "  - Modelo Medgemma: {$config['medgemma_model']}<br>";
    } else {
        echo "⚠ Configuración no encontrada, se creará con valores por defecto<br>";
        $stmt = $db->prepare("
            INSERT INTO ai_config (id, ollama_base_url, whisper_model, medgemma_model, timeout, max_audio_size_mb, enabled)
            VALUES (1, 'http://localhost:11434', 'whisper', 'medgemma', 300, 25, 1)
        ");
        $stmt->execute();
        echo "✓ Configuración creada<br>";
    }
    
    echo "<h3>✅ Instalación completada</h3>";
    echo "<p><strong>Próximos pasos:</strong></p>";
    echo "<ol>";
    echo "<li>Asignar el permiso <code>gui_ai_informes</code> a los usuarios que necesiten ver el enlace en el sidebar</li>";
    echo "<li>Asignar el permiso <code>ai_informes</code> a los usuarios que necesiten usar la funcionalidad</li>";
    echo "<li>Configurar Ollama en la pestaña 'AI Informes' de Configuración</li>";
    echo "<li>Verificar que Ollama esté corriendo y los modelos estén instalados</li>";
    echo "</ol>";
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red; padding: 20px;'>";
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
