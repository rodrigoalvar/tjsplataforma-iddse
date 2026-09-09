# 📧 Módulo de Email - Documentación Principal

## 📋 Descripción

Módulo independiente y transportable para envíos por email en sistemas TJSMEDICAL. Permite enviar emails manualmente mediante API REST o automáticamente según eventos del sistema.

## ✨ Características

- ✅ **Independiente**: Módulo autocontenido, fácil de transportar
- ✅ **Configurable**: Configuración mediante archivos externos
- ✅ **Eventos Automáticos**: Envíos automáticos según eventos del sistema
- ✅ **API REST**: Endpoints para envíos manuales
- ✅ **Plantillas HTML**: Sistema de plantillas con variables
- ✅ **Documentación Completa**: Guías detalladas de uso
- ✅ **Instalador Automático**: Script de instalación incluido

## 🚀 Inicio Rápido

### 1. Instalación

```bash
# Copiar el módulo a tu proyecto
cp -r modules/email /ruta/a/tu/proyecto/modules/

# Instalar dependencias
cd modules/email
composer install
```

### 2. Configuración

Acceder al instalador web:
```
http://tu-dominio.com/modules/email/install.php
```

O configurar manualmente editando `config/email_config.php`

### 3. Probar Conexión

```bash
php tests/test-email.php
```

O mediante API:
```
GET /modules/email/api/test-connection.php
```

## 📁 Estructura del Módulo

```
modules/email/
├── EmailService.php          # Clase principal de envío
├── EmailConfig.php           # Gestor de configuración
├── EmailTemplate.php         # Gestor de plantillas
├── EmailEventManager.php     # Gestor de eventos
├── install.php               # Instalador automático
├── composer.json             # Dependencias
│
├── api/                     # Endpoints REST
│   ├── send.php
│   ├── send-template.php
│   ├── test-connection.php
│   └── list-templates.php
│
├── config/                   # Configuración
│   ├── email_config.php
│   └── email_events.php
│
├── templates/                # Plantillas HTML
│   ├── verification.html
│   ├── informe-completado.html
│   ├── asignacion-estudio.html
│   └── notificacion-generica.html
│
├── docs/                     # Documentación
│   ├── README.md (este archivo)
│   ├── INSTALACION.md
│   ├── CONFIGURACION.md
│   ├── EVENTOS_AUTOMATICOS.md
│   └── API_REFERENCE.md
│
└── logs/                     # Logs del sistema
    └── email.log
```

## 🔧 Uso Básico

### Envío Manual desde PHP

```php
require_once 'modules/email/EmailService.php';
require_once 'modules/email/EmailConfig.php';

$config = EmailConfig::load();
$emailService = new EmailService($config);

$result = $emailService->send([
    'to' => 'usuario@email.com',
    'subject' => 'Asunto del email',
    'body' => '<h1>Contenido HTML</h1>',
    'body_type' => 'html'
]);

if ($result['success']) {
    echo "Email enviado: " . $result['message_id'];
}
```

### Envío con Plantilla

```php
$result = $emailService->sendTemplate(
    'verification',
    'usuario@email.com',
    'Verifica tu cuenta - {{app_name}}',
    [
        'nombre_usuario' => 'Juan Pérez',
        'token_verificacion' => 'abc123'
    ]
);
```

### Eventos Automáticos

```php
require_once 'modules/email/EmailEventManager.php';

$emailManager = new EmailEventManager();
$emailManager->trigger('usuario_registrado', [
    'user_email' => $user->email,
    'nombre_usuario' => $user->nombre,
    'token_verificacion' => $token
]);
```

### Desde JavaScript (API REST)

```javascript
fetch('/modules/email/api/send.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
    },
    body: JSON.stringify({
        to: 'usuario@email.com',
        subject: 'Asunto',
        body: '<p>Mensaje HTML</p>'
    })
})
.then(res => res.json())
.then(data => {
    if (data.success) {
        console.log('Email enviado');
    }
});
```

## 📚 Documentación Completa

- **[INSTALACION.md](INSTALACION.md)** - Guía detallada de instalación
- **[CONFIGURACION.md](CONFIGURACION.md)** - Configuración SMTP y opciones
- **[EVENTOS_AUTOMATICOS.md](EVENTOS_AUTOMATICOS.md)** - Sistema de eventos
- **[API_REFERENCE.md](API_REFERENCE.md)** - Referencia completa de API

## 🔐 Seguridad

- ✅ Validación de emails
- ✅ Autenticación requerida en endpoints
- ✅ Credenciales en archivos de configuración (no en código)
- ✅ Logging de operaciones
- ✅ Sanitización de inputs

## 🧪 Testing

Ejecutar tests:
```bash
php tests/test-email.php
```

## 📦 Requisitos

- PHP 7.4+
- Extensiones: `openssl`, `curl`, `mbstring`
- PHPMailer (instalado via Composer)
- Servidor SMTP configurado

## 🆘 Soporte

Para problemas o preguntas:
1. Revisar la documentación en `docs/`
2. Verificar logs en `logs/email.log`
3. Ejecutar `tests/test-email.php` para diagnóstico

## 📄 Licencia

MIT License - Ver archivo LICENSE

## 🔄 Versión

**Versión actual**: 1.0.0

---

**Última actualización**: 2025-01-15

