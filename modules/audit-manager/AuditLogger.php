<?php
/**
 * Registro de eventos del módulo Audit Manager.
 * No debe romper el flujo si la tabla no existe o falla el INSERT.
 */

class AuditLogger {

    public static function clientIp(): ?string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return null;
    }

    public static function userAgent(): ?string {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }
        $ua = (string) $_SERVER['HTTP_USER_AGENT'];
        return strlen($ua) > 512 ? substr($ua, 0, 512) : $ua;
    }

    public static function tableExists(PDO $db): bool {
        try {
            $st = $db->query("SHOW TABLES LIKE 'audit_manager_events'");
            return $st && $st->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param array $row user_id, action_key, resource_type?, resource_id?, description?, metadata? (array), ip_address?, user_agent?, client_duration_ms?, server_processing_ms?, rtt_ms?
     */
    public static function log(PDO $db, array $row): bool {
        if (!self::tableExists($db)) {
            return false;
        }
        try {
            $meta = null;
            if (!empty($row['metadata']) && is_array($row['metadata'])) {
                $meta = json_encode($row['metadata'], JSON_UNESCAPED_UNICODE);
            }
            $sql = "INSERT INTO audit_manager_events
                (user_id, action_key, resource_type, resource_id, description, metadata, ip_address, user_agent, client_duration_ms, server_processing_ms, rtt_ms)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                (int) $row['user_id'],
                (string) $row['action_key'],
                $row['resource_type'] ?? null,
                $row['resource_id'] ?? null,
                $row['description'] ?? null,
                $meta,
                $row['ip_address'] ?? self::clientIp(),
                $row['user_agent'] ?? self::userAgent(),
                isset($row['client_duration_ms']) ? (int) $row['client_duration_ms'] : null,
                isset($row['server_processing_ms']) ? (int) $row['server_processing_ms'] : null,
                isset($row['rtt_ms']) ? (int) $row['rtt_ms'] : null,
            ]);
            return true;
        } catch (Exception $e) {
            error_log('[AuditLogger] ' . $e->getMessage());
            return false;
        }
    }
}
