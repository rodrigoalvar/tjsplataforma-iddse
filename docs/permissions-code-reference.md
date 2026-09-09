# Referencia de Código - Sistema de Permisos

## 📁 Archivos del Sistema

### **Archivo Principal**
```
assets/js/simple-permission-manager.js
```

### **Dependencias**
```
js/auth-middleware.js
api/auth/validate-session-simple.php
```

## 🔧 Funciones Disponibles

### **Funciones Globales**

#### **`checkPermissionSimple(permission, section)`**
```javascript
// Verificación simple de permisos
const result = await checkPermissionSimple('estudios', 'Gestión Estudios');

// Resultado
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

#### **`requirePermissionSimple(permission, section, options)`**
```javascript
// Verificación con modal automático
const hasAccess = await requirePermissionSimple('estudios', 'Gestión Estudios', {
    title: 'Acceso Denegado - Gestión Estudios',
    message: 'No tienes permisos para acceder a <strong>Gestión Estudios</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});

// Retorna: boolean (true si tiene permisos, false si no)
```

### **Clase SimplePermissionManager**

#### **Métodos Principales**
```javascript
// Instancia global
const pm = window.simplePermissionManager;

// Verificar permiso
const result = await pm.checkPermission('estudios', 'Gestión Estudios');

// Verificar con modal
const hasAccess = await pm.requirePermission('estudios', 'Gestión Estudios');

// Obtener usuario actual
const user = pm.getCurrentUser();

// Obtener permisos del usuario
const permissions = pm.getUserPermissions();

// Verificación síncrona
const hasPermission = pm.hasPermissionSync('estudios');
```

## 📋 Opciones del Modal

### **Estructura de `options`**
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

### **Ejemplos de Opciones**

#### **Modal Básico**
```javascript
await requirePermissionSimple('estudios', 'Gestión Estudios');
```

#### **Modal Personalizado**
```javascript
await requirePermissionSimple('estudios', 'Gestión Estudios', {
    title: 'Acceso Restringido',
    message: 'Esta sección requiere permisos especiales.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

#### **Modal con Botones Personalizados**
```javascript
await requirePermissionSimple('estudios', 'Gestión Estudios', {
    title: 'Acceso Denegado',
    message: 'No tienes permisos para acceder a Gestión Estudios.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: false,
    customButtons: [
        {
            text: 'Volver al Dashboard',
            class: 'btn-primary',
            icon: 'fas fa-arrow-left',
            onclick: 'window.location.href="dashboard-unified.html"'
        },
        {
            text: 'Contactar Admin',
            class: 'btn-warning',
            icon: 'fas fa-envelope',
            onclick: 'window.location.href="mailto:admin@example.com"'
        }
    ]
});
```

## 🎯 Implementaciones por Sección

### **Gestión Estudios** ✅
```javascript
await requirePermissionSimple('estudios', 'Gestión Estudios', {
    title: 'Acceso Denegado - Gestión Estudios',
    message: 'No tienes permisos para acceder a <strong>Gestión Estudios</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **Gestión Informes** 🔄
```javascript
await requirePermissionSimple('informes', 'Gestión Informes', {
    title: 'Acceso Denegado - Gestión Informes',
    message: 'No tienes permisos para acceder a <strong>Gestión Informes</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **Gestión Usuarios** 🔄
```javascript
await requirePermissionSimple('usuarios', 'Gestión Usuarios', {
    title: 'Acceso Denegado - Gestión Usuarios',
    message: 'No tienes permisos para acceder a <strong>Gestión Usuarios</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **Visor DICOM** 🔄
```javascript
await requirePermissionSimple('visor', 'Visor DICOM', {
    title: 'Acceso Denegado - Visor DICOM',
    message: 'No tienes permisos para acceder a <strong>Visor DICOM</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **Gestión Plantillas** 🔄
```javascript
await requirePermissionSimple('plantillas', 'Gestión Plantillas', {
    title: 'Acceso Denegado - Gestión Plantillas',
    message: 'No tienes permisos para acceder a <strong>Gestión Plantillas</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **PACS Query** 🔄
```javascript
await requirePermissionSimple('pacs_query', 'PACS Query', {
    title: 'Acceso Denegado - PACS Query',
    message: 'No tienes permisos para acceder a <strong>PACS Query</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

## 🔍 Ejemplos Avanzados

### **Verificación Condicional**
```javascript
async function loadDashboard() {
    const hasPacsQuery = await checkPermissionSimple('pacs_query', 'PACS Query');
    const hasEstudios = await checkPermissionSimple('estudios', 'Gestión Estudios');
    
    if (hasPacsQuery.success && hasPacsQuery.hasPermission) {
        // Cargar dashboard completo con PACS
        loadPacsDashboard();
    } else if (hasEstudios.success && hasEstudios.hasPermission) {
        // Cargar dashboard limitado
        loadLimitedDashboard();
    } else {
        // Mostrar mensaje de acceso limitado
        showLimitedAccessMessage();
    }
}
```

### **Verificación para Acciones Específicas**
```javascript
async function deleteStudy(studyId) {
    const hasPermission = await requirePermissionSimple('estudios', 'Eliminar Estudio');
    if (!hasPermission) return;
    
    // Proceder con la eliminación
    if (confirm('¿Estás seguro de eliminar este estudio?')) {
        // Lógica de eliminación
        await deleteStudyFromServer(studyId);
    }
}
```

### **Verificación Múltiple**
```javascript
async function checkMultiplePermissions() {
    const estudios = await checkPermissionSimple('estudios', 'Gestión Estudios');
    const informes = await checkPermissionSimple('informes', 'Gestión Informes');
    const usuarios = await checkPermissionSimple('usuarios', 'Gestión Usuarios');
    
    console.log('Estudios:', estudios.hasPermission);
    console.log('Informes:', informes.hasPermission);
    console.log('Usuarios:', usuarios.hasPermission);
}
```

### **Verificación Síncrona**
```javascript
// Después de cargar permisos una vez
const user = window.simplePermissionManager.getCurrentUser();
if (user) {
    // Verificación síncrona (más rápida)
    const canManageStudies = window.simplePermissionManager.hasPermissionSync('estudios');
    const canManageUsers = window.simplePermissionManager.hasPermissionSync('usuarios');
    
    // Mostrar/ocultar elementos según permisos
    document.getElementById('studies-section').style.display = canManageStudies ? 'block' : 'none';
    document.getElementById('users-section').style.display = canManageUsers ? 'block' : 'none';
}
```

## 🧪 Testing y Debug

### **Comandos de Debug**
```javascript
// Verificar estado del usuario
console.log('Usuario actual:', window.simplePermissionManager.getCurrentUser());
console.log('Permisos:', window.simplePermissionManager.getUserPermissions());

// Verificar API directamente
fetch('api/auth/validate-session-simple.php')
    .then(response => response.json())
    .then(data => console.log('API Response:', data));

// Verificar permiso específico
const result = await checkPermissionSimple('estudios', 'Gestión Estudios');
console.log('Resultado:', result);
```

### **Testing de Implementación**
```javascript
// Función de prueba
async function testPermissionImplementation() {
    console.log('🧪 Probando implementación de permisos...');
    
    // Probar verificación simple
    const result = await checkPermissionSimple('estudios', 'Gestión Estudios');
    console.log('✅ Verificación simple:', result);
    
    // Probar verificación con modal
    const hasAccess = await requirePermissionSimple('estudios', 'Gestión Estudios');
    console.log('✅ Verificación con modal:', hasAccess);
    
    // Probar información del usuario
    const user = window.simplePermissionManager.getCurrentUser();
    console.log('✅ Usuario actual:', user);
    
    console.log('🎉 Pruebas completadas');
}

// Ejecutar pruebas
testPermissionImplementation();
```

## 📚 Estructura del Modal HTML

### **Modal Generado Dinámicamente**
```html
<div class="modal fade" id="permissionDeniedModal" tabindex="-1" aria-labelledby="permissionDeniedModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="permissionDeniedModalLabel">
                    <i class="fas fa-shield-alt me-2"></i>
                    Acceso Denegado
                </h5>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                </div>
                <h6 class="text-danger mb-3">Sin Permisos</h6>
                <p class="text-muted mb-3">
                    No tienes permisos para acceder a <strong>Gestión Estudios</strong>.
                </p>
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Permiso requerido:</strong> estudios
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-primary" onclick="window.location.href='dashboard-unified.html'">
                    <i class="fas fa-arrow-left me-2"></i>
                    Volver al Dashboard
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='login.html'">
                    <i class="fas fa-sign-out-alt me-2"></i>
                    Cerrar Sesión
                </button>
            </div>
        </div>
    </div>
</div>
```

## 🔧 Configuración CSS

### **Estilos del Modal**
```css
/* CSS específico para el modal de acceso denegado */
#permissionDeniedModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#permissionDeniedModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#permissionDeniedModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de acceso denegado debe aparecer sobre otros elementos */
#permissionDeniedModal.show {
    display: block !important;
}

#permissionDeniedModal.modal.show {
    display: block !important;
}
```

---

**Archivo**: `docs/permissions-code-reference.md`  
**Versión**: 1.0  
**Fecha**: 24 de octubre de 2025  
**Propósito**: Referencia rápida de código y ejemplos
