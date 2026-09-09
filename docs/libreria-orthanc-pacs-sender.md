# Librería: OrthancPacsSender

## 📋 Descripción

**OrthancPacsSender** es una clase PHP reutilizable que permite enviar archivos PDF como objetos DICOM Encapsulated PDF a un servidor Orthanc PACS mediante su REST API.

Esta librería proporciona una solución completa y lista para usar que puede integrarse en cualquier proyecto PHP que necesite enviar documentos médicos a un sistema PACS.

### Características Principales

- ✅ **Conversión automática**: Convierte PDFs a formato DICOM Encapsulated PDF
- ✅ **Envío directo**: Envía archivos a Orthanc mediante REST API
- ✅ **Verificación de duplicados**: Detecta estudios duplicados antes de enviar
- ✅ **Generación de tags DICOM**: Crea automáticamente las tags DICOM requeridas
- ✅ **Manejo de errores**: Sistema robusto de reintentos con backoff exponencial
- ✅ **Reutilizable**: Clase independiente que puede usarse en cualquier proyecto
- ✅ **Configurable**: Soporta configuración personalizada o uso de configuración por defecto

## 📁 Archivos

- **`api/OrthancPacsSender.php`**: Clase principal reutilizable
- **`api/informes/send-to-pacs.php`**: Endpoint API específico para enviar informes médicos

## 🚀 Instalación

### Requisitos

- PHP 7.4 o superior
- Extensión `curl` habilitada
- Extensión `json` habilitada
- Acceso al servidor Orthanc PACS

### Configuración

1. **Copiar archivos**: Copia `api/OrthancPacsSender.php` a tu proyecto

2. **Configurar Orthanc**: La clase usa `OrthancConfig` por defecto. Si no tienes este archivo, puedes configurarlo directamente:

```php
// En tu archivo de configuración
class OrthancConfig {
    public static function getServerUrl() {
        return 'http://tu-servidor-orthanc:8042';
    }
    
    public static function getCredentials() {
        return [
            'username' => 'orthanc',
            'password' => 'orthanc'
        ];
    }
    
    public static function getConfig() {
        return [
            'api' => [
                'timeout' => 60,
                'connect_timeout' => 10,
                'verify_ssl' => false
            ]
        ];
    }
}
```

3. **O usar configuración personalizada** (ver sección "Uso Básico")

## 📖 Uso Básico

### Enviar un PDF como DICOM

```php
<?php
require_once 'api/OrthancPacsSender.php';

// Crear instancia con configuración por defecto
$pacsSender = new OrthancPacsSender();

// Preparar tags DICOM
$dicomTags = [
    'PatientName' => 'JUAN PEDRO SILVA',
    'PatientID' => '12345',
    'StudyDate' => '20240115',
    'StudyTime' => '143000',
    'Modality' => 'OT',
    'StudyDescription' => 'Informe Médico',
    'AccessionNumber' => '98765',
    'InstitutionName' => 'HOSPITAL DIGITAL',
    'ReferringPhysicianName' => 'DR. SANTOS'
];

// Enviar PDF
$result = $pacsSender->sendPdfAsDicom('/ruta/al/informe.pdf', $dicomTags);

if ($result['success']) {
    echo "PDF enviado exitosamente!\n";
    echo "Instance ID: " . $result['instance_id'] . "\n";
    echo "Study ID: " . $result['study_id'] . "\n";
} else {
    echo "Error: " . $result['error'] . "\n";
}
?>
```

### Usar configuración personalizada

```php
<?php
$config = [
    'orthanc_url' => 'http://192.168.1.100:8042',
    'username' => 'admin',
    'password' => 'mi_contraseña',
    'timeout' => 90,
    'max_retries' => 5,
    'max_file_size_mb' => 100
];

$pacsSender = new OrthancPacsSender($config);
?>
```

### Verificar duplicados antes de enviar

```php
<?php
$pacsSender = new OrthancPacsSender();

$duplicateCheck = $pacsSender->checkDuplicate([
    'accession_number' => '98765',
    'patient_id' => '12345',
    'patient_name' => 'SILVA^JUAN^PEDRO', // Formato DICOM
    'patient_name_natural' => 'JUAN PEDRO SILVA', // Formato natural
    'study_date' => '20240115'
]);

if ($duplicateCheck['exists']) {
    echo "Ya existe un estudio con estos datos!\n";
    echo "Study ID: " . $duplicateCheck['study_id'] . "\n";
    echo "Método de detección: " . $duplicateCheck['method'] . "\n";
} else {
    echo "No se encontraron duplicados, puede proceder con el envío\n";
}
?>
```

