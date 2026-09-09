<?php
/**
 * Gestor de Configuración de Nodos PACS
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Maneja la sincronización de nodos con Orthanc DicomModalities
 * 
 * @package PacsNodesManager
 * @version 1.0.0
 */

require_once __DIR__ . '/../../api/config/orthanc_config.php';

class PacsNodeConfig {
    private $db;
    private $orthancBaseUrl;
    private $orthancCredentials;
    
    public function __construct($db) {
        $this->db = $db;
        $this->orthancBaseUrl = OrthancConfig::getServerUrl();
        $this->orthancCredentials = OrthancConfig::getCredentials();
    }
    
    /**
     * Lista todas las modalidades configuradas en Orthanc
     * 
     * @param bool $expand Si true, incluye información expandida
     * @return array Lista de modalidades
     */
    public function listOrthancModalities($expand = true) {
        $url = $this->orthancBaseUrl . '/modalities' . ($expand ? '?expand' : '');
        return $this->makeOrthancRequest('GET', $url);
    }
    
    /**
     * Sincroniza un nodo DIMSE con Orthanc DicomModalities usando REST API
     * Con DicomModalitiesInDatabase: true, Orthanc guarda en BD interna
     * 
     * @param array $node Datos del nodo
     * @return array Resultado de la sincronización
     */
    public function syncNodeWithOrthanc($node) {
        // Solo sincronizar nodos DIMSE o hybrid
        if ($node['node_type'] !== 'dimse' && $node['node_type'] !== 'hybrid') {
            return [
                'success' => true,
                'message' => 'Nodo no requiere sincronización con Orthanc (tipo: ' . $node['node_type'] . ')',
                'synced' => false
            ];
        }
        
        if (empty($node['aet']) || empty($node['host']) || empty($node['port'])) {
            throw new Exception('Nodo DIMSE requiere AET, host y port');
        }
        
        // Usar el ID del nodo o el AET como nombre interno en Orthanc
        $nodeId = !empty($node['id']) ? 'NODE_' . $node['id'] : $node['aet'];
        $url = $this->orthancBaseUrl . '/modalities/' . $nodeId;
        
        // Construir payload completo con todos los flags
        $data = [
            'AET' => $node['aet'],
            'Host' => $node['host'],
            'Port' => (int)$node['port']
        ];
        
        // Flags de operaciones permitidas
        $data['AllowFind'] = isset($node['allow_find']) ? (bool)$node['allow_find'] : true;
        $data['AllowMove'] = isset($node['allow_move']) ? (bool)$node['allow_move'] : true;
        $data['AllowGet'] = isset($node['allow_get']) ? (bool)$node['allow_get'] : true;
        $data['AllowStore'] = isset($node['allow_store']) ? (bool)$node['allow_store'] : false;
        $data['AllowTranscoding'] = isset($node['allow_transcoding']) ? (bool)$node['allow_transcoding'] : false;
        
        // Configuración adicional
        if (!empty($node['manufacturer'])) {
            $data['Manufacturer'] = $node['manufacturer'];
        } else {
            $data['Manufacturer'] = 'Generic';
        }
        
        if (isset($node['timeout']) && $node['timeout'] > 0) {
            $data['Timeout'] = (int)$node['timeout'];
        } else {
            $data['Timeout'] = 30; // Default
        }
        
        if (isset($node['use_dicom_tls'])) {
            $data['UseDicomTls'] = (bool)$node['use_dicom_tls'];
        } else {
            $data['UseDicomTls'] = false;
        }
        
        // LocalAet: AET alternativo que Orthanc usará como Calling AET para este peer
        // Permite diferenciar operaciones del PACS Nodes Manager en los logs del PACS remoto
        if (!empty($node['local_aet'])) {
            $data['LocalAet'] = $node['local_aet'];
            error_log("[PacsNodeConfig] Usando LocalAet: {$node['local_aet']} para diferenciar operaciones del PACS Nodes Manager");
        }
        
        // Autenticación DICOM (si está configurada)
        if (!empty($node['username'])) {
            $data['Username'] = $node['username'];
        }
        
        if (!empty($node['password'])) {
            // La contraseña debe estar desencriptada para Orthanc
            // En producción, necesitaríamos un método para desencriptar temporalmente
            $data['Password'] = $node['password'];
        }
        
        // Log del request antes de enviarlo
        error_log("[PacsNodeConfig] Sincronizando nodo con Orthanc: PUT $url");
        error_log("[PacsNodeConfig] Datos a enviar: " . json_encode($data, JSON_PRETTY_PRINT));
        
        try {
            $response = $this->makeOrthancRequest('PUT', $url, $data);
            error_log("[PacsNodeConfig] Respuesta de Orthanc: " . json_encode($response, JSON_PRETTY_PRINT));
            
            return [
                'success' => true,
                'message' => 'Nodo sincronizado con Orthanc',
                'synced' => true,
                'orthanc_id' => $nodeId,
                'response' => $response
            ];
        } catch (Exception $e) {
            error_log("[PacsNodeConfig] Error sincronizando nodo: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Prueba la conectividad con un nodo usando C-ECHO
     * 
     * @param string $nodeId ID del nodo en Orthanc (o AET)
     * @return array Resultado del test
     */
    public function testNodeConnection($nodeId) {
        $url = $this->orthancBaseUrl . '/modalities/' . $nodeId . '/echo';
        
        $startTime = microtime(true);
        
        try {
            $response = $this->makeOrthancRequest('POST', $url, []);
            $latency = round((microtime(true) - $startTime) * 1000); // ms
            
            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'latency_ms' => $latency,
                'response' => $response
            ];
        } catch (Exception $e) {
            $latency = round((microtime(true) - $startTime) * 1000);
            
            return [
                'success' => false,
                'message' => 'Error de conexión: ' . $e->getMessage(),
                'latency_ms' => $latency,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verifica si un nodo está configurado en Orthanc
     * 
     * @param string $aet AET del nodo
     * @return bool True si está configurado
     */
    public function isNodeInOrthanc($aet) {
        try {
            $url = $this->orthancBaseUrl . '/modalities/' . $aet;
            $this->makeOrthancRequest('GET', $url);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Elimina un nodo de Orthanc
     * 
     * @param string $aet AET del nodo
     * @return array Resultado de la eliminación
     */
    public function removeNodeFromOrthanc($aet) {
        try {
            $url = $this->orthancBaseUrl . '/modalities/' . $aet;
            $this->makeOrthancRequest('DELETE', $url);
            return [
                'success' => true,
                'message' => 'Nodo eliminado de Orthanc'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al eliminar nodo de Orthanc: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Valida la configuración de un nodo antes de guardar
     * 
     * @param array $nodeData Datos del nodo
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateNodeConfig($nodeData) {
        $errors = [];
        
        // Validar tipo de nodo
        $validTypes = ['dimse', 'dicomweb', 'local', 'hybrid'];
        if (!in_array($nodeData['node_type'], $validTypes)) {
            $errors[] = 'Tipo de nodo inválido. Debe ser: ' . implode(', ', $validTypes);
        }
        
        // Validar según tipo
        if ($nodeData['node_type'] === 'dimse' || $nodeData['node_type'] === 'hybrid') {
            if (empty($nodeData['aet'])) {
                $errors[] = 'AET es requerido para nodos DIMSE';
            } elseif (strlen($nodeData['aet']) > 16) {
                $errors[] = 'AET no puede tener más de 16 caracteres';
            }
            
            if (empty($nodeData['host'])) {
                $errors[] = 'Host es requerido para nodos DIMSE';
            }
            
            if (empty($nodeData['port'])) {
                $errors[] = 'Puerto es requerido para nodos DIMSE';
            } elseif (!is_numeric($nodeData['port']) || $nodeData['port'] < 1 || $nodeData['port'] > 65535) {
                $errors[] = 'Puerto debe ser un número entre 1 y 65535';
            }
        }
        
        if ($nodeData['node_type'] === 'dicomweb' || $nodeData['node_type'] === 'hybrid') {
            if (empty($nodeData['dicomweb_url'])) {
                $errors[] = 'URL DICOMweb es requerida para nodos DICOMweb';
            } elseif (!filter_var($nodeData['dicomweb_url'], FILTER_VALIDATE_URL)) {
                $errors[] = 'URL DICOMweb no es válida';
            }
        }
        
        if ($nodeData['node_type'] === 'local') {
            // Nodos locales no requieren configuración adicional
        }
        
        if ($nodeData['node_type'] === 'hybrid' && isset($nodeData['find_query_mode']) && $nodeData['find_query_mode'] !== null && $nodeData['find_query_mode'] !== '') {
            if (!in_array($nodeData['find_query_mode'], ['dicomweb', 'dimse'], true)) {
                $errors[] = 'Modo de búsqueda híbrido inválido (use dicomweb, dimse o vacío para automático)';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Realiza una petición HTTP a Orthanc
     * 
     * @param string $method Método HTTP
     * @param string $url URL completa
     * @param array|null $data Datos a enviar
     * @return array Respuesta de Orthanc
     * @throws Exception Si hay error
     */
    private function makeOrthancRequest($method, $url, $data = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Aumentado de 10 a 30 segundos
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Aumentado de 5 a 10 segundos
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Error cURL con Orthanc: " . $error . " (URL: $url)");
            throw new Exception('Error de conexión con Orthanc: ' . $error);
        }
        
        if ($httpCode >= 400) {
            $errorMsg = json_decode($response, true);
            $errorMessage = is_array($errorMsg) && isset($errorMsg['Message']) 
                ? $errorMsg['Message'] 
                : (is_string($response) ? substr($response, 0, 200) : 'Error desconocido');
            error_log("Error HTTP $httpCode de Orthanc: $errorMessage (URL: $url)");
            throw new Exception('Error HTTP ' . $httpCode . ': ' . $errorMessage);
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE && !empty($response)) {
            error_log("Error decodificando JSON de Orthanc: " . json_last_error_msg() . " (Response: " . substr($response, 0, 200) . ")");
        }
        
        return $decoded ?? [];
    }
}
