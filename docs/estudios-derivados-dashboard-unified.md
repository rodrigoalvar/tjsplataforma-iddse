# Visualización de Estudios Derivados en Dashboard Unified

## 📋 Problema Identificado

Los usuarios sin permiso **PACS QUERY** que recibían estudios **derivados** (subasignados) desde cuentas padre **NO podían visualizarlos** en `dashboard-unified.html`.

El dashboard solo mostraba estudios asignados directamente pero ignoraba las derivaciones.

### Ejemplo del Problema:
- Usuario `tucuman@iddse.com.ar` (padre) asigna estudios a `luisfajre@mail.com` (hijo)
- Usuario `luisfajre@mail.com` ingresa a `dashboard-unified.html`
- ❌ **NO veía** los estudios derivados, solo veía estudios asignados directamente

---

## ✅ Solución Implementada

### 1. Modificación de la API: `get_user_assigned_studies_fixed.php`

Se actualizó la consulta SQL para incluir **TANTO asignaciones directas COMO derivaciones**:

#### Antes:
```sql
-- Solo consultaba study_assignments
SELECT * FROM study_assignments 
WHERE user_id = ? AND status = 'active'
```

#### Después:
```sql
-- Consulta 1: Asignaciones directas
SELECT sa.*, 'assigned' as source_type
FROM study_assignments sa
WHERE sa.user_id = ? AND sa.status = 'active'

-- Consulta 2: Derivaciones (subasignaciones)
SELECT ss.study_id, sa.*, 'subassigned' as source_type
FROM study_subassignments ss
LEFT JOIN study_assignments sa ON ss.study_id = sa.study_id 
WHERE ss.subassigned_to_user_id = ? AND ss.status = 'active'

-- Combinar ambas y eliminar duplicados
```

### 2. Lógica de Combinación

```php
// Combinar asignaciones y derivaciones
$assignments = array_merge($assignedStudies, $subassignedStudies);

// Eliminar duplicados por study_id (priorizar asignaciones directas)
$uniqueStudies = [];
$seenStudyIds = [];

foreach ($assignments as $assignment) {
    $studyId = $assignment['study_id'];
    if (!in_array($studyId, $seenStudyIds)) {
        $uniqueStudies[] = $assignment;
        $seenStudyIds[] = $studyId;
    }
}
```

### 3. Respuesta de la API Mejorada

La API ahora incluye información detallada:

```json
{
  "success": true,
  "data": {
    "studies": [...],
    "user": {...},
    "total": 5,
    "assigned_count": 3,
    "subassigned_count": 2,
    "message": "Estudios asignados y derivados obtenidos correctamente"
  }
}
```

### 4. Frontend: `dashboard-with-permissions.js`

Se actualizó para mostrar información detallada:

```javascript
// Mostrar en consola
const assignedCount = result.data.assigned_count || 0;
const subassignedCount = result.data.subassigned_count || 0;

console.log('📊 Estudios cargados:', {
    total: this.studies.length,
    asignados: assignedCount,
    derivados: subassignedCount
});

if (subassignedCount > 0) {
    console.log('✅ Se incluyeron estudios derivados (subasignaciones)');
}
```

Se cambió el texto del modo:

```
Antes: "Modo: Estudios Asignados"
Después: "Modo: Estudios Asignados y Derivados"
```

---

## 🧪 Resultados de Pruebas

### Usuario: `luisfajre@mail.com` (Hijo)
- **Asignaciones directas**: 1 estudio
- **Derivaciones**: 2 estudios
- **Total visible**: 3 estudios ✅

### Usuario: `solanamedica@mail.com` (Hijo)
- **Asignaciones directas**: 0
- **Derivaciones**: 1 estudio
- **Total visible**: 1 estudio ✅

### Usuario: `tucuman@iddse.com.ar` (Padre)
- **Asignaciones directas**: 3 estudios
- **Derivaciones**: 0 (es cuenta padre, no recibe derivaciones)
- **Total visible**: 3 estudios ✅

---

## 📂 Archivos Modificados

1. **`api/get_user_assigned_studies_fixed.php`**
   - Líneas 131-237: Nueva lógica de consulta combinada (asignaciones + derivaciones)
   - Línea 139, 184: Agregado campo `'assigned'/'subassigned' as source_type` en queries
   - Línea 288: Inclusión de `source_type` en array de respuesta del estudio
   - Líneas 254-256: Actualización del mensaje de respuesta vacía
   - Líneas 353-355: Inclusión de contadores en respuesta exitosa

