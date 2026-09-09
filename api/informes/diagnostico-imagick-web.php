<?php
/**
 * Diagnóstico de Imagick desde contexto web (Apache/PHP-FPM)
 * 
 * Este script verifica si Imagick está disponible cuando se ejecuta
 * desde el servidor web (no solo desde CLI)
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Diagnóstico Imagick - Contexto Web</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .status {
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 5px solid;
        }
        .status.success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .status.error {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .status.warning {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        .info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 5px solid #2196F3;
            font-family: monospace;
            white-space: pre-wrap;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnóstico Imagick - Contexto Web</h1>
        
        <p><strong>Este script se ejecuta desde el servidor web (Apache/PHP-FPM), no desde CLI.</strong></p>
        <p>Esto es importante porque a veces Imagick funciona en CLI pero no en web.</p>
        
        <hr>
        
        <?php
        echo "<h2>1️⃣ Verificación de Extensión</h2>\n";
        
        $extensionLoaded = extension_loaded('imagick');
        $classExists = class_exists('Imagick');
        
        echo '<div class="status ' . ($extensionLoaded ? 'success' : 'error') . '">';
        echo '<strong>extension_loaded(\'imagick\'):</strong> ' . ($extensionLoaded ? '✅ SÍ' : '❌ NO');
        echo '</div>';
        
        echo '<div class="status ' . ($classExists ? 'success' : 'error') . '">';
        echo '<strong>class_exists(\'Imagick\'):</strong> ' . ($classExists ? '✅ SÍ' : '❌ NO');
        echo '</div>';
        
        echo '<div class="info">';
        echo "<strong>Lista de extensiones cargadas:</strong>\n";
        $extensions = get_loaded_extensions();
        sort($extensions);
        $hasImagick = false;
        foreach ($extensions as $ext) {
            if (strtolower($ext) === 'imagick') {
                echo "<strong style='color: green;'>✅ $ext</strong>\n";
                $hasImagick = true;
            } else {
                echo "$ext\n";
            }
        }
        if (!$hasImagick) {
            echo "\n❌ <strong style='color: red;'>imagick NO está en la lista de extensiones</strong>\n";
        }
        echo '</div>';
        
        echo "<h2>2️⃣ Información PHP</h2>\n";
        echo '<div class="info">';
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "SAPI: " . php_sapi_name() . "\n";
        echo "Architecture: " . (PHP_INT_SIZE === 8 ? 'x64' : 'x86') . "\n";
        echo "Thread Safety: " . (ZEND_THREAD_SAFE ? 'ZTS' : 'NTS') . "\n";
        echo "php.ini cargado: " . php_ini_loaded_file() . "\n";
        echo "Directorio extensiones: " . ini_get('extension_dir') . "\n";
        echo '</div>';
        
        echo "<h2>3️⃣ Verificación de Archivo DLL</h2>\n";
        $extensionDir = ini_get('extension_dir');
        $imagickDll = $extensionDir . '/php_imagick.dll';
        
        echo '<div class="status ' . (file_exists($imagickDll) ? 'success' : 'error') . '">';
        echo '<strong>Archivo DLL:</strong> ' . htmlspecialchars($imagickDll) . '<br>';
        echo '<strong>Existe:</strong> ' . (file_exists($imagickDll) ? '✅ SÍ' : '❌ NO');
        if (file_exists($imagickDll)) {
            echo '<br><strong>Tamaño:</strong> ' . filesize($imagickDll) . ' bytes';
            echo '<br><strong>Última modificación:</strong> ' . date('Y-m-d H:i:s', filemtime($imagickDll));
        }
        echo '</div>';
        
        echo "<h2>4️⃣ Prueba de Instanciación</h2>\n";
        
        if ($extensionLoaded || $classExists) {
            try {
                $imagick = new Imagick();
                $version = $imagick->getVersion();
                
                echo '<div class="status success">';
                echo '<strong>✅ Imagick funciona correctamente</strong><br>';
                echo 'Versión: ' . htmlspecialchars($version['versionString']) . '<br>';
                
                // Verificar formatos
                $formats = $imagick->queryFormats('PDF');
                if (in_array('PDF', $formats)) {
                    echo '✅ Puede leer PDFs<br>';
                } else {
                    echo '⚠️ NO puede leer PDFs (política de seguridad)<br>';
                }
                
                $imagick->destroy();
                echo '</div>';
                
            } catch (Exception $e) {
                echo '<div class="status error">';
                echo '<strong>❌ Error al instanciar Imagick</strong><br>';
                echo 'Error: ' . htmlspecialchars($e->getMessage()) . '<br>';
                echo 'Tipo: ' . get_class($e) . '<br>';
                echo '</div>';
            }
        } else {
            echo '<div class="status error">';
            echo '<strong>❌ No se puede probar - Imagick no está disponible</strong>';
            echo '</div>';
        }
        
        echo "<h2>5️⃣ Configuración php.ini</h2>\n";
        $phpIniFile = php_ini_loaded_file();
        echo '<div class="info">';
        if ($phpIniFile && file_exists($phpIniFile)) {
            $phpIniContent = file_get_contents($phpIniFile);
            if (preg_match('/^extension\s*=\s*imagick/im', $phpIniContent, $matches)) {
                echo "✅ <code>extension=imagick</code> encontrado en php.ini\n";
                echo "Archivo: " . htmlspecialchars($phpIniFile) . "\n";
            } elseif (preg_match('/^;extension\s*=\s*imagick/im', $phpIniContent)) {
                echo "⚠️ <code>extension=imagick</code> está COMENTADO (línea con ;)\n";
                echo "Archivo: " . htmlspecialchars($phpIniFile) . "\n";
                echo "Necesitas descomentar esa línea.\n";
            } else {
                echo "❌ <code>extension=imagick</code> NO encontrado en php.ini\n";
                echo "Archivo: " . htmlspecialchars($phpIniFile) . "\n";
                echo "Necesitas agregar: extension=imagick\n";
            }
        } else {
            echo "⚠️ No se pudo leer php.ini\n";
        }
        echo '</div>';
        
        echo "<h2>6️⃣ Recomendaciones</h2>\n";
        
        if (!$extensionLoaded && !$classExists) {
            echo '<div class="status warning">';
            echo '<strong>⚠️ Imagick NO está disponible desde el contexto web</strong><br><br>';
            echo '<strong>Posibles soluciones:</strong><br>';
            echo '1. Verificar que <code>extension=imagick</code> esté en php.ini correcto<br>';
            echo '2. Verificar que estés editando el php.ini correcto (no el de CLI)<br>';
            echo '3. Reiniciar Apache/WAMP completamente<br>';
            echo '4. Verificar que php_imagick.dll exista en el directorio de extensiones<br>';
            echo '5. Verificar que todos los DLLs de ImageMagick estén en el directorio PHP<br>';
            echo '</div>';
        } elseif ($extensionLoaded || $classExists) {
            try {
                $imagick = new Imagick();
                $formats = $imagick->queryFormats('PDF');
                if (!in_array('PDF', $formats)) {
                    echo '<div class="status warning">';
                    echo '<strong>⚠️ Imagick funciona pero NO puede leer PDFs</strong><br><br>';
                    echo '<strong>Solución:</strong><br>';
                    echo 'Editar política de seguridad de ImageMagick:<br>';
                    echo '<code>C:\\Program Files\\ImageMagick-7.x.x\\config\\policy.xml</code><br>';
                    echo 'Cambiar: <code>&lt;policy domain="coder" rights="none" pattern="PDF" /&gt;</code><br>';
                    echo 'Por: <code>&lt;policy domain="coder" rights="read|write" pattern="PDF" /&gt;</code><br>';
                    echo '</div>';
                } else {
                    echo '<div class="status success">';
                    echo '<strong>✅ Todo está funcionando correctamente</strong><br>';
                    echo 'Imagick está disponible y puede leer PDFs desde el contexto web.';
                    echo '</div>';
                }
                $imagick->destroy();
            } catch (Exception $e) {
                echo '<div class="status error">';
                echo '<strong>❌ Error al probar Imagick</strong><br>';
                echo htmlspecialchars($e->getMessage());
                echo '</div>';
            }
        }
        ?>
        
        <hr>
        
        <h2>📊 Comparación CLI vs Web</h2>
        <div class="info">
            <strong>Ejecutar este diagnóstico desde CLI:</strong>
            <code>php api/informes/diagnostico-imagick-web.php</code>
            <br><br>
            Si funciona en CLI pero no en web, el problema es la configuración de Apache/PHP-FPM.
        </div>
    </div>
</body>
</html>

