# Análisis: Configuración de WAHA por Usuario

## Resumen Ejecutivo

Actualmente, la configuración de WAHA (WhatsApp HTTP API) es **global** para todo el sistema. Este documento analiza la viabilidad y los pasos necesarios para implementar configuración **individual por usuario** para aquellos que tengan el permiso `administracion_whatsapp`.

## Estado Actual

### 1. Configuración Global Actual

**Ubicación:** `modules/whatsapp/config/whatsapp_config.php`

**Estructura:**
```php
return array (
  'waha' => 
  array (
    'base_url' => 'http://100.80.2.42:9081',
    'api_key' => '449c8b43e025456abae7e4c52070acd4',
    'timeout' => 30,
    'default_session' => 'default',
    'default_country_code' => '54',
  ),
  'options' => 
  array (
    'log_errors' => true,
    'log_file' => '/var/www/tjsidimagenes/modules/whatsapp/logs/whatsapp.log',
  ),
);
```

**Clase de gestión:** `modules/whatsapp/WhatsAppConfig.php`
- Métodos: `load()`, `save()`, `getWahaConfig()`

### 2. Cómo se Usa Actualmente

**En `api/whatsapp/send-message.php` (líneas 111-125):**
```php
// Cargar configuración de WhatsApp
$whatsappConfig = WhatsAppConfig::load();
$wahaConfig = $whatsappConfig->getWahaConfig();

// Crear instancia de WAHA API
$wahaAPI = new WAHAAPI($wahaConfig);
```

**En `modules/email/admin.php` (líneas 1776-1806):**
- Formulario para configurar WAHA globalmente
- Solo accesible con permiso `administracion_email` o `administracion_whatsapp`

### 3. Permisos Relacionados

**Permisos existentes:**
- `administracion_whatsapp`: Permite acceder a la administración del módulo de WhatsApp
- `envios_whatsapp`: Permite enviar mensajes por WhatsApp

**Verificación de permisos:**
- En `api/whatsapp/send-message.php`: Verifica permiso `pacientes` o `all` para enviar
- En `modules/email/admin.php`: Verifica `administracion_email` o `all` para acceder

## Análisis de Viabilidad

### ✅ Factible - Similar a SMTP por Usuario

**Referencia existente:** `modules/email/database/user_smtp_config.sql`

Ya existe un sistema similar para configuración SMTP por usuario:
- Tabla: `user_smtp_config`
- Métodos: `EmailConfig::loadForUser($userId)`, `EmailConfig::saveForUser($userId, $config)`
- Implementación completa en `modules/email/IMPLEMENTACION_SMTP_POR_USUARIO.md`

### Ventajas de Implementar Configuración por Usuario

1. **Aislamiento:** Cada usuario puede usar su propia instancia de WAHA
2. **Seguridad:** Las API keys no se comparten entre usuarios
3. **Flexibilidad:** Diferentes usuarios pueden usar diferentes servidores WAHA
4. **Escalabilidad:** Permite múltiples instancias de WhatsApp Business

### Consideraciones

1. **Fallback a configuración global:** Si el usuario no tiene configuración personal, usar la global
2. **Permisos:** Solo usuarios con `administracion_whatsapp` pueden configurar su propia instancia
3. **Validación:** Verificar que la URL de WAHA sea accesible antes de guardar
4. **Sesiones:** Cada usuario puede tener sus propias sesiones de WhatsApp

## Propuesta de Implementación

### 1. Base de Datos

**Crear tabla:** `user_whatsapp_config`

```sql
CREATE TABLE IF NOT EXISTS `user_whatsapp_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `waha_base_url` varchar(255) NOT NULL,
  `waha_api_key` varchar(255) DEFAULT NULL,
  `waha_timeout` int DEFAULT 30,
  `waha_default_session` varchar(100) DEFAULT 'default',
  `waha_default_country_code` varchar(5) DEFAULT '54',
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario_id` (`usuario_id`),
  KEY `idx_usuario_activo` (`usuario_id`, `activo`),
  CONSTRAINT `fk_user_whatsapp_config_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Modificar `WhatsAppConfig.php`

**Agregar métodos:**