### Generar tags DICOM automáticamente

```php
<?php
// Preparar datos del informe
$informeData = [
    'patient_id' => '12345',
    'patient_name' => 'JUAN PEDRO SILVA',
    'patient_birth_date' => '19800115',
    'patient_sex' => 'M',
    'study_date' => time(), // Timestamp o YYYYMMDD
    'modality' => 'OT',
    'accession_number' => '98765',
    'study_instance_uid' => null, // Se genera automáticamente si no se proporciona
    'study_description' => 'Informe de TC de Tórax',
    'institution_name' => 'HOSPITAL DIGITAL',
    'referring_physician' => 'DR. SANTOS'
];

// Generar tags DICOM
$dicomTags = OrthancPacsSender::generateDicomTags($informeData);

// Ahora puedes usar $dicomTags para enviar el PDF
$result = $pacsSender->sendPdfAsDicom('/ruta/al/informe.pdf', $dicomTags);
?>
```

## 🔌 Uso con API Endpoint

### Endpoint: `POST /api/informes/send-to-pacs.php`

Este endpoint está diseñado específicamente para enviar informes médicos desde la base de datos.

#### Request

```json
{
    "informe_id": 123,
    "check_duplicates": true
}
```

#### Headers

```
Authorization: Bearer <session_token>
Content-Type: application/json
```

#### Response (Éxito)

```json
{
    "success": true,
    "message": "Informe enviado exitosamente a Orthanc PACS",
    "data": {
        "instance_id": "abc123-def456-ghi789",
        "study_id": "study-xyz",
        "file_size_mb": 2.5
    }
}
```

#### Response (Duplicado encontrado)

```json
{
    "success": false,
    "message": "Ya existe un estudio con estos datos en Orthanc",
    "duplicate": true,
    "study_id": "existing-study-id",
    "method": "accession_number"
}
```

#### Response (Error)

```json
{
    "success": false,
    "message": "Descripción del error",
    "error_code": "SEND_TO_PACS_ERROR"
}
```

#### Ejemplo de uso desde JavaScript

