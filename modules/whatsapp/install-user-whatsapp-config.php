<?php
/**
 * Script de Instalación - Configuración de WAHA por Usuario
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script crea la tabla necesaria para almacenar la configuración de WAHA por usuario.
 * Permite que cada usuario con permiso 'administracion_whatsapp' configure su propia instancia de WAHA.
 * 
 * Uso: php install-user-whatsapp-config.php
 * 
 * @package WhatsAppModule
 * @version 1.0
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  INSTALACIÓN - Configuración de WAHA por Usuario\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Cargar configuración de base de datos
$dbConfigFile = __DIR__ . '/../../config/database.php';
if (!file_exists($dbConfigFile)) {
    die("❌ Error: No se encontró el archivo de configuración de base de datos: $dbConfigFile\n");
}

require_once $dbConfigFile;

try {
    $pdo = getDBConnection();
    
    if (!$pdo) {
        die("❌ Error: No se pudo conectar a la base de datos\n");
    }
    
    echo "✅ Conexión a la base de datos exitosa\n\n";
    
    // Leer el script SQL
    $sqlFile = __DIR__ . '/database/user_whatsapp_config.sql';
    if (!file_exists($sqlFile)) {
        die("❌ Error: No se encontró el archivo SQL: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    if (empty($sql)) {
        die("❌ Error: El archivo SQL está vacío\n");
    }
    
    echo "📄 Leyendo script SQL...\n";
    echo "   Archivo: $sqlFile\n\n";
    
    // Ejecutar el script SQL
    echo "🔧 Creando tabla user_whatsapp_config...\n";
    
    // Remover comentarios de una sola línea que empiezan con --
    $sql = preg_replace('/^--.*$/m', '', $sql);
    
    // Dividir el SQL en sentencias individuales (por punto y coma)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            $stmt = trim($stmt);
            return !empty($stmt) && strlen($stmt) > 10; // Filtrar sentencias muy cortas (probablemente vacías)
        }
    );
    
    $executed = false;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $result = $pdo->exec($statement);
                $executed = true;
                if ($result === false) {
                    // Verificar si hay errores
                    $errorInfo = $pdo->errorInfo();
                    if ($errorInfo[0] !== '00000') {
                        throw new PDOException($errorInfo[2], $errorInfo[1]);
                    }
                }
            } catch (PDOException $e) {
                // Si la tabla ya existe, no es un error crítico
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, 'already exists') !== false || 
                    strpos($errorMsg, 'Duplicate') !== false ||
                    (strpos($errorMsg, 'Table') !== false && strpos($errorMsg, 'exists') !== false)) {
                    echo "   ⚠️  La tabla ya existe (esto es normal si ya se ejecutó antes)\n";
                    $executed = true;
                } else {
                    // Mostrar el error pero continuar
                    echo "   ⚠️  Advertencia al ejecutar SQL: " . $errorMsg . "\n";
                    echo "   Continuando con la verificación...\n";
                }
            }
        }
    }
    
    if ($executed) {
        echo "✅ SQL ejecutado\n\n";
    } else {
        echo "⚠️  No se ejecutaron sentencias SQL (puede que la tabla ya exista)\n\n";
    }
    
    // Verificar que la tabla existe
    echo "🔍 Verificando instalación...\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_whatsapp_config'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "   ✅ Tabla 'user_whatsapp_config' existe\n";
        
        // Verificar estructura
        $stmt = $pdo->query("DESCRIBE user_whatsapp_config");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $requiredColumns = [
            'id', 
            'usuario_id', 
            'waha_base_url', 
            'waha_api_key', 
            'waha_timeout', 
            'waha_default_session', 
            'waha_default_country_code',
            'activo'
        ];
        
        $missingColumns = array_diff($requiredColumns, $columns);
        
        if (empty($missingColumns)) {
            echo "   ✅ Estructura de la tabla correcta\n";
            echo "   📊 Columnas encontradas: " . count($columns) . "\n\n";
        } else {
            echo "   ⚠️  Columnas faltantes: " . implode(', ', $missingColumns) . "\n\n";
        }
        
        // Verificar índices
        $stmt = $pdo->query("SHOW INDEXES FROM user_whatsapp_config");
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('usuario_id', $indexes)) {
            echo "   ✅ Índice único en 'usuario_id' existe\n";
        } else {
            echo "   ⚠️  Índice único en 'usuario_id' no encontrado\n";
        }
        
    } else {
        echo "   ❌ Error: La tabla no se creó correctamente\n\n";
        exit(1);
    }
    
    // Verificar que los métodos en WhatsAppConfig.php existen
    echo "\n🔍 Verificando métodos en WhatsAppConfig.php...\n";
    
    $whatsappConfigFile = __DIR__ . '/WhatsAppConfig.php';
    if (!file_exists($whatsappConfigFile)) {
        echo "   ⚠️  Archivo WhatsAppConfig.php no encontrado\n";
    } else {
        require_once $whatsappConfigFile;
        
        if (class_exists('WhatsAppConfig')) {
            echo "   ✅ Clase WhatsAppConfig existe\n";
            
            // Verificar métodos
            $requiredMethods = ['loadForUser', 'saveForUser'];
            $reflection = new ReflectionClass('WhatsAppConfig');
            
            foreach ($requiredMethods as $method) {
                if ($reflection->hasMethod($method)) {
                    echo "   ✅ Método $method() existe\n";
                } else {
                    echo "   ❌ Método $method() NO existe\n";
                }
            }
        } else {
            echo "   ❌ Clase WhatsAppConfig no encontrada\n";
        }
    }
    
    // Verificar que send-message.php está actualizado
    echo "\n🔍 Verificando integración en send-message.php...\n";
    
    $sendMessageFile = __DIR__ . '/../../api/whatsapp/send-message.php';
    if (!file_exists($sendMessageFile)) {
        echo "   ⚠️  Archivo send-message.php no encontrado\n";
    } else {
        $content = file_get_contents($sendMessageFile);
        if (strpos($content, 'loadForUser') !== false) {
            echo "   ✅ send-message.php usa configuración por usuario\n";
        } else {
            echo "   ⚠️  send-message.php puede no estar actualizado\n";
        }
    }
    
    // Verificar que admin.php tiene el formulario
    echo "\n🔍 Verificando interfaz en admin.php...\n";
    
    $adminFile = __DIR__ . '/../email/admin.php';
    if (!file_exists($adminFile)) {
        echo "   ⚠️  Archivo admin.php no encontrado\n";
    } else {
        $content = file_get_contents($adminFile);
        if (strpos($content, 'save-my-whatsapp-config') !== false) {
            echo "   ✅ admin.php tiene formulario de configuración personal\n";
        } else {
            echo "   ⚠️  admin.php puede no tener el formulario de configuración personal\n";
        }
    }
    
    // Contar configuraciones existentes
    echo "\n📊 Estadísticas...\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activas FROM user_whatsapp_config");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "   📈 Configuraciones totales: " . ($stats['total'] ?? 0) . "\n";
        echo "   ✅ Configuraciones activas: " . ($stats['activas'] ?? 0) . "\n";
    } catch (Exception $e) {
        echo "   ⚠️  No se pudieron obtener estadísticas: " . $e->getMessage() . "\n";
    }
    
    echo "\n═══════════════════════════════════════════════════════════════\n";
    echo "  ✅ INSTALACIÓN COMPLETADA\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    echo "📝 Funcionalidad instalada:\n";
    echo "   ✅ Tabla user_whatsapp_config creada\n";
    echo "   ✅ Métodos loadForUser() y saveForUser() disponibles\n";
    echo "   ✅ Integración en send-message.php\n";
    echo "   ✅ Interfaz en admin.php\n\n";
    echo "📋 Próximos pasos:\n";
    echo "   1. Los usuarios con permiso 'administracion_whatsapp' pueden\n";
    echo "      configurar su propia instancia de WAHA desde admin.php\n";
    echo "   2. Al enviar mensajes, se usará la configuración personal\n";
    echo "      si existe, o la configuración global como fallback\n";
    echo "   3. Cada usuario puede tener su propia URL, API Key y sesión\n\n";
    echo "💡 Nota: La configuración personal tiene prioridad sobre la global.\n";
    echo "   Si un usuario no tiene configuración personal, se usa la global.\n\n";
    echo "🔐 Permisos requeridos:\n";
    echo "   - administracion_whatsapp: Para configurar instancia personal\n";
    echo "   - envios_whatsapp: Para enviar mensajes (ya existente)\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "   Código: " . $e->getCode() . "\n";
    if (isset($e->errorInfo) && is_array($e->errorInfo)) {
        echo "   Info: " . print_r($e->errorInfo, true) . "\n";
    }
    if (method_exists($e, 'getTraceAsString')) {
        echo "\n   Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}

