# 🔧 Solución: Configurar GhostScript (gswin64c) para Imagick

## ✅ GhostScript Encontrado

**Ubicación:**
```
C:\Program Files\gs\gs10.05.1\bin\gswin64c.exe
```

**Problema:**
- Imagick busca `gs.exe` o `gs`
- En Windows, el ejecutable se llama `gswin64c.exe`
- Necesitamos crear un alias `gs.exe` que apunte a `gswin64c.exe`

---

## 🔧 Solución Rápida (Recomendada)

### Opción 1: Crear alias manualmente (Requiere Admin)

**1. Abrir PowerShell como Administrador:**
- Click derecho en PowerShell → "Ejecutar como administrador"

**2. Ejecutar estos comandos:**
```powershell
cd "C:\Program Files\gs\gs10.05.1\bin"
cmd /c mklink gs.exe gswin64c.exe
```

**O si mklink no funciona, crear copia:**
```powershell
cd "C:\Program Files\gs\gs10.05.1\bin"
Copy-Item gswin64c.exe gs.exe
```

**3. Verificar:**
```powershell
.\gs.exe --version
```

Debería mostrar:
```
GPL Ghostscript 10.05.1 (yyyy-mm-dd)
```

**4. Reiniciar WAMP completamente:**
- Detener todos los servicios
- Reiniciar servicios WAMP

---

### Opción 2: Renombrar archivo (Alternativa)

**Si no puedes crear enlace, puedes renombrar temporalmente:**

**⚠️ ADVERTENCIA:** Esto puede afectar otras aplicaciones que usan `gswin64c.exe`

**1. Abrir PowerShell como Administrador**

**2. Ejecutar:**
```powershell
cd "C:\Program Files\gs\gs10.05.1\bin"
Copy-Item gswin64c.exe gs.exe
```

**3. Verificar que funciona:**
```powershell
.\gs.exe --version
```

---

### Opción 3: Configurar Imagick para usar gswin64c

**Editar configuración de ImageMagick:**

**1. Ubicación de configuración:**
```
C:\Program Files\ImageMagick-7.x.x\config\delegates.xml
```

**2. Buscar línea:**
```xml
<delegate decode="pdf" command="&quot;@PSDelegate@&quot; -q -dQUIET -dSAFER -dBATCH -dNOPAUSE -dNOPROMPT -dMaxBitmap=500000000 -dAlignToPixels=0 -dGridFitTT=2 &quot;-sDEVICE=pngalpha&quot; -dTextAlphaBits=4 -dGraphicsAlphaBits=4 &quot;-r%s&quot; %s &quot;-sOutputFile=%s&quot; &quot;-f%s&quot; &quot;-f%s&quot;"/>
```

**3. Cambiar `@PSDelegate@` por la ruta completa:**
```xml
<delegate decode="pdf" command="&quot;C:\Program Files\gs\gs10.05.1\bin\gswin64c.exe&quot; -q -dQUIET -dSAFER -dBATCH -dNOPAUSE -dNOPROMPT -dMaxBitmap=500000000 -dAlignToPixels=0 -dGridFitTT=2 &quot;-sDEVICE=pngalpha&quot; -dTextAlphaBits=4 -dGraphicsAlphaBits=4 &quot;-r%s&quot; %s &quot;-sOutputFile=%s&quot; &quot;-f%s&quot; &quot;-f%s&quot;"/>
```

**⚠️ NOTA:** Esto puede requerir reiniciar servicios o servidor web.

---

## ✅ Verificación

### Verificar que gs.exe existe:
```powershell
Test-Path "C:\Program Files\gs\gs10.05.1\bin\gs.exe"
```

Debería devolver: `True`

### Verificar que funciona:
```powershell
& "C:\Program Files\gs\gs10.05.1\bin\gs.exe" --version
```

Debería mostrar la versión de GhostScript.

---

## 🚀 Probar Conversión

**Después de crear el alias:**

1. **Reiniciar WAMP completamente:**
   - Detener todos los servicios
   - Reiniciar servicios WAMP

2. **Probar en Informes Manager:**
   - Toggle switch a **PNG** (derecha, naranja)
   - Click "Enviar a PACS"
   - Verificar logs

**Logs esperados (éxito):**
```
=== INICIO CONVERSIÓN PDF A PNG ===
Usando Imagick para convertir PDF a PNG
PNG generado exitosamente: C:\wamp64\www\PORTAL_ESTUDIOS\uploads\png_informes\informe_XX_YYYYMMDD_HHMMSS.png
```

**Si aún falla:**
- Verificar que `gs.exe` existe en el directorio
- Verificar que el directorio está en PATH
- Reiniciar Windows completamente

---

## 📋 Checklist

- [ ] ✅ GhostScript instalado (`gswin64c.exe` existe)
- [ ] ✅ Directorio en PATH del sistema
- [ ] ✅ Alias `gs.exe` creado en el directorio
- [ ] ✅ `gs.exe --version` funciona
- [ ] ✅ WAMP reiniciado completamente
- [ ] ✅ Conversión PDF a PNG funciona

---

## 🔍 Troubleshooting

### Problema: "Acceso denegado" al crear alias

**Solución:**
1. Abrir PowerShell como Administrador
2. Ejecutar: `Set-ExecutionPolicy RemoteSigned -Scope CurrentUser`
3. Intentar crear alias nuevamente

### Problema: "gs.exe --version" no funciona

**Solución:**
1. Verificar que `gs.exe` existe en el directorio
2. Verificar que el directorio está en PATH
3. Abrir CMD nuevo (no reutilizar el existente)
4. Ejecutar `gs.exe --version` desde el directorio directamente

### Problema: Imagick aún no encuentra gs

**Solución:**
1. Verificar configuración de `delegates.xml` (Opción 3)
2. Reiniciar Windows completamente
3. Verificar que PHP puede ejecutar programas externos

---

## 📝 Resumen Rápido

**El problema:**
- Imagick busca `gs`
- Windows tiene `gswin64c.exe`

**La solución:**
- Crear alias `gs.exe` que apunte a `gswin64c.exe`
- Ubicación: `C:\Program Files\gs\gs10.05.1\bin\`

**Comando (como Admin):**
```powershell
cd "C:\Program Files\gs\gs10.05.1\bin"
cmd /c mklink gs.exe gswin64c.exe
```

**O copiar:**
```powershell
Copy-Item gswin64c.exe gs.exe
```

---

_Última actualización: 31 de Octubre, 2025_  
_Solución específica para GhostScript gswin64c en Windows_

