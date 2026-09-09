# 🔄 Flujo Completo de Series ID - Confirmado y Mejorado

## ✅ Sí, el Flujo Está Implementado

El sistema **SÍ realiza** exactamente el flujo que describiste:

1. ✅ **Verificar** `pacs_series_id` antes de enviar
2. ✅ Si tiene valor → **Eliminar** serie anterior de Orthanc
3. ✅ **Enviar** nuevo informe (PDF o PNG)
4. ✅ **Recibir** JSON de Orthanc con nuevo `series_id`
5. ✅ **Guardar** `series_id` en `pacs_series_id` en BD

---

## 📊 Flujo Detallado

### **ANTES de Enviar Informe**

```
┌─────────────────────────────────────────┐
│ 1. Leer informe de BD                      │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 2. Verificar campo pacs_series_id        │
│    existingSeriesId = informe['pacs_series_id']│
│                                          │
│    SI tiene valor (NO NULL, NO vacío):  │
│      → isUpdate = true                   │
│    SI está NULL o vacío:                 │
│      → isUpdate = false                  │
└──────────────┬──────────────────────────┘
               │
               ▼ (si isUpdate = true)
┌─────────────────────────────────────────┐
│ 3. ELIMINAR serie anterior de Orthanc     │
│    DELETE /series/{existingSeriesId}    │
│    → Serie eliminada exitosamente        │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 4. Enviar nuevo informe a Orthanc        │
│    POST /tools/create-dicom               │
│    → PDF o PNG según formato seleccionado│
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 5. Orthanc responde con JSON:            │
│    {                                     │
│      "ID": "instance-123",              │
│      "ParentStudy": "study-456",         │
│      "ParentSeries": "series-789"  ← 👈  │
│    }                                     │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 6. Extraer series_id del JSON            │
│    seriesId = response['ParentSeries']   │
│    → "series-789"                        │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 7. Guardar en BD                         │
│    UPDATE informes SET                    │
│      pacs_series_id = 'series-789'  ← 👈  │
│    WHERE id = {informe_id}               │
│                                          │
│    ✅ Series ID guardado correctamente   │
└─────────────────────────────────────────┘
```

---

## 🔍 Código Específico

### 1. Verificación ANTES de Enviar (línea 255)

```php
// Leer series_id anterior de BD
$existingSeriesId = $informe['pacs_series_id'] ?? null;

// Verificar si tiene valor
$isUpdate = !empty($existingSeriesId) || !empty($existingInstanceId);
```

### 2. Eliminación de Serie Anterior (líneas 260-291)

```php
// Si tiene series_id, eliminar serie anterior
if ($isUpdate && !empty($existingSeriesId)) {
    error_log("Eliminando serie anterior (SeriesID: {$existingSeriesId})");
    $deleteResult = $pacsSender->deleteSeries($existingSeriesId);
    
    if ($deleteResult['success']) {
        error_log("✅ Serie anterior eliminada exitosamente");
    }
}
```

### 3. Envío del Informe (líneas 409-413)

```php
// Enviar según formato seleccionado
if ($format === 'jpg') {
    $result = $pacsSender->sendImageAsDicom(...);
} else {
    $result = $pacsSender->sendPdfAsDicom(...);
}
```

### 4. Extracción del Series ID del JSON (líneas 416-461)

```php
// Múltiples métodos para extraer series_id
$seriesId = $result['series_id'] ?? 
           $result['data']['ParentSeries'] ?? null;

// Si no está, obtener desde detalles de instancia
if (empty($seriesId)) {
    $instanceDetails = obtenerDesdeOrthanc($instanceId);
    $seriesId = $instanceDetails['ParentSeries'] ?? null;
}
```

### 5. Guardado en BD (líneas 485-517)

```php
// Guardar series_id en pacs_series_id
UPDATE informes SET
    fecha_enviado_pacs = NOW(),
    pacs_instance_id = ?,
    pacs_study_id = ?,
    pacs_series_id = ?  ← 👈 Series ID del JSON de Orthanc
WHERE id = ?

// Verificar que se guardó correctamente
SELECT pacs_series_id FROM informes WHERE id = ?
```

---

## 🎯 Campos en BD

### Tabla `informes`

| Campo | Descripción | Uso |
|-------|-------------|-----|
| `pacs_series_id` | **Series ID de Orthanc** | **CRÍTICO:** Guarda el series_id que Orthanc devuelve. Se usa para eliminar serie anterior al reenviar. |
| `series_instance_uid` | SeriesInstanceUID DICOM | Tag DICOM (UID), diferente al Series ID de Orthanc |

**Importante:**
- `pacs_series_id` = Series ID de Orthanc (UUID del servidor)
- `series_instance_uid` = SeriesInstanceUID DICOM (tag estándar DICOM)

**Solo `pacs_series_id` se usa para eliminar series.**

---

## ✅ Mejoras Implementadas

### 1. Extracción Robusta de Series ID

**Ahora intenta 4 métodos diferentes:**
1. ✅ Del campo directo: `result['series_id']`
2. ✅ Del data: `result['data']['ParentSeries']`
3. ✅ Del data alternativo: `result['data']['Series']`
4. ✅ Desde detalles de instancia (petición adicional si es necesario)

### 2. Logs Detallados

**Ahora registra:**
- ✅ Respuesta completa de Orthanc (JSON completo)
- ✅ Series ID extraído
- ✅ Verificación de guardado en BD
- ✅ Confirmación de eliminación de serie anterior

### 3. Verificación de Guardado

