<?php
/**
 * Script de Instalación para Módulo de Configuración
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script agrega los permisos necesarios para el módulo de configuración
 */

require_once 'config/database.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación - Módulo de Configuración</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
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
            margin-bottom: 20px;
        }
        .step {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #667eea;
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        .info {
            color: #17a2b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Instalación - Módulo de Configuración</h1>
        <p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>
        
        <?php
        try {
            $pdo = getDBConnection();
            
            if (!$pdo) {
                throw new Exception('No se pudo conectar a la base de datos');
            }
            
            echo "<div class='step'>";
            echo "<h3>Paso 1: Verificando tabla system_permissions</h3>";
            
            // Verificar que la tabla existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'system_permissions'");
            if ($checkTable->rowCount() === 0) {
                throw new Exception('La tabla system_permissions no existe. Ejecuta primero el script de instalación de gestión de usuarios.');
            }
            
            echo "<div class='success'>✓ Tabla system_permissions encontrada</div>";
            echo "</div>";
            
            echo "<div class='step'>";
            echo "<h3>Paso 2: Agregando permisos GUI para Configuración</h3>";
            
            // Permisos a agregar
            $permissions = [
                [
                    'permission_key' => 'gui_configuracion',
                    'permission_name' => 'Acceso a Configuración (GUI)',
                    'description' => 'Permite ver el enlace de Configuración en el sidebar',
                    'category' => 'gui'
                ],
                [
                    'permission_key' => 'configuracion_manage',
                    'permission_name' => 'Gestionar Configuración',
                    'description' => 'Permite modificar la configuración del sistema (base de datos, PACS, URLs)',
                    'category' => 'admin'
                ]
            ];
            
            $permissionsInserted = 0;
            foreach ($permissions as $permission) {
                try {
                    $query = "INSERT INTO system_permissions (permission_key, permission_name, description, category) VALUES (?, ?, ?, ?)";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([
                        $permission['permission_key'],
                        $permission['permission_name'],
                        $permission['description'],
                        $permission['category']
                    ]);
                    $permissionsInserted++;
                    echo "<div class='success'>✓ Permiso agregado: {$permission['permission_key']}</div>";
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        echo "<div class='info'>ℹ Permiso ya existe: {$permission['permission_key']}</div>";
                    } else {
                        throw $e;
                    }
                }
            }
            
            echo "<div class='success'><strong>Permisos procesados: {$permissionsInserted} nuevos</strong></div>";
            echo "</div>";
            
            echo "<div class='step'>";
            echo "<h3>Paso 3: Verificando tabla configuracion</h3>";
            
            // Verificar que la tabla configuracion existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'configuracion'");
            if ($checkTable->rowCount() === 0) {
                echo "<div class='info'>ℹ Creando tabla configuracion...</div>";
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS configuracion (
                        clave VARCHAR(100) PRIMARY KEY,
                        valor TEXT,
                        descripcion TEXT,
                        fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                echo "<div class='success'>✓ Tabla configuracion creada</div>";
            } else {
                echo "<div class='success'>✓ Tabla configuracion ya existe</div>";
            }
            echo "</div>";
            
            echo "<div class='step'>";
            echo "<h3>✅ Instalación Completada</h3>";
            echo "<p>El módulo de configuración ha sido instalado correctamente.</p>";
            echo "<p><strong>Próximos pasos:</strong></p>";
            echo "<ul>";
            echo "<li>Asigna el permiso 'gui_configuracion' a los usuarios que deben ver el enlace de Configuración</li>";
            echo "<li>Asigna el permiso 'configuracion_manage' a los usuarios ROOT y ADMIN que pueden modificar la configuración</li>";
            echo "<li>Accede a <a href='configuracion.html'>configuracion.html</a> para gestionar las configuraciones</li>";
            echo "</ul>";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<div class='step'>";
            echo "<h3 class='error'>❌ Error en la instalación</h3>";
            echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>

