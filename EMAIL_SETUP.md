# Configuración de Email - TJSMEDICAL

## Estado Actual

Actualmente, el sistema de autenticación está configurado para **desarrollo** y **NO** envía emails reales. Los usuarios pueden iniciar sesión sin verificar su email.

## Verificación de Email en Desarrollo

Cuando un usuario se registra, el token de verificación se guarda en los **logs del servidor PHP**.

### Cómo ver los tokens de verificación:

1. **En Windows con WAMP:**
   - Los logs están en: `C:\wamp64\logs\php_error.log`
   - También puedes verlos en la consola donde ejecutas `php -S localhost:8080`

2. **Buscar en los logs:**
   ```
   === EMAIL DE VERIFICACIÓN ===
   Email: usuario@ejemplo.com
   Token: abc123def456...
   URL de verificación: http://localhost:8080/verify-email.php?token=abc123def456...
   ==============================
   ```

3. **Para verificar manualmente:**
   - Copia la URL de verificación del log
   - Pégala en tu navegador
   - El usuario quedará verificado

## Configuración para Producción

### Opción 1: PHPMailer (Recomendado)

1. **Instalar PHPMailer:**
   ```bash
   composer require phpmailer/phpmailer
   ```

2. **Configurar en User.php:**
   - Descomenta las líneas de PHPMailer en el método `sendVerificationEmail()`
   - Configura tu servidor SMTP

3. **Ejemplo de configuración SMTP:**
   ```php
   $mail->isSMTP();
   $mail->Host = 'smtp.gmail.com';
   $mail->SMTPAuth = true;
   $mail->Username = 'tu-email@gmail.com';
   $mail->Password = 'tu-app-password';
   $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
   $mail->Port = 587;
   ```

### Opción 2: Servicio de Email (SendGrid, Mailgun, etc.)

1. **Registrarse en un servicio de email**
2. **Obtener API Key**
3. **Implementar en el método sendVerificationEmail()**

### Habilitar Verificación Obligatoria

Una vez configurado el envío de emails:

1. **En `classes/User.php`, línea ~97:**
   ```php
   // Descomenta estas líneas:
   if (!$row['email_verificado']) {
       return array('success' => false, 'message' => 'Debes verificar tu email antes de iniciar sesión');
   }
   ```

## Notas Importantes

- ⚠️ **Nunca** subas credenciales de email al repositorio
- 📧 Usa variables de entorno para configuración sensible
- 🔒 Para Gmail, usa "App Passwords" en lugar de tu contraseña normal
- 📝 Personaliza el template del email según tu marca

## Testing

Para probar el envío de emails:

1. Configura un servidor SMTP de prueba (MailHog, Mailtrap)
2. Registra un usuario de prueba
3. Verifica que el email llegue correctamente
4. Prueba el enlace de verificación