/**
 * JavaScript para gestión de AI Informes
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

let estudiosTable;
let selectedAudioFile = null;
let selectedAudioFileReport = null;
let currentStudyId = null;
let currentTranscriptionId = null;
let testTranscriptionText = null; // Almacenar transcripción de prueba
let testSelectedAudioFile = null;
let testTranscriptionSegments = null; // Almacenar segments con timestamps
let showTimestamps = false; // Estado del toggle de timestamps
let searchTimeout = null; // Timeout para debounce de búsqueda

// Sistema de cola para procesamiento secuencial
let processingQueue = {
    transcription: null,  // ID del audio siendo transcrito
    report: null          // ID del audio generando informe
};

// Constantes para persistencia de preferencias de ordenamiento
const AI_INFORMES_SORT_PREFERENCE_KEY = 'ai_informes_sort';
const AI_INFORMES_SORT_LOCALSTORAGE_KEY = 'ai_informes_sort_preference';

// Mapeo de índices de columna DataTables a identificadores
const COLUMN_MAPPING = {
    0: 'estudio_id',
    1: 'paciente',
    2: 'modalidad',
    3: 'descripcion',
    4: 'fecha'
};

// Mapeo inverso: identificadores a índices de columna
const COLUMN_INDEX_MAPPING = {
    'estudio_id': 0,
    'paciente': 1,
    'modalidad': 2,
    'descripcion': 3,
    'fecha': 4
};

// Inicializar cuando el DOM esté listo
$(document).ready(async function() {
    // Ocultar Modo Prueba por defecto (solo se mostrará si el usuario es ROOT)
    const testButtonsCard = document.getElementById('testButtonsCard');
    if (testButtonsCard) {
        testButtonsCard.style.display = 'none';
    }
    
    // Verificar si el usuario es ROOT para mostrar Modo Prueba
    async function checkAndShowTestMode() {
        try {
            // Obtener información del usuario directamente desde la API
            const response = await fetch('api/auth/validate-session-simple.php');
            const result = await response.json();
            
            if (result.success && result.user) {
                const isRoot = result.user.nivel === 'root';
                
                if (testButtonsCard) {
                    testButtonsCard.style.display = isRoot ? 'block' : 'none';
                }
                
                console.log('Modo Prueba:', isRoot ? 'VISIBLE (Usuario ROOT)' : 'OCULTO (Usuario no ROOT)');
            } else {
                // Si no hay sesión válida, ocultar
                if (testButtonsCard) {
                    testButtonsCard.style.display = 'none';
                }
            }
        } catch (error) {
            console.warn('Error verificando nivel de usuario:', error);
            // Ocultar por defecto si hay error
            if (testButtonsCard) {
                testButtonsCard.style.display = 'none';
            }
        }
    }
    
    // Cargar permisos del usuario al inicio
    if (window.simplePermissionManager) {
        try {
            // Verificar permisos para asegurar que se carguen
            await window.simplePermissionManager.checkPermission('transcribir_ai');
            await window.simplePermissionManager.checkPermission('generar_informe_ai');
        } catch (error) {
            console.warn('Error cargando permisos:', error);
        }
    }
    
    // Verificar y mostrar/ocultar Modo Prueba
    await checkAndShowTestMode();
    
    initializeTable();
    setupAudioUpload();
    setupAudioUploadReport();
    setupTestAudioUpload();
    loadTemplates();
    
    // Iniciar polling de estado de cola
    startQueueStatusPolling();
});

/**
 * Cargar preferencia de ordenamiento desde BD o localStorage
 */
async function loadSortPreference() {
    try {
        const token = getAuthToken();
        if (token) {
            try {
                const response = await fetch(`api/users/get-user-preference.php?preference_key=${AI_INFORMES_SORT_PREFERENCE_KEY}`, {
                    method: 'GET',
                    headers: {
                        'Authorization': `Bearer ${token}`
                    },
                    credentials: 'include'
                });
                
                const result = await response.json();
                if (result.success && result.data) {
                    const preference = result.data;
                    if (preference.column && preference.direction) {
                        console.log(`✅ Preferencia de ordenamiento cargada desde BD: ${preference.column} (${preference.direction})`);
                        
                        // Sincronizar localStorage con la BD
                        localStorage.setItem(AI_INFORMES_SORT_LOCALSTORAGE_KEY, JSON.stringify(preference));
                        
                        // Retornar formato para DataTables: [[index, direction]]
                        const columnIndex = COLUMN_INDEX_MAPPING[preference.column];
                        if (columnIndex !== undefined) {
                            return [[columnIndex, preference.direction]];
                        }
                    }
                }
            } catch (dbError) {
                console.warn('⚠️ Error cargando desde BD, intentando localStorage:', dbError);
            }
        }
        
        // Si no se pudo cargar desde BD, intentar desde localStorage (respaldo)
        const saved = localStorage.getItem(AI_INFORMES_SORT_LOCALSTORAGE_KEY);
        if (saved) {
            try {
                const preference = JSON.parse(saved);
                if (preference && preference.column && preference.direction) {
                    console.log(`📋 Preferencia encontrada en localStorage (respaldo): ${preference.column} (${preference.direction})`);
                    
                    const columnIndex = COLUMN_INDEX_MAPPING[preference.column];
                    if (columnIndex !== undefined) {
                        return [[columnIndex, preference.direction]];
                    }
                }
            } catch (error) {
                console.warn('Error parseando preferencia de localStorage:', error);
            }
        }
        
        // Si no hay preferencia, retornar null para usar orden por defecto
        return null;
    } catch (error) {
        console.error('❌ Error cargando preferencia de ordenamiento:', error);
        return null;
    }
}

/**
 * Guardar preferencia de ordenamiento en BD y localStorage
 */
async function saveSortPreference(columnIndex, direction) {
    try {
        const columnKey = COLUMN_MAPPING[columnIndex];
        if (!columnKey) {
            console.warn('⚠️ Columna no ordenable:', columnIndex);
            return;
        }
        
        const preference = {
            column: columnKey,
            direction: direction
        };
        
        // Guardar en localStorage como respaldo
        localStorage.setItem(AI_INFORMES_SORT_LOCALSTORAGE_KEY, JSON.stringify(preference));
        console.log(`💾 Preferencia de ordenamiento guardada en localStorage: ${columnKey} (${direction})`);
        
        // Guardar en la base de datos (persistente entre sesiones)
        try {
            const token = getAuthToken();
            if (token) {
                const response = await fetch('api/users/save-user-preference.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        preference_key: AI_INFORMES_SORT_PREFERENCE_KEY,
                        preference_value: preference
                    })
                });
                
                const result = await response.json();
                if (result.success) {
                    console.log(`✅ Preferencia de ordenamiento guardada en BD: ${columnKey} (${direction})`);
                } else {
                    console.warn('⚠️ No se pudo guardar en BD, pero se guardó en localStorage:', result.message);
                }
            } else {
                console.warn('⚠️ No hay token de sesión, solo se guardó en localStorage');
            }
        } catch (dbError) {
            console.warn('⚠️ Error guardando en BD (se guardó en localStorage como respaldo):', dbError);
        }
    } catch (error) {
        console.error('❌ Error guardando preferencia de ordenamiento:', error);
    }
}

/**
 * Inicializar DataTable de estudios con audios
 */
async function initializeTable() {
    // Cargar preferencia de ordenamiento antes de inicializar
    const savedOrder = await loadSortPreference();
    const defaultOrder = [[5, 'desc']]; // Orden por defecto: Fecha Estudio descendente (columna 5 después de agregar ID Paciente)
    const initialOrder = savedOrder || defaultOrder;
    
    estudiosTable = $('#estudiosTable').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
            emptyTable: 'No hay audios disponibles en el sistema.',
            zeroRecords: 'No se encontraron estudios que coincidan con el filtro.'
        },
        processing: true,
        serverSide: false,
        ajax: {
            url: 'api/ai-informes.php?action=list-audios',
            type: 'GET',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            },
            dataSrc: function(json) {
                console.log('Datos recibidos de API (audios):', json);
                if (json.success) {
                    const data = json.data || [];
                    console.log('Número de estudios con audios:', data.length);
                    // Transformar datos para DataTable
                    return data.map(item => {
                        const estudio = item.estudio;
                        const audios = item.audios || [];
                        const transcriptionCount = audios.filter(a => a.transcription && a.transcription.status === 'completed').length;
                        const reportCount = audios.filter(a => a.report && a.report.status === 'completed').length;
                        
                        return {
                            estudio_id: estudio.id,
                            estudio_orthanc_id: estudio.orthanc_study_id,
                            paciente: estudio.patient_name_pacs || 'N/A',
                            patient_id_pacs: estudio.patient_id_pacs || 'N/A',
                            modalidad: estudio.modality || 'N/A',
                            descripcion: estudio.study_description || 'N/A',
                            fecha_estudio: estudio.study_date || null,
                            audios: audios,
                            audio_count: audios.length,
                            transcription_count: transcriptionCount,
                            report_count: reportCount
                        };
                    });
                }
                if (json.message) {
                    console.warn('Advertencia al cargar audios:', json.message);
                }
                return [];
            },
            error: function(xhr, error, thrown) {
                console.error('Error Ajax cargando audios:', error, thrown);
                showAlert('warning', 'No se pudieron cargar los audios. Verifica la conexión.');
            }
        },
        columns: [
            { 
                data: 'estudio_id',
                title: 'ID'
            },
            { 
                data: 'paciente',
                title: 'Paciente'
            },
            { 
                data: 'patient_id_pacs',
                title: 'ID Paciente',
                render: function(data) {
                    return data && data !== 'N/A' ? `<span class="badge bg-primary">${data}</span>` : 'N/A';
                }
            },
            { 
                data: 'modalidad',
                title: 'Modalidad',
                render: function(data) {
                    return data && data !== 'N/A' ? `<span class="badge bg-info">${data}</span>` : 'N/A';
                }
            },
            { 
                data: 'descripcion',
                title: 'Descripción'
            },
            { 
                data: 'fecha_estudio',
                title: 'Fecha Estudio',
                render: function(data) {
                    return data ? new Date(data).toLocaleDateString('es-ES') : 'N/A';
                }
            },
            {
                data: 'audio_count',
                title: 'Audios',
                className: 'text-center',
                orderable: false,
                render: function(data, type, row) {
                    if (type === 'display') {
                        return `<span class="badge bg-secondary">${data || 0}</span>`;
                    }
                    return data || 0;
                }
            },
            {
                data: 'transcription_count',
                title: 'Transcripciones',
                className: 'text-center',
                render: function(data, type, row) {
                    if (type === 'display') {
                        const total = row.audio_count || 0;
                        const completed = data || 0;
                        const badgeClass = completed === total && total > 0 ? 'bg-success' : completed > 0 ? 'bg-warning' : 'bg-secondary';
                        return `<span class="badge ${badgeClass}">${completed}/${total}</span>`;
                    }
                    return data || 0;
                }
            },
            {
                data: 'report_count',
                title: 'Informes AI',
                className: 'text-center',
                render: function(data, type, row) {
                    if (type === 'display') {
                        const total = row.audio_count || 0;
                        const completed = data || 0;
                        const badgeClass = completed === total && total > 0 ? 'bg-success' : completed > 0 ? 'bg-warning' : 'bg-secondary';
                        return `<span class="badge ${badgeClass}">${completed}/${total}</span>`;
                    }
                    return data || 0;
                }
            },
            {
                data: null,
                title: 'Acciones',
                orderable: false,
                className: 'text-center',
                render: function(data, type, row) {
                    if (type === 'display' && row.audios && row.audios.length > 0) {
                        // Pasar estudio_id y orthanc_study_id para el modal
                        const estudioId = row.estudio_id || 'null';
                        const orthancId = row.estudio_orthanc_id ? `'${row.estudio_orthanc_id.replace(/'/g, "\\'")}'` : 'null';
                        const paciente = row.paciente ? `'${row.paciente.replace(/'/g, "\\'")}'` : 'null';
                        return `<button class="btn btn-sm btn-primary" onclick="window.showAudiosModal && window.showAudiosModal(${estudioId}, ${orthancId}, ${paciente})" title="Ver Audios">
                            <i class="fas fa-list"></i> <span class="d-none d-md-inline">Ver Audios</span>
                        </button>`;
                    }
                    return '<span class="text-muted">Sin audios</span>';
                }
            }
        ],
        order: initialOrder, // Usar preferencia guardada o orden por defecto
        pageLength: 25,
        responsive: false,
        // Deshabilitar el buscador por defecto de DataTables (usamos nuestro buscador personalizado)
        dom: 'lrtip' // l = length changing, r = processing, t = table, i = information, p = pagination (sin 'f' que es el filtro/buscador)
    });
    
    // Listener para cambios de ordenamiento
    estudiosTable.on('order.dt', function() {
        const order = estudiosTable.order();
        if (order && order.length > 0 && order[0].length >= 2) {
            const columnIndex = order[0][0];
            const direction = order[0][1]; // 'asc' o 'desc'
            saveSortPreference(columnIndex, direction);
        }
    });
    
    // Log para debug
    estudiosTable.on('draw', function() {
        const rowCount = estudiosTable.rows().count();
        console.log('Tabla redibujada. Filas visibles:', rowCount);
    });
    
    // Configurar buscador después de inicializar la tabla
    setupSearchFilter();
}

/**
 * Configurar buscador/filtro de búsqueda en tiempo real
 */
function setupSearchFilter() {
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    
    if (!searchInput) {
        // Si no existe el input, intentar de nuevo después de un breve delay
        setTimeout(setupSearchFilter, 100);
        return;
    }
    
    if (!estudiosTable) {
        console.warn('⚠️ Tabla no inicializada aún, reintentando...');
        setTimeout(setupSearchFilter, 200);
        return;
    }
    
    // Función para aplicar búsqueda con debounce
    function performSearch(searchValue) {
        if (estudiosTable) {
            estudiosTable.search(searchValue).draw();
            
            // Mostrar/ocultar botón de limpiar
            if (clearSearchBtn) {
                clearSearchBtn.style.display = searchValue.trim() ? 'block' : 'none';
            }
        }
    }
    
    // Listener para búsqueda en tiempo real con debounce
    searchInput.addEventListener('input', function() {
        const searchValue = this.value;
        
        // Limpiar timeout anterior
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // Si el campo está vacío, limpiar búsqueda inmediatamente
        if (!searchValue.trim()) {
            performSearch('');
            return;
        }
        
        // Aplicar búsqueda con debounce (300ms)
        searchTimeout = setTimeout(() => {
            performSearch(searchValue);
        }, 300);
    });
    
    // Listener para Enter (búsqueda inmediata)
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            performSearch(this.value);
        }
    });
    
    // Botón para limpiar búsqueda
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            performSearch('');
            searchInput.focus();
        });
    }
}

/**
 * Configurar área de carga de audio
 */
