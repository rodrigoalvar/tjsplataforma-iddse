# Validaciones Adicionales para Protección de Audios

## Resumen

Se han implementado **12 validaciones adicionales** para proteger los datos de audio y mejorar la experiencia del usuario antes de cambiar de estudio o guardar audios.

---

## Validaciones Implementadas

### 1. **Verificar Grabación Activa** ✅
```javascript
hasActiveRecording()
```
- **Propósito**: Detecta si hay una grabación en curso antes de cambiar de estudio
- **Retorna**: `true` si hay grabación activa, `false` en caso contrario
- **Uso**: Previene pérdida de datos si el usuario intenta cambiar de estudio mientras graba

---

### 2. **Verificar Conexión a Internet** ✅
```javascript
checkNetworkConnection()
```
- **Propósito**: Verifica si hay conexión a internet antes de guardar datos
- **Retorna**: Objeto con `{ online: boolean, message: string }`
- **Uso**: Evita intentos de guardado cuando no hay conexión
- **Timeout**: 3 segundos máximo

---

### 3. **Verificar Errores de Red Recientes** ✅
```javascript
hasRecentNetworkErrors(maxAgeMinutes = 5)
```
- **Propósito**: Detecta si hubo errores de conexión en los últimos minutos
- **Parámetros**: 
  - `maxAgeMinutes`: Tiempo en minutos para considerar errores "recientes" (default: 5)
- **Retorna**: `true` si hay errores recientes
- **Uso**: Advertir al usuario sobre problemas de conexión persistentes

---

### 4. **Validar Tamaño de Audio** ✅
```javascript
validateAudioSize(blob, maxSizeMB = 50)
```
- **Propósito**: Verifica que el audio no exceda el tamaño máximo permitido
- **Parámetros**:
  - `blob`: Blob del audio a validar
  - `maxSizeMB`: Tamaño máximo en MB (default: 50MB)
- **Retorna**: `{ valid: boolean, message?: string, sizeMB?: string }`
- **Uso**: Previene subida de archivos demasiado grandes

---

### 5. **Validar Formato de Audio** ✅
```javascript
validateAudioFormat(blob)
```
- **Propósito**: Verifica que el formato del audio sea soportado
- **Formatos permitidos**: WebM, MP4, M4A, OGG, WAV, MP3, FLAC
- **Retorna**: `{ valid: boolean, message?: string, format?: string }`
- **Uso**: Asegura compatibilidad antes de guardar

---

### 6. **Validar Duración de Audio** ✅
```javascript
validateAudioDuration(blob, minDurationSeconds = 1)
```
- **Propósito**: Verifica que el audio tenga una duración mínima válida
- **Parámetros**:
  - `blob`: Blob del audio
  - `minDurationSeconds`: Duración mínima en segundos (default: 1s)
- **Retorna**: `{ valid: boolean, message?: string, duration?: number }`
- **Uso**: Detecta audios vacíos o demasiado cortos
- **Nota**: No bloquea el guardado, solo advierte

---

### 7. **Validar URL de Blob** ✅
```javascript
validateBlobUrl(blobUrl)
```
- **Propósito**: Verifica que la URL del blob siga siendo válida y no haya expirado
- **Retorna**: `{ valid: boolean, message: string }`
- **Uso**: Detecta URLs expiradas antes de intentar guardar

---

### 8. **Verificar que el Estudio Existe** ✅
```javascript
validateStudyExists(studyId, studyInstanceUID, orthancStudyId)
```
- **Propósito**: Verifica que el estudio nuevo existe antes de cambiar
- **Retorna**: `{ valid: boolean, message: string, studyData?: object, warning?: boolean }`
- **Uso**: Previene cambios a estudios inexistentes o sin permisos
- **Endpoint**: `api/studies/check.php`

---

### 9. **Verificar Permisos del Usuario** ✅
```javascript
checkUserPermissions(studyId)
```
- **Propósito**: Verifica que el usuario tenga permisos para acceder al estudio
- **Retorna**: `{ hasPermission: boolean, message: string }`
- **Uso**: Control de acceso antes de cambiar de estudio

---

### 10. **Validación Completa Antes de Cambiar Estudio** ✅
```javascript
validateBeforeStudyChange(newStudyId, currentStudyId)
```
- **Propósito**: Ejecuta todas las validaciones relevantes antes de cambiar de estudio
- **Retorna**: Objeto con todas las validaciones y resultados:
  ```javascript
  {
    hasUnsavedData: boolean,
    hasActiveRecording: boolean,
    networkStatus: { online: boolean, message: string },
    studyExists: { valid: boolean, message: string },
    errors: string[],
    warnings: string[]
  }
  ```
- **Uso**: Validación completa antes de permitir cambio de estudio

---

### 11. **Validación Completa Antes de Guardar Audio** ✅
```javascript
validateBeforeSaveAudio(blob, blobUrl)
```
- **Propósito**: Ejecuta todas las validaciones relevantes antes de guardar un audio
- **Retorna**: Objeto con todas las validaciones:
  ```javascript
  {
    networkStatus: { online: boolean, message: string },
    audioSize: { valid: boolean, message?: string },
    audioFormat: { valid: boolean, message?: string },
    audioDuration: { valid: boolean, duration?: number },
    blobUrlValid: { valid: boolean, message: string },
    errors: string[],
    warnings: string[]
  }
  ```
