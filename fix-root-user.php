<?php
/**
 * Script para verificar y corregir el usuario ROOT
 */

require_once 'config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    echo "=== VERIFICACIÓN Y CORRECCIÓN DEL USUARIO ROOT ===\n\n";
    
    // 1. Verificar si existe usuario con ID: 1
    echo "1. Verificando usuario con ID: 1...\n";
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = 1");
    $stmt->execute();
    $rootUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($rootUser) {
        echo "   ✓ Usuario encontrado:\n";
        echo "     - ID: {$rootUser['id']}\n";
        echo "     - Nombre: {$rootUser['nombre']} {$rootUser['apellido']}\n";
        echo "     - Email: {$rootUser['email']}\n";
        echo "     - Nivel: {$rootUser['nivel']}\n";
        echo "     - Activo: {$rootUser['activo']}\n";
        echo "     - Permisos: {$rootUser['permisos']}\n\n";
        
        // Verificar si es ROOT
        if ($rootUser['nivel'] !== 'root') {
            echo "   ❌ PROBLEMA: El usuario no tiene nivel 'root'\n";
            echo "   🔧 CORRIGIENDO: Actualizando nivel a 'root'...\n";
            
            $updateStmt = $pdo->prepare("UPDATE usuarios SET nivel = 'root', permisos = '[\"all\"]' WHERE id = 1");
            if ($updateStmt->execute()) {
                echo "   ✅ CORREGIDO: Usuario actualizado a nivel ROOT\n";
            } else {
                echo "   ❌ ERROR: No se pudo actualizar el usuario\n";
            }
        } else {
            echo "   ✅ CORRECTO: El usuario ya tiene nivel 'root'\n";
        }
        
        // Verificar si está activo
        if ($rootUser['activo'] != 1) {
            echo "   ❌ PROBLEMA: El usuario está inactivo\n";
            echo "   🔧 CORRIGIENDO: Activando usuario...\n";
            
            $updateStmt = $pdo->prepare("UPDATE usuarios SET activo = 1 WHERE id = 1");
            if ($updateStmt->execute()) {
                echo "   ✅ CORREGIDO: Usuario activado\n";
            } else {
                echo "   ❌ ERROR: No se pudo activar el usuario\n";
            }
        } else {
            echo "   ✅ CORRECTO: El usuario está activo\n";
        }
        
    } else {
        echo "   ❌ PROBLEMA: No existe usuario con ID: 1\n";
        echo "   🔧 CORRIGIENDO: Creando usuario ROOT...\n";
        
        $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare("
            INSERT INTO usuarios 
            (id, nombre, apellido, email, telefono, matricula_profesional, 
             password_hash, nivel, padre_id, especialidad, permisos, 
             activo, created_at) 
            VALUES 
            (1, 'Usuario', 'ROOT', 'root@system.com', '0000000000', 'ROOT001', 
             ?, 'root', NULL, 'Sistema', '[\"all\"]', 
             1, NOW())
        ");
        
        if ($insertStmt->execute([$password_hash])) {
            echo "   ✅ CORREGIDO: Usuario ROOT creado\n";
        } else {
            echo "   ❌ ERROR: No se pudo crear el usuario ROOT\n";
        }
    }
    
    echo "\n";
    
    // 2. Verificar usuario objetivo (ID: 10)
    echo "2. Verificando usuario objetivo (ID: 10)...\n";
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = 10");
    $stmt->execute();
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($targetUser) {
        echo "   ✓ Usuario objetivo encontrado:\n";
        echo "     - ID: {$targetUser['id']}\n";
        echo "     - Nombre: {$targetUser['nombre']} {$targetUser['apellido']}\n";
        echo "     - Email: {$targetUser['email']}\n";
        echo "     - Nivel: {$targetUser['nivel']}\n";
        echo "     - Activo: {$targetUser['activo']}\n";
    } else {
        echo "   ❌ PROBLEMA: No existe usuario con ID: 10\n";
        echo "   🔧 CORRIGIENDO: Creando usuario de prueba...\n";
        
        $password_hash = password_hash('user123', PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare("
            INSERT INTO usuarios 
            (nombre, apellido, email, telefono, matricula_profesional, 
             password_hash, nivel, padre_id, especialidad, permisos, 
             activo, created_at) 
            VALUES 
            ('Usuario', 'Prueba', 'usuario@test.com', '1111111111', 'TEST001', 
             ?, 'user', NULL, 'Prueba', '[\"dashboard\", \"informes\"]', 
             1, NOW())
        ");
        
        if ($insertStmt->execute([$password_hash])) {
            $newUserId = $pdo->lastInsertId();
            echo "   ✅ CORREGIDO: Usuario de prueba creado con ID: {$newUserId}\n";
        } else {
            echo "   ❌ ERROR: No se pudo crear el usuario de prueba\n";
        }
    }
    
    echo "\n";
    
    // 3. Verificar estadísticas generales
    echo "3. Estadísticas generales:\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE activo = 1");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   - Total usuarios activos: {$total}\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE nivel = 'root'");
    $rootCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   - Usuarios ROOT: {$rootCount}\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE nivel = 'admin'");
    $adminCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   - Usuarios ADMIN: {$adminCount}\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE nivel = 'user'");
    $userCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   - Usuarios USER: {$userCount}\n";
    
    echo "\n";
    
    // 4. Verificar tabla de asignaciones
    echo "4. Verificando tabla study_assignments...\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM study_assignments");
        $assignmentsCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "   ✓ Tabla existe con {$assignmentsCount} asignaciones\n";
    } catch (Exception $e) {
        echo "   ❌ PROBLEMA: Tabla study_assignments no existe\n";
        echo "   🔧 CORRIGIENDO: Creando tabla...\n";
        
        $createTableSQL = "
            CREATE TABLE IF NOT EXISTS study_assignments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                study_id VARCHAR(255) NOT NULL,
                user_id INT NOT NULL,
                assigned_by INT NOT NULL,
                assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('active', 'inactive') DEFAULT 'active',
                INDEX idx_study_user (study_id, user_id),
                INDEX idx_user_id (user_id),
                INDEX idx_assigned_by (assigned_by),
                FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_by) REFERENCES usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        if ($pdo->exec($createTableSQL)) {
            echo "   ✅ CORREGIDO: Tabla study_assignments creada\n";
        } else {
            echo "   ❌ ERROR: No se pudo crear la tabla\n";
        }
    }
    
    echo "\n";
    echo "=== VERIFICACIÓN COMPLETADA ===\n";
    echo "Ahora puedes probar la asignación de estudios nuevamente.\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
