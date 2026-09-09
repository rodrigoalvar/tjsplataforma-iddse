# 🔍 Diagnóstico: Toggle Switch No Funciona

## ✅ Logs Agregados para Debugging

He agregado logs detallados en:
1. **JavaScript (`informes-manager.js`):**
   - Estado del toggle switch
   - Formato seleccionado
   - Body de la petición

2. **PHP (`send-to-pacs.php`):**
   - Input completo recibido
   - Campo format recibido
   - Formato procesado

---

## 🔍 Cómo Diagnosticar

### Paso 1: Abrir Consola del Navegador

1. Abrir Informes Manager:
   ```
   http://localhost/portal_estudios/components/informes-manager.html
   ```

2. Abrir Consola (F12 → Console)

3. Click en el toggle switch (mover a PNG - derecha)

4. Click en "Enviar a PACS"

5. Ver en consola:
   ```
   🔍 Toggle switch encontrado: <input...>
   🔍 Estado del toggle (checked): true/false
   📄 Formato seleccionado desde toggle global: jpg o pdf
   📄 Toggle está en: PNG (derecha/naranja) o PDF (izquierda/verde)
   📤 Preparando envío a PACS:
     - Informe ID: ...
     - Formato seleccionado: jpg o pdf
   📤 Body de la petición: {...}
   ```

### Paso 2: Verificar Logs del Servidor

**Buscar en logs:**
```bash
cd C:\wamp64\www\PORTAL_ESTUDIOS
tail -f logs/php_errors.log | findstr "SEND_TO_PACS"
```

**Deberías ver:**
```
[SEND_TO_PACS][INPUT] Input completo recibido: {...}
[SEND_TO_PACS][INPUT] Campo format recibido: jpg o pdf
[SEND_TO_PACS][FORMAT] Formato después de strtolower: jpg o pdf
[SEND_TO_PACS][FORMAT] ✅ Formato válido: jpg (tipo: PNG/Imagen) o pdf (tipo: PDF)
```

---

## ⚠️ Posibles Problemas

### Problema 1: Toggle Switch No Se Lee Correctamente

**Síntoma:**
- Toggle está en PNG pero logs muestran `checked: false`
- Formato seleccionado siempre es `pdf`

**Solución:**
- Verificar que el toggle tiene ID correcto: `pacs-format-global`
- Verificar que el DOM está cargado cuando se lee el toggle
- Puede haber un problema de timing

### Problema 2: Formato No Se Envía Correctamente

**Síntoma:**
- Logs en consola muestran `format: jpg`
- Logs en servidor muestran `format: pdf` o `format: NO RECIBIDO`

**Solución:**
- Verificar que el body de la petición incluye `format: jpg`
- Verificar que la API está leyendo correctamente el JSON

### Problema 3: Fallback a PDF Automático

**Síntoma:**
- Formato se envía como `jpg` correctamente
- Pero se envía como PDF de todos modos

**Causa:**
- Imagick no está disponible
- La conversión PNG falla
- El código hace fallback a PDF automáticamente

**Logs esperados:**
```
[SEND_TO_PACS][FORMAT] ✅ Formato válido: jpg (tipo: PNG/Imagen)
=== INICIO CONVERSIÓN PDF A PNG ===
EXCEPCIÓN EN CONVERSIÓN PDF A PNG: No hay ninguna librería de conversión...
⚠️ Conversión a PNG falló. El informe se enviará como PDF en lugar de fallar.
```

---

## 🔧 Soluciones

### Si el Toggle No Se Lee Correctamente:

**Verificar HTML:**
```html
<input type="checkbox" class="pacs-format-switch" id="pacs-format-global">
```

**Verificar que existe en DOM:**
```javascript
const toggle = document.getElementById('pacs-format-global');
console.log('Toggle:', toggle);
console.log('Checked:', toggle?.checked);
```

### Si el Formato No Se Envía:

**Verificar body de la petición:**
```javascript
const body = {
    informe_id: informeId,
    format: formatoSeleccionado,  // Debe ser 'jpg' o 'pdf'
    check_duplicates: true
};
console.log('Body:', JSON.stringify(body));
```

### Si Hay Fallback a PDF:

**Problema:**
- Imagick no está disponible en servidor web
- La conversión PDF a PNG falla
- El código automáticamente envía como PDF

**Solución:**
- Habilitar Imagick en php.ini de Apache
- Ver guía: `SOLUCION_IMAGICK_NO_DISPONIBLE_WEB.md`

---

## 📋 Checklist de Diagnóstico

1. [ ] Abrir consola del navegador (F12)
2. [ ] Click en toggle switch (mover a PNG)
3. [ ] Verificar en consola: `Toggle está en: PNG (derecha/naranja)`
4. [ ] Click "Enviar a PACS"
5. [ ] Verificar en consola: `Formato seleccionado: jpg`
6. [ ] Verificar en consola: `Body de la petición: {format: "jpg"}`
7. [ ] Verificar en logs del servidor: `Campo format recibido: jpg`
8. [ ] Verificar en logs del servidor: `Formato válido: jpg`

---

## 🎯 Próximos Pasos

1. **Ejecutar diagnóstico:**
   - Abrir consola del navegador
   - Intentar enviar con toggle en PNG
   - Ver logs en consola y servidor

2. **Reportar resultados:**
   - ¿Qué muestra la consola?
   - ¿Qué muestran los logs del servidor?
   - ¿En qué paso falla?

3. **Solución según problema:**
   - Si toggle no se lee → Verificar HTML/DOM
   - Si formato no se envía → Verificar petición
   - Si hay fallback a PDF → Habilitar Imagick

---

## 📝 Logs Esperados (Éxito)

### Consola del Navegador:
```
🔍 Toggle switch encontrado: <input id="pacs-format-global" ...>
🔍 Estado del toggle (checked): true
📄 Formato seleccionado desde toggle global: jpg
📄 Toggle está en: PNG (derecha/naranja)
📤 Preparando envío a PACS:
  - Informe ID: 123
  - Formato seleccionado: jpg
  - URL: http://localhost/portal_estudios/api/informes/send-to-pacs.php
📤 Body de la petición: {
  "informe_id": 123,
  "format": "jpg",
  "check_duplicates": true
}
```

### Logs del Servidor:
```
[SEND_TO_PACS][INPUT] Input completo recibido: {
  "informe_id": 123,
  "format": "jpg",
  "check_duplicates": true
}
[SEND_TO_PACS][INPUT] Campo format recibido: jpg
[SEND_TO_PACS][FORMAT] Formato después de strtolower: jpg
[SEND_TO_PACS][FORMAT] ✅ Formato válido: jpg (tipo: PNG/Imagen)
=== INICIO CONVERSIÓN PDF A PNG ===
PNG generado exitosamente: ...
```

---

_Última actualización: 30 de Octubre, 2025_  
_Logs de debugging agregados para diagnóstico_

