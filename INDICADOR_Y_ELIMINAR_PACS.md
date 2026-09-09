# ✅ Indicador y Botón Eliminar de PACS - Implementado

## 🎯 Funcionalidad Agregada

Se agregaron dos características:

1. ✅ **Indicador visual** de que el informe ya se envió a PACS
2. ✅ **Botón para eliminar** el informe del PACS y limpiar campos

---

## 📊 Indicador Visual

### Ubicación:
- Aparece **antes de los botones de acción** en cada fila de informe
- Badge verde con texto "En PACS" y icono de check

### Visualización:
```
[✅ En PACS] [Ver] [Editar] [Eliminar de PACS] [Eliminar]
```

### Condición:
El indicador aparece si el informe tiene:
- `pacs_series_id` con valor (no NULL, no vacío)
- **O** `pacs_instance_id` con valor (fallback)

**Prioridad:**
1. ✅ Si tiene `pacs_series_id` → Muestra indicador
2. ✅ Si tiene `pacs_instance_id` → Muestra indicador
3. ❌ Si ambos están vacíos → NO muestra indicador

---

## 🔘 Botones de Acción

### Botón "Enviar a PACS" (Verde):
- Aparece cuando el informe **NO** está en PACS
- Icono: `fa-cloud-upload-alt`
- Acción: Enviar informe a Orthanc PACS

### Botón "Eliminar de PACS" (Amarillo):
- Aparece cuando el informe **SÍ** está en PACS
- Icono: `fa-cloud-download-alt`
- Acción: Eliminar serie/instancia de Orthanc y limpiar campos

**Lógica:**
```javascript
${(informe.pacs_series_id || informe.pacs_instance_id) ? `
    // Muestra botón "Eliminar de PACS"
` : `
    // Muestra botón "Enviar a PACS"
`}
```

---

## 🗑️ Función `removeFromPacs()`

### Ubicación:
- Archivo: `assets/js/informes-manager.js`
- Método: `removeFromPacs(informeId)`

### Flujo:

1. **Confirmar eliminación:**
   - Muestra mensaje de confirmación
   - Usuario debe confirmar

2. **Llamar API:**
   - POST a `api/informes/remove-from-pacs.php`
   - Envía: `{ informe_id: X }`

3. **Recargar informes:**
   - Después de eliminar, recarga la lista
   - El indicador desaparece automáticamente
   - El botón cambia a "Enviar a PACS"

---

## 🔧 Endpoint: `remove-from-pacs.php`

### Funcionalidad:

1. **Validar sesión y permisos**
   - Verifica token de sesión
   - Verifica permisos del usuario

2. **Obtener informe:**
   - Lee `pacs_series_id` y `pacs_instance_id` de BD
   - Verifica que al menos uno tenga valor

3. **Eliminar de Orthanc:**
   - **Prioridad 1:** Eliminar por `pacs_series_id` (elimina toda la serie)
   - **Prioridad 2:** Eliminar por `pacs_instance_id` (elimina solo la instancia)

4. **Limpiar campos en BD:**
   - `pacs_instance_id = NULL`
   - `pacs_study_id = NULL`
   - `pacs_series_id = NULL`
   - `fecha_enviado_pacs = NULL`

5. **Respuesta:**
   ```json
   {
     "success": true,
     "message": "Serie eliminada exitosamente de Orthanc PACS y campos limpiados",
     "deleted_by": "series",
     "data": {
       "informe_id": 123,
       "series_id": "...",
       "instance_id": "..."
     }
   }
   ```

---

## 📋 Cambios Realizados

### 1. Frontend (`informes-manager.js`):

**Renderizado:**
- ✅ Agregado indicador visual "En PACS" cuando hay `pacs_series_id` o `pacs_instance_id`
- ✅ Botón "Eliminar de PACS" aparece cuando está en PACS
- ✅ Botón "Enviar a PACS" aparece cuando NO está en PACS

**Función:**
- ✅ Agregada función `removeFromPacs(informeId)`
- ✅ Confirmación antes de eliminar
- ✅ Recarga automática después de eliminar

### 2. Backend (`remove-from-pacs.php`):

**Funcionalidad:**
- ✅ Validación de sesión y permisos
- ✅ Obtención de campos PACS del informe
- ✅ Eliminación de serie/instancia de Orthanc
- ✅ Limpieza de campos PACS en BD

### 3. API List (`list.php`):

**Campos agregados:**
- ✅ `pacs_instance_id` incluido en SELECT
- ✅ `pacs_study_id` incluido en SELECT
- ✅ `pacs_series_id` incluido en SELECT
- ✅ `fecha_enviado_pacs` incluido en SELECT

