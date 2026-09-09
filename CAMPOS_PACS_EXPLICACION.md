# 📋 Campos PACS - Explicación y Propósito

## 🎯 Campos PACS en la Tabla `informes`

Estos campos almacenan las **referencias de Orthanc** que se reciben al enviar un informe al PACS. Son **CRÍTICOS** para:

1. ✅ Eliminar series anteriores al reenviar un informe
2. ✅ Vincular informes con estudios en Orthanc
3. ✅ Evitar duplicados
4. ✅ Rastrear qué se envió a PACS

---

## 📊 Campos y su Propósito

### 1. `pacs_instance_id`

**Descripción:**
- **ID de la instancia DICOM** en Orthanc
- Cada vez que envías un informe (PDF o PNG), Orthanc crea una **instancia DICOM**
- Este ID identifica ese objeto específico en Orthanc

**Para qué sirve:**
- ✅ Identificar el objeto DICOM específico en Orthanc
- ✅ **Eliminar instancia anterior** al reenviar (fallback si no hay `series_id`)
- ✅ Consultar detalles de la instancia en Orthanc
- ✅ Rastrear qué objeto específico se creó

**Ejemplo:**
```
pacs_instance_id = "5b701f1f-edc88b77-ac3783be-911ee1f8-ae683fa9"
```

**Uso en código:**
```php
// Leer instance_id anterior
$existingInstanceId = $informe['pacs_instance_id'] ?? null;

// Si hay instance_id, eliminar instancia anterior (fallback)
if ($existingInstanceId) {
    $pacsSender->deleteInstance($existingInstanceId);
}

// Guardar nuevo instance_id después de enviar
UPDATE informes SET pacs_instance_id = 'nuevo-instance-id' WHERE id = ?
```

---

### 2. `pacs_study_id`

**Descripción:**
- **ID del estudio DICOM** en Orthanc
- Un **estudio** puede contener múltiples series (imágenes del estudio + informe)
- Este ID identifica el estudio completo en Orthanc

**Para qué sirve:**
- ✅ Vincular el informe al estudio original del paciente
- ✅ Agrupar el informe con las imágenes del estudio
- ✅ Identificar a qué estudio pertenece el informe en Orthanc
- ✅ Consultar el estudio completo desde Orthanc

**Ejemplo:**
```
pacs_study_id = "b79dea2d-29c52f51-6e7a1dc9-b65d88dc-39e77223"
```

**Uso en código:**
```php
// Guardar study_id después de enviar
UPDATE informes SET pacs_study_id = 'study-id' WHERE id = ?

// El study_id ayuda a identificar a qué estudio pertenece el informe
```

---

### 3. `pacs_series_id` ⭐ **MÁS IMPORTANTE**

**Descripción:**
- **ID de la serie DICOM** en Orthanc
- Una **serie** contiene una o más instancias relacionadas (puede ser solo el informe, o múltiples versiones)
- Este ID identifica la serie del informe en Orthanc

**Para qué sirve:**
- ✅ **ELIMINAR la serie anterior completa** al reenviar un informe
- ✅ Más eficiente que eliminar solo la instancia (elimina toda la serie)
- ✅ Evitar duplicados cuando se reenvía un informe
- ✅ Es el campo **PRIORITARIO** para eliminar antes de reenviar

**Ejemplo:**
```
pacs_series_id = "c9eea7b0-07683118-447aed43-34277734-34fce175"
```

**Uso en código:**
```php
// Leer series_id anterior (PRIORIDAD 1)
$existingSeriesId = $informe['pacs_series_id'] ?? null;

// Eliminar serie anterior antes de reenviar
if ($existingSeriesId) {
    $pacsSender->deleteSeries($existingSeriesId); // ✅ Elimina toda la serie
}

// Guardar nuevo series_id después de enviar
UPDATE informes SET pacs_series_id = 'nuevo-series-id' WHERE id = ?
```

**⚠️ IMPORTANTE:**
Este es el campo **MÁS CRÍTICO** porque se usa para eliminar la serie anterior al reenviar un informe.

---

### 4. `fecha_enviado_pacs` (Opcional)

**Descripción:**
- **Fecha y hora del último envío** a PACS
- Registra cuándo se envió el informe a Orthanc

