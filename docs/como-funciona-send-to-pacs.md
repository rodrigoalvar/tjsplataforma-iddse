# Documentación: send-to-pacs.php

## Resumen
**Archivo**: `api/informes/send-to-pacs.php`

**Propósito**: Enviar informes médicos en formato PDF como objetos DICOM Encapsulated PDF a Orthanc PACS, vinculándolos al estudio original del paciente.

**Librería PDF**: TCPDF (reemplazó a DOMPDF por problemas de CSS)

---

## Flujo Completo del Proceso

### 1. **Configuración Inicial y Manejo de Errores** (Líneas 17-67)

```php
// Configurar error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar en pantalla
ini_set('log_errors', 1);     // Escribir en log

// Shutdown function para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        // Devolver JSON con el error
        echo json_encode([
            'success' => false,
            'error_code' => 'FATAL_ERROR',
            'message' => 'Error interno del servidor (fatal)',
            'error_details' => $error
        ]);
    }
});
```

**Características**:
- ✅ Buffer de salida (`ob_start()`) para controlar la respuesta
- ✅ Headers JSON configurados al inicio
- ✅ Captura de errores fatales
- ✅ Solo acepta método POST

---

### 2. **Carga de Dependencias** (Líneas 69-77)

```php
// ORDEN IMPORTANTE: Composer autoloader PRIMERO
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    // Carga automáticamente: TCPDF, database.php (via composer.json)
}

// Luego cargar clases adicionales
require_once '../../classes/User.php';
require_once '../OrthancPacsSender.php';
```

**Importante**: 
- ⚠️ `database.php` NO se carga manualmente (Composer lo hace automáticamente)
- ⚠️ Si se carga manualmente, causaría error de redeclaración de `getDBConnection()`

---

### 3. **Validación de Sesión** (Líneas 80-117)

```php
// Buscar token en múltiples lugares (prioridad):
1. Header Authorization
2. $_SERVER['HTTP_AUTHORIZATION']
3. $_POST['token'] o $_POST['session_token']
4. $_COOKIE['session_token']

// Validar con la clase User
$user = new User();
$userData = $user->validateSession($sessionToken);
```

**Respuestas posibles**:
- ❌ 401: Token requerido
- ❌ 401: Sesión inválida
- ✅ Continuar con el proceso

---

### 4. **Obtener y Validar Informe** (Líneas 119-166)

```php
// Leer input (JSON o POST)
$input = json_decode(file_get_contents('php://input'), true);
$informeId = $input['informe_id'] ?? null;

// Verificar permisos del usuario
$userPermissions = json_decode($userData['permisos'] ?? '[]', true);
$canManageAllReports = in_array('all', $userPermissions) || 
                       in_array('gestionInformes', $userPermissions);

// Query según permisos:
if ($canManageAllReports) {
    // Ver todos los informes
    SELECT FROM informes WHERE id = ?
} else {
    // Solo sus propios informes
    SELECT FROM informes WHERE id = ? AND usuario_id = ?
}
```

**Respuestas posibles**:
- ❌ 400: `informe_id` requerido
- ❌ 404: Informe no encontrado
- ✅ Continuar

---

### 5. **Obtener StudyInstanceUID del Estudio Original** (Líneas 168-209)

```php
// Prioridad 1: Desde base de datos
$originalStudyInstanceUID = $informe['study_instance_uid'] ?? null;

// Prioridad 2: Desde Orthanc (si existe estudio_id)
if (empty($originalStudyInstanceUID) && !empty($informe['estudio_id'])) {
    $orthancClient = new OrthancClient();
    $studyData = $orthancClient->getStudyDetails($informe['estudio_id']);
    $originalStudyInstanceUID = $studyData['study_instance_uid'];
    
    // Actualizar BD con el UID obtenido
    UPDATE informes SET study_instance_uid = ? WHERE id = ?
}
```

**Propósito**: 
- 🔗 **Vincular el PDF al estudio original** del paciente en PACS
- Si no se obtiene, el informe se crea como estudio independiente (ADVERTENCIA en log)

---

### 6. **Preparar Datos DICOM** (Líneas 211-242)

```php
$informeData = [
    'patient_id' => $informe['patient_id'],
    'patient_name' => $informe['patient_name'],
    'patient_birth_date' => $studyData['patient_birth_date'],
    'patient_sex' => $studyData['patient_sex'],
    'study_date' => strtotime($informe['fecha_modificacion']),
    'modality' => $informe['modality'] ?? 'OT',
    'accession_number' => $informe['accession_number'],
    'study_instance_uid' => $originalStudyInstanceUID, // CRÍTICO
    'study_description' => $informe['titulo'],
    'institution_name' => 'HOSPITAL DIGITAL',
    'referring_physician' => "$nombre $apellido"
];

// Generar tags DICOM
$dicomTags = OrthancPacsSender::generateDicomTags($informeData);
```

---

### 7. **Manejo de Actualizaciones (Eliminar Versión Anterior)** (Líneas 244-283)

