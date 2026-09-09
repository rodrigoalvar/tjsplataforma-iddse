# 🔄 Flujo de Eliminación y Actualización de Series en PACS

## ✅ Sí, el Proceso Está Implementado

El sistema **SÍ realiza** el proceso completo de:
1. ✅ Recibir `series_id` de Orthanc al enviar informe
2. ✅ Guardar `series_id` en base de datos
3. ✅ Al reenviar, **eliminar la serie anterior** usando ese `series_id`
4. ✅ Enviar nueva versión del informe
5. ✅ Recibir y guardar el nuevo `series_id`

---

## 📊 Flujo Completo Paso a Paso

### Primera Vez que se Envía un Informe

```
┌─────────────────────────────────────────┐
│ 1. Usuario click "Enviar a PACS"         │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 2. Verificar si ya existe series_id     │
│    en BD (informe['pacs_series_id'])     │
│    → NO existe (primera vez)            │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 3. Enviar informe a Orthanc             │
│    POST /tools/create-dicom              │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 4. Orthanc responde con JSON:           │
│    {                                     │
│      "ID": "abc123-def456",             │
│      "ParentStudy": "xyz789",            │
│      "ParentSeries": "series123"  ← 👈  │
│    }                                     │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 5. Extraer series_id de la respuesta    │
│    series_id = "series123"              │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 6. Guardar en BD:                       │
│    UPDATE informes SET                  │
│      pacs_instance_id = 'abc123-def456',│
│      pacs_study_id = 'xyz789',          │
│      pacs_series_id = 'series123' ← 👈  │
│    WHERE id = {informe_id}              │
└─────────────────────────────────────────┘
```

---

### Segunda Vez (Reenvío o Actualización)

```
┌─────────────────────────────────────────┐
│ 1. Usuario click "Enviar a PACS"        │
│    (mismo informe)                      │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 2. Verificar si ya existe series_id     │
│    en BD (informe['pacs_series_id'])     │
│    → SÍ existe: "series123" ← 👈         │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 3. Detectar que es actualización        │
│    $isUpdate = true                     │
│    $existingSeriesId = "series123"       │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 4. ELIMINAR serie anterior de Orthanc    │
│    DELETE /series/{series123}           │
│    → Serie eliminada ✅                 │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 5. Enviar nueva versión a Orthanc       │
│    POST /tools/create-dicom              │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 6. Orthanc responde con nuevo JSON:     │
│    {                                     │
│      "ID": "new-instance-789",          │
│      "ParentStudy": "xyz789",            │
│      "ParentSeries": "series456"  ← 👈   │
│    }                                     │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 7. Extraer NUEVO series_id              │
│    series_id = "series456"               │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 8. ACTUALIZAR en BD con NUEVO series_id │
│    UPDATE informes SET                  │
│      pacs_instance_id = 'new-instance-789',│
│      pacs_study_id = 'xyz789',          │
│      pacs_series_id = 'series456' ← 👈 │
│    WHERE id = {informe_id}              │
│                                          │
│    ✅ Serie anterior eliminada          │
│    ✅ Nueva serie creada y guardada     │
└─────────────────────────────────────────┘
```

---

## 📋 Código Específico

### 1. Detección de Serie Anterior

```php
// Línea 255 de send-to-pacs.php
$existingSeriesId = $informe['pacs_series_id'] ?? null;
$isUpdate = !empty($existingSeriesId) || !empty($existingInstanceId);
```

### 2. Eliminación de Serie Anterior

```php
// Líneas 260-291 de send-to-pacs.php
if ($isUpdate) {
    if (!empty($existingSeriesId)) {
        error_log("Eliminando serie anterior (SeriesID: {$existingSeriesId})");
        $deleteResult = $pacsSender->deleteSeries($existingSeriesId);
        
        if ($deleteResult['success']) {
            error_log("Serie anterior eliminada exitosamente");
        }
    }
}
```

### 3. Extracción de Nuevo Series ID

