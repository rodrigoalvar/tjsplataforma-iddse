# Permisos de Asignaciones y Derivaciones

## Nuevos Permisos Agregados

Se han agregado dos nuevos permisos granulares en la categoría **Estudios**:

### 1. Asignaciones (`asignaciones`)
**Descripción:** Permite asignar, reasignar y desasignar estudios a usuarios

**Funcionalidades que controla:**
- ✅ Botón "Cambiar Asignación" en cada estudio
- ✅ Botón "Desasignar" en cada estudio  
- ✅ Botón "Asignar Seleccionados" (asignación múltiple)
- ✅ Modal de asignación de estudios

**Usuarios que deberían tener este permiso:**
- Root/Admin (ya incluido en permiso `all`)
- Coordinadores de estudios
- Personal administrativo con autorización para gestionar asignaciones

### 2. Derivaciones (`derivaciones`)
**Descripción:** Permite derivar estudios asignados a cuentas hijas

**Funcionalidades que controla:**
- ✅ Botón "Derivar" (verde) en estudios asignados al usuario
- ✅ Modal de subasignación a cuentas hijas
- ✅ Visualización de derivaciones en la columna "Derivaciones"

**Usuarios que deberían tener este permiso:**
- Root/Admin (ya incluido en permiso `all`)
- Cuentas principales/padre que necesiten derivar trabajo a sus cuentas hijas
- Supervisores con cuentas subordinadas

## Implementación en Base de Datos

Los permisos se agregaron a la tabla `system_permissions`:

```sql
-- Permiso de Asignaciones
INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES ('asignaciones', 'Asignaciones', 'Permite asignar, reasignar y desasignar estudios a usuarios', 'Estudios');

-- Permiso de Derivaciones
INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES ('derivaciones', 'Derivaciones', 'Permite derivar estudios asignados a cuentas hijas', 'Estudios');
```

## Implementación en Código

### JavaScript (`estudios-manager.js`)

Se agregaron dos flags de control:

```javascript
this.hasAsignaciones = false; // Control de permisos de Asignaciones
this.hasDerivaciones = false; // Control de permisos de Derivaciones
```

Estos flags se establecen en `checkUserPermissions()`:

```javascript
this.hasAsignaciones = permisos.includes('asignaciones') || permisos.includes('all');
this.hasDerivaciones = permisos.includes('derivaciones') || permisos.includes('all');
```

### Control de Visibilidad

**Botón "Derivar":**
```javascript
createSubassignButton(studyId, assignments) {
    // Verificar permiso de derivaciones
    if (!this.hasDerivaciones) {
        return ''; // No muestra el botón
    }
    // ... resto de validaciones
}
```

## Cómo Asignar Permisos

### 1. Acceder a Gestión de Usuarios
- Ve a `user-management.html`
- Busca el usuario al que quieres asignar permisos

### 2. Editar Permisos del Usuario
- Haz click en el botón de edición del usuario
- Busca la categoría **"Estudios"**
- Verás los siguientes permisos disponibles:
  - ☑️ Gestión de Estudios
  - ☑️ PACS Query
  - ☑️ **Asignaciones** ← NUEVO
  - ☑️ **Derivaciones** ← NUEVO

### 3. Activar los Permisos Necesarios
- Marca **"Asignaciones"** si el usuario debe poder asignar/reasignar estudios
- Marca **"Derivaciones"** si el usuario debe poder derivar estudios a cuentas hijas
- Guarda los cambios

### 4. Verificar Funcionalidad
- El usuario debe cerrar sesión y volver a iniciar
- O recargar la página `estudios-manager.html` (Ctrl+F5)
- Los botones correspondientes aparecerán según los permisos asignados

## Jerarquía de Permisos

### Permiso `all` (Root/Admin)
- ✅ Incluye automáticamente `asignaciones` y `derivaciones`
- ✅ No requiere asignación manual

### Permiso `estudios` (Gestión de Estudios)
- ❌ NO incluye automáticamente `asignaciones` ni `derivaciones`
- ℹ️ Permite acceder a `estudios-manager.html`
- ℹ️ Requiere asignación manual de `asignaciones` y/o `derivaciones` para funcionalidades específicas

## Casos de Uso

### Caso 1: Usuario Solo Visualización
**Permisos:**
- ✅ Gestión de Estudios
- ❌ Asignaciones
- ❌ Derivaciones

**Puede:**
- Ver lista de estudios
- Ver información de estudios
- Gestionar antecedentes

**No puede:**
- Asignar/reasignar estudios
- Derivar estudios

### Caso 2: Usuario Coordinador
**Permisos:**
- ✅ Gestión de Estudios
- ✅ Asignaciones
- ❌ Derivaciones

**Puede:**
- Todo lo del Caso 1
- Asignar estudios a usuarios
- Reasignar estudios
- Desasignar estudios

**No puede:**
- Derivar estudios a cuentas hijas

### Caso 3: Usuario Principal con Cuentas Hijas
**Permisos:**
- ✅ Gestión de Estudios
- ❌ Asignaciones
- ✅ Derivaciones

**Puede:**
- Todo lo del Caso 1
- Derivar estudios asignados a él a sus cuentas hijas
- Ver derivaciones realizadas

**No puede:**
- Asignar estudios a otros usuarios principales

### Caso 4: Usuario Administrador Completo
**Permisos:**
- ✅ Gestión de Estudios
- ✅ Asignaciones
- ✅ Derivaciones

**Puede:**
- Todo lo anterior
- Control total sobre asignaciones y derivaciones

## Troubleshooting

### Los botones no aparecen
**Solución:**
1. Verifica que el usuario tenga los permisos asignados en `user-management.html`
2. Cierra sesión y vuelve a iniciar
3. Limpia la caché del navegador (Ctrl+F5)
4. Verifica en la consola del navegador que los permisos se carguen correctamente

### Error: "currentUserId no está definido"
**Causa:** `checkUserPermissions()` no se ejecutó correctamente
**Solución:**
1. Verifica que la sesión del usuario esté activa
2. Recarga la página completamente
3. Verifica en consola que `checkUserPermissions()` se complete exitosamente

### El botón "Derivar" no aparece aunque tengo el permiso
**Posibles causas:**
1. El estudio no está asignado a ti
2. No tienes cuentas hijas configuradas
3. El permiso no se guardó correctamente

**Solución:**
1. Verifica que el estudio esté en la columna "Asignado a" con tu nombre
2. Verifica en `user-management.html` que tengas cuentas hijas asociadas
3. Re-asigna el permiso y recarga la página

## Resumen

| Permiso | Botones Controlados | Usuarios Típicos |
|---------|---------------------|------------------|
| `asignaciones` | Cambiar Asignación, Desasignar, Asignar Seleccionados | Coordinadores, Administrativos |
| `derivaciones` | Derivar | Cuentas principales con hijas |
| `all` | Todos | Root, Admin |

