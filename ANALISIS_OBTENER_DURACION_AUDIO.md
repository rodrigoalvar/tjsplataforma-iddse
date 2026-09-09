# Análisis: Obtener Duración de Audio (especialmente WebM)

## Problema Actual

La columna "Duración" en el modal "Audios del Estudio" muestra "N/A" para algunos audios, especialmente archivos WebM, porque:

1. **getID3 puede fallar con WebM**: El método actual usa `getID3` que puede no soportar correctamente todos los codecs WebM
2. **La duración no se calcula al subir**: Si getID3 falla, la duración queda como NULL en la base de datos
3. **Whisper devuelve la duración**: Cuando se transcribe el audio, Whisper devuelve `audio_duration_s` pero no se actualiza en `audios_informe.duracion_segundos`

## Situación Actual

### Flujo de Subida de Audio
1. Usuario sube audio (WebM, MP3, M4A, etc.)
2. Se intenta calcular duración con `getID3` → **Puede fallar con WebM**
3. Se guarda en `audios_informe` con `duracion_segundos` (puede ser NULL)

### Flujo de Transcripción
1. Se convierte a MP3 si es necesario (WebM → MP3 usando ffmpeg/ffmpeg-rest)
2. Se envía a Whisper para transcribir
3. Whisper devuelve `audio_duration_s` en la respuesta
4. **PROBLEMA**: Esta duración NO se actualiza en `audios_informe.duracion_segundos`

## Opciones de Solución

### Opción 1: Usar ffmpeg como fallback cuando getID3 falla ⭐ RECOMENDADA

**Ventajas:**
- ffmpeg soporta WebM nativamente
- Ya está disponible en el sistema (se usa para conversión)
- No requiere cambios en el flujo de transcripción
- Funciona inmediatamente al subir el audio

**Desventajas:**
- Requiere ejecutar comando ffmpeg (ligeramente más lento que getID3)
- Necesita verificar que ffmpeg esté disponible

**Implementación:**
```php
// En api/audios/upload.php, después de intentar con getID3
if (!$duracion) {
    // Intentar con ffmpeg como fallback
    $duracion = getAudioDurationWithFfmpeg($filePath);
}

function getAudioDurationWithFfmpeg($filePath) {
    // ffmpeg -i archivo.webm 2>&1 | grep Duration
    $command = "ffmpeg -i " . escapeshellarg($filePath) . " 2>&1 | grep 'Duration' | cut -d ' ' -f 4 | sed s/,//";
    $output = shell_exec($command);
    
    if ($output) {
        // Parsear formato HH:MM:SS.mmm
        $parts = explode(':', trim($output));
        if (count($parts) === 3) {
            $hours = (int)$parts[0];
            $minutes = (int)$parts[1];
            $seconds = (float)$parts[2];
            return (int)($hours * 3600 + $minutes * 60 + $seconds);
        }
    }
    return null;
}
```

### Opción 2: Actualizar duración cuando se transcribe (usando duración de Whisper)

**Ventajas:**
- Aprovecha la duración que ya devuelve Whisper
- No requiere procesamiento adicional al subir
- La duración es precisa (viene del análisis de Whisper)

**Desventajas:**
- La duración solo estará disponible DESPUÉS de transcribir
- Requiere modificar el flujo de transcripción para actualizar la BD
- Los audios sin transcribir seguirán mostrando N/A

**Implementación:**
```php
// En api/ai-informes.php, después de transcribir exitosamente
if (isset($result['stats']['audio_duration_s'])) {
    $audioDuration = $result['stats']['audio_duration_s'];
    
    // Actualizar duración en audios_informe si no existe o es diferente
    $updateStmt = $db->prepare("
        UPDATE audios_informe 
        SET duracion_segundos = ? 
        WHERE id = ? AND (duracion_segundos IS NULL OR ABS(duracion_segundos - ?) > 1)
    ");
    $updateStmt->execute([$audioDuration, $audioId, $audioDuration]);
}
```

### Opción 3: Obtener duración del MP3 convertido

**Ventajas:**
- El MP3 convertido siempre tiene metadatos válidos
- Se puede obtener durante la conversión

**Desventajas:**
- Solo funciona si se hace conversión (no para MP3/WAV originales)
- Requiere modificar el proceso de conversión
- Más complejo de implementar

### Opción 4: Combinación (Opción 1 + Opción 2) ⭐⭐ MEJOR OPCIÓN

**Ventajas:**
- Cobertura completa: duración al subir (ffmpeg) + actualización al transcribir (Whisper)
- Si falla al subir, se actualiza cuando se transcribe
- Máxima precisión

**Desventajas:**
- Requiere implementar ambas opciones
- Ligeramente más código

## Recomendación

**Implementar Opción 4 (Combinación):**

1. **Primero**: Agregar fallback con ffmpeg cuando getID3 falla (Opción 1)
   - Esto resuelve el problema inmediatamente para nuevos audios
   - Funciona para WebM y otros formatos

2. **Segundo**: Actualizar duración cuando se transcribe (Opción 2)
   - Esto actualiza audios existentes sin duración
   - Asegura precisión máxima

## Archivos a Modificar

1. **`api/audios/upload.php`**
   - Agregar función `getAudioDurationWithFfmpeg()`
   - Usar como fallback cuando getID3 falla

2. **`api/ai-informes.php`**
   - En `handleTranscribe()`: Actualizar `duracion_segundos` cuando Whisper devuelve `audio_duration_s`
   - En `handleTranscribeAudio()`: Similar actualización

## Consideraciones Técnicas

### Verificar disponibilidad de ffmpeg
```php
function isFfmpegAvailable() {
    $output = shell_exec('which ffmpeg 2>&1');
    return !empty($output);
}
```

### Parsear salida de ffmpeg
La salida de `ffmpeg -i archivo.webm` incluye:
```
Duration: 00:03:45.67, start: 0.000000, bitrate: 64 kb/s
```

Necesitamos extraer `00:03:45.67` y convertir a segundos.

## Próximos Pasos

1. Implementar función `getAudioDurationWithFfmpeg()` en `api/audios/upload.php`
2. Agregar fallback en el proceso de subida
3. Implementar actualización de duración en proceso de transcripción
4. Probar con archivos WebM
5. Verificar que audios existentes se actualicen al transcribir
