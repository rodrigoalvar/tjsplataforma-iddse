# Control de Acceso en Dashboard-Unified

## Resumen del Sistema

El `dashboard-unified.html` implementa un **sistema de control de acceso basado en permisos** que determina qué funcionalidades puede usar el usuario según sus permisos asignados.

## Arquitectura del Control de Acceso

### **Componentes Principales**

#### 1. **Autenticación Base**
```javascript
// En dashboard-unified.html
document.addEventListener('DOMContentLoaded', async function() {
    const isAuthenticated = await requireAuth();
    if (!isAuthenticated) {
        return; // Redirige al login si no está autenticado
    }
});
```

#### 2. **Verificación de Permisos Específicos**
```javascript
// En dashboard-unified.html
async function checkUserPermission(permission) {
    try {
        const response = await fetch('api/users/check-permission-simple.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ permission: permission })
        });
        
        const result = await response.json();
        return result.success && result.hasPermission;
    } catch (error) {
        console.error('Error verificando permisos:', error);
        return false;
    }
}
```

#### 3. **Dashboard con Permisos Condicionales**
```javascript
// En assets/js/dashboard-with-permissions.js
class DashboardWithPermissions {
    constructor() {
        this.userPermissions = null;
        this.hasPacsQueryPermission = false;
        this.isAssignedStudiesMode = false;
    }
}
```

## Lógica de Control de Acceso

### **Flujo de Verificación de Permisos**

#### **Paso 1: Verificación de Sesión**
```javascript
async checkUserPermissions() {
    const response = await fetch('api/auth/validate-session-simple.php');
    const result = await response.json();
    
    if (result.success && result.user) {
        this.userPermissions = result.user.permisos || [];
        this.hasPacsQueryPermission = this.userPermissions.includes('pacs_query') || this.userPermissions.includes('all');
        this.isAssignedStudiesMode = !this.hasPacsQueryPermission;
    }
}
```

#### **Paso 2: Determinación del Modo de Operación**
```javascript
// Si tiene permiso 'pacs_query' o 'all'
if (this.hasPacsQueryPermission) {
    // Modo PACS Query - Acceso completo
    this.isAssignedStudiesMode = false;
} else {
    // Modo Estudios Asignados - Acceso limitado
    this.isAssignedStudiesMode = true;
}
```

#### **Paso 3: Carga de Datos Según Permisos**
```javascript
async loadStudies() {
    if (this.isAssignedStudiesMode) {
        // Cargar solo estudios asignados al usuario
        await this.loadAssignedStudies();
    } else {
        // Cargar todos los estudios desde PACS
        await this.loadPacsStudies();
    }
}
```

## Diferencias en Funcionalidad

### **Modo PACS Query** (Usuario con permiso `pacs_query` o `all`)

#### **Funcionalidades Disponibles:**
- ✅ **Consulta completa de PACS** - Acceso a todos los estudios
- ✅ **Filtros avanzados** - Por fecha, modalidad, paciente
- ✅ **Búsqueda libre** - Sin restricciones
- ✅ **Gestión completa** - Todas las operaciones disponibles

#### **API Utilizada:**
```javascript
async loadPacsStudies() {
    const url = this.apiBaseUrl + 'get_studies.php';
    // Parámetros de filtro completos
    const params = new URLSearchParams();
    if (this.currentFilters.dateFrom) {
        params.append('dateFrom', this.currentFilters.dateFrom);
    }
    // ... más parámetros
}
```

### **Modo Estudios Asignados** (Usuario sin permiso `pacs_query`)

#### **Funcionalidades Limitadas:**
- ⚠️ **Solo estudios asignados** - Acceso limitado a estudios específicos
- ⚠️ **Filtros básicos** - Funcionalidad reducida
- ⚠️ **Sin búsqueda libre** - Solo estudios del usuario
- ⚠️ **Operaciones limitadas** - Funcionalidades restringidas

#### **API Utilizada:**
```javascript
async loadAssignedStudies() {
    const url = this.apiBaseUrl + 'get_user_assigned_studies_fixed.php';
    // Sin parámetros de filtro - solo estudios asignados
}
```

## Indicadores Visuales

### **Indicador de Modo**
```javascript
updateModeIndicator() {
    const cacheStatus = document.getElementById('cacheStatus');
    if (cacheStatus) {
        const modeText = this.isAssignedStudiesMode ? 'Estudios Asignados' : 'PACS Query';
        const modeIcon = this.isAssignedStudiesMode ? 'fa-user-check' : 'fa-database';
        
        cacheStatus.innerHTML = `<i class="fas ${modeIcon} me-1"></i>Modo: ${modeText}`;
        cacheStatus.className = 'text-info';
    }
}
```

