# Envío PDF a Gasalud (técnico)

Última actualización: 2026-09-09

## Objetivo

Enviar el PDF de informes **origen=plataforma** a la API Gasalud PortalPaciente, sin reenviar PDFs de **API recibidos** (`origen=externo`) para evitar loop.

Swagger: `http://192.168.0.149:8325/swagger/v2/swagger.json`

## Endpoints Gasalud

| Método | Ruta | Body |
|--------|------|------|
| POST | `/api/v2/Usuarios/Login` | JSON `{ nombreUsuario, password }` → `{ token, expiration }` |
| POST | `/api/v2/Informes` | `multipart/form-data` |

### Multipart Informes (Swagger)

- `Paciente` (int32) — DNI
- `Prestador` (int32) — matrícula/ID PV1
- `AcessionNumber` (string)
- `Nombre` (string) — filename
- `Descripcion` (string)
- `Tipo` (string) — `pdf`
- **`Archivo`** (binary) — PDF (no “Documento”)

## Arquitectura local

```
Informe plataforma → PDF en disco
        |
        +-- trigger al_pacs → send_to_pacs_service (éxito Orthanc)
        +-- trigger al_finalizado → save.php (estado=finalizado + pdf_path)
        +-- manual → api/informes/gasalud-send.php
        |
        v
 gasalud_try_send_informe()
        |
        +-- skip si origen=externo o inactivo
        +-- login cache token (configuracion)
        +-- POST multipart Archivo
```

## Archivos

| Pieza | Ruta |
|-------|------|
| Helper | `api/informes/gasalud_envio_helper.php` |
| Test/login | `api/informes/gasalud-test.php` |
| Envío manual | `api/informes/gasalud-send.php` |
| Hook PACS | `api/informes/send_to_pacs_service.php` |
| Hook finalizado | `api/informes/save.php` |
| UI | Configuración → Envío Gasalud (`configuracion.html` + `assets/js/configuracion.js`) |
| Claves UI | `api/config/manage.php` categoría `gasalud_envio` |
| Seed | `database/migration_gasalud_envio_login.sql` |

## Claves `configuracion`

| Clave | Default / uso |
|-------|----------------|
| `gasalud_envio_activo` | `0` |
| `gasalud_api_url` | `…/api/v2/Informes` |
| `gasalud_login_url` | `…/api/v2/Usuarios/Login` |
| `gasalud_auth_mode` | `login` |
| `gasalud_auth_username` / `gasalud_auth_password` | Credenciales API (BD) |
| `gasalud_auth_token` / `gasalud_token_expires_at` | Cache login |
| `gasalud_prestador_modo` | `worklist` → `worklist.referring_physician` |
| `gasalud_trigger` | `al_pacs` \| `al_finalizado` \| `manual` |
| `gasalud_tipo_default` | `pdf` |
| `gasalud_verify_ssl` | `0` en LAN HTTP |

## Mapeo de datos

| Campo API | Fuente |
|-----------|--------|
| Paciente | dígitos de `informes.patient_id` |
| AcessionNumber | `informes.accession_number` |
| Prestador | `worklist.referring_physician` (HL7 PV1, mismo criterio que worklist) |
| Nombre | `basename(pdf_path)` |
| Archivo | archivo en `uploads/…` |
| Descripcion | `study_description` o `titulo` |
| Tipo | extensión `pdf` |

## Política anti-loop

`gasalud_informe_is_plataforma()`: si `origen === 'externo'` → skip.  
Los upserts de `informe_recibido_upsert_informe.php` marcan `origen=externo` y no disparan este envío.

## Operación

```bash
# Aplicar seed/defaults
mysql … < database/migration_gasalud_envio_login.sql
```

UI: **Validar config** / **Probar login**. Activar `gasalud_envio_activo=1` cuando el login OK.

Logs: prefijo `[GASALUD]` y `[SEND_TO_PACS][GASALUD]`.

## Manual usuario

Ver `doc/usuario/gasalud-envio-informes.md`.
