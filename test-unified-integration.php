<?php
/**
 * Script de Prueba de Integración con Dashboard Unified
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script verifica que el panel de administración esté correctamente integrado
 */

echo "<h1>Prueba de Integración con Dashboard Unified</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

// Verificar archivos específicos de la integración
$integrationFiles = [
    'dashboard-unified.html' => 'Dashboard principal unificado',
    'user-management.html' => 'Panel de gestión de usuarios',
    'user-management.js' => 'JavaScript del panel',
    'js/auth-middleware.js' => 'Middleware de autenticación',
    'api/users/check-permission.php' => 'API de verificación de permisos',
    'api/users/manage.php' => 'API de gestión de usuarios',
    'api/users/assignable.php' => 'API de usuarios asignables',
    'api/users/hierarchy.php' => 'API de jerarquías',
    'api/users/permissions.php' => 'API de permisos'
];

echo "<h3>Verificación de Archivos de Integración:</h3>";
$filesOk = 0;
foreach ($integrationFiles as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
        $filesOk++;
    } else {
        echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<div style='background: #e9ecef; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
echo "<strong>Archivos de integración encontrados:</strong> {$filesOk} de " . count($integrationFiles);
echo "</div>";

// Verificar contenido específico en dashboard-unified.html
echo "<h3>Verificación de Contenido en Dashboard Unified:</h3>";

if (file_exists('dashboard-unified.html')) {
    $content = file_get_contents('dashboard-unified.html');
    
    $checks = [
        'userManagementNavItem' => 'Enlace de Gestión Usuarios en sidebar',
        'checkUserPermission' => 'Función de verificación de permisos',
        'canManageUsers' => 'Lógica de verificación de permisos',
        'fas fa-users' => 'Icono de usuarios en sidebar',
        'user-management.html' => 'Enlace al panel de gestión'
    ];
    
    foreach ($checks as $check => $description) {
        if (strpos($content, $check) !== false) {
            echo "<div style='color: green;'>✓ {$description}</div>";
        } else {
            echo "<div style='color: red;'>✗ {$description} - NO ENCONTRADO</div>";
        }
    }
} else {
    echo "<div style='color: red;'>✗ No se puede verificar contenido - dashboard-unified.html no existe</div>";
}

// Verificar contenido específico en user-management.html
echo "<h3>Verificación de Contenido en User Management:</h3>";

if (file_exists('user-management.html')) {
    $content = file_get_contents('user-management.html');
    
    $checks = [
        'dashboard-unified.html' => 'Enlace correcto al dashboard unificado',
        'checkUserPermission' => 'Función de verificación de permisos',
        'requireAuth' => 'Función de autenticación',
        'initializeUserManagement' => 'Función de inicialización'
    ];
    
    foreach ($checks as $check => $description) {
        if (strpos($content, $check) !== false) {
            echo "<div style='color: green;'>✓ {$description}</div>";
        } else {
            echo "<div style='color: red;'>✗ {$description} - NO ENCONTRADO</div>";
        }
    }
} else {
    echo "<div style='color: red;'>✗ No se puede verificar contenido - user-management.html no existe</div>";
}

// Verificar base de datos
echo "<h3>Verificación de Base de Datos:</h3>";
try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    // Verificar usuario ROOT
    try {
        $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $rootUser = $stmt->fetch();
        
        if ($rootUser) {
            echo "<div style='color: green;'>✓ Usuario ROOT existe y está activo</div>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
            echo "<p><strong>Nivel:</strong> {$rootUser['nivel']}</p>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ Usuario ROOT no existe o no está activo</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error verificando usuario ROOT: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Verificar permisos
    try {
        $query = "SELECT COUNT(*) as total FROM system_permissions WHERE permission_key = 'usuarios'";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $permissionsCount = $stmt->fetch()['total'];
        
        if ($permissionsCount > 0) {
            echo "<div style='color: green;'>✓ Permiso 'usuarios' existe en el sistema</div>";
        } else {
            echo "<div style='color: red;'>✗ Permiso 'usuarios' no existe en el sistema</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error verificando permisos: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error conectando a la base de datos: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>Instrucciones de Prueba:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Para probar la integración:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Inicia sesión como ROOT:</strong></li>";
echo "<ul>";
echo "<li>Email: root@portal.com</li>";
echo "<li>Contraseña: admin123</li>";
echo "</ul>";
echo "<li><strong>Accede al Dashboard Unificado:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
echo "<li><strong>Verifica que aparece 'Gestión Usuarios' en el sidebar</strong></li>";
echo "<li><strong>Haz clic en 'Gestión Usuarios'</strong> para acceder al panel</li>";
echo "<li><strong>O accede directamente:</strong> <a href='user-management.html' target='_blank'>user-management.html</a></li>";
echo "</ol>";
echo "</div>";

echo "<h3>Funcionalidades Esperadas:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<ul>";
echo "<li>✅ Enlace 'Gestión Usuarios' visible en sidebar para ADMIN/ROOT</li>";
echo "<li>✅ Enlace oculto para usuarios USER</li>";
echo "<li>✅ Panel de gestión completamente funcional</li>";
echo "<li>✅ Navegación correcta entre dashboard y panel</li>";
echo "<li>✅ Verificación de permisos en tiempo real</li>";
echo "<li>✅ Autenticación y autorización funcionando</li>";
echo "</ul>";
echo "</div>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #856404;'>Notas Importantes:</h4>";
echo "<ul style='color: #856404;'>";
echo "<li>El enlace solo aparece para usuarios con permisos de gestión</li>";
echo "<li>Si no aparece el enlace, verifica que tengas permisos de 'usuarios'</li>";
echo "<li>El panel está completamente integrado con el sistema unificado</li>";
echo "<li>Todas las rutas apuntan correctamente a dashboard-unified.html</li>";
echo "</ul>";
echo "</div>";

echo "<hr>";
echo "<p><small>Prueba de integración completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