### 4. Estilos (`informes-manager.html`):

**CSS agregado:**
- ✅ Estilos para badge "En PACS"
- ✅ Animación pulse para destacar el indicador

---

## 🎨 Visualización

### Antes de Enviar:
```
[Ver] [Editar] [Enviar a PACS] [Eliminar]
              ↑ Botón verde
```

### Después de Enviar:
```
[✅ En PACS] [Ver] [Editar] [Eliminar de PACS] [Eliminar]
   ↑ Badge     ↑ Botón amarillo
```

---

## 🔍 Cómo Funciona

### Verificación de Estado:
```javascript
// Verificar si el informe está en PACS
const estaEnPacs = informe.pacs_series_id || informe.pacs_instance_id;

if (estaEnPacs) {
    // Mostrar indicador y botón "Eliminar de PACS"
} else {
    // Mostrar botón "Enviar a PACS"
}
```

### Eliminación:
1. Click en "Eliminar de PACS"
2. Confirmar eliminación
3. API elimina de Orthanc (prioridad: series_id > instance_id)
4. API limpia campos en BD
5. Interfaz se recarga automáticamente
6. Indicador desaparece y botón cambia a "Enviar a PACS"

---

## 📊 Flujo Completo

### Enviar Informe:
```
1. Usuario click "Enviar a PACS"
2. Informe se envía a Orthanc
3. Orthanc devuelve series_id, instance_id, study_id
4. Se guardan en BD: pacs_series_id, pacs_instance_id, pacs_study_id
5. Interfaz se recarga
6. Aparece indicador "En PACS" ✅
7. Botón cambia a "Eliminar de PACS" 🗑️
```

### Eliminar de PACS:
```
1. Usuario click "Eliminar de PACS"
2. Confirmar eliminación
3. API elimina serie/instancia de Orthanc
4. API limpia campos en BD (NULL)
5. Interfaz se recarga
6. Indicador desaparece ❌
7. Botón cambia a "Enviar a PACS" ✅
```

---

## ✅ Verificación

### Verificar que funciona:

1. **Enviar un informe a PACS:**
   - Click "Enviar a PACS"
   - Debe aparecer badge "En PACS" ✅
   - Botón debe cambiar a "Eliminar de PACS" 🗑️

2. **Eliminar de PACS:**
   - Click "Eliminar de PACS"
   - Confirmar eliminación
   - Badge debe desaparecer ❌
   - Botón debe cambiar a "Enviar a PACS" ✅

3. **Verificar en BD:**
   ```sql
   SELECT id, pacs_series_id, pacs_instance_id, pacs_study_id 
   FROM informes 
   WHERE id = ?;
   ```
   - Después de enviar: Debe tener valores
   - Después de eliminar: Debe ser NULL

---

## 📝 Archivos Modificados/Creados

### Modificados:
1. ✅ `assets/js/informes-manager.js`
   - Agregado indicador visual
   - Agregada función `removeFromPacs()`
   - Lógica condicional de botones

2. ✅ `api/informes/list.php`
   - Agregados campos PACS al SELECT

3. ✅ `components/informes-manager.html`
   - Agregados estilos CSS para indicador

### Creados:
1. ✅ `api/informes/remove-from-pacs.php`
   - Endpoint para eliminar de PACS y limpiar campos

---

## 🎯 Resumen

### ✅ Indicador Visual:
- Badge verde "En PACS" cuando el informe está en PACS
- Aparece si `pacs_series_id` o `pacs_instance_id` tienen valor

### ✅ Botón Eliminar:
- Botón amarillo "Eliminar de PACS" cuando está en PACS
- Elimina serie/instancia de Orthanc
- Limpia campos PACS en BD
- Recarga interfaz automáticamente

### ✅ Botón Enviar:
- Botón verde "Enviar a PACS" cuando NO está en PACS
- Reemplaza botón "Eliminar de PACS" cuando se elimina

---

## 🚀 Próximos Pasos

1. **Verificar que las columnas PACS existen:**
   - Ejecutar: `database/crear_todas_columnas_pacs.sql`

2. **Probar funcionalidad:**
   - Enviar un informe → Verificar que aparece indicador
   - Eliminar de PACS → Verificar que desaparece indicador

3. **Verificar en BD:**
   - Después de enviar: Campos tienen valores
   - Después de eliminar: Campos son NULL

---

_Última actualización: 31 de Octubre, 2025_  
_Funcionalidad completa implementada y lista para usar_

