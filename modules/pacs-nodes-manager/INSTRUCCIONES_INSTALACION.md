# 📋 Instrucciones de Instalación - PACS NODES MANAGER

**Versión**: 1.0.0  
**Fecha**: 2026-03-06  
**Sistema**: TJSMEDICAL

---

## 🎯 Requisitos Previos

Antes de instalar el módulo, asegúrese de tener:

- ✅ PHP 7.4 o superior
- ✅ MySQL/MariaDB 5.7+ con soporte JSON
- ✅ Extensiones PHP: `pdo`, `pdo_mysql`, `curl`, `json`, `openssl`
- ✅ Servidor Orthanc configurado y accesible
- ✅ Permisos de escritura en el directorio del módulo
- ✅ Acceso a la base de datos del sistema

---

## 📦 Paso 1: Verificar Estructura del Módulo

Verifique que todos los archivos estén presentes:

```bash
cd /var/www/tjsiddse/modules/pacs-nodes-manager
ls -la
```

Debe ver:
- `PacsNodeConfig.php`
- `PacsNodeClient.php`
- `install.php`
- `database/install.sql`
- `api/` (con todos los endpoints)
- `assets/js/pacs-nodes-manager.js`
- `assets/css/pacs-nodes-manager.css`

---

## 🗄️ Paso 2: Ejecutar el Instalador

### Opción A: Instalador Web (Recomendado)

1. Abra su navegador y acceda a:
   ```
   http://tu-dominio.com/modules/pacs-nodes-manager/install.php
   ```

2. El instalador verificará automáticamente:
   - ✅ Versión de PHP
   - ✅ Extensiones requeridas
   - ✅ Conexión a base de datos
   - ✅ Creación de tablas
   - ✅ Creación de permisos
   - ✅ Archivos del módulo

3. Si todo está correcto, verá un mensaje de "Instalación Completada"

### Opción B: Instalación Manual

Si prefiere instalar manualmente:

```bash
# 1. Crear tablas en la base de datos
mysql -u usuario -p nombre_base_datos < modules/pacs-nodes-manager/database/install.sql

# 2. Verificar que las tablas se crearon
mysql -u usuario -p nombre_base_datos -e "SHOW TABLES LIKE 'pacs_%';"
```

Debe ver 4 tablas:
- `pacs_nodes`
- `pacs_node_queries`
- `pacs_node_jobs`
- `pacs_node_statistics`

---

## 🔐 Paso 3: Configurar Permisos

### Asignar Permisos a Usuarios

1. Acceda a **Gestión de Usuarios** (`user-management.html`)

2. Seleccione el usuario al que desea dar acceso

3. Asigne los siguientes permisos:
   - ✅ **`pacs_nodes_manager`** - Permiso funcional (requerido para operaciones)
   - ✅ **`gui_pacs_nodes_manager`** - Permiso de interfaz (requerido para ver en sidebar)

4. Guarde los cambios

### Verificar Permisos en Base de Datos

```sql
-- Verificar que los permisos existen
SELECT * FROM system_permissions 
WHERE permission_key IN ('pacs_nodes_manager', 'gui_pacs_nodes_manager');

-- Verificar permisos de un usuario específico
SELECT up.*, sp.permission_name 
FROM user_permissions up
JOIN system_permissions sp ON up.permission_key = sp.permission_key
WHERE up.user_id = [ID_USUARIO]
  AND up.permission_key IN ('pacs_nodes_manager', 'gui_pacs_nodes_manager');
```

---

## 🔧 Paso 4: Configurar Integración con Sidebar

El módulo debe aparecer automáticamente en el sidebar si:
- ✅ El permiso `gui_pacs_nodes_manager` está asignado al usuario
- ✅ El archivo `sidebar-gui-manager.js` está cargado

Si no aparece, verifique que el mapeo esté en `assets/js/sidebar-gui-manager.js`:

```javascript
'gui_pacs_nodes_manager': {
    selectors: ['a[href*="pacs-nodes-manager.html"]'],
    text: 'PACS Nodes Manager',
    hrefPatterns: ['pacs-nodes-manager.html']
}
```

---

## ✅ Paso 5: Verificar Instalación

### 5.1 Verificar Endpoints API

Pruebe los endpoints desde la consola del navegador o con curl:

```bash
# Listar nodos (requiere autenticación)
curl -X GET "http://tu-dominio.com/modules/pacs-nodes-manager/api/nodes.php" \
  -H "Cookie: session_token=TU_TOKEN"
```

### 5.2 Acceder a la Interfaz

1. Inicie sesión con un usuario que tenga los permisos asignados

2. Acceda a:
   ```
   http://tu-dominio.com/pacs-nodes-manager.html
   ```

3. Debe ver la interfaz del módulo con 4 pestañas:
   - **Nodos**: Lista de nodos configurados
   - **Buscar**: Búsqueda de estudios
   - **Jobs**: Monitoreo de jobs
   - **Dashboard**: Estadísticas

---

## 🚀 Paso 6: Configurar Primer Nodo

### 6.1 Crear Nodo DIMSE (Legacy)

