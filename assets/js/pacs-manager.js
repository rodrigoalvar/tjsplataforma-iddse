/**
 * PACS Manager - Gestión de Estudios en PACS (Orthanc)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Permite editar y eliminar estudios directamente en el servidor PACS
 */

class PACSManager {
    constructor() {
        this.studies = [];
        this.filteredStudies = [];
        this.currentFilters = {
            search: '',
            dateFrom: '',
            dateTo: '',
            patientId: '',
            modalities: [] // Filtro de modalidades (array vacío = todas)
        };
        this.apiBaseUrl = 'api/pacs-manager/';
        this.editModal = null;
        this.deleteModal = null;
        this.uploadModal = null;
        this.pendingDelete = null; // Almacenar datos del estudio a eliminar
        this.storageKey = 'pacs_manager_state'; // Clave para localStorage (igual que dashboard-unified y estudios-manager)
        this.isUploading = false; // Flag para prevenir múltiples subidas simultáneas
        
        // Sistema de trabajos
        this.jobs = []; // Array de trabajos
        this.jobsStorageKey = 'pacs_manager_jobs'; // Clave para localStorage
        this.activePolling = new Map(); // Map de jobId -> intervalId para polling activo
        this.jobsLogModal = null; // Referencia al modal de logs
        this.auditDetailModal = null;
        this.currentAuditLogId = null;
        
        // Configuración de ordenamiento
        this.sortConfig = {
            column: null,
            direction: 'asc' // 'asc' o 'desc'
        };
        
        // Selección de estudios
        this.savedSelectedStudyId = null; // ID del estudio seleccionado para restaurar
        
        // Inicializar módulo de subida
        this.uploader = new PACSUploader({
            apiBaseUrl: this.apiBaseUrl,
            showNotifications: true,
            onUploadStart: (files) => this.onUploadStart(files),
            onUploadProgress: (progress, result) => this.onUploadProgress(progress, result),
            onUploadComplete: (result) => this.onUploadComplete(result),
            onUploadError: (error) => this.onUploadError(error)
        });
    }

    /**
     * Inicializa el módulo
     */
    async init() {
        console.log('🔧 Inicializando PACS Manager...');
        
        this.setupEventListeners();
        this.setupModal();
        this.setupJobsEventListeners();
        this.setupAuditEventListeners();
        
        // Configurar ordenamiento de columnas
        this.setupColumnSorting();
        
        // Cargar trabajos desde localStorage
        this.loadJobsFromStorage();
        
        // Asegurar que los contadores se actualicen después de que el DOM esté listo
        setTimeout(() => {
            this.updateJobsCounters();
        }, 100);
        
        // Intentar cargar estado persistente (igual que dashboard-unified y estudios-manager)
        const cachedData = this.loadFromCache();
        if (cachedData && cachedData.studies && cachedData.studies.length > 0) {
            console.log('📦 Restaurando datos desde caché:', cachedData.studies.length, 'estudios');
            
            // Restaurar estudios y filtros desde el caché
            this.studies = cachedData.studies;
            this.currentFilters = { ...this.currentFilters, ...cachedData.filters };
            
            // Restaurar configuración de ordenamiento
            if (cachedData.sortConfig) {
                this.sortConfig = { ...this.sortConfig, ...cachedData.sortConfig };
                console.log('📦 Configuración de ordenamiento restaurada:', this.sortConfig);
            }
            
            // Restaurar selección guardada
            if (cachedData.selectedStudyId) {
                this.savedSelectedStudyId = cachedData.selectedStudyId;
                console.log('📦 Selección de estudio restaurada:', this.savedSelectedStudyId);
            }
            
            // Restaurar valores en los campos del formulario
            this.restoreFormValues();
            
            // Actualizar botones de modalidad
            this.updateModalityButtons();
            
            // Aplicar filtros locales y renderizar
            this.applyFilters();
            
            // Actualizar iconos de ordenamiento después de restaurar
            setTimeout(() => {
                this.updateSortIcons();
            }, 150);
            
            // Solo cargar modalidades faltantes si realmente faltan (no todas están cargadas)
            // Las modalidades ya deberían estar en el caché, solo consultar las que realmente falten
            const hasMissingModalities = this.studies.some(study => 
                !study.modality || study.modality === '' || study.modality === 'N/A' || study.modality.trim() === ''
            );
            if (hasMissingModalities) {
                console.log('📦 Algunas modalidades faltan, cargando bajo demanda...');
                this.loadMissingModalities();
            } else {
                console.log('✅ Todas las modalidades ya están en caché');
            }
            
            console.log('✅ Estado restaurado desde localStorage:', this.studies.length, 'estudios');
        } else {
            // Si no hay caché, mostrar estado vacío (no cargar desde Orthanc automáticamente)
            // El usuario debe hacer clic en "Buscar" para cargar estudios
            this.showEmptyState();
        }
    }

    /**
     * Configura los event listeners
     */
    setupEventListeners() {
        // Botón de búsqueda
        const searchButton = document.getElementById('searchButton');
        if (searchButton) {
            searchButton.addEventListener('click', () => this.handleSearch());
        }

        // Filtro de búsqueda general
        const searchFilter = document.getElementById('searchFilter');
        if (searchFilter) {
            searchFilter.addEventListener('input', (e) => {
                this.currentFilters.search = e.target.value;
                this.applyFilters();
            });
        }

        // Botón guardar en modal
        const saveStudyBtn = document.getElementById('saveStudyBtn');
        if (saveStudyBtn) {
            saveStudyBtn.addEventListener('click', () => this.saveStudy());
        }

        // Botón subir estudios
        const uploadButton = document.getElementById('uploadButton');
        if (uploadButton) {
            uploadButton.addEventListener('click', () => this.openUploadModal());
        }
        
        // Botón confirmar subida en modal
        const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
        if (uploadSubmitBtn) {
            uploadSubmitBtn.addEventListener('click', async () => {
                // Si el botón está en modo "Cerrar", cerrar el modal
                if (uploadSubmitBtn.dataset.action === 'close') {
                    if (this.uploadModal) this.uploadModal.hide();
                    this.loadStudies(); // Recargar lista de estudios
                    // Resetear el botón para la próxima vez
                    uploadSubmitBtn.dataset.action = 'upload';
                    uploadSubmitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Subir Archivos';
                    return;
                }
                
                // Si ya hay una subida en progreso, ignorar
                if (this.isUploading) {
                    console.log('⚠️ Subida ya en progreso, ignorando clic');
                    return;
                }
                
                await this.handleUpload();
            });
        }
        
        // Input de archivos
        const uploadFilesInput = document.getElementById('uploadFilesInput');
        if (uploadFilesInput) {
            uploadFilesInput.addEventListener('change', (e) => this.handleFileSelection(e));
        }
        
        // Botón confirmar eliminación en modal
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', () => this.confirmDelete());
        }

        // Configurar listeners para botones de modalidad (se actualizarán dinámicamente)
        this.setupModalityButtonListeners();
        
