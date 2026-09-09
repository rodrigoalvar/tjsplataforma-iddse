<?php
/**
 * Script de Instalación - Preferencias de Email por Usuario
 * 
 * Este script crea la tabla necesaria para almacenar las preferencias de email por usuario.
 * 
 * Uso: php install-user-email-preferences.php
 * 
 * @package EmailModule
 * @version 1.0
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  INSTALACIÓN - Preferencias de Email por Usuario\n";
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
    $sqlFile = __DIR__ . '/database/user_email_preferences.sql';
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
    echo "🔧 Creando tabla user_email_preferences...\n";
    
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
                    strpos($errorMsg, 'Table') !== false && strpos($errorMsg, 'exists') !== false) {
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
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_email_preferences'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "   ✅ Tabla 'user_email_preferences' existe\n";
        
        // Verificar estructura
        $stmt = $pdo->query("DESCRIBE user_email_preferences");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $requiredColumns = ['id', 'usuario_id', 'default_template'];
        
        $missingColumns = array_diff($requiredColumns, $columns);
        
        if (empty($missingColumns)) {
            echo "   ✅ Estructura de la tabla correcta\n";
            echo "   📊 Columnas encontradas: " . count($columns) . "\n\n";
        } else {
            echo "   ⚠️  Columnas faltantes: " . implode(', ', $missingColumns) . "\n\n";
        }
    } else {
        echo "   ❌ Error: La tabla no se creó correctamente\n\n";
        exit(1);
    }
    
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  ✅ INSTALACIÓN COMPLETADA\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    echo "📝 Próximos pasos:\n";
    echo "   1. Los usuarios pueden seleccionar su plantilla por defecto\n";
    echo "   2. La preferencia se guarda automáticamente en la base de datos\n";
    echo "   3. La plantilla por defecto se aplica al abrir el modal de envío\n";
    echo "   4. Cada usuario puede tener su propia plantilla por defecto\n\n";
    echo "💡 Nota: La preferencia persiste entre sesiones y es específica\n";
    echo "   por cuenta de usuario.\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "   Código: " . $e->getCode() . "\n";
    if (isset($e->errorInfo) && is_array($e->errorInfo)) {
        echo "   Info: " . print_r($e->errorInfo, true) . "\n";
    }
    exit(1);
}

