<?php
/**
 * API REST para gestión de Worklist
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoints:
 * POST /api/worklist/import-txt     → Importar archivo TXT
 * POST /api/worklist/import-json    → Importar datos JSON
 * GET  /api/worklist                → Listar worklist con filtros
 * GET  /api/worklist/{accession}    → Obtener por accession_number
 * PUT  /api/worklist/{accession}    → Actualizar worklist
 * DELETE /api/worklist/{accession}  → Eliminar worklist
 * POST /api/worklist/sync           → Sincronizar con Orthanc
 * GET  /api/worklist.php?reconcile=1 → Validar coincidencia BD vs Orthanc
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/TxtWorklistParser.php';
require_once __DIR__ . '/../utils/DicomWorklistGenerator.php';
require_once __DIR__ . '/../utils/WorklistIngestionService.php';
require_once __DIR__ . '/../utils/WorklistPacsReconcileService.php';

// Asegurar que getDBConnection esté disponible
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        $database = new Database();
        return $database->getConnection();
    }
}

try {
    // Validar sesión
    $sessionToken = null;
    
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de autorización requerido']);
        exit();
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Asegurar que las tablas existen
    ensureWorklistTables($db);
    $ingestionService = new WorklistIngestionService($db);
    $ingestionService->ensureSchema();
    WorklistPacsReconcileService::ensureWorklistConfigPacsColumns($db);
    
    // Obtener método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Determinar acción basada en método y parámetros
    $action = null;
    $accession = $_GET['accession'] ?? null;
    
    if ($method === 'GET') {
        if (!empty($_GET['reconcile'])) {
            $action = 'reconcile';
        } elseif (!empty($_GET['changes'])) {
            $action = 'changes';
        } elseif ($accession) {
            $action = 'get';
        } else {
            $action = 'list';
        }
    } elseif ($method === 'POST') {
        // POST con archivo = import-txt
        if (isset($_FILES['txt']) && $_FILES['txt']['error'] === UPLOAD_ERR_OK) {
            $action = 'import-txt';
        } else {
            // POST con JSON = import-json o sync
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['action']) && $input['action'] === 'sync') {
                $action = 'sync';
            } elseif ($input && !empty($input)) {
                $action = 'import-json';
            } else {
                throw new Exception('Datos requeridos para POST');
            }
        }
    } elseif ($method === 'PUT') {
        // PUT requiere accession
        if ($accession) {
            $action = 'update';
        } else {
            throw new Exception('accession_number es requerido para actualizar');
        }
    } elseif ($method === 'DELETE') {
        // DELETE requiere accession
        if ($accession) {
            $action = 'delete';
        } else {
            throw new Exception('accession_number es requerido para eliminar');
        }
    }
    
    if (!$action) {
        throw new Exception('Acción no válida');
    }
    
    // Procesar acción
    switch ($action) {
        case 'import-txt':
            handleImportTxt($db, $userData['id'], $ingestionService);
            break;
            
        case 'import-json':
            handleImportJson($db, $userData['id'], $ingestionService);
            break;
            
        case 'sync':
            handleSync($db, $userData['id'], $ingestionService);
            break;
            
        case 'list':
            handleList($db, $_GET);
            break;

        case 'changes':
            handleChanges($db, $_GET);
            break;

        case 'reconcile':
            handleReconcile($db, $ingestionService);
            break;
            
        case 'get':
            handleGet($db, $accession);
            break;
            
        case 'update':
            handleUpdate($db, $accession, $userData['id'], $ingestionService);
            break;
            
        case 'delete':
            handleDelete($db, $accession, $userData['id'], $ingestionService);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Acción no válida: ' . $action]);
            break;
    }
    
} catch (Exception $e) {
    // Log del error para debugging
    error_log("Error en worklist.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    
    // Determinar código de error apropiado
    $code = 500;
    $message = $e->getMessage();
    
    if (strpos($message, 'requerido') !== false || 
        strpos($message, 'inválid') !== false ||
        strpos($message, 'no encontrado') !== false) {
        $code = 400;
    } elseif (strpos($message, 'autorización') !== false || 
              strpos($message, 'sesión') !== false ||
              strpos($message, 'permisos') !== false) {
        $code = 401;
    }
    
    if (!headers_sent()) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Importar archivo TXT
 */
