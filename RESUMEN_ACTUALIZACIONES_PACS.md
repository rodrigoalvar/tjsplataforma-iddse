# ✅ Implementación de Actualización de Informes en PACS - COMPLETADA

## 📋 Resumen

Se ha implementado la funcionalidad para **actualizar informes existentes en Orthanc PACS** cuando un informe que ya fue enviado es modificado y se vuelve a enviar.

## 🔄 Comportamiento Implementado

### Flujo Automático:

1. **Usuario modifica informe** que ya fue enviado a PACS
2. **Usuario hace clic en "Enviar a PACS"**
3. **Sistema detecta** que el informe tiene `pacs_instance_id` en BD
4. **Sistema elimina** la versión anterior de Orthanc automáticamente
5. **Sistema envía** la nueva versión actualizada
6. **Sistema actualiza** las referencias en BD con nuevos IDs

### Ventajas:

- ✅ **Automático**: Sin intervención manual
- ✅ **Limpio**: No deja versiones obsoletas en PACS
- ✅ **Robusto**: Maneja errores (404, errores de red)
- ✅ **Transparente**: El usuario solo ve "Informe actualizado exitosamente"

## 🔧 Cambios Implementados

### 1. Clase `OrthancPacsSender` (`api/OrthancPacsSender.php`)

#### Nuevos Métodos:

**`deleteInstance($instanceId)`**
- Elimina una instancia DICOM de Orthanc
- Endpoint: `DELETE /instances/{id}`
- Maneja 404 (instancia ya eliminada) como caso válido
- Retorna información detallada del resultado

**`deleteStudy($studyId)`**
- Elimina un estudio completo de Orthanc
- Endpoint: `DELETE /studies/{id}`
- Maneja 404 como caso válido
- Útil para eliminaciones masivas (no usado en actualizaciones de informes)

#### Mejoras:

- Soporte para método HTTP `DELETE` en `makeRequestWithRetry()`
- Manejo especial de 404 para DELETE (recurso ya no existe)

### 2. Endpoint `send-to-pacs.php` (`api/informes/send-to-pacs.php`)

#### Lógica Actualizada:

```php
// 1. Detectar si es actualización
$existingInstanceId = $informe['pacs_instance_id'] ?? null;
$isUpdate = !empty($existingInstanceId);

// 2. Si es actualización, eliminar versión anterior
if ($isUpdate) {
    $deleteResult = $pacsSender->deleteInstance($existingInstanceId);
    // Continúa aunque falle (puede que ya no exista)
}

// 3. Verificar duplicados SOLO si NO es actualización
// (porque si es actualización, ya eliminamos la anterior)

// 4. Enviar nueva versión normalmente

// 5. Actualizar referencias en BD
```

#### Respuestas Mejoradas:

- Indica si es actualización (`is_update: true/false`)
- Incluye ID de instancia anterior (`old_instance_id`)
- Mensaje diferenciado: "actualizado y reenviado" vs "enviado"

### 3. Frontend (`assets/js/informes-manager.js` y `assets/js/reports.js`)

#### Mensajes Mejorados:

**Gestión de Informes:**
- Primera vez: "✅ Informe enviado exitosamente"
- Actualización: "✅ Informe actualizado y reenviado exitosamente"

**Editor:**
- Primera vez: "¡Éxito! Informe enviado exitosamente..."
- Actualización: "Informe Actualizado. Informe actualizado y reenviado..."
- Detalles: Muestra Instance ID actual y anterior

## 📊 Casos de Uso

### Caso 1: Actualización Normal

```
Usuario modifica informe #123 (ya enviado a PACS)
  ↓
Sistema detecta pacs_instance_id = "old-instance-abc"
  ↓
Elimina "old-instance-abc" de Orthanc
  ↓
Genera PDF del informe actualizado
  ↓
Envía nueva versión → recibe "new-instance-xyz"
  ↓
Actualiza BD: pacs_instance_id = "new-instance-xyz"
  ↓
✅ Usuario ve: "Informe actualizado y reenviado exitosamente"
```

### Caso 2: Reenvío Después de Eliminación Manual

```
Informe #123 tiene pacs_instance_id = "instance-abc"
Pero fue eliminado manualmente de Orthanc
  ↓
Usuario hace clic en "Enviar a PACS"
  ↓
Sistema intenta eliminar "instance-abc"
  ↓
Orthanc retorna 404 (no encontrado)
  ↓
Sistema marca como "already_deleted" y continúa
  ↓
Envía nueva versión normalmente
  ↓
✅ Nueva versión enviada exitosamente
```

### Caso 3: Primera Vez (No es Actualización)

```
Informe #456 nunca fue enviado a PACS
  ↓
Usuario hace clic en "Enviar a PACS"
  ↓
Sistema no encuentra pacs_instance_id
  ↓
Verifica duplicados (como antes)
  ↓
Envía normalmente
  ↓
✅ Usuario ve: "Informe enviado exitosamente"
```

## 🛡️ Manejo de Errores

### Error al Eliminar Instancia Anterior

**Escenario:** Orthanc no responde o hay error de red al eliminar

**Comportamiento:**
- Se registra advertencia en logs
- Se continúa con el envío de la nueva versión
- Orthanc puede manejar múltiples instancias si es necesario

### Error al Enviar Nueva Versión

**Escenario:** Fallo después de eliminar la anterior

**Comportamiento:**
- Se lanza excepción
- La instancia anterior ya fue eliminada
- Se requiere intervención manual si se necesita restaurar

**Solución Recomendada:**
- Mantener backup de versiones anteriores en BD (mejora futura)
- O usar historial de versiones del informe

## 📝 Documentación Creada

1. **`docs/gestion-actualizaciones-informes-pacs.md`**
   - Documentación técnica completa
   - Flujos y casos de uso
   - Consideraciones y limitaciones

2. **`RESUMEN_ACTUALIZACIONES_PACS.md`** (este archivo)
   - Resumen ejecutivo
   - Cambios implementados

## ✅ Verificación

### Endpoints Orthanc Utilizados:

- ✅ `DELETE /instances/{id}` - Para eliminar instancia anterior
- ✅ `POST /tools/create-dicom` - Para enviar nueva versión
- ✅ `POST /tools/find` - Para verificar duplicados (solo si no es actualización)

### Campos de Base de Datos:

- ✅ `pacs_instance_id` - ID de la instancia DICOM en Orthanc
- ✅ `pacs_study_id` - ID del estudio en Orthanc
- ✅ `fecha_enviado_pacs` - Timestamp del último envío

## 🎯 Resultado Final

El sistema ahora maneja automáticamente:

1. ✅ **Envío inicial**: Funciona como antes
2. ✅ **Actualización**: Elimina anterior y envía nueva
3. ✅ **Reenvío después de eliminación manual**: Detecta y maneja correctamente
4. ✅ **Mensajes diferenciados**: Usuario sabe si es nuevo envío o actualización

**Estado:** ✅ **COMPLETO Y FUNCIONAL**

---

**Fecha:** Enero 2025  
**Versión:** 1.0.0

