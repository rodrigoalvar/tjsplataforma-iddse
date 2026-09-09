# Módulo HL7 → Worklist (técnico)

## Objetivo

Recibir ORM^O01 por **MLLP** (sin Mirth), parsear e insertar/actualizar `worklist` vía `WorklistIngestionService`, coexistiendo con PULL `.txt`.

## Arquitectura

```
RIS --TCP/MLLP--> hl7-mllp-listener.js
                      | ACK MSA|AA
                      v
                 inbox/*.hl7
                      |
                      v
            hl7-process-one.php
                      |
                      v
         Hl7OrmWorklistParser
                      |
                      v
    WorklistIngestionService::ingestHl7Content
                      |
          +-----------+-----------+
          v                       v
       worklist              Orthanc .wl / REST
```

## Mapeo HL7 (ORM^O01)

| worklist | HL7 |
|----------|-----|
| accession_number | ORC-2 / OBR-2 / IPC-1 |
| patient_id | PID-2 o PID-3 (preferir CX tipo DNI) |
| patient_name | PID-5 |
| patient_birth_date | PID-7 |
| patient_sex | PID-8 |
| modality | OBR-24 / IPC-5 (fallback OBR-25) |
| procedure_description | IPC-7 / OBR-19 / OBR-4 |
| scheduled_date/time | ORC-7 / OBR-27 (YYYYMMDDHHMMSS) |
| referring_physician | **configurable** `hl7_prestador_field` |
| order_control | ORC-1 (`NW` alta/actualiza; `CA`/`OC`/`DC` cancela) |

### Cancelación (`ORC|CA|accession`)

Si `ORC-1` es `CA`, `OC` o `DC`: se elimina el ítem de Orthanc (si estaba sync) y se borra la fila en `worklist` (libera el accession para un `NW` posterior). Queda registro en `worklist_ingests` con `status=deleted`.

## Configuración

Keys en `worklist_config` (UI: Configuración → Worklist).  
Al guardar: `Hl7WorklistModule::writeConfigSnapshot()` → `modules/hl7-worklist/runtime/config.json`.

## Archivos clave

| Pieza | Ruta |
|-------|------|
| Parser | `modules/hl7-worklist/php/Hl7OrmWorklistParser.php` |
| Fachada | `modules/hl7-worklist/php/Hl7WorklistModule.php` |
| Listener | `modules/hl7-worklist/listener/hl7-mllp-listener.js` |
| Workers | `…/workers/hl7-process-one.php`, `hl7-inbox-worker.php` |
| Status | `modules/hl7-worklist/api/status.php` |
| Core hook | `utils/WorklistIngestionService.php` → `ingestHl7Content` |
| Config API | `api/worklist-config.php` |

## Ops

```bash
# systemd
sudo cp modules/hl7-worklist/listener/hl7-mllp-listener.service.example \
  /etc/systemd/system/hl7-mllp-listener.service
sudo systemctl enable --now hl7-mllp-listener

# cron de respaldo
* * * * * php /var/www/tjsiddse/modules/hl7-worklist/workers/hl7-inbox-worker.php
```

Firewall: permitir solo la IP del RIS hacia `hl7_port`.

Logs: `modules/hl7-worklist/logs/listener.log`  
UI: **Configuración → Logs → HL7 / MLLP** (`modules/hl7-worklist/api/logs.php`)  
Heartbeat: `modules/hl7-worklist/runtime/listener.heartbeat` (< 60 s = vivo)

## Coexistencia TXT

Selector **Canal de ingesta Worklist** (`worklist_ingest_mode`):

| Valor | Efecto |
|-------|--------|
| `none` | Sin PULL TXT ni HL7 (manual/API) |
| `txt` | Solo `.txt` (`pull_enabled=1`, `hl7_enabled=0`) |
| `hl7` | Solo MLLP (`pull_enabled=0`, `hl7_enabled=1`) |
| `both` | Ambos canales activos |

**No duplicar ítems:** `worklist.accession_number` es UNIQUE. TXT y HL7 hacen upsert sobre la misma fila. Si el contenido es idéntico (`last_payload_hash`), el segundo canal responde `no_change`.

Misma tabla `worklist`; `source_type` en `worklist_ingests` distingue `PULL_FOLDER` vs `HL7_MLLP` solo en el log de ingestas.

## Troubleshooting

| Síntoma | Acción |
|---------|--------|
| Listener sin heartbeat | `systemctl status hl7-mllp-listener`; revisar Node PATH |
| Mensajes en failed | Ver error en log del worker; validar scheduled_date/time |
| Prestador incorrecto | Cambiar selector PV1-7/PV1-8 en Configuración |
| Puerto no abre | Firewall / bind_host / otro proceso en el puerto |

## Contrato portable

Ver `modules/hl7-worklist/INTEGRATION.md`.

---

*Última actualización: 2026-09-02*
