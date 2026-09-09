# Actualización: Badge de Modalidad con DOC para Informes en PACS

## 📋 Información de la Modificación

**Fecha de Implementación:** 17 de Diciembre, 2025  
**Hora:** 19:49:32 (UTC)  
**Versión del Sistema:** Compatible con todas las versiones  
**Autor:** Sistema de Actualización Automática

---

## 🎯 Objetivo

Actualizar el badge de modalidad en `dashboard-unified` para mostrar "DOC" cuando un estudio tiene un informe médico enviado a PACS, permitiendo identificar visualmente qué estudios tienen informes disponibles en el sistema PACS.

### Problema Resuelto

- **Antes:** Los estudios con informes en PACS solo mostraban su modalidad original (ej: "CT")
- **Después:** Los estudios con informes en PACS muestran modalidad combinada (ej: "CT, DOC")

---

## 🔧 Detalles Técnicos

### Archivos Modificados

1. **`api/get_user_assigned_studies_fixed.php`**
   - Usuarios sin permiso PACS QUERY (estudios asignados/derivados)
   - Líneas modificadas: ~261-370

2. **`api/get_all_studies.php`**
   - Usuarios con permiso PACS QUERY (estudios desde PACS)
   - Líneas modificadas: ~150-290

### Cambios Implementados

#### 1. Consulta de Informes en PACS

**En `get_user_assigned_studies_fixed.php`:**
- Se agregó consulta para verificar informes en PACS antes de procesar estudios
- Cotejo por múltiples identificadores (prioridad):
  1. `study_instance_uid` (más confiable - estándar DICOM)
  2. `study_id` (ID del estudio en tabla de asignaciones)
  3. `orthanc_study_id` vs `estudio_id` (IDs de Orthanc)

**En `get_all_studies.php`:**
- Se modificó la consulta existente para filtrar solo informes EN PACS
- Se agregó verificación de columnas PACS antes de consultar
- Cotejo por múltiples identificadores (misma prioridad)

#### 2. Verificación de Informes en PACS

Ambas APIs verifican que el informe esté realmente en PACS usando:
- `pacs_instance_id IS NOT NULL AND pacs_instance_id != ''` O
- `fecha_enviado_pacs IS NOT NULL`

**Importante:** Solo se considera informe en PACS si tiene al menos uno de estos campos, no solo si existe en la BD.

#### 3. Actualización de Modalidad

**Lógica implementada:**
```php
// Determinar modalidad base
$baseModality = $study['modality'] ?? 'CT';

// Verificar si tiene informe en PACS
$hasPacsReport = /* verificación de informe en PACS */;

// Combinar modalidades si hay informe en PACS
$finalModality = $baseModality;
if ($hasPacsReport && strpos($baseModality, 'DOC') === false) {
    $finalModality = $baseModality . ', DOC';
}
```

### Comportamiento Final

| Situación | Badge de Modalidad | Explicación |
|-----------|-------------------|-------------|
| Estudio CT sin informe | `CT` | Solo modalidad del estudio |
| Estudio CT con informe en PACS | `CT, DOC` | Modalidad DOC disponible en PACS |
| Estudio CT con informe pero NO en PACS | `CT` | Informe existe en BD pero no está en PACS aún |

---

## 📝 Instrucciones de Implementación

### Requisitos Previos

1. **Base de Datos:**
   - Tabla `informes` debe tener al menos una de estas columnas:
     - `pacs_instance_id` (VARCHAR)
     - `fecha_enviado_pacs` (DATETIME/TIMESTAMP)
   - Columnas opcionales pero recomendadas:
     - `study_instance_uid` (VARCHAR) - para mejor cotejo
     - `study_id` (VARCHAR) - para mejor cotejo

2. **Verificar Columnas Existentes:**
   ```sql
   SHOW COLUMNS FROM informes WHERE 
       Field LIKE 'pacs%' OR 
       Field LIKE 'fecha_enviado%' OR
       Field LIKE 'study%';
   ```

