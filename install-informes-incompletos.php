<?php
/**
 * Script de instalación para funcionalidad de Informes Incompletos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script:
 * 1. Crea la tabla study_flags si no existe
 * 2. Agrega el permiso marcar_incompletos a system_permissions
 */

require_once __DIR__ . '/config/database.php';

$db = getDBConnection();

if (!$db) {
    die("❌ Error: No se pudo conectar a la base de datos.\n");
}

echo "🚀 Instalando funcionalidad de Informes Incompletos...\n\n";

try {
    // 1. Crear tabla study_flags
    echo "📋 Creando tabla study_flags...\n";
    
    $createTableSQL = "
    CREATE TABLE IF NOT EXISTS study_flags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        study_id VARCHAR(255) NOT NULL COMMENT 'ID del estudio (orthanc_id o study_instance_uid)',
        orthanc_id VARCHAR(255) NULL COMMENT 'Orthanc ID del estudio',
        study_instance_uid VARCHAR(255) NULL COMMENT 'Study Instance UID del estudio',
        user_id INT NOT NULL COMMENT 'ID del usuario para el cual aplica el flag',
        informes_incompletos BOOLEAN DEFAULT FALSE COMMENT 'Indica si el estudio tiene informes incompletos',
        nota TEXT NULL COMMENT 'Nota opcional sobre los informes incompletos',
        prioridad VARCHAR(20) DEFAULT 'normal' COMMENT 'Prioridad del estudio: normal, promesa, urgente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación del flag',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
        created_by INT NULL COMMENT 'ID del usuario que creó el flag',
        
        UNIQUE KEY unique_study_user (study_id, user_id),
        INDEX idx_study_id (study_id),
        INDEX idx_orthanc_id (orthanc_id),
        INDEX idx_study_instance_uid (study_instance_uid),
        INDEX idx_user_id (user_id),
        INDEX idx_informes_incompletos (informes_incompletos),
        INDEX idx_prioridad (prioridad),
        INDEX idx_created_by (created_by),
        
        FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $db->exec($createTableSQL);
    echo "✅ Tabla study_flags creada correctamente.\n\n";
    
    // 2. Agregar permiso marcar_incompletos
    echo "🔐 Agregando permiso marcar_incompletos...\n";
    
    $insertPermissionSQL = "
    INSERT INTO system_permissions (permission_key, permission_name, description, category) 
    VALUES 
    ('marcar_incompletos', 'Marcar Incompletos', 'Permite marcar y desmarcar estudios como informes incompletos', 'informes')
    ON DUPLICATE KEY UPDATE 
        permission_name = VALUES(permission_name),
        description = VALUES(description),
        category = VALUES(category);
    ";
    
    $db->exec($insertPermissionSQL);
    echo "✅ Permiso marcar_incompletos agregado correctamente.\n\n";
    
    echo "✨ Instalación completada exitosamente!\n\n";
    echo "📝 Próximos pasos:\n";
    echo "   1. Asignar el permiso 'marcar_incompletos' a los usuarios que necesiten marcar informes como incompletos\n";
    echo "   2. Los usuarios con este permiso verán un botón de advertencia (⚠️) en cada informe\n";
    echo "   3. Pueden usar el filtro 'Incompletos' en el dropdown de estado para ver solo estudios marcados como incompletos\n";
    
} catch (PDOException $e) {
    echo "❌ Error durante la instalación: " . $e->getMessage() . "\n";
    exit(1);
}
?>

