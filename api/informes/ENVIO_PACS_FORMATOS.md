# Envío de Informes a PACS - Múltiples Formatos

## Descripción

El endpoint `send-to-pacs.php` ahora soporta enviar informes médicos al servidor Orthanc PACS en dos formatos:

1. **PDF** - Encapsulated PDF DICOM (formato tradicional)
2. **JPG** - Secondary Capture DICOM (imagen PNG convertida)

## Uso del API

### Endpoint

```
POST /api/informes/send-to-pacs.php
```

### Parámetros

| Parámetro | Tipo | Requerido | Valores | Descripción |
|-----------|------|-----------|---------|-------------|
| `informe_id` | integer | Sí | - | ID del informe a enviar |
| `format` | string | No | `pdf`, `jpg` | Formato de envío (por defecto: `pdf`) |
| `check_duplicates` | boolean | No | `true`, `false` | Verificar duplicados (por defecto: `true`) |

### Headers

```
Authorization: Bearer {session_token}
Content-Type: application/json
```

## Ejemplos de Uso

### Enviar como PDF (tradicional)

```javascript
fetch('/api/informes/send-to-pacs.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + sessionToken
  },
  body: JSON.stringify({
    informe_id: 123,
    format: 'pdf'
  })
})
.then(response => response.json())
.then(data => {
  if (data.success) {
    console.log('Informe enviado como PDF:', data);
  }
});
```

### Enviar como JPG (imagen)

```javascript
fetch('/api/informes/send-to-pacs.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + sessionToken
  },
  body: JSON.stringify({
    informe_id: 123,
    format: 'jpg'
  })
})
.then(response => response.json())
.then(data => {
  if (data.success) {
    console.log('Informe enviado como imagen JPG:', data);
  }
});
```

### cURL Examples

#### Enviar como PDF
```bash
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_SESSION_TOKEN" \
  -d '{
    "informe_id": 123,
    "format": "pdf"
  }'
```

#### Enviar como JPG
```bash
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_SESSION_TOKEN" \
  -d '{
    "informe_id": 123,
    "format": "jpg"
  }'
```

## Respuesta del API

### Respuesta Exitosa

```json
{
  "success": true,
  "message": "Informe enviado exitosamente a Orthanc PACS como imagen JPG",
  "format": "jpg",
  "is_update": false,
  "old_series_id": null,
  "old_instance_id": null,
  "data": {
    "instance_id": "abc123-def456-...",
    "study_id": "xyz789-uvw012-...",
    "series_id": "mno345-pqr678-...",
    "file_size_mb": 1.23
  }
}
```

### Respuesta de Error

```json
{
  "success": false,
  "message": "Formato inválido. Use \"pdf\" o \"jpg\"",
  "error_code": "SEND_TO_PACS_ERROR"
}
```

## Flujo de Conversión

### Formato PDF
1. HTML del informe → PDF (TCPDF)
2. Guardar PDF en `/uploads/pdf_informes/`
3. Enviar PDF a Orthanc como Encapsulated PDF DICOM
4. DICOM SOP Class: `1.2.840.10008.5.1.4.1.1.104.1`
5. Modality: Según configuración del informe

### Formato JPG
1. HTML del informe → PDF (TCPDF)
2. Guardar PDF en `/uploads/pdf_informes/`
3. **PDF → PNG** (Imagick o GhostScript)
4. Guardar PNG en `/uploads/png_informes/`
5. Enviar PNG a Orthanc como Secondary Capture DICOM
6. DICOM SOP Class: `1.2.840.10008.5.1.4.1.1.7`
7. Modality: `SC` (Secondary Capture)

## Requisitos del Sistema

### Para formato PDF
- ✅ TCPDF (ya instalado via Composer)

### Para formato JPG (adicional)
Se requiere **al menos una** de estas opciones:

#### Opción 1: Imagick (Recomendado)
```bash
# Ubuntu/Debian
sudo apt-get install php-imagick imagemagick

# Windows (WAMP)
# 1. Descargar ImageMagick: https://imagemagick.org/script/download.php
# 2. Instalar ImageMagick
# 3. Habilitar extensión php_imagick en php.ini
extension=imagick

# Verificar instalación
php -m | grep imagick
```

#### Opción 2: GhostScript (Alternativa)
```bash
# Ubuntu/Debian
sudo apt-get install ghostscript

# Windows (WAMP)
# Descargar e instalar desde: https://www.ghostscript.com/download.html

# Verificar instalación
gs --version
```

### Verificar Requisitos

Crear un archivo PHP de prueba:

```php
<?php
// test_requisitos.php
echo "TCPDF: " . (class_exists('TCPDF') ? 'INSTALADO ✅' : 'NO INSTALADO ❌') . "\n";
echo "Imagick: " . (class_exists('Imagick') ? 'INSTALADO ✅' : 'NO INSTALADO ❌') . "\n";

// Test GhostScript
exec('gs --version 2>&1', $output, $returnCode);
echo "GhostScript: " . ($returnCode === 0 ? 'INSTALADO ✅' : 'NO INSTALADO ❌') . "\n";

echo "\n--- RECOMENDACIÓN ---\n";
if (class_exists('TCPDF')) {
    echo "✅ Formato PDF: DISPONIBLE\n";
} else {
    echo "❌ Formato PDF: NO DISPONIBLE (instalar TCPDF)\n";
}

if (class_exists('Imagick') || $returnCode === 0) {
    echo "✅ Formato JPG: DISPONIBLE\n";
} else {
    echo "❌ Formato JPG: NO DISPONIBLE (instalar Imagick o GhostScript)\n";
}
?>
```

