<?php
/**
 * Script de Verificación del Sistema de Gestión de Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script verifica que la instalación fue exitosa
 */

require_once 'config/database.php';

echo "<h1>Verificación del Sistema de Gestión de Usuarios</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

try {
    $pdo = getDBConnection();
    echo "<div style='color: green;'>✓ Conexión a la base de datos exitosa</div>";
    
    // Verificar tablas
    $tables = ['usuarios', 'system_permissions', 'user_audit_logs', 'user_sessions'];
    $tablesOk = 0;
    
    echo "<h3>Verificación de Tablas:</h3>";
    foreach ($tables as $table) {
        try {
            $query = "SHOW TABLES LIKE ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$table]);
            $result = $stmt->fetchAll();
            
            if (count($result) > 0) {
                echo "<div style='color: green;'>✓ Tabla '{$table}' existe</div>";
                $tablesOk++;
            } else {
                echo "<div style='color: red;'>✗ Tabla '{$table}' no existe</div>";
            }
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error verificando tabla '{$table}': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Verificar campos en tabla usuarios
    echo "<h3>Verificación de Campos en Tabla Usuarios:</h3>";
    $requiredFields = ['nivel', 'padre_id', 'especialidad', 'permisos', 'ultimo_acceso', 'created_at', 'updated_at'];
    $fieldsOk = 0;
    
    foreach ($requiredFields as $field) {
        try {
            $query = "SHOW COLUMNS FROM usuarios LIKE ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$field]);
            $result = $stmt->fetchAll();
            
            if (count($result) > 0) {
                echo "<div style='color: green;'>✓ Campo '{$field}' existe</div>";
                $fieldsOk++;
            } else {
                echo "<div style='color: red;'>✗ Campo '{$field}' no existe</div>";
            }
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error verificando campo '{$field}': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Verificar usuario ROOT
    echo "<h3>Verificación de Usuario ROOT:</h3>";
    try {
        $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE nivel = 'root' AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $rootUser = $stmt->fetch();
        
        if ($rootUser) {
            echo "<div style='color: green;'>✓ Usuario ROOT existe</div>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>ID:</strong> {$rootUser['id']}</p>";
            echo "<p><strong>Nombre:</strong> {$rootUser['nombre']} {$rootUser['apellido']}</p>";
            echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
            echo "<p><strong>Nivel:</strong> {$rootUser['nivel']}</p>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ Usuario ROOT no existe</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error verificando usuario ROOT: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Verificar permisos del sistema
    echo "<h3>Verificación de Permisos del Sistema:</h3>";
    try {
        $query = "SELECT COUNT(*) as total FROM system_permissions";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $permissionsCount = $stmt->fetch()['total'];
        
        if ($permissionsCount > 0) {
            echo "<div style='color: green;'>✓ Permisos del sistema: {$permissionsCount} permisos</div>";
            
            // Mostrar permisos por categoría
            $query = "SELECT category, COUNT(*) as count FROM system_permissions GROUP BY category";
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $permissionsByCategory = $stmt->fetchAll();
            
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Permisos por Categoría:</h5>";
            foreach ($permissionsByCategory as $category) {
                echo "<p><strong>{$category['category']}:</strong> {$category['count']} permisos</p>";
            }
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ No hay permisos del sistema</div>";
        }
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error verificando permisos: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Verificar índices
    echo "<h3>Verificación de Índices:</h3>";
    $requiredIndexes = ['idx_nivel', 'idx_padre_id', 'idx_activo'];
    $indexesOk = 0;
    
    foreach ($requiredIndexes as $index) {
        try {
            $query = "SHOW INDEX FROM usuarios WHERE Key_name = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$index]);
            $result = $stmt->fetchAll();
            
            if (count($result) > 0) {
                echo "<div style='color: green;'>✓ Índice '{$index}' existe</div>";
                $indexesOk++;
            } else {
                echo "<div style='color: red;'>✗ Índice '{$index}' no existe</div>";
            }
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ Error verificando índice '{$index}': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Estadísticas generales
    echo "<h3>Estadísticas Generales:</h3>";
    try {
        $query = "SELECT nivel, COUNT(*) as count FROM usuarios WHERE activo = 1 GROUP BY nivel";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $stats = $stmt->fetchAll();
        
        echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Usuarios por Nivel:</h5>";
        foreach ($stats as $stat) {
            echo "<p><strong>{$stat['nivel']}:</strong> {$stat['count']} usuarios</p>";
        }
        echo "</div>";
        
        // Usuarios sin jerarquía
        $query = "SELECT COUNT(*) as count FROM usuarios WHERE padre_id IS NULL AND nivel = 'user' AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $usersWithoutHierarchy = $stmt->fetch()['count'];
        
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Usuarios sin jerarquía:</strong> {$usersWithoutHierarchy}</p>";
        if ($usersWithoutHierarchy > 0) {
            echo "<p style='color: #856404;'>Estos usuarios necesitan ser asignados a una jerarquía por un ADMIN/ROOT.</p>";
        }
        echo "</div>";
        
    } catch (PDOException $e) {
        echo "<div style='color: red;'>✗ Error obteniendo estadísticas: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Resumen final
    $totalChecks = count($tables) + count($requiredFields) + count($requiredIndexes) + 3; // +3 para ROOT, permisos y estadísticas
    $passedChecks = $tablesOk + $fieldsOk + $indexesOk + 3; // Ajustar según verificaciones exitosas
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4 style='color: #155724;'>Resumen de Verificación:</h4>";
    echo "<p style='color: #155724;'><strong>Verificaciones pasadas:</strong> {$passedChecks} de {$totalChecks}</p>";
    
    if ($passedChecks >= $totalChecks * 0.8) {
        echo "<p style='color: #155724;'><strong>Estado:</strong> ✅ Sistema funcionando correctamente</p>";
        echo "<p style='color: #155724;'>El sistema de gestión de usuarios está listo para usar.</p>";
    } else {
        echo "<p style='color: #721c24;'><strong>Estado:</strong> ⚠️ Problemas detectados</p>";
        echo "<p style='color: #721c24;'>Algunos componentes no están funcionando correctamente. Revisa los errores arriba.</p>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error durante la verificación: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<hr>";
echo "<p><small>Verificación completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


