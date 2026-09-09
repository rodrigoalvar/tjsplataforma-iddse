# 🔗 Cómo se Vincula el Informe con el Estudio en PACS

## 📋 Respuesta Directa

El informe se vincula con el estudio en PACS usando **`StudyInstanceUID`** (Study Instance UID), que es el identificador único DICOM del estudio.

## 🎯 Identificadores DICOM Usados

### Estudio (Study) - Nivel de Agrupación Principal

| Identificador | Tag DICOM | Uso | Coincidencia Requerida |
|---------------|-----------|-----|------------------------|
| **StudyInstanceUID** | `(0020,000D)` | ⭐ **Identificador ÚNICO del estudio** | ✅ **DEBE ser el MISMO** |
| StudyID | `(0020,0010)` | ID secundario (menos usado) | 🟡 Opcional |
| AccessionNumber | `(0008,0050)` | Número de acceso | ✅ **Recomendado igual** |

### Serie (Series) - Subdivisión del Estudio

| Identificador | Tag DICOM | Uso | Coincidencia Requerida |
|---------------|-----------|-----|------------------------|
| SeriesInstanceUID | `(0020,000E)` | Identificador único de la serie | ❌ **NUEVO** (nueva serie) |
| SeriesNumber | `(0020,0011)` | Número de serie | ❌ **NUEVO** (puede ser 2, 3, etc.) |

## 🔗 Cómo Funciona la Vinculación

### Ejemplo Visual:

```
Estudio Original: CT de Tórax
├── StudyInstanceUID: 1.2.840.10008.1.1.20240115.123456.1  ← IDENTIFICADOR CLAVE
├── AccessionNumber: ACC123456
├── PatientID: PAT001
│
├── Serie 1: Imágenes CT
│   ├── SeriesInstanceUID: 1.2.840...001
│   ├── SeriesNumber: 1
│   ├── Modality: CT
│   └── Instances: [Imagen 1.dcm, Imagen 2.dcm, ...]
│
└── Serie 2: Informe PDF  ← VINCULADO (mismo StudyInstanceUID)
    ├── SeriesInstanceUID: 1.2.840...002  ← NUEVO (serie diferente)
    ├── SeriesNumber: 2                    ← SIGUIENTE número
    ├── Modality: OT                       ← Diferente (PDF)
    └── Instance: Informe.pdf
```

## ✅ Qué Campos Coinciden y Cuáles No

### Campos que COINCIDEN (Vinculación):

```php
StudyInstanceUID: "1.2.840...abc123"  // ← MISMO que estudio original
AccessionNumber: "ACC123456"          // ← MISMO
PatientID: "PAT001"                  // ← MISMO
PatientName: "JUAN PEREZ"            // ← MISMO
StudyDate: "20240115"                // ← MISMO (o muy cercano)
```

**Resultado:** Orthanc reconoce que pertenecen al mismo estudio → **VINCULADO**

### Campos que DIFIEREN (Nueva Serie):

```php
SeriesInstanceUID: "1.2.840...xyz789"  // ← NUEVO (serie diferente)
SeriesNumber: "2"                       // ← SIGUIENTE número
SeriesDescription: "Informe Médico - PDF"  // ← NUEVO
Modality: "OT"                          // ← Diferente (PDF vs CT)
```

**Resultado:** El PDF aparece como **nueva serie** dentro del mismo estudio → **CORRECTO**

## 📊 Flujo de Vinculación en el Código

### Paso 1: Obtener StudyInstanceUID

```php
// Prioridad 1: Desde BD (más confiable)
$originalStudyInstanceUID = $informe['study_instance_uid'] ?? null;

// Prioridad 2: Desde Orthanc (si no está en BD)
if (empty($originalStudyInstanceUID) && !empty($informe['estudio_id'])) {
    $studyData = $orthancClient->getStudyDetails($informe['estudio_id']);
    $originalStudyInstanceUID = $studyData['study_instance_uid'] ?? null;
}

// Prioridad 3: Generar nuevo (solo si no se puede obtener)
// ⚠️ Esto crea estudio independiente (no vinculado)
```

