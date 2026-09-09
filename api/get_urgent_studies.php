<?php
/**
 * API para obtener IDs de estudios urgentes/prometidos
 * Retorna los IDs de estudios con prioridad alta (urgente, promesa, pendiente)
 * filtrados por visibilidad del usuario actual (asignación, flags, ver_urgentes_otros).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/study_badges_helper.php';

try {
    // Verificar sesión
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
        exit();
    }
    
    $user = new User();
    $user_data = $user->validateSession($session_token);
    if (!$user_data) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión expirada o inválida']);
        exit();
    }
    
    $userId = $user_data['id'];
    $userLevel = $user_data['nivel'] ?? 'user';
    $pdo = getDBConnection();
    
    // Verificar permisos del usuario
    $user_permisos = $user_data['permisos'];
    if (is_string($user_permisos)) {
        $user_permisos = json_decode($user_permisos, true) ?: [];
    }
    if (!is_array($user_permisos)) {
        $user_permisos = [];
    }
    
    $hasPacsQuery = in_array('all', $user_permisos) || 
                    in_array('pacs_query', $user_permisos);
    
    // Verificar si es root o admin
    $isRootOrAdmin = ($userLevel === 'root' || $userLevel === 'admin') || 
                     in_array('all', $user_permisos);
    
    // Verificar si la tabla study_flags existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'study_flags'");
    if ($stmt->rowCount() == 0) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'data' => [
                'urgent_study_ids' => []
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Obtener estudios con prioridad urgente o promesa
    // IMPORTANTE: La prioridad es una propiedad del estudio (no del usuario)
    // Por lo tanto, buscamos prioridades de cualquier usuario
    $query = "
        SELECT DISTINCT 
            sf.study_id,
            sf.orthanc_id,
            sf.study_instance_uid,
            sf.prioridad,
            sf.nota,
            sf.updated_at
        FROM study_flags sf
        WHERE sf.prioridad IN ('urgente', 'promesa', 'pendiente')
        AND sf.prioridad IS NOT NULL
    ";
    
    $params = [];
    
    // Verificar si tiene permiso para ver urgentes de otros usuarios
    // Este permiso es independiente de pacs_query y controla la visibilidad de badges
    $canSeeOthersUrgent = in_array('ver_urgentes_otros', $user_permisos) || 
                          in_array('all', $user_permisos);
    
    // Si NO tiene permiso PACS QUERY y NO es root/admin,
    // filtrar por estudios donde el usuario es receptor, emisor/interviniente o owner
    if (!$hasPacsQuery && !$isRootOrAdmin) {
        // REGLA: El usuario U ve estudios donde:
        // 1. RECEPTOR: Estudios asignados/derivados a U (receptor actual)
        // 2. EMISOR: Estudios que U asignó/derivó a otros (interviniente en asignación/derivación)
        // 3. INTERVINIENTE EN FLAGS: Estudios donde U marcó flags en algún momento (user_id o created_by en study_flags)
        // 4. OBSERVADOR: Estudios de otros solo si tiene ver_urgentes_otros
        $query .= "
            AND (
                -- 1. RECEPTOR: Estudios asignados directamente a U
                sf.study_id IN (
                    SELECT study_id FROM study_assignments 
                    WHERE user_id = ? AND status = 'active'
                )
                OR
                sf.orthanc_id IN (
                    SELECT orthanc_study_id FROM study_assignments 
                    WHERE user_id = ? AND status = 'active'
                    AND orthanc_study_id IS NOT NULL
                )
                OR
                sf.study_instance_uid IN (
                    SELECT study_instance_uid FROM study_assignments 
                    WHERE user_id = ? AND status = 'active'
                    AND study_instance_uid IS NOT NULL
                )
                OR
                -- RECEPTOR: Estudios derivados a U
                sf.study_id IN (
                    SELECT study_id FROM study_subassignments 
                    WHERE subassigned_to_user_id = ? AND status = 'active'
                )
                OR
                (sf.orthanc_id IS NOT NULL AND sf.orthanc_id IN (
                    SELECT study_id FROM study_subassignments 
                    WHERE subassigned_to_user_id = ? AND status = 'active'
                ))
                OR
                (sf.study_instance_uid IS NOT NULL AND sf.study_instance_uid IN (
                    SELECT sa.study_instance_uid FROM study_assignments sa
                    INNER JOIN study_subassignments ss ON sa.study_id = ss.study_id
                    WHERE ss.subassigned_to_user_id = ? AND ss.status = 'active'
                    AND sa.study_instance_uid IS NOT NULL
                ))
                OR
                -- 2. EMISOR: Estudios que U asignó a otros (assigned_by = U)
                -- IMPORTANTE: Aunque otro usuario haya marcado el flag después, el emisor sigue viendo el estudio
                sf.study_id IN (
                    SELECT study_id FROM study_assignments 
                    WHERE assigned_by = ? AND status = 'active'
                )
                OR
                sf.orthanc_id IN (
                    SELECT orthanc_study_id FROM study_assignments 
                    WHERE assigned_by = ? AND status = 'active'
                    AND orthanc_study_id IS NOT NULL
                )
                OR
                sf.study_instance_uid IN (
                    SELECT study_instance_uid FROM study_assignments 
                    WHERE assigned_by = ? AND status = 'active'
                    AND study_instance_uid IS NOT NULL
                )
                OR
                -- EMISOR: Estudios que U derivó a otros (assigned_by_user_id = U)
                sf.study_id IN (
                    SELECT study_id FROM study_subassignments 
                    WHERE assigned_by_user_id = ? AND status = 'active'
                )
                OR
                (sf.orthanc_id IS NOT NULL AND sf.orthanc_id IN (
                    SELECT study_id FROM study_subassignments 
                    WHERE assigned_by_user_id = ? AND status = 'active'
                ))
                OR
                (sf.study_instance_uid IS NOT NULL AND sf.study_instance_uid IN (
                    SELECT sa.study_instance_uid FROM study_assignments sa
                    INNER JOIN study_subassignments ss ON sa.study_id = ss.study_id
                    WHERE ss.assigned_by_user_id = ? AND ss.status = 'active'
                    AND sa.study_instance_uid IS NOT NULL
                ))
                OR
                -- 3. INTERVINIENTE EN FLAGS: Estudios donde U marcó flags en algún momento
                -- Buscar si existe algún flag (actual o histórico) donde user_id = U o created_by = U
                EXISTS (
                    SELECT 1 FROM study_flags sf2
                    WHERE (sf2.study_id = sf.study_id 
                           OR (sf2.orthanc_id = sf.orthanc_id AND sf.orthanc_id IS NOT NULL)
                           OR (sf2.study_instance_uid = sf.study_instance_uid AND sf.study_instance_uid IS NOT NULL))
                    AND (sf2.user_id = ? OR sf2.created_by = ?)
                    AND (sf2.informes_incompletos = 1 OR sf2.prioridad IN ('urgente', 'promesa', 'pendiente'))
                )
                " . ($canSeeOthersUrgent ? "
                OR
                -- 4. OBSERVADOR: Estudios de otros usuarios (solo si tiene permiso ver_urgentes_otros)
                -- Estos son estudios donde el usuario NO es ni receptor, ni emisor, ni interviniente en flags
                (
                    NOT EXISTS (
                        SELECT 1 FROM study_assignments 
                        WHERE (user_id = ? OR assigned_by = ?) AND status = 'active'
                        AND (study_id = sf.study_id 
                             OR (orthanc_study_id = sf.orthanc_id AND sf.orthanc_id IS NOT NULL)
                             OR (study_instance_uid = sf.study_instance_uid AND sf.study_instance_uid IS NOT NULL))
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM study_subassignments 
                        WHERE (subassigned_to_user_id = ? OR assigned_by_user_id = ?) AND status = 'active'
                        AND study_id = sf.study_id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM study_flags sf3
                        WHERE (sf3.study_id = sf.study_id 
                               OR (sf3.orthanc_id = sf.orthanc_id AND sf.orthanc_id IS NOT NULL)
                               OR (sf3.study_instance_uid = sf.study_instance_uid AND sf.study_instance_uid IS NOT NULL))
                        AND (sf3.user_id = ? OR sf3.created_by = ?)
                    )
                )" : "") . "
            )
        ";
        // Parámetros: 6 para receptor, 6 para emisor, 2 para interviniente en flags, más opcionales si tiene permiso
        $params = array_merge($params, array_fill(0, 6, $userId)); // Receptor
        $params = array_merge($params, array_fill(0, 6, $userId)); // Emisor
        $params = array_merge($params, [$userId, $userId]); // Interviniente en flags (user_id o created_by)
        if ($canSeeOthersUrgent) {
            $params = array_merge($params, array_fill(0, 6, $userId));
        }
    } elseif ($hasPacsQuery && !$canSeeOthersUrgent && !$isRootOrAdmin) {
        // PACS Query da acceso al PACS, no amplía visibilidad de prioridades ajenas.
        // Sin ver_urgentes_otros: solo estudios donde es receptor, emisor o interviniente en flags.
        $query .= " AND (
            sf.study_id IN (SELECT study_id FROM study_assignments WHERE (user_id = ? OR assigned_by = ?) AND status = 'active') OR
            sf.orthanc_id IN (SELECT orthanc_study_id FROM study_assignments WHERE (user_id = ? OR assigned_by = ?) AND status = 'active' AND orthanc_study_id IS NOT NULL) OR
            sf.study_instance_uid IN (SELECT study_instance_uid FROM study_assignments WHERE (user_id = ? OR assigned_by = ?) AND status = 'active' AND study_instance_uid IS NOT NULL) OR
            sf.study_id IN (SELECT study_id FROM study_subassignments WHERE (subassigned_to_user_id = ? OR assigned_by_user_id = ?) AND status = 'active') OR
            EXISTS (
                SELECT 1 FROM study_flags sf2
                WHERE (sf2.study_id = sf.study_id 
                       OR (sf2.orthanc_id = sf.orthanc_id AND sf.orthanc_id IS NOT NULL)
                       OR (sf2.study_instance_uid = sf.study_instance_uid AND sf.study_instance_uid IS NOT NULL))
                AND (sf2.user_id = ? OR sf2.created_by = ?)
                AND (sf2.informes_incompletos = 1 OR sf2.prioridad IN ('urgente', 'promesa', 'pendiente'))
            )
        )";
        $params = array_merge($params, array_fill(0, 10, $userId));
    }
    
    $query .= " ORDER BY 
        CASE 
            WHEN sf.prioridad = 'urgente' THEN 1
            WHEN sf.prioridad = 'promesa' THEN 2
            WHEN sf.prioridad = 'pendiente' THEN 3
            ELSE 4
        END,
        sf.updated_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    if (!$stmt) {
        throw new Exception('Error preparando query: ' . implode(', ', $pdo->errorInfo()));
    }
    
    $stmt->execute($params);
    $urgentFlags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($urgentFlags)) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'data' => [
                'urgent_study_ids' => []
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Formatear respuesta con IDs únicos
    $urgentStudyIds = [];
    $seenIds = [];
    
    foreach ($urgentFlags as $flag) {
        // Priorizar orthanc_id, luego study_instance_uid, luego study_id
        $orthancId = $flag['orthanc_id'] ?: $flag['study_id'];
        $studyInstanceUID = $flag['study_instance_uid'];
        
        // Crear clave única para evitar duplicados
        $uniqueKey = $orthancId . '|' . ($studyInstanceUID ?: '');
        
        if (!isset($seenIds[$uniqueKey])) {
            $urgentStudyIds[] = [
                'study_id' => $flag['study_id'] ?? null, // Guardar study_id original
                'orthanc_id' => $orthancId,
                'study_instance_uid' => $studyInstanceUID,
                'prioridad' => $flag['prioridad'],
                'nota' => $flag['nota']
            ];
            $seenIds[$uniqueKey] = true;
        }
    }
    
    // Verificar si los estudios urgentes también están marcados como incompletos
    // y decorar cada estudio con flags de visibilidad de badges
    foreach ($urgentStudyIds as &$studyFlag) {
        // Priorizar study_id original, luego orthanc_id, luego study_instance_uid
        $studyId = $studyFlag['study_id'] ?? $studyFlag['orthanc_id'] ?? $studyFlag['study_instance_uid'] ?? null;
        $orthancId = $studyFlag['orthanc_id'] ?? null;
        $studyInstanceUID = $studyFlag['study_instance_uid'] ?? null;
        
        if (!$studyId) {
            continue;
        }
        
        // Verificar si también está marcado como incompleto
        $isIncomplete = false;
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM study_flags
                WHERE informes_incompletos = 1
                AND (
                    study_id = ? 
                    OR orthanc_id = ? 
                    OR study_instance_uid = ?
                )
            ");
            $stmt->execute([$studyId, $orthancId ?: $studyId, $studyInstanceUID ?: $studyId]);
            $incompleteCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            $isIncomplete = ($incompleteCheck && $incompleteCheck['count'] > 0);
        } catch (Exception $e) {
            error_log('Error verificando incompleto: ' . $e->getMessage());
        }
        
        // Obtener información de asignación/derivación y owner
        $assignmentInfo = get_study_assignment_info($pdo, $studyId, $orthancId, $studyInstanceUID, $userId);
        
        // Preparar objeto estudio para decorar
        $study = [
            'owner_id' => $assignmentInfo['owner_id'],
            'assigned_to' => $assignmentInfo['assigned_to'],
            'derived_to' => $assignmentInfo['derived_to'],
            'assigned_by' => $assignmentInfo['assigned_by'],
            'derived_by' => $assignmentInfo['derived_by'],
            'is_intervenient_in_flags' => $assignmentInfo['is_intervenient_in_flags'] ?? false,
            'incompleto' => $isIncomplete,
            'prioridad' => $studyFlag['prioridad'] ?? 'normal'
        ];
        
        // Log de depuración antes de decorar
        error_log(json_encode([
            'debug' => 'badge_test_urgent',
            'user_id' => $userId,
            'study_id' => $studyId,
            'orthanc_id' => $orthancId,
            'study_instance_uid' => $studyInstanceUID,
            'owner_id' => $assignmentInfo['owner_id'],
            'assigned_to' => $assignmentInfo['assigned_to'],
            'derived_to' => $assignmentInfo['derived_to'],
            'incompleto' => $study['incompleto'],
            'prioridad' => $study['prioridad'],
        ], JSON_UNESCAPED_UNICODE));
        
        // Decorar con badges
        $study = decorate_study_with_badges($study, [
            'id' => $userId,
            'permisos' => $user_permisos
        ]);
        
        // Agregar flags de visibilidad al resultado
        $studyFlag['show_incomplete_badge'] = $study['show_incomplete_badge'] ?? false;
        $studyFlag['show_urgent_badge'] = $study['show_urgent_badge'] ?? false;
    }
    unset($studyFlag); // Liberar referencia
    
    error_log("[GET_URGENT_STUDIES] Usuario $userId - Estudios urgentes encontrados: " . count($urgentStudyIds));
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'urgent_study_ids' => $urgentStudyIds
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log('Error en get_urgent_studies.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error obteniendo estudios urgentes: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
