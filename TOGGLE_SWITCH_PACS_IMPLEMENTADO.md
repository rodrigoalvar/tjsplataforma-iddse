# ✅ Toggle Switch PDF/PNG Implementado en Informes Manager

## 🎯 Lo Que Se Implementó

Se agregó un **toggle switch visual** en Informes Manager que permite elegir entre **PDF** y **PNG** cada vez que envías un informe a PACS.

---

## 📍 Ubicación

El toggle switch aparece **junto al botón "Enviar a PACS"** en cada fila de la tabla de informes.

**Visual:**
```
[Ver] [Editar] [📄 PDF ──●── 🖼️ PNG] [Enviar a PACS] [Eliminar]
                      ↑ Toggle Switch
```

---

## 🎨 Características del Toggle

### Estados Visuales:

**📄 PDF (Por Defecto - Izquierda):**
- Color verde (#28a745)
- Texto: "📄 PDF"
- Estado: No marcado (unchecked)

**🖼️ PNG (Activado - Derecha):**
- Color naranja (#ff9800)
- Texto: "🖼️ PNG"
- Estado: Marcado (checked)

### Interacción:
- ✅ Click para cambiar de PDF a PNG o viceversa
- ✅ Animación suave al cambiar
- ✅ Visual claro del formato seleccionado
- ✅ Hover effect para mejor UX

---

## 🔧 Funcionamiento

### 1. Prioridad del Toggle Switch

El toggle switch tiene **PRIORIDAD** sobre la configuración global:

```
Toggle Switch (si existe) → Configuración Global → PDF por defecto
```

### 2. Inicialización

Al cargar los informes:
1. Se renderizan los toggle switches (por defecto en PDF)
2. Se carga la configuración global
3. Se aplica la configuración global a todos los toggles
4. Si no hay configuración global, quedan en PDF

### 3. Al Enviar

Cuando clickeas "Enviar a PACS":
1. El sistema lee el estado del toggle switch de ese informe
2. Si está marcado → PNG/JPG
3. Si NO está marcado → PDF
4. Envía con ese formato

---

## 📋 Cambios Realizados

### Archivos Modificados:

**1. `assets/js/informes-manager.js`**
- ✅ Agregado toggle switch en `renderRow()` (línea ~1744)
- ✅ Función `initPacsFormatToggles()` para inicializar toggles
- ✅ Función `sendToPacs()` modificada para usar valor del toggle

**2. `components/informes-manager.html`**
- ✅ Estilos CSS agregados para el toggle switch (línea ~971)
- ✅ Animaciones y efectos visuales

---

## 💻 Código del Toggle Switch

### HTML Renderizado:
```html
<div class="pacs-format-toggle-container">
    <label class="pacs-format-toggle" title="Seleccionar formato de envío">
        <input type="checkbox" class="pacs-format-switch" 
               id="pacs-format-123" data-informe-id="123">
        <span class="pacs-format-slider">
            <span class="pacs-format-label-left">📄 PDF</span>
            <span class="pacs-format-label-right">🖼️ PNG</span>
        </span>
    </label>
</div>
```

### JavaScript:
```javascript
// Obtener valor del toggle
const toggleSwitch = document.getElementById(`pacs-format-${informeId}`);
const formato = toggleSwitch.checked ? 'jpg' : 'pdf';
```

---

## 🎯 Cómo Usar

### Enviar como PDF:
1. Deja el toggle switch a la izquierda (PDF)
2. Click en "Enviar a PACS"
3. Se envía como PDF

### Enviar como PNG:
1. Click en el toggle switch (se mueve a la derecha, PNG)
2. Click en "Enviar a PACS"
3. Se envía como PNG/JPG

---

## ✨ Ventajas

### ✅ Comparado con Configuración Global:

| Aspecto | Configuración Global | Toggle Switch |
|---------|---------------------|---------------|
| **Flexibilidad** | ❌ Mismo formato para todos | ✅ Diferente por informe |
| **Control** | ❌ Una vez configurado | ✅ Cambiar en cada envío |
| **Claridad Visual** | ❌ No se ve el formato | ✅ Se ve claramente |
| **Velocidad** | ✅ Más rápido | ⚠️ Un click extra |

### ✅ Lo Mejor de Ambos:

Ahora tienes **AMBOS**:
- ✅ Toggle switch para elegir por informe
- ✅ Configuración global como valor inicial de los toggles
- ✅ Toggle switch tiene prioridad (siempre puedes cambiar)

---

## 🔍 Debugging

### Ver en Consola:

```javascript
// Al inicializar
✅ Toggle switches inicializados con formato: pdf

// Al enviar
📄 Formato seleccionado desde toggle: pdf
// o
📄 Formato seleccionado desde toggle: jpg
```

### Verificar Estado:

```javascript
// Ver estado de un toggle específico
const toggle = document.getElementById('pacs-format-123');
console.log(toggle.checked); // true = PNG, false = PDF
```

---

## 📊 Flujo Completo

```
1. Usuario abre Informes Manager
   ↓
2. Sistema carga informes y renderiza tabla
   ↓
3. Se renderizan toggle switches (por defecto PDF)
   ↓
4. initPacsFormatToggles() carga configuración global
   ↓
5. Aplica configuración global a todos los toggles
   ↓
6. Usuario puede cambiar cualquier toggle individualmente
   ↓
7. Usuario click "Enviar a PACS"
   ↓
8. sendToPacs() lee valor del toggle de ese informe
   ↓
9. Envía con el formato seleccionado
```

---

## 🎨 Personalización (Opcional)

### Cambiar Colores:

**En `informes-manager.html`, línea ~1001:**
```css
/* Color PDF (verde) */
.pacs-format-slider {
    background-color: #28a745; /* Cambiar este color */
}

/* Color PNG (naranja) */
.pacs-format-switch:checked + .pacs-format-slider {
    background-color: #ff9800; /* Cambiar este color */
}
```

### Cambiar Tamaño:

```css
.pacs-format-toggle {
    width: 90px;  /* Ancho */
    height: 26px;  /* Alto */
}
```

---

## ✅ Resumen

**Lo que tienes ahora:**
- ✅ Toggle switch visual en cada fila de informes
- ✅ PDF por defecto (izquierda, verde)
- ✅ PNG al activar (derecha, naranja)
- ✅ Prioridad sobre configuración global
- ✅ Inicialización desde configuración global
- ✅ Funciona perfectamente con el sistema existente

**El toggle switch está:**
- ✅ Visualmente atractivo
- ✅ Fácil de usar
- ✅ Claramente identificable
- ✅ Totalmente funcional

---

## 🚀 Listo para Usar

El toggle switch ya está implementado y funcionando. Solo necesitas:

1. **Recargar** Informes Manager
2. **Ver** el toggle switch junto al botón "Enviar a PACS"
3. **Probar** cambiando entre PDF y PNG
4. **Enviar** informes con el formato elegido

---

_¡Implementación completada! 🎉_