```php
// Líneas 416-439 de send-to-pacs.php
$seriesId = $result['series_id'] ?? 
           ($result['data']['ParentSeries'] ?? null);

// Si no está en respuesta directa, obtener desde instancia
if (empty($seriesId) && !empty($instanceId)) {
    $instanceDetails = obtenerDetallesInstancia($instanceId);
    $seriesId = $instanceDetails['ParentSeries'] ?? null;
}
```

### 4. Guardado en BD

```php
// Líneas 447-462 de send-to-pacs.php
UPDATE informes SET
    fecha_enviado_pacs = NOW(),
    pacs_instance_id = ?,
    pacs_study_id = ?,
    pacs_series_id = ?  ← 👈 Se guarda el nuevo series_id
WHERE id = ?
```

---

## 🔍 Verificación del Flujo

### Logs Esperados

**Primera vez:**
```
[Enviar informe #123]
→ No hay series_id anterior
→ Enviando a Orthanc...
→ SeriesID recibido: series123
→ Guardado en BD: pacs_series_id = series123
```

**Segunda vez:**
```
[Enviar informe #123] (mismo informe)
→ Serie anterior encontrada: series123
→ Eliminando serie anterior (SeriesID: series123)
→ Serie anterior eliminada exitosamente
→ Enviando nueva versión a Orthanc...
→ Nuevo SeriesID recibido: series456
→ Actualizado en BD: pacs_series_id = series456
```

### Verificar en Logs

```bash
# Buscar logs de eliminación
grep "Eliminando serie anterior" logs/php_errors.log

# Buscar logs de actualización
grep "SeriesID extraído" logs/php_errors.log

# Buscar logs de guardado
grep "Referencias PACS actualizadas" logs/php_errors.log
```

---

## ✅ Mejoras Implementadas

### 1. Extracción Robusta de Series ID

**Antes:**
```php
$seriesId = $result['data']['ParentSeries'] ?? null;
```

**Ahora:**
```php
// Intentar múltiples fuentes
$seriesId = $result['series_id'] ?? 
           ($result['data']['ParentSeries'] ?? null);

// Si no está, obtener desde detalles de instancia
if (empty($seriesId)) {
    $instanceDetails = obtenerDesdeOrthanc($instanceId);
    $seriesId = $instanceDetails['ParentSeries'] ?? null;
}
```

### 2. Logs Detallados

Ahora se registra:
- ✅ Respuesta completa de Orthanc
- ✅ SeriesID extraído
- ✅ Advertencia si no se puede extraer
- ✅ Confirmación de eliminación de serie anterior

### 3. Fallback si No Hay Series ID

Si Orthanc no devuelve `ParentSeries` directamente:
1. ✅ Intentar desde `result['data']['ParentSeries']`
2. ✅ Intentar desde `result['data']['Series']`
3. ✅ Hacer petición GET a `/instances/{instanceId}` para obtenerlo

---

## 🎯 Prioridades en Eliminación

El sistema usa esta prioridad:

**1. Eliminar por Series ID** (prioritario)
```
DELETE /series/{seriesId}
```
✅ Elimina toda la serie (más limpio)

**2. Eliminar por Instance ID** (fallback)
```
DELETE /instances/{instanceId}
```
⚠️ Solo si no hay Series ID o falló la eliminación por Series

---

## 📊 Estado en Base de Datos

### Campos en Tabla `informes`

| Campo | Descripción | Ejemplo |
|-------|-------------|---------|
| `pacs_instance_id` | ID de la instancia DICOM | `abc123-def456-...` |
| `pacs_study_id` | ID del estudio DICOM | `xyz789-uvw012-...` |
| `pacs_series_id` | ID de la serie DICOM | `series123-456-...` |
| `fecha_enviado_pacs` | Fecha del último envío | `2025-10-30 23:00:00` |

**Actualización:**
Cada vez que se envía, estos campos se actualizan con los nuevos valores.

---

## 🔍 Verificar que Funciona

### Método 1: Ver Logs

