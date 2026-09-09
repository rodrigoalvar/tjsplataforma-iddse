/**
 * UI del módulo Control de Calidad.
 */
(function () {
    'use strict';

    const API = 'api/';
    const STORAGE_KEY = 'qa_publicacion_queue_state';
    const STORAGE_MAX_AGE_MS = 24 * 60 * 60 * 1000;

    let config = {};
    let queue = [];
    let canManageConfig = false;
    let canViewLog = false;
    let qaEnabled = false;
    let auditLogModal = null;
    let confirmModal = null;
    let motivoModal = null;
    let searchDebounceTimer = null;
    let sortConfig = { column: 'study_date', direction: 'desc' };
    let filters = {
        dateFrom: '',
        dateTo: '',
        q: '',
        estado: '',
        tieneInforme: '',
    };

    function $(id) { return document.getElementById(id); }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function accionLabel(accion) {
        const map = {
            publicar: 'Publicar',
            bloquear: 'Bloquear',
            bajar_pacs: 'Bajar PACS',
            pendiente: 'Pendiente',
        };
        return map[accion] || accion || '';
    }

    function showToast(message, type = 'success') {
        const container = $('qaToastContainer');
        if (!container || typeof bootstrap === 'undefined') return;

        const toastId = 'qaMgrToast_' + Date.now();
        const bgClass = type === 'success' ? 'bg-success'
            : type === 'error' ? 'bg-danger'
                : type === 'warning' ? 'bg-warning text-dark' : 'bg-info text-dark';
        const icon = type === 'success' ? 'check-circle'
            : type === 'error' ? 'exclamation-triangle' : 'info-circle';

        container.insertAdjacentHTML('beforeend', `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${icon} me-2"></i>${escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>`);

        const el = document.getElementById(toastId);
        const toast = bootstrap.Toast.getOrCreateInstance(el, {
            autohide: true,
            delay: type === 'error' ? 5000 : 3500,
        });
        toast.show();
        el.addEventListener('hidden.bs.toast', () => el.remove());
    }

    function openConfirmModal(options) {
        return new Promise((resolve) => {
            const modalEl = $('qaConfirmModal');
            if (!modalEl || typeof bootstrap === 'undefined') {
                resolve(false);
                return;
            }
            if (!confirmModal) {
                confirmModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            }

            const titleEl = $('qaConfirmModalTitle');
            const bodyEl = $('qaConfirmModalBody');
            const btnEl = $('qaConfirmModalBtn');
            if (!titleEl || !bodyEl || !btnEl) {
                resolve(false);
                return;
            }

            titleEl.textContent = options.title || 'Confirmar';
            bodyEl.textContent = options.message || '';
            btnEl.textContent = options.confirmText || 'Confirmar';
            btnEl.className = 'btn ' + (options.confirmClass || 'btn-primary');

            let settled = false;
            const finish = (value) => {
                if (settled) return;
                settled = true;
                cleanup();
                resolve(value);
            };

            const onConfirm = () => {
                confirmModal.hide();
                finish(true);
            };
            const onHidden = () => finish(false);
            const cleanup = () => {
                btnEl.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            };

            btnEl.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            confirmModal.show();
        });
    }

    function openMotivoModal(options) {
        return new Promise((resolve) => {
            const modalEl = $('qaMotivoModal');
            if (!modalEl || typeof bootstrap === 'undefined') {
                resolve(null);
                return;
            }
            if (!motivoModal) {
                motivoModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            }

            const titleEl = $('qaMotivoModalTitle');
            const descEl = $('qaMotivoModalDesc');
            const selectEl = $('qaMotivoSelect');
            const detalleEl = $('qaMotivoDetalle');
            const btnEl = $('qaMotivoModalBtn');
            if (!titleEl || !selectEl || !detalleEl || !btnEl) {
                resolve(null);
                return;
            }

            titleEl.textContent = options.title || 'Motivo de bloqueo';
            if (descEl) {
                descEl.textContent = options.description || '';
                descEl.style.display = options.description ? '' : 'none';
            }
            selectEl.value = options.defaultMotivo || 'otro';
            detalleEl.value = '';

            let settled = false;
            const finish = (value) => {
                if (settled) return;
                settled = true;
                cleanup();
                resolve(value);
            };

            const onConfirm = () => {
                const motivo = selectEl.value;
                const motivoDetalle = detalleEl.value.trim();
                motivoModal.hide();
                finish({ motivo, motivo_detalle: motivoDetalle });
            };
            const onHidden = () => finish(null);
            const cleanup = () => {
                btnEl.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            };

            btnEl.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            motivoModal.show();
        });
    }

    function defaultDateRange() {
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - 7);
        return {
            dateFrom: from.toISOString().slice(0, 10),
            dateTo: to.toISOString().slice(0, 10),
        };
    }

    function readFiltersFromUi() {
        filters.dateFrom = $('qaDateFrom')?.value || '';
        filters.dateTo = $('qaDateTo')?.value || '';
        filters.q = $('qaSearch')?.value.trim() || '';
        filters.estado = $('qaEstadoFilter')?.value || '';
        filters.tieneInforme = $('qaInformeFilter')?.value || '';
    }

    function applyFiltersToUi() {
        if ($('qaDateFrom')) $('qaDateFrom').value = filters.dateFrom;
        if ($('qaDateTo')) $('qaDateTo').value = filters.dateTo;
        if ($('qaSearch')) $('qaSearch').value = filters.q;
        if ($('qaEstadoFilter')) $('qaEstadoFilter').value = filters.estado;
        if ($('qaInformeFilter')) $('qaInformeFilter').value = filters.tieneInforme;
    }

    function saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                filters: { ...filters },
                sortConfig: { ...sortConfig },
                timestamp: Date.now(),
            }));
        } catch (e) {
            console.warn('[QA] No se pudo guardar estado:', e);
        }
    }

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return false;
            const state = JSON.parse(raw);
            if (!state || Date.now() - (state.timestamp || 0) > STORAGE_MAX_AGE_MS) {
                localStorage.removeItem(STORAGE_KEY);
                return false;
            }
            if (state.filters) {
                filters = { ...filters, ...state.filters };
            }
            if (state.sortConfig?.column) {
                sortConfig = { ...sortConfig, ...state.sortConfig };
            }
            return true;
        } catch (e) {
            return false;
        }
    }

    async function apiGet(path) {
        const r = await fetch(API + path, { credentials: 'include' });
        return r.json();
    }

    async function apiPost(path, body) {
        const r = await fetch(API + path, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        return r.json();
    }

    function setBadge(el, text, className, visible) {
        if (!el) return;
        if (!visible) {
            el.classList.add('d-none');
            return;
        }
        el.textContent = text;
        el.className = 'badge ' + className;
        el.classList.remove('d-none');
    }

    function renderConfigBadges(cfg, enabled) {
        const mode = cfg.qa_mode || 'lista_negra';
        const modeLabels = {
            lista_negra: 'MODO LISTA NEGRA',
            lista_blanca: 'MODO LISTA BLANCA',
            hibrido: 'MODO HÍBRIDO',
        };
        const modeClasses = {
            lista_negra: 'bg-dark',
            lista_blanca: 'bg-primary',
            hibrido: 'bg-info text-dark',
        };

        setBadge($('qaEnabledBadge'), enabled ? 'QA ACTIVO' : 'QA INACTIVO', enabled ? 'bg-success' : 'bg-secondary', true);
        if ($('qaEnabledBadge')) {
            $('qaEnabledBadge').title = enabled
                ? 'El portal aplica reglas QA y bloqueos'
                : 'El portal no filtra; los bloqueos quedan guardados hasta activar QA';
        }
        setBadge(
            $('qaModeBadge'),
            modeLabels[mode] || ('MODO ' + String(mode).toUpperCase()),
            modeClasses[mode] || 'bg-primary',
            true
        );

        const isHybrid = mode === 'hibrido';
        const hybridInforme = (cfg.qa_hybrid_require_informe || '0') === '1';
        const hybridMixed = (cfg.qa_hybrid_block_mixed || '1') === '1';

        setBadge(
            $('qaHybridInformeBadge'),
            hybridInforme ? 'HÍBRIDO: EXIGE INFORME' : 'HÍBRIDO: SIN EXIGIR INFORME',
            hybridInforme ? 'bg-warning text-dark' : 'bg-light text-dark border',
            isHybrid
        );
        setBadge(
            $('qaHybridMixedBadge'),
            hybridMixed ? 'HÍBRIDO: BLOQ. MEZCLA' : 'HÍBRIDO: PERMITE MEZCLA',
            hybridMixed ? 'bg-danger' : 'bg-light text-dark border',
            isHybrid
        );
    }

    async function resolveCanManageConfig(serverFlag) {
        if (typeof serverFlag === 'boolean') {
            return serverFlag;
        }
        if (typeof SimplePermissionManager === 'undefined') {
            return false;
        }
        const pm = new SimplePermissionManager();
        const result = await pm.checkPermission('qa_config');
        return !!(result.success && result.hasPermission);
    }

    async function resolveCanViewLog(serverFlag) {
        if (typeof serverFlag === 'boolean') {
            return serverFlag;
        }
        if (typeof SimplePermissionManager === 'undefined') {
            return false;
        }
        const pm = new SimplePermissionManager();
        const checks = await Promise.all([
            pm.checkPermission('qa_ver_registro'),
            pm.checkPermission('qa_config'),
        ]);
        return checks.some((r) => r.success && r.hasPermission);
    }

    function applyAuditLogButtonVisibility() {
        const btn = $('qaAuditLogBtn');
        if (!btn) return;
        btn.classList.toggle('d-none', !canViewLog);
    }

    function isAuditLogModalOpen() {
        const modalEl = $('qaAuditLogModal');
        return !!(modalEl && modalEl.classList.contains('show'));
    }

    function openAuditLogModal() {
        if (!canViewLog) return;
        const modalEl = $('qaAuditLogModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        if (!auditLogModal) {
            auditLogModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        auditLogModal.show();
        loadLog();
    }

    function applyConfigSectionVisibility() {
        const cfgSection = $('qaConfigSection');
        const queueSection = $('qaQueueSection');
        if (canManageConfig) {
            cfgSection?.classList.remove('d-none');
            queueSection?.classList.remove('col-12');
            queueSection?.classList.add('col-lg-8');
        } else {
            cfgSection?.classList.add('d-none');
            queueSection?.classList.remove('col-lg-8');
            queueSection?.classList.add('col-12');
        }
    }

    function statusBadge(estado, emptyLabel) {
        if (!estado) {
            return `<span class="badge bg-light text-muted border">${emptyLabel || 'sin informe'}</span>`;
        }

        if (estado === 'bloqueado' && !qaEnabled) {
            return `<span class="badge bg-secondary" title="Bloqueado en QA; sigue visible en portal mientras QA esté inactivo">
                <i class="fas fa-ban me-1"></i>bloqueado · <i class="fas fa-globe-americas me-1"></i>visible en portal
            </span>`;
        }

        const labels = {
            publicado: { text: 'publicado', cls: 'success', icon: 'fa-globe-americas' },
            bloqueado: { text: 'portal oculto', cls: 'danger', icon: 'fa-eye-slash' },
            pendiente: { text: qaEnabled ? 'pendiente' : 'pendiente (sin efecto)', cls: 'warning', icon: 'fa-hourglass-half' },
        };
        const meta = labels[estado] || { text: estado, cls: 'secondary', icon: '' };
        const iconHtml = meta.icon ? `<i class="fas ${meta.icon} me-1"></i>` : '';
        return `<span class="badge bg-${meta.cls}${meta.cls === 'warning' ? ' text-dark' : ''}">${iconHtml}${meta.text}</span>`;
    }

    function renderImgToggle(item) {
        if (!item.study_instance_uid) {
            return '<small class="text-muted" title="Sin StudyInstanceUID">Img —</small>';
        }
        const estado = item.qa_estudio?.estado || 'publicado';
        const ds = `data-uid="${item.study_instance_uid || ''}" data-oid="${item.orthanc_id || ''}" data-pid="${item.patient_id || ''}" data-acc="${item.accession_number || ''}"`;
        const isBlocked = estado === 'bloqueado';
        const isPending = estado === 'pendiente';

        if (isPending) {
            return `<button type="button" class="btn btn-sm btn-warning text-dark" data-action="pub-img" ${ds} title="Imágenes pendientes — clic para publicar">
                <i class="fas fa-x-ray me-1"></i><i class="fas fa-hourglass-half"></i>
            </button>`;
        }
        if (isBlocked) {
            const title = qaEnabled
                ? 'Imágenes ocultas en portal — clic para publicar'
                : 'Bloqueadas en QA (visibles en portal) — clic para publicar';
            return `<button type="button" class="btn btn-sm ${qaEnabled ? 'btn-outline-danger' : 'btn-secondary'}" data-action="pub-img" ${ds} title="${title}">
                <i class="fas fa-x-ray me-1"></i><i class="fas fa-${qaEnabled ? 'eye-slash' : 'globe-americas'}"></i>
            </button>`;
        }
        return `<button type="button" class="btn btn-sm btn-success" data-action="blk-img" ${ds} title="Imágenes visibles en portal — clic para bloquear">
            <i class="fas fa-x-ray me-1"></i><i class="fas fa-globe-americas"></i>
        </button>`;
    }

    function renderInfToggle(item) {
        if (!item.informe_id) {
            return '<small class="text-muted">—</small>';
        }
        const estado = item.qa_informe?.estado || 'publicado';
        const iid = item.informe_id;
        const isBlocked = estado === 'bloqueado';
        const isPending = estado === 'pendiente';

        if (isPending) {
            return `<button type="button" class="btn btn-sm btn-warning text-dark" data-action="pub-inf" data-iid="${iid}" title="Informe pendiente — clic para publicar">
                <i class="fas fa-file-medical me-1"></i><i class="fas fa-hourglass-half"></i>
            </button>`;
        }
        if (isBlocked) {
            const title = qaEnabled
                ? 'Informe oculto en portal — clic para publicar'
                : 'Bloqueado en QA (visible en portal) — clic para publicar';
            return `<button type="button" class="btn btn-sm ${qaEnabled ? 'btn-outline-danger' : 'btn-secondary'}" data-action="pub-inf" data-iid="${iid}" title="${title}">
                <i class="fas fa-file-medical me-1"></i><i class="fas fa-${qaEnabled ? 'eye-slash' : 'globe-americas'}"></i>
            </button>`;
        }
        return `<button type="button" class="btn btn-sm btn-success" data-action="blk-inf" data-iid="${iid}" title="Informe visible en portal — clic para bloquear">
            <i class="fas fa-file-medical me-1"></i><i class="fas fa-globe-americas"></i>
        </button>`;
    }

    function sortValue(item, column) {
        switch (column) {
            case 'study_date':
                return item.study_date || item.sort_date || item.fecha_creacion || '';
            case 'patient_name':
                return (item.patient_name || '').toLowerCase();
            case 'modality':
                return ((item.modality || '') + ' ' + (item.study_description || '')).toLowerCase();
            case 'qa_estudio':
                return (item.qa_estudio?.estado || '').toLowerCase();
            case 'qa_informe':
                return item.tiene_informe ? (item.qa_informe?.estado || '').toLowerCase() : 'zzz_sin';
            case 'informe_id':
                return item.informe_id || 0;
            default:
                return '';
        }
    }

    function sortQueue(items) {
        if (!sortConfig.column) return items;
        const dir = sortConfig.direction === 'asc' ? 1 : -1;
        return [...items].sort((a, b) => {
            const va = sortValue(a, sortConfig.column);
            const vb = sortValue(b, sortConfig.column);
            if (va < vb) return -1 * dir;
            if (va > vb) return 1 * dir;
            return 0;
        });
    }

    function queueMatchesSearch(item, search) {
        if (!search) return true;
        const haystack = [
            item.patient_id,
            item.patient_name,
            item.accession_number,
            item.study_instance_uid,
            item.study_description,
            item.titulo,
            item.modality,
            item.informe_id,
        ].map((v) => String(v ?? '')).join(' ').toLowerCase();
        return haystack.includes(search.toLowerCase());
    }

    function getVisibleQueue() {
        const search = (filters.q || '').trim();
        const items = search ? queue.filter((item) => queueMatchesSearch(item, search)) : queue;
        return sortQueue(items);
    }

    function updateSortIcons() {
        document.querySelectorAll('#qaQueueTable thead th.sortable').forEach((header) => {
            const icon = header.querySelector('.sort-icon');
            if (!icon) return;
            const column = header.getAttribute('data-column');
            if (sortConfig.column === column) {
                icon.className = sortConfig.direction === 'asc'
                    ? 'fas fa-sort-up sort-icon'
                    : 'fas fa-sort-down sort-icon';
            } else {
                icon.className = 'fas fa-sort sort-icon';
            }
        });
    }

    function setupColumnSorting() {
        document.querySelectorAll('#qaQueueTable thead th.sortable').forEach((header) => {
            header.addEventListener('click', () => {
                const column = header.getAttribute('data-column');
                if (!column) return;
                if (sortConfig.column === column) {
                    sortConfig.direction = sortConfig.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    sortConfig.column = column;
                    sortConfig.direction = column === 'study_date' ? 'desc' : 'asc';
                }
                saveState();
                updateSortIcons();
                renderQueue();
            });
        });
        updateSortIcons();
    }

    function renderQueue() {
        const tbody = $('qaQueueBody');
        const countEl = $('qaQueueCount');
        const sorted = getVisibleQueue();
        if (countEl) countEl.textContent = String(sorted.length);

        if (!tbody) return;
        if (!sorted.length) {
            const emptyMsg = (filters.q || '').trim()
                ? 'Sin registros que coincidan con la búsqueda'
                : 'Sin registros en el rango seleccionado';
            tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">${emptyMsg}</td></tr>`;
            return;
        }

        tbody.innerHTML = sorted.map((item) => {
            const mixed = item.mixed_series && item.mixed_series.mixed
                ? '<span class="badge bg-danger ms-1" title="Posible mezcla de pacientes">MEZCLA</span>' : '';
            const fecha = item.study_date || (item.fecha_creacion ? String(item.fecha_creacion).slice(0, 10) : '');
            const informeBadge = item.tiene_informe
                ? statusBadge(item.qa_informe?.estado) + (item.en_pacs ? '' : ' <small class="text-muted">(sin PACS)</small>')
                : statusBadge(null, 'sin informe');
            const infBtns = item.informe_id
                ? `${renderInfToggle(item)}
                   <button class="btn btn-sm btn-outline-warning" data-action="bajar" data-iid="${item.informe_id}" title="Bajar del PACS">
                       <i class="fas fa-cloud-download-alt"></i>
                   </button>`
                : '<small class="text-muted">—</small>';
            const imgBtns = renderImgToggle(item);

            return `<tr>
                <td>${fecha}</td>
                <td>${item.patient_name || ''}<br><small class="text-muted">${item.patient_id || ''}</small></td>
                <td>${item.modality || ''}<br><small>${item.accession_number || ''}</small>${item.study_description ? `<br><small class="text-muted">${item.study_description}</small>` : ''}</td>
                <td>${statusBadge(item.qa_estudio?.estado)}${mixed}</td>
                <td>${informeBadge}</td>
                <td><small>${item.informe_id ? '#' + item.informe_id : '—'}</small></td>
                <td class="text-nowrap">
                    ${imgBtns}
                    ${infBtns}
                </td>
            </tr>`;
        }).join('');
    }

    async function loadQueue() {
        readFiltersFromUi();
        if (!filters.dateFrom || !filters.dateTo) {
            const defaults = defaultDateRange();
            filters.dateFrom = defaults.dateFrom;
            filters.dateTo = defaults.dateTo;
            applyFiltersToUi();
        }
        if (filters.dateFrom > filters.dateTo) {
            showToast('La fecha "Desde" no puede ser posterior a "Hasta".', 'warning');
            return;
        }

        saveState();

        const params = new URLSearchParams({
            limit: '200',
            date_from: filters.dateFrom,
            date_to: filters.dateTo,
        });
        if (filters.estado) params.set('estado', filters.estado);
        if (filters.tieneInforme) params.set('tiene_informe', filters.tieneInforme);

        const tbody = $('qaQueueBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Cargando estudios…</td></tr>';
        }

        const res = await apiGet('list-queue.php?' + params.toString());
        if (!res.success) {
            showToast(res.error || 'Error cargando cola', 'error');
            return;
        }
        queue = res.items || [];
        config = res.config || {};
        qaEnabled = !!res.enabled;
        if (typeof res.can_manage_config === 'boolean') {
            canManageConfig = res.can_manage_config;
            applyConfigSectionVisibility();
        }
        if (typeof res.can_view_log === 'boolean') {
            canViewLog = res.can_view_log;
            applyAuditLogButtonVisibility();
        }
        renderQueue();
        renderConfigBadges(config, !!res.enabled);
    }

    async function loadConfigForm() {
        if (!canManageConfig) return;
        const res = await apiGet('get-config.php');
        if (!res.success) return;
        if (typeof res.can_manage_config === 'boolean') {
            canManageConfig = res.can_manage_config;
            applyConfigSectionVisibility();
            if (!canManageConfig) return;
        }
        config = res.config || {};
        applyConfigForm(config);
        renderConfigBadges(config, (config.qa_enabled || '0') === '1');
    }

    function applyConfigForm(cfg) {
        if ($('cfgEnabled')) $('cfgEnabled').value = cfg.qa_enabled || '0';
        if ($('cfgMode')) $('cfgMode').value = cfg.qa_mode || 'lista_negra';
        if ($('cfgHybridInforme')) $('cfgHybridInforme').value = cfg.qa_hybrid_require_informe || '0';
        if ($('cfgHybridMixed')) $('cfgHybridMixed').value = cfg.qa_hybrid_block_mixed || '1';
    }

    async function saveConfig() {
        if (!canManageConfig) {
            showToast('No tenés permiso para modificar la configuración QA.', 'warning');
            return;
        }
        const body = {
            qa_enabled: $('cfgEnabled').value,
            qa_mode: $('cfgMode').value,
            qa_hybrid_require_informe: $('cfgHybridInforme').value,
            qa_hybrid_block_mixed: $('cfgHybridMixed').value,
        };
        const res = await apiPost('set-config.php', body);
        if (!res.success) {
            showToast(res.error || 'Error guardando', 'error');
            return;
        }
        showToast('Configuración guardada.', 'success');
        config = res.config || config;
        qaEnabled = (config.qa_enabled || '0') === '1';
        renderConfigBadges(config, qaEnabled);
        loadQueue();
    }

    async function executeSetStudy(estado, row, item, motivo, motivoDetalle) {
        const res = await apiPost('set-study-status.php', {
            study_instance_uid: row.uid,
            orthanc_id: row.oid,
            patient_id_pacs: row.pid,
            accession_number: row.acc,
            estado,
            motivo,
            motivo_detalle: motivoDetalle,
            patient_name: item.patient_name || '',
            patient_id: item.patient_id || row.pid || '',
            modality: item.modality || '',
            study_date: item.study_date || '',
            study_description: item.study_description || '',
        });
        if (!res.success) {
            showToast(res.error || 'Error actualizando estudio', 'error');
            return;
        }
        if (estado === 'bloqueado' && res.informes_cascaded > 0) {
            console.log('[QA] Informes bloqueados en cascada:', res.informes_cascaded);
        }
        if (estado === 'bloqueado' && res.portal_effective === false) {
            showToast(
                'Estudio marcado como bloqueado en QA. Sigue visible en portal hasta activar «QA habilitado».',
                'warning'
            );
        } else if (estado === 'bloqueado') {
            showToast('Estudio bloqueado en el portal.', 'success');
        } else {
            showToast('Imágenes publicadas en el portal.', 'success');
        }
        loadQueue();
        if (isAuditLogModalOpen()) {
            loadLog();
        }
    }

    async function setStudy(estado, row) {
        const item = queue.find((q) => q.study_instance_uid === row.uid) || {};
        if (estado === 'bloqueado') {
            const motivoData = await openMotivoModal({
                title: 'Bloquear imágenes en portal',
                description: [item.patient_name, item.study_description].filter(Boolean).join(' — '),
                defaultMotivo: 'otro',
            });
            if (!motivoData) return;
            await executeSetStudy(estado, row, item, motivoData.motivo, motivoData.motivo_detalle);
            return;
        }
        await executeSetStudy(estado, row, item, '', '');
    }

    async function executeSetInforme(estado, informeId, bajarDePacs, motivo, motivoDetalle) {
        const res = await apiPost('set-informe-status.php', {
            informe_id: informeId,
            estado,
            motivo,
            motivo_detalle: motivoDetalle,
            bajar_de_pacs: !!bajarDePacs,
        });
        if (!res.success) {
            showToast(res.error || 'Error actualizando informe', 'error');
            return;
        }
        if (bajarDePacs) {
            showToast('Informe bloqueado y bajado del PACS.', 'success');
        } else if (estado === 'bloqueado' && res.portal_effective === false) {
            showToast(
                'Informe marcado como bloqueado en QA. Sigue visible en portal hasta activar «QA habilitado».',
                'warning'
            );
        } else if (estado === 'bloqueado') {
            showToast('Informe bloqueado en el portal.', 'success');
        } else {
            showToast('Informe publicado en el portal.', 'success');
        }
        loadQueue();
        if (isAuditLogModalOpen()) {
            loadLog();
        }
    }

    async function setInforme(estado, informeId, bajarDePacs) {
        let motivo = '';
        let motivoDetalle = '';
        if (estado === 'bloqueado') {
            const item = queue.find((q) => q.informe_id === informeId) || {};
            const motivoData = await openMotivoModal({
                title: bajarDePacs ? 'Bajar informe del PACS' : 'Bloquear informe en portal',
                description: item.study_description
                    ? `${item.patient_name || ''} — ${item.study_description}`
                    : `Informe #${informeId}`,
                defaultMotivo: 'informe_erroneo',
            });
            if (!motivoData) return;
            motivo = motivoData.motivo;
            motivoDetalle = motivoData.motivo_detalle;
        }
        await executeSetInforme(estado, informeId, bajarDePacs, motivo, motivoDetalle);
    }

    async function loadLog() {
        const el = $('qaLogBody');
        if (!el || !canViewLog) return;
        el.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Cargando…</td></tr>';
        const res = await apiGet('log-list.php?limit=30');
        if (!el || !res.success) {
            if (el) {
                el.innerHTML = '<tr><td colspan="8" class="text-danger text-center py-4">No se pudo cargar el registro</td></tr>';
            }
            return;
        }
        el.innerHTML = (res.items || []).map((l) => {
            const ctx = l.study_context || {};
            const patientLine = ctx.patient_name
                ? `${escapeHtml(ctx.patient_name)}<br><small class="text-muted">${escapeHtml(ctx.patient_id || '')}</small>`
                : '<span class="text-muted">—</span>';
            const studyLine = ctx.study_description
                ? escapeHtml(ctx.study_description)
                : (l.informe_id ? `Informe #${l.informe_id}` : '<span class="text-muted">—</span>');
            const uid = l.study_instance_uid ? String(l.study_instance_uid) : '';
            const refLine = uid
                ? `<small class="text-muted d-block">${escapeHtml(uid.length > 28 ? `${uid.slice(0, 24)}…` : uid)}</small>`
                : (l.informe_id ? `<small class="text-muted d-block">Informe #${l.informe_id}</small>` : '');
            return `<tr>
                <td><small>${escapeHtml(l.created_at || '')}</small></td>
                <td>${escapeHtml(accionLabel(l.accion))}<br><small class="text-muted">${escapeHtml(l.target_type || '')}</small></td>
                <td>${patientLine}</td>
                <td>${studyLine}${refLine}</td>
                <td>${escapeHtml(ctx.modality || '—')}</td>
                <td><small>${escapeHtml(ctx.study_date || '—')}</small></td>
                <td><small>${escapeHtml(l.motivo || '')}</small></td>
                <td><small>${escapeHtml(((l.usuario_nombre || '') + ' ' + (l.usuario_apellido || '')).trim())}</small></td>
            </tr>`;
        }).join('') || '<tr><td colspan="8" class="text-muted text-center">Sin registros</td></tr>';
    }

    function bindEvents() {
        $('qaRefreshBtn')?.addEventListener('click', loadQueue);
        $('qaAuditLogBtn')?.addEventListener('click', openAuditLogModal);
        $('qaLogRefreshBtn')?.addEventListener('click', loadLog);
        $('qaSearchBtn')?.addEventListener('click', loadQueue);
        $('qaSaveConfigBtn')?.addEventListener('click', saveConfig);

        ['qaDateFrom', 'qaDateTo', 'qaEstadoFilter', 'qaInformeFilter'].forEach((id) => {
            $(id)?.addEventListener('change', () => {
                readFiltersFromUi();
                saveState();
            });
        });

        $('qaSearch')?.addEventListener('input', () => {
            readFiltersFromUi();
            saveState();
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => renderQueue(), 180);
        });

        $('qaSearch')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                readFiltersFromUi();
                saveState();
                renderQueue();
            }
        });

        $('qaQueueBody')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.dataset.action;
            if (action === 'pub-img') {
                setStudy('publicado', btn.dataset);
            } else if (action === 'blk-img') {
                setStudy('bloqueado', btn.dataset);
            } else if (action === 'pub-inf') {
                setInforme('publicado', parseInt(btn.dataset.iid, 10), false);
            } else if (action === 'blk-inf') {
                setInforme('bloqueado', parseInt(btn.dataset.iid, 10), false);
            } else if (action === 'bajar') {
                const informeId = parseInt(btn.dataset.iid, 10);
                const confirmed = await openConfirmModal({
                    title: 'Bajar informe del PACS',
                    message: '¿Bajar informe del PACS? (igual que Eliminar de PACS)',
                    confirmText: 'Bajar del PACS',
                    confirmClass: 'btn-warning',
                });
                if (confirmed) {
                    await setInforme('bloqueado', informeId, true);
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const ok = await requireAuth();
        if (!ok) return;
        const has = await requirePermissionSimple('gui_qa_publicacion', 'QA / Control de Calidad', {
            redirectUrl: '../../dashboard-unified.html',
        });
        if (!has) return;

        const cfgAccess = await apiGet('get-config.php');
        if (cfgAccess.success) {
            config = cfgAccess.config || {};
            canManageConfig = await resolveCanManageConfig(cfgAccess.can_manage_config);
            canViewLog = await resolveCanViewLog(cfgAccess.can_view_log);
            qaEnabled = (config.qa_enabled || '0') === '1';
            renderConfigBadges(config, qaEnabled);
            if (canManageConfig) {
                applyConfigForm(config);
            }
        } else {
            canManageConfig = await resolveCanManageConfig();
            canViewLog = await resolveCanViewLog();
        }
        applyConfigSectionVisibility();
        applyAuditLogButtonVisibility();

        const restored = loadState();
        if (!restored) {
            Object.assign(filters, defaultDateRange());
        }
        applyFiltersToUi();
        bindEvents();
        setupColumnSorting();
        await loadQueue();
    });
})();
