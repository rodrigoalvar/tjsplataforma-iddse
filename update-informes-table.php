<?php
/**
 * Script para actualizar la tabla de informes agregando campos DICOM
 */

require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Actualizar Tabla de Informes - Agregar Campos DICOM</h2>";

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Verificar y agregar campos individualmente
    $fieldsToAdd = [
        'study_instance_uid' => "VARCHAR(255) NULL COMMENT 'Study Instance UID del estudio DICOM'",
        'study_id' => "VARCHAR(255) NULL COMMENT 'Study ID del estudio DICOM'",
        'series_instance_uid' => "VARCHAR(255) NULL COMMENT 'Series Instance UID (opcional)'",
        'accession_number' => "VARCHAR(255) NULL COMMENT 'Accession Number del estudio'",
        'version' => "INT DEFAULT 1 COMMENT 'Versión del informe'",
        'fecha_modificacion' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última modificación'",
        'notas_revision' => "TEXT NULL COMMENT 'Notas de revisión'"
    ];
    
    $fieldsAdded = 0;
    foreach ($fieldsToAdd as $fieldName => $fieldDefinition) {
        $checkColumn = "SHOW COLUMNS FROM informes LIKE '{$fieldName}'";
        $result = $pdo->query($checkColumn);
        
        if ($result->rowCount() == 0) {
            try {
                $alterSql = "ALTER TABLE informes ADD COLUMN {$fieldName} {$fieldDefinition}";
                $pdo->exec($alterSql);
                echo "<p style='color: green;'>✓ Campo '{$fieldName}' agregado exitosamente</p>";
                $fieldsAdded++;
            } catch (PDOException $e) {
                echo "<p style='color: orange;'>Advertencia agregando campo '{$fieldName}': " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color: blue;'>ℹ Campo '{$fieldName}' ya existe</p>";
        }
    }
    
    if ($fieldsAdded > 0) {
        echo "<p style='color: green;'>✓ {$fieldsAdded} campos nuevos agregados</p>";
    }
        
        // Crear índices para los nuevos campos
        $indices = [
            "CREATE INDEX idx_informes_study_instance_uid ON informes(study_instance_uid)",
            "CREATE INDEX idx_informes_study_id ON informes(study_id)",
            "CREATE INDEX idx_informes_accession_number ON informes(accession_number)"
        ];
        
        foreach ($indices as $index_sql) {
            try {
                $pdo->exec($index_sql);
                echo "<p style='color: green;'>✓ Índice creado: " . substr($index_sql, 13, 50) . "...</p>";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                    echo "<p style='color: orange;'>Advertencia creando índice: " . $e->getMessage() . "</p>";
                }
            }
        }
    
    // Mostrar estructura actual de la tabla
    echo "<h3>Estructura actual de la tabla informes:</h3>";
    $columns = $pdo->query("SHOW COLUMNS FROM informes");
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Clave</th><th>Default</th><th>Extra</th></tr>";
    
    while ($column = $columns->fetch()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Actualizar informes existentes con datos de ejemplo
    echo "<h3>Actualizando informes existentes con datos DICOM de ejemplo:</h3>";
    
    $updateExisting = "
        UPDATE informes 
        SET 
            study_instance_uid = CONCAT('1.2.826.0.1.3680043.2.1125.', id, '.', UNIX_TIMESTAMP()),
            study_id = CONCAT('STUDY', LPAD(id, 6, '0')),
            accession_number = CONCAT('ACC', LPAD(id, 8, '0')),
            contenido_texto = REGEXP_REPLACE(contenido_html, '<[^>]+>', ''),
            fecha_modificacion = fecha_creacion
        WHERE study_instance_uid IS NULL
    ";
    
    $stmt = $pdo->prepare($updateExisting);
    $stmt->execute();
    $updated = $stmt->rowCount();
    
    echo "<p style='color: green;'>✓ {$updated} informes actualizados con datos DICOM</p>";
    
    echo "<h3>✅ Actualización completada exitosamente</h3>";
    echo "<p>La tabla informes ahora incluye los campos necesarios para vincular con estudios DICOM:</p>";
    echo "<ul>";
    echo "<li><strong>study_instance_uid:</strong> Para vincular con el Study Instance UID de DICOM</li>";
    echo "<li><strong>study_id:</strong> Para vincular con el Study ID</li>";
    echo "<li><strong>accession_number:</strong> Para vincular con el Accession Number</li>";
    echo "<li><strong>contenido_texto:</strong> Para búsquedas de texto</li>";
    echo "<li><strong>version:</strong> Para control de versiones</li>";
    echo "<li><strong>fecha_modificacion:</strong> Para tracking de cambios</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error general: " . $e->getMessage() . "</p>";
}

echo "<br><a href='components/informes-manager.html'>← Volver al Gestor de Informes</a>";
?>