        const dateFromEl = document.getElementById('dateFrom');
        if (dateFromEl) {
            dateFromEl.addEventListener('change', () => this.clearQuickDateButtonStates());
        }
        const dateToEl = document.getElementById('dateTo');
        if (dateToEl) {
            dateToEl.addEventListener('change', () => this.clearQuickDateButtonStates());
        }
        this.setupQuickDatePresetButtons();
    }
    
    /**
     * Fecha local en YYYY-MM-DD (evita desfases de zona respecto a UTC).
     */
    formatLocalYMD(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }
    
    clearQuickDateButtonStates() {
        ['quickDateToday', 'quickDateYesterday', 'quickDateLast7'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) {
                el.classList.remove('active');
            }
        });
    }
    
    setQuickDateButtonActive(preset) {
        this.clearQuickDateButtonStates();
        const map = { today: 'quickDateToday', yesterday: 'quickDateYesterday', last7: 'quickDateLast7' };
        const id = map[preset];
        if (id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('active');
            }
        }
    }
    
    /**
     * Atajos Hoy / Ayer / Últimos 7 días: rellena fechas y ejecuta la misma búsqueda que «Buscar».
     */
    async setQuickDateRange(preset) {
        const now = new Date();
        let fromStr;
        let toStr;
        if (preset === 'today') {
            fromStr = toStr = this.formatLocalYMD(now);
        } else if (preset === 'yesterday') {
            const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
            fromStr = toStr = this.formatLocalYMD(y);
        } else if (preset === 'last7') {
            const end = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const start = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6);
            fromStr = this.formatLocalYMD(start);
            toStr = this.formatLocalYMD(end);
        } else {
            return;
        }
        
        const df = document.getElementById('dateFrom');
        const dt = document.getElementById('dateTo');
        if (df) {
            df.value = fromStr;
        }
        if (dt) {
            dt.value = toStr;
        }
        this.currentFilters.dateFrom = fromStr;
        this.currentFilters.dateTo = toStr;
        
        this.setQuickDateButtonActive(preset);
        await this.handleSearch();
    }
    
    setupQuickDatePresetButtons() {
        const bind = (id, preset) => {
            const btn = document.getElementById(id);
            if (!btn) {
                return;
            }
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.setQuickDateRange(preset).catch((err) => {
                    console.error('Error en atajo de fechas:', err);
                    this.showError('No se pudo aplicar el rango de fechas');
                });
            });
        };
        bind('quickDateToday', 'today');
        bind('quickDateYesterday', 'yesterday');
        bind('quickDateLast7', 'last7');
    }

    /**
     * Configura los modales
     */
    setupModal() {
        const editModalElement = document.getElementById('editStudyModal');
        if (editModalElement) {
            this.editModal = new bootstrap.Modal(editModalElement);
        }

        const deleteModalElement = document.getElementById('deleteStudyModal');
        if (deleteModalElement) {
            this.deleteModal = new bootstrap.Modal(deleteModalElement);
        }
        
        const uploadModalElement = document.getElementById('uploadStudyModal');
        if (uploadModalElement) {
            this.uploadModal = new bootstrap.Modal(uploadModalElement);
        }
    }

    /**
     * Maneja la búsqueda con filtros
     * 
     * Comportamiento:
     * - Si hay rango de fechas Y ID paciente: busca ID paciente en ese rango de fechas
     * - Si NO hay rango de fechas PERO SÍ hay ID paciente: busca ID paciente en todo Orthanc
     * - Si solo hay fechas: busca estudios en ese rango de fechas
     * - Si no hay filtros: no permite búsqueda (evita sobrecarga del PACS)
     */
    async handleSearch() {
        // Actualizar filtros desde el formulario
        this.updateFiltersFromForm();
        
        // Validar que haya al menos un filtro (fechas o ID de paciente)
        if (!this.currentFilters.dateFrom && !this.currentFilters.dateTo && !this.currentFilters.patientId) {
            this.showError('Por favor especifica al menos un rango de fechas o ID de paciente para consultar estudios');
            return;
        }
        
        // Si se especifican fechas, validar que ambas estén presentes
        if (this.currentFilters.dateFrom || this.currentFilters.dateTo) {
            if (!this.currentFilters.dateFrom || !this.currentFilters.dateTo) {
                this.showError('Si especificas fechas, debes indicar tanto "Desde" como "Hasta"');
                return;
            }
            
            // Validar que la fecha "desde" no sea mayor que "hasta"
            if (this.currentFilters.dateFrom > this.currentFilters.dateTo) {
                this.showError('La fecha "Desde" no puede ser mayor que la fecha "Hasta"');
                return;
            }
        }
        
        // Cuando el usuario hace clic explícitamente en "Buscar", siempre recargar desde Orthanc
        // para obtener los datos más actuales, sin importar si hay caché disponible
        await this.loadStudies();
    }

    /**
     * Carga estudios desde PACS
     */
    async loadStudies() {
        try {
            this.showLoading();

            const params = new URLSearchParams();
            // Los parámetros son opcionales y se pueden combinar:
            // - Solo fechas: busca en rango de fechas
            // - Solo patientId: busca paciente en todo Orthanc
            // - Fechas + patientId: busca paciente en rango de fechas
            if (this.currentFilters.dateFrom) {
                params.append('dateFrom', this.currentFilters.dateFrom);
            }
            if (this.currentFilters.dateTo) {
                params.append('dateTo', this.currentFilters.dateTo);
            }
            if (this.currentFilters.patientId) {
                params.append('patientId', this.currentFilters.patientId);
            }
            // Nota: No enviamos filtro de modalidad al servidor, se filtra localmente

            const url = this.apiBaseUrl + 'list.php' + (params.toString() ? '?' + params.toString() : '');
            const response = await fetch(url);
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Error al cargar estudios');
            }

            this.studies = result.data || [];
            
            // Guardar en caché después de cargar desde Orthanc
            this.saveToCache();
            
            // Actualizar botones de modalidad disponibles ANTES de aplicar filtros
            // para asegurar que los botones estén disponibles incluso si algunos estudios
            // aún no tienen modalidad cargada
            this.updateModalityButtons();
            
            // Aplicar filtros después de actualizar botones
            this.applyFilters();
            
            // Cargar modalidades faltantes en segundo plano
            this.loadMissingModalities();

            console.log(`✅ ${this.studies.length} estudios cargados`);
        } catch (error) {
            console.error('❌ Error cargando estudios:', error);
            this.showError('Error al cargar estudios: ' + error.message);
        }
    }
    
    /**
     * Carga modalidades en segundo plano para estudios que no las tengan
     * OPTIMIZADO: Carga en lotes para no sobrecargar el servidor
     */
    async loadMissingModalities() {
        try {
            const studiesWithoutModality = this.studies.filter(study =>
                !study.modality || study.modality === '' || study.modality === 'N/A' || study.modality.trim() === ''
            );

            if (studiesWithoutModality.length === 0) {
                return;
            }

            console.log(`Cargando modalidades para ${studiesWithoutModality.length} estudios (listado completo)...`);

            let anyUpdated = false;
            const batchSize = 20;
            for (let i = 0; i < studiesWithoutModality.length; i += batchSize) {
                const batch = studiesWithoutModality.slice(i, i + batchSize);

                const promises = batch.map(async (study) => {
                    try {
                        const response = await fetch(`${this.apiBaseUrl.replace('pacs-manager/', '')}get_study_details.php?study_id=${study.study_id}`);

                        if (!response.ok) {
                            console.warn(`Error ${response.status} cargando modalidad para estudio ${study.study_id}`);
                            return;
                        }

                        const result = await response.json();

                        if (result.success && result.data && result.data.modality && result.data.modality !== 'N/A') {
                            anyUpdated = true;
                            const studyIndex = this.studies.findIndex(s => s.study_id === study.study_id);
                            if (studyIndex !== -1) {
                                this.studies[studyIndex].modality = result.data.modality;
                            }

                            const filteredIndex = this.filteredStudies.findIndex(s => s.study_id === study.study_id);
                            if (filteredIndex !== -1) {
                                this.filteredStudies[filteredIndex].modality = result.data.modality;
                            }

                            this.updateStudyRowModality(study.study_id, result.data.modality);
                        }
                    } catch (error) {
                        console.error(`Error cargando modalidad para estudio ${study.study_id}:`, error);
                    }
                });

                await Promise.all(promises);

                this.saveToCache();

                if (i + batchSize < studiesWithoutModality.length) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                }
            }

            console.log('Modalidades cargadas en segundo plano');

            if (anyUpdated) {
                this.updateModalityButtons();
                this.applyFilters();
            } else {
                this.updateModalityButtons();
            }

            this.saveToCache();
        } catch (error) {
            console.error('Error cargando modalidades faltantes:', error);
        }
    }
    
    /**
     * Actualiza un estudio editado en la lista sin recargar toda la lista
     */
    async updateEditedStudy(studyId) {
        try {
            // Obtener los valores del formulario que se acaban de editar
            const patientName = document.getElementById('editPatientName')?.value.trim() || '';
            const patientID = document.getElementById('editPatientID')?.value.trim() || '';
            const studyDescription = document.getElementById('editStudyDescription')?.value.trim() || '';
            const studyDate = document.getElementById('editStudyDate')?.value || '';
            const formattedDate = studyDate ? studyDate.replace(/-/g, '') : '';
            const accessionNumber = document.getElementById('editAccessionNumber')?.value.trim() || '';
            const institutionName = document.getElementById('editInstitutionName')?.value.trim() || '';
            const referringPhysician = document.getElementById('editReferringPhysician')?.value.trim() || '';

            // Buscar el estudio en los arrays
            const studyIndex = this.studies.findIndex(s => s.study_id === studyId);
            if (studyIndex === -1) {
                console.warn('Estudio no encontrado en la lista, recargando toda la lista...');
                await this.loadStudies();
                return;
            }

            // Actualizar el estudio en el array principal
            const updatedStudy = { ...this.studies[studyIndex] };
            if (patientName) updatedStudy.patient_name = patientName;
            if (patientID) updatedStudy.patient_id = patientID;
            if (studyDescription) updatedStudy.study_description = studyDescription;
            if (formattedDate) updatedStudy.study_date = formattedDate;
            if (accessionNumber !== undefined) updatedStudy.accession_number = accessionNumber;
            if (institutionName !== undefined) updatedStudy.institution_name = institutionName;
            if (referringPhysician !== undefined) updatedStudy.referring_physician_name = referringPhysician;

            this.studies[studyIndex] = updatedStudy;

            // Actualizar también en filteredStudies si existe
            const filteredIndex = this.filteredStudies.findIndex(s => s.study_id === studyId);
            if (filteredIndex !== -1) {
                this.filteredStudies[filteredIndex] = { ...updatedStudy };
            }

            // Actualizar la fila inmediatamente con los datos del formulario
            this.updateStudyRow(studyId, updatedStudy);

            // Obtener modalidad actualizada en segundo plano (no bloquea)
            this.loadStudyModality(studyId).then(modality => {
                if (modality) {
                    updatedStudy.modality = modality;
                    this.studies[studyIndex].modality = modality;
                    if (filteredIndex !== -1) {
                        this.filteredStudies[filteredIndex].modality = modality;
                    }
                    // Actualizar la fila y los botones de modalidad
                    this.updateStudyRowModality(studyId, modality);
                    this.updateModalityButtons();
                }
            }).catch(() => {
                // Si falla, no hacer nada (ya actualizamos la fila)
            });
        } catch (error) {
            console.error('Error actualizando estudio editado:', error);
            // Fallback: recargar toda la lista si hay error
            await this.loadStudies();
        }
        
        // Actualizar caché después de editar
        this.saveToCache();
    }

    /**
     * Carga la modalidad de un estudio específico
     */
    async loadStudyModality(studyId) {
        try {
            const response = await fetch(`${this.apiBaseUrl.replace('pacs-manager/', '')}get_study_details.php?study_id=${studyId}`);
            if (!response.ok) return null;
            
            const result = await response.json();
            if (result.success && result.data && result.data.modality && result.data.modality !== 'N/A') {
                return result.data.modality;
            }
            return null;
        } catch (error) {
            console.error('Error cargando modalidad del estudio:', error);
            return null;
        }
    }

    /**
     * Actualiza una fila específica de la tabla con los nuevos datos del estudio
     */
    updateStudyRow(studyId, study) {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;

        // Buscar la fila por el study_id
        const rows = tbody.querySelectorAll('tr');
        for (const row of rows) {
            const editBtn = row.querySelector(`.btn-edit-study[data-study-id="${studyId}"]`);
            if (editBtn) {
                // Formatear fecha para mostrar
                let displayDate = study.study_date || '';
                if (displayDate && displayDate.length === 8) {
                    displayDate = `${displayDate.substring(6,8)}/${displayDate.substring(4,6)}/${displayDate.substring(0,4)}`;
                } else if (displayDate && displayDate.includes('-')) {
                    // Si viene en formato YYYY-MM-DD
                    const parts = displayDate.split('-');
                    if (parts.length === 3) {
                        displayDate = `${parts[2]}/${parts[1]}/${parts[0]}`;
                    }
                }

                // Formatear hora
                let displayTime = study.study_time || '';
                if (displayTime && displayTime.length >= 6) {
                    displayTime = `${displayTime.substring(0,2)}:${displayTime.substring(2,4)}`;
                }

                // Formatear nombre del paciente
                const patientName = study.patient_name || 'N/A';
                const formattedPatientName = patientName.replace(/\^/g, ' ').trim();

                // Modalidad
                const modality = study.modality || 'Cargando...';
                const modalityBadge = modality.includes('Cargando') 
                    ? modality 
                    : `<span class="badge bg-info">${modality}</span>`;

                // Actualizar las celdas de la fila
                const cells = row.querySelectorAll('td');
                if (cells.length >= 8) {
                    cells[0].textContent = displayDate; // Fecha
                    if (cells[1]) cells[1].textContent = displayTime; // Hora
                    if (cells[2]) {
                        const nameDiv = cells[2].querySelector('.fw-semibold');
                        if (nameDiv) {
                            nameDiv.textContent = formattedPatientName;
                        } else {
                            cells[2].innerHTML = `<div class="fw-semibold">${formattedPatientName}</div>`;
                        }
                    }
                    if (cells[3]) cells[3].textContent = study.patient_id || 'N/A'; // ID
                    if (cells[4]) {
                        cells[4].setAttribute('data-study-id', studyId);
                        cells[4].innerHTML = modalityBadge; // Modalidad
                    }
                    if (cells[5]) cells[5].textContent = study.study_description || 'N/A'; // Descripción
                    if (cells[6] && study.r2_status !== undefined && study.r2_status !== null) {
                        cells[6].innerHTML = this.formatR2StudyBadge(study.r2_status);
                    }
                }
                break;
            }
        }
    }

    /**
     * Actualiza la modalidad en una fila específica de la tabla
     */
    updateStudyRowModality(studyId, modality) {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;
        
        // Buscar la celda por el atributo data-study-id
        const modalityCell = tbody.querySelector(`td[data-study-id="${studyId}"]`);
        if (modalityCell) {
            modalityCell.innerHTML = `<span class="badge bg-info">${modality}</span>`;
        } else {
            // Fallback: buscar por contenido si no hay atributo
            const rows = tbody.querySelectorAll('tr');
            for (const row of rows) {
                const rowText = row.textContent || '';
                if (rowText.includes(studyId)) {
                    const cells = row.querySelectorAll('td');
                    // La modalidad está en la 5ta columna (índice 4) en pacs-manager
                    // (fecha, hora, nombre, patient_id, modalidad)
                    if (cells.length > 4) {
                        cells[4].innerHTML = `<span class="badge bg-info">${modality}</span>`;
                    }
                    break;
                }
            }
        }
    }

    /**
     * Tokens de modalidad de un estudio (mayúsculas, únicos), coma o barra invertida DICOM.
     */
    getStudyModalityTokens(study) {
        if (!study || !study.modality || study.modality === 'N/A' || String(study.modality).trim() === '') {
            return [];
        }
        const parts = String(study.modality).split(/[,\\]+/).map(m => m.trim().toUpperCase()).filter(Boolean);
        return [...new Set(parts)];
    }

    /**
     * Coincide con el texto de búsqueda en tabla (sin filtro por modalidad).
     */
    studyMatchesSearchFilter(study) {
        const searchTerm = (this.currentFilters.search || '').toLowerCase().trim();
        if (!searchTerm) {
            return true;
        }
        const patientName = (study.patient_name || '').toLowerCase();
        const patientId = (study.patient_id || '').toLowerCase();
        const studyDescription = (study.study_description || '').toLowerCase();
        const modality = (study.modality || '').toLowerCase();
        const accessionNumber = (study.accession_number || '').toLowerCase();

        return patientName.includes(searchTerm) ||
            patientId.includes(searchTerm) ||
            studyDescription.includes(searchTerm) ||
            modality.includes(searchTerm) ||
            accessionNumber.includes(searchTerm);
    }

    /**
     * Coincide con el filtro de modalidades seleccionadas en chips.
     */
    studyMatchesModalityChipFilter(study) {
        const modalityFilters = this.currentFilters.modalities;
        if (!modalityFilters || modalityFilters.length === 0) {
            return true;
        }
        const tokens = this.getStudyModalityTokens(study);
        return modalityFilters.some(filterModality =>
            tokens.includes(String(filterModality).toUpperCase())
        );
    }

    /**
     * Aplica filtros locales (búsqueda general y modalidad)
     */
    applyFilters() {
        let filtered = this.studies.filter(study =>
            this.studyMatchesSearchFilter(study) && this.studyMatchesModalityChipFilter(study)
        );

        filtered = this.sortResults(filtered);

        this.filteredStudies = filtered;
        this.refreshModalitySubcounts();
        this.renderStudies();
        this.updateCounters();
    }

    /**
     * Extrae modalidades únicas de los estudios cargados
     */
    getAvailableModalities() {
        const modalitiesSet = new Set();
        
        // Recorrer todos los estudios para extraer modalidades
        this.studies.forEach(study => {
            this.getStudyModalityTokens(study).forEach(m => modalitiesSet.add(m));
        });
        
        // Ordenar modalidades de forma estándar
        const standardOrder = ['RX', 'DX', 'CT', 'MR', 'US', 'MG', 'OT', 'XA', 'NM', 'PT', 'RF', 'DOC', 'CR', 'ES', 'SC'];
        const sortedModalities = Array.from(modalitiesSet).sort((a, b) => {
            const indexA = standardOrder.indexOf(a);
            const indexB = standardOrder.indexOf(b);
            if (indexA !== -1 && indexB !== -1) return indexA - indexB;
            if (indexA !== -1) return -1;
            if (indexB !== -1) return 1;
            return a.localeCompare(b);
        });
        
        return sortedModalities;
    }

    /**
     * Cuenta estudios por código de modalidad (un estudio con "CT, MR" suma en CT y en MR).
     * @param {Array} studies
     * @returns {Record<string, number>}
     */
    getModalityCountsFromStudies(studies) {
        const counts = {};
        if (!studies || !studies.length) {
            return counts;
        }
        studies.forEach(study => {
            this.getStudyModalityTokens(study).forEach(m => {
                counts[m] = (counts[m] || 0) + 1;
            });
        });
        return counts;
    }

    /**
     * Actualiza solo los subíndices de cantidad en los botones de modalidad
     * (lista filtrada por búsqueda en tabla, sin filtro por chip de modalidad).
     */
    refreshModalitySubcounts() {
        const modalityFiltersContainer = document.getElementById('modalityButtonsContainer') ||
            document.querySelector('.modality-filters .d-flex.flex-wrap');
        if (!modalityFiltersContainer) {
            return;
        }

        const modalityMap = {
            RX: 'CR',
            DX: 'CR',
            CT: 'CT',
            MR: 'MR',
            US: 'US',
            MG: 'MG',
            NM: 'NM',
            PT: 'PT',
            XA: 'XA',
            RF: 'RF',
            OT: 'OT'
        };

        const baseStudies = this.studies.filter(study => this.studyMatchesSearchFilter(study));
        const modalityCounts = this.getModalityCountsFromStudies(baseStudies);

        modalityFiltersContainer.querySelectorAll('.modality-btn').forEach(btn => {
            const modality = btn.getAttribute('data-modality');
            const sup = btn.querySelector('.modality-count-sub');
            if (!sup) {
                return;
            }
            if (modality === 'all') {
                sup.textContent = String(baseStudies.length);
            } else if (modality) {
                const modUpper = modality.toUpperCase();
                const dicomModality = (modalityMap[modality] || modality).toUpperCase();
                const n = (modalityCounts[modUpper] || 0) +
                    (modUpper !== dicomModality ? (modalityCounts[dicomModality] || 0) : 0);
                sup.textContent = n > 0 ? String(n) : '';
            }
        });
    }

    /**
     * Orden de códigos de modalidad (misma lógica que getAvailableModalities)
     */
    sortModalityKeys(keys) {
        const standardOrder = ['RX', 'DX', 'CT', 'MR', 'US', 'MG', 'OT', 'XA', 'NM', 'PT', 'RF', 'DOC', 'CR', 'ES', 'SC'];
        return [...keys].sort((a, b) => {
            const indexA = standardOrder.indexOf(a);
            const indexB = standardOrder.indexOf(b);
            if (indexA !== -1 && indexB !== -1) return indexA - indexB;
            if (indexA !== -1) return -1;
            if (indexB !== -1) return 1;
            return a.localeCompare(b);
        });
    }

    /**
     * Pinta contadores por modalidad en el header según el resultado íntegro de la última
     * consulta al PACS (this.studies), sin aplicar filtro local por modalidad ni búsqueda en tabla.
     */
    updateHeaderModalityCounts() {
        const wrap = document.getElementById('headerModalityCountsWrap');
        const el = document.getElementById('headerModalityCounts');
        if (!wrap || !el) return;

        const counts = this.getModalityCountsFromStudies(this.studies);
        const keys = this.sortModalityKeys(Object.keys(counts));
        if (keys.length === 0) {
            wrap.classList.add('d-none');
            el.innerHTML = '';
            return;
        }
        wrap.classList.remove('d-none');
        el.innerHTML = keys.map(m => {
            const n = counts[m];
            return `<span class="badge rounded-pill bg-light text-dark border" role="listitem"><span class="fw-semibold">${this.escapeHtml(m)}</span> <span class="text-muted">${n}</span></span>`;
        }).join('');
    }

    /**
     * Actualiza dinámicamente los botones de modalidad disponibles
     */
    updateModalityButtons() {
        const modalityFiltersContainer = document.getElementById('modalityButtonsContainer') || 
                                         document.querySelector('.modality-filters .d-flex.flex-wrap');
        if (!modalityFiltersContainer) return;
        
        const availableModalities = this.getAvailableModalities();
        const currentActiveModalities = this.currentFilters.modalities || [];
        
        // Guardar el estado activo antes de actualizar
        const wasAllActive = currentActiveModalities.length === 0;
        
        // Limpiar botones existentes (excepto "Todas")
        const allBtn = modalityFiltersContainer.querySelector('.modality-btn[data-modality="all"]');
        modalityFiltersContainer.innerHTML = '';
        
        // Restaurar botón "Todas"
        if (allBtn) {
            if (!allBtn.querySelector('.modality-count-sub')) {
                allBtn.innerHTML = '<span class="modality-label">Todas</span><sup class="modality-count-sub ms-1 text-body-secondary fw-normal"></sup>';
            }
            modalityFiltersContainer.appendChild(allBtn);
            if (wasAllActive) {
                allBtn.classList.add('active');
            } else {
                allBtn.classList.remove('active');
            }
        } else {
            const newAllBtn = document.createElement('button');
            newAllBtn.type = 'button';
            newAllBtn.className = `btn modality-btn ${wasAllActive ? 'active' : ''}`;
            newAllBtn.setAttribute('data-modality', 'all');
            newAllBtn.innerHTML = '<span class="modality-label">Todas</span><sup class="modality-count-sub ms-1 text-body-secondary fw-normal"></sup>';
            modalityFiltersContainer.appendChild(newAllBtn);
        }
        
        // Crear botones para cada modalidad disponible
        availableModalities.forEach(modality => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn modality-btn';
            btn.setAttribute('data-modality', modality);
            btn.innerHTML = `<span class="modality-label">${this.escapeHtml(modality)}</span><sup class="modality-count-sub ms-1 text-body-secondary fw-normal"></sup>`;
            
            if (currentActiveModalities.includes(modality)) {
                btn.classList.add('active');
                const allButton = modalityFiltersContainer.querySelector('.modality-btn[data-modality="all"]');
                if (allButton) {
                    allButton.classList.remove('active');
                }
            }
            
            modalityFiltersContainer.appendChild(btn);
        });
        
        this.setupModalityButtonListeners();
        this.refreshModalitySubcounts();
        this.updateModalityCounter();
    }

    /**
     * Configura los event listeners para los botones de modalidad
     */
    setupModalityButtonListeners() {
        // Remover todos los listeners anteriores agregando nuevos botones
        document.querySelectorAll('.modality-btn').forEach(btn => {
            // Crear nuevo botón sin listeners
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            
            newBtn.addEventListener('click', (e) => {
                const btn = e.currentTarget;
                const modality = btn.dataset.modality;

                if (modality === 'all') {
                    document.querySelectorAll('.modality-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    this.currentFilters.modalities = [];
                } else {
                    const allBtn = document.querySelector('.modality-btn[data-modality="all"]');
                    if (allBtn) {
                        allBtn.classList.remove('active');
                    }

                    if (btn.classList.contains('active')) {
                        btn.classList.remove('active');
                        this.currentFilters.modalities = this.currentFilters.modalities.filter(m => m !== modality);
                    } else {
                        btn.classList.add('active');
                        this.currentFilters.modalities.push(modality);
                    }

                    if (this.currentFilters.modalities.length === 0 && allBtn) {
                        allBtn.classList.add('active');
                    }
                }

                this.updateModalityCounter();
                this.applyFilters();
            });
        });
    }

    /**
     * Actualiza el contador de modalidades seleccionadas
     */
    updateModalityCounter() {
        const counter = document.getElementById('modalityCounter');
        if (!counter) return;

        const activeModalities = document.querySelectorAll('.modality-btn.active:not([data-modality="all"])');
        if (activeModalities.length > 0) {
            counter.textContent = activeModalities.length;
            counter.style.display = 'inline-block';
        } else {
            counter.style.display = 'none';
        }
    }

    /**
     * Configurar ordenamiento de columnas
     */
    setupColumnSorting() {
        // Usar setTimeout para asegurar que el DOM esté completamente listo
        setTimeout(() => {
            const sortableHeaders = document.querySelectorAll('.studies-table thead th.sortable');
            sortableHeaders.forEach(header => {
                header.style.cursor = 'pointer';
                // Remover listeners anteriores si existen
                const newHeader = header.cloneNode(true);
                header.parentNode.replaceChild(newHeader, header);
                // Agregar listener al nuevo header
                newHeader.addEventListener('click', () => {
                    const column = newHeader.getAttribute('data-column');
                    if (column) {
                        this.sortByColumn(column);
                    }
                });
            });
            // Actualizar iconos iniciales
            this.updateSortIcons();
        }, 100);
    }
    
    /**
     * Ordenar por una columna específica
     */
    sortByColumn(column) {
        // Si se hace clic en la misma columna, invertir dirección
        if (this.sortConfig.column === column) {
            this.sortConfig.direction = this.sortConfig.direction === 'asc' ? 'desc' : 'asc';
        } else {
            // Nueva columna, ordenar ascendente por defecto
            this.sortConfig.column = column;
            this.sortConfig.direction = 'asc';
        }
        
        // Guardar estado persistente
        this.saveToCache();
        
        // Actualizar iconos y renderizar
        this.updateSortIcons();
        this.applyFilters(); // Esto aplicará el ordenamiento y renderizará
    }
    
    /**
     * Actualizar iconos de ordenamiento
     */
    updateSortIcons() {
        const sortableHeaders = document.querySelectorAll('.studies-table thead th.sortable');
        sortableHeaders.forEach(header => {
            const icon = header.querySelector('.sort-icon');
            if (!icon) return;
            
            const column = header.getAttribute('data-column');
            if (this.sortConfig.column === column) {
                icon.className = this.sortConfig.direction === 'asc' 
                    ? 'fas fa-sort-up sort-icon' 
                    : 'fas fa-sort-down sort-icon';
            } else {
                icon.className = 'fas fa-sort sort-icon';
            }
        });
    }
    
    /**
     * Ordenar resultados
     */
    sortResults(results) {
        if (!this.sortConfig.column) {
            // Si no hay columna seleccionada, retornar sin ordenar
            return results;
        }
        
        return results.sort((a, b) => {
            let valueA, valueB;
            
            switch (this.sortConfig.column) {
                case 'date':
                    // Ordenar por fecha (formato YYYY-MM-DD)
                    valueA = a.study_date || '';
                    valueB = b.study_date || '';
                    break;
                case 'time':
                    // Ordenar por hora (formato HH:MM:SS o HHMMSS)
                    valueA = a.study_time || '';
                    valueB = b.study_time || '';
                    // Si viene en formato HHMMSS, convertir a HH:MM:SS para comparar
                    if (valueA.length === 6 && !valueA.includes(':')) {
                        valueA = `${valueA.substring(0, 2)}:${valueA.substring(2, 4)}:${valueA.substring(4, 6)}`;
                    }
                    if (valueB.length === 6 && !valueB.includes(':')) {
                        valueB = `${valueB.substring(0, 2)}:${valueB.substring(2, 4)}:${valueB.substring(4, 6)}`;
                    }
                    break;
                case 'patient_name':
                    valueA = (a.patient_name || '').toLowerCase();
                    valueB = (b.patient_name || '').toLowerCase();
                    break;
                case 'patient_id':
                    valueA = (a.patient_id || '').toLowerCase();
                    valueB = (b.patient_id || '').toLowerCase();
                    break;
                case 'modality':
                    valueA = (a.modality || '').toLowerCase();
                    valueB = (b.modality || '').toLowerCase();
                    break;
                case 'study_description':
                    valueA = (a.study_description || '').toLowerCase();
                    valueB = (b.study_description || '').toLowerCase();
                    break;
                default:
                    return 0;
            }
            
            // Comparar valores
            if (valueA < valueB) {
                return this.sortConfig.direction === 'asc' ? -1 : 1;
            }
            if (valueA > valueB) {
                return this.sortConfig.direction === 'asc' ? 1 : -1;
            }
            return 0;
        });
    }

    /**
     * Badge de estado R2 (cloud-storage) para la lista de estudios
     */
    formatR2StudyBadge(r2Status) {
        const s = (r2Status || 'none').toLowerCase();
        if (s === 'online') {
            return '<span class="badge bg-success" title="Estudio disponible en R2"><i class="fas fa-cloud me-1"></i>En R2</span>';
        }
        if (s === 'pending') {
            return '<span class="badge bg-warning text-dark" title="Pendiente de sincronización con R2">Pendiente</span>';
        }
        return '<span class="text-muted small" title="Sin copia en R2">—</span>';
    }

    /**
     * Renderiza la tabla de estudios
     */
    renderStudies() {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;

        if (this.filteredStudies.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                        <div class="text-muted">No se encontraron estudios</div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = this.filteredStudies.map(study => {
            const studyDate = this.formatDate(study.study_date);
            const studyTime = this.formatTime(study.study_time);
            // Mostrar modalidad o placeholder si está vacía (se cargará en segundo plano)
            const modality = study.modality && study.modality !== '' && study.modality !== 'N/A' 
                ? study.modality 
                : '<span class="text-muted">Cargando...</span>';
            const studyDescription = study.study_description || 'Sin descripción';
            const patientName = this.escapeHtml(study.patient_name || 'N/A');
            const studyId = this.escapeHtml(study.study_id);

            return `
                <tr data-study-id="${studyId}">
                    <td>${studyDate}</td>
                    <td class="d-none d-md-table-cell">${studyTime}</td>
                    <td>
                        <div class="fw-semibold">${patientName}</div>
                    </td>
                    <td class="d-none d-lg-table-cell">${study.patient_id || 'N/A'}</td>
                    <td class="d-none d-sm-table-cell" data-study-id="${studyId}">
                        ${modality.includes('Cargando') ? modality : `<span class="badge bg-info">${modality}</span>`}
                    </td>
                    <td class="d-none d-xl-table-cell">${studyDescription}</td>
                    <td class="text-center align-middle">${this.formatR2StudyBadge(study.r2_status)}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-action btn-info btn-info-study" data-study-id="${studyId}" title="Información">
                                <i class="fas fa-info-circle"></i>
                            </button>
                            ${study.viewer_url ? `
                            <button class="btn btn-sm btn-action btn-view btn-view-study" data-viewer-url="${this.escapeHtml(study.viewer_url)}" title="Ver">
                                <i class="fas fa-eye"></i>
                                <span class="d-none d-sm-inline ms-1">Ver</span>
                            </button>
                            ` : ''}
                            <button class="btn btn-sm btn-action btn-download btn-download-study" data-study-id="${studyId}" title="Descargar">
                                <i class="fas fa-download"></i>
                                <span class="d-none d-md-inline ms-1">Descargar</span>
                            </button>
                            <button class="btn btn-sm btn-primary btn-edit-study" data-study-id="${studyId}" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger btn-delete-study" data-study-id="${studyId}" data-patient-name="${patientName}" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        // Agregar event listeners a los botones
        tbody.querySelectorAll('.btn-info-study').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const studyId = e.target.closest('.btn-info-study').getAttribute('data-study-id');
                this.showStudyInfo(studyId);
            });
        });

        tbody.querySelectorAll('.btn-view-study').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const viewerUrl = e.target.closest('.btn-view-study').getAttribute('data-viewer-url');
                if (viewerUrl) {
                    window.open(viewerUrl, '_blank');
                }
            });
        });

        tbody.querySelectorAll('.btn-download-study').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const studyId = e.target.closest('.btn-download-study').getAttribute('data-study-id');
                this.downloadStudy(studyId);
            });
        });

        tbody.querySelectorAll('.btn-edit-study').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const studyId = e.target.closest('.btn-edit-study').getAttribute('data-study-id');
                this.editStudy(studyId);
            });
        });

        tbody.querySelectorAll('.btn-delete-study').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const studyId = e.target.closest('.btn-delete-study').getAttribute('data-study-id');
                const patientName = e.target.closest('.btn-delete-study').getAttribute('data-patient-name');
                this.deleteStudy(studyId, patientName);
            });
        });
        
        // Configurar selección de filas
        this.setupRowSelection();
    }
    
    /**
     * Configurar selección de filas al hacer click
     */
    setupRowSelection() {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;
        
        // Restaurar selección guardada
        const selectedStudyId = this.savedSelectedStudyId || null;
        
        // Agregar listeners a todas las filas
        const rows = tbody.querySelectorAll('tr[data-study-id]');
        rows.forEach(row => {
            const studyId = row.getAttribute('data-study-id');
            
            // Restaurar selección si coincide
            if (selectedStudyId && studyId === selectedStudyId) {
                row.classList.add('selected');
            }
            
            row.addEventListener('click', (e) => {
                // No seleccionar si se hace click en un botón
                if (e.target.closest('button')) {
                    return;
                }
                
                // Remover selección anterior
                tbody.querySelectorAll('tr.selected').forEach(r => {
                    r.classList.remove('selected');
                });
                
                // Agregar selección a la fila clickeada
                row.classList.add('selected');
                
                // Guardar selección en persistencia
                this.saveSelectedStudy(studyId);
            });
        });
        
        // Limpiar savedSelectedStudyId después de usarlo
        if (this.savedSelectedStudyId) {
            delete this.savedSelectedStudyId;
        }
    }
    
    /**
     * Guardar estudio seleccionado en persistencia
     */
    saveSelectedStudy(studyId) {
        // Guardar en variable temporal para usar en saveToCache
        this.savedSelectedStudyId = studyId;
        // Guardar estado completo
        this.saveToCache();
    }

    /**
     * Abre el modal para editar un estudio
     */
    async editStudy(studyId) {
        try {
            const study = this.studies.find(s => s.study_id === studyId);
            if (!study) {
                throw new Error('Estudio no encontrado');
            }

            // Llenar formulario con datos del estudio
            document.getElementById('editStudyId').value = studyId;
            
            // Llenar campos con los valores del estudio
            // Si vienen vacíos desde Orthanc, dejar vacíos para que el usuario los complete
            document.getElementById('editPatientName').value = study.patient_name || '';
            document.getElementById('editPatientID').value = study.patient_id || '';
            
            // Para la descripción, si está vacía, usar un placeholder que el usuario puede editar
            const studyDescription = study.study_description || '';
            document.getElementById('editStudyDescription').value = studyDescription;
            
            // Log para depuración
            console.log('📝 Cargando estudio para edición:', {
                studyId: studyId,
                patient_name: study.patient_name,
                patient_id: study.patient_id,
                study_description: study.study_description,
                study_description_length: study.study_description ? study.study_description.length : 0
            });
            
            // Convertir fecha DICOM (YYYYMMDD) a formato input date (YYYY-MM-DD)
            const studyDate = study.study_date || '';
            if (studyDate && studyDate.length === 8) {
                const formattedDate = `${studyDate.substring(0, 4)}-${studyDate.substring(4, 6)}-${studyDate.substring(6, 8)}`;
                document.getElementById('editStudyDate').value = formattedDate;
            } else if (studyDate && studyDate.length === 10) {
                // Ya está en formato YYYY-MM-DD
                document.getElementById('editStudyDate').value = studyDate;
            } else {
                // Si no hay fecha, usar la fecha actual
                const today = new Date();
                const todayFormatted = today.toISOString().split('T')[0];
                document.getElementById('editStudyDate').value = todayFormatted;
            }
            
            document.getElementById('editAccessionNumber').value = study.accession_number || '';
            document.getElementById('editInstitutionName').value = study.institution_name || '';
            document.getElementById('editReferringPhysician').value = study.referring_physician_name || '';

            // Mostrar modal
            if (this.editModal) {
                this.editModal.show();
            }
        } catch (error) {
            console.error('Error abriendo modal de edición:', error);
            this.showError('Error al cargar datos del estudio: ' + error.message);
        }
    }

    /**
     * Guarda los cambios del estudio
     */
    async saveStudy() {
        try {
            const studyId = document.getElementById('editStudyId').value;
            if (!studyId) {
                throw new Error('ID de estudio no válido');
            }

            // Log para diagnóstico
            console.log('🔍 [PACS_MANAGER] Enviando estudio para editar:', {
                studyId: studyId,
                studyId_length: studyId.length,
                studyId_type: typeof studyId
            });

            // Obtener valores del formulario
            const studyDate = document.getElementById('editStudyDate').value;
            let formattedDate = '';
            if (studyDate) {
                // Convertir de YYYY-MM-DD a YYYYMMDD (formato DICOM)
                formattedDate = studyDate.replace(/-/g, '');
            }

            const patientName = document.getElementById('editPatientName').value.trim();
            const patientID = document.getElementById('editPatientID').value.trim();
            const studyDescription = document.getElementById('editStudyDescription').value.trim();

            // Log para depuración
            console.log('💾 Validando campos antes de guardar:', {
                patientName: patientName ? patientName.substring(0, 20) + '...' : '(vacío)',
                patientID: patientID ? patientID.substring(0, 20) + '...' : '(vacío)',
                studyDescription: studyDescription ? studyDescription.substring(0, 30) + '...' : '(vacío)',
                studyDescription_length: studyDescription.length
            });

            // Validar solo el formato de fecha si se proporciona (permitir campos vacíos)
            // Nota: Algunos campos pueden dejarse vacíos para eliminarlos del estudio
            if (studyDate && (!formattedDate || formattedDate.length !== 8)) {
                throw new Error('El campo Fecha debe tener un formato válido (YYYY-MM-DD) si se proporciona');
            }

            // Construir objeto tags con todos los campos (incluyendo vacíos para eliminarlos)
            const tags = {};
            
            // Siempre incluir estos campos, incluso si están vacíos (para permitir eliminarlos)
            tags.PatientName = patientName || '';
            tags.PatientID = patientID || '';
            tags.StudyDescription = studyDescription || '';
            if (formattedDate) tags.StudyDate = formattedDate;
            
            const accessionNumber = document.getElementById('editAccessionNumber').value.trim();
            const institutionName = document.getElementById('editInstitutionName').value.trim();
            const referringPhysician = document.getElementById('editReferringPhysician').value.trim();
            
            // Incluir todos los campos, incluso si están vacíos
            tags.AccessionNumber = accessionNumber || '';
            tags.InstitutionName = institutionName || '';
            tags.ReferringPhysicianName = referringPhysician || '';

            // Deshabilitar botón mientras se guarda
            const saveBtn = document.getElementById('saveStudyBtn');
            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Iniciando...';

            // Preparar payload
            const deleteOriginal = document.getElementById('editDeleteOriginalStudy')?.checked !== false;

            const payload = {
                study_id: studyId,
                tags: tags,
                delete_original: deleteOriginal
            };
            
            console.log('📤 [PACS_MANAGER] Payload a enviar:', {
                study_id: payload.study_id,
                study_id_length: payload.study_id ? payload.study_id.length : 0,
                tags_count: Object.keys(payload.tags).length,
                tags: payload.tags
            });
            
            // Crear trabajo ANTES de enviar
            const study = this.studies.find(s => s.study_id === studyId);
            const job = this.createJob('edit', studyId, {
                patientName: patientName || (study ? study.patient_name : 'N/A'),
                patientId: patientID || (study ? study.patient_id : 'N/A'),
                studyDescription: studyDescription || (study ? study.study_description : 'N/A'),
                tagsModified: tags // Los tags que se van a modificar
            });
            
            // Crear un AbortController para manejar timeout extendido
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutos timeout
            
            try {
                // Enviar a la API con timeout extendido
                const response = await fetch(this.apiBaseUrl + 'edit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload),
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);
            
                console.log('📥 [PACS_MANAGER] Respuesta recibida:', {
                    status: response.status,
                    statusText: response.statusText,
                    ok: response.ok
                });

                // Leer respuesta como texto primero para debugging
                const responseText = await response.text();
            console.log('📄 [PACS_MANAGER] Respuesta raw:', responseText);
            
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('❌ [PACS_MANAGER] Error parseando JSON:', e);
                    console.error('❌ [PACS_MANAGER] Respuesta recibida:', responseText);
                    throw new Error('Error en la respuesta del servidor: ' + responseText.substring(0, 200));
                }
                
                console.log('📊 [PACS_MANAGER] Resultado parseado:', result);

                if (result.success) {
                    // Cerrar modal de edición de inmediato (el job Orthanc sigue en segundo plano)
                    if (this.editModal) {
                        this.editModal.hide();
                    }

                    // Verificar si es modo asíncrono
                    if (result.async && result.job_id) {
                        const updateData = {
                            jobId: result.job_id,
                            status: 'running',
                            progress: 0
                        };
                        
                        if (result.original_study_id) {
                            updateData.originalStudyId = result.original_study_id;
                            updateData.studyId = result.original_study_id;
                        }
                        if (result.migration_log_id) {
                            updateData.migrationLogId = result.migration_log_id;
                        }
                        if (typeof result.delete_original === 'boolean') {
                            updateData.deleteOriginal = result.delete_original;
                        }
                        
                        this.updateJob(job.id, updateData);
                        
                        const updatedJob = this.jobs.find(j => j.id === job.id);
                        const jobToPoll = updatedJob || job;
                        this.startJobPolling(jobToPoll);
                        
                        this.showSuccess(
                            result.message ||
                            'Modificación en Orthanc en curso. Puedes seguir usando PACS Manager; revisa el progreso en la pestaña Trabajos.'
                        );
                        
                    } else {
                        // Modo síncrono (fallback)
                        console.log('✅ Edición completada en modo síncrono, actualizando trabajo:', job.id);
                        this.updateJob(job.id, {
                            status: 'success',
                            newStudyId: result.study_id !== studyId ? result.study_id : null,
                            note: result.note || null,
                            migrationLogId: result.migration_log_id || null,
                            reconcileStatus: result.reconcile_status || null
                        });
                        
                        // Forzar actualización de contadores después de un pequeño delay
                        setTimeout(() => {
                            this.updateJobsCounters();
                        }, 100);
                        
                        // Mostrar mensaje de éxito
                        this.showSuccess(result.message || 'Estudio actualizado exitosamente');

                        // Si se creó un nuevo estudio (y se eliminó el original), recargar la lista completa
                        // Si solo se modificaron tags de paciente, actualizar solo el estudio
                        if (result.original_study_id && result.study_id !== result.original_study_id) {
                            console.log('📝 Se creó un nuevo estudio, recargando lista completa...');
                            // Recargar estudios para mostrar el nuevo estudio (el original ya fue eliminado)
                            await this.loadStudies();
                        } else {
                            // Actualizar solo el estudio editado en la lista (sin recargar toda la lista)
                            const finalStudyId = result.study_id || studyId;
                            await this.updateEditedStudy(finalStudyId);
                        }
                    }
                } else {
                    const errorMsg = result.error || 'Error al actualizar estudio';
                    console.error('❌ [PACS_MANAGER] Error del servidor:', errorMsg);
                    
                    // Actualizar trabajo con error
                    this.updateJob(job.id, {
                        status: 'error',
                        error: errorMsg
                    });
                    
                    throw new Error(errorMsg);
                }

                // Restaurar botón
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            } catch (error) {
                clearTimeout(timeoutId);
                
                console.error('Error guardando estudio:', error);
                
                // Actualizar trabajo con error
                this.updateJob(job.id, {
                    status: 'error',
                    error: error.message
                });
                
                let errorMessage = 'Error al guardar: ' + error.message;
                if (error.name === 'AbortError') {
                    errorMessage = 'La operación tardó demasiado tiempo. El proceso puede estar completándose en segundo plano. Por favor, espera unos momentos y recarga la página para verificar si los cambios se aplicaron.';
                }
                
                this.showError(errorMessage);
                
                // Restaurar botón
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar Cambios';
                }
            }
        } catch (error) {
            console.error('Error guardando estudio (catch externo):', error);
            
            this.showError('Error al guardar: ' + error.message);
            
            // Restaurar botón
            const saveBtn = document.getElementById('saveStudyBtn');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar Cambios';
            }
        }
    }

    /**
     * Muestra el modal de confirmación para eliminar un estudio
     */
    async deleteStudy(studyId, patientName) {
        const study = this.studies.find(s => s.study_id === studyId);

        this.pendingDelete = {
            studyId: studyId,
            patientName: patientName,
            studyInstanceUid: study ? (study.study_instance_uid || null) : null,
            patientId: study ? (study.patient_id || null) : null,
            deleteImpact: null,
            requiresAcknowledgement: false
        };

        const patientNameElement = document.getElementById('deletePatientName');
        if (patientNameElement) {
            patientNameElement.textContent = patientName;
        }

        this.resetDeleteImpactModal();

        if (this.deleteModal) {
            this.deleteModal.show();
        }

        await this.loadDeleteImpact(studyId, this.pendingDelete.studyInstanceUid);
    }

    resetDeleteImpactModal() {
        const panel = document.getElementById('deleteImpactPanel');
        const loading = document.getElementById('deleteImpactLoading');
        const content = document.getElementById('deleteImpactContent');
        const ackWrap = document.getElementById('deleteImpactAckWrap');
        const ack = document.getElementById('deleteImpactAcknowledge');
        const confirmBtn = document.getElementById('confirmDeleteBtn');

        if (panel) panel.classList.remove('d-none');
        if (loading) loading.classList.remove('d-none');
        if (content) {
            content.classList.add('d-none');
            content.innerHTML = '';
        }
        if (ackWrap) ackWrap.classList.add('d-none');
        if (ack) ack.checked = false;
        if (confirmBtn) confirmBtn.disabled = true;
    }

    async loadDeleteImpact(studyId, studyInstanceUid) {
        const loading = document.getElementById('deleteImpactLoading');
        const content = document.getElementById('deleteImpactContent');
        const confirmBtn = document.getElementById('confirmDeleteBtn');

        try {
            let url = `${this.apiBaseUrl}delete-impact.php?study_id=${encodeURIComponent(studyId)}`;
            if (studyInstanceUid) {
                url += `&study_instance_uid=${encodeURIComponent(studyInstanceUid)}`;
            }
            const response = await fetch(url);
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'No se pudo analizar el impacto');
            }

            if (this.pendingDelete && this.pendingDelete.studyId === studyId) {
                this.pendingDelete.deleteImpact = result;
                this.pendingDelete.requiresAcknowledgement = !!result.requires_acknowledgement;
            }

            this.renderDeleteImpact(result);
        } catch (error) {
            console.error('Error cargando impacto de eliminación:', error);
            if (content) {
                content.classList.remove('d-none');
                content.innerHTML = `
                    <div class="alert alert-warning mb-0" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No se pudo verificar informes/audios vinculados. Proceda con precaución.
                    </div>`;
            }
            if (this.pendingDelete) {
                this.pendingDelete.requiresAcknowledgement = true;
            }
            const ackWrap = document.getElementById('deleteImpactAckWrap');
            if (ackWrap) ackWrap.classList.remove('d-none');
        } finally {
            if (loading) loading.classList.add('d-none');
            if (confirmBtn && this.pendingDelete && !this.pendingDelete.requiresAcknowledgement) {
                confirmBtn.disabled = false;
            }
        }
    }

    renderDeleteImpact(result) {
        const content = document.getElementById('deleteImpactContent');
        const ackWrap = document.getElementById('deleteImpactAckWrap');
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        if (!content) return;

        const severity = result.severity || 'none';
        const impact = result.impact || {};
        const informes = impact.informes || {};
        const audios = impact.audios || {};
        const messages = result.messages || [];

        let alertClass = 'alert-info';
        if (severity === 'high') alertClass = 'alert-danger';
        else if (severity === 'medium') alertClass = 'alert-warning';
        else if (severity === 'none') alertClass = 'alert-success';

        let html = `<div class="alert ${alertClass} mb-2" role="alert">`;

        if (severity === 'none') {
            html += '<i class="fas fa-check-circle me-2"></i><strong>Sin informes ni audios vinculados</strong> en la plataforma para este estudio.';
        } else {
            html += '<i class="fas fa-link me-2"></i><strong>Contenido vinculado en la plataforma</strong><ul class="mb-0 mt-2 ps-3">';
            messages.forEach(msg => {
                html += `<li>${this.escapeHtml(msg)}</li>`;
            });
            html += '</ul>';
        }
        html += '</div>';

        if ((informes.items || []).length > 0) {
            html += '<div class="small mb-2"><strong>Informes:</strong><ul class="mb-0 ps-3">';
            informes.items.slice(0, 8).forEach(inf => {
                const pacsBadge = inf.en_pacs ? ' <span class="badge bg-warning text-dark">en PACS</span>' : '';
                html += `<li>#${inf.id} — ${this.escapeHtml(inf.estado || 'N/A')}${pacsBadge}</li>`;
            });
            if (informes.items.length > 8) {
                html += `<li class="text-muted">… y ${informes.items.length - 8} más</li>`;
            }
            html += '</ul></div>';
        }

        if ((audios.items || []).length > 0) {
            html += '<div class="small mb-0"><strong>Audios:</strong><ul class="mb-0 ps-3">';
            audios.items.slice(0, 5).forEach(aud => {
                const infRef = aud.informe_id ? ` (informe #${aud.informe_id})` : '';
                html += `<li>#${aud.id}${infRef} — ${this.escapeHtml(aud.estado || 'N/A')}</li>`;
            });
            if (audios.items.length > 5) {
                html += `<li class="text-muted">… y ${audios.items.length - 5} más</li>`;
            }
            html += '</ul></div>';
        }

        content.innerHTML = html;
        content.classList.remove('d-none');

        if (result.requires_acknowledgement) {
            if (ackWrap) ackWrap.classList.remove('d-none');
            if (confirmBtn) confirmBtn.disabled = true;
            const ack = document.getElementById('deleteImpactAcknowledge');
            if (ack && !ack._boundDeleteAck) {
                ack._boundDeleteAck = true;
                ack.addEventListener('change', () => {
                    const btn = document.getElementById('confirmDeleteBtn');
                    if (btn) btn.disabled = !ack.checked;
                });
            }
        } else if (confirmBtn) {
            confirmBtn.disabled = false;
        }
    }

    /**
     * Confirma y ejecuta la eliminación del estudio
     */
    async confirmDelete() {
        if (!this.pendingDelete) {
            return;
        }

        const { studyId, patientName, studyInstanceUid, patientId, requiresAcknowledgement } = this.pendingDelete;

        if (requiresAcknowledgement) {
            const ack = document.getElementById('deleteImpactAcknowledge');
            if (!ack || !ack.checked) {
                this.showError('Debe confirmar que entiende el impacto sobre informes y audios antes de eliminar.');
                return;
            }
        }

        this.pendingDelete = null;

        // Cerrar el modal
        if (this.deleteModal) {
            this.deleteModal.hide();
        }

        // Deshabilitar todos los botones de eliminar para evitar múltiples clics
        const deleteButtons = document.querySelectorAll('.btn-delete-study');
        deleteButtons.forEach(btn => {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        });

        // Obtener datos del estudio para el trabajo
        const study = this.studies.find(s => s.study_id === studyId);
        
        // Crear trabajo ANTES de enviar
        const job = this.createJob('delete', studyId, {
            patientName: patientName || (study ? study.patient_name : 'N/A'),
            patientId: study ? study.patient_id : 'N/A',
            studyDescription: study ? study.study_description : 'N/A'
        });

        // Mostrar indicador de carga
        this.showLoading();
        const loadingMessage = this.showInfo('Eliminando estudio... Esto puede tardar unos momentos.');

        // Crear un AbortController para manejar timeout extendido (estudios grandes pueden tardar varios minutos)
        const controller = new AbortController();
        let timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutos timeout

        try {
            const response = await fetch(this.apiBaseUrl + 'delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    study_id: studyId,
                    study_instance_uid: studyInstanceUid || undefined,
                    patient_id: patientId || undefined,
                    patient_name: patientName || undefined
                }),
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            // Verificar si la respuesta es JSON válido
            let result;
            const contentType = response.headers.get('content-type') || '';
            const responseText = await response.text();
            
            console.log('[DELETE] Content-Type:', contentType);
            console.log('[DELETE] Response status:', response.status);
            console.log('[DELETE] Response text (first 500 chars):', responseText.substring(0, 500));
            
            if (contentType.includes('application/json')) {
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('[DELETE] Error parseando JSON:', e);
                    console.error('[DELETE] Respuesta completa:', responseText);
                    throw new Error(`Error del servidor (${response.status}): Respuesta no es JSON válido. ${responseText.substring(0, 300)}`);
                }
            } else {
                // Si no es JSON, leer como texto para debugging
                console.error('[DELETE] Respuesta no JSON recibida. Content-Type:', contentType);
                console.error('[DELETE] Respuesta completa:', responseText);
                throw new Error(`Error del servidor (${response.status}): ${responseText.substring(0, 500)}`);
            }

            // Verificar código HTTP
            if (!response.ok) {
                const errorMsg = result.error || result.message || `Error del servidor (${response.status})`;
                console.error('[DELETE] Error HTTP:', response.status);
                console.error('[DELETE] Error details:', result);
                
                // Actualizar trabajo con error
                this.updateJob(job.id, {
                    status: 'error',
                    error: errorMsg
                });
                
                throw new Error(errorMsg);
            }

            if (result.success) {
                // Ocultar mensaje de carga
                if (loadingMessage) loadingMessage.remove();
                
                // Verificar si es modo asíncrono
                if (result.async && result.job_id) {
                    // Modo asíncrono
                    this.updateJob(job.id, {
                        jobId: result.job_id,
                        status: 'running'
                    });
                    
                    // Iniciar polling
                    this.startJobPolling(job);
                    
                    // Mostrar notificación
                    this.showSuccess('Eliminación iniciada. Procesando en segundo plano...');
                    
                } else {
                    // Modo síncrono (fallback)
                    console.log('✅ Eliminación completada en modo síncrono, actualizando trabajo:', job.id);
                    this.updateJob(job.id, {
                        status: 'success'
                    });
                    
                    // Forzar actualización de contadores después de un pequeño delay
                    setTimeout(() => {
                        this.updateJobsCounters();
                    }, 100);
                    
                    this.showSuccess('Estudio eliminado exitosamente' + (result.pacs_refs_cleared > 0
                        ? ` (refs PACS limpiadas en ${result.pacs_refs_cleared} informe(s))`
                        : ''));
                    // Actualizar caché después de eliminar
                    this.saveToCache();
                    
                    await this.loadStudies();
                }
            } else {
                // Actualizar trabajo con error
                this.updateJob(job.id, {
                    status: 'error',
                    error: result.error || 'Error al eliminar estudio'
                });
                
                throw new Error(result.error || 'Error al eliminar estudio');
            }
        } catch (error) {
            console.error('Error eliminando estudio:', error);
            // Limpiar timeout si aún está activo
            if (timeoutId) clearTimeout(timeoutId);
            
            // Ocultar mensaje de carga
            if (loadingMessage) loadingMessage.remove();

            // Si el cliente abortó por timeout, verificar si el backend ya eliminó el estudio
            if (error.name === 'AbortError') {
                const deletedDespiteAbort = await this.verifyStudyDeleted(studyId);
                if (deletedDespiteAbort) {
                    this.updateJob(job.id, { status: 'success' });
                    setTimeout(() => this.updateJobsCounters(), 100);
                    this.showSuccess('Estudio eliminado exitosamente (verificado tras espera prolongada)');
                    this.saveToCache();
                    await this.loadStudies();
                    return;
                }
            }
            
            // Mensaje de error más descriptivo
            let errorMessage = 'Error al eliminar estudio';
            if (error.name === 'AbortError') {
                errorMessage = 'La operación tardó demasiado tiempo. El estudio puede estar siendo eliminado. Por favor, espera unos momentos y recarga la página.';
            } else if (error.message) {
                errorMessage = error.message;
            } else if (error instanceof TypeError && error.message.includes('fetch')) {
                errorMessage = 'Error de conexión. Verifica tu conexión a internet o contacta al administrador.';
            }

            // Actualizar trabajo con error si no se actualizó antes
            if (job && job.status !== 'error') {
                this.updateJob(job.id, {
                    status: 'error',
                    error: errorMessage
                });
            }
            
            this.showError(errorMessage);
        } finally {
            // Re-habilitar botones (aunque se recargará la tabla después)
            deleteButtons.forEach(btn => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash"></i>';
            });
        }
    }

    /**
     * Verifica si un estudio ya no existe en Orthanc (p. ej. tras timeout del cliente).
     */
    async verifyStudyDeleted(studyId) {
        try {
            const response = await fetch(`/api/get_study_details.php?study_id=${encodeURIComponent(studyId)}`);
            const result = await response.json();
            if (result.success) {
                return false;
            }
            const err = (result.error || '').toLowerCase();
            return err.includes('404') || err.includes('not found') || err.includes('no se pudieron obtener');
        } catch (e) {
            return false;
        }
    }

    /**
     * Actualiza los contadores
     */
    updateCounters() {
        const totalEstudios = document.getElementById('totalEstudios');
        if (totalEstudios) {
            totalEstudios.textContent = this.filteredStudies.length;
        }
        this.updateModalityCounter();

        const studiesCount = document.getElementById('studiesCount');
        if (studiesCount) {
            studiesCount.textContent = `${this.filteredStudies.length} estudios`;
        }
        this.updateHeaderModalityCounts();
    }

    /**
     * Muestra estado de carga
     */
    showLoading() {
        const tbody = document.getElementById('studiesTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando estudios...</span>
                        </div>
                        <div class="mt-2">Consultando estudios desde PACS...</div>
                    </td>
                </tr>
            `;
        }
    }

    /**
     * Muestra estado vacío (sin estudios cargados)
     */
    showEmptyState() {
        const tbody = document.getElementById('studiesTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-search fa-2x text-muted mb-3"></i>
                        <div class="text-muted">Selecciona filtros y haz clic en "Buscar" para consultar estudios desde PACS</div>
                    </td>
                </tr>
            `;
        }
        this.updateCounters();
    }

    /**
     * Muestra mensaje de error
     */
    showError(message) {
        // Crear alerta temporal
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
        alert.style.zIndex = '9999';
        alert.innerHTML = `
            <i class="fas fa-exclamation-circle me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);

        setTimeout(() => {
            alert.remove();
        }, 5000);
    }

    /**
     * Muestra mensaje de éxito
     */
    showSuccess(message) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
        alert.style.zIndex = '9999';
        alert.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);

        setTimeout(() => {
            alert.remove();
        }, 3000);
    }

    /**
     * Muestra mensaje informativo
     */
    showInfo(message) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-info alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
        alert.style.zIndex = '9999';
        alert.innerHTML = `
            <i class="fas fa-info-circle me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);
        return alert; // Retornar para poder eliminarlo después
    }

    /**
     * Formatea fecha DICOM (YYYYMMDD) a formato legible
     */
    formatDate(dateStr) {
        if (!dateStr || dateStr.length !== 8) return 'N/A';
        const year = dateStr.substring(0, 4);
        const month = dateStr.substring(4, 6);
        const day = dateStr.substring(6, 8);
        return `${day}/${month}/${year}`;
    }

    /**
     * Formatea hora DICOM (HHMMSS) a formato legible
     */
    formatTime(timeStr) {
        if (!timeStr || timeStr.length < 4) return 'N/A';
        const hour = timeStr.substring(0, 2);
        const minute = timeStr.substring(2, 4);
        return `${hour}:${minute}`;
    }

    /**
     * Escapa HTML para prevenir XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Muestra información detallada de un estudio
     */
    async showStudyInfo(studyId) {
        const study = this.studies.find(s => s.study_id === studyId);
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

        // Verificar si los detalles ya están cargados
        let currentModality = study.modality || 'No disponible';
        let currentInstancesCount = study.instances_count || 0;

        // Si instances_count es 0, intentar cargar los detalles bajo demanda
        if (currentInstancesCount === 0) {
            try {
                // Construir URL con seriesIds si están disponibles
                let url = `${this.apiBaseUrl.replace('pacs-manager/', '')}get_study_details.php?studyId=${encodeURIComponent(studyId)}`;
                if (study._series_ids && Array.isArray(study._series_ids) && study._series_ids.length > 0) {
                    const seriesIdsParam = encodeURIComponent(JSON.stringify(study._series_ids));
                    url += `&seriesIds=${seriesIdsParam}`;
                }
                
                // Obtener detalles del estudio desde la API
                const response = await fetch(url);
                if (response.ok) {
                    const result = await response.json();
                    if (result.success && result.data) {
                        currentModality = result.data.modality || currentModality;
                        currentInstancesCount = result.data.instances_count || currentInstancesCount;
                        
                        // Actualizar el estudio en memoria para futuras consultas
                        study.modality = currentModality;
                        study.instances_count = currentInstancesCount;
                    }
                }
            } catch (error) {
                console.error('Error cargando detalles del estudio:', error);
                // Continuar con los datos disponibles
            }
        }

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
                                        <strong>Nombre:</strong> ${this.escapeHtml(study.patient_name || 'No disponible')}
                                    </div>
                                    <div class="mb-2">
                                        <strong>ID:</strong> ${this.escapeHtml(study.patient_id || 'No disponible')}
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
                                        <strong>Fecha:</strong> ${this.formatDate(study.study_date)}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Hora:</strong> ${this.formatTime(study.study_time || '')}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Modalidad:</strong> ${this.escapeHtml(currentModality)}
                                    </div>
                                    <div class="mb-3">
                                        <strong>Descripción:</strong> ${this.escapeHtml(study.study_description || 'No disponible')}
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-clipboard-list me-2"></i>Información Clínica
                                    </h6>
                                    <div class="mb-2">
                                        <strong>Número de acceso:</strong> ${this.escapeHtml(study.accession_number || 'No disponible')}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Institución:</strong> ${this.escapeHtml(study.institution_name || 'No disponible')}
                                    </div>
                                    <div class="mb-3">
                                        <strong>Médico referente:</strong> ${this.escapeHtml(study.referring_physician_name || 'No disponible')}
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
                                        <strong>Número de imágenes:</strong> ${currentInstancesCount}
                                    </div>
                                    <div class="mb-2">
                                        <strong>ID TJS PACS:</strong> 
                                        <code class="small">${this.escapeHtml(study.study_id)}</code>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Study UID:</strong> 
                                        <code class="small">${this.escapeHtml(study.study_instance_uid || 'N/A')}</code>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            ${study.viewer_url ? `
                                <button type="button" class="btn btn-primary" onclick="window.open('${this.escapeHtml(study.viewer_url)}', '_blank')">
                                    <i class="fas fa-eye me-2"></i>Ver Estudio
                                </button>
                            ` : ''}
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
     * Obtiene la URL de descarga desde la configuración
     */
    async getDownloadUrl() {
        try {
            // Intentar obtener desde caché si existe
            if (this._downloadUrlCache) {
                return this._downloadUrlCache;
            }
            
            // Obtener desde la API
            const apiBaseUrl = this.apiBaseUrl.replace('pacs-manager/', '');
            const response = await fetch(`${apiBaseUrl}config/get-download-url.php`, {
                method: 'GET',
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`Error obteniendo URL de descargas: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success && data.download_url) {
                // Guardar en caché
                this._downloadUrlCache = data.download_url;
                return data.download_url;
            } else {
                // Fallback a URL por defecto
                const fallbackUrl = 'https://demoportal.tanjousoft.com.ar/visorweb';
                this._downloadUrlCache = fallbackUrl;
                return fallbackUrl;
            }
        } catch (error) {
            console.error('Error obteniendo URL de descargas:', error);
            // Fallback a URL por defecto
            const fallbackUrl = 'https://demoportal.tanjousoft.com.ar/visorweb';
            this._downloadUrlCache = fallbackUrl;
            return fallbackUrl;
        }
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
            return `study_${study.id || study.orthanc_study_id || 'unknown'}.zip`;
        }
        
        return `${parts.join('-')}.zip`;
    }

    /**
     * Descarga un estudio desde Orthanc
     */
    async downloadStudy(orthancStudyId) {
        try {
            // Validar que tenemos un ID válido
            if (!orthancStudyId || orthancStudyId.trim() === '') {
                console.error('❌ No se proporcionó ID de Orthanc para descargar');
                alert('Error: No se pudo obtener el ID del estudio en Orthanc. El estudio puede haber sido eliminado del servidor PACS.');
                return;
            }
            
            console.log('📥 Iniciando descarga del estudio desde Orthanc:', orthancStudyId);
            
            // Mostrar indicador de carga
            const downloadBtn = document.querySelector(`button[data-orthanc-study-id="${orthancStudyId}"], button[onclick*="${orthancStudyId}"], .btn-download-study[data-study-id="${orthancStudyId}"]`);
            if (downloadBtn) {
                // Guardar HTML original en un atributo para poder restaurarlo
                if (!downloadBtn.getAttribute('data-original-html')) {
                    downloadBtn.setAttribute('data-original-html', downloadBtn.innerHTML);
                }
                downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span class="d-none d-md-inline ms-1">Descargando...</span>';
                downloadBtn.disabled = true;
            }
            
            // Buscar el estudio en el array de estudios para obtener información
            const study = this.studies.find(s => 
                (s.orthanc_study_id && s.orthanc_study_id === orthancStudyId) || 
                (s.id && s.id === orthancStudyId) ||
                (s.study_id && s.study_id === orthancStudyId)
            );
            
            // Construir nombre del archivo
            let filename = `study_${orthancStudyId}.zip`;
            if (study) {
                filename = this.buildDownloadFilename(study);
            }
            
            let downloadUrl;
            if (study && study.download_url) {
                downloadUrl = study.download_url;
            } else {
                const orthancBaseUrl = await this.getDownloadUrl();
                downloadUrl = `${orthancBaseUrl}/studies/${orthancStudyId}/archive?filename=${encodeURIComponent(filename)}`;
            }
            
            console.log('🔗 URL de descarga configurada:', downloadUrl);
            
            // Crear enlace temporal para descarga (método simple como en PORTAL_ESTUDIOS)
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            
            // Ejecutar descarga
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            console.log('✅ Descarga iniciada para estudio:', orthancStudyId);
            
            // Restaurar botón después de un tiempo
            setTimeout(() => {
                if (downloadBtn) {
                    const originalHTML = downloadBtn.getAttribute('data-original-html');
                    if (originalHTML) {
                        downloadBtn.innerHTML = originalHTML;
                        downloadBtn.removeAttribute('data-original-html');
                    } else {
                        downloadBtn.innerHTML = '<i class="fas fa-download"></i> <span class="d-none d-md-inline ms-1">Descargar</span>';
                    }
                    downloadBtn.disabled = false;
                }
            }, 3000);
            
        } catch (error) {
            console.error('❌ Error descargando estudio:', error);
            alert('Error al descargar el estudio. Verifique la conexión con Orthanc o que el estudio aún exista en el servidor PACS.');
            
            // Restaurar botón en caso de error
            const downloadBtn = document.querySelector(`button[data-orthanc-study-id="${orthancStudyId}"], button[onclick*="${orthancStudyId}"], .btn-download-study[data-study-id="${orthancStudyId}"]`);
            if (downloadBtn) {
                const originalHTML = downloadBtn.getAttribute('data-original-html');
                if (originalHTML) {
                    downloadBtn.innerHTML = originalHTML;
                    downloadBtn.removeAttribute('data-original-html');
                } else {
                    downloadBtn.innerHTML = '<i class="fas fa-download"></i> <span class="d-none d-md-inline ms-1">Descargar</span>';
                }
                downloadBtn.disabled = false;
            }
        }
    }

    /**
     * Actualiza los filtros desde el formulario
     */
    updateFiltersFromForm() {
        const dateFrom = document.getElementById('dateFrom')?.value || '';
        const dateTo = document.getElementById('dateTo')?.value || '';
        const patientId = document.getElementById('patientId')?.value || '';
        
        this.currentFilters.dateFrom = dateFrom;
        this.currentFilters.dateTo = dateTo;
        this.currentFilters.patientId = patientId;
    }

    /**
     * Guarda el estado actual en localStorage
     */
    saveToCache() {
        try {
            // Obtener ID del estudio seleccionado antes de guardar
            const selectedRow = document.querySelector('#studiesTableBody tr.selected');
            const selectedStudyId = selectedRow ? selectedRow.getAttribute('data-study-id') : (this.savedSelectedStudyId || null);
            
            const state = {
                studies: this.studies,
                filters: {
                    dateFrom: this.currentFilters.dateFrom,
                    dateTo: this.currentFilters.dateTo,
                    patientId: this.currentFilters.patientId
                    // No guardamos search ni modalities porque son filtros locales
                },
                sortConfig: { ...this.sortConfig }, // Guardar configuración de ordenamiento
                selectedStudyId: selectedStudyId, // Guardar selección
                timestamp: Date.now()
            };
            localStorage.setItem(this.storageKey, JSON.stringify(state));
            console.log('💾 Estado guardado en localStorage:', this.studies.length, 'estudios');
        } catch (error) {
            console.error('Error guardando en localStorage:', error);
            // Si localStorage está lleno, intentar limpiar y guardar de nuevo
            try {
                localStorage.removeItem(this.storageKey);
                const selectedRow = document.querySelector('#studiesTableBody tr.selected');
                const selectedStudyId = selectedRow ? selectedRow.getAttribute('data-study-id') : (this.savedSelectedStudyId || null);
                localStorage.setItem(this.storageKey, JSON.stringify({
                    studies: this.studies,
                    filters: {
                        dateFrom: this.currentFilters.dateFrom,
                        dateTo: this.currentFilters.dateTo,
                        patientId: this.currentFilters.patientId
                    },
                    sortConfig: { ...this.sortConfig }, // Guardar configuración de ordenamiento
                    selectedStudyId: selectedStudyId, // Guardar selección
                    timestamp: Date.now()
                }));
            } catch (e) {
                console.error('Error al intentar limpiar y guardar caché:', e);
            }
        }
    }

    /**
     * Carga el estado desde localStorage
     */
    loadFromCache() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            if (!stored) return null;

            const state = JSON.parse(stored);
            
            // Verificar que el estado no sea muy antiguo (24 horas, igual que dashboard-unified)
            const maxAge = 24 * 60 * 60 * 1000; // 24 horas en milisegundos
            if (Date.now() - state.timestamp > maxAge) {
                console.log('Caché expirado, limpiando...');
                this.clearCache();
                return null;
            }

            return state;
        } catch (error) {
            console.error('Error cargando desde localStorage:', error);
            return null;
        }
    }

    /**
     * Verifica si los filtros actuales coinciden con los del caché
     */
    filtersMatch(cachedFilters) {
        return (
            (this.currentFilters.dateFrom || '') === (cachedFilters.dateFrom || '') &&
            (this.currentFilters.dateTo || '') === (cachedFilters.dateTo || '') &&
            (this.currentFilters.patientId || '') === (cachedFilters.patientId || '')
        );
    }

    /**
     * Restaura los valores del formulario desde los filtros guardados
     */
    restoreFormValues() {
        if (this.currentFilters.dateFrom) {
            const dateFromInput = document.getElementById('dateFrom');
            if (dateFromInput) dateFromInput.value = this.currentFilters.dateFrom;
        }
        if (this.currentFilters.dateTo) {
            const dateToInput = document.getElementById('dateTo');
            if (dateToInput) dateToInput.value = this.currentFilters.dateTo;
        }
        if (this.currentFilters.patientId) {
            const patientIdInput = document.getElementById('patientId');
            if (patientIdInput) patientIdInput.value = this.currentFilters.patientId;
        }
    }

    /**
     * Limpia el caché
     */
    clearCache() {
        try {
            localStorage.removeItem(this.storageKey);
            console.log('🗑️ Caché limpiado');
        } catch (error) {
            console.error('Error limpiando caché:', error);
        }
    }
    
    /**
     * Abre el modal de subida de estudios
     */
    openUploadModal() {
        if (this.uploadModal) {
            // Resetear el modal
            const uploadFilesInput = document.getElementById('uploadFilesInput');
            const uploadFilesList = document.getElementById('uploadFilesList');
            const uploadProgress = document.getElementById('uploadProgress');
            const uploadResults = document.getElementById('uploadResults');
            const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
            
            if (uploadFilesInput) uploadFilesInput.value = '';
            if (uploadFilesList) uploadFilesList.style.display = 'none';
            if (uploadProgress) uploadProgress.style.display = 'none';
            if (uploadResults) uploadResults.style.display = 'none';
            if (uploadSubmitBtn) {
                uploadSubmitBtn.disabled = true;
                uploadSubmitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Subir Archivos';
                uploadSubmitBtn.dataset.action = 'upload'; // Resetear acción
            }
            
            // Resetear flag de subida
            this.isUploading = false;
            
            this.uploadModal.show();
        }
    }
    
    /**
     * Maneja la selección de archivos
     */
    handleFileSelection(event) {
        const files = event.target.files;
        const uploadFilesList = document.getElementById('uploadFilesList');
        const uploadFilesListItems = document.getElementById('uploadFilesListItems');
        const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
        
        if (files.length === 0) {
            if (uploadFilesList) uploadFilesList.style.display = 'none';
            if (uploadSubmitBtn) uploadSubmitBtn.disabled = true;
            return;
        }
        
        // Mostrar lista de archivos
        if (uploadFilesList) uploadFilesList.style.display = 'block';
        if (uploadFilesListItems) {
            uploadFilesListItems.innerHTML = '';
            Array.from(files).forEach((file, index) => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';
                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                li.innerHTML = `
                    <div>
                        <i class="fas fa-file me-2"></i>
                        <strong>${file.name}</strong>
                        <small class="text-muted ms-2">(${sizeMB} MB)</small>
                    </div>
                `;
                uploadFilesListItems.appendChild(li);
            });
        }
        
        if (uploadSubmitBtn) uploadSubmitBtn.disabled = false;
    }
    
    /**
     * Maneja la subida de archivos
     */
    async handleUpload() {
        // Prevenir múltiples llamadas simultáneas
        if (this.isUploading) {
            console.log('⚠️ Subida ya en progreso, ignorando llamada');
            return;
        }
        
        const uploadFilesInput = document.getElementById('uploadFilesInput');
        if (!uploadFilesInput || !uploadFilesInput.files || uploadFilesInput.files.length === 0) {
            this.showError('No se seleccionaron archivos');
            return;
        }
        
        this.isUploading = true;
        
        const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
        const uploadProgress = document.getElementById('uploadProgress');
        const uploadProgressBar = document.getElementById('uploadProgressBar');
        const uploadStatus = document.getElementById('uploadStatus');
        const uploadResults = document.getElementById('uploadResults');
        const uploadResultsContent = document.getElementById('uploadResultsContent');
        
        // Deshabilitar botón y mostrar progreso
        if (uploadSubmitBtn) uploadSubmitBtn.disabled = true;
        if (uploadProgress) uploadProgress.style.display = 'block';
        if (uploadProgressBar) {
            uploadProgressBar.style.width = '0%';
            uploadProgressBar.textContent = '0%';
        }
        if (uploadStatus) uploadStatus.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Subiendo archivos...';
        if (uploadResults) uploadResults.style.display = 'none';
        
        try {
            const files = uploadFilesInput.files;
            
            // Actualizar mensaje cuando la transferencia HTTP completa pero aún se procesa
            const originalOnProgress = this.uploader.onUploadProgress;
            let lastProgressUpdate = 0;
            this.uploader.onUploadProgress = (progress, result) => {
                // Llamar al callback original
                if (originalOnProgress) {
                    originalOnProgress(progress, result);
                }
                
                // Actualizar mensaje según el progreso (con throttling para evitar actualizaciones muy rápidas)
                const now = Date.now();
                if (now - lastProgressUpdate < 200) return; // Actualizar máximo cada 200ms
                lastProgressUpdate = now;
                
                if (uploadStatus) {
                    if (progress >= 100) {
                        uploadStatus.innerHTML = '<i class="fas fa-cog fa-spin me-2"></i>Procesando en Orthanc...';
                    } else if (progress >= 90) {
                        uploadStatus.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Finalizando subida... (${progress}%)`;
                    } else {
                        uploadStatus.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Subiendo archivos... (${progress}%)`;
                    }
                }
            };
            
            const result = await this.uploader.uploadFiles(files);
            
            // Restaurar callback original
            this.uploader.onUploadProgress = originalOnProgress;
            
            // Actualizar progreso a 100%
            if (uploadProgressBar) {
                uploadProgressBar.style.width = '100%';
                uploadProgressBar.textContent = '100%';
                uploadProgressBar.classList.remove('progress-bar-animated');
            }
            
            // Actualizar mensaje cuando completa (esto se ejecuta después de que la subida termina)
            if (uploadStatus) {
                if (result.success) {
                    uploadStatus.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Subida completada exitosamente';
                } else {
                    uploadStatus.innerHTML = '<i class="fas fa-exclamation-triangle text-warning me-2"></i>Subida completada con algunos errores';
                }
            }
            
            // Mostrar resultados
            if (uploadResults) uploadResults.style.display = 'block';
            if (uploadResultsContent) {
                let html = '';
                
                // Mostrar resumen del estudio si está disponible
                const successfulUploads = result.results.filter(r => r.success && r.study_details);
                if (successfulUploads.length > 0) {
                    // Agrupar por study_id (puede haber múltiples archivos del mismo estudio)
                    const studiesMap = new Map();
                    successfulUploads.forEach(fileResult => {
                        if (fileResult.study_details) {
                            const studyId = fileResult.study_details.study_id;
                            if (!studiesMap.has(studyId)) {
                                studiesMap.set(studyId, fileResult.study_details);
                            }
                        }
                    });
                    
                    // Mostrar resumen de cada estudio
                    studiesMap.forEach((studyDetails, studyId) => {
                        const patient = studyDetails.patient || {};
                        const formatDate = (dateStr) => {
                            if (!dateStr || dateStr.length !== 8) return dateStr;
                            return `${dateStr.substring(0,4)}-${dateStr.substring(4,6)}-${dateStr.substring(6,8)}`;
                        };
                        
                        html += `
                            <div class="card mb-3 border-success">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0">
                                        <i class="fas fa-check-circle me-2"></i>
                                        Estudio Subido Exitosamente
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="text-primary mb-3">
                                                <i class="fas fa-user me-2"></i>Datos del Paciente
                                            </h6>
                                            <table class="table table-sm table-borderless mb-0">
                                                <tr>
                                                    <td class="fw-bold" style="width: 40%;">Nombre:</td>
                                                    <td>${(patient.patient_name || 'N/A').replace(/\^/g, ' ').trim()}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">ID Paciente:</td>
                                                    <td>${patient.patient_id || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Fecha Nacimiento:</td>
                                                    <td>${formatDate(patient.patient_birth_date) || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Sexo:</td>
                                                    <td>${patient.patient_sex || 'N/A'}</td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="text-primary mb-3">
                                                <i class="fas fa-file-medical me-2"></i>Datos del Estudio
                                            </h6>
                                            <table class="table table-sm table-borderless mb-0">
                                                <tr>
                                                    <td class="fw-bold" style="width: 40%;">Descripción:</td>
                                                    <td>${studyDetails.study_description || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Fecha Estudio:</td>
                                                    <td>${formatDate(studyDetails.study_date) || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Número Acceso:</td>
                                                    <td>${studyDetails.accession_number || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Institución:</td>
                                                    <td>${studyDetails.institution_name || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Médico Ref.:</td>
                                                    <td>${studyDetails.referring_physician || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Series:</td>
                                                    <td>${studyDetails.series_count || 0}</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Instancias:</td>
                                                    <td>${studyDetails.instances_count || 0}</td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-12">
                                            <small class="text-muted">
                                                <strong>Study ID:</strong> ${studyId}<br>
                                                <strong>Study Instance UID:</strong> ${studyDetails.study_instance_uid || 'N/A'}
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                }
                
                // Mostrar lista de archivos procesados
                html += '<h6 class="mt-3 mb-2">Archivos Procesados:</h6>';
                html += '<div class="list-group">';
                result.results.forEach((fileResult, index) => {
                    const icon = fileResult.success ? 'check-circle text-success' : 'times-circle text-danger';
                    const badge = fileResult.success ? 'success' : 'danger';
                    html += `
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-${icon} me-2"></i>
                                    <strong>${fileResult.file_name}</strong>
                                </div>
                                <span class="badge bg-${badge}">${fileResult.success ? 'Éxito' : 'Error'}</span>
                            </div>
                            ${!fileResult.success ? `
                                <small class="text-danger mt-2 d-block">${fileResult.error || 'Error desconocido'}</small>
                            ` : ''}
                        </div>
                    `;
                });
                html += '</div>';
                
                uploadResultsContent.innerHTML = html;
            }
            
            // Si todos fueron exitosos, no cerrar automáticamente para que el usuario vea el resumen
            if (result.success) {
                if (uploadStatus) {
                    uploadStatus.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Todos los archivos se subieron exitosamente';
                }
                // Cambiar texto del botón a "Cerrar" y cambiar comportamiento
                if (uploadSubmitBtn) {
                    uploadSubmitBtn.disabled = false;
                    uploadSubmitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Cerrar';
                    // Usar un flag para cambiar el comportamiento del botón
                    uploadSubmitBtn.dataset.action = 'close';
                }
            } else {
                if (uploadStatus) {
                    uploadStatus.innerHTML = `<i class="fas fa-exclamation-triangle text-warning me-2"></i>Subida completada con algunos errores`;
                }
                if (uploadSubmitBtn) uploadSubmitBtn.disabled = false;
            }
            
        } catch (error) {
            console.error('Error al subir archivos:', error);
            if (uploadProgressBar) {
                uploadProgressBar.classList.remove('progress-bar-animated');
                uploadProgressBar.classList.add('bg-danger');
            }
            if (uploadStatus) {
                uploadStatus.innerHTML = `<i class="fas fa-times-circle text-danger me-2"></i>Error: ${error.message}`;
            }
            if (uploadSubmitBtn) uploadSubmitBtn.disabled = false;
        } finally {
            // Resetear flag de subida
            this.isUploading = false;
        }
    }
    
    /**
     * Callback cuando inicia la subida
     */
    onUploadStart(files) {
        console.log('📤 Iniciando subida de', files.length, 'archivo(s)');
    }
    
    /**
     * Callback de progreso de subida
     */
    onUploadProgress(progress, result) {
        const uploadProgressBar = document.getElementById('uploadProgressBar');
        if (uploadProgressBar) {
            uploadProgressBar.style.width = progress + '%';
            uploadProgressBar.textContent = progress + '%';
        }
    }
    
    /**
     * Callback cuando completa la subida
     */
    onUploadComplete(result) {
        console.log('✅ Subida completada:', result);
        
        // Actualizar mensaje cuando realmente completa
        const uploadStatus = document.getElementById('uploadStatus');
        if (uploadStatus) {
            if (result.success) {
                uploadStatus.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>Subida completada exitosamente';
            } else {
                uploadStatus.innerHTML = '<i class="fas fa-exclamation-triangle text-warning me-2"></i>Subida completada con algunos errores';
            }
        }
    }
    
    /**
     * Callback cuando hay error en la subida
     */
    onUploadError(error) {
        console.error('❌ Error en subida:', error);
    }

    // ============================================
    // SISTEMA DE GESTIÓN DE TRABAJOS
    // ============================================

    /**
     * Configura los event listeners para el sistema de trabajos
     */
    setupJobsEventListeners() {
        // Botón de logs
        const jobsLogButton = document.getElementById('jobsLogButton');
        if (jobsLogButton) {
            jobsLogButton.addEventListener('click', () => this.openJobsLogModal());
        }
        
        // Botón limpiar logs
        const clearJobsLogButton = document.getElementById('clearJobsLogButton');
        if (clearJobsLogButton) {
            clearJobsLogButton.addEventListener('click', () => this.clearJobsLog());
        }
        
        // Filtros del modal
        const filterType = document.getElementById('jobsLogFilterType');
        const filterStatus = document.getElementById('jobsLogFilterStatus');
        const filterDateFrom = document.getElementById('jobsLogFilterDateFrom');
        const filterDateTo = document.getElementById('jobsLogFilterDateTo');
        
        if (filterType) {
            filterType.addEventListener('change', () => this.updateJobsLogModal());
        }
        if (filterStatus) {
            filterStatus.addEventListener('change', () => this.updateJobsLogModal());
        }
        if (filterDateFrom) {
            filterDateFrom.addEventListener('change', () => this.updateJobsLogModal());
        }
        if (filterDateTo) {
            filterDateTo.addEventListener('change', () => this.updateJobsLogModal());
        }
        const jobsTodayBtn = document.getElementById('jobsLogFilterTodayBtn');
        if (jobsTodayBtn) {
            jobsTodayBtn.addEventListener('click', () => {
                const today = this._formatDateInput(new Date());
                if (filterDateFrom) filterDateFrom.value = today;
                if (filterDateTo) filterDateTo.value = today;
                this.updateJobsLogModal();
            });
        }
        const jobsWeekBtn = document.getElementById('jobsLogFilterWeekBtn');
        if (jobsWeekBtn) {
            jobsWeekBtn.addEventListener('click', () => {
                const to = new Date();
                const from = new Date();
                from.setDate(from.getDate() - 6);
                if (filterDateFrom) filterDateFrom.value = this._formatDateInput(from);
                if (filterDateTo) filterDateTo.value = this._formatDateInput(to);
                this.updateJobsLogModal();
            });
        }
        const jobsClearDatesBtn = document.getElementById('jobsLogFilterClearDatesBtn');
        if (jobsClearDatesBtn) {
            jobsClearDatesBtn.addEventListener('click', () => {
                if (filterDateFrom) filterDateFrom.value = '';
                if (filterDateTo) filterDateTo.value = '';
                this.updateJobsLogModal();
            });
        }
    }

    /**
     * Crea un nuevo trabajo y lo agrega a la lista
     */
    createJob(type, studyId, studyData, jobId = null) {
        const job = {
            id: `job_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
            type: type, // 'edit' o 'delete'
            status: jobId ? 'running' : 'pending',
            studyId: studyId,
            newStudyId: null,
            jobId: jobId,
            patientName: studyData.patientName || 'N/A',
            patientId: studyData.patientId || 'N/A',
            studyDescription: studyData.studyDescription || 'N/A',
            tagsModified: studyData.tagsModified || {},
            createdAt: new Date().toISOString(),
            completedAt: null,
            duration: null,
            error: null,
            progress: 0,
            note: null
        };
        
        this.jobs.unshift(job); // Agregar al inicio
        this.saveJobsToStorage();
        
        console.log('📝 Trabajo creado:', {
            id: job.id,
            type: type,
            status: job.status,
            totalJobs: this.jobs.length
        });
        
        // Actualizar contadores con un pequeño delay para asegurar que el DOM esté listo
        setTimeout(() => {
            this.updateJobsCounters();
        }, 50);
        
        return job;
    }

    /**
     * Actualiza el estado de un trabajo
     */
    updateJob(jobId, updates) {
        // Buscar trabajo por ID (puede ser job.id o job.jobId)
        const job = this.jobs.find(j => {
            return j.id === jobId || 
                   j.jobId === jobId || 
                   String(j.id) === String(jobId) || 
                   String(j.jobId) === String(jobId);
        });
        
        if (job) {
            const oldStatus = job.status;
            Object.assign(job, updates);
            
            console.log('📝 Actualizando trabajo:', {
                jobIdBuscado: jobId,
                jobIdEncontrado: job.id,
                oldStatus: oldStatus,
                newStatus: job.status,
                updates: updates,
                totalJobs: this.jobs.length
            });
            
            if (updates.status === 'success' || updates.status === 'error') {
                job.completedAt = new Date().toISOString();
                if (job.createdAt) {
                    const start = new Date(job.createdAt);
                    const end = new Date(job.completedAt);
                    job.duration = Math.round((end - start) / 1000); // Duración en segundos
                }
            }
            this.saveJobsToStorage();
            
            // Actualizar contadores con un pequeño delay para asegurar que el DOM esté listo
            setTimeout(() => {
                this.updateJobsCounters();
            }, 50);
            
            // Si el modal está abierto, actualizarlo
            if (this.jobsLogModal && document.getElementById('jobsLogModal')) {
                const modalEl = document.getElementById('jobsLogModal');
                if (modalEl && modalEl.classList.contains('show')) {
                    this.updateJobsLogModal();
                }
            }
            
            console.log('✅ Trabajo actualizado exitosamente:', job.id, 'Estado:', job.status);
        } else {
            console.warn('⚠️ Trabajo no encontrado para actualizar:', jobId);
            console.warn('⚠️ Tipo de jobId:', typeof jobId);
            console.warn('⚠️ Trabajos disponibles:', this.jobs.map(j => ({ 
                id: j.id, 
                jobId: j.jobId,
                type: typeof j.id,
                typeJobId: typeof j.jobId
            })));
        }
    }

    /**
     * Guarda trabajos en localStorage
     */
    saveJobsToStorage() {
        try {
            // Mantener solo los últimos 100 trabajos
            const jobsToSave = this.jobs.slice(0, 100);
            localStorage.setItem(this.jobsStorageKey, JSON.stringify(jobsToSave));
        } catch (e) {
            console.error('❌ Error guardando trabajos:', e);
        }
    }

    /**
     * Carga trabajos desde localStorage
     */
    loadJobsFromStorage() {
        try {
            const stored = localStorage.getItem(this.jobsStorageKey);
            if (stored) {
                this.jobs = JSON.parse(stored);
                console.log('📦 Trabajos cargados desde localStorage:', this.jobs.length);
                
                // Verificar si hay trabajos en curso que necesitan polling
                this.jobs.forEach(job => {
                    if (job.status === 'running' && job.jobId) {
                        // Reiniciar polling si la página se recargó
                        console.log('🔄 Reiniciando polling para trabajo:', job.id);
                        this.startJobPolling(job);
                    }
                });
                this.updateJobsCounters();
            }
        } catch (e) {
            console.error('❌ Error cargando trabajos:', e);
            this.jobs = [];
        }
    }

    /**
     * Actualiza los contadores de trabajos en el header
     */
    updateJobsCounters() {
        const running = this.jobs.filter(j => j.status === 'running' || j.status === 'pending').length;
        const completed = this.jobs.filter(j => j.status === 'success' || j.status === 'error').length;
        
        const runningEl = document.getElementById('jobsRunning');
        const completedEl = document.getElementById('jobsCompleted');
        const separatorEl = document.getElementById('jobsSeparator');
        
        console.log('📊 Actualizando contadores de trabajos:', {
            total: this.jobs.length,
            running: running,
            completed: completed,
            jobs: this.jobs.map(j => ({ id: j.id, status: j.status })),
            runningElExists: !!runningEl,
            completedElExists: !!completedEl
        });
        
        if (runningEl) {
            runningEl.textContent = running;
            if (running > 0) {
                runningEl.classList.remove('d-none');
                if (separatorEl) separatorEl.classList.remove('d-none');
            } else {
                runningEl.classList.add('d-none');
                if (separatorEl) separatorEl.classList.add('d-none');
            }
            console.log('✅ Contador de trabajos en curso actualizado:', running);
        } else {
            console.warn('⚠️ Elemento jobsRunning no encontrado en el DOM. Reintentando en 200ms...');
            // Reintentar después de un delay si el elemento no está disponible
            setTimeout(() => {
                const retryEl = document.getElementById('jobsRunning');
                if (retryEl) {
                    this.updateJobsCounters();
                }
            }, 200);
        }
        
        if (completedEl) {
            completedEl.textContent = completed;
            console.log('✅ Contador de trabajos completados actualizado:', completed);
        } else {
            console.warn('⚠️ Elemento jobsCompleted no encontrado en el DOM. Reintentando en 200ms...');
            // Reintentar después de un delay si el elemento no está disponible
            setTimeout(() => {
                const retryEl = document.getElementById('jobsCompleted');
                if (retryEl) {
                    this.updateJobsCounters();
                }
            }, 200);
        }
    }

    /**
     * Inicia polling para un trabajo asíncrono
     */
    startJobPolling(job) {
        if (!job.jobId) {
            console.warn('⚠️ No se puede iniciar polling sin jobId:', job.id);
            return;
        }
        
        // Evitar polling duplicado
        if (this.activePolling.has(job.jobId)) {
            console.log('⚠️ Polling ya activo para jobId:', job.jobId);
            return;
        }
        
        console.log('🔄 Iniciando polling para trabajo:', job.id, 'jobId:', job.jobId);
        
        const pollInterval = setInterval(async () => {
            try {
                // Usar originalStudyId si está disponible, sino usar studyId
                const studyIdToUse = job.originalStudyId || job.studyId;
                let url = `${this.apiBaseUrl}job-status.php?job_id=${encodeURIComponent(job.jobId)}&study_id=${encodeURIComponent(studyIdToUse)}`;
                if (job.originalStudyId) {
                    url += `&original_study_id=${encodeURIComponent(job.originalStudyId)}`;
                }
                if (job.migrationLogId) {
                    url += `&migration_log_id=${encodeURIComponent(job.migrationLogId)}`;
                }
                if (typeof job.deleteOriginal === 'boolean') {
                    url += `&delete_original=${job.deleteOriginal ? '1' : '0'}`;
                }
                const response = await fetch(url);
                const result = await response.json();
                
                if (result.success) {
                    // Job encadenado (p. ej. paso 2): seguir polling con el nuevo job_id
                    if (result.job_state === 'Running' && result.job_id && result.job_id !== job.jobId) {
                        this.updateJob(job.id, {
                            jobId: result.job_id,
                            status: 'running',
                            progress: result.progress || 0
                        });
                        clearInterval(pollInterval);
                        this.activePolling.delete(job.jobId);
                        const refreshed = this.jobs.find(j => j.id === job.id);
                        if (refreshed) {
                            this.startJobPolling(refreshed);
                        }
                        return;
                    }

                    if (result.job_state === 'Success') {
                        // Job completado
                        clearInterval(pollInterval);
                        this.activePolling.delete(job.jobId);
                        
                        this.updateJob(job.id, {
                            status: result.reconcile_status === 'partial' ? 'error' : 'success',
                            newStudyId: result.new_study_id || null,
                            progress: 100,
                            note: result.note || null,
                            reconcileStatus: result.reconcile_status || null,
                            error: result.reconcile_status === 'partial' ? (result.reconcile_error || result.error) : null
                        });
                        
                        const msg = result.note || (job.type === 'edit' ? 'Estudio editado y plataforma alineada' : 'Estudio eliminado');
                        if (result.success === false) {
                            this.showError(result.error || msg);
                        } else {
                            this.showSuccess(msg);
                        }
                        
                        // Recargar lista de estudios si es necesario
                        if (result.new_study_id && result.new_study_id !== job.studyId) {
                            console.log('📝 Nuevo estudio creado, recargando lista...');
                            await this.loadStudies();
                        } else if (job.type === 'delete') {
                            console.log('🗑️ Estudio eliminado, recargando lista...');
                            await this.loadStudies();
                        }

                    } else if (result.job_state === 'PendingResolution' || result.resolution_pending) {
                        this.updateJob(job.id, {
                            status: 'running',
                            progress: result.progress || 90,
                            note: result.note || 'Localizando estudio nuevo en PACS…'
                        });
                        
                    } else if (result.job_state === 'Failure') {
                        // Job falló
                        clearInterval(pollInterval);
                        this.activePolling.delete(job.jobId);
                        
                        this.updateJob(job.id, {
                            status: 'error',
                            error: result.error || 'Error desconocido',
                            progress: 0
                        });
                        
                        this.showError(`Error en trabajo: ${result.error || 'Error desconocido'}`);
                        
                    } else if (result.job_state === 'Running') {
                        // Job aún corriendo, actualizar progreso
                        this.updateJob(job.id, {
                            progress: result.progress || 0
                        });
                    }
                } else {
                    // Error en la consulta
                    clearInterval(pollInterval);
                    this.activePolling.delete(job.jobId);
                    
                    this.updateJob(job.id, {
                        status: 'error',
                        error: result.error || 'Error consultando estado del trabajo'
                    });
                }
            } catch (error) {
                console.error('❌ Error en polling:', error);
                // Continuar polling en caso de error temporal
            }
        }, 5000); // Polling cada 5 segundos
        
        this.activePolling.set(job.jobId, pollInterval);
        
        // Timeout máximo de 20 minutos
        setTimeout(() => {
            if (this.activePolling.has(job.jobId)) {
                clearInterval(pollInterval);
                this.activePolling.delete(job.jobId);
                
                this.updateJob(job.id, {
                    status: 'error',
                    error: 'Timeout: El trabajo está tomando más tiempo del esperado'
                });
                
                console.warn('⏱️ Timeout en polling para trabajo:', job.id);
            }
        }, 20 * 60 * 1000); // 20 minutos
    }

    /**
     * Formato YYYY-MM-DD para inputs type=date (zona local).
     */
    _formatDateInput(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    /**
     * Filtra un job local por rango de fechas (según createdAt).
     */
    _jobMatchesDateRange(job, dateFrom, dateTo) {
        if (!dateFrom && !dateTo) {
            return true;
        }
        const raw = job.createdAt || job.completedAt;
        if (!raw) {
            return false;
        }
        const jobDate = new Date(raw);
        if (Number.isNaN(jobDate.getTime())) {
            return false;
        }
        if (dateFrom) {
            const from = new Date(`${dateFrom}T00:00:00`);
            if (jobDate < from) {
                return false;
            }
        }
        if (dateTo) {
            const to = new Date(`${dateTo}T23:59:59.999`);
            if (jobDate > to) {
                return false;
            }
        }
        return true;
    }

    /**
     * Abre el modal de logs de trabajos
     */
    openJobsLogModal() {
        if (!this.jobsLogModal) {
            const modalEl = document.getElementById('jobsLogModal');
            if (modalEl) {
                this.jobsLogModal = new bootstrap.Modal(modalEl);
            }
        }
        
        if (this.jobsLogModal) {
            this.updateJobsLogModal();
            this.jobsLogModal.show();
        }
    }

    /**
     * Actualiza el contenido del modal de logs
     */
    updateJobsLogModal() {
        const tbody = document.getElementById('jobsLogTableBody');
        if (!tbody) return;
        
        const typeFilter = document.getElementById('jobsLogFilterType')?.value || 'all';
        const statusFilter = document.getElementById('jobsLogFilterStatus')?.value || 'all';
        const dateFrom = document.getElementById('jobsLogFilterDateFrom')?.value || '';
        const dateTo = document.getElementById('jobsLogFilterDateTo')?.value || '';
        
        // Filtrar trabajos
        let filteredJobs = this.jobs;
        if (typeFilter !== 'all') {
            filteredJobs = filteredJobs.filter(j => j.type === typeFilter);
        }
        if (statusFilter !== 'all') {
            filteredJobs = filteredJobs.filter(j => j.status === statusFilter);
        }
        filteredJobs = filteredJobs.filter(j => this._jobMatchesDateRange(j, dateFrom, dateTo));
        
        const hasExtraFilters = typeFilter !== 'all' || statusFilter !== 'all' || dateFrom || dateTo;
        
        // Actualizar total
        const totalEl = document.getElementById('jobsLogTotal');
        const hintEl = document.getElementById('jobsLogTotalHint');
        if (totalEl) totalEl.textContent = String(filteredJobs.length);
        if (hintEl) {
            hintEl.textContent = hasExtraFilters && this.jobs.length !== filteredJobs.length
                ? ` de ${this.jobs.length} trabajos`
                : (filteredJobs.length === 1 ? ' trabajo' : ' trabajos');
        }
        
        // Renderizar tabla
        if (filteredJobs.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-2x mb-2"></i>
                        <div>No hay trabajos que mostrar</div>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = filteredJobs.map(job => {
            const date = new Date(job.createdAt);
            const dateStr = date.toLocaleString('es-AR');
            const durationStr = job.duration ? `${job.duration}s` : '-';
            
            const statusBadge = {
                'pending': '<span class="badge bg-secondary">Pendiente</span>',
                'running': '<span class="badge bg-warning"><i class="fas fa-spinner fa-spin"></i> En curso</span>',
                'success': '<span class="badge bg-success">Completado</span>',
                'error': '<span class="badge bg-danger">Error</span>'
            }[job.status] || '<span class="badge bg-secondary">Desconocido</span>';
            
            const progressBar = job.status === 'running' ? `
                <div class="progress" style="height: 20px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                         role="progressbar" 
                         style="width: ${job.progress}%">
                        ${job.progress}%
                    </div>
                </div>
            ` : '-';
            
            return `
                <tr>
                    <td><small>${dateStr}</small></td>
                    <td>
                        <span class="badge ${job.type === 'edit' ? 'bg-primary' : 'bg-danger'}">
                            ${job.type === 'edit' ? 'Edición' : 'Eliminación'}
                        </span>
                    </td>
                    <td>
                        <strong>${this.escapeHtml(job.patientName)}</strong><br>
                        <small class="text-muted">${this.escapeHtml(job.patientId)}</small>
                    </td>
                    <td>
                        <small>${this.escapeHtml(job.studyDescription)}</small><br>
                        <code class="text-muted" style="font-size: 0.7rem">${job.studyId.substring(0, 20)}...</code>
                    </td>
                    <td>${statusBadge}</td>
                    <td>${progressBar}</td>
                    <td><small>${durationStr}</small></td>
                    <td>
                        <button class="btn btn-sm btn-outline-info" onclick="pacsManager.showJobDetails('${job.id}')" title="Ver detalles">
                            <i class="fas fa-info-circle"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    /**
     * Muestra detalles de un trabajo específico
     */
    showJobDetails(jobId) {
        const job = this.jobs.find(j => j.id === jobId);
        if (!job) {
            this.showError('Trabajo no encontrado');
            return;
        }
        
        const dateCreated = new Date(job.createdAt).toLocaleString('es-AR');
        const dateCompleted = job.completedAt ? new Date(job.completedAt).toLocaleString('es-AR') : 'En curso';
        
        let detailsHtml = `
            <div class="card">
                <div class="card-header bg-${job.type === 'edit' ? 'primary' : 'danger'} text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-${job.type === 'edit' ? 'edit' : 'trash'} me-2"></i>
                        Detalles del Trabajo: ${job.type === 'edit' ? 'Edición' : 'Eliminación'}
                    </h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <th style="width: 30%;">Tipo:</th>
                            <td><span class="badge ${job.type === 'edit' ? 'bg-primary' : 'bg-danger'}">${job.type === 'edit' ? 'Edición' : 'Eliminación'}</span></td>
                        </tr>
                        <tr>
                            <th>Estado:</th>
                            <td>
                                ${job.status === 'running' ? '<span class="badge bg-warning"><i class="fas fa-spinner fa-spin"></i> En curso</span>' : ''}
                                ${job.status === 'success' ? '<span class="badge bg-success">Completado</span>' : ''}
                                ${job.status === 'error' ? '<span class="badge bg-danger">Error</span>' : ''}
                                ${job.status === 'pending' ? '<span class="badge bg-secondary">Pendiente</span>' : ''}
                            </td>
                        </tr>
                        <tr>
                            <th>Paciente:</th>
                            <td><strong>${this.escapeHtml(job.patientName)}</strong> (${this.escapeHtml(job.patientId)})</td>
                        </tr>
                        <tr>
                            <th>Estudio:</th>
                            <td>${this.escapeHtml(job.studyDescription)}</td>
                        </tr>
                        <tr>
                            <th>ID Original:</th>
                            <td><code style="font-size: 0.85rem">${job.studyId}</code></td>
                        </tr>
                        ${job.newStudyId ? `
                        <tr>
                            <th>ID Nuevo:</th>
                            <td><code style="font-size: 0.85rem">${job.newStudyId}</code></td>
                        </tr>
                        ` : ''}
                        <tr>
                            <th>Iniciado:</th>
                            <td>${dateCreated}</td>
                        </tr>
                        <tr>
                            <th>Completado:</th>
                            <td>${dateCompleted}</td>
                        </tr>
                        ${job.duration ? `
                        <tr>
                            <th>Duración:</th>
                            <td>${job.duration} segundos</td>
                        </tr>
                        ` : ''}
                        ${job.error ? `
                        <tr>
                            <th>Error:</th>
                            <td><span class="text-danger">${this.escapeHtml(job.error)}</span></td>
                        </tr>
                        ` : ''}
                        ${job.note ? `
                        <tr>
                            <th>Nota:</th>
                            <td>${this.escapeHtml(job.note)}</td>
                        </tr>
                        ` : ''}
                        ${Object.keys(job.tagsModified).length > 0 ? `
                        <tr>
                            <th>Tags Modificados:</th>
                            <td>
                                <ul class="mb-0">
                                    ${Object.entries(job.tagsModified).map(([key, value]) => 
                                        `<li><code>${this.escapeHtml(key)}</code>: ${this.escapeHtml(String(value))}</li>`
                                    ).join('')}
                                </ul>
                            </td>
                        </tr>
                        ` : ''}
                    </table>
                </div>
                <div class="card-footer">
                    <button class="btn btn-secondary btn-sm" onclick="bootstrap.Modal.getInstance(document.getElementById('jobDetailsModal')).hide()">
                        <i class="fas fa-times me-1"></i>Cerrar
                    </button>
                </div>
            </div>
        `;
        
        // Crear modal temporal para mostrar detalles
        let modalEl = document.getElementById('jobDetailsModal');
        if (!modalEl) {
            modalEl = document.createElement('div');
            modalEl.id = 'jobDetailsModal';
            modalEl.className = 'modal fade';
            modalEl.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div id="jobDetailsContent"></div>
                    </div>
                </div>
            `;
            document.body.appendChild(modalEl);
        }
        
        document.getElementById('jobDetailsContent').innerHTML = detailsHtml;
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    setupAuditEventListeners() {
        const tabBtn = document.getElementById('tab-audit-btn');
        if (tabBtn) {
            tabBtn.addEventListener('shown.bs.tab', () => this.loadAuditList());
        }
        const refreshBtn = document.getElementById('auditRefreshBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadAuditList());
        }
        const filter = document.getElementById('auditFilterStatus');
        if (filter) {
            filter.addEventListener('change', () => this.loadAuditList());
        }
        const auditDateFrom = document.getElementById('auditFilterDateFrom');
        const auditDateTo = document.getElementById('auditFilterDateTo');
        if (auditDateFrom) {
            auditDateFrom.addEventListener('change', () => this.loadAuditList());
        }
        if (auditDateTo) {
            auditDateTo.addEventListener('change', () => this.loadAuditList());
        }
        const auditTodayBtn = document.getElementById('auditFilterTodayBtn');
        if (auditTodayBtn) {
            auditTodayBtn.addEventListener('click', () => {
                const today = this._formatDateInput(new Date());
                if (auditDateFrom) auditDateFrom.value = today;
                if (auditDateTo) auditDateTo.value = today;
                this.loadAuditList();
            });
        }
        const retryBtn = document.getElementById('auditRetryReconcileBtn');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => this.retryAuditReconcile('full'));
        }
        const syncBdBtn = document.getElementById('auditSyncBdBtn');
        if (syncBdBtn) {
            syncBdBtn.addEventListener('click', () => this.retryAuditReconcile('metadata_only'));
        }
        const relinkDocBtn = document.getElementById('auditRelinkDocBtn');
        if (relinkDocBtn) {
            relinkDocBtn.addEventListener('click', () => this.retryAuditReconcile('doc_only'));
        }
        const setNewStudyBtn = document.getElementById('auditSetNewStudyBtn');
        if (setNewStudyBtn) {
            setNewStudyBtn.addEventListener('click', () => this.setAuditNewStudyId());
        }
        const detailEl = document.getElementById('auditDetailModal');
        if (detailEl) {
            this.auditDetailModal = new bootstrap.Modal(detailEl);
        }
    }

    async loadAuditList() {
        const tbody = document.getElementById('auditTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></td></tr>';
        const status = document.getElementById('auditFilterStatus')?.value || 'all';
        const dateFrom = document.getElementById('auditFilterDateFrom')?.value || '';
        const dateTo = document.getElementById('auditFilterDateTo')?.value || '';
        let url = `${this.apiBaseUrl}audit-list.php?limit=50`;
        if (status && status !== 'all') {
            url += `&status=${encodeURIComponent(status)}`;
        }
        if (dateFrom) {
            url += `&date_from=${encodeURIComponent(dateFrom)}`;
        }
        if (dateTo) {
            url += `&date_to=${encodeURIComponent(dateTo)}`;
        }
        try {
            const res = await fetch(url);
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || 'Error cargando auditoría');
            }
            if (!data.items || data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Sin registros.</td></tr>';
                return;
            }
            tbody.innerHTML = data.items.map(row => {
                const user = [row.user_nombre, row.user_apellido].filter(Boolean).join(' ') || '—';
                const delOrig = parseInt(row.delete_original_requested, 10) === 1;
                const origDel = row.original_deleted == null ? '—' : (parseInt(row.original_deleted, 10) ? 'Eliminado' : 'Conservado');
                const canFullRetry = ['failed', 'partial'].includes(row.reconcile_status) || ['failed', 'partial'].includes(row.status);
                const canSyncBd = !!row.new_orthanc_study_id;
                return `<tr>
                    <td class="small">${this.escapeHtml(String(row.created_at || ''))}</td>
                    <td class="small">${this.escapeHtml(user)}</td>
                    <td class="small">${this.escapeHtml(row.patient_name_pacs || row.patient_id_pacs || '—')}</td>
                    <td><span class="badge bg-secondary">${this.escapeHtml(row.status || '')}</span></td>
                    <td class="small">${this.escapeHtml(row.reconcile_status || '—')}</td>
                    <td class="small">${delOrig ? 'Auto-del' : 'Mantener'} / ${origDel}</td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" data-audit-id="${row.id}" data-audit-full-retry="${canFullRetry ? '1' : '0'}" data-audit-sync-bd="${canSyncBd ? '1' : '0'}">Ver</button>
                        ${canSyncBd ? `<button class="btn btn-sm btn-outline-secondary ms-1" data-audit-sync-id="${row.id}" title="Sincronizar metadatos en BD sin modificar Orthanc"><i class="fas fa-database"></i></button>` : ''}
                        ${canSyncBd ? `<button class="btn btn-sm btn-outline-info ms-1" data-audit-doc-id="${row.id}" title="Re-vincular serie DOC desde Orthanc"><i class="fas fa-file-medical"></i></button>` : ''}
                    </td>
                </tr>`;
            }).join('');
            tbody.querySelectorAll('button[data-audit-id]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = parseInt(btn.getAttribute('data-audit-id'), 10);
                    this.showAuditDetail(id, {
                        fullRetry: btn.getAttribute('data-audit-full-retry') === '1',
                        syncBd: btn.getAttribute('data-audit-sync-bd') === '1'
                    });
                });
            });
            tbody.querySelectorAll('button[data-audit-sync-id]').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const id = parseInt(btn.getAttribute('data-audit-sync-id'), 10);
                    this.currentAuditLogId = id;
                    await this.retryAuditReconcile('metadata_only');
                    await this.loadAuditList();
                });
            });
            tbody.querySelectorAll('button[data-audit-doc-id]').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const id = parseInt(btn.getAttribute('data-audit-doc-id'), 10);
                    this.currentAuditLogId = id;
                    await this.retryAuditReconcile('doc_only');
                    await this.loadAuditList();
                });
            });
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-danger text-center">${this.escapeHtml(e.message)}</td></tr>`;
        }
    }

    async showAuditDetail(logId, actions = {}) {
        const showFullRetry = actions.fullRetry === true;
        const showSyncBd = actions.syncBd === true;
        this.currentAuditLogId = logId;
        const body = document.getElementById('auditDetailBody');
        const retryBtn = document.getElementById('auditRetryReconcileBtn');
        const syncBdBtn = document.getElementById('auditSyncBdBtn');
        const relinkDocBtn = document.getElementById('auditRelinkDocBtn');
        const deleteWrap = document.getElementById('auditDeleteOriginalWrap');
        const setNewStudyWrap = document.getElementById('auditSetNewStudyWrap');
        const setNewStudyFeedback = document.getElementById('auditSetNewStudyFeedback');
        if (!body) return;
        body.innerHTML = '<p class="text-muted">Cargando...</p>';
        if (retryBtn) retryBtn.style.display = 'none';
        if (syncBdBtn) syncBdBtn.style.display = 'none';
        if (relinkDocBtn) relinkDocBtn.style.display = 'none';
        if (deleteWrap) deleteWrap.style.display = 'none';
        if (setNewStudyWrap) setNewStudyWrap.style.display = 'none';
        if (setNewStudyFeedback) setNewStudyFeedback.innerHTML = '';
        const inputEl = document.getElementById('auditNewStudyIdInput');
        if (inputEl) inputEl.value = '';
        if (this.auditDetailModal) this.auditDetailModal.show();
        try {
            const res = await fetch(`${this.apiBaseUrl}audit-detail.php?id=${logId}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Error');
            const log = data.log;
            const details = data.details || [];
            const tags = log.tags_requested && typeof log.tags_requested === 'object' ? log.tags_requested : {};
            const tagLines = Object.keys(tags)
                .filter(k => !k.startsWith('_'))
                .map(k => `<li><strong>${this.escapeHtml(k)}:</strong> ${this.escapeHtml(String(tags[k] ?? ''))}</li>`)
                .join('') || '<li class="text-muted">—</li>';
            const rows = details.filter(d => (d.rows_updated || 0) > 0)
                .map(d => `<li>${this.escapeHtml(d.table_name)}.${this.escapeHtml(d.column_name)}: ${d.rows_updated}</li>`).join('') || '<li class="text-muted">Sin filas actualizadas registradas</li>';
            body.innerHTML = `
                <dl class="row small mb-3">
                    <dt class="col-sm-4">Estado</dt><dd class="col-sm-8">${this.escapeHtml(log.status)} / reconcile: ${this.escapeHtml(log.reconcile_status || '—')}</dd>
                    <dt class="col-sm-4">Paciente (destino)</dt><dd class="col-sm-8">${this.escapeHtml(log.patient_name_pacs || '—')} (${this.escapeHtml(log.patient_id_pacs || '—')})</dd>
                    <dt class="col-sm-4">Orthanc antiguo</dt><dd class="col-sm-8"><code>${this.escapeHtml(log.old_orthanc_study_id || '')}</code></dd>
                    <dt class="col-sm-4">Orthanc nuevo</dt><dd class="col-sm-8"><code>${this.escapeHtml(log.new_orthanc_study_id || '—')}</code></dd>
                    <dt class="col-sm-4">SUID antiguo</dt><dd class="col-sm-8 text-break"><code>${this.escapeHtml(log.old_study_instance_uid || '—')}</code></dd>
                    <dt class="col-sm-4">SUID nuevo</dt><dd class="col-sm-8 text-break"><code>${this.escapeHtml(log.new_study_instance_uid || '—')}</code></dd>
                    <dt class="col-sm-4">Eliminar original</dt><dd class="col-sm-8">${parseInt(log.delete_original_requested, 10) ? 'Sí' : 'No'} (${log.original_deleted == null ? 'pendiente' : (parseInt(log.original_deleted, 10) ? 'eliminado' : 'conservado')})</dd>
                    ${log.error_message ? `<dt class="col-sm-4">Error</dt><dd class="col-sm-8 text-danger">${this.escapeHtml(log.error_message)}</dd>` : ''}
                    ${log.reconcile_error ? `<dt class="col-sm-4">Reconcile</dt><dd class="col-sm-8 text-danger">${this.escapeHtml(log.reconcile_error)}</dd>` : ''}
                </dl>
                <h6>Tags modificados</h6><ul class="small mb-3">${tagLines}</ul>
                <h6>Tablas actualizadas</h6><ul class="small">${rows}</ul>
            `;
            const hasNewStudy = !!log.new_orthanc_study_id;
            const isFailed = log.status === 'failed';
            const failedPartial = ['failed', 'partial'].includes(log.reconcile_status) || ['failed', 'partial'].includes(log.status);
            // Panel manual: solo cuando el status es failed y no hay new_orthanc_study_id
            if (setNewStudyWrap) {
                setNewStudyWrap.style.display = (isFailed && !hasNewStudy) ? 'block' : 'none';
            }
            if (syncBdBtn) {
                syncBdBtn.style.display = (showSyncBd || hasNewStudy) ? 'inline-block' : 'none';
            }
            if (relinkDocBtn) {
                relinkDocBtn.style.display = (showSyncBd || hasNewStudy) ? 'inline-block' : 'none';
            }
            if (retryBtn) {
                retryBtn.style.display = (showFullRetry || failedPartial) && hasNewStudy ? 'inline-block' : 'none';
            }
            if (deleteWrap) {
                const showDel = failedPartial && hasNewStudy && parseInt(log.delete_original_requested, 10) === 1 && !parseInt(log.original_deleted, 10);
                deleteWrap.style.display = showDel ? 'block' : 'none';
                const delChk = document.getElementById('auditAttemptDeleteOriginal');
                if (delChk) delChk.checked = false;
            }
        } catch (e) {
            body.innerHTML = `<p class="text-danger">${this.escapeHtml(e.message)}</p>`;
        }
    }

    async retryAuditReconcile(mode = 'full') {
        if (!this.currentAuditLogId) return;
        const isMetadataOnly = mode === 'metadata_only';
        const isDocOnly = mode === 'doc_only';
        const retryBtn = document.getElementById('auditRetryReconcileBtn');
        const syncBdBtn = document.getElementById('auditSyncBdBtn');
        const relinkDocBtn = document.getElementById('auditRelinkDocBtn');
        const activeBtn = isMetadataOnly ? syncBdBtn : (isDocOnly ? relinkDocBtn : retryBtn);
        if (activeBtn) activeBtn.disabled = true;
        try {
            const attemptDelete = mode === 'full' && document.getElementById('auditAttemptDeleteOriginal')?.checked;
            const res = await fetch(`${this.apiBaseUrl}reconcile.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    migration_log_id: this.currentAuditLogId,
                    mode: mode,
                    attempt_delete_original: !!attemptDelete
                })
            });
            const data = await res.json();
            if (!data.success && data.status !== 'partial') {
                throw new Error(data.error || data.note || 'Reconciliación fallida');
            }
            const defaultMsg = isMetadataOnly
                ? 'Metadatos sincronizados en BD'
                : (isDocOnly ? 'Serie DOC re-vinculada' : 'Reconciliación ejecutada');
            const msg = data.note || defaultMsg;
            this.showSuccess(msg);
            await this.showAuditDetail(this.currentAuditLogId, { syncBd: true, fullRetry: mode === 'full' });
            await this.loadAuditList();
        } catch (e) {
            this.showError(e.message);
        } finally {
            if (retryBtn) retryBtn.disabled = false;
            if (syncBdBtn) syncBdBtn.disabled = false;
            if (relinkDocBtn) relinkDocBtn.disabled = false;
        }
    }

    /**
     * Asigna manualmente el new_orthanc_study_id a un log fallido.
     * Valida el UUID en el servidor (verifica existencia en Orthanc y PatientID).
     * Tras éxito, habilita los botones de reconciliación.
     */
    async setAuditNewStudyId() {
        if (!this.currentAuditLogId) return;
        const inputEl  = document.getElementById('auditNewStudyIdInput');
        const btn      = document.getElementById('auditSetNewStudyBtn');
        const feedback = document.getElementById('auditSetNewStudyFeedback');
        const uuid = inputEl ? inputEl.value.trim() : '';

        if (!uuid) {
            if (feedback) feedback.innerHTML = '<span class="text-danger">Ingresá el UUID del estudio en Orthanc.</span>';
            return;
        }

        if (btn) btn.disabled = true;
        if (feedback) feedback.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>Verificando en Orthanc…</span>';

        try {
            const res = await fetch(`${this.apiBaseUrl}set-new-study.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    migration_log_id: this.currentAuditLogId,
                    new_orthanc_study_id: uuid,
                }),
            });
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || 'Error al asignar UUID');
            }

            if (feedback) {
                feedback.innerHTML = `<span class="text-success"><i class="fas fa-check me-1"></i>UUID asignado correctamente.`
                    + (data.patient_name_orthanc ? ` Paciente: <strong>${this.escapeHtml(data.patient_name_orthanc)}</strong>` : '')
                    + (data.study_description ? ` | Estudio: <strong>${this.escapeHtml(data.study_description)}</strong>` : '')
                    + `</span>`;
            }

            // Habilitar botones de reconciliación ahora que tenemos estudio nuevo
            const setWrap   = document.getElementById('auditSetNewStudyWrap');
            const retryBtn  = document.getElementById('auditRetryReconcileBtn');
            const syncBdBtn = document.getElementById('auditSyncBdBtn');
            const relinkDoc = document.getElementById('auditRelinkDocBtn');
            if (retryBtn)  { retryBtn.style.display  = 'inline-block'; retryBtn.disabled  = false; }
            if (syncBdBtn) { syncBdBtn.style.display  = 'inline-block'; syncBdBtn.disabled  = false; }
            if (relinkDoc) { relinkDoc.style.display  = 'inline-block'; relinkDoc.disabled  = false; }
            // Ocultar el panel de asignación (ya no es necesario)
            if (setWrap) setWrap.style.display = 'none';

            // Refrescar el cuerpo del modal para mostrar el nuevo UUID
            await this.showAuditDetail(this.currentAuditLogId);
        } catch (e) {
            if (feedback) feedback.innerHTML = `<span class="text-danger"><i class="fas fa-times me-1"></i>${this.escapeHtml(e.message)}</span>`;
            if (btn) btn.disabled = false;
        }
    }

    /**
     * Limpia los logs de trabajos
     */
    clearJobsLog() {
        if (confirm('¿Estás seguro de que deseas limpiar todos los logs de trabajos?')) {
            this.jobs = [];
            this.saveJobsToStorage();
            this.updateJobsCounters();
            this.updateJobsLogModal();
            this.showSuccess('Logs de trabajos limpiados');
        }
    }
}

// Inicializar cuando el DOM esté listo
let pacsManager;
document.addEventListener('DOMContentLoaded', function() {
    pacsManager = new PACSManager();
    window.pacsManager = pacsManager; // Hacer disponible globalmente para otros módulos
    pacsManager.init();
});

