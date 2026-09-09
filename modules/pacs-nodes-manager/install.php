<?php
/**
 * Instalador del Módulo PACS NODES MANAGER
 * 
 * Script automático para instalar y configurar el módulo.
 * Verifica dependencias, crea tablas y configura permisos.
 * 
 * @package PacsNodesManager
 * @version 1.0.0
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
    <title>Instalador - PACS NODES MANAGER</title>
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
            max-width: 900px;
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
            border-radius: 5px;
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
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .footer {
            padding: 20px;
            text-align: center;
            color: #666;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📡 PACS NODES MANAGER</h1>
            <p>Instalador del Módulo</p>
        </div>
        <div class="content">
            <?php
            $errors = [];
            $warnings = [];
            $success = [];
            
            // Paso 1: Verificar PHP
            $step1 = version_compare(PHP_VERSION, '7.4.0', '>=');
            if ($step1) {
                $success[] = "PHP " . PHP_VERSION . " ✓";
            } else {
                $errors[] = "PHP 7.4+ requerido. Actual: " . PHP_VERSION;
            }
            
            // Paso 2: Verificar extensiones
            $requiredExtensions = ['pdo', 'pdo_mysql', 'curl', 'json'];
            $missingExtensions = [];
            foreach ($requiredExtensions as $ext) {
                if (!extension_loaded($ext)) {
                    $missingExtensions[] = $ext;
                }
            }
            if (empty($missingExtensions)) {
                $success[] = "Extensiones PHP requeridas ✓";
            } else {
                $errors[] = "Extensiones faltantes: " . implode(', ', $missingExtensions);
            }
            
            // Paso 3: Verificar conexión a BD
            $dbConnected = false;
            try {
                require_once __DIR__ . '/../../config/database.php';
                $database = new Database();
                $db = $database->getConnection();
                if ($db) {
                    $dbConnected = true;
                    $success[] = "Conexión a base de datos ✓";
                }
            } catch (Exception $e) {
                $errors[] = "Error de conexión a BD: " . $e->getMessage();
            }
            
            // Paso 4: Crear tablas
            $tablesCreated = false;
            if ($dbConnected) {
                try {
                    $sqlFile = __DIR__ . '/database/install.sql';
                    if (file_exists($sqlFile)) {
                        $sql = file_get_contents($sqlFile);
                        
                        // Ejecutar SQL
                        $statements = array_filter(
                            array_map('trim', explode(';', $sql)),
                            function($stmt) {
                                return !empty($stmt) && 
                                       !preg_match('/^--/', $stmt) && 
                                       !preg_match('/^\/\*/', $stmt);
                            }
                        );
                        
                        foreach ($statements as $statement) {
                            if (!empty(trim($statement))) {
                                try {
                                    $db->exec($statement);
                                } catch (PDOException $e) {
                                    // Ignorar errores de "tabla ya existe"
                                    if (strpos($e->getMessage(), 'already exists') === false) {
                                        throw $e;
                                    }
                                }
                            }
                        }
                        
                        $tablesCreated = true;
                        $success[] = "Tablas de base de datos creadas ✓";
                    } else {
                        $errors[] = "Archivo SQL no encontrado: " . $sqlFile;
                    }
                } catch (Exception $e) {
                    $errors[] = "Error creando tablas: " . $e->getMessage();
                }
            }
            
            // Paso 5: Verificar permisos
            $permissionsCreated = false;
            if ($dbConnected) {
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM system_permissions WHERE permission_key IN ('pacs_nodes_manager', 'gui_pacs_nodes_manager')");
                    $stmt->execute();
                    $count = $stmt->fetchColumn();
                    
                    if ($count >= 2) {
                        $permissionsCreated = true;
                        $success[] = "Permisos del sistema creados ✓";
                    } else {
                        $warnings[] = "Algunos permisos pueden no estar creados. Verifique la tabla system_permissions.";
                    }
                } catch (Exception $e) {
                    $warnings[] = "No se pudo verificar permisos: " . $e->getMessage();
                }
            }
            
            // Paso 6: Verificar archivos del módulo
            $requiredFiles = [
                'PacsNodeConfig.php',
                'PacsNodeClient.php',
                'api/nodes.php',
                'api/ping.php',
                'api/find.php',
                'api/retrieve.php',
                'api/jobs.php'
            ];
            
            $missingFiles = [];
            foreach ($requiredFiles as $file) {
                if (!file_exists(__DIR__ . '/' . $file)) {
                    $missingFiles[] = $file;
                }
            }
            
            if (empty($missingFiles)) {
                $success[] = "Archivos del módulo presentes ✓";
            } else {
                $errors[] = "Archivos faltantes: " . implode(', ', $missingFiles);
            }
            
            // Mostrar resultados
            if (!empty($success)) {
                echo '<div class="step success">';
                echo '<h3>✅ Verificaciones Exitosas</h3>';
                foreach ($success as $msg) {
                    echo '<p>' . htmlspecialchars($msg) . '</p>';
                }
                echo '</div>';
            }
            
            if (!empty($warnings)) {
                echo '<div class="step warning">';
                echo '<h3>⚠️ Advertencias</h3>';
                foreach ($warnings as $msg) {
                    echo '<p>' . htmlspecialchars($msg) . '</p>';
                }
                echo '</div>';
            }
            
            if (!empty($errors)) {
                echo '<div class="step error">';
                echo '<h3>❌ Errores</h3>';
                foreach ($errors as $msg) {
                    echo '<p>' . htmlspecialchars($msg) . '</p>';
                }
                echo '</div>';
            }
            
            // Resumen final
            if (empty($errors)) {
                echo '<div class="step success">';
                echo '<h3>🎉 Instalación Completada</h3>';
                echo '<p>El módulo PACS NODES MANAGER ha sido instalado correctamente.</p>';
                echo '<p><strong>Próximos pasos:</strong></p>';
                echo '<ul style="margin-left: 20px; margin-top: 10px;">';
                echo '<li>Asignar permisos a usuarios en <code>user-management.html</code></li>';
                echo '<li>Configurar nodos PACS desde el módulo</li>';
                echo '<li>Probar conectividad con los nodos</li>';
                echo '</ul>';
                echo '</div>';
            } else {
                echo '<div class="step error">';
                echo '<h3>⚠️ Instalación Incompleta</h3>';
                echo '<p>Por favor, corrija los errores antes de usar el módulo.</p>';
                echo '</div>';
            }
            ?>
        </div>
        <div class="footer">
            <p>PACS NODES MANAGER v1.0.0 - Sistema TJSMEDICAL</p>
            <p style="margin-top: 10px;">
                <a href="../../dashboard-unified.html" class="btn">Ir al Dashboard</a>
            </p>
        </div>
    </div>
</body>
</html>
