# Study routing (R2 / PACS local)

## Qué hace
- Con permiso `study_routing` y modo efectivo `r2`, los listados que ya integran el servicio (`get_all_studies.php`, `api/pacs-manager/list.php`) devuelven:
  - **`viewer_url`**: PACS local, o visor + parámetro `manifestUrl` apuntando a `manifest.php` si el estudio está en R2 con **manifest** (no ZIP).
  - **`download_url`**: ZIP de Orthanc, o **URL presignada** del `study.zip` en R2 si la subida fue en modo **ZIP**.
- Sin permiso o modo `local` → siempre enlaces al PACS como antes.
- **ZIP en R2**: `r2_zip_key` en `r2_studies` (el worker rellena al completar subida ZIP). El visor sigue abriendo desde **PACS local**; la descarga usa R2.
- **Manifest**: requiere URL pública del API `manifest.php` → **Cloud Storage → URL pública del portal** (`r2_public_portal_base_url`). Las firmas de `.dcm` siguen usando **Custom Domain** de R2 si está configurado.

## Migraciones SQL (MySQL)
1. `modules/cloud-storage/database/add_r2_studies_zip_key_safe.sql` — columna `r2_zip_key`.
2. `database/add_study_routing_user_column_safe.sql` — columna `usuarios.study_routing_mode`.
3. `database/add_study_routing_permissions.sql` — permisos `gui_study_routing`, `study_routing`, `study_routing_manage`.

## Configuración
- **Global**: Configuración del sistema → pestaña **Study routing** (visible con `gui_study_routing` o root/`all`).
- **Por usuario**: Gestión de usuarios → categoría Visor → **Study routing (por usuario)** (requiere columna `study_routing_mode`).
- Claves en tabla `configuracion`: `study_routing_global_mode` (`local`|`r2`), `study_routing_manifest_query_param` (por defecto `manifestUrl`).

## Pendiente / extensiones
- Integrar el mismo resolver en `get_patient_studies.php` y `get_user_assigned_studies_fixed.php` si esos flujos deben servir R2.
- Toasts en UI cuando `study_routing.fallback` indique caída a local.
