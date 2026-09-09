<?php
/**
 * Funciones compartidas para envío/cola FTP de audios.
 */

require_once __DIR__ . '/../../classes/FtpClient.php';

function ftpAllowedAudioStates(): array {
    return ['guardado_informe', 'enviado_transcripcion', 'enviado_ftp'];
}

function ftpGetExistingSuccessfulSend(PDO $db, int $audioId): ?array {
    try {
        $stmt = $db->prepare("
            SELECT id, file_name, sent_at
            FROM audios_ftp_log
            WHERE audio_id = ? AND status = 'success'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$audioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (Exception $e) {
        error_log('ftp-helpers: Error consultando audios_ftp_log: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT fecha_envio_ftp AS sent_at
            FROM audios_informe
            WHERE id = ? AND fecha_envio_ftp IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$audioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['sent_at'])) {
            return ['file_name' => null, 'sent_at' => $row['sent_at']];
        }
    } catch (Exception $e) {
        error_log('ftp-helpers: Error consultando fecha_envio_ftp: ' . $e->getMessage());
    }

    return null;
}

function ftpHasPendingQueueEntry(PDO $db, int $audioId): bool {
    $stmt = $db->prepare("
        SELECT id FROM audios_ftp_log
        WHERE audio_id = ? AND status IN ('pending', 'retrying')
        LIMIT 1
    ");
    $stmt->execute([$audioId]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function ftpEnsureLogTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS audios_ftp_log (
            id INT(11) NOT NULL AUTO_INCREMENT,
            audio_id INT(11) NOT NULL,
            ftp_host VARCHAR(255) NOT NULL,
            remote_path VARCHAR(500) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            status ENUM('pending', 'success', 'failed', 'retrying') NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            attempts INT(11) NOT NULL DEFAULT 0,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_audio_id (audio_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ftpGetConfigurationForUser(PDO $db, $userId) {
    $query = "SELECT * FROM usuarios_ftp_config
              WHERE usuario_id = ? AND activo = 1
              ORDER BY created_at DESC
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        return null;
    }
    if (!empty($config['ftp_password'])) {
        $config['ftp_password'] = ftpDecryptPassword($config['ftp_password']);
    }
    return $config;
}

function ftpDecryptPassword($encryptedValue) {
    if (empty($encryptedValue) || $encryptedValue === '••••••••') {
        return '';
    }
    try {
        $decoded = base64_decode($encryptedValue);
        if (strpos($decoded, '::') === false) {
            return $encryptedValue;
        }
        list($encrypted, $iv) = explode('::', $decoded, 2);
        $encryptionKey = ftpGetEncryptionKey();
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);
        return $decrypted !== false ? $decrypted : $encryptedValue;
    } catch (Exception $e) {
        error_log('ftp-helpers: Error desencriptando contraseña FTP: ' . $e->getMessage());
        return $encryptedValue;
    }
}

function ftpGetEncryptionKey() {
    return hash('sha256', 'tjsmedical_ftp_encryption_key_2024', true);
}

function ftpGetFfmpegRestUrl(PDO $db): ?string {
    try {
        $stmt = $db->prepare('SELECT ffmpeg_rest_url FROM ai_config WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['ffmpeg_rest_url'])) {
            return rtrim($row['ffmpeg_rest_url'], '/');
        }
    } catch (Exception $e) {
        error_log('ftp-helpers: Error obteniendo ffmpeg_rest_url: ' . $e->getMessage());
    }
    return null;
}

function ftpGetAudioDurationWithFfmpeg(string $filePath): ?int {
    $ffmpegPath = shell_exec('which ffmpeg 2>&1');
    if (empty(trim($ffmpegPath ?? ''))) {
        return null;
    }
    $command = 'ffmpeg -i ' . escapeshellarg($filePath) . " 2>&1 | grep 'Duration' | head -1";
    $output = shell_exec($command);
    if (empty($output)) {
        return null;
    }
    if (preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}\.?\d*)/', $output, $matches)) {
        $totalSeconds = (int) ($matches[1] * 3600 + $matches[2] * 60 + (float) $matches[3]);
        return $totalSeconds > 0 ? $totalSeconds : null;
    }
    return null;
}

function ftpResolveAudioFilePath(array $audioData): string {
    $root = dirname(__DIR__, 2);
    $filePath = $root . '/' . ltrim($audioData['ruta_archivo'] ?? '', '/');
    if (!file_exists($filePath) && !empty($audioData['backup_path'])) {
        $filePath = $root . '/' . ltrim($audioData['backup_path'], '/');
    }
    return $filePath;
}

function ftpBuildRemoteFileName(array $userData, array $audioData, PDO $db): string {
    $rawUserName = trim(($userData['nombre'] ?? '') . ' ' . ($userData['apellido'] ?? ''));
    if ($rawUserName === '') {
        $rawUserName = $userData['email'] ?? 'usuario';
    }
    $userSlug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $rawUserName), '_') ?: 'usuario';

    $patientId = 'sin_id';
    if (!empty($audioData['informe_id'])) {
        $stmtInf = $db->prepare('SELECT patient_id FROM informes WHERE id = ?');
        $stmtInf->execute([$audioData['informe_id']]);
        $informeData = $stmtInf->fetch(PDO::FETCH_ASSOC);
        if ($informeData && !empty($informeData['patient_id'])) {
            $clean = preg_replace('/[^A-Za-z0-9]+/', '', $informeData['patient_id']);
            if ($clean !== '') {
                $patientId = $clean;
            }
        }
    }

    if ($patientId === 'sin_id' && !empty($audioData['estudio_id'])) {
        $stmtEst = $db->prepare(
            "SELECT patient_id_pacs FROM estudios
             WHERE orthanc_study_id = ? OR study_instance_uid = ? OR CONVERT(id, CHAR) = ?
             LIMIT 1"
        );
        $stmtEst->execute([
            $audioData['estudio_id'],
            $audioData['estudio_id'],
            $audioData['estudio_id'],
        ]);
        $estudyData = $stmtEst->fetch(PDO::FETCH_ASSOC);
        if ($estudyData && !empty($estudyData['patient_id_pacs'])) {
            $clean = preg_replace('/[^A-Za-z0-9]+/', '', $estudyData['patient_id_pacs']);
            if ($clean !== '') {
                $patientId = $clean;
            }
        }
    }

    return $userSlug . '_' . $patientId . '_' . date('Ymd_His') . '.mp3';
}