```php
/**
 * Carga la configuración de WAHA para un usuario específico
 * @param int $userId ID del usuario
 * @return array|null Configuración de WAHA o null si no existe
 */
public static function loadForUser($userId) {
    try {
        require_once __DIR__ . '/../../config/database.php';
        $pdo = getDBConnection();
        
        $query = "SELECT waha_base_url, waha_api_key, waha_timeout, 
                         waha_default_session, waha_default_country_code
                  FROM user_whatsapp_config 
                  WHERE usuario_id = ? AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config) {
            return [
                'base_url' => $config['waha_base_url'],
                'api_key' => $config['waha_api_key'] ?? '',
                'timeout' => $config['waha_timeout'] ?? 30,
                'default_session' => $config['waha_default_session'] ?? 'default',
                'session_name' => $config['waha_default_session'] ?? 'default',
                'default_country_code' => $config['waha_default_country_code'] ?? '54'
            ];
        }
        
        return null;
    } catch (Exception $e) {
        error_log('Error cargando configuración WAHA por usuario: ' . $e->getMessage());
        return null;
    }
}

/**
 * Guarda la configuración de WAHA para un usuario específico
 * @param int $userId ID del usuario
 * @param array $config Configuración de WAHA
 * @return bool True si se guardó correctamente
 */
public static function saveForUser($userId, $config) {
    try {
        require_once __DIR__ . '/../../config/database.php';
        $pdo = getDBConnection();
        
        // Verificar si ya existe configuración para este usuario
        $checkQuery = "SELECT id FROM user_whatsapp_config WHERE usuario_id = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$userId]);
        $exists = $checkStmt->fetch();
        
        if ($exists) {
            // Actualizar
            $query = "UPDATE user_whatsapp_config SET
                      waha_base_url = ?,
                      waha_api_key = ?,
                      waha_timeout = ?,
                      waha_default_session = ?,
                      waha_default_country_code = ?,
                      activo = 1
                      WHERE usuario_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $config['base_url'],
                $config['api_key'] ?? '',
                $config['timeout'] ?? 30,
                $config['default_session'] ?? 'default',
                $config['default_country_code'] ?? '54',
                $userId
            ]);
        } else {
            // Insertar
            $query = "INSERT INTO user_whatsapp_config 
                     (usuario_id, waha_base_url, waha_api_key, waha_timeout, 
                      waha_default_session, waha_default_country_code, activo)
                     VALUES (?, ?, ?, ?, ?, ?, 1)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $userId,
                $config['base_url'],
                $config['api_key'] ?? '',
                $config['timeout'] ?? 30,
                $config['default_session'] ?? 'default',
                $config['default_country_code'] ?? '54'
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Error guardando configuración WAHA por usuario: ' . $e->getMessage());
        return false;
    }
}
```

### 3. Modificar `api/whatsapp/send-message.php`

**Cambiar líneas 110-125:**

```php
// Obtener ID del usuario actual
$userId = $user_data['id'] ?? null;

// Intentar cargar configuración del usuario primero
$wahaConfig = null;
if ($userId) {
    $wahaConfig = WhatsAppConfig::loadForUser($userId);
}

// Si no tiene configuración personal, usar la global
if (!$wahaConfig) {
    $whatsappConfig = WhatsAppConfig::load();
    $wahaConfig = $whatsappConfig->getWahaConfig();
}

// Mapear default_session a session_name para WAHAAPI
if (isset($wahaConfig['default_session']) && !isset($wahaConfig['session_name'])) {
    $wahaConfig['session_name'] = $wahaConfig['default_session'];
}

// Verificar que la configuración esté completa
if (empty($wahaConfig['base_url'])) {
    sendJsonResponse(false, null, 'Configuración de WAHA incompleta: falta base_url');
}

// Crear instancia de WAHA API
$wahaAPI = new WAHAAPI($wahaConfig);
```

### 4. Modificar `modules/email/admin.php`

**Agregar sección de configuración personal:**

