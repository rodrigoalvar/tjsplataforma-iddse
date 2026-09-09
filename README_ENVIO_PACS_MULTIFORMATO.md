# 📤 Sistema de Envío de Informes a PACS - Múltiples Formatos

## 🎯 Descripción General

Se ha implementado la funcionalidad para enviar informes médicos al servidor Orthanc PACS en dos formatos diferentes:

1. **📄 PDF** - Encapsulated PDF DICOM (formato tradicional)
2. **🖼️ JPG** - Secondary Capture DICOM (imagen PNG)

El usuario puede seleccionar el formato deseado mediante un parámetro en la petición o a través de un selector visual en el frontend.

## ✨ Características Principales

- ✅ Envío en formato PDF (Encapsulated PDF DICOM)
- ✅ Envío en formato JPG/PNG (Secondary Capture DICOM)
- ✅ Conversión automática PDF → PNG usando Imagick
- ✅ Fallback a GhostScript si Imagick no está disponible
- ✅ Archivos guardados persistentemente en disco
- ✅ Actualización de referencias PACS en base de datos
- ✅ Vinculación con estudios existentes (Parent StudyInstanceUID)
- ✅ Eliminación de versiones anteriores al actualizar
- ✅ Verificación de duplicados
- ✅ Logs detallados para debugging
- ✅ Manejo robusto de errores

## 📁 Archivos Modificados/Creados

### Archivos Backend (PHP)

| Archivo | Descripción |
|---------|-------------|
| `api/OrthancPacsSender.php` | ✏️ Modificado - Agregado método `sendImageAsDicom()` y `convertImageToPng()` |
| `api/informes/send-to-pacs.php` | ✏️ Modificado - Soporte para parámetro `format` y conversión PDF→PNG |
| `api/informes/ENVIO_PACS_FORMATOS.md` | 📄 Nuevo - Documentación técnica completa |
| `api/informes/verificar_requisitos.php` | 📄 Nuevo - Script de verificación de requisitos |
| `api/informes/test_envio_formatos.php` | 📄 Nuevo - Script de pruebas automatizado |

### Archivos Frontend (HTML/JS)

| Archivo | Descripción |
|---------|-------------|
| `api/informes/ejemplo_frontend_selector_formato.html` | 📄 Nuevo - Ejemplo visual con toggle switch |

### Documentación

| Archivo | Descripción |
|---------|-------------|
| `README_ENVIO_PACS_MULTIFORMATO.md` | 📄 Este archivo - README principal |

## 🚀 Uso Rápido

### Desde JavaScript (Frontend)

```javascript
// Enviar como PDF
fetch('/api/informes/send-to-pacs.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + sessionToken
  },
  body: JSON.stringify({
    informe_id: 123,
    format: 'pdf'  // o 'jpg'
  })
})
.then(res => res.json())
.then(data => console.log(data));
```

### Desde cURL (Terminal)

```bash
# Enviar como PDF
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{"informe_id": 123, "format": "pdf"}'

# Enviar como JPG
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{"informe_id": 123, "format": "jpg"}'
```

### Desde PHP

```php
require_once 'api/OrthancPacsSender.php';

$pacsSender = new OrthancPacsSender();

// Enviar como PDF
$result = $pacsSender->sendPdfAsDicom(
    '/path/to/file.pdf',
    $dicomTags,
    $parentStudyInstanceUID
);

// Enviar como Imagen
$result = $pacsSender->sendImageAsDicom(
    '/path/to/image.png',
    $dicomTags,
    $parentStudyInstanceUID
);
```

## 🔧 Instalación y Configuración

### 1. Requisitos del Sistema

#### Para formato PDF (✅ Ya instalado)
```bash
composer require tecnickcom/tcpdf
```

#### Para formato JPG (🆕 Nuevo)

**Opción A: Imagick (Recomendado)**
```bash
# Ubuntu/Debian
sudo apt-get install php-imagick imagemagick

# Verificar instalación
php -m | grep imagick

# Si Imagick no puede leer PDFs, editar política de seguridad
sudo nano /etc/ImageMagick-6/policy.xml
# Cambiar: <policy domain="coder" rights="none" pattern="PDF" />
# Por: <policy domain="coder" rights="read|write" pattern="PDF" />
```

