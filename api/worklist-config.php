<?php
/**
 * API para gestión de configuración de Worklist
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/OrthancWorklistManager.php';
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
    
    // Verificar que el usuario tenga permisos de administración
    if (!in_array(strtolower($userData['nivel'] ?? 'user'), ['root', 'admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tienes permisos para acceder a la configuración']);
        exit();
    }
    
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Asegurar que la tabla worklist_config existe
    ensureWorklistConfigTable($db);
    
    $service = new WorklistIngestionService($db);
    $service->ensureSchema();

    // GET: Obtener configuración
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT * FROM worklist_config WHERE id = 1");
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            // Crear configuración por defecto
            $stmt = $db->prepare("
                INSERT INTO worklist_config (
                    id, orthanc_worklist_path, orthanc_host, sync_interval, orthanc_worklist_mode
                )
                VALUES (1, '/var/lib/orthanc/db/WorklistsDatabase', 'localhost', 300, 'filesystem')
            ");
            $stmt->execute();
            
            $stmt = $db->prepare("SELECT * FROM worklist_config WHERE id = 1");
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // No devolver la contraseña SFTP en texto plano (aunque está encriptada)
        unset($config['sftp_pass']);
        unset($config['orthanc_rest_pass']);

        $pacsNodes = [];
        $chk = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'pacs_nodes'
        ");
        $chk->execute();
        if ((int)$chk->fetchColumn() > 0) {
            $pacsNodes = $db->query(
                "SELECT id, name, aet, host, node_type FROM pacs_nodes WHERE is_active = 1 ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        echo json_encode([
            'success' => true,
            'config' => $config,
            'pacs_nodes' => $pacsNodes,
            'message' => 'Configuración obtenida exitosamente'
        ]);
    }
    
    // POST: Guardar configuración
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Datos inválidos');
        }
        
        if (($input['action'] ?? '') === 'test_connection') {
            $testConfig = [
                'orthanc_worklist_mode' => $input['orthanc_worklist_mode'] ?? 'filesystem',
                'orthanc_worklist_path' => $input['orthanc_worklist_path'] ?? '',
                'orthanc_rest_base_url' => $input['orthanc_rest_base_url'] ?? '',
                'orthanc_rest_user' => $input['orthanc_rest_user'] ?? '',
                'orthanc_rest_pass' => $input['orthanc_rest_pass'] ?? '',
                'orthanc_rest_verify_ssl' => (int)($input['orthanc_rest_verify_ssl'] ?? 0),
                'orthanc_rest_timeout' => (int)($input['orthanc_rest_timeout'] ?? 15),
            ];
            $result = OrthancWorklistManager::testConnection($testConfig);
            echo json_encode([
                'success' => true,
                'message' => $result['message'] ?? 'Conexión exitosa'
            ]);
            exit();
        }

        $mode = strtolower((string)($input['orthanc_worklist_mode'] ?? 'filesystem'));
        if ($mode === 'filesystem' && empty($input['orthanc_worklist_path'])) {
            throw new Exception('La ruta de Worklist de Orthanc es requerida en modo filesystem');
        }
        if ($mode === 'rest' && empty($input['orthanc_rest_base_url'])) {
            throw new Exception('orthanc_rest_base_url es requerido en modo REST');
        }
        
        // Preparar datos para actualización
        $fields = [
            'orthanc_worklist_path' => $input['orthanc_worklist_path'] ?? '/var/lib/orthanc/db/WorklistsDatabase',
            'orthanc_host' => $input['orthanc_host'] ?? 'localhost',
            'orthanc_worklist_mode' => $mode,
            'orthanc_rest_base_url' => $input['orthanc_rest_base_url'] ?? null,
            'orthanc_rest_user' => $input['orthanc_rest_user'] ?? null,
            'orthanc_rest_pass' => !empty($input['orthanc_rest_pass']) ? $input['orthanc_rest_pass'] : null,
            'orthanc_rest_verify_ssl' => (int)($input['orthanc_rest_verify_ssl'] ?? 0),
            'orthanc_rest_timeout' => (int)($input['orthanc_rest_timeout'] ?? 15),
            'pull_enabled' => (int)($input['pull_enabled'] ?? 0),
            'pull_input_path' => $input['pull_input_path'] ?? null,
            'pull_processed_path' => $input['pull_processed_path'] ?? null,
            'pull_failed_path' => $input['pull_failed_path'] ?? null,
            'worklist_ingest_mode' => normalizeWorklistIngestMode($input['worklist_ingest_mode'] ?? null),
            'push_enabled' => (int)($input['push_enabled'] ?? 0),
            'push_allowed_ips' => $input['push_allowed_ips'] ?? null,
            'sftp_host' => $input['sftp_host'] ?? null,
            'sftp_port' => $input['sftp_port'] ?? 22,
            'sftp_user' => $input['sftp_user'] ?? null,
            'sftp_pass' => !empty($input['sftp_pass']) ? $input['sftp_pass'] : null,
            'sync_interval' => $input['sync_interval'] ?? 300,
            'pacs_reconcile_node_id' => isset($input['pacs_reconcile_node_id']) && $input['pacs_reconcile_node_id'] !== '' && $input['pacs_reconcile_node_id'] !== null
                ? (int)$input['pacs_reconcile_node_id']
                : null,
            'pacs_reconcile_on_list_load' => (int)($input['pacs_reconcile_on_list_load'] ?? 0),
            'pacs_reconcile_min_interval_minutes' => max(5, min(1440, (int)($input['pacs_reconcile_min_interval_minutes'] ?? 30))),
            'pacs_reconcile_max_per_request' => max(1, min(80, (int)($input['pacs_reconcile_max_per_request'] ?? 40))),
            'pacs_reconcile_day_span_days' => max(1, min(365, (int)($input['pacs_reconcile_day_span_days'] ?? 45))),
            'hl7_enabled' => (int)($input['hl7_enabled'] ?? 0),
            'hl7_port' => max(1, min(65535, (int)($input['hl7_port'] ?? 2575))),
            'hl7_bind_host' => trim((string)($input['hl7_bind_host'] ?? '0.0.0.0')) ?: '0.0.0.0',
            'hl7_input_path' => $input['hl7_input_path'] ?? null,
            'hl7_processed_path' => $input['hl7_processed_path'] ?? null,
            'hl7_failed_path' => $input['hl7_failed_path'] ?? null,
            'hl7_prestador_field' => normalizeHl7PrestadorField($input['hl7_prestador_field'] ?? 'PV1-8'),
            'hl7_spawn_worker_on_receive' => (int)($input['hl7_spawn_worker_on_receive'] ?? 1),
        ];

        // Modo de canal es la fuente de verdad → sincronizar flags
        $mode = $fields['worklist_ingest_mode'];
        if ($mode === 'txt') {
            $fields['pull_enabled'] = 1;
            $fields['hl7_enabled'] = 0;
        } elseif ($mode === 'hl7') {
            $fields['pull_enabled'] = 0;
            $fields['hl7_enabled'] = 1;
        } elseif ($mode === 'both') {
            $fields['pull_enabled'] = 1;
            $fields['hl7_enabled'] = 1;
        } elseif ($mode === 'none') {
            $fields['pull_enabled'] = 0;
            $fields['hl7_enabled'] = 0;
        } else {
            // compat: si no mandan mode, derivar desde flags
            $t = (int)$fields['pull_enabled'] === 1;
            $h = (int)$fields['hl7_enabled'] === 1;
            $fields['worklist_ingest_mode'] = ($t && $h) ? 'both' : ($h ? 'hl7' : ($t ? 'txt' : 'none'));
        }
        
        // Si no se proporciona contraseña, mantener la existente
        if (empty($fields['sftp_pass'])) {
            $stmt = $db->prepare("SELECT sftp_pass FROM worklist_config WHERE id = 1");
            $stmt->execute();
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && !empty($existing['sftp_pass'])) {
                $fields['sftp_pass'] = $existing['sftp_pass'];
            }
        }

        if (empty($fields['orthanc_rest_pass'])) {
            $stmt = $db->prepare("SELECT orthanc_rest_pass FROM worklist_config WHERE id = 1");
            $stmt->execute();
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && !empty($existing['orthanc_rest_pass'])) {
                $fields['orthanc_rest_pass'] = $existing['orthanc_rest_pass'];
            }
        }
        
        // Actualizar o insertar
        $stmt = $db->prepare("
            INSERT INTO worklist_config (
                id, orthanc_worklist_path, orthanc_host, orthanc_worklist_mode,
                orthanc_rest_base_url, orthanc_rest_user, orthanc_rest_pass,
                orthanc_rest_verify_ssl, orthanc_rest_timeout,
                pull_enabled, pull_input_path, pull_processed_path, pull_failed_path,
                push_enabled, push_allowed_ips,
                sftp_host, sftp_port, sftp_user, sftp_pass, sync_interval,
                pacs_reconcile_node_id, pacs_reconcile_on_list_load,
                pacs_reconcile_min_interval_minutes, pacs_reconcile_max_per_request,
                pacs_reconcile_day_span_days,
                hl7_enabled, hl7_port, hl7_bind_host,
                hl7_input_path, hl7_processed_path, hl7_failed_path,
                hl7_prestador_field, hl7_spawn_worker_on_receive,
                worklist_ingest_mode
            ) VALUES (
                1, :orthanc_worklist_path, :orthanc_host, :orthanc_worklist_mode,
                :orthanc_rest_base_url, :orthanc_rest_user, :orthanc_rest_pass,
                :orthanc_rest_verify_ssl, :orthanc_rest_timeout,
                :pull_enabled, :pull_input_path, :pull_processed_path, :pull_failed_path,
                :push_enabled, :push_allowed_ips,
                :sftp_host, :sftp_port, :sftp_user, :sftp_pass, :sync_interval,
                :pacs_reconcile_node_id, :pacs_reconcile_on_list_load,
                :pacs_reconcile_min_interval_minutes, :pacs_reconcile_max_per_request,
                :pacs_reconcile_day_span_days,
                :hl7_enabled, :hl7_port, :hl7_bind_host,
                :hl7_input_path, :hl7_processed_path, :hl7_failed_path,
                :hl7_prestador_field, :hl7_spawn_worker_on_receive,
                :worklist_ingest_mode
            )
            ON DUPLICATE KEY UPDATE
                orthanc_worklist_path = VALUES(orthanc_worklist_path),
                orthanc_host = VALUES(orthanc_host),
                orthanc_worklist_mode = VALUES(orthanc_worklist_mode),
                orthanc_rest_base_url = VALUES(orthanc_rest_base_url),
                orthanc_rest_user = VALUES(orthanc_rest_user),
                orthanc_rest_pass = COALESCE(VALUES(orthanc_rest_pass), orthanc_rest_pass),
                orthanc_rest_verify_ssl = VALUES(orthanc_rest_verify_ssl),
                orthanc_rest_timeout = VALUES(orthanc_rest_timeout),
                pull_enabled = VALUES(pull_enabled),
                pull_input_path = VALUES(pull_input_path),
                pull_processed_path = VALUES(pull_processed_path),
                pull_failed_path = VALUES(pull_failed_path),
                push_enabled = VALUES(push_enabled),
                push_allowed_ips = VALUES(push_allowed_ips),
                sftp_host = VALUES(sftp_host),
                sftp_port = VALUES(sftp_port),
                sftp_user = VALUES(sftp_user),
                sftp_pass = COALESCE(VALUES(sftp_pass), sftp_pass),
                sync_interval = VALUES(sync_interval),
                pacs_reconcile_node_id = VALUES(pacs_reconcile_node_id),
                pacs_reconcile_on_list_load = VALUES(pacs_reconcile_on_list_load),
                pacs_reconcile_min_interval_minutes = VALUES(pacs_reconcile_min_interval_minutes),
                pacs_reconcile_max_per_request = VALUES(pacs_reconcile_max_per_request),
                pacs_reconcile_day_span_days = VALUES(pacs_reconcile_day_span_days),
                hl7_enabled = VALUES(hl7_enabled),
                hl7_port = VALUES(hl7_port),
                hl7_bind_host = VALUES(hl7_bind_host),
                hl7_input_path = VALUES(hl7_input_path),
                hl7_processed_path = VALUES(hl7_processed_path),
                hl7_failed_path = VALUES(hl7_failed_path),
                hl7_prestador_field = VALUES(hl7_prestador_field),
                hl7_spawn_worker_on_receive = VALUES(hl7_spawn_worker_on_receive),
                worklist_ingest_mode = VALUES(worklist_ingest_mode)
        ");
        
        $stmt->execute($fields);

        // Snapshot para listener MLLP del módulo hl7-worklist
        $hl7SnapshotNote = null;
        try {
            $hl7Boot = __DIR__ . '/../modules/hl7-worklist/php/bootstrap.php';
            if (is_file($hl7Boot)) {
                require_once $hl7Boot;
                Hl7WorklistModule::ensureConfigColumns($db);
                Hl7WorklistModule::ensureIngestSourceType($db);
                $snapPath = Hl7WorklistModule::writeConfigSnapshot($db);
                $hl7SnapshotNote = $snapPath;
            }
        } catch (Exception $eSnap) {
            error_log('worklist-config HL7 snapshot: ' . $eSnap->getMessage());
            $hl7SnapshotNote = 'error: ' . $eSnap->getMessage();
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuración guardada exitosamente',
            'hl7_config_snapshot' => $hl7SnapshotNote,
        ]);
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Asegurar que la tabla worklist_config existe
 */
