<?php
/**
 * Script de Verificación de Requisitos para Envío de Informes a PACS
 * 
 * Este script verifica que todas las librerías necesarias estén instaladas
 * para poder enviar informes tanto en formato PDF como JPG.
 * 
 * Uso: php verificar_requisitos.php
 * O acceder via web: http://localhost/api/informes/verificar_requisitos.php
 */

// Headers para acceso web
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  VERIFICACIÓN DE REQUISITOS - ENVÍO DE INFORMES A PACS        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$requisitos = [];
$errores = [];
$advertencias = [];

// 1. Verificar TCPDF (requerido para PDF)
echo "📋 Verificando TCPDF...\n";
if (class_exists('TCPDF')) {
    echo "   ✅ TCPDF está instalado\n";
    $requisitos['tcpdf'] = true;
    
    try {
        $pdf = new TCPDF();
        echo "   ✅ TCPDF funciona correctamente\n";
    } catch (Exception $e) {
        echo "   ⚠️  TCPDF está instalado pero tiene problemas: " . $e->getMessage() . "\n";
        $advertencias[] = "TCPDF tiene problemas de inicialización";
    }
} else {
    echo "   ❌ TCPDF NO está instalado\n";
    echo "   💡 Instalar con: composer require tecnickcom/tcpdf\n";
    $requisitos['tcpdf'] = false;
    $errores[] = "TCPDF es REQUERIDO para generar PDFs";
}

echo "\n";

// 2. Verificar Imagick (requerido para JPG)
echo "🖼️  Verificando Imagick...\n";
if (extension_loaded('imagick')) {
    echo "   ✅ Imagick está instalado\n";
    $requisitos['imagick'] = true;
    
    try {
        $imagick = new Imagick();
        $version = $imagick->getVersion();
        echo "   ✅ Imagick funciona correctamente\n";
        echo "   ℹ️  Versión: " . ($version['versionString'] ?? 'Desconocida') . "\n";
        
        // Verificar soporte para PDF
        $formats = $imagick->queryFormats('PDF');
        if (in_array('PDF', $formats)) {
            echo "   ✅ Imagick puede leer PDFs\n";
        } else {
            echo "   ⚠️  Imagick NO puede leer PDFs (verificar política de seguridad)\n";
            $advertencias[] = "Imagick no puede leer PDFs. Verificar /etc/ImageMagick-*/policy.xml";
        }
        
        $imagick->clear();
        $imagick->destroy();
        
    } catch (Exception $e) {
        echo "   ⚠️  Imagick está instalado pero tiene problemas: " . $e->getMessage() . "\n";
        $advertencias[] = "Imagick tiene problemas de inicialización";
    }
} else {
    echo "   ❌ Imagick NO está instalado\n";
    echo "   💡 Linux: sudo apt-get install php-imagick imagemagick\n";
    echo "   💡 Windows: Descargar ImageMagick y habilitar extension=imagick en php.ini\n";
    $requisitos['imagick'] = false;
    $advertencias[] = "Imagick NO está instalado. Se intentará usar GhostScript como alternativa";
}

echo "\n";

// 3. Verificar GhostScript (alternativa para JPG)
echo "👻 Verificando GhostScript...\n";
if (function_exists('exec')) {
    exec('gs --version 2>&1', $output, $returnCode);
    
    if ($returnCode === 0) {
        echo "   ✅ GhostScript está instalado\n";
        echo "   ℹ️  Versión: " . trim(implode(' ', $output)) . "\n";
        $requisitos['ghostscript'] = true;
    } else {
        echo "   ❌ GhostScript NO está instalado\n";
        echo "   💡 Linux: sudo apt-get install ghostscript\n";
        echo "   💡 Windows: https://www.ghostscript.com/download.html\n";
        $requisitos['ghostscript'] = false;
        
        if (!$requisitos['imagick']) {
            $errores[] = "Ni Imagick ni GhostScript están instalados. Se requiere al menos uno para formato JPG";
        }
    }
} else {
    echo "   ⚠️  La función exec() está deshabilitada (no se puede verificar GhostScript)\n";
    echo "   💡 Verificar disable_functions en php.ini\n";
    $requisitos['ghostscript'] = false;
    $advertencias[] = "exec() deshabilitado - GhostScript no puede usarse como fallback";
}

echo "\n";

// 4. Verificar extensión GD (fallback para conversión de imágenes)
echo "🎨 Verificando GD...\n";
if (extension_loaded('gd')) {
    echo "   ✅ GD está instalado\n";
    $gdInfo = gd_info();
    echo "   ℹ️  Versión: " . ($gdInfo['GD Version'] ?? 'Desconocida') . "\n";
    echo "   ℹ️  Soporte PNG: " . ($gdInfo['PNG Support'] ? 'Sí' : 'No') . "\n";
    echo "   ℹ️  Soporte JPEG: " . ($gdInfo['JPEG Support'] ?? $gdInfo['JPG Support'] ?? false ? 'Sí' : 'No') . "\n";
    $requisitos['gd'] = true;
} else {
    echo "   ⚠️  GD NO está instalado\n";
    echo "   💡 Linux: sudo apt-get install php-gd\n";
    $requisitos['gd'] = false;
    $advertencias[] = "GD no está instalado (usado como fallback para conversión)";
}

