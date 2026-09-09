<?php
/**
 * Script para verificar si ffmpeg está disponible para PHP
 * Ejecutar desde navegador o línea de comandos
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verificación de ffmpeg</title>
    <style>
        body { font-family: monospace; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Verificación de ffmpeg para conversión de audio</h1>
    
    <?php
    echo "<h2>1. Verificando con 'which ffmpeg':</h2>";
    $whichResult = @shell_exec('which ffmpeg 2>/dev/null');
    if ($whichResult && trim($whichResult)) {
        echo "<p class='success'>✓ Encontrado: " . htmlspecialchars(trim($whichResult)) . "</p>";
    } else {
        echo "<p class='error'>✗ No encontrado con 'which'</p>";
    }
    
    echo "<h2>2. Verificando con 'command -v ffmpeg':</h2>";
    $output = [];
    $returnCode = 0;
    @exec('command -v ffmpeg 2>/dev/null', $output, $returnCode);
    if ($returnCode === 0 && !empty($output[0])) {
        echo "<p class='success'>✓ Encontrado: " . htmlspecialchars($output[0]) . "</p>";
    } else {
        echo "<p class='error'>✗ No encontrado con 'command -v'</p>";
    }
    
    echo "<h2>3. Verificando rutas comunes:</h2>";
    $commonPaths = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/bin/ffmpeg',
        '/opt/ffmpeg/bin/ffmpeg'
    ];
    $found = false;
    foreach ($commonPaths as $path) {
        if (file_exists($path)) {
            $executable = is_executable($path) ? ' (ejecutable)' : ' (no ejecutable)';
            echo "<p class='success'>✓ Encontrado: $path$executable</p>";
            $found = true;
        }
    }
    if (!$found) {
        echo "<p class='error'>✗ No encontrado en rutas comunes</p>";
    }
    
    echo "<h2>4. Probando comando directo 'ffmpeg -version':</h2>";
    $testCmd = @shell_exec('ffmpeg -version 2>&1');
    if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
        echo "<p class='success'>✓ ffmpeg responde correctamente</p>";
        echo "<pre>" . htmlspecialchars(substr($testCmd, 0, 500)) . "</pre>";
    } else {
        echo "<p class='error'>✗ ffmpeg no responde o no está instalado</p>";
        if ($testCmd) {
            echo "<pre>" . htmlspecialchars($testCmd) . "</pre>";
        }
    }
    
    echo "<h2>5. Probando ejecución directa de /usr/bin/ffmpeg:</h2>";
    if (file_exists('/usr/bin/ffmpeg')) {
        echo "<p class='success'>✓ Archivo existe: /usr/bin/ffmpeg</p>";
        $testDirect = @shell_exec('/usr/bin/ffmpeg -version 2>&1');
        if ($testDirect && strpos($testDirect, 'ffmpeg version') !== false) {
            echo "<p class='success'>✓ Ejecución exitosa desde /usr/bin/ffmpeg</p>";
            echo "<pre>" . htmlspecialchars(substr($testDirect, 0, 200)) . "</pre>";
        } else {
            echo "<p class='error'>✗ No se pudo ejecutar /usr/bin/ffmpeg</p>";
            if ($testDirect) {
                echo "<pre>" . htmlspecialchars($testDirect) . "</pre>";
            }
        }
    } else {
        echo "<p class='error'>✗ /usr/bin/ffmpeg no existe</p>";
    }
    
    echo "<h2>6. Buscando en rutas específicas (sin find completo para evitar timeout):</h2>";
    $specificPaths = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/bin/ffmpeg',
        '/opt/ffmpeg/bin/ffmpeg',
        '/snap/bin/ffmpeg'
    ];
    $foundPaths = [];
    foreach ($specificPaths as $path) {
        $testCmd = @shell_exec(escapeshellarg($path) . ' -version 2>&1');
        if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
            $foundPaths[] = $path;
        }
    }
    if (!empty($foundPaths)) {
        echo "<p class='success'>✓ Archivos encontrados y funcionales:</p>";
        echo "<ul>";
        foreach ($foundPaths as $path) {
            echo "<li>" . htmlspecialchars($path) . " ✓</li>";
        }
        echo "</ul>";
    } else {
        echo "<p class='error'>✗ No se encontró ffmpeg en rutas comunes</p>";
    }
    
    echo "<h2>7. Verificando permisos del usuario PHP:</h2>";
    $user = @exec('whoami');
    $phpUser = get_current_user();
    $processUser = @exec('ps aux | grep -E "php-fpm|apache" | head -1 | awk \'{print $1}\'');
    echo "<p class='info'>Usuario actual (whoami): " . htmlspecialchars($user ?: 'desconocido') . "</p>";
    echo "<p class='info'>Usuario PHP (get_current_user): " . htmlspecialchars($phpUser) . "</p>";
    echo "<p class='info'>Usuario proceso PHP-FPM/Apache: " . htmlspecialchars($processUser ?: 'no detectado') . "</p>";
    
    // Verificar permisos específicos
    if (@file_exists('/usr/bin/ffmpeg')) {
        $perms = @fileperms('/usr/bin/ffmpeg');
        if ($perms !== false) {
            $permsStr = substr(sprintf('%o', $perms), -4);
            echo "<p class='info'>Permisos de /usr/bin/ffmpeg: " . htmlspecialchars($permsStr) . "</p>";
            $owner = @fileowner('/usr/bin/ffmpeg');
            $group = @filegroup('/usr/bin/ffmpeg');
            if ($owner !== false && function_exists('posix_getpwuid')) {
                $ownerInfo = @posix_getpwuid($owner);
                echo "<p class='info'>Propietario: " . htmlspecialchars($ownerInfo['name'] ?? 'desconocido') . "</p>";
            }
            if ($group !== false && function_exists('posix_getgrgid')) {
                $groupInfo = @posix_getgrgid($group);
                echo "<p class='info'>Grupo: " . htmlspecialchars($groupInfo['name'] ?? 'desconocido') . "</p>";
            }
        }
    }
    
    echo "<h2>8. Verificando variables de entorno:</h2>";
    $path = getenv('PATH');
    echo "<p class='info'>PATH: " . htmlspecialchars($path ?: 'no definido') . "</p>";
    
    // Verificar si /usr/bin está en el PATH
    if ($path && strpos($path, '/usr/bin') !== false) {
        echo "<p class='success'>✓ /usr/bin está en el PATH</p>";
    } else {
        echo "<p class='error'>✗ /usr/bin NO está en el PATH de PHP</p>";
        echo "<p class='info'>Esto explica por qué 'which ffmpeg' no funciona, pero podemos usar la ruta absoluta /usr/bin/ffmpeg</p>";
    }
    
    echo "<h2>9. Verificando con dpkg (Debian/Ubuntu):</h2>";
    $dpkgResult = @shell_exec('dpkg -l | grep -i ffmpeg 2>/dev/null');
    if ($dpkgResult && trim($dpkgResult)) {
        echo "<pre>" . htmlspecialchars($dpkgResult) . "</pre>";
    } else {
        echo "<p class='error'>✗ No encontrado en dpkg (puede no estar instalado o no ser Debian/Ubuntu)</p>";
    }
    
    echo "<h2>Conclusión:</h2>";
    $ffmpegFound = false;
    $ffmpegPath = null;
    
    // Usar la misma lógica que WhisperClient
    $whichResult = @shell_exec('which ffmpeg 2>/dev/null');
    if ($whichResult && trim($whichResult)) {
        $ffmpegPath = trim($whichResult);
        $ffmpegFound = true;
    } else {
        // Probar rutas comunes (priorizar /usr/bin/ffmpeg)
        $commonPaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/bin/ffmpeg', '/opt/ffmpeg/bin/ffmpeg'];
        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                // Probar ejecución directa
                $testCmd = @shell_exec(escapeshellarg($path) . ' -version 2>&1');
                if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
                    $ffmpegPath = $path;
                    $ffmpegFound = true;
                    break;
                }
            }
        }
    }
    
    if (!$ffmpegFound) {
        $testCmd = @shell_exec('ffmpeg -version 2>&1');
        if ($testCmd && strpos($testCmd, 'ffmpeg version') !== false) {
            $ffmpegPath = 'ffmpeg';
            $ffmpegFound = true;
        }
    }
    
    if ($ffmpegFound) {
        echo "<p class='success'><strong>✓ ffmpeg está disponible en: " . htmlspecialchars($ffmpegPath) . "</strong></p>";
        echo "<p>La conversión de M4A debería funcionar correctamente.</p>";
    } else {
        echo "<p class='error'><strong>✗ ffmpeg NO está disponible</strong></p>";
        echo "<p>Para instalar ffmpeg:</p>";
        echo "<pre>";
        echo "# Ubuntu/Debian:\n";
        echo "sudo apt-get update\n";
        echo "sudo apt-get install ffmpeg\n\n";
        echo "# CentOS/RHEL:\n";
        echo "sudo yum install ffmpeg\n";
        echo "# O para versiones más recientes:\n";
        echo "sudo dnf install ffmpeg\n";
        echo "</pre>";
    }
    ?>
</body>
</html>
