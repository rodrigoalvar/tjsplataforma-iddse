/**
 * Cloud Storage Manager - Gestión de almacenamiento en R2
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

class CloudStorageManager {
    constructor() {
        this.studies = [];
        this.filteredStudies = [];
        this.selectedStudies = new Set();
        this.currentFilters = {
            search: '',
            dateFrom: '',
            dateTo: '',
            patientId: '',
            modalities: [] // Array de modalidades seleccionadas (vacío = todas)
        };
        this.apiBaseUrl = '/modules/cloud-storage/api/';
        this.currentTab = 'studies';
        this.storageKey = 'cloud_storage_state';
        
        // Configuración de ordenamiento
        this.sortConfig = {
            column: null,
            direction: 'asc' // 'asc' o 'desc'
        };
        
        // Polling automático
        this.pollingInterval = null;
        this.pollingIntervalMs = 5000; // 5 segundos
        this.isPolling = false;
        
        // Configuración del método de upload (se carga desde la API)
        this.currentUploadMethod = 'instance'; // 'instance' o 'zip'
        this.isDeletingR2Study = false; // candado UI para evitar múltiples purge simultáneos
        this.currentDeletingR2StudyId = null; // orthanc_study_id en eliminación
        this.isTogglingLockR2Study = false; // candado UI para evitar múltiples lock/unlock simultáneos
        this.currentLockR2StudyId = null; // orthanc_study_id en lock/unlock
        /** Selección múltiple en pestaña "Estudios en R2" (orthanc_study_id) */
        this.selectedR2Studies = new Set();
        /** Índice de fila (orden actual de la tabla) para Shift+clic */
        this.r2SelectionAnchorIndex = null;
        this.isBulkOperatingR2 = false; // bloqueo UI durante lock/unlock/delete masivos
        this.r2UsageLastUpdatedAt = null;
        this.canQueryR2Usage = false;
        this.isPurgingOrphan = false;
        this.currentPurgingOrphanUid = null;
        this.isLoadingR2Usage = false;
        this.lastR2UsageRequestAt = 0;
        this.r2UsageMinIntervalMs = 60000; // evitar consultar Cloudflare API cada 5s
        /** Token auto-enqueue en claro solo en memoria (para Lua tras guardar campo enmascarado) */
        this.autoEnqueueSecretPlaintext = null;
        this.autoEnqueuePacsNodes = [];
    }

    /**
     * Inicializa el módulo
     */
    async init() {
        console.log('🔧 Inicializando Cloud Storage Manager...');
        
        this.setupEventListeners();
        this.setupTabs();
        this.setupColumnSorting();

        /** Caché una sola vez: selección R2 debe existir antes del primer loadR2Studies */
        const cachedData = this.loadFromCache();
        if (cachedData && Array.isArray(cachedData.selectedR2StudyIds)) {
            const ids = cachedData.selectedR2StudyIds.filter(
                id =>
                    typeof id === 'string' &&
                    id.length > 0 &&
                    /^[a-zA-Z0-9_.-]{1,255}$/.test(id)
            );
            this.selectedR2Studies = new Set(ids);
            if (ids.length > 0) {
                console.log('📦 Selección Estudios en R2 restaurada desde caché:', ids.length);
            }
        }
        
        // Cargar datos iniciales (NO cargar estudios PACS hasta que el usuario busque)
        await this.loadConfig();
        await this.loadQueueStatus();
        await this.loadR2Studies();
        await this.loadR2Usage(true);
        
        // Iniciar polling automático
        this.startPolling();
        
        // Limpiar polling cuando se cierra la página
        window.addEventListener('beforeunload', () => {
            this.stopPolling();
            try {
                this.saveToCache();
            } catch (e) {
                /* ignorar (p.ej. modo privado) */
            }
        });
        
        // Restaurar lista PACS / filtros desde caché (misma lectura cachedData)
        if (cachedData && cachedData.studies && cachedData.studies.length > 0) {
            console.log('📦 Restaurando datos desde caché:', cachedData.studies.length, 'estudios');
            
            // Restaurar estudios y filtros desde el caché
            this.studies = cachedData.studies;
            if (cachedData.filters) {
                this.currentFilters = { ...this.currentFilters, ...cachedData.filters };
                // Asegurar que modalities sea un array
                if (cachedData.filters.modalities && Array.isArray(cachedData.filters.modalities)) {
                    this.currentFilters.modalities = [...cachedData.filters.modalities];
                } else {
                    // Compatibilidad con versiones antiguas que usaban modality (string)
                    if (cachedData.filters.modality && cachedData.filters.modality !== 'all') {
                        this.currentFilters.modalities = [cachedData.filters.modality];
                    } else {
                        this.currentFilters.modalities = [];
                    }
                }
            }
            
            // Restaurar configuración de ordenamiento
            if (cachedData.sortConfig) {
                this.sortConfig = { ...this.sortConfig, ...cachedData.sortConfig };
                console.log('📦 Configuración de ordenamiento restaurada:', this.sortConfig);
            }
            
            // Restaurar selecciones guardadas
            if (cachedData.selectedStudyIds && Array.isArray(cachedData.selectedStudyIds)) {
                this.selectedStudies = new Set(cachedData.selectedStudyIds);
                console.log('📦 Selecciones de estudios restauradas:', this.selectedStudies.size);
            }
            
            // Restaurar valores en los campos del formulario
            this.restoreFormValues();
            
            // Actualizar botones de modalidad (después de restaurar filtros)
            this.updateModalityButtons();
            
            console.log('📦 Filtros restaurados:', {
                dateFrom: this.currentFilters.dateFrom,
                dateTo: this.currentFilters.dateTo,
                patientId: this.currentFilters.patientId,
                modalities: this.currentFilters.modalities
            });
            
            // Aplicar filtros locales y renderizar
            this.applyFilters();
            
            // Restaurar selecciones en los checkboxes después de renderizar
            setTimeout(() => {
                this.restoreSelectedStudies();
            }, 200);
            
            // Actualizar iconos de ordenamiento después de restaurar
            setTimeout(() => {
                this.updateSortIcons();
            }, 150);
            
            // Actualizar contadores
            this.updateCounters();
            
            // Cargar modalidades faltantes si hay estudios sin modalidad
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
            this.showEmptyStudiesState();
        }

        // Volver a la última pestaña abierta (después de restaurar lista PACS si aplica)
        setTimeout(() => this.restoreCloudStorageTabFromCache(cachedData), 0);
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
                this.currentFilters.search = e.target.value.toLowerCase();
                this.applyFilters();
            });
        }

        // Botón encolar seleccionados
        const enqueueBtn = document.getElementById('enqueueSelectedBtn');
        if (enqueueBtn) {
            enqueueBtn.addEventListener('click', () => this.enqueueSelected());
        }

        // Checkbox seleccionar todos
        const selectAll = document.getElementById('selectAllCheckbox');
        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                this.toggleSelectAll(e.target.checked);
            });
        }

        // Botones de actualizar (con indicador visual)
        const refreshQueue = document.getElementById('refreshQueueBtn');
        if (refreshQueue) {
            refreshQueue.addEventListener('click', () => {
                this.loadQueueStatus(false); // false = mostrar indicador
            });
        }

        // Controles de la cola
        const stopWorker = document.getElementById('stopWorkerBtn');
        if (stopWorker) {
            stopWorker.addEventListener('click', () => {
                this.stopWorker();
            });
        }

        const cancelPending = document.getElementById('cancelPendingBtn');
        if (cancelPending) {
            cancelPending.addEventListener('click', () => {
                this.cancelPendingJobs();
            });
        }

        const clearQueue = document.getElementById('clearQueueBtn');
        if (clearQueue) {
            clearQueue.addEventListener('click', () => {
                this.clearQueue();
            });
        }

        const refreshR2 = document.getElementById('refreshR2Btn');
        if (refreshR2) {
            refreshR2.addEventListener('click', () => {
                this.loadR2Studies(false); // false = mostrar indicador
            });
        }

        const r2BulkLock = document.getElementById('r2BulkLockBtn');
        if (r2BulkLock) {
            r2BulkLock.addEventListener('click', () => this.bulkLockR2Studies());
        }
        const r2BulkUnlock = document.getElementById('r2BulkUnlockBtn');
        if (r2BulkUnlock) {
            r2BulkUnlock.addEventListener('click', () => this.bulkUnlockR2Studies());
        }
        const r2BulkDelete = document.getElementById('r2BulkDeleteBtn');
        if (r2BulkDelete) {
            r2BulkDelete.addEventListener('click', () => this.bulkDeleteR2Studies());
        }

        this.setupR2StudiesBulkSelectionListeners();

        const refreshR2Usage = document.getElementById('refreshR2UsageBtn');
        if (refreshR2Usage) {
            refreshR2Usage.addEventListener('click', () => {
                this.loadR2Usage(false); // actualización manual
            });
        }

        const refreshR2Audit = document.getElementById('refreshR2AuditBtn');
        if (refreshR2Audit) {
            refreshR2Audit.addEventListener('click', () => {
                this.loadR2Audit(false);
            });
        }

        // Formulario de configuración
        const configForm = document.getElementById('r2ConfigForm');
        if (configForm) {
            configForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveConfig();
            });
        }

        // Formulario de Quote Manager
        const quoteForm = document.getElementById('r2QuoteManagerForm');
        if (quoteForm) {
            quoteForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveQuoteManagerConfig();
            });
        }

        // Botón refresco específico para Quote Manager (usa el mismo fetch que loadR2Usage)
        const refreshQuoteBtn = document.getElementById('refreshR2UsageBtnQuote');
        if (refreshQuoteBtn) {
            refreshQuoteBtn.addEventListener('click', () => this.loadR2Usage(false));
        }

        // Botón probar conexión
        const testBtn = document.getElementById('testR2ConnectionBtn');
        if (testBtn) {
            testBtn.addEventListener('click', () => this.testConnection());
        }

        const cloudLogsRefresh = document.getElementById('cloudLogsRefreshBtn');
        if (cloudLogsRefresh) {
            cloudLogsRefresh.addEventListener('click', () => this.refreshCloudLog());
        }
        const cloudLogsFile = document.getElementById('cloudLogsFileSelect');
        if (cloudLogsFile) {
            cloudLogsFile.addEventListener('change', () => this.refreshCloudLog());
        }
        const cloudLogsLines = document.getElementById('cloudLogsLinesSelect');
        if (cloudLogsLines) {
            cloudLogsLines.addEventListener('change', () => this.refreshCloudLog());
        }

        const r2AutoGenTok = document.getElementById('r2AutoEnqueueGenerateTokenBtn');
        if (r2AutoGenTok) {
            r2AutoGenTok.addEventListener('click', () => this.generateAutoEnqueueSecret());
        }
        const r2AutoSave = document.getElementById('r2AutoEnqueueSaveBtn');
        if (r2AutoSave) {
            r2AutoSave.addEventListener('click', () => this.saveAutoEnqueueConfig());
        }
        const r2AutoLua = document.getElementById('r2AutoEnqueueRefreshLuaBtn');
        if (r2AutoLua) {
            r2AutoLua.addEventListener('click', () => this.updateAutoEnqueueLuaSnippet());
        }
        const r2AutoCopyUrl = document.getElementById('r2AutoEnqueueCopyUrl');
        if (r2AutoCopyUrl) {
            r2AutoCopyUrl.addEventListener('click', () => this.copyAutoEnqueueUrl());
        }
        const r2AutoCopyLua = document.getElementById('r2AutoEnqueueCopyLuaBtn');
        if (r2AutoCopyLua) {
            r2AutoCopyLua.addEventListener('click', () => this.copyAutoEnqueueLuaSnippet());
        }
        const r2AutoSug = document.getElementById('r2AutoEnqueueUseSuggestedUrl');
        if (r2AutoSug) {
            r2AutoSug.addEventListener('click', () => {
                const el = document.getElementById('r2AutoEnqueueWebhookUrl');
                if (el) el.value = this.getSuggestedAutoEnqueueUrl();
                this.updateAutoEnqueueLuaSnippet();
            });
        }
        const r2AutoSource = document.getElementById('r2AutoEnqueueInstancesSource');
        if (r2AutoSource) {
            r2AutoSource.addEventListener('change', () => this.updateAutoEnqueueNodesEnabledState());
        }
        const r2AutoUrlInput = document.getElementById('r2AutoEnqueueWebhookUrl');
        if (r2AutoUrlInput) {
            r2AutoUrlInput.addEventListener('input', () => this.updateAutoEnqueueLuaSnippet());
        }
        const r2AutoSec = document.getElementById('r2AutoEnqueueSecret');
        if (r2AutoSec) {
            const mask = '••••••••••••••••••••••••••••••••••••••••';
            r2AutoSec.addEventListener('input', () => {
                if (r2AutoSec.value !== mask) {
                    r2AutoSec.removeAttribute('data-has-value');
                }
                const v = r2AutoSec.value.trim();
                if (v && !v.match(/^[•\*]+$/)) {
                    this.autoEnqueueSecretPlaintext = v;
                } else if (!v) {
                    this.autoEnqueueSecretPlaintext = null;
                }
                this.updateAutoEnqueueLuaSnippet();
            });
        }
    }

    /**
     * Configura las pestañas
     */
    setupTabs() {
        const tabButtons = document.querySelectorAll('#mainTabs button[data-bs-toggle="tab"]');
        tabButtons.forEach(btn => {
            btn.addEventListener('shown.bs.tab', (e) => {
                this.currentTab = e.target.getAttribute('data-bs-target').replace('#', '');
                try {
                    this.saveToCache();
                } catch (err) {
                    /* noop */
                }
                
                // Cargar datos según la pestaña activa
                if (this.currentTab === 'queue') {
                    this.loadQueueStatus();
                } else if (this.currentTab === 'r2-studies') {
                    this.loadR2Studies();
                } else if (this.currentTab === 'r2-audit') {
                    this.loadR2Audit(false);
                } else if (this.currentTab === 'logs') {
                    this.loadLogsTab();
                } else if (this.currentTab === 'auto-enqueue') {
                    this.updateAutoEnqueueLuaSnippet();
                } else if (this.currentTab === 'config') {
                    this.loadConfig();
                } else if (this.currentTab === 'quote-manager') {
                    this.loadQuoteManagerConfig();
                } else if (this.currentTab === 'studies') {
                    // Cuando se vuelve a la pestaña de estudios, regenerar botones de modalidad
                    // para asegurar que todos los botones estén disponibles
                    this.updateModalityButtons();
                    // Aplicar filtros para actualizar la vista
                    this.applyFilters();
                }
            });
        });
    }

    /**
     * Carga configuración de cuota/reciclado para la pestaña Quote Manager
     */
    async loadQuoteManagerConfig() {
        try {
            const response = await fetch(`${this.apiBaseUrl}config.php`);
            const data = await response.json();
            if (!data.success || !data.data) return;

            const config = data.data;

            const quotaEnabledEl = document.getElementById('r2QuotaEnabled');
            if (quotaEnabledEl) quotaEnabledEl.value = config.r2_quota_enabled ? 'true' : 'false';

            const quotaLimitEl = document.getElementById('r2QuotaLimitGb');
            if (quotaLimitEl) quotaLimitEl.value = config.r2_quota_limit_gb ?? 0;

            const recycleEnabledEl = document.getElementById('r2RecycleEnabled');
            if (recycleEnabledEl) recycleEnabledEl.value = config.r2_recycle_enabled ? 'true' : 'false';

            const triggerPercentEl = document.getElementById('r2RecycleTriggerPercent');
            if (triggerPercentEl) triggerPercentEl.value = config.r2_recycle_trigger_percent ?? 0;

            const triggerGbEl = document.getElementById('r2RecycleTriggerGb');
            if (triggerGbEl) triggerGbEl.value = config.r2_recycle_trigger_gb ?? 0;

            // Refrescar indicadores según último uso
            await this.loadR2Usage(false);
        } catch (error) {
            console.error('Error loadQuoteManagerConfig:', error);
            this.showNotification(`❌ Error cargando Quote Manager: ${error.message}`, 'error');
        }
    }

    /**
     * Guarda configuración de cuota/reciclado
     */
    async saveQuoteManagerConfig() {
        try {
            const enabledSel = document.getElementById('r2QuotaEnabled');
            const limitGbInput = document.getElementById('r2QuotaLimitGb');
            const recycleEnabledSel = document.getElementById('r2RecycleEnabled');
            const triggerPercentInput = document.getElementById('r2RecycleTriggerPercent');
            const triggerGbInput = document.getElementById('r2RecycleTriggerGb');

            if (!enabledSel || !limitGbInput || !recycleEnabledSel || !triggerPercentInput || !triggerGbInput) {
                throw new Error('Faltan campos de Quote Manager en el HTML');
            }

            const config = {};
            config.r2_quota_enabled = enabledSel.value === 'true';
            config.r2_quota_limit_gb = parseFloat(limitGbInput.value);
            config.r2_recycle_enabled = recycleEnabledSel.value === 'true';
            config.r2_recycle_trigger_percent = parseFloat(triggerPercentInput.value);
            config.r2_recycle_trigger_gb = parseFloat(triggerGbInput.value);

            if (Number.isNaN(config.r2_quota_limit_gb) || config.r2_quota_limit_gb < 0) {
                throw new Error('Límite de cuota (GB) inválido');
            }
            if (Number.isNaN(config.r2_recycle_trigger_percent) || config.r2_recycle_trigger_percent < 0) {
                throw new Error('Trigger % inválido');
            }
            if (Number.isNaN(config.r2_recycle_trigger_gb) || config.r2_recycle_trigger_gb < 0) {
                throw new Error('Trigger GB inválido');
            }

            const response = await fetch(`${this.apiBaseUrl}config.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(config)
            });

            const result = await response.json();
            if (!result.success) {
                const errorMsg = result.errors && result.errors.length ? result.errors.join(', ') : (result.error || 'Error');
                throw new Error(errorMsg);
            }

            this.showNotification('✅ Quote Manager guardado', 'success');
            await this.loadQuoteManagerConfig();
        } catch (error) {
            console.error('saveQuoteManagerConfig:', error);
            this.showNotification(`❌ Error guardando Quote Manager: ${error.message}`, 'error');
        }
    }

    async loadLogsTab() {
        await this.loadCloudLogsCatalog();
        await this.refreshCloudLog();
    }

    async loadCloudLogsCatalog() {
        const sel = document.getElementById('cloudLogsFileSelect');
        const hintEl = document.getElementById('cloudLogsHint');
        if (!sel) {
            return;
        }
        try {
            const response = await fetch(`${this.apiBaseUrl}logs.php?action=list`);
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Error');
            }
            const prev = sel.value;
            sel.innerHTML = '';
            const logs = data.data.logs || [];
            for (const log of logs) {
                const opt = document.createElement('option');
                opt.value = log.id;
                opt.textContent = `${log.label}${log.exists ? '' : ' (archivo aún no creado)'}`;
                opt.title = log.description || '';
                sel.appendChild(opt);
            }
            if (prev && [...sel.options].some((o) => o.value === prev)) {
                sel.value = prev;
            }
            if (hintEl && data.data.hint) {
                hintEl.textContent = data.data.hint;
            }
        } catch (e) {
            console.error('loadCloudLogsCatalog', e);
            sel.innerHTML = '<option value="">Error cargando catálogo</option>';
        }
    }

    async refreshCloudLog() {
        const pre = document.getElementById('cloudLogsPre');
        const meta = document.getElementById('cloudLogsMeta');
        const sel = document.getElementById('cloudLogsFileSelect');
        const linesSel = document.getElementById('cloudLogsLinesSelect');
        if (!pre || !sel || !sel.value) {
            return;
        }
        const lines = linesSel ? linesSel.value : '500';
        pre.textContent = 'Cargando…';
        if (meta) {
            meta.textContent = '';
        }
        try {
            const params = new URLSearchParams({ file: sel.value, lines });
            const response = await fetch(`${this.apiBaseUrl}logs.php?${params}`);
            const data = await response.json();
            if (!data.success) {
                pre.textContent = data.error || 'No se pudo leer el log';
                return;
            }
            const d = data.data;
            pre.textContent = d.content !== undefined && d.content !== '' ? d.content : '(vacío)';
            if (meta) {
                const kb = (d.size_bytes / 1024).toFixed(1);
                meta.textContent =
                    `Archivo: ${d.file} · Tamaño: ${kb} KB · Modificado: ${d.modified_at || '—'} · Hasta ${d.lines_requested} líneas finales`;
            }
        } catch (e) {
            console.error('refreshCloudLog', e);
            pre.textContent = 'Error de red al leer el log';
        }
    }

    /**
     * Maneja la búsqueda de estudios
     */
    async handleSearch() {
        const dateFrom = document.getElementById('dateFrom')?.value || '';
        const dateTo = document.getElementById('dateTo')?.value || '';
        const patientId = document.getElementById('patientId')?.value || '';
        
        this.currentFilters.dateFrom = dateFrom;
        this.currentFilters.dateTo = dateTo;
        this.currentFilters.patientId = patientId;
        // NOTA: NO resetear modalities aquí, mantener la selección del usuario
        
        await this.loadStudies();
    }

    /**
     * Carga estudios desde PACS
     */
    async loadStudies() {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="mt-2">Consultando estudios desde PACS...</div>
                </td>
            </tr>
        `;
        
        try {
            const params = new URLSearchParams();
            if (this.currentFilters.dateFrom) params.append('date_from', this.currentFilters.dateFrom);
            if (this.currentFilters.dateTo) params.append('date_to', this.currentFilters.dateTo);
            if (this.currentFilters.patientId) params.append('patient_id', this.currentFilters.patientId);
            // Si hay modalidades seleccionadas, enviar la primera (el backend puede filtrar por una)
            // El filtro completo se aplica en el frontend con applyFilters()
            if (this.currentFilters.modalities && this.currentFilters.modalities.length > 0) {
                params.append('modality', this.currentFilters.modalities[0]);
            }
            
            const response = await fetch(`${this.apiBaseUrl}list-studies.php?${params}`, {
                credentials: 'same-origin',
            });
            const data = await response.json();
            
            if (data.success) {
                this.studies = data.data || [];
                
                // Debug: Ver qué modalidades tienen los estudios
                if (this.studies.length > 0) {
                    console.log('🔍 Primer estudio (ejemplo):', {
                        orthanc_id: this.studies[0].orthanc_id,
                        modality: this.studies[0].modality,
                        Modality: this.studies[0].Modality,
                        modality_type: this.studies[0].modality_type,
                        allKeys: Object.keys(this.studies[0])
                    });
                }
                
                // Regenerar botones de modalidad con TODAS las modalidades disponibles
                // (antes de aplicar filtros, para que todos los botones estén disponibles)
                this.updateModalityButtons();
                
                // Aplicar filtros (que incluye ordenamiento si está configurado)
                this.applyFilters();
                
                // Cargar modalidades faltantes en segundo plano
                this.loadMissingModalities();
                
                // Actualizar iconos de ordenamiento después de renderizar
                setTimeout(() => {
                    this.updateSortIcons();
                }, 150);
                
                this.updateCounters();
            } else {
                throw new Error(data.error || 'Error cargando estudios');
            }
        } catch (error) {
            console.error('Error cargando estudios:', error);
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error: ${error.message}
                    </td>
                </tr>
            `;
        }
    }

    /**
     * Aplica filtros locales
     */
    applyFilters() {
        let filtered = this.studies.filter(study => {
            // Filtro de búsqueda general
            if (this.currentFilters.search) {
                const search = this.currentFilters.search;
                const matches = 
                    (study.patient_name || '').toLowerCase().includes(search) ||
                    (study.patient_id || '').toLowerCase().includes(search) ||
                    (study.study_description || '').toLowerCase().includes(search) ||
                    (study.study_instance_uid || '').toLowerCase().includes(search);
                if (!matches) return false;
            }
            
            // Filtro de modalidad (múltiple selección)
            if (this.currentFilters.modalities && this.currentFilters.modalities.length > 0) {
                const studyModalities = (study.modality || '').split(',').map(m => m.trim());
                // Verificar si alguna de las modalidades del estudio coincide con alguna de las seleccionadas
                const matches = this.currentFilters.modalities.some(selectedMod => 
                    studyModalities.includes(selectedMod)
                );
                if (!matches) return false;
            }
            
            return true;
        });
        
        // Aplicar ordenamiento si está configurado
        filtered = this.sortResults(filtered);
        
        this.filteredStudies = filtered;
        this.renderStudies();
        
        // Guardar estado después de aplicar filtros (incluye ordenamiento)
        this.saveToCache();
    }

    /**
     * Renderiza los estudios en la tabla
     */
    renderStudies() {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;
        
        if (this.filteredStudies.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-4 text-muted">
                        No hay estudios disponibles
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = this.filteredStudies.map(study => {
            const studyId = study.orthanc_id || study.study_id || '';
            const isSelected = this.selectedStudies.has(studyId);
            const r2Status = study.r2_status || 'none';
            const queueStatus = study.queue_status || null;
            
            // Determinar estado y badge con estados granulares
            let r2StatusClass = '';
            let r2StatusText = '';
            let isEnCurso = false;
            
            // Mapeo de estados granulares (como en Cola de Subida)
            const statusLabels = {
                'preparando_estudio': 'Preparando estudio',
                'descargando_instancias': 'Descargando instancias',
                'generando_zip': 'Generando ZIP',
                'generando_manifest': 'Generando manifest',
                'guardando_manifest_en_zip': 'Guardando manifest',
                'subiendo': 'Subiendo',
                'enviando_a_r2': 'Enviando a R2',
                'guardado_en_r2': 'Guardado en R2',
                'pending_extraction': 'Pendiente extracción',
                'extraccion_en_curso': 'Extracción en curso',
                'estudio_online': 'Estudio ONLINE',
                'uploading': 'Subiendo',
                'pending': 'Pendiente',
                'done': 'Completado',
                'error': 'Error',
                'cancelled': 'Cancelado'
            };
            
            const statusColors = {
                'preparando_estudio': 'info',
                'descargando_instancias': 'info',
                'generando_zip': 'info',
                'generando_manifest': 'info',
                'guardando_manifest_en_zip': 'info',
                'subiendo': 'primary',
                'enviando_a_r2': 'primary',
                'guardado_en_r2': 'success',
                'pending_extraction': 'primary',
                'extraccion_en_curso': 'primary',
                'estudio_online': 'success',
                'uploading': 'info',
                'pending': 'warning',
                'done': 'success',
                'error': 'danger',
                'cancelled': 'secondary'
            };
            
            // Priorizar estado R2 real:
            // - si está online => "En R2"
            // - si NO está online, no mostrar "done" de cola como si estuviera en R2
            if (r2Status === 'online') {
                // Si está en R2, mostrar "En R2" (aunque pueda haber queue_status)
                r2StatusText = 'En R2';
                r2StatusClass = 'r2-status-online';
            } else if (queueStatus && statusLabels[queueStatus] && !['done', 'estudio_online'].includes(queueStatus)) {
                // Si está en cola, mostrar estado granular
                r2StatusText = statusLabels[queueStatus];
                r2StatusClass = `r2-status-${statusColors[queueStatus] || 'info'}`;
                isEnCurso = ['pending', 'uploading', 'preparando_estudio', 'descargando_instancias', 'generando_zip', 'generando_manifest', 'guardando_manifest_en_zip', 'subiendo', 'enviando_a_r2', 'guardado_en_r2', 'pending_extraction', 'extraccion_en_curso'].includes(queueStatus);
            } else {
                // Estado R2 normal
                r2StatusText = {
                    'online': 'En R2',
                    'pending': 'Pendiente',
                    'none': 'No en R2'
                }[r2Status] || 'Desconocido';
                r2StatusClass = `r2-status-${r2Status}`;
            }
            
            const studyDate = this.formatDate(study.study_date);
            const studyTime = this.formatTime(study.study_time);
            const patientName = this.escapeHtml(study.patient_name || 'N/A');
            const studyDescription = this.escapeHtml(study.study_description || 'N/A');
            
            return `
                <tr class="${isSelected ? 'selected' : ''}" data-study-id="${studyId}">
                    <td>
                        <input type="checkbox" class="study-checkbox" 
                               data-study-id="${studyId}" 
                               ${isSelected ? 'checked' : ''}>
                    </td>
                    <td>${studyDate}</td>
                    <td class="d-none d-md-table-cell">${studyTime}</td>
                    <td>${patientName}</td>
                    <td class="d-none d-lg-table-cell">${this.escapeHtml(study.patient_id || 'N/A')}</td>
                    <td class="d-none d-sm-table-cell">
                        ${study.modality && study.modality !== 'N/A' && study.modality !== '' 
                            ? `<span class="badge bg-info">${this.escapeHtml(study.modality)}</span>` 
                            : '<span class="text-muted">N/A</span>'}
                    </td>
                    <td class="d-none d-xl-table-cell">${studyDescription}</td>
                    <td>
                        <span class="badge r2-status-badge ${r2StatusClass}">${r2StatusText}</span>
                    </td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap align-items-center">
                            ${study.viewer_url ? `
                            <button type="button" class="btn btn-sm btn-outline-primary view-study-pacs-btn"
                                    data-viewer-url="${this.escapeHtml(study.viewer_url)}"
                                    title="Ver estudio en el visor">
                                <i class="fas fa-eye"></i>
                                <span class="d-none d-xl-inline ms-1">Ver</span>
                            </button>
                            ` : ''}
                            <button type="button" class="btn btn-sm btn-success enqueue-single-btn" 
                                data-study-id="${studyId}"
                                ${r2Status === 'online' || isEnCurso ? 'disabled' : ''}
                                title="Encolar a R2">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
        
        // Event listeners para checkboxes
        tbody.querySelectorAll('.study-checkbox').forEach(cb => {
            cb.addEventListener('change', (e) => {
                const studyId = e.target.dataset.studyId;
                if (e.target.checked) {
                    this.selectedStudies.add(studyId);
                } else {
                    this.selectedStudies.delete(studyId);
                }
                this.updateEnqueueButton();
            });
        });
        
        tbody.querySelectorAll('.view-study-pacs-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const url = e.currentTarget.getAttribute('data-viewer-url');
                if (url) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
            });
        });

        // Event listeners para botones individuales
        tbody.querySelectorAll('.enqueue-single-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const studyId = e.target.closest('button').dataset.studyId;
                this.enqueueStudy(studyId);
            });
        });
        
        // Actualizar contador
        document.getElementById('studiesCount').textContent = `${this.filteredStudies.length} estudios`;
    }

    /**
     * Actualiza los botones de modalidad
     * IMPORTANTE: Siempre usa this.studies (TODOS los estudios sin filtrar),
     * NO this.filteredStudies, para que todos los botones de modalidad estén disponibles
     */
    updateModalityButtons() {
        const container = document.getElementById('modalityButtonsContainer');
        if (!container) return;
        
        // Obtener modalidades únicas de TODOS los estudios (sin filtrar)
        // Usar this.studies, NO this.filteredStudies, para mostrar todas las modalidades disponibles
        const modalities = new Set();
        this.studies.forEach(study => {
            // Buscar modalidad en diferentes campos posibles
            let modality = study.modality || study.Modality || study.modality_type || '';
            
            if (modality) {
                // Si viene como string separado por comas, dividirlo
                if (typeof modality === 'string' && modality.includes(',')) {
                    modality.split(',').forEach(m => {
                        const mod = m.trim();
                        if (mod && mod !== 'N/A' && mod !== '') {
                            modalities.add(mod);
                        }
                    });
                } else if (modality && modality !== 'N/A' && modality !== '') {
                    modalities.add(modality.trim());
                }
            }
        });
        
        const modalityArray = Array.from(modalities).sort();
        
        console.log('🔍 Modalidades detectadas:', modalityArray);
        console.log('📊 Total de estudios:', this.studies.length);
        
        // Si no hay modalidades pero hay estudios, puede que las modalidades no estén cargadas
        if (modalityArray.length === 0 && this.studies.length > 0) {
            console.warn('⚠️ No se encontraron modalidades en los estudios. Puede que necesiten cargarse desde Orthanc.');
        }
        
        container.innerHTML = `
            <button class="btn modality-btn ${this.currentFilters.modalities.length === 0 ? 'active' : ''}" data-modality="all">Todas</button>
            ${modalityArray.map(mod => `
                <button class="btn modality-btn ${this.currentFilters.modalities.includes(mod) ? 'active' : ''}" data-modality="${mod}">${mod}</button>
            `).join('')}
        `;
        
        // Event listeners - permitir selección múltiple (toggle)
        container.querySelectorAll('.modality-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modality = e.target.dataset.modality;
                
                if (modality === 'all') {
                    // Si se hace clic en "Todas", limpiar todas las selecciones
                    this.currentFilters.modalities = [];
                    container.querySelectorAll('.modality-btn').forEach(b => {
                        b.classList.remove('active');
                        if (b.dataset.modality === 'all') {
                            b.classList.add('active');
                        }
                    });
                } else {
                    // Toggle de la modalidad seleccionada
                    const index = this.currentFilters.modalities.indexOf(modality);
                    if (index > -1) {
                        // Deseleccionar
                        this.currentFilters.modalities.splice(index, 1);
                        e.target.classList.remove('active');
                    } else {
                        // Seleccionar
                        this.currentFilters.modalities.push(modality);
                        e.target.classList.add('active');
                    }
                    
                    // Actualizar botón "Todas"
                    const allBtn = container.querySelector('[data-modality="all"]');
                    if (allBtn) {
                        if (this.currentFilters.modalities.length === 0) {
                            allBtn.classList.add('active');
                        } else {
                            allBtn.classList.remove('active');
                        }
                    }
                }
                
                // Guardar filtros y aplicar
                this.saveToCache();
                this.applyFilters();
            });
        });
    }

    /**
     * Toggle seleccionar todos
     */
    toggleSelectAll(checked) {
        this.selectedStudies.clear();
        if (checked) {
            this.filteredStudies.forEach(study => {
                const studyId = study.orthanc_id || study.study_id || '';
                if (study.r2_status !== 'online') {
                    this.selectedStudies.add(studyId);
                }
            });
        }
        
        // Actualizar checkboxes
        document.querySelectorAll('.study-checkbox').forEach(cb => {
            const studyId = cb.dataset.studyId;
            cb.checked = this.selectedStudies.has(studyId);
        });
        
        this.updateEnqueueButton();
    }

    /**
     * Actualiza el botón de encolar
     */
    updateEnqueueButton() {
        const btn = document.getElementById('enqueueSelectedBtn');
        if (btn) {
            btn.disabled = this.selectedStudies.size === 0;
            if (this.selectedStudies.size > 0) {
                btn.innerHTML = `<i class="fas fa-cloud-upload-alt me-2"></i>Encolar (${this.selectedStudies.size})`;
            } else {
                btn.innerHTML = `<i class="fas fa-cloud-upload-alt me-2"></i>Encolar a R2`;
            }
        }
    }

    /**
     * Encola estudios seleccionados
     */
    async enqueueSelected() {
        if (this.selectedStudies.size === 0) return;
        
        const studyIds = Array.from(this.selectedStudies);
        await this.enqueueStudies(studyIds);
    }

    /**
     * Encola un estudio individual
     */
    async enqueueStudy(studyId) {
        await this.enqueueStudies([studyId]);
    }

    /**
     * Encola múltiples estudios
     */
    async enqueueStudies(studyIds) {
        try {
            const response = await fetch(`${this.apiBaseUrl}enqueue-batch.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ study_ids: studyIds })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Mostrar notificación
                this.showNotification(`✓ ${data.success_count} estudios encolados correctamente`, 'success');
                
                // Actualizar estados R2 y de cola sin consultar PACS
                await this.updateStudiesR2Status(studyIds);
                
                // Mover estudios encolados al principio de la lista
                this.moveEnqueuedStudiesToTop(studyIds);
                
                // Limpiar selección
                this.selectedStudies.clear();
                document.getElementById('selectAllCheckbox').checked = false;
                this.updateEnqueueButton();
                
                // Recargar cola y estudios R2 automáticamente
                await this.loadQueueStatus();
                await this.loadR2Studies();
                await this.loadR2Usage(true);
                
                // Si no estamos en la pestaña de cola, cambiar a ella para ver el progreso
                if (this.currentTab !== 'queue') {
                    const queueTab = document.getElementById('queue-tab');
                    if (queueTab) {
                        queueTab.click();
                    }
                }
            } else {
                throw new Error(data.error || 'Error encolando estudios');
            }
        } catch (error) {
            console.error('Error encolando estudios:', error);
            this.showNotification(`✗ Error: ${error.message}`, 'error');
        }
    }

    /**
     * Carga estado de la cola
     */
    async loadQueueStatus(silent = false) {
        const tbody = document.getElementById('queueTableBody');
        if (!tbody) return;
        
        // Indicador visual de actualización (solo si no es silencioso)
        const refreshBtn = document.getElementById('refreshQueueBtn');
        if (!silent && refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Actualizando...';
        }
        
        try {
            const response = await fetch(`${this.apiBaseUrl}queue-status.php`);
            const data = await response.json();
            
            if (data.success) {
                const queue = data.data || [];
                
                if (queue.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                No hay estudios en la cola
                            </td>
                        </tr>
                    `;
                } else {
                    tbody.innerHTML = queue.map(item => {
                        const statusColors = {
                            'pending': 'warning',
                            'preparando_estudio': 'info',
                            'descargando_instancias': 'info',
                            'generando_zip': 'info',
                            'generando_manifest': 'info',
                            'guardando_manifest_en_zip': 'info',
                            'subiendo': 'primary',
                            'enviando_a_r2': 'primary',
                            'guardado_en_r2': 'success',
                            'uploading': 'info',
                            'done': 'success',
                            'error': 'danger',
                            'cancelled': 'secondary',
                            'pending_extraction': 'primary',
                            'extraccion_en_curso': 'primary',
                            'estudio_online': 'success'
                        };
                        const statusLabels = {
                            'pending': 'Pendiente',
                            'preparando_estudio': 'Preparando estudio',
                            'descargando_instancias': 'Descargando instancias',
                            'generando_zip': 'Generando ZIP',
                            'generando_manifest': 'Generando manifest',
                            'guardando_manifest_en_zip': 'Guardando manifest en ZIP',
                            'subiendo': 'Subiendo',
                            'enviando_a_r2': 'Enviando a R2',
                            'guardado_en_r2': 'Guardado en R2',
                            'uploading': 'Subiendo',
                            'done': 'Completado',
                            'error': 'Error',
                            'cancelled': 'Cancelado',
                            'pending_extraction': 'Pendiente extracción',
                            'extraccion_en_curso': 'Extracción en curso',
                            'estudio_online': 'Estudio ONLINE'
                        };
                        const statusColor = statusColors[item.status] || 'secondary';
                        const uploadingStatesEarly = ['uploading', 'subiendo', 'enviando_a_r2'];
                        const isResyncJob = item.is_resync === 1 || item.is_resync === true || item.is_resync === '1';
                        let statusLabel = statusLabels[item.status] || item.status;
                        if (isResyncJob) {
                            if (item.status === 'pending') {
                                statusLabel = 'Pendiente · sync R2';
                            } else if (uploadingStatesEarly.includes(item.status)) {
                                statusLabel = 'Actualizando R2';
                            } else if (item.status === 'done') {
                                statusLabel = 'Sync R2 completado';
                            }
                        }
                        
                        // Detectar método de upload (verificar upload_method)
                        // upload_method puede ser 'instance', 'zip', 'pre-download', 'zip-extract-upload', o 'rclone'
                        let isZipMode = item.upload_method === 'zip' || (item.zip_path && item.zip_path.length > 0);
                        let isPreDownloadMode = item.upload_method === 'pre-download';
                        let isZipExtractUploadMode = item.upload_method === 'zip-extract-upload';
                        let isRcloneMode = item.upload_method === 'rclone';
                        // Para ítems pending sin método explícito (o con 'instance' como default del ENUM),
                        // usar el método configurado actualmente en el sistema
                        const hasExplicitMethod = item.upload_method && item.upload_method !== 'instance';
                        if (!isZipMode && !isPreDownloadMode && !isZipExtractUploadMode && !isRcloneMode && item.status === 'pending' && !hasExplicitMethod) {
                            isZipMode = this.currentUploadMethod === 'zip';
                            isPreDownloadMode = this.currentUploadMethod === 'pre-download';
                            isZipExtractUploadMode = this.currentUploadMethod === 'zip-extract-upload';
                            isRcloneMode = this.currentUploadMethod === 'rclone';
                        }
                        
                        // Debug: Log para verificar detección de modo ZIP (solo en casos problemáticos)
                        // Comentado para reducir ruido en consola
                        // if (item.status === 'uploading' || item.status === 'pending_extraction' || (item.status === 'pending' && isZipMode)) {
                        //     console.log('🔍 Detección modo ZIP:', {
                        //         queue_id: item.id,
                        //         upload_method: item.upload_method,
                        //         zip_path: item.zip_path,
                        //         currentUploadMethod: this.currentUploadMethod,
                        //         isZipMode: isZipMode,
                        //         status: item.status
                        //     });
                        // }
                        
                        // Calcular progreso si está en proceso de upload
                        let progressHtml = '';
                        let statusInfoHtml = '';
                        let statusCompactInfo = ''; // Info compacta para mostrar durante upload
                        
                        // Estados que indican que está en proceso de upload/subida
                        const uploadingStates = ['uploading', 'subiendo', 'enviando_a_r2'];
                        const processingStates = ['preparando_estudio', 'descargando_instancias', 'generando_zip', 'generando_manifest', 'guardando_manifest_en_zip'];
                        const isUploading = uploadingStates.includes(item.status);
                        const isProcessing = processingStates.includes(item.status);
                        
                        if (isUploading || isProcessing || item.status === 'guardado_en_r2') {
                            // Inicializar variables que se usarán en modalData
                            let progress = 0;
                            let speedText = '';
                            let timeText = '';
                            
                            // Para etapas de procesamiento, mostrar mensaje con tamaño parcial si está disponible
                            if (isProcessing) {
                                let sizeInfo = '';
                                // Si está generando ZIP y tenemos bytes_uploaded, mostrar tamaño parcial
                                if (item.status === 'generando_zip' && item.bytes_uploaded > 0) {
                                    const partialSize = this.formatBytes(item.bytes_uploaded);
                                    const totalSize = item.total_bytes > 0 ? this.formatBytes(item.total_bytes) : 'calculando...';
                                    sizeInfo = ` <small class="text-muted">(${partialSize}${item.total_bytes > 0 ? ' / ' + totalSize : ''})</small>`;
                                }
                                progressHtml = `
                                    <div class="spinner-border spinner-border-sm text-info me-2" role="status">
                                        <span class="visually-hidden">Procesando...</span>
                                    </div>
                                    <small class="text-muted">${statusLabel}${sizeInfo}</small>
                                `;
                                statusCompactInfo = '';
                            } else if (isUploading) {
                                // Solo durante "subiendo" mostrar barra de progreso y velocidad
                                let progressLabel = '';
                                
                                if (isZipMode) {
                                    // Modo ZIP: progreso basado en bytes
                                    if (item.bytes_uploaded > 0 && item.total_bytes > 0) {
                                        progress = Math.round(item.bytes_uploaded / item.total_bytes * 100);
                                    } else {
                                        progress = 0; // Indeterminado durante la subida del ZIP
                                    }
                                    const zipSize = item.total_bytes > 0 ? this.formatBytes(item.total_bytes) : 'calculando...';
                                    progressLabel = `<i class="fas fa-file-archive me-1"></i>ZIP: ${zipSize}`;
                                } else {
                                    // Modo instance: progreso basado en instancias
                                    if (item.total_instances > 0) {
                                        progress = Math.round((item.instances_uploaded || 0) / item.total_instances * 100);
                                    }
                                    progressLabel = `${item.instances_uploaded || 0} / ${item.total_instances || '?'} instancias`;
                                    if (item.total_bytes > 0) {
                                        progressLabel += ` • ${this.formatBytes(item.bytes_uploaded || 0)} / ${this.formatBytes(item.total_bytes)}`;
                                    }
                                }
                                
                                // Barra de progreso (animada si progress=0 en ZIP mode → indeterminada)
                                const isIndeterminate = isZipMode && progress === 0;
                                progressHtml = `
                                    <div class="progress mb-1" style="height: 20px;">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" 
                                             role="progressbar" 
                                             style="width: ${isIndeterminate ? 100 : progress}%; opacity: ${isIndeterminate ? 0.5 : 1};"
                                             aria-valuenow="${progress}" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                            ${isIndeterminate ? 'Subiendo...' : `${progress}%`}
                                        </div>
                                    </div>
                                    <small class="text-muted">${progressLabel}</small>
                                `;
                                
                                // Velocidad de upload (SOLO durante etapa "subiendo")
                                // Nota: backend guarda este campo como MB/s (aunque el nombre sea upload_speed_mbps).
                                const speedMbps = parseFloat(item.upload_speed_mbps) || null;
                                let speedBytesPerSec = null;
                                
                                if (speedMbps && speedMbps > 0) {
                                    speedText = `${speedMbps.toFixed(2)} MB/s`;
                                    speedBytesPerSec = speedMbps * 1024 * 1024;
                                } else if (item.upload_started_at && item.bytes_uploaded > 0) {
                                    const uploadStartMs = new Date(item.upload_started_at).getTime();
                                    const now = Date.now();
                                    const elapsedSeconds = (now - uploadStartMs) / 1000;
                                    if (elapsedSeconds > 0) {
                                        const speedMBs = (item.bytes_uploaded / 1024 / 1024) / elapsedSeconds;
                                        if (speedMBs > 0) {
                                            speedText = `${speedMBs.toFixed(2)} MB/s`;
                                            speedBytesPerSec = item.bytes_uploaded / elapsedSeconds;
                                        }
                                    }
                                }
                                
                                // ETA estimada (en base a bytes restantes / velocidad)
                                let etaText = null;
                                if (item.total_bytes > 0 && item.bytes_uploaded >= 0 && speedBytesPerSec && speedBytesPerSec > 0) {
                                    const remainingBytes = Math.max(0, item.total_bytes - item.bytes_uploaded);
                                    if (remainingBytes > 0) {
                                        const etaSeconds = Math.ceil(remainingBytes / speedBytesPerSec);
                                        if (etaSeconds > 0) etaText = this.formatDuration(etaSeconds);
                                    }
                                }
                                
                                // Tiempo transcurrido total (desde que se creó la cola si existe; si no, desde upload_started_at)
                                if (item.created_at || item.upload_started_at) {
                                    try {
                                        const startMs = item.created_at
                                            ? new Date(item.created_at).getTime()
                                            : new Date(item.upload_started_at).getTime();
                                        if (!isNaN(startMs)) {
                                            const elapsedSeconds = Math.floor((Date.now() - startMs) / 1000);
                                            if (elapsedSeconds > 0) timeText = this.formatDuration(elapsedSeconds);
                                        }
                                    } catch (e) {
                                        console.warn('Error calculando tiempo transcurrido:', e);
                                    }
                                }
                                
                                statusCompactInfo = `
                                    <div class="small mt-1">
                                        ${speedText ? `<div><i class="fas fa-tachometer-alt me-1"></i>${speedText}</div>` : ''}
                                        ${etaText ? `<div><i class="fas fa-hourglass-half me-1"></i>ETA: ${etaText}</div>` : ''}
                                        ${timeText ? `<div><i class="fas fa-clock me-1"></i>${timeText}</div>` : ''}
                                    </div>
                                `;
                            } else if (item.status === 'guardado_en_r2') {
                                // ZIP guardado en R2, esperando extracción
                                progressHtml = `
                                    <span class="badge bg-success">
                                        <i class="fas fa-check-circle me-1"></i>ZIP guardado en R2
                                    </span>
                                `;
                                statusCompactInfo = '';
                            }
                            
                            // Datos para el modal
                            const instancesInfo = isZipMode
                                ? `${item.total_instances || '?'} instancias en ZIP`
                                : `${item.instances_uploaded || 0} / ${item.total_instances || '?'}`;
                            const bytesInfo = item.total_bytes > 0
                                ? `${this.formatBytes(item.bytes_uploaded || 0)} / ${this.formatBytes(item.total_bytes)}`
                                : 'N/A';
                            
                            const modalData = {
                                speed: speedText || (isUploading ? 'Calculando...' : 'N/A'),
                                time: timeText || 'N/A',
                                ip_local: item.upload_ip || 'N/A',
                                ip_wan: item.upload_ip_wan || 'N/A',
                                instances: instancesInfo,
                                bytes: bytesInfo,
                                started_at: item.upload_started_at || 'N/A',
                                progress: progress || 0,
                                method: isZipMode ? 'ZIP' : (isPreDownloadMode ? 'Pre-Download' : (isZipExtractUploadMode ? 'ZIP-Extract-Upload' : (isRcloneMode ? 'Rclone' : 'Instance'))),
                                // Datos del paciente/estudio
                                patient_name: item.patient_name || null,
                                patient_id: item.patient_id || null,
                                study_date: item.study_date || null,
                                modality: item.modality || null,
                                study_description: item.study_description || null,
                                study_instance_uid: item.study_instance_uid || null,
                                orthanc_study_id: item.orthanc_study_id || null
                            };
                            
                            statusInfoHtml = `
                                <button class="btn btn-sm btn-outline-info queue-info-btn" 
                                        style="padding: 0.15rem 0.4rem; font-size: 0.75rem; line-height: 1.2;"
                                        data-queue-id="${item.id}"
                                        data-status="${item.status}"
                                        data-info='${JSON.stringify(modalData).replace(/'/g, "&apos;")}'
                                        title="Ver información detallada">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            `;
                            
                        } else if (item.status === 'done' || item.status === 'pending_extraction') {
                            // Badge de completado o pendiente de extracción
                            if (item.status === 'done') {
                                progressHtml = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Completado</span>';
                            } else {
                                progressHtml = '<span class="badge bg-primary"><i class="fas fa-file-archive me-1"></i>ZIP en R2</span>';
                            }
                            
                            // Calcular duración
                            let duration = '';
                            if (item.upload_duration_seconds !== null && item.upload_duration_seconds !== undefined) {
                                const dur = parseInt(item.upload_duration_seconds) || 0;
                                if (dur > 0) duration = this.formatDuration(dur);
                            } else if (item.upload_started_at && item.upload_finished_at) {
                                try {
                                    const startTime = new Date(item.upload_started_at).getTime();
                                    const endTime = new Date(item.upload_finished_at).getTime();
                                    if (!isNaN(startTime) && !isNaN(endTime)) {
                                        const dur = Math.floor((endTime - startTime) / 1000);
                                        if (dur > 0) duration = this.formatDuration(dur);
                                    }
                                } catch (e) {
                                    console.warn('Error calculando duración:', e);
                                }
                            }
                            
                            // Info de instancias/bytes según modo
                            const instancesInfo = isZipMode
                                ? `${item.total_instances || '?'} instancias en ZIP`
                                : `${item.total_instances || 0}`;
                            const bytesInfo = item.total_bytes > 0 ? this.formatBytes(item.total_bytes) : 'N/A';
                            
                            const modalData = {
                                duration: duration || 'N/A',
                                speed_min: parseFloat(item.upload_speed_min_mbps) || 0,
                                speed_max: parseFloat(item.upload_speed_max_mbps) || 0,
                                speed_avg: parseFloat(item.upload_speed_avg_mbps) || 0,
                                ip_local: item.upload_ip || 'N/A',
                                ip_wan: item.upload_ip_wan || 'N/A',
                                instances: instancesInfo,
                                bytes: bytesInfo,
                                started_at: item.upload_started_at || 'N/A',
                                finished_at: item.upload_finished_at || 'N/A',
                                method: isZipMode ? 'ZIP' : (isPreDownloadMode ? 'Pre-Download' : (isZipExtractUploadMode ? 'ZIP-Extract-Upload' : (isRcloneMode ? 'Rclone' : 'Instance'))),
                                zip_path: item.zip_path || null,
                                // Datos del paciente/estudio
                                patient_name: item.patient_name || null,
                                patient_id: item.patient_id || null,
                                study_date: item.study_date || null,
                                modality: item.modality || null,
                                study_description: item.study_description || null,
                                study_instance_uid: item.study_instance_uid || null,
                                orthanc_study_id: item.orthanc_study_id || null
                            };
                            
                            statusInfoHtml = `
                                <button class="btn btn-sm btn-outline-info queue-info-btn" 
                                        style="padding: 0.15rem 0.4rem; font-size: 0.75rem; line-height: 1.2;"
                                        data-queue-id="${item.id}"
                                        data-status="${item.status}"
                                        data-info='${JSON.stringify(modalData).replace(/'/g, "&apos;")}'
                                        title="Ver información detallada">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            `;
                        } else {
                            // Para pending, error, cancelled
                            const modalData = {
                                ip_local: item.upload_ip || 'N/A',
                                ip_wan: item.upload_ip_wan || 'N/A',
                                error: (item.last_error || 'N/A').substring(0, 200), // Limitar longitud del error
                                method: isZipMode ? 'ZIP' : (isPreDownloadMode ? 'Pre-Download' : (isZipExtractUploadMode ? 'ZIP-Extract-Upload' : (isRcloneMode ? 'Rclone' : 'Instance'))),
                                // Datos del paciente/estudio
                                patient_name: item.patient_name || null,
                                patient_id: item.patient_id || null,
                                study_date: item.study_date || null,
                                modality: item.modality || null,
                                study_description: item.study_description || null,
                                study_instance_uid: item.study_instance_uid || null,
                                orthanc_study_id: item.orthanc_study_id || null
                            };
                            
                            // Escapar correctamente el JSON para atributo HTML
                            const jsonStr = JSON.stringify(modalData);
                            const escapedJson = jsonStr.replace(/'/g, "&apos;").replace(/"/g, "&quot;");
                            
                            statusInfoHtml = `
                                <button class="btn btn-sm btn-outline-secondary queue-info-btn" 
                                        style="padding: 0.15rem 0.4rem; font-size: 0.75rem; line-height: 1.2;"
                                        data-queue-id="${item.id}"
                                        data-status="${item.status}"
                                        data-info="${escapedJson}"
                                        title="Ver información detallada">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            `;
                        }
                        
                        return `
                            <tr>
                                <td>${item.id}</td>
                                <td>
                                    <code>${(item.orthanc_study_id || '').substring(0, 20)}...</code>
                                    ${isZipMode ? '<span class="badge bg-dark ms-1" title="Método ZIP"><i class="fas fa-file-archive"></i></span>' : ''}
                                </td>
                                <td>
                                    <span class="badge bg-${statusColor}">${statusLabel}</span>
                                    ${isResyncJob ? '<span class="badge ms-1 align-middle" style="background:#6f42c1" title="Sincronización incremental: nuevas instancias hacia R2"><i class="fas fa-sync-alt me-1"></i>Re-sync</span>' : ''}
                                    ${statusCompactInfo}
                                    ${statusInfoHtml}
                                </td>
                                <td>
                                    ${progressHtml || '-'}
                                </td>
                                <td>${item.retry_count || 0}</td>
                                <td>${item.last_error ? `<small class="text-danger">${item.last_error.substring(0, 50)}...</small>` : '-'}</td>
                                <td>${item.created_at || 'N/A'}</td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        ${item.status === 'error' && item.retry_count < 3 ? `
                                            <button class="btn btn-outline-primary retry-btn" data-id="${item.id}" title="Reintentar">
                                                <i class="fas fa-redo"></i>
                                            </button>
                                        ` : ''}
                                        ${['pending', 'uploading', 'preparando_estudio', 'descargando_instancias', 'generando_zip', 'generando_manifest', 'guardando_manifest_en_zip', 'subiendo', 'enviando_a_r2'].includes(item.status) ? `
                                            <button class="btn btn-outline-danger cancel-job-btn" data-id="${item.id}" title="Cancelar trabajo">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        ` : ''}
                                        ${['done', 'error', 'cancelled', 'pending_extraction'].includes(item.status) ? `
                                            <button class="btn btn-outline-secondary delete-job-btn" data-id="${item.id}" title="Eliminar de la cola">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        ` : ''}
                                    </div>
                                </td>
                            </tr>
                        `;
                    }).join('');
                    
                    // Re-conectar event listeners para botones de reintentar y info
                    this.setupRetryButtons();
                    this.setupQueueInfoButtons();
                    this.setupQueueActionButtons();
                }
            }
        } catch (error) {
            console.error('Error cargando cola:', error);
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4 text-danger">
                        Error: ${error.message}
                    </td>
                </tr>
            `;
        } finally {
            // Restaurar botón de actualizar
            if (!silent && refreshBtn) {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Actualizar';
            }
        }
    }
    
    /**
     * Configura los botones de reintentar en la tabla de cola
     */
    setupRetryButtons() {
        const retryButtons = document.querySelectorAll('.retry-btn');
        retryButtons.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const queueId = e.target.closest('.retry-btn').getAttribute('data-id');
                if (queueId) {
                    await this.retryQueueItem(queueId);
                }
            });
        });
    }
    
    /**
     * Muestra un modal de confirmación de Bootstrap
     * @param {string} title Título del modal
     * @param {string} message Mensaje de confirmación
     * @param {string} confirmText Texto del botón de confirmar (default: 'Confirmar')
     * @param {string} cancelText Texto del botón de cancelar (default: 'Cancelar')
     * @param {string} type Tipo de modal: 'danger', 'warning', 'info', 'success' (default: 'warning')
     * @returns {Promise<boolean>} Promise que se resuelve con true si confirma, false si cancela
     */
    async showConfirmModal(title, message, confirmText = 'Confirmar', cancelText = 'Cancelar', type = 'warning') {
        return new Promise((resolve) => {
            // Crear o reutilizar modal de confirmación
            let modal = document.getElementById('confirmModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'confirmModal';
                modal.className = 'modal fade';
                modal.setAttribute('tabindex', '-1');
                modal.setAttribute('data-bs-backdrop', 'static');
                modal.setAttribute('data-bs-keyboard', 'false');
                document.body.appendChild(modal);
            }

            const iconClass = {
                'danger': 'fas fa-exclamation-triangle text-danger',
                'warning': 'fas fa-exclamation-triangle text-warning',
                'info': 'fas fa-info-circle text-info',
                'success': 'fas fa-check-circle text-success'
            }[type] || 'fas fa-question-circle text-warning';

            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="${iconClass} me-2"></i>${title}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>${message}</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${cancelText}</button>
                            <button type="button" class="btn btn-${type === 'danger' ? 'danger' : 'primary'}" id="confirmModalBtn">${confirmText}</button>
                        </div>
                    </div>
                </div>
            `;

            const bsModal = new bootstrap.Modal(modal);
            
            // Limpiar listeners anteriores
            const confirmBtn = modal.querySelector('#confirmModalBtn');
            const cancelBtn = modal.querySelector('.btn-secondary');
            
            const handleConfirm = () => {
                bsModal.hide();
                resolve(true);
            };
            
            const handleCancel = () => {
                bsModal.hide();
                resolve(false);
            };

            confirmBtn.addEventListener('click', handleConfirm);
            cancelBtn.addEventListener('click', handleCancel);
            
            // Resolver con false cuando se cierra el modal sin confirmar
            modal.addEventListener('hidden.bs.modal', () => {
                confirmBtn.removeEventListener('click', handleConfirm);
                cancelBtn.removeEventListener('click', handleCancel);
            }, { once: true });

            bsModal.show();
        });
    }

    /**
     * Configura los botones de acción en la tabla de cola (cancelar, eliminar)
     */
    setupQueueActionButtons() {
        // Botones de cancelar trabajo
        const cancelButtons = document.querySelectorAll('.cancel-job-btn');
        cancelButtons.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const queueId = btn.getAttribute('data-id');
                if (queueId) {
                    const confirmed = await this.showConfirmModal(
                        'Cancelar Trabajo',
                        '¿Estás seguro de cancelar este trabajo?',
                        'Sí, Cancelar',
                        'No',
                        'warning'
                    );
                    if (confirmed) {
                        await this.cancelJob(queueId);
                    }
                }
            });
        });

        // Botones de eliminar trabajo
        const deleteButtons = document.querySelectorAll('.delete-job-btn');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const queueId = btn.getAttribute('data-id');
                if (queueId) {
                    const confirmed = await this.showConfirmModal(
                        'Eliminar Trabajo',
                        '¿Estás seguro de eliminar este trabajo de la cola?',
                        'Sí, Eliminar',
                        'No',
                        'danger'
                    );
                    if (confirmed) {
                        await this.deleteJob(queueId);
                    }
                }
            });
        });
    }

    /**
     * Configura los botones de información en la tabla de cola
     */
    setupQueueInfoButtons() {
        const infoButtons = document.querySelectorAll('.queue-info-btn');
        infoButtons.forEach(btn => {
            // Remover listeners anteriores para evitar duplicados
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            
            newBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const queueId = newBtn.getAttribute('data-queue-id');
                const status = newBtn.getAttribute('data-status');
                let infoJson = newBtn.getAttribute('data-info');
                
                // Decodificar HTML entities
                if (infoJson) {
                    infoJson = infoJson.replace(/&apos;/g, "'").replace(/&quot;/g, '"');
                }
                
                try {
                    const info = JSON.parse(infoJson);
                    this.showQueueInfoModal(queueId, status, info);
                } catch (error) {
                    console.error('Error parseando info del botón:', error, infoJson);
                    // Mostrar modal con datos básicos si falla el parsing
                    this.showQueueInfoModal(queueId, status, {
                        error: 'Error parseando información: ' + error.message,
                        raw_data: infoJson ? infoJson.substring(0, 100) : 'N/A'
                    });
                }
            });
        });
    }
    
    /**
     * Muestra modal con información detallada del item de la cola
     */
    showQueueInfoModal(queueId, status, info) {
        // Crear o reutilizar modal
        let modal = document.getElementById('queueInfoModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'queueInfoModal';
            modal.className = 'modal fade';
            modal.setAttribute('tabindex', '-1');
            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-info-circle me-2"></i>Información del Upload
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="queueInfoModalBody">
                            <!-- Contenido dinámico -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="refreshQueueInfoBtn">
                                <i class="fas fa-sync-alt me-1"></i>Actualizar
                            </button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Configurar botón de actualizar
            const refreshBtn = document.getElementById('refreshQueueInfoBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    if (this.currentQueueInfoQueueId) {
                        // Recargar datos y actualizar modal
                        this.loadQueueStatus(true).then(() => {
                            // Buscar el botón info del item actual y obtener sus datos actualizados
                            const btn = document.querySelector(`.queue-info-btn[data-queue-id="${this.currentQueueInfoQueueId}"]`);
                            if (btn) {
                                const status = btn.getAttribute('data-status');
                                let infoJson = btn.getAttribute('data-info');
                                if (infoJson) {
                                    infoJson = infoJson.replace(/&apos;/g, "'");
                                    try {
                                        const info = JSON.parse(infoJson);
                                        this.updateQueueInfoModalContent(status, info);
                                    } catch (e) {
                                        console.error('Error actualizando modal:', e);
                                    }
                                }
                            }
                        });
                    }
                });
            }
        }
        
        // Guardar queueId para actualización automática
        this.currentQueueInfoQueueId = queueId;
        
        // Limpiar intervalo anterior si existe
        if (this.queueInfoModalInterval) {
            clearInterval(this.queueInfoModalInterval);
        }
        
        // Si está en proceso (uploading o processing), actualizar automáticamente cada 3 segundos
        const activeStates = ['uploading', 'subiendo', 'enviando_a_r2', 'preparando_estudio', 'descargando_instancias', 'generando_zip', 'generando_manifest', 'guardando_manifest_en_zip', 'guardado_en_r2'];
        if (activeStates.includes(status)) {
            this.queueInfoModalInterval = setInterval(() => {
                if (document.getElementById('queueInfoModal') && document.getElementById('queueInfoModal').classList.contains('show')) {
                    // Recargar datos y actualizar
                    this.loadQueueStatus(true).then(() => {
                        const btn = document.querySelector(`.queue-info-btn[data-queue-id="${queueId}"]`);
                        if (btn) {
                            const newStatus = btn.getAttribute('data-status');
                            let infoJson = btn.getAttribute('data-info');
                            if (infoJson) {
                                infoJson = infoJson.replace(/&apos;/g, "'");
                                try {
                                    const info = JSON.parse(infoJson);
                                    this.updateQueueInfoModalContent(newStatus, info);
                                    // Si cambió a un estado final, detener actualización
                                    const finalStates = ['done', 'pending_extraction', 'error', 'cancelled', 'estudio_online'];
                                    if (finalStates.includes(newStatus)) {
                                        clearInterval(this.queueInfoModalInterval);
                                    }
                                } catch (e) {
                                    console.error('Error actualizando modal:', e);
                                }
                            }
                        }
                    });
                } else {
                    // Modal cerrado, detener actualización
                    clearInterval(this.queueInfoModalInterval);
                }
            }, 3000);
            
            // Detener cuando se cierre el modal
            modal.addEventListener('hidden.bs.modal', () => {
                if (this.queueInfoModalInterval) {
                    clearInterval(this.queueInfoModalInterval);
                    this.queueInfoModalInterval = null;
                }
            });
        }
        
        // Construir contenido según el estado
        let content = '';
        
        if (status === 'uploading' || status === 'subiendo' || status === 'enviando_a_r2') {
            content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-chart-line me-2"></i>Progreso</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Progreso:</strong></td><td>${info.progress || 0}%</td></tr>
                            <tr><td><strong>Instancias:</strong></td><td>${info.instances || 'N/A'}</td></tr>
                            <tr><td><strong>Bytes:</strong></td><td>${info.bytes || 'N/A'}</td></tr>
                            <tr><td><strong>Velocidad actual:</strong></td><td>${info.speed || 'Calculando...'}</td></tr>
                            <tr><td><strong>Tiempo transcurrido:</strong></td><td>${info.time || 'N/A'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-tachometer-alt me-2"></i>Velocidades</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Actual:</strong></td><td>${info.speed || 'Calculando...'}</td></tr>
                            <tr><td><strong>Mínima:</strong></td><td>${(info.speed_min || 0).toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Máxima:</strong></td><td>${(info.speed_max || 0).toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Promedio:</strong></td><td>${(info.speed_avg || 0).toFixed(2)} MB/s</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-network-wired me-2"></i>Conexión</h6>
                        <table class="table table-sm">
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan}</code></td></tr>
                            <tr><td><strong>Iniciado:</strong></td><td>${info.started_at}</td></tr>
                        </table>
                        <div class="alert alert-info mt-2">
                            <small><i class="fas fa-info-circle me-1"></i>El upload está en progreso. Esta información se actualiza automáticamente cada 3 segundos.</small>
                        </div>
                    </div>
                </div>
            `;
        } else if (status === 'done') {
            content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-check-circle me-2 text-success"></i>Resultado</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Estado:</strong></td><td><span class="badge bg-success">Completado</span></td></tr>
                            <tr><td><strong>Duración total:</strong></td><td>${info.duration || 'N/A'}</td></tr>
                            <tr><td><strong>Instancias:</strong></td><td>${info.instances}</td></tr>
                            <tr><td><strong>Tamaño total:</strong></td><td>${info.bytes}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-tachometer-alt me-2"></i>Velocidades</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Mínima:</strong></td><td>${info.speed_min.toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Máxima:</strong></td><td>${info.speed_max.toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Promedio:</strong></td><td>${info.speed_avg.toFixed(2)} MB/s</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-network-wired me-2"></i>Conexión</h6>
                        <table class="table table-sm">
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan}</code></td></tr>
                            <tr><td><strong>Iniciado:</strong></td><td>${info.started_at}</td></tr>
                            <tr><td><strong>Finalizado:</strong></td><td>${info.finished_at}</td></tr>
                        </table>
                    </div>
                </div>
            `;
        } else {
            content = `
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Estado: ${status}</h6>
                        <table class="table table-sm">
                            ${info.error && info.error !== 'N/A' ? `<tr><td><strong>Error:</strong></td><td class="text-danger">${info.error}</td></tr>` : ''}
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan}</code></td></tr>
                        </table>
                    </div>
                </div>
            `;
        }
        
        // Actualizar contenido del modal
        this.updateQueueInfoModalContent(status, info);
        
        // Mostrar modal
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
    
    /**
     * Actualiza el contenido del modal de información de la cola
     */
    /**
     * Genera el bloque HTML de datos del paciente/estudio para el modal
     */
    buildPatientInfoBlock(info) {
        const hasInfo = info.patient_name || info.patient_id || info.study_date || info.modality || info.study_description;
        if (!hasInfo && !info.study_instance_uid && !info.orthanc_study_id) return '';
        
        const formatDate = (d) => {
            if (!d || d.length < 8) return d || 'N/A';
            return `${d.substring(6,8)}/${d.substring(4,6)}/${d.substring(0,4)}`;
        };
        
        const pName = info.patient_name || '<span class="text-muted">N/A</span>';
        const pId = info.patient_id || '<span class="text-muted">N/A</span>';
        const sDate = info.study_date ? formatDate(info.study_date) : '<span class="text-muted">N/A</span>';
        const mod = info.modality || '<span class="text-muted">N/A</span>';
        const desc = info.study_description || '<span class="text-muted">N/A</span>';
        const sUID = info.study_instance_uid
            ? `<small><code style="word-break:break-all;">${info.study_instance_uid}</code></small>`
            : '<span class="text-muted">N/A</span>';
        const oId = info.orthanc_study_id
            ? `<small><code>${info.orthanc_study_id}</code></small>`
            : '<span class="text-muted">N/A</span>';
        
        return `
            <div class="row mt-3">
                <div class="col-12">
                    <h6><i class="fas fa-user-injured me-2"></i>Datos del Estudio</h6>
                    <table class="table table-sm table-bordered">
                        <tr><td style="width:35%"><strong>Paciente:</strong></td><td>${pName}</td></tr>
                        <tr><td><strong>ID Paciente:</strong></td><td>${pId}</td></tr>
                        <tr><td><strong>Fecha Estudio:</strong></td><td>${sDate}</td></tr>
                        <tr><td><strong>Modalidad:</strong></td><td>${mod}</td></tr>
                        <tr><td><strong>Descripción:</strong></td><td>${desc}</td></tr>
                        <tr><td><strong>Study UID:</strong></td><td>${sUID}</td></tr>
                        <tr><td><strong>Orthanc ID:</strong></td><td>${oId}</td></tr>
                    </table>
                </div>
            </div>
        `;
    }
    
    updateQueueInfoModalContent(status, info) {
        const modalBody = document.getElementById('queueInfoModalBody');
        if (!modalBody) return;
        
        let content = '';
        
        const isZipMode = info.method === 'ZIP';
        const isPreDownloadMode = info.method === 'Pre-Download';
        const isZipExtractUploadMode = info.method === 'ZIP-Extract-Upload';
        const isRcloneMode = info.method === 'Rclone';
        const methodBadge = isZipMode
            ? '<span class="badge bg-dark"><i class="fas fa-file-archive me-1"></i>ZIP</span>'
            : isPreDownloadMode
            ? '<span class="badge bg-info"><i class="fas fa-download me-1"></i>Pre-Download</span>'
            : isZipExtractUploadMode
            ? '<span class="badge bg-success"><i class="fas fa-layer-group me-1"></i>ZIP-Extract-Upload</span>'
            : isRcloneMode
            ? '<span class="badge bg-primary"><i class="fas fa-rocket me-1"></i>Rclone</span>'
            : '<span class="badge bg-secondary"><i class="fas fa-images me-1"></i>Instancias</span>';
        
        const statusLabels = {
            'pending': 'Pendiente',
            'preparando_estudio': 'Preparando estudio',
            'generando_zip': 'Generando ZIP',
            'generando_manifest': 'Generando manifest',
            'guardando_manifest_en_zip': 'Guardando manifest en ZIP',
            'subiendo': 'Subiendo',
            'enviando_a_r2': 'Enviando a R2',
            'guardado_en_r2': 'Guardado en R2',
            'uploading': 'Subiendo',
            'done': 'Completado',
            'error': 'Error',
            'cancelled': 'Cancelado',
            'pending_extraction': 'Pendiente extracción',
            'extraccion_en_curso': 'Extracción en curso',
            'estudio_online': 'Estudio ONLINE'
        };
        
        const uploadingStates = ['uploading', 'subiendo', 'enviando_a_r2'];
        const processingStates = ['preparando_estudio', 'generando_zip', 'generando_manifest', 'guardando_manifest_en_zip'];
        
        if (uploadingStates.includes(status)) {
            // Durante la subida, mostrar velocidad y progreso
            const progressLabel = isZipMode
                ? (info.progress > 0 ? `${info.progress}%` : 'Subiendo...')
                : `${info.progress || 0}%`;
            const instancesLabel = isZipMode ? 'Contenido del ZIP:' : 'Instancias:';
            
            content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-chart-line me-2"></i>Progreso</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Método:</strong></td><td>${methodBadge}</td></tr>
                            <tr><td><strong>Progreso:</strong></td><td>${progressLabel}</td></tr>
                            <tr><td><strong>${instancesLabel}</strong></td><td>${info.instances || 'N/A'}</td></tr>
                            <tr><td><strong>Tamaño:</strong></td><td>${info.bytes || 'N/A'}</td></tr>
                            <tr><td><strong>Velocidad actual:</strong></td><td>${info.speed || 'Calculando...'}</td></tr>
                            <tr><td><strong>Tiempo transcurrido:</strong></td><td>${info.time || 'N/A'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-tachometer-alt me-2"></i>Velocidades</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Actual:</strong></td><td>${info.speed || 'Calculando...'}</td></tr>
                            <tr><td><strong>Mínima:</strong></td><td>${(info.speed_min || 0).toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Máxima:</strong></td><td>${(info.speed_max || 0).toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Promedio:</strong></td><td>${(info.speed_avg || 0).toFixed(2)} MB/s</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-network-wired me-2"></i>Conexión</h6>
                        <table class="table table-sm">
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan}</code></td></tr>
                            <tr><td><strong>Iniciado:</strong></td><td>${info.started_at}</td></tr>
                        </table>
                        <div class="alert alert-info mt-2">
                            <small><i class="fas fa-info-circle me-1"></i>
                                ${isZipMode ? 'Subiendo ZIP a R2.' : 'Upload en progreso.'} 
                                Se actualiza automáticamente cada 3 segundos.
                            </small>
                        </div>
                    </div>
                </div>
                ${this.buildPatientInfoBlock(info)}
            `;
        } else if (processingStates.includes(status)) {
            // Durante el procesamiento, mostrar solo el estado
            content = `
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-info">
                            <h6><i class="fas fa-cog fa-spin me-2"></i>${statusLabels[status] || status}</h6>
                            <p class="mb-0">El estudio se está procesando. Esta información se actualiza automáticamente.</p>
                        </div>
                        <table class="table table-sm">
                            <tr><td><strong>Método:</strong></td><td>${methodBadge}</td></tr>
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local || 'N/A'}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan || 'N/A'}</code></td></tr>
                        </table>
                    </div>
                </div>
                ${this.buildPatientInfoBlock(info)}
            `;
        } else if (status === 'guardado_en_r2') {
            content = `
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i>ZIP guardado en R2</h6>
                            <p class="mb-0">El archivo ZIP se ha guardado exitosamente en R2. Esperando extracción.</p>
                        </div>
                        <table class="table table-sm">
                            <tr><td><strong>Método:</strong></td><td>${methodBadge}</td></tr>
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local || 'N/A'}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan || 'N/A'}</code></td></tr>
                        </table>
                    </div>
                </div>
                ${this.buildPatientInfoBlock(info)}
            `;
        } else if (status === 'done' || status === 'pending_extraction' || status === 'extraccion_en_curso' || status === 'estudio_online') {
            const statusBadge = status === 'done'
                ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Completado</span>'
                : status === 'estudio_online'
                ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Estudio ONLINE</span>'
                : status === 'extraccion_en_curso'
                ? '<span class="badge bg-primary"><i class="fas fa-cog fa-spin me-1"></i>Extracción en curso</span>'
                : '<span class="badge bg-primary"><i class="fas fa-file-archive me-1"></i>ZIP en R2 (pendiente extracción)</span>';
            const instancesLabel = isZipMode ? 'Contenido del ZIP:' : 'Instancias:';
            const speedMin = parseFloat(info.speed_min) || 0;
            const speedMax = parseFloat(info.speed_max) || 0;
            const speedAvg = parseFloat(info.speed_avg) || 0;
            
            content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-check-circle me-2 text-success"></i>Resultado</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Estado:</strong></td><td>${statusBadge}</td></tr>
                            <tr><td><strong>Método:</strong></td><td>${methodBadge}</td></tr>
                            <tr><td><strong>Duración total:</strong></td><td>${info.duration || 'N/A'}</td></tr>
                            <tr><td><strong>${instancesLabel}</strong></td><td>${info.instances}</td></tr>
                            <tr><td><strong>Tamaño total:</strong></td><td>${info.bytes}</td></tr>
                            ${isZipMode && info.zip_path ? `<tr><td><strong>Key en R2:</strong></td><td><small><code>${info.zip_path}</code></small></td></tr>` : ''}
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-tachometer-alt me-2"></i>Velocidades</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Mínima:</strong></td><td>${speedMin.toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Máxima:</strong></td><td>${speedMax.toFixed(2)} MB/s</td></tr>
                            <tr><td><strong>Promedio:</strong></td><td>${speedAvg.toFixed(2)} MB/s</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-network-wired me-2"></i>Conexión</h6>
                        <table class="table table-sm">
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan}</code></td></tr>
                            <tr><td><strong>Iniciado:</strong></td><td>${info.started_at}</td></tr>
                            <tr><td><strong>Finalizado:</strong></td><td>${info.finished_at || 'N/A'}</td></tr>
                        </table>
                    </div>
                </div>
                ${this.buildPatientInfoBlock(info)}
            `;
        } else {
            // Estados finales o de error (pending, error, cancelled, etc.)
            const finalStatusBadge = status === 'pending'
                ? '<span class="badge bg-warning">Pendiente</span>'
                : status === 'error'
                ? '<span class="badge bg-danger">Error</span>'
                : status === 'cancelled'
                ? '<span class="badge bg-secondary">Cancelado</span>'
                : `<span class="badge bg-secondary">${statusLabels[status] || status}</span>`;
            
            content = `
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-info-circle me-2"></i>Estado: ${finalStatusBadge}</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Método:</strong></td><td>${methodBadge}</td></tr>
                            ${info.error && info.error !== 'N/A' ? `<tr><td><strong>Error:</strong></td><td class="text-danger">${info.error}</td></tr>` : ''}
                            <tr><td><strong>IP Local:</strong></td><td><code>${info.ip_local || 'N/A'}</code></td></tr>
                            <tr><td><strong>IP WAN:</strong></td><td><code>${info.ip_wan || 'N/A'}</code></td></tr>
                        </table>
                    </div>
                </div>
                ${this.buildPatientInfoBlock(info)}
            `;
        }
        
        modalBody.innerHTML = content;
    }
    
    /**
     * Reintenta un item de la cola
     */
    async retryQueueItem(queueId) {
        try {
            const response = await fetch(`${this.apiBaseUrl}queue-control.php?action=retry_study`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    queue_id: queueId
                })
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification('✅ ' + result.message, 'success');
                await this.loadQueueStatus(true);
            } else {
                this.showNotification('❌ Error: ' + (result.error || 'Error desconocido'), 'error');
            }
        } catch (error) {
            console.error('Error reintentando:', error);
            this.showNotification('❌ Error al reintentar: ' + error.message, 'error');
        }
    }

    /**
     * Detiene el worker
     */
    async stopWorker() {
        const confirmed = await this.showConfirmModal(
            'Detener Worker',
            '¿Estás seguro de detener el worker? Esto detendrá el procesamiento de la cola.',
            'Sí, Detener',
            'Cancelar',
            'warning'
        );
        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}queue-control.php?action=stop_worker`, {
                method: 'POST'
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification('✅ ' + result.message, 'success');
            } else {
                this.showNotification('❌ Error: ' + (result.error || 'Error desconocido'), 'error');
            }
        } catch (error) {
            console.error('Error deteniendo worker:', error);
            this.showNotification('❌ Error deteniendo worker: ' + error.message, 'error');
        }
    }

    /**
     * Cancela trabajos pendientes
     */
    async cancelPendingJobs() {
        const confirmed = await this.showConfirmModal(
            'Cancelar Trabajos',
            '¿Estás seguro de cancelar todos los trabajos pendientes y en progreso?',
            'Sí, Cancelar Todos',
            'Cancelar',
            'warning'
        );
        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}queue-control.php?action=cancel_pending`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({})
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification(`✅ ${result.message}`, 'success');
                await this.loadQueueStatus(true);
            } else {
                this.showNotification('❌ Error: ' + (result.error || 'Error desconocido'), 'error');
            }
        } catch (error) {
            console.error('Error cancelando trabajos:', error);
            this.showNotification('❌ Error cancelando trabajos: ' + error.message, 'error');
        }
    }

    /**
     * Limpia la cola
     */
    async clearQueue() {
        const includeActive = await this.showConfirmModal(
            'Incluir Trabajos Activos',
            '¿Incluir trabajos activos?<br><small class="text-muted">Cancelar = solo trabajos finalizados</small>',
            'Sí, Incluir Activos',
            'Solo Finalizados',
            'info'
        );
        
        const confirmed = await this.showConfirmModal(
            'Limpiar Cola',
            `¿Estás seguro de limpiar la cola?<br><strong>${includeActive ? 'Se eliminarán TODOS los trabajos.' : 'Solo se eliminarán trabajos finalizados.'}</strong>`,
            'Sí, Limpiar',
            'Cancelar',
            includeActive ? 'danger' : 'warning'
        );
        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}queue-control.php?action=clear_queue`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    include_active: includeActive
                })
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification(`✅ ${result.message}`, 'success');
                await this.loadQueueStatus(true);
            } else {
                this.showNotification('❌ Error: ' + (result.error || 'Error desconocido'), 'error');
            }
        } catch (error) {
            console.error('Error limpiando cola:', error);
            this.showNotification('❌ Error limpiando cola: ' + error.message, 'error');
        }
    }

    /**
     * Cancela un trabajo específico
     */
    async cancelJob(queueId) {
        try {
            const response = await fetch(`${this.apiBaseUrl}queue-control.php?action=cancel_pending`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    study_ids: [queueId]
                })
            });

            // Verificar que la respuesta sea válida
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error HTTP:', response.status, errorText);
                let errorMsg = `Error HTTP ${response.status}`;
                try {
                    const errorJson = JSON.parse(errorText);
                    errorMsg = errorJson.error || errorMsg;
                } catch (e) {
                    errorMsg = errorText.substring(0, 200) || errorMsg;
                }
                throw new Error(errorMsg);
            }

            const text = await response.text();
            if (!text || text.trim() === '') {
                throw new Error('Respuesta vacía del servidor');
            }

            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                console.error('Error parseando JSON:', parseError, 'Texto recibido:', text);
                throw new Error('Respuesta inválida del servidor: ' + text.substring(0, 100));
            }

            if (result.success) {
                this.showNotification(`✅ ${result.message}`, 'success');
                await this.loadQueueStatus(true);
            } else {
                const errorMsg = result.error || 'Error desconocido';
                this.showNotification('❌ Error: ' + errorMsg, 'error');
                
                // Si el error menciona el script SQL, mostrar información adicional
                if (errorMsg.includes('add_cancelled_status_safe.sql')) {
                    console.error('⚠️ IMPORTANTE: Necesitas ejecutar el script SQL para agregar el estado "cancelled"');
                    console.error('Script: /var/www/tjsiddse/modules/cloud-storage/database/add_cancelled_status_safe.sql');
                }
            }
        } catch (error) {
            console.error('Error cancelando trabajo:', error);
            this.showNotification('❌ Error cancelando trabajo: ' + error.message, 'error');
        }
    }

    /**
     * Elimina un trabajo específico de la cola
     */
    async deleteJob(queueId) {
        try {
            const response = await fetch(`${this.apiBaseUrl}queue-control.php?action=delete_study`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    queue_id: queueId
                })
            });

            // Verificar que la respuesta sea válida
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error HTTP:', response.status, errorText);
                throw new Error(`Error HTTP ${response.status}: ${errorText.substring(0, 100)}`);
            }

            const text = await response.text();
            if (!text || text.trim() === '') {
                throw new Error('Respuesta vacía del servidor');
            }

            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                console.error('Error parseando JSON:', parseError, 'Texto recibido:', text);
                throw new Error('Respuesta inválida del servidor: ' + text.substring(0, 100));
            }

            if (result.success) {
                this.showNotification('✅ ' + result.message, 'success');
                await this.loadQueueStatus(true);
            } else {
                this.showNotification('❌ Error: ' + (result.error || 'Error desconocido'), 'error');
            }
        } catch (error) {
            console.error('Error eliminando trabajo:', error);
            this.showNotification('❌ Error eliminando trabajo: ' + error.message, 'error');
        }
    }

    /**
     * Actualiza estados R2 de estudios en la lista actual (sin recargar desde PACS)
     */
    async refreshStudiesR2Status() {
        // Solo actualizar si estamos en la pestaña de estudios y hay estudios cargados
        if (this.currentTab !== 'studies' || this.studies.length === 0) {
            return;
        }
        
        try {
            const studyIds = this.studies.map(s => s.orthanc_id || s.study_id).filter(id => id);
            if (studyIds.length === 0) return;
            
            await this.updateStudiesR2Status(studyIds);
        } catch (error) {
            console.error('Error refrescando estados R2:', error);
            // No mostrar error, solo log
        }
    }
    
    /**
     * Inicia el polling automático para actualizar cola y estudios R2
     */
    startPolling() {
        // Limpiar intervalo anterior si existe
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
        
        // Polling automático cada 5 segundos
        this.pollingInterval = setInterval(() => {
            if (this.isPolling) return; // Evitar múltiples llamadas simultáneas
            
            // Actualizar según la pestaña activa
            if (this.currentTab === 'queue' || this.currentTab === 'r2-studies') {
                this.pollingUpdate();
            } else if (this.currentTab === 'r2-audit') {
                this.loadR2Audit(true);
            } else if (this.currentTab === 'studies') {
                // Si estamos en estudios, actualizar estados R2 sin recargar desde PACS
                this.refreshStudiesR2Status();
            }
            this.loadR2Usage(true);
        }, this.pollingIntervalMs);
        
        console.log('🔄 Polling automático iniciado (cada', this.pollingIntervalMs / 1000, 'segundos)');
    }
    
    /**
     * Detiene el polling automático
     */
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
            console.log('⏸️ Polling automático detenido');
        }
    }
    
    /**
     * Actualización silenciosa durante polling (sin indicadores visuales)
     */
    async pollingUpdate() {
        if (this.isPolling) return;
        
        this.isPolling = true;
        
        try {
            // Actualizar cola si estamos en esa pestaña o si hay estudios pendientes
            if (this.currentTab === 'queue') {
                await this.loadQueueStatus(true); // true = actualización silenciosa
            }
            
            // Actualizar estudios R2 si estamos en esa pestaña
            if (this.currentTab === 'r2-studies') {
                await this.loadR2Studies(true); // true = actualización silenciosa
            }
            if (this.currentTab === 'r2-audit') {
                await this.loadR2Audit(true);
            }
            await this.loadR2Usage(true);
        } catch (error) {
            console.error('Error en polling:', error);
        } finally {
            this.isPolling = false;
        }
    }

    /**
     * Listeners para multiselección en pestaña "Estudios en R2" (delegación en la tabla).
     */
    setupR2StudiesBulkSelectionListeners() {
        const table = document.getElementById('r2StudiesTable');
        if (table && !table.dataset.r2BulkDelegationBound) {
            table.dataset.r2BulkDelegationBound = '1';
            table.addEventListener('change', (e) => {
                const t = e.target;
                if (!t || !t.classList || !t.classList.contains('r2-study-select-cb')) return;
                const id = t.getAttribute('data-orthanc-study-id');
                if (!id) return;
                if (t.checked) {
                    this.selectedR2Studies.add(id);
                    const tr = t.closest('tr.r2-study-data-row');
                    const idx = tr ? parseInt(tr.getAttribute('data-r2-row-index'), 10) : NaN;
                    if (!Number.isNaN(idx)) {
                        this.r2SelectionAnchorIndex = idx;
                    }
                } else {
                    this.selectedR2Studies.delete(id);
                }
                this.refreshR2StudySelectionUI();
                try {
                    this.saveToCache();
                } catch (e) {
                    /* noop */
                }
            });
            table.addEventListener('click', (e) => this.handleR2StudyTableClick(e));
        }
        const master = document.getElementById('selectAllR2StudiesCheckbox');
        if (master && !master.dataset.r2Bound) {
            master.dataset.r2Bound = '1';
            master.addEventListener('change', (e) => {
                this.toggleSelectAllR2Studies(!!e.target.checked);
            });
        }
    }

    setR2StudyCheckboxesDisabled(disabled) {
        const master = document.getElementById('selectAllR2StudiesCheckbox');
        if (master) master.disabled = disabled;
        document.querySelectorAll('#r2StudiesTableBody .r2-study-select-cb').forEach(cb => {
            cb.disabled = disabled;
        });
    }

    syncSelectAllR2Checkbox() {
        const master = document.getElementById('selectAllR2StudiesCheckbox');
        if (!master) return;
        const cbs = [...document.querySelectorAll('#r2StudiesTableBody .r2-study-select-cb')].filter(
            cb => !cb.disabled
        );
        if (cbs.length === 0) {
            master.checked = false;
            master.indeterminate = false;
            return;
        }
        const nSel = cbs.filter(cb => cb.checked).length;
        master.checked = nSel === cbs.length;
        master.indeterminate = nSel > 0 && nSel < cbs.length;
    }

    toggleSelectAllR2Studies(checked) {
        const cbs = document.querySelectorAll('#r2StudiesTableBody .r2-study-select-cb');
        cbs.forEach(cb => {
            if (cb.disabled) return;
            cb.checked = checked;
            const id = cb.getAttribute('data-orthanc-study-id');
            if (!id) return;
            if (checked) {
                this.selectedR2Studies.add(id);
            } else {
                this.selectedR2Studies.delete(id);
            }
        });
        this.r2SelectionAnchorIndex = null;
        this.refreshR2StudySelectionUI();
        try {
            this.saveToCache();
        } catch (e) {
            /* noop */
        }
    }

    /**
     * Sincroniza checkboxes con selectedR2Studies y actualiza resaltado / barra.
     */
    syncR2SelectionFromSet() {
        document.querySelectorAll('#r2StudiesTableBody .r2-study-select-cb').forEach(cb => {
            const id = cb.getAttribute('data-orthanc-study-id');
            if (id) {
                cb.checked = this.selectedR2Studies.has(id);
            }
        });
        this.refreshR2StudySelectionUI();
    }

    applyR2StudyRowHighlight() {
        document.querySelectorAll('#r2StudiesTable tbody tr.r2-study-data-row').forEach(tr => {
            const cb = tr.querySelector('.r2-study-select-cb');
            const id = cb && cb.getAttribute('data-orthanc-study-id');
            tr.classList.toggle('r2-row-selected', !!(id && this.selectedR2Studies.has(id)));
        });
    }

    refreshR2StudySelectionUI() {
        this.applyR2StudyRowHighlight();
        this.syncSelectAllR2Checkbox();
        this.updateR2BulkToolbar();
    }

    /**
     * Clic en fila: selección simple; Ctrl/Cmd: alternar; Shift: rango desde ancla.
     */
    handleR2StudyTableClick(e) {
        const tbody = document.getElementById('r2StudiesTableBody');
        if (!tbody || !tbody.contains(e.target)) return;

        const tr = e.target.closest('tr.r2-study-data-row');
        if (!tr) return;

        if (e.target.closest('button, a, .btn-group, .btn')) return;
        if (e.target.matches('input.r2-study-select-cb')) return;

        const cb = tr.querySelector('.r2-study-select-cb');
        if (!cb || cb.disabled) return;
        const id = cb.getAttribute('data-orthanc-study-id');
        if (!id) return;

        const idx = parseInt(tr.getAttribute('data-r2-row-index'), 10);
        if (Number.isNaN(idx)) return;

        const meta = e.ctrlKey || e.metaKey;
        const shift = e.shiftKey;

        if (shift) {
            let a;
            let b;
            if (this.r2SelectionAnchorIndex !== null && !Number.isNaN(this.r2SelectionAnchorIndex)) {
                a = Math.min(this.r2SelectionAnchorIndex, idx);
                b = Math.max(this.r2SelectionAnchorIndex, idx);
            } else {
                a = b = idx;
            }
            this.selectedR2Studies.clear();
            document.querySelectorAll('#r2StudiesTable tbody tr.r2-study-data-row').forEach(row => {
                const i = parseInt(row.getAttribute('data-r2-row-index'), 10);
                if (Number.isNaN(i) || i < a || i > b) return;
                const c = row.querySelector('.r2-study-select-cb');
                const oid = c && c.getAttribute('data-orthanc-study-id');
                if (oid) {
                    this.selectedR2Studies.add(oid);
                }
            });
        } else if (meta) {
            if (this.selectedR2Studies.has(id)) {
                this.selectedR2Studies.delete(id);
            } else {
                this.selectedR2Studies.add(id);
            }
            this.r2SelectionAnchorIndex = idx;
        } else {
            this.selectedR2Studies.clear();
            this.selectedR2Studies.add(id);
            this.r2SelectionAnchorIndex = idx;
        }

        this.syncR2SelectionFromSet();
        try {
            this.saveToCache();
        } catch (err) {
            /* noop */
        }
        e.preventDefault();
    }

    getR2SelectionLockCounts() {
        let unlockedCount = 0;
        let lockedCount = 0;
        const tbody = document.getElementById('r2StudiesTableBody');
        if (!tbody) return { unlockedCount: 0, lockedCount: 0 };
        tbody.querySelectorAll('.r2-study-select-cb').forEach(cb => {
            const id = cb.getAttribute('data-orthanc-study-id');
            if (!id || !this.selectedR2Studies.has(id)) return;
            if (cb.getAttribute('data-locked') === '1') {
                lockedCount++;
            } else {
                unlockedCount++;
            }
        });
        return { unlockedCount, lockedCount };
    }

    updateR2BulkToolbar() {
        const n = this.selectedR2Studies.size;
        const countEl = document.getElementById('r2StudiesSelectedCount');
        if (countEl) {
            countEl.textContent = n === 1 ? '1 seleccionado' : `${n} seleccionados`;
        }

        const busy =
            this.isBulkOperatingR2 || this.isDeletingR2Study || this.isTogglingLockR2Study;
        const { unlockedCount } = this.getR2SelectionLockCounts();

        const lockBtn = document.getElementById('r2BulkLockBtn');
        const unlockBtn = document.getElementById('r2BulkUnlockBtn');
        const delBtn = document.getElementById('r2BulkDeleteBtn');

        const canBulk = n > 0 && !busy;
        if (lockBtn) lockBtn.disabled = !canBulk;
        if (unlockBtn) unlockBtn.disabled = !canBulk;
        if (delBtn) delBtn.disabled = !canBulk || unlockedCount === 0;
    }
    
    /**
     * Carga estudios en R2
     */
    async loadR2Studies(silent = false) {
        const tbody = document.getElementById('r2StudiesTableBody');
        if (!tbody) return;
        
        // Indicador visual de actualización (solo si no es silencioso)
        const refreshBtn = document.getElementById('refreshR2Btn');
        if (!silent && refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Actualizando...';
        }
        
        try {
            const response = await fetch(`${this.apiBaseUrl}r2-studies.php`);
            const data = await response.json();
            
            if (data.success) {
                const studies = data.data || [];
                const visibleIds = new Set(
                    studies.map(s => s.orthanc_study_id).filter(Boolean)
                );
                const prevR2SelCount = this.selectedR2Studies.size;
                this.selectedR2Studies = new Set(
                    [...this.selectedR2Studies].filter(id => visibleIds.has(id))
                );
                if (prevR2SelCount !== this.selectedR2Studies.size) {
                    try {
                        this.saveToCache();
                    } catch (e) {
                        /* noop */
                    }
                }
                
                // Actualizar contador
                document.getElementById('r2Estudios').textContent = data.stats?.total || 0;
                
                if (studies.length === 0) {
                    this.r2SelectionAnchorIndex = null;
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                No hay estudios en R2 aún
                            </td>
                        </tr>
                    `;
                    this.refreshR2StudySelectionUI();
                } else {
                    this.r2SelectionAnchorIndex = null;
                    tbody.innerHTML = studies.map((study, rowIndex) => {
                        const syncRaw = (study.r2_sync_status || 'idle').toString();
                        const syncLabelMap = {
                            idle: 'Al día',
                            pending: 'Sync pendiente',
                            syncing: 'Actualizando…'
                        };
                        const syncColorMap = {
                            idle: 'success',
                            pending: 'warning',
                            syncing: 'primary'
                        };
                        const syncLabel = syncLabelMap[syncRaw] || syncRaw;
                        const syncColor = syncColorMap[syncRaw] || 'secondary';

                        const isDeletingThisStudy =
                            this.isDeletingR2Study &&
                            this.currentDeletingR2StudyId &&
                            this.currentDeletingR2StudyId === study.orthanc_study_id;
                        const isDeleteLocked =
                            this.isDeletingR2Study &&
                            (!this.currentDeletingR2StudyId || !isDeletingThisStudy);
                        const isStudyLocked = study.is_locked === true ||
                            study.is_locked === 1 ||
                            study.is_locked === '1';
                        const deleteTitle = isStudyLocked
                            ? 'Estudio bloqueado (no se puede eliminar)'
                            : (isDeletingThisStudy
                                ? 'Eliminando estudio en R2...'
                                : (isDeleteLocked
                                    ? (this.isBulkOperatingR2
                                        ? 'Espera: eliminación masiva en curso'
                                        : 'Espera: hay una eliminación en curso')
                                    : 'Eliminar estudio desde R2'));
                        const deleteIcon = isDeletingThisStudy
                            ? '<i class="fas fa-spinner fa-spin"></i>'
                            : '<i class="fas fa-trash"></i>';

                        const lockAction = isStudyLocked ? 'unlock' : 'lock';
                        // El icono refleja estado: desbloqueado => candado abierto; bloqueado => candado cerrado.
                        const lockIcon = isStudyLocked
                            ? '<i class="fas fa-lock"></i>'
                            : '<i class="fas fa-unlock-alt"></i>';
                        const lockTitle = isStudyLocked
                            ? 'Desbloquear estudio para permitir eliminar/reciclar'
                            : 'Bloquear estudio para que no se elimine/recicle';
                        const isLockingThis = this.isTogglingLockR2Study && this.currentLockR2StudyId === study.orthanc_study_id;
                        const isLockingOther = this.isTogglingLockR2Study && !isLockingThis;
                        const lockButtonDisabled = isLockingOther || isDeletingThisStudy;
                        const lockBtnIcon = isLockingThis ? '<i class="fas fa-spinner fa-spin"></i>' : lockIcon;
                        const lockBtnTitle = isLockingThis ? 'Aplicando bloqueo...' : lockTitle;

                        const r2SelDisabled = this.isBulkOperatingR2 ? 'disabled' : '';
                        const r2Checked = this.selectedR2Studies.has(study.orthanc_study_id)
                            ? 'checked'
                            : '';
                        const oid = String(study.orthanc_study_id || '').replace(/"/g, '&quot;');

                        return `
                            <tr class="r2-study-data-row" data-r2-row-index="${rowIndex}">
                                <td class="text-center align-middle">
                                    <input type="checkbox" class="form-check-input r2-study-select-cb"
                                        data-orthanc-study-id="${oid}"
                                        data-locked="${isStudyLocked ? '1' : '0'}"
                                        ${r2SelDisabled}
                                        ${r2Checked}
                                        aria-label="Seleccionar estudio en R2">
                                </td>
                                <td><code>${(study.orthanc_study_id || '').substring(0, 20)}...</code></td>
                                <td><code>${(study.study_instance_uid || '').substring(0, 30)}...</code></td>
                                <td>${study.total_instances || 0}</td>
                                <td>${study.size_mb || 0} MB</td>
                                <td>${study.uploaded_at || 'N/A'}</td>
                                <td>
                                    <span class="badge bg-${syncColor}" title="Estado respecto a nuevas instancias en Orthanc">${syncLabel}</span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-outline-primary view-manifest-btn"
                                                data-study-id="${study.orthanc_study_id}">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-secondary toggle-lock-r2-study-btn"
                                                data-orthanc-study-id="${study.orthanc_study_id}"
                                                data-lock-action="${lockAction}"
                                                title="${lockBtnTitle}"
                                                ${lockButtonDisabled ? 'disabled' : ''}>
                                            ${lockBtnIcon}
                                        </button>
                                        <button class="btn btn-outline-danger delete-r2-study-btn"
                                                data-orthanc-study-id="${study.orthanc_study_id}"
                                                title="${deleteTitle}"
                                                ${isStudyLocked || this.isDeletingR2Study ? 'disabled' : ''}>
                                            ${deleteIcon}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `;
                    }).join('');
                    
                    // Re-conectar event listeners para botones de manifest
                    this.setupManifestButtons();
                    this.setupR2DeleteButtons();
                    this.setupR2LockButtons();
                    this.refreshR2StudySelectionUI();
                }
            }
        } catch (error) {
            console.error('Error cargando estudios R2:', error);
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4 text-danger">
                        Error: ${error.message}
                    </td>
                </tr>
            `;
        } finally {
            // Restaurar botón de actualizar
            if (!silent && refreshBtn) {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Actualizar';
            }
        }
    }

    /**
     * Carga auditoría de consistencia R2 vs BD.
     */
    async loadR2Audit(silent = false) {
        const orphansBody = document.getElementById('r2OrphansTableBody');
        const missingBody = document.getElementById('r2MissingTableBody');
        if (!orphansBody || !missingBody) return;

        const refreshBtn = document.getElementById('refreshR2AuditBtn');
        if (!silent && refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Escaneando...';
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}r2-audit.php`);
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Error cargando auditoría R2');
            }

            const payload = data.data || {};
            const orphans = payload.orphans || [];
            const missing = payload.missing_in_r2 || [];

            const totalDirsEl = document.getElementById('r2AuditTotalDirs');
            const orphansCountEl = document.getElementById('r2AuditOrphansCount');
            const missingCountEl = document.getElementById('r2AuditMissingCount');
            if (totalDirsEl) totalDirsEl.textContent = payload.uids_in_r2_count ?? 0;
            if (orphansCountEl) orphansCountEl.textContent = payload.orphans_count ?? 0;
            if (missingCountEl) missingCountEl.textContent = payload.missing_in_r2_count ?? 0;

            if (orphans.length === 0) {
                orphansBody.innerHTML = '<tr><td colspan="2" class="text-center py-3 text-muted">No hay huérfanos en R2</td></tr>';
            } else {
                orphansBody.innerHTML = orphans.map(uid => {
                    const isPurgingThis = this.isPurgingOrphan && this.currentPurgingOrphanUid === uid;
                    const icon = isPurgingThis ? '<i class="fas fa-spinner fa-spin"></i>' : '<i class="fas fa-trash"></i>';
                    return `
                        <tr>
                            <td><code>${uid}</code></td>
                            <td>
                                <button class="btn btn-sm btn-outline-danger purge-orphan-btn"
                                        data-study-uid="${uid}"
                                        ${this.isPurgingOrphan ? 'disabled' : ''}
                                        title="${isPurgingThis ? 'Eliminando huérfano...' : 'Purgar huérfano en R2'}">
                                    ${icon}
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('');
            }

            if (missing.length === 0) {
                missingBody.innerHTML = '<tr><td class="text-center py-3 text-muted">No hay faltantes en R2</td></tr>';
            } else {
                missingBody.innerHTML = missing.map(uid => `<tr><td><code>${uid}</code></td></tr>`).join('');
            }

            this.setupOrphanPurgeButtons();
        } catch (error) {
            console.error('Error loadR2Audit:', error);
            if (!silent) {
                this.showNotification(`❌ Error en auditoría R2: ${error.message}`, 'error');
            }
        } finally {
            if (!silent && refreshBtn) {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Escanear';
            }
        }
    }

    setupOrphanPurgeButtons() {
        const buttons = document.querySelectorAll('.purge-orphan-btn');
        buttons.forEach(btn => {
            btn.addEventListener('click', async () => {
                const uid = btn.getAttribute('data-study-uid');
                if (!uid) return;
                if (this.isPurgingOrphan) {
                    this.showNotification('⏳ Ya hay un purgado de huérfano en curso. Espera.', 'info');
                    return;
                }

                const confirmed = await this.showConfirmModal(
                    'Purgar Huérfano de R2',
                    `Se eliminará recursivamente el prefijo del UID:<br><code>${uid}</code><br><br>¿Continuar?`,
                    'Sí, Purgar',
                    'No',
                    'danger'
                );
                if (!confirmed) return;

                this.isPurgingOrphan = true;
                this.currentPurgingOrphanUid = uid;
                try {
                    await this.purgeOrphan(uid);
                } finally {
                    this.isPurgingOrphan = false;
                    this.currentPurgingOrphanUid = null;
                }
            });
        });
    }

    async purgeOrphan(studyUid) {
        try {
            this.showNotification('⏳ Purga de huérfano en progreso...', 'info');
            const response = await fetch(`${this.apiBaseUrl}r2-audit-control.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'purge_orphan',
                    study_instance_uid: studyUid
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'No se pudo purgar huérfano');
            }

            this.showNotification(`✅ ${data.message}`, 'success');
            await Promise.all([
                this.loadR2Audit(true),
                this.loadR2Studies(true),
                this.loadR2Usage(true),
                this.loadQueueStatus(true),
                this.forceRefreshStudiesR2Status()
            ]);
        } catch (error) {
            console.error('Error purgeOrphan:', error);
            this.showNotification(`❌ Error purgando huérfano: ${error.message}`, 'error');
        }
    }
    
    /**
     * Configura los botones de ver manifest en la tabla de estudios R2
     */
    setupManifestButtons() {
        const manifestButtons = document.querySelectorAll('.view-manifest-btn');
        manifestButtons.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const studyId = e.target.closest('.view-manifest-btn').getAttribute('data-study-id');
                if (studyId) {
                    await this.viewManifest(studyId);
                }
            });
        });
    }

    /**
     * Durante eliminación masiva: spinner en la fila actual y resto deshabilitado.
     * @param {string|null} activeOrthancId - estudio en proceso, o null entre ítems
     */
    updateR2DeleteButtonsBulkProgress(activeOrthancId) {
        const tbody = document.getElementById('r2StudiesTableBody');
        if (!tbody) return;
        if (!this.isBulkOperatingR2 || !this.isDeletingR2Study) return;

        tbody.querySelectorAll('.delete-r2-study-btn').forEach(btn => {
            const sid = btn.getAttribute('data-orthanc-study-id');
            if (!sid) return;
            btn.disabled = true;
            if (activeOrthancId && sid === activeOrthancId) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                btn.title = 'Eliminando estudio en R2...';
            } else {
                btn.innerHTML = '<i class="fas fa-trash"></i>';
                btn.title = 'Espera: eliminación masiva en curso';
            }
        });
    }

    /**
     * Configura los botones de eliminación en la tabla de estudios R2
     */
    setupR2DeleteButtons() {
        const deleteButtons = document.querySelectorAll('.delete-r2-study-btn');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const orthancStudyId = btn.getAttribute('data-orthanc-study-id');
                if (!orthancStudyId) return;

                // Evitar que el usuario inicie otro purge mientras corre uno anterior
                if (this.isDeletingR2Study) return;
                if (this.isBulkOperatingR2) {
                    this.showNotification('⏳ Operación masiva en curso en esta pestaña. Espera.', 'info');
                    return;
                }

                const confirmed = await this.showConfirmModal(
                    'Eliminar Estudio de R2',
                    'Se eliminará TODO lo que esté bajo el UID de este estudio en R2. ¿Continuar?',
                    'Sí, Eliminar',
                    'No',
                    'danger'
                );

                if (confirmed) {
                    this.isDeletingR2Study = true;
                    this.currentDeletingR2StudyId = orthancStudyId;
                    try {
                        // Deshabilitar todos los botones y marcar visualmente el estudio en curso
                        deleteButtons.forEach(b => {
                            b.disabled = true;
                            const btnStudyId = b.getAttribute('data-orthanc-study-id');
                            if (btnStudyId === orthancStudyId) {
                                b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                                b.title = 'Eliminando estudio en R2...';
                            } else {
                                b.title = 'Espera: hay una eliminación en curso';
                            }
                        });
                        await this.deleteR2Study(orthancStudyId);
                    } finally {
                        this.isDeletingR2Study = false;
                        this.currentDeletingR2StudyId = null;
                        deleteButtons.forEach(b => b.disabled = false);
                    }
                }
            });

            // Avisar al usuario si intenta eliminar otro estudio mientras hay uno en curso
            btn.addEventListener('mousedown', (e) => {
                if (!this.isDeletingR2Study) return;
                if (this.isBulkOperatingR2) return;
                const btnStudyId = btn.getAttribute('data-orthanc-study-id');
                if (btnStudyId !== this.currentDeletingR2StudyId) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.showNotification('⏳ Ya hay un estudio eliminándose de R2. Espera a que finalice.', 'info');
                }
            });
        });
    }

    /**
     * Configura botones de lock/unlock en la tabla de estudios R2
     */
    setupR2LockButtons() {
        const lockButtons = document.querySelectorAll('.toggle-lock-r2-study-btn');
        lockButtons.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();

                const orthancStudyId = btn.getAttribute('data-orthanc-study-id');
                const lockAction = btn.getAttribute('data-lock-action');
                if (!orthancStudyId || !lockAction) return;

                // Evitar colisión con purge en curso
                if (this.isDeletingR2Study) {
                    this.showNotification('⏳ Espera: hay una eliminación en curso', 'info');
                    return;
                }

                if (this.isBulkOperatingR2) {
                    this.showNotification('⏳ Operación masiva en curso en esta pestaña. Espera.', 'info');
                    return;
                }

                if (this.isTogglingLockR2Study) return;

                const isLock = lockAction === 'lock';
                const confirmed = await this.showConfirmModal(
                    isLock ? 'Bloquear Estudio en R2' : 'Desbloquear Estudio en R2',
                    isLock
                        ? 'Este estudio quedará protegido: no se podrá eliminar ni reciclar automáticamente. ¿Continuar?'
                        : 'Se quitará el bloqueo del estudio. ¿Continuar?',
                    isLock ? 'Sí, Bloquear' : 'Sí, Desbloquear',
                    'No',
                    isLock ? 'warning' : 'secondary'
                );
                if (!confirmed) return;

                this.isTogglingLockR2Study = true;
                this.currentLockR2StudyId = orthancStudyId;
                try {
                    await this.toggleLockR2Study(orthancStudyId, lockAction);
                } finally {
                    this.isTogglingLockR2Study = false;
                    this.currentLockR2StudyId = null;
                    // Recargar para refrescar botones/spinners
                    await this.loadR2Studies(true);
                }
            });
        });
    }

    /**
     * POST a r2-studies-control.php (delete | lock | unlock).
     */
    async r2StudiesControlApi(action, orthancStudyId, extra = {}) {
        const body = { action, orthanc_study_id: orthancStudyId, ...extra };
        const response = await fetch(`${this.apiBaseUrl}r2-studies-control.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });
        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            data = { success: false, error: 'Respuesta no JSON' };
        }
        return { response, data, ok: response.ok && !!data.success };
    }

    /**
     * Lógica lock/unlock vía API
     */
    async toggleLockR2Study(orthancStudyId, lockAction, options = {}) {
        const { silentNotify = false } = options;
        try {
            if (!silentNotify) {
                await this.showNotification(
                    lockAction === 'lock' ? '⏳ Bloqueando estudio...' : '⏳ Desbloqueando estudio...',
                    'info'
                );
            }

            const extra = lockAction === 'lock' ? { lock_reason: '' } : {};
            const { ok, data } = await this.r2StudiesControlApi(lockAction, orthancStudyId, extra);
            if (!ok) {
                throw new Error(data.error || data.message || 'Error desconocido al cambiar lock');
            }

            if (!silentNotify) {
                this.showNotification(`✅ ${data.message}`, 'success');
            }
            return { ok: true, data };
        } catch (error) {
            console.error('Error toggleLockR2Study:', error);
            if (!silentNotify) {
                this.showNotification(`❌ Error al cambiar lock: ${error.message}`, 'error');
            }
            throw error;
        }
    }

    /**
     * Elimina un estudio desde R2 (rclone purge sobre prefijo)
     * @param {string} orthancStudyId
     * @param {{ skipRefresh?: boolean, silentNotify?: boolean }} options
     */
    async deleteR2Study(orthancStudyId, options = {}) {
        const { skipRefresh = false, silentNotify = false } = options;
        try {
            if (!silentNotify) {
                this.showNotification(
                    '⏳ Eliminando estudio en R2... Esto puede tardar unos segundos.',
                    'info'
                );
            }

            const { ok, data } = await this.r2StudiesControlApi('delete', orthancStudyId);
            if (!ok) {
                throw new Error(data.error || data.message || 'Error desconocido eliminando estudio');
            }

            if (!silentNotify) {
                this.showNotification(`✅ ${data.message}`, 'success');
            }
            if (!skipRefresh) {
                await Promise.all([
                    this.loadR2Studies(true),
                    this.loadQueueStatus(true),
                    this.loadR2Usage(true),
                    this.forceRefreshStudiesR2Status()
                ]);
            }
            return { ok: true, data };
        } catch (error) {
            console.error('Error eliminando estudio R2:', error);
            if (!silentNotify) {
                this.showNotification(`❌ Error al eliminar: ${error.message}`, 'error');
            }
            return { ok: false, error: error.message };
        }
    }

    async bulkLockR2Studies() {
        if (this.isBulkOperatingR2 || this.isDeletingR2Study || this.isTogglingLockR2Study) return;
        const ids = Array.from(this.selectedR2Studies);
        if (ids.length === 0) return;

        const confirmed = await this.showConfirmModal(
            'Bloquear estudios en R2',
            `Se bloquearán <strong>${ids.length}</strong> estudio(s) seleccionado(s). Quedarán protegidos frente a eliminación y reciclado automático. ¿Continuar?`,
            'Sí, Bloquear',
            'No',
            'warning'
        );
        if (!confirmed) return;

        this.isBulkOperatingR2 = true;
        this.setR2StudyCheckboxesDisabled(true);
        this.updateR2BulkToolbar();

        let okCount = 0;
        const failures = [];
        try {
            for (let i = 0; i < ids.length; i++) {
                const id = ids[i];
                this.showNotification(`⏳ Bloqueando ${i + 1}/${ids.length}...`, 'info');
                const { ok, data } = await this.r2StudiesControlApi('lock', id, { lock_reason: '' });
                if (ok) okCount++;
                else failures.push({ id, err: data.error || data.message || 'Error' });
            }
            if (failures.length === 0) {
                this.showNotification(`✅ ${okCount} estudio(s) bloqueado(s).`, 'success');
            } else {
                this.showNotification(
                    `⚠️ Bloqueo: ${okCount} OK, ${failures.length} error(es). Revisa la consola.`,
                    'warning'
                );
                console.warn('[R2 bulk lock] Fallos:', failures);
            }
        } finally {
            this.isBulkOperatingR2 = false;
            this.setR2StudyCheckboxesDisabled(false);
            await Promise.all([this.loadR2Studies(true), this.loadR2Usage(true)]);
            this.refreshR2StudySelectionUI();
        }
    }

    async bulkUnlockR2Studies() {
        if (this.isBulkOperatingR2 || this.isDeletingR2Study || this.isTogglingLockR2Study) return;
        const ids = Array.from(this.selectedR2Studies);
        if (ids.length === 0) return;

        const confirmed = await this.showConfirmModal(
            'Desbloquear estudios en R2',
            `Se desbloquearán <strong>${ids.length}</strong> estudio(s) seleccionado(s). ¿Continuar?`,
            'Sí, Desbloquear',
            'No',
            'secondary'
        );
        if (!confirmed) return;

        this.isBulkOperatingR2 = true;
        this.setR2StudyCheckboxesDisabled(true);
        this.updateR2BulkToolbar();

        let okCount = 0;
        const failures = [];
        try {
            for (let i = 0; i < ids.length; i++) {
                const id = ids[i];
                this.showNotification(`⏳ Desbloqueando ${i + 1}/${ids.length}...`, 'info');
                const { ok, data } = await this.r2StudiesControlApi('unlock', id);
                if (ok) okCount++;
                else failures.push({ id, err: data.error || data.message || 'Error' });
            }
            if (failures.length === 0) {
                this.showNotification(`✅ ${okCount} estudio(s) desbloqueado(s).`, 'success');
            } else {
                this.showNotification(
                    `⚠️ Desbloqueo: ${okCount} OK, ${failures.length} error(es). Revisa la consola.`,
                    'warning'
                );
                console.warn('[R2 bulk unlock] Fallos:', failures);
            }
        } finally {
            this.isBulkOperatingR2 = false;
            this.setR2StudyCheckboxesDisabled(false);
            await Promise.all([this.loadR2Studies(true), this.loadR2Usage(true)]);
            this.refreshR2StudySelectionUI();
        }
    }

    async bulkDeleteR2Studies() {
        if (this.isBulkOperatingR2 || this.isDeletingR2Study) return;
        const ids = Array.from(this.selectedR2Studies);
        const tbody = document.getElementById('r2StudiesTableBody');
        const toDelete = ids.filter(id => {
            const cb = [...(tbody?.querySelectorAll('.r2-study-select-cb') || [])].find(
                c => c.getAttribute('data-orthanc-study-id') === id
            );
            return cb && cb.getAttribute('data-locked') !== '1';
        });
        const skippedLocked = ids.length - toDelete.length;

        if (toDelete.length === 0) {
            this.showNotification(
                'Ningún estudio seleccionado puede eliminarse (todos están bloqueados o no están en la lista).',
                'warning'
            );
            return;
        }

        let msg = `Se eliminarán <strong>${toDelete.length}</strong> estudio(s) de R2 (prefijo por StudyInstanceUID). Esta acción no se puede deshacer.`;
        if (skippedLocked > 0) {
            msg += `<br><span class="text-muted">${skippedLocked} omitido(s) por estar bloqueados.</span>`;
        }
        msg += '<br><br>¿Continuar?';

        const confirmed = await this.showConfirmModal(
            'Eliminar estudios de R2',
            msg,
            'Sí, eliminar',
            'No',
            'danger'
        );
        if (!confirmed) return;

        const delBtn = document.getElementById('r2BulkDeleteBtn');
        const savedBulkDeleteHtml = delBtn ? delBtn.innerHTML : '';

        this.isBulkOperatingR2 = true;
        this.setR2StudyCheckboxesDisabled(true);
        this.updateR2BulkToolbar();

        const setBulkDeleteProgress = (current, total) => {
            if (!delBtn) return;
            delBtn.disabled = true;
            delBtn.innerHTML = `<i class="fas fa-spinner fa-spin me-1"></i>Eliminando ${current}/${total}...`;
        };
        if (delBtn) {
            delBtn.disabled = true;
            delBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Eliminando…';
        }

        this.isDeletingR2Study = true;
        this.currentDeletingR2StudyId = null;
        this.updateR2DeleteButtonsBulkProgress(null);

        let okCount = 0;
        const failures = [];
        try {
            for (let i = 0; i < toDelete.length; i++) {
                const id = toDelete[i];
                setBulkDeleteProgress(i + 1, toDelete.length);
                this.currentDeletingR2StudyId = id;
                this.updateR2DeleteButtonsBulkProgress(id);
                this.showNotification(`⏳ Eliminando ${i + 1}/${toDelete.length} de R2...`, 'info');
                const r = await this.deleteR2Study(id, { skipRefresh: true, silentNotify: true });
                if (r.ok) okCount++;
                else failures.push({ id, err: r.error || 'Error' });
                this.currentDeletingR2StudyId = null;
                this.updateR2DeleteButtonsBulkProgress(null);
            }

            if (failures.length === 0) {
                this.showNotification(`✅ ${okCount} estudio(s) eliminado(s) de R2.`, 'success');
            } else {
                this.showNotification(
                    `⚠️ Eliminación: ${okCount} OK, ${failures.length} error(es). Revisa la consola.`,
                    'warning'
                );
                console.warn('[R2 bulk delete] Fallos:', failures);
            }

            this.selectedR2Studies.clear();
            const master = document.getElementById('selectAllR2StudiesCheckbox');
            if (master) master.checked = false;

            this.isDeletingR2Study = false;
            this.currentDeletingR2StudyId = null;

            await Promise.all([
                this.loadR2Studies(true),
                this.loadQueueStatus(true),
                this.loadR2Usage(true),
                this.forceRefreshStudiesR2Status()
            ]);
        } finally {
            this.isDeletingR2Study = false;
            this.currentDeletingR2StudyId = null;
            this.isBulkOperatingR2 = false;
            this.setR2StudyCheckboxesDisabled(false);
            if (delBtn) {
                delBtn.innerHTML =
                    savedBulkDeleteHtml ||
                    '<i class="fas fa-trash me-1"></i>Eliminar de R2';
            }
            this.refreshR2StudySelectionUI();
            try {
                this.saveToCache();
            } catch (e) {
                /* noop */
            }
        }
    }

    /**
     * Refresca r2_status de los estudios en memoria (pestaña "Estudios PACS"),
     * sin depender de currentTab.
     */
    async forceRefreshStudiesR2Status() {
        try {
            if (!this.studies || this.studies.length === 0) return;
            const studyIds = this.studies
                .map(s => s.orthanc_id || s.study_id)
                .filter(id => id);
            if (studyIds.length === 0) return;
            await this.updateStudiesR2Status(studyIds);
        } catch (e) {
            console.error('Error force refrescando estados R2:', e);
        }
    }
    
    /**
     * Muestra el manifest de un estudio
     */
    async viewManifest(orthancStudyId) {
        try {
            this.showNotification('Cargando manifest...', 'info');
            
            const url = `${this.apiBaseUrl}manifest.php?id=${encodeURIComponent(orthancStudyId)}`;
            console.log('📥 Cargando manifest desde:', url);
            
            const response = await fetch(url);
            
            // Intentar parsear JSON incluso si hay error HTTP
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                const text = await response.text();
                throw new Error(`Error parseando respuesta: ${text.substring(0, 200)}`);
            }
            
            if (!response.ok) {
                throw new Error(data.error || `Error HTTP ${response.status}: ${data.message || 'Error desconocido'}`);
            }
            
            // Mostrar manifest en un modal
            this.showManifestModal(data, orthancStudyId);
            this.showNotification('Manifest cargado correctamente', 'success');
            
        } catch (error) {
            console.error('Error cargando manifest:', error);
            this.showNotification(`Error cargando manifest: ${error.message}`, 'error');
        }
    }
    
    /**
     * Muestra el manifest en un modal de Bootstrap
     */
    showManifestModal(manifest, studyId) {
        // Crear o reutilizar modal
        let modal = document.getElementById('manifestModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'manifestModal';
            modal.className = 'modal fade';
            modal.setAttribute('tabindex', '-1');
            modal.innerHTML = `
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-file-code me-2"></i>Manifest JSON
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <small class="text-muted">Study ID: <code id="manifestStudyId"></code></small>
                            </div>
                            <div class="mb-3">
                                <button class="btn btn-sm btn-outline-secondary" id="copyManifestBtn">
                                    <i class="fas fa-copy me-1"></i>Copiar JSON
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" id="downloadManifestBtn">
                                    <i class="fas fa-download me-1"></i>Descargar JSON
                                </button>
                            </div>
                            <pre id="manifestContent" class="bg-light p-3 rounded" style="max-height: 600px; overflow-y: auto; font-size: 12px;"></pre>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Configurar botón de copiar
            const copyBtn = document.getElementById('copyManifestBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', () => {
                    const content = document.getElementById('manifestContent').textContent;
                    navigator.clipboard.writeText(content).then(() => {
                        this.showNotification('Manifest copiado al portapapeles', 'success');
                    }).catch(err => {
                        console.error('Error copiando:', err);
                        this.showNotification('Error al copiar', 'error');
                    });
                });
            }
            
            // Configurar botón de descargar
            const downloadBtn = document.getElementById('downloadManifestBtn');
            if (downloadBtn) {
                downloadBtn.addEventListener('click', () => {
                    const content = document.getElementById('manifestContent').textContent;
                    const blob = new Blob([content], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `manifest-${studyId.substring(0, 20)}.json`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    this.showNotification('Manifest descargado', 'success');
                });
            }
        }
        
        // Actualizar contenido del modal
        document.getElementById('manifestStudyId').textContent = studyId.substring(0, 30) + '...';
        document.getElementById('manifestContent').textContent = JSON.stringify(manifest, null, 2);
        
        // Mostrar modal
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }

    /**
     * Carga configuración
     */
    async loadConfig() {
        try {
            const response = await fetch(`${this.apiBaseUrl}config.php`);
            const data = await response.json();
            
            if (data.success && data.data) {
                const config = data.data;
                document.getElementById('r2Enabled').value = config.r2_enabled ? 'true' : 'false';
                document.getElementById('r2AccountId').value = config.r2_account_id || '';
                
                // Access Key y Secret Key: mostrar asteriscos si hay valor guardado
                // Esto indica que hay un valor guardado sin mostrarlo
                const accessKeyInput = document.getElementById('r2AccessKey');
                if (accessKeyInput) {
                    // Si hay un valor guardado (detectado por el valor truncado de la API)
                    if (config.r2_access_key && config.r2_access_key.includes('...')) {
                        // Mostrar asteriscos para indicar que hay un valor guardado
                        // Usar un valor fijo de asteriscos que no se guardará
                        accessKeyInput.value = '••••••••••••••••••••••••••••••••';
                        accessKeyInput.setAttribute('data-has-value', 'true');
                        // Limpiar el atributo cuando el usuario empiece a escribir
                        accessKeyInput.addEventListener('input', function() {
                            if (this.value !== '••••••••••••••••••••••••••••••••') {
                                this.removeAttribute('data-has-value');
                            }
                        });
                    } else {
                        accessKeyInput.value = '';
                        accessKeyInput.removeAttribute('data-has-value');
                    }
                }
                
                // Secret Key: siempre mostrar asteriscos si hay valor guardado
                const secretKeyInput = document.getElementById('r2SecretKey');
                if (secretKeyInput) {
                    // Si hay un secret_key guardado (siempre se muestra como ***HIDDEN*** en la API)
                    if (config.r2_secret_key === '***HIDDEN***' || config.r2_secret_key) {
                        secretKeyInput.value = '••••••••••••••••••••••••••••••••••••••••••••••••••••';
                        secretKeyInput.setAttribute('data-has-value', 'true');
                        // Limpiar el atributo cuando el usuario empiece a escribir
                        secretKeyInput.addEventListener('input', function() {
                            const asterisksValue = '••••••••••••••••••••••••••••••••••••••••••••••••••••';
                            if (this.value !== asterisksValue) {
                                this.removeAttribute('data-has-value');
                            }
                        });
                    } else {
                        secretKeyInput.value = '';
                        secretKeyInput.removeAttribute('data-has-value');
                    }
                }
                document.getElementById('r2BucketName').value = config.r2_bucket_name || '';
                document.getElementById('r2CustomDomain').value = config.r2_custom_domain || '';
                const pubBase = document.getElementById('r2PublicPortalBaseUrl');
                if (pubBase) pubBase.value = config.r2_public_portal_base_url || '';
                document.getElementById('r2StoragePrefix').value = config.r2_storage_prefix || 'studies/';
                document.getElementById('r2UploadConcurrency').value = config.r2_upload_concurrency || 2;
                document.getElementById('r2PresignedTtl').value = config.r2_presigned_ttl || 600;
                
                // Método de upload
                const uploadMethodSelect = document.getElementById('r2UploadMethod');
                if (uploadMethodSelect) {
                    this.currentUploadMethod = config.r2_upload_method || 'instance';
                    uploadMethodSelect.value = this.currentUploadMethod;
                }
                
                // Carpeta temp para ZIPs
                const zipTempDirInput = document.getElementById('r2ZipTempDir');
                if (zipTempDirInput) {
                    zipTempDirInput.value = config.r2_zip_temp_dir || '';
                }

                // Token Cloudflare API (lectura uso R2)
                const cfApiTokenInput = document.getElementById('r2CfApiTokenRead');
                if (cfApiTokenInput) {
                    if (config.r2_cf_api_token_read === '***CONFIGURADO***' || !!config.r2_cf_api_token_read) {
                        cfApiTokenInput.value = '••••••••••••••••••••••••••••••••••';
                        cfApiTokenInput.setAttribute('data-has-value', 'true');
                        cfApiTokenInput.addEventListener('input', function() {
                            const masked = '••••••••••••••••••••••••••••••••••';
                            if (this.value !== masked) {
                                this.removeAttribute('data-has-value');
                            }
                        });
                    } else {
                        cfApiTokenInput.value = '';
                        cfApiTokenInput.removeAttribute('data-has-value');
                    }
                }

                const hasToken = config.r2_cf_api_token_read === '***CONFIGURADO***' || !!config.r2_cf_api_token_read;
                this.canQueryR2Usage = !!(config.r2_account_id && config.r2_bucket_name && hasToken);

                const aeEnabled = document.getElementById('r2AutoEnqueueEnabled');
                if (aeEnabled) {
                    aeEnabled.value = config.r2_auto_enqueue_enabled ? 'true' : 'false';
                }
                const aeMod = document.getElementById('r2AutoEnqueueModalities');
                if (aeMod) {
                    aeMod.value = config.r2_auto_enqueue_modalities != null ? config.r2_auto_enqueue_modalities : 'CT';
                }
                const aeMinInstances = document.getElementById('r2AutoEnqueueMinInstances');
                if (aeMinInstances) {
                    aeMinInstances.value = Number.isFinite(config.r2_auto_enqueue_min_instances)
                        ? config.r2_auto_enqueue_min_instances
                        : 0;
                }
                const aeSource = document.getElementById('r2AutoEnqueueInstancesSource');
                if (aeSource) {
                    aeSource.value = config.r2_auto_enqueue_instances_source || 'orthanc';
                }
                const aeUrl = document.getElementById('r2AutoEnqueueWebhookUrl');
                if (aeUrl) {
                    aeUrl.value = config.r2_auto_enqueue_webhook_url || this.getSuggestedAutoEnqueueUrl();
                }
                const aePath = document.getElementById('r2AutoEnqueueEndpointPath');
                if (aePath && config.r2_auto_enqueue_endpoint_hint) {
                    aePath.textContent = config.r2_auto_enqueue_endpoint_hint;
                }
                const aeSec = document.getElementById('r2AutoEnqueueSecret');
                if (aeSec) {
                    aeSec.type = 'password';
                    if (config.r2_auto_enqueue_secret === '***CONFIGURADO***' || config.r2_auto_enqueue_secret) {
                        aeSec.value = '••••••••••••••••••••••••••••••••••••••••';
                        aeSec.setAttribute('data-has-value', 'true');
                    } else {
                        aeSec.value = '';
                        aeSec.removeAttribute('data-has-value');
                    }
                }
                await this.loadAutoEnqueuePacsNodes();
                const aeNodes = document.getElementById('r2AutoEnqueuePacsNodes');
                if (aeNodes) {
                    const selectedRaw = (config.r2_auto_enqueue_pacs_node_ids || '').toString();
                    const selected = selectedRaw
                        .split(/[,\s;]+/)
                        .map((x) => parseInt(x, 10))
                        .filter((x) => Number.isInteger(x) && x > 0);
                    Array.from(aeNodes.options).forEach((opt) => {
                        opt.selected = selected.includes(parseInt(opt.value, 10));
                    });
                }
                this.updateAutoEnqueueNodesEnabledState();
                this.updateAutoEnqueueLuaSnippet();
            }
        } catch (error) {
            console.error('Error cargando configuración:', error);
        }
    }

    getSuggestedAutoEnqueueUrl() {
        if (typeof window === 'undefined' || !window.location || !window.location.origin) {
            return '';
        }
        return `${window.location.origin}/modules/cloud-storage/api/auto-enqueue.php`;
    }

    /**
     * Genera token Bearer (32 bytes → 64 hex) para auto-enqueue. Actualiza el snippet Lua.
     */
    generateAutoEnqueueSecret() {
        const el = document.getElementById('r2AutoEnqueueSecret');
        if (!el) return;

        let hex;
        if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
            const buf = new Uint8Array(32);
            crypto.getRandomValues(buf);
            hex = Array.from(buf, (b) => b.toString(16).padStart(2, '0')).join('');
        } else {
            hex = '';
            for (let i = 0; i < 64; i++) {
                hex += Math.floor(Math.random() * 16).toString(16);
            }
        }

        el.value = hex;
        el.removeAttribute('data-has-value');
        el.type = 'text';
        this.autoEnqueueSecretPlaintext = hex;
        this.updateAutoEnqueueLuaSnippet();
        this.showNotification('Token generado. Guardá la configuración, luego copiá el Lua a Orthanc.', 'info');
    }

    updateAutoEnqueueLuaSnippet() {
        const ta = document.getElementById('r2AutoEnqueueLuaSnippet');
        if (!ta) return;

        const urlEl = document.getElementById('r2AutoEnqueueWebhookUrl');
        let url = (urlEl && urlEl.value) ? urlEl.value.trim() : '';
        if (!url) {
            url = this.getSuggestedAutoEnqueueUrl();
        }
        const secEl = document.getElementById('r2AutoEnqueueSecret');
        let tokenEsc = 'CAMBIAR_POR_TOKEN_SEGURO';
        const secRaw = secEl && secEl.value ? secEl.value.trim() : '';
        if (secRaw && !secRaw.match(/^[•\*]+$/)) {
            tokenEsc = secRaw.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        } else if (this.autoEnqueueSecretPlaintext) {
            tokenEsc = this.autoEnqueueSecretPlaintext.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        }
        const urlEsc = url.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

        ta.value = [
            '-- Copiar a notify-r2.lua (Orthanc). Mismo URL/token que esta pestaña.',
            '-- print() es compatible; LogWarning/LogInfo no existen en muchos builds.',
            '-- Si falla SSL: ca-certificates en el host Orthanc o URL http interna.',
            '',
            'local R2_AUTO_ENQUEUE_URL   = \'' + urlEsc + '\'',
            'local R2_AUTO_ENQUEUE_TOKEN = \'' + tokenEsc + '\'',
            '',
            'function OnStableStudy(studyId, tags, metadata, origin)',
            '    if R2_AUTO_ENQUEUE_URL == \'\' or R2_AUTO_ENQUEUE_TOKEN == \'\' or R2_AUTO_ENQUEUE_TOKEN == \'CAMBIAR_POR_TOKEN_SEGURO\' then',
            '        print(\'[R2 auto-enqueue] Falta URL o token\')',
            '        return',
            '    end',
            '    if origin and origin[\'RequestOrigin\'] == \'Lua\' then',
            '        return',
            '    end',
            '    SetHttpTimeout(20)',
            '    local modalities = \'\'',
            '    if tags then',
            '        modalities = tags[\'ModalitiesInStudy\'] or tags[\'Modality\'] or \'\'',
            '    end',
            '    local payload = {',
            '        orthanc_study_id   = studyId,',
            '        study_instance_uid = (tags and tags[\'StudyInstanceUID\']) or \'\',',
            '        modality           = modalities,',
            '        patient_id         = (tags and tags[\'PatientID\']) or \'\',',
            '        remote_aet         = (origin and origin[\'RemoteAet\']) or \'unknown\',',
            '    }',
            '    local headers = {',
            '        [\'Content-Type\']  = \'application/json\',',
            '        [\'Authorization\'] = \'Bearer \' .. R2_AUTO_ENQUEUE_TOKEN,',
            '    }',
            '    local body = DumpJson(payload)',
            '    local ok, success, status = pcall(function()',
            '        return HttpPost(R2_AUTO_ENQUEUE_URL, body, headers)',
            '    end)',
            '    if not ok then',
            '        print(\'[R2 auto-enqueue] HttpPost excepción (¿SSL/certificado?): \' .. tostring(success))',
            '        return',
            '    end',
            '    if not success then',
            '        print(\'[R2 auto-enqueue] HTTP falló study=\' .. tostring(studyId) .. \' status=\' .. tostring(status))',
            '    else',
            '        print(\'[R2 auto-enqueue] OK study=\' .. tostring(studyId) .. \' modality=\' .. tostring(payload.modality))',
            '    end',
            'end',
            ''
        ].join('\n');
    }

    async loadAutoEnqueuePacsNodes() {
        const selectEl = document.getElementById('r2AutoEnqueuePacsNodes');
        if (!selectEl) return;
        try {
            const response = await fetch('/modules/pacs-nodes-manager/api/nodes.php');
            const data = await response.json();
            if (!data.success || !Array.isArray(data.data)) {
                return;
            }
            this.autoEnqueuePacsNodes = data.data.filter((n) => {
                const active = n.is_active === true || n.is_active === 1 || n.is_active === '1';
                return active;
            });
            selectEl.innerHTML = this.autoEnqueuePacsNodes.map((n) => {
                const nodeType = n.node_type || 'dimse';
                return `<option value="${n.id}">${n.name} (#${n.id}) [${nodeType}]</option>`;
            }).join('');
        } catch (e) {
            console.warn('No se pudieron cargar nodos PACS para auto-encolado:', e);
        }
    }

    updateAutoEnqueueNodesEnabledState() {
        const sourceEl = document.getElementById('r2AutoEnqueueInstancesSource');
        const nodesEl = document.getElementById('r2AutoEnqueuePacsNodes');
        if (!sourceEl || !nodesEl) return;
        nodesEl.disabled = sourceEl.value !== 'pacs_nodes';
    }

    copyAutoEnqueueUrl() {
        const el = document.getElementById('r2AutoEnqueueWebhookUrl');
        const v = (el && el.value && el.value.trim()) ? el.value.trim() : this.getSuggestedAutoEnqueueUrl();
        navigator.clipboard.writeText(v).then(() => {
            this.showNotification('URL copiada', 'success');
        }).catch(() => this.showNotification('No se pudo copiar', 'error'));
    }

    copyAutoEnqueueLuaSnippet() {
        const ta = document.getElementById('r2AutoEnqueueLuaSnippet');
        if (!ta) return;
        navigator.clipboard.writeText(ta.value).then(() => {
            this.showNotification('Lua copiado', 'success');
        }).catch(() => this.showNotification('No se pudo copiar', 'error'));
    }

    async saveAutoEnqueueConfig() {
        const config = {};
        const aeEnabled = document.getElementById('r2AutoEnqueueEnabled');
        config.r2_auto_enqueue_enabled = aeEnabled && aeEnabled.value === 'true';

        const aeMod = document.getElementById('r2AutoEnqueueModalities');
        config.r2_auto_enqueue_modalities = aeMod ? aeMod.value.trim() : '';

        const aeMinInstances = document.getElementById('r2AutoEnqueueMinInstances');
        config.r2_auto_enqueue_min_instances = aeMinInstances
            ? Math.max(0, parseInt(aeMinInstances.value || '0', 10) || 0)
            : 0;

        const aeSource = document.getElementById('r2AutoEnqueueInstancesSource');
        config.r2_auto_enqueue_instances_source = (aeSource && aeSource.value === 'pacs_nodes')
            ? 'pacs_nodes'
            : 'orthanc';

        const aeNodes = document.getElementById('r2AutoEnqueuePacsNodes');
        if (aeNodes) {
            const selectedIds = Array.from(aeNodes.selectedOptions)
                .map((opt) => parseInt(opt.value, 10))
                .filter((x) => Number.isInteger(x) && x > 0);
            config.r2_auto_enqueue_pacs_node_ids = selectedIds.join(',');
        }

        const aeUrl = document.getElementById('r2AutoEnqueueWebhookUrl');
        config.r2_auto_enqueue_webhook_url = aeUrl ? aeUrl.value.trim() : '';

        const secEl = document.getElementById('r2AutoEnqueueSecret');
        if (secEl) {
            const v = secEl.value.trim();
            const hasValue = secEl.getAttribute('data-has-value') === 'true';
            if (hasValue && (v === '' || v.match(/^[•\*]+$/))) {
                // preservar
            } else if (v) {
                config.r2_auto_enqueue_secret = v;
            }
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}config.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(config)
            });
            const result = await response.json();
            if (result.success) {
                if (config.r2_auto_enqueue_secret) {
                    this.autoEnqueueSecretPlaintext = config.r2_auto_enqueue_secret;
                }
                this.showNotification('✅ Encolado automático guardado', 'success');
                await this.loadConfig();
            } else {
                const errorMsg = result.errors && result.errors.length ? result.errors.join(', ') : (result.error || 'Error');
                this.showNotification('❌ ' + errorMsg, 'error');
            }
        } catch (e) {
            console.error(e);
            this.showNotification('❌ Error de red al guardar', 'error');
        }
    }

    /**
     * Guarda configuración en .env y BD
     */
    async saveConfig() {
        // Solo enviar campos que tienen valor (no vacíos)
        // Esto evita sobrescribir valores existentes con vacíos
        const config = {};
        
        const r2Enabled = document.getElementById('r2Enabled').value;
        if (r2Enabled) config.r2_enabled = r2Enabled === 'true';
        
        const r2AccountId = document.getElementById('r2AccountId').value.trim();
        if (r2AccountId) config.r2_account_id = r2AccountId;
        
        // Access Key: solo enviar si el usuario ingresó un valor nuevo
        // Si el campo tiene el atributo data-has-value y el valor no cambió, no enviar
        const accessKeyInput = document.getElementById('r2AccessKey');
        if (accessKeyInput) {
            const r2AccessKey = accessKeyInput.value.trim();
            const hasValue = accessKeyInput.getAttribute('data-has-value') === 'true';
            
            // Si tiene valor guardado y el usuario no modificó el campo (sigue siendo asteriscos o vacío), no enviar
            if (hasValue && (r2AccessKey === '' || r2AccessKey.match(/^[•\*\.]+$/))) {
                // No enviar, preservar valor existente
            } else if (r2AccessKey) {
                // Usuario ingresó un valor nuevo
                config.r2_access_key = r2AccessKey;
            }
        }
        
        // Secret Key: solo enviar si el usuario ingresó un valor nuevo
        const secretKeyInput = document.getElementById('r2SecretKey');
        if (secretKeyInput) {
            const r2SecretKey = secretKeyInput.value.trim();
            const hasValue = secretKeyInput.getAttribute('data-has-value') === 'true';
            
            // Si tiene valor guardado y el usuario no modificó el campo (sigue siendo asteriscos o vacío), no enviar
            if (hasValue && (r2SecretKey === '' || r2SecretKey.match(/^[•\*\.]+$/))) {
                // No enviar, preservar valor existente
            } else if (r2SecretKey) {
                // Usuario ingresó un valor nuevo
                config.r2_secret_key = r2SecretKey;
            }
        }
        
        const r2BucketName = document.getElementById('r2BucketName').value.trim();
        if (r2BucketName) config.r2_bucket_name = r2BucketName;

        // Token Cloudflare API read-only para usage
        const cfApiTokenInput = document.getElementById('r2CfApiTokenRead');
        if (cfApiTokenInput) {
            const token = cfApiTokenInput.value.trim();
            const hasValue = cfApiTokenInput.getAttribute('data-has-value') === 'true';
            if (hasValue && (token === '' || token.match(/^[•\*\.]+$/))) {
                // preservar token guardado
            } else if (token) {
                config.r2_cf_api_token_read = token;
            }
        }
        
        const r2Region = document.getElementById('r2Region')?.value || 'auto';
        if (r2Region) config.r2_region = r2Region;
        
        const r2CustomDomain = document.getElementById('r2CustomDomain').value.trim();
        if (r2CustomDomain) config.r2_custom_domain = r2CustomDomain;
        const r2PublicPortalBaseUrlEl = document.getElementById('r2PublicPortalBaseUrl');
        if (r2PublicPortalBaseUrlEl) {
            config.r2_public_portal_base_url = r2PublicPortalBaseUrlEl.value.trim();
        }
        
        const r2StoragePrefix = document.getElementById('r2StoragePrefix').value.trim();
        if (r2StoragePrefix) config.r2_storage_prefix = r2StoragePrefix;
        
        const r2UploadConcurrency = parseInt(document.getElementById('r2UploadConcurrency').value);
        if (r2UploadConcurrency) config.r2_upload_concurrency = r2UploadConcurrency;
        
        const r2PresignedTtl = parseInt(document.getElementById('r2PresignedTtl').value);
        if (r2PresignedTtl) config.r2_presigned_ttl = r2PresignedTtl;
        
        const r2UploadMethod = document.getElementById('r2UploadMethod').value;
        if (r2UploadMethod) config.r2_upload_method = r2UploadMethod;
        
        const r2ZipTempDir = document.getElementById('r2ZipTempDir')?.value.trim();
        if (r2ZipTempDir) config.r2_zip_temp_dir = r2ZipTempDir;
        
        try {
            const response = await fetch(`${this.apiBaseUrl}config.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(config)
            });
            
            const result = await response.json();
            
            if (result.success) {
                let message = '✅ Configuración guardada';
                if (result.env_saved && result.db_saved) {
                    message += ' en .env y base de datos';
                } else if (result.env_saved) {
                    message += ' en .env';
                } else if (result.db_saved) {
                    message += ' en base de datos';
                }
                this.showNotification(message, 'success');
                
                // Recargar configuración para mostrar valores actualizados
                await this.loadConfig();
                await this.loadR2Usage(true);
            } else {
                const errorMsg = result.errors && result.errors.length > 0 
                    ? result.errors.join(', ') 
                    : (result.error || 'Error desconocido');
                this.showNotification('❌ Error guardando configuración: ' + errorMsg, 'error');
                console.error('Error guardando configuración:', result);
            }
        } catch (error) {
            console.error('Error guardando configuración:', error);
            this.showNotification('❌ Error guardando configuración: ' + error.message, 'error');
        }
    }

    /**
     * Prueba conexión R2
     */
    async testConnection() {
        const btn = document.getElementById('testR2ConnectionBtn');
        if (!btn) return;
        
        // Deshabilitar botón y mostrar estado de carga
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Probando...';
        
        try {
            this.showNotification('Probando conexión a R2...', 'info');
            
            const response = await fetch(`${this.apiBaseUrl}test-connection.php`);
            const data = await response.json();
            
            if (data.success) {
                this.showNotification(`✓ ${data.message}`, 'success');
                console.log('✅ Conexión R2 exitosa:', data.details);
                if (data.details?.env_source) {
                    console.log('📁 Fuente de configuración:', data.details.env_source);
                }
            } else {
                const errorMsg = data.error || 'Error al probar conexión';
                this.showNotification(`✗ ${errorMsg}`, 'error');
                console.error('❌ Error en conexión R2:', errorMsg);
                if (data.debug) {
                    console.error('🔍 Debug info:', data.debug);
                    if (data.debug.env_source) {
                        console.error('📁 Fuente de configuración:', data.debug.env_source);
                    }
                    if (data.debug.credentials_status) {
                        console.error('🔑 Estado de credenciales:', data.debug.credentials_status);
                    }
                }
            }
        } catch (error) {
            console.error('Error probando conexión R2:', error);
            this.showNotification(`✗ Error: ${error.message}`, 'error');
        } finally {
            // Restaurar botón
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    /**
     * Actualiza contadores
     */
    updateCounters() {
        document.getElementById('totalEstudios').textContent = this.filteredStudies.length;
    }

    /**
     * Configura el ordenamiento de columnas
     */
    setupColumnSorting() {
        setTimeout(() => {
            const sortableHeaders = document.querySelectorAll('#studiesTable thead th.sortable');
            sortableHeaders.forEach(header => {
                header.style.cursor = 'pointer';
                header.addEventListener('click', () => {
                    const column = header.getAttribute('data-column');
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
        const sortableHeaders = document.querySelectorAll('#studiesTable thead th.sortable');
        sortableHeaders.forEach(header => {
            const column = header.getAttribute('data-column');
            if (!column) return;
            
            // Remover iconos existentes
            const existingIcon = header.querySelector('.sort-icon');
            if (existingIcon) {
                existingIcon.remove();
            }
            
            // Crear nuevo icono
            const icon = document.createElement('i');
            icon.className = 'sort-icon ms-1';
            
            if (this.sortConfig.column === column) {
                icon.className += this.sortConfig.direction === 'asc' 
                    ? ' fas fa-sort-up' 
                    : ' fas fa-sort-down';
                icon.style.color = '#0d6efd';
            } else {
                icon.className += ' fas fa-sort';
                icon.style.color = '#6c757d';
            }
            
            header.appendChild(icon);
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
     * Guarda el estado en localStorage
     */
    saveToCache() {
        try {
            // Obtener IDs de estudios seleccionados antes de guardar
            const selectedStudyIds = Array.from(this.selectedStudies);
            
            const state = {
                studies: this.studies,
                filters: {
                    dateFrom: this.currentFilters.dateFrom,
                    dateTo: this.currentFilters.dateTo,
                    patientId: this.currentFilters.patientId,
                    modalities: [...this.currentFilters.modalities] // Guardar modalidades seleccionadas
                    // No guardamos search porque es un filtro local
                },
                sortConfig: { ...this.sortConfig }, // Guardar configuración de ordenamiento
                selectedStudyIds: selectedStudyIds, // Guardar selecciones
                selectedR2StudyIds: Array.from(this.selectedR2Studies),
                activeCloudStorageTab: this.currentTab,
                timestamp: Date.now()
            };
            localStorage.setItem(this.storageKey, JSON.stringify(state));
            console.log('💾 Estado guardado en localStorage:', this.studies.length, 'estudios');
        } catch (error) {
            console.error('Error guardando en localStorage:', error);
            // Si localStorage está lleno, intentar limpiar y guardar de nuevo
            try {
                localStorage.removeItem(this.storageKey);
                const selectedStudyIds = Array.from(this.selectedStudies);
                localStorage.setItem(this.storageKey, JSON.stringify({
                    studies: this.studies,
                    filters: {
                        dateFrom: this.currentFilters.dateFrom,
                        dateTo: this.currentFilters.dateTo,
                        patientId: this.currentFilters.patientId,
                        modalities: [...this.currentFilters.modalities]
                    },
                    sortConfig: { ...this.sortConfig },
                    selectedStudyIds: selectedStudyIds,
                    selectedR2StudyIds: Array.from(this.selectedR2Studies),
                    activeCloudStorageTab: this.currentTab,
                    timestamp: Date.now()
                }));
            } catch (e) {
                console.error('Error al intentar limpiar y guardar caché:', e);
            }
        }
    }

    /**
     * Pestañas válidas de Cloud Storage (id del panel, sin #)
     */
    getAllowedCloudStorageTabs() {
        return new Set([
            'studies',
            'queue',
            'r2-studies',
            'r2-audit',
            'auto-enqueue',
            'logs',
            'config',
            'quote-manager'
        ]);
    }

    /**
     * Activa la pestaña guardada al volver a la sección (tras cargar la página).
     */
    restoreCloudStorageTabFromCache(cachedData) {
        const allowed = this.getAllowedCloudStorageTabs();
        const tabId =
            cachedData &&
            typeof cachedData.activeCloudStorageTab === 'string' &&
            allowed.has(cachedData.activeCloudStorageTab)
                ? cachedData.activeCloudStorageTab
                : null;
        if (!tabId || tabId === 'studies') {
            return;
        }
        const btn = document.querySelector(`#mainTabs button[data-bs-target="#${tabId}"]`);
        if (!btn) {
            return;
        }
        try {
            if (typeof window.bootstrap !== 'undefined' && window.bootstrap.Tab) {
                window.bootstrap.Tab.getOrCreateInstance(btn).show();
            } else {
                btn.click();
            }
            console.log('📂 Pestaña Cloud Storage restaurada:', tabId);
        } catch (e) {
            console.warn('No se pudo restaurar pestaña Cloud Storage:', e);
        }
    }
    
    /**
     * Carga el estado desde localStorage
     */
    loadFromCache() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            if (!stored) {
                console.log('📦 No hay estado guardado en localStorage');
                return null;
            }

            const state = JSON.parse(stored);
            
            // Verificar que el estado no sea muy antiguo (24 horas)
            const maxAge = 24 * 60 * 60 * 1000; // 24 horas en milisegundos
            if (Date.now() - state.timestamp > maxAge) {
                console.log('⏰ Caché expirado, limpiando...');
                this.clearCache();
                return null;
            }

            return state;
        } catch (error) {
            console.error('❌ Error cargando desde localStorage:', error);
            return null;
        }
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
     * Restaura las selecciones de estudios en los checkboxes
     */
    restoreSelectedStudies() {
        this.selectedStudies.forEach(studyId => {
            const checkbox = document.querySelector(`input.study-checkbox[data-study-id="${studyId}"]`);
            if (checkbox) {
                checkbox.checked = true;
                const row = checkbox.closest('tr');
                if (row) {
                    row.classList.add('selected');
                }
            }
        });
        this.updateEnqueueButton();
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
     * Carga modalidades en segundo plano para estudios que no las tengan
     * OPTIMIZADO: Carga en lotes para no sobrecargar el servidor
     */
    async loadMissingModalities() {
        try {
            // Identificar estudios sin modalidad
            const studiesWithoutModality = this.studies.filter(study => 
                !study.modality || study.modality === '' || study.modality === 'N/A' || study.modality.trim() === ''
            );
            
            if (studiesWithoutModality.length === 0) {
                return; // Todos los estudios ya tienen modalidad
            }
            
            console.log(`📥 Cargando modalidades para ${studiesWithoutModality.length} estudios...`);
            
            // Cargar modalidades en lotes de 20 para no sobrecargar
            const batchSize = 20;
            for (let i = 0; i < studiesWithoutModality.length; i += batchSize) {
                const batch = studiesWithoutModality.slice(i, i + batchSize);
                
                const promises = batch.map(async (study) => {
                    try {
                        const studyId = study.orthanc_id || study.study_id;
                        if (!studyId) return;
                        
                        const response = await fetch(`/api/get_study_details.php?study_id=${studyId}`);
                        if (!response.ok) {
                            console.warn(`Error ${response.status} cargando modalidad para estudio ${studyId}`);
                            return;
                        }
                        
                        const result = await response.json();
                        
                        if (result.success && result.data && result.data.modality && result.data.modality !== 'N/A') {
                            // Actualizar el estudio en el array principal
                            const studyIndex = this.studies.findIndex(s => (s.orthanc_id || s.study_id) === studyId);
                            if (studyIndex !== -1) {
                                this.studies[studyIndex].modality = result.data.modality;
                            }
                            
                            // Actualizar en filteredStudies también
                            const filteredIndex = this.filteredStudies.findIndex(s => (s.orthanc_id || s.study_id) === studyId);
                            if (filteredIndex !== -1) {
                                this.filteredStudies[filteredIndex].modality = result.data.modality;
                            }
                            
                            // Re-renderizar la fila específica
                            this.updateStudyRowModality(studyId, result.data.modality);
                            
                            // Actualizar botones de modalidad cuando se detecta una nueva
                            this.updateModalityButtons();
                        }
                    } catch (error) {
                        console.error(`Error cargando modalidad para estudio ${study.orthanc_id || study.study_id}:`, error);
                    }
                });
                
                // Esperar a que termine este lote antes de continuar
                await Promise.all(promises);
                
                // Actualizar botones de modalidad después de cada lote
                this.updateModalityButtons();
                
                // Guardar en caché después de cada lote para persistir las modalidades cargadas
                this.saveToCache();
                
                // Pequeña pausa entre lotes para no sobrecargar
                if (i + batchSize < studiesWithoutModality.length) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                }
            }
            
            console.log('✅ Modalidades cargadas');
        } catch (error) {
            console.error('Error cargando modalidades:', error);
        }
    }
    
    /**
     * Actualiza la modalidad en una fila específica de la tabla
     */
    updateStudyRowModality(studyId, modality) {
        const row = document.querySelector(`tr[data-study-id="${studyId}"]`);
        if (row) {
            const modalityCell = row.querySelector('td:nth-child(6)'); // Columna de modalidad (6ta columna)
            if (modalityCell) {
                if (modality && modality !== 'N/A' && modality !== '') {
                    modalityCell.innerHTML = `<span class="badge bg-info">${this.escapeHtml(modality)}</span>`;
                } else {
                    modalityCell.innerHTML = '<span class="text-muted">N/A</span>';
                }
            }
        }
    }
    
    /**
     * Formatea bytes a formato legible (KB, MB, GB)
     */
    formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
    
    /**
     * Formatea duración en segundos a formato legible (HH:MM:SS o MM:SS)
     */
    formatDuration(seconds) {
        if (!seconds || seconds < 0) return '0s';
        
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        if (hours > 0) {
            return `${hours}h ${minutes}m ${secs}s`;
        } else if (minutes > 0) {
            return `${minutes}m ${secs}s`;
        } else {
            return `${secs}s`;
        }
    }
    
    /**
     * Formatea fecha DICOM (YYYYMMDD) a formato legible (DD/MM/YYYY)
     */
    formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        
        // Si viene en formato YYYYMMDD (8 caracteres)
        if (dateStr.length === 8 && !dateStr.includes('-') && !dateStr.includes('/')) {
            const year = dateStr.substring(0, 4);
            const month = dateStr.substring(4, 6);
            const day = dateStr.substring(6, 8);
            return `${day}/${month}/${year}`;
        }
        
        // Si viene en formato YYYY-MM-DD
        if (dateStr.includes('-')) {
            const parts = dateStr.split('-');
            if (parts.length === 3) {
                return `${parts[2]}/${parts[1]}/${parts[0]}`;
            }
        }
        
        // Si ya viene formateado, devolverlo tal cual
        return dateStr;
    }

    /**
     * Formatea hora DICOM (HHMMSS) a formato legible (HH:MM)
     */
    formatTime(timeStr) {
        if (!timeStr || timeStr.length < 4) return 'N/A';
        
        // Si viene en formato HHMMSS (6 caracteres) o HHMM (4 caracteres)
        if (!timeStr.includes(':')) {
            if (timeStr.length >= 4) {
                const hour = timeStr.substring(0, 2);
                const minute = timeStr.substring(2, 4);
                return `${hour}:${minute}`;
            }
        }
        
        // Si ya viene formateado (HH:MM:SS o HH:MM), tomar solo HH:MM
        if (timeStr.includes(':')) {
            const parts = timeStr.split(':');
            if (parts.length >= 2) {
                return `${parts[0]}:${parts[1]}`;
            }
        }
        
        return timeStr;
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
     * Actualiza estados R2 y de cola sin consultar PACS
     */
    async updateStudiesR2Status(studyIds) {
        try {
            const response = await fetch(`${this.apiBaseUrl}get-r2-statuses.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ study_ids: studyIds })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Actualizar estados en la lista local
                this.studies.forEach(study => {
                    const studyId = study.orthanc_id || study.study_id || '';
                    if (data.data[studyId]) {
                        study.r2_status = data.data[studyId].r2_status;
                        study.r2_manifest_path = data.data[studyId].r2_manifest_path;
                        study.queue_status = data.data[studyId].queue_status;
                    }
                });
                
                // Re-renderizar con los nuevos estados
                this.applyFilters();
            }
        } catch (error) {
            console.error('Error actualizando estados R2:', error);
            // No mostrar error al usuario, solo log
        }
    }

    /**
     * Carga y muestra el uso actual de R2 (Cloudflare API)
     */
    async loadR2Usage(silent = false) {
        const usageEl = document.getElementById('r2Usage');
        const objectsEl = document.getElementById('r2Objects');
        const refreshBtn = document.getElementById('refreshR2UsageBtn');
        const quoteUsageEl = document.getElementById('quoteUsageEl');
        const quoteLimitEl = document.getElementById('quoteLimitEl');
        const quoteAvailableEl = document.getElementById('quoteAvailableEl');
        const refreshQuoteBtn = document.getElementById('refreshR2UsageBtnQuote');
        if (!usageEl || !objectsEl) return;

        if (!this.canQueryR2Usage) {
            usageEl.textContent = '--';
            objectsEl.textContent = '--';
            if (!silent) {
                this.showNotification('Configura Account ID, Bucket y Cloudflare API Token para consultar uso R2.', 'info');
            }
            return;
        }

        // Throttle en modo silencioso: máximo 1 request/minuto
        const now = Date.now();
        if (silent && (now - this.lastR2UsageRequestAt < this.r2UsageMinIntervalMs)) {
            return;
        }
        if (this.isLoadingR2Usage) return;
        this.isLoadingR2Usage = true;
        this.lastR2UsageRequestAt = now;

        if (!silent) {
            if (refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }
            if (refreshQuoteBtn) {
                refreshQuoteBtn.disabled = true;
                refreshQuoteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}r2-usage.php`);
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'No se pudo consultar uso R2');
            }

            const usage = data.data || {};
            usageEl.textContent = usage.human || '--';
            objectsEl.textContent = (usage.object_count ?? '--').toString();
            this.r2UsageLastUpdatedAt = usage.updated_at || null;

            if (quoteUsageEl) quoteUsageEl.textContent = usage.human || '--';

            // Mostrar límite y disponible desde inputs de Quote Manager (si existen)
            const limitGbInput = document.getElementById('r2QuotaLimitGb');
            const limitGb = limitGbInput ? parseFloat(limitGbInput.value) : 0;
            const usedGb = (usage.gb != null && !Number.isNaN(usage.gb)) ? Number(usage.gb) : null;
            if (quoteLimitEl) quoteLimitEl.textContent = `${Number.isFinite(limitGb) ? limitGb : 0} GB`;
            if (quoteAvailableEl && usedGb !== null) {
                const avail = Math.max(0, limitGb - usedGb);
                quoteAvailableEl.textContent = `${avail.toFixed(2)} GB`;
            }

            if (refreshBtn) {
                refreshBtn.title = this.r2UsageLastUpdatedAt
                    ? `Uso R2 actualizado: ${this.r2UsageLastUpdatedAt}`
                    : 'Actualizar uso R2';
            }
        } catch (error) {
            console.error('Error cargando uso R2:', error);
            if (!silent) {
                this.showNotification(`❌ Error consultando uso R2: ${error.message}`, 'error');
            }
            if (!this.r2UsageLastUpdatedAt) {
                usageEl.textContent = 'N/A';
                objectsEl.textContent = 'N/A';
            }
        } finally {
            this.isLoadingR2Usage = false;
            if (!silent) {
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
                }
                if (refreshQuoteBtn) {
                    refreshQuoteBtn.disabled = false;
                    refreshQuoteBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
                }
            }
        }
    }
    
    /**
     * Mueve estudios encolados al principio de la lista
     */
    moveEnqueuedStudiesToTop(studyIds) {
        // Separar estudios encolados y no encolados
        const enqueued = [];
        const notEnqueued = [];
        
        this.studies.forEach(study => {
            const studyId = study.orthanc_id || study.study_id || '';
            if (studyIds.includes(studyId)) {
                enqueued.push(study);
            } else {
                notEnqueued.push(study);
            }
        });
        
        // Reordenar: encolados primero, luego los demás
        this.studies = [...enqueued, ...notEnqueued];
        
        // Re-aplicar filtros para actualizar la vista
        this.applyFilters();
    }
    
    /**
     * Muestra estado vacío inicial para estudios PACS
     */
    showEmptyStudiesState() {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-muted">
                    <i class="fas fa-search fa-2x mb-3 text-muted"></i>
                    <div class="mt-2">Usa los filtros de búsqueda para consultar estudios desde PACS</div>
                </td>
            </tr>
        `;
    }

    /**
     * Muestra notificación
     */
    showNotification(message, type = 'info') {
        // Crear notificación simple
        const alert = document.createElement('div');
        alert.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} alert-dismissible fade show position-fixed`;
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);
        
        setTimeout(() => {
            alert.remove();
        }, 5000);
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    const manager = new CloudStorageManager();
    manager.init();
    window.cloudStorageManager = manager; // Para acceso global si es necesario
});
