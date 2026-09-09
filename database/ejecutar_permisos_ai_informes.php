<?php
/**
 * Script para agregar permisos de AI Informes: Transcribir con AI y Generar Informe con AI
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Uso: php database/ejecutar_permisos_ai_informes.php
 */

require_once __DIR__ . '/../config/database.php';

echo "🔧 Agregando permisos de AI Informes...\n\n";

try {
    $pdo = getDBConnection();
    
    $permisos = [
        [
            'permission_key' => 'transcribir_ai',
            'permission_name' => 'Transcribir con AI',
            'description' => 'Permite transcribir audios usando Whisper.cpp (AI)',
            'category' => 'ai_informes'
        ],
        [
            'permission_key' => 'generar_informe_ai',
            'permission_name' => 'Generar Informe con AI',
            'description' => 'Permite generar informes médicos usando Medgemma (AI)',
            'category' => 'ai_informes'
        ]
    ];
    
    $agregados = 0;
    $actualizados = 0;
    
    foreach ($permisos as $permiso) {
        // Verificar si existe
        $checkQuery = "SELECT permission_key, category FROM system_permissions WHERE permission_key = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$permiso['permission_key']]);
        $existe = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existe) {
            // Actualizar si cambió la categoría o descripción
            $updateQuery = "UPDATE system_permissions 
                            SET permission_name = ?,
                                description = ?,
                                category = ?
                            WHERE permission_key = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([
                $permiso['permission_name'],
                $permiso['description'],
                $permiso['category'],
                $permiso['permission_key']
            ]);
            if ($updateStmt->rowCount() > 0) {
                echo "   🔄 Actualizado: {$permiso['permission_name']} ({$permiso['category']})\n";
                $actualizados++;
            } else {
                echo "   ✅ Ya existe: {$permiso['permission_name']}\n";
            }
        } else {
            // Insertar nuevo
            $insertQuery = "INSERT INTO system_permissions (permission_key, permission_name, description, category) 
                           VALUES (?, ?, ?, ?)";
            $insertStmt = $pdo->prepare($insertQuery);
            $insertStmt->execute([
                $permiso['permission_key'],
                $permiso['permission_name'],
                $permiso['description'],
                $permiso['category']
            ]);
            echo "   ➕ Agregado: {$permiso['permission_name']} ({$permiso['category']})\n";
            $agregados++;
        }
    }
    
    echo "\n✅ Proceso completado:\n";
    echo "   • Agregados: {$agregados}\n";
    echo "   • Actualizados: {$actualizados}\n";
    echo "   • Total procesados: " . count($permisos) . "\n\n";
    
    // Verificar que se agregaron correctamente
    echo "📋 Permisos de AI Informes en la base de datos:\n";
    $verifyQuery = "SELECT permission_key, permission_name, category FROM system_permissions WHERE category = 'ai_informes' ORDER BY permission_key";
    $verifyStmt = $pdo->prepare($verifyQuery);
    $verifyStmt->execute();
    $resultados = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($resultados)) {
        echo "   ⚠️  No se encontraron permisos de AI Informes\n";
    } else {
        foreach ($resultados as $resultado) {
            echo "   • {$resultado['permission_name']} ({$resultado['permission_key']})\n";
        }
    }
    
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
