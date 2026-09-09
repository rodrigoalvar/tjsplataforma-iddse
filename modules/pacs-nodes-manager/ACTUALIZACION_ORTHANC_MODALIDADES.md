# 🔄 Actualización: Gestión de Modalidades DICOM en Orthanc

**Versión**: 1.1.0  
**Fecha**: 2026-03-06

---

## 📋 Cambios Implementados

### 1. Gestión de Modalidades mediante REST API de Orthanc

El módulo ahora gestiona las modalidades DICOM directamente en Orthanc usando la REST API, compatible con `DicomModalitiesInDatabase: true`.

### 2. Nuevos Campos en Base de Datos

Se agregaron los siguientes campos a la tabla `pacs_nodes`:

- `allow_find` - Permitir operaciones C-FIND
- `allow_move` - Permitir operaciones C-MOVE
- `allow_get` - Permitir operaciones C-GET
- `allow_store` - Permitir operaciones C-STORE
- `allow_transcoding` - Permitir transcodificación
- `manufacturer` - Fabricante del PACS (Generic, GE, Siemens, etc.)
- `timeout` - Timeout en segundos para operaciones DICOM
- `use_dicom_tls` - Usar DICOM TLS (seguridad)
- `orthanc_node_id` - ID del nodo en Orthanc (para DicomModalitiesInDatabase)

### 3. Funcionalidades Agregadas

#### PacsNodeConfig.php

- `listOrthancModalities($expand)` - Lista todas las modalidades configuradas en Orthanc
- `testNodeConnection($nodeId)` - Prueba conectividad usando C-ECHO de Orthanc
- `syncNodeWithOrthanc($node)` - Sincroniza nodo con todos los flags y configuración

#### Endpoints API

- **GET /api/nodes.php** - Ahora incluye todos los campos de configuración
- **POST /api/nodes.php** - Crea nodo y sincroniza automáticamente con Orthanc
- **PUT /api/nodes.php** - Actualiza nodo y sincroniza con Orthanc
- **DELETE /api/nodes.php** - Elimina nodo de Orthanc antes de eliminar de BD
- **POST /api/ping.php** - Usa C-ECHO de Orthanc para nodos DIMSE

### 4. Interfaz de Usuario

El formulario de creación/edición de nodos ahora incluye:

- **Operaciones Permitidas**: Checkboxes para AllowFind, AllowMove, AllowGet, AllowStore, AllowTranscoding
- **Configuración Avanzada**: 
  - Fabricante (Generic, GE, Siemens, Philips, etc.)
  - Timeout (segundos)
  - Usar DICOM TLS

---

## 🚀 Instalación de la Migración

### Paso 1: Ejecutar Script de Migración

```bash
mysql -u iddse -p tjsmedical_iddse < modules/pacs-nodes-manager/database/migration_add_orthanc_flags.sql
```

O desde MySQL:

```sql
SOURCE modules/pacs-nodes-manager/database/migration_add_orthanc_flags.sql;
```

### Paso 2: Verificar Campos Agregados

```sql
DESCRIBE pacs_nodes;
```

Debe mostrar los nuevos campos:
- `allow_find`
- `allow_move`
- `allow_get`
- `allow_store`
- `allow_transcoding`
- `manufacturer`
- `timeout`
- `use_dicom_tls`
- `orthanc_node_id`

---

## 📝 Uso

### Crear un Nuevo Nodo DIMSE

1. Acceder a **PACS Nodes Manager** → **Nodos**
2. Clic en **Nuevo Nodo**
3. Seleccionar tipo: **DIMSE (Legacy)**
4. Completar:
   - **AET**: Nombre del nodo (máx 16 caracteres)
   - **Host/IP**: Dirección del servidor
   - **Puerto**: Puerto DICOM (típicamente 104)
5. Configurar **Operaciones Permitidas**:
   - ✅ Allow Find (C-FIND)
   - ✅ Allow Move (C-MOVE)
   - ✅ Allow Get (C-GET)
   - ⬜ Allow Store (C-STORE) - opcional
6. Configurar **Configuración Avanzada**:
   - **Fabricante**: Seleccionar (Generic por defecto)
   - **Timeout**: 30 segundos (por defecto)
7. Clic en **Guardar**

El nodo se sincronizará automáticamente con Orthanc usando `PUT /modalities/{id}`.

### Probar Conectividad

1. En la tabla de nodos, clic en el botón **Test Ping** (icono de red)
2. El sistema ejecutará `POST /modalities/{id}/echo` en Orthanc
3. Se mostrará el resultado: éxito/fallo y latencia en milisegundos

### Sincronización Automática

- Al **crear** un nodo DIMSE → Se crea en Orthanc automáticamente
- Al **actualizar** un nodo DIMSE → Se actualiza en Orthanc automáticamente
- Al **eliminar** un nodo DIMSE → Se elimina de Orthanc automáticamente

---

## 🔧 Configuración de Orthanc Requerida

Asegúrese de que Orthanc tenga configurado:

```json
{
  "DicomModalitiesInDatabase": true,
  "AuthenticationEnabled": true
}
```

Con esta configuración, Orthanc:
- Ignora la sección estática `DicomModalities` del JSON
- Guarda las modalidades en su base de datos interna
- Solo acepta modalidades definidas mediante REST API

---

## 📚 Endpoints de Orthanc Utilizados

- `GET /modalities?expand` - Listar modalidades
- `PUT /modalities/{id}` - Crear/actualizar modalidad
- `DELETE /modalities/{id}` - Eliminar modalidad
- `POST /modalities/{id}/echo` - Test de conectividad (C-ECHO)
- `POST /modalities/{id}/find` - Búsqueda C-FIND
- `POST /modalities/{id}/move` - Recuperación C-MOVE

---

## ✅ Verificación

Después de crear un nodo, verificar en Orthanc:

```bash
curl -u orthanc:orthanc http://localhost:8042/modalities?expand
```

Debe mostrar el nodo recién creado con todos sus flags y configuración.

---

**¡Actualización Completada!** 🎉
