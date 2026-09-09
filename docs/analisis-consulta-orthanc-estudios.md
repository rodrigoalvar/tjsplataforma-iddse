# Análisis: Consultas a Orthanc sobre Estudios DICOM

## Objetivo
Determinar qué datos se consultan actualmente a Orthanc sobre los estudios DICOM para implementar un sistema de sincronización mediante Lua scripting en el servidor Orthanc. Los datos se guardarán en una tabla de base de datos para liberar a Orthanc de consultas pesadas.

---

## 1. Métodos Principales que Consultan Orthanc

### 1.1 `getAllStudiesEfficient()` - OrthancClient.php
**Ubicación**: `api/OrthancClient.php` (línea 215)

**Descripción**: Método principal para obtener todos los estudios con filtros opcionales.

**Parámetros de filtrado**:
- `$dateFrom`: Fecha inicial (formato YYYY-MM-DD)
- `$dateTo`: Fecha final (formato YYYY-MM-DD)
- `$patientId`: ID del paciente (búsqueda con wildcard)
- `$modality`: Modalidad del estudio (CT, MR, DX, US, etc.)

**Endpoints Orthanc utilizados**:
- `POST /tools/find` - Búsqueda de estudios con filtros
- `GET /studies/{id}` - Detalles de cada estudio encontrado
- `GET /studies/{id}/series` - Series del estudio (para obtener modalidades)

**Usado en**:
- `api/get_all_studies.php` - Endpoint principal para dashboard y estudios-manager
- `api/pacs-manager/list.php` - Listado de estudios en PACS Manager

---

### 1.2 `getStudyDetails()` - OrthancClient.php
**Ubicación**: `api/OrthancClient.php` (línea 127)

**Descripción**: Obtiene información detallada de un estudio específico.

**Endpoints Orthanc utilizados**:
- `GET /studies/{id}` - Información del estudio
- `GET /patients/{id}` - Información del paciente padre
- `GET /studies/{id}/series` - Series para obtener modalidades

**Usado en**:
- `api/informes/list.php` - Para obtener institución del estudio
- `api/informes/send-to-pacs.php` - Para obtener StudyInstanceUID
- `api/update_existing_assignments_institution.php` - Para actualizar asignaciones

---

### 1.3 `findStudiesByPatientId()` - OrthancClient.php
**Ubicación**: `api/OrthancClient.php` (línea 64)

**Descripción**: Busca estudios por ID de paciente.

**Endpoints Orthanc utilizados**:
- `POST /tools/find` - Búsqueda con filtro PatientID
- `GET /studies/{id}` - Detalles de cada estudio (si expand=false)

**Usado en**:
- `api/get_patient_studies.php` - Estudios de un paciente específico
- `api/pacientes/search-by-idpacs.php` - Búsqueda de paciente

---

## 2. Campos DICOM Consultados de los Estudios

### 2.1 Campos del Paciente (PatientMainDicomTags)
Estos campos se obtienen del recurso Patient padre del estudio:

| Campo DICOM | Tag | Nombre en BD/API | Descripción |
|------------|-----|------------------|-------------|
| PatientID | (0010,0020) | `patient_id` | Identificador único del paciente |
| PatientName | (0010,0010) | `patient_name` | Nombre completo del paciente |
| PatientBirthDate | (0010,0030) | `patient_birth_date` | Fecha de nacimiento (YYYYMMDD) |
| PatientSex | (0010,0040) | `patient_sex` | Sexo del paciente (M/F/O) |

### 2.2 Campos del Estudio (MainDicomTags)
Estos campos se obtienen directamente del recurso Study:

| Campo DICOM | Tag | Nombre en BD/API | Descripción |
|------------|-----|------------------|-------------|
| StudyDate | (0008,0020) | `study_date` | Fecha del estudio (YYYYMMDD) |
| StudyTime | (0008,0030) | `study_time` | Hora del estudio (HHMMSS) |
| StudyDescription | (0008,1030) | `study_description` | Descripción del estudio |
| StudyInstanceUID | (0020,000D) | `study_instance_uid` | UID único del estudio |
| AccessionNumber | (0008,0050) | `accession_number` | Número de acceso del estudio |
| ReferringPhysicianName | (0008,0090) | `referring_physician` | Nombre del médico referente |
| InstitutionName | (0008,0080) | `institution_name` | Nombre de la institución |

### 2.3 Campos Derivados/Calculados

| Campo | Origen | Descripción |
|-------|--------|-------------|
| `modality` | Calculado desde Series | Lista de modalidades del estudio (ej: "CT, MR") |
| `series_count` | `count($study['Series'])` | Número de series en el estudio |
| `orthanc_id` / `study_id` | ID del recurso Orthanc | Identificador interno de Orthanc |
| `viewer_url` | Generado | URL del visor DICOM configurado |

