# 🎯 Cómo Elegir entre PDF y PNG

## 📌 Resumen Ultra-Rápido

Para elegir el formato solo cambia **1 parámetro**:

```javascript
// 📄 ENVIAR COMO PDF
body: JSON.stringify({
  informe_id: 123,
  format: 'pdf'  // 👈 PDF
})

// 🖼️ ENVIAR COMO PNG
body: JSON.stringify({
  informe_id: 123,
  format: 'jpg'  // 👈 PNG (sí, el parámetro se llama 'jpg')
})
```

---

## 🎨 Tres Formas de Implementarlo

### 1️⃣ Selector Visual (MÁS FÁCIL)

**Abrir en navegador:**
```
http://localhost/api/informes/selector_pdf_png.html
```

**Lo que hace:**
- ✅ Cards visuales para PDF y PNG
- ✅ Click para seleccionar
- ✅ Botón "Enviar a PACS"
- ✅ Muestra resultado en pantalla

**Para usar en tu app:**
1. Copia el archivo `selector_pdf_png.html`
2. Cambia `INFORME_ID` (línea 262)
3. ¡Listo!

---

### 2️⃣ Radio Buttons (SIMPLE)

```html
<form>
  <h3>Selecciona formato:</h3>
  
  <label>
    <input type="radio" name="formato" value="pdf" checked>
    📄 PDF (Documento)
  </label>
  <br>
  <label>
    <input type="radio" name="formato" value="jpg">
    🖼️ PNG (Imagen)
  </label>
  <br><br>
  <button type="button" onclick="enviar()">Enviar</button>
</form>

<script>
function enviar() {
  const formato = document.querySelector('input[name="formato"]:checked').value;
  
  fetch('/api/informes/send-to-pacs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + sessionToken
    },
    body: JSON.stringify({
      informe_id: informeId,
      format: formato  // 'pdf' o 'jpg'
    })
  })
  .then(res => res.json())
  .then(data => alert(data.message));
}
</script>
```

---

### 3️⃣ Dropdown (COMPACTO)

```html
<select id="formato">
  <option value="pdf">📄 PDF - Documento</option>
  <option value="jpg">🖼️ PNG - Imagen</option>
</select>
<button onclick="enviar()">Enviar</button>

<script>
function enviar() {
  const formato = document.getElementById('formato').value;
  
  fetch('/api/informes/send-to-pacs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + sessionToken
    },
    body: JSON.stringify({
      informe_id: informeId,
      format: formato
    })
  })
  .then(res => res.json())
  .then(data => console.log(data));
}
</script>
```

---

## 💡 Cuadro Comparativo

| Característica | 📄 PDF | 🖼️ PNG |
|---------------|--------|---------|
| **Tamaño** | 1-2 MB | 3-8 MB |
| **Texto seleccionable** | ✅ Sí | ❌ No |
| **Compatibilidad visores** | ⚠️ Algunos | ✅ Todos |
| **Necesita plugins** | ⚠️ A veces | ❌ No |
| **Calidad impresión** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ |
| **Velocidad carga** | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ |

---

## ❓ Guía de Decisión Rápida

### ¿Cuándo usar PDF? 📄

**USA PDF SI:**
- ✅ Tu visor DICOM soporta PDFs
- ✅ Necesitas copiar/pegar texto
- ✅ Vas a imprimir el informe
- ✅ Quieres archivo más ligero
- ✅ Es un informe médico estándar

**Ejemplo:**
```javascript
format: 'pdf'
```

---

### ¿Cuándo usar PNG? 🖼️

**USA PNG SI:**
- ✅ Tu visor DICOM NO soporta PDFs
- ✅ Trabajas con sistemas legacy
- ✅ Priorizas compatibilidad universal
- ✅ Solo necesitas visualizar (no editar)
- ✅ Integración debe ser inmediata

**Ejemplo:**
```javascript
format: 'jpg'  // Sí, el parámetro se llama 'jpg' pero envía PNG de alta calidad
```

---

## 🚀 Ejemplos Prácticos

### Ejemplo 1: Usuario Elige con Radio Buttons

```html
<!DOCTYPE html>
<html>
<body>
  <h2>Enviar Informe #123</h2>
  
  <div>
    <input type="radio" id="pdf" name="formato" value="pdf" checked>
    <label for="pdf">📄 PDF</label>
  </div>
  
  <div>
    <input type="radio" id="png" name="formato" value="jpg">
    <label for="png">🖼️ PNG</label>
  </div>
  
  <button onclick="enviarInforme(123)">Enviar a PACS</button>
  
  <script>
    function enviarInforme(informeId) {
      const formato = document.querySelector('input[name="formato"]:checked').value;
      
      fetch('/api/informes/send-to-pacs.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + sessionStorage.getItem('token')
        },
        body: JSON.stringify({
          informe_id: informeId,
          format: formato
        })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          alert('✅ Enviado como ' + formato.toUpperCase());
        } else {
          alert('❌ Error: ' + data.message);
        }
      });
    }
  </script>
</body>
</html>
```

