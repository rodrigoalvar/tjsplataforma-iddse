# 🎯 Guía de Selección de Formato: PDF vs PNG

## 📋 Resumen Rápido

Tu sistema ahora puede enviar informes en **2 formatos**:

| Formato | Parámetro | Tipo DICOM | Resultado |
|---------|-----------|------------|-----------|
| 📄 **PDF** | `format: 'pdf'` | Encapsulated PDF | Documento PDF en PACS |
| 🖼️ **PNG/JPG** | `format: 'jpg'` | Secondary Capture | Imagen PNG en PACS |

---

## 🚀 Cómo Elegir el Formato

### Opción 1: Desde JavaScript (Frontend)

```javascript
// ✅ ENVIAR COMO PDF
const enviarComoPDF = async (informeId) => {
  const response = await fetch('/api/informes/send-to-pacs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + sessionToken
    },
    body: JSON.stringify({
      informe_id: informeId,
      format: 'pdf'  // 👈 PDF
    })
  });
  
  const result = await response.json();
  if (result.success) {
    console.log('✅ Enviado como PDF');
  }
};

// ✅ ENVIAR COMO PNG/IMAGEN
const enviarComoPNG = async (informeId) => {
  const response = await fetch('/api/informes/send-to-pacs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + sessionToken
    },
    body: JSON.stringify({
      informe_id: informeId,
      format: 'jpg'  // 👈 PNG/IMAGEN (el parámetro se llama 'jpg' pero envía PNG)
    })
  });
  
  const result = await response.json();
  if (result.success) {
    console.log('✅ Enviado como Imagen PNG');
  }
};
```

### Opción 2: Con Selector de Usuario

```html
<!-- Selector simple con radio buttons -->
<div class="formato-selector">
  <h3>Selecciona el formato de envío:</h3>
  
  <label>
    <input type="radio" name="formato" value="pdf" checked>
    📄 PDF (Documento) - Texto seleccionable
  </label>
  
  <label>
    <input type="radio" name="formato" value="jpg">
    🖼️ PNG (Imagen) - Máxima compatibilidad
  </label>
  
  <button onclick="enviarInforme()">Enviar a PACS</button>
</div>

<script>
function enviarInforme() {
  // Obtener formato seleccionado
  const formatoSeleccionado = document.querySelector('input[name="formato"]:checked').value;
  
  // Enviar con el formato elegido
  fetch('/api/informes/send-to-pacs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + sessionToken
    },
    body: JSON.stringify({
      informe_id: informeId,
      format: formatoSeleccionado  // 👈 'pdf' o 'jpg'
    })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert(`✅ Enviado como ${formatoSeleccionado.toUpperCase()}`);
    }
  });
}
</script>
```

### Opción 3: Con Toggle Switch Elegante

Ver el archivo completo en: `api/informes/ejemplo_frontend_selector_formato.html`

```html
<!-- Toggle Switch PDF/PNG -->
<div class="toggle-container">
  <input type="radio" name="format" id="optPdf" value="pdf" checked>
  <input type="radio" name="format" id="optPng" value="jpg">
  
  <div class="toggle-switch">
    <label for="optPdf" class="toggle-option">
      📄 PDF
    </label>
    <label for="optPng" class="toggle-option">
      🖼️ PNG
    </label>
  </div>
</div>

<button id="btnEnviar">Enviar a PACS</button>

<script>
document.getElementById('btnEnviar').addEventListener('click', () => {
  const formato = document.querySelector('input[name="format"]:checked').value;
  
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
});
</script>
```

### Opción 4: Dropdown/Select