---

## 3. Estructura de Datos Retornada

### 3.1 Estructura de `getAllStudiesEfficient()`

```php
[
    [
        'study_id' => 'orthanc-study-id',
        'orthanc_id' => 'orthanc-study-id',
        'patient_id' => '12345678',
        'patient_name' => 'PEREZ^JUAN',
        'patient_birth_date' => '19800101',
        'patient_sex' => 'M',
        'study_date' => '20241215',
        'study_time' => '143000',
        'study_description' => 'CT TORAX',
        'study_instance_uid' => '1.2.840.113619.2.55.3.1234567890',
        'accession_number' => 'ACC123456',
        'referring_physician' => 'DR. GARCIA',
        'institution_name' => 'HOSPITAL CENTRAL',
        'modality' => 'CT',
        'viewer_url' => 'http://orthanc:8042/app/stone-webviewer/index.html?...'
    ],
    // ... más estudios
]
```

### 3.2 Estructura de `getStudyDetails()`

Similar a `getAllStudiesEfficient()` pero incluye:
- `series_count`: Número de series

---

## 4. Uso de los Datos en las Interfaces

### 4.1 Dashboard Unified (`dashboard-unified.html` / `dashboard-with-permissions.js`)

**API utilizada**: `api/get_all_studies.php`

**Datos utilizados**:
- `patient_name` - Mostrar nombre del paciente
- `study_date` - Fecha del estudio
- `modality` - Badge de modalidad
- `study_id` - Para acciones (ver, descargar)
- `viewer_url` - Para abrir visor DICOM
- `study_description` - Descripción del estudio

**Filtros aplicados**:
- Rango de fechas
- ID de paciente
- Modalidad

---

### 4.2 Estudios Manager (`estudios-manager.html` / `estudios-manager.js`)

**API utilizada**: `api/get_all_studies.php`

**Datos utilizados**:
- `patient_name` - Nombre del paciente en la tabla
- `patient_id` - ID del paciente
- `study_date` - Fecha del estudio
- `study_time` - Hora del estudio
- `study_description` - Descripción
- `modality` - Modalidad (filtro y visualización)
- `study_id` - Para asignaciones, reasignaciones, eliminación
- `study_instance_uid` - Para operaciones DICOM
- `accession_number` - Número de acceso
- `institution_name` - Institución (para filtros de permisos)

**Funcionalidades que dependen de estos datos**:
- Listado de estudios con paginación
- Filtros por fecha, paciente, modalidad
- Asignación/reasignación de estudios
- Visualización de información del estudio
- Descarga de estudios
- Eliminación de estudios

---

### 4.3 PACS Manager (`pacs-manager.html` / `pacs-manager.js`)

**API utilizada**: `api/pacs-manager/list.php` (usa `getAllStudiesEfficient()`)

**Datos utilizados**:
- Todos los campos básicos del estudio
- `study_id` - Para eliminación de estudios
- `patient_name` - Para edición de nombre de paciente

---

### 4.4 Informes Manager (`informes-manager.js`)

**API utilizada**: 
- `api/get_all_studies.php` - Para cargar estudios desde Orthanc
- `api/informes/list.php` - Para listar informes (usa `getStudyDetails()`)

**Datos utilizados**:
- `study_id` - ID del estudio
- `patient_name` - Nombre del paciente
- `modality` - Modalidad
- `study_description` - Descripción
- `study_instance_uid` - UID del estudio
- `institution_name` - Para filtros de permisos por institución
- `patient_id` - ID del paciente

**Funcionalidades**:
- Cargar estudios desde Orthanc para crear informes
- Filtrar por institución
- Mostrar información del estudio en modales

---

## 5. Consultas Pesadas Identificadas

### 5.1 Consultas que Impactan el Rendimiento

1. **`getAllStudiesEfficient()` con muchos estudios**
   - Hace `POST /tools/find` para obtener IDs
   - Luego hace `GET /studies/{id}` para CADA estudio encontrado
   - Además hace `GET /studies/{id}/series` para obtener modalidades
   - **Impacto**: Si hay 1000 estudios, son 2000+ requests a Orthanc

2. **`getStudyDetails()` en loops**
   - Usado en `informes/list.php` para obtener institución de cada estudio
   - Si hay 100 informes, son 100+ requests adicionales

3. **Búsquedas repetidas por PatientID**
   - `findStudiesByPatientId()` se llama múltiples veces
   - Cada llamada hace requests a Orthanc

### 5.2 Patrones de Uso

- **Dashboard**: Carga todos los estudios del día/rango de fechas
- **Estudios Manager**: Carga estudios con filtros, se refresca frecuentemente
- **Informes Manager**: Carga estudios para seleccionar al crear informe
- **Búsqueda de pacientes**: Consulta estudios por PatientID