**Opción B: GhostScript (Alternativa)**
```bash
# Ubuntu/Debian
sudo apt-get install ghostscript

# Verificar instalación
gs --version
```

### 2. Verificar Requisitos

Ejecutar script de verificación:
```bash
php api/informes/verificar_requisitos.php
```

O acceder via web:
```
http://localhost/api/informes/verificar_requisitos.php
```

### 3. Configurar Base de Datos (Opcional pero recomendado)

Agregar columna para tracking de Series ID:

```sql
ALTER TABLE informes 
ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;

CREATE INDEX idx_pacs_series_id ON informes(pacs_series_id);
```

### 4. Permisos de Directorios

```bash
# Crear directorios necesarios
mkdir -p uploads/pdf_informes
mkdir -p uploads/png_informes
mkdir -p logs

# Dar permisos de escritura
chmod 775 uploads/pdf_informes
chmod 775 uploads/png_informes
chmod 775 logs
```

## 🧪 Pruebas

### Prueba Automatizada

```bash
# Editar configuración en el archivo primero
php api/informes/test_envio_formatos.php
```

O via web:
```
http://localhost/api/informes/test_envio_formatos.php?informe_id=123&token=YOUR_TOKEN
```

### Prueba Manual

1. Abrir `api/informes/ejemplo_frontend_selector_formato.html` en navegador
2. Seleccionar formato (PDF o JPG)
3. Click en "Enviar a PACS"
4. Verificar resultado

## 📊 Flujo de Procesamiento

### Formato PDF
```
HTML del informe
    ↓
Generar PDF (TCPDF)
    ↓
Guardar en /uploads/pdf_informes/
    ↓
Enviar a Orthanc como Encapsulated PDF DICOM
    ↓
Actualizar BD con referencias PACS
```

### Formato JPG
```
HTML del informe
    ↓
Generar PDF (TCPDF)
    ↓
Guardar en /uploads/pdf_informes/
    ↓
Convertir PDF → PNG (Imagick/GhostScript)
    ↓
Guardar en /uploads/png_informes/
    ↓
Enviar a Orthanc como Secondary Capture DICOM
    ↓
Actualizar BD con referencias PACS
```

## 🔍 Detalles Técnicos

### Tags DICOM

#### PDF (Encapsulated PDF)
- **SOP Class UID**: `1.2.840.10008.5.1.4.1.1.104.1`
- **Modality**: Según configuración del informe
- **Content**: Base64 encoded PDF con prefijo `data:application/pdf;base64,`

#### JPG (Secondary Capture)
- **SOP Class UID**: `1.2.840.10008.5.1.4.1.1.7`
- **Modality**: `SC` (Secondary Capture)
- **Image**: Base64 encoded PNG con prefijo `data:image/png;base64,`

### Conversión PDF → PNG

1. **Imagick** (primera opción):
   - Resolución: 150 DPI (configurable)
   - Formato: PNG
   - Compresión: 95%
   - Páginas múltiples: Apiladas verticalmente

2. **GhostScript** (fallback):
   - Resolución: 150 DPI
   - Device: png16m (16M colores)
   - Comando: `gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r150`

### Vinculación con Estudios Existentes

Si el informe tiene un `study_instance_uid`:
1. Se busca el estudio en Orthanc
2. Se obtiene el Orthanc Study ID
3. Se envía con parámetro `Parent`
4. Orthanc crea una nueva serie dentro del estudio existente

### Actualización de Informes

Al reenviar un informe:
1. Se detecta si existe `pacs_series_id`
2. Se elimina la serie anterior de Orthanc
3. Se envía la nueva versión
4. Se actualizan las referencias en BD

## 📈 Comparación de Formatos

| Característica | PDF | JPG |
|---------------|-----|-----|
| Tamaño archivo | ➖ Menor | ➕ Mayor (2-5x) |
| Compatibilidad PACS | ➖ Algunos visores no soportan | ✅ Universal |
| Texto seleccionable | ✅ Sí | ❌ No |
| Calidad impresión | ✅ Excelente | ➖ Depende de resolución |
| Velocidad visualización | ➖ Puede requerir plugin | ✅ Inmediata |
| Uso recomendado | Informes estándar | Máxima compatibilidad |

