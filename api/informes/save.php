<?php
/**
 * API Endpoint para guardar informes médicos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Registrar errores en log

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

/**
 * Fallback para getallheaders() si no está disponible
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

require_once '../../classes/User.php';
require_once '../../config/database.php';
require_once __DIR__ . '/informe_firma_helper.php';
require_once __DIR__ . '/informe_medico_responsable_helper.php';

try {
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos JSON inválidos');
    }
    
    // Validar sesión
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $input['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    // Verificar permisos del usuario
    $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
    $canManageAllReports = in_array('all', $userPermissions) || in_array('gestionInformes', $userPermissions);
    
    // Validar campos requeridos
    $requiredFields = ['estudio_id'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            throw new Exception("Campo requerido: {$field}");
        }
    }
    
    // Validar contenido_html - debe existir pero puede estar vacío
    if (!isset($input['contenido_html'])) {
        throw new Exception("Campo requerido: contenido_html");
    }
    // Asegurar que contenido_html sea siempre un string (puede estar vacío)
    $contenidoHtml = isset($input['contenido_html']) ? (string)$input['contenido_html'] : '';

    $estadoInput = strtolower(trim((string)($input['estado'] ?? 'borrador')));
    if (!in_array($estadoInput, firma_allowed_estados(), true)) {
        throw new Exception('Estado de informe no válido: ' . $estadoInput);
    }
    $input['estado'] = $estadoInput;
    
    // Identificadores DICOM adicionales (opcionales)
    $studyInstanceUID = $input['study_instance_uid'] ?? null;
    $studyId = $input['study_id'] ?? null;
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    $isUpdate = false;
    $reportId = null;
    $version = 1;
    $existingReport = null;
    
    // Si se proporciona un ID de informe, verificar si existe y el usuario tiene permisos
    if (!empty($input['id'])) {
        $checkQuery = "SELECT id, version, usuario_id, contenido_html, estado, origen, pdf_path FROM informes WHERE id = ?";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([$input['id']]);
        $existingReport = $checkStmt->fetch();
        
        if ($existingReport) {
            // Verificar permisos: el usuario debe ser el propietario o tener permisos de gestión de informes
            $isOwner = ($existingReport['usuario_id'] == $userData['id']);
            $canEdit = $isOwner || $canManageAllReports;
            
            if ($canEdit) {
                $isUpdate = true;
                $reportId = $existingReport['id'];
                $version = $existingReport['version'];

                if (ir_informe_es_externo($existingReport)) {
                    // Externo: no permitir editar HTML clínico; solo estado / metadatos
                    $contenidoHtml = (string)($existingReport['contenido_html'] ?? '');
                    if ($estadoInput === 'finalizado' && strtolower((string)$existingReport['estado']) === 'transcripto'
                        && (in_array('firmarInformes', $userPermissions, true) || in_array('all', $userPermissions, true))
                        && !$canManageAllReports) {
                        throw new Exception('Debe firmar el informe antes de finalizarlo');
                    }
                }
            } else {
                throw new Exception('No tiene permisos para editar este informe');
            }
        } else {
            throw new Exception('El informe especificado no existe');
        }
    } else {
        // Si no se proporciona ID, SIEMPRE crear un nuevo informe
        // No buscar informes existentes - cada informe debe tener su propio ID
        // Las versiones se manejan cuando se edita un informe existente (con ID)
        $isUpdate = false;
        $reportId = null;
        $version = 1;
        $existingReport = null;
    }
    
    // Verificar si hay cambios reales en el contenido HTML (solo para actualizaciones)
    $hayCambiosReales = false;
    if ($isUpdate && $existingReport) {
        // Solo comparar contenido HTML - cambios de estado NO generan nueva versión
        $contenidoAnterior = trim($existingReport['contenido_html'] ?? '');
        $contenidoNuevo = trim($contenidoHtml);
        
        // Normalizar espacios en blanco para comparación más precisa
        $contenidoAnterior = preg_replace('/\s+/', ' ', $contenidoAnterior);
        $contenidoNuevo = preg_replace('/\s+/', ' ', $contenidoNuevo);
        
        if ($contenidoAnterior !== $contenidoNuevo) {
            $hayCambiosReales = true;
        }
    } else if (!$isUpdate) {
        // Nuevo informe - siempre hay cambios (es nuevo)
        $hayCambiosReales = true;
    }
    
    // Si hay cambios reales, incrementar versión
    if ($hayCambiosReales && $existingReport) {
        $version++;
        
        // Guardar versión anterior en historial ANTES de actualizar
        try {
            // Obtener datos del médico informante (dueño del informe) desde el primer informe del estudio
            $estudioIdToSearch = $existingReport['estudio_id'] ?? $existingReport['study_instance_uid'] ?? $existingReport['study_id'];
            $medicoInfoQuery = "SELECT usuario_id, medico_informante_rol 
                               FROM informes 
                               WHERE (estudio_id = ? OR study_instance_uid = ? OR study_id = ?)
                               ORDER BY fecha_creacion ASC, id ASC 
                               LIMIT 1";
            $medicoInfoStmt = $db->prepare($medicoInfoQuery);
            $medicoInfoStmt->execute([$estudioIdToSearch, $estudioIdToSearch, $estudioIdToSearch]);
            $medicoInfo = $medicoInfoStmt->fetch(PDO::FETCH_ASSOC);
            
            // Obtener datos completos del médico informante (del primer informe)
            $medicoDataQuery = "SELECT id, nombre, apellido, rol FROM usuarios WHERE id = ?";
            $medicoDataStmt = $db->prepare($medicoDataQuery);
            $medicoDataStmt->execute([$medicoInfo['usuario_id']]);
            $medicoData = $medicoDataStmt->fetch(PDO::FETCH_ASSOC);
            
            // Obtener rol del médico informante (priorizar el guardado en el informe, luego el del usuario)
            $medicoInformanteRol = $medicoInfo['medico_informante_rol'] ?? $medicoData['rol'] ?? null;
            
            // Obtener datos del transcriptor (usuario que está modificando)
            $transcriptorDataQuery = "SELECT id, nombre, apellido, rol FROM usuarios WHERE id = ?";
            $transcriptorDataStmt = $db->prepare($transcriptorDataQuery);
            $transcriptorDataStmt->execute([$userData['id']]);
            $transcriptorData = $transcriptorDataStmt->fetch(PDO::FETCH_ASSOC);
            
            $historialQuery = "INSERT INTO informes_historial 
                              (informe_id, version_anterior, contenido_html_anterior, estado_anterior, 
                               usuario_modificacion, motivo_cambio,
                               medico_informante_id, medico_informante_nombre, medico_informante_apellido, medico_informante_rol,
                               transcriptor_id, transcriptor_nombre, transcriptor_apellido, transcriptor_rol) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $historialStmt = $db->prepare($historialQuery);
            $resultadoHistorial = $historialStmt->execute([
                $existingReport['id'], // Mantener el MISMO ID del informe
                $existingReport['version'],
                $existingReport['contenido_html'],
                $existingReport['estado'],
                $userData['id'], // Usuario que modificó (transcriptor)
                'Edición del contenido - nueva versión',
                // Datos del médico informante (dueño del estudio - del primer informe)
                $medicoData['id'] ?? null,
                $medicoData['nombre'] ?? null,
                $medicoData['apellido'] ?? null,
                $medicoInformanteRol,
                // Datos del transcriptor (quien modificó esta versión)
                $transcriptorData['id'] ?? null,
                $transcriptorData['nombre'] ?? null,
                $transcriptorData['apellido'] ?? null,
                $transcriptorData['rol'] ?? null
            ]);
            
            if ($resultadoHistorial) {
                error_log("✅ Historial guardado correctamente: informe_id={$existingReport['id']}, version_anterior={$existingReport['version']}");
            } else {
                error_log("⚠️ Error guardando historial - execute devolvió false");
            }
        } catch (PDOException $e) {
            // Si falla el historial, registrar error pero continuar
            error_log('❌ Error guardando historial: ' . $e->getMessage());
            error_log('   - informe_id: ' . $existingReport['id']);
            error_log('   - version_anterior: ' . $existingReport['version']);
        }
        
        // IMPORTANTE: Mantener isUpdate = true para actualizar el mismo registro, no crear uno nuevo
        $isUpdate = true;
    }
    
    // Extraer texto plano del HTML para búsquedas
    $contenidoTexto = strip_tags($contenidoHtml);
    $contenidoTexto = html_entity_decode($contenidoTexto, ENT_QUOTES, 'UTF-8');
    $contenidoTexto = preg_replace('/\s+/', ' ', trim($contenidoTexto));
    
    if ($isUpdate) {
        // Actualizar informe existente (mantiene el mismo ID, solo cambia versión si hay cambios reales)
        $updateQuery = "UPDATE informes SET 
                        contenido_html = ?,
                        contenido_texto = ?,
                        titulo = ?,
                        estado = ?,
                        patient_id = ?,
                        patient_name = ?,
                        modality = ?,
                        study_description = ?,
                        study_instance_uid = ?,
                        study_id = ?,
                        notas_revision = ?,
                        version = ?,
                        fecha_modificacion = CURRENT_TIMESTAMP
                        WHERE id = ?";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            $contenidoHtml,
            $contenidoTexto,
            $input['titulo'] ?? null,
            $input['estado'] ?? 'borrador',
            $input['patient_id'] ?? null,
            $input['patient_name'] ?? null,
            $input['modality'] ?? null,
            $input['study_description'] ?? null,
            $studyInstanceUID,
            $studyId,
            $input['notas_revision'] ?? null,
            $version, // Incluir la versión actualizada
            $reportId
        ]);
        
        $message = $hayCambiosReales && $existingReport ? 
                   "Nueva versión {$version} del informe creada exitosamente" : 
                   'Informe actualizado exitosamente';
        
    } else {
        // Crear NUEVO informe (solo cuando NO existe un informe previo)
        // NOTA: Las nuevas versiones se manejan con UPDATE, no INSERT
        
        // Determinar el médico informante (dueño del informe)
        // Si ya existe un informe para este estudio, usar el médico informante del primer informe
        // Si no existe, usar el usuario actual como médico informante
        $medicoInformanteId = $userData['id'];
        $medicoInformanteRol = null;
        
        // Buscar el primer informe del estudio para obtener el médico informante
        $estudioIdToSearch = $input['estudio_id'] ?? $studyInstanceUID ?? $studyId;
        if ($estudioIdToSearch) {
            $primerInformeQuery = "SELECT usuario_id, medico_informante_rol 
                                  FROM informes 
                                  WHERE (estudio_id = ? OR study_instance_uid = ? OR study_id = ?)
                                  ORDER BY fecha_creacion ASC, id ASC 
                                  LIMIT 1";
            $primerInformeStmt = $db->prepare($primerInformeQuery);
            $primerInformeStmt->execute([$estudioIdToSearch, $estudioIdToSearch, $estudioIdToSearch]);
            $primerInforme = $primerInformeStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($primerInforme) {
                // Usar el médico informante del primer informe
                $medicoInformanteId = $primerInforme['usuario_id'];
                $medicoInformanteRol = $primerInforme['medico_informante_rol'];
                
                // Si no tiene rol guardado, obtenerlo del usuario
                if (!$medicoInformanteRol) {
                    $userRolQuery = "SELECT rol FROM usuarios WHERE id = ?";
                    $userRolStmt = $db->prepare($userRolQuery);
                    $userRolStmt->execute([$medicoInformanteId]);
                    $medicoInformanteRol = $userRolStmt->fetchColumn();
                }
            } else {
                // Es el primer informe del estudio, usar el usuario actual
                $userRolQuery = "SELECT rol FROM usuarios WHERE id = ?";
                $userRolStmt = $db->prepare($userRolQuery);
                $userRolStmt->execute([$userData['id']]);
                $medicoInformanteRol = $userRolStmt->fetchColumn();
            }
        } else {
            // Si no hay estudio_id, usar el usuario actual
            $userRolQuery = "SELECT rol FROM usuarios WHERE id = ?";
            $userRolStmt = $db->prepare($userRolQuery);
            $userRolStmt->execute([$userData['id']]);
            $medicoInformanteRol = $userRolStmt->fetchColumn();
        }
        
        // IMPORTANTE: El usuario_id siempre será el usuario actual (quien crea el informe)
        // pero el medico_informante_rol será el del primer informe del estudio
        // Esto permite que el transcriptor pueda crear informes pero el dueño sea el médico informante original
        
        $insertQuery = "INSERT INTO informes (
                        estudio_id, study_instance_uid, study_id, usuario_id, patient_id, patient_name, 
                        modality, study_description, titulo, contenido_html, 
                        contenido_texto, estado, origen, version, notas_revision, medico_informante_rol
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'plataforma', ?, ?, ?)";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            $input['estudio_id'],
            $studyInstanceUID,
            $studyId,
            $userData['id'], // Usuario actual (puede ser transcriptor)
            $input['patient_id'] ?? null,
            $input['patient_name'] ?? null,
            $input['modality'] ?? null,
            $input['study_description'] ?? null,
            $input['titulo'] ?? null,
            $contenidoHtml,
            $contenidoTexto,
            $input['estado'] ?? 'borrador',
            $version,
            $input['notas_revision'] ?? null,
            $medicoInformanteRol // Rol del médico informante (dueño del estudio)
        ]);
        
        $reportId = $db->lastInsertId();
        $message = 'Informe creado exitosamente';
    }
    
    // Actualizar fecha de finalización si el estado es 'finalizado'
    if (!empty($input['estado']) && $input['estado'] === 'finalizado') {
        $finalizeQuery = "UPDATE informes SET fecha_finalizacion = CURRENT_TIMESTAMP WHERE id = ?";
        $finalizeStmt = $db->prepare($finalizeQuery);
        $finalizeStmt->execute([$reportId]);
    }
    
    // Obtener los datos completos del informe guardado para la respuesta
    $getReportQuery = "SELECT i.id, i.version, i.estado, i.titulo, i.patient_id, i.patient_name, i.modality, 
                      i.study_description, i.estudio_id, i.study_instance_uid, i.study_id,
                      i.fecha_creacion, i.fecha_modificacion, i.notas_revision,
                      COALESCE(u.nombre, 'Usuario desconocido') as usuario_nombre,
                      COALESCE(u.apellido, '') as usuario_apellido,
                      u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id
                      WHERE i.id = ?";
    $getReportStmt = $db->prepare($getReportQuery);
    $getReportStmt->execute([$reportId]);
    $savedReport = $getReportStmt->fetch(PDO::FETCH_ASSOC);

    // Timeline SLA: dictado / transcripto
    try {
        require_once __DIR__ . '/../estudios/sla_helper.php';
        if ($savedReport) {
            $estadoSaved = strtolower((string)($savedReport['estado'] ?? $input['estado'] ?? ''));
            $eid = sla_find_estudio_id($db, [
                'orthanc_study_id' => $savedReport['estudio_id'] ?? null,
                'study_id' => $savedReport['study_id'] ?? $savedReport['estudio_id'] ?? null,
                'study_instance_uid' => $savedReport['study_instance_uid'] ?? null,
            ]);
            if ($eid) {
                sla_try_mark_dictated($db, $eid);
                if ($estadoSaved === 'transcripto') {
                    sla_mark_informe_estado_event($db, $savedReport, 'transcripto');
                }
            }
        }
    } catch (Throwable $slaEx) {
        error_log('[SAVE] sla timeline: ' . $slaEx->getMessage());
    }

    // Gasalud: al finalizar informe de plataforma (si trigger=al_finalizado y hay PDF)
    try {
        if (!empty($input['estado']) && $input['estado'] === 'finalizado' && $reportId) {
            require_once __DIR__ . '/gasalud_envio_helper.php';
            $g = gasalud_try_send_informe($db, (int)$reportId, 'al_finalizado');
            if (empty($g['skipped'])) {
                error_log('[SAVE][GASALUD] ' . ($g['message'] ?? ''));
            }
        }
    } catch (Throwable $gEx) {
        error_log('[SAVE][GASALUD] ' . $gEx->getMessage());
    }
    
    // Formatear fechas para la respuesta
    $fechaCreacionFormatted = $savedReport['fecha_creacion'] ? 
        date('d/m/Y H:i', strtotime($savedReport['fecha_creacion'])) : null;
    $fechaModificacionFormatted = $savedReport['fecha_modificacion'] ? 
        date('d/m/Y H:i', strtotime($savedReport['fecha_modificacion'])) : null;
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'id' => $reportId,
            'informe_id' => $reportId,
            'version' => $version,
            'is_update' => $isUpdate,
            'estado' => $input['estado'] ?? 'borrador',
            'titulo' => $savedReport['titulo'] ?? null,
            'patient_id' => $savedReport['patient_id'] ?? null,
            'patient_name' => $savedReport['patient_name'] ?? null,
            'modality' => $savedReport['modality'] ?? null,
            'study_description' => $savedReport['study_description'] ?? null,
            'estudio_id' => $savedReport['estudio_id'] ?? null,
            'study_instance_uid' => $savedReport['study_instance_uid'] ?? null,
            'study_id' => $savedReport['study_id'] ?? null,
            'fecha_creacion' => $savedReport['fecha_creacion'] ?? null,
            'fecha_modificacion' => $savedReport['fecha_modificacion'] ?? null,
            'fecha_creacion_formatted' => $fechaCreacionFormatted,
            'fecha_modificacion_formatted' => $fechaModificacionFormatted,
            'notas_revision' => $savedReport['notas_revision'] ?? null,
            'usuario_nombre' => $savedReport['usuario_nombre'] ?? null,
            'usuario_apellido' => $savedReport['usuario_apellido'] ?? null,
            'usuario_email' => $savedReport['usuario_email'] ?? null,
            'debug' => [
                'hayCambiosReales' => $hayCambiosReales,
                'existingReport' => $existingReport ? 'exists' : 'null',
                'version_anterior' => $existingReport ? $existingReport['version'] : 'N/A',
                'version_nueva' => $version,
                'historial_guardado' => $hayCambiosReales && $existingReport ? 'Sí' : 'No',
                'tipo_cambio' => $hayCambiosReales ? 'Contenido modificado' : 'Solo cambio de estado',
                'contenido_comparado' => $existingReport ? 'Sí' : 'N/A'
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'SAVE_REPORT_ERROR'
    ]);
} catch (PDOException $e) {
    error_log("Error de base de datos en save.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_code' => 'DATABASE_ERROR'
    ]);
}

// Asegurar que se envíe la respuesta
if (ob_get_level()) {
    ob_end_flush();
}
?>