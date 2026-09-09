# 📦 Guía de Instalación - Módulo de Email

## 📋 Requisitos Previos

- PHP 7.4 o superior
- Composer instalado
- Extensiones PHP: `openssl`, `curl`, `mbstring`
- Acceso a un servidor SMTP (Gmail, Outlook, SendGrid, etc.)

## 🚀 Instalación Paso a Paso

### Opción 1: Instalación Automática (Recomendada)

1. **Copiar el módulo a tu proyecto**

```bash
# Si estás en el proyecto original
cp -r modules/email /ruta/a/otro/proyecto/modules/

# O descargar/copiar la carpeta modules/email completa
```

2. **Acceder al instalador web**

Abrir en el navegador:
```
http://tu-dominio.com/modules/email/install.php
```

3. **Seguir el asistente**

El instalador verificará:
- ✅ Versión de PHP
- ✅ Extensiones requeridas
- ✅ Directorios necesarios
- ✅ PHPMailer

4. **Configurar SMTP**

Completar el formulario con:
- Servidor SMTP
- Puerto
- Usuario y contraseña
- Email remitente

5. **Instalar dependencias**

```bash
cd modules/email
composer install
```

### Opción 2: Instalación Manual

1. **Copiar el módulo**

```bash
cp -r modules/email /ruta/a/tu/proyecto/modules/
```

2. **Instalar dependencias**

```bash
cd modules/email
composer install
```

3. **Configurar manualmente**

Editar `config/email_config.php`:

```php
return [
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'secure' => 'tls',
        'username' => 'tu-email@gmail.com',
        'password' => 'tu-app-password',
        'from_email' => 'noreply@tjsmedical.com',
        'from_name' => 'TJS Medical'
    ],
    // ...
];
```

4. **Verificar permisos**

```bash
chmod 755 modules/email/logs
chmod 644 modules/email/config/*.php
```

## 🔧 Configuración de SMTP

### Gmail

1. **Habilitar App Passwords**
   - Ir a: https://myaccount.google.com/apppasswords
   - Generar una contraseña de aplicación
   - Usar esa contraseña (no tu contraseña normal)

2. **Configuración**
   ```php
   'host' => 'smtp.gmail.com',
   'port' => 587,
   'secure' => 'tls',
   ```

### Outlook/Hotmail

```php
'host' => 'smtp-mail.outlook.com',
'port' => 587,
'secure' => 'tls',
```

### SendGrid

```php
'host' => 'smtp.sendgrid.net',
'port' => 587,
'secure' => 'tls',
'username' => 'apikey',
'password' => 'tu-api-key',
```

### Mailgun

```php
'host' => 'smtp.mailgun.org',
'port' => 587,
'secure' => 'tls',
'username' => 'tu-dominio',
'password' => 'tu-api-key',
```

## ✅ Verificación de Instalación

### 1. Probar desde línea de comandos

```bash
cd modules/email
php tests/test-email.php
```

### 2. Probar conexión SMTP (API)

```bash
curl -X GET \
  -H "Authorization: Bearer TU_TOKEN" \
  http://tu-dominio.com/modules/email/api/test-connection.php
```

### 3. Enviar email de prueba

```php
require_once 'modules/email/EmailService.php';
require_once 'modules/email/EmailConfig.php';

$config = EmailConfig::load();
$emailService = new EmailService($config);

$result = $emailService->send([
    'to' => 'tu-email@ejemplo.com',
    'subject' => 'Test',
    'body' => '<h1>Email de prueba</h1>'
]);

var_dump($result);
```

## 🔍 Solución de Problemas

### Error: "PHPMailer no está instalado"

**Solución:**
```bash
cd modules/email
composer install
```

### Error: "No se puede conectar al servidor SMTP"

**Verificar:**
1. Credenciales correctas
2. Puerto y seguridad (TLS/SSL)
3. Firewall no bloquea el puerto
4. Para Gmail: usar App Password

### Error: "Directorio logs no es escribible"

**Solución:**
```bash
chmod 755 modules/email/logs
chown www-data:www-data modules/email/logs
```

### Error: "Extensiones PHP faltantes"

**Solución (Ubuntu/Debian):**
```bash
sudo apt-get install php-openssl php-curl php-mbstring
```

**Solución (CentOS/RHEL):**
```bash
sudo yum install php-openssl php-curl php-mbstring
```

## 📝 Notas Importantes

1. **No subir credenciales al repositorio**: Usar variables de entorno en producción
2. **Permisos**: Asegurar que los directorios tienen permisos correctos
3. **Logs**: Revisar `logs/email.log` para diagnóstico
4. **Producción**: Desactivar modo debug en producción

## 🔄 Actualización

Para actualizar el módulo:

```bash
cd modules/email
composer update
```

## 📚 Próximos Pasos

Después de la instalación:

1. ✅ Revisar [CONFIGURACION.md](CONFIGURACION.md)
2. ✅ Configurar eventos en `config/email_events.php`
3. ✅ Personalizar plantillas en `templates/`
4. ✅ Revisar [API_REFERENCE.md](API_REFERENCE.md) para uso de API

---

**Última actualización**: 2025-01-15

