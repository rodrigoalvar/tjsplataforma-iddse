<?php
/**
 * Script para instalar permisos del módulo PACS NODES MANAGER
 * Ejecutar este script si los permisos no aparecen en user-management
 * 
 * Uso: Acceder desde navegador o ejecutar desde línea de comandos
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalar Permisos - PACS NODES MANAGER</title>
    <style>
        body {
            font-family: Arial, sans-serif;
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
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        h1 {
            color: #333;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Instalar Permisos - PACS NODES MANAGER</h1>
        
        <?php
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            if (!$db) {
                throw new Exception('No se pudo conectar a la base de datos');
            }
            
            echo '<div class="info">Conectado a la base de datos correctamente.</div>';
            
            // Verificar si los permisos ya existen
            $checkStmt = $db->prepare("SELECT * FROM system_permissions WHERE permission_key IN ('pacs_nodes_manager', 'gui_pacs_nodes_manager')");
            $checkStmt->execute();
            $existing = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($existing) > 0) {
                echo '<div class="info">';
                echo '<strong>Permisos encontrados:</strong><ul>';
                foreach ($existing as $perm) {
                    echo '<li>' . htmlspecialchars($perm['permission_key']) . ' - ' . htmlspecialchars($perm['permission_name']) . '</li>';
                }
                echo '</ul></div>';
            }
            
            // Insertar permisos
            $permissions = [
                [
                    'permission_key' => 'pacs_nodes_manager',
                    'permission_name' => 'Gestionar Nodos PACS',
                    'description' => 'Permite gestionar nodos PACS remotos y realizar operaciones C-FIND, C-MOVE, C-GET',
                    'category' => 'pacs'
                ],
                [
                    'permission_key' => 'gui_pacs_nodes_manager',
                    'permission_name' => 'Ver PACS Nodes Manager',
                    'description' => 'Permite ver y acceder al módulo PACS Nodes Manager en el sidebar',
                    'category' => 'interfaz'
                ]
            ];
            
            $inserted = 0;
            $updated = 0;
            
            foreach ($permissions as $perm) {
                // Verificar si existe
                $checkStmt = $db->prepare("SELECT * FROM system_permissions WHERE permission_key = ?");
                $checkStmt->execute([$perm['permission_key']]);
                $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($exists) {
                    // Actualizar si existe
                    $updateStmt = $db->prepare("
                        UPDATE system_permissions 
                        SET permission_name = ?, 
                            description = ?, 
                            category = ?
                        WHERE permission_key = ?
                    ");
                    $updateStmt->execute([
                        $perm['permission_name'],
                        $perm['description'],
                        $perm['category'],
                        $perm['permission_key']
                    ]);
                    $updated++;
                    echo '<div class="success">✓ Permiso actualizado: ' . htmlspecialchars($perm['permission_key']) . '</div>';
                } else {
                    // Insertar si no existe
                    $insertStmt = $db->prepare("
                        INSERT INTO system_permissions 
                        (permission_key, permission_name, description, category, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $insertStmt->execute([
                        $perm['permission_key'],
                        $perm['permission_name'],
                        $perm['description'],
                        $perm['category']
                    ]);
                    $inserted++;
                    echo '<div class="success">✓ Permiso creado: ' . htmlspecialchars($perm['permission_key']) . '</div>';
                }
            }
            
            echo '<div class="success">';
            echo '<strong>✅ Proceso completado:</strong><br>';
            echo '- Permisos creados: ' . $inserted . '<br>';
            echo '- Permisos actualizados: ' . $updated . '<br>';
            echo '</div>';
            
            // Verificar resultado final
            $finalCheck = $db->prepare("SELECT * FROM system_permissions WHERE permission_key IN ('pacs_nodes_manager', 'gui_pacs_nodes_manager') ORDER BY permission_key");
            $finalCheck->execute();
            $final = $finalCheck->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<div class="info">';
            echo '<strong>Permisos finales en la base de datos:</strong><ul>';
            foreach ($final as $perm) {
                echo '<li><strong>' . htmlspecialchars($perm['permission_key']) . '</strong>: ' . 
                     htmlspecialchars($perm['permission_name']) . ' (Categoría: ' . htmlspecialchars($perm['category']) . ')</li>';
            }
            echo '</ul></div>';
            
            echo '<p><strong>Próximos pasos:</strong></p>';
            echo '<ol>';
            echo '<li>Asignar los permisos a usuarios en <a href="../../user-management.html">Gestión de Usuarios</a></li>';
            echo '<li>Recargar la página de Gestión de Usuarios para ver los nuevos permisos</li>';
            echo '<li>El enlace "PACS Nodes Manager" aparecerá en el sidebar cuando el usuario tenga el permiso <code>gui_pacs_nodes_manager</code></li>';
            echo '</ol>';
            
        } catch (Exception $e) {
            echo '<div class="error">';
            echo '<strong>❌ Error:</strong> ' . htmlspecialchars($e->getMessage());
            echo '</div>';
        }
        ?>
        
        <a href="../../user-management.html" class="btn">Ir a Gestión de Usuarios</a>
        <a href="../../dashboard-unified.html" class="btn">Ir al Dashboard</a>
    </div>
</body>
</html>
