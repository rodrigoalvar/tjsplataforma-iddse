# API de Worklist - Documentación

## Descripción

Sistema para gestionar listas de trabajo DICOM (Worklist) que permite importar, consultar, actualizar y sincronizar estudios programados con sistemas PACS como Orthanc. Los datos pueden ser importados desde archivos TXT con formato DICOM o mediante JSON.

## Autenticación

Todas las peticiones requieren autenticación mediante token Bearer en el header:

```
Authorization: Bearer {session_token}
```

El token puede obtenerse mediante:
- Cookie `session_token`
- Header `Authorization: Bearer {token}`
- LocalStorage/SessionStorage `session_token`

## Endpoints

### 1. Importar Archivo TXT

**Endpoint:** `POST /api/worklist.php`

**Descripción:** Importa un archivo TXT con datos DICOM de worklist. El sistema parsea el archivo, valida los datos, los almacena en la base de datos y genera automáticamente el archivo `.wl` para Orthanc.

**Content-Type:** `multipart/form-data`

**Parámetros:**
- `txt` (file, requerido): Archivo TXT con datos DICOM en formato worklist

**Formato del archivo TXT:**

```
[Message]
Type=Add
[DicomData]
;Numero de acceso
(0008.0050)=581309
;Modalidad
(0008.0060)=MR
;Medico referente
(0008.0090)=4518
;Nombre del paciente
(0010.0010)=CAZAUX^MERCEDES GRACIELA
;Identificacion del paciente
(0010.0020)=10010647
;Nombre del equipo
(0040.0001)=RAYOS
;Fecha programada del procedimiento
(0040.0002)=20250731
;Hora programada del procedimiento
(0040.0003)=104100
;Descripcion del procedimiento
(0040.0007)=RADIOGRAFIA O TELERADIOGRAFIA DE TORAX
;Fecha de nacimiento del paciente
(0010.0030)=19520303
;Sexo del paciente
(0010.0040)=F
;Descripcion del procedimiento requerido
(0032.1060)=RADIOGRAFIA O TELERADIOGRAFIA DE TORAX
```

**Tags DICOM soportados:**
- `(0008.0050)` - Accession Number (requerido)
- `(0008.0060)` - Modality
- `(0008.0090)` - Referring Physician
- `(0010.0010)` - Patient Name (formato: APELLIDO^NOMBRE)
- `(0010.0020)` - Patient ID
- `(0010.0030)` - Patient Birth Date (formato: YYYYMMDD)
- `(0010.0040)` - Patient Sex (M/F/O)
- `(0040.0001)` - Equipment Name / Scheduled Station AE Title
- `(0040.0002)` - Scheduled Procedure Step Start Date (requerido, formato: YYYYMMDD)
- `(0040.0003)` - Scheduled Procedure Step Start Time (requerido, formato: HHMMSS)
- `(0040.0007)` - Scheduled Procedure Step Description
- `(0032.1060)` - Requested Procedure Description / Reason for Study

**Campos requeridos:**
- `accession_number` (0008.0050)
- `scheduled_date` (0040.0002)
- `scheduled_time` (0040.0003)

**Ejemplo con cURL:**

```bash
curl -X POST http://tu-servidor/api/worklist.php \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -F "txt=@/ruta/al/worklist.txt"
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Archivo importado exitosamente",
  "data": {
    "id": 1,
    "accession_number": "581309",
    "patient_name": "CAZAUX MERCEDES GRACIELA",
    "patient_id": "10010647",
    "patient_birth_date": "1952-03-03",
    "patient_sex": "F",
    "modality": "MR",
    "referring_physician": "4518",
    "equipment_name": "RAYOS",
    "scheduled_date": "2025-07-31",
    "scheduled_time": "10:41:00",
    "procedure_description": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
    "reason_for_study": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
    "status": "pending",
    "source_file": "worklist.txt",
    "wl_file": "/var/lib/orthanc/db/WorklistsDatabase/581309.wl",
    "is_new": true,
    "created_at": "2025-01-15 10:30:00",
    "updated_at": "2025-01-15 10:30:00"
  }
}
```

