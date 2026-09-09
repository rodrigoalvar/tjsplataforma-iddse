# Worker Pool Pattern para Upload Paralelo

## Resumen

Se implementó un **Worker Pool Pattern** para mantener siempre N uploads activos simultáneamente, optimizando el uso del ancho de banda y mejorando significativamente el rendimiento del upload de instancias DICOM a R2.

## ¿Qué es el Worker Pool Pattern?

El Worker Pool Pattern mantiene un número constante de "workers" (uploads) activos simultáneamente. Cuando un worker termina, el siguiente comienza inmediatamente, sin esperar a que terminen todos los workers del lote.

### Comparación: Lotes Fijos vs Worker Pool

#### Sistema Anterior (Lotes Fijos)
```
Lote 1: [Upload 1, Upload 2, Upload 3, Upload 4] → Esperar a que TODOS terminen
Lote 2: [Upload 5, Upload 6, Upload 7, Upload 8] → Esperar a que TODOS terminen
Lote 3: [Upload 9, Upload 10, ...] → etc.
```

**Problema:** Si Upload 1 termina rápido pero Upload 4 tarda mucho, los slots 2 y 3 quedan inactivos esperando.

#### Sistema Nuevo (Worker Pool)
```
Pool (10 workers): [W1, W2, W3, W4, W5, W6, W7, W8, W9, W10]
W1 termina → Inicia W11 inmediatamente
W3 termina → Inicia W12 inmediatamente
W7 termina → Inicia W13 inmediatamente
...
```

**Ventaja:** El pool siempre está ocupado, saturando el ancho de banda disponible.

## Arquitectura

### Flujo del Worker Pool

```
1. Inicializar Pool
   └─> Iniciar maxConcurrency descargas desde Orthanc

2. Loop Principal (Worker Pool)
   ├─> Ejecutar descargas activas (curl_multi_exec)
   ├─> Detectar descargas completadas
   ├─> Para cada descarga completada:
   │   ├─> Subir a R2 inmediatamente
   │   ├─> Actualizar progreso
   │   └─> Iniciar siguiente descarga (mantener pool lleno)
   └─> Repetir hasta procesar todas las instancias
```

### Componentes

#### 1. `uploadInstancesParallel()` - Orquestador Principal
- Inicializa el Worker Pool
- Mantiene el loop principal
- Gestiona el ciclo de vida de los workers

#### 2. `startDownloadFromOrthanc()` - Iniciador de Workers
- Crea una nueva descarga desde Orthanc
- Configura HTTP/2 y keep-alive
- Agrega al pool activo

#### 3. `processDownloadedInstance()` - Procesador de Workers Completados
- Sube el archivo descargado a R2
- Retorna resultado (éxito/error)
- Limpia recursos

## Configuración

### Variable de Entorno

```env
# Número de uploads simultáneos (Worker Pool size)
# Recomendado: 10-20 para saturar ancho de banda
R2_UPLOAD_CONCURRENCY=15
```

### Valores Recomendados

| Ancho de Banda | Concurrencia Recomendada | Notas |
|----------------|--------------------------|-------|
| < 10 Mbps | 5-8 | Evitar saturar conexión |
| 10-50 Mbps | 10-15 | Balance óptimo |
| 50-100 Mbps | 15-20 | Máximo rendimiento |
| > 100 Mbps | 20-30 | Solo si CPU/memoria lo permiten |

**Nota:** Valores muy altos pueden:
- Saturar Orthanc (demasiadas conexiones)
- Consumir mucha memoria (cada worker usa ~10MB)
- Causar timeouts en R2

## Ventajas del Worker Pool

### 1. Mejor Uso del Ancho de Banda
- **Antes:** Ancho de banda ocioso entre lotes
- **Ahora:** Ancho de banda siempre ocupado

### 2. Mejor para Instancias de Tamaños Variables
- Instancias pequeñas terminan rápido y liberan slots
- Instancias grandes no bloquean todo el proceso
- El pool se adapta automáticamente

### 3. Menor Latencia Total
- No hay esperas innecesarias entre lotes
- Procesamiento continuo y fluido

### 4. Escalabilidad
- Fácil ajustar concurrencia según recursos disponibles
- Funciona bien con cualquier número de instancias

## Implementación Técnica

### Estructura de Datos

```php
$activeDownloads = [
    $instanceIndex => [
        'handle' => $curlHandle,      // Handle de cURL
        'stream' => $tempStream,      // Stream temporal con datos
        'instance' => $instanceData   // Metadata de la instancia
    ]
]
```

### Loop Principal

