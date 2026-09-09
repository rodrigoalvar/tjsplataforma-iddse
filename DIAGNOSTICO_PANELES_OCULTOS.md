# 🔍 DIAGNÓSTICO: Paneles Ocultos en Workspace

## 📋 Descripción del Problema

**Usuario afectado:** llascas@iddse.com.ar  
**Usuario funcionando correctamente:** root  
**Síntoma:** Al abrir la sección workspace, los paneles de grabadora y visor DICOM no se muestran. Solo se ve fondo negro, aunque los paneles están activos.

## 🔎 Análisis del Código

### 1. Estructura de los Paneles

#### Panel de Audio (Grabadora)
- **Ubicación CSS:** Líneas 209-215, 245-260
- **Estructura HTML:** Líneas 4267-4380
- **Fondo:** `background: #1c1c1e` (gris muy oscuro, casi negro)
- **Contenedor principal:** `.audio-recorder-container`
- **Contenedor interno:** `.audio-recorder-wrapper` con `background: #1c1c1e`

#### Panel DICOM (Visor)
- **Ubicación CSS:** Líneas 1308-1313
- **Estructura HTML:** Líneas 4384-4396
- **Fondo:** `background: transparent`
- **Contenedor principal:** `.dicom-viewer-container`
- **Elementos internos:** `.dicom-viewer-loading` y `.dicom-viewer-frame` (iframe)

### 2. Proceso de Inicialización

#### Panel de Audio
1. Se crea el HTML en `createAudioPanel()` (línea 4267)
2. Se inicializa en `initPanel()` → caso 'audio' (línea 5121)
3. Se configura responsive en `setupAudioPanelResponsive()` (línea 5166)
4. Se integra con AudioModule en `setupAudioPanelIntegration()` (línea 10557)

**Posibles puntos de fallo:**
- Si `window.AudioModule` no está disponible, solo muestra un warning (línea 5140)
- Si `setupAudioPanelResponsive()` no encuentra `.audio-recorder-wrapper`, retorna sin hacer nada (línea 5170)

#### Panel DICOM
1. Se crea el HTML en `createDicomPanel()` (línea 4384)
2. Se inicializa en `initPanel()` → caso 'dicom' (línea 5150)
3. Se carga el visor en `initDicomViewer()` (línea 4400)

**Posibles puntos de fallo:**
- Si no encuentra `.dicom-viewer-container`, `.dicom-viewer-loading` o `.dicom-viewer-frame`, retorna con error (línea 4413)
- Si la API `get-user-viewer-config.php` falla, el visor no se carga (línea 4518)

### 3. Estilos CSS Relevantes

```css
/* Panel de audio */
.panel-audio .panel-content {
    padding: 0 !important;
    background: #1c1c1e;
    display: flex;
    flex-direction: column;
    overflow: auto;
}

/* Panel DICOM */
.panel-dicom .panel-content {
    padding: 0;
    height: 100%;
    background: transparent;
}
```

**Observación:** Si el contenido interno no se renderiza, solo se vería el fondo negro (`#1c1c1e` o `#0a0a0a` del contenedor padre).

## 🎯 Posibles Causas

### Causa 1: Elementos HTML no se están creando
**Probabilidad:** Media-Alta  
**Evidencia:** Los paneles están "activos" pero no se muestran

**Verificación necesaria:**
- Inspeccionar el DOM en el navegador para verificar si existen `.audio-recorder-wrapper` y `.dicom-viewer-container`
- Revisar la consola del navegador para errores de JavaScript

### Causa 2: CSS ocultando elementos
**Probabilidad:** Media  
**Evidencia:** Solo se ve fondo negro

**Verificación necesaria:**
- Buscar reglas CSS con `display: none` o `visibility: hidden` aplicadas a estos elementos
- Verificar si hay estilos inline que oculten el contenido
- Revisar si hay conflictos de z-index

### Causa 3: AudioModule no está disponible
**Probabilidad:** Media  
**Evidencia:** El panel de audio depende de `window.AudioModule`

**Verificación necesaria:**
- Verificar si `audio.js` se carga correctamente (línea 2304)
- Revisar si hay errores de carga de scripts en la consola
- Verificar permisos de acceso a archivos JavaScript

