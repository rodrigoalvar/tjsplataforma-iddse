# Mejoras para el Método de Upload por Instancias

## Análisis del Estado Actual

El método `uploadStudyToR2()` actualmente:
- Sube instancias **secuencialmente** (una por una)
- Descarga cada instancia desde Orthanc a un stream temporal
- Sube cada instancia a R2 usando `putObject()`
- Actualiza progreso cada 5 instancias

**Problema principal**: El overhead de HTTP (handshakes, conexiones) se repite para cada instancia, lo que ralentiza el proceso.

## Mejoras Propuestas

### 1. ✅ Upload Paralelo/Concurrente (ALTA PRIORIDAD)
**Objetivo**: Subir múltiples instancias en paralelo para aprovechar mejor el ancho de banda.

**Implementación**:
- Usar `curl_multi` para descargar múltiples instancias desde Orthanc en paralelo
- Usar threads/processes o async para subir múltiples instancias a R2 simultáneamente
- Controlar concurrencia con `r2_upload_concurrency` (ya configurado)

**Beneficio esperado**: 3-5x más rápido para estudios con muchas instancias

### 2. ✅ Reutilizar Conexiones HTTP (ALTA PRIORIDAD)
**Objetivo**: Reducir overhead de handshakes TCP/TLS.

**Implementación**:
- Habilitar `CURLOPT_TCP_KEEPALIVE` y `CURLOPT_TCP_KEEPIDLE`
- Usar `curl_multi` con conexiones persistentes
- Reutilizar handles de cURL cuando sea posible

**Beneficio esperado**: 20-30% más rápido, menos latencia

### 3. ✅ Optimizar Streams (MEDIA PRIORIDAD)
**Objetivo**: Reducir uso de memoria y mejorar eficiencia.

**Implementación**:
- Usar `php://temp/maxmemory:XXX` para limitar memoria
- Stream directamente desde Orthanc a R2 cuando sea posible (sin buffer intermedio)
- Usar `MultipartUpload` para instancias grandes (>10MB)

**Beneficio esperado**: Menor uso de memoria, mejor para instancias grandes

### 4. ⚠️ Batch Uploads (BAJA PRIORIDAD - Requiere cambios en R2)
**Objetivo**: Subir múltiples archivos en una sola operación.

**Nota**: R2/S3 no soporta batch uploads nativamente. Esta mejora requeriría:
- Crear un ZIP temporal con múltiples instancias
- Subir el ZIP y luego extraerlo (similar al método ZIP que ya tenemos)

**Beneficio esperado**: Menor overhead HTTP, pero más complejidad

### 5. ✅ Pre-calcular Tamaños (BAJA PRIORIDAD)
**Objetivo**: Mejorar precisión del progreso.

**Implementación**:
- Obtener tamaños reales de instancias desde Orthanc antes de subir
- Usar `HEAD` requests o metadata de Orthanc

**Beneficio esperado**: Progreso más preciso, mejor UX

## Plan de Implementación

### Fase 1: Upload Paralelo (Implementar primero)
1. Modificar `uploadStudyToR2()` para procesar instancias en lotes
2. Usar `curl_multi` para descargar múltiples instancias en paralelo
3. Subir instancias a R2 en paralelo usando múltiples llamadas concurrentes
4. Mantener tracking de progreso y velocidades

### Fase 2: Optimización de Conexiones
1. Habilitar keep-alive en cURL
2. Reutilizar handles de cURL cuando sea posible
3. Optimizar timeouts y retries

### Fase 3: Optimización de Streams
1. Evaluar uso de streams directos (sin buffer)
2. Implementar MultipartUpload para instancias grandes
3. Optimizar uso de memoria

## Métricas de Éxito

- **Velocidad de upload**: Aumentar de ~10-15 MB/s a 30-50 MB/s
- **Tiempo total**: Reducir tiempo de upload en 50-70%
- **Uso de recursos**: Mantener o reducir uso de CPU/memoria
- **Confiabilidad**: Mantener o mejorar tasa de éxito