function setupAudioUpload() {
    const uploadArea = document.getElementById('audioUploadArea');
    const fileInput = document.getElementById('audioFileInput');
    
    if (!uploadArea || !fileInput) return;
    
    // Drag and drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleAudioFileSelect({ target: fileInput });
        }
    });
}

/**
 * Manejar selección de archivo de audio
 */
function handleAudioFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    selectedAudioFile = file;
    const fileInfo = document.getElementById('audioFileInfo');
    const fileName = document.getElementById('audioFileName');
    const transcribeBtn = document.getElementById('transcribeBtn');
    
    if (fileInfo && fileName) {
        fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
        fileInfo.style.display = 'block';
    }
    
    if (transcribeBtn) {
        transcribeBtn.disabled = false;
    }
}

/**
 * Abrir modal de transcripción
 */
// Asegurar que las funciones estén en el scope global
window.openTranscribeModal = function(studyId) {
    console.log('Abriendo modal de transcripción para estudio:', studyId);
    currentStudyId = studyId;
    document.getElementById('transcribeStudyId').value = studyId;
    
    // Resetear formulario
    document.getElementById('audioFileInput').value = '';
    document.getElementById('audioFileInfo').style.display = 'none';
    document.getElementById('transcribeBtn').disabled = true;
    selectedAudioFile = null;
    
    const modal = new bootstrap.Modal(document.getElementById('transcribeModal'));
    modal.show();
}

/**
 * Transcribir audio
 */
async function transcribeAudio() {
    if (!selectedAudioFile || !currentStudyId) {
        showAlert('error', 'Por favor selecciona un archivo de audio');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', selectedAudioFile);
    formData.append('study_id', currentStudyId);
    formData.append('action', 'transcribe');
    
    const transcribeBtn = document.getElementById('transcribeBtn');
    if (transcribeBtn) {
        transcribeBtn.disabled = true;
        transcribeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Transcribiendo...';
    }
    
    try {
        const response = await fetch('api/ai-informes.php?action=transcribe', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('success', 'Transcripción completada exitosamente');
            
            // Cerrar modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('transcribeModal'));
            modal.hide();
            
            // Recargar tabla
            estudiosTable.ajax.reload();
        } else {
            throw new Error(result.message || 'Error al transcribir');
        }
    } catch (error) {
        console.error('Error transcribiendo:', error);
        showAlert('error', 'Error al transcribir: ' + error.message);
    } finally {
        if (transcribeBtn) {
            transcribeBtn.disabled = false;
            transcribeBtn.innerHTML = 'Transcribir';
        }
    }
}

/**
 * Manejar selección de audio en modal de informe
 */
function handleAudioFileSelectReport(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    selectedAudioFileReport = file;
    const fileInfo = document.getElementById('audioFileInfoReport');
    const fileName = document.getElementById('audioFileNameReport');
    const transcribeBtn = document.getElementById('transcribeNewAudioBtn');
    
    if (fileInfo && fileName) {
        fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
        fileInfo.style.display = 'block';
    }
    
    if (transcribeBtn) {
        transcribeBtn.disabled = false;
    }
    
    // Limpiar selección de transcripción existente
    const transcriptionSelect = document.getElementById('transcriptionSelect');
    if (transcriptionSelect) {
        transcriptionSelect.value = '';
    }
    currentTranscriptionId = null;
    updateGenerateButtonState();
}

/**
 * Transcribir audio y continuar con el flujo
 */
async function transcribeAndContinue() {
    if (!selectedAudioFileReport || !currentStudyId) {
        showAlert('error', 'Por favor selecciona un archivo de audio');
        return;
    }
    
    const transcribeBtn = document.getElementById('transcribeNewAudioBtn');
    if (transcribeBtn) {
        transcribeBtn.disabled = true;
        transcribeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Transcribiendo...';
    }
    
    try {
        const formData = new FormData();
        formData.append('file', selectedAudioFileReport);
        formData.append('study_id', currentStudyId);
        formData.append('action', 'transcribe');
        
        const response = await fetch('api/ai-informes.php?action=transcribe', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Recargar transcripciones
            await loadTranscriptions(currentStudyId);
            
            // Seleccionar la transcripción recién creada
            if (result.data && result.data.transcription_id) {
                const transcriptionSelect = document.getElementById('transcriptionSelect');
                if (transcriptionSelect) {
                    transcriptionSelect.value = result.data.transcription_id;
                    currentTranscriptionId = result.data.transcription_id;
                    showTranscriptionPreview(result.data.text);
                }
            }
            
            showAlert('success', 'Transcripción completada exitosamente');
            updateGenerateButtonState();
        } else {
            throw new Error(result.message || 'Error al transcribir');
        }
    } catch (error) {
        console.error('Error transcribiendo:', error);
        showAlert('error', 'Error al transcribir: ' + error.message);
    } finally {
        if (transcribeBtn) {
            transcribeBtn.disabled = false;
            transcribeBtn.innerHTML = '<i class="fas fa-microphone"></i> Transcribir';
        }
    }
}

/**
 * Abrir modal de generación de informe
 */
// Asegurar que las funciones estén en el scope global
window.openGenerateReportModal = async function(studyId) {
    console.log('Abriendo modal de generar informe para estudio:', studyId);
    currentStudyId = studyId;
    document.getElementById('generateReportStudyId').value = studyId;
    currentTranscriptionId = null;
    selectedAudioFileReport = null;
    
    // Resetear formulario
    document.getElementById('audioFileInputReport').value = '';
    document.getElementById('audioFileInfoReport').style.display = 'none';
    document.getElementById('transcriptionSelect').value = '';
    document.getElementById('transcriptionPreview').style.display = 'none';
    document.getElementById('templateSelect').value = '';
    document.getElementById('reportPreviewSection').style.display = 'none';
    const generateBtn = document.getElementById('generateReportBtn');
    if (generateBtn) {
        generateBtn.disabled = true;
        generateBtn.innerHTML = '<i class="fas fa-robot"></i> Generar Informe';
        generateBtn.onclick = generateReport;
    }
    
    // Cargar transcripciones del estudio
    await loadTranscriptions(studyId);
    
    // Las plantillas ya están cargadas
    const modal = new bootstrap.Modal(document.getElementById('generateReportModal'));
    modal.show();
    
    // Agregar listener para cambio de transcripción
    const transcriptionSelect = document.getElementById('transcriptionSelect');
    if (transcriptionSelect) {
        // Remover listener anterior si existe
        const newSelect = transcriptionSelect.cloneNode(true);
        transcriptionSelect.parentNode.replaceChild(newSelect, transcriptionSelect);
        
        newSelect.addEventListener('change', function() {
            currentTranscriptionId = this.value;
            if (this.value && this.options[this.selectedIndex].dataset.text) {
                showTranscriptionPreview(this.options[this.selectedIndex].dataset.text);
            } else {
                document.getElementById('transcriptionPreview').style.display = 'none';
            }
            updateGenerateButtonState();
        });
    }
    
    // Configurar drag and drop para el área de audio
    setupAudioUploadReport();
}

/**
 * Configurar área de carga de audio en modal de informe
 */
function setupAudioUploadReport() {
    const uploadArea = document.getElementById('audioUploadAreaReport');
    const fileInput = document.getElementById('audioFileInputReport');
    
    if (!uploadArea || !fileInput) return;
    
    // Drag and drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleAudioFileSelectReport({ target: fileInput });
        }
    });
}

/**
 * Mostrar vista previa de transcripción
 */
function showTranscriptionPreview(text) {
    const previewDiv = document.getElementById('transcriptionPreview');
    const previewText = document.getElementById('transcriptionTextPreview');
    
    if (previewDiv && previewText) {
        previewText.textContent = text || 'Sin transcripción';
        previewDiv.style.display = 'block';
    }
}

/**
 * Actualizar estado del botón de generar
 */
function updateGenerateButtonState() {
    const transcriptionSelect = document.getElementById('transcriptionSelect');
    const generateBtn = document.getElementById('generateReportBtn');
    
    if (generateBtn) {
        const hasTranscription = (transcriptionSelect && transcriptionSelect.value) || currentTranscriptionId;
        generateBtn.disabled = !hasTranscription;
    }
}

/**
 * Cargar transcripciones del estudio
 */
async function loadTranscriptions(studyId) {
    try {
        const response = await fetch(`api/ai-informes.php?study_id=${studyId}`, {
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            }
        });
        
        const result = await response.json();
        const select = document.getElementById('transcriptionSelect');
        
        if (select) {
            select.innerHTML = '<option value="">Seleccionar transcripción...</option>';
            
            if (result.success && result.data.transcriptions) {
                result.data.transcriptions.forEach(transcription => {
                    if (transcription.status === 'completed' && transcription.transcription_text) {
                        const option = document.createElement('option');
                        option.value = transcription.id;
                        const date = transcription.created_at ? new Date(transcription.created_at).toLocaleDateString('es-ES') : '';
                        const preview = transcription.transcription_text.substring(0, 80) + '...';
                        option.textContent = `Transcripción #${transcription.id} - ${date} - ${preview}`;
                        option.dataset.text = transcription.transcription_text;
                        select.appendChild(option);
                    }
                });
            }
        }
    } catch (error) {
        console.error('Error cargando transcripciones:', error);
    }
}

/**
 * Cargar plantillas
 */
