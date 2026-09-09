/**
 * Pestaña MPPS / Equipos en Auditoría — carga listado y stats del módulo mpps-audit.
 */
(function (global) {
    'use strict';

    const API_BASE = 'modules/mpps-audit/api/';

    const AUDIT_LABELS = {
        normal: { text: 'Normal', class: 'bg-success' },
        orphan: { text: 'Fuera de worklist', class: 'bg-warning text-dark' },
        ghost: { text: 'Fantasma', class: 'bg-danger' },
        no_show: { text: 'Ausente', class: 'bg-secondary' },
        pending_images: { text: 'Pendiente imágenes', class: 'bg-info text-dark' },
        unknown: { text: 'Desconocido', class: 'bg-light text-dark' },
    };

    const WL_LABELS = {
        matched: { text: 'En worklist', class: 'text-success' },
        orphan: { text: 'Sin match', class: 'text-warning' },
        no_accession: { text: 'Sin accession', class: 'text-warning' },
        unknown: { text: '—', class: 'text-muted' },
    };

    function el(id) {
        return document.getElementById(id);
    }

    function esc(s) {
        if (s == null || s === '') return '—';
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function fmtDt(v) {
        if (!v) return '—';
        const s = String(v).replace('T', ' ');
        return esc(s.length > 16 ? s.slice(0, 16) : s);
    }

    function stateBadge(state) {
        const st = String(state || 'UNKNOWN').toUpperCase().replace(/[\s-]+/g, '_');
        let cls = 'bg-secondary';
        if (st === 'IN_PROGRESS') cls = 'bg-primary';
        else if (st === 'COMPLETED') cls = 'bg-success';
        else if (st === 'DISCONTINUED') cls = 'bg-danger';
        else if (st === 'UNKNOWN') cls = 'bg-light text-dark border';
        return '<span class="badge ' + cls + '">' + esc(st.replace(/_/g, ' ')) + '</span>';
    }

    async function apiGet(path) {
        const r = await fetch(API_BASE + path, { credentials: 'same-origin' });
        const j = await r.json().catch(function () { return {}; });
        if (!r.ok) {
            throw new Error(j.error || j.message || ('HTTP ' + r.status));
        }
        return j;
    }

    function renderStats(stats) {
        if (!stats) return;
        el('mppsStatTotal').textContent = String(stats.total != null ? stats.total : '—');
        el('mppsStatInProgress').textContent = String(stats.in_progress != null ? stats.in_progress : '—');
        el('mppsStatOrphan').textContent = String(stats.orphan != null ? stats.orphan : '—');
        el('mppsStatGhost').textContent = String(stats.ghost != null ? stats.ghost : '—');
        const badge = el('auditMppsMockBadge');
        if (badge) {
            if (stats.is_mock) badge.classList.remove('d-none');
            else badge.classList.add('d-none');
        }
        const hint = el('auditMppsHint');
        if (hint && stats.is_mock) {
            hint.innerHTML = 'Mostrando <strong>datos de demostración</strong>.';
        } else if (hint && stats.source === 'orthanc') {
            hint.textContent = 'Consulta en vivo a Orthanc (GET /mpps). Refrescá para actualizar. Si la lista está vacía, Orthanc aún no recibió MPPS.';
        } else if (hint) {
            hint.textContent = 'Eventos de inicio/fin de estudio reportados por los equipos (MPPS).';
        }
    }

    function renderRows(rows) {
        const tbody = el('mppsEventsBody');
        if (!tbody) return;
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-muted text-center">Sin eventos</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function (row) {
            const audit = AUDIT_LABELS[row.audit_status] || AUDIT_LABELS.unknown;
            const wl = WL_LABELS[row.worklist_match] || WL_LABELS.unknown;
            const pacs = row.pacs_study_found
                ? '<span class="text-success">Sí</span>'
                : '<span class="text-muted">No</span>';
            const patient = esc(row.patient_name || '') +
                (row.patient_id ? '<br><span class="small text-muted">' + esc(row.patient_id) + '</span>' : '');
            return '<tr>' +
                '<td>' + stateBadge(row.state) + '</td>' +
                '<td>' + patient + '</td>' +
                '<td><code class="small">' + esc(row.accession_number) + '</code></td>' +
                '<td>' + esc(row.modality) + '</td>' +
                '<td>' + esc(row.station_name) + '</td>' +
                '<td class="small">' + fmtDt(row.started_at) + '</td>' +
                '<td class="small">' + fmtDt(row.ended_at) + '</td>' +
                '<td class="small ' + wl.class + '">' + wl.text + '</td>' +
                '<td>' + pacs + '</td>' +
                '<td><span class="badge ' + audit.class + '">' + audit.text + '</span></td>' +
                '</tr>';
        }).join('');
    }

    async function loadMpps() {
        const footer = el('mppsEventsFooter');
        const filter = (el('mppsFilterStatus') && el('mppsFilterStatus').value) || '';
        try {
            const q = 'source=orthanc&limit=50&offset=0' + (filter ? '&audit_status=' + encodeURIComponent(filter) : '');
            const tbody = el('mppsEventsBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-muted text-center">Consultando Orthanc…</td></tr>';
            }
            const [listRes, statsRes] = await Promise.all([
                apiGet('list.php?' + q),
                apiGet('stats.php?source=orthanc'),
            ]);
            renderStats(statsRes.stats || {});
            renderRows(listRes.rows || []);
            if (footer) {
                const src = listRes.source === 'orthanc' ? 'Orthanc' : (listRes.is_mock ? 'demostración' : '');
                footer.textContent = 'Total: ' + (listRes.total != null ? listRes.total : 0) +
                    (src ? ' (' + src + ')' : '');
            }
        } catch (e) {
            const tbody = el('mppsEventsBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-danger text-center">' +
                    esc(e.message || 'Error al cargar') + '</td></tr>';
            }
            if (footer) footer.textContent = '';
        }
    }

    function initMppsTab() {
        const tab = el('auditTabMpps');
        if (!tab || tab.dataset.mppsBound === '1') return;
        tab.dataset.mppsBound = '1';
        tab.addEventListener('shown.bs.tab', loadMpps);
        const btnF = el('mppsFilterBtn');
        const btnR = el('mppsReloadBtn');
        if (btnF) btnF.addEventListener('click', loadMpps);
        if (btnR) btnR.addEventListener('click', loadMpps);
        if (tab.classList.contains('active')) {
            loadMpps();
        }
    }

    global.MppsAuditTab = { init: initMppsTab, reload: loadMpps };
})(window);
