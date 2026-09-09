<?php
header('Content-Type: text/html; charset=UTF-8');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "portal_estudios";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Análisis de Tablas de Antecedentes</h1>";
    
    // 1. Verificar estructura de study_antecedents
    echo "<h2>1. Estructura de tabla study_antecedents</h2>";
    $stmt = $pdo->query("DESCRIBE study_antecedents");
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. Verificar estructura de study_antecedents_files
    echo "<h2>2. Estructura de tabla study_antecedents_files</h2>";
    $stmt = $pdo->query("DESCRIBE study_antecedents_files");
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 3. Contar registros en ambas tablas
    echo "<h2>3. Conteo de registros</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM study_antecedents");
    $count1 = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM study_antecedents_files");
    $count2 = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "<p><strong>study_antecedents:</strong> $count1 registros</p>";
    echo "<p><strong>study_antecedents_files:</strong> $count2 registros</p>";
    
    // 4. Verificar datos para estudios específicos del usuario TUCUMAN
    echo "<h2>4. Datos de antecedentes para estudios del usuario TUCUMAN</h2>";
    
    // Primero obtener los study_ids del usuario TUCUMAN
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.study_id, s.study_instance_uid, s.patient_name
        FROM studies s 
        JOIN user_study_assignments usa ON s.study_id = usa.study_id 
        JOIN users u ON usa.user_id = u.user_id 
        WHERE u.username = 'TUCUMAN'
        ORDER BY s.study_id
        LIMIT 10
    ");
    $stmt->execute();
    $tucuman_studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Estudios asignados a TUCUMAN:</h3>";
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Study ID</th><th>Study Instance UID</th><th>Patient Name</th></tr>";
    foreach ($tucuman_studies as $study) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($study['study_id']) . "</td>";
        echo "<td>" . htmlspecialchars($study['study_instance_uid']) . "</td>";
        echo "<td>" . htmlspecialchars($study['patient_name']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 5. Verificar antecedentes en study_antecedents para estos estudios
    echo "<h3>Antecedentes en study_antecedents:</h3>";
    $study_ids = array_column($tucuman_studies, 'study_id');
    if (!empty($study_ids)) {
        $placeholders = str_repeat('?,', count($study_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT study_id, notes, created_date, created_by_name, created_by_surname
            FROM study_antecedents 
            WHERE study_id IN ($placeholders)
            ORDER BY study_id, created_date
        ");
        $stmt->execute($study_ids);
        $antecedents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Study ID</th><th>Notes</th><th>Created Date</th><th>Created By</th></tr>";
        foreach ($antecedents as $ant) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($ant['study_id']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($ant['notes'], 0, 100)) . "...</td>";
            echo "<td>" . htmlspecialchars($ant['created_date']) . "</td>";
            echo "<td>" . htmlspecialchars($ant['created_by_name'] . ' ' . $ant['created_by_surname']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 6. Verificar archivos en study_antecedents_files para estos estudios
        echo "<h3>Archivos en study_antecedents_files:</h3>";
        $stmt = $pdo->prepare("
            SELECT saf.study_id, saf.file_name, saf.file_type, saf.upload_date, saf.uploaded_by_name
            FROM study_antecedents_files saf
            WHERE saf.study_id IN ($placeholders)
            ORDER BY saf.study_id, saf.upload_date
        ");
        $stmt->execute($study_ids);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Study ID</th><th>File Name</th><th>File Type</th><th>Upload Date</th><th>Uploaded By</th></tr>";
        foreach ($files as $file) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($file['study_id']) . "</td>";
            echo "<td>" . htmlspecialchars($file['file_name']) . "</td>";
            echo "<td>" . htmlspecialchars($file['file_type']) . "</td>";
            echo "<td>" . htmlspecialchars($file['upload_date']) . "</td>";
            echo "<td>" . htmlspecialchars($file['uploaded_by_name']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 7. Análisis de contadores por estudio
        echo "<h2>5. Análisis de contadores por estudio</h2>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Study ID</th><th>Notas (study_antecedents)</th><th>Archivos (study_antecedents_files)</th><th>Total Antecedentes</th></tr>";
        
        foreach ($study_ids as $study_id) {
            // Contar notas
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_antecedents WHERE study_id = ?");
            $stmt->execute([$study_id]);
            $notes_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Contar archivos
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_antecedents_files WHERE study_id = ?");
            $stmt->execute([$study_id]);
            $files_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $total = $notes_count + $files_count;
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($study_id) . "</td>";
            echo "<td>" . $notes_count . "</td>";
            echo "<td>" . $files_count . "</td>";
            echo "<td><strong>" . $total . "</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>