async function loadTemplates() {
    try {
        const response = await fetch('api/plantillas/list.php', {
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            }
        });
        
        const result = await response.json();
        const select = document.getElementById('templateSelect');
        
        if (select && result.success && result.data) {
            // Verificar que result.data sea un array
            const templates = Array.isArray(result.data) ? result.data : [];
            
            templates.forEach(template => {
                const option = document.createElement('option');
                option.value = template.id || template.template_id;
                option.textContent = template.nombre || `Plantilla #${template.id || template.template_id}`;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error cargando plantillas:', error);
    }
}

/**
 * Cargar plantillas para el select de prueba
 */
async function loadTemplatesForTest() {
    try {
        const response = await fetch('api/plantillas/list.php', {
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            }
        });
        
        const result = await response.json();
        const select = document.getElementById('testReportTemplateSelect');
        
        if (select) {
            select.innerHTML = '<option value="">Sin plantilla</option>';
            
            if (result.success && result.data) {
                // Verificar que result.data sea un array
                const templates = Array.isArray(result.data) ? result.data : [];
                
                templates.forEach(template => {
                    const option = document.createElement('option');
                    option.value = template.id || template.template_id;
                    option.textContent = template.nombre || `Plantilla #${template.id || template.template_id}`;
                    select.appendChild(option);
                });
            }
        }
    } catch (error) {
        console.error('Error cargando plantillas para prueba:', error);
    }
}

/**
 * Generar informe
 */
async function generateReport() {
    const studyId = document.getElementById('generateReportStudyId').value;
    const transcriptionSelect = document.getElementById('transcriptionSelect');
    const transcriptionId = transcriptionSelect ? transcriptionSelect.value : currentTranscriptionId;
    const templateId = document.getElementById('templateSelect').value;
    
    if (!studyId) {
        showAlert('error', 'ID de estudio requerido');
        return;
    }
    
    if (!transcriptionId) {
        showAlert('error', 'Debes seleccionar una transcripción o transcribir un audio primero');
        return;
    }
    
    const generateBtn = document.getElementById('generateReportBtn');
    if (generateBtn) {
        generateBtn.disabled = true;
        generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando...';
    }
    
    try {
        const response = await fetch('api/ai-informes.php?action=generate-report', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: JSON.stringify({
                study_id: studyId,
                transcription_id: transcriptionId,
                template_id: templateId || null
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Mostrar vista previa del informe generado
            const previewSection = document.getElementById('reportPreviewSection');
            const previewContent = document.getElementById('reportPreviewContent');
            
            if (previewSection && previewContent) {
                previewContent.textContent = result.data.content;
                previewSection.style.display = 'block';
                
                // Scroll a la vista previa
                previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            
            showAlert('success', 'Informe generado exitosamente. Revisa la vista previa.');
            
            // Cambiar botón a "Aceptar"
            if (generateBtn) {
                generateBtn.innerHTML = '<i class="fas fa-check"></i> Aceptar';
                generateBtn.onclick = function() {
                    // Cerrar modal y recargar tabla
                    const modal = bootstrap.Modal.getInstance(document.getElementById('generateReportModal'));
                    modal.hide();
                    estudiosTable.ajax.reload();
                };
            }
        } else {
            throw new Error(result.message || 'Error al generar informe');
        }
    } catch (error) {
        console.error('Error generando informe:', error);
        showAlert('error', 'Error al generar informe: ' + error.message);
        if (generateBtn) {
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-robot"></i> Generar Informe';
        }
    }
}

/**
 * Ver transcripciones
 */
// Asegurar que las funciones estén en el scope global
window.viewTranscriptions = function(studyId) {
    // TODO: Implementar vista de transcripciones
    showAlert('info', 'Funcionalidad en desarrollo');
}

/**
 * Ver informes
 */
// Asegurar que las funciones estén en el scope global
window.viewReports = function(studyId) {
    // TODO: Implementar vista de informes
    showAlert('info', 'Funcionalidad en desarrollo');
}

/**
 * Formatear tamaño de archivo
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
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
    
    container.innerHTML = '';
    container.appendChild(alert);
    
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 5000);
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

// ============================================
// FUNCIONES DE PRUEBA (SIN GUARDAR EN BD)
// ============================================

/**
 * Configurar drag and drop para el área de audio de prueba
 */
// Variables para almacenar los handlers y evitar duplicados
let testAudioUploadHandlers = {
    click: null,
    dragover: null,
    dragleave: null,
    drop: null
};

function setupTestAudioUpload() {
    const uploadArea = document.getElementById('testAudioUploadArea');
    const fileInput = document.getElementById('testAudioFileInput');
    
    if (!uploadArea || !fileInput) {
        return;
    }
    
    // Remover listeners anteriores si existen
    if (testAudioUploadHandlers.click) {
        uploadArea.removeEventListener('click', testAudioUploadHandlers.click);
    }
    if (testAudioUploadHandlers.dragover) {
        uploadArea.removeEventListener('dragover', testAudioUploadHandlers.dragover);
    }
    if (testAudioUploadHandlers.dragleave) {
        uploadArea.removeEventListener('dragleave', testAudioUploadHandlers.dragleave);
    }
    if (testAudioUploadHandlers.drop) {
        uploadArea.removeEventListener('drop', testAudioUploadHandlers.drop);
    }
    
    // Crear nuevos handlers
    testAudioUploadHandlers.click = (e) => {
        // Solo hacer click si no hay archivo siendo arrastrado
        if (!e.dataTransfer || e.dataTransfer.files.length === 0) {
            fileInput.click();
        }
    };
    
    testAudioUploadHandlers.dragover = (e) => {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('dragover');
    };
    
    testAudioUploadHandlers.dragleave = (e) => {
        e.preventDefault();
        e.stopPropagation();
        // Solo remover dragover si realmente salimos del área
        if (!uploadArea.contains(e.relatedTarget)) {
            uploadArea.classList.remove('dragover');
        }
    };
    
    testAudioUploadHandlers.drop = (e) => {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            // Validar que sea un archivo de audio
            const file = files[0];
            if (file.type.startsWith('audio/') || file.name.match(/\.(mp3|wav|m4a|ogg|webm)$/i)) {
                // Asignar archivo al input
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                fileInput.files = dataTransfer.files;
                
                // Disparar evento change manualmente
                const changeEvent = new Event('change', { bubbles: true });
                fileInput.dispatchEvent(changeEvent);
            } else {
                showAlert('error', 'Por favor, selecciona un archivo de audio válido (MP3, WAV, M4A, OGG)');
            }
        }
    };
    
    // Agregar los nuevos listeners
    uploadArea.addEventListener('click', testAudioUploadHandlers.click);
    uploadArea.addEventListener('dragover', testAudioUploadHandlers.dragover);
    uploadArea.addEventListener('dragleave', testAudioUploadHandlers.dragleave);
    uploadArea.addEventListener('drop', testAudioUploadHandlers.drop);
}

/**
 * Abrir modal de prueba de transcripción
 */
window.openTestTranscribeModal = async function() {
    // Verificar que el usuario sea ROOT
    try {
        const response = await fetch('api/auth/validate-session-simple.php');
        const result = await response.json();
        
        if (!result.success || !result.user || result.user.nivel !== 'root') {
            showAlert('error', 'Solo los usuarios ROOT pueden acceder al Modo Prueba.');
            return;
        }
    } catch (error) {
        console.error('Error verificando usuario:', error);
        showAlert('error', 'No se pudo verificar los permisos. Solo los usuarios ROOT pueden acceder al Modo Prueba.');
        return;
    }
    
    testSelectedAudioFile = null;
    testTranscriptionText = null;
    
    // Resetear formulario
    document.getElementById('testAudioFileInput').value = '';
    document.getElementById('testAudioFileInfo').style.display = 'none';
    document.getElementById('testTranscriptionResult').style.display = 'none';
    document.getElementById('testTranscribeBtn').disabled = true;
    
    const modal = new bootstrap.Modal(document.getElementById('testTranscribeModal'));
    modal.show();
    
    // No es necesario llamar setupTestAudioUpload() de nuevo ya que se llama al inicio
    // Los listeners ya están configurados y no se duplican gracias a la lógica de remoción
};

/**
 * Manejar selección de archivo de audio en modo prueba
 */
function handleTestAudioFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    // Validar tamaño del archivo (máximo 500 MB para transcripciones largas)
    const maxSizeMB = 500;
    const maxSizeBytes = maxSizeMB * 1024 * 1024;
    
    if (file.size > maxSizeBytes) {
        showAlert('error', `El archivo es demasiado grande (${formatFileSize(file.size)}). Tamaño máximo: ${maxSizeMB} MB`);
        event.target.value = '';
        testSelectedAudioFile = null;
        const fileInfo = document.getElementById('testAudioFileInfo');
        const transcribeBtn = document.getElementById('testTranscribeBtn');
        if (fileInfo) fileInfo.style.display = 'none';
        if (transcribeBtn) transcribeBtn.disabled = true;
        return;
    }
    
    testSelectedAudioFile = file;
    const fileInfo = document.getElementById('testAudioFileInfo');
    const fileName = document.getElementById('testAudioFileName');
    const transcribeBtn = document.getElementById('testTranscribeBtn');
    
    if (fileInfo && fileName) {
        fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
        fileInfo.style.display = 'block';
    }
    
    if (transcribeBtn) {
        transcribeBtn.disabled = false;
    }
}

/**
 * Transcribir audio en modo prueba (sin guardar en BD)
 */
async function testTranscribeAudio() {
    if (!testSelectedAudioFile) {
        showAlert('error', 'Por favor selecciona un archivo de audio');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', testSelectedAudioFile);
    formData.append('action', 'test-transcribe');
    
    const transcribeBtn = document.getElementById('testTranscribeBtn');
    if (transcribeBtn) {
        transcribeBtn.disabled = true;
        transcribeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Transcribiendo...';
    }
    
    try {
        const response = await fetch('api/ai-informes.php?action=test-transcribe', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: formData
        });
        
        // Verificar content-type
        const contentType = response.headers.get('content-type') || '';
        let result;
        
        if (!contentType.includes('application/json')) {
            const text = await response.text();
            if (response.status === 413) {
                throw new Error(`El archivo es demasiado grande (413 Content Too Large). ` +
                    `El servidor web (Nginx/Apache) tiene un límite de tamaño. ` +
                    `Contacta al administrador para aumentar el límite o usa un archivo más pequeño (máximo recomendado: 500MB).`);
            } else if (response.status === 500) {
                // Intentar parsear como JSON aunque no tenga el content-type correcto
                try {
                    result = JSON.parse(text);
                    throw new Error(result.message || result.error || 'Error interno del servidor');
                } catch (parseError) {
                    throw new Error(`Error del servidor (500): ${text.substring(0, 200)}`);
                }
            } else {
                throw new Error(`Error del servidor (${response.status}): ${text.substring(0, 200)}`);
            }
        }
        
        // Intentar parsear JSON
        try {
            const text = await response.text();
            if (!text || text.trim() === '') {
                throw new Error('Respuesta vacía del servidor');
            }
            result = JSON.parse(text);
        } catch (parseError) {
            console.error('Error parseando JSON:', parseError);
            throw new Error('Error al procesar la respuesta del servidor. Verifica los logs del servidor.');
        }
        
        if (!response.ok) {
            throw new Error(result.message || `Error HTTP ${response.status}`);
        }
        
        if (result.success) {
            // Debug: ver toda la respuesta completa
            console.log('Respuesta completa de la API:', result);
            console.log('result.data completo:', result.data);
            console.log('Claves en result.data:', Object.keys(result.data || {}));
            
            // Guardar transcripción en memoria y localStorage
            testTranscriptionText = result.data.text || '';
            testTranscriptionSegments = result.data.segments || null;
            
            // Debug: verificar si hay segments
            console.log('Segments recibidos:', testTranscriptionSegments);
            console.log('¿Hay segments?', testTranscriptionSegments && Array.isArray(testTranscriptionSegments) && testTranscriptionSegments.length > 0);
            
            localStorage.setItem('test_transcription', JSON.stringify({
                text: testTranscriptionText,
                segments: testTranscriptionSegments,
                timestamp: new Date().toISOString(),
                processing_time: result.data.processing_time,
                stats: result.data.stats || {}
            }));
            
            // Mostrar resultado
            const resultDiv = document.getElementById('testTranscriptionResult');
            const textDiv = document.getElementById('testTranscriptionText');
            const statsDiv = document.getElementById('testTranscriptionStats');
            
            if (resultDiv && textDiv) {
                // Mostrar transcripción sin timestamps por defecto
                updateTranscriptionDisplay();
                resultDiv.style.display = 'block';
                
                // Mostrar/ocultar botón de timestamps según disponibilidad
                const toggleBtn = document.getElementById('toggleTimestampsBtn');
                if (toggleBtn) {
                    const hasSegments = testTranscriptionSegments && Array.isArray(testTranscriptionSegments) && testTranscriptionSegments.length > 0;
                    console.log('Segments disponibles:', testTranscriptionSegments);
                    console.log('¿Mostrar botón toggle?', hasSegments);
                    if (hasSegments) {
                        toggleBtn.style.display = 'block';
                        toggleBtn.style.visibility = 'visible';
                    } else {
                        // Ocultar el botón si no hay segments
                        toggleBtn.style.display = 'none';
                        // Mostrar un mensaje informativo si no hay timestamps disponibles
                        console.warn('No hay timestamps disponibles. El servidor whisper-server puede necesitar estar configurado con -ml (max-len) para generar segments.');
                    }
                } else {
                    console.error('Botón toggleTimestampsBtn no encontrado en el DOM');
                }
            }
            
            // Mostrar estadísticas si están disponibles
            if (statsDiv && result.data) {
                let statsHtml = '<div class="mt-3"><small class="text-muted"><strong>Estadísticas:</strong></small><ul class="list-unstyled mb-0">';
                
                if (result.data.audio_duration_formatted) {
                    statsHtml += `<li><small class="text-muted">Duración del audio: <strong>${result.data.audio_duration_formatted}</strong></small></li>`;
                }
                
                if (result.data.processing_time_formatted) {
                    statsHtml += `<li><small class="text-muted">Tiempo de transcripción: <strong>${result.data.processing_time_formatted}</strong></small></li>`;
                }
                
                // Timings adicionales si están disponibles
                if (result.data.stats && result.data.stats.timings) {
                    const timings = result.data.stats.timings;
                    if (timings.load_time) {
                        statsHtml += `<li><small class="text-muted">Tiempo de carga: <strong>${timings.load_time.toFixed(2)}s</strong></small></li>`;
                    }
                    if (timings.process_time) {
                        statsHtml += `<li><small class="text-muted">Tiempo de procesamiento: <strong>${timings.process_time.toFixed(2)}s</strong></small></li>`;
                    }
                }
                
                statsHtml += '</ul></div>';
                statsDiv.innerHTML = statsHtml;
                statsDiv.style.display = 'block';
            }
            
            showAlert('success', 'Transcripción completada. Puedes usar este resultado para probar la generación de informes.');
        } else {
            throw new Error(result.message || 'Error al transcribir');
        }
    } catch (error) {
        console.error('Error transcribiendo (prueba):', error);
        
        // Mensajes de error más amigables
        let errorMessage = error.message;
        
        // Errores relacionados con whisper.cpp
        if (errorMessage.includes('whisper.cpp') || errorMessage.includes('whisper') || errorMessage.includes('Whisper')) {
            if (errorMessage.includes('Connection refused') || errorMessage.includes('Failed to connect')) {
                errorMessage = 'No se pudo conectar a whisper.cpp. Verifica que:\n' +
                              '1. El servidor whisper.cpp esté corriendo\n' +
                              '2. La URL en Configuración → AI Informes sea correcta (ej: http://192.168.0.33:8080)\n' +
                              '3. No haya un firewall bloqueando la conexión\n' +
                              '4. El servidor whisper.cpp esté configurado para escuchar en la red (no solo localhost)\n\n' +
                              'Revisa la configuración en: Configuración → AI Informes → Configuración de Whisper.cpp';
            } else if (errorMessage.includes('timeout') || errorMessage.includes('Timeout')) {
                errorMessage = 'Timeout al conectar con whisper.cpp. El servidor puede estar sobrecargado o la red es lenta.';
            } else {
                errorMessage = 'Error con whisper.cpp: ' + errorMessage;
            }
        } else if (errorMessage.includes('Connection refused') || errorMessage.includes('Failed to connect')) {
            // Errores genéricos de conexión
            errorMessage = 'No se pudo conectar al servidor. Verifica que:\n' +
                          '1. El servidor esté corriendo\n' +
                          '2. La configuración de IP y puerto sea correcta\n' +
                          '3. No haya un firewall bloqueando la conexión\n\n' +
                          'Revisa la configuración en: Configuración → AI Informes';
        } else if (errorMessage.includes('timeout') || errorMessage.includes('Timeout')) {
            errorMessage = 'Timeout al conectar con el servidor. El servidor puede estar sobrecargado o la red es lenta.';
        }
        
        showAlert('error', 'Error al transcribir:\n' + errorMessage);
    } finally {
        if (transcribeBtn) {
            transcribeBtn.disabled = false;
            transcribeBtn.innerHTML = '<i class="fas fa-microphone"></i> Transcribir';
        }
    }
}

/**
 * Usar transcripción de prueba para generar informe
 */
function useTestTranscriptionForReport() {
    if (!testTranscriptionText) {
        // Intentar cargar desde localStorage
        const stored = localStorage.getItem('test_transcription');
        if (stored) {
            try {
                const data = JSON.parse(stored);
                testTranscriptionText = data.text;
                testTranscriptionSegments = data.segments || null;
            } catch (e) {
                console.error('Error cargando transcripción de prueba:', e);
            }
        }
    }
    
    if (!testTranscriptionText) {
        showAlert('error', 'No hay transcripción de prueba disponible. Por favor, transcribe un audio primero.');
        return;
    }
    
    // Cerrar modal de transcripción
    const transcribeModal = bootstrap.Modal.getInstance(document.getElementById('testTranscribeModal'));
    if (transcribeModal) transcribeModal.hide();
    
    // Abrir modal de generar informe en modo prueba
    openTestGenerateReportModal();
}

/**
 * Abrir modal de prueba de generar informe
 */
window.openTestGenerateReportModal = async function() {
    // Verificar que el usuario sea ROOT
    try {
        const response = await fetch('api/auth/validate-session-simple.php');
        const result = await response.json();
        
        if (!result.success || !result.user || result.user.nivel !== 'root') {
            showAlert('error', 'Solo los usuarios ROOT pueden acceder al Modo Prueba.');
            return;
        }
    } catch (error) {
        console.error('Error verificando usuario:', error);
        showAlert('error', 'No se pudo verificar los permisos. Solo los usuarios ROOT pueden acceder al Modo Prueba.');
        return;
    }
    
    // Cargar transcripción de prueba desde localStorage si no está en memoria
    if (!testTranscriptionText) {
        const stored = localStorage.getItem('test_transcription');
        if (stored) {
            try {
                const data = JSON.parse(stored);
                testTranscriptionText = data.text;
                testTranscriptionSegments = data.segments || null;
            } catch (e) {
                console.error('Error cargando transcripción:', e);
            }
        }
    }
    
    // Resetear formulario
    const transcriptionTextarea = document.getElementById('testReportTranscriptionText');
    if (transcriptionTextarea) {
        transcriptionTextarea.value = testTranscriptionText || '';
    }
    document.getElementById('testReportPreviewSection').style.display = 'none';
    
    // Cargar plantillas en el select de prueba
    await loadTemplatesForTest();
    
    const modal = new bootstrap.Modal(document.getElementById('testGenerateReportModal'));
    modal.show();
};

/**
 * Generar informe en modo prueba
 */
async function testGenerateReport() {
    const transcriptionTextarea = document.getElementById('testReportTranscriptionText');
    const transcription = transcriptionTextarea ? transcriptionTextarea.value.trim() : '';
    
    if (!transcription) {
        showAlert('error', 'Por favor, ingresa o carga una transcripción');
        return;
    }
    
    const templateSelect = document.getElementById('testReportTemplateSelect');
    const templateId = templateSelect ? templateSelect.value : '';
    
    const generateBtn = document.getElementById('testGenerateReportBtn');
    if (generateBtn) {
        generateBtn.disabled = true;
        generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando...';
    }
    
    try {
        const response = await fetch('api/ai-informes.php?action=test-generate-report', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: JSON.stringify({
                transcription: transcription,
                template_id: templateId || null
            })
        });
        
        let result;
        try {
            const text = await response.text();
            if (!text || text.trim() === '') {
                throw new Error('Respuesta vacía del servidor');
            }
            result = JSON.parse(text);
        } catch (parseError) {
            // Si no es JSON, puede ser HTML (error 504, 502, etc.)
            if (response.status === 504 || response.status === 502) {
                throw new Error('Timeout del servidor (504 Gateway Timeout). ' +
                    'Nginx está cortando la conexión antes de que termine la generación del informe. ' +
                    'Esto ocurre porque los modelos grandes (ej: medgemma:27b) pueden tardar 10-20 minutos. ' +
                    'SOLUCIÓN: Configura los timeouts de Nginx ejecutando: sudo bash /var/www/tjsiddse/configurar-timeout-nginx.sh ' +
                    'O edita manualmente /etc/nginx/sites-available/tu-sitio.conf y agrega: fastcgi_read_timeout 1800s; ' +
                    'Luego: sudo nginx -t && sudo systemctl reload nginx');
            }
            throw new Error('Error al procesar la respuesta del servidor: ' + parseError.message);
        }
        
        if (!response.ok) {
            if (response.status === 504 || response.status === 502) {
                throw new Error('Timeout del servidor (504 Gateway Timeout). ' +
                    'Nginx está cortando la conexión antes de que termine la generación del informe. ' +
                    'SOLUCIÓN: Configura los timeouts de Nginx. Ver: docs/configurar-timeout-nginx-informes.md');
            }
            throw new Error(result.message || `Error HTTP ${response.status}`);
        }
        
        if (result.success) {
            // Mostrar resultado
            const previewSection = document.getElementById('testReportPreviewSection');
            const previewContent = document.getElementById('testReportPreviewContent');
            const statsDiv = document.getElementById('testReportStats');
            
            if (previewSection && previewContent) {
                previewContent.textContent = result.data.text || '';
                previewSection.style.display = 'block';
            }
            
            // Mostrar estadísticas si están disponibles
            if (statsDiv && result.data) {
                let statsHtml = '<div class="mt-3"><small class="text-muted"><strong>Estadísticas:</strong></small><ul class="list-unstyled mb-0">';
                
                if (result.data.processing_time_formatted) {
                    statsHtml += `<li><small class="text-muted">Tiempo de generación: <strong>${result.data.processing_time_formatted}</strong></small></li>`;
                }
                
                if (result.data.model) {
                    statsHtml += `<li><small class="text-muted">Modelo: <strong>${result.data.model}</strong></small></li>`;
                }
                
                statsHtml += '</ul></div>';
                statsDiv.innerHTML = statsHtml;
                statsDiv.style.display = 'block';
            }
            
            showAlert('success', 'Informe generado exitosamente (modo prueba)');
        } else {
            throw new Error(result.message || 'Error al generar el informe');
        }
    } catch (error) {
        console.error('Error generando informe (prueba):', error);
        showAlert('error', 'Error al generar informe: ' + error.message);
    } finally {
        if (generateBtn) {
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-robot"></i> Generar Informe';
        }
    }
}

/**
 * Limpiar transcripción de prueba
 */
function clearTestTranscription() {
    testTranscriptionText = null;
    testTranscriptionSegments = null;
    showTimestamps = false;
    localStorage.removeItem('test_transcription');
    document.getElementById('testTranscriptionResult').style.display = 'none';
    showAlert('info', 'Transcripción de prueba limpiada');
}

/**
 * Formatear duración en formato MM:SS.mmm o HH:MM:SS.mmm
 */
function formatTimestamp(seconds) {
    if (!seconds && seconds !== 0) return '';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    const ms = Math.floor((seconds % 1) * 1000);
    
    if (hours > 0) {
        return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}.${ms.toString().padStart(3, '0')}`;
    } else {
        return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}.${ms.toString().padStart(3, '0')}`;
    }
}

