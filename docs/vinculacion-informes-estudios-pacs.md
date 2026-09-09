# Vinculación de Informes con Estudios en PACS

## 📋 Resumen

Cuando se envía un informe médico como PDF a Orthanc PACS, es **crítico** que se vincule correctamente con el estudio DICOM original usando los identificadores DICOM correctos.

## 🔗 Identificadores DICOM para Vinculación

### Identificadores Principales (Nivel de Estudio)

1. **`StudyInstanceUID`** ⭐ **MÁS IMPORTANTE**
   - **UID único del estudio** en DICOM
   - **Debe ser el mismo** que el estudio original
   - Si el informe usa el mismo `StudyInstanceUID`, Orthanc lo asociará automáticamente al estudio existente
   - **Formato**: UID DICOM (ej: `1.2.840.10008.1.1.20240115.123456.1`)

2. **`AccessionNumber`**
   - Número de acceso del estudio
   - Debe coincidir con el estudio original
   - **Formato**: String (ej: `ACC123456`)

3. **`PatientID`**
   - ID del paciente
   - Debe coincidir con el estudio original
   - **Formato**: String (ej: `PAT001`)

4. **`StudyDate` y `StudyTime`**
   - Fecha y hora del estudio original
   - Deben coincidir (o ser muy cercanos) al estudio original

### Identificadores Secundarios (Nivel de Serie)

5. **`SeriesInstanceUID`**
   - UID único de la serie
   - **Puede ser diferente** (el informe será una nueva serie dentro del mismo estudio)
   - **Formato**: UID DICOM

6. **`SeriesNumber`**
   - Número de serie dentro del estudio
   - **Puede ser diferente** (el informe será una serie separada)

## ✅ Estrategia de Vinculación Correcta

### Escenario Ideal: Informe Vinculado a Estudio Existente

Para que el informe se asocie con el estudio original en Orthanc:

```
Estudio Original (CT de Tórax):
  - StudyInstanceUID: 1.2.840.10008.1.1.20240115.123456.1
  - AccessionNumber: ACC123456
  - PatientID: PAT001
  - Series: Serie 1 (Imágenes DICOM)

Informe PDF:
  - StudyInstanceUID: 1.2.840.10008.1.1.20240115.123456.1  ← MISMO
  - AccessionNumber: ACC123456                               ← MISMO
  - PatientID: PAT001                                        ← MISMO
  - SeriesInstanceUID: 1.2.840.10008.1.1.20240115.123456.2  ← NUEVO (serie diferente)
  - SeriesNumber: 2                                          ← NUEVO
  - Modality: OT (Other)                                     ← Diferente (PDF, no imagen)

Resultado: Orthanc asocia el PDF como una NUEVA SERIE dentro del MISMO ESTUDIO
```

### ⚠️ Problema Actual

Si el informe usa un **`StudyInstanceUID` diferente**, Orthanc lo tratará como un **estudio completamente nuevo y separado**, no vinculado al estudio original.

## 🔧 Implementación Correcta

### Paso 1: Obtener StudyInstanceUID del Estudio Original

```php
// En send-to-pacs.php
if (!empty($informe['estudio_id'])) {
    $orthancClient = new OrthancClient();
    $studyData = $orthancClient->getStudyDetails($informe['estudio_id']);
    
    // OBTENER StudyInstanceUID del estudio original
    $originalStudyInstanceUID = $studyData['study_instance_uid'] ?? null;
}
```

### Paso 2: Usar StudyInstanceUID del Estudio Original

```php
$informeData = [
    // ... otros datos ...
    'study_instance_uid' => $originalStudyInstanceUID,  // ← CRÍTICO: Usar el del estudio original
    'accession_number' => $studyData['accession_number'],  // ← También importante
    'patient_id' => $studyData['patient_id'],            // ← También importante
    // ... resto de datos ...
];

// Generar tags DICOM
$dicomTags = OrthancPacsSender::generateDicomTags($informeData);
```

### Paso 3: Generar UIDs Apropiados

En `generateDicomTags()`:

```php
// Si se proporciona StudyInstanceUID, usarlo (vinculación con estudio existente)
// Si NO se proporciona, generar uno nuevo (estudio independiente)
$studyInstanceUID = $informeData['study_instance_uid'] ?? self::generateUID();

// SeriesInstanceUID siempre se genera nuevo (nueva serie dentro del estudio)
$seriesInstanceUID = $informeData['series_instance_uid'] ?? self::generateUID();
```

## 📊 Estructura en Orthanc

### Estudio Original (SIN Informe)

```
Patient: Juan Pérez (PAT001)
└── Study: CT de Tórax
    ├── StudyInstanceUID: 1.2.840...
    ├── AccessionNumber: ACC123456
    └── Series: Imágenes CT
        ├── SeriesInstanceUID: 1.2.840...001
        └── Instances: [Imagen 1, Imagen 2, ...]
```

### Estudio CON Informe (Correcto)

```
Patient: Juan Pérez (PAT001)
└── Study: CT de Tórax
    ├── StudyInstanceUID: 1.2.840... (mismo)
    ├── AccessionNumber: ACC123456 (mismo)
    ├── Series: Imágenes CT
    │   ├── SeriesInstanceUID: 1.2.840...001
    │   └── Instances: [Imagen 1, Imagen 2, ...]
    └── Series: Informe PDF  ← NUEVA SERIE
        ├── SeriesInstanceUID: 1.2.840...002  (nuevo)
        ├── SeriesNumber: 2
        ├── Modality: OT
        └── Instance: Informe.pdf
```

