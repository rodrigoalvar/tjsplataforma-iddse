# Permiso "Gestión de Plantillas" (plantillas)

## Resumen
El permiso `plantillas` (también conocido como "Gestión de Plantillas") controla el acceso completo al sistema de gestión de plantillas de informes médicos.

## ¿Qué permite este permiso?

### ✅ **CON Permiso "Gestión de Plantillas" (`plantillas` o `all`):**

1. **Ver TODAS las plantillas del sistema**
   - Puede ver plantillas creadas por cualquier usuario
   - Puede ver plantillas del sistema (usuario_id IS NULL)
   - Endpoint: `api/plantillas/list.php`

2. **Editar cualquier plantilla**
   - Puede editar plantillas creadas por otros usuarios
   - Puede editar plantillas del sistema
   - Endpoint: `api/plantillas/save.php` (UPDATE)

3. **Eliminar cualquier plantilla**
   - Puede eliminar (soft delete) plantillas de otros usuarios
   - Puede eliminar plantillas del sistema
   - Endpoint: `api/plantillas/delete.php`

4. **Acceder al Gestor de Plantillas**
   - Puede abrir el modal "Gestión de Plantillas"
   - El botón "Gestionar" se muestra en el selector de plantillas
   - Función: `openTemplateManager()` en `informes-manager.js`

5. **Ver y gestionar plantillas de todos los usuarios**
   - Control total sobre el sistema de plantillas

---

### ❌ **SIN Permiso "Gestión de Plantillas":**

1. **Solo puede ver SUS PROPIAS plantillas**
   - No puede ver plantillas creadas por otros usuarios
   - No puede ver plantillas del sistema
   - Endpoint: `api/plantillas/list.php` filtra por `usuario_id = su_id`

2. **Solo puede editar SUS PROPIAS plantillas**
   - No puede editar plantillas de otros usuarios
   - No puede editar plantillas del sistema
   - Endpoint: `api/plantillas/save.php` (UPDATE) requiere ser dueño

3. **Solo puede eliminar SUS PROPIAS plantillas**
   - No puede eliminar plantillas de otros usuarios
   - No puede eliminar plantillas del sistema
   - Endpoint: `api/plantillas/delete.php` requiere ser dueño

4. **NO puede acceder al Gestor de Plantillas**
   - El botón "Gestionar" está OCULTO en el selector de plantillas
   - Si intenta abrir el gestor, se muestra un error de permisos
   - Función: `openTemplateManager()` rechaza el acceso

5. **Puede crear sus propias plantillas**
   - ✅ **IMPORTANTE**: Cualquier usuario autenticado puede crear sus propias plantillas
   - No requiere el permiso "Gestión de Plantillas" para crear
   - Endpoint: `api/plantillas/save.php` (INSERT) permite a todos los usuarios autenticados

---

## Tabla de Resumen de Permisos

| Acción | Sin Permiso `plantillas` | Con Permiso `plantillas` |
|--------|-------------------------|--------------------------|
| **Ver plantillas** | Solo propias | Todas las plantillas |
| **Crear plantillas** | ✅ Sí (sus propias) | ✅ Sí (sus propias) |
| **Editar plantillas** | Solo propias | Cualquier plantilla |
| **Eliminar plantillas** | Solo propias | Cualquier plantilla |
| **Acceder al Gestor** | ❌ No | ✅ Sí |
| **Botón "Gestionar"** | ❌ Oculto | ✅ Visible |

---

## Ubicación del Permiso en la Base de Datos

- **Tabla**: `system_permissions`
- **Clave**: `plantillas`
- **Nombre**: "Gestión de Plantillas"
- **Categoría**: `plantillas`
- **Descripción**: "Permite gestionar plantillas de informes"

---

## Verificación del Permiso en el Código

### Backend (PHP):
- `api/plantillas/list.php`: Verifica `plantillas` o `all` para mostrar todas las plantillas
- `api/plantillas/save.php`: Verifica `plantillas` o `all` para editar plantillas de otros
- `api/plantillas/delete.php`: Verifica `plantillas` o `all` para eliminar plantillas de otros

### Frontend (JavaScript):
- `assets/js/informes-manager.js`: 
  - `checkPlantillasPermission()`: Verifica el permiso
  - `openTemplateManager()`: Requiere el permiso para abrir el gestor
- `assets/js/template-manager-module.js`:
  - `checkAndShowManageButton()`: Muestra/oculta el botón "Gestionar" según el permiso

---

## Notas Importantes

1. **Crear plantillas NO requiere el permiso**: Todos los usuarios autenticados pueden crear sus propias plantillas, independientemente de tener el permiso "Gestión de Plantillas".

2. **El permiso permite gestión de plantillas de OTROS usuarios**: Es un permiso administrativo que permite gestionar todas las plantillas del sistema, no solo las propias.

3. **El permiso `all` también otorga estos permisos**: Los usuarios con permiso `all` tienen automáticamente todos los permisos, incluyendo "Gestión de Plantillas".