```php
while (count($activeDownloads) > 0 || $nextInstanceIndex < $totalInstances) {
    // 1. Ejecutar descargas activas
    curl_multi_exec($multiHandle, $running);
    
    // 2. Procesar completadas
    while (($info = curl_multi_info_read($multiHandle)) !== false) {
        if ($info['msg'] === CURLMSG_DONE) {
            // Subir a R2
            $result = processDownloadedInstance(...);
            
            // Iniciar siguiente inmediatamente
            startDownloadFromOrthanc(...);
        }
    }
}
```

### Gestión de Recursos

- **Streams temporales:** `php://temp/maxmemory:10485760` (10MB max)
- **Limpieza:** Se cierran automáticamente después de subir a R2
- **Handles cURL:** Se cierran después de procesar

## Métricas de Rendimiento

### Antes (Lotes Fijos, concurrency=4)
- **Velocidad promedio:** ~15-20 MB/s
- **Tiempo para 100 instancias:** ~4-5 minutos
- **Uso de ancho de banda:** ~60-70% (pausas entre lotes)

### Después (Worker Pool, concurrency=15)
**Esperado:**
- **Velocidad promedio:** 40-60 MB/s (2-3x mejora)
- **Tiempo para 100 instancias:** ~1.5-2 minutos
- **Uso de ancho de banda:** ~90-95% (pool siempre ocupado)

**Mejora total esperada:** 2-3x más rápido

## Monitoreo y Debugging

### Logs

El sistema registra:
- Inicio de cada descarga
- Completado de cada upload
- Errores individuales (no detienen el proceso)

### Métricas en Base de Datos

La tabla `r2_queue` almacena:
- `upload_speed_avg_mbps`: Velocidad promedio
- `upload_speed_min_mbps`: Velocidad mínima
- `upload_speed_max_mbps`: Velocidad máxima
- `upload_duration_seconds`: Duración total

### Verificación del Pool

Para verificar que el pool está funcionando:
1. Monitorear logs: deberías ver uploads completándose continuamente
2. Verificar velocidades: deberían ser más altas y consistentes
3. Observar progreso: debería avanzar de forma más fluida

## Troubleshooting

### Pool no se mantiene lleno

**Síntoma:** Velocidad baja, uploads secuenciales

**Posibles causas:**
1. `maxConcurrency` muy bajo
2. Orthanc rechazando conexiones (límite de conexiones)
3. Timeouts muy cortos

**Solución:**
- Aumentar `R2_UPLOAD_CONCURRENCY` gradualmente
- Verificar logs de Orthanc
- Aumentar timeouts si es necesario

### Errores de memoria

**Síntoma:** `Allowed memory size exhausted`

**Causa:** Demasiados workers activos consumiendo memoria

**Solución:**
- Reducir `R2_UPLOAD_CONCURRENCY`
- Aumentar `memory_limit` en PHP
- Los streams usan `maxmemory:10485760` (10MB max cada uno)

### Orthanc sobrecargado

**Síntoma:** Timeouts, errores HTTP 503

**Solución:**
- Reducir `R2_UPLOAD_CONCURRENCY`
- Verificar recursos del servidor Orthanc
- Considerar escalar Orthanc si es necesario

### Velocidad no mejora

**Posibles causas:**
1. Ancho de banda limitado (no es problema del código)
2. R2 limitando velocidad (rate limiting)
3. CPU del servidor saturada

**Solución:**
- Verificar ancho de banda disponible
- Monitorear CPU durante uploads
- Ajustar concurrencia según recursos

## Comparación con Otros Patrones

### vs. Lotes Fijos (Anterior)
- ✅ Mejor uso de ancho de banda
- ✅ Mejor para tamaños variables
- ✅ Menor latencia total
- ⚠️ Ligeramente más complejo

### vs. Secuencial
- ✅ 10-20x más rápido
- ✅ Mejor uso de recursos
- ⚠️ Más consumo de memoria
- ⚠️ Más complejidad

### vs. Async/Await (Futuro)
- ⚠️ Menos eficiente que async puro
- ✅ Funciona en PHP sin extensiones especiales
- ✅ Compatible con código existente

## Próximas Mejoras Posibles

1. **Adaptive Concurrency:** Ajustar concurrencia automáticamente según velocidad
2. **Priorización:** Priorizar instancias urgentes
3. **Retry Logic:** Reintentar automáticamente instancias fallidas
4. **Streaming Directo:** Stream desde Orthanc a R2 sin buffer intermedio (reduce memoria)

## Referencias

- [Worker Pool Pattern](https://en.wikipedia.org/wiki/Thread_pool)
- [cURL Multi Interface](https://curl.se/libcurl/c/libcurl-multi.html)
- [PHP curl_multi Functions](https://www.php.net/manual/en/ref.curl.php)
