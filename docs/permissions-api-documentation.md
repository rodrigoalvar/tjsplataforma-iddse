# Documentación - API Centralizada de Permisos

## Resumen General

Se ha creado una API centralizada y una clase JavaScript reutilizable para el manejo de permisos en todo el sistema Portal Estudios. Esta solución permite verificar permisos de manera consistente y mostrar mensajes de acceso denegado reutilizables en cualquier sección.

## Arquitectura del Sistema

### 1. **API Backend** (`api/permissions/check.php`)
- **Propósito**: Verificación centralizada de permisos
- **Método**: POST
- **Entrada**: JSON con `permission` y `section` opcional
- **Salida**: Información completa del usuario y resultado de verificación

### 2. **Clase JavaScript** (`assets/js/permission-manager.js`)
- **Propósito**: Manejo frontend de permisos
- **Instancia global**: `window.permissionManager`
- **Funciones de conveniencia**: `requirePermission()`, `checkPermission()`

## API Backend

### Endpoint: `api/permissions/check.php`

#### **Request**
```json
{
    "permission": "estudios",
    "section": "Gestión Estudios"
}
```

#### **Response (Éxito)**
```json
{
    "success": true,
    "hasPermission": true,
    "permission": "estudios",
    "section": "Gestión Estudios",
    "permissionInfo": {
        "permission_name": "Gestión de Estudios",
        "description": "Permite gestionar y asignar estudios médicos",
        "category": "estudios"
    },
    "user": {
        "id": 1,
        "nombre": "Usuario",
        "apellido": "Root",
        "email": "root@example.com",
        "nivel": "root",
        "permisos": ["all", "estudios", "pacs_query", "informes"]
    },
    "timestamp": "2025-10-24 21:30:00"
}
```

#### **Response (Error)**
```json
{
    "success": false,
    "error": "Token de sesión requerido",
    "timestamp": "2025-10-24 21:30:00"
}
```

## Clase JavaScript PermissionManager

### **Constructor**
```javascript
const permissionManager = new PermissionManager();
```

### **Métodos Principales**

#### 1. **`checkPermission(permission, section)`**
Verifica si el usuario tiene un permiso específico.

```javascript
const result = await permissionManager.checkPermission('estudios', 'Gestión Estudios');
if (result.success && result.hasPermission) {
    console.log('Usuario tiene permisos');
}
```

#### 2. **`requirePermission(permission, section, options)`**
Verifica permisos y muestra modal de acceso denegado si es necesario.

```javascript
const hasAccess = await permissionManager.requirePermission('estudios', 'Gestión Estudios', {
    title: 'Acceso Denegado',
    message: 'No tienes permisos para acceder a Gestión Estudios.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

#### 3. **`checkMultiplePermissions(permissions, section)`**
Verifica múltiples permisos simultáneamente.

```javascript
const results = await permissionManager.checkMultiplePermissions(
    ['estudios', 'pacs_query', 'usuarios'], 
    'Dashboard'
);
```

#### 4. **`hasAnyPermission(permissions, section)`**
Verifica si el usuario tiene al menos uno de los permisos especificados.

```javascript
const hasAny = await permissionManager.hasAnyPermission(
    ['estudios', 'pacs_query'], 
    'Estudios'
);
```

#### 5. **`hasAllPermissions(permissions, section)`**
Verifica si el usuario tiene todos los permisos especificados.

```javascript
const hasAll = await permissionManager.hasAllPermissions(
    ['estudios', 'usuarios'], 
    'Administración'
);
```

### **Funciones de Conveniencia Globales**

```javascript
// Verificación rápida con modal automático
await requirePermission('estudios', 'Gestión Estudios');

// Verificación simple
const result = await checkPermission('estudios', 'Gestión Estudios');
```

## Opciones del Modal de Acceso Denegado

### **Parámetros de `requirePermission()`**

```javascript
const options = {
    title: 'Acceso Denegado - Gestión Estudios',        // Título del modal
    message: 'No tienes permisos para acceder...',       // Mensaje personalizado
    redirectUrl: 'dashboard-unified.html',              // URL de redirección
    showLogoutButton: true,                             // Mostrar botón de cerrar sesión
    customButtons: [                                     // Botones personalizados
        {
            text: 'Contactar Admin',
            class: 'btn-warning',
            icon: 'fas fa-envelope',
            onclick: 'window.location.href="mailto:admin@example.com"'
        }
    ]
};
```

## Implementación en Estudios-Manager

### **Antes (Código específico)**
```javascript
// 70+ líneas de código específico para verificación de permisos
async function checkEstudiosManagerPermissions() {
    // ... código específico ...
}

