<?php
/**
 * Clase base abstracta para drivers de almacenamiento
 * Sistema TJSMEDICAL - Cloud Storage Module
 */

require_once __DIR__ . '/StorageDriverInterface.php';

abstract class BaseStorageDriver implements StorageDriverInterface {
    protected $config;
    protected $storagePrefix;
    
    public function __construct($config) {
        $this->config = $config;
        $storagePrefix = $config['storage_prefix'] ?? 'studies/';
        
        // Validar que storage_prefix sea un string
        if (!is_string($storagePrefix)) {
            error_log('[BASE_STORAGE_DRIVER] ERROR: storage_prefix no es string, tipo: ' . gettype($storagePrefix) . ', valor: ' . var_export($storagePrefix, true));
            $storagePrefix = 'studies/'; // Fallback seguro
        }
        
        $this->storagePrefix = $storagePrefix;
    }
    
    /**
     * Obtiene el prefijo base para un estudio
     */
    public function getStudyPrefix($studyInstanceUid) {
        return rtrim($this->storagePrefix, '/') . '/' . $studyInstanceUid . '/';
    }
    
    /**
     * Obtiene el prefijo para una serie
     */
    public function getSeriesPrefix($studyInstanceUid, $seriesInstanceUid) {
        return $this->getStudyPrefix($studyInstanceUid) . 'series/' . $seriesInstanceUid . '/';
    }
    
    /**
     * Obtiene la key completa para una instancia DICOM
     */
    public function getInstanceKey($studyInstanceUid, $seriesInstanceUid, $sopInstanceUid) {
        return $this->getSeriesPrefix($studyInstanceUid, $seriesInstanceUid) . $sopInstanceUid . '.dcm';
    }
    
    /**
     * Obtiene la key para el manifest.json de un estudio
     */
    public function getManifestKey($studyInstanceUid) {
        return $this->getStudyPrefix($studyInstanceUid) . 'manifest.json';
    }
}
