# Módulo PACS Manager

## 📋 Descripción General

El **Módulo PACS Manager** es un sistema completo para la administración de estudios médicos almacenados en el servidor PACS (Orthanc). Permite editar y eliminar estudios directamente desde la interfaz web, proporcionando control total sobre los datos DICOM almacenados.

### Características Principales

- ✅ **Edición de Estudios**: Modifica tags DICOM de estudios existentes en PACS
- ✅ **Eliminación de Estudios**: Elimina estudios completos del servidor PACS
- ✅ **Búsqueda y Filtrado**: Búsqueda avanzada por fecha, paciente, modalidad, etc.
- ✅ **Interfaz Intuitiva**: Basada en dashboard-unified para consistencia visual
- ✅ **Control de Permisos**: Requiere permiso específico `pacs_manager` para acceso
- ✅ **Integración Completa**: Se integra automáticamente con el sidebar y sistema de permisos

---

## 🚀 Instalación

### Requisitos Previos

- Sistema TJSMEDICAL instalado y funcionando
- Base de datos MySQL/MariaDB accesible
- Servidor PACS (Orthanc) configurado y accesible
- Permisos de escritura en el directorio del proyecto

### Pasos de Instalación

#### 1. Ejecutar el Instalador

Accede al script de instalación desde tu navegador:

```
http://tu-dominio.com/install-pacs-manager.php
```

El instalador realizará automáticamente:
- ✅ Creación de permisos en `system_permissions`
- ✅ Verificación de archivos del módulo
- ✅ Validación de la base de datos

#### 2. Verificar Instalación

Después de ejecutar el instalador, verifica que:

1. Los permisos se crearon correctamente:
   ```sql
   SELECT * FROM system_permissions 
   WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager');
   ```

2. Los archivos están presentes:
   - `pacs-manager.html`
   - `assets/js/pacs-manager.js`
   - `api/pacs-manager/list.php`
   - `api/pacs-manager/edit.php`
   - `api/pacs-manager/delete.php`

#### 3. Asignar Permisos a Usuarios

Para que los usuarios puedan acceder al módulo:

1. Accede a **Gestión Usuarios** (`user-management.html`)
2. Selecciona el usuario
3. Asigna los permisos:
   - `pacs_manager`: Permiso funcional (requerido para usar el módulo)
   - `gui_pacs_manager`: Permiso de interfaz (requerido para ver en el sidebar)

#### 4. Eliminar Archivo de Instalación (Recomendado)

Por seguridad, elimina el archivo de instalación después de completar la configuración:

```bash
rm install-pacs-manager.php
```

---

## 📖 Uso del Módulo

### Acceso al Módulo

1. Inicia sesión con un usuario que tenga el permiso `pacs_manager`
2. El enlace **PACS Manager** aparecerá en el sidebar (si tienes `gui_pacs_manager`)
3. Haz clic en **PACS Manager** para acceder

### Funcionalidades

#### Listar Estudios

- Los estudios se cargan automáticamente al abrir el módulo
- Usa los filtros para buscar estudios específicos:
  - **Fecha Inicio / Fecha Fin**: Rango de fechas
  - **ID Paciente**: Filtrar por ID de paciente
  - **Búsqueda General**: Busca en nombre, ID, descripción, modalidad, etc.

#### Editar Estudio

1. Haz clic en el botón **Editar** (ícono de lápiz) en la fila del estudio
2. Se abrirá un modal con los campos editables:
   - Nombre del Paciente
   - ID Paciente
   - Descripción del Estudio
   - Fecha del Estudio
   - Número de Acceso
   - Institución
   - Médico Solicitante
3. Modifica los campos necesarios
4. Haz clic en **Guardar Cambios**
5. Los cambios se aplicarán inmediatamente en PACS

**Nota**: Los cambios son permanentes y se propagan a todas las instancias del estudio en Orthanc.

#### Eliminar Estudio

1. Haz clic en el botón **Eliminar** (ícono de papelera) en la fila del estudio
2. Confirma la eliminación en el diálogo
3. El estudio será eliminado permanentemente de PACS

**⚠️ ADVERTENCIA**: La eliminación es permanente y no se puede deshacer. Asegúrate de tener respaldos si es necesario.

---

## 🔧 Estructura del Módulo

### Archivos Principales

```
pacs-manager/
├── pacs-manager.html              # Interfaz principal
├── assets/js/pacs-manager.js      # Lógica JavaScript
├── api/pacs-manager/
│   ├── list.php                   # API listar estudios
│   ├── edit.php                   # API editar estudios
│   └── delete.php                 # API eliminar estudios
├── database/
│   └── pacs_manager_install.sql   # Script SQL de instalación
├── install-pacs-manager.php       # Instalador del módulo
└── README_PACS_MANAGER.md         # Esta documentación
```

### APIs Disponibles

#### `GET /api/pacs-manager/list.php`

Lista estudios desde PACS con filtros opcionales.

**Parámetros (query string):**
- `dateFrom` (opcional): Fecha inicio (YYYY-MM-DD)
- `dateTo` (opcional): Fecha fin (YYYY-MM-DD)
- `patientId` (opcional): ID del paciente

