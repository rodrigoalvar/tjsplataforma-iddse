# Gestión de Actualizaciones de Informes en PACS

## 📋 Resumen

Cuando un informe que ya fue enviado a Orthanc PACS es modificado y se vuelve a enviar, el sistema automáticamente:

1. **Detecta** que el informe ya tiene una versión en PACS
2. **Elimina** la versión anterior de Orthanc
3. **Envía** la nueva versión actualizada
4. **Actualiza** las referencias en la base de datos

## 🔄 Flujo de Actualización

### Paso 1: Detección

El sistema verifica si el informe tiene `pacs_instance_id` en la base de datos:

```sql
SELECT pacs_instance_id, pacs_study_id 
FROM informes 
WHERE id = ?
```

Si `pacs_instance_id` existe → **Es una actualización**

### Paso 2: Eliminación de Versión Anterior

Si es una actualización:

```php
// Eliminar instancia anterior de Orthanc
$deleteResult = $pacsSender->deleteInstance($existingInstanceId);

// Si falla, registrar advertencia pero continuar
// (puede que ya fue eliminado manualmente)
```

**Endpoints Orthanc utilizados:**
- `DELETE /instances/{instance_id}` - Elimina la instancia DICOM

### Paso 3: Verificación de Duplicados (Solo para nuevos envíos)

Si **NO** es una actualización, se verifica duplicados antes de enviar.

Si **ES** una actualización, se omite la verificación de duplicados porque:
- Ya eliminamos la versión anterior
- Es el mismo informe, no un duplicado

### Paso 4: Envío de Nueva Versión

Se genera el PDF desde el HTML actualizado y se envía a Orthanc normalmente.

### Paso 5: Actualización de Referencias

Se actualizan los campos en la base de datos:

```sql
UPDATE informes 
SET fecha_enviado_pacs = NOW(),
    pacs_instance_id = ?,
    pacs_study_id = ?
WHERE id = ?
```

## 📊 Respuestas de la API

### Actualización Exitosa

```json
{
    "success": true,
    "message": "Informe actualizado y reenviado exitosamente a Orthanc PACS",
    "is_update": true,
    "old_instance_id": "abc123-def456-ghi789",
    "data": {
        "instance_id": "xyz987-wvu654-tsr321",
        "study_id": "study-new-id",
        "file_size_mb": 2.5
    }
}
```

### Nuevo Envío (Primera Vez)

```json
{
    "success": true,
    "message": "Informe enviado exitosamente a Orthanc PACS",
    "is_update": false,
    "old_instance_id": null,
    "data": {
        "instance_id": "xyz987-wvu654-tsr321",
        "study_id": "study-id",
        "file_size_mb": 2.5
    }
}
```

## 🛡️ Manejo de Errores

### Caso 1: Instancia Anterior No Encontrada

Si la instancia anterior ya fue eliminada manualmente o no existe:

```php
// El sistema detecta error 404
// Lo marca como "already_deleted" y continúa
// Esto permite enviar la nueva versión sin problemas
```

**Comportamiento:** ✅ Continúa con el envío de la nueva versión

### Caso 2: Error al Eliminar Instancia

Si hay un error al eliminar pero no es 404:

```php
// Se registra advertencia en logs
// Se continúa con el envío de la nueva versión
// (Orthanc puede manejar múltiples instancias)
```

**Comportamiento:** ⚠️ Registra advertencia pero continúa

### Caso 3: Error al Enviar Nueva Versión

Si falla el envío de la nueva versión después de eliminar la anterior:

```php
// Se lanza excepción
// La instancia anterior ya fue eliminada
// Se requiere intervención manual si es necesario
```

**Comportamiento:** ❌ Error crítico, requiere atención

## 💡 Consideraciones Importantes

### Ventajas del Enfoque Actual

1. **✅ Automático**: No requiere intervención manual
2. **✅ Limpio**: No deja versiones obsoletas en PACS
3. **✅ Rastreable**: Mantiene referencias en BD
4. **✅ Robusto**: Maneja casos edge (404, errores)

### Limitaciones

1. **No mantiene historial en PACS**: La versión anterior se elimina completamente
2. **No permite múltiples versiones**: Solo mantiene la última versión
3. **Depende de Orthanc**: Si Orthanc no responde, no se puede actualizar

### Alternativas Consideradas

#### Opción A: Actual Elim (Recomendada) ✅
- Elimina versión anterior antes de enviar nueva
- Mantiene solo la última versión en PACS
- Simple y directo

#### Opción B: Mantener Múltiples Versiones
- Enviar nueva versión sin eliminar anterior
- Usar tags DICOM diferentes (Series Number, Instance Number)
- **Desventaja**: PACS tendría múltiples versiones del mismo informe

#### Opción C: Soft Delete (Marcar como inactivo)
- No eliminar, solo marcar como "obsoleto" con tags DICOM
- **Desventaja**: Más complejo, requiere soporte de tags custom

## 🔧 Implementación Técnica

