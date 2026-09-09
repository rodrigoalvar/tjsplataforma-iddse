# 🔧 Solución: Error GhostScript en Conversión PDF a PNG

## ❌ Problema Detectado

**Error en logs:**
```
FailedToExecuteCommand `"gs"` (The system cannot find the file specified.)
```

**Causa:**
Imagick necesita **GhostScript** (`gs`) para convertir PDF a PNG, pero GhostScript:
- ❌ No está instalado
- ❌ No está en el PATH del sistema
- ❌ No está en el PATH donde PHP/Imagick lo busca

---

## ✅ Soluciones

### Opción 1: Instalar GhostScript (Recomendado)

**1. Descargar GhostScript:**
- Descargar desde: https://www.ghostscript.com/download/gsdnld.html
- Versión recomendada: **GhostScript 10.x** (64-bit)
- Archivo: `gs1000w64.exe` (Windows 64-bit)

**2. Instalar GhostScript:**
1. Ejecutar el instalador
2. Instalar en ubicación predeterminada: `C:\Program Files\gs\gs10.x.x\`
3. **⚠️ IMPORTANTE:** Durante la instalación, asegurarse de:
   - ✅ Agregar GhostScript al PATH del sistema
   - ✅ Agregar al PATH de usuario
   - ✅ Registrar con el sistema

**3. Verificar instalación:**
```bash
# Abrir CMD como Administrador
gs --version
```

Debería mostrar:
```
GPL Ghostscript 10.x.x (yyyy-mm-dd)
```

**4. Verificar PATH:**
```bash
echo %PATH% | findstr "gs"
```

Debería incluir:
```
C:\Program Files\gs\gs10.x.x\bin
```

**5. Reiniciar WAMP:**
- Detener todos los servicios WAMP
- Reiniciar Windows (recomendado)
- Iniciar WAMP nuevamente

**6. Probar conversión:**
Enviar un informe con toggle en modo imagen (PNG)

---

### Opción 2: Agregar GhostScript al PATH Manualmente

**Si GhostScript ya está instalado pero no está en PATH:**

**1. Encontrar ubicación de GhostScript:**
```
C:\Program Files\gs\gs10.x.x\bin\
```
o
```
C:\Program Files (x86)\gs\gs10.x.x\bin\
```

**2. Agregar al PATH del Sistema:**
1. Abrir **Configuración del Sistema** → **Variables de entorno**
2. En **Variables del sistema**, seleccionar **Path**
3. Click **Editar**
4. Click **Nuevo**
5. Agregar: `C:\Program Files\gs\gs10.x.x\bin`
6. Click **Aceptar** en todas las ventanas

**3. Verificar:**
```bash
# Abrir CMD nuevo (no usar el existente)
gs --version
```

**4. Reiniciar WAMP:**
- Detener todos los servicios
- Reiniciar servicios WAMP

---

### Opción 3: Configurar PATH en PHP (Alternativa)

Si no puedes modificar el PATH del sistema, puedes configurarlo en PHP:

**Editar `php.ini`:**
```ini
[PATH]
include_path = "C:\Program Files\gs\gs10.x.x\bin;"
```

**O en el código PHP antes de usar Imagick:**
```php
putenv('PATH=' . getenv('PATH') . ';C:\Program Files\gs\gs10.x.x\bin');
```

**⚠️ NOTA:** Esto es menos confiable, mejor modificar PATH del sistema.

---

## 🔍 Verificar si GhostScript está Instalado

### Método 1: Buscar en Sistema
```bash
dir "C:\Program Files\gs" /s /b
```

### Método 2: Buscar Ejecutable
```bash
where gs
```

### Método 3: Intentar Ejecutar
```bash
gs --version
```

Si muestra error "no se reconoce como comando", GhostScript no está en PATH.

---

## 🎯 Solución Temporal: Usar PDF en lugar de PNG

Mientras solucionas GhostScript, puedes:

1. **Cambiar toggle a PDF** (izquierda, verde)
2. **Enviar como PDF** (funciona sin GhostScript)

El código automáticamente hace fallback a PDF si la conversión PNG falla, pero es mejor solucionar el problema raíz.

---

## 📋 Checklist de Verificación

Después de instalar GhostScript:

- [ ] ✅ GhostScript instalado
- [ ] ✅ `gs --version` funciona
- [ ] ✅ GhostScript en PATH del sistema
- [ ] ✅ WAMP reiniciado completamente
- [ ] ✅ PHP puede ejecutar `gs` (verificar en phpinfo o script)
- [ ] ✅ Conversión PDF a PNG funciona

---

## 🚀 Probar Conversión

**Después de instalar GhostScript:**

1. Abrir Informes Manager
2. Toggle switch a **PNG** (derecha, naranja)
3. Click "Enviar a PACS"
4. Ver logs - debería ver:
   ```
   === INICIO CONVERSIÓN PDF A PNG ===
   Usando Imagick para convertir PDF a PNG
   PNG generado exitosamente: ...
   ```

**Si funciona:**
```
✅ Conversión PDF a PNG exitosa
✅ Informe enviado como PNG/Imagen a Orthanc
```

**Si aún falla:**
- Verificar PATH del sistema
- Reiniciar Windows
- Verificar permisos de PHP para ejecutar `gs`

---

## 📝 Logs Esperados (Éxito)

```
[ORTHANC][SEND_IMAGE] ===== POST A ORTHANC (MODO IMAGEN) =====
[ORTHANC][SEND_IMAGE] Payload (estructura): {...}
[ORTHANC][SEND_IMAGE] Image: data:image/png;base64,... [BASE64 IMAGEN COMPLETA]
```

---

## ⚠️ Problemas Comunes

### Problema: "gs no se reconoce como comando"

**Solución:**
- Verificar que GhostScript está instalado
- Verificar que está en PATH
- Reiniciar CMD/PowerShell después de agregar a PATH

### Problema: "PHP no puede ejecutar gs"

**Solución:**
- Verificar permisos de PHP para ejecutar programas externos
- Verificar configuración de `exec()` en php.ini
- Verificar que `gs.exe` existe en la ruta especificada

### Problema: "GhostScript instalado pero no funciona"

**Solución:**
- Verificar versión (debe ser compatible con Imagick)
- Verificar que es la versión correcta (64-bit vs 32-bit)
- Reinstalar GhostScript

---

## 🔗 Enlaces Útiles

- **GhostScript Downloads:** https://www.ghostscript.com/download/gsdnld.html
- **GhostScript Documentation:** https://www.ghostscript.com/doc/
- **Imagick Documentation:** https://www.php.net/manual/en/book.imagick.php

---

## ✅ Resumen

**Problema:** Imagick necesita GhostScript para convertir PDF a PNG.

**Solución:** Instalar GhostScript y agregarlo al PATH del sistema.

**Pasos:**
1. Descargar e instalar GhostScript
2. Agregar al PATH del sistema
3. Reiniciar WAMP
4. Probar conversión PDF a PNG

---

_Última actualización: 31 de Octubre, 2025_  
_Solución para error GhostScript en conversión PDF a PNG_

