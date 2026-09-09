<?php
/**
 * Script para mejorar el sistema de jerarquías
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== MEJORANDO SISTEMA DE JERARQUÍAS ===\n";
    
    // 1. Verificar estructura actual
    echo "1. Verificando estructura actual...\n";
    $stmt = $pdo->query("DESCRIBE usuarios");
    $columns = $stmt->fetchAll();
    
    $hasNivel = false;
    $hasPadreId = false;
    
    foreach ($columns as $column) {
        if ($column['Field'] === 'nivel') $hasNivel = true;
        if ($column['Field'] === 'padre_id') $hasPadreId = true;
    }
    
    echo "   - Campo 'nivel': " . ($hasNivel ? "✓ Existe" : "✗ No existe") . "\n";
    echo "   - Campo 'padre_id': " . ($hasPadreId ? "✓ Existe" : "✗ No existe") . "\n";
    
    // 2. Crear tabla de asignaciones de estudios si no existe
    echo "\n2. Creando tabla de asignaciones de estudios...\n";
    
    $createAssignmentsTable = "
    CREATE TABLE IF NOT EXISTS study_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        study_id VARCHAR(255) NOT NULL,
        assigned_to_user_id INT NOT NULL,
        assigned_by_user_id INT NOT NULL,
        assignment_type ENUM('direct', 'inherited') DEFAULT 'direct',
        inherited_from_user_id INT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_study_user (study_id, assigned_to_user_id),
        INDEX idx_assigned_to (assigned_to_user_id),
        INDEX idx_assigned_by (assigned_by_user_id),
        INDEX idx_inherited_from (inherited_from_user_id),
        FOREIGN KEY (assigned_to_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (assigned_by_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (inherited_from_user_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )";
    
    $pdo->exec($createAssignmentsTable);
    echo "   ✓ Tabla study_assignments creada/verificada\n";
    
    // 3. Crear función para obtener jerarquía
    echo "\n3. Creando función para obtener jerarquía...\n";
    
    $createFunction = "
    CREATE FUNCTION IF NOT EXISTS GetUserHierarchy(userId INT) 
    RETURNS TEXT
    READS SQL DATA
    DETERMINISTIC
    BEGIN
        DECLARE hierarchy TEXT DEFAULT '';
        DECLARE currentUserId INT DEFAULT userId;
        DECLARE parentId INT DEFAULT NULL;
        DECLARE userName VARCHAR(200) DEFAULT '';
        
        WHILE currentUserId IS NOT NULL DO
            SELECT padre_id, CONCAT(nombre, ' ', apellido) 
            INTO parentId, userName
            FROM usuarios 
            WHERE id = currentUserId AND activo = 1;
            
            IF hierarchy = '' THEN
                SET hierarchy = userName;
            ELSE
                SET hierarchy = CONCAT(userName, ' > ', hierarchy);
            END IF;
            
            SET currentUserId = parentId;
        END WHILE;
        
        RETURN hierarchy;
    END";
    
    try {
        $pdo->exec($createFunction);
        echo "   ✓ Función GetUserHierarchy creada\n";
    } catch (Exception $e) {
        echo "   ⚠ Función ya existe o error: " . $e->getMessage() . "\n";
    }
    
    // 4. Crear vista para usuarios con jerarquía
    echo "\n4. Creando vista de usuarios con jerarquía...\n";
    
    $createView = "
    CREATE OR REPLACE VIEW usuarios_con_jerarquia AS
    SELECT 
        u.id,
        u.nombre,
        u.apellido,
        u.email,
        u.telefono,
        u.matricula_profesional,
        u.nivel,
        u.padre_id,
        u.especialidad,
        u.activo,
        u.permisos,
        u.created_at,
        p.nombre as padre_nombre,
        p.apellido as padre_apellido,
        p.email as padre_email,
        p.nivel as padre_nivel,
        GetUserHierarchy(u.id) as jerarquia_completa,
        (SELECT COUNT(*) FROM usuarios h WHERE h.padre_id = u.id AND h.activo = 1) as hijos_count,
        (SELECT COUNT(*) FROM usuarios d WHERE d.padre_id = u.id AND d.activo = 1) as dependientes_count
    FROM usuarios u
    LEFT JOIN usuarios p ON u.padre_id = p.id
    WHERE u.activo = 1
    ORDER BY u.nivel DESC, u.nombre ASC";
    
    $pdo->exec($createView);
    echo "   ✓ Vista usuarios_con_jerarquia creada\n";
    
    // 5. Insertar datos de ejemplo si no existen usuarios ROOT/ADMIN
    echo "\n5. Verificando usuarios administrativos...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usuarios WHERE nivel IN ('root', 'admin') AND activo = 1");
    $adminCount = $stmt->fetch()['count'];
    
    if ($adminCount == 0) {
        echo "   - No hay usuarios administrativos, creando usuario ROOT...\n";
        
        $insertRoot = "
        INSERT INTO usuarios (
            nombre, apellido, email, telefono, matricula_profesional, 
            password_hash, nivel, padre_id, especialidad, activo, permisos
        ) VALUES (
            'Root', 'Administrator', 'root@portal.com', '0000000000', 'ROOT001',
            ?, 'root', NULL, 'Administración', 1, ?
        )";
        
        $stmt = $pdo->prepare($insertRoot);
        $stmt->execute([
            password_hash('admin123', PASSWORD_DEFAULT),
            json_encode(['all'])
        ]);
        
        echo "   ✓ Usuario ROOT creado (root@portal.com / admin123)\n";
    } else {
        echo "   ✓ Usuarios administrativos encontrados: $adminCount\n";
    }
    
    // 6. Mostrar estructura de jerarquías actual
    echo "\n6. Estructura de jerarquías actual:\n";
    
    $stmt = $pdo->query("
        SELECT 
            id, nombre, apellido, email, nivel, padre_id,
            padre_nombre, padre_apellido, jerarquia_completa, dependientes_count
        FROM usuarios_con_jerarquia 
        ORDER BY nivel DESC, nombre ASC
    ");
    $users = $stmt->fetchAll();
    
    foreach ($users as $user) {
        $hierarchy = $user['jerarquia_completa'] ?: 'Sin jerarquía';
        $dependents = $user['dependientes_count'] > 0 ? " ({$user['dependientes_count']} dependientes)" : '';
        
        echo "   - ID {$user['id']}: {$user['nombre']} {$user['apellido']} ({$user['nivel']}) - {$hierarchy}{$dependents}\n";
    }
    
    echo "\n=== SISTEMA DE JERARQUÍAS MEJORADO ===\n";
    echo "✓ Base de datos actualizada\n";
    echo "✓ Tabla de asignaciones creada\n";
    echo "✓ Función de jerarquía creada\n";
    echo "✓ Vista de usuarios creada\n";
    echo "✓ Usuarios administrativos verificados\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