2. **`assets/js/dashboard-with-permissions.js`**
   - Línea 89: Actualización del texto de modo en `init()`
   - Línea 119: Actualización del texto de modo en `checkAuth()`
   - Línea 162: Actualización del texto de modo en `updateModeIndicator()`
   - Líneas 221-233: Logs detallados de asignaciones y derivaciones
   - Línea 816: Cambio de badge de estado estático a método dinámico `getStatusBadge(study)`
   - Líneas 833-848: Nuevo método `getStatusBadge()` para mostrar "Asignado" o "Derivado"

---

## 🔑 Conceptos Clave

### Asignación Directa
Cuando un estudio es asignado directamente a un usuario desde `estudios-manager.html` o desde una cuenta de mayor jerarquía.

**Tabla**: `study_assignments`
- Campo clave: `user_id`

### Derivación (Subasignación)
Cuando una cuenta **padre** que tiene estudios asignados, los **deriva/subasigna** a sus cuentas **hijas**.

**Tabla**: `study_subassignments`
- Campo clave: `subassigned_to_user_id`
- Mantiene referencia a `main_user_id` (cuenta padre original)

### Prioridad
Si un estudio está **tanto asignado como derivado** al mismo usuario (caso poco común), se **prioriza la asignación directa** para evitar duplicados.

---

## 🎯 Flujo Completo

1. **Admin/Root** asigna estudios a **Cuenta Padre** (`tucuman@iddse.com.ar`)
   - Se crea registro en `study_assignments`

2. **Cuenta Padre** deriva estudios a **Cuenta Hija** (`luisfajre@mail.com`)
   - Se crea registro en `study_subassignments`

3. **Cuenta Hija** ingresa a `dashboard-unified.html`
   - API consulta `study_assignments` (asignaciones directas)
   - API consulta `study_subassignments` (derivaciones)
   - API combina ambos resultados
   - Frontend muestra estudios de ambas fuentes ✅

---

---

## 🎨 Indicador Visual en Columna ESTADO

### Implementación

Se agregó un método `getStatusBadge()` en `dashboard-with-permissions.js` que diferencia visualmente los estudios:

#### Badge "Asignado" (Azul)
```html
<span class="badge bg-primary" title="Este estudio fue asignado directamente a tu cuenta">
    <i class="fas fa-user-check me-1"></i>Asignado
</span>
```

#### Badge "Derivado" (Verde)
```html
<span class="badge bg-success" title="Este estudio fue derivado desde una cuenta padre">
    <i class="fas fa-share-square me-1"></i>Derivado
</span>
```

### Lógica de Determinación

```javascript
getStatusBadge(study) {
    const sourceType = study.source_type || 'assigned';
    
    if (sourceType === 'subassigned') {
        return `<span class="badge bg-success">...</span>`; // Verde - Derivado
    } else {
        return `<span class="badge bg-primary">...</span>`; // Azul - Asignado
    }
}
```

El campo `source_type` proviene directamente de la API (`get_user_assigned_studies_fixed.php`) que lo obtiene de las consultas SQL.

---

## 🚀 Próximos Pasos

### Posibles Mejoras Futuras:

1. ✅ **Indicador visual** en la lista de estudios para diferenciar:
   - 🔵 Estudios asignados directamente (IMPLEMENTADO)
   - 🟢 Estudios derivados desde cuenta padre (IMPLEMENTADO)

2. **Filtro específico** para mostrar solo:
   - Asignaciones directas
   - Derivaciones
   - Ambos (actual)

3. **Información de origen** en detalles del estudio:
   - "Derivado desde: TUCUMAN INFORMANTES"
   - "Asignado por: Admin Root"

---

## 📝 Notas Técnicas

- La solución **NO modifica** la tabla `study_assignments`
- La cuenta padre **mantiene** la asignación original
- Las derivaciones **NO reemplazan** la asignación principal
- Los permisos de **PACS QUERY** NO se ven afectados
- Compatible con la jerarquía de cuentas existente

---

**Fecha de Implementación**: 29 de Octubre, 2025  
**Versión**: 1.0  
**Estado**: ✅ Implementado y Probado

