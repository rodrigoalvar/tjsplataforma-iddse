# 🔧 Guía Completa: Instalar Imagick en WAMP64 con PHP 8.2.18

## 📋 Paso 1: Verificar tu Configuración PHP

**Ejecutar script de verificación:**
```
Abrir en navegador: http://localhost/verificar-php.php
```

**O crear archivo `phpinfo.php` en `C:\wamp64\www\`:**
```php
<?php
phpinfo();
?>
```

**Anotar EXACTAMENTE:**
- ✅ Versión PHP: **8.2.18**
- ✅ Architecture: **x64** o **x86**
- ✅ Thread Safety: **enabled (TS)** o **disabled (NTS)**
- ✅ Compiler: **MSVC19** o **VS16** o **VS17**

---

## 📥 Paso 2: Descargar Imagick Correcto

### Opción A: PECL Oficial (Recomendado)

**URL:** https://pecl.php.net/package/imagick

**Buscar:**
- Versión compatible con PHP 8.2
- Thread Safe (TS) si tu PHP tiene TS
- Non-Thread Safe (NTS) si tu PHP es NTS
- x64 si tu sistema es 64-bit

### Opción B: Binarios Precompilados (Más Fácil)

**URL:** https://windows.php.net/downloads/pecl/releases/imagick/

**Para PHP 8.2.18, buscar archivo como:**
```
php_imagick-3.7.0-8.2-ts-vs16-x64.zip    ← Thread Safe, VS16
php_imagick-3.7.0-8.2-nts-vs16-x64.zip   ← Non-Thread Safe, VS16
php_imagick-3.7.0-8.2-ts-vs17-x64.zip    ← Thread Safe, VS17
```

**O usar esta fuente alternativa:**
https://github.com/mkoppanen/imagick-windows/releases

---

## 🔧 Paso 3: Instalar Imagick

### 3.1 Deshabilitar Extensión Actual (Si Existe)

**Editar:** `C:\wamp64\bin\php\php8.2.18\php.ini`

**Buscar línea:**
```ini
extension=imagick
```

**Comentar o eliminar:**
```ini
;extension=imagick
```

### 3.2 Extraer Archivo Descargado

Extraer el ZIP descargado a una carpeta temporal (ejemplo: `C:\temp\imagick\`)

### 3.3 Copiar Archivos

**Copiar `php_imagick.dll` a:**
```
C:\wamp64\bin\php\php8.2.18\ext\
```

**Copiar TODOS los demás archivos .dll de la carpeta extraída a:**
```
C:\wamp64\bin\php\php8.2.18\
```

**Archivos que típicamente incluye:**
- `CORE_RL_*.dll`
- `libMagickCore*.dll`
- `libMagickWand*.dll`
- `msvcp*.dll`
- `msvcr*.dll`

**⚠️ IMPORTANTE:** Copiar TODOS los DLL, no solo php_imagick.dll

### 3.4 Habilitar Extensión

**Editar:** `C:\wamp64\bin\php\php8.2.18\php.ini`

**Agregar (o descomentar):**
```ini
extension=imagick
```

**Guardar archivo**

---

## 🔄 Paso 4: Reiniciar WAMP

**1. Detener todos los servicios:**
```
- Click derecho en ícono WAMP (systray)
- "Stop All Services"
```

**2. Iniciar servicios:**
```
- "Start All Services"
```

**O reiniciar completamente Windows si es necesario**

---

## ✅ Paso 5: Verificar Instalación

### Método 1: Línea de Comandos

**Abrir PowerShell o CMD:**
```bash
cd C:\wamp64\bin\php\php8.2.18
php -m | findstr imagick
```

**Si aparece `imagick` → ✅ Instalado correctamente**

### Método 2: Verificar desde PHP

**Crear archivo `test-imagick.php` en `C:\wamp64\www\`:**
```php
<?php
if (extension_loaded('imagick')) {
    echo "✅ Imagick está instalado\n";
    $imagick = new Imagick();
    $version = $imagick->getVersion();
    echo "Versión: " . $version['versionString'] . "\n";
    
    // Verificar soporte PDF
    $formats = $imagick->queryFormats('PDF');
    if (in_array('PDF', $formats)) {
        echo "✅ Imagick puede leer PDFs\n";
    } else {
        echo "⚠️ Imagick NO puede leer PDFs (verificar política)\n";
    }
} else {
    echo "❌ Imagick NO está instalado\n";
}
?>
```

**Abrir:** `http://localhost/test-imagick.php`

