/**
 * Modal: cola / trazabilidad informes_carpeta_archivos + badge en botón.
 */
(function () {
    'use strict';

    const IC_ATTENTION_THROTTLE_MS = 3500;
    let lastIcAttentionFetchAt = 0;

    function apiBase() {
        return window.location.pathname.includes('/components/')
            ? '../api/informes/carpeta'
            : 'api/informes/carpeta';
    }

    function showToast(message, type) {
        if (window.InformesManager && typeof window.InformesManager.showToast === 'function') {
            window.InformesManager.showToast(message, type || 'info');
            return;
        }
        alert(message);
    }

    function getToken() {
        if (window.InformesManager && typeof window.InformesManager.getSessionToken === 'function') {
            return window.InformesManager.getSessionToken();
        }
        return null;
    }

    async function fetchAttentionTotal() {
        const token = getToken();
        if (!token) {
            throw new Error('No hay sesión');
        }
        const res = await fetch(`${apiBase()}/count.php`, {
            headers: { Authorization: 'Bearer ' + token }
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.error || 'No se pudo obtener el contador');
        }
        return typeof data.attention_total === 'number' ? data.attention_total : 0;
    }

    function applyIcAttentionBadge(n) {
        const btnBadge = document.getElementById('icBtnBadge');
        if (!btnBadge) return;
        const display = n > 99 ? '99+' : String(n);
        btnBadge.textContent = display;
        if (n > 0) {
            btnBadge.classList.remove('d-none');
        } else {
            btnBadge.classList.add('d-none');
        }
    }

    async function refreshIcAttentionBadge(options) {
        const force = options && options.force === true;
        const now = Date.now();
        if (!force && now - lastIcAttentionFetchAt < IC_ATTENTION_THROTTLE_MS) {
            return;
        }
        lastIcAttentionFetchAt = now;

        const btn = document.getElementById('btnInformesCarpeta');
        if (!btn || btn.style.display === 'none') {
            applyIcAttentionBadge(0);
            return;
        }

        try {
            const total = await fetchAttentionTotal();
            applyIcAttentionBadge(total);
        } catch (err) {
            console.warn('[InformesCarpetaModal] Contador atención:', err);
            applyIcAttentionBadge(0);
        }
    }

    async function loadRows() {
        const token = getToken();
        if (!token) {
            throw new Error('No hay sesión');
        }
        const estado = document.getElementById('icEstadoFilter')?.value || '';
        const idp = (document.getElementById('icIdpacienteFilter')?.value || '').trim();
        const params = new URLSearchParams({ page: '1', limit: '100' });
        if (estado) params.set('estado', estado);
        if (idp) params.set('idpaciente', idp);

        const res = await fetch(`${apiBase()}/list.php?${params.toString()}`, {
            headers: { Authorization: 'Bearer ' + token }
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.error || 'Error al listar');
        }
        return data.data || [];
    }

    function renderRows(rows) {
        const tbody = document.getElementById('icTableBody');
        if (!tbody) return;
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Sin registros</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map((r) => {
            const fn = escapeHtml(r.nombre_archivo || '');
            const fd = r.fecha_deteccion || '';
            return `<tr>
                <td>${Number(r.id)}</td>
                <td>${escapeHtml(r.tipo)}</td>
                <td><span class="badge bg-secondary">${escapeHtml(r.estado)}</span></td>
                <td>${escapeHtml(r.idpaciente)}</td>
                <td class="text-break small" title="${escapeHtml(r.ruta_absoluta || '')}">${fn}</td>
                <td>${r.informe_recibido_id != null ? Number(r.informe_recibido_id) : '—'}</td>
                <td class="small">${escapeHtml(fd)}</td>
            </tr>`;
        }).join('');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    async function reloadTableAndBadge() {
        const rows = await loadRows();
        renderRows(rows);
        await refreshIcAttentionBadge({ force: true });
    }

    function showReprocesarResult(html, type) {
        const el = document.getElementById('icReprocesarResult');
        if (!el) return;
        el.className = `alert alert-${type} mb-3`;
        el.innerHTML = html;
        el.classList.remove('d-none');
    }

    function hideReprocesarResult() {
        const el = document.getElementById('icReprocesarResult');
        if (el) el.classList.add('d-none');
    }

    async function fetchStats() {
        const token = getToken();
        if (!token) throw new Error('No hay sesión');
        const res = await fetch(`${apiBase()}/reprocesar.php`, {
            headers: { Authorization: 'Bearer ' + token }
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) throw new Error(data.error || 'Error al obtener estadísticas');
        return { estadisticas: data.estadisticas, activo: data.activo };
    }

    async function runReprocesar(limite) {
        const token = getToken();
        if (!token) throw new Error('No hay sesión');
        const res = await fetch(`${apiBase()}/reprocesar.php`, {
            method: 'POST',
            headers: {
                Authorization: 'Bearer ' + token,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ limite: limite || 200 }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) throw new Error(data.error || 'Error al reprocesar');
        return data;
    }

    function setReprocesarBtnsDisabled(disabled) {
        ['icReprocesarBtn', 'icReprocesarTodoBtn', 'icStatsBtn'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = disabled;
        });
    }

    function attachEvents() {
        const openBtn            = document.getElementById('btnInformesCarpeta');
        const refreshBtn         = document.getElementById('icRefreshBtn');
        const statsBtn           = document.getElementById('icStatsBtn');
        const reprocesarBtn      = document.getElementById('icReprocesarBtn');
        const reprocesarTodoBtn  = document.getElementById('icReprocesarTodoBtn');
        if (!openBtn) return;

        openBtn.addEventListener('click', async function () {
            const modalEl = document.getElementById('informesCarpetaModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            hideReprocesarResult();
            try {
                await reloadTableAndBadge();
            } catch (e) {
                showToast(e.message || 'Error', 'danger');
            }
        });

        if (refreshBtn) {
            refreshBtn.addEventListener('click', async function () {
                hideReprocesarResult();
                try {
                    await reloadTableAndBadge();
                } catch (e) {
                    showToast(e.message || 'Error', 'danger');
                }
            });
        }

        if (statsBtn) {
            statsBtn.addEventListener('click', async function () {
                statsBtn.disabled = true;
                statsBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Consultando…';
                try {
                    const resp = await fetchStats();
                    const s = resp.estadisticas;
                    const avisoActivo = resp.activo === false
                        ? '<div class="mb-2 text-warning"><i class="fas fa-exclamation-triangle me-1"></i><strong>ic_activo = No</strong> — el procesamiento automático está desactivado. El reprocesador manual seguirá funcionando.</div>'
                        : '';
                    showReprocesarResult(
                        `${avisoActivo}<strong>Estadísticas de carpeta:</strong>
                        <ul class="mb-0 mt-1">
                            <li>Archivos en disco: <strong>${s.en_disco}</strong></li>
                            <li>Registrados en BD: <strong>${s.en_bd_total}</strong></li>
                            <li>Pendiente par / detectado: <strong>${s.en_bd_pendiente_par}</strong></li>
                            <li>Con error: <strong>${s.en_bd_error}</strong></li>
                            <li>Nuevos en disco (aprox.): <strong>${s.nuevos_disco_aprox}</strong></li>
                        </ul>`,
                        'info'
                    );
                } catch (e) {
                    showReprocesarResult(escapeHtml(e.message || 'Error'), 'danger');
                } finally {
                    statsBtn.disabled = false;
                    statsBtn.innerHTML = '<i class="fas fa-chart-bar me-1"></i>Estadísticas';
                }
            });
        }

        function buildReprocesarHtml(r, acum) {
            const f1 = r.fase1 || {};
            const f2 = r.fase2 || {};
            const hayMas = f2.hay_mas;
            const avisoInactivo = r.activo === false
                ? '<div class="mb-2 text-warning"><i class="fas fa-exclamation-triangle me-1"></i><strong>Nota:</strong> ic_activo = No. El reprocesador manual procesó igual. Active en Configuración para que el watcher/cron procese automáticamente.</div>'
                : '';
            const acumHtml = acum
                ? `<div class="mt-2 small text-muted">Acumulado: ingresados <strong>${acum.ingresados}</strong> | nuevos <strong>${acum.nuevos}</strong> | lotes <strong>${acum.lotes}</strong></div>`
                : '';
            return {
                html: `${avisoInactivo}<strong>Reprocesamiento completado.</strong>
                <div class="row mt-2 g-2">
                  <div class="col-sm-6">
                    <strong>Fase 1 – Pares en BD:</strong>
                    <ul class="mb-0">
                      <li>Revisados: ${f1.retried ?? '—'}</li>
                      <li>Ingresados: <strong class="text-success">${f1.ingresados ?? '—'}</strong></li>
                      <li>Omitidos (duplicado): ${f1.omitidos_duplicado ?? '—'}</li>
                      <li>Aún sin par: ${f1.pendiente_par_aun ?? '—'}</li>
                    </ul>
                  </div>
                  <div class="col-sm-6">
                    <strong>Fase 2 – Nuevos en disco:</strong>
                    <ul class="mb-0">
                      <li>Archivos en carpeta: ${f2.total_en_carpeta ?? '—'}</li>
                      <li>Procesados (lote): ${f2.procesados_lote ?? '—'}</li>
                      <li>Nuevos registrados: <strong class="text-success">${f2.nuevos ?? '—'}</strong></li>
                      <li>Omitidos: ${f2.omitidos ?? '—'}</li>
                      <li>Errores: ${f2.errores ?? '—'}</li>
                    </ul>
                  </div>
                </div>
                ${acumHtml}
                ${hayMas ? '<div class="mt-2 text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Hay más archivos. Presione <strong>Reprocesar</strong> o <strong>Todo</strong> para continuar.</div>' : ''}`,
                hayMas,
            };
        }

        if (reprocesarBtn) {
            reprocesarBtn.addEventListener('click', async function () {
                setReprocesarBtnsDisabled(true);
                reprocesarBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Reprocesando…';
                hideReprocesarResult();
                try {
                    const r = await runReprocesar(200);
                    const { html, hayMas } = buildReprocesarHtml(r, null);
                    showReprocesarResult(html, hayMas ? 'warning' : 'success');
                    await reloadTableAndBadge();
                } catch (e) {
                    showReprocesarResult(escapeHtml(e.message || 'Error al reprocesar'), 'danger');
                } finally {
                    setReprocesarBtnsDisabled(false);
                    reprocesarBtn.innerHTML = '<i class="fas fa-redo me-1"></i>Reprocesar';
                }
            });
        }

        if (reprocesarTodoBtn) {
            let todoAbort = false;
            reprocesarTodoBtn.addEventListener('click', async function () {
                if (reprocesarTodoBtn.dataset.running === '1') {
                    todoAbort = true;
                    reprocesarTodoBtn.innerHTML = '<i class="fas fa-stop me-1"></i>Deteniendo…';
                    return;
                }
                todoAbort = false;
                reprocesarTodoBtn.dataset.running = '1';
                setReprocesarBtnsDisabled(true);
                reprocesarTodoBtn.disabled = false; // sigue activo para poder detener
                reprocesarTodoBtn.innerHTML = '<i class="fas fa-stop me-1"></i>Detener';
                hideReprocesarResult();

                const acum = { ingresados: 0, nuevos: 0, lotes: 0 };
                let hayMas = true;
                let lastR = null;

                while (hayMas && !todoAbort) {
                    try {
                        showReprocesarResult(
                            `<i class="fas fa-spinner fa-spin me-2"></i>Procesando lote ${acum.lotes + 1}… (ingresados: ${acum.ingresados}, nuevos: ${acum.nuevos})`,
                            'info'
                        );
                        const r = await runReprocesar(300);
                        lastR = r;
                        acum.lotes++;
                        acum.ingresados += (r.fase1 || {}).ingresados || 0;
                        acum.nuevos     += (r.fase2 || {}).nuevos     || 0;
                        hayMas = (r.fase2 || {}).hay_mas || false;
                    } catch (e) {
                        showReprocesarResult(escapeHtml(e.message || 'Error'), 'danger');
                        break;
                    }
                }

                if (lastR) {
                    const { html } = buildReprocesarHtml(lastR, acum);
                    const finalMsg = todoAbort
                        ? '<div class="mb-2 text-warning"><i class="fas fa-hand-paper me-1"></i>Reprocesamiento detenido manualmente.</div>' + html
                        : html;
                    showReprocesarResult(finalMsg, hayMas ? 'warning' : 'success');
                }

                await reloadTableAndBadge();
                todoAbort = false;
                reprocesarTodoBtn.dataset.running = '0';
                setReprocesarBtnsDisabled(false);
                reprocesarTodoBtn.innerHTML = '<i class="fas fa-forward me-1"></i>Todo';
            });
        }

        const estadoEl = document.getElementById('icEstadoFilter');
        if (estadoEl) {
            estadoEl.addEventListener('change', async function () {
                try {
                    const rows = await loadRows();
                    renderRows(rows);
                } catch (e) {
                    showToast(e.message || 'Error', 'danger');
                }
            });
        }
        const idpEl = document.getElementById('icIdpacienteFilter');
        if (idpEl) {
            let t = null;
            idpEl.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(async () => {
                    try {
                        const rows = await loadRows();
                        renderRows(rows);
                    } catch (e) {
                        showToast(e.message || 'Error', 'danger');
                    }
                }, 400);
            });
        }
    }

    // ── Vista previa de pares ────────────────────────────────────────────────

    function fmtDate(ymd) {
        if (!ymd) return '—';
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd);
        if (m) return `${m[3]}/${m[2]}/${m[1]}`;
        return ymd;
    }

    function scoreBadge(score, tipo) {
        let cls = 'bg-secondary';
        let icon = '';
        if (tipo === 'fecha_exacta')      { cls = 'bg-success';  icon = '<i class="fas fa-check-circle me-1"></i>'; }
        else if (tipo === 'fecha_aproximada') { cls = 'bg-warning text-dark'; icon = '<i class="fas fa-exclamation-circle me-1"></i>'; }
        else                              { cls = 'bg-secondary'; icon = '<i class="fas fa-question-circle me-1"></i>'; }
        const label = tipo === 'fecha_exacta' ? 'Exacta' : tipo === 'fecha_aproximada' ? '±1 día' : 'Sin fecha';
        return `<span class="badge ${cls}" title="${escapeHtml(tipo)}">${icon}${score} — ${label}</span>`;
    }

    function openIcFileViewer(type, url) {
        const modalEl = document.getElementById('icFileViewerModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            window.open(url, '_blank');
            return;
        }
        const frame  = document.getElementById('icFileViewerFrame');
        const txtDiv = document.getElementById('icFileViewerTxt');
        const title  = document.getElementById('icFileViewerTitle');
        if (!frame || !txtDiv || !title) { window.open(url, '_blank'); return; }

        frame.classList.add('d-none');
        txtDiv.classList.add('d-none');
        frame.removeAttribute('src');
        txtDiv.textContent = '';

        if (type === 'pdf') {
            title.textContent = 'PDF — carpeta';
            frame.setAttribute('src', url);
            frame.classList.remove('d-none');
        } else {
            title.textContent = 'TXT DICOM — carpeta';
            const token = getToken();
            const headers = token ? { Authorization: 'Bearer ' + token } : {};
            fetch(url, { credentials: 'include', headers })
                .then(r => {
                    if (!r.ok) throw new Error(`HTTP ${r.status}`);
                    return r.text();
                })
                .then(t => { txtDiv.textContent = t; txtDiv.classList.remove('d-none'); })
                .catch(err => { txtDiv.textContent = 'Error al cargar: ' + err.message; txtDiv.classList.remove('d-none'); });
        }
        const inst = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: true });
        // Elevar el backdrop al nivel del visor cuando se muestre
        modalEl.addEventListener('show.bs.modal', function onShow() {
            modalEl.removeEventListener('show.bs.modal', onShow);
            requestAnimationFrame(() => {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 0) {
                    backdrops[backdrops.length - 1].style.zIndex = '1065';
                }
            });
        }, { once: true });
        inst.show();
    }

    function applyIcPreviewFilter() {
        const input = document.getElementById('icPreviewFilter');
        const onlyConfirmables = document.getElementById('icPreviewOnlyConfirmables');
        const q = (input ? input.value : '').trim().toLowerCase();
        const onlyConfirm = !!(onlyConfirmables && onlyConfirmables.checked);
        const tbl = document.getElementById('icPreviewParesTable');
        if (!tbl) return;
        let visible = 0;
        tbl.querySelectorAll('tbody tr').forEach(tr => {
            const text = tr.textContent.toLowerCase();
            const isDuplicate = tr.getAttribute('data-duplicate') === '1';
            const matchText = !q || text.includes(q);
            const matchConfirm = !onlyConfirm || !isDuplicate;
            const match = matchText && matchConfirm;
            tr.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        const info = document.getElementById('icPreviewInfo');
        if (info && tbl) {
            const total = tbl.querySelectorAll('tbody tr').length;
            info.textContent = q ? `${visible} de ${total} pares` : `${total} par${total !== 1 ? 'es' : ''} propuesto${total !== 1 ? 's' : ''}`;
        }
    }

    function renderPreviewPares(data) {
        const container  = document.getElementById('icPreviewPares');
        const sinParWrap = document.getElementById('icPreviewSinPar');
        const sinParBody = document.getElementById('icPreviewSinParBody');
        const badge      = document.getElementById('icPreviewBadge');
        const infoEl     = document.getElementById('icPreviewInfo');
        if (!container) return;

        const pares = data.pares || [];
        const sinPdf = data.sin_par_pdf || [];

        if (badge) {
            badge.textContent = pares.length;
            badge.classList.toggle('d-none', pares.length === 0);
        }
        if (infoEl) {
            const duplicados = pares.filter(p => p.ya_existe_en_ir).length;
            infoEl.textContent = `${pares.length} par${pares.length !== 1 ? 'es' : ''} propuesto${pares.length !== 1 ? 's' : ''} (${duplicados} ya existentes en IR), ${sinPdf.length} PDF sin par`;
        }

        if (!pares.length) {
            container.innerHTML = '<p class="text-muted small">No hay pares propuestos pendientes.</p>';
        } else {
            container.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle" id="icPreviewParesTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:35%">PDF</th>
                                <th style="width:10%">Score</th>
                                <th style="width:40%">TXT / DICOM</th>
                                <th style="width:15%">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${pares.map((p, i) => `
                            <tr id="icPreviewRow_${i}" data-pdf-id="${p.pdf.id}" data-txt-id="${p.txt.id}" data-duplicate="${p.ya_existe_en_ir ? '1' : '0'}">
                                <td>
                                    <div class="small fw-semibold text-break">${escapeHtml(p.pdf.nombre_archivo)}</div>
                                    <div class="small text-muted">${fmtDate(p.pdf.pdf_report_date)}</div>
                                    ${p.pdf.pdf_description ? `<div class="small text-info">${escapeHtml(p.pdf.pdf_description)}</div>` : ''}
                                    <div class="mt-1">
                                        <button class="btn btn-xs btn-outline-primary py-0 px-1 icVerPdfBtn"
                                                data-url="${escapeHtml(p.pdf.url)}" data-type="pdf">
                                            <i class="fas fa-file-pdf me-1"></i>Ver PDF
                                        </button>
                                    </div>
                                </td>
                                <td class="text-center">${scoreBadge(p.score, p.tipo_emparejamiento)}</td>
                                <td>
                                    <div class="small fw-semibold text-break">${escapeHtml(p.txt.nombre_archivo)}</div>
                                    <div class="small text-muted">Fecha: ${fmtDate(p.txt.procedure_date)} | Mod: <strong>${escapeHtml(p.txt.modality || '?')}</strong></div>
                                    ${p.txt.procedure_description ? `<div class="small text-info">${escapeHtml(p.txt.procedure_description)}</div>` : ''}
                                    <div class="small text-muted">ACCNO: ${escapeHtml(p.txt.accession_number || '—')}</div>
                                    <div class="mt-1">
                                        <button class="btn btn-xs btn-outline-secondary py-0 px-1 icVerTxtBtn"
                                                data-url="${escapeHtml(p.txt.url)}" data-type="txt">
                                            <i class="fas fa-file-alt me-1"></i>Ver TXT
                                        </button>
                                    </div>
                                </td>
                                <td class="text-center">
                                    ${p.ya_existe_en_ir
                                        ? `<span class="badge bg-warning text-dark mb-1" title="${p.motivo_duplicado === 'pdf_numero_informe' ? 'Mismo número de informe PDF para el mismo paciente' : 'Este ACCNO ya existe en informes_recibidos'}">
                                             <i class="fas fa-copy me-1"></i>Ya existe IR #${Number(p.informe_recibido_id_existente || 0)}
                                           </span>
                                           <div class="small text-muted mb-1">${p.motivo_duplicado === 'pdf_numero_informe' ? 'Duplicado por N° informe PDF' : 'Duplicado por ACCNO'}</div>
                                           <div>
                                             <button class="btn btn-sm btn-secondary" disabled title="No se puede confirmar: ya existe en Informes Recibidos">
                                               <i class="fas fa-ban me-1"></i>Duplicado
                                             </button>
                                           </div>`
                                        : `<button class="btn btn-sm btn-success icConfirmarParBtn"
                                              data-row="${i}"
                                              data-pdf-id="${p.pdf.id}"
                                              data-txt-id="${p.txt.id}"
                                              title="Confirmar este par e ingresar en Informes Recibidos">
                                             <i class="fas fa-check me-1"></i>Confirmar
                                           </button>`
                                    }
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>`;

            // Event delegation sobre la tabla para todos los botones de acción
            const tbl = document.getElementById('icPreviewParesTable');
            if (tbl) {
                tbl.addEventListener('click', async function (e) {
                    const btn = e.target.closest('button');
                    if (!btn) return;
                    if (btn.classList.contains('icConfirmarParBtn')) {
                        const pdfId = parseInt(btn.dataset.pdfId);
                        const txtId = parseInt(btn.dataset.txtId);
                        await confirmIcPar(pdfId, txtId, btn.dataset.row, btn);
                    } else if (btn.classList.contains('icVerPdfBtn') || btn.classList.contains('icVerTxtBtn')) {
                        openIcFileViewer(btn.dataset.type, btn.dataset.url);
                    }
                });
            }
        }

        // Conectar filtro de búsqueda
        const filterInput = document.getElementById('icPreviewFilter');
        if (filterInput) {
            filterInput.value = '';
            filterInput.oninput = applyIcPreviewFilter;
        }
        const onlyConfirmables = document.getElementById('icPreviewOnlyConfirmables');
        if (onlyConfirmables) {
            onlyConfirmables.checked = false;
            onlyConfirmables.onchange = applyIcPreviewFilter;
        }

        // Sin par PDFs
        if (sinParWrap && sinParBody) {
            if (sinPdf.length) {
                sinParBody.innerHTML = sinPdf.map(p => `<tr>
                    <td>${p.id}</td>
                    <td class="small text-break">${escapeHtml(p.nombre_archivo)}</td>
                    <td>${fmtDate(p.pdf_report_date)}</td>
                    <td class="small">${escapeHtml(p.pdf_description || '—')}</td>
                </tr>`).join('');
                sinParWrap.classList.remove('d-none');
            } else {
                sinParWrap.classList.add('d-none');
            }
        }
    }

    async function confirmIcPar(pdfId, txtId, rowIdx, btn) {
        const token = getToken();
        if (!token) return;
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        const alertEl = document.getElementById('icPreviewAlert');

        try {
            const res = await fetch(`${apiBase()}/preview-pares.php`, {
                method: 'POST',
                headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
                body: JSON.stringify({ pdf_id: pdfId, txt_id: txtId }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) throw new Error(data.error || 'Error al confirmar');

            // Ocultar la fila confirmada
            const row = document.getElementById(`icPreviewRow_${rowIdx}`);
            if (row) {
                row.style.opacity = '0.4';
                row.querySelectorAll('button').forEach(b => b.disabled = true);
                const tdAccion = row.querySelector('td:last-child');
                if (tdAccion) tdAccion.innerHTML = `<span class="badge bg-success"><i class="fas fa-check me-1"></i>IR #${data.informe_recibido_id}</span>`;
            }

            if (alertEl) {
                alertEl.className = `alert alert-${data.duplicado ? 'warning' : 'success'}`;
                alertEl.innerHTML = `<i class="fas fa-${data.duplicado ? 'exclamation-triangle' : 'check-circle'} me-1"></i>${escapeHtml(data.message)}`;
                alertEl.classList.remove('d-none');
            }
            await refreshIcAttentionBadge({ force: true });
        } catch (e) {
            btn.disabled = false;
            btn.innerHTML = orig;
            if (alertEl) {
                alertEl.className = 'alert alert-danger';
                alertEl.innerHTML = `<i class="fas fa-times-circle me-1"></i>${escapeHtml(e.message || 'Error')}`;
                alertEl.classList.remove('d-none');
            }
        }
    }

    function attachPreviewEvents() {
        const loadBtn = document.getElementById('icPreviewLoadBtn');
        if (loadBtn) {
            loadBtn.addEventListener('click', async function () {
                loadBtn.disabled = true;
                loadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Cargando…';
                const alertEl = document.getElementById('icPreviewAlert');
                if (alertEl) alertEl.classList.add('d-none');
                const token = getToken();
                try {
                    const res = await fetch(`${apiBase()}/preview-pares.php`, {
                        headers: { Authorization: 'Bearer ' + token }
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.success) throw new Error(data.error || 'Error al cargar');
                    renderPreviewPares(data);
                } catch (e) {
                    if (alertEl) {
                        alertEl.className = 'alert alert-danger';
                        alertEl.innerHTML = `<i class="fas fa-times-circle me-1"></i>${escapeHtml(e.message || 'Error')}`;
                        alertEl.classList.remove('d-none');
                    }
                } finally {
                    loadBtn.disabled = false;
                    loadBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Recargar propuestas';
                }
            });
        }
    }

    function onDomReady() {
        attachEvents();
        attachPreviewEvents();
        refreshIcAttentionBadge({ force: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onDomReady);
    } else {
        onDomReady();
    }

    window.InformesCarpetaModal = {
        refreshAttentionBadge: refreshIcAttentionBadge
    };
})();