1. En la pestaña **Nodos**, haga clic en **Nuevo Nodo**

2. Complete el formulario:
   - **Nombre**: "Hospital Central"
   - **Tipo**: DIMSE (Legacy)
   - **AET**: "HOSPITAL_CENTRAL" (máx 16 caracteres)
   - **Host/IP**: "192.168.1.100"
   - **Puerto**: 104
   - **Usuario/Contraseña**: (si aplica)

3. Haga clic en **Guardar**

4. El nodo se sincronizará automáticamente con Orthanc

5. Haga clic en el botón de **Test Ping** para verificar conectividad

### 6.2 Crear Nodo DICOMweb (Moderno)

1. En la pestaña **Nodos**, haga clic en **Nuevo Nodo**

2. Complete el formulario:
   - **Nombre**: "PACS Moderno"
   - **Tipo**: DICOMweb (Moderno)
   - **URL Base**: "https://pacs.example.com/dicomweb"
   - **Tipo de Autenticación**: Basic Auth (si aplica)
   - **Usuario/Contraseña**: (si aplica)

3. Haga clic en **Guardar**

4. Haga clic en el botón de **Test Ping** para verificar conectividad

---

## 🧪 Paso 7: Probar Funcionalidades

### 7.1 Test de Conectividad

1. En la pestaña **Nodos**, haga clic en el botón de **Test Ping** (icono de red)
2. Debe ver un mensaje de éxito con la latencia

### 7.2 Búsqueda de Estudios

1. Vaya a la pestaña **Buscar**
2. Seleccione un nodo
3. Ingrese filtros (ej: PatientID, StudyDate)
4. Haga clic en **Buscar**
5. Debe ver los resultados en la tabla

### 7.3 Recuperación de Estudios

1. En los resultados de búsqueda, haga clic en **Recuperar**
2. Vaya a la pestaña **Jobs**
3. Debe ver el job en ejecución con progreso

---

## 🔍 Verificación de Logs

Los logs del módulo se guardan en:
```
modules/pacs-nodes-manager/logs/pacs-nodes.log
```

Verifique que el archivo se esté creando y tenga permisos de escritura:

```bash
touch modules/pacs-nodes-manager/logs/pacs-nodes.log
chmod 666 modules/pacs-nodes-manager/logs/pacs-nodes.log
```

---

## 🐛 Troubleshooting

### El módulo no aparece en el sidebar

**Solución**:
1. Verifique que el permiso `gui_pacs_nodes_manager` esté asignado
2. Limpie la caché del navegador
3. Verifique que `sidebar-gui-manager.js` esté cargado

### Error "No tienes permisos"

**Solución**:
1. Verifique que el permiso `pacs_nodes_manager` esté asignado
2. Verifique que el usuario esté autenticado
3. Revise los logs del servidor

### Error al crear nodo

**Solución**:
1. Verifique que Orthanc esté accesible
2. Verifique el formato del AET (máx 16 caracteres)
3. Verifique IP y puerto válidos
4. Revise los logs en `logs/pacs-nodes.log`

### Error en búsqueda C-FIND

**Solución**:
1. Verifique conectividad con `Test Ping`
2. Verifique que el nodo esté configurado en Orthanc
3. Verifique formato de query DICOM
4. Revise logs de Orthanc

### Error en recuperación C-MOVE

**Solución**:
1. Verifique que el nodo remoto acepte C-MOVE
2. Verifique que el AET local esté configurado en Orthanc
3. Aumente timeout si es necesario
4. Revise logs del job en Orthanc

---

## 📝 Notas Importantes

1. **Seguridad**: Las contraseñas se almacenan encriptadas en la base de datos
2. **Cache**: Los resultados de búsqueda se cachean por 5 minutos por defecto
3. **Jobs**: Los jobs se monitorean automáticamente cada 5 segundos
4. **Orthanc**: Los nodos DIMSE se sincronizan automáticamente con Orthanc

---

## 🔄 Actualización del Módulo

Si necesita actualizar el módulo:

1. Haga backup de la base de datos:
   ```bash
   mysqldump -u usuario -p nombre_bd pacs_nodes pacs_node_queries pacs_node_jobs pacs_node_statistics > backup_pacs_nodes.sql
   ```

2. Reemplace los archivos del módulo

3. Ejecute migraciones si las hay (ver `database/migrations/`)

4. Verifique que todo funcione correctamente

---

## 📞 Soporte

Para problemas o preguntas:

1. Revise esta documentación
2. Revise los logs en `logs/pacs-nodes.log`
3. Revise los logs de Orthanc
4. Contacte al administrador del sistema

---

## ✅ Checklist de Instalación

- [ ] Estructura de archivos verificada
- [ ] Instalador ejecutado exitosamente
- [ ] Tablas creadas en base de datos
- [ ] Permisos asignados a usuarios
- [ ] Módulo visible en sidebar
- [ ] Primer nodo configurado
- [ ] Test de conectividad exitoso
- [ ] Búsqueda funcionando
- [ ] Recuperación funcionando
- [ ] Logs funcionando

---

**¡Instalación Completada!** 🎉

El módulo PACS NODES MANAGER está listo para usar.
