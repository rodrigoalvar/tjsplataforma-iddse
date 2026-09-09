# 📡 Referencia de API - Módulo de Email

## 🔐 Autenticación

Todos los endpoints requieren autenticación mediante Bearer Token:

```
Authorization: Bearer TU_TOKEN_DE_SESION
```

O mediante cookie:
```
Cookie: session_token=TU_TOKEN_DE_SESION
```

## 📋 Endpoints Disponibles

### 1. Enviar Email

**Endpoint:** `POST /modules/email/api/send.php`

**Descripción:** Envía un email manualmente.

**Request Body:**
```json
{
    "to": "destinatario@email.com",
    "subject": "Asunto del email",
    "body": "<h1>Contenido HTML</h1>",
    "body_type": "html",
    "cc": ["copia@email.com"],
    "bcc": ["copia-oculta@email.com"],
    "reply_to": "respuesta@email.com",
    "attachments": [
        {
            "path": "/ruta/al/archivo.pdf",
            "name": "documento.pdf"
        }
    ]
}
```

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `to` | string | Sí | Email destinatario |
| `subject` | string | Sí | Asunto del email |
| `body` | string | Sí | Cuerpo del mensaje (HTML o texto) |
| `body_type` | string | No | `html` (default) o `text` |
| `cc` | array | No | Emails en copia |
| `bcc` | array | No | Emails en copia oculta |
| `reply_to` | string | No | Email para respuesta |
| `attachments` | array | No | Archivos adjuntos |

**Response (Éxito):**
```json
{
    "success": true,
    "data": {
        "message": "Email enviado correctamente",
        "message_id": "unique-message-id"
    }
}
```

**Response (Error):**
```json
{
    "success": false,
    "error": "Mensaje de error"
}
```

**Ejemplo (JavaScript):**
```javascript
fetch('/modules/email/api/send.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
    },
    body: JSON.stringify({
        to: 'usuario@email.com',
        subject: 'Test',
        body: '<h1>Email de prueba</h1>'
    })
})
.then(res => res.json())
.then(data => {
    if (data.success) {
        console.log('Email enviado:', data.data.message_id);
    } else {
        console.error('Error:', data.error);
    }
});
```

**Ejemplo (cURL):**
```bash
curl -X POST http://tu-dominio.com/modules/email/api/send.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_TOKEN" \
  -d '{
    "to": "usuario@email.com",
    "subject": "Test",
    "body": "<h1>Email de prueba</h1>"
  }'
```

---

### 2. Enviar Email con Plantilla

**Endpoint:** `POST /modules/email/api/send-template.php`

**Descripción:** Envía un email usando una plantilla HTML predefinida.

**Request Body:**
```json
{
    "template": "verification",
    "to": "destinatario@email.com",
    "subject": "Verifica tu cuenta - {{app_name}}",
    "variables": {
        "nombre_usuario": "Juan Pérez",
        "token_verificacion": "abc123"
    },
    "cc": ["copia@email.com"],
    "bcc": ["copia-oculta@email.com"],
    "attachments": [
        {
            "path": "/ruta/al/archivo.pdf",
            "name": "documento.pdf"
        }
    ]
}
```

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `template` | string | Sí | Nombre de la plantilla (sin extensión) |
| `to` | string | Sí | Email destinatario |
| `subject` | string | No | Asunto (puede contener variables) |
| `variables` | object | No | Variables para la plantilla |
| `cc` | array | No | Emails en copia |
| `bcc` | array | No | Emails en copia oculta |
| `attachments` | array | No | Archivos adjuntos |

**Response (Éxito):**
```json
{
    "success": true,
    "data": {
        "message": "Email enviado correctamente",
        "message_id": "unique-message-id",
        "template": "verification"
    }
}
```

**Response (Error):**
```json
{
    "success": false,
    "error": "Plantilla no encontrada: verification"
}
```

**Ejemplo (JavaScript):**
```javascript
fetch('/modules/email/api/send-template.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
    },
    body: JSON.stringify({
        template: 'verification',
        to: 'usuario@email.com',
        subject: 'Verifica tu cuenta - {{app_name}}',
        variables: {
            nombre_usuario: 'Juan Pérez',
            token_verificacion: 'abc123'
        }
    })
})
.then(res => res.json())
.then(data => {
    if (data.success) {
        console.log('Email enviado con plantilla');
    }
});
```

---

### 3. Probar Conexión SMTP

**Endpoint:** `GET /modules/email/api/test-connection.php`

