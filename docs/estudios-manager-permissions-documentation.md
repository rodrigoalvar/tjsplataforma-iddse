# Documentación - Verificación de Permisos para Gestión Estudios

## Resumen General

Se ha implementado un sistema de verificación de permisos para la sección "Gestión Estudios" (`estudios-manager.html`) que controla el acceso basado en el permiso `'estudios'` del usuario. El sistema sigue el mismo patrón utilizado en `dashboard-unified.html` para la verificación de permisos PACS QUERY.

## Problema Resuelto

### Antes
- Cualquier usuario autenticado podía acceder a `estudios-manager.html`
- No había control granular de permisos por sección
- Falta de seguridad en el acceso a funcionalidades administrativas

### Después
- Solo usuarios con permiso `'estudios'` o `'all'` pueden acceder
- Verificación automática al cargar la página
- Mensaje informativo para usuarios sin permisos
- Redirección segura a dashboard o login

## Implementación

### 1. Verificación de Permisos

**Ubicación**: `estudios-manager.html` líneas 14-23

```javascript
// Proteger página - requiere autenticación y permisos
document.addEventListener('DOMContentLoaded', async function() {
    const isAuthenticated = await requireAuth();
    if (!isAuthenticated) {
        return; // La función requireAuth ya maneja la redirección
    }
    
    // Verificar permisos específicos para Gestión Estudios
    await checkEstudiosManagerPermissions();
});
```

### 2. Función de Verificación

**Ubicación**: `estudios-manager.html` líneas 25-68

```javascript
async function checkEstudiosManagerPermissions() {
    try {
        console.log('🔍 Verificando permisos para Gestión Estudios...');
        
        // Obtener información del usuario actual
        const response = await fetch('api/auth/validate-session-simple.php');
        const result = await response.json();
        
        if (result.success && result.user) {
            const userPermissions = result.user.permisos || [];
            const hasEstudiosPermission = userPermissions.includes('estudios') || userPermissions.includes('all');
            
            if (!hasEstudiosPermission) {
                // Usuario no tiene permisos, mostrar mensaje y bloquear acceso
                showPermissionDeniedMessage(result.user);
                return false;
            }
            
            // Usuario tiene permisos, continuar normalmente
            console.log('✅ Usuario autorizado para Gestión Estudios');
            return true;
        }
        
    } catch (error) {
        console.error('🚨 Error verificando permisos:', error);
        showPermissionDeniedMessage(null);
        return false;
    }
}
```

### 3. Mensaje de Acceso Denegado

**Ubicación**: `estudios-manager.html` líneas 70-136

```javascript
function showPermissionDeniedMessage(user) {
    // Ocultar todo el contenido de la página
    document.body.innerHTML = '';
    
    // Crear mensaje de acceso denegado con Bootstrap
    const deniedMessage = `
        <div class="container-fluid vh-100 d-flex align-items-center justify-content-center bg-light">
            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-4">
                    <div class="card shadow-lg border-0">
                        <div class="card-body text-center p-5">
                            <div class="mb-4">
                                <i class="fas fa-shield-alt text-danger" style="font-size: 4rem;"></i>
                            </div>
                            <h2 class="card-title text-danger mb-3">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Acceso Denegado
                            </h2>
                            <p class="card-text text-muted mb-4">
                                ${user ? `Hola ${user.nombre} ${user.apellido || ''}` : 'Usuario'},<br>
                                No tienes permisos para acceder a la sección de <strong>Gestión Estudios</strong>.
                            </p>
                            <div class="alert alert-warning mb-4">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Permiso requerido:</strong> Gestión de Estudios<br>
                                <small>Contacta al administrador para solicitar acceso.</small>
                            </div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" onclick="window.location.href='dashboard-unified.html'">
                                    <i class="fas fa-arrow-left me-2"></i>
                                    Volver al Dashboard
                                </button>
                                <button class="btn btn-outline-secondary" onclick="window.location.href='login.html'">
                                    <i class="fas fa-sign-out-alt me-2"></i>
                                    Cerrar Sesión
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Insertar el mensaje y estilos
    document.body.innerHTML = deniedMessage;
    // ... estilos adicionales
}
```

## API Utilizada

### `api/auth/validate-session-simple.php`

**Propósito**: Validar sesión del usuario y obtener sus permisos

**Respuesta**:
```json
{
    "success": true,
    "user": {
        "id": 1,
        "nombre": "Usuario",
        "apellido": "Root",
        "nivel": "root",
        "permisos": ["all", "pacs_query", "dashboard", "estudios", "informes", "gestionInformes", "usuarios", "plantillas", "visor"]
    }
}
```

**Permisos relevantes**:
- `'estudios'`: Permiso específico para Gestión Estudios
- `'all'`: Acceso completo a todas las funcionalidades

## Flujo de Verificación

### 1. **Carga de Página**
```
estudios-manager.html carga
    ↓
requireAuth() verifica autenticación
    ↓
checkEstudiosManagerPermissions() verifica permisos
    ↓
API validate-session-simple.php devuelve permisos
    ↓
Verificar si incluye 'estudios' o 'all'
```

### 2. **Usuario CON Permisos**
```
✅ Permiso encontrado
    ↓
