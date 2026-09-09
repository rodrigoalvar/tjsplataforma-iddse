<?php
/**
 * Script para crear la tabla audios_informe
 */

require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Crear Tabla audios_informe - TJSMEDICAL</h2>";

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Leer el archivo SQL
    $sqlFile = 'database/informes_audios.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Dividir en declaraciones individuales
    $statements = explode(';', $sql);
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $success_count++;
            echo "<p style='color: green;'>✓ Ejecutado: " . substr($statement, 0, 50) . "...</p>";
        } catch (PDOException $e) {
            $error_count++;
            echo "<p style='color: orange;'>⚠ Error (puede ser normal si ya existe): " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<hr>";
    echo "<p><strong>Resumen:</strong></p>";
    echo "<p>Declaraciones ejecutadas exitosamente: $success_count</p>";
    echo "<p>Errores (normales si las tablas ya existen): $error_count</p>";
    
    // Verificar que la tabla existe
    $checkQuery = "SHOW TABLES LIKE 'audios_informe'";
    $result = $pdo->query($checkQuery);
    
    if ($result->rowCount() > 0) {
        echo "<p style='color: green; font-weight: bold;'>✅ Tabla 'audios_informe' existe correctamente</p>";
        
        // Mostrar estructura de la tabla
        $describeQuery = "DESCRIBE audios_informe";
        $columns = $pdo->query($describeQuery)->fetchAll();
        
        echo "<h3>Estructura de la tabla audios_informe:</h3>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>{$column['Field']}</td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
            echo "<td>{$column['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<p style='color: red; font-weight: bold;'>❌ Error: La tabla 'audios_informe' no se creó correctamente</p>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red; border: 1px solid red; padding: 10px; margin: 10px 0;'>";
    echo "<h3>Error:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<br><a href='components/informes-manager.html'>← Volver al Gestor de Informes</a>";
?>