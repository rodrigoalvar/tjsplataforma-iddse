# Flujo Propuesto Implementado - Gestión de Informes en PACS

## ✅ Implementación Completada

Se ha implementado exitosamente el flujo propuesto para gestionar informes médicos en PACS usando **SeriesID** en lugar de solo InstanceID.

## 📋 Flujo Implementado

### 1. Encapsular PDF como DICOM
✅ **Implementado**
- Se usa `OrthancPacsSender::sendPdfAsDicom()` que encapsula el PDF como DICOM Encapsulated PDF
- Genera tags DICOM correctas incluyendo `StudyInstanceUID` para vinculación

### 2. Enviar PDF al PACS usando StudyInstanceUID
✅ **Implementado**
- El sistema obtiene `StudyInstanceUID` del estudio original (desde BD o Orthanc)
- Lo usa en tags DICOM para garantizar vinculación al estudio existente

### 3. PDF como nueva serie en el estudio
✅ **Implementado**
- El PDF se crea como nueva serie dentro del mismo estudio (mismo `StudyInstanceUID`)
- Genera nuevo `SeriesInstanceUID` automáticamente

### 4. Guardar SeriesID en metadatos
✅ **Implementado**
- Orthanc responde con JSON incluyendo `ParentSeries` (SeriesID)
- El sistema guarda `SeriesID` en el campo `pacs_series_id` de la tabla `informes`
- También guarda `pacs_instance_id` y `pacs_study_id` por compatibilidad

### 5. Eliminar serie anterior al actualizar
✅ **Implementado**
- Cuando se actualiza un informe, el sistema:
  1. **Prioridad 1**: Elimina por `SeriesID` usando `DELETE /series/{series_id}` (flujo propuesto)
  2. **Fallback**: Si no hay SeriesID o falla, elimina por `InstanceID` (compatibilidad)

### 6. Enviar nueva versión y guardar nuevo SeriesID
✅ **Implementado**
- Al enviar nueva versión, se repite el proceso completo
- Se guarda el nuevo `SeriesID` en BD
- La serie anterior es eliminada automáticamente

## 🔧 Cambios Implementados

### Base de Datos

**Script SQL:** `database/add_pacs_series_id_to_informes.sql`

```sql
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pacs_series_id VARCHAR(255) NULL 
COMMENT 'ID de la serie en Orthanc donde se almacenó el PDF.';

CREATE INDEX IF NOT EXISTS idx_pacs_series_id ON informes(pacs_series_id);
```

### API - OrthancPacsSender.php

**Nuevo método:** `deleteSeries($seriesId)`

```php
public function deleteSeries($seriesId) {
    // Elimina serie completa usando DELETE /series/{series_id}
    // Maneja errores y reintentos automáticamente
    // Retorna resultado de la operación
}
```

**Método actualizado:** `sendPdfAsDicom()`

```php
// Ahora incluye series_id en la respuesta
return [
    'success' => true,
    'instance_id' => $response['data']['ID'],
    'study_id' => $response['data']['ParentStudy'],
    'series_id' => $response['data']['ParentSeries'], // ← NUEVO
    'data' => $response['data'], // ← Datos completos
    'file_size_mb' => round($fileSizeMB, 2)
];
```

### API - send-to-pacs.php

**Cambios en eliminación:**

```php
// Ahora usa SeriesID como prioridad
if ($isUpdate) {
    // Prioridad 1: Eliminar por SeriesID (flujo propuesto)
    if (!empty($existingSeriesId)) {
        $deleteResult = $pacsSender->deleteSeries($existingSeriesId);
    }
    
    // Fallback: Eliminar por InstanceID (compatibilidad)
    if (!$deletedSuccessfully && !empty($existingInstanceId)) {
        $deleteResult = $pacsSender->deleteInstance($existingInstanceId);
    }
}
```

**Cambios en guardado:**

```php
// Guarda SeriesID junto con otros IDs
UPDATE informes 
SET fecha_enviado_pacs = NOW(),
    pacs_instance_id = ?,
    pacs_study_id = ?,
    pacs_series_id = ?  // ← NUEVO
WHERE id = ?
```

**Cambios en respuesta JSON:**

