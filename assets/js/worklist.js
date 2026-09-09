/**
 * JavaScript para gestión de Worklist
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

let worklistTable;
let selectedFile = null;

/** Cursor de cambios incrementales (timestamp del servidor en formato Y-m-d H:i:s). */
let worklistChangesCursor = '';
/** Identificador del intervalo de auto-refresh para poder pausarlo/reanudarlo. */
let worklistAutoRefreshTimer = null;
/** Intervalo de polling en milisegundos. */
const WORKLIST_AUTO_REFRESH_MS = 10000;
/** Marca si una llamada de cambios esta en curso para evitar solapamientos. */
let worklistChangesInFlight = false;

const WORKLIST_FILTERS_STORAGE_KEY = 'worklist_filters_v1';

/** Valores permitidos del selector «Mostrar N registros» (DataTables). */
const WORKLIST_PAGE_LENGTH_OPTIONS = [10, 25, 50, 100];

/** Rango efectivo del último fetch (solo fechas); el resumen del header refleja filas visibles tras filtros locales. */
let worklistSummaryDateRange = { date_from: '', date_to: '' };

function getWorklistPageLengthFromStorage() {
    try {
        const raw = localStorage.getItem(WORKLIST_FILTERS_STORAGE_KEY);
        if (!raw) {
            return 25;
        }
        const n = parseInt(JSON.parse(raw).page_length, 10);
        if (Number.isFinite(n) && WORKLIST_PAGE_LENGTH_OPTIONS.indexOf(n) !== -1) {
            return n;
        }
    } catch (e) {
        console.warn('worklist: page_length en storage inválido', e);
    }
    return 25;
}

function getDefaultWorklistDateRange() {
    const today = new Date();
    const iso = toISODateLocal(today);
    return {
        dateFrom: iso,
        dateTo: iso
    };
}

function toISODateLocal(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
}

/** Fecha ISO YYYY-MM-DD → DD-MM-AAAA para tabla y resumen. */
function formatWorklistDateDisplay(iso) {
    if (iso == null || iso === undefined) {
        return '';
    }
    const s = String(iso).trim();
    if (s === '') {
        return '';
    }
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) {
        return m[3] + '-' + m[2] + '-' + m[1];
    }
    return s;
}

function restoreWorklistFilters() {
    const def = getDefaultWorklistDateRange();
    try {
        const raw = localStorage.getItem(WORKLIST_FILTERS_STORAGE_KEY);
        if (raw) {
            const o = JSON.parse(raw);
            $('#filterDateFrom').val(o.date_from || def.dateFrom);
            $('#filterDateTo').val(o.date_to || def.dateTo);
            $('#filterModality').val(o.modality || '');
            $('#filterStatus').val(o.status || '');
            $('#filterOrthancSync').val(o.orthanc_sync || '');
            if (o.table_search !== undefined && o.table_search !== null) {
                $('#worklistTableSearch').val(o.table_search);
            }
            return;
        }
    } catch (e) {
        console.warn('worklist: no se pudo restaurar filtros', e);
    }
    $('#filterDateFrom').val(def.dateFrom);
    $('#filterDateTo').val(def.dateTo);
    $('#filterModality').val('');
    $('#filterStatus').val('');
    $('#filterOrthancSync').val('');
    $('#worklistTableSearch').val('');
}

function saveWorklistFilters() {
    try {
        let page_length = 25;
        if (worklistTable) {
            page_length = worklistTable.page.len();
        } else {
            try {
                const raw = localStorage.getItem(WORKLIST_FILTERS_STORAGE_KEY);
                if (raw) {
                    const prev = JSON.parse(raw);
                    const p = parseInt(prev.page_length, 10);
                    if (Number.isFinite(p) && WORKLIST_PAGE_LENGTH_OPTIONS.indexOf(p) !== -1) {
                        page_length = p;
                    }
                }
            } catch (e2) {
                /* ignorar */
            }
        }
        const o = {
            date_from: $('#filterDateFrom').val() || '',
            date_to: $('#filterDateTo').val() || '',
            modality: $('#filterModality').val() || '',
            status: $('#filterStatus').val() || '',
            orthanc_sync: $('#filterOrthancSync').val() || '',
            table_search: $('#worklistTableSearch').val() || '',
            page_length: page_length
        };
        localStorage.setItem(WORKLIST_FILTERS_STORAGE_KEY, JSON.stringify(o));
    } catch (e) {
        console.warn('worklist: no se pudo guardar filtros', e);
    }
}

function renderWorklistSummary(s) {
    const el = document.getElementById('worklistSummary');
    if (!el) {
        return;
    }
    if (!s) {
        el.innerHTML = '';
        return;
    }
    let rangeText = '';
    if (s.date_from && s.date_to) {
        rangeText =
            formatWorklistDateDisplay(s.date_from) + ' → ' + formatWorklistDateDisplay(s.date_to);
    } else if (s.date_from) {
        rangeText = 'Desde ' + formatWorklistDateDisplay(s.date_from);
    } else if (s.date_to) {
        rangeText = 'Hasta ' + formatWorklistDateDisplay(s.date_to);
    } else {
        rangeText = '—';
    }
    const mods = Object.entries(s.by_modality || {})
        .map(function (kv) {
            const k = kv[0];
            const v = kv[1];
            return (
                '<span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 me-1 mb-1">' +
                escapeHtmlWorklist(k) +
                ': <strong>' +
                String(v) +
                '</strong></span>'
            );
        })
        .join('');
    el.innerHTML =
        '<div class="mb-2"><span class="opacity-90">Rango aplicado: </span><strong>' +
        escapeHtmlWorklist(rangeText) +
        '</strong></div>' +
        '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">' +
        '<span class="badge bg-white text-dark">Total turnos: <strong>' +
        String(s.total) +
        '</strong></span>' +
        '<span class="badge bg-success">Con estudio PACS: ' +
        String(s.with_pacs_uid) +
        '</span>' +
        '<span class="badge bg-warning text-dark">Sin PACS (no cancelados): ' +
        String(s.without_pacs_excl_cancelled) +
        '</span>' +
        '<span class="badge bg-secondary">Completados sin UID PACS: ' +
        String(s.completed_without_pacs) +
        '</span>' +
        '</div>' +
        (mods ? '<div class="d-flex flex-wrap align-items-center"><span class="me-2 opacity-90">Por modalidad:</span>' + mods + '</div>' : '');
}