/**
 * Formatear transcripción con timestamps
 */
function formatTranscriptionWithTimestamps(text, segments) {
    if (!segments || !Array.isArray(segments) || segments.length === 0) {
        return text;
    }
    
    let formattedText = '';
    
    segments.forEach((segment) => {
        const startTime = formatTimestamp(segment.start || 0);
        const endTime = formatTimestamp(segment.end || 0);
        const segmentText = segment.text || '';
        
        if (segmentText.trim()) {
            formattedText += `[${startTime} → ${endTime}] ${segmentText.trim()}\n`;
        }
    });
    
    return formattedText.trim();
}

/**
 * Actualizar visualización de la transcripción según el estado del toggle
 */
function updateTranscriptionDisplay() {
    const textDiv = document.getElementById('testTranscriptionText');
    if (!textDiv) return;
    
    if (showTimestamps && testTranscriptionSegments && testTranscriptionSegments.length > 0) {
        textDiv.textContent = formatTranscriptionWithTimestamps(testTranscriptionText, testTranscriptionSegments);
    } else {
        textDiv.textContent = testTranscriptionText || '';
    }
}

/**
 * Toggle para mostrar/ocultar timestamps
 */
function toggleTimestamps() {
    showTimestamps = !showTimestamps;
    updateTranscriptionDisplay();
    
    const toggleText = document.getElementById('timestampsToggleText');
    if (toggleText) {
        toggleText.textContent = showTimestamps ? 'Ocultar Timestamps' : 'Mostrar Timestamps';
    }
    
    const toggleBtn = document.getElementById('toggleTimestampsBtn');
    if (toggleBtn) {
        if (showTimestamps) {
            toggleBtn.classList.remove('btn-outline-secondary');
            toggleBtn.classList.add('btn-secondary');
        } else {
            toggleBtn.classList.remove('btn-secondary');
            toggleBtn.classList.add('btn-outline-secondary');
        }
    }
}

/**
 * Mostrar modal con lista de audios de un estudio
 */
