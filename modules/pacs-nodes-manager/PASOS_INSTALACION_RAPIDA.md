# 🚀 Pasos de Instalación Rápida - PACS NODES MANAGER

**Fecha**: 2026-03-06

---

## ⚡ Solución al Problema de Permisos y Sidebar

### 🔧 Paso 1: Instalar Permisos en la Base de Datos

**Acceda desde su navegador a:**
```
http://tu-dominio.com/modules/pacs-nodes-manager/install-permissions.php
```

Este script creará/actualizará los permisos necesarios:
- ✅ `pacs_nodes_manager` (categoría: `pacs`)
- ✅ `gui_pacs_nodes_manager` (categoría: `gui`)

**O ejecute manualmente en MySQL:**
```sql
INSERT IGNORE INTO `system_permissions` (`permission_key`, `permission_name`, `permission_description`, `category`, `created_at`) VALUES
('pacs_nodes_manager', 'Gestionar Nodos PACS', 'Permite gestionar nodos PACS remotos y realizar operaciones C-FIND, C-MOVE, C-GET', 'pacs', NOW()),
('gui_pacs_nodes_manager', 'Ver PACS Nodes Manager', 'Permite ver y acceder al módulo PACS Nodes Manager en el sidebar', 'gui', NOW());
```

---

### 👤 Paso 2: Asignar Permisos a Usuarios

1. **Acceda a Gestión de Usuarios:**
   ```
   http://tu-dominio.com/user-management.html
   ```

2. **Seleccione el usuario** al que desea dar acceso

3. **Busque los permisos** en las categorías:
   - **Categoría "PACS"**: Busque `pacs_nodes_manager` (Gestionar Nodos PACS)
   - **Categoría "GUI"**: Busque `gui_pacs_nodes_manager` (Ver PACS Nodes Manager)

4. **Marque ambos checkboxes** ✅

5. **Guarde los cambios**

---

### 🔄 Paso 3: Recargar y Verificar

1. **Recargue la página** de Gestión de Usuarios (F5)
   - Los permisos deberían aparecer ahora

2. **Recargue el Dashboard** (Ctrl+Shift+R para limpiar caché)
   - El enlace "PACS Nodes Manager" debería aparecer en el sidebar

3. **Haga clic en "PACS Nodes Manager"** en el sidebar
   - Debe abrir la interfaz del módulo

---

## ✅ Verificación Final

### Verificar Permisos en BD:
```sql
SELECT permission_key, permission_name, category 
FROM system_permissions 
WHERE permission_key LIKE 'pacs%';
```

### Verificar Permisos de Usuario:
```sql
SELECT up.*, sp.permission_name 
FROM user_permissions up
JOIN system_permissions sp ON up.permission_key = sp.permission_key
WHERE up.user_id = [TU_USER_ID]
  AND up.permission_key LIKE 'pacs%';
```

### Verificar Archivos:
```bash
# Verificar que el HTML existe
ls -la /var/www/tjsiddse/pacs-nodes-manager.html

# Verificar que los permisos están en el sidebar
grep -n "pacs-nodes-manager" /var/www/tjsiddse/dashboard-unified.html
```

---

## 🐛 Si Aún No Funciona

### Los permisos no aparecen en user-management:

1. **Ejecute el script de instalación completo:**
   ```
   http://tu-dominio.com/modules/pacs-nodes-manager/install.php
   ```

2. **Verifique que la tabla `system_permissions` existe:**
   ```sql
   SHOW TABLES LIKE 'system_permissions';
   ```

3. **Verifique las categorías:**
   ```sql
   SELECT DISTINCT category FROM system_permissions;
   ```
   Debe incluir `pacs` y `gui`

### El sidebar no muestra el enlace:

1. **Verifique que el permiso esté asignado:**
   - El usuario debe tener `gui_pacs_nodes_manager` asignado

2. **Limpie la caché del navegador:**
   - Ctrl+Shift+R (Chrome/Firefox)
   - O borre la caché manualmente

3. **Verifique la consola del navegador:**
   - F12 → Console
   - Busque errores relacionados con `sidebar-gui-manager.js`

4. **Verifique que el enlace HTML existe:**
   - Abra `dashboard-unified.html`
   - Busque "PACS Nodes Manager"
   - Debe estar después de "PACS Manager"

---

## 📋 Checklist de Instalación

- [ ] Permisos creados en `system_permissions`
- [ ] Permisos asignados a usuario en `user_permissions`
- [ ] Enlace agregado en `dashboard-unified.html`
- [ ] Mapeo agregado en `sidebar-gui-manager.js`
- [ ] Mapeo agregado en `app-container.html`
- [ ] Archivo `pacs-nodes-manager.html` existe en raíz
- [ ] Usuario recargó la página después de asignar permisos

---

## 🎯 Resultado Esperado

Después de completar estos pasos:

1. ✅ Los permisos aparecen en **Gestión de Usuarios**
2. ✅ El enlace **"PACS Nodes Manager"** aparece en el **sidebar**
3. ✅ Al hacer clic, se abre la interfaz del módulo
4. ✅ Se pueden crear y gestionar nodos PACS

---

**¿Problemas?** Consulte `SOLUCION_PERMISOS_SIDEBAR.md` para más detalles.