function buildWorklistSummaryFromRows(rows) {
    const byModality = {};
    let withPacs = 0;
    let withoutPacsExclCancelled = 0;
    let completedWithoutPacs = 0;

    rows.forEach(function (row) {
        let mod = String(row.modality || '').trim();
        if (!mod) {
            mod = '—';
        }
        byModality[mod] = (byModality[mod] || 0) + 1;

        const uid = String(row.pacs_study_instance_uid || '').trim();
        const st = row.status || '';

        if (uid !== '') {
            withPacs++;
        } else if (st !== 'cancelled') {
            withoutPacsExclCancelled++;
        }
        if (st === 'completed' && uid === '') {
            completedWithoutPacs++;
        }
    });

    const keys = Object.keys(byModality).sort(function (a, b) {
        return a.toLowerCase().localeCompare(b.toLowerCase(), undefined, { numeric: true, sensitivity: 'base' });
    });
    const sorted = {};
    keys.forEach(function (k) {
        sorted[k] = byModality[k];
    });

    return {
        total: rows.length,
        date_from: worklistSummaryDateRange.date_from,
        date_to: worklistSummaryDateRange.date_to,
        by_modality: sorted,
        with_pacs_uid: withPacs,
        without_pacs_excl_cancelled: withoutPacsExclCancelled,
        completed_without_pacs: completedWithoutPacs
    };
}

function updateWorklistSummaryFromTable() {
    if (!worklistTable) {
        return;
    }
    const rows = worklistTable.rows({ search: 'applied' }).data().toArray();
    renderWorklistSummary(buildWorklistSummaryFromRows(rows));
}

function installWorklistLocalFiltersOnce() {
    if (window.worklistLocalFilterInstalled) {
        return;
    }
    window.worklistLocalFilterInstalled = true;
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (!settings || !settings.nTable || settings.nTable.id !== 'worklistTable') {
            return true;
        }
        const api = new $.fn.dataTable.Api(settings);
        const row = api.row(dataIndex).data();
        if (!row) {
            return true;
        }

        const mod = ($('#filterModality').val() || '').trim();
        if (mod) {
            const rowMod = String(row.modality || '').trim();
            if (mod === '__none__') {
                if (rowMod !== '') {
                    return false;
                }
            } else if (rowMod !== mod) {
                return false;
            }
        }

        const st = ($('#filterStatus').val() || '').trim();
        if (st && String(row.status || '') !== st) {
            return false;
        }

        const orth = ($('#filterOrthancSync').val() || '').trim();
        if (orth) {
            const os = String(row.orthanc_sync_status || '').toLowerCase();
            if (orth === 'ok' && os !== 'ok') {
                return false;
            }
            if (orth === 'error' && os !== 'error') {
                return false;
            }
            if (orth === 'pending' && (os === 'ok' || os === 'error')) {
                return false;
            }
        }

        return true;
    });
}

/**
 * Valor seguro para atributo data-accession (evita romper HTML/JS con comillas o &).
 */
function escapeDataAttr(s) {
    if (s == null || s === undefined) {
        return '';
    }
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/\r?\n/g, ' ');
}

function escapeHtmlWorklist(s) {
    if (s == null || s === undefined) {
        return '';
    }
    const t = document.createElement('div');
    t.textContent = String(s);
    return t.innerHTML;
}

/**
 * Confirmación Bootstrap (misma línea visual que los modales de Worklist). Devuelve Promise resuelta a true/false.
 */
function showWorklistConfirm(title, message, options = {}) {
    const confirmText = options.confirmText || 'Confirmar';
    const cancelText = options.cancelText || 'Cancelar';
    const variant = options.confirmVariant || 'primary';
    const allowed = ['primary', 'danger', 'warning', 'info', 'success', 'secondary'];
    const btnVariant = allowed.indexOf(variant) >= 0 ? variant : 'primary';

    return new Promise(function (resolve) {
        let settled = false;
        function done(value) {
            if (settled) {
                return;
            }
            settled = true;
            resolve(value);
        }

        const modalId = 'worklistConfirmModal';
        const prev = document.getElementById(modalId);
        if (prev) {
            prev.remove();
        }

        const lines = String(message).split('\n');
        const bodyHtml = lines
            .map(function (line) {
                if (line === '') {
                    return '<p class="mb-2 text-muted small">&nbsp;</p>';
                }
                return '<p class="mb-2 mb-md-0">' + escapeHtmlWorklist(line) + '</p>';
            })
            .join('');

        const html =
            '<div class="modal fade" id="' +
            modalId +
            '" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="true" aria-hidden="true">' +
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content">' +
            '<div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">' +
            '<h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>' +
            escapeHtmlWorklist(title) +
            '</h5>' +
            '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
            '</div>' +
            '<div class="modal-body">' +
            bodyHtml +
            '</div>' +
            '<div class="modal-footer border-0">' +
            '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
            escapeHtmlWorklist(cancelText) +
            '</button>' +
            '<button type="button" class="btn btn-' +
            btnVariant +
            '" id="' +
            modalId +
            'Ok">' +
            escapeHtmlWorklist(confirmText) +
            '</button>' +
            '</div></div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        const el = document.getElementById(modalId);
        const bs = new bootstrap.Modal(el);
        let confirmed = false;

        document.getElementById(modalId + 'Ok').addEventListener('click', function () {
            confirmed = true;
            bs.hide();
        });

        el.addEventListener(
            'hidden.bs.modal',
            function onHidden() {
                el.removeEventListener('hidden.bs.modal', onHidden);
                done(confirmed);
                el.remove();
            },
            { once: true }
        );

        bs.show();
    });
}

// Inicializar cuando el DOM esté listo
$(document).ready(function() {
    restoreWorklistFilters();
    initializeTable();
    setupWorklistTableSearch();
    setupWorklistScrollTop();
    setupWorklistTableActions();
    setupFileUpload();
    setupWorklistQuickDateButtons();
    setupWorklistModalityChips();
    setupWorklistAutoRefreshIndicator();
    startWorklistAutoRefresh();
});

