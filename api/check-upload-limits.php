<?php
/**
 * Script de diagnóstico para verificar límites de subida de archivos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Diagnóstico de Límites de Subida</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #007bff; }
        .ok { border-left-color: #28a745; }
        .warning { border-left-color: #ffc107; }
        .error { border-left-color: #dc3545; }
        .info { border-left-color: #17a2b8; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto; }
        .value { font-weight: bold; color: #007bff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnóstico de Límites de Subida de Archivos</h1>
        
        <?php
        function return_bytes($val) {
            $val = trim($val);
            $last = strtolower($val[strlen($val)-1]);
            $val = (int)$val;
            switch($last) {
                case 'g': $val *= 1024;
                case 'm': $val *= 1024;
                case 'k': $val *= 1024;
            }
            return $val;
        }
        
        function format_bytes($bytes) {
            if ($bytes >= 1073741824) {
                return number_format($bytes / 1073741824, 2) . ' GB';
            } elseif ($bytes >= 1048576) {
                return number_format($bytes / 1048576, 2) . ' MB';
            } elseif ($bytes >= 1024) {
                return number_format($bytes / 1024, 2) . ' KB';
            } else {
                return $bytes . ' bytes';
            }
        }
        
        // 1. Límites de PHP
        echo '<div class="section">';
        echo '<h2>1. Límites de PHP</h2>';
        
        $upload_max_filesize = ini_get('upload_max_filesize');
        $post_max_size = ini_get('post_max_size');
        $memory_limit = ini_get('memory_limit');
        $max_execution_time = ini_get('max_execution_time');
        $max_input_time = ini_get('max_input_time');
        
        $upload_max_bytes = return_bytes($upload_max_filesize);
        $post_max_bytes = return_bytes($post_max_size);
        $memory_limit_bytes = return_bytes($memory_limit);
        
        echo '<p><strong>upload_max_filesize:</strong> <span class="value">' . $upload_max_filesize . '</span> (' . format_bytes($upload_max_bytes) . ')</p>';
        echo '<p><strong>post_max_size:</strong> <span class="value">' . $post_max_size . '</span> (' . format_bytes($post_max_bytes) . ')</p>';
        echo '<p><strong>memory_limit:</strong> <span class="value">' . $memory_limit . '</span> (' . format_bytes($memory_limit_bytes) . ')</p>';
        echo '<p><strong>max_execution_time:</strong> <span class="value">' . $max_execution_time . '</span> segundos</p>';
        echo '<p><strong>max_input_time:</strong> <span class="value">' . $max_input_time . '</span> segundos</p>';
        
        $recommended = 100 * 1024 * 1024; // 100MB
        $status_class = 'ok';
        if ($upload_max_bytes < $recommended || $post_max_bytes < $recommended) {
            $status_class = 'warning';
            echo '<p style="color: #856404;"><strong>⚠️ Advertencia:</strong> Los límites son menores a 100MB. Para archivos grandes de audio, se recomienda al menos 100MB.</p>';
        } else {
            echo '<p style="color: #155724;"><strong>✅ OK:</strong> Los límites de PHP son suficientes para archivos grandes.</p>';
        }
        echo '</div>';
        
        // 2. Verificar .htaccess
        echo '<div class="section">';
        echo '<h2>2. Configuración .htaccess</h2>';
        $htaccess_path = __DIR__ . '/.htaccess';
        if (file_exists($htaccess_path)) {
            echo '<p style="color: #155724;"><strong>✅ Archivo .htaccess encontrado:</strong> <code>' . $htaccess_path . '</code></p>';
            $htaccess_content = file_get_contents($htaccess_path);
            echo '<pre>' . htmlspecialchars($htaccess_content) . '</pre>';
            
            // Verificar si Apache está usando .htaccess
            if (function_exists('apache_get_modules')) {
                $modules = apache_get_modules();
                if (in_array('mod_rewrite', $modules)) {
                    echo '<p style="color: #155724;">✅ mod_rewrite está habilitado (necesario para .htaccess)</p>';
                } else {
                    echo '<p style="color: #856404;">⚠️ mod_rewrite no está habilitado</p>';
                }
            }
        } else {
            echo '<p style="color: #856404;"><strong>⚠️ Archivo .htaccess no encontrado:</strong> <code>' . $htaccess_path . '</code></p>';
            echo '<p>Si usas Apache, crea este archivo con el siguiente contenido:</p>';
            echo '<pre>php_value upload_max_filesize 100M
php_value post_max_size 100M
php_value memory_limit 256M
php_value max_execution_time 600</pre>';
        }
        echo '</div>';
        
        // 3. Detectar servidor web
        echo '<div class="section">';
        echo '<h2>3. Servidor Web</h2>';
        $server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido';
        echo '<p><strong>Servidor detectado:</strong> <span class="value">' . htmlspecialchars($server_software) . '</span></p>';
        
        if (strpos(strtolower($server_software), 'nginx') !== false) {
            echo '<div class="info" style="padding: 15px; margin-top: 10px;">';
            echo '<h3>🔧 Configuración para Nginx</h3>';
            echo '<p>Para permitir archivos de hasta 100MB, agrega esta línea en la configuración de tu sitio:</p>';
            echo '<pre>client_max_body_size 100M;</pre>';
            echo '<p><strong>Ubicación del archivo:</strong> Generalmente en <code>/etc/nginx/sites-available/tu-sitio.conf</code></p>';
            echo '<p><strong>Después de modificar:</strong></p>';
            echo '<pre>sudo nginx -t  # Verificar configuración
sudo systemctl reload nginx  # Recargar Nginx</pre>';
            echo '</div>';
        } elseif (strpos(strtolower($server_software), 'apache') !== false) {
            echo '<div class="info" style="padding: 15px; margin-top: 10px;">';
            echo '<h3>🔧 Configuración para Apache</h3>';
            echo '<p>Si el archivo .htaccess no funciona, agrega en la configuración del virtual host:</p>';
            echo '<pre>&lt;Directory "/var/www/tjsiddse"&gt;
    LimitRequestBody 104857600  # 100MB en bytes
&lt;/Directory&gt;</pre>';
            echo '</div>';
        }
        echo '</div>';
        
        // 4. Verificar permisos
        echo '<div class="section">';
        echo '<h2>4. Permisos y Directorios</h2>';
        $upload_dir = __DIR__ . '/../uploads/audio/temp/';
        if (is_dir($upload_dir)) {
            echo '<p style="color: #155724;">✅ Directorio de uploads existe: <code>' . $upload_dir . '</code></p>';
            if (is_writable($upload_dir)) {
                echo '<p style="color: #155724;">✅ Directorio es escribible</p>';
            } else {
                echo '<p style="color: #dc3545;">❌ Directorio NO es escribible</p>';
                echo '<p>Ejecuta: <code>chmod 775 ' . $upload_dir . '</code></p>';
            }
        } else {
            echo '<p style="color: #dc3545;">❌ Directorio de uploads no existe: <code>' . $upload_dir . '</code></p>';
            echo '<p>Se creará automáticamente al primer uso.</p>';
        }
        echo '</div>';
        
        // 5. Resumen y recomendaciones
        echo '<div class="section ' . $status_class . '">';
        echo '<h2>5. Resumen y Recomendaciones</h2>';
        
        if ($upload_max_bytes >= $recommended && $post_max_bytes >= $recommended) {
            echo '<p style="color: #155724;"><strong>✅ Los límites de PHP están configurados correctamente.</strong></p>';
        } else {
            echo '<p style="color: #856404;"><strong>⚠️ Se recomienda aumentar los límites de PHP a al menos 100MB.</strong></p>';
        }
        
        if (strpos(strtolower($server_software), 'nginx') !== false) {
            echo '<p style="color: #856404;"><strong>⚠️ IMPORTANTE:</strong> Si sigues recibiendo error 413, verifica que <code>client_max_body_size</code> esté configurado en Nginx.</p>';
            echo '<p>El error 413 (Content Too Large) generalmente viene del servidor web, no de PHP.</p>';
        }
        
        echo '<p><strong>Para verificar los límites actuales del servidor web, revisa los logs:</strong></p>';
        echo '<pre># Nginx
sudo tail -f /var/log/nginx/error.log

# Apache
sudo tail -f /var/log/apache2/error.log</pre>';
        echo '</div>';
        ?>
        
        <div class="section info">
            <h2>📚 Documentación Adicional</h2>
            <p>Para más información sobre cómo configurar límites de subida:</p>
            <ul>
                <li><strong>Nginx:</strong> <a href="https://nginx.org/en/docs/http/ngx_http_core_module.html#client_max_body_size" target="_blank">Documentación oficial</a></li>
                <li><strong>Apache:</strong> <a href="https://httpd.apache.org/docs/2.4/mod/core.html#limitrequestbody" target="_blank">Documentación oficial</a></li>
                <li><strong>PHP:</strong> <a href="https://www.php.net/manual/en/ini.core.php#ini.upload-max-filesize" target="_blank">Documentación oficial</a></li>
            </ul>
        </div>
    </div>
</body>
</html>
