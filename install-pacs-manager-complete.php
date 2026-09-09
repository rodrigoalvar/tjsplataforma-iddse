<?php
/**
 * Script de Instalación Completo del Módulo PACS Manager
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script instala los permisos y verifica que todo esté correcto
 */

// Configuración de base de datos
require_once __DIR__ . '/config/database.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación PACS Manager - Completa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.css" rel="stylesheet">
    <style>
        body {
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .card {
            margin-bottom: 20px;
        }
        .success { color: #27ae60; }
        .error { color: #e74c3c; }
        .warning { color: #f39c12; }
        .info { color: #3498db; }
        code {
            background: #ecf0f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .step {
            border-left: 4px solid #3498db;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        table {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h3 class="mb-0"><i class="fas fa-database me-2"></i>Instalación del Módulo PACS Manager</h3>
        </div>
        <div class="card-body">
            <?php
            try {
                // Conectar a la base de datos
                $pdo = getDBConnection();
                echo '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>✓ Conexión a la base de datos exitosa</strong></div>';
                
                // ============================================
                // PASO 1: Crear/Eliminar permisos existentes si tienen categoría incorrecta
                // ============================================
                echo '<div class="step">';
                echo '<h5><i class="fas fa-step-forward me-2"></i>Paso 1: Verificar permisos existentes</h5>';
                
                $checkQuery = "SELECT permission_key, category FROM system_permissions WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager')";
                $checkStmt = $pdo->prepare($checkQuery);
                $checkStmt->execute();
                $existingPerms = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($existingPerms)) {
                    echo '<div class="alert alert-warning">';
                    echo '<strong>Permisos existentes encontrados:</strong><ul>';
                    foreach ($existingPerms as $perm) {
                        echo "<li><code>{$perm['permission_key']}</code> - Categoría: <code>{$perm['category']}</code></li>";
                    }
                    echo '</ul></div>';
                    
                    // Eliminar permisos existentes para recrearlos con la categoría correcta
                    $deleteQuery = "DELETE FROM system_permissions WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager')";
                    $pdo->exec($deleteQuery);
                    echo '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>Permisos existentes eliminados para recrearlos con la categoría correcta</div>';
                } else {
                    echo '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No se encontraron permisos existentes</div>';
                }
                echo '</div>';
                
                // ============================================
                // PASO 2: Insertar permisos con categoría correcta
                // ============================================
                echo '<div class="step">';
                echo '<h5><i class="fas fa-step-forward me-2"></i>Paso 2: Crear permisos del sistema</h5>';
                
                // Permiso funcional - categoría 'admin' (Administración)
                $insert1 = "INSERT INTO system_permissions (permission_key, permission_name, description, category) 
                           VALUES ('pacs_manager', 'Gestión PACS', 'Permite editar y eliminar estudios en el servidor PACS (Orthanc)', 'admin')";
                $pdo->exec($insert1);
                echo '<div class="alert alert-success"><i class="fas fa-check me-2"></i>Permiso <code>pacs_manager</code> creado en categoría <code>admin</code> (Administración)</div>';
                
                // Permiso de interfaz - categoría 'interfaz' (Interfaz/GUI)
                $insert2 = "INSERT INTO system_permissions (permission_key, permission_name, description, category) 
                           VALUES ('gui_pacs_manager', 'PACS Manager Visible', 'Controla la visibilidad y estado activo del acceso PACS Manager en el sidebar', 'interfaz')";
                $pdo->exec($insert2);
                echo '<div class="alert alert-success"><i class="fas fa-check me-2"></i>Permiso <code>gui_pacs_manager</code> creado en categoría <code>interfaz</code> (Interfaz/GUI)</div>';
                
                echo '</div>';
                
                // ============================================
                // PASO 3: Verificar que se crearon correctamente
                // ============================================
                echo '<div class="step">';
                echo '<h5><i class="fas fa-step-forward me-2"></i>Paso 3: Verificación de permisos</h5>';
                
                $verifyQuery = "SELECT permission_key, permission_name, description, category 
                               FROM system_permissions 
                               WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager')
                               ORDER BY permission_key";
                $verifyStmt = $pdo->prepare($verifyQuery);
                $verifyStmt->execute();
                $permissions = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($permissions) === 2) {
                    echo '<div class="alert alert-success"><strong>✓ Permisos creados correctamente</strong></div>';
                    echo '<table class="table table-bordered table-sm">';
                    echo '<thead><tr><th>Clave</th><th>Nombre</th><th>Categoría</th><th>Descripción</th></tr></thead>';
                    echo '<tbody>';
                    foreach ($permissions as $perm) {
                        $categoryName = $perm['category'] === 'admin' ? 'Administración' : 'Interfaz/GUI';
                        echo '<tr>';
                        echo '<td><code>' . htmlspecialchars($perm['permission_key']) . '</code></td>';
                        echo '<td>' . htmlspecialchars($perm['permission_name']) . '</td>';
                        echo '<td><span class="badge bg-primary">' . htmlspecialchars($categoryName) . '</span></td>';
                        echo '<td><small>' . htmlspecialchars($perm['description']) . '</small></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    throw new Exception('No se pudieron crear todos los permisos. Solo se encontraron ' . count($permissions) . ' de 2');
                }
                echo '</div>';
                
                // ============================================
                // PASO 4: Verificar archivos del módulo
                // ============================================
                echo '<div class="step">';
                echo '<h5><i class="fas fa-step-forward me-2"></i>Paso 4: Verificación de archivos</h5>';
                
                $requiredFiles = [
                    'pacs-manager.html' => 'Interfaz principal',
                    'assets/js/pacs-manager.js' => 'Lógica JavaScript',
                    'api/pacs-manager/list.php' => 'API listar estudios',
                    'api/pacs-manager/edit.php' => 'API editar estudios',
                    'api/pacs-manager/delete.php' => 'API eliminar estudios'
                ];
                
                $allFilesOk = true;
                echo '<table class="table table-sm">';
                echo '<thead><tr><th>Archivo</th><th>Descripción</th><th>Estado</th></tr></thead>';
                echo '<tbody>';
                foreach ($requiredFiles as $file => $description) {
                    $filePath = __DIR__ . '/' . $file;
                    $exists = file_exists($filePath);
                    if ($exists) {
                        echo "<tr class='table-success'><td><code>$file</code></td><td>$description</td><td><i class='fas fa-check text-success'></i> OK</td></tr>";
                    } else {
                        echo "<tr class='table-danger'><td><code>$file</code></td><td>$description</td><td><i class='fas fa-times text-danger'></i> NO ENCONTRADO</td></tr>";
                        $allFilesOk = false;
                    }
                }
                echo '</tbody></table>';
                echo '</div>';
                
                // ============================================
                // PASO 5: Verificar permisos en la API
                // ============================================
                echo '<div class="step">';
                echo '<h5><i class="fas fa-step-forward me-2"></i>Paso 5: Verificar API de permisos</h5>';
                
                // Simular consulta que hace permissions-simple.php
                $apiQuery = "SELECT permission_key, permission_name, description, category 
                            FROM system_permissions 
                            WHERE category = 'admin'
                            AND permission_key = 'pacs_manager'";
                $apiStmt = $pdo->prepare($apiQuery);
                $apiStmt->execute();
                $apiResult = $apiStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($apiResult) {
                    echo '<div class="alert alert-success">';
                    echo '<strong>✓ El permiso está disponible en la API</strong><br>';
                    echo 'La API <code>permissions-simple.php</code> debería devolver este permiso en la categoría <code>admin</code>';
                    echo '</div>';
                } else {
                    echo '<div class="alert alert-danger">';
                    echo '<strong>✗ El permiso NO está disponible en la API</strong><br>';
                    echo 'Hay un problema con la consulta de permisos';
                    echo '</div>';
                }
                echo '</div>';
                
                // ============================================
                // RESUMEN FINAL
                // ============================================
                if ($allFilesOk) {
                    echo '<div class="alert alert-success" style="margin-top: 30px;">';
                    echo '<h4><i class="fas fa-check-circle me-2"></i>¡Instalación Completada Exitosamente!</h4>';
                    echo '<p><strong>El módulo PACS Manager está listo para usar.</strong></p>';
                    echo '<hr>';
                    echo '<h5>Próximos pasos:</h5>';
                    echo '<ol>';
                    echo '<li><strong>Refrescar user-management:</strong> Abre <code>user-management.html</code> y recarga la página (F5 o Ctrl+R)</li>';
                    echo '<li><strong>Asignar permisos:</strong> Edita un usuario y busca en la sección <strong>"Administración"</strong> el permiso <code>pacs_manager</code></li>';
                    echo '<li><strong>Asignar permiso GUI:</strong> En la sección <strong>"Interfaz/GUI"</strong> asigna <code>gui_pacs_manager</code> para que aparezca en el sidebar</li>';
                    echo '<li><strong>Probar el módulo:</strong> Accede a <code>pacs-manager.html</code> para gestionar estudios</li>';
                    echo '</ol>';
                    echo '</div>';
                } else {
                    echo '<div class="alert alert-warning" style="margin-top: 30px;">';
                    echo '<h4><i class="fas fa-exclamation-triangle me-2"></i>Instalación Parcial</h4>';
                    echo '<p>Los permisos se instalaron correctamente, pero faltan algunos archivos del módulo.</p>';
                    echo '<p>Por favor, asegúrate de que todos los archivos estén presentes antes de usar el módulo.</p>';
                    echo '</div>';
                }
                
                // ============================================
                // INFORMACIÓN ADICIONAL
                // ============================================
                echo '<div class="alert alert-info" style="margin-top: 20px;">';
                echo '<h5><i class="fas fa-info-circle me-2"></i>Información Importante:</h5>';
                echo '<ul class="mb-0">';
                echo '<li>El permiso <code>pacs_manager</code> aparecerá en la sección <strong>"Administración"</strong> en user-management</li>';
                echo '<li>El permiso <code>gui_pacs_manager</code> aparecerá en la sección <strong>"Interfaz/GUI"</strong> en user-management</li>';
                echo '<li>Si no ves los permisos después de refrescar, limpia la caché del navegador (Ctrl+Shift+R)</li>';
                echo '<li>Las operaciones de edición/eliminación en PACS son permanentes - usa con precaución</li>';
                echo '</ul>';
                echo '</div>';
                
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">';
                echo '<h4><i class="fas fa-exclamation-circle me-2"></i>Error durante la instalación</h4>';
                echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '<hr>';
                echo '<h5>Soluciones Comunes:</h5>';
                echo '<ul>';
                echo '<li>Verifica que la base de datos existe y es accesible</li>';
                echo '<li>Verifica las credenciales en <code>config/database.php</code></li>';
                echo '<li>Asegúrate de que el usuario de MySQL tiene permisos para modificar tablas</li>';
                echo '<li>Revisa los logs de error de PHP para más detalles</li>';
                echo '</ul>';
                echo '</div>';
            }
            ?>
        </div>
        <div class="card-footer text-muted">
            <small>Instalación completada el <?php echo date('Y-m-d H:i:s'); ?></small>
        </div>
    </div>
</div>
</body>
</html>



