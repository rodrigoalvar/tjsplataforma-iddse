# Análisis: Agregar Datos a la Lista de Estudios/Audios en AI-Informes

## Objetivo
Agregar información adicional a la sección AI-Informes:

### En la Tabla Principal (Lista de Estudios):
1. **ID Paciente** - ID del paciente asociado al estudio
2. **Fecha del estudio** - Fecha en que se realizó el estudio

### En el Modal "Audios del Estudio" (una fila por audio):
1. **Usuario que dictó el audio** - Nombre del usuario que grabó/dictó cada audio
2. **Fecha de dictado del audio** - Fecha en que se grabó/dictó cada audio
3. **ID Paciente** - ID del paciente (mostrado en encabezado o información del estudio)
4. **Fecha del estudio** - Fecha del estudio (mostrado en encabezado o información del estudio)

**Nota importante**: Como un estudio puede tener múltiples audios con diferentes usuarios y fechas de dictado, la información del usuario y fecha de dictado se mostrará en el modal donde cada audio tiene su propia fila.

## Estado Actual

### Estructura de Datos

#### Backend (`api/ai-informes.php`)
- La consulta SQL actual obtiene:
  - ✅ `patient_id_pacs` - ID del paciente (ya disponible)
  - ✅ `study_date` - Fecha del estudio (ya disponible)
  - ✅ `audio_fecha_creacion` - Fecha de creación del audio (ya disponible como `audio_fecha_creacion`)
  - ❌ **FALTA**: `usuario_id` del audio y nombre del usuario

#### Frontend (`assets/js/ai-informes.js`)
- **Tabla principal** muestra:
  - ID, Paciente, Modalidad, Descripción, Fecha, Audios, Transcripciones, Informes AI, Acciones
  - ❌ **FALTA**: ID Paciente, Fecha Estudio (renombrar "Fecha")
- **Modal "Audios del Estudio"** muestra (líneas ~1778-1901):
  - ID, Audio, Tamaño, Duración, Transcripción, Informe, Acciones
  - ❌ **FALTA**: Usuario que dictó, Fecha de dictado
  - ❌ **FALTA**: ID Paciente y Fecha del estudio (en encabezado o sección de info)

#### HTML (`ai-informes.html`)
- El `<thead>` tiene las columnas actuales
- ❌ **FALTA**: Agregar columnas para los nuevos datos

## Cambios Necesarios

### 1. Backend - Modificar Consulta SQL (`api/ai-informes.php`)

**Ubicación**: Función `handleListAudios()`, línea ~884

**Cambios requeridos**:
1. Agregar `ai.usuario_id` a la consulta SELECT
2. Hacer JOIN con la tabla `usuarios` para obtener nombre y apellido
3. Incluir estos datos en el GROUP BY si es necesario

**Código actual** (líneas 884-951):
```sql
SELECT 
    ai.id as audio_id,
    ai.estudio_id as orthanc_study_id,
    ai.informe_id,
    ...
    ai.fecha_creacion as audio_fecha_creacion,
    ...
FROM audios_informe ai
LEFT JOIN estudios e ON ai.estudio_id = e.orthanc_study_id
LEFT JOIN informes i ON ai.informe_id = i.id
LEFT JOIN estudios e2 ON i.estudio_id = e2.orthanc_study_id
WHERE ai.activo = 1
GROUP BY ...
```

**Código modificado** (agregar):
```sql
SELECT 
    ai.id as audio_id,
    ai.estudio_id as orthanc_study_id,
    ai.informe_id,
    ai.usuario_id as audio_usuario_id,  -- NUEVO
    ai.fecha_creacion as audio_fecha_creacion,
    ...
    COALESCE(MAX(e.patient_id_pacs), MAX(i.patient_id)) as patient_id_pacs,
    ...
FROM audios_informe ai
LEFT JOIN estudios e ON ai.estudio_id = e.orthanc_study_id
LEFT JOIN informes i ON ai.informe_id = i.id
LEFT JOIN estudios e2 ON i.estudio_id = e2.orthanc_study_id
LEFT JOIN usuarios u ON ai.usuario_id = u.id  -- NUEVO JOIN
WHERE ai.activo = 1
GROUP BY ai.id, ai.estudio_id, ai.informe_id, ai.usuario_id, ...  -- Agregar ai.usuario_id
```

**Nota**: Como los estudios pueden tener múltiples audios con diferentes usuarios y fechas:
- En la **tabla principal**: Solo mostraremos ID Paciente y Fecha del Estudio (datos del estudio, no del audio)
- En el **modal de audios**: Cada fila mostrará el Usuario y Fecha de dictado específicos de ese audio

### 2. Backend - Modificar Procesamiento de Datos (`api/ai-informes.php`)

