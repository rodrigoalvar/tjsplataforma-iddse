# 🔧 Solución: Faltan DLLs de ImageMagick

## ❌ Problema Detectado

El archivo `php_imagick.dll` existe, pero faltan los DLLs principales de ImageMagick:
- ❌ `libMagickCore*.dll` - NO encontrado
- ❌ `libMagickWand*.dll` - NO encontrado

Estos DLLs son necesarios para que Imagick funcione.

---

## ✅ Solución Recomendada: Instalar ImageMagick Completo

### Paso 1: Descargar ImageMagick Completo

**URL:** https://imagemagick.org/script/download.php#windows

**Descargar:**
- ImageMagick-7.x.x-Q16-HDRI-x64-dll.exe
- O la versión más reciente para Windows 64-bit

### Paso 2: Instalar ImageMagick

1. **Ejecutar el instalador** descargado
2. **Marcar esta opción CRÍTICA:**
   ```
   ☑️ Add application directory to your system PATH
   ```
3. **Instalar** con configuración por defecto
4. **Anotar ruta de instalación** (típicamente: `C:\Program Files\ImageMagick-7.x.x-Q16-HDRI`)

### Paso 3: Copiar DLLs Necesarios

**Copiar desde:** `C:\Program Files\ImageMagick-7.x.x-Q16-HDRI\`

**Copiar estos archivos a:** `C:\wamp64\bin\php\php8.2.18\`

**Archivos necesarios:**
```
libMagickCore-7.Q16HDRI-xx.dll    → Copiar como: libMagickCore-7.Q16HDRI.dll
libMagickWand-7.Q16HDRI-xx.dll    → Copiar como: libMagickWand-7.Q16HDRI.dll
```

**O mejor aún:** Copiar TODOS los DLLs de ImageMagick a la carpeta PHP

### Paso 4: Reiniciar WAMP

1. Stop All Services
2. Start All Services
3. O reiniciar Windows

### Paso 5: Verificar

```powershell
cd C:\wamp64\bin\php\php8.2.18
php -m | findstr imagick
```

**Debe mostrar:** `imagick` sin errores ✅

---

## 🔄 Solución Alternativa: Descargar Paquete Completo de Imagick

Si prefieres NO instalar ImageMagick completo, busca un paquete ZIP que incluya TODOS los DLLs necesarios.

### Buscar en:
1. **GitHub:** https://github.com/mkoppanen/imagick-windows/releases
2. **PECL Binaries:** https://windows.php.net/downloads/pecl/releases/imagick/
3. **Foros de PHP:** Buscar "imagick windows all dlls"

**Buscar:** Paquete que incluya:
- `php_imagick.dll`
- `libMagickCore*.dll`
- `libMagickWand*.dll`
- Todos los CORE_RL_*.dll (ya los tienes)

---

## 📋 Verificación Actual

**✅ Tienes:**
- `php_imagick.dll` en `ext\`
- Muchos `CORE_RL_*.dll` en directorio PHP

**❌ Faltan:**
- `libMagickCore*.dll`
- `libMagickWand*.dll`

---

## 🚀 Pasos Rápidos (Resumen)

```
1. Descargar ImageMagick completo:
   https://imagemagick.org/script/download.php#windows

2. Instalar con "Add to PATH" marcado

3. Copiar libMagickCore*.dll y libMagickWand*.dll a:
   C:\wamp64\bin\php\php8.2.18\

4. Reiniciar WAMP

5. Verificar: php -m | findstr imagick
```

---

## 💡 Nota Importante

El error "Unable to load dynamic library" significa que:
- ✅ La extensión está habilitada en php.ini
- ✅ El archivo php_imagick.dll existe
- ❌ Pero faltan las DLLs de dependencias (ImageMagick)

**Solución:** Instalar ImageMagick completo o copiar los DLLs faltantes.

---

## 🔍 Verificar qué DLLs faltan

Para ver qué DLLs exactamente necesita Imagick:

```powershell
cd C:\wamp64\bin\php\php8.2.18
php -r "dl('php_imagick.dll');"
```

Esto mostrará qué DLLs específicamente no encuentra.

---

_Última actualización: 30 de Octubre, 2025_