**Respuesta de error:**

```json
{
  "success": false,
  "message": "El campo scheduled_date es requerido"
}
```

### 2. Importar Datos JSON

**Endpoint:** `POST /api/worklist.php`

**Descripción:** Importa datos de worklist en formato JSON. Útil para integraciones programáticas.

**Content-Type:** `application/json`

**Body (JSON):**

```json
{
  "accession_number": "581309",
  "patient_name": "CAZAUX MERCEDES GRACIELA",
  "patient_id": "10010647",
  "patient_birth_date": "1952-03-03",
  "patient_sex": "F",
  "modality": "MR",
  "referring_physician": "4518",
  "equipment_name": "RAYOS",
  "scheduled_date": "2025-07-31",
  "scheduled_time": "10:41:00",
  "procedure_description": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
  "reason_for_study": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
  "status": "pending"
}
```

**Campos requeridos:**
- `accession_number`
- `scheduled_date` (formato: YYYY-MM-DD)
- `scheduled_time` (formato: HH:MM:SS)

**Ejemplo con cURL:**

```bash
curl -X POST http://tu-servidor/api/worklist.php \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d '{
    "accession_number": "581309",
    "patient_name": "CAZAUX MERCEDES GRACIELA",
    "scheduled_date": "2025-07-31",
    "scheduled_time": "10:41:00",
    "modality": "MR"
  }'
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Datos importados exitosamente",
  "data": {
    "id": 1,
    "accession_number": "581309",
    "patient_name": "CAZAUX MERCEDES GRACIELA",
    "scheduled_date": "2025-07-31",
    "scheduled_time": "10:41:00",
    "modality": "MR",
    "status": "pending",
    "wl_file": "/var/lib/orthanc/db/WorklistsDatabase/581309.wl",
    "is_new": true
  }
}
```

### 3. Listar Worklist

**Endpoint:** `GET /api/worklist.php`

**Descripción:** Obtiene la lista de worklist con filtros opcionales.

**Parámetros de consulta:**
- `date` (string, opcional): Filtrar por fecha programada (formato: YYYY-MM-DD)
- `modality` (string, opcional): Filtrar por modalidad (MR, CT, US, CR, DX, MG, etc.)
- `status` (string, opcional): Filtrar por estado (pending, scheduled, in_progress, completed, cancelled)

**Ejemplo:**

```bash
curl "http://tu-servidor/api/worklist.php?date=2025-07-31&modality=MR&status=pending" \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Respuesta:**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "accession_number": "581309",
      "patient_name": "CAZAUX MERCEDES GRACIELA",
      "patient_id": "10010647",
      "patient_birth_date": "1952-03-03",
      "patient_sex": "F",
      "modality": "MR",
      "referring_physician": "4518",
      "equipment_name": "RAYOS",
      "scheduled_date": "2025-07-31",
      "scheduled_time": "10:41:00",
      "procedure_description": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
      "reason_for_study": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
      "status": "pending",
      "source_file": "worklist.txt",
      "created_at": "2025-01-15 10:30:00",
      "updated_at": "2025-01-15 10:30:00"
    }
  ],
  "count": 1
}
```

### 4. Obtener Worklist por Accession Number

**Endpoint:** `GET /api/worklist.php?accession={accession_number}`

**Descripción:** Obtiene un item específico de worklist por su número de acceso.

**Parámetros:**
- `accession` (string, requerido): Número de acceso DICOM

**Ejemplo:**

```bash
curl "http://tu-servidor/api/worklist.php?accession=581309" \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "accession_number": "581309",
    "patient_name": "CAZAUX MERCEDES GRACIELA",
    "patient_id": "10010647",
    "patient_birth_date": "1952-03-03",
    "patient_sex": "F",
    "modality": "MR",
    "referring_physician": "4518",
    "equipment_name": "RAYOS",
    "scheduled_date": "2025-07-31",
    "scheduled_time": "10:41:00",
    "procedure_description": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
    "reason_for_study": "RADIOGRAFIA O TELERADIOGRAFIA DE TORAX",
    "status": "pending",
    "source_file": "worklist.txt",
    "created_at": "2025-01-15 10:30:00",
    "updated_at": "2025-01-15 10:30:00"
  }
}
```

