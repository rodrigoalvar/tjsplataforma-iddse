<?php
/**
 * Script para restaurar TODOS los permisos del sistema
 * Basado en la lista completa de permissions-simple.php
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "🔄 Restaurando TODOS los permisos del sistema...\n\n";
    
    // Lista completa de permisos según permissions-simple.php
    $todosLosPermisos = [
        // General
        ['permission_key' => 'dashboard', 'permission_name' => 'Acceso al Dashboard', 'description' => 'Permite acceder al panel principal del sistema', 'category' => 'general'],
        
        // Estudios
        ['permission_key' => 'estudios', 'permission_name' => 'Gestión de Estudios', 'description' => 'Permite gestionar y asignar estudios médicos', 'category' => 'estudios'],
        ['permission_key' => 'pacs_query', 'permission_name' => 'PACS Query', 'description' => 'Permite consultar el PACS directamente', 'category' => 'estudios'],
        ['permission_key' => 'asignaciones', 'permission_name' => 'Asignaciones', 'description' => 'Permite asignar estudios a usuarios', 'category' => 'estudios'],
        ['permission_key' => 'derivaciones', 'permission_name' => 'Derivaciones', 'description' => 'Permite derivar estudios a usuarios', 'category' => 'estudios'],
        ['permission_key' => 'asignar_prioridad', 'permission_name' => 'Asignar Prioridad', 'description' => 'Permite establecer la prioridad (urgente, promesa) de los estudios', 'category' => 'estudios'],
        ['permission_key' => 'filter_institutions', 'permission_name' => 'Filtrar por Instituciones', 'description' => 'Permite filtrar estudios por instituciones', 'category' => 'estudios'],
        
        // Informes
        ['permission_key' => 'informes', 'permission_name' => 'Creación de Informes', 'description' => 'Permite crear informes médicos', 'category' => 'informes'],
        ['permission_key' => 'gestionInformes', 'permission_name' => 'Gestión de Informes', 'description' => 'Permite gestionar todos los informes del sistema', 'category' => 'informes'],
        ['permission_key' => 'verTodosInformes', 'permission_name' => 'Ver Todos', 'description' => 'Permite ver todos los informes del sistema', 'category' => 'informes'],
        ['permission_key' => 'enviar_pacs', 'permission_name' => 'Enviar a PACS', 'description' => 'Permite enviar informes médicos a PACS', 'category' => 'informes'],
        ['permission_key' => 'marcar_incompletos', 'permission_name' => 'Marcar Incompletos', 'description' => 'Permite marcar y desmarcar estudios como informes incompletos', 'category' => 'informes'],
        
        // Audio
        ['permission_key' => 'grabacion', 'permission_name' => 'Grabación de Audio', 'description' => 'Permite grabar audios para informes', 'category' => 'audio'],
        ['permission_key' => 'dictado', 'permission_name' => 'Dictado por Voz', 'description' => 'Permite usar dictado por voz para informes', 'category' => 'audio'],
        ['permission_key' => 'grabacion_sincronizada', 'permission_name' => 'Grabación Sincronizada', 'description' => 'Permite usar grabación sincronizada con transcripción', 'category' => 'audio'],
        ['permission_key' => 'transcripcion_audio', 'permission_name' => 'Transcripción de Archivos de Audio', 'description' => 'Permite transcribir archivos de audio', 'category' => 'audio'],
        ['permission_key' => 'adjuntar_audios', 'permission_name' => 'Adjuntar Audios', 'description' => 'Permite adjuntar archivos de audio a informes', 'category' => 'audio'],
        
        // Plantillas
        ['permission_key' => 'plantillas', 'permission_name' => 'Gestión de Plantillas', 'description' => 'Permite gestionar plantillas de informes', 'category' => 'plantillas'],
        
        // Visor
        ['permission_key' => 'visor', 'permission_name' => 'Visor DICOM', 'description' => 'Permite acceder al visor de imágenes DICOM', 'category' => 'visor'],
        
        // DICOM
        ['permission_key' => 'dicom_query_retrieve', 'permission_name' => 'QUERY/RETRIEVE', 'description' => 'Permite realizar consultas y recuperación de estudios DICOM desde el servidor PACS', 'category' => 'dicom'],
        ['permission_key' => 'dicom_web', 'permission_name' => 'DICOMWeb', 'description' => 'Permite acceder a funcionalidades DICOMWeb para consulta y recuperación de estudios', 'category' => 'dicom'],
        
        // Antecedentes
        ['permission_key' => 'antecedentes_notas', 'permission_name' => 'Antecedentes - Notas y Texto', 'description' => 'Permite acceder a la sección de notas y texto en antecedentes médicos', 'category' => 'antecedentes'],
        ['permission_key' => 'antecedentes_imagenes', 'permission_name' => 'Antecedentes - Imágenes', 'description' => 'Permite acceder a la sección de imágenes en antecedentes médicos', 'category' => 'antecedentes'],
        ['permission_key' => 'antecedentes_camara', 'permission_name' => 'Antecedentes - Cámara', 'description' => 'Permite acceder a la sección de cámara en antecedentes médicos', 'category' => 'antecedentes'],
        ['permission_key' => 'antecedentes_archivos', 'permission_name' => 'Antecedentes - Archivos', 'description' => 'Permite acceder a la sección de archivos en antecedentes médicos', 'category' => 'antecedentes'],
        ['permission_key' => 'antecedentes_qr_movil', 'permission_name' => 'Antecedentes - QR Móvil', 'description' => 'Permite acceder a la sección de QR móvil en antecedentes médicos', 'category' => 'antecedentes'],
        
        // Interfaz
        ['permission_key' => 'gui_antecedentes', 'permission_name' => 'Antecedentes Visible', 'description' => 'Controla la visibilidad del botón de Antecedentes en estudios-manager', 'category' => 'interfaz'],
        ['permission_key' => 'gui_dashboard', 'permission_name' => 'Dashboard Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Dashboard en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_estudios', 'permission_name' => 'Estudios Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Estudios en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_informes', 'permission_name' => 'Informes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Informes en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_gestion_informes', 'permission_name' => 'Gestión Informes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Informes en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_gestion_estudios', 'permission_name' => 'Gestión Estudios Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Estudios en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_gestion_pacientes', 'permission_name' => 'Gestión Pacientes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Pacientes en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_grabacion', 'permission_name' => 'Grabación Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Grabación en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_visor_dicom', 'permission_name' => 'Visor DICOM Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Visor DICOM en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_workspace', 'permission_name' => 'WorkSpace Visible', 'description' => 'Controla la visibilidad y estado activo del acceso WorkSpace en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'gui_gestion_usuarios', 'permission_name' => 'Gestión Usuarios Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Usuarios en el sidebar', 'category' => 'interfaz'],
        
        // GUI
        ['permission_key' => 'gui_configuracion', 'permission_name' => 'Acceso a Configuración (GUI)', 'description' => 'Permite ver el enlace de Configuración en el sidebar', 'category' => 'gui'],
        
        // Admin
        ['permission_key' => 'configuracion', 'permission_name' => 'Configuración del Sistema', 'description' => 'Permite acceder a la configuración del sistema', 'category' => 'admin'],
        ['permission_key' => 'configuracion_manage', 'permission_name' => 'Gestionar Configuración', 'description' => 'Permite modificar la configuración del sistema (base de datos, PACS, URLs)', 'category' => 'admin'],
        ['permission_key' => 'usuarios', 'permission_name' => 'Gestión de Usuarios', 'description' => 'Permite gestionar usuarios del sistema', 'category' => 'admin'],
        ['permission_key' => 'pacientes', 'permission_name' => 'Gestión Pacientes', 'description' => 'Permite gestionar pacientes del sistema', 'category' => 'admin'],
        ['permission_key' => 'all', 'permission_name' => 'Acceso Completo', 'description' => 'Acceso completo a todas las funcionalidades', 'category' => 'admin']
    ];
    
    $agregados = 0;
    $actualizados = 0;
    
    foreach ($todosLosPermisos as $permiso) {
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
    echo "   • Total procesados: " . count($todosLosPermisos) . "\n\n";
    
    // Verificar resultado final por categoría
    echo "📋 Resumen por categoría:\n";
    $query = "SELECT category, COUNT(*) as total
              FROM system_permissions 
              GROUP BY category
              ORDER BY category";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($categorias as $cat) {
        echo "   • {$cat['category']}: {$cat['total']} permisos\n";
    }
    
    echo "\n✅ Todos los permisos han sido restaurados correctamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
