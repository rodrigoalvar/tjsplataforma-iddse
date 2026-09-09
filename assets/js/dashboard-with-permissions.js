/**
 * Dashboard con lógica condicional basada en permisos PACS Query
 * Si el usuario tiene permiso PACS Query: consulta PACS normalmente
 * Si no tiene permiso: muestra estudios asignados
 */
class DashboardWithPermissions {
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
            modalities: [], // Array para selección múltiple de modalidades
            institutionName: '', // Filtro por nombre de institución
            reportStatus: '', // Filtro por estado de informe
            assignmentSource: '' // PACS: '' | 'assigned_only'
        };

        /** Claves de estudios asignados/subasignados (id Orthanc, study_instance_uid, etc.) para cruzar con PACS */
        this.assignedToMeKeySet = new Set();

        this.userNivel = '';
        /** root o permiso global `all`: sin filtro por defecto a “solo asignados” */
        this.isRootUser = false;
        
        this.userPermissions = null;
        this.hasPacsQueryPermission = false;
        this.hasAntecedentesPermission = false;
        this.hasWorkspacePermission = false;
        this.isAssignedStudiesMode = false;
        this.hasFilterInstitutionsPermission = false;
        this.hasOcultarInformesPermission = false;
        this.allowedInstitutions = [];
        
        // Configuración de paginación
        this.pagination = {
            currentPage: 1,
            perPage: 25, // Estudios por página
            totalPages: 1,
            totalItems: 0
        };
        
        // Configuración de ordenamiento
        this.sortConfig = {
            column: null, // Columna actualmente ordenada (null = orden por prioridad)
            direction: 'asc' // 'asc' o 'desc'
        };
        
        // Clave para localStorage (persistente entre sesiones - como respaldo)
        this.sortPreferenceKey = 'dashboard_sort_preference';

        // Preferencia "WS on Top": si es true, el estudio abierto en WorkSpace
        // se promueve al tope del listado (comportamiento histórico). Si es false,
        // conserva su orden natural (por columna o por prioridad). Default true.
        // Persistencia: localStorage como respaldo + BD vía save-/get-pin-workspace-preference.php.
        this.pinWorkspaceOnTop = true;
        this.pinWorkspaceOnTopKey = 'dashboard_pin_workspace_on_top';

        // Intervalo para actualización automática de estudios urgentes e incompletos
        this.priorityRefreshInterval = null;

        this.userId = '';
    }
    
    /**
     * Obtiene el token de sesión para autenticación
     */
    getSessionToken() {
        try {
            // Intentar obtener de múltiples fuentes
            const lsToken = localStorage.getItem('session_token') || localStorage.getItem('auth_token');
            const ssToken = sessionStorage.getItem('session_token') || sessionStorage.getItem('auth_token');
            if (lsToken) return lsToken;
            if (ssToken) return ssToken;
            
            // Intentar obtener de cookies
            const match = document.cookie.match(/(?:^|; )session_token=([^;]+)/);
            if (match) return decodeURIComponent(match[1]);
            const match2 = document.cookie.match(/(?:^|; )token=([^;]+)/);
            if (match2) return decodeURIComponent(match2[1]);
            
            return null;
        } catch (e) {
            console.error('Error obteniendo token de sesión:', e);
            return null;
        }
    }

    /**
     * Prioridades altas (misma regla de bypass de filtros en dashboard que urgente/promesa).
     */
    isHighPriorityPrioridad(prioridad) {
        return prioridad === 'urgente' || prioridad === 'promesa' || prioridad === 'pendiente';
    }

    /**
     * Indica si el estudio tiene al menos un informe (para dejar de bypasear filtros con prioridad alta).
     */
    studyHasInforme(study) {
        const ri = study.report_info || {};
        const n = Number(ri.total_informes);
        if (Number.isFinite(n) && n > 0) return true;
        if (study.has_report === true) return true;
        if (ri.has_report === true) return true;
        return false;
    }

    /**
     * Inicializa el dashboard con verificación de permisos
     */
    async init() {
        try {
            console.log('🔍 Inicializando dashboard con verificación de permisos...');
            
            // Verificar permisos del usuario
            const authSuccess = await this.checkUserPermissions();
            
            // Si no hay sesión activa, no continuar con la inicialización
            if (!authSuccess) {
                console.log('❌ Inicialización cancelada - no hay sesión activa');
                return;
            }
            
            this.setupEventListeners();

            if (this.hasPacsQueryPermission && !this.isAssignedStudiesMode) {
                await this.refreshAssignedStudyKeys();
            }
            
            // Configurar listener para cambios de estado global
            this.setupGlobalStateListener();
            
            // Configurar listener para cuando se elimina un informe
            this.setupInformeEliminadoListener();

            // Configurar listener para cuando se finaliza/crea un informe
            // (workspace, editor.html, informes-manager) y reflejarlo en la grilla.
            this.setupInformeFinalizadoListener();
            this.setupInformeEstadoCambiadoListener();
            this.loadPendingSignCount();

            // Configurar listener para cambios en workspace (actualizar indicadores)
            this.setupWorkspaceStateListener();

            // Cola de lectura workspace (sync con popup multimonitor)
            this.setupStudyQueueListener();
            
            // Configurar listener para cambios en estados de estudios (prioridad, incompleto)
            this.setupStudyStatusChangeListener();
            
            // Cargar preferencia de ordenamiento guardada (desde BD o localStorage)
            await this.loadSortPreference();

            // Cargar preferencia "WS on Top" (BD con respaldo en localStorage). Si nunca
            // se guardó, mantiene el default true (no toca el comportamiento histórico).
            await this.loadPinWorkspaceOnTopPreference();
            
            // Configurar ordenamiento de columnas
            this.setupColumnSorting();
            
            // Inicializar iconos de ordenamiento
            this.updateSortIcons();
            
            // Cargar estudios urgentes e incompletos al inicio SIEMPRE (FRESH, sin caché)
            // Esto asegura que al abrir el dashboard se vean los estudios prioritarios actualizados
            const [urgentStudies, incompleteStudies] = await Promise.all([
                this.loadUrgentStudies(),
                this.loadIncompleteStudies()
            ]);
            
            // Intentar cargar estado persistente
            let persistentState = this.loadPersistentState();

            // Si hubo un informeFinalizado o informeEliminado más reciente que el caché
            // (típicamente cuando el usuario vuelve al dashboard después de finalizar
            // o eliminar un informe en otra vista — flujo donde el iframe del dashboard
            // fue reemplazado y el listener no estuvo activo en el momento del cambio),
            // descartar caché y forzar fetch fresco.
            let forceFreshAfterInformeFinalizado = false;
            try {
                const candidates = [
                    { key: 'informe_finalizado_event', label: 'informeFinalizado' },
                    { key: 'informe_eliminado_event', label: 'informeEliminado' }
                ];
                for (const { key, label } of candidates) {
                    const lastEvtRaw = localStorage.getItem(key);
                    if (lastEvtRaw && persistentState && persistentState.timestamp) {
                        const lastEvt = JSON.parse(lastEvtRaw);
                        if (lastEvt && Number(lastEvt.ts) > Number(persistentState.timestamp)) {
                            console.log(`🔄 Caché del dashboard es anterior a un ${label} — recargando datos frescos`, {
                                cacheTs: persistentState.timestamp,
                                eventTs: lastEvt.ts
                            });
                            this.clearPersistentState();
                            persistentState = null;
                            forceFreshAfterInformeFinalizado = true;
                            break;
                        }
                    }
                }
            } catch (e) {
                console.warn('⚠️ No se pudo evaluar eventos de informe vs caché:', e);
            }

            if (persistentState && persistentState.studies && persistentState.studies.length > 0) {
                // Restaurar estado desde localStorage (solo estudios normales)
                // Combinar: urgentes (fresh) + incompletos (fresh) + normales (caché)
                this.studies = this.mergeStudies(urgentStudies, incompleteStudies, persistentState.studies);
                const persistedFilters = persistentState.filters || {};
                const hadPersistedAssignmentSource = Object.prototype.hasOwnProperty.call(persistedFilters, 'assignmentSource');
                this.currentFilters = { ...this.currentFilters, ...persistedFilters };
                this.isDataLoaded = true;
                
                this.hydrateModalityFromSessionStorage();
                
                console.log(`📦 Estado restaurado: ${urgentStudies.length} urgentes + ${incompleteStudies.length} incompletos (fresh) + ${persistentState.studies.length} normales (caché) = ${this.studies.length} total`);
                
                if (this.hasPacsQueryPermission && !this.isAssignedStudiesMode) {
                    await this.refreshAssignedStudyKeys();
                    this.syncAssignmentSourceDefaultForPacs(hadPersistedAssignmentSource);
                }
                
            // Restaurar valores en los campos del formulario
            this.restoreFormValues();
            
            // Restaurar configuración de paginación si existe
            if (persistentState.pagination) {
                this.pagination = { ...this.pagination, ...persistentState.pagination };
            }
            
            // Restaurar selector de elementos por página
            const perPageSelect = document.getElementById('perPageSelect');
            if (perPageSelect && this.pagination.perPage) {
                perPageSelect.value = this.pagination.perPage;
            }
            
            // Actualizar el filtro de institución con los estudios restaurados
            this.updateInstitutionFilter();
            
            // Aplicar filtros locales y renderizar
            this.applyLocalFilters();
            
            // Actualizar contadores de urgentes e incompletos después de restaurar estado
            this.updatePriorityCounters(urgentStudies, incompleteStudies);
                
                // Recargar antecedentes después de restaurar estado para asegurar datos correctos
                setTimeout(async () => {
                    console.log('🔄 Recargando antecedentes después de restaurar estado...');
                    if (this.hasPacsQueryPermission) {
                        await this.loadAntecedentsForPacsStudies();
                    } else {
                        this.updateAntecedentsCounters();
                    }
                    
                    // Verificar y corregir estudios sin datos de antecedentes
                    await this.checkAndFixMissingAntecedents();
                }, 200);
                
                this.updateCacheStatus('Datos restaurados desde caché local', 'text-success');
                
                console.log('Estado restaurado desde localStorage:', this.studies.length, 'estudios');
            } else if (forceFreshAfterInformeFinalizado) {
                // Hubo un informeFinalizado más reciente que el caché y se invalidó.
                // Cargar datos frescos automáticamente para que el usuario no tenga
                // que apretar Buscar al volver al dashboard.
                console.log('🔄 Recargando estudios frescos tras invalidación de caché por informeFinalizado...');
                try {
                    if (this.hasPacsQueryPermission && !this.isAssignedStudiesMode) {
                        this.syncAssignmentSourceDefaultForPacs(false);
                    }
                    this.applyAssignmentSourceToCheckbox();
                    await this.loadStudies();
                    this.updateCacheStatus('Datos actualizados después de finalizar informe', 'text-success');
                } catch (err) {
                    console.error('Error recargando estudios tras informeFinalizado:', err);
                    // Fallback: si urgentes/incompletos hay algo, mostrarlos; sino, estado vacío
                    if (urgentStudies.length > 0 || incompleteStudies.length > 0) {
                        this.studies = this.mergeStudies(urgentStudies, incompleteStudies, []);
                        this.hydrateModalityFromSessionStorage();
                        this.filteredStudies = [...this.studies];
                        this.isDataLoaded = true;
                        this.renderStudies();
                        this.updateStats();
                        this.updatePriorityCounters(urgentStudies, incompleteStudies);
                    } else {
                        this.renderEmptyState();
                    }
                    this.updateCacheStatus('Error recargando datos — usando los disponibles', 'text-warning');
                }
            } else if (urgentStudies.length > 0 || incompleteStudies.length > 0) {
                // No hay caché pero sí hay estudios urgentes o incompletos: mostrarlos
                this.studies = this.mergeStudies(urgentStudies, incompleteStudies, []);
                this.hydrateModalityFromSessionStorage();
                if (this.hasPacsQueryPermission && !this.isAssignedStudiesMode) {
                    this.syncAssignmentSourceDefaultForPacs(false);
                }
                this.applyAssignmentSourceToCheckbox();
                this.filteredStudies = [...this.studies];
                this.isDataLoaded = true;
                this.renderStudies();
                this.updateStats();
                // Actualizar contadores de urgentes e incompletos específicamente
                this.updatePriorityCounters(urgentStudies, incompleteStudies);
                const totalPriority = urgentStudies.length + incompleteStudies.length;
                this.updateCacheStatus(`${totalPriority} estudios prioritarios cargados`, 'text-success');
                console.log(`📋 Dashboard inicializado - Mostrando ${urgentStudies.length} urgentes + ${incompleteStudies.length} incompletos`);
            } else {
                // Estado inicial vacío
                if (this.hasPacsQueryPermission && !this.isAssignedStudiesMode) {
                    this.syncAssignmentSourceDefaultForPacs(false);
                }
                this.applyAssignmentSourceToCheckbox();
                this.renderEmptyState();
                this.updateCacheStatus(this.getInitialStatusMessage(), 'text-info');
            }
            
            // Sincronizar con el estado global
            this.syncWithGlobalState();
            
            // Iniciar actualización automática periódica de estudios urgentes e incompletos
            this.startPriorityAutoRefresh();
            
            console.log('✅ Dashboard inicializado correctamente');
            console.log('🔐 Modo:', this.isAssignedStudiesMode ? 'Estudios Asignados y Derivados' : 'PACS Query');
            
        } catch (error) {
            console.error('Error inicializando dashboard:', error);
            this.showError('Error inicializando dashboard');
        }
    }
    
    /**
     * Inicia la actualización automática periódica de estudios urgentes e incompletos
     */
    startPriorityAutoRefresh() {
        // Limpiar intervalo anterior si existe
        if (this.priorityRefreshInterval) {
            clearInterval(this.priorityRefreshInterval);
        }
        
        // Actualizar cada 30 segundos
        this.priorityRefreshInterval = setInterval(async () => {
            try {
                console.log('🔄 Actualizando estudios urgentes e incompletos automáticamente...');
                await this.refreshPriorityStudies();
            } catch (error) {
                console.error('Error en actualización automática de estudios prioritarios:', error);
            }
        }, 30000); // 30 segundos
    }
    
    /**
     * Detiene la actualización automática periódica
     */
    stopPriorityAutoRefresh() {
        if (this.priorityRefreshInterval) {
            clearInterval(this.priorityRefreshInterval);
            this.priorityRefreshInterval = null;
        }
    }
    
    /**
     * Actualiza los estudios urgentes e incompletos y los contadores
     */
    async refreshPriorityStudies() {
        try {
            // Cargar estudios urgentes e incompletos frescos
            const [urgentStudies, incompleteStudies] = await Promise.all([
                this.loadUrgentStudies(),
                this.loadIncompleteStudies()
            ]);
            
            // Actualizar los estudios en this.studies
            // Primero, remover los estudios urgentes e incompletos antiguos
                this.studies = this.studies.filter(study => {
                const wasUrgent = study.is_urgent ||
                                 (study.prioridad && this.isHighPriorityPrioridad(study.prioridad));
                const wasIncomplete = study.is_incomplete || 
                                     (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
                return !wasUrgent && !wasIncomplete;
            });
            
            // Agregar los nuevos estudios urgentes e incompletos
            this.studies = this.mergeStudies(urgentStudies, incompleteStudies, this.studies);
            
            // Actualizar contadores
            this.updatePriorityCounters(urgentStudies, incompleteStudies);
            
            // Re-aplicar filtros locales para reflejar cambios
            this.applyLocalFilters();
            
            console.log(`✅ Estudios prioritarios actualizados: ${urgentStudies.length} urgentes, ${incompleteStudies.length} incompletos`);
        } catch (error) {
            console.error('Error actualizando estudios prioritarios:', error);
        }
    }
    
    /**
     * Verifica los permisos del usuario actual
     */
    async checkUserPermissions() {
        try {
            console.log('🔍 Verificando permisos del usuario...');
            
            // Obtener información del usuario actual
            const response = await fetch('api/auth/validate-session-simple.php');
            console.log('📡 Respuesta de la API:', response);
            
            const result = await response.json();
            console.log('📋 Resultado parseado:', result);
            
            if (result.success && result.user) {
                // Manejar permisos que pueden venir como array o string
                let permisos = result.user.permisos || [];
                if (typeof permisos === 'string') {
                    try {
                        permisos = JSON.parse(permisos);
                    } catch (e) {
                        permisos = [permisos];
                    }
                }
                if (!Array.isArray(permisos)) {
                    permisos = [];
                }
                
                this.userPermissions = permisos;
                this.userId = result.user.id ? String(result.user.id) : '';
                this.userNivel = (result.user.nivel !== undefined && result.user.nivel !== null)
                    ? String(result.user.nivel)
                    : '';
                this.isRootUser = String(this.userNivel).toLowerCase() === 'root' || permisos.includes('all');
                this.hasPacsQueryPermission = permisos.includes('pacs_query') || permisos.includes('all');
                this.hasAntecedentesPermission = permisos.includes('antecedentes') || permisos.includes('all');
                this.hasWorkspacePermission = permisos.includes('gui_workspace') || permisos.includes('all');
                this.hasFilterInstitutionsPermission = permisos.includes('filter_institutions') || permisos.includes('all');
                this.hasOcultarInformesPermission = permisos.includes('ocultar_informes');
                this.isAssignedStudiesMode = !this.hasPacsQueryPermission;
                
                // Obtener instituciones permitidas si el usuario tiene el permiso
                if (this.hasFilterInstitutionsPermission && result.user.id) {
                    try {
                        const instResponse = await fetch(`api/users/get-institutions.php?user_id=${result.user.id}`);
                        const instResult = await instResponse.json();
                        if (instResult.success && instResult.instituciones) {
                            this.allowedInstitutions = instResult.instituciones.map(inst => inst.trim().toUpperCase());
                            console.log('🏥 Instituciones permitidas:', this.allowedInstitutions);
                        }
                    } catch (e) {
                        console.warn('Error obteniendo instituciones permitidas:', e);
                    }
                }
                
                console.log('👤 Usuario:', result.user.nombre, result.user.apellido);
                console.log('🔐 Permisos:', this.userPermissions);
                console.log('🔍 PACS Query:', this.hasPacsQueryPermission ? 'SÍ' : 'NO');
                console.log('🔍 Antecedentes:', this.hasAntecedentesPermission ? 'SÍ' : 'NO');
                console.log('🔍 Filtrar Instituciones:', this.hasFilterInstitutionsPermission ? 'SÍ' : 'NO');
                console.log('📋 Modo:', this.isAssignedStudiesMode ? 'Estudios Asignados y Derivados' : 'PACS Query');
                
                // Actualizar indicador de modo en la interfaz
                this.updateModeIndicator();
                
                return true; // Usuario autenticado exitosamente
                
            } else {
                console.warn('⚠️ No hay sesión activa, redirigiendo al login...');
                
                // Mostrar mensaje y redirigir al login
                this.showError('Debes iniciar sesión para acceder al dashboard');
                
                // Redirigir al login después de 2 segundos
                setTimeout(() => {
                    window.location.href = 'login.html';
                }, 2000);
                
                return false; // Usuario no autenticado
            }
            
        } catch (error) {
            console.error('❌ Error verificando permisos:', error);
            console.warn('⚠️ Error verificando permisos, redirigiendo al login...');
            
            // Mostrar mensaje y redirigir al login
            this.showError('Error verificando permisos. Redirigiendo al login...');
            
            // Redirigir al login después de 2 segundos
            setTimeout(() => {
                window.location.href = 'login.html';
            }, 2000);
            
            return false; // Error en verificación
        }
    }
    
    /**
     * Actualiza el indicador de modo en la interfaz
     */
    updateModeIndicator() {
        const cacheStatus = document.getElementById('cacheStatus');
        if (cacheStatus) {
            const modeText = this.isAssignedStudiesMode ? 'Estudios Asignados y Derivados' : 'PACS Query';
            const modeIcon = this.isAssignedStudiesMode ? 'fa-user-check' : 'fa-database';
            
            cacheStatus.innerHTML = `<i class="fas ${modeIcon} me-1"></i>Modo: ${modeText}`;
            cacheStatus.className = 'text-info';
        }
        this.updateAssignmentFilterVisibility();
    }

    /**
     * Muestra el filtro "Solo asignados" solo en modo PACS Query.
     */
    updateAssignmentFilterVisibility() {
        const wrap = document.getElementById('assignmentSourceFilterWrap');
        if (!wrap) return;

        if (this.hasPacsQueryPermission && !this.isAssignedStudiesMode) {
            wrap.classList.remove('d-none');
        } else {
            wrap.classList.add('d-none');
            this.currentFilters.assignmentSource = '';
            const cb = document.getElementById('assignmentSourceOnlyAssigned');
            if (cb) cb.checked = false;
        }
    }

    /**
     * Sincroniza el switch con currentFilters.assignmentSource
     */
    applyAssignmentSourceToCheckbox() {
        const cb = document.getElementById('assignmentSourceOnlyAssigned');
        if (cb) {
            cb.checked = this.currentFilters.assignmentSource === 'assigned_only';
        }
    }

    /**
     * Por defecto "solo asignados" para usuarios no root con PACS y al menos una asignación,
     * salvo que ya exista assignmentSource guardado en persistencia.
     * @param {boolean} hadPersistedAssignmentSource — true si el objeto filters guardado ya traía la clave assignmentSource
     */
    syncAssignmentSourceDefaultForPacs(hadPersistedAssignmentSource) {
        if (hadPersistedAssignmentSource) {
            return;
        }
        if (!this.hasPacsQueryPermission || this.isAssignedStudiesMode) {
            return;
        }
        if (this.isRootUser) {
            return;
        }
        if (!this.assignedToMeKeySet || this.assignedToMeKeySet.size === 0) {
            return;
        }
        this.currentFilters.assignmentSource = 'assigned_only';
    }

    /**
     * Carga identificadores de estudios asignados al usuario para filtrar resultados PACS.
     */
    async refreshAssignedStudyKeys() {
        if (!this.hasPacsQueryPermission) {
            this.assignedToMeKeySet = new Set();
            return;
        }
        const next = new Set();
        try {
            const response = await fetch(`${this.apiBaseUrl}get_user_assigned_studies_fixed.php`, {
                credentials: 'include'
            });
            const result = await response.json();
            if (!result.success || !result.data || !Array.isArray(result.data.studies)) {
                this.assignedToMeKeySet = next;
                return;
            }
            for (const s of result.data.studies) {
                const ids = [s.id, s.study_id, s.orthanc_study_id, s.study_instance_uid];
                for (const raw of ids) {
                    if (raw !== undefined && raw !== null && String(raw).trim() !== '') {
                        next.add(String(raw).trim());
                    }
                }
            }
            this.assignedToMeKeySet = next;
            console.log(`📌 Claves asignaciones para filtro PACS: ${next.size}`);
        } catch (e) {
            console.warn('No se pudieron cargar asignaciones para filtro PACS:', e);
            this.assignedToMeKeySet = next;
        }
    }

    /**
     * ¿El estudio del listado PACS coincide con una asignación del usuario?
     */
    isStudyAssignedToMe(study) {
        if (!study || !this.assignedToMeKeySet || this.assignedToMeKeySet.size === 0) {
            return false;
        }
        const keys = [study.id, study.study_id, study.orthanc_study_id, study.study_instance_uid];
        for (const raw of keys) {
            if (raw !== undefined && raw !== null && String(raw).trim() !== '') {
                if (this.assignedToMeKeySet.has(String(raw).trim())) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Obtiene el mensaje de estado inicial según el modo
     */
    getInitialStatusMessage() {
        if (this.isAssignedStudiesMode) {
            return 'Listo para mostrar estudios asignados';
        } else {
            return 'Listo para buscar - presiona "Buscar" para consultar PACS';
        }
    }
    
    /**
     * Carga estudios según el modo del usuario
     */
    async loadStudies() {
        try {
            if (this.isAssignedStudiesMode) {
                console.log('📋 Cargando estudios asignados...');
                await this.loadAssignedStudies();
            } else {
                console.log('🔍 Cargando estudios desde PACS...');
                await this.loadPacsStudies();
            }
            await this.loadSortPreference();
            this.applyLocalFilters();
        } catch (error) {
            console.error('Error cargando estudios:', error);
            throw error;
        }
    }
    
    /**
     * Carga estudios asignados al usuario
     */
    async loadAssignedStudies() {
        try {
            // Limpiar caché antes de cargar nuevos datos
            this.clearPersistentState();
            
            // Cargar estudios urgentes, incompletos y asignados en paralelo
            const [urgentStudies, incompleteStudies, assignedResponse] = await Promise.all([
                this.loadUrgentStudies(),
                this.loadIncompleteStudies(),
                fetch(this.apiBaseUrl + 'get_user_assigned_studies_fixed.php')
            ]);
            
            const assignedResult = await assignedResponse.json();
            
            if (!assignedResult.success) {
                throw new Error(assignedResult.error);
            }
            
            const normalStudies = assignedResult.data.studies || [];
            
            // Combinar: urgentes primero, luego incompletos, luego normales (sin duplicados)
            this.studies = this.mergeStudies(urgentStudies, incompleteStudies, normalStudies);
            this.isDataLoaded = true;
            
            this.hydrateModalityFromSessionStorage();
            
            // Mostrar información detallada de asignaciones y derivaciones
            const assignedCount = assignedResult.data.assigned_count || 0;
            const subassignedCount = assignedResult.data.subassigned_count || 0;
            
            console.log('📊 Estudios cargados:', {
                urgentes: urgentStudies.length,
                incompletos: incompleteStudies.length,
                normales: normalStudies.length,
                total: this.studies.length,
                asignados: assignedCount,
                derivados: subassignedCount
            });
            
            if (subassignedCount > 0) {
                console.log('✅ Se incluyeron estudios derivados (subasignaciones)');
            }
            
            // Actualizar contadores de urgentes e incompletos
            this.updatePriorityCounters(urgentStudies, incompleteStudies);
            
            // Los antecedentes ya vienen incluidos en los datos de la API
            // Actualizar contadores directamente desde los datos (con delay para DOM)
            setTimeout(() => {
                this.updateAntecedentsCounters();
            }, 100);
            
            // Cargar flags de informes incompletos para los estudios cargados
            await this.loadIncompleteStudyFlags();
            
            // Actualizar el filtro de institución con los estudios cargados
            this.updateInstitutionFilter();
            
        } catch (error) {
            console.error('Error cargando estudios asignados:', error);
            throw error;
        }
    }
    
    /**
     * Carga el estado de antecedentes para todos los estudios
     */
    async loadAntecedentsStatus() {
        try {
            console.log('=== INICIO loadAntecedentsStatus ===');
            console.log('Cargando estado de antecedentes para todos los estudios...');
            console.log('Estudios disponibles:', this.studies.length);
            
            // Obtener todos los study_ids
            const studyIds = this.studies.map(study => study.id);
            console.log('Study IDs:', studyIds);
            
            if (studyIds.length === 0) {
                console.log('No hay estudios para cargar antecedentes');
                return;
            }
            
            // Usar la misma API que estudios-manager
            const url = `${this.apiBaseUrl}study_antecedents.php?study_ids=${studyIds.join(',')}`;
            console.log('URL de consulta:', url);
            
            const response = await fetch(url);
            const result = await response.json();
            
            console.log('Respuesta del API:', result);
            
            if (result.success && result.data) {
                console.log('Datos recibidos:', result.data);
                
                // Actualizar indicadores visuales (como en estudios-manager)
                result.data.forEach(item => {
                    console.log('Procesando estudio:', item.study_id, 'con', item.total_antecedents, 'antecedentes');
                    
                    const indicator = document.getElementById(`antecedents-${item.study_id}`);
                    const counter = document.getElementById(`antecedents-counter-${item.study_id}`);
                    
                    console.log('Elementos encontrados:', {
                        indicator: !!indicator,
                        counter: !!counter,
                        studyId: item.study_id
                    });
                    
                    if (indicator) {
                        indicator.style.display = 'inline-block';
                        indicator.title = `${item.files_count} archivo(s) adjunto(s)`;
                        console.log('Indicador actualizado para:', item.study_id);
                    }
                    
                    // Actualizar contador superpuesto
                    if (counter) {
                        const totalCount = item.total_antecedents || item.files_count || 0;
                        counter.textContent = totalCount;
                        
                        // Usar clases CSS en lugar de style.display
                        if (totalCount > 0) {
                            counter.classList.add('show');
                            counter.classList.remove('d-none');
                        } else {
                            counter.classList.remove('show');
                            counter.classList.add('d-none');
                        }
                        
                        counter.title = `${totalCount} antecedente(s) cargado(s)`;
                        console.log(`Contador actualizado para ${item.study_id}: ${totalCount} antecedentes`);
                    } else {
                        console.warn(`Contador no encontrado para estudio: ${item.study_id}`);
                    }
                });
                
                console.log(`Estado de antecedentes cargado: ${result.data.length} estudios con antecedentes`);
            } else {
                console.log('No se encontraron antecedentes o respuesta no exitosa');
                // Asignar datos por defecto para que la columna funcione
                this.studies.forEach(study => {
                    if (!study.antecedents) {
                        study.antecedents = {
                            has_notes: false,
                            has_files: false,
                            total_count: 0,
                            files_count: 0,
                            notes: '',
                            created_date: '',
                            created_by_name: '',
                            created_by_surname: ''
                        };
                    }
                });
                this.renderStudies();
            }
            
            console.log('=== FIN loadAntecedentsStatus ===');
            
        } catch (error) {
            console.error('Error cargando estado de antecedentes:', error);
            // En caso de error, asignar datos por defecto
            this.studies.forEach(study => {
                if (!study.antecedents) {
                    study.antecedents = {
                        has_notes: false,
                        has_files: false,
                        total_count: 0,
                        files_count: 0,
                        notes: '',
                        created_date: '',
                        created_by_name: '',
                        created_by_surname: ''
                    };
                }
            });
            this.renderStudies();
        }
    }
    
    /**
     * Carga estudios desde PACS (comportamiento original)
     */
    async loadPacsStudies() {
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
            
            // Cargar estudios urgentes, incompletos y normales en paralelo
            const [urgentStudies, incompleteStudies, normalStudiesResponse] = await Promise.all([
                this.loadUrgentStudies(),
                this.loadIncompleteStudies(),
                fetch(url)
            ]);
            
            const responseText = await normalStudiesResponse.text();
            let normalStudiesResult;
            try {
                normalStudiesResult = JSON.parse(responseText);
            } catch (parseErr) {
                const st = normalStudiesResponse.status;
                const gateway = st === 502 || st === 504;
                const msg = gateway
                    ? 'El servidor no pudo completar la consulta a tiempo (502/504). Suele ocurrir con días con muchos estudios; reintente o acorte el rango de fechas.'
                    : `La respuesta del servidor no es JSON válido (HTTP ${st}).`;
                throw new Error(msg);
            }
            if (!normalStudiesResponse.ok) {
                if (normalStudiesResult && normalStudiesResult.error) {
                    throw new Error(normalStudiesResult.error);
                }
                throw new Error(`Error HTTP ${normalStudiesResponse.status}`);
            }
            if (!normalStudiesResult.success) {
                throw new Error(normalStudiesResult.error || 'Error al obtener estudios');
            }
            
            const normalStudies = normalStudiesResult.data || [];
            
            // Combinar: urgentes primero, luego incompletos, luego normales (sin duplicados)
            this.studies = this.mergeStudies(urgentStudies, incompleteStudies, normalStudies);
            this.isDataLoaded = true;
            
            this.hydrateModalityFromSessionStorage();
            
            console.log('📊 Estudios cargados:', {
                urgentes: urgentStudies.length,
                incompletos: incompleteStudies.length,
                normales: normalStudies.length,
                total: this.studies.length
            });
            
            // Actualizar contadores de urgentes e incompletos
            this.updatePriorityCounters(urgentStudies, incompleteStudies);
            
            // Actualizar dropdown de instituciones
            this.updateInstitutionFilter();
            
            // Consultar antecedentes para cada estudio del PACS
            await this.loadAntecedentsForPacsStudies();

            await this.refreshAssignedStudyKeys();
            
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
            dateFrom.value = this.currentFilters.dateFrom;
            dateFrom.addEventListener('change', (e) => {
                this.currentFilters.dateFrom = e.target.value;
                this.clearQuickDateButtonStates();
                // Actualizar contadores cuando cambie la fecha
                this.updateStats();
                // Actualizar modalidades disponibles según el periodo
                this.updateAvailableModalities();
                // Solo actualizar filtro, no recargar - el usuario debe presionar "Buscar"
            });
        }
        
        const dateTo = document.getElementById('dateTo');
        if (dateTo) {
            dateTo.value = this.currentFilters.dateTo;
            dateTo.addEventListener('change', (e) => {
                this.currentFilters.dateTo = e.target.value;
                this.clearQuickDateButtonStates();
                // Actualizar contadores cuando cambie la fecha
                this.updateStats();
                // Actualizar modalidades disponibles según el periodo
                this.updateAvailableModalities();
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
        
        // Filtro de institución - filtrado local
        const institutionFilter = document.getElementById('institutionFilter');
        if (institutionFilter) {
            institutionFilter.addEventListener('change', (e) => {
                this.currentFilters.institutionName = e.target.value;
                // Aplicar filtro local inmediatamente
                this.applyLocalFilters();
            });
        }

        const reportStatusFilter = document.getElementById('reportStatusFilter');
        if (reportStatusFilter) {
            reportStatusFilter.addEventListener('change', (e) => {
                this.currentFilters.reportStatus = e.target.value;
                this.applyLocalFilters();
            });
        }

        const assignmentSourceOnlyAssigned = document.getElementById('assignmentSourceOnlyAssigned');
        if (assignmentSourceOnlyAssigned) {
            assignmentSourceOnlyAssigned.addEventListener('change', (e) => {
                this.currentFilters.assignmentSource = e.target.checked ? 'assigned_only' : '';
                this.applyLocalFilters();
            });
        }

        // Switch "WS on Top": pin/unpin del estudio en WorkSpace.
        // Afecta dos cosas: (a) orden/paginación y (b) si el estudio del WorkSpace
        // se incluye incondicionalmente (true) o debe cumplir filtros (false).
        const pinWsOnTopEl = document.getElementById('pinWorkspaceOnTop');
        if (pinWsOnTopEl) {
            // Reflejar el valor actual de la preferencia (puede ser default true o lo cargado)
            pinWsOnTopEl.checked = !!this.pinWorkspaceOnTop;
            pinWsOnTopEl.addEventListener('change', (e) => {
                this.pinWorkspaceOnTop = !!e.target.checked;
                // Persistir (BD + localStorage). No bloquea la UI.
                this.savePinWorkspaceOnTopPreference().catch(err => {
                    console.warn('⚠️ Error guardando preferencia WS on Top:', err);
                });
                // Re-aplicar filtros: el atajo "incluir sin filtros" del estudio en
                // WorkSpace depende de este flag, así que filteredStudies puede cambiar.
                try {
                    this.applyLocalFilters();
                } catch (err) {
                    console.warn('⚠️ Error aplicando filtros tras toggle WS on Top:', err);
                    try { this.renderStudies(); } catch (_) { /* noop */ }
                }
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
        
        this.setupQuickDatePresetButtons();
    }
    
    /**
     * Fecha local en YYYY-MM-DD (evita desfases de zona respecto a toISOString UTC).
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
     * Atajos Hoy / Ayer / Últimos 7 días: mismos filtros y validaciones que «Buscar»
     * (PACS Query vs estudios asignados lo decide applyFilters según permisos).
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
        
        const dateFromEl = document.getElementById('dateFrom');
        const dateToEl = document.getElementById('dateTo');
        if (dateFromEl) {
            dateFromEl.value = fromStr;
        }
        if (dateToEl) {
            dateToEl.value = toStr;
        }
        this.currentFilters.dateFrom = fromStr;
        this.currentFilters.dateTo = toStr;
        
        this.setQuickDateButtonActive(preset);
        this.updateStats();
        this.updateAvailableModalities();
        
        await this.handleSearchClick();
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
     * Aplica los filtros a los estudios
     */
    async applyFilters() {
        try {
            if (this.isAssignedStudiesMode) {
                // En modo estudios asignados, no validar fechas
                console.log('📋 Aplicando filtros a estudios asignados...');
                this.updateCacheStatus('Cargando estudios asignados...', 'text-warning');
                
                // Mostrar indicador de carga
                const tableBody = document.querySelector('.studies-table tbody');
                if (tableBody) {
                    tableBody.innerHTML = '<tr><td colspan="9" class="text-center"><i class="fas fa-spinner fa-spin"></i> Cargando estudios asignados...</td></tr>';
                }
                
                // Cargar estudios asignados
                await this.loadAssignedStudies();
                
                // Asegurar que la preferencia de ordenamiento esté cargada antes de aplicar filtros
                await this.loadSortPreference();
                
                // Aplicar todos los filtros localmente
                this.applyLocalFilters();
                
                // Actualizar indicador de estado
                this.updateCacheStatus('Estudios asignados cargados', 'text-success');
                
            } else {
                // En modo PACS, validar que haya al menos un filtro (fechas o ID de paciente)
                // Comportamiento similar a pacs-manager:
                // - Si hay rango de fechas Y ID paciente: busca ID paciente en ese rango de fechas
                // - Si NO hay rango de fechas PERO SÍ hay ID paciente: busca ID paciente en todo Orthanc
                // - Si solo hay fechas: busca estudios en ese rango de fechas
                if (!this.currentFilters.dateFrom && !this.currentFilters.dateTo && !this.currentFilters.patientId) {
                    this.showError('Por favor especifica al menos un rango de fechas o ID de paciente para consultar estudios');
                    this.updateCacheStatus('Filtros requeridos para consultar PACS', 'text-warning');
                    return;
                }
                
                // Si hay fechas, validar que ambas estén presentes y que el rango sea válido
                if (this.currentFilters.dateFrom || this.currentFilters.dateTo) {
                    if (!this.currentFilters.dateFrom || !this.currentFilters.dateTo) {
                        this.showError('Si especificas fechas, debes indicar tanto "Desde" como "Hasta"');
                        this.updateCacheStatus('Rango de fechas incompleto', 'text-warning');
                        return;
                    }
                    
                    // Validar que la fecha "desde" no sea mayor que "hasta"
                    if (this.currentFilters.dateFrom > this.currentFilters.dateTo) {
                        this.showError('La fecha "Desde" no puede ser mayor que la fecha "Hasta"');
                        this.updateCacheStatus('Rango de fechas inválido', 'text-warning');
                        return;
                    }
                }
                
                // Actualizar indicador de estado
                this.updateCacheStatus('Consultando PACS...', 'text-warning');
                
                // Mostrar indicador de carga
                const tableBody = document.querySelector('.studies-table tbody');
                if (tableBody) {
                    tableBody.innerHTML = '<tr><td colspan="9" class="text-center"><i class="fas fa-spinner fa-spin"></i> Consultando PACS...</td></tr>';
                }
                
                // Recargar estudios desde el servidor (solo con filtros de fecha y paciente)
                await this.loadPacsStudies();
                
                // Asegurar que la preferencia de ordenamiento esté cargada antes de aplicar filtros
                await this.loadSortPreference();
                
                // Aplicar todos los filtros localmente
                this.applyLocalFilters();
                
                // Actualizar indicador de estado
                this.updateCacheStatus('Datos actualizados desde PACS', 'text-success');
            }
            
            // La persistencia la hace applyLocalFilters() al final (y loadMissingModalities al terminar modalidades)
            
        } catch (error) {
            console.error('Error aplicando filtros:', error);
            this.showError('Error aplicando filtros');
            this.updateCacheStatus('Error cargando estudios', 'text-danger');
        }
    }
    
    /**
     * Aplica filtros locales sin recargar desde el servidor
     */
    applyLocalFilters() {
        // Actualizar indicador de estado
        this.updateCacheStatus('Usando caché local', 'text-muted');
        
        // Recuperar modalidades ya consultadas en esta sesión (evita refetch al volver al dashboard)
        this.hydrateModalityFromSessionStorage();
        
        // 🔍 DEBUG: Agregar logs para diagnosticar el problema
        console.log('🔍 DEBUG applyLocalFilters - INICIO');
        console.log('🔍 DEBUG - this.studies.length:', this.studies.length);
        console.log('🔍 DEBUG - this.studies:', this.studies);
        console.log('🔍 DEBUG - currentFilters:', this.currentFilters);
        
        // Resetear paginación cuando se aplican filtros
        this.pagination.currentPage = 1;

        // PRIORIDAD: Identificar el estudio abierto en workspace (si existe).
        // Solo lo usamos como atajo de "incluir sin filtros" cuando pinWorkspaceOnTop está activo.
        // Cuando WS on Top = false, el estudio del WorkSpace pasa por todos los filtros como cualquier otro.
        const workspaceStudy = this.pinWorkspaceOnTop
            ? this.studies.find(study => this.isStudyOpenInWorkspace(study))
            : null;

        this.filteredStudies = this.studies.filter(study => {
            console.log('🔍 DEBUG - Evaluando estudio:', study.id, study.patient_name);

            // Atajo solo si WS on Top está activo: el estudio abierto en WorkSpace
            // se incluye sin pasar por los filtros (mantiene el comportamiento histórico).
            if (workspaceStudy && (study.id === workspaceStudy.id ||
                (study.study_instance_uid && study.study_instance_uid === workspaceStudy.study_instance_uid) ||
                (study.orthanc_study_id && study.orthanc_study_id === workspaceStudy.orthanc_study_id))) {
                console.log('✅ Estudio en workspace incluido sin filtros (WS on Top activo):', study.patient_name);
                return true;
            }
            
            // IMPORTANTE: Los estudios incompletos SIEMPRE se muestran (sin filtro de fecha).
            // Urgente / promesa / pendiente bypasean filtros SOLO mientras no tengan informe;
            // con informe aplican los mismos filtros que un estudio normal.
            const isIncomplete = study.is_incomplete ||
                               (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
            const isHighPriority = study.is_urgent ||
                           this.isHighPriorityPrioridad(study.prioridad);
            const hasInforme = this.studyHasInforme(study);
            const highPriorityBypass = isHighPriority && !hasInforme;
            const isPriority = highPriorityBypass || isIncomplete;

            if (isPriority) {
                console.log('🚨 Estudio prioritario incluido sin filtros:', study.patient_name, study.date, {
                    prioridad: study.prioridad,
                    incompleto: isIncomplete,
                    highPriorityBypass,
                    hasInforme
                });
            }
            
            // Filtro de fecha desde (NO aplica a prioritarios)
            if (!isPriority && this.currentFilters.dateFrom) {
                const studyDate = study.date || study.study_date || '';
                console.log('🔍 DEBUG - Filtro dateFrom:', this.currentFilters.dateFrom, 'vs studyDate:', studyDate);
                
                // Convertir ambas fechas al formato YYYYMMDD para comparación correcta
                const studyDateFormatted = studyDate.replace(/-/g, '');
                const filterDateFormatted = this.currentFilters.dateFrom.replace(/-/g, '');
                
                console.log('🔍 DEBUG - Comparación formateada:', filterDateFormatted, 'vs', studyDateFormatted);
                
                if (studyDateFormatted < filterDateFormatted) {
                    console.log('🔍 DEBUG - RECHAZADO por dateFrom');
                    return false;
                }
            }
            
            // Filtro de fecha hasta (NO aplica a prioritarios)
            if (!isPriority && this.currentFilters.dateTo) {
                const studyDate = study.date || study.study_date || '';
                console.log('🔍 DEBUG - Filtro dateTo:', this.currentFilters.dateTo, 'vs studyDate:', studyDate);
                
                // Convertir ambas fechas al formato YYYYMMDD para comparación correcta
                const studyDateFormatted = studyDate.replace(/-/g, '');
                const filterDateFormatted = this.currentFilters.dateTo.replace(/-/g, '');
                
                console.log('🔍 DEBUG - Comparación formateada:', studyDateFormatted, 'vs', filterDateFormatted);
                
                if (studyDateFormatted > filterDateFormatted) {
                    console.log('🔍 DEBUG - RECHAZADO por dateTo');
                    return false;
                }
            }
            
            // Filtro de ID de paciente (NO aplica a prioritarios)
            if (!isPriority && this.currentFilters.patientId && this.currentFilters.patientId.trim() !== '') {
                const patientId = (study.patient_id || '').toLowerCase();
                const filterPatientId = this.currentFilters.patientId.toLowerCase();
                if (!patientId.includes(filterPatientId)) {
                    return false;
                }
            }
            
            // Filtro de búsqueda general (NO aplica a prioritarios)
            if (!isPriority && this.currentFilters.search && this.currentFilters.search.trim() !== '') {
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
            
            // Filtro de institución (NO aplica a prioritarios)
            if (!isPriority && this.currentFilters.institutionName) {
                const studyInstitution = study.institution_name || '';
                if (studyInstitution !== this.currentFilters.institutionName) {
                    return false;
                }
            }

            // PACS: solo estudios asignados a esta cuenta (NO aplica a prioritarios)
            if (!isPriority &&
                this.hasPacsQueryPermission &&
                !this.isAssignedStudiesMode &&
                this.currentFilters.assignmentSource === 'assigned_only') {
                if (!this.isStudyAssignedToMe(study)) {
                    return false;
                }
            }

            // Filtro por estado de informe (NO aplica a incompletos ni a prioridad alta sin informe)
            if (!isPriority && this.currentFilters.reportStatus) {
                const studyReportStatus = this.getStudyReportStatus(study);
                const selectedStatus = this.currentFilters.reportStatus;

                if (selectedStatus === 'informed') {
                    if (studyReportStatus === 'none') {
                        return false;
                    }
                } else if (selectedStatus === 'pending_sign') {
                    if (studyReportStatus !== 'pending_sign') return false;
                } else if (selectedStatus === 'signed') {
                    if (studyReportStatus !== 'signed') return false;
                } else if (studyReportStatus !== selectedStatus) {
                    return false;
                }
            }
            
            // Filtro de modalidad local - selección múltiple (NO aplica a prioritarios)
            if (!isPriority && this.currentFilters.modalities && this.currentFilters.modalities.length > 0) {
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
                
                // Verificar si alguna de las modalidades del estudio coincide con alguna de las seleccionadas
                // study.modality puede ser una cadena como "CT, DOC" o una sola modalidad
                const studyModalities = study.modality ? study.modality.split(',').map(m => m.trim()) : [];
                const matchesModality = this.currentFilters.modalities.some(selectedModality => {
                    const dicomModality = modalityMap[selectedModality] || selectedModality;
                    // Verificar si la modalidad seleccionada está en las modalidades del estudio
                    return studyModalities.includes(dicomModality);
                });
                
                if (!matchesModality) {
                    return false;
                }
            }
            
            return true;
        });
        
        console.log('🔍 DEBUG - this.filteredStudies.length:', this.filteredStudies.length);
        console.log('🔍 DEBUG - this.filteredStudies:', this.filteredStudies);
        console.log(`🔍 DEBUG - sortConfig antes de renderizar: columna=${this.sortConfig.column}, dirección=${this.sortConfig.direction}`);
        
        this.renderStudies();
        // Actualizar iconos de ordenamiento para reflejar la preferencia guardada
        this.updateSortIcons();
        console.log(`✅ Iconos de ordenamiento actualizados: columna=${this.sortConfig.column}, dirección=${this.sortConfig.direction}`);
        this.updateStats();
        this.updateAvailableModalities(); // Actualizar botones de modalidad según estudios del periodo
        
        // Persistir después de aplicar filtros y renderizar (no al inicio: las modalidades se cargan async)
        this.savePersistentState();
    }
    
    /**
     * Rellena study.modality desde sessionStorage (clave escrita al resolver modalidad vía API).
     */
    hydrateModalityFromSessionStorage() {
        if (!this.studies || this.studies.length === 0) {
            return;
        }
        let count = 0;
        for (const study of this.studies) {
            if (study.modality && String(study.modality).trim() !== '') {
                continue;
            }
            try {
                const v = sessionStorage.getItem(`study_modality_${study.id}`);
                if (v && String(v).trim() !== '' && v !== 'N/A') {
                    study.modality = v;
                    count++;
                }
            } catch (e) {
                /* ignore */
            }
        }
        if (count > 0) {
            console.log(`📌 Modalidad restaurada desde sessionStorage: ${count} estudios`);
        }
    }
    
    /**
     * Renderiza los estudios en la tabla con paginación
     */
    renderStudies() {
        const tbody = document.querySelector('.studies-table tbody');
        console.log('🔍 DEBUG renderStudies - tbody encontrado:', !!tbody);
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        if (this.filteredStudies.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No se encontraron estudios con los criterios especificados</td></tr>';
            this.pagination.totalItems = 0;
            this.pagination.totalPages = 1;
            this.renderPagination();
            return;
        }
        
        // Ordenar estudios: primero el estudio abierto en workspace, luego por prioridad/badges, luego por columna seleccionada
        const sortedStudies = this.sortStudies([...this.filteredStudies]);
        
        // Si la preferencia "WS on Top" está activa y hay un estudio en workspace,
        // asegurar que esté en la primera página (consistente con sortStudies que lo
        // promueve al tope). Cuando la preferencia está desactivada, respetamos la
        // página actual para que el estudio aparezca en su orden natural.
        if (this.pinWorkspaceOnTop) {
            const workspaceStudyIndex = sortedStudies.findIndex(study => this.isStudyOpenInWorkspace(study));
            if (workspaceStudyIndex > 0 && workspaceStudyIndex >= this.pagination.perPage) {
                this.pagination.currentPage = 1;
            }
        }
        
        // Calcular paginación
        this.pagination.totalItems = sortedStudies.length;
        this.pagination.totalPages = Math.ceil(this.pagination.totalItems / this.pagination.perPage);
        
        // Asegurar que currentPage no exceda totalPages
        if (this.pagination.currentPage > this.pagination.totalPages) {
            this.pagination.currentPage = Math.max(1, this.pagination.totalPages);
        }
        
        // Calcular índices de inicio y fin
        const startIndex = (this.pagination.currentPage - 1) * this.pagination.perPage;
        const endIndex = Math.min(startIndex + this.pagination.perPage, sortedStudies.length);
        
        // Obtener estudios de la página actual
        const studiesToShow = sortedStudies.slice(startIndex, endIndex);
        
        // Renderizar estudios de la página actual
        studiesToShow.forEach(study => {
            const row = this.createStudyRow(study);
            tbody.appendChild(row);
        });
        
        // Renderizar paginación
        this.renderPagination();
        
        // Actualizar contador en el header
        this.updateStudiesCounter();
        
        // Actualizar indicadores de workspace después de renderizar
        setTimeout(() => {
            this.updateWorkspaceIndicators();
        }, 100);
        
        // Verificar estudios que podrían necesitar antecedentes después de renderizar
        setTimeout(async () => {
            this.updateAntecedentsCounters();
            await this.checkAndFixMissingAntecedents();
        }, 100);
        
        // Cargar modalidades faltantes en segundo plano
        this.loadMissingModalities();
    }
    
    /**
     * Carga modalidades en segundo plano para estudios que no las tengan
     * OPTIMIZADO: Carga en lotes para no sobrecargar el servidor
     * ACTUALIZADO: Carga modalidades para TODOS los estudios filtrados, no solo los de la página actual
     */
    async loadMissingModalities() {
        try {
            // Identificar estudios sin modalidad en TODOS los estudios filtrados
            const studiesWithoutModality = this.filteredStudies.filter(study => 
                !study.modality || study.modality === '' || study.modality.trim() === ''
            );
            
            if (studiesWithoutModality.length === 0) {
                return; // Todos los estudios ya tienen modalidad
            }
            
            // Priorizar estudios de la página actual
            const startIndex = (this.pagination.currentPage - 1) * this.pagination.perPage;
            const endIndex = Math.min(startIndex + this.pagination.perPage, this.filteredStudies.length);
            const visibleStudies = this.filteredStudies.slice(startIndex, endIndex);
            const visibleStudiesWithoutModality = visibleStudies.filter(study => 
                !study.modality || study.modality === '' || study.modality.trim() === ''
            );
            
            // Crear lista priorizada: primero los visibles, luego el resto
            const prioritizedStudies = [
                ...visibleStudiesWithoutModality,
                ...studiesWithoutModality.filter(study => 
                    !visibleStudiesWithoutModality.find(vs => vs.id === study.id)
                )
            ];
            
            console.log(`Cargando modalidades para ${prioritizedStudies.length} estudios (${visibleStudiesWithoutModality.length} visibles primero)...`);
            
            // Inicializar tracking de modalidades en carga para evitar duplicados
            if (!this.modalityLoadingSet) {
                this.modalityLoadingSet = new Set();
            }
            
            // Cargar modalidades en lotes de 20 para no sobrecargar
            const batchSize = 20;
            for (let i = 0; i < prioritizedStudies.length; i += batchSize) {
                const batch = prioritizedStudies.slice(i, i + batchSize);
                
                // Filtrar estudios que ya están siendo cargados
                const batchToLoad = batch.filter(study => !this.modalityLoadingSet.has(study.id));
                
                // Marcar como en carga
                batchToLoad.forEach(study => this.modalityLoadingSet.add(study.id));
                
                if (batchToLoad.length === 0) {
                    continue; // Todos los estudios de este lote ya están en carga
                }
                
                // Cargar modalidades en paralelo para este lote
                const promises = batchToLoad.map(async (study) => {
                    try {
                        // Usar el endpoint de detalles bajo demanda
                        const response = await fetch(`${this.apiBaseUrl}get_study_details.php?study_id=${study.id}`);
                        
                        if (!response.ok) {
                            console.warn(`Error ${response.status} cargando modalidad para estudio ${study.id}`);
                            // Remover del set si falla
                            this.modalityLoadingSet.delete(study.id);
                            return; // Continuar con el siguiente estudio
                        }
                        
                        const result = await response.json();
                        
                        if (result.success && result.data && result.data.modality && result.data.modality !== 'N/A') {
                            // Actualizar el estudio en el array principal
                            const studyIndex = this.studies.findIndex(s => s.id === study.id);
                            if (studyIndex !== -1) {
                                this.studies[studyIndex].modality = result.data.modality;
                            }
                            
                            // Actualizar en filteredStudies también
                            const filteredIndex = this.filteredStudies.findIndex(s => s.id === study.id);
                            if (filteredIndex !== -1) {
                                this.filteredStudies[filteredIndex].modality = result.data.modality;
                            }
                            
                            // Cachear modalidad en sessionStorage para que workspace/editor-workspace
                            // puedan usarla sin necesidad de llamar al PACS nuevamente
                            try {
                                sessionStorage.setItem(`study_modality_${study.id}`, result.data.modality);
                            } catch (e) { /* sessionStorage no disponible */ }
                            
                            // Actualizar la celda en la tabla si existe (puede estar en cualquier página)
                            const studyRow = document.querySelector(`tr[data-study-id="${study.id}"]`);
                            if (studyRow) {
                                const cells = studyRow.querySelectorAll('td');
                                // La modalidad está en la 5ta columna (índice 4) en dashboard-with-permissions
                                // (después de checkbox, nombre, fecha, hora)
                                if (cells.length > 4) {
                                    const modalityBadge = this.getModalityBadge(result.data.modality);
                                    cells[4].innerHTML = modalityBadge;
                                }
                            }
                            
                            // Verificar dinámicamente si el estudio está en la página actual
                            // (puede haber cambiado mientras se cargaba la modalidad)
                            const currentStartIndex = (this.pagination.currentPage - 1) * this.pagination.perPage;
                            const currentEndIndex = Math.min(currentStartIndex + this.pagination.perPage, this.filteredStudies.length);
                            const currentVisibleStudies = this.filteredStudies.slice(currentStartIndex, currentEndIndex);
                            const isCurrentlyVisible = currentVisibleStudies.some(s => s.id === study.id);
                            
                            if (isCurrentlyVisible) {
                                // Actualizar botones de modalidad disponibles si el estudio está visible
                                this.updateAvailableModalities();
                            }
                        }
                        
                        // Remover del set cuando termine (exitoso o no)
                        this.modalityLoadingSet.delete(study.id);
                    } catch (error) {
                        console.error(`Error cargando modalidad para estudio ${study.id}:`, error);
                        // Remover del set si hay error
                        this.modalityLoadingSet.delete(study.id);
                    }
                });
                
                // Esperar a que termine este lote antes de continuar
                await Promise.all(promises);
                
                // Pequeña pausa entre lotes para no sobrecargar
                if (i + batchSize < prioritizedStudies.length) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                }
            }
            
            console.log('Modalidades cargadas en segundo plano');
            // Guardar en localStorage con modalidades ya resueltas (antes solo se persistía sin ellas)
            this.savePersistentState();
        } catch (error) {
            console.error('Error cargando modalidades faltantes:', error);
        }
    }
    
    /**
     * Renderiza los controles de paginación
     */
    renderPagination() {
        const container = document.getElementById('paginationContainer');
        if (!container) return;
        
        const { currentPage, totalPages, totalItems, perPage } = this.pagination;
        
        // Si solo hay una página o menos, no mostrar paginación
        if (totalPages <= 1) {
            container.innerHTML = '';
            this.updatePaginationInfo();
            return;
        }
        
        let paginationHtml = '<nav aria-label="Paginación de estudios"><ul class="pagination justify-content-center mb-0">';
        
        // Botón anterior
        paginationHtml += `
            <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="dashboardWithPermissions.goToPage(${currentPage - 1}); return false;" aria-label="Anterior">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
        `;
        
        // Páginas
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="dashboardWithPermissions.goToPage(1); return false;">1</a></li>`;
            if (startPage > 2) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="dashboardWithPermissions.goToPage(${i}); return false;">${i}</a>
                </li>
            `;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="dashboardWithPermissions.goToPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }
        
        // Botón siguiente
        paginationHtml += `
            <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="dashboardWithPermissions.goToPage(${currentPage + 1}); return false;" aria-label="Siguiente">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
        `;
        
        paginationHtml += '</ul></nav>';
        
        container.innerHTML = paginationHtml;
        
        // Actualizar información de paginación
        this.updatePaginationInfo();
    }
    
    /**
     * Actualiza la información de paginación (contador)
     */
    updatePaginationInfo() {
        const paginationInfoEl = document.getElementById('paginationInfo');
        if (!paginationInfoEl) return;
        
        const { currentPage, totalPages, totalItems, perPage } = this.pagination;
        
        if (totalItems === 0) {
            paginationInfoEl.textContent = 'No hay resultados';
            return;
        }
        
        const start = (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, totalItems);
        
        paginationInfoEl.textContent = `Mostrando ${start} - ${end} de ${totalItems} estudio${totalItems !== 1 ? 's' : ''}`;
    }
    
    /**
     * Navega a una página específica
     */
    goToPage(page) {
        if (page < 1 || page > this.pagination.totalPages) {
            return;
        }
        
        this.pagination.currentPage = page;
        this.renderStudies();
        
        // Scroll suave hacia la tabla
        const table = document.querySelector('.studies-table');
        if (table) {
            table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    
    /**
     * Cambia el número de elementos por página
     */
    changePerPage(newPerPage) {
        this.pagination.perPage = parseInt(newPerPage);
        this.pagination.currentPage = 1; // Resetear a la primera página
        this.renderStudies();
    }
    
    /**
     * Actualiza el contador de estudios en el header de la tabla
     */
    updateStudiesCounter() {
        const badge = document.querySelector('.card-header .badge');
        if (badge) {
            const total = this.pagination.totalItems;
            badge.textContent = `${total} estudio${total !== 1 ? 's' : ''}`;
        }
    }
    
    /**
     * Escapa HTML para prevenir XSS y errores de sintaxis
     */
    escapeHtml(text) {
        if (text == null || text === undefined) {
            return '';
        }
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Escapa JavaScript para usar en atributos onclick y otros contextos JS
     */
    escapeJs(text) {
        if (text == null || text === undefined) {
            return '';
        }
        return String(text)
            .replace(/\\/g, '\\\\')  // Escapar backslashes primero
            .replace(/'/g, "\\'")   // Escapar comillas simples
            .replace(/"/g, '\\"')    // Escapar comillas dobles
            .replace(/\n/g, '\\n')   // Escapar saltos de línea
            .replace(/\r/g, '\\r')   // Escapar retornos de carro
            .replace(/\t/g, '\\t');  // Escapar tabs
    }
    
    /**
     * Verifica si un estudio está abierto en el workspace
     */
    isStudyOpenInWorkspace(study) {
        try {
            // Verificar tanto sessionStorage (workspace normal/mismo tab) como
            // localStorage (workspace popup multimonitor en otra ventana)
            const sources = [
                sessionStorage.getItem('workspace_study_data'),
                localStorage.getItem('workspace_study_data_mm')
            ];

            for (const raw of sources) {
                if (!raw) continue;
                const studyData = JSON.parse(raw);
                if (studyData.studyId && studyData.studyId === study.id) return true;
                if (studyData.studyInstanceUID && study.study_instance_uid &&
                    studyData.studyInstanceUID === study.study_instance_uid) return true;
                if (studyData.orthancStudyId && study.orthanc_study_id &&
                    studyData.orthancStudyId === study.orthanc_study_id) return true;
            }
            return false;
        } catch (e) {
            console.error('Error verificando estudio en workspace:', e);
            return false;
        }
    }

    /**
     * Crea una fila de la tabla para un estudio
     */
    createStudyRow(study) {
        const row = document.createElement('tr');
        
        // Agregar atributo data-study-id a la fila para identificación
        row.setAttribute('data-study-id', study.id);
        
        // Verificar si este estudio está abierto en workspace
        const isOpenInWorkspace = this.isStudyOpenInWorkspace(study);
        if (isOpenInWorkspace) {
            row.classList.add('study-open-in-workspace');
            row.setAttribute('data-workspace-open', 'true');
        }

        const rs = this.getStudyReportStatus(study);
        if (rs === 'pending_sign') {
            row.classList.add('study-pending-sign');
            row.style.backgroundColor = 'rgba(13, 202, 240, 0.12)';
        } else if (rs === 'signed') {
            row.classList.add('study-signed-report');
            row.style.backgroundColor = 'rgba(13, 110, 253, 0.08)';
        }
        
        // Usar los nombres correctos de las propiedades
        const formattedDate = this.formatDate(study.date || study.study_date);
        const formattedTime = this.formatTime(study.time || study.study_time || '');
        const modalityBadge = this.getModalityBadge(study.modality);
        
        // Escapar todos los datos del usuario para prevenir XSS y errores de sintaxis
        const safePatientName = this.escapeHtml(study.patient_name || '');
        const safePatientId = this.escapeHtml(study.patient_id || '');
        const safeStudyDescription = this.escapeHtml(study.study_description || 'N/A');
        const safeStudyIdJs = this.escapeJs(study.id || '');
        
        // Verificar si hay un informe en progreso para este estudio
        const hasInProgressReport = this.checkInProgressReport(study.id);
        const reportButtonClass = hasInProgressReport ? 'btn-warning' : 'btn-report';
        const reportButtonText = hasInProgressReport ? 'Continuar' : 'Informe';
        const reportButtonIcon = hasInProgressReport ? 'fas fa-edit' : 'fas fa-file-medical';
        
        // Generar botones de acciones según el modo
        const actionButtons = this.generateActionButtons(study, hasInProgressReport, reportButtonClass, reportButtonText, reportButtonIcon);
        
        // Generar columna de antecedentes con botón clickeable
        const antecedentsCount = study.antecedents ? study.antecedents.total_count : 0;
        const hasAntecedents = study.antecedents && (
            study.antecedents.has_notes || 
            study.antecedents.has_files || 
            study.antecedents.has_images || 
            study.antecedents.has_camera_captures ||
            antecedentsCount > 0
        );
        
        // Verificar permiso de antecedentes antes de renderizar
        const hasAntecedentesPermiso = this.hasAntecedentesPermission === true;
        
        // Si no hay datos de antecedentes pero el estudio debería tenerlos, forzar consulta
        if (!hasAntecedents && !study.antecedents) {
            // Marcar para consulta posterior (solo una vez)
            if (!study._needsAntecedentsCheck) {
                study._needsAntecedentsCheck = true;
            }
        }
        
        const antecedentsColumn = `
            <td class="text-center" data-label="Antecedentes">
                ${hasAntecedentesPermiso && hasAntecedents ? 
                    `<span class="position-relative d-inline-block">
                        <span class="badge bg-warning text-dark cursor-pointer" 
                              style="font-size: 0.9rem; padding: 0.5rem 0.75rem; cursor: pointer; border-radius: 0.5rem;" 
                              title="Ver Antecedentes (${antecedentsCount} antecedente${antecedentsCount > 1 ? 's' : ''})" 
                              onclick="dashboardWithPermissions.showAntecedents('${safeStudyIdJs}')">
                            <i class="fas fa-file-medical me-1"></i>
                            <span class="fw-bold">${antecedentsCount}</span>
                        </span>
                    </span>` : 
                    '<span class="text-muted">-</span>'
                }
            </td>
        `;
        
        // Indicador visual de estudio abierto en workspace (en columna Estado, segunda línea)
        const workspaceIndicator = isOpenInWorkspace ? `
            <div style="margin-top: 4px;">
                <span class="badge bg-info text-white workspace-indicator" 
                      title="Este estudio está abierto en WorkSpace" 
                      style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                    <i class="fas fa-th-large me-1"></i>
                    En WorkSpace
                </span>
            </div>
        ` : '';

        const queueIndicator = this.getQueueIndicatorHtml(study);
        
        const rowHTML = `
            <td data-label="Fecha">${formattedDate}</td>
            <td data-label="Hora">${formattedTime}</td>
            <td data-label="Paciente">
                <div class="fw-semibold">${safePatientName}</div>
                <small class="text-muted">ID: ${safePatientId}</small>
            </td>
            <td class="d-none d-lg-table-cell" data-label="ID">${safePatientId}</td>
            <td data-label="Modalidad">${modalityBadge}</td>
            <td data-label="Estudio">${safeStudyDescription}</td>
            <td data-label="Estado">
                <div>
                    ${this.getStatusBadge(study)}
                    ${this.getPriorityBadge(study)}
                    ${this.getIncompleteBadge(study)}
                </div>
                ${workspaceIndicator}
                ${queueIndicator}
            </td>
            ${antecedentsColumn}
            <td data-label="Acciones">
                ${actionButtons}
            </td>
        `;
        
        row.innerHTML = rowHTML;
        
        // Agregar event listener para menú contextual (click derecho)
        row.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.showContextMenu(e, study);
        });
        
        return row;
    }
    
    /**
     * Obtiene el badge de prioridad del estudio
     * Usa show_urgent_badge de la API si está disponible, sino usa study.prioridad
     */
    getPriorityBadge(study) {
        // Si la API proporcionó el flag show_urgent_badge, usarlo directamente
        if (study.show_urgent_badge === false) {
            return ''; // La API dice que no mostrar badge urgente
        }
        
        // Si show_urgent_badge es true, mostrar badge según la prioridad
        if (study.show_urgent_badge === true) {
            const prioridad = study.prioridad || 'urgente'; // Si no hay prioridad pero el flag es true, asumir urgente
            if (prioridad === 'urgente') {
                return `<span class="badge bg-danger priority-badge priority-urgente ms-1" title="Prioridad Urgente" style="font-weight: bold; animation: pulse-danger 2s ease-in-out infinite;">
                    <i class="fas fa-exclamation-circle me-1"></i>Urgente
                </span>`;
            } else if (prioridad === 'promesa') {
                return `<span class="badge bg-warning text-dark priority-badge priority-promesa ms-1" title="Prioridad Promesa" style="font-weight: bold; animation: pulse-warning-incompletos 2s ease-in-out infinite;">
                    <i class="fas fa-flag me-1"></i>Promesa
                </span>`;
            } else if (prioridad === 'pendiente') {
                return `<span class="badge priority-badge priority-pendiente ms-1" title="Prioridad Pendiente" style="background-color:#6f42c1;color:#fff;font-weight:bold;animation:pulse-warning-incompletos 2s ease-in-out infinite;">
                    <i class="fas fa-hourglass-half me-1"></i>Pendiente
                </span>`;
            }
        }

        // Fallback: usar study.prioridad si no hay flag de la API
        let prioridad = study.prioridad;
        
        // Si no hay prioridad válida en study.prioridad, intentar obtenerla del flag
        if (!prioridad || prioridad === 'normal') {
            // Intentar obtener de informes_incompletos.prioridad
            if (study.informes_incompletos && study.informes_incompletos.prioridad) {
                const flagPrioridad = study.informes_incompletos.prioridad;
                // Solo usar si es una prioridad válida (urgente o promesa)
                if (flagPrioridad === 'urgente' || flagPrioridad === 'promesa' || flagPrioridad === 'pendiente') {
                    prioridad = flagPrioridad;
                    // Sincronizar: guardar la prioridad en study.prioridad para mantener consistencia
                    study.prioridad = prioridad;
                } else {
                    prioridad = 'normal';
                }
            } else {
                prioridad = 'normal';
            }
        }
        
        // Asegurar que siempre se use el mismo estilo, independientemente del origen
        // NUNCA mostrar badge si la prioridad es 'normal' o no válida
        if (prioridad === 'urgente') {
            return `<span class="badge bg-danger priority-badge priority-urgente ms-1" title="Prioridad Urgente" style="font-weight: bold; animation: pulse-danger 2s ease-in-out infinite;">
                <i class="fas fa-exclamation-circle me-1"></i>Urgente
            </span>`;
        } else if (prioridad === 'promesa') {
            return `<span class="badge bg-warning text-dark priority-badge priority-promesa ms-1" title="Prioridad Promesa" style="font-weight: bold; animation: pulse-warning-incompletos 2s ease-in-out infinite;">
                <i class="fas fa-flag me-1"></i>Promesa
            </span>`;
        } else if (prioridad === 'pendiente') {
            return `<span class="badge priority-badge priority-pendiente ms-1" title="Prioridad Pendiente" style="background-color:#6f42c1;color:#fff;font-weight:bold;animation:pulse-warning-incompletos 2s ease-in-out infinite;">
                <i class="fas fa-hourglass-half me-1"></i>Pendiente
            </span>`;
        }

        return ''; // Sin badge para prioridad normal o inválida
    }
    
    /**
     * Obtiene el badge de informes incompletos
     * Se muestra independientemente de la prioridad
     * Usa show_incomplete_badge de la API si está disponible
     */
    getIncompleteBadge(study) {
        // Si la API proporcionó el flag show_incomplete_badge, usarlo directamente
        if (study.show_incomplete_badge === false) {
            return ''; // La API dice que no mostrar badge incompleto
        }
        
        // Si show_incomplete_badge es true o no está definido, verificar informes_incompletos
        const informesIncompletos = study.informes_incompletos || null;
        const hasInformesIncompletos = study.show_incomplete_badge === true || 
                                      (informesIncompletos && informesIncompletos.informes_incompletos === true);
        
        if (hasInformesIncompletos) {
            // Recopilar todas las notas de informes incompletos
            let allNotes = [];
            
            // Si hay múltiples informes con notas (array de objetos)
            if (informesIncompletos && informesIncompletos.notas_por_informe && Array.isArray(informesIncompletos.notas_por_informe)) {
                informesIncompletos.notas_por_informe.forEach(item => {
                    if (item.nota) {
                        allNotes.push(`#${item.informe_id}: ${item.nota}`);
                    }
                });
            }
            // Si solo hay una nota general (compatibilidad con formato antiguo)
            else if (informesIncompletos && informesIncompletos.nota) {
                allNotes.push(informesIncompletos.nota);
            }
            
            const title = allNotes.length > 0 
                ? `Informes incompletos:\n${allNotes.join('\n')}` 
                : 'Estudio marcado como informes incompletos';
                
            return `<span class="badge bg-warning text-dark ms-1 incomplete-badge" title="${this.escapeHtml(title)}" style="font-weight: bold; animation: pulse-warning-incompletos 2s ease-in-out infinite;">
                <i class="fas fa-exclamation-triangle me-1"></i>Incompleto${allNotes.length > 1 ? ` (${allNotes.length})` : ''}
            </span>`;
        }
        
        return ''; // Sin badge si no está marcado como incompleto
    }
    
    /**
     * Obtiene el badge de estado según el tipo de asignación y permisos PACS QUERY
     */
    getStatusBadge(study) {
        // Si el usuario tiene permiso PACS QUERY, los estudios se leen directamente del PACS
        // Por lo tanto, mostrar "ONLINE" en lugar de asignado/derivado
        if (this.hasPacsQueryPermission) {
            return `<span class="badge bg-info" title="Estudio leído directamente desde PACS">
                <i class="fas fa-wifi me-1"></i>ONLINE
            </span>`;
        }
        
        // Si no tiene permiso PACS QUERY, usar la lógica de asignado/derivado
        // Determinar si es asignado o derivado basado en source_type de la API
        const sourceType = study.source_type || 'assigned'; // Por defecto 'assigned' si no viene el campo
        
        if (sourceType === 'subassigned') {
            // Estudio derivado (subasignado)
            return `<span class="badge bg-success" title="Este estudio fue derivado desde una cuenta padre">
                <i class="fas fa-share-square me-1"></i>Derivado
            </span>`;
        } else {
            // Estudio asignado directamente
            return `<span class="badge bg-primary" title="Este estudio fue asignado directamente a tu cuenta">
                <i class="fas fa-user-check me-1"></i>Asignado
            </span>`;
        }
    }

    /**
     * Obtiene un estado de informe normalizado para filtros y tooltips.
     * Estados: none | informed_pending_pacs | published_pacs | pending_sign | signed | informed
     */
    getStudyReportStatus(study) {
        const reportInfo = study.report_info || {};
        const estados = Array.isArray(reportInfo.estados_disponibles)
            ? reportInfo.estados_disponibles.map(e => String(e).toLowerCase())
            : String(reportInfo.estados_disponibles || '').split(',').map(e => e.trim().toLowerCase()).filter(Boolean);
        if (estados.includes('transcripto') || String(reportInfo.ultimo_estado || '').toLowerCase() === 'transcripto') {
            return 'pending_sign';
        }
        if (estados.includes('firmado') || String(reportInfo.ultimo_estado || '').toLowerCase() === 'firmado') {
            // Si también hay finalizado/publicado, preferir published cuando aplique
            if (!reportInfo.published_to_pacs) return 'signed';
        }

        const explicitStatus = (study.report_status || '').trim();
        if (explicitStatus === 'none' || explicitStatus === 'informed_pending_pacs' || explicitStatus === 'published_pacs'
            || explicitStatus === 'pending_sign' || explicitStatus === 'signed') {
            return explicitStatus;
        }

        const totalInformes = Number(reportInfo.total_informes || 0);
        const hasReport = Boolean(reportInfo.has_report) || totalInformes > 0;
        const publishedToPacs = Boolean(reportInfo.published_to_pacs);

        if (!hasReport) return 'none';
        return publishedToPacs ? 'published_pacs' : 'informed_pending_pacs';
    }

    async loadPendingSignCount() {
        try {
            const token = this._pendingSignToken();
            const resp = await fetch('api/informes/pending-sign.php?count_only=1', {
                headers: token ? { Authorization: `Bearer ${token}` } : {}
            });
            const data = await resp.json();
            const n = (data && data.success) ? Number(data.count || 0) : 0;
            const el = document.getElementById('informesParaFirmar');
            if (el) el.textContent = String(n);
            const wrap = document.getElementById('paraFirmarStat');
            if (wrap && !wrap.dataset.pendingSignBound) {
                wrap.dataset.pendingSignBound = '1';
                wrap.style.cursor = 'pointer';
                wrap.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.openPendingSignModal();
                });
            }
            this._bindPendingSignModalOnce();
        } catch (e) {
            console.warn('pending-sign count:', e);
        }
    }

    _pendingSignToken() {
        if (typeof AuthMiddleware !== 'undefined' && AuthMiddleware.getToken) {
            return AuthMiddleware.getToken() || '';
        }
        return localStorage.getItem('session_token')
            || sessionStorage.getItem('session_token')
            || '';
    }

    _bindPendingSignModalOnce() {
        if (this._pendingSignModalBound) return;
        this._pendingSignModalBound = true;
        const filterBtn = document.getElementById('pendingSignFilterBtn');
        const clearBtn = document.getElementById('pendingSignClearBtn');
        const refreshBtn = document.getElementById('pendingSignRefreshBtn');
        if (filterBtn) filterBtn.addEventListener('click', () => this.loadPendingSignModalList());
        if (refreshBtn) refreshBtn.addEventListener('click', () => this.loadPendingSignModalList());
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                const a = document.getElementById('pendingSignFechaInicio');
                const b = document.getElementById('pendingSignFechaFin');
                if (a) a.value = '';
                if (b) b.value = '';
                this.loadPendingSignModalList();
            });
        }
        const tbody = document.getElementById('pendingSignTbody');
        if (tbody) {
            tbody.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-ps-action]');
                if (!btn) return;
                const id = parseInt(btn.getAttribute('data-ps-id') || '0', 10);
                const action = btn.getAttribute('data-ps-action');
                const editable = btn.getAttribute('data-ps-editable') === '1';
                if (!id) return;
                if (action === 'view') {
                    this.openInformeInManager(id, 'view');
                } else if (action === 'edit') {
                    if (!editable) {
                        this.showError('Los informes PDF externos no se editan; use Ver o Firmar.');
                        return;
                    }
                    this.openInformeInManager(id, 'edit');
                } else if (action === 'sign') {
                    this.firmarInformeFromDashboard(id);
                }
            });
        }
    }

    openPendingSignModal() {
        const modalEl = document.getElementById('pendingSignModal');
        if (!modalEl) {
            this.showError('Modal de firma no disponible');
            return;
        }
        this._bindPendingSignModalOnce();
        if (typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
        this.loadPendingSignModalList();
    }

    async loadPendingSignModalList() {
        const loading = document.getElementById('pendingSignLoading');
        const empty = document.getElementById('pendingSignEmpty');
        const tbody = document.getElementById('pendingSignTbody');
        const countBadge = document.getElementById('pendingSignModalCount');
        const fi = document.getElementById('pendingSignFechaInicio')?.value || '';
        const ff = document.getElementById('pendingSignFechaFin')?.value || '';
        if (loading) loading.classList.remove('d-none');
        if (empty) empty.classList.add('d-none');
        if (tbody) tbody.innerHTML = '';
        try {
            const token = this._pendingSignToken();
            const params = new URLSearchParams({ limit: '100' });
            if (fi) params.set('fecha_inicio', fi);
            if (ff) params.set('fecha_fin', ff);
            const resp = await fetch('api/informes/pending-sign.php?' + params.toString(), {
                headers: token ? { Authorization: `Bearer ${token}` } : {}
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.message || 'Error al cargar');
            const items = data.items || [];
            if (countBadge) countBadge.textContent = String(data.count ?? items.length);
            // Actualizar contador del banner con el total sin filtro de fechas del modal
            // (el badge del modal refleja el filtro actual)
            if (!fi && !ff) {
                const el = document.getElementById('informesParaFirmar');
                if (el) el.textContent = String(data.count ?? items.length);
            }
            if (!items.length) {
                if (empty) empty.classList.remove('d-none');
                return;
            }
            if (!tbody) return;
            tbody.innerHTML = items.map((it) => {
                const id = it.id;
                const editable = it.editable !== false && it.origen !== 'externo';
                const origenLabel = (it.origen === 'externo')
                    ? '<span class="badge bg-secondary">PDF externo</span>'
                    : '<span class="badge bg-success">Plataforma</span>';
                const editBtn = editable
                    ? `<button type="button" class="btn btn-sm btn-outline-primary" data-ps-action="edit" data-ps-id="${id}" data-ps-editable="1" title="Editar en Gestión Informes"><i class="fas fa-edit"></i></button>`
                    : `<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="No editable"><i class="fas fa-edit"></i></button>`;
                return `<tr>
                    <td><small>${this.escapeHtml(it.fecha_modificacion_fmt || '')}</small></td>
                    <td>
                        <div class="fw-medium">${this.escapeHtml(it.patient_name || '—')}</div>
                        <small class="text-muted">${this.escapeHtml(it.patient_id || '')}</small>
                    </td>
                    <td><span class="badge bg-light text-dark border">${this.escapeHtml(it.modality || '—')}</span></td>
                    <td><small>${this.escapeHtml(it.titulo || ('Informe #' + id))}</small></td>
                    <td>${origenLabel}</td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" data-ps-action="view" data-ps-id="${id}" title="Ver"><i class="fas fa-eye"></i></button>
                            ${editBtn}
                            <button type="button" class="btn btn-primary" data-ps-action="sign" data-ps-id="${id}" title="Firmar / aprobar"><i class="fas fa-signature"></i> Firmar</button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        } catch (e) {
            if (empty) {
                empty.classList.remove('d-none');
                empty.textContent = e.message || 'Error al cargar la lista';
            }
        } finally {
            if (loading) loading.classList.add('d-none');
        }
    }

    openInformeInManager(informeId, mode) {
        const m = mode === 'edit' ? 'edit' : (mode === 'sign' ? 'sign' : 'view');
        const url = `components/informes-manager.html?informe_id=${encodeURIComponent(informeId)}&mode=${m}&v=202609030030`;
        // Preferir misma ventana/iframe padre si el dashboard está embebido
        try {
            if (window.top && window.top !== window && window.top.location) {
                window.top.location.href = url;
                return;
            }
        } catch (e) { /* cross-origin */ }
        window.location.href = url;
    }

    async firmarInformeFromDashboard(informeId) {
        if (!confirm('¿Aprobar y firmar este informe?\nPasará a estado Firmado. El transcriptor lo finalizará y publicará en PACS.')) {
            return;
        }
        try {
            const token = this._pendingSignToken();
            const resp = await fetch('api/informes/sign.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { Authorization: `Bearer ${token}` } : {})
                },
                body: JSON.stringify({ id: informeId, session_token: token })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.message || 'No se pudo firmar');
            this.showSuccess(data.message || 'Informe firmado (aprobado)');
            try {
                const detail = { informe_id: informeId, estado: 'firmado', source: 'dashboard-pending-sign', ts: Date.now() };
                window.dispatchEvent(new CustomEvent('informeEstadoCambiado', { detail }));
                localStorage.setItem('informe_estado_cambiado_event', JSON.stringify(detail));
            } catch (e) {}
            await this.loadPendingSignModalList();
            await this.loadPendingSignCount();
        } catch (e) {
            this.showError(e.message || 'Error al firmar');
        }
    }

    setupInformeEstadoCambiadoListener() {
        const refresh = () => {
            try { this.loadPendingSignCount(); } catch (e) {}
            const modalEl = document.getElementById('pendingSignModal');
            if (modalEl && modalEl.classList.contains('show')) {
                try { this.loadPendingSignModalList(); } catch (e) {}
            }
        };
        window.addEventListener('informeEstadoCambiado', refresh);
        window.addEventListener('message', (e) => {
            if (e.data && e.data.type === 'informeEstadoCambiado') refresh();
        });
        window.addEventListener('storage', (e) => {
            if (e.key === 'informe_estado_cambiado_event') refresh();
        });
    }
    
    /**
     * Genera los botones de acciones según el modo
     */
    generateActionButtons(study, hasInProgressReport, reportButtonClass, reportButtonText, reportButtonIcon) {
        // Escapar todos los datos para JavaScript
        const safeStudyIdJs = this.escapeJs(study.id || '');
        const safeViewerUrlJs = this.escapeJs(study.viewer_url || '');
        const safeDownloadIdJs = this.escapeJs(study.orthanc_study_id || study.id || '');
        const safePatientIdJs = this.escapeJs(study.patient_id || '');
        const safePatientNameJs = this.escapeJs(study.patient_name || '');
        const safeModalityJs = this.escapeJs(study.modality || '');
        const safeStudyDescriptionJs = this.escapeJs(study.study_description || '');
        const safeStudyInstanceUIDJs = this.escapeJs(study.study_instance_uid || '');
        const safeStudyDateJs = this.escapeJs(study.date || study.study_date || '');
        
        let buttons = `
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-action btn-info" title="Información" 
                        onclick="dashboardWithPermissions.showStudyInfo('${safeStudyIdJs}')">
                    <i class="fas fa-info-circle"></i>
                </button>
        `;
        
        // Botón de Ver (siempre disponible si tiene viewer_url)
        if (study.viewer_url && study.viewer_url !== '#') {
            buttons += `
                <button class="btn btn-sm btn-action btn-view" title="Ver" onclick="window.open('${safeViewerUrlJs}', '_blank')">
                    <i class="fas fa-eye"></i>
                    <span class="d-none d-sm-inline ms-1">Ver</span>
                </button>
            `;
        }
        
        // Botón de Descargar (siempre disponible si tiene orthanc_study_id o study_id)
        const downloadId = study.orthanc_study_id || study.id;
        if (downloadId) {
            buttons += `
                <button class="btn btn-sm btn-action btn-download" title="Descargar" 
                        data-study-id="${this.escapeHtml(study.id || '')}" 
                        data-orthanc-study-id="${this.escapeHtml(downloadId)}" 
                        onclick="dashboardWithPermissions.downloadStudy('${safeDownloadIdJs}')">
                    <i class="fas fa-download"></i>
                    <span class="d-none d-md-inline ms-1">Descargar</span>
                </button>
            `;
        }
        
        // Botón de informe (verificar permiso para ocultar)
        // Si el usuario tiene el permiso "ocultar_informes", no mostrar el botón
        if (!this.hasOcultarInformesPermission) {
            const reportInfo = study.report_info || {};
            const totalInformes = reportInfo.total_informes || 0;
            const hasInformes = totalInformes > 0;
            const studyReportStatus = this.getStudyReportStatus(study);
            
            // Verificar si tiene flag de informes incompletos
            const informesIncompletos = study.informes_incompletos || null;
            const hasInformesIncompletos = informesIncompletos && informesIncompletos.informes_incompletos === true;
            
            // Recopilar todas las notas de informes incompletos
            let allNotes = [];
            if (hasInformesIncompletos) {
                // Si hay múltiples informes con notas (array de objetos)
                if (informesIncompletos.notas_por_informe && Array.isArray(informesIncompletos.notas_por_informe)) {
                    informesIncompletos.notas_por_informe.forEach(item => {
                        if (item.nota) {
                            allNotes.push(`#${item.informe_id}: ${item.nota}`);
                        }
                    });
                }
                // Si solo hay una nota general (compatibilidad con formato antiguo)
                else if (informesIncompletos.nota) {
                    allNotes.push(informesIncompletos.nota);
                }
            }
            
            // Construir tooltip
            // Si hay informes incompletos, priorizar ese mensaje sobre todo lo demás
            let tooltipText;
            if (hasInformesIncompletos) {
                if (allNotes.length > 0) {
                    tooltipText = `⚠️ Informes incompletos:\n${allNotes.join('\n')}`;
                } else {
                    tooltipText = '⚠️ Informes incompletos';
                }
            } else if (hasInProgressReport) {
                tooltipText = 'Continuar Informe en Progreso';
            } else if (studyReportStatus === 'published_pacs') {
                tooltipText = `Informe publicado en PACS${hasInformes ? ` (${totalInformes})` : ''}`;
            } else if (studyReportStatus === 'informed_pending_pacs') {
                tooltipText = `Informe realizado (pendiente de publicar en PACS)${hasInformes ? ` (${totalInformes})` : ''}`;
            } else {
                // El botón verde es siempre para generar informes nuevos
                tooltipText = 'Generar Informe';
            }
            
            // Clase adicional para efecto visual de informes incompletos
            // Si tiene informes incompletos, usar un color más llamativo (danger/rojo) con animación
            let finalButtonClass = reportButtonClass;
            const incompletosClass = hasInformesIncompletos ? ' btn-informes-incompletos' : '';
            
            // Si tiene informes incompletos, usar btn-danger (rojo) para que sea más llamativo
            // Si también está en progreso, mantener warning pero con la animación
            if (hasInformesIncompletos) {
                if (!hasInProgressReport) {
                    finalButtonClass = 'btn-danger'; // Rojo más llamativo cuando no está en progreso
                } else {
                    finalButtonClass = 'btn-warning'; // Amarillo si está en progreso pero con animación
                }
            }
            
            // Escapar HTML para tooltip
            const escapeHtml = (text) => {
                if (!text) return '';
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return String(text).replace(/[&<>"']/g, m => map[m]);
            };
            
            // Agregar clase position-relative para el contador superpuesto
            // Usar data-bs-title para que Bootstrap tooltip lo maneje correctamente
            const reportButtonWithCounter = `
                <button class="btn btn-sm btn-action ${finalButtonClass}${incompletosClass} position-relative" 
                        data-bs-title="${escapeHtml(tooltipText)}" 
                        title="${escapeHtml(tooltipText)}"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        data-study-id="${this.escapeHtml(study.id || '')}" 
                        data-orthanc-study-id="${this.escapeHtml(study.orthanc_study_id || study.id || '')}" 
                        onclick="generateReport('${safePatientIdJs}', '${safePatientNameJs}', '${safeModalityJs}', '${safeStudyDescriptionJs}', '${safeStudyIdJs}', '${safeStudyInstanceUIDJs}', '${safeStudyDateJs}')">
                    <i class="${reportButtonIcon}"></i>
                    <span class="d-none d-lg-inline ms-1">${reportButtonText}</span>
                    ${hasInformes ? `
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
                              style="font-size: 0.65rem; padding: 0.25rem 0.4rem; min-width: 1.2rem; z-index: 10; box-shadow: 0 0 0 2px white;">
                            ${totalInformes}
                        </span>
                    ` : ''}
                </button>
            `;
            
            buttons += reportButtonWithCounter;
        }
        buttons += `
        </div>
        `;
        
        return buttons;
    }
    
    /**
     * Carga estudios marcados como incompletos desde study_flags
     * Para PACS Query: Consulta Orthanc directamente por ID
     * Para usuarios sin PACS Query: Obtiene datos desde study_assignments
     * Se cargan FRESH siempre (sin caché) para reflejar cambios en tiempo real
     */
    async loadIncompleteStudies() {
        try {
            console.log('🔴 Cargando estudios incompletos...');
            
            // 1. Obtener IDs de estudios incompletos (ya filtrados por permisos en la API)
            const response = await fetch(`${this.apiBaseUrl}get_incomplete_studies.php`);
            const result = await response.json();
            
            if (!result.success || !result.data.incomplete_study_ids.length) {
                console.log('✅ No hay estudios incompletos');
                return [];
            }
            
            const incompleteStudyIds = result.data.incomplete_study_ids;
            console.log(`📋 Encontrados ${incompleteStudyIds.length} estudios incompletos`);
            
            let incompleteStudies = [];
            
            // 2. Obtener datos completos según permisos del usuario
            if (this.hasPacsQueryPermission) {
                // Usuario CON PACS Query: consultar Orthanc directamente
                const orthancIds = incompleteStudyIds
                    .map(item => item.orthanc_id)
                    .filter(id => id && id !== 'null');
                
                if (orthancIds.length === 0) {
                    console.warn('⚠️ No hay orthanc_ids válidos para consultar');
                    return [];
                }
                
                console.log(`📝 IDs a consultar en Orthanc: ${orthancIds.join(', ')}`);
                
                const idsParam = orthancIds.join(',');
                const studiesResponse = await fetch(`${this.apiBaseUrl}get_studies_by_ids.php?ids=${idsParam}`);
                const studiesResult = await studiesResponse.json();
                
                if (!studiesResult.success) {
                    throw new Error(studiesResult.message || 'Error obteniendo estudios desde Orthanc');
                }
                
                incompleteStudies = studiesResult.data || [];
                
                // Agregar flags de la API a los estudios obtenidos del PACS
                incompleteStudies.forEach(study => {
                    const flag = incompleteStudyIds.find(f => 
                        f.study_id === study.id ||
                        f.orthanc_id === study.id || 
                        f.orthanc_id === study.orthanc_study_id ||
                        f.study_instance_uid === study.study_instance_uid ||
                        (f.study_id && f.study_id === study.orthanc_study_id)
                    );
                    
                    if (flag) {
                        // Usar los flags de la API para determinar visibilidad de badges
                        if (flag.show_incomplete_badge !== undefined) {
                            study.show_incomplete_badge = flag.show_incomplete_badge;
                        }
                        if (flag.show_urgent_badge !== undefined) {
                            study.show_urgent_badge = flag.show_urgent_badge;
                        }
                        // Agregar información de incompletos
                        study.informes_incompletos = {
                            informes_incompletos: true,
                            nota: flag.nota,
                            notas_por_informe: flag.notas_por_informe || []
                        };
                        study.is_incomplete = true;
                        // Preservar prioridad si existe
                        if (flag.prioridad && this.isHighPriorityPrioridad(flag.prioridad)) {
                            study.prioridad = flag.prioridad;
                            study.is_urgent = true;
                        }
                    }
                });
            } else {
                // Usuario SIN PACS Query: obtener datos desde study_assignments
                console.log('📦 Usuario sin PACS Query: obteniendo estudios desde study_assignments');
                
                const assignedResponse = await fetch(`${this.apiBaseUrl}get_user_assigned_studies_fixed.php`);
                const assignedResult = await assignedResponse.json();
                
                if (!assignedResult.success) {
                    throw new Error(assignedResult.error || 'Error obteniendo estudios asignados');
                }
                
                const assignedStudies = assignedResult.data.studies || [];
                
                // Filtrar estudios que están en la lista de incompletos y están asignados/derivados
                const incompleteFromAssigned = assignedStudies.filter(study => {
                    return incompleteStudyIds.some(flag => 
                        flag.study_id === study.id ||
                        flag.orthanc_id === study.id || 
                        flag.orthanc_id === study.orthanc_study_id ||
                        flag.study_instance_uid === study.study_instance_uid ||
                        (flag.study_id && flag.study_id === study.orthanc_study_id)
                    );
                });
                
                // Identificar estudios incompletos donde el usuario es owner pero NO están asignados
                // Estos estudios vienen de la API porque sf.user_id = userId (owner/emisor)
                const incompleteFromOwner = incompleteStudyIds
                    .filter(flag => {
                        // Verificar si este flag NO está en los estudios asignados
                        return !assignedStudies.some(study => 
                            flag.study_id === study.id ||
                            flag.orthanc_id === study.id || 
                            flag.orthanc_id === study.orthanc_study_id ||
                            flag.study_instance_uid === study.study_instance_uid ||
                            (flag.study_id && flag.study_id === study.orthanc_study_id)
                        );
                    })
                    .map(flag => {
                        // Crear un objeto estudio básico desde el flag
                        // Nota: Estos estudios pueden no tener todos los datos, pero tienen los flags
                        return {
                            id: flag.study_id || flag.orthanc_id || flag.study_instance_uid,
                            orthanc_study_id: flag.orthanc_id || flag.study_id,
                            study_instance_uid: flag.study_instance_uid,
                            informes_incompletos: {
                                informes_incompletos: true,
                                nota: flag.nota,
                                notas_por_informe: flag.notas_por_informe || []
                            },
                            is_incomplete: true,
                            prioridad: flag.prioridad || 'normal',
                            show_incomplete_badge: flag.show_incomplete_badge !== undefined ? flag.show_incomplete_badge : true,
                            show_urgent_badge: flag.show_urgent_badge !== undefined ? flag.show_urgent_badge : false,
                            // Datos básicos que pueden faltar (se completarán si es necesario)
                            patient_name: 'Paciente ' + (flag.study_id || flag.orthanc_id || '').substring(0, 8),
                            date: new Date().toISOString().split('T')[0].replace(/-/g, ''),
                            modality: 'CT',
                            study_description: 'Estudio marcado como incompleto',
                            source_type: 'owner' // Indicar que viene de owner, no de asignación
                        };
                    });
                
                // Combinar estudios asignados y estudios donde es owner
                incompleteStudies = [...incompleteFromAssigned, ...incompleteFromOwner];
                
                console.log(`📦 Estudios incompletos encontrados: ${incompleteStudies.length} (${incompleteFromAssigned.length} asignados, ${incompleteFromOwner.length} como owner)`);
            }
            
            // 3. Agregar información de informes_incompletos y marcarlos
            incompleteStudies.forEach(study => {
                const flag = incompleteStudyIds.find(f => 
                    f.study_id === study.id ||
                    f.orthanc_id === study.id || 
                    f.orthanc_id === study.orthanc_study_id ||
                    f.study_instance_uid === study.study_instance_uid ||
                    (f.study_id && f.study_id === study.orthanc_study_id)
                );
                
                if (flag) {
                    study.informes_incompletos = {
                        informes_incompletos: true,
                        nota: flag.nota,
                        notas_por_informe: flag.notas_por_informe || [] // IMPORTANTE: copiar TODAS las notas
                    };
                    study.is_incomplete = true;
                    // Usar los flags de la API si están disponibles
                    if (flag.show_incomplete_badge !== undefined) {
                        study.show_incomplete_badge = flag.show_incomplete_badge;
                    }
                    if (flag.show_urgent_badge !== undefined) {
                        study.show_urgent_badge = flag.show_urgent_badge;
                    }
                    console.log('🔴 Flag incompleto aplicado a estudio:', {
                        id: study.id,
                        orthanc_study_id: study.orthanc_study_id,
                        study_instance_uid: study.study_instance_uid,
                        informes_incompletos: study.informes_incompletos,
                        nota: flag.nota,
                        notas_por_informe: flag.notas_por_informe,
                        show_incomplete_badge: study.show_incomplete_badge,
                        show_urgent_badge: study.show_urgent_badge
                    });
                    // Preservar prioridad si existe (importante para mostrar badge de urgente)
                    if (flag.prioridad && this.isHighPriorityPrioridad(flag.prioridad)) {
                        study.prioridad = flag.prioridad;
                        study.is_urgent = true;
                    } else {
                        // No establecer 'normal' aquí, dejar que se determine por defecto
                        // para no sobrescribir una prioridad que pueda venir de otra fuente
                        if (!study.prioridad) {
                            study.prioridad = 'normal';
                        }
                    }
                } else {
                    // Si no se encuentra el flag, marcar como incompleto de todas formas
                    study.informes_incompletos = {
                        informes_incompletos: true,
                        nota: null,
                        notas_por_informe: [] // Sin notas si no se encuentra el flag
                    };
                    study.is_incomplete = true;
                    if (!study.prioridad) {
                        study.prioridad = 'normal';
                    }
                }
            });
            
            console.log(`✅ Estudios incompletos cargados: ${incompleteStudies.length}`);
            return incompleteStudies;
            
        } catch (error) {
            console.error('Error cargando estudios incompletos:', error);
            // En caso de error, retornar array vacío para no bloquear la carga normal
            return [];
        }
    }
    
    /**
     * Carga estudios urgentes/prometidos desde study_flags
     * Para PACS Query: Consulta Orthanc directamente por ID
     * Para usuarios sin PACS Query: Obtiene datos desde study_assignments
     */
    async loadUrgentStudies() {
        try {
            console.log('🚨 Cargando estudios urgentes/prometidos...');
            
            // 1. Obtener IDs de estudios urgentes/prometidos (ya filtrados por permisos en la API)
            const response = await fetch(`${this.apiBaseUrl}get_urgent_studies.php`);
            const result = await response.json();
            
            if (!result.success || !result.data.urgent_study_ids.length) {
                console.log('✅ No hay estudios urgentes/prometidos');
                return [];
            }
            
            const urgentStudyIds = result.data.urgent_study_ids;
            console.log(`📋 Encontrados ${urgentStudyIds.length} estudios urgentes/prometidos`);
            
            let urgentStudies = [];
            
            // 2. Obtener datos completos según permisos del usuario
            if (this.hasPacsQueryPermission) {
                // Usuario CON PACS Query: consultar Orthanc directamente
                const orthancIds = urgentStudyIds
                    .map(item => item.orthanc_id)
                    .filter(id => id);
                
                if (orthancIds.length === 0) {
                    console.warn('⚠️ No hay orthanc_ids válidos para consultar');
                    return [];
                }
                
                const idsParam = orthancIds.join(',');
                const studiesResponse = await fetch(`${this.apiBaseUrl}get_studies_by_ids.php?ids=${idsParam}`);
                const studiesResult = await studiesResponse.json();
                
                if (!studiesResult.success) {
                    throw new Error(studiesResult.message || 'Error obteniendo estudios desde Orthanc');
                }
                
                urgentStudies = studiesResult.data || [];
                
                // Agregar flags de la API a los estudios obtenidos del PACS
                urgentStudies.forEach(study => {
                    const flag = urgentStudyIds.find(f => 
                        f.study_id === study.id ||
                        f.orthanc_id === study.id || 
                        f.orthanc_id === study.orthanc_study_id ||
                        f.study_instance_uid === study.study_instance_uid ||
                        (f.study_id && f.study_id === study.orthanc_study_id)
                    );
                    
                    if (flag) {
                        // Usar los flags de la API para determinar visibilidad de badges
                        if (flag.show_incomplete_badge !== undefined) {
                            study.show_incomplete_badge = flag.show_incomplete_badge;
                        }
                        if (flag.show_urgent_badge !== undefined) {
                            study.show_urgent_badge = flag.show_urgent_badge;
                        }
                        // Agregar información de prioridad
                        study.prioridad = flag.prioridad;
                        study.is_urgent = true;
                    }
                });
            } else {
                // Usuario SIN PACS Query: obtener datos desde study_assignments
                console.log('📦 Usuario sin PACS Query: obteniendo estudios desde study_assignments');
                
                const assignedResponse = await fetch(`${this.apiBaseUrl}get_user_assigned_studies_fixed.php`);
                const assignedResult = await assignedResponse.json();
                
                if (!assignedResult.success) {
                    throw new Error(assignedResult.error || 'Error obteniendo estudios asignados');
                }
                
                const assignedStudies = assignedResult.data.studies || [];
                
                // Filtrar estudios que están en la lista de urgentes y están asignados/derivados
                const urgentFromAssigned = assignedStudies.filter(study => {
                    return urgentStudyIds.some(flag => 
                        flag.study_id === study.id ||
                        flag.orthanc_id === study.id || 
                        flag.orthanc_id === study.orthanc_study_id ||
                        flag.study_instance_uid === study.study_instance_uid ||
                        (flag.study_id && flag.study_id === study.orthanc_study_id)
                    );
                });
                
                // Identificar estudios urgentes donde el usuario es owner pero NO están asignados
                // Estos estudios vienen de la API porque sf.user_id = userId (owner/emisor)
                const urgentFromOwner = urgentStudyIds
                    .filter(flag => {
                        // Verificar si este flag NO está en los estudios asignados
                        return !assignedStudies.some(study => 
                            flag.study_id === study.id ||
                            flag.orthanc_id === study.id || 
                            flag.orthanc_id === study.orthanc_study_id ||
                            flag.study_instance_uid === study.study_instance_uid ||
                            (flag.study_id && flag.study_id === study.orthanc_study_id)
                        );
                    })
                    .map(flag => {
                        // Crear un objeto estudio básico desde el flag
                        // Nota: Estos estudios pueden no tener todos los datos, pero tienen los flags
                        return {
                            id: flag.study_id || flag.orthanc_id || flag.study_instance_uid,
                            orthanc_study_id: flag.orthanc_id || flag.study_id,
                            study_instance_uid: flag.study_instance_uid,
                            prioridad: flag.prioridad || 'urgente',
                            is_urgent: true,
                            show_incomplete_badge: flag.show_incomplete_badge !== undefined ? flag.show_incomplete_badge : false,
                            show_urgent_badge: flag.show_urgent_badge !== undefined ? flag.show_urgent_badge : true,
                            // Datos básicos que pueden faltar (se completarán si es necesario)
                            patient_name: 'Paciente ' + (flag.study_id || flag.orthanc_id || '').substring(0, 8),
                            date: new Date().toISOString().split('T')[0].replace(/-/g, ''),
                            modality: 'CT',
                            study_description: 'Estudio con prioridad',
                            source_type: 'owner' // Indicar que viene de owner, no de asignación
                        };
                    });
                
                // Combinar estudios asignados y estudios donde es owner
                urgentStudies = [...urgentFromAssigned, ...urgentFromOwner];
                
                console.log(`📦 Estudios urgentes encontrados: ${urgentStudies.length} (${urgentFromAssigned.length} asignados, ${urgentFromOwner.length} como owner)`);
            }
            
            // 3. Agregar información de prioridad y marcarlos como urgentes
            urgentStudies.forEach(study => {
                // Buscar la prioridad del flag correspondiente
                const flag = urgentStudyIds.find(f => 
                    f.study_id === study.id ||
                    f.orthanc_id === study.id || 
                    f.orthanc_id === study.orthanc_study_id ||
                    f.study_instance_uid === study.study_instance_uid ||
                    (f.study_id && f.study_id === study.orthanc_study_id)
                );
                
                if (flag) {
                    study.prioridad = flag.prioridad;
                    study.is_urgent = true;
                    // Usar los flags de la API si están disponibles
                    if (flag.show_incomplete_badge !== undefined) {
                        study.show_incomplete_badge = flag.show_incomplete_badge;
                    }
                    if (flag.show_urgent_badge !== undefined) {
                        study.show_urgent_badge = flag.show_urgent_badge;
                    }
                } else {
                    // Si no se encuentra el flag, usar la prioridad que ya viene del estudio
                    study.is_urgent = this.isHighPriorityPrioridad(study.prioridad);
                }
            });
            
            // Orden: urgente > promesa > pendiente
            const rankPrioridad = (p) => {
                if (p === 'urgente') return 3;
                if (p === 'promesa') return 2;
                if (p === 'pendiente') return 1;
                return 0;
            };
            urgentStudies.sort((a, b) => rankPrioridad(b.prioridad) - rankPrioridad(a.prioridad));
            
            console.log(`✅ Estudios urgentes/prometidos cargados: ${urgentStudies.length}`);
            return urgentStudies;
            
        } catch (error) {
            console.error('Error cargando estudios urgentes:', error);
            // En caso de error, retornar array vacío para no bloquear la carga normal
            return [];
        }
    }
    
    /**
     * Combina estudios prioritarios (urgentes, incompletos) y normales sin duplicados
     * Orden: 1) Urgentes, 2) Promesas, 3) Incompletos sin prioridad, 4) Normales
     */
    mergeStudies(urgentStudies, incompleteStudies, normalStudies) {
        // Crear mapa de estudios normales por ID para búsqueda rápida
        const normalMap = new Map();
        normalStudies.forEach(study => {
            normalMap.set(study.id, study);
            // También indexar por study_instance_uid si existe
            if (study.study_instance_uid) {
                normalMap.set(study.study_instance_uid, study);
            }
        });
        
        // Array final combinado
        const merged = [];
        const seenIds = new Set();
        
        // Primero: agregar estudios urgentes/prometidos (sin duplicados)
        urgentStudies.forEach(study => {
            const key = study.id;
            if (!seenIds.has(key)) {
                merged.push(study);
                seenIds.add(key);
                // También marcar study_instance_uid si existe
                if (study.study_instance_uid) {
                    seenIds.add(study.study_instance_uid);
                }
            }
        });
        
        // Segundo: agregar estudios incompletos SIN prioridad urgente/promesa
        // (los que tienen prioridad ya están en urgentStudies)
        incompleteStudies.forEach(study => {
            const key = study.id;
            const hasUrgentPriority = this.isHighPriorityPrioridad(study.prioridad);
            if (!seenIds.has(key) && !hasUrgentPriority) {
                merged.push(study);
                seenIds.add(key);
                // También marcar study_instance_uid si existe
                if (study.study_instance_uid) {
                    seenIds.add(study.study_instance_uid);
                }
            }
        });
        
        // Tercero: agregar estudios normales (excluyendo los que ya están en urgentes e incompletos)
        normalStudies.forEach(study => {
            const key = study.id;
            if (!seenIds.has(key)) {
                merged.push(study);
                seenIds.add(key);
                // También marcar study_instance_uid si existe
                if (study.study_instance_uid) {
                    seenIds.add(study.study_instance_uid);
                }
            }
        });
        
        return merged;
    }
    
    /**
     * Carga flags de informes incompletos para los estudios cargados
     */
    async loadIncompleteStudyFlags() {
        try {
            if (!this.studies || this.studies.length === 0) {
                return;
            }
            
            // Llamar a la API para obtener estudios incompletos
            const response = await fetch(`${this.apiBaseUrl}get_incomplete_studies.php`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                console.warn('No se pudieron cargar estudios incompletos');
                return;
            }
            
            const result = await response.json();
            
            if (result.success && result.data && result.data.incomplete_study_ids) {
                const incompleteStudyIds = result.data.incomplete_study_ids;
                
                // Crear un mapa de IDs incompletos para búsqueda rápida
                const incompleteMap = new Map();
                incompleteStudyIds.forEach(item => {
                    if (item.orthanc_id) incompleteMap.set(item.orthanc_id, item);
                    if (item.study_instance_uid) incompleteMap.set(item.study_instance_uid, item);
                });
                
                // Agregar información de incompletos a los estudios
                this.studies.forEach(study => {
                    const flag = incompleteMap.get(study.id) || 
                                incompleteMap.get(study.orthanc_study_id) ||
                                incompleteMap.get(study.study_instance_uid);
                    
                    if (flag) {
                        study.informes_incompletos = {
                            informes_incompletos: true,
                            nota: flag.nota,
                            notas_por_informe: flag.notas_por_informe || [], // IMPORTANTE: copiar TODAS las notas
                            prioridad: flag.prioridad || 'normal'
                        };
                    }
                });
                
                console.log(`✅ Flags de informes incompletos cargados: ${incompleteStudyIds.length} estudios marcados`);
            }
        } catch (error) {
            console.error('Error cargando flags de informes incompletos:', error);
        }
    }

    /**
     * Muestra los antecedentes de un estudio
     */
    async showAntecedents(studyId) {
        const study = this.studies.find(s => s.id === studyId);
        if (!study) {
            this.showError('Estudio no encontrado');
            return;
        }
        
        console.log('📋 Mostrando antecedentes para estudio:', studyId);
        console.log('📋 Antecedentes:', study.antecedents);
        
        try {
            // Cargar antecedentes detallados desde la API
            const response = await fetch(`${this.apiBaseUrl}study_antecedents.php?study_id=${studyId}`);
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Error cargando antecedentes');
            }
            
            const antecedentsData = result.data;
            console.log('📋 Antecedentes cargados:', antecedentsData);
            
            // Crear modal de antecedentes similar al de estudios-manager
            const modalHtml = `
                <div class="modal fade" id="antecedentsModal" tabindex="-1" aria-labelledby="antecedentsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="antecedentsModalLabel">
                                    <i class="fas fa-file-medical me-2"></i>Antecedentes Médicos
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Información del Estudio -->
                                <div class="row mb-4">
                                    <div class="col-12">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6 class="card-title">
                                                    <i class="fas fa-info-circle me-2"></i>Información del Estudio
                                                </h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <strong>Paciente:</strong> ${study.patient_name}<br>
                                                        <strong>ID Paciente:</strong> ${study.patient_id}<br>
                                                        <strong>Modalidad:</strong> ${study.modality}
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Fecha:</strong> ${this.formatDate(study.date)}<br>
                                                        <strong>Descripción:</strong> ${study.study_description}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pestañas de Contenido -->
                                <ul class="nav nav-tabs" id="antecedentsTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button" role="tab">
                                            <i class="fas fa-sticky-note me-2"></i>Notas y Texto
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="images-tab" data-bs-toggle="tab" data-bs-target="#images" type="button" role="tab">
                                            <i class="fas fa-images me-2"></i>Imágenes
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="files-tab" data-bs-toggle="tab" data-bs-target="#files" type="button" role="tab">
                                            <i class="fas fa-file-upload me-2"></i>Archivos
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content" id="antecedentsTabContent">
                                    <!-- Pestaña de Notas -->
                                    <div class="tab-pane fade show active" id="notes" role="tabpanel">
                                        <div class="mt-3">
                                            <label class="form-label">
                                                <i class="fas fa-edit me-2"></i>Notas y Antecedentes Médicos
                                            </label>
                                            <div class="border rounded p-3" style="min-height: 200px;">
                                                ${antecedentsData.notes ? 
                                                    `<p>${antecedentsData.notes}</p>` : 
                                                    '<p class="text-muted">No hay notas de antecedentes disponibles.</p>'
                                                }
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pestaña de Imágenes -->
                                    <div class="tab-pane fade" id="images" role="tabpanel">
                                        <div class="mt-3">
                                            <label class="form-label">
                                                <i class="fas fa-images me-2"></i>Imágenes Adjuntas
                                            </label>
                                            <div id="uploadedImages" class="border rounded p-3" style="min-height: 200px;">
                                                ${antecedentsData.images && antecedentsData.images.length > 0 ? 
                                                    antecedentsData.images.map(img => `
                                                        <div class="d-inline-block me-2 mb-2">
                                                            <img src="${img.file_path}" class="img-thumbnail" style="width: 100px; height: 100px; object-fit: cover;" 
                                                                 onclick="window.open('${img.file_path}', '_blank')" title="Hacer clic para ver en tamaño completo">
                                                        </div>
                                                    `).join('') :
                                                    '<div class="text-muted text-center"><i class="fas fa-image fa-2x mb-2"></i><br>No hay imágenes adjuntas</div>'
                                                }
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pestaña de Archivos -->
                                    <div class="tab-pane fade" id="files" role="tabpanel">
                                        <div class="mt-3">
                                            <label class="form-label">
                                                <i class="fas fa-folder me-2"></i>Archivos Adjuntos
                                            </label>
                                            <div id="uploadedFiles" class="border rounded p-3" style="min-height: 200px;">
                                                ${antecedentsData.files && antecedentsData.files.length > 0 ? 
                                                    antecedentsData.files.map(file => `
                                                        <div class="d-flex align-items-center mb-2 p-2 border rounded">
                                                            <i class="fas fa-file me-2"></i>
                                                            <div class="flex-grow-1">
                                                                <strong>${file.original_name}</strong><br>
                                                                <small class="text-muted">${this.formatFileSize(file.file_size)}</small>
                                                            </div>
                                                            <a href="${file.file_path}" target="_blank" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                        </div>
                                                    `).join('') :
                                                    '<div class="text-muted text-center"><i class="fas fa-file fa-2x mb-2"></i><br>No hay archivos adjuntos</div>'
                                                }
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('antecedentsModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Agregar modal al DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('antecedentsModal'));
            modal.show();
            
            // Limpiar modal cuando se cierre
            document.getElementById('antecedentsModal').addEventListener('hidden.bs.modal', function() {
                this.remove();
            });
            
        } catch (error) {
            console.error('Error cargando antecedentes:', error);
            this.showError('Error cargando antecedentes: ' + error.message);
        }
    }
    
    /**
     * Formatea el tamaño del archivo
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // ... resto de métodos iguales que en DashboardOrthanc ...
    
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
     * Configura el listener para cuando se elimina un informe.
     *
     * Existen dos rutas:
     *  A) Mismo window (poco frecuente con la arquitectura iframe): listener original
     *     que hace un loadStudies() completo. Se mantiene intacto para no romper flujos
     *     que ya dependen de él.
     *  B) Cross-iframe (caso real desde components/informes-manager.html que vive en
     *     un iframe hermano del dashboard): postMessage del padre + storage event.
     *     Aplicamos un update INCREMENTAL (decremento) para que la burbuja roja y el
     *     filtro "Estado informe" se actualicen al instante, sin viaje a la API.
     */
    setupInformeEliminadoListener() {
        // ----- Ruta A: mismo window (comportamiento histórico, NO se modifica) -----
        window.addEventListener('informeEliminado', async (event) => {
            console.log('🔄 Informe eliminado detectado, actualizando dashboard...', event.detail);
            
            const { study_id, study_instance_uid, orthanc_id } = event.detail;
            
            // Recargar estudios para actualizar el estado
            try {
                await this.loadStudies();
                console.log('✅ Dashboard actualizado después de eliminar informe');
            } catch (error) {
                console.error('❌ Error actualizando dashboard después de eliminar informe:', error);
            }
        });

        // ----- Ruta B: cross-iframe (postMessage / storage) con update incremental -----
        const matchesStudy = (s, d) => {
            if (!s || !d) return false;
            return (
                (d.study_id && (s.id === d.study_id || s.orthanc_study_id === d.study_id)) ||
                (d.orthanc_id && (s.id === d.orthanc_id || s.orthanc_study_id === d.orthanc_id)) ||
                (d.study_instance_uid && s.study_instance_uid === d.study_instance_uid)
            );
        };

        const applyDelete = (detail) => {
            if (!detail || typeof detail !== 'object') return;
            let touched = false;
            // Sólo iteramos this.studies: this.filteredStudies comparte referencias
            // con this.studies (es un filter()), por lo que la mutación se refleja en
            // ambos. Iterar dos veces produciría el bug histórico de doble decremento.
            if (Array.isArray(this.studies)) {
                this.studies.filter(s => matchesStudy(s, detail)).forEach(s => {
                    s.report_info = s.report_info || {
                        total_informes: 0,
                        has_report: false,
                        published_to_pacs: false,
                        ultimo_estado: null,
                        ultima_fecha_informe: null,
                        estados_disponibles: []
                    };
                    // Si el origen mandó conteo exacto, lo usamos. Si no, decrementamos.
                    if (Number.isFinite(Number(detail.total_informes))) {
                        s.report_info.total_informes = Math.max(0, Number(detail.total_informes));
                    } else {
                        s.report_info.total_informes = Math.max(0, (Number(s.report_info.total_informes) || 0) - 1);
                    }
                    s.report_info.has_report = (Number(s.report_info.total_informes) || 0) > 0;
                    if (!s.report_info.has_report) {
                        // Sin informes ⇒ tampoco puede estar publicado en PACS
                        s.report_info.published_to_pacs = false;
                        s.report_info.has_report_in_pacs = false;
                    }
                    s.has_report = s.report_info.has_report;
                    s.report_status = s.report_info.published_to_pacs
                        ? 'published_pacs'
                        : (s.report_info.has_report ? 'informed_pending_pacs' : 'none');
                    touched = true;
                });
            }
            if (touched) {
                console.log('🔄 informeEliminado (cross-iframe) aplicado al estudio en grilla', detail);
                try { this.applyLocalFilters(); } catch (e) {
                    console.warn('⚠️ Error aplicando filtros tras informeEliminado:', e);
                }
            } else {
                console.log('ℹ️ informeEliminado recibido pero el estudio no está en la grilla actual', detail);
            }
        };

        // postMessage desde otros iframes / ventana padre
        window.addEventListener('message', (e) => {
            try {
                if (e && e.data && e.data.type === 'informeEliminado') {
                    applyDelete(e.data.detail);
                }
            } catch (err) { console.warn(err); }
        });

        // storage event para iframes hermanos del mismo origen
        window.addEventListener('storage', (e) => {
            try {
                if (e && e.key === 'informe_eliminado_event' && e.newValue) {
                    applyDelete(JSON.parse(e.newValue));
                }
            } catch (err) { console.warn(err); }
        });
    }

    /**
     * Configura el listener para cuando se finaliza/crea un informe.
     * Aplica un update incremental sobre el estudio correspondiente para que la
     * burbuja roja (total_informes), el filtro "Estado informe" y el caché
     * persistente queden actualizados sin necesidad de re-llamar a la API.
     *
     * Recibe el detalle por tres canales (todos opcionales, redundantes a propósito):
     *  1) CustomEvent('informeFinalizado') — mismo window
     *  2) postMessage({ type: 'informeFinalizado', detail }) — desde iframes/padre
     *  3) storage event sobre la clave 'informe_finalizado_event' — multi-iframe/multi-tab
     */
    setupInformeFinalizadoListener() {
        const matchesStudy = (s, d) => {
            if (!s || !d) return false;
            return (
                (d.study_id && (s.id === d.study_id || s.orthanc_study_id === d.study_id)) ||
                (d.orthanc_id && (s.id === d.orthanc_id || s.orthanc_study_id === d.orthanc_id)) ||
                (d.study_instance_uid && s.study_instance_uid === d.study_instance_uid)
            );
        };

        const apply = (detail) => {
            if (!detail || typeof detail !== 'object') return;

            // Las ediciones de un informe existente no modifican total_informes ni el estado
            // de informe (sólo cambian contenido). Salir temprano evita re-renders innecesarios
            // y previene desbordes de contador desde autosaves.
            const action = (detail.action || '').toLowerCase();
            if (action === 'update' &&
                !Number.isFinite(Number(detail.total_informes)) &&
                typeof detail.published_to_pacs !== 'boolean') {
                return;
            }

            let touched = false;
            // IMPORTANTE: iterar solo sobre this.studies. this.filteredStudies contiene las
            // mismas REFERENCIAS de objeto (es un filter() de this.studies), por lo que mutar
            // los objetos aquí actualiza ambos arrays. Iterar los dos arrays produciría doble
            // incremento del contador (ver bug histórico burbuja=2 al crear un único informe).
            if (Array.isArray(this.studies)) {
                this.studies.filter(s => matchesStudy(s, detail)).forEach(s => {
                    s.report_info = s.report_info || {
                        total_informes: 0,
                        has_report: false,
                        published_to_pacs: false,
                        ultimo_estado: null,
                        ultima_fecha_informe: null,
                        estados_disponibles: []
                    };

                    // Si el origen mandó el conteo exacto, lo usamos. Si no, +1 sólo cuando es create.
                    if (Number.isFinite(Number(detail.total_informes))) {
                        s.report_info.total_informes = Number(detail.total_informes);
                    } else if (action !== 'update') {
                        s.report_info.total_informes = (Number(s.report_info.total_informes) || 0) + 1;
                    }

                    s.report_info.has_report = (Number(s.report_info.total_informes) || 0) > 0;
                    if (typeof detail.published_to_pacs === 'boolean') {
                        s.report_info.published_to_pacs = detail.published_to_pacs;
                        s.report_info.has_report_in_pacs = detail.published_to_pacs;
                    }
                    s.has_report = s.report_info.has_report;

                    // Mantener report_status sincronizado para el filtro "Estado informe"
                    s.report_status = s.report_info.published_to_pacs
                        ? 'published_pacs'
                        : (s.report_info.has_report ? 'informed_pending_pacs' : 'none');

                    touched = true;
                });
            }

            if (touched) {
                console.log('🔄 Informe finalizado/creado detectado, actualizando estudio en grilla...', detail);
                // applyLocalFilters re-aplica el filtro "Estado informe" y vuelve a renderizar.
                // Internamente también llama a savePersistentState(), por lo que el caché
                // localStorage queda sincronizado para el próximo init().
                try { this.applyLocalFilters(); } catch (e) {
                    console.warn('⚠️ Error aplicando filtros tras informeFinalizado:', e);
                }
            } else {
                // El estudio no está en la grilla actual (puede estar fuera del periodo filtrado).
                // No hacemos nada: cuando el usuario cambie filtros, applyLocalFilters lo evaluará.
                console.log('ℹ️ informeFinalizado recibido pero el estudio no está en la grilla actual', detail);
            }
        };

        // Canal 1: mismo window (poco común con la arquitectura de iframes, pero gratis)
        window.addEventListener('informeFinalizado', (e) => {
            try { apply(e && e.detail); } catch (err) { console.warn(err); }
        });

        // Canal 2: postMessage desde otros iframes / ventana padre
        window.addEventListener('message', (e) => {
            try {
                if (e && e.data && e.data.type === 'informeFinalizado') {
                    apply(e.data.detail);
                }
            } catch (err) { console.warn(err); }
        });

        // Canal 3: storage event para iframes/pestañas hermanas del mismo origen
        window.addEventListener('storage', (e) => {
            try {
                if (e && e.key === 'informe_finalizado_event' && e.newValue) {
                    apply(JSON.parse(e.newValue));
                }
            } catch (err) { console.warn(err); }
        });
    }

    /**
     * Configura el listener para cambios en estados de estudios (prioridad, incompleto)
     */
    setupStudyStatusChangeListener() {
        // Escuchar eventos personalizados para cambios de estado
        window.addEventListener('studyPriorityChanged', async (event) => {
            console.log('🔄 Prioridad de estudio cambiada, actualizando contadores...', event.detail);
            // Actualizar estudios prioritarios inmediatamente
            await this.refreshPriorityStudies();
        });
        
        window.addEventListener('studyIncompleteChanged', async (event) => {
            console.log('🔄 Estado de incompleto cambiado, actualizando contadores...', event.detail);
            // Actualizar estudios prioritarios inmediatamente
            await this.refreshPriorityStudies();
        });
        
        // También escuchar cambios en el estado global
        window.addEventListener('globalStateChanged', async (event) => {
            if (event.detail && (event.detail.type === 'priority' || event.detail.type === 'incomplete')) {
                console.log('🔄 Cambio de estado global detectado, actualizando contadores...', event.detail);
                await this.refreshPriorityStudies();
            }
        });
    }
    
    /**
     * Configura el listener para cambios en el estado del workspace
     * Actualiza los indicadores visuales cuando se abre/cierra un estudio en workspace
     */
    setupWorkspaceStateListener() {
        // Guardar referencia al último estudio conocido en workspace
        let lastWorkspaceStudyId = null;
        
        // Escuchar cambios en sessionStorage (cuando workspace guarda/cambia estudio)
        const originalSetItem = Storage.prototype.setItem;
        const self = this;
        
        Storage.prototype.setItem = function(key, value) {
            originalSetItem.apply(this, arguments);
            
            // Si se actualiza el estudio del workspace, actualizar indicadores
            if (key === 'workspace_study_data') {
                try {
                    const newStudyData = JSON.parse(value);
                    const newStudyId = newStudyData.studyId || newStudyData.studyInstanceUID || newStudyData.orthancStudyId;
                    
                    // Solo actualizar si cambió el estudio
                    if (newStudyId !== lastWorkspaceStudyId) {
                        console.log('🔄 Cambio detectado en workspace_study_data, actualizando indicadores...', {
                            anterior: lastWorkspaceStudyId,
                            nuevo: newStudyId
                        });
                        lastWorkspaceStudyId = newStudyId;
                        
                        // Forzar actualización de todos los indicadores y re-renderizar
                        setTimeout(() => {
                            self.updateWorkspaceIndicators();
                            // Re-renderizar para que el estudio aparezca primero
                            if (self.filteredStudies && self.filteredStudies.length > 0) {
                                self.renderStudies();
                            }
                        }, 100);
                    } else if (newStudyId === lastWorkspaceStudyId) {
                        // Mismo estudio, pero asegurar que los indicadores estén actualizados
                        setTimeout(() => {
                            self.updateWorkspaceIndicators();
                        }, 50);
                    }
                } catch (e) {
                    console.error('Error procesando cambio en workspace_study_data:', e);
                    // Si hay error, actualizar de todas formas
                    setTimeout(() => {
                        self.updateWorkspaceIndicators();
                        if (self.filteredStudies && self.filteredStudies.length > 0) {
                            self.renderStudies();
                        }
                    }, 100);
                }
            }
        };
        
        // Escuchar mensajes postMessage del workspace (si está en iframe)
        window.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'workspace-study-changed') {
                console.log('🔄 Mensaje recibido del workspace: estudio cambiado', event.data);
                const newStudyId = event.data.studyData?.studyId || event.data.studyData?.studyInstanceUID || event.data.studyData?.orthancStudyId;
                if (newStudyId !== lastWorkspaceStudyId) {
                    lastWorkspaceStudyId = newStudyId;
                    setTimeout(() => {
                        this.updateWorkspaceIndicators();
                        // Re-renderizar para que el estudio aparezca primero
                        if (this.filteredStudies && this.filteredStudies.length > 0) {
                            this.renderStudies();
                        }
                    }, 100);
                }
            }
        });
        
        // Escuchar eventos personalizados de cambio de estudio en workspace
        window.addEventListener('workspaceStudyChanged', (event) => {
            console.log('🔄 Evento workspaceStudyChanged recibido:', event.detail);
            const newStudyId = event.detail?.studyId || event.detail?.studyInstanceUID || event.detail?.orthancStudyId;
            if (newStudyId !== lastWorkspaceStudyId) {
                lastWorkspaceStudyId = newStudyId;
                setTimeout(() => {
                    this.updateWorkspaceIndicators();
                    // Re-renderizar para que el estudio aparezca primero
                    if (this.filteredStudies && this.filteredStudies.length > 0) {
                        this.renderStudies();
                    }
                }, 100);
            }
        });
        
        // Escuchar cambios de localStorage desde el popup multimonitor (otra ventana).
        // El evento 'storage' sólo se dispara cuando OTRA ventana modifica localStorage.
        window.addEventListener('storage', (event) => {
            if (event.key === 'workspace_study_data_mm') {
                try {
                    if (event.newValue) {
                        const studyData = JSON.parse(event.newValue);
                        const newStudyId = studyData.studyId || studyData.studyInstanceUID || studyData.orthancStudyId;
                        if (newStudyId !== lastWorkspaceStudyId) {
                            console.log('🔄 [MM] Estudio cambiado en popup workspace:', newStudyId);
                            lastWorkspaceStudyId = newStudyId;
                            setTimeout(() => {
                                this.updateWorkspaceIndicators();
                                if (this.filteredStudies && this.filteredStudies.length > 0) {
                                    this.renderStudies();
                                }
                            }, 100);
                        }
                    } else {
                        // Popup cerrado: limpiar badge si no hay estudio en workspace normal
                        const localStudy = sessionStorage.getItem('workspace_study_data');
                        if (!localStudy && lastWorkspaceStudyId !== null) {
                            lastWorkspaceStudyId = null;
                            setTimeout(() => {
                                this.updateWorkspaceIndicators();
                                if (this.filteredStudies && this.filteredStudies.length > 0) {
                                    this.renderStudies();
                                }
                            }, 100);
                        }
                    }
                } catch (e) {
                    console.error('Error procesando storage event workspace_study_data_mm:', e);
                }
            }
        });

        // Verificar periódicamente cambios en sessionStorage o localStorage MM (fallback)
        setInterval(() => {
            try {
                const workspaceStudyData = sessionStorage.getItem('workspace_study_data')
                                        || localStorage.getItem('workspace_study_data_mm');
                if (workspaceStudyData) {
                    const studyData = JSON.parse(workspaceStudyData);
                    const currentStudyId = studyData.studyId || studyData.studyInstanceUID || studyData.orthancStudyId;
                    if (currentStudyId !== lastWorkspaceStudyId) {
                        lastWorkspaceStudyId = currentStudyId;
                        this.updateWorkspaceIndicators();
                    }
                } else if (lastWorkspaceStudyId !== null) {
                    // Si se eliminó el estudio del workspace, limpiar indicadores
                    lastWorkspaceStudyId = null;
                    this.updateWorkspaceIndicators();
                }
            } catch (e) {
                // Ignorar errores en verificación periódica
            }
        }, 2000); // Cada 2 segundos
        
        // Actualizar indicadores al cargar la página
        setTimeout(() => {
            try {
                const workspaceStudyData = sessionStorage.getItem('workspace_study_data')
                                        || localStorage.getItem('workspace_study_data_mm');
                if (workspaceStudyData) {
                    const studyData = JSON.parse(workspaceStudyData);
                    lastWorkspaceStudyId = studyData.studyId || studyData.studyInstanceUID || studyData.orthancStudyId;
                }
            } catch (e) {
                // Ignorar errores
            }
            this.updateWorkspaceIndicators();
        }, 500);
    }
    
    /**
     * Actualiza los indicadores visuales de estudios abiertos en workspace
     */
    updateWorkspaceIndicators() {
        const rows = document.querySelectorAll('.studies-table tbody tr[data-study-id]');
        let hasChanges = false;
        
        // Obtener el estudio actualmente abierto en workspace (si existe)
        let currentWorkspaceStudy = null;
        try {
            const workspaceStudyData = sessionStorage.getItem('workspace_study_data');
            if (workspaceStudyData) {
                currentWorkspaceStudy = JSON.parse(workspaceStudyData);
            }
        } catch (e) {
            console.error('Error leyendo workspace_study_data:', e);
        }
        
        rows.forEach(row => {
            const studyId = row.getAttribute('data-study-id');
            const study = this.studies.find(s => s.id === studyId);
            
            if (!study) return;
            
            const isOpenInWorkspace = this.isStudyOpenInWorkspace(study);
            const currentlyMarked = row.classList.contains('study-open-in-workspace');
            
            // Si hay un estudio en workspace y este NO es el actual, remover indicador
            if (currentWorkspaceStudy && !isOpenInWorkspace && currentlyMarked) {
                // Remover indicador del estudio anterior
                row.classList.remove('study-open-in-workspace');
                row.removeAttribute('data-workspace-open');
                
                // Remover badge y su contenedor
                const indicatorContainer = row.querySelector('.workspace-indicator-container');
                if (indicatorContainer) {
                    indicatorContainer.remove();
                    hasChanges = true;
                }
            } else if (isOpenInWorkspace && !currentlyMarked) {
                // Agregar indicador solo si es el estudio actual en workspace
                row.classList.add('study-open-in-workspace');
                row.setAttribute('data-workspace-open', 'true');
                
                // Agregar badge en columna Estado (segunda línea) si no existe
                const estadoCell = row.querySelector('td[data-label="Estado"]');
                if (estadoCell && !estadoCell.querySelector('.workspace-indicator')) {
                    // Verificar si ya hay un contenedor div, si no crearlo
                    let indicatorContainer = estadoCell.querySelector('.workspace-indicator-container');
                    if (!indicatorContainer) {
                        indicatorContainer = document.createElement('div');
                        indicatorContainer.className = 'workspace-indicator-container';
                        indicatorContainer.style.cssText = 'margin-top: 4px;';
                        estadoCell.appendChild(indicatorContainer);
                    }
                    
                    const indicator = document.createElement('span');
                    indicator.className = 'badge bg-info text-white workspace-indicator';
                    indicator.title = 'Este estudio está abierto en WorkSpace';
                    indicator.style.cssText = 'font-size: 0.7rem; padding: 0.2rem 0.4rem;';
                    indicator.innerHTML = '<i class="fas fa-th-large me-1"></i>En WorkSpace';
                    indicatorContainer.appendChild(indicator);
                    hasChanges = true;
                }
            } else if (!isOpenInWorkspace && currentlyMarked) {
                // Remover indicador si ya no está en workspace
                row.classList.remove('study-open-in-workspace');
                row.removeAttribute('data-workspace-open');
                
                // Remover badge y su contenedor
                const indicatorContainer = row.querySelector('.workspace-indicator-container');
                if (indicatorContainer) {
                    indicatorContainer.remove();
                    hasChanges = true;
                }
            }
        });
        
        if (hasChanges) {
            console.log('✅ Indicadores de workspace actualizados');
        }
    }
    
    /**
     * Configura el ordenamiento de columnas
     */
    setupColumnSorting() {
        const sortableHeaders = document.querySelectorAll('.studies-table thead th.sortable');
        sortableHeaders.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', async () => {
                const column = header.getAttribute('data-column');
                await this.sortByColumn(column);
            });
        });
    }
    
    /**
     * Ordena por una columna específica
     */
    async sortByColumn(column) {
        // Si se hace clic en la misma columna, invertir dirección
        if (this.sortConfig.column === column) {
            this.sortConfig.direction = this.sortConfig.direction === 'asc' ? 'desc' : 'asc';
        } else {
            // Si es una columna diferente, verificar si hay una preferencia guardada para esta columna
            const saved = localStorage.getItem(this.sortPreferenceKey);
            if (saved) {
                try {
                    const preference = JSON.parse(saved);
                    // Si la preferencia guardada es para esta columna, usar esa dirección
                    if (preference.column === column && preference.direction) {
                        this.sortConfig.column = column;
                        this.sortConfig.direction = preference.direction;
                        console.log(`📋 Usando preferencia guardada para ${column}: ${preference.direction}`);
                    } else {
                        // Si no hay preferencia para esta columna, usar 'asc' por defecto
                        this.sortConfig.column = column;
                        this.sortConfig.direction = 'asc';
                    }
                } catch (error) {
                    // Si hay error al parsear, usar 'asc' por defecto
                    this.sortConfig.column = column;
                    this.sortConfig.direction = 'asc';
                }
            } else {
                // Si no hay preferencia guardada, usar 'asc' por defecto
                this.sortConfig.column = column;
                this.sortConfig.direction = 'asc';
            }
        }
        
        // Guardar preferencia de ordenamiento
        this.saveSortPreference();
        
        // Actualizar iconos en los headers
        this.updateSortIcons();
        
        // Re-renderizar estudios
        this.renderStudies();
    }
    
    /**
     * Guarda la preferencia de ordenamiento en la base de datos y localStorage (respaldo)
     */
    async saveSortPreference() {
        try {
            const preference = {
                column: this.sortConfig.column,
                direction: this.sortConfig.direction
            };
            
            // Guardar en localStorage como respaldo
            const jsonPreference = JSON.stringify(preference);
            localStorage.setItem(this.sortPreferenceKey, jsonPreference);
            console.log(`💾 Preferencia de ordenamiento guardada en localStorage: ${preference.column} (${preference.direction})`);
            
            // Guardar en la base de datos (persistente entre sesiones)
            try {
                const token = this.getSessionToken();
                if (token) {
                    const response = await fetch(this.apiBaseUrl + 'users/save-sort-preference.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': `Bearer ${token}`
                        },
                        credentials: 'include',
                        body: JSON.stringify(preference)
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        console.log(`✅ Preferencia de ordenamiento guardada en BD: ${preference.column} (${preference.direction})`);
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
     * Carga la preferencia de ordenamiento desde la base de datos (o localStorage como respaldo)
     */
    async loadSortPreference() {
        try {
            // Primero intentar cargar desde la base de datos
            const token = this.getSessionToken();
            if (token) {
                try {
                    const response = await fetch(this.apiBaseUrl + 'users/get-sort-preference.php', {
                        method: 'GET',
                        headers: {
                            'Authorization': `Bearer ${token}`
                        },
                        credentials: 'include'
                    });
                    
                    const result = await response.json();
                    if (result.success && result.data) {
                        const preference = result.data;
                        if (preference.column !== undefined && preference.direction) {
                            this.sortConfig.column = preference.column;
                            this.sortConfig.direction = preference.direction;
                            console.log(`✅ Preferencia de ordenamiento cargada desde BD: ${preference.column} (${preference.direction})`);
                            
                            // Sincronizar localStorage con la BD
                            localStorage.setItem(this.sortPreferenceKey, JSON.stringify(preference));
                            return true;
                        }
                    }
                } catch (dbError) {
                    console.warn('⚠️ Error cargando desde BD, intentando localStorage:', dbError);
                }
            }
            
            // Si no se pudo cargar desde BD, intentar desde localStorage (respaldo)
            const saved = localStorage.getItem(this.sortPreferenceKey);
            if (saved) {
                const preference = JSON.parse(saved);
                console.log('📋 Preferencia encontrada en localStorage (respaldo):', preference);
                
                // Validar que la preferencia tenga los campos necesarios
                if (preference && typeof preference === 'object') {
                    // Si hay columna y dirección, aplicarlas
                    if (preference.column !== undefined && preference.column !== null && preference.direction) {
                        this.sortConfig.column = preference.column;
                        this.sortConfig.direction = preference.direction;
                        console.log(`✅ Preferencia de ordenamiento aplicada desde localStorage: ${preference.column} (${preference.direction})`);
                        return true;
                    } else if (preference.column === null && preference.direction) {
                        // Permitir column null (orden por prioridad) pero con dirección guardada
                        this.sortConfig.column = null;
                        this.sortConfig.direction = preference.direction;
                        console.log(`✅ Preferencia de ordenamiento aplicada desde localStorage: orden por prioridad (${preference.direction})`);
                        return true;
                    } else {
                        console.warn('⚠️ Preferencia inválida, ignorando:', preference);
                    }
                }
            } else {
                console.log('📋 No hay preferencia de ordenamiento guardada, usando valores por defecto');
            }
        } catch (error) {
            console.error('❌ Error cargando preferencia de ordenamiento:', error);
        }
        return false; // Indica que no se pudo cargar
    }

    /**
     * Guarda la preferencia "WS on Top" en BD y en localStorage como respaldo.
     * Sigue el mismo patrón que saveSortPreference (con fallback graceful).
     */
    async savePinWorkspaceOnTopPreference() {
        const enabled = !!this.pinWorkspaceOnTop;
        try {
            try {
                localStorage.setItem(this.pinWorkspaceOnTopKey, JSON.stringify({ enabled }));
                console.log(`💾 Preferencia WS on Top guardada en localStorage: ${enabled}`);
            } catch (lsErr) {
                console.warn('⚠️ No se pudo escribir WS on Top en localStorage:', lsErr);
            }

            try {
                const token = this.getSessionToken();
                if (!token) {
                    console.warn('⚠️ No hay token de sesión, WS on Top solo se guardó en localStorage');
                    return;
                }
                const response = await fetch(this.apiBaseUrl + 'users/save-pin-workspace-preference.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    credentials: 'include',
                    body: JSON.stringify({ enabled })
                });
                const result = await response.json();
                if (result && result.success) {
                    console.log(`✅ Preferencia WS on Top guardada en BD: ${enabled}`);
                } else {
                    console.warn('⚠️ No se pudo guardar WS on Top en BD (queda en localStorage):', result && result.message);
                }
            } catch (dbErr) {
                console.warn('⚠️ Error guardando WS on Top en BD (queda en localStorage):', dbErr);
            }
        } catch (error) {
            console.error('❌ Error guardando preferencia WS on Top:', error);
        }
    }

    /**
     * Carga la preferencia "WS on Top" desde BD; si no hay, usa localStorage como
     * respaldo. Si nada está guardado, mantiene el default true (comportamiento histórico).
     * Sincroniza el switch del DOM tras cargar (los listeners se montan antes que esta carga).
     */
    async loadPinWorkspaceOnTopPreference() {
        const syncSwitchToDOM = () => {
            try {
                const el = document.getElementById('pinWorkspaceOnTop');
                if (el) el.checked = !!this.pinWorkspaceOnTop;
            } catch (_) { /* noop */ }
        };

        try {
            const token = this.getSessionToken();
            if (token) {
                try {
                    const response = await fetch(this.apiBaseUrl + 'users/get-pin-workspace-preference.php', {
                        method: 'GET',
                        headers: { 'Authorization': `Bearer ${token}` },
                        credentials: 'include'
                    });
                    const result = await response.json();
                    if (result && result.success && result.data && typeof result.data.enabled === 'boolean') {
                        this.pinWorkspaceOnTop = result.data.enabled;
                        try {
                            localStorage.setItem(this.pinWorkspaceOnTopKey, JSON.stringify({ enabled: this.pinWorkspaceOnTop }));
                        } catch (_) { /* noop */ }
                        console.log(`✅ Preferencia WS on Top cargada desde BD: ${this.pinWorkspaceOnTop}`);
                        syncSwitchToDOM();
                        return true;
                    }
                } catch (dbErr) {
                    console.warn('⚠️ Error cargando WS on Top desde BD, intentando localStorage:', dbErr);
                }
            }

            const savedRaw = localStorage.getItem(this.pinWorkspaceOnTopKey);
            if (savedRaw) {
                try {
                    const saved = JSON.parse(savedRaw);
                    if (saved && typeof saved.enabled === 'boolean') {
                        this.pinWorkspaceOnTop = saved.enabled;
                        console.log(`📋 Preferencia WS on Top aplicada desde localStorage: ${this.pinWorkspaceOnTop}`);
                        syncSwitchToDOM();
                        return true;
                    }
                } catch (parseErr) {
                    console.warn('⚠️ Preferencia WS on Top en localStorage inválida, ignorando:', parseErr);
                }
            } else {
                console.log('📋 No hay preferencia WS on Top guardada, usando default (true)');
            }
            // Default sin preferencia guardada: dejamos pinWorkspaceOnTop=true tal cual,
            // pero igualmente sincronizamos el switch por si el DOM no estaba listo cuando
            // setupEventListeners corrió (por timing) o si fue alterado externamente.
            syncSwitchToDOM();
        } catch (error) {
            console.error('❌ Error cargando preferencia WS on Top:', error);
        }
        return false;
    }

    /**
     * Actualiza los iconos de ordenamiento en los headers
     */
    updateSortIcons() {
        const sortableHeaders = document.querySelectorAll('.studies-table thead th.sortable');
        sortableHeaders.forEach(header => {
            const icon = header.querySelector('.sort-icon');
            const column = header.getAttribute('data-column');
            
            if (icon) {
                if (this.sortConfig.column === column) {
                    icon.className = this.sortConfig.direction === 'asc' 
                        ? 'fas fa-sort-up sort-icon' 
                        : 'fas fa-sort-down sort-icon';
                    icon.style.opacity = '1';
                } else {
                    icon.className = 'fas fa-sort sort-icon';
                    icon.style.opacity = '0.3';
                }
            }
        });
    }
    
    /**
     * Ordena estudios. Si pinWorkspaceOnTop está activo (default), promueve al estudio
     * abierto en WorkSpace al primer lugar. Si está desactivado, ese estudio entra al
     * pipeline normal (badges + columna) conservando su orden natural.
     */
    sortStudies(studies) {
        // Separar el estudio abierto en workspace SOLO si la preferencia "WS on Top" está activa.
        // Cuando está desactivada, lo dejamos pasar como un estudio más al pipeline normal,
        // de modo que el ordenamiento por columna o por prioridad/badges decida su posición.
        const workspaceStudy = [];
        const otherStudies = [];

        if (this.pinWorkspaceOnTop) {
            studies.forEach(study => {
                if (this.isStudyOpenInWorkspace(study)) {
                    workspaceStudy.push(study);
                } else {
                    otherStudies.push(study);
                }
            });
        } else {
            // Sin pin: todos los estudios pasan por el pipeline normal
            otherStudies.push(...studies);
        }
        
        // Separar estudios con badges (prioridad o incompletos) de los normales (de los que NO están en workspace)
        const studiesWithBadges = [];
        const normalStudies = [];
        
        otherStudies.forEach(study => {
            const hasPriority = study.prioridad && this.isHighPriorityPrioridad(study.prioridad);
            const isIncomplete = study.is_incomplete || (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
            
            if (hasPriority || isIncomplete) {
                studiesWithBadges.push(study);
            } else {
                normalStudies.push(study);
            }
        });
        
        // Ordenar estudios con badges por prioridad (como antes)
        const sortedWithBadges = this.sortStudiesByPriority(studiesWithBadges);
        
        // Ordenar estudios normales por la columna seleccionada
        console.log(`🔍 [sortStudies] Aplicando ordenamiento: columna=${this.sortConfig.column}, dirección=${this.sortConfig.direction}, estudios normales=${normalStudies.length}`);
        const sortedNormal = this.sortByColumnValue([...normalStudies], this.sortConfig.column, this.sortConfig.direction);
        console.log(`✅ [sortStudies] Estudios ordenados: ${sortedNormal.length} estudios`);
        console.log(`✅ [sortStudies] Estudios ordenados: ${sortedNormal.length} estudios`);
        
        // Combinar: estudio en workspace primero, luego estudios con badges, luego normales ordenados
        return [...workspaceStudy, ...sortedWithBadges, ...sortedNormal];
    }

    /**
     * Orden para cola de lectura: mismo criterio que sortStudies pero sin promover
     * el estudio abierto en workspace al tope (pinWorkspaceOnTop ignorado).
     */
    sortStudiesForQueue(studies) {
        const prevPin = this.pinWorkspaceOnTop;
        this.pinWorkspaceOnTop = false;
        const sorted = this.sortStudies([...studies]);
        this.pinWorkspaceOnTop = prevPin;
        return sorted;
    }

    /**
     * Estudios elegibles para la cola de lectura en workspace.
     */
    getQueueEligibleStudies() {
        return this.filteredStudies.filter(study => {
            if (this.isHighPriorityPrioridad(study.prioridad)) return false;
            if (this.studyHasInforme(study)) {
                const isIncomplete = study.is_incomplete ||
                    (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
                return !!isIncomplete;
            }
            return true;
        });
    }

    /**
     * Construye o limpia la cola compartida al abrir un estudio en workspace.
     */
    buildStudyQueue(study) {
        if (!window.StudyQueue) return;

        if (this.isHighPriorityPrioridad(study.prioridad)) {
            StudyQueue.clear();
            console.log('📋 Cola no activada: estudio con prioridad alta');
            return;
        }

        const eligible = this.getQueueEligibleStudies();
        const sorted = this.sortStudiesForQueue(eligible);
        const userId = this.userId ||
            sessionStorage.getItem('user_id') ||
            localStorage.getItem('user_id') ||
            '';

        StudyQueue.build(sorted, study, userId, {
            column: this.sortConfig.column,
            direction: this.sortConfig.direction
        });

        console.log('📋 Cola de lectura construida:', {
            total: sorted.length,
            current: StudyQueue.getStudyKey(study)
        });
    }

    getQueueIndicatorHtml(study) {
        if (!window.StudyQueue) return '';
        const badge = StudyQueue.getQueueBadgeForStudy(study);
        if (!badge) return '';
        return `
            <div style="margin-top: 4px;">
                <span class="badge bg-success text-white study-queue-indicator"
                      title="Posición en cola de lectura"
                      style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                    <i class="fas fa-list-ol me-1"></i>Cola ${badge}
                </span>
            </div>
        `;
    }

    setupStudyQueueListener() {
        if (!window.StudyQueue) return;

        StudyQueue.onUpdate(() => {
            if (this.filteredStudies && this.filteredStudies.length > 0) {
                this.renderStudies();
            }
        });
    }
    
    /**
     * Ordena estudios por el valor de una columna específica
     */
    sortByColumnValue(studies, column, direction) {
        if (!column) {
            // Si no hay columna seleccionada, ordenar por fecha descendente
            return studies.sort((a, b) => {
                const dateA = a.date || a.study_date || '';
                const dateB = b.date || b.study_date || '';
                if (dateA && dateB) {
                    return dateB.localeCompare(dateA);
                }
                if (dateA) return -1;
                if (dateB) return 1;
                return 0;
            });
        }
        
        return studies.sort((a, b) => {
            let valueA, valueB;
            
            switch (column) {
                case 'date':
                    valueA = a.date || a.study_date || '';
                    valueB = b.date || b.study_date || '';
                    break;
                case 'time':
                    valueA = a.time || a.study_time || '';
                    valueB = b.time || b.study_time || '';
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
            let comparison = 0;
            if (valueA < valueB) {
                comparison = -1;
            } else if (valueA > valueB) {
                comparison = 1;
            }
            
            // Aplicar dirección de ordenamiento
            return direction === 'asc' ? comparison : -comparison;
        });
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
        // Prevenir ejecución múltiple simultánea
        if (this._updatingIndicators) {
            return;
        }
        this._updatingIndicators = true;
        
        const studyRows = document.querySelectorAll('[data-study-id]');
        studyRows.forEach(row => {
            const studyId = row.getAttribute('data-study-id');
            if (studyId) {
                const reportButton = row.querySelector('.btn-report, .btn-warning');
                if (reportButton) {
                    const isInProgress = this.checkInProgressReport(studyId);
                    const currentIsInProgress = reportButton.classList.contains('btn-warning');
                    
                    // Solo actualizar si el estado cambió para evitar parpadeo
                    if (isInProgress !== currentIsInProgress) {
                        this.updateReportButtonState(reportButton, isInProgress);
                    }
                }
            }
        });
        
        this._updatingIndicators = false;
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
     * Actualiza los botones de modalidad basándose en las modalidades disponibles en los estudios del periodo
     */
    updateAvailableModalities() {
        // Determinar qué estudios usar para calcular modalidades disponibles
        let studiesToCheck = [];
        
        if (this.currentFilters.dateFrom && this.currentFilters.dateTo) {
            // Si hay filtros de fecha, usar estudios del periodo filtrado
            const dateFrom = this.currentFilters.dateFrom.replace(/-/g, '');
            const dateTo = this.currentFilters.dateTo.replace(/-/g, '');
            
            studiesToCheck = this.studies.filter(study => {
                if (!study.date) return false;
                const studyDate = study.date.replace(/-/g, '').substring(0, 8);
                return studyDate >= dateFrom && studyDate <= dateTo;
            });
        } else {
            // Si no hay filtros de fecha, usar todos los estudios disponibles
            studiesToCheck = this.studies;
        }
        
        if (!studiesToCheck || studiesToCheck.length === 0) {
            // Si no hay estudios en el periodo, ocultar todos los botones excepto "Todas"
            const modalityButtons = document.querySelectorAll('.modality-btn');
            modalityButtons.forEach(btn => {
                const modality = btn.getAttribute('data-modality');
                if (modality === 'all') {
                    btn.style.display = 'inline-block';
                    btn.disabled = false;
                } else {
                    btn.style.display = 'none';
                    btn.disabled = true;
                }
            });
            return;
        }
        
        // Obtener modalidades únicas de los estudios del periodo
        // study.modality puede ser una cadena como "CT, DOC" o una sola modalidad
        const allModalities = [];
        studiesToCheck.forEach(study => {
            if (study.modality) {
                // Dividir por comas y obtener modalidades individuales
                const studyModalities = study.modality.split(',').map(m => m.trim()).filter(Boolean);
                allModalities.push(...studyModalities);
            }
        });
        const availableModalities = [...new Set(allModalities)];
        
        // Mapeo de modalidades DICOM comunes
        const modalityMap = {
            'RX': 'CR',
            'DX': 'CR',
            'CT': 'CT',
            'MR': 'MR',
            'US': 'US',
            'MG': 'MG',
            'NM': 'NM',
            'PT': 'PT',
            'XA': 'XA',
            'RF': 'RF',
            'OT': 'OT'
        };
        
        // Obtener todos los botones de modalidad
        const modalityButtons = document.querySelectorAll('.modality-btn');
        
        modalityButtons.forEach(btn => {
            const modality = btn.getAttribute('data-modality');
            
            if (modality === 'all') {
                // El botón "Todas" siempre está disponible
                btn.style.display = 'inline-block';
                btn.disabled = false;
            } else {
                // Verificar si la modalidad está disponible (directa o mediante mapeo)
                const dicomModality = modalityMap[modality] || modality;
                const isAvailable = availableModalities.includes(modality) || availableModalities.includes(dicomModality);
                
                if (isAvailable) {
                    btn.style.display = 'inline-block';
                    btn.disabled = false;
                } else {
                    btn.style.display = 'none';
                    btn.disabled = true;
                }
            }
        });
        
        console.log('Modalidades disponibles en el periodo:', availableModalities);
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
        const reportStatusFilter = document.getElementById('reportStatusFilter');
        const assignmentSourceOnlyAssigned = document.getElementById('assignmentSourceOnlyAssigned');
        
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

        if (reportStatusFilter) {
            this.currentFilters.reportStatus = reportStatusFilter.value;
        }

        if (assignmentSourceOnlyAssigned) {
            this.currentFilters.assignmentSource = assignmentSourceOnlyAssigned.checked ? 'assigned_only' : '';
        }
    }
    
    /**
     * Renderiza el estado inicial vacío
     */
    renderEmptyState() {
        const tbody = document.querySelector('.studies-table tbody');
        if (!tbody) return;
        
        const emptyMessage = this.isAssignedStudiesMode ? 
            'No hay estudios asignados para mostrar' : 
            'Especifica los criterios de búsqueda y presiona "Buscar" para consultar estudios';
        
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="fas fa-search fa-2x mb-3"></i>
                    <p class="mb-0">${emptyMessage}</p>
                </td>
            </tr>
        `;
        
        // Limpiar estadísticas
        this.updateStats();
        this.updateAvailableModalities(); // Ocultar modalidades cuando no hay estudios
    }
    
    /**
     * Actualiza el dropdown de instituciones con las instituciones disponibles
     */
    updateInstitutionFilter() {
        const institutionFilter = document.getElementById('institutionFilter');
        if (!institutionFilter) return;
        
        // Obtener todas las instituciones únicas de los estudios
        const institutions = new Set();
        this.studies.forEach(study => {
            if (study.institution_name && study.institution_name.trim() !== '') {
                institutions.add(study.institution_name);
            }
        });
        
        // Si el usuario tiene permiso de filtrar por instituciones, filtrar las opciones
        let filteredInstitutions = Array.from(institutions);
        if (this.hasFilterInstitutionsPermission && this.allowedInstitutions.length > 0) {
            filteredInstitutions = filteredInstitutions.filter(inst => {
                const instUpper = inst.trim().toUpperCase();
                return this.allowedInstitutions.includes(instUpper);
            });
        }
        
        // Ordenar instituciones alfabéticamente
        const sortedInstitutions = filteredInstitutions.sort();
        
        const domValue = institutionFilter.value;
        const savedName = (this.currentFilters.institutionName || '').trim();
        
        // Limpiar opciones existentes (excepto la primera "Todas")
        institutionFilter.innerHTML = '<option value="">Todas las Instituciones</option>';
        
        // Agregar opciones de instituciones
        sortedInstitutions.forEach(institution => {
            const option = document.createElement('option');
            option.value = institution;
            option.textContent = institution;
            institutionFilter.appendChild(option);
        });
        
        // Preferir filtro persistido; si no, lo que ya estaba en el DOM
        const pick =
            savedName && sortedInstitutions.includes(savedName)
                ? savedName
                : domValue && sortedInstitutions.includes(domValue)
                  ? domValue
                  : '';
        institutionFilter.value = pick;
        this.currentFilters.institutionName = pick;
    }
    
    /**
     * Actualiza las estadísticas del dashboard
     */
    updateStats() {
        // Calcular estudios de hoy
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const todayStr = today.toISOString().split('T')[0].replace(/-/g, '');
        
        const todayStudies = this.studies.filter(study => {
            if (!study.date) return false;
            const studyDate = study.date.replace(/-/g, '').substring(0, 8); // Asegurar formato YYYYMMDD
            return studyDate === todayStr;
        }).length;
        
        // Calcular estudios en el periodo filtrado
        let periodoStudies = 0;
        
        if (this.currentFilters.dateFrom && this.currentFilters.dateTo) {
            // Si hay filtros de fecha, calcular estudios en ese periodo
            const dateFrom = this.currentFilters.dateFrom.replace(/-/g, '');
            const dateTo = this.currentFilters.dateTo.replace(/-/g, '');
            
            periodoStudies = this.studies.filter(study => {
                if (!study.date) return false;
                const studyDate = study.date.replace(/-/g, '').substring(0, 8); // Asegurar formato YYYYMMDD
                return studyDate >= dateFrom && studyDate <= dateTo;
            }).length;
        } else {
            // Si no hay filtros de fecha, mostrar todos los estudios disponibles
            periodoStudies = this.studies.length;
        }
        
        // Calcular estudios urgentes y incompletos
        const urgentStudies = this.studies.filter(study => {
            const hasPriority = study.prioridad && this.isHighPriorityPrioridad(study.prioridad);
            const isUrgent = study.is_urgent || hasPriority;
            return isUrgent;
        });
        
        const incompleteStudies = this.studies.filter(study => {
            const isIncomplete = study.is_incomplete || 
                               (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
            return isIncomplete;
        });
        
        // Actualizar estadísticas en el DOM usando IDs específicos
        const estudiosHoyEl = document.getElementById('estudiosHoy');
        const totalPeriodoEl = document.getElementById('totalPeriodo');
        const estudiosUrgentesEl = document.getElementById('estudiosUrgentes');
        const estudiosIncompletosEl = document.getElementById('estudiosIncompletos');
        const urgentesBadgeEl = document.getElementById('urgentesBadge');
        const badgeElement = document.querySelector('.badge.bg-primary');
        
        if (estudiosHoyEl) {
            estudiosHoyEl.textContent = todayStudies;
        }
        
        if (totalPeriodoEl) {
            totalPeriodoEl.textContent = periodoStudies;
        }
        
        // Actualizar contadores de urgentes e incompletos
        if (estudiosUrgentesEl) {
            estudiosUrgentesEl.textContent = urgentStudies.length;
        }
        
        if (estudiosIncompletosEl) {
            estudiosIncompletosEl.textContent = incompleteStudies.length;
        }
        
        // Mostrar/ocultar badge de urgentes según si hay estudios
        if (urgentesBadgeEl) {
            if (urgentStudies.length > 0) {
                urgentesBadgeEl.textContent = urgentStudies.length;
                urgentesBadgeEl.style.display = 'block';
            } else {
                urgentesBadgeEl.style.display = 'none';
            }
        }
        
        if (badgeElement) {
            badgeElement.textContent = `${this.filteredStudies.length} estudios`;
        }
        
        // Fallback: si los IDs no existen, usar selectores genéricos (compatibilidad)
        const statNumbers = document.querySelectorAll('.stat-number');
        if (statNumbers.length >= 2 && !estudiosHoyEl) {
            statNumbers[0].textContent = todayStudies;
        }
        if (statNumbers.length >= 2 && !totalPeriodoEl) {
            statNumbers[1].textContent = periodoStudies;
        }
    }
    
    /**
     * Actualiza los contadores de estudios urgentes e incompletos en el header
     */
    updatePriorityCounters(urgentStudies = null, incompleteStudies = null) {
        // Si no se pasan como parámetros, calcularlos desde this.studies
        if (urgentStudies === null || incompleteStudies === null) {
            urgentStudies = this.studies.filter(study => {
                const hasPriority = study.prioridad && this.isHighPriorityPrioridad(study.prioridad);
                const isUrgent = study.is_urgent || hasPriority;
                return isUrgent;
            });
            
            incompleteStudies = this.studies.filter(study => {
                const isIncomplete = study.is_incomplete || 
                                   (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
                return isIncomplete;
            });
        }
        
        const estudiosUrgentesEl = document.getElementById('estudiosUrgentes');
        const estudiosIncompletosEl = document.getElementById('estudiosIncompletos');
        const urgentesBadgeEl = document.getElementById('urgentesBadge');
        
        // Actualizar contador de urgentes
        if (estudiosUrgentesEl) {
            estudiosUrgentesEl.textContent = urgentStudies.length;
        }
        
        // Actualizar badge de urgentes
        if (urgentesBadgeEl) {
            if (urgentStudies.length > 0) {
                urgentesBadgeEl.textContent = urgentStudies.length;
                urgentesBadgeEl.style.display = 'block';
            } else {
                urgentesBadgeEl.style.display = 'none';
            }
        }
        
        // Actualizar contador de incompletos (solo el contador principal, sin badge)
        if (estudiosIncompletosEl) {
            estudiosIncompletosEl.textContent = incompleteStudies.length;
        }
        
        console.log(`📊 Contadores actualizados: ${urgentStudies.length} urgentes, ${incompleteStudies.length} incompletos`);
    }
    
    /**
     * Formatea una fecha a formato DD/MM/AAAA
     * Soporta formatos: YYYYMMDD, YYYY-MM-DD, DD/MM/YYYY
     */
    formatDate(dateStr) {
        if (!dateStr) return dateStr;
        
        // Limpiar la fecha de espacios y caracteres extraños
        dateStr = dateStr.toString().trim();
        
        let year, month, day;
        
        // Formato YYYY-MM-DD (con guiones)
        if (dateStr.includes('-') && dateStr.length === 10) {
            const parts = dateStr.split('-');
            year = parts[0];
            month = parts[1];
            day = parts[2];
        }
        // Formato YYYYMMDD (sin separadores)
        else if (dateStr.length === 8 && /^\d{8}$/.test(dateStr)) {
            year = dateStr.substring(0, 4);
            month = dateStr.substring(4, 6);
            day = dateStr.substring(6, 8);
        }
        // Formato DD/MM/YYYY (ya formateado)
        else if (dateStr.includes('/') && dateStr.length === 10) {
            return dateStr; // Ya está en el formato correcto
        }
        // Si no coincide con ningún formato conocido, devolver tal como está
        else {
            return dateStr;
        }
        
        // Formatear a DD/MM/AAAA
        return `${day.padStart(2, '0')}/${month.padStart(2, '0')}/${year}`;
    }
    
    /**
     * Formatea una hora a formato HH:MM
     * Soporta formatos: HHMMSS, HH:MM:SS, HH:MM, formatos incorrectos
     * Devuelve "N/A" si no hay hora disponible
     */
    formatTime(timeStr) {
        if (!timeStr) return 'N/A';
        
        // Limpiar la hora de espacios y caracteres extraños
        timeStr = timeStr.toString().trim();
        
        // Si después de limpiar está vacío, devolver N/A
        if (!timeStr || timeStr === '' || timeStr === 'undefined' || timeStr === 'null') {
            return 'N/A';
        }
        
        let hours, minutes;
        
        // Formato HH:MM:SS o HH:MM (con dos puntos)
        if (timeStr.includes(':')) {
            const parts = timeStr.split(':');
            hours = parts[0];
            minutes = parts[1] || '00';
            
            // Corregir formatos incorrectos como "12::1"
            if (minutes === '' || minutes === undefined) {
                minutes = parts[2] || '00';
            }
        }
        // Formato HHMMSS (sin separadores, 6 dígitos)
        else if (timeStr.length >= 4 && /^\d+$/.test(timeStr)) {
            hours = timeStr.substring(0, 2);
            minutes = timeStr.substring(2, 4);
        }
        // Si no coincide con ningún formato, intentar extraer números
        else {
            const numbers = timeStr.replace(/[^\d]/g, '');
            if (numbers.length >= 2) {
                hours = numbers.substring(0, 2);
                minutes = numbers.substring(2, 4) || '00';
            } else {
                return 'N/A'; // Si no se puede procesar, devolver N/A
            }
        }
        
        // Asegurar que hours y minutes sean válidos
        hours = hours.padStart(2, '0');
        minutes = minutes.padStart(2, '0');
        
        // Validar rangos
        if (parseInt(hours) > 23) hours = '00';
        if (parseInt(minutes) > 59) minutes = '00';
        
        // Si después de procesar no hay valores válidos, devolver N/A
        if (!hours || !minutes) {
            return 'N/A';
        }
        
        return `${hours}:${minutes}`;
    }
    
    /**
     * Obtiene el badge HTML para una modalidad (mismos colores Bootstrap que estudios-manager / burbuja visible).
     */
    getModalityBadge(modality) {
        const raw = modality != null ? String(modality).trim() : '';
        if (!raw) {
            return '<span class="badge bg-secondary" title="Modalidad pendiente de cargar">—</span>';
        }
        const modalityColors = {
            CT: 'bg-primary',
            MR: 'bg-success',
            RX: 'bg-info',
            DX: 'bg-info',
            US: 'bg-warning text-dark',
            CR: 'bg-secondary',
            MG: 'bg-danger',
            NM: 'bg-dark',
            PT: 'bg-dark',
            XA: 'bg-primary',
            RF: 'bg-primary',
            OT: 'bg-light text-dark border',
            DOC: 'bg-secondary'
        };
        const parts = raw.split(',').map((p) => p.trim()).filter(Boolean);
        return parts
            .map((p) => {
                const code = p.toUpperCase();
                const cls = modalityColors[code] || 'bg-secondary';
                return `<span class="badge ${cls} me-1">${this.escapeHtml(p)}</span>`;
            })
            .join('');
    }
    
    /**
     * Muestra información detallada del estudio
     * OPTIMIZADO: Carga detalles bajo demanda si no están cargados
     */
    async showStudyInfo(studyId) {
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

        // Verificar si los detalles ya están cargados
        const detailsLoaded = study._details_loaded === true;
        let currentModality = study.modality || 'No disponible';
        let currentInstancesCount = study.instances_count || 0;

        // Si instances_count es 0, intentar cargar los detalles bajo demanda
        // (similar a pacs-manager)
        if (currentInstancesCount === 0) {
            try {
                // Construir URL con seriesIds si están disponibles
                let url = `${this.apiBaseUrl}get_study_details.php?studyId=${encodeURIComponent(studyId)}`;
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
                        study._details_loaded = true;
                    }
                }
            } catch (error) {
                console.error('Error cargando detalles del estudio:', error);
                // Continuar con los datos disponibles
            }
        } else if (!detailsLoaded && study._series_ids) {
            // Si los detalles no están cargados pero tenemos series_ids, cargarlos
            try {
                const seriesIdsParam = encodeURIComponent(JSON.stringify(study._series_ids));
                const response = await fetch(`${this.apiBaseUrl}get_study_details.php?studyId=${encodeURIComponent(studyId)}&seriesIds=${seriesIdsParam}`);
                const result = await response.json();
                
                if (result.success && result.data) {
                    currentModality = result.data.modality || currentModality;
                    currentInstancesCount = result.data.instances_count || currentInstancesCount;
                    
                    // Actualizar el estudio en memoria para futuras consultas
                    study.modality = currentModality;
                    study.instances_count = currentInstancesCount;
                    study._details_loaded = true;
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
                                        <strong>Hora:</strong> ${this.formatTime(study.time || study.study_time || '')}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Modalidad:</strong> ${currentModality}
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
                                    <div class="mb-2">
                                        <strong>Institución:</strong> ${study.institution_name || 'No disponible'}
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
                                        <strong>Número de imágenes:</strong> ${currentInstancesCount}
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
                            ${!this.isAssignedStudiesMode ? `
                                <button type="button" class="btn btn-primary" onclick="window.open('${study.viewer_url}', '_blank')">
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
     * Abre el modal de antecedentes para un estudio (como estudios-manager)
     */
    showAntecedents(studyId) {
        try {
            console.log('=== INICIO showAntecedents ===');
            console.log('studyId recibido:', studyId);
            
            // Verificar permiso de antecedentes
            if (!this.hasAntecedentesPermission) {
                alert('No tienes permisos para acceder a los antecedentes médicos.');
                console.warn('⚠️ Acceso denegado: Usuario sin permiso de antecedentes');
                return;
            }
            
            // Buscar el estudio por ID
            const study = this.studies.find(s => s.id === studyId);
            if (!study) {
                console.error('Estudio no encontrado:', studyId);
                alert('Estudio no encontrado');
                return;
            }
            
            console.log('Estudio encontrado:', study);
            
            // Crear modal de antecedentes dinámicamente
            this.createAntecedentsModal(study);
            
            console.log('=== FIN showAntecedents ===');
            
        } catch (error) {
            console.error('Error abriendo modal de antecedentes:', error);
            alert('Error abriendo modal de antecedentes: ' + error.message);
        }
    }
    
    /**
     * Crea el modal de antecedentes dinámicamente
     */
    createAntecedentsModal(study) {
        try {
            console.log('Creando modal de antecedentes para:', study.id);
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('antecedentsModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Crear nuevo modal
            const modal = document.createElement('div');
            modal.id = 'antecedentsModal';
            modal.className = 'modal fade';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'antecedentsModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            
            modal.innerHTML = `
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="antecedentsModalLabel">
                                <i class="fas fa-file-medical me-2"></i>Antecedentes Médicos
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Información del Estudio -->
                            <div class="card mb-4">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-info-circle me-2"></i>Información del Estudio
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Paciente:</strong> ${study.patient_name || 'N/A'}<br>
                                            <strong>ID Paciente:</strong> ${study.patient_id || 'N/A'}<br>
                                            <strong>Modalidad:</strong> ${study.modality || 'N/A'}
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Fecha:</strong> ${study.date || 'N/A'}<br>
                                            <strong>Descripción:</strong> ${study.study_description || 'N/A'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pestañas -->
                            <ul class="nav nav-tabs" id="antecedentsTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="existing-tab" data-bs-toggle="tab" 
                                            data-bs-target="#existing" type="button" role="tab">
                                        <i class="fas fa-folder-open me-2"></i>Existentes
                                    </button>
                                </li>
                            </ul>
                            
                            <div class="tab-content" id="antecedentsTabContent">
                                <!-- Pestaña de Antecedentes Existentes -->
                                <div class="tab-pane fade show active" id="existing" role="tabpanel">
                                    <div class="mt-3">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="mb-0">
                                                <i class="fas fa-folder-open me-2"></i>Antecedentes Existentes
                                            </h6>
                                            <button class="btn btn-outline-primary btn-sm" onclick="dashboardWithPermissions.refreshExistingAntecedents()">
                                                <i class="fas fa-sync-alt me-1"></i>Actualizar
                                            </button>
                                        </div>
                                        
                                        <!-- Información de antecedentes -->
                                        <div id="existing-antecedents-info" class="alert alert-info" style="display: none;">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <strong>Notas:</strong>
                                                    <div id="existing-notes" class="mt-1"></div>
                                                </div>
                                                <div class="col-md-6">
                                                    <strong>Creado por:</strong> <span id="existing-created-by"></span><br>
                                                    <strong>Fecha:</strong> <span id="existing-created-date"></span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Lista de archivos existentes -->
                                        <div id="existing-files-container">
                                            <div class="text-center text-muted py-4" id="no-existing-files">
                                                <i class="fas fa-folder-open fa-3x mb-3"></i>
                                                <p>No hay antecedentes cargados para este estudio</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al body
            document.body.appendChild(modal);
            
            // Mostrar modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // Cargar antecedentes existentes
            setTimeout(async () => {
                await this.loadExistingAntecedents(study.id);
            }, 500);
            
        } catch (error) {
            console.error('Error creando modal de antecedentes:', error);
        }
    }
    
    /**
     * Carga los antecedentes existentes para el estudio actual
     */
    async loadExistingAntecedents(studyId) {
        try {
            console.log('Cargando antecedentes existentes para:', studyId);
            
            const response = await fetch(`${this.apiBaseUrl}study_antecedents.php?study_id=${studyId}`);
            const result = await response.json();
            
            console.log('Respuesta del API:', result);
            
            if (result.success && result.data.antecedents) {
                console.log('Antecedentes encontrados:', result.data.antecedents);
                console.log('Archivos encontrados:', result.data.files);
                this.displayExistingAntecedents(result.data.antecedents, result.data.files);
            } else {
                console.log('No hay antecedentes o respuesta no exitosa');
                this.hideExistingAntecedents();
            }
            
        } catch (error) {
            console.error('Error cargando antecedentes existentes:', error);
            this.hideExistingAntecedents();
        }
    }
    
    /**
     * Muestra los antecedentes existentes en la pestaña correspondiente
     */
    displayExistingAntecedents(antecedents, files) {
        try {
            console.log('Mostrando antecedentes existentes:', antecedents, files);
            
            // Mostrar información de antecedentes
            const infoDiv = document.getElementById('existing-antecedents-info');
            const notesDiv = document.getElementById('existing-notes');
            const createdBySpan = document.getElementById('existing-created-by');
            const createdDateSpan = document.getElementById('existing-created-date');
            
            if (antecedents && infoDiv && notesDiv && createdBySpan && createdDateSpan) {
                console.log('Antecedentes recibidos:', antecedents);
                console.log('Notas recibidas:', antecedents.notes);
                
                // Mostrar las notas
                const notesText = antecedents.notes || 'Sin notas';
                notesDiv.textContent = notesText;
                console.log('Notas mostradas:', notesText);
                
                // Mostrar información del creador
                const creatorName = antecedents.created_by_name || 'Usuario';
                const creatorSurname = antecedents.created_by_surname || 'Desconocido';
                createdBySpan.textContent = `${creatorName} ${creatorSurname}`;
                
                // Mostrar fecha de creación
                const createdDate = antecedents.created_date ? 
                    new Date(antecedents.created_date).toLocaleString() : 'Fecha desconocida';
                createdDateSpan.textContent = createdDate;
                
                // Mostrar el div de información
                infoDiv.style.display = 'block';
            }
            
            // Mostrar archivos si existen
            if (files && files.length > 0) {
                console.log('Mostrando archivos:', files);
                this.displayExistingFiles(files);
            } else {
                console.log('No hay archivos para mostrar');
                this.hideExistingFiles();
            }
            
        } catch (error) {
            console.error('Error mostrando antecedentes existentes:', error);
        }
    }
    
    /**
     * Construye la URL correcta para un archivo de antecedentes
     */
    buildFileUrl(file) {
        let fileUrl;
        if (file.file_path) {
            // Si file_path contiene ../uploads/antecedents/filename, convertir a URL accesible
            if (file.file_path.startsWith('../uploads/')) {
                const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
                fileUrl = `${baseUrl}${file.file_path.replace('../', '')}`;
            } else if (file.file_path.startsWith('uploads/')) {
                const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
                fileUrl = `${baseUrl}${file.file_path}`;
            } else {
                // Si ya es una URL completa, usarla tal como está
                fileUrl = file.file_path;
            }
        } else {
            // Fallback: construir URL usando file_name
            const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
            fileUrl = `${baseUrl}uploads/antecedents/${file.file_name}`;
        }
        
        console.log('🔍 Debug - Construyendo URL para archivo:', {
            originalPath: file.file_path,
            fileName: file.file_name,
            constructedUrl: fileUrl
        });
        
        return fileUrl;
    }
    
    /**
     * Muestra los archivos existentes usando el FileViewer
     */
    displayExistingFiles(files) {
        const container = document.getElementById('existing-files-container');
        const noFilesDiv = document.getElementById('no-existing-files');
        
        if (!container) return;
        
        // Ocultar mensaje de "no hay archivos"
        if (noFilesDiv) {
            noFilesDiv.style.display = 'none';
        }
        
        // Crear lista de archivos usando FileViewer
        const filesList = document.createElement('div');
        filesList.id = 'existing-files-list';
        filesList.className = 'row';
        
        // Verificar que FileViewer esté disponible
        if (typeof FileViewer === 'undefined') {
            console.error('FileViewer no está disponible');
            // Fallback al método anterior
            this.displayExistingFilesLegacy(files);
            return;
        }
        
        files.forEach(file => {
            const fileCard = document.createElement('div');
            fileCard.className = 'col-md-4 mb-3';
            
            // Construir la URL completa del archivo usando la función auxiliar
            const fileUrl = this.buildFileUrl(file);
            
            // Usar FileViewer para crear la visualización del archivo
            const fileViewer = FileViewer.createFileCard({
                fileName: file.file_name, // Usar file_name que es el nombre original
                filePath: fileUrl,
                fileType: file.file_type,
                mimeType: file.mime_type,
                fileSize: file.file_size,
                uploadDate: file.uploaded_date
            });
            
            fileCard.appendChild(fileViewer);
            filesList.appendChild(fileCard);
        });
        
        container.appendChild(filesList);
    }
    
    /**
     * Método legacy para mostrar archivos (fallback)
     */
    displayExistingFilesLegacy(files) {
        const container = document.getElementById('existing-files-container');
        const noFilesDiv = document.getElementById('no-existing-files');
        
        if (!container) return;
        
        // Ocultar mensaje de "no hay archivos"
        if (noFilesDiv) {
            noFilesDiv.style.display = 'none';
        }
        
        // Crear lista de archivos
        const filesList = document.createElement('div');
        filesList.id = 'existing-files-list';
        filesList.className = 'row';
        
        files.forEach(file => {
            const fileCard = document.createElement('div');
            fileCard.className = 'col-md-4 mb-3';
            
            // Construir la URL completa del archivo usando la función auxiliar
            const fileUrl = this.buildFileUrl(file);
            
            fileCard.innerHTML = `
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-file me-2"></i>
                            <div class="flex-grow-1">
                                <h6 class="card-title mb-1">${file.file_name || 'Archivo'}</h6>
                                <small class="text-muted">${file.file_type || 'Tipo desconocido'}</small>
                            </div>
                        </div>
                        <div class="mt-2">
                            <button class="btn btn-outline-primary btn-sm" onclick="window.open('${fileUrl}', '_blank')">
                                <i class="fas fa-eye me-1"></i>Ver
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            filesList.appendChild(fileCard);
        });
        
        container.appendChild(filesList);
    }
    
    /**
     * Oculta los archivos existentes
     */
    hideExistingFiles() {
        const filesList = document.getElementById('existing-files-list');
        const noFilesDiv = document.getElementById('no-existing-files');
        
        if (filesList) filesList.remove();
        if (noFilesDiv) noFilesDiv.style.display = 'block';
    }
    
    /**
     * Oculta los antecedentes existentes
     */
    hideExistingAntecedents() {
        const infoDiv = document.getElementById('existing-antecedents-info');
        const noFilesDiv = document.getElementById('no-existing-files');
        
        if (infoDiv) infoDiv.style.display = 'none';
        if (noFilesDiv) noFilesDiv.style.display = 'block';
    }
    
    /**
     * Actualiza los antecedentes existentes
     */
    async refreshExistingAntecedents() {
        // Obtener el studyId del modal actual
        const modal = document.getElementById('antecedentsModal');
        if (!modal) return;
        
        // Buscar el estudio actual (esto es una simplificación)
        const studyId = this.currentStudyId || this.studies[0]?.id;
        if (studyId) {
            await this.loadExistingAntecedents(studyId);
        }
    }
    
    /**
     * Carga antecedentes para estudios del PACS
     */
    async loadAntecedentsForPacsStudies() {
        try {
            console.log('=== INICIO loadAntecedentsForPacsStudies ===');
            console.log('Cargando antecedentes para estudios del PACS...');
            console.log('Estudios disponibles:', this.studies.length);
            
            if (this.studies.length === 0) {
                console.log('No hay estudios para cargar antecedentes');
                return;
            }
            
            // Obtener IDs de estudios (usar study_id o study_instance_uid según la tabla)
            const studyIds = this.studies.map(study => study.study_id || study.study_instance_uid || study.id).filter(id => id);
            console.log('Study IDs para consultar antecedentes:', studyIds.length);
            
            if (studyIds.length === 0) {
                console.log('No hay IDs de estudios válidos');
                return;
            }
            
            // Dividir en lotes de 100 para evitar error 414 (Request-URI Too Large)
            const batchSize = 100;
            const batches = [];
            for (let i = 0; i < studyIds.length; i += batchSize) {
                batches.push(studyIds.slice(i, i + batchSize));
            }
            
            console.log(`Dividiendo ${studyIds.length} estudios en ${batches.length} lotes`);
            
            // Cargar antecedentes en lotes usando POST
            const allResults = [];
            for (let i = 0; i < batches.length; i++) {
                const batch = batches[i];
                console.log(`Cargando lote ${i + 1}/${batches.length} (${batch.length} estudios)...`);
                
                try {
                    const response = await fetch(`${this.apiBaseUrl}study_antecedents.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            study_ids: batch
                        })
                    });
                    
                    if (!response.ok) {
                        console.error(`Error en lote ${i + 1}:`, response.status, response.statusText);
                        continue;
                    }
                    
                    const result = await response.json();
                    
                    if (result.success && result.data) {
                        allResults.push(...result.data);
                        console.log(`Lote ${i + 1} cargado: ${result.data.length} estudios con antecedentes`);
                    }
                } catch (error) {
                    console.error(`Error cargando lote ${i + 1}:`, error);
                    // Continuar con el siguiente lote
                }
            }
            
            console.log(`Total de estudios con antecedentes: ${allResults.length}`);
            
            if (allResults.length > 0) {
                console.log('Datos de antecedentes recibidos:', allResults);
                
                // Asociar antecedentes con estudios
                allResults.forEach(item => {
                    console.log('Procesando antecedentes para estudio:', item.study_id, 'con', item.total_antecedents, 'antecedentes');
                    
                    // Buscar el estudio correspondiente
                    const study = this.studies.find(s => 
                        (s.study_id && s.study_id === item.study_id) || 
                        (s.study_instance_uid && s.study_instance_uid === item.study_id)
                    );
                    
                    if (study) {
                        // Asociar datos de antecedentes al estudio
                        study.antecedents = {
                            has_notes: item.has_notes > 0,
                            has_files: item.files_count > 0,
                            total_count: item.total_antecedents || 0,
                            files_count: item.files_count || 0,
                            notes: item.notes || '',
                            created_date: item.created_date || '',
                            created_by_name: item.created_by_name || '',
                            created_by_surname: item.created_by_surname || ''
                        };
                        console.log('Antecedentes asociados al estudio:', study.id, study.antecedents);
                    } else {
                        console.warn('Estudio no encontrado para antecedentes:', item.study_id);
                    }
                });
                
                // Actualizar contadores en la UI (con delay para DOM)
                setTimeout(() => {
                    this.updateAntecedentsCounters();
                }, 100);
                
                console.log(`Antecedentes cargados para estudios del PACS: ${allResults.length} estudios con antecedentes`);
            } else {
                console.log('No se encontraron antecedentes o respuesta no exitosa');
                // Asignar datos por defecto para estudios sin antecedentes
                this.studies.forEach(study => {
                    if (!study.antecedents) {
                        study.antecedents = {
                            has_notes: false,
                            has_files: false,
                            total_count: 0,
                            files_count: 0,
                            notes: '',
                            created_date: '',
                            created_by_name: '',
                            created_by_surname: ''
                        };
                    }
                });
                setTimeout(() => {
                    this.updateAntecedentsCounters();
                }, 100);
            }
            
            console.log('=== FIN loadAntecedentsForPacsStudies ===');
            
        } catch (error) {
            console.error('Error cargando antecedentes para estudios del PACS:', error);
            // Asignar datos por defecto en caso de error
            this.studies.forEach(study => {
                if (!study.antecedents) {
                    study.antecedents = {
                        has_notes: false,
                        has_files: false,
                        total_count: 0,
                        files_count: 0,
                        notes: '',
                        created_date: '',
                        created_by_name: '',
                        created_by_surname: ''
                    };
                }
            });
            setTimeout(() => {
                this.updateAntecedentsCounters();
            }, 100);
        }
    }
    
    /**
     * Verifica y corrige estudios que necesitan consulta de antecedentes
     */
    async checkAndFixMissingAntecedents() {
        try {
            // Prevenir ejecución múltiple simultánea
            if (this._checkingAntecedents) {
                return;
            }
            this._checkingAntecedents = true;
            
            const studiesNeedingCheck = this.studies.filter(study => study._needsAntecedentsCheck && !study._antecedentsChecked);
            
            if (studiesNeedingCheck.length > 0) {
                const studyIds = studiesNeedingCheck.map(study => study.study_id || study.study_instance_uid || study.id).filter(id => id);
                
                if (studyIds.length > 0) {
                    const url = `${this.apiBaseUrl}study_antecedents.php?study_ids=${studyIds.join(',')}`;
                    const response = await fetch(url);
                    const result = await response.json();
                    
                    if (result.success && result.data) {
                        let needsRerender = false;
                        
                        result.data.forEach(item => {
                            const study = this.studies.find(s => 
                                (s.study_id && s.study_id === item.study_id) || 
                                (s.study_instance_uid && s.study_instance_uid === item.study_id) ||
                                (s.id === item.study_id)
                            );
                            
                            if (study && !study._antecedentsChecked) {
                                study.antecedents = {
                                    has_notes: item.has_notes > 0,
                                    has_files: item.files_count > 0,
                                    total_count: item.total_antecedents || 0,
                                    files_count: item.files_count || 0,
                                    notes: item.notes || '',
                                    created_date: item.created_date || '',
                                    created_by_name: item.created_by_name || '',
                                    created_by_surname: item.created_by_surname || ''
                                };
                                study._needsAntecedentsCheck = false;
                                study._antecedentsChecked = true;
                                needsRerender = true;
                            }
                        });
                        
                        // Re-renderizar solo si hubo cambios
                        if (needsRerender) {
                            this.renderStudies();
                        }
                    }
                    
                    // Marcar todos los estudios como verificados para evitar loops
                    studiesNeedingCheck.forEach(study => {
                        study._antecedentsChecked = true;
                    });
                }
            }
            
            this._checkingAntecedents = false;
            
        } catch (error) {
            console.error('Error verificando antecedentes faltantes:', error);
            this._checkingAntecedents = false;
        }
    }
    
    /**
     * Ordena estudios por prioridad: incompletos primero, luego por fecha descendente
     */
    sortStudiesByPriority(studies) {
        const priorityOrder = { 'urgente': 5, 'promesa': 4, 'pendiente': 3, 'incompleto': 2, 'normal': 1 };
        
        return studies.sort((a, b) => {
            // Determinar el grupo de prioridad de cada estudio
            const getPriorityGroup = (study) => {
                const prioridad = study.prioridad || (study.informes_incompletos && study.informes_incompletos.prioridad) || 'normal';
                const isIncomplete = study.is_incomplete || (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
                
                if (prioridad === 'urgente') return 'urgente';
                if (prioridad === 'promesa') return 'promesa';
                if (prioridad === 'pendiente') return 'pendiente';
                if (isIncomplete && prioridad === 'normal') return 'incompleto';
                return 'normal';
            };
            
            const groupA = getPriorityGroup(a);
            const groupB = getPriorityGroup(b);
            
            // Primero ordenar por grupo de prioridad
            const priorityDiff = priorityOrder[groupB] - priorityOrder[groupA];
            if (priorityDiff !== 0) {
                return priorityDiff;
            }
            
            // Dentro del mismo grupo, ordenar por fecha descendente
            const dateA = a.date || a.study_date || '';
            const dateB = b.date || b.study_date || '';
            if (dateA && dateB) {
                return dateB.localeCompare(dateA);
            }
            if (dateA) return -1;
            if (dateB) return 1;
            return 0;
        });
    }
    
    /**
     * Actualiza los contadores de antecedentes directamente desde los datos de los estudios
     * Los contadores están integrados en el HTML, esta función solo verifica consistencia
     */
    updateAntecedentsCounters() {
        // Los contadores están integrados en el HTML generado por createStudyRow
        // No necesitan actualización separada, solo se mantiene para compatibilidad
    }
    
    /**
     * Obtiene la URL de descargas configurada desde el servidor
     */
    async getDownloadUrl() {
        try {
            // Intentar obtener desde caché si existe
            if (this._downloadUrlCache) {
                return this._downloadUrlCache;
            }
            
            // Obtener desde la API
            const apiBaseUrl = this.apiBaseUrl;
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
            return `study_${study.orthanc_study_id || study.id || 'unknown'}.zip`;
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
            const downloadBtn = document.querySelector(`button[data-orthanc-study-id="${orthancStudyId}"], button[onclick*="${orthancStudyId}"]`);
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
                (s.id && s.id === orthancStudyId)
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
            const downloadBtn = document.querySelector(`button[data-orthanc-study-id="${orthancStudyId}"], button[onclick*="${orthancStudyId}"]`);
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
                    <small>Verifique que el servidor esté ejecutándose y la configuración sea correcta.</small>
                </div>
            `;
        }
    }
    
    /**
     * Muestra un mensaje de éxito usando toast
     */
    showSuccess(message) {
        // Crear contenedor de toasts si no existe
        let toastContainer = document.getElementById('dashboardToastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'dashboardToastContainer';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        const toastId = 'successToast_' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-check-circle me-2"></i>
                        ${this.escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 3000
        });
        
        toast.show();
        
        // Remover toast del DOM cuando se oculte
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
    
    /**
     * Muestra un mensaje de error usando toast (para errores menores que no requieren reemplazar el contenido)
     */
    showErrorToast(message) {
        // Crear contenedor de toasts si no existe
        let toastContainer = document.getElementById('dashboardToastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'dashboardToastContainer';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        const toastId = 'errorToast_' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        ${this.escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 5000
        });
        
        toast.show();
        
        // Remover toast del DOM cuando se oculte
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
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
                pagination: {
                    perPage: this.pagination.perPage
                    // No guardamos currentPage para que siempre empiece en la página 1 al restaurar
                },
                timestamp: Date.now()
            };
            localStorage.setItem(this.storageKey, JSON.stringify(state));
            console.log('Estado guardado en localStorage');
            
            // Logging para verificar datos de antecedentes
            const studiesWithAntecedents = this.studies.filter(s => s.antecedents && s.antecedents.total_count > 0);
            console.log(`Estudios con antecedentes guardados: ${studiesWithAntecedents.length}`);
            studiesWithAntecedents.forEach(study => {
                console.log(`  - ${study.id}: ${study.antecedents.total_count} antecedentes`);
            });
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
            
            // Logging para verificar datos de antecedentes cargados
            if (state.studies) {
                const studiesWithAntecedents = state.studies.filter(s => s.antecedents && s.antecedents.total_count > 0);
                console.log(`Estudios con antecedentes cargados desde localStorage: ${studiesWithAntecedents.length}`);
                studiesWithAntecedents.forEach(study => {
                    console.log(`  - ${study.id}: ${study.antecedents.total_count} antecedentes`);
                });
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

            const reportStatusFilter = document.getElementById('reportStatusFilter');
            if (reportStatusFilter) {
                reportStatusFilter.value = this.currentFilters.reportStatus || '';
            }

            const assignmentSourceOnlyAssigned = document.getElementById('assignmentSourceOnlyAssigned');
            if (assignmentSourceOnlyAssigned) {
                assignmentSourceOnlyAssigned.checked = this.currentFilters.assignmentSource === 'assigned_only';
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
    
    /**
     * Muestra el menú contextual (click derecho) para un estudio
     */
    showContextMenu(event, study) {
        // Remover menú contextual existente si existe
        const existingMenu = document.getElementById('studyContextMenu');
        if (existingMenu) {
            existingMenu.remove();
        }
        
        // Verificar si hay texto seleccionado
        const selectedText = window.getSelection().toString().trim();
        const hasSelectedText = selectedText.length > 0;
        
        // Crear menú contextual
        const menu = document.createElement('div');
        menu.id = 'studyContextMenu';
        menu.className = 'context-menu';
        menu.style.cssText = `
            position: fixed;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 10000;
            min-width: 200px;
            padding: 4px 0;
            left: ${event.clientX}px;
            top: ${event.clientY}px;
        `;
        
        // Opción: Copiar texto seleccionado (solo si hay texto seleccionado)
        if (hasSelectedText) {
            const copyOption = document.createElement('div');
            copyOption.className = 'context-menu-item';
            copyOption.style.cssText = `
                padding: 8px 16px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
            `;
            copyOption.innerHTML = `
                <i class="fas fa-copy"></i>
                <span>Copiar</span>
            `;
            copyOption.addEventListener('click', async () => {
                try {
                    // Intentar usar la API moderna de Clipboard
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(selectedText);
                        this.showSuccess('Texto copiado al portapapeles');
                        menu.remove();
                        return;
                    }
                    
                    // Fallback para navegadores que no soportan Clipboard API
                    const textArea = document.createElement('textarea');
                    textArea.value = selectedText;
                    textArea.style.position = 'fixed';
                    textArea.style.top = '0';
                    textArea.style.left = '0';
                    textArea.style.width = '2em';
                    textArea.style.height = '2em';
                    textArea.style.padding = '0';
                    textArea.style.border = 'none';
                    textArea.style.outline = 'none';
                    textArea.style.boxShadow = 'none';
                    textArea.style.background = 'transparent';
                    textArea.style.opacity = '0';
                    textArea.style.zIndex = '-1';
                    
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    
                    const successful = document.execCommand('copy');
                    document.body.removeChild(textArea);
                    
                    if (successful) {
                        this.showSuccess('Texto copiado al portapapeles');
                        menu.remove();
                    } else {
                        // Si falla, mostrar mensaje y ofrecer alternativa
                        this.showErrorToast('No se pudo copiar automáticamente');
                        // Ofrecer mostrar el texto en un prompt
                        setTimeout(() => {
                            const userConfirmed = confirm('No se pudo copiar automáticamente. ¿Desea ver el texto para copiarlo manualmente?');
                            if (userConfirmed) {
                                prompt('Copie el siguiente texto:', selectedText);
                            }
                        }, 100);
                        menu.remove();
                    }
                } catch (err) {
                    console.error('Error copiando texto:', err);
                    // Intentar método alternativo
                    try {
                        const textArea = document.createElement('textarea');
                        textArea.value = selectedText;
                        textArea.style.position = 'fixed';
                        textArea.style.top = '0';
                        textArea.style.left = '0';
                        textArea.style.width = '2em';
                        textArea.style.height = '2em';
                        textArea.style.padding = '0';
                        textArea.style.border = 'none';
                        textArea.style.outline = 'none';
                        textArea.style.boxShadow = 'none';
                        textArea.style.background = 'transparent';
                        textArea.style.opacity = '0';
                        textArea.style.zIndex = '-1';
                        
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        
                        const successful = document.execCommand('copy');
                        document.body.removeChild(textArea);
                        
                        if (successful) {
                            this.showSuccess('Texto copiado al portapapeles');
                            menu.remove();
                        } else {
                            // Mostrar mensaje de error con toast
                            this.showErrorToast('No se pudo copiar el texto');
                            setTimeout(() => {
                                prompt('Copie el siguiente texto:', selectedText);
                            }, 100);
                            menu.remove();
                        }
                    } catch (e) {
                        console.error('Error en fallback de copiado:', e);
                        // Mostrar mensaje de error con toast
                        this.showErrorToast('No se pudo copiar el texto');
                        setTimeout(() => {
                            prompt('No se pudo copiar automáticamente. Copie el siguiente texto:', selectedText);
                        }, 100);
                        menu.remove();
                    }
                }
            });
            copyOption.addEventListener('mouseenter', () => {
                copyOption.style.backgroundColor = '#f0f0f0';
            });
            copyOption.addEventListener('mouseleave', () => {
                copyOption.style.backgroundColor = 'transparent';
            });
            
            menu.appendChild(copyOption);
            
            // Agregar separador si también hay opción de workspace
            if (this.hasWorkspacePermission) {
                const separator = document.createElement('div');
                separator.style.cssText = `
                    height: 1px;
                    background-color: #e0e0e0;
                    margin: 4px 0;
                `;
                menu.appendChild(separator);
            }
        }
        
        // Opción: Abrir en WorkSpace (solo si tiene permiso)
        if (this.hasWorkspacePermission) {
            const workspaceOption = document.createElement('div');
            workspaceOption.className = 'context-menu-item';
            workspaceOption.style.cssText = `
                padding: 8px 16px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
            `;
            workspaceOption.innerHTML = `
                <i class="fas fa-th-large"></i>
                <span>Abrir en WorkSpace</span>
            `;
            workspaceOption.addEventListener('click', () => {
                this.openInWorkspace(study);
                menu.remove();
            });
            workspaceOption.addEventListener('mouseenter', () => {
                workspaceOption.style.backgroundColor = '#f0f0f0';
            });
            workspaceOption.addEventListener('mouseleave', () => {
                workspaceOption.style.backgroundColor = 'transparent';
            });
            
            menu.appendChild(workspaceOption);
        }
        
        // Si no hay opciones, no mostrar el menú
        if (menu.children.length === 0) {
            return;
        }
        
        // Agregar al DOM
        document.body.appendChild(menu);
        
        // Cerrar menú al hacer click fuera
        const closeMenu = (e) => {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            }
        };
        
        // Cerrar después de un pequeño delay para permitir el click en la opción
        setTimeout(() => {
            document.addEventListener('click', closeMenu);
        }, 100);
        
        // Ajustar posición si el menú se sale de la pantalla
        setTimeout(() => {
            const rect = menu.getBoundingClientRect();
            if (rect.right > window.innerWidth) {
                menu.style.left = `${event.clientX - rect.width}px`;
            }
            if (rect.bottom > window.innerHeight) {
                menu.style.top = `${event.clientY - rect.height}px`;
            }
        }, 0);
    }
    
    /**
     * Abre el WorkSpace con los datos del estudio seleccionado
     * Navega a la sección workspace en la misma pestaña actual
     */
    openInWorkspace(study) {
        try {
            // Construir/actualizar cola de lectura antes de navegar
            this.buildStudyQueue(study);

            // Resolver modality desde sessionStorage si el objeto study no la trae todavía
            // (ocurre cuando loadMissingModalities la cargó después de renderizar la fila)
            if (!study.modality) {
                const studyKey = study.id || study.orthanc_study_id;
                if (studyKey) {
                    const cachedModality = sessionStorage.getItem(`study_modality_${studyKey}`);
                    if (cachedModality && cachedModality !== 'N/A') {
                        study.modality = cachedModality;
                        console.log(`🔧 Modality para estudio ${studyKey} obtenida de sessionStorage para workspace: ${cachedModality}`);
                    }
                }
            }

            // ── Multi-monitor: si workspace está en un popup (otro monitor), navegar allá ──
            // Verificamos ANTES que cualquier otra lógica. Si lo maneja el popup, salimos.
            if (window.MultiMonitorManager) {
                const params = new URLSearchParams();
                params.set('studyId', study.id || '');
                if (study.study_instance_uid) params.set('studyInstanceUID', study.study_instance_uid);
                if (study.orthanc_study_id)   params.set('orthancStudyId', study.orthanc_study_id);
                if (study.patient_id)         params.set('patientId', study.patient_id);
                if (study.patient_name)       params.set('patientName', study.patient_name);
                if (study.modality)           params.set('modality', study.modality);
                if (study.study_description)  params.set('studyDescription', study.study_description);
                // Usar URL absoluta para evitar resolución relativa incorrecta cuando
                // el popup ya está en /components/ (daría /components/components/workspace.html)
                const wsUrl = new URL('components/workspace.html?' + params.toString(), window.location.href).href;
                if (window.MultiMonitorManager.loadStudyInPopup(wsUrl)) {
                    console.log('🖥️ Estudio enviado al popup workspace (multi-monitor):', wsUrl);
                    return;   // manejado por el popup
                }
            }

            // Verificar si este estudio ya está abierto en workspace
            const currentWorkspaceData = sessionStorage.getItem('workspace_study_data');
            let isAlreadyOpen = false;
            
            if (currentWorkspaceData) {
                try {
                    const currentData = JSON.parse(currentWorkspaceData);
                    // Comparar por studyId, studyInstanceUID o orthancStudyId
                    const currentStudyId = currentData.studyId || currentData.studyInstanceUID || currentData.orthancStudyId;
                    const newStudyId = study.id || study.study_instance_uid || study.orthanc_study_id;
                    
                    if (currentStudyId && newStudyId && currentStudyId === newStudyId) {
                        isAlreadyOpen = true;
                        console.log('✅ Estudio ya está abierto en workspace, navegando sin recargar:', {
                            studyId: study.id,
                            studyInstanceUID: study.study_instance_uid
                        });
                    }
                } catch (e) {
                    console.warn('Error verificando estudio en workspace:', e);
                }
            }
            
            // Si el estudio ya está abierto, solo navegar al workspace sin recargar
            if (isAlreadyOpen) {
                // Solo navegar al workspace sin pasar parámetros nuevos (evita recargar el visor)
                const workspaceUrl = 'components/workspace.html';
                
                // Intentar usar AppContainer si está disponible (evita recarga completa de página)
                if (window.parent && window.parent !== window.self && window.parent.AppContainer) {
                    console.log('✅ Navegando a workspace existente (sin recargar) usando AppContainer del padre');
                    
                    // Construir URL completa con todos los parámetros del estudio
                    const params = new URLSearchParams();
                    params.set('studyId', study.id || '');
                    if (study.study_instance_uid) params.set('studyInstanceUID', study.study_instance_uid);
                    if (study.orthanc_study_id) params.set('orthancStudyId', study.orthanc_study_id);
                    if (study.patient_id) params.set('patientId', study.patient_id);
                    if (study.patient_name) params.set('patientName', study.patient_name);
                    if (study.modality) params.set('modality', study.modality);
                    if (study.study_description) params.set('studyDescription', study.study_description);
                    
                    const workspaceUrl = `components/workspace.html?${params.toString()}`;
                    
                    // Usar loadWorkspace con la URL completa para asegurar que todos los parámetros se pasen
                    window.parent.AppContainer.loadWorkspace(workspaceUrl);
                    
                    // Actualizar URL sin recargar
                    const containerParams = new URLSearchParams();
                    containerParams.set('section', 'workspace');
                    params.forEach((value, key) => containerParams.set(key, value));
                    const containerUrl = `app-container.html?${containerParams.toString()}`;
                    window.parent.history.pushState({ section: 'workspace' }, '', containerUrl);
                } else if (window.AppContainer) {
                    // Si estamos en app-container.html directamente
                    console.log('✅ Navegando a workspace existente (sin recargar) usando AppContainer local');
                    
                    // Construir URL completa con todos los parámetros del estudio
                    const params = new URLSearchParams();
                    params.set('studyId', study.id || '');
                    if (study.study_instance_uid) params.set('studyInstanceUID', study.study_instance_uid);
                    if (study.orthanc_study_id) params.set('orthancStudyId', study.orthanc_study_id);
                    if (study.patient_id) params.set('patientId', study.patient_id);
                    if (study.patient_name) params.set('patientName', study.patient_name);
                    if (study.modality) params.set('modality', study.modality);
                    if (study.study_description) params.set('studyDescription', study.study_description);
                    
                    const workspaceUrl = `components/workspace.html?${params.toString()}`;
                    
                    // Usar loadWorkspace con la URL completa
                    window.AppContainer.loadWorkspace(workspaceUrl);
                    
                    // Actualizar URL sin recargar
                    const containerUrl = 'app-container.html?section=workspace';
                    window.history.pushState({ section: 'workspace' }, '', containerUrl);
                } else {
                    // Fallback: navegar sin parámetros para evitar recargar
                    console.log('⚠️ AppContainer no disponible, navegando sin parámetros para evitar recargar');
                    const containerUrl = 'app-container.html?section=workspace';
                    window.location.href = containerUrl;
                }
                
                return; // Salir temprano para evitar recargar
            }
            
            // Si el estudio NO está abierto, proceder con el flujo normal (cargar nuevo estudio)
            // Construir URL del WorkSpace con parámetros del estudio
            const params = new URLSearchParams();
            params.set('studyId', study.id || '');
            if (study.study_instance_uid) {
                params.set('studyInstanceUID', study.study_instance_uid);
            }
            if (study.orthanc_study_id) {
                params.set('orthancStudyId', study.orthanc_study_id);
            }
            if (study.patient_id) {
                params.set('patientId', study.patient_id);
            }
            if (study.patient_name) {
                params.set('patientName', study.patient_name);
            }
            if (study.modality) {
                params.set('modality', study.modality);
            }
            if (study.study_description) {
                params.set('studyDescription', study.study_description);
            }
            
            // Guardar datos del estudio en sessionStorage ANTES de navegar
            // Esto asegura que el indicador se actualice inmediatamente
            const studyData = {
                studyId: study.id || null,
                studyInstanceUID: study.study_instance_uid || null,
                orthancStudyId: study.orthanc_study_id || null,
                patientId: study.patient_id || null,
                patientName: study.patient_name || null,
                modality: study.modality || null,
                studyDescription: study.study_description || null
            };
            sessionStorage.setItem('workspace_study_data', JSON.stringify(studyData));
            console.log('💾 Datos del estudio guardados en sessionStorage antes de abrir workspace');
            
            // Construir URL del workspace
            const workspaceUrl = `components/workspace.html?${params.toString()}`;
            
            console.log('🔗 Abriendo WorkSpace con estudio NUEVO:', {
                studyId: study.id,
                studyInstanceUID: study.study_instance_uid,
                url: workspaceUrl
            });
            
            // Actualizar indicadores INMEDIATAMENTE antes de navegar
            setTimeout(() => {
                this.updateWorkspaceIndicators();
                // También re-renderizar para asegurar que el estudio aparezca primero
                this.renderStudies();
            }, 50);
            
            // Intentar usar AppContainer si está disponible (evita recarga completa de página)
            if (window.parent && window.parent !== window.self && window.parent.AppContainer) {
                console.log('✅ Usando AppContainer del padre para navegar a workspace');
                window.parent.AppContainer.loadWorkspace(workspaceUrl);
                
                // Actualizar URL sin recargar
                const containerUrl = `app-container.html?section=workspace&${params.toString()}`;
                window.parent.history.pushState({ section: 'workspace' }, '', containerUrl);
            } else if (window.AppContainer) {
                // Si estamos en app-container.html directamente
                console.log('✅ Usando AppContainer local para navegar a workspace');
                window.AppContainer.loadWorkspace(workspaceUrl);
                
                // Actualizar URL sin recargar
                const containerUrl = `app-container.html?section=workspace&${params.toString()}`;
                window.history.pushState({ section: 'workspace' }, '', containerUrl);
            } else {
                // Fallback: recargar página completa (solo si no hay AppContainer disponible)
                console.log('⚠️ AppContainer no disponible, usando recarga completa de página');
                const containerUrl = `app-container.html?section=workspace&${params.toString()}`;
                window.location.href = containerUrl;
            }
        } catch (error) {
            console.error('Error navegando a WorkSpace:', error);
            alert('Error al abrir el WorkSpace. Por favor, intente nuevamente.');
        }
    }
}

// Instancia global
let dashboardWithPermissions;

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded - Pathname:', window.location.pathname);
    // Solo inicializar si estamos en el dashboard
    if (window.location.pathname.includes('dashboard-unified.html')) {
        console.log('Inicializando dashboard con permisos...');
        
        // Función para verificar si el global state manager está listo
        function waitForGlobalStateManager() {
            if (window.globalStateManager) {
                console.log('Global state manager disponible, inicializando dashboard...');
                dashboardWithPermissions = new DashboardWithPermissions();
                window.dashboardWithPermissions = dashboardWithPermissions; // Exponer globalmente
                dashboardWithPermissions.init();
            } else {
                console.log('Esperando global state manager...');
                setTimeout(waitForGlobalStateManager, 50);
            }
        }
        
        // Esperar un poco para que el contenido dinámico se cargue
        setTimeout(waitForGlobalStateManager, 100);
    }
});

// La instancia se expone dentro de waitForGlobalStateManager como window.dashboardWithPermissions

// Función global para búsqueda (mantener compatibilidad)
function searchStudies() {
    if (dashboardWithPermissions) {
        dashboardWithPermissions.applyFilters();
    }
}
