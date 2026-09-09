# Arquitectura — Auditoría MPPS

Documentación técnica del módulo **`modules/mpps-audit/`** (Portal de Estudios Médicos / tjsplataforma).

---

## Resumen

MPPS (Modality Performed Procedure Step) es el servicio DICOM por el cual la **modalidad** notifica al RIS/PACS el inicio (`IN PROGRESS`), fin (`COMPLETED`) o interrupción (`DISCONTINUED`) de un procedimiento.

Este módulo:

1. Persiste eventos MPPS en MySQL (`mpps_events`).
2. (Entrega 2) Los obtiene de Orthanc vía polling `/changes` o webhook Lua.
3. Cruza con worklist (`accession_number`) y estudios en PACS (`StudyInstanceUID`).
4. Expone una pestaña en **Auditoría** (`audit-manager.html`).

```
Modalidad ──MPPS──► Orthanc (/mpps)
                        │
         worker poll /changes (entrega 2) o Lua OnMppsStatusChanged
                        │
                        ▼
                  mpps_events (MySQL)
                        │
         cruce worklist + Orthanc /studies (entrega 2)
                        │
                        ▼
         Auditoría → pestaña MPPS (api/list.php, stats.php)
```

**Entrega 1 (actual):** pestaña en Auditoría consulta **Orthanc en vivo** al refrescar (`GET /mpps`). Tablas `mpps_events` listas para persistir (entrega 2). Mock solo con `?source=mock`. Worker de polling sigue siendo stub.

---

## Componentes

| Ruta | Rol |
|------|-----|
| `modules/mpps-audit/MppsAuditService.php` | diagnose, listEvents, getStats, mock |
| `modules/mpps-audit/api/*.php` | health, list, stats, detail, ingest |
| `modules/mpps-audit/workers/mpps-poll-orthanc.php` | Stub poll (entrega 2) |
| `modules/mpps-audit/assets/js/mpps-audit-tab.js` | UI de la pestaña |
| `audit-manager.html` | Tab + pane MPPS |
| `modules/audit-manager/assets/js/audit-manager.js` | Carga dinámica del script de la pestaña |

---

## Tablas

### `mpps_events`

Campos clave: `orthanc_mpps_id` (único), `state`, `accession_number`, `patient_*`, `modality`, `station_name`, `study_instance_uid`, `started_at` / `ended_at` (hora del equipo), `worklist_id` (FK opcional), `worklist_match`, `pacs_study_found`, `audit_status`, `raw_json`.

### `mpps_audit_config`

- `poll_interval_seconds` (default 30)
- `ghost_threshold_minutes` (default 30)
- `use_mock_when_empty` (default 1)

### `mpps_poll_state`

Cursor `last_seq` para `/changes?since=`.

---

## Diagnóstico (`audit_status`)

| Valor | Condición aproximada |
|-------|----------------------|
| `normal` | worklist matched + MPPS activo/completado + PACS sí |
| `orphan` | sin match worklist + MPPS + PACS sí |
| `ghost` | MPPS COMPLETED/DISCONTINUED + PACS no |
| `no_show` | worklist matched + sin MPPS útil + PACS no (demo / futuro job) |
| `pending_images` | MPPS activo + PACS no |
| `unknown` | resto |

Implementación: `MppsAuditService::diagnose()`.

---

## APIs

Autenticación: cookie `session_token` o Bearer. Permiso: `mpps_audit` **o** `audit_manager` (o `root` / `all`).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `modules/mpps-audit/api/health.php` | Tablas y conteo |
| GET | `modules/mpps-audit/api/list.php?source=orthanc` | Listado en vivo desde Orthanc `/mpps` (default) |
| GET | `modules/mpps-audit/api/list.php?source=mock` | Dataset de demostración |
| GET | `modules/mpps-audit/api/list.php?source=db` | Filas persistidas en `mpps_events` |
| GET | `modules/mpps-audit/api/stats.php?source=orthanc` | Cards resumen |
| GET | `modules/mpps-audit/api/detail.php?id=` o `orthanc_mpps_id=` | Detalle |
| POST | `modules/mpps-audit/api/ingest.php` | Stub webhook (entrega 2) |

---

## Orthanc

- MPPS SCP nativo: Orthanc **≥ 1.12.5**, o plugin `orthanc-mpps` en versiones anteriores.
- `/changes` **no** trae timestamp; hay que enriquecer con `GET /mpps/{id}` (tags `0040,0244` / `0040,0245`, etc.).
- Polling 30 s es aceptable para auditoría; no pierde eventos (cursor `Seq`).
- Alternativa: Lua `OnMppsStatusChanged` → POST `ingest.php` (entrega 2).

Ver `modules/mpps-audit/docs/INTEGRACION_ORTHANC.md`.

---

## Instalación

1. `modules/mpps-audit/install.php`
2. `modules/mpps-audit/install-permissions.php`
3. Parches de UI ya aplicados en esta instancia (ver `INTEGRACION_AUDIT_UI.md`)
4. Asignar `audit_manager` (pestaña) y opcionalmente `mpps_audit`

Registro: `docs/MODULES_REGISTRY.md`.

---

## Manual de usuario

`doc/usuario/mpps-auditoria-equipos.md`

---

*Última actualización: 2026-09-02*
