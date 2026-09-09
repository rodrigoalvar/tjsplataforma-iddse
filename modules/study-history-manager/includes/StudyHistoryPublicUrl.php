<?php
/**
 * URL pública del portal (enlaces absolutos a manifest.php / wado-instance.php).
 * Prioridad: configuracion.url_study_history_api (si no vacía), luego url_base, luego detección por SCRIPT_NAME.
 */

class StudyHistoryPublicUrl {
    /**
     * Base pública sin barra final, p. ej. https://host/tjsiddse
     */
    public static function getPortalPublicBase(PDO $db) {
        foreach (['url_study_history_api', 'url_base'] as $clave) {
            try {
                $st = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
                $st->execute([$clave]);
                $v = $st->fetchColumn();
                if (is_string($v)) {
                    $v = rtrim(trim($v), '/');
                    if ($v !== '') {
                        return $v;
                    }
                }
            } catch (Exception $e) {
                error_log('[STUDY_HISTORY] ' . $clave . ': ' . $e->getMessage());
            }
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $proto = $https ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $pathPrefix = '';
        if ($script !== '' && preg_match('#^(.*)/modules/study-history-manager/api/#', $script, $m)) {
            $pathPrefix = $m[1];
        }
        return $proto . '://' . $host . $pathPrefix;
    }

    public static function appendQueryParam($url, $name, $value) {
        $url = (string) $url;
        $sep = strpos($url, '?') !== false ? '&' : '?';
        return $url . $sep . rawurlencode($name) . '=' . rawurlencode((string) $value);
    }
}