**Descripción:** Prueba la configuración SMTP sin enviar emails. Requiere permisos de administrador.

**Request:**
```
GET /modules/email/api/test-connection.php
Authorization: Bearer ADMIN_TOKEN
```

**Response (Éxito):**
```json
{
    "success": true,
    "data": {
        "message": "Conexión SMTP exitosa",
        "server_info": {
            "host": "smtp.gmail.com",
            "port": 587,
            "secure": "tls"
        }
    }
}
```

**Response (Error):**
```json
{
    "success": false,
    "error": "Error de conexión: Connection timeout"
}
```

**Ejemplo (cURL):**
```bash
curl -X GET \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  http://tu-dominio.com/modules/email/api/test-connection.php
```

---

### 4. Listar Plantillas

**Endpoint:** `GET /modules/email/api/list-templates.php`

**Descripción:** Obtiene lista de plantillas HTML disponibles.

**Request:**
```
GET /modules/email/api/list-templates.php
Authorization: Bearer TOKEN
```

**Response (Éxito):**
```json
{
    "success": true,
    "data": {
        "templates": [
            {
                "name": "verification",
                "file": "verification.html",
                "path": "/ruta/completa/verification.html",
                "size": 1234,
                "modified": 1705315845
            },
            {
                "name": "informe-completado",
                "file": "informe-completado.html",
                "path": "/ruta/completa/informe-completado.html",
                "size": 2345,
                "modified": 1705315846
            }
        ],
        "count": 2,
        "default_variables": {
            "app_name": "TJS Medical - Portal de Estudios",
            "app_url": "http://sistema.com",
            "current_year": "2025",
            "current_date": "15/01/2025",
            "current_datetime": "15/01/2025 10:30:45"
        }
    }
}
```

**Ejemplo (JavaScript):**
```javascript
fetch('/modules/email/api/list-templates.php', {
    headers: {
        'Authorization': 'Bearer ' + token
    }
})
.then(res => res.json())
.then(data => {
    if (data.success) {
        console.log('Plantillas disponibles:', data.data.templates);
    }
});
```

---

## 🔒 Códigos de Estado HTTP

| Código | Descripción |
|--------|-------------|
| `200` | Éxito |
| `400` | Error en los datos enviados |
| `401` | No autenticado |
| `403` | Sin permisos (solo para test-connection.php) |
| `404` | Recurso no encontrado (plantilla, etc.) |
| `405` | Método HTTP no permitido |
| `500` | Error interno del servidor |

## ⚠️ Manejo de Errores

Todos los endpoints devuelven errores en formato JSON:

```json
{
    "success": false,
    "error": "Mensaje descriptivo del error"
}
```

**Errores comunes:**

- `"No autenticado o sesión inválida"` - Token inválido o expirado
- `"Campo requerido: to"` - Faltan campos requeridos
- `"Email destinatario inválido"` - Email con formato incorrecto
- `"Plantilla no encontrada: nombre"` - La plantilla no existe
- `"Error al enviar email: ..."` - Error de SMTP o configuración

## 📝 Notas Importantes

1. **Autenticación**: Todos los endpoints requieren autenticación válida
2. **Content-Type**: Usar `application/json` para requests POST
3. **Encoding**: Los emails se envían en UTF-8
4. **Adjuntos**: Las rutas de archivos deben ser absolutas o relativas al servidor
5. **Rate Limiting**: No hay límite implementado, considerar agregarlo en producción

## 🧪 Ejemplos Completos

### Enviar Email Simple

```javascript
async function enviarEmail(to, subject, body) {
    const response = await fetch('/modules/email/api/send.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + getToken()
        },
        body: JSON.stringify({ to, subject, body })
    });
    
    const data = await response.json();
    return data;
}

// Uso
enviarEmail(
    'usuario@email.com',
    'Bienvenido',
    '<h1>Bienvenido al sistema</h1>'
).then(result => {
    if (result.success) {
        alert('Email enviado');
    }
});
```

### Enviar Email con Plantilla

```javascript
async function enviarEmailVerificacion(email, nombre, token) {
    const response = await fetch('/modules/email/api/send-template.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + getToken()
        },
        body: JSON.stringify({
            template: 'verification',
            to: email,
            subject: 'Verifica tu cuenta',
            variables: {
                nombre_usuario: nombre,
                token_verificacion: token
            }
        })
    });
    
    return await response.json();
}
```

---

**Última actualización**: 2025-01-15

