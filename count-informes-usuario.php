<?php
/**
 * Script para contar informes de un usuario por email
 * Uso: php count-informes-usuario.php email@ejemplo.com
 */

require_once __DIR__ . '/config/database.php';

$email = $argv[1] ?? 'kirylukfranco@gmail.com';

if (!$email) {
    echo "Uso: php count-informes-usuario.php email@ejemplo.com\n";
    exit(1);
}

try {
    $db = getDBConnection();
    
    if (!$db) {
        echo "❌ Error: No se pudo conectar a la base de datos\n";
        exit(1);
    }
    
    echo "=== CONTEO DE INFORMES POR USUARIO ===\n\n";
    echo "Email: $email\n\n";
    
    // Primero obtener el ID del usuario
    $stmt = $db->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE email = ? AND activo = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "❌ Usuario no encontrado o inactivo\n";
        exit(1);
    }
    
    echo "✅ Usuario encontrado:\n";
    echo "   ID: {$user['id']}\n";
    echo "   Nombre: {$user['nombre']} {$user['apellido']}\n";
    echo "   Email: {$user['email']}\n\n";
    
    // Contar todos los informes del usuario
    $countQuery = "SELECT COUNT(*) as total 
                   FROM informes i 
                   WHERE i.usuario_id = ?";
    
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute([$user['id']]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "📊 Total de informes: $total\n\n";
    
    // Contar por estado
    $statusQuery = "SELECT estado, COUNT(*) as cantidad 
                    FROM informes 
                    WHERE usuario_id = ? 
                    GROUP BY estado 
                    ORDER BY cantidad DESC";
    
    $statusStmt = $db->prepare($statusQuery);
    $statusStmt->execute([$user['id']]);
    $statuses = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($statuses)) {
        echo "📋 Informes por estado:\n";
        foreach ($statuses as $status) {
            echo "   - {$status['estado']}: {$status['cantidad']}\n";
        }
        echo "\n";
    }
    
    // Contar por modalidad
    $modalityQuery = "SELECT modality, COUNT(*) as cantidad 
                      FROM informes 
                      WHERE usuario_id = ? AND modality IS NOT NULL
                      GROUP BY modality 
                      ORDER BY cantidad DESC";
    
    $modalityStmt = $db->prepare($modalityQuery);
    $modalityStmt->execute([$user['id']]);
    $modalities = $modalityStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($modalities)) {
        echo "🔬 Informes por modalidad:\n";
        foreach ($modalities as $modality) {
            echo "   - {$modality['modality']}: {$modality['cantidad']}\n";
        }
        echo "\n";
    }
    
    // Información adicional: fechas
    $dateQuery = "SELECT 
                    MIN(fecha_creacion) as primer_informe,
                    MAX(fecha_creacion) as ultimo_informe,
                    COUNT(DISTINCT DATE(fecha_creacion)) as dias_con_informes
                  FROM informes 
                  WHERE usuario_id = ?";
    
    $dateStmt = $db->prepare($dateQuery);
    $dateStmt->execute([$user['id']]);
    $dates = $dateStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dates['primer_informe']) {
        echo "📅 Información de fechas:\n";
        echo "   - Primer informe: {$dates['primer_informe']}\n";
        echo "   - Último informe: {$dates['ultimo_informe']}\n";
        echo "   - Días con informes: {$dates['dias_con_informes']}\n";
    }
    
    echo "\n✅ Consulta completada\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>



