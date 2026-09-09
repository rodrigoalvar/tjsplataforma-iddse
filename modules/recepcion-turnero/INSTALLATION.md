# Instalación — Recepción y Turnero

## Pre-requisitos

- Entorno **staging** o ventana de mantenimiento en producción
- Backup completo de la base de datos
- Usuario con acceso a ejecutar scripts PHP vía web o CLI

## Paso 1: Desplegar archivos

Copiar la carpeta `modules/recepcion-turnero/` al servidor. No modificar archivos del núcleo en esta fase.

## Paso 2: Crear tablas

**Navegador:**  
`https://<host>/modules/recepcion-turnero/install.php`

**CLI:**
```bash
php /var/www/tjsiddse/modules/recepcion-turnero/install.php
```

El script es **idempotente**: puede ejecutarse varias veces sin error.

Verificar que aparezcan mensajes de creación o "ya existía" para las 14 tablas.

## Paso 3: Registrar permisos

`https://<host>/modules/recepcion-turnero/install-permissions.php`

Confirma que se crean/actualizan 11 permisos.

## Paso 4: Asignar permisos

1. Ir a **Gestión de usuarios**
2. Asignar al usuario piloto:
   - Recepción: `gui_turnero`, `turnero`, `gui_ingreso_pacientes`, `ingreso_pacientes`, `turnero_confirmar_ingreso`
   - Admin catálogos: `gui_abm_catalogos` + `*_abm` según rol

## Paso 5: Verificar (sin activar sidebar aún)

- `api/health.php` responde JSON con `success: true` (requiere sesión + permiso `turnero`)
- Páginas placeholder cargan con autenticación:
  - `/modules/recepcion-turnero/turnero.html`
  - `/modules/recepcion-turnero/ingreso-pacientes.html`
  - `/modules/recepcion-turnero/catalogos.html`

## Paso 6: Activar sidebar (cuando corresponda)

En una fase posterior, añadir los 3 ítems en `dashboard-unified.html` y `sidebar-gui-manager.js` siguiendo el patrón de worklist/audit-manager, con `display:none` hasta que el permiso GUI esté asignado.

Referencias en `module.json` → `sidebar`.

## Checklist pre-producción

- [ ] Backup BD + archivos
- [ ] `install.php` ejecutado 2 veces sin error
- [ ] `pacientes-manager` sigue funcionando
- [ ] `worklist.html` sigue funcionando
- [ ] Permisos solo en usuario piloto
- [ ] Rollback documentado

## Rollback

**Modo seguro (recomendado):**
```
/modules/recepcion-turnero/uninstall.php
```
Elimina permisos; conserva datos.

**Solo staging/desarrollo:**
```
/modules/recepcion-turnero/uninstall.php?drop_tables=1
```
Elimina permisos y tablas del módulo.

Además: revertir cambios manuales en sidebars si se hubieran aplicado.

## Troubleshooting

| Problema | Solución |
|----------|----------|
| Sin conexión BD | Verificar `config/database.php` |
| FK a pacientes omitida | Normal si no existe tabla `pacientes`; se añade en re-ejecución |
| FK a worklist omitida | Normal si no existe tabla `worklist` |
| Permisos no en Gestión usuarios | Re-ejecutar `install-permissions.php` |
| 403 en API | Asignar permiso funcional al usuario |
