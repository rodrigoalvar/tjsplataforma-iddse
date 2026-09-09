/**
 * Textos de ayuda contextual — PACS Cloner (español).
 * Cargar antes de pacs-nodes-manager.js
 */
const PACS_CLONER_HELP_ES = {
    intro_pacs_cloner: {
        title: 'PACS Cloner — vista general',
        html: '<p>El <strong>PACS Cloner</strong> encola recuperaciones DICOM (C-MOVE) desde nodos remotos hacia Orthanc local, usando el mismo flujo que la pestaña <strong>Jobs</strong>.</p><ul><li><strong>Políticas</strong>: reglas guardadas (ventana, modalidad, orden de descubrimiento, concurrencia). El <em>worker</em> (script PHP en cron) solo actúa sobre políticas <strong>activas</strong>, modo <code>automatic</code> y nodo remoto válido.</li><li><strong>Órdenes</strong>: cada encolado genera una orden y un job; el estado se alinea con Orthanc.</li><li><strong>Cross Sync / Jobs</strong>: atajos en el bloque superior.</li></ul><p class="text-muted mb-0">Aplique las migraciones SQL indicadas en la cinta informativa antes de usar columnas nuevas.</p>'
    },
    sector_workers_cli: {
        title: 'Workers CLI (v1 / v2)',
        html: '<p><strong>Worker v1</strong> (<code>cloner-worker.php</code>): script pensado para cron/systemd; descubre estudios por política, crea órdenes y despacha C-MOVE.</p><p><strong>Worker v2</strong> (<code>cloner-worker-v2.php</code>): motor alternativo, independiente; requiere entrada propia en cron y flag activo. No reemplaza al v1 salvo que reorganice el cron.</p><p class="text-muted mb-0">Si desactiva v1, el cron que llama a v1 no hará nada hasta volver a habilitarlo.</p>'
    },
    worker_v1: {
        title: 'Worker v1 habilitado',
        html: '<p>Si está desmarcado, el proceso <code>cloner-worker.php</code> termina al inicio sin tocar políticas ni órdenes (útil para mantenimiento o pruebas).</p><p class="text-muted mb-0">El cron puede seguir ejecutándose; simplemente no habrá actividad del cloner automático.</p>'
    },
    worker_v2: {
        title: 'Worker v2 habilitado',
        html: '<p>Habilita el flag en base de datos para el script v2. Solo tiene efecto si el servidor ejecuta <code>cloner-worker-v2.php</code> en cron.</p><p class="text-muted mb-0">Por defecto v2 está desactivado; la mayoría de instalaciones usan solo v1.</p>'
    },
    sector_wr_runtime: {
        title: 'Parámetros del worker en base de datos',
        html: '<p>Estos valores se guardan en la tabla <code>configuracion</code>. Si en el sistema hay variables de entorno <code>PACS_CLONER_*</code> equivalentes, <strong>las variables de entorno tienen prioridad</strong> y el texto gris bajo el título lo advertirá.</p><p>Use los botones <strong>?</strong> junto a cada campo para el detalle de límites y efectos.</p><p class="mb-0">En la pestaña <strong>Jobs</strong>, la columna <strong>Origen</strong> distingue jobs del worker (verde), dispatch desde esta UI (azul) y retrieve directo / Cross Sync (gris).</p><hr class="my-2"><p class="small fw-bold mb-1">Diagnóstico rápido de estudios que no sincronizan</p><ul class="small mb-0"><li><strong>active_job alto</strong> en las notas de corrida: hay jobs <code>pending/running</code> viejos bloqueando. Baje los timeouts stale (pending/running) para liberarlos más rápido.</li><li><strong>defer alto</strong>: el C-FIND no devolvió conteo de instancias o el remoto reporta igual que el local. Revise conectividad DIMSE y el log PHP (<code>[cloner-worker] remoteInst=0</code>).</li><li><strong>stable alto</strong>: estudios ya alineados según conteo. Si Cross Sync muestra conteos distintos, puede ser que el nodo reporte números incorrectos.</li><li><strong>Jobs fallan constantemente</strong>: revise <code>error_message</code> en la pestaña Jobs — debe aparecer el mensaje real de Orthanc. Causas frecuentes: AET/host incorrecto en la modalidad Orthanc, PACS remoto rechaza el MOVE, timeout de red, reinicio de Orthanc.</li></ul>'
    },
    wr_max_per_run: {
        title: 'Máx. estudios / corrida (total)',
        html: '<p>Tope de <strong>nuevas órdenes</strong> que el worker puede crear en una sola ejecución, sumando todas las políticas automáticas.</p><ul><li><strong>Bajo</strong>: menos carga por corrida; la cola crece más lento.</li><li><strong>Alto</strong>: más trabajo por pasada; más presión sobre Orthanc y el PACS remoto.</li></ul><p class="text-muted mb-0">Clave BD: <code>pacs_cloner_max_per_run</code>. Env: <code>PACS_CLONER_MAX_PER_RUN</code>.</p>'
    },
    wr_max_per_policy: {
        title: 'Máx. / política',
        html: '<p>Límite de nuevas órdenes por <strong>cada política</strong> en la misma corrida.</p><p>Evita que una sola política consuma toda la cuota global. Si es mayor que el máximo total, el total sigue mandando.</p><p class="text-muted mb-0">Clave: <code>pacs_cloner_max_per_policy</code>. Env: <code>PACS_CLONER_MAX_PER_POLICY</code>.</p>'
    },
    wr_poll_sec: {
        title: 'Poll cupo (segundos)',
        html: '<p>Segundos de espera entre intentos cuando hay órdenes pendientes pero no hay cupo de concurrencia (<code>max_concurrent</code> de la política) o hay que esperar a que termine un job.</p><ul><li><strong>Bajo</strong>: reacciona antes; más iteraciones por corrida.</li><li><strong>Alto</strong>: menos consultas; puede tardar más en arrancar el siguiente C-MOVE.</li></ul><p class="text-muted mb-0">Env: <code>PACS_CLONER_DISPATCH_POLL_SEC</code>.</p>'
    },
    wr_dispatch_max_sec: {
        title: 'Espera máx. cupo (segundos)',
        html: '<p>Tiempo máximo que el worker puede seguir en bucle esperando cupo y despachando dentro de <strong>esa corrida</strong> y política.</p><p><strong>0</strong>: solo una ronda de dispatch y continúa (útil con cron muy frecuente). Valor alto: una ejecución del script puede durar mucho; evite solaparse con otra instancia del mismo worker si el cron es corto.</p><p class="text-muted mb-0">Env: <code>PACS_CLONER_DISPATCH_MAX_SEC</code>.</p>'
    },
    wr_max_starts: {
        title: 'Máx. C-MOVE / corrida / política',
        html: '<p>Cuántos C-MOVE nuevos puede iniciar el worker por política en una sola corrida.</p><p>Limita picos aunque haya muchas órdenes <code>pending</code>. El paralelismo real también lo frena <code>max_concurrent</code> de cada política.</p><p class="text-muted mb-0">Env: <code>PACS_CLONER_DISPATCH_MAX_STARTS</code>.</p>'
    },
    wr_reconcile_limit: {
        title: 'Reconciliar jobs (máx.)',
        html: '<p>Cuántos registros <code>pacs_node_jobs</code> en estado pendiente/en ejecución se consultan contra Orthanc al <strong>inicio</strong> de cada corrida para corregir progreso y cierres.</p><p class="text-muted mb-0">Env: <code>PACS_CLONER_RECONCILE_JOBS_LIMIT</code>.</p>'
    },
    wr_stale_jobs: {
        title: 'Timeouts pending / running (liberar bloqueo)',
        html: '<p>Al <strong>inicio de cada corrida</strong> del worker, los jobs en <code>pending</code> más viejos que el umbral en minutos, o en <code>running</code> sin cierre más viejos que el umbral en horas, se marcan <strong>Fallido</strong> con un mensaje de timeout administrativo.</p><p>Eso libera el bloqueo que impide encolar de nuevo el mismo estudio (<code>cloner_worker_has_active_job</code>) cuando Orthanc o PHP dejaron un job colgado.</p><p class="text-muted mb-0">Claves: <code>pacs_cloner_stale_pending_minutes</code>, <code>pacs_cloner_stale_running_hours</code>. Env: <code>PACS_CLONER_STALE_PENDING_MINUTES</code>, <code>PACS_CLONER_STALE_RUNNING_HOURS</code>. Suba las horas si los estudios muy grandes suelen tardar más que el umbral en completar.</p>'
    },
    wr_modality_global: {
        title: 'Prioridad modalidad global (CSV)',
        html: '<p>Lista como <code>CR,DX,CT,MR</code> en orden de prioridad. Se usa cuando la <strong>política no define</strong> su propia prioridad de modalidad y el orden de descubrimiento es por modalidad.</p><p class="text-muted mb-0">Env: <code>PACS_CLONER_MODALITY_PRIORITY</code>. Dejar vacío si no la necesita.</p>'
    },
    wr_reconcile_enabled: {
        title: 'Reconciliar jobs al inicio',
        html: '<p>Si está activo, al comenzar cada corrida el worker consulta Orthanc para alinear estados de jobs locales.</p><p>Si desactiva, acorta el arranque pero la BD puede desfasarse del estado real hasta otra reconciliación (API Jobs, etc.).</p><p class="text-muted mb-0">La variable <code>PACS_CLONER_RECONCILE_JOBS=0</code> en el servidor desactiva siempre, pase lo que diga la BD.</p>'
    },
    wr_save_refresh: {
        title: 'Guardar y refrescar',
        html: '<p><strong>Guardar workers y parámetros</strong> escribe en <code>configuracion</code> los flags v1/v2 y los números de runtime.</p><p>El icono de <strong>refresco</strong> vuelve a leer el servidor (últimas corridas, órdenes worker, textos de override por entorno) sin guardar cambios no enviados.</p>'
    },
    wr_valores_orientativos: {
        title: 'Valores orientativos — análisis detallado',
        html: '<h6 class="small fw-bold">1. Cuello de botella típico</h6><p>Cada job C-MOVE consume CPU y E/S en Orthanc, ancho de banda hasta el nodo remoto y recursos en el PACS origen. Muchos jobs <strong>en ejecución a la vez</strong> aumentan la probabilidad de timeouts DIMSE, respuestas lentas o cortes de red → estado <strong>Fallido</strong> o reconciliación a estados inconsistentes.</p><h6 class="small fw-bold">2. Parámetros que bajan el paralelismo</h6><ul><li><strong>Concurrencia máx.</strong> en cada política (formulario de política): menos órdenes <code>running</code> por política.</li><li><strong>Máx. estudios / corrida</strong> y <strong>Máx. / política</strong>: menos órdenes nuevas por ejecución del cron.</li><li><strong>Máx. C-MOVE / corrida / política</strong>: menos arranques de retrieve por pasada.</li><li><strong>Orden al descubrir estudios</strong> en “más antiguo primero” ayuda a vaciar backlog sin cambiar el paralelismo, pero sigue habiendo que limitar concurrencia si hay saturación.</li></ul><h6 class="small fw-bold">3. Cross Sync y jobs “Directo”</h6><p>Cross Sync y la recuperación desde la búsqueda por nodo no crean orden en <code>pacs_cloner_orders</code>; en Jobs aparecen como <span class="badge bg-secondary">Directo</span>. Varios estudios seleccionados y “Recuperar seleccionados” disparan muchos jobs directos en paralelo: considere lotes más pequeños o espaciar manualmente.</p><h6 class="small fw-bold">4. Cron</h6><p>Cron muy frecuente con <code>dispatch_max_sec</code> alto puede alargar cada proceso PHP; evite solapar dos instancias del mismo worker si el intervalo es menor que el tiempo máximo de corrida.</p><h6 class="small fw-bold">5. Inspección</h6><p>Use la pestaña Jobs: muchos <span class="badge bg-success">Worker</span> + muchos <span class="badge bg-secondary">Directo</span> simultáneos indican carga combinada. Ajuste primero concurrencia de políticas y tamaño de lotes Cross Sync antes de subir límites globales.</p>'
    },
    wr_activity: {
        title: 'Actividad reciente del worker',
        html: '<p><strong>Resumen</strong>: cantidad de órdenes con origen <code>worker</code> en estado pendiente o en ejecución.</p><p><strong>Tabla de corridas</strong>: filas de <code>pacs_cloner_worker_runs</code> (inicio, fin, políticas tocadas, estudios descubiertos, encolados, notas resumidas).</p><p><strong>Órdenes recientes</strong>: últimas órdenes creadas por el worker con nodo, estado y etiqueta.</p><p class="text-muted mb-0">Los jobs en la pestaña Jobs con origen <span class="badge bg-success">Worker</span> corresponden a estas órdenes cuando ya tienen <code>pacs_node_job_id</code> enlazado.</p>'
    },
    sector_policy_form: {
        title: 'Formulario de política',
        html: '<p>Una <strong>política</strong> agrupa criterios para el mismo nodo origen. El worker solo usa políticas en modo <code>automatic</code>, marcadas como habilitadas y con nodo remoto activo.</p><p>Use el botón <strong>?</strong> de cada campo para detalle. Guarde con el botón inferior; <em>Cancelar edición</em> limpia el formulario al crear otra.</p>'
    },
    policy_name: {
        title: 'Nombre de la política',
        html: '<p>Etiqueta humana para reconocer la política en listados y en la columna de acciones. No afecta la lógica DICOM.</p>'
    },
    policy_node: {
        title: 'Nodo origen',
        html: '<p>Nodo PACS remoto desde el que se hará el C-FIND y el C-MOVE. Debe estar activo y no ser tipo <code>local</code> para el worker automático.</p>'
    },
    policy_dates: {
        title: 'Fecha desde / hasta',
        html: '<p>Si completa <strong>ambas</strong> fechas, el worker usa ese rango fijo de <code>StudyDate</code> en el C-FIND en lugar de la ventana en horas.</p><p>Si deja vacío, se usa la ventana (horas) configurada abajo.</p>'
    },
    policy_scan_h: {
        title: 'Ventana (horas)',
        html: '<p>Sin rango desde/hasta, el worker calcula un intervalo de fechas DICOM equivalente a las últimas <strong>N</strong> horas hacia atrás desde ahora.</p><p>Ventana grande: más estudios candidatos y C-FIND más pesado.</p>'
    },
    policy_max_conc: {
        title: 'Concurrencia máxima',
        html: '<p>Máximo de órdenes <code>running</code> a la vez <strong>para esta política</strong> (C-MOVE en paralelo hacia esa cola).</p><p>Valores altos aceleran pero multiplican carga en red, Orthanc y el remoto; compiten con recuperaciones manuales o Cross Sync.</p>'
    },
    policy_mode: {
        title: 'Modo de la política',
        html: '<p><strong>paused</strong>: solo uso manual desde la UI; el worker no la procesa.</p><p><strong>automatic</strong>: el worker en cron puede descubrir estudios y encolar.</p><p><strong>assisted</strong>: reservado; hoy no tiene efecto operativo.</p>'
    },
    policy_mod_filter: {
        title: 'Filtro de modalidad',
        html: '<p>Texto libre (p. ej. <code>CT</code> o <code>MR</code>) para ignorar estudios cuya modalidad no coincida. Vacío = todas.</p><p>Varias modalidades en el filtro se evalúan en el resultado del C-FIND según la implementación del worker (lista separada por comas).</p>'
    },
    policy_mod_priority: {
        title: 'Prioridad por modalidad (CSV)',
        html: '<p>Orden de preferencia, de izquierda a derecha, p. ej. <code>CR,DX,CT,MR</code>. Se usa cuando el orden de descubrimiento es <strong>por modalidad</strong> y como desempate en orden por fecha si hay lista.</p><p>Si está vacío, se usa la prioridad global del worker (si existe).</p>'
    },
    policy_discovery_sort: {
        title: 'Orden al descubrir estudios',
        html: '<p>Define cómo ordenar los resultados del C-FIND <strong>antes</strong> de evaluar qué estudios encolan.</p><ul><li><strong>Por modalidad</strong>: según la lista de prioridad; sin lista, orden natural del PACS.</li><li><strong>Estudio más antiguo primero</strong>: por <code>StudyDate</code>/<code>StudyTime</code> ascendente (útil para drenar backlog dentro de la ventana).</li><li><strong>Más reciente primero</strong>: lo opuesto.</li></ul>'
    },
    policy_alignment: {
        title: 'Alineación / reanudación C-MOVE',
        html: '<p><strong>Estudio</strong>: un C-MOVE a nivel estudio (simple).</p><p><strong>Serie / Instancia</strong>: reanudación más fina con más C-FIND/MOVE; útil si faltan series o SOP concretos. Requiere nodo DIMSE adecuado.</p>'
    },
    policy_notes: {
        title: 'Notas',
        html: '<p>Campo libre para documentación interna. No influye en el comportamiento del worker.</p>'
    },
    policy_enabled: {
        title: 'Política habilitada',
        html: '<p>Si no está marcada, el worker ignora la política aunque esté en modo <code>automatic</code>.</p><p>Útil para desactivar temporalmente sin borrar la fila.</p>'
    },
    sector_dispatch: {
        title: 'Encolar recuperación manual',
        html: '<p>Crea una <strong>orden</strong> con origen UI y lanza el C-MOVE de inmediato (no espera al worker). Sirve para estudios puntuales.</p><p>Los campos tienen ayuda individual con <strong>?</strong>.</p>'
    },
    dispatch_node: {
        title: 'Nodo origen (dispatch)',
        html: '<p>Mismo concepto que en la política: PACS remoto desde el que se solicita el C-MOVE hacia Orthanc local.</p>'
    },
    dispatch_uids: {
        title: 'StudyInstanceUID',
        html: '<p>Uno o más UIDs de estudio, separados por coma, punto y coma o salto de línea. Se validan y se envían en un solo flujo de recuperación.</p>'
    },
    dispatch_label: {
        title: 'Etiqueta (dispatch)',
        html: '<p>Texto opcional para identificar la orden en el historial (auditoría humana).</p>'
    },
    dispatch_alignment: {
        title: 'Alineación C-MOVE (dispatch)',
        html: '<p>Si no elige política asociada, este valor define la granularidad del C-MOVE (estudio / serie / instancia) igual que en la política.</p>'
    },
    dispatch_go: {
        title: 'Iniciar clonado',
        html: '<p>Ejecuta la acción <code>dispatch</code> en la API: inserta orden, llama al retrieve y devuelve error detallado si el nodo o Orthanc rechazan la operación.</p><p>Revise la pestaña <strong>Jobs</strong> para el progreso.</p>'
    },
    tbl_policies: {
        title: 'Tabla Políticas guardadas',
        html: '<p>Columnas: <strong>Nombre</strong>, <strong>Nodo</strong>, <strong>Modo</strong> (paused/automatic), <strong>Ventana h</strong>, <strong>Activa</strong>, <strong>Descubr.</strong> (orden de descubrimiento: modalidad / antiguos / recientes), <strong>Alineación</strong>, acciones editar/borrar.</p><p>Edición carga el formulario izquierdo.</p>'
    },
    tbl_orders: {
        title: 'Tabla Órdenes recientes',
        html: '<p>Muestra las últimas órdenes del cloner: <strong>Origen</strong> (<code>worker</code>, <code>ui</code>, etc.), estado, enlace al job Orthanc, cantidad de estudios en la orden, etiqueta y fecha.</p><p>Las fallidas pueden reintentarse con el botón correspondiente si la lógica lo permite.</p>'
    }
};