- **Uso**: Validación completa antes de guardar audio

---

### 12. **Obtener Resumen de Validaciones** ✅
```javascript
getValidationSummary(validations)
```
- **Propósito**: Genera un resumen legible de las validaciones para mostrar al usuario
- **Retorna**: `{ canProceed: boolean, message: string, type: 'success'|'warning'|'error' }`
- **Uso**: Formatea los resultados para mostrar en diálogos

---

## Cómo Usar las Validaciones

### Ejemplo 1: Antes de Cambiar de Estudio

```javascript
// En readStudyParams() o antes de cambiar estudio
const validations = await this.validateBeforeStudyChange(newStudyId, currentStudyId);

if (validations.errors.length > 0) {
    // Hay errores críticos - no permitir cambio
    const summary = this.getValidationSummary(validations);
    this.showToast(summary.message, 'error');
    return; // Cancelar cambio
}

if (validations.warnings.length > 0) {
    // Hay advertencias - mostrar pero permitir continuar
    const summary = this.getValidationSummary(validations);
    const proceed = confirm(summary.message + '\n\n¿Deseas continuar de todas formas?');
    if (!proceed) {
        return; // Cancelar cambio
    }
}
```

### Ejemplo 2: Antes de Guardar Audio

```javascript
// En addRecordingToList() o antes de subir audio
const response = await fetch(audioUrl);
const blob = await response.blob();

const validations = await this.validateBeforeSaveAudio(blob, audioUrl);

if (validations.errors.length > 0) {
    // Hay errores críticos - no guardar
    const summary = this.getValidationSummary(validations);
    this.showToast(summary.message, 'error');
    return; // Cancelar guardado
}

if (validations.warnings.length > 0) {
    // Hay advertencias - mostrar pero permitir continuar
    const summary = this.getValidationSummary(validations);
    console.warn('Advertencias al guardar audio:', summary.message);
}
```

### Ejemplo 3: Verificar Grabación Activa

```javascript
// Antes de cambiar de estudio
if (this.hasActiveRecording()) {
    this.showToast('Hay una grabación en curso. Detén la grabación antes de cambiar de estudio.', 'warning');
    return; // Cancelar cambio
}
```

### Ejemplo 4: Verificar Conexión de Red

```javascript
// Antes de guardar datos
const networkStatus = await this.checkNetworkConnection();
if (!networkStatus.online) {
    const proceed = confirm(
        networkStatus.message + 
        '\n\n¿Deseas intentar guardar de todas formas? (puede fallar)'
    );
    if (!proceed) {
        return; // Cancelar guardado
    }
}
```

---

## Integración con el Flujo Existente

### Puntos de Integración Recomendados:

1. **`readStudyParams()`**: Usar `validateBeforeStudyChange()` antes de cambiar estudio
2. **`addRecordingToList()`**: Usar `validateBeforeSaveAudio()` antes de agregar audio
3. **`finishReportFromRecorder()`**: Usar `checkNetworkConnection()` antes de guardar
4. **`beforeunload` event**: Usar `hasActiveRecording()` y `hasUnsavedData()` para advertencias

---

## Variables de Estado Agregadas

Se agregaron las siguientes variables al objeto `WorkspaceManager`:

```javascript
networkErrors: [],           // Historial de errores de red recientes
lastNetworkCheck: null       // Última verificación de conexión
```

---

## Endpoint API Creado

### `api/studies/check.php`

**Método**: GET  
**Autenticación**: Bearer Token requerido  
**Parámetros**:
- `studyId` (query string): Identificador del estudio a verificar

**Respuesta exitosa**:
```json
{
  "success": true,
  "exists": true,
  "message": "Estudio encontrado",
  "studyData": {
    "id": "...",
    "orthanc_study_id": "...",
    "study_instance_uid": "...",
    "patient_id": "...",
    "patient_name": "...",
    "modality": "...",
    "study_description": "...",
    "study_date": "..."
  }
}
```

**Respuesta si no existe**:
```json
{
  "success": true,
  "exists": false,
  "message": "Estudio no encontrado",
  "studyData": null
}
```

---

## Beneficios de las Validaciones

1. ✅ **Previene pérdida de datos**: Detecta problemas antes de que ocurran
2. ✅ **Mejora UX**: Informa al usuario sobre problemas potenciales
3. ✅ **Ahorra tiempo**: Evita intentos de guardado que fallarán
4. ✅ **Mejora confiabilidad**: Valida datos antes de procesarlos
5. ✅ **Facilita debugging**: Registra errores para análisis posterior

---

## Próximos Pasos Recomendados

1. Integrar `validateBeforeStudyChange()` en `readStudyParams()`
2. Integrar `validateBeforeSaveAudio()` en `addRecordingToList()`
3. Agregar indicadores visuales de estado de conexión
4. Implementar reintentos automáticos para errores de red temporales
5. Agregar logging de validaciones para análisis posterior

---

## Notas Importantes

- Las validaciones de **duración** y **URL de blob** son opcionales y no bloquean el guardado
- Las validaciones de **red** pueden tener un pequeño delay (3 segundos timeout)
- El endpoint `check.php` requiere autenticación válida
- Las validaciones se ejecutan de forma asíncrona cuando es necesario
