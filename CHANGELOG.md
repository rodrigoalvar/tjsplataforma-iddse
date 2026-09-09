# Changelog

Historial de cambios y nuevas funcionalidades del sistema.

---

## [v1.4.2] — 09/09/2026

### Envío PDF Gasalud (multipart real)

- Auth **Login API** (`/Usuarios/Login` → Bearer) con credenciales en BD.
- POST multipart a `/Informes` con campo **`Archivo`** (Swagger).
- Solo informes `origen=plataforma`; no API recibidos (anti-loop).
- Trigger default `al_pacs`; Prestador desde worklist (matrícula PV1 HL7).
- Docs: `doc/usuario/gasalud-envio-informes.md`, `doc/tecnico/gasalud-envio-informes.md`.

---

## [v1.4.1] — 03/09/2026

### Envío PDF Gasalud (config)

Pestaña **Configuración → Envío Gasalud** (base). En 1.4.2 se cablea el envío real.

### Informes recibidos API → Finalizado (auto-PACS)

Al vincular/upsert PDF externo por API el informe queda `finalizado` + `origen=externo` (no entra en cola PARA FIRMAR). La firma médica sigue en informes de plataforma (`transcripto` → `firmado` → `finalizado`).

### SLA estudios recibidos (feature off por defecto)

Monitoreo de plazo desde llegada al PACS local hasta publicación del informe.

- Ancla `estudios.local_arrived_at`; cumplido = publicado en PACS.
- Configuración → pestaña **Estudios Recibidos** (`sla_activo=0` por defecto, 72 h, aviso 24 h, plantillas, webhook secret).
- Permiso `monitorearSlaEstudios`; contadores/modal/filtro en informes-manager.
- Timeline `study_informe_timeline` (llegada → asignado → dictado → transcripto → firmado → publicado).
- Migración: `database/migration_sla_estudios_recibidos.sql`.
- Docs: `doc/usuario/sla-estudios-recibidos.md`, `doc/tecnico/sla-estudios-recibidos.md`.

---

## [v1.4.0] — 02/09/2026

### Circuito de firma médica de informes

Flujo **Transcripto → Firmado → Finalizado → PACS** sin cambiar el gate PACS (`solo finalizado`).

- Nuevo estado `transcripto` y columna `origen` (`plataforma`|`externo`).
- Firma del médico responsable (`sign.php`): sello en HTML (plataforma) o página de sello en PDF (externo, TCPDF+Ghostscript).
- Dueño clínico al vincular/adjuntar vía `study_assignments` (ya no Admin del upsert).
- PDF API sin estudio: primero vincular; sin combo médico en bandeja.
- Perfil **Mi firma** (upload + QR móvil `mobile-firma.html`).
- Dashboard: contador **PARA FIRMAR** abre modal con lista (fechas, Ver/Editar/Firmar); filtros `pending_sign`/`signed`, resaltado de filas.
- Deep-link `informes-manager.html?informe_id=&mode=view|edit` desde el modal.
- Permisos: `firmarInformes`, `gestionarFirmaPropia`.
- Docs: `doc/usuario/informes-firma-medica.md`, `doc/tecnico/informes-circuito-firma-pacs.md`.
- Migración: `database/migration_informes_firma_medica.sql`.

### Features estabilizadas (deuda post-1.3.20, documentadas en este release)

- Alertas de estado de transcripción en Informes Manager + health Whisper.
- Módulo HL7 → Worklist (recepción MLLP).
- Auditoría MPPS / equipos.
- Copiar antecedentes (mismo día) y copiar plantillas a médicos.

#### Archivos principales
- `api/informes/sign.php`, `pending-sign.php`, `informe_firma_helper.php`, `informe_medico_responsable_helper.php`
- `api/informes/save.php`, `list.php`, `get.php`, `attach-pdf.php`, recibidos upsert
- `api/users/firma/*`, `mobile-firma.html`, `api/mobile_session.php`
- `components/informes-manager.html`, `assets/js/informes-manager.js`
- `dashboard-unified.html`, `assets/js/dashboard-with-permissions.js`
- `version.json`, `assets/js/app-version.js` — build `202609022300` (JS dashboard/manager cache-bust `202609030030` para modal PARA FIRMAR)

---

## [v1.3.20] — 09/07/2026

### QA / Control de Calidad — refinamiento portal, UI cola y diálogos

Ajustes posteriores a v1.3.19 según uso real del módulo.

**Portal con QA inactivo (`qa_enabled=0`):**
- El portal **no filtra** estudios ni informes (comportamiento original del módulo).
- Los bloqueos quedan guardados en BD y aplican al activar QA.
- Badges y toasts indican **«VISIBLE EN PORTAL»** cuando hay bloqueo en QA pero QA está inactivo.
- `open.php` solo rechaza estudios bloqueados si QA está activo.

**Cola de revisión (`qa-manager`):**
- Búsqueda **en tiempo real** al escribir (filtro local con debounce).
- Botones de acción **toggle** (un botón por imágenes y otro por informe) en lugar de par verde/rojo.
- Badges de estado con iconos y texto según efecto real en portal.
- Diálogos nativos (`alert` / `confirm` / `prompt`) reemplazados por **toasts y modales Bootstrap**.

