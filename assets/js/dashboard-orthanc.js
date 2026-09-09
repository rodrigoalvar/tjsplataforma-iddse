/**
 * Integración de Orthanc con el Dashboard Profesional
 * Maneja la carga y visualización de estudios desde Orthanc
 */
class DashboardOrthanc {
    constructor() {
        // Detectar si estamos en components/ y ajustar la URL base
        this.apiBaseUrl = window.location.pathname.includes('/components/') ? '../api/' : 'api/';
        this.studies = []; // Caché de todos los estudios del servidor
        this.filteredStudies = [];
        this.isDataLoaded = false; // Flag para saber si ya se cargaron los datos
        this.storageKey = 'dashboard_orthanc_state'; // Clave para localStorage
        
        this.currentFilters = {
            search: '',
            dateFrom: '', // Vacío por defecto - el usuario debe especificar fechas
            dateTo: '',   // Vacío por defecto - el usuario debe especificar fechas
            patientId: '',
            modalities: [] // Array para selección múltiple de modalidades
        };
    }
    
    /**
     * Inicializa el dashboard
     */
    async init() {
        try {
            this.setupEventListeners();
            
            // Configurar listener para cambios de estado global
            this.setupGlobalStateListener();
            
            // Intentar cargar estado persistente
            const persistentState = this.loadPersistentState();
            if (persistentState && persistentState.studies && persistentState.studies.length > 0) {
                // Restaurar estado desde localStorage
                this.studies = persistentState.studies;
                this.currentFilters = { ...this.currentFilters, ...persistentState.filters };
                this.isDataLoaded = true;
                
                // Restaurar valores en los campos del formulario
                this.restoreFormValues();
                
                // Aplicar filtros locales y renderizar
                this.applyLocalFilters();
                this.updateCacheStatus('Datos restaurados desde caché local', 'text-success');
                
                console.log('Estado restaurado desde localStorage:', this.studies.length, 'estudios');
            } else {
                // Estado inicial vacío
                this.renderEmptyState();
                this.updateCacheStatus('Listo para buscar - presiona "Buscar" para consultar PACS', 'text-info');
            }
            
            // Sincronizar con el estado global
            this.syncWithGlobalState();
        } catch (error) {
            console.error('Error inicializando dashboard:', error);
            this.showError('Error inicializando dashboard');
        }
    }
    
    /**
     * Carga todos los estudios desde Orthanc (solo fechas, patientId - sin filtro de modalidad)
     */
    async loadStudies() {
        try {
            // Limpiar caché antes de cargar nuevos datos
            this.clearPersistentState();
            
            // Construir URL con parámetros de filtro (sin modalidad para obtener todos los estudios)
            const params = new URLSearchParams();
            
            if (this.currentFilters.dateFrom) {
                params.append('dateFrom', this.currentFilters.dateFrom);
            }
            if (this.currentFilters.dateTo) {
                params.append('dateTo', this.currentFilters.dateTo);
            }
            if (this.currentFilters.patientId) {
                params.append('patientId', this.currentFilters.patientId);
            }
            // NO enviamos filtro de modalidad al servidor - se filtra localmente
            
            const url = this.apiBaseUrl + 'get_all_studies.php' + (params.toString() ? '?' + params.toString() : '');
            const response = await fetch(url);
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error);
            }
            
            this.studies = result.data;
            this.isDataLoaded = true;
            
            // Guardar estado en localStorage
            this.savePersistentState();
            