**Respuesta:**
```json
{
  "success": true,
  "data": [
    {
      "study_id": "abc123...",
      "patient_name": "APELLIDO^NOMBRE",
      "patient_id": "12345",
      "study_date": "20240115",
      "study_description": "CT Tórax",
      "modality": "CT",
      ...
    }
  ],
  "count": 10
}
```

#### `POST /api/pacs-manager/edit.php`

Actualiza tags DICOM de un estudio.

**Body (JSON):**
```json
{
  "study_id": "abc123...",
  "tags": {
    "PatientName": "NUEVO NOMBRE",
    "PatientID": "12345",
    "StudyDescription": "Nueva descripción",
    "StudyDate": "20240115",
    "AccessionNumber": "ACC123",
    "InstitutionName": "HOSPITAL",
    "ReferringPhysicianName": "DR. APELLIDO"
  }
}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Estudio actualizado exitosamente",
  "study_id": "abc123...",
  "updated_tags": ["PatientName", "PatientID", ...]
}
```

#### `POST /api/pacs-manager/delete.php`

Elimina un estudio de PACS.

**Body (JSON):**
```json
{
  "study_id": "abc123..."
}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Estudio eliminado exitosamente",
  "study_id": "abc123..."
}
```

---

## 🔐 Permisos y Seguridad

### Permisos Requeridos

- **`pacs_manager`**: Permiso funcional necesario para usar el módulo
- **`gui_pacs_manager`**: Permiso de interfaz para mostrar en el sidebar

### Seguridad

- ✅ Todas las APIs verifican autenticación
- ✅ Todas las APIs verifican permisos antes de ejecutar operaciones
- ✅ Las operaciones se registran en logs del servidor
- ✅ Validación de datos en frontend y backend
- ✅ Prevención de XSS mediante escape de HTML

### Campos Editables

Los siguientes campos DICOM pueden ser editados:
- `PatientName` (0010,0010)
- `PatientID` (0010,0020)
- `StudyDescription` (0008,1030)
- `StudyDate` (0008,0020)
- `AccessionNumber` (0008,0050)
- `InstitutionName` (0008,0080)
- `ReferringPhysicianName` (0008,0090)

**Campos NO editables** (por seguridad):
- `StudyInstanceUID` - Identificador único del estudio
- `SeriesInstanceUID` - Identificador único de la serie
- Otros UIDs DICOM

---

## 🔄 Integración con Orthanc

### Operaciones PACS

El módulo utiliza la API REST de Orthanc para:

1. **Listar Estudios**: `GET /studies`
2. **Editar Tags**: `PATCH /studies/{id}/tags`
3. **Eliminar Estudio**: `DELETE /studies/{id}`

### Propagación de Cambios

Cuando se edita un estudio:
- Orthanc propaga automáticamente los cambios a todas las instancias del estudio
- No es necesario editar cada instancia individualmente
- Los cambios son inmediatos y permanentes

---

## 🐛 Troubleshooting

### El módulo no aparece en el sidebar

**Solución:**
1. Verifica que el permiso `gui_pacs_manager` esté asignado al usuario
2. Verifica que `sidebar-gui-manager.js` esté cargado
3. Limpia la caché del navegador

### Error "No tienes permisos para gestionar estudios PACS"

**Solución:**
1. Verifica que el permiso `pacs_manager` esté asignado al usuario
2. Verifica que el usuario esté autenticado correctamente
3. Revisa los logs del servidor para más detalles

### Error al editar estudio

**Solución:**
1. Verifica que Orthanc esté accesible
2. Verifica que el `study_id` sea válido
3. Verifica que los campos requeridos estén completos
4. Revisa los logs de Orthanc para errores específicos

### Estudios no se cargan

**Solución:**
1. Verifica la conexión con Orthanc
2. Verifica que el usuario tenga permiso `pacs_query` (si aplica)
3. Revisa la consola del navegador para errores JavaScript
4. Revisa los logs del servidor PHP

---

## 📝 Notas para Futuras Versiones

### Mejoras Sugeridas

1. **Historial de Cambios**: Registrar quién y cuándo se modificó cada estudio
2. **Validación Avanzada**: Validar formato de nombres DICOM, fechas, etc.
3. **Edición Masiva**: Permitir editar múltiples estudios a la vez
4. **Exportación**: Exportar lista de estudios a CSV/Excel
5. **Filtros Avanzados**: Filtros por modalidad, institución, etc.
6. **Vista Previa**: Mostrar imágenes del estudio antes de editar/eliminar
7. **Confirmación Adicional**: Confirmación doble para eliminaciones

### Compatibilidad

- ✅ Compatible con Orthanc 1.x y 2.x
- ✅ Compatible con MySQL 5.7+ y MariaDB 10.2+
- ✅ Compatible con PHP 7.4+
- ✅ Compatible con navegadores modernos (Chrome, Firefox, Edge, Safari)

---

## 📞 Soporte

Para problemas o preguntas sobre el módulo:

1. Revisa esta documentación
2. Revisa los logs del servidor (`/var/log/apache2/error.log` o similar)
3. Revisa los logs de Orthanc
4. Contacta al administrador del sistema

---

## 📄 Licencia

Este módulo es parte del Sistema TJSMEDICAL y está sujeto a la misma licencia del proyecto principal.

---

**Versión**: 1.0.0  
**Fecha de Creación**: 2025-01-XX  
**Última Actualización**: 2025-01-XX  
**Autor**: Sistema TJSMEDICAL



