<?php
/**
 * Script para migrar datos existentes y crear historial para informes con múltiples versiones
 */

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Iniciando migración de historial de versiones...\n";
    
    // Buscar informes con múltiples versiones
    $query = "
        SELECT 
            estudio_id,
            usuario_id,
            COUNT(*) as total_versiones,
            GROUP_CONCAT(id ORDER BY version ASC) as informe_ids,
            GROUP_CONCAT(version ORDER BY version ASC) as versiones
        FROM informes 
        GROUP BY estudio_id, usuario_id 
        HAVING COUNT(*) > 1
        ORDER BY estudio_id, usuario_id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Encontrados " . count($grupos) . " grupos de informes con múltiples versiones.\n";
    
    $totalMigrados = 0;
    
    foreach ($grupos as $grupo) {
        echo "\nProcesando grupo: Estudio {$grupo['estudio_id']}, Usuario {$grupo['usuario_id']} ({$grupo['total_versiones']} versiones)\n";
        
        $informeIds = explode(',', $grupo['informe_ids']);
        $versiones = explode(',', $grupo['versiones']);
        
        // Obtener detalles de cada versión
        $detallesQuery = "
            SELECT id, version, contenido_html, estado, usuario_id, fecha_modificacion
            FROM informes 
            WHERE id IN (" . implode(',', array_fill(0, count($informeIds), '?')) . ")
            ORDER BY version ASC
        ";
        
        $detallesStmt = $db->prepare($detallesQuery);
        $detallesStmt->execute($informeIds);
        $detalles = $detallesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Crear historial para cada versión anterior
        for ($i = 1; $i < count($detalles); $i++) {
            $versionActual = $detalles[$i];
            $versionAnterior = $detalles[$i - 1];
            
            // Verificar si ya existe el historial
            $checkQuery = "SELECT COUNT(*) FROM informes_historial WHERE informe_id = ? AND version_anterior = ?";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$versionActual['id'], $versionAnterior['version']]);
            $existe = $checkStmt->fetchColumn() > 0;
            
            if (!$existe) {
                // Insertar en historial
                $insertQuery = "
                    INSERT INTO informes_historial 
                    (informe_id, version_anterior, contenido_html_anterior, estado_anterior, usuario_modificacion, motivo_cambio, fecha_cambio)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ";
                
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->execute([
                    $versionActual['id'],
                    $versionAnterior['version'],
                    $versionAnterior['contenido_html'],
                    $versionAnterior['estado'],
                    $versionActual['usuario_id'],
                    'Migración automática de versiones existentes',
                    $versionActual['fecha_modificacion']
                ]);
                
                echo "  ✓ Creado historial: Informe {$versionActual['id']} (v{$versionActual['version']}) <- v{$versionAnterior['version']}\n";
                $totalMigrados++;
            } else {
                echo "  - Historial ya existe: Informe {$versionActual['id']} (v{$versionActual['version']}) <- v{$versionAnterior['version']}\n";
            }
        }
    }
    
    echo "\nMigración completada. Total de registros de historial creados: {$totalMigrados}\n";
    
    // Verificar resultado final
    $countQuery = "SELECT COUNT(*) as total FROM informes_historial";
    $stmt = $db->prepare($countQuery);
    $stmt->execute();
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "Total de registros en historial: {$total}\n";
    
} catch (Exception $e) {
    echo "Error durante la migración: " . $e->getMessage() . "\n";
}
?>