#### Archivos modificados
- `modules/qa-publicacion/QaPublicationService.php` — `applyToPortal` con QA inactivo sin filtro
- `modules/qa-publicacion/assets/js/qa-manager.js`, `qa-quick-action.js`
- `modules/qa-publicacion/qa-manager.html`
- `modules/qa-publicacion/api/set-study-status.php`, `set-informe-status.php`
- `api/portal-estudios/v2/open.php`
- `estudios-manager.html`, `components/informes-manager.html`
- `version.json`, `assets/js/app-version.js` — build `202607091420`

---

## [v1.3.19] — 09/07/2026

### QA / Control de Calidad — mejoras de UX, portal y auditoría

Refinamiento del módulo QA tras el despliegue inicial: acción rápida, visibilidad en managers, bloqueo real en portal y registro de auditoría enriquecido.

**Acción rápida (estudios-manager / informes-manager):**
- Toasts Bootstrap en lugar de `alert()` del navegador al despublicar/republicar.
- Badges **PORTAL OCULTO** / **QA PENDIENTE** en la grilla de estudios e informes.
- Botón de portal con iconos por estado (globe = visible, user-slash = oculto, hourglass = pendiente) para no confundirlo con el botón «Ver».
- Botón **republicar** cuando el ítem está bloqueado; refresco de estados vía `get-status-batch.php` y evento `qa-status-changed`.
- Botón QA de informes siempre visible (no solo junto al botón amarillo de PACS).

**Portal del paciente:**
- `applyToPortal()`: estudios con `estado=bloqueado` se ocultan aunque `qa_enabled=0` (bloqueos explícitos siempre aplican).
- `portal-estudios/v2/open.php`: rechaza apertura directa (403) de estudios bloqueados en QA.
- Cascada estudio → informe al bloquear imágenes.

**Registro de auditoría QA:**
- Cada acción guarda contexto del estudio: paciente, ID, modalidad, fecha y descripción (JSON en `detalle` + enriquecimiento al consultar desde informes/Orthanc).
- Tabla ampliada con columnas de paciente, estudio, modalidad y fecha.
- **Modal** «Auditoría» (botón en la cola de revisión) en lugar de card fija debajo de la lista; visible con permiso `qa_ver_registro`.

**Fix:**
- `estudios-manager`: método `refreshQaStudyStatuses()` faltante tras integración QA (error al inicializar con caché local).

#### Archivos modificados
- `modules/qa-publicacion/QaPublicationService.php` — portal, contexto auditoría, cola
- `modules/qa-publicacion/assets/js/qa-quick-action.js`, `qa-manager.js`
- `modules/qa-publicacion/qa-manager.html`
- `modules/qa-publicacion/api/*` — `set-study-status`, `set-informe-status`, `log-list`, `get-config`, `_auth`
- `api/portal-estudios/v2/open.php`
- `assets/js/estudios-manager.js`, `assets/js/informes-manager.js`
- `estudios-manager.html`, `components/informes-manager.html`
- `version.json`, `assets/js/app-version.js` — build `202607091330`

---

## [v1.3.18] — 09/07/2026

### PACS Manager — impacto al eliminar estudios (informes / audios)

Antes de borrar un estudio en Orthanc, el sistema analiza qué hay vinculado en la plataforma y advierte al operador.

**Fase 1 — Detección y aviso:**
- Nuevo endpoint `delete-impact.php`: informes, audios, flags y asignaciones ligados al `study_id` / `StudyInstanceUID`.
- Modal de eliminación muestra resumen (severidad, listado de informes/audios).
- Si hay informes finalizados, PDF en PACS o audios: checkbox de confirmación obligatorio.

**Fase 2 — Post-eliminación:**
- Tras borrado exitoso en Orthanc, limpia refs PACS huérfanas en `informes` (`pacs_series_id`, `pacs_instance_id`, etc.) sin borrar el informe.

**Fase 4 — Auditoría:**
- Tabla `pacs_study_delete_log` con snapshot de impacto, usuario y refs limpiadas.

**Pendiente (Fase 3):** flujo «estudio reemplazado» para migrar informe/audios cuando reenvían con nuevo UID.

#### Archivos nuevos
- `api/pacs-manager/delete-impact.php`
- `api/pacs-manager/lib/StudyDeleteImpact.php`
- `api/pacs-manager/lib/PacsStudyDeleteLog.php`

#### Archivos modificados
- `api/pacs-manager/delete.php` — auditoría + limpieza refs PACS
- `assets/js/pacs-manager.js` — modal con impacto
- `pacs-manager.html`
- `version.json`, `assets/js/app-version.js` — build `202607090045`

---

## [v1.3.17] — 08/07/2026

### Nuevo módulo: QA / Control de Calidad del portal

Control de calidad para autorizar o bloquear la visualización de **imágenes** e **informes** en el portal del paciente (`paciente.html` v1/v2).

