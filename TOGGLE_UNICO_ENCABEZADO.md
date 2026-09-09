# ✅ Toggle Switch Único en Encabezado Implementado

## 🎯 Cambio Realizado

Se movió el toggle switch de **cada fila individual** a **un único toggle switch en el encabezado** de la tabla, junto a la columna "Acciones".

---

## 📍 Nueva Ubicación

### Antes:
```
[Ver] [Editar] [📄 PDF ──●── 🖼️ PNG] [Enviar] [Eliminar]  ← Cada fila tenía su toggle
[Ver] [Editar] [📄 PDF ──●── 🖼️ PNG] [Enviar] [Eliminar]
[Ver] [Editar] [📄 PDF ──●── 🖼️ PNG] [Enviar] [Eliminar]
```

### Ahora:
```
┌─────────────────────────────────────────────────────────┐
│ Título | Paciente | ... | Acciones [📄 PDF ──●── 🖼️ PNG]│ ← Toggle único en encabezado
├─────────────────────────────────────────────────────────┤
│ [Ver] [Editar] [Enviar] [Eliminar]                      │ ← Filas sin toggle
│ [Ver] [Editar] [Enviar] [Eliminar]                      │
│ [Ver] [Editar] [Enviar] [Eliminar]                      │
└─────────────────────────────────────────────────────────┘
```

---

## ✨ Ventajas del Nuevo Diseño

| Aspecto | Antes (Toggle por fila) | Ahora (Toggle único) |
|---------|-------------------------|----------------------|
| **Espacio** | ❌ Ocupa mucho espacio | ✅ Ocupa poco espacio |
| **Claridad** | ⚠️ Muchos toggles | ✅ Un solo toggle visible |
| **Uso** | ⚠️ Puede confundir | ✅ Más claro e intuitivo |
| **Rendimiento** | ⚠️ Muchos elementos DOM | ✅ Menos elementos DOM |
| **UX** | ⚠️ Cambio por informe | ✅ Formato global por sesión |

---

## 🔧 Funcionamiento

### 1. Ubicación
- ✅ Toggle switch en el encabezado de la tabla
- ✅ Columna "Acciones" con el toggle a la derecha
- ✅ Visible siempre (sticky header)

### 2. Inicialización
- ✅ Al cargar los informes, se inicializa el toggle
- ✅ Usa la configuración global como valor inicial
- ✅ Si no hay configuración, queda en PDF (izquierda)

### 3. Al Enviar Informes
- ✅ Todos los envíos usan el mismo formato del toggle
- ✅ Si toggle está a la izquierda → PDF
- ✅ Si toggle está a la derecha → PNG/JPG
- ✅ Formato aplica a todos los envíos de esa sesión

---

## 📋 Cambios Realizados

### Archivos Modificados:

**1. `components/informes-manager.html`**
- ✅ Agregado toggle switch en `<th>Acciones</th>` del encabezado
- ✅ Estilos CSS ajustados para el encabezado
- ✅ ID del toggle: `pacs-format-global`

**2. `assets/js/informes-manager.js`**
- ✅ Removido toggle switch de cada fila individual
- ✅ Función `sendToPacs()` ahora usa `pacs-format-global`
- ✅ Función `initPacsFormatToggles()` actualizada para un solo toggle

---

## 💻 Código del Toggle

### HTML (Encabezado):
```html
<th style="min-width: 250px;">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <span>Acciones</span>
        <div class="pacs-format-toggle-container">
            <label class="pacs-format-toggle">
                <input type="checkbox" class="pacs-format-switch" id="pacs-format-global">
                <span class="pacs-format-slider">
                    <span class="pacs-format-label-left">📄 PDF</span>
                    <span class="pacs-format-label-right">🖼️ PNG</span>
                </span>
            </label>
        </div>
    </div>
</th>
```

### JavaScript:
```javascript
// Obtener valor del toggle global
const toggleGlobal = document.getElementById('pacs-format-global');
const formato = toggleGlobal.checked ? 'jpg' : 'pdf';
```

---

## 🎯 Cómo Usar

### Establecer Formato:
1. Ver el toggle switch en el encabezado de la tabla
2. Click para cambiar entre PDF (izquierda) y PNG (derecha)
3. El formato se aplica a todos los envíos posteriores

### Enviar Informes:
1. Establecer el formato deseado con el toggle
2. Click en "Enviar a PACS" en cualquier informe
3. Todos los envíos usan el mismo formato del toggle

---

## 🎨 Visual

### Encabezado de la Tabla:
```
┌──────────────────────────────────────────────────────────────┐
│ Título  │ Paciente │ Modalidad │ Estado │ ... │ Acciones     │
│         │          │           │        │     │ [📄 PDF ──●] │
└──────────────────────────────────────────────────────────────┘
                     ↑ Toggle Switch Global
```

### Estados:
- **PDF (Izquierda - Verde):** Todos los envíos serán PDF
- **PNG (Derecha - Naranja):** Todos los envíos serán PNG/JPG

---

## ✨ Características

- ✅ **Espacio Optimizado:** Solo un toggle en lugar de uno por fila
- ✅ **Claridad Visual:** Formato visible siempre en el encabezado
- ✅ **Formato Global:** Un solo formato para todos los envíos
- ✅ **Inicialización Automática:** Usa configuración global
- ✅ **Fácil de Cambiar:** Un click para cambiar formato
- ✅ **Visible Siempre:** En el encabezado sticky de la tabla

---

## 🔍 Debugging

### Ver en Consola:
```javascript
// Al inicializar
✅ Toggle switch global inicializado con formato: pdf

// Al enviar
📄 Formato seleccionado desde toggle global: pdf
// o
📄 Formato seleccionado desde toggle global: jpg
```

### Verificar Estado:
```javascript
const toggle = document.getElementById('pacs-format-global');
console.log(toggle.checked); // true = PNG, false = PDF
```

---

## 📊 Flujo Actualizado

```
1. Usuario abre Informes Manager
   ↓
2. Sistema carga informes y renderiza tabla
   ↓
3. Toggle switch único en encabezado (inicializado en PDF)
   ↓
4. initPacsFormatToggles() carga configuración global
   ↓
5. Aplica configuración global al toggle del encabezado
   ↓
6. Usuario puede cambiar el toggle (aplica a todos los envíos)
   ↓
7. Usuario click "Enviar a PACS" en cualquier informe
   ↓
8. sendToPacs() lee valor del toggle global del encabezado
   ↓
9. Envía con el formato seleccionado (aplica a todos)
```

---

## ✅ Resumen de Cambios

### Removido:
- ❌ Toggle switch individual por cada fila de informe

### Agregado:
- ✅ Toggle switch único en encabezado de la tabla
- ✅ Posicionado junto a "Acciones"
- ✅ Visible siempre (sticky header)

### Modificado:
- ✅ `sendToPacs()` usa `pacs-format-global`
- ✅ `initPacsFormatToggles()` inicializa solo un toggle
- ✅ Estilos CSS ajustados para encabezado

---

## 🚀 Listo para Usar

**Recarga Informes Manager y verás:**
- ✅ Un solo toggle switch en el encabezado
- ✅ Junto a la columna "Acciones"
- ✅ Controla el formato de todos los envíos
- ✅ Más espacio en las filas de informes
- ✅ Interfaz más limpia y organizada

---

_¡Diseño optimizado completado! 🎉_

