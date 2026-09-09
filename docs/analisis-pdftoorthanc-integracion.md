# Análisis de Integración: PDFtoOrthanc con Sistema de Informes

## 📋 Resumen Ejecutivo

**PDFtoOrthanc** es una herramienta Python que convierte archivos PDF a formato DICOM Encapsulated PDF y los envía automáticamente a un servidor Orthanc PACS mediante su REST API.

## 🎯 Objetivo

Evaluar si esta herramienta sirve para enviar los informes médicos generados en nuestro sistema (`informes-manager.html`, `editor.html`) al PACS Orthanc y determinar las mejores formas de implementación.

## 🔍 Funcionalidades de PDFtoOrthanc

### Lo que hace la herramienta:

1. **Lectura de PDFs desde una carpeta** (monitoreo de directorio)
2. **Parsing del nombre del archivo** para extraer:
   - PatientID (ID del paciente)
   - Nombre del paciente (FirstName, MiddleName, LastName)
   - Fecha del estudio (StudyDate)
   - AccessionNumber (número de acceso)
3. **Conversión a DICOM Encapsulated PDF**:
   - SOP Class: `1.2.840.10008.5.1.4.1.1.104.1`
   - Convierte PDF a base64
   - Crea objeto DICOM con tags apropiadas
4. **Envío automático a Orthanc** mediante REST API:
   - Endpoint: `POST /tools/create-dicom`
   - Autenticación: Basic Auth
5. **Detección de duplicados** (múltiples criterios)
6. **Organización de archivos**:
   - `Procesados/` - PDFs enviados exitosamente
   - `Duplicados/` - PDFs que ya existen en Orthanc
   - `Errores/` - PDFs con errores

### Formatos de nombres de archivo soportados:

#### Formato Estructurado (Recomendado):
```
PatientID_FirstName_MiddleName_LastName_Date_AccessionNumber.pdf
```
Ejemplo: `12345_JUAN_PEDRO_SILVA_SANTOS_15012024_98765.pdf`

#### Formato Legado:
```
FirstName_MiddleName_LastName_Date.pdf
```
Ejemplo: `MARIA_OLIVEIRA_15012024.pdf`

## ✅ ¿SIRVE PARA NUESTRO SISTEMA?

### **SÍ, PERO REQUIERE ADAPTACIONES**

La herramienta **sí es útil**, pero necesitamos adaptarla o crear una solución integrada porque:

### Ventajas:
1. ✅ **Ya implementa la conversión PDF → DICOM**
2. ✅ **Ya tiene integración con Orthanc REST API**
3. ✅ **Maneja detección de duplicados**
4. ✅ **Maneja reintentos y errores**
5. ✅ **Procesamiento paralelo**

### Desventajas/Adaptaciones Necesarias:

1. ❌ **Monitorea carpetas, no genera PDFs desde HTML**
   - Necesitamos generar PDFs desde los informes HTML (TinyMCE)
   - No podemos simplemente guardar PDFs en una carpeta y esperar

2. ❌ **Depende del formato del nombre del archivo**
   - Nuestro sistema tiene la información en la base de datos
   - Podríamos generar PDFs con nombres estructurados, pero es mejor usar la BD directamente

3. ❌ **Es un script Python standalone**
   - Nuestro sistema es PHP/JavaScript
   - Necesitamos una integración más directa

## 🚀 FORMAS DE IMPLEMENTACIÓN

### **Opción 1: Adaptar la herramienta Python (No recomendada para producción inmediata)**

**Ventajas:**
- Reutiliza código existente
- Funciona como servicio independiente

**Desventajas:**
- Requiere ejecutar Python en el servidor
- Agregar dependencia externa
- Dificulta la integración directa con PHP

**Cuándo usar:**
- Si ya se tiene Python configurado en el servidor
- Si se quiere un servicio de procesamiento por lotes
- Si se quiere separar la generación de PDFs del envío a Orthanc

**Implementación:**
1. Modificar `PDFtoOrthanc.py` para aceptar parámetros desde línea de comandos
2. Generar PDFs desde PHP con nombres estructurados
3. Ejecutar script Python desde PHP con `exec()` o `shell_exec()`
4. Script procesa y envía a Orthanc

---

### **Opción 2: Implementar directamente en PHP (RECOMENDADA) ⭐**

**Ventajas:**
- ✅ Integración nativa con nuestro sistema PHP
- ✅ No requiere dependencias externas
- ✅ Control total del flujo
- ✅ Puede ejecutarse desde el frontend (botón "Enviar a PACS")
- ✅ Acceso directo a la base de datos
- ✅ Más fácil de depurar y mantener

