<?php
/**
 * API Endpoint para obtener flags de múltiples estudios (batch)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/User.php';

try {
    // Verificar sesión
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
            if (strpos($auth_header, 'Bearer ') === 0) {
                $session_token = substr($auth_header, 7);
            }
        }
    }
    
    if (!$session_token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
        exit();
    }
    
    $user = new User();
    $user_data = $user->validateSession($session_token);
    if (!$user_data) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión expirada o inválida']);
        exit();
    }
    
    $db = getDBConnection();
    
    // Obtener study_ids desde query string (GET) o body (POST)
    $study_ids_param = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (isset($data['study_ids'])) {
            $study_ids_param = is_array($data['study_ids']) ? implode(',', $data['study_ids']) : $data['study_ids'];
        } elseif (isset($_POST['study_ids'])) {
            $study_ids_param = $_POST['study_ids'];
        }
    } else {
        $study_ids_param = $_GET['study_ids'] ?? '';
    }
    
    if (empty($study_ids_param)) {
        echo json_encode([
            'success' => true,
            'data' => []
        ]);
        exit();
    }
    
    $study_ids = array_filter(array_map('trim', explode(',', $study_ids_param)));
    if (empty($study_ids)) {
        echo json_encode([
            'success' => true,
            'data' => []
        ]);
        exit();
    }
    
    // Crear placeholders para la consulta IN
    $placeholders = str_repeat('?,', count($study_ids) - 1) . '?';
    
    // Consultar flags para todos los estudios
    // Buscar por study_id, orthanc_id O study_instance_uid
    // Incluir informe_id para poder mapear flags individuales por informe
    // Mostrar informes_incompletos para TODOS los usuarios (no filtrar por user_id)
    // IMPORTANTE: Buscar TODOS los flags (no solo informes_incompletos = 1) para poder mapear correctamente
    // Esto asegura que encontremos el flag sin importar qué ID se use
    $stmt = $db->prepare("
        SELECT study_id, orthanc_id, study_instance_uid, informe_id, informes_incompletos, prioridad, nota, updated_at, user_id
        FROM study_flags 
        WHERE (
            study_id IN ($placeholders) 
            OR orthanc_id IN ($placeholders)
            OR study_instance_uid IN ($placeholders)
        )
    ");
    
    // Repetir los study_ids 3 veces (para study_id, orthanc_id, study_instance_uid)
    $params = array_merge($study_ids, $study_ids, $study_ids);
    $stmt->execute($params);
    $flags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convertir a formato más útil: study_id => flag_data
    // Primero, agrupar TODOS los flags por study_id para poder combinarlos correctamente
    $flags_by_study = [];
    foreach ($flags as $flag) {
        $study_id = $flag['study_id'];
        if (!isset($flags_by_study[$study_id])) {
            $flags_by_study[$study_id] = [];
        }
        $flags_by_study[$study_id][] = $flag;
    }
    
    // Ahora procesar cada grupo de flags por study_id
    $flags_map = new stdClass();
    foreach ($flags_by_study as $study_id => $study_flags) {
        $combined_informes_incompletos = false;
        $combined_prioridad = 'normal';
        $combined_nota = null;
        $notas_por_informe = [];
        $max_updated_at = '';
        
        $priority_order = ['normal' => 0, 'pendiente' => 1, 'promesa' => 2, 'urgente' => 3];
        $max_priority_value = 0;
        
        // Procesar TODOS los flags de este study_id
        foreach ($study_flags as $flag) {
            $informes_incompletos = (bool)$flag['informes_incompletos'];
            $nota = $flag['nota'] ?? null;
            $prioridad = $flag['prioridad'] ?? 'normal';
            $informe_id = $flag['informe_id'] ?? null;
            $updated_at = $flag['updated_at'] ?? '';
            
            // Combinar informes_incompletos (OR: si alguno tiene, el resultado tiene)
            if ($informes_incompletos) {
                $combined_informes_incompletos = true;
                
                // Recopilar nota de este informe si tiene informe_id y nota
                if ($informe_id && $nota) {
                    // Verificar que no esté duplicado (por si hay múltiples registros)
                    $existe = false;
                    foreach ($notas_por_informe as $item) {
                        if ($item['informe_id'] == $informe_id) {
                            $existe = true;
                            break;
                        }
                    }
                    if (!$existe) {
                        $notas_por_informe[] = [
                            'informe_id' => $informe_id,
                            'nota' => $nota
                        ];
                    }
                }
                
                // Mantener una nota general (la última o la que exista, para compatibilidad)
                if ($nota) {
                    $combined_nota = $nota;
                }
            }
            
            // Prioridad: mantener la más alta
            $priority_value = $priority_order[$prioridad] ?? 0;
            if ($priority_value > $max_priority_value) {
                $max_priority_value = $priority_value;
                $combined_prioridad = $prioridad;
            }
            
            // Mantener el updated_at más reciente
            if ($updated_at > $max_updated_at) {
                $max_updated_at = $updated_at;
            }
        }
        
        $flags_map->{$study_id} = [
            'informes_incompletos' => $combined_informes_incompletos,
            'prioridad' => $combined_prioridad,
            'nota' => $combined_nota,
            'notas_por_informe' => $notas_por_informe,
            'updated_at' => $max_updated_at
        ];
    }
    
    // Log para depuración: ver qué flags se están combinando
    error_log("[BATCH FLAGS] Total estudios procesados: " . count($flags_by_study));
    foreach ($flags_by_study as $study_id => $study_flags) {
        $flag_data = $flags_map->{$study_id} ?? null;
        if ($flag_data && $flag_data['informes_incompletos'] && !empty($flag_data['notas_por_informe'])) {
            error_log("[BATCH FLAGS] Study $study_id tiene " . count($flag_data['notas_por_informe']) . " notas: " . json_encode($flag_data['notas_por_informe']));
        }
    }
    
    // Expandir flags_map para incluir TODOS los IDs alternativos de cada estudio
    $expanded_flags_map = new stdClass();
    
    foreach ($flags_map as $flag_study_id => $flag_data) {
        $expanded_flags_map->{$flag_study_id} = $flag_data;
    }
    
    // Agregar también bajo TODOS los IDs alternativos posibles
    // Esto asegura que el flag se pueda encontrar sin importar qué ID se use
    foreach ($flags as $flag) {
        $study_id = $flag['study_id'];
        $orthanc_id = $flag['orthanc_id'] ?? null;
        $study_instance_uid = $flag['study_instance_uid'] ?? null;
        
        $flag_data = $flags_map->{$study_id} ?? null;
        
        if ($flag_data) {
            // Guardar bajo study_id (ya está guardado, pero por si acaso)
            if (!isset($expanded_flags_map->{$study_id})) {
                $expanded_flags_map->{$study_id} = $flag_data;
            }
            
            // Guardar bajo orthanc_id si existe
            if ($orthanc_id && !isset($expanded_flags_map->{$orthanc_id})) {
                $expanded_flags_map->{$orthanc_id} = $flag_data;
            }
            
            // Guardar bajo study_instance_uid si existe
            if ($study_instance_uid && !isset($expanded_flags_map->{$study_instance_uid})) {
                $expanded_flags_map->{$study_instance_uid} = $flag_data;
            }
            
            // También buscar si alguno de los study_ids enviados coincide con alguno de estos IDs
            // y guardar el flag bajo ese ID también
            foreach ($study_ids as $requested_id) {
                if ($requested_id === $study_id || 
                    $requested_id === $orthanc_id || 
                    $requested_id === $study_instance_uid) {
                    if (!isset($expanded_flags_map->{$requested_id})) {
                        $expanded_flags_map->{$requested_id} = $flag_data;
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $expanded_flags_map
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[STUDY_FLAGS_BATCH] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>

