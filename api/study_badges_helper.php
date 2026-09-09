<?php
/**
 * Helper para decorar estudios con flags de visibilidad de badges
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

/**
 * Decora un estudio con flags de visibilidad de badges (incompleto y urgente)
 * 
 * Reglas:
 * 1. INVOLUCRADO: Si el usuario está involucrado (receptor, emisor, owner o interviniente), SIEMPRE mostrar badges según estado actual
 * 2. OBSERVADOR: Solo si tiene permisos ver_incompletos_otros / ver_urgentes_otros (prioridades de otros)
 * 
 * @param array $study Estudio con: owner_id, assigned_to, derived_to, assigned_by, derived_by, is_intervenient_in_flags, incompleto, prioridad
 * @param array $user Usuario con: id, permisos (array)
 * @return array Estudio modificado con: show_incomplete_badge, show_urgent_badge
 */
function decorate_study_with_badges($study, $user) {
    $userId = $user['id'];
    $userPermisos = $user['permisos'] ?? [];
    
    // Verificar si el usuario está involucrado en el estudio
    // 1. RECEPTOR: Asignado o derivado a él
    $isAssigned = isset($study['assigned_to']) && $study['assigned_to'] == $userId;
    $isDerived = isset($study['derived_to']) && $study['derived_to'] == $userId;
    $isReceptor = $isAssigned || $isDerived;
    
    // 2. EMISOR: Asignó o derivó el estudio a otro
    $isAssignedBy = isset($study['assigned_by']) && $study['assigned_by'] == $userId;
    $isDerivedBy = isset($study['derived_by']) && $study['derived_by'] == $userId;
    $isEmisor = $isAssignedBy || $isDerivedBy;
    
    // 3. OWNER: Es el owner actual del flag (último que marcó)
    $isOwned = isset($study['owner_id']) && $study['owner_id'] == $userId;
    
    // 4. INTERVINIENTE EN FLAGS: Marcó flags en algún momento
    $isIntervenientInFlags = isset($study['is_intervenient_in_flags']) && $study['is_intervenient_in_flags'] === true;
    
    // Flag general: usuario involucrado en el estudio
    $isInvolved = $isReceptor || $isEmisor || $isOwned || $isIntervenientInFlags;
    
    // Verificar permisos para ver badges de otros usuarios (solo si NO está involucrado)
    $canSeeOthersIncomplete = in_array('ver_incompletos_otros', $userPermisos) || 
                               in_array('all', $userPermisos);
    $canSeeOthersUrgent = in_array('ver_urgentes_otros', $userPermisos) || 
                          in_array('all', $userPermisos);
    
    // REGLA 1: INVOLUCRADO - Si está involucrado, SIEMPRE mostrar badges según estado ACTUAL
    // Los badges se muestran según el estado actual del estudio, sin depender de permisos
    if ($isInvolved) {
        $study['show_incomplete_badge'] = isset($study['incompleto']) && $study['incompleto'] === true;
        $study['show_urgent_badge'] = isset($study['prioridad']) && $study['prioridad'] !== 'normal';
        return $study;
    }
    
    // REGLA 2: OBSERVADOR - Si NO está involucrado, aplicar permisos para ver badges de otros
    // Badge de incompleto
    if (isset($study['incompleto']) && $study['incompleto'] === true) {
        $study['show_incomplete_badge'] = $canSeeOthersIncomplete;
    } else {
        $study['show_incomplete_badge'] = false;
    }
    
    // Badge de urgente
    if (isset($study['prioridad']) && $study['prioridad'] !== 'normal') {
        $study['show_urgent_badge'] = $canSeeOthersUrgent;
    } else {
        $study['show_urgent_badge'] = false;
    }
    
    return $study;
}

/**
 * Obtiene información de asignación/derivación, owner e interviniente de un estudio
 * 
 * @param PDO $db Conexión a la base de datos
 * @param string $studyId ID del estudio (orthanc_id, study_instance_uid o study_id)
 * @param string|null $orthancId Orthanc ID del estudio
 * @param string|null $studyInstanceUID Study Instance UID del estudio
 * @param int $userId ID del usuario actual
 * @return array Con: owner_id, assigned_to, derived_to, is_intervenient_in_flags, assigned_by, derived_by
 */
