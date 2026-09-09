# Referencia de API - Módulo de WhatsApp

## 📋 Índice

1. [Clase WAHAAPI](#clase-wahaapi)
2. [Endpoints REST](#endpoints-rest)
3. [Ejemplos](#ejemplos)

---

## 🔧 Clase WAHAAPI

### Constructor

```php
$wahaAPI = new WAHAAPI($config);
```

**Parámetros:**
- `$config` (array): Configuración de WAHA
  - `base_url` (string): URL base de WAHA (ej: `http://localhost:3000`)
  - `api_key` (string, opcional): API key si WAHA requiere autenticación
  - `timeout` (int, opcional): Timeout en segundos (default: 30)

### Métodos

#### `sendTextMessage($sessionName, $to, $message)`

Envía un mensaje de texto.

**Parámetros:**
- `$sessionName` (string): Nombre de la sesión a usar
- `$to` (string): Número de teléfono del destinatario
- `$message` (string): Mensaje a enviar

**Retorna:**
```php
[
    'success' => true,
    'data' => [...] // Respuesta de WAHA
]
```

**Ejemplo:**
```php
$result = $wahaAPI->sendTextMessage('default', '5491123456789', 'Hola!');
```

#### `createSession($sessionName)`

Crea una nueva sesión de WhatsApp.

**Parámetros:**
- `$sessionName` (string): Nombre de la sesión

**Retorna:** Respuesta de WAHA con información de la sesión

**Ejemplo:**
```php
$result = $wahaAPI->createSession('mi_sesion');
```

#### `getQRCode($sessionName)`

Obtiene el código QR para autenticar una sesión.

**Parámetros:**
- `$sessionName` (string): Nombre de la sesión

**Retorna:** Respuesta con QR code (base64 o URL)

**Ejemplo:**
```php
$qr = $wahaAPI->getQRCode('mi_sesion');
// $qr['qr'] contiene el QR code
```

#### `getSessionStatus($sessionName)`

Obtiene el estado de una sesión.

**Parámetros:**
- `$sessionName` (string): Nombre de la sesión

**Retorna:** Estado de la sesión

**Ejemplo:**
```php
$status = $wahaAPI->getSessionStatus('mi_sesion');
// $status['state'] puede ser: 'open', 'connecting', 'close', etc.
```

#### `getSessions()`

Obtiene lista de todas las sesiones.

**Retorna:** Array de sesiones

**Ejemplo:**
```php
$sessions = $wahaAPI->getSessions();
```

#### `deleteSession($sessionName)`

Elimina una sesión.

**Parámetros:**
- `$sessionName` (string): Nombre de la sesión

**Retorna:** Respuesta de WAHA

**Ejemplo:**
```php
$wahaAPI->deleteSession('mi_sesion');
```

#### `checkConnection()`

Verifica la conexión con WAHA.

**Retorna:** `true` si WAHA está disponible, `false` en caso contrario

**Ejemplo:**
```php
if ($wahaAPI->checkConnection()) {
    echo "WAHA está disponible";
}
```

#### `formatPhoneNumber($phone)`

Formatea un número de teléfono para WAHA.

**Parámetros:**
- `$phone` (string): Número de teléfono

**Retorna:** Número formateado (ej: `5491123456789@c.us`)

**Ejemplo:**
```php
$formatted = $wahaAPI->formatPhoneNumber('11-1234-5678');
// Retorna: '5491123456789@c.us'
```

---

## 🌐 Endpoints REST

### POST `/api/whatsapp/send-message.php`

Envía un mensaje de WhatsApp.

**Autenticación:** Requerida (token de sesión)

**Permisos:** `pacientes` o `all`

**Request:**
```json
{
    "telefono": "5491123456789",
    "mensaje": "Mensaje a enviar",
    "session": "default"  // Opcional, usa default si no se especifica
}
```

**Response (éxito):**
```json
{
    "success": true,
    "data": {
        "message": "Mensaje enviado correctamente",
        "result": {...}
    }
}
```

**Response (error):**
```json
{
    "success": false,
    "error": "Mensaje de error"
}
```

### GET `/modules/whatsapp/api/sessions.php`

Obtiene lista de sesiones o estado de una sesión específica.

**Autenticación:** Requerida

**Permisos:** `administracion_email` o `all`

**Query Parameters:**
- `session` (opcional): Nombre de sesión específica

**Response (lista):**
```json
{
    "success": true,
    "data": {
        "sessions": [
            {
                "name": "default",
                "status": {...}
            }
        ]
    }
}
```

**Response (sesión específica):**
```json
{
    "success": true,
    "data": {
        "session": "default",
        "status": {
            "state": "open",
            ...
        }
    }
}
```

### POST `/modules/whatsapp/api/sessions.php`

Crea una nueva sesión.

**Autenticación:** Requerida

**Permisos:** `administracion_email` o `all`

**Request:**
```json
{
    "name": "mi_sesion"
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "session": "mi_sesion",
        "result": {...}
    }
}
```

### DELETE `/modules/whatsapp/api/sessions.php`

Elimina una sesión.

**Autenticación:** Requerida

**Permisos:** `administracion_email` o `all`

**Request:**
```json
{
    "name": "mi_sesion"
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "session": "mi_sesion",
        "result": {...}
    }
}
```

### GET `/modules/whatsapp/api/qrcode.php`

Obtiene el código QR de una sesión.

**Autenticación:** Requerida

**Permisos:** `administracion_email` o `all`

**Query Parameters:**
- `session` (requerido): Nombre de la sesión

**Response:**
```json
{
    "success": true,
    "data": {
        "session": "mi_sesion",
        "qr": "data:image/png;base64,...",
        "raw": {...}
    }
}
```

---

## 💡 Ejemplos

### Ejemplo Completo: Envío de Mensaje

```php
<?php
require_once 'modules/whatsapp/WAHAAPI.php';
require_once 'modules/whatsapp/WhatsAppConfig.php';

try {
    // Cargar configuración
    $config = WhatsAppConfig::load();
    $wahaAPI = new WAHAAPI($config->getWahaConfig());
    
    // Verificar conexión
    if (!$wahaAPI->checkConnection()) {
        throw new Exception('WAHA no está disponible');
    }
    
    // Enviar mensaje
    $result = $wahaAPI->sendTextMessage(
        'default',
        '5491123456789',
        'Hola! Este es un mensaje de prueba.'
    );
    
    if ($result['success']) {
        echo "Mensaje enviado correctamente\n";
    } else {
        echo "Error: " . ($result['error'] ?? 'Error desconocido') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
```

### Ejemplo: Gestión Completa de Sesión

```php
<?php
require_once 'modules/whatsapp/WAHAAPI.php';
require_once 'modules/whatsapp/WhatsAppConfig.php';

$config = WhatsAppConfig::load();
$wahaAPI = new WAHAAPI($config->getWahaConfig());

$sessionName = 'mi_sesion';

try {
    // 1. Crear sesión
    echo "Creando sesión...\n";
    $wahaAPI->createSession($sessionName);
    
    // 2. Obtener QR
    echo "Obteniendo QR code...\n";
    $qr = $wahaAPI->getQRCode($sessionName);
    echo "QR: " . substr($qr['qr'], 0, 50) . "...\n";
    
    // 3. Esperar autenticación (en producción, usar polling)
    echo "Esperando autenticación...\n";
    sleep(10);
    
    // 4. Verificar estado
    $status = $wahaAPI->getSessionStatus($sessionName);
    echo "Estado: " . ($status['state'] ?? 'unknown') . "\n";
    
    // 5. Enviar mensaje si está conectado
    if (($status['state'] ?? '') === 'open') {
        $result = $wahaAPI->sendTextMessage($sessionName, '5491123456789', 'Hola!');
        echo "Mensaje enviado: " . ($result['success'] ? 'Sí' : 'No') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
```

### Ejemplo: JavaScript (Fetch API)

```javascript
// Enviar mensaje
async function enviarMensaje(telefono, mensaje) {
    try {
        // Obtener token de autenticación
        const token = document.cookie
            .split('; ')
            .find(row => row.startsWith('session_token='))
            ?.split('=')[1];
        
        const response = await fetch('api/whatsapp/send-message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': token ? `Bearer ${token}` : ''
            },
            credentials: 'include',
            body: JSON.stringify({
                telefono: telefono,
                mensaje: mensaje
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            console.log('Mensaje enviado correctamente');
            return result;
        } else {
            throw new Error(result.error || 'Error al enviar mensaje');
        }
    } catch (error) {
        console.error('Error:', error);
        throw error;
    }
}
```

---

## 🔐 Autenticación

Todos los endpoints requieren autenticación mediante:

- **Cookie:** `session_token`
- **Header:** `Authorization: Bearer {token}`

El token se obtiene del sistema de autenticación del proyecto (mismo que el módulo de email).

---

## ⚠️ Códigos de Error HTTP

- **200:** Éxito
- **400:** Bad Request (datos inválidos)
- **401:** No autenticado
- **403:** Sin permisos
- **404:** Recurso no encontrado
- **500:** Error interno del servidor

---

## 📝 Notas

- Todos los números de teléfono se formatean automáticamente
- El formato por defecto es para números argentinos (código 54)
- Los mensajes se envían a través de la sesión especificada
- Si no se especifica sesión, se usa la sesión por defecto de la configuración

---

## 📚 Referencias

- [README.md](README.md) - Información general
- [USO.md](USO.md) - Guía de uso
- [Documentación de WAHA](https://waha.devlike.pro/docs/) - Documentación oficial

