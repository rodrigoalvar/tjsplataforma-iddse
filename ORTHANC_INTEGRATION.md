# Integración de Orthanc con Portal de Estudios

## Descripción General

Este módulo integra el servidor DICOM Orthanc con el Portal de Estudios, permitiendo la visualización y gestión de estudios médicos desde una interfaz web unificada.

## Estructura del Módulo

### Archivos PHP (Backend)

#### 1. `api/config/orthanc_config.php`
**Propósito**: Configuración centralizada del servidor Orthanc

**Configuración**:
```php
// Configurar estos valores según su instalación de Orthanc
private $host = 'localhost';        // IP del servidor Orthanc
private $port = 8042;               // Puerto del servidor Orthanc
private $protocol = 'http';         // Protocolo (http/https)
private $username = 'orthanc';      // Usuario de Orthanc
private $password = 'orthanc';      // Contraseña de Orthanc
private $viewerBaseUrl = 'https://tuc-i310100.tail186bcc.ts.net/?studyId='; // URL del visor
```

#### 2. `api/OrthancClient.php`
**Propósito**: Cliente PHP para interactuar con la API REST de Orthanc

**Métodos principales**:
- `findStudiesByPatientId($patientId)`: Busca estudios por ID de paciente
- `getStudyDetails($studyId)`: Obtiene detalles de un estudio específico
- `getAllPatients()`: Obtiene todos los pacientes
- `getPatientStudies($patientId)`: Obtiene estudios de un paciente específico
- `getServerStatus()`: Verifica el estado del servidor Orthanc

#### 3. `api/get_patient_studies.php`
**Propósito**: Endpoint para obtener estudios de un paciente específico

**Uso**: `GET /api/get_patient_studies.php?patient_id=12345`

**Respuesta**:
```json
{
  "success": true,
  "data": [
    {
      "orthanc_id": "uuid-del-estudio",
      "study_date": "20231201",
      "study_time": "143000",
      "modality": "CT",
      "study_description": "Tomografía de Tórax",
      "patient_name": "Apellido^Nombre",
      "patient_id": "12345",
      "viewer_url": "https://viewer.com/?studyId=uuid-del-estudio"
    }
  ],
  "patient_id": "12345",
  "total_studies": 1
}
```

#### 4. `api/get_all_studies.php`
**Propósito**: Endpoint para obtener todos los pacientes y estudios (dashboard profesional)

**Uso**: `GET /api/get_all_studies.php`

### Archivos JavaScript (Frontend)

#### 1. `assets/js/orthanc-integration.js`
**Propósito**: Integración de Orthanc con el portal de pacientes

**Clase**: `OrthancIntegration`
**Métodos principales**:
- `getPatientStudies(patientId)`: Obtiene estudios del paciente
- `updateStudiesModal(patientId)`: Actualiza el modal con estudios
- `formatDate(dateStr)`: Formatea fechas DICOM
- `generateStudiesHTML(studies)`: Genera HTML para mostrar estudios

#### 2. `assets/js/dashboard-orthanc.js`
**Propósito**: Integración de Orthanc con el dashboard profesional

**Clase**: `DashboardOrthanc`
**Métodos principales**:
- `loadAllStudies()`: Carga todos los estudios
- `filterStudies()`: Aplica filtros de búsqueda
- `renderStudies(studies)`: Renderiza estudios en la tabla
- `updateStatistics(studies)`: Actualiza estadísticas del dashboard

## Endpoints de Orthanc Utilizados

### 1. `/tools/find` (POST)
**Propósito**: Buscar estudios por criterios específicos

**Ejemplo de uso**:
```json
{
  "Level": "Study",
  "Query": {
    "PatientID": "12345"
  }
}
```

### 2. `/patients` (GET)
**Propósito**: Obtener lista de todos los pacientes

### 3. `/patients/{id}/studies` (GET)
**Propósito**: Obtener estudios de un paciente específico

### 4. `/studies/{id}` (GET)
**Propósito**: Obtener detalles de un estudio específico

### 5. `/system` (GET)
**Propósito**: Verificar estado del servidor Orthanc

## Configuración del Servidor Orthanc

### 1. Habilitar API REST
En el archivo `orthanc.json`:
```json
{
  "RemoteAccessAllowed": true,
  "AuthenticationEnabled": true,
  "RegisteredUsers": {
    "orthanc": "orthanc"
  }
}
```

### 2. Configurar CORS (si es necesario)
```json
{
  "HttpsCACertificates": "/etc/ssl/certs/ca-certificates.crt",
  "HttpHeaders": {
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Methods": "GET, POST, PUT, DELETE, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Authorization"
  }
}
```

### 3. Configurar Puerto y Protocolo
```json
{
  "HttpPort": 8042,
  "HttpsPort": 8043,
  "SslEnabled": false
}
```

## Instalación y Configuración

### Paso 1: Configurar Orthanc
1. Editar `api/config/orthanc_config.php` con los datos de su servidor Orthanc
2. Configurar la URL del visor DICOM
3. Verificar credenciales de acceso

### Paso 2: Verificar Permisos
1. Asegurar que el servidor web tenga acceso a los archivos PHP
2. Verificar que Orthanc esté accesible desde el servidor web

### Paso 3: Probar Conexión
1. Acceder a `api/get_all_studies.php` para verificar la conexión
2. Revisar logs de error en caso de problemas

## Integración con las Páginas

### Portal de Pacientes (`paciente.html`)
- Los pacientes ingresan su número de documento
- El sistema busca estudios usando el documento como PatientID
- Los estudios se muestran en un modal con opciones de visualización

### Dashboard Profesional (`dashboard-unified.html`)
- Muestra todos los pacientes y estudios
- Permite filtrar por paciente, fecha, ID y modalidad
- Proporciona estadísticas en tiempo real
- Integra botones para ver y descargar estudios

## Seguridad

### Medidas Implementadas
1. **Configuración centralizada**: Credenciales en archivo PHP (no expuestas al cliente)
2. **Validación de entrada**: Sanitización de parámetros de entrada
3. **Manejo de errores**: Mensajes de error controlados sin exponer información sensible
4. **Headers de seguridad**: CORS configurado apropiadamente

### Recomendaciones Adicionales
1. Usar HTTPS en producción
2. Configurar autenticación robusta en Orthanc
3. Implementar rate limiting en los endpoints
4. Auditar accesos a estudios médicos

## Solución de Problemas

### Error de Conexión
1. Verificar que Orthanc esté ejecutándose
2. Comprobar IP, puerto y credenciales en `orthanc_config.php`
3. Revisar configuración de firewall

### Estudios No Aparecen
1. Verificar que los estudios tengan PatientID configurado
2. Comprobar formato de fecha en Orthanc
3. Revisar logs de Orthanc para errores

### Problemas de CORS
1. Configurar headers CORS en Orthanc
2. Verificar configuración del servidor web

## Mantenimiento

### Logs
- Revisar logs de PHP en `/var/log/apache2/error.log` (Linux) o equivalente
- Monitorear logs de Orthanc

### Actualizaciones
- Mantener Orthanc actualizado
- Revisar compatibilidad de API con nuevas versiones

### Backup
- Respaldar configuración de Orthanc
- Mantener copia de seguridad de la base de datos DICOM

## Contacto y Soporte

Para soporte técnico o consultas sobre la integración, consultar:
- Documentación oficial de Orthanc: https://book.orthanc-server.com/
- API Reference: https://api.orthanc-server.com/