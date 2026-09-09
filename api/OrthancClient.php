<?php
// Asegurar que no haya salida antes de require
if (!class_exists('OrthancConfig')) {
    require_once __DIR__ . '/config/orthanc_config.php';
}

/**
 * Cliente para interactuar con la API REST de Orthanc
 * Maneja todas las operaciones de consulta y recuperación de datos
 */
class OrthancClient {
    private $baseUrl;
    private $credentials;
    private $timeout;
    private $debugEnabled;
    
    public function __construct() {
        $this->baseUrl = OrthancConfig::getServerUrl();
        $this->credentials = OrthancConfig::getCredentials();
        $this->timeout = OrthancConfig::getConfig()['api']['timeout'];
        $this->debugEnabled = false;
        
        $this->debugLog('[ORTHANC_CLIENT] Constructor - URL: ' . $this->baseUrl);
        $this->debugLog('[ORTHANC_CLIENT] Constructor - Usuario: ' . $this->credentials['username']);
        $this->debugLog('[ORTHANC_CLIENT] Constructor - Timeout: ' . $this->timeout);
    }

    private function debugLog($message) {
        if ($this->debugEnabled) {
            error_log($message);
        }
    }
    
    /**
     * Realiza una petición HTTP a la API de Orthanc
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        
        $this->debugLog('[ORTHANC_CLIENT] makeRequest: ' . $method . ' ' . $url);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->credentials['username'] . ':' . $this->credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, OrthancConfig::getConfig()['api']['connect_timeout']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, OrthancConfig::getConfig()['api']['verify_ssl']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        // Habilitar HTTP/2 para mejor rendimiento (si está disponible)
        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }
        
        // Keep-alive para reutilizar conexiones
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $this->debugLog('[ORTHANC_CLIENT] POST data: ' . json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->debugLog('[ORTHANC_CLIENT] Response HTTP Code: ' . $httpCode);
        
        if ($error) {
            $this->debugLog('[ORTHANC_CLIENT] cURL Error: ' . $error);
            throw new Exception('Error de conexión: ' . $error);
        }
        
        if ($httpCode !== 200) {
            $this->debugLog('[ORTHANC_CLIENT] HTTP Error ' . $httpCode . ': ' . substr($response, 0, 200));
            throw new Exception('Error HTTP: ' . $httpCode . ' - ' . substr($response, 0, 100));
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->debugLog('[ORTHANC_CLIENT] JSON Error: ' . json_last_error_msg());
            $this->debugLog('[ORTHANC_CLIENT] Response (first 500 chars): ' . substr($response, 0, 500));
        }
        
        return $decoded;
    }
    
    /**
     * Busca estudios por ID de paciente usando /tools/find (optimizado)
     */
    public function findStudiesByPatientId($patientId) {
        $query = [
            'Level' => 'Study',
            'Query' => [
                'PatientID' => $patientId
            ],
            'Expand' => true  // Obtener detalles expandidos en una sola consulta
        ];
        
        $results = $this->makeRequest('/tools/find', 'POST', $query);
        
        $studies = [];
        $patientCache = [];  // Cache para evitar consultas repetidas de paciente
        
        foreach ($results as $study) {
            try {
                // Si expand=true funciona, $study ya contiene los detalles
                if (is_array($study) && isset($study['MainDicomTags'])) {
                    $studyData = $study;
                } else {
                    // Fallback: consulta individual si expand no funciona
                    $studyData = $this->makeRequest('/studies/' . $study);
                }
                
                $patientOrthancId = $studyData['ParentPatient'] ?? '';
                $patientTags = $studyData['PatientMainDicomTags'] ?? [];
                $patient = null;

                // Fallback: cargar paciente si no vino expandido o faltan tags esenciales.
                $needsPatientLookup = empty($patientTags['PatientID']) || empty($patientTags['PatientName']);
                if ($needsPatientLookup && $patientOrthancId !== '') {
                    if (!isset($patientCache[$patientOrthancId])) {
                        $patientCache[$patientOrthancId] = $this->makeRequest('/patients/' . $patientOrthancId);
                    }
                    $patient = $patientCache[$patientOrthancId];
                    $patientTags = array_merge($patientTags, $patient['MainDicomTags'] ?? []);
                }
                
                $studyId = is_string($study) ? $study : $studyData['ID'];
                try {
                    $modalitiesList = $this->getStudyModalitiesListFromStudyData($studyData);
                } catch (Exception $e) {
                    $this->debugLog('[ORTHANC_CLIENT] Modalidades estudio ' . $studyId . ': ' . $e->getMessage());
                    $modalitiesList = [];
                }
                $studyDesc = (string) ($studyData['MainDicomTags']['StudyDescription'] ?? '');
                $isPacsPdfInforme = self::matchesPacsPdfInformeStudy($modalitiesList, $studyDesc);

                $studies[] = [
                    'study_id' => $studyId,
                    'orthanc_id' => $studyId,
                    'patient_id' => $patientTags['PatientID'] ?? '',
                    'patient_name' => $patientTags['PatientName'] ?? '',
                    'patient_birth_date' => $patientTags['PatientBirthDate'] ?? '',
                    'study_date' => $studyData['MainDicomTags']['StudyDate'] ?? '',
                    'study_time' => $studyData['MainDicomTags']['StudyTime'] ?? '',
                    'study_description' => $studyDesc,
                    'study_instance_uid' => $studyData['MainDicomTags']['StudyInstanceUID'] ?? '',
                    'modality' => !empty($modalitiesList) ? implode(', ', $modalitiesList) : 'N/A',
                    'accession_number' => $studyData['MainDicomTags']['AccessionNumber'] ?? '',
                    'referring_physician' => $studyData['MainDicomTags']['ReferringPhysicianName'] ?? '',
                    'institution_name' => $studyData['MainDicomTags']['InstitutionName'] ?? '',
                    'series_count' => count($studyData['Series'] ?? []),
                    'viewer_url' => OrthancConfig::getViewerUrl($studyId, 'UDV', $studyData['MainDicomTags']['StudyInstanceUID'] ?? null),
                    'viewer_url_mobile' => OrthancConfig::getViewerUrl($studyId, 'UDV', $studyData['MainDicomTags']['StudyInstanceUID'] ?? null, true),
                    'viewer_url_desktop' => OrthancConfig::getViewerUrl($studyId, 'StoneViewer', $studyData['MainDicomTags']['StudyInstanceUID'] ?? null, false),
                    'is_pacs_pdf_informe' => $isPacsPdfInforme,
                ];
            } catch (Exception $e) {
                error_log('Error procesando estudio: ' . $e->getMessage());
                continue;
            }
        }
        
        return $studies;
    }
    