### Paso 1: Hacer Backup

```bash
# Backup de archivos a modificar
cp api/get_user_assigned_studies_fixed.php api/get_user_assigned_studies_fixed.php.backup
cp api/get_all_studies.php api/get_all_studies.php.backup

# Backup de base de datos (recomendado)
mysqldump -u usuario -p nombre_bd > backup_antes_modificacion_$(date +%Y%m%d_%H%M%S).sql
```

### Paso 2: Aplicar Modificaciones

#### Opción A: Aplicar Parches Manualmente

**Archivo 1: `api/get_user_assigned_studies_fixed.php`**

1. **Ubicación:** Después de la línea que contiene `$assignments = $uniqueStudies;`

2. **Insertar código de consulta de informes en PACS:**
   - Ver sección "Código a Insertar" más abajo

3. **Modificar sección de procesamiento de estudios:**
   - Buscar línea: `'modality' => $assignment['modality'] ?: 'CT',`
   - Reemplazar con lógica de modalidad combinada (ver código completo)

**Archivo 2: `api/get_all_studies.php`**

1. **Modificar consulta de informes:**
   - Buscar sección que consulta informes (alrededor de línea 168)
   - Agregar verificación de columnas PACS
   - Agregar filtro para solo informes EN PACS
   - Modificar cotejo para usar múltiples identificadores

2. **Modificar actualización de modalidad:**
   - Buscar línea: `'modality' => $study['modality'] ?? '',`
   - Reemplazar con lógica de modalidad combinada

#### Opción B: Reemplazar Archivos Completos

Si prefieres reemplazar los archivos completos, copia los archivos modificados desde el servidor donde se implementó.

### Paso 3: Verificar Implementación

1. **Verificar sintaxis PHP:**
   ```bash
   php -l api/get_user_assigned_studies_fixed.php
   php -l api/get_all_studies.php
   ```

2. **Probar funcionalidad:**
   - Acceder a `dashboard-unified.html`
   - Verificar que estudios con informes en PACS muestren "CT, DOC" (o modalidad correspondiente + DOC)
   - Verificar que estudios sin informes en PACS solo muestren su modalidad base

3. **Verificar logs:**
   ```bash
   tail -f logs/php_errors.log
   # Buscar errores relacionados con las APIs modificadas
   ```

### Paso 4: Probar Casos Específicos

1. **Estudio con informe en PACS:**
   - Debe mostrar: `CT, DOC` (o modalidad correspondiente + DOC)

2. **Estudio con informe pero NO en PACS:**
   - Debe mostrar solo: `CT` (sin DOC)

3. **Estudio sin informe:**
   - Debe mostrar solo: `CT` (sin DOC)

4. **Diferentes modalidades:**
   - Probar con MR, RX, US, etc.
   - Verificar que todas muestren correctamente `MODALIDAD, DOC` cuando hay informe en PACS

---

## 🔍 Código Clave a Implementar

### Para `get_user_assigned_studies_fixed.php`

**Sección 1: Consulta de Informes en PACS (insertar después de `$assignments = $uniqueStudies;`)**

