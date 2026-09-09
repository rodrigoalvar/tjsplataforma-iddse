<?php
/**
 * OrthancPacsSender - Clase reutilizable para enviar archivos PDF como objetos DICOM Encapsulated PDF a Orthanc PACS
 * 
 * Esta clase permite enviar archivos PDF como objetos DICOM (SOP Class: Encapsulated PDF)
 * a un servidor Orthanc PACS mediante su REST API.
 * 
 * Características:
 * - Conversión de PDF a DICOM Encapsulated PDF
 * - Envío directo a Orthanc mediante REST API
 * - Verificación de duplicados antes de enviar
 * - Generación automática de tags DICOM
 * - Manejo de errores y reintentos
 * - Reutilizable en cualquier proyecto PHP
 * 
 * @package    PORTAL_ESTUDIOS
 * @subpackage API
 * @author     Sistema TJSMEDICAL
 * @version    1.0.0
 * @license    GPL-3.0+
 */

require_once __DIR__ . '/config/orthanc_config.php';

class OrthancPacsSender {
    
    /** @var string URL base del servidor Orthanc */
    private $orthancUrl;
    
    /** @var array Credenciales de autenticación (username, password) */
    private $credentials;
    
    /** @var int Timeout para peticiones HTTP (segundos) */
    private $timeout;
    
    /** @var int Número máximo de reintentos */
    private $maxRetries;
    
    /** @var float Base del backoff exponencial para reintentos */
    private $backoffBase;
    
    /** @var float Tamaño máximo de archivo permitido (MB) */
    private $maxFileSizeMB;
    
    /** @var string SOP Class UID para Encapsulated PDF */
    const SOPCLASS_PDF = '1.2.840.10008.5.1.4.1.1.104.1';
    
    /** @var string SOP Class UID para Secondary Capture (imágenes) */
    const SOPCLASS_SECONDARY_CAPTURE = '1.2.840.10008.5.1.4.1.1.7';
    
    /**
     * Constructor
     * 
     * @param array|null $config Configuración personalizada (opcional)
     *                          - orthanc_url: URL del servidor Orthanc
     *                          - username: Usuario de Orthanc
     *                          - password: Contraseña de Orthanc
     *                          - timeout: Timeout para peticiones (segundos)
     *                          - max_retries: Número máximo de reintentos
     *                          - backoff_base: Base del backoff exponencial
     *                          - max_file_size_mb: Tamaño máximo de archivo (MB)
     */
    public function __construct($config = null) {
        // Usar configuración por defecto de OrthancConfig o configuración personalizada
        if ($config && isset($config['orthanc_url'])) {
            $this->orthancUrl = rtrim($config['orthanc_url'], '/');
            $this->credentials = [
                'username' => $config['username'] ?? 'orthanc',
                'password' => $config['password'] ?? 'orthanc'
            ];
        } else {
            $this->orthancUrl = OrthancConfig::getServerUrl();
            $this->credentials = OrthancConfig::getCredentials();
        }
        
        $orthancConfig = OrthancConfig::getConfig();
        $this->timeout = $config['timeout'] ?? ($orthancConfig['api']['timeout'] ?? 60);
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->backoffBase = $config['backoff_base'] ?? 1.5;
        $this->maxFileSizeMB = $config['max_file_size_mb'] ?? 50;
    }
    
    /**
     * Envía un archivo PDF como objeto DICOM Encapsulated PDF a Orthanc
     * 
     * @param string $pdfPath Ruta al archivo PDF a enviar
     * @param array $dicomTags Tags DICOM para el objeto
     * @param string|null $parentStudyInstanceUID StudyInstanceUID del estudio destino (Parent)
     *                        Campos requeridos:
     *                        - PatientName: Nombre del paciente (formato: "NOMBRE APELLIDO")
     *                        - StudyDate: Fecha del estudio (formato: YYYYMMDD)
     *                        - Modality: Modalidad del estudio (ej: "OT", "CT", "MR")
     *                        Campos opcionales:
     *                        - PatientID: ID del paciente
     *                        - AccessionNumber: Número de acceso
     *                        - StudyInstanceUID: UID único del estudio
     *                        - StudyDescription: Descripción del estudio
     *                        - InstitutionName: Nombre de la institución
     *                        - ReferringPhysicianName: Nombre del médico solicitante
     * 
     * @return array Resultado de la operación:
     *              [
     *                  'success' => true/false,
     *                  'instance_id' => ID del objeto DICOM creado en Orthanc,
     *                  'study_id' => ID del estudio en Orthanc,
     *                  'message' => Mensaje descriptivo,
     *                  'error' => Mensaje de error (si success = false)
     *              ]
     * 
     * @throws Exception Si el archivo no existe o es demasiado grande
     */
    public function sendPdfAsDicom($pdfPath, array $dicomTags, $parentStudyInstanceUID = null) {
        // Validar que el archivo existe
        if (!file_exists($pdfPath)) {
            throw new Exception("El archivo PDF no existe: $pdfPath");
        }
        
        // Validar tamaño del archivo
        $fileSizeMB = filesize($pdfPath) / (1024 * 1024);
        if ($fileSizeMB > $this->maxFileSizeMB) {
            throw new Exception("El archivo es demasiado grande: {$fileSizeMB}MB > {$this->maxFileSizeMB}MB");
        }
        
        // Validar tags DICOM requeridas
        $this->validateDicomTags($dicomTags);
        
        // Leer contenido del PDF
        $pdfContent = file_get_contents($pdfPath);
        if ($pdfContent === false) {
            throw new Exception("No se pudo leer el archivo PDF: $pdfPath");
        }
        
        // Convertir PDF a base64
        $pdfBase64 = base64_encode($pdfContent);
        
        // Agregar SOP Class UID para Encapsulated PDF
        $dicomTags['SOPClassUID'] = self::SOPCLASS_PDF;
        
        // Si se indicó Parent como StudyInstanceUID, resolver Orthanc Study ID
        $parentOrthancStudyId = null;
        if (!empty($parentStudyInstanceUID)) {
            try {
                $resolved = $this->findStudyByStudyInstanceUID($parentStudyInstanceUID);
                if ($resolved['found']) {
                    $parentOrthancStudyId = $resolved['study_id'];
                    error_log('[ORTHANC][PARENT_RESOLVED] StudyInstanceUID ' . $parentStudyInstanceUID . ' -> OrthancStudyID ' . $parentOrthancStudyId);
                } else {
                    error_log('[ORTHANC][PARENT_NOT_FOUND] No se encontró Study por StudyInstanceUID: ' . $parentStudyInstanceUID . ' (se creará estudio nuevo)');
                }
            } catch (Exception $e) {
                error_log('[ORTHANC][PARENT_RESOLVE_ERROR] ' . $e->getMessage());
            }
        }

        // IMPORTANTE: Si hay Parent (estudio existente), Orthanc hereda automáticamente
        // los tags del nivel Study y Patient. Solo debemos enviar tags de Serie/Instancia.
        // Enviar tags heredados causa "Trying to override a value inherited from a parent module" (2020)
        if (!empty($parentOrthancStudyId)) {
            // Tags heredados del Study/Patient que NO debemos enviar cuando hay Parent:
            $inheritedTags = [
                'StudyInstanceUID', 'SeriesInstanceUID',
                'PatientID', 'PatientName', 'PatientBirthDate', 'PatientSex',
                'StudyDate', 'StudyTime', 'StudyDescription',
                'AccessionNumber', 'ReferringPhysicianName', 'InstitutionName'
            ];
            foreach ($inheritedTags as $tag) {
                if (isset($dicomTags[$tag])) {
                    unset($dicomTags[$tag]);
                }
            }
            error_log('[ORTHANC][TAGS_FILTERED] Tags heredados eliminados (Parent presente)');
        } else {
            // Sin Parent, solo eliminar los UIDs que Orthanc genera automáticamente
            if (isset($dicomTags['SeriesInstanceUID'])) {
                unset($dicomTags['SeriesInstanceUID']);
            }
            if (isset($dicomTags['StudyInstanceUID'])) {
                unset($dicomTags['StudyInstanceUID']);
            }
        }

        // Crear payload para Orthanc
        $payload = [
            'Tags' => $dicomTags,
            'Content' => "data:application/pdf;base64,{$pdfBase64}"
        ];
        // Adjuntar a estudio existente si se resolvió Orthanc Study ID
        if (!empty($parentOrthancStudyId)) {
            $payload['Parent'] = $parentOrthancStudyId;
        }
        
        // Calcular timeout proporcional al tamaño del archivo
        $requestTimeout = max($this->timeout, 15.0 + ($fileSizeMB * 1.5));
        
        // Enviar a Orthanc con reintentos
        $response = $this->makeRequestWithRetry(
            '/tools/create-dicom',
            'POST',
            $payload,
            $requestTimeout
        );
        
        if ($response['success']) {
            $instanceId = $response['data']['ID'] ?? null;
            $studyId = $response['data']['ParentStudy'] ?? null;
            $seriesId = $response['data']['ParentSeries'] ?? null;
            
            // Si no se obtuvo SeriesID de la respuesta inicial, obtenerlo desde la instancia
            if (empty($seriesId) && !empty($instanceId)) {
                try {
                    // Hacer petición adicional para obtener detalles de la instancia
                    $instanceDetails = $this->makeRequestWithRetry(
                        '/instances/' . urlencode($instanceId),
                        'GET',
                        null,
                        10
                    );
                    
                    if ($instanceDetails['success'] && isset($instanceDetails['data']['ParentSeries'])) {
                        $seriesId = $instanceDetails['data']['ParentSeries'];
                        error_log('[ORTHANC][SERIES_ID_RESOLVED] SeriesID obtenido desde detalles de instancia: ' . $seriesId);
                        // También obtener study_id si no estaba disponible
                        if (empty($studyId) && isset($instanceDetails['data']['ParentStudy'])) {
                            $studyId = $instanceDetails['data']['ParentStudy'];
                        }
                    }
                } catch (Exception $e) {
                    error_log('[ORTHANC][SERIES_ID_RESOLVE_ERROR] No se pudo obtener SeriesID desde instancia: ' . $e->getMessage());
                    // Continuar sin SeriesID (no crítico)
                }
            }
            
            // Paso 3: Obtener StudyInstanceUID del estudio para determinar SeriesNumber
            $studyInstanceUIDForSeriesNumber = null;
            if (!empty($studyId)) {
                try {
                    error_log('[ORTHANC][PDF_SERIES_NUMBER] Obteniendo StudyInstanceUID desde study_id: ' . $studyId);
                    $studyDetails = $this->makeRequestWithRetry(
                        '/studies/' . urlencode($studyId),
                        'GET',
                        null,
                        10
                    );
                    
                    if ($studyDetails['success'] && isset($studyDetails['data']['MainDicomTags']['StudyInstanceUID'])) {
                        $studyInstanceUIDForSeriesNumber = $studyDetails['data']['MainDicomTags']['StudyInstanceUID'];
                        error_log('[ORTHANC][PDF_SERIES_NUMBER] StudyInstanceUID obtenido: ' . $studyInstanceUIDForSeriesNumber);
                    } elseif (!empty($parentStudyInstanceUID)) {
                        // Fallback: usar el parentStudyInstanceUID que se pasó como parámetro
                        $studyInstanceUIDForSeriesNumber = $parentStudyInstanceUID;
                        error_log('[ORTHANC][PDF_SERIES_NUMBER] Usando parentStudyInstanceUID como fallback: ' . $studyInstanceUIDForSeriesNumber);
                    }
                } catch (Exception $e) {
                    error_log('[ORTHANC][PDF_SERIES_NUMBER] Error obteniendo StudyInstanceUID: ' . $e->getMessage());
                    // Fallback: usar parentStudyInstanceUID si está disponible
                    if (!empty($parentStudyInstanceUID)) {
                        $studyInstanceUIDForSeriesNumber = $parentStudyInstanceUID;
                        error_log('[ORTHANC][PDF_SERIES_NUMBER] Usando parentStudyInstanceUID como fallback: ' . $studyInstanceUIDForSeriesNumber);
                    }
                }
            } elseif (!empty($parentStudyInstanceUID)) {
                // Si no tenemos study_id pero tenemos parentStudyInstanceUID, usarlo directamente
                $studyInstanceUIDForSeriesNumber = $parentStudyInstanceUID;
                error_log('[ORTHANC][PDF_SERIES_NUMBER] Usando parentStudyInstanceUID directamente: ' . $studyInstanceUIDForSeriesNumber);
            }
            
            // Paso 4: Determinar el siguiente SeriesNumber y actualizar la instancia
            if (!empty($studyInstanceUIDForSeriesNumber) && !empty($instanceId)) {
                try {
                    error_log('[ORTHANC][PDF_SERIES_NUMBER] Determinando SeriesNumber para estudio: ' . $studyInstanceUIDForSeriesNumber);
                    $nextSeriesNumber = $this->getNextSeriesNumberForStudy($studyInstanceUIDForSeriesNumber);
                    error_log('[ORTHANC][PDF_SERIES_NUMBER] SeriesNumber asignado al PDF: ' . $nextSeriesNumber);
                    
                    // Paso 5: Actualizar el SeriesNumber de la instancia usando PATCH
                    $updateResult = $this->updateInstanceTag($instanceId, '0020,0011', (string)$nextSeriesNumber);
                    
                    if ($updateResult['success']) {
                        error_log('[ORTHANC][PDF_SERIES_NUMBER] ✅ SeriesNumber actualizado exitosamente en instancia: ' . $instanceId);
                    } else {
                        error_log('[ORTHANC][PDF_SERIES_NUMBER] ⚠️ Advertencia: No se pudo actualizar SeriesNumber: ' . ($updateResult['error'] ?? 'Error desconocido'));
                        // No es crítico, continuar con el flujo
                    }
                } catch (Exception $e) {
                    error_log('[ORTHANC][PDF_SERIES_NUMBER] ❌ Error actualizando SeriesNumber: ' . $e->getMessage());
                    // No es crítico, continuar con el flujo
                }
            } else {
                error_log('[ORTHANC][PDF_SERIES_NUMBER] ⚠️ No se pudo determinar StudyInstanceUID o instance_id, omitiendo actualización de SeriesNumber');
            }
            
            return [
                'success' => true,
                'instance_id' => $instanceId,
                'study_id' => $studyId,
                'series_id' => $seriesId, // SeriesID según flujo propuesto
                'data' => $response['data'] ?? [], // Incluir datos completos para acceso a todos los campos
                'message' => "PDF enviado exitosamente a Orthanc",
                'file_size_mb' => round($fileSizeMB, 2)
            ];
        } else {
            return [
                'success' => false,
                'error' => $response['error'] ?? 'Error desconocido al enviar a Orthanc',
                'message' => "No se pudo enviar el PDF a Orthanc"
            ];
        }
    }
    
