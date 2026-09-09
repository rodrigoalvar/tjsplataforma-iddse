<?php
/**
 * Driver para Cloudflare R2 Storage
 * Sistema TJSMEDICAL - Cloud Storage Module
 * 
 * Usa AWS SDK PHP compatible con R2 S3 API
 */

require_once __DIR__ . '/BaseStorageDriver.php';

class R2StorageDriver extends BaseStorageDriver {
    private $s3Client;
    private $orthancUrl;
    private $orthancCredentials;
    
    public function __construct($config) {
        // Mapear r2_storage_prefix a storage_prefix para BaseStorageDriver
        if (isset($config['r2_storage_prefix']) && !isset($config['storage_prefix'])) {
            $config['storage_prefix'] = $config['r2_storage_prefix'];
        }
        
        parent::__construct($config);
        
        // Inicializar AWS SDK para R2
        $this->initializeS3Client($config);
        
        // Configuración de Orthanc para descargar instancias
        $this->orthancUrl = $config['orthanc_url'] ?? 'http://orthanc:8042';
        $this->orthancCredentials = [
            'username' => $config['orthanc_user'] ?? 'orthanc',
            'password' => $config['orthanc_pass'] ?? 'orthanc'
        ];
    }
    
    /**
     * Inicializa el cliente S3 compatible con Cloudflare R2.
     *
     * ─── CONFIGURACIÓN DEL ENDPOINT ────────────────────────────────────────────
     * El endpoint tiene el formato:
     *   https://<ACCOUNT_ID>.r2.cloudflarestorage.com
     *
     * IMPORTANTE: R2_ACCOUNT_ID debe ser el Account ID de la cuenta de Cloudflare
     * (visible en https://dash.cloudflare.com → panel derecho → "Account ID").
     * Es un string hexadecimal de exactamente 32 caracteres. Ejemplo:
     *   f655abdd6763fb88c632a31f41a1c450
     *
     * NO confundir con:
     *   - Access Key ID del token R2 (alfanumérico, ~40 chars, ej: t370ESQ1mC...)
     *   - Secret Key del token R2
     * Usar el valor incorrecto en R2_ACCOUNT_ID genera el error:
     *   "error:0A000410:SSL routines::sslv3 alert handshake failure"
     * porque el subdominio no existe en Cloudflare y rechaza el handshake TLS.
     *
     * ─── CREDENCIALES ──────────────────────────────────────────────────────────
     * Se generan en: Cloudflare Dashboard → R2 → Manage R2 API Tokens
     *   R2_ACCESS_KEY → "Access Key ID" del token (32 chars hex)
     *   R2_SECRET_KEY → "Secret Access Key" del token (64 chars hex)
     *
     * ─── OPCIONES SSL/TLS ──────────────────────────────────────────────────────
     * El servidor usa OpenSSL 3.0.2 con SECLEVEL=2 en /etc/ssl/openssl.cnf.
     * Se agregaron las opciones de cURL a continuación como medida preventiva
     * durante el diagnóstico del error SSL. Son inofensivas y pueden dejarse.
     *
     * Para revertir al comportamiento mínimo (sin opciones cURL extra):
     *   1. Eliminar el bloque $curlOptions completo
     *   2. Dejar solo $httpConfig con 'timeout', 'connect_timeout' y 'verify'
     *   3. Asegurarse de que R2_ACCOUNT_ID sea el correcto (ver arriba)
     *
     * R2_VERIFY_SSL (en .env del módulo):
     *   true  → verifica el certificado SSL de R2 (recomendado en producción)
     *   false → deshabilita verificación (solo para diagnóstico, NO usar en prod)
     */
    private function initializeS3Client($config) {
        // Intentar cargar AWS SDK (puede estar en vendor del módulo o global)
        $awsSdkPaths = [
            __DIR__ . '/../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php'
        ];
        
        $awsSdkLoaded = false;
        foreach ($awsSdkPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                $awsSdkLoaded = true;
                break;
            }
        }
        
        if (!$awsSdkLoaded && !class_exists('Aws\S3\S3Client')) {
            throw new Exception('AWS SDK PHP no está instalado. Ejecuta: composer require aws/aws-sdk-php en el directorio del módulo');
        }
        
        // Endpoint de R2: https://<ACCOUNT_ID>.r2.cloudflarestorage.com
        // Ver docblock del método para la diferencia entre Account ID y Access Key ID
        $accountId = $config['r2_account_id'] ?? '';
        if (empty($accountId)) {
            throw new Exception('R2_ACCOUNT_ID no está configurado');
        }
        