**Para qué sirve:**
- ✅ Rastrear cuándo se envió el informe
- ✅ Verificar si un informe fue enviado
- ✅ Auditoría y logs
- ✅ Mostrar fecha de envío en la interfaz

**Ejemplo:**
```
fecha_enviado_pacs = "2025-10-31 00:37:50"
```

---

## 🔄 Flujo de Uso

### Al Enviar un Informe:

```
1. Enviar informe → Orthanc
   ↓
2. Orthanc responde con JSON:
   {
     "ID": "instance-id",           → pacs_instance_id
     "ParentStudy": "study-id",     → pacs_study_id
     "ParentSeries": "series-id"    → pacs_series_id ⭐
   }
   ↓
3. Guardar en BD:
   UPDATE informes SET
     pacs_instance_id = 'instance-id',
     pacs_study_id = 'study-id',
     pacs_series_id = 'series-id'  ← 👈 MÁS IMPORTANTE
   WHERE id = ?
```

### Al Reenviar un Informe:

```
1. Leer de BD:
   pacs_series_id = informes.pacs_series_id  ← 👈 PRIORIDAD 1
   pacs_instance_id = informes.pacs_instance_id (fallback)
   ↓
2. Eliminar serie anterior:
   DELETE /series/{pacs_series_id}  ← 👈 Usa series_id
   (o DELETE /instances/{pacs_instance_id} si no hay series_id)
   ↓
3. Enviar nueva versión
   ↓
4. Guardar nuevos IDs en BD
```

---

## 📊 Jerarquía DICOM

```
Patient (Paciente)
  └── Study (Estudio)           ← pacs_study_id
        └── Series (Serie)      ← pacs_series_id ⭐
              └── Instance      ← pacs_instance_id
```

**Ejemplo:**
```
Patient: Juan García
  └── Study: "Estudio RX de Tórax" (2025-10-31)
        ├── Series: "Imágenes RX" (serie 1)
        │     └── Instance 1, 2, 3...
        └── Series: "Informe" (serie 2)  ← 👈 pacs_series_id
              └── Instance: Informe PDF  ← 👈 pacs_instance_id
```

**Por eso `pacs_series_id` es crítico:**
- Eliminando la serie, se eliminan **todas las instancias** de esa serie
- Es más eficiente que eliminar instancia por instancia

---

## ⚠️ Si las Columnas No Existen

**Error que obtienes:**
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'pacs_instance_id' in 'field list'
```

**Solución:**
Crear todas las columnas PACS necesarias:

```sql
-- Crear todas las columnas PACS necesarias
ALTER TABLE informes 
ADD COLUMN pacs_instance_id VARCHAR(255) DEFAULT NULL,
ADD COLUMN pacs_study_id VARCHAR(255) DEFAULT NULL,
ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL,
ADD COLUMN fecha_enviado_pacs DATETIME DEFAULT NULL;
```

---

## ✅ Verificar Columnas Existentes

```sql
-- Ver todas las columnas PACS
SHOW COLUMNS FROM informes WHERE Field LIKE 'pacs%' OR Field LIKE 'fecha_enviado%';
```

**O:**

```sql
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND (COLUMN_NAME LIKE 'pacs%' OR COLUMN_NAME LIKE 'fecha_enviado%')
ORDER BY ORDINAL_POSITION;
```

---

## 📋 Resumen

| Campo | Propósito Principal | Crítico Para |
|-------|---------------------|--------------|
| `pacs_instance_id` | ID de instancia DICOM | Eliminar instancia anterior (fallback) |
| `pacs_study_id` | ID de estudio DICOM | Vincular con estudio original |
| `pacs_series_id` | ⭐ **ID de serie DICOM** | ⭐ **Eliminar serie anterior al reenviar** |
| `fecha_enviado_pacs` | Fecha de envío | Auditoría y rastreo |

**⭐ `pacs_series_id` es el MÁS IMPORTANTE** porque se usa para eliminar la serie completa antes de reenviar.

---

## 🔧 Script para Crear Todas las Columnas

Ver archivo: `database/crear_todas_columnas_pacs.sql`

---

_Última actualización: 31 de Octubre, 2025_

