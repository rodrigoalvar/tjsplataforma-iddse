/**
 * Gestión de Informes Recibidos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

let informesTable = null;
let currentPage = 1;
let allInformes = []; // Cache para detalles

// Cargar informes al iniciar
document.addEventListener('DOMContentLoaded', function() {
    cargarInformes();
    
    // Event listeners para filtros
    const searchInput = document.getElementById('filterSearch');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                cargarInformes();
            }
        });
    }
});

function cargarInformes(page = 1) {
    currentPage = page;
    
    const estado = document.getElementById('filterEstado').value;
    const accession = document.getElementById('filterAccession').value;
    const search = document.getElementById('filterSearch').value;
    
    const params = new URLSearchParams({
        page: page,
        limit: 50
    });
    
    if (estado) params.append('estado', estado);
    if (accession) params.append('accession_number', accession);
    if (search) params.append('search', search);
    
    // Mostrar loading
    const tbody = document.getElementById('informesTableBody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div></td></tr>';
    
    fetch(`api/informes/recibidos/list.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allInformes = data.data; // Guardar para detalles
                mostrarInformes(data.data, data.pagination);
            } else {
                console.error('Error:', data.error);
                mostrarError('Error al cargar informes: ' + (data.error || 'Error desconocido'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarError('Error al cargar informes');
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Error al cargar informes. Por favor, recarga la página.</td></tr>';
        });
}

function mostrarInformes(informes, pagination) {
    const tbody = document.getElementById('informesTableBody');
    
    if (informes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center">No se encontraron informes recibidos</td></tr>';
        return;
    }
    
    tbody.innerHTML = informes.map(informe => {
        const estadoClass = `status-${informe.estado}`;
        const estadoText = {
            'recibido': 'Recibido',
            'procesado': 'Procesado',
            'vinculado': 'Vinculado',
            'error': 'Error',
            'descartado': 'Descartado'
        }[informe.estado] || informe.estado;
        
        return `
            <tr>
                <td>${informe.id}</td>
                <td><strong>${escapeHtml(informe.accession_number || '')}</strong></td>
                <td>${escapeHtml(informe.patient_name || 'N/A')}</td>
                <td>${escapeHtml(informe.patient_id || 'N/A')}</td>
                <td>${escapeHtml(informe.modality || 'N/A')}</td>
                <td>${informe.fecha_recepcion_formatted || 'N/A'}</td>
                <td><span class="status-badge ${estadoClass}">${estadoText}</span></td>
                <td>
                    ${informe.estudio_id 
                        ? `<a href="estudios-manager.html?study_id=${informe.estudio_id}" class="btn btn-sm btn-link p-0">Ver Estudio #${informe.estudio_id}</a>`
                        : '<span class="text-muted">No vinculado</span>'
                    }
                </td>
                <td>
                    <button class="btn btn-sm btn-info me-1" onclick="verDetalles(${informe.id})" title="Ver detalles">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-primary me-1" onclick="verPDF('${escapeHtml(informe.pdf_path)}')" title="Ver PDF">
                        <i class="fas fa-file-pdf"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="verTXT('${escapeHtml(informe.txt_path)}')" title="Ver TXT">
                        <i class="fas fa-file-lines"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
    
    // Agregar paginación si es necesario
    if (pagination && pagination.pages > 1) {
        // Implementar paginación aquí si se necesita
    }
}

function verDetalles(id) {
    // Buscar en el cache
    const informe = allInformes.find(i => i.id === id);
    
    if (informe) {
        mostrarModalDetalles(informe);
    } else {
        // Si no está en cache, cargar desde la API
        fetch(`api/informes/recibidos/list.php`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const informeEncontrado = data.data.find(i => i.id === id);
                    if (informeEncontrado) {
                        mostrarModalDetalles(informeEncontrado);
                    } else {
                        mostrarError('Informe no encontrado');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarError('Error al cargar detalles');
            });
    }
}

function mostrarModalDetalles(informe) {
    const modalBody = document.getElementById('modalDetallesBody');
    
    const estadoClass = `status-${informe.estado}`;
    const estadoText = {
        'recibido': 'Recibido',
        'procesado': 'Procesado',
        'vinculado': 'Vinculado',
        'error': 'Error',
        'descartado': 'Descartado'
    }[informe.estado] || informe.estado;
    
    modalBody.innerHTML = `
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>ID:</strong> ${informe.id}
            </div>
            <div class="col-md-6">
                <strong>Número de Acceso:</strong> ${escapeHtml(informe.accession_number || 'N/A')}
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Paciente:</strong> ${escapeHtml(informe.patient_name || 'N/A')}
            </div>
            <div class="col-md-6">
                <strong>ID Paciente:</strong> ${escapeHtml(informe.patient_id || 'N/A')}
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Fecha Nacimiento:</strong> ${informe.patient_birth_date || 'N/A'}
            </div>
            <div class="col-md-6">
                <strong>Sexo:</strong> ${informe.patient_sex || 'N/A'}
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Modalidad:</strong> ${escapeHtml(informe.modality || 'N/A')}
            </div>
            <div class="col-md-6">
                <strong>Médico Referente:</strong> ${escapeHtml(informe.referring_physician || 'N/A')}
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Equipo:</strong> ${escapeHtml(informe.equipment_name || 'N/A')}
            </div>
            <div class="col-md-6">
                <strong>Fecha Procedimiento:</strong> ${informe.procedure_date_formatted || 'N/A'}
            </div>
        </div>
        ${informe.procedure_time ? `
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Hora Procedimiento:</strong> ${informe.procedure_time}
            </div>
        </div>
        ` : ''}
        <div class="row mb-3">
            <div class="col-12">
                <strong>Descripción:</strong> ${escapeHtml(informe.procedure_description || 'N/A')}
            </div>
        </div>
        ${informe.reason_for_study ? `
        <div class="row mb-3">
            <div class="col-12">
                <strong>Razón del Estudio:</strong> ${escapeHtml(informe.reason_for_study)}
            </div>
        </div>
        ` : ''}
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Estado:</strong> <span class="status-badge ${estadoClass}">${estadoText}</span>
            </div>
            <div class="col-md-6">
                <strong>Fecha Recepción:</strong> ${informe.fecha_recepcion_formatted || 'N/A'}
            </div>
        </div>
        ${informe.fecha_vinculacion ? `
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Fecha Vinculación:</strong> ${informe.fecha_vinculacion_formatted || 'N/A'}
            </div>
        </div>
        ` : ''}
        ${informe.estudio_id ? `
        <div class="row mb-3">
            <div class="col-12">
                <strong>Estudio Vinculado:</strong> 
                <a href="estudios-manager.html?study_id=${informe.estudio_id}" class="btn btn-sm btn-link">
                    Ver Estudio #${informe.estudio_id}
                </a>
                ${informe.estudio_descripcion ? `<br><small class="text-muted">${escapeHtml(informe.estudio_descripcion)}</small>` : ''}
            </div>
        </div>
        ` : ''}
        ${informe.error_message ? `
        <div class="alert alert-danger">
            <strong>Error:</strong> ${escapeHtml(informe.error_message)}
        </div>
        ` : ''}
        ${informe.motivo_descarte ? `
        <div class="alert alert-warning">
            <strong>Motivo de descarte:</strong> ${escapeHtml(informe.motivo_descarte)}
            ${informe.fecha_descarte_formatted ? `<br><small>Fecha descarte: ${escapeHtml(informe.fecha_descarte_formatted)}</small>` : ''}
        </div>
        ` : ''}
        <div class="row mt-3">
            <div class="col-12">
                <button class="btn btn-primary me-2" onclick="verPDF('${escapeHtml(informe.pdf_path)}')">
                    <i class="fas fa-file-pdf me-1"></i>Ver PDF
                </button>
                <button class="btn btn-outline-secondary" onclick="verTXT('${escapeHtml(informe.txt_path)}')">
                    <i class="fas fa-file-lines me-1"></i>Ver TXT
                </button>
            </div>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('modalDetalles'));
    modal.show();
}

function verPDF(pdfPath) {
    if (!pdfPath) {
        mostrarError('Ruta del PDF no disponible');
        return;
    }

    const frame = document.getElementById('pdfViewerFrame');
    const modalEl = document.getElementById('modalPdfViewer');
    if (!frame || !modalEl || typeof bootstrap === 'undefined') {
        mostrarError('Visor PDF no disponible');
        return;
    }

    const normalizedPath = /^https?:\/\//i.test(pdfPath)
        ? pdfPath
        : (pdfPath.startsWith('/') ? pdfPath : ('/' + pdfPath));

    frame.setAttribute('src', normalizedPath);
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

async function verTXT(txtPath) {
    if (!txtPath) {
        mostrarError('Ruta del TXT no disponible');
        return;
    }

    const contentEl = document.getElementById('txtViewerContent');
    const modalEl = document.getElementById('modalTxtViewer');
    if (!contentEl || !modalEl || typeof bootstrap === 'undefined') {
        mostrarError('Visor TXT no disponible');
        return;
    }

    const normalizedPath = /^https?:\/\//i.test(txtPath)
        ? txtPath
        : (txtPath.startsWith('/') ? txtPath : ('/' + txtPath));

    contentEl.textContent = 'Cargando...';
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    try {
        const res = await fetch(normalizedPath, { credentials: 'include' });
        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }
        contentEl.textContent = await res.text();
    } catch (err) {
        contentEl.textContent = 'No se pudo cargar el TXT: ' + (err.message || 'Error desconocido');
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function mostrarError(mensaje) {
    // Implementar notificación de error
    console.error(mensaje);
    alert(mensaje);
}
