# Análisis: Modificación Asíncrona de Estudios PACS

## 📋 Situación Actual (Síncrona)

### Flujo Actual
```
Frontend → POST /api/pacs-manager/edit.php
              ↓
          Backend PHP (espera hasta 10 minutos)
              ↓
          OrthancPacsSender::updateStudyTags()
              ↓
          POST /studies/{id}/modify (espera hasta 5 minutos)
              ↓
          Espera respuesta completa de Orthanc
              ↓
          Si se creó nuevo estudio → Elimina original
              ↓
          Retorna resultado al frontend
              ↓
          Frontend actualiza UI
```

### Problemas Identificados
1. **Timeouts**: Nginx (60s) < PHP (600s) < Frontend (600s) → Desalineación
2. **Experiencia de usuario**: Usuario bloqueado esperando respuesta
3. **Recursos**: Conexión HTTP mantenida durante toda la operación
4. **Escalabilidad**: No permite múltiples modificaciones simultáneas eficientemente
5. **Errores**: Si falla a los 8 minutos, el usuario pierde todo el tiempo de espera

---

## 🎯 Solución Propuesta (Asíncrona con Jobs)

### Flujo Propuesto
```
Frontend → POST /api/pacs-manager/edit.php
              ↓
          Backend PHP (inmediato, < 5 segundos)
              ↓
          OrthancPacsSender::updateStudyTagsAsync()
              ↓
          POST /studies/{id}/modify?Synchronous=false
              ↓
          Orthanc retorna Job ID inmediatamente
              ↓
          Backend retorna {success: true, job_id: "...", study_id: "..."}
              ↓
          Frontend cierra modal, muestra notificación
              ↓
          Frontend hace polling a GET /api/pacs-manager/job-status.php?job_id=...
              ↓
          Backend consulta GET /jobs/{jobId} en Orthanc
              ↓
          Cuando job.State === "Success" → Obtiene nuevo study_id
              ↓
          Si hay nuevo estudio → Elimina original (asíncrono también)
              ↓
          Frontend actualiza lista cuando job completa
```

---

## 🔍 Análisis Técnico

### 1. Verificación de Compatibilidad con Orthanc

#### Endpoints a Verificar:
- ✅ `/studies/{id}/modify` - ¿Soporta `Synchronous=false`?
- ✅ `/patients/{id}/modify` - ¿Soporta `Synchronous=false`?
- ✅ `/jobs/{jobId}` - Ya existe en el código (método `waitForJobCompletion`)

#### Formato Esperado de Respuesta con `Synchronous=false`:
```json
{
  "ID": "11541b16-e368-41cf-a8e9-3acf4061d238",
  "Path": "/jobs/11541b16-e368-41cf-a8e9-3acf4061d238"
}
```

#### Formato de Estado del Job:
```json
{
  "ID": "11541b16-e368-41cf-a8e9-3acf4061d238",
  "State": "Running" | "Success" | "Failure" | "Paused",
  "Progress": 42,
  "Content": {
    "Resources": ["/studies/abc123..."]  // Nuevo study_id aquí
  },
  "ErrorDescription": "..."
}
```

### 2. Cambios Necesarios por Capa

#### A. Backend PHP - `OrthancPacsSender.php`

**Nuevo método: `updateStudyTagsAsync()`**
- Similar a `updateStudyTags()` pero:
  - Agrega `?Synchronous=false` al endpoint de modificación
  - O incluye `"Synchronous": false` en el payload (según documentación de Orthanc)
  - No espera la respuesta completa
  - Retorna inmediatamente con `job_id`

**Modificaciones en `updateStudyTags()`:**
- Opción 1: Agregar parámetro `$async = false` para mantener compatibilidad
- Opción 2: Crear método separado `updateStudyTagsAsync()`

**Nuevo método: `getJobStatus($jobId)`**
- Consulta `GET /jobs/{jobId}` en Orthanc
- Retorna estado, progreso, y recursos creados