### Paso 2: Usar en Tags DICOM

```php
$dicomTags = [
    'StudyInstanceUID' => $originalStudyInstanceUID,  // ← VINCULACIÓN
    'AccessionNumber' => $studyData['accession_number'],
    'PatientID' => $studyData['patient_id'],
    'SeriesInstanceUID' => generateNewUID(),  // ← Nueva serie
    // ... otros campos
];
```

### Paso 3: Envío a Orthanc

```php
// Orthanc recibe el PDF con:
// - StudyInstanceUID = "1.2.840...abc123" (mismo que estudio original)
// - PatientID = "PAT001" (mismo)
// - AccessionNumber = "ACC123456" (mismo)
// → Orthanc asocia automáticamente como nueva serie del estudio existente
```

## 🔍 Verificación de Vinculación

### En Orthanc Web Interface:

1. Buscar el estudio de imágenes (CT, MR, etc.)
2. Abrir el estudio
3. Verificar que hay **múltiples series** en la lista:
   - ✅ **Serie 1**: Imágenes DICOM
   - ✅ **Serie 2**: Informe PDF
4. Si ambas aparecen → **VINCULADO CORRECTAMENTE** ✅

### Via Orthanc API:

```bash
# Obtener información del estudio
GET /studies/{study_id}

# Respuesta:
{
  "Series": [
    "series-1-id",  // Imágenes
    "series-2-id"   // PDF Informe ← Debe aparecer aquí
  ],
  "MainDicomTags": {
    "StudyInstanceUID": "1.2.840...abc123"
  }
}
```

### Verificar Tags del PDF:

```bash
GET /instances/{pdf_instance_id}/tags

# Verificar:
{
  "StudyInstanceUID": "1.2.840...abc123",  // ← Debe coincidir con estudio
  "SeriesInstanceUID": "1.2.840...xyz789", // ← Diferente (nueva serie)
  "Modality": "OT"                          // ← Diferente (PDF)
}
```

## 🎯 Resumen por Nivel DICOM

### Nivel Estudio (Study)
- **StudyInstanceUID**: ✅ **MISMO** (vinculación)
- **AccessionNumber**: ✅ **MISMO** (verificación)
- **PatientID**: ✅ **MISMO** (verificación)
- **StudyDate**: ✅ **MISMO** (preferible)

### Nivel Serie (Series)
- **SeriesInstanceUID**: ❌ **NUEVO** (serie diferente)
- **SeriesNumber**: ❌ **NUEVO** (siguiente número)
- **SeriesDescription**: ❌ **NUEVO** ("Informe Médico - PDF")
- **Modality**: ❌ **DIFERENTE** ("OT" para PDF)

### Nivel Instancia (Instance)
- **SOPInstanceUID**: ❌ **NUEVO** (instancia nueva)
- **InstanceNumber**: ❌ **NUEVO** (generalmente "1")

## 📝 Respuesta Directa a tu Pregunta

**¿Cómo se vincula el informe con el estudio en PACS?**

1. **Usando `StudyInstanceUID`**: Es el **identificador clave** que vincula todo
2. **Debe ser el mismo** que el estudio original
3. **No se usa `study_id`** (ese es el ID interno de Orthanc, no un UID DICOM)
4. **No se usa solo `SeriesID`** (eso solo identifica la serie, no vincula con el estudio)

**En resumen:**
- ✅ **`StudyInstanceUID`** → Vinculación al estudio
- ❌ **`study_id`** (Orthanc ID) → Solo para obtener información
- ❌ **`SeriesInstanceUID`** → Solo identifica la serie, no vincula

## ✅ Estado de Implementación

El sistema actual:
- ✅ Obtiene `StudyInstanceUID` del estudio original (desde BD o Orthanc)
- ✅ Lo usa en tags DICOM del informe
- ✅ Garantiza vinculación correcta
- ✅ Crea nueva serie (no nuevo estudio)
- ✅ Actualiza BD para uso futuro

**Resultado:** El informe aparece como **nueva serie dentro del mismo estudio** en Orthanc PACS.

---

**Versión:** 1.0.0  
**Fecha:** Enero 2025

