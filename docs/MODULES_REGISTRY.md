# Registro de módulos instalables

Inventario de plugins en `/modules/`. Cada módulo incluye `module.json` (cuando aplica), scripts de instalación idempotentes y permisos en `system_permissions`.

| Módulo | Carpeta | Versión | install.php | Permisos principales | Toca núcleo | Estado |
|--------|---------|---------|-------------|----------------------|-------------|--------|
| Audit Manager | `modules/audit-manager` | — | Sí | `audit_manager`, `gui_audit_manager` | `User.php`, sidebars | Instalado |
| PACS Nodes Manager | `modules/pacs-nodes-manager` | 1.0.0 | Sí | `pacs_nodes_manager`, `gui_pacs_nodes_manager` | sidebars | Instalado |
| Cloud Storage | `modules/cloud-storage` | — | Parcial | — | uploads | Instalado |
| Email | `modules/email` | — | Sí | — | eventos | Instalado |
| WhatsApp | `modules/whatsapp` | — | Sí | — | notificaciones | Instalado |
| **Recepción y Turnero** | `modules/recepcion-turnero` | 1.0.0 | Sí | `gui_turnero`, `turnero`, `gui_ingreso_pacientes`, `ingreso_pacientes`, `gui_abm_catalogos`, … | FK opcional a `pacientes`/`worklist`; sidebars (pendiente activación) | **Fase 0 — scaffold** |
| **MPPS Audit** | `modules/mpps-audit` | 1.0.0 | Sí | `mpps_audit`, `gui_mpps_audit` | pestaña en `audit-manager.html`; FK opcional `worklist` | **Fase 1 — scaffold + mock UI** |

## MPPS Audit — detalle

- **Manifiesto:** [`modules/mpps-audit/module.json`](../modules/mpps-audit/module.json)
- **Instalación:** [`modules/mpps-audit/INSTALLATION.md`](../modules/mpps-audit/INSTALLATION.md)
- **Migración:** [`modules/mpps-audit/docs/MIGRACION_OTRAS_INSTANCIAS.md`](../modules/mpps-audit/docs/MIGRACION_OTRAS_INSTANCIAS.md)
- **URLs:**
  - Instalar tablas: `/modules/mpps-audit/install.php`
  - Instalar permisos: `/modules/mpps-audit/install-permissions.php`
  - Desinstalar (suave): `/modules/mpps-audit/uninstall.php`
- **UI:** Auditoría → pestaña **MPPS / Equipos** (permiso `audit_manager`)
- **Docs:** [`doc/usuario/mpps-auditoria-equipos.md`](../doc/usuario/mpps-auditoria-equipos.md), [`doc/tecnico/mpps-auditoria-arquitectura.md`](../doc/tecnico/mpps-auditoria-arquitectura.md)

### Tablas del módulo

`mpps_events`, `mpps_audit_config`, `mpps_poll_state`

## Recepción y Turnero — detalle

- **Manifiesto:** [`modules/recepcion-turnero/module.json`](../modules/recepcion-turnero/module.json)
- **Instalación:** [`modules/recepcion-turnero/INSTALLATION.md`](../modules/recepcion-turnero/INSTALLATION.md)
- **URLs:**
  - Instalar tablas: `/modules/recepcion-turnero/install.php`
  - Instalar permisos: `/modules/recepcion-turnero/install-permissions.php`
  - Desinstalar (suave): `/modules/recepcion-turnero/uninstall.php`

### Permisos del módulo

| permission_key | Tipo |
|----------------|------|
| `gui_turnero` | interfaz |
| `turnero` | funcional |
| `gui_ingreso_pacientes` | interfaz |
| `ingreso_pacientes` | funcional |
| `gui_abm_catalogos` | interfaz |
| `turnero_confirmar_ingreso` | funcional |
| `obras_sociales_abm` | funcional |
| `nomenclador_abm` | funcional |
| `ref_physicians_abm` | funcional |
| `equipos_horarios_abm` | funcional |
| `turnero_admin` | funcional |

### Tablas del módulo

`ref_physicians`, `equipos_imagen`, `nomencladores`, `obras_sociales`, `nomenclador_practicas`, `equipo_horarios`, `equipo_excepciones`, `equipo_secuencias`, `nomenclador_import_logs`, `rt_pacientes`, `rt_paciente_obras_sociales`, `rt_turnos`, `rt_turno_practicas`, `rt_module_meta`

### Integraciones

- **pacientes:** vínculo opcional `rt_pacientes.pacientes_id` → `pacientes.id`
- **worklist:** vínculo opcional `rt_turnos.worklist_id` → `worklist.id` (solo al confirmar ingreso, Fase 5)

## Convención para nuevos módulos

1. Carpeta bajo `modules/<nombre>/`
2. `module.json` con id, versión, permisos y tablas
3. `install.php` idempotente + `install-permissions.php`
4. `uninstall.php` que no borre datos por defecto
5. Entrada en esta tabla
