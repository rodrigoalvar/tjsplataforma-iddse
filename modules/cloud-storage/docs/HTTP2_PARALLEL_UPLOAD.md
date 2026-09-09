# Implementación de HTTP/2 y Worker Pool Pattern

## Resumen

Se ha implementado una solución completa que combina **HTTP/2**, **upload paralelo** y **Worker Pool Pattern** para mejorar significativamente el rendimiento del upload de instancias DICOM a R2.

**Nota:** El sistema ahora usa **Worker Pool Pattern** en lugar de lotes fijos. Ver `docs/WORKER_POOL_UPLOAD.md` para detalles.

## Mejoras Implementadas

### 1. HTTP/2 Multiplexing

**¿Qué es HTTP/2?**
- Protocolo que permite múltiples requests sobre una sola conexión TCP/TLS
- Elimina el overhead de handshakes repetidos
- Reduce latencia y mejora el uso del ancho de banda

**Implementación:**
- Habilitado en todas las conexiones cURL (Orthanc y R2)
- Usa `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0`
- Requiere cURL 7.43.0+ compilado con nghttp2

**Beneficio esperado:** 30-50% más rápido

### 2. Upload Paralelo con curl_multi

**¿Qué es curl_multi?**
- API de cURL que permite ejecutar múltiples requests HTTP simultáneamente
- Procesa múltiples instancias en paralelo en lugar de secuencialmente

**Implementación:**
- Nuevo método `uploadInstancesParallel()` en `R2StorageDriver`
- Procesa instancias en lotes según `r2_upload_concurrency` (configurable)
- Descarga desde Orthanc y sube a R2 en paralelo

**Beneficio esperado:** 3-5x más rápido

### 3. Conexiones Persistentes (Keep-Alive)

**Implementación:**
- `CURLOPT_TCP_KEEPALIVE = 1`
- `CURLOPT_TCP_KEEPIDLE = 60` (60 segundos)
- Reutiliza conexiones TCP existentes en lugar de crear nuevas

**Beneficio esperado:** Reduce overhead de conexiones

## Arquitectura

### Flujo Anterior (Secuencial)

```
Estudio → Instancia 1 → Descargar → Subir → Instancia 2 → Descargar → Subir → ...
         (una por una, secuencial)
```

**Problema:** Cada instancia requiere:
- Handshake TCP/TLS con Orthanc
- Handshake TCP/TLS con R2
- Espera de respuesta antes de procesar siguiente

### Flujo Nuevo (Paralelo + HTTP/2)

```
Estudio → Lote 1 [Instancia 1, Instancia 2, ...] → Descargar en paralelo → Subir en paralelo
         → Lote 2 [Instancia 3, Instancia 4, ...] → Descargar en paralelo → Subir en paralelo
```

**Ventajas:**
- Múltiples instancias procesadas simultáneamente
- Una sola conexión TCP/TLS (HTTP/2 multiplexing)
- Mejor uso del ancho de banda disponible

## Configuración

### Variables de Entorno

```env
# Número de uploads simultáneos (default: 2)
R2_UPLOAD_CONCURRENCY=4

# TTL para presigned URLs (no afecta upload)
R2_PRESIGNED_TTL=600
```

### Ajuste de Concurrencia

**Recomendaciones:**
- **Concurrencia baja (2-3):** Para conexiones lentas o servidor con recursos limitados
- **Concurrencia media (4-6):** Para la mayoría de casos
- **Concurrencia alta (8+):** Solo si tienes buen ancho de banda y CPU disponible

**Nota:** Valores muy altos pueden saturar Orthanc o R2. Empieza con 4 y ajusta según resultados.

## Código Implementado

### R2StorageDriver.php

#### Método: `uploadInstancesParallel()`

```php
public function uploadInstancesParallel(
    array $instances,           // Array de instancias a subir
    $maxConcurrency = 2,        // Número máximo de uploads simultáneos
    callable $progressCallback = null  // Callback para actualizar progreso
)
```

**Uso:**
```php
$instances = [
    [
        'instanceId' => 'abc123',
        'sopInstanceUid' => '1.2.3.4.5',
        'studyInstanceUid' => '1.2.3.4',
        'seriesInstanceUid' => '1.2.3.4.6'
    ],
    // ... más instancias
];

$results = $r2Driver->uploadInstancesParallel($instances, 4);
```

#### Método: `uploadBatchParallel()` (privado)

Procesa un lote de instancias usando `curl_multi`:
1. Inicializa `curl_multi_init()`
2. Agrega todas las descargas desde Orthanc al multi handle
3. Ejecuta todas en paralelo con `curl_multi_exec()`
4. Sube cada instancia a R2 después de descargar