```javascript
async function enviarInformeAPACS(informeId) {
    try {
        const response = await fetch('/api/informes/send-to-pacs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${sessionToken}`
            },
            body: JSON.stringify({
                informe_id: informeId,
                check_duplicates: true
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            console.log('✅ Informe enviado exitosamente');
            console.log('Instance ID:', result.data.instance_id);
            console.log('Study ID:', result.data.study_id);
        } else {
            if (result.duplicate) {
                console.warn('⚠️ Duplicado encontrado:', result.message);
                console.log('Study ID existente:', result.study_id);
            } else {
                console.error('❌ Error:', result.message);
            }
        }
    } catch (error) {
        console.error('Error en petición:', error);
    }
}
```

## 📊 Métodos Disponibles

### `sendPdfAsDicom(string $pdfPath, array $dicomTags): array`

Envía un archivo PDF como objeto DICOM a Orthanc.

**Parámetros:**
- `$pdfPath`: Ruta completa al archivo PDF
- `$dicomTags`: Array con las tags DICOM (ver "Tags DICOM Requeridas")

**Retorna:**
```php
[
    'success' => true/false,
    'instance_id' => 'ID del objeto DICOM',
    'study_id' => 'ID del estudio',
    'message' => 'Mensaje descriptivo',
    'error' => 'Mensaje de error (si success = false)',
    'file_size_mb' => 2.5
]
```

### `deleteInstance(string $instanceId): array`

Elimina una instancia DICOM de Orthanc. Útil para actualizar informes (eliminar versión anterior).

**Parámetros:**
- `$instanceId`: ID de la instancia DICOM a eliminar

**Retorna:**
```php
[
    'success' => true/false,
    'message' => 'Mensaje descriptivo',
    'instance_id' => 'ID de la instancia eliminada',
    'already_deleted' => true/false,  // Si la instancia ya no existía
    'error' => 'Mensaje de error (si success = false)'
]
```

**Comportamiento:**
- Si la instancia ya fue eliminada (404), retorna `success: true` con `already_deleted: true`
- Esto permite reenvíos después de eliminaciones manuales sin errores

### `deleteStudy(string $studyId): array`

Elimina un estudio completo de Orthanc (incluye todas sus instancias y series).

**Parámetros:**
- `$studyId`: ID del estudio a eliminar

**Retorna:**
```php
[
    'success' => true/false,
    'message' => 'Mensaje descriptivo',
    'study_id' => 'ID del estudio eliminado',
    'already_deleted' => true/false,
    'error' => 'Mensaje de error (si success = false)'
]
```

**Advertencia:** ⚠️ Eliminar un estudio elimina TODAS sus instancias y series. Usar con precaución.

### `checkDuplicate(array $searchCriteria): array`

Verifica si ya existe un estudio duplicado en Orthanc.

**Parámetros:**
```php
[
    'accession_number' => '98765', // Opcional
    'patient_id' => '12345', // Opcional
    'patient_name' => 'SILVA^JUAN', // Formato DICOM, opcional
    'patient_name_natural' => 'JUAN SILVA', // Formato natural, opcional
    'study_date' => '20240115' // Requerido si se usan nombres de paciente
]
```

**Retorna:**
```php
[
    'exists' => true/false,
    'study_id' => 'ID del estudio encontrado',
    'method' => 'accession_number|patient_id_and_date|patient_name_dicom_and_date|patient_name_natural_and_date'
]
```

### `generateDicomTags(array $informeData): array`

Método estático que genera tags DICOM estándar a partir de datos del informe.

**Parámetros:**
```php
[
    'patient_id' => '12345',
    'patient_name' => 'JUAN PEDRO SILVA',
    'study_date' => timestamp o 'YYYYMMDD',
    'modality' => 'OT',
    // ... ver documentación completa en código
]
```

### `testConnection(): array`

Verifica la conexión con Orthanc.

**Retorna:**
```php
[
    'connected' => true/false,
    'version' => 'Versión de Orthanc',
    'name' => 'Nombre del servidor',
    'error' => 'Mensaje de error (si connected = false)'
]
```

## 🏷️ Tags DICOM

### Tags Requeridas

Estas tags deben estar presentes en `$dicomTags`:

- **`PatientName`**: Nombre del paciente (formato: "NOMBRE APELLIDO")
- **`StudyDate`**: Fecha del estudio (formato: YYYYMMDD)
- **`Modality`**: Modalidad del estudio (ej: "OT", "CT", "MR")

### Tags Opcionales

- **`PatientID`**: ID del paciente
- **`PatientBirthDate`**: Fecha de nacimiento (YYYYMMDD)
- **`PatientSex`**: Sexo del paciente (M/F/O)
- **`AccessionNumber`**: Número de acceso
- **`StudyInstanceUID`**: UID único del estudio (se genera si no se proporciona)
- **`StudyDescription`**: Descripción del estudio
- **`StudyTime`**: Hora del estudio (HHMMSS)
- **`SeriesInstanceUID`**: UID de la serie (se genera si no se proporciona)
- **`SeriesDescription`**: Descripción de la serie
- **`InstitutionName`**: Nombre de la institución
- **`ReferringPhysicianName`**: Nombre del médico solicitante

### SOP Class UID

La clase automáticamente agrega:
- **`SOPClassUID`**: `1.2.840.10008.5.1.4.1.1.104.1` (Encapsulated PDF)

## ⚙️ Configuración Avanzada

### Timeout y Reintentos

```php
$config = [
    'timeout' => 120, // Timeout en segundos
    'max_retries' => 5, // Número de reintentos
    'backoff_base' => 2.0, // Base del backoff exponencial (espera = base^intento)
    'max_file_size_mb' => 100 // Tamaño máximo de archivo
];

$pacsSender = new OrthancPacsSender($config);
```

### Verificación de Duplicados

El sistema verifica duplicados en este orden (de más confiable a menos):

1. **AccessionNumber**: Busca por número de acceso
2. **PatientID + StudyDate**: Busca por ID de paciente y fecha
3. **PatientName (DICOM) + StudyDate**: Busca por nombre en formato DICOM y fecha
4. **PatientName (Natural) + StudyDate**: Busca por nombre natural y fecha

## 🔧 Requisitos para Generación de PDF

El endpoint `send-to-pacs.php` requiere una librería PHP para generar PDFs desde HTML.

### Opción 1: dompdf (Recomendado)

```bash
composer require dompdf/dompdf
```

### Opción 2: wkhtmltopdf

```bash
# Instalar wkhtmltopdf en el sistema
sudo apt-get install wkhtmltopdf  # Linux
# o descargar desde: https://wkhtmltopdf.org/downloads.html