**Desventajas:**
- Requiere implementar la lógica de conversión PDF → DICOM en PHP
- Necesita biblioteca PHP para generar/leer PDFs

**Implementación:**

#### 2.1. Generar PDF desde HTML del informe

**Opción A: Usar biblioteca PHP para generar PDF**
```php
// Ejemplo usando dompdf o similar
use Dompdf\Dompdf;

function generarPDFDesdeHTML($html, $informeData) {
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}
```

**Opción B: Usar API externa de generación PDF** (por ejemplo, servicio de conversión HTML→PDF)

#### 2.2. Convertir PDF a DICOM Encapsulated PDF

```php
function convertirPDFaDICOM($pdf_bytes, $tags_dicom) {
    // Codificar PDF en base64
    $pdf_b64 = base64_encode($pdf_bytes);
    
    // Crear payload para Orthanc
    $payload = [
        "Tags" => $tags_dicom,
        "Content" => "data:application/pdf;base64," . $pdf_b64
    ];
    
    return $payload;
}
```

#### 2.3. Enviar a Orthanc

```php
function enviarDICOMaOrthanc($payload) {
    $url = "http://localhost:8042/tools/create-dicom";
    $auth = base64_encode("orthanc:orthanc");
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    
    throw new Exception("Error enviando a Orthanc: HTTP $httpCode");
}
```

#### 2.4. Verificar duplicados (adaptado del script Python)

```php
function verificarDuplicado($orthanc_url, $auth_headers, $patient_id, $study_date, $accession_number = null) {
    // 1. Por AccessionNumber
    if ($accession_number) {
        $body = ["Level" => "Study", "Query" => ["AccessionNumber" => $accession_number]];
        $result = hacerPeticionOrthanc("POST", "$orthanc_url/tools/find", $body, $auth_headers);
        if (!empty($result)) {
            return ['existe' => true, 'study_id' => $result[0], 'metodo' => 'accession'];
        }
    }
    
    // 2. Por PatientID + StudyDate
    if ($patient_id) {
        $body = ["Level" => "Study", "Query" => ["PatientID" => $patient_id, "StudyDate" => $study_date]];
        $result = hacerPeticionOrthanc("POST", "$orthanc_url/tools/find", $body, $auth_headers);
        if (!empty($result)) {
            return ['existe' => true, 'study_id' => $result[0], 'metodo' => 'patient_id_date'];
        }
    }
    
    return ['existe' => false];
}
```

**Ubicación en el sistema:**
- Crear: `api/informes/send-to-pacs.php`
- Funcionalidad:
  1. Recibir `informe_id` desde el frontend
  2. Cargar informe desde BD
  3. Generar PDF desde HTML del informe
  4. Convertir a DICOM
  5. Enviar a Orthanc
  6. Registrar resultado en BD (opcional)

---

### **Opción 3: Híbrida - PHP genera PDF, Python procesa (Compromiso)**

**Implementación:**
1. PHP genera PDF con nombre estructurado y lo guarda en carpeta
2. Python (`PDFtoOrthanc.py`) monitorea la carpeta y envía a Orthanc
3. PHP puede verificar estado mediante consulta a Orthanc

**Ventajas:**
- Separa responsabilidades
- Permite procesamiento en background
- Reutiliza código Python existente

**Desventajas:**
- Requiere ambos PHP y Python
- Dependencia de sistema de archivos
- Latencia (no es inmediato)

---

## 📝 PLAN RECOMENDADO

### ✅ **IMPLEMENTACIÓN COMPLETADA:**

1. ✅ **Implementado en PHP directamente** (Opción 2)
2. ✅ **Clase reutilizable creada:** `api/OrthancPacsSender.php`
3. ✅ **API endpoint creado:** `api/informes/send-to-pacs.php`
4. ⏳ **Pendiente: Agregar botón "Enviar a PACS"** en:
   - `informes-manager.html` (lista de informes)
   - `editor.html` (después de guardar informe)
5. ⏳ **Pendiente: Instalar biblioteca PHP para PDF:**
   - `composer require dompdf/dompdf`
   - O `composer require knplabs/knp-snappy` para wkhtmltopdf

### 📚 **Documentación creada:**
- `docs/libreria-orthanc-pacs-sender.md`: Documentación completa de la librería
- `database/add_pacs_fields_to_informes.sql`: Script SQL para agregar campos opcionales

