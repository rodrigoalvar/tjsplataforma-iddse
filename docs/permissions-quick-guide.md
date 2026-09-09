# Guía Rápida - Implementación de Permisos

## 🚀 Implementación en 3 Pasos

### **Paso 1: Incluir Scripts**
```html
<script src="js/auth-middleware.js"></script>
<script src="assets/js/simple-permission-manager.js"></script>
```

### **Paso 2: Agregar Verificación de Permisos**
```javascript
document.addEventListener('DOMContentLoaded', async function() {
    const isAuthenticated = await requireAuth();
    if (!isAuthenticated) return;
    
    const hasAccess = await requirePermissionSimple('PERMISSION_KEY', 'SECTION_NAME', {
        title: 'Acceso Denegado - SECTION_NAME',
        message: 'No tienes permisos para acceder a <strong>SECTION_NAME</strong>.',
        redirectUrl: 'dashboard-unified.html',
        showLogoutButton: true
    });
    
    // Si no tiene permisos, no continuar con el resto del código
    if (!hasAccess) {
        return;
    }
});
```

### **Paso 3: Reemplazar Variables**
- `PERMISSION_KEY` → Clave del permiso (ej: `estudios`, `informes`, `usuarios`)
- `SECTION_NAME` → Nombre de la sección (ej: `Gestión Estudios`, `Gestión Informes`)

## 📋 Mapeo Rápido de Permisos

| Sección | Permiso | Ejemplo |
|---------|---------|---------|
| Gestión Estudios | `estudios` | ✅ Implementado |
| Gestión Informes | `gestionInformes` | ✅ Implementado |
| Gestión Usuarios | `usuarios` | ✅ Implementado |
| Visor DICOM | `visor` | 🔄 Pendiente |
| Gestión Plantillas | `plantillas` | 🔄 Pendiente |
| PACS Query | `pacs_query` | 🔄 Pendiente |

## 🔧 Ejemplos de Implementación

### **Gestión Informes**
```javascript
await requirePermissionSimple('informes', 'Gestión Informes', {
    title: 'Acceso Denegado - Gestión Informes',
    message: 'No tienes permisos para acceder a <strong>Gestión Informes</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

### **Gestión Usuarios** ✅
```javascript
// En user-management.html
document.addEventListener('DOMContentLoaded', async function() {
    const isAuthenticated = await requireAuth();
    if (!isAuthenticated) {
        return;
    }
    
    const hasAccess = await requirePermissionSimple('usuarios', 'Gestión Usuarios', {
        title: 'Acceso Denegado - Gestión Usuarios',
        message: 'No tienes permisos para acceder a <strong>Gestión Usuarios</strong>.',
        redirectUrl: 'dashboard-unified.html',
        showLogoutButton: true
    });
    
    if (!hasAccess) {
        return;
    }
    
    // Inicializar la aplicación
    initializeUserManagement();
});
```

### **Visor DICOM**
```javascript
await requirePermissionSimple('visor', 'Visor DICOM', {
    title: 'Acceso Denegado - Visor DICOM',
    message: 'No tienes permisos para acceder a <strong>Visor DICOM</strong>.',
    redirectUrl: 'dashboard-unified.html',
    showLogoutButton: true
});
```

## 🧪 Testing

### **Páginas de Prueba Disponibles**
- **`test-simple-permissions.html`** - Pruebas generales del sistema
- **`test-estudios-manager-permissions.html`** - Pruebas específicas para Gestión Estudios
- **`test-informes-permissions.html`** - Pruebas específicas para Gestión Informes
- **`test-user-management-permissions.html`** - Pruebas específicas para Gestión Usuarios

### **Páginas de Debug Disponibles**
- **`debug-permission-manager.html`** - Debug general del sistema
- **`debug-informes-permissions.html`** - Debug específico para Gestión Informes
- **`debug-user-management-permissions.html`** - Debug específico para Gestión Usuarios

### **Verificar Implementación**
1. Abrir la sección protegida
2. Si no tienes permisos → Modal de acceso denegado
3. Si tienes permisos → Acceso normal

### **Debug**
```javascript
// Verificar permisos del usuario
const result = await checkPermissionSimple('estudios', 'Gestión Estudios');
console.log('Resultado:', result);

// Verificar información del usuario
const user = window.simplePermissionManager.getCurrentUser();
console.log('Usuario:', user);
```

## 📚 Documentación Completa

- **`docs/permissions-system-documentation.md`** - Documentación técnica completa
- **`test-simple-permissions.html`** - Página de prueba
- **`debug-permission-manager.html`** - Herramientas de debug

## ⚡ Template Completo

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SECTION_NAME - Portal de Estudios Médicos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
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
        const hasAccess = await requirePermissionSimple('PERMISSION_KEY', 'SECTION_NAME', {
            title: 'Acceso Denegado - SECTION_NAME',
            message: 'No tienes permisos para acceder a <strong>SECTION_NAME</strong>.',
            redirectUrl: 'dashboard-unified.html',
            showLogoutButton: true
        });
        
        // Si no tiene permisos, no continuar con el resto del código
        if (!hasAccess) {
            return;
        }
        });
    </script>
    
    <!-- Contenido de la página -->
    <div class="container">
        <h1>SECTION_NAME</h1>
        <!-- ... resto del contenido ... -->
    </div>
</body>
</html>
```

## 🎯 Checklist de Implementación

- [ ] Incluir scripts necesarios
- [ ] Agregar verificación de autenticación
- [ ] Agregar verificación de permisos
- [ ] Reemplazar variables (PERMISSION_KEY, SECTION_NAME)
- [ ] Probar con usuario sin permisos
- [ ] Probar con usuario con permisos
- [ ] Verificar redirección del modal
- [ ] Documentar en la lista de secciones implementadas

---

**Archivo**: `docs/permissions-quick-guide.md`  
**Versión**: 1.0  
**Fecha**: 24 de octubre de 2025
