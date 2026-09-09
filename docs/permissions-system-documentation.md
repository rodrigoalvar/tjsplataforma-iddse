# Documentación - Sistema de Permisos Portal Estudios

## Resumen Ejecutivo

El Sistema de Permisos Portal Estudios utiliza `simple-permission-manager.js` como solución centralizada para el control de acceso en todas las secciones del sistema. Esta implementación proporciona verificación de permisos consistente, modales de acceso denegado reutilizables y facilita el mantenimiento del sistema.

## Arquitectura del Sistema

### **Componente Principal**
- **Archivo**: `assets/js/simple-permission-manager.js`
- **Clase**: `SimplePermissionManager`
- **Instancia Global**: `window.simplePermissionManager`

### **Dependencias**
- **API Backend**: `api/auth/validate-session-simple.php`
- **Middleware**: `js/auth-middleware.js`
- **Bootstrap**: Para modales y estilos

## Implementación Estándar

### **Template HTML Básico**
```html
<!DOCTYPE html>
<html lang="es">
<head>
    <!-- ... otros head elements ... -->
    <script src="js/auth-middleware.js"></script>
    <script src="assets/js/simple-permission-manager.js"></script>
</head>
<body>
    <script>
        // Proteger página - requiere autenticación y permisos
        document.addEventListener('DOMContentLoaded', async function() {
            const isAuthenticated = await requireAuth();
            if (!isAuthenticated) {
                return; // La función requireAuth ya maneja la redirección
            }
            
            // Verificar permisos específicos para esta sección
            await requirePermissionSimple('PERMISSION_KEY', 'SECTION_NAME', {
                title: 'Acceso Denegado - SECTION_NAME',
                message: 'No tienes permisos para acceder a <strong>SECTION_NAME</strong>.',
                redirectUrl: 'dashboard-unified.html',
                showLogoutButton: true
            });
        });
    </script>
    
    <!-- ... resto del contenido ... -->
</body>
</html>
```

## API y Funciones Disponibles

### **Funciones Globales de Conveniencia**

#### **1. `checkPermissionSimple(permission, section)`**
Verifica si el usuario tiene un permiso específico.

```javascript
const result = await checkPermissionSimple('estudios', 'Gestión Estudios');
if (result.success && result.hasPermission) {
    console.log('Usuario tiene permisos');
}
```

**Parámetros:**
- `permission` (string): Clave del permiso a verificar
- `section` (string, opcional): Nombre de la sección para contexto

**Retorna:**
```javascript
{
    success: true,
    hasPermission: true,
    permission: 'estudios',
    section: 'Gestión Estudios',
    user: {
        id: 1,
        nombre: 'Usuario',
        apellido: 'Root',
        nivel: 'root',
        permisos: ['all', 'estudios', 'pacs_query']
    }
}
```

#### **2. `requirePermissionSimple(permission, section, options)`**
Verifica permisos y muestra modal de acceso denegado si es necesario.