### **Flujo recomendado:**

```
Usuario guarda informe
    ↓
Usuario hace clic en "Enviar a PACS"
    ↓
Frontend llama a: POST /api/informes/send-to-pacs.php
    ↓
Backend PHP:
  1. Carga informe desde BD
  2. Verifica si ya existe en Orthanc (duplicado)
  3. Si no existe:
     - Genera PDF desde HTML (TinyMCE content)
     - Convierte PDF a DICOM Encapsulated PDF
     - Envía a Orthanc
     - Guarda referencia en BD (opcional: tabla informes_pacs_sync)
  4. Retorna resultado (éxito/error)
    ↓
Frontend muestra notificación al usuario
```

---

## 🔧 Dependencias Necesarias

### Para Opción 2 (PHP directo):

1. **Biblioteca PHP para PDF:**
   ```bash
   composer require dompdf/dompdf
   # O
   composer require knplabs/knp-snappy
   ```

2. **Biblioteca PHP para HTTP requests:**
   ```php
   // Ya tenemos acceso a curl o podemos usar Guzzle
   composer require guzzlehttp/guzzle
   ```

3. **Configuración de Orthanc:**
   - URL del servidor Orthanc
   - Credenciales (usuario/password)
   - Variables en `config/database.php` o archivo de configuración separado

---

## 📊 Tags DICOM Necesarias

Basándonos en la información que tenemos en nuestros informes:

```php
$tags = [
    "PatientID" => $informe['patient_id'],
    "PatientName" => $informe['patient_name'], // Formato: "NOMBRE APELLIDO"
    "PatientBirthDate" => $informe['patient_birth_date'] ?? '',
    "PatientSex" => $informe['patient_sex'] ?? '',
    
    "StudyInstanceUID" => $informe['study_instance_uid'] ?? generarUID(),
    "StudyDate" => date('Ymd', strtotime($informe['fecha_modificacion'])),
    "StudyTime" => date('His', strtotime($informe['fecha_modificacion'])),
    "StudyDescription" => "Informe Médico",
    "AccessionNumber" => $informe['estudio_id'] ?? '',
    
    "SeriesInstanceUID" => generarUID(),
    "SeriesNumber" => "1",
    "SeriesDescription" => "Informe Médico - PDF",
    "SeriesDate" => date('Ymd', strtotime($informe['fecha_modificacion'])),
    "SeriesTime" => date('His', strtotime($informe['fecha_modificacion'])),
    
    "Modality" => "OT", // "Other" - para informes no imagenológicos
    "SOPClassUID" => "1.2.840.10008.5.1.4.1.1.104.1", // Encapsulated PDF
    
    "InstanceNumber" => "1",
    "ContentDate" => date('Ymd', strtotime($informe['fecha_modificacion'])),
    "ContentTime" => date('His', strtotime($informe['fecha_modificacion'])),
    
    "InstitutionName" => obtenerNombreInstitucion(),
    "ReferringPhysicianName" => obtenerMedicoRef($informe['usuario_id']),
    
    // Opcional: Información del informe
    "StudyComments" => "Informe generado desde PORTAL_ESTUDIOS",
];
```

---

## 🎯 PRÓXIMOS PASOS SUGERIDOS

1. **Decision:**
   - ¿Queremos enviar automáticamente al guardar, o manualmente con botón?
   - ¿Queremos verificar duplicados antes de enviar?

2. **Implementación:**
   - Crear `api/informes/send-to-pacs.php`
   - Agregar botón en UI
   - Configurar credenciales de Orthanc

3. **Testing:**
   - Probar con informes de prueba
   - Verificar que aparezcan en Orthanc
   - Verificar que no se dupliquen

4. **Documentación:**
   - Documentar proceso de envío
   - Documentar configuración de Orthanc

---

## 📚 Referencias

- [Orthanc REST API Documentation](https://book.orthanc-server.com/users/rest-api.html)
- [DICOM Encapsulated PDF (SOP Class 1.2.840.10008.5.1.4.1.1.104.1)](http://dicom.nema.org/medical/dicom/current/output/chtml/part03/sect_A.49.1.html)
- Script original: `libs/PDFtoOrthanc-main/PDFtoOrthanc.py`
- Versión en español: `libs/PDFtoOrthanc-main/PDFtoOrthanc_ES.py`

---

**Conclusión:** La herramienta PDFtoOrthanc es útil como referencia, pero **recomendamos implementar directamente en PHP** para mejor integración con nuestro sistema existente.