    /**
     * Envía una imagen como objeto DICOM Secondary Capture a Orthanc
     * 
     * @param string $imagePath Ruta al archivo de imagen (PNG, JPG)
     * @param array $dicomTags Tags DICOM para el objeto
     * @param string|null $parentStudyInstanceUID StudyInstanceUID del estudio destino (Parent)
     * 
     * @return array Resultado de la operación (mismo formato que sendPdfAsDicom)
     * 
     * @throws Exception Si el archivo no existe o es demasiado grande
     */
    public function sendImageAsDicom($pdfPath, array $dicomTags, $parentStudyInstanceUID = null) {
        // NUEVO FLUJO: PDF -> JPEGs múltiples -> DICOMs -> Orthanc
        // Validar que el archivo PDF existe
        if (!file_exists($pdfPath)) {
            throw new Exception("El archivo PDF no existe: $pdfPath");
        }
        
        // Validar tamaño del archivo
        $fileSizeMB = filesize($pdfPath) / (1024 * 1024);
        if ($fileSizeMB > $this->maxFileSizeMB) {
            throw new Exception("El archivo es demasiado grande: {$fileSizeMB}MB > {$this->maxFileSizeMB}MB");
        }
        
        // Validar tags DICOM requeridas
        $this->validateDicomTags($dicomTags);
        
        error_log('[ORTHANC][SEND_IMAGE_MULTI] ===== INICIANDO FLUJO MULTIPÁGINA =====');
        error_log('[ORTHANC][SEND_IMAGE_MULTI] PDF: ' . $pdfPath);
        
        // Obtener StudyInstanceUID para asociar todas las imágenes
        $studyInstanceUID = $dicomTags['StudyInstanceUID'] ?? null;
        if (empty($studyInstanceUID) && !empty($parentStudyInstanceUID)) {
            $studyInstanceUID = $parentStudyInstanceUID;
        }
        
        if (empty($studyInstanceUID)) {
            throw new Exception("StudyInstanceUID es requerido para enviar imágenes multipágina");
        }
        
        error_log('[ORTHANC][SEND_IMAGE_MULTI] StudyInstanceUID: ' . $studyInstanceUID);
        
        // Paso 0: Consultar Orthanc para obtener el SeriesNumber más alto del estudio
        // Esto permite posicionar el informe al final del estudio
        $nextSeriesNumber = $this->getNextSeriesNumberForStudy($studyInstanceUID);
        error_log('[ORTHANC][SEND_IMAGE_MULTI] SeriesNumber asignado al informe: ' . $nextSeriesNumber);
        
        // Paso 1: Convertir PDF a JPEGs múltiples (una por página) usando pdftoppm -jpeg
        $jpgFiles = $this->convertPdfToJpgs($pdfPath);
        if (empty($jpgFiles)) {
            throw new Exception("No se pudieron generar imágenes JPEG desde el PDF");
        }
        
        error_log('[ORTHANC][SEND_IMAGE_MULTI] Imágenes JPEG generadas: ' . count($jpgFiles));
        
        // Verificar si img2dcm está disponible para convertir localmente
        // MÉTODO REQUERIDO: pdftoppm + img2dcm (sin fallback)
        error_log('[ORTHANC][SEND_IMAGE_MULTI] ===== VERIFICANDO DISPONIBILIDAD DE img2dcm =====');
        $useImg2dcm = $this->isImg2dcmAvailable();
        error_log('[ORTHANC][SEND_IMAGE_MULTI] img2dcm disponible: ' . ($useImg2dcm ? 'SÍ ✅' : 'NO ❌'));
        
        // REQUERIR img2dcm - no usar fallback
        if (!$useImg2dcm) {
            throw new Exception("img2dcm no está disponible en el sistema. Se requiere img2dcm para convertir PNG a DICOM. El método de fallback está desactivado temporalmente.");
        }
        
        $instanceIds = [];
        $seriesIds = [];
        $studyIds = [];
        $dicomFiles = [];
        $actualMethodUsed = 'img2dcm_local'; // Siempre usar img2dcm localmente
        
        // CRÍTICO: Generar un SeriesInstanceUID único para TODAS las imágenes de este informe
        // Todas las imágenes deben tener el mismo SeriesInstanceUID para estar en la misma serie
        $seriesInstanceUID = $dicomTags['SeriesInstanceUID'] ?? self::generateUID();
        error_log('[ORTHANC][SEND_IMAGE_MULTI] SeriesInstanceUID (común para todas las imágenes): ' . $seriesInstanceUID);
        
        // Usar método pdftoppm + img2dcm (REQUERIDO)
        if ($useImg2dcm) {
            // Método 1: Convertir PNG → DICOM localmente con img2dcm, luego subir
            error_log('[ORTHANC][SEND_IMAGE_MULTI] Usando img2dcm para conversión local');
            
            // Paso 2: Convertir cada JPEG a DICOM usando img2dcm
            $conversionErrors = [];
            
            foreach ($jpgFiles as $index => $jpgFile) {
                try {
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] ===== CONVIRTIENDO PÁGINA ' . ($index + 1) . ' =====');
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] JPEG: ' . basename($jpgFile));
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] StudyInstanceUID: ' . $studyInstanceUID);
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] JPEG existe: ' . (file_exists($jpgFile) ? 'SÍ' : 'NO'));
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] JPEG tamaño: ' . (file_exists($jpgFile) ? filesize($jpgFile) . ' bytes' : 'N/A'));
                    
                    // Usar SeriesNumber calculado y SeriesDescription "Informe de Estudio"
                    $dicomFile = $this->convertJpgToDicom($jpgFile, $dicomTags, $studyInstanceUID, $seriesInstanceUID, $index + 1, $nextSeriesNumber);
                    
                    if ($dicomFile && file_exists($dicomFile)) {
                        $dicomFiles[] = $dicomFile;
                        $dicomSize = filesize($dicomFile);
                        error_log('[ORTHANC][SEND_IMAGE_MULTI] ✅ DICOM generado exitosamente');
                        error_log('[ORTHANC][SEND_IMAGE_MULTI] DICOM: ' . basename($dicomFile));
                        error_log('[ORTHANC][SEND_IMAGE_MULTI] DICOM tamaño: ' . $dicomSize . ' bytes');
                        error_log('[ORTHANC][SEND_IMAGE_MULTI] ===== PÁGINA ' . ($index + 1) . ' CONVERTIDA =====');
                    } else {
                        $errorMsg = "DICOM generado pero archivo no existe: " . ($dicomFile ?? 'NULL');
                        error_log('[ORTHANC][SEND_IMAGE_MULTI] ❌ ERROR: ' . $errorMsg);
                        error_log('[ORTHANC][SEND_IMAGE_MULTI] Ruta esperada: ' . ($dicomFile ?? 'NULL'));
                        if ($dicomFile) {
                            error_log('[ORTHANC][SEND_IMAGE_MULTI] Directorio existe: ' . (is_dir(dirname($dicomFile)) ? 'SÍ' : 'NO'));
                            error_log('[ORTHANC][SEND_IMAGE_MULTI] Directorio escribible: ' . (is_writable(dirname($dicomFile)) ? 'SÍ' : 'NO'));
                        }
                        $conversionErrors[] = $errorMsg;
                    }
                } catch (Exception $e) {
                    $errorMsg = "Error convirtiendo JPEG a DICOM (página " . ($index + 1) . "): " . $e->getMessage();
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] ❌ EXCEPCIÓN: ' . $errorMsg);
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] Tipo de excepción: ' . get_class($e));
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] Stack trace: ' . $e->getTraceAsString());
                    $conversionErrors[] = $errorMsg;
                    // Continuar con las siguientes imágenes
                }
            }
            
            // Si se generaron algunos DICOMs, continuar con el método 1
            if (!empty($dicomFiles)) {
                error_log('[ORTHANC][SEND_IMAGE_MULTI] Archivos DICOM generados: ' . count($dicomFiles) . ' de ' . count($jpgFiles));
                
                // Paso 3: Subir cada DICOM a Orthanc usando POST /instances
                foreach ($dicomFiles as $dicomFile) {
                    try {
                        $uploadResult = $this->uploadDicomInstanceFromPath($dicomFile);
                        if ($uploadResult['success']) {
                            $instanceIds[] = $uploadResult['instance_id'];
                            $seriesIds[] = $uploadResult['series_id'] ?? null;
                            $studyIds[] = $uploadResult['study_id'] ?? null;
                            error_log('[ORTHANC][SEND_IMAGE_MULTI] Instancia subida: ' . $uploadResult['instance_id']);
                        }
                    } catch (Exception $e) {
                        error_log('[ORTHANC][SEND_IMAGE_MULTI] Error subiendo DICOM: ' . $e->getMessage());
                        // Continuar con los siguientes archivos
                    }
                }
                
            }
            
            // Si img2dcm falló completamente (no se generó ningún DICOM), lanzar excepción
            // FALBACK DESACTIVADO: No usar API de Orthanc directamente
            if (empty($dicomFiles) || empty($instanceIds)) {
                $errorDetails = !empty($conversionErrors) ? "\nErrores:\n- " . implode("\n- ", $conversionErrors) : "";
                if (empty($dicomFiles)) {
                    $errorMsg = "img2dcm falló completamente. JPEGs generados: " . count($jpgFiles) . $errorDetails;
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] ❌ ' . $errorMsg);
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] ⚠️ Fallback desactivado - se requiere que img2dcm funcione correctamente');
                    throw new Exception("Error en conversión DICOM: " . $errorMsg);
                } else {
                    $errorMsg = "img2dcm generó DICOMs pero no se pudieron subir. Instancias subidas: 0";
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] ❌ ' . $errorMsg);
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] ⚠️ Fallback desactivado - se requiere que img2dcm funcione correctamente');
                    throw new Exception("Error subiendo DICOMs: " . $errorMsg);
                }
            } else {
                // Éxito con img2dcm, continuar con el flujo
                error_log('[ORTHANC][SEND_IMAGE_MULTI] ✅ Método img2dcm exitoso: ' . count($instanceIds) . ' instancias subidas');
                $actualMethodUsed = 'img2dcm_local'; // Método usado exitosamente
            }
        }
        
        if (empty($instanceIds)) {
            throw new Exception("No se pudieron subir las imágenes a Orthanc");
        }
        
        // Paso 4: Obtener el SeriesID principal (de la primera instancia)
        $primarySeriesId = null;
        $primaryStudyId = null;
        $primaryInstanceId = $instanceIds[0];
        
        if (!empty($primaryInstanceId)) {
            try {
                $instanceDetails = $this->makeRequestWithRetry(
                    '/instances/' . urlencode($primaryInstanceId),
                    'GET',
                    null,
                    10
                );
                
                if ($instanceDetails['success'] && isset($instanceDetails['data']['ParentSeries'])) {
                    $primarySeriesId = $instanceDetails['data']['ParentSeries'];
                    $primaryStudyId = $instanceDetails['data']['ParentStudy'] ?? null;
                    error_log('[ORTHANC][SEND_IMAGE_MULTI] SeriesID principal obtenido: ' . $primarySeriesId);
                }
            } catch (Exception $e) {
                error_log('[ORTHANC][SEND_IMAGE_MULTI] Error obteniendo detalles de instancia: ' . $e->getMessage());
            }
        }
        
        // Limpiar archivos temporales (los JPEGs se limpian de todas formas)
        $filesToClean = $jpgFiles; // Siempre limpiar JPEGs
        if (!empty($dicomFiles)) {
            $filesToClean = array_merge($filesToClean, $dicomFiles); // Agregar DICOMs si existen
        }
        $this->cleanupTempFiles($filesToClean);
        
        // Determinar método usado para conversión DICOM
        // SOLO usar img2dcm_local (fallback desactivado)
        $conversionMethod = 'img2dcm_local';
        
        // Preparar respuesta final
        error_log('[ORTHANC][SEND_IMAGE_MULTI] ===== FLUJO COMPLETADO =====');
        error_log('[ORTHANC][SEND_IMAGE_MULTI] Método usado: ' . $conversionMethod);
        error_log('[ORTHANC][SEND_IMAGE_MULTI] Instancias subidas: ' . count($instanceIds));
        error_log('[ORTHANC][SEND_IMAGE_MULTI] SeriesID principal: ' . ($primarySeriesId ?? 'NULL'));
        error_log('[ORTHANC][SEND_IMAGE_MULTI] StudyID principal: ' . ($primaryStudyId ?? 'NULL'));
        
        return [
            'success' => true,
            'instance_id' => $primaryInstanceId, // Primera instancia como referencia
            'study_id' => $primaryStudyId,
            'series_id' => $primarySeriesId, // SeriesID principal para gestión
            'data' => [
                'instance_ids' => $instanceIds,
                'series_ids' => array_filter($seriesIds),
                'study_ids' => array_filter($studyIds),
                'pages_count' => count($instanceIds),
                'dicom_files_count' => count($dicomFiles),
                'conversion_method' => $conversionMethod, // Método usado para conversión
                'conversion_method_name' => $conversionMethod === 'img2dcm_local' 
                    ? 'img2dcm (conversión local)' 
                    : 'Orthanc API Directa (conversión en servidor)'
            ],
            'message' => "Imagenes multipágina enviadas exitosamente a Orthanc (" . count($instanceIds) . " páginas)",
            'file_size_mb' => round($fileSizeMB, 2),
            'pages_count' => count($instanceIds),
            'conversion_method' => $conversionMethod // Para compatibilidad
        ];
    }
    
    /**
     * Paso 1: Convertir PDF a múltiples JPEGs (una por página)
     * 
     * Usa pdftoppm -jpeg para generar JPEGs directamente desde el PDF
     * 
     * @param string $pdfPath Ruta al archivo PDF
     * @return array Array de rutas a archivos JPEG generados
     * @throws Exception Si pdftoppm no está disponible o falla la conversión
     */
    private function convertPdfToJpgs($pdfPath) {
        error_log('[ORTHANC][CONVERT_PDF_JPG] Iniciando conversión PDF a JPEGs múltiples');
        
        // Crear directorio temporal para JPEGs
        $tempDir = $this->getTempJpgDirectory();
        if (!is_dir($tempDir)) {
            if (!@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
                throw new Exception('No se pudo crear el directorio temporal para JPEGs: ' . $tempDir);
            }
        }
        
        // Usar pdftoppm -jpeg para convertir PDF a JPEGs directamente
        if (function_exists('exec')) {
            try {
                $jpgFiles = $this->convertPdfToJpgsWithPdfToPpm($pdfPath, $tempDir);
                if (!empty($jpgFiles)) {
                    error_log('[ORTHANC][CONVERT_PDF_JPG] ✅ Conversión exitosa con pdftoppm -jpeg');
                    return $jpgFiles;
                }
            } catch (Exception $e) {
                error_log('[ORTHANC][CONVERT_PDF_JPG] ❌ Error con pdftoppm: ' . $e->getMessage());
                throw new Exception("No se pudo convertir PDF a JPEGs con pdftoppm: " . $e->getMessage());
            }
        }
        
        throw new Exception("pdftoppm no está disponible. Se requiere pdftoppm para convertir PDF a JPEGs.");
    }
    
    /**
     * Convertir PDF a JPEGs usando pdftoppm -jpeg
     * 
     * Comando: pdftoppm -jpeg informeentrada.pdf informesalida
     * Esto genera archivos JPEG numerados por cada página
     * 
     * @param string $pdfPath Ruta al archivo PDF
     * @param string $tempDir Directorio temporal para guardar JPEGs
     * @return array Array de rutas a archivos JPEG generados
     * @throws Exception Si pdftoppm falla
     */
    private function convertPdfToJpgsWithPdfToPpm($pdfPath, $tempDir) {
        $outputPrefix = $tempDir . DIRECTORY_SEPARATOR . 'pagina';
        $cmd = sprintf(
            'pdftoppm -jpeg -r 150 %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($outputPrefix)
        );
        
        error_log('[ORTHANC][CONVERT_PDF_JPG][PDFTOPM] Ejecutando: ' . $cmd);
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);
            throw new Exception("pdftoppm error: " . $errorMsg);
        }
        
        // Buscar archivos JPEG generados (pdftoppm genera pagina-1.jpg, pagina-2.jpg, etc.)
        $jpgFiles = glob($outputPrefix . '-*.jpg');
        
        if (empty($jpgFiles)) {
            throw new Exception("pdftoppm no generó archivos JPEG");
        }
        
        // Ordenar archivos por número de página
        usort($jpgFiles, function($a, $b) {
            preg_match('/-(\d+)\.jpg$/', $a, $matchA);
            preg_match('/-(\d+)\.jpg$/', $b, $matchB);
            $numA = isset($matchA[1]) ? (int)$matchA[1] : 0;
            $numB = isset($matchB[1]) ? (int)$matchB[1] : 0;
            return $numA <=> $numB;
        });
        
        error_log('[ORTHANC][CONVERT_PDF_JPG][PDFTOPM] JPEGs generados: ' . count($jpgFiles));
        
        return $jpgFiles;
    }
    
    /**
     * Verificar si img2dcm está disponible en el sistema
     * 
     * Verifica que img2dcm esté en el PATH y sea ejecutable
     * 
     * @return bool True si img2dcm está disponible
     */
    private function isImg2dcmAvailable() {
        if (!function_exists('exec')) {
            error_log('[ORTHANC][IMG2DCM_CHECK] ❌ La función exec() no está disponible');
            return false;
        }
        
        // Intentar ejecutar img2dcm --version o --help para verificar disponibilidad
        // En Windows y Linux, estos comandos deberían estar disponibles si img2dcm está en PATH
        $commands = [
            'img2dcm --version 2>&1',  // Versión (más rápido)
            'img2dcm --help 2>&1'     // Ayuda (fallback)
        ];
        
        foreach ($commands as $cmd) {
            exec($cmd, $output, $returnCode);
            
            // Si el comando devuelve 0 (éxito) o muestra salida, está disponible
            if ($returnCode === 0 || (is_array($output) && !empty($output))) {
                error_log('[ORTHANC][IMG2DCM_CHECK] ✅ img2dcm está disponible');
                error_log('[ORTHANC][IMG2DCM_CHECK] Comando: ' . $cmd);
                error_log('[ORTHANC][IMG2DCM_CHECK] Return code: ' . $returnCode);
                return true;
            }
        }
        
        error_log('[ORTHANC][IMG2DCM_CHECK] ❌ img2dcm NO está disponible');
        error_log('[ORTHANC][IMG2DCM_CHECK] Último return code: ' . $returnCode);
        
        return false;
    }
    
    /**
     * Paso 2: Convertir JPEG a DICOM usando img2dcm
     * 
     * @param string $jpgPath Ruta al archivo JPEG
     * @param array $dicomTags Tags DICOM base
     * @param string $studyInstanceUID StudyInstanceUID para asociar la imagen
     * @param string $seriesInstanceUID SeriesInstanceUID para asociar todas las imágenes de la misma serie
     * @param int $instanceNumber Número de instancia (página)
     * @param int|null $seriesNumber SeriesNumber para numerar la serie (tag 0020,0011)
     * @return string Ruta al archivo DICOM generado
     * @throws Exception Si img2dcm no está disponible o falla la conversión
     */
    private function convertJpgToDicom($jpgPath, array $dicomTags, $studyInstanceUID, $seriesInstanceUID, $instanceNumber, $seriesNumber = null) {
        error_log("[ORTHANC][CONVERT_JPG_DICOM] ===== INICIANDO CONVERSIÓN JPEG A DICOM =====");
        error_log("[ORTHANC][CONVERT_JPG_DICOM] JPEG: " . basename($jpgPath) . " (página $instanceNumber)");
        error_log("[ORTHANC][CONVERT_JPG_DICOM] JPEG existe: " . (file_exists($jpgPath) ? 'SÍ' : 'NO'));
        
        if (!file_exists($jpgPath)) {
            throw new Exception("El archivo JPEG no existe: $jpgPath");
        }
        
        if (!function_exists('exec')) {
            throw new Exception('La función exec() no está disponible. Se requiere para ejecutar img2dcm');
        }
        
        // Crear archivo DICOM temporal en el mismo directorio del JPEG
        $dcmPath = str_replace('.jpg', '.dcm', $jpgPath);
        error_log('[ORTHANC][CONVERT_JPG_DICOM] Ruta DICOM destino: ' . $dcmPath);
        
        // Construir comando img2dcm con tags DICOM usando -k (key)
        // Formato: img2dcm -k "0020,000D"=StudyInstanceUID input.jpg output.dcm
        // Tags DICOM:
        // - StudyInstanceUID: 0020,000D
        // - SeriesInstanceUID: 0020,000E
        // - InstanceNumber: 0020,0013
        // Nota: img2dcm soporta JPEG por defecto
        
        // Construir opciones -k para cada tag DICOM necesario
        $keyOptions = [];
        
        // StudyInstanceUID (tag 0020,000D)
        $keyOptions[] = sprintf('-k "0020,000D"=%s', escapeshellarg($studyInstanceUID));
        
        // SeriesInstanceUID (tag 0020,000E) - usar el mismo para todas las imágenes
        $keyOptions[] = sprintf('-k "0020,000E"=%s', escapeshellarg($seriesInstanceUID));
        
        // SeriesNumber (tag 0020,0011) - número de serie para posicionar al final del estudio
        if ($seriesNumber !== null) {
            $keyOptions[] = sprintf('-k "0020,0011"=%d', $seriesNumber);
            error_log('[ORTHANC][CONVERT_JPG_DICOM] SeriesNumber asignado: ' . $seriesNumber);
        }
        
        // InstanceNumber (tag 0020,0013) - número de página
        $keyOptions[] = sprintf('-k "0020,0013"=%d', $instanceNumber);
        
        // Agregar otros tags DICOM importantes si están disponibles
        if (isset($dicomTags['PatientID'])) {
            $keyOptions[] = sprintf('-k "0010,0020"=%s', escapeshellarg($dicomTags['PatientID']));
        }
        if (isset($dicomTags['PatientName'])) {
            $keyOptions[] = sprintf('-k "0010,0010"=%s', escapeshellarg($dicomTags['PatientName']));
        }
        if (isset($dicomTags['Modality'])) {
            $keyOptions[] = sprintf('-k "0008,0060"=%s', escapeshellarg($dicomTags['Modality']));
        }
        if (isset($dicomTags['StudyDate'])) {
            $keyOptions[] = sprintf('-k "0008,0020"=%s', escapeshellarg($dicomTags['StudyDate']));
        }
        
        // SeriesDescription: siempre usar "Informe de Estudio" para los informes
        $keyOptions[] = sprintf('-k "0008,103E"=%s', escapeshellarg('Informe de Estudio'));
        error_log('[ORTHANC][CONVERT_JPG_DICOM] SeriesDescription establecido: "Informe de Estudio"');
        
        // Construir comando completo usando el archivo JPEG directamente
        $cmd = sprintf(
            'img2dcm %s %s %s 2>&1',
            implode(' ', $keyOptions),
            escapeshellarg($jpgPath),
            escapeshellarg($dcmPath)
        );
        
        error_log('[ORTHANC][CONVERT_JPG_DICOM] SeriesInstanceUID: ' . $seriesInstanceUID);
        error_log('[ORTHANC][CONVERT_JPG_DICOM] Comando a ejecutar: ' . $cmd);
        error_log('[ORTHANC][CONVERT_JPG_DICOM] StudyInstanceUID: ' . $studyInstanceUID);
        error_log('[ORTHANC][CONVERT_JPG_DICOM] SeriesInstanceUID: ' . $seriesInstanceUID);
        
        exec($cmd, $output, $returnCode);
        
        error_log('[ORTHANC][CONVERT_JPG_DICOM] Return code: ' . $returnCode);
        error_log('[ORTHANC][CONVERT_JPG_DICOM] Output: ' . (is_array($output) ? implode("\n", $output) : 'NO OUTPUT'));
        
        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);
            error_log('[ORTHANC][CONVERT_JPG_DICOM] ❌ Error ejecutando img2dcm');
            error_log('[ORTHANC][CONVERT_JPG_DICOM] Return code: ' . $returnCode);
            error_log('[ORTHANC][CONVERT_JPG_DICOM] Error output: ' . $errorMsg);
            throw new Exception("Error convirtiendo JPEG a DICOM (código $returnCode): " . ($errorMsg ?: 'Sin mensaje de error'));
        }
        
        if (!file_exists($dcmPath)) {
            error_log('[ORTHANC][CONVERT_JPG_DICOM] ❌ img2dcm ejecutó exitosamente pero el archivo DICOM no existe');
            error_log('[ORTHANC][CONVERT_JPG_DICOM] Ruta esperada: ' . $dcmPath);
            error_log('[ORTHANC][CONVERT_JPG_DICOM] Directorio existe: ' . (is_dir(dirname($dcmPath)) ? 'SÍ' : 'NO'));
            error_log('[ORTHANC][CONVERT_JPG_DICOM] Directorio escribible: ' . (is_writable(dirname($dcmPath)) ? 'SÍ' : 'NO'));
            throw new Exception("img2dcm no generó el archivo DICOM: " . $dcmPath . " (return code: $returnCode)");
        }
        
        $fileSize = filesize($dcmPath);
        error_log('[ORTHANC][CONVERT_JPG_DICOM] ✅ DICOM generado exitosamente: ' . basename($dcmPath));
        error_log('[ORTHANC][CONVERT_JPG_DICOM] Tamaño DICOM: ' . $fileSize . ' bytes');
        error_log("[ORTHANC][CONVERT_JPG_DICOM] ===== FIN CONVERSIÓN JPEG A DICOM =====");
        
        return $dcmPath;
    }
    
    /**
     * Paso 3: Subir archivo DICOM a Orthanc usando POST /instances
     * 
     * @param string $dicomPath Ruta al archivo DICOM
     * @return array Resultado con instance_id, series_id, study_id
     * @throws Exception Si falla la subida
     */
    private function uploadDicomInstanceFromPath($dicomPath) {
        error_log('[ORTHANC][UPLOAD_DICOM] Subiendo DICOM: ' . basename($dicomPath));
        
        if (!file_exists($dicomPath)) {
            throw new Exception("El archivo DICOM no existe: $dicomPath");
        }
        
        $dicomContent = file_get_contents($dicomPath);
        if ($dicomContent === false) {
            throw new Exception("No se pudo leer el archivo DICOM: $dicomPath");
        }
        
        // Subir usando POST /instances con Content-Type: application/dicom
        // Usar cURL directamente para enviar contenido binario
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->orthancUrl . '/instances');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dicomContent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/dicom']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if (!empty($this->credentials['username']) && !empty($this->credentials['password'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->credentials['username'] . ':' . $this->credentials['password']);
        }
        
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("Error en cURL al subir DICOM: " . $curlError);
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            // La respuesta puede ser solo el ID como string, o un objeto JSON
            $instanceId = null;
            
            if (is_string($responseBody)) {
                $decoded = json_decode($responseBody, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $instanceId = $decoded['ID'] ?? $decoded ?? null;
                } else {
                    // Si no es JSON, puede ser directamente el ID como string
                    $instanceId = trim($responseBody);
                }
            }
            
            if (empty($instanceId)) {
                throw new Exception("No se pudo obtener instance_id de la respuesta de Orthanc");
            }
            
            // Obtener detalles de la instancia para obtener SeriesID y StudyID
            $seriesId = null;
            $studyId = null;
            
            try {
                $instanceDetails = $this->makeRequestWithRetry(
                    '/instances/' . urlencode($instanceId),
                    'GET',
                    null,
                    10
                );
                
                if ($instanceDetails['success']) {
                    $seriesId = $instanceDetails['data']['ParentSeries'] ?? null;
                    $studyId = $instanceDetails['data']['ParentStudy'] ?? null;
                }
            } catch (Exception $e) {
                error_log('[ORTHANC][UPLOAD_DICOM] Error obteniendo detalles de instancia: ' . $e->getMessage());
            }
            
            return [
                'success' => true,
                'instance_id' => $instanceId,
                'series_id' => $seriesId,
                'study_id' => $studyId
            ];
        } else {
            $errorMsg = "HTTP $httpCode: " . $responseBody;
            throw new Exception("Error subiendo DICOM a Orthanc: " . $errorMsg);
        }
    }
    
    /**
     * Sube contenido DICOM (archivo o ZIP) directamente a Orthanc
     * Método público para subir desde API
     * 
     * @param string $fileContent Contenido binario del archivo DICOM o ZIP
     * @param bool $isZip Si es true, el contenido es un ZIP
     * @return array Resultado con instance_id, study_id, patient_id
     * @throws Exception Si falla la subida
     */
    public function uploadDicomInstance($fileContent, $isZip = false) {
        try {
            $contentType = $isZip ? 'application/zip' : 'application/dicom';
            error_log('[ORTHANC][UPLOAD_DICOM] Subiendo ' . ($isZip ? 'ZIP' : 'DICOM') . ' (' . strlen($fileContent) . ' bytes)');
            
            // Subir usando POST /instances con Content-Type apropiado
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->orthancUrl . '/instances');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . $contentType]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutos para archivos grandes
            
            if (!empty($this->credentials['username']) && !empty($this->credentials['password'])) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $this->credentials['username'] . ':' . $this->credentials['password']);
            }
            
            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                throw new Exception("Error en cURL al subir DICOM: " . $curlError);
            }
            
            if ($httpCode >= 200 && $httpCode < 300) {
                // La respuesta puede ser solo el ID como string, o un objeto JSON
                $instanceId = null;
                $responseData = null;
                
                if (is_string($responseBody)) {
                    $decoded = json_decode($responseBody, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $responseData = $decoded;
                        // Si es un objeto simple (no array), obtener el ID directamente
                        if (!is_array($responseData) || (isset($responseData['ID']) && !isset($responseData[0]))) {
                            $instanceId = $responseData['ID'] ?? null;
                        }
                    } else {
                        // Si no es JSON, puede ser directamente el ID como string
                        $instanceId = trim($responseBody);
                    }
                }
                
                // Variables para IDs
                $seriesId = null;
                $studyId = null;
                $patientId = null;
                
                // Si es ZIP, la respuesta puede contener múltiples IDs en un array
                if ($isZip && is_array($responseData) && !empty($responseData)) {
                    error_log('[ORTHANC][UPLOAD_DICOM] Respuesta ZIP: ' . json_encode($responseData, JSON_PRETTY_PRINT));
                    
                    // ZIP retorna array de objetos, obtener el primer resultado
                    $firstResult = is_array($responseData[0]) ? $responseData[0] : $responseData;
                    
                    // Extraer IDs directamente de la respuesta si están disponibles
                    if (isset($firstResult['ID'])) {
                        $instanceId = $firstResult['ID'];
                    }
                    if (isset($firstResult['ParentStudy'])) {
                        $studyId = $firstResult['ParentStudy'];
                    }
                    if (isset($firstResult['ParentSeries'])) {
                        $seriesId = $firstResult['ParentSeries'];
                    }
                    if (isset($firstResult['ParentPatient'])) {
                        $patientId = $firstResult['ParentPatient'];
                    }
                    
                    error_log('[ORTHANC][UPLOAD_DICOM] IDs extraídos del ZIP: instance=' . ($instanceId ?? 'null') . ', study=' . ($studyId ?? 'null') . ', patient=' . ($patientId ?? 'null'));
                }
                
                // Si no se obtuvieron todos los IDs desde la respuesta del ZIP, obtenerlos consultando la instancia
                if ($instanceId && (!$studyId || !$patientId)) {
                    try {
                        $instanceDetails = $this->makeRequestWithRetry(
                            '/instances/' . urlencode($instanceId),
                            'GET',
                            null,
                            10
                        );
                        
                        if ($instanceDetails['success']) {
                            if (!$seriesId) {
                                $seriesId = $instanceDetails['data']['ParentSeries'] ?? null;
                            }
                            if (!$studyId) {
                                $studyId = $instanceDetails['data']['ParentStudy'] ?? null;
                            }
                            
                            // Obtener PatientID desde el estudio si aún no lo tenemos
                            if ($studyId && !$patientId) {
                                $studyDetails = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($studyId),
                                    'GET',
                                    null,
                                    10
                                );
                                
                                if ($studyDetails['success']) {
                                    $patientId = $studyDetails['data']['ParentPatient'] ?? null;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log('[ORTHANC][UPLOAD_DICOM] Error obteniendo detalles: ' . $e->getMessage());
                    }
                }
                
                return [
                    'success' => true,
                    'instance_id' => $instanceId,
                    'series_id' => $seriesId,
                    'study_id' => $studyId,
                    'patient_id' => $patientId
                ];
            } else {
                $errorMsg = "HTTP $httpCode";
                if (!empty($responseBody)) {
                    $decoded = json_decode($responseBody, true);
                    if ($decoded) {
                        $errorMsg .= ': ' . ($decoded['Message'] ?? $decoded['message'] ?? json_encode($decoded));
                    } else {
                        $errorMsg .= ': ' . substr($responseBody, 0, 200);
                    }
                }
                throw new Exception("Error subiendo DICOM a Orthanc: " . $errorMsg);
            }
        } catch (Exception $e) {
            error_log('[ORTHANC][UPLOAD_DICOM] ❌ Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Subir imagen PNG directamente a Orthanc usando /tools/create-dicom
     * 
     * Este método se usa como fallback cuando img2dcm no está disponible
     * 
     * @param string $pngPath Ruta al archivo PNG
     * @param array $dicomTags Tags DICOM para la imagen
     * @param string|null $parentOrthancStudyId Orthanc Study ID del estudio padre
     * @param int $instanceNumber Número de instancia (página)
     * @return array Resultado con instance_id, series_id, study_id
     * @throws Exception Si falla la subida
     */
    private function uploadImageDirectlyToOrthanc($pngPath, array $dicomTags, $parentOrthancStudyId = null, $instanceNumber = 1) {
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ===== INICIANDO SUBIDA DE IMAGEN =====');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Archivo PNG: ' . basename($pngPath) . ' (página ' . $instanceNumber . ')');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Ruta completa: ' . $pngPath);
        
        if (!file_exists($pngPath)) {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ❌ ERROR: El archivo PNG no existe');
            throw new Exception("El archivo PNG no existe: $pngPath");
        }
        
        // Verificar tamaño del archivo
        $originalSize = filesize($pngPath);
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Tamaño original: ' . $originalSize . ' bytes (' . round($originalSize / 1024, 2) . ' KB)');
        
        // Detectar tipo MIME (debería ser image/png)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $pngPath);
        finfo_close($finfo);
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Tipo MIME: ' . $mimeType);
        
        // Verificar que sea PNG
        if ($mimeType !== 'image/png') {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ⚠️ ADVERTENCIA: El archivo no es PNG, es: ' . $mimeType);
        }
        
        // Leer imagen PNG
        $imageContent = file_get_contents($pngPath);
        if ($imageContent === false) {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ❌ ERROR: No se pudo leer el archivo PNG');
            throw new Exception("No se pudo leer el archivo PNG: $pngPath");
        }
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Contenido leído: ' . strlen($imageContent) . ' bytes');
        
        // Codificar a base64
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Codificando imagen a base64...');
        $imageBase64 = base64_encode($imageContent);
        $base64Length = strlen($imageBase64);
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ✅ Base64 generado: ' . $base64Length . ' caracteres');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Tamaño base64 aprox: ' . round($base64Length * 0.75 / 1024, 2) . ' KB (tamaño binario estimado)');
        
        // Verificar que el base64 no esté vacío
        if (empty($imageBase64)) {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ❌ ERROR: Base64 está vacío');
            throw new Exception("Error: El contenido base64 está vacío");
        }
        
        // Crear payload para Orthanc según documentación oficial
        // El endpoint /tools/create-dicom requiere el campo "Content" (según documentación oficial de Orthanc)
        // Formato: "data:image/png;base64,{base64string}" para PNG
        // Formato: "data:image/jpeg;base64,{base64string}" para JPEG
        $dataUri = "data:{$mimeType};base64,{$imageBase64}";
        $payload = [
            'Tags' => $dicomTags,
            'Content' => $dataUri
        ];
        
        if (!empty($parentOrthancStudyId)) {
            $payload['Parent'] = $parentOrthancStudyId;
        }
        
        // Calcular timeout proporcional al tamaño del archivo
        $fileSizeMB = strlen($imageContent) / (1024 * 1024);
        $requestTimeout = max($this->timeout, 15.0 + ($fileSizeMB * 1.5));
        
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ===== PAYLOAD PREPARADO =====');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Tipo de dato: ' . $mimeType . ' (PNG)');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Tamaño base64: ' . $base64Length . ' caracteres');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Tamaño Data URI: ' . strlen($dataUri) . ' caracteres');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Parent: ' . ($parentOrthancStudyId ?? 'NO (se creará estudio nuevo)'));
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Tags DICOM: ' . count($dicomTags) . ' tags');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Modality: ' . ($dicomTags['Modality'] ?? 'NO DEFINIDA'));
        
        // Mostrar primeros caracteres del Data URI para verificación
        $dataUriPreview = substr($dataUri, 0, 100) . '...';
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Data URI preview: ' . $dataUriPreview);
        
        // Verificar que el Data URI tenga el formato correcto
        if (strpos($dataUri, 'data:image/png;base64,') !== 0) {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ⚠️ ADVERTENCIA: El formato del Data URI puede no ser correcto');
        } else {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] ✅ Formato del Data URI verificado correctamente');
        }
        
        // Enviar a Orthanc usando /tools/create-dicom
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Enviando a /tools/create-dicom...');
        $response = $this->makeRequestWithRetry(
            '/tools/create-dicom',
            'POST',
            $payload,
            $requestTimeout
        );
        
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Respuesta recibida:');
        error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT]   - Success: ' . ($response['success'] ? 'SÍ' : 'NO'));
        if (isset($response['data'])) {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT]   - Instance ID: ' . ($response['data']['ID'] ?? 'NO DISPONIBLE'));
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT]   - ParentStudy: ' . ($response['data']['ParentStudy'] ?? 'NO DISPONIBLE'));
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT]   - ParentSeries: ' . ($response['data']['ParentSeries'] ?? 'NO DISPONIBLE'));
        }
        if (isset($response['error'])) {
            error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT]   - Error: ' . $response['error']);
        }
        
        if ($response['success']) {
            $instanceId = $response['data']['ID'] ?? null;
            $studyId = $response['data']['ParentStudy'] ?? null;
            $seriesId = $response['data']['ParentSeries'] ?? null;
            
            // Si no se obtuvo SeriesID, obtenerlo desde la instancia
            if (empty($seriesId) && !empty($instanceId)) {
                try {
                    $instanceDetails = $this->makeRequestWithRetry(
                        '/instances/' . urlencode($instanceId),
                        'GET',
                        null,
                        10
                    );
                    
                    if ($instanceDetails['success'] && isset($instanceDetails['data']['ParentSeries'])) {
                        $seriesId = $instanceDetails['data']['ParentSeries'];
                        $studyId = $instanceDetails['data']['ParentStudy'] ?? $studyId;
                    }
                } catch (Exception $e) {
                    error_log('[ORTHANC][UPLOAD_IMAGE_DIRECT] Error obteniendo detalles: ' . $e->getMessage());
                }
            }
            
            return [
                'success' => true,
                'instance_id' => $instanceId,
                'series_id' => $seriesId,
                'study_id' => $studyId
            ];
        } else {
            throw new Exception("Error subiendo imagen a Orthanc: " . ($response['error'] ?? 'Error desconocido'));
        }
    }
    
    /**
     * Obtener directorio temporal para JPGs
     * 
     * Siempre usa uploads/temp_jpg/ para mantener consistencia en Windows y Linux
     * 
     * @return string Ruta al directorio temporal
     */
    private function getTempJpgDirectory() {
        // Determinar ruta base de uploads (usando la misma lógica que send-to-pacs.php)
        // __DIR__ es api/ cuando se ejecuta desde OrthancPacsSender.php
        // Necesitamos subir dos niveles para llegar a la raíz del proyecto
        $uploadsBase = realpath(__DIR__ . '/../../');
        if ($uploadsBase === false) {
            throw new Exception('No se pudo resolver la ruta base de uploads');
        }
        
        // Verificar que la ruta base es correcta (debe contener 'tjslosalisos' o el nombre del proyecto)
        // Si realpath devuelve /var/www en lugar de /var/www/tjslosalisos, corregir la ruta
        if (basename($uploadsBase) !== 'tjslosalisos' && is_dir($uploadsBase . DIRECTORY_SEPARATOR . 'tjslosalisos')) {
            $uploadsBase = $uploadsBase . DIRECTORY_SEPARATOR . 'tjslosalisos';
            error_log('[ORTHANC][TEMP_DIR] Ruta base corregida a: ' . $uploadsBase);
        }
        
        // Crear directorio base para temporales: uploads/temp_jpg/
        // Usa DIRECTORY_SEPARATOR para compatibilidad Windows/Linux
        $tempBaseDir = $uploadsBase . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'temp_jpg';
        
        // Crear directorio base si no existe
        if (!is_dir($tempBaseDir)) {
            if (!@mkdir($tempBaseDir, 0775, true) && !is_dir($tempBaseDir)) {
                $error = error_get_last();
                $errorMsg = $error ? $error['message'] : 'Error desconocido';
                throw new Exception('No se pudo crear el directorio base de temporales: ' . $tempBaseDir . '. Error: ' . $errorMsg);
            }
            // Intentar establecer permisos después de crear el directorio
            @chmod($tempBaseDir, 0775);
            error_log('[ORTHANC][TEMP_DIR] Directorio base creado: ' . $tempBaseDir);
        }
        
        // Verificar permisos de escritura
        if (!is_writable($tempBaseDir)) {
            $currentPerms = substr(sprintf('%o', fileperms($tempBaseDir)), -4);
            $owner = fileowner($tempBaseDir);
            $group = filegroup($tempBaseDir);
            throw new Exception('El directorio temporal no tiene permisos de escritura: ' . $tempBaseDir . 
                              '. Permisos actuales: ' . $currentPerms . 
                              '. Propietario: ' . $owner . 
                              '. Grupo: ' . $group . 
                              '. El servidor web necesita permisos de escritura en este directorio.');
        }
        
        // Crear subdirectorio único para esta conversión
        // Formato: temp_{timestamp}_{uniqid} para evitar conflictos
        $tempDir = $tempBaseDir . DIRECTORY_SEPARATOR . 'temp_' . time() . '_' . uniqid();
        
        error_log('[ORTHANC][TEMP_DIR] Usando directorio temporal: ' . $tempDir);
        
        return $tempDir;
    }
    
    /**
     * Limpiar archivos temporales (JPGs y DICOMs)
     * 
     * @param array $files Array de rutas a archivos temporales
     */
    private function cleanupTempFiles(array $files) {
        foreach ($files as $file) {
            if (file_exists($file)) {
                try {
                    @unlink($file);
                    error_log('[ORTHANC][CLEANUP] Archivo eliminado: ' . basename($file));
                } catch (Exception $e) {
                    error_log('[ORTHANC][CLEANUP] Error eliminando archivo: ' . $file . ' - ' . $e->getMessage());
                }
            }
        }
        
        // Intentar eliminar directorios temporales si están vacíos
        $dirs = [];
        foreach ($files as $file) {
            $dir = dirname($file);
            if (!in_array($dir, $dirs)) {
                $dirs[] = $dir;
            }
        }
        
        foreach ($dirs as $dir) {
            if (strpos($dir, 'temp_') !== false && is_dir($dir)) {
                try {
                    $filesInDir = glob($dir . DIRECTORY_SEPARATOR . '*');
                    if (empty($filesInDir)) {
                        @rmdir($dir);
                        error_log('[ORTHANC][CLEANUP] Directorio temporal eliminado: ' . $dir);
                    }
                } catch (Exception $e) {
                    // Ignorar errores al eliminar directorios
                }
            }
        }
    }
    
    /**
     * Convierte una imagen a formato PNG
     * 
     * @param string $imagePath Ruta al archivo de imagen
     * @return string Contenido binario de la imagen en formato PNG
     * @throws Exception Si no se puede convertir la imagen
     */
    private function convertImageToPng($imagePath) {
        // Intentar usar Imagick si está disponible
        if (class_exists('Imagick')) {
            try {
                $imagick = new \Imagick($imagePath);
                $imagick->setImageFormat('png');
                $imagick->setImageCompressionQuality(95);
                $pngContent = $imagick->getImageBlob();
                $imagick->clear();
                $imagick->destroy();
                return $pngContent;
            } catch (Exception $e) {
                error_log("Error convirtiendo con Imagick: " . $e->getMessage());
            }
        }
        
        // Fallback: usar GD
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            throw new Exception("No se pudo obtener información de la imagen");
        }
        
        $mimeType = $imageInfo['mime'];
        $image = null;
        
        switch ($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($imagePath);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($imagePath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($imagePath);
                break;
            default:
                throw new Exception("Formato de imagen no soportado: $mimeType");
        }
        
        if ($image === false) {
            throw new Exception("No se pudo cargar la imagen con GD");
        }
        
        // Convertir a PNG
        ob_start();
        imagepng($image, null, 9); // Máxima compresión
        $pngContent = ob_get_clean();
        imagedestroy($image);
        
        return $pngContent;
    }
    
    /**
     * Verifica si ya existe un estudio duplicado en Orthanc
     * 
     * @param array $searchCriteria Criterios de búsqueda:
     *                            - accession_number: Número de acceso
     *                            - patient_id: ID del paciente
     *                            - patient_name: Nombre del paciente (formato DICOM: "APELLIDO^NOMBRE")
     *                            - patient_name_natural: Nombre del paciente (formato natural: "NOMBRE APELLIDO")
     *                            - study_date: Fecha del estudio (formato: YYYYMMDD)
     * 
     * @return array Resultado:
     *              [
     *                  'exists' => true/false,
     *                  'study_id' => ID del estudio encontrado (si exists = true),
     *                  'method' => Método usado para encontrar el duplicado
     *              ]
     */
    public function checkDuplicate(array $searchCriteria) {
        $methods = [];
        
        // 1. Buscar por AccessionNumber (más confiable)
        if (!empty($searchCriteria['accession_number'])) {
            $result = $this->findStudy([
                'AccessionNumber' => $searchCriteria['accession_number']
            ]);
            if ($result['found']) {
                return [
                    'exists' => true,
                    'study_id' => $result['study_id'],
                    'method' => 'accession_number'
                ];
            }
        }
        
        // 2. Buscar por PatientID + StudyDate
        if (!empty($searchCriteria['patient_id']) && !empty($searchCriteria['study_date'])) {
            $result = $this->findStudy([
                'PatientID' => $searchCriteria['patient_id'],
                'StudyDate' => $searchCriteria['study_date']
            ]);
            if ($result['found']) {
                return [
                    'exists' => true,
                    'study_id' => $result['study_id'],
                    'method' => 'patient_id_and_date'
                ];
            }
        }
        
        // 3. Buscar por PatientName (formato DICOM) + StudyDate
        if (!empty($searchCriteria['patient_name']) && !empty($searchCriteria['study_date'])) {
            $result = $this->findStudy([
                'PatientName' => $searchCriteria['patient_name'],
                'StudyDate' => $searchCriteria['study_date']
            ]);
            if ($result['found']) {
                return [
                    'exists' => true,
                    'study_id' => $result['study_id'],
                    'method' => 'patient_name_dicom_and_date'
                ];
            }
        }
        
        // 4. Buscar por PatientName (formato natural) + StudyDate (fallback)
        if (!empty($searchCriteria['patient_name_natural']) && 
            !empty($searchCriteria['study_date']) &&
            $searchCriteria['patient_name_natural'] !== $searchCriteria['patient_name']) {
            $result = $this->findStudy([
                'PatientName' => $searchCriteria['patient_name_natural'],
                'StudyDate' => $searchCriteria['study_date']
            ]);
            if ($result['found']) {
                return [
                    'exists' => true,
                    'study_id' => $result['study_id'],
                    'method' => 'patient_name_natural_and_date'
                ];
            }
        }
        
        return [
            'exists' => false,
            'study_id' => null,
            'method' => null
        ];
    }
    
    /**
     * Busca un estudio en Orthanc usando criterios específicos
     * 
     * @param array $query Criterios de búsqueda DICOM
     * @return array ['found' => bool, 'study_id' => string|null]
     */
    private function findStudy(array $query) {
        try {
            $payload = [
                'Level' => 'Study',
                'Query' => $query
            ];
            
            $response = $this->makeRequestWithRetry(
                '/tools/find',
                'POST',
                $payload,
                30
            );
            
            if ($response['success'] && !empty($response['data'])) {
                return [
                    'found' => true,
                    'study_id' => $response['data'][0]
                ];
            }
            
            return ['found' => false, 'study_id' => null];
        } catch (Exception $e) {
            error_log("Error buscando estudio en Orthanc: " . $e->getMessage());
            return ['found' => false, 'study_id' => null];
        }
    }

    /**
     * Encuentra un estudio por StudyInstanceUID y devuelve el Orthanc Study ID
     *
     * @param string $studyInstanceUID
     * @return array ['found' => bool, 'study_id' => string|null]
     */
    /**
     * Obtener el siguiente SeriesNumber disponible para un estudio
     * 
     * Consulta todas las series del estudio y devuelve el siguiente número consecutivo
     * 
     * @param string $studyInstanceUID StudyInstanceUID del estudio
     * @return int El siguiente SeriesNumber a usar (1 si no hay series existentes)
     */
    private function getNextSeriesNumberForStudy($studyInstanceUID) {
        error_log('[ORTHANC][GET_SERIES_NUMBER] Consultando SeriesNumber para estudio: ' . $studyInstanceUID);
        
        try {
            // Primero encontrar el Orthanc Study ID por StudyInstanceUID
            $studyResult = $this->findStudyByStudyInstanceUID($studyInstanceUID);
            
            if (!$studyResult['found']) {
                error_log('[ORTHANC][GET_SERIES_NUMBER] Estudio no encontrado, usando SeriesNumber = 1');
                return 1; // Si no existe el estudio, empezar desde 1
            }
            
            $orthancStudyId = $studyResult['study_id'];
            error_log('[ORTHANC][GET_SERIES_NUMBER] Orthanc Study ID: ' . $orthancStudyId);
            
            // Consultar todas las series del estudio
            $response = $this->makeRequestWithRetry(
                '/studies/' . urlencode($orthancStudyId) . '/series',
                'GET',
                null,
                30
            );
            
            if (!$response['success']) {
                error_log('[ORTHANC][GET_SERIES_NUMBER] Error consultando series, usando SeriesNumber = 1');
                return 1; // En caso de error, usar 1
            }
            
            $seriesData = $response['data'] ?? [];
            
            // Validar que seriesData sea un array
            if (!is_array($seriesData)) {
                error_log('[ORTHANC][GET_SERIES_NUMBER] Respuesta no es un array, usando SeriesNumber = 1');
                error_log('[ORTHANC][GET_SERIES_NUMBER] Tipo de respuesta: ' . gettype($seriesData));
                error_log('[ORTHANC][GET_SERIES_NUMBER] Contenido de respuesta: ' . json_encode($seriesData));
                return 1;
            }
            
            // La API de Orthanc /studies/{id}/series devuelve un array simple de IDs
            // Extraer solo los IDs si es necesario
            $seriesIds = [];
            foreach ($seriesData as $item) {
                // Si el item es un string/number directamente, usar ese
                if (is_string($item) || is_numeric($item)) {
                    $seriesIds[] = $item;
                }
                // Si el item es un array u objeto, intentar extraer el ID
                elseif (is_array($item)) {
                    // Buscar en diferentes posibles estructuras
                    if (isset($item['ID'])) {
                        $seriesIds[] = $item['ID'];
                    } elseif (isset($item[0])) {
                        $seriesIds[] = $item[0];
                    } else {
                        // Intentar usar el primer valor si es un array asociativo
                        $firstValue = reset($item);
                        if (is_string($firstValue) || is_numeric($firstValue)) {
                            $seriesIds[] = $firstValue;
                        }
                    }
                }
            }
            
            error_log('[ORTHANC][GET_SERIES_NUMBER] Series encontradas: ' . count($seriesIds));
            error_log('[ORTHANC][GET_SERIES_NUMBER] Series IDs: ' . json_encode($seriesIds));
            
            if (empty($seriesIds)) {
                error_log('[ORTHANC][GET_SERIES_NUMBER] No hay series existentes, usando SeriesNumber = 1');
                return 1; // No hay series, empezar desde 1
            }
            
            // Consultar cada serie para obtener su SeriesNumber
            $seriesNumbers = [];
            foreach ($seriesIds as $seriesId) {
                try {
                    // Validar que seriesId sea un string
                    if (!is_string($seriesId) && !is_numeric($seriesId)) {
                        error_log('[ORTHANC][GET_SERIES_NUMBER] Serie ID no es válido (tipo: ' . gettype($seriesId) . '), saltando');
                        continue;
                    }
                    
                    // Convertir a string si es numérico
                    $seriesIdStr = (string)$seriesId;
                    
                    $seriesResponse = $this->makeRequestWithRetry(
                        '/series/' . urlencode($seriesIdStr),
                        'GET',
                        null,
                        10
                    );
                    
                    if ($seriesResponse['success'] && isset($seriesResponse['data']['MainDicomTags'])) {
                        $tags = $seriesResponse['data']['MainDicomTags'];
                        if (isset($tags['SeriesNumber']) && !empty($tags['SeriesNumber'])) {
                            $seriesNum = (int)$tags['SeriesNumber'];
                            $seriesNumbers[] = $seriesNum;
                            error_log('[ORTHANC][GET_SERIES_NUMBER] Serie ' . $seriesIdStr . ' tiene SeriesNumber: ' . $seriesNum);
                        }
                    }
                } catch (Exception $e) {
                    $seriesIdForLog = isset($seriesIdStr) ? $seriesIdStr : (is_string($seriesId) || is_numeric($seriesId) ? (string)$seriesId : 'INVÁLIDO');
                    error_log('[ORTHANC][GET_SERIES_NUMBER] Error consultando serie ' . $seriesIdForLog . ': ' . $e->getMessage());
                    // Continuar con las siguientes series
                }
            }
            
            if (empty($seriesNumbers)) {
                error_log('[ORTHANC][GET_SERIES_NUMBER] No se encontraron SeriesNumbers válidos, usando SeriesNumber = 1');
                return 1;
            }
            
            // Encontrar el SeriesNumber más alto
            $maxSeriesNumber = max($seriesNumbers);
            $nextSeriesNumber = $maxSeriesNumber + 1;
            
            error_log('[ORTHANC][GET_SERIES_NUMBER] SeriesNumber más alto encontrado: ' . $maxSeriesNumber);
            error_log('[ORTHANC][GET_SERIES_NUMBER] Siguiente SeriesNumber asignado: ' . $nextSeriesNumber);
            
            return $nextSeriesNumber;
            
        } catch (Exception $e) {
            error_log('[ORTHANC][GET_SERIES_NUMBER] Excepción al consultar SeriesNumber: ' . $e->getMessage());
            error_log('[ORTHANC][GET_SERIES_NUMBER] Usando SeriesNumber = 1 por defecto');
            return 1; // En caso de excepción, usar 1
        }
    }
    
    private function findStudyByStudyInstanceUID($studyInstanceUID) {
        $payload = [
            'Level' => 'Study',
            'Query' => [ 'StudyInstanceUID' => $studyInstanceUID ]
        ];
        $response = $this->makeRequestWithRetry(
            '/tools/find',
            'POST',
            $payload,
            30
        );
        if ($response['success'] && !empty($response['data'])) {
            // La respuesta de /tools/find devuelve una lista de Orthanc IDs de estudios
            return [ 'found' => true, 'study_id' => $response['data'][0] ];
        }
        return [ 'found' => false, 'study_id' => null ];
    }
    
    /**
     * Genera tags DICOM estándar para un informe médico
     * 
     * @param array $informeData Datos del informe:
     *                          - patient_id: ID del paciente
     *                          - patient_name: Nombre del paciente
     *                          - study_date: Fecha del estudio (timestamp o YYYYMMDD)
     *                          - modality: Modalidad del estudio
     *                          - accession_number: Número de acceso (opcional)
     *                          - study_instance_uid: UID del estudio (opcional, se genera si no existe)
     *                          - study_description: Descripción del estudio
     *                          - institution_name: Nombre de la institución
     *                          - referring_physician: Nombre del médico solicitante
     * 
     * @return array Tags DICOM formateadas
     */
    public static function generateDicomTags(array $informeData) {
        // Formatear fecha del estudio
        $studyDate = $informeData['study_date'] ?? date('Ymd');
        if (is_numeric($studyDate) && strlen($studyDate) > 8) {
            // Es un timestamp
            $studyDate = date('Ymd', $studyDate);
        } elseif (strlen($studyDate) !== 8) {
            // Intentar parsear como fecha
            $timestamp = strtotime($studyDate);
            $studyDate = $timestamp ? date('Ymd', $timestamp) : date('Ymd');
        }
        
        // Formatear hora del estudio
        $studyTime = isset($informeData['study_time']) ? 
            $informeData['study_time'] : 
            date('His');
        if (is_numeric($studyTime) && strlen($studyTime) > 6) {
            $studyTime = date('His', $studyTime);
        }
        
        // Generar UIDs
        // CRÍTICO: Si se proporciona study_instance_uid, usarlo (vinculación con estudio existente)
        // Si NO se proporciona, generar uno nuevo (informe independiente, sin vincular)
        $studyInstanceUID = !empty($informeData['study_instance_uid']) 
            ? $informeData['study_instance_uid'] 
            : self::generateUID();
        
        // SeriesInstanceUID siempre se genera nuevo (nueva serie dentro del estudio o estudio nuevo)
        $seriesInstanceUID = $informeData['series_instance_uid'] ?? self::generateUID();
        
        // Log para debugging
        if (!empty($informeData['study_instance_uid'])) {
            error_log("Vincular informe con estudio existente. StudyInstanceUID: {$studyInstanceUID}");
        } else {
            error_log("Crear informe como estudio independiente. Nuevo StudyInstanceUID generado: {$studyInstanceUID}");
        }
        
        // Construir tags DICOM
        $tags = [
            'PatientName' => $informeData['patient_name'] ?? 'PACIENTE DESCONOCIDO',
            'StudyDescription' => $informeData['study_description'] ?? 'Informe Médico',
            'StudyDate' => $studyDate,
            'StudyTime' => $studyTime,
            'StudyInstanceUID' => $studyInstanceUID,
            'SeriesDescription' => ($informeData['study_description'] ?? 'Informe Médico') . ' - PDF',
            'SeriesDate' => $studyDate,
            'SeriesTime' => $studyTime,
            'SeriesInstanceUID' => $seriesInstanceUID,
            'SeriesNumber' => '1',
            'Modality' => $informeData['modality'] ?? 'DOC', // Por defecto DOC para informes
            'ContentDate' => $studyDate,
            'ContentTime' => $studyTime,
            'InstanceNumber' => '1',
            'InstitutionName' => $informeData['institution_name'] ?? 'HOSPITAL DIGITAL',
            'ReferringPhysicianName' => $informeData['referring_physician'] ?? 'AUTOMATIZADO',
            'SOPClassUID' => self::SOPCLASS_PDF
        ];
        
        // Agregar campos opcionales si existen
        if (!empty($informeData['patient_id'])) {
            $tags['PatientID'] = $informeData['patient_id'];
        }
        
        if (!empty($informeData['accession_number'])) {
            $tags['AccessionNumber'] = $informeData['accession_number'];
        }
        
        if (!empty($informeData['patient_birth_date'])) {
            $birthDate = $informeData['patient_birth_date'];
            if (is_numeric($birthDate) && strlen($birthDate) > 8) {
                $birthDate = date('Ymd', $birthDate);
            } elseif (strlen($birthDate) !== 8) {
                $timestamp = strtotime($birthDate);
                $birthDate = $timestamp ? date('Ymd', $timestamp) : null;
            }
            if ($birthDate) {
                $tags['PatientBirthDate'] = $birthDate;
            }
        }
        
        if (!empty($informeData['patient_sex'])) {
            $tags['PatientSex'] = strtoupper(substr($informeData['patient_sex'], 0, 1));
        }
        
        return $tags;
    }
    
    /**
     * Valida que las tags DICOM requeridas estén presentes
     * 
     * @param array $tags Tags DICOM a validar
     * @throws Exception Si faltan tags requeridas
     */
    private function validateDicomTags(array $tags) {
        $required = ['PatientName', 'StudyDate', 'Modality'];
        $missing = [];
        
        foreach ($required as $field) {
            if (empty($tags[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new Exception("Faltan tags DICOM requeridas: " . implode(', ', $missing));
        }
    }
    
    /**
     * Realiza una petición HTTP a Orthanc con reintentos automáticos
     * 
     * @param string $endpoint Endpoint de la API (ej: '/tools/create-dicom')
     * @param string $method Método HTTP ('GET', 'POST', etc.)
     * @param array|null $data Datos a enviar (para POST)
     * @param float|null $timeout Timeout específico (opcional)
     * @return array Respuesta procesada
     */
    public function makeRequestWithRetry($endpoint, $method = 'GET', $data = null, $timeout = null) {
        $url = $this->orthancUrl . $endpoint;
        $usedTimeout = $timeout ?? $this->timeout;
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $this->credentials['username'] . ':' . $this->credentials['password']);
                curl_setopt($ch, CURLOPT_TIMEOUT, $usedTimeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                
                if ($method === 'POST' && $data !== null) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json'
                    ]);
                } elseif ($method === 'PATCH' && $data !== null) {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json'
                    ]);
                } elseif ($method === 'DELETE') {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                if ($error) {
                    // Para DELETE, si hay timeout, puede ser que DelayedDeletion esté procesando
                    // en segundo plano. El mensaje debe ser más informativo.
                    if ($method === 'DELETE' && stripos($error, 'timeout') !== false) {
                        throw new Exception("Timeout al eliminar estudio. La eliminación puede estar procesándose en segundo plano con DelayedDeletion. Error: $error");
                    }
                    throw new Exception("Error de conexión: $error");
                }
                
                // DELETE puede retornar 200 (OK) o 204 (No Content) o 404 (Not Found)
                // Con DelayedDeletion, DELETE debería responder inmediatamente (<500ms)
                // No hacer reintentos para DELETE con códigos válidos (200, 204, 404)
                if ($httpCode >= 200 && $httpCode < 300) {
                    // Para 204 No Content, la respuesta puede estar vacía
                    $decoded = null;
                    if (!empty($response) && $httpCode !== 204) {
                        $decoded = json_decode($response, true);
                    }
                    return [
                        'success' => true,
                        'data' => $decoded ?: [],
                        'http_code' => $httpCode
                    ];
                } else {
                    // Para DELETE, 404 puede ser aceptable (recurso ya no existe)
                    // Con DelayedDeletion, esto puede pasar si el estudio ya fue eliminado
                    if ($method === 'DELETE' && $httpCode === 404) {
                        return [
                            'success' => false,
                            'http_code' => 404,
                            'error' => 'Not Found'
                        ];
                    }
                    
                    // Intentar parsear JSON de la respuesta
                    $decoded = null;
                    $jsonError = null;
                    if (!empty($response)) {
                        $decoded = json_decode($response, true);
                        $jsonError = json_last_error();
                    }
                    
                    // Log completo del error HTTP de Orthanc
                    error_log('[ORTHANC][HTTP_ERROR] code=' . $httpCode . ' method=' . $method . ' url=' . $url);
                    error_log('[ORTHANC][HTTP_ERROR][RAW] ' . (is_string($response) ? substr($response, 0, 1000) : '[empty]'));
                    if ($jsonError !== JSON_ERROR_NONE) {
                        error_log('[ORTHANC][HTTP_ERROR][JSON_ERROR] ' . json_last_error_msg());
                    }
                    if ($decoded) {
                        error_log('[ORTHANC][HTTP_ERROR][DECODED] ' . json_encode($decoded, JSON_PRETTY_PRINT));
                    }
                    
                    // Construir mensaje de error
                    $errorMsg = "HTTP Error $httpCode";
                    if ($decoded) {
                        $errorMsg = $decoded['Message'] ?? $decoded['message'] ?? $errorMsg;
                        if (!empty($decoded['Details'])) {
                            $errorMsg .= ': ' . $decoded['Details'];
                        }
                    } elseif (!empty($response) && is_string($response)) {
                        // Si no es JSON, usar los primeros caracteres de la respuesta
                        $errorMsg = "HTTP Error $httpCode: " . substr(trim($response), 0, 200);
                    }
                    
                    // Si hay un error de "Unknown DICOM tag", intentar identificar el tag problemático
                    if (stripos($errorMsg, 'Unknown DICOM tag') !== false || stripos($errorMsg, 'unknown') !== false) {
                        error_log('[ORTHANC][HTTP_ERROR] ⚠️ Error de tag DICOM desconocido detectado');
                        error_log('[ORTHANC][HTTP_ERROR] Mensaje completo: ' . $errorMsg);
                        if (!empty($data)) {
                            error_log('[ORTHANC][HTTP_ERROR] Tags enviados: ' . json_encode(array_keys($data), JSON_PRETTY_PRINT));
                        }
                    }
                    
                    throw new Exception($errorMsg);
                }
                
            } catch (Exception $e) {
                $lastException = $e;
                
                // Si no es el último intento, esperar con backoff exponencial
                if ($attempt < $this->maxRetries) {
                    $waitTime = pow($this->backoffBase, $attempt);
                    $waitMicros = (int) round($waitTime * 1000000);
                    error_log("Intento $attempt fallido para $endpoint. Reintentando en " . number_format($waitTime, 2) . "s. Error: " . $e->getMessage());
                    usleep($waitMicros);
                }
            }
        }
        
        // Todos los intentos fallaron
        return [
            'success' => false,
            'error' => $lastException ? $lastException->getMessage() : 'Error desconocido',
            'http_code' => null
        ];
    }
    
    /**
     * Elimina una instancia DICOM de Orthanc
     * 
     * @param string $instanceId ID de la instancia a eliminar
     * @return array Resultado de la operación
     */
    public function deleteInstance($instanceId) {
        try {
            $response = $this->makeRequestWithRetry(
                '/instances/' . urlencode($instanceId),
                'DELETE',
                null,
                30
            );
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => "Instancia eliminada exitosamente de Orthanc",
                    'instance_id' => $instanceId
                ];
            } else {
                // Si la instancia ya no existe, considerarlo éxito
                if (strpos($response['error'] ?? '', '404') !== false || 
                    strpos($response['error'] ?? '', 'not found') !== false) {
                    return [
                        'success' => true,
                        'message' => "Instancia ya no existe en Orthanc",
                        'instance_id' => $instanceId,
                        'already_deleted' => true
                    ];
                }
                
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'Error desconocido al eliminar instancia',
                    'instance_id' => $instanceId
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'instance_id' => $instanceId
            ];
        }
    }
    
    /**
     * Elimina una serie completa de Orthanc (flujo propuesto)
     * 
     * @param string $seriesId ID de la serie a eliminar
     * @return array Resultado de la operación
     */
    public function deleteSeries($seriesId) {
        try {
            $response = $this->makeRequestWithRetry(
                '/series/' . urlencode($seriesId),
                'DELETE',
                null,
                30
            );
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => "Serie eliminada exitosamente de Orthanc",
                    'series_id' => $seriesId
                ];
            } else {
                // Si la serie ya no existe, considerarlo éxito
                if (strpos($response['error'] ?? '', '404') !== false || 
                    strpos($response['error'] ?? '', 'not found') !== false) {
                    return [
                        'success' => true,
                        'message' => "Serie ya no existe en Orthanc",
                        'series_id' => $seriesId,
                        'already_deleted' => true
                    ];
                }
                
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'Error desconocido al eliminar serie',
                    'series_id' => $seriesId
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'series_id' => $seriesId
            ];
        }
    }
    
    /**
     * Elimina un estudio completo de Orthanc
     * 
     * @param string $studyId ID del estudio a eliminar
     * @return array Resultado de la operación
     */
    public function deleteStudy($studyId) {
        try {
            error_log('[ORTHANC][DELETE_STUDY] Iniciando eliminación de estudio: ' . $studyId);
            
            // Usar el método estándar DELETE /studies/{id}
            // El plugin DelayedDeletion es transparente: intercepta esta llamada automáticamente
            // y hace la eliminación diferida en segundo plano, respondiendo inmediatamente (<500ms)
            // Si el plugin no está activo, la eliminación será estándar (más lenta)
            // Usar timeout de 60 segundos: suficiente margen para DelayedDeletion y eliminación estándar
            // Nota: Aunque DelayedDeletion debería responder rápido, algunos casos pueden tardar más
            // especialmente si Orthanc está ocupado procesando otras operaciones
            $response = $this->makeRequestWithRetry(
                '/studies/' . urlencode($studyId),
                'DELETE',
                null,
                60 // Timeout de 60 segundos: margen suficiente para DelayedDeletion y eliminación estándar
            );
            
            error_log('[ORTHANC][DELETE_STUDY] Respuesta recibida: ' . json_encode($response));
            
            // Verificar si hubo timeout
            $hadTimeout = false;
            if (!$response['success']) {
                $errorMsg = $response['error'] ?? '';
                $hadTimeout = (stripos($errorMsg, 'timeout') !== false || 
                              stripos($errorMsg, 'timed out') !== false ||
                              stripos($errorMsg, 'Operation timed out') !== false);
            }
            
            if ($response['success']) {
                $httpCode = $response['http_code'] ?? null;
                error_log('[ORTHANC][DELETE_STUDY] ✅ Orthanc respondió éxito al DELETE. HTTP Code: ' . $httpCode);

                // Verificar que el estudio fue eliminado.
                // Con el plugin DelayedDeletion el DELETE devuelve 200 OK inmediatamente pero
                // el estudio puede seguir apareciendo brevemente mientras se encola la eliminación.
                // En ese caso confiamos en el 200 OK de Orthanc y retornamos éxito.
                $verifyResponse = $this->makeRequestWithRetry(
                    '/studies/' . urlencode($studyId),
                    'GET',
                    null,
                    10
                );

                if ($verifyResponse['success'] && isset($verifyResponse['data'])) {
                    // Puede ser DelayedDeletion (encolado) o realmente falló.
                    // Como Orthanc devolvió 200 OK al DELETE, confiamos en él.
                    error_log('[ORTHANC][DELETE_STUDY] ℹ️ El estudio aún aparece en GET tras 200 OK (probable DelayedDeletion). Se retorna éxito.');
                    return [
                        'success' => true,
                        'message' => 'Estudio eliminado (eliminación diferida en curso o ya completada)',
                        'study_id' => $studyId,
                        'delayed_deletion' => true,
                        'http_code' => $httpCode,
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Estudio eliminado exitosamente de Orthanc',
                    'study_id' => $studyId,
                    'http_code' => $httpCode,
                ];
            } else {
                $httpCode = $response['http_code'] ?? null;
                $errorMsg = $response['error'] ?? 'Error desconocido al eliminar estudio';

                error_log('[ORTHANC][DELETE_STUDY] Error al eliminar. HTTP Code: ' . $httpCode . ', Error: ' . $errorMsg);

                // Ante cualquier error (timeout, red, etc.) verificar si el estudio ya no existe.
                // Cubre: (a) timeout de PHP mientras cURL completó, (b) respuesta perdida en red.
                error_log('[ORTHANC][DELETE_STUDY] Verificando existencia del estudio tras error...');
                $verifyResponse = $this->makeRequestWithRetry(
                    '/studies/' . urlencode($studyId),
                    'GET',
                    null,
                    10
                );

                if (!$verifyResponse['success'] || !isset($verifyResponse['data'])) {
                    // El estudio ya no existe → la eliminación se completó a pesar del error reportado.
                    error_log('[ORTHANC][DELETE_STUDY] ✅ Estudio no encontrado tras error: eliminación completada.');
                    return [
                        'success' => true,
                        'message' => 'Estudio eliminado (verificado tras error de comunicación)',
                        'study_id' => $studyId,
                        'already_deleted' => true,
                        'http_code' => 200,
                    ];
                }

                // El estudio sigue existiendo: error real.
                if ($hadTimeout) {
                    error_log('[ORTHANC][DELETE_STUDY] ❌ Timeout y el estudio aún existe.');
                    return [
                        'success' => false,
                        'error' => 'Timeout al eliminar: el estudio todavía existe en Orthanc. Reintentá la operación.',
                        'study_id' => $studyId,
                        'http_code' => $httpCode,
                        'timeout' => true,
                    ];
                }

                return [
                    'success' => false,
                    'error' => $errorMsg,
                    'study_id' => $studyId,
                    'http_code' => $httpCode,
                ];
            }
            
        } catch (Exception $e) {
            error_log('[ORTHANC][DELETE_STUDY] Excepción: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'study_id' => $studyId,
                'http_code' => null
            ];
        }
    }
    
    /**
     * Actualiza tags DICOM de un estudio completo usando /tools/modify
     * Según la documentación de Orthanc, se debe usar /tools/modify que crea un job
     * que genera nuevas instancias con los tags modificados
     * 
     * @param string $studyId ID del estudio en Orthanc
     * @param array $tags Array asociativo de tags DICOM a actualizar
     *                   Ejemplo: ['PatientName' => 'NUEVO NOMBRE', 'StudyDescription' => 'Nueva descripción']
     * @param array $context Opciones: defer_post_process (bool) — no eliminar original; post-proceso en job-status
     * @return array Resultado de la operación
     */
    public function updateStudyTags($studyId, array $tags, array $context = []) {
        $deferPostProcess = !empty($context['defer_post_process']);
        try {
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ===== INICIANDO ACTUALIZACIÓN =====');
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Study ID recibido: ' . $studyId);
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Study ID tipo: ' . gettype($studyId));
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Study ID longitud: ' . strlen($studyId));
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Tags a actualizar: ' . json_encode($tags, JSON_PRETTY_PRINT));
            
            // Validar que el estudio existe ANTES de intentar modificarlo
            // Nota: Si el estudio fue modificado previamente, puede que el ID original ya no exista
            // En ese caso, intentaremos continuar de todas formas ya que /studies/{id}/modify
            // puede manejar estudios que fueron movidos o renombrados
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Verificando existencia del estudio...');
            $studyInfo = $this->makeRequestWithRetry(
                '/studies/' . urlencode($studyId),
                'GET',
                null,
                10
            );
            
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Respuesta de verificación: ' . json_encode($studyInfo, JSON_PRETTY_PRINT));
            
            // Si el estudio no existe, intentar continuar de todas formas
            // porque puede que haya sido modificado previamente y el ID cambió
            // El endpoint /studies/{id}/modify retornará un error si realmente no existe
            if (!$studyInfo['success']) {
                $httpCode = $studyInfo['http_code'] ?? null;
                $errorMsg = $studyInfo['error'] ?? 'Estudio no encontrado';
                
                // Si es 404 o el error contiene "404" o "not found", el estudio no existe
                // pero intentaremos modificar de todas formas porque puede que el estudio
                // haya sido renombrado/modificado previamente
                $isNotFound = ($httpCode == 404) || 
                             (stripos($errorMsg, '404') !== false) || 
                             (stripos($errorMsg, 'not found') !== false) ||
                             (stripos($errorMsg, 'Unknown resource') !== false);
                
                if ($isNotFound) {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ ADVERTENCIA: Estudio no encontrado, pero continuando...');
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] El estudio puede haber sido modificado previamente. Intentando modificar de todas formas.');
                    // Continuar sin $studyInfo, usaremos valores por defecto
                    $studyInfo = ['success' => false, 'data' => []];
                } else {
                    // Para otros errores (no 404), retornar error solo si es un error crítico
                    // Errores de conexión o timeout pueden ser temporales
                    $isTemporaryError = (stripos($errorMsg, 'timeout') !== false) ||
                                       (stripos($errorMsg, 'connection') !== false) ||
                                       (stripos($errorMsg, 'conexión') !== false);
                    
                    if ($isTemporaryError) {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ ADVERTENCIA: Error temporal detectado, continuando...');
                        $studyInfo = ['success' => false, 'data' => []];
                    } else {
                        // Para otros errores, retornar error
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ ERROR: Error verificando estudio en Orthanc');
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Error: ' . $errorMsg);
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] HTTP Code: ' . ($httpCode ?? 'N/A'));
                        
                        return [
                            'success' => false,
                            'error' => 'Error verificando estudio en Orthanc: ' . $errorMsg,
                            'study_id' => $studyId,
                            'http_code' => $httpCode
                        ];
                    }
                }
            } else {
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio encontrado en Orthanc');
            }
            
            // Mapeo de nombres de tags a números DICOM (formato "GGGG,EEEE")
            $dicomTagMapping = [
                'PatientName' => '0010,0010',
                'PatientID' => '0010,0020',
                'StudyDescription' => '0008,1030',
                'StudyDate' => '0008,0020',
                'AccessionNumber' => '0008,0050',
                'InstitutionName' => '0008,0080',
                'ReferringPhysicianName' => '0008,0090'
            ];
            
            // Tags de paciente que requieren incluir todos los Patient MainDicomTags
            $patientTags = ['PatientName', 'PatientID'];
            $hasPatientTags = false;
            foreach ($tags as $tagName => $tagValue) {
                if (in_array($tagName, $patientTags)) {
                    $hasPatientTags = true;
                    break;
                }
            }
            
            // Si se están modificando tags de paciente, obtener todos los Patient MainDicomTags
            $patientMainTags = [];
            if ($hasPatientTags && $studyInfo['success'] && isset($studyInfo['data'])) {
                // Obtener el ID del paciente desde el estudio
                $patientId = $studyInfo['data']['PatientMainDicomTags']['PatientID'] ?? 
                            $studyInfo['data']['MainDicomTags']['PatientID'] ?? null;
                
                if ($patientId) {
                    // Obtener información del paciente para obtener todos sus MainDicomTags
                    // Primero, obtener el ID interno del paciente desde el estudio
                    // Orthanc usa "ParentPatient" para el ID del paciente en la respuesta del estudio
                    $patientInternalId = $studyInfo['data']['ParentPatient'] ?? $studyInfo['data']['Patient'] ?? null;
                    
                    if ($patientInternalId) {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Obteniendo Patient MainDicomTags desde paciente: ' . $patientInternalId);
                        $patientInfo = $this->makeRequestWithRetry(
                            '/patients/' . urlencode($patientInternalId),
                            'GET',
                            null,
                            10
                        );
                        
                        if ($patientInfo['success'] && isset($patientInfo['data']['MainDicomTags'])) {
                            $patientMainTags = $patientInfo['data']['MainDicomTags'];
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Patient MainDicomTags obtenidos: ' . json_encode($patientMainTags, JSON_PRETTY_PRINT));
                        }
                    }
                }
                
                // Si no se pudieron obtener desde el paciente, intentar desde el estudio
                if (empty($patientMainTags) && isset($studyInfo['data']['PatientMainDicomTags'])) {
                    $patientMainTags = $studyInfo['data']['PatientMainDicomTags'];
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Patient MainDicomTags obtenidos desde estudio: ' . json_encode($patientMainTags, JSON_PRETTY_PRINT));
                } elseif (empty($patientMainTags) && isset($studyInfo['data']['MainDicomTags'])) {
                    // Si no hay PatientMainDicomTags, intentar obtener desde MainDicomTags del estudio
                    // Incluir todos los tags de paciente que puedan estar en MainDicomTags
                    $studyMainTags = $studyInfo['data']['MainDicomTags'];
                    $patientTagKeys = ['PatientName', 'PatientID', 'PatientBirthDate', 'PatientSex'];
                    foreach ($patientTagKeys as $tagKey) {
                        if (isset($studyMainTags[$tagKey])) {
                            $patientMainTags[$tagKey] = $studyMainTags[$tagKey];
                        }
                    }
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Patient MainDicomTags obtenidos desde MainDicomTags: ' . json_encode($patientMainTags, JSON_PRETTY_PRINT));
                }
            } elseif ($hasPatientTags && !$studyInfo['success']) {
                // Si el estudio no se encontró pero hay tags de paciente, intentar obtener Patient MainDicomTags
                // desde el paciente usando el PatientID de los tags
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ Estudio no encontrado, intentando obtener Patient MainDicomTags desde el paciente');
                
                // Intentar obtener el PatientID desde los tags que se están modificando
                $patientIdFromTags = $tags['PatientID'] ?? null;
                if ($patientIdFromTags) {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Buscando paciente por PatientID: ' . $patientIdFromTags);
                    // Buscar pacientes con este PatientID
                    $searchResponse = $this->makeRequestWithRetry(
                        '/tools/find',
                        'POST',
                        ['PatientID' => $patientIdFromTags],
                        10
                    );
                    
                    if ($searchResponse['success'] && !empty($searchResponse['data'])) {
                        // Obtener el primer resultado y verificar si es un paciente
                        $firstResult = $searchResponse['data'][0] ?? null;
                        if ($firstResult) {
                            $patientInternalId = null;
                            // Si el resultado es un estudio, obtener su paciente padre
                            if (strpos($firstResult, '/studies/') !== false) {
                                $tempStudyId = str_replace('/studies/', '', $firstResult);
                                $tempStudyInfo = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($tempStudyId),
                                    'GET',
                                    null,
                                    10
                                );
                                if ($tempStudyInfo['success'] && isset($tempStudyInfo['data']['ParentPatient'])) {
                                    $patientInternalId = $tempStudyInfo['data']['ParentPatient'];
                                }
                            } elseif (strpos($firstResult, '/patients/') !== false) {
                                $patientInternalId = str_replace('/patients/', '', $firstResult);
                            }
                            
                            if ($patientInternalId) {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Paciente encontrado: ' . $patientInternalId);
                                $patientInfo = $this->makeRequestWithRetry(
                                    '/patients/' . urlencode($patientInternalId),
                                    'GET',
                                    null,
                                    10
                                );
                                
                                if ($patientInfo['success'] && isset($patientInfo['data']['MainDicomTags'])) {
                                    $patientMainTags = $patientInfo['data']['MainDicomTags'];
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Patient MainDicomTags obtenidos desde paciente: ' . json_encode($patientMainTags, JSON_PRETTY_PRINT));
                                }
                            }
                        }
                    }
                }
                
                if (empty($patientMainTags)) {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se pudieron obtener Patient MainDicomTags (estudio no encontrado), continuando sin ellos');
                }
            }
            
            // Para /studies/{id}/modify, usar tags en formato legible (nombres, no números DICOM)
            // El endpoint acepta tags en formato legible como "StudyDescription", "PatientID", etc.
            $dicomTags = [];
            foreach ($tags as $tagName => $tagValue) {
                // Usar el nombre del tag directamente (formato legible)
                // Solo incluir si el valor no está vacío o si es explícitamente una cadena vacía
                if ($tagValue !== null && $tagValue !== '') {
                    $dicomTags[$tagName] = $tagValue;
                } elseif ($tagValue === '') {
                    // Permitir valores vacíos para eliminar el contenido del campo
                    $dicomTags[$tagName] = '';
                }
            }
            
            // Si se están modificando tags de paciente, incluir todos los Patient MainDicomTags
            // para evitar el error "Trying to change patient tags in a study"
            if ($hasPatientTags && !empty($patientMainTags)) {
                // Incluir todos los Patient MainDicomTags existentes que no se están modificando
                foreach ($patientMainTags as $tagName => $tagValue) {
                    // Solo incluir si no se está modificando explícitamente
                    if (!isset($dicomTags[$tagName])) {
                        $dicomTags[$tagName] = $tagValue;
                    }
                }
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Patient MainDicomTags incluidos: ' . json_encode($dicomTags, JSON_PRETTY_PRINT));
            }
            
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Tags finales a enviar (formato legible): ' . json_encode($dicomTags, JSON_PRETTY_PRINT));
            
            // Separar tags de paciente de otros tags
            // IMPORTANTE: Solo considerar tags que el usuario REALMENTE está modificando
            // No considerar tags que se agregaron automáticamente (Patient MainDicomTags)
            $patientTagNames = ['PatientName', 'PatientID', 'PatientBirthDate', 'PatientSex'];
            $patientTags = [];
            $studyTags = [];
            
            // Primero, identificar qué tags el usuario REALMENTE está modificando (vienen en $tags, no en $dicomTags)
            $userModifiedTags = array_keys($tags); // Tags que el usuario envió explícitamente
            
            // Separar tags de paciente y estudio basándose en lo que el usuario modificó
            foreach ($userModifiedTags as $tagName) {
                if (in_array($tagName, $patientTagNames)) {
                    // Es un tag de paciente que el usuario está modificando
                    if (isset($dicomTags[$tagName])) {
                        $patientTags[$tagName] = $dicomTags[$tagName];
                    }
                } else {
                    // Es un tag de estudio que el usuario está modificando
                    if (isset($dicomTags[$tagName])) {
                        $studyTags[$tagName] = $dicomTags[$tagName];
                    }
                }
            }
            
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Tags modificados por usuario - Paciente: ' . json_encode(array_keys($patientTags)) . ', Estudio: ' . json_encode(array_keys($studyTags)));

            // PACS Manager: job async; un paso por estudio salvo paciente con varios estudios y tags de paciente cambiados.
            if ($deferPostProcess) {
                return $this->updateStudyTagsPacsManagerDeferred(
                    $studyId,
                    $tags,
                    $studyInfo,
                    $dicomTags,
                    $patientTags,
                    $studyTags,
                    $patientMainTags
                );
            }
            
            // Estrategia: Si solo se modifican tags de paciente, usar /patients/{id}/modify
            // Esto propaga los cambios a todos los estudios del paciente sin duplicar
            // Si se modifican otros tags, usar /studies/{id}/modify y eliminar el original si se crea uno nuevo
            
            if (!empty($patientTags) && empty($studyTags)) {
                // Solo tags de paciente: usar /patients/{id}/modify (más eficiente, no duplica)
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Solo tags de paciente detectados, usando /patients/{id}/modify');
                
                // Obtener el ID del paciente desde el estudio
                // Si el estudio no se encontró, intentar buscar el paciente por PatientID desde los tags
                $patientInternalId = null;
                
                if ($studyInfo['success'] && isset($studyInfo['data'])) {
                    $patientInternalId = $studyInfo['data']['ParentPatient'] ?? $studyInfo['data']['Patient'] ?? null;
                }
                
                // Si no se pudo obtener desde el estudio, intentar buscar por PatientID
                if (!$patientInternalId && isset($patientTags['PatientID'])) {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio no encontrado, buscando paciente por PatientID: ' . $patientTags['PatientID']);
                    // Buscar pacientes con este PatientID
                    $searchResponse = $this->makeRequestWithRetry(
                        '/tools/find',
                        'POST',
                        ['PatientID' => $patientTags['PatientID']],
                        10
                    );
                    
                    if ($searchResponse['success'] && !empty($searchResponse['data'])) {
                        // Obtener el primer resultado y verificar si es un paciente
                        $firstResult = $searchResponse['data'][0] ?? null;
                        if ($firstResult) {
                            // Si el resultado es un estudio, obtener su paciente padre
                            if (strpos($firstResult, '/studies/') !== false) {
                                $tempStudyId = str_replace('/studies/', '', $firstResult);
                                $tempStudyInfo = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($tempStudyId),
                                    'GET',
                                    null,
                                    10
                                );
                                if ($tempStudyInfo['success'] && isset($tempStudyInfo['data']['ParentPatient'])) {
                                    $patientInternalId = $tempStudyInfo['data']['ParentPatient'];
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Paciente encontrado desde estudio: ' . $patientInternalId);
                                }
                            } elseif (strpos($firstResult, '/patients/') !== false) {
                                $patientInternalId = str_replace('/patients/', '', $firstResult);
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Paciente encontrado por PatientID: ' . $patientInternalId);
                            }
                        }
                    }
                }
                
                if (!$patientInternalId) {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se pudo obtener el ID del paciente');
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Usando /studies/{id}/modify como fallback (el estudio puede haber sido modificado previamente)');
                    // Continuar con el flujo de /studies/{id}/modify más abajo
                    $patientInternalId = null; // Forzar que no use /patients/{id}/modify
                }
                
                if ($patientInternalId) {
                    // Obtener StudyInstanceUID para encontrar el nuevo estudio después de la modificación
                    $originalStudyInstanceUID = $studyInfo['data']['MainDicomTags']['StudyInstanceUID'] ?? null;
                    
                    // ESTRATEGIA SEGURA: NO usar KeepSource:false
                    // 1. Crear nuevo estudio primero (KeepSource:true por defecto, o no especificarlo)
                    // 2. Verificar que el nuevo estudio existe
                    // 3. Solo entonces eliminar el original explícitamente
                    $payload = [
                        'Replace' => $patientTags,
                        'Force' => true,
                        // NO usar KeepSource:false - dejamos que Orthanc cree el nuevo manteniendo el original
                        'Synchronous' => false  // Modo asíncrono para no bloquear
                    ];
                    
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Usando POST /patients/' . $patientInternalId . '/modify (modo asíncrono)');
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));
                    
                    $response = $this->makeRequestWithRetry(
                        '/patients/' . urlencode($patientInternalId) . '/modify',
                        'POST',
                        $payload,
                        30 // Timeout corto porque Orthanc responde inmediatamente con job_id
                    );
                    
                    if ($response['success']) {
                        $respData = $response['data'] ?? [];
                        if ($this->isOrthancModifyJobResponse($respData)) {
                            $jobId = $this->extractOrthancJobId($respData);
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Job asíncrono creado (solo patient tags): ' . $jobId);
                            
                            return [
                                'success' => true,
                                'async' => true,
                                'job_id' => $jobId,
                                'study_id' => $studyId,
                                'message' => 'Modificación de tags de paciente iniciada en segundo plano',
                                'updated_tags' => array_keys($tags)
                            ];
                        }
                        
                        // Modo síncrono (fallback si Orthanc no soporta asíncrono o si hay error)
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Tags de paciente actualizados exitosamente (modo síncrono)');
                        
                        // ESTRATEGIA SEGURA: Buscar el nuevo estudio primero, verificar que existe, luego eliminar el original
                        // Buscar el nuevo estudio usando StudyInstanceUID (que se preservó)
                        $newStudyId = null;
                        if ($originalStudyInstanceUID) {
                            // Esperar un momento para que Orthanc termine de crear el nuevo estudio
                            sleep(2);
                            
                            $findResponse = $this->makeRequestWithRetry(
                                '/tools/find',
                                'POST',
                                [
                                    'Level' => 'Study',
                                    'Query' => ['StudyInstanceUID' => $originalStudyInstanceUID]
                                ],
                                10
                            );
                            
                            if ($findResponse['success'] && !empty($findResponse['data'])) {
                                // Puede haber múltiples estudios con el mismo StudyInstanceUID (original + nuevo)
                                // Necesitamos encontrar el que tiene el nuevo PatientID/PatientName
                                $foundStudies = $findResponse['data'];
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudios encontrados con StudyInstanceUID: ' . count($foundStudies));
                                
                                // Buscar el estudio que tiene los tags modificados
                                foreach ($foundStudies as $foundStudy) {
                                    $foundStudyId = strpos($foundStudy, '/studies/') !== false 
                                        ? str_replace('/studies/', '', $foundStudy) 
                                        : $foundStudy;
                                    
                                    // Verificar que el estudio existe y tiene los tags modificados
                                    $studyCheck = $this->makeRequestWithRetry(
                                        '/studies/' . urlencode($foundStudyId),
                                        'GET',
                                        null,
                                        10
                                    );
                                    
                                    if ($studyCheck['success'] && isset($studyCheck['data'])) {
                                        $studyMainTags = $studyCheck['data']['MainDicomTags'] ?? [];
                                        $matchesNewTags = true;
                                        
                                        // Verificar si este estudio tiene los tags modificados
                                        foreach ($patientTags as $tagName => $tagValue) {
                                            $currentValue = $studyMainTags[$tagName] ?? null;
                                            if ($currentValue !== $tagValue) {
                                                $matchesNewTags = false;
                                                break;
                                            }
                                        }
                                        
                                        if ($matchesNewTags && $foundStudyId !== $studyId) {
                                            $newStudyId = $foundStudyId;
                                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Nuevo estudio encontrado con tags modificados: ' . $newStudyId);
                                            break;
                                        }
                                    }
                                }
                                
                                // Si no encontramos uno nuevo, usar el primero que no sea el original
                                if (!$newStudyId && count($foundStudies) > 0) {
                                    foreach ($foundStudies as $foundStudy) {
                                        $foundStudyId = strpos($foundStudy, '/studies/') !== false 
                                            ? str_replace('/studies/', '', $foundStudy) 
                                            : $foundStudy;
                                        
                                        if ($foundStudyId !== $studyId) {
                                            $newStudyId = $foundStudyId;
                                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Usando estudio diferente al original: ' . $newStudyId);
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Si no encontramos un nuevo estudio, usar el original (puede que no se haya duplicado)
                        if (!$newStudyId) {
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se encontró nuevo estudio, usando ID original');
                            $newStudyId = $studyId;
                        }
                        
                        // IMPORTANTE: Solo eliminar el original si encontramos un nuevo estudio diferente
                        $deleteNote = '';
                        if ($deferPostProcess && $newStudyId !== $studyId) {
                            $deleteNote = 'Modificación completada en Orthanc; reconciliación y borrado opcional en post-proceso.';
                        } elseif ($newStudyId !== $studyId) {
                            // Verificar que el nuevo estudio realmente existe antes de eliminar el original
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Verificando que el nuevo estudio existe antes de eliminar el original: ' . $newStudyId);
                            $verifyNew = $this->makeRequestWithRetry(
                                '/studies/' . urlencode($newStudyId),
                                'GET',
                                null,
                                10
                            );
                            
                            if ($verifyNew['success'] && isset($verifyNew['data'])) {
                                // El nuevo estudio existe, ahora es seguro eliminar el original
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Nuevo estudio verificado. Eliminando estudio original: ' . $studyId);
                                
                                $checkOriginal = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($studyId),
                                    'GET',
                                    null,
                                    10
                                );
                                
                                if ($checkOriginal['success'] && isset($checkOriginal['data'])) {
                                    // El original todavía existe, eliminarlo
                                    $deleteResponse = $this->deleteStudy($studyId);
                                    
                                    if ($deleteResponse['success'] && !isset($deleteResponse['already_deleted'])) {
                                        // Verificar que realmente fue eliminado
                                        $finalCheck = $this->makeRequestWithRetry(
                                            '/studies/' . urlencode($studyId),
                                            'GET',
                                            null,
                                            10
                                        );
                                        
                                        if (!$finalCheck['success'] || !isset($finalCheck['data'])) {
                                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio original eliminado exitosamente');
                                            $deleteNote = 'Tags de paciente modificados correctamente. El estudio original fue eliminado.';
                                        } else {
                                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El estudio original todavía existe después de la eliminación');
                                            $deleteNote = 'Tags de paciente modificados. ⚠️ ERROR: No se pudo eliminar el estudio original (ID: ' . $studyId . '). Por favor, elimínalo manualmente.';
                                        }
                                    } else {
                                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se pudo eliminar el estudio original: ' . ($deleteResponse['error'] ?? 'Error desconocido'));
                                        $deleteNote = 'Tags de paciente modificados. ⚠️ ERROR: No se pudo eliminar el estudio original (ID: ' . $studyId . '). Por favor, elimínalo manualmente.';
                                    }
                                } else {
                                    // El original ya no existe
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio original ya no existe');
                                    $deleteNote = 'Tags de paciente modificados correctamente.';
                                }
                            } else {
                                // El nuevo estudio no existe, NO eliminar el original
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El nuevo estudio no existe. NO se eliminará el original para evitar pérdida de datos.');
                                $newStudyId = $studyId; // Usar el original
                                $deleteNote = 'Tags de paciente modificados. ⚠️ ADVERTENCIA: No se pudo verificar el nuevo estudio. Se mantiene el estudio original.';
                            }
                        } else {
                            // El ID no cambió (puede que no se haya duplicado)
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ El ID del estudio no cambió después de modificar paciente');
                            $deleteNote = 'Tags de paciente modificados correctamente.';
                        }
                        
                        return [
                            'success' => true,
                            'message' => 'Tags del paciente actualizados exitosamente (propagado a todos los estudios)',
                            'study_id' => $newStudyId,
                            'original_study_id' => $studyId,
                            'updated_tags' => array_keys($tags),
                            'note' => $deleteNote,
                            'needs_post_process' => $deferPostProcess && $newStudyId !== $studyId,
                        ];
                    } else {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ Error actualizando tags de paciente');
                        // Si falla /patients/{id}/modify, continuar con /studies/{id}/modify como fallback
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Usando /studies/{id}/modify como fallback');
                    }
                }
                
                // Si no se pudo usar /patients/{id}/modify, usar /studies/{id}/modify
                if (!$patientInternalId || (isset($response) && !$response['success'])) {
                    // Hay tags de estudio o una mezcla: usar /studies/{id}/modify
                    // NOTA: Este endpoint crea un nuevo estudio, por lo que debemos eliminar el original
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Usando /studies/{id}/modify (fallback o tags de estudio detectados)');
                
                // Si hay tags de paciente, asegurarse de incluir TODOS los Patient MainDicomTags
                // Orthanc requiere esto para evitar el error "Trying to change patient tags in a study"
                if (!empty($patientTags) && !empty($patientMainTags)) {
                    // Incluir todos los Patient MainDicomTags que no se están modificando explícitamente
                    foreach ($patientMainTags as $tagName => $tagValue) {
                        if (!isset($dicomTags[$tagName])) {
                            $dicomTags[$tagName] = $tagValue;
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Agregando Patient MainDicomTag faltante: ' . $tagName . ' = ' . $tagValue);
                        }
                    }
                }
                
                $payload = [
                    'Replace' => $dicomTags,
                    'Synchronous' => false  // Modo asíncrono para no bloquear
                ];
                
                // Orthanc requiere "Force": true cuando se modifican tags de paciente
                if (!empty($patientTags)) {
                    $payload['Force'] = true;
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Se detectaron tags de paciente, agregando Force: true');
                }
                
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Usando POST /studies/' . $studyId . '/modify (modo asíncrono)');
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));
                
                // La modificación puede tardar si el estudio tiene muchas instancias
                // Con Synchronous: false, Orthanc responde inmediatamente con job_id
                $response = $this->makeRequestWithRetry(
                    '/studies/' . urlencode($studyId) . '/modify',
                    'POST',
                    $payload,
                    30 // Timeout corto porque Orthanc responde inmediatamente con job_id
                );
                
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Respuesta de /studies/{id}/modify: ' . json_encode($response, JSON_PRETTY_PRINT));
                
                if ($response['success']) {
                    $respData = $response['data'] ?? [];
                    if ($this->isOrthancModifyJobResponse($respData)) {
                        $jobId = $this->extractOrthancJobId($respData);
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Job asíncrono creado: ' . $jobId);
                        
                        return [
                            'success' => true,
                            'async' => true,
                            'job_id' => $jobId,
                            'study_id' => $studyId,
                            'message' => 'Modificación de tags iniciada en segundo plano',
                            'updated_tags' => array_keys($tags)
                        ];
                    }
                    
                    // Modo síncrono real (Path /studies/... o sin job)
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Tags actualizados exitosamente (modo síncrono)');
                    
                    $newStudyId = $this->extractStudyIdFromSyncModifyResponse($response['data'] ?? []);
                    
                    if ($newStudyId && $newStudyId !== $studyId) {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Nuevo estudio creado: ' . $newStudyId);
                        
                        // PRIMERO: Verificar que el nuevo estudio realmente existe antes de eliminar el original
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Verificando que el nuevo estudio existe antes de eliminar el original: ' . $newStudyId);
                        $verifyNew = $this->makeRequestWithRetry(
                            '/studies/' . urlencode($newStudyId),
                            'GET',
                            null,
                            10
                        );
                        
                        if (!$verifyNew['success'] || !isset($verifyNew['data'])) {
                            // El nuevo estudio no existe, NO eliminar el original
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El nuevo estudio no existe. NO se eliminará el original para evitar pérdida de datos.');
                            return [
                                'success' => false,
                                'error' => 'El nuevo estudio no se pudo verificar. Se mantiene el estudio original para evitar pérdida de datos.',
                                'study_id' => $studyId,
                                'original_study_id' => $studyId
                            ];
                        }
                        
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Nuevo estudio verificado.');
                        $deleteNote = '';
                        
                        if ($deferPostProcess) {
                            $deleteNote = 'Modificación completada en Orthanc; reconciliación en post-proceso.';
                        } else {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Procediendo a eliminar el original: ' . $studyId);
                        
                        // Verificar primero si el estudio original aún existe
                        $checkResponse = $this->makeRequestWithRetry(
                            '/studies/' . urlencode($studyId),
                            'GET',
                            null,
                            10
                        );
                        
                        $originalExists = $checkResponse['success'] && isset($checkResponse['data']);
                        
                        if ($originalExists) {
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio original existe, procediendo a eliminar...');
                            
                            // Eliminar el estudio original para evitar duplicados
                            $deleteResponse = $this->deleteStudy($studyId);
                            
                            if ($deleteResponse['success'] && !isset($deleteResponse['already_deleted'])) {
                                // Verificar una vez más que realmente fue eliminado
                                $finalCheck = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($studyId),
                                    'GET',
                                    null,
                                    10
                                );
                                
                                if ($finalCheck['success'] && isset($finalCheck['data'])) {
                                    // El estudio todavía existe
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El estudio original todavía existe después de la eliminación');
                                    $deleteNote = 'Se creó un nuevo estudio con los tags modificados. ⚠️ ERROR CRÍTICO: No se pudo eliminar el estudio original (ID: ' . $studyId . '). Por favor, elimínalo manualmente para evitar duplicados.';
                                } else {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio original eliminado exitosamente (verificado)');
                                    $deleteNote = 'Se creó un nuevo estudio con los tags modificados. El estudio original fue eliminado.';
                                }
                            } elseif (isset($deleteResponse['already_deleted'])) {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ Estudio original ya no existe (puede haber sido eliminado previamente)');
                                $deleteNote = 'Se creó un nuevo estudio con los tags modificados. El estudio original ya no existe.';
                            } else {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ No se pudo eliminar el estudio original: ' . ($deleteResponse['error'] ?? 'Error desconocido'));
                                
                                // Verificar si el estudio todavía existe
                                $errorCheck = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($studyId),
                                    'GET',
                                    null,
                                    10
                                );
                                
                                if ($errorCheck['success'] && isset($errorCheck['data'])) {
                                    $deleteNote = 'Se creó un nuevo estudio con los tags modificados. ⚠️ ERROR: No se pudo eliminar el estudio original (ID: ' . $studyId . '). El estudio todavía existe. Por favor, elimínalo manualmente para evitar duplicados.';
                                } else {
                                    $deleteNote = 'Se creó un nuevo estudio con los tags modificados. El estudio original ya no existe.';
                                }
                            }
                        } else {
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ Estudio original no existe (puede haber sido eliminado automáticamente por Orthanc)');
                            $deleteNote = 'Se creó un nuevo estudio con los tags modificados. El estudio original ya no existe.';
                        }
                        }
                        
                        return [
                            'success' => true,
                            'message' => 'Tags del estudio actualizados exitosamente',
                            'study_id' => $newStudyId,
                            'original_study_id' => $studyId,
                            'updated_tags' => array_keys($tags),
                            'note' => $deleteNote,
                            'needs_post_process' => $deferPostProcess,
                        ];
                    } else {
                        // Si el ID es el mismo (no debería pasar con /studies/{id}/modify, pero por si acaso)
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Study ID no cambió (inusual): ' . $studyId);
                        return [
                            'success' => true,
                            'message' => 'Tags del estudio actualizados exitosamente',
                            'study_id' => $studyId,
                            'updated_tags' => array_keys($tags),
                            'note' => 'Estudio modificado exitosamente'
                        ];
                    }
                } else {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ Error actualizando tags');
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Error: ' . ($response['error'] ?? 'Error desconocido'));
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] HTTP Code: ' . ($response['http_code'] ?? 'N/A'));
                    return [
                        'success' => false,
                        'error' => $response['error'] ?? 'Error desconocido al actualizar tags del estudio',
                        'study_id' => $studyId,
                        'http_code' => $response['http_code'] ?? null
                    ];
                }
                } // Cierre del if para /studies/{id}/modify
            } else {
                // Hay tags de estudio o una mezcla de tags de paciente y estudio
                // Si hay tags de paciente Y estudio, primero modificar los tags de paciente usando /patients/{id}/modify
                // Luego modificar los tags de estudio usando /studies/{id}/modify
                $patientResponse = null;
                $patientTagsModified = false;
                $intermediateStudyId = $studyId; // ID del estudio a modificar en Paso 2 (se actualiza si Paso 1 usa KeepSource:false)
                
                if (!empty($patientTags) && !empty($studyTags)) {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Tags de paciente Y estudio detectados, usando estrategia de dos pasos');
                    
                    // Paso 1: Modificar tags de paciente usando /patients/{id}/modify
                    $patientInternalId = null;
                    if ($studyInfo['success'] && isset($studyInfo['data'])) {
                        $patientInternalId = $studyInfo['data']['ParentPatient'] ?? $studyInfo['data']['Patient'] ?? null;
                    }
                    
                    // Si no se pudo obtener desde el estudio, intentar buscar por PatientID
                    if (!$patientInternalId && isset($patientTags['PatientID'])) {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Buscando paciente por PatientID: ' . $patientTags['PatientID']);
                        $searchResponse = $this->makeRequestWithRetry(
                            '/tools/find',
                            'POST',
                            ['PatientID' => $patientTags['PatientID']],
                            10
                        );
                        
                        if ($searchResponse['success'] && !empty($searchResponse['data'])) {
                            $firstResult = $searchResponse['data'][0] ?? null;
                            if ($firstResult) {
                                if (strpos($firstResult, '/studies/') !== false) {
                                    $tempStudyId = str_replace('/studies/', '', $firstResult);
                                    $tempStudyInfo = $this->makeRequestWithRetry(
                                        '/studies/' . urlencode($tempStudyId),
                                        'GET',
                                        null,
                                        10
                                    );
                                    if ($tempStudyInfo['success'] && isset($tempStudyInfo['data']['ParentPatient'])) {
                                        $patientInternalId = $tempStudyInfo['data']['ParentPatient'];
                                    }
                                } elseif (strpos($firstResult, '/patients/') !== false) {
                                    $patientInternalId = str_replace('/patients/', '', $firstResult);
                                }
                            }
                        }
                    }
                    
                    if ($patientInternalId) {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Paso 1: Modificando tags de paciente usando /patients/{id}/modify');
                        
                        // Obtener StudyInstanceUID original para encontrar el nuevo estudio después de la modificación
                        $originalStudyInstanceUID = $studyInfo['data']['MainDicomTags']['StudyInstanceUID'] ?? null;
                        
                        // ESTRATEGIA SEGURA: NO usar KeepSource:false
                        // 1. Crear nuevo estudio primero (mantener original)
                        // 2. Verificar que el nuevo existe
                        // 3. Solo entonces eliminar el original explícitamente
                        $patientPayload = [
                            'Replace' => $patientTags,
                            'Force' => true,
                            // NO usar KeepSource:false - dejamos que Orthanc cree el nuevo manteniendo el original
                            'Synchronous' => false  // Modo asíncrono para no bloquear
                        ];
                        
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Payload del Paso 1 (modo asíncrono): ' . json_encode($patientPayload, JSON_PRETTY_PRINT));
                        
                        $patientResponse = $this->makeRequestWithRetry(
                            '/patients/' . urlencode($patientInternalId) . '/modify',
                            'POST',
                            $patientPayload,
                            30 // Timeout corto porque Orthanc responde inmediatamente con job_id
                        );
                        
                        if ($patientResponse['success']) {
                            $patientRespData = $patientResponse['data'] ?? [];
                            if ($this->isOrthancModifyJobResponse($patientRespData)) {
                                $patientJobId = $this->extractOrthancJobId($patientRespData);
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Job Paso 1 creado: ' . $patientJobId . ' — esperando finalización...');
                                $waitPatient = $this->waitForJobCompletion($patientJobId, 300);
                                if (!$waitPatient['success']) {
                                    return [
                                        'success' => false,
                                        'error' => $waitPatient['error'] ?? 'El job de modificación de paciente falló',
                                        'study_id' => $studyId,
                                        'original_study_id' => $studyId,
                                    ];
                                }
                                $resolved = $this->findIntermediateStudyAfterPatientJob(
                                    $studyId,
                                    $waitPatient['job_data'] ?? [],
                                    $patientTags,
                                    $originalStudyInstanceUID,
                                    $studyInfo['data']['MainDicomTags'] ?? []
                                );
                                if ($resolved) {
                                    $intermediateStudyId = $resolved;
                                } else {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se identificó estudio intermedio tras job Paso 1; se usará búsqueda por UID');
                                    $intermediateStudyId = $studyId;
                                }
                                $patientTagsModified = true;
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio a usar en Paso 2 (tras job): ' . $intermediateStudyId);
                            } else {
                            // Modo síncrono (fallback)
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Tags de paciente actualizados exitosamente (modo síncrono)');
                            
                            // ESTRATEGIA SEGURA: Buscar el nuevo estudio primero, verificar que existe, luego eliminar el original
                            // Esperar un momento para que Orthanc termine de crear el nuevo estudio
                            sleep(2);
                            
                            $intermediateStudyId = $studyId; // Fallback al original
                            
                            // Buscar el nuevo estudio intermedio usando StudyInstanceUID (que se preservó)
                            if ($originalStudyInstanceUID) {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Buscando nuevo estudio intermedio por StudyInstanceUID: ' . $originalStudyInstanceUID);
                                $findResponse = $this->makeRequestWithRetry(
                                    '/tools/find',
                                    'POST',
                                    [
                                        'Level' => 'Study',
                                        'Query' => ['StudyInstanceUID' => $originalStudyInstanceUID]
                                    ],
                                    10
                                );
                                
                                if ($findResponse['success'] && !empty($findResponse['data'])) {
                                    // Puede haber múltiples estudios con el mismo StudyInstanceUID (original + nuevo)
                                    $foundStudies = $findResponse['data'];
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudios encontrados con StudyInstanceUID: ' . count($foundStudies));
                                    
                                    // Buscar el estudio que tiene los tags modificados
                                    foreach ($foundStudies as $foundStudy) {
                                        $foundStudyId = strpos($foundStudy, '/studies/') !== false 
                                            ? str_replace('/studies/', '', $foundStudy) 
                                            : $foundStudy;
                                        
                                        // Verificar que el estudio existe y tiene los tags modificados
                                        $studyCheck = $this->makeRequestWithRetry(
                                            '/studies/' . urlencode($foundStudyId),
                                            'GET',
                                            null,
                                            10
                                        );
                                        
                                        if ($studyCheck['success'] && isset($studyCheck['data'])) {
                                            $studyMainTags = $studyCheck['data']['MainDicomTags'] ?? [];
                                            $matchesNewTags = true;
                                            
                                            // Verificar si este estudio tiene los tags modificados
                                            foreach ($patientTags as $tagName => $tagValue) {
                                                $currentValue = $studyMainTags[$tagName] ?? null;
                                                if ($currentValue !== $tagValue) {
                                                    $matchesNewTags = false;
                                                    break;
                                                }
                                            }
                                            
                                            if ($matchesNewTags && $foundStudyId !== $studyId) {
                                                $intermediateStudyId = $foundStudyId;
                                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio intermedio encontrado con tags modificados: ' . $intermediateStudyId);
                                                break;
                                            }
                                        }
                                    }
                                    
                                    // Si no encontramos uno nuevo, usar el primero que no sea el original
                                    if ($intermediateStudyId === $studyId && count($foundStudies) > 0) {
                                        foreach ($foundStudies as $foundStudy) {
                                            $foundStudyId = strpos($foundStudy, '/studies/') !== false 
                                                ? str_replace('/studies/', '', $foundStudy) 
                                                : $foundStudy;
                                            
                                            if ($foundStudyId !== $studyId) {
                                                $intermediateStudyId = $foundStudyId;
                                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Usando estudio diferente al original: ' . $intermediateStudyId);
                                                break;
                                            }
                                        }
                                    }
                                } else {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se encontró estudio con StudyInstanceUID: ' . $originalStudyInstanceUID . '. Usando ID original como fallback.');
                                }
                            } else {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ StudyInstanceUID no disponible, usando ID original como fallback.');
                            }
                            
                            // IMPORTANTE: Solo eliminar el original si encontramos un nuevo estudio diferente (salvo post-proceso diferido)
                            if (!$deferPostProcess && $intermediateStudyId !== $studyId) {
                                // Verificar que el nuevo estudio realmente existe antes de eliminar el original
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Verificando que el estudio intermedio existe antes de eliminar el original: ' . $intermediateStudyId);
                                $verifyIntermediate = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($intermediateStudyId),
                                    'GET',
                                    null,
                                    10
                                );
                                
                                if ($verifyIntermediate['success'] && isset($verifyIntermediate['data'])) {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio intermedio verificado. Eliminando estudio original: ' . $studyId);
                                    
                                    $checkOriginal = $this->makeRequestWithRetry(
                                        '/studies/' . urlencode($studyId),
                                        'GET',
                                        null,
                                        10
                                    );
                                    
                                    if ($checkOriginal['success'] && isset($checkOriginal['data'])) {
                                        $deleteResponse = $this->deleteStudy($studyId);
                                        
                                        if ($deleteResponse['success'] && !isset($deleteResponse['already_deleted'])) {
                                            $finalCheck = $this->makeRequestWithRetry(
                                                '/studies/' . urlencode($studyId),
                                                'GET',
                                                null,
                                                10
                                            );
                                            
                                            if (!$finalCheck['success'] || !isset($finalCheck['data'])) {
                                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio original eliminado exitosamente');
                                            } else {
                                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El estudio original todavía existe después de la eliminación');
                                            }
                                        }
                                    } else {
                                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio original ya no existe');
                                    }
                                } else {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El estudio intermedio no existe. NO se eliminará el original para evitar pérdida de datos.');
                                    $intermediateStudyId = $studyId;
                                }
                            }
                            
                            $patientTagsModified = true;
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio a usar en Paso 2: ' . $intermediateStudyId);
                            } // fin modo síncrono Paso 1
                        } else {
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ Error actualizando tags de paciente, continuando con tags de estudio usando ID original');
                            $intermediateStudyId = $studyId;
                        }
                    } else {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se pudo obtener ID del paciente, usando /studies/{id}/modify directamente');
                    }
                }
                
                // Paso 2 (o único paso si no hay tags de paciente): Modificar tags de estudio usando /studies/{id}/modify
                // Si ya modificamos los tags de paciente en el paso 1, solo modificar los tags de estudio
                // Si no hay tags de paciente o no se pudieron modificar, incluir todos los tags
                if (!empty($patientTags) && !empty($studyTags) && $patientTagsModified) {
                    // Ya modificamos los tags de paciente, solo modificar los tags de estudio
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Paso 2: Modificando solo tags de estudio (tags de paciente ya actualizados)');
                    $payload = [
                        'Replace' => $studyTags,
                        'Synchronous' => false  // Modo asíncrono para no bloquear
                    ];
                } else {
                    // No hay tags de paciente o no se pudieron modificar, usar todos los tags
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Modificando tags de estudio usando /studies/{id}/modify');
                    
                    // Si hay tags de paciente, asegurarse de incluir TODOS los Patient MainDicomTags actuales
                    // Orthanc requiere esto para evitar el error "Trying to change patient tags in a study"
                    if (!empty($patientTags) && !empty($patientMainTags)) {
                        // Incluir todos los Patient MainDicomTags actuales
                        foreach ($patientMainTags as $tagName => $tagValue) {
                            if (!isset($dicomTags[$tagName])) {
                                $dicomTags[$tagName] = $tagValue;
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Agregando Patient MainDicomTag actual: ' . $tagName . ' = ' . $tagValue);
                            }
                        }
                    }
                    
                    $payload = [
                        'Replace' => $dicomTags,
                        'Synchronous' => false  // Modo asíncrono para no bloquear
                    ];
                    
                    // Orthanc requiere "Force": true cuando se modifican tags de paciente
                    if (!empty($patientTags)) {
                        $payload['Force'] = true;
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Se detectaron tags de paciente, agregando Force: true');
                    }
                }
                
                // Usar intermediateStudyId para Paso 2:
                // - Si Paso 1 creó un nuevo estudio, $intermediateStudyId es el nuevo estudio creado por Paso 1
                // - Si no hubo Paso 1 o falló, $intermediateStudyId es $studyId (el original)
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Usando POST /studies/' . $intermediateStudyId . '/modify (estudio para Paso 2, modo asíncrono)');
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));
                
                // La modificación puede tardar si el estudio tiene muchas instancias
                // Con Synchronous: false, Orthanc responde inmediatamente con job_id
                $response = $this->makeRequestWithRetry(
                    '/studies/' . urlencode($intermediateStudyId) . '/modify',
                    'POST',
                    $payload,
                    30 // Timeout corto porque Orthanc responde inmediatamente con job_id
                );
                
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Respuesta de /studies/{id}/modify: ' . json_encode($response, JSON_PRETTY_PRINT));
                
                if ($response['success']) {
                    $respData = $response['data'] ?? [];
                    if ($this->isOrthancModifyJobResponse($respData)) {
                        $jobId = $this->extractOrthancJobId($respData);
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Job asíncrono creado en Paso 2: ' . $jobId);
                        
                        return [
                            'success' => true,
                            'async' => true,
                            'job_id' => $jobId,
                            'study_id' => $intermediateStudyId, // ID del estudio que se está modificando
                            'original_study_id' => $studyId, // ID del estudio original
                            'message' => 'Modificación de tags iniciada en segundo plano',
                            'updated_tags' => array_keys($tags)
                        ];
                    }
                    
                    // Modo síncrono real (Path /studies/...)
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Tags actualizados exitosamente (modo síncrono)');
                    
                    $newStudyId = $this->extractStudyIdFromSyncModifyResponse($response['data'] ?? []);
                    
                    if ($newStudyId && $newStudyId !== $intermediateStudyId) {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Nuevo estudio final creado: ' . $newStudyId);
                        
                        // LÓGICA DE ELIMINACIÓN SEGURA:
                        // 1. Verificar que el nuevo estudio ($newStudyId) existe antes de eliminar cualquier cosa
                        // 2. Intentar eliminar el estudio original ($studyId) primero
                        // 3. Si el original no existe o ya fue eliminado, eliminar el intermedio ($intermediateStudyId)
                        // 4. NUNCA eliminar si no se puede verificar que el nuevo estudio existe
                        
                        // PRIMERO: Verificar que el nuevo estudio realmente existe antes de eliminar cualquier cosa
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Verificando que el nuevo estudio existe antes de eliminar el original: ' . $newStudyId);
                        $verifyNew = $this->makeRequestWithRetry(
                            '/studies/' . urlencode($newStudyId),
                            'GET',
                            null,
                            10
                        );
                        
                        if (!$verifyNew['success'] || !isset($verifyNew['data'])) {
                            // El nuevo estudio no existe, NO eliminar el original
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El nuevo estudio no existe. NO se eliminará el original para evitar pérdida de datos.');
                            return [
                                'success' => false,
                                'error' => 'El nuevo estudio no se pudo verificar. Se mantiene el estudio original para evitar pérdida de datos.',
                                'study_id' => $studyId,
                                'original_study_id' => $studyId
                            ];
                        }
                        
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Nuevo estudio verificado.');
                        
                        $deleteNote = '';
                        $deletedSuccessfully = false;
                        
                        if ($deferPostProcess) {
                            $deleteNote = 'Modificación completada en Orthanc; reconciliación en post-proceso.';
                        } else {
                        
                        // Primero intentar eliminar el estudio original (orthancid original que el usuario pasó)
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Verificando si el estudio ORIGINAL existe: ' . $studyId);
                        $checkOriginal = $this->makeRequestWithRetry(
                            '/studies/' . urlencode($studyId),
                            'GET',
                            null,
                            10
                        );
                        
                        if ($checkOriginal['success'] && isset($checkOriginal['data'])) {
                            // El original todavía existe, eliminarlo
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio original existe, eliminándolo: ' . $studyId);
                            $deleteResponse = $this->deleteStudy($studyId);
                            
                            if ($deleteResponse['success'] && !isset($deleteResponse['already_deleted'])) {
                                // Verificar que realmente fue eliminado
                                $finalCheck = $this->makeRequestWithRetry(
                                    '/studies/' . urlencode($studyId),
                                    'GET',
                                    null,
                                    10
                                );
                                
                                if (!$finalCheck['success'] || !isset($finalCheck['data'])) {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio original eliminado exitosamente');
                                    $deleteNote = 'Estudio modificado exitosamente. El estudio original fue eliminado.';
                                    $deletedSuccessfully = true;
                                } else {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El estudio original todavía existe después de la eliminación');
                                    $deleteNote = 'Se creó un nuevo estudio con los tags modificados. ⚠️ ERROR CRÍTICO: No se pudo eliminar el estudio original (ID: ' . $studyId . '). Por favor, elimínalo manualmente para evitar duplicados.';
                                }
                            } else {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se pudo eliminar el estudio original: ' . ($deleteResponse['error'] ?? 'Error desconocido'));
                                // Continuar para intentar eliminar el intermedio
                            }
                        } else {
                            // El original ya no existe (probablemente eliminado por Paso 1)
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio original ya no existe (probablemente eliminado por Paso 1 con KeepSource:false)');
                        }
                        
                        // Si hay un estudio intermedio diferente del original y del nuevo, eliminarlo
                        // Este es el estudio que quedó después del Paso 1 y debe eliminarse después del Paso 2
                        if ($intermediateStudyId !== $studyId && $intermediateStudyId !== $newStudyId) {
                            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Verificando y eliminando estudio intermedio: ' . $intermediateStudyId);
                            $intermediateCheck = $this->makeRequestWithRetry(
                                '/studies/' . urlencode($intermediateStudyId),
                                'GET',
                                null,
                                10
                            );
                            
                            if ($intermediateCheck['success'] && isset($intermediateCheck['data'])) {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio intermedio existe, eliminándolo...');
                                $intermediateDelete = $this->deleteStudy($intermediateStudyId);
                                
                                if ($intermediateDelete['success'] && !isset($intermediateDelete['already_deleted'])) {
                                    // Verificar que realmente fue eliminado
                                    $finalCheckIntermediate = $this->makeRequestWithRetry(
                                        '/studies/' . urlencode($intermediateStudyId),
                                        'GET',
                                        null,
                                        10
                                    );
                                    
                                    if (!$finalCheckIntermediate['success'] || !isset($finalCheckIntermediate['data'])) {
                                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio intermedio eliminado exitosamente');
                                        if (!$deletedSuccessfully) {
                                            $deleteNote = 'Estudio modificado exitosamente. El estudio original fue eliminado.';
                                            $deletedSuccessfully = true;
                                        }
                                    } else {
                                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ CRÍTICO: El estudio intermedio todavía existe después de la eliminación');
                                        if (!$deletedSuccessfully) {
                                            $deleteNote = 'Se creó un nuevo estudio con los tags modificados. ⚠️ ERROR CRÍTICO: No se pudo eliminar el estudio original (ID: ' . $intermediateStudyId . '). Por favor, elimínalo manualmente para evitar duplicados.';
                                        }
                                    }
                                } else {
                                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ⚠️ No se pudo eliminar el estudio intermedio: ' . ($intermediateDelete['error'] ?? 'Error desconocido'));
                                    if (!$deletedSuccessfully) {
                                        // Verificar si todavía existe
                                        $errorCheck = $this->makeRequestWithRetry(
                                            '/studies/' . urlencode($intermediateStudyId),
                                            'GET',
                                            null,
                                            10
                                        );
                                        
                                        if ($errorCheck['success'] && isset($errorCheck['data'])) {
                                            $deleteNote = 'Se creó un nuevo estudio con los tags modificados. ⚠️ ERROR: No se pudo eliminar el estudio original (ID: ' . $intermediateStudyId . '). El estudio todavía existe. Por favor, elimínalo manualmente para evitar duplicados.';
                                        } else {
                                            $deleteNote = 'Estudio modificado exitosamente.';
                                        }
                                    }
                                }
                            } else {
                                error_log('[ORTHANC][UPDATE_STUDY_TAGS] ✅ Estudio intermedio ya no existe');
                                if (!$deletedSuccessfully) {
                                    $deleteNote = 'Estudio modificado exitosamente.';
                                }
                            }
                        }
                        
                        // Si no se eliminó nada y no hay nota, establecer una nota por defecto
                        if (empty($deleteNote)) {
                            $deleteNote = 'Estudio modificado exitosamente.';
                        }
                        }
                        
                        return [
                            'success' => true,
                            'message' => 'Tags del estudio actualizados exitosamente',
                            'study_id' => $newStudyId,
                            'original_study_id' => $studyId,
                            'updated_tags' => array_keys($tags),
                            'note' => $deleteNote,
                            'needs_post_process' => $deferPostProcess,
                        ];
                    } else {
                        // Si el ID es el mismo, el estudio fue modificado en el mismo lugar
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Study ID no cambió después del Paso 2: ' . ($newStudyId ?? 'null'));
                        return [
                            'success' => true,
                            'message' => 'Tags del estudio actualizados exitosamente',
                            'study_id' => $newStudyId ?? $intermediateStudyId,
                            'updated_tags' => array_keys($tags),
                            'note' => 'Estudio modificado exitosamente'
                        ];
                    }
                } else {
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ Error actualizando tags');
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] Error: ' . ($response['error'] ?? 'Error desconocido'));
                    error_log('[ORTHANC][UPDATE_STUDY_TAGS] HTTP Code: ' . ($response['http_code'] ?? 'N/A'));
                    return [
                        'success' => false,
                        'error' => $response['error'] ?? 'Error desconocido al actualizar tags del estudio',
                        'study_id' => $studyId,
                        'http_code' => $response['http_code'] ?? null
                    ];
                }
            } // Cierre del if para solo tags de paciente
        } catch (Exception $e) {
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] ❌ Excepción actualizando tags: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'study_id' => $studyId
            ];
        }
    }

    /**
     * Flujo PACS Manager: async + post-proceso en job-status.
     */
    private function updateStudyTagsPacsManagerDeferred(
        string $studyId,
        array $userTags,
        array $studyInfo,
        array $dicomTags,
        array $patientTags,
        array $studyTags,
        array $patientMainTags
    ): array {
        $studyData = ($studyInfo['success'] ?? false) ? ($studyInfo['data'] ?? []) : [];
        $studyMain = $studyData['MainDicomTags'] ?? [];
        $studyPatient = $studyData['PatientMainDicomTags'] ?? [];
        $patientInternalId = $studyData['ParentPatient'] ?? $studyData['Patient'] ?? null;
        $studyCount = $patientInternalId ? $this->countOrthancPatientStudies($patientInternalId) : 1;

        if (!empty($patientTags) && !empty($patientMainTags)) {
            foreach ($patientMainTags as $tagName => $tagValue) {
                if (!isset($dicomTags[$tagName]) && $tagValue !== '' && $tagValue !== null) {
                    $dicomTags[$tagName] = $tagValue;
                }
            }
        }

        $patientChanging = $this->patientTagsDifferFromBaseline($patientTags, $patientMainTags, $studyPatient);
        $studyOnlyReplace = $this->buildStudyOnlyReplace($studyTags, $studyMain);
        $fullReplace = $this->sanitizeModifyReplaceTags($dicomTags);

        $studyCountByPatientId = $this->countStudiesByDicomPatientId(
            $patientMainTags['PatientID'] ?? $studyPatient['PatientID'] ?? $studyMain['PatientID'] ?? null
        );
        $effectiveStudyCount = max($studyCount, $studyCountByPatientId);

        error_log('[ORTHANC][UPDATE_STUDY_TAGS] PACS Manager: estudios paciente Orthanc='
            . $studyCount . ' por PatientID=' . $studyCountByPatientId
            . ' patientChanging=' . ($patientChanging ? 'yes' : 'no')
            . ' studyReplaceKeys=' . json_encode(array_keys($studyOnlyReplace)));

        if ($patientChanging && !$patientInternalId) {
            return [
                'success' => false,
                'error' => 'No se pudo obtener el paciente en Orthanc para actualizar ID/nombre.',
                'study_id' => $studyId,
                'original_study_id' => $studyId,
            ];
        }

        // Corregir ID de paciente de UN estudio: siempre intentar /studies/{id}/modify primero
        // (asocia el examen al PatientID correcto sin tocar otros estudios del mismo ID en PACS).
        $replace = !empty($studyOnlyReplace)
            ? array_merge($this->sanitizeModifyReplaceTags($patientTags), $studyOnlyReplace)
            : $fullReplace;

        if (empty($replace)) {
            return [
                'success' => false,
                'error' => 'No hay cambios válidos para enviar a Orthanc (revise los campos editados).',
                'study_id' => $studyId,
                'original_study_id' => $studyId,
            ];
        }

        $payload = [
            'Replace' => $replace,
            'Synchronous' => false,
        ];
        if ($patientChanging) {
            $payload['Force'] = true;
        }

        $newPid = $patientTags['PatientID'] ?? null;
        $oldPid = $studyPatient['PatientID'] ?? $patientMainTags['PatientID'] ?? $studyMain['PatientID'] ?? null;
        if ($newPid && $newPid !== $oldPid) {
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Corrección PatientID: ' . $oldPid . ' → ' . $newPid
                . ' (solo este estudio vía /studies/modify)');
        }

        error_log('[ORTHANC][UPDATE_STUDY_TAGS] PACS Manager: POST /studies/' . $studyId . '/modify (un paso, async)');
        try {
            $response = $this->makeRequestWithRetry(
                '/studies/' . urlencode($studyId) . '/modify',
                'POST',
                $payload,
                30
            );
        } catch (Exception $e) {
            $err = $e->getMessage();
            if ($patientChanging && $patientInternalId && stripos($err, 'other studies') !== false) {
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Fallback (excepción): reintentando /patients/modify');
                return $this->runPatientsModifyAsync(
                    $patientInternalId,
                    $this->sanitizeModifyReplaceTags(array_merge($patientMainTags, $patientTags)),
                    $studyId,
                    $userTags,
                    $effectiveStudyCount
                );
            }
            return [
                'success' => false,
                'error' => $err,
                'study_id' => $studyId,
                'original_study_id' => $studyId,
            ];
        }

        if ($response['success']) {
            $respData = $response['data'] ?? [];
            if ($this->isOrthancModifyJobResponse($respData)) {
                $jobId = $this->extractOrthancJobId($respData);
                return [
                    'success' => true,
                    'async' => true,
                    'job_id' => $jobId,
                    'study_id' => $studyId,
                    'original_study_id' => $studyId,
                    'message' => 'Modificación iniciada en Orthanc (monitoreo en segundo plano)',
                    'updated_tags' => array_keys($userTags),
                    'needs_post_process' => true,
                    'modify_via' => 'studies',
                ];
            }
            $newStudyId = $this->extractStudyIdFromSyncModifyResponse($respData);
            if ($newStudyId && $newStudyId !== $studyId) {
                return [
                    'success' => true,
                    'study_id' => $newStudyId,
                    'original_study_id' => $studyId,
                    'updated_tags' => array_keys($userTags),
                    'needs_post_process' => true,
                    'note' => 'Modificación síncrona; post-proceso pendiente.',
                ];
            }
        }

        $err = $this->formatOrthancErrorMessage($response);

        // Último recurso: Orthanc exige /patients/modify (varios estudios bajo el mismo registro de paciente erróneo).
        if ($patientChanging && $patientInternalId && stripos($err, 'other studies') !== false) {
            error_log('[ORTHANC][UPDATE_STUDY_TAGS] Fallback: /studies/modify rechazado → /patients/modify (solo copias de este job; sin borrado masivo)');
            $result = $this->runPatientsModifyAsync(
                $patientInternalId,
                $this->sanitizeModifyReplaceTags(array_merge($patientMainTags, $patientTags)),
                $studyId,
                $userTags,
                $effectiveStudyCount
            );
            if ($result['success'] ?? false) {
                $result['message'] = ($result['message'] ?? '') . ' No se eliminarán otros estudios con el mismo ID en PACS.';
            }
            return $result;
        }

        if (stripos($err, 'other studies') !== false) {
            $err = 'Orthanc no permite cambiar el ID de paciente en este estudio porque comparte registro con otros exámenes. '
                . 'Contacte soporte o agrupe los exámenes manualmente en Orthanc.';
        }

        return [
            'success' => false,
            'error' => $err,
            'study_id' => $studyId,
            'original_study_id' => $studyId,
        ];
    }

    private function runPatientsModifyAsync(
        string $patientInternalId,
        array $patientReplace,
        string $studyId,
        array $userTags,
        int $studyCountHint
    ): array {
        $payload = [
            'Replace' => $patientReplace,
            'Force' => true,
            'Synchronous' => false,
        ];
        try {
            $response = $this->makeRequestWithRetry(
                '/patients/' . urlencode($patientInternalId) . '/modify',
                'POST',
                $payload,
                30
            );
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'study_id' => $studyId,
                'original_study_id' => $studyId,
            ];
        }
        if ($response['success']) {
            $respData = $response['data'] ?? [];
            if ($this->isOrthancModifyJobResponse($respData)) {
                $jobId = $this->extractOrthancJobId($respData);
                $msg = 'Modificación del paciente iniciada en Orthanc';
                if ($studyCountHint > 1) {
                    $msg .= ' (paciente con varios estudios; se alineará este estudio al finalizar).';
                }
                return [
                    'success' => true,
                    'async' => true,
                    'job_id' => $jobId,
                    'study_id' => $studyId,
                    'original_study_id' => $studyId,
                    'message' => $msg,
                    'updated_tags' => array_keys($userTags),
                    'needs_post_process' => true,
                    'modify_via' => 'patients',
                ];
            }
        }
        return [
            'success' => false,
            'error' => $this->formatOrthancErrorMessage($response),
            'study_id' => $studyId,
            'original_study_id' => $studyId,
        ];
    }

    private function countStudiesByDicomPatientId(?string $patientId): int {
        if (!$patientId) {
            return 0;
        }
        $findResponse = $this->makeRequestWithRetry(
            '/tools/find',
            'POST',
            ['Level' => 'Study', 'Query' => ['PatientID' => $patientId]],
            15
        );
        if ($findResponse['success'] && is_array($findResponse['data'])) {
            return count($findResponse['data']);
        }
        return 0;
    }

    private function countOrthancPatientStudies(string $patientInternalId): int {
        $response = $this->makeRequestWithRetry(
            '/patients/' . urlencode($patientInternalId) . '/studies',
            'GET',
            null,
            15
        );
        if ($response['success'] && is_array($response['data'])) {
            return count($response['data']);
        }
        return 1;
    }

    /** Omite tags vacíos (Orthanc suele rechazarlos en Replace). */
    private function sanitizeModifyReplaceTags(array $tags): array {
        $out = [];
        foreach ($tags as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private function patientTagsDifferFromBaseline(array $patientTags, array $patientMainTags, array $studyPatientTags): bool {
        foreach ($patientTags as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $base = $patientMainTags[$name] ?? $studyPatientTags[$name] ?? null;
            if ((string) $base !== (string) $value) {
                return true;
            }
        }
        return false;
    }

    private function buildStudyOnlyReplace(array $studyTags, array $studyMainTags): array {
        $out = [];
        foreach ($studyTags as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $current = $studyMainTags[$name] ?? null;
            if ((string) $current !== (string) $value) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    private function formatOrthancErrorMessage(array $response): string {
        $msg = $response['error'] ?? 'Error al modificar en Orthanc';
        if (!empty($response['data']['Details'])) {
            $msg = ($response['data']['Message'] ?? $msg) . ': ' . $response['data']['Details'];
        }
        return $msg;
    }

    /**
     * Respuesta async de POST .../modify: Path /jobs/{id} (no confundir con /studies/{id}).
     */
    private function isOrthancModifyJobResponse(array $data): bool {
        if (!empty($data['Path']) && is_string($data['Path'])) {
            return strncmp($data['Path'], '/jobs/', 6) === 0;
        }
        return isset($data['ID']) && !isset($data['RemainingAncestor']);
    }

    private function extractOrthancJobId(array $data): ?string {
        if (!empty($data['Path']) && preg_match('#^/jobs/([a-f0-9\-]+)#i', $data['Path'], $m)) {
            return $m[1];
        }
        return $data['ID'] ?? null;
    }

    /**
     * ID de estudio nuevo en respuesta síncrona (Path /studies/...).
     */
    private function extractStudyIdFromSyncModifyResponse(array $data): ?string {
        if ($this->isOrthancModifyJobResponse($data)) {
            return null;
        }
        if (!empty($data['Path']) && preg_match('#^/studies/([a-f0-9\-]+)#i', $data['Path'], $m)) {
            return $m[1];
        }
        if (isset($data['ID']) && empty($data['Path'])) {
            return $data['ID'];
        }
        return null;
    }

    /** @return string[] UUIDs de estudios referenciados en el job */
    private function extractStudyIdsFromJobData(array $jobData): array {
        $ids = [];
        $lists = [];
        if (isset($jobData['Content']['Resources']) && is_array($jobData['Content']['Resources'])) {
            $lists[] = $jobData['Content']['Resources'];
        }
        if (isset($jobData['Content']['ModifiedResources']) && is_array($jobData['Content']['ModifiedResources'])) {
            $lists[] = $jobData['Content']['ModifiedResources'];
        }
        if (isset($jobData['Resources']) && is_array($jobData['Resources'])) {
            $lists[] = $jobData['Resources'];
        }
        foreach ($lists as $resources) {
            foreach ($resources as $resource) {
                if (is_string($resource) && preg_match('/\/studies\/([a-f0-9\-]+)/i', $resource, $m)) {
                    $ids[] = $m[1];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Tras modify a nivel paciente, localiza el estudio intermedio (nuevo) para el Paso 2.
     */
    private function findIntermediateStudyAfterPatientJob(
        string $originalStudyId,
        array $jobData,
        array $patientTags,
        ?string $originalStudyInstanceUID,
        array $originalStudyMainTags = []
    ): ?string {
        foreach ($this->extractStudyIdsFromJobData($jobData) as $candidateId) {
            if ($candidateId === $originalStudyId) {
                continue;
            }
            if ($this->studyMatchesReplaceTags($candidateId, $patientTags)) {
                error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio intermedio desde job: ' . $candidateId);
                return $candidateId;
            }
        }

        if (isset($patientTags['PatientID'])) {
            $findResponse = $this->makeRequestWithRetry(
                '/tools/find',
                'POST',
                ['Level' => 'Study', 'Query' => ['PatientID' => $patientTags['PatientID']]],
                15
            );
            if ($findResponse['success'] && !empty($findResponse['data'])) {
                $accession = $originalStudyMainTags['AccessionNumber'] ?? null;
                foreach ($findResponse['data'] as $foundStudy) {
                    $foundStudyId = strpos($foundStudy, '/studies/') !== false
                        ? str_replace('/studies/', '', $foundStudy)
                        : $foundStudy;
                    if ($foundStudyId === $originalStudyId) {
                        continue;
                    }
                    if ($accession) {
                        $check = $this->makeRequestWithRetry('/studies/' . urlencode($foundStudyId), 'GET', null, 10);
                        $acc = $check['data']['MainDicomTags']['AccessionNumber'] ?? null;
                        if ($acc !== $accession) {
                            continue;
                        }
                    }
                    if ($this->studyMatchesReplaceTags($foundStudyId, $patientTags)) {
                        error_log('[ORTHANC][UPDATE_STUDY_TAGS] Estudio intermedio por PatientID: ' . $foundStudyId);
                        return $foundStudyId;
                    }
                }
            }
        }

        if ($originalStudyInstanceUID) {
            sleep(2);
            $findResponse = $this->makeRequestWithRetry(
                '/tools/find',
                'POST',
                ['Level' => 'Study', 'Query' => ['StudyInstanceUID' => $originalStudyInstanceUID]],
                10
            );
            if ($findResponse['success'] && !empty($findResponse['data'])) {
                foreach ($findResponse['data'] as $foundStudy) {
                    $foundStudyId = strpos($foundStudy, '/studies/') !== false
                        ? str_replace('/studies/', '', $foundStudy)
                        : $foundStudy;
                    if ($foundStudyId !== $originalStudyId && $this->studyMatchesReplaceTags($foundStudyId, $patientTags)) {
                        return $foundStudyId;
                    }
                }
            }
        }

        return null;
    }

    private function studyMatchesReplaceTags(string $studyId, array $replaceTags): bool {
        $studyCheck = $this->makeRequestWithRetry('/studies/' . urlencode($studyId), 'GET', null, 10);
        if (!$studyCheck['success'] || !isset($studyCheck['data'])) {
            return false;
        }
        $main = $studyCheck['data']['MainDicomTags'] ?? [];
        $patient = $studyCheck['data']['PatientMainDicomTags'] ?? [];
        foreach ($replaceTags as $tagName => $tagValue) {
            $current = $main[$tagName] ?? $patient[$tagName] ?? null;
            if ((string) $current !== (string) $tagValue) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Espera a que un job de Orthanc se complete
     * 
     * @param string $jobId ID del job
     * @param int $maxWaitSeconds Tiempo máximo de espera en segundos
     * @return array Resultado con el nuevo study_id si está disponible
     */
    private function waitForJobCompletion($jobId, $maxWaitSeconds = 120) {
        $startTime = time();
        $checkInterval = 2; // Verificar cada 2 segundos
        
        error_log('[ORTHANC][WAIT_JOB] Iniciando espera del job: ' . $jobId);
        
        while ((time() - $startTime) < $maxWaitSeconds) {
            try {
                $jobResponse = $this->makeRequestWithRetry(
                    '/jobs/' . urlencode($jobId),
                    'GET',
                    null,
                    10
                );
                
                if ($jobResponse['success']) {
                    $jobData = $jobResponse['data'];
                    $jobState = $jobData['State'] ?? 'Unknown';
                    
                    error_log('[ORTHANC][WAIT_JOB] Estado del job: ' . $jobState);
                    
                    if ($jobState === 'Success') {
                        // El job completó exitosamente
                        // Obtener el nuevo study_id de los recursos creados
                        $newStudyId = null;
                        
                        // La estructura puede variar según la versión de Orthanc
                        if (isset($jobData['Content']['Resources'])) {
                            $resources = $jobData['Content']['Resources'];
                            if (!empty($resources) && isset($resources[0])) {
                                $newStudyId = $resources[0];
                            }
                        } elseif (isset($jobData['Content']['ModifiedResources'])) {
                            // Algunas versiones usan ModifiedResources
                            $resources = $jobData['Content']['ModifiedResources'];
                            if (!empty($resources) && isset($resources[0])) {
                                $newStudyId = $resources[0];
                            }
                        } elseif (isset($jobData['Resources'])) {
                            // O directamente en Resources
                            $resources = $jobData['Resources'];
                            if (!empty($resources) && isset($resources[0])) {
                                $newStudyId = $resources[0];
                            }
                        }
                        
                        error_log('[ORTHANC][WAIT_JOB] ✅ Job completado. Nuevo study_id: ' . ($newStudyId ?? 'NO DISPONIBLE'));
                        error_log('[ORTHANC][WAIT_JOB] Estructura completa del job: ' . json_encode($jobData));
                        
                        return [
                            'success' => true,
                            'new_study_id' => $newStudyId,
                            'job_state' => $jobState,
                            'job_data' => $jobData,
                        ];
                    } elseif ($jobState === 'Failure' || $jobState === 'Paused') {
                        $errorMsg = $jobData['ErrorDescription'] ?? ($jobData['Error'] ?? 'Job falló');
                        error_log('[ORTHANC][WAIT_JOB] ❌ Job falló: ' . $errorMsg);
                        return [
                            'success' => false,
                            'error' => $errorMsg,
                            'job_state' => $jobState
                        ];
                    }
                    // Si el estado es 'Running' o 'Pending', continuar esperando
                } else {
                    // Si no se puede obtener el job, puede que no exista aún o haya un error
                    $errorMsg = $jobResponse['error'] ?? 'Error desconocido';
                    if (strpos($errorMsg, 'Unknown resource') !== false || strpos($errorMsg, '404') !== false) {
                        error_log('[ORTHANC][WAIT_JOB] ⚠️ Job no encontrado aún, continuando espera...');
                        // Continuar esperando, puede que el job aún no esté disponible
                    } else {
                        error_log('[ORTHANC][WAIT_JOB] ❌ Error obteniendo job: ' . $errorMsg);
                        // Esperar un poco más antes de retornar error
                        if ((time() - $startTime) > 10) {
                            return [
                                'success' => false,
                                'error' => $errorMsg,
                                'job_state' => 'Error'
                            ];
                        }
                    }
                }
                
                // Esperar antes de la siguiente verificación
                sleep($checkInterval);
            } catch (Exception $e) {
                error_log('[ORTHANC][WAIT_JOB] ❌ Excepción verificando job: ' . $e->getMessage());
                // Continuar esperando si no ha pasado mucho tiempo
                if ((time() - $startTime) > 10) {
                    return [
                        'success' => false,
                        'error' => 'Error verificando job: ' . $e->getMessage(),
                        'job_state' => 'Error'
                    ];
                }
                sleep($checkInterval);
            }
        }
        
        // Timeout
        error_log('[ORTHANC][WAIT_JOB] ⏱️ Timeout esperando job: ' . $jobId);
        return [
            'success' => false,
            'error' => 'Timeout esperando que el job se complete',
            'job_id' => $jobId,
            'job_state' => 'Timeout'
        ];
    }
    
    /**
     * Actualiza un tag DICOM de una instancia usando PATCH
     * 
     * @param string $instanceId ID de la instancia
     * @param string $tag Tag DICOM en formato "0020,0011" (SeriesNumber)
     * @param string $value Valor del tag
     * @return array Resultado de la operación
     */
    private function updateInstanceTag($instanceId, $tag, $value) {
        try {
            error_log('[ORTHANC][UPDATE_TAG] Actualizando tag ' . $tag . ' de instancia ' . $instanceId . ' con valor: ' . $value);
            
            // Crear payload con el tag DICOM como clave
            $payload = [
                $tag => $value
            ];
            
            error_log('[ORTHANC][UPDATE_TAG] Payload: ' . json_encode($payload));
            
            // Realizar PATCH a /instances/{instanceId}/tags
            $response = $this->makeRequestWithRetry(
                '/instances/' . urlencode($instanceId) . '/tags',
                'PATCH',
                $payload,
                15
            );
            
            if ($response['success']) {
                error_log('[ORTHANC][UPDATE_TAG] ✅ Tag actualizado exitosamente');
                return [
                    'success' => true,
                    'message' => "Tag actualizado exitosamente",
                    'instance_id' => $instanceId,
                    'tag' => $tag,
                    'value' => $value
                ];
            } else {
                error_log('[ORTHANC][UPDATE_TAG] ❌ Error actualizando tag: ' . ($response['error'] ?? 'Error desconocido'));
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'Error desconocido al actualizar tag',
                    'instance_id' => $instanceId,
                    'tag' => $tag
                ];
            }
        } catch (Exception $e) {
            error_log('[ORTHANC][UPDATE_TAG] ❌ Excepción actualizando tag: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'instance_id' => $instanceId,
                'tag' => $tag
            ];
        }
    }
    
    /**
     * Busca la primera serie con Modality=DOC en un estudio de Orthanc.
     * Sirve para detectar si ya se subió un informe PDF a ese estudio (evita dobles envíos
     * cuando el primer intento llegó a Orthanc pero PHP murió antes de actualizar la BD).
     *
     * @param string $studyId ID del estudio en Orthanc (UUID)
     * @return array|null ['series_id'=>string, 'instance_id'=>string|null] o null si no hay DOC
     */
    /**
     * Busca la primera serie DOC en un estudio que coincida con el AccessionNumber dado.
     *
     * @param string      $studyId          Orthanc study UUID.
     * @param string|null $accessionNumber  AccessionNumber del informe que se intenta enviar.
     *                                      Si no está vacío, solo se acepta la serie DOC cuyo
     *                                      AccessionNumber (en sus instancias) coincida. Esto evita
     *                                      falsos positivos cuando un estudio ya tiene DOC series de
     *                                      otros informes distintos (mismo paciente, mismo estudio,
     *                                      pero diferentes números de informe/ACCNO).
     *                                      Si está vacío/null se acepta la primera serie DOC
     *                                      encontrada (comportamiento histórico).
     * @return array|null ['series_id'=>string, 'instance_id'=>string|null, 'accession_number'=>string]
     */
    /**
     * Lista todas las series DOC de un estudio Orthanc (para re-vinculación multiples informes).
     *
     * @return list<array{series_id:string, instance_id:?string, accession_number:string, instance_count:int, sop_class_uid:string}>
     */
    public function listDocSeriesInStudy(string $studyId): array
    {
        $studyId = trim($studyId);
        if ($studyId === '') {
            return [];
        }
        $out = [];
        try {
            $resp = $this->makeRequestWithRetry('/studies/' . urlencode($studyId), 'GET', null, 10);
            if (!$resp['success'] || empty($resp['data']['Series'])) {
                return [];
            }
            foreach ((array) $resp['data']['Series'] as $seriesId) {
                try {
                    $sr = $this->makeRequestWithRetry('/series/' . urlencode($seriesId), 'GET', null, 8);
                    if (!$sr['success']) {
                        continue;
                    }
                    $modality = $sr['data']['MainDicomTags']['Modality'] ?? '';
                    if (strtoupper($modality) !== 'DOC') {
                        continue;
                    }
                    $instances = $sr['data']['Instances'] ?? [];
                    $instanceId = !empty($instances[0]) ? (string) $instances[0] : null;
                    $seriesAccno = trim((string) ($sr['data']['MainDicomTags']['AccessionNumber'] ?? ''));
                    $sopClass = '';
                    if ($instanceId !== null) {
                        $inst = $this->makeRequestWithRetry(
                            '/instances/' . urlencode($instanceId) . '/simplified-tags',
                            'GET',
                            null,
                            5
                        );
                        if ($inst['success']) {
                            if ($seriesAccno === '') {
                                $seriesAccno = trim((string) ($inst['data']['AccessionNumber'] ?? ''));
                            }
                            $sopClass = trim((string) ($inst['data']['SOPClassUID'] ?? ''));
                        }
                    }
                    $out[] = [
                        'series_id' => (string) $seriesId,
                        'instance_id' => $instanceId,
                        'accession_number' => $seriesAccno,
                        'instance_count' => is_array($instances) ? count($instances) : 0,
                        'sop_class_uid' => $sopClass,
                    ];
                } catch (Exception $e) {
                    error_log('[ORTHANC][LIST_DOC_SERIES] Error leyendo serie ' . $seriesId . ': ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('[ORTHANC][LIST_DOC_SERIES] Error: ' . $e->getMessage());
        }
        return $out;
    }

    public function getDocSeriesInStudy(string $studyId, ?string $accessionNumber = null): ?array
    {
        $matchAccno = trim((string)($accessionNumber ?? ''));
        $all = $this->listDocSeriesInStudy($studyId);
        if (empty($all)) {
            return null;
        }

        if ($matchAccno === '') {
            $first = $all[0];
            error_log('[ORTHANC][GET_DOC_SERIES] Serie DOC encontrada (sin filtro ACCNO) en estudio ' . $studyId . ': series=' . $first['series_id']);
            return [
                'series_id' => $first['series_id'],
                'instance_id' => $first['instance_id'],
                'accession_number' => $first['accession_number'] ?? '',
            ];
        }

        foreach ($all as $series) {
            $seriesAccno = trim((string) ($series['accession_number'] ?? ''));
            if ($seriesAccno !== '' && $seriesAccno === $matchAccno) {
                error_log('[ORTHANC][GET_DOC_SERIES] Serie DOC coincidente encontrada en estudio ' . $studyId . ': series=' . $series['series_id'] . ' ACCNO=' . $seriesAccno);
                return [
                    'series_id' => $series['series_id'],
                    'instance_id' => $series['instance_id'],
                    'accession_number' => $seriesAccno,
                ];
            }
        }

        foreach ($all as $series) {
            $seriesAccno = trim((string) ($series['accession_number'] ?? ''));
            if ($seriesAccno === '') {
                error_log('[ORTHANC][GET_DOC_SERIES] Serie DOC con ACCNO vacío, aceptando como match (legado) en estudio ' . $studyId . ': series=' . $series['series_id']);
                return [
                    'series_id' => $series['series_id'],
                    'instance_id' => $series['instance_id'],
                    'accession_number' => '',
                ];
            }
            error_log('[ORTHANC][GET_DOC_SERIES] Serie DOC descartada (ACCNO ' . $seriesAccno . ' ≠ ' . $matchAccno . ') en estudio ' . $studyId . ': series=' . $series['series_id']);
        }

        return null;
    }

    /**
     * Obtener StudyInstanceUID desde un study_id de Orthanc
     * 
     * @param string $studyId ID del estudio en Orthanc
     * @return string|null StudyInstanceUID o null si no se encuentra
     */
    public function getStudyInstanceUID($studyId) {
        try {
            $response = $this->makeRequestWithRetry(
                '/studies/' . urlencode($studyId),
                'GET',
                null,
                10
            );
            
            if ($response['success'] && 
                isset($response['data']['MainDicomTags']['StudyInstanceUID'])) {
                return $response['data']['MainDicomTags']['StudyInstanceUID'];
            }
            
            return null;
        } catch (Exception $e) {
            error_log('[ORTHANC][GET_STUDY_INSTANCE_UID] Error: ' . $e->getMessage());
            return null;
        }
    }
    
    public function testConnection() {
        try {
            $response = $this->makeRequestWithRetry('/system', 'GET', null, 10);
            
            if ($response['success']) {
                return [
                    'connected' => true,
                    'version' => $response['data']['Version'] ?? 'Unknown',
                    'name' => $response['data']['Name'] ?? 'Orthanc'
                ];
            } else {
                return [
                    'connected' => false,
                    'error' => $response['error'] ?? 'Error desconocido'
                ];
            }
        } catch (Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Genera un UID DICOM único
     * 
     * @param string $prefix Prefijo para el UID (por defecto: 1.2.840.10008)
     * @return string UID generado
     */
    private static function generateUID($prefix = '1.2.840.10008') {
        $timestamp = microtime(true);
        $random = mt_rand(100000, 999999);
        $unique = uniqid('', true);
        $hash = substr(md5($unique . $timestamp . $random), 0, 12);
        
        return $prefix . '.' . date('Ymd') . '.' . $random . '.' . $hash;
    }
}

?>
