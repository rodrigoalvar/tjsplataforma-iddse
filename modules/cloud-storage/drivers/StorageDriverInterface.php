<?php
/**
 * Interfaz para drivers de almacenamiento en la nube
 * Sistema TJSMEDICAL - Cloud Storage Module
 */

interface StorageDriverInterface {
    /**
     * Sube una instancia DICOM al almacenamiento
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param string $instanceId ID de la instancia en Orthanc
     * @param string $sopInstanceUid SOPInstanceUID
     * @param string $studyInstanceUid StudyInstanceUID
     * @param string $seriesInstanceUid SeriesInstanceUID
     * @return array ['success' => bool, 'r2_key' => string, 'size' => int]
     */
    public function uploadInstance($orthancStudyId, $instanceId, $sopInstanceUid, $studyInstanceUid, $seriesInstanceUid);
    
    /**
     * Obtiene un objeto del almacenamiento
     * @param string $objectKey Key del objeto
     * @return string Contenido del objeto
     */
    public function getObject($objectKey);
    
    /**
     * Sube un objeto al almacenamiento
     * @param string $objectKey Key del objeto
     * @param string $content Contenido del objeto
     * @param string $contentType Content-Type
     * @return bool
     */
    public function putObject($objectKey, $content, $contentType = 'application/json');
    
    /**
     * Genera una URL presignada para un objeto
     * @param string $objectKey Key del objeto
     * @param int $ttl Tiempo de vida en segundos
     * @return string URL presignada
     */
    public function generatePresignedUrl($objectKey, $ttl = 600);
    
    /**
     * Obtiene el prefijo base para un estudio
     * @param string $studyInstanceUid StudyInstanceUID
     * @return string Prefijo (ej: "studies/1.2.3.4.5/")
     */
    public function getStudyPrefix($studyInstanceUid);
    
    /**
     * Obtiene el prefijo para una serie
     * @param string $studyInstanceUid StudyInstanceUID
     * @param string $seriesInstanceUid SeriesInstanceUID
     * @return string Prefijo (ej: "studies/1.2.3.4.5/series/1.2.3.4.6/")
     */
    public function getSeriesPrefix($studyInstanceUid, $seriesInstanceUid);
    
    /**
     * Obtiene la key completa para una instancia DICOM
     * @param string $studyInstanceUid StudyInstanceUID
     * @param string $seriesInstanceUid SeriesInstanceUID
     * @param string $sopInstanceUid SOPInstanceUID
     * @return string Key completa (ej: "studies/1.2.3.4.5/series/1.2.3.4.6/1.2.3.4.7.dcm")
     */
    public function getInstanceKey($studyInstanceUid, $seriesInstanceUid, $sopInstanceUid);
    
    /**
     * Obtiene la key para el manifest.json de un estudio
     * @param string $studyInstanceUid StudyInstanceUID
     * @return string Key del manifest (ej: "studies/1.2.3.4.5/manifest.json")
     */
    public function getManifestKey($studyInstanceUid);
    
    /**
     * Verifica la conexión con el almacenamiento
     * @return bool
     */
    public function testConnection();
}