function showPermissionDeniedMessage(user) {
    // ... 50+ líneas de código específico ...
}
```

### **Después (API centralizada)**
```javascript
// Solo 8 líneas usando la API centralizada
await requirePermission('estudios', 'Gestión Estudios', {
    title: 'Acceso Denegado - Gestión Estudios',
    message: 'No tienes permisos para acceder a <strong>Gestión Estudios</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

## Ventajas del Sistema Centralizado

### ✅ **Reutilización**
- **Una sola API** para todas las verificaciones de permisos
- **Una sola clase** JavaScript para manejo frontend
- **Modal reutilizable** con opciones personalizables

### ✅ **Consistencia**
- **Mismo comportamiento** en todas las secciones
- **Mismos estilos** y diseño del modal
- **Misma lógica** de verificación de permisos

### ✅ **Mantenibilidad**
- **Un solo lugar** para modificar lógica de permisos
- **Fácil actualización** de mensajes y estilos
- **Debugging centralizado**

### ✅ **Escalabilidad**
- **Fácil agregar** nuevas secciones protegidas
- **Soporte para** múltiples permisos simultáneos
- **Extensible** para nuevas funcionalidades

## Ejemplos de Uso

### **1. Proteger una página completa**
```javascript
document.addEventListener('DOMContentLoaded', async function() {
    const isAuthenticated = await requireAuth();
    if (!isAuthenticated) return;
    
    await requirePermission('estudios', 'Gestión Estudios');
});
```

### **2. Verificar permisos para una acción específica**
```javascript
async function deleteStudy(studyId) {
    const hasPermission = await requirePermission('estudios', 'Eliminar Estudio');
    if (!hasPermission) return;
    
    // Proceder con la eliminación
}
```

### **3. Verificar múltiples permisos**
```javascript
async function showAdminPanel() {
    const results = await permissionManager.checkMultiplePermissions(
        ['usuarios', 'configuracion'], 
        'Panel de Administración'
    );
    
    if (results.usuarios.hasPermission && results.configuracion.hasPermission) {
        // Mostrar panel completo
    } else if (results.usuarios.hasPermission) {
        // Mostrar solo gestión de usuarios
    } else {
        // Mostrar modal de acceso denegado
        permissionManager.showPermissionDeniedModal(results.usuarios);
    }
}
```

### **4. Verificar permisos condicionalmente**
```javascript
async function loadDashboard() {
    const hasPacsQuery = await checkPermission('pacs_query', 'PACS Query');
    const hasEstudios = await checkPermission('estudios', 'Gestión Estudios');
    
    if (hasPacsQuery.success && hasPacsQuery.hasPermission) {
        // Cargar dashboard completo con PACS
    } else if (hasEstudios.success && hasEstudios.hasPermission) {
        // Cargar dashboard limitado
    } else {
        // Mostrar mensaje de acceso limitado
    }
}
```

## Archivos Creados

### **Backend**
- **`api/permissions/check.php`** - API centralizada de verificación de permisos

### **Frontend**
- **`assets/js/permission-manager.js`** - Clase JavaScript reutilizable
- **`test-permissions-api.html`** - Página de prueba y demostración

### **Modificados**
- **`estudios-manager.html`** - Refactorizado para usar la nueva API

## Testing y Verificación

### **Página de Prueba**: `test-permissions-api.html`

**Funcionalidades de prueba**:
- ✅ Información del usuario actual
- ✅ Lista de permisos del usuario
- ✅ Pruebas individuales de permisos
- ✅ Pruebas múltiples de permisos
- ✅ Pruebas de "cualquier permiso"
- ✅ Demostración del modal de acceso denegado

### **Comandos de Debug**
```javascript
// Verificar permisos del usuario actual
const result = await checkPermission('estudios', 'Gestión Estudios');
console.log('Resultado:', result);

// Verificar múltiples permisos
const results = await permissionManager.checkMultiplePermissions(
    ['estudios', 'pacs_query', 'usuarios'], 
    'Dashboard'
);
console.log('Resultados múltiples:', results);
```

## Consideraciones de Seguridad

### **Backend**
- ✅ **Validación de sesión** en cada request
- ✅ **Verificación de token** de autenticación
- ✅ **Consulta a base de datos** para permisos actuales
- ✅ **Manejo de errores** robusto

### **Frontend**
- ⚠️ **Verificación del lado cliente** (solo para UX)
- ✅ **API backend** para seguridad real
- ✅ **Limpieza automática** de modales
- ✅ **Manejo de errores** de red

### **Recomendaciones**
- **Siempre verificar permisos** en el backend para acciones críticas
- **Usar la API centralizada** para consistencia
- **Implementar logging** de accesos denegados
- **Considerar caché** de permisos para mejor rendimiento

## Migración de Código Existente

### **Patrón de Migración**

#### **Antes**:
```javascript
// Código específico para cada sección
async function checkSectionPermissions() {
    const response = await fetch('api/auth/validate-session-simple.php');
    const result = await response.json();
    // ... lógica específica ...
}
```

#### **Después**:
```javascript
// Uso de la API centralizada
await requirePermission('section_permission', 'Section Name');
```

### **Beneficios de la Migración**
- **Reducción de código** del 80-90%
- **Consistencia** en comportamiento
- **Mantenimiento** simplificado
- **Testing** centralizado

---

**Fecha de creación**: 24 de octubre de 2025  
**Última actualización**: 24 de octubre de 2025  
**Autor**: Sistema de Documentación Portal Estudios  
**Versión**: 1.0
