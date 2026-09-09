# Guía Completa: Vinculación de Informes con Estudios en PACS

## 🔗 Cómo Funciona la Vinculación

### Conceptos Clave DICOM

En DICOM, los objetos se organizan jerárquicamente:

```
Patient (Paciente)
  └── Study (Estudio)
      ├── StudyInstanceUID: Identificador ÚNICO del estudio
      ├── AccessionNumber: Número de acceso
      ├── PatientID: ID del paciente
      └── Series (Serie)
          ├── SeriesInstanceUID: Identificador único de la serie
          └── Instance (Instancia/Imagen)
              └── SOPInstanceUID: Identificador único de la instancia
```

### ⭐ Vinculación por StudyInstanceUID

**Regla de oro DICOM:**
> Dos objetos con el **mismo `StudyInstanceUID`** pertenecen al **mismo estudio**, independientemente de otros campos.

Esto significa que si el informe PDF usa el **mismo `StudyInstanceUID`** que el estudio de imágenes original, Orthanc los asociará automáticamente.

## 📊 Campos de Vinculación

### Campos que DEBEN Coincidir (Vinculación Garantizada)

| Campo | DICOM Tag | Descripción | Prioridad |
|-------|-----------|-------------|-----------|
| **StudyInstanceUID** | `(0020,000D)` | ⭐ **MÁS IMPORTANTE** - Identificador único del estudio | 🔴 **CRÍTICO** |
| PatientID | `(0010,0020)` | ID del paciente | 🔴 **CRÍTICO** |
| PatientName | `(0010,0010)` | Nombre del paciente | 🔴 **CRÍTICO** |
| AccessionNumber | `(0008,0050)` | Número de acceso | 🟡 Importante |
| StudyDate | `(0008,0020)` | Fecha del estudio | 🟡 Recomendado |

### Campos que PUEDEN Diferir (Nueva Serie)

| Campo | DICOM Tag | Descripción | Ejemplo |
|-------|-----------|-------------|---------|
| SeriesInstanceUID | `(0020,000E)` | Identificador único de la serie | Nuevo UID (serie PDF) |
| SeriesNumber | `(0020,0011)` | Número de serie | 2, 3, etc. (siguiente serie) |
| SeriesDescription | `(0008,103E)` | Descripción de la serie | "Informe Médico - PDF" |
| Modality | `(0008,0060)` | Modalidad | "OT" (Other, para PDF) |

## ✅ Implementación Actual

### Flujo de Vinculación Implementado

```php
// 1. Obtener StudyInstanceUID del estudio original
$originalStudyInstanceUID = null;

// Prioridad 1: Desde BD (si existe)
$originalStudyInstanceUID = $informe['study_instance_uid'] ?? null;

// Prioridad 2: Desde Orthanc (usando estudio_id)
if (empty($originalStudyInstanceUID) && !empty($informe['estudio_id'])) {
    $studyData = $orthancClient->getStudyDetails($informe['estudio_id']);
    $originalStudyInstanceUID = $studyData['study_instance_uid'] ?? null;
}

// 2. Usar StudyInstanceUID en tags DICOM
$dicomTags = [
    'StudyInstanceUID' => $originalStudyInstanceUID,  // ← VINCULACIÓN
    'AccessionNumber' => $studyData['accession_number'],
    'PatientID' => $studyData['patient_id'],
    'SeriesInstanceUID' => generateNewUID(),  // ← NUEVO (nueva serie)
    // ... otros campos
];
```

### Resultado en Orthanc

Si `StudyInstanceUID` coincide:

```
Study: CT de Tórax
├── StudyInstanceUID: 1.2.840...abc123
├── Series: Imágenes CT
│   └── SeriesInstanceUID: 1.2.840...001
└── Series: Informe PDF  ← VINCULADO CORRECTAMENTE
    └── SeriesInstanceUID: 1.2.840...002 (nuevo)
```

Si `StudyInstanceUID` NO coincide:

```
Study: CT de Tórax
└── StudyInstanceUID: 1.2.840...abc123

Study: Informe Médico  ← ESTUDIO SEPARADO (NO VINCULADO)
└── StudyInstanceUID: 1.2.840...xyz789 (diferente)
```

## 🔧 Campos en Base de Datos

### Tabla `informes`

| Campo | Tipo | Descripción | Uso para Vinculación |
|-------|------|-------------|----------------------|
| `estudio_id` | VARCHAR(255) | ID del estudio en Orthanc (Orthanc ID) | Obtener estudio desde Orthanc |
| `study_instance_uid` | VARCHAR(255) | **StudyInstanceUID del estudio** | ⭐ **Vinculación directa** |
| `accession_number` | VARCHAR(255) | Número de acceso | Verificación de vinculación |
| `pacs_instance_id` | VARCHAR(255) | ID de la instancia PDF en Orthanc | Referencia al PDF enviado |
| `pacs_study_id` | VARCHAR(255) | ID del estudio en Orthanc | Referencia al estudio en Orthanc |

## 📝 Cómo Almacenar StudyInstanceUID

### Opción 1: Al Crear el Informe (Recomendado)

Cuando se crea un informe desde un estudio, almacenar el `StudyInstanceUID`:

