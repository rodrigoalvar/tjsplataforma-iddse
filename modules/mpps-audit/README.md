# MPPS Audit

Módulo plugin para auditar el uso de equipos DICOM mediante notificaciones **MPPS** (inicio/fin de estudio), con cruce opcional a worklist y PACS.

**Versión:** 1.0.0 (Fase 1 — scaffold + pestaña mock)  
**Estado:** Listo para demo en staging; polling Orthanc real = entrega 2.

## Qué incluye (Fase 1)

- Manifiesto `module.json`
- DDL idempotente (`database/install.sql` + `install.php`)
- Permisos `mpps_audit`, `gui_mpps_audit`
- Desinstalación suave (`uninstall.php`)
- APIs: `health`, `list`, `stats`, `detail`, `ingest` (stub)
- Servicio `MppsAuditService` + dataset mock (4 escenarios)
- Worker stub CLI `workers/mpps-poll-orthanc.php`
- Pestaña **MPPS / Equipos** en Auditoría + `assets/js/mpps-audit-tab.js`

## Qué NO hace aún (entrega 2)

- Polling real de Orthanc `/changes` + `/mpps`
- Cruce automático worklist/PACS en producción
- Actualización de estados de worklist desde MPPS
- Webhook Lua productivo

## Requisitos

- PHP 7.4+ / 8+ con PDO MySQL
- Tablas `system_permissions`, `user_permissions`, `sesiones`
- Módulo Auditoría (pestaña) o al menos permiso `audit_manager` / `mpps_audit`
- Opcional: tabla `worklist`, Orthanc con MPPS SCP

## Instalación rápida

1. Backup de BD
2. Ejecutar `install.php`
3. Ejecutar `install-permissions.php`
4. Verificar pestaña en `audit-manager.html` (ver [docs/INTEGRACION_AUDIT_UI.md](docs/INTEGRACION_AUDIT_UI.md))
5. Abrir Auditoría → MPPS / Equipos (debe mostrar demo)

Detalle: [INSTALLATION.md](INSTALLATION.md) · Migración: [docs/MIGRACION_OTRAS_INSTANCIAS.md](docs/MIGRACION_OTRAS_INSTANCIAS.md)

## Documentación

| Documento | Contenido |
|-----------|-----------|
| [INSTALLATION.md](INSTALLATION.md) | Pasos, rollback, checklist |
| [docs/INTEGRACION_ORTHANC.md](docs/INTEGRACION_ORTHANC.md) | MPPS SCP, polling, Lua |
| [docs/INTEGRACION_WORKLIST.md](docs/INTEGRACION_WORKLIST.md) | Cruce por accession |
| [docs/INTEGRACION_AUDIT_UI.md](docs/INTEGRACION_AUDIT_UI.md) | Parches en núcleo |
| [docs/MIGRACION_OTRAS_INSTANCIAS.md](docs/MIGRACION_OTRAS_INSTANCIAS.md) | Copiar a otras plataformas |
| [../../doc/usuario/mpps-auditoria-equipos.md](../../doc/usuario/mpps-auditoria-equipos.md) | Manual usuario |
| [../../doc/tecnico/mpps-auditoria-arquitectura.md](../../doc/tecnico/mpps-auditoria-arquitectura.md) | Manual técnico |

## Permisos

| Clave | Uso |
|-------|-----|
| `mpps_audit` | APIs del módulo |
| `gui_mpps_audit` | Reservado sidebar futuro |
| `audit_manager` | Ver pestaña en Auditoría (ya existente) |

Las APIs aceptan `mpps_audit` **o** `audit_manager`.