```php
echo json_encode([
    'success' => true,
    'is_update' => $isUpdate,
    'old_series_id' => $isUpdate ? $existingSeriesId : null, // ← NUEVO
    'old_instance_id' => $isUpdate ? $existingInstanceId : null,
    'data' => [
        'instance_id' => $result['instance_id'],
        'study_id' => $result['study_id'],
        'series_id' => $seriesId, // ← NUEVO
        'file_size_mb' => $result['file_size_mb']
    ]
]);
```

## 📊 Ventajas del Flujo Propuesto

### ✅ Más Robusto
- Elimina **toda la serie** de una vez
- Si hay múltiples instancias accidentalmente, todas se eliminan
- Menos riesgo de objetos huérfanos en PACS

### ✅ Más Simple
- Un solo identificador principal (`SeriesID`)
- Menos complejidad en la lógica de eliminación
- Código más limpio y fácil de mantener

### ✅ Compatible hacia atrás
- Mantiene guardado de `InstanceID` y `StudyID` por compatibilidad
- Fallback a eliminación por `InstanceID` si no hay `SeriesID`
- Informes antiguos siguen funcionando

### ✅ Estandarizado
- Sigue mejor práctica de trabajar a nivel de serie en DICOM
- Alineado con estándares DICOM y workflows comunes

## 🔄 Migración de Informes Existentes

Los informes que ya fueron enviados a PACS antes de esta implementación:
- Tienen `pacs_instance_id` y `pacs_study_id` guardados
- **NO tienen** `pacs_series_id` (NULL)
- Al actualizarlos, el sistema:
  1. Intenta eliminar por `SeriesID` (no existe) → falla
  2. Usa fallback y elimina por `InstanceID` → funciona
  3. Guarda nuevo `SeriesID` → próximas actualizaciones usarán SeriesID

**Resultado:** Migración automática sin problemas.

## 📝 Testing

### Escenario 1: Envío Inicial de Informe

```
1. Usuario envía informe a PACS
2. Sistema encapsula PDF como DICOM
3. Orthanc crea objeto DICOM
4. Sistema guarda:
   - pacs_instance_id: "abc-123"
   - pacs_study_id: "study-456"
   - pacs_series_id: "series-789" ← NUEVO
5. ✅ Informe vinculado correctamente al estudio
```

### Escenario 2: Actualización de Informe

```
1. Usuario actualiza informe y lo reenvía
2. Sistema verifica pacs_series_id existe
3. Sistema elimina serie completa: DELETE /series/series-789
4. Sistema envía nueva versión
5. Sistema guarda nuevo:
   - pacs_series_id: "series-999" ← NUEVO
6. ✅ Serie anterior eliminada, nueva serie creada
```

### Escenario 3: Actualización de Informe Antiguo (sin SeriesID)

```
1. Usuario actualiza informe creado antes de esta implementación
2. Sistema verifica pacs_series_id → NULL
3. Sistema usa fallback y elimina por InstanceID
4. Sistema envía nueva versión
5. Sistema guarda nuevo pacs_series_id
6. ✅ Próximas actualizaciones usarán SeriesID
```

## ✅ Estado de Implementación

- [x] Script SQL para agregar campo `pacs_series_id`
- [x] Método `deleteSeries()` en OrthancPacsSender
- [x] Modificación de `sendPdfAsDicom()` para retornar `series_id`
- [x] Actualización de lógica de eliminación (prioridad SeriesID)
- [x] Actualización de guardado en BD (incluir SeriesID)
- [x] Actualización de respuesta JSON (incluir SeriesID)
- [x] Compatibilidad hacia atrás (fallback a InstanceID)
- [x] Logging y manejo de errores

## 📚 Documentación Relacionada

- `docs/comparacion-flujo-vinculacion.md` - Comparación detallada
- `docs/vinculacion-informes-estudios-pacs.md` - Vinculación por StudyInstanceUID
- `docs/guia-vinculacion-pacs.md` - Guía completa de vinculación

---

**Implementación completada:** Enero 2025  
**Versión:** 1.0.0  
**Estado:** ✅ **LISTO PARA PRODUCCIÓN**