### Estudio SIN Informe (Incorrecto - StudyInstanceUID Diferente)

```
Patient: Juan Pérez (PAT001)
├── Study: CT de Tórax
│   └── StudyInstanceUID: 1.2.840...001
└── Study: Informe Médico (SIN VINCULAR) ❌
    └── StudyInstanceUID: 1.2.840...999 (diferente)
```

## 🎯 Campos de Vinculación por Nivel

### Nivel de Estudio (Deben Coincidir)

| Campo DICOM | Uso | Coincidencia Requerida |
|-------------|-----|------------------------|
| `StudyInstanceUID` | Identificador único del estudio | ✅ **DEBE ser igual** |
| `AccessionNumber` | Número de acceso | ✅ **Recomendado igual** |
| `PatientID` | ID del paciente | ✅ **DEBE ser igual** |
| `PatientName` | Nombre del paciente | ✅ **DEBE ser igual** |
| `StudyDate` | Fecha del estudio | ✅ **Recomendado igual o cercano** |
| `StudyTime` | Hora del estudio | ⚠️ Puede ser diferente |

### Nivel de Serie (Pueden Diferir)

| Campo DICOM | Uso | Coincidencia Requerida |
|-------------|-----|------------------------|
| `SeriesInstanceUID` | Identificador único de la serie | ❌ **NUEVO** (serie diferente) |
| `SeriesNumber` | Número de serie | ❌ **NUEVO** (ej: siguiente número) |
| `SeriesDescription` | Descripción de la serie | ❌ **NUEVO** (ej: "Informe Médico - PDF") |
| `Modality` | Modalidad | ❌ **NUEVO** (ej: "OT" para PDF) |

## 🔄 Flujo de Vinculación Actual (Necesita Corrección)

### Estado Actual:

```
1. Cargar informe desde BD
2. Intentar obtener estudio desde Orthanc usando estudio_id
3. Si obtiene study_instance_uid → lo usa
4. Si NO lo obtiene → GENERA NUEVO UID ← PROBLEMA
5. Envía PDF con StudyInstanceUID (puede ser nuevo)
```

### Problema:

Si el estudio no se puede obtener de Orthanc o no tiene `study_instance_uid`, se genera uno nuevo, **desvinculando** el informe del estudio original.

## ✅ Solución Recomendada

### Opción 1: Almacenar StudyInstanceUID en BD (Recomendada)

Al crear el informe, almacenar el `study_instance_uid` del estudio:

```sql
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS study_instance_uid VARCHAR(255) NULL 
COMMENT 'StudyInstanceUID del estudio DICOM original';
```

**Ventajas:**
- ✅ No depende de que Orthanc esté disponible al enviar
- ✅ Vinculación garantizada
- ✅ Historial completo

### Opción 2: Obtener desde Orthanc Siempre

**Ventajas:**
- ✅ Siempre actualizado
- ✅ No requiere cambios en BD

**Desventajas:**
- ❌ Requiere que Orthanc esté disponible
- ❌ Puede fallar si Orthanc no responde

### Opción 3: Híbrida (Recomendada para Implementar)

1. **Al crear informe**: Almacenar `study_instance_uid` en BD
2. **Al enviar a PACS**: 
   - Usar `study_instance_uid` de BD (si existe)
   - Si no existe, intentar obtener desde Orthanc
   - Si no se puede obtener, usar `estudio_id` como `AccessionNumber` pero generar nuevo `StudyInstanceUID` (y advertir al usuario)

## 📝 Campos DICOM Actuales vs Recomendados

### Campos Actuales en `generateDicomTags()`

```php
$tags = [
    'StudyInstanceUID' => $studyInstanceUID,  // ✅ Si viene de estudio original
    'AccessionNumber' => $informeData['accession_number'],  // ✅ Si viene de estudio original
    'PatientID' => $informeData['patient_id'],  // ✅ Si viene de estudio original
    'SeriesInstanceUID' => $seriesInstanceUID,  // ✅ Siempre nuevo (OK)
    'SeriesNumber' => '1',  // ⚠️ Debería ser el siguiente número de serie
    'Modality' => 'OT',  // ✅ Correcto para PDF
    // ... otros campos
];
```

### Mejoras Recomendadas

1. **Verificar que `StudyInstanceUID` coincida con el estudio original**
2. **Usar el siguiente `SeriesNumber` del estudio** (no siempre '1')
3. **Guardar `study_instance_uid` en BD al crear el informe**
4. **Validar que PatientID y AccessionNumber coincidan**

## 🎯 Prioridad de Vinculación

### Orden de Prioridad para Obtener StudyInstanceUID:

1. **Desde BD** (`informes.study_instance_uid`) ← **Más confiable**
2. **Desde Orthanc** (obtener estudio actual y extraer UID)
3. **Generar nuevo** (pero advertir que no se vinculará al estudio original)

---

**Conclusión:** El sistema **debe usar el mismo `StudyInstanceUID` del estudio original** para que Orthanc asocie el informe PDF como una nueva serie dentro del mismo estudio.

