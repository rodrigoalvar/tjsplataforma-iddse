# Migración a otras instancias / versiones de plataforma

Checklist para llevar **MPPS Audit** a otra copia del Portal (ej. `tjsidimagenes`, staging, otro centro).

## Fase A — Copiar módulo (sin núcleo)

1. Copiar carpeta completa `modules/mpps-audit/`
2. Backup BD destino
3. Ejecutar `install.php` (2 veces, verificar idempotencia)
4. Ejecutar `install-permissions.php`
5. Asignar `audit_manager` y/o `mpps_audit` al usuario piloto
6. Verificar CLI:
   ```bash
   php modules/mpps-audit/workers/mpps-poll-orthanc.php --dry-run
   ```

## Fase B — Parches de núcleo (obligatorios para la pestaña)

Aplicar según [INTEGRACION_AUDIT_UI.md](INTEGRACION_AUDIT_UI.md):

| Archivo | Cambio |
|---------|--------|
| `audit-manager.html` | Tab + pane MPPS |
| `modules/audit-manager/assets/js/audit-manager.js` | `initMppsAuditTab` |

Si la instancia **no** tiene Audit Manager:

- Instalar primero `modules/audit-manager/`, **o**
- Crear página standalone (no incluida en Fase 1; usar `gui_mpps_audit` + sidebar — documentar en fase futura)

## Fase C — Documentación del destino

Copiar o enlazar:

- `doc/usuario/mpps-auditoria-equipos.md`
- `doc/tecnico/mpps-auditoria-arquitectura.md`
- Entradas en `doc/README.md`
- Fila en `docs/MODULES_REGISTRY.md`

## Fase D — Orthanc (cuando pase a entrega 2)

1. Confirmar versión Orthanc / plugin MPPS
2. Configurar modalidades → MPPS SCP
3. Implementar o desplegar worker real + cron
4. Probar estudio de punta a punta
5. `UPDATE mpps_audit_config SET use_mock_when_empty = 0 WHERE id = 1` si se desea forzar no-mock

## Verificación post-migración

- [ ] `install.php` ×2 OK
- [ ] Permisos visibles en Gestión de usuarios
- [ ] Pestaña MPPS abre y muestra Demo (o datos)
- [ ] `api/health.php` → success
- [ ] `api/list.php` / `stats.php` OK
- [ ] Otras pestañas de Auditoría intactas
- [ ] Worklist (si existe) sigue funcionando
- [ ] Rollback probado en staging (`uninstall.php`)

## Rollback

```
/modules/mpps-audit/uninstall.php          # quita permisos, conserva datos
/modules/mpps-audit/uninstall.php?drop_tables=1   # solo staging
```

Revertir diffs de `audit-manager.html` y `audit-manager.js`. Quitar cron si se hubiera añadido.

## Diferencias frecuentes entre instancias

| Situación | Acción |
|-----------|--------|
| Sin tabla `worklist` | Normal; FK omitida; diagnósticos sin match |
| Orthanc remoto | Ajustar `orthanc_config` antes de worker real |
| PHP 7.4 vs 8 | Evitar sintaxis solo-8 en parches nuevos; el módulo usa `declare(strict_types=1)` y `str_starts_with` (polyfill/PHP 8) |
| Rutas distintas a `/var/www/tjsiddse` | Ajustar paths en docs/cron; el código usa `__DIR__` relativo |

Si la instancia es PHP 7.4 y `str_starts_with` no existe, añadir polyfill en `_common.php` o reemplazar por `strpos(...) === 0` antes de desplegar.
