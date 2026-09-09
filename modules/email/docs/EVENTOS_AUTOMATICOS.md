# 🎯 Sistema de Eventos Automáticos - Módulo de Email

## 📋 Descripción

El módulo de email permite enviar emails automáticamente cuando ocurren ciertos eventos en el sistema. Los eventos se configuran en `config/email_events.php` y se disparan desde el código del sistema principal.

## ⚙️ Configuración de Eventos

### Archivo: `config/email_events.php`

```php
return [
    'usuario_registrado' => [
        'enabled' => true,                    // Habilitar/deshabilitar
        'template' => 'verification',         // Nombre de plantilla
        'recipient' => 'user_email',           // Clave en $data para email
        'subject' => 'Verifica tu cuenta - {{app_name}}',
        'description' => 'Se dispara cuando un usuario se registra'
    ],
    // ... más eventos
];
```

### Parámetros de Evento

| Parámetro | Descripción | Requerido |
|-----------|-------------|-----------|
| `enabled` | Si el evento está habilitado | Sí |
| `template` | Nombre de la plantilla (sin extensión) | Sí |
| `recipient` | Clave en `$data` que contiene el email | Sí |
| `subject` | Asunto del email (puede contener variables) | Sí |
| `description` | Descripción del evento | No |

## 📧 Eventos Predefinidos

### 1. `usuario_registrado`

Se dispara cuando un nuevo usuario se registra.

**Configuración:**
```php
'usuario_registrado' => [
    'enabled' => true,
    'template' => 'verification',
    'recipient' => 'user_email',
    'subject' => 'Verifica tu cuenta - {{app_name}}'
]
```

**Uso:**
```php
$emailManager->trigger('usuario_registrado', [
    'user_email' => $user->email,
    'nombre_usuario' => $user->nombre,
    'token_verificacion' => $token
]);
```

### 2. `informe_completado`

Se dispara cuando se completa un informe médico.

**Configuración:**
```php
'informe_completado' => [
    'enabled' => true,
    'template' => 'informe-completado',
    'recipient' => 'paciente_email',
    'subject' => 'Tu informe está listo - {{app_name}}'
]
```

**Uso:**
```php
$emailManager->trigger('informe_completado', [
    'paciente_email' => $paciente->email,
    'nombre_paciente' => $paciente->nombre,
    'informe_id' => $informe->id,
    'fecha_completado' => date('d/m/Y'),
    'medico' => $medico->nombre
]);
```

### 3. `estudio_asignado`

Se dispara cuando se asigna un estudio a un médico.

**Configuración:**
```php
'estudio_asignado' => [
    'enabled' => true,
    'template' => 'asignacion-estudio',
    'recipient' => 'medico_email',
    'subject' => 'Nuevo estudio asignado - {{app_name}}'
]
```

**Uso:**
```php
$emailManager->trigger('estudio_asignado', [
    'medico_email' => $medico->email,
    'nombre_medico' => $medico->nombre,
    'nombre_paciente' => $paciente->nombre,
    'estudio_id' => $estudio->id,
    'tipo_estudio' => $estudio->tipo,
    'fecha_asignacion' => date('d/m/Y')
]);
```

### 4. `notificacion_generica`

Para notificaciones generales del sistema.

**Configuración:**
```php
'notificacion_generica' => [
    'enabled' => true,
    'template' => 'notificacion-generica',
    'recipient' => 'email',
    'subject' => 'Notificación - {{app_name}}'
]
```

**Uso:**
```php
$emailManager->trigger('notificacion_generica', [
    'email' => 'usuario@email.com',
    'nombre_usuario' => 'Juan Pérez',
    'titulo' => 'Recordatorio importante',
    'mensaje' => 'Este es un mensaje de notificación',
    'url_accion' => 'http://sistema.com/accion',
    'texto_accion' => 'Ver más'
]);
```

## 🔌 Integración en el Sistema

### Opción 1: Integración Directa

En el código donde ocurre el evento:

```php
// En User.php después de registrar usuario
require_once __DIR__ . '/modules/email/EmailEventManager.php';

$emailManager = new EmailEventManager();
$emailManager->trigger('usuario_registrado', [
    'user_email' => $user->email,
    'nombre_usuario' => $user->nombre . ' ' . $user->apellido,
    'token_verificacion' => $token
]);
```

### Opción 2: Función Helper

