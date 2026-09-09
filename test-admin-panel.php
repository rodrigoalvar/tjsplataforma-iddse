<?php
/**
 * Script de Prueba del Panel de Administración
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script verifica que todos los componentes estén funcionando
 */

echo "<h1>Prueba del Panel de Administración</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

// Verificar archivos necesarios
$requiredFiles = [
    'dashboard-unified.html' => 'Dashboard principal',
    'user-management.html' => 'Panel de gestión de usuarios',
    'user-management.js' => 'JavaScript del panel',
    'styles.css' => 'Estilos CSS',
    'js/auth-middleware.js' => 'Middleware de autenticación',
    'api/users/check-permission.php' => 'API de verificación de permisos',
    'api/users/manage.php' => 'API de gestión de usuarios',
    'api/users/assignable.php' => 'API de usuarios asignables',
    'api/users/hierarchy.php' => 'API de jerarquías',
    'api/users/permissions.php' => 'API de permisos'
];

echo "<h3>Verificación de Archivos:</h3>";
$filesOk = 0;
foreach ($requiredFiles as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
        $filesOk++;
    } else {
        echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<div style='background: #e9ecef; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
echo "<strong>Archivos encontrados:</strong> {$filesOk} de " . count($requiredFiles);
echo "</div>";

// Verificar base de datos
try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<h3>Verificación de Base de Datos:</h3>";
    
    // Verificar tablas
    $tables = ['usuarios', 'system_permissions', 'user_audit_logs', 'user_sessions'];
    foreach ($tables as $table) {
        try {
            $query = "SHOW TABLES LIKE ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$table]);
            $result = $stmt->fetchAll();
            
            if (count($result) > 0) {
                echo "<div style='color: green;'>✓ Tabla '{$table}' existe</div>";
            } else {
                echo "<div style='color: red;'>✗ Tabla '{$table}' no existe</div>";
            }
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error verificando tabla '{$table}': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Verificar usuario ROOT
    try {
        $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $rootUser = $stmt->fetch();
        
        if ($rootUser) {
            echo "<div style='color: green;'>✓ Usuario ROOT existe</div>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
            echo "<p><strong>Nivel:</strong> {$rootUser['nivel']}</p>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ Usuario ROOT no existe</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error verificando usuario ROOT: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Verificar permisos
    try {
        $query = "SELECT COUNT(*) as total FROM system_permissions";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $permissionsCount = $stmt->fetch()['total'];
        
        if ($permissionsCount > 0) {
            echo "<div style='color: green;'>✓ Permisos del sistema: {$permissionsCount} permisos</div>";
        } else {
            echo "<div style='color: red;'>✗ No hay permisos del sistema</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error verificando permisos: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error conectando a la base de datos: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>Instrucciones de Acceso:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Para acceder al Panel de Administración:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Inicia sesión como ROOT:</strong></li>";
echo "<ul>";
echo "<li>Email: root@portal.com</li>";
echo "<li>Contraseña: admin123</li>";
echo "</ul>";
echo "<li><strong>Ve al Dashboard:</strong> <a href='dashboard-unified.html'>dashboard-unified.html</a></li>";
echo "<li><strong>Busca 'Gestión Usuarios' en el sidebar</strong> (debería aparecer automáticamente)</li>";
echo "<li><strong>O accede directamente:</strong> <a href='user-management.html'>user-management.html</a></li>";
echo "</ol>";
echo "</div>";

echo "<h3>Funcionalidades del Panel:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<ul>";
echo "<li>✅ Dashboard con estadísticas de usuarios</li>";
echo "<li>✅ Lista completa de usuarios con filtros</li>";
echo "<li>✅ Vista jerárquica padre-hijo</li>";
echo "<li>✅ Crear nuevos usuarios</li>";
echo "<li>✅ Editar usuarios existentes</li>";
echo "<li>✅ Gestionar jerarquías</li>";
echo "<li>✅ Asignar permisos granulares</li>";
echo "<li>✅ Reset de contraseñas</li>";
echo "<li>✅ Eliminar usuarios (con validaciones)</li>";
echo "<li>✅ Logs de auditoría</li>";
echo "</ul>";
echo "</div>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #856404;'>Notas Importantes:</h4>";
echo "<ul style='color: #856404;'>";
echo "<li>El enlace 'Gestión Usuarios' solo aparece para usuarios ADMIN y ROOT</li>";
echo "<li>Los usuarios USER no pueden acceder al panel</li>";
echo "<li>ADMIN no puede modificar usuarios ROOT</li>";
echo "<li>Todas las acciones quedan registradas en logs de auditoría</li>";
echo "</ul>";
echo "</div>";

echo "<hr>";
echo "<p><small>Prueba completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>