**Respuesta de error (no encontrado):**

```json
{
  "success": false,
  "message": "Worklist no encontrado"
}
```

### 5. Actualizar Worklist

**Endpoint:** `PUT /api/worklist.php?accession={accession_number}`

**Descripción:** Actualiza un item de worklist existente. Solo se actualizan los campos enviados.

**Content-Type:** `application/json`

**Body (JSON):**

```json
{
  "patient_name": "NUEVO NOMBRE",
  "status": "in_progress",
  "procedure_description": "Nueva descripción"
}
```

**Campos actualizables:**
- `patient_name`
- `patient_id`
- `patient_birth_date`
- `patient_sex`
- `modality`
- `referring_physician`
- `equipment_name`
- `scheduled_date`
- `scheduled_time`
- `procedure_description`
- `reason_for_study`
- `status`

**Ejemplo:**

```bash
curl -X PUT "http://tu-servidor/api/worklist.php?accession=581309" \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_progress",
    "procedure_description": "Procedimiento actualizado"
  }'
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Worklist actualizado exitosamente",
  "data": {
    "id": 1,
    "accession_number": "581309",
    "status": "in_progress",
    "procedure_description": "Procedimiento actualizado",
    "updated_at": "2025-01-15 11:00:00"
  }
}
```

**Nota:** Al actualizar un worklist, el sistema regenera automáticamente el archivo `.wl` para Orthanc.

### 6. Eliminar Worklist

**Endpoint:** `DELETE /api/worklist.php?accession={accession_number}`

**Descripción:** Elimina un item de worklist de la base de datos.

**Parámetros:**
- `accession` (string, requerido): Número de acceso DICOM

**Ejemplo:**

```bash
curl -X DELETE "http://tu-servidor/api/worklist.php?accession=581309" \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Worklist eliminado exitosamente"
}
```

**Respuesta de error (no encontrado):**

```json
{
  "success": false,
  "message": "Worklist no encontrado"
}
```

### 7. Sincronizar con Orthanc

**Endpoint:** `POST /api/worklist.php`

**Descripción:** Sincroniza todos los worklist pendientes, programados o en progreso con Orthanc, generando los archivos `.wl` correspondientes.

**Content-Type:** `application/json`

**Body (JSON):**

```json
{
  "action": "sync"
}
```

**Ejemplo:**

```bash
curl -X POST http://tu-servidor/api/worklist.php \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d '{"action": "sync"}'
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Sincronización completada: 15 archivos procesados",
  "synced": 15,
  "errors": []
}
```

**Respuesta con errores:**

```json
{
  "success": true,
  "message": "Sincronización completada: 12 archivos procesados",
  "synced": 12,
  "errors": [
    {
      "accession": "581310",
      "error": "No se pudo copiar el archivo a: /var/lib/orthanc/db/WorklistsDatabase/581310.wl"
    }
  ]
}
```

## Estados del Worklist

- **pending**: Pendiente de programación
- **scheduled**: Programado
- **in_progress**: En progreso
- **completed**: Completado
- **cancelled**: Cancelado

## Generación Automática de Archivos .wl

Cuando se importa o actualiza un worklist, el sistema:

1. Genera automáticamente un archivo `.wl` en formato DICOM Worklist
2. Copia el archivo a la carpeta configurada de Orthanc (configurable en `configuracion.html`)
3. El archivo se nombra con el formato: `{accession_number}.wl`

La ruta de Orthanc se configura en:
- **Interfaz:** `configuracion.html` → Pestaña "Worklist"
- **Campo:** "Ruta de Worklist de Orthanc"
- **Ejemplo:** `/var/lib/orthanc/db/WorklistsDatabase`

## Base de Datos

El sistema utiliza las siguientes tablas:

### Tabla `worklist`

