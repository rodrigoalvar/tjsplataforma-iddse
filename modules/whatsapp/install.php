<?php
/**
 * Instalador del Módulo de WhatsApp
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script instala el módulo de WhatsApp creando la estructura
 * de directorios necesaria y verificando dependencias.
 * 
 * Uso: php install.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  INSTALACIÓN - Módulo de WhatsApp (WAHA)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$moduleDir = __DIR__;
$errors = [];
$warnings = [];

// 1. Verificar estructura de directorios
echo "📁 Verificando estructura de directorios...\n";

$directories = [
    'config',
    'api',
    'logs',
    'docs'
];

foreach ($directories as $dir) {
    $path = $moduleDir . '/' . $dir;
    if (!is_dir($path)) {
        if (mkdir($path, 0755, true)) {
            echo "   ✅ Directorio creado: $dir/\n";
        } else {
            $errors[] = "No se pudo crear el directorio: $dir/";
            echo "   ❌ Error creando directorio: $dir/\n";
        }
    } else {
        echo "   ✅ Directorio existe: $dir/\n";
    }
}

// 2. Verificar archivos principales
echo "\n📄 Verificando archivos principales...\n";

$requiredFiles = [
    'WAHAAPI.php' => 'Clase principal de WAHA API',
    'WhatsAppConfig.php' => 'Gestor de configuración',
    'config/whatsapp_config.php' => 'Archivo de configuración',
    'api/sessions.php' => 'API de gestión de sesiones',
    'api/qrcode.php' => 'API de códigos QR'
];

foreach ($requiredFiles as $file => $description) {
    $path = $moduleDir . '/' . $file;
    if (file_exists($path)) {
        echo "   ✅ $file - $description\n";
    } else {
        $errors[] = "Archivo faltante: $file";
        echo "   ❌ Faltante: $file - $description\n";
    }
}

// 3. Verificar permisos de escritura
echo "\n🔐 Verificando permisos de escritura...\n";

$writablePaths = [
    'config' => 'Configuración',
    'logs' => 'Logs'
];

foreach ($writablePaths as $path => $description) {
    $fullPath = $moduleDir . '/' . $path;
    if (is_dir($fullPath)) {
        if (is_writable($fullPath)) {
            echo "   ✅ $description ($path/) - Escribible\n";
        } else {
            $warnings[] = "El directorio $path/ no es escribible. Puede causar problemas al guardar configuración.";
            echo "   ⚠️  $description ($path/) - No escribible\n";
        }
    }
}

// 4. Verificar dependencias
echo "\n🔍 Verificando dependencias...\n";

// Verificar que existe el módulo de email (para usar _auth.php)
$emailAuthPath = $moduleDir . '/../email/api/_auth.php';
if (file_exists($emailAuthPath)) {
    echo "   ✅ Módulo de email encontrado (para autenticación)\n";
} else {
    $warnings[] = "No se encontró el módulo de email. La autenticación puede no funcionar correctamente.";
    echo "   ⚠️  Módulo de email no encontrado\n";
}

// Verificar que admin.php existe (para agregar la pestaña)
$adminPath = $moduleDir . '/../email/admin.php';
if (file_exists($adminPath)) {
    echo "   ✅ admin.php encontrado (para panel de administración)\n";
} else {
    $warnings[] = "No se encontró admin.php. La pestaña de WhatsApp puede no estar disponible.";
    echo "   ⚠️  admin.php no encontrado\n";
}

// 5. Crear archivo de configuración por defecto si no existe
echo "\n⚙️  Verificando configuración...\n";

$configFile = $moduleDir . '/config/whatsapp_config.php';
if (!file_exists($configFile)) {
    $defaultConfig = "<?php
/**
 * Configuración del Módulo de WhatsApp
 * Generado automáticamente por el instalador
 * 
 * @package WhatsAppModule
 */

return [
    'waha' => [
        'base_url' => 'http://localhost:3000',
        'api_key' => '',
        'timeout' => 30,
        'default_session' => 'default',
        'default_country_code' => '54' // Argentina
    ],
    'options' => [
        'log_errors' => true,
        'log_file' => __DIR__ . '/../logs/whatsapp.log'
    ]
];
";
    
    if (file_put_contents($configFile, $defaultConfig)) {
        echo "   ✅ Archivo de configuración creado\n";
    } else {
        $errors[] = "No se pudo crear el archivo de configuración";
        echo "   ❌ Error creando archivo de configuración\n";
    }
} else {
    echo "   ✅ Archivo de configuración existe\n";
}

// 6. Verificar conexión con WAHA (opcional)
echo "\n🌐 Verificando conexión con WAHA (opcional)...\n";

try {
    require_once $moduleDir . '/WhatsAppConfig.php';
    require_once $moduleDir . '/WAHAAPI.php';
    
    $config = WhatsAppConfig::load();
    $wahaConfig = $config->getWahaConfig();
    $wahaAPI = new WAHAAPI($wahaConfig);
    
    if ($wahaAPI->checkConnection()) {
        echo "   ✅ Conexión con WAHA exitosa\n";
    } else {
        $warnings[] = "No se pudo conectar con WAHA. Verifique que WAHA esté corriendo en: " . ($wahaConfig['base_url'] ?? 'http://localhost:3000');
        echo "   ⚠️  No se pudo conectar con WAHA\n";
    }
} catch (Exception $e) {
    $warnings[] = "Error verificando conexión con WAHA: " . $e->getMessage();
    echo "   ⚠️  Error verificando conexión: " . $e->getMessage() . "\n";
}

// Resumen
echo "\n═══════════════════════════════════════════════════════════════\n";

if (empty($errors)) {
    echo "  ✅ INSTALACIÓN COMPLETADA\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    
    if (!empty($warnings)) {
        echo "⚠️  ADVERTENCIAS:\n";
        foreach ($warnings as $warning) {
            echo "   - $warning\n";
        }
        echo "\n";
    }
    
    echo "📋 PRÓXIMOS PASOS:\n";
    echo "   1. Configure WAHA en admin.php (pestaña 'Mensajería WhatsApp')\n";
    echo "   2. Cree una sesión de WhatsApp desde el panel de administración\n";
    echo "   3. Escanee el código QR para autenticar la sesión\n";
    echo "   4. Comience a enviar mensajes de WhatsApp\n\n";
    
    echo "📚 DOCUMENTACIÓN:\n";
    echo "   - Ver: modules/whatsapp/docs/README.md\n";
    echo "   - Ver: modules/whatsapp/docs/INSTALACION.md\n\n";
    
    exit(0);
} else {
    echo "  ❌ INSTALACIÓN FALLIDA\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    
    echo "❌ ERRORES ENCONTRADOS:\n";
    foreach ($errors as $error) {
        echo "   - $error\n";
    }
    echo "\n";
    
    if (!empty($warnings)) {
        echo "⚠️  ADVERTENCIAS:\n";
        foreach ($warnings as $warning) {
            echo "   - $warning\n";
        }
        echo "\n";
    }
    
    exit(1);
}

