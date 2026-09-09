<?php
/**
 * Worker persistente para subir archivos a R2
 * Procesa múltiples archivos en un loop, reutilizando conexiones S3
 * 
 * Uso: php worker_upload.php <queue_file> <worker_id> <result_file>
 */

error_reporting(E_ALL);
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '0'); // Sin límite, el proceso padre controla

// Registrar handler para errores fatales
register_shutdown_function(function() use (&$resultFile) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $errorMsg = sprintf(
            "Fatal error: %s in %s on line %d",
            $error['message'],
            $error['file'],
            $error['line']
        );
        file_put_contents('php://stderr', $errorMsg . "\n", FILE_APPEND);
        
        // Escribir resultado de error si tenemos el archivo
        if (isset($resultFile)) {
            $errorResult = [
                [
                    'id'      => 'fatal_error',
                    'r2_key'  => '',
                    'success' => false,
                    'size'    => 0,
                    'error'   => $errorMsg
                ]
            ];
            @file_put_contents($resultFile, json_encode($errorResult));
        }
    }
});

if ($argc < 4) {
    fwrite(STDERR, "Uso: php worker_upload.php <queue_file> <worker_id> <result_file>\n");
    exit(1);
}

$queueFile = $argv[1];
$workerId  = $argv[2];
$resultFile = $argv[3];
$lockFile  = $queueFile . '.lock';

// Cambiar al directorio base del proyecto
// __DIR__ = /var/www/tjsiddse/modules/cloud-storage/workers
// Necesitamos: /var/www/tjsiddse
$projectRoot = realpath(__DIR__ . '/../../..');
if (!$projectRoot) {
    fwrite(STDERR, "Error: No se pudo determinar el directorio raíz del proyecto\n");
    exit(1);
}
chdir($projectRoot);

// Cargar configuración
$autoloadPath = $projectRoot . '/modules/cloud-storage/vendor/autoload.php';
$configPath = $projectRoot . '/modules/cloud-storage/config/cloud_storage_config.php';

if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Autoloader no encontrado: $autoloadPath\n");
    exit(1);
}

if (!file_exists($configPath)) {
    fwrite(STDERR, "Error: Config no encontrado: $configPath\n");
    exit(1);
}

require_once $autoloadPath;
require_once $configPath;

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Exception\AwsException;