Crear una función helper en el sistema principal:

```php
// En helpers/email.php
function sendEmailEvent($eventName, $data) {
    require_once __DIR__ . '/../modules/email/EmailEventManager.php';
    $emailManager = new EmailEventManager();
    return $emailManager->trigger($eventName, $data);
}

// Uso
sendEmailEvent('usuario_registrado', [
    'user_email' => $user->email,
    'nombre_usuario' => $user->nombre
]);
```

### Opción 3: Hook System

Si el sistema tiene un sistema de hooks:

```php
// Registrar hook
add_hook('user_registered', function($user) {
    require_once __DIR__ . '/modules/email/EmailEventManager.php';
    $emailManager = new EmailEventManager();
    $emailManager->trigger('usuario_registrado', [
        'user_email' => $user->email,
        'nombre_usuario' => $user->nombre
    ]);
});
```

## ➕ Crear Nuevos Eventos

### 1. Agregar en `config/email_events.php`

```php
'mi_nuevo_evento' => [
    'enabled' => true,
    'template' => 'mi-plantilla',
    'recipient' => 'email_destinatario',
    'subject' => 'Asunto - {{app_name}}',
    'description' => 'Descripción del evento'
]
```

### 2. Crear Plantilla

Crear `templates/mi-plantilla.html`:

```html
<!DOCTYPE html>
<html>
<head>
    <title>{{titulo}}</title>
</head>
<body>
    <h1>{{titulo}}</h1>
    <p>{{mensaje}}</p>
</body>
</html>
```

### 3. Disparar Evento

```php
$emailManager->trigger('mi_nuevo_evento', [
    'email_destinatario' => 'usuario@email.com',
    'titulo' => 'Mi Título',
    'mensaje' => 'Mi mensaje'
]);
```

## 🔄 Habilitar/Deshabilitar Eventos

### Desde Código

```php
$emailManager = new EmailEventManager();

// Habilitar
$emailManager->enableEvent('usuario_registrado');

// Deshabilitar
$emailManager->disableEvent('usuario_registrado');
```

### Desde Configuración

Editar `config/email_events.php`:

```php
'usuario_registrado' => [
    'enabled' => false, // Cambiar a false para deshabilitar
    // ...
]
```

## 📝 Variables Disponibles en Plantillas

Todas las variables pasadas en `$data` están disponibles en las plantillas, más las variables del sistema:

### Variables del Sistema

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `{{app_name}}` | Nombre de la aplicación | `TJS Medical - Portal de Estudios` |
| `{{app_url}}` | URL base de la aplicación | `http://sistema.com` |
| `{{current_year}}` | Año actual | `2025` |
| `{{current_date}}` | Fecha actual | `15/01/2025` |
| `{{current_datetime}}` | Fecha y hora actual | `15/01/2025 10:30:45` |

### Variables Personalizadas

Cualquier variable pasada en `$data` está disponible:

```php
$emailManager->trigger('evento', [
    'mi_variable' => 'valor'
]);
```

En la plantilla:
```html
<p>{{mi_variable}}</p>
```

## 🧪 Probar Eventos

### Desde Código

```php
require_once 'modules/email/EmailEventManager.php';

$emailManager = new EmailEventManager();

// Probar evento
$result = $emailManager->trigger('usuario_registrado', [
    'user_email' => 'test@ejemplo.com',
    'nombre_usuario' => 'Usuario de Prueba',
    'token_verificacion' => 'test-token-123'
]);

if ($result['success']) {
    echo "Email enviado correctamente";
} else {
    echo "Error: " . $result['message'];
}
```

### Verificar Logs

Revisar `logs/email.log` para ver el resultado de los eventos:

```
[2025-01-15 10:30:45] [info] [EmailEventManager] Evento usuario_registrado disparado exitosamente - Email enviado a: test@ejemplo.com
```

## ⚠️ Consideraciones

1. **Verificar que el evento esté habilitado** antes de dispararlo
2. **Asegurar que la plantilla existe** y está en `templates/`
3. **Validar que el email destinatario** esté en `$data` con la clave correcta
4. **Revisar logs** si los emails no se envían
5. **No disparar eventos en loops** sin control de rate limiting

## 📚 Ejemplos Completos

Ver ejemplos en:
- `docs/README.md` - Ejemplos básicos
- `tests/test-email.php` - Script de prueba

---

**Última actualización**: 2025-01-15