```html
<select id="formatoEnvio">
  <option value="pdf">📄 PDF - Documento con texto seleccionable</option>
  <option value="jpg">🖼️ PNG - Imagen compatible con todos los visores</option>
</select>

<button onclick="enviar()">Enviar a PACS</button>

<script>
function enviar() {
  const formato = document.getElementById('formatoEnvio').value;
  
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

## 💡 ¿Cuándo usar cada formato?

### Usar 📄 PDF cuando:

✅ Necesitas texto seleccionable y copiable  
✅ El informe se va a imprimir  
✅ Tu visor DICOM soporta PDFs  
✅ Quieres un archivo más ligero (1-2 MB)  
✅ Es un informe médico estándar con texto  

**Ejemplo de uso:**
```javascript
// Informes estándar
enviarInforme(informeId, 'pdf');
```

### Usar 🖼️ PNG cuando:

✅ Necesitas máxima compatibilidad con visores DICOM  
✅ Tu visor DICOM NO soporta PDFs  
✅ Trabajas con sistemas legacy  
✅ Priorizas visualización inmediata sin plugins  
✅ No necesitas copiar texto del informe  

**Ejemplo de uso:**
```javascript
// Para máxima compatibilidad
enviarInforme(informeId, 'jpg');
```

---

## 🎨 Ejemplo Completo con Interfaz Moderna

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Enviar a PACS</title>
    <style>
        .formato-card {
            display: inline-block;
            border: 2px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin: 10px;
            cursor: pointer;
            transition: all 0.3s;
            width: 200px;
            text-align: center;
        }
        
        .formato-card:hover {
            border-color: #667eea;
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .formato-card.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .formato-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .btn-enviar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }
        
        .btn-enviar:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <h1>Enviar Informe a PACS</h1>
    
    <div id="selector">
        <!-- Card para PDF -->
        <div class="formato-card selected" data-format="pdf" onclick="seleccionarFormato('pdf')">
            <div class="formato-icon">📄</div>
            <h3>PDF</h3>
            <p>Documento con texto seleccionable</p>
            <small>Tamaño: ~1-2 MB</small>
        </div>
        
        <!-- Card para PNG -->
        <div class="formato-card" data-format="jpg" onclick="seleccionarFormato('jpg')">
            <div class="formato-icon">🖼️</div>
            <h3>PNG/Imagen</h3>
            <p>Máxima compatibilidad DICOM</p>
            <small>Tamaño: ~3-8 MB</small>
        </div>
    </div>
    
    <button class="btn-enviar" onclick="enviarAPACS()">
        📤 Enviar a PACS
    </button>
    
    <div id="resultado"></div>
    
    <script>
        let formatoSeleccionado = 'pdf';
        const informeId = 123; // Cambiar por ID real
        const sessionToken = 'tu_token_aqui'; // Cambiar por token real
        
        function seleccionarFormato(formato) {
            formatoSeleccionado = formato;
            
            // Actualizar UI
            document.querySelectorAll('.formato-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`[data-format="${formato}"]`).classList.add('selected');
        }
        
        async function enviarAPACS() {
            const btn = document.querySelector('.btn-enviar');
            btn.disabled = true;
            btn.textContent = '⏳ Enviando...';
            
            try {
                const response = await fetch('/api/informes/send-to-pacs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + sessionToken
                    },
                    body: JSON.stringify({
                        informe_id: informeId,
                        format: formatoSeleccionado
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('resultado').innerHTML = `
                        <div style="background: #d4edda; padding: 15px; border-radius: 5px; margin-top: 20px;">
                            <strong>✅ ${result.message}</strong><br>
                            Formato: ${result.format.toUpperCase()}<br>
                            Tamaño: ${result.data.file_size_mb} MB<br>
                            Instance ID: ${result.data.instance_id}
                        </div>
                    `;
                } else {
                    document.getElementById('resultado').innerHTML = `
                        <div style="background: #f8d7da; padding: 15px; border-radius: 5px; margin-top: 20px;">
                            <strong>❌ Error:</strong> ${result.message}
                        </div>
                    `;
                }
            } catch (error) {
                document.getElementById('resultado').innerHTML = `
                    <div style="background: #f8d7da; padding: 15px; border-radius: 5px; margin-top: 20px;">
                        <strong>❌ Error de conexión:</strong> ${error.message}
                    </div>
                `;
            } finally {
                btn.disabled = false;
                btn.textContent = '📤 Enviar a PACS';
            }
        }
    </script>
</body>
</html>
```

---

## 📊 Tabla de Decisión Rápida

| Tu Necesidad | Formato a Usar |
|-------------|----------------|
| Visor DICOM estándar moderno | 📄 PDF |
| Visor DICOM legacy/antiguo | 🖼️ PNG |
| Necesito copiar texto | 📄 PDF |
| Solo visualizar | 🖼️ PNG |
| Archivar largo plazo | 📄 PDF |
| Integración rápida | 🖼️ PNG |
| Archivo más pequeño | 📄 PDF |
| Compatibilidad garantizada | 🖼️ PNG |

---

## 🔄 Flujo de Procesamiento

### Cuando eliges 📄 PDF:
```
HTML → PDF (TCPDF) → Guardar PDF → Enviar PDF a PACS
```

### Cuando eliges 🖼️ PNG:
```
HTML → PDF (TCPDF) → Guardar PDF → Convertir PDF→PNG → Guardar PNG → Enviar PNG a PACS
```

---

## ⚙️ Configuración por Defecto

Si NO especificas el formato, se usa **PDF** por defecto:

```javascript
// Sin especificar formato → usa PDF
fetch('/api/informes/send-to-pacs.php', {
  body: JSON.stringify({
    informe_id: 123
    // format: 'pdf' <- por defecto
  })
});
```

---

## 🧪 Ejemplos de Prueba

### Probar PDF:
```bash
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{"informe_id": 123, "format": "pdf"}'
```

### Probar PNG:
```bash
curl -X POST http://localhost/api/informes/send-to-pacs.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{"informe_id": 123, "format": "jpg"}'
```

---

## 💡 Tips Importantes

1. **El parámetro se llama 'jpg' pero envía PNG**
   - Internamente se convierte a PNG de alta calidad
   - PNG es mejor para DICOM que JPG (sin pérdida)

2. **Ambos formatos son válidos DICOM**
   - PDF: SOP Class `1.2.840.10008.5.1.4.1.1.104.1`
   - PNG: SOP Class `1.2.840.10008.5.1.4.1.1.7` (Secondary Capture)

3. **Los archivos se guardan en disco**
   - PDFs: `/uploads/pdf_informes/`
   - PNGs: `/uploads/png_informes/`

---

## 🎯 Resumen

**Para elegir el formato solo necesitas:**

```javascript
// ELEGIR PDF
format: 'pdf'

// ELEGIR PNG
format: 'jpg'
```

¡Así de simple! 🎉

---

_Última actualización: 30 de Octubre, 2025_

