<?php
/**
 * Script para crear tabla de informes
 */

require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Crear Tabla de Informes - TJSMEDICAL</h2>";

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Crear tabla de informes
    $informes_sql = "
        CREATE TABLE IF NOT EXISTS informes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estudio_id VARCHAR(255) NOT NULL,
            patient_id VARCHAR(255) NOT NULL,
            patient_name VARCHAR(255) NOT NULL,
            modality VARCHAR(50) NOT NULL,
            study_description TEXT,
            titulo VARCHAR(255) NOT NULL,
            contenido_html LONGTEXT NOT NULL,
            estado ENUM('borrador', 'finalizado', 'revisado') DEFAULT 'borrador',
            usuario_id INT,
            fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($informes_sql);
    echo "<p style='color: green;'>✓ Tabla 'informes' creada exitosamente</p>";
    
    // Crear tabla de audios de informes
    $audios_sql = "
        CREATE TABLE IF NOT EXISTS informe_audios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            informe_id INT NOT NULL,
            archivo_path VARCHAR(500) NOT NULL,
            duracion DECIMAL(10,2),
            sync_data JSON,
            fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($audios_sql);
    echo "<p style='color: green;'>✓ Tabla 'informe_audios' creada exitosamente</p>";
    
    // Crear índices
    $indices = [
        "CREATE INDEX idx_informes_estudio ON informes(estudio_id)",
        "CREATE INDEX idx_informes_patient ON informes(patient_id)",
        "CREATE INDEX idx_informes_modality ON informes(modality)",
        "CREATE INDEX idx_informes_estado ON informes(estado)",
        "CREATE INDEX idx_informes_fecha ON informes(fecha_creacion)",
        "CREATE INDEX idx_audios_informe ON informe_audios(informe_id)"
    ];
    
    $indices_creados = 0;
    foreach ($indices as $index_sql) {
        try {
            $pdo->exec($index_sql);
            $indices_creados++;
        } catch (PDOException $e) {
            // Ignorar errores de índices que ya existen
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "<p style='color: orange;'>Advertencia creando índice: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    echo "<p style='color: green;'>✓ {$indices_creados} índices creados</p>";
    
    // Insertar algunos informes de prueba
    $informes_prueba = [
        [
            'estudio_id' => 'STUDY001',
            'patient_id' => 'PAT001',
            'patient_name' => 'Juan Pérez',
            'modality' => 'RX',
            'study_description' => 'Radiografía de tórax',
            'titulo' => 'Informe de Radiografía de Tórax - Juan Pérez',
            'contenido_html' => '<h3>Informe Radiológico</h3><p><strong>Paciente:</strong> Juan Pérez</p><p><strong>Estudio:</strong> Radiografía de tórax</p><p><strong>Hallazgos:</strong> Campos pulmonares libres. Silueta cardíaca normal.</p><p><strong>Conclusión:</strong> Estudio normal.</p>',
            'estado' => 'finalizado'
        ],
        [
            'estudio_id' => 'STUDY002',
            'patient_id' => 'PAT002',
            'patient_name' => 'María García',
            'modality' => 'TC',
            'study_description' => 'TC de abdomen',
            'titulo' => 'Informe de TC de Abdomen - María García',
            'contenido_html' => '<h3>Informe Radiológico</h3><p><strong>Paciente:</strong> María García</p><p><strong>Estudio:</strong> TC de abdomen</p><p><strong>Hallazgos:</strong> Hígado de tamaño y morfología normal. Vesícula biliar sin alteraciones.</p><p><strong>Conclusión:</strong> TC de abdomen sin alteraciones significativas.</p>',
            'estado' => 'borrador'
        ],
        [
            'estudio_id' => 'STUDY003',
            'patient_id' => 'PAT003',
            'patient_name' => 'Carlos López',
            'modality' => 'RM',
            'study_description' => 'RM de rodilla',
            'titulo' => 'Informe de RM de Rodilla - Carlos López',
            'contenido_html' => '<h3>Informe Radiológico</h3><p><strong>Paciente:</strong> Carlos López</p><p><strong>Estudio:</strong> RM de rodilla derecha</p><p><strong>Hallazgos:</strong> Menisco medial con señal aumentada. Ligamentos cruzados íntegros.</p><p><strong>Conclusión:</strong> Lesión meniscal medial.</p>',
            'estado' => 'revisado'
        ]
    ];
    
    // Obtener ID del usuario de prueba
    $query = "SELECT id FROM usuarios WHERE email = 'test@tjsmedical.com' LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $user = $stmt->fetch();
    $user_id = $user ? $user['id'] : null;
    
    $query = "INSERT INTO informes (estudio_id, patient_id, patient_name, modality, study_description, titulo, contenido_html, estado, usuario_id) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($query);
    $informes_insertados = 0;
    
    foreach ($informes_prueba as $informe) {
        try {
            $result = $stmt->execute([
                $informe['estudio_id'],
                $informe['patient_id'],
                $informe['patient_name'],
                $informe['modality'],
                $informe['study_description'],
                $informe['titulo'],
                $informe['contenido_html'],
                $informe['estado'],
                $user_id
            ]);
            
            if ($result) {
                $informes_insertados++;
            }
        } catch (PDOException $e) {
            echo "<p style='color: orange;'>Advertencia insertando informe: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<p style='color: green;'>✓ {$informes_insertados} informes de prueba insertados</p>";
    
    echo "<hr>";
    echo "<h3>Base de datos configurada correctamente</h3>";
    echo "<p><a href='components/informes-manager.html'>Probar Gestor de Informes</a></p>";
    echo "<p><a href='test-session.php'>Verificar Sesión</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>Detalles: " . $e->getTraceAsString() . "</p>";
}
?>