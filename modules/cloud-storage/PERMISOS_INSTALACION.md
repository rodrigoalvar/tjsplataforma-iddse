# 🔐 Instalación de Permisos - Cloud Storage

## 📋 Resumen

Para que Cloud Storage funcione correctamente con el sistema de permisos, es necesario:

1. ✅ Instalar los permisos en la base de datos
2. ✅ Asignar permisos a usuarios desde Gestión de Usuarios
3. ✅ Verificar que el sidebar muestre/oculte el enlace según permisos

---

## 🚀 Instalación de Permisos

### Opción 1: Script Web (Recomendado)

1. Accede al script de instalación desde tu navegador:
   ```
   https://plataforma.iddse.com.ar/modules/cloud-storage/install-permissions.php
   ```

2. El script:
   - Verificará si los permisos ya existen
   - Creará o actualizará los permisos necesarios
   - Mostrará un resumen de lo realizado

### Opción 2: SQL Directo

Si prefieres ejecutar SQL directamente:

```sql
INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES 
('cloud_storage', 'Cloud Storage', 'Permite acceder y gestionar el almacenamiento en Cloudflare R2', 'pacs'),
('gui_cloud_storage', 'Cloud Storage (GUI)', 'Muestra el enlace de Cloud Storage en el sidebar', 'gui')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);
```

---

## 📝 Permisos Creados

### 1. `cloud_storage`
- **Tipo**: Permiso funcional
- **Categoría**: `pacs`
- **Descripción**: Permite acceder y gestionar el almacenamiento en Cloudflare R2
- **Uso**: Controla el acceso a la funcionalidad de Cloud Storage

### 2. `gui_cloud_storage`
- **Tipo**: Permiso GUI
- **Categoría**: `gui`
- **Descripción**: Muestra el enlace de Cloud Storage en el sidebar
- **Uso**: Controla la visibilidad del enlace en el sidebar

---

## ✅ Verificación

### 1. Verificar que los permisos existen

```sql
SELECT permission_key, permission_name, category 
FROM system_permissions 
WHERE permission_key IN ('cloud_storage', 'gui_cloud_storage');
```

### 2. Asignar permisos a usuarios

1. Ir a **Gestión de Usuarios** (`user-management.html`)
2. Editar un usuario
3. Buscar los permisos:
   - `Cloud Storage` (permiso funcional)
   - `Cloud Storage (GUI)` (permiso GUI)
4. Marcar los checkboxes correspondientes
5. Guardar

### 3. Verificar funcionamiento

- **Con permiso `gui_cloud_storage`**: El enlace "Cloud Storage" aparece en el sidebar
- **Sin permiso `gui_cloud_storage`**: El enlace NO aparece en el sidebar
- **Con permiso `cloud_storage`**: El usuario puede acceder a Cloud Storage
- **Sin permiso `cloud_storage`**: El usuario ve mensaje de acceso denegado

---

## 🔧 Configuración Actual

### En `cloud-storage.html`
- Verifica el permiso: `cloud_storage`
- Si no tiene permiso, muestra mensaje de acceso denegado

### En `sidebar-gui-manager.js`
- Mapeo del permiso: `gui_cloud_storage`
- Si el usuario tiene este permiso, muestra el enlace en el sidebar

---

## 📌 Notas Importantes

1. **Dos permisos diferentes**:
   - `cloud_storage`: Controla el acceso funcional
   - `gui_cloud_storage`: Controla la visibilidad en el sidebar

2. **Recomendación**: Asignar ambos permisos a los usuarios que necesiten acceder a Cloud Storage

3. **Usuarios ROOT**: Los usuarios con nivel `root` tienen acceso automático a todo, no necesitan permisos explícitos

---

## 🐛 Solución de Problemas

### El enlace no aparece en el sidebar
- Verificar que el usuario tenga el permiso `gui_cloud_storage`
- Recargar la página después de asignar permisos
- Verificar que `sidebar-gui-manager.js` esté cargado

### No puedo acceder a Cloud Storage
- Verificar que el usuario tenga el permiso `cloud_storage`
- Verificar que la sesión esté activa
- Revisar la consola del navegador para errores

### Los permisos no aparecen en Gestión de Usuarios
- Ejecutar el script `install-permissions.php`
- Verificar que los permisos existan en la tabla `system_permissions`
- Recargar la página de Gestión de Usuarios

---

**✅ Una vez instalados los permisos, Cloud Storage funcionará correctamente con el sistema de permisos del sistema.**