```php
// Consultar informes en PACS para estos estudios
$pacsReportInfo = [];
if (!empty($assignments)) {
    try {
        // Recopilar identificadores de estudios para la consulta
        $studyInstanceUIDs = [];
        $studyIds = [];
        $orthancStudyIds = [];
        
        foreach ($assignments as $assignment) {
            if (!empty($assignment['study_instance_uid'])) {
                $studyInstanceUIDs[] = $assignment['study_instance_uid'];
            }
            if (!empty($assignment['study_id'])) {
                $studyIds[] = $assignment['study_id'];
            }
            if (!empty($assignment['orthanc_study_id'])) {
                $orthancStudyIds[] = $assignment['orthanc_study_id'];
            }
        }
        
        // Eliminar duplicados
        $studyInstanceUIDs = array_unique($studyInstanceUIDs);
        $studyIds = array_unique($studyIds);
        $orthancStudyIds = array_unique($orthancStudyIds);
        
        if (!empty($studyInstanceUIDs) || !empty($studyIds) || !empty($orthancStudyIds)) {
            // Verificar qué columnas PACS existen
            $checkColumnsQuery = "SHOW COLUMNS FROM informes";
            $checkColumnsStmt = $pdo->query($checkColumnsQuery);
            $columns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $hasPacsInstanceId = in_array('pacs_instance_id', $columns);
            $hasFechaEnviadoPacs = in_array('fecha_enviado_pacs', $columns);
            
            // Construir condiciones de cotejo
            $conditions = [];
            $params = [];
            
            // Prioridad 1: Cotejar por study_instance_uid
            if (!empty($studyInstanceUIDs)) {
                $placeholders = str_repeat('?,', count($studyInstanceUIDs) - 1) . '?';
                $conditions[] = "i.study_instance_uid IN ($placeholders)";
                $params = array_merge($params, $studyInstanceUIDs);
            }
            
            // Prioridad 2: Cotejar por study_id
            if (!empty($studyIds)) {
                $placeholders = str_repeat('?,', count($studyIds) - 1) . '?';
                $conditions[] = "i.study_id IN ($placeholders)";
                $params = array_merge($params, $studyIds);
            }
            
            // Prioridad 3: Cotejar por orthanc_study_id vs estudio_id
            if (!empty($orthancStudyIds)) {
                $placeholders = str_repeat('?,', count($orthancStudyIds) - 1) . '?';
                $conditions[] = "i.estudio_id IN ($placeholders)";
                $params = array_merge($params, $orthancStudyIds);
            }
            
            if (!empty($conditions)) {
                $whereClause = "(" . implode(" OR ", $conditions) . ")";
                
                // Construir condición para verificar que está EN PACS
                $pacsCondition = "";
                if ($hasPacsInstanceId && $hasFechaEnviadoPacs) {
                    $pacsCondition = " AND ((i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '') OR i.fecha_enviado_pacs IS NOT NULL)";
                } elseif ($hasPacsInstanceId) {
                    $pacsCondition = " AND (i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '')";
                } elseif ($hasFechaEnviadoPacs) {
                    $pacsCondition = " AND i.fecha_enviado_pacs IS NOT NULL";
                }
                
                $pacsQuery = "
                    SELECT DISTINCT
                        COALESCE(i.study_id, i.study_instance_uid, i.estudio_id) as study_identifier,
                        i.study_id,
                        i.study_instance_uid,
                        i.estudio_id
                    FROM informes i
                    WHERE $whereClause
                    $pacsCondition
                ";
                
                $pacsStmt = $pdo->prepare($pacsQuery);
                $pacsStmt->execute($params);
                $pacsReports = $pacsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Crear mapa de identificadores que tienen informe en PACS
                foreach ($pacsReports as $report) {
                    // Agregar todos los identificadores posibles para facilitar la búsqueda
                    if (!empty($report['study_identifier'])) {
                        $pacsReportInfo[$report['study_identifier']] = true;
                    }
                    if (!empty($report['study_id'])) {
                        $pacsReportInfo[$report['study_id']] = true;
                    }
                    if (!empty($report['study_instance_uid'])) {
                        $pacsReportInfo[$report['study_instance_uid']] = true;
                    }
                    if (!empty($report['estudio_id'])) {
                        $pacsReportInfo[$report['estudio_id']] = true;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('[GET_USER_ASSIGNED_STUDIES] Error consultando informes en PACS: ' . $e->getMessage());
    }
}
```

**Sección 2: Actualización de Modalidad (modificar en el foreach de procesamiento)**

