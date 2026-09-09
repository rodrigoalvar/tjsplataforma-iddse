# 🔍 Cómo Verificar que Imagick está Instalado en PHP

## 🚀 Método 1: Verificación Visual (MÁS FÁCIL)

**Abrir en navegador:**
```
http://localhost/verificar-imagick.php
```

**Lo que muestra:**
- ✅ Si Imagick está instalado
- ✅ Versión de Imagick
- ✅ Si puede leer PDFs
- ✅ Formatos soportados
- ✅ Información completa del sistema

---

## 💻 Método 2: Línea de Comandos (PowerShell)

### Verificación Rápida

```powershell
cd C:\wamp64\bin\php\php8.2.18
php -m | findstr imagick
```

**✅ Si está instalado:**
```
imagick
```

**❌ Si NO está instalado:**
```
(Nada - no muestra imagick)
```

**⚠️ Si hay error:**
```
Warning: PHP Startup: Unable to load...
```
(Este mensaje indica problema con DLLs)

---

### Verificación Detallada

```powershell
# Verificar si la extensión está cargada
php -r "echo extension_loaded('imagick') ? '✅ Instalado' : '❌ No instalado';"
```

**✅ Debe mostrar:**
```
✅ Instalado
```

---

### Verificar Versión de Imagick

```powershell
php -r "$img = new Imagick(); echo $img->getVersion()['versionString'];"
```

**✅ Debe mostrar:**
```
ImageMagick 7.x.x-Q16-HDRI x64 (por ejemplo)
```

**❌ Si hay error:**
```
Fatal error: Uncaught Error: Class 'Imagick' not found...
```
(Imagick NO está instalado)

---

### Verificar si puede leer PDFs

```powershell
php -r "$img = new Imagick(); $formats = $img->queryFormats('PDF'); echo in_array('PDF', $formats) ? '✅ Puede leer PDFs' : '❌ NO puede leer PDFs';"
```

**✅ Debe mostrar:**
```
✅ Puede leer PDFs
```

---

## 📋 Método 3: Crear Script PHP Simple

**Crear archivo:** `C:\wamp64\www\test-imagick.php`

```php
<?php
if (extension_loaded('imagick')) {
    echo "✅ Imagick está instalado\n";
    
    try {
        $imagick = new Imagick();
        $version = $imagick->getVersion();
        echo "Versión: " . $version['versionString'] . "\n";
        
        // Verificar PDF
        $formats = $imagick->queryFormats('PDF');
        if (in_array('PDF', $formats)) {
            echo "✅ Puede leer PDFs\n";
        } else {
            echo "❌ NO puede leer PDFs\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Imagick NO está instalado\n";
}
?>
```

**Ejecutar:**
```powershell
php C:\wamp64\www\test-imagick.php
```

**O abrir en navegador:**
```
http://localhost/test-imagick.php
```

---

## 📊 Método 4: phpinfo()

**Crear archivo:** `C:\wamp64\www\phpinfo-imagick.php`

```php
<?php
phpinfo(INFO_MODULES);
?>
```

**Abrir en navegador:**
```
http://localhost/phpinfo-imagick.php
```

**Buscar:**
- Buscar "imagick" en la página
- Si aparece una sección de Imagick → ✅ Instalado
- Si NO aparece → ❌ No instalado

---

## ✅ Resultados Esperados

### ✅ Instalación Correcta

```powershell
PS> php -m | findstr imagick
imagick

PS> php -r "echo extension_loaded('imagick');"
1

PS> php -r "$img = new Imagick(); echo $img->getVersion()['versionString'];"
ImageMagick 7.1.1-28 Q16-HDRI x64 2023-09-23
```

### ❌ No Instalado

```powershell
PS> php -m | findstr imagick
(Nada - vacío)

PS> php -r "$img = new Imagick();"
Fatal error: Uncaught Error: Class 'Imagick' not found...
```

### ⚠️ Error de DLLs Faltantes

```powershell
PS> php -m | findstr imagick
Warning: PHP Startup: Unable to load dynamic library 'imagick'...
```

**Significa:** php_imagick.dll existe pero faltan DLLs de ImageMagick

---

## 🔍 Verificación Completa (Script del Sistema)

**Ejecutar:**
```powershell
cd C:\wamp64\www\PORTAL_ESTUDIOS
php api/informes/verificar_requisitos.php
```

**Debe mostrar:**
```
🖼️ Verificando Imagick...
   ✅ Imagick está instalado
   ✅ Imagick funciona correctamente
   ✅ Imagick puede leer PDFs
   
🖼️ FORMATO JPG:
   ✅ DISPONIBLE - Usando: Imagick
```

---

## 📋 Checklist Rápido

Para verificar que Imagick está correctamente instalado:

- [ ] `php -m | findstr imagick` muestra "imagick"
- [ ] `extension_loaded('imagick')` retorna `true`
- [ ] Se puede crear instancia: `new Imagick()`
- [ ] Se puede obtener versión: `getVersion()`
- [ ] Puede leer PDFs: `queryFormats('PDF')` incluye "PDF"
- [ ] No hay warnings al ejecutar PHP

---

## 🎯 Resumen de Comandos

### Verificación Básica (30 segundos)
```powershell
php -m | findstr imagick
```

### Verificación Completa (1 minuto)
```
Abrir: http://localhost/verificar-imagick.php
```

### Verificación del Sistema (2 minutos)
```powershell
php api/informes/verificar_requisitos.php
```

---

## 💡 Interpretación de Resultados

| Resultado | Significado |
|-----------|-------------|
| `imagick` aparece en `php -m` | ✅ Extensión cargada |
| `new Imagick()` funciona | ✅ Funcionalidad OK |
| `queryFormats('PDF')` incluye PDF | ✅ Puede leer PDFs |
| Warning: Unable to load | ⚠️ Faltan DLLs |
| Class 'Imagick' not found | ❌ No instalado |
| Sin errores ni warnings | ✅ Todo perfecto |

---

**El método más rápido:** Abrir `http://localhost/verificar-imagick.php` en el navegador 🚀