**Características:**
- Modo configurable: **lista negra** (publicar todo salvo bloqueados), **lista blanca** (solo aprobados) u **híbrido** (auto-validación + revisión manual).
- Módulo instalable en `modules/qa-publicacion/` con permisos dedicados en Gestión de usuarios.
- UI `qa-manager.html`: cola de revisión, configuración y registro de auditoría.
- **Acción rápida** en `estudios-manager` e `informes-manager` (permiso `qa_despublicar_rapido`) para despublicar del portal sin entrar al módulo.
- **Bajar del PACS** sincronizado con el botón amarillo «Eliminar de PACS» vía `InformePacsRemover.php`.
- Detección de posible **mezcla de series de distintos pacientes** en un estudio.
- Mensaje al paciente cuando el estudio está pendiente de publicación.

**Instalación:** ejecutar `modules/qa-publicacion/install.php` y `install-permissions.php`. Por defecto `qa_enabled=0` (sin impacto en portal hasta activarlo).

#### Archivos nuevos
- `modules/qa-publicacion/*` — módulo completo
- `api/informes/InformePacsRemover.php` — lógica compartida de eliminación PACS

#### Archivos modificados
- `api/portal-estudios/v2/search.php`, `api/get_patient_studies.php` — filtro QA
- `api/informes/remove-from-pacs.php` — refactor + log QA
- `assets/js/orthanc-integration.js` — mensaje pendiente
- `assets/js/estudios-manager.js`, `assets/js/informes-manager.js` — acción rápida
- `dashboard-unified.html`, `assets/js/sidebar-gui-manager.js` — menú QA

---

## [v1.3.16] — 07/07/2026

### Fix: error al eliminar estudios grandes en PACS Manager

Al eliminar estudios con muchas instancias (p. ej. MR con ~1100 imágenes), la UI mostraba error **«signal is aborted without reason»** a los 60 s aunque Orthanc remoto sí completaba el borrado.

**Causa:** el frontend abortaba la petición a los 60 s (`AbortController`) mientras `delete.php` y Orthanc seguían procesando (eliminación síncrona en el PACS remoto, ~1 min o más). Nginx registraba HTTP 499 (cliente cerró la conexión).

**Fix:**
- Timeout de eliminación en frontend: 60 s → **10 minutos** (alineado con edición de estudios).
- Tras timeout del cliente, **verificación** si el estudio ya no existe en Orthanc → marcar trabajo como éxito en lugar de error.
- `delete.php`: `max_execution_time` 120 s → **600 s**.
- Mensaje de error del trabajo más claro (no expone el texto crudo de abort).

**Nota:** la eliminación desde PACS Manager sigue siendo solo borrado en Orthanc; no reconcilia informes ni audios en BD (ver análisis pendiente de flujo post-eliminación).

#### Archivos modificados
- `assets/js/pacs-manager.js` — timeout extendido, `verifyStudyDeleted()`
- `api/pacs-manager/delete.php` — timeout PHP
- `pacs-manager.html` — cache bust
- `version.json`, `assets/js/app-version.js` — build `202607072032`

---

## [v1.3.15] — 03/07/2026

### Fix: alert nativo del navegador al cargar siguiente estudio en cola

Tras finalizar informe y pulsar **Siguiente estudio**, aparecía el diálogo nativo «Los cambios pueden no guardarse».

**Causa:** `navigateToStudy` vaciaba `reportFinishedByPanel` antes de `location.href`, y el handler `beforeunload` volvía a detectar informe sin finalizar.

**Fix:**
- `_allowNavigation = true` antes de navegar en cola (suprime el alert nativo; limpieza silenciosa en `beforeunload`).
- No resetear `reportFinishedByPanel` antes del unload.
- Si el informe ya está finalizado, omitir el modal de datos sin guardar al avanzar en cola.

#### Archivos modificados
- `components/workspace.html`
- `version.json`, `assets/js/app-version.js` — build `202607031201`

---

## [v1.3.14] — 03/07/2026

### Fix: botón «Siguiente estudio» bloqueado por error en check.php

Al avanzar en la cola de workspace, el toast decía «no tengas permisos» pero la causa era técnica:

- **`api/studies/check.php`**: consulta con UUID de Orthanc contra columna `id` (INT) y columna inexistente `created_at` → HTTP 500.
- **`validateBeforeStudyChange`**: validaba el estudio de la URL actual, no el siguiente de la cola.
- **`loadNextStudyFromQueue`**: ahora pasa el ítem destino; si solo falta en BD local (estudios del dashboard/Orthanc), permite avanzar.

#### Archivos modificados
- `api/studies/check.php`
- `components/workspace.html`
- `version.json`, `assets/js/app-version.js` — build `202607031147`

---

## [v1.3.13] — 03/07/2026

### PACS Nodes Manager — Cross Sync: detección de estudios editados en PACS local

Cross Sync cruza los resultados de comparación con `pacs_study_modify_log` (auditoría de PACS Manager) para identificar pares viejo/nuevo cuando un estudio fue modificado localmente (nuevo `StudyInstanceUID` en PACS local, UID original aún presente en nodos DIMSE remotos).

