# Módulo de Mensajería WhatsApp

## 📋 Descripción

Módulo completo para el envío de mensajes de WhatsApp usando **WAHA (WhatsApp HTTP API)**. Este módulo proporciona una interfaz de administración integrada en el panel de administración del sistema, permitiendo gestionar sesiones de WhatsApp, escanear códigos QR para autenticación, y enviar mensajes desde cualquier parte del sistema.

## ✨ Características

- ✅ **Gestión de Sesiones:** Crear, listar y eliminar sesiones de WhatsApp
- ✅ **Autenticación con QR:** Escanear códigos QR directamente desde la plataforma
- ✅ **Panel de Administración:** Interfaz integrada en `admin.php`
- ✅ **API REST:** Endpoints para gestión de sesiones y envío de mensajes
- ✅ **Configuración Centralizada:** Archivo de configuración único
- ✅ **Transportable:** Módulo independiente que puede instalarse en otras versiones del sistema
- ✅ **Documentación Completa:** Guías de instalación, uso y API

## 🏗️ Arquitectura

```
modules/whatsapp/
├── WAHAAPI.php              # Cliente PHP para WAHA
├── WhatsAppConfig.php       # Gestor de configuración
├── install.php              # Instalador del módulo
├── config/
│   └── whatsapp_config.php  # Configuración
├── api/
│   ├── sessions.php         # API de gestión de sesiones
│   └── qrcode.php           # API de códigos QR
├── logs/                    # Logs del módulo
└── docs/                    # Documentación
    ├── README.md            # Este archivo
    ├── INSTALACION.md       # Guía de instalación
    ├── USO.md               # Guía de uso
    └── API_REFERENCE.md      # Referencia de API
```

## 📦 Requisitos

- PHP 7.4 o superior
- WAHA corriendo en Docker (o servidor accesible)
- Módulo de email (para autenticación compartida)
- Permisos de escritura en `config/` y `logs/`

## 🚀 Instalación Rápida

1. **Ejecutar instalador:**
   ```bash
   cd modules/whatsapp
   php install.php
   ```

2. **Configurar WAHA:**
   - Acceder a `modules/email/admin.php`
   - Ir a la pestaña "💬 Mensajería WhatsApp"
   - Configurar URL base de WAHA y API key (si aplica)

3. **Crear sesión:**
   - Hacer clic en "Crear Nueva Sesión"
   - Escanear el código QR con WhatsApp
   - ¡Listo para enviar mensajes!

## 📚 Documentación

- **[INSTALACION.md](INSTALACION.md)** - Guía detallada de instalación
- **[USO.md](USO.md)** - Guía de uso del módulo
- **[API_REFERENCE.md](API_REFERENCE.md)** - Referencia de la API

## 🔧 Configuración

La configuración se realiza desde el panel de administración (`admin.php` → pestaña "Mensajería WhatsApp") o editando directamente `config/whatsapp_config.php`.

### Parámetros de Configuración

- **base_url:** URL donde está corriendo WAHA (ej: `http://localhost:3000`)
- **api_key:** API Key si WAHA requiere autenticación (opcional)
- **default_session:** Nombre de la sesión por defecto para enviar mensajes
- **timeout:** Timeout para peticiones HTTP (segundos)

## 💻 Uso Básico

### Desde PHP

```php
require_once 'modules/whatsapp/WAHAAPI.php';
require_once 'modules/whatsapp/WhatsAppConfig.php';

$config = WhatsAppConfig::load();
$wahaAPI = new WAHAAPI($config->getWahaConfig());

// Enviar mensaje
$result = $wahaAPI->sendTextMessage('default', '5491123456789', 'Hola desde WAHA!');
```

### Desde JavaScript

```javascript
// El cliente ya está disponible globalmente como evolutionAPI o wahaAPI
const result = await evolutionAPI.sendTextMessage('5491123456789', 'Hola desde WAHA!');
```

## 🔐 Permisos

El módulo requiere los mismos permisos que el módulo de email:
- `administracion_email` o `all` para gestionar sesiones
- `pacientes` o `all` para enviar mensajes

## 🐛 Solución de Problemas

### Error: "No se pudo conectar con WAHA"
- Verificar que WAHA esté corriendo
- Verificar la URL base en la configuración
- Verificar conectividad de red

### Error: "Sesión no autenticada"
- Escanear el código QR desde el panel de administración
- Verificar que la sesión exista en WAHA

### Error: "Número de teléfono inválido"
- Verificar formato del número (debe incluir código de país)
- Ejemplo correcto: `5491123456789` (Argentina)

## 📝 Changelog

### v1.0.0 (2024)
- Migración de Evolution API a WAHA
- Panel de administración integrado
- Gestión de sesiones con QR
- Documentación completa

## 📄 Licencia

Este módulo es parte del sistema TJSMEDICAL - Portal de Estudios Médicos.

## 🤝 Soporte

Para problemas o preguntas, consultar la documentación en `docs/` o revisar los logs en `logs/whatsapp.log`.