echo "\n";

// 5. Verificar permisos de directorios
echo "📁 Verificando permisos de directorios...\n";
$baseDir = realpath(__DIR__ . '/../../');
$directories = [
    'uploads/pdf_informes' => $baseDir . '/uploads/pdf_informes',
    'uploads/png_informes' => $baseDir . '/uploads/png_informes',
    'logs' => $baseDir . '/logs'
];

foreach ($directories as $name => $path) {
    if (!is_dir($path)) {
        echo "   ⚠️  Directorio $name no existe ($path)\n";
        echo "   💡 Se creará automáticamente al primer uso\n";
        
        // Intentar crear
        if (@mkdir($path, 0775, true)) {
            echo "   ✅ Directorio $name creado exitosamente\n";
        } else {
            echo "   ❌ No se pudo crear directorio $name\n";
            $errores[] = "No se puede crear directorio $name";
        }
    } else {
        if (is_writable($path)) {
            echo "   ✅ Directorio $name existe y es escribible\n";
        } else {
            echo "   ❌ Directorio $name existe pero NO es escribible\n";
            echo "   💡 Ejecutar: chmod 775 $path\n";
            $errores[] = "Directorio $name no es escribible";
        }
    }
}

echo "\n";

// 6. Verificar configuración PHP
echo "⚙️  Verificando configuración PHP...\n";
$uploadMaxFilesize = ini_get('upload_max_filesize');
$postMaxSize = ini_get('post_max_size');
$memoryLimit = ini_get('memory_limit');
$maxExecutionTime = ini_get('max_execution_time');

echo "   ℹ️  upload_max_filesize: $uploadMaxFilesize\n";
echo "   ℹ️  post_max_size: $postMaxSize\n";
echo "   ℹ️  memory_limit: $memoryLimit\n";
echo "   ℹ️  max_execution_time: $maxExecutionTime segundos\n";

// Verificar si los valores son adecuados
$uploadMaxBytes = return_bytes($uploadMaxFilesize);
$postMaxBytes = return_bytes($postMaxSize);
$memoryLimitBytes = return_bytes($memoryLimit);

if ($uploadMaxBytes < 10 * 1024 * 1024) { // 10MB
    $advertencias[] = "upload_max_filesize es menor a 10MB (recomendado para PDFs grandes)";
}
if ($postMaxBytes < 10 * 1024 * 1024) {
    $advertencias[] = "post_max_size es menor a 10MB";
}
if ($memoryLimitBytes > 0 && $memoryLimitBytes < 128 * 1024 * 1024) { // 128MB
    $advertencias[] = "memory_limit es menor a 128MB (puede causar problemas con PDFs grandes)";
}
if ($maxExecutionTime > 0 && $maxExecutionTime < 60) {
    $advertencias[] = "max_execution_time es menor a 60 segundos (puede causar timeouts)";
}

echo "\n";

// Resumen final
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                        RESUMEN FINAL                           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Formato PDF
echo "📄 FORMATO PDF:\n";
if ($requisitos['tcpdf']) {
    echo "   ✅ DISPONIBLE - Todos los requisitos cumplidos\n";
} else {
    echo "   ❌ NO DISPONIBLE - Instalar TCPDF\n";
}

echo "\n";

// Formato JPG
echo "🖼️  FORMATO JPG:\n";
if ($requisitos['imagick'] || $requisitos['ghostscript']) {
    echo "   ✅ DISPONIBLE - ";
    $metodos = [];
    if ($requisitos['imagick']) $metodos[] = "Imagick";
    if ($requisitos['ghostscript']) $metodos[] = "GhostScript";
    echo "Usando: " . implode(", ", $metodos) . "\n";
} else {
    echo "   ❌ NO DISPONIBLE - Instalar Imagick o GhostScript\n";
}

echo "\n";

// Errores críticos
if (!empty($errores)) {
    echo "❌ ERRORES CRÍTICOS:\n";
    foreach ($errores as $error) {
        echo "   • $error\n";
    }
    echo "\n";
}

// Advertencias
if (!empty($advertencias)) {
    echo "⚠️  ADVERTENCIAS:\n";
    foreach ($advertencias as $advertencia) {
        echo "   • $advertencia\n";
    }
    echo "\n";
}

// Estado general
if (empty($errores)) {
    if (empty($advertencias)) {
        echo "✅ SISTEMA COMPLETAMENTE FUNCIONAL\n";
        echo "   Puede enviar informes en ambos formatos (PDF y JPG)\n";
    } else {
        echo "✅ SISTEMA FUNCIONAL CON ADVERTENCIAS\n";
        echo "   Puede enviar informes pero hay mejoras recomendadas\n";
    }
} else {
    echo "❌ SISTEMA CON PROBLEMAS\n";
    echo "   Resolver errores críticos antes de usar\n";
}

echo "\n";

/**
 * Convierte notación abreviada de bytes a número
 */
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    
    return $val;
}
?>

