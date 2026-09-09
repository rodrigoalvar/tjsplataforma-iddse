<?php
/**
 * Script de instalación para TJSMEDICAL Portal
 * Crea la base de datos y las tablas necesarias
 */

header('Content-Type: text/html; charset=utf-8');

// Configuración de la base de datos
$host = 'localhost';
$username = 'root';
$password = '';
$database_name = 'TJSMEDICAL';

$success_messages = [];
$error_messages = [];

try {
    // Conectar a MySQL sin especificar base de datos
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Crear base de datos si no existe
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $success_messages[] = "Base de datos '$database_name' creada o ya existe";
    
    // Conectar a la base de datos específica
    $pdo = new PDO("mysql:host=$host;dbname=$database_name;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Crear tabla de usuarios
    $usuarios_sql = "
        CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            apellido VARCHAR(100) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            telefono VARCHAR(20) NOT NULL,
            matricula_profesional VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email_verificado BOOLEAN DEFAULT FALSE,
            token_verificacion VARCHAR(255) NULL,
            fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            activo BOOLEAN DEFAULT TRUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($usuarios_sql);
    $success_messages[] = "Tabla 'usuarios' creada exitosamente";
    
    // Crear tabla de sesiones
    $sesiones_sql = "
        CREATE TABLE IF NOT EXISTS sesiones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            token_sesion VARCHAR(255) UNIQUE NOT NULL,
            fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_expiracion TIMESTAMP NOT NULL,
            activa BOOLEAN DEFAULT TRUE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($sesiones_sql);
    $success_messages[] = "Tabla 'sesiones' creada exitosamente";
    
    // Crear índices (ignorar errores si ya existen)
    $indices = [
        "CREATE INDEX idx_usuarios_email ON usuarios(email)",
        "CREATE INDEX idx_usuarios_matricula ON usuarios(matricula_profesional)",
        "CREATE INDEX idx_sesiones_token ON sesiones(token_sesion)",
        "CREATE INDEX idx_sesiones_usuario ON sesiones(usuario_id)"
    ];
    
    $indices_creados = 0;
    foreach ($indices as $index_sql) {
        try {
            $pdo->exec($index_sql);
            $indices_creados++;
        } catch (PDOException $e) {
            // Ignorar error si el índice ya existe (código 1061)
            if ($e->getCode() != '42000' || strpos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e; // Re-lanzar si es un error diferente
            }
        }
    }
    $success_messages[] = "Índices procesados exitosamente ($indices_creados nuevos creados)";
    
    // Verificar si ya existe el usuario administrador
    $admin_check = $pdo->prepare("SELECT id FROM usuarios WHERE email = 'admin@tjsmedical.com'");
    $admin_check->execute();
    
    if ($admin_check->rowCount() == 0) {
        // Crear usuario administrador por defecto
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $admin_sql = "
            INSERT INTO usuarios (nombre, apellido, email, telefono, matricula_profesional, password_hash, email_verificado) 
            VALUES ('Administrador', 'Sistema', 'admin@tjsmedical.com', '1234567890', 'ADMIN001', ?, TRUE)
        ";
        
        $admin_stmt = $pdo->prepare($admin_sql);
        $admin_stmt->execute([$admin_password]);
        $success_messages[] = "Usuario administrador creado (Email: admin@tjsmedical.com, Password: admin123)";
    } else {
        $success_messages[] = "Usuario administrador ya existe";
    }
    
} catch (PDOException $e) {
    $error_messages[] = "Error de base de datos: " . $e->getMessage();
} catch (Exception $e) {
    $error_messages[] = "Error general: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación - TJSMEDICAL Portal</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .install-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2.5rem;
        }
        
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-weight: 500;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .btn {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            text-align: center;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .status-icon {
            font-size: 4rem;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .success-icon {
            color: #28a745;
        }
        
        .error-icon {
            color: #dc3545;
        }
        
        .info-box {
            background: #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .info-box h3 {
            margin-top: 0;
            color: #495057;
        }
        
        .credentials {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .credentials strong {
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <h1>Instalación TJSMEDICAL</h1>
        
        <?php if (empty($error_messages)): ?>
            <div class="status-icon success-icon">✅</div>
            <h2 style="text-align: center; color: #28a745;">¡Instalación Exitosa!</h2>
            
            <?php foreach ($success_messages as $message): ?>
                <div class="message success"><?php echo htmlspecialchars($message); ?></div>
            <?php endforeach; ?>
            
            <div class="info-box">
                <h3>Próximos pasos:</h3>
                <ol>
                    <li>La base de datos <strong>TJSMEDICAL</strong> ha sido creada</li>
                    <li>Las tablas necesarias han sido configuradas</li>
                    <li>Se ha creado un usuario administrador por defecto</li>
                    <li>Ya puedes comenzar a usar el sistema</li>
                </ol>
                
                <div class="credentials">
                    <strong>Credenciales de Administrador:</strong><br>
                    Email: <code>admin@tjsmedical.com</code><br>
                    Contraseña: <code>admin123</code>
                </div>
            </div>
            
            <div style="text-align: center;">
                <a href="login.html" class="btn">Ir al Login</a>
                <a href="register.html" class="btn" style="margin-left: 10px;">Registrar Usuario</a>
            </div>
            
        <?php else: ?>
            <div class="status-icon error-icon">❌</div>
            <h2 style="text-align: center; color: #dc3545;">Error en la Instalación</h2>
            
            <?php foreach ($error_messages as $message): ?>
                <div class="message error"><?php echo htmlspecialchars($message); ?></div>
            <?php endforeach; ?>
            
            <div class="info-box">
                <h3>Posibles soluciones:</h3>
                <ul>
                    <li>Verifica que MySQL esté ejecutándose</li>
                    <li>Confirma las credenciales de conexión en <code>config/database.php</code></li>
                    <li>Asegúrate de que el usuario tenga permisos para crear bases de datos</li>
                    <li>Verifica que el puerto 3306 esté disponible</li>
                </ul>
            </div>
            
            <div style="text-align: center;">
                <a href="install.php" class="btn">Reintentar Instalación</a>
            </div>
        <?php endif; ?>
        
        <div class="info-box" style="margin-top: 30px;">
            <h3>Información del Sistema:</h3>
            <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
            <p><strong>Base de datos:</strong> <?php echo $database_name; ?></p>
            <p><strong>Host:</strong> <?php echo $host; ?></p>
            <p><strong>Fecha de instalación:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>