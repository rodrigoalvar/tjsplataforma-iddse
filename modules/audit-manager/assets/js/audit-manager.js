/**
 * UI del módulo Audit Manager — filtros servidor con «Filtrar», filtro local en tiempo real,
 * orden por columnas con persistencia (localStorage), desplegable de usuarios.
 */
(function () {
    const API_BASE = 'modules/audit-manager/api/';

    const LS_CONN_SORT = 'audit_manager_connections_sort_v1';
    const LS_CONN_LIVE = 'audit_manager_connections_live_v1';
    const LS_EV_SORT = 'audit_manager_events_sort_v1';
    const LS_EV_LIVE = 'audit_manager_events_live_v1';
    const LS_PORT_SORT = 'audit_manager_portal_sort_v1';
    const LS_PORT_LIVE = 'audit_manager_portal_live_v1';
    const LS_AUD_SORT = 'audit_manager_audios_sort_v1';
    const LS_AUD_LIVE = 'audit_manager_audios_live_v1';
    const PORTAL_PURGE_CONFIRM = 'VACIAR_AUDITORIA_PORTAL';
    const AUDIOS_PAGE_LIMIT = 50;
    const AUDIOS_FILL_MAX_DEPTH = 5;

    let connRawRows = [];
    let evRawRows = [];
    let portalRawRows = [];
    let audRawRows = [];
    let audSelectedIds = new Set();
    let usersOptionsLoaded = false;

    const connSort = loadSort(LS_CONN_SORT, 'fecha_creacion', 'desc');
    const evSort = loadSort(LS_EV_SORT, 'created_at', 'desc');
    const portSort = loadSort(LS_PORT_SORT, 'created_at', 'desc');
    const audSort = loadSort(LS_AUD_SORT, 'fecha_creacion', 'desc');

    function loadSort(key, defCol, defDir) {
        try {
            const raw = localStorage.getItem(key);
            if (!raw) return { column: defCol, direction: defDir };
            const o = JSON.parse(raw);
            if (o && o.column) return { column: o.column, direction: o.direction === 'desc' ? 'desc' : 'asc' };
        } catch (e) {}
        return { column: defCol, direction: defDir };
    }

    function saveSort(key, cfg) {
        try {
            localStorage.setItem(key, JSON.stringify({ column: cfg.column, direction: cfg.direction }));
        } catch (e) {}
    }

    function el(id) {
        return document.getElementById(id);
    }

    function debounce(fn, ms) {
        let t;
        return function () {
            clearTimeout(t);
            const a = arguments;
            const th = this;
            t = setTimeout(function () { fn.apply(th, a); }, ms);
        };
    }

    async function apiGet(path) {
        const r = await fetch(API_BASE + path, { credentials: 'same-origin' });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
            throw new Error(j.error || j.message || ('HTTP ' + r.status));
        }
        return j;
    }

    async function apiPost(path, body) {
        const r = await fetch(API_BASE + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
            throw new Error(j.error || j.message || ('HTTP ' + r.status));
        }
        return j;
    }

    function fmtDate(s) {
        if (!s) return '—';
        try {
            return new Date(String(s).replace(' ', 'T')).toLocaleString();
        } catch (e) {
            return s;
        }
    }

    function fmtActiveSeconds(sec) {
        const n = parseInt(sec, 10);
        if (isNaN(n) || n < 0) {
            return '—';
        }
        if (n < 60) {
            return n + ' s';
        }
        const m = Math.floor(n / 60);
        if (m < 60) {
            return m + ' min ' + (n % 60) + ' s';
        }
        const h = Math.floor(m / 60);
        const mm = m % 60;
        return h + ' h ' + mm + ' min';
    }

    function fmtAudioDuration(sec) {
        if (sec == null || sec === '') {
            return '—';
        }
        const n = parseFloat(sec);
        if (isNaN(n) || n < 0) {
            return '—';
        }
        const whole = Math.floor(n);
        if (whole < 60) {
            return (n % 1 !== 0 ? n.toFixed(1) : String(whole)) + ' s';
        }
        const m = Math.floor(whole / 60);
        const s = whole % 60;
        if (m < 60) {
            return m + ' min ' + s + ' s';
        }
        const h = Math.floor(m / 60);
        const mm = m % 60;
        return h + ' h ' + mm + ' min';
    }

    function escapeHtml(t) {
        if (!t) return '';
        const d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    function updatePortalStatsBar(data) {
        const bar = el('auditPortalStatsBar');
        if (!bar) {
            return;
        }
        if (!data || !data.stats || data.portal_table_configured === false) {
            bar.classList.add('d-none');
            return;
        }
        bar.classList.remove('d-none');
        const s = data.stats;
        el('auditPortalStatAccess').textContent = 'Cargas de página: ' + (s.page_access_only != null ? s.page_access_only : '—');
        el('auditPortalStatSearch').textContent = 'Búsquedas con texto: ' + (s.with_search_text != null ? s.with_search_text : '—');
        el('auditPortalStatTotal').textContent = 'Registros coincidentes: ' + (s.period_total != null ? s.period_total : '—');
    }

    function updateAudiosStatsBar(data) {
        const bar = el('auditAudiosStatsBar');
        if (!bar) {
            return;
        }
        if (!data || !data.stats || data.audios_table_configured === false) {
            bar.classList.add('d-none');
            return;
        }
        bar.classList.remove('d-none');
        const s = data.stats;
        el('auditAudiosStatCount').textContent = 'Audios: ' + (s.total_audios != null ? s.total_audios : '—');
        el('auditAudiosStatMinutes').textContent = 'Minutos totales: ' + (s.total_minutes != null ? s.total_minutes : '—');
    }

    function showAuditToast(message, variant) {
        const c = el('auditToastContainer');
        if (!c || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            return;
        }
        const v = variant === 'success' ? 'text-bg-success' : variant === 'danger' ? 'text-bg-danger' : 'text-bg-secondary';
        const tid = 'auditToast_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
        const wrap = document.createElement('div');
        wrap.id = tid;
        wrap.className = 'toast align-items-center border-0 ' + v;
        wrap.setAttribute('role', 'alert');
        wrap.innerHTML =
            '<div class="d-flex">' +
            '<div class="toast-body">' +
            escapeHtml(String(message || '')) +
            '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>' +
            '</div>';
        c.appendChild(wrap);
        const t = bootstrap.Toast.getOrCreateInstance(wrap, { delay: 4500 });
        t.show();
        wrap.addEventListener('hidden.bs.toast', function () {
            wrap.remove();
        });
    }

    /**
     * @param {{ title: string, message: string, confirmBtnText?: string }} opts
     * @returns {Promise<{ ok: boolean, reason: string }>}
     */
    function showRevokeConfirmDialog(opts) {
        return new Promise(function (resolve) {
            const modalEl = el('auditRevokeModal');
            const btnConfirm = el('auditRevokeModalConfirm');
            if (!modalEl || !btnConfirm || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                resolve({ ok: false, reason: '' });
                return;
            }
            el('auditRevokeModalTitle').textContent = opts.title || 'Confirmar';
            el('auditRevokeModalMsg').textContent = opts.message || '';
            el('auditRevokeReason').value = '';
            btnConfirm.textContent = opts.confirmBtnText || 'Confirmar';

            let settled = false;
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            function onHidden() {
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                btnConfirm.removeEventListener('click', onConfirmClick);
                if (!settled) {
                    settled = true;
                    resolve({ ok: false, reason: '' });
                }
            }

            function onConfirmClick() {
                if (settled) {
                    return;
                }
                settled = true;
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                btnConfirm.removeEventListener('click', onConfirmClick);
                const reason = el('auditRevokeReason').value.trim();
                modal.hide();
                resolve({ ok: true, reason: reason });
            }

            modalEl.addEventListener('hidden.bs.modal', onHidden);
            btnConfirm.addEventListener('click', onConfirmClick);
            modal.show();
        });
    }

    function connRowSearchText(s) {
        const userLabel = (((s.nombre || '') + ' ' + (s.apellido || '')).trim() + ' ' + (s.email || '') + ' #' + s.usuario_id).toLowerCase();
        const parts = [
            String(s.session_id),
            userLabel,
            String(s.activa),
            fmtDate(s.fecha_creacion),
            fmtDate(s.ultima_actividad),
            fmtDate(s.fecha_cierre),
            fmtActiveSeconds(s.active_seconds),
            String(s.ip_login || ''),
            String(s.user_agent_login || ''),
        ];
        return parts.join(' ').toLowerCase();
    }

    function filterByLiveText(rows, liveEl) {
        const q = (liveEl && liveEl.value ? liveEl.value : '').trim().toLowerCase();
        if (!q) return rows.slice();
        return rows.filter(function (r) {
            return connRowSearchText(r).indexOf(q) !== -1;
        });
    }

    function eventRowSearchText(e) {
        const u = ((e.nombre || '') + ' ' + (e.apellido || '') + ' ' + (e.email || '') + ' #' + e.user_id).toLowerCase();
        const meta = typeof e.metadata === 'object' ? JSON.stringify(e.metadata) : String(e.metadata || '');
        return [
            fmtDate(e.created_at),
            u,
            String(e.action_key || ''),
            String(e.rtt_ms != null ? e.rtt_ms : ''),
            String(e.client_duration_ms != null ? e.client_duration_ms : ''),
            meta.toLowerCase(),
        ].join(' ').toLowerCase();
    }

    function filterEventsLive(rows) {
        const q = (el('auditEventsLiveFilter').value || '').trim().toLowerCase();
        if (!q) return rows.slice();
        return rows.filter(function (r) {
            return eventRowSearchText(r).indexOf(q) !== -1;
        });
    }

    function portalRowSearchText(v) {
        return [
            fmtDate(v.created_at),
            String(v.ip_address || ''),
            String(v.page_url || ''),
            String(v.patient_query || ''),
            String(v.user_agent || ''),
            String(v.referer || ''),
        ].join(' ').toLowerCase();
    }

    function filterPortalLive(rows) {
        const q = (el('auditPortalLiveFilter').value || '').trim().toLowerCase();
        if (!q) return rows.slice();
        return rows.filter(function (r) {
            return portalRowSearchText(r).indexOf(q) !== -1;
        });
    }

    function audioRowSearchText(a) {
        const u = ((a.user_label || '') + ' ' + (a.email || '') + ' #' + a.usuario_id).toLowerCase();
        const fileName = String(a.display_file_name || a.nombre_archivo || '');
        return [
            String(a.id),
            u,
            String(a.origin_label || ''),
            String(a.estudio_id || ''),
            String(a.study_patient_name || ''),
            String(a.study_modality || ''),
            fmtDate(a.fecha_creacion),
            String(a.duracion_segundos != null ? a.duracion_segundos : ''),
            String(a.tamano_label || ''),
            String(a.format_label || ''),
            String(a.tipo_mime || ''),
            String(a.estado != null ? a.estado : ''),
            a.ftp_sent ? 'ftp mp3' : '',
            String(a.transcription_queue_status || ''),
            fileName,
            String(a.informe_id != null ? a.informe_id : ''),
        ].join(' ').toLowerCase();
    }

    function filterAudiosLive(rows) {
        const liveEl = el('auditAudiosLiveFilter');
        const q = (liveEl && liveEl.value ? liveEl.value : '').trim().toLowerCase();
        if (!q) return rows.slice();
        return rows.filter(function (r) {
            return audioRowSearchText(r).indexOf(q) !== -1;
        });
    }

    function estadoRank(s) {
        const online = parseInt(s.likely_online, 10) === 1;
        const valid = parseInt(s.session_valid, 10) === 1;
        if (online) return 3;
        if (valid) return 2;
        return 1;
    }

    function sortConnections(rows, cfg) {
        if (!cfg.column) return rows;
        const dir = cfg.direction === 'asc' ? 1 : -1;
        return rows.slice().sort(function (a, b) {
            let va;
            let vb;
            switch (cfg.column) {
                case 'session_id':
                    va = parseInt(a.session_id, 10) || 0;
                    vb = parseInt(b.session_id, 10) || 0;
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'user':
                    va = (((a.nombre || '') + ' ' + (a.apellido || '') + ' ' + (a.email || ''))).toLowerCase();
                    vb = (((b.nombre || '') + ' ' + (b.apellido || '') + ' ' + (b.email || ''))).toLowerCase();
                    break;
                case 'estado':
                    va = estadoRank(a);
                    vb = estadoRank(b);
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'activa':
                    va = parseInt(a.activa, 10) || 0;
                    vb = parseInt(b.activa, 10) || 0;
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'fecha_creacion':
                case 'ultima_actividad':
                case 'fecha_cierre':
                    va = String(a[cfg.column] || '');
                    vb = String(b[cfg.column] || '');
                    break;
                case 'active_seconds':
                    va = parseInt(a.active_seconds, 10) || 0;
                    vb = parseInt(b.active_seconds, 10) || 0;
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'ip_login':
                    va = String(a.ip_login || '').toLowerCase();
                    vb = String(b.ip_login || '').toLowerCase();
                    break;
                case 'user_agent_login':
                    va = String(a.user_agent_login || '').toLowerCase();
                    vb = String(b.user_agent_login || '').toLowerCase();
                    break;
                default:
                    return 0;
            }
            if (va < vb) return -dir;
            if (va > vb) return dir;
            return 0;
        });
    }

    function sortEvents(rows, cfg) {
        if (!cfg.column || cfg.column === 'meta') return rows;
        const dir = cfg.direction === 'asc' ? 1 : -1;
        return rows.slice().sort(function (a, b) {
            let va;
            let vb;
            switch (cfg.column) {
                case 'created_at':
                    va = String(a.created_at || '');
                    vb = String(b.created_at || '');
                    break;
                case 'user':
                    va = ((a.nombre || '') + ' ' + (a.apellido || '') + ' ' + (a.email || '')).toLowerCase();
                    vb = ((b.nombre || '') + ' ' + (b.apellido || '') + ' ' + (b.email || '')).toLowerCase();
                    break;
                case 'action_key':
                    va = String(a.action_key || '').toLowerCase();
                    vb = String(b.action_key || '').toLowerCase();
                    break;
                case 'rtt_ms':
                case 'client_duration_ms':
                    va = parseInt(a[cfg.column], 10);
                    vb = parseInt(b[cfg.column], 10);
                    if (isNaN(va)) va = -1;
                    if (isNaN(vb)) vb = -1;
                    return va < vb ? -dir : va > vb ? dir : 0;
                default:
                    return 0;
            }
            if (va < vb) return -dir;
            if (va > vb) return dir;
            return 0;
        });
    }

    function sortPortal(rows, cfg) {
        if (!cfg.column) return rows;
        const dir = cfg.direction === 'asc' ? 1 : -1;
        return rows.slice().sort(function (a, b) {
            const col = cfg.column;
            let va = String(a[col] || '').toLowerCase();
            let vb = String(b[col] || '').toLowerCase();
            if (col === 'created_at') {
                va = String(a.created_at || '');
                vb = String(b.created_at || '');
            }
            if (va < vb) return -dir;
            if (va > vb) return dir;
            return 0;
        });
    }

    function sortAudios(rows, cfg) {
        if (!cfg.column) return rows;
        const dir = cfg.direction === 'asc' ? 1 : -1;
        return rows.slice().sort(function (a, b) {
            let va;
            let vb;
            switch (cfg.column) {
                case 'id':
                    va = parseInt(a.id, 10) || 0;
                    vb = parseInt(b.id, 10) || 0;
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'user':
                    va = ((a.user_label || '') + ' ' + (a.email || '')).toLowerCase();
                    vb = ((b.user_label || '') + ' ' + (b.email || '')).toLowerCase();
                    break;
                case 'origin_label':
                    va = String(a.origin_label || '').toLowerCase();
                    vb = String(b.origin_label || '').toLowerCase();
                    break;
                case 'estudio_id':
                    va = String(a.estudio_id || '').toLowerCase();
                    vb = String(b.estudio_id || '').toLowerCase();
                    break;
                case 'study_patient_name':
                    va = String(a.study_patient_name || '').toLowerCase();
                    vb = String(b.study_patient_name || '').toLowerCase();
                    break;
                case 'study_modality':
                    va = String(a.study_modality || '').toLowerCase();
                    vb = String(b.study_modality || '').toLowerCase();
                    break;
                case 'fecha_creacion':
                    va = String(a.fecha_creacion || '');
                    vb = String(b.fecha_creacion || '');
                    break;
                case 'duracion_segundos':
                    va = parseFloat(a.duracion_segundos);
                    vb = parseFloat(b.duracion_segundos);
                    if (isNaN(va)) va = -1;
                    if (isNaN(vb)) vb = -1;
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'tamano_bytes':
                    va = a.tamano_bytes != null ? parseInt(a.tamano_bytes, 10) : -1;
                    vb = b.tamano_bytes != null ? parseInt(b.tamano_bytes, 10) : -1;
                    if (isNaN(va)) va = -1;
                    if (isNaN(vb)) vb = -1;
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'format_label':
                    va = String(a.format_label || '').toLowerCase();
                    vb = String(b.format_label || '').toLowerCase();
                    break;
                case 'estado':
                    va = String(a.estado != null ? a.estado : '').toLowerCase();
                    vb = String(b.estado != null ? b.estado : '').toLowerCase();
                    break;
                case 'ftp_sent':
                    va = a.ftp_sent ? 1 : 0;
                    vb = b.ftp_sent ? 1 : 0;
                    return va < vb ? -dir : va > vb ? dir : 0;
                case 'transcription_queue_status':
                    va = String(a.transcription_queue_status || '').toLowerCase();
                    vb = String(b.transcription_queue_status || '').toLowerCase();
                    break;
                case 'nombre_archivo':
                    va = String(a.display_file_name || a.nombre_archivo || '').toLowerCase();
                    vb = String(b.display_file_name || b.nombre_archivo || '').toLowerCase();
                    break;
                default:
                    return 0;
            }
            if (va < vb) return -dir;
            if (va > vb) return dir;
            return 0;
        });
    }

    function updateSortIcons(tableId, cfg) {
        const table = el(tableId);
        if (!table) return;
        table.querySelectorAll('thead th.sortable').forEach(function (header) {
            const icon = header.querySelector('.sort-icon');
            if (!icon) return;
            const column = header.getAttribute('data-column');
            if (cfg.column === column) {
                icon.className = cfg.direction === 'asc' ? 'fas fa-sort-up sort-icon' : 'fas fa-sort-down sort-icon';
            } else {
                icon.className = 'fas fa-sort sort-icon';
            }
        });
    }

    function bindTableSort(tableId, cfg, lsKey, onSort) {
        const table = el(tableId);
        if (!table || table.dataset.sortBound === '1') return;
        table.dataset.sortBound = '1';
        const thead = table.querySelector('thead');
        if (!thead) return;
        thead.addEventListener('click', function (e) {
            const th = e.target.closest('th.sortable');
            if (!th) return;
            const column = th.getAttribute('data-column');
            if (!column) return;
            if (cfg.column === column) {
                cfg.direction = cfg.direction === 'asc' ? 'desc' : 'asc';
            } else {
                cfg.column = column;
                cfg.direction = 'asc';
            }
            saveSort(lsKey, cfg);
            updateSortIcons(tableId, cfg);
            if (typeof onSort === 'function') onSort();
        });
    }

    async function ensureUserSelectsFilled() {
        if (usersOptionsLoaded) return;
        const data = await apiGet('users-options.php');
        const users = data.users || [];
        ['auditConnUserSelect', 'auditEventsUserSelect', 'auditAudiosUserSelect'].forEach(function (selId) {
            const sel = el(selId);
            if (!sel) return;
            const keep = sel.value;
            while (sel.options.length > 1) sel.remove(1);
            users.forEach(function (u) {
                const opt = document.createElement('option');
                opt.value = String(u.id);
                opt.textContent = u.label || ('#' + u.id);
                sel.appendChild(opt);
            });
            if (keep && sel.querySelector('option[value="' + keep + '"]')) {
                sel.value = keep;
            }
        });
        usersOptionsLoaded = true;
    }

    function renderConnectionsRows(rows) {
        const tbody = el('auditConnectionsBody');
        const live = el('auditConnLiveFilter');
        const filtered = filterByLiveText(rows, live);
        const sorted = sortConnections(filtered, connSort);
        el('auditConnLiveHint').textContent = sorted.length === rows.length
            ? 'Mostrando ' + sorted.length + ' fila(s) de esta página.'
            : 'Mostrando ' + sorted.length + ' de ' + rows.length + ' (filtro local).';

        if (!sorted.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-muted">Sin filas (ajustá filtros o la consulta)</td></tr>';
            return;
        }

        tbody.innerHTML = sorted.map(function (s) {
            const online = parseInt(s.likely_online, 10) === 1;
            const valid = parseInt(s.session_valid, 10) === 1;
            const badge = online
                ? '<span class="badge bg-success">En línea</span>'
                : (valid ? '<span class="badge bg-warning text-dark">Sesión abierta</span>' : '<span class="badge bg-secondary">Cerrada / exp.</span>');
            const activa = parseInt(s.activa, 10) === 1 ? 'Sí' : 'No';
            const ua = (s.user_agent_login || '').substring(0, 55) + ((s.user_agent_login || '').length > 55 ? '…' : '');
            const userLabel = (((s.nombre || '') + ' ' + (s.apellido || '')).trim()) || '(sin datos)';
            const email = s.email || '';
            const actions = valid
                ? ('<button type="button" class="btn btn-sm btn-outline-danger audit-revoke-one" data-sid="' + s.session_id + '" data-uid="' + s.usuario_id + '">Cerrar</button> ' +
                    '<button type="button" class="btn btn-sm btn-outline-warning audit-revoke-all" data-uid="' + s.usuario_id + '">Todas</button>')
                : '<span class="text-muted small">—</span>';

            return (
                '<tr>' +
                '<td>' + s.session_id + '</td>' +
                '<td>' + escapeHtml(userLabel) + '<br><small class="text-muted">#' + s.usuario_id + (email ? ' · ' + escapeHtml(email) : '') + '</small></td>' +
                '<td>' + badge + '</td>' +
                '<td>' + activa + '</td>' +
                '<td>' + fmtDate(s.fecha_creacion) + '</td>' +
                '<td>' + fmtDate(s.ultima_actividad) + '</td>' +
                '<td><small title="Suma con pestaña visible e interacción (ver audit-session-activity.js)">' + escapeHtml(fmtActiveSeconds(s.active_seconds)) + '</small></td>' +
                '<td>' + fmtDate(s.fecha_cierre) + '</td>' +
                '<td>' + escapeHtml(s.ip_login || '—') + '</td>' +
                '<td><small>' + escapeHtml(ua || '—') + '</small></td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
        }).join('');

        tbody.querySelectorAll('.audit-revoke-one').forEach(function (btn) {
            btn.addEventListener('click', function () {
                revokeSession(parseInt(btn.getAttribute('data-sid'), 10));
            });
        });
        tbody.querySelectorAll('.audit-revoke-all').forEach(function (btn) {
            btn.addEventListener('click', function () {
                revokeAllForUser(parseInt(btn.getAttribute('data-uid'), 10));
            });
        });
    }

    async function loadConnections() {
        const tbody = el('auditConnectionsBody');
        tbody.innerHTML = '<tr><td colspan="11" class="text-muted">Cargando…</td></tr>';
        const page = parseInt(el('auditConnPage').value, 10) || 1;
        const uid = el('auditConnUserId').value.trim();
        const userQ = el('auditConnUserQ').value.trim();
        const from = el('auditConnDateFrom').value;
        const to = el('auditConnDateTo').value;
        const activeOnly = el('auditConnActiveOnly').checked;
        const onlineMin = el('auditConnOnlineMin').value || '5';

        let q = 'connections.php?page=' + page + '&limit=100&online_within_minutes=' + encodeURIComponent(onlineMin);
        if (uid) q += '&user_id=' + encodeURIComponent(uid);
        if (userQ) q += '&user_q=' + encodeURIComponent(userQ);

        if (from) q += '&from=' + encodeURIComponent(from);
        if (to) q += '&to=' + encodeURIComponent(to);
        if (activeOnly) q += '&active_only=1';

        try {
            await ensureUserSelectsFilled();
            const data = await apiGet(q);
            connRawRows = data.connections || [];
            const f = data.filters || {};
            el('auditConnTotal').textContent = 'Total servidor: ' + (data.total || 0) + ' · Página ' + (data.page || 1) + ' (Filtrar = nueva consulta)';
            el('auditConnectionsHint').textContent = activeOnly
                ? 'Solo sesiones válidas. “En línea” = última actividad en los últimos ' + (f.online_within_minutes || onlineMin) + ' min. Tiempo activo = uso con pestaña visible e interacción (heartbeat). Lista desplegable e ID filtran en el servidor al usar Filtrar.'
                : 'Rango por inicio de sesión (fecha_creacion). Tiempo activo = acumulado por el cliente (ver install.php + auth-middleware). El cuadro de abajo filtra en tiempo real solo sobre las filas de esta página.';

            updateSortIcons('auditConnectionsTable', connSort);
            renderConnectionsRows(connRawRows);
        } catch (e) {
            connRawRows = [];
            tbody.innerHTML = '<tr><td colspan="11" class="text-danger">' + escapeHtml(e.message) + '</td></tr>';
        }
    }

    async function revokeSession(sessionId) {
        const d = await showRevokeConfirmDialog({
            title: 'Cerrar sesión',
            message: '¿Cerrar esta sesión? El usuario deberá volver a iniciar sesión.',
            confirmBtnText: 'Cerrar sesión',
        });
        if (!d.ok) {
            return;
        }
        try {
            await apiPost('revoke.php', { session_id: sessionId, reason: d.reason });
            showAuditToast('Sesión cerrada correctamente.', 'success');
            await loadConnections();
        } catch (err) {
            showAuditToast(err.message || 'Error al cerrar la sesión', 'danger');
        }
    }

    async function revokeAllForUser(userId) {
        const d = await showRevokeConfirmDialog({
            title: 'Cerrar todas las sesiones',
            message:
                '¿Cerrar TODAS las sesiones activas de este usuario? Afecta a todos los dispositivos donde tenga sesión abierta.',
            confirmBtnText: 'Cerrar todas',
        });
        if (!d.ok) {
            return;
        }
        try {
            const res = await apiPost('revoke.php', { user_id: userId, revoke_all_for_user: true, reason: d.reason });
            showAuditToast('Sesiones cerradas: ' + (res.revoked || 0), 'success');
            await loadConnections();
        } catch (err) {
            showAuditToast(err.message || 'Error al cerrar sesiones', 'danger');
        }
    }

    function renderEventsRows(rows) {
        const tbody = el('auditEventsBody');
        const filtered = filterEventsLive(rows);
        const sorted = sortEvents(filtered, evSort);

        if (!sorted.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Sin filas</td></tr>';
            return;
        }

        tbody.innerHTML = sorted.map(function (e) {
            let meta = '';
            if (e.metadata && typeof e.metadata === 'object') {
                try {
                    meta = '<pre class="small mb-0">' + escapeHtml(JSON.stringify(e.metadata, null, 0)) + '</pre>';
                } catch (x) {
                    meta = '';
                }
            } else if (e.metadata) {
                meta = '<small>' + escapeHtml(String(e.metadata)) + '</small>';
            }
            return (
                '<tr><td>' + fmtDate(e.created_at) + '</td>' +
                '<td>' + escapeHtml((e.nombre || '') + ' ' + (e.apellido || '')) + '<br><small>#' + e.user_id + '</small></td>' +
                '<td><code>' + escapeHtml(e.action_key) + '</code></td>' +
                '<td>' + (e.rtt_ms != null ? e.rtt_ms + ' ms' : '—') + '</td>' +
                '<td>' + (e.client_duration_ms != null ? e.client_duration_ms + ' ms' : '—') + '</td>' +
                '<td>' + meta + '</td></tr>'
            );
        }).join('');
    }

    async function loadEvents() {
        const tbody = el('auditEventsBody');
        tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Cargando…</td></tr>';
        const page = parseInt(el('auditEventsPage').value, 10) || 1;
        const userId = el('auditFilterUserId').value.trim();
        const userQ = el('auditFilterUserQ').value.trim();
        const action = el('auditFilterAction').value.trim();
        const from = el('auditFilterFrom').value;
        const to = el('auditFilterTo').value;
        let q = 'list.php?page=' + page + '&limit=40';
        if (userId) q += '&user_id=' + encodeURIComponent(userId);
        if (userQ) q += '&user_q=' + encodeURIComponent(userQ);
        if (action) q += '&action_key=' + encodeURIComponent(action);
        if (from) q += '&from=' + encodeURIComponent(from);
        if (to) q += '&to=' + encodeURIComponent(to);
        try {
            await ensureUserSelectsFilled();
            const data = await apiGet(q);
            el('auditEventsTotal').textContent = 'Total: ' + (data.total || 0);
            evRawRows = data.events || [];
            updateSortIcons('auditEventsTable', evSort);
            renderEventsRows(evRawRows);
        } catch (err) {
            evRawRows = [];
            tbody.innerHTML = '<tr><td colspan="6" class="text-danger">' + escapeHtml(err.message) + '</td></tr>';
        }
    }

    function renderPortalRows(rows) {
        const tbody = el('auditPortalBody');
        const filtered = filterPortalLive(rows);
        const sorted = sortPortal(filtered, portSort);
        if (!sorted.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Sin filas</td></tr>';
            return;
        }
        tbody.innerHTML = sorted.map(function (v) {
            const ua = (v.user_agent || '').substring(0, 70) + ((v.user_agent || '').length > 70 ? '…' : '');
            const ref = (v.referer || '').substring(0, 60) + ((v.referer || '').length > 60 ? '…' : '');
            const pq = v.patient_query != null && String(v.patient_query) !== '' ? escapeHtml(String(v.patient_query)) : '—';
            return (
                '<tr><td>' + fmtDate(v.created_at) + '</td>' +
                '<td><code>' + escapeHtml(v.ip_address || '—') + '</code></td>' +
                '<td>' + escapeHtml(v.page_url || '—') + '</td>' +
                '<td><code class="small">' + pq + '</code></td>' +
                '<td><small>' + escapeHtml(ua || '—') + '</small></td>' +
                '<td><small>' + escapeHtml(ref || '—') + '</small></td></tr>'
            );
        }).join('');
    }

    async function loadPortalVisits() {
        const tbody = el('auditPortalBody');
        tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Cargando…</td></tr>';
        const page = parseInt(el('auditPortalPage').value, 10) || 1;
        const from = el('auditPortalDateFrom').value;
        const to = el('auditPortalDateTo').value;
        const ip = el('auditPortalIp').value.trim();
        const pQ = el('auditPortalQuery') ? el('auditPortalQuery').value.trim() : '';
        let q = 'portal-paciente-list.php?page=' + page + '&limit=100';
        if (from) q += '&from=' + encodeURIComponent(from);
        if (to) q += '&to=' + encodeURIComponent(to);
        if (ip) q += '&ip=' + encodeURIComponent(ip);
        if (pQ) q += '&q=' + encodeURIComponent(pQ);
        try {
            const data = await apiGet(q);
            let totalLabel = 'Total: ' + (data.total || 0) + ' · Página ' + (data.page || 1);
            if (data.portal_table_configured === false) {
                totalLabel +=
                    ' · La tabla de visitas no existe: ejecutá modules/audit-manager/install.php y recargá esta pestaña.';
            }
            el('auditPortalTotal').textContent = totalLabel;
            portalRawRows = data.visits || [];
            updatePortalStatsBar(data);
            updateSortIcons('auditPortalTable', portSort);
            renderPortalRows(portalRawRows);
        } catch (e) {
            portalRawRows = [];
            updatePortalStatsBar(null);
            tbody.innerHTML = '<tr><td colspan="6" class="text-danger">' + escapeHtml(e.message) + '</td></tr>';
        }
    }

    function applyAudiosMetaFilters(data) {
        const configured = !!(data && data.audios_table_configured === true);
        const meta = (data && data.meta) || {};
        const estEl = el('auditAudiosEstado');
        if (estEl) {
            estEl.disabled = configured && !meta.has_estado;
            estEl.title = configured && !meta.has_estado
                ? 'No hay columna estado en audios_informe'
                : '';
        }
        const ftpEl = el('auditAudiosFtp');
        if (ftpEl) {
            ftpEl.disabled = configured && !meta.has_ftp_log;
            ftpEl.title = configured && !meta.has_ftp_log
                ? 'No existe la tabla audios_ftp_log'
                : '';
        }
        const txEl = el('auditAudiosTx');
        if (txEl) {
            txEl.disabled = configured && !meta.has_transcription_queue;
            txEl.title = configured && !meta.has_transcription_queue
                ? 'No existe la tabla ai_transcription_queue'
                : '';
        }
    }

    function updateAudiosBulkButtons() {
        const selectedCount = audSelectedIds.size;
        const txBtn = el('auditAudiosRequeueTxBtn');
        const ftpBtn = el('auditAudiosRetryFtpBtn');
        if (txBtn) {
            txBtn.disabled = selectedCount === 0;
            txBtn.title = selectedCount === 0
                ? 'Seleccioná audios para reencolar TX'
                : ('Reencolar TX para ' + selectedCount + ' audio(s)');
        }
        if (ftpBtn) {
            ftpBtn.disabled = selectedCount === 0;
            ftpBtn.title = selectedCount === 0
                ? 'Seleccioná audios para reintentar FTP'
                : ('Reintentar FTP para ' + selectedCount + ' audio(s)');
        }
    }

    function syncAudiosSelectAllUi(sortedRows) {
        const selectAll = el('auditAudiosSelectAll');
        if (!selectAll) return;
        if (!sortedRows.length) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            return;
        }
        const selectedOnPage = sortedRows.filter(function (r) { return audSelectedIds.has(Number(r.id)); }).length;
        selectAll.checked = selectedOnPage > 0 && selectedOnPage === sortedRows.length;
        selectAll.indeterminate = selectedOnPage > 0 && selectedOnPage < sortedRows.length;
    }

    async function runAudiosBulkAction(kind, explicitIds) {
        const ids = Array.isArray(explicitIds) && explicitIds.length
            ? explicitIds.map(function (n) { return Number(n); }).filter(Boolean)
            : Array.from(audSelectedIds.values()).map(function (n) { return Number(n); }).filter(Boolean);
        if (!ids.length) {
            showAuditToast('Seleccioná al menos un audio.', 'warning');
            return;
        }
        const endpoint = kind === 'tx' ? 'audios-requeue-tx.php' : 'audios-retry-ftp.php';
        const actionLabel = kind === 'tx' ? 'Reencolar TX' : 'Reintentar FTP';
        const isSingle = Array.isArray(explicitIds) && explicitIds.length === 1;
        if (!isSingle) {
            const confirmed = window.confirm(actionLabel + ': ¿confirmás aplicar la acción a ' + ids.length + ' audio(s)?');
            if (!confirmed) {
                return;
            }
        }
        try {
            const res = await apiPost(endpoint, { audio_ids: ids });
            const requeued = Number(res.requeued || 0);
            const skipped = Number(res.skipped || 0);
            let detailLine = '';
            if (res.details && Array.isArray(res.details) && res.details.length) {
                const tally = {};
                res.details.forEach(function (d) {
                    const k = String(d.result || 'unknown');
                    tally[k] = (tally[k] || 0) + 1;
                });
                const parts = Object.keys(tally).map(function (k) { return k + ': ' + tally[k]; });
                detailLine = parts.length ? (' [' + parts.join(' · ') + ']') : '';
            }
            showAuditToast(
                actionLabel + ': ' + requeued + ' aplicado(s), ' + skipped + ' omitido(s).' + detailLine,
                requeued > 0 ? 'success' : 'secondary'
            );
            await loadAudios(0);
        } catch (e) {
            showAuditToast(e.message || ('Error al ejecutar ' + actionLabel), 'danger');
        }
    }

    function renderAudiosRows(rows) {
        const tbody = el('auditAudiosBody');
        const hint = el('auditAudiosLiveHint');
        const filtered = filterAudiosLive(rows);
        const sorted = sortAudios(filtered, audSort);
        if (hint) {
            hint.textContent = sorted.length === rows.length
                ? 'Mostrando ' + sorted.length + ' fila(s) de esta página.'
                : 'Mostrando ' + sorted.length + ' de ' + rows.length + ' (filtro local).';
        }

        if (!sorted.length) {
            tbody.innerHTML = '<tr><td colspan="16" class="text-muted">Sin filas (ajustá filtros o la consulta)</td></tr>';
            syncAudiosSelectAllUi([]);
            updateAudiosBulkButtons();
            return;
        }

        tbody.innerHTML = sorted.map(function (a) {
            const userLine = escapeHtml((a.user_label || '').trim() || '(sin nombre)');
            const email = a.email ? escapeHtml(a.email) : '';
            const ftpCell = a.ftp_sent
                ? '<span class="badge bg-success">Sí · ' + escapeHtml(a.ftp_destination_format || 'MP3') + '</span>'
                : '<span class="text-muted">No</span>';
            const tx = a.transcription_queue_status
                ? '<code class="small">' + escapeHtml(String(a.transcription_queue_status)) + '</code>'
                : '<span class="text-muted">—</span>';
            const est = a.estado != null && String(a.estado) !== '' ? escapeHtml(String(a.estado)) : '—';
            const fn = (a.display_file_name || a.nombre_archivo || '');
            const fnShort = fn.length > 48 ? escapeHtml(fn.substring(0, 45)) + '…' : escapeHtml(fn || '—');
            const estudio = escapeHtml(String(a.estudio_id || '—'));
            const pac = a.study_patient_name != null && String(a.study_patient_name) !== ''
                ? escapeHtml(String(a.study_patient_name))
                : '—';
            const mod = a.study_modality != null && String(a.study_modality) !== ''
                ? escapeHtml(String(a.study_modality))
                : '—';
            const tam = escapeHtml(String(a.tamano_label || '—'));
            const fmt = escapeHtml(String(a.format_label || '—'));
            const aid = Number(a.id);
            const selected = audSelectedIds.has(aid) ? 'checked' : '';
            const txPending = !a.transcription_queue_status || String(a.transcription_queue_status).toLowerCase() !== 'completed';
            const txBtn = txPending
                ? '<button type="button" class="btn btn-xs btn-outline-warning me-1 audit-audio-action" data-action="tx" data-audio-id="' + aid + '" title="Reencolar transcripción"><i class="fas fa-rotate-right"></i></button>'
                : '<button type="button" class="btn btn-xs btn-outline-secondary me-1" disabled title="TX completada"><i class="fas fa-check"></i></button>';
            const ftpBtn = '<button type="button" class="btn btn-xs btn-outline-info audit-audio-action" data-action="ftp" data-audio-id="' + aid + '" title="Reintentar FTP"><i class="fas fa-cloud-arrow-up"></i></button>';

            return (
                '<tr>' +
                '<td><input type="checkbox" class="form-check-input audit-audio-select" data-audio-id="' + aid + '" ' + selected + '></td>' +
                '<td>' + a.id + '</td>' +
                '<td>' + userLine + '<br><small class="text-muted">#' + a.usuario_id + (email ? ' · ' + email : '') + '</small></td>' +
                '<td>' + escapeHtml(String(a.origin_label || '—')) + '</td>' +
                '<td><code class="small">' + estudio + '</code></td>' +
                '<td><small>' + pac + '</small></td>' +
                '<td><small>' + mod + '</small></td>' +
                '<td><small>' + fmtDate(a.fecha_creacion) + '</small></td>' +
                '<td><small>' + escapeHtml(fmtAudioDuration(a.duracion_segundos)) + '</small></td>' +
                '<td><small>' + tam + '</small></td>' +
                '<td><small>' + fmt + '</small></td>' +
                '<td><small>' + est + '</small></td>' +
                '<td>' + ftpCell + '</td>' +
                '<td>' + tx + '</td>' +
                '<td><small>' + txBtn + ftpBtn + '</small></td>' +
                '<td><small title="' + escapeHtml(fn) + '">' + fnShort + '</small></td>' +
                '</tr>'
            );
        }).join('');
        syncAudiosSelectAllUi(sorted);
        updateAudiosBulkButtons();
    }

    async function loadAudios(fillDepth) {
        fillDepth = typeof fillDepth === 'number' ? fillDepth : 0;
        const tbody = el('auditAudiosBody');
        const notCfg = el('auditAudiosNotConfigured');
        if (tbody && fillDepth === 0) {
            tbody.innerHTML = '<tr><td colspan="14" class="text-muted">Cargando…</td></tr>';
        }
        const page = parseInt(el('auditAudiosPage').value, 10) || 1;
        let q = 'audios-audit-list.php?page=' + page + '&limit=' + AUDIOS_PAGE_LIMIT;
        const from = el('auditAudiosDateFrom').value;
        const to = el('auditAudiosDateTo').value;
        if (from) q += '&from=' + encodeURIComponent(from);
        if (to) q += '&to=' + encodeURIComponent(to);
        const uid = el('auditAudiosUserId').value.trim();
        if (uid) q += '&user_id=' + encodeURIComponent(uid);
        const uq = el('auditAudiosUserQ').value.trim();
        if (uq) q += '&user_q=' + encodeURIComponent(uq);
        const sq = el('auditAudiosStudyQ').value.trim();
        if (sq) q += '&study_q=' + encodeURIComponent(sq);
        const est = el('auditAudiosEstado').value.trim();
        if (est) q += '&estado=' + encodeURIComponent(est);
        const origin = el('auditAudiosOrigin').value;
        if (origin) q += '&origin=' + encodeURIComponent(origin);
        const ftp = el('auditAudiosFtp').value;
        if (ftp) q += '&ftp=' + encodeURIComponent(ftp);
        const tx = el('auditAudiosTx').value;
        if (tx) q += '&transcription=' + encodeURIComponent(tx);
        if (el('auditAudiosIncludeInactive').checked) q += '&include_inactive=1';

        try {
            await ensureUserSelectsFilled();
            const data = await apiGet(q);
            if (notCfg) {
                if (data.audios_table_configured === false) {
                    notCfg.classList.remove('d-none');
                } else {
                    notCfg.classList.add('d-none');
                }
            }
            applyAudiosMetaFilters(data);
            updateAudiosStatsBar(data);
            audRawRows = data.audios || [];
            let totalLabel = 'Total servidor: ' + (data.total || 0) + ' · Página ' + (data.page || 1) + ' (Filtrar = nueva consulta)';
            if (data.audios_table_configured === false) {
                totalLabel += ' · Sin tabla audios_informe.';
            }
            el('auditAudiosTotal').textContent = totalLabel;
            updateSortIcons('auditAudiosTable', audSort);
            renderAudiosRows(audRawRows);

            if (data.audios_table_configured !== false && fillDepth < AUDIOS_FILL_MAX_DEPTH) {
                const missingIds = audRawRows
                    .filter(function (a) {
                        return a.db_duration_missing === true;
                    })
                    .map(function (a) {
                        return a.id;
                    });
                if (missingIds.length > 0) {
                    try {
                        const fr = await apiPost('audios-fill-duration.php', { audio_ids: missingIds.slice(0, 40) });
                        const nUp = fr.updated && fr.updated.length ? fr.updated.length : 0;
                        if (nUp > 0) {
                            if (fillDepth === 0) {
                                showAuditToast('Duración guardada en BD para ' + nUp + ' audio(s) (ffprobe/ffmpeg).', 'success');
                            }
                            await loadAudios(fillDepth + 1);
                            return;
                        }
                    } catch (fillErr) {
                        if (fillDepth === 0) {
                            showAuditToast(fillErr.message || 'No se pudo medir duraciones en servidor (¿ffprobe instalado?)', 'danger');
                        }
                    }
                }
            }
        } catch (e) {
            audRawRows = [];
            updateAudiosStatsBar(null);
            if (notCfg) notCfg.classList.add('d-none');
            applyAudiosMetaFilters(null);
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="16" class="text-danger">' + escapeHtml(e.message) + '</td></tr>';
            }
        }
    }

    async function auditAudiosFillDurationManual() {
        const missingIds = (audRawRows || [])
            .filter(function (a) {
                return a.db_duration_missing === true;
            })
            .map(function (a) {
                return a.id;
            });
        if (!missingIds.length) {
            showAuditToast('En esta página no hay audios sin duración en BD.', 'secondary');
            return;
        }
        try {
            const fr = await apiPost('audios-fill-duration.php', { audio_ids: missingIds.slice(0, 40) });
            const nUp = fr.updated && fr.updated.length ? fr.updated.length : 0;
            const nFail = fr.failed && fr.failed.length ? fr.failed.length : 0;
            if (nUp > 0) {
                showAuditToast(
                    'Duración guardada para ' + nUp + ' audio(s).' + (nFail ? ' Sin medir: ' + nFail + '.' : ''),
                    'success'
                );
                await loadAudios(0);
            } else {
                showAuditToast(
                    'No se obtuvo duración (archivo ausente o ffprobe/ffmpeg). Revisá servidor o rutas.',
                    'danger'
                );
            }
        } catch (err) {
            showAuditToast(err.message || 'Error al medir duraciones', 'danger');
        }
    }

    async function loadStats() {
        const box = el('auditStatsBox');
        box.innerHTML = '<p class="text-muted">Cargando…</p>';
        const days = el('auditStatsDays').value || '7';
        try {
            const data = await apiGet('stats.php?days=' + encodeURIComponent(days));
            const ping = data.connectivity_ping || {};
            let html = '<h6>Ping / conectividad (action_key = connectivity.ping)</h6>';
            html += '<p>Muestras: <strong>' + (ping.n || 0) + '</strong> · RTT medio: <strong>' + (ping.avg_rtt_ms != null ? Math.round(ping.avg_rtt_ms) + ' ms' : '—') + '</strong> · min/max: ' + (ping.min_rtt != null ? ping.min_rtt : '—') + ' / ' + (ping.max_rtt != null ? ping.max_rtt : '—') + ' ms</p>';
            html += '<h6 class="mt-3">Por acción (últimos ' + (data.period_days || days) + ' días)</h6>';
            html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Acción</th><th>N</th><th>RTT ∅</th><th>Cliente ∅</th><th>Servidor ∅</th></tr></thead><tbody>';
            (data.by_action || []).forEach(function (r) {
                html += '<tr><td><code>' + escapeHtml(r.action_key) + '</code></td><td>' + r.cnt + '</td>';
                html += '<td>' + (r.avg_rtt_ms != null ? Math.round(r.avg_rtt_ms) : '—') + '</td>';
                html += '<td>' + (r.avg_client_ms != null ? Math.round(r.avg_client_ms) : '—') + '</td>';
                html += '<td>' + (r.avg_server_ms != null ? Math.round(r.avg_server_ms) : '—') + '</td></tr>';
            });
            html += '</tbody></table></div>';
            box.innerHTML = html;
        } catch (e) {
            box.innerHTML = '<p class="text-danger">' + escapeHtml(e.message) + '</p>';
        }
    }

    function loadInformesRecibidosFrame() {
        const frame = el('auditInformesRecibidosFrame');
        if (!frame) {
            return;
        }
        const configuredSrc = frame.getAttribute('data-src') || 'informes-recibidos.html?iframe=1';
        if (frame.getAttribute('src') !== configuredSrc) {
            frame.setAttribute('src', configuredSrc);
        }
    }

    async function fetchInformesCountByEstado(estado) {
        const params = new URLSearchParams({
            page: '1',
            limit: '1',
            estado: estado,
        });
        const r = await fetch('api/informes/recibidos/list.php?' + params.toString(), {
            credentials: 'same-origin',
        });
        const j = await r.json().catch(function () {
            return {};
        });
        if (!r.ok || !j.success) {
            throw new Error(j.error || j.message || ('HTTP ' + r.status));
        }
        return j.pagination && typeof j.pagination.total === 'number' ? j.pagination.total : 0;
    }

    async function refreshInformesApiBadge() {
        const badge = el('auditInformesRecibidosBadge');
        if (!badge) {
            return;
        }
        try {
            const counts = await Promise.all([
                fetchInformesCountByEstado('recibido'),
                fetchInformesCountByEstado('error'),
            ]);
            const pendientes = counts[0] || 0;
            const errores = counts[1] || 0;
            badge.textContent = 'P:' + pendientes + ' · E:' + errores;
            badge.classList.remove('bg-secondary', 'bg-danger', 'd-none');
            if (errores > 0) {
                badge.classList.add('bg-danger');
            } else {
                badge.classList.add('bg-secondary');
            }
            if (pendientes === 0 && errores === 0) {
                badge.classList.add('d-none');
            }
        } catch (e) {
            badge.textContent = 'P:— · E:—';
            badge.classList.remove('bg-danger');
            badge.classList.add('bg-secondary');
            badge.classList.remove('d-none');
        }
    }

    function renderIrAttemptsRows(rows) {
        const tbody = el('auditIrAttemptBody');
        if (!tbody) return;
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-muted">Sin filas</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function (a) {
            const estadoClass = a.estado === 'exitoso'
                ? 'bg-success'
                : (a.estado === 'error' ? 'bg-danger' : 'bg-secondary');
            const files = [a.pdf_filename ? ('PDF: ' + a.pdf_filename) : null, a.txt_filename ? ('TXT: ' + a.txt_filename) : null]
                .filter(Boolean)
                .join(' | ') || '—';
            const err = a.error_message ? String(a.error_message) : '—';
            const actionBtn = a.retry_available
                ? '<button type="button" class="btn btn-sm btn-outline-primary audit-ir-retry-btn" data-attempt-id="' + Number(a.id) + '">Reintentar envío</button>'
                : '<span class="text-muted small">—</span>';
            return '<tr>' +
                '<td>' + fmtDate(a.created_at) + '</td>' +
                '<td><span class="badge ' + estadoClass + '">' + escapeHtml(a.estado || '—') + '</span></td>' +
                '<td><code class="small">' + escapeHtml(a.request_id || '—') + '</code></td>' +
                '<td>' + escapeHtml(a.accession_number || '—') + '</td>' +
                '<td><small>' + escapeHtml(files) + '</small></td>' +
                '<td><code>' + escapeHtml(a.remote_ip || '—') + '</code></td>' +
                '<td><small title="' + escapeHtml(err) + '">' + escapeHtml(err.length > 120 ? (err.substring(0, 117) + '...') : err) + '</small></td>' +
                '<td>' + actionBtn + '</td>' +
                '</tr>';
        }).join('');

        tbody.querySelectorAll('.audit-ir-retry-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const attemptId = Number(btn.getAttribute('data-attempt-id'));
                retryIrAttempt(attemptId);
            });
        });
    }

    async function loadIrAttempts() {
        const tbody = el('auditIrAttemptBody');
        const totalEl = el('auditIrAttemptTotal');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-muted">Cargando…</td></tr>';
        }
        const page = parseInt(el('auditIrAttemptPage').value, 10) || 1;
        const estado = (el('auditIrAttemptEstado').value || '').trim();
        const from = (el('auditIrAttemptFrom').value || '').trim();
        const to = (el('auditIrAttemptTo').value || '').trim();
        const q = (el('auditIrAttemptQ').value || '').trim();
        let query = 'informes-api-attempts.php?page=' + page + '&limit=30';
        if (estado) query += '&estado=' + encodeURIComponent(estado);
        if (from) query += '&from=' + encodeURIComponent(from);
        if (to) query += '&to=' + encodeURIComponent(to);
        if (q) query += '&q=' + encodeURIComponent(q);
        try {
            const data = await apiGet(query);
            if (totalEl) {
                if (data.configured === false) {
                    totalEl.textContent = 'Tabla de intentos no configurada. Ejecutar script SQL de creación.';
                } else {
                    totalEl.textContent = 'Total: ' + (data.total || 0) + ' · Página ' + (data.page || page);
                }
            }
            renderIrAttemptsRows(data.attempts || []);
        } catch (e) {
            if (totalEl) totalEl.textContent = 'Error al cargar intentos';
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-danger">' + escapeHtml(e.message) + '</td></tr>';
        }
    }

    function resolveDefaultIrTestUrl() {
        return window.location.origin + '/api/informes/recibir-pdf.php';
    }

    async function sendIrApiTest() {
        const url = (el('auditIrTestUrl').value || '').trim();
        const pdfInput = el('auditIrTestPdf');
        const txtInput = el('auditIrTestTxt');
        const result = el('auditIrTestResult');
        if (!url) {
            showAuditToast('Debe indicar URL destino', 'danger');
            return;
        }
        if (!pdfInput || !pdfInput.files || !pdfInput.files[0]) {
            showAuditToast('Seleccione archivo PDF', 'danger');
            return;
        }
        if (!txtInput || !txtInput.files || !txtInput.files[0]) {
            showAuditToast('Seleccione archivo TXT', 'danger');
            return;
        }
        const fd = new FormData();
        fd.append('pdf', pdfInput.files[0]);
        fd.append('txt', txtInput.files[0]);
        if (result) {
            result.textContent = 'Enviando...';
        }
        try {
            const res = await fetch(url, {
                method: 'POST',
                body: fd,
            });
            const raw = await res.text();
            let parsed;
            try {
                parsed = JSON.parse(raw);
            } catch (e) {
                parsed = null;
            }
            const out = {
                status: res.status,
                ok: res.ok,
                response_json: parsed,
                response_raw: parsed ? undefined : raw,
            };
            if (result) {
                result.textContent = JSON.stringify(out, null, 2);
            }
            if (res.ok) {
                showAuditToast('Prueba enviada. Revisá respuesta y trazabilidad.', 'success');
                refreshInformesApiBadge();
                loadIrAttempts();
            } else {
                showAuditToast('El endpoint respondió con error HTTP ' + res.status, 'danger');
            }
        } catch (e) {
            if (result) {
                result.textContent = JSON.stringify({
                    error: e.message || 'Error de red/CORS',
                }, null, 2);
            }
            showAuditToast('Error de envío (posible CORS/red).', 'danger');
        }
    }

    async function retryIrAttempt(attemptId) {
        if (!attemptId || Number.isNaN(attemptId)) {
            showAuditToast('attempt_id inválido', 'danger');
            return;
        }
        const url = (el('auditIrTestUrl').value || '').trim();
        const result = el('auditIrTestResult');
        if (!url) {
            showAuditToast('Definí URL destino para reintento', 'danger');
            return;
        }
        if (result) {
            result.textContent = 'Reintentando envío del intento #' + attemptId + ' ...';
        }
        try {
            const data = await apiPost('informes-api-retry.php', {
                attempt_id: attemptId,
                target_url: url,
            });
            if (result) {
                result.textContent = JSON.stringify(data, null, 2);
            }
            if (data.http_status && data.http_status >= 200 && data.http_status < 300) {
                showAuditToast('Reintento enviado correctamente (HTTP ' + data.http_status + ')', 'success');
            } else {
                showAuditToast('Reintento ejecutado, revisar respuesta (HTTP ' + (data.http_status || 'N/A') + ')', 'danger');
            }
            refreshInformesApiBadge();
            loadIrAttempts();
        } catch (e) {
            if (result) {
                result.textContent = JSON.stringify({ success: false, error: e.message || 'Error' }, null, 2);
            }
            showAuditToast(e.message || 'No se pudo reintentar envío', 'danger');
        }
    }

    const debouncedConnLive = debounce(function () {
        try {
            localStorage.setItem(LS_CONN_LIVE, el('auditConnLiveFilter').value || '');
        } catch (e) {}
        renderConnectionsRows(connRawRows);
    }, 200);

    const debouncedEvLive = debounce(function () {
        try {
            localStorage.setItem(LS_EV_LIVE, el('auditEventsLiveFilter').value || '');
        } catch (e) {}
        renderEventsRows(evRawRows);
    }, 200);

    const debouncedPortLive = debounce(function () {
        try {
            localStorage.setItem(LS_PORT_LIVE, el('auditPortalLiveFilter').value || '');
        } catch (e) {}
        renderPortalRows(portalRawRows);
    }, 200);

    const debouncedAudLive = debounce(function () {
        try {
            localStorage.setItem(LS_AUD_LIVE, el('auditAudiosLiveFilter').value || '');
        } catch (e) {}
        renderAudiosRows(audRawRows);
    }, 200);

    function syncConnSelectFromId() {
        const sel = el('auditConnUserSelect');
        const id = el('auditConnUserId').value.trim();
        if (!sel) return;
        if (!id) {
            sel.value = '';
            return;
        }
        sel.value = sel.querySelector('option[value="' + id + '"]') ? id : '';
    }

    function syncAudiosSelectFromId() {
        const sel = el('auditAudiosUserSelect');
        const id = el('auditAudiosUserId').value.trim();
        if (!sel) return;
        if (!id) {
            sel.value = '';
            return;
        }
        sel.value = sel.querySelector('option[value="' + id + '"]') ? id : '';
    }

    function init() {
        try {
            const lv = localStorage.getItem(LS_CONN_LIVE);
            if (lv && el('auditConnLiveFilter')) el('auditConnLiveFilter').value = lv;
        } catch (e) {}
        try {
            const lv = localStorage.getItem(LS_EV_LIVE);
            if (lv && el('auditEventsLiveFilter')) el('auditEventsLiveFilter').value = lv;
        } catch (e) {}
        try {
            const lv = localStorage.getItem(LS_PORT_LIVE);
            if (lv && el('auditPortalLiveFilter')) el('auditPortalLiveFilter').value = lv;
        } catch (e) {}
        try {
            const lv = localStorage.getItem(LS_AUD_LIVE);
            if (lv && el('auditAudiosLiveFilter')) el('auditAudiosLiveFilter').value = lv;
        } catch (e) {}

        updateSortIcons('auditConnectionsTable', connSort);
        updateSortIcons('auditEventsTable', evSort);
        updateSortIcons('auditPortalTable', portSort);
        updateSortIcons('auditAudiosTable', audSort);

        bindTableSort('auditConnectionsTable', connSort, LS_CONN_SORT, function () {
            renderConnectionsRows(connRawRows);
        });
        bindTableSort('auditEventsTable', evSort, LS_EV_SORT, function () {
            renderEventsRows(evRawRows);
        });
        bindTableSort('auditPortalTable', portSort, LS_PORT_SORT, function () {
            renderPortalRows(portalRawRows);
        });
        bindTableSort('auditAudiosTable', audSort, LS_AUD_SORT, function () {
            renderAudiosRows(audRawRows);
        });

        el('auditConnLiveFilter').addEventListener('input', debouncedConnLive);
        el('auditEventsLiveFilter').addEventListener('input', debouncedEvLive);
        el('auditPortalLiveFilter').addEventListener('input', debouncedPortLive);
        el('auditAudiosLiveFilter').addEventListener('input', debouncedAudLive);

        el('auditConnUserSelect').addEventListener('change', function () {
            const v = el('auditConnUserSelect').value;
            el('auditConnUserId').value = v || '';
            el('auditConnUserQ').value = '';
            el('auditConnPage').value = '1';
            loadConnections();
        });

        el('auditConnUserId').addEventListener('change', syncConnSelectFromId);
        el('auditConnUserId').addEventListener('blur', syncConnSelectFromId);

        el('auditAudiosUserSelect').addEventListener('change', function () {
            const v = el('auditAudiosUserSelect').value;
            el('auditAudiosUserId').value = v || '';
            el('auditAudiosUserQ').value = '';
            el('auditAudiosPage').value = '1';
            loadAudios();
        });
        el('auditAudiosUserId').addEventListener('change', syncAudiosSelectFromId);
        el('auditAudiosUserId').addEventListener('blur', syncAudiosSelectFromId);

        el('auditEventsUserSelect').addEventListener('change', function () {
            el('auditFilterUserId').value = el('auditEventsUserSelect').value || '';
            el('auditFilterUserQ').value = '';
            el('auditEventsPage').value = '1';
            loadEvents();
        });

        el('auditFilterUserId').addEventListener('blur', function () {
            const id = el('auditFilterUserId').value.trim();
            const sel = el('auditEventsUserSelect');
            if (sel && (!id || sel.querySelector('option[value="' + id + '"]'))) {
                sel.value = id || '';
            }
        });

        el('auditTabConnections').addEventListener('shown.bs.tab', function () {
            ensureUserSelectsFilled().then(function () {
                syncConnSelectFromId();
            });
        });
        el('auditTabPortalPaciente').addEventListener('shown.bs.tab', loadPortalVisits);
        el('auditTabAudios').addEventListener('shown.bs.tab', function () {
            ensureUserSelectsFilled().then(function () {
                syncAudiosSelectFromId();
                loadAudios();
            });
        });
        el('auditTabInformesRecibidos').addEventListener('shown.bs.tab', function () {
            loadInformesRecibidosFrame();
            refreshInformesApiBadge();
        });
        el('auditTabEvents').addEventListener('shown.bs.tab', function () {
            ensureUserSelectsFilled().then(function () {
                const id = el('auditFilterUserId').value.trim();
                const sel = el('auditEventsUserSelect');
                if (sel) sel.value = id && sel.querySelector('option[value="' + id + '"]') ? id : '';
                loadEvents();
            });
        });
        el('auditTabStats').addEventListener('shown.bs.tab', loadStats);

        // Pestaña MPPS / Equipos (módulo modules/mpps-audit)
        (function initMppsAuditTab() {
            const tab = el('auditTabMpps');
            if (!tab) return;
            function boot() {
                function afterReady() {
                    if (window.MppsAuditTab && typeof window.MppsAuditTab.init === 'function') {
                        window.MppsAuditTab.init();
                    }
                    if (window.MppsAuditTab && typeof window.MppsAuditTab.reload === 'function') {
                        window.MppsAuditTab.reload();
                    }
                }
                if (window.MppsAuditTab) {
                    afterReady();
                    return;
                }
                const s = document.createElement('script');
                s.src = 'modules/mpps-audit/assets/js/mpps-audit-tab.js?v=202609022000';
                s.onload = afterReady;
                s.onerror = function () {
                    console.warn('[audit-manager] No se pudo cargar mpps-audit-tab.js');
                };
                document.head.appendChild(s);
            }
            tab.addEventListener('shown.bs.tab', boot);
        })();

        el('auditConnFilterBtn').addEventListener('click', function () {
            el('auditConnPage').value = '1';
            loadConnections();
        });
        el('auditRefreshConnections').addEventListener('click', loadConnections);
        el('auditConnNext').addEventListener('click', function () {
            el('auditConnPage').value = String((parseInt(el('auditConnPage').value, 10) || 1) + 1);
            loadConnections();
        });
        el('auditConnPrev').addEventListener('click', function () {
            const p = Math.max(1, (parseInt(el('auditConnPage').value, 10) || 1) - 1);
            el('auditConnPage').value = String(p);
            loadConnections();
        });

        el('auditPortalFilterBtn').addEventListener('click', function () {
            el('auditPortalPage').value = '1';
            loadPortalVisits();
        });

        const purgeBtn = el('auditPortalPurgeOpenBtn');
        const purgeModal = el('auditPortalPurgeModal');
        if (purgeBtn && purgeModal && typeof bootstrap !== 'undefined') {
            purgeBtn.addEventListener('click', function () {
                bootstrap.Modal.getOrCreateInstance(purgeModal).show();
            });
        }
        const purgeConfirm = el('auditPortalPurgeConfirm');
        if (purgeConfirm) {
            purgeConfirm.addEventListener('click', async function () {
                const scopeAll = el('purgeScopeAll');
                const scope = scopeAll && scopeAll.checked ? 'all' : 'filtered';
                const body = { confirm: PORTAL_PURGE_CONFIRM, scope: scope };
                if (scope === 'filtered') {
                    body.filters = {
                        from: el('auditPortalDateFrom').value,
                        to: el('auditPortalDateTo').value,
                        ip: el('auditPortalIp').value.trim(),
                        q: el('auditPortalQuery') ? el('auditPortalQuery').value.trim() : '',
                    };
                }
                try {
                    const res = await apiPost('portal-paciente-purge.php', body);
                    if (purgeModal && typeof bootstrap !== 'undefined') {
                        bootstrap.Modal.getOrCreateInstance(purgeModal).hide();
                    }
                    if (res.scope === 'all') {
                        showAuditToast('Auditoría del portal vaciada por completo.', 'success');
                    } else {
                        showAuditToast(
                            'Eliminados ' + (res.deleted != null ? res.deleted : 0) + ' registro(s) del período filtrado.',
                            'success'
                        );
                    }
                    el('auditPortalPage').value = '1';
                    await loadPortalVisits();
                } catch (err) {
                    showAuditToast(err.message || 'Error al limpiar la auditoría', 'danger');
                }
            });
        }
        el('auditPortalNext').addEventListener('click', function () {
            el('auditPortalPage').value = String((parseInt(el('auditPortalPage').value, 10) || 1) + 1);
            loadPortalVisits();
        });
        el('auditPortalPrev').addEventListener('click', function () {
            const p = Math.max(1, (parseInt(el('auditPortalPage').value, 10) || 1) - 1);
            el('auditPortalPage').value = String(p);
            loadPortalVisits();
        });

        el('auditAudiosFilterBtn').addEventListener('click', function () {
            el('auditAudiosPage').value = '1';
            loadAudios();
        });
        el('auditAudiosNext').addEventListener('click', function () {
            el('auditAudiosPage').value = String((parseInt(el('auditAudiosPage').value, 10) || 1) + 1);
            loadAudios();
        });
        el('auditAudiosPrev').addEventListener('click', function () {
            const p = Math.max(1, (parseInt(el('auditAudiosPage').value, 10) || 1) - 1);
            el('auditAudiosPage').value = String(p);
            loadAudios();
        });
        const fillDurBtn = el('auditAudiosFillDurationBtn');
        if (fillDurBtn) {
            fillDurBtn.addEventListener('click', function () {
                auditAudiosFillDurationManual();
            });
        }
        const selectAllAudios = el('auditAudiosSelectAll');
        if (selectAllAudios) {
            selectAllAudios.addEventListener('change', function () {
                const checks = document.querySelectorAll('#auditAudiosBody .audit-audio-select');
                checks.forEach(function (cb) {
                    const id = Number(cb.getAttribute('data-audio-id'));
                    cb.checked = selectAllAudios.checked;
                    if (selectAllAudios.checked) audSelectedIds.add(id);
                    else audSelectedIds.delete(id);
                });
                updateAudiosBulkButtons();
            });
        }
        const audiosTbody = el('auditAudiosBody');
        if (audiosTbody) {
            audiosTbody.addEventListener('change', function (ev) {
                const t = ev.target;
                if (!t || !t.classList || !t.classList.contains('audit-audio-select')) return;
                const id = Number(t.getAttribute('data-audio-id'));
                if (t.checked) audSelectedIds.add(id);
                else audSelectedIds.delete(id);
                syncAudiosSelectAllUi(sortAudios(filterAudiosLive(audRawRows), audSort));
                updateAudiosBulkButtons();
            });
            audiosTbody.addEventListener('click', function (ev) {
                const btn = ev.target && ev.target.closest ? ev.target.closest('.audit-audio-action') : null;
                if (!btn) return;
                const action = btn.getAttribute('data-action');
                const id = Number(btn.getAttribute('data-audio-id'));
                if (!id || !action) return;
                runAudiosBulkAction(action === 'tx' ? 'tx' : 'ftp', [id]);
            });
        }
        const requeueTxBtn = el('auditAudiosRequeueTxBtn');
        if (requeueTxBtn) {
            requeueTxBtn.addEventListener('click', function () { runAudiosBulkAction('tx'); });
        }
        const retryFtpBtn = el('auditAudiosRetryFtpBtn');
        if (retryFtpBtn) {
            retryFtpBtn.addEventListener('click', function () { runAudiosBulkAction('ftp'); });
        }

        el('auditEventsFilterBtn').addEventListener('click', function () {
            el('auditEventsPage').value = '1';
            loadEvents();
        });
        el('auditEventsNext').addEventListener('click', function () {
            el('auditEventsPage').value = String((parseInt(el('auditEventsPage').value, 10) || 1) + 1);
            loadEvents();
        });
        el('auditEventsPrev').addEventListener('click', function () {
            const p = Math.max(1, (parseInt(el('auditEventsPage').value, 10) || 1) - 1);
            el('auditEventsPage').value = String(p);
            loadEvents();
        });
        el('auditStatsReload').addEventListener('click', loadStats);

        el('auditIrAttemptFilterBtn').addEventListener('click', function () {
            el('auditIrAttemptPage').value = '1';
            loadIrAttempts();
        });
        el('auditIrAttemptRefreshBtn').addEventListener('click', loadIrAttempts);
        el('auditIrAttemptNext').addEventListener('click', function () {
            el('auditIrAttemptPage').value = String((parseInt(el('auditIrAttemptPage').value, 10) || 1) + 1);
            loadIrAttempts();
        });
        el('auditIrAttemptPrev').addEventListener('click', function () {
            const p = Math.max(1, (parseInt(el('auditIrAttemptPage').value, 10) || 1) - 1);
            el('auditIrAttemptPage').value = String(p);
            loadIrAttempts();
        });
        const testUrlEl = el('auditIrTestUrl');
        if (testUrlEl && !testUrlEl.value) {
            testUrlEl.value = resolveDefaultIrTestUrl();
        }
        el('auditIrTestSendBtn').addEventListener('click', sendIrApiTest);
        const irAttemptsOpenBtn = el('auditIrOpenAttemptsModalBtn');
        const irAttemptsModalEl = el('auditIrAttemptsModal');
        if (irAttemptsOpenBtn && irAttemptsModalEl && typeof bootstrap !== 'undefined') {
            irAttemptsOpenBtn.addEventListener('click', function () {
                bootstrap.Modal.getOrCreateInstance(irAttemptsModalEl).show();
                loadIrAttempts();
            });
        }
        const irTesterOpenBtn = el('auditIrOpenTesterModalBtn');
        const irTesterModalEl = el('auditIrTesterModal');
        if (irTesterOpenBtn && irTesterModalEl && typeof bootstrap !== 'undefined') {
            irTesterOpenBtn.addEventListener('click', function () {
                bootstrap.Modal.getOrCreateInstance(irTesterModalEl).show();
            });
        }

        refreshInformesApiBadge();
        setInterval(refreshInformesApiBadge, 60000);
        loadConnections();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
