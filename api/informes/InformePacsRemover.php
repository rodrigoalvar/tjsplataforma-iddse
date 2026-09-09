<?php
/**
 * Elimina un informe de Orthanc PACS y limpia referencias en la tabla informes.
 * Usado por remove-from-pacs.php (botón amarillo) y por el módulo QA.
 */
declare(strict_types=1);

require_once __DIR__ . '/../OrthancPacsSender.php';

final class InformePacsRemover
{
    /**
     * @return array{success:bool,message:string,deleted_by:?string,warning?:string,data:array}
     */
    public static function removeInformeFromPacs(PDO $db, int $informeId): array
    {
        $stmt = $db->prepare(
            'SELECT id, pacs_instance_id, pacs_study_id, pacs_series_id
             FROM informes WHERE id = ?'
        );
        $stmt->execute([$informeId]);
        $informe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$informe) {
            return [
                'success' => false,
                'message' => 'Informe no encontrado',
                'deleted_by' => null,
                'data' => ['informe_id' => $informeId],
            ];
        }

        $pacsInstanceId = $informe['pacs_instance_id'] ?? null;
        $pacsStudyId = $informe['pacs_study_id'] ?? null;
        $pacsSeriesId = $informe['pacs_series_id'] ?? null;

        if (empty($pacsSeriesId) && empty($pacsInstanceId)) {
            return [
                'success' => false,
                'message' => 'Este informe no está en PACS. No hay series_id ni instance_id guardados.',
                'deleted_by' => null,
                'data' => ['informe_id' => $informeId],
            ];
        }

        error_log('[REMOVE_FROM_PACS] Eliminando informe #' . $informeId . ' de Orthanc PACS');
        error_log('[REMOVE_FROM_PACS] Series ID: ' . ($pacsSeriesId ?? 'NULL'));
        error_log('[REMOVE_FROM_PACS] Instance ID: ' . ($pacsInstanceId ?? 'NULL'));

        $pacsSender = new OrthancPacsSender();
        $deletedSuccessfully = false;
        $deletedBy = null;

        if (!empty($pacsSeriesId)) {
            $deleteResult = $pacsSender->deleteSeries($pacsSeriesId);
            if ($deleteResult['success']) {
                $deletedSuccessfully = true;
                $deletedBy = 'series';
            } else {
                error_log('[REMOVE_FROM_PACS] No se pudo eliminar serie: ' . ($deleteResult['error'] ?? 'Error desconocido'));
            }
        }

        if (!$deletedSuccessfully && !empty($pacsInstanceId)) {
            $deleteResult = $pacsSender->deleteInstance($pacsInstanceId);
            if ($deleteResult['success']) {
                $deletedSuccessfully = true;
                $deletedBy = 'instance';
            } else {
                error_log('[REMOVE_FROM_PACS] No se pudo eliminar instancia: ' . ($deleteResult['error'] ?? 'Error desconocido'));
            }
        }

        self::clearPacsFieldsInDb($db, $informeId);

        $data = [
            'informe_id' => $informeId,
            'series_id' => $pacsSeriesId,
            'instance_id' => $pacsInstanceId,
            'study_id' => $pacsStudyId,
        ];

        if ($deletedSuccessfully) {
            $message = $deletedBy === 'series'
                ? 'Serie eliminada exitosamente de PORTAL ESTUDIOS y campos limpiados'
                : 'Instancia eliminada exitosamente de PORTAL ESTUDIOS y campos limpiados';

            return [
                'success' => true,
                'message' => $message,
                'deleted_by' => $deletedBy,
                'data' => $data,
            ];
        }

        return [
            'success' => true,
            'message' => 'Campos PACS limpiados en BD. No se pudo eliminar de PORTAL ESTUDIOS (puede que ya no exista)',
            'deleted_by' => null,
            'warning' => 'El objeto puede que ya no exista en Orthanc',
            'data' => $data,
        ];
    }

    public static function clearPacsFieldsInDb(PDO $db, int $informeId): void
    {
        try {
            $columns = $db->query('SHOW COLUMNS FROM informes')->fetchAll(PDO::FETCH_COLUMN);
            $updateFields = [];

            if (in_array('pacs_instance_id', $columns, true)) {
                $updateFields[] = 'pacs_instance_id = NULL';
            }
            if (in_array('pacs_study_id', $columns, true)) {
                $updateFields[] = 'pacs_study_id = NULL';
            }
            if (in_array('pacs_series_id', $columns, true)) {
                $updateFields[] = 'pacs_series_id = NULL';
            }
            if (in_array('fecha_enviado_pacs', $columns, true)) {
                $updateFields[] = 'fecha_enviado_pacs = NULL';
            }

            if (empty($updateFields)) {
                return;
            }

            $updateQuery = 'UPDATE informes SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$informeId]);
        } catch (PDOException $e) {
            error_log('[REMOVE_FROM_PACS] Error limpiando campos PACS en BD: ' . $e->getMessage());
        }
    }

    public static function informeHasPacsInOrthanc(PDO $db, int $informeId): bool
    {
        $stmt = $db->prepare(
            'SELECT pacs_instance_id, pacs_series_id, pacs_study_id, fecha_enviado_pacs
             FROM informes WHERE id = ?'
        );
        $stmt->execute([$informeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        foreach (['pacs_instance_id', 'pacs_series_id', 'pacs_study_id'] as $col) {
            if (!empty($row[$col])) {
                return true;
            }
        }

        return !empty($row['fecha_enviado_pacs']);
    }
}