window.showAudiosModal = async function(studyId, orthancStudyId, paciente) {
    // Normalizar parámetros
    studyId = studyId === 'null' || studyId === null ? null : studyId;
    orthancStudyId = orthancStudyId === 'null' || orthancStudyId === null ? null : orthancStudyId;
    paciente = paciente === 'null' || paciente === null ? null : paciente;
    
    // Guardar parámetros del modal actual para poder recargarlo
    currentModalStudyId = studyId;
    currentModalOrthancStudyId = orthancStudyId;
    
    // Cargar audios desde servidor
    let audios = [];
    let estudioData = null;
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes.php?action=list-audios', {
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });
            const result = await response.json();
            if (result.success) {
                // Buscar el estudio por ID o por orthanc_study_id
                
                // Primero intentar por estudio_id numérico
                if (studyId && studyId !== 'null' && studyId !== null) {
                    estudioData = result.data.find(e => e.estudio.id === parseInt(studyId));
                }
                
                // Si no se encuentra, buscar por orthanc_study_id
                if (!estudioData && orthancStudyId && orthancStudyId !== 'null' && orthancStudyId !== null) {
                    estudioData = result.data.find(e => e.estudio.orthanc_study_id === orthancStudyId);
                }
                
                // Si aún no se encuentra y hay un orthanc_study_id, buscar en todos los grupos
                // y verificar si algún audio del grupo tiene relación con ese orthanc_study_id
                if (!estudioData && orthancStudyId && orthancStudyId !== 'null' && orthancStudyId !== null) {
                    for (const item of result.data) {
                        if (item.estudio.orthanc_study_id === orthancStudyId) {
                            estudioData = item;
                            break;
                        }
                    }
                }
                
                // Si aún no se encuentra, buscar el primer grupo que tenga audios
                // (útil cuando no hay estudio_id pero hay audios en el sistema)
                if (!estudioData && result.data.length > 0) {
                    estudioData = result.data.find(e => e.audios && e.audios.length > 0);
                }
                
                if (estudioData) {
                    audios = estudioData.audios || [];
                } else {
                    console.warn('No se encontró estudio con los parámetros:', { studyId, orthancStudyId, paciente });
                }
            }
    } catch (error) {
        console.error('Error cargando audios:', error);
        showAlert('error', 'Error al cargar audios del servidor');
        return;
    }
    
    if (audios.length === 0) {
        showAlert('info', 'Este estudio no tiene audios');
        return;
    }
    
    // Determinar título del modal y subtítulo con información del estudio
    let modalTitle = 'Audios';
    let modalSubtitle = '';
    
    if (studyId && studyId !== 'null' && studyId !== null) {
        modalTitle = `Audios del Estudio #${studyId}`;
    } else if (paciente && paciente !== 'null' && paciente !== null) {
        modalTitle = `Audios - ${paciente}`;
    } else if (orthancStudyId && orthancStudyId !== 'null' && orthancStudyId !== null) {
        const shortId = orthancStudyId.length > 30 ? orthancStudyId.substring(0, 30) + '...' : orthancStudyId;
        modalTitle = `Audios del Estudio (${shortId})`;
    } else if (audios.length > 0) {
        // Si hay audios pero no hay información del estudio, usar información del primer audio
        const firstAudio = audios[0];
        if (firstAudio.nombre_original) {
            const audioName = firstAudio.nombre_original.length > 40 ? firstAudio.nombre_original.substring(0, 40) + '...' : firstAudio.nombre_original;
            modalTitle = `Audios - ${audioName}`;
        } else {
            modalTitle = `Audios (${audios.length} audio${audios.length > 1 ? 's' : ''})`;
        }
    } else {
        modalTitle = 'Audios del Sistema';
    }
    
    // Agregar información del estudio al subtítulo
    if (estudioData && estudioData.estudio) {
        const estudio = estudioData.estudio;
        const infoParts = [];
        
        if (estudio.patient_id_pacs) {
            infoParts.push(`ID Paciente: ${estudio.patient_id_pacs}`);
        }
        if (estudio.study_date) {
            const fechaEstudio = new Date(estudio.study_date).toLocaleDateString('es-ES');
            infoParts.push(`Fecha Estudio: ${fechaEstudio}`);
        }
        
        if (infoParts.length > 0) {
            modalSubtitle = infoParts.join(' | ');
        }
    }
    
    // Crear contenido del modal dinámicamente
    let modalContent = `
        <div class="modal fade" id="audiosModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1">${modalTitle} (${audios.length} audio${audios.length > 1 ? 's' : ''})</h5>
                            ${modalSubtitle ? `<small class="text-muted">${modalSubtitle}</small>` : ''}
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAllAudios" onchange="toggleSelectAllAudios(this)" title="Seleccionar todos">
                                        </th>
                                        <th>ID</th>
                                        <th>Audio</th>
                                        <th>Dictado por</th>
                                        <th>Fecha Dictado</th>
                                        <th>Tamaño</th>
                                        <th>Duración</th>
                                        <th>Transcripción</th>
                                        <th>Informe</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
    `;
    
    // Verificar permisos del usuario (una sola vez para todos los audios)
    let hasTranscribePermission = true; // Por defecto true para no bloquear si no hay manager
    let hasGenerateReportPermission = true; // Por defecto true para no bloquear si no hay manager
    
    if (window.simplePermissionManager) {
        // Verificar permisos de forma síncrona si están disponibles
        const userPermissions = window.simplePermissionManager.getUserPermissions() || [];
        const isRoot = window.simplePermissionManager.currentUser && window.simplePermissionManager.currentUser.nivel === 'root';
        hasTranscribePermission = isRoot || userPermissions.includes('transcribir_ai') || userPermissions.includes('all');
        hasGenerateReportPermission = isRoot || userPermissions.includes('generar_informe_ai') || userPermissions.includes('all');
    }
    
    // Guardar el permiso de transcripción en variable global para que updateTranscribeSelectedButton() pueda acceder
    modalHasTranscribePermission = hasTranscribePermission;
    
    audios.forEach(audio => {
        const transcriptionStatus = audio.transcription ? audio.transcription.status : 'none';
        const reportStatus = audio.report ? audio.report.status : 'none';
        const transcriptionBadge = transcriptionStatus === 'completed' ? 
            '<span class="badge bg-success">Completada</span>' : 
            transcriptionStatus === 'processing' ? 
            '<span class="badge bg-info"><i class="fas fa-spinner fa-spin"></i> Procesando</span>' : 
            transcriptionStatus === 'failed' ? 
            '<span class="badge bg-danger">Error</span>' : 
            '<span class="badge bg-secondary">Pendiente</span>';
        
        const reportBadge = reportStatus === 'completed' ? 
            '<span class="badge bg-success">Completado</span>' : 
            reportStatus === 'draft' ? 
            '<span class="badge bg-warning">Borrador</span>' : 
            '<span class="badge bg-secondary">Pendiente</span>';
        
        // Verificar si puede transcribir: debe tener permiso Y el audio no estar en proceso
        // Permitir retranscribir incluso si ya está completado (requerirá confirmación)
        const canTranscribe = hasTranscribePermission && transcriptionStatus !== 'processing';
        const isRetranscribe = transcriptionStatus === 'completed'; // Indica si es una retranscripción
        // Verificar si puede generar informe: debe tener permiso Y haber transcripción completada Y no estar en proceso
        const canGenerateReport = hasGenerateReportPermission && transcriptionStatus === 'completed' && reportStatus !== 'processing';
        
        // Usar la información del estudio del audio (prioridad) o la del modal como fallback
        const audioStudyId = audio.estudio_id || studyId;
        const audioOrthancStudyId = audio.orthanc_study_id || orthancStudyId;
        
        // Preparar valores para los botones
        const studyIdForButtons = (audioStudyId && audioStudyId !== 'null' && audioStudyId !== null) ? audioStudyId : 'null';
        const orthancStudyIdForButtons = audioOrthancStudyId && audioOrthancStudyId !== 'null' && audioOrthancStudyId !== null 
            ? `'${String(audioOrthancStudyId).replace(/'/g, "\\'")}'` 
            : 'null';
        
        // Verificar si hay transcripción bloqueada (processing por más de 10 minutos sería ideal, pero por ahora solo verificamos si está en processing)
        const hasBlockedTranscription = transcriptionStatus === 'processing';
        
        // Obtener nombre del usuario que dictó el audio
        const usuarioNombre = audio.usuario_nombre && audio.usuario_apellido
            ? `${audio.usuario_nombre} ${audio.usuario_apellido}`
            : audio.usuario_nombre || 'N/A';
        
        // Formatear fecha de dictado
        const fechaDictado = audio.fecha_creacion 
            ? new Date(audio.fecha_creacion).toLocaleString('es-ES', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            })
            : 'N/A';
        
        modalContent += `
            <tr>
                <td>
                    <input type="checkbox" class="audio-checkbox" value="${audio.id}" 
                           data-study-id="${studyIdForButtons}" 
                           data-orthanc-study-id="${orthancStudyIdForButtons}"
                           onchange="updateTranscribeSelectedButton()">
                </td>
                <td>
                    <span class="badge bg-secondary" title="ID del audio">${audio.id}</span>
                </td>
                <td>
                    <i class="fas fa-file-audio me-2"></i>
                    ${(audio.nombre_original || audio.nombre_archivo || 'Sin nombre').replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                </td>
                <td>
                    <span class="badge bg-info" title="Usuario que dictó el audio">${usuarioNombre.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</span>
                </td>
                <td>
                    <small>${fechaDictado}</small>
                </td>
                <td>${formatFileSize(audio.tamano_bytes || 0)}</td>
                <td>
                    ${audio.duracion_segundos && audio.duracion_segundos > 0 
                        ? formatDurationFromSeconds(audio.duracion_segundos) 
                        : '<span class="text-muted">N/A</span>'}
                </td>
                <td>${transcriptionBadge}</td>
                <td>${reportBadge}</td>
                <td>
                    <div class="btn-group btn-group-sm" role="group" style="flex-wrap: wrap;">
                        <button class="btn ${isRetranscribe ? 'btn-warning' : 'btn-primary'}" 
                                style="padding: 0.125rem 0.375rem; font-size: 0.75rem;"
                                onclick="transcribeAudioFromList(${audio.id}, ${studyIdForButtons}, ${orthancStudyIdForButtons}, ${isRetranscribe ? 'true' : 'false'})" 
                                ${canTranscribe ? '' : 'disabled'}
                                title="${!hasTranscribePermission ? 'No tienes permisos para transcribir con AI. Contacta al administrador.' : isRetranscribe ? 'Retranscribir audio (reemplazará la transcripción existente)' : canTranscribe ? 'Transcribir audio' : 'Transcripción en proceso'}">
                            <i class="fas fa-microphone"></i>
                        </button>
                        ${hasBlockedTranscription ? 
                            `<button class="btn btn-warning" 
                                    style="padding: 0.125rem 0.375rem; font-size: 0.75rem;"
                                    onclick="cleanupBlockedTranscriptionFromList(${audio.id})" 
                                    title="Limpiar transcripción bloqueada">
                                <i class="fas fa-broom"></i>
                            </button>` : ''}
                        <button class="btn btn-success" 
                                style="padding: 0.125rem 0.375rem; font-size: 0.75rem;"
                                onclick="generateReportFromAudio(${audio.id}, ${audio.transcription ? audio.transcription.id : 'null'}, ${studyIdForButtons})" 
                                ${canGenerateReport ? '' : 'disabled'}
                                title="${!hasGenerateReportPermission ? 'No tienes permisos para generar informes con AI. Contacta al administrador.' : canGenerateReport ? 'Generar informe' : 'Requiere transcripción completada'}">
                            <i class="fas fa-robot"></i>
                        </button>
                        ${audio.transcription && audio.transcription.status === 'completed' ? 
                            `<button class="btn btn-info" 
                                    style="padding: 0.125rem 0.375rem; font-size: 0.75rem;"
                                    onclick="viewTranscription(${audio.transcription.id})" 
                                    title="Ver transcripción">
                                <i class="fas fa-eye"></i>
                            </button>` : ''}
                        ${audio.report && audio.report.status === 'completed' ? 
                            `<button class="btn btn-info" 
                                    style="padding: 0.125rem 0.375rem; font-size: 0.75rem;"
                                    onclick="viewReport(${audio.report.id})" 
                                    title="Ver informe">
                                <i class="fas fa-file-alt"></i>
                            </button>` : ''}
                        <button class="btn btn-secondary" 
                                style="padding: 0.125rem 0.375rem; font-size: 0.75rem;"
                                onclick="downloadAudioAsMp3(${audio.id}, '${(audio.nombre_original || audio.nombre_archivo || 'audio').replace(/'/g, "\\'")}')" 
                                title="Descargar audio en formato MP3">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });
    
    modalContent += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" id="transcribeSelectedBtn" onclick="transcribeSelectedAudios()" disabled
                                title="${!hasTranscribePermission ? 'No tienes permisos para transcribir con AI. Contacta al administrador.' : 'Transcribir los audios seleccionados'}">
                            <i class="fas fa-microphone"></i> Transcribir Seleccionados (<span id="selectedCount">0</span>)
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remover modal anterior si existe
    const existingModal = document.getElementById('audiosModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Agregar modal al DOM
    document.body.insertAdjacentHTML('beforeend', modalContent);
    
    // Mostrar modal
    const modal = new bootstrap.Modal(document.getElementById('audiosModal'));
    modal.show();
    
    // Limpiar modal al cerrar
    document.getElementById('audiosModal').addEventListener('hidden.bs.modal', function() {
        // Limpiar parámetros del modal cuando se cierra
        currentModalStudyId = null;
        currentModalOrthancStudyId = null;
        modalHasTranscribePermission = true; // Resetear a valor por defecto
        this.remove();
    });
    
    // Inicializar el estado del botón de transcribir seleccionados
    updateTranscribeSelectedButton();
    
    // Obtener duración de audios que no la tienen usando HTML5 Audio
    loadAudioDurationsForModal(audios);
}

/**
 * Obtener duración de audios sin duración usando HTML5 Audio
 */
async function loadAudioDurationsForModal(audios) {
    // Filtrar audios que no tienen duración
    const audiosSinDuracion = audios.filter(audio => !audio.duracion_segundos || audio.duracion_segundos <= 0);
    
    if (audiosSinDuracion.length === 0) {
        return; // Todos los audios ya tienen duración
    }
    
    console.log(`Obteniendo duración para ${audiosSinDuracion.length} audio(s) sin duración...`);
    
    // Procesar cada audio sin duración
    for (const audio of audiosSinDuracion) {
        try {
            // Construir URL del audio (similar a informes-manager.js)
            let audioUrl = '';
            if (audio.ruta_archivo) {
                // Si viene con ruta_archivo, construir la URL completa
                if (audio.ruta_archivo.startsWith('uploads/')) {
                    audioUrl = '../' + audio.ruta_archivo;
                } else if (audio.ruta_archivo.startsWith('../')) {
                    audioUrl = audio.ruta_archivo;
                } else if (audio.ruta_archivo.startsWith('http')) {
                    audioUrl = audio.ruta_archivo;
                } else {
                    // Si no tiene prefijo, asumir que está en uploads/audios/
                    audioUrl = '../uploads/audios/' + audio.ruta_archivo;
                }
            } else if (audio.nombre_archivo) {
                // Fallback: usar nombre_archivo
                audioUrl = '../uploads/audios/' + audio.nombre_archivo;
            } else {
                console.warn(`No se puede construir URL para audio_id=${audio.id}: falta ruta_archivo y nombre_archivo`);
                continue;
            }
            
            const fullUrl = audioUrl;
            
            // Crear elemento Audio oculto para obtener duración
            const audioElement = document.createElement('audio');
            audioElement.preload = 'metadata';
            audioElement.style.display = 'none';
            
            // Función para manejar cuando se carga el metadata
            const handleLoadedMetadata = async () => {
                const duration = audioElement.duration;
                
                // Verificar que la duración sea válida
                if (duration && isFinite(duration) && duration > 0) {
                    console.log(`Duración obtenida para audio_id=${audio.id}: ${duration} segundos`);
                    
                    // Actualizar en el backend
                    try {
                        const token = getAuthToken();
                        const response = await fetch('api/ai-informes.php?action=update-audio-duration', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Authorization': 'Bearer ' + token
                            },
                            body: JSON.stringify({
                                audio_id: audio.id,
                                duration: duration
                            })
                        });
                        
                        const result = await response.json();
                        if (result.success) {
                            // Actualizar la visualización en el modal
                            updateAudioDurationInModal(audio.id, duration);
                        } else {
                            console.warn(`Error actualizando duración para audio_id=${audio.id}:`, result.message);
                        }
                    } catch (error) {
                        console.error(`Error enviando duración al backend para audio_id=${audio.id}:`, error);
                    }
                } else {
                    console.warn(`Duración no válida para audio_id=${audio.id}:`, duration);
                }
                
                // Limpiar
                audioElement.remove();
            };
            
            // Agregar event listeners
            audioElement.addEventListener('loadedmetadata', handleLoadedMetadata);
            audioElement.addEventListener('error', (e) => {
                console.error(`Error cargando audio_id=${audio.id}:`, e);
                audioElement.remove();
            });
            
            // Configurar timeout (10 segundos máximo)
            setTimeout(() => {
                if (audioElement.parentNode) {
                    console.warn(`Timeout obteniendo duración para audio_id=${audio.id}`);
                    audioElement.remove();
                }
            }, 10000);
            
            // Agregar al DOM y cargar
            document.body.appendChild(audioElement);
            audioElement.src = fullUrl;
            audioElement.load();
            
            // Esperar un poco entre cada carga para no sobrecargar
            await new Promise(resolve => setTimeout(resolve, 200));
            
        } catch (error) {
            console.error(`Error procesando audio_id=${audio.id}:`, error);
        }
    }
}

/**
 * Actualizar la duración mostrada en el modal
 */
function updateAudioDurationInModal(audioId, duration) {
    // Buscar la fila del audio en el modal
    const modal = document.getElementById('audiosModal');
    if (!modal) return;
    
    // Buscar todas las filas de la tabla
    const rows = modal.querySelectorAll('tbody tr');
    for (const row of rows) {
        // Buscar el checkbox con el audio_id
        const checkbox = row.querySelector(`input.audio-checkbox[value="${audioId}"]`);
        if (checkbox) {
            // Encontrar la celda de duración (columna 6, índice 5)
            const durationCell = row.cells[6]; // Índice 6 es la columna "Duración"
            if (durationCell) {
                durationCell.innerHTML = formatDurationFromSeconds(duration);
                console.log(`Duración actualizada en modal para audio_id=${audioId}: ${duration} segundos`);
            }
            break;
        }
    }
}

/**
 * Mostrar modal de confirmación personalizado
 */
/**
 * Función auxiliar para escapar HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Mostrar modal de confirmación personalizado (reemplaza confirm() del navegador)
 */
function showConfirmModal(title, message, onConfirm, onCancel = null) {
    // Remover modal anterior si existe
    const existingModal = document.getElementById('confirmModal');
    if (existingModal) {
        const existingBootstrapModal = bootstrap.Modal.getInstance(existingModal);
        if (existingBootstrapModal) {
            existingBootstrapModal.hide();
        }
        existingModal.remove();
    }
    
    // Escapar HTML para seguridad
    const safeTitle = escapeHtml(title);
    const safeMessage = escapeHtml(message);
    
    const modalHtml = `
        <div class="modal fade" id="confirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>${safeTitle}</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">${safeMessage}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="confirmModalCancel">
                            <i class="fas fa-times me-1"></i> Cancelar
                        </button>
                        <button type="button" class="btn btn-primary" id="confirmModalConfirm">
                            <i class="fas fa-check me-1"></i> Confirmar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    const modalElement = document.getElementById('confirmModal');
    const modal = new bootstrap.Modal(modalElement);
    
    // Configurar botón de confirmar
    const confirmBtn = document.getElementById('confirmModalConfirm');
    confirmBtn.addEventListener('click', function() {
        modal.hide();
        // Pequeño delay para que la animación de cierre se complete
        setTimeout(() => {
            if (onConfirm) {
                onConfirm();
            }
        }, 300);
    });
    
    // Configurar botón de cancelar
    const cancelBtn = document.getElementById('confirmModalCancel');
    cancelBtn.addEventListener('click', function() {
        if (onCancel) {
            onCancel();
        }
    });
    
    // Limpiar al cerrar
    modalElement.addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
    
    // Mostrar modal
    modal.show();
}

/**
 * Transcribir audio desde la lista
 */
async function transcribeAudioFromList(audioId, studyId, orthancStudyId, isRetranscribe = false) {
    // Verificar permiso para transcribir con AI
    if (window.simplePermissionManager) {
        const permissionCheck = await window.simplePermissionManager.checkPermission('transcribir_ai');
        if (!permissionCheck.hasPermission) {
            showAlert('error', 'No tienes permisos para transcribir audios con AI. Contacta al administrador para habilitar el permiso "Transcribir con AI" en tu cuenta.');
            return;
        }
    }
    
    // Verificar cola
    if (processingQueue.transcription !== null) {
        showAlert('warning', 'Ya hay una transcripción en proceso. Espera a que termine.');
        return;
    }
    
    // Normalizar parámetros
    studyId = studyId === 'null' || studyId === null ? null : studyId;
    orthancStudyId = orthancStudyId === 'null' || orthancStudyId === null ? null : orthancStudyId;
    // Normalizar isRetranscribe: puede venir como booleano, string 'true'/'false', o string 'null'
    if (typeof isRetranscribe === 'string') {
        isRetranscribe = isRetranscribe.toLowerCase() === 'true';
    }
    isRetranscribe = isRetranscribe === true;
    
    // Mensaje de confirmación diferente si es retranscripción
    const confirmTitle = isRetranscribe ? 'Confirmar Retranscripción' : 'Confirmar Transcripción';
    const confirmMessage = isRetranscribe 
        ? 'Este audio ya tiene una transcripción. ¿Deseas transcribirlo nuevamente? La nueva transcripción reemplazará la anterior. Esto puede tomar varios minutos dependiendo del tamaño del archivo.'
        : '¿Transcribir este audio? Esto puede tomar varios minutos dependiendo del tamaño del archivo.';
    
    // Mostrar modal de confirmación personalizado
    showConfirmModal(
        confirmTitle,
        confirmMessage,
        () => {
            // Función de confirmación
            executeTranscription(audioId, studyId, orthancStudyId, isRetranscribe);
        }
    );
}

/**
 * Ejecutar transcripción (llamado después de confirmar)
 */
