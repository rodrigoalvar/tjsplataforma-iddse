# 🚀 Guía de Inicio Rápido - Envío Multi-Formato a PACS

## ⚡ En 5 Pasos

### Paso 1️⃣: Verificar Requisitos (2 minutos)

```bash
php api/informes/verificar_requisitos.php
```

**Resultado esperado:**
```
✅ TCPDF está instalado
✅ Imagick está instalado
✅ GhostScript está instalado
✅ Sistema completamente funcional
```

**Si falta algo:**
```bash
# Para formato JPG (necesario)
sudo apt-get install php-imagick imagemagick
# O
sudo apt-get install ghostscript
```

---

### Paso 2️⃣: Probar el Sistema (2 minutos)

**Opción A - Línea de comandos:**
```bash
php api/informes/test_envio_formatos.php
```

**Opción B - Navegador:**
```
http://localhost/api/informes/test_envio_formatos.php?informe_id=1
```

---

### Paso 3️⃣: Ver Demo Visual (1 minuto)

Abrir en navegador:
```
http://localhost/api/informes/ejemplo_frontend_selector_formato.html
```

Probar el toggle switch PDF/JPG y enviar un informe de prueba.

---

### Paso 4️⃣: Integrar en tu Frontend

**Código mínimo:**

```javascript
// En tu código existente, agregar selector de formato:

const formato = 'pdf'; // o 'jpg' según selección del usuario

fetch('/api/informes/send-to-pacs.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + tuToken
  },
  body: JSON.stringify({
    informe_id: informeId,
    format: formato
  })
})
.then(res => res.json())
.then(data => {
  if (data.success) {
    console.log(`✅ Enviado como ${formato.toUpperCase()}`);
  }
});
```

---

### Paso 5️⃣: Agregar UI (Opcional)

**Copiar del ejemplo:**

Extraer de `ejemplo_frontend_selector_formato.html`:
- Toggle switch CSS (líneas 20-120)
- HTML del selector (líneas 175-190)
- Lógica JavaScript (líneas 220-280)

O usar el componente completo directamente.

---

## 🎯 Uso Inmediato

### Enviar como PDF

```bash
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{"informe_id": 123, "format": "pdf"}'
```

### Enviar como JPG

```bash
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{"informe_id": 123, "format": "jpg"}'
```

---

## ❓ FAQ Rápido

### ¿Qué formato usar?

| Necesitas... | Usa |
|-------------|-----|
| Texto seleccionable | 📄 PDF |
| Máxima compatibilidad | 🖼️ JPG |
| Archivo más ligero | 📄 PDF |
| Visualización garantizada | 🖼️ JPG |

### ¿Funciona sin Imagick?

✅ **PDF**: Sí, solo necesita TCPDF  
⚠️ **JPG**: No, necesita Imagick O GhostScript

### ¿Dónde se guardan los archivos?

```
/uploads/pdf_informes/   <- PDFs generados
/uploads/png_informes/   <- PNGs para envío como JPG
/logs/php_errors.log     <- Logs del sistema
```

### ¿Cómo ver los logs?

```bash
tail -f logs/php_errors.log
```

O buscar específicamente:
```bash
grep "SEND_TO_PACS" logs/php_errors.log
```

---

## 🔧 Solución Rápida de Problemas

### ❌ "No hay librería de conversión PDF a PNG"

```bash
sudo apt-get install php-imagick imagemagick
sudo service apache2 restart
```

### ❌ "Imagick no puede leer PDFs"

```bash
sudo nano /etc/ImageMagick-6/policy.xml
# Buscar: rights="none" pattern="PDF"
# Cambiar a: rights="read|write" pattern="PDF"
sudo service apache2 restart
```

### ❌ "Session inválida"

Verificar que el token de autorización sea válido:
```javascript
console.log(sessionStorage.getItem('session_token'));
```

---

## 📚 Más Información

- **Documentación completa**: `README_ENVIO_PACS_MULTIFORMATO.md`
- **Guía técnica**: `api/informes/ENVIO_PACS_FORMATOS.md`
- **Resumen ejecutivo**: `RESUMEN_IMPLEMENTACION.md`

---

## ✅ Checklist Pre-Producción

- [ ] Verificar requisitos instalados
- [ ] Ejecutar pruebas exitosamente
- [ ] Probar envío PDF
- [ ] Probar envío JPG
- [ ] Verificar logs sin errores
- [ ] Verificar archivos guardados correctamente
- [ ] Verificar visualización en visor DICOM
- [ ] Integrar selector en frontend
- [ ] Capacitar usuarios sobre ambos formatos

---

## 🎉 ¡Listo!

Tu sistema ahora puede enviar informes en dos formatos. Selecciona el más apropiado según tus necesidades.

**¿Preguntas?** Revisa la documentación completa o los logs del sistema.

---

_Última actualización: 30 de Octubre, 2025_