### Método 3: Script de Verificación del Sistema

```bash
cd C:\wamp64\www\PORTAL_ESTUDIOS
php api/informes/verificar_requisitos.php
```

---

## 🐛 Si Sigue Dando Error

### Error: "Entry Point Not Found"

**Causa:** Versión incorrecta o DLL faltantes

**Solución:**
1. ✅ Verificar que copiaste TODOS los DLL
2. ✅ Verificar que TS/NTS coincida
3. ✅ Intentar con otra versión (VS17 si VS16 no funciona)
4. ✅ Descargar ImageMagick completo e instalar primero

### Error: "Cannot load module"

**Causa:** DLL faltantes

**Solución:**
1. ✅ Instalar **Visual C++ Redistributable**
   - VS16: https://aka.ms/vs/17/release/vc_redist.x64.exe
   - O buscar en: https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist

### Error: "PDF not supported"

**Causa:** Política de seguridad de ImageMagick

**Solución:**
1. ✅ Editar: `C:\Program Files\ImageMagick-7.x\config\policy.xml`
2. ✅ Buscar: `<policy domain="coder" rights="none" pattern="PDF" />`
3. ✅ Cambiar a: `<policy domain="coder" rights="read|write" pattern="PDF" />`
4. ✅ Reiniciar WAMP

---

## 🎯 Solución Rápida Alternativa: Instalar ImageMagick Completo

Si los binarios DLL no funcionan, instalar ImageMagick completo:

### 1. Descargar ImageMagick
**URL:** https://imagemagick.org/script/download.php#windows

**Descargar:** Versión Windows 64-bit

### 2. Instalar ImageMagick
- Ejecutar instalador
- Marcar "Add application directory to your system path"
- Instalar

### 3. Instalar PHP Imagick Extension
- Seguir pasos 2-4 arriba
- Ahora debería funcionar porque ImageMagick está instalado

---

## 📋 Checklist Final

Antes de considerar que funciona:

- [ ] ✅ php_imagick.dll en `ext\` folder
- [ ] ✅ Todos los DLL de soporte en folder PHP
- [ ] ✅ `extension=imagick` en php.ini
- [ ] ✅ WAMP reiniciado completamente
- [ ] ✅ `php -m` muestra `imagick`
- [ ] ✅ `test-imagick.php` muestra versión
- [ ] ✅ Verificación de requisitos pasa
- [ ] ✅ Prueba de envío a PACS funciona

---

## 🚀 Después de Instalar

### 1. Verificar Sistema
```bash
php api/informes/verificar_requisitos.php
```

**Debe mostrar:**
```
✅ Imagick está instalado
✅ Imagick puede leer PDFs
✅ FORMATO JPG: DISPONIBLE
```

### 2. Probar Envío
1. Configurar formato PNG: `configuracion-pacs.html`
2. Enviar informe desde Informes Manager
3. Debe funcionar sin errores ✅

---

## 💡 Recursos Útiles

- **PECL Imagick:** https://pecl.php.net/package/imagick
- **Binarios Windows:** https://windows.php.net/downloads/pecl/releases/imagick/
- **GitHub Windows Binaries:** https://github.com/mkoppanen/imagick-windows
- **ImageMagick Completo:** https://imagemagick.org/

---

## ⚠️ Nota Importante

Si después de todos los intentos sigue fallando, la **solución más confiable** es:

1. ✅ Usar **PDF por defecto** (no requiere Imagick)
2. ✅ Solo usar PNG cuando sea absolutamente necesario
3. ✅ Considerar instalar XAMPP (viene con Imagick preconfigurado)

---

_Última actualización: 30 de Octubre, 2025_