function handleImportTxt($db, $userId, WorklistIngestionService $service) {
    if (!isset($_FILES['txt']) || $_FILES['txt']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo');
    }
    
    $filePath = $_FILES['txt']['tmp_name'];
    $fileName = $_FILES['txt']['name'];
    
    $txtContent = file_get_contents($filePath);
    if ($txtContent === false) {
        throw new Exception('No se pudo leer el archivo TXT');
    }

    $result = $service->ingestTxtContent(
        $txtContent,
        'MANUAL_UI',
        $fileName,
        (int)$userId,
        true
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Archivo importado exitosamente',
        'data' => $result
    ]);
}

/**
 * Importar datos JSON
 */
function handleImportJson($db, $userId, WorklistIngestionService $service) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos JSON inválidos');
    }
    
    // Validar campos requeridos
    if (empty($input['accession_number'])) {
        throw new Exception('accession_number es requerido');
    }
    if (empty($input['scheduled_date'])) {
        throw new Exception('scheduled_date es requerido');
    }
    if (empty($input['scheduled_time'])) {
        throw new Exception('scheduled_time es requerido');
    }
    
    $result = $service->upsertFromData(
        $input,
        'MANUAL_UI',
        'JSON',
        (int)$userId,
        true
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Datos importados exitosamente',
        'data' => $result
    ]);
}

/**
 * Comparar worklist del portal con Orthanc (REST o filesystem).
 */