**Ubicación**: Líneas ~1024-1065, donde se prepara `$audioData`

**Cambios requeridos**:
1. Agregar `usuario_id` y datos del usuario a `$audioData` (para el modal)
2. Asegurar que `patient_id_pacs` y `study_date` estén en el objeto `estudio` (para la tabla principal)

**Código a modificar**:
```php
// En la preparación de $audioData (línea ~1025)
$audioData = [
    'id' => $audio['audio_id'],
    ...
    'usuario_id' => $audio['audio_usuario_id'],  // NUEVO
    'usuario_nombre' => $audio['usuario_nombre'] ?? null,  // NUEVO
    'usuario_apellido' => $audio['usuario_apellido'] ?? null,  // NUEVO
    'fecha_creacion' => $audio['audio_fecha_creacion'],  // Ya existe, se usará en el modal
    ...
];

// En la creación de $estudiosMap (línea ~1010) - Ya existe patient_id_pacs y study_date
$estudiosMap[$estudioId] = [
    'estudio' => [
        'id' => ...,
        'patient_id_pacs' => $audio['patient_id_pacs'],  // Ya existe, se mostrará en tabla principal
        'study_date' => $audio['study_date'],  // Ya existe, se mostrará en tabla principal
        ...
    ],
    'audios' => []  // Cada audio tendrá su usuario_id, usuario_nombre, usuario_apellido, fecha_creacion
];
```

### 3. Frontend - Modificar Transformación de Datos para Tabla Principal (`assets/js/ai-informes.js`)

**Ubicación**: Función `initializeTable()`, `dataSrc` (líneas ~253-271)

**Cambios requeridos**:
1. Incluir `patient_id_pacs` y `study_date` en el objeto retornado (para la tabla principal)
2. NO incluir información de usuario/fecha de dictado aquí (se mostrará en el modal)

**Código a modificar**:
```javascript
return data.map(item => {
    const estudio = item.estudio;
    const audios = item.audios || [];
    
    const transcriptionCount = audios.filter(a => a.transcription && a.transcription.status === 'completed').length;
    const reportCount = audios.filter(a => a.report && a.report.status === 'completed').length;
    
    return {
        estudio_id: estudio.id,
        estudio_orthanc_id: estudio.orthanc_study_id,
        paciente: estudio.patient_name_pacs || 'N/A',
        patient_id_pacs: estudio.patient_id_pacs || 'N/A',  // NUEVO - para tabla principal
        modalidad: estudio.modality || 'N/A',
        descripcion: estudio.study_description || 'N/A',
        fecha_estudio: estudio.study_date || null,  // NUEVO - renombrar 'fecha' a 'fecha_estudio'
        audios: audios,  // Los audios ya tienen usuario_id, usuario_nombre, usuario_apellido, fecha_creacion
        audio_count: audios.length,
        transcription_count: transcriptionCount,
        report_count: reportCount
    };
});
```

### 4. Frontend - Agregar Columnas a DataTable Principal (`assets/js/ai-informes.js`)

**Ubicación**: Función `initializeTable()`, array `columns` (líneas ~283-368)

**Cambios requeridos**:
1. Agregar columna "ID Paciente" después de "Paciente"
2. Modificar columna "Fecha" para que muestre "Fecha Estudio"
3. NO agregar columnas de Usuario/Fecha Dictado aquí (se mostrarán en el modal)

**Orden sugerido de columnas**:
1. ID
2. Paciente
3. **ID Paciente** (NUEVO)
4. Modalidad
5. Descripción
6. **Fecha Estudio** (renombrar "Fecha")
7. Audios
8. Transcripciones
9. Informes AI
10. Acciones

### 5. Frontend - Modificar Modal "Audios del Estudio" (`assets/js/ai-informes.js`)

**Ubicación**: Función `showAudiosModal()`, construcción del modal (líneas ~1777-1901)

**Cambios requeridos**:
1. Agregar columna "Usuario" en el `<thead>` del modal
2. Agregar columna "Fecha Dictado" en el `<thead>` del modal
3. Mostrar información del usuario en cada fila de audio
4. Mostrar fecha de dictado en cada fila de audio
5. Agregar información del estudio (ID Paciente, Fecha Estudio) en el encabezado del modal o en una sección de información

**Código a modificar** (líneas ~1778-1794):
```javascript
<thead>
    <tr>
        <th style="width: 40px;">
            <input type="checkbox" id="selectAllAudios" ...>
        </th>
        <th>ID</th>
        <th>Audio</th>
        <th>Usuario</th>  // NUEVO
        <th>Fecha Dictado</th>  // NUEVO
        <th>Tamaño</th>
        <th>Duración</th>
        <th>Transcripción</th>
        <th>Informe</th>
        <th>Acciones</th>
    </tr>
</thead>
```