```javascript
const hasAccess = await requirePermissionSimple('estudios', 'Gestión Estudios', {
    title: 'Acceso Denegado',
    message: 'No tienes permisos para acceder a Gestión Estudios.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

**Parámetros:**
- `permission` (string): Clave del permiso a verificar
- `section` (string, opcional): Nombre de la sección
- `options` (object, opcional): Opciones del modal

**Retorna:** `boolean` - `true` si tiene permisos, `false` si no

### **Clase SimplePermissionManager**

#### **Métodos Principales**

##### **`checkPermission(permission, section)`**
```javascript
const result = await window.simplePermissionManager.checkPermission('estudios', 'Gestión Estudios');
```

##### **`requirePermission(permission, section, options)`**
```javascript
const hasAccess = await window.simplePermissionManager.requirePermission('estudios', 'Gestión Estudios');
```

##### **`getCurrentUser()`**
```javascript
const user = window.simplePermissionManager.getCurrentUser();
```

##### **`getUserPermissions()`**
```javascript
const permissions = window.simplePermissionManager.getUserPermissions();
```

##### **`hasPermissionSync(permission)`**
```javascript
const hasPermission = window.simplePermissionManager.hasPermissionSync('estudios');
```

## Opciones del Modal de Acceso Denegado

### **Parámetros de `options`**

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

### **Estructura del Modal**
- **Header**: Título con icono de escudo
- **Body**: Icono de advertencia, mensaje y alerta informativa
- **Footer**: Botones de acción (Volver al Dashboard, Cerrar Sesión)

## Mapeo de Secciones y Permisos

### **Secciones Implementadas**

| Sección | Archivo | Permiso | Estado |
|---------|---------|---------|--------|
| **Gestión Estudios** | `estudios-manager.html` | `estudios` | ✅ **Implementado** |
| **Gestión Informes** | `components/informes-manager.html` | `gestionInformes` | ✅ **Implementado** |
| **Gestión Usuarios** | `user-management.html` | `usuarios` | ✅ **Implementado** |
| **Ver Todos Informes** | `[Pendiente Implementación]` | `verTodosInformes` | 🆕 **Nuevo Permiso** |

### **Secciones Pendientes**

| Sección | Archivo | Permiso | Prioridad |
|---------|---------|---------|-----------|
| **Visor DICOM** | `visor-dicom.html` | `visor` | 🟡 **Media** |
| **Gestión Plantillas** | `plantillas-manager.html` | `plantillas` | 🟡 **Media** |
| **PACS Query** | `pacs-query.html` | `pacs_query` | 🟡 **Media** |
| **Dashboard** | `dashboard-unified.html` | `dashboard` | 🟢 **Baja** |

### **Permisos del Sistema**

| Permiso | Descripción | Secciones |
|---------|-------------|-----------|
| `estudios` | Gestión de Estudios Médicos | Gestión Estudios |
| `informes` | Creación de Informes Médicos | Creación de Informes |
| `gestionInformes` | Gestión de Informes del Sistema | Gestión Informes |
| `verTodosInformes` | Ver Todos los Informes del Sistema | Supervisión Informes |
| `usuarios` | Gestión de Usuarios | Gestión Usuarios |
| `plantillas` | Gestión de Plantillas | Gestión Plantillas |
| `visor` | Acceso al Visor DICOM | Visor DICOM |
| `pacs_query` | Consulta PACS | PACS Query |
| `dashboard` | Acceso al Dashboard | Dashboard |
| `all` | Todos los permisos (root) | Todas las secciones |

## Implementación por Sección

### **1. Gestión Informes**
```javascript
await requirePermissionSimple('informes', 'Gestión Informes', {
    title: 'Acceso Denegado - Gestión Informes',
    message: 'No tienes permisos para acceder a <strong>Gestión Informes</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **2. Gestión Informes** ✅
```javascript
await requirePermissionSimple('gestionInformes', 'Gestión Informes', {
    title: 'Acceso Denegado - Gestión Informes',
    message: 'No tienes permisos para acceder a <strong>Gestión Informes</strong>.',
    redirectUrl: '../dashboard-unified.html',
    showLogoutButton: true
});
```

### **3. Gestión Usuarios** ✅
```javascript
await requirePermissionSimple('usuarios', 'Gestión Usuarios', {
    title: 'Acceso Denegado - Gestión Usuarios',
    message: 'No tienes permisos para acceder a <strong>Gestión Usuarios</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **4. Ver Todos Informes** 🆕
```javascript
await requirePermissionSimple('verTodosInformes', 'Ver Todos Informes', {
    title: 'Acceso Denegado - Ver Todos Informes',
    message: 'No tienes permisos para acceder a <strong>Ver Todos Informes</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **5. Visor DICOM** 🔄
```javascript
await requirePermissionSimple('visor', 'Visor DICOM', {
    title: 'Acceso Denegado - Visor DICOM',
    message: 'No tienes permisos para acceder a <strong>Visor DICOM</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **5. Gestión Plantillas** 🔄
```javascript
await requirePermissionSimple('plantillas', 'Gestión Plantillas', {
    title: 'Acceso Denegado - Gestión Plantillas',
    message: 'No tienes permisos para acceder a <strong>Gestión Plantillas</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **6. PACS Query** 🔄
```javascript
await requirePermissionSimple('pacs_query', 'PACS Query', {
    title: 'Acceso Denegado - PACS Query',
    message: 'No tienes permisos para acceder a <strong>PACS Query</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

## Plan de Implementación

### **Fase 1: Secciones Críticas** (Prioridad Alta)
1. ✅ **Gestión Estudios** - Completado
2. ✅ **Gestión Informes** - Completado
3. 🔄 **Gestión Usuarios** - Próximo

### **Fase 2: Secciones Secundarias** (Prioridad Media)
4. 🔄 **Visor DICOM**
5. 🔄 **Gestión Plantillas**
6. 🔄 **PACS Query**

### **Fase 3: Secciones Adicionales** (Prioridad Baja)
7. 🔄 **Dashboard** (si es necesario)
8. 🔄 **Otras secciones** que se creen

## Ventajas del Sistema

### **Consistencia**
- ✅ **Mismo comportamiento** en todas las secciones
- ✅ **Mismos estilos** y diseño del modal
- ✅ **Misma lógica** de verificación

### **Mantenibilidad**
- ✅ **Un solo archivo** para mantener
- ✅ **Fácil actualización** de mensajes y estilos
- ✅ **Debugging centralizado**

### **Escalabilidad**
- ✅ **Fácil agregar** nuevas secciones
- ✅ **Soporte para** múltiples permisos
- ✅ **Extensible** para nuevas funcionalidades

## Testing y Debugging

### **Archivos de Prueba**
- **`test-simple-permissions.html`** - Prueba de funcionalidad
- **`debug-permission-manager.html`** - Debug avanzado

### **Comandos de Debug**
```javascript
// Verificar permisos del usuario actual
const result = await checkPermissionSimple('estudios', 'Gestión Estudios');
console.log('Resultado:', result);

// Verificar información del usuario
const user = window.simplePermissionManager.getCurrentUser();
console.log('Usuario:', user);

// Verificar permisos del usuario
const permissions = window.simplePermissionManager.getUserPermissions();
console.log('Permisos:', permissions);
```

## Consideraciones de Seguridad

### **Frontend (UX)**
- ⚠️ **Verificación del lado cliente** (solo para experiencia de usuario)
- ✅ **API backend** para seguridad real
- ✅ **Limpieza automática** de modales
- ✅ **Manejo de errores** de red

### **Backend (Seguridad Real)**
- ✅ **Validación de sesión** en cada request
- ✅ **Verificación de token** de autenticación
- ✅ **Consulta a base de datos** para permisos actuales
- ✅ **Manejo de errores** robusto

### **Recomendaciones**
- **Siempre verificar permisos** en el backend para acciones críticas
- **Usar la API centralizada** para consistencia
- **Implementar logging** de accesos denegados
- **Considerar caché** de permisos para mejor rendimiento

## Migración de Código Existente

### **Patrón de Migración**

#### **Antes (Código específico):**
```javascript
// Código específico para cada sección
async function checkSectionPermissions() {
    const response = await fetch('api/auth/validate-session-simple.php');
    const result = await response.json();
    // ... lógica específica ...
}
```

#### **Después (API centralizada):**
```javascript
// Uso de la API centralizada
await requirePermissionSimple('section_permission', 'Section Name');
```

### **Beneficios de la Migración**
- **Reducción de código** del 80-90%
- **Consistencia** en comportamiento
- **Mantenimiento** simplificado
- **Testing** centralizado

## Archivos del Sistema

### **Archivos Principales**
- **`assets/js/simple-permission-manager.js`** - Clase principal
- **`api/auth/validate-session-simple.php`** - API de validación de sesión

### **Archivos de Prueba**
- **`test-simple-permissions.html`** - Prueba de funcionalidad
- **`debug-permission-manager.html`** - Debug avanzado

### **Archivos de Documentación**
- **`docs/permissions-system-documentation.md`** - Esta documentación
- **`docs/permissions-api-documentation.md`** - Documentación técnica de la API

## Ejemplos de Uso Avanzado

### **1. Verificación Condicional**
```javascript
async function loadDashboard() {
    const hasPacsQuery = await checkPermissionSimple('pacs_query', 'PACS Query');
    const hasEstudios = await checkPermissionSimple('estudios', 'Gestión Estudios');
    
    if (hasPacsQuery.success && hasPacsQuery.hasPermission) {
        // Cargar dashboard completo con PACS
    } else if (hasEstudios.success && hasEstudios.hasPermission) {
        // Cargar dashboard limitado
    } else {
        // Mostrar mensaje de acceso limitado
    }
}
```

### **2. Verificación para Acciones Específicas**
```javascript
async function deleteStudy(studyId) {
    const hasPermission = await requirePermissionSimple('estudios', 'Eliminar Estudio');
    if (!hasPermission) return;
    
    // Proceder con la eliminación
}
```

### **3. Modal Personalizado**
```javascript
await requirePermissionSimple('estudios', 'Gestión Estudios', {
    title: 'Acceso Restringido',
    message: 'Esta sección requiere permisos especiales.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: false,
    customButtons: [
        {
            text: 'Contactar Administrador',
            class: 'btn-warning',
            icon: 'fas fa-envelope',
            onclick: 'window.location.href="mailto:admin@example.com"'
        }
    ]
});
```

## Troubleshooting

### **Problemas Comunes**

#### **1. Modal no se muestra**
- Verificar que Bootstrap esté cargado
- Verificar que no hay errores de JavaScript
- Revisar la consola del navegador

#### **2. Permisos no se verifican correctamente**
- Verificar que `validate-session-simple.php` funciona
- Verificar que el usuario tiene los permisos correctos
- Revisar la respuesta de la API

#### **3. Redirección no funciona**
- Verificar que la URL de redirección existe
- Verificar que no hay errores de JavaScript
- Revisar la consola del navegador

### **Comandos de Debug**
```javascript
// Verificar estado del usuario
console.log('Usuario actual:', window.simplePermissionManager.getCurrentUser());
console.log('Permisos:', window.simplePermissionManager.getUserPermissions());

// Verificar API directamente
fetch('api/auth/validate-session-simple.php')
    .then(response => response.json())
    .then(data => console.log('API Response:', data));
```

---

**Fecha de creación**: 24 de octubre de 2025  
**Última actualización**: 24 de octubre de 2025  
**Autor**: Sistema de Documentación Portal Estudios  
**Versión**: 1.0  
**Estado**: Activo