Continuar carga normal de la página
    ↓
Mostrar interfaz completa de Gestión Estudios
```

### 3. **Usuario SIN Permisos**
```
❌ Permiso no encontrado
    ↓
showPermissionDeniedMessage()
    ↓
Reemplazar contenido de la página
    ↓
Mostrar mensaje de acceso denegado
    ↓
Opciones: Volver al Dashboard o Cerrar Sesión
```

## Permisos del Sistema

### Niveles de Usuario

| Nivel | Permisos por Defecto | Acceso a Gestión Estudios |
|-------|---------------------|---------------------------|
| `root` | `['all']` | ✅ SÍ (permiso 'all') |
| `admin` | `['dashboard', 'estudios', 'pacs_query', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor']` | ✅ SÍ (permiso 'estudios') |
| `user` | `['dashboard', 'informes', 'grabacion']` | ❌ NO (sin permiso 'estudios') |

### Permisos Específicos

- **`'estudios'`**: Permite acceso a Gestión Estudios
- **`'all'`**: Permite acceso a todas las funcionalidades
- **`'pacs_query'`**: Permite consultar PACS directamente (usado en dashboard)

## Archivos Modificados

### 1. `estudios-manager.html`
- **Líneas 14-23**: Agregada verificación de permisos en DOMContentLoaded
- **Líneas 25-68**: Función `checkEstudiosManagerPermissions()`
- **Líneas 70-136**: Función `showPermissionDeniedMessage()`

### 2. `test-estudios-manager-permissions.html` (NUEVO)
- **Propósito**: Página de prueba para verificar funcionalidad de permisos
- **Funciones**: 
  - `loadUserInfo()`: Cargar información del usuario
  - `testEstudiosManagerAccess()`: Probar acceso a Gestión Estudios
  - `testPermissionAPI()`: Probar API de permisos
  - `showUserInfo()`: Mostrar información detallada

## Testing y Verificación

### Checklist de Verificación

- [ ] Usuario root puede acceder (permiso 'all')
- [ ] Usuario admin puede acceder (permiso 'estudios')
- [ ] Usuario user NO puede acceder (sin permiso 'estudios')
- [ ] Mensaje de acceso denegado se muestra correctamente
- [ ] Botones de navegación funcionan
- [ ] API de permisos responde correctamente
- [ ] Logs de consola muestran información útil

### Comandos de Debug

```javascript
// Verificar permisos del usuario actual
fetch('api/auth/validate-session-simple.php')
    .then(response => response.json())
    .then(data => console.log('Permisos:', data.user.permisos));

// Verificar si tiene permiso específico
const hasEstudios = userPermissions.includes('estudios') || userPermissions.includes('all');
console.log('Tiene permiso estudios:', hasEstudios);
```

### Página de Prueba

**URL**: `test-estudios-manager-permissions.html`

**Funcionalidades**:
- Muestra información del usuario actual
- Lista todos los permisos
- Indica si puede acceder a Gestión Estudios
- Botones para probar acceso y API
- Información detallada del usuario

## Consideraciones de Seguridad

### 1. **Verificación del Lado Cliente**
- La verificación se hace en JavaScript (lado cliente)
- **Importante**: Esto es solo para UX, no para seguridad real
- La seguridad real debe implementarse en el servidor

### 2. **API de Permisos**
- La API `validate-session-simple.php` valida la sesión del servidor
- Los permisos se obtienen de la base de datos
- El token de sesión se verifica en cada request

### 3. **Recomendaciones Futuras**
- Implementar verificación de permisos en todas las APIs del servidor
- Agregar middleware de permisos en PHP
- Implementar logging de accesos denegados
- Considerar verificación adicional en cada acción crítica

## Patrón para Otras Secciones

### Plantilla para Nuevas Secciones

```javascript
// 1. Agregar verificación en DOMContentLoaded
document.addEventListener('DOMContentLoaded', async function() {
    const isAuthenticated = await requireAuth();
    if (!isAuthenticated) return;
    
    await checkSectionPermissions('nombre_permiso');
});

// 2. Función de verificación genérica
async function checkSectionPermissions(requiredPermission) {
    try {
        const response = await fetch('api/auth/validate-session-simple.php');
        const result = await response.json();
        
        if (result.success && result.user) {
            const permissions = result.user.permisos || [];
            const hasPermission = permissions.includes(requiredPermission) || permissions.includes('all');
            
            if (!hasPermission) {
                showPermissionDeniedMessage(result.user, requiredPermission);
                return false;
            }
            
            return true;
        }
    } catch (error) {
        console.error('Error verificando permisos:', error);
        showPermissionDeniedMessage(null, requiredPermission);
        return false;
    }
}
```

## Referencias

- **Dashboard con Permisos**: `assets/js/dashboard-with-permissions.js`
- **API de Validación**: `api/auth/validate-session-simple.php`
- **API de Permisos**: `api/users/permissions-simple.php`
- **Middleware de Auth**: `js/auth-middleware.js`

---

**Fecha de creación**: 24 de octubre de 2025  
**Última actualización**: 24 de octubre de 2025  
**Autor**: Sistema de Documentación Portal Estudios  
**Versión**: 1.0
