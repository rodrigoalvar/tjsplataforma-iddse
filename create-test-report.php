<?php
/**
 * Script para crear un informe de ejemplo completo para pruebas
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once 'config/database.php';

try {
    // Obtener conexión a la base de datos
    $db = getDBConnection();
    // Datos del informe de ejemplo
    $reportData = [
        'estudio_id' => 'TEST_STUDY_001',
        'usuario_id' => 1, // Asumiendo que existe un usuario con ID 1
        'patient_id' => 'PAT_001',
        'patient_name' => 'Juan Pérez García',
        'modality' => 'CT',
        'study_description' => 'TC de Tórax con contraste',
        'titulo' => 'TC de Tórax - Evaluación de nódulo pulmonar',
        'contenido_html' => '<h2>TÉCNICA</h2><p>Se realizó estudio de tomografía computarizada de tórax con administración de contraste endovenoso.</p><h2>HALLAZGOS</h2><p><strong>Pulmones:</strong> Se identifica nódulo sólido de 8mm en lóbulo superior derecho, de contornos regulares. No se observan otras lesiones nodulares significativas.</p><p><strong>Mediastino:</strong> Estructuras mediastinales de aspecto normal. No adenopatías significativas.</p><p><strong>Pleura:</strong> Sin evidencia de derrame pleural bilateral.</p><h2>IMPRESIÓN DIAGNÓSTICA</h2><p>1. Nódulo pulmonar sólido de 8mm en lóbulo superior derecho.</p><p>2. Resto del estudio sin alteraciones significativas.</p><h2>RECOMENDACIONES</h2><p>Se sugiere seguimiento con TC de tórax en 6 meses para evaluar estabilidad del nódulo descrito.</p>',
        'contenido_texto' => 'TÉCNICA\nSe realizó estudio de tomografía computarizada de tórax con administración de contraste endovenoso.\n\nHALLAZGOS\nPulmones: Se identifica nódulo sólido de 8mm en lóbulo superior derecho, de contornos regulares. No se observan otras lesiones nodulares significativas.\nMediastino: Estructuras mediastinales de aspecto normal. No adenopatías significativas.\nPleura: Sin evidencia de derrame pleural bilateral.\n\nIMPRESIÓN DIAGNÓSTICA\n1. Nódulo pulmonar sólido de 8mm en lóbulo superior derecho.\n2. Resto del estudio sin alteraciones significativas.\n\nRECOMENDACIONES\nSe sugiere seguimiento con TC de tórax en 6 meses para evaluar estabilidad del nódulo descrito.',
        'estado' => 'finalizado',
        'version' => 1,
        'notas_revision' => 'Informe de ejemplo para pruebas del sistema'
    ];
    
    // Insertar informe
    $query = "INSERT INTO informes (
                estudio_id, usuario_id, patient_id, patient_name, 
                modality, study_description, titulo, contenido_html, 
                contenido_texto, estado, version, notas_revision,
                fecha_creacion, fecha_modificacion
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        $reportData['estudio_id'],
        $reportData['usuario_id'],
        $reportData['patient_id'],
        $reportData['patient_name'],
        $reportData['modality'],
        $reportData['study_description'],
        $reportData['titulo'],
        $reportData['contenido_html'],
        $reportData['contenido_texto'],
        $reportData['estado'],
        $reportData['version'],
        $reportData['notas_revision']
    ]);
    
    $reportId = $db->lastInsertId();
    
    echo "<h2>Informe de ejemplo creado exitosamente</h2>";
    echo "<p><strong>ID del informe:</strong> {$reportId}</p>";
    echo "<p><strong>Paciente:</strong> {$reportData['patient_name']}</p>";
    echo "<p><strong>Estudio:</strong> {$reportData['study_description']}</p>";
    echo "<p><strong>Estado:</strong> {$reportData['estado']}</p>";
    echo "<p><strong>Versión:</strong> {$reportData['version']}</p>";
    
    // Crear algunos audios de ejemplo (opcional)
    $audioData = [
        [
            'informe_id' => $reportId,
            'nombre_archivo' => 'audio_tecnica.wav',
            'ruta_archivo' => 'uploads/audios/audio_tecnica_' . $reportId . '.wav',
            'tipo_grabacion' => 'normal',
            'duracion_segundos' => 45,
            'tamano_bytes' => 1024000,
             'tipo_mime' => 'audio/webm',
              'transcripcion_texto' => 'Ejemplo de transcripción del audio de dictado médico.'
        ],
        [
            'informe_id' => $reportId,
            'nombre_archivo' => 'audio_hallazgos.wav',
            'ruta_archivo' => 'uploads/audios/audio_hallazgos_' . $reportId . '.wav',
            'tipo_grabacion' => 'normal',
            'duracion_segundos' => 120,
            'tamano_bytes' => 2048000,
             'tipo_mime' => 'audio/webm',
              'transcripcion_texto' => 'Ejemplo de transcripción del audio de dictado médico.'
        ]
    ];
    
    foreach ($audioData as $audio) {
        $audioQuery = "INSERT INTO audios_informe (
                         informe_id, estudio_id, usuario_id, tipo_grabacion, 
                         nombre_archivo, ruta_archivo, duracion_segundos, 
                         tamano_bytes, tipo_mime, transcripcion_texto
                       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $audioStmt = $db->prepare($audioQuery);
        $audioStmt->execute([
             $reportId,
             $reportData['estudio_id'],
             $reportData['usuario_id'],
             $audio['tipo_grabacion'],
             $audio['nombre_archivo'],
             $audio['ruta_archivo'],
             $audio['duracion_segundos'],
             $audio['tamano_bytes'],
             $audio['tipo_mime'],
             $audio['transcripcion_texto']
         ]);
    }
    
    echo "<p><strong>Audios de ejemplo:</strong> 2 archivos creados</p>";
    echo "<hr>";
    echo "<p><a href='components/informes-manager.html' class='btn btn-primary'>Ir a Gestión de Informes</a></p>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h3>Error al crear informe de ejemplo:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h3>Error de base de datos:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>

<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Crear Informe de Ejemplo</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='row justify-content-center'>
            <div class='col-md-8'>
                <div class='card'>
                    <div class='card-body'>
                        <!-- El contenido PHP se muestra aquí -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>