/**
 * Acciones de fila sin onclick inline (evita SyntaxError por comillas en accession / JSON).
 */
function setupWorklistTableActions() {
    $('#worklistTable').on('click', '.js-wl-edit', function (e) {
        e.preventDefault();
        const acc = $(this).attr('data-accession');
        if (acc !== undefined && acc !== null) {
            editWorklist(acc);
        }
    });
    $('#worklistTable').on('click', '.js-wl-unpublish', function (e) {
        e.preventDefault();
        const acc = $(this).attr('data-accession');
        if (acc !== undefined && acc !== null) {
            unpublishOrthancOnly(acc);
        }
    });
    $('#worklistTable').on('click', '.js-wl-delete', function (e) {
        e.preventDefault();
        const acc = $(this).attr('data-accession');
        if (acc !== undefined && acc !== null) {
            deleteWorklist(acc);
        }
    });
}

/**
 * Inicializar DataTable
 */
function initializeTable() {
    installWorklistLocalFiltersOnce();

    worklistTable = $('#worklistTable').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        processing: true,
        serverSide: false,
        dom: 'lrtip',
        search: {
            smart: true,
            caseInsensitive: true
        },
        ajax: {
            url: 'api/worklist.php',
            type: 'GET',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            },
            data: function(d) {
                d.date_from = $('#filterDateFrom').val();
                d.date_to = $('#filterDateTo').val();
            },
            dataSrc: function(json) {
                if (json.success && json.summary) {
                    worklistSummaryDateRange = {
                        date_from: json.summary.date_from || '',
                        date_to: json.summary.date_to || ''
                    };
                } else if (json.success) {
                    worklistSummaryDateRange = { date_from: '', date_to: '' };
                }
                if (json.success) {
                    if (json.server_time) {
                        worklistChangesCursor = json.server_time;
                    } else {
                        worklistChangesCursor = nowAsServerTimestamp();
                    }
                    const byModality = (json.summary && json.summary.by_modality) || {};
                    renderWorklistModalityChips(byModality);
                    return json.data || [];
                }
                renderWorklistModalityChips({});
                return [];
            }
        },
        columns: [
            { data: 'accession_number' },
            { 
                data: 'patient_name',
                render: function(data, type, row) {
                    return data || 'N/A';
                }
            },
            {
                data: 'patient_id',
                render: function(data) {
                    if (data === null || data === undefined || String(data).trim() === '') {
                        return '<span class="text-muted">—</span>';
                    }
                    return escapeHtmlWorklist(String(data));
                }
            },
            {
                data: 'scheduled_date',
                render: function (data, type) {
                    if (type === 'sort' || type === 'type') {
                        return data || '';
                    }
                    const d = formatWorklistDateDisplay(data);
                    if (!d) {
                        return '<span class="text-muted">—</span>';
                    }
                    return escapeHtmlWorklist(d);
                }
            },
            { 
                data: 'scheduled_time',
                render: function(data) {
                    return data ? data.substring(0, 5) : 'N/A';
                }
            },
            { 
                data: 'modality',
                render: function(data) {
                    return data ? `<span class="badge bg-info">${data}</span>` : 'N/A';
                }
            },
            { 
                data: 'status',
                render: function(data) {
                    const statusMap = {
                        'pending': { class: 'status-pending', text: 'Pendiente' },
                        'scheduled': { class: 'status-scheduled', text: 'Programado' },
                        'in_progress': { class: 'status-in_progress', text: 'En Progreso' },
                        'completed': { class: 'status-completed', text: 'Completado' },
                        'cancelled': { class: 'status-cancelled', text: 'Cancelado' }
                    };
                    const status = statusMap[data] || { class: 'status-pending', text: data || '—' };
                    return `<span class="status-badge ${status.class}">${status.text}</span>`;
                }
            },
            {
                data: 'pacs_study_at',
                render: function(data, type, row) {
                    if (!data) {
                        return '<span class="text-muted">—</span>';
                    }
                    const short = typeof data === 'string' && data.length > 16 ? data.substring(0, 16) : data;
                    const uid = row.pacs_study_instance_uid;
                    const title = uid ? 'title="' + escapeDataAttr('UID: ' + uid) + '"' : '';
                    if (uid) {
                        return (
                            '<span class="badge bg-success" ' +
                            title +
                            ' style="font-weight:500">' +
                            escapeHtmlWorklist(String(short)) +
                            '</span>'
                        );
                    }
                    return escapeHtmlWorklist(String(short));
                }
            },
            {
                data: 'orthanc_sync_status',
                render: function(data, type, row) {
                    const s = (data || '').toLowerCase();
                    const err = row.orthanc_sync_error || '';
                    const syncedAt = row.orthanc_synced_at ? ' Sinc: ' + row.orthanc_synced_at : '';
                    let dotClass = 'wl-server-dot--pending';
                    let title = 'No cargado en WL Server (worklist del servidor).' + syncedAt;
                    let aria = 'No cargado en WL Server';
                    if (s === 'ok') {
                        dotClass = 'wl-server-dot--ok';
                        title = 'Cargado en WL Server.' + syncedAt;
                        aria = 'Cargado en WL Server';
                    } else if (s === 'error') {
                        dotClass = 'wl-server-dot--error';
                        title = err ? String(err) : 'Error al cargar en WL Server.';
                        aria = 'Error en WL Server';
                    } else if (!s || s === 'pending') {
                        title = 'No cargado en WL Server o pendiente de envío.' + syncedAt;
                    }
                    const safeTitle = escapeDataAttr(title);
                    const safeAria = escapeDataAttr(aria);
                    return (
                        '<span class="wl-server-dot ' +
                        dotClass +
                        '" title="' +
                        safeTitle +
                        '" role="img" aria-label="' +
                        safeAria +
                        '"></span>'
                    );
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    const accEsc = escapeDataAttr(row.accession_number);
                    const canUnpublish = row.orthanc_sync_status === 'ok' || row.orthanc_sync_status === 'error';
                    const unpublishBtn = canUnpublish
                        ? `<button type="button" class="btn btn-sm btn-outline-warning js-wl-unpublish" data-accession="${accEsc}" title="Quitar solo del WL Server (worklist); conservar en el portal">
                                <i class="fas fa-ban"></i>
                           </button>`
                        : '';
                    return `
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-primary js-wl-edit" data-accession="${accEsc}" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            ${unpublishBtn}
                            <button type="button" class="btn btn-sm btn-danger js-wl-delete" data-accession="${accEsc}" title="Eliminar del portal y del WL Server">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;
                }
            }
        ],
        order: [[3, 'desc'], [4, 'desc']], // Fecha y hora programada (índices tras ID paciente)
        pageLength: getWorklistPageLengthFromStorage(),
        lengthMenu: [
            [10, 25, 50, 100],
            [10, 25, 50, 100]
        ],
        responsive: true,
        initComplete: function () {
            const q = ($('#worklistTableSearch').val() || '').trim();
            const api = this.api();
            if (q) {
                api.search(q);
            }
            api.draw(false);
        },
        drawCallback: function () {
            updateWorklistSummaryFromTable();
        }
    });

    $('#worklistTable').on('length.dt', function () {
        saveWorklistFilters();
    });
}

let worklistSearchDebounceTimer;

function setupWorklistTableSearch() {
    $('#worklistTableSearch').on('input', function () {
        const el = this;
        clearTimeout(worklistSearchDebounceTimer);
        worklistSearchDebounceTimer = setTimeout(function () {
            saveWorklistFilters();
            if (worklistTable) {
                worklistTable.search(el.value || '').draw();
            }
        }, 100);
    });
}

function setupWorklistScrollTop() {
    const btn = document.getElementById('worklistScrollTopBtn');
    const anchor = document.getElementById('worklistTopAnchor');
    if (!btn || !anchor) {
        return;
    }
    const onScroll = function () {
        if (window.scrollY > 260) {
            btn.classList.add('visible');
        } else {
            btn.classList.remove('visible');
        }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    btn.addEventListener('click', function () {
        anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}

/**
 * Filtros modalidad / estado / WL Server: solo cliente (lista ya cargada por rango).
 */
function applyWorklistLocalFilters() {
    saveWorklistFilters();
    if (worklistTable) {
        worklistTable.draw();
    }
}

/**
 * Recargar lista desde servidor (rango de fechas); mantiene filtros locales en los selects.
 */
function applyFilters() {
    let f = $('#filterDateFrom').val();
    let t = $('#filterDateTo').val();
    if (!f || !t) {
        const d = getDefaultWorklistDateRange();
        if (!f) {
            $('#filterDateFrom').val(d.dateFrom);
        }
        if (!t) {
            $('#filterDateTo').val(d.dateTo);
        }
    }
    saveWorklistFilters();
    if (worklistTable) {
        worklistTable.ajax.reload();
    }
}

/**
 * Aplicar rango de fechas (ambas obligatorias) y recargar
 */
function applyWorklistDateRange() {
    const f = $('#filterDateFrom').val();
    const t = $('#filterDateTo').val();
    if (!f || !t) {
        showAlert('warning', 'Indica fecha desde y fecha hasta, o usa Restablecer para cargar el rango por defecto.');
        return;
    }
    applyFilters();
}

/**
 * Restablecer rango por defecto (~30 días) y demás filtros
 */
function clearFilters() {
    const d = getDefaultWorklistDateRange();
    $('#filterDateFrom').val(d.dateFrom);
    $('#filterDateTo').val(d.dateTo);
    $('#filterModality').val('');
    $('#filterStatus').val('');
    $('#filterOrthancSync').val('');
    $('#worklistTableSearch').val('');
    if (typeof refreshWorklistQuickDateActiveFromInputs === 'function') {
        refreshWorklistQuickDateActiveFromInputs();
    }
    if (typeof renderWorklistModalityChips === 'function') {
        renderWorklistModalityChips({});
    }
    saveWorklistFilters();
    if (worklistTable) {
        worklistTable.search('');
    }
    applyFilters();
}

/**
 * Abrir modal para nuevo worklist
 */
function openNewModal() {
    $('#modalTitle').text('Nuevo Worklist');
    $('#worklistForm')[0].reset();
    $('#editAccession').val('');
    $('#accessionNumber').prop('disabled', false);
    const modal = new bootstrap.Modal(document.getElementById('worklistModal'));
    modal.show();
}

/**
 * Abrir modal para editar worklist
 */
async function editWorklist(accession) {
    try {
        const response = await fetch(`api/worklist.php?accession=${encodeURIComponent(accession)}`, {
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            const item = data.data;
            $('#modalTitle').text('Editar Worklist');
            $('#editAccession').val(accession);
            $('#accessionNumber').val(item.accession_number).prop('disabled', true);
            $('#patientName').val(item.patient_name || '');
            $('#patientId').val(item.patient_id || '');
            $('#patientBirthDate').val(item.patient_birth_date || '');
            $('#patientSex').val(item.patient_sex || '');
            $('#modality').val(item.modality || '');
            $('#referringPhysician').val(item.referring_physician || '');
            $('#equipmentName').val(item.equipment_name || '');
            $('#scheduledDate').val(item.scheduled_date || '');
            $('#scheduledTime').val(item.scheduled_time ? item.scheduled_time.substring(0, 5) : '');
            $('#procedureDescription').val(item.procedure_description || '');
            $('#reasonForStudy').val(item.reason_for_study || '');
            $('#status').val(item.status || 'pending');
            
            const modal = new bootstrap.Modal(document.getElementById('worklistModal'));
            modal.show();
        } else {
            showAlert('error', data.message || 'Error al cargar el worklist');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('error', 'Error al cargar el worklist: ' + error.message);
    }
}

/**
 * Guardar worklist
 */
async function saveWorklist() {
    const form = document.getElementById('worklistForm');
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    const data = {};
    
    for (let [key, value] of formData.entries()) {
        if (value) {
            data[key] = value;
        }
    }
    
    // Convertir hora a formato HH:MM:SS
    if (data.scheduled_time && !data.scheduled_time.includes(':')) {
        data.scheduled_time = data.scheduled_time + ':00';
    }
    
    const isEdit = $('#editAccession').val();
    const url = isEdit ? `api/worklist.php?accession=${encodeURIComponent(isEdit)}` : 'api/worklist.php';
    const method = isEdit ? 'PUT' : 'POST';
    
    try {
        showLoading(true);
        
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('success', isEdit ? 'Worklist actualizado exitosamente' : 'Worklist creado exitosamente');
            const modal = bootstrap.Modal.getInstance(document.getElementById('worklistModal'));
            modal.hide();
            worklistTable.ajax.reload();
        } else {
            showAlert('error', result.message || 'Error al guardar el worklist');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('error', 'Error al guardar el worklist: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Quitar entrada del worklist en Orthanc; la fila permanece en la BD del portal.
 */
async function unpublishOrthancOnly(accession) {
    const ok = await showWorklistConfirm(
        'Quitar de Orthanc',
        '¿Quitar esta entrada solo del worklist de Orthanc?\n\nLa fila seguirá visible en el portal (estado Orthanc: pendiente).',
        { confirmText: 'Quitar de Orthanc', confirmVariant: 'warning' }
    );
    if (!ok) {
        return;
    }
    try {
        showLoading(true);
        const response = await fetch(
            `api/worklist.php?accession=${encodeURIComponent(accession)}&scope=orthanc_only`,
            {
                method: 'DELETE',
                headers: { 'Authorization': 'Bearer ' + getAuthToken() }
            }
        );
        const result = await response.json();
        if (result.success) {
            showAlert('success', result.message || 'Quitado de Orthanc');
            worklistTable.ajax.reload();
        } else {
            showAlert('error', result.message || 'Error al quitar de Orthanc');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('error', 'Error: ' + error.message);
    } finally {
        showLoading(false);
    }
}

async function deleteWorklist(accession) {
    const ok = await showWorklistConfirm(
        'Eliminar worklist',
        '¿Eliminar del portal y del worklist de Orthanc?\n\nSe borrará la fila en la base local.',
        { confirmText: 'Eliminar', confirmVariant: 'danger' }
    );
    if (!ok) {
        return;
    }

    try {
        showLoading(true);
        
        const response = await fetch(`api/worklist.php?accession=${encodeURIComponent(accession)}`, {
            method: 'DELETE',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('success', 'Worklist eliminado exitosamente');
            worklistTable.ajax.reload();
        } else {
            showAlert('error', result.message || 'Error al eliminar el worklist');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('error', 'Error al eliminar el worklist: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Abrir modal de upload
 */
function openUploadModal() {
    selectedFile = null;
    $('#txtFileInput').val('');
    $('#fileInfo').hide();
    $('#uploadBtn').prop('disabled', true);
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}

/**
 * Configurar área de upload
 */
function setupFileUpload() {
    const uploadArea = document.getElementById('fileUploadArea');
    
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        uploadArea.addEventListener(eventName, () => {
            uploadArea.classList.add('dragover');
        }, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, () => {
            uploadArea.classList.remove('dragover');
        }, false);
    });
    
    uploadArea.addEventListener('drop', handleDrop, false);
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    }
}

/**
 * Manejar selección de archivo
 */
function handleFileSelect(event) {
    const file = event.target.files[0];
    if (file) {
        handleFile(file);
    }
}

/**
 * Manejar archivo seleccionado
 */
function handleFile(file) {
    if (!file.name.endsWith('.txt')) {
        showAlert('error', 'Por favor seleccione un archivo .txt');
        return;
    }
    
    selectedFile = file;
    $('#fileName').text(file.name);
    $('#fileInfo').show();
    $('#uploadBtn').prop('disabled', false);
}

/**
 * Subir archivo TXT
 */
async function uploadTxtFile() {
    if (!selectedFile) {
        showAlert('error', 'Por favor seleccione un archivo');
        return;
    }
    
    const formData = new FormData();
    formData.append('txt', selectedFile);
    
    try {
        showLoading(true);
        
        const response = await fetch('api/worklist.php', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('success', 'Archivo importado exitosamente');
            const modal = bootstrap.Modal.getInstance(document.getElementById('uploadModal'));
            modal.hide();
            worklistTable.ajax.reload();
        } else {
            showAlert('error', result.message || 'Error al importar el archivo');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('error', 'Error al importar el archivo: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Sincronizar con Orthanc
 */
async function syncOrthanc() {
    const ok = await showWorklistConfirm(
        'Sincronizar con Orthanc',
        'Se enviarán a Orthanc los turnos activos que aún no están sincronizados o que tuvieron error.',
        { confirmText: 'Sincronizar', confirmVariant: 'info' }
    );
    if (!ok) {
        return;
    }

    try {
        showLoading(true);
        
        const response = await fetch('api/worklist.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: JSON.stringify({ action: 'sync' })
        });
        
        const result = await response.json();
        
        if (result.success) {
            const message = result.synced > 0 
                ? `Sincronización completada: ${result.synced} archivo(s) procesado(s)`
                : 'No hay archivos para sincronizar';
            showAlert('success', message);
            worklistTable.ajax.reload();
        } else {
            showAlert('error', result.message || 'Error al sincronizar');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('error', 'Error al sincronizar: ' + error.message);
    } finally {
        showLoading(false);
    }
}

const RECONCILE_TYPE_LABELS = {
    orphan_in_orthanc: 'Huérfano en Orthanc',
    bd_ok_missing_in_orthanc: 'BD indica ok pero no está en Orthanc',
    accession_mismatch: 'Accession distinto entre portal y Orthanc',
    bd_ok_without_remote_id: 'BD ok sin UUID (REST)',
    bd_ok_missing_file: 'BD ok pero falta archivo .wl',
    orphan_file_in_orthanc_dir: 'Archivo .wl sin fila en portal'
};

/**
 * Validar coincidencia entre la BD local y Orthanc (puede tardar si hay muchas entradas).
 */
async function runReconcileOrthanc() {
    const ok = await showWorklistConfirm(
        'Validar vs Orthanc',
        'Se consultará Orthanc y se comparará con la base del portal. Puede tardar si hay muchas entradas.',
        { confirmText: 'Continuar', confirmVariant: 'primary' }
    );
    if (!ok) {
        return;
    }
    try {
        showLoading(true);
        const response = await fetch('api/worklist.php?reconcile=1', {
            headers: { 'Authorization': 'Bearer ' + getAuthToken() }
        });
        const json = await response.json();
        if (!json.success) {
            showAlert('error', json.message || 'Error en validación');
            return;
        }
        const d = json.data;
        const summary = document.getElementById('reconcileSummary');
        const inSync = d.in_sync;
        summary.innerHTML = `
            <div class="alert ${inSync ? 'alert-success' : 'alert-warning'} mb-0">
                <strong>${inSync ? 'Coincidencia al 100%' : 'Hay diferencias'}</strong><br>
                Modo: <code>${escapeHtmlWorklist(d.mode)}</code> ·
                Entradas en Orthanc: <strong>${d.orthanc_count}</strong> ·
                Filas en portal: <strong>${d.bd_rows}</strong> ·
                Alineadas (según BD ok): <strong>${d.aligned_count}</strong> ·
                Incidencias: <strong>${d.issue_count}</strong><br>
                <small class="text-muted">${escapeHtmlWorklist(d.generated_at || '')}</small>
            </div>
        `;
        const issuesBody = document.getElementById('reconcileIssuesBody');
        const issuesWrap = document.getElementById('reconcileIssuesWrap');
        const issuesTitle = document.getElementById('reconcileIssuesTitle');
        issuesBody.innerHTML = '';
        (d.issues || []).forEach((issue) => {
            const tr = document.createElement('tr');
            const typeLabel = RECONCILE_TYPE_LABELS[issue.type] || issue.type;
            tr.innerHTML = '<td>' + escapeHtmlWorklist(typeLabel) + '</td><td>' +
                escapeHtmlWorklist(issue.accession_number || '—') + '</td><td><small>' +
                escapeHtmlWorklist(issue.orthanc_worklist_id || '—') + '</small></td><td>' +
                escapeHtmlWorklist(issue.detail || '') + '</td>';
            issuesBody.appendChild(tr);
        });
        issuesWrap.style.display = d.issue_count ? 'block' : 'none';
        issuesTitle.style.display = d.issue_count ? 'block' : 'none';

        const alignedBody = document.getElementById('reconcileAlignedBody');
        const alignedWrap = document.getElementById('reconcileAlignedWrap');
        const alignedTitle = document.getElementById('reconcileAlignedTitle');
        const alignedNote = document.getElementById('reconcileAlignedNote');
        alignedBody.innerHTML = '';
        const aligned = (d.aligned || []).slice(0, 200);
        aligned.forEach((row) => {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + escapeHtmlWorklist(row.accession_number) + '</td><td><small>' +
                escapeHtmlWorklist(row.orthanc_worklist_id || '—') + '</small></td>';
            alignedBody.appendChild(tr);
        });
        const showAligned = aligned.length > 0;
        alignedWrap.style.display = showAligned ? 'block' : 'none';
        alignedTitle.style.display = showAligned ? 'block' : 'none';
        alignedNote.style.display = showAligned && d.aligned_count > 200 ? 'block' : 'none';

        const modal = new bootstrap.Modal(document.getElementById('reconcileModal'));
        modal.show();
    } catch (e) {
        console.error(e);
        showAlert('error', 'Error al validar: ' + e.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Obtener token de autenticación
 */
function getAuthToken() {
    return localStorage.getItem('session_token') || 
           sessionStorage.getItem('session_token') ||
           document.cookie.split('; ').find(row => row.startsWith('session_token='))?.split('=')[1] ||
           '';
}

/**
 * Mostrar alerta
 */
function showAlert(type, message) {
    const container = document.getElementById('alertContainer');
    if (!container) return;
    
    const alertClass = {
        'success': 'alert-success',
        'error': 'alert-danger',
        'warning': 'alert-warning',
        'info': 'alert-info'
    }[type] || 'alert-info';
    
    const alert = document.createElement('div');
    alert.className = `alert ${alertClass} alert-dismissible fade show`;
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    container.appendChild(alert);
    
    // Auto-dismiss después de 5 segundos
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 5000);
}

/**
 * Mostrar/ocultar loading
 */
function showLoading(show) {
    // Implementar según necesidad
    if (show) {
        $('body').css('cursor', 'wait');
    } else {
        $('body').css('cursor', 'default');
    }
}

/* ============================================================
 * Auto-refresh incremental (polling sin redibujar toda la tabla)
 * ============================================================ */

function nowAsServerTimestamp() {
    const d = new Date();
    const pad = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
        pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}

/**
 * Inyecta el indicador discreto al final de la barra de acciones (si existe).
 * Muestra estado (vivo / pausado), hora del último chequeo y badge de novedades.
 */
function setupWorklistAutoRefreshIndicator() {
    if (document.getElementById('worklistAutoRefreshIndicator')) {
        return;
    }
    const bar = document.querySelector('.actions-bar');
    if (!bar) {
        return;
    }
    const wrap = document.createElement('span');
    wrap.id = 'worklistAutoRefreshIndicator';
    wrap.className = 'd-inline-flex align-items-center ms-auto small text-muted';
    wrap.style.gap = '0.4rem';
    wrap.innerHTML =
        '<span class="d-inline-block rounded-circle" id="worklistAutoRefreshDot" ' +
        'style="width:8px;height:8px;background:#198754;box-shadow:0 0 0 0 rgba(25,135,84,0.7);transition:background .2s"></span>' +
        '<span id="worklistAutoRefreshLabel">Actualización automática</span>' +
        '<span id="worklistAutoRefreshLast" class="text-muted"></span>' +
        '<span id="worklistAutoRefreshBadge" class="badge bg-success ms-1" style="display:none">+0</span>';
    bar.appendChild(wrap);
}

function setWorklistAutoRefreshStatus(active) {
    const dot = document.getElementById('worklistAutoRefreshDot');
    const label = document.getElementById('worklistAutoRefreshLabel');
    if (!dot || !label) {
        return;
    }
    if (active) {
        dot.style.background = '#198754';
        label.textContent = 'Actualización automática';
    } else {
        dot.style.background = '#adb5bd';
        label.textContent = 'Pausado';
    }
}

function setWorklistAutoRefreshLast() {
    const el = document.getElementById('worklistAutoRefreshLast');
    if (!el) {
        return;
    }
    const d = new Date();
    const pad = function (n) { return String(n).padStart(2, '0'); };
    el.textContent = '· ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}

function flashWorklistAutoRefreshBadge(count) {
    const badge = document.getElementById('worklistAutoRefreshBadge');
    if (!badge) {
        return;
    }
    if (!count || count <= 0) {
        badge.style.display = 'none';
        return;
    }
    badge.textContent = '+' + count;
    badge.style.display = 'inline-block';
    clearTimeout(badge._hideTimer);
    badge._hideTimer = setTimeout(function () {
        badge.style.display = 'none';
    }, 4000);
}

function startWorklistAutoRefresh() {
    stopWorklistAutoRefresh();
    setWorklistAutoRefreshStatus(true);
    worklistAutoRefreshTimer = setInterval(tickWorklistAutoRefresh, WORKLIST_AUTO_REFRESH_MS);
}

function stopWorklistAutoRefresh() {
    if (worklistAutoRefreshTimer) {
        clearInterval(worklistAutoRefreshTimer);
        worklistAutoRefreshTimer = null;
    }
}

/**
 * Tick del poller: respeta visibilidad del tab y presencia de modales abiertos.
 */
function tickWorklistAutoRefresh() {
    if (document.hidden) {
        return;
    }
    if (document.querySelector('.modal.show')) {
        return;
    }
    if (worklistChangesInFlight) {
        return;
    }
    if (!worklistTable) {
        return;
    }
    if (!worklistChangesCursor) {
        worklistChangesCursor = nowAsServerTimestamp();
        return;
    }
    runWorklistChanges();
}

async function runWorklistChanges() {
    worklistChangesInFlight = true;
    try {
        const params = new URLSearchParams();
        params.set('changes', '1');
        params.set('since', worklistChangesCursor);
        const f = $('#filterDateFrom').val();
        const t = $('#filterDateTo').val();
        if (f) {
            params.set('date_from', f);
        }
        if (t) {
            params.set('date_to', t);
        }
        const response = await fetch('api/worklist.php?' + params.toString(), {
            headers: { 'Authorization': 'Bearer ' + getAuthToken() }
        });
        if (!response.ok) {
            return;
        }
        const json = await response.json();
        if (!json || !json.success) {
            return;
        }
        if (json.server_time) {
            worklistChangesCursor = json.server_time;
        }
        const rows = Array.isArray(json.data) ? json.data : [];
        if (rows.length > 0) {
            const stats = mergeWorklistChangesIntoTable(rows);
            if (stats.added > 0 || stats.updated > 0) {
                flashWorklistAutoRefreshBadge(stats.added);
            }
        }
        setWorklistAutoRefreshLast();
    } catch (e) {
        console.warn('worklist auto-refresh: error', e);
    } finally {
        worklistChangesInFlight = false;
    }
}

/**
 * Merge incremental sobre la DataTable: actualiza filas existentes y agrega nuevas.
 * Conserva paginación, búsqueda y selección porque usamos draw(false).
 *
 * @param {Array<Object>} changes
 * @returns {{added:number, updated:number}}
 */
/* ============================================================
 * Atajos de rango de fechas (Hoy / Ayer / Últimos 7 días)
 * Mantienen el mismo estilo que dashboard-unified.
 * ============================================================ */

function clearWorklistQuickDateButtonStates() {
    ['quickDateToday', 'quickDateYesterday', 'quickDateLast7'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.classList.remove('active');
        }
    });
}

function setWorklistQuickDateButtonActive(preset) {
    clearWorklistQuickDateButtonStates();
    const map = {
        today: 'quickDateToday',
        yesterday: 'quickDateYesterday',
        last7: 'quickDateLast7'
    };
    const id = map[preset];
    if (!id) {
        return;
    }
    const el = document.getElementById(id);
    if (el) {
        el.classList.add('active');
    }
}

function setWorklistQuickDateRange(preset) {
    const now = new Date();
    let fromStr;
    let toStr;
    if (preset === 'today') {
        fromStr = toStr = toISODateLocal(now);
    } else if (preset === 'yesterday') {
        const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
        fromStr = toStr = toISODateLocal(y);
    } else if (preset === 'last7') {
        const end = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const start = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6);
        fromStr = toISODateLocal(start);
        toStr = toISODateLocal(end);
    } else {
        return;
    }
    $('#filterDateFrom').val(fromStr);
    $('#filterDateTo').val(toStr);
    setWorklistQuickDateButtonActive(preset);
    applyFilters();
}

function setupWorklistQuickDateButtons() {
    const bind = function (id, preset) {
        const btn = document.getElementById(id);
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            setWorklistQuickDateRange(preset);
        });
    };
    bind('quickDateToday', 'today');
    bind('quickDateYesterday', 'yesterday');
    bind('quickDateLast7', 'last7');

    // Marcar como activo el preset que coincida con los inputs actuales (si aplica).
    refreshWorklistQuickDateActiveFromInputs();

    $('#filterDateFrom, #filterDateTo').on('change', function () {
        refreshWorklistQuickDateActiveFromInputs();
    });
}

function refreshWorklistQuickDateActiveFromInputs() {
    const f = $('#filterDateFrom').val();
    const t = $('#filterDateTo').val();
    if (!f || !t) {
        clearWorklistQuickDateButtonStates();
        return;
    }
    const now = new Date();
    const todayStr = toISODateLocal(now);
    const yStr = toISODateLocal(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1));
    const startStr = toISODateLocal(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6));

    if (f === todayStr && t === todayStr) {
        setWorklistQuickDateButtonActive('today');
        return;
    }
    if (f === yStr && t === yStr) {
        setWorklistQuickDateButtonActive('yesterday');
        return;
    }
    if (f === startStr && t === todayStr) {
        setWorklistQuickDateButtonActive('last7');
        return;
    }
    clearWorklistQuickDateButtonStates();
}

function mergeWorklistChangesIntoTable(changes) {
    let added = 0;
    let updated = 0;
    if (!worklistTable) {
        return { added: 0, updated: 0 };
    }

    const indexByAccession = {};
    worklistTable.rows().every(function () {
        const d = this.data();
        if (d && d.accession_number !== undefined && d.accession_number !== null) {
            indexByAccession[String(d.accession_number)] = this.index();
        }
        return true;
    });

    changes.forEach(function (row) {
        if (!row || row.accession_number === undefined || row.accession_number === null) {
            return;
        }
        const acc = String(row.accession_number);
        if (Object.prototype.hasOwnProperty.call(indexByAccession, acc)) {
            const idx = indexByAccession[acc];
            const prev = worklistTable.row(idx).data() || {};
            const merged = Object.assign({}, prev, row);
            worklistTable.row(idx).data(merged);
            updated++;
        } else {
            worklistTable.row.add(row);
            added++;
        }
    });

    if (added > 0 || updated > 0) {
        worklistTable.draw(false);
    }
    return { added: added, updated: updated };
}

/* ============================================================
 * Chips de modalidad (mismo lenguaje visual que pacs-manager.html)
 * Mantenemos #filterModality (hidden) como fuente de verdad para
 * que applyWorklistLocalFilters() y restoreWorklistFilters() sigan
 * funcionando sin cambios.
 * ============================================================ */

const WORKLIST_MODALITY_FALLBACKS = ['MR', 'CT', 'US', 'CR', 'DX', 'MG'];

function getWorklistModalityChipContainer() {
    return document.getElementById('worklistModalityChips');
}

function getWorklistSelectedModality() {
    const el = document.getElementById('filterModality');
    if (!el) {
        return '';
    }
    return (el.value || '').trim();
}

function setWorklistSelectedModalityValue(value) {
    const el = document.getElementById('filterModality');
    if (!el) {
        return;
    }
    el.value = value || '';
}

function renderWorklistModalityChips(byModality) {
    const container = getWorklistModalityChipContainer();
    if (!container) {
        return;
    }

    const counts = byModality && typeof byModality === 'object' ? byModality : {};
    const selected = getWorklistSelectedModality();

    const modalities = Object.keys(counts).filter(function (m) {
        return m && m !== '—' && m !== '-' && m !== '';
    });
    const unknownCount = (counts['—'] || counts['-'] || 0) | 0;

    if (modalities.length === 0) {
        WORKLIST_MODALITY_FALLBACKS.forEach(function (m) {
            modalities.push(m);
        });
    }

    if (selected && selected !== 'all' && modalities.indexOf(selected) === -1) {
        modalities.push(selected);
    }

    modalities.sort(function (a, b) {
        return a.localeCompare(b, undefined, { sensitivity: 'base' });
    });

    let total = 0;
    Object.keys(counts).forEach(function (k) {
        total += (counts[k] || 0) | 0;
    });

    const activeValue = selected || 'all';
    const html = [];

    html.push(
        '<button type="button" class="btn modality-btn' + (activeValue === 'all' ? ' active' : '') + '" data-modality="all">' +
        '<span class="modality-label">Todas</span>' +
        '<sup class="modality-count-sub ms-1 text-body-secondary fw-normal">' + (total > 0 ? total : '') + '</sup>' +
        '</button>'
    );

    modalities.forEach(function (m) {
        const c = (counts[m] || 0) | 0;
        const isActive = activeValue === m;
        html.push(
            '<button type="button" class="btn modality-btn' + (isActive ? ' active' : '') + '" data-modality="' + escapeWorklistAttr(m) + '">' +
            '<span class="modality-label">' + escapeWorklistHtml(m) + '</span>' +
            '<sup class="modality-count-sub ms-1 text-body-secondary fw-normal">' + (c > 0 ? c : '') + '</sup>' +
            '</button>'
        );
    });

    if (unknownCount > 0) {
        const isActive = activeValue === '__none__';
        html.push(
            '<button type="button" class="btn modality-btn' + (isActive ? ' active' : '') + '" data-modality="__none__" title="Turnos sin modalidad asignada">' +
            '<span class="modality-label">Sin modalidad</span>' +
            '<sup class="modality-count-sub ms-1 text-body-secondary fw-normal">' + unknownCount + '</sup>' +
            '</button>'
        );
    }

    container.innerHTML = html.join('');

    const counter = document.getElementById('worklistModalityCounter');
    if (counter) {
        if (activeValue !== 'all') {
            const c = activeValue === '__none__' ? unknownCount : (counts[activeValue] || 0) | 0;
            counter.textContent = c + ' en rango';
            counter.style.display = '';
        } else {
            counter.textContent = '';
            counter.style.display = 'none';
        }
    }
}

function setWorklistModalitySelection(value) {
    const normalized = value === 'all' ? '' : (value || '');
    setWorklistSelectedModalityValue(normalized);

    const container = getWorklistModalityChipContainer();
    if (container) {
        const target = normalized || 'all';
        container.querySelectorAll('.modality-btn').forEach(function (btn) {
            if ((btn.getAttribute('data-modality') || '') === target) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    if (typeof applyWorklistLocalFilters === 'function') {
        applyWorklistLocalFilters();
    }
}

function setupWorklistModalityChips() {
    const container = getWorklistModalityChipContainer();
    if (!container) {
        return;
    }
    container.addEventListener('click', function (e) {
        const btn = e.target.closest('.modality-btn');
        if (!btn || !container.contains(btn)) {
            return;
        }
        e.preventDefault();
        const value = btn.getAttribute('data-modality') || 'all';
        setWorklistModalitySelection(value);
    });

    // Render inicial con valor restaurado (si existe) para que se vea algo antes
    // de la primera respuesta del servidor.
    renderWorklistModalityChips({});
}

function escapeWorklistHtml(s) {
    if (s === null || s === undefined) {
        return '';
    }
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeWorklistAttr(s) {
    return escapeWorklistHtml(s);
}