**Nuevo método: `processJobCompletion($jobId, $originalStudyId)`**
- Cuando job completa con éxito:
  - Extrae nuevo `study_id` de `Content.Resources`
  - Si hay nuevo estudio diferente al original → Elimina original
  - Retorna resultado final

#### B. Backend PHP - `api/pacs-manager/edit.php`

**Modificaciones:**
- Cambiar llamada de `updateStudyTags()` a `updateStudyTagsAsync()`
- Retornar estructura:
  ```php
  {
    'success': true,
    'job_id': '...',
    'study_id': '...',  // Original
    'message': 'Modificación iniciada. Procesando en segundo plano...',
    'async': true
  }
  ```

**Nuevo endpoint: `api/pacs-manager/job-status.php`**
- Recibe `job_id` y `study_id` (original)
- Consulta estado del job en Orthanc
- Si job completó:
  - Llama a `processJobCompletion()`
  - Retorna resultado final con nuevo `study_id` si aplica
- Si job aún corriendo:
  - Retorna estado y progreso

#### C. Frontend - `pacs-manager.js`

**Modificaciones en `saveStudy()`:**
- Detectar si respuesta tiene `async: true` y `job_id`
- Si es asíncrono:
  - Cerrar modal inmediatamente
  - Mostrar notificación: "Modificación iniciada. Procesando en segundo plano..."
  - Iniciar polling a `job-status.php`
- Si es síncrono (fallback):
  - Mantener comportamiento actual

**Nuevo método: `pollJobStatus(jobId, originalStudyId)`**
- Polling cada 5-10 segundos a `GET /api/pacs-manager/job-status.php?job_id=...&study_id=...`
- Mostrar progreso si está disponible
- Cuando `job_state === "Success"`:
  - Actualizar lista de estudios
  - Mostrar notificación de éxito
- Cuando `job_state === "Failure"`:
  - Mostrar error
  - Detener polling

**UI/UX:**
- Notificación persistente con progreso (opcional)
- Botón para cancelar polling (opcional)
- Indicador visual en la lista de estudios en proceso

---

## ⚠️ Consideraciones y Desafíos

### 1. Eliminación del Estudio Original

**Problema:**
- `/studies/{id}/modify` crea un nuevo estudio
- El original debe eliminarse para evitar duplicados
- La eliminación también puede ser lenta con `delayeddeletion`

**Solución:**
- Opción A: Eliminar original también de forma asíncrona (nuevo job)
- Opción B: Eliminar original inmediatamente después de iniciar modificación (riesgo si falla)
- Opción C: Marcar original para eliminación y hacerlo en background job separado

**Recomendación:** Opción A - Job asíncrono para eliminación

### 2. Manejo de `/patients/{id}/modify`

**Problema:**
- Este endpoint puede no crear nuevo estudio (solo modifica tags)
- ¿Soporta `Synchronous=false`?

**Solución:**
- Verificar documentación de Orthanc
- Si no soporta, mantener síncrono para este caso (es rápido)
- Si soporta, implementar también asíncrono

### 3. Persistencia del Estado del Job

**Problema:**
- Si el usuario cierra el navegador, ¿cómo sabe que el job completó?
- ¿Dónde guardar el estado del job?

**Solución:**
- Opción A: Guardar `job_id` en localStorage
- Opción B: Al recargar página, verificar jobs pendientes en backend
- Opción C: Notificaciones push (más complejo)

**Recomendación:** Opción A + B combinados

### 4. Timeout del Polling

**Problema:**
- ¿Cuánto tiempo debe hacer polling el frontend?
- ¿Qué pasa si el job tarda más de lo esperado?

**Solución:**
- Polling máximo: 15-20 minutos
- Si excede, mostrar mensaje: "La modificación está tomando más tiempo del esperado. Se completará en segundo plano."
- Permitir al usuario continuar trabajando