```php
// Verificar si el usuario tiene permiso para configurar WhatsApp personal
$canConfigureWhatsApp = false;
if (isset($user_data['permisos'])) {
    $permissions = is_string($user_data['permisos']) 
        ? json_decode($user_data['permisos'], true) 
        : $user_data['permisos'];
    $canConfigureWhatsApp = in_array('administracion_whatsapp', $permissions) 
                         || in_array('all', $permissions);
}

// En la sección de WhatsApp, agregar:
<?php if ($canConfigureWhatsApp): ?>
<div class="form-section" style="margin-bottom: 30px;">
    <div class="form-section-title">
        <i class="fas fa-user-cog me-2"></i>Mi Configuración de WAHA
    </div>
    <form method="POST" action="?action=save-my-whatsapp-config" id="myWhatsAppConfigForm">
        <?php
        // Cargar configuración personal del usuario
        $myWahaConfig = null;
        if ($userId) {
            $myWahaConfig = WhatsAppConfig::loadForUser($userId);
        }
        ?>
        <div class="form-group">
            <label>URL Base de WAHA (Personal):</label>
            <input type="text" name="waha_base_url" 
                   value="<?php echo htmlspecialchars($myWahaConfig['base_url'] ?? ''); ?>" 
                   placeholder="http://localhost:3000" required>
            <small>Tu instancia personal de WAHA</small>
        </div>
        <div class="form-group">
            <label>API Key (opcional):</label>
            <input type="text" name="waha_api_key" 
                   value="<?php echo htmlspecialchars($myWahaConfig['api_key'] ?? ''); ?>" 
                   placeholder="Dejar vacío si no requiere autenticación">
        </div>
        <button type="submit" class="btn btn-primary">💾 Guardar Mi Configuración</button>
    </form>
</div>
<?php endif; ?>
```

**Agregar handler para guardar configuración personal:**

```php
// En el switch de acciones, agregar:
case 'save-my-whatsapp-config':
    if (!$canConfigureWhatsApp) {
        $message = 'No tienes permisos para configurar WhatsApp';
        $messageType = 'error';
        break;
    }
    
    $wahaConfig = [
        'base_url' => $_POST['waha_base_url'] ?? '',
        'api_key' => $_POST['waha_api_key'] ?? '',
        'timeout' => intval($_POST['waha_timeout'] ?? 30),
        'default_session' => $_POST['waha_default_session'] ?? 'default',
        'default_country_code' => $_POST['waha_default_country_code'] ?? '54'
    ];
    
    if (WhatsAppConfig::saveForUser($userId, $wahaConfig)) {
        $message = 'Configuración personal de WAHA guardada exitosamente';
        $messageType = 'success';
    } else {
        $message = 'Error al guardar configuración personal de WAHA';
        $messageType = 'error';
    }
    break;
```

## Archivos a Modificar

1. ✅ **Crear:** `modules/whatsapp/database/user_whatsapp_config.sql`
2. ✅ **Modificar:** `modules/whatsapp/WhatsAppConfig.php` (agregar métodos `loadForUser` y `saveForUser`)
3. ✅ **Modificar:** `api/whatsapp/send-message.php` (cargar configuración del usuario primero)
4. ✅ **Modificar:** `modules/email/admin.php` (agregar formulario de configuración personal)

## Próximos Pasos

1. **Revisar este análisis** con el equipo
2. **Decidir si implementar** configuración por usuario
3. **Si se aprueba:**
   - Crear la tabla en base de datos
   - Implementar los métodos en `WhatsAppConfig.php`
   - Modificar `send-message.php` para usar configuración del usuario
   - Agregar interfaz en `admin.php`
   - Probar con usuarios de prueba

## Consideraciones Adicionales

### Validación de URL
Antes de guardar, validar que la URL de WAHA sea accesible:
```php
function validateWahaUrl($url) {
    $ch = curl_init($url . '/api/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode >= 200 && $httpCode < 400;
}
```

### Logging
Registrar qué configuración se está usando (global vs usuario):
```php
error_log("Usando configuración WAHA: " . ($userId ? "Usuario #$userId" : "Global"));
```

### Migración
Si hay usuarios existentes que necesitan migrar su configuración, crear script de migración.

## Conclusión

✅ **Es factible** implementar configuración de WAHA por usuario, siguiendo el mismo patrón que SMTP por usuario.

✅ **Ventajas claras:** Aislamiento, seguridad, flexibilidad.

⚠️ **Consideraciones:** Validación, fallback a global, permisos.

📋 **Siguiente paso:** Decisión del equipo sobre implementación.