```php
// Determinar modalidad base
$baseModality = $assignment['modality'] ?: 'CT';

// Verificar si tiene informe en PACS
$hasPacsReport = false;
$studyIdentifier = $assignment['study_instance_uid'] ?? $assignment['study_id'] ?? $assignment['orthanc_study_id'] ?? $studyId;

// Buscar en el mapa de informes en PACS usando diferentes identificadores
if (isset($pacsReportInfo[$studyIdentifier]) || 
    isset($pacsReportInfo[$assignment['study_id'] ?? '']) ||
    isset($pacsReportInfo[$assignment['study_instance_uid'] ?? '']) ||
    isset($pacsReportInfo[$assignment['orthanc_study_id'] ?? ''])) {
    $hasPacsReport = true;
}

// Combinar modalidades si hay informe en PACS
$finalModality = $baseModality;
if ($hasPacsReport && strpos($baseModality, 'DOC') === false) {
    $finalModality = $baseModality . ', DOC';
}

// En el array $study, cambiar:
'modality' => $finalModality,  // En lugar de: $assignment['modality'] ?: 'CT'
```

### Para `get_all_studies.php`

**Sección 1: Modificar consulta de informes (alrededor de línea 150-200)**

```php
// Reemplazar la sección que recopila studyInstanceUIDs y agrega:
$orthancStudyIds = []; // IDs de Orthanc para cotejo adicional

foreach ($studies as $study) {
    if (!empty($study['study_instance_uid'])) {
        $studyInstanceUIDs[] = $study['study_instance_uid'];
        $studyMapping[$study['orthanc_id']] = $study['study_instance_uid'];
    }
    if (!empty($study['orthanc_id'])) {
        $orthancStudyIds[] = $study['orthanc_id'];
    }
}

// Modificar condición de verificación:
if (!empty($studyInstanceUIDs) || !empty($orthancStudyIds)) {
    // ... código de verificación de columnas PACS ...
    
    // Construir condiciones de cotejo (prioridad: study_instance_uid, study_id, estudio_id)
    $conditions = [];
    $params = [];
    
    // Prioridad 1: Cotejar por study_instance_uid
    if (!empty($studyInstanceUIDs)) {
        $placeholders = str_repeat('?,', count($studyInstanceUIDs) - 1) . '?';
        $conditions[] = "i.study_instance_uid IN ($placeholders)";
        $params = array_merge($params, $studyInstanceUIDs);
    }
    
    // Prioridad 2: Cotejar por study_id (si existe columna)
    if (in_array('study_id', $columns) && !empty($orthancStudyIds)) {
        $placeholders = str_repeat('?,', count($orthancStudyIds) - 1) . '?';
        $conditions[] = "i.study_id IN ($placeholders)";
        $params = array_merge($params, $orthancStudyIds);
    }
    
    // Prioridad 3: Cotejar por estudio_id (orthanc_study_id)
    if (!empty($orthancStudyIds)) {
        $placeholders = str_repeat('?,', count($orthancStudyIds) - 1) . '?';
        $conditions[] = "i.estudio_id IN ($placeholders)";
        $params = array_merge($params, $orthancStudyIds);
    }
    
    if (!empty($conditions)) {
        $whereClause = "(" . implode(" OR ", $conditions) . ")";
        
        // Consulta con filtro PACS
        $query = "
            SELECT 
                i.estudio_id,
                i.study_instance_uid,
                i.study_id,
                COUNT(*) as total_informes,
                MAX(i.estado) as ultimo_estado,
                MAX(i.fecha_creacion) as ultima_fecha_informe,
                GROUP_CONCAT(DISTINCT i.estado) as estados_disponibles
            FROM informes i
            WHERE $whereClause
            $pacsCondition
            GROUP BY i.estudio_id, i.study_instance_uid, i.study_id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        // ... resto del código ...
    }
}
```

**Sección 2: Actualizar modalidad (alrededor de línea 260-280)**