### 5. Múltiples Modificaciones Simultáneas

**Problema:**
- Usuario puede iniciar múltiples modificaciones
- Cada una tiene su propio job

**Solución:**
- Mantener array de jobs activos en frontend
- Polling paralelo para todos los jobs activos
- UI muestra estado de cada modificación

### 6. Obtención del Nuevo Study ID

**Problema:**
- Cuando job completa, ¿cómo obtener el nuevo `study_id`?
- La estructura de `Content.Resources` puede variar

**Solución:**
- Analizar respuesta del job cuando `State === "Success"`
- Buscar en `Content.Resources`, `Content.ModifiedResources`, o `Resources`
- Extraer ID del path `/studies/{id}`
- Validar que el nuevo ID sea diferente del original

---

## 📊 Comparación: Síncrono vs Asíncrono

| Aspecto | Síncrono (Actual) | Asíncrono (Propuesto) |
|---------|-------------------|----------------------|
| **Tiempo de respuesta inicial** | 5-10 minutos | < 5 segundos |
| **Experiencia de usuario** | Bloqueado esperando | Puede continuar trabajando |
| **Timeouts** | Múltiples capas (Nginx, PHP, cURL) | Solo polling corto |
| **Escalabilidad** | Limitada (1 conexión larga) | Alta (múltiples jobs) |
| **Manejo de errores** | Todo o nada | Puede reintentar polling |
| **Complejidad** | Baja | Media-Alta |
| **Recursos del servidor** | Conexión HTTP mantenida | Conexiones cortas |

---

## 🎯 Plan de Implementación (si se aprueba)

### Fase 1: Backend - Modificación Asíncrona
1. Crear `updateStudyTagsAsync()` en `OrthancPacsSender.php`
2. Modificar llamadas a usar `Synchronous=false`
3. Crear `getJobStatus()` y `processJobCompletion()`
4. Crear endpoint `job-status.php`

### Fase 2: Frontend - Polling
1. Modificar `saveStudy()` para detectar modo asíncrono
2. Crear `pollJobStatus()`
3. Implementar UI de progreso (opcional)

### Fase 3: Eliminación Asíncrona
1. Implementar eliminación del original también asíncrona
2. Manejar caso donde modificación completa pero eliminación falla

### Fase 4: Testing y Refinamiento
1. Probar con estudios grandes
2. Probar múltiples modificaciones simultáneas
3. Probar casos de error
4. Optimizar intervalos de polling

---

## ❓ Preguntas para Decidir

1. **¿Orthanc soporta `Synchronous=false` en `/studies/{id}/modify`?**
   - Necesitamos verificar documentación o probar

2. **¿Mantener compatibilidad con modo síncrono?**
   - Para `/patients/{id}/modify` que es rápido
   - Como fallback si asíncrono falla

3. **¿Mostrar progreso en UI?**
   - Barra de progreso vs solo notificación
   - Depende de si Orthanc provee `Progress` en job

4. **¿Persistir jobs en base de datos?**
   - Para recuperar estado después de recargar página
   - O solo usar localStorage

5. **¿Implementar cancelación de jobs?**
   - `POST /jobs/{jobId}/cancel` en Orthanc
   - UI para cancelar modificación en progreso

---

## ✅ Recomendación Final

**SÍ, proceder con la implementación asíncrona** porque:
- ✅ Mejora significativamente la experiencia de usuario
- ✅ Resuelve problemas de timeout
- ✅ Permite escalabilidad
- ✅ El código ya tiene base (`waitForJobCompletion` existe)
- ⚠️ Requiere testing cuidadoso de la respuesta de Orthanc

**Próximos pasos:**
1. Verificar si Orthanc soporta `Synchronous=false` (probar con curl)
2. Si sí → Implementar Fase 1 y 2
3. Si no → Evaluar usar `/tools/modify` que definitivamente crea jobs
