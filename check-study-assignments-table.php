<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "Verificando tabla study_assignments...\n";
    
    $stmt = $pdo->query("DESCRIBE study_assignments");
    $columns = $stmt->fetchAll();
    
    echo "Estructura actual:\n";
    foreach($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    
    // Verificar si necesitamos crear la tabla
    if (empty($columns)) {
        echo "\nTabla no existe, creándola...\n";
        
        $createTable = "
        CREATE TABLE study_assignments (
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
            INDEX idx_inherited_from (inherited_from_user_id)
        )";
        
        $pdo->exec($createTable);
        echo "✓ Tabla creada exitosamente\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