```php
// Verificar si tiene informe en PACS usando diferentes identificadores
$hasReportInPacs = false;
if ($hasReport) {
    // Ya está en reportInfo (que solo contiene informes en PACS)
    $hasReportInPacs = true;
} else {
    // Intentar con otros identificadores
    if (isset($reportInfo[$studyId]) || 
        isset($reportInfo[$studyInstanceUID]) ||
        (!empty($study['study_id']) && isset($reportInfo[$study['study_id']]))) {
        $hasReportInPacs = true;
    }
}

// Determinar modalidad base
$baseModality = $study['modality'] ?? '';

// Combinar modalidades si hay informe en PACS
$finalModality = $baseModality;
if ($hasReportInPacs && !empty($baseModality) && strpos($baseModality, 'DOC') === false) {
    $finalModality = $baseModality . ', DOC';
}

// En el array $formattedStudies, cambiar:
'modality' => $finalModality,  // En lugar de: $study['modality'] ?? ''
```

---

## ⚠️ Consideraciones Importantes

### Compatibilidad

- **Versiones PHP:** Compatible con PHP 7.0+
- **Versiones MySQL:** Compatible con MySQL 5.7+ y MariaDB 10.2+
- **Dependencias:** No requiere nuevas dependencias

### Rendimiento

- Las consultas adicionales pueden aumentar ligeramente el tiempo de respuesta
- Se recomienda tener índices en:
  - `informes.study_instance_uid`
  - `informes.study_id`
  - `informes.estudio_id`
  - `informes.pacs_instance_id`
  - `informes.fecha_enviado_pacs`

### Verificación de Columnas

El código verifica dinámicamente qué columnas existen antes de usarlas, por lo que es compatible con diferentes versiones de la base de datos.

---

## 🐛 Solución de Problemas

### Problema: No se muestra "DOC" en el badge

**Posibles causas:**
1. El informe no está en PACS (verificar `pacs_instance_id` o `fecha_enviado_pacs`)
2. El cotejo no encuentra el informe (verificar que los identificadores coincidan)
3. Error en la consulta (revisar logs de PHP)

**Solución:**
```sql
-- Verificar si el informe está en PACS
SELECT id, study_instance_uid, study_id, estudio_id, 
       pacs_instance_id, fecha_enviado_pacs
FROM informes 
WHERE estudio_id = 'ID_DEL_ESTUDIO' 
   OR study_instance_uid = 'UID_DEL_ESTUDIO';
```

### Problema: Error de sintaxis PHP

**Solución:**
```bash
php -l api/get_user_assigned_studies_fixed.php
php -l api/get_all_studies.php
```

### Problema: Consulta SQL falla

**Verificar:**
- Que las columnas PACS existan en la tabla `informes`
- Que los identificadores no estén vacíos
- Revisar logs de errores de PHP

---

## 📊 Verificación Post-Implementación

### Checklist

- [ ] Archivos modificados correctamente
- [ ] Sin errores de sintaxis PHP
- [ ] Estudios con informes en PACS muestran "MODALIDAD, DOC"
- [ ] Estudios sin informes en PACS muestran solo "MODALIDAD"
- [ ] Funciona para usuarios con PACS QUERY
- [ ] Funciona para usuarios sin PACS QUERY (asignados/derivados)
- [ ] No hay errores en logs de PHP
- [ ] Rendimiento aceptable

### Pruebas Recomendadas

1. **Estudio CT con informe en PACS:**
   - Debe mostrar: `CT, DOC`

2. **Estudio MR con informe en PACS:**
   - Debe mostrar: `MR, DOC`

3. **Estudio RX sin informe:**
   - Debe mostrar: `RX`

4. **Estudio US con informe pero NO en PACS:**
   - Debe mostrar: `US` (sin DOC)

---

## 📞 Soporte

Si encuentras problemas durante la implementación:

1. Revisar logs de PHP: `logs/php_errors.log`
2. Verificar que las columnas PACS existan en la BD
3. Verificar que los identificadores de estudios coincidan
4. Revisar permisos de archivos PHP

---

**Última actualización:** 17 de Diciembre, 2025 19:49:32  
**Versión del documento:** 1.0

