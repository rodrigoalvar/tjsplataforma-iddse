# Study History Manager

Búsqueda de estudios por **Patient ID** (idpaciente) en todos los nodos activos de **PACS Nodes Manager**, agrupada por **StudyInstanceUID**. Origen por defecto: nodo `local` primero, luego `id` ascendente. Si el mismo UID está en varios nodos, la API devuelve `sources[]` y la UI ofrece un selector; **Abrir** llama a `viewer-link.php` con el `source_node_id` elegido (manifest/WADO para ese nodo, sin repetir C-FIND).

## Instalación

1. Ejecutar [install-permissions.php](install-permissions.php) (desde el navegador).
2. Asignar a usuarios:
   - `study_history_manager` — uso de la API y de la página.
   - `gui_study_history_manager` — ítem visible en el sidebar (junto a otros `gui_*`).
3. Ejecutar [database/migration_add_node_viewer_bases.sql](database/migration_add_node_viewer_bases.sql) cuando aún no existan las columnas.
4. Completar por nodo en **Configuración del sistema** → pestaña **Visores nodos PACS** (`configuracion.html`):
   - `wado_uri_base` — base WADO-URI (UDV / legacy).
   - `dicomweb_proxy_base` — base del proxy DICOMweb (Stone u otros).

## API

- `POST api/search.php` — cuerpo JSON `{ "patient_id": "..." }`.
- `POST api/viewer-link.php` — `{ "study_instance_uid", "source_node_id", "is_on_local_pacs": bool }`.

Si el UID existe en Orthanc local, el enlace usa la configuración global de visores. Si no, se usa `RemoteViewerUrlBuilder` con los campos del nodo.

## Integración futura

La página [study-history-manager.html](../../study-history-manager.html) sirve de sandbox; más adelante se puede incrustar el mismo flujo en el workspace sin duplicar la lógica de agregación.
