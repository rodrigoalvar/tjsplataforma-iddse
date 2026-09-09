<?php
/**
 * API para obtener estudios marcados como incompletos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Siempre retorna datos FRESH (no usa caché)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/study_badges_helper.php';

try {
    // Verificar sesión
    $session_token = $_COOKIE['session_token'] ?? null;
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
    
    $userId = $user_data['id'];
    $db = getDBConnection();
    
    if (!$db) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error de conexión a la base de datos'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Verificar si la tabla study_flags existe, si no, crearla
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'study_flags'");
        if ($checkTable->rowCount() === 0) {
            // Tabla no existe, crearla automáticamente
            error_log('[GET_INCOMPLETE_STUDIES] Tabla study_flags no existe, creándola...');
            
            $createTableSQL = "
            CREATE TABLE IF NOT EXISTS study_flags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                study_id VARCHAR(255) NOT NULL COMMENT 'ID del estudio (orthanc_id o study_instance_uid)',
                orthanc_id VARCHAR(255) NULL COMMENT 'Orthanc ID del estudio',
                study_instance_uid VARCHAR(255) NULL COMMENT 'Study Instance UID del estudio',
                user_id INT NOT NULL COMMENT 'ID del usuario para el cual aplica el flag',
                informes_incompletos BOOLEAN DEFAULT FALSE COMMENT 'Indica si el estudio tiene informes incompletos',
                nota TEXT NULL COMMENT 'Nota opcional sobre los informes incompletos',
                prioridad VARCHAR(20) DEFAULT 'normal' COMMENT 'Prioridad del estudio: normal, promesa, urgente',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación del flag',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
                created_by INT NULL COMMENT 'ID del usuario que creó el flag',
                
                UNIQUE KEY unique_study_user (study_id, user_id),
                INDEX idx_study_id (study_id),
                INDEX idx_orthanc_id (orthanc_id),
                INDEX idx_study_instance_uid (study_instance_uid),
                INDEX idx_user_id (user_id),
                INDEX idx_informes_incompletos (informes_incompletos),
                INDEX idx_prioridad (prioridad),
                INDEX idx_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            
            // Intentar crear la tabla sin foreign keys primero (por si la tabla usuarios no existe aún)
            try {
                $db->exec($createTableSQL);
                error_log('[GET_INCOMPLETE_STUDIES] Tabla study_flags creada exitosamente');
            } catch (PDOException $e) {
                // Si falla por foreign keys, intentar sin ellas
                if (strpos($e->getMessage(), 'foreign key') !== false || strpos($e->getMessage(), 'FOREIGN KEY') !== false) {
                    error_log('[GET_INCOMPLETE_STUDIES] Error con foreign keys, creando tabla sin ellas...');
                    $createTableSQLNoFK = "
                    CREATE TABLE IF NOT EXISTS study_flags (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        study_id VARCHAR(255) NOT NULL COMMENT 'ID del estudio (orthanc_id o study_instance_uid)',
                        orthanc_id VARCHAR(255) NULL COMMENT 'Orthanc ID del estudio',
                        study_instance_uid VARCHAR(255) NULL COMMENT 'Study Instance UID del estudio',
                        user_id INT NOT NULL COMMENT 'ID del usuario para el cual aplica el flag',
                        informes_incompletos BOOLEAN DEFAULT FALSE COMMENT 'Indica si el estudio tiene informes incompletos',
                        nota TEXT NULL COMMENT 'Nota opcional sobre los informes incompletos',
                        prioridad VARCHAR(20) DEFAULT 'normal' COMMENT 'Prioridad del estudio: normal, promesa, urgente',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación del flag',
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
                        created_by INT NULL COMMENT 'ID del usuario que creó el flag',
                        
                        UNIQUE KEY unique_study_user (study_id, user_id),
                        INDEX idx_study_id (study_id),
                        INDEX idx_orthanc_id (orthanc_id),
                        INDEX idx_study_instance_uid (study_instance_uid),
                        INDEX idx_user_id (user_id),
                        INDEX idx_informes_incompletos (informes_incompletos),
                        INDEX idx_prioridad (prioridad),
                        INDEX idx_created_by (created_by)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ";
                    $db->exec($createTableSQLNoFK);
                    error_log('[GET_INCOMPLETE_STUDIES] Tabla study_flags creada sin foreign keys');
                } else {
                    throw $e;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error verificando/creando tabla study_flags: ' . $e->getMessage());
        // Continuar de todas formas, retornar respuesta vacía
        echo json_encode([
            'success' => true,
            'data' => [
                'incomplete_study_ids' => []
            ],
            'message' => 'Tabla study_flags no disponible'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Verificar permisos del usuario
    $user_permisos = $user_data['permisos'] ?? [];
    if (is_string($user_permisos)) {
        $user_permisos = json_decode($user_permisos, true) ?: [];
    }
    if (!is_array($user_permisos)) {
        $user_permisos = [];
    }
    
    // Usuarios root tienen acceso automático
    $userLevel = $user_data['nivel'] ?? '';
    if ($userLevel === 'root') {
        $hasPacsQuery = true;
        $isRootOrAdmin = true;
    } else {
        $hasPacsQuery = in_array('all', $user_permisos) || 
                        in_array('pacs_query', $user_permisos);
        $isRootOrAdmin = in_array('all', $user_permisos) || 
                         $userLevel === 'admin';
    }
    
    // Consulta para obtener estudios con informes_incompletos = 1
    // IMPORTANTE: Seleccionar TODOS los flags (incluyendo informe_id) para poder agrupar notas
    $query = "
        SELECT 
            sf.study_id,
            COALESCE(sf.orthanc_id, sf.study_id) as orthanc_id,
            sf.study_instance_uid,
            sf.informes_incompletos,
            sf.prioridad,
            sf.nota,
            sf.informe_id
        FROM study_flags sf
        WHERE sf.informes_incompletos = 1
    ";
    
    $params = [];
    
    // Verificar si tiene permiso para ver incompletos de otros usuarios
    // Este permiso es independiente de pacs_query y controla la visibilidad de badges
    $canSeeOthersIncomplete = in_array('ver_incompletos_otros', $user_permisos) || 
                               in_array('all', $user_permisos);
    
    // Si NO tiene permiso PACS Query y NO es root/admin,
    // filtrar por estudios donde el usuario es receptor, emisor/interviniente o owner
    if (!$hasPacsQuery && !$isRootOrAdmin) {
        // REGLA: El usuario U ve estudios donde:
        // 1. RECEPTOR: Estudios asignados/derivados a U (receptor actual)
        // 2. EMISOR: Estudios que U asignó/derivó a otros (interviniente en asignación/derivación)
        // 3. INTERVINIENTE EN FLAGS: Estudios donde U marcó flags en algún momento (user_id o created_by en study_flags)
        // 4. OBSERVADOR: Estudios de otros solo si tiene ver_incompletos_otros
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
                " . ($canSeeOthersIncomplete ? "
                OR
                -- 4. OBSERVADOR: Estudios de otros usuarios (solo si tiene permiso ver_incompletos_otros)
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
        $params = array_merge($params, [$userId, $userId, $userId, $userId, $userId, $userId]); // Receptor
        $params = array_merge($params, [$userId, $userId, $userId, $userId, $userId, $userId]); // Emisor
        $params = array_merge($params, [$userId, $userId]); // Interviniente en flags (user_id o created_by)
        if ($canSeeOthersIncomplete) {
            $params = array_merge($params, [$userId, $userId, $userId, $userId, $userId, $userId]);
        }
    } elseif (!$canSeeOthersIncomplete && !$isRootOrAdmin) {
        // Si tiene PACS Query pero NO tiene permiso para ver de otros:
        // Ver solo estudios donde es receptor, emisor o interviniente en flags
        // Nota: Con PACS Query normalmente ve todo, pero si no tiene permiso, limitamos
        $query .= " AND (
            -- Receptor o emisor en asignaciones/derivaciones
            sf.study_id IN (SELECT study_id FROM study_assignments WHERE (user_id = ? OR assigned_by = ?) AND status = 'active') OR
            sf.orthanc_id IN (SELECT orthanc_study_id FROM study_assignments WHERE (user_id = ? OR assigned_by = ?) AND status = 'active' AND orthanc_study_id IS NOT NULL) OR
            sf.study_instance_uid IN (SELECT study_instance_uid FROM study_assignments WHERE (user_id = ? OR assigned_by = ?) AND status = 'active' AND study_instance_uid IS NOT NULL) OR
            sf.study_id IN (SELECT study_id FROM study_subassignments WHERE (subassigned_to_user_id = ? OR assigned_by_user_id = ?) AND status = 'active') OR
            -- Interviniente en flags (marcó flags en algún momento)
            EXISTS (
                SELECT 1 FROM study_flags sf2
                WHERE (sf2.study_id = sf.study_id 
                       OR (sf2.orthanc_id = sf.orthanc_id AND sf.orthanc_id IS NOT NULL)
                       OR (sf2.study_instance_uid = sf.study_instance_uid AND sf.study_instance_uid IS NOT NULL))
                AND (sf2.user_id = ? OR sf2.created_by = ?)
                AND (sf2.informes_incompletos = 1 OR sf2.prioridad IN ('urgente', 'promesa', 'pendiente'))
            )
        )";
        $params = array_merge($params, [$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    }
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $incompleteStudyFlags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error ejecutando consulta en get_incomplete_studies.php: ' . $e->getMessage());
        error_log('Query: ' . $query);
        error_log('Params: ' . json_encode($params));
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error ejecutando consulta: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    if (empty($incompleteStudyFlags)) {
        echo json_encode([
            'success' => true,
            'data' => [
                'incomplete_study_ids' => []
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Primero, agrupar TODOS los flags por study_id para combinar notas
    $flagsByStudy = [];
    foreach ($incompleteStudyFlags as $flag) {
        $studyId = $flag['study_id'];
        if (!isset($flagsByStudy[$studyId])) {
            $flagsByStudy[$studyId] = [];
        }
        $flagsByStudy[$studyId][] = $flag;
    }
    
    // Ahora procesar cada grupo y recopilar TODAS las notas
    $validIncompleteStudyIds = [];
    $flagsToClean = [];
    $seenIds = [];
    
    foreach ($flagsByStudy as $studyId => $studyFlags) {
        // Tomar el primer flag como base
        $baseFlag = $studyFlags[0];
        $orthancId = $baseFlag['orthanc_id'] ?: $baseFlag['study_id'];
        $studyInstanceUID = $baseFlag['study_instance_uid'];
        
        $uniqueKey = $orthancId . '|' . ($studyInstanceUID ?: '');
        
        if (isset($seenIds[$uniqueKey])) {
            continue; // Ya procesado
        }
        $seenIds[$uniqueKey] = true;
        
        // Recopilar TODAS las notas de todos los informes incompletos de este estudio
        $notasPorInforme = [];
        $notaGeneral = null;
        $informesVistos = []; // Para evitar duplicados
        
        foreach ($studyFlags as $flag) {
            if ($flag['nota'] && $flag['informe_id']) {
                // Verificar que no esté duplicado
                if (!isset($informesVistos[$flag['informe_id']])) {
                    $notasPorInforme[] = [
                        'informe_id' => $flag['informe_id'],
                        'nota' => $flag['nota']
                    ];
                    $informesVistos[$flag['informe_id']] = true;
                }
            }
            // Mantener una nota general para compatibilidad
            if ($flag['nota']) {
                $notaGeneral = $flag['nota'];
            }
        }
        
        // Verificar si realmente existe un informe para este estudio
        // Buscar por todos los posibles IDs: study_id, study_instance_uid, estudio_id, orthanc_id
        // Primero verificar qué columnas existen en la tabla informes
        try {
            $checkColsStmt = $db->query("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'informes'
                  AND COLUMN_NAME IN ('estudio_id', 'study_id', 'study_instance_uid', 'orthanc_id')
            ");
            $existingCols = $checkColsStmt ? $checkColsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            
            // Construir condiciones dinámicamente según columnas disponibles
            $checkConditions = [];
            $checkParams = [];
            
            if (in_array('estudio_id', $existingCols)) {
                $checkConditions[] = "estudio_id = ?";
                $checkParams[] = $studyId;
            }
            if (in_array('study_id', $existingCols)) {
                $checkConditions[] = "study_id = ?";
                $checkParams[] = $studyId;
            }
            if (in_array('study_instance_uid', $existingCols) && $studyInstanceUID) {
                $checkConditions[] = "study_instance_uid = ?";
                $checkParams[] = $studyInstanceUID;
            }
            if (in_array('orthanc_id', $existingCols) && $orthancId) {
                $checkConditions[] = "orthanc_id = ?";
                $checkParams[] = $orthancId;
            }
            
            if (empty($checkConditions)) {
                // Si no hay columnas disponibles, asumir que hay informes (conservador)
                $validIncompleteStudyIds[] = [
                    'study_id' => $studyId, // Guardar study_id original
                    'orthanc_id' => $orthancId,
                    'study_instance_uid' => $studyInstanceUID,
                    'informes_incompletos' => $baseFlag['informes_incompletos'],
                    'prioridad' => $baseFlag['prioridad'],
                    'nota' => $notaGeneral,
                    'notas_por_informe' => $notasPorInforme
                ];
                continue;
            }
            
            $checkInformeQuery = "
                SELECT COUNT(*) as count 
                FROM informes 
                WHERE (" . implode(' OR ', $checkConditions) . ")
            ";
            
            $checkStmt = $db->prepare($checkInformeQuery);
            $checkStmt->execute($checkParams);
            $informeCount = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($informeCount && $informeCount['count'] > 0) {
                // Hay informes, mantener el flag y agregar a la respuesta
                $validIncompleteStudyIds[] = [
                    'study_id' => $studyId, // Guardar study_id original
                    'orthanc_id' => $orthancId,
                    'study_instance_uid' => $studyInstanceUID,
                    'informes_incompletos' => $baseFlag['informes_incompletos'],
                    'prioridad' => $baseFlag['prioridad'],
                    'nota' => $notaGeneral,
                    'notas_por_informe' => $notasPorInforme
                ];
            } else {
                // No hay informes, marcar flag para limpiar
                $flagsToClean[] = [
                    'study_id' => $studyId,
                    'orthanc_id' => $orthancId,
                    'study_instance_uid' => $studyInstanceUID
                ];
            }
        } catch (Exception $e) {
            // Si falla la verificación, asumir que hay informes (conservador)
            error_log('Error verificando informes para estudio ' . $studyId . ': ' . $e->getMessage());
            $validIncompleteStudyIds[] = [
                'study_id' => $studyId, // Guardar study_id original
                'orthanc_id' => $orthancId,
                'study_instance_uid' => $studyInstanceUID,
                'informes_incompletos' => $baseFlag['informes_incompletos'],
                'prioridad' => $baseFlag['prioridad'],
                'nota' => $notaGeneral,
                'notas_por_informe' => $notasPorInforme
            ];
        }
    }
    
    // Limpiar flags huérfanos (sin informes)
    if (!empty($flagsToClean)) {
        try {
            foreach ($flagsToClean as $flagToClean) {
                $cleanConditions = [];
                $cleanParams = [];
                
                if ($flagToClean['study_id']) {
                    $cleanConditions[] = "study_id = ?";
                    $cleanParams[] = $flagToClean['study_id'];
                }
                if ($flagToClean['orthanc_id'] && $flagToClean['orthanc_id'] !== $flagToClean['study_id']) {
                    $cleanConditions[] = "orthanc_id = ?";
                    $cleanParams[] = $flagToClean['orthanc_id'];
                }
                if ($flagToClean['study_instance_uid']) {
                    $cleanConditions[] = "study_instance_uid = ?";
                    $cleanParams[] = $flagToClean['study_instance_uid'];
                }
                
                if (!empty($cleanConditions)) {
                    // Actualizar flag: quitar informes_incompletos
                    $updateQuery = "
                        UPDATE study_flags 
                        SET informes_incompletos = 0 
                        WHERE (" . implode(' OR ', $cleanConditions) . ") AND informes_incompletos = 1
                    ";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->execute($cleanParams);
                    
                    // Si el flag solo tenía informes_incompletos y no tiene prioridad, eliminarlo
                    $deleteQuery = "
                        DELETE FROM study_flags 
                        WHERE (" . implode(' OR ', $cleanConditions) . ") 
                        AND informes_incompletos = 0 
                        AND (prioridad IS NULL OR prioridad = 'normal')
                    ";
                    $deleteStmt = $db->prepare($deleteQuery);
                    $deleteStmt->execute($cleanParams);
                    
                    error_log(sprintf(
                        'Flag limpiado automáticamente - Study ID: %s, Orthanc ID: %s, Flags actualizados: %d, Flags eliminados: %d',
                        $flagToClean['study_id'],
                        $flagToClean['orthanc_id'],
                        $updateStmt->rowCount(),
                        $deleteStmt->rowCount()
                    ));
                }
            }
        } catch (Exception $e) {
            error_log('Error limpiando flags huérfanos: ' . $e->getMessage());
        }
    }
    
    // Decorar cada estudio con flags de visibilidad de badges
    foreach ($validIncompleteStudyIds as &$studyFlag) {
        // Priorizar study_id original, luego orthanc_id, luego study_instance_uid
        $studyId = $studyFlag['study_id'] ?? $studyFlag['orthanc_id'] ?? $studyFlag['study_instance_uid'] ?? null;
        $orthancId = $studyFlag['orthanc_id'] ?? null;
        $studyInstanceUID = $studyFlag['study_instance_uid'] ?? null;
        
        if (!$studyId) {
            continue;
        }
        
        // Obtener información de asignación/derivación y owner
        $assignmentInfo = get_study_assignment_info($db, $studyId, $orthancId, $studyInstanceUID, $userId);
        
        // Preparar objeto estudio para decorar
        $study = [
            'owner_id' => $assignmentInfo['owner_id'],
            'assigned_to' => $assignmentInfo['assigned_to'],
            'derived_to' => $assignmentInfo['derived_to'],
            'assigned_by' => $assignmentInfo['assigned_by'],
            'derived_by' => $assignmentInfo['derived_by'],
            'is_intervenient_in_flags' => $assignmentInfo['is_intervenient_in_flags'] ?? false,
            'incompleto' => true, // Ya sabemos que es incompleto
            'prioridad' => $studyFlag['prioridad'] ?? 'normal'
        ];
        
        // Log de depuración antes de decorar
        error_log(json_encode([
            'debug' => 'badge_test_incomplete',
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
    
    echo json_encode([
        'success' => true,
        'data' => [
            'incomplete_study_ids' => $validIncompleteStudyIds
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Error en get_incomplete_studies.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Limpiar cualquier output no deseado
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error obteniendo estudios incompletos: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    error_log('Fatal Error en get_incomplete_studies.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Limpiar cualquier output no deseado
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal obteniendo estudios incompletos: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>

