/**
 * PACS NODES MANAGER - JavaScript Frontend
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * @version 1.0.0
 */

const PacsNodesManager = {
    apiBase: 'modules/pacs-nodes-manager/api',
    nodes: [],
    currentSearchResults: [],
    filteredSearchResults: [], // Resultados filtrados localmente
    localSearchTerm: '', // Término de búsqueda local
    selectedModalities: [], // Modalidades seleccionadas para filtrar
    jobsPollingInterval: null,
    sortConfig: {
        column: null,
        direction: 'asc' // 'asc' o 'desc'
    },
    storageKey: 'pacs_nodes_manager_state', // Clave para localStorage
    activeTab: 'nodes', // Pestaña activa por defecto
    /** Resultados de búsqueda por nodo (pestañas): id de nodo -> estado */
    nodeSearchTabs: {},
    /** Id del nodo cuya pestaña de resultados está activa (string) */
    activeNodeResultTabId: null,
    /** Última comparación Cross Sync: { rows, nodeIds, nodeLabels, nodeErrors, fetchedAt } */
    crossSyncComparison: null,
    /** Filtros Cross Sync restaurados desde localStorage (se aplican al mostrar la pestaña) */
    savedCrossSyncFilters: null,
    savedCrossSyncNodeIds: null,
    /** Ordenación solo para la tabla Cross Sync (independiente de Buscar) */
    crossSyncSortConfig: {
        column: null,
        direction: 'asc'
    },
    /** Texto en minúsculas para filtrar filas de la tabla Cross Sync (solo vista) */
    crossSyncLocalSearchTerm: '',
    /** UIDs seleccionados en tabla Cross Sync para recuperación en lote */
    crossSyncSelectedStudies: {},
    /** Última lista renderizada (post-filtros) en Cross Sync */
    crossSyncLastRenderedRows: [],
    /** Estado de ejecución de recuperación en lote */
    crossSyncBatchRetrieve: null,
    /** UIDs con job pending/running en servidor (mapa uid -> true) para marcar filas Cross Sync */
    crossSyncActiveJobUids: {},
    /** Intervalo que refresca jobs activos mientras se usa Cross Sync */
    crossSyncActiveJobsTimer: null,
    /** Caché de políticas PACS Cloner (última carga de la tabla) */
    pacsClonerPoliciesList: [],
    /** Chips de modalidad en la tabla Cross Sync (códigos en mayúsculas); [] = todas */
    crossSyncListModalities: [],
    /** Restaurar columna de filtros Cross Sync colapsada (desde localStorage) */
    savedCrossSyncFiltersCollapsed: undefined,
    /**
     * Seguimiento por UID (polling C-FIND puntual). null si inactivo.
     * { studyUID, nodeIds, nodeLabels, pollSec, timerId, tickCount, lastByNode: { id: { series, instances, error, at } } }
     */
    replicationWatch: null,
    /** Insights Fase 2: historial corto + ranking de latencia */
    replicationInsights: {
        recentSessions: [],
        latencyRanking: [],
        loadedAt: null,
        days: 7,
        limit: 5,
        hidden: true
    },
    
    /**
     * Inicialización
     */
    init: function() {
        console.log('🚀 Inicializando PACS NODES MANAGER...');
        
        // Cargar estado persistente
        this.loadPersistentState();
        
        // Restaurar pestaña activa y resultados
        this.restoreActiveTab();
        
        // Si la pestaña activa es 'search', cargar nodos automáticamente
        if (this.activeTab === 'search') {
            // Los nodos se cargarán cuando se restauren los filtros
            // pero también los cargamos aquí por si acaso
            setTimeout(() => {
                this.loadNodesForSearch();
            }, 300);
        }
        
        // Cargar nodos al iniciar
        this.loadNodes();
        
        // Cargar jobs
        this.loadJobs();
        
        // Event listeners
        this.setupEventListeners();
        
        // Configurar ordenamiento de columnas
        this.setupColumnSorting();
        this.setupCrossSyncSorting();
        
        // Configurar listeners de tabs para guardar estado
        this.setupTabListeners();
        this.setupPacsClonerTabShortcuts();
        this.setupPacsClonerPanel();
        
        // Actualizar visibilidad del botón "Nuevo Nodo" según la pestaña activa
        this.updateNewNodeButtonVisibility();
        
        // Configurar polling de jobs
        this.startJobsPolling();
        this.startCrossSyncActiveJobsPolling();
        
        // Cargar dashboard
        this.loadDashboard();
        this.loadReplicationInsights().catch(err => console.warn('[replicationInsights][init]', err));
        
        window.addEventListener('beforeunload', (e) => {
            if (PacsNodesManager.replicationWatch) {
                e.preventDefault();
                e.returnValue = 'Hay una medición de replicación en curso. Si sale de esta página se detendrá.';
            }
        });
    },
    
    /**
     * Actualizar visibilidad del botón "Nuevo Nodo" según la pestaña activa
     */
    updateNewNodeButtonVisibility: function() {
        const btnNewNode = document.getElementById('btnNewNode');
        if (!btnNewNode) return;
        
        // Mostrar solo cuando la pestaña activa es 'nodes'
        if (this.activeTab === 'nodes') {
            btnNewNode.style.display = '';
        } else {
            btnNewNode.style.display = 'none';
        }
    },
    
    /**
     * Configurar event listeners
     */
    setupEventListeners: function() {
        // Botón nuevo nodo
        document.getElementById('btnNewNode').addEventListener('click', () => {
            this.openNodeModal();
        });
        
        // Guardar nodo
        document.getElementById('btnSaveNode').addEventListener('click', () => {
            this.saveNode();
        });
        
        // Selector de elementos por página (resultados)
        const perPageSelect = document.getElementById('resultsPerPageSelect');
        if (perPageSelect) {
            perPageSelect.addEventListener('change', (e) => {
                this.changeResultsPerPage(e.target.value);
                this.savePersistentState();
            });
        }
        
        // Guardar estado cuando cambian los filtros de búsqueda
        const searchInputs = ['searchNodeSelect', 'searchPatientID', 'searchPatientName', 
                              'searchDateFrom', 'searchDateTo', 'searchModality', 'searchAccessionNumber'];
        searchInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (input) {
                input.addEventListener('change', () => {
                    this.savePersistentState();
                });
                input.addEventListener('input', () => {
                    // Debounce para no guardar en cada tecla
                    clearTimeout(this.searchInputTimeout);
                    this.searchInputTimeout = setTimeout(() => {
                        this.savePersistentState();
                    }, 1000);
                });
            }
        });
        
        // Validación de fechas en tiempo real
        const dateFromInput = document.getElementById('searchDateFrom');
        const dateToInput = document.getElementById('searchDateTo');
        
        if (dateFromInput && dateToInput) {
            // Crear elemento para mostrar advertencias
            let warningElement = document.getElementById('dateRangeWarning');
            if (!warningElement) {
                warningElement = document.createElement('div');
                warningElement.id = 'dateRangeWarning';
                warningElement.className = 'alert mt-2 d-none';
                warningElement.style.fontSize = '0.875rem';
                // Insertar después del contenedor de fechas
                const dateContainer = dateToInput.closest('.row') || dateToInput.parentElement;
                if (dateContainer && dateContainer.parentElement) {
                    dateContainer.parentElement.appendChild(warningElement);
                }
            }
            
            // Validar cuando cambian las fechas
            const validateDates = () => {
                const dateFrom = dateFromInput.value;
                const dateTo = dateToInput.value;
                
                // Remover clases de error previas
                dateFromInput.classList.remove('is-invalid');
                dateToInput.classList.remove('is-invalid');
                warningElement.classList.add('d-none');
                warningElement.classList.remove('alert-danger', 'alert-warning', 'alert-info');
                
                if (dateFrom && dateTo) {
                    const fromDate = new Date(dateFrom);
                    const toDate = new Date(dateTo);
                    
                    // Validar que fecha inicio no sea mayor que fecha fin
                    if (fromDate > toDate) {
                        dateFromInput.classList.add('is-invalid');
                        dateToInput.classList.add('is-invalid');
                        warningElement.textContent = '❌ La fecha de inicio no puede ser mayor que la fecha de fin';
                        warningElement.classList.remove('d-none');
                        warningElement.classList.add('alert-danger');
                        return;
                    }
                    
                    // Calcular diferencia
                    const diffTime = Math.abs(toDate - fromDate);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    
                    // Mostrar advertencias según el rango
                    if (diffDays > 365) {
                        warningElement.textContent = `⚠️ Rango muy grande (${diffDays} días, >1 año). Puede bloquear el PACS. Se recomienda < 1 mes.`;
                        warningElement.classList.remove('d-none');
                        warningElement.classList.add('alert-danger');
                    } else if (diffDays > 180) {
                        warningElement.textContent = `⚠️ Rango grande (${diffDays} días, >6 meses). Puede causar lentitud. Se recomienda < 1 mes.`;
                        warningElement.classList.remove('d-none');
                        warningElement.classList.add('alert-danger');
                    } else if (diffDays > 30) {
                        warningElement.textContent = `ℹ️ Rango amplio (${diffDays} días, >1 mes). Puede retornar muchos estudios. Se recomienda < 1 mes.`;
                        warningElement.classList.remove('d-none');
                        warningElement.classList.add('alert-warning');
                    }
                }
            };
            
            dateFromInput.addEventListener('change', validateDates);
            dateToInput.addEventListener('change', validateDates);
            dateFromInput.addEventListener('input', validateDates);
            dateToInput.addEventListener('input', validateDates);
        }
        
        // Guardar estado cuando cambia el filtro de jobs
        const jobsFilter = document.getElementById('jobsFilterStatus');
        if (jobsFilter) {
            jobsFilter.addEventListener('change', () => {
                this.savePersistentState();
            });
        }
        
        // Cambio de tipo de nodo
        document.getElementById('nodeType').addEventListener('change', (e) => {
            this.toggleNodeTypeConfig(e.target.value);
        });
        
        // Cambio de tipo de autenticación DICOMweb
        document.getElementById('nodeDicomwebAuthType').addEventListener('change', (e) => {
            this.toggleDicomwebAuth(e.target.value);
        });
        
        // Buscar
        document.getElementById('btnSearch').addEventListener('click', () => {
            this.executeSearch();
        });
        
        // Cross Sync
        const btnCross = document.getElementById('btnCrossSyncRun');
        if (btnCross) {
            btnCross.addEventListener('click', () => this.executeCrossSync());
        }
        const crossDiff = document.getElementById('crossSyncFilterDiffOnly');
        if (crossDiff) {
            crossDiff.addEventListener('change', () => {
                this.renderCrossSyncTable();
                this.savePersistentState();
            });
        }
        const crossTreatExtras = document.getElementById('crossSyncTreatExtrasAligned');
        if (crossTreatExtras) {
            crossTreatExtras.addEventListener('change', () => {
                const comp = this.crossSyncComparison;
                if (comp && comp.nodeIds && comp.nodeIds.length && comp.rawPerNode) {
                    const rebuilt = this.buildCrossSyncMerge(
                        comp.nodeIds,
                        comp.rawPerNode,
                        comp.nodeLabels || {},
                        comp.nodeErrors || {}
                    );
                    this.applyCrossSyncModifyLogHints(rebuilt, comp.modifyLogItems || []);
                    this.crossSyncComparison = rebuilt;
                    this.savePersistentState();
                } else if (comp && comp.nodeIds && comp.nodeIds.length) {
                    this.showInfo('Para aplicar este toggle en la lista actual, vuelva a ejecutar «Comparar nodos».');
                }
                this.renderCrossSyncTable();
            });
        }
        const crossStatusChips = document.querySelectorAll('#crossSyncResultCount, #crossSyncStatAbsent, #crossSyncStatIncomplete, #crossSyncStatAligned');
        if (crossStatusChips && crossStatusChips.length) {
            crossStatusChips.forEach(chip => {
                chip.addEventListener('click', () => {
                    this.setCrossSyncResultStatusFilter(chip.dataset.status || 'all');
                    this.renderCrossSyncTable();
                    this.savePersistentState();
                });
                chip.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter' && e.key !== ' ') return;
                    e.preventDefault();
                    this.setCrossSyncResultStatusFilter(chip.dataset.status || 'all');
                    this.renderCrossSyncTable();
                    this.savePersistentState();
                });
            });
        }
        const crossSelectAll = document.getElementById('crossSyncSelectAll');
        const crossSelectNone = document.getElementById('crossSyncSelectNone');
        if (crossSelectAll) {
            crossSelectAll.addEventListener('click', () => {
                document.querySelectorAll('.cross-sync-node-cb').forEach(cb => { cb.checked = true; });
                this.savePersistentState();
            });
        }
        if (crossSelectNone) {
            crossSelectNone.addEventListener('click', () => {
                document.querySelectorAll('.cross-sync-node-cb').forEach(cb => { cb.checked = false; });
                this.savePersistentState();
            });
        }
        const crossInputIds = ['crossSyncPatientID', 'crossSyncPatientName', 'crossSyncDateFrom', 'crossSyncDateTo',
            'crossSyncModality', 'crossSyncAccessionNumber'];
        crossInputIds.forEach(inputId => {
            const el = document.getElementById(inputId);
            if (el) {
                el.addEventListener('change', () => this.savePersistentState());
                el.addEventListener('input', () => {
                    clearTimeout(this.crossSyncInputTimeout);
                    this.crossSyncInputTimeout = setTimeout(() => this.savePersistentState(), 800);
                });
            }
        });
        
        const crossSyncRow = document.getElementById('crossSyncRow');
        const toggleCross = document.getElementById('toggleCrossSyncFilters');
        const showCross = document.getElementById('showCrossSyncFilters');
        if (toggleCross && crossSyncRow) {
            toggleCross.addEventListener('click', (e) => {
                e.preventDefault();
                crossSyncRow.classList.add('filters-collapsed');
                this.savePersistentState();
            });
        }
        if (showCross && crossSyncRow) {
            showCross.addEventListener('click', (e) => {
                e.preventDefault();
                crossSyncRow.classList.remove('filters-collapsed');
                this.savePersistentState();
            });
        }
        const crossLocalFilter = document.getElementById('crossSyncLocalSearchFilter');
        const clearCrossLocal = document.getElementById('clearCrossSyncLocalSearch');
        if (crossLocalFilter) {
            crossLocalFilter.addEventListener('input', (e) => {
                this.crossSyncLocalSearchTerm = (e.target.value || '').trim().toLowerCase();
                this.updateCrossSyncLocalSearchChrome();
                this.renderCrossSyncTable();
                clearTimeout(this.crossSyncLocalSearchTimeout);
                this.crossSyncLocalSearchTimeout = setTimeout(() => this.savePersistentState(), 600);
            });
        }
        if (clearCrossLocal && crossLocalFilter) {
            clearCrossLocal.addEventListener('click', () => {
                crossLocalFilter.value = '';
                this.crossSyncLocalSearchTerm = '';
                this.updateCrossSyncLocalSearchChrome();
                this.renderCrossSyncTable();
                this.savePersistentState();
            });
        }
        const crossModWrap = document.getElementById('crossSyncModalityFiltersWrap');
        if (crossModWrap && !crossModWrap.dataset.delegBound) {
            crossModWrap.dataset.delegBound = '1';
            crossModWrap.addEventListener('click', (e) => {
                const btn = e.target.closest('.modality-btn');
                if (!btn || !crossModWrap.contains(btn)) return;
                e.preventDefault();
                const modality = btn.getAttribute('data-modality');
                if (!modality) return;
                if (modality === 'all') {
                    this.crossSyncListModalities = [];
                    crossModWrap.querySelectorAll('.modality-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                } else {
                    const allBtn = crossModWrap.querySelector('.modality-btn[data-modality="all"]');
                    if (allBtn) allBtn.classList.remove('active');
                    btn.classList.toggle('active');
                    const picked = Array.from(crossModWrap.querySelectorAll('.modality-btn.active:not([data-modality="all"])'))
                        .map(b => b.getAttribute('data-modality'))
                        .filter(Boolean);
                    this.crossSyncListModalities = picked;
                    if (!this.crossSyncListModalities.length && allBtn) {
                        allBtn.classList.add('active');
                    }
                }
                this.renderCrossSyncTable();
                this.savePersistentState();
            });
        }
        const btnCrossSelectVisible = document.getElementById('crossSyncSelectVisible');
        if (btnCrossSelectVisible) {
            btnCrossSelectVisible.addEventListener('click', () => this.selectVisibleCrossSyncRows());
        }
        const btnCrossClearSelection = document.getElementById('crossSyncClearSelection');
        if (btnCrossClearSelection) {
            btnCrossClearSelection.addEventListener('click', () => this.clearCrossSyncSelection());
        }
        const btnCrossRetrieveSelected = document.getElementById('btnCrossSyncRetrieveSelected');
        if (btnCrossRetrieveSelected) {
            btnCrossRetrieveSelected.addEventListener('click', () => this.retrieveSelectedCrossSyncStudies());
        }
        const crossBatchMode = document.getElementById('crossSyncBatchMode');
        const crossBatchFixedNode = document.getElementById('crossSyncBatchFixedNode');
        if (crossBatchMode) {
            crossBatchMode.addEventListener('change', () => this.updateCrossSyncBatchControls());
        }
        if (crossBatchFixedNode) {
            crossBatchFixedNode.addEventListener('change', () => this.updateCrossSyncBatchControls());
        }
        
        const btnWatchStop = document.getElementById('btnCrossSyncWatchStop');
        if (btnWatchStop) {
            btnWatchStop.addEventListener('click', () => this.stopReplicationWatch('user_stop'));
        }
        const watchIv = document.getElementById('crossSyncWatchInterval');
        if (watchIv) {
            watchIv.addEventListener('change', () => {
                if (!this.replicationWatch) return;
                const sec = parseInt(watchIv.value, 10) || 10;
                this.replicationWatch.pollSec = sec;
                this.replicationWatch.effectivePollSec = sec;
                this.replicationWatch.noActivityTicks = 0;
                this.restartReplicationWatchTimer();
            });
        }
        
        // Filtros rápidos de insights de replicación
        const bindInsightsFilter = (id, days) => {
            const btn = document.getElementById(id);
            if (!btn) return;
            btn.addEventListener('click', async () => {
                this.replicationInsights.days = days;
                this.renderReplicationInsights();
                try {
                    await this.loadReplicationInsights(days);
                } catch (err) {
                    console.warn('[replicationInsights][filter]', err);
                }
            });
        };
        bindInsightsFilter('crossSyncInsightsDays1', 1);
        bindInsightsFilter('crossSyncInsightsDays7', 7);
        bindInsightsFilter('crossSyncInsightsDays30', 30);
        const insightsLimit = document.getElementById('crossSyncInsightsLimit');
        if (insightsLimit) {
            insightsLimit.addEventListener('change', async (e) => {
                const limit = Math.max(1, Math.min(20, parseInt(e.target.value, 10) || 5));
                this.replicationInsights.limit = limit;
                this.savePersistentState();
                try {
                    await this.loadReplicationInsights(this.replicationInsights.days, limit);
                } catch (err) {
                    console.warn('[replicationInsights][limit]', err);
                }
            });
        }
        const refreshInsights = document.getElementById('crossSyncInsightsRefreshBtn');
        if (refreshInsights) {
            refreshInsights.addEventListener('click', async () => {
                try {
                    await this.loadReplicationInsights(this.replicationInsights.days, this.replicationInsights.limit);
                } catch (err) {
                    console.warn('[replicationInsights][refresh]', err);
                }
            });
        }
        const hideInsights = document.getElementById('crossSyncInsightsHideBtn');
        if (hideInsights) {
            hideInsights.addEventListener('click', () => {
                this.replicationInsights.hidden = true;
                this.renderReplicationInsights();
                this.savePersistentState();
            });
        }
        const showInsights = document.getElementById('crossSyncInsightsShowBtn');
        if (showInsights) {
            showInsights.addEventListener('click', async () => {
                this.replicationInsights.hidden = false;
                this.renderReplicationInsights();
                this.savePersistentState();
                try {
                    await this.loadReplicationInsights(this.replicationInsights.days, this.replicationInsights.limit);
                } catch (err) {
                    console.warn('[replicationInsights][show]', err);
                }
            });
        }
        
        // Filtro de jobs (loadJobs lee siempre el valor del select; así el polling respeta el filtro)
        document.getElementById('jobsFilterStatus').addEventListener('change', () => {
            this.loadJobs();
        });
        
        // Cargar nodos en selector de búsqueda
        document.getElementById('searchNodeSelect').addEventListener('focus', () => {
            this.loadNodesForSearch();
        });
        
        // Filtro de búsqueda local en resultados
        const localSearchFilter = document.getElementById('localSearchFilter');
        const clearLocalSearch = document.getElementById('clearLocalSearch');
        
        if (localSearchFilter) {
            localSearchFilter.addEventListener('input', (e) => {
                this.localSearchTerm = e.target.value.trim().toLowerCase();
                this.applyLocalSearchFilter();
                
                // Mostrar/ocultar botón de limpiar
                if (clearLocalSearch) {
                    if (this.localSearchTerm) {
                        clearLocalSearch.style.display = 'block';
                    } else {
                        clearLocalSearch.style.display = 'none';
                    }
                }
            });
        }
        
        if (clearLocalSearch) {
            clearLocalSearch.addEventListener('click', () => {
                if (localSearchFilter) {
                    localSearchFilter.value = '';
                    this.localSearchTerm = '';
                    this.applyLocalSearchFilter();
                    clearLocalSearch.style.display = 'none';
                }
            });
        }
        
        // Pestañas de resultados por nodo (delegación)
        const nodeResultTabsNav = document.getElementById('nodeResultTabsNav');
        if (nodeResultTabsNav) {
            nodeResultTabsNav.addEventListener('click', (e) => {
                const closeBtn = e.target.closest('[data-node-close-tab]');
                if (closeBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.closeNodeResultTab(closeBtn.getAttribute('data-node-close-tab'));
                    return;
                }
                const tabBtn = e.target.closest('[data-node-result-tab]');
                if (tabBtn) {
                    e.preventDefault();
                    this.switchToNodeResultTab(tabBtn.getAttribute('data-node-result-tab'));
                }
            });
        }
    },
    
    /**
     * Cargar lista de nodos
     */
    loadNodes: async function() {
        try {
            const response = await fetch(`${this.apiBase}/nodes.php`, {
                method: 'GET',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.nodes = data.data;
                this.renderNodesTable();
                this.loadDashboard();
                this.refreshCrossSyncNodeList();
            } else {
                this.showError('Error cargando nodos: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
            this.showError('Error de conexión al cargar nodos');
        }
    },
    
    /**
     * Renderizar tabla de nodos
     */
    renderNodesTable: function() {
        const tbody = document.getElementById('nodesTableBody');
        
        if (this.nodes.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-muted">
                        No hay nodos configurados. <a href="#" onclick="PacsNodesManager.openNodeModal(); return false;">Crear primer nodo</a>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = this.nodes.map(node => {
            const statusIcon = this.getStatusIcon(node.last_ping_status);
            const statusText = this.getStatusText(node.last_ping_status);
            const nodeTypeBadge = this.getNodeTypeBadge(node.node_type);
            const config = this.getNodeConfig(node);
            const detectedInfo = this.getDetectedInfo(node);
            
            return `
                <tr>
                    <td><strong>${this.escapeHtml(node.name)}</strong></td>
                    <td>${nodeTypeBadge}</td>
                    <td><small class="text-muted">${config}</small></td>
                    <td>
                        <span class="badge ${this.getStatusBadgeClass(node.last_ping_status)}">
                            ${statusIcon} ${statusText}
                        </span>
                    </td>
                    <td>
                        ${detectedInfo}
                    </td>
                    <td>
                        ${node.last_sync ? new Date(node.last_sync).toLocaleString('es-AR') : '-'}
                    </td>
                    <td>
                        ${node.last_ping_latency_ms ? `${node.last_ping_latency_ms}ms` : '-'}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="PacsNodesManager.testPing(${node.id})" title="Test Ping">
                            <i class="fas fa-network-wired"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-info" onclick="PacsNodesManager.detectImplementation(${node.id}, event)" title="Detectar PACS">
                            <i class="fas fa-search"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="PacsNodesManager.editNode(${node.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="PacsNodesManager.deleteNode(${node.id})" title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    },
    
    /**
     * Obtener icono de status
     */
    getStatusIcon: function(status) {
        switch(status) {
            case 'success': return '🟢';
            case 'failed': return '🔴';
            case 'timeout': return '🟡';
            default: return '⚪';
        }
    },
    
    /**
     * Obtener texto de status
     */
    getStatusText: function(status) {
        switch(status) {
            case 'success': return 'ONLINE';
            case 'failed': return 'ERROR';
            case 'timeout': return 'TIMEOUT';
            default: return 'UNKNOWN';
        }
    },
    
    /**
     * Obtener clase de badge de status
     */
    getStatusBadgeClass: function(status) {
        switch(status) {
            case 'success': return 'bg-success';
            case 'failed': return 'bg-danger';
            case 'timeout': return 'bg-warning';
            default: return 'bg-secondary';
        }
    },
    
    /**
     * Obtener badge de tipo de nodo
     */
    getNodeTypeBadge: function(type) {
        const badges = {
            'dimse': '<span class="badge bg-info">DIMSE</span>',
            'dicomweb': '<span class="badge bg-primary">DICOMweb</span>',
            'local': '<span class="badge bg-secondary">LOCAL</span>',
            'hybrid': '<span class="badge bg-success">HYBRID</span>'
        };
        return badges[type] || type;
    },
    
    /**
     * Obtener configuración del nodo para mostrar
     */
    getNodeConfig: function(node) {
        let config = '';
        
        if (node.node_type === 'dicomweb') {
            config = node.dicomweb_url || '-';
        } else if (node.node_type === 'dimse') {
            config = `${node.aet || '-'} @ ${node.host || '-'}:${node.port || '-'}`;
            if (node.manufacturer && node.manufacturer !== 'Generic') {
                config += ` (${node.manufacturer})`;
            }
        } else if (node.node_type === 'local') {
            config = 'Orthanc Local';
        } else {
            config = `${node.aet || '-'} @ ${node.host || '-'}:${node.port || '-'} / ${node.dicomweb_url || '-'}`;
        }
        
        // Agregar flags de operaciones
        if (node.node_type === 'dimse' || node.node_type === 'hybrid') {
            const flags = [];
            if (node.allow_find) flags.push('FIND');
            if (node.allow_move) flags.push('MOVE');
            if (node.allow_get) flags.push('GET');
            if (node.allow_store) flags.push('STORE');
            if (flags.length > 0) {
                config += ` [${flags.join(', ')}]`;
            }
        }
        
        return config;
    },
    
    /**
     * Obtener información detectada del PACS para mostrar
     */
    getDetectedInfo: function(node) {
        // Verificar si hay información detectada
        const hasBasicInfo = node.detected_manufacturer || node.detected_software_version;
        const hasImplInfo = node.implementation_class_uid || node.implementation_version_name;
        
        if (!hasBasicInfo && !hasImplInfo) {
            return '<small class="text-muted">No detectado</small>';
        }
        
        let info = [];
        
        // Información básica (desde objetos DICOM)
        if (node.detected_manufacturer) {
            info.push(`<strong>${this.escapeHtml(node.detected_manufacturer)}</strong>`);
        }
        if (node.detected_manufacturer_model) {
            info.push(this.escapeHtml(node.detected_manufacturer_model));
        }
        if (node.detected_software_version) {
            info.push(`v${this.escapeHtml(node.detected_software_version)}`);
        }
        if (node.detected_station_name) {
            info.push(`<small class="text-muted">(${this.escapeHtml(node.detected_station_name)})</small>`);
        }
        
        let result = info.join(' ');
        
        // Información de implementación DICOM (desde asociación)
        if (node.implementation_version_name) {
            if (result) result += '<br>';
            result += `<small class="text-info"><i class="fas fa-info-circle"></i> ${this.escapeHtml(node.implementation_version_name)}</small>`;
        }
        if (node.implementation_class_uid) {
            result += `<br><small class="text-muted" title="Implementation Class UID">UID: ${this.escapeHtml(node.implementation_class_uid)}</small>`;
        }
        
        // Fuente de detección
        if (node.implementation_detected_from) {
            const sourceLabels = {
                'dicom_objects': 'Objetos DICOM',
                'orthanc_logs': 'Logs Orthanc',
                'manual': 'Manual'
            };
            const sourceLabel = sourceLabels[node.implementation_detected_from] || node.implementation_detected_from;
            result += `<br><small class="text-muted">Fuente: ${sourceLabel}</small>`;
        }
        
        // Fecha de detección
        if (node.implementation_detected_at) {
            const detectedDate = new Date(node.implementation_detected_at);
            result += `<br><small class="text-muted">Detectado: ${detectedDate.toLocaleString('es-AR')}</small>`;
        }
        
        return result;
    },
    
    /**
     * Abrir modal para crear/editar nodo
     */
    openNodeModal: function(nodeId = null) {
        const modal = new bootstrap.Modal(document.getElementById('nodeModal'));
        const form = document.getElementById('nodeForm');
        form.reset();
        document.getElementById('nodeId').value = '';
        document.getElementById('nodeModalTitle').textContent = nodeId ? 'Editar Nodo' : 'Nuevo Nodo';
        
        if (nodeId) {
            const node = this.nodes.find(n => n.id == nodeId);
            if (node) {
                this.populateNodeForm(node);
            }
        } else {
            // Resetear configuraciones
            this.toggleNodeTypeConfig('dimse');
        }
        
        modal.show();
    },
    
    /**
     * Poblar formulario con datos del nodo
     */
    populateNodeForm: function(node) {
        document.getElementById('nodeId').value = node.id;
        document.getElementById('nodeName').value = node.name || '';
        document.getElementById('nodeType').value = node.node_type || 'dimse';
        const fqEl = document.getElementById('nodeFindQueryMode');
        if (fqEl) {
            fqEl.value = (node.find_query_mode === 'dicomweb' || node.find_query_mode === 'dimse')
                ? node.find_query_mode
                : '';
        }
        document.getElementById('nodeAet').value = node.aet || '';
        document.getElementById('nodeLocalAet').value = node.local_aet || '';
        document.getElementById('nodeHost').value = node.host || '';
        document.getElementById('nodePort').value = node.port || '';
        document.getElementById('nodeUsername').value = node.username || '';
        document.getElementById('nodeDicomwebUrl').value = node.dicomweb_url || '';
        document.getElementById('nodeDicomwebAuthType').value = node.dicomweb_auth_type || 'none';
        document.getElementById('nodeDicomwebUsername').value = node.dicomweb_username || '';
        document.getElementById('nodeDescription').value = node.description || '';
        document.getElementById('nodeIsActive').checked = node.is_active == 1;
        
        // Flags de operaciones
        document.getElementById('nodeAllowFind').checked = node.allow_find == 1;
        document.getElementById('nodeAllowMove').checked = node.allow_move == 1;
        document.getElementById('nodeAllowGet').checked = node.allow_get == 1;
        document.getElementById('nodeAllowStore').checked = node.allow_store == 1;
        document.getElementById('nodeAllowTranscoding').checked = node.allow_transcoding == 1;
        document.getElementById('nodeUseDicomTls').checked = node.use_dicom_tls == 1;
        
        // Configuración avanzada
        document.getElementById('nodeManufacturer').value = node.manufacturer || 'Generic';
        document.getElementById('nodeTimeout').value = node.timeout || 30;
        
        this.toggleNodeTypeConfig(node.node_type);
        this.toggleDicomwebAuth(node.dicomweb_auth_type || 'none');
    },
    
    /**
     * Toggle configuración según tipo de nodo
     */
    toggleNodeTypeConfig: function(nodeType) {
        const dimseConfig = document.getElementById('dimseConfig');
        const dicomwebConfig = document.getElementById('dicomwebConfig');
        
        const findWrap = document.getElementById('findQueryModeWrap');
        if (findWrap) {
            findWrap.classList.toggle('d-none', nodeType !== 'hybrid');
        }
        if (nodeType === 'dimse') {
            dimseConfig.style.display = 'block';
            dicomwebConfig.style.display = 'none';
        } else if (nodeType === 'dicomweb') {
            dimseConfig.style.display = 'none';
            dicomwebConfig.style.display = 'block';
        } else if (nodeType === 'local') {
            dimseConfig.style.display = 'none';
            dicomwebConfig.style.display = 'none';
        } else if (nodeType === 'hybrid') {
            dimseConfig.style.display = 'block';
            dicomwebConfig.style.display = 'block';
        }
    },
    
    /**
     * Toggle campos de autenticación DICOMweb
     */
    toggleDicomwebAuth: function(authType) {
        const authFields = document.getElementById('dicomwebAuthFields');
        if (authType === 'none') {
            authFields.style.display = 'none';
        } else {
            authFields.style.display = 'block';
        }
    },
    
    /**
     * Guardar nodo
     */
    saveNode: async function() {
        const form = document.getElementById('nodeForm');
        const formData = new FormData(form);
        
        const nt = formData.get('node_type');
        const fqRaw = formData.get('find_query_mode');
        const nodeData = {
            name: formData.get('name'),
            node_type: nt,
            find_query_mode: nt === 'hybrid' && fqRaw ? fqRaw : null,
            aet: formData.get('aet') || null,
            local_aet: formData.get('local_aet') || null,
            host: formData.get('host') || null,
            port: formData.get('port') ? parseInt(formData.get('port')) : null,
            username: formData.get('username') || null,
            password: formData.get('password') || null,
            dicomweb_url: formData.get('dicomweb_url') || null,
            dicomweb_auth_type: formData.get('dicomweb_auth_type') || 'none',
            dicomweb_username: formData.get('dicomweb_username') || null,
            dicomweb_password: formData.get('dicomweb_password') || null,
            description: formData.get('description') || null,
            is_active: formData.get('is_active') ? 1 : 0,
            // Flags de operaciones Orthanc
            allow_find: document.getElementById('nodeAllowFind').checked ? 1 : 0,
            allow_move: document.getElementById('nodeAllowMove').checked ? 1 : 0,
            allow_get: document.getElementById('nodeAllowGet').checked ? 1 : 0,
            allow_store: document.getElementById('nodeAllowStore').checked ? 1 : 0,
            allow_transcoding: document.getElementById('nodeAllowTranscoding').checked ? 1 : 0,
            use_dicom_tls: document.getElementById('nodeUseDicomTls').checked ? 1 : 0,
            // Configuración avanzada
            manufacturer: formData.get('manufacturer') || 'Generic',
            timeout: formData.get('timeout') ? parseInt(formData.get('timeout')) : 30
        };
        
        const nodeId = formData.get('id');
        const method = nodeId ? 'PUT' : 'POST';
        const url = `${this.apiBase}/nodes.php${nodeId ? '?id=' + nodeId : ''}`;
        
        try {
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify(nodeData)
            });
            
            const data = await response.json();
            
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('nodeModal')).hide();
                this.showSuccess(nodeId ? 'Nodo actualizado exitosamente' : 'Nodo creado exitosamente');
                this.loadNodes();
            } else {
                this.showError('Error: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
            this.showError('Error de conexión al guardar nodo');
        }
    },
    
    /**
     * Editar nodo
     */
    editNode: function(nodeId) {
        this.openNodeModal(nodeId);
    },
    
    /**
     * Eliminar nodo
     */
    deleteNode: async function(nodeId) {
        const confirmed = await this.showConfirm(
            'Eliminar Nodo',
            '¿Está seguro de eliminar este nodo? Esta acción no se puede deshacer.',
            'Eliminar',
            'Cancelar'
        );
        
        if (!confirmed) {
            return;
        }
        
        try {
            const response = await fetch(`${this.apiBase}/nodes.php?id=${nodeId}`, {
                method: 'DELETE',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Nodo eliminado exitosamente');
                this.loadNodes();
            } else {
                this.showError('Error: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
            this.showError('Error de conexión al eliminar nodo');
        }
    },
    
    /**
     * Test de ping
     */
    testPing: async function(nodeId) {
        try {
            const response = await fetch(`${this.apiBase}/ping.php?id=${nodeId}`, {
                method: 'POST',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                const status = data.data.status === 'success' ? 'ONLINE' : 'ERROR';
                this.showSuccess(`Test de conectividad: ${status} (${data.data.response_time_ms}ms)`);
                this.loadNodes();
            } else {
                this.showError('Error: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
            this.showError('Error de conexión al hacer ping');
        }
    },
    
    /**
     * Detectar información de implementación del PACS (C-ECHO + C-FIND)
     */
    detectImplementation: async function(nodeId, event) {
        const node = this.nodes.find(n => n.id === nodeId);
        if (!node) {
            this.showError('Nodo no encontrado');
            return;
        }
        
        // Mostrar indicador de carga
        const button = event?.target?.closest('button') || (event?.currentTarget || null);
        const originalHtml = button ? button.innerHTML : '';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }
        
        try {
            this.showInfo('Detectando información del PACS... (esto puede tardar unos segundos)');
            
            const response = await fetch(`${this.apiBase}/detect-implementation.php?id=${nodeId}`, {
                method: 'POST',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                const info = data.data;
                
                if (info.detected) {
                    let message = 'Información del PACS detectada: ';
                    const parts = [];
                    if (info.manufacturer) parts.push(`Fabricante: ${info.manufacturer}`);
                    if (info.manufacturer_model) parts.push(`Modelo: ${info.manufacturer_model}`);
                    if (info.software_version) parts.push(`Versión: ${info.software_version}`);
                    if (info.station_name) parts.push(`Estación: ${info.station_name}`);
                    
                    message += parts.join(', ');
                    this.showSuccess(message);
                } else {
                    let errorMsg = 'No se pudo detectar información del PACS';
                    if (info.error) {
                        errorMsg += `: ${info.error}`;
                    }
                    if (!info.echo_success) {
                        errorMsg += ' (C-ECHO falló, verifique conectividad)';
                    } else {
                        errorMsg += ' (C-FIND no devolvió información de implementación)';
                    }
                    this.showWarning(errorMsg);
                }
                
                // Recargar nodos para mostrar información actualizada
                await this.loadNodes();
            } else {
                this.showError('Error: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
            this.showError('Error de conexión al detectar implementación');
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        }
    },
    
    /**
     * Cargar nodos para selector de búsqueda
     */
    loadNodesForSearch: async function() {
        const select = document.getElementById('searchNodeSelect');
        
        if (select.options.length > 1) {
            return; // Ya están cargados
        }
        
        try {
            const response = await fetch(`${this.apiBase}/nodes.php`, {
                method: 'GET',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                select.innerHTML = '<option value="">Seleccionar nodo...</option>';
                data.data.filter(n => n.is_active).forEach(node => {
                    const option = document.createElement('option');
                    option.value = node.id;
                    option.textContent = node.name;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error:', error);
        }
    },
    
    /**
     * Validar fechas antes de ejecutar búsqueda
     * @returns {Object} {valid: boolean, warnings: Array<Object>}
     */
    validateDateFilters: function(dateFrom, dateTo) {
        const warnings = [];
        let valid = true;
        
        // Validar si no hay fechas
        if (!dateFrom && !dateTo) {
            warnings.push({
                type: 'warning',
                message: 'No se especificaron fechas. La búsqueda puede ser muy lenta y retornar muchos resultados, lo que puede bloquear el PACS. Se recomienda especificar un rango de fechas.'
            });
            // No invalidamos, solo advertimos
        }
        
        // Validar si solo hay una fecha
        if (dateFrom && !dateTo) {
            warnings.push({
                type: 'info',
                message: 'Solo se especificó fecha de inicio. Se buscarán estudios desde esa fecha en adelante. Esto puede retornar muchos resultados.'
            });
        }
        
        if (!dateFrom && dateTo) {
            warnings.push({
                type: 'info',
                message: 'Solo se especificó fecha de fin. Se buscarán estudios hasta esa fecha. Esto puede retornar muchos resultados.'
            });
        }
        
        // Validar si ambas fechas están presentes
        if (dateFrom && dateTo) {
            const fromDate = new Date(dateFrom);
            const toDate = new Date(dateTo);
            
            // Validar que fecha inicio no sea mayor que fecha fin
            if (fromDate > toDate) {
                warnings.push({
                    type: 'error',
                    message: 'La fecha de inicio es mayor que la fecha de fin. Por favor, corrija las fechas.'
                });
                valid = false;
                return { valid, warnings };
            }
            
            // Calcular diferencia en días
            const diffTime = Math.abs(toDate - fromDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            const diffMonths = Math.floor(diffDays / 30);
            
            // Advertir según el rango
            if (diffDays > 365) {
                warnings.push({
                    type: 'error',
                    message: `El rango de fechas es muy grande (${diffDays} días, más de 1 año). Esta consulta puede retornar miles de estudios y bloquear el PACS. Se recomienda usar un rango menor a 1 mes.`,
                    requiresConfirmation: true
                });
            } else if (diffDays > 180) {
                warnings.push({
                    type: 'error',
                    message: `El rango de fechas es muy grande (${diffDays} días, más de 6 meses). Esta consulta puede retornar cientos de estudios y causar lentitud en el PACS. Se recomienda usar un rango menor a 1 mes.`,
                    requiresConfirmation: true
                });
            } else if (diffDays > 30) {
                warnings.push({
                    type: 'warning',
                    message: `El rango de fechas es amplio (${diffDays} días, más de 1 mes). Esta consulta puede retornar muchos estudios y afectar el rendimiento del PACS. Se recomienda usar un rango menor a 1 mes para evitar bloqueos.`,
                    requiresConfirmation: true
                });
            }
        }
        
        return { valid, warnings };
    },
    
    /**
     * Ejecutar búsqueda
     */
    executeSearch: async function() {
        const nodeId = document.getElementById('searchNodeSelect').value;
        
        if (!nodeId) {
            this.showError('Seleccione un nodo');
            return;
        }
        
        const dateFrom = document.getElementById('searchDateFrom').value;
        const dateTo = document.getElementById('searchDateTo').value;
        
        // Validar fechas
        const dateValidation = this.validateDateFilters(dateFrom, dateTo);
        
        // Si hay errores críticos, detener la búsqueda
        if (!dateValidation.valid) {
            dateValidation.warnings.forEach(warning => {
                if (warning.type === 'error') {
                    this.showError(warning.message);
                } else if (warning.type === 'warning') {
                    this.showWarning(warning.message);
                } else {
                    this.showInfo(warning.message);
                }
            });
            return;
        }
        
        // Si hay advertencias que requieren confirmación, preguntar al usuario
        const criticalWarnings = dateValidation.warnings.filter(w => w.requiresConfirmation);
        if (criticalWarnings.length > 0) {
            // Mostrar todas las advertencias primero
            dateValidation.warnings.forEach(warning => {
                if (warning.type === 'error') {
                    this.showError(warning.message);
                } else if (warning.type === 'warning') {
                    this.showWarning(warning.message);
                } else {
                    this.showInfo(warning.message);
                }
            });
            
            // Construir mensaje de confirmación
            const confirmationMessage = criticalWarnings
                .map(w => w.message)
                .join('\n\n') + 
                '\n\n¿Desea continuar con la búsqueda de todos modos?';
            
            const continueSearch = confirm(confirmationMessage);
            if (!continueSearch) {
                return;
            }
        } else if (dateValidation.warnings.length > 0) {
            // Mostrar advertencias informativas sin bloquear
            dateValidation.warnings.forEach(warning => {
                if (warning.type === 'warning') {
                    this.showWarning(warning.message);
                } else {
                    this.showInfo(warning.message);
                }
            });
        }
        
        const query = {
            Level: 'Study',
            Query: {}
        };
        
        const patientID = document.getElementById('searchPatientID').value;
        const patientName = document.getElementById('searchPatientName').value;
        const modality = document.getElementById('searchModality').value;
        const accessionNumber = document.getElementById('searchAccessionNumber').value;
        
        if (patientID) query.Query.PatientID = patientID;
        if (patientName) query.Query.PatientName = patientName;
        if (dateFrom && dateTo) {
            query.Query.StudyDate = dateFrom.replace(/-/g, '') + '-' + dateTo.replace(/-/g, '');
        } else if (dateFrom) {
            query.Query.StudyDate = dateFrom.replace(/-/g, '');
        }
        if (modality) query.Query.ModalitiesInStudy = modality;
        if (accessionNumber) query.Query.AccessionNumber = accessionNumber;
        
        const nodeSelectEl = document.getElementById('searchNodeSelect');
        const nodeName = nodeSelectEl && nodeSelectEl.selectedIndex >= 0
            ? (nodeSelectEl.options[nodeSelectEl.selectedIndex].textContent || 'Nodo').trim()
            : 'Nodo';
        const targetId = String(nodeId);
        
        if (this.activeNodeResultTabId && String(this.activeNodeResultTabId) !== targetId) {
            this.persistCurrentTabState();
        }
        
        this.activeNodeResultTabId = targetId;
        this.ensureNodeResultTab(targetId, nodeName);
        this.updateTabActiveStyles();
        
        const tbody = document.getElementById('resultsTableBody');
        tbody.innerHTML = '<tr><td colspan="9" class="text-center"><div class="spinner-border text-primary"></div></td></tr>';
        
        try {
            const response = await fetch(`${this.apiBase}/find.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    node_id: nodeId,
                    query: query
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                const rows = data.data.data || [];
                const count = typeof data.data.count === 'number' ? data.data.count : rows.length;
                
                this.nodeSearchTabs[targetId] = {
                    nodeName: nodeName,
                    currentSearchResults: rows,
                    filteredSearchResults: [...rows],
                    localSearchTerm: '',
                    selectedModalities: [],
                    sortConfig: { column: null, direction: 'asc' },
                    resultsPagination: {
                        currentPage: 1,
                        perPage: parseInt(document.getElementById('resultsPerPageSelect')?.value || 25, 10),
                        totalItems: rows.length
                    },
                    apiCount: count
                };
                this.syncTabStateToGlobals(targetId);
                
                const localSearchFilter = document.getElementById('localSearchFilter');
                if (localSearchFilter) {
                    localSearchFilter.value = '';
                }
                const clearLocalSearch = document.getElementById('clearLocalSearch');
                if (clearLocalSearch) {
                    clearLocalSearch.style.display = 'none';
                }
                this.updateLocalSearchInfo();
                this.updateModalityButtons();
                this.updateSortIcons();
                this.updateResultsCountDisplay();
                this.renderSearchResults();
                
                // Recargar nodos para actualizar información detectada (si se detectó automáticamente)
                // Esto se hace en segundo plano sin bloquear la UI
                setTimeout(() => {
                    this.loadNodes();
                }, 500);
                
                // Guardar estado después de búsqueda exitosa
                this.savePersistentState();
            } else {
                this.showError('Error: ' + (data.error || data.message));
                this.handleSearchErrorResponse(targetId, tbody);
            }
        } catch (error) {
            console.error('Error:', error);
            this.showError('Error de conexión al buscar');
            const tbodyErr = document.getElementById('resultsTableBody');
            if (tbodyErr) {
                this.handleSearchErrorResponse(targetId, tbodyErr);
            }
        }
    },
    
    /**
     * Tras error de búsqueda: restaurar resultados previos del nodo si existían; si no, quitar pestaña vacía.
     */
    handleSearchErrorResponse: function(targetId, tbody) {
        const id = String(targetId);
        if (this.nodeSearchTabs[id]) {
            this.syncTabStateToGlobals(id);
            this.renderSearchResults();
            return;
        }
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Error en la búsqueda</td></tr>';
        const nav = document.getElementById('nodeResultTabsNav');
        const li = nav ? nav.querySelector(`[data-node-tab-li="${id}"]`) : null;
        if (li) li.remove();
        if (nav && nav.children.length === 0) nav.style.display = 'none';
        if (String(this.activeNodeResultTabId) === id) {
            this.activeNodeResultTabId = null;
        }
    },
    
    /**
     * Aplicar filtro de búsqueda local y modalidades
     */
    applyLocalSearchFilter: function() {
        if (!this.currentSearchResults || this.currentSearchResults.length === 0) {
            this.filteredSearchResults = [];
            this.renderSearchResults();
            return;
        }
        
        let filtered = [...this.currentSearchResults];
        
        // Filtrar por modalidades si hay alguna seleccionada
        if (this.selectedModalities && this.selectedModalities.length > 0) {
            console.log('[MODALITY_FILTER] Filtrando por modalidades:', this.selectedModalities);
            console.log('[MODALITY_FILTER] Resultados antes de filtrar:', filtered.length);
            
            filtered = filtered.filter(result => {
                const studyModality = (result.ModalitiesInStudy || '').toUpperCase();
                if (!studyModality) {
                    console.log('[MODALITY_FILTER] Estudio sin modalidad:', result.StudyInstanceUID);
                    return false;
                }
                
                // Verificar si la modalidad del estudio contiene alguna de las modalidades filtradas
                // (puede ser múltiple, ej: "CT, MR" o "CT\\MR")
                const matches = this.selectedModalities.some(filterModality => {
                    // Si el estudio tiene múltiples modalidades, verificar cada una
                    // Separar por coma o backslash
                    const studyModalities = studyModality.split(/[,\\]+/).map(m => m.trim()).filter(m => m);
                    const filterModalityUpper = filterModality.toUpperCase();
                    const found = studyModalities.some(mod => mod === filterModalityUpper);
                    
                    if (found) {
                        console.log('[MODALITY_FILTER] Match encontrado:', filterModalityUpper, 'en', studyModalities);
                    }
                    
                    return found;
                });
                
                if (!matches) {
                    console.log('[MODALITY_FILTER] Estudio no coincide:', result.StudyInstanceUID, 'Modalidad:', studyModality);
                }
                
                return matches;
            });
            
            console.log('[MODALITY_FILTER] Resultados después de filtrar:', filtered.length);
        }
        
        // Filtrar por término de búsqueda local
        if (this.localSearchTerm) {
            filtered = filtered.filter(result => {
                const patientName = (result.PatientName || '').toLowerCase();
                const patientID = (result.PatientID || '').toLowerCase();
                const studyDate = (result.StudyDate || '').toLowerCase();
                const studyTime = (result.StudyTime || '').toLowerCase();
                const modality = (result.ModalitiesInStudy || '').toLowerCase();
                const studyDescription = (result.StudyDescription || '').toLowerCase();
                const seriesCount = String(result.NumberOfStudyRelatedSeries ?? '').toLowerCase();
                const instancesCount = String(result.NumberOfStudyRelatedInstances ?? '').toLowerCase();
                
                return patientName.includes(this.localSearchTerm) ||
                       patientID.includes(this.localSearchTerm) ||
                       studyDate.includes(this.localSearchTerm) ||
                       studyTime.includes(this.localSearchTerm) ||
                       modality.includes(this.localSearchTerm) ||
                       studyDescription.includes(this.localSearchTerm) ||
                       seriesCount.includes(this.localSearchTerm) ||
                       instancesCount.includes(this.localSearchTerm);
            });
        }
        
        this.filteredSearchResults = filtered;
        
        // Actualizar información de búsqueda
        this.updateLocalSearchInfo();
        
        this.updateResultsCountDisplay();
        
        // Resetear a página 1 cuando se filtra
        if (this.resultsPagination) {
            this.resultsPagination.currentPage = 1;
        }
        
        // Renderizar resultados filtrados
        this.renderSearchResults();
        this.persistCurrentTabState();
    },
    
    /**
     * Obtener modalidades disponibles de los resultados actuales
     */
    getAvailableModalities: function() {
        if (!this.currentSearchResults || this.currentSearchResults.length === 0) {
            return [];
        }
        
        const modalitiesSet = new Set();
        
        this.currentSearchResults.forEach(result => {
            const modality = result.ModalitiesInStudy || '';
            if (modality) {
                // Separar modalidades múltiples (ej: "CT, MR" o "CT\\MR")
                const modalities = modality.split(/[,\\]+/).map(m => m.trim()).filter(m => m);
                modalities.forEach(mod => {
                    if (mod) {
                        modalitiesSet.add(mod.toUpperCase());
                    }
                });
            }
        });
        
        // Ordenar modalidades
        return Array.from(modalitiesSet).sort();
    },
    
    /**
     * Actualizar botones de modalidad
     */
    updateModalityButtons: function() {
        const modalityFiltersContainer = document.getElementById('modalityFiltersContainer');
        const modalityButtonsContainer = document.getElementById('modalityButtonsContainer');
        
        if (!modalityFiltersContainer || !modalityButtonsContainer) {
            return;
        }
        
        // Ocultar si no hay resultados
        if (!this.currentSearchResults || this.currentSearchResults.length === 0) {
            modalityFiltersContainer.style.display = 'none';
            return;
        }
        
        // Mostrar contenedor
        modalityFiltersContainer.style.display = 'block';
        
        const availableModalities = this.getAvailableModalities();
        const currentActiveModalities = this.selectedModalities || [];
        
        // Guardar el estado activo antes de actualizar
        const wasAllActive = currentActiveModalities.length === 0;
        
        // Limpiar botones existentes (excepto "Todas")
        const allBtn = modalityButtonsContainer.querySelector('.modality-btn[data-modality="all"]');
        modalityButtonsContainer.innerHTML = '';
        
        // Restaurar botón "Todas"
        if (allBtn) {
            modalityButtonsContainer.appendChild(allBtn);
            if (wasAllActive) {
                allBtn.classList.add('active');
            } else {
                allBtn.classList.remove('active');
            }
        } else {
            // Crear botón "Todas" si no existe
            const newAllBtn = document.createElement('button');
            newAllBtn.className = `btn modality-btn ${wasAllActive ? 'active' : ''}`;
            newAllBtn.setAttribute('data-modality', 'all');
            newAllBtn.textContent = 'Todas';
            modalityButtonsContainer.appendChild(newAllBtn);
        }
        
        // Crear botones para cada modalidad disponible
        availableModalities.forEach(modality => {
            const btn = document.createElement('button');
            btn.className = 'btn modality-btn';
            btn.setAttribute('data-modality', modality);
            btn.textContent = modality;
            
            // Restaurar estado activo si esta modalidad estaba seleccionada
            if (currentActiveModalities.includes(modality)) {
                btn.classList.add('active');
                const allButton = modalityButtonsContainer.querySelector('.modality-btn[data-modality="all"]');
                if (allButton) {
                    allButton.classList.remove('active');
                }
            }
            
            modalityButtonsContainer.appendChild(btn);
        });
        
        // Re-conectar event listeners para los nuevos botones
        this.setupModalityButtonListeners();
        
        // Actualizar contador
        this.updateModalityCounter();
    },
    
    /**
     * Configurar event listeners para botones de modalidad
     */
    setupModalityButtonListeners: function() {
        const modalityButtonsContainer = document.getElementById('modalityButtonsContainer');
        if (!modalityButtonsContainer) return;
        
        const buttons = modalityButtonsContainer.querySelectorAll('.modality-btn');
        const self = this;
        
        buttons.forEach(btn => {
            // Remover listeners previos clonando el botón
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            
            newBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const modality = this.getAttribute('data-modality');
                console.log('[MODALITY_FILTER] Click en modalidad:', modality);
                
                // Obtener botones actuales del DOM (no usar la lista original)
                const allButtons = modalityButtonsContainer.querySelectorAll('.modality-btn');
                const allBtn = modalityButtonsContainer.querySelector('.modality-btn[data-modality="all"]');
                
                if (modality === 'all') {
                    // Activar "Todas" y desactivar todas las demás
                    self.selectedModalities = [];
                    allButtons.forEach(b => {
                        if (b.getAttribute('data-modality') === 'all') {
                            b.classList.add('active');
                        } else {
                            b.classList.remove('active');
                        }
                    });
                    console.log('[MODALITY_FILTER] Todas seleccionadas, modalidades limpiadas');
                } else {
                    // Toggle de modalidad individual
                    if (this.classList.contains('active')) {
                        this.classList.remove('active');
                        self.selectedModalities = self.selectedModalities.filter(m => m !== modality);
                        console.log('[MODALITY_FILTER] Modalidad deseleccionada:', modality, 'Seleccionadas:', self.selectedModalities);
                    } else {
                        this.classList.add('active');
                        if (!self.selectedModalities.includes(modality)) {
                            self.selectedModalities.push(modality);
                        }
                        console.log('[MODALITY_FILTER] Modalidad seleccionada:', modality, 'Seleccionadas:', self.selectedModalities);
                    }
                    
                    // Si no hay modalidades seleccionadas, activar "Todas"
                    if (self.selectedModalities.length === 0 && allBtn) {
                        allBtn.classList.add('active');
                    } else if (allBtn) {
                        allBtn.classList.remove('active');
                    }
                }
                
                // Actualizar contador
                self.updateModalityCounter();
                
                // Aplicar filtros
                console.log('[MODALITY_FILTER] Aplicando filtros con modalidades:', self.selectedModalities);
                self.applyLocalSearchFilter();
                
                // Guardar estado
                self.savePersistentState();
            });
        });
    },
    
    /**
     * Actualizar contador de modalidades seleccionadas
     */
    updateModalityCounter: function() {
        const counter = document.getElementById('modalityCounter');
        if (!counter) return;
        
        if (this.selectedModalities && this.selectedModalities.length > 0) {
            counter.textContent = `${this.selectedModalities.length} seleccionada${this.selectedModalities.length > 1 ? 's' : ''}`;
            counter.style.display = 'inline-block';
        } else {
            counter.style.display = 'none';
        }
    },
    
    /**
     * Actualizar información del filtro de búsqueda local
     */
    updateLocalSearchInfo: function() {
        const localSearchInfo = document.getElementById('localSearchInfo');
        if (!localSearchInfo) return;
        
        const totalResults = this.currentSearchResults ? this.currentSearchResults.length : 0;
        const filteredResults = this.filteredSearchResults ? this.filteredSearchResults.length : 0;
        
        if (this.localSearchTerm) {
            if (filteredResults === 0) {
                localSearchInfo.textContent = `No se encontraron resultados para "${this.localSearchTerm}"`;
                localSearchInfo.className = 'text-muted d-block mt-1 text-danger';
            } else {
                localSearchInfo.textContent = `Mostrando ${filteredResults} de ${totalResults} resultados`;
                localSearchInfo.className = 'text-muted d-block mt-1';
            }
        } else {
            localSearchInfo.textContent = 'Escriba para filtrar los resultados';
            localSearchInfo.className = 'text-muted d-block mt-1';
        }
    },
    
    /**
     * Renderizar resultados de búsqueda con paginación
     */
    renderSearchResults: function() {
        const tbody = document.getElementById('resultsTableBody');
        if (!tbody) return;
        
        const hasAnyNodeTab = this.nodeSearchTabs && Object.keys(this.nodeSearchTabs).length > 0;
        if (!hasAnyNodeTab && !this.activeNodeResultTabId && (!this.currentSearchResults || this.currentSearchResults.length === 0)) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Realice una búsqueda para ver resultados (se abrirá una pestaña por nodo)</td></tr>';
            this.renderPagination();
            this.updatePaginationInfo();
            return;
        }
        
        // Usar resultados filtrados si hay término de búsqueda o filtros de modalidad, sino usar todos
        const hasFilters = this.localSearchTerm || (this.selectedModalities && this.selectedModalities.length > 0);
        const resultsToRender = hasFilters && this.filteredSearchResults
            ? this.filteredSearchResults
            : (this.currentSearchResults || []);
        
        if (!resultsToRender || resultsToRender.length === 0) {
            if (this.localSearchTerm) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No se encontraron resultados para la búsqueda</td></tr>';
            } else {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No se encontraron resultados</td></tr>';
            }
            this.renderPagination();
            this.updatePaginationInfo();
            return;
        }
        
        // Ordenar resultados antes de paginar
        const sortedResults = this.sortResults([...resultsToRender]);
        
        // Inicializar paginación si no existe
        if (!this.resultsPagination) {
            this.resultsPagination = {
                currentPage: 1,
                perPage: 25,
                totalItems: sortedResults.length
            };
        } else {
            // Actualizar total de items
            this.resultsPagination.totalItems = sortedResults.length;
        }
        
        const { currentPage, perPage } = this.resultsPagination;
        const startIndex = (currentPage - 1) * perPage;
        const endIndex = startIndex + perPage;
        const paginatedResults = sortedResults.slice(startIndex, endIndex);
        
        tbody.innerHTML = paginatedResults.map(result => {
            // Formatear hora si existe (puede venir como HH:MM:SS o HHMMSS)
            let studyTime = result.StudyTime || '-';
            if (studyTime !== '-' && studyTime.length === 6 && !studyTime.includes(':')) {
                // Convertir HHMMSS a HH:MM:SS
                studyTime = studyTime.substring(0, 2) + ':' + studyTime.substring(2, 4) + ':' + studyTime.substring(4, 6);
            }

            // Formatear fecha a DD-MM-AAAA (puede venir como YYYY-MM-DD, YYYYMMDD o rango YYYYMMDD-YYYYMMDD)
            const studyDate = this.formatStudyDate(result.StudyDate);
            
            const seriesCount = (result.NumberOfStudyRelatedSeries ?? '-');
            const instancesCount = (result.NumberOfStudyRelatedInstances ?? '-');
            
            return `
                <tr data-study-uid="${result.StudyInstanceUID}">
                    <td>${this.escapeHtml(result.PatientName || '-')}</td>
                    <td>${this.escapeHtml(result.PatientID || '-')}</td>
                    <td>${studyDate}</td>
                    <td>${studyTime}</td>
                    <td>${result.ModalitiesInStudy || '-'}</td>
                    <td>${seriesCount}</td>
                    <td>${instancesCount}</td>
                    <td>${this.escapeHtml(result.StudyDescription || '-')}</td>
                    <td>
                        <button class="btn btn-xs btn-primary" onclick="event.stopPropagation(); PacsNodesManager.retrieveStudy('${result.StudyInstanceUID}')">
                            <i class="fas fa-download"></i> Recuperar
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
        
        // Agregar event listeners para selección de filas
        this.setupRowSelection();
        
        // Renderizar paginación
        this.renderPagination();
        this.updatePaginationInfo();
    },

    /**
     * Formatear StudyDate a DD-MM-AAAA (acepta YYYY-MM-DD, YYYYMMDD y rangos YYYYMMDD-YYYYMMDD)
     */
    formatStudyDate: function(studyDate) {
        if (!studyDate) return '-';
        const raw = String(studyDate).trim();
        if (!raw) return '-';

        const formatOne = (value) => {
            if (!value) return '-';
            const v = String(value).trim();
            if (!v) return '-';

            // Caso ya formateado YYYY-MM-DD
            if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                const [yyyy, mm, dd] = v.split('-');
                return `${dd}-${mm}-${yyyy}`;
            }

            // Caso DICOM YYYYMMDD (o con basura alrededor)
            const digits = v.replace(/\D/g, '');
            if (digits.length >= 8) {
                const yyyy = digits.substring(0, 4);
                const mm = digits.substring(4, 6);
                const dd = digits.substring(6, 8);
                return `${dd}-${mm}-${yyyy}`;
            }

            return v; // Fallback: no tocar
        };

        // Rango (ej: 20260101-20260131)
        if (raw.includes('-')) {
            const parts = raw.split('-').map(p => p.trim()).filter(Boolean);
            if (parts.length === 2) {
                return `${formatOne(parts[0])} - ${formatOne(parts[1])}`;
            }
        }

        return formatOne(raw);
    },
    
    /**
     * Formatear StudyTime para tabla (HHMMSS, HHMMSS.fff u HH:MM:SS).
     */
    formatStudyTime: function(studyTime) {
        if (studyTime === null || studyTime === undefined) return '—';
        let s = String(studyTime).trim();
        if (!s) return '—';
        if (s.includes('.')) {
            s = s.split('.')[0].trim();
        }
        const digits = s.replace(/\D/g, '');
        if (digits.length >= 6) {
            return `${digits.substring(0, 2)}:${digits.substring(2, 4)}:${digits.substring(4, 6)}`;
        }
        if (s.includes(':')) {
            return s;
        }
        return s || '—';
    },
    
    /**
     * Normalizar StudyTime para ordenar (string comparable tipo HH:MM:SS).
     */
    normalizeStudyTimeForSort: function(studyTime) {
        return this.formatStudyTime(studyTime).replace(/—/g, '');
    },
    
    /**
     * Guardar en memoria el estado de la pestaña de resultados activa (filtros locales, orden, paginación).
     */
    persistCurrentTabState: function() {
        if (!this.activeNodeResultTabId) return;
        const id = String(this.activeNodeResultTabId);
        if (!this.nodeSearchTabs[id]) {
            this.nodeSearchTabs[id] = { nodeName: 'Nodo' };
        }
        const t = this.nodeSearchTabs[id];
        t.currentSearchResults = Array.isArray(this.currentSearchResults) ? [...this.currentSearchResults] : [];
        t.filteredSearchResults = Array.isArray(this.filteredSearchResults) ? [...this.filteredSearchResults] : [];
        t.localSearchTerm = this.localSearchTerm || '';
        t.selectedModalities = Array.isArray(this.selectedModalities) ? [...this.selectedModalities] : [];
        t.sortConfig = this.sortConfig ? { ...this.sortConfig } : { column: null, direction: 'asc' };
        t.resultsPagination = this.resultsPagination ? { ...this.resultsPagination } : null;
        if (t.apiCount === undefined && t.currentSearchResults.length) {
            t.apiCount = t.currentSearchResults.length;
        }
    },
    
    /**
     * Cargar en la UI compartida el estado guardado para un nodo.
     */
    syncTabStateToGlobals: function(nodeId) {
        const id = String(nodeId);
        const t = this.nodeSearchTabs[id];
        if (!t) return;
        this.currentSearchResults = Array.isArray(t.currentSearchResults) ? [...t.currentSearchResults] : [];
        this.filteredSearchResults = Array.isArray(t.filteredSearchResults) ? [...t.filteredSearchResults] : [...this.currentSearchResults];
        this.localSearchTerm = t.localSearchTerm || '';
        this.selectedModalities = Array.isArray(t.selectedModalities) ? [...t.selectedModalities] : [];
        this.sortConfig = t.sortConfig ? { ...t.sortConfig } : { column: null, direction: 'asc' };
        this.resultsPagination = t.resultsPagination ? { ...t.resultsPagination } : null;
        const nodeSelect = document.getElementById('searchNodeSelect');
        if (nodeSelect) {
            nodeSelect.value = id;
        }
        const lsf = document.getElementById('localSearchFilter');
        if (lsf) {
            lsf.value = this.localSearchTerm || '';
        }
        const clearLocalSearch = document.getElementById('clearLocalSearch');
        if (clearLocalSearch) {
            clearLocalSearch.style.display = this.localSearchTerm ? 'block' : 'none';
        }
    },
    
    /**
     * Asegurar que exista el botón de pestaña para el nodo (crear si no existe).
     */
    ensureNodeResultTab: function(nodeId, nodeName) {
        const id = String(nodeId);
        const nav = document.getElementById('nodeResultTabsNav');
        if (!nav) return;
        let tabBtn = nav.querySelector(`[data-node-result-tab="${id}"]`);
        if (!tabBtn) {
            const li = document.createElement('li');
            li.className = 'nav-item d-flex align-items-center gap-1 mb-2 me-2';
            li.setAttribute('data-node-tab-li', id);
            const label = this.escapeHtml(nodeName || `Nodo ${id}`);
            li.innerHTML = `
                <button type="button" class="nav-link py-1 px-2 rounded" data-node-result-tab="${id}">${label}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-node-close-tab="${id}" title="Cerrar pestaña" aria-label="Cerrar"><i class="fas fa-times"></i></button>
            `;
            nav.appendChild(li);
        } else if (nodeName && tabBtn.textContent.trim() !== nodeName.trim()) {
            tabBtn.textContent = nodeName;
        }
        nav.style.display = '';
    },
    
    /**
     * Actualizar estilos activos en las pestañas de nodos.
     */
    updateTabActiveStyles: function() {
        const nav = document.getElementById('nodeResultTabsNav');
        if (!nav) return;
        nav.querySelectorAll('[data-node-result-tab]').forEach(btn => {
            const nid = btn.getAttribute('data-node-result-tab');
            if (String(nid) === String(this.activeNodeResultTabId)) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    },
    
    /**
     * Cambiar a la pestaña de resultados de otro nodo (sin nueva búsqueda).
     */
    switchToNodeResultTab: function(nodeId) {
        const id = String(nodeId);
        if (!this.nodeSearchTabs[id]) return;
        if (String(this.activeNodeResultTabId) === id) return;
        this.persistCurrentTabState();
        this.activeNodeResultTabId = id;
        this.syncTabStateToGlobals(id);
        this.updateLocalSearchInfo();
        this.updateModalityButtons();
        this.updateSortIcons();
        this.updateResultsCountDisplay();
        this.renderSearchResults();
        this.updateTabActiveStyles();
        this.savePersistentState();
    },
    
    /**
     * Cerrar pestaña de resultados de un nodo.
     */
    closeNodeResultTab: function(nodeId) {
        const id = String(nodeId);
        if (!this.nodeSearchTabs[id]) return;
        delete this.nodeSearchTabs[id];
        const nav = document.getElementById('nodeResultTabsNav');
        if (nav) {
            const li = nav.querySelector(`[data-node-tab-li="${id}"]`);
            if (li) li.remove();
            if (nav.children.length === 0) {
                nav.style.display = 'none';
            }
        }
        const wasActive = String(this.activeNodeResultTabId) === id;
        if (wasActive) {
            const remaining = Object.keys(this.nodeSearchTabs);
            if (remaining.length === 0) {
                this.activeNodeResultTabId = null;
                this.clearSharedResultsUI();
            } else {
                const nextId = remaining[remaining.length - 1];
                this.activeNodeResultTabId = nextId;
                this.syncTabStateToGlobals(nextId);
                this.updateLocalSearchInfo();
                this.updateModalityButtons();
                this.updateSortIcons();
                this.updateResultsCountDisplay();
                this.renderSearchResults();
                this.updateTabActiveStyles();
            }
        }
        this.savePersistentState();
    },
    
    /**
     * Vaciar tabla y filtros compartidos cuando no queda ninguna pestaña.
     */
    clearSharedResultsUI: function() {
        this.currentSearchResults = [];
        this.filteredSearchResults = [];
        this.localSearchTerm = '';
        this.selectedModalities = [];
        this.sortConfig = { column: null, direction: 'asc' };
        this.resultsPagination = null;
        const tbody = document.getElementById('resultsTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Realice una búsqueda para ver resultados (se abrirá una pestaña por nodo)</td></tr>';
        }
        const lsf = document.getElementById('localSearchFilter');
        if (lsf) lsf.value = '';
        const clearLocalSearch = document.getElementById('clearLocalSearch');
        if (clearLocalSearch) clearLocalSearch.style.display = 'none';
        const modalityFiltersContainer = document.getElementById('modalityFiltersContainer');
        if (modalityFiltersContainer) modalityFiltersContainer.style.display = 'none';
        const resultsCount = document.getElementById('resultsCount');
        if (resultsCount) resultsCount.textContent = '0 resultados';
        const container = document.getElementById('resultsPaginationContainer');
        if (container) container.innerHTML = '';
        const infoElement = document.getElementById('resultsPaginationInfo');
        if (infoElement) infoElement.textContent = 'Mostrando 0 - 0 de 0 resultados';
        this.updateSortIcons();
    },
    
    /**
     * Reconstruir pestañas desde estado en memoria (p. ej. tras cargar localStorage).
     */
    rebuildNodeTabsNavFromState: function() {
        const nav = document.getElementById('nodeResultTabsNav');
        if (!nav) return;
        nav.innerHTML = '';
        const keys = Object.keys(this.nodeSearchTabs || {});
        if (keys.length === 0) {
            nav.style.display = 'none';
            return;
        }
        keys.forEach(k => {
            const t = this.nodeSearchTabs[k];
            this.ensureNodeResultTab(k, t.nodeName || `Nodo ${k}`);
        });
        nav.style.display = '';
        this.updateTabActiveStyles();
    },
    
    /**
     * Actualizar badge de cantidad de resultados según pestaña activa y filtros.
     */
    updateResultsCountDisplay: function() {
        const resultsCount = document.getElementById('resultsCount');
        if (!resultsCount) return;
        const id = this.activeNodeResultTabId;
        const apiCount = id && this.nodeSearchTabs[String(id)] && this.nodeSearchTabs[String(id)].apiCount != null
            ? this.nodeSearchTabs[String(id)].apiCount
            : (this.currentSearchResults ? this.currentSearchResults.length : 0);
        const hasFilters = this.localSearchTerm || (this.selectedModalities && this.selectedModalities.length > 0);
        if (hasFilters && this.filteredSearchResults) {
            resultsCount.textContent = `${this.filteredSearchResults.length} de ${apiCount} resultados`;
        } else {
            resultsCount.textContent = `${apiCount} resultados`;
        }
    },
    
    /**
     * Configurar selección de filas al hacer click
     */
    setupRowSelection: function() {
        const tbody = document.getElementById('resultsTableBody');
        if (!tbody) return;
        
        // Restaurar selección guardada (usar savedSelectedStudyUID si existe)
        const selectedStudyUID = this.savedSelectedStudyUID || null;
        
        // Agregar listeners a todas las filas
        const rows = tbody.querySelectorAll('tr[data-study-uid]');
        rows.forEach(row => {
            const studyUID = row.getAttribute('data-study-uid');
            
            // Restaurar selección si coincide
            if (selectedStudyUID && studyUID === selectedStudyUID) {
                row.classList.add('selected');
            }
            
            row.addEventListener('click', (e) => {
                // No seleccionar si se hace click en el botón
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
                this.saveSelectedStudy(studyUID);
            });
        });
        
        // Limpiar savedSelectedStudyUID después de usarlo
        if (this.savedSelectedStudyUID) {
            delete this.savedSelectedStudyUID;
        }
    },
    
    /**
     * Guardar estudio seleccionado en persistencia
     */
    saveSelectedStudy: function(studyUID) {
        // Guardar en variable temporal para usar en savePersistentState
        this.savedSelectedStudyUID = studyUID;
        // Guardar estado completo
        this.savePersistentState();
    },
    
    /**
     * Renderizar controles de paginación
     */
    renderPagination: function() {
        const container = document.getElementById('resultsPaginationContainer');
        if (!container) return;
        
        if (!this.resultsPagination) {
            container.innerHTML = '';
            return;
        }
        
        const { currentPage, perPage, totalItems } = this.resultsPagination;
        const totalPages = Math.ceil(totalItems / perPage);
        
        // Si solo hay una página o menos, no mostrar paginación
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let paginationHtml = '<nav aria-label="Paginación de resultados"><ul class="pagination justify-content-center mb-0">';
        
        // Botón anterior
        paginationHtml += `
            <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="PacsNodesManager.goToResultsPage(${currentPage - 1}); return false;" aria-label="Anterior">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
        `;
        
        // Páginas
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="PacsNodesManager.goToResultsPage(1); return false;">1</a></li>`;
            if (startPage > 2) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="PacsNodesManager.goToResultsPage(${i}); return false;">${i}</a>
                </li>
            `;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="PacsNodesManager.goToResultsPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }
        
        // Botón siguiente
        paginationHtml += `
            <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="PacsNodesManager.goToResultsPage(${currentPage + 1}); return false;" aria-label="Siguiente">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
        `;
        
        paginationHtml += '</ul></nav>';
        container.innerHTML = paginationHtml;
    },
    
    /**
     * Ir a una página específica
     */
    goToResultsPage: function(page) {
        if (!this.resultsPagination) {
            this.resultsPagination = { currentPage: 1, perPage: 25, totalItems: 0 };
        }
        
        const totalPages = Math.ceil(this.resultsPagination.totalItems / this.resultsPagination.perPage);
        if (page < 1 || page > totalPages) return;
        
        this.resultsPagination.currentPage = page;
        this.renderSearchResults();
        this.persistCurrentTabState();
        
        // Guardar estado
        this.savePersistentState();
        
        // Scroll suave hacia la tabla
        const table = document.getElementById('resultsTable');
        if (table) {
            table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    },
    
    /**
     * Cambiar cantidad de elementos por página
     */
    changeResultsPerPage: function(newPerPage) {
        if (!this.resultsPagination) {
            this.resultsPagination = { currentPage: 1, perPage: 25, totalItems: 0 };
        }
        
        this.resultsPagination.perPage = parseInt(newPerPage);
        this.resultsPagination.currentPage = 1; // Resetear a la primera página
        this.renderSearchResults();
        this.persistCurrentTabState();
    },
    
    /**
     * Actualizar información de paginación
     */
    updatePaginationInfo: function() {
        const infoElement = document.getElementById('resultsPaginationInfo');
        if (!infoElement || !this.resultsPagination) {
            return;
        }
        
        const { currentPage, perPage, totalItems } = this.resultsPagination;
        const startIndex = (currentPage - 1) * perPage + 1;
        const endIndex = Math.min(currentPage * perPage, totalItems);
        
        if (totalItems === 0) {
            infoElement.textContent = 'Mostrando 0 - 0 de 0 resultados';
        } else {
            infoElement.textContent = `Mostrando ${startIndex} - ${endIndex} de ${totalItems} resultados`;
        }
    },
    
    /**
     * Configurar ordenamiento de columnas
     */
    setupColumnSorting: function() {
        const sortableHeaders = document.querySelectorAll('#resultsTable thead th.sortable');
        sortableHeaders.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                const column = header.getAttribute('data-column');
                if (column) {
                    this.sortByColumn(column);
                }
            });
        });
    },
    
    /**
     * Ordenar por una columna específica
     */
    sortByColumn: function(column) {
        // Si se hace clic en la misma columna, invertir dirección
        if (this.sortConfig.column === column) {
            this.sortConfig.direction = this.sortConfig.direction === 'asc' ? 'desc' : 'asc';
        } else {
            // Nueva columna, ordenar ascendente por defecto
            this.sortConfig.column = column;
            this.sortConfig.direction = 'asc';
        }
        
        // Resetear a página 1 cuando se ordena
        if (this.resultsPagination) {
            this.resultsPagination.currentPage = 1;
        }
        
        // Actualizar iconos y renderizar
        this.updateSortIcons();
        this.renderSearchResults();
        this.persistCurrentTabState();
        
        // Guardar estado
        this.savePersistentState();
    },
    
    /**
     * Actualizar iconos de ordenamiento
     */
    updateSortIcons: function() {
        const sortableHeaders = document.querySelectorAll('#resultsTable thead th.sortable');
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
    },
    
    /**
     * Ordenar resultados
     */
    sortResults: function(results) {
        if (!this.sortConfig.column) {
            // Si no hay columna seleccionada, retornar sin ordenar
            return results;
        }
        
        return results.sort((a, b) => {
            let valueA, valueB;
            
            switch (this.sortConfig.column) {
                case 'patient_name':
                    valueA = (a.PatientName || '').toLowerCase();
                    valueB = (b.PatientName || '').toLowerCase();
                    break;
                case 'patient_id':
                    valueA = (a.PatientID || '').toLowerCase();
                    valueB = (b.PatientID || '').toLowerCase();
                    break;
                case 'study_date':
                    // Ordenar por fecha (formato YYYY-MM-DD)
                    valueA = a.StudyDate || '';
                    valueB = b.StudyDate || '';
                    break;
                case 'study_time':
                    // Ordenar por hora (formato HH:MM:SS o HHMMSS)
                    valueA = a.StudyTime || '';
                    valueB = b.StudyTime || '';
                    // Si viene en formato HHMMSS, convertir a HH:MM:SS para comparar
                    if (valueA.length === 6 && !valueA.includes(':')) {
                        valueA = valueA.substring(0, 2) + ':' + valueA.substring(2, 4) + ':' + valueA.substring(4, 6);
                    }
                    if (valueB.length === 6 && !valueB.includes(':')) {
                        valueB = valueB.substring(0, 2) + ':' + valueB.substring(2, 4) + ':' + valueB.substring(4, 6);
                    }
                    break;
                case 'modality':
                    valueA = (a.ModalitiesInStudy || '').toLowerCase();
                    valueB = (b.ModalitiesInStudy || '').toLowerCase();
                    break;
                case 'study_description':
                    valueA = (a.StudyDescription || '').toLowerCase();
                    valueB = (b.StudyDescription || '').toLowerCase();
                    break;
                case 'study_series_count':
                    valueA = Number(a.NumberOfStudyRelatedSeries ?? 0);
                    valueB = Number(b.NumberOfStudyRelatedSeries ?? 0);
                    break;
                case 'study_instances_count':
                    valueA = Number(a.NumberOfStudyRelatedInstances ?? 0);
                    valueB = Number(b.NumberOfStudyRelatedInstances ?? 0);
                    break;
                default:
                    return 0;
            }
            
            // Comparar valores
            let comparison = 0;
            if (this.sortConfig.column === 'study_date' || this.sortConfig.column === 'study_time') {
                // Para fechas y horas, comparar directamente (formato YYYY-MM-DD y HH:MM:SS permiten comparación string)
                comparison = valueA.localeCompare(valueB);
            } else if (this.sortConfig.column === 'study_series_count' || this.sortConfig.column === 'study_instances_count') {
                // Para conteos, comparar numéricamente
                comparison = valueA - valueB;
            } else {
                // Para texto, comparar alfabéticamente
                if (valueA < valueB) {
                    comparison = -1;
                } else if (valueA > valueB) {
                    comparison = 1;
                }
            }
            
            // Aplicar dirección de ordenamiento
            return this.sortConfig.direction === 'asc' ? comparison : -comparison;
        });
    },
    
    /**
     * Delegación de clicks para ordenar columnas en Cross Sync
     */
    setupCrossSyncSorting: function() {
        const table = document.getElementById('crossSyncResultsTable');
        if (!table || table._crossSyncSortBound) return;
        table._crossSyncSortBound = true;
        table.addEventListener('click', (e) => {
            const th = e.target.closest('th.sortable');
            if (!th || !table.contains(th)) return;
            const col = th.getAttribute('data-cross-column');
            if (!col) return;
            e.preventDefault();
            this.sortCrossSyncByColumn(col);
        });
    },
    
    /**
     * Ordenar tabla Cross Sync por columna
     */
    sortCrossSyncByColumn: function(column) {
        if (this.crossSyncSortConfig.column === column) {
            this.crossSyncSortConfig.direction = this.crossSyncSortConfig.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.crossSyncSortConfig.column = column;
            this.crossSyncSortConfig.direction = 'asc';
        }
        this.renderCrossSyncTable();
        this.savePersistentState();
    },
    
    /**
     * Iconos de orden en cabeceras Cross Sync
     */
    updateCrossSyncSortIcons: function() {
        document.querySelectorAll('#crossSyncResultsTable thead th.sortable').forEach(header => {
            const icon = header.querySelector('.sort-icon');
            if (!icon) return;
            const column = header.getAttribute('data-cross-column');
            if (this.crossSyncSortConfig.column === column) {
                icon.className = this.crossSyncSortConfig.direction === 'asc'
                    ? 'fas fa-sort-up sort-icon'
                    : 'fas fa-sort-down sort-icon';
            } else {
                icon.className = 'fas fa-sort sort-icon';
            }
        });
    },
    
    /**
     * Valor numérico para ordenar columna de nodo (instancias/series; sin dato = -1)
     */
    crossSyncNodeSortValue: function(row, nid) {
        const st = row.nodeStates[nid];
        if (!st || st.kind === 'missing' || st.kind === 'error') return -1;
        const inst = Number(st.instances) || 0;
        const ser = Number(st.series) || 0;
        return inst * 1e6 + ser;
    },
    
    /**
     * Comparar fechas de estudio en formatos mixtos (YYYYMMDD, etc.)
     */
    compareStudyDateRawForSort: function(dateA, dateB) {
        const norm = (d) => {
            if (!d) return '';
            const s = String(d);
            const digits = s.replace(/\D/g, '');
            if (digits.length >= 8) return digits.substring(0, 8);
            return s;
        };
        return norm(dateA).localeCompare(norm(dateB));
    },
    
    /**
     * Ordenar filas Cross Sync según crossSyncSortConfig
     */
    sortCrossSyncRows: function(rows) {
        const col = this.crossSyncSortConfig.column;
        const dirMul = this.crossSyncSortConfig.direction === 'desc' ? -1 : 1;
        if (!col) {
            return [...rows].sort((a, b) => {
                if (a.sortKey !== b.sortKey) return a.sortKey - b.sortKey;
                return (a.meta.PatientName || '').localeCompare(b.meta.PatientName || '', 'es', { sensitivity: 'base' });
            });
        }
        return [...rows].sort((a, b) => {
            let cmp = 0;
            switch (col) {
                case 'patient_name':
                    cmp = (a.meta.PatientName || '').localeCompare(b.meta.PatientName || '', 'es', { sensitivity: 'base' });
                    break;
                case 'patient_id':
                    cmp = (a.meta.PatientID || '').localeCompare(b.meta.PatientID || '', 'es', { sensitivity: 'base' });
                    break;
                case 'study_date':
                    cmp = this.compareStudyDateRawForSort(a.meta.StudyDate, b.meta.StudyDate);
                    break;
                case 'study_time':
                    cmp = this.normalizeStudyTimeForSort(a.meta.StudyTime).localeCompare(this.normalizeStudyTimeForSort(b.meta.StudyTime));
                    break;
                case 'modality':
                    cmp = (a.meta.ModalitiesInStudy || '').localeCompare(b.meta.ModalitiesInStudy || '', 'es', { sensitivity: 'base' });
                    break;
                case 'study_uid':
                    cmp = (a.StudyInstanceUID || '').localeCompare(b.StudyInstanceUID || '');
                    break;
                case 'summary':
                    cmp = (a.summary || '').localeCompare(b.summary || '', 'es', { sensitivity: 'base' });
                    break;
                default:
                    if (col.startsWith('node:')) {
                        const nid = col.slice(5);
                        cmp = this.crossSyncNodeSortValue(a, nid) - this.crossSyncNodeSortValue(b, nid);
                    }
                    break;
            }
            return cmp * dirMul;
        });
    },
    
    /**
     * Comprime resultados Cross Sync para localStorage si hace falta
     */
    cloneCrossSyncForStorage: function(comp, maxRows) {
        if (!comp || !Array.isArray(comp.rows)) return null;
        const { persistedTruncated, persistedRowsTotal, ...base } = comp;
        if (comp.rows.length <= maxRows) {
            return { ...base, rows: comp.rows };
        }
        return {
            ...base,
            rows: comp.rows.slice(0, maxRows),
            persistedTruncated: true,
            persistedRowsTotal: comp.rows.length
        };
    },
    
    /**
     * Nodo permite C-MOVE (recuperar al local)
     */
    nodeAllowsRetrieve: function(nid) {
        const n = (this.nodes || []).find(x => String(x.id) === String(nid));
        if (!n) return true;
        const off = n.allow_move == 0 || n.allow_move === false || String(n.allow_move) === '0';
        return !off;
    },
    
    /**
     * Nodo tipo local (Orthanc local): en Cross Sync no se ofrece recuperar al propio PACS.
     */
    isLocalPacsNode: function(nid) {
        const n = (this.nodes || []).find(x => String(x.id) === String(nid));
        return !!(n && n.node_type === 'local');
    },
    
    /**
     * Configurar listeners de tabs para guardar estado
     */
    setupTabListeners: function() {
        const tabButtons = document.querySelectorAll('#mainTabs button[data-bs-toggle="tab"]');
        tabButtons.forEach(button => {
            // Aplicar estado ANTES de que se muestre la pestaña (evento 'show' en lugar de 'shown')
            button.addEventListener('show.bs.tab', (e) => {
                const targetId = e.target.getAttribute('data-bs-target');
                if (targetId) {
                    const tabId = targetId.replace('#', '');
                    // Si se va a mostrar la pestaña de búsqueda, aplicar estado de filtros ANTES
                    if (tabId === 'search' && this.savedFiltersCollapsed !== undefined) {
                        const searchRow = document.getElementById('searchRow');
                        if (searchRow) {
                            if (this.savedFiltersCollapsed) {
                                searchRow.classList.add('filters-collapsed');
                                console.log('✅ Estado de filtros aplicado ANTES de mostrar pestaña (show event): colapsado');
                            } else {
                                searchRow.classList.remove('filters-collapsed');
                                console.log('✅ Estado de filtros aplicado ANTES de mostrar pestaña (show event): visible');
                            }
                        }
                    }
                    if (tabId === 'crossSync' && this.savedCrossSyncFiltersCollapsed !== undefined) {
                        const crossSyncRow = document.getElementById('crossSyncRow');
                        if (crossSyncRow) {
                            if (this.savedCrossSyncFiltersCollapsed) {
                                crossSyncRow.classList.add('filters-collapsed');
                            } else {
                                crossSyncRow.classList.remove('filters-collapsed');
                            }
                        }
                    }
                }
            });
            
            button.addEventListener('shown.bs.tab', (e) => {
                // Obtener el ID de la pestaña desde el data-bs-target
                const targetId = e.target.getAttribute('data-bs-target');
                if (targetId) {
                    // Remover el # del inicio
                    this.activeTab = targetId.replace('#', '');
                    this.savePersistentState();
                    console.log('💾 Pestaña activa guardada:', this.activeTab);
                    
                    // Actualizar visibilidad del botón "Nuevo Nodo"
                    this.updateNewNodeButtonVisibility();
                    
                    if (this.activeTab === 'dashboard') {
                        this.loadDashboard();
                    }
                    
                    if (this.activeTab === 'crossSync') {
                        setTimeout(() => {
                            if (this.savedCrossSyncFiltersCollapsed !== undefined) {
                                delete this.savedCrossSyncFiltersCollapsed;
                            }
                            this.refreshCrossSyncNodeList();
                            this.applyCrossSyncSavedFiltersToInputs();
                            const loc = document.getElementById('crossSyncLocalSearchFilter');
                            if (loc) loc.value = this.crossSyncLocalSearchTerm || '';
                            this.updateCrossSyncLocalSearchChrome();
                            this.refreshCrossSyncActiveJobsFromApi().then(() => {
                                if (this.crossSyncComparison) {
                                    this.renderCrossSyncTable();
                                }
                            });
                            this.renderReplicationInsights();
                            this.loadReplicationInsights().catch(err => console.warn('[replicationInsights][tab]', err));
                        }, 50);
                    }
                    
                    if (this.activeTab === 'pacsCloner') {
                        setTimeout(() => {
                            this.refreshPacsClonerTab().catch(err => console.warn('[pacsCloner][tab]', err));
                        }, 50);
                    }
                    
                    // Si se navega a la pestaña de búsqueda, restaurar estado de filtros
                    if (this.activeTab === 'search') {
                        setTimeout(async () => {
                            const hasNodeTabs = this.nodeSearchTabs && Object.keys(this.nodeSearchTabs).length > 0;
                            if ((this.currentSearchResults && this.currentSearchResults.length > 0) || hasNodeTabs) {
                                await this.restoreSearchFilters();
                                this.restoreSearchResults();
                            }
                            // Verificar que el estado de filtros esté aplicado (ya debería estar, pero por si acaso)
                            setTimeout(() => {
                                this.restoreFiltersState();
                            }, 100);
                        }, 50);
                    }
                }
            });
        });
    },
    
    /**
     * Atajos desde la pestaña PACS Cloner hacia Cross Sync y Jobs (misma ventana).
     */
    setupPacsClonerTabShortcuts: function() {
        const goCross = document.getElementById('pacsClonerGotoCrossSync');
        const goJobs = document.getElementById('pacsClonerGotoJobs');
        const showTab = (selector) => {
            const el = document.querySelector(selector);
            if (el && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(el).show();
            }
        };
        if (goCross) {
            goCross.addEventListener('click', () => showTab('#cross-sync-tab'));
        }
        if (goJobs) {
            goJobs.addEventListener('click', () => showTab('#jobs-tab'));
        }
    },
    
    /**
     * Nodos elegibles como origen de C-MOVE (excluye Orthanc local).
     */
    getPacsClonerSourceNodes: function() {
        return (this.nodes || []).filter(n => n && n.is_active && !this.isLocalPacsNode(n.id));
    },
    
    populatePacsClonerNodeSelects: function() {
        const src = this.getPacsClonerSourceNodes();
        const policySel = document.getElementById('pacsClonerPolicyNode');
        const dispatchSel = document.getElementById('pacsClonerDispatchNode');
        const fill = (sel) => {
            if (!sel) return;
            const cur = sel.value;
            sel.innerHTML = '<option value="">Seleccionar nodo…</option>';
            src.forEach(n => {
                const opt = document.createElement('option');
                opt.value = String(n.id);
                opt.textContent = `${n.name} (${n.node_type || 'dimse'})`;
                sel.appendChild(opt);
            });
            if (cur && [...sel.options].some(o => o.value === cur)) {
                sel.value = cur;
            }
        };
        fill(policySel);
        fill(dispatchSel);
    },
    
    /** Normaliza fecha YYYY-MM-DD para input type=date (desde API MySQL). */
    formatPolicyDateForInput: function(raw) {
        if (raw == null || raw === '') return '';
        const s = String(raw).trim();
        if (s.length >= 10 && /^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
        return '';
    },
    
    clearPacsClonerPolicyForm: function() {
        const form = document.getElementById('pacsClonerPolicyForm');
        if (form) form.reset();
        const hid = document.getElementById('pacsClonerPolicyId');
        if (hid) hid.value = '';
        const scan = document.getElementById('pacsClonerPolicyScanHours');
        if (scan) scan.value = '24';
        const mc = document.getElementById('pacsClonerPolicyMaxConc');
        if (mc) mc.value = '2';
        const mode = document.getElementById('pacsClonerPolicyMode');
        if (mode) mode.value = 'paused';
        const df = document.getElementById('pacsClonerPolicyDateFrom');
        const dt = document.getElementById('pacsClonerPolicyDateTo');
        if (df) df.value = '';
        if (dt) dt.value = '';
        const mp = document.getElementById('pacsClonerPolicyModalityPriority');
        if (mp) mp.value = '';
        const al = document.getElementById('pacsClonerPolicyAlignment');
        if (al) al.value = 'study';
        const disc = document.getElementById('pacsClonerPolicyDiscoverySort');
        if (disc) disc.value = 'modality';
        const title = document.getElementById('pacsClonerPolicyFormTitle');
        if (title) title.textContent = 'Nueva política';
        const subLbl = document.getElementById('pacsClonerPolicySubmitLabel');
        if (subLbl) subLbl.textContent = 'Guardar política';
        const cancel = document.getElementById('btnPacsClonerPolicyCancelEdit');
        if (cancel) cancel.classList.add('d-none');
    },
    
    fillPacsClonerPolicyForm: function(p) {
        if (!p) return;
        this.populatePacsClonerNodeSelects();
        const hid = document.getElementById('pacsClonerPolicyId');
        if (hid) hid.value = String(p.id || '');
        const name = document.getElementById('pacsClonerPolicyName');
        if (name) name.value = p.name || '';
        const node = document.getElementById('pacsClonerPolicyNode');
        if (node) node.value = String(p.node_id || '');
        const scan = document.getElementById('pacsClonerPolicyScanHours');
        if (scan) scan.value = String(p.scan_window_hours != null ? p.scan_window_hours : 24);
        const mc = document.getElementById('pacsClonerPolicyMaxConc');
        if (mc) mc.value = String(p.max_concurrent != null ? p.max_concurrent : 2);
        const mode = document.getElementById('pacsClonerPolicyMode');
        if (mode && p.mode) mode.value = p.mode;
        const mod = document.getElementById('pacsClonerPolicyModality');
        if (mod) mod.value = p.modality_filter || '';
        const prio = document.getElementById('pacsClonerPolicyModalityPriority');
        if (prio) prio.value = p.modality_priority || '';
        const align = document.getElementById('pacsClonerPolicyAlignment');
        if (align) align.value = (p.alignment_strategy && ['study', 'series', 'instance'].includes(p.alignment_strategy)) ? p.alignment_strategy : 'study';
        const disc = document.getElementById('pacsClonerPolicyDiscoverySort');
        if (disc) {
            const ds = p.discovery_sort && ['modality', 'oldest_study', 'newest_study'].includes(p.discovery_sort) ? p.discovery_sort : 'modality';
            disc.value = ds;
        }
        const notes = document.getElementById('pacsClonerPolicyNotes');
        if (notes) notes.value = p.notes || '';
        const en = document.getElementById('pacsClonerPolicyEnabled');
        if (en) en.checked = !!(p.is_enabled == 1 || p.is_enabled === true);
        const df = document.getElementById('pacsClonerPolicyDateFrom');
        const dt = document.getElementById('pacsClonerPolicyDateTo');
        if (df) df.value = this.formatPolicyDateForInput(p.date_from);
        if (dt) dt.value = this.formatPolicyDateForInput(p.date_to);
        const title = document.getElementById('pacsClonerPolicyFormTitle');
        if (title) title.textContent = 'Editar política';
        const subLbl = document.getElementById('pacsClonerPolicySubmitLabel');
        if (subLbl) subLbl.textContent = 'Actualizar política';
        const cancel = document.getElementById('btnPacsClonerPolicyCancelEdit');
        if (cancel) cancel.classList.remove('d-none');
        const card = document.getElementById('pacsClonerPolicyForm')?.closest('.card');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },
    
    loadPacsClonerPolicies: async function() {
        const tbody = document.getElementById('pacsClonerPoliciesTableBody');
        if (!tbody) return;
        try {
            const response = await fetch(`${this.apiBase}/cloner.php?policies=1`, { credentials: 'include' });
            const data = await response.json();
            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="8" class="text-danger text-center py-3">${this.escapeHtml(data.error || 'Error')}</td></tr>`;
                this.pacsClonerPoliciesList = [];
                return;
            }
            const list = data.data && data.data.policies ? data.data.policies : [];
            this.pacsClonerPoliciesList = list;
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">Sin políticas. Cree una con el formulario.</td></tr>';
                return;
            }
            tbody.innerHTML = list.map(p => {
                const en = p.is_enabled == 1 || p.is_enabled === true ? 'Sí' : 'No';
                const pid = this.escapeHtml(String(p.id || ''));
                const align = this.escapeHtml(p.alignment_strategy || 'study');
                const ds = p.discovery_sort && ['modality', 'oldest_study', 'newest_study'].includes(p.discovery_sort) ? p.discovery_sort : 'modality';
                const dsLabel = ds === 'oldest_study' ? 'Antiguos' : (ds === 'newest_study' ? 'Recientes' : 'Modalidad');
                return `<tr>
                    <td>${this.escapeHtml(p.name || '')}</td>
                    <td>${this.escapeHtml(p.node_name || p.node_id || '')}</td>
                    <td>${this.escapeHtml(p.mode || '')}</td>
                    <td>${this.escapeHtml(String(p.scan_window_hours ?? ''))}</td>
                    <td>${this.escapeHtml(en)}</td>
                    <td>${this.escapeHtml(dsLabel)}</td>
                    <td>${align}</td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary pacs-cloner-edit-policy" data-policy-id="${pid}" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger pacs-cloner-delete-policy" data-policy-id="${pid}" title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
            tbody.querySelectorAll('.pacs-cloner-edit-policy').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-policy-id');
                    const row = (this.pacsClonerPoliciesList || []).find(x => String(x.id) === String(id));
                    if (row) this.fillPacsClonerPolicyForm(row);
                });
            });
            tbody.querySelectorAll('.pacs-cloner-delete-policy').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const id = btn.getAttribute('data-policy-id');
                    const row = (this.pacsClonerPoliciesList || []).find(x => String(x.id) === String(id));
                    const label = row ? (row.name || `#${id}`) : `#${id}`;
                    const ok = await this.showConfirm(
                        'Eliminar política',
                        `¿Eliminar la política «${label}»? Las órdenes existentes conservan su historial (policy_id quedará sin enlace).`,
                        'Eliminar',
                        'Cancelar'
                    );
                    if (!ok) return;
                    try {
                        const resp = await fetch(`${this.apiBase}/cloner.php`, {
                            method: 'POST',
                            credentials: 'include',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_policy', id: parseInt(id, 10) })
                        });
                        const d = await resp.json();
                        if (d.success) {
                            this.showSuccess('Política eliminada');
                            this.clearPacsClonerPolicyForm();
                            await this.loadPacsClonerPolicies();
                            this.populatePacsClonerNodeSelects();
                        } else {
                            this.showError(d.error || 'No se pudo eliminar');
                        }
                    } catch (err) {
                        console.error(err);
                        this.showError('Error de red');
                    }
                });
            });
        } catch (e) {
            console.error(e);
            this.pacsClonerPoliciesList = [];
            tbody.innerHTML = '<tr><td colspan="8" class="text-danger text-center py-3">Error de red</td></tr>';
        }
    },
    
    loadPacsClonerOrders: async function() {
        const tbody = document.getElementById('pacsClonerOrdersTableBody');
        if (!tbody) return;
        try {
            const response = await fetch(`${this.apiBase}/cloner.php?orders=1&limit=50`, { credentials: 'include' });
            const data = await response.json();
            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="9" class="text-danger text-center py-3">${this.escapeHtml(data.error || 'Error')}</td></tr>`;
                return;
            }
            const list = data.data && data.data.orders ? data.data.orders : [];
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-muted text-center py-3">Sin órdenes aún.</td></tr>';
                return;
            }
            tbody.innerHTML = list.map(o => {
                let uids = [];
                try {
                    uids = typeof o.study_instance_uids === 'string' ? JSON.parse(o.study_instance_uids) : (o.study_instance_uids || []);
                } catch (x) { uids = []; }
                const nStud = Array.isArray(uids) ? uids.length : 0;
                const disp = (o.job_status && ['success', 'failed', 'cancelled'].includes(o.job_status))
                    ? o.job_status
                    : (o.status || '—');
                const jobCell = o.pacs_node_job_id
                    ? `<a href="#" class="pacs-cloner-job-link" data-job-id="${this.escapeHtml(String(o.pacs_node_job_id))}">${this.escapeHtml(String(o.pacs_node_job_id))}</a>`
                    : '—';
                const created = o.created_at ? this.escapeHtml(String(o.created_at)) : '—';
                const oid = this.escapeHtml(String(o.id || ''));
                const retryBtn = (o.status === 'failed')
                    ? `<button type="button" class="btn btn-sm btn-outline-primary pacs-cloner-retry-order" data-order-id="${oid}">Reintentar</button>`
                    : '—';
                return `<tr>
                    <td>${this.escapeHtml(String(o.id))}</td>
                    <td>${this.escapeHtml(o.trigger_type || '—')}</td>
                    <td>${this.escapeHtml(o.node_name || '')}</td>
                    <td><span class="badge bg-secondary">${this.escapeHtml(disp)}</span></td>
                    <td>${jobCell}</td>
                    <td>${nStud}</td>
                    <td>${this.escapeHtml(o.label || '')}</td>
                    <td>${created}</td>
                    <td class="text-end">${retryBtn}</td>
                </tr>`;
            }).join('');
            tbody.querySelectorAll('.pacs-cloner-job-link').forEach(a => {
                a.addEventListener('click', (ev) => {
                    ev.preventDefault();
                    const jid = a.getAttribute('data-job-id');
                    if (jid && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                        bootstrap.Tab.getOrCreateInstance(document.querySelector('#jobs-tab')).show();
                        this.loadJobs();
                    }
                });
            });
            tbody.querySelectorAll('.pacs-cloner-retry-order').forEach(btn => {
                btn.addEventListener('click', async (ev) => {
                    ev.preventDefault();
                    const oid = btn.getAttribute('data-order-id');
                    if (!oid) return;
                    btn.disabled = true;
                    try {
                        const response = await fetch(`${this.apiBase}/cloner.php`, {
                            method: 'POST',
                            credentials: 'include',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'retry_cloner_order', id: parseInt(oid, 10) })
                        });
                        const d = await response.json();
                        if (d.success) {
                            this.showSuccess(d.message || 'Orden reencolada');
                            await this.loadPacsClonerOrders();
                        } else {
                            this.showError(d.error || 'No se pudo reintentar');
                        }
                    } catch (err) {
                        console.error(err);
                        this.showError('Error de red');
                    } finally {
                        btn.disabled = false;
                    }
                });
            });
        } catch (e) {
            console.error(e);
            tbody.innerHTML = '<tr><td colspan="9" class="text-danger text-center py-3">Error de red</td></tr>';
        }
    },
    
    refreshPacsClonerTab: async function() {
        if (!this.nodes || this.nodes.length === 0) {
            await this.loadNodes();
        }
        this.populatePacsClonerNodeSelects();
        await this.loadPacsClonerWorkerConsole();
        await this.loadPacsClonerPolicies();
        await this.loadPacsClonerOrders();
    },

    loadPacsClonerWorkerConsole: async function() {
        const v1 = document.getElementById('pacsClonerWorkerV1Enabled');
        const v2 = document.getElementById('pacsClonerWorkerV2Enabled');
        const hintEl = document.getElementById('pacsClonerWorkerConsoleHint');
        const runsBody = document.getElementById('pacsClonerWorkerRunsTableBody');
        const ordBody = document.getElementById('pacsClonerWorkerRecentOrdersBody');
        const qSum = document.getElementById('pacsClonerWorkerQueueSummary');
        try {
            const response = await fetch(`${this.apiBase}/cloner.php?worker_console=1`, { credentials: 'include' });
            const data = await response.json();
            if (!data.success || !data.data) {
                if (hintEl) hintEl.textContent = data.error || 'No se pudo cargar la consola del worker.';
                return;
            }
            const d = data.data;
            if (v1 && d.flags) v1.checked = !!d.flags.v1_enabled;
            if (v2 && d.flags) v2.checked = !!d.flags.v2_enabled;
            const rt = d.runtime || {};
            const setNum = (id, key) => {
                const el = document.getElementById(id);
                const o = rt[key];
                if (el && o && o.value != null) el.value = String(o.value);
            };
            setNum('pacsClonerRuntimeMaxPerRun', 'max_per_run');
            setNum('pacsClonerRuntimeMaxPerPolicy', 'max_per_policy');
            setNum('pacsClonerRuntimeDispatchPoll', 'dispatch_poll_sec');
            setNum('pacsClonerRuntimeDispatchMaxSec', 'dispatch_max_sec');
            setNum('pacsClonerRuntimeDispatchMaxStarts', 'dispatch_max_starts');
            setNum('pacsClonerRuntimeReconcileLimit', 'reconcile_jobs_limit');
            setNum('pacsClonerRuntimeStalePendingMin', 'stale_pending_minutes');
            setNum('pacsClonerRuntimeStaleRunningH', 'stale_running_hours');
            const mg = document.getElementById('pacsClonerRuntimeModalityGlobal');
            if (mg && rt.modality_priority_global) mg.value = rt.modality_priority_global.value || '';
            const rchk = document.getElementById('pacsClonerRuntimeReconcileEnabled');
            if (rchk && rt.reconcile_jobs_enabled) rchk.checked = !!rt.reconcile_jobs_enabled.value;
            const envKeys = [];
            Object.keys(rt).forEach(k => {
                if (rt[k] && rt[k].source === 'env') envKeys.push(k);
            });
            if (hintEl) {
                let t = d.hint || '';
                if (envKeys.length) {
                    t = 'Hay valores definidos por variable de entorno (' + envKeys.join(', ') + ') que pisan la base de datos. ' + t;
                }
                hintEl.textContent = t || 'Los valores se guardan en la tabla configuracion (salvo override por entorno).';
            }
            const st = d.status || {};
            if (qSum) {
                const p = st.worker_orders_pending != null ? st.worker_orders_pending : 0;
                const r = st.worker_orders_running != null ? st.worker_orders_running : 0;
                qSum.textContent = `Órdenes worker: ${p} pendientes, ${r} en ejecución.`;
            }
            const runs = Array.isArray(st.last_runs) ? st.last_runs : [];

            // Banner de diagnóstico automático basado en la última corrida
            const diagHint = document.getElementById('pacsClonerWorkerDiagHint');
            const diagText = document.getElementById('pacsClonerWorkerDiagText');
            if (diagHint && diagText && runs.length > 0) {
                const lastRun = runs[0];
                const notes = String(lastRun.notes || '');
                const diagParts = [];
                const parseNote = (key) => {
                    const m = notes.match(new RegExp(key + '=(\\d+)'));
                    return m ? parseInt(m[1], 10) : 0;
                };
                const activeJob = parseNote('active_job');
                const defer = parseNote('defer');
                const discovered = parseNote('discovered');
                const queued = parseNote('dispatched');
                if (activeJob > 0) {
                    diagParts.push(`⚠ ${activeJob} estudio(s) bloqueados por jobs activos en BD. Baje "Timeout pending" y "Timeout running" para liberarlos más rápido en la próxima corrida.`);
                }
                if (defer > 3 && queued === 0) {
                    diagParts.push(`⚠ ${defer} estudio(s) en defer y ninguno encolado. Posible problema de C-FIND sin conteo de instancias o estudios que llevan solo 1 corrida.`);
                }
                if (discovered === 0) {
                    diagParts.push('⚠ Última corrida no descubrió estudios. Verifique conectividad DIMSE con el nodo remoto y el rango de fechas de la política.');
                }
                if (diagParts.length > 0) {
                    diagText.textContent = diagParts.join(' | ');
                    diagHint.style.display = '';
                } else {
                    diagHint.style.display = 'none';
                }
            } else if (diagHint) {
                diagHint.style.display = 'none';
            }

            if (runsBody) {
                if (runs.length === 0) {
                    runsBody.innerHTML = '<tr><td colspan="6" class="text-muted">Sin ejecuciones registradas aún (tabla pacs_cloner_worker_runs).</td></tr>';
                } else {
                    runsBody.innerHTML = runs.map(row => {
                        const n = (x) => (x != null && String(x) !== '') ? this.escapeHtml(String(x)) : '—';
                        return `<tr>
                            <td>${n(row.started_at)}</td>
                            <td>${n(row.finished_at)}</td>
                            <td>${n(row.policies_touched)}</td>
                            <td>${n(row.studies_discovered)}</td>
                            <td>${n(row.studies_queued)}</td>
                            <td class="small text-break">${n(row.notes)}</td>
                        </tr>`;
                    }).join('');
                }
            }
            const orders = Array.isArray(st.recent_worker_orders) ? st.recent_worker_orders : [];
            if (ordBody) {
                if (orders.length === 0) {
                    ordBody.innerHTML = '<tr><td colspan="5" class="text-muted">Sin órdenes worker recientes.</td></tr>';
                } else {
                    ordBody.innerHTML = orders.map(o => {
                        const oid = this.escapeHtml(String(o.id || ''));
                        const nn = this.escapeHtml(o.node_name || '');
                        const stt = this.escapeHtml(o.status || '');
                        const lb = this.escapeHtml((o.label || '').slice(0, 80));
                        const cr = o.created_at ? this.escapeHtml(String(o.created_at)) : '—';
                        return `<tr><td>${oid}</td><td>${nn}</td><td><span class="badge bg-secondary">${stt}</span></td><td class="small">${lb}</td><td class="small">${cr}</td></tr>`;
                    }).join('');
                }
            }
        } catch (err) {
            console.warn('[pacsCloner][worker_console]', err);
            if (hintEl) hintEl.textContent = 'Error de red al cargar la consola del worker.';
        }
    },

    savePacsClonerWorkerConsole: async function() {
        const v1 = document.getElementById('pacsClonerWorkerV1Enabled');
        const v2 = document.getElementById('pacsClonerWorkerV2Enabled');
        if (!v1 || !v2) return;
        const gi = (id) => {
            const el = document.getElementById(id);
            return el ? parseInt(el.value, 10) : NaN;
        };
        const gs = (id) => {
            const el = document.getElementById(id);
            return el ? String(el.value || '') : '';
        };
        const intOr = (id, fallback) => {
            const v = gi(id);
            return Number.isNaN(v) ? fallback : v;
        };
        try {
            const response = await fetch(`${this.apiBase}/cloner.php`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_worker_console',
                    v1_enabled: !!v1.checked,
                    v2_enabled: !!v2.checked,
                    max_per_run: intOr('pacsClonerRuntimeMaxPerRun', 25),
                    max_per_policy: intOr('pacsClonerRuntimeMaxPerPolicy', 25),
                    dispatch_poll_sec: intOr('pacsClonerRuntimeDispatchPoll', 5),
                    dispatch_max_sec: intOr('pacsClonerRuntimeDispatchMaxSec', 900),
                    dispatch_max_starts: intOr('pacsClonerRuntimeDispatchMaxStarts', 200),
                    reconcile_jobs_limit: intOr('pacsClonerRuntimeReconcileLimit', 200),
                    stale_pending_minutes: intOr('pacsClonerRuntimeStalePendingMin', 90),
                    stale_running_hours: intOr('pacsClonerRuntimeStaleRunningH', 6),
                    modality_priority_global: gs('pacsClonerRuntimeModalityGlobal'),
                    reconcile_jobs_enabled: !!(document.getElementById('pacsClonerRuntimeReconcileEnabled') || {}).checked
                })
            });
            const data = await response.json();
            if (data.success) {
                this.showSuccess(data.message || 'Guardado');
                await this.loadPacsClonerWorkerConsole();
            } else {
                this.showError(data.error || 'No se pudo guardar');
            }
        } catch (err) {
            this.showError(err.message || 'Error de red');
        }
    },

    loadPacsClonerWorkerFlags: async function() {
        await this.loadPacsClonerWorkerConsole();
    },

    savePacsClonerWorkerFlags: async function() {
        await this.savePacsClonerWorkerConsole();
    },

    unlockStaleJobs: async function() {
        const btn = document.getElementById('btnPacsClonerUnlockStaleJobs');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Liberando…'; }
        try {
            const res = await fetch(`${this.apiBase}/cloner.php`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'unlock_stale_jobs' })
            });
            const data = await res.json();
            if (data.success) {
                const d = data.data || {};
                const total = (d.pending_closed || 0) + (d.running_closed || 0);
                if (total > 0) {
                    this.showSuccess(
                        `${total} job(s) bloqueante(s) cerrado(s) ` +
                        `(pending: ${d.pending_closed || 0}, running: ${d.running_closed || 0}, reconciliados: ${d.reconciled || 0}). ` +
                        `El worker los redescubrirá en la próxima corrida.`
                    );
                } else {
                    this.showSuccess('Sin jobs bloqueantes en este momento. El worker está al día.');
                }
                await this.loadPacsClonerWorkerConsole();
            } else {
                this.showError(data.error || 'No se pudo ejecutar la liberación');
            }
        } catch (e) {
            this.showError('Error de red al liberar bloqueos: ' + e.message);
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-unlock-alt me-1"></i> Liberar bloqueos ahora'; }
        }
    },

    getPacsClonerHelpLibrary: function() {
        return (typeof PACS_CLONER_HELP_ES !== 'undefined' && PACS_CLONER_HELP_ES) ? PACS_CLONER_HELP_ES : {};
    },

    showPacsClonerHelpModal: function(helpId) {
        const lib = this.getPacsClonerHelpLibrary();
        const entry = lib[helpId];
        const titleEl = document.getElementById('pacsClonerHelpModalTitle');
        const bodyEl = document.getElementById('pacsClonerHelpModalBody');
        const modalEl = document.getElementById('pacsClonerHelpModal');
        if (!titleEl || !bodyEl) return;
        if (!entry) {
            titleEl.textContent = 'Ayuda';
            bodyEl.innerHTML = '<p class="text-muted mb-0">No hay ayuda definida para esta opción.</p>';
        } else {
            titleEl.textContent = entry.title;
            bodyEl.innerHTML = entry.html;
        }
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            const t = entry ? entry.title : 'Ayuda';
            const plain = bodyEl.textContent || '';
            window.alert(t + (plain ? '\n\n' + plain : ''));
            return;
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    },

    setupPacsClonerHelpDelegation: function() {
        const root = document.getElementById('pacsCloner');
        if (!root || root.dataset.pacsClonerHelpBound === '1') return;
        root.dataset.pacsClonerHelpBound = '1';
        root.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.pacs-cloner-help-btn');
            if (!btn) return;
            ev.preventDefault();
            const id = btn.getAttribute('data-pacs-cloner-help');
            if (id) {
                PacsNodesManager.showPacsClonerHelpModal(id);
            }
        });
    },
    
    setupPacsClonerPanel: function() {
        const form = document.getElementById('pacsClonerPolicyForm');
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const name = (document.getElementById('pacsClonerPolicyName') || {}).value || '';
                const nodeId = (document.getElementById('pacsClonerPolicyNode') || {}).value || '';
                const policyIdRaw = (document.getElementById('pacsClonerPolicyId') || {}).value || '';
                const policyId = policyIdRaw ? parseInt(policyIdRaw, 10) : 0;
                const df = (document.getElementById('pacsClonerPolicyDateFrom') || {}).value || '';
                const dt = (document.getElementById('pacsClonerPolicyDateTo') || {}).value || '';
                const body = {
                    action: policyId > 0 ? 'update_policy' : 'create_policy',
                    name: name.trim(),
                    node_id: parseInt(nodeId, 10),
                    is_enabled: document.getElementById('pacsClonerPolicyEnabled')?.checked || false,
                    mode: (document.getElementById('pacsClonerPolicyMode') || {}).value || 'paused',
                    scan_window_hours: parseInt((document.getElementById('pacsClonerPolicyScanHours') || {}).value, 10) || 24,
                    max_concurrent: parseInt((document.getElementById('pacsClonerPolicyMaxConc') || {}).value, 10) || 2,
                    modality_filter: (document.getElementById('pacsClonerPolicyModality') || {}).value || '',
                    modality_priority: (document.getElementById('pacsClonerPolicyModalityPriority') || {}).value || '',
                    discovery_sort: (document.getElementById('pacsClonerPolicyDiscoverySort') || {}).value || 'modality',
                    alignment_strategy: (document.getElementById('pacsClonerPolicyAlignment') || {}).value || 'study',
                    notes: (document.getElementById('pacsClonerPolicyNotes') || {}).value || ''
                };
                if (policyId > 0) {
                    body.id = policyId;
                }
                if (df.trim()) {
                    body.date_from = df.trim();
                }
                if (dt.trim()) {
                    body.date_to = dt.trim();
                }
                try {
                    const response = await fetch(`${this.apiBase}/cloner.php`, {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body)
                    });
                    const data = await response.json();
                    if (data.success) {
                        this.showSuccess(policyId > 0 ? 'Política actualizada' : 'Política guardada');
                        this.clearPacsClonerPolicyForm();
                        await this.loadPacsClonerPolicies();
                        this.populatePacsClonerNodeSelects();
                    } else {
                        this.showError(data.error || 'No se pudo guardar');
                    }
                } catch (err) {
                    console.error(err);
                    this.showError('Error de red al guardar política');
                }
            });
        }
        document.getElementById('btnPacsClonerPolicyCancelEdit')?.addEventListener('click', () => {
            this.clearPacsClonerPolicyForm();
            this.populatePacsClonerNodeSelects();
        });
        document.getElementById('btnPacsClonerRefreshPolicies')?.addEventListener('click', () => this.loadPacsClonerPolicies());
        document.getElementById('btnPacsClonerRefreshOrders')?.addEventListener('click', () => this.loadPacsClonerOrders());
        document.getElementById('btnPacsClonerWorkerFlagsSave')?.addEventListener('click', () => this.savePacsClonerWorkerConsole());
        document.getElementById('btnPacsClonerWorkerConsoleRefresh')?.addEventListener('click', () => this.loadPacsClonerWorkerConsole());
        document.getElementById('btnPacsClonerUnlockStaleJobs')?.addEventListener('click', () => this.unlockStaleJobs());
        document.getElementById('btnPacsClonerDispatch')?.addEventListener('click', async () => {
            const nodeId = (document.getElementById('pacsClonerDispatchNode') || {}).value || '';
            const raw = (document.getElementById('pacsClonerDispatchUids') || {}).value || '';
            const label = (document.getElementById('pacsClonerDispatchLabel') || {}).value || '';
            const parts = raw.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean);
            if (!nodeId || parts.length === 0) {
                this.showError('Seleccione nodo e ingrese al menos un UID');
                return;
            }
            const btn = document.getElementById('btnPacsClonerDispatch');
            if (btn) btn.disabled = true;
            try {
                const response = await fetch(`${this.apiBase}/cloner.php`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'dispatch',
                        node_id: parseInt(nodeId, 10),
                        study_instance_uids: parts,
                        label: label.trim() || undefined,
                        alignment_strategy: (document.getElementById('pacsClonerDispatchAlignment') || {}).value || 'study'
                    })
                });
                const data = await response.json();
                if (data.success) {
                    this.showSuccess('Recuperación iniciada (ver Jobs)');
                    await this.loadPacsClonerOrders();
                } else {
                    this.showError(data.error || 'No se pudo iniciar');
                }
            } catch (err) {
                console.error(err);
                this.showError('Error de red');
            } finally {
                if (btn) btn.disabled = false;
            }
        });
        this.setupPacsClonerHelpDelegation();
    },
    
    /**
     * Restaurar pestaña activa
     */
    restoreActiveTab: function() {
        // Cross Sync: columna de filtros colapsada (misma idea que Buscar)
        if (this.activeTab === 'crossSync' && this.savedCrossSyncFiltersCollapsed !== undefined) {
            const crossSyncRow = document.getElementById('crossSyncRow');
            if (crossSyncRow) {
                if (this.savedCrossSyncFiltersCollapsed) {
                    crossSyncRow.classList.add('filters-collapsed');
                } else {
                    crossSyncRow.classList.remove('filters-collapsed');
                }
            }
        }
        
        // Si es la pestaña de búsqueda, aplicar estado de filtros ANTES de mostrar la pestaña
        if (this.activeTab === 'search' && this.savedFiltersCollapsed !== undefined) {
            // Aplicar estado inmediatamente si el elemento existe
            const searchRow = document.getElementById('searchRow');
            if (searchRow) {
                if (this.savedFiltersCollapsed) {
                    searchRow.classList.add('filters-collapsed');
                    console.log('✅ Estado de filtros aplicado ANTES de mostrar pestaña: colapsado');
                } else {
                    searchRow.classList.remove('filters-collapsed');
                    console.log('✅ Estado de filtros aplicado ANTES de mostrar pestaña: visible');
                }
            }
        }
        
        // Esperar a que Bootstrap esté listo y el DOM esté completamente cargado
        setTimeout(() => {
            if (this.activeTab && this.activeTab !== 'nodes') {
                const tabButton = document.querySelector(`#mainTabs button[data-bs-target="#${this.activeTab}"]`);
                if (tabButton) {
                    // Remover active de todas las tabs
                    document.querySelectorAll('#mainTabs .nav-link').forEach(link => {
                        link.classList.remove('active');
                    });
                    document.querySelectorAll('.tab-pane').forEach(pane => {
                        pane.classList.remove('show', 'active');
                    });
                    
                    // Si es la pestaña de búsqueda, asegurar que el estado de filtros esté aplicado antes de mostrar
                    if (this.activeTab === 'search' && this.savedFiltersCollapsed !== undefined) {
                        const searchRow = document.getElementById('searchRow');
                        if (searchRow) {
                            if (this.savedFiltersCollapsed) {
                                searchRow.classList.add('filters-collapsed');
                            } else {
                                searchRow.classList.remove('filters-collapsed');
                            }
                        }
                    }
                    
                    if (this.activeTab === 'crossSync' && this.savedCrossSyncFiltersCollapsed !== undefined) {
                        const crossSyncRow = document.getElementById('crossSyncRow');
                        if (crossSyncRow) {
                            if (this.savedCrossSyncFiltersCollapsed) {
                                crossSyncRow.classList.add('filters-collapsed');
                            } else {
                                crossSyncRow.classList.remove('filters-collapsed');
                            }
                        }
                    }
                    
                    // Activar la pestaña guardada
                    tabButton.classList.add('active');
                    const targetPane = document.querySelector(`#${this.activeTab}`);
                    if (targetPane) {
                        targetPane.classList.add('show', 'active');
                    }
                    
                    console.log('✅ Pestaña restaurada:', this.activeTab);
                    
                    // Actualizar visibilidad del botón "Nuevo Nodo"
                    this.updateNewNodeButtonVisibility();
                    
                    // Si es la pestaña de búsqueda, restaurar filtros y resultados
                    if (this.activeTab === 'search') {
                        setTimeout(async () => {
                            await this.restoreSearchFilters();
                            this.restoreSearchResults();
                            // Verificar que el estado de filtros esté aplicado (ya debería estar, pero por si acaso)
                            setTimeout(() => {
                                this.restoreFiltersState();
                            }, 100);
                        }, 200);
                    }
                    if (this.activeTab === 'dashboard') {
                        setTimeout(() => this.loadDashboard(), 150);
                    }
                    if (this.activeTab === 'crossSync') {
                        setTimeout(() => {
                            if (this.savedCrossSyncFiltersCollapsed !== undefined) {
                                delete this.savedCrossSyncFiltersCollapsed;
                            }
                            this.refreshCrossSyncNodeList();
                            this.applyCrossSyncSavedFiltersToInputs();
                            const loc = document.getElementById('crossSyncLocalSearchFilter');
                            if (loc) loc.value = this.crossSyncLocalSearchTerm || '';
                            this.updateCrossSyncLocalSearchChrome();
                            this.refreshCrossSyncActiveJobsFromApi().then(() => {
                                if (this.crossSyncComparison) {
                                    this.renderCrossSyncTable();
                                }
                            });
                            this.renderReplicationInsights();
                            this.loadReplicationInsights().catch(err => console.warn('[replicationInsights][restore-tab]', err));
                        }, 150);
                    }
                    if (this.activeTab === 'pacsCloner') {
                        setTimeout(() => {
                            this.refreshPacsClonerTab().catch(err => console.warn('[pacsCloner][restore-tab]', err));
                        }, 150);
                    }
                }
            }
        }, 200);
    },
    
    /**
     * Restaurar resultados de búsqueda (llamado después de restaurar la pestaña)
     */
    restoreSearchResults: function() {
        const hasTabData = this.nodeSearchTabs && Object.keys(this.nodeSearchTabs).length > 0;
        if ((!this.currentSearchResults || this.currentSearchResults.length === 0) && !hasTabData) {
            return;
        }
        
        this.rebuildNodeTabsNavFromState();
        if (this.activeNodeResultTabId && this.nodeSearchTabs && this.nodeSearchTabs[String(this.activeNodeResultTabId)]) {
            this.syncTabStateToGlobals(this.activeNodeResultTabId);
        }
        
        // Actualizar botones de modalidad con las modalidades disponibles
        this.updateModalityButtons();
        
        // Aplicar filtros (búsqueda local + modalidades)
        this.applyLocalSearchFilter();
        
        this.updateResultsCountDisplay();
        
        // Actualizar totales de paginación
        if (this.resultsPagination) {
            if (!this.resultsPagination.totalItems || this.resultsPagination.totalItems === 0) {
                this.resultsPagination.totalItems = this.currentSearchResults.length;
            }
        }
        
        // Actualizar iconos de ordenamiento
        this.updateSortIcons();
        
        // Renderizar resultados
        this.renderSearchResults();
        
        console.log('✅ Resultados de búsqueda renderizados:', this.currentSearchResults.length);
    },
    
    /**
     * Restaurar filtros de búsqueda (llamado después de restaurar la pestaña)
     */
    restoreSearchFilters: async function() {
        if (!this.savedSearchFilters) {
            return;
        }
        
        const filters = this.savedSearchFilters;
        
        // Primero, asegurarse de que los nodos estén cargados en el selector
        const nodeSelect = document.getElementById('searchNodeSelect');
        if (nodeSelect && filters.nodeId) {
            // Si el selector está vacío o no tiene la opción, cargar nodos primero
            if (nodeSelect.options.length <= 1 || !Array.from(nodeSelect.options).some(opt => opt.value === filters.nodeId)) {
                await this.loadNodesForSearch();
            }
            
            // Esperar un momento para que el DOM se actualice
            await new Promise(resolve => setTimeout(resolve, 50));
            
            // Ahora restaurar el valor
            const optionExists = Array.from(nodeSelect.options).some(opt => opt.value === filters.nodeId);
            if (optionExists) {
                nodeSelect.value = filters.nodeId;
                console.log('✅ Nodo restaurado:', filters.nodeId);
            } else {
                console.warn('⚠️ Nodo guardado no encontrado en la lista:', filters.nodeId);
            }
        }
        
        // Restaurar otros filtros
        if (filters.patientID && document.getElementById('searchPatientID')) {
            document.getElementById('searchPatientID').value = filters.patientID;
        }
        if (filters.patientName && document.getElementById('searchPatientName')) {
            document.getElementById('searchPatientName').value = filters.patientName;
        }
        if (filters.dateFrom && document.getElementById('searchDateFrom')) {
            document.getElementById('searchDateFrom').value = filters.dateFrom;
        }
        if (filters.dateTo && document.getElementById('searchDateTo')) {
            document.getElementById('searchDateTo').value = filters.dateTo;
        }
        if (filters.modality && document.getElementById('searchModality')) {
            document.getElementById('searchModality').value = filters.modality;
        }
        if (filters.accessionNumber && document.getElementById('searchAccessionNumber')) {
            document.getElementById('searchAccessionNumber').value = filters.accessionNumber;
        }
        
        console.log('✅ Filtros de búsqueda restaurados');
    },
    
    /**
     * Restaurar estado de filtros (colapsado o no)
     */
    restoreFiltersState: function() {
        if (this.savedFiltersCollapsed === undefined) {
            console.log('ℹ️ No hay estado de filtros guardado para restaurar');
            return;
        }
        
        const searchRow = document.getElementById('searchRow');
        if (!searchRow) {
            console.warn('⚠️ Elemento searchRow no encontrado, reintentando...');
            // Reintentar después de un breve delay
            setTimeout(() => {
                this.restoreFiltersState();
            }, 300);
            return;
        }
        
        // Verificar que la pestaña de búsqueda esté visible
        const searchTab = document.getElementById('search');
        if (!searchTab || !searchTab.classList.contains('active')) {
            console.log('ℹ️ Pestaña de búsqueda no está activa, esperando...');
            // Esperar a que la pestaña esté activa
            setTimeout(() => {
                this.restoreFiltersState();
            }, 200);
            return;
        }
        
        // Restaurar estado de filtros
        if (this.savedFiltersCollapsed) {
            searchRow.classList.add('filters-collapsed');
            console.log('✅ Estado de filtros restaurado: colapsado');
        } else {
            searchRow.classList.remove('filters-collapsed');
            console.log('✅ Estado de filtros restaurado: visible');
        }
        
        // Limpiar variable temporal después de usarla
        const wasCollapsed = this.savedFiltersCollapsed;
        delete this.savedFiltersCollapsed;
        
        // Verificar que el estado se aplicó correctamente
        setTimeout(() => {
            const isCollapsed = searchRow.classList.contains('filters-collapsed');
            if (wasCollapsed !== isCollapsed) {
                console.warn('⚠️ El estado de filtros no se aplicó correctamente, reintentando...');
                if (wasCollapsed) {
                    searchRow.classList.add('filters-collapsed');
                } else {
                    searchRow.classList.remove('filters-collapsed');
                }
            }
        }, 100);
    },
    
    /**
     * Guardar estado persistente
     */
    savePersistentState: function() {
        try {
            this.persistCurrentTabState();
            // Obtener valores de los filtros de búsqueda
            const searchFilters = {
                nodeId: document.getElementById('searchNodeSelect')?.value || '',
                patientID: document.getElementById('searchPatientID')?.value || '',
                patientName: document.getElementById('searchPatientName')?.value || '',
                dateFrom: document.getElementById('searchDateFrom')?.value || '',
                dateTo: document.getElementById('searchDateTo')?.value || '',
                modality: document.getElementById('searchModality')?.value || '',
                accessionNumber: document.getElementById('searchAccessionNumber')?.value || ''
            };
            
            // Obtener estudio seleccionado
            const selectedRow = document.querySelector('#resultsTableBody tr.selected');
            const selectedStudyUID = selectedRow ? selectedRow.getAttribute('data-study-uid') : null;
            
            // Obtener estado de los filtros (colapsado o no)
            const searchRow = document.getElementById('searchRow');
            const filtersCollapsed = searchRow ? searchRow.classList.contains('filters-collapsed') : false;
            console.log('💾 Guardando estado de filtros:', filtersCollapsed ? 'colapsado' : 'visible');
            
            const crossSyncNodeIds = Array.from(document.querySelectorAll('.cross-sync-node-cb:checked')).map(cb => String(cb.value));
            const crossSyncFilters = {
                patientID: document.getElementById('crossSyncPatientID')?.value || '',
                patientName: document.getElementById('crossSyncPatientName')?.value || '',
                dateFrom: document.getElementById('crossSyncDateFrom')?.value || '',
                dateTo: document.getElementById('crossSyncDateTo')?.value || '',
                modality: document.getElementById('crossSyncModality')?.value || '',
                accessionNumber: document.getElementById('crossSyncAccessionNumber')?.value || '',
                diffOnly: !!document.getElementById('crossSyncFilterDiffOnly')?.checked,
                treatExtrasAligned: document.getElementById('crossSyncTreatExtrasAligned')?.checked !== false,
                resultStatus: this.getCrossSyncResultStatusFilter(),
                listModalities: Array.isArray(this.crossSyncListModalities) ? [...this.crossSyncListModalities] : []
            };
            
            const crossSyncRowEl = document.getElementById('crossSyncRow');
            const crossSyncFiltersCollapsed = crossSyncRowEl ? crossSyncRowEl.classList.contains('filters-collapsed') : false;
            const locFilterEl = document.getElementById('crossSyncLocalSearchFilter');
            const crossSyncLocalRaw = locFilterEl ? locFilterEl.value.trim().toLowerCase() : (this.crossSyncLocalSearchTerm || '').trim().toLowerCase();
            
            const state = {
                activeTab: this.activeTab,
                searchFilters: searchFilters,
                searchResults: this.currentSearchResults || [],
                selectedModalities: this.selectedModalities || [],
                sortConfig: { ...this.sortConfig },
                pagination: this.resultsPagination ? {
                    currentPage: this.resultsPagination.currentPage,
                    perPage: this.resultsPagination.perPage,
                    totalItems: this.resultsPagination.totalItems
                } : null,
                nodeSearchTabs: this.nodeSearchTabs || {},
                activeNodeResultTabId: this.activeNodeResultTabId,
                jobsFilter: document.getElementById('jobsFilterStatus')?.value || '',
                selectedStudyUID: selectedStudyUID,
                filtersCollapsed: filtersCollapsed, // Guardar estado de filtros
                crossSyncFilters: crossSyncFilters,
                crossSyncNodeIds: crossSyncNodeIds,
                crossSyncFiltersCollapsed: crossSyncFiltersCollapsed,
                crossSyncLocalSearchTerm: crossSyncLocalRaw,
                crossSyncComparison: this.crossSyncComparison || null,
                crossSyncSortConfig: { ...this.crossSyncSortConfig },
                replicationInsights: {
                    days: this.replicationInsights.days || 7,
                    limit: this.replicationInsights.limit || 5,
                    hidden: !!this.replicationInsights.hidden
                },
                timestamp: Date.now()
            };
            
            const writeState = (s) => localStorage.setItem(this.storageKey, JSON.stringify(s));
            try {
                writeState(state);
            } catch (quotaErr) {
                console.warn('[PACS Nodes] Persistencia: reduciendo Cross Sync (cuota o tamaño)', quotaErr);
                try {
                    writeState({
                        ...state,
                        crossSyncComparison: this.cloneCrossSyncForStorage(this.crossSyncComparison, 1200)
                    });
                } catch (e2) {
                    try {
                        writeState({ ...state, crossSyncComparison: null });
                    } catch (e3) {
                        throw quotaErr;
                    }
                }
            }
            console.log('💾 Estado persistente guardado:', state.searchResults.length, 'resultados');
        } catch (error) {
            console.warn('Error guardando estado persistente:', error);
            // Si localStorage está lleno, guardar sin resultados
            try {
                const searchFilters = {
                    nodeId: document.getElementById('searchNodeSelect')?.value || '',
                    patientID: document.getElementById('searchPatientID')?.value || '',
                    patientName: document.getElementById('searchPatientName')?.value || '',
                    dateFrom: document.getElementById('searchDateFrom')?.value || '',
                    dateTo: document.getElementById('searchDateTo')?.value || '',
                    modality: document.getElementById('searchModality')?.value || '',
                    accessionNumber: document.getElementById('searchAccessionNumber')?.value || ''
                };
                // Obtener estado de los filtros (colapsado o no)
                const searchRow = document.getElementById('searchRow');
                const filtersCollapsed = searchRow ? searchRow.classList.contains('filters-collapsed') : false;
                
                const crossSyncNodeIdsMin = Array.from(document.querySelectorAll('.cross-sync-node-cb:checked')).map(cb => String(cb.value));
                const crossSyncFiltersMin = {
                    patientID: document.getElementById('crossSyncPatientID')?.value || '',
                    patientName: document.getElementById('crossSyncPatientName')?.value || '',
                    dateFrom: document.getElementById('crossSyncDateFrom')?.value || '',
                    dateTo: document.getElementById('crossSyncDateTo')?.value || '',
                    modality: document.getElementById('crossSyncModality')?.value || '',
                    accessionNumber: document.getElementById('crossSyncAccessionNumber')?.value || '',
                    diffOnly: !!document.getElementById('crossSyncFilterDiffOnly')?.checked,
                    treatExtrasAligned: document.getElementById('crossSyncTreatExtrasAligned')?.checked !== false,
                    resultStatus: this.getCrossSyncResultStatusFilter(),
                    listModalities: Array.isArray(this.crossSyncListModalities) ? [...this.crossSyncListModalities] : []
                };
                const crossSyncRowMin = document.getElementById('crossSyncRow');
                const crossSyncFiltersCollapsedMin = crossSyncRowMin ? crossSyncRowMin.classList.contains('filters-collapsed') : false;
                const locMin = document.getElementById('crossSyncLocalSearchFilter');
                const crossSyncLocalMin = locMin ? locMin.value.trim().toLowerCase() : (this.crossSyncLocalSearchTerm || '').trim().toLowerCase();
                const stateMinimal = {
                    activeTab: this.activeTab,
                    searchFilters: searchFilters,
                    searchResults: [],
                    sortConfig: { ...this.sortConfig },
                    pagination: this.resultsPagination ? {
                        currentPage: this.resultsPagination.currentPage,
                        perPage: this.resultsPagination.perPage,
                        totalItems: 0
                    } : null,
                    nodeSearchTabs: {},
                    activeNodeResultTabId: null,
                    jobsFilter: document.getElementById('jobsFilterStatus')?.value || '',
                    filtersCollapsed: filtersCollapsed, // Guardar estado de filtros
                    crossSyncFilters: crossSyncFiltersMin,
                    crossSyncNodeIds: crossSyncNodeIdsMin,
                    crossSyncFiltersCollapsed: crossSyncFiltersCollapsedMin,
                    crossSyncLocalSearchTerm: crossSyncLocalMin,
                    crossSyncComparison: this.cloneCrossSyncForStorage(this.crossSyncComparison, 400),
                    crossSyncSortConfig: { ...this.crossSyncSortConfig },
                    replicationInsights: {
                        days: this.replicationInsights.days || 7,
                        limit: this.replicationInsights.limit || 5,
                        hidden: !!this.replicationInsights.hidden
                    },
                    timestamp: Date.now()
                };
                localStorage.setItem(this.storageKey, JSON.stringify(stateMinimal));
            } catch (e) {
                console.warn('Error al guardar estado mínimo:', e);
            }
        }
    },
    
    /**
     * Cargar estado persistente
     */
    loadPersistentState: function() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            if (!stored) {
                return;
            }
            
            const state = JSON.parse(stored);
            
            // Restaurar pestaña activa
            if (state.activeTab) {
                this.activeTab = state.activeTab;
            }
            
            // Restaurar configuración de ordenamiento
            if (state.sortConfig) {
                this.sortConfig = { ...this.sortConfig, ...state.sortConfig };
            }
            
            // Restaurar pestañas de resultados por nodo (preferido)
            if (state.nodeSearchTabs && typeof state.nodeSearchTabs === 'object') {
                this.nodeSearchTabs = state.nodeSearchTabs;
            }
            if (state.activeNodeResultTabId !== undefined && state.activeNodeResultTabId !== null) {
                this.activeNodeResultTabId = state.activeNodeResultTabId;
            }
            
            // Compatibilidad: estado antiguo sin nodeSearchTabs
            if ((!this.nodeSearchTabs || Object.keys(this.nodeSearchTabs).length === 0) &&
                state.searchResults && state.searchResults.length > 0 &&
                state.searchFilters && state.searchFilters.nodeId) {
                const nid = String(state.searchFilters.nodeId);
                this.nodeSearchTabs = {
                    [nid]: {
                        nodeName: 'Nodo',
                        currentSearchResults: state.searchResults,
                        filteredSearchResults: [...state.searchResults],
                        localSearchTerm: '',
                        selectedModalities: state.selectedModalities && Array.isArray(state.selectedModalities) ? [...state.selectedModalities] : [],
                        sortConfig: state.sortConfig ? { ...state.sortConfig } : { column: null, direction: 'asc' },
                        resultsPagination: state.pagination ? {
                            currentPage: state.pagination.currentPage || 1,
                            perPage: state.pagination.perPage,
                            totalItems: state.pagination.totalItems || state.searchResults.length
                        } : { currentPage: 1, perPage: 25, totalItems: state.searchResults.length },
                        apiCount: state.searchResults.length
                    }
                };
                this.activeNodeResultTabId = nid;
            }
            
            // Restaurar paginación (puede ser sobreescrita por syncTabStateToGlobals)
            if (state.pagination && state.pagination.perPage) {
                if (!this.resultsPagination) {
                    this.resultsPagination = {
                        currentPage: state.pagination.currentPage || 1,
                        perPage: state.pagination.perPage,
                        totalItems: state.pagination.totalItems || 0
                    };
                } else {
                    this.resultsPagination.currentPage = state.pagination.currentPage || 1;
                    this.resultsPagination.perPage = state.pagination.perPage;
                    this.resultsPagination.totalItems = state.pagination.totalItems || 0;
                }
                const perPageSelect = document.getElementById('resultsPerPageSelect');
                if (perPageSelect) {
                    perPageSelect.value = state.pagination.perPage;
                }
            }
            
            if (this.activeNodeResultTabId && this.nodeSearchTabs && this.nodeSearchTabs[String(this.activeNodeResultTabId)]) {
                this.syncTabStateToGlobals(this.activeNodeResultTabId);
                console.log('📦 Resultados restaurados desde pestaña nodo:', this.activeNodeResultTabId);
            } else if (state.searchResults && state.searchResults.length > 0) {
                this.currentSearchResults = state.searchResults;
                console.log('📦 Resultados de búsqueda restaurados:', this.currentSearchResults.length);
            }
            
            if (!(this.activeNodeResultTabId && this.nodeSearchTabs && this.nodeSearchTabs[String(this.activeNodeResultTabId)])) {
                if (state.selectedModalities && Array.isArray(state.selectedModalities)) {
                    this.selectedModalities = state.selectedModalities;
                }
            }
            
            // Restaurar filtros de búsqueda (se restaurarán cuando se active la pestaña)
            // Guardamos los filtros en una variable temporal para restaurarlos después
            if (state.searchFilters) {
                this.savedSearchFilters = state.searchFilters;
            }
            
            // Restaurar filtro de jobs
            if (state.jobsFilter && document.getElementById('jobsFilterStatus')) {
                document.getElementById('jobsFilterStatus').value = state.jobsFilter;
            }
            
            // Guardar selectedStudyUID para restaurarlo después de renderizar
            if (state.selectedStudyUID) {
                this.savedSelectedStudyUID = state.selectedStudyUID;
            }
            
            // Guardar estado de filtros para restaurarlo después
            if (state.filtersCollapsed !== undefined) {
                this.savedFiltersCollapsed = state.filtersCollapsed;
                
                // Aplicar estado inmediatamente si el elemento existe (para evitar flash visual)
                const searchRow = document.getElementById('searchRow');
                if (searchRow) {
                    if (state.filtersCollapsed) {
                        searchRow.classList.add('filters-collapsed');
                        console.log('✅ Estado de filtros aplicado inmediatamente: colapsado');
                    } else {
                        searchRow.classList.remove('filters-collapsed');
                        console.log('✅ Estado de filtros aplicado inmediatamente: visible');
                    }
                }
            }
            
            if (state.crossSyncFilters && typeof state.crossSyncFilters === 'object') {
                this.savedCrossSyncFilters = state.crossSyncFilters;
            }
            if (state.crossSyncNodeIds && Array.isArray(state.crossSyncNodeIds)) {
                this.savedCrossSyncNodeIds = state.crossSyncNodeIds.map(String);
            }
            
            if (state.crossSyncFiltersCollapsed !== undefined) {
                this.savedCrossSyncFiltersCollapsed = !!state.crossSyncFiltersCollapsed;
            }
            if (state.crossSyncLocalSearchTerm !== undefined && state.crossSyncLocalSearchTerm !== null) {
                this.crossSyncLocalSearchTerm = String(state.crossSyncLocalSearchTerm).trim().toLowerCase();
            }
            if (state.crossSyncFilters && Array.isArray(state.crossSyncFilters.listModalities)) {
                this.crossSyncListModalities = state.crossSyncFilters.listModalities.map(m => String(m).toUpperCase()).filter(Boolean);
            }
            
            if (state.crossSyncSortConfig && typeof state.crossSyncSortConfig === 'object') {
                let col = state.crossSyncSortConfig.column || null;
                if (col === 'study_uid') col = null;
                this.crossSyncSortConfig = {
                    column: col,
                    direction: state.crossSyncSortConfig.direction === 'desc' ? 'desc' : 'asc'
                };
            }
            if (state.crossSyncComparison && state.crossSyncComparison.rows && Array.isArray(state.crossSyncComparison.rows)) {
                this.crossSyncComparison = state.crossSyncComparison;
            }
            if (state.replicationInsights && typeof state.replicationInsights === 'object') {
                const d = parseInt(state.replicationInsights.days, 10);
                const l = parseInt(state.replicationInsights.limit, 10);
                if (Number.isFinite(d) && d >= 1 && d <= 30) this.replicationInsights.days = d;
                if (Number.isFinite(l) && l >= 1 && l <= 20) this.replicationInsights.limit = l;
                if (Object.prototype.hasOwnProperty.call(state.replicationInsights, 'hidden')) {
                    this.replicationInsights.hidden = !!state.replicationInsights.hidden;
                }
            }
            
            console.log('✅ Estado persistente cargado');
        } catch (error) {
            console.warn('Error cargando estado persistente:', error);
        }
    },
    
    /**
     * Limpiar estado persistente (al cerrar sesión)
     */
    clearPersistentState: function() {
        this.stopReplicationWatch('state_clear');
        try {
            localStorage.removeItem(this.storageKey);
            console.log('🗑️ Estado persistente limpiado');
        } catch (error) {
            console.warn('Error limpiando estado persistente:', error);
        }
    },
    
    /**
     * Recuperar estudio
     */
    retrieveStudy: async function(studyInstanceUID, explicitNodeId) {
        let nodeId;
        if (explicitNodeId != null && String(explicitNodeId).trim() !== '') {
            nodeId = String(explicitNodeId).trim();
        } else {
            nodeId = this.activeNodeResultTabId || document.getElementById('searchNodeSelect')?.value;
        }
        
        if (!nodeId) {
            this.showError('Seleccione un nodo');
            return;
        }
        
        if (this.studyUidHasActiveJob(studyInstanceUID)) {
            this.showWarning('Ya hay un job pendiente o en ejecución para este estudio. Revise la pestaña Jobs.');
            return;
        }
        
        let confirmMsg = '¿Desea recuperar este estudio desde el nodo remoto hacia el PACS local?';
        const nodeObj = (this.nodes || []).find(n => String(n.id) === String(nodeId));
        if (nodeObj && nodeObj.name) {
            confirmMsg = `¿Desea recuperar este estudio desde el nodo «${nodeObj.name}» hacia el PACS local?`;
        }
        
        const confirmed = await this.showConfirm(
            'Recuperar Estudio',
            confirmMsg,
            'Recuperar',
            'Cancelar'
        );
        
        if (!confirmed) {
            return;
        }
        
        try {
            // Mostrar modal de procesamiento
            this.showProcessingModal(studyInstanceUID, nodeId);
            
            const response = await fetch(`${this.apiBase}/retrieve.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    node_id: nodeId,
                    StudyInstanceUIDs: [studyInstanceUID]
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // sendSuccessResponse envuelve los datos en 'data'
                const jobData = data.data || data;
                const jobId = jobData.job_id;
                
                if (!jobId) {
                    console.error('No se recibió job_id en la respuesta:', data);
                    this.hideProcessingModal();
                    this.showError('Error: No se recibió ID de job. Respuesta: ' + JSON.stringify(data));
                    return;
                }
                
                // Iniciar monitoreo del job
                this.monitorJobProgress(jobId, studyInstanceUID);
                await this.syncCrossSyncJobMarkersAfterJobsApi();
            } else {
                this.hideProcessingModal();
                this.showError('Error: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
            this.hideProcessingModal();
            this.showError('Error de conexión al recuperar estudio');
        }
    },
    
    /**
     * Mostrar modal de procesamiento
     */
    showProcessingModal: function(studyInstanceUID, nodeId) {
        // Crear modal si no existe
        let modal = document.getElementById('retrieveProcessingModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'retrieveProcessingModal';
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-download"></i> Recuperando Estudio
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar" title="Cerrar (el job continúa en segundo plano)"></button>
                        </div>
                        <div class="modal-body text-center">
                            <div class="spinner-border text-primary mb-3" role="status">
                                <span class="visually-hidden">Procesando...</span>
                            </div>
                            <p id="processingMessage">Iniciando recuperación del estudio...</p>
                            <div id="processingProgress" class="mt-3">
                                <div class="progress mb-2">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                         role="progressbar" 
                                         style="width: 0%"
                                         id="progressBar"
                                         aria-valuenow="0"
                                         aria-valuemin="0"
                                         aria-valuemax="100"></div>
                                </div>
                                <small class="text-muted" id="progressDetails"></small>
                            </div>
                        </div>
                        <div class="modal-footer justify-content-between">
                            <small class="text-muted"><i class="fas fa-info-circle"></i> Cerrar no cancela el job — continuará en segundo plano.</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        // Resetear contenido
        const messageEl = modal.querySelector('#processingMessage');
        const progressEl = modal.querySelector('#processingProgress');
        const progressBar = modal.querySelector('#progressBar');
        const progressDetails = modal.querySelector('#progressDetails');
        
        if (messageEl) messageEl.textContent = 'Iniciando recuperación del estudio...';
        // Mostrar progreso desde el inicio
        if (progressEl) progressEl.style.display = 'block';
        if (progressBar) {
            progressBar.style.width = '0%';
            progressBar.setAttribute('aria-valuenow', '0');
            progressBar.classList.remove('bg-success', 'bg-danger');
            progressBar.classList.add('progress-bar-animated');
        }
        if (progressDetails) progressDetails.textContent = 'Preparando recuperación...';
        
        // Mostrar modal
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        
        // Guardar referencia para poder actualizarlo
        this.processingModal = bsModal;
        this.processingModalElement = modal;
    },
    
    /**
     * Ocultar modal de procesamiento
     */
    hideProcessingModal: function() {
        if (this.processingModal) {
            this.processingModal.hide();
            // Limpiar referencias después de un delay para permitir la animación
            setTimeout(() => {
                this.processingModal = null;
                this.processingModalElement = null;
            }, 300);
        }
    },
    
    /**
     * Ir a la pestaña Jobs (solo cuando se requiere atención operativa)
     */
    openJobsTab: function() {
        const jobsTab = document.getElementById('jobs-tab');
        if (jobsTab) jobsTab.click();
    },
    
    /**
     * Refrescar solo un UID en Cross Sync para mantener filtros y actualizar conteos.
     */
    refreshCrossSyncStudyByUid: async function(studyUID) {
        const uid = String(studyUID || '').trim();
        const comp = this.crossSyncComparison;
        if (!uid || !comp || !Array.isArray(comp.nodeIds) || comp.nodeIds.length < 2) return;
        
        const nodeIds = comp.nodeIds.map(String);
        const query = {
            Level: 'Study',
            Query: { StudyInstanceUID: uid }
        };
        const perNode = {};
        const nodeErrors = {};
        
        await Promise.all(nodeIds.map(async (nid) => {
            try {
                const response = await fetch(`${this.apiBase}/find.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    credentials: 'include',
                    body: JSON.stringify({
                        node_id: nid,
                        query,
                        no_cache: true,
                        request_source: 'cross_sync_post_retrieve_refresh'
                    })
                });
                let data;
                try {
                    data = await response.json();
                } catch (parseErr) {
                    perNode[nid] = [];
                    nodeErrors[nid] = `Respuesta no JSON (HTTP ${response.status})`;
                    return;
                }
                if (!response.ok || !data.success) {
                    perNode[nid] = [];
                    nodeErrors[nid] = data?.error || data?.message || `HTTP ${response.status}`;
                    return;
                }
                const rows = this.extractFindResponseRows(data);
                const row = this.pickStudyRowForWatch(rows, uid);
                perNode[nid] = row ? [row] : [];
            } catch (err) {
                perNode[nid] = [];
                nodeErrors[nid] = 'Error de red';
            }
        }));
        
        const merged = this.buildCrossSyncMerge(nodeIds, perNode, comp.nodeLabels || {}, nodeErrors);
        const updatedRow = (merged.rows || [])[0] || null;
        const idx = (comp.rows || []).findIndex(r => String(r.StudyInstanceUID || '').trim() === uid);
        if (idx >= 0 && updatedRow) {
            this.applyCrossSyncModifyLogHints({ rows: [updatedRow], nodeIds }, comp.modifyLogItems || []);
            comp.rows[idx] = updatedRow;
        } else if (idx >= 0 && !updatedRow) {
            comp.rows[idx].nodeStates = {};
            nodeIds.forEach(nid => { comp.rows[idx].nodeStates[nid] = { kind: 'missing' }; });
            comp.rows[idx].hasMissing = true;
            comp.rows[idx].hasPartial = false;
            comp.rows[idx].summary = 'ausente en algún nodo';
            comp.rows[idx].summaryClass = 'danger';
        }
        
        const rows = comp.rows || [];
        comp.stats = {
            total: rows.length,
            absent: rows.filter(r => r.hasMissing).length,
            incomplete: rows.filter(r => r.hasPartial && !r.hasMissing).length,
            aligned: rows.filter(r => !r.hasMissing && !r.hasPartial).length
        };
        this.savePersistentState();
        this.renderCrossSyncTable();
    },
    
    /**
     * Monitorear progreso del job
     */
    monitorJobProgress: async function(jobId, studyInstanceUID) {
        const maxAttempts = 300; // máx 10 min (intervalos de 2s)
        let attempts      = 0;
        let consecutiveErrors = 0;
        
        // Mostrar progreso indeterminado mientras llega la primera respuesta
        const messageEl  = this.processingModalElement?.querySelector('#processingMessage');
        const progressBar = this.processingModalElement?.querySelector('#progressBar');
        if (messageEl) messageEl.textContent = 'Transfiriendo estudio desde el nodo remoto...';
        if (progressBar) {
            progressBar.style.width = '5%';
            progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
        }
        
        const checkProgress = async () => {
            try {
                const response = await fetch(`${this.apiBase}/jobs.php?id=${jobId}`, {
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    if (response.status === 404 && attempts < 3) {
                        attempts++;
                        setTimeout(checkProgress, 2000);
                        return;
                    }
                    throw new Error(`HTTP ${response.status}`);
                }
                
                consecutiveErrors = 0;
                const data = await response.json();
                
                if (!data.success || !data.data) {
                    throw new Error(data.error || 'Respuesta inválida');
                }
                
                const job = data.data;
                
                console.log(`[PACS Nodes] Job ${jobId}: status=${job.status} progress=${job.progress}% inst=${job.received_instances}/${job.expected_instances}`);
                
                // Actualizar UI del modal
                this.updateProcessingModal(job);
                
                // ¿Terminó?
                if (job.status === 'success' || job.status === 'failed' || job.status === 'cancelled') {
                    setTimeout(() => {
                        this.hideProcessingModal();
                        
                        if (job.status === 'success') {
                            const recv = parseInt(job.received_instances) || 0;
                            const exp  = parseInt(job.expected_instances) || 0;
                            let message = 'Estudio recuperado exitosamente.';
                            if (exp > 0)  message += ` ${recv}/${exp} instancias recibidas.`;
                            else if (recv > 0) message += ` ${recv} instancias recibidas.`;
                            this.showSuccess(message);
                            this.refreshCrossSyncStudyByUid(studyInstanceUID).catch(err => {
                                console.warn('[CrossSync][postRetrieveRefresh]', err);
                            });
                        } else {
                            this.showError(job.error_message || 'Error al recuperar el estudio');
                            this.openJobsTab();
                        }
                        this.loadJobs();
                    }, 2000);
                    return;
                }
                
                // Continuar monitoreando cada 2 segundos
                attempts++;
                if (attempts < maxAttempts) {
                    setTimeout(checkProgress, 2000);
                } else {
                    this.hideProcessingModal();
                    this.showError('Tiempo de espera agotado. Verifique el estado en la pestaña Jobs.');
                    this.openJobsTab();
                    this.syncCrossSyncJobMarkersAfterJobsApi();
                }
                
            } catch (error) {
                consecutiveErrors++;
                console.error(`[PACS Nodes] Error en polling (${consecutiveErrors}):`, error);
                if (consecutiveErrors < 5) {
                    setTimeout(checkProgress, 3000);
                } else {
                    this.hideProcessingModal();
                    this.showError('Error al monitorear el progreso: ' + error.message);
                    this.openJobsTab();
                }
            }
        };
        
        // Iniciar polling inmediatamente (sin delay artificial)
        checkProgress();
    },
    
    /**
     * Actualizar modal de procesamiento con progreso
     */
    updateProcessingModal: function(job) {
        if (!this.processingModalElement) return;
        
        const messageEl = this.processingModalElement.querySelector('#processingMessage');
        const progressEl = this.processingModalElement.querySelector('#processingProgress');
        const progressBar = this.processingModalElement.querySelector('#progressBar');
        const progressDetails = this.processingModalElement.querySelector('#progressDetails');
        
        if (!messageEl || !progressEl || !progressBar || !progressDetails) {
            console.warn('[PACS Nodes] Elementos del modal no encontrados');
            return;
        }
        
        const progress          = parseInt(job.progress)           || 0;
        const expectedSeries    = parseInt(job.expected_series)    || 0;
        const expectedInstances = parseInt(job.expected_instances) || 0;
        const receivedSeries    = parseInt(job.received_series)    || 0;
        const receivedInstances = parseInt(job.received_instances) || 0;
        
        // Mostrar sección de progreso siempre
        progressEl.style.display = 'block';
        
        // Calcular porcentaje de progreso
        // Prioridad: instancias recibidas > series recibidas > progress de Orthanc
        let progressPercent = progress;
        if (expectedInstances > 0 && receivedInstances > 0) {
            progressPercent = Math.min(99, Math.round((receivedInstances / expectedInstances) * 100));
        } else if (expectedSeries > 0 && receivedSeries > 0) {
            progressPercent = Math.min(99, Math.round((receivedSeries / expectedSeries) * 100));
        } else if (progress > 0) {
            progressPercent = Math.min(99, progress);
        }
        // Al menos 5% para mostrar actividad mientras está running
        if (job.status === 'running' || job.status === 'pending') {
            progressPercent = Math.max(5, progressPercent);
        }
        
        // Actualizar mensaje y barra de progreso según el estado
        if (job.status === 'running' || job.status === 'pending') {
            messageEl.textContent = 'Transfiriendo estudio desde el nodo remoto...';
            
            // Barra animada mientras corre
            progressBar.style.width = `${progressPercent}%`;
            progressBar.setAttribute('aria-valuenow', progressPercent);
            progressBar.classList.remove('bg-success', 'bg-danger');
            progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
            
            // Texto de detalle
            let details = '';
            if (expectedInstances > 0 && receivedInstances > 0) {
                details = `Recibiendo ${receivedInstances} / ${expectedInstances} instancias (${progressPercent}%)`;
            } else if (receivedInstances > 0) {
                details = `Recibiendo ${receivedInstances} instancias...`;
            } else if (expectedInstances > 0) {
                details = `Esperando instancias (0 / ${expectedInstances})...`;
            } else {
                details = 'Transfiriendo...';
            }
            if (expectedSeries > 0 && receivedSeries > 0) {
                details += ` — ${receivedSeries}/${expectedSeries} series`;
            }
            progressDetails.textContent = details;
            
        } else if (job.status === 'success') {
            messageEl.textContent = '¡Estudio recuperado exitosamente!';
            progressBar.style.width = '100%';
            progressBar.setAttribute('aria-valuenow', 100);
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.add('bg-success');
            
            let details = 'Completado';
            if (expectedInstances > 0) {
                details += ` | ${receivedInstances}/${expectedInstances} instancias`;
            }
            if (expectedSeries > 0) {
                details += ` | ${receivedSeries}/${expectedSeries} series`;
            }
            progressDetails.textContent = details;
            
        } else if (job.status === 'failed') {
            messageEl.textContent = 'Error al recuperar el estudio';
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.add('bg-danger');
            const errorMsg = job.error_message || 'Error desconocido';
            progressDetails.textContent = `Error: ${errorMsg}`;
            
        } else {
            // Estado desconocido
            messageEl.textContent = `Estado: ${job.status || 'desconocido'}`;
            progressBar.style.width = `${progress}%`;
            progressDetails.textContent = `Progreso: ${progress}%`;
        }
    },
    
    /**
     * Cargar jobs (respeta el filtro del desplegable: polling y demás refrescos sin argumentos)
     */
    loadJobs: async function() {
        const status = (document.getElementById('jobsFilterStatus')?.value || '').trim();
        let url = `${this.apiBase}/jobs.php`;
        if (status) {
            url += `?status=${encodeURIComponent(status)}`;
        }
        
        try {
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                const jobs = Array.isArray(data.data) ? data.data : [];
                const jobsWithLive = await this.enrichRunningJobsForList(jobs);
                this.renderJobsTable(jobsWithLive);
                await this.syncCrossSyncJobMarkersAfterJobsApi();
            } else {
                this.showError('Error cargando jobs: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
        }
    },
    
    /**
     * Enriquecer lista de jobs consultando detalle de running/pending.
     * Reutiliza jobs.php?id=... para que progreso/estado refleje lo mismo que el modal.
     */
    enrichRunningJobsForList: async function(jobs) {
        const list = Array.isArray(jobs) ? jobs.slice() : [];
        const running = list
            .map((job, idx) => ({ job, idx }))
            .filter(x => x.job && (x.job.status === 'running' || x.job.status === 'pending'))
            .slice(0, 12); // límite defensivo por polling
        if (!running.length) return list;
        
        const workers = 4;
        let ptr = 0;
        const runOne = async () => {
            while (true) {
                const current = ptr++;
                if (current >= running.length) return;
                const item = running[current];
                try {
                    const resp = await fetch(`${this.apiBase}/jobs.php?id=${item.job.id}`, {
                        method: 'GET',
                        credentials: 'include'
                    });
                    const data = await resp.json();
                    if (resp.ok && data.success && data.data) {
                        // mantener contexto enriquecido de UI listado si viene vacío en detalle
                        const prevContext = list[item.idx].study_context;
                        const prevOrigin = list[item.idx].retrieve_source;
                        const prevLabel = list[item.idx].retrieve_source_label;
                        const prevHint = list[item.idx].retrieve_source_hint;
                        list[item.idx] = {
                            ...list[item.idx],
                            ...data.data,
                            study_context: data.data.study_context || prevContext
                        };
                        if (!list[item.idx].retrieve_source && prevOrigin) {
                            list[item.idx].retrieve_source = prevOrigin;
                            list[item.idx].retrieve_source_label = prevLabel;
                            list[item.idx].retrieve_source_hint = prevHint;
                        }
                    }
                } catch (e) {
                    console.warn('[Jobs] No se pudo refrescar job en vivo:', item.job.id, e);
                }
            }
        };
        
        const pool = [];
        for (let i = 0; i < Math.min(workers, running.length); i++) {
            pool.push(runOne());
        }
        await Promise.all(pool);
        return list;
    },
    
    /**
     * Renderizar tabla de jobs
     */
    renderJobsTable: function(jobs) {
        const tbody = document.getElementById('jobsTableBody');
        
        if (jobs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No hay jobs</td></tr>';
            return;
        }
        
        tbody.innerHTML = jobs.map(job => {
            const statusBadge = this.getJobStatusBadge(job.status);
            const originBadge = this.getJobRetrieveSourceBadge(job);
            const progressBar = job.status === 'running' ? 
                `<div class="progress" style="height: 20px;">
                    <div class="progress-bar" role="progressbar" style="width: ${job.progress}%">${job.progress}%</div>
                </div>` : '';
            const studyCtx = job.study_context || {};
            const uid = (studyCtx.study_uid || (Array.isArray(job.study_instance_uids) ? job.study_instance_uids[0] : '') || '').toString();
            const uidShort = uid ? this.shortStudyUid(uid) : '';
            const patientName = this.escapeHtml(studyCtx.patient_name || '—');
            const patientId = this.escapeHtml(studyCtx.patient_id || '—');
            const studyDate = this.escapeHtml(this.formatStudyDate(studyCtx.study_date || '') || '—');
            const studyInfoHtml = `
                <div class="small lh-sm">
                    <div><strong>${patientName}</strong></div>
                    <div class="text-muted">ID: ${patientId} · Fecha: ${studyDate}</div>
                    ${uidShort ? `<div class="text-muted"><code title="${this.escapeHtml(uid)}">${this.escapeHtml(uidShort)}</code></div>` : ''}
                </div>
            `;
            
            const errorMsg = (job.status === 'failed' && job.error_message)
                ? `<div class="text-danger small mt-1" title="${this.escapeHtml(job.error_message)}"><i class="fas fa-exclamation-circle me-1"></i>${this.escapeHtml(job.error_message.length > 120 ? job.error_message.slice(0, 120) + '…' : job.error_message)}</div>`
                : '';

            return `
                <tr>
                    <td>#${job.id}</td>
                    <td>${this.escapeHtml(job.node_name || '-')}</td>
                    <td>${job.job_type.toUpperCase()}</td>
                    <td>${originBadge}</td>
                    <td>${Array.isArray(job.study_instance_uids) ? job.study_instance_uids.length : 0}</td>
                    <td>${studyInfoHtml}</td>
                    <td>${statusBadge}${errorMsg}</td>
                    <td>${progressBar || '-'}</td>
                    <td>${new Date(job.created_at).toLocaleString('es-AR')}</td>
                    <td>
                        ${job.status === 'running' || job.status === 'pending' ? 
                            `<button class="btn btn-sm btn-outline-danger" onclick="PacsNodesManager.cancelJob(${job.id})">
                                <i class="fas fa-times"></i>
                            </button>` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    },
    
    /**
     * Obtener badge de status de job
     */
    getJobStatusBadge: function(status) {
        const badges = {
            'pending': '<span class="badge bg-secondary">Pendiente</span>',
            'running': '<span class="badge bg-primary">En ejecución</span>',
            'success': '<span class="badge bg-success">Completado</span>',
            'failed': '<span class="badge bg-danger">Fallido</span>',
            'cancelled': '<span class="badge bg-warning">Cancelado</span>'
        };
        return badges[status] || status;
    },

    /**
     * Badge de origen del job (worker / cloner UI / directo = Cross Sync u otro retrieve sin orden).
     */
    getJobRetrieveSourceBadge: function(job) {
        const code = (job && job.retrieve_source) ? String(job.retrieve_source) : 'direct';
        const hintRaw = (job && job.retrieve_source_hint) ? String(job.retrieve_source_hint) : '';
        const labelRaw = (job && job.retrieve_source_label) ? String(job.retrieve_source_label) : '';
        const label = this.escapeHtml(labelRaw || (code === 'worker' ? 'Worker' : code === 'cloner_ui' ? 'Cloner UI' : 'Directo'));
        const hint = this.escapeHtml(hintRaw);
        const cls = {
            worker: 'bg-success',
            cloner_ui: 'bg-primary',
            direct: 'bg-secondary',
            scheduled: 'bg-info text-dark',
            cloner_other: 'bg-warning text-dark'
        };
        const c = cls[code] || 'bg-secondary';
        return `<span class="badge ${c}" title="${hint}">${label}</span>`;
    },

    /**
     * Metadatos del job activo para un StudyInstanceUID (Cross Sync).
     */
    getCrossSyncActiveJobMeta: function(uid) {
        const m = this.crossSyncActiveJobUids && this.crossSyncActiveJobUids[String(uid || '').trim()];
        return (m && typeof m === 'object') ? m : null;
    },

    /**
     * Badge de origen del job para filas Cross Sync (misma semántica que pestaña Jobs).
     */
    getCrossSyncJobOriginHtml: function(uid) {
        const meta = this.getCrossSyncActiveJobMeta(uid);
        if (!meta) {
            return '<span class="badge bg-secondary" title="Job pendiente o en ejecución (origen no detallado)">Job</span>';
        }
        return this.getJobRetrieveSourceBadge(meta);
    },
    
    /**
     * Cancelar job
     */
    cancelJob: async function(jobId) {
        const confirmed = await this.showConfirm(
            'Cancelar Job',
            '¿Está seguro de cancelar este job? Esta acción no se puede deshacer.',
            'Cancelar Job',
            'No Cancelar'
        );
        
        if (!confirmed) {
            return;
        }
        
        try {
            const response = await fetch(`${this.apiBase}/jobs.php?id=${jobId}`, {
                method: 'DELETE',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Job cancelado exitosamente');
                this.loadJobs();
            } else {
                this.showError('Error: ' + (data.error || data.message));
            }
        } catch (error) {
            console.error('Error:', error);
            this.showError('Error de conexión al cancelar job');
        }
    },
    
    /**
     * Iniciar polling de jobs
     */
    startJobsPolling: function() {
        // Polling cada 5 segundos si estamos en la pestaña de jobs
        setInterval(() => {
            const jobsTab = document.getElementById('jobs-tab');
            if (jobsTab && jobsTab.classList.contains('active')) {
                this.loadJobs();
            }
        }, 5000);
    },
    
    /**
     * Cargar dashboard
     */
    /**
     * Dashboard: contadores y tabla por nodo (datos desde API + BD).
     */
    loadDashboard: async function() {
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };
        
        const applyFallbackFromMemory = () => {
            const totalNodes = this.nodes.length;
            const activeNodes = this.nodes.filter(n => n.is_active).length;
            setText('statTotalNodes', totalNodes);
            setText('statActiveNodes', activeNodes);
            setText('statTotalQueries', '—');
            setText('statTotalRetrieves', '—');
            this.renderNodeStatsTableFromNodes();
        };
        
        try {
            const response = await fetch(`${this.apiBase}/statistics.php`, {
                method: 'GET',
                credentials: 'include'
            });
            const data = await response.json();
            
            if (data.success && data.data) {
                const d = data.data;
                setText('statTotalNodes', d.nodes_total ?? 0);
                setText('statActiveNodes', d.nodes_active ?? 0);
                setText('statTotalQueries', d.queries_today ?? 0);
                setText('statTotalRetrieves', d.retrieves_today ?? 0);
                this.renderNodeStatsTable(d.by_node || [], d.date);
                return;
            }
        } catch (err) {
            console.warn('[PACS Nodes] Dashboard API:', err);
        }
        
        applyFallbackFromMemory();
    },
    
    /**
     * Tabla "Estadísticas por nodo" desde filas del API (hoy).
     */
    renderNodeStatsTable: function(rows, dateLabel) {
        const container = document.getElementById('nodesStatsContainer');
        if (!container) return;
        
        if (!rows || rows.length === 0) {
            container.innerHTML = '<p class="text-muted text-center mb-0">No hay nodos configurados o aún no hay actividad registrada para hoy.</p>';
            return;
        }
        
        const day = dateLabel ? ` (${dateLabel})` : '';
        let html = `<p class="text-muted small mb-2">Cifras del día actual${day}: consultas (C-FIND) y recuperaciones (C-MOVE) registradas en el sistema.</p>`;
        html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>';
        html += '<th>Nodo</th><th>Tipo</th><th class="text-end">Consultas</th><th class="text-end">Recuperaciones</th><th class="text-end">Estudios recibidos</th>';
        html += '</tr></thead><tbody>';
        
        rows.forEach(r => {
            const name = this.escapeHtml(r.name || '');
            const type = this.escapeHtml(r.node_type || '');
            const q = parseInt(r.queries_today, 10) || 0;
            const ret = parseInt(r.retrieves_today, 10) || 0;
            const st = parseInt(r.studies_retrieved_today, 10) || 0;
            html += `<tr>
                <td>${name}</td>
                <td><span class="badge bg-secondary">${type}</span></td>
                <td class="text-end">${q}</td>
                <td class="text-end">${ret}</td>
                <td class="text-end">${st}</td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
        container.innerHTML = html;
    },
    
    /**
     * Fallback: listar nodos sin cifras de estadística (solo nombres).
     */
    renderNodeStatsTableFromNodes: function() {
        const container = document.getElementById('nodesStatsContainer');
        if (!container) return;
        if (!this.nodes || this.nodes.length === 0) {
            container.innerHTML = '<p class="text-muted text-center mb-0">No hay nodos configurados.</p>';
            return;
        }
        let html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>';
        html += '<th>Nodo</th><th>Tipo</th><th class="text-end">Consultas hoy</th><th class="text-end">Recuperaciones hoy</th>';
        html += '</tr></thead><tbody>';
        this.nodes.forEach(n => {
            html += `<tr>
                <td>${this.escapeHtml(n.name || '')}</td>
                <td><span class="badge bg-secondary">${this.escapeHtml(n.node_type || '')}</span></td>
                <td class="text-end text-muted">—</td>
                <td class="text-end text-muted">—</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    },
    
    /**
     * Cross Sync: lista de nodos elegibles (activos + C-FIND)
     */
    refreshCrossSyncNodeList: function() {
        const container = document.getElementById('crossSyncNodesContainer');
        if (!container) return;
        
        const eligible = (this.nodes || []).filter(n => {
            const inactive = n.is_active == 0 || n.is_active === false || String(n.is_active) === '0';
            const findOff = n.allow_find == 0 || n.allow_find === false || String(n.allow_find) === '0';
            return !inactive && !findOff;
        });
        
        if (eligible.length === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">No hay nodos con búsqueda (C-FIND) disponible o están inactivos.</p>';
            return;
        }
        
        const saved = new Set((this.savedCrossSyncNodeIds || []).map(String));
        container.innerHTML = eligible.map(n => {
            const id = String(n.id);
            const checked = saved.has(id) ? 'checked' : '';
            return `
                <div class="form-check">
                    <input class="form-check-input cross-sync-node-cb" type="checkbox" value="${id}" id="crossSyncNode_${id}" ${checked}>
                    <label class="form-check-label" for="crossSyncNode_${id}">${this.escapeHtml(n.name || id)} <small class="text-muted">(${this.escapeHtml(n.node_type || '')})</small></label>
                </div>
            `;
        }).join('');
        
        container.querySelectorAll('.cross-sync-node-cb').forEach(cb => {
            cb.addEventListener('change', () => this.savePersistentState());
        });
    },
    
    /**
     * Aplicar filtros Cross Sync guardados a los inputs
     */
    applyCrossSyncSavedFiltersToInputs: function() {
        const f = this.savedCrossSyncFilters;
        if (!f || typeof f !== 'object') return;
        
        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el && val !== undefined && val !== null) el.value = val;
        };
        setVal('crossSyncPatientID', f.patientID);
        setVal('crossSyncPatientName', f.patientName);
        setVal('crossSyncDateFrom', f.dateFrom);
        setVal('crossSyncDateTo', f.dateTo);
        setVal('crossSyncModality', f.modality);
        setVal('crossSyncAccessionNumber', f.accessionNumber);
        const diff = document.getElementById('crossSyncFilterDiffOnly');
        if (diff && typeof f.diffOnly === 'boolean') diff.checked = f.diffOnly;
        const extras = document.getElementById('crossSyncTreatExtrasAligned');
        if (extras) extras.checked = (typeof f.treatExtrasAligned === 'boolean') ? f.treatExtrasAligned : true;
        const value = typeof f.resultStatus === 'string' ? f.resultStatus : 'all';
        this.setCrossSyncResultStatusFilter(value);
        if (Array.isArray(f.listModalities)) {
            this.crossSyncListModalities = f.listModalities.map(m => String(m).toUpperCase()).filter(Boolean);
        }
        const loc = document.getElementById('crossSyncLocalSearchFilter');
        if (loc && this.crossSyncLocalSearchTerm !== undefined) {
            loc.value = this.crossSyncLocalSearchTerm || '';
        }
        this.updateCrossSyncLocalSearchChrome();
    },
    
    /**
     * IDs de nodos seleccionados en Cross Sync
     */
    getSelectedCrossSyncNodeIds: function() {
        return Array.from(document.querySelectorAll('.cross-sync-node-cb:checked')).map(cb => String(cb.value));
    },
    
    /**
     * Construir cuerpo de consulta C-FIND para Cross Sync (misma lógica que búsqueda simple)
     */
    collectCrossSyncQuery: function() {
        const query = {
            Level: 'Study',
            Query: {}
        };
        const patientID = document.getElementById('crossSyncPatientID')?.value?.trim() || '';
        const patientName = document.getElementById('crossSyncPatientName')?.value?.trim() || '';
        const dateFrom = document.getElementById('crossSyncDateFrom')?.value || '';
        const dateTo = document.getElementById('crossSyncDateTo')?.value || '';
        const modality = document.getElementById('crossSyncModality')?.value || '';
        const accessionNumber = document.getElementById('crossSyncAccessionNumber')?.value?.trim() || '';
        
        if (patientID) query.Query.PatientID = patientID;
        if (patientName) query.Query.PatientName = patientName;
        if (dateFrom && dateTo) {
            query.Query.StudyDate = dateFrom.replace(/-/g, '') + '-' + dateTo.replace(/-/g, '');
        } else if (dateFrom) {
            query.Query.StudyDate = dateFrom.replace(/-/g, '');
        }
        if (modality) query.Query.ModalitiesInStudy = modality;
        if (accessionNumber) query.Query.AccessionNumber = accessionNumber;
        return query;
    },
    
    parseStudyCounts: function(row) {
        const s = parseInt(row.NumberOfStudyRelatedSeries, 10);
        const i = parseInt(row.NumberOfStudyRelatedInstances, 10);
        return {
            series: Number.isFinite(s) ? s : 0,
            instances: Number.isFinite(i) ? i : 0
        };
    },
    
    /**
     * Parsear modalidades de estudio (ej: "CT\\DOC" / "CT,SR") a tokens normalizados.
     */
    parseStudyModalities: function(modsRaw) {
        return String(modsRaw || '')
            .split(/[\\,]+/)
            .map(m => m.trim().toUpperCase())
            .filter(Boolean);
    },
    
    /**
     * Si true, diferencias por "extras" no rompen alineación.
     */
    isCrossSyncTreatExtrasAlignedEnabled: function() {
        return document.getElementById('crossSyncTreatExtrasAligned')?.checked !== false;
    },
    
    /**
     * Ejecutar comparación Cross Sync (C-FIND en paralelo)
     */
    executeCrossSync: async function() {
        const nodeIds = this.getSelectedCrossSyncNodeIds();
        if (nodeIds.length < 2) {
            this.showError('Seleccione al menos dos nodos para comparar');
            return;
        }
        
        const dateFrom = document.getElementById('crossSyncDateFrom')?.value || '';
        const dateTo = document.getElementById('crossSyncDateTo')?.value || '';
        const dateValidation = this.validateDateFilters(dateFrom, dateTo);
        if (!dateValidation.valid) {
            dateValidation.warnings.forEach(warning => {
                if (warning.type === 'error') this.showError(warning.message);
                else if (warning.type === 'warning') this.showWarning(warning.message);
                else this.showInfo(warning.message);
            });
            return;
        }
        const criticalWarnings = dateValidation.warnings.filter(w => w.requiresConfirmation);
        if (criticalWarnings.length > 0) {
            dateValidation.warnings.forEach(warning => {
                if (warning.type === 'error') this.showError(warning.message);
                else if (warning.type === 'warning') this.showWarning(warning.message);
                else this.showInfo(warning.message);
            });
            const confirmationMessage = criticalWarnings.map(w => w.message).join('\n\n') +
                '\n\n¿Desea continuar con la comparación entre nodos?';
            if (!confirm(confirmationMessage)) return;
        } else if (dateValidation.warnings.length > 0) {
            dateValidation.warnings.forEach(warning => {
                if (warning.type === 'warning') this.showWarning(warning.message);
                else this.showInfo(warning.message);
            });
        }
        
        const query = this.collectCrossSyncQuery();
        console.info('[CrossSync] Ejecutando comparación forzada (sin cache)', {
            nodeIds,
            query
        });
        const alerts = document.getElementById('crossSyncAlerts');
        if (alerts) {
            alerts.innerHTML = '';
        }
        if (this.replicationWatch) {
            this.stopReplicationWatch('new_compare');
        }
        const thead = document.getElementById('crossSyncResultsHead');
        const tbody = document.getElementById('crossSyncResultsBody');
        const countEl = document.getElementById('crossSyncResultCount');
        if (thead) thead.innerHTML = '<tr><th class="text-muted">Consultando nodos…</th></tr>';
        if (tbody) tbody.innerHTML = '<tr><td class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>';
        if (countEl) countEl.textContent = '…';
        
        const nodeLabels = {};
        (this.nodes || []).forEach(n => { nodeLabels[String(n.id)] = n.name || `Nivel ${n.id}`; });
        
        const perNode = {};
        const nodeErrors = {};
        
        await Promise.all(nodeIds.map(async nid => {
            let timeoutId = null;
            try {
                const nodeCfg = (this.nodes || []).find(n => String(n.id) === String(nid)) || null;
                const isLocalNode = String(nodeCfg?.node_type || '').toLowerCase() === 'local';
                const controller = new AbortController();
                const timeoutMs = isLocalNode ? 30000 : 15000;
                timeoutId = setTimeout(() => controller.abort(), timeoutMs);
                const response = await fetch(`${this.apiBase}/find.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    credentials: 'include',
                    signal: controller.signal,
                    body: JSON.stringify({
                        node_id: nid,
                        query,
                        no_cache: true,
                        request_source: 'cross_sync'
                    })
                });
                clearTimeout(timeoutId);
                const raw = await response.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : null;
                } catch (parseErr) {
                    const shortBody = (raw || '').slice(0, 180).replace(/\s+/g, ' ').trim();
                    nodeErrors[nid] = `Respuesta no JSON (HTTP ${response.status})${shortBody ? `: ${shortBody}` : ''}`;
                    perNode[nid] = [];
                    console.error('[CrossSync] Respuesta inválida de find.php', {
                        nodeId: String(nid),
                        status: response.status,
                        parseErr,
                        bodyPreview: shortBody
                    });
                    return;
                }

                if (!response.ok) {
                    nodeErrors[nid] = data?.error || data?.message || `HTTP ${response.status}`;
                    perNode[nid] = [];
                    console.warn('[CrossSync] Error HTTP de nodo', {
                        nodeId: String(nid),
                        status: response.status,
                        error: nodeErrors[nid]
                    });
                    return;
                }

                if (data.success && data.data) {
                    const rows = data.data.data || [];
                    perNode[nid] = rows;
                    console.info('[CrossSync] Nodo consultado', {
                        nodeId: String(nid),
                        rows: rows.length,
                        cached: !!data?.data?.cached
                    });
                } else {
                    nodeErrors[nid] = data.error || data.message || 'Error desconocido';
                    perNode[nid] = [];
                    console.warn('[CrossSync] Error de nodo', {
                        nodeId: String(nid),
                        error: nodeErrors[nid]
                    });
                }
            } catch (e) {
                console.error('[CrossSync]', e);
                nodeErrors[nid] = (e && e.name === 'AbortError')
                    ? (() => {
                        const nodeCfg = (this.nodes || []).find(n => String(n.id) === String(nid)) || null;
                        const isLocalNode = String(nodeCfg?.node_type || '').toLowerCase() === 'local';
                        return isLocalNode
                            ? 'Tiempo de espera agotado (30s)'
                            : 'Tiempo de espera agotado (15s)';
                    })()
                    : 'Error de red';
                perNode[nid] = [];
            } finally {
                if (timeoutId) clearTimeout(timeoutId);
            }
        }));
        
        const merged = this.buildCrossSyncMerge(nodeIds, perNode, nodeLabels, nodeErrors);
        await this.finalizeCrossSyncComparison(merged);
        this.crossSyncComparison = merged;
        this.crossSyncSelectedStudies = {};
        this.crossSyncLastRenderedRows = [];
        this.savePersistentState();
        this.refreshCrossSyncActiveJobsFromApi().then(() => {
            this.renderCrossSyncTable();
        });
        this.updateCrossSyncBatchControls();
        
        if (alerts && Object.keys(nodeErrors).length > 0) {
            const parts = Object.keys(nodeErrors).map(id => {
                const label = this.escapeHtml(nodeLabels[id] || id);
                const msg = this.escapeHtml(nodeErrors[id]);
                return `<div class="alert alert-warning py-2 mb-2"><strong>${label}:</strong> ${msg}</div>`;
            });
            alerts.innerHTML = parts.join('');
        }
    },
    
    /**
     * Consultar auditoría PACS Manager por UIDs presentes en Cross Sync.
     */
    fetchCrossSyncModifyLogs: async function(merged) {
        if (!merged?.rows?.length) return [];
        const uids = merged.rows.map(r => r.StudyInstanceUID).filter(Boolean);
        if (!uids.length) return [];
        const dateFrom = document.getElementById('crossSyncDateFrom')?.value || '';
        const dateTo = document.getElementById('crossSyncDateTo')?.value || '';
        try {
            const response = await fetch(`${this.apiBase}/modify-log-lookup.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                credentials: 'include',
                body: JSON.stringify({
                    uids,
                    date_from: dateFrom || null,
                    date_to: dateTo || null
                })
            });
            const data = await response.json();
            if (data.success && Array.isArray(data.items)) {
                console.info('[CrossSync] modify-log lookup', { matched: data.items.length, uids: uids.length });
                return data.items;
            }
        } catch (e) {
            console.warn('[CrossSync] modify-log lookup failed', e);
        }
        return [];
    },
    
    /**
     * Aplicar enriquecimiento con logs y guardar items en la comparación.
     */
    finalizeCrossSyncComparison: async function(merged, logItems) {
        if (!merged) return merged;
        const items = logItems || await this.fetchCrossSyncModifyLogs(merged);
        this.applyCrossSyncModifyLogHints(merged, items);
        merged.modifyLogItems = items;
        return merged;
    },
    
    /**
     * ¿El estudio falta solo en el nodo PACS local?
     */
    isCrossSyncRowMissingOnlyOnLocal: function(row, localNodeId) {
        if (!localNodeId || !row?.nodeStates) return false;
        const stLocal = row.nodeStates[String(localNodeId)];
        if (!stLocal || stLocal.kind !== 'missing') return false;
        return Object.keys(row.nodeStates).some(nid => {
            if (String(nid) === String(localNodeId)) return false;
            const st = row.nodeStates[nid];
            return st && (st.kind === 'ok' || st.kind === 'partial');
        });
    },
    
    /**
     * ¿El estudio está solo en PACS local (ausente en remotos)?
     */
    isCrossSyncRowPresentOnlyOnLocal: function(row, localNodeId) {
        if (!localNodeId || !row?.nodeStates) return false;
        const stLocal = row.nodeStates[String(localNodeId)];
        if (!stLocal || (stLocal.kind !== 'ok' && stLocal.kind !== 'partial')) return false;
        return Object.keys(row.nodeStates).some(nid => {
            if (String(nid) === String(localNodeId)) return false;
            const st = row.nodeStates[nid];
            return st && st.kind === 'missing';
        });
    },
    
    /**
     * Construir hint de edición local para una fila Cross Sync.
     */
    buildCrossSyncModifyHint: function(row, logEntry, nodeIds) {
        const log = logEntry.log || logEntry;
        const role = logEntry.role;
        const pairedUid = logEntry.pairedUid
            || (role === 'old' ? log.new_study_instance_uid : log.old_study_instance_uid)
            || null;
        let tags = log.tags_requested;
        if (typeof tags === 'string') {
            try { tags = JSON.parse(tags); } catch (e) { tags = {}; }
        }
        tags = tags || {};
        const changedTags = Object.keys(tags).filter(k => !k.startsWith('_'));
        const localName = log.patient_name_pacs || tags.PatientName || '';
        const localId = log.patient_id_pacs || tags.PatientID || '';
        const status = log.status || '';
        const localNodeId = (nodeIds || []).find(nid => this.isLocalPacsNode(nid)) || null;
        const running = ['orthanc_running', 'pending_resolution', 'reconciling'].includes(status);
        let badgeLabel = role === 'old' ? 'UID anterior' : 'Copia local';
        if (running) badgeLabel = 'Editando…';
        let tooltip = '';
        if (role === 'old') {
            tooltip = 'Estudio editado en PACS Manager.';
            if (localName) tooltip += ` Datos actuales en PACS local: ${localName}.`;
            if (pairedUid) tooltip += ` Nuevo UID: ${this.shortStudyUid(pairedUid)}.`;
        } else {
            tooltip = 'Copia creada por edición en PACS Manager.';
            if (pairedUid) tooltip += ` UID anterior en remotos: ${this.shortStudyUid(pairedUid)}.`;
        }
        if (changedTags.length) {
            tooltip += ` Campos modificados: ${changedTags.join(', ')}.`;
        }
        return {
            logId: log.id,
            role,
            pairedUid: pairedUid ? String(pairedUid) : null,
            status,
            localPatientName: localName,
            localPatientId: localId,
            changedTags,
            badgeLabel,
            tooltip,
            originalDeleted: log.original_deleted,
            localNodeId: localNodeId ? String(localNodeId) : null,
            createdAt: log.created_at || null
        };
    },
    
    /**
     * Ajustar resumen de fila cuando hay edición local registrada.
     */
    applyCrossSyncModifySummaryToRow: function(row, hint) {
        if (!row || !hint) return;
        row.hasLocalModify = true;
        row.modifyRole = hint.role;
        const running = ['orthanc_running', 'pending_resolution', 'reconciling'].includes(hint.status);
        if (hint.role === 'old') {
            if (running) {
                row.summary = 'edición en curso (UID anterior)';
            } else if (this.isCrossSyncRowMissingOnlyOnLocal(row, hint.localNodeId)) {
                row.summary = 'editado en PACS local';
            } else if (row.hasMissing) {
                row.summary = 'editado en PACS local (UID anterior)';
            }
            row.summaryClass = 'info';
        } else if (hint.role === 'new') {
            if (running) {
                row.summary = 'edición en curso (copia local)';
            } else if (this.isCrossSyncRowPresentOnlyOnLocal(row, hint.localNodeId)) {
                row.summary = 'copia local post-edición';
            } else if (row.hasMissing) {
                row.summary = 'copia local post-edición';
            }
            row.summaryClass = 'info';
        }
        if (row.summaryClass === 'info') {
            row.sortKey = 0;
        }
    },
    
    /**
     * Enriquecer filas Cross Sync con pares viejo/nuevo de pacs_study_modify_log.
     */
    applyCrossSyncModifyLogHints: function(merged, logItems) {
        if (!merged || !Array.isArray(merged.rows)) return merged;
        merged.modifyLogByUid = merged.modifyLogByUid || {};
        if (!logItems?.length) return merged;
        
        const uidMap = {};
        const isNewerLog = (a, b) => {
            const ta = Date.parse(a?.created_at || '') || 0;
            const tb = Date.parse(b?.created_at || '') || 0;
            return ta >= tb;
        };
        
        logItems.forEach(log => {
            const oldUid = String(log.old_study_instance_uid || '').trim();
            const newUid = String(log.new_study_instance_uid || '').trim();
            if (oldUid) {
                if (!uidMap[oldUid] || isNewerLog(log, uidMap[oldUid].log)) {
                    uidMap[oldUid] = { log, role: 'old', pairedUid: newUid || null };
                }
            }
            if (newUid) {
                if (!uidMap[newUid] || isNewerLog(log, uidMap[newUid].log)) {
                    uidMap[newUid] = { log, role: 'new', pairedUid: oldUid || null };
                }
            }
        });
        
        merged.rows.forEach(row => {
            const uid = String(row.StudyInstanceUID || '').trim();
            const entry = uidMap[uid];
            if (!entry) return;
            row.modifyHint = this.buildCrossSyncModifyHint(row, entry, merged.nodeIds);
            merged.modifyLogByUid[uid] = entry;
            this.applyCrossSyncModifySummaryToRow(row, row.modifyHint);
        });
        return merged;
    },
    
    /**
     * Badge HTML para fila con edición local detectada.
     */
    getCrossSyncModifyBadgeHtml: function(row) {
        if (!row?.modifyHint) return '';
        const h = row.modifyHint;
        const title = this.escapeHtml(h.tooltip || '');
        return ` <span class="badge bg-info text-dark align-middle cross-sync-modify-badge" title="${title}"><i class="fas fa-edit fa-xs me-1"></i>${this.escapeHtml(h.badgeLabel || 'Editado')}</span>`;
    },
    
    /**
     * Subtítulo con datos actuales en PACS local (UID anterior).
     */
    getCrossSyncModifyLocalNameHintHtml: function(row) {
        const h = row?.modifyHint;
        if (!h || h.role !== 'old') return '';
        const parts = [];
        if (h.localPatientName) parts.push(this.escapeHtml(h.localPatientName));
        if (h.localPatientId) parts.push(`ID ${this.escapeHtml(h.localPatientId)}`);
        if (!parts.length) return '';
        return `<small class="text-info d-block cross-sync-modify-local-hint">→ Local: ${parts.join(' · ')}</small>`;
    },
    
    /**
     * Unir respuestas por StudyInstanceUID y calcular discrepancias
     */
    buildCrossSyncMerge: function(nodeIds, perNode, nodeLabels, nodeErrors) {
        const uidMapEl = {};
        
        nodeIds.forEach(nid => {
            const rows = perNode[nid] || [];
            rows.forEach(row => {
                const uid = row.StudyInstanceUID;
                if (!uid) return;
                if (!uidMapEl[uid]) {
                    uidMapEl[uid] = {
                        StudyInstanceUID: uid,
                        meta: { ...row },
                        byNode: {}
                    };
                }
                const counts = this.parseStudyCounts(row);
                uidMapEl[uid].byNode[nid] = {
                    series: counts.series,
                    instances: counts.instances,
                    modalities: this.parseStudyModalities(row.ModalitiesInStudy)
                };
                const m = uidMapEl[uid].meta;
                if (!m.PatientName && row.PatientName) m.PatientName = row.PatientName;
                if (!m.PatientID && row.PatientID) m.PatientID = row.PatientID;
                if (!m.StudyDate && row.StudyDate) m.StudyDate = row.StudyDate;
                if (!m.ModalitiesInStudy && row.ModalitiesInStudy) m.ModalitiesInStudy = row.ModalitiesInStudy;
            });
        });
        
        const rows = Object.keys(uidMapEl).map(uid => {
            const entry = uidMapEl[uid];
            const availableNonError = nodeIds.filter(nid => !nodeErrors[nid] && !!entry.byNode[nid]);
            const baselineCandidates = availableNonError.filter(nid => !this.isLocalPacsNode(nid));
            const pool = baselineCandidates.length ? baselineCandidates : availableNonError;
            const treatExtrasAsAligned = this.isCrossSyncTreatExtrasAlignedEnabled();
            let baselineNodeId = null;
            pool.forEach(nid => {
                if (!baselineNodeId) {
                    baselineNodeId = nid;
                    return;
                }
                const a = entry.byNode[baselineNodeId];
                const b = entry.byNode[nid];
                if (!a || !b) return;
                const replace = treatExtrasAsAligned
                    ? (b.instances < a.instances || (b.instances === a.instances && b.series < a.series))
                    : (b.instances > a.instances || (b.instances === a.instances && b.series > a.series));
                if (replace) {
                    baselineNodeId = nid;
                }
            });
            const baseline = baselineNodeId ? entry.byNode[baselineNodeId] : null;
            const refS = baseline ? baseline.series : 0;
            const refI = baseline ? baseline.instances : 0;
            const refMods = new Set((baseline && Array.isArray(baseline.modalities)) ? baseline.modalities : []);
            
            let hasMissing = false;
            let hasPartial = false;
            let hasExtra = false;
            const nodeStates = {};
            
            nodeIds.forEach(nid => {
                if (nodeErrors[nid]) {
                    nodeStates[nid] = { kind: 'error' };
                    hasMissing = true;
                    return;
                }
                const cell = entry.byNode[nid];
                if (!cell) {
                    nodeStates[nid] = { kind: 'missing' };
                    hasMissing = true;
                } else if (refI > 0 && (cell.instances < refI || cell.series < refS)) {
                    nodeStates[nid] = { kind: 'partial', ...cell };
                    hasPartial = true;
                } else {
                    const extraSeries = Math.max(0, (cell.series || 0) - refS);
                    const extraInstances = Math.max(0, (cell.instances || 0) - refI);
                    const modalityExtras = (cell.modalities || []).filter(m => !refMods.has(m));
                    const extras = {
                        series: extraSeries,
                        instances: extraInstances,
                        modalities: modalityExtras
                    };
                    const hasNodeExtra = (extraSeries > 0 || extraInstances > 0 || modalityExtras.length > 0);
                    if (hasNodeExtra) hasExtra = true;
                    nodeStates[nid] = { kind: 'ok', ...cell, extras: hasNodeExtra ? extras : null };
                }
            });
            
            let summary = 'alineado';
            let summaryClass = 'success';
            if (hasMissing && hasPartial) {
                summary = 'ausente / parcial';
                summaryClass = 'danger';
            } else if (hasMissing) {
                summary = 'ausente en algún nodo';
                summaryClass = 'warning';
            } else if (hasPartial) {
                summary = 'conteos distintos';
                summaryClass = 'warning';
            } else if (hasExtra) {
                summary = 'alineado + extras';
                summaryClass = 'success';
            }
            
            const sortKey = summary === 'alineado' ? 2 : (hasMissing ? 0 : 1);
            
            return {
                StudyInstanceUID: uid,
                meta: entry.meta,
                nodeStates,
                maxSeries: refS,
                maxInstances: refI,
                baselineNodeId: baselineNodeId || null,
                summary,
                summaryClass,
                sortKey,
                hasMissing,
                hasPartial,
                hasExtra
            };
        });
        
        rows.sort((a, b) => {
            if (a.sortKey !== b.sortKey) return a.sortKey - b.sortKey;
            const na = (a.meta.PatientName || '').toUpperCase();
            const nb = (b.meta.PatientName || '').toUpperCase();
            return na.localeCompare(nb);
        });
        
        const absent = rows.filter(r => r.hasMissing).length;
        const incomplete = rows.filter(r => r.hasPartial && !r.hasMissing).length;
        const aligned = rows.filter(r => !r.hasMissing && !r.hasPartial).length;
        const stats = {
            total: rows.length,
            absent,
            incomplete,
            aligned
        };
        
        return {
            rows,
            nodeIds,
            nodeLabels,
            nodeErrors,
            rawPerNode: perNode,
            fetchedAt: Date.now(),
            stats
        };
    },
    
    shortStudyUid: function(uid) {
        if (!uid || uid.length < 20) return uid || '—';
        return uid.substring(0, 10) + '…' + uid.substring(uid.length - 8);
    },
    
    /**
     * Fila de Cross Sync coincide con el filtro local (paciente, ID, fecha, UID, resumen, modalidad…)
     */
    rowMatchesCrossSyncLocalFilter: function(r, term) {
        if (!term) return true;
        const m = r.meta || {};
        const dateFmt = this.formatStudyDate(m.StudyDate || '');
        const timeFmt = this.formatStudyTime(m.StudyTime);
        const hay = [
            m.PatientName,
            m.PatientID,
            m.StudyDate,
            dateFmt,
            m.StudyTime,
            timeFmt,
            r.StudyInstanceUID,
            m.ModalitiesInStudy,
            m.StudyDescription,
            r.summary,
            r.modifyHint?.localPatientName,
            r.modifyHint?.badgeLabel,
            r.modifyHint?.tooltip,
            r.modifyHint?.pairedUid,
            'editado',
            'modificado',
            'copia local'
        ].filter(Boolean).join(' ').toLowerCase();
        return hay.includes(term);
    },
    
    /**
     * Texto de ayuda bajo el filtro local de Cross Sync
     */
    updateCrossSyncLocalSearchInfo: function(visible, afterDiff, total, diffOnly, term, statusLabel) {
        const el = document.getElementById('crossSyncLocalSearchInfo');
        if (!el) return;
        const statusHint = statusLabel ? ` y estado: ${statusLabel}` : '';
        if (term) {
            if (visible === 0) {
                el.textContent = `Ninguna fila coincide con «${term}» (${afterDiff} filas en la lista actual${statusHint}).`;
                el.className = 'text-danger d-block mt-1';
            } else {
                el.textContent = `Mostrando ${visible} de ${afterDiff} fila${afterDiff === 1 ? '' : 's'} (tras “solo diferencias”${diffOnly ? ' activo' : ''}${statusHint}).`;
                el.className = 'text-muted d-block mt-1';
            }
            return;
        }
        if (diffOnly) {
            el.textContent = `${afterDiff} fila${afterDiff === 1 ? '' : 's'} tras “solo diferencias”, de ${total} estudios en la comparación${statusHint}. Escriba para acotar más.`;
        } else {
            el.textContent = `${total} estudios en la comparación${statusHint}. Escriba para filtrar esta tabla (paciente, ID, fecha, UID, resumen…).`;
        }
        el.className = 'text-muted d-block mt-1';
    },
    
    /**
     * Botón limpiar del filtro local Cross Sync
     */
    updateCrossSyncLocalSearchChrome: function() {
        const clearBtn = document.getElementById('clearCrossSyncLocalSearch');
        const has = !!(this.crossSyncLocalSearchTerm && this.crossSyncLocalSearchTerm.length);
        if (clearBtn) clearBtn.style.display = has ? '' : 'none';
    },
    
    /**
     * Obtener nodo origen sugerido para recuperar un estudio desde Cross Sync.
     */
    getCrossSyncRetrieveCandidateNodeIds: function(row) {
        const comp = this.crossSyncComparison;
        if (!row || !comp || !Array.isArray(comp.nodeIds)) return null;
        if (this.studyUidHasActiveJob(row.StudyInstanceUID)) return [];
        const candidates = [];
        for (const nidRaw of comp.nodeIds) {
            const nid = String(nidRaw);
            const st = row.nodeStates ? row.nodeStates[nid] : null;
            if (!st) continue;
            if (!(st.kind === 'ok' || st.kind === 'partial')) continue;
            if (this.isLocalPacsNode(nid)) continue;
            if (!this.nodeAllowsRetrieve(nid)) continue;
            candidates.push(nid);
        }
        return candidates;
    },
    
    getCrossSyncRetrieveCandidateNodeId: function(row) {
        const candidates = this.getCrossSyncRetrieveCandidateNodeIds(row);
        return candidates && candidates.length ? candidates[0] : null;
    },
    
    /**
     * Configuración de lote desde UI.
     */
    getCrossSyncBatchConfig: function() {
        const modeRaw = document.getElementById('crossSyncBatchMode')?.value || 'auto';
        const mode = (modeRaw === 'fixed') ? 'fixed' : 'auto';
        const cRaw = parseInt(document.getElementById('crossSyncBatchConcurrency')?.value || '2', 10);
        const concurrency = (Number.isFinite(cRaw) && cRaw >= 1 && cRaw <= 4) ? cRaw : 2;
        const mpnRaw = parseInt(document.getElementById('crossSyncBatchMaxPerNode')?.value || '1', 10);
        const maxPerNode = (Number.isFinite(mpnRaw) && mpnRaw >= 1 && mpnRaw <= 2) ? mpnRaw : 1;
        const fixedNodeId = String(document.getElementById('crossSyncBatchFixedNode')?.value || '').trim();
        return { mode, concurrency, maxPerNode, fixedNodeId };
    },
    
    /**
     * Mantener controles de lote sincronizados (modo, nodo fijo, disponibilidad).
     */
    updateCrossSyncBatchControls: function() {
        const modeEl = document.getElementById('crossSyncBatchMode');
        const fixedEl = document.getElementById('crossSyncBatchFixedNode');
        if (!modeEl || !fixedEl) return;
        const comp = this.crossSyncComparison;
        const nodeIds = comp && Array.isArray(comp.nodeIds) ? comp.nodeIds.map(String) : [];
        const options = nodeIds.filter(nid => !this.isLocalPacsNode(nid) && this.nodeAllowsRetrieve(nid));
        const current = String(fixedEl.value || '');
        fixedEl.innerHTML = options.map(nid => {
            const label = this.escapeHtml((comp?.nodeLabels && comp.nodeLabels[nid]) ? comp.nodeLabels[nid] : nid);
            return `<option value="${this.escapeHtml(nid)}">${label}</option>`;
        }).join('');
        if (options.includes(current)) fixedEl.value = current;
        else if (options.length) fixedEl.value = options[0];
        const fixedMode = modeEl.value === 'fixed';
        fixedEl.classList.toggle('d-none', !fixedMode);
        if (fixedMode && options.length === 0) {
            modeEl.value = 'auto';
            fixedEl.classList.add('d-none');
        }
    },
    
    /**
     * Elegir nodo de origen por fila según modo de lote.
     */
    pickCrossSyncRetrieveNodeForRow: function(row, config, assignedByNode) {
        const candidates = this.getCrossSyncRetrieveCandidateNodeIds(row) || [];
        if (!candidates.length) return null;
        if (config.mode === 'fixed') {
            if (config.fixedNodeId && candidates.includes(config.fixedNodeId)) {
                return { primary: config.fixedNodeId, fallbacks: candidates.filter(n => n !== config.fixedNodeId) };
            }
            return null;
        }
        const ordered = [...candidates].sort((a, b) => (assignedByNode[a] || 0) - (assignedByNode[b] || 0));
        return { primary: ordered[0], fallbacks: ordered.slice(1) };
    },
    
    /**
     * Actualiza el estado visual de selección para recuperación en lote.
     */
    updateCrossSyncSelectionUi: function() {
        const comp = this.crossSyncComparison;
        const allRows = comp && Array.isArray(comp.rows) ? comp.rows : [];
        const eligible = allRows.filter(r => (this.getCrossSyncRetrieveCandidateNodeIds(r) || []).length > 0);
        const selectedEligible = eligible.filter(r => !!this.crossSyncSelectedStudies[String(r.StudyInstanceUID || '')]).length;
        const selectedTotal = Object.keys(this.crossSyncSelectedStudies || {}).filter(uid => !!uid).length;
        const info = document.getElementById('crossSyncSelectionInfo');
        if (info) {
            info.textContent = `${selectedEligible} seleccionados${selectedTotal !== selectedEligible ? ` (${selectedTotal} totales)` : ''}`;
        }
        const btn = document.getElementById('btnCrossSyncRetrieveSelected');
        if (btn) {
            const running = !!(this.crossSyncBatchRetrieve && this.crossSyncBatchRetrieve.running);
            btn.disabled = running || selectedEligible < 1;
            btn.innerHTML = running
                ? '<i class="fas fa-spinner fa-spin me-1"></i> Procesando lote…'
                : '<i class="fas fa-download me-1"></i> Recuperar seleccionados';
        }
    },
    
    /**
     * Seleccionar filas visibles (solo las que permiten recuperar).
     */
    selectVisibleCrossSyncRows: function() {
        const rows = this.crossSyncLastRenderedRows || [];
        rows.forEach(r => {
            const uid = String(r.StudyInstanceUID || '');
            if (!uid) return;
            if (!this.getCrossSyncRetrieveCandidateNodeId(r)) return;
            this.crossSyncSelectedStudies[uid] = true;
        });
        this.renderCrossSyncTable();
    },
    
    /**
     * Limpiar selección de recuperación en lote.
     */
    clearCrossSyncSelection: function() {
        this.crossSyncSelectedStudies = {};
        this.renderCrossSyncTable();
    },
    
    /**
     * Crear job de recuperación sin modal (uso en lotes).
     */
    createRetrieveJob: async function(studyInstanceUID, nodeId) {
        const response = await fetch(`${this.apiBase}/retrieve.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                node_id: String(nodeId),
                StudyInstanceUIDs: [String(studyInstanceUID)]
            })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data?.error || data?.message || `HTTP ${response.status}`);
        }
        const jobData = data.data || data;
        const jobId = jobData.job_id;
        if (!jobId) {
            throw new Error('No se recibió job_id');
        }
        return { jobId: String(jobId) };
    },
    
    /**
     * Recuperación por lotes de estudios seleccionados en Cross Sync.
     */
    retrieveSelectedCrossSyncStudies: async function() {
        if (this.crossSyncBatchRetrieve && this.crossSyncBatchRetrieve.running) {
            this.showWarning('Ya hay una recuperación en lote en curso.');
            return;
        }
        const comp = this.crossSyncComparison;
        if (!comp || !Array.isArray(comp.rows) || comp.rows.length < 1) {
            this.showError('No hay resultados para recuperar.');
            return;
        }
        const byUid = {};
        comp.rows.forEach(r => { byUid[String(r.StudyInstanceUID || '')] = r; });
        const batchConfig = this.getCrossSyncBatchConfig();
        const assignedByNode = {};
        const selectedUids = Object.keys(this.crossSyncSelectedStudies || {}).filter(uid => !!this.crossSyncSelectedStudies[uid]);
        const skippedJob = [];
        const items = selectedUids.map(uid => {
            if (this.studyUidHasActiveJob(uid)) {
                skippedJob.push(uid);
                return null;
            }
            const row = byUid[uid];
            if (!row) return null;
            const pick = this.pickCrossSyncRetrieveNodeForRow(row, batchConfig, assignedByNode);
            if (!pick || !pick.primary) return null;
            assignedByNode[pick.primary] = (assignedByNode[pick.primary] || 0) + 1;
            return {
                uid,
                nodeId: pick.primary,
                fallbackNodeIds: pick.fallbacks || [],
                row
            };
        }).filter(Boolean);
        
        if (skippedJob.length) {
            skippedJob.forEach(uid => { delete this.crossSyncSelectedStudies[uid]; });
            this.showWarning(`${skippedJob.length} estudio(s) omitido(s): ya tienen un job pendiente o en ejecución (revise la pestaña Jobs).`);
        }
        
        if (items.length < 1) {
            this.showWarning('No hay estudios seleccionados con un nodo recuperable.');
            this.renderCrossSyncTable();
            return;
        }
        
        const concurrency = batchConfig.concurrency;
        const maxPerNode = batchConfig.maxPerNode;
        const nodeLabels = (this.crossSyncComparison && this.crossSyncComparison.nodeLabels) ? this.crossSyncComparison.nodeLabels : {};
        const assignmentSummary = Object.keys(assignedByNode).sort((a, b) => (assignedByNode[b] || 0) - (assignedByNode[a] || 0))
            .map(nid => `${nodeLabels[nid] || nid}: ${assignedByNode[nid] || 0}`)
            .join(', ');
        const confirmed = await this.showConfirm(
            'Recuperación en lote',
            `Se crearán ${items.length} jobs de recuperación (concurrencia ${concurrency}, máx/nodo ${maxPerNode}, modo ${batchConfig.mode === 'fixed' ? 'nodo fijo' : 'auto balanceado'}).` +
            `${assignmentSummary ? `\n\nDistribución prevista: ${assignmentSummary}` : ''}\n\n¿Desea continuar?`,
            'Iniciar lote',
            'Cancelar'
        );
        if (!confirmed) return;
        
        const alerts = document.getElementById('crossSyncAlerts');
        const state = {
            running: true,
            total: items.length,
            done: 0,
            ok: 0,
            fail: 0,
            errors: []
        };
        this.crossSyncBatchRetrieve = state;
        this.updateCrossSyncSelectionUi();
        const renderProgress = () => {
            if (!alerts) return;
            const tone = state.fail > 0 ? 'warning' : 'info';
            alerts.innerHTML = `
                <div class="alert alert-${tone} py-2 mb-2">
                    <strong>Recuperación en lote:</strong> ${state.done}/${state.total} procesados ·
                    <span class="text-success">OK ${state.ok}</span> ·
                    <span class="text-danger">Error ${state.fail}</span>
                </div>
            `;
        };
        renderProgress();
        
        const pending = [...items];
        const inFlightByNode = {};
        const claimNextItem = () => {
            for (let i = 0; i < pending.length; i++) {
                const item = pending[i];
                const node = String(item.nodeId);
                const currentNodeInFlight = inFlightByNode[node] || 0;
                if (currentNodeInFlight >= maxPerNode) continue;
                pending.splice(i, 1);
                inFlightByNode[node] = currentNodeInFlight + 1;
                return item;
            }
            return null;
        };
        const worker = async () => {
            while (true) {
                const item = claimNextItem();
                if (!item) {
                    if (pending.length === 0) return;
                    await new Promise(resolve => setTimeout(resolve, 120));
                    continue;
                }
                try {
                    await this.createRetrieveJob(item.uid, item.nodeId);
                    state.ok += 1;
                } catch (err) {
                    let recovered = false;
                    for (const fb of (item.fallbackNodeIds || [])) {
                        try {
                            await this.createRetrieveJob(item.uid, fb);
                            state.ok += 1;
                            recovered = true;
                            break;
                        } catch (fbErr) {
                            // probar siguiente fallback
                        }
                    }
                    if (!recovered) {
                        state.fail += 1;
                        state.errors.push(`${item.uid}: ${err.message || err}`);
                    }
                } finally {
                    const node = String(item.nodeId);
                    inFlightByNode[node] = Math.max(0, (inFlightByNode[node] || 1) - 1);
                    state.done += 1;
                    renderProgress();
                }
            }
        };
        
        const workers = [];
        for (let i = 0; i < Math.min(concurrency, items.length); i++) {
            workers.push(worker());
        }
        await Promise.all(workers);
        
        state.running = false;
        this.crossSyncBatchRetrieve = null;
        this.loadJobs();
        if (state.fail > 0) {
            this.showWarning(`Lote despachado: ${state.ok} jobs iniciados, ${state.fail} con error al crear job. Revise la pestaña Jobs para el progreso de transferencia.`);
        } else {
            this.showSuccess(`Lote despachado: ${state.ok} jobs iniciados. La transferencia continúa en segundo plano; siga el avance en la pestaña Jobs.`);
        }
        this.updateCrossSyncSelectionUi();
    },
    
    /**
     * Estado actual del filtro por chips en Cross Sync
     */
    getCrossSyncResultStatusFilter: function() {
        const active = document.querySelector('.cross-sync-status-chip.active');
        const status = active?.dataset?.status || 'all';
        return ['all', 'absent', 'incomplete', 'aligned'].includes(status) ? status : 'all';
    },
    
    /**
     * Marcar chip activo de filtro por estado en Cross Sync
     */
    setCrossSyncResultStatusFilter: function(status) {
        const next = ['all', 'absent', 'incomplete', 'aligned'].includes(status) ? status : 'all';
        document.querySelectorAll('.cross-sync-status-chip').forEach(btn => {
            const on = (btn.dataset.status || 'all') === next;
            btn.classList.toggle('active', on);
            btn.classList.toggle('border-dark', on);
            btn.classList.toggle('fw-semibold', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    },
    
    /**
     * Iniciar medición por polling: C-FIND Study con solo StudyInstanceUID en cada nodo.
     */
    startReplicationWatch: function(studyUID, nodeIdsParam) {
        const uid = String(studyUID || '').trim();
        if (!uid) {
            this.showError('StudyInstanceUID no válido');
            return;
        }
        let nodeIds = nodeIdsParam;
        if (!nodeIds || !nodeIds.length) {
            const comp = this.crossSyncComparison;
            if (comp && comp.nodeIds && comp.nodeIds.length) {
                nodeIds = comp.nodeIds;
            }
        }
        if (!nodeIds || nodeIds.length < 1) {
            this.showError('No hay nodos para medir. Ejecute antes «Comparar nodos».');
            return;
        }
        this.stopReplicationWatch('restart');
        const labels = {};
        nodeIds.forEach(nid => {
            const n = (this.nodes || []).find(x => String(x.id) === String(nid));
            labels[String(nid)] = (n && n.name) ? n.name : String(nid);
        });
        const pollSel = document.getElementById('crossSyncWatchInterval');
        const pollSec = pollSel ? (parseInt(pollSel.value, 10) || 10) : 10;
        this.replicationWatch = {
            studyUID: uid,
            nodeIds: nodeIds.map(String),
            nodeLabels: labels,
            pollSec,
            effectivePollSec: pollSec,
            timerId: null,
            tickCount: 0,
            inFlight: false,
            lastByNode: {},
            changesCursor: 0,
            startedAt: Date.now(),
            maxDurationMs: 20 * 60 * 1000, // guardrail: 20 min por sesión on-demand
            lastTickAt: null,
            lastLocalInstances: 0,
            localSpeedInstPerSec: 0,
            peakLocalSpeedInstPerSec: 0,
            speedSamplesSum: 0,
            speedSamplesCount: 0,
            localFirstSeenAt: null,
            lastProgressAt: null,
            noProgressTicks: 0,
            stallThresholdTicks: 4,
            stalled: false,
            etaSec: null,
            remoteFirstSeenByNode: {},
            noActivityTicks: 0,
            remoteProbeEveryTicks: Math.max(1, Math.round(15 / Math.max(3, pollSec)))
        };
        const banner = document.getElementById('crossSyncWatchBanner');
        const card = document.getElementById('crossSyncWatchCard');
        const uidDisp = document.getElementById('crossSyncWatchUidDisplay');
        if (banner) banner.classList.remove('d-none');
        if (card) card.classList.remove('d-none');
        if (uidDisp) uidDisp.textContent = uid;
        this.tickReplicationWatch().catch(err => console.error('[replicationWatch] primer ciclo', err));
        this.restartReplicationWatchTimer();
        this.showInfo(`Medición iniciada (on-demand): base ${pollSec}s, con ajuste adaptativo por actividad. Tiempo máximo por sesión: 20 min.`);
    },
    
    /**
     * Detener polling de medición
     */
    stopReplicationWatch: function(reason = 'user') {
        const ended = this.replicationWatch ? { ...this.replicationWatch } : null;
        if (this.replicationWatch && this.replicationWatch.timerId) {
            clearInterval(this.replicationWatch.timerId);
        }
        this.replicationWatch = null;
        const banner = document.getElementById('crossSyncWatchBanner');
        const card = document.getElementById('crossSyncWatchCard');
        const tbody = document.getElementById('crossSyncWatchResultsBody');
        if (banner) banner.classList.add('d-none');
        if (card) card.classList.add('d-none');
        if (tbody) tbody.innerHTML = '';
        if (ended && (ended.tickCount || 0) > 0) {
            this.persistReplicationWatchSession(ended, reason).catch(err => {
                console.warn('[replicationInsights][persist]', err);
            });
        }
    },
    
    restartReplicationWatchTimer: function() {
        const w = this.replicationWatch;
        if (!w) return;
        if (w.timerId) {
            clearInterval(w.timerId);
            w.timerId = null;
        }
        const ms = Math.max(3, w.effectivePollSec || w.pollSec) * 1000;
        w.timerId = setInterval(() => {
            this.tickReplicationWatch().catch(err => console.error('[replicationWatch] interval', err));
        }, ms);
    },
    
    /**
     * Extrae filas del JSON de find.php (mismo envoltorio que Cross Sync)
     */
    extractFindResponseRows: function(data) {
        if (!data || !data.success || !data.data) return [];
        const inner = data.data;
        if (Array.isArray(inner.data)) return inner.data;
        if (Array.isArray(inner)) return inner;
        return [];
    },
    
    /**
     * Elige la fila del estudio que coincide con el UID (por si el backend devuelve varias)
     */
    pickStudyRowForWatch: function(rows, studyUID) {
        if (!rows || !rows.length) return null;
        const uid = String(studyUID || '').trim();
        if (!uid) return rows[0];
        const exact = rows.find(r => String(r.StudyInstanceUID || '').trim() === uid);
        return exact || rows[0];
    },
    
    /**
     * Poll local: usa /changes + conteo local por UID para calcular velocidad real de llegada.
     */
    pollLocalReplicationMetrics: async function(w) {
        const response = await fetch(`${this.apiBase}/replication-metrics.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            credentials: 'include',
            body: JSON.stringify({
                study_uid: w.studyUID,
                since: w.changesCursor || 0,
                limit: 300
            })
        });
        const data = await response.json();
        if (!data.success || !data.data) {
            throw new Error(data.error || data.message || 'Error en replication-metrics');
        }
        const nowMs = Date.now();
        const localInstances = parseInt(data.data.local_instances, 10) || 0;
        const prevInstances = parseInt(w.lastLocalInstances, 10) || 0;
        const prevTickAt = w.lastTickAt || nowMs;
        const dt = Math.max(0.001, (nowMs - prevTickAt) / 1000);
        const delta = Math.max(0, localInstances - prevInstances);
        w.localSpeedInstPerSec = delta / dt;
        if (w.localSpeedInstPerSec > (w.peakLocalSpeedInstPerSec || 0)) {
            w.peakLocalSpeedInstPerSec = w.localSpeedInstPerSec;
        }
        w.speedSamplesSum = (w.speedSamplesSum || 0) + w.localSpeedInstPerSec;
        w.speedSamplesCount = (w.speedSamplesCount || 0) + 1;
        w.lastLocalInstances = localInstances;
        w.lastTickAt = nowMs;
        w.changesCursor = parseInt(data.data.changes_last, 10) || w.changesCursor || 0;
        if (!w.localFirstSeenAt && localInstances > 0) {
            w.localFirstSeenAt = nowMs;
        }
        if (delta > 0) {
            w.lastProgressAt = nowMs;
            w.noProgressTicks = 0;
            w.stalled = false;
        } else {
            w.noProgressTicks = (w.noProgressTicks || 0) + 1;
        }
        return data.data;
    },
    
    /**
     * Ajusta cadencia en función de actividad para evitar carga innecesaria.
     */
    updateAdaptiveWatchCadence: function(w, localMetrics) {
        const localInstances = parseInt(localMetrics?.local_instances, 10) || 0;
        const matched = parseInt(localMetrics?.matched_new_instances, 10) || 0;
        const isActive = matched > 0 || w.localSpeedInstPerSec > 0.01 || localInstances > 0;
        if (isActive) {
            w.noActivityTicks = 0;
            const target = Math.max(3, w.pollSec);
            if (target !== w.effectivePollSec) {
                w.effectivePollSec = target;
                this.restartReplicationWatchTimer();
            }
            return;
        }
        w.noActivityTicks = (w.noActivityTicks || 0) + 1;
        const base = Math.max(3, w.pollSec);
        const backoff = Math.min(30, base + (w.noActivityTicks * 2));
        if (backoff !== w.effectivePollSec) {
            w.effectivePollSec = backoff;
            this.restartReplicationWatchTimer();
        }
    },
    
    /**
     * Sondeo remoto espaciado para detectar primer "seen" por nodo y conteos actuales.
     */
    probeRemoteNodesForWatch: async function(w) {
        const query = {
            Level: 'Study',
            Query: { StudyInstanceUID: w.studyUID }
        };
        await Promise.all(w.nodeIds.map(async (nid) => {
            const key = String(nid);
            try {
                const response = await fetch(`${this.apiBase}/find.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    credentials: 'include',
                    body: JSON.stringify({
                        node_id: nid,
                        query,
                        no_cache: true,
                        request_source: 'replication_watch_remote_probe'
                    })
                });
                let data;
                try {
                    data = await response.json();
                } catch (parseErr) {
                    console.warn('[replicationWatch] JSON inválido', nid, parseErr);
                    w.lastByNode[key] = {
                        series: 0,
                        instances: 0,
                        error: `HTTP ${response.status}: respuesta no JSON`,
                        at: Date.now()
                    };
                    return;
                }
                if (!data.success) {
                    w.lastByNode[key] = {
                        series: 0,
                        instances: 0,
                        error: data.error || data.message || 'Error',
                        at: Date.now()
                    };
                    return;
                }
                const rows = this.extractFindResponseRows(data);
                const row = this.pickStudyRowForWatch(rows, w.studyUID);
                if (row) {
                    const c = this.parseStudyCounts(row);
                    w.lastByNode[key] = {
                        series: c.series,
                        instances: c.instances,
                        error: null,
                        at: Date.now()
                    };
                    if (!w.remoteFirstSeenByNode[key] && c.instances > 0) {
                        w.remoteFirstSeenByNode[key] = Date.now();
                    }
                } else {
                    w.lastByNode[key] = {
                        series: 0,
                        instances: 0,
                        error: null,
                        at: Date.now()
                    };
                }
            } catch (e) {
                console.warn('[replicationWatch][probe]', key, e);
                w.lastByNode[key] = {
                    series: 0,
                    instances: 0,
                    error: 'Red',
                    at: Date.now()
                };
            }
        }));
    },
    
    /**
     * Una ronda de C-FIND puntual por nodo para el UID en seguimiento
     */
    tickReplicationWatch: async function() {
        const w = this.replicationWatch;
        if (!w) return;
        if (w.inFlight) {
            console.info('[replicationWatch] Tick omitido (anterior aún en curso)');
            return;
        }
        w.inFlight = true;
        if ((Date.now() - (w.startedAt || Date.now())) > (w.maxDurationMs || 0)) {
            this.showWarning('Medición detenida automáticamente por tiempo máximo (20 min).');
            this.stopReplicationWatch('timeout');
            return;
        }
        w.tickCount += 1;
        console.info('[replicationWatch] Tick', {
            tick: w.tickCount,
            uid: w.studyUID,
            nodeIds: w.nodeIds
        });
        
        const meta = document.getElementById('crossSyncWatchMeta');
        try {
            const local = await this.pollLocalReplicationMetrics(w);
            const mustProbeRemotes = (w.tickCount === 1) || (w.tickCount % w.remoteProbeEveryTicks === 0);
            if (mustProbeRemotes) {
                await this.probeRemoteNodesForWatch(w);
            }
            this.updateAdaptiveWatchCadence(w, local);
            if (meta) {
                const speedTxt = Number.isFinite(w.localSpeedInstPerSec)
                    ? `${w.localSpeedInstPerSec.toFixed(2)} inst/s`
                    : '0.00 inst/s';
                const cadTxt = `${w.effectivePollSec || w.pollSec}s`;
                meta.textContent = `Ciclo ${w.tickCount} · ${new Date().toLocaleString('es-AR')} · Local ${local.local_instances || 0} inst · Vel ${speedTxt} · Cadencia ${cadTxt}`;
            }
        } catch (e) {
            console.warn('[replicationWatch][local-metrics]', e);
            // Fallback para no dejar el medidor sin datos si el endpoint nuevo no está disponible.
            await this.probeRemoteNodesForWatch(w);
            if (meta) {
                meta.textContent = `Ciclo ${w.tickCount} · Fallback remoto activo (métricas locales no disponibles)`;
            }
        } finally {
            w.inFlight = false;
        }
        
        this.updateReplicationWatchPanel();
    },
    
    /**
     * Actualizar tabla resumen del seguimiento
     */
    updateReplicationWatchPanel: function() {
        const w = this.replicationWatch;
        const tbody = document.getElementById('crossSyncWatchResultsBody');
        const lastAt = document.getElementById('crossSyncWatchLastAt');
        if (!w || !tbody) return;
        
        let maxI = 0;
        w.nodeIds.forEach(nid => {
            const cell = w.lastByNode[String(nid)];
            if (cell && !cell.error && cell.instances > maxI) {
                maxI = cell.instances;
            }
        });
        
        if (lastAt) {
            const speedTxt = Number.isFinite(w.localSpeedInstPerSec) ? w.localSpeedInstPerSec.toFixed(2) : '0.00';
            const localSeen = w.localFirstSeenAt ? ` · Local visto: ${new Date(w.localFirstSeenAt).toLocaleTimeString('es-AR')}` : '';
            const localInst = parseInt(w.lastLocalInstances, 10) || 0;
            const missing = maxI > 0 ? Math.max(0, maxI - localInst) : 0;
            w.etaSec = (missing > 0 && w.localSpeedInstPerSec > 0.01) ? (missing / w.localSpeedInstPerSec) : null;
            const etaTxt = Number.isFinite(w.etaSec) ? ` · ETA aprox: ${Math.ceil(w.etaSec)} s` : '';
            if (missing > 0 && (w.noProgressTicks || 0) >= (w.stallThresholdTicks || 4)) {
                w.stalled = true;
            }
            const stallTxt = w.stalled ? ' · ⚠ posible estancamiento' : '';
            lastAt.textContent = 'Actualizado: ' + new Date().toLocaleString('es-AR')
                + (maxI > 0 ? ` · Máx. instancias entre nodos: ${maxI}` : '')
                + ` · Vel local: ${speedTxt} inst/s`
                + localSeen;
            lastAt.textContent += etaTxt + stallTxt;
        }
        
        tbody.innerHTML = w.nodeIds.map(nid => {
            const key = String(nid);
            const name = this.escapeHtml(w.nodeLabels[key] || nid);
            const cell = w.lastByNode[key];
            if (!cell) {
                return `<tr><td>${name}</td><td class="text-end text-muted" colspan="4">…</td></tr>`;
            }
            if (cell.error) {
                return `<tr><td>${name}</td><td class="text-end">—</td><td class="text-end">—</td><td class="text-end">—</td><td><span class="text-danger">${this.escapeHtml(cell.error)}</span></td></tr>`;
            }
            const delta = maxI > 0 ? (maxI - cell.instances) : 0;
            let deltaHtml = '—';
            if (maxI > 0) {
                deltaHtml = delta === 0
                    ? '<span class="text-success">0</span>'
                    : `<span class="text-warning">${delta}</span>`;
            }
            let note = '—';
            if (maxI > 0) {
                if (cell.instances === 0) {
                    note = 'Estudio no presente o 0 instancias';
                } else if (delta === 0) {
                    note = 'Igual al máximo observado';
                } else {
                    note = 'Por debajo del máximo (réplica en curso o diferencias)';
                }
            }
            const remoteSeenAt = w.remoteFirstSeenByNode ? w.remoteFirstSeenByNode[key] : null;
            if (remoteSeenAt && w.localFirstSeenAt) {
                const lagSec = Math.max(0, (w.localFirstSeenAt - remoteSeenAt) / 1000);
                note += ` · Latencia a local: ${lagSec.toFixed(1)} s`;
            }
            return `<tr><td>${name}</td><td class="text-end">${cell.series}</td><td class="text-end">${cell.instances}</td><td class="text-end">${deltaHtml}</td><td><small>${note}</small></td></tr>`;
        }).join('');
    },
    
    persistReplicationWatchSession: async function(w, reason) {
        if (!w || !w.studyUID) return;
        if (reason === 'state_clear' || reason === 'restart') return;
        const started = w.startedAt || Date.now();
        const ended = Date.now();
        const durationSec = Math.max(0, Math.round((ended - started) / 1000));
        const avgSpeed = (w.speedSamplesCount || 0) > 0 ? (w.speedSamplesSum / w.speedSamplesCount) : 0;
        const nodes = (w.nodeIds || []).map(nid => {
            const key = String(nid);
            const remoteSeenAt = w.remoteFirstSeenByNode ? w.remoteFirstSeenByNode[key] : null;
            const lagSec = (remoteSeenAt && w.localFirstSeenAt)
                ? Math.max(0, (w.localFirstSeenAt - remoteSeenAt) / 1000)
                : null;
            const cell = w.lastByNode ? w.lastByNode[key] : null;
            return {
                node_id: Number.isFinite(parseInt(key, 10)) ? parseInt(key, 10) : null,
                node_name: w.nodeLabels ? (w.nodeLabels[key] || key) : key,
                remote_first_seen_at_ms: remoteSeenAt || null,
                lag_seconds: lagSec,
                final_instances: cell ? (parseInt(cell.instances, 10) || 0) : 0,
                had_error: !!(cell && cell.error)
            };
        });
        const payload = {
            study_uid: w.studyUID,
            started_at_ms: started,
            ended_at_ms: ended,
            duration_sec: durationSec,
            status: reason || 'completed',
            local_first_seen_at_ms: w.localFirstSeenAt || null,
            local_last_instances: parseInt(w.lastLocalInstances, 10) || 0,
            avg_speed_inst_sec: Number.isFinite(avgSpeed) ? avgSpeed : 0,
            peak_speed_inst_sec: Number.isFinite(w.peakLocalSpeedInstPerSec) ? w.peakLocalSpeedInstPerSec : 0,
            ticks_count: parseInt(w.tickCount, 10) || 0,
            notes: w.stalled ? 'possible_stall' : null,
            meta: {
                effective_poll_sec: w.effectivePollSec || w.pollSec,
                no_progress_ticks: w.noProgressTicks || 0,
                eta_sec: Number.isFinite(w.etaSec) ? w.etaSec : null
            },
            nodes
        };
        const response = await fetch(`${this.apiBase}/replication-sessions.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            cache: 'no-store',
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.error || data.message || 'No se pudo guardar sesión');
        }
        await this.loadReplicationInsights();
    },
    
    loadReplicationInsights: async function(daysParam, limitParam) {
        const days = Math.max(1, Math.min(30, parseInt(daysParam != null ? daysParam : this.replicationInsights.days, 10) || 7));
        const limit = Math.max(1, Math.min(20, parseInt(limitParam != null ? limitParam : this.replicationInsights.limit, 10) || 5));
        this.replicationInsights.days = days;
        this.replicationInsights.limit = limit;
        const response = await fetch(`${this.apiBase}/replication-sessions.php?mode=insights&days=${days}&limit=${limit}`, {
            method: 'GET',
            credentials: 'include',
            cache: 'no-store'
        });
        const data = await response.json();
        if (!data.success || !data.data) {
            throw new Error(data.error || data.message || 'No se pudieron cargar insights');
        }
        this.replicationInsights.recentSessions = data.data.recent_sessions || [];
        this.replicationInsights.latencyRanking = data.data.latency_ranking || [];
        this.replicationInsights.loadedAt = Date.now();
        this.renderReplicationInsights();
    },
    
    renderReplicationInsights: function() {
        const recentEl = document.getElementById('crossSyncRecentSessions');
        const rankingEl = document.getElementById('crossSyncLatencyRanking');
        const card = document.getElementById('crossSyncInsightsCard');
        const showWrap = document.getElementById('crossSyncInsightsShowWrap');
        const loadedAtEl = document.getElementById('crossSyncInsightsLoadedAt');
        const days = this.replicationInsights.days || 7;
        const limit = this.replicationInsights.limit || 5;
        const hidden = !!this.replicationInsights.hidden;
        const setBtn = (id, active) => {
            const b = document.getElementById(id);
            if (!b) return;
            b.classList.toggle('active', active);
            b.classList.toggle('btn-secondary', active);
            b.classList.toggle('btn-outline-secondary', !active);
        };
        setBtn('crossSyncInsightsDays1', days === 1);
        setBtn('crossSyncInsightsDays7', days === 7);
        setBtn('crossSyncInsightsDays30', days === 30);
        if (card) card.classList.toggle('d-none', hidden);
        if (showWrap) showWrap.classList.toggle('d-none', !hidden);
        const limitSel = document.getElementById('crossSyncInsightsLimit');
        if (limitSel && String(limitSel.value) !== String(limit)) {
            limitSel.value = String(limit);
        }
        if (card) {
            const titleEl = card.querySelector('.card-header .small strong');
            if (titleEl) {
                titleEl.textContent = `Insights de replicación (${days === 1 ? 'hoy' : `últimos ${days} días`})`;
            }
        }
        if (loadedAtEl) {
            loadedAtEl.textContent = this.replicationInsights.loadedAt
                ? `Actualizado: ${new Date(this.replicationInsights.loadedAt).toLocaleString('es-AR')}`
                : 'Actualizado: —';
        }
        if (!recentEl || !rankingEl) return;
        const recent = this.replicationInsights.recentSessions || [];
        const rank = this.replicationInsights.latencyRanking || [];
        if (!recent.length) {
            recentEl.innerHTML = '<div class="text-muted small">Sin sesiones recientes</div>';
        } else {
            recentEl.innerHTML = recent.map(s => {
                const uid = this.escapeHtml(this.shortStudyUid(s.study_instance_uid || ''));
                const dur = parseInt(s.duration_sec, 10) || 0;
                const avg = Number.isFinite(parseFloat(s.avg_speed_inst_sec)) ? parseFloat(s.avg_speed_inst_sec).toFixed(2) : '0.00';
                const peak = Number.isFinite(parseFloat(s.peak_speed_inst_sec)) ? parseFloat(s.peak_speed_inst_sec).toFixed(2) : '0.00';
                const status = this.escapeHtml(s.status || 'completed');
                return `<div class="small mb-1"><strong>${uid}</strong> · ${dur}s · avg ${avg} inst/s · pico ${peak} · <span class="text-muted">${status}</span></div>`;
            }).join('');
        }
        if (!rank.length) {
            rankingEl.innerHTML = '<div class="text-muted small">Sin datos de latencia todavía</div>';
        } else {
            rankingEl.innerHTML = rank.slice(0, 8).map(r => {
                const name = this.escapeHtml(r.node_name || `Nodo #${r.node_id || ''}`);
                const avg = Number.isFinite(parseFloat(r.avg_lag_seconds)) ? parseFloat(r.avg_lag_seconds).toFixed(1) : '0.0';
                const samples = parseInt(r.samples, 10) || 0;
                return `<div class="small mb-1"><strong>${name}</strong> · lag prom ${avg}s <span class="text-muted">(${samples} muestras)</span></div>`;
            }).join('');
        }
    },
    
    studyUidHasActiveJob: function(uid) {
        return !!(this.crossSyncActiveJobUids && this.crossSyncActiveJobUids[String(uid || '').trim()]);
    },
    
    /**
     * Firma estable del estado «job activo» por UID en la comparación Cross Sync (para re-pintar solo si cambió).
     */
    snapshotCrossSyncComparisonJobFlags: function() {
        const comp = this.crossSyncComparison;
        if (!comp?.rows?.length) return '';
        const map = this.crossSyncActiveJobUids || {};
        let s = '';
        for (let i = 0; i < comp.rows.length; i++) {
            const u = String(comp.rows[i].StudyInstanceUID || '').trim();
            if (!u) continue;
            const m = map[u];
            let sig = '0';
            if (m) {
                if (typeof m === 'object') {
                    sig = `1:${m.retrieve_source || ''}:${m.job_id || ''}`;
                } else {
                    sig = '1:legacy';
                }
            }
            s += u + ':' + sig + ';';
        }
        return s;
    },
    
    /**
     * Refresca marcadores de jobs en Cross Sync si hay comparación cargada; re-render solo si cambió algo relevante.
     */
    syncCrossSyncJobMarkersAfterJobsApi: async function() {
        if (!this.crossSyncComparison?.rows?.length) return;
        const before = this.snapshotCrossSyncComparisonJobFlags();
        await this.refreshCrossSyncActiveJobsFromApi();
        const after = this.snapshotCrossSyncComparisonJobFlags();
        if (before === after) return;
        const crossTab = document.getElementById('cross-sync-tab');
        if (crossTab && crossTab.classList.contains('active')) {
            this.renderCrossSyncTable();
        }
    },
    
    refreshCrossSyncActiveJobsFromApi: async function() {
        const next = {};
        const assignJobToUid = (u, j) => {
            const uid = String(u || '').trim();
            if (!uid || !j) return;
            const meta = {
                job_id: j.id,
                retrieve_source: j.retrieve_source || 'direct',
                retrieve_source_label: j.retrieve_source_label || 'Directo',
                retrieve_source_hint: j.retrieve_source_hint || ''
            };
            const prev = next[uid];
            if (!prev) {
                next[uid] = meta;
                return;
            }
            if (prev === true) {
                next[uid] = meta;
                return;
            }
            const prevId = parseInt(prev.job_id, 10) || 0;
            const curId = parseInt(j.id, 10) || 0;
            if (curId >= prevId) {
                next[uid] = meta;
            }
        };
        const merge = (jobs) => {
            if (!Array.isArray(jobs)) return;
            jobs.forEach(j => {
                const uids = j.study_instance_uids;
                if (!Array.isArray(uids)) return;
                uids.forEach(raw => assignJobToUid(raw, j));
            });
        };
        try {
            const [resRun, resPen] = await Promise.all([
                fetch(`${this.apiBase}/jobs.php?status=running&limit=100`, { credentials: 'include', cache: 'no-store' }),
                fetch(`${this.apiBase}/jobs.php?status=pending&limit=100`, { credentials: 'include', cache: 'no-store' })
            ]);
            const [d1, d2] = await Promise.all([resRun.json(), resPen.json()]);
            if (d1.success && d1.data) merge(d1.data);
            if (d2.success && d2.data) merge(d2.data);
        } catch (e) {
            console.warn('[CrossSync] No se pudieron cargar jobs activos:', e);
        }
        this.crossSyncActiveJobUids = next;
    },
    
    startCrossSyncActiveJobsPolling: function() {
        if (this.crossSyncActiveJobsTimer) {
            clearInterval(this.crossSyncActiveJobsTimer);
        }
        this.crossSyncActiveJobsTimer = setInterval(() => {
            const tab = document.getElementById('cross-sync-tab');
            if (!tab || !tab.classList.contains('active')) return;
            if (!this.crossSyncComparison?.rows?.length) return;
            const before = this.snapshotCrossSyncComparisonJobFlags();
            this.refreshCrossSyncActiveJobsFromApi().then(() => {
                const after = this.snapshotCrossSyncComparisonJobFlags();
                if (before !== after) {
                    this.renderCrossSyncTable();
                }
            });
        }, 5000);
    },
    
    getCrossSyncRowModalityTokens: function(row) {
        const m = row?.meta?.ModalitiesInStudy;
        if (m == null) return [];
        const s = String(m).trim();
        if (!s || s === '—') return [];
        const parts = s.split(/[,\\]+/).map(x => x.trim().toUpperCase()).filter(Boolean);
        return [...new Set(parts)];
    },
    
    crossSyncRowMatchesListModalityFilter: function(row) {
        const filters = this.crossSyncListModalities || [];
        if (!filters.length) return true;
        const tokens = this.getCrossSyncRowModalityTokens(row);
        return filters.some(f => tokens.includes(String(f).toUpperCase()));
    },
    
    getModalityCountsFromCrossSyncRows: function(rows) {
        const counts = {};
        if (!rows || !rows.length) return counts;
        rows.forEach(row => {
            this.getCrossSyncRowModalityTokens(row).forEach(mod => {
                counts[mod] = (counts[mod] || 0) + 1;
            });
        });
        return counts;
    },
    
    sortCrossSyncModalityKeys: function(keys) {
        const standardOrder = ['RX', 'DX', 'CT', 'MR', 'US', 'MG', 'OT', 'XA', 'NM', 'PT', 'RF', 'DOC', 'CR', 'ES', 'SC'];
        return [...keys].sort((a, b) => {
            const indexA = standardOrder.indexOf(a);
            const indexB = standardOrder.indexOf(b);
            if (indexA !== -1 && indexB !== -1) return indexA - indexB;
            if (indexA !== -1) return -1;
            if (indexB !== -1) return 1;
            return a.localeCompare(b);
        });
    },
    
    renderCrossSyncModalityChipsUI: function(baseRows) {
        const wrap = document.getElementById('crossSyncModalityFiltersWrap');
        const container = document.getElementById('crossSyncModalityButtonsContainer');
        if (!wrap || !container) return;
        if (!baseRows || !baseRows.length) {
            container.innerHTML = '';
            wrap.classList.add('d-none');
            return;
        }
        wrap.classList.remove('d-none');
        const counts = this.getModalityCountsFromCrossSyncRows(baseRows);
        const keys = this.sortCrossSyncModalityKeys(Object.keys(counts));
        const active = new Set((this.crossSyncListModalities || []).map(m => String(m).toUpperCase()));
        const allOn = active.size === 0;
        let html = '';
        html += `<button type="button" class="btn modality-btn ${allOn ? 'active' : ''}" data-modality="all"><span class="modality-label">Todas</span><sup class="modality-count-sub ms-1 text-body-secondary fw-normal">${baseRows.length}</sup></button>`;
        keys.forEach(mod => {
            const n = counts[mod];
            const on = active.has(mod);
            html += `<button type="button" class="btn modality-btn ${on ? 'active' : ''}" data-modality="${this.escapeHtml(mod)}"><span class="modality-label">${this.escapeHtml(mod)}</span><sup class="modality-count-sub ms-1 text-body-secondary fw-normal">${n}</sup></button>`;
        });
        container.innerHTML = html;
    },
    
    /**
     * Pintar tabla Cross Sync (usa this.crossSyncComparison)
     */
    renderCrossSyncTable: function() {
        const thead = document.getElementById('crossSyncResultsHead');
        const tbody = document.getElementById('crossSyncResultsBody');
        const countEl = document.getElementById('crossSyncResultCount');
        const stAbs = document.getElementById('crossSyncStatAbsent');
        const stInc = document.getElementById('crossSyncStatIncomplete');
        const stAli = document.getElementById('crossSyncStatAligned');
        if (!thead || !tbody) return;
        
        const resetStats = () => {
            if (stAbs) stAbs.textContent = 'Ausentes: 0';
            if (stInc) stInc.textContent = 'Incompletos: 0';
            if (stAli) stAli.textContent = 'Alineados: 0';
        };
        
        const locInfoEl = document.getElementById('crossSyncLocalSearchInfo');
        if (locInfoEl) {
            locInfoEl.textContent = 'Escriba para filtrar las filas mostradas';
            locInfoEl.className = 'text-muted d-block mt-1';
        }
        
        const chipWrap = document.getElementById('crossSyncModalityFiltersWrap');
        const chipContainer = document.getElementById('crossSyncModalityButtonsContainer');
        
        const comp = this.crossSyncComparison;
        if (!comp || !comp.nodeIds || comp.nodeIds.length === 0) {
            const tr = document.getElementById('crossSyncTruncateHint');
            if (tr) tr.remove();
            thead.innerHTML = '<tr><th class="text-muted">Ejecute una comparación con al menos dos nodos</th></tr>';
            tbody.innerHTML = '<tr><td class="text-center text-muted py-4">Sin datos</td></tr>';
            if (countEl) countEl.textContent = '0 estudios';
            if (chipContainer) chipContainer.innerHTML = '';
            if (chipWrap) chipWrap.classList.add('d-none');
            this.crossSyncLastRenderedRows = [];
            this.crossSyncSelectedStudies = {};
            this.updateCrossSyncSelectionUi();
            this.updateCrossSyncBatchControls();
            resetStats();
            return;
        }
        
        if (comp.rows.length && typeof comp.rows[0].hasMissing !== 'boolean') {
            comp.rows.forEach(r => {
                r.hasMissing = r.summary === 'ausente en algún nodo' || r.summary === 'ausente / parcial';
                r.hasPartial = r.summary === 'conteos distintos' || r.summary === 'ausente / parcial';
                r.hasExtra = r.summary === 'alineado + extras';
            });
        }
        
        let stats = comp.stats;
        if (!stats || typeof stats.absent !== 'number') {
            stats = {
                total: comp.rows.length,
                absent: comp.rows.filter(r => r.hasMissing).length,
                incomplete: comp.rows.filter(r => r.hasPartial && !r.hasMissing).length,
                aligned: comp.rows.filter(r => !r.hasMissing && !r.hasPartial).length
            };
            comp.stats = stats;
        }
        if (stAbs) stAbs.textContent = `Ausentes: ${stats.absent}`;
        if (stInc) stInc.textContent = `Incompletos: ${stats.incomplete}`;
        if (stAli) stAli.textContent = `Alineados: ${stats.aligned}`;
        
        const diffOnly = !!document.getElementById('crossSyncFilterDiffOnly')?.checked;
        const resultStatus = this.getCrossSyncResultStatusFilter();
        let list = comp.rows.filter(() => true);
        if (diffOnly) {
            list = list.filter(r => r.summary !== 'alineado');
        }
        if (resultStatus === 'absent') {
            list = list.filter(r => r.hasMissing);
        } else if (resultStatus === 'incomplete') {
            list = list.filter(r => r.hasPartial && !r.hasMissing);
        } else if (resultStatus === 'aligned') {
            list = list.filter(r => !r.hasMissing && !r.hasPartial);
        }
        const afterDiffCount = list.length;
        const term = (this.crossSyncLocalSearchTerm || '').trim().toLowerCase();
        if (term) {
            list = list.filter(r => this.rowMatchesCrossSyncLocalFilter(r, term));
        }
        const afterLocalCount = list.length;
        this.renderCrossSyncModalityChipsUI(list);
        list = list.filter(r => this.crossSyncRowMatchesListModalityFilter(r));
        list = this.sortCrossSyncRows(list);
        
        const total = stats.total != null ? stats.total : comp.rows.length;
        if (countEl) {
            const modOn = (this.crossSyncListModalities || []).length > 0;
            countEl.textContent = `${list.length} visibles${modOn ? ' · modalidad' : ''} · ${afterLocalCount} tras búsqueda · ${afterDiffCount} tras estado/dif. · ${total} en comparación`;
        }
        const statusLabel = ({
            absent: 'ausentes',
            incomplete: 'incompletos',
            aligned: 'alineados'
        })[resultStatus] || '';
        this.updateCrossSyncLocalSearchInfo(list.length, term ? afterLocalCount : afterDiffCount, total, diffOnly, term, statusLabel);
        
        this.crossSyncLastRenderedRows = list;
        
        let headHtml = '<tr>';
        headHtml += '<th class="text-center text-nowrap" title="Seleccionar para recuperación en lote"><i class="fas fa-check-square"></i></th>';
        headHtml += `<th class="sortable" data-cross-column="patient_name">Paciente <i class="fas fa-sort sort-icon"></i></th>`;
        headHtml += `<th class="sortable" data-cross-column="patient_id">ID <i class="fas fa-sort sort-icon"></i></th>`;
        headHtml += `<th class="sortable" data-cross-column="study_date">Fecha <i class="fas fa-sort sort-icon"></i></th>`;
        headHtml += `<th class="sortable" data-cross-column="study_time">Hora <i class="fas fa-sort sort-icon"></i></th>`;
        headHtml += `<th class="sortable" data-cross-column="modality">Modalidad <i class="fas fa-sort sort-icon"></i></th>`;
        headHtml += `<th class="sortable" data-cross-column="summary">Resumen <i class="fas fa-sort sort-icon"></i></th>`;
        headHtml += '<th class="text-center text-nowrap cross-sync-col-actions" title="Ver, información, medición">Acc.</th>';
        comp.nodeIds.forEach(nid => {
            const name = this.escapeHtml(comp.nodeLabels[nid] || nid);
            const err = comp.nodeErrors && comp.nodeErrors[nid];
            headHtml += `<th class="cross-sync-node-col sortable" data-cross-column="node:${nid}">${name}${err ? ' <i class="fas fa-exclamation-triangle text-warning" aria-label="Error en búsqueda"></i>' : ''} <i class="fas fa-sort sort-icon"></i></th>`;
        });
        headHtml += '</tr>';
        thead.innerHTML = headHtml;
        
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td class="text-center text-muted py-4" colspan="' + (8 + comp.nodeIds.length) + '">No hay estudios que mostrar con el filtro actual</td></tr>';
            this.updateCrossSyncSelectionUi();
            this.updateCrossSyncBatchControls();
            this.updateCrossSyncSortIcons();
            return;
        }
        
        const escUidForJs = (uid) => String(uid || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        
        tbody.innerHTML = list.map(r => {
            const m = r.meta;
            const studyDate = this.formatStudyDate(m.StudyDate || '');
            const studyTimeDisp = this.formatStudyTime(m.StudyTime);
            const badgeClass = r.summaryClass === 'success' ? 'bg-success'
                : (r.summaryClass === 'danger' ? 'bg-danger'
                : (r.summaryClass === 'info' ? 'bg-info text-dark'
                : 'bg-warning text-dark'));
            const uidSafe = escUidForJs(r.StudyInstanceUID);
            const uid = String(r.StudyInstanceUID || '');
            const jobBusy = this.studyUidHasActiveJob(uid);
            const jobHintTitle = jobBusy
                ? this.escapeHtml((this.getCrossSyncActiveJobMeta(uid)?.retrieve_source_hint) || 'Recuperación en curso para este estudio. Revise la pestaña Jobs.')
                : '';
            const canBatchRetrieve = !!this.getCrossSyncRetrieveCandidateNodeId(r);
            const checked = !!this.crossSyncSelectedStudies[uid];
            const selectTd = canBatchRetrieve
                ? `<td class="text-center"><input type="checkbox" class="form-check-input cross-sync-row-select" data-uid="${this.escapeHtml(uid)}" ${checked ? 'checked' : ''}></td>`
                : (jobBusy
                    ? `<td class="text-center" title="${jobHintTitle}"><span class="text-muted"><i class="fas fa-tasks"></i></span></td>`
                    : '<td class="text-center"><span class="text-muted">—</span></td>');
            
            let cells = '';
            comp.nodeIds.forEach(nid => {
                const st = r.nodeStates[nid];
                if (!st) {
                    cells += '<td class="cross-sync-node-col">—</td>';
                    return;
                }
                let inner = '';
                if (st.kind === 'error') {
                    inner = '<span class="badge bg-secondary">Error</span>';
                } else if (st.kind === 'missing') {
                    inner = '<span class="badge bg-secondary">No</span>';
                } else if (st.kind === 'partial') {
                    inner = `<span class="badge bg-warning text-dark">S:${st.series} I:${st.instances}</span>`;
                } else {
                    const extras = st.extras || null;
                    const extraText = extras
                        ? ` <small class="text-muted d-block mt-1">+S:${extras.series || 0} +I:${extras.instances || 0}${extras.modalities && extras.modalities.length ? ` · ${this.escapeHtml(extras.modalities.join('/'))}` : ''}</small>`
                        : '';
                    inner = `<span class="badge bg-success">S:${st.series} I:${st.instances}</span>${extraText}`;
                }
                
                let btn = '';
                if (this.isLocalPacsNode(nid)) {
                    btn = '';
                } else if (jobBusy) {
                    btn = `<small class="text-muted d-block mt-1" title="${jobHintTitle}">En curso…</small>`;
                } else if ((st.kind === 'ok' || st.kind === 'partial') && this.nodeAllowsRetrieve(nid)) {
                    btn = `<button type="button" class="btn btn-xs btn-outline-primary mt-1" onclick="event.stopPropagation(); PacsNodesManager.retrieveStudy('${uidSafe}', '${String(nid)}')" title="Recuperar al PACS local desde este nodo"><i class="fas fa-download"></i></button>`;
                } else if ((st.kind === 'ok' || st.kind === 'partial') && !this.nodeAllowsRetrieve(nid)) {
                    btn = '<small class="text-muted d-block mt-1">C-MOVE off</small>';
                }
                
                cells += `<td class="cross-sync-node-col"><div class="d-flex flex-column align-items-center gap-0">${inner}${btn}</div></td>`;
            });
            
            const showFollow = !!(r.hasMissing || r.hasPartial);
            const followBtn = showFollow
                ? `<button type="button" class="btn btn-outline-info py-0 px-1" onclick="event.stopPropagation(); PacsNodesManager.startReplicationWatch('${uidSafe}', null)" title="Medir replicación (C-FIND por UID)"><i class="fas fa-stopwatch"></i></button>`
                : '';
            const actionsTd = `
                <td class="text-center align-middle cross-sync-actions p-1">
                    <div class="btn-group btn-group-sm" role="group">
                        ${followBtn}
                        <button type="button" class="btn btn-outline-primary py-0 px-2" onclick="event.stopPropagation(); PacsNodesManager.openCrossSyncStudyViewerByUid('${uidSafe}')" title="Ver estudio"><i class="fas fa-eye"></i></button>
                        <button type="button" class="btn btn-outline-secondary py-0 px-2" onclick="event.stopPropagation(); PacsNodesManager.showCrossSyncStudyInfoByUid('${uidSafe}')" title="Información"><i class="fas fa-info-circle"></i></button>
                    </div>
                </td>`;
            
            const jobBadge = jobBusy
                ? ` <span class="ms-1 align-middle" title="${jobHintTitle}">${this.getCrossSyncJobOriginHtml(uid)}</span>`
                : '';
            return `
                <tr class="cross-sync-data-row ${jobBusy ? 'cross-sync-row-job-busy' : ''}${r.hasLocalModify ? ' cross-sync-row-local-modify' : ''}" data-cross-sync-uid="${this.escapeHtml(uid)}">
                    ${selectTd}
                    <td class="small py-1">${this.escapeHtml(m.PatientName || '—')}${this.getCrossSyncModifyLocalNameHintHtml(r)}${jobBadge}${this.getCrossSyncModifyBadgeHtml(r)}</td>
                    <td class="small py-1">${this.escapeHtml(m.PatientID || '—')}</td>
                    <td class="small py-1">${studyDate}</td>
                    <td class="small py-1">${studyTimeDisp === '—' ? '—' : this.escapeHtml(studyTimeDisp)}</td>
                    <td class="small py-1">${this.escapeHtml(m.ModalitiesInStudy || '—')}</td>
                    <td class="small py-1"><span class="badge ${badgeClass}">${this.escapeHtml(r.summary)}</span></td>
                    ${actionsTd}
                    ${cells}
                </tr>
            `;
        }).join('');
        
        const truncPrev = document.getElementById('crossSyncTruncateHint');
        if (truncPrev) truncPrev.remove();
        if (comp.persistedTruncated && comp.persistedRowsTotal) {
            const hintWrap = document.getElementById('crossSyncAlerts');
            if (hintWrap) {
                hintWrap.insertAdjacentHTML('afterbegin',
                    `<div id="crossSyncTruncateHint" class="alert alert-info py-2 mb-2"><i class="fas fa-info-circle me-1"></i>Lista guardada recortada en el navegador: se muestran ${comp.rows.length} de ${comp.persistedRowsTotal} estudios. Vuelva a ejecutar «Comparar nodos» para el conjunto completo.</div>`);
            }
        }
        
        tbody.querySelectorAll('.cross-sync-row-select').forEach(cb => {
            cb.addEventListener('change', (e) => {
                const uid = String(e.target?.dataset?.uid || '');
                if (!uid) return;
                if (e.target.checked) this.crossSyncSelectedStudies[uid] = true;
                else delete this.crossSyncSelectedStudies[uid];
                this.updateCrossSyncSelectionUi();
            });
        });
        tbody.querySelectorAll('tr[data-cross-sync-uid]').forEach(tr => {
            tr.addEventListener('contextmenu', (e) => {
                if (e.target.closest('button, input, a, label, .btn')) return;
                const uid = tr.getAttribute('data-cross-sync-uid');
                const row = list.find(x => String(x.StudyInstanceUID || '') === uid);
                if (row) this.showCrossSyncContextMenu(e, row);
            });
        });
        this.updateCrossSyncSelectionUi();
        this.updateCrossSyncBatchControls();
        
        this.updateCrossSyncSortIcons();
    },
    
    getCrossSyncRowByUid: function(uidRaw) {
        const uid = String(uidRaw || '').trim();
        if (!uid || !this.crossSyncComparison?.rows) return null;
        return this.crossSyncComparison.rows.find(r => String(r.StudyInstanceUID || '') === uid) || null;
    },
    
    openCrossSyncStudyViewerByUid: function(uidRaw) {
        const row = this.getCrossSyncRowByUid(uidRaw);
        if (!row) return;
        this.openCrossSyncStudyViewer(row);
    },
    
    showCrossSyncStudyInfoByUid: function(uidRaw) {
        const row = this.getCrossSyncRowByUid(uidRaw);
        if (!row) {
            this.showError('Fila no encontrada');
            return;
        }
        this.showCrossSyncStudyInfo(row);
    },
    
    pickCrossSyncViewerRemoteNodeId: function(row) {
        const comp = this.crossSyncComparison;
        if (!row || !comp?.nodeIds) return null;
        for (let i = 0; i < comp.nodeIds.length; i++) {
            const nid = String(comp.nodeIds[i]);
            if (this.isLocalPacsNode(nid)) continue;
            const st = row.nodeStates ? row.nodeStates[nid] : null;
            if (st && (st.kind === 'ok' || st.kind === 'partial')) {
                return nid;
            }
        }
        return null;
    },
    
    openCrossSyncStudyViewer: async function(row) {
        const uid = String(row?.StudyInstanceUID || '').trim();
        if (!uid) return;
        try {
            let res = await fetch(`${this.apiBase}/viewer-open.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    study_instance_uid: uid,
                    source_node_id: 0,
                    is_on_local_pacs: false
                })
            });
            let data = await res.json();
            if (!data.success) {
                this.showError(data.error || 'No se pudo obtener el visor');
                return;
            }
            if (data.open_url) {
                window.open(data.open_url, '_blank');
                return;
            }
            const remoteId = parseInt(this.pickCrossSyncViewerRemoteNodeId(row), 10) || 0;
            if (!remoteId) {
                this.showWarning(data.hint || 'El estudio no está en el PACS local. No hay nodo remoto en esta fila con datos para abrir el visor.');
                return;
            }
            res = await fetch(`${this.apiBase}/viewer-open.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    study_instance_uid: uid,
                    source_node_id: remoteId,
                    is_on_local_pacs: false
                })
            });
            data = await res.json();
            if (data.success && data.open_url) {
                window.open(data.open_url, '_blank');
                return;
            }
            this.showWarning(data.hint || data.error || 'No se pudo abrir el visor remoto.');
        } catch (e) {
            console.error(e);
            this.showError('Error de conexión al abrir el visor.');
        }
    },
    
    showCrossSyncStudyInfo: function(row) {
        const m = row.meta || {};
        const uid = String(row.StudyInstanceUID || '');
        const comp = this.crossSyncComparison;
        let nodesRows = '';
        if (comp?.nodeIds?.length) {
            comp.nodeIds.forEach(nid => {
                const label = this.escapeHtml(comp.nodeLabels[nid] || nid);
                const st = row.nodeStates ? row.nodeStates[nid] : null;
                let detail = '—';
                if (st) {
                    if (st.kind === 'error') detail = 'Error en búsqueda';
                    else if (st.kind === 'missing') detail = 'Ausente';
                    else if (st.kind === 'partial') detail = `Parcial · S:${st.series} I:${st.instances}`;
                    else detail = `S:${st.series} I:${st.instances}`;
                }
                nodesRows += `<tr><td class="small">${label}</td><td class="small">${this.escapeHtml(detail)}</td></tr>`;
            });
        }
        const modalId = 'crossSyncStudyInfoModal';
        const existing = document.getElementById(modalId);
        if (existing) existing.remove();
        const desc = m.StudyDescription || '—';
        const acc = m.AccessionNumber || '—';
        let modifySection = '';
        if (row.modifyHint) {
            const h = row.modifyHint;
            const paired = h.pairedUid ? this.escapeHtml(h.pairedUid) : '—';
            const pairedShort = h.pairedUid ? this.escapeHtml(this.shortStudyUid(h.pairedUid)) : '—';
            const roleLabel = h.role === 'old' ? 'UID anterior (remotos)' : 'Copia local post-edición';
            const changed = (h.changedTags || []).length
                ? this.escapeHtml(h.changedTags.join(', '))
                : '—';
            modifySection = `
                            <div class="alert alert-info py-2 px-3 small mb-3">
                                <div class="fw-semibold mb-1"><i class="fas fa-edit me-1"></i>Edición en PACS Manager</div>
                                <div class="mb-1"><strong>Rol en comparación:</strong> ${this.escapeHtml(roleLabel)}</div>
                                <div class="mb-1"><strong>Estado auditoría:</strong> ${this.escapeHtml(h.status || '—')}</div>
                                ${h.localPatientName ? `<div class="mb-1"><strong>Datos actuales en PACS local:</strong> ${this.escapeHtml(h.localPatientName)}${h.localPatientId ? ` · ID ${this.escapeHtml(h.localPatientId)}` : ''}</div>` : ''}
                                <div class="mb-1"><strong>UID vinculado:</strong> <code class="small">${pairedShort}</code></div>
                                <div class="mb-1"><strong>Campos modificados:</strong> ${changed}</div>
                                ${h.logId ? `<div class="mb-0 text-muted">Registro auditoría #${this.escapeHtml(String(h.logId))}</div>` : ''}
                            </div>
                            <div class="mb-2 d-none" aria-hidden="true"><input type="hidden" data-paired-uid="${paired}"></div>`;
        }
        const html = `
            <div class="modal fade" id="${modalId}" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h5 class="modal-title"><i class="fas fa-info-circle text-primary me-2"></i>Información del estudio (Cross Sync)</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            ${modifySection}
                            <div class="row small">
                                <div class="col-md-6">
                                    <div class="mb-1"><strong>Paciente:</strong> ${this.escapeHtml(m.PatientName || '—')}</div>
                                    <div class="mb-1"><strong>ID paciente:</strong> ${this.escapeHtml(m.PatientID || '—')}</div>
                                    <div class="mb-1"><strong>Fecha:</strong> ${this.escapeHtml(this.formatStudyDate(m.StudyDate || '') || '—')}</div>
                                    <div class="mb-1"><strong>Hora:</strong> ${this.escapeHtml(this.formatStudyTime(m.StudyTime || '') || '—')}</div>
                                    <div class="mb-1"><strong>Modalidad:</strong> ${this.escapeHtml(m.ModalitiesInStudy || '—')}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-1"><strong>Descripción:</strong> ${this.escapeHtml(desc)}</div>
                                    <div class="mb-1"><strong>Accession:</strong> ${this.escapeHtml(acc)}</div>
                                    <div class="mb-1"><strong>Resumen comparación:</strong> <span class="badge bg-secondary">${this.escapeHtml(row.summary || '—')}</span></div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <strong class="small">StudyInstanceUID</strong>
                                <div class="input-group input-group-sm mt-1">
                                    <input type="text" class="form-control font-monospace small" readonly value="${this.escapeHtml(uid)}">
                                    <button class="btn btn-outline-secondary" type="button" data-copy-uid="1" title="Copiar"><i class="fas fa-copy"></i></button>
                                </div>
                            </div>
                            <h6 class="mt-3 small text-primary">Por nodo</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead><tr><th>Nodo</th><th>Estado</th></tr></thead>
                                    <tbody>${nodesRows || '<tr><td colspan="2" class="text-muted small">Sin datos</td></tr>'}</tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer py-2">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-primary btn-sm" id="crossSyncInfoBtnView"><i class="fas fa-eye me-1"></i>Ver estudio</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        const modalEl = document.getElementById(modalId);
        const btnCopy = modalEl.querySelector('[data-copy-uid]');
        if (btnCopy) {
            btnCopy.addEventListener('click', () => this.copyTextToClipboard(uid));
        }
        const btnView = document.getElementById('crossSyncInfoBtnView');
        if (btnView) {
            btnView.addEventListener('click', () => {
                const inst = bootstrap.Modal.getInstance(modalEl);
                if (inst) inst.hide();
                this.openCrossSyncStudyViewer(row);
            });
        }
        modalEl.addEventListener('hidden.bs.modal', () => modalEl.remove(), { once: true });
        new bootstrap.Modal(modalEl).show();
    },
    
    copyTextToClipboard: function(text) {
        const t = String(text || '');
        if (!t) return;
        const done = () => this.showSuccess('Copiado al portapapeles');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t).then(done).catch(() => {
                try {
                    const ta = document.createElement('textarea');
                    ta.value = t;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    ta.remove();
                    done();
                } catch (e) {
                    this.showWarning('No se pudo copiar');
                }
            });
        } else {
            try {
                const ta = document.createElement('textarea');
                ta.value = t;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
                done();
            } catch (e) {
                this.showWarning('No se pudo copiar');
            }
        }
    },
    
    closeCrossSyncContextMenu: function() {
        const m = document.getElementById('crossSyncContextMenu');
        if (m) m.remove();
        if (this._crossSyncCtxEscape) {
            document.removeEventListener('keydown', this._crossSyncCtxEscape);
            this._crossSyncCtxEscape = null;
        }
    },
    
    showCrossSyncContextMenu: function(e, row) {
        e.preventDefault();
        this.closeCrossSyncContextMenu();
        const uid = String(row.StudyInstanceUID || '');
        const showFollow = !!(row.hasMissing || row.hasPartial);
        const menu = document.createElement('div');
        menu.id = 'crossSyncContextMenu';
        menu.className = 'context-menu cross-sync-context-menu';
        menu.style.position = 'fixed';
        menu.style.left = `${Math.min(e.clientX, window.innerWidth - 220)}px`;
        menu.style.top = `${Math.min(e.clientY, window.innerHeight - 200)}px`;
        menu.style.zIndex = '10050';
        let inner = `
            <div class="context-menu-item" data-action="info"><i class="fas fa-info-circle me-2"></i><span>Información</span></div>
            <div class="context-menu-item" data-action="view"><i class="fas fa-eye me-2"></i><span>Ver estudio</span></div>
            <div class="context-menu-item" data-action="copy"><i class="fas fa-copy me-2"></i><span>Copiar Study UID</span></div>`;
        if (showFollow) {
            inner += `<div class="context-menu-item" data-action="measure"><i class="fas fa-stopwatch me-2"></i><span>Medir replicación</span></div>`;
        }
        menu.innerHTML = inner;
        document.body.appendChild(menu);
        menu.querySelectorAll('.context-menu-item').forEach(item => {
            item.addEventListener('click', () => {
                const a = item.getAttribute('data-action');
                this.closeCrossSyncContextMenu();
                if (a === 'info') this.showCrossSyncStudyInfo(row);
                else if (a === 'view') this.openCrossSyncStudyViewer(row);
                else if (a === 'copy') this.copyTextToClipboard(uid);
                else if (a === 'measure') this.startReplicationWatch(uid, null);
            });
        });
        this._crossSyncCtxEscape = (ev) => {
            if (ev.key === 'Escape') this.closeCrossSyncContextMenu();
        };
        document.addEventListener('keydown', this._crossSyncCtxEscape);
        setTimeout(() => {
            document.addEventListener('click', (ev) => {
                if (!menu.contains(ev.target)) this.closeCrossSyncContextMenu();
            }, { capture: true, once: true });
        }, 0);
    },
    
    /**
     * Utilidades
     */
    escapeHtml: function(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    /**
     * Mostrar modal de confirmación
     */
    showConfirm: function(title, message, confirmText = 'Confirmar', cancelText = 'Cancelar') {
        return new Promise((resolve) => {
            // Crear modal dinámicamente
            const modalId = 'pacsNodesConfirmModal_' + Date.now();
            const modalHtml = `
                <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-question-circle me-2"></i>${this.escapeHtml(title)}
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-0">${this.escapeHtml(message)}</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="${modalId}_cancel">
                                    <i class="fas fa-times me-1"></i>${this.escapeHtml(cancelText)}
                                </button>
                                <button type="button" class="btn btn-primary" id="${modalId}_confirm">
                                    <i class="fas fa-check me-1"></i>${this.escapeHtml(confirmText)}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modalElement = document.getElementById(modalId);
            const confirmBtn = document.getElementById(`${modalId}_confirm`);
            const cancelBtn = document.getElementById(`${modalId}_cancel`);
            
            // Crear instancia de Bootstrap Modal
            const bsModal = new bootstrap.Modal(modalElement, {
                backdrop: 'static',
                keyboard: false
            });
            
            // Event listeners
            confirmBtn.addEventListener('click', () => {
                bsModal.hide();
                resolve(true);
            }, { once: true });
            
            cancelBtn.addEventListener('click', () => {
                bsModal.hide();
                resolve(false);
            }, { once: true });
            
            // Limpiar cuando se cierre el modal
            modalElement.addEventListener('hidden.bs.modal', () => {
                modalElement.remove();
            }, { once: true });
            
            // Mostrar modal
            bsModal.show();
        });
    },
    
    showSuccess: function(message) {
        // Crear toast de éxito
        const toast = document.createElement('div');
        toast.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 end-0 m-3';
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    },
    
    showError: function(message) {
        // Crear toast de error
        const toast = document.createElement('div');
        toast.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 end-0 m-3';
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    },
    
    showWarning: function(message) {
        // Crear toast de advertencia
        const toast = document.createElement('div');
        toast.className = 'alert alert-warning alert-dismissible fade show position-fixed top-0 end-0 m-3';
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    },
    
    showInfo: function(message) {
        // Crear toast de información
        const toast = document.createElement('div');
        toast.className = 'alert alert-info alert-dismissible fade show position-fixed top-0 end-0 m-3';
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    }
};

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    PacsNodesManager.init();
});
