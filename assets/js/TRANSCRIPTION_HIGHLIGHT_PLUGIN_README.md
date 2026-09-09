# Transcription Highlight Plugin

Plugin reutilizable para resaltado de palabras durante reproducción de audio con transcripciones.

## Características

- ✅ Resaltado sincronizado con audio usando rangos [start, end)
- ✅ Búsqueda binaria O(log n) para mejor rendimiento
- ✅ requestAnimationFrame para actualizaciones fluidas (~60fps)
- ✅ Configuración personalizable desde servidor
- ✅ Soporte para modal y panel flotante
- ✅ Palabras clicables para saltar a timestamps
- ✅ Scroll automático configurable
- ✅ Estilos personalizables (colores, fuentes, transiciones)

## Instalación

1. **Agregar columnas a la base de datos:**
   ```bash
   mysql -u usuario -p nombre_base_datos < database/add_transcription_highlight_config.sql
   ```

2. **Incluir los archivos JavaScript:**
   ```html
   <script src="assets/js/transcription-highlight-plugin.js"></script>
   <script src="assets/js/transcription-highlight-config.js"></script>
   ```

## Uso Básico

### Ejemplo 1: Modal de Transcripción

```javascript
// En el modal de transcripción (ai-informes.js)
const plugin = new TranscriptionHighlightPlugin({
    mode: 'modal',
    textContainer: document.getElementById('viewTranscriptionText'),
    audioElement: document.getElementById('transcriptionAudioPlayer'),
    transcription: {
        transcription_text: 'Texto completo de la transcripción...',
        segments: [
            { start: 0.0, end: 2.5, text: 'Primer segmento' },
            { start: 2.5, end: 5.0, text: 'Segundo segmento' }
        ]
    }
});

// Inicializar
await plugin.init();
```

### Ejemplo 2: Panel Flotante

```javascript
// En el panel flotante (informes-manager.js)
const plugin = new TranscriptionHighlightPlugin({
    mode: 'floating',
    textContainer: document.getElementById('floatingTranscriptionText'),
    audioElement: document.getElementById('editAudioElement'),
    transcription: {
        transcription_text: audio.transcripcion,
        segments: audio.segments
    }
});

await plugin.init();
```

### Ejemplo 3: Con Callbacks

```javascript
const plugin = new TranscriptionHighlightPlugin({
    textContainer: '#transcriptionText',
    audioElement: '#audioPlayer',
    transcription: transcriptionData,
    onWordClick: (timestamp, wordIndex) => {
        console.log('Palabra clickeada:', timestamp, wordIndex);
    },
    onHighlightChange: (wordIndex, word) => {
        console.log('Nueva palabra activa:', word);
    }
});

await plugin.init();
```

## API del Plugin

### Constructor

```javascript
new TranscriptionHighlightPlugin(options)
```

**Opciones:**
- `textContainer` (HTMLElement|string, requerido): Contenedor del texto
- `audioElement` (HTMLElement|string, requerido): Elemento de audio
- `transcription` (Object, requerido): Objeto con `transcription_text` y `segments`
- `mode` (string, opcional): 'modal' | 'floating' (default: 'modal')
- `onWordClick` (Function, opcional): Callback cuando se hace click en palabra
- `onHighlightChange` (Function, opcional): Callback cuando cambia la palabra resaltada

### Métodos

#### `init()`
Inicializa el plugin. Debe llamarse después de crear la instancia.

```javascript
await plugin.init();
```

#### `updateTranscription(transcription)`
Actualiza la transcripción (útil cuando cambia el audio).

```javascript
plugin.updateTranscription(newTranscription);
```

#### `reloadConfig()`
Recarga la configuración desde el servidor.

```javascript
await plugin.reloadConfig();
```

#### `destroy()`
Destruye la instancia y limpia recursos.

```javascript
plugin.destroy();
```

## Configuración

La configuración se gestiona desde:
- **Configuración Principal:** `configuracion.html` → Pestaña "AI Informes" → Sección "Resaltado de Transcripción"
- **Configuración Rápida:** Modal de transcripción → Botón "⚙️ Configurar" (solo root/admin)

