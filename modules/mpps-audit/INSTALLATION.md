# Instalación — MPPS Audit

## Pre-requisitos

- Backup completo de la base de datos
- Usuario con acceso a ejecutar scripts PHP vía web o CLI
- Preferible entorno staging antes de producción

## Paso 1: Desplegar archivos

Copiar la carpeta `modules/mpps-audit/` al servidor.

En esta instancia también deben estar los parches de UI documentados en [docs/INTEGRACION_AUDIT_UI.md](docs/INTEGRACION_AUDIT_UI.md) (`audit-manager.html` + `audit-manager.js`).

## Paso 2: Crear tablas

**Navegador:**  
`https://<host>/modules/mpps-audit/install.php`

**CLI:**
```bash
php /var/www/tjsiddse/modules/mpps-audit/install.php
```

El script es **idempotente**. Ejecutarlo dos veces no debe fallar.

Verificar mensajes de creación o “ya existía” para:

- `mpps_events`
- `mpps_audit_config`
- `mpps_poll_state`

Si existe la tabla `worklist`, se intenta añadir FK `fk_mpps_events_worklist`.

## Paso 3: Registrar permisos

`https://<host>/modules/mpps-audit/install-permissions.php`

Confirma `mpps_audit` y `gui_mpps_audit`.

## Paso 4: Asignar permisos

1. Ir a **Gestión de usuarios**
2. Usuarios que ya tienen `audit_manager` ven la pestaña y pueden usar las APIs
3. Opcional: asignar solo `mpps_audit` para acceso API sin el resto de Auditoría

Usuarios `root` o con permiso `all` ya tienen acceso.

## Paso 5: Verificar

- Abrir **Auditoría** → pestaña **MPPS / Equipos**
- Debe aparecer badge **Demo** y 4 filas de ejemplo
- Con sesión válida:
  - `GET /modules/mpps-audit/api/health.php` → `success: true`
  - `GET /modules/mpps-audit/api/list.php` → `is_mock: true` (si BD vacía)
  - `GET /modules/mpps-audit/api/stats.php` → cards

Worker stub (opcional):
```bash
php /var/www/tjsiddse/modules/mpps-audit/workers/mpps-poll-orthanc.php --dry-run
```

## Checklist pre-producción (Fase 1)

- [ ] Backup BD + archivos
- [ ] `install.php` ejecutado 2 veces sin error
- [ ] `install-permissions.php` OK
- [ ] Pestaña MPPS visible y mock cargado
- [ ] `health` / `list` / `stats` OK
- [ ] Auditoría existente (otras pestañas) sigue funcionando
- [ ] Rollback documentado

## Rollback

**Modo seguro (recomendado):**
```
/modules/mpps-audit/uninstall.php
```
Elimina permisos del módulo; conserva `mpps_*`.

**Solo staging:**
```
/modules/mpps-audit/uninstall.php?drop_tables=1
```

Además: revertir parches en `audit-manager.html` y `audit-manager.js` si se desea quitar la pestaña.

## Troubleshooting

| Problema | Solución |
|----------|----------|
| Sin conexión BD | Verificar `config/database.php` |
| FK worklist omitida | Normal si no hay tabla `worklist` |
| 403 en API | Asignar `mpps_audit` o `audit_manager` |
| Pestaña no carga datos | Revisar consola; ruta `modules/mpps-audit/assets/js/mpps-audit-tab.js` |
| Sin mock | Revisar `mpps_audit_config.use_mock_when_empty` o si ya hay filas reales |

## Entrega 2 (pendiente)

- Activar cron del worker cuando implemente polling real
- Configurar Orthanc MPPS SCP (ver INTEGRACION_ORTHANC.md)
- Desactivar mock: `UPDATE mpps_audit_config SET use_mock_when_empty = 0 WHERE id = 1`