---

## 6. Propuesta de Tabla de Base de Datos

### 6.1 Estructura Propuesta: `orthanc_studies`

```sql
CREATE TABLE orthanc_studies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- IDs
    orthanc_study_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'ID interno de Orthanc',
    study_instance_uid VARCHAR(255) NOT NULL UNIQUE COMMENT 'StudyInstanceUID DICOM',
    accession_number VARCHAR(255) DEFAULT NULL COMMENT 'AccessionNumber',
    
    -- Datos del Paciente
    patient_id VARCHAR(255) NOT NULL COMMENT 'PatientID',
    patient_name VARCHAR(255) DEFAULT NULL COMMENT 'PatientName',
    patient_birth_date DATE DEFAULT NULL COMMENT 'PatientBirthDate',
    patient_sex CHAR(1) DEFAULT NULL COMMENT 'PatientSex (M/F/O)',
    
    -- Datos del Estudio
    study_date DATE DEFAULT NULL COMMENT 'StudyDate',
    study_time TIME DEFAULT NULL COMMENT 'StudyTime',
    study_description TEXT DEFAULT NULL COMMENT 'StudyDescription',
    modality VARCHAR(100) DEFAULT NULL COMMENT 'Modalidades separadas por coma (CT,MR)',
    
    -- Metadatos
    referring_physician VARCHAR(255) DEFAULT NULL COMMENT 'ReferringPhysicianName',
    institution_name VARCHAR(255) DEFAULT NULL COMMENT 'InstitutionName',
    series_count INT UNSIGNED DEFAULT 0 COMMENT 'Número de series',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación en BD',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última actualización',
    orthanc_created_at TIMESTAMP NULL COMMENT 'Fecha de creación en Orthanc',
    orthanc_last_update TIMESTAMP NULL COMMENT 'Última actualización en Orthanc',
    
    -- Índices
    INDEX idx_patient_id (patient_id),
    INDEX idx_study_date (study_date),
    INDEX idx_modality (modality),
    INDEX idx_institution (institution_name),
    INDEX idx_accession_number (accession_number),
    INDEX idx_orthanc_created (orthanc_created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.2 Índices Adicionales Recomendados

```sql
-- Para búsquedas por rango de fechas
CREATE INDEX idx_study_date_range ON orthanc_studies(study_date);

-- Para búsquedas por paciente y fecha
CREATE INDEX idx_patient_date ON orthanc_studies(patient_id, study_date);

-- Para búsquedas por modalidad
CREATE INDEX idx_modality_date ON orthanc_studies(modality, study_date);
```

---

## 7. Script Lua para Orthanc

### 7.1 Eventos a Capturar

El script Lua debe ejecutarse en el evento `OnStoredInstance` o `OnChange` cuando:
- Se almacena un nuevo estudio (primera instancia)
- Se actualiza un estudio existente
- Se elimina un estudio

### 7.2 Datos a Extraer en Lua

El script debe extraer de Orthanc y guardar en BD:

1. **Del recurso Study** (`/studies/{id}`):
   - MainDicomTags (todos los campos listados)
   - ID del estudio (orthanc_study_id)
   - Series (para contar y obtener modalidades)

2. **Del recurso Patient** (`/patients/{id}`):
   - MainDicomTags del paciente

3. **De las Series** (`/studies/{id}/series`):
   - Modalidades de cada serie para construir el campo `modality`

### 7.3 Ejemplo de Estructura Lua (Pseudocódigo)

```lua
function OnStoredInstance(dicom, instanceId)
    -- Obtener estudio padre
    local studyId = dicom.StudyInstanceUID
    local study = RestApiGet('/studies/' .. studyId)
    
    -- Obtener paciente
    local patientId = study.ParentPatient
    local patient = RestApiGet('/patients/' .. patientId)
    
    -- Obtener series para modalidades
    local series = RestApiGet('/studies/' .. studyId .. '/series')
    local modalities = {}
    for i, seriesId in ipairs(series) do
        local seriesData = RestApiGet('/series/' .. seriesId)
        local mod = seriesData.MainDicomTags.Modality
        if mod and not contains(modalities, mod) then
            table.insert(modalities, mod)
        end
    end
    
    -- Construir datos para BD
    local studyData = {
        orthanc_study_id = studyId,
        study_instance_uid = study.MainDicomTags.StudyInstanceUID,
        accession_number = study.MainDicomTags.AccessionNumber,
        patient_id = patient.MainDicomTags.PatientID,
        patient_name = patient.MainDicomTags.PatientName,
        patient_birth_date = formatDate(patient.MainDicomTags.PatientBirthDate),
        patient_sex = patient.MainDicomTags.PatientSex,
        study_date = formatDate(study.MainDicomTags.StudyDate),
        study_time = formatTime(study.MainDicomTags.StudyTime),
        study_description = study.MainDicomTags.StudyDescription,
        modality = table.concat(modalities, ','),
        referring_physician = study.MainDicomTags.ReferringPhysicianName,
        institution_name = study.MainDicomTags.InstitutionName,
        series_count = #series
    }
    
    -- Insertar/Actualizar en BD MySQL
    -- (Requiere conexión MySQL desde Lua o llamada HTTP a API PHP)
