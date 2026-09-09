# 📋 Análisis de Diseño - PACS NODES MANAGER

**Versión**: 1.0.0  
**Fecha**: 2026-03-06
**Autor**: Sistema TJSMEDICAL

---

## 🎯 Objetivo del Módulo

Implementar un plugin/módulo completo para gestionar nodos PACS remotos y realizar operaciones DICOM estándar mediante dos protocolos:

1. **DIMSE** (C-FIND, C-MOVE, C-GET) - Para nodos legacy
2. **DICOMweb** (QIDO-RS, WADO-RS) - Para nodos modernos con streaming directo

El módulo permite la búsqueda y recuperación de estudios desde múltiples servidores DICOM remotos mediante la API de Orthanc, con detección automática del tipo de nodo y proxy inteligente según las capacidades del servidor remoto.

---

## 📐 Arquitectura General

### Estructura del Módulo

Siguiendo la arquitectura establecida en el proyecto (similar a `modules/email/`):

```
modules/pacs-nodes-manager/
├── PacsNodesManager.php          # Clase principal de gestión
├── PacsNodeClient.php            # Cliente para operaciones DICOM (C-FIND/C-MOVE/C-GET)
├── PacsNodeConfig.php            # Gestor de configuración de nodos
├── install.php                   # Instalador del módulo
├── composer.json                 # Dependencias (si aplica)
│
├── api/                          # Endpoints REST
│   ├── _auth.php                 # Autenticación compartida
│   ├── nodes.php                 # CRUD de nodos (GET, POST, PUT, DELETE)
│   ├── ping.php                  # Test de conectividad
│   ├── find.php                  # C-FIND (búsqueda DIMSE)
│   ├── retrieve.php              # C-MOVE/C-GET (recuperación DIMSE)
│   ├── jobs.php                  # Monitoreo de jobs asincrónicos
│   ├── dashboard.php             # Estadísticas y monitor
│   ├── rs/                       # Endpoints DICOMweb (QIDO-RS/WADO-RS)
│   │   ├── studies.php           # QIDO-RS: Listar estudios
│   │   ├── series.php            # QIDO-RS: Listar series
│   │   ├── instances.php         # WADO-RS: Obtener instancias
│   │   └── frames.php            # WADO-RS: Obtener frames
│   └── proxy.php                 # Proxy inteligente (detecta tipo de nodo)
│
├── config/                       # Configuración
│   ├── nodes_config.php          # Configuración global del módulo
│   └── default_modalities.json   # Plantilla de modalidades
│
├── database/                     # Scripts SQL
│   ├── install.sql               # Tablas principales
│   └── migrations/               # Migraciones futuras
│
├── docs/                         # Documentación
│   ├── ANALISIS_DISENO.md        # Este archivo
│   ├── INSTALACION.md            # Guía de instalación
│   ├── USO.md                    # Guía de uso
│   ├── API_REFERENCE.md          # Referencia de API
│   └── CHANGELOG.md              # Historial de cambios
│
├── assets/                       # Recursos frontend
│   ├── js/
│   │   └── pacs-nodes-manager.js # Lógica JavaScript principal
│   └── css/
│       └── pacs-nodes-manager.css # Estilos específicos
│
├── templates/                    # Plantillas HTML (si aplica)
│
└── logs/                         # Logs del módulo
    └── pacs-nodes.log
```

---

## 🗄️ Modelo de Datos

### Tabla: `pacs_nodes`

Almacena la configuración de nodos PACS remotos. Soporta tanto DIMSE (legacy) como DICOMweb (moderno).