```bash
cd C:\wamp64\www\PORTAL_ESTUDIOS
tail -f logs/php_errors.log | findstr "SeriesID"
```

**Buscar:**
```
SeriesID extraído: series123
Serie anterior eliminada exitosamente
Referencias PACS actualizadas en BD - SeriesID: series456
```

### Método 2: Verificar en BD

```sql
SELECT id, pacs_series_id, fecha_enviado_pacs 
FROM informes 
WHERE pacs_series_id IS NOT NULL;
```

**Debe mostrar:**
- ✅ Cada informe tiene `pacs_series_id`
- ✅ `fecha_enviado_pacs` muestra última fecha de envío

### Método 3: Enviar Mismo Informe Dos Veces

1. **Primera vez:**
   - Enviar informe #123
   - Verificar que se guarda `pacs_series_id` en BD

2. **Segunda vez:**
   - Enviar mismo informe #123
   - Ver en logs: "Eliminando serie anterior"
   - Verificar que se actualiza `pacs_series_id` con nuevo valor

---

## ⚠️ Casos Especiales

### Si la Columna `pacs_series_id` No Existe

El código tiene fallback:
- ✅ Intenta actualizar con `pacs_series_id`
- ⚠️ Si la columna no existe, actualiza sin ella
- ✅ Log: "Columna pacs_series_id no existe"

**Para habilitar completamente:**
```sql
ALTER TABLE informes 
ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;
```

### Si Orthanc No Devuelve ParentSeries

El sistema:
1. ✅ Intenta múltiples formas de obtenerlo
2. ✅ Hace petición adicional a `/instances/{id}` si es necesario
3. ⚠️ Si no se puede obtener, registra advertencia pero continúa
4. ✅ Se guarda en BD si está disponible

---

## 📈 Resumen del Flujo

### ✅ SÍ Se Está Haciendo:

1. **✅ Recibir series_id de Orthanc**
   - Se extrae de la respuesta JSON
   - Múltiples métodos de extracción

2. **✅ Guardar en BD**
   - Se actualiza `pacs_series_id` en tabla `informes`
   - Se guarda junto con `instance_id` y `study_id`

3. **✅ Eliminar serie anterior al reenviar**
   - Se lee `pacs_series_id` de BD
   - Se elimina la serie anterior de Orthanc
   - Se registra en logs

4. **✅ Recibir y guardar nuevo series_id**
   - Se extrae de la nueva respuesta
   - Se actualiza en BD con el nuevo valor

---

## 🔧 Mejoras Recientes

### Extracción Mejorada de Series ID

**Agregado:**
- ✅ Búsqueda en múltiples niveles de la respuesta
- ✅ Petición adicional a `/instances/{id}` si es necesario
- ✅ Logs detallados para debugging

### Logs Mejorados

**Agregado:**
- ✅ Log de respuesta completa de Orthanc
- ✅ Log de SeriesID extraído
- ✅ Advertencia si no se puede extraer
- ✅ Confirmación de eliminación

---

## ✅ Conclusión

**Sí, el proceso está completamente implementado:**

✅ Recibe `series_id` de Orthanc  
✅ Lo guarda en BD  
✅ Lo usa para eliminar serie anterior al reenviar  
✅ Guarda el nuevo `series_id` después del reenvío  

**Mejoras agregadas:**
- ✅ Extracción más robusta de `series_id`
- ✅ Logs más detallados
- ✅ Fallback si no se encuentra inmediatamente

---

## 🔍 Si No Funciona

**Verificar:**
1. ✅ Columna `pacs_series_id` existe en BD
2. ✅ Orthanc devuelve `ParentSeries` en respuesta
3. ✅ Logs muestran eliminación de serie anterior
4. ✅ BD se actualiza con nuevo `series_id`

**Debugging:**
- Ver logs: `logs/php_errors.log`
- Buscar: `SeriesID`, `eliminando serie`, `actualizadas en BD`
- Verificar respuesta de Orthanc en logs

---

_Última actualización: 30 de Octubre, 2025_  
_Flujo verificado y mejorado_

