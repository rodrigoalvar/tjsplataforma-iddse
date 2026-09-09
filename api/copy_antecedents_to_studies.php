<?php
/**
 * API: Copiar antecedentes de un estudio a otros estudios.
 *
 * POST JSON:
 * {
 *   "source_study_id": "...",
 *   "target_study_ids": ["...", "..."],
 *   "created_by": 123,
 *   "notes_mode": "overwrite" | "append",   // default overwrite
 *   "copy_files": true                       // default true
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

/**
 * Resuelve ruta física de un archivo de antecedentes.
 */
function resolveAntecedentFilePath(string $filePath): ?string
{
    $candidates = [];
    $trimmed = trim($filePath);
    if ($trimmed === '') {
        return null;
    }

    if ($trimmed[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $trimmed)) {
        $candidates[] = $trimmed;
    }

    $candidates[] = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $trimmed), '/');
    $candidates[] = __DIR__ . '/' . ltrim(str_replace('\\', '/', $trimmed), '/');

    // Rutas típicas guardadas como ../uploads/... o uploads/...
    if (strpos($trimmed, '../uploads/') === 0) {
        $candidates[] = __DIR__ . '/../' . substr($trimmed, 3);
    }
    if (strpos($trimmed, 'uploads/') === 0) {
        $candidates[] = __DIR__ . '/../' . $trimmed;
    }

    foreach ($candidates as $path) {
        $real = realpath($path);
        if ($real && is_file($real)) {
            return $real;
        }
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Obtiene o crea el registro study_antecedents para un study_id.
 */
function getOrCreateAntecedentId(PDO $pdo, string $studyId, int $createdBy, string $notes = ''): int
{
    $stmt = $pdo->prepare('SELECT id FROM study_antecedents WHERE study_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$studyId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $ins = $pdo->prepare('INSERT INTO study_antecedents (study_id, notes, created_by) VALUES (?, ?, ?)');
    $ins->execute([$studyId, $notes, $createdBy]);
    return (int) $pdo->lastInsertId();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido']);
        exit;
    }

    $sourceStudyId = trim((string) ($input['source_study_id'] ?? ''));
    $targetIds = $input['target_study_ids'] ?? [];
    $createdBy = (int) ($input['created_by'] ?? 0);
    $notesMode = strtolower(trim((string) ($input['notes_mode'] ?? 'overwrite')));
    $copyFiles = !isset($input['copy_files']) || (bool) $input['copy_files'];

    if ($sourceStudyId === '' || $createdBy <= 0 || !is_array($targetIds) || empty($targetIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'source_study_id, target_study_ids y created_by son requeridos']);
        exit;
    }

    if (!in_array($notesMode, ['overwrite', 'append'], true)) {
        $notesMode = 'overwrite';
    }

    $targetIds = array_values(array_unique(array_filter(array_map(function ($id) use ($sourceStudyId) {
        $id = trim((string) $id);
        if ($id === '' || $id === $sourceStudyId) {
            return null;
        }
        return $id;
    }, $targetIds))));

    if (empty($targetIds)) {
        echo json_encode(['success' => true, 'copied' => 0, 'skipped' => 0, 'details' => [], 'message' => 'No hay destinos válidos']);
        exit;
    }

    if (count($targetIds) > 50) {
        $targetIds = array_slice($targetIds, 0, 50);
    }

    $pdo = getDBConnection();

    // Fuente
    $srcStmt = $pdo->prepare('SELECT * FROM study_antecedents WHERE study_id = ? ORDER BY id DESC LIMIT 1');
    $srcStmt->execute([$sourceStudyId]);
    $source = $srcStmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'El estudio origen no tiene antecedentes']);
        exit;
    }

    $sourceNotes = (string) ($source['notes'] ?? '');
    $hasSourceNotes = trim($sourceNotes) !== '';

    $filesStmt = $pdo->prepare('SELECT * FROM study_antecedents_files WHERE antecedent_id = ? ORDER BY id ASC');
    $filesStmt->execute([(int) $source['id']]);
    $sourceFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$hasSourceNotes && empty($sourceFiles)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No hay notas ni archivos para copiar']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/antecedents/';
    if ($copyFiles && !empty($sourceFiles) && !is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $copied = 0;
    $skipped = 0;
    $details = [];

    foreach ($targetIds as $targetId) {
        try {
            $pdo->beginTransaction();

            $tgtStmt = $pdo->prepare('SELECT id, notes FROM study_antecedents WHERE study_id = ? ORDER BY id DESC LIMIT 1');
            $tgtStmt->execute([$targetId]);
            $target = $tgtStmt->fetch(PDO::FETCH_ASSOC);

            $finalNotes = $sourceNotes;
            if ($target) {
                $existingNotes = (string) ($target['notes'] ?? '');
                if ($notesMode === 'append' && trim($existingNotes) !== '' && $hasSourceNotes) {
                    $finalNotes = rtrim($existingNotes) . "\n\n---\n\n" . $sourceNotes;
                } elseif ($notesMode === 'append' && !$hasSourceNotes) {
                    $finalNotes = $existingNotes;
                } elseif (!$hasSourceNotes) {
                    // overwrite pero fuente sin notas: conservar destino
                    $finalNotes = $existingNotes;
                }

                $upd = $pdo->prepare('UPDATE study_antecedents SET notes = ?, updated_date = CURRENT_TIMESTAMP WHERE id = ?');
                $upd->execute([$finalNotes, (int) $target['id']]);
                $antecedentId = (int) $target['id'];
            } else {
                $ins = $pdo->prepare('INSERT INTO study_antecedents (study_id, notes, created_by) VALUES (?, ?, ?)');
                $ins->execute([$targetId, $finalNotes, $createdBy]);
                $antecedentId = (int) $pdo->lastInsertId();
            }

            $filesCopied = 0;
            $filesFailed = 0;

            if ($copyFiles && !empty($sourceFiles)) {
                $insertFile = $pdo->prepare('
                    INSERT INTO study_antecedents_files
                    (antecedent_id, file_name, file_path, file_type, file_size, mime_type)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');

                foreach ($sourceFiles as $file) {
                    $srcPath = resolveAntecedentFilePath((string) ($file['file_path'] ?? ''));
                    if (!$srcPath) {
                        $filesFailed++;
                        continue;
                    }

                    $ext = pathinfo($srcPath, PATHINFO_EXTENSION);
                    $newName = uniqid('copy_', true) . '_' . time() . ($ext ? ('.' . $ext) : '');
                    $destAbs = $uploadDir . $newName;
                    $destRel = '../uploads/antecedents/' . $newName;

                    if (!@copy($srcPath, $destAbs)) {
                        $filesFailed++;
                        continue;
                    }

                    $insertFile->execute([
                        $antecedentId,
                        $file['file_name'] ?? $newName,
                        $destRel,
                        $file['file_type'] ?? 'document',
                        (int) ($file['file_size'] ?? filesize($destAbs)),
                        $file['mime_type'] ?? 'application/octet-stream',
                    ]);
                    $filesCopied++;
                }
            }

            $pdo->commit();
            $copied++;
            $details[] = [
                'study_id' => $targetId,
                'result' => 'copied',
                'files_copied' => $filesCopied,
                'files_failed' => $filesFailed,
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $skipped++;
            $details[] = [
                'study_id' => $targetId,
                'result' => 'error',
                'error' => $e->getMessage(),
            ];
            error_log('copy_antecedents_to_studies: error target=' . $targetId . ' ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'copied' => $copied,
        'skipped' => $skipped,
        'details' => $details,
        'message' => "Antecedentes copiados a {$copied} estudio(s)" . ($skipped > 0 ? ", {$skipped} omitido(s)" : ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
