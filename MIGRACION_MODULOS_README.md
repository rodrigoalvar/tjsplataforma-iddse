# Migración de Módulos desde tjsidimagenes

## Resumen de la Migración

Fecha: 25 de Enero de 2026

### Módulos Migrados

#### 1. Módulo de Email (`/modules/email/`)
Sistema completo de gestión de emails con las siguientes funcionalidades:

- **Panel de Administración** (`admin.php`): Interfaz web para configurar SMTP, gestionar plantillas y ver logs
- **Configuración SMTP por Usuario**: Cada usuario puede tener su propia configuración SMTP
- **Plantillas de Email**: Sistema de plantillas HTML personalizables
- **API de Envíos**: Endpoints para enviar emails programáticamente
- **Sistema de Logs**: Registro de todos los emails enviados

**Estructura del módulo:**
```
modules/email/
├── admin.php              # Panel de administración
├── api/                   # APIs REST
│   ├── send.php
│   ├── list-templates.php
│   ├── manage-template.php
│   └── ...
├── config/                # Configuración
│   ├── email_config.php
│   └── email_events.php
├── templates/             # Plantillas de email
├── logs/                  # Logs de envíos
├── vendor/                # PHPMailer
├── EmailService.php       # Clase principal de envío
├── EmailConfig.php        # Gestión de configuración
└── EmailTemplate.php      # Gestión de plantillas
```

#### 2. Gestor de Pacientes Avanzado
Actualización del módulo de gestión de pacientes con nuevas funcionalidades:

- **Envío de Emails a Pacientes**: Botón para enviar emails directamente desde la lista
- **Envío de WhatsApp**: Integración con WhatsApp para notificaciones
- **Médico Referente**: Campos para registrar datos del médico referente
- **Plantillas Configurables**: Selección de plantillas por defecto para paciente y médico
- **Búsqueda de Estudios Mejorada**: Modal para buscar estudios y cargar ID PACS automáticamente

### Tablas de Base de Datos Creadas

1. **`user_smtp_config`**: Configuración SMTP por usuario
   - `usuario_id`, `smtp_host`, `smtp_port`, `smtp_secure`
   - `smtp_username`, `smtp_password`, `from_email`, `from_name`

2. **`user_email_preferences`**: Preferencias de email por usuario
   - `usuario_id`, `default_template`, `default_template_medico`

3. **Columnas agregadas a `pacientes`**:
   - `medico_referente_nombre`
   - `medico_referente_matricula`
   - `medico_referente_telefono`
   - `medico_referente_email`

### Permisos del Sistema

Se agregaron los siguientes permisos:
- `administracion_email`: Acceso al panel de administración de email
- `envios_email`: Permiso para enviar emails a pacientes
- `envios_whatsapp`: Permiso para enviar mensajes de WhatsApp

### Archivos Modificados

- `pacientes-manager.html`: Reemplazado con versión avanzada
- `assets/js/pacientes-manager.js`: Reemplazado con versión avanzada
- `dashboard-unified.html`: Agregado enlace a Gestión Mensajes en sidebar
- `estudios-manager.html`: Agregado enlace a Gestión Mensajes en sidebar

### Backups Creados

Se crearon backups automáticos de los archivos existentes:
- `pacientes-manager.html.backup_YYYYMMDDHHMMSS`
- `pacientes-manager.js.backup_YYYYMMDDHHMMSS`

### Cómo Usar

1. **Acceder al Panel de Email**: 
   - Navegar a `modules/email/admin.php` o usar el enlace "Gestión Mensajes" en el sidebar

2. **Configurar SMTP**:
   - En el panel de administración, configurar los datos del servidor SMTP
   - Cada usuario puede tener su propia configuración

3. **Crear Plantillas**:
   - Usar el editor de plantillas para crear emails personalizados
   - Las plantillas soportan variables como `{{nombre_paciente}}`, `{{fecha}}`, etc.

4. **Enviar Emails desde Pacientes**:
   - En la lista de pacientes, usar el botón de email para enviar notificaciones
   - Seleccionar la plantilla deseada antes de enviar

### Instalador

El archivo `install-modules-migration.php` puede ejecutarse nuevamente si es necesario:
- Acceder desde el navegador para ver la interfaz gráfica
- O ejecutar desde línea de comandos

### Notas Importantes

- Los archivos originales fueron respaldados antes de la migración
- El módulo de email requiere PHPMailer (ya incluido en vendor/)
- Para Gmail, usar "App Password" en lugar de la contraseña normal
- Los logs de email se guardan en `modules/email/logs/`
