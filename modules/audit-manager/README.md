# Audit Manager (plugin)

Módulo de auditoría: eventos (`auth.login`, `auth.logout`, conectividad, acciones registradas desde el cliente), sesiones activas con IP de login, cierre forzado de sesiones y estadísticas agregadas.

## Si `install.php` fallaba antes con error 1067 en `fecha_expiracion`

En MySQL/MariaDB con `sql_mode` estricto, `TIMESTAMP NOT NULL` en `sesiones` puede impedir cualquier `ALTER` de la tabla. **`install.php` ahora detecta ese caso**, normaliza fechas raras y convierte `fecha_expiracion` a **`DATETIME NOT NULL`** (compatible con el código actual), y **reintenta** añadir `ip_login`, `user_agent_login` y `fecha_cierre`.

Si aun así fallara, ejecutá manualmente `database/fix_sesiones_fecha_expiracion.sql` (tras backup) y volvé a abrir `install.php`.

## Instalación en una instancia nueva

1. Copiar la carpeta `modules/audit-manager/` al proyecto.
2. Abrir en el navegador **`/modules/audit-manager/install.php`** (crea `audit_manager_events` y columnas opcionales en `sesiones`: `ip_login`, `user_agent_login`, `fecha_cierre`).
3. Abrir **`/modules/audit-manager/install-permissions.php`** y luego asignar en Gestión de usuarios:
   - `audit_manager` — uso del módulo (APIs y página).
   - `gui_audit_manager` — mostrar el ítem “Auditoría” en el sidebar (junto con el resto de permisos GUI).

Los usuarios **`root`** o con permiso **`all`** en JSON ya tienen acceso sin pasos extra.

## Compatibilidad con código existente

- **`classes/User.php`**: el `INSERT` de sesión no cambia. Si existen las columnas opcionales, se hace un `UPDATE` posterior con IP y user-agent. En logout/invalidación, si existe `fecha_cierre`, se rellena.
- **`api/auth/login.php` y `logout.php`**: solo llaman a `AuditLogger` si el archivo existe y la tabla está creada; no rompen si el módulo no está instalado.

## Archivos tocados en el núcleo (referencia)

- `classes/User.php`
- `api/auth/login.php`, `api/auth/logout.php`
- `audit-manager.html` (raíz), sidebars en HTML existentes, `app-container.html`, `assets/js/sidebar-gui-manager.js`, `dashboard-unified.html` (script de conectividad opcional)

## APIs (misma cookie `session_token`)

| Ruta | Quién |
|------|--------|
| `GET api/ping.php` | Sesión válida |
| `POST api/activity-heartbeat.php` | Sesión válida — suma `sesiones.active_seconds` (tiempo con pestaña visible + interacción) |
| `POST api/record.php` | Sesión válida |
| `GET api/connections.php` | `audit_manager` — conexiones: `user_id`, `user_q` (nombre/apellido/email), fechas, `active_only` |
| `GET api/sessions.php` | `audit_manager` — alias: solo activas, sin rango de fechas (compat.) |
| `POST/GET api/portal-paciente-log.php` | **Público** — registro de visita a portal paciente (rate limit / IP) |
| `GET api/portal-paciente-list.php` | `audit_manager` — listado + `stats` (cargas vs búsquedas en el período filtrado) |
| `GET api/audios-audit-list.php` | **Auditor** — solo lectura: `audios_informe` + usuario, estudio, FTP, cola TX; campo `db_duration_missing`; mismos filtros que arriba; `stats.total_minutes` |
| `POST api/audios-fill-duration.php` | **Auditor** — body JSON `{ "audio_ids": [1,2,…] }` (máx. 40): `UPDATE duracion_segundos` vía ffprobe/ffmpeg si archivo existe y BD sin duración |
| `POST api/portal-paciente-purge.php` | `audit_manager` — vaciar tabla o borrar por período/filtros (`confirm`, `scope`) |
| `GET api/users-options.php` | `audit_manager` — lista de usuarios activos (desplegable en la UI) |
| `GET api/list.php` | `audit_manager` — eventos: `user_id`, `user_q`, `action_key`, fechas |
| `GET api/stats.php` | `audit_manager` |
| `POST api/revoke.php` | `audit_manager` |

Tras actualizar el módulo, volvé a ejecutar **`install.php`** para tablas nuevas y columnas en `sesiones` (`active_seconds`, `active_tick_at`, etc.).

## Documentación adicional

- `docs/INTEGRACION.md` — registrar tiempos de consultas desde el front.
- `docs/METRICAS.md` — interpretar RTT vs tiempos de servidor/cliente.
