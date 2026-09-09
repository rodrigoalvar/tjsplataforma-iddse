# INTEGRATION — Portar hl7-worklist a otra versión de plataforma

## Requisitos

| Componente | Mínimo |
|------------|--------|
| PHP | 7.4+ (recomendado 8.x) con PDO MySQL |
| Node.js | 16+ (`node` en PATH) |
| Core TJSMEDICAL | `WorklistIngestionService`, tabla `worklist`, `worklist_config` |
| Sistema | systemd (o supervisor equivalente), puerto TCP libre |

## Contrato de datos (estable)

El parser entrega el **mismo payload** que `TxtWorklistParser`:

```
accession_number, patient_id, patient_name, patient_birth_date, patient_sex,
modality, referring_physician, equipment_name, scheduled_date, scheduled_time,
procedure_description, reason_for_study, status, source_file
```

Obligatorios: `accession_number`, `scheduled_date`, `scheduled_time`.

Ingesta: `WorklistIngestionService::upsertFromData(..., 'HL7_MLLP', ...)`.

## Archivos del núcleo a tocar (lista mínima)

1. `utils/WorklistIngestionService.php` — método `ingestHl7Content` + ENUM `HL7_MLLP`
2. `api/worklist-config.php` — keys `hl7_*` + snapshot al guardar
3. `assets/js/configuracion.js` (+ cache-bust HTML) — sección UI HL7
4. Copiar carpeta completa `modules/hl7-worklist/`
5. Unit systemd + firewall al puerto

Todo lo demás vive **dentro del módulo**.

## Checklist de instalación

1. [ ] Backup BD
2. [ ] Desplegar `modules/hl7-worklist/`
3. [ ] Aplicar cambios de núcleo (1–3) si la versión no los trae
4. [ ] `php modules/hl7-worklist/install.php`
5. [ ] `php modules/hl7-worklist/php/tests/smoke-parser.php` → OK
6. [ ] Instalar `hl7-mllp-listener.service`
7. [ ] Configuración → Worklist: `hl7_enabled=1`, puerto, Prestador
8. [ ] Guardar config (genera `runtime/config.json`)
9. [ ] `systemctl restart hl7-mllp-listener`
10. [ ] Probar listener desde UI / `api/status.php`
11. [ ] Enviar ORM^O01 de prueba → fila en `worklist`
12. [ ] Opcional cron: `hl7-inbox-worker.php`

## Keys de configuración (`worklist_config`)

| Key | Default | Descripción |
|-----|---------|-------------|
| `hl7_enabled` | 0 | Activa listener |
| `hl7_port` | 2575 | Puerto TCP |
| `hl7_bind_host` | 0.0.0.0 | Bind |
| `hl7_input_path` | uploads/inbox-hl7 | Inbox |
| `hl7_processed_path` | …/processed | OK |
| `hl7_failed_path` | …/failed | Error |
| `hl7_prestador_field` | PV1-8 | PV1-7 / PV1-8 / OBR-16 / NONE |
| `hl7_spawn_worker_on_receive` | 1 | Spawn PHP al recibir |

## Rollback

1. `systemctl disable --now hl7-mllp-listener`
2. `hl7_enabled = 0` en Configuración
3. `php modules/hl7-worklist/uninstall.php` (no borra worklist ni columnas)

## Upgrade entre versiones del módulo

Ver `module.json` → `version`. Changelog de keys/parser en README del módulo.
Conservar `runtime/config.json` y carpetas inbox al actualizar archivos.
