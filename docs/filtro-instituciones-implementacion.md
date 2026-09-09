# Documentación: Filtro de Instituciones por Usuario

## 📋 Índice
1. [Descripción General](#descripción-general)
2. [Estructura de Base de Datos](#estructura-de-base-de-datos)
3. [Flujo del Proceso](#flujo-del-proceso)
4. [Archivos Involucrados](#archivos-involucrados)
5. [Implementación Paso a Paso](#implementación-paso-a-paso)
6. [Casos de Uso](#casos-de-uso)
7. [Consideraciones de Rendimiento](#consideraciones-de-rendimiento)

---

## 📖 Descripción General

El **Filtro de Instituciones** es un sistema de control de acceso que permite restringir la visualización de estudios e informes médicos a usuarios específicos basándose en las instituciones médicas asociadas a cada estudio.

### Objetivo
Permitir que usuarios con el permiso `filter_institutions` solo puedan ver y gestionar estudios/informes de instituciones específicas que han sido configuradas en su perfil.

### Características Principales
- ✅ Control granular por institución
- ✅ Múltiples instituciones permitidas por usuario
- ✅ Filtrado automático en listas de estudios e informes
- ✅ Filtrado en dropdowns de selección
- ✅ Compatible con el sistema de permisos existente

---

## 🗄️ Estructura de Base de Datos

### Tabla: `usuarios`

#### Campo Agregado
```sql
instituciones_permitidas JSON NULL COMMENT 'Array JSON con nombres de instituciones permitidas para filtrar estudios'
```

#### Estructura del JSON
```json
["HOSPITAL DIGITAL", "CLINICA X", "CENTRO Y"]
```

**Notas:**
- Tipo: `JSON` (MySQL 5.7+) o `TEXT` (versiones anteriores)
- Formato: Array JSON de strings
- Nullable: Sí (si el usuario no tiene el permiso, puede ser NULL)
- Los nombres deben coincidir exactamente con los nombres de instituciones en los estudios

### Tabla: `system_permissions`

#### Permiso Agregado
```sql
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('filter_institutions', 'Filtrar por Instituciones', 'Permite restringir la visualización de estudios a instituciones específicas', 'estudios')
```

---

## 🔄 Flujo del Proceso

### 1. Configuración del Usuario (User Management)

```
┌─────────────────────────────────────────────────────────┐
│ 1. Usuario con permiso "Filtrar por Instituciones"     │
│    activado en user-management                          │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│ 2. Campo de texto aparece automáticamente              │
│    "Instituciones Permitidas"                          │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│ 3. Administrador ingresa instituciones separadas por    │
│    comas: "HOSPITAL DIGITAL, CLINICA X"                │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│ 4. Sistema procesa y guarda como JSON en BD:          │
│    ["HOSPITAL DIGITAL", "CLINICA X"]                   │
└─────────────────────────────────────────────────────────┘
```

### 2. Consulta de Estudios/Informes

```
┌─────────────────────────────────────────────────────────┐
│ 1. Usuario solicita lista de estudios/informes          │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│ 2. Sistema verifica permisos del usuario                │
│    - ¿Tiene permiso 'filter_institutions'?              │
└─────────────────┬───────────────────────────────────────┘
                  │
        ┌─────────┴─────────┐
        │                   │
        ▼                   ▼
    ┌───────┐         ┌──────────────┐
    │  NO   │         │     SÍ       │
    └───┬───┘         └──────┬───────┘
        │                    │
        │                    ▼
        │         ┌──────────────────────────┐
        │         │ 3. Obtener instituciones │
        │         │    permitidas de BD      │
        │         └──────────┬───────────────┘
        │                    │
        │                    ▼
        │         ┌──────────────────────────┐
        │         │ 4. Consultar estudios    │
        │         │    desde PACS/BD        │
        │         └──────────┬───────────────┘
        │                    │
        │                    ▼
        │         ┌──────────────────────────┐
        │         │ 5. Para cada estudio:    │
        │         │    - Obtener institution │
        │         │    - Comparar con lista  │
        │         │    - Filtrar si no match │
        │         └──────────┬───────────────┘
        │                    │
        └────────────────────┴───────────────┐
                                             │
                                             ▼
                              ┌──────────────────────────┐
                              │ 6. Retornar solo estudios│
                              │    de instituciones      │
                              │    permitidas            │
                              └──────────────────────────┘
```

### 3. Filtrado en Dropdowns

```
┌─────────────────────────────────────────────────────────┐
│ 1. Sistema carga lista de instituciones disponibles    │
│    desde estudios cargados                              │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│ 2. Si usuario tiene permiso filter_institutions:       │
│    - Obtener instituciones permitidas                  │
│    - Filtrar lista para mostrar solo permitidas        │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│ 3. Populate dropdown con instituciones filtradas       │
└─────────────────────────────────────────────────────────┘
```

---

## 📁 Archivos Involucrados

### Backend (PHP)

#### 1. `api/users/manage-real-complete.php`
**Función:** Gestión de usuarios (crear, editar, listar)
**Modificaciones:**
- Agregado campo `instituciones_permitidas` al SELECT en `handleGetUsers()`
- Agregado campo a `$allowedFields` en `handleUpdateUser()`
- Agregado manejo especial para JSON en actualización
- Agregado campo al INSERT en `handleCreateUser()`

**Líneas clave:**
```php
// SELECT
u.instituciones_permitidas,

// UPDATE - Manejo especial
elseif ($field === 'instituciones_permitidas') {
    if ($input[$field] === null || $input[$field] === '') {
        $values[] = null;
    } elseif (is_string($input[$field])) {
        $decoded = json_decode($input[$field], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $values[] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    }
}
```

#### 2. `api/informes/list.php`
**Función:** Listar informes médicos
**Modificaciones:**
- Verificación del permiso `filter_institutions`
- Obtención de instituciones permitidas del usuario
- Filtrado post-consulta consultando Orthanc

**Líneas clave:**
```php
// Verificar permiso
$has_filter_institutions = in_array('all', $user_permisos) || 
                          in_array('filter_institutions', $user_permisos);

// Obtener instituciones
if ($has_filter_institutions && $user_id) {
    $stmt = $db->prepare("SELECT instituciones_permitidas FROM usuarios WHERE id = ?");
    // ... procesar JSON
}

// Aplicar filtro
if ($has_filter_institutions && !empty($allowed_institutions)) {
    // Consultar Orthanc para cada estudio
    // Filtrar informes
}
```

#### 3. `api/get_all_studies.php`
**Función:** Listar estudios desde PACS
**Modificaciones:**
- Similar a `list.php` pero para estudios
- Filtrado durante el procesamiento de estudios

#### 4. `api/users/get-institutions.php` (NUEVO)
**Función:** Endpoint para obtener instituciones permitidas de un usuario
**Uso:** Llamado desde frontend para filtrar dropdowns

```php
GET api/users/get-institutions.php?user_id=123
Response: {
    "success": true,
    "instituciones": ["HOSPITAL DIGITAL", "CLINICA X"]
}
```

#### 5. `api/OrthancClient.php`
**Función:** Cliente para consultar Orthanc
**Modificaciones:**
- Agregado `institution_name` al método `getStudyDetails()`

### Frontend (JavaScript)

#### 1. `user-management-v2.js`
**Función:** Gestión de usuarios en frontend
**Modificaciones:**
- Función `setupInstitucionesFieldToggle()` para mostrar/ocultar campo
- Procesamiento de instituciones al guardar
- Carga de instituciones al editar

**Funciones clave:**
```javascript
setupInstitucionesFieldToggle() {
    // Muestra/oculta campo según estado del checkbox
    // Agrega listener al checkbox perm_filter_institutions
}

// En saveUser()
if (permissions.includes('filter_institutions')) {
    const institucionesText = formData.get('instituciones_permitidas') || '';
    const instituciones = institucionesText.split(',').map(inst => inst.trim());
    userData.instituciones_permitidas = JSON.stringify(instituciones);
}
```

#### 2. `assets/js/dashboard-with-permissions.js`
**Función:** Dashboard principal de estudios
**Modificaciones:**
- Propiedades: `hasFilterInstitutionsPermission`, `allowedInstitutions`
- Obtención de instituciones en `checkUserPermissions()`
- Filtrado en `updateInstitutionFilter()`

**Código clave:**
```javascript
// Obtener instituciones
if (this.hasFilterInstitutionsPermission && result.user.id) {
    const instResponse = await fetch(`api/users/get-institutions.php?user_id=${result.user.id}`);
    const instResult = await instResponse.json();
    this.allowedInstitutions = instResult.instituciones.map(inst => inst.trim().toUpperCase());
}

// Filtrar dropdown
if (this.hasFilterInstitutionsPermission && this.allowedInstitutions.length > 0) {
    filteredInstitutions = filteredInstitutions.filter(inst => {
        return this.allowedInstitutions.includes(inst.trim().toUpperCase());
    });
}
```

#### 3. `assets/js/estudios-manager.js`
**Función:** Gestor de estudios y derivaciones
**Modificaciones:**
- Similar a `dashboard-with-permissions.js`
- Mismas propiedades y lógica de filtrado

### Base de Datos

#### 1. `database/add_instituciones_permitidas.sql`
**Función:** Script de migración
**Contenido:**
- ALTER TABLE para agregar columna
- INSERT del permiso en system_permissions

---

## 🛠️ Implementación Paso a Paso

### Paso 1: Base de Datos

```sql
-- 1. Agregar columna a tabla usuarios
ALTER TABLE usuarios 
ADD COLUMN instituciones_permitidas JSON NULL 
COMMENT 'Array JSON con nombres de instituciones permitidas para filtrar estudios';

-- 2. Agregar permiso al sistema
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('filter_institutions', 'Filtrar por Instituciones', 
 'Permite restringir la visualización de estudios a instituciones específicas', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);
```

### Paso 2: Backend - API de Usuarios

#### 2.1 Modificar `handleGetUsers()`
```php
$query = "SELECT 
    u.id,
    u.nombre,
    // ... otros campos ...
    u.instituciones_permitidas,  // ← AGREGAR
    // ... resto de campos ...
FROM usuarios u";
```

#### 2.2 Modificar `handleUpdateUser()`
```php
// Agregar a allowedFields
$allowedFields = [
    // ... campos existentes ...
    'instituciones_permitidas'  // ← AGREGAR
];

// Agregar manejo especial
elseif ($field === 'instituciones_permitidas') {
    if ($input[$field] === null || $input[$field] === '') {
        $values[] = null;
    } elseif (is_string($input[$field])) {
        $decoded = json_decode($input[$field], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $values[] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    } elseif (is_array($input[$field])) {
        $values[] = json_encode($input[$field], JSON_UNESCAPED_UNICODE);
    }
}
```

#### 2.3 Crear `api/users/get-institutions.php`
```php
<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'user_id requerido']);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT instituciones_permitidas FROM usuarios WHERE id = ? AND activo = 1");
$stmt->execute([$user_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result && !empty($result['instituciones_permitidas'])) {
    $instituciones = json_decode($result['instituciones_permitidas'], true) ?: [];
    echo json_encode(['success' => true, 'instituciones' => $instituciones]);
} else {
    echo json_encode(['success' => true, 'instituciones' => []]);
}
?>
```

### Paso 3: Backend - API de Listado

#### 3.1 Verificar Permiso
```php
$has_filter_institutions = in_array('all', $user_permisos) || 
                          in_array('filter_institutions', $user_permisos);
$allowed_institutions = [];
```

#### 3.2 Obtener Instituciones Permitidas
```php
if ($has_filter_institutions && $user_id) {
    $stmt = $db->prepare("SELECT instituciones_permitidas FROM usuarios WHERE id = ? AND activo = 1");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['instituciones_permitidas'])) {
        $allowed_institutions = json_decode($result['instituciones_permitidas'], true) ?: [];
        $allowed_institutions = array_map(function($inst) {
            return trim(strtoupper($inst));
        }, $allowed_institutions);
    }
}
```

#### 3.3 Aplicar Filtro
```php
if ($has_filter_institutions && !empty($allowed_institutions)) {
    require_once __DIR__ . '/../OrthancClient.php';
    $orthancClient = new OrthancClient();
    $institutionCache = [];
    $filteredItems = [];
    
    foreach ($items as $item) {
        $studyId = $item['study_id'] ?? $item['study_instance_uid'] ?? null;
        
        if (!$studyId) continue;
        
        // Consultar Orthanc (con cache)
        if (!isset($institutionCache[$studyId])) {
            $studyDetails = $orthancClient->getStudyDetails($studyId);
            $institutionName = isset($studyDetails['institution_name']) 
                ? trim(strtoupper($studyDetails['institution_name'])) 
                : '';
            $institutionCache[$studyId] = $institutionName;
        }
        
        $institutionName = $institutionCache[$studyId];
        
        // Filtrar
        if (!empty($institutionName) && in_array($institutionName, $allowed_institutions)) {
            $filteredItems[] = $item;
        }
    }
    
    $items = $filteredItems;
}
```

### Paso 4: Frontend - User Management

#### 4.1 Agregar Función de Toggle
```javascript
setupInstitucionesFieldToggle() {
    const institucionesContainer = document.getElementById('institucionesContainer');
    const institucionesField = document.getElementById('instituciones_permitidas');
    const filterInstitutionsCheckbox = document.getElementById('perm_filter_institutions');
    
    if (!institucionesContainer || !institucionesField || !filterInstitutionsCheckbox) {
        return;
    }
    
    const toggleInstitucionesField = (isChecked) => {
        if (isChecked) {
            institucionesContainer.style.display = 'block';
        } else {
            institucionesContainer.style.display = 'none';
            institucionesField.value = '';
        }
    };
    
    // Agregar listener
    filterInstitutionsCheckbox.addEventListener('change', function() {
        toggleInstitucionesField(this.checked);
    });
    
    // Aplicar estado inicial
    toggleInstitucionesField(filterInstitutionsCheckbox.checked);
}
```

#### 4.2 Procesar al Guardar
```javascript
async saveUser() {
    const permissions = [];
    const permissionInputs = form.querySelectorAll('input[type="checkbox"]:checked');
    permissionInputs.forEach(input => {
        permissions.push(input.value);
    });
    
    // Procesar instituciones
    if (permissions.includes('filter_institutions')) {
        const institucionesText = formData.get('instituciones_permitidas') || '';
        if (institucionesText.trim()) {
            const instituciones = institucionesText
                .split(',')
                .map(inst => inst.trim())
                .filter(inst => inst.length > 0);
            userData.instituciones_permitidas = JSON.stringify(instituciones);
        } else {
            userData.instituciones_permitidas = JSON.stringify([]);
        }
    } else {
        userData.instituciones_permitidas = null;
    }
}
```

#### 4.3 Cargar al Editar
```javascript
async editUser(userId) {
    const user = this.users.find(u => u.id == userId);
    
    // ... llenar otros campos ...
    
    // Cargar instituciones después de mostrar modal
    const modalElement = document.getElementById('userModal');
    modalElement.addEventListener('shown.bs.modal', () => {
        setTimeout(() => {
            this.setupInstitucionesFieldToggle();
            
            const institucionesField = document.getElementById('instituciones_permitidas');
            if (user.instituciones_permitidas && institucionesField) {
                let instituciones = user.instituciones_permitidas;
                if (typeof instituciones === 'string') {
                    instituciones = JSON.parse(instituciones);
                }
                if (Array.isArray(instituciones) && instituciones.length > 0) {
                    institucionesField.value = instituciones.join(', ');
                }
            }
        }, 100);
    }, { once: true });
}
```

### Paso 5: Frontend - Filtrado en Dropdowns

#### 5.1 Obtener Instituciones Permitidas
```javascript
async checkUserPermissions() {
    // ... verificar otros permisos ...
    
    this.hasFilterInstitutionsPermission = permisos.includes('filter_institutions') || 
                                           permisos.includes('all');
    
    // Obtener instituciones permitidas
    if (this.hasFilterInstitutionsPermission && result.user.id) {
        try {
            const instResponse = await fetch(`api/users/get-institutions.php?user_id=${result.user.id}`);
            const instResult = await instResponse.json();
            if (instResult.success && instResult.instituciones) {
                this.allowedInstitutions = instResult.instituciones.map(inst => 
                    inst.trim().toUpperCase()
                );
            }
        } catch (e) {
            console.warn('Error obteniendo instituciones permitidas:', e);
        }
    }
}
```

#### 5.2 Filtrar Dropdown
```javascript
updateInstitutionFilter() {
    const institutionFilter = document.getElementById('institutionFilter');
    if (!institutionFilter) return;
    
    // Obtener todas las instituciones de los estudios
    const institutions = new Set();
    this.studies.forEach(study => {
        if (study.institution_name && study.institution_name.trim() !== '') {
            institutions.add(study.institution_name);
        }
    });
    
    // Filtrar si tiene permiso
    let filteredInstitutions = Array.from(institutions);
    if (this.hasFilterInstitutionsPermission && this.allowedInstitutions.length > 0) {
        filteredInstitutions = filteredInstitutions.filter(inst => {
            const instUpper = inst.trim().toUpperCase();
            return this.allowedInstitutions.includes(instUpper);
        });
    }
    
    // Populate dropdown
    institutionFilter.innerHTML = '<option value="">Todas las Instituciones</option>';
    filteredInstitutions.sort().forEach(institution => {
        const option = document.createElement('option');
        option.value = institution;
        option.textContent = institution;
        institutionFilter.appendChild(option);
    });
}
```

---

## 📊 Casos de Uso

### Caso 1: Usuario con Permiso Activo
**Escenario:** Usuario médico asignado a "HOSPITAL DIGITAL" y "CLINICA X"

**Comportamiento:**
- ✅ Ve solo estudios/informes de esas dos instituciones
- ✅ Dropdown de instituciones muestra solo esas opciones
- ✅ Búsquedas filtran automáticamente

### Caso 2: Usuario sin Permiso
**Escenario:** Usuario sin permiso `filter_institutions`

**Comportamiento:**
- ✅ Ve todos los estudios según otros permisos (jerarquía, asignaciones, etc.)
- ✅ Dropdown muestra todas las instituciones disponibles
- ✅ No se aplica filtro de instituciones

### Caso 3: Usuario con Permiso pero Sin Instituciones Configuradas
**Escenario:** Permiso activo pero campo `instituciones_permitidas` vacío o NULL

**Comportamiento:**
- ✅ No se aplica filtro (array vacío)
- ✅ Comportamiento similar a usuario sin permiso
- ⚠️ **Recomendación:** Validar en frontend que se configuren instituciones

---

## ⚡ Consideraciones de Rendimiento

### 1. Cache de Consultas a Orthanc
**Problema:** Consultar Orthanc para cada estudio es costoso.

**Solución Implementada:**
```php
$institutionCache = [];
foreach ($items as $item) {
    $studyId = $item['study_id'];
    if (!isset($institutionCache[$studyId])) {
        // Consultar Orthanc solo una vez por estudio
        $institutionCache[$studyId] = $orthancClient->getStudyDetails($studyId)['institution_name'];
    }
    // Usar cache en siguientes iteraciones
}
```

### 2. Optimización Futura
**Recomendación:** Agregar campo `institution_name` a tabla `informes` o `estudios` para evitar consultas a Orthanc.

```sql
ALTER TABLE informes ADD COLUMN institution_name VARCHAR(255) NULL;
ALTER TABLE estudios ADD COLUMN institution_name VARCHAR(255) NULL;
```

**Ventajas:**
- ✅ Consultas más rápidas (sin llamadas a Orthanc)
- ✅ Filtrado en SQL (más eficiente)
- ✅ Menor carga en servidor Orthanc

**Desventajas:**
- ⚠️ Requiere sincronización cuando cambia institución en Orthanc
- ⚠️ Más espacio en base de datos

### 3. Límites de Paginación
**Nota:** El filtro se aplica después de la paginación, lo que puede afectar el conteo total.

**Solución Actual:**
```php
$totalResults = count($filteredInformes); // Actualizar después del filtro
```

**Mejora Futura:** Aplicar filtro antes de paginación si se agrega `institution_name` a BD.

---

## 🔍 Validaciones y Manejo de Errores

### Validaciones Frontend
```javascript
// Validar que se ingresen instituciones si el permiso está activo
if (permissions.includes('filter_institutions')) {
    const institucionesText = formData.get('instituciones_permitidas') || '';
    if (!institucionesText.trim()) {
        // Mostrar advertencia (opcional)
        console.warn('Permiso activo pero sin instituciones configuradas');
    }
}
```

### Manejo de Errores Backend
```php
// Si falla consulta a Orthanc, continuar sin filtrar
try {
    $studyDetails = $orthancClient->getStudyDetails($studyId);
} catch (Exception $e) {
    error_log('Error obteniendo institución: ' . $e->getMessage());
    // Continuar sin filtrar este estudio (o omitirlo según política)
    continue;
}
```

### Validación de Nombres
```php
// Normalizar nombres para comparación
$allowed_institutions = array_map(function($inst) {
    return trim(strtoupper($inst));
}, $allowed_institutions);

$institutionName = trim(strtoupper($studyDetails['institution_name']));
```

---

## 📝 Notas de Implementación

### Compatibilidad
- ✅ Compatible con sistema de permisos existente
- ✅ No afecta usuarios sin el permiso
- ✅ Funciona con jerarquía de usuarios
- ✅ Compatible con asignaciones/derivaciones

### Dependencias
- OrthancClient para consultar instituciones
- Sistema de permisos (`system_permissions`)
- Autenticación de usuarios

### Testing
**Casos a probar:**
1. Usuario con permiso y instituciones configuradas
2. Usuario con permiso pero sin instituciones
3. Usuario sin permiso
4. Múltiples instituciones (separadas por comas)
5. Nombres con espacios y caracteres especiales
6. Estudios sin institución definida
7. Error en consulta a Orthanc

---

## 🔄 Migración desde Sistema Sin Filtro

### Paso 1: Backup
```sql
-- Backup de tabla usuarios
CREATE TABLE usuarios_backup AS SELECT * FROM usuarios;
```

### Paso 2: Agregar Columna
```sql
ALTER TABLE usuarios 
ADD COLUMN instituciones_permitidas JSON NULL;
```

### Paso 3: Agregar Permiso
```sql
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES ('filter_institutions', 'Filtrar por Instituciones', 
        'Permite restringir la visualización de estudios a instituciones específicas', 'estudios');
```

### Paso 4: Desplegar Código
- Actualizar archivos PHP
- Actualizar archivos JavaScript
- Limpiar caché del navegador

### Paso 5: Configurar Usuarios
- Activar permiso para usuarios que lo necesiten
- Configurar instituciones permitidas

---

## 📚 Referencias

- **Archivo de migración:** `database/add_instituciones_permitidas.sql`
- **API de usuarios:** `api/users/manage-real-complete.php`
- **API de informes:** `api/informes/list.php`
- **API de estudios:** `api/get_all_studies.php`
- **Cliente Orthanc:** `api/OrthancClient.php`
- **Frontend user-management:** `user-management-v2.js`
- **Frontend dashboard:** `assets/js/dashboard-with-permissions.js`

---

## ✅ Checklist de Implementación

- [ ] Agregar columna `instituciones_permitidas` a BD
- [ ] Agregar permiso `filter_institutions` a `system_permissions`
- [ ] Modificar API de usuarios (SELECT, INSERT, UPDATE)
- [ ] Crear endpoint `get-institutions.php`
- [ ] Modificar API de listado (informes/estudios)
- [ ] Agregar función toggle en user-management
- [ ] Procesar instituciones al guardar usuario
- [ ] Cargar instituciones al editar usuario
- [ ] Obtener instituciones en frontend (dashboard/estudios)
- [ ] Filtrar dropdowns de instituciones
- [ ] Agregar `institution_name` a `OrthancClient::getStudyDetails()`
- [ ] Testing completo
- [ ] Documentación actualizada

---

**Última actualización:** 2024
**Versión:** 1.0
**Autor:** Sistema TJSMEDICAL