function get_study_assignment_info($db, $studyId, $orthancId, $studyInstanceUID, $userId) {
    $result = [
        'owner_id' => null,
        'assigned_to' => null,
        'derived_to' => null,
        'assigned_by' => null,
        'derived_by' => null,
        'is_intervenient_in_flags' => false
    ];
    
    try {
        // Obtener owner_id desde study_flags (user_id del flag actual - último que marcó)
        $stmt = $db->prepare("
            SELECT DISTINCT user_id as owner_id
            FROM study_flags
            WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?)
            AND (informes_incompletos = 1 OR prioridad IN ('urgente', 'promesa', 'pendiente'))
            LIMIT 1
        ");
        $stmt->execute([$studyId, $orthancId ?: $studyId, $studyInstanceUID ?: $studyId]);
        $ownerFlag = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ownerFlag) {
            $result['owner_id'] = (int)$ownerFlag['owner_id'];
        }
        
        // Verificar si el usuario es interviniente en flags (marcó flags en algún momento)
        // Buscar si existe algún flag donde user_id = userId o created_by = userId
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM study_flags
            WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?)
            AND (user_id = ? OR created_by = ?)
            AND (informes_incompletos = 1 OR prioridad IN ('urgente', 'promesa', 'pendiente'))
            LIMIT 1
        ");
        $stmt->execute([$studyId, $orthancId ?: $studyId, $studyInstanceUID ?: $studyId, $userId, $userId]);
        $intervenientCheck = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($intervenientCheck && $intervenientCheck['count'] > 0) {
            $result['is_intervenient_in_flags'] = true;
        }
        
        // Verificar si el usuario asignó el estudio a otro (emisor de asignación)
        $stmt = $db->prepare("
            SELECT assigned_by
            FROM study_assignments
            WHERE assigned_by = ?
            AND status = 'active'
            AND (
                study_id = ? 
                OR orthanc_study_id = ? 
                OR study_instance_uid = ?
            )
            LIMIT 1
        ");
        $stmt->execute([$userId, $studyId, $orthancId ?: $studyId, $studyInstanceUID ?: $studyId]);
        $assignedBy = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($assignedBy) {
            $result['assigned_by'] = (int)$assignedBy['assigned_by'];
        }
        
        // Verificar si el usuario derivó el estudio a otro (emisor de derivación)
        $stmt = $db->prepare("
            SELECT ss.assigned_by_user_id as derived_by
            FROM study_subassignments ss
            WHERE ss.assigned_by_user_id = ?
            AND ss.status = 'active'
            AND ss.study_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $studyId]);
        $derivedBy = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($derivedBy) {
            $result['derived_by'] = (int)$derivedBy['derived_by'];
        }
        
        // Si no encontramos por studyId directo, intentar buscar por orthanc_id o study_instance_uid
        if (!$result['derived_by'] && ($orthancId || $studyInstanceUID)) {
            $stmt = $db->prepare("
                SELECT ss.assigned_by_user_id as derived_by
                FROM study_subassignments ss
                INNER JOIN study_assignments sa ON ss.study_id = sa.study_id
                WHERE ss.assigned_by_user_id = ?
                AND ss.status = 'active'
                AND (
                    sa.orthanc_study_id = ? 
                    OR sa.study_instance_uid = ?
                )
                LIMIT 1
            ");
            $stmt->execute([
                $userId, 
                $orthancId ?: '', 
                $studyInstanceUID ?: ''
            ]);
            $derivedBy = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($derivedBy) {
                $result['derived_by'] = (int)$derivedBy['derived_by'];
            }
        }
        
        // Verificar si está asignado al usuario
        // Buscar por study_id, orthanc_id o study_instance_uid
        $stmt = $db->prepare("
            SELECT user_id as assigned_to
            FROM study_assignments
            WHERE user_id = ? 
            AND status = 'active'
            AND (
                study_id = ? 
                OR orthanc_study_id = ? 
                OR study_instance_uid = ?
            )
            LIMIT 1
        ");
        $stmt->execute([$userId, $studyId, $orthancId ?: $studyId, $studyInstanceUID ?: $studyId]);
        $assigned = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($assigned) {
            $result['assigned_to'] = (int)$assigned['assigned_to'];
        }
        
        // Verificar si está derivado al usuario
        // study_subassignments solo tiene study_id, así que buscamos directamente por study_id
        // También verificamos que el estudio coincida con study_assignments usando el study_id
        $stmt = $db->prepare("
            SELECT ss.subassigned_to_user_id as derived_to
            FROM study_subassignments ss
            WHERE ss.subassigned_to_user_id = ?
            AND ss.status = 'active'
            AND ss.study_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $studyId]);
        $derived = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($derived) {
            $result['derived_to'] = (int)$derived['derived_to'];
        }
        
        // Si no encontramos por studyId directo, intentar buscar por orthanc_id o study_instance_uid
        // a través de study_assignments
        if (!$result['derived_to'] && ($orthancId || $studyInstanceUID)) {
            $stmt = $db->prepare("
                SELECT ss.subassigned_to_user_id as derived_to
                FROM study_subassignments ss
                INNER JOIN study_assignments sa ON ss.study_id = sa.study_id
                WHERE ss.subassigned_to_user_id = ?
                AND ss.status = 'active'
                AND (
                    sa.orthanc_study_id = ? 
                    OR sa.study_instance_uid = ?
                )
                LIMIT 1
            ");
            $stmt->execute([
                $userId, 
                $orthancId ?: '', 
                $studyInstanceUID ?: ''
            ]);
            $derived = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($derived) {
                $result['derived_to'] = (int)$derived['derived_to'];
            }
        }
        
    } catch (Exception $e) {
        error_log('Error obteniendo información de asignación: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
    }
    
    return $result;
}
?>