    /**
     * Obtiene detalles de un estudio específico
     */
    public function getStudyDetails($studyId) {
        try {
            $study = $this->makeRequest('/studies/' . $studyId);
            $patientTags = $study['PatientMainDicomTags'] ?? [];
            $patientOrthancId = $study['ParentPatient'] ?? '';

            // Orthanc a veces deja PatientName vacío en /patients/{id} pero completo en PatientMainDicomTags del estudio.
            $needsPatientLookup = empty($patientTags['PatientID']) || empty($patientTags['PatientName']);
            if ($needsPatientLookup && $patientOrthancId !== '') {
                $patient = $this->makeRequest('/patients/' . $patientOrthancId);
                foreach ($patient['MainDicomTags'] ?? [] as $tag => $value) {
                    if (($value !== '' && $value !== null) && empty($patientTags[$tag])) {
                        $patientTags[$tag] = $value;
                    }
                }
            }
            
            return [
                'study_id' => $studyId,
                'orthanc_id' => $studyId,
                'patient_id' => $patientTags['PatientID'] ?? '',
                'patient_name' => $patientTags['PatientName'] ?? '',
                'patient_birth_date' => $patientTags['PatientBirthDate'] ?? '',
                'patient_sex' => $patientTags['PatientSex'] ?? '',
                'study_date' => $study['MainDicomTags']['StudyDate'] ?? '',
                'study_time' => $study['MainDicomTags']['StudyTime'] ?? '',
                'study_description' => $study['MainDicomTags']['StudyDescription'] ?? '',
                'study_instance_uid' => $study['MainDicomTags']['StudyInstanceUID'] ?? '',
                'modality' => $this->getStudyModalities($studyId),
                'accession_number' => $study['MainDicomTags']['AccessionNumber'] ?? '',
                'referring_physician' => $study['MainDicomTags']['ReferringPhysicianName'] ?? '',
                'institution_name' => $study['MainDicomTags']['InstitutionName'] ?? '',
                'series_count' => count($study['Series'] ?? []),
                'viewer_url' => OrthancConfig::getViewerUrl($studyId, 'UDV', $study['MainDicomTags']['StudyInstanceUID'] ?? null),
                'viewer_url_mobile' => OrthancConfig::getViewerUrl($studyId, 'UDV', $study['MainDicomTags']['StudyInstanceUID'] ?? null, true),
                'viewer_url_desktop' => OrthancConfig::getViewerUrl($studyId, 'StoneViewer', $study['MainDicomTags']['StudyInstanceUID'] ?? null, false),
            ];
        } catch (Exception $e) {
            error_log('Error obteniendo detalles del estudio: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Recurso JSON del estudio en Orthanc (GET /studies/{id REST interno}).
     */
    private function fetchOrthancStudyResource(string $studyId): ?array
    {
        $studyId = trim($studyId);
        if ($studyId === '') {
            return null;
        }
        $pathId = rawurlencode($studyId);
        $segments = $pathId === $studyId ? [$pathId] : [$pathId, $studyId];
        foreach ($segments as $segment) {
            try {
                $study = $this->makeRequest('/studies/' . $segment);
                if (is_array($study) && isset($study['MainDicomTags'])) {
                    return $study;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * StudyDate (0008,0020) desde recurso /studies/{id}. $studyId = ID interno Orthanc (UUID).
     */
    private function fetchStudyDateFromOrthancStudyEndpoint(string $studyId): ?string
    {
        $study = $this->fetchOrthancStudyResource($studyId);
        if (!$study) {
            return null;
        }
        $raw = $study['MainDicomTags']['StudyDate'] ?? '';
        $digits = preg_replace('/\D/', '', (string) $raw);
        if (strlen($digits) < 8) {
            return null;
        }
        $digits = substr($digits, 0, 8);

        return substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2);
    }

    /**
     * Busca estudios en Orthanc por fecha exacta (YYYYMMDD) y token de nombre (apellido parcial).
     * Usado como Stage 3 de auto-vinculación: cuando el patient_id no coincide (ej. error de tipeo
     * en el equipo) pero sí tenemos fecha y nombre del informe recibido.
     * Devuelve el mismo formato que findStudiesByPatientId para compatibilidad.
     */
    public function findStudiesByDateAndNameToken(string $studyDateDicom, string $nameToken): array
    {
        $studyDateDicom = preg_replace('/\D/', '', $studyDateDicom);
        if (strlen($studyDateDicom) !== 8) {
            return [];
        }
        $nameToken = trim($nameToken);
        if ($nameToken === '') {
            return [];
        }
        // Orthanc /tools/find soporta wildcards: "ROBLES*"
        $query = [
            'Level'  => 'Study',
            'Query'  => [
                'StudyDate'   => $studyDateDicom,
                'PatientName' => strtoupper($nameToken) . '*',
            ],
            'Expand' => true,
        ];
        try {
            $results = $this->makeRequest('/tools/find', 'POST', $query);
        } catch (Exception $e) {
            error_log('[ORTHANC_CLIENT] findStudiesByDateAndNameToken error: ' . $e->getMessage());
            return [];
        }
        if (!is_array($results)) {
            return [];
        }

        $studies      = [];
        $patientCache = [];
        foreach ($results as $study) {
            try {
                if (is_array($study) && isset($study['MainDicomTags'])) {
                    $studyData = $study;
                } else {
                    $studyData = $this->makeRequest('/studies/' . $study);
                }
                $patientOrthancId = $studyData['ParentPatient'] ?? '';
                $patientTags      = $studyData['PatientMainDicomTags'] ?? [];
                $needsPatientLookup = empty($patientTags['PatientID']) || empty($patientTags['PatientName']);
                if ($needsPatientLookup && $patientOrthancId !== '') {
                    if (!isset($patientCache[$patientOrthancId])) {
                        $patientCache[$patientOrthancId] = $this->makeRequest('/patients/' . $patientOrthancId);
                    }
                    $patientTags = array_merge($patientTags, $patientCache[$patientOrthancId]['MainDicomTags'] ?? []);
                }
                $studyId = is_string($study) ? $study : ($studyData['ID'] ?? '');
                try {
                    $modalitiesList = $this->getStudyModalitiesListFromStudyData($studyData);
                } catch (Exception $e) {
                    $modalitiesList = [];
                }
                $studyDesc = (string)($studyData['MainDicomTags']['StudyDescription'] ?? '');
                $studies[] = [
                    'study_id'           => $studyId,
                    'orthanc_id'         => $studyId,
                    'patient_id'         => $patientTags['PatientID'] ?? '',
                    'patient_name'       => $patientTags['PatientName'] ?? '',
                    'study_date'         => $studyData['MainDicomTags']['StudyDate'] ?? '',
                    'study_description'  => $studyDesc,
                    'study_instance_uid' => $studyData['MainDicomTags']['StudyInstanceUID'] ?? '',
                    'modality'           => !empty($modalitiesList) ? implode(', ', $modalitiesList) : 'N/A',
                    'accession_number'   => $studyData['MainDicomTags']['AccessionNumber'] ?? '',
                    'series_count'       => count($studyData['Series'] ?? []),
                    'is_pacs_pdf_informe'=> self::matchesPacsPdfInformeStudy($modalitiesList, $studyDesc),
                ];
            } catch (Exception $e) {
                error_log('[ORTHANC_CLIENT] findStudiesByDateAndNameToken study error: ' . $e->getMessage());
                continue;
            }
        }
        return $studies;
    }

    /**
     * Tags principales del estudio en PACS (para UI cuando estudios.* viene vacío en BD).
     *
     * @return array{study_instance_uid: string, accession_number: string}
     */
    public function getStudyPacsDisplayMeta(string $orthancInternalStudyId): array
    {
        $out = ['study_instance_uid' => '', 'accession_number' => ''];
        $study = $this->fetchOrthancStudyResource(trim($orthancInternalStudyId));
        if (!$study) {
            return $out;
        }
        $tags = $study['MainDicomTags'] ?? [];
        $out['study_instance_uid'] = trim((string) ($tags['StudyInstanceUID'] ?? ''));
        $out['accession_number'] = trim((string) ($tags['AccessionNumber'] ?? ''));

        return $out;
    }

    /**
     * StudyDate DICOM del estudio en Orthanc, normalizado a yyyy-mm-dd (para UI / scoring).
     * Si en BD se guardó el Study Instance UID (1.2...) en lugar del ID REST de Orthanc (UUID), se resuelve con /tools/find.
     */
    public function getStudyDateIsoFromOrthanc(string $orthancStudyId): ?string
    {
        $orthancStudyId = trim($orthancStudyId);
        if ($orthancStudyId === '') {
            return null;
        }
        $tryIds = [];
        if (preg_match('/^1\\.2\\.\\d/', $orthancStudyId)) {
            $resolved = $this->findOrthancStudyIdByStudyInstanceUid($orthancStudyId);
            if ($resolved !== null && $resolved !== '' && $resolved !== $orthancStudyId) {
                $tryIds[] = $resolved;
            }
        }
        $tryIds[] = $orthancStudyId;
        $tryIds = array_values(array_unique($tryIds));
        foreach ($tryIds as $id) {
            $iso = $this->fetchStudyDateFromOrthancStudyEndpoint($id);
            if ($iso !== null && $iso !== '') {
                return $iso;
            }
        }

        return null;
    }

    /**
     * Resuelve el ID interno de estudio en Orthanc a partir del Study Instance UID (DICOM).
     */
    public function findOrthancStudyIdByStudyInstanceUid(string $studyInstanceUid): ?string
    {
        $studyInstanceUid = trim($studyInstanceUid);
        if ($studyInstanceUid === '') {
            return null;
        }
        try {
            $results = $this->makeRequest('/tools/find', 'POST', [
                'Level' => 'Study',
                'Query' => [
                    'StudyInstanceUID' => $studyInstanceUid,
                ],
            ]);
            if (!is_array($results) || $results === []) {
                return null;
            }
            $first = $results[0];
            if (is_string($first) && $first !== '') {
                return $first;
            }
            if (is_array($first) && !empty($first['ID'])) {
                return (string) $first['ID'];
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Lista única de modalidades de todas las series (ordenadas).
     * @param array $studyData Respuesta expandida o GET /studies/{id}
     * @return list<string>
     */
    private function getStudyModalitiesListFromStudyData(array $studyData): array {
        $modalities = [];
        if (!isset($studyData['Series']) || !is_array($studyData['Series'])) {
            return [];
        }
        foreach ($studyData['Series'] as $seriesId) {
            try {
                $series = $this->makeRequest('/series/' . $seriesId);
                $modality = $series['MainDicomTags']['Modality'] ?? '';
                if ($modality !== '' && !in_array($modality, $modalities, true)) {
                    $modalities[] = $modality;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        sort($modalities);
        return $modalities;
    }

    /**
     * Informe PDF encapsulado en PACS: solo modalidad DOC y StudyDescription INFORME (normalizado).
     *
     * @param list<string> $uniqueSortedModalities
     */
    public static function matchesPacsPdfInformeStudy(array $uniqueSortedModalities, string $studyDescription): bool {
        if (count($uniqueSortedModalities) !== 1) {
            return false;
        }
        if (strtoupper($uniqueSortedModalities[0]) !== 'DOC') {
            return false;
        }
        return strtoupper(trim($studyDescription)) === 'INFORME';
    }

    /**
     * Resuelve la primera instancia de la primera serie DOC si el estudio pertenece al paciente
     * y cumple DOC + INFORME (misma regla que el listado del portal paciente).
     *
     * @return string|null instance Orthanc ID
     */
    public function resolvePacsPdfInformeInstanceForPatient(string $studyId, string $expectedPatientId): ?string {
        $studyId = trim($studyId);
        $expectedPatientId = trim($expectedPatientId);
        if ($studyId === '' || $expectedPatientId === '') {
            return null;
        }
        try {
            $study = $this->makeRequest('/studies/' . rawurlencode($studyId));
        } catch (Exception $e) {
            $this->debugLog('[ORTHANC_CLIENT] resolvePacsPdfInformeInstanceForPatient study: ' . $e->getMessage());
            return null;
        }
        $pid = trim((string) ($study['PatientMainDicomTags']['PatientID'] ?? ''));
        if ($pid !== $expectedPatientId) {
            return null;
        }
        $mods = $this->getStudyModalitiesListFromStudyData($study);
        $desc = (string) ($study['MainDicomTags']['StudyDescription'] ?? '');
        if (!self::matchesPacsPdfInformeStudy($mods, $desc)) {
            return null;
        }
        foreach ($study['Series'] ?? [] as $seriesId) {
            try {
                $series = $this->makeRequest('/series/' . rawurlencode($seriesId));
            } catch (Exception $e) {
                continue;
            }
            if (($series['MainDicomTags']['Modality'] ?? '') !== 'DOC') {
                continue;
            }
            $instances = $series['Instances'] ?? [];
            if (!is_array($instances) || $instances === []) {
                continue;
            }
            $instanceId = (string) $instances[0];
            if ($instanceId !== '') {
                return $instanceId;
            }
        }
        return null;
    }

    /**
     * GET binario a Orthanc (sin decodificar JSON).
     *
     * @return array{http_code:int, body:string, error:string}
     */
    public function getBinary(string $endpoint): array {
        $path = $endpoint;
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->credentials['username'] . ':' . $this->credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, OrthancConfig::getConfig()['api']['connect_timeout']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, OrthancConfig::getConfig()['api']['verify_ssl']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            $body = '';
        }
        return ['http_code' => $httpCode, 'body' => $body, 'error' => $err];
    }

    /**
     * Obtiene las modalidades de un estudio (optimizado)
     * Obtiene todas las modalidades únicas de todas las series
     */
    private function getStudyModalitiesOptimized($studyData) {
        try {
            $modalities = $this->getStudyModalitiesListFromStudyData($studyData);
            return !empty($modalities) ? implode(', ', $modalities) : 'N/A';
        } catch (Exception $e) {
            error_log('Error obteniendo modalidades: ' . $e->getMessage());
            return 'N/A';
        }
    }
    
    /**
     * Obtiene las modalidades de un estudio (método original)
     */
    private function getStudyModalities($studyId) {
        try {
            $study = $this->makeRequest('/studies/' . $studyId);
            $modalities = [];
            
            foreach ($study['Series'] as $seriesId) {
                $series = $this->makeRequest('/series/' . $seriesId);
                $modality = $series['MainDicomTags']['Modality'] ?? '';
                if ($modality && !in_array($modality, $modalities)) {
                    $modalities[] = $modality;
                }
            }
            
            return implode(', ', $modalities);
        } catch (Exception $e) {
            return '';
        }
    }
    
    /**
     * Normaliza StudyDate DICOM (YYYYMMDD o con separadores / tiempo) a 8 dígitos.
     */
    private function dicomStudyDateToYmd($raw) {
        if ($raw === null || $raw === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string)$raw);
        if (strlen($digits) < 8) {
            return null;
        }
        return substr($digits, 0, 8);
    }
    
    /**
     * Obtiene todos los estudios usando el método eficiente /tools/find
     * OPTIMIZADO: Carga solo datos básicos inicialmente, sin consultas de series
     * Los datos detallados (modalidades, instancias) se pueden cargar bajo demanda
     *
     * @param bool $expandSingleCalendarDayForOrthancFind Solo para entornos donde /tools/find
     *        con un solo día (from=to) en Orthanc provoca timeout o 502: usa StudyDate A–(A+1)
     *        y filtra en PHP al día A. pacs-manager y otros callers deben dejarlo en false.
     */
    public function getAllStudiesEfficient($dateFrom = null, $dateTo = null, $patientId = null, $modality = null, $loadDetails = false, $expandSingleCalendarDayForOrthancFind = false) {
        try {
            // Construir query para filtros
            $query = [];
            $singleDayYmdFilter = null;
            
            // Filtro por rango de fechas (opcional)
            // Si hay fechas, se aplican al query; si no hay fechas pero sí patientId,
            // se busca el paciente en todo Orthanc sin restricción de fechas
            if ($dateFrom && $dateTo) {
                $fromFormatted = date('Ymd', strtotime($dateFrom));
                $toFormatted = date('Ymd', strtotime($dateTo));
                if ($fromFormatted === $toFormatted) {
                    if ($expandSingleCalendarDayForOrthancFind) {
                        // Workaround dashboard-unified / get_all_studies: Orthanc con día único a veces 502.
                        $nextDay = date('Ymd', strtotime($dateFrom . ' +1 day'));
                        $query['StudyDate'] = $fromFormatted . '-' . $nextDay;
                        $singleDayYmdFilter = $fromFormatted;
                    } else {
                        // Un día en DICOM: valor exacto YYYYMMDD (pacs-manager, cloud-storage, etc.)
                        $query['StudyDate'] = $fromFormatted;
                    }
                } else {
                    $query['StudyDate'] = $fromFormatted . '-' . $toFormatted;
                }
            } elseif ($dateFrom) {
                $query['StudyDate'] = date('Ymd', strtotime($dateFrom)) . '-';
            } elseif ($dateTo) {
                $query['StudyDate'] = '-' . date('Ymd', strtotime($dateTo));
            }
            
            // Filtro por ID de paciente (opcional, funciona con o sin fechas)
            // Si solo hay patientId sin fechas, busca en todo Orthanc
            // Si hay patientId + fechas, busca paciente en ese rango de fechas
            if ($patientId) {
                $query['PatientID'] = '*' . $patientId . '*';
            }
            
            // Nota: El filtro de modalidad se aplica después de obtener los estudios
            // porque ModalitiesInStudy en la consulta inicial puede causar errores HTTP 500
            
            $requestBody = [
                'Level' => 'Study',
                'Query' => $query,
                'Expand' => true
            ];
            
            $this->debugLog('[ORTHANC_CLIENT] getAllStudiesEfficient - Query: ' . json_encode($requestBody));
            
            $studies = $this->makeRequest('/tools/find', 'POST', $requestBody);
            
            if (!is_array($studies)) {
                $this->debugLog('[ORTHANC_CLIENT] Error: makeRequest no devolvió un array. Tipo: ' . gettype($studies));
                $studies = [];
            }
            
            $this->debugLog('[ORTHANC_CLIENT] Estudios recibidos del PACS: ' . count($studies));
            
            $formattedStudies = [];
            $patientCache = []; // Cache para evitar consultas repetidas de paciente
            
            foreach ($studies as $study) {
                try {
                    // Verificar si el estudio está expandido o es solo un ID (string)
                    // Si expand=true funciona, $study ya contiene los detalles
                    if (is_array($study) && isset($study['MainDicomTags'])) {
                        $studyData = $study;
                    } else {
                        // Fallback: si expand no funciona o devuelve solo IDs, cargar detalles individualmente
                        $studyId = is_string($study) ? $study : ($study['ID'] ?? null);
                        if (!$studyId) {
                            $this->debugLog('[ORTHANC_CLIENT] Error: No se pudo obtener ID del estudio');
                            continue;
                        }
                        $studyData = $this->makeRequest('/studies/' . $studyId);
                        
                        // Cargar datos del paciente si no están en el estudio expandido
                        if (!isset($studyData['PatientMainDicomTags'])) {
                            $patientId = $studyData['ParentPatient'] ?? null;
                            if ($patientId) {
                                // Usar cache para datos del paciente
                                if (!isset($patientCache[$patientId])) {
                                    $patientCache[$patientId] = $this->makeRequest('/patients/' . $patientId);
                                }
                                $patient = $patientCache[$patientId];
                                // Agregar datos del paciente al estudio para mantener compatibilidad
                                $studyData['PatientMainDicomTags'] = $patient['MainDicomTags'] ?? [];
                            }
                        }
                    }
                    
                    // Si loadDetails es false, usar datos básicos sin consultar series
                    // Esto reduce drásticamente el número de consultas HTTP
                    $studyModalityString = '';
                    $instancesCount = 0;
                    
                    // Si hay filtro de modalidad y loadDetails es false, necesitamos cargar detalles
                    // para poder aplicar el filtro correctamente
                    $needsDetailsForFilter = ($modality && $modality !== 'all' && !$loadDetails);
                    
                    if ($loadDetails || $needsDetailsForFilter) {
                        // Cargar detalles si se solicita explícitamente o si necesitamos filtrar por modalidad
                        // Obtener todas las modalidades únicas de todas las series
                        $studyModalities = [];
                        if (!empty($studyData['Series'])) {
                            foreach ($studyData['Series'] as $seriesId) {
                                try {
                                    $series = $this->makeRequest('/series/' . $seriesId);
                                    $seriesModality = $series['MainDicomTags']['Modality'] ?? '';
                                    if ($seriesModality && !in_array($seriesModality, $studyModalities)) {
                                        $studyModalities[] = $seriesModality;
                                    }
                                } catch (Exception $e) {
                                    // Continuar con la siguiente serie si hay error
                                    continue;
                                }
                            }
                        }
                        
                        // Ordenar modalidades alfabéticamente para consistencia
                        sort($studyModalities);
                        $studyModalityString = !empty($studyModalities) ? implode(', ', $studyModalities) : '';
                        
                        // Contar instancias solo si loadDetails es true
                        if ($loadDetails) {
                            $instancesCount = $this->countInstances($studyData['Series'] ?? []);
                        }
                        
                        // Aplicar filtro de modalidad después de obtener los datos
                        if ($modality && $modality !== 'all') {
                            $matchesModality = in_array($modality, $studyModalities);
                            if (!$matchesModality) {
                                continue; // Saltar este estudio si no coincide la modalidad
                            }
                        }
                    } else {
                        // Modo rápido: intentar usar ModalitiesInStudy de Orthanc si está disponible
                        // Orthanc proporciona este campo cuando se usa Expand=true
                        if (isset($studyData['MainDicomTags']['ModalitiesInStudy']) && 
                            !empty($studyData['MainDicomTags']['ModalitiesInStudy'])) {
                            // ModalitiesInStudy viene como string separado por backslash (ej: "CT\MR")
                            $modalitiesInStudy = $studyData['MainDicomTags']['ModalitiesInStudy'];
                            $modalityArray = array_map('trim', explode('\\', $modalitiesInStudy));
                            $modalityArray = array_filter($modalityArray); // Eliminar valores vacíos
                            sort($modalityArray);
                            $studyModalityString = !empty($modalityArray) ? implode(', ', $modalityArray) : '';
                        } else {
                            // Si no está disponible, dejar vacío para cargar bajo demanda
                            $studyModalityString = '';
                        }
                        $instancesCount = 0; // Se cargará bajo demanda
                    }
                    
                    $studyId = $studyData['ID'] ?? (is_string($study) ? $study : null);
                    if (!$studyId) {
                        $this->debugLog('[ORTHANC_CLIENT] Error: No se pudo obtener ID del estudio después de procesar');
                        continue;
                    }
                    
                    $formattedStudies[] = [
                        'orthanc_id' => $studyId,
                        'orthanc_study_id' => $studyId,
                        'patient_id' => $studyData['PatientMainDicomTags']['PatientID'] ?? '',
                        'patient_name' => $studyData['PatientMainDicomTags']['PatientName'] ?? '',
                        'patient_birth_date' => $studyData['PatientMainDicomTags']['PatientBirthDate'] ?? '',
                        'patient_sex' => $studyData['PatientMainDicomTags']['PatientSex'] ?? '',
                        'study_date' => $studyData['MainDicomTags']['StudyDate'] ?? '',
                        'study_time' => $studyData['MainDicomTags']['StudyTime'] ?? '',
                        'study_description' => $studyData['MainDicomTags']['StudyDescription'] ?? '',
                        'study_instance_uid' => $studyData['MainDicomTags']['StudyInstanceUID'] ?? '',
                        'accession_number' => $studyData['MainDicomTags']['AccessionNumber'] ?? '',
                        'referring_physician' => $studyData['MainDicomTags']['ReferringPhysicianName'] ?? '',
                        'institution_name' => $studyData['MainDicomTags']['InstitutionName'] ?? '',
                        'modality' => $studyModalityString,
                        'series_count' => count($studyData['Series'] ?? []),
                        'instances_count' => $instancesCount,
                        'viewer_url' => OrthancConfig::getViewerUrl($studyId, 'UDV', $studyData['MainDicomTags']['StudyInstanceUID'] ?? null),
                        'viewer_url_mobile' => OrthancConfig::getViewerUrl($studyId, 'UDV', $studyData['MainDicomTags']['StudyInstanceUID'] ?? null, true),
                        'viewer_url_desktop' => OrthancConfig::getViewerUrl($studyId, 'StoneViewer', $studyData['MainDicomTags']['StudyInstanceUID'] ?? null, false),
                        '_details_loaded' => $loadDetails, // Flag para saber si los detalles están cargados
                        '_series_ids' => $loadDetails ? null : ($studyData['Series'] ?? []) // Guardar IDs de series para carga diferida
                    ];
                } catch (Exception $e) {
                    $this->debugLog('[ORTHANC_CLIENT] Error procesando estudio: ' . $e->getMessage());
                    $this->debugLog('[ORTHANC_CLIENT] Trace: ' . $e->getTraceAsString());
                    continue; // Continuar con el siguiente estudio si hay error
                }
            }
            
            if ($singleDayYmdFilter !== null) {
                $before = count($formattedStudies);
                $formattedStudies = array_values(array_filter($formattedStudies, function ($s) use ($singleDayYmdFilter) {
                    return $this->dicomStudyDateToYmd($s['study_date'] ?? '') === $singleDayYmdFilter;
                }));
                $this->debugLog('[ORTHANC_CLIENT] Filtro día único ' . $singleDayYmdFilter . ': estudios ' . $before . ' -> ' . count($formattedStudies));
            }
            
            return $formattedStudies;
        } catch (Exception $e) {
            error_log('Error obteniendo estudios eficientemente: ' . $e->getMessage());
            // No devolver []: el llamador interpretaría "PACS vacío" y en get_all_studies
            // se dispara la rama de huérfanos sin NOT IN, con consultas masivas y 502.
            throw $e;
        }
    }
    
    /**
     * Carga los detalles de un estudio específico bajo demanda
     * Incluye modalidades e instancias_count
     */
    public function getStudyDetailsOnDemand($studyId, $seriesIds = null) {
        try {
            // Si no se proporcionan series_ids, obtenerlos del estudio
            if ($seriesIds === null) {
                $study = $this->makeRequest('/studies/' . $studyId);
                $seriesIds = $study['Series'] ?? [];
            }
            
            $studyModalities = [];
            $instancesCount = 0;
            
            // Obtener modalidades e instancias de las series
            if (!empty($seriesIds)) {
                foreach ($seriesIds as $seriesId) {
                    try {
                        $series = $this->makeRequest('/series/' . $seriesId);
                        $seriesModality = $series['MainDicomTags']['Modality'] ?? '';
                        if ($seriesModality && !in_array($seriesModality, $studyModalities)) {
                            $studyModalities[] = $seriesModality;
                        }
                        $instancesCount += count($series['Instances'] ?? []);
                    } catch (Exception $e) {
                        // Continuar con la siguiente serie si hay error
                        continue;
                    }
                }
            }
            
            // Ordenar modalidades alfabéticamente
            sort($studyModalities);
            $studyModalityString = !empty($studyModalities) ? implode(', ', $studyModalities) : 'N/A';
            
            // Contar el número de series
            $seriesCount = count($seriesIds ?? []);
            
            return [
                'modality' => $studyModalityString,
                'series_count' => $seriesCount,
                'instances_count' => $instancesCount,
                'details_loaded' => true
            ];
        } catch (Exception $e) {
            error_log('Error obteniendo detalles del estudio bajo demanda: ' . $e->getMessage());
            // Lanzar la excepción para que el endpoint pueda manejarla apropiadamente
            throw new Exception('Error obteniendo detalles del estudio: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Cuenta el número total de instancias en las series
     */
    private function countInstances($series) {
        $count = 0;
        foreach ($series as $serieId) {
            try {
                $serie = $this->makeRequest('/series/' . $serieId);
                $count += count($serie['Instances'] ?? []);
            } catch (Exception $e) {
                // Continuar si hay error en una serie
            }
        }
        return $count;
    }
    
    /**
     * Obtiene todos los pacientes del servidor Orthanc (método legacy)
     */
    public function getAllPatients() {
        try {
            $patientIds = $this->makeRequest('/patients');
            $patients = [];
            
            foreach ($patientIds as $patientId) {
                $patient = $this->makeRequest('/patients/' . $patientId);
                $studies = $this->getPatientStudies($patientId);
                
                $patients[] = [
                    'orthanc_id' => $patientId,
                    'patient_id' => $patient['MainDicomTags']['PatientID'] ?? '',
                    'patient_name' => $patient['MainDicomTags']['PatientName'] ?? '',
                    'patient_birth_date' => $patient['MainDicomTags']['PatientBirthDate'] ?? '',
                    'patient_sex' => $patient['MainDicomTags']['PatientSex'] ?? '',
                    'studies' => $studies,
                    'studies_count' => count($studies)
                ];
            }
            
            return $patients;
        } catch (Exception $e) {
            error_log('Error obteniendo pacientes: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene los estudios de un paciente específico
     */
    public function getPatientStudies($orthancPatientId) {
        try {
            $patient = $this->makeRequest('/patients/' . $orthancPatientId);
            $studies = [];
            
            foreach ($patient['Studies'] as $studyId) {
                $studyDetails = $this->getStudyDetails($studyId);
                if ($studyDetails) {
                    $studies[] = $studyDetails;
                }
            }
            
            return $studies;
        } catch (Exception $e) {
            error_log('Error obteniendo estudios del paciente: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Lista IDs de instancias MPPS (Orthanc ≥ 1.12.5 o plugin orthanc-mpps).
     * Si ?expand=true devuelve objetos, se normaliza a lista de IDs.
     *
     * @return list<string>
     */
    public function getMppsIds(): array
    {
        $raw = $this->makeRequest('/mpps');
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $ids[] = $item;
            } elseif (is_array($item)) {
                $id = (string) ($item['ID'] ?? $item['Id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * Detalle de una instancia MPPS.
     *
     * @return array<string,mixed>
     */
    public function getMppsById(string $mppsId): array
    {
        $mppsId = trim($mppsId);
        if ($mppsId === '') {
            throw new Exception('MPPS ID vacío');
        }
        $data = $this->makeRequest('/mpps/' . rawurlencode($mppsId));
        return is_array($data) ? $data : [];
    }

    /**
     * IDs de estudio Orthanc por StudyInstanceUID (vacío si no hay imágenes).
     *
     * @return list<string>
     */
    public function findStudyIdsByStudyInstanceUid(string $studyInstanceUid): array
    {
        $uid = trim($studyInstanceUid);
        if ($uid === '') {
            return [];
        }
        try {
            $results = $this->makeRequest('/tools/find', 'POST', [
                'Level' => 'Study',
                'Query' => ['StudyInstanceUID' => $uid],
            ]);
            if (!is_array($results)) {
                return [];
            }
            $ids = [];
            foreach ($results as $item) {
                if (is_string($item) && $item !== '') {
                    $ids[] = $item;
                } elseif (is_array($item) && !empty($item['ID'])) {
                    $ids[] = (string) $item['ID'];
                }
            }
            return $ids;
        } catch (Exception $e) {
            error_log('[ORTHANC_CLIENT] findStudyIdsByStudyInstanceUid: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Verifica el estado del servidor Orthanc
     */
    public function getServerStatus() {
        try {
            $system = $this->makeRequest('/system');
            return [
                'status' => 'connected',
                'version' => $system['Version'] ?? 'Unknown',
                'name' => $system['Name'] ?? 'Orthanc',
                'database_version' => $system['DatabaseVersion'] ?? 'Unknown'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
?>