end
```

---

## 8. Modificaciones Necesarias en el Código PHP

### 8.1 Nuevo Método: `getAllStudiesFromDatabase()`

Crear método en `OrthancClient.php` o nueva clase que:
- Consulte la tabla `orthanc_studies` en lugar de Orthanc
- Aplique los mismos filtros (fecha, paciente, modalidad)
- Retorne la misma estructura de datos para compatibilidad

### 8.2 Modificar Endpoints

**`api/get_all_studies.php`**:
- Opción 1: Cambiar completamente a consulta BD
- Opción 2: Mantener fallback a Orthanc si no hay datos en BD

**`api/pacs-manager/list.php`**:
- Usar consulta a BD en lugar de `getAllStudiesEfficient()`

**`api/get_patient_studies.php`**:
- Consultar BD por `patient_id` en lugar de `findStudiesByPatientId()`

### 8.3 Mantener Compatibilidad

- Mantener métodos antiguos como fallback
- Agregar flag de configuración para elegir fuente (BD u Orthanc)
- Logging para monitorear uso de cada fuente

---

## 9. Consideraciones Adicionales

### 9.1 Sincronización

- **Inicial**: Script para migrar estudios existentes de Orthanc a BD
- **Incremental**: Lua script captura nuevos estudios automáticamente
- **Actualizaciones**: Lua script debe manejar actualizaciones de estudios
- **Eliminaciones**: Lua script debe marcar como eliminado o borrar de BD

### 9.2 Validación de Datos

- Validar que los datos en BD coincidan con Orthanc
- Script de verificación periódica
- Mecanismo de resincronización si hay discrepancias

### 9.3 Performance

- La tabla `orthanc_studies` debe tener índices apropiados
- Considerar particionamiento por fecha si hay muchos estudios
- Cachear consultas frecuentes si es necesario

### 9.4 Eliminación de Estudios

- Cuando se elimina un estudio en Orthanc, el Lua script debe:
  - Eliminar el registro de BD, o
  - Marcar como eliminado con timestamp

---

## 10. Plan de Implementación Sugerido

### Fase 1: Preparación
1. Crear tabla `orthanc_studies` en BD
2. Crear script de migración de estudios existentes
3. Ejecutar migración inicial

### Fase 2: Lua Script
1. Desarrollar script Lua para capturar eventos
2. Probar en ambiente de desarrollo
3. Implementar conexión a MySQL desde Lua (o API PHP intermedia)

### Fase 3: Modificación de APIs
1. Crear método `getAllStudiesFromDatabase()`
2. Modificar `get_all_studies.php` para usar BD
3. Modificar otros endpoints que consultan estudios

### Fase 4: Testing
1. Probar que los datos se sincronizan correctamente
2. Verificar que las interfaces funcionan igual
3. Comparar performance (BD vs Orthanc)

### Fase 5: Despliegue
1. Activar Lua script en producción
2. Cambiar endpoints a usar BD
3. Monitorear por un período

---

## 11. Archivos a Modificar

### Archivos PHP
- `api/OrthancClient.php` - Agregar métodos de BD
- `api/get_all_studies.php` - Cambiar a consulta BD
- `api/pacs-manager/list.php` - Cambiar a consulta BD
- `api/get_patient_studies.php` - Cambiar a consulta BD
- `api/informes/list.php` - Usar BD para obtener institución

### Archivos de Configuración
- Crear `api/OrthancDatabaseClient.php` (nueva clase)
- Configurar conexión MySQL para Lua script

### Scripts Lua
- `orthanc-sync-studies.lua` - Script principal de sincronización

### Base de Datos
- `database/migrations/create_orthanc_studies_table.sql`
- `database/migrations/migrate_existing_studies.php`

---

## 12. Notas Finales

- Este análisis se basa en el código actual del sistema
- Se recomienda revisar los logs de Orthanc para identificar consultas adicionales
- Considerar agregar más campos si se necesitan en el futuro
- Mantener documentación actualizada de la estructura de datos

---

**Fecha de Análisis**: 2025-12-17
**Analista**: Sistema de Análisis Automático
**Versión del Documento**: 1.0


