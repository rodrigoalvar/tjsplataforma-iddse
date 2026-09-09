<?php
/**
 * Construcción de URLs de visor para estudios en nodos remotos (WADO-URI / DICOMweb proxy).
 * Las plantillas son configurables por nodo; valores por defecto razonables para iterar.
 */

if (!class_exists('OrthancConfig')) {
    require_once __DIR__ . '/../../../api/config/orthanc_config.php';
}

class RemoteViewerUrlBuilder {
    /**
     * @param array $node Fila pacs_nodes (con columnas opcionales wado_uri_base, dicomweb_proxy_base)
     * @param string $viewerType UDV|StoneViewer|Oviyam
     * @param string $studyInstanceUID
     * @return array{url:?string,hint:string,strategy:string}
     */
    public static function build(array $node, $viewerType, $studyInstanceUID) {
        $viewerType = $viewerType ?: 'UDV';
        $uid = trim((string) $studyInstanceUID);
        if ($uid === '') {
            return ['url' => null, 'hint' => 'StudyInstanceUID vacío', 'strategy' => 'none'];
        }

        $wadoBase = isset($node['wado_uri_base']) ? trim((string) $node['wado_uri_base']) : '';
        $proxyBase = isset($node['dicomweb_proxy_base']) ? trim((string) $node['dicomweb_proxy_base']) : '';

        $config = OrthancConfig::getConfig();
        $viewers = $config['viewer']['viewers'] ?? [];
        $gatewayDefault = isset($config['viewer']['dicomweb_proxy_gateway_default'])
            ? trim((string) $config['viewer']['dicomweb_proxy_gateway_default'])
            : '';

        if ($viewerType === 'StoneViewer') {
            if ($proxyBase === '' && $gatewayDefault !== '') {
                $proxyBase = $gatewayDefault;
            }
            if ($proxyBase === '') {
                return [
                    'url' => null,
                    'hint' => 'Configure dicomweb_proxy_base en el nodo PACS o la URL por defecto del gateway (Configuración PACS → Gateway DICOMweb) para Stone remoto (ej. knopkem dicomweb-proxy …/rs).',
                    'strategy' => 'remote_stone_proxy'
                ];
            }
            $stone = $viewers['StoneViewer'] ?? [];
            $url = trim((string)($stone['remote_url'] ?? ''));
            if ($url === '') {
                $url = $stone['url'] ?? '';
            }
            $param = $stone['study_id_param'] ?? 'study';
            if ($url === '') {
                return ['url' => null, 'hint' => 'Falta URL de StoneViewer en configuración PACS global.', 'strategy' => 'remote_stone_proxy'];
            }
            $sep = (strpos($url, '?') !== false) ? '&' : '?';
            $proxyQueryKey = isset($stone['remote_proxy_query_key']) ? (string) $stone['remote_proxy_query_key'] : 'server';
            if ($proxyQueryKey !== 'dicomWebRoot') {
                $proxyQueryKey = 'server';
            }
            $open = $url . $sep . $param . '=' . rawurlencode($uid)
                . '&' . $proxyQueryKey . '=' . rawurlencode(rtrim($proxyBase, '/'));
            return ['url' => $open, 'hint' => '', 'strategy' => 'remote_stone_proxy'];
        }

        if ($viewerType === 'Oviyam') {
            if ($wadoBase === '') {
                return [
                    'url' => null,
                    'hint' => 'Configure wado_uri_base en el nodo para Oviyam/WADO remoto, o use otro visor.',
                    'strategy' => 'remote_oviyam'
                ];
            }
            $ov = $viewers['Oviyam'] ?? [];
            $url = $ov['url'] ?? '';
            if ($url === '') {
                return ['url' => null, 'hint' => 'Falta URL de Oviyam en configuración PACS global.', 'strategy' => 'remote_oviyam'];
            }
            $sep = (strpos($url, '?') !== false) ? '&' : '?';
            $open = $url . $sep . 'studyUID=' . rawurlencode($uid) . '&wadoUrl=' . rawurlencode(rtrim($wadoBase, '/'));
            return ['url' => $open, 'hint' => 'Parámetros Oviyam pueden requerir ajuste según instalación.', 'strategy' => 'remote_oviyam'];
        }

        // UDV + WADO-URI típico DCM4CHEE
        if ($wadoBase === '') {
            return [
                'url' => null,
                'hint' => 'Configure wado_uri_base en el nodo PACS (base WADO-URI del servidor legacy).',
                'strategy' => 'remote_wado_uri'
            ];
        }
        // Enlace directo WADO-URI a nivel estudio (DCM4CHEE / gateways); el visor UDV puede abrirse aparte apuntando al mismo PACS.
        $base = rtrim($wadoBase, '?&');
        $sep = (strpos($base, '?') !== false) ? '&' : '?';
        $wadoStudy = $base . $sep . 'requestType=WADO&studyUID=' . rawurlencode($uid);
        return [
            'url' => $wadoStudy,
            'hint' => 'URL WADO-URI directa; ajuste wado_uri_base del nodo si su PACS usa otros parámetros.',
            'strategy' => 'remote_wado_uri'
        ];
    }
}