### Métodos Agregados a `OrthancPacsSender`

#### `deleteInstance($instanceId)`

```php
/**
 * Elimina una instancia DICOM de Orthanc
 * 
 * @param string $instanceId ID de la instancia
 * @return array Resultado
 */
public function deleteInstance($instanceId)
```

**Endpoints Orthanc:**
- `DELETE /instances/{id}`

#### `deleteStudy($studyId)`

```php
/**
 * Elimina un estudio completo de Orthanc
 * 
 * @param string $studyId ID del estudio
 * @return array Resultado
 */
public function deleteStudy($studyId)
```

**Endpoints Orthanc:**
- `DELETE /studies/{id}`

### Lógica en `send-to-pacs.php`

```php
// 1. Verificar si ya existe versión en PACS
$existingInstanceId = $informe['pacs_instance_id'] ?? null;
$isUpdate = !empty($existingInstanceId);

// 2. Si es actualización, eliminar versión anterior
if ($isUpdate) {
    $deleteResult = $pacsSender->deleteInstance($existingInstanceId);
    // Continuar aunque falle (puede que ya no exista)
}

// 3. Verificar duplicados solo si NO es actualización
if ($checkDuplicates && !$isUpdate) {
    // Verificar duplicados...
}

// 4. Enviar nueva versión
$result = $pacsSender->sendPdfAsDicom($tempPdfPath, $dicomTags);

// 5. Actualizar referencias en BD
UPDATE informes SET pacs_instance_id = ?, pacs_study_id = ? ...
```

## 📝 Ejemplos de Uso

### Escenario 1: Actualizar Informe Existente

```javascript
// Usuario modifica informe #123 que ya fue enviado a PACS
// Instance ID anterior: "old-instance-123"

// Usuario hace clic en "Enviar a PACS"
InformesManager.sendToPacs(123);

// Sistema detecta pacs_instance_id = "old-instance-123"
// 1. Elimina "old-instance-123" de Orthanc
// 2. Genera PDF del informe actualizado
// 3. Envía nueva versión a Orthanc
// 4. Obtiene nuevo Instance ID: "new-instance-456"
// 5. Actualiza BD con nuevo Instance ID

// Resultado: Solo la versión actualizada existe en PACS
```

### Escenario 2: Reenviar Informe Eliminado Manualmente

```javascript
// Informe #123 tiene pacs_instance_id = "instance-123"
// Pero la instancia fue eliminada manualmente de Orthanc

// Usuario hace clic en "Enviar a PACS"
InformesManager.sendToPacs(123);

// Sistema intenta eliminar "instance-123"
// Orthanc retorna 404 (no encontrado)
// Sistema marca como "already_deleted" y continúa
// Envía nueva versión normalmente

// Resultado: Nueva versión enviada exitosamente
```

## 🎯 Indicadores Visuales

### En la Lista de Informes

El botón "Enviar a PACS" muestra comportamiento diferente:

- **Primera vez**: Envía normalmente
- **Actualización**: Elimina anterior y envía nueva

Los mensajes indican el tipo de operación:
- ✅ "Informe enviado exitosamente" (primera vez)
- ✅ "Informe actualizado y reenviado exitosamente" (actualización)

### En el Editor

El mensaje de estado muestra:
- Si es actualización: "Informe Actualizado"
- Si es nuevo: "¡Éxito!"
- Detalles: Instance ID actual y anterior (si aplica)

## 🔒 Seguridad y Validación

### Validaciones Implementadas

1. **Autenticación**: Requiere sesión válida
2. **Permisos**: Usuario solo puede enviar sus propios informes (o todos si tiene permiso)
3. **Existencia**: Verifica que el informe exista antes de procesar
4. **Contenido**: Verifica que el informe tenga contenido HTML válido

### Manejo de Casos Edge

1. **Instancia no encontrada (404)**: Continúa con envío
2. **Error de red**: Reintentos con backoff exponencial
3. **Error al generar PDF**: Falla antes de tocar Orthanc
4. **Error al enviar nueva versión**: La anterior ya fue eliminada, requiere acción manual

## 📊 Logs y Auditoría

El sistema registra todos los eventos:

```php
// Logs de actualización
error_log("Informe #123 ya fue enviado a PACS. Eliminando versión anterior (Instance: abc123)");
error_log("Versión anterior eliminada exitosamente. Already deleted: false");
error_log("Referencias PACS actualizadas en BD para informe #123");
```

## 🚀 Mejoras Futuras (Opcional)

1. **Historial de Versiones en BD**: Tabla `informes_pacs_history` para mantener registro de todos los envíos
2. **Confirmación Específica para Actualizaciones**: Diferente mensaje cuando es actualización
3. **Rollback**: Capacidad de restaurar versión anterior si hay errores
4. **Notificaciones**: Email cuando se actualiza informe en PACS

---

**Versión:** 1.0.0  
**Última actualización:** Enero 2025  
**Estado:** ✅ Implementado y Funcional