            console.log('Estudios cargados en caché:', this.studies.length);
        } catch (error) {
            console.error('Error cargando estudios:', error);
            throw error;
        }
    }
    
    /**
     * Configura los event listeners
     */
    setupEventListeners() {
        // Filtro de búsqueda general
        const searchFilter = document.getElementById('searchFilter');
        if (searchFilter) {
            searchFilter.addEventListener('input', (e) => {
                this.currentFilters.search = e.target.value;
                this.applyLocalFilters();
            });
        }
        

        
        const dateFrom = document.getElementById('dateFrom');
        if (dateFrom) {
            // No establecer valor por defecto - campos vacíos
            dateFrom.value = this.currentFilters.dateFrom;
            dateFrom.addEventListener('change', (e) => {
                this.currentFilters.dateFrom = e.target.value;
                // Solo actualizar filtro, no recargar - el usuario debe presionar "Buscar"
            });
        }
        
        const dateTo = document.getElementById('dateTo');
        if (dateTo) {
            // No establecer valor por defecto - campos vacíos
            dateTo.value = this.currentFilters.dateTo;
            dateTo.addEventListener('change', (e) => {
                this.currentFilters.dateTo = e.target.value;
                // Solo actualizar filtro, no recargar - el usuario debe presionar "Buscar"
            });
        }
        
        const patientIdInput = document.getElementById('patientId');
        if (patientIdInput) {
            patientIdInput.addEventListener('input', (e) => {
                this.currentFilters.patientId = e.target.value;
                // Solo actualizar filtro, no recargar - el usuario debe presionar "Buscar"
            });
        }
        
        // Botones de modalidad - filtrado local sin consultar servidor con selección múltiple
        document.querySelectorAll('.modality-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modality = e.target.dataset.modality;
                
                if (modality === 'all') {
                    // Si se selecciona "Todas", limpiar todas las selecciones
                    document.querySelectorAll('.modality-btn').forEach(b => b.classList.remove('active'));
                    e.target.classList.add('active');
                    this.currentFilters.modalities = [];
                } else {
                    // Remover "Todas" si está activo
                    const allBtn = document.querySelector('.modality-btn[data-modality="all"]');
                    if (allBtn) allBtn.classList.remove('active');
                    
                    // Toggle de la modalidad seleccionada
                    if (e.target.classList.contains('active')) {
                        e.target.classList.remove('active');
                        this.currentFilters.modalities = this.currentFilters.modalities.filter(m => m !== modality);
                    } else {
                        e.target.classList.add('active');
                        this.currentFilters.modalities.push(modality);
                    }
                    
                    // Si no hay modalidades seleccionadas, activar "Todas"
                    if (this.currentFilters.modalities.length === 0 && allBtn) {
                        allBtn.classList.add('active');
                    }
                }
                
                // Actualizar contador de modalidades seleccionadas
                this.updateModalityCounter();
                
                // Solo aplicar filtros locales, no recargar desde servidor
                this.applyLocalFilters();
            });
        });
        
        // Botón de búsqueda
        const searchBtn = document.getElementById('searchButton');
        if (searchBtn) {
            searchBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleSearchClick();
            });
        }
    }
    
    /**
     * Configura el listener para cambios de estado global
     */
    setupGlobalStateListener() {
        if (window.globalStateManager) {
            window.addEventListener('globalStateChanged', (event) => {
                console.log('Estado global cambiado, actualizando indicadores...');
                this.updateVisualIndicators();
            });
        }
    }
    
    /**
     * Sincroniza con el estado global al cargar
     */
    syncWithGlobalState() {
        if (window.globalStateManager) {
            // Sincronizar el estado global con la persistencia local
            window.globalStateManager.syncWithPersistence();
            
            // Actualizar indicadores visuales
            this.updateVisualIndicators();
        }
    }
    
    /**
     * Actualiza los indicadores visuales de todos los estudios
     */
    updateVisualIndicators() {
        const studyRows = document.querySelectorAll('[data-study-id]');
        studyRows.forEach(row => {
            const studyId = row.getAttribute('data-study-id');
            if (studyId) {
                const reportButton = row.querySelector('.btn-report, .btn-warning');
                if (reportButton) {
                    const isInProgress = this.checkInProgressReport(studyId);
                    this.updateReportButtonState(reportButton, isInProgress);
                }
            }
        });
    }
    
    /**
     * Actualiza el estado visual de un botón de informe
     */
    updateReportButtonState(button, isInProgress) {
        if (isInProgress) {
            button.className = button.className.replace('btn-report', 'btn-warning');
            const icon = button.querySelector('i');
            const span = button.querySelector('span');
            if (icon) icon.className = 'fas fa-edit';
            if (span) span.textContent = 'Continuar';
            button.title = 'Continuar informe en progreso';
        } else {
            button.className = button.className.replace('btn-warning', 'btn-report');
            const icon = button.querySelector('i');
            const span = button.querySelector('span');
            if (icon) icon.className = 'fas fa-file-medical';
            if (span) span.textContent = 'Informe';
            button.title = 'Generar Informe';
        }
    }
    
    /**
     * Actualiza el contador de modalidades seleccionadas
     */
    updateModalityCounter() {
        const counter = document.getElementById('modalityCounter');
        if (counter) {
            const selectedCount = this.currentFilters.modalities ? this.currentFilters.modalities.length : 0;
            if (selectedCount > 0) {
                counter.textContent = `${selectedCount} seleccionada${selectedCount > 1 ? 's' : ''}`;
                counter.style.display = 'inline';
            } else {
                counter.style.display = 'none';
            }
        }
    }

    /**
     * Actualiza los filtros desde los campos del formulario
     */
    updateFiltersFromForm() {
        // Actualizar fechas
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const patientId = document.getElementById('patientId');
        const searchFilter = document.getElementById('searchFilter');
        
        if (dateFrom) {
            this.currentFilters.dateFrom = dateFrom.value;
        }
        
        if (dateTo) {
            this.currentFilters.dateTo = dateTo.value;
        }
        
        if (patientId) {
            this.currentFilters.patientId = patientId.value;
        }
        
        if (searchFilter) {
            this.currentFilters.search = searchFilter.value;
        }
    }
    
    /**
     * Maneja el clic en el botón de búsqueda con spinner
     */
    async handleSearchClick() {
        const searchBtn = document.getElementById('searchButton');
        if (!searchBtn) return;
        
        // Actualizar filtros desde los campos del formulario antes de aplicar
        this.updateFiltersFromForm();
        
        // Guardar contenido original del botón
        const originalContent = searchBtn.innerHTML;
        
        try {
            // Mostrar spinner en el botón
            searchBtn.disabled = true;
            searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Buscando...';
            
            // Aplicar filtros
            await this.applyFilters();
            
        } catch (error) {
            console.error('Error en búsqueda:', error);
            this.showError('Error realizando la búsqueda');
        } finally {
            // Restaurar botón original
            searchBtn.disabled = false;
            searchBtn.innerHTML = originalContent;
        }
    }
    
    /**
     * Aplica los filtros a los estudios (recarga desde servidor)
     * Solo se ejecuta cuando se presiona el botón "Buscar"
     */
    async applyFilters() {
        try {
            // Validar que se especifiquen fechas
            if (!this.currentFilters.dateFrom || !this.currentFilters.dateTo) {
                this.showError('Por favor especifica las fechas "Desde" y "Hasta" para consultar estudios');
                this.updateCacheStatus('Fechas requeridas para consultar PACS', 'text-warning');
                return;
            }
            
            // Validar que la fecha "desde" no sea mayor que "hasta"
            if (this.currentFilters.dateFrom > this.currentFilters.dateTo) {
                this.showError('La fecha "Desde" no puede ser mayor que la fecha "Hasta"');
                this.updateCacheStatus('Rango de fechas inválido', 'text-warning');
                return;
            }
            
            // Actualizar indicador de estado
            this.updateCacheStatus('Consultando PACS...', 'text-warning');
            
            // Mostrar indicador de carga
            const tableBody = document.querySelector('.studies-table tbody');
            if (tableBody) {
                tableBody.innerHTML = '<tr><td colspan="7" class="text-center"><i class="fas fa-spinner fa-spin"></i> Consultando PACS...</td></tr>';
            }
            
            // Recargar estudios desde el servidor (solo con filtros de fecha y paciente)
            await this.loadStudies();
            
            // Aplicar todos los filtros localmente
            this.applyLocalFilters();
            
            // Actualizar indicador de estado
            this.updateCacheStatus('Datos actualizados desde PACS', 'text-success');
            
            // Guardar estado actualizado
            this.savePersistentState();
        } catch (error) {
            console.error('Error aplicando filtros:', error);
            this.showError('Error aplicando filtros');
            this.updateCacheStatus('Error consultando PACS', 'text-danger');
        }
    }
    
    /**
     * Aplica filtros locales sin recargar desde el servidor
     */
    applyLocalFilters() {
        // Actualizar indicador de estado
        this.updateCacheStatus('Usando caché local', 'text-muted');
        
        // Guardar estado actual en localStorage
        this.savePersistentState();
        
        this.filteredStudies = this.studies.filter(study => {
            // Filtro de fecha desde
            if (this.currentFilters.dateFrom) {
                const studyDate = study.date || study.study_date || '';
                if (studyDate < this.currentFilters.dateFrom.replace(/-/g, '')) {
                    return false;
                }
            }
            
            // Filtro de fecha hasta
            if (this.currentFilters.dateTo) {
                const studyDate = study.date || study.study_date || '';
                if (studyDate > this.currentFilters.dateTo.replace(/-/g, '')) {
                    return false;
                }
            }
            
            // Filtro de ID de paciente
            if (this.currentFilters.patientId && this.currentFilters.patientId.trim() !== '') {
                const patientId = (study.patient_id || '').toLowerCase();
                const filterPatientId = this.currentFilters.patientId.toLowerCase();
                if (!patientId.includes(filterPatientId)) {
                    return false;
                }
            }
            
            // Filtro de búsqueda general
            if (this.currentFilters.search && this.currentFilters.search.trim() !== '') {
                const searchTerm = this.currentFilters.search.toLowerCase();
                const searchableFields = [
                    study.patient_name || '',
                    study.patient_id || '',
                    study.modality || '',
                    study.study_description || '',
                    study.date || study.study_date || '',
                    study.time || study.study_time || '',
                    study.accession_number || ''
                ];
                
                const matchesSearch = searchableFields.some(field => 
                    field.toString().toLowerCase().includes(searchTerm)
                );
                
                if (!matchesSearch) {
                    return false;
                }
            }
            
            // Filtro de modalidad local - selección múltiple
            if (this.currentFilters.modalities && this.currentFilters.modalities.length > 0) {
                // Mapear modalidades de interfaz a códigos DICOM para comparación
                const modalityMap = {
                    'RX': 'CR',     // Radiografía Computarizada
                    'DX': 'DX',     // Radiografía Digital
                    'CT': 'CT',     // Tomografía Computarizada
                    'MR': 'MR',     // Resonancia Magnética
                    'US': 'US',     // Ultrasonido
                    'MG': 'MG',     // Mamografía
                    'OT': 'OT',     // Otros
                    'XA': 'XA',     // Angiografía por Rayos X
                    'NM': 'NM',     // Medicina Nuclear
                    'PT': 'PT',     // Tomografía por Emisión de Positrones
                    'RF': 'RF'      // Fluoroscopia por Rayos X
                };
                
                // Verificar si la modalidad del estudio coincide con alguna de las seleccionadas
                const matchesModality = this.currentFilters.modalities.some(selectedModality => {
                    const dicomModality = modalityMap[selectedModality] || selectedModality;
                    return study.modality === dicomModality;
                });
                
                if (!matchesModality) {
                    return false;
                }
            }
            
            return true;
        });
        
        this.renderStudies();
        this.updateStats();
    }
    
    /**
     * Renderiza los estudios en la tabla
     */
    renderStudies() {
        const tbody = document.querySelector('.studies-table tbody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        if (this.filteredStudies.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No se encontraron estudios con los criterios especificados</td></tr>';
            return;
        }
        
        this.filteredStudies.forEach(study => {
            const row = this.createStudyRow(study);
            tbody.appendChild(row);
        });
    }
    
    /**
     * Renderiza el estado inicial vacío
     */
    renderEmptyState() {
        const tbody = document.querySelector('.studies-table tbody');
        if (!tbody) return;
        
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    <i class="fas fa-search fa-2x mb-3"></i>
                    <p class="mb-0">Especifica los criterios de búsqueda y presiona "Buscar" para consultar estudios</p>
                </td>
            </tr>
        `;
        
        // Limpiar estadísticas
        this.updateStats();
    }
    
    /**
     * Crea una fila de la tabla para un estudio
     */
    createStudyRow(study) {
        const row = document.createElement('tr');
        
        // Agregar atributo data-study-id a la fila para identificación
        row.setAttribute('data-study-id', study.id);
        
        // Usar los nombres correctos de las propiedades
        const formattedDate = this.formatDate(study.date || study.study_date);
        const formattedTime = this.formatTime(study.time || study.study_time);
        const modalityBadge = this.getModalityBadge(study.modality);
        
        // Verificar si hay un informe en progreso para este estudio
        const hasInProgressReport = this.checkInProgressReport(study.id);
        const reportButtonClass = hasInProgressReport ? 'btn-warning' : 'btn-report';
        const reportButtonText = hasInProgressReport ? 'Continuar' : 'Informe';
        const reportButtonIcon = hasInProgressReport ? 'fas fa-edit' : 'fas fa-file-medical';
        
        row.innerHTML = `
            <td>${formattedDate}</td>
            <td class="d-none d-md-table-cell">${formattedTime}</td>
            <td>
                <div class="fw-semibold">${study.patient_name}</div>
                <small class="text-muted d-block d-lg-none">ID: ${study.patient_id}</small>
            </td>
            <td class="d-none d-lg-table-cell">${study.patient_id}</td>
            <td class="d-none d-sm-table-cell">${modalityBadge}</td>
            <td class="d-none d-xl-table-cell">${study.study_description}</td>
            <td class="d-none d-lg-table-cell">
                <span class="badge status-badge status-completed">${study.status}</span>
            </td>
            <td>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-action btn-info" title="Información" 
                            onclick="dashboardOrthanc.showStudyInfo('${study.id}')">
                        <i class="fas fa-info-circle"></i>
                    </button>
                    <button class="btn btn-sm btn-action btn-view" title="Ver" onclick="window.open('${study.viewer_url}', '_blank')">
                        <i class="fas fa-eye"></i>
                        <span class="d-none d-sm-inline ms-1">Ver</span>
                    </button>
                    <button class="btn btn-sm btn-action btn-download" title="Descargar" 
                            data-study-id="${study.id}" 
                            data-orthanc-study-id="${study.orthanc_study_id}" 
                            onclick="dashboardOrthanc.downloadStudy('${study.orthanc_study_id}')">
                        <i class="fas fa-download"></i>
                        <span class="d-none d-md-inline ms-1">Descargar</span>
                    </button>
                    <button class="btn btn-sm btn-action ${reportButtonClass}" title="${hasInProgressReport ? 'Continuar Informe en Progreso' : 'Generar Informe'}" 
                            data-study-id="${study.id}" 
                            data-orthanc-study-id="${study.orthanc_study_id}" 
                            onclick="generateReport('${study.patient_id}', '${study.patient_name}', '${study.modality}', '${study.study_description}', '${study.id}', '${study.study_instance_uid}')">
                        <i class="${reportButtonIcon}"></i>
                        <span class="d-none d-lg-inline ms-1">${reportButtonText}</span>
                    </button>
                </div>
            </td>
        `;
        
        return row;
    }
    
    /**
     * Actualiza las estadísticas del dashboard
     */
    updateStats() {
        const today = new Date().toISOString().split('T')[0].replace(/-/g, '');
        const todayStudies = this.studies.filter(study => study.date === today).length;
        
        const thisMonth = new Date();
        const monthStart = new Date(thisMonth.getFullYear(), thisMonth.getMonth(), 1).toISOString().split('T')[0].replace(/-/g, '');
        const monthStudies = this.studies.filter(study => study.date >= monthStart).length;
        
        // Actualizar estadísticas en el DOM
        const statNumbers = document.querySelectorAll('.stat-number');
        const badgeElement = document.querySelector('.badge.bg-primary');
        
        if (statNumbers[0]) statNumbers[0].textContent = todayStudies;
        if (statNumbers[1]) statNumbers[1].textContent = monthStudies;
        if (badgeElement) badgeElement.textContent = `${this.filteredStudies.length} estudios`;
    }
    
    /**
     * Popula el filtro de pacientes con los nombres únicos
     */

    
    /**
     * Formatea una fecha DICOM (YYYYMMDD) a formato legible
     */
    formatDate(dateStr) {
        if (!dateStr || dateStr.length !== 8) return dateStr;
        
        const year = dateStr.substring(0, 4);
        const month = dateStr.substring(4, 6);
        const day = dateStr.substring(6, 8);
        
        return `${day}/${month}/${year}`;
    }
    
    /**
     * Formatea una hora DICOM (HHMMSS) a formato legible
     */
    formatTime(timeStr) {
        if (!timeStr || timeStr.length < 4) return timeStr;
        
        const hours = timeStr.substring(0, 2);
        const minutes = timeStr.substring(2, 4);
        
        return `${hours}:${minutes}`;
    }
    
    /**
     * Obtiene el badge HTML para una modalidad
     */
    getModalityBadge(modality) {
        const modalityClass = modality.toLowerCase().replace(/[^a-z]/g, '');
        return `<span class="badge modality-badge modality-${modalityClass}">${modality}</span>`;
    }
    
    /**
     * Muestra información detallada del estudio
     */
    showStudyInfo(studyId) {
        const study = this.studies.find(s => s.id === studyId);
        if (!study) {
            this.showError('Estudio no encontrado');
            return;
        }

        // Formatear fecha de nacimiento
        const formatBirthDate = (dateStr) => {
            if (!dateStr || dateStr.length !== 8) return 'No disponible';
            return `${dateStr.substring(6,8)}/${dateStr.substring(4,6)}/${dateStr.substring(0,4)}`;
        };

        // Formatear sexo
        const formatSex = (sex) => {
            const sexMap = { 'M': 'Masculino', 'F': 'Femenino', 'O': 'Otro' };
            return sexMap[sex] || sex || 'No especificado';
        };

        const modalHtml = `
            <div class="modal fade" id="studyInfoModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-info-circle text-primary me-2"></i>
                                Información del Estudio
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-user me-2"></i>Información del Paciente
                                    </h6>
                                    <div class="mb-2">
                                        <strong>Nombre:</strong> ${study.patient_name || 'No disponible'}
                                    </div>
                                    <div class="mb-2">
                                        <strong>ID:</strong> ${study.patient_id || 'No disponible'}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Fecha de nacimiento:</strong> ${formatBirthDate(study.patient_birth_date)}
                                    </div>
                                    <div class="mb-3">
                                        <strong>Sexo:</strong> ${formatSex(study.patient_sex)}
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-file-medical me-2"></i>Detalles del Estudio
                                    </h6>
                                    <div class="mb-2">
                                        <strong>Fecha:</strong> ${this.formatDate(study.date)}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Hora:</strong> ${this.formatTime(study.time)}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Modalidad:</strong> ${study.modality || 'No disponible'}
                                    </div>
                                    <div class="mb-3">
                                        <strong>Descripción:</strong> ${study.study_description || 'No disponible'}
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-clipboard-list me-2"></i>Información Clínica
                                    </h6>
                                    <div class="mb-2">
                                        <strong>Número de acceso:</strong> ${study.accession_number || 'No disponible'}
                                    </div>
                                    <div class="mb-3">
                                        <strong>Médico referente:</strong> ${study.referring_physician || 'No disponible'}
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-chart-bar me-2"></i>Estadísticas Técnicas
                                    </h6>
                                    <div class="mb-2">
                                        <strong>Número de series:</strong> ${study.series_count || 0}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Número de imágenes:</strong> ${study.instances_count || 0}
                                    </div>
                                    <div class="mb-2">
                                          <strong>ID TJS PACS:</strong> 
                                          <code class="small">${study.orthanc_study_id || study.id}</code>
                                      </div>
                                      <div class="mb-2">
                                          <strong>Study UID:</strong> 
                                          <code class="small">${study.study_instance_uid || 'N/A'}</code>
                                      </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-primary" onclick="window.open('${study.viewer_url}', '_blank')">
                                <i class="fas fa-eye me-2"></i>Ver Estudio
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Remover modal existente si existe
        const existingModal = document.getElementById('studyInfoModal');
        if (existingModal) {
            existingModal.remove();
        }

        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('studyInfoModal'));
        modal.show();

        // Limpiar modal cuando se cierre
        document.getElementById('studyInfoModal').addEventListener('hidden.bs.modal', function() {
            this.remove();
        });
    }

    /**
     * Construye el nombre del archivo para descarga en formato: idpaciente-nombrepaciente-fechaestudio-descripcion.zip
     */
    buildDownloadFilename(study) {
        const patientId = (study.patient_id || '').trim();
        const patientName = (study.patient_name || '').trim();
        const studyDate = study.date || study.study_date || '';
        const studyDescription = (study.study_description || '').trim();
        
        // Formatear fecha: convertir a formato YYYYMMDD
        let formattedDate = '';
        if (studyDate) {
            // Si la fecha viene en formato YYYY-MM-DD o YYYYMMDD
            formattedDate = studyDate.replace(/-/g, '').substring(0, 8);
        }
        
        // Construir partes del filename
        const parts = [];
        if (patientId) parts.push(patientId);
        if (patientName) parts.push(patientName);
        if (formattedDate) parts.push(formattedDate);
        if (studyDescription) parts.push(studyDescription);
        
        // Si no hay información, usar fallback
        if (parts.length === 0) {
            return `study_${study.orthanc_study_id || study.id || 'unknown'}.zip`;
        }
        
        return `${parts.join('-')}.zip`;
    }

    /**
     * Descarga un estudio desde Orthanc
     */
    async downloadStudy(orthancStudyId) {
        try {
            console.log('Iniciando descarga del estudio:', orthancStudyId);
            
            // Mostrar indicador de carga
            const downloadBtn = document.querySelector(`button[onclick*="${orthancStudyId}"]`);
            if (downloadBtn) {
                const originalHTML = downloadBtn.innerHTML;
                downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span class="d-none d-md-inline ms-1">Descargando...</span>';
                downloadBtn.disabled = true;
                
                // Restaurar botón después de un tiempo
                setTimeout(() => {
                    downloadBtn.innerHTML = originalHTML;
                    downloadBtn.disabled = false;
                }, 3000);
            }
            
            // Buscar el estudio en el array de estudios para obtener información
            const study = this.studies.find(s => 
                (s.orthanc_study_id && s.orthanc_study_id === orthancStudyId) || 
                (s.id && s.id === orthancStudyId)
            );
            
            // Construir nombre del archivo
            let filename = `study_${orthancStudyId}.zip`;
            if (study) {
                filename = this.buildDownloadFilename(study);
            }
            
            // Crear URL de descarga directa desde Orthanc
            const orthancBaseUrl = 'https://demoportal.tanjousoft.com.ar/visorweb'; // URL del servidor Orthanc
            const downloadUrl = `${orthancBaseUrl}/studies/${orthancStudyId}/archive?filename=${encodeURIComponent(filename)}`;
            
            // Crear enlace temporal para descarga
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            link.target = '_blank';
            
            // Ejecutar descarga
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            console.log('Descarga iniciada para estudio:', orthancStudyId);
            
        } catch (error) {
            console.error('Error descargando estudio:', error);
            alert('Error al descargar el estudio. Verifique la conexión con Orthanc.');
        }
    }
    
    /**
     * Actualiza el indicador de estado del caché
     */
    updateCacheStatus(message, className = 'text-muted') {
        const cacheStatus = document.getElementById('cacheStatus');
        if (cacheStatus) {
            const icon = className === 'text-warning' ? 'fa-spinner fa-spin' : 
                        className === 'text-success' ? 'fa-check-circle' :
                        className === 'text-danger' ? 'fa-exclamation-triangle' :
                        'fa-database';
            
            cacheStatus.innerHTML = `<i class="fas ${icon} me-1"></i>${message}`;
            cacheStatus.className = `${className}`;
            
            // Si es éxito, volver al estado normal después de 3 segundos
            if (className === 'text-success') {
                setTimeout(() => {
                    this.updateCacheStatus('Usando caché local', 'text-muted');
                }, 3000);
            }
        }
    }
    
    /**
     * Muestra un error en el dashboard
     */
    showError(message) {
        const container = document.querySelector('.main-content .container-fluid');
        if (container) {
            container.innerHTML = `
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ${message}
                    <br><br>
                    <small>Verifique que el servidor Orthanc esté ejecutándose y la configuración sea correcta.</small>
                </div>
            `;
        }
    }
    
    /**
     * Guarda el estado actual en localStorage
     */
    savePersistentState() {
        try {
            const state = {
                studies: this.studies,
                filters: this.currentFilters,
                isDataLoaded: this.isDataLoaded,
                timestamp: Date.now()
            };
            localStorage.setItem(this.storageKey, JSON.stringify(state));
            console.log('Estado guardado en localStorage');
        } catch (error) {
            console.error('Error guardando estado en localStorage:', error);
        }
    }
    
    /**
     * Carga el estado desde localStorage
     */
    loadPersistentState() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            if (!stored) return null;
            
            const state = JSON.parse(stored);
            
            // Verificar que el estado no sea muy antiguo (24 horas)
            const maxAge = 24 * 60 * 60 * 1000; // 24 horas en milisegundos
            if (Date.now() - state.timestamp > maxAge) {
                console.log('Estado en localStorage expirado, limpiando...');
                this.clearPersistentState();
                return null;
            }
            
            return state;
        } catch (error) {
            console.error('Error cargando estado desde localStorage:', error);
            return null;
        }
    }
    
    /**
     * Limpia el estado persistente
     */
    clearPersistentState() {
        try {
            localStorage.removeItem(this.storageKey);
            console.log('Estado persistente limpiado');
        } catch (error) {
            console.error('Error limpiando estado persistente:', error);
        }
    }
    
    /**
     * Restaura los valores en los campos del formulario
     */
    restoreFormValues() {
        try {
            const searchFilter = document.getElementById('searchFilter');
            if (searchFilter && this.currentFilters.search) {
                searchFilter.value = this.currentFilters.search;
            }
            
            const dateFrom = document.getElementById('dateFrom');
            if (dateFrom && this.currentFilters.dateFrom) {
                dateFrom.value = this.currentFilters.dateFrom;
            }
            
            const dateTo = document.getElementById('dateTo');
            if (dateTo && this.currentFilters.dateTo) {
                dateTo.value = this.currentFilters.dateTo;
            }
            
            const patientId = document.getElementById('patientId');
            if (patientId && this.currentFilters.patientId) {
                patientId.value = this.currentFilters.patientId;
            }
            
            // Restaurar botones de modalidad activos (selección múltiple)
            document.querySelectorAll('.modality-btn').forEach(btn => {
                btn.classList.remove('active');
                if (this.currentFilters.modalities && this.currentFilters.modalities.includes(btn.dataset.modality)) {
                    btn.classList.add('active');
                }
            });
            
            // Si no hay modalidades seleccionadas, activar "Todas"
            if (!this.currentFilters.modalities || this.currentFilters.modalities.length === 0) {
                const allBtn = document.querySelector('.modality-btn[data-modality="all"]');
                if (allBtn) allBtn.classList.add('active');
            }
            
            // Actualizar contador de modalidades
            this.updateModalityCounter();
            
            console.log('Valores del formulario restaurados');
         } catch (error) {
             console.error('Error restaurando valores del formulario:', error);
         }
     }
     
    /**
      * Verifica si hay un informe en progreso para un estudio específico
      */
     checkInProgressReport(studyId) {
         try {
             console.log('Verificando informe en progreso para studyId:', studyId);
             
             // Primero verificar URLESTUDIO
             if (window.urlStudyManager) {
                 console.log('URL Study Manager disponible');
                 
                 // Verificar si este estudio es el activo en URLESTUDIO
                 if (window.urlStudyManager.isActiveStudy(studyId)) {
                     console.log('Estudio activo en URLESTUDIO:', studyId);
                     
                     // Verificar si tiene contenido persistido
                     if (window.urlStudyManager.hasPersistedContent()) {
                         console.log('Informe encontrado en URLESTUDIO con contenido persistido');
                         return true;
                     }
                 }
             } else {
                 console.log('URL Study Manager NO disponible');
             }
             
             // Fallback: verificar el estado global (compatibilidad)
             if (window.globalStateManager) {
                 console.log('Verificando global state manager como fallback');
                 const isActive = window.globalStateManager.isReportActive(studyId);
                 if (isActive) {
                     console.log('Informe encontrado como activo en estado global');
                     return true;
                 }
             }
             
             // Fallback: buscar directamente en localStorage
             for (let i = 0; i < localStorage.length; i++) {
                 const key = localStorage.key(i);
                 if (key && key.startsWith('editor_persistence_') && key.includes(`${studyId}_`)) {
                     const data = localStorage.getItem(key);
                     if (data) {
                         try {
                             const parsedData = JSON.parse(data);
                             
                             // Verificar si hay contenido TinyMCE significativo
                             if (parsedData.tinymce && parsedData.tinymce.content) {
                                 const content = parsedData.tinymce.content.trim();
                                 // Verificar que no sea solo el template vacío o muy corto
                                 if (content.length > 200 && 
                                     !content.includes('[Describir') && 
                                     !content.includes('Técnica:') && 
                                     !content.includes('Hallazgos:') && 
                                     !content.includes('Conclusión:')) {
                                     return true;
                                 }
                                 // También verificar si el template tiene contenido añadido
                                 if (content.length > 500) {
                                     return true;
                                 }
                             }
                             
                             // Verificar si hay audio guardado
                             if (parsedData.audio && (parsedData.audio.blob || parsedData.audio.recordings)) {
                                 return true;
                             }
                             
                             // Verificar si hay cambios marcados como no guardados
                             if (parsedData.hasUnsavedChanges === true) {
                                 return true;
                             }
                         } catch (parseError) {
                             console.warn('Error parsing persistence data for key:', key, parseError);
                         }
                     }
                 }
             }
             
             return false;
         } catch (error) {
             console.error('Error verificando informe en progreso:', error);
             return false;
         }
     }
     

}

// Instancia global
let dashboardOrthanc;

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded - Pathname:', window.location.pathname);
    // Solo inicializar si estamos en el dashboard
    if (window.location.pathname.includes('dashboard-unified.html')) {
        console.log('Inicializando dashboard orthanc...');
        
        // Función para verificar si el global state manager está listo
        function waitForGlobalStateManager() {
            if (window.globalStateManager) {
                console.log('Global state manager disponible, inicializando dashboard...');
                dashboardOrthanc = new DashboardOrthanc();
                dashboardOrthanc.init();
            } else {
                console.log('Esperando global state manager...');
                setTimeout(waitForGlobalStateManager, 50);
            }
        }
        
        // Esperar un poco para que el contenido dinámico se cargue
        setTimeout(waitForGlobalStateManager, 100);
    }
});

// Función global para búsqueda (mantener compatibilidad)
function searchStudies() {
    if (dashboardOrthanc) {
        dashboardOrthanc.applyFilters();
    }
}