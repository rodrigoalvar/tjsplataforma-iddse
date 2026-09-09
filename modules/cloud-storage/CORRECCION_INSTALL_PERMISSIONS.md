# ✅ Corrección - install-permissions.php

## 🐛 Problema

El script `install-permissions.php` devolvía error 500 debido a una ruta incorrecta del include de `database.php`.

## 🔧 Solución

**Ruta incorrecta:**
```php
require_once __DIR__ . '/../../../config/database.php';  // ❌ 3 niveles
```

**Ruta correcta:**
```php
require_once __DIR__ . '/../../config/database.php';  // ✅ 2 niveles
```

## 📁 Estructura de Directorios

Desde `modules/cloud-storage/install-permissions.php`:
- `../` → `modules/`
- `../../` → raíz del proyecto (`/var/www/tjsiddse/`)
- `../../config/database.php` → `/var/www/tjsiddse/config/database.php` ✅

## ✅ Estado

**Script corregido y funcionando correctamente.**

Los permisos se han creado exitosamente:
- ✅ `cloud_storage` - Permiso funcional
- ✅ `gui_cloud_storage` - Permiso GUI

## 🚀 Uso

Accede al script desde el navegador:
```
https://plataforma.iddse.com.ar/modules/cloud-storage/install-permissions.php
```

El script ahora debería funcionar sin errores.
