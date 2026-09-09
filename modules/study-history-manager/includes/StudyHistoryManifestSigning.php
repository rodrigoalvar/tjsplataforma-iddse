<?php
/**
 * Firma HMAC para GET público de manifest (el visor hace fetch sin cookies).
 */

class StudyHistoryManifestSigning {
    const CONFIG_KEY = 'study_history_manifest_hmac_secret';

    public static function getOrCreateSecret(PDO $db) {
        $st = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
        $st->execute([self::CONFIG_KEY]);
        $existing = $st->fetchColumn();
        if (is_string($existing) && strlen($existing) >= 32) {
            return $existing;
        }
        $secret = bin2hex(random_bytes(32));
        $up = $db->prepare('INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        $up->execute([self::CONFIG_KEY, $secret]);
        return $secret;
    }

    public static function sign($studyUid, $nodeId, $expUnix, $secret) {
        $payload = (string) $studyUid . '|' . (string) (int) $nodeId . '|' . (string) (int) $expUnix;
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify($studyUid, $nodeId, $expUnix, $sig, $secret) {
        if (!is_string($sig) || strlen($sig) !== 64 || !ctype_xdigit($sig)) {
            return false;
        }
        $expect = self::sign($studyUid, $nodeId, $expUnix, $secret);
        return hash_equals(strtolower($expect), strtolower($sig));
    }

    /**
     * Firma por instancia WADO (proxy HTTPS → PACS interno HTTP).
     * Payload con prefijo para no colisionar con sign() del manifest.
     */
    public static function signWadoInstance($nodeId, $studyUid, $seriesUid, $sopUid, $expUnix, $secret) {
        $payload = 'wadoi|'
            . (string) (int) $nodeId . '|'
            . (string) $studyUid . '|'
            . (string) $seriesUid . '|'
            . (string) $sopUid . '|'
            . (string) (int) $expUnix;
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verifyWadoInstance($nodeId, $studyUid, $seriesUid, $sopUid, $expUnix, $sig, $secret) {
        if (!is_string($sig) || strlen($sig) !== 64 || !ctype_xdigit($sig)) {
            return false;
        }
        $expect = self::signWadoInstance($nodeId, $studyUid, $seriesUid, $sopUid, $expUnix, $secret);
        return hash_equals(strtolower($expect), strtolower($sig));
    }
}
