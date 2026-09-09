# Pasos para Activar la Configuración SMTP por Usuario

## ✅ Lo que ya está implementado

1. **Código PHP completo**:
   - ✅ Métodos `loadForUser()` y `saveForUser()` en `EmailConfig.php`
   - ✅ Lógica en `admin.php` para guardar/cargar configuración por usuario
   - ✅ Actualización de APIs (`send.php`, `send-template.php`, `test-connection.php`) para usar configuración del usuario
   - ✅ Script de instalación `install-user-smtp.php`

2. **Interfaz de usuario**:
   - ✅ Checkbox "Guardar esta configuración solo para mi usuario" en el formulario
   - ✅ Carga automática de configuración del usuario si existe

## 🔧 Pasos para activar la funcionalidad

### Paso 1: Crear la tabla en la base de datos

Ejecuta el script de instalación:

```bash
cd /var/www/tjsidimagenes/modules/email
php install-user-smtp.php
```

**O manualmente con MySQL:**

```bash
mysql -u root -p tu_base_de_datos < modules/email/database/user_smtp_config.sql
```

### Paso 2: Verificar que la tabla se creó correctamente

```sql
SHOW TABLES LIKE 'user_smtp_config';
DESCRIBE user_smtp_config;
```

Deberías ver la tabla con las siguientes columnas:
- `id` (AUTO_INCREMENT, PRIMARY KEY)
- `usuario_id` (UNIQUE, FOREIGN KEY a `usuarios.id`)
- `smtp_host`
- `smtp_port`
- `smtp_secure` (enum: 'tls', 'ssl')
- `smtp_username`
- `smtp_password`
- `from_email`
- `from_name`
- `activo` (tinyint, default 1)
- `fecha_creacion`
- `fecha_actualizacion`

### Paso 3: Probar la funcionalidad

1. **Accede a la administración de email:**
   ```
   https://tu-dominio.com/modules/email/admin.php
   ```

2. **Configura SMTP para tu usuario:**
   - Completa el formulario de configuración SMTP
   - ✅ **Marca el checkbox "Guardar esta configuración solo para mi usuario"**
   - Haz clic en "Guardar Configuración"

3. **Verifica que se guardó:**
   ```sql
   SELECT * FROM user_smtp_config WHERE usuario_id = TU_ID_DE_USUARIO;
   ```

4. **Prueba el envío:**
   - Usa la pestaña "Enviar Email de Prueba"
   - El sistema debería usar tu configuración personal

## 📋 Verificación de funcionamiento

### Verificar que la configuración se carga correctamente

1. **Revisa los logs del servidor:**
   ```bash
   tail -f /var/log/apache2/error.log
   # O donde estén tus logs de PHP
   ```

2. **Busca estos mensajes:**
   - `🔍 [admin.php] Guardando configuración para el usuario ID: X`
   - `🔍 [admin.php] Cargando configuración SMTP específica del usuario ID: X`
   - `🔍 [api/send.php] Usando configuración SMTP del usuario ID: X`

### Verificar en la base de datos

```sql
-- Ver todas las configuraciones de usuario
SELECT 
    u.id as usuario_id,
    u.nombre,
    u.apellido,
    usc.smtp_host,
    usc.from_email,
    usc.activo,
    usc.fecha_actualizacion
FROM user_smtp_config usc
INNER JOIN usuarios u ON usc.usuario_id = u.id
ORDER BY usc.fecha_actualizacion DESC;
```

## 🔍 Solución de problemas

### Error: "Table 'user_smtp_config' doesn't exist"

**Solución:** Ejecuta el script de instalación:
```bash
php modules/email/install-user-smtp.php
```

### La configuración no se guarda

1. Verifica permisos de la base de datos
2. Revisa los logs de PHP/Apache
3. Verifica que el checkbox esté marcado antes de guardar

### Se sigue usando la configuración global

1. Verifica que existe una configuración para tu usuario:
   ```sql
   SELECT * FROM user_smtp_config WHERE usuario_id = TU_ID;
   ```

2. Verifica que `activo = 1` en la tabla

3. Si eres usuario 'root', el sistema siempre usará la configuración global (por diseño)

### Error de conexión a la base de datos

Verifica que el archivo `config/database.php` existe y tiene la configuración correcta.

## 📝 Notas importantes

1. **Usuarios 'root'**: Siempre usan la configuración global, no pueden tener configuración personal.

2. **Prioridad de configuración:**
   - Si el usuario tiene configuración personal → se usa esa
   - Si no tiene configuración personal → se usa la global

3. **Cada usuario puede tener su propia configuración SMTP independiente.**

4. **La configuración se encripta en la base de datos** (el password se almacena como texto plano por ahora - considerar encriptación en el futuro).

## ✅ Checklist final

- [ ] Tabla `user_smtp_config` creada en la base de datos
- [ ] Script de instalación ejecutado sin errores
- [ ] Puedo acceder a `modules/email/admin.php`
- [ ] Veo el checkbox "Guardar esta configuración solo para mi usuario"
- [ ] Puedo guardar mi configuración personal
- [ ] La configuración aparece en la base de datos
- [ ] Los emails se envían usando mi configuración personal
- [ ] Los logs muestran que se está usando la configuración del usuario

---

**¿Necesitas ayuda?** Revisa los logs del servidor y los mensajes de error en la consola del navegador.