```sql
CREATE TABLE `pacs_nodes` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL COMMENT 'Nombre descriptivo del nodo',
  
  -- Configuración DIMSE (para nodos legacy)
  `aet` VARCHAR(16) DEFAULT NULL COMMENT 'Application Entity Title (DIMSE)',
  `host` VARCHAR(255) DEFAULT NULL COMMENT 'IP o hostname (DIMSE)',
  `port` INT(5) UNSIGNED DEFAULT NULL COMMENT 'Puerto DICOM (típicamente 104 para DIMSE)',
  
  -- Configuración DICOMweb (para nodos modernos)
  `dicomweb_url` VARCHAR(500) DEFAULT NULL COMMENT 'URL base DICOMweb (ej: https://pacs.example.com/dicomweb)',
  `dicomweb_username` VARCHAR(255) DEFAULT NULL COMMENT 'Usuario DICOMweb (si requiere autenticación)',
  `dicomweb_password` VARCHAR(255) DEFAULT NULL COMMENT 'Contraseña DICOMweb (encriptada)',
  `dicomweb_auth_type` ENUM('none', 'basic', 'bearer', 'oauth2') DEFAULT 'none' COMMENT 'Tipo de autenticación DICOMweb',
  
  -- Configuración general
  `node_type` ENUM('dimse', 'dicomweb', 'local', 'hybrid') DEFAULT 'dimse' COMMENT 'Tipo de nodo',
  `username` VARCHAR(255) DEFAULT NULL COMMENT 'Usuario (DIMSE, si requiere autenticación)',
  `password` VARCHAR(255) DEFAULT NULL COMMENT 'Contraseña (DIMSE, encriptada)',
  `description` TEXT DEFAULT NULL COMMENT 'Descripción del nodo',
  
  -- Estado y monitoreo
  `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Nodo activo/inactivo',
  `last_ping` DATETIME DEFAULT NULL COMMENT 'Última prueba de conectividad',
  `last_ping_status` ENUM('success', 'failed', 'timeout') DEFAULT NULL,
  `last_ping_latency_ms` INT(11) DEFAULT NULL COMMENT 'Latencia en milisegundos',
  `cache_size_mb` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Tamaño de cache local (MB)',
  `cache_studies_count` INT(11) DEFAULT 0 COMMENT 'Número de estudios en cache',
  `last_sync` DATETIME DEFAULT NULL COMMENT 'Última sincronización',
  
  -- Metadata
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Usuario que creó el nodo',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_aet` (`aet`),
  KEY `idx_active` (`is_active`),
  KEY `idx_node_type` (`node_type`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notas sobre tipos de nodo**:
- **`dimse`**: Nodo legacy que usa DIMSE (C-FIND, C-MOVE). Requiere `aet`, `host`, `port`.
- **`dicomweb`**: Nodo moderno que usa DICOMweb (QIDO-RS, WADO-RS). Requiere `dicomweb_url`.
- **`local`**: Nodo Orthanc local. No requiere configuración remota.
- **`hybrid`**: Nodo que soporta ambos protocolos. Requiere ambas configuraciones.

### Tabla: `pacs_node_queries`

Cache de consultas C-FIND realizadas.

```sql
CREATE TABLE `pacs_node_queries` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `query_hash` VARCHAR(64) NOT NULL COMMENT 'Hash MD5 de la query para cache',
  `query_params` JSON NOT NULL COMMENT 'Parámetros de la búsqueda',
  `results_count` INT(11) DEFAULT 0 COMMENT 'Número de resultados',
  `results_data` JSON DEFAULT NULL COMMENT 'Resultados cacheados (opcional)',
  `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL COMMENT 'Expiración del cache',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_query` (`node_id`, `query_hash`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_expires_at` (`expires_at`),
  FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabla: `pacs_node_jobs`

Monitoreo de jobs asincrónicos (C-MOVE/C-GET).

```sql
CREATE TABLE `pacs_node_jobs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `job_type` ENUM('c_move', 'c_get') NOT NULL,
  `orthanc_job_id` VARCHAR(255) DEFAULT NULL COMMENT 'ID del job en Orthanc',
  `study_instance_uids` JSON NOT NULL COMMENT 'UIDs de estudios a recuperar',
  `status` ENUM('pending', 'running', 'success', 'failed', 'cancelled') DEFAULT 'pending',
  `progress` INT(3) DEFAULT 0 COMMENT 'Progreso 0-100',
  `callback_url` VARCHAR(500) DEFAULT NULL COMMENT 'Webhook para notificar',
  `error_message` TEXT DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_status` (`status`),
  KEY `idx_orthanc_job_id` (`orthanc_job_id`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabla: `pacs_node_statistics`

Estadísticas de uso por nodo.

```sql
CREATE TABLE `pacs_node_statistics` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `queries_count` INT(11) DEFAULT 0 COMMENT 'Número de C-FIND realizados',
  `retrieves_count` INT(11) DEFAULT 0 COMMENT 'Número de C-MOVE/C-GET realizados',
  `studies_retrieved` INT(11) DEFAULT 0 COMMENT 'Total de estudios recuperados',
  `success_rate` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Tasa de éxito %',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_node_date` (`node_id`, `date`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_date` (`date`),
  FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 🔌 Integración con Orthanc

### Configuración Automática en DicomModalities (Solo para nodos DIMSE)

Cuando se crea/actualiza un nodo **DIMSE**, se debe configurar automáticamente en Orthanc:

**Endpoint Orthanc**: `PUT /modalities/{aet}`

```json
{
  "AET": "NODE_AET",
  "Host": "192.168.1.100",
  "Port": 104,
  "Username": "user",
  "Password": "pass",
  "Manufacturer": "Generic"
}
```

**Clase PHP**: `PacsNodeConfig.php` se encargará de:
- Sincronizar nodos DIMSE con `DicomModalities` de Orthanc
- Validar configuración antes de guardar
- Manejar errores de sincronización
- **No sincronizar** nodos DICOMweb (se acceden directamente)

### Lógica Inteligente por Tipo de Nodo

El módulo detecta automáticamente el tipo de nodo y usa el protocolo apropiado:

```php
// En PacsNodeClient.php
public function executeQuery($node, $query) {
    if ($node['node_type'] === 'dicomweb' || !empty($node['dicomweb_url'])) {
        // Nodo moderno → Proxy directo DICOMweb
        return $this->proxyDicomwebQuery($node, $query);
    } else if ($node['node_type'] === 'dimse' || (!empty($node['aet']) && !empty($node['host']))) {
        // Nodo legacy → Traduce a C-FIND/C-MOVE
        return $this->executeDimseQuery($node, $query);
    } else if ($node['node_type'] === 'local') {
        // Nodo local → Orthanc nativo
        return $this->queryLocalOrthanc($query);
    } else {
        throw new Exception('Tipo de nodo no soportado o configuración incompleta');
    }
}
```

---

## 🔍 Operaciones C-FIND

### Flujo de Búsqueda

1. **Usuario realiza búsqueda** → Frontend envía query a `/api/pacs-nodes-manager/find.php`
2. **Backend valida query** → Verifica permisos y formato
3. **Verifica cache** → Busca en `pacs_node_queries` si existe query similar reciente
4. **Ejecuta C-FIND** → Usa API de Orthanc: `POST /modalities/{aet}/query`
5. **Procesa resultados** → Convierte respuesta DICOM a JSON estructurado
6. **Guarda en cache** → Almacena resultados en `pacs_node_queries`
7. **Retorna resultados** → Frontend muestra estudios encontrados

### Formato de Query C-FIND

**Request**:
```json
{
  "Level": "Study",
  "Query": {
    "PatientName": "*",
    "PatientID": "12345",
    "StudyDate": "20260101-20260301",
    "AccessionNumber": "ACC123",
    "ModalitiesInStudy": "CT"
  }
}
```

**Response**:
```json
{
  "success": true,
  "data": [
    {
      "PatientName": "APELLIDO^NOMBRE",
      "PatientID": "12345",
      "PatientBirthDate": "19800101",
      "StudyInstanceUID": "1.2.840.113619.2.55.3.123456789",
      "StudyDate": "20260115",
      "StudyTime": "143000",
      "StudyDescription": "CT Tórax",
      "AccessionNumber": "ACC123",
      "ModalitiesInStudy": "CT",
      "NumberOfStudyRelatedSeries": 1,
      "NumberOfStudyRelatedInstances": 50
    }
  ],
  "count": 1,
  "cached": false
}
```

---

## 📥 Operaciones C-MOVE/C-GET

### Flujo de Recuperación

1. **Usuario selecciona estudios** → Frontend envía `StudyInstanceUIDs` a `/api/pacs-nodes-manager/retrieve.php`
2. **Backend crea job** → Registra en `pacs_node_jobs` con status `pending`
3. **Ejecuta C-MOVE** → Usa API de Orthanc: `POST /modalities/{aet}/move`
4. **Orthanc procesa asincrónicamente** → Retorna `job_id`
5. **Backend actualiza job** → Guarda `orthanc_job_id` y cambia status a `running`
6. **Monitoreo** → Polling periódico a `/jobs/{id}` para verificar progreso
7. **Webhook (opcional)** → Cuando completa, llama a `callback_url` si está configurado
8. **Actualiza estadísticas** → Incrementa contadores en `pacs_node_statistics`

### Formato de Retrieve

**Request**:
```json
{
  "StudyInstanceUIDs": [
    "1.2.840.113619.2.55.3.123456789",
    "1.2.840.113619.2.55.3.987654321"
  ],
  "Callback": "/webhook/studies-received",
  "Priority": "medium"
}
```

**Response**:
```json
{
  "success": true,
  "job_id": 123,
  "orthanc_job_id": "abc123-def456-ghi789",
  "status": "running",
  "message": "Job iniciado correctamente"
}
```

---

## 🌐 Operaciones DICOMweb (QIDO-RS / WADO-RS)

### Soporte para Nodos Modernos

Para nodos que soportan DICOMweb, el módulo proporciona endpoints que actúan como proxy inteligente, permitiendo streaming directo sin necesidad de descargar estudios completos.

### QIDO-RS (Query based on ID for DICOM Objects - RESTful Services)

Endpoints para búsqueda y listado sin descargar datos:

#### Listar Estudios
```
GET /api/pacs-nodes-manager/rs/{nodeId}/studies?PatientID=123&Modalities=CT&limit=50
```

**Parámetros de query**:
- `PatientID`: ID del paciente
- `PatientName`: Nombre del paciente
- `StudyDate`: Fecha del estudio (formato: YYYYMMDD o rango YYYYMMDD-YYYYMMDD)
- `Modalities`: Modalidades (CT, MR, DX, etc.)
- `AccessionNumber`: Número de acceso
- `limit`: Límite de resultados (default: 50)
- `offset`: Offset para paginación

**Response**:
```json
{
  "success": true,
  "data": [
    {
      "00080020": { "Value": ["20260115"] },
      "00080030": { "Value": ["143000"] },
      "00100010": { "Value": [{"Alphabetic": "APELLIDO^NOMBRE"}] },
      "00100020": { "Value": ["12345"] },
      "0020000D": { "Value": ["1.2.840.113619.2.55.3.123456789"] },
      "00081030": { "Value": ["CT Tórax"] },
      "00080050": { "Value": ["ACC123"] },
      "00080061": { "Value": ["CT"] }
    }
  ],
  "count": 1
}
```

#### Listar Series de un Estudio
```
GET /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series
```

#### Listar Instancias de una Serie
```
GET /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series/{seriesUID}/instances
```

### WADO-RS (Web Access to DICOM Objects - RESTful Services)

Endpoints para streaming de imágenes y frames:

#### Obtener Instancia (Imagen completa)
```
GET /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series/{seriesUID}/instances/{sopInstanceUID}
```

**Headers de respuesta**:
- `Content-Type: application/dicom` (imagen DICOM completa)
- `Content-Type: image/jpeg` (si se solicita con `?format=jpeg`)

#### Obtener Frames (Streaming Inteligente)
```
GET /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series/{seriesUID}/instances/{sopInstanceUID}/frames/1-10
```

**Parámetros**:
- `frames`: Rango de frames (ej: `1-10`, `1,3,5`, `1-`)
- `format`: Formato de salida (`dicom`, `jpeg`, `png`)
- `quality`: Calidad JPEG (1-100, default: 90)

**Response**: Multipart response con cada frame como parte separada.

### Proxy Inteligente

El módulo detecta automáticamente el tipo de nodo y enruta las peticiones:

```php
// En api/rs/proxy.php
public function handleRequest($nodeId, $path, $queryParams) {
    $node = $this->getNode($nodeId);
    
    if ($node['node_type'] === 'dicomweb' || !empty($node['dicomweb_url'])) {
        // Proxy directo a DICOMweb remoto
        return $this->proxyToRemoteDicomweb($node, $path, $queryParams);
    } else if ($node['node_type'] === 'dimse' || (!empty($node['aet']) && !empty($node['host']))) {
        // Traduce DICOMweb request a DIMSE y retorna en formato DICOMweb
        return $this->dimseToDicomweb($node, $path, $queryParams);
    } else if ($node['node_type'] === 'local') {
        // Usa Orthanc nativo (soporta DICOMweb)
        return $this->orthancLocalWado($node, $path, $queryParams);
    }
}
```

### Ventajas de DICOMweb

1. **Streaming Directo**: No requiere descargar estudios completos
2. **Integración con Viewers**: Compatible con OHIF, VolView, Cornerstone, etc.
3. **Performance**: Mejor rendimiento para visualización
4. **Estándar Moderno**: Protocolo REST estándar de la industria

---

## 🎨 Interfaz de Usuario

### Página Principal: `pacs-nodes-manager.html`

**Estructura**:
```
┌─────────────────────────────────────────────────────────┐
│  PACS NODES MANAGER                    [➕ Nuevo Nodo]  │
├─────────────────────────────────────────────────────────┤
│                                                           │
│  ┌─────────────────┐  ┌──────────────────────────────┐ │
│  │  LISTA DE NODOS │  │    BUSCADOR UNIFICADO        │ │
│  │                 │  │                              │ │
│  │  [Nodo 1] 🟢    │  │  Nodo: [Dropdown ▼]          │ │
│  │  [Nodo 2] 🟡    │  │  Filtros:                    │ │
│  │  [Nodo 3] 🔴    │  │  - Paciente: [____]          │ │
│  │                 │  │  - Fecha: [____] - [____]    │ │
│  │  [➕ Agregar]   │  │  - Modalidad: [____]         │ │
│  │                 │  │                              │ │
│  │  Status:        │  │  [🔍 Buscar]                 │ │
│  │  🟢 ONLINE      │  │                              │ │
│  │  🟡 TIMEOUT      │  │  ┌────────────────────────┐ │
│  │  🔴 ERROR        │  │  │ RESULTADOS              │ │
│  │                 │  │  │                        │ │
│  └─────────────────┘  │  │ [Estudio 1] [📥]        │ │
│                        │  │ [Estudio 2] [📥]        │ │
│                        │  │                        │ │
│                        │  └────────────────────────┘ │
│                        └──────────────────────────────┘ │
│                                                           │
│  ┌────────────────────────────────────────────────────┐ │
│  │  JOBS ACTIVOS                                      │ │
│  │  [Job 1] ████████░░ 80% Recuperando...            │ │
│  │  [Job 2] ██████████ 100% ✅ Completado             │ │
│  └────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
```

### Componentes UI

1. **Tabla de Nodos**:
   - Columnas: Nombre, Tipo, Host/URL, Status, Cache, Última sincronización, Latencia, Acciones
   - **Estados visuales**:
     - 🟢 **ONLINE**: Nodo conectado y respondiendo
     - 🟡 **TIMEOUT**: Nodo con latencia alta o respuesta lenta
     - 🔴 **ERROR**: Nodo no responde o error de conexión
   - **Información por nodo**:
     ```json
     {
       "nodeId": "sucursal1",
       "name": "Hospital Central",
       "status": "🟢 ONLINE",
       "type": "DICOMweb",  // o "DIMSE" o "LOCAL" o "HYBRID"
       "cache": "2.3GB (150 estudios)",
       "lastSync": "2026-03-06 11:30",
       "latency": "45ms",
       "dicomwebUrl": "https://pacs.example.com/dicomweb"
     }
     ```
   - Acciones: Editar, Eliminar, Test Ping, Ver Logs, Configurar Viewer

2. **Buscador Unificado**:
   - Dropdown para seleccionar nodo
   - Filtros DICOM estándar (PatientID, PatientName, StudyDate, AccessionNumber, Modality)
   - Botón de búsqueda
   - Resultados en tabla con preview

3. **Preview de Estudios**:
   - Modal que muestra detalles del estudio antes de retrieve
   - Información: Paciente, Fecha, Modalidad, Descripción, Series, Instancias

4. **Monitor de Jobs**:
   - Lista de jobs activos con barra de progreso
   - Filtros por status
   - Botón para cancelar jobs

5. **Dashboard/Estadísticas**:
   - Gráficos de uso por nodo
   - Tasa de éxito de retrieves
   - Estudios recuperados por día

---

## 🔐 Seguridad y Permisos

### Permisos Requeridos

1. **Permiso Funcional**: `pacs_nodes_manager`
   - Requerido para todas las operaciones del módulo
   - Verificar en cada endpoint API

2. **Permiso de Interfaz**: `gui_pacs_nodes_manager`
   - Requerido para mostrar en el sidebar
   - Gestionado por `sidebar-gui-manager.js`

### Validaciones de Seguridad

- ✅ Autenticación requerida en todos los endpoints
- ✅ Validación de permisos antes de operaciones
- ✅ Sanitización de inputs (AET, IP, puertos)
- ✅ Encriptación de contraseñas en base de datos
- ✅ Rate limiting en búsquedas (prevenir abuso)
- ✅ Logging de todas las operaciones
- ✅ Validación de formato DICOM en queries

---

## 📡 Endpoints API Propuestos

### Gestión de Nodos

```
GET    /api/pacs-nodes-manager/nodes.php              # Listar nodos
POST   /api/pacs-nodes-manager/nodes.php              # Crear nodo
GET    /api/pacs-nodes-manager/nodes.php?id={id}       # Obtener nodo
PUT    /api/pacs-nodes-manager/nodes.php?id={id}      # Actualizar nodo
DELETE /api/pacs-nodes-manager/nodes.php?id={id}      # Eliminar nodo
```

### Operaciones DICOM (DIMSE)

```
POST   /api/pacs-nodes-manager/ping.php?id={id}       # Test conectividad
GET    /api/pacs-nodes-manager/find.php?node_id={id}&query=...  # Búsqueda rápida (C-FIND)
POST   /api/pacs-nodes-manager/find.php               # Búsqueda avanzada (C-FIND)
POST   /api/pacs-nodes-manager/retrieve.php           # Recuperar estudios (C-MOVE/C-GET)
```

### Operaciones DICOMweb (QIDO-RS / WADO-RS)

```
# QIDO-RS (Query)
GET    /api/pacs-nodes-manager/rs/{nodeId}/studies                    # Listar estudios
GET    /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series   # Listar series
GET    /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series/{seriesUID}/instances  # Listar instancias

# WADO-RS (Retrieve)
GET    /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series/{seriesUID}/instances/{sopInstanceUID}  # Obtener instancia
GET    /api/pacs-nodes-manager/rs/{nodeId}/studies/{studyUID}/series/{seriesUID}/instances/{sopInstanceUID}/frames/{frames}  # Obtener frames
```

### Monitoreo

```
GET    /api/pacs-nodes-manager/jobs.php                # Listar jobs
GET    /api/pacs-nodes-manager/jobs.php?id={id}        # Estado de job
DELETE /api/pacs-nodes-manager/jobs.php?id={id}       # Cancelar job
GET    /api/pacs-nodes-manager/dashboard.php           # Estadísticas
```

---

## 🔄 Integración con Viewer

### Botón "Buscar en otros PACS"

**Ubicación**: En pantalla de paciente/estudio (`paciente.html` o `viewer.html`)

**Funcionalidad**:
1. Usuario hace clic en "Buscar en otros PACS"
2. Se abre modal con buscador de PACS NODES MANAGER
3. Usuario selecciona nodo y realiza búsqueda
4. Al seleccionar estudio:
   - **Si nodo es DICOMweb**: Streaming directo al viewer (sin descargar)
   - **Si nodo es DIMSE**: Se ejecuta auto-retrieve y luego se abre en viewer
5. Cuando el estudio está disponible, se muestra notificación
6. Usuario puede abrir estudio en viewer

**Implementación**:
- Incluir `pacs-nodes-manager.js` en página del viewer
- Agregar botón en UI del paciente/estudio
- Modal reutilizable del módulo

### Configuración para Viewers Modernos (OHIF, VolView, Cornerstone, etc.)

El módulo proporciona endpoints DICOMweb compatibles con cualquier viewer que soporte el estándar DICOMweb.

**Configuración para Viewer**:
```json
{
  "wadoRoot": "https://tu-sistema.com/api/pacs-nodes-manager/rs/{nodeId}",
  "qidoRoot": "https://tu-sistema.com/api/pacs-nodes-manager/rs/{nodeId}",
  "wadoRoot": "https://tu-sistema.com/api/pacs-nodes-manager/rs/{nodeId}",
  "qidoSupported": true,
  "wadoSupported": true,
  "wadouriSupported": false,
  "wadorsSupported": true
}
```

**Ejemplo de uso en OHIF**:
```javascript
const config = {
  servers: {
    dicomWeb: [
      {
        name: "Hospital Central",
        wadoUriRoot: "https://tu-sistema.com/api/pacs-nodes-manager/rs/sucursal1",
        qidoRoot: "https://tu-sistema.com/api/pacs-nodes-manager/rs/sucursal1",
        wadoRoot: "https://tu-sistema.com/api/pacs-nodes-manager/rs/sucursal1",
        qidoSupportsIncludeField: true,
        supportsReject: false,
        imageRendering: "wadors",
        thumbnailRendering: "wadors",
        requestTransferSyntax: "1.2.840.10008.1.2.4.70" // JPEG Lossless
      }
    ]
  }
};
```

**Ventajas**:
- ✅ Streaming directo sin descargar estudios completos
- ✅ Compatible con cualquier viewer DICOMweb estándar
- ✅ Mejor performance para visualización
- ✅ Soporte para múltiples nodos simultáneos

---

## 📊 Cache y Optimización

### Estrategia de Cache

1. **Cache de Queries C-FIND**:
   - TTL: 5 minutos por defecto (configurable)
   - Hash MD5 de parámetros de query
   - Invalidación manual o automática

2. **Cache de Resultados**:
   - Opcional: guardar resultados completos en `pacs_node_queries.results_data`
   - Útil para queries frecuentes
   - Limpieza automática de queries expiradas

3. **Paginación**:
   - Límite por defecto: 50 resultados
   - Offset para paginación
   - Scroll infinito en frontend

---

## 🧪 Testing

### Tests Propuestos

1. **Unit Tests**:
   - `PacsNodeClient::testCFind()`
   - `PacsNodeClient::testCMove()`
   - `PacsNodeConfig::testSyncWithOrthanc()`

2. **Integration Tests**:
   - Flujo completo de búsqueda y retrieve
   - Sincronización con Orthanc
   - Manejo de errores de conectividad

3. **E2E Tests**:
   - Crear nodo → Buscar → Retrieve → Verificar en Orthanc

---

## 📝 Consideraciones Técnicas

### Dependencias

- **PHP**: 7.4+
- **Orthanc**: 1.x o 2.x con API REST habilitada
- **MySQL/MariaDB**: 5.7+ (soporte JSON)
- **Extensiones PHP**: `curl`, `json`, `openssl`

### Límites y Timeouts

- **Timeout C-FIND**: 30 segundos
- **Timeout C-MOVE**: 300 segundos (5 minutos)
- **Tamaño máximo de resultados**: 1000 estudios por query
- **Jobs concurrentes**: Máximo 5 por nodo

### Manejo de Errores

- Errores de conectividad → Retry con backoff exponencial
- Errores DICOM → Log detallado + mensaje usuario
- Timeouts → Notificar usuario + opción de reintentar
- Jobs fallidos → Guardar error_message + permitir reintento

---

## 🚀 Plan de Implementación

### Fase 1: Base del Módulo (Semana 1)
- [ ] Estructura de directorios
- [ ] Tablas de base de datos
- [ ] Clases PHP base (`PacsNodesManager`, `PacsNodeConfig`)
- [ ] Instalador (`install.php`)
- [ ] Documentación inicial

### Fase 2: Gestión de Nodos (Semana 1-2)
- [ ] CRUD de nodos (API + UI)
- [ ] Sincronización con Orthanc `DicomModalities`
- [ ] Test de conectividad (ping)
- [ ] Validaciones y seguridad

### Fase 3: Operaciones C-FIND (Semana 2-3)
- [ ] Cliente C-FIND (`PacsNodeClient`)
- [ ] API de búsqueda
- [ ] Sistema de cache
- [ ] UI de búsqueda

### Fase 4: Operaciones C-MOVE/C-GET (Semana 3-4)
- [ ] Cliente C-MOVE/C-GET
- [ ] Sistema de jobs asincrónicos
- [ ] Monitoreo de progreso
- [ ] Webhooks

### Fase 5: UI Completa (Semana 4)
- [ ] Página principal completa
- [ ] Dashboard de estadísticas
- [ ] Integración con viewer
- [ ] Logs y debugging

### Fase 6: Testing y Documentación (Semana 5)
- [ ] Tests unitarios e integración
- [ ] Documentación completa
- [ ] Guías de uso
- [ ] Changelog

---

## 📚 Documentación a Generar

1. **INSTALACION.md**: Guía paso a paso de instalación
2. **USO.md**: Guía de uso para usuarios finales
3. **API_REFERENCE.md**: Documentación completa de endpoints
4. **CHANGELOG.md**: Historial de versiones y cambios
5. **TROUBLESHOOTING.md**: Solución de problemas comunes

---

## 🔮 Mejoras Futuras (v2.0+)

- [ ] Soporte para múltiples AETs por nodo
- [ ] Búsqueda simultánea en múltiples nodos
- [ ] Sincronización automática de estudios
- [ ] Notificaciones push en tiempo real
- [ ] Exportación de resultados a CSV/Excel
- [ ] Integración con sistemas de scheduling
- [ ] Soporte para C-STORE (envío a nodos remotos)

---

## ✅ Checklist de Validación

Antes de considerar el módulo completo:

- [ ] Todas las tablas creadas correctamente
- [ ] CRUD de nodos funcionando
- [ ] C-FIND operativo con cache
- [ ] C-MOVE/C-GET con monitoreo de jobs
- [ ] UI responsive y funcional
- [ ] Integración con sidebar
- [ ] Permisos configurados
- [ ] Documentación completa
- [ ] Tests pasando
- [ ] Logs funcionando
- [ ] Sin errores en producción

---

**Fin del Análisis de Diseño**

*Este documento debe actualizarse con cada cambio significativo en el diseño del módulo.*
