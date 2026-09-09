# ✅ Implementación: Vinculación de Informes con Estudios en PACS

## 📋 Resumen Ejecutivo

Se ha implementado la vinculación correcta de informes PDF con estudios DICOM existentes en Orthanc PACS usando **`StudyInstanceUID`**.

## 🔗 Cómo Funciona la Vinculación

### Identificador Clave: StudyInstanceUID

**Regla DICOM:**
> Todos los objetos con el **mismo `StudyInstanceUID`** pertenecen al **mismo estudio**.

Por lo tanto:

1. **Si el informe usa el mismo `StudyInstanceUID`** → Se vincula al estudio existente (aparece como nueva serie)
2. **Si el informe usa un `StudyInstanceUID` diferente** → Se crea como estudio separado (desvinculado)

## ✅ Implementación Actual

### 1. Obtención de StudyInstanceUID (Prioridad)

```
Prioridad 1: Desde BD (informes.study_instance_uid)
    ↓ (si no existe)
Prioridad 2: Desde Orthanc (usando estudio_id)
    ↓ (si no se puede obtener)
Prioridad 3: Generar nuevo (y advertir en logs)
```

### 2. Uso en Tags DICOM

```php
$dicomTags = [
    'StudyInstanceUID' => $originalStudyInstanceUID,  // ← MISMO que estudio original
    'SeriesInstanceUID' => generateNewUID(),           // ← NUEVO (nueva serie)
    'SeriesNumber' => '1',                            // Puede ser el siguiente número
    'AccessionNumber' => $studyData['accession_number'], // ← MISMO
    'PatientID' => $studyData['patient_id'],          // ← MISMO
    // ... otros campos
];
```

### 3. Almacenamiento en BD

El sistema intenta guardar `study_instance_uid` en BD:
- Al crear informe: Si viene en el input, se guarda
- Al enviar a PACS: Si se obtiene desde Orthanc, se actualiza en BD para uso futuro

## 📊 Campos DICOM de Vinculación

### Campos que DEBEN Coincidir (Vinculación)

| Campo | Uso Actual | Estado |
|-------|------------|--------|
| **StudyInstanceUID** | ⭐ Se obtiene desde BD o Orthanc | ✅ **IMPLEMENTADO** |
| PatientID | Se usa del estudio original | ✅ **IMPLEMENTADO** |
| AccessionNumber | Se usa del estudio original | ✅ **IMPLEMENTADO** |
| PatientName | Se usa del estudio original | ✅ **IMPLEMENTADO** |
| StudyDate | Se intenta usar del estudio original | ✅ **MEJORADO** |

### Campos que Pueden Diferir (Nueva Serie)

| Campo | Valor Actual | Explicación |
|-------|--------------|-------------|
| SeriesInstanceUID | Nuevo UID generado | ✅ Correcto (nueva serie) |
| SeriesNumber | '1' (fijo) | ⚠️ Podría mejorarse para usar siguiente número |
| SeriesDescription | "Informe Médico - PDF" | ✅ Correcto |
| Modality | "OT" (Other) | ✅ Correcto para PDF |

## 🔄 Flujo Completo de Vinculación

### Escenario 1: Informe Vinculado Correctamente ✅

```
1. Usuario crea informe desde estudio CT de Tórax
   - estudio_id = "orthanc-study-123"
   
2. Sistema guarda informe en BD
   - estudio_id = "orthanc-study-123"
   - study_instance_uid = NULL (inicialmente)
   
3. Usuario envía informe a PACS
   
4. Sistema obtiene estudio desde Orthanc:
   - StudyInstanceUID: "1.2.840...abc123"
   - AccessionNumber: "ACC123456"
   - PatientID: "PAT001"
   
5. Sistema actualiza BD:
   - study_instance_uid = "1.2.840...abc123"  ← Guardado para futuro
   
6. Sistema genera tags DICOM:
   - StudyInstanceUID: "1.2.840...abc123"  ← MISMO que estudio original
   - SeriesInstanceUID: "1.2.840...xyz789"  ← NUEVO (nueva serie)
   
7. Sistema envía a Orthanc
   
8. Resultado en Orthanc:
   Study: CT de Tórax
   ├── Series: Imágenes CT
   └── Series: Informe PDF  ✅ VINCULADO
```

### Escenario 2: Informe sin Vincular (Fallback) ⚠️

```
1. Usuario crea informe
2. estudio_id = "orthanc-study-123"
3. study_instance_uid = NULL
   
4. Usuario envía a PACS
5. Sistema intenta obtener desde Orthanc → FALLA (Orthanc no disponible)
6. Sistema genera nuevo StudyInstanceUID
7. Sistema advierte en logs
8. Resultado:
   Study: Informe Médico (INDEPENDIENTE)  ⚠️ NO VINCULADO
```

## 🎯 Mejoras Implementadas

### 1. Obtención Automática de StudyInstanceUID

**En `send-to-pacs.php`:**
- ✅ Prioridad 1: Desde BD (`informes.study_instance_uid`)
- ✅ Prioridad 2: Desde Orthanc (usando `estudio_id`)
- ✅ Actualización automática en BD si se obtiene desde Orthanc
- ✅ Advertencia en logs si no se puede obtener

### 2. Uso Correcto en Tags DICOM

**En `generateDicomTags()`:**
- ✅ Usa `study_instance_uid` si está disponible (vinculación)
- ✅ Genera nuevo solo si no está disponible (fallback)
- ✅ Logging para debugging

### 3. Mejora de Fechas

**En `send-to-pacs.php`:**
- ✅ Intenta usar fecha del estudio original
- ✅ Formatea correctamente (YYYYMMDD)

## 📝 Campos en Base de Datos

### Tabla `informes` - Campos Relacionados

