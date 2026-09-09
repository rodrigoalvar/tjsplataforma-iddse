# Análisis: Funcionalidad de Informes Incompletos

## 📋 Resumen

Análisis comparativo entre `PORTAL_ESTUDIOS` y `tjsplataforma` para implementar la funcionalidad de marcar/desmarcar informes incompletos en `informes-manager`.

## 🔍 Funcionalidad en PORTAL_ESTUDIOS

### Características Principales:

1. **Marcar/Desmarcar Informes Incompletos**
   - Botón en cada fila de informe para marcar/desmarcar
   - Modal de confirmación con campo de nota opcional
   - Permiso `marcar_incompletos` requerido

2. **Filtro de Informes Incompletos**
   - Opción "Incompletos" en el dropdown de estado
   - Filtra estudios que tienen el flag `informes_incompletos = true`

3. **APIs Utilizadas**
   - `api/study-flags.php` - GET/POST para obtener/guardar flags
   - `api/study-flags/batch.php` - GET para obtener múltiples flags a la vez

4. **Base de Datos**
   - Tabla `study_flags` con campos:
     - `study_id` (VARCHAR 255)
     - `user_id` (INT)
     - `informes_incompletos` (BOOLEAN)
     - `nota` (TEXT)
     - `prioridad` (VARCHAR - opcional, para otra funcionalidad)
     - `orthanc_id` (VARCHAR - opcional)
     - `study_instance_uid` (VARCHAR - opcional)

5. **Integración con Dashboard**
   - Los flags se muestran en el dashboard
   - Los estudios marcados aparecen con indicador visual

## 🔄 Funcionalidades que Faltan en tjsplataforma

### 1. Funcionalidad de Informes Incompletos
- ❌ No existe tabla `study_flags`
- ❌ No existe API `study-flags.php`
- ❌ No existe API `study-flags/batch.php`
- ❌ No existe permiso `marcar_incompletos`
- ❌ No existe botón para marcar/desmarcar en informes-manager
- ❌ No existe filtro "Incompletos" en el dropdown de estado
- ❌ No existe modal de confirmación

### 2. Otras Funcionalidades Potenciales
- Revisar si hay otras diferencias menores (pendiente de análisis más profundo)

## 📝 Plan de Implementación

### Fase 1: Base de Datos
1. ✅ Crear script SQL para tabla `study_flags`
2. ✅ Agregar permiso `marcar_incompletos` a `system_permissions`

### Fase 2: APIs
1. ✅ Crear `api/study-flags.php` (GET/POST)
2. ✅ Crear `api/study-flags/batch.php` (GET)

### Fase 3: Frontend - JavaScript
1. ✅ Agregar estado `canMarcarIncompletos` y `studyFlags` en `informes-manager.js`
2. ✅ Agregar función `checkMarcarIncompletosPermission()`
3. ✅ Agregar función `loadStudyFlags()`
4. ✅ Agregar función `toggleInformesIncompletos()`
5. ✅ Agregar función `showInformesIncompletosModal()`
6. ✅ Modificar `renderReports()` para incluir botón de incompletos
7. ✅ Modificar `applyFilters()` para incluir filtro de incompletos

### Fase 4: Frontend - HTML
1. ✅ Agregar opción "Incompletos" en el dropdown de estado
2. ✅ El botón se agregará dinámicamente en JavaScript

### Fase 5: Integración
1. ✅ Integrar con sistema de permisos existente
2. ✅ Verificar que funcione con el resto de funcionalidades

## 🔗 Archivos a Modificar/Crear

### Nuevos Archivos:
- `database/create_study_flags_table.sql`
- `database/add_permiso_marcar_incompletos.sql`
- `api/study-flags.php`
- `api/study-flags/batch.php`

### Archivos a Modificar:
- `assets/js/informes-manager.js`
- `components/informes-manager.html`
- `api/users/permissions-simple.php` (agregar permiso por defecto)

## ⚠️ Consideraciones

1. **Compatibilidad**: Mantener todas las funcionalidades existentes de tjsplataforma
2. **Permisos**: Integrar con el sistema de permisos existente
3. **Base de Datos**: Asegurar que la tabla `study_flags` tenga los campos necesarios
4. **APIs**: Adaptar las APIs de PORTAL_ESTUDIOS a la estructura de tjsplataforma
5. **Testing**: Probar que no se rompan funcionalidades existentes

