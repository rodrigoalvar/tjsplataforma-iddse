# Guía de Instalación - Módulo de WhatsApp

## 📋 Requisitos Previos

### Software Necesario

1. **WAHA (WhatsApp HTTP API)**
   - Debe estar corriendo en Docker o servidor accesible
   - URL accesible desde el servidor PHP
   - Documentación: [https://waha.devlike.pro/](https://waha.devlike.pro/)

2. **PHP 7.4+**
   - Extensiones: `curl`, `json`

3. **Módulo de Email**
   - El módulo de WhatsApp usa la autenticación del módulo de email
   - Debe estar instalado y funcionando

### Permisos

- Permisos de escritura en `modules/whatsapp/config/`
- Permisos de escritura en `modules/whatsapp/logs/`

## 🚀 Instalación Paso a Paso

### Paso 1: Verificar WAHA

Asegúrate de que WAHA esté corriendo y accesible:

```bash
# Verificar que WAHA esté corriendo
curl http://localhost:3000/api/sessions
```

Si WAHA está en otro puerto o servidor, ajusta la URL según corresponda.

### Paso 2: Ejecutar Instalador

```bash
cd /var/www/tjsidimagenes/modules/whatsapp
php install.php
```

El instalador:
- ✅ Crea la estructura de directorios necesaria
- ✅ Verifica archivos principales
- ✅ Verifica permisos de escritura
- ✅ Crea archivo de configuración por defecto
- ✅ Verifica conexión con WAHA (opcional)

### Paso 3: Configurar WAHA

1. Acceder a `modules/email/admin.php`
2. Ir a la pestaña **"💬 Mensajería WhatsApp"**
3. Configurar:
   - **URL Base de WAHA:** URL donde está corriendo WAHA (ej: `http://localhost:3000`)
   - **API Key:** Si WAHA requiere autenticación (dejar vacío si no)
   - **Sesión por Defecto:** Nombre de la sesión por defecto (ej: `default`)
4. Hacer clic en **"💾 Guardar Configuración"**

### Paso 4: Crear Primera Sesión

1. En el panel de administración, hacer clic en **"Crear Nueva Sesión"**
2. Ingresar un nombre para la sesión (ej: `default`)
3. Se mostrará un código QR
4. Abrir WhatsApp en tu teléfono
5. Ir a **Configuración → Dispositivos vinculados → Vincular un dispositivo**
6. Escanear el código QR mostrado en la pantalla
7. Esperar a que la sesión se autentique (el QR se actualiza automáticamente)

### Paso 5: Verificar Instalación

1. Verificar que la sesión aparezca como "conectada" en el panel
2. Probar envío de mensaje desde `pacientes-manager.html`

## 🔧 Configuración Avanzada

### Configuración Manual

Si prefieres editar la configuración manualmente:

```php
// modules/whatsapp/config/whatsapp_config.php
return [
    'waha' => [
        'base_url' => 'http://localhost:3000',
        'api_key' => 'tu_api_key_si_es_necesaria',
        'timeout' => 30,
        'default_session' => 'default',
        'default_country_code' => '54'
    ],
    'options' => [
        'log_errors' => true,
        'log_file' => __DIR__ . '/../logs/whatsapp.log'
    ]
];
```

### Variables de Entorno (Opcional)

Puedes usar variables de entorno para configuración sensible:

```bash
export WAHA_BASE_URL=http://localhost:3000
export WAHA_API_KEY=tu_api_key
```

Luego modificar `WhatsAppConfig.php` para leer estas variables.

## 🐛 Solución de Problemas

### Error: "No se pudo conectar con WAHA"

**Causas posibles:**
- WAHA no está corriendo
- URL incorrecta en la configuración
- Problemas de red/firewall

**Solución:**
1. Verificar que WAHA esté corriendo: `docker ps` o verificar proceso
2. Probar conectividad: `curl http://localhost:3000/api/sessions`
3. Verificar URL en configuración

### Error: "Directorio no escribible"

**Solución:**
```bash
chmod 755 modules/whatsapp/config
chmod 755 modules/whatsapp/logs
chown -R www-data:www-data modules/whatsapp/config modules/whatsapp/logs
```

### Error: "Archivo faltante"

**Solución:**
- Verificar que todos los archivos del módulo estén presentes
- Re-ejecutar el instalador
- Verificar permisos de lectura

### Error: "Sesión no se autentica"

**Causas posibles:**
- QR code expirado
- WhatsApp no está conectado a internet
- Sesión ya existe en otro dispositivo

**Solución:**
1. Eliminar la sesión desde el panel
2. Crear nueva sesión
3. Escanear QR nuevamente
4. Asegurarse de que WhatsApp tenga conexión a internet

## ✅ Verificación de Instalación

Después de la instalación, verifica:

- [ ] Estructura de directorios creada
- [ ] Archivo de configuración existe
- [ ] Permisos de escritura correctos
- [ ] Conexión con WAHA exitosa
- [ ] Pestaña de WhatsApp visible en admin.php
- [ ] Puede crear sesiones
- [ ] Puede escanear QR
- [ ] Sesión se autentica correctamente

## 📚 Próximos Pasos

Después de la instalación:

1. Leer [USO.md](USO.md) para aprender a usar el módulo
2. Revisar [API_REFERENCE.md](API_REFERENCE.md) para integración programática
3. Configurar sesiones según necesidades

## 🔄 Actualización

Para actualizar el módulo:

1. Hacer backup de `config/whatsapp_config.php`
2. Reemplazar archivos del módulo
3. Ejecutar `php install.php` nuevamente
4. Restaurar configuración si es necesario

## 📞 Soporte

Si encuentras problemas durante la instalación:

1. Revisar logs en `modules/whatsapp/logs/`
2. Verificar logs de PHP
3. Consultar documentación de WAHA
4. Revisar [README.md](README.md) para información general

