# Integración: tiempos de acción y consultas

Para medir cuánto tarda una operación percibida por el usuario:

1. En el **cliente**, guardá `performance.now()` (o `Date.now()`) antes del `fetch` y después de parsear la respuesta.
2. Enviá un `POST` a `modules/audit-manager/api/record.php` con JSON:

```json
{
  "action_key": "query.mi_modulo.listado",
  "client_duration_ms": 420,
  "resource_type": "informe",
  "resource_id": "123",
  "metadata": { "rows": 15 }
}
```

Si el endpoint principal devuelve `server_processing_ms` en el JSON, podés reenviarlo en `metadata` para correlacionar en informes.

El script **`assets/js/audit-connectivity.js`** (incluido en `dashboard-unified.html`) registra periódicamente `connectivity.ping` con RTT aproximado para la pestaña del dashboard.

Tras `requireAuth()` el **`js/auth-middleware.js`** carga **`assets/js/audit-session-activity.js`**, que envía `POST` a **`activity-heartbeat.php`** solo con pestaña visible e interacción reciente; el servidor acumula segundos en `sesiones.active_seconds` (ejecutar **`install.php`** si faltan columnas).

## Portal paciente (`paciente.html`)

Al cargar **`paciente.html`** se registra una visita sin texto de búsqueda; al pulsar **Visualizar mi estudio** se envía de nuevo **`portal-paciente-log.php`** (POST) con `patient_query` (documento / ID introducido). Se guardan IP, user-agent, referer y página en `audit_portal_paciente_visits`. No requiere sesión; hay límite aproximado de **200 registros por IP y por hora**. Ejecutar **`install.php`** para la columna `patient_query`.
