# 🧪 Cómo Probar el Módulo de Email

## 📋 Opciones de Prueba

### 1. 🖥️ Interfaz Web de Administración (Más Fácil)

**Acceder a:**
```
http://tu-dominio.com/modules/email/admin.php
```

**Pasos:**
1. Abre la pestaña **"🧪 Pruebas"**
2. Ingresa tu email
3. Haz clic en **"📧 Enviar Email de Prueba"**
4. Revisa tu bandeja de entrada

---

### 2. 🔌 Probar Conexión SMTP

**Opción A: Desde la Interfaz Web**
1. Abre `admin.php`
2. Ve a la pestaña **"⚙️ Configuración SMTP"**
3. Haz clic en **"🔌 Probar Conexión"**

**Opción B: Desde la API**
```bash
curl -X GET \
  -H "Authorization: Bearer TU_TOKEN" \
  http://tu-dominio.com/modules/email/api/test-connection.php
```

**Opción C: Desde PHP**
```php
<?php
require_once 'modules/email/EmailConfig.php';
require_once 'modules/email/EmailService.php';

$config = EmailConfig::load();
$emailService = new EmailService($config);

$result = $emailService->testConnection();
var_dump($result);
?>
```

---

### 3. 📧 Enviar Email de Prueba desde Línea de Comandos

**Ejecutar:**
```bash
cd /var/www/tjsidimagenes/modules/email
php tests/test-email.php
```

Este script:
- ✅ Verifica la configuración
- ✅ Prueba la conexión SMTP
- ✅ Lista las plantillas disponibles
- ✅ Te permite enviar un email de prueba

---

### 4. 🌐 Probar desde API REST

**Enviar email simple:**
```bash
curl -X POST http://tu-dominio.com/modules/email/api/send.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_TOKEN" \
  -d '{
    "to": "tu-email@ejemplo.com",
    "subject": "Test del Módulo",
    "body": "<h1>Email de prueba</h1><p>Funciona correctamente!</p>"
  }'
```

**Enviar con plantilla:**
```bash
curl -X POST http://tu-dominio.com/modules/email/api/send-template.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_TOKEN" \
  -d '{
    "template": "notificacion-generica",
    "to": "tu-email@ejemplo.com",
    "subject": "Test con Plantilla",
    "variables": {
      "nombre_usuario": "Usuario de Prueba",
      "mensaje": "Este es un mensaje de prueba"
    }
  }'
```

---

### 5. 💻 Probar desde PHP Directo

**Crear archivo `test-simple.php`:**
```php
<?php
require_once 'modules/email/EmailConfig.php';
require_once 'modules/email/EmailService.php';

// Cargar configuración
$config = EmailConfig::load();

// Validar configuración
$validation = $config->validate();
if (!$validation['valid']) {
    echo "❌ Errores de configuración:\n";
    foreach ($validation['errors'] as $error) {
        echo "  - $error\n";
    }
    exit(1);
}

// Crear servicio
$emailService = new EmailService($config);

// Probar conexión
echo "🔌 Probando conexión SMTP...\n";
$connectionTest = $emailService->testConnection();
if ($connectionTest['success']) {
    echo "✅ Conexión exitosa!\n";
} else {
    echo "❌ Error de conexión: " . $connectionTest['message'] . "\n";
    exit(1);
}

// Enviar email de prueba
echo "\n📧 Enviando email de prueba...\n";
$result = $emailService->send([
    'to' => 'tu-email@ejemplo.com',  // ⚠️ CAMBIAR por tu email
    'subject' => 'Test del Módulo de Email - ' . date('Y-m-d H:i:s'),
    'body' => '<h1>Email de Prueba</h1><p>Este es un email de prueba del módulo de email.</p><p>Fecha: ' . date('Y-m-d H:i:s') . '</p>',
    'body_type' => 'html'
]);

if ($result['success']) {
    echo "✅ Email enviado exitosamente!\n";
    echo "   Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";
} else {
    echo "❌ Error al enviar: " . $result['message'] . "\n";
}
?>
```

**Ejecutar:**
```bash
php test-simple.php
```

---

### 6. 🎯 Probar Eventos Automáticos

**Crear archivo `test-events.php`:**
```php
<?php
require_once 'modules/email/EmailEventManager.php';

$emailManager = new EmailEventManager();

// Probar evento de usuario registrado
echo "🎯 Probando evento: usuario_registrado\n";
$result = $emailManager->trigger('usuario_registrado', [
    'user_email' => 'tu-email@ejemplo.com',  // ⚠️ CAMBIAR
    'nombre_usuario' => 'Usuario de Prueba',
    'token_verificacion' => 'test-token-123'
]);

if ($result['success']) {
    echo "✅ Evento disparado exitosamente!\n";
} else {
    echo "❌ Error: " . $result['message'] . "\n";
    if (isset($result['skipped'])) {
        echo "   (Evento deshabilitado)\n";
    }
}
?>
```

**Ejecutar:**
```bash
php test-events.php
```

---

## ✅ Checklist de Pruebas

- [ ] **Configuración válida**: No hay errores en la validación
- [ ] **Conexión SMTP**: Se puede conectar al servidor SMTP
- [ ] **Envío básico**: Se puede enviar un email simple
- [ ] **Plantillas**: Se pueden cargar y usar plantillas
- [ ] **Eventos**: Los eventos se disparan correctamente
- [ ] **Logs**: Los logs se generan correctamente

---

## 🔍 Verificar Logs

**Ver logs en tiempo real:**
```bash
tail -f /var/www/tjsidimagenes/modules/email/logs/email.log
```

**Ver últimas 20 líneas:**
```bash
tail -20 /var/www/tjsidimagenes/modules/email/logs/email.log
```

---

## 🆘 Solución de Problemas

### Error: "No se puede conectar al servidor SMTP"
- Verifica credenciales (usuario y contraseña)
- Verifica puerto y seguridad (TLS/SSL)
- Verifica que el firewall no bloquee el puerto
- Para Gmail: usa App Password, no tu contraseña normal

### Error: "Email no enviado"
- Revisa los logs en `logs/email.log`
- Verifica que el email destinatario sea válido
- Verifica que el servidor SMTP esté funcionando

### Error: "Plantilla no encontrada"
- Verifica que la plantilla exista en `templates/`
- Verifica el nombre (sin extensión .html)

---

## 📚 Más Información

- **Documentación completa**: `docs/README.md`
- **Referencia de API**: `docs/API_REFERENCE.md`
- **Configuración**: `docs/CONFIGURACION.md`

