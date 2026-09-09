<?php
/**
 * Instalador del Módulo de Email
 * 
 * Script automático para instalar y configurar el módulo de email.
 * Verifica dependencias, crea directorios necesarios y configura el módulo.
 * 
 * @package EmailModule
 * @version 1.0
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador - Módulo de Email</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }
        .content {
            padding: 30px;
        }
        .step {
            margin: 20px 0;
            padding: 20px;
            border-left: 4px solid #667eea;
            background: #f9f9f9;
        }
        .step.success {
            border-left-color: #28a745;
            background: #d4edda;
        }
        .step.error {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        .step.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        .step h3 {
            margin-bottom: 10px;
            color: #333;
        }
        .step p {
            margin: 5px 0;
            color: #666;
        }
        .step code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
        .form-group {
            margin: 20px 0;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .summary {
            background: #e7f3ff;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .summary h3 {
            margin-bottom: 15px;
            color: #004085;
        }
        .summary ul {
            list-style: none;
            padding-left: 0;
        }
        .summary li {
            padding: 5px 0;
            color: #004085;
        }
        .summary li:before {
            content: "✓ ";
            color: #28a745;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📧 Instalador del Módulo de Email</h1>
            <p>Configuración automática del sistema de envíos por email</p>
        </div>
        <div class="content">
            <?php
            $step = isset($_GET['step']) ? $_GET['step'] : 'check';
            $errors = [];
            $warnings = [];
            $success = [];
            
            // Paso 1: Verificar requisitos
            if ($step === 'check') {
                echo '<h2>Paso 1: Verificación de Requisitos</h2>';
                
                // Verificar PHP
                $phpVersion = phpversion();
                $phpOk = version_compare($phpVersion, '7.4.0', '>=');
                if ($phpOk) {
                    echo '<div class="step success"><h3>✓ Versión de PHP</h3><p>PHP ' . $phpVersion . ' (OK)</p></div>';
                    $success[] = 'PHP ' . $phpVersion;
                } else {
                    echo '<div class="step error"><h3>✗ Versión de PHP</h3><p>PHP ' . $phpVersion . ' (Se requiere PHP 7.4+)</p></div>';
                    $errors[] = 'PHP 7.4+ requerido';
                }
                
                // Verificar extensiones
                $extensions = ['openssl', 'curl', 'mbstring'];
                foreach ($extensions as $ext) {
                    if (extension_loaded($ext)) {
                        echo '<div class="step success"><h3>✓ Extensión ' . $ext . '</h3><p>Disponible</p></div>';
                        $success[] = 'Extensión ' . $ext;
                    } else {
                        echo '<div class="step error"><h3>✗ Extensión ' . $ext . '</h3><p>No disponible</p></div>';
                        $errors[] = 'Extensión ' . $ext . ' requerida';
                    }
                }
                
                // Verificar PHPMailer
                $phpmailerPaths = [
                    __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
                    __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php'
                ];
                $phpmailerFound = false;
                foreach ($phpmailerPaths as $path) {
                    if (file_exists($path)) {
                        $phpmailerFound = true;
                        break;
                    }
                }
                
                if ($phpmailerFound) {
                    echo '<div class="step success"><h3>✓ PHPMailer</h3><p>Instalado</p></div>';
                    $success[] = 'PHPMailer instalado';
                } else {
                    echo '<div class="step warning"><h3>⚠ PHPMailer</h3><p>No encontrado. Se instalará automáticamente con Composer.</p></div>';
                    $warnings[] = 'PHPMailer no encontrado';
                }
                
                // Verificar directorios
                $dirs = ['config', 'templates', 'logs', 'api'];
                foreach ($dirs as $dir) {
                    $path = __DIR__ . '/' . $dir;
                    if (is_dir($path) && is_writable($path)) {
                        echo '<div class="step success"><h3>✓ Directorio ' . $dir . '</h3><p>Existe y es escribible</p></div>';
                        $success[] = 'Directorio ' . $dir;
                    } else {
                        if (!is_dir($path)) {
                            if (@mkdir($path, 0755, true)) {
                                echo '<div class="step success"><h3>✓ Directorio ' . $dir . '</h3><p>Creado exitosamente</p></div>';
                                $success[] = 'Directorio ' . $dir . ' creado';
                            } else {
                                echo '<div class="step error"><h3>✗ Directorio ' . $dir . '</h3><p>No se pudo crear</p></div>';
                                $errors[] = 'No se pudo crear directorio ' . $dir;
                            }
                        } else {
                            echo '<div class="step error"><h3>✗ Directorio ' . $dir . '</h3><p>No es escribible</p></div>';
                            $errors[] = 'Directorio ' . $dir . ' no escribible';
                        }
                    }
                }
                
                // Verificar archivos de configuración
                $configFile = __DIR__ . '/config/email_config.php';
                if (file_exists($configFile)) {
                    echo '<div class="step warning"><h3>⚠ Archivo de configuración</h3><p>Ya existe. Se puede sobrescribir.</p></div>';
                } else {
                    echo '<div class="step success"><h3>✓ Archivo de configuración</h3><p>Se creará en el siguiente paso</p></div>';
                }
                
                // Resumen
                echo '<div class="summary">';
                echo '<h3>Resumen</h3>';
                echo '<ul>';
                echo '<li>Verificaciones exitosas: ' . count($success) . '</li>';
                echo '<li>Advertencias: ' . count($warnings) . '</li>';
                echo '<li>Errores: ' . count($errors) . '</li>';
                echo '</ul>';
                echo '</div>';
                
                // Botones de acción
                if (empty($errors)) {
                    echo '<a href="?step=config" class="btn btn-success">Continuar con la Configuración →</a>';
                } else {
                    echo '<div class="step error"><h3>Errores encontrados</h3><p>Por favor, corrija los errores antes de continuar.</p></div>';
                }
            }
            
            // Paso 2: Configuración
            if ($step === 'config') {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    // Guardar configuración
                    $config = [
                        'smtp' => [
                            'host' => $_POST['smtp_host'] ?? 'smtp.gmail.com',
                            'port' => (int)($_POST['smtp_port'] ?? 587),
                            'secure' => $_POST['smtp_secure'] ?? 'tls',
                            'username' => $_POST['smtp_username'] ?? '',
                            'password' => $_POST['smtp_password'] ?? '',
                            'from_email' => $_POST['from_email'] ?? 'noreply@tjsmedical.com',
                            'from_name' => $_POST['from_name'] ?? 'TJS Medical - Portal de Estudios'
                        ],
                        'options' => [
                            'charset' => 'UTF-8',
                            'debug' => isset($_POST['debug']) && $_POST['debug'] === '1',
                            'log_errors' => true,
                            'log_file' => __DIR__ . '/logs/email.log'
                        ]
                    ];
                    
                    $configContent = "<?php\n/**\n * Configuración del Módulo de Email\n * Generado automáticamente por el instalador\n */\n\nreturn " . var_export($config, true) . ";\n";
                    
                    $configFile = __DIR__ . '/config/email_config.php';
                    if (file_put_contents($configFile, $configContent)) {
                        echo '<div class="step success"><h3>✓ Configuración guardada</h3><p>El archivo de configuración se ha creado exitosamente.</p></div>';
                        echo '<a href="?step=complete" class="btn btn-success">Finalizar Instalación →</a>';
                    } else {
                        echo '<div class="step error"><h3>✗ Error al guardar</h3><p>No se pudo escribir el archivo de configuración. Verifique permisos.</p></div>';
                        echo '<a href="?step=config" class="btn">Intentar de nuevo</a>';
                    }
                } else {
                    // Mostrar formulario
                    echo '<h2>Paso 2: Configuración SMTP</h2>';
                    echo '<form method="POST" action="?step=config">';
                    
                    echo '<div class="form-group">';
                    echo '<label>Servidor SMTP (Host):</label>';
                    echo '<input type="text" name="smtp_host" value="smtp.gmail.com" required>';
                    echo '</div>';
                    
                    echo '<div class="form-group">';
                    echo '<label>Puerto SMTP:</label>';
                    echo '<input type="number" name="smtp_port" value="587" required>';
                    echo '</div>';
                    
                    echo '<div class="form-group">';
                    echo '<label>Seguridad:</label>';
                    echo '<select name="smtp_secure" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">';
                    echo '<option value="tls" selected>TLS</option>';
                    echo '<option value="ssl">SSL</option>';
                    echo '</select>';
                    echo '</div>';
                    
                    echo '<div class="form-group">';
                    echo '<label>Usuario SMTP (Email):</label>';
                    echo '<input type="email" name="smtp_username" required>';
                    echo '</div>';
                    
                    echo '<div class="form-group">';
                    echo '<label>Contraseña SMTP (App Password):</label>';
                    echo '<input type="password" name="smtp_password" required>';
                    echo '<p style="font-size: 12px; color: #666; margin-top: 5px;">Para Gmail, use una "App Password" en lugar de su contraseña normal.</p>';
                    echo '</div>';
                    
                    echo '<div class="form-group">';
                    echo '<label>Email Remitente (From):</label>';
                    echo '<input type="email" name="from_email" value="noreply@tjsmedical.com" required>';
                    echo '</div>';
                    
                    echo '<div class="form-group">';
                    echo '<label>Nombre Remitente:</label>';
                    echo '<input type="text" name="from_name" value="TJS Medical - Portal de Estudios" required>';
                    echo '</div>';
                    
                    echo '<div class="form-group">';
                    echo '<label><input type="checkbox" name="debug" value="1"> Modo Debug (solo para desarrollo)</label>';
                    echo '</div>';
                    
                    echo '<button type="submit" class="btn btn-success">Guardar Configuración</button>';
                    echo '<a href="?step=check" class="btn">← Volver</a>';
                    echo '</form>';
                }
            }
            
            // Paso 3: Completado
            if ($step === 'complete') {
                echo '<h2>✓ Instalación Completada</h2>';
                echo '<div class="step success">';
                echo '<h3>¡El módulo de email ha sido instalado exitosamente!</h3>';
                echo '<p>El módulo está listo para usar.</p>';
                echo '</div>';
                
                echo '<div class="summary">';
                echo '<h3>Próximos pasos:</h3>';
                echo '<ul>';
                echo '<li>Instalar PHPMailer: <code>composer require phpmailer/phpmailer</code></li>';
                echo '<li>Probar conexión: <code>GET /modules/email/api/test-connection.php</code></li>';
                echo '<li>Revisar documentación en <code>modules/email/docs/</code></li>';
                echo '<li>Configurar eventos automáticos en <code>config/email_events.php</code></li>';
                echo '</ul>';
                echo '</div>';
                
                echo '<a href="../docs/README.md" class="btn">Ver Documentación</a>';
                echo '<a href="api/test-connection.php" class="btn">Probar Conexión</a>';
            }
            ?>
        </div>
    </div>
</body>
</html>