        // Leer configuración de verificación SSL desde .env (R2_VERIFY_SSL)
        // Por defecto: true (verificar certificado en producción)
        $verifySSL = true;
        if (isset($config['r2_verify_ssl'])) {
            if (is_bool($config['r2_verify_ssl'])) {
                $verifySSL = $config['r2_verify_ssl'];
            } elseif (is_string($config['r2_verify_ssl'])) {
                $verifySSL = in_array(strtolower($config['r2_verify_ssl']), ['true', '1', 'yes', 'on']);
            } elseif (is_numeric($config['r2_verify_ssl'])) {
                $verifySSL = (bool)$config['r2_verify_ssl'];
            }
        }
        
        error_log('[R2_STORAGE] Configuración SSL - verify: ' . ($verifySSL ? 'true' : 'false'));
        
        // Configuración HTTP base
        $httpConfig = [
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => $verifySSL,
        ];
        
        // ── Opciones cURL adicionales ──────────────────────────────────────────
        // Agregadas durante el diagnóstico SSL (marzo 2026). Son compatibles con
        // los otros sistemas del servidor (no modifican configuración global).
        // Ver docblock del método para instrucciones de reversión.
        $curlOptions = [];
        
        // Si R2_VERIFY_SSL=false: deshabilitar también a nivel cURL
        if (!$verifySSL) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        
        // Forzar mínimo TLS 1.2 (Cloudflare R2 requiere TLS 1.2 o superior)
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $curlOptions[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }
        
        // SECLEVEL=1: evita que OpenSSL 3.x rechace ciphers válidos de Cloudflare.
        // El sistema tiene SECLEVEL=2 en /etc/ssl/openssl.cnf (más restrictivo).
        // Esta opción opera solo en este proceso PHP, no afecta otros sistemas.
        if (defined('CURLOPT_SSL_CIPHER_LIST')) {
            $curlOptions[CURLOPT_SSL_CIPHER_LIST] = 'DEFAULT@SECLEVEL=1';
        }
        
        // Renegociación legacy (solo si la constante existe en esta versión de cURL)
        if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_ALLOW_UNSAFE_LEGACY_RENEGOTIATION')) {
            $curlOptions[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_ALLOW_UNSAFE_LEGACY_RENEGOTIATION;
        }
        
        // ── HTTP/2 para mejor rendimiento ──────────────────────────────────────
        // HTTP/2 permite multiplexing: múltiples requests sobre una sola conexión TCP/TLS
        // Esto elimina el overhead de handshakes repetidos y mejora significativamente
        // la velocidad de upload cuando hay muchas instancias.
        // Requiere: cURL 7.43.0+ compilado con nghttp2, y servidor que soporte HTTP/2
        if (defined('CURL_HTTP_VERSION_2_0')) {
            $curlOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
            error_log('[R2_STORAGE] HTTP/2 habilitado para conexiones R2');
        } else {
            error_log('[R2_STORAGE] WARNING: HTTP/2 no disponible en esta versión de cURL');
        }
        
        // Keep-alive para reutilizar conexiones
        $curlOptions[CURLOPT_TCP_KEEPALIVE] = 1;
        $curlOptions[CURLOPT_TCP_KEEPIDLE] = 60; // 60 segundos antes de enviar keep-alive
        
        if (!empty($curlOptions)) {
            $httpConfig['curl'] = $curlOptions;
            error_log('[R2_STORAGE] Configuración cURL aplicada: ' . json_encode(array_keys($curlOptions)));
        }
        