### **Mensajes de Estado**
```javascript
getInitialStatusMessage() {
    if (this.isAssignedStudiesMode) {
        return 'Mostrando estudios asignados a tu usuario';
    } else {
        return 'Mostrando estudios desde PACS';
    }
}
```

## Control de Acceso por Funcionalidad

### **Navegación y Enlaces**

#### **Verificación de Estado de Informes**
```javascript
// En dashboard-unified.html
async function updateInformesNavState() {
    if (window.URLStudyManager && window.URLStudyManager.getCurrentStudy()) {
        // Habilitar enlace de Informes si hay estudio seleccionado
        const informesLink = document.querySelector('a[href*="informes"]');
        if (informesLink) {
            informesLink.classList.remove('disabled');
        }
    }
}
```

#### **Verificación de Permisos Específicos**
```javascript
// Verificación individual de permisos
const hasEstudiosPermission = await checkUserPermission('estudios');
const hasInformesPermission = await checkUserPermission('informes');
const hasUsuariosPermission = await checkUserPermission('usuarios');
```

## APIs Utilizadas

### **APIs de Autenticación**
- **`api/auth/validate-session-simple.php`** - Verificación de sesión y permisos
- **`js/auth-middleware.js`** - Middleware de autenticación

### **APIs de Permisos**
- **`api/users/check-permission-simple.php`** - Verificación de permisos específicos

### **APIs de Datos**
- **`api/get_studies.php`** - Estudios desde PACS (modo completo)
- **`api/get_user_assigned_studies_fixed.php`** - Estudios asignados (modo limitado)

## Flujo de Inicialización

### **Secuencia de Carga**
1. **Autenticación** - Verificar sesión activa
2. **Verificación de Permisos** - Obtener permisos del usuario
3. **Determinación de Modo** - PACS Query vs Estudios Asignados
4. **Actualización de UI** - Mostrar indicadores de modo
5. **Carga de Datos** - Estudios según permisos
6. **Configuración de Funcionalidades** - Habilitar/deshabilitar según permisos

### **Código de Inicialización**
```javascript
// En dashboard-with-permissions.js
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.pathname.includes('dashboard-unified.html')) {
        function waitForGlobalStateManager() {
            if (window.globalStateManager) {
                dashboardWithPermissions = new DashboardWithPermissions();
                dashboardWithPermissions.init();
            } else {
                setTimeout(waitForGlobalStateManager, 50);
            }
        }
        setTimeout(waitForGlobalStateManager, 100);
    }
});
```

## Comparación con Simple Permission Manager

### **Dashboard-Unified (Sistema Actual)**
- ✅ **Control granular** por funcionalidad
- ✅ **Modos de operación** diferentes según permisos
- ✅ **APIs específicas** para cada modo
- ✅ **Indicadores visuales** del modo actual
- ⚠️ **Código específico** para dashboard
- ⚠️ **No reutilizable** en otras secciones

### **Simple Permission Manager (Sistema Propuesto)**
- ✅ **Reutilizable** en todas las secciones
- ✅ **Consistente** en comportamiento
- ✅ **Fácil implementación** en nuevas secciones
- ✅ **Modal de acceso denegado** profesional
- ⚠️ **Menos granular** (bloquea acceso completo)

## Recomendación de Implementación

### **Para Dashboard-Unified**
**Mantener el sistema actual** porque:
- ✅ **Funcionalidad específica** del dashboard
- ✅ **Control granular** por permisos
- ✅ **Modos de operación** diferentes
- ✅ **Ya está funcionando** correctamente

### **Para Otras Secciones**
**Usar Simple Permission Manager** porque:
- ✅ **Implementación rápida**
- ✅ **Consistencia** en el sistema
- ✅ **Mantenimiento simplificado**
- ✅ **Control de acceso** completo por sección

## Archivos del Sistema

### **Archivos Principales**
- **`dashboard-unified.html`** - Página principal del dashboard
- **`assets/js/dashboard-with-permissions.js`** - Lógica de permisos del dashboard
- **`js/auth-middleware.js`** - Middleware de autenticación

### **APIs Utilizadas**
- **`api/auth/validate-session-simple.php`** - Validación de sesión
- **`api/users/check-permission-simple.php`** - Verificación de permisos
- **`api/get_studies.php`** - Estudios desde PACS
- **`api/get_user_assigned_studies_fixed.php`** - Estudios asignados

---

**Fecha de creación**: 24 de octubre de 2025  
**Última actualización**: 24 de octubre de 2025  
**Autor**: Sistema de Documentación Portal Estudios  
**Versión**: 1.0