```php
$existingSeriesId = $informe['pacs_series_id'] ?? null;
$existingInstanceId = $informe['pacs_instance_id'] ?? null;
$isUpdate = !empty($existingSeriesId) || !empty($existingInstanceId);

if ($isUpdate) {
    $pacsSender = new OrthancPacsSender();
    
    // Prioridad 1: Eliminar por SeriesID (flujo recomendado)
    if (!empty($existingSeriesId)) {
        $pacsSender->deleteSeries($existingSeriesId);
    }
    
    // Fallback: Eliminar por InstanceID (compatibilidad)
    if (!$deletedSuccessfully && !empty($existingInstanceId)) {
        $pacsSender->deleteInstance($existingInstanceId);
    }
}
```

**Flujo recomendado**:
1. ✅ Eliminar serie anterior usando `SeriesID`
2. ✅ Enviar nueva versión del PDF
3. ✅ Guardar nuevo `SeriesID` en BD

---

### 8. **Verificación de Duplicados** (Líneas 287-315)

```php
if ($checkDuplicates && !$isUpdate) {
    // Convertir nombre a formato DICOM: "APELLIDO^NOMBRE"
    $patientNameDicom = "$lastName^$firstName";
    
    $duplicateCheck = $pacsSender->checkDuplicate([
        'accession_number' => $informeData['accession_number'],
        'patient_id' => $informeData['patient_id'],
        'patient_name' => $patientNameDicom,
        'patient_name_natural' => $informeData['patient_name'],
        'study_date' => date('Ymd', $informeData['study_date'])
    ]);
    
    if ($duplicateCheck['exists']) {
        // ❌ 409 Conflict: Ya existe
        exit();
    }
}
```

**Nota**: Solo verifica si NO es actualización (para actualización ya eliminamos la anterior)

---

### 9. **Generación del PDF con TCPDF** (Líneas 317-334)

```php
function generatePdfFromHtml($htmlContent, $informeData) {
    // Verificar disponibilidad de TCPDF
    if (class_exists('TCPDF')) {
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Configurar metadatos
        $pdf->SetCreator('Portal de Estudios');
        $pdf->SetAuthor($informeData['referring_physician']);
        $pdf->SetTitle('Informe Médico');
        $pdf->SetSubject('Informe Médico - ' . $informeData['patient_name']);
        
        // Configurar márgenes y página
        $pdf->SetMargins(15, 15, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 11);
        
        // Limpiar HTML (solo tags seguros)
        $cleanHtml = strip_tags($htmlContent, '<p><br><h1><h2><h3><h4><h5><h6><strong><b><em><i><u><ul><ol><li><table><tr><td><th><tbody><thead><tfoot><div><span>');
        
        // Escribir HTML al PDF
        $pdf->writeHTML($cleanHtml, true, false, true, false, '');
        
        // Retornar PDF como string
        return $pdf->Output('', 'S');
    }
    
    // Si TCPDF no está disponible
    throw new Exception('TCPDF es requerido');
}
```

**Ventajas de TCPDF**:
- ✅ Sin errores de CSS (problema de DOMPDF)
- ✅ Estable y confiable
- ✅ UTF-8 nativo
- ✅ Buen soporte para HTML

---

### 10. **Guardar PDF Temporal** (Líneas 336-342)

```php
$tempDir = sys_get_temp_dir();
$tempPdfPath = $tempDir . '/informe_' . $informeId . '_' . uniqid() . '.pdf';

if (file_put_contents($tempPdfPath, $pdfContent) === false) {
    throw new Exception('No se pudo guardar el PDF temporal');
}
```

**Propósito**: 
- El archivo temporal se usa para enviar a Orthanc
- Se elimina después del envío (línea 350)

---

### 11. **Enviar PDF a Orthanc PACS** (Líneas 344-429)

```php
$pacsSender = new OrthancPacsSender();
$result = $pacsSender->sendPdfAsDicom($tempPdfPath, $dicomTags);

// Limpiar archivo temporal
@unlink($tempPdfPath);

if ($result['success']) {
    // Obtener SeriesID de la respuesta de Orthanc
    $seriesId = $result['series_id'] ?? 
                ($result['data']['ParentSeries'] ?? null);
    
    // Guardar referencias en BD
    try {
        UPDATE informes 
        SET fecha_enviado_pacs = NOW(), 
            pacs_instance_id = ?,
            pacs_study_id = ?,
            pacs_series_id = ?  // Flujo recomendado
        WHERE id = ?
    } catch (PDOException $e) {
        // Fallback si columna pacs_series_id no existe
        if (strpos($e->getMessage(), 'pacs_series_id') !== false) {
            UPDATE informes 
            SET fecha_enviado_pacs = NOW(), 
                pacs_instance_id = ?,
                pacs_study_id = ?
            WHERE id = ?
        }
    }
    
    // ✅ Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Informe enviado exitosamente a Orthanc PACS',
        'is_update' => $isUpdate,
        'data' => [
            'instance_id' => $result['instance_id'],
            'study_id' => $result['study_id'],
            'series_id' => $seriesId  // Incluir para futuras actualizaciones
        ]
    ]);
}
```