# Instalar wrapper PHP
composer require knplabs/knp-snappy
```

### Configuración en el código

El endpoint detecta automáticamente qué librería está disponible. Si ninguna está instalada, devolverá un error indicando que se requiere instalar una librería de PDF.

## 🐛 Manejo de Errores

### Errores Comunes

1. **Error de conexión con Orthanc**
   - Verificar que Orthanc esté ejecutándose
   - Verificar URL y credenciales
   - Verificar conectividad de red

2. **Archivo PDF no encontrado**
   - Verificar ruta completa del archivo
   - Verificar permisos de lectura

3. **Archivo demasiado grande**
   - Ajustar `max_file_size_mb` en configuración
   - Comprimir PDF antes de enviar

4. **Tags DICOM faltantes**
   - Asegurarse de proporcionar todas las tags requeridas
   - Usar `generateDicomTags()` para generarlas automáticamente

### Logging

La clase registra errores usando `error_log()`. Los logs incluyen:
- Errores de conexión
- Intentos de reintento
- Errores de validación

Para habilitar logging detallado, configura el error_log en PHP:
```php
ini_set('log_errors', 1);
ini_set('error_log', '/ruta/a/logs/php_error.log');
```

## 🔄 Actualización de Informes Existentes

Cuando un informe que ya fue enviado a PACS es modificado y se vuelve a enviar, el sistema automáticamente:

1. **Detecta** que el informe tiene `pacs_instance_id` en la BD
2. **Elimina** la versión anterior de Orthanc
3. **Envía** la nueva versión actualizada
4. **Actualiza** las referencias en BD

### Ejemplo: Actualizar Informe Existente

```php
<?php
// Cargar informe desde BD
$informe = [
    'id' => 123,
    'pacs_instance_id' => 'old-instance-abc',  // Ya fue enviado antes
    'contenido_html' => '<h1>Informe Actualizado</h1>...'
];

// Verificar si ya fue enviado
if (!empty($informe['pacs_instance_id'])) {
    $pacsSender = new OrthancPacsSender();
    
    // Eliminar versión anterior
    $deleteResult = $pacsSender->deleteInstance($informe['pacs_instance_id']);
    
    if (!$deleteResult['success'] && !$deleteResult['already_deleted']) {
        error_log("Advertencia: No se pudo eliminar instancia anterior");
        // Continuar de todas formas
    }
}

// Generar PDF del contenido actualizado
$pdfContent = generatePdfFromHTML($informe['contenido_html']);

// Guardar temporalmente
$tempPath = sys_get_temp_dir() . '/informe_' . $informe['id'] . '.pdf';
file_put_contents($tempPath, $pdfContent);

// Enviar nueva versión
$dicomTags = OrthancPacsSender::generateDicomTags([...]);
$result = $pacsSender->sendPdfAsDicom($tempPath, $dicomTags);

// Actualizar referencia en BD
if ($result['success']) {
    $updateQuery = "UPDATE informes SET pacs_instance_id = ?, pacs_study_id = ? WHERE id = ?";
    // ... ejecutar update
}

unlink($tempPath);
?>
```

**Nota:** El endpoint `send-to-pacs.php` maneja esto automáticamente. No necesitas hacerlo manualmente.

## 📝 Ejemplos Completos

### Ejemplo 1: Enviar informe médico completo

```php
<?php
require_once 'api/OrthancPacsSender.php';

// 1. Cargar informe desde base de datos
$informe = [
    'patient_id' => '12345',
    'patient_name' => 'JUAN PEDRO SILVA',
    'study_date' => '2024-01-15',
    'modality' => 'CT',
    'titulo' => 'Informe de TC de Tórax',
    'contenido_html' => '<h1>Informe Médico</h1><p>Contenido...</p>'
];

// 2. Generar PDF desde HTML (usando dompdf)
use Dompdf\Dompdf;
$dompdf = new Dompdf();
$dompdf->loadHtml($informe['contenido_html']);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdfContent = $dompdf->output();

