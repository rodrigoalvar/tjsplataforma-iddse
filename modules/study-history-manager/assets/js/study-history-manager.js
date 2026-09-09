/**
 * Study History Manager — búsqueda multi-nodo por PatientID
 * Persistencia localStorage, orden por columnas, selección de fila (como pacs-manager).
 */
(function () {
    'use strict';

    const API_BASE = 'modules/study-history-manager/api/';
    const STORAGE_KEY = 'study_history_manager_state';
    const MAX_AGE_MS = 24 * 60 * 60 * 1000;

    let studiesCache = [];
    let sortConfig = { column: 'study_date', direction: 'desc' };
    let pendingSelectUid = null;

    function getEl(id) {
        return document.getElementById(id);
    }

    function showAlert(type, message) {
        const box = getEl('shmAlert');
        if (!box) return;
        box.className = 'alert alert-' + type;
        box.textContent = message;
        box.classList.remove('d-none');
    }

    function hideAlert() {
        const box = getEl('shmAlert');
        if (box) {
            box.classList.add('d-none');
            box.textContent = '';
        }
    }

    function saveState() {
        try {
            const selectedRow = document.querySelector('#shmResultsTable tbody tr.shm-row-selected');
            const selectedStudyUid = selectedRow ? selectedRow.getAttribute('data-study-uid') : null;
            const pidInput = getEl('shmPatientId');
            const state = {
                patientId: pidInput ? (pidInput.value || '').trim() : '',
                studies: studiesCache,
                sortConfig: { column: sortConfig.column, direction: sortConfig.direction },
                selectedStudyUid: selectedStudyUid,
                timestamp: Date.now()
            };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            console.error('[SHM] No se pudo guardar estado:', e);
        }
    }

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            const state = JSON.parse(raw);
            if (!state || typeof state !== 'object') return null;
            if (Date.now() - (state.timestamp || 0) > MAX_AGE_MS) {
                localStorage.removeItem(STORAGE_KEY);
                return null;
            }
            return state;
        } catch (e) {
            return null;
        }
    }

    async function postJson(endpoint, body) {
        const res = await fetch(API_BASE + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.success === false) {
            const err = data.error || data.message || ('HTTP ' + res.status);
            throw new Error(err);
        }
        return data;
    }

    function studySources(s) {
        if (Array.isArray(s.sources) && s.sources.length > 0) {
            return s.sources;
        }
        return [{
            source_node_id: s.source_node_id,
            source_node_name: s.source_node_name,
            source_node_type: s.source_node_type,
            remote_open_mode: s.remote_open_mode,
            is_on_local_pacs: !!s.is_on_local_pacs,
            series_count: typeof s.number_of_series === 'number' ? s.number_of_series : 0,
            instances_count: typeof s.number_of_instances === 'number' ? s.number_of_instances : 0
        }];
    }

    function originBadgeHtml(isLocal) {
        return isLocal
            ? '<span class="badge bg-success">Local</span>'
            : '<span class="badge bg-secondary">Remoto</span>';
    }

    function formatSeriesInstances(series, instances) {
        const s = series != null ? String(series) : '0';
        const i = instances != null ? String(instances) : '0';
        return s + ' / ' + i;
    }

    function sortStudies(list) {
        if (!sortConfig.column) {
            return list.slice();
        }
        const col = sortConfig.column;
        const dir = sortConfig.direction === 'asc' ? 1 : -1;
        const sorted = list.slice();
        sorted.sort(function (a, b) {
            var va;
            var vb;
            switch (col) {
                case 'study_uid':
                    va = (a.study_instance_uid || '').toLowerCase();
                    vb = (b.study_instance_uid || '').toLowerCase();
                    break;
                case 'patient_name':
                    va = (a.patient_name || '').toLowerCase();
                    vb = (b.patient_name || '').toLowerCase();
                    break;
                case 'study_date':
                    va = a.study_date || '';
                    vb = b.study_date || '';
                    break;
                case 'modalities':
                    va = (a.modalities_in_study || '').toLowerCase();
                    vb = (b.modalities_in_study || '').toLowerCase();
                    break;
                case 'study_description':
                    va = (a.study_description || '').toLowerCase();
                    vb = (b.study_description || '').toLowerCase();
                    break;
                case 'series_instances':
                    va = (a.number_of_instances || 0) * 1e6 + (a.number_of_series || 0);
                    vb = (b.number_of_instances || 0) * 1e6 + (b.number_of_series || 0);
                    if (va < vb) return -1 * dir;
                    if (va > vb) return 1 * dir;
                    return 0;
                case 'source_node':
                    va = (a.source_node_name || '').toLowerCase();
                    vb = (b.source_node_name || '').toLowerCase();
                    break;
                case 'origin':
                    va = a.is_on_local_pacs ? 0 : 1;
                    vb = b.is_on_local_pacs ? 0 : 1;
                    if (va < vb) return -1 * dir;
                    if (va > vb) return 1 * dir;
                    return 0;
                default:
                    return 0;
            }
            if (va < vb) return -1 * dir;
            if (va > vb) return 1 * dir;
            return 0;
        });
        return sorted;
    }

    function updateSortHeaderIcons() {
        document.querySelectorAll('#shmResultsTable thead th.sortable').forEach(function (th) {
            const key = th.getAttribute('data-shm-sort');
            const icon = th.querySelector('.shm-sort-icon');
            if (!icon) return;
            if (sortConfig.column === key) {
                icon.className = 'fas shm-sort-icon ' + (sortConfig.direction === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
            } else {
                icon.className = 'fas fa-sort shm-sort-icon text-muted';
            }
        });
    }

    function bindColumnSorting() {
        const thead = document.querySelector('#shmResultsTable thead');
        if (!thead || thead.dataset.shmSortBound === '1') return;
        thead.dataset.shmSortBound = '1';
        thead.addEventListener('click', function (ev) {
            const th = ev.target.closest('th.sortable');
            if (!th) return;
            const key = th.getAttribute('data-shm-sort');
            if (!key) return;
            if (sortConfig.column === key) {
                sortConfig.direction = sortConfig.direction === 'asc' ? 'desc' : 'asc';
            } else {
                sortConfig.column = key;
                sortConfig.direction = key === 'study_date' ? 'desc' : 'asc';
            }
            updateSortHeaderIcons();
            renderTable(sortStudies(studiesCache));
            saveState();
        });
    }

    function updateSeriesInstCells(tr, src) {
        const cell = tr.querySelector('.shm-serinst-cell');
        if (!cell || !src) return;
        var sc = src.series_count != null ? src.series_count : 0;
        var ic = src.instances_count != null ? src.instances_count : 0;
        cell.textContent = formatSeriesInstances(sc, ic);
        cell.setAttribute('title', 'Series / instancias en el nodo seleccionado');
    }

    function renderTable(studies) {
        const tbody = getEl('shmResultsBody');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (!studies || studies.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-muted text-center py-4">Sin resultados</td></tr>';
            updateSortHeaderIcons();
            return;
        }

        studies.forEach(function (s) {
            const tr = document.createElement('tr');
            tr.setAttribute('data-study-uid', s.study_instance_uid || '');
            tr.classList.add('shm-data-row');
            const sources = studySources(s);
            const primary = sources[0];
            let nodeCellHtml;
            if (sources.length > 1) {
                nodeCellHtml = '<select class="form-select form-select-sm shm-node-select" aria-label="Nodo origen del estudio">';
                sources.forEach(function (src) {
                    const nid = String(src.source_node_id);
                    const loc = src.is_on_local_pacs ? '1' : '0';
                    const sc = src.series_count != null ? src.series_count : 0;
                    const ic = src.instances_count != null ? src.instances_count : 0;
                    const label = escapeHtml(src.source_node_name || ('Nodo ' + nid));
                    nodeCellHtml += '<option value="' + escapeAttr(nid) + '" data-local="' + loc + '" data-series="' + escapeAttr(String(sc)) + '" data-instances="' + escapeAttr(String(ic)) + '">' + label + '</option>';
                });
                nodeCellHtml += '</select>';
            } else {
                nodeCellHtml = escapeHtml(primary.source_node_name || '');
            }

            const serInst = formatSeriesInstances(
                s.number_of_series != null ? s.number_of_series : primary.series_count,
                s.number_of_instances != null ? s.number_of_instances : primary.instances_count
            );

            tr.innerHTML =
                '<td><code class="small">' + escapeHtml((s.study_instance_uid || '').substring(0, 20)) + '…</code></td>' +
                '<td>' + escapeHtml(s.patient_name || '') + '</td>' +
                '<td>' + escapeHtml(s.study_date || '') + '</td>' +
                '<td>' + escapeHtml(s.modalities_in_study || '') + '</td>' +
                '<td>' + escapeHtml(s.study_description || '') + '</td>' +
                '<td class="shm-serinst-cell text-nowrap" title="Series / instancias (nodo por defecto o seleccionado)">' + escapeHtml(serInst) + '</td>' +
                '<td class="shm-node-cell">' + nodeCellHtml + '</td>' +
                '<td class="shm-origin-cell"><span class="shm-origin-badge">' + originBadgeHtml(!!primary.is_on_local_pacs) + '</span></td>' +
                '<td class="shm-hint-cell text-muted small"></td>' +
                '<td><button type="button" class="btn btn-sm btn-primary shm-open" data-uid="' + escapeAttr(s.study_instance_uid) + '" data-node="' + escapeAttr(String(primary.source_node_id)) + '" data-local="' + (primary.is_on_local_pacs ? '1' : '0') + '">Abrir</button></td>';

            tbody.appendChild(tr);

            const sel = tr.querySelector('.shm-node-select');
            if (sel) {
                sel.addEventListener('change', function () {
                    const opt = sel.options[sel.selectedIndex];
                    const isLoc = opt.getAttribute('data-local') === '1';
                    const badgeWrap = tr.querySelector('.shm-origin-badge');
                    if (badgeWrap) {
                        badgeWrap.innerHTML = originBadgeHtml(isLoc);
                    }
                    updateSeriesInstCells(tr, {
                        series_count: parseInt(opt.getAttribute('data-series') || '0', 10),
                        instances_count: parseInt(opt.getAttribute('data-instances') || '0', 10)
                    });
                });
            }
        });

        tbody.querySelectorAll('.shm-open').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const tr = btn.closest('tr');
                const rowSel = tr ? tr.querySelector('.shm-node-select') : null;
                var nodeId;
                var isLocal;
                if (rowSel) {
                    const opt = rowSel.options[rowSel.selectedIndex];
                    nodeId = parseInt(opt.value, 10);
                    isLocal = opt.getAttribute('data-local') === '1';
                } else {
                    nodeId = parseInt(btn.getAttribute('data-node'), 10);
                    isLocal = btn.getAttribute('data-local') === '1';
                }
                openStudy(btn.getAttribute('data-uid'), nodeId, isLocal, tr);
            });
        });

        setupRowSelection();
        updateSortHeaderIcons();

        if (pendingSelectUid) {
            var row = null;
            tbody.querySelectorAll('tr[data-study-uid]').forEach(function (r) {
                if (r.getAttribute('data-study-uid') === pendingSelectUid) {
                    row = r;
                }
            });
            if (row) {
                tbody.querySelectorAll('tr.shm-row-selected').forEach(function (r) {
                    r.classList.remove('shm-row-selected');
                });
                row.classList.add('shm-row-selected');
            }
            pendingSelectUid = null;
        }
    }

    function setupRowSelection() {
        const tbody = getEl('shmResultsBody');
        if (!tbody) return;

        tbody.querySelectorAll('tr.shm-data-row').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('button') || e.target.closest('select') || e.target.closest('option')) {
                    return;
                }
                tbody.querySelectorAll('tr.shm-row-selected').forEach(function (r) {
                    r.classList.remove('shm-row-selected');
                });
                row.classList.add('shm-row-selected');
                saveState();
            });
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function escapeAttr(s) {
        return String(s || '').replace(/"/g, '&quot;');
    }

    async function openStudy(uid, sourceNodeId, isLocal, row) {
        const hintCell = row ? row.querySelector('.shm-hint-cell') : null;
        if (hintCell) hintCell.textContent = 'Generando enlace…';

        try {
            const data = await postJson('viewer-link.php', {
                study_instance_uid: uid,
                source_node_id: sourceNodeId,
                is_on_local_pacs: isLocal
            });

            if (hintCell) {
                hintCell.textContent = data.hint || '';
            }

            if (data.open_url) {
                window.open(data.open_url, '_blank', 'noopener,noreferrer');
            } else {
                showAlert('warning', data.hint || 'No se pudo generar URL de visor.');
            }
        } catch (e) {
            if (hintCell) hintCell.textContent = '';
            showAlert('danger', e.message || String(e));
        }
    }

    function applySearchResultMeta(data) {
        getEl('shmMeta').textContent = data.count + ' estudio(s)' +
            (data.errors && data.errors.length ? ' · ' + data.errors.length + ' nodo(s) con error' : '');

        if (data.errors && data.errors.length) {
            const errBox = getEl('shmErrors');
            if (errBox) {
                errBox.classList.remove('d-none');
                errBox.innerHTML = '<strong>Errores por nodo:</strong><ul class="mb-0">' +
                    data.errors.map(function (e) {
                        return '<li>' + escapeHtml(e.node_name || ('Nodo ' + e.node_id)) + ': ' + escapeHtml(e.error) + '</li>';
                    }).join('') + '</ul>';
            }
        } else {
            const errBox = getEl('shmErrors');
            if (errBox) {
                errBox.classList.add('d-none');
                errBox.innerHTML = '';
            }
        }
    }

    async function runSearch() {
        hideAlert();
        const pid = (getEl('shmPatientId').value || '').trim();
        if (!pid) {
            showAlert('warning', 'Ingrese Patient ID (idpaciente).');
            return;
        }

        const tbody = getEl('shmResultsBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Buscando…</td></tr>';
        }

        try {
            const data = await postJson('search.php', { patient_id: pid });
            studiesCache = data.studies || [];
            applySearchResultMeta(data);
            renderTable(sortStudies(studiesCache));
            saveState();
        } catch (e) {
            if (tbody) tbody.innerHTML = '';
            studiesCache = [];
            saveState();
            showAlert('danger', e.message || String(e));
        }
    }

    function restoreFromStorage() {
        const state = loadState();
        if (!state) return;
        const pidInput = getEl('shmPatientId');
        if (pidInput && state.patientId) {
            pidInput.value = state.patientId;
        }
        if (state.sortConfig && state.sortConfig.column) {
            sortConfig = {
                column: state.sortConfig.column,
                direction: state.sortConfig.direction === 'asc' ? 'asc' : 'desc'
            };
        }
        if (Array.isArray(state.studies) && state.studies.length > 0) {
            studiesCache = state.studies;
            pendingSelectUid = state.selectedStudyUid || null;
            applySearchResultMeta({ count: studiesCache.length, errors: [] });
            getEl('shmMeta').textContent = studiesCache.length + ' estudio(s) · restaurado de la sesión anterior (24 h)';
            renderTable(sortStudies(studiesCache));
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindColumnSorting();
        restoreFromStorage();
        updateSortHeaderIcons();

        const btn = getEl('shmSearchBtn');
        if (btn) btn.addEventListener('click', runSearch);
        const input = getEl('shmPatientId');
        if (input) {
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    runSearch();
                }
            });
            input.addEventListener('blur', function () {
                saveState();
            });
        }
    });
})();
