<?php
/**
 * Envío y cola FTP de audios (conversión MP3 + subida).
 */

require_once __DIR__ . '/FtpClient.php';
require_once __DIR__ . '/../api/audios/ftp-helpers.php';

class FtpAudioSender
{
    /**
     * Encola audios para envío FTP (respuesta inmediata al cliente).
     *
     * @return array{enqueued:int, skipped:int, errors:array}
     */
    public static function enqueueAudioIds(PDO $db, array $audioIds, int $requestUserId): array
    {
        ftpEnsureLogTable($db);

        $enqueued = 0;
        $skipped = 0;
        $errors = [];

        $ids = array_values(array_unique(array_filter(array_map('intval', $audioIds))));
        if (empty($ids)) {
            return ['enqueued' => 0, 'skipped' => 0, 'errors' => []];
        }

        $ftpConfig = ftpGetConfigurationForUser($db, $requestUserId);
        if (!$ftpConfig) {
            return ['enqueued' => 0, 'skipped' => count($ids), 'errors' => ['FTP no configurado para el usuario']];
        }

        if (empty($ftpConfig['ftp_auto_send'])) {
            return ['enqueued' => 0, 'skipped' => count($ids), 'errors' => ['Envío automático FTP deshabilitado']];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT id, informe_id, estudio_id, usuario_id, estado
            FROM audios_informe
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($ids);
        $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($audios as $audio) {
            $audioId = (int) $audio['id'];

            try {
                if (($audio['estado'] ?? '') === 'eliminado') {
                    $skipped++;
                    continue;
                }

                if (empty($audio['informe_id'])) {
                    $skipped++;
                    $errors[] = "Audio $audioId sin informe_id";
                    continue;
                }

                $estado = $audio['estado'] ?? null;
                if ($estado !== null && $estado !== '' && !in_array($estado, ftpAllowedAudioStates(), true)) {
                    $skipped++;
                    continue;
                }

                if (ftpGetExistingSuccessfulSend($db, $audioId)) {
                    $skipped++;
                    continue;
                }

                if (ftpHasPendingQueueEntry($db, $audioId)) {
                    $skipped++;
                    continue;
                }

                $insert = $db->prepare("
                    INSERT INTO audios_ftp_log
                    (audio_id, ftp_host, remote_path, file_name, status, error_message, attempts, sent_at)
                    VALUES (?, ?, ?, ?, 'pending', NULL, 0, NULL)
                ");
                $insert->execute([
                    $audioId,
                    $ftpConfig['ftp_host'],
                    $ftpConfig['ftp_remote_path'],
                    'pending_' . $audioId . '.mp3',
                ]);

                $enqueued++;
            } catch (Exception $e) {
                $errors[] = "Audio $audioId: " . $e->getMessage();
            }
        }

        return ['enqueued' => $enqueued, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Procesa un registro de audios_ftp_log (pending / failed / retrying).
     *
     * @return array{success:bool, message?:string, remote_file?:string, attempts?:int}
     */
    public static function processLogEntry(PDO $db, array $logRow): array
    {
        $logId = (int) $logRow['id'];
        $audioId = (int) $logRow['audio_id'];

        $dupStmt = $db->prepare("
            SELECT id FROM audios_ftp_log
            WHERE audio_id = ? AND status = 'success' AND id != ?
            LIMIT 1
        ");
        $dupStmt->execute([$audioId, $logId]);
        if ($dupStmt->fetch()) {
            $obs = $db->prepare("UPDATE audios_ftp_log SET status = 'success', error_message = 'obsoleto: ya enviado por otra vía', updated_at = NOW() WHERE id = ?");
            $obs->execute([$logId]);
            return ['success' => true, 'message' => 'omitido: ya enviado', 'skipped' => true];
        }

        $audioStmt = $db->prepare("
            SELECT id, nombre_archivo, ruta_archivo, backup_path, estudio_id, informe_id, usuario_id, estado
            FROM audios_informe WHERE id = ?
        ");
        $audioStmt->execute([$audioId]);
        $audioData = $audioStmt->fetch(PDO::FETCH_ASSOC);

        if (!$audioData) {
            throw new Exception('Audio no encontrado');
        }

        if (($audioData['estado'] ?? '') === 'eliminado') {
            $cancel = $db->prepare("UPDATE audios_ftp_log SET status = 'failed', error_message = 'cancelado: audio eliminado', updated_at = NOW() WHERE id = ?");
            $cancel->execute([$logId]);
            return ['success' => false, 'message' => 'audio eliminado', 'skipped' => true];
        }

        $retryStmt = $db->prepare("UPDATE audios_ftp_log SET status = 'retrying', attempts = attempts + 1, updated_at = NOW() WHERE id = ?");
        $retryStmt->execute([$logId]);

        $userData = ftpLoadUserForAudio($db, (int) $audioData['usuario_id']);
        if (!$userData) {
            throw new Exception('Usuario del audio no encontrado');
        }

        $ftpConfig = ftpGetConfigurationForUser($db, (int) $audioData['usuario_id']);
        if (!$ftpConfig) {
            throw new Exception('No hay configuración FTP activa para el usuario del audio');
        }

        $filePath = ftpResolveAudioFilePath($audioData);
        if (!file_exists($filePath)) {
            throw new Exception('Archivo de audio no encontrado: ' . $filePath);
        }

        $tempMp3Path = null;
        try {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($extension !== 'mp3') {
                $ffmpegRestUrl = ftpGetFfmpegRestUrl($db);
                if (!$ffmpegRestUrl) {
                    throw new Exception('ffmpeg-rest no está configurado');
                }

                $mimeType = mime_content_type($filePath) ?: 'audio/webm';
                $ch = curl_init(rtrim($ffmpegRestUrl, '/') . '/audio/mp3');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_POSTFIELDS => [
                        'file' => new CURLFile($filePath, $mimeType, basename($filePath)),
                    ],
                    CURLOPT_HTTPHEADER => ['Accept: audio/mpeg, audio/*, */*'],
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    throw new Exception('Error ffmpeg-rest: ' . $curlError);
                }
                if ($httpCode !== 200 || empty($response)) {
                    throw new Exception('ffmpeg-rest HTTP ' . $httpCode);
                }

                // Usar sys_get_temp_dir() para que sea escribible tanto por www-data (FPM)
                // como por dicomsuites (cron), evitando errores de permisos en /uploads/ftp_mp3.
                $tempDir = rtrim(sys_get_temp_dir(), '/') . '/ftp_mp3/';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }
                $tempMp3Path = $tempDir . 'queue_' . $audioId . '_' . uniqid('', true) . '.mp3';
                if (file_put_contents($tempMp3Path, $response) === false) {
                    throw new Exception('No se pudo guardar MP3 temporal en ' . $tempDir);
                }

                $duracionMp3 = ftpGetAudioDurationWithFfmpeg($tempMp3Path);
                if ($duracionMp3 && $duracionMp3 > 0) {
                    $upd = $db->prepare('
                        UPDATE audios_informe SET duracion_segundos = ?
                        WHERE id = ? AND (duracion_segundos IS NULL OR ABS(COALESCE(duracion_segundos, 0) - ?) > 0.5)
                    ');
                    $upd->execute([$duracionMp3, $audioId, $duracionMp3]);
                }

                $filePath = $tempMp3Path;
            }

            $remoteFileName = ftpBuildRemoteFileName($userData, $audioData, $db);

            $clientConfig = [
                'host' => $ftpConfig['ftp_host'],
                'port' => (int) $ftpConfig['ftp_port'],
                'username' => $ftpConfig['ftp_username'],
                'password' => $ftpConfig['ftp_password'],
                'remote_path' => $ftpConfig['ftp_remote_path'],
                'passive_mode' => $ftpConfig['ftp_passive_mode'] == 1,
                'timeout' => (int) $ftpConfig['ftp_timeout'],
            ];

            $maxAttempts = max(1, (int) $ftpConfig['ftp_retry_attempts']);
            $attempt = 0;
            $success = false;
            $errorMessage = null;

            while ($attempt < $maxAttempts && !$success) {
                $attempt++;
                try {
                    $ftpClient = new FtpClient($clientConfig);
                    $ftpClient->connect();
                    $ftpClient->uploadFile($filePath, $remoteFileName);
                    $ftpClient->disconnect();
                    $success = true;
                } catch (Exception $e) {
                    $errorMessage = $e->getMessage();
                    if ($attempt < $maxAttempts) {
                        sleep((int) pow(2, $attempt - 1));
                    }
                }
            }

            if ($success) {
                $ok = $db->prepare("
                    UPDATE audios_ftp_log
                    SET status = 'success', file_name = ?, sent_at = NOW(), error_message = NULL, updated_at = NOW()
                    WHERE id = ?
                ");
                $ok->execute([$remoteFileName, $logId]);

                ftpUpdateAudioStateAfterSuccess($db, $audioId, $audioData, $userData, $remoteFileName, $ftpConfig['ftp_host']);

                return [
                    'success' => true,
                    'remote_file' => $remoteFileName,
                    'attempts' => $attempt,
                ];
            }

            $fail = $db->prepare("UPDATE audios_ftp_log SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?");
            $fail->execute([$errorMessage, $logId]);

            return [
                'success' => false,
                'message' => $errorMessage,
                'attempts' => $attempt,
            ];
        } finally {
            if ($tempMp3Path && file_exists($tempMp3Path)) {
                @unlink($tempMp3Path);
            }
        }
    }

    /**
     * Envío síncrono inmediato (botón manual / API send-to-ftp).
     */
    public static function sendSynchronously(PDO $db, int $audioId, array $userData, bool $requireAutoSend = false): array
    {
        ftpEnsureLogTable($db);

        $audioStmt = $db->prepare("
            SELECT id, nombre_archivo, ruta_archivo, backup_path, estudio_id, informe_id, usuario_id, estado
            FROM audios_informe WHERE id = ?
        ");
        $audioStmt->execute([$audioId]);
        $audioData = $audioStmt->fetch(PDO::FETCH_ASSOC);

        if (!$audioData) {
            throw new Exception('Audio no encontrado');
        }
        if (($audioData['estado'] ?? '') === 'eliminado') {
            throw new Exception('No se puede enviar a FTP un audio eliminado');
        }
        if (empty($audioData['informe_id'])) {
            throw new Exception('El audio debe estar vinculado a un informe. Finalice el informe antes de enviar a FTP.');
        }
        $estado = $audioData['estado'] ?? null;
        if ($estado !== null && $estado !== '' && !in_array($estado, ftpAllowedAudioStates(), true)) {
            throw new Exception('El audio debe estar guardado con el informe antes de enviarse a FTP. Estado: ' . $estado);
        }

        $existing = ftpGetExistingSuccessfulSend($db, $audioId);
        if ($existing) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Audio ya fue enviado a FTP anteriormente',
                'remote_file' => $existing['file_name'],
                'sent_at' => $existing['sent_at'],
            ];
        }

        $ftpConfig = ftpGetConfigurationForUser($db, $userData['id']);
        if (!$ftpConfig) {
            throw new Exception('No hay configuración FTP activa para tu usuario.');
        }
        if ($requireAutoSend && empty($ftpConfig['ftp_auto_send'])) {
            throw new Exception('El envío automático FTP está deshabilitado para tu usuario');
        }

        if (ftpHasPendingQueueEntry($db, $audioId)) {
            return [
                'success' => true,
                'queued' => true,
                'message' => 'El audio ya está en cola FTP',
            ];
        }

        $insert = $db->prepare("
            INSERT INTO audios_ftp_log
            (audio_id, ftp_host, remote_path, file_name, status, attempts, sent_at)
            VALUES (?, ?, ?, ?, 'retrying', 0, NULL)
        ");
        $insert->execute([
            $audioId,
            $ftpConfig['ftp_host'],
            $ftpConfig['ftp_remote_path'],
            'sync_' . $audioId . '.mp3',
        ]);
        $logId = (int) $db->lastInsertId();

        $result = self::processLogEntry($db, [
            'id' => $logId,
            'audio_id' => $audioId,
            'file_name' => 'sync_' . $audioId . '.mp3',
            'attempts' => 0,
        ]);

        if (!empty($result['success'])) {
            return array_merge($result, [
                'message' => $result['message'] ?? 'Audio enviado exitosamente a FTP',
            ]);
        }

        throw new Exception($result['message'] ?? 'Error al enviar audio a FTP');
    }
}