- Resumen distinto de «ausente en algún nodo» cuando se detecta edición local (`editado en PACS local`, `copia local post-edición`, etc.).
- Badge e hint con datos actuales en PACS local (ej. corrección LOCCO → FLOCCO) y UID vinculado.
- Modal de información ampliado con detalle de auditoría (campos modificados, registro #).
- Consulta read-only; no altera C-FIND ni el flujo de edición en PACS Manager.

#### Archivos nuevos
- `modules/pacs-nodes-manager/api/modify-log-lookup.php`

#### Archivos modificados
- `modules/pacs-nodes-manager/assets/js/pacs-nodes-manager.js` — enriquecimiento post-merge y UI
- `modules/pacs-nodes-manager/assets/css/pacs-nodes-manager.css` — estilos filas/badges edición local
- `api/pacs-manager/lib/PacsStudyModifyLog.php` — método `findByStudyUids()`
- `pacs-nodes-manager.html` — cache bust `?v=202607031104`
- `version.json`, `assets/js/app-version.js` — versión `1.3.13`, build `202607031104`

---

## [v1.3.12] — 26/06/2026

### Permiso de usuario: auto-avance en cola de WorkSpace

El auto-avance al siguiente estudio (Shift+clic en **Siguiente estudio** y carga automática 2 s tras finalizar informe) queda controlado por permiso asignable en **Gestión de Usuarios**.

**Permiso:** `workspace_cola_auto_avance` — *Cola WorkSpace — Auto-avance* (categoría interfaz).

- Sin permiso: la cola y el botón **Siguiente estudio** siguen funcionando; solo se bloquea el toggle y el auto-avance.
- Con permiso `all` o nivel `root`: habilitado como el resto de features.
- Al denegar el permiso, se desactiva `workspace_auto_advance_queue` en `localStorage` del navegador.

#### Archivos nuevos
- `database/agregar_permiso_workspace_cola_auto_avance.sql`

#### Archivos modificados
- `api/users/permissions-simple.php` — registro del permiso (fallback)
- `components/workspace.html` — `checkQueueAutoAdvancePermission()`, validación en UI
- `version.json`, `assets/js/app-version.js` — versión `1.3.12`, build `202606262000`

---

## [v1.3.11] — 26/06/2026

### Cola automática de estudios: dashboard-unified → workspace

Flujo de lectura en lote sin volver al dashboard entre estudios. El usuario filtra y ordena la tabla en **dashboard-unified**, abre un estudio en workspace y puede avanzar al siguiente desde el propio workspace.

**Cola (`workspace_study_queue` en localStorage):**
- Se construye al abrir un estudio desde el menú contextual (snapshot del orden y filtros actuales).
- Incluye todos los estudios filtrados visibles (no solo la página actual).
- Excluye urgente, promesa y pendiente; excluye estudios con informe completo (incluye incompletos para re-informar).
- Orden igual al de la tabla, sin promover el estudio abierto (“WS on Top” ignorado para la cola).

**Workspace:**
- Botón **Siguiente estudio** en el header del panel DICOM (contador `N/Total`).
- Deshabilitado hasta finalizar el informe; luego carga el siguiente con las validaciones existentes (audios sin guardar, grabación activa, móvil pendiente).
- **Shift+clic** en el botón: activa/desactiva auto-avance opt-in (2 s tras finalizar).

**Dashboard:**
- Badge **Cola N/Total** en la fila del estudio activo.

**Multimonitor:**
- Sincronización de índice de cola al enviar estudios al popup (`loadStudyInPopup`, `mm-load-study`).

#### Archivos nuevos
- `assets/js/workspace-study-queue.js` — módulo compartido `StudyQueue`

#### Archivos modificados
- `assets/js/dashboard-with-permissions.js` — `sortStudiesForQueue`, `buildStudyQueue`, badge en grilla
- `components/workspace.html` — UI y lógica de avance en cola
- `modules/multimonitor/assets/js/multimonitor-manager.js` — sync de cola en popup
- `dashboard-unified.html` — carga del módulo de cola
- `version.json`, `assets/js/app-version.js` — versión `1.3.11`, build `202606261800`

---

## [v1.3.10] — 26/06/2026

### Fix: `ReferenceError: IS_DETACHED is not defined` al abrir estudio en workspace

Al guardar `workspace_study_data_mm` en `readStudyParams()`, se usaba la variable `IS_DETACHED` definida en otro bloque `<script>` (IIFE del módulo multimonitor), fuera del alcance de `WorkspaceManager`. Eso rompía la carga del estudio en workspace normal (iframe) con error en consola.

**Fix:** en `readStudyParams()` se detecta el modo detached localmente con `new URLSearchParams(location.search).get('mm_mode') === 'detached'` antes de escribir en `localStorage['workspace_study_data_mm']`.

#### Archivos modificados
- `components/workspace.html` — detección local de popup detached en `readStudyParams()`
- `version.json`, `assets/js/app-version.js` — versión `1.3.10`, build `202606261600`
- `dashboard-unified.html`, `app-container.html` — cache bust de `app-version.js`

---

## [v1.3.9] — 25/06/2026

### Sistema de versión, cache busting y versión visible en sidebar

Centralización de la versión de la aplicación para que los usuarios reciban actualizaciones sin depender de limpiar caché del navegador.

**Nuevos archivos:**
- `version.json` — fuente de verdad (`version`, `build`, `released`)
- `assets/js/app-version.js` — expone `APP_VERSION`, `appendAppVersion()` y `assetUrl()` en runtime

**Cache busting (`?v={build}`):**
- Assets estáticos propios (`script`, `link`) en páginas principales con sidebar
- URL del iframe del workspace en `app-container.html` incluye `v=` en cada carga

**Versión en sidebar:**
- `sidebar-gui-manager.js` lee `version.json` e inserta `v1.3.9` en el footer del sidebar (encima de Cerrar Sesión)
- Estilos en `styles.css` para `.sidebar-version`

**Deploy:** actualizar `version.json`, `app-version.js` y el `build` en los HTML, más entrada en este changelog.

**Pendiente (Fase 5):** headers `Cache-Control` en Nginx/Apache.

#### Archivos modificados
- `version.json` (nuevo)
- `assets/js/app-version.js` (nuevo)
- `assets/js/sidebar-gui-manager.js`
- `styles.css`
- `app-container.html`
- `components/workspace.html`
- `dashboard-unified.html`
- `estudios-manager.html` y demás páginas con sidebar

---

## [v1.3.8] — 24/06/2026

### Fix: badge "En Workspace" no se actualizaba en dashboard cuando el estudio se abría en el popup multimonitor

El `sessionStorage` es local a cada ventana, por lo que el popup workspace escribía `workspace_study_data` en su propio contexto y el dashboard nunca lo detectaba.

**Causa:** `setupWorkspaceStateListener()` en `dashboard-with-permissions.js` sólo escuchaba el `sessionStorage` local y mensajes `postMessage`/custom events, ninguno de los cuales cruza ventanas no relacionadas.

**Fix:**
- `workspace.html`: cuando `IS_DETACHED || window.opener` (modo popup), también escribe el dato en `localStorage['workspace_study_data_mm']`. Al cerrar el popup (`beforeunload` real), lo elimina.
- `dashboard-with-permissions.js`:
  - `isStudyOpenInWorkspace()` ahora revisa tanto `sessionStorage['workspace_study_data']` como `localStorage['workspace_study_data_mm']`.
  - `setupWorkspaceStateListener()` agrega `window.addEventListener('storage', ...)` para detectar cambios de `workspace_study_data_mm` desde el popup (el evento `storage` sólo se dispara en ventanas *distintas* de la que escribió).
  - El intervalo de fallback (2 s) y la inicialización también revisan `localStorage['workspace_study_data_mm']`.

#### Archivos modificados
- `components/workspace.html` — escribe/limpia `localStorage['workspace_study_data_mm']`
- `assets/js/dashboard-with-permissions.js` — listener `storage`, `isStudyOpenInWorkspace`, intervalo e inicialización

---

## [v1.3.7] — 24/06/2026

### Fix: sidebar en popup, workspace normal al cambiar estudio, restore al cerrar popup

#### Tres bugs corregidos

**Bug 1 — Popup mostraba sidebar al cargar nuevo estudio**
Al navegar a un nuevo estudio vía `mm-load-study`, la URL enviada no tenía `?mm_mode=detached`. Workspace.html ahora agrega `&mm_mode=detached` a la URL si el popup fue abierto en ese modo (`IS_DETACHED = true`).

**Bug 2 — Workspace normal mostraba estudio viejo al navegar el popup**
Cuando el popup navega a un nuevo estudio, su `beforeunload` disparaba y enviaba `mm-workspace-closed`. El workspace normal lo recibía, quitaba el placeholder y mostraba el estudio viejo. Fix: se agrega `sessionStorage['mm_navigating_study']` como flag ANTES de `window.location.href`. El `beforeunload` verifica este flag; si está seteado, omite el envío de `mm-workspace-closed`.

**Bug 3 — Cerrar popup no restauraba el estudio en workspace normal**
Al cerrar el popup, workspace normal quitaba el placeholder pero no sabía qué estudio mostrar. Fix: el popup envía su URL actual junto con `mm-workspace-closed` (campo `restoreUrl`, sin `mm_mode=detached`). Workspace normal lo recibe y navega a esa URL, mostrando el estudio que tenía el popup.

#### Archivos modificados
- `components/workspace.html` — `beforeunload` (flag + restoreUrl), `mm-load-study` (mm_mode + flag), `mm-workspace-closed` (restoreUrl), `postMessage` handler unificado
- `modules/multimonitor/assets/js/multimonitor-manager.js` — `loadStudyInPopup` usa `postMessage` en vez de `location.href` directo

---

## [v1.3.6] — 24/06/2026

### Fix: estudios nuevos van al popup detached; flujo normal cuando no hay popup activo

#### Comportamiento correcto implementado
1. "Abrir en WorkSpace" desde el dashboard → **funciona igual que sin multimonitor** (sin cambio al flujo existente)
2. Dentro de workspace, usuario elige "Mover a otro monitor" → workspace se convierte en popup detached
3. Al volver al dashboard y abrir otro estudio → **va al popup detached** (no abre workspace nuevo)
4. Si no hay popup activo → flujo normal

#### Problema que se corregía (v1.3.6 anterior era incorrecto)
La versión anterior abría un popup automáticamente al primer clic en "Abrir en WorkSpace", lo cual no era lo deseado.

#### Solución correcta
**`loadStudyInPopup()` — solo redirige, nunca abre popups nuevos:**
- Caso A: tiene referencia directa al popup → navega `_workspaceWin.location.href`
- Caso B: estado en localStorage sin referencia (dashboard recargó) → BroadcastChannel `mm-load-study`
- Si no hay popup: retorna `false` → `openInWorkspace()` usa su flujo normal

**`workspace.html` — guarda estado directamente al detacharse:**
- `openDetachedOnScreen()`: guarda `mm_workspace_state` y `mm_workspace_screen` en `localStorage` directamente (no depende del dashboard para hacer esto, porque en el flujo normal el dashboard ya navegó a app-container y no puede recibir `mm-move-request`)
- `moveWsTo()`: actualiza `mm_workspace_screen` en localStorage al moverse
- `beforeunload`: limpia `mm_workspace_state` y `mm_workspace_screen` cuando el popup cierra

Esto garantiza que aunque el dashboard esté en `app-container.html` (sin `multimonitor-manager.js`), el estado queda registrado. Cuando el usuario vuelve a `dashboard-unified.html`, `multimonitor-manager.js` lo detecta y redirige los próximos estudios al popup.

#### Archivos modificados
- `modules/multimonitor/assets/js/multimonitor-manager.js` — `loadStudyInPopup()` simplificado
- `components/workspace.html` — `openDetachedOnScreen`, `moveWsTo`, `beforeunload` guardan en localStorage
- `assets/js/dashboard-with-permissions.js` — check multimonitor en `openInWorkspace()`

---

## [v1.3.5] — 24/06/2026

### Fix: estudios abiertos desde páginas secundarias ahora abren en el monitor correcto (monitor 2)

#### Problema raíz identificado
`window.open(url, '_blank')` sin coordenadas de posición no abre en el monitor del popup workspace. El navegador decide dónde abrir la nueva ventana (usualmente el monitor principal). El enfoque anterior (BroadcastChannel → workspace llama `window.open(_blank)`) tenía el mismo problema: la nueva ventana abría en el monitor equivocado.

Adicionalmente, `estudios-manager.html` es una página completamente separada de `dashboard-unified.html` y no tenía ningún interceptor de `window.open`.

#### Solución: coordenadas explícitas del monitor
El enfoque correcto es usar **coordenadas explícitas** en `window.open(url, '_blank', features)` especificando `left`, `top`, `width`, `height` del monitor destino. Esto fuerza al navegador a posicionar la ventana en el monitor correcto (requiere permiso `window-management` para abrir cross-screen, pero funciona en el mismo monitor sin permiso adicional).

**Cambios aplicados:**

1. **Interceptor en `multimonitor-manager.js`** (`_startInterceptingOpens`):
   - Ahora usa `_workspaceWin.screenX/Y` y `outerWidth/Height` para obtener las coordenadas actuales del popup workspace
   - Nueva función `_workspaceFeatures()` que construye el features string con esas coordenadas
   - El interceptor abre directamente el estudio en el monitor de workspace usando `_origWindowOpen(url, '_blank', feat)`
   - BroadcastChannel sigue como fallback (si se pierde la referencia al popup)

2. **Coordenadas guardadas en `localStorage['mm_workspace_screen']`**:
   - Nueva función `_saveWorkspaceScreen(left, top, width, height)`
   - Se llama en `_openWorkspace`, `_openWorkspaceOnCurrentMonitor`, `_moveWorkspaceTo` y al procesar `mm-move-request`
   - Se limpia al cerrar workspace (`_onWorkspaceClosed`)

3. **Handler `mm-open-url` en `workspace.html`**:
   - Usa `window.screenX/Y` y `outerWidth/Height` de workspace para abrir el estudio con coordenadas explícitas
   - Garantiza que el nuevo estudio se posicione en el mismo monitor que workspace

4. **`assets/js/mm-study-redirect.js`** *(nuevo — para `estudios-manager.html` y otras páginas)*:
   - Lee `localStorage['mm_workspace_state']` para saber si hay workspace activo
   - Lee `localStorage['mm_workspace_screen']` para las coordenadas del monitor destino
   - Abre con coordenadas explícitas (estrategia 1), cae a BroadcastChannel (estrategia 2)
   - Cargado en `estudios-manager.html` antes de `estudios-manager.js`

#### Archivos modificados/creados
- `modules/multimonitor/assets/js/multimonitor-manager.js` — interceptor con coordenadas explícitas + `_saveWorkspaceScreen`
- `components/workspace.html` — `mm-open-url` con coordenadas explícitas
- `assets/js/mm-study-redirect.js` *(nuevo)* — interceptor liviano para páginas secundarias
- `estudios-manager.html` — carga `mm-study-redirect.js`

---

## [v1.3.4] — 24/06/2026

### Cambio arquitectural: WorkSpace abre como popup (solo usuarios con permiso multimonitor)

#### Problema resuelto
Al mover workspace a otro monitor se abría una nueva instancia, recargando el estudio y perdiendo el estado del visor DICOM, audios y configuración de paneles.

#### Solución
Cuando el módulo multimonitor está activo (usuario con permiso `feature_multimonitor`), el enlace **"WorkSpace"** del sidebar ya no navega a `app-container.html` sino que abre workspace como una **ventana popup** en el monitor actual. Al ser popup desde el inicio, `window.moveTo()` puede trasladarlo a cualquier monitor **sin recarga** — el estudio, el visor y los audios permanecen intactos.

#### Comportamiento por tipo de usuario

| Tipo de usuario | Clic en "WorkSpace" |
|-----------------|---------------------|
| Sin permiso `feature_multimonitor` | Navega a `app-container.html` (comportamiento clásico, sin cambios) |
| Con permiso `feature_multimonitor` | Abre `workspace.html` como popup en el monitor actual |

#### Flujo multimonitor actualizado
1. Usuario clic en "WorkSpace" → popup se abre en el monitor actual (completo, con sidebar)
2. Carga el estudio normalmente
3. Clic en ícono 🖥️ → picker de monitores → `window.moveTo()` → workspace se mueve sin recargar
4. Estudio, visor DICOM, grabaciones de audio y paneles permanecen intactos

#### Archivos modificados
- `modules/multimonitor/assets/js/multimonitor-manager.js`:
  - Nueva función `_openWorkspaceOnCurrentMonitor()` — abre popup en el monitor del dashboard
  - Nueva función `_interceptWorkspaceLink()` — intercepta clic en el enlace sidebar para abrir como popup
  - Popup abre con `WORKSPACE_URL` (sin `mm_mode=detached`) para mantener la UI completa
  - Picker "Este monitor" también usa popup en vez de navegar
  - Registrado en `ready()` junto con `_injectTriggerButton()`

---

## [v1.3.3] — 24/06/2026

### Mejoras al módulo Multi-Monitor WorkSpace

#### Workspace sin sidebar en monitor secundario
- La ventana de workspace que se abre en el monitor secundario carga ahora con `?mm_mode=detached`, ocultando automáticamente el sidebar y todos los botones de navegación lateral. El viewer ocupa la pantalla completa.
- La detección del parámetro ocurre antes del `DOMContentLoaded` para evitar el flash del sidebar.

#### Placeholder en workspace original
- Al mover workspace a otro monitor desde dentro del propio workspace (cuando no es popup), el workspace original muestra un placeholder de pantalla completa con:
  - Indicador de en qué monitor está el workspace
  - Botón "Ir a WorkSpace" (focus al popup)
  - Botón "Restaurar aquí" (cierra el popup y vuelve al workspace original)
  - Auto-dismiss si el popup se cierra

#### Estudios se abren en el monitor donde está workspace
- Mientras workspace está detached en el monitor secundario, cualquier acción de "Ver estudio" desde el dashboard (que internamente usa `window.open(..., '_blank')`) redirige automáticamente el visor al popup de workspace en el monitor secundario.
- El interceptor se activa al abrir workspace y se desactiva automáticamente al cerrarlo.
- Comunicación: dashboard → `postMessage('mm-open-url')` → workspace popup → `window.open(_blank)` en contexto del monitor 2.

#### Fallback mejorado para browsers con detección limitada (Brave)
- El selector de monitores ahora muestra opciones numeradas horizontales (`Monitor 2 →`, `Monitor 3 →`...) en lugar de solo cuatro direcciones, cubriendo setups de 6+ monitores.
- Mensajes contextuales diferenciados: Brave/privacidad vs. sin soporte de API.

---

## [v1.3.2] — 23/06/2026

### Módulo Multi-Monitor WorkSpace (`modules/multimonitor/`)

Nueva funcionalidad opcional instalable como módulo independiente que permite abrir WorkSpace en un monitor secundario mientras el dashboard permanece activo en el monitor principal.

#### Estructura del módulo

- **`modules/multimonitor/module.json`** — descriptor del módulo (versión, dependencias, notas)
- **`modules/multimonitor/install.php`** — verifica compatibilidad del entorno (idempotente)
- **`modules/multimonitor/install-permissions.php`** — registra el permiso `feature_multimonitor` en `system_permissions` bajo la categoría `interfaz`
- **`modules/multimonitor/uninstall.php`** — elimina el permiso y sus asignaciones a usuarios (rollback limpio, sin afectar tablas de datos)
- **`modules/multimonitor/assets/js/multimonitor-manager.js`** — lógica completa del feature

#### Comportamiento

- Al instalar y asignar el permiso en Gestión de Usuarios, el usuario verá un ícono 🖥️ en el sidebar header del dashboard.
- Si el equipo tiene más de un monitor conectado (`screen.isExtended === true`), al hacer clic se muestra un modal selector de monitores con nombre y resolución de cada pantalla.
- Al elegir un monitor secundario, WorkSpace se abre en una ventana nueva posicionada en ese monitor; el enlace a WorkSpace desaparece del sidebar del dashboard.
- Al cerrar la ventana de WorkSpace, el enlace al sidebar se restaura automáticamente.
- Si el equipo tiene un solo monitor, el clic abre WorkSpace en la misma pestaña (comportamiento clásico sin cambios).
- Sin el permiso asignado, no se carga ningún JS adicional y el sistema funciona exactamente igual que antes.

#### Comunicación entre ventanas

- `BroadcastChannel('mm-workspace-state')` para notificar cierre de workspace al dashboard.
- `localStorage('mm_workspace_state')` para persistir el estado entre recargas del dashboard.
- Polling de 1,5 s como fallback para detectar el cierre de la ventana workspace.

#### Pasos de instalación

1. Navegar a `/modules/multimonitor/install.php`
2. Navegar a `/modules/multimonitor/install-permissions.php`
3. Ir a Gestión de Usuarios → asignar `feature_multimonitor` a las cuentas deseadas

#### Archivos modificados del núcleo

- `dashboard-unified.html` — carga condicional de `multimonitor-manager.js` tras verificar permiso `feature_multimonitor`
- `components/workspace.html` — agrega emisor `BroadcastChannel` en `beforeunload` para notificar cierre al dashboard
- `assets/js/sidebar-gui-manager.js` — nuevo método `setMultimonitorDetached(bool)` para ocultar/restaurar el ítem WorkSpace del sidebar

---

## [v1.3.1] — 23/06/2026

### Mejoras en la barra compacta de grabadora (panel DICOM con audio oculto)

Cuando el panel de grabadora de audio está oculto y sus controles se muestran compactamente en la barra de título del panel DICOM, ahora se incluyen dos botones adicionales:

- **Finalizar Informe** (`✓`): botón verde compacto que permite finalizar y guardar el informe directamente desde la barra del DICOM, sin necesidad de volver a mostrar el panel de grabadora. Se habilita/deshabilita automáticamente según el estado de las grabaciones (igual que el botón principal del panel de grabadora).
- **Mostrar Grabadora** (`🎤`): botón azul compacto para restaurar el panel de grabadora a su posición original con un solo clic.

**Archivos modificados:**
- `components/workspace.html`
  - `insertDicomCompactAudioToolbar`: agrega los dos nuevos botones al toolbar compacto.
  - `updateFinishReportButton`: sincroniza el estado del botón compacto `finishReportButton-compact-{panelId}`.
  - `syncDicomCompactAudioToolbar`: llama a `updateFinishReportButton` al crear el toolbar para reflejar el estado inicial correcto.
  - Estilos CSS para `.btn-finish-report-compact` y `.btn-show-audio-compact`.

---

## [v1.3] — 23/06/2026

### Visor de imágenes mejorado en antecedentes médicos (galería, navegación y zoom)

Se mejoró el visor de imágenes del modal de **Antecedentes Médicos** en el workspace para ofrecer una experiencia más profesional similar a un visualizador de imágenes de escritorio.

#### Galería con navegación entre imágenes

- Las imágenes del conjunto de antecedentes se registran en una galería al mostrar las cards.
- Botones **◀ Anterior** y **▶ Siguiente** aparecen superpuestos sobre la imagen cuando hay más de una en la galería.
- Contador de posición en el encabezado del visor: `"1 / 3"`.
- Navegación también disponible por teclado: `←` `→` para cambiar imagen, `Esc` para cerrar.

#### Zoom sobre la imagen

- **Rueda del mouse**: zoom in/out centrado en el área del cursor.
- **Click derecho** sobre la imagen: zoom in (el menú contextual del navegador queda suprimido).
- **Doble clic**: alterna entre 1× y 2×; si ya está ampliado, resetea a 1×.
- **Botones** `−` `100%` `+` en el footer del visor: ajuste manual de zoom y reset con clic en el porcentaje.
- Indicador flotante de porcentaje de zoom que desaparece automáticamente.
- Rango de zoom: 50% – 800%.
- Teclas `+` / `-` / `0` para controlar el zoom con teclado.

#### Pan (arrastrar imagen ampliada)

- Cuando el zoom supera 1×, se puede arrastrar la imagen con el botón izquierdo del mouse para desplazarse por ella.
- El cursor cambia a `grab` / `grabbing` según el estado.

**Archivos modificados:**
- `assets/js/FileViewer.js`: refactorización completa del lightbox con galería estática (`_gallery`), métodos `clearGallery()`, `navigateLightbox()`, `adjustZoom()`, `resetZoom()`, `_applyZoom()` y eventos de zoom/pan.
- `components/workspace.html` → `displayExistingFiles`: agrega llamada a `FileViewer.clearGallery()` antes de generar las cards para limpiar la galería entre usos.
