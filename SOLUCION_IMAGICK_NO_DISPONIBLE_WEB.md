# 🔧 Solución: Imagick Funciona en CLI pero NO en Web

## ❌ Problema Detectado

**Síntoma:**
- ✅ `php -m | findstr imagick` → Funciona (CLI)
- ✅ `php -r "new Imagick();"` → Funciona (CLI)
- ❌ `http://localhost/api/informes/send-to-pacs.php` → Error (Web)
- ❌ Error: "No hay ninguna librería de conversión PDF a PNG disponible"

**Causa:**
Imagick está configurado para **PHP CLI** pero **NO para PHP-FPM/Apache** (servidor web).

---

## 🔍 Diagnóstico Rápido

**Ejecutar diagnóstico desde web:**
```
http://localhost/portal_estudios/api/informes/diagnostico-imagick-web.php
```

**Esto mostrará:**
- ✅ Si Imagick está cargado desde el contexto web
- ✅ Qué php.ini está usando Apache
- ✅ Si el archivo DLL existe
- ✅ Recomendaciones específicas

---

## ✅ Solución Paso a Paso

### Paso 1: Identificar php.ini Correcto

**CLI usa:** `C:\wamp64\bin\php\php8.2.18\php.ini`  
**Apache necesita:** Un php.ini diferente (a veces el mismo, a veces no)

**Verificar php.ini de Apache:**
1. Abrir: `http://localhost/portal_estudios/api/informes/diagnostico-imagick-web.php`
2. Buscar línea: **"php.ini cargado:"**
3. Esa es la ruta del php.ini que usa Apache

**O crear archivo `phpinfo.php`:**
```php
<?php
phpinfo();
?>
```

Abrir: `http://localhost/phpinfo.php`  
Buscar: **"Loaded Configuration File"**  
Esa es la ruta del php.ini de Apache.

---

### Paso 2: Habilitar Imagick en php.ini de Apache

**Editar el php.ini que usa Apache** (no el de CLI):

1. Abrir el archivo php.ini identificado en Paso 1
2. Buscar sección `[ExtensionList]` o buscar `imagick`
3. Agregar o descomentar:
   ```ini
   extension=imagick
   ```
4. **Guardar** el archivo

---

### Paso 3: Verificar DLLs en Directorio Correcto

**Verificar que php_imagick.dll existe:**

Desde el phpinfo.php, buscar:
- **"extension_dir"** → Esa es la ruta que usa Apache

**Copiar DLLs si faltan:**

1. Desde: `C:\wamp64\bin\php\php8.2.18\ext\php_imagick.dll`
2. A: La ruta que muestra "extension_dir" en phpinfo

**También copiar DLLs de ImageMagick:**

1. Copiar **TODOS los DLLs** de:
   ```
   C:\wamp64\bin\php\php8.2.18\
   ```
2. A la ruta que muestra "extension_dir" en phpinfo

**DLLs necesarios:**
- `php_imagick.dll`
- `libMagickCore*.dll`
- `libMagickWand*.dll`
- Todos los `CORE_RL_*.dll`

---

### Paso 4: Reiniciar WAMP Completamente

**⚠️ IMPORTANTE:** Debes reiniciar WAMP completamente, no solo Apache.

1. **Click derecho** en ícono WAMP (systray)
2. **"Stop All Services"**
3. **Esperar** que se detengan completamente
4. **"Start All Services"**
5. **O mejor:** Reiniciar Windows completamente

---

### Paso 5: Verificar

**Desde web (no CLI):**
```
http://localhost/portal_estudios/api/informes/diagnostico-imagick-web.php
```

**Debe mostrar:**
```
✅ extension_loaded('imagick'): SÍ
✅ class_exists('Imagick'): SÍ
✅ Imagick funciona correctamente
✅ Puede leer PDFs
```

---

## 🔄 Solución Temporal (Mientras Solucionas)

Si necesitas enviar informes AHORA:

**1. Cambiar toggle switch a PDF:**
- El toggle en Informes Manager debe estar a la izquierda (PDF)
- Todos los envíos funcionarán como PDF

**2. El sistema tiene fallback automático:**
- Si seleccionas PNG pero falla
- El sistema enviará como PDF automáticamente
- Te avisará en el mensaje de respuesta

---

## 🎯 Verificación Final

**Probar envío:**
1. Toggle switch en Informes Manager → PNG (derecha)
2. Click "Enviar a PACS"
3. **Si funciona:** ✅ Imagick está correctamente configurado
4. **Si falla pero envía PDF:** ⚠️ Imagick no disponible, pero fallback funcionó
5. **Si falla completamente:** ❌ Revisar pasos 1-4

---

## 💡 Problemas Comunes

### Problema: "No encuentro php.ini de Apache"

**Solución:**
1. Crear `phpinfo.php` con `<?php phpinfo(); ?>`
2. Abrir en navegador
3. Buscar "Loaded Configuration File"
4. Esa es la ruta correcta

### Problema: "Agregué extension=imagick pero no funciona"

**Soluciones:**
1. Verificar que NO está comentado (sin `;` al inicio)
2. Verificar que estás editando el php.ini correcto
3. Reiniciar WAMP completamente
4. Verificar que php_imagick.dll existe en extension_dir

### Problema: "DLLs no están en el directorio correcto"

**Solución:**
1. Desde phpinfo, obtener "extension_dir"
2. Copiar php_imagick.dll allí
3. Copiar todos los DLLs de ImageMagick también

---

## 📋 Checklist

Después de seguir los pasos, verificar:

- [ ] php.ini de Apache identificado
- [ ] `extension=imagick` agregado (sin `;`)
- [ ] php_imagick.dll en extension_dir correcto
- [ ] DLLs de ImageMagick en extension_dir
- [ ] WAMP reiniciado completamente
- [ ] Diagnóstico web muestra Imagick disponible
- [ ] Prueba de envío PNG funciona

---

## 🔗 Enlaces Útiles

- **Diagnóstico Web:** `http://localhost/portal_estudios/api/informes/diagnostico-imagick-web.php`
- **Verificación Requisitos:** `http://localhost/portal_estudios/api/informes/verificar_requisitos.php`
- **phpinfo:** Crear `phpinfo.php` con `<?php phpinfo(); ?>`

---

## ✅ Resumen Rápido

**El problema:** Imagick funciona en CLI pero no en web

**La solución:** 
1. Identificar php.ini de Apache
2. Habilitar `extension=imagick` ahí
3. Copiar DLLs a extension_dir de Apache
4. Reiniciar WAMP completamente

**Solución temporal:** 
- Usar PDF o dejar que el sistema haga fallback automático

---

_Última actualización: 30 de Octubre, 2025_