        $this->s3Client = new Aws\S3\S3Client([
            'version'                 => 'latest',
            'region'                  => $config['r2_region'] ?? 'auto',
            // Endpoint: https://<ACCOUNT_ID>.r2.cloudflarestorage.com
            // ACCOUNT_ID = Cloudflare Account ID (32 chars hex), NO el Access Key ID del token
            'endpoint'                => 'https://' . $accountId . '.r2.cloudflarestorage.com',
            'credentials'             => [
                'key'    => $config['r2_access_key'],
                'secret' => $config['r2_secret_key'],
            ],
            // path-style: requerido por R2 (no usa virtual-hosted-style como AWS S3)
            'use_path_style_endpoint' => true,
            'http'                    => $httpConfig,
        ]);
    }
    
    /**
     * Sube una instancia directamente desde Orthanc REST a R2
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param string $instanceId ID de la instancia en Orthanc
     * @param string $sopInstanceUid SOPInstanceUID
     * @param string $studyInstanceUid StudyInstanceUID
     * @param string $seriesInstanceUid SeriesInstanceUID
     * @return array ['success' => bool, 'r2_key' => string, 'size' => int]
     */
    public function uploadInstance($orthancStudyId, $instanceId, $sopInstanceUid, $studyInstanceUid, $seriesInstanceUid) {
        try {
            // 1. Calcular R2 key
            $r2Key = $this->getInstanceKey($studyInstanceUid, $seriesInstanceUid, $sopInstanceUid);
            
            // 2. Obtener stream desde Orthanc REST
            $orthancEndpoint = $this->orthancUrl . '/instances/' . $instanceId . '/file';
            
            // Usar stream temporal para transferir directamente
            $tempStream = fopen('php://temp', 'r+');
            
            $ch = curl_init($orthancEndpoint);
            curl_setopt($ch, CURLOPT_FILE, $tempStream);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            
            // Habilitar HTTP/2 para conexión a Orthanc (si está disponible)
            if (defined('CURL_HTTP_VERSION_2_0')) {
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
            
            // Keep-alive para reutilizar conexiones
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
            
            $curlSuccess = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if (!$curlSuccess || $httpCode !== 200) {
                fclose($tempStream);
                throw new Exception("Error descargando instancia desde Orthanc: HTTP $httpCode - $curlError");
            }
            
            // 3. Obtener tamaño del stream
            rewind($tempStream);
            $streamSize = fstat($tempStream)['size'];
            
            // 4. Subir directamente a R2 usando stream
            $result = $this->s3Client->putObject([
                'Bucket' => $this->config['r2_bucket_name'],
                'Key' => $r2Key,
                'Body' => $tempStream,
                'ContentType' => 'application/dicom'
            ]);
            
            fclose($tempStream);
            
            return [
                'success' => true,
                'r2_key' => $r2Key,
                'size' => $streamSize
            ];
            
        } catch (Exception $e) {
            if (isset($tempStream) && is_resource($tempStream)) {
                fclose($tempStream);
            }
            error_log('[R2_STORAGE] Error subiendo instancia: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sube múltiples instancias en paralelo usando Worker Pool Pattern
     * 
     * Este método implementa un Worker Pool que mantiene siempre N uploads activos simultáneamente.
     * Cuando un upload termina, el siguiente comienza inmediatamente, saturando el ancho de banda
     * de forma similar a como lo hace un archivo grande.
     * 
     * **Worker Pool Pattern:**
     * - Mantiene siempre `maxConcurrency` uploads activos
     * - Cuando uno termina, inicia el siguiente inmediatamente
     * - No espera a que termine todo un lote antes de empezar el siguiente
     * - Optimiza el uso del ancho de banda disponible
     * 
     * **Flujo:**
     * 1. Inicia `maxConcurrency` descargas desde Orthanc en paralelo
     * 2. Cuando una descarga termina:
     *    a. Sube inmediatamente a R2
     *    b. Inicia la siguiente descarga desde Orthanc
     * 3. Repite hasta procesar todas las instancias
     * 
     * @param array $instances Array de arrays con:
     *   - 'instanceId' => ID de instancia en Orthanc
     *   - 'sopInstanceUid' => SOPInstanceUID
     *   - 'studyInstanceUid' => StudyInstanceUID
     *   - 'seriesInstanceUid' => SeriesInstanceUID
     *   - 'size' => Tamaño estimado (opcional, para referencia)
     * @param int $maxConcurrency Número máximo de uploads simultáneos (Worker Pool size)
     * @param callable|null $progressCallback Función callback($uploaded, $total, $totalBytesUploaded)
     * @return array Array de resultados ['success' => bool, 'r2_key' => string, 'size' => int, 'error' => string|null]
     */
    public function uploadInstancesParallel(array $instances, $maxConcurrency = 10, callable $progressCallback = null) {
        $results = [];
        $totalInstances = count($instances);
        $processed = 0;
        $totalBytesUploaded = 0;
        $startTime = microtime(true);
        
        if ($totalInstances === 0) {
            return $results;
        }
        
        // Worker Pool: mantener siempre maxConcurrency uploads activos
        $multiHandle = curl_multi_init();
        $activeDownloads = []; // [instanceIndex => ['handle' => curl, 'stream' => resource, 'instance' => array]]
        $nextInstanceIndex = 0;
        
        // Inicializar pool: empezar con maxConcurrency descargas
        while (count($activeDownloads) < $maxConcurrency && $nextInstanceIndex < $totalInstances) {
            $this->startDownloadFromOrthanc($instances[$nextInstanceIndex], $multiHandle, $activeDownloads, $nextInstanceIndex);
            $nextInstanceIndex++;
        }
        
        // Worker Pool Loop: mantener siempre el pool lleno
        while (count($activeDownloads) > 0 || $nextInstanceIndex < $totalInstances) {
            // Ejecutar descargas activas
            $running = null;
            $mrc = curl_multi_exec($multiHandle, $running);
            
            // Procesar descargas completadas
            while (($info = curl_multi_info_read($multiHandle)) !== false) {
                if ($info['msg'] === CURLMSG_DONE) {
                    $completedHandle = $info['handle'];
                    
                    // Encontrar qué instancia corresponde a este handle
                    $completedInstanceIndex = null;
                    foreach ($activeDownloads as $idx => $download) {
                        if ($download['handle'] === $completedHandle) {
                            $completedInstanceIndex = $idx;
                            break;
                        }
                    }
                    
                    if ($completedInstanceIndex === null) {
                        continue; // Handle no encontrado, saltar
                    }
                    
                    $download = $activeDownloads[$completedInstanceIndex];
                    $instance = $download['instance'];
                    
                    // Remover handle del multi
                    curl_multi_remove_handle($multiHandle, $completedHandle);
                    unset($activeDownloads[$completedInstanceIndex]);
                    
                    // Procesar descarga completada: subir a R2
                    $result = $this->processDownloadedInstance($completedHandle, $download['stream'], $instance);
                    $results[] = $result;
                    $processed++;
                    
                    // Acumular bytes reales subidos
                    if ($result['success']) {
                        $totalBytesUploaded += $result['size'];
                    }
                    
                    // Llamar callback de progreso
                    if ($progressCallback !== null && is_callable($progressCallback)) {
                        $progressCallback($processed, $totalInstances, $totalBytesUploaded);
                    }
                    
                    // Limpiar handle
                    curl_close($completedHandle);
                    
                    // Iniciar siguiente descarga inmediatamente (mantener pool lleno)
                    if ($nextInstanceIndex < $totalInstances) {
                        $this->startDownloadFromOrthanc($instances[$nextInstanceIndex], $multiHandle, $activeDownloads, $nextInstanceIndex);
                        $nextInstanceIndex++;
                    }
                }
            }
            
            // Si no hay descargas completadas pero aún quedan por iniciar, llenar el pool
            if (count($activeDownloads) < $maxConcurrency && $nextInstanceIndex < $totalInstances) {
                // Iniciar más descargas para llenar el pool
                while (count($activeDownloads) < $maxConcurrency && $nextInstanceIndex < $totalInstances) {
                    $this->startDownloadFromOrthanc($instances[$nextInstanceIndex], $multiHandle, $activeDownloads, $nextInstanceIndex);
                    $nextInstanceIndex++;
                }
            }
            
            // Si hay descargas activas pero ninguna completada, esperar un poco
            if ($running > 0) {
                curl_multi_select($multiHandle, 0.1); // Esperar 100ms antes de verificar de nuevo
            }
        }
        
        curl_multi_close($multiHandle);
        
        return $results;
    }
    
    /**
     * Inicia una descarga desde Orthanc y la agrega al Worker Pool
     * 
     * @param array $instance Datos de la instancia
     * @param resource $multiHandle Handle de curl_multi
     * @param array &$activeDownloads Array de descargas activas (por referencia)
     * @param int $instanceIndex Índice de la instancia en el array original
     */
    private function startDownloadFromOrthanc($instance, $multiHandle, &$activeDownloads, $instanceIndex) {
        $instanceId = $instance['instanceId'];
        $orthancEndpoint = $this->orthancUrl . '/instances/' . $instanceId . '/file';
        
        // Crear stream temporal para la descarga
        $tempStream = fopen('php://temp/maxmemory:10485760', 'r+'); // 10MB max en memoria
        
        // Configurar cURL para descargar desde Orthanc
        $ch = curl_init($orthancEndpoint);
        curl_setopt($ch, CURLOPT_FILE, $tempStream);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        // HTTP/2 y keep-alive
        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
        
        // Agregar al multi handle
        curl_multi_add_handle($multiHandle, $ch);
        
        // Guardar en activeDownloads
        $activeDownloads[$instanceIndex] = [
            'handle' => $ch,
            'stream' => $tempStream,
            'instance' => $instance
        ];
    }
    
    /**
     * Procesa una instancia descargada: sube a R2 y retorna resultado
     * 
     * @param resource $handle Handle de cURL completado
     * @param resource $stream Stream con el contenido descargado
     * @param array $instance Datos de la instancia
     * @return array Resultado ['success' => bool, 'r2_key' => string, 'size' => int, 'error' => string|null]
     */
    private function processDownloadedInstance($handle, $stream, $instance) {
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        
        if ($httpCode !== 200 || $curlError) {
            fclose($stream);
            return [
                'success' => false,
                'r2_key' => null,
                'size' => 0,
                'error' => "Error descargando desde Orthanc: HTTP $httpCode - $curlError"
            ];
        }
        
        // Obtener tamaño del stream
        rewind($stream);
        $streamSize = fstat($stream)['size'];
        
        // Calcular R2 key
        $r2Key = $this->getInstanceKey(
            $instance['studyInstanceUid'],
            $instance['seriesInstanceUid'],
            $instance['sopInstanceUid']
        );
        
        // Subir a R2
        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->config['r2_bucket_name'],
                'Key' => $r2Key,
                'Body' => $stream,
                'ContentType' => 'application/dicom'
            ]);
            
            fclose($stream);
            
            return [
                'success' => true,
                'r2_key' => $r2Key,
                'size' => $streamSize,
                'error' => null
            ];
        } catch (Exception $e) {
            fclose($stream);
            return [
                'success' => false,
                'r2_key' => $r2Key,
                'size' => 0,
                'error' => 'Error subiendo a R2: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Sube un lote de instancias en paralelo usando curl_multi
     * 
     * @param array $batch Array de instancias a procesar en paralelo
     * @return array Array de resultados
     */
    private function uploadBatchParallel(array $batch) {
        $multiHandle = curl_multi_init();
        $handles = [];
        $streams = [];
        $instanceData = [];
        
        // Preparar todas las descargas desde Orthanc en paralelo
        foreach ($batch as $index => $instance) {
            $instanceId = $instance['instanceId'];
            $orthancEndpoint = $this->orthancUrl . '/instances/' . $instanceId . '/file';
            
            // Crear stream temporal para cada instancia
            $tempStream = fopen('php://temp/maxmemory:10485760', 'r+'); // 10MB max en memoria
            $streams[$index] = $tempStream;
            
            // Configurar cURL para descargar desde Orthanc
            $ch = curl_init($orthancEndpoint);
            curl_setopt($ch, CURLOPT_FILE, $tempStream);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            
            // HTTP/2 y keep-alive
            if (defined('CURL_HTTP_VERSION_2_0')) {
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
            
            // Guardar metadata de la instancia
            $instanceData[$index] = $instance;
            
            // Agregar al multi handle
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$index] = $ch;
        }
        
        // Ejecutar todas las descargas en paralelo
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle, 0.1); // Esperar 100ms antes de verificar de nuevo
        } while ($running > 0);
        
        // Procesar resultados y subir a R2
        $results = [];
        foreach ($handles as $index => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $instance = $instanceData[$index];
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
            
            if ($httpCode !== 200 || $curlError) {
                fclose($streams[$index]);
                $results[] = [
                    'success' => false,
                    'r2_key' => null,
                    'size' => 0,
                    'error' => "Error descargando desde Orthanc: HTTP $httpCode - $curlError"
                ];
                continue;
            }
            
            // Obtener tamaño del stream
            rewind($streams[$index]);
            $streamSize = fstat($streams[$index])['size'];
            
            // Calcular R2 key
            $r2Key = $this->getInstanceKey(
                $instance['studyInstanceUid'],
                $instance['seriesInstanceUid'],
                $instance['sopInstanceUid']
            );
            
            // Subir a R2
            try {
                $result = $this->s3Client->putObject([
                    'Bucket' => $this->config['r2_bucket_name'],
                    'Key' => $r2Key,
                    'Body' => $streams[$index],
                    'ContentType' => 'application/dicom'
                ]);
                
                fclose($streams[$index]);
                
                $results[] = [
                    'success' => true,
                    'r2_key' => $r2Key,
                    'size' => $streamSize,
                    'error' => null
                ];
            } catch (Exception $e) {
                fclose($streams[$index]);
                $results[] = [
                    'success' => false,
                    'r2_key' => $r2Key,
                    'size' => 0,
                    'error' => 'Error subiendo a R2: ' . $e->getMessage()
                ];
            }
        }
        
        curl_multi_close($multiHandle);
        
        return $results;
    }
    
    /**
     * Obtiene un objeto del almacenamiento
     */
    public function getObject($objectKey) {
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->config['r2_bucket_name'],
                'Key' => $objectKey
            ]);
            
            return (string)$result['Body'];
        } catch (Exception $e) {
            error_log('[R2_STORAGE] Error obteniendo objeto: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sube un objeto al almacenamiento
     */
    public function putObject($objectKey, $content, $contentType = 'application/json') {
        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->config['r2_bucket_name'],
                'Key' => $objectKey,
                'Body' => $content,
                'ContentType' => $contentType
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log('[R2_STORAGE] Error subiendo objeto: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * HeadObject en R2: devuelve ContentLength si el objeto existe; null si no existe (404).
     * Sirve para omitir re-subidas en jobs de re-sync cuando el tamaño coincide con Orthanc.
     *
     * @throws Exception si el error no es "no encontrado"
     */
    public function getObjectContentLengthIfExists(string $objectKey): ?int {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->config['r2_bucket_name'],
                'Key' => $objectKey,
            ]);
            return isset($result['ContentLength']) ? (int) $result['ContentLength'] : null;
        } catch (\Throwable $e) {
            $code = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';
            $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 0;
            if ($status === 404 || $code === 'NotFound' || stripos($e->getMessage(), 'Not Found') !== false) {
                return null;
            }
            error_log('[R2_STORAGE] headObject error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sube un archivo al almacenamiento desde una ruta local
     * Usa el mismo método que uploadInstance: leer el archivo completo en memoria
     * @param string $objectKey Key del objeto en R2
     * @param string $filePath Ruta del archivo local
     * @param string $contentType Tipo MIME del archivo
     * @param callable|null $progressCallback Callback para actualizar progreso: function($bytesUploaded, $totalBytes, $speedMbps)
     * @return bool
     */
    public function putObjectFromFile($objectKey, $filePath, $contentType = 'application/octet-stream', $progressCallback = null) {
        try {
            if (!file_exists($filePath)) {
                throw new Exception("Archivo no existe: $filePath");
            }
            
            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                throw new Exception("No se pudo obtener tamaño del archivo: $filePath");
            }
            
            // Validar parámetros antes de procesar
            if (!is_string($objectKey)) {
                error_log('[R2_STORAGE] ERROR: objectKey no es string, tipo: ' . gettype($objectKey) . ', valor: ' . var_export($objectKey, true));
                throw new Exception("Error: objectKey debe ser string, recibido: " . gettype($objectKey));
            }
            
            $bucketName = $this->config['r2_bucket_name'];
            if (!is_string($bucketName)) {
                error_log('[R2_STORAGE] ERROR: bucketName no es string, tipo: ' . gettype($bucketName) . ', valor: ' . var_export($bucketName, true));
                throw new Exception("Error: bucketName debe ser string, recibido: " . gettype($bucketName));
            }
            
            // Validar que filePath sea un string (ruta de archivo)
            if (!is_string($filePath)) {
                error_log('[R2_STORAGE] ERROR: filePath no es string, tipo: ' . gettype($filePath) . ', valor: ' . var_export($filePath, true));
                throw new Exception("Error: filePath debe ser string (ruta de archivo), recibido: " . gettype($filePath));
            }
            
            // Verificar que el archivo existe y es legible
            if (!is_readable($filePath)) {
                throw new Exception("Error: archivo no es legible: $filePath");
            }
            
            // Para archivos medianos/grandes (>5MB), usar MultipartUpload con callbacks para medir velocidad
            // Para archivos pequeños, leer en memoria (más simple)
            if ($fileSize > 5 * 1024 * 1024) {
                // Usar MultipartUpload para archivos medianos/grandes
                error_log("[R2_STORAGE] Usando MultipartUpload para archivo: Key=$objectKey, Size=$fileSize bytes, Bucket=$bucketName, FilePath=$filePath");
                
                // Asegurarse de que SourceFile sea una ruta de archivo válida (string)
                $sourceFile = realpath($filePath);
                if ($sourceFile === false) {
                    throw new Exception("Error: no se pudo resolver ruta real del archivo: $filePath");
                }
                
                // Validar que todos los parámetros sean del tipo correcto antes de crear MultipartUploader
                if (!is_string($bucketName)) {
                    throw new Exception("Error: bucketName debe ser string, recibido: " . gettype($bucketName));
                }
                if (!is_string($objectKey)) {
                    throw new Exception("Error: objectKey debe ser string, recibido: " . gettype($objectKey));
                }
                if (!is_string($sourceFile)) {
                    throw new Exception("Error: sourceFile debe ser string, recibido: " . gettype($sourceFile));
                }
                if (!is_string($contentType)) {
                    throw new Exception("Error: contentType debe ser string, recibido: " . gettype($contentType));
                }
                
                // Verificar que el archivo existe antes de crear el uploader
                if (!file_exists($sourceFile)) {
                    throw new Exception("Error: archivo no existe: $sourceFile");
                }
                
                error_log("[R2_STORAGE] Creando MultipartUploader con: Bucket=$bucketName, Key=$objectKey, SourceFile=$sourceFile, ContentType=$contentType");
                
                // Variables para rastrear progreso y velocidad durante MultipartUpload
                $uploadStartTime = microtime(true);
                $partStartTime = $uploadStartTime;
                $speedSamples = [];
                $speedMin = null;
                $speedMax = null;
                $partsCompleted = 0;
                $estimatedPartSize = 5 * 1024 * 1024; // 5MB por parte (tamaño por defecto de MultipartUpload)
                
                // MultipartUploader constructor: (client, source, config)
                // source: ruta del archivo (string) o stream
                // config: array con 'bucket', 'key', 'params' (para ContentType), etc.
                $uploaderConfig = [
                    'bucket' => $bucketName,  // lowercase 'bucket', no 'Bucket'
                    'key' => $objectKey,      // lowercase 'key', no 'Key'
                    'params' => [             // Parámetros adicionales para los comandos
                        'ContentType' => $contentType
                    ],
                    'part_size' => $estimatedPartSize // Usar tamaño de parte conocido para mejor estimación
                ];
                
                // Agregar callback para medir velocidad durante el upload de cada parte
                if ($progressCallback !== null) {
                    $uploaderConfig['before_upload'] = function($command) use (&$partsCompleted, &$partStartTime, &$speedSamples, &$speedMin, &$speedMax, $fileSize, $estimatedPartSize, $progressCallback, $uploadStartTime) {
                        // Este callback se ejecuta antes de cada parte del upload
                        $currentTime = microtime(true);
                        
                        // Si no es la primera parte, calcular velocidad de la parte anterior
                        if ($partsCompleted > 0) {
                            $partDuration = $currentTime - $partStartTime;
                            if ($partDuration > 0) {
                                // Calcular velocidad de la parte anterior (asumiendo tamaño estimado)
                                $partSpeed = ($estimatedPartSize / 1024 / 1024) / $partDuration;
                                $speedSamples[] = $partSpeed;
                                
                                // Actualizar min/max
                                if ($speedMin === null || $partSpeed < $speedMin) {
                                    $speedMin = $partSpeed;
                                }
                                if ($speedMax === null || $partSpeed > $speedMax) {
                                    $speedMax = $partSpeed;
                                }
                                
                                // Calcular velocidad promedio hasta ahora
                                $avgSpeed = count($speedSamples) > 0 ? (array_sum($speedSamples) / count($speedSamples)) : 0;
                                
                                // Estimar bytes subidos (partes completadas * tamaño de parte)
                                // Nota: partsCompleted ya incluye la parte que acaba de completarse
                                $bytesUploaded = min($fileSize, $partsCompleted * $estimatedPartSize);
                                
                                // Llamar al callback de progreso
                                if (is_callable($progressCallback)) {
                                    $progressCallback($bytesUploaded, $fileSize, $partSpeed, $speedMin, $speedMax, $avgSpeed);
                                }
                            }
                        }
                        
                        // Actualizar para la siguiente parte
                        $partStartTime = $currentTime;
                        $partsCompleted++;
                    };
                }
                
                // Validar que el array de configuración no contenga arrays anidados problemáticos
                foreach ($uploaderConfig as $key => $value) {
                    if (in_array($key, ['params', 'before_upload']) && (is_array($value) || is_callable($value))) {
                        // Estos pueden ser arrays o callables, está bien
                        continue;
                    }
                    if (is_array($value)) {
                        error_log("[R2_STORAGE] ERROR: Config contiene array en clave '$key': " . var_export($value, true));
                        throw new Exception("Error: configuración de MultipartUploader contiene array en clave '$key'");
                    }
                }
                
                // Constructor: MultipartUploader(client, source, config)
                // source = ruta del archivo (string)
                // config = array con bucket, key, params, etc.
                $uploader = new \Aws\S3\MultipartUploader($this->s3Client, $sourceFile, $uploaderConfig);
                
                $result = $uploader->upload();
                
                // Si hay callback, llamarlo una última vez con el progreso final
                if ($progressCallback !== null && is_callable($progressCallback)) {
                    $uploadEndTime = microtime(true);
                    $totalDuration = $uploadEndTime - $uploadStartTime;
                    $finalAvgSpeed = $totalDuration > 0 ? ($fileSize / 1024 / 1024) / $totalDuration : 0;
                    
                    // Si no tenemos muestras, usar la velocidad promedio total
                    if (count($speedSamples) === 0) {
                        $speedMin = $finalAvgSpeed;
                        $speedMax = $finalAvgSpeed;
                        $finalAvgSpeed = $finalAvgSpeed;
                    } else {
                        // Asegurar que min/max estén actualizados
                        if ($speedMin === null) $speedMin = min($speedSamples);
                        if ($speedMax === null) $speedMax = max($speedSamples);
                        $finalAvgSpeed = array_sum($speedSamples) / count($speedSamples);
                    }
                    
                    $progressCallback($fileSize, $fileSize, $finalAvgSpeed, $speedMin, $speedMax, $finalAvgSpeed);
                }
                
                return true;
            } else {
                // Para archivos pequeños, leer en memoria (similar a putObject)
                $fileContent = file_get_contents($filePath);
                if ($fileContent === false) {
                    throw new Exception("No se pudo leer archivo: $filePath");
                }
                
                // Validar tipos antes de llamar a putObject
                if (!is_string($fileContent)) {
                    error_log('[R2_STORAGE] ERROR: fileContent no es string, tipo: ' . gettype($fileContent));
                    throw new Exception("Error: fileContent debe ser string, recibido: " . gettype($fileContent));
                }
                
                if (!is_string($objectKey)) {
                    error_log('[R2_STORAGE] ERROR: objectKey no es string, tipo: ' . gettype($objectKey) . ', valor: ' . var_export($objectKey, true));
                    throw new Exception("Error: objectKey debe ser string, recibido: " . gettype($objectKey));
                }
                
                $bucketName = $this->config['r2_bucket_name'];
                if (!is_string($bucketName)) {
                    error_log('[R2_STORAGE] ERROR: bucketName no es string, tipo: ' . gettype($bucketName) . ', valor: ' . var_export($bucketName, true));
                    throw new Exception("Error: bucketName debe ser string, recibido: " . gettype($bucketName));
                }
                
                error_log("[R2_STORAGE] Subiendo ZIP: Key=$objectKey, Size=" . strlen($fileContent) . " bytes, Bucket=$bucketName");
                
                $result = $this->s3Client->putObject([
                    'Bucket' => $bucketName,
                    'Key' => $objectKey,
                    'Body' => $fileContent,
                    'ContentType' => $contentType
                ]);
                
                return true;
            }
        } catch (Exception $e) {
            error_log('[R2_STORAGE] Error subiendo archivo: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Genera una URL presignada para un objeto
     */
    public function generatePresignedUrl($objectKey, $ttl = 600) {
        try {
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->config['r2_bucket_name'],
                'Key' => $objectKey
            ]);
            
            $request = $this->s3Client->createPresignedRequest($cmd, '+' . $ttl . ' seconds');
            $url = (string)$request->getUri();
            
            // Si hay R2_CUSTOM_DOMAIN configurado, intentar usarlo
            // Si no está configurado o está vacío, usar el endpoint interno de R2 (funciona perfectamente)
            $customDomain = trim($this->config['r2_custom_domain'] ?? '');
            if (!empty($customDomain)) {
                // Reemplazar el host por el custom domain
                $parsed = parse_url($url);
                $customDomain = rtrim($customDomain, '/');
                $url = $customDomain . $parsed['path'] . '?' . ($parsed['query'] ?? '');
            }
            // Si customDomain está vacío, la URL ya viene con el endpoint interno de R2
            
            return $url;
        } catch (Exception $e) {
            error_log('[R2_STORAGE] Error generando presigned URL: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Verifica la conexión con R2
     */
    public function testConnection() {
        try {
            $this->s3Client->headBucket([
                'Bucket' => $this->config['r2_bucket_name']
            ]);
            return true;
        } catch (AwsException $e) {
            error_log('[R2_STORAGE] Error AWS en test de conexión: ' . $e->getMessage());
            error_log('[R2_STORAGE] AWS Error Code: ' . $e->getAwsErrorCode());
            throw $e; // Re-lanzar para que el endpoint pueda capturar el error específico
        } catch (Exception $e) {
            error_log('[R2_STORAGE] Error en test de conexión: ' . $e->getMessage());
            throw $e; // Re-lanzar para que el endpoint pueda capturar el error específico
        }
    }
}