function handleReconcile(PDO $db, WorklistIngestionService $service): void
{
    $report = $service->reconcileWithOrthanc();
    echo json_encode([
        'success' => true,
        'data' => $report,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Resumen de filas worklist ya filtradas (tras posible merge PACS en memoria).
 */
function worklistBuildListSummary(array $rows, string $dateFrom, string $dateTo): array {
    $byModality = [];
    $withPacs = 0;
    $withoutPacsExclCancelled = 0;
    $completedWithoutPacs = 0;

    foreach ($rows as $row) {
        $mod = trim((string)($row['modality'] ?? ''));
        if ($mod === '') {
            $mod = '—';
        }
        if (!isset($byModality[$mod])) {
            $byModality[$mod] = 0;
        }
        $byModality[$mod]++;

        $uid = trim((string)($row['pacs_study_instance_uid'] ?? ''));
        $st = $row['status'] ?? '';

        if ($uid !== '') {
            $withPacs++;
        } elseif ($st !== 'cancelled') {
            $withoutPacsExclCancelled++;
        }
        if ($st === 'completed' && $uid === '') {
            $completedWithoutPacs++;
        }
    }

    ksort($byModality, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'total' => count($rows),
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'by_modality' => $byModality,
        'with_pacs_uid' => $withPacs,
        'without_pacs_excl_cancelled' => $withoutPacsExclCancelled,
        'completed_without_pacs' => $completedWithoutPacs,
    ];
}

/**
 * Listar worklist con filtros
 */
function handleList($db, $params) {
    $where = [];
    $bindings = [];

    $dateFrom = isset($params['date_from']) ? trim((string)$params['date_from']) : '';
    $dateTo = isset($params['date_to']) ? trim((string)$params['date_to']) : '';
    $legacyDate = isset($params['date']) ? trim((string)$params['date']) : '';

    if ($dateFrom !== '' && $dateTo !== '') {
        if ($dateFrom > $dateTo) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }
        $where[] = 'scheduled_date BETWEEN ? AND ?';
        $bindings[] = $dateFrom;
        $bindings[] = $dateTo;
    } elseif ($dateFrom !== '') {
        $where[] = 'scheduled_date >= ?';
        $bindings[] = $dateFrom;
    } elseif ($dateTo !== '') {
        $where[] = 'scheduled_date <= ?';
        $bindings[] = $dateTo;
    } elseif ($legacyDate !== '') {
        $where[] = 'scheduled_date = ?';
        $bindings[] = $legacyDate;
        $dateFrom = $legacyDate;
        $dateTo = $legacyDate;
    } else {
        $dateTo = date('Y-m-d');
        $dateFrom = date('Y-m-d', strtotime('-29 days'));
        $where[] = 'scheduled_date BETWEEN ? AND ?';
        $bindings[] = $dateFrom;
        $bindings[] = $dateTo;
    }

    // Modalidad, estado y Orthanc se filtran en el cliente (DataTables) sobre la lista del rango.

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $sql = "SELECT * FROM worklist $whereClause ORDER BY scheduled_date DESC, scheduled_time DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $wlCfg = null;
        $cfgStmt = $db->prepare('SELECT * FROM worklist_config WHERE id = 1');
        try {
            $cfgStmt->execute();
            $wlCfg = $cfgStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            $wlCfg = null;
        }
        if ($wlCfg && !empty($wlCfg['pacs_reconcile_on_list_load'])) {
            $overlay = WorklistPacsReconcileService::reconcileAfterList($db, $wlCfg, $items);
            foreach ($items as &$row) {
                $rid = (int)($row['id'] ?? 0);
                if ($rid > 0 && isset($overlay[$rid])) {
                    foreach ($overlay[$rid] as $k => $v) {
                        $row[$k] = $v;
                    }
                }
            }
            unset($row);
        }

        $summary = worklistBuildListSummary($items, $dateFrom, $dateTo);

        echo json_encode([
            'success' => true,
            'data' => $items,
            'count' => count($items),
            'summary' => $summary,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log("Error en handleList: " . $e->getMessage());
        throw new Exception('Error al obtener la lista de worklist: ' . $e->getMessage());
    }
}

/**
 * Cambios incrementales: devuelve filas con updated_at > since dentro del rango activo.
 * Pensado para polling liviano sin reemplazar toda la tabla.
 *
 * Parametros GET:
 *   changes=1
 *   since=YYYY-MM-DD HH:MM:SS   (timestamp del servidor; si vacio, usa los ultimos 60s)
 *   date_from / date_to          (mismo criterio que list; opcional)
 *   limit=200                    (cap defensivo)
 */
function handleChanges(PDO $db, array $params): void
{
    $since = isset($params['since']) ? trim((string)$params['since']) : '';
    if ($since === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $since)) {
        $since = date('Y-m-d H:i:s', time() - 60);
    } else {
        $since = str_replace('T', ' ', $since);
    }

    $dateFrom = isset($params['date_from']) ? trim((string)$params['date_from']) : '';
    $dateTo = isset($params['date_to']) ? trim((string)$params['date_to']) : '';
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        $tmp = $dateFrom;
        $dateFrom = $dateTo;
        $dateTo = $tmp;
    }

    $where = ['updated_at > ?'];
    $bindings = [$since];

    if ($dateFrom !== '' && $dateTo !== '') {
        $where[] = 'scheduled_date BETWEEN ? AND ?';
        $bindings[] = $dateFrom;
        $bindings[] = $dateTo;
    } elseif ($dateFrom !== '') {
        $where[] = 'scheduled_date >= ?';
        $bindings[] = $dateFrom;
    } elseif ($dateTo !== '') {
        $where[] = 'scheduled_date <= ?';
        $bindings[] = $dateTo;
    }

    $limit = isset($params['limit']) ? (int)$params['limit'] : 200;
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 1000) {
        $limit = 1000;
    }

    try {
        $sql = "SELECT * FROM worklist WHERE " . implode(' AND ', $where) .
            " ORDER BY updated_at ASC, id ASC LIMIT " . $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $items,
            'count' => count($items),
            'since' => $since,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('Error en handleChanges: ' . $e->getMessage());
        throw new Exception('Error al obtener cambios de worklist: ' . $e->getMessage());
    }
}

/**
 * Obtener worklist por accession_number
 */
function handleGet($db, $accession) {
    $stmt = $db->prepare("SELECT * FROM worklist WHERE accession_number = ?");
    $stmt->execute([$accession]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Worklist no encontrado']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $item
    ]);
}

/**
 * Actualizar worklist
 */
function handleUpdate($db, $accession, $userId, WorklistIngestionService $service) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos');
    }
    
    $stmt = $db->prepare("SELECT * FROM worklist WHERE accession_number = ?");
    $stmt->execute([$accession]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Worklist no encontrado']);
        return;
    }

    $merged = $existing;
    foreach ($input as $k => $v) {
        $merged[$k] = $v;
    }
    $merged['accession_number'] = $accession;
    $result = $service->upsertFromData($merged, 'MANUAL_UI', 'EDIT_FORM', (int)$userId, true);
    
    echo json_encode([
        'success' => true,
        'message' => 'Worklist actualizado exitosamente',
        'data' => $result['data'] ?? null
    ]);
}

