<?php
/**
 * Resolución de ID de estudio Orthanc por StudyInstanceUID (sin modificar OrthancClient).
 */

if (!class_exists('OrthancConfig')) {
    require_once __DIR__ . '/../../../api/config/orthanc_config.php';
}

class OrthancUidLookup {
    /**
     * @return string[] IDs de estudio en Orthanc (0 o más)
     */
    public static function findStudyIdsByInstanceUid($studyInstanceUid) {
        $studyInstanceUid = trim((string) $studyInstanceUid);
        if ($studyInstanceUid === '') {
            return [];
        }

        $baseUrl = OrthancConfig::getServerUrl();
        $creds = OrthancConfig::getCredentials();
        $cfg = OrthancConfig::getConfig();
        $timeout = (int) ($cfg['api']['timeout'] ?? 60);
        $connectTimeout = (int) ($cfg['api']['connect_timeout'] ?? 10);
        $verifySsl = !empty($cfg['api']['verify_ssl']);

        $url = $baseUrl . '/tools/find';
        $body = json_encode([
            'Level' => 'Study',
            'Query' => [
                'StudyInstanceUID' => $studyInstanceUid
            ]
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $creds['username'] . ':' . $creds['password']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return [];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $ids[] = $item;
            }
        }
        return $ids;
    }
}
