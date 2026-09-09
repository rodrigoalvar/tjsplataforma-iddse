<?php
/**
 * Script para agregar campos DICOM a la tabla usuarios
 */

$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== AGREGANDO CAMPOS DICOM A LA TABLA USUARIOS ===\n\n";
    
    // Verificar si las columnas ya existen
    $checkColumns = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'dicom_%'");
    $existingColumns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
    
    $columnsToAdd = [
        ['dicom_aetitle', 'VARCHAR(50) NULL COMMENT \'Application Entity Title (AE Title) para DICOM\''],
        ['dicom_puerto', 'INT NULL COMMENT \'Puerto para conexión DICOM\''],
        ['dicom_ip', 'VARCHAR(45) NULL COMMENT \'Dirección IP para conexión DICOM\'']
    ];
    
    foreach ($columnsToAdd as $column) {
        $columnName = $column[0];
        $columnDef = $column[1];
        
        if (in_array($columnName, $existingColumns)) {
            echo "⚠️  La columna {$columnName} ya existe, omitiendo...\n";
        } else {
            try {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN {$columnName} {$columnDef}");
                echo "✅ Columna {$columnName} agregada correctamente\n";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                    echo "⚠️  La columna {$columnName} ya existe\n";
                } else {
                    throw $e;
                }
            }
        }
    }
    
    echo "\n✅ CAMPOS DICOM AGREGADOS CORRECTAMENTE\n";
    
    // Verificar columnas DICOM
    echo "\n📋 COLUMNAS DICOM EN LA TABLA USUARIOS:\n";
    $stmt = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_COMMENT 
                         FROM INFORMATION_SCHEMA.COLUMNS 
                         WHERE TABLE_SCHEMA = 'tjsmedical' 
                           AND TABLE_NAME = 'usuarios' 
                           AND COLUMN_NAME LIKE 'dicom_%'");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "   • {$col['COLUMN_NAME']} ({$col['DATA_TYPE']}) - {$col['COLUMN_COMMENT']}\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