async function executeTranscription(audioId, studyId, orthancStudyId, isRetranscribe = false) {
    processingQueue.transcription = audioId;
    updateProcessingButtons();
    
    
    try {
        const token = getAuthToken();
        const requestBody = {
            audio_id: audioId
        };
        
        // Agregar study_id si está disponible
        if (studyId && studyId !== 'null' && studyId !== null) {
            requestBody.study_id = studyId;
        }
        
        // Agregar orthanc_study_id si está disponible
        if (orthancStudyId && orthancStudyId !== 'null' && orthancStudyId !== null) {
            requestBody.orthanc_study_id = orthancStudyId;
        }
        
        // Agregar retranscribe si es una retranscripción (ya está normalizado como booleano)
        if (isRetranscribe === true) {
            requestBody.retranscribe = true;
        }
        
        // Log para depuración
        console.log('Enviando request de transcripción:', {
            audio_id: audioId,
            retranscribe: requestBody.retranscribe || false,
            isRetranscribe: isRetranscribe,
            requestBody: requestBody
        });
        
        const response = await fetch('api/ai-informes.php?action=transcribe-audio', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(requestBody)
        });
        
        // Verificar si la respuesta es exitosa
        if (!response.ok) {
            let errorMessage = 'Error al transcribir audio';
            try {
                const errorResult = await response.json();
                errorMessage = errorResult.message || errorResult.error || errorMessage;
            } catch (e) {
                errorMessage = `Error ${response.status}: ${response.statusText}`;
            }
            throw new Error(errorMessage);
        }
        
        const result = await response.json();
        
        if (result.success) {
            // Usar el mensaje del backend si está disponible, o un mensaje por defecto
            const message = result.message || 'Transcripción completada exitosamente';
            showAlert('success', message);
            
            // Si fue agregado a la cola, no cerrar el modal inmediatamente
            if (result.queued) {
                console.log('Audio agregado a la cola de transcripción. Se procesará en breve.');
            } else {
                // Si se procesó directamente y tenemos el transcription_id, mostrar el modal de transcripción
                if (result.data && result.data.transcription_id) {
                    // Guardar parámetros del modal de audios antes de cerrarlo
                    const audiosModal = document.getElementById('audiosModal');
                    const modalInstance = bootstrap.Modal.getInstance(audiosModal);
                    const studyId = currentModalStudyId;
                    const orthancStudyId = currentModalOrthancStudyId;
                    const paciente = audiosModal?.querySelector('.modal-title')?.textContent?.split('(')[0]?.trim() || null;
                    
                    // Cerrar el modal de audios primero
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                    
                    // Esperar un momento para que se cierre el modal anterior y luego abrir el modal de transcripción
                    setTimeout(() => {
                        viewTranscription(result.data.transcription_id);
                        
                        // Después de mostrar la transcripción, recargar el modal de audios si estaba abierto
                        // Esto asegura que la duración actualizada se muestre
                        setTimeout(() => {
                            if (studyId !== null || orthancStudyId !== null) {
                                showAudiosModal(studyId, orthancStudyId, paciente);
                            } else if (estudiosTable) {
                                estudiosTable.ajax.reload();
                            }
                        }, 500);
                    }, 300);
                } else {
                    // Si no hay transcription_id, recargar el modal de audios para mostrar la duración actualizada
                    const audiosModal = document.getElementById('audiosModal');
                    if (audiosModal && audiosModal.classList.contains('show')) {
                        const studyId = currentModalStudyId;
                        const orthancStudyId = currentModalOrthancStudyId;
                        const paciente = audiosModal?.querySelector('.modal-title')?.textContent?.split('(')[0]?.trim() || null;
                        const modalInstance = bootstrap.Modal.getInstance(audiosModal);
                        if (modalInstance) {
                            modalInstance.hide();
                            setTimeout(() => {
                                if (studyId !== null || orthancStudyId !== null) {
                                    showAudiosModal(studyId, orthancStudyId, paciente);
                                } else if (estudiosTable) {
                                    estudiosTable.ajax.reload();
                                }
                            }, 300);
                        }
                    } else {
                        // Si el modal no está abierto, solo recargar la tabla
                        if (estudiosTable) {
                            estudiosTable.ajax.reload();
                        }
                    }
                }
            }
        } else {
            throw new Error(result.message || 'Error al transcribir');
        }
    } catch (error) {
        console.error('Error transcribiendo audio:', error);
        showAlert('error', 'Error al transcribir: ' + error.message);
    } finally {
        processingQueue.transcription = null;
        updateProcessingButtons();
    }
}

/**
 * Generar informe desde audio
 */
async function generateReportFromAudio(audioId, transcriptionId, studyId) {
    // Verificar permiso para generar informe con AI
    if (window.simplePermissionManager) {
        const permissionCheck = await window.simplePermissionManager.checkPermission('generar_informe_ai');
        if (!permissionCheck.hasPermission) {
            showAlert('error', 'No tienes permisos para generar informes con AI. Contacta al administrador para habilitar el permiso "Generar Informe con AI" en tu cuenta.');
            return;
        }
    }
    
    // Verificar cola
    if (processingQueue.report !== null) {
        showAlert('warning', 'Ya hay una generación de informe en proceso. Espera a que termine.');
        return;
    }
    
    if (!transcriptionId || transcriptionId === 'null') {
        showAlert('error', 'Este audio no tiene transcripción. Transcribe el audio primero.');
        return;
    }
    
    // Mostrar modal de confirmación personalizado
    showConfirmModal(
        'Confirmar Generación de Informe',
        '¿Generar informe desde esta transcripción? Esto puede tomar varios minutos dependiendo del modelo y la longitud de la transcripción.',
        () => {
            // Función de confirmación
            executeReportGeneration(audioId, transcriptionId, studyId);
        }
    );
}

/**
 * Ejecutar generación de informe (llamado después de confirmar)
 */
async function executeReportGeneration(audioId, transcriptionId, studyId) {
    processingQueue.report = audioId;
    updateProcessingButtons();
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes.php?action=generate-report-from-audio', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                audio_id: audioId,
                transcription_id: transcriptionId,
                study_id: studyId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('success', 'Informe generado exitosamente');
            // Recargar tabla
            if (estudiosTable) {
                estudiosTable.ajax.reload();
            }
            // Cerrar modal y recargar
            const modal = bootstrap.Modal.getInstance(document.getElementById('audiosModal'));
            if (modal) {
                modal.hide();
            }
        } else {
            throw new Error(result.message || 'Error al generar informe');
        }
    } catch (error) {
        console.error('Error generando informe:', error);
        showAlert('error', 'Error al generar informe: ' + error.message);
    } finally {
        processingQueue.report = null;
        updateProcessingButtons();
    }
}

/**
 * Actualizar estado de botones según cola de procesamiento
 */
function updateProcessingButtons() {
    // Esta función se puede expandir para deshabilitar/habilitar botones globalmente
    // Por ahora, los botones se manejan individualmente en el modal
}

/**
 * Formatear duración en segundos a formato legible
 */
function formatDurationFromSeconds(seconds) {
    if (!seconds) return 'N/A';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    
    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    } else {
        return `${minutes}:${String(secs).padStart(2, '0')}`;
    }
}

/**
 * Formatear duración en segundos (decimal) a formato legible con decimales
 */
function formatDuration(seconds) {
    if (!seconds && seconds !== 0) return 'N/A';
    const totalSeconds = Math.floor(seconds);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const secs = totalSeconds % 60;
    const ms = Math.floor((seconds % 1) * 1000);
    
    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}.${String(ms).padStart(3, '0')}s`;
    } else if (minutes > 0) {
        return `${minutes}:${String(secs).padStart(2, '0')}.${String(ms).padStart(3, '0')}s`;
    } else {
        return `${secs}.${String(ms).padStart(3, '0')}s`;
    }
}

/**
 * Ver transcripción
 */
async function viewTranscription(transcriptionId) {
    if (!transcriptionId) {
        showAlert('error', 'ID de transcripción no válido');
        return;
    }
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes.php?action=get-transcription', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ transcription_id: transcriptionId })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Error al obtener la transcripción');
        }
        
        const transcription = result.data;
        
        // Crear modal similar al de "Probar Transcripción"
        const modalHtml = `
            <div class="modal fade" id="viewTranscriptionModal" tabindex="-1">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header bg-info text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-file-alt me-2"></i>Ver Transcripción
                            </h5>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-light" id="btnConfigureHighlight" onclick="openTranscriptionHighlightConfig()" title="Configurar Resaltado" style="display: none;">
                                    <i class="fas fa-cog me-1"></i> Configurar
                                </button>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                        </div>
                        <div class="modal-body">
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label text-muted small mb-0">Archivo de audio</label>
                                    <p class="mb-0 fw-semibold">${escapeHtml(transcription.audio_name || 'Sin nombre')}</p>
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label text-muted small mb-0">Modelo</label>
                                    <p class="mb-0">${escapeHtml(transcription.model_used || 'N/A')}</p>
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label text-muted small mb-0">Procesamiento</label>
                                    <p class="mb-0">
                                        ${transcription.processing_time ? formatDuration(transcription.processing_time) : '—'}
                                        ${transcription.audio_duration ? ` / ${formatDurationFromSeconds(transcription.audio_duration)}` : ''}
                                    </p>
                                </div>
                            </div>

                            <!-- Tabs: Texto limpio / Con timestamps / Debug JSON -->
                            <ul class="nav nav-tabs mb-3" id="transcriptionTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="tab-text" data-bs-toggle="tab" data-bs-target="#pane-text" type="button" role="tab">
                                        <i class="fas fa-align-left me-1"></i>Texto
                                    </button>
                                </li>
                                ${transcription.segments && transcription.segments.length ? `
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-timestamps" data-bs-toggle="tab" data-bs-target="#pane-timestamps" type="button" role="tab">
                                        <i class="fas fa-clock me-1"></i>Con timestamps
                                    </button>
                                </li>
                                ` : ''}
                                ${transcription.whisper_response ? `
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-debug" data-bs-toggle="tab" data-bs-target="#pane-debug" type="button" role="tab">
                                        <i class="fas fa-bug me-1"></i>Debug JSON
                                    </button>
                                </li>
                                ` : ''}
                            </ul>

                            <div class="tab-content" id="transcriptionTabContent">
                                <!-- Tab: Texto limpio con reproductor -->
                                <div class="tab-pane fade show active" id="pane-text" role="tabpanel">
                                    ${transcription.audio_url && transcription.segments && transcription.segments.length ? `
                                    <div class="mb-3">
                                        <audio id="transcriptionAudioPlayer" controls style="width:100%;" preload="metadata">
                                            <source src="${escapeHtml(transcription.audio_url)}" type="audio/mpeg">
                                            Tu navegador no soporta el elemento de audio.
                                        </audio>
                                    </div>
                                    ` : ''}
                                    <div class="card">
                                        <div class="card-body p-3">
                                            <div id="viewTranscriptionText" 
                                                 data-segments='${transcription.segments ? escapeHtml(JSON.stringify(transcription.segments)) : "[]"}' 
                                                 style="max-height:380px;overflow-y:auto;white-space:pre-wrap;font-family:inherit;line-height:1.7;text-align:left;cursor:text;">
                                                ${escapeHtml(transcription.transcription_text || 'Sin transcripción disponible')}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tab: Con timestamps (formato whisper-cli) -->
                                ${transcription.segments && transcription.segments.length ? `
                                <div class="tab-pane fade" id="pane-timestamps" role="tabpanel">
                                    <pre class="bg-dark text-light p-3 rounded mb-0" style="max-height:380px;overflow-y:auto;font-size:0.82rem;line-height:1.6;white-space:pre;">${buildWhisperCliFormat(transcription.segments)}</pre>
                                </div>
                                ` : ''}

                                <!-- Tab: Debug JSON -->
                                ${transcription.whisper_response ? `
                                <div class="tab-pane fade" id="pane-debug" role="tabpanel">
                                    <pre class="bg-dark text-success p-3 rounded mb-0" style="max-height:380px;overflow-y:auto;font-size:0.78rem;white-space:pre-wrap;word-break:break-all;">${escapeHtml(JSON.stringify(transcription.whisper_response, null, 2))}</pre>
                                </div>
                                ` : ''}
                            </div>

                            <div class="mt-2">
                                <small class="text-muted">
                                    <strong>Creado:</strong> ${new Date(transcription.created_at).toLocaleString('es-ES')}
                                    ${transcription.updated_at && transcription.updated_at !== transcription.created_at ? 
                                        ` &nbsp;·&nbsp; <strong>Actualizado:</strong> ${new Date(transcription.updated_at).toLocaleString('es-ES')}` : ''}
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-primary" onclick="copyTranscriptionToClipboard(${transcriptionId})">
                                <i class="fas fa-copy me-1"></i> Copiar Transcripción
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal anterior si existe
        const existingModal = document.getElementById('viewTranscriptionModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('viewTranscriptionModal'));
        modal.show();
        
        // Verificar permisos para mostrar botón de configuración
        const configureBtn = document.getElementById('btnConfigureHighlight');
        if (configureBtn && window.simplePermissionManager?.currentUser) {
            const currentUser = window.simplePermissionManager.currentUser;
            if (currentUser.nivel === 'root' || currentUser.nivel === 'admin') {
                configureBtn.style.display = 'inline-block';
            }
        }
        
        // Inicializar reproductor interactivo si hay audio y segments
        if (transcription.audio_url && transcription.segments && transcription.segments.length) {
            setTimeout(() => {
                initInteractiveTranscription(transcription);
            }, 300); // Esperar a que el modal se renderice
        }
        
        // Limpiar modal al cerrar
        document.getElementById('viewTranscriptionModal').addEventListener('hidden.bs.modal', function() {
            // Limpiar instancia del plugin
            if (transcriptionPluginInstance) {
                transcriptionPluginInstance.destroy();
                transcriptionPluginInstance = null;
            }
            this.remove();
        });
        
    } catch (error) {
        console.error('Error obteniendo transcripción:', error);
        showAlert('error', 'Error al obtener la transcripción: ' + error.message);
    }
}

/**
 * Formatea segundos a HH:MM:SS.mmm (formato whisper-cli)
 */
