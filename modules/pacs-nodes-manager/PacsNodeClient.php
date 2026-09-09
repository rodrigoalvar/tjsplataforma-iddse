<?php
/**
 * Cliente para operaciones DICOM con nodos PACS
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Maneja operaciones C-FIND, C-MOVE, C-GET y proxy DICOMweb
 * 
 * @package PacsNodesManager
 * @version 1.0.0
 */

require_once __DIR__ . '/../../api/config/orthanc_config.php';

class PacsNodeClient {
    private $db;
    public $orthancBaseUrl; // Público para verificación en retrieve.php
    private $orthancCredentials;
    
    public function __construct($db) {
        $this->db = $db;
        $this->orthancBaseUrl = OrthancConfig::getServerUrl();
        $this->orthancCredentials = OrthancConfig::getCredentials();
    }

    /**
     * Activa logs detallados solo cuando se solicita explícitamente por entorno.
     */
    private function isDebugEnabled() {
        $raw = strtolower(trim((string)getenv('PACS_NODES_DEBUG')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
    
    /**
     * Resuelve si las búsquedas deben usar QIDO-RS, C-FIND DIMSE o Orthanc local.
     * Depende de node_type y, en híbridos, de find_query_mode (columna opcional).
     * No infiere DICOMweb solo por tener dicomweb_url: un nodo DIMSE puede tener URL sin usarla para C-FIND.
     *
     * @return string 'local'|'qido'|'dimse'
     */
    public function resolveFindQueryBackend(array $node) {
        $type = $node['node_type'] ?? '';
        if ($type === 'local') {
            return 'local';
        }
        if ($type === 'dicomweb') {
            if (empty(trim((string)($node['dicomweb_url'] ?? '')))) {
                throw new Exception('Nodo DICOMweb sin URL base');
            }
            return 'qido';
        }
        if ($type === 'dimse') {
            if (empty($node['aet']) || empty($node['host'])) {
                throw new Exception('Nodo DIMSE sin AET u host');
            }
            return 'dimse';
        }
        if ($type === 'hybrid') {
            $mode = isset($node['find_query_mode']) ? $node['find_query_mode'] : null;
            if ($mode === 'dicomweb') {
                if (empty(trim((string)($node['dicomweb_url'] ?? '')))) {
                    throw new Exception('Modo búsqueda DICOMweb requiere URL');
                }
                return 'qido';
            }
            if ($mode === 'dimse') {
                if (empty($node['aet']) || empty($node['host'])) {
                    throw new Exception('Modo búsqueda DIMSE requiere AET y host');
                }
                return 'dimse';
            }
            if (!empty(trim((string)($node['dicomweb_url'] ?? '')))) {
                return 'qido';
            }
            if (!empty($node['aet']) && !empty($node['host'])) {
                return 'dimse';
            }
            throw new Exception('Nodo híbrido: configure URL DICOMweb o AET/host, o elija modo de búsqueda');
        }
        throw new Exception('Tipo de nodo no soportado para búsqueda');
    }
    
    /**
     * Ejecuta C-ECHO (test de conectividad) con un nodo
     * 
     * @param array $node Datos del nodo
     * @return array Resultado del test
     */
    public function testConnection($node) {
        $startTime = microtime(true);
        
        try {
            if (($node['node_type'] ?? '') === 'local') {
                return $this->testLocalConnection($startTime);
            }
            $backend = $this->resolveFindQueryBackend($node);
            if ($backend === 'qido') {
                return $this->testDicomwebConnection($node);
            }
            if ($backend === 'dimse') {
                return $this->testDimseConnection($node, $startTime);
            }
            throw new Exception('Tipo de nodo no soportado para test de conectividad');
        } catch (Exception $e) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            return [
                'success' => false,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Test de conectividad DIMSE usando C-ECHO
     */
    private function testDimseConnection($node, $startTime) {
        $modalityId = $this->getOrthancModalityId($node);
        $url = $this->orthancBaseUrl . '/modalities/' . $modalityId . '/echo';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        if ($error) {
            return [
                'success' => false,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'failed',
                'error' => 'Error de conexión: ' . $error
            ];
        }
        
        if ($httpCode === 200) {
            return [
                'success' => true,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'success'
            ];
        } else {
            return [
                'success' => false,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'failed',
                'error' => 'HTTP ' . $httpCode
            ];
        }
    }
    
    /**
     * Test de conectividad DICOMweb
     */
    private function testDicomwebConnection($node) {
        $startTime = microtime(true);
        $baseUrl = rtrim($node['dicomweb_url'], '/');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        // Agregar autenticación si es necesario
        if ($node['dicomweb_auth_type'] === 'basic' && 
            !empty($node['dicomweb_username']) && 
            !empty($node['dicomweb_password'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $node['dicomweb_username'] . ':' . $node['dicomweb_password']);
        }
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        if ($error) {
            return [
                'success' => false,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'failed',
                'error' => 'Error de conexión: ' . $error
            ];
        }
        
        if ($httpCode >= 200 && $httpCode < 400) {
            return [
                'success' => true,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'success'
            ];
        } else {
            return [
                'success' => false,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'failed',
                'error' => 'HTTP ' . $httpCode
            ];
        }
    }
    
    /**
     * Test de conectividad local (Orthanc)
     */
    private function testLocalConnection($startTime) {
        $url = $this->orthancBaseUrl . '/system';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        return [
            'success' => $httpCode === 200,
            'response_time_ms' => round($responseTime, 2),
            'status' => $httpCode === 200 ? 'success' : 'failed'
        ];
    }
    
    /**
     * Ejecuta C-FIND (búsqueda) en un nodo
     * 
     * @param array $node Datos del nodo
     * @param array $query Parámetros de búsqueda DICOM
     * @return array Resultados de la búsqueda
     */
    public function executeCFind($node, $query) {
        $backend = $this->resolveFindQueryBackend($node);
        if ($backend === 'qido') {
            return $this->executeQidoRs($node, $query);
        }
        if ($backend === 'dimse') {
            return $this->executeDimseCFind($node, $query);
        }
        if ($backend === 'local') {
            return $this->executeLocalFind($query);
        }
        throw new Exception('Tipo de nodo no soportado para búsqueda');
    }
    
    /**
     * Obtiene el ID del nodo en Orthanc (orthanc_node_id tiene prioridad sobre aet)
     * 
     * IMPORTANTE: Este ID debe coincidir exactamente con el ID usado en /modalities/{id} de Orthanc
     * Si el nodo fue creado con orthanc_node_id="NODE_1", entonces debe usarse "NODE_1"
     * Si no tiene orthanc_node_id, se usa el AET del nodo
     */
    private function getOrthancModalityId($node) {
        // Usar orthanc_node_id si existe (formato NODE_{id} o cualquier ID personalizado)
        if (!empty($node['orthanc_node_id'])) {
            $modalityId = $node['orthanc_node_id'];
            if ($this->isDebugEnabled()) {
                error_log("[PACS_NODES] Usando orthanc_node_id como modalityId: $modalityId");
            }
            return $modalityId;
        }
        // Fallback: intentar con el AET directamente
        $modalityId = $node['aet'] ?? null;
        if (empty($modalityId)) {
            throw new Exception('No se puede determinar el modalityId: falta orthanc_node_id y aet');
        }
        if ($this->isDebugEnabled()) {
            error_log("[PACS_NODES] Usando AET como modalityId: $modalityId");
        }
        return $modalityId;
    }

    /**
     * C-FIND DIMSE y devuelve cada respuesta /content?simplify como array PHP (sin parse al formato estudio).
     * Útil para niveles Series / Instance (Study History Manager, manifests).
     * Flujo Orthanc: POST /modalities/{id}/query → GET /queries/{id}/answers → GET .../content?simplify
     *
     * @param array $node
     * @param string $level Study|Series|Instance
     * @param array $queryFilters claves DICOM con valores de filtro (no vacíos) o retorno (vacíos)
     * @param array $returnTagNames lista de tags a solicitar como vacíos (retorno)
     * @return array<int,array> contenidos simplificados crudos
     */
    public function executeDimseCFindRaw($node, $level, array $queryFilters, array $returnTagNames) {
        $returnFields = [];
        foreach ($returnTagNames as $t) {
            $returnFields[$t] = '';
        }
        $finalQuery = array_merge($returnFields, $queryFilters);
        $dimseQuery = [
            'Level' => $level,
            'Query' => $finalQuery
        ];
        return $this->dimseModalityFetchRawContents($node, $dimseQuery);
    }

    /**
     * POST /modalities/{id}/query y recolecta respuestas simplify sin parsear a filas de estudio.
     *
     * @param array $dimseQuery [ 'Level' => ..., 'Query' => [...] ]
     * @return array<int,array>
     */
    private function dimseModalityFetchRawContents($node, array $dimseQuery) {
        $modalityId = $this->getOrthancModalityId($node);
        $url = $this->orthancBaseUrl . '/modalities/' . $modalityId . '/query';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dimseQuery));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('Error de conexión: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception('Error HTTP ' . $httpCode . ': ' . $response);
        }

        $queryResponse = json_decode($response, true);
        if (!isset($queryResponse['ID'])) {
            throw new Exception('Error en C-FIND: no se obtuvo ID de query');
        }

        $queryId = $queryResponse['ID'];
        $answersUrl = $this->orthancBaseUrl . '/queries/' . $queryId . '/answers';
        $answerIndices = $this->makeOrthancRequest('GET', $answersUrl);

        $results = [];
        foreach ($answerIndices as $idx) {
            $contentUrl = $this->orthancBaseUrl . '/queries/' . $queryId . '/answers/' . $idx . '/content?simplify';
            try {
                $content = $this->makeOrthancRequest('GET', $contentUrl);
                if (is_array($content)) {
                    $results[] = $content;
                }
            } catch (Exception $e) {
                error_log("[PACS_NODES] Error obteniendo answer $idx: " . $e->getMessage());
            }
        }

        try {
            $this->makeOrthancRequest('DELETE', $this->orthancBaseUrl . '/queries/' . $queryId);
        } catch (Exception $e) {
            error_log("Warning: No se pudo limpiar query temporal: " . $e->getMessage());
        }

        return $results;
    }

    private function executeDimseCFind($node, $query) {
        $userQuery = $query['Query'] ?? [];

        $returnFields = [
            'PatientName'          => '',
            'PatientID'            => '',
            'PatientBirthDate'     => '',
            'StudyInstanceUID'     => '',
            'StudyDate'            => '',
            'StudyTime'            => '',
            'StudyDescription'     => '',
            'AccessionNumber'      => '',
            'ModalitiesInStudy'    => '',
            'NumberOfStudyRelatedSeries'    => '',
            'NumberOfStudyRelatedInstances' => '',
            'Manufacturer'         => '',
            'ManufacturerModelName' => '',
            'SoftwareVersion'      => '',
            'StationName'          => '',
            'InstitutionName'      => '',
            'InstitutionAddress'  => '',
        ];

        $finalQuery = array_merge($returnFields, $userQuery);
        $dimseQuery = [
            'Level' => $query['Level'] ?? 'Study',
            'Query' => $finalQuery
        ];

        $rawList = $this->dimseModalityFetchRawContents($node, $dimseQuery);
        $results = [];
        foreach ($rawList as $content) {
            $results[] = $this->parseSimplifiedDicomAnswer($content);
        }
        return $results;
    }
    
    /**
     * Ejecuta QIDO-RS para nodos DICOMweb
     */
    private function executeQidoRs($node, $query) {
        $baseUrl = rtrim($node['dicomweb_url'], '/');
        $url = $baseUrl . '/studies';
        
        // Convertir query DICOM a parámetros QIDO-RS
        $params = [];
        if (isset($query['Query']['PatientID'])) {
            $params['PatientID'] = $query['Query']['PatientID'];
        }
        if (isset($query['Query']['PatientName'])) {
            $params['PatientName'] = $query['Query']['PatientName'];
        }
        if (isset($query['Query']['StudyDate'])) {
            $params['StudyDate'] = $query['Query']['StudyDate'];
        }
        if (isset($query['Query']['AccessionNumber'])) {
            $params['AccessionNumber'] = $query['Query']['AccessionNumber'];
        }
        if (isset($query['Query']['ModalitiesInStudy'])) {
            $params['ModalitiesInStudy'] = $query['Query']['ModalitiesInStudy'];
        }
        // Búsqueda puntual por UID (p. ej. medición Cross Sync / seguimiento de réplica)
        if (!empty($query['Query']['StudyInstanceUID'])) {
            $params['StudyInstanceUID'] = $query['Query']['StudyInstanceUID'];
        }
        if (isset($query['limit'])) {
            $params['limit'] = $query['limit'];
        }
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/dicom+json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Autenticación
        if ($node['dicomweb_auth_type'] === 'basic' && 
            !empty($node['dicomweb_username']) && 
            !empty($node['dicomweb_password'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $node['dicomweb_username'] . ':' . $node['dicomweb_password']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Error de conexión: ' . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('Error HTTP ' . $httpCode . ': ' . $response);
        }
        
        $results = json_decode($response, true);
        
        // Convertir formato DICOMweb a formato estándar
        $formattedResults = [];
        foreach ($results as $result) {
            $formattedResults[] = $this->convertDicomwebToStandard($result);
        }
        
        return $formattedResults;
    }
    
    /**
     * Ejecuta búsqueda local en Orthanc
     */
    private function executeLocalFind($query) {
        $url = $this->orthancBaseUrl . '/tools/find';
        
        $dimseQuery = [
            'Level' => $query['Level'] ?? 'Study',
            'Query' => $query['Query'] ?? [],
            'Expand' => true
        ];
        
        $results = $this->makeOrthancRequest('POST', $url, $dimseQuery);
        
        // Log de diagnóstico opcional
        if ($this->isDebugEnabled() && !empty($results) && is_array($results[0])) {
            $firstStudy = $results[0];
            $debugInfo = [
                'has_MainDicomTags' => isset($firstStudy['MainDicomTags']),
                'has_PatientMainDicomTags' => isset($firstStudy['PatientMainDicomTags']),
                'has_ParentPatient' => isset($firstStudy['ParentPatient']),
                'MainDicomTags_keys' => isset($firstStudy['MainDicomTags']) ? array_keys($firstStudy['MainDicomTags']) : [],
                'ParentPatient' => $firstStudy['ParentPatient'] ?? 'NOT_SET'
            ];
            error_log("[PACS_NODES][LOCAL_FIND] Estructura del estudio: " . json_encode($debugInfo));
        }
        
        // Cache para pacientes (evitar consultas repetidas)
        $patientCache = [];
        
        // Procesar resultados
        $formattedResults = [];
        foreach ($results as $study) {
            try {
                // Fast path: con Expand=true, normalmente ya tenemos lo necesario sin GET adicional.
                if (is_array($study)) {
                    $studyDetails = $study;
                    if (!isset($studyDetails['_StudyId']) && isset($studyDetails['ID'])) {
                        $studyDetails['_StudyId'] = $studyDetails['ID'];
                    }
                } else {
                    $studyId = is_string($study) ? $study : null;
                    if (!$studyId) {
                        if ($this->isDebugEnabled()) {
                            error_log("[PACS_NODES][LOCAL_FIND] Error: No se pudo obtener ID del estudio");
                        }
                        continue;
                    }
                    $studyDetails = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/studies/' . $studyId, null, 10);
                    $studyDetails['_StudyId'] = $studyId;
                }
                
                // Cargar datos del paciente si no están en el estudio expandido
                if (!isset($studyDetails['PatientMainDicomTags'])) {
                    $patientId = $studyDetails['ParentPatient'] ?? null;
                    if ($patientId) {
                        // Usar cache para datos del paciente
                        if (!isset($patientCache[$patientId])) {
                            try {
                                $patientUrl = $this->orthancBaseUrl . '/patients/' . $patientId;
                                $patient = $this->makeOrthancRequest('GET', $patientUrl, null, 10);
                                $patientCache[$patientId] = $patient;
                            } catch (Exception $e) {
                                if ($this->isDebugEnabled()) {
                                    error_log("[PACS_NODES][LOCAL_FIND] Error obteniendo paciente: " . $e->getMessage());
                                }
                                $patientCache[$patientId] = null;
                            }
                        }
                        
                        if ($patientCache[$patientId]) {
                            $patient = $patientCache[$patientId];
                            // Agregar datos del paciente al estudio para mantener compatibilidad
                            $studyDetails['PatientMainDicomTags'] = $patient['MainDicomTags'] ?? [];
                        }
                    }
                }
                
                $formattedResults[] = $this->parseStudyDetails($studyDetails, $patientCache);
            } catch (Exception $e) {
                if ($this->isDebugEnabled()) {
                    error_log("[PACS_NODES][LOCAL_FIND] Error procesando estudio: " . $e->getMessage());
                }
                continue; // Continuar con el siguiente estudio si hay error
            }
        }
        
        // Log de diagnóstico opcional
        if ($this->isDebugEnabled() && !empty($formattedResults)) {
            $firstParsed = $formattedResults[0];
            $debugParsed = [
                'PatientName' => $firstParsed['PatientName'] ?? 'EMPTY',
                'PatientID' => $firstParsed['PatientID'] ?? 'EMPTY',
                'StudyInstanceUID' => $firstParsed['StudyInstanceUID'] ?? 'EMPTY'
            ];
            error_log("[PACS_NODES][LOCAL_FIND] Después de parsear: " . json_encode($debugParsed));
        }
        
        return $formattedResults;
    }
    
    /**
     * C-MOVE DIMSE a través de Orthanc con nivel y recursos DICOM explícitos.
     *
     * @param array  $node
     * @param string $level Study|Series|Instance
     * @param array  $resources lista de objetos con tags DICOM (p. ej. StudyInstanceUID, SeriesInstanceUID…)
     * @param string|null $targetAet
     * @return array{job_id:string,status:string,progress:int,full_response:array}
     */
    public function executeCMoveAtLevel($node, $level, array $resources, $targetAet = null) {
        $type = $node['node_type'] ?? '';
        if ($type === 'dicomweb') {
            throw new Exception('C-MOVE no está disponible para nodos DICOMweb. Use streaming directo.');
        }
        if ($type === 'local') {
            throw new Exception('C-MOVE no aplica al nodo local en este flujo');
        }
        if (empty($node['aet']) || empty($node['host'])) {
            throw new Exception('C-MOVE requiere AET y host DIMSE (nodos DIMSE e híbridos con peer DIMSE)');
        }
        $level = trim((string) $level);
        if (!in_array($level, ['Study', 'Series', 'Instance'], true)) {
            throw new Exception('C-MOVE: Level inválido');
        }
        if (empty($resources)) {
            throw new Exception('C-MOVE: Resources vacío');
        }

        $modalityId = $this->getOrthancModalityId($node);
        $url = $this->orthancBaseUrl . '/modalities/' . $modalityId . '/move';

        if (empty($targetAet)) {
            try {
                $systemUrl = $this->orthancBaseUrl . '/system';
                $systemInfo = $this->makeOrthancRequest('GET', $systemUrl);
                $targetAet = $systemInfo['DicomAet'] ?? 'ORTHANC';
                error_log("[C-MOVE] Usando DicomAet de Orthanc como TargetAet: $targetAet");
            } catch (Exception $e) {
                error_log('[C-MOVE] Warning: No se pudo obtener DicomAet de Orthanc: ' . $e->getMessage());
                $targetAet = 'ORTHANC';
                error_log('[C-MOVE] Usando TargetAet por defecto: ' . $targetAet);
            }
        }

        $data = [
            'Level' => $level,
            'Resources' => $resources,
            'TargetAet' => $targetAet,
            'Timeout' => 300,
            'Asynchronous' => true,
        ];

        error_log('[C-MOVE] Request to Orthanc: URL=' . $url . ' Level=' . $level);
        error_log('[C-MOVE] ModalityId=' . $modalityId);
        error_log('[C-MOVE] TargetAet=' . $targetAet);
        error_log('[C-MOVE] Resources: ' . json_encode($resources, JSON_UNESCAPED_UNICODE));
        error_log('[C-MOVE] Full Request Data: ' . json_encode($data, JSON_PRETTY_PRINT));

        try {
            $response = $this->makeOrthancRequest('POST', $url, $data, 120);
            error_log('[C-MOVE] Orthanc Response (completa): ' . json_encode($response, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            error_log('[C-MOVE] Error en makeOrthancRequest: ' . $e->getMessage());
            error_log('[C-MOVE] Request data que causó el error: ' . json_encode($data, JSON_PRETTY_PRINT));
            throw new Exception('Error en C-MOVE: ' . $e->getMessage());
        }

        $jobId = null;
        $status = 'Pending';
        $progress = 0;

        if (isset($response['ID'])) {
            $jobId = $response['ID'];
            $status = $response['State'] ?? 'Pending';
            $progress = $response['Progress'] ?? 0;
            error_log('[C-MOVE] Success - Job ID: ' . $jobId . ', State: ' . $status);
        } elseif (isset($response['Content']['ID'])) {
            $jobId = $response['Content']['ID'];
            $status = $response['State'] ?? $response['Content']['Status'] ?? 'Pending';
            $progress = $response['Progress'] ?? 0;
            error_log('[C-MOVE] Success - Job ID desde Content: ' . $jobId . ', State: ' . $status);
        } elseif (isset($response['Description']) && isset($response['RemoteAet'])) {
            error_log('[C-MOVE] Respuesta sin job ID pero con indicadores de éxito:');
            if (isset($response['Query']) || isset($response['RemoteAet'])) {
                $jobId = 'completed-immediately-' . time();
                $status = 'Success';
                $progress = 100;
                error_log('[C-MOVE] C-MOVE completado inmediatamente (sin job ID). Marcando como Success.');
            } else {
                error_log('[C-MOVE] Warning: Respuesta inesperada pero con RemoteAet. Asumiendo éxito.');
                $jobId = 'unknown-' . time();
                $status = 'Success';
                $progress = 100;
            }
        } else {
            error_log('[C-MOVE] Response sin ID y sin indicadores de éxito. Respuesta completa: ' . json_encode($response, JSON_PRETTY_PRINT));
            throw new Exception('Error en C-MOVE: no se obtuvo ID de job y la respuesta no tiene formato esperado. Respuesta: ' . json_encode($response));
        }

        return [
            'job_id' => $jobId,
            'status' => $status,
            'progress' => $progress,
            'full_response' => $response,
        ];
    }

    /**
     * Ejecuta C-MOVE (recuperación) de estudios a nivel Study (comportamiento histórico).
     *
     * @param array  $node
     * @param array  $studyInstanceUIDs
     * @param string $targetAet
     * @return array Información del job creado
     */
    public function executeCMove($node, $studyInstanceUIDs, $targetAet = 'ORTHANC') {
        $resources = [];
        foreach ($studyInstanceUIDs as $uid) {
            $resources[] = [
                'StudyInstanceUID' => $uid,
            ];
        }

        return $this->executeCMoveAtLevel($node, 'Study', $resources, $targetAet);
    }
    
    /**
     * Obtiene el estado de un job en Orthanc
     * 
     * @param string $jobId ID del job
     * @return array Estado del job
     */
    public function getJobStatus($jobId) {
        $url = $this->orthancBaseUrl . '/jobs/' . $jobId;
        $response = $this->makeOrthancRequest('GET', $url);
        
        $content = $response['Content'] ?? [];
        
        // Extraer instancias recibidas desde Content
        // Orthanc puede tener: Content.Instances, Content.RetrievedInstancesIds, o ambos
        $receivedInstances = 0;
        
        if (isset($content['Instances'])) {
            // Campo directo con número de instancias
            $receivedInstances = (int)$content['Instances'];
        } elseif (isset($content['RetrievedInstancesIds']) && is_array($content['RetrievedInstancesIds'])) {
            // Lista de IDs de instancias recibidas
            $receivedInstances = count($content['RetrievedInstancesIds']);
        } elseif (isset($content['Resources']) && is_array($content['Resources'])) {
            // Contar recursos procesados
            $receivedInstances = count($content['Resources']);
        }
        
        $orthancMessage = '';
        foreach (['Message', 'Description', 'HttpError', 'OrthancError'] as $k) {
            if (!empty($response[$k]) && is_scalar($response[$k])) {
                $t = trim((string) $response[$k]);
                if ($t !== '') {
                    $orthancMessage = substr($t, 0, 2000);
                    break;
                }
            }
        }

        return [
            'job_id' => $jobId,
            'status' => $response['State'] ?? 'Unknown',
            'progress' => $response['Progress'] ?? 0,
            'content' => $content,
            'received_instances' => $receivedInstances,
            'failed_instances' => (int) ($content['FailedInstances'] ?? 0),
            'orthanc_message' => $orthancMessage,
        ];
    }
    
    /**
     * Parsea la respuesta de /content?simplify de Orthanc.
     * Formato: { "PatientName": "PADILLA^ROD", "PatientID": "31739479", ... }
     */
    private function parseSimplifiedDicomAnswer($content) {
        if (!is_array($content)) {
            return $this->getEmptyResult();
        }
        
        // PatientName: convertir formato DICOM APELLIDO^NOMBRE a legible
        $patientName = $content['PatientName'] ?? '';
        if (!empty($patientName)) {
            $patientName = str_replace('^', ' ', $patientName);
            $patientName = trim($patientName);
        }
        
        // StudyDate: convertir de YYYYMMDD a YYYY-MM-DD
        $studyDate = $content['StudyDate'] ?? '';
        if (!empty($studyDate) && strlen($studyDate) === 8 && ctype_digit($studyDate)) {
            $studyDate = substr($studyDate, 0, 4) . '-' . substr($studyDate, 4, 2) . '-' . substr($studyDate, 6, 2);
        }
        
        // PatientBirthDate: convertir de YYYYMMDD a YYYY-MM-DD
        $birthDate = $content['PatientBirthDate'] ?? '';
        if (!empty($birthDate) && strlen($birthDate) === 8 && ctype_digit($birthDate)) {
            $birthDate = substr($birthDate, 0, 4) . '-' . substr($birthDate, 4, 2) . '-' . substr($birthDate, 6, 2);
        }
        
        // StudyTime: convertir de HHMMSS a HH:MM:SS
        $studyTime = $content['StudyTime'] ?? '';
        if (!empty($studyTime) && strlen($studyTime) >= 6) {
            $studyTime = substr($studyTime, 0, 2) . ':' . substr($studyTime, 2, 2) . ':' . substr($studyTime, 4, 2);
        }
        
        return [
            'PatientName'                   => $patientName,
            'PatientID'                     => $content['PatientID'] ?? '',
            'PatientBirthDate'              => $birthDate,
            'StudyInstanceUID'              => $content['StudyInstanceUID'] ?? '',
            'StudyDate'                     => $studyDate,
            'StudyTime'                     => $studyTime,
            'StudyDescription'              => $content['StudyDescription'] ?? '',
            'AccessionNumber'               => $content['AccessionNumber'] ?? '',
            'ModalitiesInStudy'             => $content['ModalitiesInStudy'] ?? '',
            'NumberOfStudyRelatedSeries'    => (int)($content['NumberOfStudyRelatedSeries'] ?? 0),
            'NumberOfStudyRelatedInstances' => (int)($content['NumberOfStudyRelatedInstances'] ?? 0),
        ];
    }

    /**
     * Parsea una respuesta DICOM a formato estándar (formato sin ?simplify)
     */
    private function parseDicomAnswer($answer) {
        // Orthanc retorna: { "ID": "...", "Type": "DicomAnswers", "Content": {...} }
        // O cuando se obtiene de /queries/{id}/answers, puede venir directamente el Content
        $content = $answer['Content'] ?? $answer ?? [];
        
        // Si $content no es un array o está vacío, retornar valores vacíos
        if (!is_array($content) || empty($content)) {
            return $this->getEmptyResult();
        }
        
        // Helper para extraer valor de tag DICOM
        $getTagValue = function($tagWithComma) use ($content) {
            // Intentar con coma primero (formato estándar Orthanc: "0010,0010")
            $tag = $tagWithComma;
            if (!isset($content[$tag])) {
                // Intentar sin coma (formato alternativo: "00100010")
                $tag = str_replace(',', '', $tagWithComma);
            }
            
            if (!isset($content[$tag])) {
                return '';
            }
            
            $tagData = $content[$tag];
            
            // Orthanc retorna: { "Name": "...", "Type": "String", "Value": "..." }
            // O puede ser directamente el valor
            if (is_array($tagData)) {
                // Buscar 'Value' primero (formato estándar)
                if (isset($tagData['Value'])) {
                    $value = $tagData['Value'];
                } elseif (isset($tagData[0])) {
                    // Array directo
                    $value = $tagData[0];
                } else {
                    // Intentar el primer valor del array
                    $value = reset($tagData);
                }
            } else {
                $value = $tagData;
            }
            
            // Si es array, tomar el primer elemento
            if (is_array($value) && !empty($value)) {
                $value = is_string($value[0]) ? $value[0] : (isset($value[0]['Value']) ? $value[0]['Value'] : reset($value));
            }
            
            return (string)$value;
        };
        
        // Extraer valores
        $patientName = $getTagValue('0010,0010');
        $patientID = $getTagValue('0010,0020');
        $patientBirthDate = $getTagValue('0010,0030');
        $studyInstanceUID = $getTagValue('0020,000D');
        $studyDate = $getTagValue('0008,0020');
        $studyTime = $getTagValue('0008,0030');
        $studyDescription = $getTagValue('0008,1030');
        $accessionNumber = $getTagValue('0008,0050');
        $modalitiesInStudy = $getTagValue('0008,0061');
        $numberOfSeries = $getTagValue('0020,1206');
        $numberOfInstances = $getTagValue('0020,1208');
        
        // Formatear PatientName: convertir de formato DICOM (APELLIDO^NOMBRE) a legible
        if (!empty($patientName)) {
            $patientName = str_replace('^', ' ', $patientName);
            $patientName = trim($patientName);
        }
        
        // Formatear StudyDate: convertir de YYYYMMDD a YYYY-MM-DD
        if (!empty($studyDate) && strlen($studyDate) === 8 && ctype_digit($studyDate)) {
            $studyDate = substr($studyDate, 0, 4) . '-' . substr($studyDate, 4, 2) . '-' . substr($studyDate, 6, 2);
        }
        
        // Formatear PatientBirthDate: convertir de YYYYMMDD a YYYY-MM-DD
        if (!empty($patientBirthDate) && strlen($patientBirthDate) === 8 && ctype_digit($patientBirthDate)) {
            $patientBirthDate = substr($patientBirthDate, 0, 4) . '-' . substr($patientBirthDate, 4, 2) . '-' . substr($patientBirthDate, 6, 2);
        }
        
        // Formatear StudyTime: convertir DICOM TM a HH:MM:SS
        // TM puede venir como HHMMSS, HHMMSS.FFFFFF o incluso con separadores.
        if (!empty($studyTime)) {
            $rawStudyTime = trim((string)$studyTime);
            if ($rawStudyTime !== '' && strpos($rawStudyTime, ':') === false) {
                $digits = preg_replace('/\D/', '', $rawStudyTime);
                if (is_string($digits) && strlen($digits) >= 6) {
                    $studyTime = substr($digits, 0, 2) . ':' . substr($digits, 2, 2) . ':' . substr($digits, 4, 2);
                }
            }
        }
        
        // Convertir números a enteros
        $numberOfSeries = !empty($numberOfSeries) ? (int)$numberOfSeries : 0;
        $numberOfInstances = !empty($numberOfInstances) ? (int)$numberOfInstances : 0;
        
        // Extraer campos de implementación si están disponibles
        $manufacturer = $getTagValue('0008,0070');
        $manufacturerModel = $getTagValue('0008,1090');
        $softwareVersion = $getTagValue('0018,1020');
        $stationName = $getTagValue('0008,1010');
        $institutionName = $getTagValue('0008,0080');
        $institutionAddress = $getTagValue('0008,0081');
        
        return [
            'PatientName' => $patientName,
            'PatientID' => $patientID,
            'PatientBirthDate' => $patientBirthDate,
            'StudyInstanceUID' => $studyInstanceUID,
            'StudyDate' => $studyDate,
            'StudyTime' => $studyTime,
            'StudyDescription' => $studyDescription,
            'AccessionNumber' => $accessionNumber,
            'ModalitiesInStudy' => $modalitiesInStudy,
            'NumberOfStudyRelatedSeries' => $numberOfSeries,
            'NumberOfStudyRelatedInstances' => $numberOfInstances,
            // Campos de implementación
            'Manufacturer' => $manufacturer,
            'ManufacturerModelName' => $manufacturerModel,
            'SoftwareVersion' => $softwareVersion,
            'StationName' => $stationName,
            'InstitutionName' => $institutionName,
            'InstitutionAddress' => $institutionAddress
        ];
    }
    
    /**
     * Retorna un resultado vacío con estructura estándar
     */
    private function getEmptyResult() {
        return [
            'PatientName' => '',
            'PatientID' => '',
            'PatientBirthDate' => '',
            'StudyInstanceUID' => '',
            'StudyDate' => '',
            'StudyTime' => '',
            'StudyDescription' => '',
            'AccessionNumber' => '',
            'ModalitiesInStudy' => '',
            'NumberOfStudyRelatedSeries' => 0,
            'NumberOfStudyRelatedInstances' => 0
        ];
    }
    
    /**
     * Convierte formato DICOMweb a formato estándar
     */
    private function convertDicomwebToStandard($dicomwebResult) {
        return [
            'PatientName' => $dicomwebResult['00100010']['Value'][0]['Alphabetic'] ?? '',
            'PatientID' => $dicomwebResult['00100020']['Value'][0] ?? '',
            'PatientBirthDate' => $dicomwebResult['00100030']['Value'][0] ?? '',
            'StudyInstanceUID' => $dicomwebResult['0020000D']['Value'][0] ?? '',
            'StudyDate' => $dicomwebResult['00080020']['Value'][0] ?? '',
            'StudyTime' => $dicomwebResult['00080030']['Value'][0] ?? '',
            'StudyDescription' => $dicomwebResult['00081030']['Value'][0] ?? '',
            'AccessionNumber' => $dicomwebResult['00080050']['Value'][0] ?? '',
            'ModalitiesInStudy' => $dicomwebResult['00080061']['Value'][0] ?? '',
            'NumberOfStudyRelatedSeries' => $dicomwebResult['00201206']['Value'][0] ?? 0,
            'NumberOfStudyRelatedInstances' => $dicomwebResult['00201208']['Value'][0] ?? 0
        ];
    }
    
    /**
     * Parsea detalles de estudio de Orthanc
     */
    private function parseStudyDetails($study, &$patientCache = []) {
        $mainTags = $study['MainDicomTags'] ?? [];
        
        // Obtener datos del paciente
        // Orthanc con Expand=true puede tener PatientMainDicomTags o necesitar consultar el paciente padre
        $patientName = '';
        $patientID = '';
        $patientBirthDate = '';
        
        // Intentar desde PatientMainDicomTags primero (si existe en estructura expandida)
        if (isset($study['PatientMainDicomTags'])) {
            $patientMainTags = $study['PatientMainDicomTags'];
            $patientName = $patientMainTags['PatientName'] ?? '';
            $patientID = $patientMainTags['PatientID'] ?? '';
            $patientBirthDate = $patientMainTags['PatientBirthDate'] ?? '';
            if ($this->isDebugEnabled()) {
                error_log("[PACS_NODES][PARSE] Datos desde PatientMainDicomTags: Name=" . ($patientName ?: 'EMPTY') . ", ID=" . ($patientID ?: 'EMPTY'));
            }
        }
        
        // Si no están, intentar desde MainDicomTags (por compatibilidad)
        if (empty($patientName) || empty($patientID)) {
            $patientName = $mainTags['PatientName'] ?? $patientName;
            $patientID = $mainTags['PatientID'] ?? $patientID;
            $patientBirthDate = $mainTags['PatientBirthDate'] ?? $patientBirthDate;
            if (($patientName || $patientID) && $this->isDebugEnabled()) {
                error_log("[PACS_NODES][PARSE] Datos desde MainDicomTags: Name=" . ($patientName ?: 'EMPTY') . ", ID=" . ($patientID ?: 'EMPTY'));
            }
        }
        
        // Si aún no están, consultar el paciente padre (igual que hace OrthancClient)
        if ((empty($patientName) || empty($patientID)) && !empty($study['ParentPatient'])) {
            $patientId = $study['ParentPatient'];
            if ($this->isDebugEnabled()) {
                error_log("[PACS_NODES][PARSE] Consultando paciente padre: " . $patientId);
            }
            
            // Usar cache si existe
            if (!isset($patientCache[$patientId])) {
                try {
                    $patientUrl = $this->orthancBaseUrl . '/patients/' . $patientId;
                    $patient = $this->makeOrthancRequest('GET', $patientUrl, null, 10);
                    $patientCache[$patientId] = $patient;
                    if ($this->isDebugEnabled()) {
                        error_log("[PACS_NODES][PARSE] Paciente obtenido desde API: " . json_encode($patient['MainDicomTags'] ?? []));
                    }
                } catch (Exception $e) {
                    if ($this->isDebugEnabled()) {
                        error_log("[PACS_NODES] Error obteniendo paciente padre: " . $e->getMessage());
                    }
                    $patientCache[$patientId] = null;
                }
            }
            
            if ($patientCache[$patientId]) {
                $patientMainTags = $patientCache[$patientId]['MainDicomTags'] ?? [];
                
                if (empty($patientName)) {
                    $patientName = $patientMainTags['PatientName'] ?? '';
                }
                if (empty($patientID)) {
                    $patientID = $patientMainTags['PatientID'] ?? '';
                }
                if (empty($patientBirthDate)) {
                    $patientBirthDate = $patientMainTags['PatientBirthDate'] ?? '';
                }
                if ($this->isDebugEnabled()) {
                    error_log("[PACS_NODES][PARSE] Datos desde paciente padre: Name=" . ($patientName ?: 'EMPTY') . ", ID=" . ($patientID ?: 'EMPTY'));
                }
            }
        }
        
        // Formatear PatientName: convertir de formato DICOM (APELLIDO^NOMBRE) a legible
        if (!empty($patientName)) {
            $patientName = str_replace('^', ' ', $patientName);
            $patientName = trim($patientName);
        }
        
        // Formatear StudyDate: convertir de YYYYMMDD a YYYY-MM-DD si viene sin guiones
        $studyDate = $mainTags['StudyDate'] ?? '';
        if (!empty($studyDate) && strlen($studyDate) === 8 && ctype_digit($studyDate)) {
            $studyDate = substr($studyDate, 0, 4) . '-' . substr($studyDate, 4, 2) . '-' . substr($studyDate, 6, 2);
        }
        
        // Formatear StudyTime: convertir DICOM TM a HH:MM:SS si viene sin dos puntos
        $studyTime = $mainTags['StudyTime'] ?? '';
        if (!empty($studyTime)) {
            $rawStudyTime = trim((string)$studyTime);
            if ($rawStudyTime !== '' && strpos($rawStudyTime, ':') === false) {
                $digits = preg_replace('/\D/', '', $rawStudyTime);
                if (is_string($digits) && strlen($digits) >= 6) {
                    $studyTime = substr($digits, 0, 2) . ':' . substr($digits, 2, 2) . ':' . substr($digits, 4, 2);
                }
            }
        }
        
        // Formatear PatientBirthDate: convertir de YYYYMMDD a YYYY-MM-DD si viene sin guiones
        if (!empty($patientBirthDate) && strlen($patientBirthDate) === 8 && ctype_digit($patientBirthDate)) {
            $patientBirthDate = substr($patientBirthDate, 0, 4) . '-' . substr($patientBirthDate, 4, 2) . '-' . substr($patientBirthDate, 6, 2);
        }
        
        // Recorrer series UNA SOLA VEZ para obtener modalidades + conteo de instancias.
        // Orthanc local responde muy rápido a estas llamadas; es más eficiente que /statistics.
        // Prioridad: si ModalitiesInStudy ya viene en MainDicomTags, no llamamos series para eso.
        $modalitiesFromTags = isset($mainTags['ModalitiesInStudy'])
            ? (string)$mainTags['ModalitiesInStudy']
            : null;

        $seriesList  = $study['Series'] ?? [];
        $seriesCount = count($seriesList);
        $instancesCount = 0;
        $resolvedModalities = $modalitiesFromTags;

        foreach ($seriesList as $seriesId) {
            try {
                $series = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/series/' . $seriesId);
                $instancesCount += count($series['Instances'] ?? []);
                // Acumular modalidades solo si no las teníamos de MainDicomTags
                if ($resolvedModalities === null) {
                    $mod = $series['MainDicomTags']['Modality'] ?? '';
                    if ($mod !== '') {
                        $resolvedModalities = ($resolvedModalities === null ? $mod : $resolvedModalities . '\\' . $mod);
                    }
                }
            } catch (Exception $e) {
                // Continuar con la siguiente serie
            }
        }

        $modalitiesInStudy = $resolvedModalities ?? '';

        return [
            'PatientName' => $patientName,
            'PatientID' => $patientID,
            'PatientBirthDate' => $patientBirthDate,
            'StudyInstanceUID' => $mainTags['StudyInstanceUID'] ?? '',
            'StudyDate' => $studyDate,
            'StudyTime' => $studyTime,
            'StudyDescription' => $mainTags['StudyDescription'] ?? '',
            'AccessionNumber' => $mainTags['AccessionNumber'] ?? '',
            'ModalitiesInStudy' => $modalitiesInStudy,
            'NumberOfStudyRelatedSeries' => $seriesCount,
            'NumberOfStudyRelatedInstances' => $instancesCount,
            // Campos de implementación
            'Manufacturer' => $mainTags['Manufacturer'] ?? '',
            'ManufacturerModelName' => $mainTags['ManufacturerModelName'] ?? '',
            'SoftwareVersion' => $mainTags['SoftwareVersion'] ?? '',
            'StationName' => $mainTags['StationName'] ?? '',
            'InstitutionName' => $mainTags['InstitutionName'] ?? '',
            'InstitutionAddress' => $mainTags['InstitutionAddress'] ?? ''
        ];
    }

    
    /**
     * Obtiene modalidades de un estudio
     */
    private function getStudyModalities($study) {
        // Intentar obtener de MainDicomTags
        if (isset($study['MainDicomTags']['ModalitiesInStudy'])) {
            return $study['MainDicomTags']['ModalitiesInStudy'];
        }

        // Evitar N requests por serie cuando la búsqueda devuelve muchos estudios.
        $seriesList = $study['Series'] ?? [];
        $seriesCount = is_array($seriesList) ? count($seriesList) : 0;
        if ($seriesCount === 0 || $seriesCount > 12) {
            return '';
        }

        // Si no está y hay pocas series, obtener de las series
        $modalities = [];
        foreach ($seriesList as $seriesId) {
            try {
                $series = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/series/' . $seriesId);
                $modality = $series['MainDicomTags']['Modality'] ?? '';
                if ($modality && !in_array($modality, $modalities)) {
                    $modalities[] = $modality;
                }
            } catch (Exception $e) {
                // Continuar con siguiente serie
            }
        }
        
        return implode('\\', $modalities);
    }
    
    
    /**
     * Obtiene información de un estudio en el Orthanc LOCAL usando POST /tools/find
     * Usa /studies/{id}/statistics para obtener conteos sin descargar listas completas.
     * 
     * @param string $studyInstanceUID StudyInstanceUID del estudio
     * @return array|null ['orthanc_id'=>string, 'series'=>int, 'instances'=>int, 'found'=>bool] o null en error
     */
    public function getLocalStudyInfo($studyInstanceUID) {
        try {
            // Paso 1: Buscar el Orthanc Study ID por StudyInstanceUID (búsqueda indexada, rápida < 100ms)
            $findUrl = $this->orthancBaseUrl . '/tools/find';
            $findBody = [
                'Level' => 'Study',
                'Query' => ['StudyInstanceUID' => $studyInstanceUID]
            ];
            $studyIds = $this->makeOrthancRequest('POST', $findUrl, $findBody, 10);
            
            if (empty($studyIds) || !is_array($studyIds)) {
                return ['found' => false, 'series' => 0, 'instances' => 0];
            }
            
            $orthancStudyId = $studyIds[0];
            
            // Paso 2: Obtener estadísticas del estudio — 1 petición, sin listas grandes
            // GET /studies/{id}/statistics devuelve: CountInstances, CountSeries
            $statsUrl = $this->orthancBaseUrl . '/studies/' . $orthancStudyId . '/statistics';
            $stats = $this->makeOrthancRequest('GET', $statsUrl, null, 10);
            
            $instanceCount = (int)($stats['CountInstances'] ?? 0);
            $seriesCount   = (int)($stats['CountSeries']   ?? 0);
            
            // Fallback: si no hay statistics, contar series manualmente
            if ($seriesCount === 0 && $instanceCount === 0) {
                $study = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/studies/' . $orthancStudyId, null, 10);
                $seriesCount = count($study['Series'] ?? []);
            }
            
            return [
                'orthanc_id'  => $orthancStudyId,
                'series'      => $seriesCount,
                'instances'   => $instanceCount,
                'found'       => true
            ];
        } catch (Exception $e) {
            error_log("[PacsNodeClient] Error en getLocalStudyInfo($studyInstanceUID): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mapa SeriesInstanceUID => número de instancias en Orthanc local para un StudyInstanceUID.
     *
     * @return array<string,int>
     */
    public function getLocalSeriesInstanceCountsByStudyUid($studyInstanceUID) {
        $studyInstanceUID = trim((string) $studyInstanceUID);
        if ($studyInstanceUID === '') {
            return [];
        }
        try {
            $info = $this->getLocalStudyInfo($studyInstanceUID);
            if (!$info || empty($info['found']) || empty($info['orthanc_id'])) {
                return [];
            }
            $studyOrthancId = $info['orthanc_id'];
            $seriesIds = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/studies/' . $studyOrthancId . '/series', null, 60);
            if (!is_array($seriesIds)) {
                return [];
            }
            $out = [];
            foreach ($seriesIds as $seriesOrthancId) {
                if (!is_string($seriesOrthancId) || $seriesOrthancId === '') {
                    continue;
                }
                $stats = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/series/' . $seriesOrthancId . '/statistics', null, 20);
                $inst = (int) ($stats['CountInstances'] ?? 0);
                $seriesJson = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/series/' . $seriesOrthancId, null, 20);
                $tags = is_array($seriesJson) ? ($seriesJson['MainDicomTags'] ?? []) : [];
                $serUid = (string) ($tags['SeriesInstanceUID'] ?? '');
                if ($serUid !== '') {
                    $out[$serUid] = ($out[$serUid] ?? 0) + $inst;
                }
            }

            return $out;
        } catch (Exception $e) {
            error_log('[PacsNodeClient] getLocalSeriesInstanceCountsByStudyUid: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Conjunto de SOPInstanceUID presentes en Orthanc local para el estudio (hasta $maxInstances).
     *
     * @return array<string,bool> claves = SOPInstanceUID
     */
    public function getLocalSopInstanceUidSetForStudy($studyInstanceUID, $maxInstances = 8000) {
        $studyInstanceUID = trim((string) $studyInstanceUID);
        $maxInstances = max(100, min(50000, (int) $maxInstances));
        if ($studyInstanceUID === '') {
            return [];
        }
        try {
            $findUrl = $this->orthancBaseUrl . '/tools/find';
            $findBody = [
                'Level' => 'Instance',
                'Query' => ['StudyInstanceUID' => $studyInstanceUID],
            ];
            $instanceIds = $this->makeOrthancRequest('POST', $findUrl, $findBody, 120);
            if (!is_array($instanceIds)) {
                return [];
            }
            $set = [];
            $n = 0;
            foreach ($instanceIds as $iid) {
                if ($n >= $maxInstances) {
                    break;
                }
                if (!is_string($iid) || $iid === '') {
                    continue;
                }
                $n++;
                try {
                    $inst = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/instances/' . $iid, null, 15);
                    $tags = is_array($inst) ? ($inst['MainDicomTags'] ?? []) : [];
                    $sop = (string) ($tags['SOPInstanceUID'] ?? '');
                    if ($sop !== '') {
                        $set[$sop] = true;
                    }
                } catch (Exception $e) {
                    if ($this->isDebugEnabled()) {
                        error_log('[PacsNodeClient] simplified-tags instance ' . $iid . ': ' . $e->getMessage());
                    }
                }
            }

            return $set;
        } catch (Exception $e) {
            error_log('[PacsNodeClient] getLocalSopInstanceUidSetForStudy: ' . $e->getMessage());

            return [];
        }
    }
    
    /**
     * Obtiene un resumen demográfico del estudio en Orthanc local.
     * Retorna datos livianos para UI de Jobs (sin conteos).
     *
     * @param string $studyInstanceUID
     * @return array ['found'=>bool, 'patient_name'=>string, 'patient_id'=>string, 'study_date'=>string]
     */
    public function getLocalStudySummary($studyInstanceUID) {
        try {
            $uid = trim((string)$studyInstanceUID);
            if ($uid === '') {
                return ['found' => false, 'patient_name' => '', 'patient_id' => '', 'study_date' => ''];
            }
            
            $findUrl = $this->orthancBaseUrl . '/tools/find';
            $findBody = [
                'Level' => 'Study',
                'Query' => ['StudyInstanceUID' => $uid],
                'Expand' => true,
                'Limit' => 1
            ];
            $rows = $this->makeOrthancRequest('POST', $findUrl, $findBody, 10);
            if (empty($rows) || !is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
                return ['found' => false, 'patient_name' => '', 'patient_id' => '', 'study_date' => ''];
            }
            
            $study = $rows[0];
            $mainTags = $study['MainDicomTags'] ?? [];
            $patientTags = $study['PatientMainDicomTags'] ?? [];
            
            $patientName = (string)($patientTags['PatientName'] ?? ($mainTags['PatientName'] ?? ''));
            $patientId = (string)($patientTags['PatientID'] ?? ($mainTags['PatientID'] ?? ''));
            $studyDate = (string)($mainTags['StudyDate'] ?? '');
            
            if ($patientName !== '') {
                $patientName = trim(str_replace('^', ' ', $patientName));
            }
            if ($studyDate !== '' && strlen($studyDate) === 8 && ctype_digit($studyDate)) {
                $studyDate = substr($studyDate, 0, 4) . '-' . substr($studyDate, 4, 2) . '-' . substr($studyDate, 6, 2);
            }
            
            return [
                'found' => true,
                'patient_name' => $patientName,
                'patient_id' => $patientId,
                'study_date' => $studyDate
            ];
        } catch (Exception $e) {
            error_log("[PacsNodeClient] Error en getLocalStudySummary($studyInstanceUID): " . $e->getMessage());
            return ['found' => false, 'patient_name' => '', 'patient_id' => '', 'study_date' => ''];
        }
    }

    /**
     * Alias para compatibilidad con código anterior
     * @deprecated Usar getLocalStudyInfo()
     */
    public function getStudyInstancesCount($studyInstanceUID) {
        $info = $this->getLocalStudyInfo($studyInstanceUID);
        if (!$info || !$info['found']) return null;
        return ['series' => $info['series'], 'instances' => $info['instances']];
    }
    
    /**
     * Lee eventos de /changes en Orthanc local de forma incremental.
     *
     * @param int $since Cursor secuencial previo
     * @param int $limit Máximo de eventos
     * @return array ['changes' => array, 'last' => int, 'done' => bool]
     */
    public function getOrthancChanges($since = 0, $limit = 200) {
        try {
            $s = max(0, (int)$since);
            $l = max(1, min(1000, (int)$limit));
            $url = $this->orthancBaseUrl . '/changes?since=' . $s . '&limit=' . $l;
            $response = $this->makeOrthancRequest('GET', $url, null, 10);
            $changes = [];
            $last = $s;
            $done = true;
            if (is_array($response)) {
                if (isset($response['Changes']) && is_array($response['Changes'])) {
                    $changes = $response['Changes'];
                } elseif (isset($response[0]) && is_array($response[0])) {
                    // Compatibilidad con formatos antiguos
                    $changes = $response;
                }
                if (isset($response['Last'])) {
                    $last = (int)$response['Last'];
                } elseif (!empty($changes)) {
                    $seqs = array_map(function($c) { return (int)($c['Seq'] ?? 0); }, $changes);
                    $last = max($seqs);
                }
                if (isset($response['Done'])) {
                    $done = (bool)$response['Done'];
                }
            }
            return [
                'changes' => $changes,
                'last' => $last,
                'done' => $done
            ];
        } catch (Exception $e) {
            error_log("[PacsNodeClient] Error en getOrthancChanges(since=$since): " . $e->getMessage());
            return ['changes' => [], 'last' => (int)$since, 'done' => true];
        }
    }
    
    /**
     * Obtiene StudyInstanceUID de una instancia local de Orthanc.
     *
     * @param string $instanceId ID interno de instancia en Orthanc
     * @return string StudyInstanceUID o vacío si no disponible
     */
    public function getStudyUidFromInstanceId($instanceId) {
        $id = trim((string)$instanceId);
        if ($id === '') {
            return '';
        }
        try {
            $url = $this->orthancBaseUrl . '/instances/' . rawurlencode($id) . '/simplified-tags';
            $tags = $this->makeOrthancRequest('GET', $url, null, 10);
            return trim((string)($tags['StudyInstanceUID'] ?? ''));
        } catch (Exception $e) {
            error_log("[PacsNodeClient] Error en getStudyUidFromInstanceId($id): " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Realiza una petición HTTP a Orthanc
     * 
     * @param string $method Método HTTP (GET, POST, PUT, DELETE)
     * @param string $url URL completa
     * @param array|null $data Datos a enviar (para POST/PUT)
     * @param int $timeout Timeout en segundos (default: 30)
     */
    private function makeOrthancRequest($method, $url, $data = null, $timeout = 30) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout / 3)); // Timeout de conexión proporcional
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } elseif ($method === 'PUT' && $data) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // No registrar cada petición OK: con C-FIND/manifest el volumen a stderr rompe nginx+php-fpm (502).
        if (getenv('PACS_ORTHANC_TRACE') === '1') {
            error_log("[makeOrthancRequest] HTTP Code: $httpCode, URL: $url");
        }
        if ($httpCode >= 400) {
            error_log("[makeOrthancRequest] Error Response (raw): " . $response);
        }
        
        if ($error) {
            error_log("[makeOrthancRequest] cURL Error: " . $error);
            throw new Exception('Error de conexión con Orthanc: ' . $error);
        }
        
        if ($httpCode >= 400) {
            $errorMsg = json_decode($response, true);
            $errorDetails = '';
            
            // Capturar toda la información del error de Orthanc
            if (is_array($errorMsg)) {
                $errorDetails = json_encode($errorMsg, JSON_PRETTY_PRINT);
                $message = $errorMsg['Message'] ?? $errorMsg['HttpError'] ?? $errorMsg['OrthancError'] ?? $response;
                
                // Log detallado de todos los campos del error
                error_log("[makeOrthancRequest] Error HTTP $httpCode - Detalles completos:");
                error_log("[makeOrthancRequest] " . $errorDetails);
                if (isset($errorMsg['HttpStatus'])) {
                    error_log("[makeOrthancRequest] HttpStatus: " . $errorMsg['HttpStatus']);
                }
                if (isset($errorMsg['OrthancStatus'])) {
                    error_log("[makeOrthancRequest] OrthancStatus: " . $errorMsg['OrthancStatus']);
                }
            } else {
                $errorDetails = $response;
                $message = $response;
                error_log("[makeOrthancRequest] Error HTTP $httpCode - Response (no JSON): " . $errorDetails);
            }
            
            throw new Exception('Error HTTP ' . $httpCode . ': ' . $message);
        }
        
        return json_decode($response, true) ?? [];
    }
    
    /**
     * Obtiene información de implementación del PACS (fabricante, versión, modelo)
     * Estrategia combinada: primero verifica conectividad con C-ECHO, luego hace C-FIND
     * para obtener información de implementación desde los objetos DICOM
     * 
     * @param array $node Datos del nodo
     * @return array Información de implementación detectada
     */
    public function getNodeImplementationInfo($node) {
        try {
            $backend = $this->resolveFindQueryBackend($node);
        } catch (Exception $e) {
            return ['detected' => false, 'error' => $e->getMessage()];
        }
        if ($backend === 'qido') {
            return $this->getDicomwebImplementationInfo($node);
        }
        if ($backend === 'dimse') {
            return $this->getDimseImplementationInfo($node);
        }
        if ($backend === 'local') {
            return $this->getLocalImplementationInfo();
        }
        return ['detected' => false, 'error' => 'Tipo de nodo no soportado'];
    }
    
    /**
     * Obtiene información de implementación mediante C-ECHO + C-FIND DIMSE
     */
    private function getDimseImplementationInfo($node) {
        try {
            // Paso 1: Verificar conectividad con C-ECHO
            $echoResult = $this->testConnection($node);
            if (!$echoResult['success']) {
                return [
                    'detected' => false,
                    'error' => 'C-ECHO falló: ' . ($echoResult['error'] ?? 'Sin conexión'),
                    'echo_success' => false
                ];
            }
            
            // Paso 2: Hacer C-FIND para obtener información de implementación
            // Usamos fecha del día actual para hacer un query más específico y rápido
            $modalityId = $this->getOrthancModalityId($node);
            $url = $this->orthancBaseUrl . '/modalities/' . $modalityId . '/query';
            
            // C-FIND a nivel Study con fecha del día actual
            // Los campos de implementación están disponibles en los estudios
            $today = date('Ymd'); // Formato DICOM: YYYYMMDD
            $query = [
                'Level' => 'Study',
                'Query' => [
                    'StudyDate' => $today, // Fecha del día actual para query más específico
                    // Campos de implementación que queremos obtener
                    'Manufacturer' => '',
                    'ManufacturerModelName' => '',
                    'SoftwareVersion' => '',
                    'StationName' => '',
                    'InstitutionName' => '',
                    'InstitutionAddress' => ''
                ]
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 45); // Aumentado a 45 segundos
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Aumentado a 10 segundos
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                error_log("[PACS_NODES] Error en C-FIND para info de implementación: " . $error);
                $errorMsg = 'Error de conexión en C-FIND: ' . $error;
                // Si es timeout, dar un mensaje más claro
                if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                    $errorMsg = 'C-FIND excedió el tiempo de espera (45s). El PACS puede estar procesando una query muy grande o tener muchos estudios. Intente nuevamente o verifique la conectividad.';
                }
                return [
                    'detected' => false,
                    'error' => $errorMsg,
                    'echo_success' => true
                ];
            }
            
            if ($httpCode !== 200) {
                error_log("[PACS_NODES] Error HTTP $httpCode en C-FIND para info de implementación");
                return [
                    'detected' => false,
                    'error' => "C-FIND falló con HTTP $httpCode",
                    'echo_success' => true
                ];
            }
            
            $queryResponse = json_decode($response, true);
            if (!isset($queryResponse['ID'])) {
                return [
                    'detected' => false,
                    'error' => 'No se obtuvo ID de query en C-FIND',
                    'echo_success' => true
                ];
            }
            
            $queryId = $queryResponse['ID'];
            
            // Obtener primera respuesta (solo necesitamos una para obtener info del PACS)
            // Esperar un poco para que Orthanc procese la query
            sleep(2);
            
            $answersUrl = $this->orthancBaseUrl . '/queries/' . $queryId . '/answers';
            $answerIndices = [];
            try {
                // Usar timeout mayor para obtener respuestas (60 segundos)
                $answerIndices = $this->makeOrthancRequest('GET', $answersUrl, null, 60);
            } catch (Exception $e) {
                error_log("[PACS_NODES] Error obteniendo respuestas de C-FIND: " . $e->getMessage());
                // Limpiar query
                try {
                    $this->makeOrthancRequest('DELETE', $this->orthancBaseUrl . '/queries/' . $queryId, null, 10);
                } catch (Exception $e2) {
                    // Ignorar
                }
                return [
                    'detected' => false,
                    'error' => 'No se obtuvieron respuestas de C-FIND: ' . $e->getMessage(),
                    'echo_success' => true
                ];
            }
            
            $info = [
                'detected' => false,
                'manufacturer' => '',
                'manufacturer_model' => '',
                'software_version' => '',
                'station_name' => '',
                'institution_name' => '',
                'institution_address' => '',
                'implementation_class_uid' => '',
                'implementation_version_name' => '',
                'detected_from' => 'dicom_objects',
                'echo_success' => true
            ];
            
            // Intentar obtener Implementation Class UID y Version Name desde logs de Orthanc
            // (si están disponibles y Orthanc tiene --trace-dicom habilitado)
            try {
                $logInfo = $this->getImplementationFromOrthancLogs($node);
                if ($logInfo) {
                    $info['implementation_class_uid'] = $logInfo['implementation_class_uid'] ?? '';
                    $info['implementation_version_name'] = $logInfo['implementation_version_name'] ?? '';
                    $info['detected_from'] = $logInfo['detected_from'] ?? 'orthanc_logs';
                }
            } catch (Exception $e) {
                error_log("[PACS_NODES] Error obteniendo info desde logs: " . $e->getMessage());
            }
            
            if (!empty($answerIndices) && isset($answerIndices[0])) {
                $contentUrl = $this->orthancBaseUrl . '/queries/' . $queryId . '/answers/' . $answerIndices[0] . '/content?simplify';
                try {
                    // Usar timeout mayor para obtener contenido (60 segundos)
                    $content = $this->makeOrthancRequest('GET', $contentUrl, null, 60);
                    
                    // Extraer campos de implementación
                    $getTagValue = function($tag) use ($content) {
                        $tagWithComma = $tag;
                        if (!isset($content[$tagWithComma])) {
                            $tagWithComma = str_replace(',', '', $tag);
                        }
                        if (!isset($content[$tagWithComma])) {
                            return '';
                        }
                        $tagData = $content[$tagWithComma];
                        if (is_array($tagData)) {
                            if (isset($tagData['Value'])) {
                                $value = $tagData['Value'];
                            } elseif (isset($tagData[0])) {
                                $value = $tagData[0];
                            } else {
                                $value = reset($tagData);
                            }
                        } else {
                            $value = $tagData;
                        }
                        if (is_array($value) && !empty($value)) {
                            $value = is_string($value[0]) ? $value[0] : (isset($value[0]['Value']) ? $value[0]['Value'] : reset($value));
                        }
                        return trim((string)$value);
                    };
                    
                    $info['manufacturer'] = $getTagValue('0008,0070');
                    $info['manufacturer_model'] = $getTagValue('0008,1090');
                    $info['software_version'] = $getTagValue('0018,1020');
                    $info['station_name'] = $getTagValue('0008,1010');
                    $info['institution_name'] = $getTagValue('0008,0080');
                    $info['institution_address'] = $getTagValue('0008,0081');
                    
                    // Si al menos tenemos manufacturer, consideramos que detectamos info
                    $info['detected'] = !empty($info['manufacturer']);
                    
                } catch (Exception $e) {
                    error_log("[PACS_NODES] Error obteniendo contenido de respuesta: " . $e->getMessage());
                }
            }
            
            // Limpiar query temporal
            try {
                $this->makeOrthancRequest('DELETE', $this->orthancBaseUrl . '/queries/' . $queryId, null, 10);
            } catch (Exception $e) {
                // Ignorar error de limpieza
            }
            
            return $info;
            
        } catch (Exception $e) {
            error_log("[PACS_NODES] Error obteniendo info de implementación: " . $e->getMessage());
            return [
                'detected' => false,
                'error' => $e->getMessage(),
                'echo_success' => false
            ];
        }
    }
    
    /**
     * Obtiene información de implementación para nodos DICOMweb
     */
    private function getDicomwebImplementationInfo($node) {
        // Para DICOMweb, la información de implementación es limitada
        // Podríamos intentar obtener metadata desde /studies, pero es menos confiable
        return [
            'detected' => false,
            'error' => 'Detección de implementación no disponible para nodos DICOMweb',
            'echo_success' => null
        ];
    }
    
    /**
     * Obtiene información de implementación del nodo local (Orthanc)
     */
    private function getLocalImplementationInfo() {
        try {
            $systemInfo = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/system');
            return [
                'detected' => true,
                'manufacturer' => 'Orthanc',
                'software_version' => $systemInfo['Version'] ?? 'Unknown',
                'station_name' => $systemInfo['DicomAet'] ?? 'ORTHANC',
                'echo_success' => true
            ];
        } catch (Exception $e) {
            return [
                'detected' => false,
                'error' => $e->getMessage(),
                'echo_success' => false
            ];
        }
    }
    
    /**
     * Intenta obtener Implementation Class UID y Version Name desde logs de Orthanc
     * 
     * NOTA: Esto requiere que Orthanc esté configurado con --trace-dicom o TraceDicom: true
     * y que tengamos acceso a los logs. Si no están disponibles, retorna null.
     * 
     * @param array $node Datos del nodo
     * @return array|null Información de implementación desde logs o null si no disponible
     */
    public function getImplementationFromOrthancLogs($node) {
        // Intentar obtener desde logs de Orthanc si están disponibles
        // Esto es opcional y requiere configuración específica de Orthanc
        
        // Posibles ubicaciones de logs de Orthanc
        $possibleLogPaths = [
            '/var/log/orthanc/orthanc.log',
            '/var/log/orthanc.log',
            '/opt/orthanc/logs/orthanc.log',
            sys_get_temp_dir() . '/orthanc.log'
        ];
        
        $aet = $node['aet'] ?? '';
        $host = $node['host'] ?? '';
        
        if (empty($aet) || empty($host)) {
            return null;
        }
        
        // Buscar en logs (si están disponibles)
        foreach ($possibleLogPaths as $logPath) {
            if (file_exists($logPath) && is_readable($logPath)) {
                try {
                    $info = $this->parseOrthancLogsForImplementation($logPath, $aet, $host);
                    if ($info) {
                        return $info;
                    }
                } catch (Exception $e) {
                    error_log("[PACS_NODES] Error parseando logs de Orthanc: " . $e->getMessage());
                }
            }
        }
        
        return null;
    }
    
    /**
     * Parsea logs de Orthanc buscando Implementation Class UID y Version Name
     * 
     * Busca líneas como:
     * - "implClass: 1.2.40.0.13.1.1.1"
     * - "implVersion: dcm4che-1.4.34"
     * 
     * @param string $logPath Ruta al archivo de log
     * @param string $aet AET del nodo
     * @param string $host Host del nodo
     * @return array|null Información encontrada o null
     */
    private function parseOrthancLogsForImplementation($logPath, $aet, $host) {
        // Leer últimas 1000 líneas del log (más recientes)
        $lines = [];
        if (function_exists('exec')) {
            exec("tail -n 1000 " . escapeshellarg($logPath) . " 2>/dev/null", $lines);
        } else {
            // Fallback: leer todo el archivo (puede ser lento)
            $content = @file_get_contents($logPath);
            if ($content) {
                $allLines = explode("\n", $content);
                $lines = array_slice($allLines, -1000);
            }
        }
        
        if (empty($lines)) {
            return null;
        }
        
        // Buscar asociaciones recientes con este nodo
        $implClass = null;
        $implVersion = null;
        
        // Buscar en orden inverso (más recientes primero)
        $lines = array_reverse($lines);
        
        foreach ($lines as $line) {
            // Buscar líneas que mencionen el AET o host
            if (strpos($line, $aet) !== false || strpos($line, $host) !== false) {
                // Buscar implClass
                if (preg_match('/implClass:\s*([0-9.]+)/i', $line, $matches)) {
                    $implClass = $matches[1];
                }
                // Buscar implVersion
                if (preg_match('/implVersion:\s*([^\s]+)/i', $line, $matches)) {
                    $implVersion = trim($matches[1]);
                }
                
                // Si encontramos ambos, retornar
                if ($implClass && $implVersion) {
                    return [
                        'implementation_class_uid' => $implClass,
                        'implementation_version_name' => $implVersion,
                        'detected_from' => 'orthanc_logs'
                    ];
                }
            }
        }
        
        // Si encontramos al menos uno, retornar
        if ($implClass || $implVersion) {
            return [
                'implementation_class_uid' => $implClass,
                'implementation_version_name' => $implVersion,
                'detected_from' => 'orthanc_logs'
            ];
        }
        
        return null;
    }
}
