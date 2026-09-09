# ⚙️ Configuración Global - Formato de Envío a PACS

## 🎯 ¿Qué es esto?

Un sistema de **configuración global y persistente** que define qué formato (PDF o PNG) se usará **AUTOMÁTICAMENTE** cuando envíes informes a PACS desde **Informes Manager**.

### ✅ Lo que HACE:
- Configuras UNA VEZ el formato deseado (PDF o PNG)
- TODOS los envíos posteriores usan ese formato automáticamente
- No necesitas elegir el formato cada vez
- La configuración se guarda en base de datos
- Aplica para todos los usuarios del sistema

### ❌ Lo que NO hace:
- NO es una configuración por informe individual
- NO requiere elegir cada vez
- NO es solo en el navegador (se guarda en BD)

---

## 🚀 Cómo Usar

### Paso 1: Acceder a Configuración

Abrir en el navegador:
```
http://localhost/components/configuracion-pacs.html
```

### Paso 2: Seleccionar Formato

Click en el formato deseado:
- **📄 PDF** - Para informes con texto seleccionable
- **🖼️ PNG** - Para máxima compatibilidad DICOM

### Paso 3: Guardar

Click en "💾 Guardar Configuración"

### ¡Listo! ✅

Todos los envíos desde Informes Manager usarán el formato configurado.

---

## 📊 Flujo del Sistema

```
┌─────────────────────────────┐
│  Usuario configura formato  │
│  en configuracion-pacs.html │
└──────────┬──────────────────┘
           │
           ▼
┌─────────────────────────────┐
│  Se guarda en Base de Datos │
│  tabla: configuracion       │
│  clave: pacs_formato_defecto│
└──────────┬──────────────────┘
           │
           ▼
┌─────────────────────────────────────┐
│  Usuario envía informe desde        │
│  Informes Manager                   │
└──────────┬──────────────────────────┘
           │
           ▼
┌──────────────────────────────────────┐
│  Sistema obtiene formato configurado │
│  desde config-formato-pacs.php       │
└──────────┬───────────────────────────┘
           │
           ▼
┌──────────────────────────────────────┐
│  Envía a PACS con formato configurado│
│  (PDF o PNG según configuración)     │
└──────────────────────────────────────┘
```

---

## 📁 Archivos Creados/Modificados

### Archivos Nuevos

**1. `api/informes/config-formato-pacs.php`**
- API REST para obtener/guardar la configuración
- GET: Obtiene formato configurado
- POST: Guarda nuevo formato
- Crea tabla `configuracion` si no existe

**2. `components/configuracion-pacs.html`**
- Interface visual para configurar el formato
- Cards para PDF y PNG
- Botón de guardar
- Información detallada de cada formato

### Archivos Modificados

**3. `assets/js/informes-manager.js`**
- Función `sendToPacs()` modificada (línea 3389)
- Ahora obtiene el formato configurado antes de enviar
- Incluye `format` en el body del request

**4. `assets/js/reports.js`**
- Función `sendReport()` modificada (línea 1373)
- También obtiene el formato configurado
- Incluye `format` en el body del request

---

## 🔧 Detalles Técnicos

### Tabla en Base de Datos

```sql
CREATE TABLE IF NOT EXISTS configuracion (
    clave VARCHAR(100) PRIMARY KEY,
    valor TEXT,
    descripcion TEXT,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Registro para formato PACS
INSERT INTO configuracion (clave, valor, descripcion) 
VALUES ('pacs_formato_defecto', 'pdf', 'Formato por defecto para envío a PACS: pdf o jpg');
```

### API Endpoints

#### GET - Obtener Configuración Actual
```bash
GET /api/informes/config-formato-pacs.php
Authorization: Bearer {token}
```

**Respuesta:**
```json
{
  "success": true,
  "formato": "pdf",
  "message": "Configuración obtenida exitosamente"
}
```

#### POST - Guardar Nueva Configuración
```bash
POST /api/informes/config-formato-pacs.php
Authorization: Bearer {token}
Content-Type: application/json

{
  "formato": "jpg"
}
```

**Respuesta:**
```json
{
  "success": true,
  "formato": "jpg",
  "message": "Formato por defecto configurado como JPG"
}
```

### Código JavaScript (Ejemplo)