function ftpUpdateAudioStateAfterSuccess(PDO $db, int $audioId, array $audioData, array $userData, string $remoteFileName, string $ftpHost): void {
    $columnsCheck = $db->query("SHOW COLUMNS FROM audios_informe LIKE 'estado'");
    if ($columnsCheck->rowCount() === 0) {
        return;
    }

    $currentStateStmt = $db->prepare('SELECT estado FROM audios_informe WHERE id = ?');
    $currentStateStmt->execute([$audioId]);
    $currentState = $currentStateStmt->fetchColumn();

    $estadosFinales = ['enviado_ftp', 'eliminado'];
    if (!$currentState || in_array($currentState, $estadosFinales, true)) {
        return;
    }

    $updateStmt = $db->prepare("UPDATE audios_informe SET estado = 'enviado_ftp', fecha_envio_ftp = NOW() WHERE id = ?");
    $updateStmt->execute([$audioId]);

    try {
        $tablesCheck = $db->query("SHOW TABLES LIKE 'audios_estado_log'");
        if ($tablesCheck->rowCount() > 0) {
            $logStmt = $db->prepare("
                INSERT INTO audios_estado_log (
                    audio_id, estado_anterior, estado_nuevo, accion, usuario_id, estudio_id, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $logStmt->execute([
                $audioId,
                $currentState,
                'enviado_ftp',
                'enviar_ftp',
                $userData['id'] ?? null,
                $audioData['estudio_id'] ?? null,
                json_encode(['remote_file' => $remoteFileName, 'ftp_host' => $ftpHost]),
            ]);
        }
    } catch (Exception $e) {
        error_log('ftp-helpers: Error en audios_estado_log: ' . $e->getMessage());
    }
}

function ftpLoadUserForAudio(PDO $db, int $userId): ?array {
    $stmt = $db->prepare('SELECT id, nombre, apellido, email FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