// 3. Guardar PDF temporalmente
$tempPath = sys_get_temp_dir() . '/informe_' . uniqid() . '.pdf';
file_put_contents($tempPath, $pdfContent);

// 4. Preparar datos para tags DICOM
$dicomData = [
    'patient_id' => $informe['patient_id'],
    'patient_name' => $informe['patient_name'],
    'study_date' => strtotime($informe['study_date']),
    'modality' => $informe['modality'],
    'study_description' => $informe['titulo']
];

// 5. Generar tags DICOM
$dicomTags = OrthancPacsSender::generateDicomTags($dicomData);

// 6. Verificar duplicados (opcional)
$pacsSender = new OrthancPacsSender();
$duplicate = $pacsSender->checkDuplicate([
    'accession_number' => $informe['patient_id'],
    'patient_id' => $informe['patient_id'],
    'study_date' => date('Ymd', strtotime($informe['study_date']))
]);

if ($duplicate['exists']) {
    echo "Ya existe un estudio con estos datos!\n";
    exit;
}

// 7. Enviar a Orthanc
$result = $pacsSender->sendPdfAsDicom($tempPath, $dicomTags);

// 8. Limpiar archivo temporal
unlink($tempPath);

// 9. Manejar resultado
if ($result['success']) {
    echo "✅ Informe enviado exitosamente\n";
    echo "Instance ID: " . $result['instance_id'] . "\n";
} else {
    echo "❌ Error: " . $result['error'] . "\n";
}
?>
```

### Ejemplo 2: Integración en un sistema existente

```php
<?php
// En tu controlador de informes
class InformeController {
    private $pacsSender;
    
    public function __construct() {
        $this->pacsSender = new OrthancPacsSender();
    }
    
    public function enviarAPacs($informeId) {
        // Cargar informe desde BD
        $informe = $this->loadInforme($informeId);
        
        // Generar PDF
        $pdfPath = $this->generatePdf($informe);
        
        // Preparar tags DICOM
        $dicomTags = OrthancPacsSender::generateDicomTags([
            'patient_id' => $informe['patient_id'],
            'patient_name' => $informe['patient_name'],
            'study_date' => strtotime($informe['fecha_estudio']),
            'modality' => $informe['modality'],
            'study_description' => $informe['titulo']
        ]);
        
        // Enviar a PACS
        $result = $this->pacsSender->sendPdfAsDicom($pdfPath, $dicomTags);
        
        // Guardar resultado en BD
        if ($result['success']) {
            $this->savePacsReference($informeId, $result);
        }
        
        return $result;
    }
}
?>
```

## 🔒 Seguridad

### Consideraciones

1. **Credenciales**: Nunca hardcodees credenciales en el código. Usa archivos de configuración o variables de entorno.

2. **Validación de entrada**: Siempre valida y sanitiza los datos antes de generar tags DICOM.

3. **Permisos de archivos**: Los archivos temporales deben tener permisos restringidos.

4. **HTTPS**: Si es posible, usa HTTPS para comunicación con Orthanc.

5. **Timeouts**: Configura timeouts apropiados para evitar que las peticiones se queden colgadas.

## 📚 Referencias

- [Orthanc REST API Documentation](https://book.orthanc-server.com/users/rest-api.html)
- [DICOM Standard - Encapsulated PDF](http://dicom.nema.org/medical/dicom/current/output/chtml/part03/sect_A.49.1.html)
- [SOP Class: Encapsulated PDF](https://www.dclunie.com/images/EncapsulatedPDFStorageSOPClass.pdf)

## 🤝 Integración en Otros Proyectos

Para usar esta librería en otros proyectos:

1. Copia `api/OrthancPacsSender.php` a tu proyecto
2. Ajusta las referencias a `OrthancConfig` o pasa configuración personalizada
3. Instala dependencias de generación de PDF si usas el endpoint
4. Personaliza los métodos según tus necesidades

La clase es completamente autocontenida y no tiene dependencias externas más allá de:
- PHP estándar (curl, json)
- Acceso a Orthanc

## 📄 Licencia

Esta librería es parte del sistema PORTAL_ESTUDIOS y se distribuye bajo la misma licencia del proyecto.

---

**Versión**: 1.0.0  
**Última actualización**: Enero 2025  
**Autor**: Sistema TJSMEDICAL