function formatSecondsToWhisperTs(seconds) {
    const h  = Math.floor(seconds / 3600);
    const m  = Math.floor((seconds % 3600) / 60);
    const s  = Math.floor(seconds % 60);
    const ms = Math.round((seconds - Math.floor(seconds)) * 1000);
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}.${String(ms).padStart(3,'0')}`;
}

/**
 * Convierte array de segmentos al formato visual de whisper-cli
 * [HH:MM:SS.mmm --> HH:MM:SS.mmm]   texto
 */
function buildWhisperCliFormat(segments) {
    if (!segments || !segments.length) return '(sin segmentos)';
    return segments.map(seg => {
        const ts = `[${formatSecondsToWhisperTs(seg.start)} --> ${formatSecondsToWhisperTs(seg.end)}]`;
        return `${ts}   ${seg.text || ''}`;
    }).join('\n');
}

// Variable global para mantener la instancia del plugin
let transcriptionPluginInstance = null;

/**
 * Inicializa el reproductor interactivo con resaltado de palabras
 * Usa el TranscriptionHighlightPlugin para mejor rendimiento y configuración
 */
async function initInteractiveTranscription(transcription) {
    const textContainer = document.getElementById('viewTranscriptionText');
    const audioPlayer = document.getElementById('transcriptionAudioPlayer');
    
    if (!textContainer || !audioPlayer || !transcription.segments || !transcription.segments.length) {
        return;
    }
    
    // Limpiar instancia anterior si existe
    if (transcriptionPluginInstance) {
        transcriptionPluginInstance.destroy();
        transcriptionPluginInstance = null;
    }
    
    try {
        // Crear nueva instancia del plugin
        transcriptionPluginInstance = new TranscriptionHighlightPlugin({
            mode: 'modal',
            textContainer: textContainer,
            audioElement: audioPlayer,
            transcription: transcription
        });
        
        // Inicializar el plugin
        await transcriptionPluginInstance.init();
        
        // Limpiar al cambiar de tab
        const tabElement = document.getElementById('tab-text');
        if (tabElement) {
            tabElement.addEventListener('hidden.bs.tab', () => {
                // Pausar audio al cambiar de tab
                if (!audioPlayer.paused) {
                    audioPlayer.pause();
                }
            });
        }
    } catch (error) {
        console.error('Error inicializando plugin de resaltado:', error);
        // Fallback: mostrar texto sin resaltado
        textContainer.innerHTML = escapeHtml(transcription.transcription_text || 'Sin transcripción disponible');
    }
}

/**
 * Mapea palabras del texto a timestamps usando los segments
 * Mejorado para mayor precisión en la sincronización
 */
function mapWordsToTimestamps(fullText, segments) {
    if (!fullText || !segments || !segments.length) {
        return fullText.split(/(\s+)/).map(text => ({ text, timestamp: null }));
    }
    
    // Dividir el texto completo en palabras (preservando espacios)
    const words = fullText.split(/(\s+)/);
    const result = [];
    
    // Normalizar el texto completo para comparación
    const normalizedFullText = fullText.replace(/\s+/g, ' ').trim();
    const fullWords = normalizedFullText.split(/\s+/).filter(w => w.length > 0);
    
    // Construir un mapeo más preciso: procesar cada segmento y mapear sus palabras
    let fullTextWordIdx = 0;
    let currentSegmentIdx = 0;
    
    for (let wordIdx = 0; wordIdx < words.length; wordIdx++) {
        const word = words[wordIdx];
        
        if (!word.trim()) {
            // Es un espacio, agregarlo sin timestamp
            result.push({ text: word, timestamp: null });
            continue;
        }
        
        const wordTrimmed = word.trim();
        let timestamp = null;
        
        // Buscar en qué segmento está esta palabra
        // Usar el índice de palabras en el texto completo para mejor precisión
        if (fullTextWordIdx < fullWords.length && fullWords[fullTextWordIdx] === wordTrimmed) {
            // Buscar el segmento que contiene esta palabra
            let foundInSegment = false;
            
            for (let segIdx = currentSegmentIdx; segIdx < segments.length; segIdx++) {
                const seg = segments[segIdx];
                const segText = (seg.text || '').trim();
                
                if (!segText) continue;
                
                const segWords = segText.split(/\s+/).filter(w => w.length > 0);
                
                // Verificar si esta palabra está en este segmento
                const wordPosInSegment = segWords.findIndex(w => w === wordTrimmed);
                
                if (wordPosInSegment >= 0) {
                    // Calcular timestamp más preciso
                    const totalWords = segWords.length;
                    const segmentDuration = seg.end - seg.start;
                    
                    // Distribuir el tiempo proporcionalmente entre palabras
                    // Primera palabra usa el inicio del segmento
                    // Última palabra usa el final del segmento
                    // Palabras intermedias se distribuyen uniformemente
                    if (totalWords === 1) {
                        timestamp = seg.start;
                    } else if (wordPosInSegment === 0) {
                        // Primera palabra: usar inicio del segmento
                        timestamp = seg.start;
                    } else if (wordPosInSegment === totalWords - 1) {
                        // Última palabra: usar final del segmento
                        timestamp = seg.end;
                    } else {
                        // Palabras intermedias: distribución uniforme
                        const progress = wordPosInSegment / (totalWords - 1);
                        timestamp = seg.start + segmentDuration * progress;
                    }
                    
                    result.push({ text: word, timestamp });
                    currentSegmentIdx = segIdx; // Avanzar al segmento encontrado
                    foundInSegment = true;
                    fullTextWordIdx++;
                    break;
                }
            }
            
            if (!foundInSegment) {
                // Si no se encontró en ningún segmento, usar el inicio del segmento actual
                if (currentSegmentIdx < segments.length) {
                    timestamp = segments[currentSegmentIdx].start;
                    result.push({ text: word, timestamp });
                } else {
                    result.push({ text: word, timestamp: null });
                }
                fullTextWordIdx++;
            }
        } else {
            // Palabra no encontrada en el índice, usar timestamp del segmento actual
            if (currentSegmentIdx < segments.length) {
                timestamp = segments[currentSegmentIdx].start;
                result.push({ text: word, timestamp });
            } else {
                result.push({ text: word, timestamp: null });
            }
        }
    }
    
    return result;
}

/**
 * Descargar audio en formato MP3
 */
async function downloadAudioAsMp3(audioId, audioName) {
    try {
        const token = getAuthToken();
        
        // Mostrar indicador de carga
        showAlert('info', 'Convirtiendo audio a MP3... Esto puede tomar unos momentos.');
        
        // Llamar al endpoint de descarga
        const response = await fetch('api/ai-informes.php?action=download-audio-mp3', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                audio_id: audioId
            })
        });
        
        // Verificar si la respuesta es exitosa
        if (!response.ok) {
            let errorMessage = 'Error al descargar audio';
            try {
                const errorResult = await response.json();
                errorMessage = errorResult.message || errorResult.error || errorMessage;
            } catch (e) {
                errorMessage = `Error ${response.status}: ${response.statusText}`;
            }
            throw new Error(errorMessage);
        }
        
        // Obtener el blob del archivo MP3
        const blob = await response.blob();
        
        // Crear URL temporal para descarga
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        
        // Generar nombre de archivo
        const fileName = audioName ? 
            (audioName.endsWith('.mp3') ? audioName : audioName.replace(/\.[^.]+$/, '') + '.mp3') : 
            `audio_${audioId}.mp3`;
        a.download = fileName;
        
        // Trigger descarga
        document.body.appendChild(a);
        a.click();
        
        // Limpiar
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        showAlert('success', 'Audio descargado exitosamente en formato MP3');
        
    } catch (error) {
        console.error('Error descargando audio:', error);
        showAlert('error', 'Error al descargar audio: ' + error.message);
    }
}

/**
 * Copiar transcripción al portapapeles
 */
function copyTranscriptionToClipboard(transcriptionId) {
    // Obtener el texto de la transcripción del modal
    const textDiv = document.getElementById('viewTranscriptionText');
    if (!textDiv) {
        showAlert('error', 'No se pudo encontrar el texto de la transcripción');
        return;
    }
    
    const text = textDiv.textContent || textDiv.innerText;
    
    // Copiar al portapapeles
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showAlert('success', 'Transcripción copiada al portapapeles');
        }).catch(err => {
            console.error('Error copiando al portapapeles:', err);
            showAlert('error', 'Error al copiar al portapapeles');
        });
    } else {
        // Fallback para navegadores antiguos
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showAlert('success', 'Transcripción copiada al portapapeles');
        } catch (err) {
            console.error('Error copiando al portapapeles:', err);
            showAlert('error', 'Error al copiar al portapapeles');
        }
        document.body.removeChild(textarea);
    }
}

/**
 * Ver informe
 */
function viewReport(reportId) {
    // TODO: Implementar modal para ver informe
    showAlert('info', 'Función de visualización de informe en desarrollo');
}

/**
 * Seleccionar/deseleccionar todos los audios
 */
window.toggleSelectAllAudios = function(checkbox) {
    const checkboxes = document.querySelectorAll('.audio-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateTranscribeSelectedButton();
}

/**
 * Actualizar botón de transcribir seleccionados
 */
window.updateTranscribeSelectedButton = function() {
    const checkboxes = document.querySelectorAll('.audio-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountSpan = document.getElementById('selectedCount');
    const transcribeSelectedBtn = document.getElementById('transcribeSelectedBtn');
    
    if (selectedCountSpan) {
        selectedCountSpan.textContent = count;
    }
    
    if (transcribeSelectedBtn) {
        // Deshabilitar si no hay audios seleccionados O si el usuario no tiene permisos
        const hasPermission = typeof modalHasTranscribePermission !== 'undefined' ? modalHasTranscribePermission : true;
        transcribeSelectedBtn.disabled = count === 0 || !hasPermission;
        
        // Actualizar tooltip si no tiene permisos
        if (!hasPermission) {
            transcribeSelectedBtn.setAttribute('title', 'No tienes permisos para transcribir con AI. Contacta al administrador.');
        } else if (count === 0) {
            transcribeSelectedBtn.setAttribute('title', 'Selecciona al menos un audio para transcribir');
        } else {
            transcribeSelectedBtn.setAttribute('title', `Transcribir ${count} audio${count > 1 ? 's' : ''} seleccionado${count > 1 ? 's' : ''}`);
        }
    }
    
    // Actualizar checkbox "Seleccionar todos"
    const selectAllCheckbox = document.getElementById('selectAllAudios');
    if (selectAllCheckbox) {
        const totalCheckboxes = document.querySelectorAll('.audio-checkbox').length;
        selectAllCheckbox.checked = totalCheckboxes > 0 && count === totalCheckboxes;
        selectAllCheckbox.indeterminate = count > 0 && count < totalCheckboxes;
    }
}

/**
 * Transcribir audios seleccionados
 */
window.transcribeSelectedAudios = async function() {
    // Verificar permiso
    if (window.simplePermissionManager) {
        const permissionCheck = await window.simplePermissionManager.checkPermission('transcribir_ai');
        if (!permissionCheck.hasPermission) {
            showAlert('error', 'No tienes permisos para transcribir audios con AI. Contacta al administrador para habilitar el permiso "Transcribir con AI" en tu cuenta.');
            return;
        }
    }
    
    const checkboxes = document.querySelectorAll('.audio-checkbox:checked');
    if (checkboxes.length === 0) {
        showAlert('warning', 'No hay audios seleccionados');
        return;
    }
    
    const audioIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    // Confirmar
    showConfirmModal(
        'Transcribir Múltiples Audios',
        `¿Transcribir ${audioIds.length} audio${audioIds.length > 1 ? 's' : ''}? Los audios se agregarán a la cola de transcripción y se procesarán automáticamente.`,
        async () => {
            try {
                showAlert('info', `Agregando ${audioIds.length} audio${audioIds.length > 1 ? 's' : ''} a la cola de transcripción...`);
                
                const token = getAuthToken();
                const response = await fetch('api/ai-informes.php?action=queue-batch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({
                        audio_ids: audioIds
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', `Se agregaron ${result.added} audio${result.added > 1 ? 's' : ''} a la cola. ${result.skipped > 0 ? `${result.skipped} omitido${result.skipped > 1 ? 's' : ''}.` : ''}`);
                    
                    // Deseleccionar todos
                    checkboxes.forEach(cb => cb.checked = false);
                    updateTranscribeSelectedButton();
                    
                    // Actualizar estado de la cola
                    updateQueueStatus();
                } else {
                    throw new Error(result.message || 'Error al agregar audios a la cola');
                }
            } catch (error) {
                console.error('Error transcribiendo audios seleccionados:', error);
                showAlert('error', 'Error al transcribir audios: ' + error.message);
            }
        }
    );
}

/**
 * Polling para actualizar estado de la cola
 */
let queueStatusInterval = null;

function startQueueStatusPolling() {
    // Limpiar intervalo anterior si existe
    if (queueStatusInterval) {
        clearInterval(queueStatusInterval);
    }
    
    // Actualizar inmediatamente
    updateQueueStatus();
    
    // Actualizar cada 5 segundos
    queueStatusInterval = setInterval(() => {
        updateQueueStatus();
    }, 5000);
    
    // Limpiar al salir de la página
    $(window).on('beforeunload', () => {
        if (queueStatusInterval) {
            clearInterval(queueStatusInterval);
        }
    });
}

// Variable para rastrear las últimas transcripciones completadas
let lastCompletedTranscriptions = new Set();
// Variables para rastrear el modal de audios abierto
let currentModalStudyId = null;
let currentModalOrthancStudyId = null;
// Variable para almacenar permisos del usuario en el contexto del modal
let modalHasTranscribePermission = true;

/**
 * Actualizar estado de la cola
 */
async function updateQueueStatus() {
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes.php?action=queue-status', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        
        const result = await response.json();
        
        if (result.success && result.queue) {
            const queue = result.queue;
            
            // Actualizar indicador visual si existe
            const queueIndicator = document.getElementById('queueStatusIndicator');
            if (queueIndicator) {
                if (queue.pending > 0 || queue.processing > 0) {
                    queueIndicator.innerHTML = `
                        <span class="badge bg-info">
                            <i class="fas fa-tasks"></i> Cola: ${queue.pending} pendiente${queue.pending > 1 ? 's' : ''}, ${queue.processing} procesando
                        </span>
                    `;
                    queueIndicator.style.display = 'inline-block';
                } else {
                    queueIndicator.style.display = 'none';
                }
            }
            
            // Verificar si hay transcripciones recientemente completadas
            if (result.recent_completed && result.recent_completed.length > 0) {
                const newCompleted = result.recent_completed.filter(t => {
                    const key = `${t.audio_id}_${t.transcription_id}`;
                    return !lastCompletedTranscriptions.has(key);
                });
                
                if (newCompleted.length > 0) {
                    console.log('Nuevas transcripciones completadas detectadas:', newCompleted);
                    
                    // Agregar a la lista de completadas
                    newCompleted.forEach(t => {
                        const key = `${t.audio_id}_${t.transcription_id}`;
                        lastCompletedTranscriptions.add(key);
                    });
                    
                    // Limpiar transcripciones antiguas (mantener solo las últimas 50)
                    if (lastCompletedTranscriptions.size > 50) {
                        const entries = Array.from(lastCompletedTranscriptions);
                        lastCompletedTranscriptions = new Set(entries.slice(-50));
                    }
                    
                    // Actualizar interfaz
                    const audiosModal = document.getElementById('audiosModal');
                    if (audiosModal && audiosModal.classList.contains('show')) {
                        // Si el modal está abierto, recargar los audios del modal
                        console.log('Modal de audios abierto, recargando audios...');
                        // Recargar el modal con los mismos parámetros
                        if (currentModalStudyId !== null || currentModalOrthancStudyId !== null) {
                            const paciente = audiosModal.querySelector('.modal-title')?.textContent?.split('(')[0]?.trim() || null;
                            // Cerrar y volver a abrir el modal con los datos actualizados
                            const modalInstance = bootstrap.Modal.getInstance(audiosModal);
                            if (modalInstance) {
                                modalInstance.hide();
                                // Esperar a que el modal se cierre y luego recargarlo
                                setTimeout(() => {
                                    showAudiosModal(currentModalStudyId, currentModalOrthancStudyId, paciente);
                                }, 300);
                            }
                        } else {
                            // Si no tenemos los parámetros, solo refrescar la tabla principal
                            refreshAudiosTable();
                        }
                    } else {
                        // Si el modal no está abierto, actualizar la tabla principal
                        refreshAudiosTable();
                    }
                }
            }
        }
    } catch (error) {
        console.error('Error actualizando estado de cola:', error);
    }
}

/**
 * Refrescar tabla de audios
 */
function refreshAudiosTable() {
    if (estudiosTable) {
        console.log('Refrescando tabla de audios...');
        estudiosTable.ajax.reload(null, false); // false = mantener página actual
    }
}

/**
 * Limpiar transcripción bloqueada (desde el modal de audios)
 */
async function cleanupBlockedTranscriptionFromList(audioId) {
    const confirmed = confirm(`¿Deseas limpiar las transcripciones bloqueadas para el audio ID ${audioId}?\n\nEsto marcará las transcripciones en estado "processing" como "failed".`);
    
    if (!confirmed) {
        return;
    }
    
    await performCleanupBlockedTranscription(audioId);
}

/**
 * Limpiar transcripción bloqueada (desde Modo Prueba)
 */
/**
 * Mostrar modal con lista de transcripciones bloqueadas
 */
async function showBlockedTranscriptionsModal() {
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes.php?action=list-blocked-transcriptions', {
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });
        
        if (!response.ok) {
            throw new Error('Error al obtener transcripciones bloqueadas');
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Error al obtener transcripciones bloqueadas');
        }
        
        const blocked = result.blocked_transcriptions || [];
        
        // Crear modal
        let tableRows = '';
        if (blocked.length === 0) {
            tableRows = '<tr><td colspan="7" class="text-center text-muted">No hay transcripciones bloqueadas</td></tr>';
        } else {
            blocked.forEach(item => {
                const paciente = item.paciente_nombre && item.paciente_apellido 
                    ? `${item.paciente_nombre} ${item.paciente_apellido}` 
                    : 'N/A';
                const modalidad = item.modalidad || 'N/A';
                const minutesBlocked = item.minutes_blocked || 0;
                const hasQueue = item.queue_id ? 'Sí' : 'No';
                
                tableRows += `
                    <tr>
                        <td><input type="checkbox" class="form-check-input blocked-checkbox" value="${item.transcription_id}" data-audio-id="${item.audio_id}"></td>
                        <td>${item.transcription_id}</td>
                        <td>${item.audio_id}</td>
                        <td>${paciente}</td>
                        <td>${modalidad}</td>
                        <td><span class="badge bg-danger">${minutesBlocked} min</span></td>
                        <td>${hasQueue}</td>
                    </tr>
                `;
            });
        }
        
        const modalHtml = `
            <div class="modal fade" id="blockedTranscriptionsModal" tabindex="-1">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-exclamation-triangle me-2"></i>Transcripciones Bloqueadas
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle me-2"></i>
                                Se muestran transcripciones que llevan más de 3 minutos en estado "processing" sin actualizarse.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th width="40">
                                                <input type="checkbox" class="form-check-input" id="selectAllBlocked" onchange="toggleAllBlocked(this)">
                                            </th>
                                            <th>ID Transcripción</th>
                                            <th>ID Audio</th>
                                            <th>Paciente</th>
                                            <th>Modalidad</th>
                                            <th>Tiempo Bloqueado</th>
                                            <th>En Cola</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${tableRows}
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3">
                                <strong>Total:</strong> ${blocked.length} transcripción(es) bloqueada(s)
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-danger" onclick="cleanupSelectedBlockedTranscriptions()" ${blocked.length === 0 ? 'disabled' : ''}>
                                <i class="fas fa-broom me-1"></i> Limpiar Seleccionadas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal anterior si existe
        const existingModal = document.getElementById('blockedTranscriptionsModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('blockedTranscriptionsModal'));
        modal.show();
        
        // Limpiar modal al cerrar
        document.getElementById('blockedTranscriptionsModal').addEventListener('hidden.bs.modal', function() {
            this.remove();
        });
        
    } catch (error) {
        console.error('Error obteniendo transcripciones bloqueadas:', error);
        showAlert('error', 'Error al obtener transcripciones bloqueadas: ' + error.message);
    }
}