### Campos Configurables

- **Throttle (ms):** Tiempo mínimo entre actualizaciones (10-100ms, default: 30ms)
- **Offset de Audio:** Compensación de delay (-0.5 a 0.5s, default: -0.1s)
- **Scroll Behavior:** Comportamiento de scroll (smooth/auto/instant, default: smooth)
- **Activar Scroll Automático:** Activar/desactivar scroll automático (default: true)
- **Color Activo:** Color del texto cuando está activo (default: #ffeb3b)
- **Fondo Activo:** Color de fondo cuando está activo (default: #ffeb3b)
- **Color Hover:** Color del texto al pasar el mouse (default: #1976d2)
- **Fondo Hover:** Color de fondo al pasar el mouse (default: #e3f2fd)
- **Peso de Fuente:** Peso de la fuente (400-900, default: 600)
- **Duración de Transición:** Duración de animación (0-1s, default: 0.2s)
- **Tipo de UI Config:** Modal u Offcanvas para configuración rápida (default: modal)
- **Activar Preview:** Preview en tiempo real de cambios (default: true)

## Componente de Configuración

El componente `TranscriptionHighlightConfig` se puede usar para renderizar los campos de configuración:

```javascript
// Renderizar campos
TranscriptionHighlightConfig.render(config, 'containerId', {
    showTitle: true,
    title: 'Resaltado de Transcripción',
    compactMode: false
});

// Obtener valores
const values = TranscriptionHighlightConfig.getValues();

// Validar valores
const validation = TranscriptionHighlightConfig.validate();
if (!validation.valid) {
    console.error('Errores:', validation.errors);
}
```

## Integración

### En ai-informes.js

```javascript
let transcriptionPlugin = null;

function initInteractiveTranscription(transcription) {
    // Limpiar instancia anterior
    if (transcriptionPlugin) {
        transcriptionPlugin.destroy();
    }
    
    // Crear nueva instancia
    transcriptionPlugin = new TranscriptionHighlightPlugin({
        mode: 'modal',
        textContainer: document.getElementById('viewTranscriptionText'),
        audioElement: document.getElementById('transcriptionAudioPlayer'),
        transcription: transcription
    });
    
    transcriptionPlugin.init();
}
```

### En informes-manager.js

```javascript
initFloatingTranscriptionPanel: function(audio) {
    // Limpiar instancia anterior
    if (this.transcriptionPlugin) {
        this.transcriptionPlugin.destroy();
    }
    
    // Crear nueva instancia
    this.transcriptionPlugin = new TranscriptionHighlightPlugin({
        mode: 'floating',
        textContainer: document.getElementById('floatingTranscriptionText'),
        audioElement: document.getElementById('editAudioElement'),
        transcription: {
            transcription_text: audio.transcripcion,
            segments: audio.segments
        }
    });
    
    this.transcriptionPlugin.init();
}
```

## Rendimiento

- **Búsqueda binaria:** O(log n) en lugar de O(n)
- **Throttle configurable:** 30ms por defecto (balanceado)
- **requestAnimationFrame:** Actualizaciones ligadas al repintado (~60fps)
- **Precalculo de rangos:** Una sola vez al inicializar

## Compatibilidad

- Navegadores modernos (Chrome, Firefox, Safari, Edge)
- Requiere `requestAnimationFrame` (IE10+)
- Bootstrap 5 (para modales)

## Troubleshooting

### El resaltado no funciona
1. Verificar que la transcripción tenga `segments` con `start` y `end`
2. Verificar que los elementos del DOM existan
3. Revisar la consola del navegador para errores

### El resaltado se pierde en dictado rápido
1. Reducir el throttle a 20ms o menos
2. Verificar que los timestamps sean precisos
3. Asegurarse de que los rangos [start, end) estén correctamente calculados

### Los colores no se aplican
1. Verificar que la configuración se haya guardado correctamente
2. Llamar a `plugin.reloadConfig()` después de cambiar configuración
3. Verificar que los estilos CSS se hayan inyectado correctamente

## Licencia

Sistema TJSMEDICAL - Portal de Estudios Médicos