Almacena los items de worklist con los siguientes campos principales:
- `id`: ID único
- `accession_number`: Número de acceso DICOM (único, requerido)
- `patient_name`: Nombre del paciente
- `patient_id`: ID del paciente
- `patient_birth_date`: Fecha de nacimiento
- `patient_sex`: Sexo (M/F/O)
- `modality`: Modalidad (MR, CT, US, CR, DX, MG, etc.)
- `referring_physician`: Médico referente
- `equipment_name`: Nombre del equipo
- `scheduled_date`: Fecha programada (requerido)
- `scheduled_time`: Hora programada (requerido)
- `procedure_description`: Descripción del procedimiento
- `reason_for_study`: Razón del estudio
- `status`: Estado (pending, scheduled, in_progress, completed, cancelled)
- `source_file`: Archivo fuente de importación
- `created_at`: Fecha de creación
- `updated_at`: Fecha de actualización

### Tabla `worklist_logs`

Registra todas las acciones realizadas sobre los worklist:
- `id`: ID único
- `worklist_id`: ID del worklist
- `action`: Acción (CREATE, UPDATE, DELETE, IMPORT)
- `user_id`: ID del usuario que realizó la acción
- `source`: Fuente de la acción (nombre de archivo, JSON, etc.)
- `changes`: Cambios realizados (JSON)
- `created_at`: Fecha de la acción

## Interfaz de Usuario

La interfaz para gestionar worklist está disponible en:
- **URL:** `worklist.html`
- **Funcionalidades:**
  - Listar todos los worklist con filtros (fecha, modalidad, estado)
  - Crear nuevo worklist manualmente
  - Importar archivo TXT mediante modal de upload
  - Editar worklist existente
  - Eliminar worklist
  - Sincronizar con Orthanc
  - Ver detalles completos de cada worklist

## Formato de Fechas y Horas

### En archivos TXT:
- **Fecha:** `YYYYMMDD` (ej: `20250731`)
- **Hora:** `HHMMSS` (ej: `104100`)

### En JSON/API:
- **Fecha:** `YYYY-MM-DD` (ej: `2025-07-31`)
- **Hora:** `HH:MM:SS` (ej: `10:41:00`)

### Conversión automática:
El sistema convierte automáticamente los formatos DICOM a formatos estándar de base de datos.

## Manejo de Errores

### Códigos de estado HTTP:
- `200`: Operación exitosa
- `400`: Error de validación o datos inválidos
- `401`: No autenticado o token inválido
- `404`: Recurso no encontrado
- `405`: Método HTTP no permitido
- `500`: Error interno del servidor

### Formato de respuesta de error:

```json
{
  "success": false,
  "message": "Descripción del error"
}
```

## Notas Importantes

1. El campo `accession_number` es **único** y **obligatorio** en todos los casos
2. Los campos `scheduled_date` y `scheduled_time` son **obligatorios** para importar
3. Si se importa un worklist con un `accession_number` existente, se **actualiza** el registro existente
4. La generación del archivo `.wl` es automática pero no bloquea la importación si falla
5. Los archivos `.wl` se generan en formato DICOM Worklist estándar
6. La sincronización con Orthanc requiere que la ruta esté configurada correctamente
7. Todos los cambios se registran en `worklist_logs` para auditoría

## Ejemplo Completo de Integración

```bash
# 1. Importar archivo TXT
curl -X POST http://tu-servidor/api/worklist.php \
  -H "Authorization: Bearer TU_TOKEN" \
  -F "txt=@worklist.txt"

# 2. Listar worklist del día
curl "http://tu-servidor/api/worklist.php?date=2025-07-31" \
  -H "Authorization: Bearer TU_TOKEN"

# 3. Obtener un worklist específico
curl "http://tu-servidor/api/worklist.php?accession=581309" \
  -H "Authorization: Bearer TU_TOKEN"

# 4. Actualizar estado
curl -X PUT "http://tu-servidor/api/worklist.php?accession=581309" \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status": "in_progress"}'

# 5. Sincronizar con Orthanc
curl -X POST http://tu-servidor/api/worklist.php \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action": "sync"}'
```
