# SLA estudios recibidos — referencia técnica

Última actualización: 2026-09-03

## Resumen

Ancla: `estudios.local_arrived_at` (primera aparición en Orthanc local).  
Cumplido: informe con `pacs_series_id` / `pacs_instance_id` / `fecha_enviado_pacs`.  
Feature flag: `configuracion.sla_activo` (default `0`).

## Migración

[`database/migration_sla_estudios_recibidos.sql`](../../database/migration_sla_estudios_recibidos.sql)

- Columnas en `estudios`: `local_arrived_at`, `sla_override_horas`, `sla_excluido`, `sla_excluido_motivo`
- Tablas `sla_plantillas`, `study_informe_timeline`
- Permiso `monitorearSlaEstudios`
- Claves `sla_activo`, `sla_default_horas`, `sla_warning_horas`, `sla_webhook_secret`

## APIs / helpers

| Pieza | Rol |
|-------|-----|
| `api/estudios/sla_helper.php` | Llegada, eventos, resolución de horas |
| `api/estudios/local-arrived-webhook.php` | OnStableStudy |
| `api/estudios/sla-pending.php` | Conteo/lista (respeta `sla_activo`) |
| `api/estudios/sla-templates.php` | CRUD plantillas |
| `api/estudios/sla-override.php` | Override / exclusión |
| `api/estudios/backfill-timeline.php` | CLI backfill timeline |

Horas: override estudio → plantilla (prioridad ASC, match modalidad) → `sla_default_horas`.

## Ganchos de llegada

- Webhook Orthanc
- Cloner reconcile success (`pacs_node_jobs_reconcile.php`)
- Alta estudio en `vincular.php`

## Ganchos de timeline

- `assign_study.php` → assigned
- `save.php` / `sign.php` → dictated / transcripto / firmado
- `send_to_pacs_service.php` → publicado

## UI

- Config: pestaña **Estudios Recibidos**
- Informes-manager: contadores + `#slaEstudiosModal` + `#slaFilter` (permiso)