---

### Ejemplo 2: Función Reutilizable

```javascript
// Función para enviar informe con formato específico
async function enviarInformeConFormato(informeId, formato) {
  // Validar formato
  if (!['pdf', 'jpg'].includes(formato)) {
    console.error('Formato inválido:', formato);
    return;
  }
  
  try {
    const response = await fetch('/api/informes/send-to-pacs.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + sessionStorage.getItem('token')
      },
      body: JSON.stringify({
        informe_id: informeId,
        format: formato
      })
    });
    
    const result = await response.json();
    return result;
    
  } catch (error) {
    console.error('Error al enviar:', error);
    throw error;
  }
}

// Usar la función
enviarInformeConFormato(123, 'pdf');  // Enviar como PDF
enviarInformeConFormato(456, 'jpg');  // Enviar como PNG
```

---

### Ejemplo 3: Con Confirmación Visual

```javascript
async function enviarConConfirmacion() {
  const formato = document.querySelector('input[name="formato"]:checked').value;
  const nombreFormato = formato === 'pdf' ? 'PDF' : 'PNG';
  
  if (confirm(`¿Enviar informe como ${nombreFormato}?`)) {
    const resultado = await fetch('/api/informes/send-to-pacs.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + sessionToken
      },
      body: JSON.stringify({
        informe_id: informeId,
        format: formato
      })
    }).then(res => res.json());
    
    if (resultado.success) {
      alert(`✅ ${resultado.message}`);
    } else {
      alert(`❌ ${resultado.message}`);
    }
  }
}
```

---

## 🔍 Preguntas Frecuentes

### ❓ ¿Por qué el parámetro se llama 'jpg' si envía PNG?

Por compatibilidad con la nomenclatura DICOM. Internamente se convierte a PNG de alta calidad (sin pérdida).

### ❓ ¿Puedo cambiar el formato predeterminado?

Sí, en `send-to-pacs.php` línea 133:
```php
$format = strtolower($input['format'] ?? 'pdf'); // Cambiar 'pdf' por 'jpg'
```

### ❓ ¿El sistema genera ambos formatos?

No. Solo genera el formato que especifiques:
- Si eliges `pdf` → solo genera PDF
- Si eliges `jpg` → genera PDF y luego lo convierte a PNG

### ❓ ¿Dónde se guardan los archivos?

- PDFs: `/uploads/pdf_informes/`
- PNGs: `/uploads/png_informes/`

Ambos se guardan para histórico.

### ❓ ¿Puedo enviar el mismo informe en ambos formatos?

Sí, pero debes hacer 2 llamadas:
```javascript
// Primera llamada - PDF
await enviarInforme(123, 'pdf');

// Segunda llamada - PNG
await enviarInforme(123, 'jpg');
```

Esto creará 2 series diferentes en el mismo estudio DICOM.

---

## 🎯 Resumen Final

### Para implementar la selección:

1. **Copia** uno de los ejemplos de arriba
2. **Cambia** `informeId` por el ID real
3. **Cambia** `sessionToken` por tu token real
4. **¡Listo!** El usuario podrá elegir PDF o PNG

### El parámetro clave es:

```javascript
format: 'pdf'  // o 'jpg' para PNG
```

---

## 📱 Demo Completa

**Ver demo funcionando:**
```
http://localhost/api/informes/selector_pdf_png.html
```

**Características del demo:**
- ✅ Interface visual moderna
- ✅ Cards clickeables
- ✅ Indicador de selección
- ✅ Botón de envío
- ✅ Resultado detallado
- ✅ Manejo de errores
- ✅ Loading states

**Para personalizar:**
1. Abrir `selector_pdf_png.html`
2. Cambiar línea 262: `const INFORME_ID = 123;`
3. Listo para usar

---

## ✅ Checklist de Implementación

- [ ] Decidir qué formato usar por defecto (PDF o PNG)
- [ ] Elegir tipo de selector (cards, radio, dropdown)
- [ ] Copiar código del ejemplo
- [ ] Cambiar `informeId` y `sessionToken`
- [ ] Probar envío con ambos formatos
- [ ] Verificar resultado en visor DICOM
- [ ] Capacitar usuarios sobre cuándo usar cada formato

---

**¿Aún con dudas?** 

Ver documentación completa en:
- `GUIA_SELECCION_FORMATO.md` - Guía detallada
- `README_ENVIO_PACS_MULTIFORMATO.md` - Documentación técnica

---

_¡Es así de simple! Solo cambia un parámetro y listo._ 🎉

