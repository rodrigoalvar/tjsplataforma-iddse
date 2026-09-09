# Implementación de Configuración SMTP por Usuario

## Resumen

Se ha implementado la funcionalidad para que cada usuario pueda configurar su propio servidor SMTP.

## Cambios Realizados

### 1. Base de Datos

Se creó la tabla `user_smtp_config` para almacenar la configuración SMTP por usuario:

**Archivo:** `modules/email/database/user_smtp_config.sql`

```sql
CREATE TABLE IF NOT EXISTS `user_smtp_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `smtp_host` varchar(255) NOT NULL,
  `smtp_port` int NOT NULL DEFAULT 587,
  `smtp_secure` enum('tls','ssl') NOT NULL DEFAULT 'tls',
  `smtp_username` varchar(255) NOT NULL,
  `smtp_password` varchar(255) NOT NULL,
  `from_email` varchar(255) NOT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario_id` (`usuario_id`),
  KEY `idx_usuario_activo` (`usuario_id`, `activo`),
  CONSTRAINT `fk_user_smtp_config_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Para crear la tabla, ejecuta:**
```bash
mysql -u root -p tu_base_de_datos < modules/email/database/user_smtp_config.sql
```

### 2. EmailConfig.php

Se agregaron dos nuevos métodos:

- **`EmailConfig::loadForUser($userId)`**: Carga la configuración SMTP de un usuario específico desde la base de datos
- **`EmailConfig::saveForUser($userId, $smtpConfig)`**: Guarda la configuración SMTP de un usuario en la base de datos

### 3. admin.php

**Funciones agregadas:**
- **`getCurrentUserId()`**: Obtiene el ID del usuario actual desde la sesión o token

**Modificaciones:**
- El formulario de configuración ahora incluye un checkbox "Guardar como mi configuración personal"
- Si el usuario marca este checkbox, la configuración se guarda en la base de datos para ese usuario
- Si no lo marca, se guarda como configuración global (comportamiento anterior)
- Al cargar la página, se intenta cargar primero la configuración del usuario, y si no existe, se usa la global
- Las funciones de prueba (test-connection y send-test) también usan la configuración del usuario si existe

## Cómo Usar

1. **Crear la tabla en la base de datos:**
   ```bash
   mysql -u root -p nombre_base_datos < modules/email/database/user_smtp_config.sql
   ```

2. **Para que un usuario configure su propio SMTP:**
   - El usuario accede a `modules/email/admin.php`
   - Completa el formulario de configuración SMTP
   - Marca el checkbox "Guardar como mi configuración personal"
   - Guarda la configuración

3. **Comportamiento:**
   - Si el usuario tiene configuración personal, se usa automáticamente
   - Si no tiene configuración personal, se usa la configuración global
   - Cada usuario puede tener su propia configuración SMTP independiente

## Notas Importantes

- La configuración del usuario tiene prioridad sobre la configuración global
- Si un usuario elimina su configuración personal, se usará la global
- Los administradores pueden seguir configurando la configuración global
- La configuración del usuario se almacena de forma segura en la base de datos