---

### 12. **Manejo de Errores** (Líneas 437-460)

```php
} catch (PDOException $e) {
    // Error de base de datos
    ob_end_clean();
    error_log("Error de base de datos: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_code' => 'DATABASE_ERROR'
    ]);
} catch (Exception $e) {
    // Cualquier otro error
    ob_end_clean();
    error_log("Error en send-to-pacs.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'SEND_TO_PACS_ERROR'
    ]);
}
```

---

## Flujo Resumido (Diagrama)

```
1. Request POST con informe_id
         ↓
2. Validar sesión y permisos
         ↓
3. Obtener informe de BD
         ↓
4. Obtener StudyInstanceUID (BD o Orthanc)
         ↓
5. Preparar datos DICOM
         ↓
6. ¿Es actualización? → SÍ → Eliminar serie/instancia anterior
         ↓
7. ¿Verificar duplicados? → SÍ → Verificar en Orthanc
         ↓
8. Generar PDF con TCPDF
         ↓
9. Guardar PDF temporal
         ↓
10. Enviar a Orthanc como DICOM Encapsulated PDF
         ↓
11. Obtener SeriesID de respuesta
         ↓
12. Guardar referencias en BD (instance_id, study_id, series_id)
         ↓
13. Limpiar archivo temporal
         ↓
14. ✅ Respuesta JSON exitosa
```

---

## Dependencias Clave

### Librerías PHP (Composer)
```json
{
  "require": {
    "tecnickcom/tcpdf": "^6.10"
  },
  "autoload": {
    "files": ["config/database.php"]
  }
}
```

### Clases Propias
- `User` - Validación de sesión
- `OrthancPacsSender` - Envío de PDF a PACS
- `OrthancClient` - Consultas a Orthanc REST API

### Funciones Globales
- `getDBConnection()` - Conexión a base de datos (cargada por Composer)

---

## Columnas de BD Requeridas

Tabla `informes`:
```sql
- id (INT)
- contenido_html (TEXT)
- patient_id (VARCHAR)
- patient_name (VARCHAR)
- estudio_id (VARCHAR) - ID del estudio en Orthanc
- study_instance_uid (VARCHAR) - UID DICOM del estudio
- pacs_instance_id (VARCHAR) - ID de la instancia DICOM en PACS
- pacs_study_id (VARCHAR) - ID del estudio en PACS
- pacs_series_id (VARCHAR) - SeriesID para actualizaciones [OPCIONAL pero RECOMENDADO]
- fecha_enviado_pacs (DATETIME)
```

---

## Logs Importantes

El script genera logs detallados:

```
=== INICIO GENERACIÓN PDF ===
Longitud HTML: 12345
generatePdfFromHtml: Iniciando generación de PDF
TCPDF disponible: SI
Usando TCPDF para generación de PDF
PDF generado exitosamente con TCPDF (7440 bytes)
PDF generado exitosamente, tamaño: 7440 bytes
=== FIN GENERACIÓN PDF ===
Referencias PACS actualizadas en BD para informe #123 - SeriesID: abc123
```

---

## Problemas Comunes y Soluciones

### 1. Error "Cannot redeclare getDBConnection()"
**Causa**: `database.php` se carga dos veces
**Solución**: 
- ✅ Cargar Composer autoloader PRIMERO
- ❌ NO usar `require_once 'config/database.php'` manualmente

### 2. Error "Trying to override a value inherited from a parent module"
**Causa**: DOMPDF tiene problemas con CSS
**Solución**: 
- ✅ Usar TCPDF (ya implementado)
- ❌ Desinstalar DOMPDF: `composer remove dompdf/dompdf`

### 3. PDF no se vincula al estudio original
**Causa**: `study_instance_uid` no se obtiene
**Solución**:
- Verificar que `estudio_id` existe en informe
- Verificar que Orthanc está accesible
- Ver logs: "ADVERTENCIA: No se pudo obtener StudyInstanceUID..."

### 4. Error al actualizar informe (serie anterior no se elimina)
**Causa**: `pacs_series_id` no existe en BD
**Solución**:
- Ejecutar migración: `database/add_pacs_series_id_to_informes.sql`
- El sistema tiene fallback a `pacs_instance_id`

---

## Mejoras Futuras

1. ✅ **COMPLETADO**: Usar TCPDF en lugar de DOMPDF
2. ✅ **COMPLETADO**: Flujo de actualización con SeriesID
3. 🔄 **PENDIENTE**: Configurar `institution_name` desde BD/config
4. 🔄 **PENDIENTE**: Mejorar manejo de errores con retry automático
5. 🔄 **PENDIENTE**: Implementar queue para envíos grandes

---

## Autor
**Sistema**: Portal de Estudios Médicos  
**Versión**: 1.0.0  
**Fecha**: Octubre 2025  
**Librería PDF**: TCPDF 6.10

