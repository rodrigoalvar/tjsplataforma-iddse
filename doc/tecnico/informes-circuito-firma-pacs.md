# Circuito de firma médica — referencia técnica

Última actualización: 2026-09-03

## Resumen

Release **1.4.0**. Estados: `borrador` → `transcripto` → `firmado` → `finalizado` → PACS (gate sin cambios: solo `finalizado`).

## Migración

[`database/migration_informes_firma_medica.sql`](../../database/migration_informes_firma_medica.sql)

- ENUM + `transcripto`
- Columnas `firmado_por`, `firmado_en`, `origen` (`plataforma`|`externo`)
- Tabla `usuarios_firmas`
- `mobile_sessions.session_type` + `firma`
- Permisos `firmarInformes`, `gestionarFirmaPropia`

## Dueño clínico

Helper [`api/informes/informe_medico_responsable_helper.php`](../../api/informes/informe_medico_responsable_helper.php):

1. Informe plataforma previo del estudio  
2. `study_assignments` activas  
3. `study_subassignments`  
4. Sin asignación → `sin_medico_asignado` (no Admin)

Aplicado en upsert recibidos, `attach-pdf.php`, sync al asignar/reasignar estudio.

PDF sin vínculo: permanece en `informes_recibidos`; no hay fila de firma hasta `vincular.php`.

## APIs

| Endpoint | Rol |
|----------|-----|
| `api/informes/sign.php` | Firma transcripto → firmado (HTML sello o merge PDF+gs) |
| `api/informes/pending-sign.php` | Conteo/lista para dashboard (`count_only`, `fecha_inicio`, `fecha_fin`, orden `fecha_modificacion DESC`) |
| `api/users/firma/index.php` | CRUD rúbrica del usuario |
| `api/users/firma/mobile-upload.php` | Subida desde QR móvil |
| `api/mobile_session.php` | `session_type=firma` |

Helpers: `informe_firma_helper.php` (HTML sello, overlay vía TCPDF+Ghostscript).

`save.php`: whitelist de estados; rechaza edición HTML de `origen=externo`.

Recibidos upsert (API): estado inicial `finalizado`, `origen=externo` → auto-PACS. Informes de plataforma: circuito Transcripto → Firmado → Finalizado.

## UI

- Informes-manager: filtros Transcripto/Firmados, botón Firmar, modal Mi firma, badges origen/sin médico; deep-link `?informe_id=&mode=view|edit|sign`.
- Dashboard: contador PARA FIRMAR abre `#pendingSignModal` (lista + Ver/Editar/Firmar vía `sign.php`); filtros `pending_sign` / `signed`; evento `informeEstadoCambiado`.
- `mobile-firma.html`: canvas móvil.

## Versionado HTML

Al firmar plataforma (cambio de HTML) o actualizar path PDF en wrapper se incrementa `version` y se escribe historial cuando aplica.
