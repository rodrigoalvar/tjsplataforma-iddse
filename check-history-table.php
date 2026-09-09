<?php
/**
 * Script para verificar y crear la tabla informes_historial si no existe
 */

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar si la tabla existe
    $checkTable = "SHOW TABLES LIKE 'informes_historial'";
    $stmt = $db->prepare($checkTable);
    $stmt->execute();
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        echo "Tabla informes_historial no existe. Creándola...\n";
        
        // Crear la tabla
        $createTable = "
        CREATE TABLE informes_historial (
            id INT PRIMARY KEY AUTO_INCREMENT,
            informe_id INT NOT NULL,
            version_anterior INT NOT NULL,
            contenido_html_anterior LONGTEXT,
            estado_anterior ENUM('borrador', 'finalizado', 'revisado', 'firmado'),
            usuario_modificacion INT NOT NULL,
            fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            motivo_cambio TEXT COMMENT 'Razón del cambio de versión',
            
            FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_modificacion) REFERENCES usuarios(id) ON DELETE RESTRICT,
            INDEX idx_informe_id (informe_id),
            INDEX idx_fecha_cambio (fecha_cambio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $db->exec($createTable);
        echo "Tabla informes_historial creada exitosamente.\n";
    } else {
        echo "Tabla informes_historial ya existe.\n";
    }
    
    // Verificar estructura de la tabla
    $describeTable = "DESCRIBE informes_historial";
    $stmt = $db->prepare($describeTable);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nEstructura de la tabla informes_historial:\n";
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']}\n";
    }
    
    // Verificar si hay datos
    $countQuery = "SELECT COUNT(*) as total FROM informes_historial";
    $stmt = $db->prepare($countQuery);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "\nTotal de registros en historial: {$count}\n";
    
    if ($count > 0) {
        // Mostrar algunos registros de ejemplo
        $sampleQuery = "SELECT * FROM informes_historial ORDER BY fecha_cambio DESC LIMIT 5";
        $stmt = $db->prepare($sampleQuery);
        $stmt->execute();
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nÚltimos 5 registros:\n";
        foreach ($samples as $sample) {
            echo "- ID: {$sample['id']}, Informe: {$sample['informe_id']}, Versión: {$sample['version_anterior']}, Fecha: {$sample['fecha_cambio']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
