# Integración Orthanc — MPPS

## Objetivo

Recibir notificaciones MPPS de las modalidades en Orthanc y alimentar `mpps_events`.

## Requisitos Orthanc

| Versión | Cómo |
|---------|------|
| ≥ 1.12.5 | MPPS SCP nativo |
| Anterior | Plugin oficial `orthanc-mpps` |

Configurar en cada modalidad el **MPPS SCP** apuntando al AE Title / host / puerto de Orthanc (además del destino C-STORE y MWL si aplica).

## Consulta en vivo (pestaña Auditoría)

Al pulsar **Refrescar** en Auditoría → MPPS / Equipos, el backend llama:

- `GET /mpps` (IDs)
- `GET /mpps/{id}` (detalle)

APIs: `modules/mpps-audit/api/list.php?source=orthanc` y `stats.php?source=orthanc`.

Si Orthanc no tiene MPPS, la grilla queda vacía (no se usa el mock). Mock: `?source=mock`.

## Endpoints Orthanc

- `GET /mpps` — listado de instancias MPPS
- `GET /mpps/{id}` — detalle (estado + MainDicomTags)
- `GET /changes?since={seq}` — feed incremental (ChangeType `MppsStatusChanged`)

**Importante:** `/changes` no incluye fecha/hora del evento. Siempre enriquecer con `GET /mpps/{id}` y tags:

- Start: `0040,0244` / `0040,0245`
- End: `0040,0250` / `0040,0251` (según IOD MPPS)
- Accession, PatientID, StudyInstanceUID, Modality, StationName, etc.

## Estrategias de captura

### A) Polling (entrega 2 — recomendado para auditoría)

Worker: `modules/mpps-audit/workers/mpps-poll-orthanc.php`

1. Leer `mpps_poll_state.last_seq`
2. `GET /changes?since={last_seq}`
3. Filtrar `MppsStatusChanged`
4. `GET /mpps/{id}` → mapear tags → upsert `mpps_events`
5. Guardar nuevo `Last` en `mpps_poll_state`

Intervalo sugerido: **30 segundos** (configurable en `mpps_audit_config.poll_interval_seconds`). No se pierden eventos; solo se retrasa la UI.

Reutilizar patrón de `PacsNodeClient::getOrthancChanges` en `modules/pacs-nodes-manager/PacsNodeClient.php`.

Credenciales Orthanc: `api/config/orthanc_config.php` / `OrthancClient`.

### B) Lua webhook (entrega 2 — tiempo real)

Script Orthanc con `OnMppsStatusChanged` → `HttpPost` a:

`https://<host>/modules/mpps-audit/api/ingest.php`

Hoy `ingest.php` es stub y responde que la ingesta real está pendiente.

## Checklist Orthanc

- [ ] Orthanc recibe MPPS (probar `GET /mpps` tras un estudio de prueba)
- [ ] Modalidad apunta MPPS al AE correcto
- [ ] Plataforma alcanza Orthanc REST (red / auth)
- [ ] Worker o Lua activos
- [ ] Tras primer evento real: `use_mock_when_empty` deja de aplicar (hay filas) o desactivar mock a mano
