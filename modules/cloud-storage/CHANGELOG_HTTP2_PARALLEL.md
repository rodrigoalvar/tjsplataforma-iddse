# Changelog: Implementación HTTP/2 y Upload Paralelo

## Fecha: Marzo 2026

## Resumen

Se implementó una solución completa que combina **HTTP/2 multiplexing** y **upload paralelo** para mejorar significativamente el rendimiento del upload de instancias DICOM a R2.

## Cambios Implementados

### 1. R2StorageDriver.php

#### Nuevos Métodos

- **`uploadInstancesParallel()`**: Método público para subir múltiples instancias en paralelo
  - Parámetros: `$instances`, `$maxConcurrency`, `$progressCallback`
  - Procesa instancias en lotes según `maxConcurrency`
  - Llama a `uploadBatchParallel()` para cada lote

- **`uploadBatchParallel()`**: Método privado que usa `curl_multi` para procesar un lote
  - Descarga múltiples instancias desde Orthanc en paralelo
  - Sube cada instancia a R2 después de descargar
  - Maneja errores individualmente sin detener el proceso completo

#### Modificaciones

- **`initializeS3Client()`**: 
  - Agregado `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0` para habilitar HTTP/2
  - Agregado `CURLOPT_TCP_KEEPALIVE` y `CURLOPT_TCP_KEEPIDLE` para conexiones persistentes

- **`uploadInstance()`**:
  - Agregado HTTP/2 y keep-alive para conexión a Orthanc
  - Mantiene compatibilidad con método secuencial (fallback)

### 2. R2QueueProcessor.php

#### Modificaciones

- **`uploadStudyToR2()`**: 
  - Reemplazado loop secuencial por `uploadInstancesParallel()`
  - Preparación de datos de instancias antes del upload
  - Callback de progreso para actualizar BD en tiempo real
  - Cálculo de estadísticas finales basado en resultados reales

### 3. OrthancClient.php

#### Modificaciones

- **`makeRequest()`**:
  - Agregado HTTP/2 y keep-alive para todas las conexiones
  - Mejora rendimiento de todas las operaciones con Orthanc

## Configuración

### Variables de Entorno

```env
# Número de uploads simultáneos (default: 2)
R2_UPLOAD_CONCURRENCY=4
```

### Verificación de HTTP/2

El sistema verifica automáticamente si HTTP/2 está disponible:
- Si está disponible: Se usa HTTP/2
- Si no está disponible: Se usa HTTP/1.1 (fallback automático)

## Mejoras de Rendimiento

### Antes
- Upload secuencial: ~10-15 MB/s
- Tiempo para 100 instancias: ~5-7 minutos
- Overhead: ~200ms por instancia

### Después (Esperado)
- Upload paralelo + HTTP/2: 30-50 MB/s
- Tiempo para 100 instancias: ~1-2 minutos
- Overhead: ~50ms por lote

**Mejora total esperada: 4-7x más rápido**

## Compatibilidad

### Requisitos
- cURL 7.43.0+ (para HTTP/2)
- PHP 7.0.7+ (soporte HTTP/2 en cURL)
- nghttp2 (para compilar cURL con HTTP/2)

### Fallback
Si HTTP/2 no está disponible, el sistema funciona con HTTP/1.1 pero más lento.

## Documentación

Ver `docs/HTTP2_PARALLEL_UPLOAD.md` para documentación completa:
- Arquitectura detallada
- Guía de configuración
- Troubleshooting
- Métricas y monitoreo

## Pruebas Recomendadas

1. **Probar con concurrencia baja (2):**
   ```env
   R2_UPLOAD_CONCURRENCY=2
   ```

2. **Aumentar gradualmente:**
   ```env
   R2_UPLOAD_CONCURRENCY=4
   R2_UPLOAD_CONCURRENCY=6
   ```

3. **Monitorear:**
   - Velocidad de upload en BD (`upload_speed_avg_mbps`)
   - Errores en logs
   - Recursos del servidor (CPU, memoria, red)

## Notas

- El método `uploadInstance()` (secuencial) se mantiene para compatibilidad
- El sistema detecta automáticamente si HTTP/2 está disponible
- Los errores individuales no detienen el proceso completo
- El progreso se actualiza en BD cada 5 instancias o al finalizar
