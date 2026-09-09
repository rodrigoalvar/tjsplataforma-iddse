/**
 * Modal liviano para gestionar informes recibidos desde API externa.
 * No reemplaza la lógica principal de informes-manager.
 */
(function () {
    'use strict';

    const LOW_SCORE_CONFIRM_THRESHOLD = 40;

    const state = {
        rows: [],
        pendientesTotal: null,
        currentLinkRow: null,
        candidates: [],
        selectedIds: new Set(),
        hayDiscrepanciaPatientId: false,
        candidateCriteria: {},
    };

    let lastPendientesFetchAt = 0;
    const PENDIENTES_THROTTLE_MS = 3500;

    // Contexto para el modal de confirmación de reprocesamiento
    // mode: 'all' = reprocesar todos los pendientes | 'selected' = solo IDs indicados
    let reprocesarContext = { mode: 'all', ids: [], triggerBtn: null };

    function apiBase() {
        return window.location.pathname.includes('/components/')
            ? '../api/informes/recibidos'
            : 'api/informes/recibidos';
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function showToast(message, type) {
        if (window.InformesManager && typeof window.InformesManager.showToast === 'function') {
            window.InformesManager.showToast(message, type || 'info');
            return;
        }
        alert(message);
    }

    /** Misma idea que deshabilitar "Eliminar" en informes-manager cuando hay envío PACS. */
    function isInformeEnPacs(row) {
        const s = (v) => v != null && String(v).trim() !== '';
        return (
            s(row.pacs_instance_id) ||
            s(row.pacs_study_id) ||
            s(row.pacs_series_id) ||
            s(row.fecha_enviado_pacs)
        );
    }

    function isRowVinculado(row) {
        const estado = String(row?.estado || '');
        return estado === 'vinculado' || Number(row?.estudio_id) > 0;
    }

    /** Estado del envío automático a PACS (columnas opcionales en list.php). */
    function autoPacsBadge(row) {
        const st = row && row.auto_pacs_estado != null ? String(row.auto_pacs_estado).trim() : '';
        if (!st) {
            return '<span class="text-muted">—</span>';
        }
        const err = row.auto_pacs_error ? String(row.auto_pacs_error) : '';
        const tries = row.auto_pacs_try_count != null ? Number(row.auto_pacs_try_count) : '';
        const fechaPacs = row.fecha_enviado_pacs ? new Date(row.fecha_enviado_pacs).toLocaleString('es-AR') : '';
        const titleBits = [];
        if (fechaPacs) titleBits.push(`Enviado: ${fechaPacs}`);
        if (err) titleBits.push(err);
        if (tries !== '' && !Number.isNaN(tries)) titleBits.push(`Intentos: ${tries}`);
        let title = '';
        if (titleBits.length) {
            const raw = titleBits.join(' | ');
            const safe = escapeHtml(raw).replace(/"/g, '&quot;');
            title = ` title="${safe}"`;
        }
        if (st === 'enviado') {
            return `<span class="badge bg-success"${title}>PACS OK</span>`;
        }
        if (st === 'error') {
            return `<span class="badge bg-danger"${title}>Error</span>`;
        }
        if (st === 'pendiente') {
            return `<span class="badge bg-warning text-dark"${title}>Pendiente</span>`;
        }
        return `<span class="badge bg-secondary"${title}>${escapeHtml(st)}</span>`;
    }

    function normalizeFileUrl(path) {
        const raw = (path || '').trim();
        if (!raw) return '';
        if (/^https?:\/\//i.test(raw)) return raw;
        return raw.startsWith('/') ? raw : `/${raw}`;
    }

    async function openFileViewer(type, filePath) {
        const modalEl = document.getElementById('irFileViewerModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            throw new Error('No se encontró el visor de archivos');
        }
        const frame = document.getElementById('irFileViewerPdfFrame');
        const txt = document.getElementById('irFileViewerTxtContent');
        const title = document.getElementById('irFileViewerTitle');
        if (!frame || !txt || !title) {
            throw new Error('Visor de archivos incompleto');
        }

        const url = normalizeFileUrl(filePath);
        if (!url) {
            throw new Error('Ruta de archivo inválida');
        }

        frame.classList.add('d-none');
        txt.classList.add('d-none');
        frame.removeAttribute('src');
        txt.textContent = '';

        if (type === 'pdf') {
            title.textContent = 'PDF recibido';
            frame.setAttribute('src', url);
            frame.classList.remove('d-none');
        } else {
            title.textContent = 'TXT recibido';
            const res = await fetch(url, { credentials: 'include' });
            if (!res.ok) {
                throw new Error(`No se pudo abrir TXT (HTTP ${res.status})`);
            }
            txt.textContent = await res.text();
            txt.classList.remove('d-none');
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    function fmtDate(value) {
        if (!value) return 'N/A';
        try {
            const s = String(value).trim();
            const ymd = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
            if (ymd) {
                const y = Number(ymd[1]);
                const mo = Number(ymd[2]) - 1;
                const da = Number(ymd[3]);
                const d = new Date(y, mo, da);
                if (d.getFullYear() === y && d.getMonth() === mo && d.getDate() === da) {
                    return d.toLocaleDateString('es-AR');
                }
            }
            const d = new Date(s.includes('T') ? s : `${s}T12:00:00`);
            if (Number.isNaN(d.getTime())) return s;
            return d.toLocaleDateString('es-AR');
        } catch (e) {
            return value;
        }
    }

    function renderLinkSummary(row) {
        const box = document.getElementById('irVincularResumen');
        if (!box) return;
        if (!row) {
            box.textContent = 'Seleccione un informe recibido para iniciar.';
            return;
        }

        const pdfBtn = row.pdf_path
            ? `<button type="button" class="btn btn-sm btn-outline-primary me-2" data-action="open-current-pdf"><i class="fas fa-file-pdf me-1"></i>Ver PDF recibido</button>`
            : '';
        const txtBtn = row.txt_path
            ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-action="open-current-txt"><i class="fas fa-file-lines me-1"></i>Ver TXT recibido</button>`
            : '';

        box.innerHTML = `
            <div class="small">
                <strong>Informe #${row.id}</strong> |
                ACCNO: <code>${escapeHtml(row.accession_number || 'N/A')}</code> |
                Paciente: <strong>${escapeHtml(row.patient_name || 'N/A')}</strong> |
                ID paciente: <code>${escapeHtml(row.patient_id || 'N/A')}</code> |
                Fecha proc.: <strong>${escapeHtml(fmtDate(row.procedure_date))}</strong> |
                Mod.: <strong>${escapeHtml(row.modality || 'N/A')}</strong>
            </div>
            <div class="mt-2">
                ${pdfBtn}${txtBtn}
            </div>
        `;
    }

    /**
     * Total de informes en estado "recibido" (pendientes de vincular).
     */
    async function fetchPendientesTotal() {
        const params = new URLSearchParams({ page: '1', limit: '1', estado: 'recibido' });
        const res = await fetch(`${apiBase()}/list.php?${params.toString()}`);
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'No se pudo obtener el contador');
        }
        const total = data.pagination && typeof data.pagination.total === 'number'
            ? data.pagination.total
            : 0;
        return total;
    }

    function applyPendientesCount(n) {
        state.pendientesTotal = n;

        const headerEl = document.getElementById('informesRecibidosPendientesCount');
        if (headerEl) {
            headerEl.textContent = n;
        }

        const btnBadge = document.getElementById('irBtnBadge');
        if (btnBadge) {
            const display = n > 99 ? '99+' : String(n);
            btnBadge.textContent = display;
            if (n > 0) {
                btnBadge.classList.remove('d-none');
            } else {
                btnBadge.classList.add('d-none');
            }
        }

        const modalBadge = document.getElementById('irModalPendientesBadge');
        if (modalBadge) {
            modalBadge.textContent = `${n} pendiente${n !== 1 ? 's' : ''}`;
        }
    }

    async function refreshPendientesCount(options) {
        const force = options && options.force === true;
        const now = Date.now();
        if (!force && state.pendientesTotal !== null && now - lastPendientesFetchAt < PENDIENTES_THROTTLE_MS) {
            return;
        }
        lastPendientesFetchAt = now;
        try {
            const total = await fetchPendientesTotal();
            applyPendientesCount(total);
        } catch (err) {
            console.warn('[InformesRecibidosModal] Contador pendientes:', err);
            applyPendientesCount(0);
        }
    }

    async function loadRows() {
        const tbody = document.getElementById('irTableBody');
        const estado = document.getElementById('irEstadoFilter')?.value || '';
        const search = document.getElementById('irSearchFilter')?.value || '';

        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Cargando...</td></tr>';

        const params = new URLSearchParams({ page: '1', limit: '100' });
        if (estado) params.set('estado', estado);
        if (search) params.set('search', search);

        const res = await fetch(`${apiBase()}/list.php?${params.toString()}`);
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'No se pudo cargar informes recibidos');
        }
        state.rows = data.data || [];
        state.selectedIds = new Set();
        renderRows();
        updateBulkSelectionUi();

        await refreshPendientesCount({ force: true });
    }

    function renderRows() {
        const tbody = document.getElementById('irTableBody');
        if (!state.rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No hay informes recibidos para mostrar</td></tr>';
            return;
        }

        tbody.innerHTML = state.rows.map((row) => {
            const vinculado = row.estudio_id ? `#${row.estudio_id}` : 'No vinculado';
            const estado = String(row.estado || '');
            const isVinculado = estado === 'vinculado' || Number(row.estudio_id) > 0;
            const canLink = !isVinculado && estado !== 'descartado';
            const canDiscard = canLink;
            const blockedPacs = isInformeEnPacs(row);
            const canUnlink = isVinculado && estado !== 'descartado' && !blockedPacs;
            const unlinkDisabled =
                isVinculado && estado !== 'descartado' && blockedPacs
                    ? `<button type="button" class="btn btn-sm btn-outline-secondary ms-1" disabled title="El informe vinculado está en PACS. Elimínelo de PACS en el gestor de informes antes de desvincular.">Desvincular</button>`
                    : '';
            return `
                <tr>
                    <td>
                        <input type="checkbox" class="form-check-input ir-row-select" data-id="${row.id}" ${state.selectedIds.has(Number(row.id)) ? 'checked' : ''}>
                    </td>
                    <td>${row.id}</td>
                    <td><strong>${escapeHtml(row.accession_number || '')}</strong></td>
                    <td>${escapeHtml(row.patient_name || 'N/A')}</td>
                    <td>${escapeHtml(row.patient_id || 'N/A')}</td>
                    <td>
                        ${estado === 'pendiente_sin_pacs'
                            ? `<span class="badge bg-secondary" title="Modalidad sin estudios en PACS. Vinculación manual cuando corresponda.">Sin PACS</span>`
                            : escapeHtml(estado)}
                    </td>
                    <td>${escapeHtml(vinculado)}</td>
                    <td class="text-nowrap">${autoPacsBadge(row)}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary me-1" data-action="open-pdf" data-id="${row.id}">
                            PDF
                        </button>
                        ${canLink ? `<button class="btn btn-sm btn-success" data-action="link" data-id="${row.id}">Vincular</button>` : ''}
                        ${canUnlink ? `<button class="btn btn-sm btn-outline-warning ms-1" data-action="unlink" data-id="${row.id}" title="Quitar vínculo con el estudio">Desvincular</button>` : ''}${unlinkDisabled}
                        ${canDiscard ? `<button class="btn btn-sm btn-outline-danger ms-1" data-action="discard" data-id="${row.id}">Descartar</button>` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    }

    function selectedRows() {
        if (!state.selectedIds.size) return [];
        return state.rows.filter((r) => state.selectedIds.has(Number(r.id)));
    }

    function updateBulkSelectionUi() {
        const total = state.rows.length;
        const selected = selectedRows().length;
        const badge = document.getElementById('irSelectedCountBadge');
        if (badge) {
            badge.textContent = `${selected} seleccionado${selected === 1 ? '' : 's'}`;
        }
        const chkAll = document.getElementById('irSelectAllChk');
        if (chkAll) {
            chkAll.checked = total > 0 && selected === total;
            chkAll.indeterminate = selected > 0 && selected < total;
        }
        const bulkLinkBtn = document.getElementById('irBulkLinkBtn');
        const bulkDiscardBtn = document.getElementById('irBulkDiscardBtn');
        const bulkUnlinkBtn = document.getElementById('irBulkUnlinkBtn');
        const bulkClearBtn = document.getElementById('irBulkClearBtn');
        const selectPendingsBtn = document.getElementById('irSelectPendingsBtn');
        const selectLinkedBtn = document.getElementById('irSelectLinkedBtn');
        const selectPacsBlockedBtn = document.getElementById('irSelectPacsBlockedBtn');
        if (bulkLinkBtn) bulkLinkBtn.disabled = selected === 0;
        if (bulkDiscardBtn) bulkDiscardBtn.disabled = selected === 0;
        if (bulkUnlinkBtn) bulkUnlinkBtn.disabled = selected === 0;
        if (bulkClearBtn) bulkClearBtn.disabled = selected === 0;
        if (selectPendingsBtn) {
            const pendingCount = state.rows.filter((r) => String(r.estado || '') === 'recibido').length;
            selectPendingsBtn.disabled = pendingCount === 0;
        }
        if (selectLinkedBtn) {
            const linkedCount = state.rows.filter((r) => isRowVinculado(r)).length;
            selectLinkedBtn.disabled = linkedCount === 0;
        }
        if (selectPacsBlockedBtn) {
            const blockedCount = state.rows.filter((r) => isRowVinculado(r) && isInformeEnPacs(r)).length;
            selectPacsBlockedBtn.disabled = blockedCount === 0;
        }
    }

    function showUnlinkDialog(rowOrTarget) {
        return new Promise((resolve) => {
            const modalEl = document.getElementById('irUnlinkModal');
            const targetEl = document.getElementById('irUnlinkTargetLabel');
            const confirmBtn = document.getElementById('irUnlinkConfirmBtn');
            if (!modalEl || !targetEl || !confirmBtn || typeof bootstrap === 'undefined') {
                showToast('No se encontró el modal de desvinculación', 'danger');
                resolve(false);
                return;
            }

            if (typeof rowOrTarget === 'string') {
                targetEl.textContent = rowOrTarget;
            } else {
                targetEl.textContent = `#${rowOrTarget.id}`;
            }
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            let settled = false;

            function cleanup() {
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                confirmBtn.removeEventListener('click', onConfirm);
            }

            function onHidden() {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                resolve(false);
            }

            function onConfirm() {
                settled = true;
                cleanup();
                modal.hide();
                resolve(true);
            }

            modalEl.addEventListener('hidden.bs.modal', onHidden);
            confirmBtn.addEventListener('click', onConfirm);
            modal.show();
        });
    }

    async function unlinkRow(row, options = {}) {
        if (!row || !row.id) return;
        const estado = String(row.estado || '');
        const isVinculado = estado === 'vinculado' || Number(row.estudio_id) > 0;
        if (!isVinculado || estado === 'descartado') {
            throw new Error('Este informe no está vinculado');
        }
        if (isInformeEnPacs(row)) {
            throw new Error(
                'No se puede desvincular: el informe asociado está en PACS. Elimínelo de PACS desde el gestor de informes primero.'
            );
        }
        const skipConfirm = !!options.skipConfirm;
        const ok = skipConfirm ? true : await showUnlinkDialog(row);
        if (!ok) {
            return;
        }
        const res = await fetch(`${apiBase()}/desvincular.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ recibido_id: Number(row.id) }),
        });
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'No se pudo desvincular');
        }
        if (!options.skipReload) {
            showToast('Informe desvinculado correctamente', 'success');
            await loadRows();
            if (window.InformesManager && typeof window.InformesManager.loadReports === 'function') {
                window.InformesManager.loadReports();
            }
        }
    }

    async function discardRow(row, options = {}) {
        if (!row || !row.id) return;
        if (row.estudio_id || String(row.estado || '') === 'vinculado') {
            throw new Error('No se puede descartar un informe ya vinculado');
        }
        const motivoText = options.motivo ? String(options.motivo) : await showDiscardDialog(row);
        if (!motivoText) {
            return;
        }

        const res = await fetch(`${apiBase()}/descartar.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                recibido_id: Number(row.id),
                motivo_descarte: motivoText,
            }),
        });
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'No se pudo descartar');
        }
        if (!options.skipReload) {
            showToast('Informe descartado correctamente', 'success');
            await loadRows();
            if (window.InformesManager && typeof window.InformesManager.loadReports === 'function') {
                window.InformesManager.loadReports();
            }
        }
    }

    function showDiscardDialog(rowOrTarget) {
        return new Promise((resolve) => {
            const modalEl = document.getElementById('irDiscardModal');
            const reasonEl = document.getElementById('irDiscardReason');
            const targetEl = document.getElementById('irDiscardTargetLabel');
            const confirmBtn = document.getElementById('irDiscardConfirmBtn');
            if (!modalEl || !reasonEl || !targetEl || !confirmBtn || typeof bootstrap === 'undefined') {
                showToast('No se encontró el modal de descarte', 'danger');
                resolve('');
                return;
            }

            if (typeof rowOrTarget === 'string') {
                targetEl.textContent = rowOrTarget;
            } else {
                targetEl.textContent = `#${rowOrTarget.id}`;
            }
            reasonEl.value = '';
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            let settled = false;

            function cleanup() {
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                confirmBtn.removeEventListener('click', onConfirm);
            }

            function onHidden() {
                if (settled) return;
                settled = true;
                cleanup();
                resolve('');
            }

            function onConfirm() {
                const reason = (reasonEl.value || '').trim();
                if (!reason) {
                    showToast('Debe indicar un motivo para descartar', 'warning');
                    reasonEl.focus();
                    return;
                }
                settled = true;
                cleanup();
                modal.hide();
                resolve(reason);
            }

            modalEl.addEventListener('hidden.bs.modal', onHidden);
            confirmBtn.addEventListener('click', onConfirm);
            modal.show();
            setTimeout(() => reasonEl.focus(), 150);
        });
    }

    async function fetchCandidates(row, query = '', forcePacsSearch = false) {
        const params = new URLSearchParams();
        params.set('recibido_id', String(row.id));
        if (query) params.set('q', query);
        if (forcePacsSearch) params.set('pacs_search', '1');
        const limitEl = document.getElementById('irCandidateLimit');
        const limitVal = limitEl ? Number(limitEl.value) : 20;
        const limit = Number.isFinite(limitVal) ? Math.min(100, Math.max(5, limitVal)) : 20;
        params.set('limit', String(limit));
        const res = await fetch(`${apiBase()}/candidatos-estudio.php?${params.toString()}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'No se pudieron obtener candidatos');
        // Guardar flag global de discrepancia de patient_id para mostrar en UI
        state.hayDiscrepanciaPatientId = data.hay_discrepancia_patient_id || false;
        state.candidateCriteria = data.criteria || {};
        return data.data || [];
    }

    function renderCandidates() {
        const tbody = document.getElementById('irCandidatesBody');
        const topHint = document.getElementById('irCandidateTopHint');
        if (!tbody) return;
        if (!state.candidates.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Sin candidatos para los criterios actuales</td></tr>';
            if (topHint) {
                topHint.classList.add('d-none');
                topHint.textContent = '';
            }
            return;
        }

        const topCandidate = state.candidates[0];
        if (topHint) {
            const topScore = Number(topCandidate.match_score || 0);
            let riskText = 'alta';
            let alertClass = 'alert-info';
            if (topScore < LOW_SCORE_CONFIRM_THRESHOLD) {
                riskText = 'baja';
                alertClass = 'alert-warning';
            } else if (topScore < 70) {
                riskText = 'media';
                alertClass = 'alert-primary';
            }
            const topStudyLabel = topCandidate.id ? `estudio #${escapeHtml(topCandidate.id)}` : 'estudio en PACS (sin alta local)';
            const irPatientId = state.candidateCriteria?.patient_id || '';
            let discrepanciaHtml = '';
            if (state.hayDiscrepanciaPatientId) {
                discrepanciaHtml = `
                <div class="alert alert-warning py-2 px-3 mt-2 mb-0">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <strong>Advertencia:</strong> Uno o más candidatos tienen un ID de paciente diferente al del informe
                    ${irPatientId ? `(<code>${escapeHtml(irPatientId)}</code>)` : ''}.
                    Esto puede indicar un error de carga manual en el equipo.
                    Verifique nombre, fecha y modalidad antes de vincular.
                </div>`;
            }
            topHint.className = `alert ${alertClass} py-2 px-3 mb-3`;
            topHint.innerHTML = `
                <strong>Mejor sugerencia:</strong> ${topStudyLabel} (score ${topScore}, confianza ${riskText}).
                Revise los motivos antes de confirmar la vinculación.
                ${discrepanciaHtml}
            `;
        }

        tbody.innerHTML = state.candidates.map((c, index) => {
            const reasons = Array.isArray(c.match_reasons) ? c.match_reasons : [];
            const reasonsText = reasons.length ? reasons.join(', ') : (c.match_reasons_text || 'sin_coincidencia_fuerte');
            const scoreClass = Number(c.match_score || 0) >= 70
                ? 'bg-success'
                : Number(c.match_score || 0) >= 40
                    ? 'bg-primary'
                    : 'bg-secondary';
            const signedDateDelta = Number(c.date_signed_diff_days);
            const dateSuffix = Number.isFinite(signedDateDelta)
                ? (signedDateDelta > 0 ? ` (+${signedDateDelta}d)` : ` (${signedDateDelta}d)`)
                : (c.date_diff_days != null ? ` (${c.date_diff_days}d)` : '');
            const dateSrc = c.study_date_source || '';
            const dateTitle = dateSrc === 'pacs'
                ? 'Fecha StudyDate (0008,0020) obtenida desde PACS (Orthanc).'
                : (dateSrc === 'local'
                    ? 'Fecha tomada de la base local (Orthanc no devolvió StudyDate o no hubo ID/UID resoluble).'
                    : '');
            const dateInfo = c.study_date ? `${fmtDate(c.study_date)}${dateSuffix}` : 'N/A';
            const isPacsOnly = !c.id && !!c.orthanc_study_id;
            const sourceBadge = isPacsOnly
                ? '<span class="badge bg-warning text-dark ms-1">PACS (creará estudio local)</span>'
                : '<span class="badge bg-success ms-1">Local</span>';
            const patientName = c.patient_name || c.patient_name_pacs || 'N/A';
            const candidatePrimaryId = c.id
                ? `#${c.id}`
                : (c.orthanc_study_id || c.study_instance_uid || 'PACS');
            let idHint = '';
            if (c.orthanc_internal_id) {
                idHint = `Orthanc (REST): <code>${escapeHtml(c.orthanc_internal_id)}</code>`;
                if (c.orthanc_study_id && String(c.orthanc_study_id) !== String(c.orthanc_internal_id)) {
                    idHint += `<div><small class="text-muted">En BD orthanc_study_id: ${escapeHtml(c.orthanc_study_id)}</small></div>`;
                }
            } else if (c.orthanc_study_id) {
                idHint = `Orthanc: <code>${escapeHtml(c.orthanc_study_id)}</code>`;
            } else if (c.study_instance_uid) {
                idHint = `StudyUID: <code>${escapeHtml(c.study_instance_uid)}</code>`;
            }
            return `
                <tr>
                    <td><span class="badge ${scoreClass}">${Number(c.match_score || 0)}</span></td>
                    <td><small>${escapeHtml(reasonsText)}</small></td>
                    <td>
                        <code>${escapeHtml(candidatePrimaryId)}</code>${sourceBadge}
                        ${idHint ? `<div><small class="text-muted">${idHint}</small></div>` : ''}
                    </td>
                    <td><small title="${escapeHtml(c.study_instance_uid || '')}">${escapeHtml((c.study_instance_uid || '').substring(0, 20) || 'N/A')}${(c.study_instance_uid || '').length > 20 ? '…' : ''}</small></td>
                    <td>${escapeHtml(patientName)}</td>
                    <td>
                        ${(() => {
                            const displayId = c.candidate_patient_id || c.patient_id || '';
                            const irId = state.candidateCriteria?.patient_id || '';
                            const hasMismatch = c.patient_id_discrepante || c.patient_id_fuzzy;
                            if (!displayId) return 'N/A';
                            if (!hasMismatch) return escapeHtml(displayId);
                            // Mostrar el ID del PACS con indicador de discrepancia
                            const badgeClass = c.patient_id_discrepante ? 'bg-danger' : 'bg-warning text-dark';
                            const badgeText  = c.patient_id_discrepante ? '⚠️ ID≠' : 'ID≈';
                            const tooltipMsg = c.patient_id_discrepante
                                ? `ID en PACS: ${displayId} — ID en informe: ${irId}. Probable error de carga manual en el equipo.`
                                : `ID en PACS: ${displayId} — ID en informe: ${irId}. Difiere por 1 dígito (posible typo).`;
                            return `<code class="text-danger fw-bold">${escapeHtml(displayId)}</code>
                                    <span class="badge ${badgeClass} ms-1" title="${escapeHtml(tooltipMsg)}">${badgeText}</span>
                                    ${irId ? `<div><small class="text-muted">Informe: ${escapeHtml(irId)}</small></div>` : ''}`;
                        })()}
                    </td>
                    <td${dateTitle ? ` title="${escapeHtml(dateTitle)}"` : ''}>${escapeHtml(dateInfo)}</td>
                    <td>${escapeHtml(c.modality || 'N/A')}</td>
                    <td>
                        ${escapeHtml(c.accession_number || 'N/A')}
                        ${c.accno_pacs_enriched ? '<span class="badge bg-info ms-1" title="ACCNO obtenido desde PACS en tiempo real, no guardado en BD local">PACS</span>' : ''}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-success" data-action="confirm-link" data-candidate-index="${index}">
                            Vincular
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        const firstActionBtn = tbody.querySelector('button[data-action="confirm-link"]');
        if (firstActionBtn) {
            const firstRow = firstActionBtn.closest('tr');
            if (firstRow) {
                firstRow.classList.add('table-success');
            }
        }
    }

    async function loadCandidatesForCurrentRow(forceQuery = '', forcePacsSearch = false) {
        const row = state.currentLinkRow;
        if (!row) return;
        const tbody = document.getElementById('irCandidatesBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Cargando candidatos...</td></tr>';
        }
        try {
            state.candidates = await fetchCandidates(row, forceQuery, forcePacsSearch);
            renderCandidates();
        } catch (err) {
            state.candidates = [];
            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="10" class="text-center text-danger">${escapeHtml(err.message || 'Error cargando candidatos')}</td></tr>`;
            }
        }
    }

    async function confirmLinkStudy(candidateIndex) {
        const row = state.currentLinkRow;
        if (!row) return;
        const idx = Number(candidateIndex);
        if (!Number.isInteger(idx) || idx < 0 || idx >= state.candidates.length) {
            showToast('Candidato inválido', 'warning');
            return;
        }

        const selectedCandidate = state.candidates[idx] || null;
        const numericStudyId = Number(selectedCandidate?.id || 0);
        const score = selectedCandidate ? Number(selectedCandidate.match_score || 0) : null;

        if (selectedCandidate && Number.isFinite(score) && score < LOW_SCORE_CONFIRM_THRESHOLD) {
            const reasons = Array.isArray(selectedCandidate.match_reasons) && selectedCandidate.match_reasons.length
                ? selectedCandidate.match_reasons.join(', ')
                : (selectedCandidate.match_reasons_text || 'sin motivos fuertes');
            const proceed = window.confirm(
                `El candidato seleccionado tiene score bajo (${score}).\n\n` +
                `Estudio: ${numericStudyId > 0 ? `#${numericStudyId}` : (selectedCandidate?.orthanc_study_id || 'PACS sin alta local')}\n` +
                `Motivos: ${reasons}\n\n` +
                '¿Desea vincular igualmente?'
            );
            if (!proceed) {
                return;
            }
        }

        const res = await fetch(`${apiBase()}/vincular.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                recibido_id: Number(row.id),
                estudio_id: numericStudyId > 0 ? numericStudyId : null,
                orthanc_study_id: selectedCandidate?.orthanc_study_id || null,
                orthanc_internal_id: selectedCandidate?.orthanc_internal_id || null,
                study_instance_uid: selectedCandidate?.study_instance_uid || null,
                patient_id: selectedCandidate?.patient_id || null,
                patient_name: selectedCandidate?.patient_name || null,
                modality: selectedCandidate?.modality || null,
                study_description: selectedCandidate?.study_description || null,
                study_date: selectedCandidate?.study_date || null,
                accession_number: selectedCandidate?.accession_number || null,
                match_score: score,
                match_reasons: selectedCandidate ? (selectedCandidate.match_reasons || null) : null,
                is_suggested: !!selectedCandidate
            })
        });
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'No se pudo vincular');
        }

        const linkedInformeId = data.data && data.data.informe_id ? Number(data.data.informe_id) : null;

        showToast('Informe recibido vinculado correctamente', 'success');
        const modalEl = document.getElementById('irVincularModal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            const instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) instance.hide();
        }
        await loadRows();
        if (window.InformesManager && typeof window.InformesManager.loadReports === 'function') {
            window.InformesManager.loadReports();
        }

        // El backend envía a PACS después de fastcgi_finish_request; registrar el informe
        // en el mecanismo de polling para que el botón pase de verde → amarillo al completar.
        if (linkedInformeId && window.InformesManager && typeof window.InformesManager.addPendingPacsSending === 'function') {
            setTimeout(() => {
                window.InformesManager.addPendingPacsSending(linkedInformeId);
            }, 1500);
        }
    }

    async function openLinkModal(rowId) {
        const row = state.rows.find((r) => Number(r.id) === Number(rowId));
        if (!row) return;
        state.currentLinkRow = row;
        state.candidates = [];
        renderLinkSummary(row);

        const modalEl = document.getElementById('irVincularModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            showToast('No se pudo abrir el modal de vinculación', 'danger');
            return;
        }

        const searchInput = document.getElementById('irCandidateSearch');
        if (searchInput) searchInput.value = '';

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        await loadCandidatesForCurrentRow('');
    }

    async function processBulkDiscard() {
        const rows = selectedRows();
        if (!rows.length) return;
        const elegibles = rows.filter((r) => !(r.estudio_id || String(r.estado || '') === 'vinculado'));
        if (!elegibles.length) {
            throw new Error('Ninguno de los seleccionados puede descartarse (ya están vinculados o no aplican).');
        }
        const motivo = await showDiscardDialog(`${elegibles.length} seleccionados`);
        if (!motivo) return;
        let ok = 0;
        let fail = 0;
        for (const row of elegibles) {
            try {
                await discardRow(row, { motivo, skipReload: true });
                ok += 1;
            } catch (e) {
                fail += 1;
            }
        }
        if (ok > 0) showToast(`Descartados: ${ok}`, fail > 0 ? 'warning' : 'success');
        if (fail > 0) showToast(`No se pudieron descartar ${fail}`, 'warning');
        await loadRows();
        if (window.InformesManager && typeof window.InformesManager.loadReports === 'function') {
            window.InformesManager.loadReports();
        }
    }

    async function processBulkUnlink() {
        const rows = selectedRows();
        if (!rows.length) return;
        const elegibles = rows.filter((r) => {
            const estado = String(r.estado || '');
            const isVinculado = estado === 'vinculado' || Number(r.estudio_id) > 0;
            return isVinculado && estado !== 'descartado' && !isInformeEnPacs(r);
        });
        if (!elegibles.length) {
            throw new Error('Ninguno de los seleccionados puede desvincularse (no vinculado o bloqueado por PACS).');
        }
        const okConfirm = await showUnlinkDialog(`${elegibles.length} seleccionados`);
        if (!okConfirm) return;
        let ok = 0;
        let fail = 0;
        for (const row of elegibles) {
            try {
                await unlinkRow(row, { skipConfirm: true, skipReload: true });
                ok += 1;
            } catch (e) {
                fail += 1;
            }
        }
        if (ok > 0) showToast(`Desvinculados: ${ok}`, fail > 0 ? 'warning' : 'success');
        if (fail > 0) showToast(`No se pudieron desvincular ${fail}`, 'warning');
        await loadRows();
        if (window.InformesManager && typeof window.InformesManager.loadReports === 'function') {
            window.InformesManager.loadReports();
        }
    }

    function attachEvents() {
        const openBtn = document.getElementById('btnInformesRecibidos');
        const refreshBtn = document.getElementById('irRefreshBtn');
        const tbody = document.getElementById('irTableBody');
        if (!openBtn || !refreshBtn || !tbody) return;

        openBtn.addEventListener('click', async function () {
            const modalEl = document.getElementById('informesRecibidosModal');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            try {
                await loadRows();
            } catch (err) {
                showToast(err.message || 'Error cargando informes recibidos', 'danger');
            }
        });

        refreshBtn.addEventListener('click', async function () {
            try {
                await loadRows();
            } catch (err) {
                showToast(err.message || 'Error cargando informes recibidos', 'danger');
            }
        });

        const selectAllChk = document.getElementById('irSelectAllChk');
        if (selectAllChk) {
            selectAllChk.addEventListener('change', function () {
                if (this.checked) {
                    state.rows.forEach((r) => state.selectedIds.add(Number(r.id)));
                } else {
                    state.selectedIds.clear();
                }
                renderRows();
                updateBulkSelectionUi();
            });
        }

        const bulkLinkBtn = document.getElementById('irBulkLinkBtn');
        if (bulkLinkBtn) {
            bulkLinkBtn.addEventListener('click', async function () {
                const rows = selectedRows();
                if (!rows.length) return;

                // 1 seleccionado → modal de candidatos (vinculación manual)
                if (rows.length === 1) {
                    try {
                        await openLinkModal(rows[0].id);
                    } catch (err) {
                        showToast(err.message || 'No se pudo abrir vinculación', 'danger');
                    }
                    return;
                }

                // Múltiples seleccionados → reprocesamiento automático para esos IDs
                const pendientes = rows.filter((r) => String(r.estado || '') === 'recibido');
                if (!pendientes.length) {
                    showToast('Ninguno de los seleccionados está pendiente de vinculación.', 'warning');
                    return;
                }

                const countLabel = document.getElementById('irReprocesarCountLabel');
                if (countLabel) countLabel.textContent = pendientes.length;
                // Guardamos contexto para que irReprocesarConfirmBtn sepa qué hacer
                reprocesarContext = { mode: 'selected', ids: pendientes.map((r) => r.id), triggerBtn: bulkLinkBtn };
                const confirmModalEl = document.getElementById('irReprocesarConfirmModal');
                if (confirmModalEl && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getOrCreateInstance(confirmModalEl).show();
                }
            });
        }

        const bulkDiscardBtn = document.getElementById('irBulkDiscardBtn');
        if (bulkDiscardBtn) {
            bulkDiscardBtn.addEventListener('click', async function () {
                try {
                    await processBulkDiscard();
                } catch (err) {
                    showToast(err.message || 'No se pudo procesar descarte masivo', 'danger');
                }
            });
        }

        const bulkUnlinkBtn = document.getElementById('irBulkUnlinkBtn');
        if (bulkUnlinkBtn) {
            bulkUnlinkBtn.addEventListener('click', async function () {
                try {
                    await processBulkUnlink();
                } catch (err) {
                    showToast(err.message || 'No se pudo procesar desvinculación masiva', 'danger');
                }
            });
        }

        const bulkClearBtn = document.getElementById('irBulkClearBtn');
        if (bulkClearBtn) {
            bulkClearBtn.addEventListener('click', function () {
                state.selectedIds.clear();
                renderRows();
                updateBulkSelectionUi();
            });
        }

        const selectPendingsBtn = document.getElementById('irSelectPendingsBtn');
        if (selectPendingsBtn) {
            selectPendingsBtn.addEventListener('click', function () {
                state.selectedIds.clear();
                state.rows.forEach((r) => {
                    if (String(r.estado || '') === 'recibido') {
                        state.selectedIds.add(Number(r.id));
                    }
                });
                renderRows();
                updateBulkSelectionUi();
            });
        }

        const selectLinkedBtn = document.getElementById('irSelectLinkedBtn');
        if (selectLinkedBtn) {
            selectLinkedBtn.addEventListener('click', function () {
                state.selectedIds.clear();
                state.rows.forEach((r) => {
                    if (isRowVinculado(r)) {
                        state.selectedIds.add(Number(r.id));
                    }
                });
                renderRows();
                updateBulkSelectionUi();
            });
        }

        const selectPacsBlockedBtn = document.getElementById('irSelectPacsBlockedBtn');
        if (selectPacsBlockedBtn) {
            selectPacsBlockedBtn.addEventListener('click', function () {
                state.selectedIds.clear();
                state.rows.forEach((r) => {
                    if (isRowVinculado(r) && isInformeEnPacs(r)) {
                        state.selectedIds.add(Number(r.id));
                    }
                });
                renderRows();
                updateBulkSelectionUi();
            });
        }

        tbody.addEventListener('click', async function (event) {
            const rowChk = event.target.closest('input.ir-row-select');
            if (rowChk) {
                const rid = Number(rowChk.getAttribute('data-id'));
                if (rowChk.checked) {
                    state.selectedIds.add(rid);
                } else {
                    state.selectedIds.delete(rid);
                }
                updateBulkSelectionUi();
                return;
            }
            const btn = event.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const id = Number(btn.getAttribute('data-id'));
            const row = state.rows.find((r) => Number(r.id) === id);
            if (!row) return;

            try {
                if (action === 'open-pdf') {
                    if (!row.pdf_path) throw new Error('El informe no tiene PDF');
                    await openFileViewer('pdf', row.pdf_path);
                    return;
                }
                if (action === 'link') {
                    await openLinkModal(id);
                    return;
                }
                if (action === 'unlink') {
                    await unlinkRow(row);
                    return;
                }
                if (action === 'discard') {
                    await discardRow(row);
                }
            } catch (err) {
                showToast(err.message || 'Error en la acción', 'danger');
            }
        });

        const searchBtn = document.getElementById('irCandidateSearchBtn');
        if (searchBtn) {
            searchBtn.addEventListener('click', async function () {
                const query = (document.getElementById('irCandidateSearch')?.value || '').trim();
                await loadCandidatesForCurrentRow(query);
            });
        }

        const pacsSearchBtn = document.getElementById('irCandidatePacsSearchBtn');
        if (pacsSearchBtn) {
            pacsSearchBtn.addEventListener('click', async function () {
                const query = (document.getElementById('irCandidateSearch')?.value || '').trim();
                await loadCandidatesForCurrentRow(query, true);
            });
        }

        const candidateSearchInput = document.getElementById('irCandidateSearch');
        if (candidateSearchInput) {
            candidateSearchInput.addEventListener('keypress', async function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const query = (candidateSearchInput.value || '').trim();
                    await loadCandidatesForCurrentRow(query);
                }
            });
        }

        const candidatesBody = document.getElementById('irCandidatesBody');
        if (candidatesBody) {
            candidatesBody.addEventListener('click', async function (event) {
                const btn = event.target.closest('button[data-action="confirm-link"]');
                if (!btn) return;
                const candidateIndex = Number(btn.getAttribute('data-candidate-index'));
                try {
                    await confirmLinkStudy(candidateIndex);
                } catch (err) {
                    showToast(err.message || 'No se pudo vincular', 'danger');
                }
            });
        }

        const reprocesarBtn = document.getElementById('irReprocesarBtn');
        if (reprocesarBtn) {
            reprocesarBtn.addEventListener('click', function () {
                const pendingCount = state.rows.filter((r) => String(r.estado || '') === 'recibido').length;
                if (pendingCount === 0) {
                    showToast('No hay informes pendientes para reprocesar', 'info');
                    return;
                }
                const countLabel = document.getElementById('irReprocesarCountLabel');
                if (countLabel) countLabel.textContent = pendingCount;
                reprocesarContext = { mode: 'all', ids: [], triggerBtn: reprocesarBtn };
                const confirmModalEl = document.getElementById('irReprocesarConfirmModal');
                if (!confirmModalEl || typeof bootstrap === 'undefined') return;
                bootstrap.Modal.getOrCreateInstance(confirmModalEl).show();
            });
        }

        const reprocesarConfirmBtn = document.getElementById('irReprocesarConfirmBtn');
        if (reprocesarConfirmBtn) {
            reprocesarConfirmBtn.addEventListener('click', async function () {
                const confirmModalEl = document.getElementById('irReprocesarConfirmModal');
                if (confirmModalEl && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getInstance(confirmModalEl)?.hide();
                }

                const ctx = reprocesarContext;
                const triggerBtn = ctx.triggerBtn;
                const isSelected = ctx.mode === 'selected';
                const originalHtml = triggerBtn ? triggerBtn.innerHTML : '';
                if (triggerBtn) {
                    triggerBtn.disabled = true;
                    triggerBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (isSelected ? 'Vinculando...' : 'Reprocesando...');
                }

                const body = isSelected
                    ? { ids: ctx.ids, limit: 500 }
                    : { limit: 500 };

                try {
                    const res = await fetch(`${apiBase()}/reprocesar-pendientes.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify(body),
                    });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'Error en reprocesamiento');
                    const { procesados, vinculados, sin_candidato, score_bajo, errores } = data;
                    const prefix = isSelected ? 'Vinculados seleccionados' : 'Reprocesados';
                    showToast(
                        `${prefix}: ${procesados} | Vinculados: ${vinculados} | Sin candidato: ${sin_candidato} | Score bajo: ${score_bajo} | Errores: ${errores}`,
                        vinculados > 0 ? 'success' : 'info'
                    );
                    if (isSelected) state.selectedIds.clear();
                    await loadRows();
                    if (window.InformesManager && typeof window.InformesManager.loadReports === 'function') {
                        window.InformesManager.loadReports();
                    }
                } catch (err) {
                    showToast(err.message || 'Error en reprocesamiento', 'danger');
                } finally {
                    if (triggerBtn) {
                        triggerBtn.disabled = false;
                        triggerBtn.innerHTML = originalHtml;
                    }
                    updateBulkSelectionUi();
                }
            });
        }

        const resumen = document.getElementById('irVincularResumen');
        if (resumen) {
            resumen.addEventListener('click', function (event) {
                const btn = event.target.closest('button[data-action]');
                if (!btn || !state.currentLinkRow) return;
                const action = btn.getAttribute('data-action');
                if (action === 'open-current-pdf' && state.currentLinkRow.pdf_path) {
                    openFileViewer('pdf', state.currentLinkRow.pdf_path).catch((err) => {
                        showToast(err.message || 'No se pudo abrir PDF', 'danger');
                    });
                }
                if (action === 'open-current-txt' && state.currentLinkRow.txt_path) {
                    openFileViewer('txt', state.currentLinkRow.txt_path).catch((err) => {
                        showToast(err.message || 'No se pudo abrir TXT', 'danger');
                    });
                }
            });
        }

        // Cuando el modal de candidatos/vinculación se cierra, refrescar informes-manager
        // para que el botón "Enviar a PACS" refleje el estado actualizado (si el polling
        // no pudo hacer loadReports mientras el modal estaba abierto).
        const vincularModalEl = document.getElementById('irVincularModal');
        if (vincularModalEl) {
            vincularModalEl.addEventListener('hidden.bs.modal', function () {
                if (window.InformesManager && typeof window.InformesManager.loadReports === 'function') {
                    window.InformesManager.loadReports();
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        attachEvents();
        refreshPendientesCount({ force: true });
        updateBulkSelectionUi();
    });

    window.InformesRecibidosModal = {
        refreshPendientesCount: refreshPendientesCount
    };
})();