| Campo | Tipo | Propósito | Uso para Vinculación |
|-------|------|-----------|----------------------|
| `estudio_id` | VARCHAR(255) | ID del estudio en Orthanc (Orthanc ID, no UID) | Obtener estudio desde API |
| `study_instance_uid` | VARCHAR(255) | **StudyInstanceUID DICOM** | ⭐ **Vinculación directa** |
| `accession_number` | VARCHAR(255) | Número de acceso DICOM | Verificación de vinculación |
| `pacs_instance_id` | VARCHAR(255) | ID de instancia PDF en Orthanc | Referencia al PDF enviado |
| `pacs_study_id` | VARCHAR(255) | ID del estudio en Orthanc | Referencia al estudio |

## ✅ Verificación de Vinculación

### Cómo Verificar que Está Vinculado

#### 1. En Orthanc Web Interface:

1. Buscar paciente por PatientID
2. Abrir el estudio
3. Verificar que hay **múltiples series**:
   - Serie 1: Imágenes (CT, MR, etc.)
   - Serie 2: Informe PDF
4. ✅ Si aparecen ambas series en el mismo estudio → **Correctamente vinculado**

#### 2. Via API Orthanc:

```bash
# Obtener estudio
GET /studies/{study_id}

# Verificar que retorna múltiples series
{
  "Series": [
    "series-id-1",  // Imágenes
    "series-id-2"   // PDF Informe
  ]
}
```

#### 3. Verificar Tags DICOM:

```bash
# Obtener tags del PDF
GET /instances/{pdf_instance_id}/tags

# Verificar StudyInstanceUID
{
  "StudyInstanceUID": "1.2.840...abc123"
}

# Comparar con estudio original
GET /studies/{study_id}

{
  "MainDicomTags": {
    "StudyInstanceUID": "1.2.840...abc123"  // ← Debe coincidir
  }
}
```

## 🐛 Troubleshooting

### Problema: Informe aparece como estudio separado

**Causa:** `StudyInstanceUID` del informe no coincide con el del estudio original.

**Solución:**
1. Verificar en BD: `SELECT study_instance_uid FROM informes WHERE id = ?`
2. Si es NULL, verificar logs al enviar a PACS
3. Si está pero es diferente, el estudio original puede haber cambiado
4. Reenviar informe para actualizar

### Problema: "ADVERTENCIA: No se pudo obtener StudyInstanceUID"

**Causa:** No se puede conectar con Orthanc o el estudio no existe.

**Solución:**
1. Verificar conectividad con Orthanc
2. Verificar que `estudio_id` es correcto
3. Verificar permisos de acceso a Orthanc
4. Almacenar `study_instance_uid` en BD al crear informe (prevención)

### Problema: "El informe se creará como estudio independiente"

**Causa:** No se pudo obtener `StudyInstanceUID` del estudio original.

**Resultado:** El informe se crea como estudio nuevo en Orthanc, no vinculado.

**Impacto:** 
- ⚠️ El informe aparecerá separado del estudio de imágenes
- ⚠️ No se asociarán automáticamente
- ✅ El contenido del informe es correcto
- ✅ Se puede buscar por PatientID o AccessionNumber

## 🚀 Mejora Futura Recomendada

### Obtener StudyInstanceUID al Crear Informe

Actualmente en `save.php`, el `study_instance_uid` solo se guarda si viene en el input. Se recomienda obtenerlo automáticamente:

```php
// En api/informes/save.php
if (!empty($input['estudio_id']) && empty($studyInstanceUID)) {
    try {
        require_once '../OrthancClient.php';
        $orthancClient = new OrthancClient();
        $studyData = $orthancClient->getStudyDetails($input['estudio_id']);
        $studyInstanceUID = $studyData['study_instance_uid'] ?? null;
        
        if ($studyInstanceUID) {
            error_log("StudyInstanceUID obtenido al crear informe: {$studyInstanceUID}");
        }
    } catch (Exception $e) {
        error_log("No se pudo obtener study_instance_uid al crear informe: " . $e->getMessage());
    }
}
```

**Beneficio:**
- ✅ Vinculación garantizada desde el inicio
- ✅ No depende de Orthanc al enviar a PACS
- ✅ Historial completo en BD

## 📊 Resumen de Implementación

### ✅ Implementado:

- [x] Obtención de `StudyInstanceUID` desde BD (prioridad 1)
- [x] Obtención de `StudyInstanceUID` desde Orthanc (prioridad 2)
- [x] Actualización automática en BD si se obtiene desde Orthanc
- [x] Uso correcto en tags DICOM
- [x] Logging para debugging
- [x] Manejo de errores y advertencias
- [x] Uso de AccessionNumber y PatientID del estudio original
- [x] Uso de fechas del estudio original cuando está disponible

### ⏳ Mejoras Futuras (Opcional):

- [ ] Obtener `study_instance_uid` automáticamente al crear informe
- [ ] Calcular `SeriesNumber` siguiente (no siempre '1')
- [ ] Validación que StudyInstanceUID coincide antes de enviar
- [ ] Notificación al usuario si no se puede vincular

## 🎯 Resultado Final

El sistema ahora:

1. ✅ **Intenta obtener** `StudyInstanceUID` del estudio original
2. ✅ **Usa el mismo** `StudyInstanceUID` si está disponible
3. ✅ **Crea nueva serie** dentro del estudio existente (no nuevo estudio)
4. ✅ **Vincula correctamente** el informe PDF con el estudio de imágenes
5. ✅ **Actualiza BD** para uso futuro

**Estado:** ✅ **IMPLEMENTADO Y FUNCIONAL**

---

**Última actualización:** Enero 2025  
**Documentación relacionada:**
- `docs/vinculacion-informes-estudios-pacs.md`
- `docs/guia-vinculacion-pacs.md`

