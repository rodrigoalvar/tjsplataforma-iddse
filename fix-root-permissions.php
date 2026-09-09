<?php
/**
 * Script para Actualizar Permisos del Usuario ROOT
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Actualización de Permisos del Usuario ROOT</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<h3>Estado Actual del Usuario ROOT:</h3>";
    
    // Verificar usuario ROOT actual
    $query = "SELECT id, nombre, apellido, email, nivel, permisos FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $rootUser = $stmt->fetch();
    
    if ($rootUser) {
        echo "<div style='color: green;'>✓ Usuario ROOT encontrado</div>";
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>ID:</strong> {$rootUser['id']}</p>";
        echo "<p><strong>Nombre:</strong> {$rootUser['nombre']} {$rootUser['apellido']}</p>";
        echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
        echo "<p><strong>Nivel:</strong> {$rootUser['nivel']}</p>";
        echo "<p><strong>Permisos actuales:</strong> {$rootUser['permisos']}</p>";
        echo "</div>";
        
        // Verificar permisos actuales
        $currentPermissions = json_decode($rootUser['permisos'], true) ?: [];
        echo "<div style='background: #e9ecef; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Permisos actuales:</h5>";
        if (empty($currentPermissions)) {
            echo "<p style='color: red;'>✗ No hay permisos definidos</p>";
        } else {
            echo "<ul>";
            foreach ($currentPermissions as $perm) {
                echo "<li>{$perm}</li>";
            }
            echo "</ul>";
        }
        echo "</div>";
        
        // Actualizar permisos del ROOT
        $rootPermissions = ['all']; // ROOT debe tener todos los permisos
        
        $updateQuery = "UPDATE usuarios SET permisos = ? WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $result = $updateStmt->execute([json_encode($rootPermissions), $rootUser['id']]);
        
        if ($result) {
            echo "<div style='color: green;'>✓ Permisos del usuario ROOT actualizados exitosamente</div>";
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Nuevos permisos:</strong> " . json_encode($rootPermissions) . "</p>";
            echo "<p><strong>Esto significa:</strong> El usuario ROOT ahora tiene acceso completo a todas las funcionalidades</p>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ Error actualizando permisos del usuario ROOT</div>";
        }
        
    } else {
        echo "<div style='color: red;'>✗ Usuario ROOT no encontrado</div>";
        echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Problema:</strong> No existe un usuario ROOT activo</p>";
        echo "<p><strong>Solución:</strong> Ejecutar la instalación del sistema de gestión de usuarios</p>";
        echo "<p><strong>Script:</strong> <a href='install-user-management-compatible.php'>install-user-management-compatible.php</a></p>";
        echo "</div>";
    }
    
    echo "<h3>Verificación Post-Actualización:</h3>";
    
    // Verificar que la actualización fue exitosa
    $query = "SELECT id, nombre, apellido, email, nivel, permisos FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $updatedRootUser = $stmt->fetch();
    
    if ($updatedRootUser) {
        $updatedPermissions = json_decode($updatedRootUser['permisos'], true) ?: [];
        
        if (in_array('all', $updatedPermissions)) {
            echo "<div style='color: green;'>✓ Usuario ROOT tiene permisos completos</div>";
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Estado:</strong> El usuario ROOT ahora debería poder ver 'Gestión Usuarios' en el sidebar</p>";
            echo "<p><strong>Próximo paso:</strong> <a href='dashboard-unified.html' target='_blank'>Acceder al dashboard</a></p>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ Usuario ROOT aún no tiene permisos completos</div>";
        }
    }
    
    echo "<h3>Prueba de API:</h3>";
    echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>Para probar que funciona:</h4>";
    echo "<ol>";
    echo "<li><strong>Inicia sesión como ROOT:</strong></li>";
    echo "<ul>";
    echo "<li>Email: root@portal.com</li>";
    echo "<li>Contraseña: admin123</li>";
    echo "</ul>";
    echo "<li><strong>Accede al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
    echo "<li><strong>Busca 'Gestión Usuarios' en el sidebar</strong></li>";
    echo "<li><strong>Si aún no aparece, ejecuta en la consola del navegador:</strong></li>";
    echo "</ol>";
    echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px;'>";
    echo "// Forzar mostrar el enlace
document.getElementById('userManagementNavItem').style.display = 'block';";
    echo "</pre>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<hr>";
echo "<p><small>Actualización completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


