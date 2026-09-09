# Instalación de Librería PDF para Envío a PACS

## 📋 Situación Actual

**Frontend:** ✅ Usa `html2pdf.js` (JavaScript) para generar PDFs en el navegador  
**Backend:** ❌ **NO HAY librería PHP instalada** para generar PDFs en el servidor

## 🎯 Librería Recomendada: **dompdf**

### ✅ Ventajas de dompdf:

1. **100% PHP**: No requiere ejecutables externos
2. **Fácil instalación**: Solo requiere Composer
3. **HTML → PDF directo**: Convierte HTML/CSS a PDF perfectamente
4. **Compatible con TinyMCE**: Funciona excelentemente con contenido HTML del editor
5. **Soporte CSS**: Soporta la mayoría de CSS moderno
6. **Ligera**: No necesita servidor adicional
7. **Mantenida activamente**: Proyecto activo con buena documentación

### ❌ Alternativas (y por qué no recomendarlas):

- **wkhtmltopdf**: Requiere instalar ejecutable en el sistema, más complejo
- **TCPDF**: Más antiguo, sintaxis más verbosa
- **FPDF**: Muy básico, no soporta HTML directamente
- **mPDF**: Buena alternativa, pero dompdf es más simple

## 🚀 Instalación

### Paso 1: Verificar que tienes Composer

```bash
composer --version
```

Si no tienes Composer, instálalo:
- Windows: [Descargar Composer-Setup.exe](https://getcomposer.org/download/)
- O manualmente: https://getcomposer.org/download/

### Paso 2: Instalar dompdf

```bash
cd C:\wamp64\www\PORTAL_ESTUDIOS
composer require dompdf/dompdf
```

### Paso 3: Verificar instalación

```php
<?php
// test-dompdf.php
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;

$dompdf = new Dompdf();
$dompdf->loadHtml('<h1>Test PDF</h1><p>Si ves esto, dompdf está funcionando correctamente.</p>');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$output = $dompdf->output();
file_put_contents('test-output.pdf', $output);

echo "✅ dompdf instalado correctamente! Revisa test-output.pdf\n";
?>
```

## 📝 Actualización del Código

El archivo `api/informes/send-to-pacs.php` **YA está preparado** para usar dompdf automáticamente.

Solo necesita que instales la librería y funcionará inmediatamente.

### Código actual en send-to-pacs.php:

```php
// Intentar usar dompdf si está disponible
if (class_exists('Dompdf\Dompdf')) {
    try {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    } catch (Exception $e) {
        error_log("Error usando dompdf: " . $e->getMessage());
    }
}
```

**No necesitas cambiar nada en el código.** Solo instalar dompdf.

## 🔧 Configuración Adicional (Opcional)

### Autoload de Composer

Si no tienes un `composer.json` en la raíz, créalo:

```json
{
    "require": {
        "dompdf/dompdf": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

### Requerir autoload en send-to-pacs.php

El código actual asume que `Dompdf\Dompdf` está disponible. Si no funciona automáticamente, agrega al inicio de `send-to-pacs.php`:

```php
// Si necesitas cargar autoload manualmente
require_once __DIR__ . '/../../vendor/autoload.php';
```

## ✅ Verificación Final

Después de instalar, prueba enviando un informe:

```javascript
// Desde la consola del navegador o desde tu código
fetch('/api/informes/send-to-pacs.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({
        informe_id: 1,
        check_duplicates: false // Temporalmente para prueba
    })
})
.then(r => r.json())
.then(data => console.log(data));
```

## 🐛 Troubleshooting

### Error: "Class 'Dompdf\Dompdf' not found"

**Solución:**
1. Verificar que `vendor/autoload.php` existe
2. Agregar al inicio de `send-to-pacs.php`:
   ```php
   require_once __DIR__ . '/../../vendor/autoload.php';
   ```

### Error: "No se pudo generar el PDF desde el contenido HTML"

**Solución:**
1. Verificar que el HTML es válido
2. Revisar logs de PHP para errores específicos
3. Probar con HTML simple primero:
   ```php
   $htmlContent = '<h1>Test</h1><p>Contenido simple</p>';
   ```

### Error de memoria o timeout

**Solución:**
1. Aumentar `memory_limit` en `php.ini`
2. Aumentar `max_execution_time` en `php.ini`
3. Optimizar el HTML antes de convertirlo

## 📚 Documentación de dompdf

- **GitHub:** https://github.com/dompdf/dompdf
- **Documentación:** https://github.com/dompdf/dompdf/wiki
- **Ejemplos:** https://github.com/dompdf/dompdf/tree/master/www/test

## 🎉 Resultado

Una vez instalado dompdf, el sistema de envío a PACS funcionará completamente:

1. ✅ Usuario hace clic en "Enviar a PACS"
2. ✅ Sistema carga informe desde BD
3. ✅ Genera PDF desde HTML con dompdf
4. ✅ Convierte a DICOM Encapsulated PDF
5. ✅ Envía a Orthanc PACS
6. ✅ Guarda referencias en BD

---

**Instalación requerida:** `composer require dompdf/dompdf`  
**Estado del código:** ✅ Listo para usar (solo falta instalar la librería)