/**
 * Toggle seleccionar todas las transcripciones bloqueadas
 */
function toggleAllBlocked(checkbox) {
    const checkboxes = document.querySelectorAll('.blocked-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
}

/**
 * Limpiar transcripciones bloqueadas seleccionadas
 */
async function cleanupSelectedBlockedTranscriptions() {
    const checkboxes = document.querySelectorAll('.blocked-checkbox:checked');
    
    if (checkboxes.length === 0) {
        showAlert('warning', 'Selecciona al menos una transcripción para limpiar');
        return;
    }
    
    const transcriptionIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    if (!confirm(`¿Estás seguro de limpiar ${transcriptionIds.length} transcripción(es) bloqueada(s)? Esto las marcará como fallidas y las eliminará de la cola.`)) {
        return;
    }
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes.php?action=cleanup-blocked-transcriptions', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                transcription_ids: transcriptionIds
            })
        });
        
        if (!response.ok) {
            throw new Error('Error al limpiar transcripciones bloqueadas');
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Error al limpiar transcripciones bloqueadas');
        }
        
        showAlert('success', result.message || `Se limpiaron ${result.cleaned_transcriptions} transcripción(es) bloqueada(s)`);
        
        // Cerrar modal y recargar datos
        const modal = bootstrap.Modal.getInstance(document.getElementById('blockedTranscriptionsModal'));
        if (modal) {
            modal.hide();
        }
        
        // Recargar tabla de estudios si existe
        if (typeof loadEstudios === 'function') {
            loadEstudios();
        }
        
    } catch (error) {
        console.error('Error limpiando transcripciones bloqueadas:', error);
        showAlert('error', 'Error al limpiar transcripciones bloqueadas: ' + error.message);
    }
}

async function cleanupBlockedTranscription() {
    const audioIdInput = document.getElementById('cleanupAudioId');
    const audioId = audioIdInput?.value?.trim();
    
    if (!audioId || isNaN(audioId) || parseInt(audioId) <= 0) {
        showAlert('error', 'Por favor, ingresa un ID de audio válido');
        if (audioIdInput) {
            audioIdInput.focus();
        }
        return;
    }
    
    const confirmed = confirm(`¿Deseas limpiar las transcripciones bloqueadas para el audio ID ${audioId}?\n\nEsto marcará las transcripciones en estado "processing" como "failed".`);
    
    if (!confirmed) {
        return;
    }
    
    await performCleanupBlockedTranscription(audioId);
    
    // Limpiar el input
    if (audioIdInput) {
        audioIdInput.value = '';
    }
}

/**
 * Función común para limpiar transcripción bloqueada
 */
async function performCleanupBlockedTranscription(audioId) {
    try {
        // Mostrar mensaje de procesamiento
        showAlert('info', 'Limpiando transcripción bloqueada...');
        
        const response = await fetch(`limpiar-transcripcion-bloqueada.php?audio_id=${audioId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'text/html'
            }
        });
        
        const text = await response.text();
        
        // Crear un modal para mostrar el resultado
        const resultModal = document.createElement('div');
        resultModal.className = 'modal fade';
        resultModal.id = 'cleanupResultModal';
        resultModal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="fas fa-broom me-2"></i>Resultado de Limpieza - Audio ID ${audioId}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div style="white-space: pre-wrap; font-family: monospace; font-size: 0.9em;">${text}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        <button type="button" class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync me-1"></i> Recargar Página
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(resultModal);
        const modal = new bootstrap.Modal(resultModal);
        modal.show();
        
        // Remover el modal del DOM cuando se cierre
        resultModal.addEventListener('hidden.bs.modal', () => {
            resultModal.remove();
        });
        
    } catch (error) {
        console.error('Error limpiando transcripción bloqueada:', error);
        showAlert('error', 'Error al limpiar transcripción bloqueada: ' + error.message);
    }
}

// ===== CONFIGURACIÓN RÁPIDA DE RESALTADO DE TRANSCRIPCIÓN =====

let transcriptionHighlightConfigUI = null; // Instancia del modal/offcanvas
let transcriptionHighlightPreviewConfig = null; // Configuración temporal para preview

/**
 * Abrir modal/offcanvas de configuración rápida de resaltado
 */
async function openTranscriptionHighlightConfig() {
    try {
        // Cargar configuración actual
        const response = await fetch('api/ai-informes-config.php');
        const data = await response.json();
        
        if (!data.success || !data.config) {
            showAlert('error', 'No se pudo cargar la configuración');
            return;
        }
        
        const config = data.config;
        const uiType = config.transcription_highlight_config_ui_type || 'modal';
        const enablePreview = config.transcription_highlight_enable_preview !== false;
        
        // Remover UI anterior si existe
        if (transcriptionHighlightConfigUI) {
            transcriptionHighlightConfigUI.remove();
            transcriptionHighlightConfigUI = null;
        }
        
        // Crear modal u offcanvas según configuración
        if (uiType === 'offcanvas') {
            createTranscriptionHighlightOffcanvas(config, enablePreview);
        } else {
            createTranscriptionHighlightModal(config, enablePreview);
        }
        
    } catch (error) {
        console.error('Error abriendo configuración de resaltado:', error);
        showAlert('error', 'Error al abrir configuración: ' + error.message);
    }
}

/**
 * Crear modal de configuración rápida
 */
function createTranscriptionHighlightModal(config, enablePreview) {
    const modalId = 'transcriptionHighlightConfigModal';
    const modalHtml = `
        <div class="modal fade" id="${modalId}" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="fas fa-highlighter me-2"></i>Configurar Resaltado de Transcripción
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="transcriptionHighlightConfigModalBody" style="max-height: 70vh; overflow-y: auto;">
                        <!-- Los campos se renderizarán aquí -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        ${enablePreview ? '<button type="button" class="btn btn-info" onclick="applyTranscriptionHighlightPreview()"><i class="fas fa-eye me-1"></i> Aplicar (Preview)</button>' : ''}
                        <button type="button" class="btn btn-primary" onclick="saveTranscriptionHighlightConfig()">
                            <i class="fas fa-save me-1"></i> Guardar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    transcriptionHighlightConfigUI = document.getElementById(modalId);
    
    // Renderizar campos usando el componente
    if (typeof TranscriptionHighlightConfig !== 'undefined') {
        TranscriptionHighlightConfig.render(config, 'transcriptionHighlightConfigModalBody', {
            showTitle: false,
            compactMode: true
        });
    }
    
    // Mostrar modal
    const modal = new bootstrap.Modal(transcriptionHighlightConfigUI);
    modal.show();
    
    // Limpiar al cerrar
    transcriptionHighlightConfigUI.addEventListener('hidden.bs.modal', function() {
        this.remove();
        transcriptionHighlightConfigUI = null;
        transcriptionHighlightPreviewConfig = null;
    });
}

/**
 * Crear offcanvas de configuración rápida
 */
function createTranscriptionHighlightOffcanvas(config, enablePreview) {
    const offcanvasId = 'transcriptionHighlightConfigOffcanvas';
    const offcanvasHtml = `
        <div class="offcanvas offcanvas-end" tabindex="-1" id="${offcanvasId}">
            <div class="offcanvas-header bg-warning text-dark">
                <h5 class="offcanvas-title">
                    <i class="fas fa-highlighter me-2"></i>Configurar Resaltado
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
            </div>
            <div class="offcanvas-body" id="transcriptionHighlightConfigOffcanvasBody" style="overflow-y: auto;">
                <!-- Los campos se renderizarán aquí -->
            </div>
            <div class="offcanvas-footer border-top p-3">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="offcanvas">Cerrar</button>
                ${enablePreview ? '<button type="button" class="btn btn-info" onclick="applyTranscriptionHighlightPreview()"><i class="fas fa-eye me-1"></i> Aplicar (Preview)</button>' : ''}
                <button type="button" class="btn btn-primary" onclick="saveTranscriptionHighlightConfig()">
                    <i class="fas fa-save me-1"></i> Guardar
                </button>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', offcanvasHtml);
    transcriptionHighlightConfigUI = document.getElementById(offcanvasId);
    
    // Renderizar campos usando el componente
    if (typeof TranscriptionHighlightConfig !== 'undefined') {
        TranscriptionHighlightConfig.render(config, 'transcriptionHighlightConfigOffcanvasBody', {
            showTitle: false,
            compactMode: true
        });
    }
    
    // Mostrar offcanvas
    const offcanvas = new bootstrap.Offcanvas(transcriptionHighlightConfigUI);
    offcanvas.show();
    
    // Limpiar al cerrar
    transcriptionHighlightConfigUI.addEventListener('hidden.bs.offcanvas', function() {
        this.remove();
        transcriptionHighlightConfigUI = null;
        transcriptionHighlightPreviewConfig = null;
    });
}

/**
 * Aplicar preview de configuración (sin guardar)
 */
async function applyTranscriptionHighlightPreview() {
    try {
        if (typeof TranscriptionHighlightConfig === 'undefined') {
            showAlert('error', 'Componente de configuración no disponible');
            return;
        }
        
        // Validar valores
        const validation = TranscriptionHighlightConfig.validate();
        if (!validation.valid) {
            showAlert('error', 'Errores de validación:\n' + validation.errors.join('\n'));
            return;
        }
        
        // Obtener valores
        const values = TranscriptionHighlightConfig.getValues();
        transcriptionHighlightPreviewConfig = values;
        
        // Aplicar al plugin si está activo
        if (transcriptionPluginInstance) {
            // Actualizar configuración temporalmente
            transcriptionPluginInstance.config = values;
            transcriptionPluginInstance.applyConfig();
            transcriptionPluginInstance.applyCustomStyles();
        }
        
        showAlert('success', 'Preview aplicado. Los cambios son temporales hasta que guardes.');
        
    } catch (error) {
        console.error('Error aplicando preview:', error);
        showAlert('error', 'Error al aplicar preview: ' + error.message);
    }
}

/**
 * Guardar configuración de resaltado
 */
async function saveTranscriptionHighlightConfig() {
    try {
        if (typeof TranscriptionHighlightConfig === 'undefined') {
            showAlert('error', 'Componente de configuración no disponible');
            return;
        }
        
        // Validar valores
        const validation = TranscriptionHighlightConfig.validate();
        if (!validation.valid) {
            showAlert('error', 'Errores de validación:\n' + validation.errors.join('\n'));
            return;
        }
        
        // Obtener valores
        const values = TranscriptionHighlightConfig.getValues();
        
        // Obtener token de autenticación
        const token = getAuthToken();
        
        // Cargar configuración completa primero
        const getResponse = await fetch('api/ai-informes-config.php', {
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });
        const getData = await getResponse.json();
        
        if (!getData.success) {
            throw new Error('No se pudo cargar la configuración actual');
        }
        
        // Combinar con configuración existente
        const fullConfig = {
            ...getData.config,
            ...values
        };
        
        // Guardar
        const response = await fetch('api/ai-informes-config.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(fullConfig)
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Error al guardar configuración');
        }
        
        // Recargar configuración en el plugin
        if (transcriptionPluginInstance) {
            await transcriptionPluginInstance.reloadConfig();
        }
        
        // Cerrar modal/offcanvas
        if (transcriptionHighlightConfigUI) {
            const bsInstance = bootstrap.Modal.getInstance(transcriptionHighlightConfigUI) || 
                             bootstrap.Offcanvas.getInstance(transcriptionHighlightConfigUI);
            if (bsInstance) {
                bsInstance.hide();
            }
        }
        
        showAlert('success', 'Configuración guardada exitosamente');
        transcriptionHighlightPreviewConfig = null;
        
    } catch (error) {
        console.error('Error guardando configuración:', error);
        showAlert('error', 'Error al guardar configuración: ' + error.message);
    }
}
