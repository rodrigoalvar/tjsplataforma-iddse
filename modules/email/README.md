# 📧 Módulo de Email - TJSMEDICAL

Módulo independiente y transportable para envíos por email en sistemas TJSMEDICAL.

## 🚀 Inicio Rápido

1. **Instalar dependencias:**
   ```bash
   composer install
   ```

2. **Configurar SMTP:**
   - Acceder a `install.php` en el navegador
   - O editar manualmente `config/email_config.php`

3. **Probar:**
   ```bash
   php tests/test-email.php
   ```

## 📚 Documentación

Toda la documentación está en `docs/`:

- **[README.md](docs/README.md)** - Documentación principal
- **[INSTALACION.md](docs/INSTALACION.md)** - Guía de instalación
- **[CONFIGURACION.md](docs/CONFIGURACION.md)** - Configuración SMTP
- **[EVENTOS_AUTOMATICOS.md](docs/EVENTOS_AUTOMATICOS.md)** - Sistema de eventos
- **[API_REFERENCE.md](docs/API_REFERENCE.md)** - Referencia de API

## 📁 Estructura

```
modules/email/
├── EmailService.php          # Clase principal
├── EmailConfig.php           # Configuración
├── EmailTemplate.php         # Plantillas
├── EmailEventManager.php     # Eventos
├── install.php               # Instalador
├── composer.json             # Dependencias
├── api/                      # Endpoints REST
├── config/                    # Configuración
├── templates/                 # Plantillas HTML
├── docs/                      # Documentación
└── tests/                     # Tests
```

## 🔧 Uso Básico

```php
require_once 'modules/email/EmailService.php';
require_once 'modules/email/EmailConfig.php';

$config = EmailConfig::load();
$emailService = new EmailService($config);

$result = $emailService->send([
    'to' => 'usuario@email.com',
    'subject' => 'Test',
    'body' => '<h1>Email de prueba</h1>'
]);
```

## 📦 Transportar a Otro Proyecto

1. Copiar la carpeta `modules/email/` completa
2. Ejecutar `composer install` en la nueva ubicación
3. Configurar `config/email_config.php`
4. ¡Listo!

## 📄 Licencia

MIT License

## 🔄 Versión

**1.0.0** - 2025-01-15