### R2QueueProcessor.php

#### Modificación: `uploadStudyToR2()`

**Antes:**
```php
foreach ($instances as $instance) {
    $uploadResult = $this->r2Driver->uploadInstance(...);
    // Procesar una por una
}
```

**Ahora:**
```php
$instancesToUpload = [/* preparar datos */];
$uploadResults = $this->r2Driver->uploadInstancesParallel(
    $instancesToUpload,
    $this->maxConcurrency,
    $progressCallback
);
```

### OrthancClient.php

Habilitado HTTP/2 y keep-alive en todas las conexiones:
```php
if (defined('CURL_HTTP_VERSION_2_0')) {
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
}
curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
```

## Requisitos del Sistema

### cURL

**Versión mínima:** 7.43.0

**Verificar versión:**
```bash
php -r "echo curl_version()['version'];"
```

**Verificar soporte HTTP/2:**
```bash
php -r "var_dump(defined('CURL_HTTP_VERSION_2_0'));"
```

**Compilar con nghttp2:**
Si HTTP/2 no está disponible, cURL debe recompilarse con:
```bash
./configure --with-nghttp2
```

### PHP

**Versión mínima:** PHP 7.0.7 (soporte HTTP/2 en cURL)

**Extensiones requeridas:**
- `curl` (con soporte HTTP/2)
- `openssl`

## Métricas de Rendimiento

### Antes (Upload Secuencial)

- **Velocidad promedio:** ~10-15 MB/s
- **Tiempo para 100 instancias (50MB):** ~5-7 minutos
- **Overhead:** ~200ms por instancia (handshakes)

### Después (HTTP/2 + Paralelo)

**Esperado:**
- **Velocidad promedio:** 30-50 MB/s (3-5x mejora)
- **Tiempo para 100 instancias (50MB):** ~1-2 minutos
- **Overhead:** ~50ms por lote (handshake compartido)

**Mejora total esperada:** 4-7x más rápido

## Troubleshooting

### HTTP/2 no disponible

**Síntoma:**
```
[R2_STORAGE] WARNING: HTTP/2 no disponible en esta versión de cURL
```

**Solución:**
1. Verificar versión de cURL: `curl --version`
2. Si es < 7.43.0, actualizar cURL
3. Si es >= 7.43.0 pero HTTP/2 no funciona, recompilar PHP con cURL que tenga nghttp2

**Fallback:** El sistema funciona sin HTTP/2, pero más lento (usará HTTP/1.1)

### Errores de timeout

**Síntoma:** Timeouts durante upload paralelo

**Solución:**
1. Reducir `R2_UPLOAD_CONCURRENCY` (ej: de 4 a 2)
2. Aumentar timeouts en código si es necesario
3. Verificar ancho de banda disponible

### Errores de memoria

**Síntoma:** `Allowed memory size exhausted`

**Solución:**
1. Reducir `R2_UPLOAD_CONCURRENCY`
2. Los streams usan `php://temp/maxmemory:10485760` (10MB max por instancia)
3. Aumentar `memory_limit` en PHP si es necesario

### Orthanc sobrecargado

**Síntoma:** Orthanc responde lento o rechaza conexiones

**Solución:**
1. Reducir `R2_UPLOAD_CONCURRENCY`
2. Verificar recursos del servidor Orthanc
3. Considerar escalar Orthanc si es necesario

## Monitoreo

### Logs

El sistema registra:
- Habilitación de HTTP/2: `[R2_STORAGE] HTTP/2 habilitado para conexiones R2`
- Progreso de upload: Actualizado en BD cada 5 instancias
- Errores: Registrados en logs con detalles

### Métricas en Base de Datos

La tabla `r2_queue` almacena:
- `upload_speed_mbps`: Velocidad actual
- `upload_speed_min_mbps`: Velocidad mínima
- `upload_speed_max_mbps`: Velocidad máxima
- `upload_speed_avg_mbps`: Velocidad promedio
- `upload_duration_seconds`: Duración total

## Próximas Mejoras Posibles

1. **Adaptive Concurrency:** Ajustar concurrencia automáticamente según velocidad
2. **Retry Logic:** Reintentar instancias fallidas automáticamente
3. **Priorización:** Priorizar estudios urgentes
4. **Compresión:** Comprimir instancias antes de subir (si R2 lo soporta)

## Referencias

- [HTTP/2 Specification](https://http2.github.io/)
- [cURL Multi Interface](https://curl.se/libcurl/c/libcurl-multi.html)
- [Cloudflare R2 Documentation](https://developers.cloudflare.com/r2/)