## 🐛 Troubleshooting

### Error: "No hay ninguna librería de conversión PDF a PNG disponible"

**Causa**: Ni Imagick ni GhostScript están instalados

**Solución**:
```bash
sudo apt-get install php-imagick imagemagick
# O
sudo apt-get install ghostscript
```

### Error: "Imagick no puede leer PDFs"

**Causa**: Política de seguridad de ImageMagick

**Solución**:
```bash
sudo nano /etc/ImageMagick-6/policy.xml
# Buscar: <policy domain="coder" rights="none" pattern="PDF" />
# Cambiar a: <policy domain="coder" rights="read|write" pattern="PDF" />
sudo service apache2 restart
```

### PNG muy grande

**Solución**: Reducir DPI en `convertPdfToPng()`:
```php
$imagick->setResolution(100, 100); // Reducir de 150 a 100
```

### PNG borrosa o ilegible

**Solución**: Aumentar DPI en `convertPdfToPng()`:
```php
$imagick->setResolution(200, 200); // Aumentar de 150 a 200
```

### Timeout al enviar

**Solución**: Aumentar timeout en `php.ini`:
```ini
max_execution_time = 120
```

## 📝 Logs y Debugging

### Ubicación de Logs
```
/logs/php_errors.log
```

### Buscar en Logs
```bash
# Conversión PDF a PNG
grep "convertPdfToPng" logs/php_errors.log

# Envío a Orthanc
grep "SEND_TO_PACS" logs/php_errors.log

# Errores de Imagick
grep "Imagick" logs/php_errors.log

# Errores generales
tail -f logs/php_errors.log
```

### Logs de Orthanc
Consultar configuración de Orthanc para ubicación de logs.

## 🎨 Ejemplo de Implementación Frontend

Ver archivo completo: `api/informes/ejemplo_frontend_selector_formato.html`

```html
<div class="toggle-switch">
  <input type="radio" name="format" id="togglePdf" value="pdf" checked>
  <input type="radio" name="format" id="toggleJpg" value="jpg">
  <div class="toggle-slider">
    <label for="togglePdf">📄 PDF</label>
    <label for="toggleJpg">🖼️ Imagen</label>
  </div>
</div>

<button onclick="enviarAPacs()">Enviar a PACS</button>
```

## 🔐 Seguridad

- ✅ Validación de sesión/token requerida
- ✅ Verificación de permisos de usuario
- ✅ Sanitización de inputs
- ✅ Archivos guardados fuera de webroot cuando posible
- ✅ Manejo seguro de errores sin exponer información sensible

## 🚀 Rendimiento

### Tiempos Estimados (informe típico de 3 páginas)

| Operación | Tiempo Estimado |
|-----------|-----------------|
| Generar PDF | 0.5 - 1.5 segundos |
| Convertir PDF → PNG | 1 - 3 segundos |
| Enviar a Orthanc (PDF) | 0.5 - 2 segundos |
| Enviar a Orthanc (JPG) | 1 - 4 segundos |
| **Total PDF** | **1 - 3.5 segundos** |
| **Total JPG** | **2.5 - 8.5 segundos** |

*Los tiempos varían según hardware, tamaño del informe y carga del servidor.*

## 📚 Referencias

- [Documentación Orthanc](https://book.orthanc-server.com/)
- [TCPDF Documentation](https://tcpdf.org/docs/)
- [Imagick PHP Manual](https://www.php.net/manual/en/book.imagick.php)
- [DICOM Standard](https://www.dicomstandard.org/)
- [GhostScript Documentation](https://www.ghostscript.com/doc/)

## 🤝 Contribuir

Para reportar bugs o sugerir mejoras, revisar los logs en `/logs/php_errors.log` y documentar:
1. Versión de PHP
2. Extensiones instaladas (php -m)
3. Mensaje de error completo
4. Pasos para reproducir

## 📄 Licencia

Este código es parte del sistema PORTAL_ESTUDIOS.

---

**Última actualización**: Octubre 2025
**Versión**: 1.0.0
**Autor**: Sistema TJSMEDICAL