**Código a modificar** (líneas ~1848-1901, en el forEach de audios):
```javascript
audios.forEach(audio => {
    // ... código existente ...
    
    // Obtener nombre del usuario
    const usuarioNombre = audio.usuario_nombre && audio.usuario_apellido
        ? `${audio.usuario_nombre} ${audio.usuario_apellido}`
        : audio.usuario_nombre || 'N/A';
    
    // Formatear fecha de dictado
    const fechaDictado = audio.fecha_creacion 
        ? new Date(audio.fecha_creacion).toLocaleString('es-ES')
        : 'N/A';
    
    modalContent += `
        <tr>
            <td>...</td>
            <td>...</td>
            <td>...</td>
            <td>${usuarioNombre}</td>  // NUEVO
            <td>${fechaDictado}</td>  // NUEVO
            <td>...</td>
            <td>...</td>
            <td>...</td>
            <td>...</td>
            <td>...</td>
        </tr>
    `;
});
```

**Agregar información del estudio en el encabezado del modal** (líneas ~1745-1765):
```javascript
// Determinar título del modal y agregar información del estudio
let modalTitle = 'Audios';
let modalSubtitle = '';

if (estudioData && estudioData.estudio) {
    const estudio = estudioData.estudio;
    if (studyId && studyId !== 'null' && studyId !== null) {
        modalTitle = `Audios del Estudio #${studyId}`;
    }
    
    // Agregar información del estudio
    if (estudio.patient_id_pacs) {
        modalSubtitle += `ID Paciente: ${estudio.patient_id_pacs}`;
    }
    if (estudio.study_date) {
        const fechaEstudio = new Date(estudio.study_date).toLocaleDateString('es-ES');
        modalSubtitle += modalSubtitle ? ` | Fecha Estudio: ${fechaEstudio}` : `Fecha Estudio: ${fechaEstudio}`;
    }
}
```

### 6. Frontend - Modificar HTML (`ai-informes.html`)

**Ubicación**: Tabla `estudiosTable`, `<thead>` (líneas ~494-505)

**Cambios requeridos**:
1. Agregar `<th>` para "ID Paciente"
2. Modificar `<th>` "Fecha" a "Fecha Estudio"
3. NO agregar columnas de Usuario/Fecha Dictado (solo en el modal)

## Resumen de Archivos a Modificar

1. ✅ **`api/ai-informes.php`**
   - Modificar consulta SQL (agregar JOIN con usuarios, agregar campos)
   - Modificar procesamiento de datos (incluir información de usuario)

2. ✅ **`assets/js/ai-informes.js`**
   - Modificar transformación de datos en `dataSrc`
   - Agregar columnas en DataTable
   - Actualizar orden por defecto si es necesario

3. ✅ **`ai-informes.html`**
   - Agregar columnas en `<thead>`

## Consideraciones

1. **Múltiples audios por estudio**: 
   - En la **tabla principal**: Solo se muestra información del estudio (ID Paciente, Fecha Estudio)
   - En el **modal de audios**: Cada audio muestra su propio usuario y fecha de dictado (una fila por audio)

2. **Rendimiento**: El JOIN adicional con la tabla `usuarios` no debería afectar significativamente el rendimiento si hay índices apropiados.

3. **Compatibilidad**: Los cambios son aditivos, no deberían romper funcionalidad existente.

4. **Búsqueda**: El campo de búsqueda actual busca por "paciente, modalidad, descripción". Podríamos considerar agregar búsqueda por ID Paciente también.

5. **Información del estudio en el modal**: Se puede mostrar en el encabezado del modal o en una sección de información antes de la tabla de audios.

## Resumen de Cambios por Ubicación

### Tabla Principal (Lista de Estudios)
- ✅ Agregar columna "ID Paciente"
- ✅ Renombrar columna "Fecha" a "Fecha Estudio"
- ❌ NO agregar Usuario/Fecha Dictado (varían por audio)

### Modal "Audios del Estudio"
- ✅ Agregar columna "Usuario" (una por cada audio)
- ✅ Agregar columna "Fecha Dictado" (una por cada audio)
- ✅ Mostrar ID Paciente y Fecha Estudio en encabezado o sección de info

## Próximos Pasos

1. Implementar cambios en el backend (SQL y procesamiento)
2. Implementar cambios en el frontend:
   - Tabla principal (agregar ID Paciente, renombrar Fecha)
   - Modal de audios (agregar Usuario y Fecha Dictado por fila)
3. Actualizar HTML (thead de tabla principal)
4. Probar la funcionalidad
5. Verificar que la búsqueda funcione correctamente con los nuevos campos
