<?php
/**
 * API Simplificada para Permisos del Sistema
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/permissions.php
 */

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Solo permitir método GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }
    
    // Obtener permisos del sistema
    $query = "SELECT permission_key, permission_name, description, category 
              FROM system_permissions 
              ORDER BY category, permission_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si no hay permisos en la base de datos, usar permisos por defecto
    if (empty($permissions)) {
        $permissions = [
            ['permission_key' => 'dashboard', 'permission_name' => 'Acceso al Dashboard', 'description' => 'Permite acceder al panel principal del sistema', 'category' => 'general'],
            ['permission_key' => 'ver_incompletos_otros', 'permission_name' => 'Ver Informes Incompletos de Otros', 'description' => 'Permite ver estudios marcados como incompletos por otros usuarios en el dashboard', 'category' => 'dashboard'],
            ['permission_key' => 'ver_urgentes_otros', 'permission_name' => 'Ver Prioridades de Estudios de Otros', 'description' => 'Permite ver estudios con prioridad (urgente, promesa, pendiente) marcada por otros usuarios, aunque no esté asignada ni derivada a la cuenta', 'category' => 'dashboard'],
            ['permission_key' => 'estudios', 'permission_name' => 'Gestión de Estudios', 'description' => 'Permite gestionar y asignar estudios médicos', 'category' => 'estudios'],
            ['permission_key' => 'pacs_query', 'permission_name' => 'PACS Query', 'description' => 'Permite consultar el PACS directamente', 'category' => 'estudios'],
            ['permission_key' => 'asignaciones', 'permission_name' => 'Asignaciones', 'description' => 'Permite asignar estudios a usuarios', 'category' => 'estudios'],
            ['permission_key' => 'derivaciones', 'permission_name' => 'Derivaciones', 'description' => 'Permite derivar estudios a usuarios', 'category' => 'estudios'],
            ['permission_key' => 'asignar_prioridad', 'permission_name' => 'Asignar Prioridad', 'description' => 'Permite establecer la prioridad (urgente, promesa) de los estudios', 'category' => 'estudios'],
            ['permission_key' => 'filter_institutions', 'permission_name' => 'Filtrar por Instituciones', 'description' => 'Permite filtrar estudios por instituciones', 'category' => 'estudios'],
            ['permission_key' => 'antecedentes', 'permission_name' => 'Antecedentes', 'description' => 'Permite gestionar antecedentes de pacientes', 'category' => 'estudios'],
            ['permission_key' => 'worklist', 'permission_name' => 'Acceso a Worklist', 'description' => 'Permite acceder y usar la sección de Worklist', 'category' => 'estudios'],
            ['permission_key' => 'ai_informes', 'permission_name' => 'Acceso a AI Informes', 'description' => 'Permite acceder y usar la sección de AI Informes (Whisper + Medgemma)', 'category' => 'informes'],
            ['permission_key' => 'informes', 'permission_name' => 'Creación de Informes', 'description' => 'Permite crear informes médicos', 'category' => 'informes'],
            ['permission_key' => 'gestionInformes', 'permission_name' => 'Gestión de Informes', 'description' => 'Permite gestionar todos los informes del sistema', 'category' => 'informes'],
            ['permission_key' => 'adjuntarInformes', 'permission_name' => 'Adjuntar Informes PDF', 'description' => 'Permite adjuntar informes PDF externos a estudios desde Gestión de Informes', 'category' => 'informes'],
            ['permission_key' => 'informes_recibidos', 'permission_name' => 'Informes recibidos (API)', 'description' => 'Permite ver el botón y el modal de informes recibidos por API en Gestión de Informes', 'category' => 'informes'],
            ['permission_key' => 'informes_carpeta', 'permission_name' => 'Informes desde carpetas (SMB)', 'description' => 'Permite ver el botón y la cola de PDF/TXT leídos desde carpetas montadas en Gestión de Informes', 'category' => 'informes'],
            ['permission_key' => 'adjuntar_informe_estudio', 'permission_name' => 'Adjuntar informe PDF a estudio', 'description' => 'Permite ver el botón para adjuntar un PDF a un estudio en Gestión de Informes', 'category' => 'informes'],
            ['permission_key' => 'verTodosInformes', 'permission_name' => 'Ver Todos', 'description' => 'Permite ver todos los informes del sistema', 'category' => 'informes'],
            ['permission_key' => 'enviar_pacs', 'permission_name' => 'Enviar a PACS', 'description' => 'Permite enviar informes médicos a PACS', 'category' => 'informes'],
            ['permission_key' => 'firmarInformes', 'permission_name' => 'Firmar informes', 'description' => 'Permite revisar y firmar informes en estado transcripto', 'category' => 'informes'],
            ['permission_key' => 'gestionarFirmaPropia', 'permission_name' => 'Gestionar firma propia', 'description' => 'Permite capturar/editar rúbrica y sello del usuario', 'category' => 'informes'],
            ['permission_key' => 'monitorearSlaEstudios', 'permission_name' => 'Monitorear SLA estudios', 'description' => 'Permite ver contador/modal de estudios sin informe publicado dentro del plazo SLA', 'category' => 'informes'],
            ['permission_key' => 'datosCobranzaInformes', 'permission_name' => 'Datos cobranza / planilla informes', 'description' => 'Permite registrar regiones y texto de planilla, ver listado provisorio y exportar CSV desde Gestión de Informes', 'category' => 'informes'],
            ['permission_key' => 'grabacion', 'permission_name' => 'Grabación de Audio', 'description' => 'Permite grabar audios para informes', 'category' => 'audio'],
            ['permission_key' => 'dictado', 'permission_name' => 'Dictado por Voz', 'description' => 'Permite usar dictado por voz para informes', 'category' => 'audio'],
            ['permission_key' => 'grabacion_sincronizada', 'permission_name' => 'Grabación Sincronizada', 'description' => 'Permite usar grabación sincronizada con transcripción', 'category' => 'audio'],
            ['permission_key' => 'transcripcion_audio', 'permission_name' => 'Transcripción de Archivos de Audio', 'description' => 'Permite transcribir archivos de audio', 'category' => 'audio'],
            ['permission_key' => 'adjuntar_audios', 'permission_name' => 'Adjuntar Audios', 'description' => 'Permite adjuntar archivos de audio a informes', 'category' => 'audio'],
            ['permission_key' => 'descargar_audios_informe', 'permission_name' => 'Descargar audios del informe (MP3)', 'description' => 'Permite el icono de descarga en la columna Audios de Gestión de Informes', 'category' => 'audio'],
            ['permission_key' => 'plantillas', 'permission_name' => 'Gestión de Plantillas', 'description' => 'Permite gestionar plantillas de informes', 'category' => 'plantillas'],
            ['permission_key' => 'plantillas_globales', 'permission_name' => 'Plantillas Globales', 'description' => 'Permite crear plantillas globales del sistema que todos los usuarios pueden usar', 'category' => 'plantillas'],
            ['permission_key' => 'ver_todas_plantillas', 'permission_name' => 'Ver Todas', 'description' => 'Permite ver las plantillas de cualquier usuario en el sistema', 'category' => 'plantillas'],
            ['permission_key' => 'visor', 'permission_name' => 'Visor DICOM', 'description' => 'Permite acceder al visor de imágenes DICOM', 'category' => 'visor'],
            ['permission_key' => 'dicom_query_retrieve', 'permission_name' => 'QUERY/RETRIEVE', 'description' => 'Permite realizar consultas y recuperación de estudios DICOM desde el servidor PACS', 'category' => 'dicom'],
            ['permission_key' => 'dicom_web', 'permission_name' => 'DICOMWeb', 'description' => 'Permite acceder a funcionalidades DICOMWeb para consulta y recuperación de estudios', 'category' => 'dicom'],
            ['permission_key' => 'antecedentes_notas', 'permission_name' => 'Antecedentes - Notas y Texto', 'description' => 'Permite acceder a la sección de notas y texto en antecedentes médicos', 'category' => 'antecedentes'],
            ['permission_key' => 'antecedentes_imagenes', 'permission_name' => 'Antecedentes - Imágenes', 'description' => 'Permite acceder a la sección de imágenes en antecedentes médicos', 'category' => 'antecedentes'],
            ['permission_key' => 'antecedentes_camara', 'permission_name' => 'Antecedentes - Cámara', 'description' => 'Permite acceder a la sección de cámara en antecedentes médicos', 'category' => 'antecedentes'],
            ['permission_key' => 'antecedentes_archivos', 'permission_name' => 'Antecedentes - Archivos', 'description' => 'Permite acceder a la sección de archivos en antecedentes médicos', 'category' => 'antecedentes'],
            ['permission_key' => 'antecedentes_qr_movil', 'permission_name' => 'Antecedentes - QR Móvil', 'description' => 'Permite acceder a la sección de QR móvil en antecedentes médicos', 'category' => 'antecedentes'],
            ['permission_key' => 'gui_antecedentes', 'permission_name' => 'Antecedentes Visible', 'description' => 'Controla la visibilidad del botón de Antecedentes en estudios-manager', 'category' => 'interfaz'],
            ['permission_key' => 'gui_configuracion', 'permission_name' => 'Acceso a Configuración (GUI)', 'description' => 'Permite ver el enlace de Configuración en el sidebar', 'category' => 'gui'],
            ['permission_key' => 'gui_dashboard', 'permission_name' => 'Dashboard Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Dashboard en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_estudios', 'permission_name' => 'Portal Paciente Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Portal Paciente en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_informes', 'permission_name' => 'Informes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Informes en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_gestion_informes', 'permission_name' => 'Gestión Informes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Informes en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_toggle_formato_pacs', 'permission_name' => 'Selector formato PACS (PDF/IMG)', 'description' => 'En Gestión de Informes: muestra y permite cambiar el interruptor PDF vs imagen al enviar a PACS', 'category' => 'interfaz'],
            ['permission_key' => 'gui_gestion_estudios', 'permission_name' => 'Gestión Estudios Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Estudios en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_gestion_pacientes', 'permission_name' => 'Gestión Pacientes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Pacientes en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_grabacion', 'permission_name' => 'Grabación Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Grabación en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_visor_dicom', 'permission_name' => 'Visor DICOM Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Visor DICOM en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_workspace', 'permission_name' => 'WorkSpace Visible', 'description' => 'Controla la visibilidad y estado activo del acceso WorkSpace en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'workspace_cola_auto_avance', 'permission_name' => 'Cola WorkSpace — Auto-avance', 'description' => 'Permite activar el auto-avance al siguiente estudio tras finalizar el informe (Shift+clic en Siguiente estudio en workspace)', 'category' => 'interfaz'],
            ['permission_key' => 'gui_gestion_usuarios', 'permission_name' => 'Gestión Usuarios Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Usuarios en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_pacs_manager', 'permission_name' => 'PACS Manager Visible', 'description' => 'Controla la visibilidad y estado activo del acceso PACS Manager en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_worklist', 'permission_name' => 'Worklist Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Worklist en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_ai_informes', 'permission_name' => 'AI Informes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso AI Informes en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'gui_gestion_mensajes', 'permission_name' => 'Gestión Mensajes Visible', 'description' => 'Controla la visibilidad y estado activo del acceso Gestión Mensajes en el sidebar', 'category' => 'interfaz'],
            ['permission_key' => 'ocultar_informes', 'permission_name' => 'Ocultar Botón Informe Dashboard', 'description' => 'Oculta el botón Informe en las tarjetas de estudios del dashboard', 'category' => 'interfaz'],
            ['permission_key' => 'marcar_incompletos', 'permission_name' => 'Marcar Incompletos', 'description' => 'Permite marcar y desmarcar estudios como informes incompletos', 'category' => 'informes'],
            ['permission_key' => 'configuracion', 'permission_name' => 'Configuración del Sistema', 'description' => 'Permite acceder a la configuración del sistema', 'category' => 'admin'],
            ['permission_key' => 'pacs_manager', 'permission_name' => 'Gestión PACS', 'description' => 'Permite editar y eliminar estudios en el servidor PACS (Orthanc)', 'category' => 'admin'],
            ['permission_key' => 'configuracion_manage', 'permission_name' => 'Gestionar Configuración', 'description' => 'Permite modificar la configuración del sistema (base de datos, PACS, URLs)', 'category' => 'admin'],
            ['permission_key' => 'usuarios', 'permission_name' => 'Gestión de Usuarios', 'description' => 'Permite gestionar usuarios del sistema', 'category' => 'admin'],
            ['permission_key' => 'pacientes', 'permission_name' => 'Gestión Pacientes', 'description' => 'Permite gestionar pacientes del sistema', 'category' => 'admin'],
            ['permission_key' => 'administracion_email', 'permission_name' => 'Administración de Email', 'description' => 'Permite acceder y usar la sección de Administración de Email (Gestión Mensajes)', 'category' => 'admin'],
            ['permission_key' => 'all', 'permission_name' => 'Acceso Completo', 'description' => 'Acceso completo a todas las funcionalidades', 'category' => 'admin']
        ];
    }
    
    // Agrupar permisos por categoría
    $permissionsByCategory = [];
    foreach ($permissions as $permission) {
        $category = $permission['category'];
        if (!isset($permissionsByCategory[$category])) {
            $permissionsByCategory[$category] = [];
        }
        $permissionsByCategory[$category][] = $permission;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $permissionsByCategory
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