### Causa 4: Problema con la API de configuración del visor DICOM
**Probabilidad:** Media  
**Evidencia:** El panel DICOM depende de `get-user-viewer-config.php`

**Verificación necesaria:**
- Verificar si la API responde correctamente para el usuario llascas@iddse.com.ar
- Revisar permisos de base de datos o configuración del usuario
- Verificar logs del servidor para errores de la API

### Causa 5: Problema de permisos específico del usuario
**Probabilidad:** Baja-Media  
**Evidencia:** Funciona con root pero no con llascas@iddse.com.ar

**Verificación necesaria:**
- Revisar permisos del usuario en la base de datos
- Verificar si hay restricciones de acceso a recursos
- Comparar configuración de permisos entre root y llascas@iddse.com.ar

### Causa 6: Problema de timing en la inicialización
**Probabilidad:** Media  
**Evidencia:** Los paneles están "activos" pero el contenido no se muestra

**Verificación necesaria:**
- Verificar si los `setTimeout` (100ms) son suficientes
- Revisar si hay condiciones de carrera en la inicialización
- Verificar el orden de carga de scripts

## 🔧 Soluciones Propuestas

### Solución 1: Agregar verificaciones y logs de depuración
Agregar logs detallados para identificar dónde falla la inicialización:

```javascript
// En initPanel, caso 'audio'
case 'audio':
    console.log(`🔍 [DEBUG] Inicializando panel de audio ${panelId}`);
    const wrapper = panelEl.querySelector('.audio-recorder-wrapper');
    console.log(`🔍 [DEBUG] Wrapper encontrado:`, !!wrapper);
    if (!wrapper) {
        console.error(`❌ [DEBUG] audio-recorder-wrapper no encontrado en panel ${panelId}`);
        console.log(`🔍 [DEBUG] HTML del panel:`, panelEl.innerHTML.substring(0, 500));
    }
    // ... resto del código
```

### Solución 2: Forzar visibilidad de elementos
Agregar estilos inline para asegurar que los elementos sean visibles:

```javascript
// Después de crear el panel de audio
const wrapper = panelEl.querySelector('.audio-recorder-wrapper');
if (wrapper) {
    wrapper.style.display = 'block';
    wrapper.style.visibility = 'visible';
    wrapper.style.opacity = '1';
}
```

### Solución 3: Verificar carga de scripts
Agregar verificación de que todos los scripts necesarios estén cargados:

```javascript
// Verificar antes de inicializar paneles
if (!window.AudioModule) {
    console.error('❌ AudioModule no está disponible');
    // Intentar cargar manualmente o mostrar mensaje de error
}
```

### Solución 4: Agregar fallback visual
Mostrar un mensaje de error visible si los paneles no se cargan:

```javascript
// Si el wrapper no se encuentra después de un tiempo
setTimeout(() => {
    const wrapper = panelEl.querySelector('.audio-recorder-wrapper');
    if (!wrapper || wrapper.offsetHeight === 0) {
        panelEl.innerHTML += '<div style="padding: 20px; color: white; text-align: center;">Error: No se pudo cargar el panel</div>';
    }
}, 1000);
```

### Solución 5: Verificar permisos del usuario
Revisar y comparar la configuración de permisos del usuario llascas@iddse.com.ar con root:

```sql
-- Verificar permisos del usuario
SELECT * FROM usuarios WHERE email = 'llascas@iddse.com.ar';
SELECT * FROM permisos WHERE usuario_id = (SELECT id FROM usuarios WHERE email = 'llascas@iddse.com.ar');
```

## 📝 Pasos de Diagnóstico Recomendados

1. **Abrir la consola del navegador** con la cuenta llascas@iddse.com.ar
2. **Inspeccionar el DOM** de los paneles para verificar si existen los elementos
3. **Revisar errores de JavaScript** en la consola
4. **Verificar carga de scripts** (audio.js, etc.)
5. **Revisar respuestas de APIs** (get-user-viewer-config.php)
6. **Comparar permisos** entre root y llascas@iddse.com.ar
7. **Verificar estilos CSS aplicados** a los elementos

## 🎯 Próximos Pasos

1. Implementar las soluciones propuestas
2. Agregar logs de depuración detallados
3. Verificar en el navegador con la cuenta afectada
4. Comparar comportamiento entre usuarios
5. Aplicar correcciones según los hallazgos