```php
// En api/informes/save.php
// Al guardar informe nuevo

// 1. Obtener estudio desde Orthanc
$studyData = $orthancClient->getStudyDetails($estudio_id);

// 2. Almacenar StudyInstanceUID
$insertQuery = "INSERT INTO informes (
    estudio_id,
    study_instance_uid,  // ← Guardar aquí
    patient_id,
    patient_name,
    // ... otros campos
) VALUES (?, ?, ?, ?, ...)";

$stmt->execute([
    $estudio_id,
    $studyData['study_instance_uid'],  // ← Aquí
    // ... otros valores
]);
```

### Opción 2: Actualizar Informes Existentes

Para informes ya creados sin `study_instance_uid`, se puede actualizar:

```sql
-- Script para actualizar informes existentes
-- (requiere ejecutar script PHP que obtenga de Orthanc)

UPDATE informes i
JOIN (
    -- Lógica para obtener StudyInstanceUID desde Orthanc
) AS study_data ON i.estudio_id = study_data.estudio_id
SET i.study_instance_uid = study_data.study_instance_uid
WHERE i.study_instance_uid IS NULL;
```

## 🎯 Verificación de Vinculación

### Cómo Verificar que Está Vinculado Correctamente

1. **En Orthanc Web Interface:**
   - Buscar el estudio por PatientID o AccessionNumber
   - Verificar que el estudio tiene múltiples series
   - Una serie debe contener imágenes
   - Otra serie debe contener el PDF del informe

2. **En Orthanc API:**
   ```bash
   GET /studies/{study_id}
   ```
   Debe retornar múltiples series en el array `Series`

3. **Verificar Tags DICOM:**
   ```bash
   GET /instances/{instance_id}/tags
   ```
   Verificar que `StudyInstanceUID` coincide con el estudio original

## 🐛 Problemas Comunes y Soluciones

### Problema 1: Informe Aparece como Estudio Separado

**Síntoma:** En Orthanc, el informe PDF aparece como un estudio completamente diferente del estudio de imágenes.

**Causa:** `StudyInstanceUID` del informe no coincide con el del estudio original.

**Solución:**
1. Verificar que `study_instance_uid` está guardado en BD
2. Verificar que se está usando al generar tags DICOM
3. Si no está en BD, obtener desde Orthanc antes de enviar

### Problema 2: No se Puede Obtener StudyInstanceUID

**Síntoma:** Logs muestran "ADVERTENCIA: No se pudo obtener StudyInstanceUID"

**Causa:** 
- Orthanc no está disponible
- Estudio no existe en Orthanc
- Permisos insuficientes

**Solución:**
1. Verificar conectividad con Orthanc
2. Verificar que el `estudio_id` es correcto
3. Almacenar `study_instance_uid` en BD al crear informe (prevención)

### Problema 3: Múltiples Informes Crean Múltiples Series

**Síntoma:** Cada vez que se envía el informe, aparece como nueva serie.

**Causa:** Comportamiento esperado. Cada envío crea una nueva instancia/instancia.

**Solución:** Si quieres actualizar en lugar de crear nueva serie:
- Sistema actual: Elimina instancia anterior antes de enviar nueva (ya implementado)

## 📋 Checklist de Vinculación Correcta

### Al Crear Informe:
- [ ] Obtener `study_instance_uid` del estudio desde Orthanc
- [ ] Guardar `study_instance_uid` en BD junto con el informe
- [ ] Guardar `accession_number` y otros datos relevantes

### Al Enviar a PACS:
- [ ] Cargar `study_instance_uid` desde BD (prioridad 1)
- [ ] Si no está en BD, obtener desde Orthanc (prioridad 2)
- [ ] Si no se puede obtener, advertir en logs
- [ ] Usar `study_instance_uid` en tags DICOM
- [ ] Verificar que `PatientID` y `AccessionNumber` coinciden

### En Orthanc:
- [ ] Verificar que informe aparece en el mismo estudio que las imágenes
- [ ] Verificar que tienen mismo `StudyInstanceUID`
- [ ] Verificar que aparecen como series diferentes dentro del mismo estudio

## 🚀 Mejora Recomendada: Guardar StudyInstanceUID al Crear

Para garantizar vinculación futura, se recomienda modificar `api/informes/save.php`:

```php
// Al crear informe desde estudio
if (!empty($estudio_id)) {
    try {
        $orthancClient = new OrthancClient();
        $studyData = $orthancClient->getStudyDetails($estudio_id);
        
        // Guardar StudyInstanceUID para vinculación futura
        $study_instance_uid = $studyData['study_instance_uid'] ?? null;
        $accession_number = $studyData['accession_number'] ?? null;
    } catch (Exception $e) {
        error_log("No se pudo obtener estudio desde Orthanc: " . $e->getMessage());
    }
}

// En INSERT
$insertQuery = "INSERT INTO informes (
    estudio_id,
    study_instance_uid,  // ← Nuevo campo
    accession_number,    // ← Guardar también
    // ... otros campos
) VALUES (?, ?, ?, ...)";
```

## 📚 Referencias

- **DICOM Standard Part 3**: Information Object Definitions
- **Orthanc REST API**: `/studies/{id}` - Obtener información de estudio
- **Orthanc Documentation**: Study Structure and Hierarchy

---

**Conclusión:** La vinculación correcta requiere que el **`StudyInstanceUID` del informe coincida con el del estudio original**. El sistema actual intenta obtenerlo desde Orthanc, pero lo ideal es guardarlo en BD al crear el informe.