```javascript
// Obtener formato configurado
async function obtenerFormatoConfigurado() {
  const response = await fetch('/api/informes/config-formato-pacs.php', {
    method: 'GET',
    headers: {
      'Authorization': 'Bearer ' + token
    }
  });
  
  const data = await response.json();
  return data.formato; // 'pdf' o 'jpg'
}

// Guardar formato
async function guardarFormato(formato) {
  const response = await fetch('/api/informes/config-formato-pacs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + token
    },
    body: JSON.stringify({ formato })
  });
  
  const data = await response.json();
  return data.success;
}
```

---

## 🎯 Casos de Uso

### Caso 1: Hospital con Visor DICOM Moderno
```
Configurar: PDF
Razón: Visor soporta PDFs, médicos necesitan copiar texto
```

### Caso 2: Hospital con Visor Legacy
```
Configurar: PNG
Razón: Visor no soporta PDFs, necesita máxima compatibilidad
```

### Caso 3: Cambio de Visor DICOM
```
1. Antes: PNG (visor antiguo)
2. Actualización de visor
3. Cambiar configuración a PDF
4. ¡Todos los futuros envíos usan PDF!
```

---

## 💡 Preguntas Frecuentes

### ❓ ¿Puedo cambiar el formato en cualquier momento?

**SÍ.** Solo accede a `configuracion-pacs.html`, selecciona el nuevo formato y guarda.

### ❓ ¿Afecta a informes ya enviados?

**NO.** Solo afecta a envíos **futuros**. Los informes ya enviados mantienen su formato original.

### ❓ ¿Puedo enviar un informe en otro formato sin cambiar la configuración?

**SÍ.** Usa los selectores individuales:
- `selector_pdf_png.html` - Selector visual por informe
- `ejemplo_frontend_selector_formato.html` - Toggle switch por informe

### ❓ ¿Qué pasa si la tabla configuracion no existe?

El sistema la **crea automáticamente** la primera vez que guardas la configuración.

### ❓ ¿Qué pasa si no se puede obtener la configuración?

El sistema usa **PDF por defecto** y registra una advertencia en la consola.

### ❓ ¿Dónde se guarda la configuración?

1. **Base de datos** (principal): Tabla `configuracion`
2. **localStorage** (respaldo): `pacs_formato_defecto`

### ❓ ¿Puedo tener configuraciones diferentes por usuario?

**NO actualmente.** La configuración es global para todo el sistema. Si necesitas esto, habría que modificar el sistema para guardar por usuario.

---

## 🔗 Integración con el Menú

### Opción 1: Agregar al Menú Principal

Agregar enlace en tu menú de navegación:

```html
<li>
  <a href="/components/configuracion-pacs.html">
    <i class="fas fa-cog"></i>
    Configuración PACS
  </a>
</li>
```

### Opción 2: Botón en Informes Manager

Agregar botón en la barra de herramientas:

```html
<button onclick="abrirConfiguracionPACS()" class="btn btn-secondary">
  <i class="fas fa-cog"></i>
  Configurar Formato PACS
</button>

<script>
function abrirConfiguracionPACS() {
  window.open('/components/configuracion-pacs.html', '_blank');
}
</script>
```

### Opción 3: Modal en la Misma Página

Integrar como modal dentro de `informes-manager.html`.

---

## ✅ Checklist de Verificación

Después de implementar, verificar:

- [ ] Tabla `configuracion` existe en base de datos
- [ ] Acceso a `configuracion-pacs.html` funciona
- [ ] Guardar configuración funciona correctamente
- [ ] Configuración persiste después de cerrar navegador
- [ ] Envío desde Informes Manager usa formato configurado
- [ ] Consola muestra "Formato configurado obtenido: pdf/jpg"
- [ ] Si configuración no disponible, usa PDF por defecto
- [ ] Cambiar formato actualiza envíos inmediatamente

---

## 🎓 Resumen

**Antes:**
- Usuario debe elegir formato cada vez
- Sin opción de configurar por defecto
- Más clicks y decisiones

**Ahora:**
- ✅ Configurar UNA VEZ
- ✅ Usar SIEMPRE ese formato
- ✅ Cambiar cuando sea necesario
- ✅ Sin decisiones cada vez
- ✅ Más rápido y eficiente

**Para configurar:**
1. Ir a `configuracion-pacs.html`
2. Click en formato deseado
3. Guardar
4. ¡Listo!

---

## 📚 Documentos Relacionados

- **COMO_ELEGIR_PDF_O_PNG.md** - Selección por informe individual
- **GUIA_SELECCION_FORMATO.md** - Guía completa de formatos
- **README_ENVIO_PACS_MULTIFORMATO.md** - Documentación técnica completa

---

_Última actualización: 30 de Octubre, 2025_  
_Versión: 1.0.0 - Configuración Global_