## Configuración en Base de Datos

Si envías informes como JPG, asegúrate de que la columna `pacs_series_id` existe en la tabla `informes`:

```sql
-- Verificar si la columna existe
DESCRIBE informes;

-- Si no existe, ejecutar:
ALTER TABLE informes 
ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;

-- Crear índice para mejor performance
CREATE INDEX idx_pacs_series_id ON informes(pacs_series_id);
```

## Ventajas de Cada Formato

### PDF (Encapsulated PDF DICOM)
- ✅ Texto seleccionable y copiable
- ✅ Archivo más ligero
- ✅ Formato profesional para informes
- ✅ Fácil de imprimir
- ❌ Algunos visores DICOM no soportan PDFs

### JPG (Secondary Capture DICOM)
- ✅ Compatible con todos los visores DICOM
- ✅ Visualización inmediata sin plugins
- ✅ Integración nativa con PACS
- ✅ Útil para captura de pantallas/documentos
- ❌ Archivo más pesado
- ❌ Texto no seleccionable
- ❌ Resolución limitada

## Recomendaciones de Uso

1. **Usar PDF** para:
   - Informes médicos estándar
   - Documentos que se van a imprimir
   - Cuando se necesita seleccionar/copiar texto
   - Cuando el visor DICOM soporta PDFs

2. **Usar JPG** para:
   - Compatibilidad máxima con visores DICOM
   - Cuando el visor no soporta PDFs
   - Integración con sistemas legacy
   - Visualización rápida sin plugins adicionales

## Troubleshooting

### Error: "No hay ninguna librería de conversión PDF a PNG disponible"

**Solución:** Instalar Imagick o GhostScript (ver sección de Requisitos)

### Error: "No se pudo convertir PDF a PNG con Imagick"

**Posibles causas:**
1. ImageMagick no tiene permisos para leer PDFs (política de seguridad)
2. El PDF está corrupto

**Solución para política de seguridad:**
```bash
# Editar política de ImageMagick
sudo nano /etc/ImageMagick-6/policy.xml

# Buscar y cambiar esta línea:
# <policy domain="coder" rights="none" pattern="PDF" />

# Por esta:
<policy domain="coder" rights="read|write" pattern="PDF" />

# Reiniciar servidor web
sudo service apache2 restart
```

### PNG muy pesado

**Solución:** Ajustar resolución en `convertPdfToPng()`:

```php
// Reducir DPI para archivos más pequeños
$imagick->setResolution(100, 100); // Era 150
```

### Imagen muy pequeña o ilegible

**Solución:** Aumentar resolución:

```php
// Aumentar DPI para mejor calidad
$imagick->setResolution(200, 200); // Era 150
```

## Logs y Debugging

Los logs se guardan en:
- `/logs/php_errors.log`
- Orthanc logs (según configuración de Orthanc)

Buscar en logs:
```bash
# Ver conversión PDF a PNG
grep "convertPdfToPng" logs/php_errors.log

# Ver envío a Orthanc
grep "SEND_TO_PACS" logs/php_errors.log

# Ver errores de Imagick
grep "Imagick" logs/php_errors.log
```

## Ejemplo de Implementación en Frontend

### Selector de Formato (Toggle Switch)

```html
<div class="format-selector">
  <label>
    <input type="radio" name="format" value="pdf" checked>
    <span>PDF</span>
  </label>
  <label>
    <input type="radio" name="format" value="jpg">
    <span>Imagen JPG</span>
  </label>
</div>

<button id="enviarPacs">Enviar a PACS</button>

<script>
document.getElementById('enviarPacs').addEventListener('click', async () => {
  const format = document.querySelector('input[name="format"]:checked').value;
  
  const response = await fetch('/api/informes/send-to-pacs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + sessionStorage.getItem('token')
    },
    body: JSON.stringify({
      informe_id: informeId,
      format: format
    })
  });
  
  const result = await response.json();
  
  if (result.success) {
    alert(`Informe enviado como ${format.toUpperCase()}`);
  } else {
    alert('Error: ' + result.message);
  }
});
</script>
```

## Archivos Modificados

Los siguientes archivos fueron modificados para agregar esta funcionalidad:

1. **`api/OrthancPacsSender.php`**
   - Agregado: `sendImageAsDicom()` - Envía imágenes como DICOM
   - Agregado: `convertImageToPng()` - Convierte imágenes a PNG
   - Agregado: Constante `SOPCLASS_SECONDARY_CAPTURE`

2. **`api/informes/send-to-pacs.php`**
   - Agregado: Parámetro `format` en input
   - Agregado: Validación de formato
   - Agregado: Función `convertPdfToPng()` 
   - Modificado: Lógica de envío para soportar ambos formatos
   - Modificado: Respuestas JSON incluyen campo `format`

## Soporte

Para más información o reportar problemas:
- Logs: `/logs/php_errors.log`
- Documentación Orthanc: https://book.orthanc-server.com/
- Imagick: https://www.php.net/manual/en/book.imagick.php

