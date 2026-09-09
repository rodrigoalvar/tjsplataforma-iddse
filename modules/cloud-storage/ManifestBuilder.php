<?php
/**
 * Constructor de manifest.json para estudios en R2
 * Sistema TJSMEDICAL - Cloud Storage Module
 */

require_once __DIR__ . '/../../api/OrthancClient.php';
require_once __DIR__ . '/drivers/R2StorageDriver.php';

class ManifestBuilder {
    private $orthancClient;
    private $r2Driver;
    
    public function __construct($orthancClient, $r2Driver) {
        $this->orthancClient = $orthancClient;
        $this->r2Driver = $r2Driver;
    }
    
    /**
     * Construye el manifest desde Orthanc y lo prepara para R2
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @return array Manifest estructurado
     */
    public function buildManifest($orthancStudyId) {
        try {
            // 1. Obtener info del estudio
            $studyInfo = $this->getStudyInfo($orthancStudyId);
            $studyInstanceUid = $studyInfo['MainDicomTags']['StudyInstanceUID'] ?? null;
            
            if (!$studyInstanceUid) {
                throw new Exception('No se pudo obtener StudyInstanceUID del estudio');
            }
            
            // 2. Obtener instancias del estudio
            $instances = $this->getInstances($orthancStudyId);
            
            // 3. Agrupar por series
            $seriesMap = [];
            foreach ($instances as $instance) {
                $seriesId = $instance['ParentSeries'] ?? null;
                if (!$seriesId) {
                    continue;
                }
                
                if (!isset($seriesMap[$seriesId])) {
                    $seriesInfo = $this->getSeriesInfo($seriesId);
                    $seriesMap[$seriesId] = [
                        'seriesInstanceUID' => $seriesInfo['MainDicomTags']['SeriesInstanceUID'] ?? '',
                        'seriesNumber' => $seriesInfo['MainDicomTags']['SeriesNumber'] ?? null,
                        'seriesDescription' => $seriesInfo['MainDicomTags']['SeriesDescription'] ?? '',
                        'modality' => $seriesInfo['MainDicomTags']['Modality'] ?? '',
                        'instances' => []
                    ];
                }
                
                // Agregar instancia con PATH RELATIVO (no URL presignada aún)
                $sopUid = $instance['MainDicomTags']['SOPInstanceUID'] ?? null;
                if (!$sopUid) {
                    continue;
                }
                
                $seriesMap[$seriesId]['instances'][] = [
                    'sopInstanceUID' => $sopUid,
                    'instanceNumber' => $instance['MainDicomTags']['InstanceNumber'] ?? null,
                    'fileSize' => $instance['FileSize'] ?? 0,
                    'path' => $this->r2Driver->getInstanceKey(
                        $studyInstanceUid,
                        $seriesMap[$seriesId]['seriesInstanceUID'],
                        $sopUid
                    )
                ];
            }
            
            // 4. Construir manifest final
            $manifest = [
                'studyInstanceUID' => $studyInstanceUid,
                'patientName' => $studyInfo['PatientMainDicomTags']['PatientName'] ?? '',
                'patientId' => $studyInfo['PatientMainDicomTags']['PatientID'] ?? '',
                'studyDescription' => $studyInfo['MainDicomTags']['StudyDescription'] ?? '',
                'studyDate' => $studyInfo['MainDicomTags']['StudyDate'] ?? '',
                'series' => array_values($seriesMap)
            ];
            
            return $manifest;
            
        } catch (Exception $e) {
            error_log('[MANIFEST_BUILDER] Error construyendo manifest: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtiene información del estudio desde Orthanc
     */
    private function getStudyInfo($orthancStudyId) {
        $baseUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();
        
        $ch = curl_init($baseUrl . '/studies/' . $orthancStudyId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error obteniendo estudio desde Orthanc: HTTP $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Obtiene lista de instancias del estudio
     */
    private function getInstances($orthancStudyId) {
        $baseUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();
        
        $ch = curl_init($baseUrl . '/studies/' . $orthancStudyId . '/instances');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error obteniendo instancias desde Orthanc: HTTP $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Obtiene información de una serie desde Orthanc
     */
    private function getSeriesInfo($seriesId) {
        $baseUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();
        
        $ch = curl_init($baseUrl . '/series/' . $seriesId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error obteniendo serie desde Orthanc: HTTP $httpCode");
        }
        
        return json_decode($response, true);
    }
}
