# ⚙️ Guía de Configuración - Módulo de Email

## 📋 Archivos de Configuración

El módulo utiliza dos archivos de configuración principales:

1. **`config/email_config.php`** - Configuración SMTP y opciones generales
2. **`config/email_events.php`** - Configuración de eventos automáticos

## 🔧 Configuración SMTP

### Archivo: `config/email_config.php`

```php
return [
    'smtp' => [
        'host' => 'smtp.gmail.com',        // Servidor SMTP
        'port' => 587,                     // Puerto (587 TLS, 465 SSL)
        'secure' => 'tls',                 // 'tls' o 'ssl'
        'username' => 'tu-email@gmail.com', // Usuario SMTP
        'password' => 'tu-app-password',    // Contraseña o App Password
        'from_email' => 'noreply@tjsmedical.com', // Email remitente
        'from_name' => 'TJS Medical - Portal de Estudios' // Nombre remitente
    ],
    'options' => [
        'charset' => 'UTF-8',              // Codificación
        'debug' => false,                  // Modo debug (true = desarrollo)
        'log_errors' => true,             // Registrar errores
        'log_file' => __DIR__ . '/../logs/email.log' // Archivo de log
    ]
];
```

### Parámetros SMTP

| Parámetro | Descripción | Ejemplo |
|-----------|-------------|---------|
| `host` | Servidor SMTP | `smtp.gmail.com` |
| `port` | Puerto SMTP | `587` (TLS) o `465` (SSL) |
| `secure` | Tipo de seguridad | `tls` o `ssl` |
| `username` | Usuario/Email SMTP | `tu-email@gmail.com` |
| `password` | Contraseña o App Password | `xxxx xxxx xxxx xxxx` |
| `from_email` | Email remitente | `noreply@tjsmedical.com` |
| `from_name` | Nombre del remitente | `TJS Medical` |

### Opciones Generales

| Parámetro | Descripción | Valores |
|-----------|-------------|---------|
| `charset` | Codificación de caracteres | `UTF-8` (recomendado) |
| `debug` | Modo debug | `true` (desarrollo) / `false` (producción) |
| `log_errors` | Registrar errores en log | `true` / `false` |
| `log_file` | Ruta al archivo de log | Ruta absoluta o relativa |

## 📧 Configuraciones por Proveedor

### Gmail

```php
'smtp' => [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'secure' => 'tls',
    'username' => 'tu-email@gmail.com',
    'password' => 'xxxx xxxx xxxx xxxx', // App Password
]
```

**Importante**: Usar [App Passwords](https://myaccount.google.com/apppasswords) de Google.

### Outlook/Hotmail

```php
'smtp' => [
    'host' => 'smtp-mail.outlook.com',
    'port' => 587,
    'secure' => 'tls',
    'username' => 'tu-email@outlook.com',
    'password' => 'tu-contraseña',
]
```

### Yahoo

```php
'smtp' => [
    'host' => 'smtp.mail.yahoo.com',
    'port' => 587,
    'secure' => 'tls',
    'username' => 'tu-email@yahoo.com',
    'password' => 'tu-contraseña',
]
```

### SendGrid

```php
'smtp' => [
    'host' => 'smtp.sendgrid.net',
    'port' => 587,
    'secure' => 'tls',
    'username' => 'apikey',
    'password' => 'SG.xxxxxxxxxxxxx', // API Key
]
```

### Mailgun

```php
'smtp' => [
    'host' => 'smtp.mailgun.org',
    'port' => 587,
    'secure' => 'tls',
    'username' => 'postmaster@tu-dominio.com',
    'password' => 'tu-api-key',
]
```

## 🔐 Seguridad

### Variables de Entorno (Recomendado para Producción)

En lugar de hardcodear credenciales, usar variables de entorno:

```php
'smtp' => [
    'username' => $_ENV['SMTP_USERNAME'] ?? 'default@email.com',
    'password' => $_ENV['SMTP_PASSWORD'] ?? '',
]
```

Crear archivo `.env`:
```
SMTP_USERNAME=tu-email@gmail.com
SMTP_PASSWORD=tu-app-password
```

### Permisos de Archivos

```bash
# Archivos de configuración: solo lectura para el servidor web
chmod 644 config/email_config.php
chmod 644 config/email_events.php

# Directorio de logs: escritura para el servidor web
chmod 755 logs/
```

## 📊 Configuración de Eventos

Ver [EVENTOS_AUTOMATICOS.md](EVENTOS_AUTOMATICOS.md) para configuración detallada de eventos.

## 🧪 Validar Configuración

### Desde PHP

```php
require_once 'modules/email/EmailConfig.php';

$config = EmailConfig::load();
$validation = $config->validate();

if ($validation['valid']) {
    echo "Configuración válida";
} else {
    echo "Errores:\n";
    foreach ($validation['errors'] as $error) {
        echo "- $error\n";
    }
}
```

### Desde API

```bash
curl -X GET \
  -H "Authorization: Bearer TU_TOKEN" \
  http://tu-dominio.com/modules/email/api/test-connection.php
```

## 📝 Logs

Los logs se guardan en `logs/email.log` por defecto.

**Formato de log:**
```
[2025-01-15 10:30:45] [info] Email enviado exitosamente a usuario@email.com
[2025-01-15 10:31:12] [error] Error al conectar con SMTP: Connection timeout
```

**Niveles de log:**
- `info`: Operaciones exitosas
- `warning`: Advertencias
- `error`: Errores
- `debug`: Información detallada (solo en modo debug)

## 🔄 Cambios de Configuración

Después de modificar la configuración:

1. **Verificar sintaxis PHP**
   ```bash
   php -l config/email_config.php
   ```

2. **Probar conexión**
   ```bash
   php tests/test-email.php
   ```

3. **Revisar logs** si hay errores

## 💡 Mejores Prácticas

1. ✅ **No subir credenciales al repositorio**
2. ✅ **Usar App Passwords** en lugar de contraseñas normales
3. ✅ **Desactivar debug** en producción
4. ✅ **Revisar logs** regularmente
5. ✅ **Validar configuración** antes de usar en producción
6. ✅ **Usar variables de entorno** para credenciales sensibles

---

**Última actualización**: 2025-01-15

