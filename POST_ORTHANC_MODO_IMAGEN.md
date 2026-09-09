# 📤 POST a Orthanc - Modo Imagen (PNG)

## 🎯 Estructura del POST

Cuando el toggle está en **modo imagen (PNG)**, el POST que se envía a Orthanc tiene esta estructura:

---

## 📋 POST a Orthanc

### Endpoint:
```
POST http://localhost:8042/tools/create-dicom
```

### Headers:
```
Content-Type: application/json
```

### Body (JSON):

```json
{
  "Modality": "SC",
  "PatientID": "12345678",
  "PatientName": "APELLIDO^NOMBRE",
  "PatientBirthDate": "19800101",
  "PatientSex": "M",
  "StudyDate": "20251030",
  "StudyTime": "120000",
  "StudyDescription": "INFORME - JPG",
  "SeriesDescription": "INFORME - JPG",
  "AccessionNumber": "ACC123",
  "ReferringPhysicianName": "DR. APELLIDO",
  "InstitutionName": "INSTITUCION",
  "Image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA... [IMAGEN BASE64 COMPLETA]",
  "Parent": "abc123-def456-ghi789"  // Solo si hay estudio padre
}
```

---

## 🔍 Campos Importantes

### 1. **Modality**
```json
"Modality": "SC"
```
- **SC** = Secondary Capture (para imágenes)
- Diferente a PDF que usa **Encapsulated PDF**

### 2. **Image**
```json
"Image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
```
- Imagen PNG en base64
- Formato: `data:image/png;base64,{base64_encoded_image}`
- Tamaño puede ser muy grande (depende del tamaño del informe)

### 3. **SeriesDescription**
```json
"SeriesDescription": "INFORME - JPG"
```
- Indica que es un informe enviado como imagen JPG/PNG
- Diferente a PDF que dice "INFORME - PDF"

### 4. **Parent** (Opcional)
```json
"Parent": "abc123-def456-ghi789"
```
- Solo presente si hay un estudio padre existente
- Orthanc Study ID del estudio original
- Usado para vincular el informe al estudio

---

## 📊 Tags DICOM Incluidos

El POST incluye estos tags DICOM:

| Tag | Descripción | Ejemplo |
|-----|-------------|---------|
| `Modality` | Modalidad DICOM | `SC` |
| `PatientID` | ID del paciente | `12345678` |
| `PatientName` | Nombre del paciente | `APELLIDO^NOMBRE` |
| `PatientBirthDate` | Fecha de nacimiento | `19800101` |
| `PatientSex` | Sexo | `M` / `F` |
| `StudyDate` | Fecha del estudio | `20251030` |
| `StudyTime` | Hora del estudio | `120000` |
| `StudyDescription` | Descripción del estudio | `INFORME - JPG` |
| `SeriesDescription` | Descripción de la serie | `INFORME - JPG` |
| `AccessionNumber` | Número de acceso | `ACC123` |
| `ReferringPhysicianName` | Médico que refiere | `DR. APELLIDO` |
| `InstitutionName` | Institución | `INSTITUCION` |

---

## 🔄 Proceso de Envío

### Paso 1: Generar PDF
```
HTML del informe → TCPDF → PDF
```

### Paso 2: Convertir PDF a PNG
```
PDF → Imagick/GhostScript → PNG
```

### Paso 3: Convertir PNG a Base64
```
PNG → base64_encode() → Base64 String
```

### Paso 4: Construir Payload
```php
$payload = [
    // Tags DICOM
    'Modality' => 'SC',
    'PatientID' => '...',
    // ...
    
    // Imagen en base64
    'Image' => 'data:image/png;base64,' . $base64
];
```

### Paso 5: POST a Orthanc
```php
POST /tools/create-dicom
Content-Type: application/json
Body: {JSON payload}
```

---

## 📝 Ejemplo Completo