/**
 * Eliminar worklist
 */
function handleDelete($db, $accession, $userId, WorklistIngestionService $service) {
    $stmt = $db->prepare("SELECT * FROM worklist WHERE accession_number = ?");
    $stmt->execute([$accession]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Worklist no encontrado']);
        return;
    }

    $scope = isset($_GET['scope']) ? trim((string)$_GET['scope']) : 'full';

    // Solo quitar del PACS Orthanc; conservar fila en BD del portal.
    if ($scope === 'orthanc_only') {
        try {
            $service->removeFromOrthanc($existing);
        } catch (Exception $e) {
            error_log("Error quitando worklist de Orthanc: " . $e->getMessage());
            http_response_code(502);
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo eliminar en Orthanc: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $db->prepare("
            UPDATE worklist
            SET orthanc_worklist_id = NULL,
                orthanc_sync_status = 'pending',
                orthanc_sync_error = NULL,
                orthanc_synced_at = NULL
            WHERE accession_number = ?
        ");
        $stmt->execute([$accession]);

        logWorklistAction($db, (int)$existing['id'], 'UPDATE', $userId, 'orthanc_unpublish', ['removed_from_orthanc_only' => true]);

        echo json_encode([
            'success' => true,
            'message' => 'Entrada quitada de Orthanc; permanece en el portal.'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // Eliminación completa: Orthanc primero, luego BD local
    try {
        $service->removeFromOrthanc($existing);
    } catch (Exception $e) {
        error_log("Error eliminando de Orthanc: " . $e->getMessage());
    }

    $stmt = $db->prepare("DELETE FROM worklist WHERE accession_number = ?");
    $stmt->execute([$accession]);
    
    logWorklistAction($db, $existing['id'], 'DELETE', $userId, null, null);
    
    echo json_encode([
        'success' => true,
        'message' => 'Worklist eliminado exitosamente'
    ]);
}

/**
 * Sincronizar con Orthanc
 */
function handleSync($db, $userId, WorklistIngestionService $service) {
    // Turnos activos que aún no están ok en Orthanc o fallaron (reintento)
    $stmt = $db->prepare("
        SELECT * FROM worklist 
        WHERE status IN ('pending', 'scheduled', 'in_progress')
          AND (
            orthanc_sync_status IS NULL
            OR orthanc_sync_status = ''
            OR orthanc_sync_status = 'pending'
            OR orthanc_sync_status = 'error'
          )
        ORDER BY scheduled_date, scheduled_time
    ");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $synced = 0;
    $errors = [];
    
    foreach ($items as $item) {
        try {
            $service->syncOneToOrthanc($item);
            $synced++;
        } catch (Exception $e) {
            $stmtUpdate = $db->prepare("UPDATE worklist SET orthanc_sync_status='error', orthanc_sync_error=? WHERE id=?");
            $stmtUpdate->execute([$e->getMessage(), $item['id']]);
            $errors[] = [
                'accession' => $item['accession_number'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Sincronización completada: $synced archivos procesados",
        'synced' => $synced,
        'errors' => $errors
    ]);
}

/**
 * Guardar item de worklist (INSERT o UPDATE)
 */
function saveWorklistItem($db, $data, $userId, $action, $source) {
    // Verificar si existe
    $stmt = $db->prepare("SELECT id FROM worklist WHERE accession_number = ?");
    $stmt->execute([$data['accession_number']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // UPDATE
        $fields = [
            'patient_name', 'patient_id', 'patient_birth_date', 'patient_sex',
            'modality', 'referring_physician', 'equipment_name',
            'scheduled_date', 'scheduled_time', 'procedure_description',
            'reason_for_study', 'status', 'source_file'
        ];
        
        $updates = [];
        $bindings = [];
        
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $bindings[] = $data[$field];
            }
        }
        
        $bindings[] = $data['accession_number'];
        
        $sql = "UPDATE worklist SET " . implode(", ", $updates) . " WHERE accession_number = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        
        $worklistId = $existing['id'];
        $isNew = false;
    } else {
        // INSERT
        $sql = "INSERT INTO worklist (
            accession_number, patient_name, patient_id, patient_birth_date, patient_sex,
            modality, referring_physician, equipment_name,
            scheduled_date, scheduled_time, procedure_description, reason_for_study,
            status, source_file
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $data['accession_number'],
            $data['patient_name'] ?? null,
            $data['patient_id'] ?? null,
            $data['patient_birth_date'] ?? null,
            $data['patient_sex'] ?? null,
            $data['modality'] ?? null,
            $data['referring_physician'] ?? null,
            $data['equipment_name'] ?? null,
            $data['scheduled_date'],
            $data['scheduled_time'],
            $data['procedure_description'] ?? null,
            $data['reason_for_study'] ?? null,
            $data['status'] ?? 'pending',
            $data['source_file'] ?? null
        ]);
        
        $worklistId = $db->lastInsertId();
        $isNew = true;
    }
    
    // Registrar en log
    logWorklistAction($db, $worklistId, $action, $userId, $source, $data);
    
    // Obtener item completo
    $stmt = $db->prepare("SELECT * FROM worklist WHERE id = ?");
    $stmt->execute([$worklistId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return array_merge($item, ['is_new' => $isNew]);
}

/**
 * Generar archivo .wl y copiar a Orthanc
 */
function generateAndCopyWl($db, $accession) {
    // Obtener datos del worklist
    $stmt = $db->prepare("SELECT * FROM worklist WHERE accession_number = ?");
    $stmt->execute([$accession]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) {
        throw new Exception("Worklist no encontrado: $accession");
    }
    
    // Obtener configuración
    $stmt = $db->prepare("SELECT * FROM worklist_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('Configuración de Worklist no encontrada');
    }
    
    // Generar archivo .wl
    $tempPath = sys_get_temp_dir() . '/' . $accession . '.wl';
    DicomWorklistGenerator::generate($data, $tempPath);
    
    // Copiar a carpeta de Orthanc
    $targetPath = rtrim($config['orthanc_worklist_path'], '/') . '/' . $accession . '.wl';
    
    // Crear directorio si no existe
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio: $targetDir");
        }
    }
    
    // Copiar archivo
    if (!copy($tempPath, $targetPath)) {
        throw new Exception("No se pudo copiar el archivo a: $targetPath");
    }
    
    // Eliminar archivo temporal
    @unlink($tempPath);
    
    return $targetPath;
}

/**
 * Registrar acción en log
 */
function logWorklistAction($db, $worklistId, $action, $userId, $source, $changes) {
    $stmt = $db->prepare("
        INSERT INTO worklist_logs (worklist_id, action, user_id, source, changes)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $worklistId,
        $action,
        $userId,
        $source,
        json_encode($changes)
    ]);
}

/**
 * Asegurar que las tablas existen
 */
function ensureWorklistTables($db) {
    // Tabla worklist
    $sql = "
        CREATE TABLE IF NOT EXISTS `worklist` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `accession_number` VARCHAR(64) UNIQUE NOT NULL,
          `patient_name` VARCHAR(255) NOT NULL,
          `patient_id` VARCHAR(64),
          `patient_birth_date` DATE,
          `patient_sex` ENUM('M','F','O'),
          `modality` VARCHAR(16),
          `referring_physician` VARCHAR(64),
          `equipment_name` VARCHAR(255),
          `scheduled_date` DATE NOT NULL,
          `scheduled_time` TIME NOT NULL,
          `procedure_description` VARCHAR(255),
          `reason_for_study` VARCHAR(255),
          `status` ENUM('pending','scheduled','in_progress','completed','cancelled') DEFAULT 'pending',
          `source_file` VARCHAR(255),
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_accession` (`accession_number`),
          INDEX `idx_date_modality` (`scheduled_date`,`modality`),
          INDEX `idx_status` (`status`),
          INDEX `idx_scheduled_date` (`scheduled_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $db->exec($sql);
    
    // Tabla worklist_logs
    $sql = "
        CREATE TABLE IF NOT EXISTS `worklist_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `worklist_id` INT,
          `action` ENUM('CREATE','UPDATE','DELETE','IMPORT'),
          `user_id` INT,
          `source` VARCHAR(100),
          `changes` JSON,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_worklist_id` (`worklist_id`),
          INDEX `idx_action` (`action`),
          INDEX `idx_user_id` (`user_id`),
          INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $db->exec($sql);
}