function ensureWorklistConfigTable($db) {
    $sql = "
        CREATE TABLE IF NOT EXISTS `worklist_config` (
          `id` INT PRIMARY KEY DEFAULT 1,
          `orthanc_worklist_path` VARCHAR(500) NOT NULL DEFAULT '/var/lib/orthanc/db/WorklistsDatabase',
          `orthanc_host` VARCHAR(255) DEFAULT 'localhost',
          `orthanc_worklist_mode` ENUM('filesystem','rest') DEFAULT 'filesystem',
          `orthanc_rest_base_url` VARCHAR(500) DEFAULT NULL,
          `orthanc_rest_user` VARCHAR(255) DEFAULT NULL,
          `orthanc_rest_pass` VARCHAR(255) DEFAULT NULL,
          `orthanc_rest_verify_ssl` TINYINT(1) DEFAULT 0,
          `orthanc_rest_timeout` INT DEFAULT 15,
          `pull_enabled` TINYINT(1) DEFAULT 0,
          `pull_input_path` VARCHAR(500) DEFAULT NULL,
          `pull_processed_path` VARCHAR(500) DEFAULT NULL,
          `pull_failed_path` VARCHAR(500) DEFAULT NULL,
          `push_enabled` TINYINT(1) DEFAULT 0,
          `push_allowed_ips` TEXT DEFAULT NULL,
          `sftp_host` VARCHAR(255),
          `sftp_port` INT DEFAULT 22,
          `sftp_user` VARCHAR(255),
          `sftp_pass` VARCHAR(255),
          `sync_interval` INT DEFAULT 300,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT `chk_single_config` CHECK (`id` = 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $db->exec($sql);
    
    // Insertar configuración inicial si no existe
    $stmt = $db->prepare("SELECT COUNT(*) FROM worklist_config WHERE id = 1");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $stmt = $db->prepare("
            INSERT INTO worklist_config (
                id, orthanc_worklist_path, orthanc_host, sync_interval, orthanc_worklist_mode
            ) VALUES (1, '/var/lib/orthanc/db/WorklistsDatabase', 'localhost', 300, 'filesystem')
        ");
        $stmt->execute();
    }

    // Migraciones para instalaciones existentes (compatible con MySQL/MariaDB sin IF NOT EXISTS)
    addColumnIfMissing($db, 'worklist_config', 'orthanc_worklist_mode', "ALTER TABLE worklist_config ADD COLUMN orthanc_worklist_mode ENUM('filesystem','rest') DEFAULT 'filesystem'");
    addColumnIfMissing($db, 'worklist_config', 'orthanc_rest_base_url', "ALTER TABLE worklist_config ADD COLUMN orthanc_rest_base_url VARCHAR(500) DEFAULT NULL");
    addColumnIfMissing($db, 'worklist_config', 'orthanc_rest_user', "ALTER TABLE worklist_config ADD COLUMN orthanc_rest_user VARCHAR(255) DEFAULT NULL");
    addColumnIfMissing($db, 'worklist_config', 'orthanc_rest_pass', "ALTER TABLE worklist_config ADD COLUMN orthanc_rest_pass VARCHAR(255) DEFAULT NULL");
    addColumnIfMissing($db, 'worklist_config', 'orthanc_rest_verify_ssl', "ALTER TABLE worklist_config ADD COLUMN orthanc_rest_verify_ssl TINYINT(1) DEFAULT 0");
    addColumnIfMissing($db, 'worklist_config', 'orthanc_rest_timeout', "ALTER TABLE worklist_config ADD COLUMN orthanc_rest_timeout INT DEFAULT 15");
    addColumnIfMissing($db, 'worklist_config', 'pull_enabled', "ALTER TABLE worklist_config ADD COLUMN pull_enabled TINYINT(1) DEFAULT 0");
    addColumnIfMissing($db, 'worklist_config', 'pull_input_path', "ALTER TABLE worklist_config ADD COLUMN pull_input_path VARCHAR(500) DEFAULT NULL");
    addColumnIfMissing($db, 'worklist_config', 'pull_processed_path', "ALTER TABLE worklist_config ADD COLUMN pull_processed_path VARCHAR(500) DEFAULT NULL");
    addColumnIfMissing($db, 'worklist_config', 'pull_failed_path', "ALTER TABLE worklist_config ADD COLUMN pull_failed_path VARCHAR(500) DEFAULT NULL");
    addColumnIfMissing($db, 'worklist_config', 'push_enabled', "ALTER TABLE worklist_config ADD COLUMN push_enabled TINYINT(1) DEFAULT 0");
    addColumnIfMissing($db, 'worklist_config', 'push_allowed_ips', "ALTER TABLE worklist_config ADD COLUMN push_allowed_ips TEXT DEFAULT NULL");

    // Módulo HL7 MLLP
    $hl7Boot = __DIR__ . '/../modules/hl7-worklist/php/bootstrap.php';
    if (is_file($hl7Boot)) {
        require_once $hl7Boot;
        Hl7WorklistModule::ensureConfigColumns($db);
        Hl7WorklistModule::ensureIngestSourceType($db);
    } else {
        addColumnIfMissing($db, 'worklist_config', 'hl7_enabled', "ALTER TABLE worklist_config ADD COLUMN hl7_enabled TINYINT(1) NOT NULL DEFAULT 0");
        addColumnIfMissing($db, 'worklist_config', 'hl7_port', "ALTER TABLE worklist_config ADD COLUMN hl7_port INT NOT NULL DEFAULT 2575");
        addColumnIfMissing($db, 'worklist_config', 'hl7_bind_host', "ALTER TABLE worklist_config ADD COLUMN hl7_bind_host VARCHAR(64) NOT NULL DEFAULT '0.0.0.0'");
        addColumnIfMissing($db, 'worklist_config', 'hl7_input_path', "ALTER TABLE worklist_config ADD COLUMN hl7_input_path VARCHAR(500) DEFAULT NULL");
        addColumnIfMissing($db, 'worklist_config', 'hl7_processed_path', "ALTER TABLE worklist_config ADD COLUMN hl7_processed_path VARCHAR(500) DEFAULT NULL");
        addColumnIfMissing($db, 'worklist_config', 'hl7_failed_path', "ALTER TABLE worklist_config ADD COLUMN hl7_failed_path VARCHAR(500) DEFAULT NULL");
        addColumnIfMissing($db, 'worklist_config', 'hl7_prestador_field', "ALTER TABLE worklist_config ADD COLUMN hl7_prestador_field VARCHAR(16) NOT NULL DEFAULT 'PV1-8'");
        addColumnIfMissing($db, 'worklist_config', 'hl7_spawn_worker_on_receive', "ALTER TABLE worklist_config ADD COLUMN hl7_spawn_worker_on_receive TINYINT(1) NOT NULL DEFAULT 1");
    }
    addColumnIfMissing($db, 'worklist_config', 'worklist_ingest_mode', "ALTER TABLE worklist_config ADD COLUMN worklist_ingest_mode VARCHAR(16) NOT NULL DEFAULT 'none'");

    WorklistPacsReconcileService::ensureWorklistConfigPacsColumns($db);
}

function normalizeWorklistIngestMode($value): string
{
    $v = strtolower(trim((string)($value ?? '')));
    $allowed = ['none', 'txt', 'hl7', 'both'];
    return in_array($v, $allowed, true) ? $v : '';
}

function normalizeHl7PrestadorField($value): string
{
    $v = strtoupper(trim((string)$value));
    $allowed = ['PV1-7', 'PV1-8', 'OBR-16', 'NONE'];
    return in_array($v, $allowed, true) ? $v : 'PV1-8';
}

function addColumnIfMissing(PDO $db, string $table, string $column, string $alterSql): void {
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = :table_name 
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column
    ]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if (!$exists) {
        $db->exec($alterSql);
    }
}
