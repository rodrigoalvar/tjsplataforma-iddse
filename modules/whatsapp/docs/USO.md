# Guía de Uso - Módulo de WhatsApp

## 📖 Índice

1. [Panel de Administración](#panel-de-administración)
2. [Gestión de Sesiones](#gestión-de-sesiones)
3. [Envío de Mensajes](#envío-de-mensajes)
4. [Uso Programático](#uso-programático)
5. [Mejores Prácticas](#mejores-prácticas)

---

## 🎛️ Panel de Administración

### Acceso

1. Ir a `modules/email/admin.php`
2. Hacer clic en la pestaña **"💬 Mensajería WhatsApp"**

### Configuración de WAHA

En la sección **"Configuración de WAHA"**:

- **URL Base de WAHA:** URL donde está corriendo WAHA
  - Ejemplo: `http://localhost:3000`
  - Ejemplo remoto: `http://192.168.1.100:3000`

- **API Key:** Clave de API si WAHA requiere autenticación
  - Dejar vacío si WAHA no requiere autenticación
  - Consultar documentación de WAHA para obtener API key

- **Sesión por Defecto:** Nombre de la sesión que se usará por defecto
  - Ejemplo: `default`, `principal`, `ventas`

Hacer clic en **"💾 Guardar Configuración"** para guardar los cambios.

---

## 💬 Gestión de Sesiones

### Crear Nueva Sesión

1. Hacer clic en **"Crear Nueva Sesión"**
2. Ingresar nombre de la sesión (ej: `default`, `ventas`, `soporte`)
3. Hacer clic en **"Aceptar"**
4. Se mostrará automáticamente el código QR

### Autenticar Sesión (Escanear QR)

1. Después de crear la sesión, se mostrará un modal con el código QR
2. Abrir WhatsApp en tu teléfono
3. Ir a **Configuración → Dispositivos vinculados → Vincular un dispositivo**
4. Escanear el código QR mostrado en la pantalla
5. Esperar a que la sesión se autentique
   - El QR se actualiza automáticamente cada 5 segundos
   - Cuando se autentique, el modal se cerrará automáticamente

**Nota:** El código QR expira después de un tiempo. Si expira, hacer clic en **"Actualizar QR"** o eliminar y recrear la sesión.

### Ver Estado de Sesiones

En la lista de sesiones verás:

- **Nombre de la sesión**
- **Estado:** 
  - 🟢 **Conectada/Autenticada:** Lista para enviar mensajes
  - 🟡 **No autenticada:** Requiere escanear QR
- **Acciones disponibles:**
  - **Ver QR:** Para sesiones no autenticadas
  - **Eliminar:** Eliminar la sesión

### Eliminar Sesión

1. Hacer clic en **"Eliminar"** en la sesión deseada
2. Confirmar la eliminación
3. La sesión se eliminará de WAHA

**⚠️ Advertencia:** Eliminar una sesión desconectará WhatsApp de WAHA. Para volver a usar, necesitarás crear una nueva sesión y escanear el QR nuevamente.

---

## 📤 Envío de Mensajes

### Desde pacientes-manager

El módulo se integra automáticamente con `pacientes-manager.html`:

1. Ir a la lista de pacientes
2. Hacer clic en el botón de WhatsApp (💬) del paciente
3. Se mostrará un modal de confirmación
4. Revisar el mensaje y destinatarios (paciente + médico referente si aplica)
5. Hacer clic en **"Enviar WhatsApp"**

### Formato de Números

El módulo formatea automáticamente los números:

- **Entrada:** `11-1234-5678`, `+54 9 11 1234-5678`, `91123456789`
- **Salida:** `5491123456789@c.us`

**Reglas de formateo:**
- Se eliminan espacios, guiones y caracteres especiales
- Si el número tiene 10 dígitos y no empieza con código de país, se agrega `54` (Argentina)
- Se agrega sufijo `@c.us` para WAHA

### Envío Múltiple

El módulo soporta envío automático a:
- **Paciente** (obligatorio)
- **Médico referente** (si tiene teléfono registrado)

Ambos recibirán el mismo mensaje automáticamente.

---

## 💻 Uso Programático

### Desde PHP

```php
require_once 'modules/whatsapp/WAHAAPI.php';
require_once 'modules/whatsapp/WhatsAppConfig.php';

// Cargar configuración
$config = WhatsAppConfig::load();
$wahaAPI = new WAHAAPI($config->getWahaConfig());

// Enviar mensaje
try {
    $result = $wahaAPI->sendTextMessage(
        'default',              // Nombre de la sesión
        '5491123456789',        // Número de teléfono
        'Mensaje a enviar'      // Mensaje
    );
    
    if ($result['success']) {
        echo "Mensaje enviado correctamente";
    } else {
        echo "Error: " . $result['error'];
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Gestión de Sesiones desde PHP

```php
// Crear sesión
$result = $wahaAPI->createSession('mi_sesion');

// Obtener QR code
$qr = $wahaAPI->getQRCode('mi_sesion');

// Verificar estado
$status = $wahaAPI->getSessionStatus('mi_sesion');

// Listar todas las sesiones
$sessions = $wahaAPI->getSessions();

// Eliminar sesión
$wahaAPI->deleteSession('mi_sesion');
```

### Desde JavaScript

```javascript
// El cliente está disponible globalmente
const result = await evolutionAPI.sendTextMessage(
    '5491123456789',        // Teléfono
    'Mensaje a enviar'      // Mensaje
);

if (result.success) {
    console.log('Mensaje enviado');
} else {
    console.error('Error:', result.error);
}
```

### Desde API REST

```bash
# Enviar mensaje
curl -X POST http://tu-servidor/api/whatsapp/send-message.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tu_token" \
  -d '{
    "telefono": "5491123456789",
    "mensaje": "Mensaje de prueba"
  }'
```

---

## ✅ Mejores Prácticas

### Gestión de Sesiones

1. **Usar nombres descriptivos:** `ventas`, `soporte`, `default`
2. **Una sesión por propósito:** Separar sesiones por departamento o función
3. **Mantener sesiones activas:** No eliminar sesiones innecesariamente
4. **Monitorear estado:** Verificar periódicamente que las sesiones estén conectadas

### Envío de Mensajes

1. **Validar números:** Asegurarse de que los números sean válidos antes de enviar
2. **Mensajes claros:** Usar mensajes concisos y claros
3. **Horarios apropiados:** Respetar horarios de atención
4. **Consentimiento:** Asegurarse de tener consentimiento para enviar mensajes

### Seguridad

1. **API Key:** Si WAHA requiere autenticación, usar API key segura
2. **Permisos:** Solo usuarios autorizados deben gestionar sesiones
3. **Logs:** Revisar logs periódicamente para detectar problemas
4. **Backup:** Hacer backup de la configuración regularmente

### Rendimiento

1. **Timeouts:** Configurar timeouts apropiados según la red
2. **Sesiones:** No crear más sesiones de las necesarias
3. **Conexión:** Verificar que WAHA esté accesible antes de enviar

---

## 🐛 Solución de Problemas Comunes

### "Sesión no autenticada"

**Solución:**
1. Verificar que la sesión exista en WAHA
2. Escanear el QR nuevamente
3. Verificar que WhatsApp tenga conexión a internet

### "Error al enviar mensaje"

**Causas posibles:**
- Sesión no autenticada
- Número inválido
- WAHA no disponible

**Solución:**
1. Verificar estado de la sesión
2. Verificar formato del número
3. Verificar que WAHA esté corriendo

### "QR code no se muestra"

**Solución:**
1. Verificar que la sesión exista
2. Intentar actualizar el QR
3. Eliminar y recrear la sesión

---

## 📚 Referencias

- [README.md](README.md) - Información general del módulo
- [INSTALACION.md](INSTALACION.md) - Guía de instalación
- [API_REFERENCE.md](API_REFERENCE.md) - Referencia completa de la API
- [Documentación de WAHA](https://waha.devlike.pro/docs/) - Documentación oficial de WAHA