try {
    // Cargar configuración R2
    $config = CloudStorageConfig::load();
    
    if (empty($config['r2_access_key']) || empty($config['r2_secret_key']) || empty($config['r2_account_id'])) {
        throw new Exception("Credenciales R2 incompletas");
    }
    
    // Inicializar cliente S3 UNA SOLA VEZ por worker (reutiliza conexiones)
    $s3Client = new S3Client([
        'version'              => 'latest',
        'region'               => $config['r2_region'] ?? 'auto',
        'endpoint'             => 'https://' . $config['r2_account_id'] . '.r2.cloudflarestorage.com',
        'credentials'          => [
            'key'    => $config['r2_access_key'],
            'secret' => $config['r2_secret_key']
        ],
        'use_path_style_endpoint' => true,
        'http' => [
            'verify'          => (bool)($config['r2_verify_ssl'] ?? false),
            'timeout'         => 120,
            'connect_timeout' => 10,
            'curl'            => [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE  => 30,
            ]
        ]
    ]);
    
    $bucketName = $config['r2_bucket_name'];
    $results = []; // Acumular resultados en memoria
    
    // Log inicial
    file_put_contents('php://stderr', "[$workerId] Iniciando worker. Cola: $queueFile\n", FILE_APPEND);
    
    // Loop principal: procesar archivos de la cola
    $filesProcessed = 0;
    while (true) {
        // Tomar siguiente archivo de la cola (con lock para evitar race conditions)
        $file = claimNextFile($queueFile, $lockFile, $workerId);
        
        if ($file === null) {
            // Cola vacía, terminar
            file_put_contents('php://stderr', "[$workerId] Cola vacía. Procesados: $filesProcessed\n", FILE_APPEND);
            break;
        }
        
        $fileId = $file['id'];
        $r2Key  = $file['r2_key'];
        $path   = $file['local_path'];
        
        file_put_contents('php://stderr', "[$workerId] Procesando archivo $fileId: $path → $r2Key\n", FILE_APPEND);
        
        try {
            // Verificar que el archivo existe ANTES de intentar subirlo
            if (!file_exists($path)) {
                throw new Exception("Archivo no existe: $path (puede haber sido eliminado prematuramente)");
            }
            
            if (!is_readable($path)) {
                throw new Exception("Archivo no es legible (permisos): $path");
            }
            
            $size = filesize($path);
            file_put_contents('php://stderr', "[$workerId] Archivo $fileId: $size bytes\n", FILE_APPEND);
            
            // Subir archivo a R2
            if ($size >= 5 * 1024 * 1024) {
                // MultipartUpload para archivos >= 5MB
                file_put_contents('php://stderr', "[$workerId] Usando MultipartUpload para $fileId\n", FILE_APPEND);
                $uploader = new MultipartUploader($s3Client, $path, [
                    'bucket'      => $bucketName,
                    'key'         => $r2Key,
                    'params'      => ['ContentType' => 'application/dicom'],
                    'part_size'   => 5 * 1024 * 1024,
                    'concurrency' => 3,
                ]);
                $uploader->upload();
            } else {
                // putObject para archivos pequeños usando SourceFile (más eficiente)
                file_put_contents('php://stderr', "[$workerId] Usando putObject (SourceFile) para $fileId\n", FILE_APPEND);
                $s3Client->putObject([
                    'Bucket'      => $bucketName,
                    'Key'         => $r2Key,
                    'SourceFile'  => $path,  // Usar SourceFile en lugar de Body
                    'ContentType' => 'application/dicom'
                ]);
            }
            
            file_put_contents('php://stderr', "[$workerId] ✅ Archivo $fileId subido exitosamente\n", FILE_APPEND);
            
            // Marcar como completado
            markFileDone($queueFile, $lockFile, $fileId, true);
            
            // Agregar a resultados
            $results[] = [
                'id'      => $fileId,
                'r2_key'  => $r2Key,
                'success' => true,
                'size'    => $size,
                'error'   => null
            ];
            
            $filesProcessed++;
            
            // Limpiar archivo local después de subir
            if (@unlink($path)) {
                file_put_contents('php://stderr', "[$workerId] Archivo local eliminado: $path\n", FILE_APPEND);
            } else {
                file_put_contents('php://stderr', "[$workerId] ⚠️ No se pudo eliminar archivo local: $path\n", FILE_APPEND);
            }
            
        } catch (AwsException $e) {
            $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
            $errorCode = $e->getAwsErrorCode() ?: 'AwsError';
            
            file_put_contents('php://stderr', "[$workerId] ❌ Error AWS [$errorCode] en $fileId: $errorMsg\n", FILE_APPEND);
            
            markFileDone($queueFile, $lockFile, $fileId, false, "[$errorCode] $errorMsg");
            
            $results[] = [
                'id'      => $fileId,
                'r2_key'  => $r2Key,
                'success' => false,
                'size'    => 0,
                'error'   => "[$errorCode] $errorMsg"
            ];
            
        } catch (Exception $e) {
            file_put_contents('php://stderr', "[$workerId] ❌ Error en $fileId: " . $e->getMessage() . "\n", FILE_APPEND);
            
            markFileDone($queueFile, $lockFile, $fileId, false, $e->getMessage());
            
            $results[] = [
                'id'      => $fileId,
                'r2_key'  => $r2Key,
                'success' => false,
                'size'    => 0,
                'error'   => $e->getMessage()
            ];
        }
    }
    
    // Escribir resultados consolidados
    file_put_contents('php://stderr', "[$workerId] Finalizando. Total procesados: $filesProcessed, resultados: " . count($results) . "\n", FILE_APPEND);
    
    if (file_put_contents($resultFile, json_encode($results)) === false) {
        file_put_contents('php://stderr', "[$workerId] ❌ Error escribiendo archivo de resultado: $resultFile\n", FILE_APPEND);
        exit(1);
    }
    
    file_put_contents('php://stderr', "[$workerId] ✅ Resultados escritos exitosamente a: $resultFile\n", FILE_APPEND);
    exit(0);
    
} catch (Throwable $e) {
    // Capturar cualquier excepción o error no capturado
    $errorMsg = sprintf(
        "[%s] ❌ Error fatal no capturado: %s en %s:%d\n",
        $workerId ?? 'UNKNOWN',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    file_put_contents('php://stderr', $errorMsg, FILE_APPEND);
    
    // Escribir resultado de error
    $errorResult = [
        [
            'id'      => 'exception',
            'r2_key'  => '',
            'success' => false,
            'size'    => 0,
            'error'   => $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine()
        ]
    ];
    
    if (isset($resultFile)) {
        @file_put_contents($resultFile, json_encode($errorResult));
    }
    
    exit(1);
}

/**
 * Toma el siguiente archivo pendiente de la cola (con lock)
 */
function claimNextFile($queueFile, $lockFile, $workerId) {
    if (!file_exists($queueFile)) {
        return null;
    }
    
    $fp = fopen($lockFile, 'c+');
    if (!$fp) {
        return null;
    }
    
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return null;
    }
    
    $queue = [];
    if (file_exists($queueFile)) {
        $content = file_get_contents($queueFile);
        if ($content) {
            $queue = json_decode($content, true) ?? [];
        }
    }
    
    $next = null;
    foreach ($queue as &$item) {
        if (isset($item['status']) && $item['status'] === 'pending') {
            $item['status'] = 'processing';
            $item['worker_id'] = $workerId;
            $next = $item;
            break;
        }
    }
    unset($item);
    
    file_put_contents($queueFile, json_encode($queue));
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return $next;
}

/**
 * Marca un archivo como completado o con error en la cola
 */
function markFileDone($queueFile, $lockFile, $fileId, $ok, $err = '') {
    if (!file_exists($queueFile)) {
        return;
    }
    
    $fp = fopen($lockFile, 'c+');
    if (!$fp) {
        return;
    }
    
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    
    $queue = [];
    if (file_exists($queueFile)) {
        $content = file_get_contents($queueFile);
        if ($content) {
            $queue = json_decode($content, true) ?? [];
        }
    }
    
    foreach ($queue as &$item) {
        if (isset($item['id']) && $item['id'] === $fileId) {
            $item['status'] = $ok ? 'done' : 'error';
            if ($err) {
                $item['error'] = $err;
            }
            break;
        }
    }
    unset($item);
    
    file_put_contents($queueFile, json_encode($queue));
    flock($fp, LOCK_UN);
    fclose($fp);
}
