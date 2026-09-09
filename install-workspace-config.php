<?php
/**
 * Script de instalación para el sistema de configuración del workspace por usuario
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script crea la tabla necesaria para guardar las configuraciones del workspace
 * por usuario en la base de datos.
 */

// Configuración
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

// Intentar obtener configuración desde database.php si existe
if (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    try {
        $pdo = getDBConnection();
        // Si getDBConnection() funciona, usar esos valores
        $host = null; // Ya tenemos conexión
    } catch (Exception $e) {
        // Si falla, usar valores por defecto
    }
}

echo "========================================\n";
echo "INSTALACIÓN DE CONFIGURACIÓN WORKSPACE\n";
echo "========================================\n\n";

try {
    // Conectar a la base de datos
    if (!isset($pdo)) {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    echo "✅ Conexión a la base de datos establecida\n\n";
    
    // Verificar si la tabla ya existe
    $checkTable = $pdo->query("SHOW TABLES LIKE 'workspace_config'");
    $tableExists = $checkTable->rowCount() > 0;
    
    if ($tableExists) {
        echo "⚠️  La tabla 'workspace_config' ya existe\n";
        echo "   ¿Desea recrearla? Esto eliminará todos los datos existentes.\n";
        echo "   (Para recrear, edite el script y establezca \$recreateTable = true)\n\n";
        
        $recreateTable = false; // Cambiar a true para recrear la tabla
        
        if ($recreateTable) {
            echo "🗑️  Eliminando tabla existente...\n";
            $pdo->exec("DROP TABLE IF EXISTS workspace_config");
            echo "✅ Tabla eliminada\n\n";
        } else {
            echo "ℹ️  Verificando estructura de la tabla existente...\n";
            
            // Verificar columnas
            $columns = $pdo->query("SHOW COLUMNS FROM workspace_config")->fetchAll(PDO::FETCH_ASSOC);
            $requiredColumns = ['id', 'user_id', 'config_data', 'created_at', 'updated_at'];
            $existingColumns = array_column($columns, 'Field');
            
            $missingColumns = array_diff($requiredColumns, $existingColumns);
            
            if (empty($missingColumns)) {
                echo "✅ La tabla tiene todas las columnas necesarias\n";
                
                // Verificar índices
                $indexes = $pdo->query("SHOW INDEXES FROM workspace_config")->fetchAll(PDO::FETCH_ASSOC);
                $hasUniqueUser = false;
                foreach ($indexes as $index) {
                    if ($index['Key_name'] === 'unique_user_workspace') {
                        $hasUniqueUser = true;
                        break;
                    }
                }
                
                if ($hasUniqueUser) {
                    echo "✅ El índice único por usuario existe\n";
                } else {
                    echo "⚠️  El índice único por usuario no existe, agregándolo...\n";
                    $pdo->exec("ALTER TABLE workspace_config ADD UNIQUE KEY unique_user_workspace (user_id)");
                    echo "✅ Índice agregado\n";
                }
                
                // Contar registros
                $count = $pdo->query("SELECT COUNT(*) as total FROM workspace_config")->fetch(PDO::FETCH_ASSOC);
                echo "📊 Configuraciones guardadas: {$count['total']}\n\n";
                
                echo "✅ La tabla está correctamente configurada\n";
                echo "   No se realizaron cambios\n\n";
            } else {
                echo "❌ Faltan columnas: " . implode(', ', $missingColumns) . "\n";
                echo "   Por favor, elimine la tabla manualmente y ejecute este script nuevamente\n";
                exit(1);
            }
        }
    }
    
    // Crear la tabla si no existe
    if (!$tableExists || (isset($recreateTable) && $recreateTable)) {
        echo "🔨 Creando tabla 'workspace_config'...\n";
        
        $createTableSQL = "
        CREATE TABLE IF NOT EXISTS workspace_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            config_data JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_workspace (user_id),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena la configuración del workspace (layout de paneles) por usuario'
        ";
        
        $pdo->exec($createTableSQL);
        echo "✅ Tabla 'workspace_config' creada correctamente\n\n";
    }
    
    // Verificar estructura final
    echo "📋 Verificando estructura de la tabla...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM workspace_config")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nColumnas de la tabla:\n";
    foreach ($columns as $column) {
        $null = $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $column['Default'] !== null ? " DEFAULT '{$column['Default']}'" : '';
        echo "   • {$column['Field']} ({$column['Type']}) {$null}{$default}\n";
    }
    
    // Verificar índices
    echo "\nÍndices:\n";
    $indexes = $pdo->query("SHOW INDEXES FROM workspace_config")->fetchAll(PDO::FETCH_ASSOC);
    $indexGroups = [];
    foreach ($indexes as $index) {
        $keyName = $index['Key_name'];
        if (!isset($indexGroups[$keyName])) {
            $indexGroups[$keyName] = [];
        }
        $indexGroups[$keyName][] = $index['Column_name'];
    }
    
    foreach ($indexGroups as $keyName => $columns) {
        $type = $keyName === 'PRIMARY' ? 'PRIMARY KEY' : ($keyName === 'unique_user_workspace' ? 'UNIQUE KEY' : 'INDEX');
        echo "   • {$keyName} ({$type}): " . implode(', ', $columns) . "\n";
    }
    
    // Verificar foreign keys
    echo "\nForeign Keys:\n";
    $fks = $pdo->query("
        SELECT 
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'workspace_config'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($fks)) {
        echo "   ⚠️  No se encontraron foreign keys (puede ser normal si la tabla usuarios no existe aún)\n";
    } else {
        foreach ($fks as $fk) {
            echo "   • {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
        }
    }
    
    // Verificar que la tabla usuarios existe (para el foreign key)
    $checkUsuarios = $pdo->query("SHOW TABLES LIKE 'usuarios'");
    if ($checkUsuarios->rowCount() === 0) {
        echo "\n⚠️  ADVERTENCIA: La tabla 'usuarios' no existe.\n";
        echo "   El foreign key no se creará hasta que exista la tabla 'usuarios'.\n";
        echo "   Puede ejecutar este script nuevamente después de crear la tabla 'usuarios'.\n";
    }
    
    echo "\n========================================\n";
    echo "✅ INSTALACIÓN COMPLETADA EXITOSAMENTE\n";
    echo "========================================\n\n";
    
    echo "📝 Resumen:\n";
    echo "   • Tabla 'workspace_config' creada/verificada\n";
    echo "   • Sistema listo para guardar configuraciones por usuario\n";
    echo "   • Cada usuario podrá tener su propia configuración del workspace\n\n";
    
    echo "🔗 Endpoints API disponibles:\n";
    echo "   • POST /api/workspace/save-config.php - Guardar configuración\n";
    echo "   • GET  /api/workspace/load-config.php  - Cargar configuración\n\n";
    
    echo "💡 Próximos pasos:\n";
    echo "   1. Verificar que los endpoints API funcionan correctamente\n";
    echo "   2. Probar guardar/cargar configuración desde el workspace\n";
    echo "   3. Verificar que cada usuario tiene su propia configuración\n\n";
    
} catch (PDOException $e) {
    echo "\n❌ ERROR EN LA INSTALACIÓN\n";
    echo "========================================\n\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Código: " . $e->getCode() . "\n\n";
    
    if (strpos($e->getMessage(), 'Access denied') !== false) {
        echo "💡 Solución: Verifique las credenciales de la base de datos\n";
        echo "   Edite este script y ajuste \$username y \$password\n\n";
    } elseif (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "💡 Solución: La base de datos '{$dbname}' no existe\n";
        echo "   Cree la base de datos primero o ajuste \$dbname\n\n";
    } elseif (strpos($e->getMessage(), 'Base table or view not found') !== false) {
        echo "💡 Solución: La tabla 'usuarios' no existe\n";
        echo "   El foreign key se creará cuando exista la tabla 'usuarios'\n\n";
    }
    
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    
    exit(1);
} catch (Exception $e) {
    echo "\n❌ ERROR GENERAL\n";
    echo "========================================\n\n";
    echo "Mensaje: " . $e->getMessage() . "\n\n";
    exit(1);
}
?>