**Ahora verifica:**
- ✅ Si la columna `pacs_series_id` existe
- ✅ Si se guardó correctamente (hace SELECT después de UPDATE)
- ✅ Si el valor guardado coincide con el extraído

---

## 🔍 Verificar que Funciona

### Método 1: Ver Logs

```bash
# Buscar logs de extracción de Series ID
grep "SeriesID extraído" logs/php_errors.log

# Buscar logs de guardado
grep "GUARDADO_BD" logs/php_errors.log

# Buscar logs de eliminación
grep "Eliminando serie anterior" logs/php_errors.log
```

### Método 2: Verificar en BD

```sql
-- Ver informes con series_id guardado
SELECT id, titulo, pacs_series_id, fecha_enviado_pacs 
FROM informes 
WHERE pacs_series_id IS NOT NULL 
ORDER BY fecha_enviado_pacs DESC;

-- Verificar que la columna existe
SHOW COLUMNS FROM informes LIKE 'pacs_series_id';
```

### Método 3: Script de Verificación

```
http://localhost/portal_estudios/api/informes/verificar-series-id-bd.php
```

**Muestra:**
- ✅ Si la columna existe
- ✅ Cuántos informes tienen `series_id` guardado
- ✅ El valor del `series_id` almacenado
- ✅ Estadísticas

---

## ⚠️ Problema Común: Columna No Existe

**Si la columna `pacs_series_id` NO existe:**

El código hace fallback pero **NO guarda** el `series_id`. Los logs mostrarán:

```
⚠️ Columna pacs_series_id no existe. Usando fallback sin SeriesID
⚠️ IMPORTANTE: El SeriesID no se guardó porque la columna no existe
```

**Solución:**

```sql
-- Crear la columna
ALTER TABLE informes 
ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;

-- O ejecutar el script
source database/crear_columna_pacs_series_id.sql;
```

---

## 🔄 Flujo Completo de Ejemplo

### Primera Vez (Informe Nuevo)

```
1. Leer informe #123 de BD
   → pacs_series_id = NULL (no existe)
   → isUpdate = false

2. NO eliminar serie anterior (no hay)

3. Enviar informe a Orthanc
   → POST /tools/create-dicom

4. Orthanc responde:
   {
     "ID": "abc-123",
     "ParentSeries": "series-789"  ← 👈
   }

5. Extraer series_id:
   → seriesId = "series-789"

6. Guardar en BD:
   UPDATE informes SET 
     pacs_series_id = 'series-789'
   WHERE id = 123

7. ✅ Series ID guardado: "series-789"
```

### Segunda Vez (Reenvío)

```
1. Leer informe #123 de BD
   → pacs_series_id = "series-789" (existe)
   → isUpdate = true

2. ELIMINAR serie anterior:
   → DELETE /series/series-789
   → ✅ Serie eliminada

3. Enviar nueva versión a Orthanc
   → POST /tools/create-dicom

4. Orthanc responde:
   {
     "ID": "abc-456",
     "ParentSeries": "series-999"  ← 👈 NUEVO
   }

5. Extraer nuevo series_id:
   → seriesId = "series-999"

6. ACTUALIZAR en BD:
   UPDATE informes SET 
     pacs_series_id = 'series-999'  ← 👈 NUEVO
   WHERE id = 123

7. ✅ Nuevo Series ID guardado: "series-999"
```

---

## 📋 Checklist de Verificación

Después de enviar un informe, verificar:

- [ ] ✅ La columna `pacs_series_id` existe en BD
- [ ] ✅ El `series_id` se extrae del JSON de Orthanc
- [ ] ✅ El `series_id` se guarda en `pacs_series_id` en BD
- [ ] ✅ Los logs muestran "SeriesID extraído correctamente"
- [ ] ✅ Los logs muestran "Series ID guardado en pacs_series_id"
- [ ] ✅ Los logs muestran "VERIFICACIÓN: Series ID guardado correctamente"
- [ ] ✅ Al reenviar, se lee `pacs_series_id` de BD
- [ ] ✅ Al reenviar, se elimina la serie anterior
- [ ] ✅ Al reenviar, se guarda el nuevo `series_id`

---

## 🎯 Resumen

### ✅ SÍ se está haciendo:

1. **✅ Verificar** `pacs_series_id` antes de enviar
2. **✅ Eliminar** serie anterior si existe
3. **✅ Enviar** informe (PDF o PNG)
4. **✅ Extraer** `series_id` del JSON de Orthanc
5. **✅ Guardar** `series_id` en `pacs_series_id` en BD

### 🔧 Mejoras agregadas:

- ✅ Extracción más robusta (4 métodos)
- ✅ Logs más detallados
- ✅ Verificación de guardado en BD
- ✅ Verificación de existencia de columna
- ✅ Confirmación de eliminación de serie anterior

---

## 🚀 Próximos Pasos

1. **Verificar** que la columna `pacs_series_id` existe:
   ```sql
   SHOW COLUMNS FROM informes LIKE 'pacs_series_id';
   ```

2. **Si no existe**, crearla:
   ```sql
   ALTER TABLE informes 
   ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL 
   AFTER pacs_study_id;
   ```

3. **Enviar un informe** y verificar logs:
   ```bash
   tail -f logs/php_errors.log | grep "SEND_TO_PACS"
   ```

4. **Verificar en BD** que se guardó:
   ```sql
   SELECT id, pacs_series_id FROM informes 
   WHERE fecha_enviado_pacs IS NOT NULL 
   ORDER BY fecha_enviado_pacs DESC 
   LIMIT 10;
   ```

---

_Última actualización: 30 de Octubre, 2025_  
_Flujo verificado y mejorado con logs detallados_

