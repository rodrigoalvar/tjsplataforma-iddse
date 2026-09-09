# Recepción y Turnero

Módulo plugin para ingreso de pacientes, turnero (provisorio y presencial), catálogos de cobertura e integración opcional con worklist DICOM.

**Versión:** 1.0.0 (Fase 0 — scaffold)  
**Estado:** Listo para desarrollo en staging; no activar en producción sin checklist.

## Qué incluye (Fase 0)

- Manifiesto `module.json`
- DDL idempotente (`database/install.sql` + `install.php`)
- Registro de permisos (`install-permissions.php`)
- Desinstalación suave (`uninstall.php`)
- Páginas placeholder: Turnero, Ingreso de Pacientes, ABM Catálogos
- API de salud: `api/health.php`

## Qué NO toca

- Tablas del núcleo (`pacientes`, `worklist`) — solo FK opcionales
- `WorklistIngestionService.php`
- `dashboard-unified.html` / sidebars (se integran en fase de activación)
- `pacientes-manager.html`

## Requisitos

- PHP 8+ con PDO MySQL
- Tablas `system_permissions`, `user_permissions`, `sesiones`
- Opcional: tablas `pacientes` y `worklist` para FKs

## Instalación rápida

1. Backup de BD
2. Ejecutar `install.php`
3. Ejecutar `install-permissions.php`
4. Asignar permisos a usuario piloto en Gestión de usuarios
5. (Opcional) Añadir ítems al sidebar según `module.json`

Ver [INSTALLATION.md](INSTALLATION.md) para pasos detallados y rollback.

## Permisos

| Clave | Tipo | Uso |
|-------|------|-----|
| `gui_turnero` | interfaz | Menú Turnero |
| `turnero` | funcional | Reservar turnos |
| `gui_ingreso_pacientes` | interfaz | Menú Ingreso |
| `ingreso_pacientes` | funcional | Ingreso y prácticas |
| `gui_abm_catalogos` | interfaz | Menú catálogos |
| `turnero_confirmar_ingreso` | funcional | Confirmar → worklist |
| `obras_sociales_abm` | funcional | ABM obras sociales |
| `nomenclador_abm` | funcional | ABM nomenclador |
| `ref_physicians_abm` | funcional | ABM médicos referentes |
| `equipos_horarios_abm` | funcional | ABM equipos/horarios |
| `turnero_admin` | funcional | Supervisor |

## Tablas creadas

Catálogos: `ref_physicians`, `equipos_imagen`, `nomencladores`, `obras_sociales`, `nomenclador_practicas`, `equipo_horarios`, `equipo_excepciones`, `equipo_secuencias`, `nomenclador_import_logs`

Operativas: `rt_pacientes`, `rt_paciente_obras_sociales`, `rt_turnos`, `rt_turno_practicas`, `rt_module_meta`

## Roadmap

| Fase | Contenido |
|------|-----------|
| 0 | Scaffold (este release) |
| 1 | APIs CRUD catálogos |
| 2 | Horarios y disponibilidad |
| 3 | Turnero y turnos provisorios |
| 4 | Ingreso de pacientes |
| 5 | Integración worklist |
| 6 | Pulido y documentación API |

## Riesgos

- Ejecutar `install.php` en horario pico sin backup
- Asignar permisos GUI antes de probar en staging
- Usar `uninstall.php?drop_tables=1` en producción

## Soporte

Documentación global: [`docs/MODULES_REGISTRY.md`](../../docs/MODULES_REGISTRY.md)
