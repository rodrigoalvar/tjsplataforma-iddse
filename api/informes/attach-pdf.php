<?php
/**
 * Adjuntar un informe PDF externo a un estudio (crea registro en informes).
 * Permisos: all, gestionInformes o adjuntarInformes.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/informe_medico_responsable_helper.php';
require_once __DIR__ . '/pdf_metadata_title.php';

function jsonError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

try {
    $sessionToken = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_POST['session_token'] ?? null;
    }
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken) {
        jsonError(401, 'Token de sesión requerido');
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        jsonError(401, 'Sesión inválida');
    }

    $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
    if (!is_array($userPermissions)) {
        $userPermissions = [];
    }

    $canAttach = in_array('all', $userPermissions, true)
        || in_array('gestionInformes', $userPermissions, true)
        || in_array('adjuntarInformes', $userPermissions, true);

    if (!$canAttach) {
        jsonError(403, 'No tienes permisos para adjuntar informes PDF');
    }

    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió el archivo PDF válido');
    }

    $file = $_FILES['pdf_file'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $fileType = '';
    if (function_exists('mime_content_type')) {
        $fileType = (string)mime_content_type($file['tmp_name']);
    } elseif (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $fileType = (string)$fi->file($file['tmp_name']);
    }

    if ($fileExtension !== 'pdf' || ($fileType !== '' && $fileType !== 'application/pdf' && $fileType !== 'application/x-pdf')) {
        throw new Exception('El archivo debe ser un PDF válido');
    }

    $maxSize = 50 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        throw new Exception('El archivo es demasiado grande. Máximo permitido: 50MB');
    }

    $informeId   = isset($_POST['informe_id']) ? (int)$_POST['informe_id'] : 0;
    $estudioId   = $_POST['estudio_id']          ?? null;
    $patientId   = $_POST['patient_id']          ?? null;
    $patientName = $_POST['patient_name']        ?? null;
    $modality    = $_POST['modality']            ?? null;
    $studyDescription = $_POST['study_description']   ?? null;
    $studyInstanceUID = $_POST['study_instance_uid']  ?? null;
    $studyId          = $_POST['study_id']             ?? null;
    $accessionNumber  = $_POST['accession_number']     ?? null;

    // Variante A: informe_id proporcionado → verificar que existe
    if ($informeId > 0) {
        $db = getDBConnection();
        $chkStmt = $db->prepare("SELECT id, estudio_id, patient_name, patient_id FROM informes WHERE id = ?");
        $chkStmt->execute([$informeId]);
        $existingInforme = $chkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingInforme) {
            throw new Exception('El informe seleccionado no existe');
        }
        $estudioId   = $existingInforme['estudio_id'];
        $patientName = $patientName ?: ($existingInforme['patient_name'] ?? null);
        $patientId   = $patientId   ?: ($existingInforme['patient_id']   ?? null);
    } elseif (empty($estudioId)) {
        throw new Exception('Se requiere informe_id o el ID del estudio');
    } else {
        $db = getDBConnection();
    }

    $uploadsBase = realpath(__DIR__ . '/../../');
    if ($uploadsBase === false) {
        throw new Exception('No se pudo resolver la ruta base de uploads');
    }

    $pdfDir = $uploadsBase . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pdf_informes';
    if (!is_dir($pdfDir)) {
        if (!@mkdir($pdfDir, 0775, true) && !is_dir($pdfDir)) {
            throw new Exception('No se pudo crear el directorio de PDFs');
        }
        @chmod($pdfDir, 0775);
    }

    if (!is_writable($pdfDir)) {
        throw new Exception('El directorio de PDFs no es escribible');
    }

    $timestamp = date('Ymd_His');
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $pdfFilename = 'adjunto_' . $timestamp . '_' . $safeFilename . '.pdf';
    $savedPdfPath = $pdfDir . DIRECTORY_SEPARATOR . $pdfFilename;

    if (!move_uploaded_file($file['tmp_name'], $savedPdfPath)) {
        throw new Exception('No se pudo guardar el archivo PDF');
    }

    @chmod($savedPdfPath, 0644);
    ir_applyPdfTitleMetadataIfConfigured($db, $savedPdfPath);

    $pdfPathRelative = 'uploads/pdf_informes/' . $pdfFilename;

    $titulo = $_POST['titulo'] ?? null;
    if (empty($titulo)) {
        $titulo = 'Informe PDF Adjunto - ' . ($patientName ?? 'Paciente') . ' - ' . date('d/m/Y');
    }

    $checkColumnStmt = $db->query("SHOW COLUMNS FROM informes LIKE 'es_adjunto'");
    $hasEsAdjuntoColumn = $checkColumnStmt && $checkColumnStmt->rowCount() > 0;

    // ── Variante A: adjuntar PDF a informe existente ──────────────────────────
    if ($informeId > 0) {
        // Bloque HTML del visor de PDF para agregar al final del contenido existente
        $pdfViewerBlock = "\n".'<div class="informe-adjunto-extra alert alert-info mt-3 text-center">
            <p><i class="fas fa-file-pdf fa-2x text-danger mb-2"></i></p>
            <p class="mb-2"><strong>PDF Adjunto:</strong> ' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</p>
            <button type="button" class="btn btn-primary pdf-viewer-btn"
                data-pdf-path="' . htmlspecialchars($pdfPathRelative, ENT_QUOTES, 'UTF-8') . '"
                data-pdf-title="' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '">
                <i class="fas fa-eye me-2"></i>Ver PDF
            </button>
        </div>';

        if ($hasEsAdjuntoColumn) {
            $updStmt = $db->prepare("UPDATE informes
                SET pdf_path       = ?,
                    titulo         = ?,
                    contenido_html = CONCAT(IFNULL(contenido_html,''), ?),
                    notas_revision = CONCAT(IFNULL(notas_revision,''), '\nPDF externo adjunto')
                WHERE id = ?");
            $updStmt->execute([$pdfPathRelative, $titulo, $pdfViewerBlock, $informeId]);
        } else {
            $updStmt = $db->prepare("UPDATE informes
                SET pdf_path       = ?,
                    titulo         = ?,
                    contenido_html = CONCAT(IFNULL(contenido_html,''), ?)
                WHERE id = ?");
            $updStmt->execute([$pdfPathRelative, $titulo, $pdfViewerBlock, $informeId]);
        }

        $successMessage = 'Informe PDF adjuntado al informe existente exitosamente';

    // ── Variante B: crear nuevo informe desde estudio PACS ────────────────────
    } else {
        $contenidoHtml = '<div class="informe-adjunto alert alert-info text-center">
        <p><i class="fas fa-file-pdf fa-3x text-danger mb-3"></i></p>
        <h5><strong>Informe PDF Adjunto</strong></h5>
        <p>Este informe fue adjuntado como archivo PDF externo.</p>
        <p class="mb-3">Haga clic para visualizar el documento.</p>
        <button type="button" class="btn btn-primary btn-lg pdf-viewer-btn" data-pdf-path="' . htmlspecialchars($pdfPathRelative, ENT_QUOTES, 'UTF-8') . '" data-pdf-title="' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '">
            <i class="fas fa-eye me-2"></i>Ver PDF
        </button>
    </div>';
        $contenidoTexto = 'Informe PDF Adjunto - ' . $titulo;

        $medico = ir_resolve_medico_responsable_estudio($db, [
            'estudio_id' => (string)$estudioId,
            'study_id' => (string)($studyId ?: $estudioId),
            'study_instance_uid' => (string)($studyInstanceUID ?: ''),
            'orthanc_study_id' => (string)$estudioId,
        ]);
        $ownerUserId = !empty($medico['usuario_id']) ? (int)$medico['usuario_id'] : (int)$userData['id'];
        $ownerRol = $medico['medico_informante_rol'] ?? ($userData['rol'] ?? null);

        if ($hasEsAdjuntoColumn) {
            $insertQuery = "INSERT INTO informes (
                estudio_id, study_instance_uid, study_id, usuario_id, patient_id, patient_name,
                modality, study_description, titulo, contenido_html,
                contenido_texto, estado, origen, version, notas_revision, es_adjunto, pdf_path, accession_number, medico_informante_rol
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([
                $estudioId, $studyInstanceUID ?: null, $studyId ?: null,
                $ownerUserId, $patientId, $patientName,
                $modality, $studyDescription, $titulo, $contenidoHtml,
                $contenidoTexto, 'transcripto', 'externo', 1, 'Informe PDF externo adjunto',
                1, $pdfPathRelative, $accessionNumber, $ownerRol
            ]);
        } else {
            $insertQuery = "INSERT INTO informes (
                estudio_id, study_instance_uid, study_id, usuario_id, patient_id, patient_name,
                modality, study_description, titulo, contenido_html,
                contenido_texto, estado, origen, version, notas_revision, pdf_path, accession_number, medico_informante_rol
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([
                $estudioId, $studyInstanceUID ?: null, $studyId ?: null,
                $ownerUserId, $patientId, $patientName,
                $modality, $studyDescription, $titulo, $contenidoHtml,
                $contenidoTexto, 'transcripto', 'externo', 1, 'Informe PDF externo adjunto',
                $pdfPathRelative, $accessionNumber, $ownerRol
            ]);
        }

        $informeId = (int)$db->lastInsertId();
        $successMessage = 'Informe PDF adjuntado exitosamente';
    }

    $selectQuery = "SELECT i.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido
                    FROM informes i
                    LEFT JOIN usuarios u ON i.usuario_id = u.id
                    WHERE i.id = ?";
    $selectStmt = $db->prepare($selectQuery);
    $selectStmt->execute([$informeId]);
    $informe = $selectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$informe) {
        throw new Exception('Error al obtener el informe');
    }

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'data' => [
            'informe_id'   => $informeId,
            'informe'      => $informe,
            'pdf_path'     => $pdfPathRelative,
            'pdf_filename' => $pdfFilename
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Error en attach-pdf.php: ' . $e->getMessage());
    jsonError(400, $e->getMessage());
}
