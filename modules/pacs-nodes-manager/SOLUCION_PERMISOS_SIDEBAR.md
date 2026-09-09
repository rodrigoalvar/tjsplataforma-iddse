# 🔧 Solución: Permisos y Sidebar no Visibles

**Problema**: Los permisos no aparecen en user-management y el enlace no aparece en el sidebar.

---

## ✅ Solución Rápida

### Paso 1: Instalar Permisos

Acceda desde su navegador a:
```
http://tu-dominio.com/modules/pacs-nodes-manager/install-permissions.php
```

Este script:
- ✅ Verifica si los permisos existen
- ✅ Los crea si no existen
- ✅ Los actualiza si ya existen pero con datos incorrectos

### Paso 2: Verificar Permisos en Base de Datos

Ejecute en MySQL:
```sql
SELECT permission_key, permission_name, category 
FROM system_permissions 
WHERE permission_key IN ('pacs_nodes_manager', 'gui_pacs_nodes_manager');
```

Debe mostrar 2 filas:
- `pacs_nodes_manager` - Categoría: `pacs`
- `gui_pacs_nodes_manager` - Categoría: `gui`

### Paso 3: Recargar Gestión de Usuarios

1. Cierre y vuelva a abrir `user-management.html`
2. Los permisos deberían aparecer en las categorías:
   - **`pacs_nodes_manager`** en la categoría "PACS"
   - **`gui_pacs_nodes_manager`** en la categoría "GUI"

### Paso 4: Asignar Permisos

1. Seleccione un usuario
2. Marque los checkboxes:
   - ✅ `pacs_nodes_manager`
   - ✅ `gui_pacs_nodes_manager`
3. Guarde los cambios

### Paso 5: Verificar Sidebar

1. Recargue la página (F5 o Ctrl+R)
2. El enlace **"PACS Nodes Manager"** debería aparecer en el sidebar
3. Si no aparece, limpie la caché del navegador (Ctrl+Shift+R)

---

## 🔍 Verificación Manual

### Verificar que el enlace está en el HTML

El enlace ya está agregado en:
- ✅ `dashboard-unified.html` (línea ~385)
- ✅ `app-container.html` (mapeo de secciones)
- ✅ `assets/js/sidebar-gui-manager.js` (mapeo de permisos)

### Verificar permisos con SQL

```sql
-- Ver todos los permisos del sistema
SELECT permission_key, permission_name, category 
FROM system_permissions 
ORDER BY category, permission_name;

-- Ver permisos de un usuario específico
SELECT up.*, sp.permission_name, sp.category
FROM user_permissions up
JOIN system_permissions sp ON up.permission_key = sp.permission_key
WHERE up.user_id = [ID_USUARIO]
  AND up.permission_key LIKE 'pacs%';
```

---

## 🐛 Si Aún No Funciona

### Problema: Permisos no aparecen en user-management

**Causa posible**: La categoría no está reconocida

**Solución**: Verificar que las categorías existan:
```sql
SELECT DISTINCT category FROM system_permissions;
```

Si no existe la categoría `pacs` o `gui`, los permisos pueden no mostrarse correctamente.

### Problema: Sidebar no muestra el enlace

**Causa posible**: El permiso `gui_pacs_nodes_manager` no está asignado

**Solución**:
1. Verificar que el permiso esté asignado al usuario
2. Verificar en la consola del navegador si hay errores JavaScript
3. Verificar que `sidebar-gui-manager.js` esté cargado

### Problema: Error 404 al hacer clic

**Causa posible**: El archivo HTML no está en la ubicación correcta

**Solución**: Verificar que `pacs-nodes-manager.html` esté en la raíz:
```bash
ls -la /var/www/tjsiddse/pacs-nodes-manager.html
```

---

## 📝 Notas

- Los permisos se agrupan por categoría en user-management
- El sidebar se controla mediante `sidebar-gui-manager.js`
- El enlace HTML debe existir en `dashboard-unified.html` para que se muestre
- Los permisos GUI controlan la visibilidad, los permisos funcionales controlan el acceso a las APIs

---

**Si después de seguir estos pasos aún no funciona, ejecute el script de instalación completo:**
```
http://tu-dominio.com/modules/pacs-nodes-manager/install.php
```
