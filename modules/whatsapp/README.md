# Módulo de Mensajería WhatsApp con WAHA

## 📋 Descripción

Módulo completo y transportable para el envío de mensajes de WhatsApp usando **WAHA (WhatsApp HTTP API)**. Este módulo reemplaza completamente a Evolution API y proporciona una solución más simple y directa.

## ✨ Características Principales

- ✅ **Gestión de Sesiones:** Crear, listar, eliminar y autenticar sesiones de WhatsApp
- ✅ **Escaneo de QR:** Autenticación mediante QR code directamente desde la plataforma
- ✅ **Panel de Administración:** Interfaz integrada en `admin.php` (pestaña "Mensajería WhatsApp")
- ✅ **API REST Completa:** Endpoints para gestión de sesiones y envío de mensajes
- ✅ **Transportable:** Módulo independiente con instalador y documentación completa
- ✅ **Documentación:** Guías detalladas de instalación, uso y API

## 🚀 Instalación Rápida

```bash
cd modules/whatsapp
php install.php
```

Luego configurar desde `modules/email/admin.php` → pestaña "💬 Mensajería WhatsApp"

## 📚 Documentación

- **[docs/README.md](docs/README.md)** - Visión general del módulo
- **[docs/INSTALACION.md](docs/INSTALACION.md)** - Guía detallada de instalación
- **[docs/USO.md](docs/USO.md)** - Cómo usar el módulo
- **[docs/API_REFERENCE.md](docs/API_REFERENCE.md)** - Referencia completa de la API
- **[MIGRACION_COMPLETA.md](MIGRACION_COMPLETA.md)** - Resumen de la migración

## 🏗️ Estructura

```
modules/whatsapp/
├── WAHAAPI.php              # Cliente PHP para WAHA
├── WhatsAppConfig.php       # Gestor de configuración
├── install.php              # Instalador
├── config/
│   └── whatsapp_config.php  # Configuración
├── api/
│   ├── sessions.php         # API de sesiones
│   └── qrcode.php           # API de QR codes
├── logs/                    # Logs del módulo
└── docs/                    # Documentación completa
```

## 🔧 Requisitos

- PHP 7.4+
- WAHA corriendo en Docker o servidor accesible
- Módulo de email (para autenticación compartida)

## 📝 Uso Básico

### Desde PHP

```php
require_once 'modules/whatsapp/WAHAAPI.php';
require_once 'modules/whatsapp/WhatsAppConfig.php';

$config = WhatsAppConfig::load();
$wahaAPI = new WAHAAPI($config->getWahaConfig());

$result = $wahaAPI->sendTextMessage('default', '5491123456789', 'Hola!');
```

### Desde JavaScript

```javascript
const result = await evolutionAPI.sendTextMessage('5491123456789', 'Hola!');
```

## 🔐 Permisos

- `administracion_email` o `all` → Gestionar sesiones
- `pacientes` o `all` → Enviar mensajes

## 📞 Soporte

Ver documentación en `docs/` o revisar logs en `logs/whatsapp.log`

---

**Versión:** 1.0.0  
**Estado:** ✅ Completado y funcional  
**Transportable:** ✅ Sí, con instalador incluido