### Request:
```http
POST http://localhost:8042/tools/create-dicom HTTP/1.1
Host: localhost:8042
Content-Type: application/json
Content-Length: 1234567

{
  "Modality": "SC",
  "PatientID": "12345678",
  "PatientName": "GARCIA^JUAN",
  "PatientBirthDate": "19800101",
  "PatientSex": "M",
  "StudyDate": "20251030",
  "StudyTime": "120000",
  "StudyDescription": "INFORME - JPG",
  "SeriesDescription": "INFORME - JPG",
  "AccessionNumber": "ACC123",
  "ReferringPhysicianName": "DR. PEREZ",
  "InstitutionName": "INSTITUCION MEDICA",
  "Image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA... [MILES DE CARACTERES]"
}
```

### Response (Éxito):
```json
{
  "ID": "abc123-def456-ghi789",
  "ParentStudy": "xyz123-uvw456-rst789",
  "ParentSeries": "series123-456-789",
  "Path": "/instances/abc123-def456-ghi789",
  "Type": "Instance"
}
```

---

## 🔍 Ver Logs

Los logs mostrarán el POST completo:

```bash
[ORTHANC][SEND_IMAGE] ===== POST A ORTHANC (MODO IMAGEN) =====
[ORTHANC][SEND_IMAGE] URL: http://localhost:8042/tools/create-dicom
[ORTHANC][SEND_IMAGE] Método: POST
[ORTHANC][SEND_IMAGE] Content-Type: application/json
[ORTHANC][SEND_IMAGE] Payload (estructura): {...}
[ORTHANC][SEND_IMAGE] Tags DICOM: {...}
[ORTHANC][SEND_IMAGE] Tamaño imagen: 2.5 MB
[ORTHANC][SEND_IMAGE] Tamaño base64: 3456.78 KB
[ORTHANC][SEND_IMAGE] ===== FIN POST A ORTHANC =====
```

**Ver logs:**
```bash
cd C:\wamp64\www\PORTAL_ESTUDIOS
tail -f logs/php_errors.log | findstr "ORTHANC.*SEND_IMAGE"
```

---

## 🔄 Comparación: PDF vs PNG

### Modo PDF:
```json
{
  "Modality": "DOC",
  "Tags": {...},
  "Content": "data:application/pdf;base64,JVBERi0xLjQK..."
}
```

### Modo PNG (Imagen):
```json
{
  "Modality": "SC",
  "Tags": {...},
  "Image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
}
```

**Diferencias:**
- ✅ **PDF:** `Content` con PDF base64
- ✅ **PNG:** `Image` con PNG base64
- ✅ **PDF:** Modality = `DOC`
- ✅ **PNG:** Modality = `SC`

---

## 🎯 Código Fuente

El POST se construye en:
```php
// api/OrthancPacsSender.php
// Método: sendImageAsDicom()

$payload = $dicomTags;
$payload['Image'] = "data:image/png;base64,{$imageBase64}";

if (!empty($parentOrthancStudyId)) {
    $payload['Parent'] = $parentOrthancStudyId;
}

POST /tools/create-dicom
Body: $payload
```

---

## ✅ Verificar el POST

### Método 1: Ver Logs
```bash
tail -f logs/php_errors.log | findstr "ORTHANC.*SEND_IMAGE"
```

### Método 2: Modo Debug en Código
Agregar antes del POST:
```php
error_log('Payload completo: ' . json_encode($payload, JSON_PRETTY_PRINT));
```

### Método 3: Usar Herramienta de Red
1. Abrir DevTools (F12)
2. Ir a Network
3. Filtrar por "create-dicom"
4. Ver Request Payload

---

## 📋 Resumen

**Cuando el toggle está en modo imagen:**
1. ✅ Se genera PDF del informe
2. ✅ Se convierte PDF a PNG (con Imagick/GhostScript)
3. ✅ Se codifica PNG a base64
4. ✅ Se construye payload con `Image: "data:image/png;base64,..."`
5. ✅ Se envía POST a `/tools/create-dicom` con Modality = `SC`
6. ✅ Orthanc crea instancia DICOM de tipo Secondary Capture

**Ver logs para ver el POST completo.**

---

_Última actualización: 30 de Octubre, 2025_  
_Para ver el POST completo en acción, revisar logs después de enviar un informe con toggle en modo imagen_

