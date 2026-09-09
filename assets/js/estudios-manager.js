/**
 * Gestor de Derivaciones de Estudios
 * Maneja la asignación de estudios del PACS a usuarios del sistema
 */

/**
 * Prioridades altas (urgente / promesa / pendiente).
 * En estudios-manager la prioridad alta bypasea siempre los filtros locales de la grilla
 * (fechas, modalidad, etc.), aunque el estudio ya tenga informe — hace falta para gestionar
 * derivaciones. En dashboard-unified la regla es distinta: allí el bypass de prioridad alta
 * solo aplica mientras no hay informe (ver dashboard-with-permissions.js).
 */
function emPrioridadAlta(prioridad) {
    return prioridad === 'urgente' || prioridad === 'promesa' || prioridad === 'pendiente';
}

/**
 * Devuelve el estado de informe normalizado de un estudio.
 * Estados válidos: 'none' | 'informed_pending_pacs' | 'published_pacs'.
 * Espejo simplificado de DashboardWithPermissions.getStudyReportStatus()
 * para usarse desde estudios-manager (gestión de derivaciones).
 */
function emGetStudyReportStatus(study) {
    if (!study) return 'none';
    const explicit = (study.report_status || '').trim();
    if (explicit === 'none' || explicit === 'informed_pending_pacs' || explicit === 'published_pacs') {
        return explicit;
    }
    const ri = study.report_info || {};
    const total = Number(ri.total_informes || 0);
    const hasReport = Boolean(study.has_report) || Boolean(ri.has_report) || total > 0;
    if (!hasReport) return 'none';
    return Boolean(ri.published_to_pacs) ? 'published_pacs' : 'informed_pending_pacs';
}

class DerivacionesManager {
    constructor() {
        this.apiBaseUrl = 'api/';
        this.externalServerUrl = 'https://losalisos.tanjousoft.com.ar'; // URL externa para desarrollo
        this.studies = []; // Caché de todos los estudios del servidor
        this.filteredStudies = [];
        this.users = []; // Lista de usuarios del sistema
        this.selectedUsers = []; // Usuarios seleccionados para asignación
        this.selectedStudy = null; // Estudio seleccionado para asignar
        this.studyAssignments = {}; // Asignaciones de estudios agrupadas por study_id
        this.studySubassignments = {}; // Subasignaciones agrupadas por study_id
        this.currentAntecedents = null; // Antecedentes del estudio actual
        this.antecedentsSavedForCopy = false; // Botón copiar solo tras Guardar en esta sesión del modal
        this.cameraStream = null; // Stream de cámara
        this.capturedImages = []; // Imágenes capturadas con cámara
        this.emergencyCapturedImages = []; // Imágenes capturadas en modal de emergencia
        this.imageTransformations = {
            flipHorizontal: false,
            flipVertical: false,
            rotation: 0
        };
        this.mobileSessionId = null; // ID de sesión móvil activa
        this.sessionRenewalTimer = null; // Timer para renovar sesión
        this.isDataLoaded = false;
        this.storageKey = 'derivaciones_manager_state';
        this.hasPacsQuery = false; // Flag para control de permisos PACS QUERY
        this.hasAsignaciones = false; // Flag para control de permisos de Asignaciones
        this.hasDerivaciones = false; // Flag para control de permisos de Derivaciones
        this.hasAntecedentes = false; // Flag para control de permisos de Antecedentes (acceso funcional)
        this.hasGuiAntecedentes = false; // Flag para control de visibilidad del botón de Antecedentes (interfaz)
        // Permisos específicos por sección de antecedentes
        this.hasAntecedentesNotas = false; // Permiso para sección de Notas y Texto
        this.hasAntecedentesImagenes = false; // Permiso para sección de Imágenes
        this.hasAntecedentesCamara = false; // Permiso para sección de Cámara
        this.hasAntecedentesArchivos = false; // Permiso para sección de Archivos
        this.hasAntecedentesQrMovil = false; // Permiso para sección de QR Móvil
        this.currentUserId = null; // ID del usuario actual
        this.currentUserLevel = null; // Nivel del usuario actual (root/admin/user)
        this.hasFilterInstitutionsPermission = false; // Permiso para filtrar por instituciones
        this.allowedInstitutions = []; // Instituciones permitidas para el usuario
        this.studyPriorities = {}; // Prioridades: normal | promesa | pendiente | urgente
        this.priorityRefreshInterval = null; // Polling de prioridades visibles (sync entre usuarios)
        this._studyStatusListenersBound = false;
        this.canAsignarPrioridad = false; // Flag para control de permiso de Asignar Prioridad
        /** Búsqueda mixta local + C-FIND remoto (permiso estudios_mixed_search + pacs_query). */
        this.hasEstudiosMixedSearch = false;
        this.mixedRemoteNodes = [];
        this.mixedSearchEnabled = false;
        this.mixedSearchRemoteNodeId = '';
        this.mixedSearchPacsNodesLoadError = '';
        
        this.currentFilters = {
            search: '',
            dateFrom: '',
            dateTo: '',
            patientId: '',
            modalities: [],
            institutionName: '',
            priority: '', // Filtro de prioridad
            assignmentScope: '', // '', unassigned, linked, assigned_only, derived_only, both
            assignmentUserId: '', // usuario asignado o derivado a
            reportStatus: '' // '', informed, not_informed
        };
        
        // Configuración de ordenamiento
        this.sortConfig = {
            column: null, // Columna actual de ordenamiento
            direction: 'desc' // Dirección: 'asc' o 'desc'
        };
        
        // Configuración de paginación
        this.pagination = {
            currentPage: 1,
            perPage: 25, // Estudios por página
            totalPages: 1,
            totalItems: 0
        };

        /** Evita varias hidrataciones de modalidad en paralelo (p. ej. muchos renderStudies). */
        this._loadMissingModalitiesInFlight = false;
        this._pageHideSaveBound = false;
    }
    
    /**
     * Inicializa el gestor de derivaciones
     */
    async init() {
        try {
            this.setupEventListeners();
        this.setupMobileUploadListener();
            this.setupStudyStatusChangeListener();
            this.setupQaStatusChangeListener();
            
            // Verificar permisos del usuario actual PRIMERO
            await this.checkUserPermissions();
            await this.loadMixedSearchPacsNodes();
            this.refreshMixedSearchControls();
            
            // Aplicar permisos al modal estático después de verificar permisos
            setTimeout(() => {
                this.applyAntecedentsPermissionsToModal();
            }, 300);
            
            // Configurar ordenamiento por columnas
            this.setupColumnSorting();
            
            // Cargar usuarios del sistema (ya filtra según permisos)
            await this.loadUsers();
            
            // Cargar asignaciones de estudios
            await this.loadStudyAssignments();
            
            // Cargar subasignaciones de estudios
            await this.loadStudySubassignments();
            
            // Actualizar estadísticas de asignación
            await this.updateAssignmentStats();
            
            // Cargar estudios urgentes/prometidos al inicio SIEMPRE (FRESH, sin caché)
            // Esto asegura que al abrir estudios-manager se vean los estudios prioritarios actualizados
            console.log('🚨 Cargando estudios urgentes al inicio...');
            const urgentStudies = await this.loadUrgentStudies();
            console.log(`✅ Estudios urgentes cargados: ${urgentStudies.length}`);
            
            // Intentar cargar estado persistente
            const persistentState = this.loadPersistentState();
            if (persistentState && persistentState.studies && persistentState.studies.length > 0) {
                // Combinar: urgentes (fresh) + normales (caché)
                const normalStudies = persistentState.studies;
                this.studies = this.mergeStudiesWithPriorities(urgentStudies, normalStudies);
                this.currentFilters = { ...this.currentFilters, ...persistentState.filters };
                this.normalizeModalityFilterChips();
                this.isDataLoaded = true;
                
                console.log(`📦 Estado restaurado: ${urgentStudies.length} urgentes (fresh) + ${normalStudies.length} normales (caché) = ${this.studies.length} total`);
                
                // Restaurar configuración de paginación si existe
                if (persistentState.pagination) {
                    this.pagination = { ...this.pagination, ...persistentState.pagination };
                }
                
                // Restaurar configuración de ordenamiento si existe
                if (persistentState.sortConfig) {
                    this.sortConfig = { ...this.sortConfig, ...persistentState.sortConfig };
                    console.log(`📋 Ordenamiento restaurado: columna=${this.sortConfig.column}, dirección=${this.sortConfig.direction}`);
                }
                
                // Restaurar selector de elementos por página
                const perPageSelect = document.getElementById('perPageSelect');
                if (perPageSelect && this.pagination.perPage) {
                    perPageSelect.value = this.pagination.perPage;
                }
                
                this.restoreFormValues();

                this.updateAvailableModalities();
                
                // Cargar prioridades antes de aplicar filtros para asegurar ordenamiento correcto
                await this.loadStudyPriorities();
                
                // Ordenar estudios por prioridad después de cargar las prioridades
                this.studies = this.sortStudiesByPriority([...this.studies]);
                
                await this.refreshQaStudyStatuses();
                
                // Actualizar contador de urgentes en el header
                await this.updateUrgentCounter();
                
                this.applyLocalFilters();
                
                // Actualizar el filtro de institución con los estudios restaurados
                this.updateInstitutionFilter();
                
                // Cargar estado de antecedentes después de restaurar estudios
                await this.loadAntecedentsStatus();
                
                // Actualizar iconos de ordenamiento
                this.updateSortIcons();
                
                // Las selecciones se restaurarán automáticamente en renderStudies() después de renderizar
                
                this.updateCacheStatus('Datos restaurados desde caché local', 'text-success');
            } else {
                // Si no hay caché, mostrar solo estudios urgentes
                if (urgentStudies.length > 0) {
                    this.studies = urgentStudies;
                    this.isDataLoaded = true;

                    this.updateAvailableModalities();
                    
                    // Cargar prioridades antes de aplicar filtros
                    await this.loadStudyPriorities();
                    
                    // Ordenar estudios por prioridad después de cargar las prioridades
                    this.studies = this.sortStudiesByPriority([...this.studies]);
                    
                    await this.refreshQaStudyStatuses();
                    
                    this.applyLocalFilters();
                    this.updateInstitutionFilter();
                    await this.loadAntecedentsStatus();
                    this.updateCacheStatus(`${urgentStudies.length} estudios urgentes/prometidos cargados`, 'text-info');
                    console.log(`✅ Mostrando ${urgentStudies.length} estudios urgentes sin caché`);
                } else {
                    this.renderEmptyState();
                    this.updateCacheStatus('Listo para buscar - presiona "Buscar Estudios" para consultar PACS', 'text-info');
                }
            }

            // Sincronizar prioridades entre pestañas/usuarios (eventos + polling de respaldo)
            this.startPriorityAutoRefresh();
            
        } catch (error) {
            console.error('Error inicializando gestor de derivaciones:', error);
            this.showError('Error inicializando gestor de derivaciones');
        }
    }
    
    /**
     * Verificar permisos del usuario actual
     */
    async checkUserPermissions() {
        try {
            // Usar cookie de sesión
            const response = await fetch('api/auth/validate-session-simple.php', {
                credentials: 'include'
            });
            const result = await response.json();
            
            console.log('Resultado de validate-session-simple:', result);
            
            // La API puede devolver result.data o result.user
            const userData = result.data || result.user;
            
            if (result.success && userData) {
                this.currentUserId = userData.id;
                this.currentUserLevel = userData.nivel || 'user'; // Capturar nivel del usuario
                
                // Verificar si permisos es string o array
                let permisos = userData.permisos || [];
                if (typeof permisos === 'string') {
                    try {
                        permisos = JSON.parse(permisos);
                    } catch (e) {
                        permisos = [permisos];
                    }
                }
                const permHas = (key) => {
                    if (!Array.isArray(permisos) || !key) {
                        return false;
                    }
                    const k = String(key).toLowerCase();
                    return permisos.some((p) => String(p).toLowerCase() === k);
                };
                
                // Usuarios root tienen acceso automático al PACS
                if (this.currentUserLevel === 'root') {
                    this.hasPacsQuery = true;
                    console.log('Usuario ROOT detectado - hasPacsQuery = true (acceso automático)');
                } else {
                    this.hasPacsQuery = permHas('pacs_query') || permHas('all');
                }
                this.hasEstudiosMixedSearch = this.currentUserLevel === 'root'
                    || permHas('all')
                    || permHas('estudios_mixed_search');
                this.hasAsignaciones = permHas('asignaciones') || permHas('all');
                this.hasDerivaciones = permHas('derivaciones') || permHas('all');
                this.hasAntecedentes = permHas('antecedentes') || permHas('all');
                this.hasGuiAntecedentes = permHas('gui_antecedentes') || permHas('all');
                this.hasFilterInstitutionsPermission = permHas('filter_institutions') || permHas('all');
                this.canAsignarPrioridad = permHas('asignar_prioridad') || permHas('all');
                
                // Obtener instituciones permitidas si el usuario tiene el permiso
                if (this.hasFilterInstitutionsPermission && this.currentUserId) {
                    try {
                        const instResponse = await fetch(`api/users/get-institutions.php?user_id=${this.currentUserId}`);
                        const instResult = await instResponse.json();
                        if (instResult.success && instResult.instituciones) {
                            this.allowedInstitutions = instResult.instituciones.map(inst => inst.trim().toUpperCase());
                            console.log('🏥 Instituciones permitidas:', this.allowedInstitutions);
                        }
                    } catch (e) {
                        console.warn('Error obteniendo instituciones permitidas:', e);
                    }
                }
                
                // Permisos específicos por sección de antecedentes
                // Lógica: Los permisos específicos configurados en user-management tienen prioridad
                const hasAll = permHas('all');
                const hasAntecedentesGeneral = permHas('antecedentes');
                const hasSpecificPermissions = permisos.some(p => String(p).toLowerCase().startsWith('antecedentes_'));
                
                if (hasAll) {
                    // Si tiene 'all', tiene acceso a todo
                    this.hasAntecedentesNotas = true;
                    this.hasAntecedentesImagenes = true;
                    this.hasAntecedentesCamara = true;
                    this.hasAntecedentesArchivos = true;
                    this.hasAntecedentesQrMovil = true;
                } else if (hasSpecificPermissions) {
                    // Si tiene permisos específicos configurados, usar solo esos (user-management tiene control granular)
                    // Esto permite que user-management controle exactamente qué pestañas se muestran
                    this.hasAntecedentesNotas = permHas('antecedentes_notas');
                    this.hasAntecedentesImagenes = permHas('antecedentes_imagenes');
                    this.hasAntecedentesCamara = permHas('antecedentes_camara');
                    this.hasAntecedentesArchivos = permHas('antecedentes_archivos');
                    this.hasAntecedentesQrMovil = permHas('antecedentes_qr_movil');
                } else if (hasAntecedentesGeneral) {
                    // Si tiene 'antecedentes' pero NO tiene permisos específicos, acceso a todo (comportamiento legacy)
                    this.hasAntecedentesNotas = true;
                    this.hasAntecedentesImagenes = true;
                    this.hasAntecedentesCamara = true;
                    this.hasAntecedentesArchivos = true;
                    this.hasAntecedentesQrMovil = true;
                } else {
                    // No tiene permisos de antecedentes
                    this.hasAntecedentesNotas = false;
                    this.hasAntecedentesImagenes = false;
                    this.hasAntecedentesCamara = false;
                    this.hasAntecedentesArchivos = false;
                    this.hasAntecedentesQrMovil = false;
                }
                
                console.log('Permisos del usuario verificados:', {
                    id: this.currentUserId,
                    nivel: this.currentUserLevel,
                    permisos: permisos,
                    hasPacsQuery: this.hasPacsQuery,
                    hasAsignaciones: this.hasAsignaciones,
                    hasDerivaciones: this.hasDerivaciones,
                    hasAntecedentes: this.hasAntecedentes,
                    hasGuiAntecedentes: this.hasGuiAntecedentes,
                    hasAntecedentesNotas: this.hasAntecedentesNotas,
                    hasAntecedentesImagenes: this.hasAntecedentesImagenes,
                    hasAntecedentesCamara: this.hasAntecedentesCamara,
                    hasAntecedentesArchivos: this.hasAntecedentesArchivos,
                    hasAntecedentesQrMovil: this.hasAntecedentesQrMovil,
                    canAsignarPrioridad: this.canAsignarPrioridad,
                    hasEstudiosMixedSearch: this.hasEstudiosMixedSearch
                });
                
                // Log detallado de la lógica de permisos de antecedentes
                // (usando las variables ya declaradas arriba)
                console.log('🔍 Lógica de permisos de antecedentes:', {
                    hasAll: hasAll,
                    hasAntecedentesGeneral: hasAntecedentesGeneral,
                    hasSpecificPermissions: hasSpecificPermissions,
                    permisosEspecificos: permisos.filter(p => String(p).toLowerCase().startsWith('antecedentes_'))
                });
            } else {
                console.warn('No se pudieron obtener permisos del usuario:', result);
                this.hasPacsQuery = false;
                this.hasAsignaciones = false;
                this.hasDerivaciones = false;
                this.hasAntecedentes = false;
                this.hasGuiAntecedentes = false;
                this.hasAntecedentesNotas = false;
                this.hasAntecedentesImagenes = false;
                this.hasAntecedentesCamara = false;
                this.hasAntecedentesArchivos = false;
                this.hasAntecedentesQrMovil = false;
                this.hasEstudiosMixedSearch = false;
                this.currentUserLevel = 'user';
                this.currentUserId = null;
            }
        } catch (error) {
            console.error('Error verificando permisos:', error);
            this.hasPacsQuery = false;
            this.hasAntecedentes = false;
            this.hasGuiAntecedentes = false;
            this.hasAntecedentesNotas = false;
            this.hasAntecedentesImagenes = false;
            this.hasAntecedentesCamara = false;
            this.hasAntecedentesArchivos = false;
            this.hasAntecedentesQrMovil = false;
            this.hasEstudiosMixedSearch = false;
            this.currentUserId = null;
        }
        
        // Log final para verificar
        console.log('🔍 checkUserPermissions completado. currentUserId:', this.currentUserId);
    }
    
    async loadMixedSearchPacsNodes() {
        this.mixedRemoteNodes = [];
        this.mixedSearchPacsNodesLoadError = '';
        if (!this.hasEstudiosMixedSearch || !this.hasPacsQuery) {
            return;
        }
        try {
            const response = await fetch(this.apiBaseUrl + 'estudios_pacs_nodes.php', { credentials: 'include' });
            const result = await response.json().catch(() => ({}));
            if (result.success && Array.isArray(result.data)) {
                this.mixedRemoteNodes = result.data;
            } else {
                this.mixedSearchPacsNodesLoadError = (result && result.error)
                    ? String(result.error)
                    : `No se pudieron listar nodos (HTTP ${response.status})`;
            }
        } catch (e) {
            console.warn('No se pudieron cargar nodos PACS remotos:', e);
            this.mixedSearchPacsNodesLoadError = e.message || 'Error de red al cargar nodos';
        }
    }
    
    refreshMixedSearchControls() {
        const row = document.getElementById('mixedSearchControlsRow');
        const sel = document.getElementById('mixedSearchRemoteNode');
        const toggle = document.getElementById('mixedSearchToggle');
        const hintNoNodes = document.getElementById('mixedSearchNoNodesHint');
        const hintNeedPacs = document.getElementById('mixedSearchNeedPacsHint');
        const hintApi = document.getElementById('mixedSearchApiHint');
        if (!row || !sel || !toggle) {
            return;
        }
        const hideHints = () => {
            if (hintNoNodes) {
                hintNoNodes.classList.add('d-none');
            }
            if (hintNeedPacs) {
                hintNeedPacs.classList.add('d-none');
            }
            if (hintApi) {
                hintApi.classList.add('d-none');
            }
        };
        if (!this.hasEstudiosMixedSearch) {
            row.style.display = 'none';
            hideHints();
            toggle.checked = false;
            sel.innerHTML = '<option value="">Seleccione nodo…</option>';
            sel.disabled = true;
            toggle.disabled = false;
            this.mixedSearchEnabled = false;
            this.mixedSearchRemoteNodeId = '';
            return;
        }
        row.style.display = '';
        if (!this.hasPacsQuery) {
            hideHints();
            if (hintNeedPacs) {
                hintNeedPacs.classList.remove('d-none');
            }
            toggle.checked = false;
            toggle.disabled = true;
            sel.innerHTML = '<option value="">Requiere permiso Consulta PACS</option>';
            sel.disabled = true;
            this.mixedSearchEnabled = false;
            this.mixedSearchRemoteNodeId = '';
            return;
        }
        if (hintNeedPacs) {
            hintNeedPacs.classList.add('d-none');
        }
        const err = (this.mixedSearchPacsNodesLoadError || '').trim();
        if (hintApi) {
            if (err) {
                hintApi.textContent = err;
                hintApi.classList.remove('d-none');
            } else {
                hintApi.classList.add('d-none');
            }
        }
        const noNodes = this.mixedRemoteNodes.length === 0;
        if (hintNoNodes) {
            if (!err && noNodes) {
                hintNoNodes.classList.remove('d-none');
            } else {
                hintNoNodes.classList.add('d-none');
            }
        }
        if (noNodes || err) {
            toggle.checked = false;
            toggle.disabled = !!err;
            sel.innerHTML = err
                ? '<option value="">—</option>'
                : '<option value="">Sin nodos remotos configurados</option>';
            sel.disabled = true;
            this.mixedSearchEnabled = false;
            this.mixedSearchRemoteNodeId = '';
            return;
        }
        toggle.disabled = false;
        const prev = sel.value;
        sel.innerHTML = '<option value="">Seleccione nodo…</option>';
        this.mixedRemoteNodes.forEach((n) => {
            const id = n.id != null ? String(n.id) : '';
            if (!id) {
                return;
            }
            const opt = document.createElement('option');
            opt.value = id;
            const label = (n.name && String(n.name).trim()) ? n.name : (n.aet ? `${n.aet} (#${id})` : `Nodo ${id}`);
            opt.textContent = label;
            sel.appendChild(opt);
        });
        if (prev && [...sel.options].some((o) => o.value === prev)) {
            sel.value = prev;
        }
        this.mixedSearchRemoteNodeId = sel.value || '';
        this.mixedSearchEnabled = toggle.checked && !!this.mixedSearchRemoteNodeId;
        sel.disabled = !toggle.checked;
    }
    
    /**
     * Dispara C-MOVE vía backend si el estudio está solo en remoto (evita job duplicado en servidor).
     */
    async triggerRemoteRetrieveIfNeeded(study) {
        if (!study || study.em_remote_only !== true) {
            return;
        }
        const suid = (study.study_instance_uid || '').trim();
        const nodeId = parseInt(String(study.em_remote_node_id || this.mixedSearchRemoteNodeId || '0'), 10);
        if (!suid || nodeId < 1) {
            return;
        }
        const response = await fetch(this.apiBaseUrl + 'estudios_trigger_retrieve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                node_id: nodeId,
                StudyInstanceUIDs: [suid],
            }),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || (!result.success && !result.skipped)) {
            throw new Error(result.error || `Retrieve remoto falló (${response.status})`);
        }
    }
    
    /**
     * Carga los usuarios del sistema
     */
    async loadUsers() {
        try {
            // Enviar cookies para que la API pueda verificar permisos
            const response = await fetch(this.apiBaseUrl + 'get_users.php', {
                credentials: 'include'
            });
            
            // Verificar si la respuesta es JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                // Si no es JSON, leer como texto para ver el error
                const text = await response.text();
                console.error('Respuesta no es JSON:', text.substring(0, 200));
                throw new Error('La API devolvió un formato incorrecto. Ver consola para detalles.');
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Error cargando usuarios');
            }
            
            this.users = result.data || [];
            
            // Verificar permisos desde la respuesta
            if (result.has_pacs_query !== undefined) {
                this.hasPacsQuery = result.has_pacs_query;
            }
            
            console.log('Usuarios cargados:', this.users.length);
            console.log('Usuario tiene PACS QUERY:', this.hasPacsQuery);
        } catch (error) {
            console.error('Error cargando usuarios:', error);
            console.error('Detalles del error:', error.message);
            this.showError('Error cargando usuarios del sistema: ' + error.message);
            // Mostrar usuarios de ejemplo para demostración
            this.users = [
                {
                    id: 1,
                    nombre: 'Juan',
                    apellido: 'Pérez',
                    email: 'juan.perez@hospital.com',
                    matricula_profesional: 'MP001'
                },
                {
                    id: 2,
                    nombre: 'María',
                    apellido: 'González',
                    email: 'maria.gonzalez@hospital.com',
                    matricula_profesional: 'MP002'
                },
                {
                    id: 3,
                    nombre: 'Carlos',
                    apellido: 'Rodríguez',
                    email: 'carlos.rodriguez@hospital.com',
                    matricula_profesional: 'MP003'
                }
            ];
            // Ya no renderizamos usuarios en la página principal
            // Solo los cargamos para usar en el modal de asignación
        }
    }
    
    /**
     * Renderiza la lista de usuarios
     */
    renderUsers() {
        const container = document.getElementById('usersContainer');
        if (!container) return;
        
        if (this.users.length === 0) {
            container.innerHTML = `
                <div class="col-12 text-center py-4">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No hay usuarios disponibles</h5>
                    <p class="text-muted">No se encontraron usuarios en el sistema</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        this.users.forEach(user => {
            const initials = (user.nombre.charAt(0) + user.apellido.charAt(0)).toUpperCase();
            const fullName = `${user.nombre} ${user.apellido}`;
            
            html += `
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="user-card" data-user-id="${user.id}">
                        <div class="user-info">
                            <div class="user-avatar">
                                ${initials}
                            </div>
                            <div class="user-details">
                                <h6>${fullName}</h6>
                                <small class="text-muted">${user.email}</small><br>
                                <small class="text-muted">MP: ${user.matricula_profesional}</small>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        
        // Agregar event listeners a las tarjetas de usuario
        container.querySelectorAll('.user-card').forEach(card => {
            card.addEventListener('click', () => {
                const userId = parseInt(card.dataset.userId);
                this.toggleUserSelection(userId, card);
            });
        });
    }
    
    /**
     * Alterna la selección de un usuario
     */
    toggleUserSelection(userId, cardElement) {
        const index = this.selectedUsers.indexOf(userId);
        
        if (index > -1) {
            // Deseleccionar usuario
            this.selectedUsers.splice(index, 1);
            cardElement.classList.remove('selected');
        } else {
            // Seleccionar usuario
            this.selectedUsers.push(userId);
            cardElement.classList.add('selected');
        }
        
        console.log('Usuarios seleccionados:', this.selectedUsers);
    }
    
    /**
     * Configura los event listeners
     */
    setupEventListeners() {
        // Botón de búsqueda
        const searchButton = document.getElementById('searchButton');
        if (searchButton) {
            searchButton.addEventListener('click', () => {
                this.handleSearchClick();
            });
        }

        const mixedToggle = document.getElementById('mixedSearchToggle');
        const mixedSel = document.getElementById('mixedSearchRemoteNode');
        if (mixedToggle && mixedSel) {
            mixedToggle.addEventListener('change', () => {
                mixedSel.disabled = !mixedToggle.checked;
                if (!mixedToggle.checked) {
                    mixedSel.value = '';
                }
                this.mixedSearchEnabled = mixedToggle.checked && !!mixedSel.value;
                this.mixedSearchRemoteNodeId = mixedSel.value || '';
            });
            mixedSel.addEventListener('change', () => {
                this.mixedSearchRemoteNodeId = mixedSel.value || '';
                this.mixedSearchEnabled = mixedToggle.checked && !!this.mixedSearchRemoteNodeId;
            });
        }

        const dateFromEl = document.getElementById('dateFrom');
        if (dateFromEl) {
            dateFromEl.addEventListener('change', () => this.clearQuickDateButtonStates());
        }
        const dateToEl = document.getElementById('dateTo');
        if (dateToEl) {
            dateToEl.addEventListener('change', () => this.clearQuickDateButtonStates());
        }
        this.setupQuickDatePresetButtons();
        
        // Enter en campos de búsqueda
        const searchInputs = ['searchFilter', 'patientId'];
        searchInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (input) {
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        this.handleSearchClick();
                    }
                });
                
                // Agregar event listener para búsqueda en tiempo real
                if (inputId === 'searchFilter') {
                    input.addEventListener('input', () => {
                        this.updateFiltersFromForm();
                        this.applyLocalFilters();
                    });
                }
            }
        });
        
        // Filtro de institución - filtrado local
        const institutionFilter = document.getElementById('institutionFilter');
        if (institutionFilter) {
            institutionFilter.addEventListener('change', (e) => {
                this.currentFilters.institutionName = e.target.value;
                // Aplicar filtro local inmediatamente
                this.applyLocalFilters();
            });
        }
        
        // Filtro de prioridad - filtrado local
        const priorityFilter = document.getElementById('priorityFilter');
        if (priorityFilter) {
            priorityFilter.addEventListener('change', (e) => {
                this.currentFilters.priority = e.target.value;
                // Aplicar filtro local inmediatamente
                this.applyLocalFilters();
            });
        }

        const assignmentScopeFilter = document.getElementById('assignmentScopeFilter');
        if (assignmentScopeFilter) {
            assignmentScopeFilter.addEventListener('change', (e) => {
                this.currentFilters.assignmentScope = e.target.value;
                this.applyLocalFilters();
            });
        }

        const assignmentUserFilter = document.getElementById('assignmentUserFilter');
        if (assignmentUserFilter) {
            assignmentUserFilter.addEventListener('change', (e) => {
                this.currentFilters.assignmentUserId = e.target.value;
                this.applyLocalFilters();
            });
        }

        // Filtro por estado de informe (Informados / No Informados) - filtrado local
        const reportStatusFilter = document.getElementById('reportStatusFilter');
        if (reportStatusFilter) {
            reportStatusFilter.addEventListener('change', (e) => {
                this.currentFilters.reportStatus = e.target.value;
                this.applyLocalFilters();
            });
        }

        // Filtros de modalidad (closest: clic en el subíndice de cantidad sigue aplicando al botón)
        document.addEventListener('click', (e) => {
            const modalityBtn = e.target.closest('.modality-btn');
            if (!modalityBtn) {
                return;
            }
            e.preventDefault();
            const modality = modalityBtn.dataset.modality;

            if (modality === 'all') {
                document.querySelectorAll('.modality-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                modalityBtn.classList.add('active');
                this.currentFilters.modalities = [];
            } else {
                document.querySelector('.modality-btn[data-modality="all"]').classList.remove('active');
                modalityBtn.classList.toggle('active');

                const index = this.currentFilters.modalities.indexOf(modality);
                if (index > -1) {
                    this.currentFilters.modalities.splice(index, 1);
                } else {
                    this.currentFilters.modalities.push(modality);
                }

                if (this.currentFilters.modalities.length === 0) {
                    document.querySelector('.modality-btn[data-modality="all"]').classList.add('active');
                }
            }

            this.applyLocalFilters();
            this.updateModalityCounter();
        });
        
        // Modal de asignación
        const confirmAssignBtn = document.getElementById('confirmAssignBtn');
        if (confirmAssignBtn) {
            confirmAssignBtn.addEventListener('click', () => {
                this.confirmBulkAssignment();
            });
        }
        
        // Nuevos event listeners para selección múltiple
        const selectAllCheckbox = document.getElementById('selectAllStudies');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', () => this.handleSelectAll());
        }
        
        const assignSelectedBtn = document.getElementById('assignSelectedBtn');
        if (assignSelectedBtn) {
            assignSelectedBtn.addEventListener('click', () => this.openBulkAssignModal());
        }
        
        const clearSelectionBtn = document.getElementById('clearSelectionBtn');
        if (clearSelectionBtn) {
            clearSelectionBtn.addEventListener('click', () => this.clearSelection());
        }
        
        // Event listener para modal estático de antecedentes (si existe)
        const antecedentsModal = document.getElementById('antecedentsModal');
        if (antecedentsModal) {
            // Aplicar permisos inmediatamente al cargar la página (para el modal estático)
            setTimeout(() => {
                this.applyAntecedentsPermissionsToModal();
            }, 500);
            
            // Aplicar permisos cuando se muestra el modal
            antecedentsModal.addEventListener('show.bs.modal', () => {
                console.log('Modal estático de antecedentes abierto, aplicando permisos...');
                setTimeout(() => {
                    this.applyAntecedentsPermissionsToModal();
                }, 100);
            });
            
            // También aplicar cuando el modal está completamente visible
            antecedentsModal.addEventListener('shown.bs.modal', () => {
                console.log('Modal estático de antecedentes completamente visible, re-aplicando permisos...');
                setTimeout(() => {
                    this.applyAntecedentsPermissionsToModal();
                }, 200);
            });
        }

        if (!this._pageHideSaveBound) {
            this._pageHideSaveBound = true;
            window.addEventListener('pagehide', () => {
                try {
                    if (this.isDataLoaded && this.studies && this.studies.length > 0) {
                        this.savePersistentState();
                    }
                } catch (e) {
                    // ignorar
                }
            });
        }
    }
    
    /**
     * Configura el listener para recibir notificaciones de subida de imágenes móviles
     */
    setupMobileUploadListener() {
        window.addEventListener('message', (event) => {
            // Manejar imágenes subidas desde móvil
            if (event.data && event.data.type === 'mobile_images_uploaded') {
                console.log('Recibida notificación de subida móvil:', event.data);
                
                // Mostrar imágenes en pestaña QR Móvil para aceptar/rechazar
                if (this.currentAntecedents && this.currentAntecedents.id === event.data.studyId) {
                    console.log('Agregando imágenes a lista de pendientes...');
                    this.addPendingImages(event.data.images || []);
                }
            }
            
            // Manejar captura de imagen individual desde móvil
            if (event.data && event.data.type === 'mobile_image_captured') {
                console.log('=== NOTIFICACIÓN DE CAPTURA MÓVIL ===');
                console.log('Datos recibidos:', event.data);
                console.log('studyId del evento:', event.data.studyId);
                console.log('this.currentAntecedents:', this.currentAntecedents);
                console.log('this.currentAntecedents?.id:', this.currentAntecedents?.id);
                console.log('Imagen recibida:', event.data.image);
                console.log('¿Coincide studyId?:', this.currentAntecedents?.id === event.data.studyId);
                
                if (this.currentAntecedents && this.currentAntecedents.id === event.data.studyId) {
                    console.log('✅ StudyId coincide - Agregando imagen capturada a lista de pendientes...');
                    this.addPendingImages([event.data.image]);
                } else {
                    console.warn('❌ StudyId NO coincide o currentAntecedents es null');
                    console.warn('Esperado:', this.currentAntecedents?.id);
                    console.warn('Recibido:', event.data.studyId);
                }
                console.log('=== FIN NOTIFICACIÓN ===');
            }
        });
        
        console.log('Listener de subida móvil configurado');
    }
    
    /**
     * Estado de carga en el botón "Buscar Estudios" (spinner + deshabilitado).
     */
    setSearchButtonLoading(isLoading) {
        const btn = document.getElementById('searchButton');
        if (!btn) {
            return;
        }
        if (isLoading) {
            if (btn.getAttribute('aria-busy') === 'true') {
                return;
            }
            btn.dataset.searchNormalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Buscando...';
        } else {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            if (btn.dataset.searchNormalHtml) {
                btn.innerHTML = btn.dataset.searchNormalHtml;
                delete btn.dataset.searchNormalHtml;
            } else {
                btn.innerHTML = '<i class="fas fa-search me-2"></i>Buscar Estudios';
            }
        }
    }

    /**
     * Fecha local en YYYY-MM-DD (misma lógica que pacs-manager).
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
     * Atajos Hoy / Ayer / Últimos 7 días: rellena fechas y ejecuta la misma búsqueda que «Buscar Estudios».
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
     * Maneja el click del botón de búsqueda
     */
    async handleSearchClick() {
        try {
            console.log('🔍 [HANDLE_SEARCH_CLICK] Iniciando búsqueda...');
            this.updateFiltersFromForm();
            
            console.log('🔍 [HANDLE_SEARCH_CLICK] Filtros actuales:', {
                dateFrom: this.currentFilters.dateFrom,
                dateTo: this.currentFilters.dateTo,
                patientId: this.currentFilters.patientId
            });
            
            // Validar que haya al menos un filtro (fechas o ID de paciente)
            // PERO: Si hay estudios urgentes/prometidos, permitir búsqueda sin fechas
            const hasUrgentStudies = await this.checkForUrgentStudies();
            console.log('🔍 [HANDLE_SEARCH_CLICK] Tiene estudios urgentes:', hasUrgentStudies);
            
            if (!hasUrgentStudies && !this.currentFilters.dateFrom && !this.currentFilters.dateTo && !this.currentFilters.patientId) {
                console.warn('⚠️ [HANDLE_SEARCH_CLICK] No hay filtros ni estudios urgentes');
                this.showError('Por favor especifica al menos un rango de fechas o ID de paciente para la búsqueda');
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

            const mixedToggleEl = document.getElementById('mixedSearchToggle');
            if (mixedToggleEl && mixedToggleEl.checked && !this.mixedSearchRemoteNodeId) {
                this.showError('Búsqueda mixta activada: elige un nodo remoto o desmarca «Incluir PACS remoto».');
                return;
            }

            this.setSearchButtonLoading(true);
            try {
                this.updateCacheStatus('Buscando estudios en PACS...', 'text-warning');
                console.log('🔍 [HANDLE_SEARCH_CLICK] Cargando estudios...');
                
                await this.loadStudies();
                console.log('🔍 [HANDLE_SEARCH_CLICK] Estudios cargados:', this.studies.length);
                
                await this.loadStudyPriorities();
                console.log('🔍 [HANDLE_SEARCH_CLICK] Prioridades e incompletos cargados');
                
                this.applyLocalFilters();
                console.log('🔍 [HANDLE_SEARCH_CLICK] Filtros aplicados, estudios filtrados:', this.filteredStudies.length);
                
                await this.loadAntecedentsStatus();
                
                this.updateCacheStatus(`Búsqueda completada - ${this.studies.length} estudios encontrados`, 'text-success');
                console.log('✅ [HANDLE_SEARCH_CLICK] Búsqueda completada exitosamente');
            } finally {
                this.setSearchButtonLoading(false);
            }
            
        } catch (error) {
            console.error('❌ [HANDLE_SEARCH_CLICK] Error en búsqueda:', error);
            console.error('❌ [HANDLE_SEARCH_CLICK] Stack:', error.stack);
            this.showError('Error realizando búsqueda: ' + error.message);
            this.updateCacheStatus('Error en la búsqueda', 'text-danger');
        }
    }
    
    /**
     * Carga todos los estudios según permisos del usuario
     */
    async loadStudies() {
        try {
            this.clearPersistentState();
            
            // Si el usuario NO tiene PACS QUERY, cargar solo estudios asignados
            if (!this.hasPacsQuery) {
                console.log('Usuario sin PACS QUERY: Cargando solo estudios asignados...');
                
                const response = await fetch(this.apiBaseUrl + 'get_user_assigned_studies_fixed.php');
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Error cargando estudios asignados');
                }
                
                // Formatear datos para ser compatibles con el resto del código
                const normalStudies = result.data.studies || [];
                
                // Cargar estudios urgentes/prometidos
                const urgentStudies = await this.loadUrgentStudies();
                
                // Combinar: urgentes primero, luego normales (sin duplicados)
                this.studies = this.mergeStudiesWithPriorities(urgentStudies, normalStudies);
                
                console.log('Estudios asignados cargados:', {
                    urgentes: urgentStudies.length,
                    normales: normalStudies.length,
                    total: this.studies.length
                });
            } else {
                // Usuario CON PACS QUERY: Cargar todos los estudios del PACS
                console.log('Usuario con PACS QUERY: Cargando todos los estudios del PACS...');
            
            // Cargar estudios urgentes/prometidos PRIMERO (sin filtros de fecha)
            const urgentStudies = await this.loadUrgentStudies();
            console.log('🚨 Estudios urgentes cargados:', urgentStudies.length);
            
            // Cargar estudios normales con filtros de fecha (si se especificaron)
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
            if (this.mixedSearchEnabled && this.mixedSearchRemoteNodeId) {
                params.append('mixed_search', '1');
                params.append('remote_node_id', this.mixedSearchRemoteNodeId);
            }
            
            let normalStudies = [];
            
            // Solo cargar estudios normales si hay filtros de fecha o paciente
            // Si no hay filtros, no cargar estudios normales (solo urgentes)
            if (this.currentFilters.dateFrom || this.currentFilters.dateTo || this.currentFilters.patientId) {
                const url = this.apiBaseUrl + 'get_all_studies.php' + (params.toString() ? '?' + params.toString() : '');
                console.log('🔍 [ESTUDIOS-MANAGER] Cargando estudios normales desde:', url);
                console.log('🔍 [ESTUDIOS-MANAGER] Usuario nivel:', this.currentUserLevel, ', hasPacsQuery:', this.hasPacsQuery);
                
                const response = await fetch(url);
                const result = await response.json();
                
                console.log('🔍 [ESTUDIOS-MANAGER] Respuesta de API:', {
                    success: result.success,
                    total: result.total_studies || result.total || 0,
                    data_length: result.data ? result.data.length : 0,
                    debug: result.debug
                });
                
                if (!result.success) {
                    throw new Error(result.error || 'Error desconocido al cargar estudios');
                }
                
                normalStudies = result.data || [];
            } else {
                console.log('ℹ️ No hay filtros de fecha/paciente - solo se mostrarán estudios urgentes');
            }
            
            // Combinar: urgentes primero, luego normales (sin duplicados)
            this.studies = this.mergeStudiesWithPriorities(urgentStudies, normalStudies);
            
            console.log('✅ [ESTUDIOS-MANAGER] Estudios cargados:', {
                urgentes: urgentStudies.length,
                normales: normalStudies.length,
                total: this.studies.length
            });
            }
            
            this.isDataLoaded = true;
            
            // Cargar prioridades e informes incompletos de los estudios ANTES de renderizar
            // Esto asegura que los datos estén disponibles cuando se rendericen los botones
            await this.loadStudyPriorities();
            
            // Ordenar estudios por prioridad después de cargar las prioridades
            // Esto asegura que los estudios urgentes/promesas estén al principio
            this.studies = this.sortStudiesByPriority([...this.studies]);
            
            // Actualizar botones de modalidad basándose en los estudios cargados
            await this.updateAvailableModalities();
            
            // Actualizar el filtro de institución con los estudios cargados
            this.updateInstitutionFilter();
            
            this.savePersistentState();

            await this.refreshQaStudyStatuses();
            
            console.log('Estudios cargados:', this.studies.length);
        } catch (error) {
            console.error('Error cargando estudios:', error);
            throw error;
        }
    }

    /**
     * Carga estados QA (portal) para la grilla visible.
     */
    async refreshQaStudyStatuses() {
        if (typeof QaQuickAction === 'undefined' || !QaQuickAction.enabled || !Array.isArray(this.studies) || !this.studies.length) {
            return;
        }
        await QaQuickAction.refreshStudyStatuses(this.studies);
    }
    
    /**
     * Actualiza los filtros desde el formulario
     */
    updateFiltersFromForm() {
        const searchFilter = document.getElementById('searchFilter');
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const patientId = document.getElementById('patientId');
        const institutionFilter = document.getElementById('institutionFilter');
        const priorityFilter = document.getElementById('priorityFilter');
        const assignmentScopeFilter = document.getElementById('assignmentScopeFilter');
        const assignmentUserFilter = document.getElementById('assignmentUserFilter');
        const reportStatusFilter = document.getElementById('reportStatusFilter');

        this.currentFilters.search = searchFilter ? searchFilter.value.trim() : '';
        this.currentFilters.dateFrom = dateFrom ? dateFrom.value : '';
        this.currentFilters.dateTo = dateTo ? dateTo.value : '';
        this.currentFilters.patientId = patientId ? patientId.value.trim() : '';
        this.currentFilters.institutionName = institutionFilter ? institutionFilter.value : '';
        this.currentFilters.priority = priorityFilter ? priorityFilter.value : '';
        this.currentFilters.assignmentScope = assignmentScopeFilter ? assignmentScopeFilter.value : '';
        this.currentFilters.assignmentUserId = assignmentUserFilter ? assignmentUserFilter.value : '';
        this.currentFilters.reportStatus = reportStatusFilter ? reportStatusFilter.value : '';

        const mixedToggle = document.getElementById('mixedSearchToggle');
        const mixedSel = document.getElementById('mixedSearchRemoteNode');
        if (mixedToggle && mixedSel) {
            this.mixedSearchRemoteNodeId = mixedSel.value || '';
            this.mixedSearchEnabled = mixedToggle.checked && !!this.mixedSearchRemoteNodeId;
        }
    }
    
    /**
     * Tokens de modalidad de un estudio (mayúsculas, únicos) para contadores.
     */
    getStudyModalityTokens(study) {
        if (!study || !study.modality || String(study.modality).trim() === '') {
            return [];
        }
        const raw = String(study.modality);
        const parts = raw.split(/[,\\]+/).map(m => m.trim().toUpperCase()).filter(Boolean);
        return [...new Set(parts)];
    }

    /**
     * Coincide con un chip de modalidad. CR agrupa radiología (CR, RX, DX), alineado con pacs-manager.
     */
    studyMatchesSelectedModalityFilter(studyTokens, selectedModality) {
        const sel = String(selectedModality).toUpperCase();
        if (sel === 'CR') {
            const rad = ['CR', 'RX', 'DX'];
            return studyTokens.some(t => rad.includes(t));
        }
        return studyTokens.includes(sel);
    }

    /**
     * El filtro por chip RX se unificó en CR; migrar caché / estado guardado.
     */
    normalizeModalityFilterChips() {
        if (!Array.isArray(this.currentFilters.modalities)) {
            return;
        }
        this.currentFilters.modalities = [...new Set(
            this.currentFilters.modalities.map(m => (m === 'RX' ? 'CR' : m))
        )];
    }

    /**
     * Indica si un estudio cumple los filtros locales.
     * @param {object} study
     * @param {{ skipModalityFilter?: boolean }} options Si skipModalityFilter, no se aplica el filtro de modalidad (para contadores por modalidad).
     */
    studyPassesLocalFilters(study, options = {}) {
        const skipModalityFilter = options.skipModalityFilter === true;

        let studyPriority = this.studyPriorities[study.id];

        if (!studyPriority) {
            studyPriority = study.prioridad ||
                (study.informes_incompletos && study.informes_incompletos.prioridad);
        }

        if (!studyPriority || studyPriority === '') {
            studyPriority = 'normal';
        }

        const isIncomplete = study.is_incomplete ||
            (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
        const isHigh = emPrioridadAlta(studyPriority);
        // Siempre bypass para prioridad alta en esta vista (no condicionar a "sin informe";
        // eso es solo en dashboard-unified).
        const isPriority = isHigh || isIncomplete;

        if (!isPriority && this.currentFilters.dateFrom) {
            const studyDate = study.date || study.study_date || '';
            if (studyDate) {
                const studyDateFormatted = studyDate.replace(/-/g, '');
                const filterDateFormatted = this.currentFilters.dateFrom.replace(/-/g, '');
                if (studyDateFormatted < filterDateFormatted) {
                    return false;
                }
            }
        }

        if (!isPriority && this.currentFilters.dateTo) {
            const studyDate = study.date || study.study_date || '';
            if (studyDate) {
                const studyDateFormatted = studyDate.replace(/-/g, '');
                const filterDateFormatted = this.currentFilters.dateTo.replace(/-/g, '');
                if (studyDateFormatted > filterDateFormatted) {
                    return false;
                }
            }
        }

        if (this.currentFilters.search) {
            const searchTerm = this.currentFilters.search.toLowerCase();
            const searchableText = [
                study.patient_name,
                study.patient_id,
                study.modality,
                study.study_description
            ].join(' ').toLowerCase();

            if (!searchableText.includes(searchTerm)) {
                return false;
            }
        }

        if (!skipModalityFilter && !isPriority && this.currentFilters.modalities.length > 0) {
            const studyModalities = this.getStudyModalityTokens(study);
            const matchesModality = this.currentFilters.modalities.some(selectedModality =>
                this.studyMatchesSelectedModalityFilter(studyModalities, selectedModality)
            );

            if (!matchesModality) {
                return false;
            }
        }

        if (!isPriority && this.currentFilters.institutionName) {
            const studyInstitution = study.institution_name || '';
            if (studyInstitution !== this.currentFilters.institutionName) {
                return false;
            }
        }

        if (this.currentFilters.priority) {
            if (studyPriority !== this.currentFilters.priority) {
                return false;
            }
        }

        // Filtro por estado de informe (Informados / No Informados).
        // Igual que los otros filtros de esta vista, no se aplica a estudios con prioridad alta
        // ni a incompletos: en estudios-manager queremos que esos siempre se vean para gestionarlos.
        if (!isPriority && this.currentFilters.reportStatus) {
            const reportStatus = emGetStudyReportStatus(study);
            const hasInforme = reportStatus !== 'none';
            if (this.currentFilters.reportStatus === 'informed' && !hasInforme) {
                return false;
            }
            if (this.currentFilters.reportStatus === 'not_informed' && hasInforme) {
                return false;
            }
        }

        const studyIdKey = String(study.id);
        const assigns = this.getStudyAssignments(studyIdKey);
        const subs = this.getStudySubassignments(studyIdKey);
        const hasAssignment = assigns && assigns.length > 0;
        const hasDerived = subs && subs.length > 0;

        if (this.currentFilters.assignmentScope) {
            const scope = this.currentFilters.assignmentScope;
            let scopeOk = true;
            if (scope === 'unassigned') {
                scopeOk = !hasAssignment && !hasDerived;
            } else if (scope === 'linked') {
                scopeOk = hasAssignment || hasDerived;
            } else if (scope === 'assigned_only') {
                scopeOk = hasAssignment;
            } else if (scope === 'derived_only') {
                scopeOk = hasDerived;
            } else if (scope === 'both') {
                scopeOk = hasAssignment && hasDerived;
            }
            if (!scopeOk) {
                return false;
            }
        }

        if (this.currentFilters.assignmentUserId) {
            const uid = String(this.currentFilters.assignmentUserId);
            const inAssign = hasAssignment && assigns.some(a => String(a.user_id) === uid);
            const inDerive = hasDerived && subs.some(s => String(s.subassigned_to_user_id) === uid);
            if (!inAssign && !inDerive) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aplica filtros locales a los estudios
     * Los estudios con prioridad (urgente/promesa) siempre se muestran, incluso sin filtros de fecha
     */
    applyLocalFilters() {
        if (!this.isDataLoaded || this.studies.length === 0) {
            this.filteredStudies = [];
            this.updateAssignmentUserFilterOptions();
            this.updateAvailableModalities();
            this.renderStudies();
            return;
        }
        
        this.filteredStudies = this.studies.filter(study => this.studyPassesLocalFilters(study));
        
        // Ordenar estudios por prioridad (urgentes primero, luego promesas, luego normales)
        // IMPORTANTE: Esto asegura que los estudios con prioridad siempre estén al principio
        this.filteredStudies = this.sortStudiesByPriority([...this.filteredStudies]);
        
        // Log para debugging: mostrar cuántos estudios con prioridad hay
        const urgentCount = this.filteredStudies.filter(s => {
            const priority = this.studyPriorities[s.id] || s.prioridad || 'normal';
            return emPrioridadAlta(priority);
        }).length;
        if (urgentCount > 0) {
            console.log(`🚨 [applyLocalFilters] ${urgentCount} estudios con prioridad al principio de la lista`);
        }
        
        // Resetear paginación cuando se aplican filtros
        this.pagination.currentPage = 1;
        
        this.updateAssignmentUserFilterOptions();
        this.updateAvailableModalities();
        this.renderStudies();
        this.updateStats();
    }
    
    /**
     * Renderiza la tabla de estudios con paginación
     */
    renderStudies() {
        const tbody = document.getElementById('studiesTableBody');
        const emptyState = document.getElementById('emptyState');
        
        if (!tbody) return;
        
        if (this.filteredStudies.length === 0) {
            this.renderEmptyState();
            this.pagination.totalItems = 0;
            this.pagination.totalPages = 1;
            this.renderPagination();
            return;
        }
        
        // Calcular paginación (usar estudios ordenados)
        const sortedStudies = this.sortStudies([...this.filteredStudies]);
        this.pagination.totalItems = sortedStudies.length;
        this.pagination.totalPages = Math.ceil(this.pagination.totalItems / this.pagination.perPage);
        
        // Asegurar que currentPage no exceda totalPages
        if (this.pagination.currentPage > this.pagination.totalPages) {
            this.pagination.currentPage = Math.max(1, this.pagination.totalPages);
        }
        
        // Calcular índices de inicio y fin
        const startIndex = (this.pagination.currentPage - 1) * this.pagination.perPage;
        const endIndex = Math.min(startIndex + this.pagination.perPage, sortedStudies.length);
        
        // Obtener estudios de la página actual (después de ordenar)
        const studiesToShow = sortedStudies.slice(startIndex, endIndex);
        
        // Mostrar columna de derivaciones según nivel de usuario y si hay datos
        const hasSubassignments = Object.keys(this.studySubassignments).length > 0;
        const isRootOrAdmin = this.currentUserLevel === 'root' || this.currentUserLevel === 'admin';
        const hasAssignments = Object.keys(this.studyAssignments).length > 0;
        
        // Mostrar columna si:
        // 1. Es Root/Admin Y hay asignaciones O subasignaciones
        // 2. Es usuario normal Y tiene subasignaciones propias
        const shouldShowColumn = (isRootOrAdmin && (hasAssignments || hasSubassignments)) || 
                                 (!isRootOrAdmin && hasSubassignments);
        
        const headerColumn = document.getElementById('subassignmentsColumnHeader');
        
        if (shouldShowColumn) {
            if (headerColumn) headerColumn.style.display = '';
            console.log('Columna de derivaciones visible');
        } else {
            if (headerColumn) headerColumn.style.display = 'none';
            console.log('Columna de derivaciones oculta');
        }
        
        // Ocultar estado vacío
        if (emptyState) {
            emptyState.style.display = 'none';
        }
        
        // Limpiar tabla
        tbody.innerHTML = '';
        
        // Renderizar estudios de la página actual
        studiesToShow.forEach(study => {
            const row = this.createStudyRow(study);
            tbody.appendChild(row);
        });
        
        // Restaurar selecciones después de renderizar
        this.restoreSelections();
        
        // Renderizar paginación
        this.renderPagination();
        
        // Actualizar contador en el header
        this.updateStudiesCounter();
        
        // Recargar estado de antecedentes después de renderizar
        this.loadAntecedentsStatus();
        
        // Cargar modalidades faltantes en segundo plano
        this.loadMissingModalities();
    }
    
    /**
     * Carga modalidades en segundo plano para estudios que no las tengan
     * Usa todo this.studies (no solo la página visible): si solo se cargaran modalidades
     * de la página actual, el filtro por modalidad contaría mal y excluiría el resto.
     * OPTIMIZADO: Carga en lotes para no sobrecargar el servidor
     */
    async loadMissingModalities() {
        if (this._loadMissingModalitiesInFlight) {
            return;
        }

        const studiesWithoutModality = this.studies.filter(study =>
            !study.modality || study.modality === '' || study.modality.trim() === ''
        );

        if (studiesWithoutModality.length === 0) {
            return;
        }

        this._loadMissingModalitiesInFlight = true;
        let anyUpdated = false;

        try {
            console.log(`Cargando modalidades para ${studiesWithoutModality.length} estudios (listado completo)...`);

            const batchSize = 20;
            for (let i = 0; i < studiesWithoutModality.length; i += batchSize) {
                const batch = studiesWithoutModality.slice(i, i + batchSize);

                const promises = batch.map(async (study) => {
                    try {
                        const response = await fetch(`${this.apiBaseUrl}get_study_details.php?study_id=${study.id}`);

                        if (!response.ok) {
                            console.warn(`Error ${response.status} cargando modalidad para estudio ${study.id}`);
                            return;
                        }

                        const result = await response.json();

                        if (result.success && result.data && result.data.modality && result.data.modality !== 'N/A') {
                            anyUpdated = true;
                            const studyIndex = this.studies.findIndex(s => s.id === study.id);
                            if (studyIndex !== -1) {
                                this.studies[studyIndex].modality = result.data.modality;
                            }

                            const filteredIndex = this.filteredStudies.findIndex(s => s.id === study.id);
                            if (filteredIndex !== -1) {
                                this.filteredStudies[filteredIndex].modality = result.data.modality;
                            }

                            const studyRow = document.querySelector(`tr[data-study-id="${study.id}"]`);
                            if (studyRow) {
                                const cells = studyRow.querySelectorAll('td');
                                if (cells.length > 5) {
                                    const modalityBadge = this.getModalityBadge(result.data.modality);
                                    cells[5].innerHTML = modalityBadge;
                                }
                            }
                        }
                    } catch (error) {
                        console.error(`Error cargando modalidad para estudio ${study.id}:`, error);
                    }
                });

                await Promise.all(promises);

                if (i + batchSize < studiesWithoutModality.length) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                }
            }

            console.log('Modalidades cargadas en segundo plano');

            if (anyUpdated) {
                this.applyLocalFilters();
            } else {
                this.updateAvailableModalities();
            }

            if (anyUpdated) {
                try {
                    this.savePersistentState();
                } catch (e) {
                    console.warn('No se pudo guardar caché tras hidratar modalidades:', e);
                }
            }
        } catch (error) {
            console.error('Error cargando modalidades faltantes:', error);
        } finally {
            this._loadMissingModalitiesInFlight = false;
        }
    }
    
    /**
     * Renderiza el estado vacío
     */
    renderEmptyState() {
        const tbody = document.getElementById('studiesTableBody');
        const emptyState = document.getElementById('emptyState');
        
        if (!tbody || !emptyState) return;
        
        // Limpiar tabla
        tbody.innerHTML = '';
        
        // Mostrar estado vacío
        emptyState.style.display = 'table-row';
        tbody.appendChild(emptyState);
        
        // Actualizar botones de modalidad (ocultar todos excepto "Todas")
        // Actualizar modalidades disponibles (async, pero no esperamos para no bloquear)
        try {
            this.updateAvailableModalities();
        } catch (err) {
            console.error('Error actualizando modalidades disponibles:', err);
        }
        
        // Limpiar paginación
        this.pagination.totalItems = 0;
        this.pagination.totalPages = 1;
        this.renderPagination();
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
                <a class="page-link" href="#" onclick="derivacionesManager.goToPage(${currentPage - 1}); return false;" aria-label="Anterior">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
        `;
        
        // Páginas
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="derivacionesManager.goToPage(1); return false;">1</a></li>`;
            if (startPage > 2) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="derivacionesManager.goToPage(${i}); return false;">${i}</a>
                </li>
            `;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="derivacionesManager.goToPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }
        
        // Botón siguiente
        paginationHtml += `
            <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="derivacionesManager.goToPage(${currentPage + 1}); return false;" aria-label="Siguiente">
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
     * Actualiza el contador de estudios en el header
     */
    updateStudiesCounter() {
        const studiesCount = document.getElementById('studiesCount');
        if (studiesCount) {
            const total = this.pagination.totalItems;
            studiesCount.textContent = `${total} estudio${total !== 1 ? 's' : ''} encontrado${total !== 1 ? 's' : ''}`;
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
     * Crea una fila de estudio para la tabla
     */
    createStudyRow(study) {
        const row = document.createElement('tr');
        row.setAttribute('data-study-id', study.id);
        
        // Verificar si el estudio es huérfano (eliminado del PACS)
        const isOrphan = study.is_orphan === true || study.orphaned_from_pacs === true;
        const isRemoteOnly = study.em_remote_only === true;
        const blockLocalViewer = isOrphan || isRemoteOnly;
        
        // Agregar clase CSS para estudios huérfanos
        if (isOrphan) {
            row.classList.add('study-orphan');
            row.style.opacity = '0.7';
            row.style.backgroundColor = '#fff3cd'; // Color de advertencia suave
        } else if (isRemoteOnly) {
            row.classList.add('study-remote-pacs');
            row.style.opacity = '0.95';
            row.style.backgroundColor = '#e8f2ff';
        }
        
        // Verificar permisos antes de renderizar
        // gui_antecedentes controla la visibilidad del botón, antecedentes controla el acceso funcional
        const hasGuiAntecedentesPermiso = this.hasGuiAntecedentes === true;
        const hasAntecedentesPermiso = this.hasAntecedentes === true;
        console.log(`🔍 Renderizando estudio ${study.id}: hasGuiAntecedentes = ${hasGuiAntecedentesPermiso}, hasAntecedentes = ${hasAntecedentesPermiso}`);
        
        const formattedDate = this.formatDate(study.date || study.study_date);
        const formattedTime = this.formatTime(study.time || study.study_time);
        const modalityBadge = this.getModalityBadge(study.modality);
        
        // Escapar todos los datos del usuario para prevenir XSS y errores de sintaxis
        const safePatientName = this.escapeHtml(study.patient_name || '');
        const safePatientId = this.escapeHtml(study.patient_id || '');
        const safeStudyDescription = this.escapeHtml(study.study_description || '');
        const safeStudyId = this.escapeHtml(study.id || '');
        const safeViewerUrl = this.escapeHtml(study.viewer_url || '');
        const safeStatus = this.escapeHtml(study.status || 'Completado');
        
        // Escapar para JavaScript (usado en atributos onclick)
        const safeStudyIdJs = this.escapeJs(study.id || '');
        const safeViewerUrlJs = this.escapeJs(study.viewer_url || '');
        
        // Obtener información de asignaciones para este estudio
        const assignments = this.getStudyAssignments(study.id);
        const assignmentInfo = this.formatAssignmentInfo(assignments);
        
        // Obtener información de subasignaciones para este estudio
        const subassignments = this.getStudySubassignments(study.id);
        const subassignmentsInfo = this.formatSubassignmentsInfo(subassignments);
        
        // Determinar si mostrar la columna de derivaciones
        const hasSubassignments = Object.keys(this.studySubassignments).length > 0;
        const isRootOrAdmin = this.currentUserLevel === 'root' || this.currentUserLevel === 'admin';
        const hasAssignments = Object.keys(this.studyAssignments).length > 0;
        const shouldShowSubassignments = (isRootOrAdmin && (hasAssignments || hasSubassignments)) || 
                                         (!isRootOrAdmin && hasSubassignments);
        
        // Determinar el badge de status
        let statusBadge = '';
        if (isOrphan) {
            const safeOrphanStatus = this.escapeHtml(study.status || 'Eliminado del PACS');
            statusBadge = `<span class="badge status-badge bg-warning text-dark" title="Este estudio fue eliminado del PACS pero aún tiene asignaciones activas">
                <i class="fas fa-exclamation-triangle me-1"></i>${safeOrphanStatus}
            </span>`;
        } else if (isRemoteOnly) {
            const safeRemoteStatus = this.escapeHtml(study.status || 'Solo en PACS remoto');
            statusBadge = `<span class="badge status-badge bg-info text-dark" title="Aún no está en Orthanc local; asignar puede disparar recuperación">
                <i class="fas fa-cloud me-1"></i>${safeRemoteStatus}
            </span>`;
        } else {
            statusBadge = `<span class="badge status-badge status-completed">${safeStatus}</span>`;
        }

        // Indicador "INFORMADO" cuando el estudio tiene informe (verde si publicado en PACS,
        // info/celeste si aún está pendiente de publicar). Se agrega siempre, incluso a huérfanos,
        // para que en estudios-manager se vea de un vistazo qué estudios ya están informados.
        // Se renderiza un poco más chico que el badge "Completado" para que destaque sin pesar.
        const reportStatus = emGetStudyReportStatus(study);
        if (reportStatus !== 'none') {
            const totalInformes = Number((study.report_info && study.report_info.total_informes) || 0);
            const isPublished = reportStatus === 'published_pacs';
            const informedClass = isPublished ? 'bg-success' : 'bg-info text-dark';
            const informedIcon = isPublished ? 'fa-file-medical' : 'fa-file-alt';
            const informedTitle = isPublished
                ? `Informe publicado en PACS${totalInformes ? ` (${totalInformes})` : ''}`
                : `Informe realizado (pendiente de publicar en PACS)${totalInformes ? ` (${totalInformes})` : ''}`;
            const informedStyle = 'font-size: 0.65rem; padding: 0.2em 0.45em; line-height: 1; vertical-align: middle;';
            statusBadge += ` <span class="badge ${informedClass} ms-1" style="${informedStyle}" title="${this.escapeHtml(informedTitle)}">
                <i class="fas ${informedIcon} me-1"></i>INFORMADO${totalInformes > 1 ? ` (${totalInformes})` : ''}
            </span>`;
        }

        if (typeof QaQuickAction !== 'undefined' && QaQuickAction.enabled) {
            statusBadge += QaQuickAction.renderStudyBadge(study);
        }
        
        row.innerHTML = `
            <td>
                <input type="checkbox" class="form-check-input study-checkbox" 
                       data-study-id="${safeStudyId}">
            </td>
            <td>
                <div class="fw-semibold">${safePatientName}</div>
                <small class="text-muted d-block d-lg-none">ID: ${safePatientId}</small>
                ${isOrphan ? '<small class="text-warning d-block"><i class="fas fa-exclamation-triangle me-1"></i>Eliminado del PACS</small>' : ''}
                ${isRemoteOnly && !isOrphan ? '<small class="text-info d-block"><i class="fas fa-cloud me-1"></i>Solo en PACS remoto</small>' : ''}
            </td>
            <td class="d-none d-lg-table-cell">${safePatientId}</td>
            <td>${formattedDate}</td>
            <td class="d-none d-md-table-cell">${formattedTime}</td>
            <td class="d-none d-sm-table-cell">${modalityBadge}</td>
            <td class="d-none d-xl-table-cell">${safeStudyDescription}</td>
            <td class="d-none d-lg-table-cell">
                ${statusBadge}
            </td>
            <td>
                <div class="assignment-info">
                    ${assignmentInfo}
                </div>
            </td>
            <td class="subassignments-column" style="display: ${shouldShowSubassignments ? '' : 'none'};">
                <div class="subassignments-info">
                    ${subassignmentsInfo}
                </div>
            </td>
            <td>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-action btn-info" title="Información" 
                            onclick="derivacionesManager.showStudyInfo('${safeStudyIdJs}')">
                        <i class="fas fa-info-circle"></i>
                    </button>
                    ${blockLocalViewer ? `
                    <button class="btn btn-sm btn-action btn-view" title="${isOrphan ? 'Estudio eliminado del PACS' : 'Sin visor local hasta recuperar el estudio'}" disabled>
                        <i class="fas fa-eye-slash"></i>
                        <span class="d-none d-sm-inline ms-1">No disponible</span>
                    </button>
                    ` : `
                    <button class="btn btn-sm btn-action btn-view" title="Ver" onclick="window.open('${safeViewerUrlJs}', '_blank')">
                        <i class="fas fa-eye"></i>
                        <span class="d-none d-sm-inline ms-1">Ver</span>
                    </button>
                    `}
                    ${this.getPriorityButton(study, study.id)}
                    ${this.createSubassignButton(study.id, assignments)}
                    ${hasGuiAntecedentesPermiso ? `
                    <button class="btn btn-sm btn-action btn-warning position-relative" title="Antecedentes" 
                            onclick="derivacionesManager.openAntecedentsModal('${safeStudyIdJs}')">
                        <i class="fas fa-file-medical"></i>
                        <span class="d-none d-sm-inline ms-1">Antecedentes</span>
                        <span class="badge bg-info ms-1 antecedents-indicator" id="antecedents-${safeStudyId}" style="display: none;">
                            <i class="fas fa-check"></i>
                        </span>
                        <!-- Contador superpuesto -->
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger antecedents-counter d-none" 
                              id="antecedents-counter-${safeStudyId}" 
                              style="font-size: 0.7rem; min-width: 18px; height: 18px; line-height: 18px;">
                            0
                        </span>
                    </button>
                    ` : ''}
                    ${(typeof QaQuickAction !== 'undefined' && QaQuickAction.enabled) ? QaQuickAction.renderStudyButton(study) : ''}
                </div>
            </td>
        `;
        
        // Agregar event listener al checkbox después de crear la fila
        const checkbox = row.querySelector('.study-checkbox');
        if (checkbox) {
            checkbox.addEventListener('change', () => {
                console.log('🔍 Debug - Checkbox cambiado, ejecutando handleStudySelection');
                this.handleStudySelection();
            });
        }
        
        // Agregar event listener para menú contextual (click derecho)
        row.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.showContextMenu(e, study);
        });
        
        return row;
    }
    
    /**
     * Muestra el menú contextual con las acciones disponibles para un estudio
     */
    showContextMenu(event, study) {
        // Cerrar cualquier menú contextual abierto previamente
        const existingMenu = document.getElementById('studyContextMenu');
        if (existingMenu) {
            existingMenu.remove();
        }
        
        // Crear el menú contextual
        const menu = document.createElement('div');
        menu.id = 'studyContextMenu';
        menu.className = 'context-menu';
        
        const safeStudyIdJs = this.escapeJs(study.id || '');
        const safeViewerUrlJs = this.escapeJs(study.viewer_url || '');
        const isOrphan = study.is_orphan || false;
        const isRemoteOnly = study.em_remote_only === true;
        const assignments = this.getStudyAssignments(study.id);
        const hasSubassignButton = this.createSubassignButton(study.id, assignments) !== '';
        
        // Verificar permisos de antecedentes (usar la misma lógica que en createStudyRow)
        const hasGuiAntecedentesPermiso = this.hasGuiAntecedentes === true;
        
        // Obtener información de prioridad
        let prioridad = this.studyPriorities[study.id] || study.prioridad || 'normal';
        const priorityText = prioridad === 'urgente' ? 'Urgente' :
                            prioridad === 'promesa' ? 'Promesa' :
                            prioridad === 'pendiente' ? 'Pendiente' :
                            'Normal';
        
        // Guardar referencia al manager para usar en los event listeners
        const manager = this;
        
        // Construir el HTML del menú
        let menuHTML = '';
        
        // Información
        menuHTML += `
            <div class="context-menu-item" data-action="info">
                <i class="fas fa-info-circle me-2"></i>
                <span>Información</span>
            </div>
        `;
        
        // Ver (solo si hay visor local)
        if (!isOrphan && !isRemoteOnly && study.viewer_url) {
            menuHTML += `
                <div class="context-menu-item" data-action="view">
                    <i class="fas fa-eye me-2"></i>
                    <span>Ver Estudio</span>
                </div>
            `;
        }
        
        // Asignar Estudio (solo si tiene permiso de asignaciones)
        if (this.hasAsignaciones) {
            menuHTML += `
                <div class="context-menu-item" data-action="assign">
                    <i class="fas fa-user-plus me-2"></i>
                    <span>Asignar Estudio</span>
                </div>
            `;
        }
        
        // Prioridad
        menuHTML += `
            <div class="context-menu-item" data-action="priority">
                <i class="fas fa-flag me-2"></i>
                <span>Prioridad: ${priorityText}</span>
            </div>
        `;
        
        // Subasignación/Derivación (solo si está disponible)
        if (hasSubassignButton) {
            menuHTML += `
                <div class="context-menu-item" data-action="subassign">
                    <i class="fas fa-share-alt me-2"></i>
                    <span>Derivar Estudio</span>
                </div>
            `;
        }
        
        // Antecedentes (solo si tiene permiso)
        if (hasGuiAntecedentesPermiso) {
            menuHTML += `
                <div class="context-menu-item" data-action="antecedents">
                    <i class="fas fa-file-medical me-2"></i>
                    <span>Antecedentes</span>
                </div>
            `;
        }
        
        menu.innerHTML = menuHTML;
        
        // Agregar event listeners a cada item del menú
        menu.querySelectorAll('.context-menu-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                const action = item.getAttribute('data-action');
                
                switch(action) {
                    case 'info':
                        manager.showStudyInfo(study.id);
                        break;
                    case 'view':
                        if (study.viewer_url) {
                            window.open(study.viewer_url, '_blank');
                        }
                        break;
                    case 'assign':
                        manager.openAssignModal(study.id);
                        break;
                    case 'priority':
                        manager.openPriorityModal(study.id);
                        break;
                    case 'subassign':
                        manager.openSubassignModal(study.id);
                        break;
                    case 'antecedents':
                        manager.openAntecedentsModal(study.id);
                        break;
                }
                
                menu.remove();
            });
        });
        
        // Agregar al body
        document.body.appendChild(menu);
        
        // Posicionar el menú
        const x = event.clientX;
        const y = event.clientY;
        menu.style.left = `${x}px`;
        menu.style.top = `${y}px`;
        
        // Asegurar que el menú esté visible en la pantalla
        setTimeout(() => {
            const rect = menu.getBoundingClientRect();
            if (rect.right > window.innerWidth) {
                menu.style.left = `${window.innerWidth - rect.width - 10}px`;
            }
            if (rect.bottom > window.innerHeight) {
                menu.style.top = `${window.innerHeight - rect.height - 10}px`;
            }
        }, 0);
        
        // Cerrar el menú al hacer click fuera o al hacer scroll
        const closeMenu = (e) => {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', closeMenu);
                document.removeEventListener('scroll', closeMenu, true);
            }
        };
        
        setTimeout(() => {
            document.addEventListener('click', closeMenu);
            document.addEventListener('scroll', closeMenu, true);
        }, 0);
    }
    
    /**
     * Abre el modal de prioridad (wrapper para compatibilidad)
     */
    openPriorityModal(studyId) {
        // Buscar el botón de prioridad y hacer click en él
        const row = document.querySelector(`tr[data-study-id="${studyId}"]`);
        if (row) {
            const priorityButton = row.querySelector('.btn-priority');
            if (priorityButton) {
                priorityButton.click();
            } else {
                // Si no hay botón, buscar el modal de prioridad y abrirlo manualmente
                // Primero necesitamos obtener el estudio para mostrar el modal
                const study = this.studies.find(s => s.id === studyId);
                if (study) {
                    // Simular click en el botón de prioridad creando uno temporalmente
                    const tempButton = document.createElement('button');
                    tempButton.className = 'btn btn-sm btn-priority';
                    tempButton.setAttribute('data-study-id', studyId);
                    tempButton.style.display = 'none';
                    document.body.appendChild(tempButton);
                    tempButton.click();
                    tempButton.remove();
                }
            }
        }
    }
    
    /**
     * Crea el botón de subasignación si el usuario actual es el principal asignado
     */
    createSubassignButton(studyId, assignments) {
        // Log de depuración DETALLADO
        console.group(`🔍 createSubassignButton para study: ${studyId}`);
        console.log('hasDerivaciones:', this.hasDerivaciones);
        console.log('currentUserId:', this.currentUserId);
        console.log('assignments:', assignments);
        console.log('totalUsers:', this.users.length);
        
        // Verificar permiso de derivaciones
        if (!this.hasDerivaciones) {
            console.log('❌ FALLO: No tiene permiso de derivaciones');
            console.groupEnd();
            return ''; // No tiene permiso de derivaciones
        }
        console.log('✅ PASO 1: Tiene permiso de derivaciones');
        
        // Verificar si el usuario actual es el asignado principal
        if (!assignments || assignments.length === 0) {
            console.log('❌ FALLO: No hay asignaciones para este estudio');
            console.groupEnd();
            return ''; // No hay asignaciones
        }
        console.log('✅ PASO 2: Hay asignaciones para este estudio');
        
        // Verificar si el usuario actual está entre los asignados
        console.log('Verificando si usuario', this.currentUserId, 'está en asignaciones...');
        assignments.forEach(a => {
            console.log('  - Asignación:', {
                user_id: a.user_id,
                tipo: typeof a.user_id,
                match: a.user_id == this.currentUserId
            });
        });
        
        const isAssignedToMe = assignments.some(a => a.user_id == this.currentUserId);
        
        if (!isAssignedToMe) {
            console.log('❌ FALLO: Estudio no asignado al usuario actual');
            console.groupEnd();
            return ''; // No está asignado al usuario actual
        }
        console.log('✅ PASO 3: Estudio asignado al usuario actual');
        
        // Verificar si el usuario tiene cuentas hijas
        console.log('Buscando usuarios con padre_id ==', this.currentUserId);
        console.log('Todos los usuarios:', this.users.map(u => ({
            id: u.id,
            nombre: u.nombre,
            padre_id: u.padre_id,
            match: u.padre_id == this.currentUserId
        })));
        
        const childUsers = this.users.filter(u => u.padre_id == this.currentUserId);
        
        console.log('🔍 Cuentas hijas encontradas:', childUsers.length);
        if (childUsers.length > 0) {
            console.log('Cuentas hijas:', childUsers);
        }
        
        if (childUsers.length === 0) {
            console.log('❌ FALLO: No tiene cuentas hijas para derivar');
            console.groupEnd();
            return ''; // No tiene cuentas hijas para subasignar
        }
        console.log('✅ PASO 4: Tiene cuentas hijas');
        
        console.log('✅✅✅ ÉXITO: Mostrando botón Derivar');
        console.groupEnd();
        
        // Escapar studyId para JavaScript
        const safeStudyIdJs = this.escapeJs(studyId);
        
        // Crear botón de subasignación
        return `
            <button class="btn btn-sm btn-action btn-success" title="Subasignar a cuenta hija" 
                    onclick="derivacionesManager.openSubassignModal('${safeStudyIdJs}')">
                <i class="fas fa-user-plus"></i>
                <span class="d-none d-sm-inline ms-1">Derivar</span>
            </button>
        `;
    }
    
    /**
     * Abre el modal de subasignación para un estudio
     */
    openSubassignModal(studyId) {
        const study = this.studies.find(s => s.id === studyId);
        if (!study) {
            this.showError('Estudio no encontrado');
            return;
        }
        
        // Verificar que currentUserId esté definido
        if (!this.currentUserId) {
            console.error('❌ currentUserId no está definido');
            this.showError('Error: No se pudo identificar el usuario actual. Recarga la página.');
            return;
        }
        
        console.log('🔍 Usuario actual ID:', this.currentUserId);
        
        // Obtener solo cuentas hijas del usuario actual
        const childUsers = this.users.filter(u => u.padre_id == this.currentUserId);
        
        console.log('🔍 Cuentas hijas encontradas:', childUsers.length, childUsers);
        
        if (childUsers.length === 0) {
            this.showError('No tienes cuentas hijas para derivar este estudio');
            return;
        }
        
        // Obtener derivaciones existentes para este estudio
        const existingSubassignments = this.getStudySubassignments(studyId);
        const subassignedUserIds = existingSubassignments.map(sub => sub.subassigned_to_user_id);
        
        console.log('🔍 Derivaciones existentes:', existingSubassignments);
        console.log('🔍 IDs de usuarios con derivación:', subassignedUserIds);
        
        // Crear contenido del modal con tildes activados para usuarios que ya tienen derivación
        let usersHTML = '<div class="list-group">';
        childUsers.forEach(user => {
            // Verificar si este usuario ya tiene derivación
            const isSubassigned = subassignedUserIds.includes(user.id);
            const checkedAttr = isSubassigned ? 'checked' : '';
            
            usersHTML += `
                <label class="list-group-item d-flex align-items-center">
                    <input type="checkbox" class="form-check-input me-2" value="${user.id}" ${checkedAttr}>
                    <div class="flex-grow-1">
                        <strong>${user.nombre} ${user.apellido}</strong>
                        <br>
                        <small class="text-muted">${user.email}</small>
                    </div>
                </label>
            `;
        });
        usersHTML += '</div>';
        
        const modalContent = `
            <div class="subassign-modal-content">
                <h6 class="mb-3">Estudio a derivar:</h6>
                <div class="card mb-3">
                    <div class="card-body">
                        <p class="mb-1"><strong>Paciente:</strong> ${study.patient_name}</p>
                        <p class="mb-1"><strong>ID Paciente:</strong> ${study.patient_id}</p>
                        <p class="mb-0"><strong>Modalidad:</strong> ${study.modality}</p>
                    </div>
                </div>
                <h6 class="mb-2">Selecciona las cuentas hijas para derivar:</h6>
                ${usersHTML}
            </div>
        `;
        
        // Limpiar estado de modales anteriores
        this.cleanupModalState();
        
        setTimeout(() => {
            // Crear modal dinámicamente
            const subassignModal = document.createElement('div');
            subassignModal.className = 'modal fade';
            subassignModal.id = 'subassignStudyModal';
            subassignModal.setAttribute('data-bs-backdrop', 'true');
            subassignModal.setAttribute('data-bs-keyboard', 'true');
            subassignModal.style.zIndex = '10000';
            subassignModal.style.position = 'fixed';
            
            subassignModal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="z-index: 10001; position: relative;">
                    <div class="modal-content" style="z-index: 10002; pointer-events: auto;">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-user-plus me-2"></i>Derivar Estudio a Cuentas Hijas
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            ${modalContent}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-success" id="confirmSubassignBtn">
                                <i class="fas fa-check me-1"></i>Confirmar Derivación
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al DOM
            document.body.appendChild(subassignModal);
            
            // Verificar si hay una instancia previa y limpiarla
            const existingInstance = bootstrap.Modal.getInstance(subassignModal);
            if (existingInstance) {
                existingInstance.dispose();
            }
            
            // Crear instancia de Bootstrap Modal
            const modalInstance = new bootstrap.Modal(subassignModal, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            
            // Configurar botón de confirmación
            const confirmBtn = subassignModal.querySelector('#confirmSubassignBtn');
            confirmBtn.addEventListener('click', () => {
                this.confirmSubassignment(studyId, modalInstance);
            });
            
            // Configurar evento para limpiar el modal cuando se cierre
            const cleanupHandler = () => {
                // Limpiar cualquier backdrop residual
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                // Remover clase modal-open del body si no hay otros modales abiertos
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
                
                // Limpiar el modal
                try {
                    modalInstance.dispose();
                    subassignModal.remove();
                } catch (e) {
                    console.warn('Error limpiando modal:', e);
                }
            };
            
            // Remover listener anterior si existe
            subassignModal.removeEventListener('hidden.bs.modal', cleanupHandler);
            // Agregar nuevo listener
            subassignModal.addEventListener('hidden.bs.modal', cleanupHandler);
            
            modalInstance.show();
        }, 100);
    }
    
    /**
     * Confirma la subasignación del estudio (crear nuevas y eliminar destildadas)
     */
    async confirmSubassignment(studyId, modalInstance) {
        try {
            // Obtener usuarios actualmente seleccionados (tildados)
            const selectedUserIds = [];
            document.querySelectorAll('#subassignStudyModal input[type="checkbox"]:checked').forEach(checkbox => {
                selectedUserIds.push(parseInt(checkbox.value));
            });
            
            // Obtener derivaciones existentes
            const existingSubassignments = this.getStudySubassignments(studyId);
            const existingUserIds = existingSubassignments.map(sub => sub.subassigned_to_user_id);
            
            console.log('🔍 Estado de derivaciones:', {
                study_id: studyId,
                seleccionados_ahora: selectedUserIds,
                existentes_antes: existingUserIds
            });
            
            // Determinar qué usuarios hay que agregar (nuevos tildados)
            const usersToAdd = selectedUserIds.filter(id => !existingUserIds.includes(id));
            
            // Determinar qué usuarios hay que eliminar (destildados)
            const usersToRemove = existingUserIds.filter(id => !selectedUserIds.includes(id));
            
            console.log('🔍 Cambios a realizar:', {
                agregar: usersToAdd,
                eliminar: usersToRemove
            });
            
            let addedCount = 0;
            let removedCount = 0;
            let errors = [];
            
            // 1. AGREGAR nuevas derivaciones
            if (usersToAdd.length > 0) {
                try {
                    const addResponse = await fetch(this.apiBaseUrl + 'create_subassignment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            study_id: studyId,
                            main_user_id: this.currentUserId,
                            subassigned_to_user_ids: usersToAdd,
                            assigned_by_user_id: this.currentUserId
                        })
                    });
                    
                    const addResult = await addResponse.json();
                    console.log('📦 Resultado agregar derivaciones:', addResult);
                    
                    if (addResult.success) {
                        addedCount = usersToAdd.length;
                    } else {
                        errors.push(`Error agregando derivaciones: ${addResult.error}`);
                    }
                } catch (error) {
                    errors.push(`Error en petición de agregar: ${error.message}`);
                }
            }
            
            // 2. ELIMINAR derivaciones destildadas
            if (usersToRemove.length > 0) {
                try {
                    const removeResponse = await fetch(this.apiBaseUrl + 'delete_subassignment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            study_id: studyId,
                            main_user_id: this.currentUserId,
                            subassigned_to_user_ids: usersToRemove
                        })
                    });
                    
                    const removeResult = await removeResponse.json();
                    console.log('📦 Resultado eliminar derivaciones:', removeResult);
                    
                    if (removeResult.success) {
                        removedCount = usersToRemove.length;
                    } else {
                        errors.push(`Error eliminando derivaciones: ${removeResult.error}`);
                    }
                } catch (error) {
                    errors.push(`Error en petición de eliminar: ${error.message}`);
                }
            }
            
            // 3. MOSTRAR RESULTADO
            if (errors.length > 0) {
                this.showError('Errores en derivación: ' + errors.join(', '));
            } else {
                let message = 'Derivaciones actualizadas exitosamente';
                if (addedCount > 0 && removedCount > 0) {
                    message = `Derivaciones actualizadas: ${addedCount} agregadas, ${removedCount} eliminadas`;
                } else if (addedCount > 0) {
                    message = `${addedCount} derivación(es) agregada(s) exitosamente`;
                } else if (removedCount > 0) {
                    message = `${removedCount} derivación(es) eliminada(s) exitosamente`;
                } else {
                    message = 'No hay cambios en las derivaciones';
                }
                
                this.showSuccess(message);
                
                // Cerrar modal
                if (modalInstance) {
                    modalInstance.hide();
                }
                
                // Recargar subasignaciones y re-renderizar
                await this.loadStudySubassignments();
                this.renderStudies();
            }
            
        } catch (error) {
            console.error('Error en subasignación:', error);
            this.showError('Error realizando derivación: ' + error.message);
        }
    }
    
    /**
     * Abre el modal de asignación para un estudio
     */
    openAssignModal(studyId) {
        const study = this.findStudyForAssignment(studyId);
        if (!study) {
            this.showError('Estudio no encontrado');
            return;
        }
        
        this.selectedStudy = study;
        this.selectedUsers = [];
        
        // Verificar que el modal existe antes de intentar actualizar
        const modalElement = document.getElementById('assignStudyModal');
        if (!modalElement) {
            this.showError('El modal de asignación no está disponible. Por favor, recarga la página.');
            return;
        }
        
        // Actualizar información del estudio en el modal (con verificaciones)
        const modalPatientName = document.getElementById('modalPatientName');
        const modalPatientId = document.getElementById('modalPatientId');
        const modalModality = document.getElementById('modalModality');
        const modalStudyDate = document.getElementById('modalStudyDate');
        const modalStudyDescription = document.getElementById('modalStudyDescription');
        
        if (modalPatientName) modalPatientName.textContent = study.patient_name || '-';
        if (modalPatientId) modalPatientId.textContent = study.patient_id || '-';
        if (modalModality) modalModality.textContent = study.modality || '-';
        if (modalStudyDate) modalStudyDate.textContent = this.formatDate(study.date || study.study_date);
        if (modalStudyDescription) modalStudyDescription.textContent = study.study_description || '-';
        
        // Renderizar usuarios en el modal
        this.renderModalUsers();
        
        // Limpiar estado de modales anteriores
        this.cleanupModalState();
        
        // Configurar el botón de confirmación para asignación individual
        const confirmAssignBtn = document.getElementById('confirmAssignBtn');
        if (confirmAssignBtn) {
            // Remover listeners anteriores
            const newBtn = confirmAssignBtn.cloneNode(true);
            confirmAssignBtn.parentNode.replaceChild(newBtn, confirmAssignBtn);
            
            // Agregar nuevo listener para asignación individual
            newBtn.addEventListener('click', () => {
                this.confirmAssignment();
            });
        }
        
        // Esperar un momento para que se limpie completamente
        setTimeout(() => {
        // Mostrar modal
            // Verificar si hay una instancia previa y limpiarla
            const existingInstance = bootstrap.Modal.getInstance(modalElement);
            if (existingInstance) {
                existingInstance.dispose();
            }
            
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            
            // Configurar evento para limpiar el modal cuando se cierre
            const cleanupHandler = () => {
                // Limpiar cualquier backdrop residual
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                // Remover clase modal-open del body si no hay otros modales abiertos
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            };
            
            // Remover listener anterior si existe
            modalElement.removeEventListener('hidden.bs.modal', cleanupHandler);
            // Agregar nuevo listener
            modalElement.addEventListener('hidden.bs.modal', cleanupHandler);
            
        modal.show();
        }, 100);
             
             // Debug adicional después de mostrar el modal
             setTimeout(() => {
                 const modalAfterShow = document.getElementById('confirmationModal');
                 const computedStyles = window.getComputedStyle(modalAfterShow);
                 console.log('🔍 Debug - Modal después de show():', {
                     display: computedStyles.display,
                     visibility: computedStyles.visibility,
                     opacity: computedStyles.opacity,
                     zIndex: computedStyles.zIndex,
                     position: computedStyles.position,
                     top: computedStyles.top,
                     left: computedStyles.left
                 });
                 
                 // Verificar si el modal está realmente visible
                 const rect = modalAfterShow.getBoundingClientRect();
                 console.log('🔍 Debug - Posición del modal:', rect);
                 
                 // Forzar estilos si es necesario
                 if (computedStyles.display === 'none' || computedStyles.visibility === 'hidden') {
                     console.log('⚠️ Modal no visible, forzando estilos...');
                     modalAfterShow.style.display = 'block !important';
                     modalAfterShow.style.visibility = 'visible !important';
                     modalAfterShow.style.opacity = '1 !important';
                 }
             }, 200);
    }
    
    /**
     * Renderiza los usuarios en el modal de asignación
     */
    renderModalUsers() {
        const container = document.getElementById('modalUsersContainer');
        if (!container) return;
        
        let html = '';
        this.users.forEach(user => {
            const initials = (user.nombre.charAt(0) + user.apellido.charAt(0)).toUpperCase();
            const fullName = `${user.nombre} ${user.apellido}`;
            
            html += `
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="${user.id}" id="modalUser${user.id}">
                    <label class="form-check-label d-flex align-items-center" for="modalUser${user.id}">
                        <div class="user-avatar me-3" style="width: 35px; height: 35px; font-size: 14px;">
                            ${initials}
                        </div>
                        <div>
                            <strong>${fullName}</strong><br>
                            <small class="text-muted">${user.email} - MP: ${user.matricula_profesional}</small>
                        </div>
                    </label>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
    
    /**
     * Confirma la asignación del estudio
     */
    async confirmAssignment() {
        try {
            // Obtener usuarios seleccionados del modal
            const selectedUserIds = [];
            document.querySelectorAll('#modalUsersContainer input[type="checkbox"]:checked').forEach(checkbox => {
                selectedUserIds.push(parseInt(checkbox.value));
            });
            
            if (selectedUserIds.length === 0) {
                this.showError('Por favor selecciona al menos un usuario para la asignación');
                return;
            }
            
            if (!this.selectedStudy) {
                this.showError('No hay estudio seleccionado');
                return;
            }
            
            // Obtener usuario actual (asignador)
            const currentUser = this.getCurrentUser();
            if (!currentUser || !currentUser.id) {
                this.showError('No se pudo obtener información del usuario actual');
                return;
            }

            const remoteOnly = this.selectedStudy.em_remote_only === true;
            if (remoteOnly) {
                await this.triggerRemoteRetrieveIfNeeded(this.selectedStudy);
            }
            
            // Preparar datos del estudio para la API
            const studyData = {
                patient_name: this.selectedStudy.patient_name || null,
                patient_id: this.selectedStudy.patient_id || null,
                study_date: this.selectedStudy.date || this.selectedStudy.study_date || null,
                study_time: this.selectedStudy.time || this.selectedStudy.study_time || null,
                modality: this.selectedStudy.modality || null,
                study_description: this.selectedStudy.study_description || null,
                accession_number: this.selectedStudy.accession_number || null,
                referring_physician: this.selectedStudy.referring_physician || null,
                study_instance_uid: this.selectedStudy.study_instance_uid || null,
                series_count: this.selectedStudy.series_count || 0,
                instances_count: this.selectedStudy.instances_count || 0,
                viewer_url: this.selectedStudy.viewer_url || null,
                orthanc_study_id: remoteOnly
                    ? (this.selectedStudy.orthanc_study_id || this.selectedStudy.orthanc_id || null)
                    : (this.selectedStudy.orthanc_study_id || this.selectedStudy.orthanc_id || this.selectedStudy.id || null),
                patient_birth_date: this.selectedStudy.patient_birth_date || null,
                patient_sex: this.selectedStudy.patient_sex || null,
                institution_name: this.selectedStudy.institution_name || null,
                em_remote_only: remoteOnly,
                em_remote_node_id: remoteOnly ? (this.selectedStudy.em_remote_node_id || null) : null,
                em_pending_orthanc: remoteOnly,
            };
            
            // Llamar a la API para asignar el estudio
            const response = await fetch(this.apiBaseUrl + 'assign_study.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    study_id: this.selectedStudy.id,
                    user_ids: selectedUserIds,
                    assigned_by: currentUser.id,
                    study_data: studyData
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Mostrar mensaje de éxito
                const selectedUserNames = selectedUserIds.map(id => {
                    const user = this.users.find(u => u.id === id);
                    return user ? `${user.nombre} ${user.apellido}` : 'Usuario desconocido';
                }).join(', ');
                
                this.showSuccess(`Estudio asignado exitosamente a: ${selectedUserNames}`);
                
                // Cerrar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('assignStudyModal'));
                if (modal) {
                    modal.hide();
                }
                
                // Recargar asignaciones
                await this.loadStudyAssignments();
                
                // Actualizar tabla
                this.renderStudies();
                
                // Actualizar estadísticas
                this.updateAssignmentStats();
            } else {
                throw new Error(result.error || 'Error en la asignación');
            }
            
        } catch (error) {
            console.error('Error en asignación:', error);
            this.showError('Error realizando asignación: ' + error.message);
        }
    }
    
    /**
     * Muestra información detallada de un estudio
     */
    async showStudyInfo(studyId) {
        const study = this.findStudyForAssignment(studyId);
        if (!study) {
            this.showError('Estudio no encontrado');
            return;
        }

        const isRemoteOnly = study.em_remote_only === true;
        
        // Verificar si los detalles ya están cargados
        let currentSeriesCount = study.series_count || 0;
        let currentInstancesCount = study.instances_count || 0;
        
        // Si instances_count es 0, intentar cargar los detalles bajo demanda (solo si hay ID Orthanc local)
        if (!isRemoteOnly && currentInstancesCount === 0) {
            try {
                const oid = String(study.orthanc_study_id || study.orthanc_id || '').trim();
                let studyIdParam = oid;
                if (!studyIdParam) {
                    const sid = String(study.id || '');
                    if (sid && !/^1\.2\.\d/.test(sid)) {
                        studyIdParam = sid;
                    }
                }
                if (!studyIdParam) {
                    throw new Error('Sin ID Orthanc local');
                }
                // Construir URL con seriesIds si están disponibles
                let url = `${this.apiBaseUrl}get_study_details.php?studyId=${encodeURIComponent(studyIdParam)}`;
                if (study._series_ids && Array.isArray(study._series_ids) && study._series_ids.length > 0) {
                    const seriesIdsParam = encodeURIComponent(JSON.stringify(study._series_ids));
                    url += `&seriesIds=${seriesIdsParam}`;
                }
                
                // Obtener detalles del estudio desde la API
                const response = await fetch(url);
                if (response.ok) {
                    const result = await response.json();
                    if (result.success && result.data) {
                        currentSeriesCount = result.data.series_count || currentSeriesCount;
                        currentInstancesCount = result.data.instances_count || currentInstancesCount;
                        
                        // Actualizar el estudio en memoria para futuras consultas
                        study.series_count = currentSeriesCount;
                        study.instances_count = currentInstancesCount;
                    }
                }
            } catch (error) {
                console.error('Error cargando detalles del estudio:', error);
                // Continuar con los datos disponibles
            }
        }
        
        // Crear modal dinámico para mostrar información
        const modalId = 'studyInfoModal';
        
        // Remover modal existente si existe
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        // Escapar todos los datos para prevenir XSS y errores de sintaxis
        const safePatientName = this.escapeHtml(study.patient_name || '');
        const safePatientId = this.escapeHtml(study.patient_id || '');
        const safePatientBirthDate = this.escapeHtml(study.patient_birth_date || 'N/A');
        const safePatientSex = this.escapeHtml(study.patient_sex || 'N/A');
        const safeModality = this.escapeHtml(study.modality || '');
        const safeStudyDescription = this.escapeHtml(study.study_description || '');
        const safeStatus = this.escapeHtml(study.status || '');
        const safeStudyInstanceUID = this.escapeHtml(study.study_instance_uid || 'N/A');
        const safeOrthancStudyId = this.escapeHtml(study.orthanc_study_id || 'N/A');
        const safeStudyId = this.escapeHtml(study.id || '');
        const safeStudyIdJs = this.escapeJs(study.id || '');
        
        const modalHTML = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="${modalId}Label">
                                <i class="fas fa-info-circle me-2"></i>Información del Estudio
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Información del Paciente</h6>
                                    <table class="table table-sm">
                                        <tr><td><strong>Nombre:</strong></td><td>${safePatientName}</td></tr>
                                        <tr><td><strong>ID Paciente:</strong></td><td>${safePatientId}</td></tr>
                                        <tr><td><strong>Fecha Nacimiento:</strong></td><td>${safePatientBirthDate}</td></tr>
                                        <tr><td><strong>Sexo:</strong></td><td>${safePatientSex}</td></tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Información del Estudio</h6>
                                    <table class="table table-sm">
                                        <tr><td><strong>Fecha:</strong></td><td>${this.formatDate(study.date || study.study_date)}</td></tr>
                                        <tr><td><strong>Hora:</strong></td><td>${this.formatTime(study.time || study.study_time)}</td></tr>
                                        <tr><td><strong>Modalidad:</strong></td><td>${safeModality}</td></tr>
                                        <tr><td><strong>Descripción:</strong></td><td>${safeStudyDescription}</td></tr>
                                        <tr><td><strong>Estado:</strong></td><td>${safeStatus}</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6>Información Técnica</h6>
                                    <table class="table table-sm">
                                        <tr><td><strong>Study Instance UID:</strong></td><td class="text-break">${safeStudyInstanceUID}</td></tr>
                                        <tr><td><strong>Orthanc Study ID:</strong></td><td>${safeOrthancStudyId}</td></tr>
                                        <tr><td><strong>Número de Series:</strong></td><td>${currentSeriesCount}</td></tr>
                                        <tr><td><strong>Número de Instancias:</strong></td><td>${currentInstancesCount}</td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-primary" onclick="derivacionesManager.openAssignModal('${safeStudyIdJs}'); bootstrap.Modal.getInstance(document.getElementById('${modalId}')).hide();">
                                <i class="fas fa-share-alt me-2"></i>Asignar Estudio
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        const modal = new bootstrap.Modal(document.getElementById(modalId), {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        modal.show();
        
        // Limpiar modal cuando se cierre
        document.getElementById(modalId).addEventListener('hidden.bs.modal', function() {
            // Limpiar cualquier backdrop residual
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            // Remover clase modal-open del body si no hay otros modales abiertos
            if (document.querySelectorAll('.modal.show').length === 0) {
                document.body.classList.remove('modal-open');
            }
            // Remover el modal del DOM
            this.remove();
        });
    }
    
    /**
     * Actualiza las estadísticas
     */
    updateStats() {
        const studiesCount = document.getElementById('studiesCount');
        if (studiesCount) {
            const count = this.filteredStudies.length;
            studiesCount.textContent = `${count} estudio${count !== 1 ? 's' : ''} encontrado${count !== 1 ? 's' : ''}`;
        }
    }
    
    /**
     * Actualiza las estadísticas de asignación desde los datos reales
     */
    async updateAssignmentStats() {
        try {
            // Obtener asignaciones desde la API para calcular estadísticas reales
            const response = await fetch(this.apiBaseUrl + 'get_study_assignments.php');
            const result = await response.json();
            
            let assignedToday = 0;
            let totalAssigned = 0;
            
            if (result.success && result.data && Array.isArray(result.data)) {
                // Obtener fecha de hoy en formato YYYY-MM-DD
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const todayStr = today.toISOString().split('T')[0];
                
                // Contar asignaciones
                result.data.forEach(assignment => {
                    if (assignment.status === 'active') {
                        totalAssigned++;
                        
                        // Verificar si fue asignada hoy
                        if (assignment.assigned_date) {
                            const assignedDate = new Date(assignment.assigned_date);
                            assignedDate.setHours(0, 0, 0, 0);
                            const assignedDateStr = assignedDate.toISOString().split('T')[0];
                            
                            if (assignedDateStr === todayStr) {
                                assignedToday++;
                            }
                        }
                    }
                });
            }
            
            // Actualizar elementos del DOM
            const assignedTodayEl = document.getElementById('assignedToday');
            const totalAssignedEl = document.getElementById('totalAssigned');
            
            if (assignedTodayEl) {
                assignedTodayEl.textContent = assignedToday;
            }
            
            if (totalAssignedEl) {
                totalAssignedEl.textContent = totalAssigned;
            }
            
            console.log(`📊 Estadísticas de asignación actualizadas: ${assignedToday} hoy, ${totalAssigned} total`);
            
        } catch (error) {
            console.error('Error actualizando estadísticas de asignación:', error);
            // En caso de error, mantener los valores actuales o poner 0
            const assignedTodayEl = document.getElementById('assignedToday');
            const totalAssignedEl = document.getElementById('totalAssigned');
            
            if (assignedTodayEl && assignedTodayEl.textContent === '0') {
                assignedTodayEl.textContent = '0';
            }
            if (totalAssignedEl && totalAssignedEl.textContent === '0') {
                totalAssignedEl.textContent = '0';
            }
        }
    }
    
    /**
     * Actualiza el contador de modalidades
     */
    updateModalityCounter() {
        const counter = document.getElementById('modalityCounter');
        if (!counter) return;
        
        const activeModalities = this.currentFilters.modalities.length;
        
        if (activeModalities > 0) {
            counter.textContent = activeModalities;
            counter.style.display = 'inline';
        } else {
            counter.style.display = 'none';
        }
    }
    
    /**
     * Actualiza el contador de estudios urgentes en el header
     */
    async updateUrgentCounter() {
        try {
            const counterElement = document.getElementById('estudiosUrgentes');
            const badgeElement = document.getElementById('urgentesBadge');
            if (!counterElement) return;
            
            // Contar estudios urgentes desde this.studies
            const urgentStudies = this.studies.filter(study => {
                const hasPriority = study.prioridad && emPrioridadAlta(study.prioridad);
                const isUrgent = study.is_urgent || hasPriority;
                return isUrgent;
            });
            
            const urgentCount = urgentStudies.length;
            counterElement.textContent = urgentCount;
            
            // Actualizar badge
            if (badgeElement) {
                if (urgentCount > 0) {
                    badgeElement.textContent = urgentCount;
                    badgeElement.style.display = 'block';
                } else {
                    badgeElement.style.display = 'none';
                }
            }
            
            console.log(`📊 Contador de urgentes actualizado: ${urgentCount}`);
        } catch (error) {
            console.error('Error actualizando contador de urgentes:', error);
        }
    }
    
    /**
     * Formatea una fecha
     */
    formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        
        try {
            let date;
            
            // Si es formato YYYYMMDD (del PACS)
            if (typeof dateStr === 'string' && dateStr.length === 8 && /^\d{8}$/.test(dateStr)) {
                const year = dateStr.substring(0, 4);
                const month = dateStr.substring(4, 6);
                const day = dateStr.substring(6, 8);
                date = new Date(year, month - 1, day);
            } else {
                // Intentar parsear como fecha normal
                date = new Date(dateStr);
            }
            
            // Verificar si la fecha es válida
            if (isNaN(date.getTime())) {
                return dateStr; // Devolver el string original si no se puede parsear
            }
            
            return date.toLocaleDateString('es-ES', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
        } catch (error) {
            console.warn('Error formateando fecha:', dateStr, error);
            return dateStr;
        }
    }
    
    /**
     * Formatea una hora
     */
    formatTime(timeStr) {
        if (!timeStr) return 'N/A';
        
        try {
            if (timeStr.length >= 6) {
                const hours = timeStr.substring(0, 2);
                const minutes = timeStr.substring(2, 4);
                return `${hours}:${minutes}`;
            }
            return timeStr;
        } catch (error) {
            return timeStr;
        }
    }
    
    /**
     * Obtiene el badge de modalidad
     */
    getModalityBadge(modality) {
        const modalityColors = {
            'CT': 'bg-primary',
            'MR': 'bg-success',
            'RX': 'bg-info',
            'DX': 'bg-info',
            'US': 'bg-warning',
            'CR': 'bg-secondary',
            'MG': 'bg-danger',
            'NM': 'bg-dark',
            'PT': 'bg-dark',
            'XA': 'bg-primary',
            'RF': 'bg-primary'
        };
        
        const colorClass = modalityColors[modality] || 'bg-secondary';
        return `<span class="badge ${colorClass}">${modality}</span>`;
    }
    
    /**
     * Actualiza el estado del caché
     */
    updateCacheStatus(message, className = 'text-muted') {
        const statusElement = document.getElementById('cacheStatus');
        if (statusElement) {
            statusElement.innerHTML = `<i class="fas fa-database me-1"></i>${message}`;
            statusElement.className = className;
        }
    }
    
    /**
     * Muestra un mensaje de error
     */
    showError(message) {
        // Crear toast de error
        const toastHTML = `
            <div class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-exclamation-circle me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        this.showToast(toastHTML);
    }
    
    /**
     * Muestra un mensaje de éxito
     */
    showSuccess(message) {
        // Crear toast de éxito
        const toastHTML = `
            <div class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-check-circle me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        this.showToast(toastHTML);
    }
    
    /**
     * Muestra un toast
     */
    showToast(toastHTML) {
        // Crear contenedor de toasts si no existe
        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        // Agregar toast
        toastContainer.insertAdjacentHTML('beforeend', toastHTML);
        
        // Inicializar y mostrar toast
        const toastElement = toastContainer.lastElementChild;
        const toast = new bootstrap.Toast(toastElement, { delay: 5000 });
        toast.show();
        
        // Remover toast del DOM cuando se oculte
        toastElement.addEventListener('hidden.bs.toast', function() {
            this.remove();
        });
    }
    
    /**
     * Guarda el estado persistente
     */
    savePersistentState() {
        try {
            // Obtener IDs de estudios seleccionados antes de guardar
            const selectedStudyIds = this.getSelectedStudyIds();
            
            const state = {
                studies: this.studies,
                filters: this.currentFilters,
                pagination: {
                    perPage: this.pagination.perPage
                    // No guardamos currentPage para que siempre empiece en la página 1 al restaurar
                },
                selectedStudyIds: selectedStudyIds, // Guardar selecciones
                sortConfig: { ...this.sortConfig }, // Guardar configuración de ordenamiento
                timestamp: Date.now()
            };
            localStorage.setItem(this.storageKey, JSON.stringify(state));
        } catch (error) {
            console.warn('Error guardando estado persistente:', error);
        }
    }
    
    /**
     * Carga el estado persistente
     */
    loadPersistentState() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            if (!stored) return null;
            
            const state = JSON.parse(stored);
            
            // Verificar que el estado no sea muy antiguo (24 horas)
            const maxAge = 24 * 60 * 60 * 1000; // 24 horas en milisegundos
            if (Date.now() - state.timestamp > maxAge) {
                this.clearPersistentState();
                return null;
            }
            
            return state;
        } catch (error) {
            console.warn('Error cargando estado persistente:', error);
            this.clearPersistentState();
            return null;
        }
    }
    
    /**
     * Limpia el estado persistente
     */
    clearPersistentState() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch (error) {
            console.warn('Error limpiando estado persistente:', error);
        }
    }
    
    /**
     * Configura el ordenamiento por columnas
     */
    setupColumnSorting() {
        const sortableHeaders = document.querySelectorAll('.studies-table thead th.sortable');
        sortableHeaders.forEach(header => {
            header.addEventListener('click', () => {
                const column = header.getAttribute('data-column');
                if (column) {
                    this.sortByColumn(column);
                }
            });
        });
    }
    
    /**
     * Ordena por una columna específica
     */
    sortByColumn(column) {
        // Si se hace clic en la misma columna, invertir dirección
        if (this.sortConfig.column === column) {
            this.sortConfig.direction = this.sortConfig.direction === 'asc' ? 'desc' : 'asc';
        } else {
            // Si es una columna diferente, usar 'asc' por defecto
            this.sortConfig.column = column;
            this.sortConfig.direction = 'asc';
        }
        
        // Guardar configuración de ordenamiento
        this.savePersistentState();
        
        // Actualizar iconos en los headers
        this.updateSortIcons();
        
        // Re-renderizar estudios
        this.renderStudies();
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
     * Ordena estudios: primero por prioridad, luego por columna seleccionada
     */
    sortStudies(studies) {
        // Separar estudios con prioridad de los normales
        const studiesWithPriority = [];
        const normalStudies = [];
        
        studies.forEach(study => {
            const prioridad = this.studyPriorities[study.id] || study.prioridad || 'normal';
            if (emPrioridadAlta(prioridad)) {
                studiesWithPriority.push(study);
            } else {
                normalStudies.push(study);
            }
        });
        
        // Ordenar: urgente > promesa > pendiente
        const sortedWithPriority = studiesWithPriority.sort((a, b) => {
            const priorityA = this.studyPriorities[a.id] || a.prioridad || 'normal';
            const priorityB = this.studyPriorities[b.id] || b.prioridad || 'normal';
            const priorityOrder = { 'urgente': 3, 'promesa': 2, 'pendiente': 1, 'normal': 0 };
            return (priorityOrder[priorityB] || 0) - (priorityOrder[priorityA] || 0);
        });
        
        // Ordenar estudios normales por la columna seleccionada
        const sortedNormal = this.sortByColumnValue([...normalStudies], this.sortConfig.column, this.sortConfig.direction);
        
        // Combinar: estudios con prioridad primero, luego normales ordenados
        return [...sortedWithPriority, ...sortedNormal];
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
     * Restaura los valores del formulario
     */
    restoreFormValues() {
        const searchFilter = document.getElementById('searchFilter');
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const patientId = document.getElementById('patientId');
        const institutionFilter = document.getElementById('institutionFilter');
        
        if (searchFilter) searchFilter.value = this.currentFilters.search || '';
        if (dateFrom) dateFrom.value = this.currentFilters.dateFrom || '';
        if (dateTo) dateTo.value = this.currentFilters.dateTo || '';
        if (patientId) patientId.value = this.currentFilters.patientId || '';
        if (institutionFilter) institutionFilter.value = this.currentFilters.institutionName || '';

        const priorityFilter = document.getElementById('priorityFilter');
        if (priorityFilter) {
            priorityFilter.value = this.currentFilters.priority != null ? this.currentFilters.priority : '';
        }

        const assignmentScopeFilter = document.getElementById('assignmentScopeFilter');
        if (assignmentScopeFilter) {
            assignmentScopeFilter.value = this.currentFilters.assignmentScope != null ? this.currentFilters.assignmentScope : '';
        }

        const assignmentUserFilter = document.getElementById('assignmentUserFilter');
        if (assignmentUserFilter) {
            assignmentUserFilter.value = this.currentFilters.assignmentUserId != null ? this.currentFilters.assignmentUserId : '';
        }

        const reportStatusFilter = document.getElementById('reportStatusFilter');
        if (reportStatusFilter) {
            reportStatusFilter.value = this.currentFilters.reportStatus != null ? this.currentFilters.reportStatus : '';
        }

        this.normalizeModalityFilterChips();
        
        // Restaurar filtros de modalidad
        document.querySelectorAll('.modality-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        if (this.currentFilters.modalities.length === 0) {
            document.querySelector('.modality-btn[data-modality="all"]')?.classList.add('active');
        } else {
            this.currentFilters.modalities.forEach(modality => {
                document.querySelector(`.modality-btn[data-modality="${modality}"]`)?.classList.add('active');
            });
        }
        
        this.updateModalityCounter();
    }
    
    /**
     * Actualiza los botones de modalidad basándose en las modalidades disponibles en los estudios
     * y subíndices con la cantidad por modalidad según el listado filtrado (sin aplicar el filtro de modalidad).
     */
    updateAvailableModalities() {
        const modalityMap = {
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

        const setSubCount = (btn, n) => {
            const sup = btn.querySelector('.modality-count-sub');
            if (!sup) {
                return;
            }
            const num = typeof n === 'number' && !Number.isNaN(n) ? n : 0;
            sup.textContent = num > 0 ? String(num) : '';
        };

        if (!this.isDataLoaded || !this.studies || this.studies.length === 0) {
            const modalityButtonsEmpty = document.querySelectorAll('.modality-btn');
            modalityButtonsEmpty.forEach(btn => {
                const modality = btn.getAttribute('data-modality');
                if (modality === 'all') {
                    btn.style.display = 'inline-block';
                    btn.disabled = false;
                    setSubCount(btn, 0);
                } else {
                    btn.style.display = 'none';
                    btn.disabled = true;
                    setSubCount(btn, 0);
                }
            });
            return;
        }

        const studiesToCheck = this.studies.filter(study =>
            this.studyPassesLocalFilters(study, { skipModalityFilter: true })
        );

        if (!studiesToCheck || studiesToCheck.length === 0) {
            const modalityButtons = document.querySelectorAll('.modality-btn');
            modalityButtons.forEach(btn => {
                const modality = btn.getAttribute('data-modality');
                if (modality === 'all') {
                    btn.style.display = 'inline-block';
                    btn.disabled = false;
                    setSubCount(btn, 0);
                } else {
                    btn.style.display = 'none';
                    btn.disabled = true;
                    setSubCount(btn, 0);
                }
            });
            return;
        }

        const modalityCounts = {};
        studiesToCheck.forEach(study => {
            const tokens = this.getStudyModalityTokens(study);
            tokens.forEach(t => {
                modalityCounts[t] = (modalityCounts[t] || 0) + 1;
            });
        });

        const availableModalities = [...new Set(
            studiesToCheck.flatMap(study => this.getStudyModalityTokens(study))
        )];

        const modalityButtons = document.querySelectorAll('.modality-btn');

        const radiographyCodes = ['CR', 'RX', 'DX'];

        modalityButtons.forEach(btn => {
            const modality = btn.getAttribute('data-modality');

            if (modality === 'all') {
                btn.style.display = 'inline-block';
                btn.disabled = false;
                setSubCount(btn, studiesToCheck.length);
            } else if (modality === 'CR') {
                const isAvailable = radiographyCodes.some(code => availableModalities.includes(code));
                if (isAvailable) {
                    btn.style.display = 'inline-block';
                    btn.disabled = false;
                    const n = studiesToCheck.filter(s =>
                        this.getStudyModalityTokens(s).some(t => radiographyCodes.includes(t))
                    ).length;
                    setSubCount(btn, n);
                } else {
                    btn.style.display = 'none';
                    btn.disabled = true;
                    setSubCount(btn, 0);
                }
            } else {
                const modUpper = modality.toUpperCase();
                const dicomModality = (modalityMap[modality] || modality).toUpperCase();
                const isAvailable = availableModalities.includes(modUpper) || availableModalities.includes(dicomModality);

                if (isAvailable) {
                    btn.style.display = 'inline-block';
                    btn.disabled = false;
                    const n = (modalityCounts[modUpper] || 0) +
                        (modUpper !== dicomModality ? (modalityCounts[dicomModality] || 0) : 0);
                    setSubCount(btn, n);
                } else {
                    btn.style.display = 'none';
                    btn.disabled = true;
                    setSubCount(btn, 0);
                }
            }
        });

        console.log('Modalidades disponibles en estudios:', availableModalities, modalityCounts);
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
        
        // Guardar el valor actual seleccionado
        const currentValue = institutionFilter.value;
        
        // Limpiar opciones existentes (excepto la primera "Todas")
        institutionFilter.innerHTML = '<option value="">Todas las Instituciones</option>';
        
        // Agregar opciones de instituciones
        sortedInstitutions.forEach(institution => {
            const option = document.createElement('option');
            option.value = institution;
            option.textContent = institution;
            institutionFilter.appendChild(option);
        });
        
        // Restaurar el valor seleccionado si existe
        if (currentValue && sortedInstitutions.includes(currentValue)) {
            institutionFilter.value = currentValue;
        }
    }

    /**
     * Rellena el desplegable de usuarios (asignación principal y/o derivación)
     * según los estudios cargados en el listado actual.
     */
    updateAssignmentUserFilterOptions() {
        const sel = document.getElementById('assignmentUserFilter');
        if (!sel) {
            return;
        }

        const studyIds = new Set(this.studies.map(s => String(s.id)));
        const usersMap = new Map();

        Object.keys(this.studyAssignments || {}).forEach(sid => {
            if (!studyIds.has(String(sid))) {
                return;
            }
            const list = this.studyAssignments[sid] || [];
            list.forEach(a => {
                const id = String(a.user_id);
                const label = `${a.usuario_nombre || ''} ${a.usuario_apellido || ''}`.trim() || `Usuario ${id}`;
                usersMap.set(id, label);
            });
        });

        Object.keys(this.studySubassignments || {}).forEach(sid => {
            if (!studyIds.has(String(sid))) {
                return;
            }
            const list = this.studySubassignments[sid] || [];
            list.forEach(s => {
                const id = String(s.subassigned_to_user_id);
                const label = `${s.subassigned_user_nombre || ''} ${s.subassigned_user_apellido || ''}`.trim() || `Usuario ${id}`;
                usersMap.set(id, label);
            });
        });

        const current = this.currentFilters.assignmentUserId || '';
        sel.innerHTML = '<option value="">Usuario (asignado o derivado a)</option>';
        const sorted = [...usersMap.entries()].sort((a, b) => a[1].localeCompare(b[1], 'es'));
        sorted.forEach(([id, label]) => {
            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = label;
            sel.appendChild(opt);
        });

        if (current && usersMap.has(current)) {
            sel.value = current;
        } else {
            sel.value = '';
            this.currentFilters.assignmentUserId = '';
        }
    }
    
    /**
     * Aplica filtros de permisos a las pestañas del modal de antecedentes
     * Oculta las pestañas según los permisos del usuario
     * Funciona tanto con el modal estático como con el modal de emergencia
     */
    applyAntecedentsPermissionsToModal() {
        try {
            console.log('🔍 Aplicando filtros de permisos a modal de antecedentes...');
            console.log('Permisos:', {
                notas: this.hasAntecedentesNotas,
                imagenes: this.hasAntecedentesImagenes,
                camara: this.hasAntecedentesCamara,
                archivos: this.hasAntecedentesArchivos,
                qrMovil: this.hasAntecedentesQrMovil
            });
            
            // IDs posibles: modal estático (notes-tab) y modal emergencia (emergency-notes-tab)
            const tabIds = {
                notas: ['notes-tab', 'emergency-notes-tab'],
                imagenes: ['images-tab', 'emergency-images-tab'],
                camara: ['camera-tab', 'emergency-camera-tab'],
                archivos: ['files-tab', 'emergency-files-tab'],
                qrMovil: ['emergency-qr-tab']
            };
            
            const paneIds = {
                notas: ['notes', 'emergency-notes'],
                imagenes: ['images', 'emergency-images'],
                camara: ['camera', 'emergency-camera'],
                archivos: ['files', 'emergency-files'],
                qrMovil: ['emergency-qr']
            };
            
            // Procesar cada sección
            Object.keys(tabIds).forEach(section => {
                // Mapear nombres de sección a nombres de propiedades
                const propertyMap = {
                    'notas': 'hasAntecedentesNotas',
                    'imagenes': 'hasAntecedentesImagenes',
                    'camara': 'hasAntecedentesCamara',
                    'archivos': 'hasAntecedentesArchivos',
                    'qrMovil': 'hasAntecedentesQrMovil'
                };
                const hasPermission = this[propertyMap[section]];
                const tabIdList = tabIds[section];
                const paneIdList = paneIds[section];
                
                tabIdList.forEach(tabId => {
                    const tab = document.getElementById(tabId);
                    if (tab) {
                        // Buscar el li padre de manera más robusta
                        let tabItem = tab.closest('li.nav-item');
                        if (!tabItem) {
                            // Si no encuentra con closest, buscar el padre directo
                            tabItem = tab.parentElement;
                            // Si el padre no es li, buscar el li más cercano
                            while (tabItem && tabItem.tagName !== 'LI') {
                                tabItem = tabItem.parentElement;
                            }
                        }
                        
                        if (tabItem) {
                            if (!hasPermission) {
                                tabItem.style.display = 'none';
                                tabItem.setAttribute('data-permission-hidden', 'true');
                                console.log(`❌ Pestaña ${section} (${tabId}) oculta`);
                            } else {
                                tabItem.style.display = '';
                                tabItem.removeAttribute('data-permission-hidden');
                                console.log(`✅ Pestaña ${section} (${tabId}) visible`);
                            }
                        } else {
                            console.warn(`⚠️ No se encontró el elemento li padre para la pestaña ${tabId}`);
                        }
                    } else {
                        // El elemento no existe, puede ser normal si es un modal diferente
                        // console.log(`ℹ️ Pestaña ${tabId} no encontrada (puede ser normal si es otro modal)`);
                    }
                });
                
                paneIdList.forEach(paneId => {
                    const pane = document.getElementById(paneId);
                    if (pane) {
                        if (!hasPermission) {
                            pane.style.display = 'none';
                            pane.setAttribute('data-permission-hidden', 'true');
                        } else {
                            pane.style.display = '';
                            pane.removeAttribute('data-permission-hidden');
                        }
                    }
                });
            });
            
            // Activar la primera pestaña visible (modal estático)
            const staticTabsContainer = document.getElementById('antecedentsTabs');
            if (staticTabsContainer) {
                // Buscar todas las pestañas visibles (que no estén ocultas por permisos)
                const staticTabs = Array.from(staticTabsContainer.querySelectorAll('.nav-item')).filter(item => {
                    return item.style.display !== 'none' && !item.hasAttribute('data-permission-hidden');
                }).map(item => item.querySelector('.nav-link')).filter(link => link !== null);
                
                if (staticTabs.length > 0) {
                    // Remover active de todas las pestañas
                    staticTabsContainer.querySelectorAll('.nav-link').forEach(tab => {
                        tab.classList.remove('active');
                    });
                    const staticTabContent = document.getElementById('antecedentsTabContent');
                    if (staticTabContent) {
                        staticTabContent.querySelectorAll('.tab-pane').forEach(pane => {
                            pane.classList.remove('show', 'active');
                        });
                    }
                    
                    // Activar la primera pestaña visible
                    const firstVisibleTab = staticTabs[0];
                    const firstVisibleTarget = firstVisibleTab.getAttribute('data-bs-target');
                    firstVisibleTab.classList.add('active');
                    if (firstVisibleTarget) {
                        const firstPane = document.querySelector(firstVisibleTarget);
                        if (firstPane) {
                            firstPane.classList.add('show', 'active');
                        }
                    }
                }
            }
            
            // Activar la primera pestaña visible (modal emergencia)
            const emergencyTabsContainer = document.getElementById('emergencyAntecedentsModal');
            if (emergencyTabsContainer) {
                const emergencyTabs = Array.from(emergencyTabsContainer.querySelectorAll('.nav-item')).filter(item => {
                    return item.style.display !== 'none' && !item.hasAttribute('data-permission-hidden');
                }).map(item => item.querySelector('.nav-link')).filter(link => link !== null);
                
                if (emergencyTabs.length > 0) {
                    // Remover active de todas las pestañas
                    emergencyTabsContainer.querySelectorAll('.nav-link').forEach(tab => {
                        tab.classList.remove('active');
                    });
                    emergencyTabsContainer.querySelectorAll('.tab-pane').forEach(pane => {
                        pane.classList.remove('show', 'active');
                    });
                    
                    // Activar la primera pestaña visible
                    const firstVisibleTab = emergencyTabs[0];
                    const firstVisibleTarget = firstVisibleTab.getAttribute('data-bs-target');
                    firstVisibleTab.classList.add('active');
                    if (firstVisibleTarget) {
                        const firstPane = document.querySelector(firstVisibleTarget);
                        if (firstPane) {
                            firstPane.classList.add('show', 'active');
                        }
                    }
                }
            }
            
        } catch (error) {
            console.error('Error aplicando filtros de permisos:', error);
            console.error('Stack trace:', error.stack);
        }
    }
    
    /**
     * Abre el modal de antecedentes para un estudio
     */
    openAntecedentsModal(studyId) {
        try {
            console.log('=== INICIO openAntecedentsModal ===');
            console.log('studyId recibido:', studyId);
            console.log('this.studies:', this.studies);
            console.log('this.studies.length:', this.studies ? this.studies.length : 'undefined');
            console.log('hasPacsQuery:', this.hasPacsQuery);
            console.log('hasAntecedentes:', this.hasAntecedentes);
            
            // Verificar permiso de antecedentes
            if (!this.hasAntecedentes) {
                this.showError('No tienes permisos para acceder a los antecedentes médicos.');
                console.warn('⚠️ Acceso denegado: Usuario sin permiso de antecedentes');
                return;
            }
            
            // Buscar el estudio por ID
            const study = this.studies.find(s => s.id === studyId);
            if (!study) {
                console.error('Estudio no encontrado:', studyId);
                this.showError('Estudio no encontrado');
                return;
            }
            
            console.log('Abriendo modal de antecedentes para estudio:', studyId);
            console.log('Estudio encontrado:', study);
            
            // Guardar estudio actual
            this.currentAntecedents = study;
            
            // Limpiar cualquier modal anterior que pueda estar abierto
            this.cleanupModal();
            
            // Verificar permisos específicos de secciones
            console.log('Permisos de secciones de antecedentes:', {
                notas: this.hasAntecedentesNotas,
                imagenes: this.hasAntecedentesImagenes,
                camara: this.hasAntecedentesCamara,
                archivos: this.hasAntecedentesArchivos,
                qrMovil: this.hasAntecedentesQrMovil
            });
            
            // Si el usuario NO tiene PACS QUERY, usar modal simplificado (solo lectura/existentes)
            // Si tiene PACS QUERY, usar modal completo (emergencia) con todas las opciones
            if (!this.hasPacsQuery) {
                console.log('Usuario SIN PACS QUERY: Usando modal simplificado (solo lectura)');
                this.createSimpleAntecedentsModal(study);
                
                // Cargar antecedentes existentes
                setTimeout(async () => {
                    await this.loadExistingAntecedentsForSimpleModal(study.id);
                }, 500);
            } else {
                console.log('Usuario CON PACS QUERY: Usando modal completo (emergencia)');
                console.log('🔍 Permisos ANTES de crear modal:', {
                    hasAntecedentesNotas: this.hasAntecedentesNotas,
                    hasAntecedentesImagenes: this.hasAntecedentesImagenes,
                    hasAntecedentesCamara: this.hasAntecedentesCamara,
                    hasAntecedentesArchivos: this.hasAntecedentesArchivos,
                    hasAntecedentesQrMovil: this.hasAntecedentesQrMovil
                });
                
                this.createEmergencyModal(study);
            
                // Cargar antecedentes existentes después de crear el modal
                setTimeout(async () => {
                    await this.loadExistingAntecedents();
                }, 500);
            }
            
            // Aplicar filtros de permisos a cualquier modal estático que pueda existir
            setTimeout(() => {
                console.log('🔍 Aplicando permisos a modal después de abrir...');
                this.applyAntecedentsPermissionsToModal();
            }, 100);
            
            // También aplicar después de un tiempo adicional por si el modal se renderiza más tarde
            setTimeout(() => {
                console.log('🔍 Re-aplicando permisos a modal (verificación adicional)...');
                this.applyAntecedentsPermissionsToModal();
            }, 500);
            
            console.log('Modal de antecedentes abierto exitosamente');
            console.log('=== FIN openAntecedentsModal ===');
            
        } catch (error) {
            console.error('Error abriendo modal de antecedentes:', error);
            console.error('Stack trace:', error.stack);
            console.log('=== FIN openAntecedentsModal ===');
            this.showError('Error abriendo modal de antecedentes: ' + error.message);
        }
    }
    
    /**
     * Configura los event listeners del modal de antecedentes
     */
    setupAntecedentsEventListeners() {
        try {
            console.log('Configurando event listeners del modal de antecedentes');
            
            // Verificar que tenemos estudios cargados
            if (!this.studies || this.studies.length === 0) {
                console.error('No hay estudios cargados');
                this.showError('No hay estudios cargados. Por favor, busque estudios primero.');
                return;
            }
            
            const study = this.studies.find(s => s.id === studyId);
            console.log('Estudio encontrado:', study);
            
            if (!study) {
                console.error('Estudio no encontrado con ID:', studyId);
                console.log('IDs disponibles:', this.studies.map(s => s.id));
                this.showError('Estudio no encontrado');
                return;
            }
            
            this.selectedStudy = study;
            console.log('Estudio seleccionado:', this.selectedStudy);
            
            // Limpiar cualquier modal anterior que pueda estar abierto
            this.cleanupModal();
            
            // Crear directamente el modal de emergencia (que funciona correctamente)
            console.log('Creando modal de emergencia directamente...');
            this.createEmergencyModal(study);
            
            console.log('Modal de antecedentes abierto exitosamente');
            console.log('=== FIN openAntecedentsModal ===');
            return;
            
            // Actualizar información del estudio en el modal
            const patientNameEl = document.getElementById('antecedentsPatientName');
            const patientIdEl = document.getElementById('antecedentsPatientId');
            const modalityEl = document.getElementById('antecedentsModality');
            const studyDateEl = document.getElementById('antecedentsStudyDate');
            const studyDescEl = document.getElementById('antecedentsStudyDescription');
            
            console.log('Elementos del modal encontrados:', {
                patientNameEl: !!patientNameEl,
                patientIdEl: !!patientIdEl,
                modalityEl: !!modalityEl,
                studyDateEl: !!studyDateEl,
                studyDescEl: !!studyDescEl
            });
            
            if (patientNameEl) patientNameEl.textContent = study.patient_name || 'N/A';
            if (patientIdEl) patientIdEl.textContent = study.patient_id || 'N/A';
            if (modalityEl) modalityEl.textContent = study.modality || 'N/A';
            if (studyDateEl) studyDateEl.textContent = this.formatDate(study.date || study.study_date);
            if (studyDescEl) studyDescEl.textContent = study.study_description || 'N/A';
            
            console.log('Información del estudio actualizada en el modal');
            
            // Limpiar formulario
            this.clearAntecedentsForm();
            console.log('Formulario limpiado');
            
            // Configurar event listeners del modal
            this.setupAntecedentsEventListeners();
            console.log('Event listeners configurados');
            
            // Cargar antecedentes existentes (sin bloquear el modal)
            this.loadAntecedents(studyId).catch(error => {
                console.warn('Error cargando antecedentes existentes:', error);
            });
            
            // Verificar que Bootstrap está disponible
            console.log('Bootstrap disponible:', typeof bootstrap !== 'undefined');
            
            // Mostrar modal
            console.log('Creando instancia del modal Bootstrap...');
            const modal = new bootstrap.Modal(modalElement);
            console.log('Instancia del modal creada:', modal);
            
            console.log('Mostrando modal...');
            modal.show();
         
         // Debug adicional después de mostrar el modal múltiple
         setTimeout(() => {
             const modalAfterShow = document.getElementById('confirmationModal');
             const computedStyles = window.getComputedStyle(modalAfterShow);
             console.log('🔍 Debug - Modal múltiple después de show():', {
                 display: computedStyles.display,
                 visibility: computedStyles.visibility,
                 opacity: computedStyles.opacity,
                 zIndex: computedStyles.zIndex,
                 position: computedStyles.position,
                 top: computedStyles.top,
                 left: computedStyles.left
             });
             
             // Verificar si el modal está realmente visible
             const rect = modalAfterShow.getBoundingClientRect();
             console.log('🔍 Debug - Posición del modal múltiple:', rect);
             
             // Forzar estilos si es necesario
             if (computedStyles.display === 'none' || computedStyles.visibility === 'hidden') {
                 console.log('⚠️ Modal múltiple no visible, forzando estilos...');
                 modalAfterShow.style.display = 'block !important';
                 modalAfterShow.style.visibility = 'visible !important';
                 modalAfterShow.style.opacity = '1 !important';
             }
         }, 200);
            
            // Debug adicional después de mostrar el modal individual
            setTimeout(() => {
                const modalAfterShow = document.getElementById('confirmationModal');
                const computedStyles = window.getComputedStyle(modalAfterShow);
                console.log('🔍 Debug - Modal individual después de show():', {
                    display: computedStyles.display,
                    visibility: computedStyles.visibility,
                    opacity: computedStyles.opacity,
                    zIndex: computedStyles.zIndex,
                    position: computedStyles.position,
                    top: computedStyles.top,
                    left: computedStyles.left
                });
                
                // Verificar si el modal está realmente visible
                const rect = modalAfterShow.getBoundingClientRect();
                console.log('🔍 Debug - Posición del modal individual:', rect);
                
                // Forzar estilos si es necesario
                if (computedStyles.display === 'none' || computedStyles.visibility === 'hidden') {
                    console.log('⚠️ Modal individual no visible, forzando estilos...');
                    modalAfterShow.style.display = 'block !important';
                    modalAfterShow.style.visibility = 'visible !important';
                    modalAfterShow.style.opacity = '1 !important';
                }
            }, 200);
            console.log('modal.show() ejecutado');
            
            // Método alternativo si Bootstrap falla
            setTimeout(() => {
                console.log('Verificando si el modal es visible...');
                const isVisible = modalElement.classList.contains('show') || 
                                 modalElement.style.display === 'block' ||
                                 window.getComputedStyle(modalElement).display === 'block';
                console.log('Modal visible:', isVisible);
                
                if (!isVisible) {
                    console.log('Modal no visible, aplicando método alternativo...');
                    
                    // Forzar visibilidad del modal
                    modalElement.style.display = 'block';
                    modalElement.style.position = 'fixed';
                    modalElement.style.top = '0';
                    modalElement.style.left = '0';
                    modalElement.style.width = '100%';
                    modalElement.style.height = '100%';
                    modalElement.style.zIndex = '1055';
                    modalElement.classList.add('show');
                    modalElement.setAttribute('aria-hidden', 'false');
                    modalElement.setAttribute('aria-modal', 'true');
                    
                    // Agregar clase al body para evitar scroll
                    document.body.classList.add('modal-open');
                    
                    // Crear y agregar backdrop
                    const existingBackdrop = document.getElementById('antecedentsModalBackdrop');
                    if (existingBackdrop) {
                        existingBackdrop.remove();
                    }
                    
                    const backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop fade show';
                    backdrop.id = 'antecedentsModalBackdrop';
                    backdrop.style.zIndex = '1050';
                    document.body.appendChild(backdrop);
                    
                    // Asegurar que el modal-dialog esté centrado y visible
                    const modalDialog = modalElement.querySelector('.modal-dialog');
                    if (modalDialog) {
                        modalDialog.style.margin = '1.75rem auto';
                        modalDialog.style.maxWidth = '90%';
                        modalDialog.style.position = 'relative';
                        modalDialog.style.zIndex = '1056';
                        modalDialog.style.display = 'block';
                    }
                    
                    // Asegurar que el modal-content sea visible
                    const modalContent = modalElement.querySelector('.modal-content');
                    if (modalContent) {
                        modalContent.style.position = 'relative';
                        modalContent.style.zIndex = '1057';
                        modalContent.style.display = 'block';
                        modalContent.style.backgroundColor = 'white';
                        modalContent.style.border = '1px solid #dee2e6';
                        modalContent.style.borderRadius = '0.375rem';
                        modalContent.style.boxShadow = '0 0.5rem 1rem rgba(0, 0, 0, 0.15)';
                    }
                    
                    // Forzar visibilidad de todos los elementos del modal
                    const allModalElements = modalElement.querySelectorAll('*');
                    allModalElements.forEach(el => {
                        if (window.getComputedStyle(el).display === 'none') {
                            el.style.display = 'block';
                        }
                    });
                    
                    // Agregar estilos de debug más agresivos
                    modalElement.style.border = '5px solid red !important';
                    modalElement.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
                    
                    console.log('Método alternativo aplicado exitosamente');
                    console.log('Modal element después del método alternativo:', modalElement);
                    console.log('Modal dialog:', modalDialog);
                    console.log('Modal content:', modalContent);
                    console.log('Backdrop creado:', backdrop);
                    
                    // Verificar visibilidad después de aplicar estilos
                    setTimeout(() => {
                        const computedStyle = window.getComputedStyle(modalElement);
                        console.log('Computed styles del modal:', {
                            display: computedStyle.display,
                            visibility: computedStyle.visibility,
                            opacity: computedStyle.opacity,
                            position: computedStyle.position,
                            zIndex: computedStyle.zIndex
                        });
                        
                        const dialogComputedStyle = modalDialog ? window.getComputedStyle(modalDialog) : null;
                        console.log('Computed styles del dialog:', dialogComputedStyle ? {
                            display: dialogComputedStyle.display,
                            visibility: dialogComputedStyle.visibility,
                            opacity: dialogComputedStyle.opacity,
                            position: dialogComputedStyle.position,
                            zIndex: dialogComputedStyle.zIndex
                        } : 'Dialog no encontrado');
                    }, 50);
                    
                    // Método de emergencia: crear modal completamente nuevo si sigue sin ser visible
                    setTimeout(() => {
                        const isStillVisible = window.getComputedStyle(modalElement).display === 'block' && 
                                             window.getComputedStyle(modalElement).visibility !== 'hidden' &&
                                             window.getComputedStyle(modalElement).opacity !== '0';
                        
                        console.log('Verificando visibilidad final:', isStillVisible);
                        
                        // Siempre crear modal de emergencia para asegurar funcionalidad
                        console.log('Creando modal de emergencia para garantizar funcionalidad...');
                        this.createEmergencyModal(study);
                    }, 200);
                    
                } else {
                    console.log('Modal ya es visible, no se necesita método alternativo');
                }
            }, 100);
            
            console.log('Modal de antecedentes abierto exitosamente');
            
        } catch (error) {
            console.error('Error abriendo modal de antecedentes:', error);
            console.error('Stack trace:', error.stack);
            this.showError('Error abriendo modal de antecedentes: ' + error.message);
        }
        
        console.log('=== FIN openAntecedentsModal ===');
    }
    
    /**
     * Configura los event listeners del modal de antecedentes
     */
    setupAntecedentsEventListeners() {
        try {
            console.log('Configurando event listeners del modal de antecedentes');
            
            // Botón guardar antecedentes
            const saveBtn = document.getElementById('saveAntecedentsBtn');
            if (saveBtn) {
                saveBtn.onclick = () => this.saveAntecedents();
                console.log('Event listener guardar configurado');
            } else {
                console.warn('Botón guardar no encontrado');
            }
            
            // Subida de imágenes
            const imageUpload = document.getElementById('imageUpload');
            if (imageUpload) {
                imageUpload.onchange = (e) => this.handleImageUpload(e);
                console.log('Event listener imagen configurado');
            } else {
                console.warn('Input imagen no encontrado');
            }
            
            // Subida de archivos
            const fileUpload = document.getElementById('fileUpload');
            if (fileUpload) {
                fileUpload.onchange = (e) => this.handleFileUpload(e);
                console.log('Event listener archivo configurado');
            } else {
                console.warn('Input archivo no encontrado');
            }
            
            // Controles de cámara
            const startCameraBtn = document.getElementById('startCameraBtn');
            const captureBtn = document.getElementById('captureBtn');
            const stopCameraBtn = document.getElementById('stopCameraBtn');
            
            if (startCameraBtn) {
                startCameraBtn.onclick = () => this.startCamera();
                console.log('Event listener iniciar cámara configurado');
            }
            if (captureBtn) {
                captureBtn.onclick = () => this.capturePhoto();
                console.log('Event listener capturar configurado');
            }
            if (stopCameraBtn) {
                stopCameraBtn.onclick = () => this.stopCamera();
                console.log('Event listener detener cámara configurado');
            }
            
            // Limpiar al cerrar modal
            const modal = document.getElementById('antecedentsModal');
            if (modal) {
                // Remover listener anterior si existe
                modal.removeEventListener('hidden.bs.modal', this.handleModalClose);
                
                // Agregar nuevo listener
                this.handleModalClose = () => {
                    this.stopCamera();
                    this.clearAntecedentsForm();
                    this.cleanupModal();
                };
                modal.addEventListener('hidden.bs.modal', this.handleModalClose);
                console.log('Event listener cerrar modal configurado');
            } else {
                console.warn('Modal no encontrado para configurar event listener');
            }
            
            console.log('Event listeners del modal configurados exitosamente');
            
        } catch (error) {
            console.error('Error configurando event listeners:', error);
        }
    }
    
    /**
     * Carga antecedentes existentes para un estudio
     */
    async loadAntecedents(studyId) {
        try {
            console.warn('[DEPRECATED] loadAntecedents(studyId) redirige a loadExistingAntecedents().');
            if (studyId) {
                this.currentAntecedents = { id: studyId };
            }

            // Flujo unificado: carga "Existentes" y sincroniza emergencyNotes.
            await this.loadExistingAntecedents();

            // Compatibilidad legacy: si aún existe el textarea viejo, mantenerlo sincronizado.
            const legacyNotesTextarea = document.getElementById('antecedentsNotes');
            const emergencyNotesTextarea = document.getElementById('emergencyNotes');
            if (legacyNotesTextarea && emergencyNotesTextarea) {
                legacyNotesTextarea.value = emergencyNotesTextarea.value || '';
            }
        } catch (error) {
            console.error('Error cargando antecedentes:', error);
            this.showError('Error cargando antecedentes: ' + error.message);
        }
    }
    
    /**
     * Guarda los antecedentes del estudio
     */
    async saveAntecedents() {
        if (!this.selectedStudy) {
            this.showError('No hay estudio seleccionado');
            return;
        }
        
        try {
            console.warn('[DEPRECATED] saveAntecedents() usa flujo unificado saveAntecedentsNotes().');

            const emergencyNotesField = document.getElementById('emergencyNotes');
            const legacyNotesField = document.getElementById('antecedentsNotes');
            const notes = emergencyNotesField
                ? emergencyNotesField.value
                : (legacyNotesField ? legacyNotesField.value : '');

            await this.saveAntecedentsNotes(this.selectedStudy.id, notes);
            await this.loadAntecedents(this.selectedStudy.id);
            this.showSuccess('Antecedentes guardados exitosamente');
            
        } catch (error) {
            console.error('Error guardando antecedentes:', error);
            this.showError('Error guardando antecedentes: ' + error.message);
        }
    }
    
    /**
     * Maneja la subida de imágenes
     */
    async handleImageUpload(event) {
        const files = event.target.files;
        if (!files || files.length === 0) return;
        
        try {
            const currentUser = this.getCurrentUser();
            if (!currentUser) {
                throw new Error('No se pudo obtener información del usuario actual');
            }
            
            for (let file of files) {
                await this.uploadFile(file, 'image', currentUser.id);
            }
            
            // Recargar antecedentes para mostrar archivos
            await this.loadAntecedents(this.selectedStudy.id);
            
        } catch (error) {
            console.error('Error subiendo imágenes:', error);
            this.showError('Error subiendo imágenes: ' + error.message);
        }
    }
    
    /**
     * Maneja la subida de archivos
     */
    async handleFileUpload(event) {
        const files = event.target.files;
        if (!files || files.length === 0) return;
        
        try {
            const currentUser = this.getCurrentUser();
            if (!currentUser) {
                throw new Error('No se pudo obtener información del usuario actual');
            }
            
            for (let file of files) {
                await this.uploadFile(file, 'document', currentUser.id);
            }
            
            // Recargar antecedentes para mostrar archivos
            await this.loadAntecedents(this.selectedStudy.id);
            
        } catch (error) {
            console.error('Error subiendo archivos:', error);
            this.showError('Error subiendo archivos: ' + error.message);
        }
    }
    
    /**
     * Sube un archivo al servidor
     */
    async uploadFile(file, fileType, createdBy) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('study_id', this.selectedStudy.id);
        formData.append('file_type', fileType);
        formData.append('created_by', createdBy);
        
        const response = await fetch(`${this.apiBaseUrl}upload_antecedents_file.php`, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Error subiendo archivo');
        }
        
        return result.data;
    }
    
    /**
     * Inicia la cámara
     */
    async startCamera() {
        try {
            this.cameraStream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                } 
            });
            
            const video = document.getElementById('cameraVideo');
            const placeholder = document.getElementById('cameraPlaceholder');
            
            video.srcObject = this.cameraStream;
            video.style.display = 'block';
            placeholder.style.display = 'none';
            
            // Habilitar controles
            document.getElementById('captureBtn').disabled = false;
            document.getElementById('stopCameraBtn').disabled = false;
            document.getElementById('startCameraBtn').disabled = true;
            
        } catch (error) {
            console.error('Error accediendo a la cámara:', error);
            this.showError('Error accediendo a la cámara: ' + error.message);
        }
    }
    
    /**
     * Captura una foto con la cámara
     */
    capturePhoto() {
        const video = document.getElementById('cameraVideo');
        const canvas = document.getElementById('cameraCanvas');
        const ctx = canvas.getContext('2d');
        
        // Configurar canvas con las dimensiones del video
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        // Dibujar el frame actual del video en el canvas
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        // Convertir a blob
        canvas.toBlob(async (blob) => {
            try {
                const currentUser = this.getCurrentUser();
                if (!currentUser) {
                    throw new Error('No se pudo obtener información del usuario actual');
                }
                
                // Crear archivo desde blob
                const file = new File([blob], `captura_${Date.now()}.jpg`, { type: 'image/jpeg' });
                
                // Subir archivo
                await this.uploadFile(file, 'camera_capture', currentUser.id);
                
                // Agregar a lista de capturas
                this.capturedImages.push({
                    name: file.name,
                    url: URL.createObjectURL(blob),
                    timestamp: new Date()
                });
                
                this.displayCapturedImages();
                
                // Recargar antecedentes para mostrar archivos
                await this.loadAntecedents(this.selectedStudy.id);
                
            } catch (error) {
                console.error('Error capturando foto:', error);
                this.showError('Error capturando foto: ' + error.message);
            }
        }, 'image/jpeg', 0.8);
    }
    
    /**
     * Detiene la cámara
     */
    stopCamera() {
        if (this.cameraStream) {
            this.cameraStream.getTracks().forEach(track => track.stop());
            this.cameraStream = null;
        }
        
        const video = document.getElementById('cameraVideo');
        const placeholder = document.getElementById('cameraPlaceholder');
        
        video.style.display = 'none';
        placeholder.style.display = 'block';
        
        // Deshabilitar controles
        document.getElementById('captureBtn').disabled = true;
        document.getElementById('stopCameraBtn').disabled = true;
        document.getElementById('startCameraBtn').disabled = false;
    }
    
    /**
     * Muestra las imágenes capturadas
     */
    displayCapturedImages() {
        const container = document.getElementById('capturedImages');
        if (!container) return;
        
        if (this.capturedImages.length === 0) {
            container.innerHTML = '<div class="text-muted text-center small">No hay fotos capturadas</div>';
            return;
        }
        
        let html = '';
        this.capturedImages.forEach((img, index) => {
            html += `
                <div class="d-flex align-items-center mb-2 p-2 border rounded">
                    <img src="${img.url}" class="me-2" style="width: 50px; height: 50px; object-fit: cover;">
                    <div class="flex-grow-1">
                        <small class="fw-semibold">${img.name}</small><br>
                        <small class="text-muted">${img.timestamp.toLocaleString()}</small>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
    
    /**
     * Muestra los archivos subidos
     */
    displayUploadedFiles(files) {
        // Mostrar imágenes
        const imagesContainer = document.getElementById('uploadedImages');
        if (imagesContainer) {
            const imageFiles = files.filter(f => f.file_type === 'image' || f.file_type === 'camera_capture');
            
            if (imageFiles.length === 0) {
                imagesContainer.innerHTML = `
                    <div class="text-muted text-center">
                        <i class="fas fa-image fa-2x mb-2"></i><br>
                        No hay imágenes adjuntas
                    </div>
                `;
            } else {
                let html = '';
                imageFiles.forEach(file => {
                    html += `
                        <div class="d-flex align-items-center mb-2 p-2 border rounded">
                            <img src="${file.file_path.replace('../', '')}" class="me-2" style="width: 50px; height: 50px; object-fit: cover;">
                            <div class="flex-grow-1">
                                <small class="fw-semibold">${file.file_name}</small><br>
                                <small class="text-muted">${(file.file_size / 1024).toFixed(1)} KB</small>
                            </div>
                        </div>
                    `;
                });
                imagesContainer.innerHTML = html;
            }
        }
        
        // Mostrar archivos
        const filesContainer = document.getElementById('uploadedFiles');
        if (filesContainer) {
            const documentFiles = files.filter(f => f.file_type === 'document');
            
            if (documentFiles.length === 0) {
                filesContainer.innerHTML = `
                    <div class="text-muted text-center">
                        <i class="fas fa-file fa-2x mb-2"></i><br>
                        No hay archivos adjuntos
                    </div>
                `;
            } else {
                let html = '';
                documentFiles.forEach(file => {
                    const icon = file.mime_type.includes('pdf') ? 'fa-file-pdf' : 'fa-file';
                    html += `
                        <div class="d-flex align-items-center mb-2 p-2 border rounded">
                            <i class="fas ${icon} fa-2x me-2 text-primary"></i>
                            <div class="flex-grow-1">
                                <small class="fw-semibold">${file.file_name}</small><br>
                                <small class="text-muted">${(file.file_size / 1024).toFixed(1)} KB</small>
                            </div>
                        </div>
                    `;
                });
                filesContainer.innerHTML = html;
            }
        }
    }
    
    /**
     * Limpia el formulario de antecedentes
     */
    clearAntecedentsForm() {
        const legacyNotes = document.getElementById('antecedentsNotes');
        const emergencyNotes = document.getElementById('emergencyNotes');
        if (legacyNotes) legacyNotes.value = '';
        if (emergencyNotes) emergencyNotes.value = '';

        const imageUpload = document.getElementById('imageUpload');
        const fileUpload = document.getElementById('fileUpload');
        if (imageUpload) imageUpload.value = '';
        if (fileUpload) fileUpload.value = '';
        this.capturedImages = [];
        this.displayCapturedImages();
        
        // Limpiar contenedores de archivos
        const uploadedImages = document.getElementById('uploadedImages');
        const uploadedFiles = document.getElementById('uploadedFiles');
        if (uploadedImages) {
            uploadedImages.innerHTML = `
                <div class="text-muted text-center">
                    <i class="fas fa-image fa-2x mb-2"></i><br>
                    No hay imágenes adjuntas
                </div>
            `;
        }
        
        if (uploadedFiles) {
            uploadedFiles.innerHTML = `
                <div class="text-muted text-center">
                    <i class="fas fa-file fa-2x mb-2"></i><br>
                    No hay archivos adjuntos
                </div>
            `;
        }
    }
    
    /**
     * Crea un modal simplificado de antecedentes (solo lectura, sin edición)
     * Para usuarios SIN PACS QUERY
     */
    createSimpleAntecedentsModal(study) {
        try {
            console.log('Creando modal simplificado de antecedentes (solo lectura)...');
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('simpleAntecedentsModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Crear nuevo modal
            const modal = document.createElement('div');
            modal.id = 'simpleAntecedentsModal';
            modal.className = 'modal fade';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'simpleAntecedentsModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = `
                z-index: 10000 !important;
                position: fixed !important;
            `;
            
            modal.innerHTML = `
                <div class="modal-dialog modal-xl" style="z-index: 10001 !important; position: relative;">
                    <div class="modal-content" style="z-index: 10002 !important; pointer-events: auto !important;">
                        <div class="modal-header">
                            <h5 class="modal-title" id="simpleAntecedentsModalLabel">
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
                                            <strong>Fecha:</strong> ${this.formatDate(study.date || study.study_date)}<br>
                                            <strong>Descripción:</strong> ${study.study_description || 'N/A'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pestañas -->
                            <ul class="nav nav-tabs" id="simpleAntecedentsTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="simple-existing-tab" data-bs-toggle="tab" 
                                            data-bs-target="#simple-existing" type="button" role="tab">
                                        <i class="fas fa-folder-open me-2"></i>Existentes
                                    </button>
                                </li>
                            </ul>
                            
                            <div class="tab-content" id="simpleAntecedentsTabContent">
                                <!-- Pestaña de Antecedentes Existentes -->
                                <div class="tab-pane fade show active" id="simple-existing" role="tabpanel">
                                    <div class="mt-3">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="mb-0">
                                                <i class="fas fa-folder-open me-2"></i>Antecedentes Existentes
                                            </h6>
                                            <button class="btn btn-outline-primary btn-sm" onclick="derivacionesManager.refreshSimpleExistingAntecedents()">
                                                <i class="fas fa-sync-alt me-1"></i>Actualizar
                                            </button>
                                        </div>
                                        
                                        <!-- Información de antecedentes -->
                                        <div id="simple-existing-antecedents-info" class="alert alert-info" style="display: none;">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <strong>Notas:</strong>
                                                    <div id="simple-existing-notes" class="mt-1"></div>
                                                </div>
                                                <div class="col-md-6">
                                                    <strong>Creado por:</strong> <span id="simple-existing-created-by"></span><br>
                                                    <strong>Fecha:</strong> <span id="simple-existing-created-date"></span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Lista de archivos existentes -->
                                        <div id="simple-existing-files-container">
                                            <div class="text-center text-muted py-4" id="simple-no-existing-files">
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
            
            // Limpiar instancia previa de Bootstrap modal
            const existingInstance = bootstrap.Modal.getInstance(modal);
            if (existingInstance) {
                existingInstance.dispose();
            }
            
            // Mostrar modal
            const bsModal = new bootstrap.Modal(modal, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            
            // Agregar cleanup al cerrar
            const cleanupHandler = () => {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
                try {
                    bsModal.dispose();
                    modal.remove();
                } catch (e) {
                    console.warn('Error limpiando modal:', e);
                }
            };
            
            modal.removeEventListener('hidden.bs.modal', cleanupHandler);
            modal.addEventListener('hidden.bs.modal', cleanupHandler);
            
            bsModal.show();
            
            console.log('Modal simplificado de antecedentes creado exitosamente');
            
        } catch (error) {
            console.error('Error creando modal simplificado de antecedentes:', error);
        }
    }
    
    /**
     * Carga antecedentes existentes para el modal simplificado
     */
    async loadExistingAntecedentsForSimpleModal(studyId) {
        try {
            console.log('Cargando antecedentes existentes para modal simplificado:', studyId);
            
            const response = await fetch(`${this.apiBaseUrl}study_antecedents.php?study_id=${studyId}`);
            const result = await response.json();
            
            console.log('Respuesta del API:', result);
            
            if (result.success && result.data.antecedents) {
                console.log('Antecedentes encontrados:', result.data.antecedents);
                console.log('Archivos encontrados:', result.data.files);
                this.displaySimpleExistingAntecedents(result.data.antecedents, result.data.files);
            } else {
                console.log('No hay antecedentes o respuesta no exitosa');
                this.hideSimpleExistingAntecedents();
            }
            
        } catch (error) {
            console.error('Error cargando antecedentes existentes:', error);
            this.hideSimpleExistingAntecedents();
        }
    }
    
    /**
     * Muestra antecedentes existentes en el modal simplificado
     */
    displaySimpleExistingAntecedents(antecedents, files) {
        try {
            console.log('Mostrando antecedentes existentes:', antecedents, files);
            
            // Mostrar información de antecedentes
            const infoDiv = document.getElementById('simple-existing-antecedents-info');
            const notesDiv = document.getElementById('simple-existing-notes');
            const createdBySpan = document.getElementById('simple-existing-created-by');
            const createdDateSpan = document.getElementById('simple-existing-created-date');
            
            if (antecedents && infoDiv && notesDiv && createdBySpan && createdDateSpan) {
                // Mostrar las notas
                const notesText = antecedents.notes || 'Sin notas';
                notesDiv.textContent = notesText;
                
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
                this.displaySimpleExistingFiles(files);
            } else {
                console.log('No hay archivos para mostrar');
                this.hideSimpleExistingFiles();
            }
            
        } catch (error) {
            console.error('Error mostrando antecedentes existentes:', error);
        }
    }
    
    /**
     * Muestra archivos existentes usando FileViewer (para modal simplificado)
     */
    displaySimpleExistingFiles(files) {
        const container = document.getElementById('simple-existing-files-container');
        const noFilesDiv = document.getElementById('simple-no-existing-files');
        
        if (!container) return;
        
        // Ocultar mensaje de "no hay archivos"
        if (noFilesDiv) {
            noFilesDiv.style.display = 'none';
        }
        
        // Remover lista anterior si existe
        const existingList = document.getElementById('simple-existing-files-list');
        if (existingList) {
            existingList.remove();
        }
        
        // Crear lista de archivos usando FileViewer
        const filesList = document.createElement('div');
        filesList.id = 'simple-existing-files-list';
        filesList.className = 'row';
        
        // Verificar que FileViewer esté disponible
        if (typeof FileViewer === 'undefined') {
            console.error('FileViewer no está disponible');
            this.displaySimpleExistingFilesLegacy(files);
            return;
        }
        
        files.forEach(file => {
            const fileCard = document.createElement('div');
            fileCard.className = 'col-md-4 mb-3';
            
            // Construir la URL completa del archivo
            const fileUrl = this.buildFileUrl(file);
            
            // Usar FileViewer para crear la visualización del archivo
            const fileViewer = FileViewer.createFileCard({
                fileName: file.file_name,
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
        
        return fileUrl;
    }
    
    /**
     * Método legacy para mostrar archivos (fallback)
     */
    displaySimpleExistingFilesLegacy(files) {
        const container = document.getElementById('simple-existing-files-container');
        const noFilesDiv = document.getElementById('simple-no-existing-files');
        
        if (!container) return;
        
        // Ocultar mensaje de "no hay archivos"
        if (noFilesDiv) {
            noFilesDiv.style.display = 'none';
        }
        
        // Remover lista anterior si existe
        const existingList = document.getElementById('simple-existing-files-list');
        if (existingList) {
            existingList.remove();
        }
        
        // Crear lista de archivos
        const filesList = document.createElement('div');
        filesList.id = 'simple-existing-files-list';
        filesList.className = 'row';
        
        files.forEach(file => {
            const fileCard = document.createElement('div');
            fileCard.className = 'col-md-4 mb-3';
            
            // Construir la URL completa del archivo
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
     * Oculta los archivos existentes (modal simplificado)
     */
    hideSimpleExistingFiles() {
        const filesList = document.getElementById('simple-existing-files-list');
        const noFilesDiv = document.getElementById('simple-no-existing-files');
        
        if (filesList) filesList.remove();
        if (noFilesDiv) noFilesDiv.style.display = 'block';
    }
    
    /**
     * Oculta los antecedentes existentes (modal simplificado)
     */
    hideSimpleExistingAntecedents() {
        const infoDiv = document.getElementById('simple-existing-antecedents-info');
        const noFilesDiv = document.getElementById('simple-no-existing-files');
        
        if (infoDiv) infoDiv.style.display = 'none';
        if (noFilesDiv) noFilesDiv.style.display = 'block';
    }
    
    /**
     * Actualiza los antecedentes existentes (modal simplificado)
     */
    async refreshSimpleExistingAntecedents() {
        try {
            if (this.currentAntecedents && this.currentAntecedents.id) {
                await this.loadExistingAntecedentsForSimpleModal(this.currentAntecedents.id);
            } else {
                console.warn('No hay estudio actual para actualizar antecedentes');
            }
        } catch (error) {
            console.error('Error actualizando antecedentes:', error);
        }
    }
    
    /**
     * Crea un modal de emergencia completamente nuevo
     */
    createEmergencyModal(study) {
        try {
            console.log('=== CREANDO MODAL DE EMERGENCIA ===');
            console.log('Permisos actuales al crear modal:', {
                hasAntecedentesNotas: this.hasAntecedentesNotas,
                hasAntecedentesImagenes: this.hasAntecedentesImagenes,
                hasAntecedentesCamara: this.hasAntecedentesCamara,
                hasAntecedentesArchivos: this.hasAntecedentesArchivos,
                hasAntecedentes: this.hasAntecedentes
            });
            
            // Escapar study.id para JavaScript
            const safeStudyIdJs = this.escapeJs(study.id || '');
            
            // Verificar si tiene al menos un permiso de antecedentes
            const hasAnyAntecedentesPermission = this.hasAntecedentesNotas || 
                                                 this.hasAntecedentesImagenes || 
                                                 this.hasAntecedentesCamara || 
                                                 this.hasAntecedentesArchivos ||
                                                 this.hasAntecedentes;
            
            if (!hasAnyAntecedentesPermission) {
                console.warn('⚠️ Usuario sin permisos de antecedentes - no se mostrarán pestañas de edición');
            }
            
            // Remover modal existente si existe
            const existingEmergencyModal = document.getElementById('emergencyAntecedentsModal');
            if (existingEmergencyModal) {
                existingEmergencyModal.remove();
            }
            
            // Crear modal completamente nuevo
            const emergencyModal = document.createElement('div');
            emergencyModal.id = 'emergencyAntecedentsModal';
            emergencyModal.className = 'modal fade show';
            emergencyModal.style.cssText = `
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                z-index: 1065 !important;
                display: block !important;
                background-color: rgba(0, 0, 0, 0.5) !important;
            `;
            
            emergencyModal.innerHTML = `
                <div class="modal-dialog modal-lg" style="margin: 1.75rem auto; max-width: 80%; position: relative; z-index: 1066;">
                    <div class="modal-content" style="background-color: white; border: 1px solid #dee2e6; border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);">
                        <div class="modal-header" style="border-bottom: 1px solid #dee2e6; padding: 1rem;">
                            <h5 class="modal-title">
                                <i class="fas fa-file-medical me-2"></i>Antecedentes Médicos
                            </h5>
                            <button type="button" class="btn-close" onclick="closeEmergencyModal();" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="padding: 1rem;">
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="card bg-light">
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
                                                    <strong>Fecha:</strong> ${this.formatDate(study.date || study.study_date)}<br>
                                                    <strong>Descripción:</strong> ${study.study_description || 'N/A'}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pestañas -->
                            <ul class="nav nav-tabs" role="tablist" style="border-bottom: 1px solid #dee2e6;">
                                ${this.hasAntecedentesNotas ? `
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="emergency-notes-tab" data-bs-toggle="tab" data-bs-target="#emergency-notes" type="button" role="tab" style="border: none; background: none; padding: 0.5rem 1rem; color: #007bff; border-bottom: 2px solid #007bff;">
                                        <i class="fas fa-sticky-note me-2"></i>Notas y Texto
                                    </button>
                                </li>
                                ` : ''}
                                ${this.hasAntecedentesImagenes === true ? `
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link ${!this.hasAntecedentesNotas ? 'active' : ''}" id="emergency-images-tab" data-bs-toggle="tab" data-bs-target="#emergency-images" type="button" role="tab" style="border: none; background: none; padding: 0.5rem 1rem; color: ${!this.hasAntecedentesNotas ? '#007bff' : '#6c757d'}; ${!this.hasAntecedentesNotas ? 'border-bottom: 2px solid #007bff;' : ''}">
                                        <i class="fas fa-images me-2"></i>Imágenes
                                    </button>
                                </li>
                                ` : ''}
                                ${this.hasAntecedentesCamara === true ? `
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes ? 'active' : ''}" id="emergency-camera-tab" data-bs-toggle="tab" data-bs-target="#emergency-camera" type="button" role="tab" style="border: none; background: none; padding: 0.5rem 1rem; color: ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes ? '#007bff' : '#6c757d'}; ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes ? 'border-bottom: 2px solid #007bff;' : ''}">
                                        <i class="fas fa-camera me-2"></i>Cámara
                                    </button>
                                </li>
                                ` : ''}
                                ${this.hasAntecedentesArchivos === true ? `
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara ? 'active' : ''}" id="emergency-files-tab" data-bs-toggle="tab" data-bs-target="#emergency-files" type="button" role="tab" style="border: none; background: none; padding: 0.5rem 1rem; color: ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara ? '#007bff' : '#6c757d'}; ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara ? 'border-bottom: 2px solid #007bff;' : ''}">
                                        <i class="fas fa-file me-2"></i>Archivos
                                    </button>
                                </li>
                                ` : ''}
                                ${this.hasAntecedentesQrMovil === true ? `
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara && !this.hasAntecedentesArchivos ? 'active' : ''}" id="emergency-qr-tab" data-bs-toggle="tab" data-bs-target="#emergency-qr" type="button" role="tab" style="border: none; background: none; padding: 0.5rem 1rem; color: ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara && !this.hasAntecedentesArchivos ? '#007bff' : '#6c757d'}; ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara && !this.hasAntecedentesArchivos ? 'border-bottom: 2px solid #007bff;' : ''}">
                                        <i class="fas fa-qrcode me-2"></i>QR Móvil
                                    </button>
                                </li>
                                ` : ''}
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara && !this.hasAntecedentesArchivos ? '' : ''}" id="emergency-existing-tab" data-bs-toggle="tab" data-bs-target="#emergency-existing" type="button" role="tab" style="border: none; background: none; padding: 0.5rem 1rem; color: #6c757d;">
                                        <i class="fas fa-folder-open me-2"></i>Existentes
                                        <span class="badge bg-info ms-1" id="existing-count" style="display: none;">0</span>
                                    </button>
                                </li>
                            </ul>
                            
                            <div class="tab-content" style="margin-top: 1rem;">
                                ${this.hasAntecedentesNotas === true ? `
                                <div class="tab-pane fade ${!this.hasAntecedentesImagenes && !this.hasAntecedentesCamara && !this.hasAntecedentesArchivos ? 'show active' : (this.hasAntecedentesNotas ? 'show active' : '')}" id="emergency-notes" role="tabpanel">
                                    <div class="mb-3">
                                        <label for="emergencyNotes" class="form-label">
                                            <i class="fas fa-edit me-2"></i>Notas y Antecedentes Médicos
                                        </label>
                                        <textarea class="form-control" id="emergencyNotes" rows="8" 
                                                  placeholder="Ingrese aquí los antecedentes médicos, pedido médico, síntomas, historial clínico, etc."></textarea>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Esta información será visible para el médico que informe el estudio.
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                ${this.hasAntecedentesImagenes === true ? `
                                <div class="tab-pane fade ${!this.hasAntecedentesNotas && !this.hasAntecedentesCamara && !this.hasAntecedentesArchivos ? 'show active' : ''}" id="emergency-images" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label for="emergencyImageUpload" class="form-label">
                                                <i class="fas fa-upload me-2"></i>Subir Imágenes
                                            </label>
                                            <input type="file" class="form-control" id="emergencyImageUpload" multiple accept="image/*">
                                            <div class="form-text">Seleccione una o múltiples imágenes (JPG, PNG, GIF)</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">
                                                <i class="fas fa-images me-2"></i>Imágenes Adjuntas
                                            </label>
                                            <div id="emergencyUploadedImages" class="border rounded p-3" style="min-height: 100px;">
                                                <div class="text-muted text-center">
                                                    <i class="fas fa-image fa-2x mb-2"></i><br>
                                                    No hay imágenes adjuntas
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                ${this.hasAntecedentesCamara === true ? `
                                <div class="tab-pane fade ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesArchivos ? 'show active' : ''}" id="emergency-camera" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <!-- Vista previa de la cámara -->
                                            <div class="position-relative">
                                                <video id="emergencyCameraPreview" class="w-100" 
                                                       style="background: #000; border-radius: 0.375rem; position: relative; z-index: 2;" 
                                                       autoplay muted playsinline></video>
                                                <canvas id="emergencyCaptureCanvas" style="display: none;"></canvas>
                                                <div id="emergencyCameraOverlay" class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" 
                                                     style="background: rgba(0,0,0,0.7); border-radius: 0.375rem; pointer-events: none; display: flex; z-index: 1;">
                                                    <div class="text-center text-white">
                                                        <i class="fas fa-camera fa-2x mb-2"></i>
                                                        <p class="mb-0">Cámara inactiva</p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Controles de manipulación de imagen -->
                                            <div class="mt-3">
                                                <label class="form-label">
                                                    <i class="fas fa-adjust me-2"></i>Controles de Imagen
                                                </label>
                                                <div class="row g-1">
                                                    <div class="col-3">
                                                        <button id="emergencyFlipHorizontalBtn" class="btn btn-outline-secondary btn-sm" disabled>
                                                            <i class="fas fa-arrows-alt-h me-1"></i>Espejo H
                                                        </button>
                                                    </div>
                                                    <div class="col-3">
                                                        <button id="emergencyFlipVerticalBtn" class="btn btn-outline-secondary btn-sm" disabled>
                                                            <i class="fas fa-arrows-alt-v me-1"></i>Espejo V
                                                        </button>
                                                    </div>
                                                    <div class="col-3">
                                                        <button id="emergencyRotateLeftBtn" class="btn btn-outline-secondary btn-sm" disabled>
                                                            <i class="fas fa-undo me-1"></i>Rotar Izq
                                                        </button>
                                                    </div>
                                                    <div class="col-3">
                                                        <button id="emergencyRotateRightBtn" class="btn btn-outline-secondary btn-sm" disabled>
                                                            <i class="fas fa-redo me-1"></i>Rotar Der
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <!-- Controles de cámara -->
                                            <div class="d-grid gap-1">
                                                <button id="emergencyStartCameraBtn" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-video me-1"></i>Iniciar Cámara
                                                </button>
                                                <button id="emergencyCaptureBtn" class="btn btn-success btn-sm" disabled>
                                                    <i class="fas fa-camera me-1"></i>Capturar Foto
                                                </button>
                                                <button id="emergencyStopCameraBtn" class="btn btn-danger btn-sm" disabled>
                                                    <i class="fas fa-stop me-1"></i>Detener Cámara
                                                </button>
                                            </div>
                                            
                                            <!-- Estado de la cámara -->
                                            <div id="emergencyCameraStatus" class="mt-3 p-2 bg-light rounded">
                                                <small class="text-muted">
                                                    <i class="fas fa-circle me-1" style="color: #dc3545;"></i>
                                                    Cámara inactiva
                                                </small>
                                            </div>
                                            
                                            <!-- Información de la cámara -->
                                            <div class="mt-3">
                                                <small class="text-muted">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    Las fotos se guardan automáticamente
                                                </small>
                                            </div>
                                            
                                            <!-- Controles adicionales -->
                                            <div class="mt-3">
                                                <label class="form-label">
                                                    <i class="fas fa-cog me-2"></i>Configuración
                                                </label>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="emergencyAutoCapture" checked>
                                                    <label class="form-check-label" for="emergencyAutoCapture">
                                                        Captura automática
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="emergencyShowGrid" checked>
                                                    <label class="form-check-label" for="emergencyShowGrid">
                                                        Mostrar cuadrícula
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Galería de fotos capturadas -->
                                    <div class="mt-4">
                                        <label class="form-label">
                                            <i class="fas fa-images me-2"></i>Fotos Capturadas
                                        </label>
                                        <div id="emergencyCapturedImages" class="border rounded p-3" style="min-height: 120px;">
                                            <div class="text-muted text-center">
                                                <i class="fas fa-camera fa-2x mb-2"></i><br>
                                                No hay fotos capturadas
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                ${this.hasAntecedentesArchivos === true ? `
                                <div class="tab-pane fade ${!this.hasAntecedentesNotas && !this.hasAntecedentesImagenes && !this.hasAntecedentesCamara ? 'show active' : ''}" id="emergency-files" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label for="emergencyFileUpload" class="form-label">
                                                <i class="fas fa-upload me-2"></i>Subir Archivos
                                            </label>
                                            <input type="file" class="form-control" id="emergencyFileUpload" multiple>
                                            <div class="form-text">Seleccione documentos, PDFs, archivos de texto, etc.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">
                                                <i class="fas fa-file me-2"></i>Archivos Adjuntos
                                            </label>
                                            <div id="emergencyUploadedFiles" class="border rounded p-3" style="min-height: 100px;">
                                                <div class="text-muted text-center">
                                                    <i class="fas fa-file fa-2x mb-2"></i><br>
                                                    No hay archivos adjuntos
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                ${this.hasAntecedentesQrMovil === true ? `
                                <div class="tab-pane fade" id="emergency-qr" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="text-center">
                                                <h6 class="mb-3">
                                                    <i class="fas fa-qrcode me-2"></i>Captura Móvil
                                                </h6>
                                                <div class="alert alert-info" id="qr-info-alert">
                                                    <i class="fas fa-mobile-alt me-2"></i>
                                                    Escanea este código QR con tu dispositivo móvil para capturar imágenes directamente
                                                </div>
                                                
                                                <!-- Contenedor del QR -->
                                                <div id="qr-container" class="border rounded p-3 mb-3" style="min-height: 200px; display: flex; align-items: center; justify-content: center;">
                                                    <div class="text-muted">
                                                        <i class="fas fa-qrcode fa-3x mb-2"></i><br>
                                                        Generando código QR...
                                                    </div>
                                                </div>
                                                
                                                <div class="d-grid gap-2">
                                                    <button class="btn btn-primary btn-sm" id="generateQRBtn">
                                                        <i class="fas fa-sync-alt me-1"></i>Generar Nuevo QR
                                                    </button>
                                                    <button class="btn btn-outline-secondary btn-sm" id="refreshQRBtn">
                                                        <i class="fas fa-refresh me-1"></i>Actualizar QR
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <!-- Instrucciones y Seguridad (se ocultan cuando hay imágenes) -->
                                            <div id="qr-instructions-container">
                                                <div class="alert alert-success">
                                                    <h6 class="alert-heading">
                                                        <i class="fas fa-mobile-alt me-2"></i>Instrucciones
                                                    </h6>
                                                    <ol class="mb-0">
                                                        <li>Haz clic en "Generar Nuevo QR"</li>
                                                        <li>Abre la cámara de tu móvil</li>
                                                        <li>Escanea el código QR</li>
                                                        <li>Captura las imágenes necesarias</li>
                                                        <li>Las imágenes aparecerán automáticamente aquí</li>
                                                    </ol>
                                                </div>
                                                
                                                <div class="alert alert-warning">
                                                    <h6 class="alert-heading">
                                                        <i class="fas fa-shield-alt me-2"></i>Seguridad
                                                    </h6>
                                                    <p class="mb-0">
                                                        La sesión permanece activa mientras esta pestaña esté visible.
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <!-- Contenedor de imágenes pendientes desde móvil -->
                                            <div id="qr-pending-images-container" style="display: none;">
                                                <div class="card">
                                                    <div class="card-header bg-primary text-white">
                                                        <h6 class="mb-0">
                                                            <i class="fas fa-images me-2"></i>Imágenes Recibidas desde Móvil
                                                            <span class="badge bg-light text-dark ms-2" id="pending-images-count">0</span>
                                                        </h6>
                                                    </div>
                                                    <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                                        <div id="qr-pending-images-list">
                                                            <!-- Aquí se mostrarán las imágenes recibidas -->
                                                        </div>
                                                    </div>
                                                    <div class="card-footer">
                                                        <div class="d-grid gap-2">
                                                            <button class="btn btn-success btn-sm" id="acceptAllPendingBtn">
                                                                <i class="fas fa-check-double me-1"></i>Aceptar Todas
                                                            </button>
                                                            <button class="btn btn-danger btn-sm" id="rejectAllPendingBtn">
                                                                <i class="fas fa-times-circle me-1"></i>Rechazar Todas
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Estado de la sesión móvil -->
                                            <div class="card mt-3">
                                                <div class="card-header">
                                                    <h6 class="mb-0">
                                                        <i class="fas fa-info-circle me-2"></i>Estado de Sesión
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div id="mobile-session-status">
                                                        <div class="text-muted">
                                                            <i class="fas fa-clock me-1"></i>
                                                            No hay sesión móvil activa
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <div class="tab-pane fade" id="emergency-existing" role="tabpanel">
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-folder-open me-2"></i>Antecedentes Existentes
                                                </h6>
                                                <button class="btn btn-outline-primary btn-sm" onclick="derivacionesManager.refreshExistingAntecedents()">
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
                                                    <h5>No hay antecedentes cargados</h5>
                                                    <p>Los antecedentes aparecerán aquí una vez que se carguen.</p>
                                                </div>
                                                
                                                <div id="existing-files-list" style="display: none;">
                                                    <!-- Los archivos se cargarán dinámicamente aquí -->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-top: 1px solid #dee2e6; padding: 1rem;">
                            <button type="button" class="btn btn-secondary" onclick="closeEmergencyModal();">
                                <i class="fas fa-times me-2"></i>Cerrar
                            </button>
                            <button type="button" class="btn btn-outline-info" id="btnCopyAntecedentsSameDay"
                                    onclick="derivacionesManager.openCopyAntecedentsModal('${safeStudyIdJs}');"
                                    title="Primero guardá los antecedentes; después podrás copiarlos a otros del mismo día"
                                    style="display: none;"
                                    disabled>
                                <i class="fas fa-copy me-2"></i>Copiar a otros del mismo día
                            </button>
                            <span id="copyAntecedentsSameDayHint" class="text-muted small ms-1 me-2" style="display: none;"></span>
                            <button type="button" class="btn btn-primary" onclick="emergencySaveAntecedents('${safeStudyIdJs}');">
                                <i class="fas fa-save me-2"></i>Guardar Antecedentes
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al body
            document.body.appendChild(emergencyModal);
            document.body.classList.add('modal-open');
            this.selectedStudy = study;
            this.antecedentsSavedForCopy = false;
            this.updateCopyAntecedentsButtonVisibility(study);
            
            // Log de verificación después de crear el modal
            console.log('Modal creado. Verificando permisos aplicados:', {
                hasAntecedentesNotas: this.hasAntecedentesNotas,
                hasAntecedentesImagenes: this.hasAntecedentesImagenes,
                hasAntecedentesCamara: this.hasAntecedentesCamara,
                hasAntecedentesArchivos: this.hasAntecedentesArchivos
            });
            
            // Verificar qué pestañas se crearon
            setTimeout(() => {
                const notesTab = document.getElementById('emergency-notes-tab');
                const imagesTab = document.getElementById('emergency-images-tab');
                const cameraTab = document.getElementById('emergency-camera-tab');
                const filesTab = document.getElementById('emergency-files-tab');
                
                console.log('Pestañas encontradas en el DOM:', {
                    notesTab: !!notesTab,
                    imagesTab: !!imagesTab,
                    cameraTab: !!cameraTab,
                    filesTab: !!filesTab
                });
                
                // Aplicar filtros de permisos después de insertar el modal en el DOM
                this.applyAntecedentsPermissionsToModal();
            }, 50);
            
            // Agregar event listener para cerrar al hacer clic en el backdrop
            emergencyModal.onclick = (e) => {
                if (e.target === emergencyModal) {
                    closeEmergencyModal();
                }
            };
            
            // Agregar event listener para tecla Escape
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeEmergencyModal();
                }
            });
            
            console.log('Modal de emergencia creado exitosamente');
            console.log('Estudio actual establecido:', this.currentAntecedents);
            
            // Configurar event listeners para el modal de emergencia
            this.setupEmergencyModalListeners(study.id);
            
        } catch (error) {
            console.error('Error creando modal de emergencia:', error);
        }
    }
    
    /**
     * Configura los event listeners del modal de emergencia
     */
    setupEmergencyModalListeners(studyId) {
        try {
            // Event listener para pestañas
            const notesTab = document.getElementById('emergency-notes-tab');
            const imagesTab = document.getElementById('emergency-images-tab');
            const cameraTab = document.getElementById('emergency-camera-tab');
            const filesTab = document.getElementById('emergency-files-tab');
            const qrTabButton = document.getElementById('emergency-qr-tab');
            
            if (notesTab) {
                notesTab.onclick = () => {
                    // Activar pestaña de notas
                    notesTab.style.color = '#007bff';
                    notesTab.style.borderBottom = '2px solid #007bff';
                    if (imagesTab) {
                        imagesTab.style.color = '#6c757d';
                        imagesTab.style.borderBottom = 'none';
                    }
                    
                    // Mostrar contenido de notas
                    const emergencyNotes0 = document.getElementById('emergency-notes');
                    const emergencyImages0 = document.getElementById('emergency-images');
                    if (emergencyNotes0) emergencyNotes0.style.display = 'block';
                    if (emergencyImages0) emergencyImages0.style.display = 'none';
                };
            }
            
            if (imagesTab) {
                imagesTab.onclick = () => {
                    // Activar pestaña de imágenes
                    imagesTab.style.color = '#007bff';
                    imagesTab.style.borderBottom = '2px solid #007bff';
                    if (notesTab) {
                        notesTab.style.color = '#6c757d';
                        notesTab.style.borderBottom = 'none';
                    }
                    
                    // Mostrar contenido de imágenes
                    const emergencyImages = document.getElementById('emergency-images');
                    const emergencyNotes = document.getElementById('emergency-notes');
                    const emergencyCamera = document.getElementById('emergency-camera');
                    if (emergencyImages) emergencyImages.style.display = 'block';
                    if (emergencyNotes) emergencyNotes.style.display = 'none';
                    if (emergencyCamera) emergencyCamera.style.display = 'none';
                };
            }
            
            if (cameraTab) {
                cameraTab.onclick = () => {
                    // Activar pestaña de cámara
                    cameraTab.style.color = '#007bff';
                    cameraTab.style.borderBottom = '2px solid #007bff';
                    if (notesTab) {
                        notesTab.style.color = '#6c757d';
                        notesTab.style.borderBottom = 'none';
                    }
                    if (imagesTab) {
                        imagesTab.style.color = '#6c757d';
                        imagesTab.style.borderBottom = 'none';
                    }
                    if (filesTab) {
                        filesTab.style.color = '#6c757d';
                        filesTab.style.borderBottom = 'none';
                    }
                    
                    // Mostrar contenido de cámara
                    const emergencyNotes2 = document.getElementById('emergency-notes');
                    const emergencyImages2 = document.getElementById('emergency-images');
                    const emergencyFiles2 = document.getElementById('emergency-files');
                    const emergencyCamera2 = document.getElementById('emergency-camera');
                    if (emergencyNotes2) emergencyNotes2.style.display = 'none';
                    if (emergencyImages2) emergencyImages2.style.display = 'none';
                    if (emergencyFiles2) emergencyFiles2.style.display = 'none';
                    if (emergencyCamera2) emergencyCamera2.style.display = 'block';
                };
            }
            
            if (filesTab) {
                filesTab.onclick = () => {
                    // Activar pestaña de archivos
                    filesTab.style.color = '#007bff';
                    filesTab.style.borderBottom = '2px solid #007bff';
                    if (notesTab) {
                        notesTab.style.color = '#6c757d';
                        notesTab.style.borderBottom = 'none';
                    }
                    if (imagesTab) {
                        imagesTab.style.color = '#6c757d';
                        imagesTab.style.borderBottom = 'none';
                    }
                    if (cameraTab) {
                        cameraTab.style.color = '#6c757d';
                        cameraTab.style.borderBottom = 'none';
                    }
                    
                    // Mostrar contenido de archivos
                    const emergencyNotes3 = document.getElementById('emergency-notes');
                    const emergencyImages3 = document.getElementById('emergency-images');
                    const emergencyCamera3 = document.getElementById('emergency-camera');
                    const emergencyFiles3 = document.getElementById('emergency-files');
                    if (emergencyNotes3) emergencyNotes3.style.display = 'none';
                    if (emergencyImages3) emergencyImages3.style.display = 'none';
                    if (emergencyCamera3) emergencyCamera3.style.display = 'none';
                    if (emergencyFiles3) emergencyFiles3.style.display = 'block';
                };
            }
            
            if (qrTabButton) {
                qrTabButton.onclick = () => {
                    // Activar pestaña de QR móvil
                    qrTabButton.style.color = '#007bff';
                    qrTabButton.style.borderBottom = '2px solid #007bff';
                    if (notesTab) {
                        notesTab.style.color = '#6c757d';
                        notesTab.style.borderBottom = 'none';
                    }
                    if (imagesTab) {
                        imagesTab.style.color = '#6c757d';
                        imagesTab.style.borderBottom = 'none';
                    }
                    if (cameraTab) {
                        cameraTab.style.color = '#6c757d';
                        cameraTab.style.borderBottom = 'none';
                    }
                    if (filesTab) {
                        filesTab.style.color = '#6c757d';
                        filesTab.style.borderBottom = 'none';
                    }
                    
                    // Mostrar contenido de QR móvil
                    const emergencyNotes4 = document.getElementById('emergency-notes');
                    const emergencyImages4 = document.getElementById('emergency-images');
                    const emergencyCamera4 = document.getElementById('emergency-camera');
                    const emergencyFiles4 = document.getElementById('emergency-files');
                    const emergencyQr = document.getElementById('emergency-qr');
                    if (emergencyNotes4) emergencyNotes4.style.display = 'none';
                    if (emergencyImages4) emergencyImages4.style.display = 'none';
                    if (emergencyCamera4) emergencyCamera4.style.display = 'none';
                    if (emergencyFiles4) emergencyFiles4.style.display = 'none';
                    if (emergencyQr) emergencyQr.style.display = 'block';
                    
                    // Generar QR automáticamente al abrir la pestaña
                    this.generateMobileQR(studyId);
                };
            }
            
            // Event listener para subida de imágenes
            const imageUpload = document.getElementById('emergencyImageUpload');
            if (imageUpload) {
                imageUpload.onchange = async (e) => {
                    console.log('Imágenes seleccionadas en modal de emergencia:', e.target.files.length);
                    await this.handleEmergencyImageUpload(e.target.files, studyId);
                };
            }
            
            // Event listener para subida de archivos generales
            const fileUpload = document.getElementById('emergencyFileUpload');
            if (fileUpload) {
                fileUpload.onchange = async (e) => {
                    console.log('Archivos seleccionados en modal de emergencia:', e.target.files.length);
                    await this.handleEmergencyFileUpload(e.target.files, studyId);
                };
            }
            
            // Event listeners para controles de QR móvil
            const generateQRBtn = document.getElementById('generateQRBtn');
            const refreshQRBtn = document.getElementById('refreshQRBtn');
            
            if (generateQRBtn) {
                generateQRBtn.onclick = () => this.generateMobileQR(studyId);
            }
            
            if (refreshQRBtn) {
                refreshQRBtn.onclick = () => this.refreshMobileQR(studyId);
            }
            
            // Event listeners para botones de aceptar/rechazar imágenes pendientes
            const acceptAllBtn = document.getElementById('acceptAllPendingBtn');
            const rejectAllBtn = document.getElementById('rejectAllPendingBtn');
            
            if (acceptAllBtn) {
                acceptAllBtn.onclick = () => this.acceptAllPendingImages();
            }
            
            if (rejectAllBtn) {
                rejectAllBtn.onclick = () => this.rejectAllPendingImages();
            }
            
            // Event listener para detectar cambio de pestaña QR Móvil
            const qrTab = document.getElementById('emergency-qr-tab');
            if (qrTab) {
                qrTab.addEventListener('shown.bs.tab', () => {
                    console.log('Pestaña QR Móvil mostrada - iniciando monitoreo de sesión');
                    this.startQRTabMonitoring();
                });
                
                qrTab.addEventListener('hidden.bs.tab', () => {
                    console.log('Pestaña QR Móvil ocultada - deteniendo monitoreo de sesión');
                    this.stopQRTabMonitoring();
                });
            }
            
            // Función helper para limpiar pestañas y mostrar solo la activa
            const cleanupTabsAndShowActive = (activeTabId, activeButtonId) => {
                console.log(`Limpiando pestañas y mostrando: ${activeTabId}`);
                
                const allTabIds = [
                    'emergency-notes',
                    'emergency-images', 
                    'emergency-camera',
                    'emergency-files',
                    'emergency-qr',
                    'emergency-existing'
                ];
                
                const allButtonIds = [
                    'emergency-notes-tab',
                    'emergency-images-tab',
                    'emergency-camera-tab',
                    'emergency-files-tab',
                    'emergency-qr-tab',
                    'emergency-existing-tab'
                ];
                
                // Resetear estilos de TODOS los botones de pestañas
                allButtonIds.forEach(buttonId => {
                    const button = document.getElementById(buttonId);
                    if (button) {
                        // Remover clases activas
                        button.classList.remove('active');
                        // Resetear estilos (inactivo)
                        button.style.color = '#6c757d';
                        button.style.borderBottom = 'none';
                        button.style.fontWeight = 'normal';
                    }
                });
                
                // Aplicar estilo activo al botón de la pestaña activa
                const activeButton = document.getElementById(activeButtonId);
                if (activeButton) {
                    activeButton.classList.add('active');
                    activeButton.style.color = '#007bff';
                    activeButton.style.borderBottom = '2px solid #007bff';
                    activeButton.style.fontWeight = '500';
                }
                
                // Ocultar TODAS las pestañas de contenido
                allTabIds.forEach(tabId => {
                    const tab = document.getElementById(tabId);
                    if (tab && tabId !== activeTabId) {
                        tab.style.display = 'none !important';
                        tab.style.visibility = 'hidden';
                        tab.style.position = 'absolute';
                        tab.style.height = '0';
                        tab.style.overflow = 'hidden';
                        tab.classList.remove('show', 'active');
                    }
                });
                
                // Mostrar SOLO la pestaña activa
                setTimeout(() => {
                    const activeTab = document.getElementById(activeTabId);
                    if (activeTab) {
                        activeTab.style.display = 'block';
                        activeTab.style.visibility = 'visible';
                        activeTab.style.position = 'relative';
                        activeTab.style.height = 'auto';
                        activeTab.style.overflow = 'visible';
                        activeTab.style.minHeight = 'auto';
                        activeTab.classList.add('show', 'active');
                        
                        console.log(`✅ Pestaña ${activeTabId} mostrada correctamente`);
                    }
                }, 10);
            };
            
            // Event listeners para TODAS las pestañas
            const tabConfig = [
                { buttonId: 'emergency-notes-tab', contentId: 'emergency-notes' },
                { buttonId: 'emergency-images-tab', contentId: 'emergency-images' },
                { buttonId: 'emergency-camera-tab', contentId: 'emergency-camera' },
                { buttonId: 'emergency-files-tab', contentId: 'emergency-files' },
                { buttonId: 'emergency-qr-tab', contentId: 'emergency-qr' },
                { buttonId: 'emergency-existing-tab', contentId: 'emergency-existing' }
            ];
            
            tabConfig.forEach(config => {
                const tabButton = document.getElementById(config.buttonId);
                if (tabButton) {
                    tabButton.addEventListener('shown.bs.tab', () => {
                        cleanupTabsAndShowActive(config.contentId, config.buttonId);
                    });
                }
            });
            
            // Event listeners para controles de cámara
            const startCameraBtn = document.getElementById('emergencyStartCameraBtn');
            const captureBtn = document.getElementById('emergencyCaptureBtn');
            const stopCameraBtn = document.getElementById('emergencyStopCameraBtn');
            
            if (startCameraBtn) {
                startCameraBtn.onclick = () => this.startEmergencyCamera();
            }
            
            if (captureBtn) {
                captureBtn.onclick = () => this.captureEmergencyPhoto();
            }
            
            if (stopCameraBtn) {
                stopCameraBtn.onclick = () => this.stopEmergencyCamera();
            }
            
            // Event listeners para controles de manipulación de imagen
            const flipHBtn = document.getElementById('emergencyFlipHorizontalBtn');
            const flipVBtn = document.getElementById('emergencyFlipVerticalBtn');
            const rotateLeftBtn = document.getElementById('emergencyRotateLeftBtn');
            const rotateRightBtn = document.getElementById('emergencyRotateRightBtn');
            
            if (flipHBtn) {
                flipHBtn.onclick = () => this.toggleFlipHorizontal();
            }
            
            if (flipVBtn) {
                flipVBtn.onclick = () => this.toggleFlipVertical();
            }
            
            if (rotateLeftBtn) {
                rotateLeftBtn.onclick = () => this.rotateLeft();
            }
            
            if (rotateRightBtn) {
                rotateRightBtn.onclick = () => this.rotateRight();
            }
            
            console.log('Event listeners del modal de emergencia configurados');
            
        } catch (error) {
            console.error('Error configurando event listeners del modal de emergencia:', error);
        }
    }
    
    /**
     * Inicia la cámara en el modal de emergencia
     */
    async startEmergencyCamera() {
        try {
            console.log('Iniciando cámara en modal de emergencia...');
            
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    facingMode: 'environment' // Cámara trasera en móviles
                } 
            });
            
            this.cameraStream = stream;
            const video = document.getElementById('emergencyCameraPreview');
            const overlay = document.getElementById('emergencyCameraOverlay');
            
            console.log('Elementos encontrados:', { video: !!video, overlay: !!overlay });
            console.log('Stream obtenido:', !!stream, 'Tracks:', stream.getTracks().length);
            
            if (video && overlay) {
                console.log('Ocultando overlay y configurando video...');
                
                // Forzar ocultación del overlay con múltiples métodos
                overlay.style.display = 'none';
                overlay.style.visibility = 'hidden';
                overlay.style.opacity = '0';
                overlay.style.pointerEvents = 'none';
                
                video.srcObject = stream;
                video.style.display = 'block';
                video.style.visibility = 'visible';
                video.style.opacity = '1';
                
                console.log('Overlay ocultado, video configurado');
                console.log('Estado del overlay después de ocultar:', {
                    display: overlay.style.display,
                    visibility: overlay.style.visibility,
                    opacity: overlay.style.opacity
                });
                console.log('Estado del video después de configurar:', {
                    display: video.style.display,
                    visibility: video.style.visibility,
                    opacity: video.style.opacity
                });
                
                // Configurar propiedades del video
                video.autoplay = true;
                video.muted = true;
                video.playsInline = true;
                
                // Aplicar transformaciones CSS
                this.applyImageTransformations();
                
                // Event listeners para debug
                video.onloadedmetadata = () => {
                    console.log('Video metadata cargado, dimensiones:', video.videoWidth, 'x', video.videoHeight);
                    this.adjustVideoSize(video);
                };
                
                video.oncanplay = () => {
                    console.log('Video listo para reproducir');
                    video.play().catch(error => {
                        console.error('Error reproduciendo video:', error);
                    });
                };
                
                video.onplaying = () => {
                    console.log('Video reproduciéndose correctamente');
                };
                
                video.onerror = (error) => {
                    console.error('Error en el video:', error);
                };
                
                // Actualizar controles
                document.getElementById('emergencyStartCameraBtn').disabled = true;
                document.getElementById('emergencyCaptureBtn').disabled = false;
                document.getElementById('emergencyStopCameraBtn').disabled = false;
                
                // Habilitar controles de manipulación de imagen
                document.getElementById('emergencyFlipHorizontalBtn').disabled = false;
                document.getElementById('emergencyFlipVerticalBtn').disabled = false;
                document.getElementById('emergencyRotateLeftBtn').disabled = false;
                document.getElementById('emergencyRotateRightBtn').disabled = false;
                
                // Actualizar estado
                const status = document.getElementById('emergencyCameraStatus');
                if (status) {
                    status.innerHTML = `
                        <small class="text-success">
                            <i class="fas fa-circle me-1" style="color: #28a745;"></i>
                            Cámara activa
                        </small>
                    `;
                }
                
                console.log('Cámara iniciada exitosamente en modal de emergencia');
            }
            
        } catch (error) {
            console.error('Error accediendo a la cámara:', error);
            console.error('Error accediendo a la cámara:', error);
            this.showBootstrapAlert(
                'Error de Cámara', 
                'No se pudo acceder a la cámara. Verifica los permisos y que la cámara esté disponible.', 
                'error', 
                0
            );
        }
    }
    
    /**
     * Captura una foto en el modal de emergencia
     */
    captureEmergencyPhoto() {
        try {
            console.log('Capturando foto en modal de emergencia...');
            console.log('Estudio actual disponible:', !!this.currentAntecedents);
            
            // Verificar que tenemos el estudio actual
            if (!this.currentAntecedents || !this.currentAntecedents.id) {
                console.error('No hay estudio actual disponible para capturar foto');
                console.error('No hay estudio actual disponible para capturar foto');
                this.showBootstrapAlert(
                    'Error de Captura', 
                    'No hay estudio seleccionado. Por favor, cierra y vuelve a abrir el modal.', 
                    'warning', 
                    0
                );
                return;
            }
            
            const video = document.getElementById('emergencyCameraPreview');
            const canvas = document.getElementById('emergencyCaptureCanvas');
            
            if (!video || !canvas) {
                console.error('Elementos de cámara no encontrados');
                return;
            }
            
            const ctx = canvas.getContext('2d');
            
            // Configurar canvas con las dimensiones del video
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            // Aplicar transformaciones al contexto antes de dibujar
            ctx.save();
            
            // Mover al centro del canvas
            ctx.translate(canvas.width / 2, canvas.height / 2);
            
            // Aplicar rotación
            if (this.imageTransformations.rotation !== 0) {
                ctx.rotate((this.imageTransformations.rotation * Math.PI) / 180);
            }
            
            // Aplicar espejos
            let scaleX = this.imageTransformations.flipHorizontal ? -1 : 1;
            let scaleY = this.imageTransformations.flipVertical ? -1 : 1;
            ctx.scale(scaleX, scaleY);
            
            // Dibujar el frame actual del video en el canvas
            ctx.drawImage(video, -video.videoWidth / 2, -video.videoHeight / 2);
            
            // Restaurar el contexto
            ctx.restore();
            
            // Convertir a imagen
            const imageData = canvas.toDataURL('image/jpeg', 0.8);
            
            // Agregar a la galería
            const capturedImage = {
                id: Date.now(),
                data: imageData,
                timestamp: new Date()
            };
            
            this.emergencyCapturedImages.push(capturedImage);
            this.displayEmergencyCapturedImages();
            
            // Subir automáticamente al servidor
            this.uploadEmergencyCapturedImage(imageData);
            
            console.log('Foto capturada exitosamente');
            
        } catch (error) {
            console.error('Error capturando foto:', error);
            this.showBootstrapAlert(
                'Error de Captura', 
                'No se pudo capturar la foto. Inténtalo de nuevo.', 
                'error', 
                0
            );
        }
    }
    
    /**
     * Detiene la cámara en el modal de emergencia
     */
    stopEmergencyCamera() {
        try {
            console.log('Deteniendo cámara en modal de emergencia...');
            
            if (this.cameraStream) {
                this.cameraStream.getTracks().forEach(track => track.stop());
                this.cameraStream = null;
                
                const video = document.getElementById('emergencyCameraPreview');
                const overlay = document.getElementById('emergencyCameraOverlay');
                
                if (video && overlay) {
                    video.srcObject = null;
                    video.style.display = 'none';
                    video.style.visibility = 'hidden';
                    video.style.opacity = '0';
                    
                    // Restaurar overlay
                    overlay.style.display = 'flex';
                    overlay.style.visibility = 'visible';
                    overlay.style.opacity = '1';
                    overlay.style.pointerEvents = 'none';
                }
                
                // Actualizar controles
                document.getElementById('emergencyStartCameraBtn').disabled = false;
                document.getElementById('emergencyCaptureBtn').disabled = true;
                document.getElementById('emergencyStopCameraBtn').disabled = true;
                
                // Deshabilitar controles de manipulación de imagen
                document.getElementById('emergencyFlipHorizontalBtn').disabled = true;
                document.getElementById('emergencyFlipVerticalBtn').disabled = true;
                document.getElementById('emergencyRotateLeftBtn').disabled = true;
                document.getElementById('emergencyRotateRightBtn').disabled = true;
                
                // Actualizar estado
                const status = document.getElementById('emergencyCameraStatus');
                if (status) {
                    status.innerHTML = `
                        <small class="text-muted">
                            <i class="fas fa-circle me-1" style="color: #dc3545;"></i>
                            Cámara inactiva
                        </small>
                    `;
                }
                
                console.log('Cámara detenida exitosamente');
            }
            
        } catch (error) {
            console.error('Error deteniendo cámara:', error);
        }
    }
    
    /**
     * Aplica las transformaciones CSS al video de la cámara
     */
    applyImageTransformations() {
        try {
            const video = document.getElementById('emergencyCameraPreview');
            if (!video) return;
            
            let transform = '';
            
            // Rotación
            if (this.imageTransformations.rotation !== 0) {
                transform += `rotate(${this.imageTransformations.rotation}deg) `;
            }
            
            // Espejo horizontal
            if (this.imageTransformations.flipHorizontal) {
                transform += 'scaleX(-1) ';
            }
            
            // Espejo vertical
            if (this.imageTransformations.flipVertical) {
                transform += 'scaleY(-1) ';
            }
            
            // Aplicar transformación
            video.style.transform = transform.trim();
            
            console.log('Transformaciones aplicadas:', transform.trim());
            
        } catch (error) {
            console.error('Error aplicando transformaciones:', error);
        }
    }
    
    /**
     * Alterna el espejo horizontal
     */
    toggleFlipHorizontal() {
        try {
            this.imageTransformations.flipHorizontal = !this.imageTransformations.flipHorizontal;
            this.applyImageTransformations();
            
            const btn = document.getElementById('emergencyFlipHorizontalBtn');
            if (btn) {
                btn.classList.toggle('btn-secondary', this.imageTransformations.flipHorizontal);
                btn.classList.toggle('btn-outline-secondary', !this.imageTransformations.flipHorizontal);
            }
            
            console.log('Espejo horizontal:', this.imageTransformations.flipHorizontal ? 'activado' : 'desactivado');
            
        } catch (error) {
            console.error('Error alternando espejo horizontal:', error);
        }
    }
    
    /**
     * Alterna el espejo vertical
     */
    toggleFlipVertical() {
        try {
            this.imageTransformations.flipVertical = !this.imageTransformations.flipVertical;
            this.applyImageTransformations();
            
            const btn = document.getElementById('emergencyFlipVerticalBtn');
            if (btn) {
                btn.classList.toggle('btn-secondary', this.imageTransformations.flipVertical);
                btn.classList.toggle('btn-outline-secondary', !this.imageTransformations.flipVertical);
            }
            
            console.log('Espejo vertical:', this.imageTransformations.flipVertical ? 'activado' : 'desactivado');
            
        } catch (error) {
            console.error('Error alternando espejo vertical:', error);
        }
    }
    
    /**
     * Rota la imagen hacia la izquierda
     */
    rotateLeft() {
        try {
            this.imageTransformations.rotation -= 90;
            this.applyImageTransformations();
            
            console.log('Rotación izquierda aplicada. Ángulo actual:', this.imageTransformations.rotation);
            
        } catch (error) {
            console.error('Error rotando izquierda:', error);
        }
    }
    
    /**
     * Rota la imagen hacia la derecha
     */
    rotateRight() {
        try {
            this.imageTransformations.rotation += 90;
            this.applyImageTransformations();
            
            console.log('Rotación derecha aplicada. Ángulo actual:', this.imageTransformations.rotation);
            
        } catch (error) {
            console.error('Error rotando derecha:', error);
        }
    }
    
    /**
     * Ajusta el tamaño del video según la resolución de la cámara
     */
    adjustVideoSize(video) {
        try {
            if (!video || !video.videoWidth || !video.videoHeight) {
                console.log('Video o dimensiones no disponibles para ajuste');
                return;
            }
            
            const videoWidth = video.videoWidth;
            const videoHeight = video.videoHeight;
            const aspectRatio = videoWidth / videoHeight;
            
            console.log('Ajustando tamaño del video:', {
                width: videoWidth,
                height: videoHeight,
                aspectRatio: aspectRatio.toFixed(2)
            });
            
            // Calcular dimensiones máximas del contenedor
            const container = video.parentElement;
            const maxWidth = container.offsetWidth;
            const maxHeight = 400; // Altura máxima deseada
            
            let newWidth, newHeight;
            
            // Calcular dimensiones manteniendo la proporción
            if (aspectRatio > 1) {
                // Video horizontal (landscape)
                newWidth = Math.min(maxWidth, maxHeight * aspectRatio);
                newHeight = newWidth / aspectRatio;
            } else {
                // Video vertical (portrait) o cuadrado
                newHeight = Math.min(maxHeight, maxWidth / aspectRatio);
                newWidth = newHeight * aspectRatio;
            }
            
            // Aplicar dimensiones
            video.style.width = `${newWidth}px`;
            video.style.height = `${newHeight}px`;
            video.style.maxWidth = '100%';
            video.style.maxHeight = `${maxHeight}px`;
            
            // Centrar el video si es necesario
            video.style.margin = '0 auto';
            video.style.display = 'block';
            
            console.log('Video ajustado a:', {
                newWidth: Math.round(newWidth),
                newHeight: Math.round(newHeight)
            });
            
        } catch (error) {
            console.error('Error ajustando tamaño del video:', error);
        }
    }
    
    /**
     * Muestra las fotos capturadas en el modal de emergencia
     */
    displayEmergencyCapturedImages() {
        const container = document.getElementById('emergencyCapturedImages');
        
        if (!container) {
            console.error('Contenedor de fotos capturadas no encontrado');
            return;
        }
        
        if (this.emergencyCapturedImages.length === 0) {
            container.innerHTML = `
                <div class="text-muted text-center">
                    <i class="fas fa-camera fa-2x mb-2"></i><br>
                    No hay fotos capturadas
                </div>
            `;
            return;
        }
        
        let html = '<div class="row">';
        this.emergencyCapturedImages.forEach(image => {
            html += `
                <div class="col-md-3 mb-2">
                    <div class="position-relative">
                        <img src="${image.data}" class="img-thumbnail w-100" style="height: 100px; object-fit: cover;">
                        <button class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                                onclick="removeEmergencyCapturedImage(${image.id})" title="Eliminar"
                                style="padding: 0.25rem 0.5rem;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <small class="text-muted d-block text-center">${image.timestamp.toLocaleTimeString()}</small>
                </div>
            `;
        });
        html += '</div>';
        
        container.innerHTML = html;
    }
    
    /**
     * Sube una imagen capturada al servidor
     */
    async uploadEmergencyCapturedImage(imageData) {
        try {
            console.log('Subiendo imagen capturada...');
            
            // Verificar que tenemos el estudio actual
            if (!this.currentAntecedents || !this.currentAntecedents.id) {
                console.error('No hay estudio actual disponible para subir la imagen');
                return;
            }
            
            // Obtener usuario actual
            const currentUser = this.getCurrentUser();
            if (!currentUser || !currentUser.id) {
                console.error('No hay usuario actual disponible para subir la imagen');
                return;
            }
            
            console.log('Subiendo imagen para estudio:', this.currentAntecedents.id);
            console.log('Usuario actual:', currentUser.id);
            
            // Convertir dataURL a Blob
            const response = await fetch(imageData);
            const blob = await response.blob();
            
            // Crear FormData
            const formData = new FormData();
            formData.append('file', blob, `captured_${Date.now()}.jpg`);
            formData.append('study_id', this.currentAntecedents.id);
            formData.append('file_type', 'camera_capture');
            formData.append('created_by', currentUser.id);
            
            // Subir al servidor
            const uploadResponse = await fetch(`${this.apiBaseUrl}upload_antecedents_file.php`, {
                method: 'POST',
                body: formData
            });
            
            const result = await uploadResponse.json();
            
            if (result.success) {
                console.log('Imagen capturada subida exitosamente');
                // Actualizar el contador en la lista principal
                setTimeout(async () => {
                    await this.loadAntecedentsStatus();
                }, 500);
            } else {
                console.error('Error subiendo imagen:', result.message);
            }
            
        } catch (error) {
            console.error('Error subiendo imagen capturada:', error);
        }
    }
    
    /**
     * Limpia el modal después de cerrarlo
     */
    cleanupModal() {
        try {
            console.log('Limpiando modal...');
            
            // Remover modal original
            const modalElement = document.getElementById('antecedentsModal');
            if (modalElement) {
                modalElement.remove();
            }
            
            // Remover modal de emergencia
            const emergencyModal = document.getElementById('emergencyAntecedentsModal');
            if (emergencyModal) {
                emergencyModal.remove();
            }
            
            // Remover backdrop personalizado
            const backdrop = document.getElementById('antecedentsModalBackdrop');
            if (backdrop) {
                backdrop.remove();
            }
            
            // Remover TODOS los backdrops de Bootstrap
            const allBackdrops = document.querySelectorAll('.modal-backdrop');
            allBackdrops.forEach(backdrop => {
                backdrop.remove();
            });
            
            // Limpiar clases del body
            document.body.classList.remove('modal-open');
            
            // Limpiar estilos del body si existen
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Detener cámara si está activa
            if (this.cameraStream) {
                this.cameraStream.getTracks().forEach(track => track.stop());
                this.cameraStream = null;
            }
            
            // Limpiar fotos capturadas
            this.emergencyCapturedImages = [];
            
            // Forzar limpieza de cualquier modal de Bootstrap activo
            if (window.bootstrap && window.bootstrap.Modal) {
                const activeModals = document.querySelectorAll('.modal.show');
                activeModals.forEach(modal => {
                    const modalInstance = bootstrap.Modal.getInstance(modal);
                    if (modalInstance) {
                        modalInstance.dispose();
                    }
                });
            }
            
            console.log('Modal limpiado exitosamente');
            
        } catch (error) {
            console.error('Error limpiando modal:', error);
        }
    }
    
    /**
     * Muestra un modal Bootstrap profesional
     */
    showBootstrapAlert(title, message, type = 'success', duration = 3000) {
        try {
            // Crear modal de alerta
            const alertModal = document.createElement('div');
            alertModal.className = 'modal fade';
            alertModal.id = 'bootstrapAlertModal';
            alertModal.setAttribute('data-bs-backdrop', 'static');
            alertModal.setAttribute('data-bs-keyboard', 'false');
            
            // Determinar colores según el tipo
            let iconClass, bgClass, textClass;
            switch(type) {
                case 'success':
                    iconClass = 'fas fa-check-circle text-success';
                    bgClass = 'bg-success';
                    textClass = 'text-white';
                    break;
                case 'error':
                case 'danger':
                    iconClass = 'fas fa-exclamation-circle text-danger';
                    bgClass = 'bg-danger';
                    textClass = 'text-white';
                    break;
                case 'warning':
                    iconClass = 'fas fa-exclamation-triangle text-warning';
                    bgClass = 'bg-warning';
                    textClass = 'text-dark';
                    break;
                case 'info':
                    iconClass = 'fas fa-info-circle text-info';
                    bgClass = 'bg-info';
                    textClass = 'text-white';
                    break;
                default:
                    iconClass = 'fas fa-info-circle text-primary';
                    bgClass = 'bg-primary';
                    textClass = 'text-white';
            }
            
            alertModal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header ${bgClass} ${textClass}">
                            <h5 class="modal-title">
                                <i class="${iconClass} me-2"></i>${title}
                            </h5>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0">${message}</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al DOM
            document.body.appendChild(alertModal);
            
            // Mostrar modal
            const modal = new bootstrap.Modal(alertModal, {
                backdrop: 'static',
                keyboard: false
            });
            modal.show();
            
            // Auto-cerrar después de la duración especificada
            if (duration > 0) {
                setTimeout(() => {
                    modal.hide();
                    setTimeout(() => {
                        if (alertModal.parentNode) {
                            alertModal.parentNode.removeChild(alertModal);
                        }
                    }, 300);
                }, duration);
            }
            
            // Event listener para limpiar cuando se cierre manualmente
            alertModal.addEventListener('hidden.bs.modal', () => {
                if (alertModal.parentNode) {
                    alertModal.parentNode.removeChild(alertModal);
                }
            });
            
        } catch (error) {
            console.error('Error mostrando alerta Bootstrap:', error);
            // Fallback al alert nativo
            alert(`${title}: ${message}`);
        }
    }
    getCurrentUser() {
        try {
            console.log('Obteniendo usuario actual...');
            
            // Prioridad 1: Usar window.getCurrentUser() de auth-middleware.js (método correcto)
            if (typeof window.getCurrentUser === 'function') {
                const user = window.getCurrentUser();
                if (user) {
                    console.log('Usuario encontrado vía window.getCurrentUser():', user);
                    return user;
                }
            }
            
            // Prioridad 2: Intentar localStorage con clave 'user' (usado por auth-middleware)
            const userData = localStorage.getItem('user');
            if (userData) {
                try {
                    const user = JSON.parse(userData);
                    console.log('Usuario encontrado en localStorage (user):', user);
                    return user;
                } catch (e) {
                    console.warn('Error parseando userData de localStorage:', e);
                }
            }
            
            // Prioridad 3: Intentar sessionStorage
            const sessionUserData = sessionStorage.getItem('userData');
            if (sessionUserData) {
                try {
                    const user = JSON.parse(sessionUserData);
                    console.log('Usuario encontrado en sessionStorage:', user);
                    return user;
                } catch (e) {
                    console.warn('Error parseando userData de sessionStorage:', e);
                }
            }
            
            // Prioridad 4: Intentar obtener de la API de validación de sesión
            console.warn('No se encontró usuario en localStorage/sessionStorage, intentando obtener de API...');
            
            // Esta es una llamada síncrona, pero necesitamos async - retornar null y que el llamador maneje
            return null;
            
        } catch (error) {
            console.error('Error obteniendo usuario actual:', error);
            return null;
        }
    }
    
    /**
     * Obtiene las asignaciones para un estudio específico
     */
    getStudyAssignments(studyId) {
        if (!this.studyAssignments) {
            return [];
        }
        const k = studyId != null ? String(studyId) : '';
        return this.studyAssignments[k] || this.studyAssignments[studyId] || [];
    }
    
    /**
     * Formatea la información de asignaciones para mostrar en la tabla
     */
    formatAssignmentInfo(assignments) {
        if (!assignments || assignments.length === 0) {
            return '<span class="text-muted">Sin asignar</span>';
        }
        
        // Crear botones de acción solo si tiene permiso de asignaciones
        const createActionButtons = (studyId, userId = null, isMultiple = false) => {
            if (!this.hasAsignaciones) {
                return ''; // No mostrar botones si no tiene permiso
            }
            
            if (isMultiple) {
            return `
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-info btn-sm" 
                                onclick="derivacionesManager.showAssignmentDetails('${studyId}')" 
                                title="Ver detalles">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm" 
                                onclick="derivacionesManager.unassignAll('${studyId}')" 
                                title="Desasignar todos">
                            <i class="fas fa-trash"></i>
                        </button>
                        </div>
                `;
            } else {
                return `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-warning btn-sm" 
                                onclick="derivacionesManager.changeAssignment('${studyId}', ${userId})" 
                                    title="Cambiar asignación">
                                <i class="fas fa-exchange-alt"></i>
                            </button>
                            <button class="btn btn-outline-danger btn-sm" 
                                onclick="derivacionesManager.unassignUser('${studyId}', ${userId})" 
                                    title="Desasignar">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                `;
            }
        };
        
        if (assignments.length === 1) {
            const assignment = assignments[0];
            const actionButtons = createActionButtons(assignment.study_id, assignment.user_id, false);
            
            return `
                <div class="assignment-single">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <i class="fas fa-user text-primary me-1"></i>
                            <span class="fw-semibold">${assignment.usuario_nombre} ${assignment.usuario_apellido}</span>
                            <small class="text-muted d-block">${assignment.matricula_profesional}</small>
                        </div>
                        ${actionButtons}
                    </div>
                </div>
            `;
        } else {
            const actionButtons = createActionButtons(assignments[0].study_id, null, true);
            
            return `
                <div class="assignment-multiple">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <i class="fas fa-users text-primary me-1"></i>
                            <span class="fw-semibold">${assignments.length} usuarios</span>
                            <small class="text-muted d-block">Ver detalles</small>
                        </div>
                        ${actionButtons}
                    </div>
                </div>
            `;
        }
    }
    
    /**
     * Formatea la información de subasignaciones para mostrar en la tabla
     */
    formatSubassignmentsInfo(subassignments) {
        if (!subassignments || subassignments.length === 0) {
            return '<span class="text-muted">Sin derivaciones</span>';
        }
        
        if (subassignments.length === 1) {
            const sub = subassignments[0];
            const nombreCompleto = `${sub.subassigned_user_nombre || ''} ${sub.subassigned_user_apellido || ''}`.trim();
            return `
                <div class="subassignment-single">
                    <i class="fas fa-user-clock text-success me-1"></i>
                    <span class="fw-semibold">${nombreCompleto || 'Usuario sin nombre'}</span>
                    <small class="text-muted d-block">${sub.subassigned_at_formatted || sub.subassigned_at || ''}</small>
                </div>
            `;
        } else {
            return `
                <div class="subassignment-multiple">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <i class="fas fa-users text-success me-1"></i>
                            <span class="fw-semibold">${subassignments.length} derivaciones</span>
                            <small class="text-muted d-block">Ver detalles</small>
                        </div>
                            <button class="btn btn-outline-info btn-sm" 
                                onclick="derivacionesManager.showSubassignmentDetails('${subassignments[0].study_id}')" 
                                title="Ver derivaciones">
                                <i class="fas fa-eye"></i>
                            </button>
                    </div>
                </div>
            `;
        }
    }
    
    /**
     * Muestra detalles de subasignaciones múltiples
     */
    showSubassignmentDetails(studyId) {
        const subassignments = this.getStudySubassignments(studyId);
        if (!subassignments || subassignments.length === 0) {
            this.showError('No se encontraron derivaciones');
            return;
        }
        
        const study = this.studies.find(s => s.id === studyId);
        const studyInfo = study ? `${study.patient_name} - ${study.patient_id}` : 'Estudio desconocido';
        
        // Obtener asignación principal
        const assignments = this.getStudyAssignments(studyId);
        const mainUser = assignments && assignments.length > 0 ? 
            `${assignments[0].usuario_nombre} ${assignments[0].usuario_apellido}` : 
            'Usuario desconocido';
        
        let detailsHTML = `
            <div class="subassignment-details">
                <h6>Derivaciones para: ${studyInfo}</h6>
                <p class="text-muted mb-3">
                    <i class="fas fa-user text-primary me-1"></i>
                    Asignado principal: <strong>${mainUser}</strong>
                </p>
                <div class="list-group">
        `;
        
        subassignments.forEach(sub => {
            const nombreCompleto = `${sub.subassigned_user_nombre || ''} ${sub.subassigned_user_apellido || ''}`.trim();
            detailsHTML += `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-user-clock text-success me-2"></i>
                            <strong>${nombreCompleto || 'Usuario sin nombre'}</strong>
                            <br>
                            <small class="text-muted">
                                ${sub.subassigned_user_email || 'Sin email'} | 
                                ${sub.subassigned_user_matricula || 'Sin matrícula'}
                            </small>
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>
                                Derivado: ${sub.subassigned_at_formatted || sub.subassigned_at || 'Fecha desconocida'}
                            </small>
                        </div>
                    </div>
                </div>
            `;
        });
        
        detailsHTML += `
                </div>
            </div>
        `;
        
        // Crear y mostrar modal dinámico
        this.createDynamicInfoModal('Detalles de Derivaciones', detailsHTML);
    }
    
    /**
     * Desasigna un usuario específico de un estudio
     */
    async unassignUser(studyId, userId) {
        console.log('🔍 Debug - Desasignando usuario:', { studyId, userId });
        
        // Obtener información del usuario y estudio para el popup
        const user = this.users.find(u => u.id == userId);
        const study = this.studies.find(s => s.id === studyId);
        
        const userName = user ? `${user.nombre} ${user.apellido}` : `Usuario ID ${userId}`;
        const studyInfo = study ? `${study.patient_name} (${study.modality})` : `Estudio ${studyId}`;
        
        // Mostrar popup de confirmación
        this.showUnassignConfirmation(studyId, userId, userName, studyInfo, 'individual');
    }
    
    /**
     * Desasigna todos los usuarios de un estudio
     */
    async unassignAll(studyId) {
        console.log('🔍 Debug - Desasignando todos los usuarios del estudio:', studyId);
        
        // Obtener información del estudio para el popup
        const study = this.studies.find(s => s.id === studyId);
        const studyInfo = study ? `${study.patient_name} (${study.modality})` : `Estudio ${studyId}`;
        
        // Mostrar popup de confirmación
        this.showUnassignConfirmation(studyId, null, 'todos los usuarios', studyInfo, 'all');
    }
    
    /**
     * Muestra popup de confirmación para desasignación
     */
    showUnassignConfirmation(studyId, userId, userName, studyInfo, type) {
        const isIndividual = type === 'individual';
        const title = isIndividual ? 'Desasignar Usuario' : 'Desasignar Todos los Usuarios';
        const message = isIndividual 
            ? `¿Estás seguro de que quieres desasignar a <strong>${userName}</strong> del estudio <strong>${studyInfo}</strong>?`
            : `¿Estás seguro de que quieres desasignar a <strong>${userName}</strong> del estudio <strong>${studyInfo}</strong>?`;
        
        const confirmButtonText = isIndividual ? 'Desasignar Usuario' : 'Desasignar Todos';
        const confirmButtonClass = isIndividual ? 'btn-warning' : 'btn-danger';
        
        // Crear modal de confirmación
        const modalHtml = `
            <div class="modal fade" id="unassignConfirmModal" tabindex="-1" aria-labelledby="unassignConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="unassignConfirmModalLabel">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                ${title}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning d-flex align-items-center">
                                <i class="fas fa-exclamation-triangle me-3 fs-4"></i>
                                <div>
                                    <strong>¡Atención!</strong><br>
                                    Esta acción no se puede deshacer.
                                </div>
                            </div>
                            <p>${message}</p>
                            <div class="bg-light p-3 rounded">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Una vez desasignado, el usuario ya no tendrá acceso a este estudio.
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cancelar
                            </button>
                            <button type="button" class="btn ${confirmButtonClass}" id="confirmUnassignBtn">
                                <i class="fas fa-trash me-1"></i>${confirmButtonText}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal existente si existe
        const existingModal = document.getElementById('unassignConfirmModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('unassignConfirmModal'), {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        modal.show();
        
        // Configurar evento de confirmación
        document.getElementById('confirmUnassignBtn').addEventListener('click', async () => {
            // Cerrar modal con manejo mejorado
            const modalElement = document.getElementById('unassignConfirmModal');
            const modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (modalInstance) {
                modalInstance.hide();
            } else {
                // Si no hay instancia, crear una nueva y cerrarla
                const newModal = new bootstrap.Modal(modalElement);
                newModal.hide();
            }
            
            // Limpiar cualquier backdrop residual después de un pequeño delay
            setTimeout(() => {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                // Remover clase modal-open del body si no hay otros modales abiertos
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.body.classList.remove('modal-open');
                }
            }, 300);
            
            // Ejecutar desasignación real
            if (isIndividual) {
                await this.executeUnassignUser(studyId, userId);
            } else {
                await this.executeUnassignAll(studyId);
            }
        });
        
        // Limpiar modal al cerrar
        document.getElementById('unassignConfirmModal').addEventListener('hidden.bs.modal', function() {
            // Limpiar cualquier backdrop residual
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            // Remover clase modal-open del body si no hay otros modales abiertos
            if (document.querySelectorAll('.modal.show').length === 0) {
                document.body.classList.remove('modal-open');
            }
            // Remover el modal del DOM
            this.remove();
        });
    }
    
    /**
     * Ejecuta la desasignación individual de un usuario
     */
    async executeUnassignUser(studyId, userId) {
        try {
            console.log('🔍 Debug - Ejecutando desasignación individual:', { studyId, userId });
            
            const currentUser = this.getCurrentUser();
            if (!currentUser) {
                this.showError('No se pudo obtener información del usuario actual');
                return;
            }
            
            const response = await fetch('api/unassign_study.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    study_id: studyId,
                    user_id: userId,
                    assigned_by: currentUser.id
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showSuccess(result.message);
                
                // Recargar asignaciones y actualizar tabla
                await this.loadStudyAssignments();
                this.renderStudies();
                
            } else {
                throw new Error(result.error || 'Error al desasignar usuario');
            }
            
        } catch (error) {
            console.error('Error desasignando usuario:', error);
            this.showError('Error al desasignar usuario: ' + error.message);
        }
    }
    
    /**
     * Ejecuta la desasignación de todos los usuarios
     */
    async executeUnassignAll(studyId) {
        try {
            console.log('🔍 Debug - Ejecutando desasignación de todos:', studyId);
            
            const currentUser = this.getCurrentUser();
            if (!currentUser) {
                this.showError('No se pudo obtener información del usuario actual');
                return;
            }
            
            const response = await fetch('api/unassign_study.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    study_id: studyId,
                    assigned_by: currentUser.id
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showSuccess(result.message);
                
                // Recargar asignaciones y actualizar tabla
                await this.loadStudyAssignments();
                this.renderStudies();
                
            } else {
                throw new Error(result.error || 'Error al desasignar usuarios');
            }
            
        } catch (error) {
            console.error('Error desasignando usuarios:', error);
            this.showError('Error al desasignar usuarios: ' + error.message);
        }
    }
    
    /**
     * Cambia la asignación de un estudio de un usuario a otro
     */
    async changeAssignment(studyId, fromUserId) {
        try {
            console.log('🔍 Debug - Cambiando asignación:', { studyId, fromUserId });
            
            const currentUser = this.getCurrentUser();
            if (!currentUser) {
                this.showError('No se pudo obtener información del usuario actual');
                return;
            }
            
            // Mostrar modal para seleccionar nuevo usuario
            this.showReassignModal(studyId, fromUserId);
            
        } catch (error) {
            console.error('Error cambiando asignación:', error);
            this.showError('Error al cambiar asignación: ' + error.message);
        }
    }
    
    /**
     * Muestra modal para reasignar estudio
     */
    async showReassignModal(studyId, fromUserId) {
        console.log('🔍 Debug - Abriendo modal de reasignación:', { studyId, fromUserId });
        
        // Crear modal si no existe
        const modal = document.getElementById('reassignModal');
        if (!modal) {
            console.log('🔍 Debug - Creando modal de reasignación...');
            this.createReassignModal();
        }
        
        // Verificar si los usuarios están cargados, si no, cargarlos
        if (!this.users || this.users.length === 0) {
            console.log('🔍 Debug - Usuarios no cargados, cargando...');
            await this.loadUsers();
        }
        
        // Configurar datos del modal
        document.getElementById('reassignStudyId').value = studyId;
        document.getElementById('reassignFromUserId').value = fromUserId;
        
        // Poblar información del estudio
        const study = this.studies.find(s => s.id === studyId);
        const studyInfoElement = document.getElementById('reassignStudyInfo');
        console.log('🔍 Debug - Estudio encontrado:', study);
        
        if (study && studyInfoElement) {
            studyInfoElement.innerHTML = `
                <div class="fw-semibold">${study.patient_name}</div>
                <small class="text-muted">ID: ${study.patient_id} | ${study.modality}</small>
            `;
            console.log('✅ Debug - Información del estudio poblada');
        } else {
            console.warn('⚠️ Debug - No se encontró el estudio o elemento:', { study, studyInfoElement });
        }
        
        // Poblar información del usuario actual
        const fromUser = this.users.find(u => u.id == fromUserId);
        const fromUserInfoElement = document.getElementById('reassignFromUserInfo');
        console.log('🔍 Debug - Usuario actual encontrado:', fromUser);
        
        if (fromUser && fromUserInfoElement) {
            fromUserInfoElement.innerHTML = `
                <div class="fw-semibold">${fromUser.nombre} ${fromUser.apellido}</div>
                <small class="text-muted">${fromUser.email} | ${fromUser.matricula_profesional}</small>
            `;
            console.log('✅ Debug - Información del usuario actual poblada');
        } else {
            console.warn('⚠️ Debug - No se encontró el usuario actual o elemento:', { fromUser, fromUserInfoElement });
        }
        
        // Cargar usuarios disponibles INMEDIATAMENTE
        console.log('🔍 Debug - Cargando usuarios inmediatamente...');
        await this.loadUsersForReassignImmediate(fromUserId);
        
        // Mostrar modal DESPUÉS de cargar usuarios
        console.log('🔍 Debug - Mostrando modal...');
        const bootstrapModal = new bootstrap.Modal(document.getElementById('reassignModal'), {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        bootstrapModal.show();
        
        console.log('✅ Debug - Modal de reasignación mostrado');
    }
    
    /**
     * Crea el modal de reasignación
     */
    createReassignModal() {
        const modalHTML = `
            <div class="modal fade" id="reassignModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-exchange-alt me-2"></i>
                                Cambiar Asignación de Estudio
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Estudio:</label>
                                <div id="reassignStudyInfo" class="form-control-plaintext"></div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Usuario actual:</label>
                                <div id="reassignFromUserInfo" class="form-control-plaintext"></div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="reassignToUserId" class="form-label">Asignar a:</label>
                                <div class="d-flex gap-2">
                                    <select class="form-select" id="reassignToUserId" required>
                                        <option value="">Seleccionar usuario...</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-secondary" onclick="derivacionesManager.reloadUsersForReassign()" title="Recargar usuarios">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                                <div id="loadingUsersIndicator" class="form-text text-info" style="display: none;">
                                    <i class="fas fa-spinner fa-spin me-1"></i>Cargando usuarios...
                                </div>
                            </div>
                            
                            <input type="hidden" id="reassignStudyId">
                            <input type="hidden" id="reassignFromUserId">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="confirmReassignBtn">
                                <i class="fas fa-exchange-alt me-2"></i>Cambiar Asignación
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Configurar event listener
        document.getElementById('confirmReassignBtn').addEventListener('click', () => {
            this.confirmReassignment();
        });
        
        // Configurar evento para limpiar el modal cuando se cierre
        document.getElementById('reassignModal').addEventListener('hidden.bs.modal', () => {
            // Limpiar cualquier backdrop residual
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            // Remover clase modal-open del body si no hay otros modales abiertos
            if (document.querySelectorAll('.modal.show').length === 0) {
                document.body.classList.remove('modal-open');
            }
        });
    }
    
    /**
     * Carga usuarios disponibles para reasignación (versión inmediata)
     */
    async loadUsersForReassignImmediate(excludeUserId) {
        console.log('🔍 Debug - loadUsersForReassignImmediate ejecutada con excludeUserId:', excludeUserId);
        console.log('🔍 Debug - this.users disponible:', this.users ? this.users.length : 'NO DISPONIBLE');
        console.log('🔍 Debug - Estructura completa de usuarios:', this.users);
        
        // Mostrar los primeros 3 usuarios para debugging
        if (this.users && this.users.length > 0) {
            console.log('🔍 Debug - Primeros 3 usuarios:', this.users.slice(0, 3));
        }
        
        // Si no hay usuarios cargados, cargarlos desde la API
        if (!this.users || this.users.length === 0) {
            console.log('🔍 Debug - Usuarios no disponibles, cargando desde API de gestión...');
            try {
                const response = await fetch('api/users/manage-real-complete.php');
                const result = await response.json();
                
                if (result.success && result.data && result.data.users) {
                    this.users = result.data.users;
                    console.log('✅ Debug - Usuarios cargados desde API de gestión:', this.users.length);
                } else {
                    console.warn('⚠️ Debug - Error cargando usuarios desde API:', result);
                    // Usar usuarios de ejemplo como fallback
                    this.users = [
                        { id: 1, nombre: 'Usuario', apellido: 'ROOT', email: 'root@test.com', nivel: 'root', activo: 1 },
                        { id: 2, nombre: 'Usuario', apellido: 'ADMIN', email: 'admin@test.com', nivel: 'admin', activo: 1 },
                        { id: 3, nombre: 'Usuario', apellido: 'USER', email: 'user@test.com', nivel: 'user', activo: 1 }
                    ];
                    console.log('🔧 Debug - Usando usuarios de ejemplo:', this.users.length);
                }
            } catch (error) {
                console.error('❌ Debug - Error cargando usuarios:', error);
                // Usar usuarios de ejemplo como fallback
                this.users = [
                    { id: 1, nombre: 'Usuario', apellido: 'ROOT', email: 'root@test.com', nivel: 'root', activo: 1 },
                    { id: 2, nombre: 'Usuario', apellido: 'ADMIN', email: 'admin@test.com', nivel: 'admin', activo: 1 },
                    { id: 3, nombre: 'Usuario', apellido: 'USER', email: 'user@test.com', nivel: 'user', activo: 1 }
                ];
                console.log('🔧 Debug - Usando usuarios de ejemplo como fallback:', this.users.length);
            }
        }
        
        if (!this.users || this.users.length === 0) {
            console.warn('⚠️ Debug - No hay usuarios disponibles después de todos los intentos');
            return;
        }
        
        // SOLUCIÓN TEMPORAL: Ignorar filtro de activo para debugging
        const availableUsers = this.users.filter(user => {
            const userId = parseInt(user.id);
            const excludeId = parseInt(excludeUserId);
            const isActive = user.activo === 1 || user.activo === true || user.activo === '1';
            
            console.log('🔍 Debug - Filtro usuario:', {
                id: user.id,
                userId: userId,
                excludeId: excludeId,
                activo: user.activo,
                isActive: isActive,
                nombre: user.nombre + ' ' + user.apellido,
                pasaFiltro: userId !== excludeId // TEMPORAL: solo excluir usuario actual
            });
            
            // TEMPORAL: Solo excluir el usuario actual, ignorar campo activo
            return userId !== excludeId;
        });
        console.log('🔍 Debug - Usuarios filtrados:', availableUsers.length);
        console.log('🔍 Debug - Usuarios disponibles:', availableUsers);
        
        if (availableUsers.length === 0) {
            console.warn('⚠️ Debug - No hay usuarios disponibles después del filtro');
            return;
        }
        
        // Esperar un poco para asegurar que el DOM esté listo
        await new Promise(resolve => setTimeout(resolve, 100));
        
        // Buscar el elemento select
        const select = document.getElementById('reassignToUserId');
        console.log('🔍 Debug - Elemento select encontrado:', !!select);
        
            if (select) {
                select.innerHTML = '<option value="">Seleccionar usuario...</option>' +
                    availableUsers.map(user => {
                        const nivel = user.nivel ? user.nivel.toUpperCase() : 'N/A';
                        const nombre = user.nombre || 'Sin nombre';
                        const apellido = user.apellido || 'Sin apellido';
                        const email = user.email || 'Sin email';
                        
                        return `<option value="${user.id}">
                            ${nombre} ${apellido} (${nivel}) - ${email}
                        </option>`;
                    }).join('');
                
                console.log('✅ Debug - Dropdown poblado con', availableUsers.length, 'usuarios');
                console.log('🔍 Debug - HTML del select:', select.innerHTML.substring(0, 200) + '...');
            } else {
                console.warn('⚠️ Debug - Elemento select no encontrado, pero usuarios están listos');
            }
        
        console.log('✅ Debug - Carga inmediata de usuarios completada');
    }

    /**
     * Carga usuarios disponibles para reasignación
     */
    async loadUsersForReassign(excludeUserId) {
        console.log('🔍 Debug - loadUsersForReassign ejecutada con excludeUserId:', excludeUserId);
        console.log('🔍 Debug - this.users disponible:', this.users ? this.users.length : 'NO DISPONIBLE');
        
        const select = document.getElementById('reassignToUserId');
        const loadingIndicator = document.getElementById('loadingUsersIndicator');
        console.log('🔍 Debug - Elemento select encontrado:', !!select);
        
        if (!select) {
            console.error('❌ Debug - No se encontró el elemento reassignToUserId');
            return;
        }
        
        // Mostrar indicador de carga
        if (loadingIndicator) {
            loadingIndicator.style.display = 'block';
        }
        
        // Deshabilitar el select mientras carga
        select.disabled = true;
        
        try {
            // Si no hay usuarios cargados, intentar cargarlos desde la API de gestión
            if (!this.users || this.users.length === 0) {
                console.log('🔍 Debug - Usuarios no disponibles, cargando desde API de gestión...');
                try {
                    const response = await fetch('api/users/manage-real-complete.php');
                    const result = await response.json();
                    
                    if (result.success && result.data && result.data.users) {
                        this.users = result.data.users;
                        console.log('✅ Debug - Usuarios cargados desde API de gestión:', this.users.length);
                    } else {
                        console.warn('⚠️ Debug - Error cargando usuarios desde API:', result);
                        // Usar usuarios de ejemplo como fallback
                        this.users = [
                            { id: 1, nombre: 'Usuario', apellido: 'ROOT', email: 'root@test.com', nivel: 'root', activo: 1 },
                            { id: 2, nombre: 'Usuario', apellido: 'ADMIN', email: 'admin@test.com', nivel: 'admin', activo: 1 },
                            { id: 3, nombre: 'Usuario', apellido: 'USER', email: 'user@test.com', nivel: 'user', activo: 1 }
                        ];
                        console.log('🔧 Debug - Usando usuarios de ejemplo:', this.users.length);
                    }
                } catch (error) {
                    console.error('❌ Debug - Error cargando usuarios:', error);
                    // Usar usuarios de ejemplo como fallback
                    this.users = [
                        { id: 1, nombre: 'Usuario', apellido: 'ROOT', email: 'root@test.com', nivel: 'root', activo: 1 },
                        { id: 2, nombre: 'Usuario', apellido: 'ADMIN', email: 'admin@test.com', nivel: 'admin', activo: 1 },
                        { id: 3, nombre: 'Usuario', apellido: 'USER', email: 'user@test.com', nivel: 'user', activo: 1 }
                    ];
                    console.log('🔧 Debug - Usando usuarios de ejemplo como fallback:', this.users.length);
                }
            }
            
            if (!this.users || this.users.length === 0) {
                console.warn('⚠️ Debug - No hay usuarios disponibles después de todos los intentos');
                select.innerHTML = '<option value="">No hay usuarios disponibles</option>';
                return;
            }
            
            const availableUsers = this.users.filter(user => user.id != excludeUserId && user.activo);
            console.log('🔍 Debug - Usuarios filtrados:', availableUsers.length);
            console.log('🔍 Debug - Usuarios disponibles:', availableUsers);
            
            if (availableUsers.length === 0) {
                console.warn('⚠️ Debug - No hay usuarios disponibles después del filtro');
                select.innerHTML = '<option value="">No hay usuarios disponibles</option>';
                return;
            }
            
            select.innerHTML = '<option value="">Seleccionar usuario...</option>' +
                availableUsers.map(user => 
                    `<option value="${user.id}">
                        ${user.nombre} ${user.apellido} (${user.nivel.toUpperCase()}) - ${user.email}
                    </option>`
                ).join('');
            
            console.log('✅ Debug - Dropdown poblado con', availableUsers.length, 'usuarios');
            console.log('🔍 Debug - HTML del select:', select.innerHTML.substring(0, 200) + '...');
            
        } catch (error) {
            console.error('❌ Debug - Error general en loadUsersForReassign:', error);
            select.innerHTML = '<option value="">Error cargando usuarios</option>';
        } finally {
            // Ocultar indicador de carga y habilitar select
            if (loadingIndicator) {
                loadingIndicator.style.display = 'none';
            }
            select.disabled = false;
            console.log('✅ Debug - Carga de usuarios completada');
        }
    }
    
    /**
     * Recarga usuarios para reasignación (función manual)
     */
    async reloadUsersForReassign() {
        console.log('🔄 Debug - Recargando usuarios manualmente...');
        
        const fromUserId = document.getElementById('reassignFromUserId').value;
        if (!fromUserId) {
            console.warn('⚠️ Debug - No hay fromUserId disponible');
            return;
        }
        
        // Limpiar usuarios actuales para forzar recarga
        this.users = null;
        
        // Recargar usuarios
        await this.loadUsersForReassign(fromUserId);
        
        console.log('✅ Debug - Recarga manual completada');
    }

    /**
     * Confirma la reasignación
     */
    async confirmReassignment() {
        try {
            const studyId = document.getElementById('reassignStudyId').value;
            const fromUserId = document.getElementById('reassignFromUserId').value;
            const toUserId = document.getElementById('reassignToUserId').value;
            
            if (!toUserId) {
                this.showError('Por favor selecciona un usuario');
                return;
            }
            
            const currentUser = this.getCurrentUser();
            if (!currentUser) {
                this.showError('No se pudo obtener información del usuario actual');
                return;
            }
            
            const response = await fetch('api/reassign_study.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    study_id: studyId,
                    from_user_id: fromUserId,
                    to_user_id: toUserId,
                    assigned_by: currentUser.id
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showSuccess(result.message);
                
                // Cerrar modal con manejo mejorado
                const modalElement = document.getElementById('reassignModal');
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                modal.hide();
                } else {
                    // Si no hay instancia, crear una nueva y cerrarla
                    const newModal = new bootstrap.Modal(modalElement);
                    newModal.hide();
                }
                
                // Limpiar cualquier backdrop residual después de un pequeño delay
                setTimeout(() => {
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => {
                        if (backdrop.parentNode) {
                            backdrop.parentNode.removeChild(backdrop);
                        }
                    });
                    // Remover clase modal-open del body si no hay otros modales abiertos
                    if (document.querySelectorAll('.modal.show').length === 0) {
                        document.body.classList.remove('modal-open');
                    }
                }, 300);
                
                // Recargar asignaciones y actualizar tabla
                await this.loadStudyAssignments();
                this.renderStudies();
                
            } else {
                throw new Error(result.error || 'Error al cambiar asignación');
            }
            
        } catch (error) {
            console.error('Error confirmando reasignación:', error);
            this.showError('Error al cambiar asignación: ' + error.message);
        }
    }
    
    /**
     * Muestra detalles de asignaciones múltiples
     */
    showAssignmentDetails(studyId) {
        const assignments = this.getStudyAssignments(studyId);
        if (!assignments || assignments.length === 0) {
            this.showError('No se encontraron asignaciones');
            return;
        }
        
        const study = this.studies.find(s => s.id === studyId);
        const studyInfo = study ? `${study.patient_name} - ${study.patient_id}` : 'Estudio desconocido';
        
        let detailsHTML = `
            <div class="assignment-details">
                <h6>Asignaciones para: ${studyInfo}</h6>
                <div class="list-group">
        `;
        
        assignments.forEach(assignment => {
            detailsHTML += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${assignment.usuario_nombre} ${assignment.usuario_apellido}</strong>
                        <br>
                        <small class="text-muted">${assignment.matricula_profesional}</small>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-warning" 
                                onclick="derivacionesManager.changeAssignment('${studyId}', ${assignment.user_id})" 
                                title="Cambiar asignación">
                            <i class="fas fa-exchange-alt"></i>
                        </button>
                        <button class="btn btn-outline-danger" 
                                onclick="derivacionesManager.unassignUser('${studyId}', ${assignment.user_id})" 
                                title="Desasignar">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        
        detailsHTML += `
                </div>
            </div>
        `;
        
        // Crear y mostrar modal dinámico
        this.createDynamicInfoModal('Detalles de Asignaciones', detailsHTML);
    }

    /**
     * Maneja la selección de estudios individuales
     */
    handleStudySelection() {
        console.log('🔍 Debug - handleStudySelection() ejecutada');
        const checkboxes = document.querySelectorAll('.study-checkbox');
        const checkedBoxes = document.querySelectorAll('.study-checkbox:checked');
        const selectAllCheckbox = document.getElementById('selectAllStudies');
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');
        const assignSelectedBtn = document.getElementById('assignSelectedBtn');
        
        console.log('🔍 Debug - Elementos encontrados:', {
            checkboxes: checkboxes.length,
            checkedBoxes: checkedBoxes.length,
            selectAllCheckbox: !!selectAllCheckbox,
            bulkActions: !!bulkActions,
            selectedCount: !!selectedCount,
            assignSelectedBtn: !!assignSelectedBtn
        });
        
        // Actualizar contador
        if (selectedCount) {
            selectedCount.textContent = checkedBoxes.length;
        }
        
        // Mostrar/ocultar acciones masivas (solo si tiene permiso de asignaciones)
        if (bulkActions) {
            if (checkedBoxes.length > 0 && this.hasAsignaciones) {
                bulkActions.classList.remove('d-none');
                console.log('🔍 Debug - Acciones masivas mostradas');
            } else {
                bulkActions.classList.add('d-none');
                console.log('🔍 Debug - Acciones masivas ocultas (sin permiso o sin selección)');
            }
        }
        
        // Habilitar/deshabilitar botón de asignación (solo si tiene permiso)
        if (assignSelectedBtn) {
            if (!this.hasAsignaciones) {
                assignSelectedBtn.disabled = true;
                assignSelectedBtn.style.display = 'none'; // Ocultar completamente
            } else {
            assignSelectedBtn.disabled = checkedBoxes.length === 0;
                assignSelectedBtn.style.display = '';
            console.log('🔍 Debug - Botón asignación:', checkedBoxes.length === 0 ? 'deshabilitado' : 'habilitado');
            }
        }
        
        // Actualizar checkbox "Seleccionar todos"
        if (selectAllCheckbox) {
            if (checkedBoxes.length === 0) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            } else if (checkedBoxes.length === checkboxes.length) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.indeterminate = true;
            }
        }
        
        // Guardar selecciones automáticamente cuando cambian
        this.savePersistentState();
    }
    
    /**
     * Maneja la selección/deselección de todos los estudios
     */
    handleSelectAll() {
        const selectAllCheckbox = document.getElementById('selectAllStudies');
        const checkboxes = document.querySelectorAll('.study-checkbox');
        
        if (!selectAllCheckbox) return;
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAllCheckbox.checked;
        });
        
        this.handleStudySelection();
    }
    
    /**
     * Limpia la selección de todos los estudios
     */
    clearSelection() {
        const checkboxes = document.querySelectorAll('.study-checkbox');
        const selectAllCheckbox = document.getElementById('selectAllStudies');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
        
        this.handleStudySelection();
    }
    
    /**
     * Restaura las selecciones guardadas en el estado persistente
     */
    restoreSelections() {
        try {
            const persistentState = this.loadPersistentState();
            if (!persistentState || !persistentState.selectedStudyIds || persistentState.selectedStudyIds.length === 0) {
                return; // No hay selecciones guardadas
            }
            
            const selectedIds = persistentState.selectedStudyIds;
            console.log('🔄 Restaurando selecciones:', selectedIds.length, 'estudios');
            
            // Restaurar checkboxes seleccionados
            selectedIds.forEach(studyId => {
                const checkbox = document.querySelector(`.study-checkbox[data-study-id="${studyId}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            
            // Actualizar estado de selección después de restaurar
            this.handleStudySelection();
        } catch (error) {
            console.warn('Error restaurando selecciones:', error);
        }
    }
    
    /**
     * Localiza el objeto de estudio para asignación (evita fallos por === entre string/number o solo en filteredStudies).
     */
    findStudyForAssignment(studyId) {
        const sid = studyId != null ? String(studyId) : '';
        if (!sid) return null;
        const pools = [this.filteredStudies, this.studies];
        for (const list of pools) {
            if (!Array.isArray(list)) continue;
            const found = list.find((s) => {
                if (!s) return false;
                return String(s.id) === sid
                    || String(s.orthanc_id ?? '') === sid
                    || String(s.orthanc_study_id ?? '') === sid
                    || String(s.study_instance_uid ?? '') === sid;
            });
            if (found) return found;
        }
        return null;
    }
    
    /**
     * Obtiene los IDs de los estudios seleccionados
     */
    getSelectedStudyIds() {
        const checkedBoxes = document.querySelectorAll('.study-checkbox:checked');
        console.log('🔍 Debug - Checkboxes encontrados:', checkedBoxes.length);
        
        const selectedIds = Array.from(checkedBoxes).map(checkbox => {
            const studyId = checkbox.getAttribute('data-study-id');
            console.log('🔍 Debug - Checkbox ID:', studyId, 'Elemento:', checkbox);
            return studyId;
        }).filter(id => id !== null && id !== undefined);
        
        console.log('🔍 Debug - IDs seleccionados:', selectedIds);
        return selectedIds;
    }
    
    /**
     * Limpia el estado de modales anteriores
     */
    cleanupModalState() {
        try {
            // Limpiar todas las instancias de modal
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                const instance = bootstrap.Modal.getInstance(modal);
                if (instance) {
                    try {
                        instance.dispose();
                    } catch (disposeError) {
                        console.warn('Error al limpiar instancia de modal:', disposeError);
                    }
                }
            });
            
            // Limpiar todos los backdrops residuales
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                try {
                    backdrop.remove();
                } catch (removeError) {
                    console.warn('Error al remover backdrop:', removeError);
                }
            });
            
            // Remover clases del body
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            console.log('Estado del modal limpiado completamente');
        } catch (error) {
            console.error('Error limpiando estado del modal:', error);
        }
    }
    
    /**
     * Abre el modal de asignación múltiple
     */
    openBulkAssignModal() {
        console.log('🔍 Debug - Abriendo modal de asignación múltiple');
        const selectedIds = this.getSelectedStudyIds();
        console.log('🔍 Debug - IDs obtenidos:', selectedIds);
        
        if (selectedIds.length === 0) {
            console.log('❌ Debug - No hay estudios seleccionados');
            this.showError('No hay estudios seleccionados');
            return;
        }
        
        console.log('✅ Debug - Procesando', selectedIds.length, 'estudios seleccionados');
        
        // Limpiar estado de modales anteriores
        this.cleanupModalState();
        
        // Esperar un momento para que se limpie completamente
        setTimeout(() => {
        // Usar el modal existente pero con múltiples estudios
            const modalElement = document.getElementById('assignStudyModal');
            
            // Verificar si hay una instancia previa y limpiarla
            const existingInstance = bootstrap.Modal.getInstance(modalElement);
            if (existingInstance) {
                existingInstance.dispose();
            }
            
            // Crear nueva instancia del modal
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
        
        // Actualizar el título del modal
        const modalTitle = document.getElementById('assignStudyModalLabel');
        if (modalTitle) {
            modalTitle.innerHTML = `
                <i class="fas fa-share-alt me-2"></i>
                Asignar ${selectedIds.length} Estudios Seleccionados
            `;
        }
        
        // Mostrar información de los estudios seleccionados
        this.updateBulkModalContent(selectedIds);
            
            // Configurar evento para limpiar el modal cuando se cierre
            const cleanupHandler = () => {
                // Limpiar cualquier backdrop residual
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                // Remover clase modal-open del body si no hay otros modales abiertos
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            };
            
            // Remover listener anterior si existe
            modalElement.removeEventListener('hidden.bs.modal', cleanupHandler);
            // Agregar nuevo listener
            modalElement.addEventListener('hidden.bs.modal', cleanupHandler);
        
        modal.show();
        }, 100);
    }
    
    /**
     * Actualiza el contenido del modal para asignación múltiple
     */
    updateBulkModalContent(studyIds) {
        const selectedStudies = this.studies.filter(study => studyIds.includes(study.id));
        
        // Crear lista de estudios seleccionados
        let studiesListHtml = '';
        selectedStudies.forEach(study => {
            studiesListHtml += `
                <div class="card mb-2">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Paciente:</strong> ${study.patient_name}<br>
                                <strong>ID Paciente:</strong> ${study.patient_id}<br>
                                <strong>Modalidad:</strong> ${study.modality}
                            </div>
                            <div class="col-md-6">
                                <strong>Fecha:</strong> ${this.formatDate(study.date || study.study_date)}<br>
                                <strong>Descripción:</strong> ${study.study_description || 'N/A'}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        // Actualizar el contenido del modal
        const modalBody = document.querySelector('#assignStudyModal .modal-body');
        if (modalBody) {
            modalBody.innerHTML = `
                <div class="row mb-3">
                    <div class="col-12">
                        <h6>Estudios Seleccionados (${studyIds.length}):</h6>
                        <div class="studies-list" style="max-height: 200px; overflow-y: auto;">
                            ${studiesListHtml}
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <h6>Seleccionar Usuario(s) para Asignación:</h6>
                        <div id="modalUsersContainer" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            <!-- Los usuarios se cargarán aquí -->
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Cargar usuarios en el modal
        this.loadUsersForModal();
    }
    
    /**
     * Carga usuarios para el modal de asignación
     */
    loadUsersForModal() {
        console.log('🔍 Debug - loadUsersForModal() ejecutada');
        const container = document.getElementById('modalUsersContainer');
        if (!container) {
            console.log('❌ Debug - No se encontró modalUsersContainer');
            return;
        }
        
        console.log('🔍 Debug - Usuarios disponibles:', this.users.length);
        
        let html = '';
        this.users.forEach(user => {
            html += `
                <div class="form-check mb-2">
                    <input class="form-check-input modal-user-checkbox" type="checkbox" 
                           value="${user.id}" id="user_${user.id}">
                    <label class="form-check-label" for="user_${user.id}">
                        <div class="d-flex align-items-center">
                            <div class="user-avatar me-2">
                                <i class="fas fa-user-circle text-primary"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">${user.nombre} ${user.apellido}</div>
                                <small class="text-muted">${user.matricula_profesional}</small>
                            </div>
                        </div>
                    </label>
                </div>
            `;
        });
        
        container.innerHTML = html;
        console.log('✅ Debug - Usuarios cargados en modal:', this.users.length);
    }
    
    /**
     * Finaliza la asignación múltiple con éxito (cierra modal, recarga grilla).
     */
    async _finishBulkAssignmentSuccess(message) {
        this.showSuccess(message);

        const modal = bootstrap.Modal.getInstance(document.getElementById('assignStudyModal'));
        if (modal) {
            modal.hide();
        }

        this.clearSelection();
        await this.loadStudyAssignments();
        this.renderStudies();
    }

    /**
     * Tras 502/timeout: verifica si la asignación se aplicó igual en el servidor.
     */
    async _tryRecoverBulkAssignmentFromGatewayError(studyIds, userIds) {
        await this.loadStudyAssignments();

        const allApplied = studyIds.every(studyId => {
            const assignments = this.getStudyAssignments(studyId);
            return userIds.every(userId =>
                assignments.some(a => parseInt(a.user_id, 10) === parseInt(userId, 10))
            );
        });

        if (!allApplied) {
            return false;
        }

        await this._finishBulkAssignmentSuccess(
            `Asignación completada (${studyIds.length} estudios). El servidor tardó en responder.`
        );
        return true;
    }

    /**
     * Confirma la asignación múltiple
     */
    async confirmBulkAssignment() {
        console.log('🔍 Debug - confirmBulkAssignment() ejecutada');
        const selectedStudyIds = this.getSelectedStudyIds();
        const selectedUserIds = Array.from(document.querySelectorAll('.modal-user-checkbox:checked'))
            .map(checkbox => parseInt(checkbox.value));
        
        console.log('🔍 Debug - Estudios seleccionados:', selectedStudyIds);
        console.log('🔍 Debug - Usuarios seleccionados:', selectedUserIds);
        
        if (selectedUserIds.length === 0) {
            console.log('❌ Debug - No hay usuarios seleccionados');
            this.showError('Debe seleccionar al menos un usuario');
            return;
        }
        
        try {
            // Obtener usuario actual (asignador)
            let currentUser = this.getCurrentUser();
            
            // Si no se obtuvo el usuario, intentar obtenerlo de la API
            if (!currentUser && typeof window.getCurrentUser === 'function') {
                currentUser = window.getCurrentUser();
            }
            
            // Si aún no hay usuario, hacer una llamada a la API para obtenerlo
            if (!currentUser) {
                try {
                    const userResponse = await fetch(this.apiBaseUrl + '../auth/validate-session-simple.php', {
                        method: 'GET',
                        credentials: 'include'
                    });
                    const userResult = await userResponse.json();
                    if (userResult.success && userResult.user) {
                        currentUser = userResult.user;
                        // Guardar en localStorage para futuras consultas
                        localStorage.setItem('user', JSON.stringify(currentUser));
                        console.log('Usuario obtenido de API:', currentUser);
                    }
                } catch (apiError) {
                    console.error('Error obteniendo usuario de API:', apiError);
                }
            }
            
            if (!currentUser || !currentUser.id) {
                throw new Error('No se pudo obtener información del usuario actual. Por favor, recarga la página.');
            }
            
            console.log('✅ Usuario actual obtenido:', currentUser);

            for (const sid of selectedStudyIds) {
                const st = this.findStudyForAssignment(sid);
                if (st && st.em_remote_only === true) {
                    await this.triggerRemoteRetrieveIfNeeded(st);
                }
            }
            
            // Obtener datos completos de los estudios seleccionados
            const studiesData = {};
            selectedStudyIds.forEach(studyId => {
                const study = this.findStudyForAssignment(studyId);
                if (study) {
                    const institutionName = study.institution_name || study.InstitutionName || null;
                    const remoteOnly = study.em_remote_only === true;
                    console.log(`🔍 Estudio ${studyId} - institution_name:`, institutionName, '| study object:', study);
                    
                    studiesData[studyId] = {
                        patient_name: study.patient_name,
                        patient_id: study.patient_id,
                        study_date: study.study_date || study.date,
                        study_time: study.study_time || study.time,
                        modality: study.modality,
                        study_description: study.study_description,
                        accession_number: study.accession_number,
                        referring_physician: study.referring_physician,
                        study_instance_uid: study.study_instance_uid,
                        series_count: study.series_count || 0,
                        instances_count: study.instances_count || 0,
                        viewer_url: study.viewer_url,
                        orthanc_study_id: remoteOnly
                            ? (study.orthanc_study_id || study.orthanc_id || null)
                            : (study.orthanc_study_id || study.orthanc_id || study.id || null),
                        patient_birth_date: study.patient_birth_date,
                        patient_sex: study.patient_sex,
                        institution_name: institutionName,
                        em_remote_only: remoteOnly,
                        em_remote_node_id: remoteOnly ? (study.em_remote_node_id || null) : null,
                        em_pending_orthanc: remoteOnly,
                    };
                }
            });
            
            console.log('📦 studiesData a enviar:', JSON.stringify(studiesData, null, 2));

            // Obtener token de sesión
            const sessionToken = this.getSessionToken ? this.getSessionToken() : 
                                (localStorage.getItem('session_token') || 
                                 document.cookie.split('; ').find(row => row.startsWith('session_token='))?.split('=')[1]);
            
            const response = await fetch(this.apiBaseUrl + 'assign_multiple_studies.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': sessionToken ? `Bearer ${sessionToken}` : ''
                },
                credentials: 'include', // Incluir cookies automáticamente
                body: JSON.stringify({
                    study_ids: selectedStudyIds,
                    user_ids: selectedUserIds,
                    assigned_by: currentUser.id, // Mantener para compatibilidad, pero el backend usa la sesión
                    studies_data: studiesData,
                    session_token: sessionToken // Incluir también en el body por compatibilidad
                })
            });

            const responseText = await response.text();
            let result = null;

            if (responseText) {
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    const recovered = await this._tryRecoverBulkAssignmentFromGatewayError(
                        selectedStudyIds,
                        selectedUserIds
                    );
                    if (recovered) {
                        return;
                    }

                    const gatewayMsg = response.status === 502
                        ? 'El servidor tardó en responder. Verifique si los estudios quedaron asignados.'
                        : 'Respuesta inválida del servidor. Intente nuevamente.';
                    throw new Error(gatewayMsg);
                }
            }

            if (!response.ok) {
                const recovered = await this._tryRecoverBulkAssignmentFromGatewayError(
                    selectedStudyIds,
                    selectedUserIds
                );
                if (recovered) {
                    return;
                }
                throw new Error((result && result.error) || `Error del servidor (${response.status})`);
            }

            if (result && result.success) {
                await this._finishBulkAssignmentSuccess(result.message);
            } else {
                throw new Error((result && result.error) || 'Error en la asignación');
            }
            
        } catch (error) {
            console.error('Error en asignación múltiple:', error);
            this.showError('Error asignando estudios: ' + error.message);
        }
    }
    
    /**
     * Carga las asignaciones de estudios desde la API
     */
    async loadStudyAssignments() {
        try {
            const response = await fetch(this.apiBaseUrl + 'get_study_assignments.php');
            const result = await response.json();
            
            if (result.success) {
                this.studyAssignments = result.grouped_data || {};
                console.log('Asignaciones cargadas:', Object.keys(this.studyAssignments).length, 'estudios');
            } else {
                console.warn('Error cargando asignaciones:', result.error);
                this.studyAssignments = {};
            }
            this.updateAssignmentUserFilterOptions();
        } catch (error) {
            console.error('Error cargando asignaciones:', error);
            this.studyAssignments = {};
            this.updateAssignmentUserFilterOptions();
        }
    }
    
    /**
     * Carga las subasignaciones de estudios desde la API
     */
    async loadStudySubassignments() {
        try {
            const response = await fetch(this.apiBaseUrl + 'get_subassignments.php');
            const result = await response.json();
            
            if (result.success && result.data && result.data.subassignments) {
                // Agrupar subasignaciones por study_id
                const grouped = {};
                result.data.subassignments.forEach(sub => {
                    if (!grouped[sub.study_id]) {
                        grouped[sub.study_id] = [];
                    }
                    grouped[sub.study_id].push(sub);
                });
                this.studySubassignments = grouped;
                console.log('Subasignaciones cargadas:', Object.keys(this.studySubassignments).length, 'estudios');
            } else {
                console.warn('No hay subasignaciones o error al cargarlas');
                this.studySubassignments = {};
            }
            this.updateAssignmentUserFilterOptions();
        } catch (error) {
            console.error('Error cargando subasignaciones:', error);
            this.studySubassignments = {};
            this.updateAssignmentUserFilterOptions();
        }
    }
    
    /**
     * Obtiene las subasignaciones para un estudio específico
     */
    getStudySubassignments(studyId) {
        if (!this.studySubassignments) {
            return [];
        }
        const k = studyId != null ? String(studyId) : '';
        return this.studySubassignments[k] || this.studySubassignments[studyId] || [];
    }
    
    /**
     * Carga el estado de antecedentes para todos los estudios
     * OPTIMIZADO: Usa POST y divide en lotes para evitar error 414
     */
    async loadAntecedentsStatus() {
        try {
            console.log('=== INICIO loadAntecedentsStatus ===');
            console.log('Cargando estado de antecedentes para todos los estudios...');
            console.log('Estudios disponibles:', this.studies.length);
            
            // Obtener todos los study_ids
            const studyIds = this.studies.map(study => study.id);
            console.log('Study IDs:', studyIds.length);
            
            if (studyIds.length === 0) {
                console.log('No hay estudios para cargar antecedentes');
                return;
            }
            
            // Dividir en lotes de 100 para evitar problemas de tamaño
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
                // Actualizar indicadores visuales
                allResults.forEach(item => {
                    const indicator = document.getElementById(`antecedents-${item.study_id}`);
                    const counter = document.getElementById(`antecedents-counter-${item.study_id}`);
                    
                    if (indicator) {
                        indicator.style.display = 'inline-block';
                        indicator.title = `${item.files_count} archivo(s) adjunto(s)`;
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
                    }
                });
                
                console.log(`Estado de antecedentes cargado: ${allResults.length} estudios con antecedentes`);
            } else {
                console.log('No se encontraron antecedentes');
            }
            
            console.log('=== FIN loadAntecedentsStatus ===');
            
        } catch (error) {
            console.error('Error cargando estado de antecedentes:', error);
        }
    }
    
    /**
     * Carga los antecedentes existentes para el estudio actual
     */
    async loadExistingAntecedents() {
        try {
            if (!this.currentAntecedents || !this.currentAntecedents.id) {
                console.log('No hay estudio actual para cargar antecedentes');
                return;
            }
            
            console.log('Cargando antecedentes existentes para:', this.currentAntecedents.id);
            
            const response = await fetch(`${this.apiBaseUrl}study_antecedents.php?study_id=${this.currentAntecedents.id}`);
            const result = await response.json();
            
            console.log('Respuesta del API:', result);
            
            if (result.success && result.data.antecedents) {
                console.log('Antecedentes encontrados:', result.data.antecedents);
                console.log('Archivos encontrados:', result.data.files);
                const emergencyNotesField = document.getElementById('emergencyNotes');
                if (emergencyNotesField) {
                    emergencyNotesField.value = result.data.antecedents.notes || '';
                }
                this.displayExistingAntecedents(result.data.antecedents, result.data.files);
            } else {
                console.log('No hay antecedentes o respuesta no exitosa');
                const emergencyNotesField = document.getElementById('emergencyNotes');
                if (emergencyNotesField) {
                    emergencyNotesField.value = '';
                }
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
                const creatorSurname = antecedents.created_by_surname || '';
                createdBySpan.textContent = `${creatorName} ${creatorSurname}`.trim();
                
                // Mostrar fecha de creación
                if (antecedents.created_date) {
                    createdDateSpan.textContent = new Date(antecedents.created_date).toLocaleString();
                } else {
                    createdDateSpan.textContent = 'Fecha no disponible';
                }
                
                infoDiv.style.display = 'block';
                console.log('Información de antecedentes mostrada correctamente');
            } else {
                console.log('No hay antecedentes o elementos no encontrados');
                console.log('antecedents:', antecedents);
                console.log('infoDiv:', !!infoDiv);
                console.log('notesDiv:', !!notesDiv);
                console.log('createdBySpan:', !!createdBySpan);
                console.log('createdDateSpan:', !!createdDateSpan);
                if (infoDiv) {
                    infoDiv.style.display = 'none';
                }
            }
            
            // Mostrar archivos usando FileViewer
            const filesList = document.getElementById('existing-files-list');
            const noFilesDiv = document.getElementById('no-existing-files');
            const countBadge = document.getElementById('existing-count');
            
            if (files && files.length > 0) {
                if (filesList && noFilesDiv) {
                    filesList.style.display = 'block';
                    noFilesDiv.style.display = 'none';
                    
                    // Usar FileViewer si está disponible, sino usar implementación legacy
                    if (typeof window.FileViewer !== 'undefined') {
                        console.log('Usando FileViewer para mostrar archivos existentes');
                        
                        // Crear contenedor con grid para FileViewer
                        const fileViewerContainer = document.createElement('div');
                        fileViewerContainer.className = 'row g-3';
                        fileViewerContainer.id = 'file-viewer-container';

                        // Agregar funcionalidad de selección múltiple
                        const selectAllContainer = document.createElement('div');
                        selectAllContainer.className = 'mb-3 d-flex justify-content-between align-items-center';
                        selectAllContainer.innerHTML = `
                            <div>
                                <input type="checkbox" id="selectAllFiles" class="form-check-input me-2">
                                <label for="selectAllFiles" class="form-check-label">Seleccionar todos</label>
                            </div>
                            <button type="button" class="btn btn-danger btn-sm" id="deleteSelectedFiles" disabled>
                                <i class="fas fa-trash me-1"></i>Eliminar seleccionados
                            </button>
                        `;

                        // Limpiar contenido existente
                        filesList.innerHTML = '';
                        filesList.appendChild(selectAllContainer);
                        filesList.appendChild(fileViewerContainer);

                        // Crear cards individuales usando FileViewer
                        files.forEach(file => {
                            const fileData = {
                                fileName: file.file_name,
                                filePath: file.file_path,
                                fileType: file.file_type || 'unknown',
                                mimeType: file.mime_type || 'application/octet-stream',
                                fileSize: file.file_size || 0,
                                uploadDate: file.uploaded_date,
                                fileId: file.id
                            };

                            // Crear columna para la card
                            const colDiv = document.createElement('div');
                            colDiv.className = 'col-md-4 col-sm-6';

                            // Crear la card usando FileViewer
                            const fileCard = window.FileViewer.createFileCard(fileData);
                            
                            // Agregar atributos de datos para identificación
                            fileCard.setAttribute('data-file-id', file.id);
                            fileCard.setAttribute('data-file-path', file.file_path);
                            
                            // Agregar checkbox para selección múltiple
                            const cardContent = fileCard.querySelector('.card-body');
                            if (cardContent) {
                                const checkbox = document.createElement('div');
                                checkbox.className = 'form-check position-absolute';
                                checkbox.style.cssText = 'top: 10px; right: 10px; z-index: 10;';
                                checkbox.innerHTML = `
                                    <input class="form-check-input file-checkbox" type="checkbox" value="${file.id}" id="file-${file.id}" data-file-id="${file.id}">
                                `;
                                fileCard.style.position = 'relative';
                                fileCard.appendChild(checkbox);

                                // Agregar botón de eliminar individual
                const deleteBtn = document.createElement('button');
                deleteBtn.className = 'btn btn-outline-danger btn-sm mt-1';
                deleteBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Eliminar';
                deleteBtn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('🔍 Debug - Botón eliminar clickeado para archivo:', file.id);
                    this.deleteFile(file.id, file.file_path);
                };
                
                const actionsDiv = cardContent.querySelector('.file-actions');
                if (actionsDiv) {
                    actionsDiv.appendChild(deleteBtn);
                } else {
                    // Si no existe .file-actions, crear uno
                    const newActionsDiv = document.createElement('div');
                    newActionsDiv.className = 'file-actions mt-2';
                    newActionsDiv.appendChild(deleteBtn);
                    cardContent.appendChild(newActionsDiv);
                }
                            }

                            colDiv.appendChild(fileCard);
                            fileViewerContainer.appendChild(colDiv);
                        });

                        // Event listeners para selección múltiple
                        this.setupMultipleSelectionEvents();
                        
                    } else {
                        // Implementación legacy
                        console.log('FileViewer no disponible, usando implementación legacy');
                        filesList.innerHTML = files.map(file => `
                            <div class="card mb-2" data-file-id="${file.id}" data-file-path="${file.file_path}">
                                <div class="card-body py-2">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-${this.getFileIcon(file.file_type)} me-2 text-primary"></i>
                                                <div>
                                                    <strong>${file.file_name}</strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        ${this.formatFileSize(file.file_size)} • 
                                                        ${new Date(file.uploaded_date).toLocaleString()}
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <button class="btn btn-outline-primary btn-sm me-1" onclick="derivacionesManager.viewFile('${file.file_path}')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm" onclick="derivacionesManager.deleteFile(${file.id}, '${file.file_path}')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `).join('');
                    }
                }
                
                if (countBadge) {
                    countBadge.textContent = files.length;
                    countBadge.style.display = 'inline-block';
                }
            } else {
                if (filesList && noFilesDiv) {
                    filesList.style.display = 'none';
                    noFilesDiv.style.display = 'block';
                }
                
                if (countBadge) {
                    countBadge.style.display = 'none';
                }
            }
            
        } catch (error) {
            console.error('Error mostrando antecedentes existentes:', error);
        }
    }
    
    /**
     * Oculta los antecedentes existentes
     */
    hideExistingAntecedents() {
        const infoDiv = document.getElementById('existing-antecedents-info');
        const filesList = document.getElementById('existing-files-list');
        const noFilesDiv = document.getElementById('no-existing-files');
        const countBadge = document.getElementById('existing-count');
        
        if (infoDiv) infoDiv.style.display = 'none';
        if (filesList) filesList.style.display = 'none';
        if (noFilesDiv) noFilesDiv.style.display = 'block';
        if (countBadge) countBadge.style.display = 'none';
    }
    
    /**
     * Actualiza los antecedentes existentes
     */
    async refreshExistingAntecedents() {
        await this.loadExistingAntecedents();
        // También actualizar el contador en la lista principal
        await this.loadAntecedentsStatus();
        this.showBootstrapAlert('Actualizado', 'Los antecedentes existentes se han actualizado.', 'success', 1500);
    }
    
    /**
     * Obtiene el ícono apropiado para el tipo de archivo
     */
    getFileIcon(fileType) {
        switch(fileType) {
            case 'image': return 'image';
            case 'camera_capture': return 'camera';
            case 'document': return 'file-alt';
            default: return 'file';
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
    
    /**
     * Visualiza un archivo
     */
    viewFile(filePath) {
        try {
            console.log('Visualizando archivo:', filePath);
            
            // Corregir la ruta del archivo para que sea relativa correctamente
            const correctedPath = this.correctFilePath(filePath);
            console.log('Ruta corregida:', correctedPath);
            
            // Determinar el tipo de archivo por extensión
            const extension = correctedPath.split('.').pop().toLowerCase();
            const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
            const documentExtensions = ['pdf', 'doc', 'docx', 'txt', 'rtf'];
            
            if (imageExtensions.includes(extension)) {
                this.viewImage(correctedPath);
            } else if (documentExtensions.includes(extension)) {
                this.viewDocument(correctedPath);
            } else {
                this.showBootstrapAlert(
                    'Tipo de Archivo', 
                    'Este tipo de archivo no se puede visualizar directamente. Descárgalo para abrirlo.', 
                    'info', 
                    0
                );
            }
            
        } catch (error) {
            console.error('Error visualizando archivo:', error);
            this.showBootstrapAlert(
                'Error', 
                'No se pudo visualizar el archivo. Inténtalo de nuevo.', 
                'error', 
                0
            );
        }
    }
    
    /**
     * Corrige la ruta del archivo para uso en el navegador
     */
    correctFilePath(filePath) {
        try {
            // Si la ruta empieza con ../uploads/, convertir a ruta relativa desde la raíz
            if (filePath.startsWith('../uploads/')) {
                return filePath.replace('../uploads/', 'uploads/');
            }
            
            // Si la ruta empieza con uploads/, mantenerla así
            if (filePath.startsWith('uploads/')) {
                return filePath;
            }
            
            // Si la ruta es absoluta o tiene protocolo, mantenerla
            if (filePath.startsWith('http://') || filePath.startsWith('https://') || filePath.startsWith('/')) {
                return filePath;
            }
            
            // Por defecto, asumir que es relativa desde uploads/
            return `uploads/${filePath}`;
            
        } catch (error) {
            console.error('Error corrigiendo ruta:', error);
            return filePath; // Devolver la ruta original si hay error
        }
    }
    
    /**
     * Visualiza una imagen en un modal
     */
    viewImage(imagePath) {
        try {
            console.log('Mostrando imagen:', imagePath);
            
            // Crear modal para visualizar imagen con z-index alto
            const imageModal = document.createElement('div');
            imageModal.className = 'modal fade';
            imageModal.id = 'imageViewerModal';
            imageModal.setAttribute('data-bs-backdrop', 'static');
            imageModal.setAttribute('data-bs-keyboard', 'true');
            imageModal.style.zIndex = '9999'; // Z-index muy alto para estar sobre el modal de antecedentes
            
            imageModal.innerHTML = `
                <div class="modal-dialog modal-lg modal-dialog-centered" style="z-index: 10000;">
                    <div class="modal-content" style="z-index: 10001;">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-image me-2"></i>Visualizador de Imagen
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-center">
                            <img src="${imagePath}" class="img-fluid" style="max-height: 70vh;" alt="Imagen">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cerrar
                            </button>
                            <button type="button" class="btn btn-primary" onclick="window.open('${imagePath}', '_blank')">
                                <i class="fas fa-external-link-alt me-1"></i>Abrir en Nueva Pestaña
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al DOM y mostrar
            document.body.appendChild(imageModal);
            const modal = new bootstrap.Modal(imageModal);
            modal.show();
            
            // Limpiar cuando se cierre
            imageModal.addEventListener('hidden.bs.modal', () => {
                if (imageModal.parentNode) {
                    imageModal.parentNode.removeChild(imageModal);
                }
            });
            
        } catch (error) {
            console.error('Error mostrando imagen:', error);
            this.showBootstrapAlert(
                'Error', 
                'No se pudo mostrar la imagen. Inténtalo de nuevo.', 
                'error', 
                0
            );
        }
    }
    
    /**
     * Visualiza un documento
     */
    viewDocument(documentPath) {
        try {
            // Corregir la ruta del documento
            const correctedPath = this.correctFilePath(documentPath);
            console.log('Abriendo documento:', correctedPath);
            
            // Para documentos, abrir en nueva pestaña
            window.open(correctedPath, '_blank');
            
        } catch (error) {
            console.error('Error abriendo documento:', error);
            this.showBootstrapAlert(
                'Error', 
                'No se pudo abrir el documento. Inténtalo de nuevo.', 
                'error', 
                0
            );
        }
    }
    
    /**
     * Elimina un archivo
     */
    async deleteFile(fileId, filePath = null) {
        try {
            console.log('🔍 Debug - deleteFile llamado con:', { fileId, filePath });
            console.log('🔍 Debug - this.showConfirmationModal disponible:', typeof this.showConfirmationModal);
            
            if (typeof this.showConfirmationModal !== 'function') {
                console.error('🔍 Debug - showConfirmationModal no es una función');
                return;
            }
            
            // Obtener el nombre del archivo para la confirmación
            let fileName = 'este archivo';
            const fileElement = document.querySelector(`[data-file-id="${fileId}"]`);
            if (fileElement) {
                const fileNameElement = fileElement.querySelector('.file-name, strong');
                if (fileNameElement) {
                    fileName = fileNameElement.textContent.trim();
                }
                if (!filePath) {
                    filePath = fileElement.getAttribute('data-file-path');
                }
            }
            
            console.log('🔍 Debug - Mostrando modal de confirmación para:', fileName);
            
            // Mostrar modal de confirmación Bootstrap
            this.showConfirmationModal(fileName, async () => {
                console.log('🔍 Debug - Confirmación recibida, ejecutando performDeleteFile');
                await this.performDeleteFile(fileId, filePath);
            });
            
        } catch (error) {
            console.error('Error en deleteFile:', error);
            this.showBootstrapAlert(
                'Error', 
                'Error eliminando archivo: ' + error.message, 
                'error', 
                0
            );
        }
    }
    
    /**
     * Realiza la eliminación del archivo (separado para reutilización)
     */
    async performDeleteFile(fileId, filePath) {
        try {
            console.log('Eliminando archivo:', fileId, 'Ruta:', filePath);
            
            // Llamar a la API para eliminar el archivo completamente (archivo + BD)
            const response = await fetch('api/delete_antecedent_file_complete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    file_id: fileId,
                    file_path: filePath
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                let message = result.message;
                if (result.warnings && result.warnings.length > 0) {
                    message += ' (' + result.warnings.join(', ') + ')';
                }
                
                this.showBootstrapAlert(
                    'Archivo Eliminado', 
                    message, 
                    result.warnings && result.warnings.length > 0 ? 'warning' : 'success', 
                    3000
                );
                
                // Actualizar la lista de archivos
                await this.refreshExistingAntecedents();
            } else {
                throw new Error(result.error || 'Error desconocido al eliminar el archivo');
            }
            
        } catch (error) {
            console.error('Error eliminando archivo:', error);
            this.showBootstrapAlert(
                'Error', 
                `No se pudo eliminar el archivo: ${error.message}`, 
                'error', 
                0
            );
        }
    }

    /**
     * Elimina múltiples archivos seleccionados
     */
    async deleteMultipleFiles(fileIds) {
        try {
            console.log('🔍 Debug - deleteMultipleFiles llamado con:', fileIds);
            console.log('🔍 Debug - this.showMultipleFilesConfirmationModal disponible:', typeof this.showMultipleFilesConfirmationModal);
            
            if (typeof this.showMultipleFilesConfirmationModal !== 'function') {
                console.error('🔍 Debug - showMultipleFilesConfirmationModal no es una función');
                return;
            }
            
            if (!fileIds || fileIds.length === 0) {
                console.warn('🔍 Debug - No hay archivos para eliminar');
                this.showBootstrapAlert('Advertencia', 'No hay archivos seleccionados para eliminar.', 'warning', 2000);
                return;
            }

            // Preparar datos de archivos para mostrar en el modal
            const files = [];
            for (const fileId of fileIds) {
                const fileElement = document.querySelector(`[data-file-id="${fileId}"]`);
                if (fileElement) {
                    const filePath = fileElement.getAttribute('data-file-path');
                    const fileNameElement = fileElement.querySelector('.file-name, strong');
                    const fileName = fileNameElement ? fileNameElement.textContent.trim() : `Archivo ${fileId}`;
                    
                    files.push({
                        file_id: fileId,
                        file_path: filePath,
                        name: fileName
                    });
                }
            }
            
            console.log('🔍 Debug - Archivos preparados para modal:', files);
            console.log('🔍 Debug - Mostrando modal de confirmación múltiple');
            
            // Mostrar modal de confirmación Bootstrap para múltiples archivos
            this.showMultipleFilesConfirmationModal(files, async () => {
                console.log('🔍 Debug - Confirmación múltiple recibida, ejecutando performDeleteMultipleFiles');
                await this.performDeleteMultipleFiles(files);
            });
            
        } catch (error) {
            console.error('Error en deleteMultipleFiles:', error);
            this.showBootstrapAlert(
                'Error', 
                'Error eliminando archivos: ' + error.message, 
                'error', 
                0
            );
        }
    }
    
    /**
     * Realiza la eliminación de múltiples archivos (separado para reutilización)
     */
    async performDeleteMultipleFiles(files) {
        try {
            console.log('Eliminando múltiples archivos:', files.map(f => f.file_id));
            
            if (files.length === 0) {
                throw new Error('No se pudieron determinar los datos de los archivos');
            }
            
            // Llamar al API para eliminar múltiples archivos
            const response = await fetch('api/delete_multiple_antecedent_files.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    files: files
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                let message = result.message;
                let alertType = 'success';
                
                // Revisar si hay advertencias en los resultados
                const hasWarnings = result.results.some(r => r.warnings && r.warnings.length > 0);
                const hasErrors = result.error_count > 0;
                
                if (hasErrors) {
                    alertType = 'error';
                } else if (hasWarnings) {
                    alertType = 'warning';
                }
                
                this.showBootstrapAlert(
                    'Archivos Procesados', 
                    message, 
                    alertType, 
                    4000
                );
                
                // Mostrar detalles si hay errores
                if (hasErrors) {
                    console.log('Detalles de eliminación:', result.results);
                }
            } else {
                throw new Error(result.error || 'Error desconocido al eliminar archivos');
            }
            
            // Actualizar la lista de archivos
            await this.refreshExistingAntecedents();
            
        } catch (error) {
            console.error('Error eliminando múltiples archivos:', error);
            this.showBootstrapAlert(
                'Error', 
                `Error eliminando archivos: ${error.message}`, 
                'error', 
                0
            );
        }
    }
    
    /**
     * Normaliza fecha de estudio a YYYYMMDD para comparar mismo día.
     */
    normalizeStudyDateKey(dateStr) {
        if (!dateStr) return '';
        const s = String(dateStr).trim();
        if (/^\d{8}$/.test(s)) return s;
        const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (iso) return `${iso[1]}${iso[2]}${iso[3]}`;
        const dmy = s.match(/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/);
        if (dmy) return `${dmy[3]}${dmy[2]}${dmy[1]}`;
        try {
            const d = new Date(s);
            if (!isNaN(d.getTime())) {
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${y}${m}${day}`;
            }
        } catch (e) { /* ignore */ }
        return '';
    }

    /**
     * Otros estudios del mismo PatientID y misma fecha (día calendario).
     */
    findSiblingStudiesSameDay(sourceStudy) {
        if (!sourceStudy) return [];
        const patientId = String(sourceStudy.patient_id || '').trim().toLowerCase();
        const dateKey = this.normalizeStudyDateKey(sourceStudy.date || sourceStudy.study_date);
        const sourceId = String(sourceStudy.id || '');
        if (!patientId || !dateKey || !sourceId) return [];

        const list = Array.isArray(this.studies) ? this.studies : [];
        return list.filter((s) => {
            if (!s || String(s.id) === sourceId) return false;
            const pid = String(s.patient_id || '').trim().toLowerCase();
            if (!pid || pid !== patientId) return false;
            const dk = this.normalizeStudyDateKey(s.date || s.study_date);
            return dk && dk === dateKey;
        });
    }

    /**
     * Botón de copia: visible (desactivado) si hay hermanos el mismo día;
     * activo solo tras Guardar en esta sesión. Así el usuario ve que existen otros estudios.
     */
    updateCopyAntecedentsButtonVisibility(study) {
        const btn = document.getElementById('btnCopyAntecedentsSameDay');
        const hint = document.getElementById('copyAntecedentsSameDayHint');
        if (!btn) return;

        const siblings = this.findSiblingStudiesSameDay(study || this.selectedStudy);
        const n = siblings.length;

        if (n <= 0) {
            btn.style.display = 'none';
            btn.disabled = true;
            btn.title = 'No hay otros estudios del mismo paciente en esta fecha';
            if (hint) {
                hint.style.display = 'none';
                hint.textContent = '';
            }
            return;
        }

        // Hay hermanos: siempre mostrar el botón para informar al usuario
        btn.style.display = '';
        const plural = n === 1 ? 'estudio' : 'estudios';
        const hintText = `Este paciente tiene ${n} ${plural} más en esta fecha.`;

        if (this.antecedentsSavedForCopy === true) {
            btn.disabled = false;
            btn.classList.remove('disabled');
            btn.title = `${hintText} Pulsá para copiar lo ya guardado.`;
            if (hint) {
                hint.style.display = '';
                hint.className = 'text-info small ms-1 me-2';
                hint.innerHTML = `<i class="fas fa-info-circle me-1"></i>${hintText} Ya podés copiar.`;
            }
        } else {
            btn.disabled = true;
            btn.classList.add('disabled');
            btn.title = `${hintText} Primero guardá los antecedentes; después se activará este botón.`;
            if (hint) {
                hint.style.display = '';
                hint.className = 'text-warning small ms-1 me-2';
                hint.innerHTML = `<i class="fas fa-exclamation-circle me-1"></i>${hintText} Guardá para activar la copia.`;
            }
        }
    }

    /**
     * Tras guardar: si hay hermanos el mismo día, sugerir copiar.
     */
    async maybeSuggestCopyAntecedents(sourceStudyId) {
        const study = (this.studies || []).find((s) => String(s.id) === String(sourceStudyId))
            || this.selectedStudy;
        if (!study || String(study.id) !== String(sourceStudyId)) {
            return;
        }
        const siblings = this.findSiblingStudiesSameDay(study);
        if (siblings.length === 0) {
            return;
        }

        const confirmed = window.confirm(
            `Este paciente tiene ${siblings.length} estudio(s) más en la misma fecha.\n\n` +
            '¿Desea copiar estos antecedentes a esos estudios?\n\n' +
            'Aceptar: abrir selector de estudios\nCancelar: dejar solo en este estudio'
        );
        if (confirmed) {
            this.openCopyAntecedentsModal(sourceStudyId, siblings);
        }
    }

    /**
     * Modal para elegir a qué estudios del mismo día copiar antecedentes.
     */
    async openCopyAntecedentsModal(sourceStudyId, siblingsPreloaded = null) {
        try {
            if (!this.antecedentsSavedForCopy) {
                this.showBootstrapAlert(
                    'Guardá primero',
                    'Primero pulsá «Guardar Antecedentes». Después podrás copiar a otros estudios del mismo día.',
                    'warning',
                    3500
                );
                return;
            }
            const sourceStudy = (this.studies || []).find((s) => String(s.id) === String(sourceStudyId))
                || this.selectedStudy;
            if (!sourceStudy) {
                this.showBootstrapAlert('Error', 'No se encontró el estudio origen.', 'error', 3000);
                return;
            }

            const siblings = siblingsPreloaded || this.findSiblingStudiesSameDay(sourceStudy);
            if (!siblings.length) {
                this.showBootstrapAlert(
                    'Sin destinos',
                    'No hay otros estudios del mismo paciente en esta fecha (en la lista actual).',
                    'info',
                    3000
                );
                return;
            }

            // Estado de antecedentes de destinos
            let statusMap = {};
            try {
                const statusResp = await fetch(`${this.apiBaseUrl}study_antecedents.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ study_ids: siblings.map((s) => s.id) })
                });
                const statusJson = await statusResp.json();
                if (statusJson.success && Array.isArray(statusJson.data)) {
                    statusJson.data.forEach((row) => {
                        statusMap[row.study_id] = row;
                    });
                }
            } catch (e) {
                console.warn('No se pudo cargar estado de antecedentes de destinos:', e);
            }

            const existing = document.getElementById('copyAntecedentsModal');
            if (existing) existing.remove();

            const rowsHtml = siblings.map((s, idx) => {
                const st = statusMap[s.id] || {};
                const hasNotes = Number(st.has_notes || 0) > 0;
                const filesCount = Number(st.files_count || 0);
                const hasAny = hasNotes || filesCount > 0;
                const badge = hasAny
                    ? `<span class="badge bg-warning text-dark">Ya tiene antecedentes${filesCount ? ` (${filesCount} arch.)` : ''}</span>`
                    : `<span class="badge bg-secondary">Sin antecedentes</span>`;
                const checked = hasAny ? '' : 'checked';
                const safeId = this.escapeHtml(String(s.id));
                return `
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input copy-ant-target" value="${safeId}" ${checked}>
                        </td>
                        <td>${this.escapeHtml(s.modality || 'N/A')}</td>
                        <td>${this.formatTime(s.time || s.study_time)}</td>
                        <td>${this.escapeHtml(s.study_description || '—')}</td>
                        <td>${badge}</td>
                    </tr>`;
            }).join('');

            const modal = document.createElement('div');
            modal.id = 'copyAntecedentsModal';
            modal.className = 'modal fade show';
            modal.style.cssText = 'display:block; position:fixed; inset:0; z-index:1060; background:rgba(0,0,0,.45);';
            modal.innerHTML = `
                <div class="modal-dialog modal-lg modal-dialog-scrollable" style="margin:4rem auto;">
                    <div class="modal-content">
                        <div class="modal-header bg-info text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-copy me-2"></i>Copiar antecedentes — mismo paciente, misma fecha
                            </h5>
                            <button type="button" class="btn-close btn-close-white" onclick="document.getElementById('copyAntecedentsModal').remove()"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">
                                Origen: <strong>${this.escapeHtml(sourceStudy.patient_name || '')}</strong>
                                (ID ${this.escapeHtml(String(sourceStudy.patient_id || ''))}) —
                                ${this.formatDate(sourceStudy.date || sourceStudy.study_date)}
                            </p>
                            <p class="text-muted small mb-3">
                                Solo se listan otros estudios del mismo PatientID en esta fecha. Los archivos se duplican; las notas se pueden sobrescribir o agregar al final.
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;">
                                                <input type="checkbox" class="form-check-input" id="copyAntSelectAll" title="Seleccionar todos">
                                            </th>
                                            <th>Modalidad</th>
                                            <th>Hora</th>
                                            <th>Descripción</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>${rowsHtml}</tbody>
                                </table>
                            </div>
                            <div class="mt-2">
                                <label class="form-label fw-semibold">Notas en destino</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="copyAntNotesMode" id="copyAntOverwrite" value="overwrite" checked>
                                    <label class="form-check-label" for="copyAntOverwrite">Sobrescribir notas del destino</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="copyAntNotesMode" id="copyAntAppend" value="append">
                                    <label class="form-check-label" for="copyAntAppend">Agregar al final (si el destino ya tiene notas)</label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('copyAntecedentsModal').remove()">Cancelar</button>
                            <button type="button" class="btn btn-info" id="confirmCopyAntecedentsBtn">
                                <i class="fas fa-copy me-1"></i>Copiar a seleccionados
                            </button>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(modal);

            const selectAll = document.getElementById('copyAntSelectAll');
            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    document.querySelectorAll('.copy-ant-target').forEach((cb) => {
                        cb.checked = selectAll.checked;
                    });
                });
            }

            const confirmBtn = document.getElementById('confirmCopyAntecedentsBtn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', async () => {
                    await this.executeCopyAntecedents(sourceStudyId);
                });
            }
        } catch (error) {
            console.error('Error abriendo modal de copia de antecedentes:', error);
            this.showBootstrapAlert('Error', 'No se pudo abrir el selector de copia.', 'error', 3000);
        }
    }

    async executeCopyAntecedents(sourceStudyId) {
        const checked = Array.from(document.querySelectorAll('.copy-ant-target:checked'))
            .map((cb) => cb.value)
            .filter(Boolean);
        if (!checked.length) {
            this.showBootstrapAlert('Atención', 'Seleccione al menos un estudio destino.', 'warning', 2500);
            return;
        }

        const modeEl = document.querySelector('input[name="copyAntNotesMode"]:checked');
        const notesMode = modeEl ? modeEl.value : 'overwrite';
        const currentUser = this.getCurrentUser();
        if (!currentUser || !currentUser.id) {
            this.showBootstrapAlert('Error', 'No hay usuario de sesión.', 'error', 3000);
            return;
        }

        const confirmBtn = document.getElementById('confirmCopyAntecedentsBtn');
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Copiando...';
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}copy_antecedents_to_studies.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    source_study_id: sourceStudyId,
                    target_study_ids: checked,
                    created_by: currentUser.id,
                    notes_mode: notesMode,
                    copy_files: true
                })
            });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.error || 'Error al copiar');
            }

            const modal = document.getElementById('copyAntecedentsModal');
            if (modal) modal.remove();

            this.showBootstrapAlert(
                'Copia completada',
                result.message || `Copiado a ${result.copied || 0} estudio(s).`,
                'success',
                3500
            );
            await this.loadAntecedentsStatus();
        } catch (error) {
            console.error('Error copiando antecedentes:', error);
            this.showBootstrapAlert('Error', error.message || 'No se pudieron copiar los antecedentes.', 'error', 0);
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copiar a seleccionados';
            }
        }
    }

    /**
     * Guarda las notas de antecedentes en la base de datos
     */
    async saveAntecedentsNotes(studyId, notes) {
        try {
            console.log('Guardando notas de antecedentes para estudio:', studyId);
            
            const currentUser = this.getCurrentUser();
            if (!currentUser || !currentUser.id) {
                throw new Error('No hay usuario actual disponible');
            }
            
            const response = await fetch(`${this.apiBaseUrl}study_antecedents.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    study_id: studyId,
                    notes: notes,
                    created_by: currentUser.id
                })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Error guardando notas');
            }
            
            console.log('Notas de antecedentes guardadas exitosamente');
            return result;
            
        } catch (error) {
            console.error('Error guardando notas de antecedentes:', error);
            throw error;
        }
    }
    
    /**
     * Maneja la subida de imágenes desde el modal de emergencia
     */
    async handleEmergencyImageUpload(files, studyId) {
        try {
            console.log('Procesando subida de imágenes:', files.length);
            
            const currentUser = this.getCurrentUser();
            if (!currentUser || !currentUser.id) {
                throw new Error('No hay usuario actual disponible');
            }
            
            const uploadedImagesContainer = document.getElementById('emergencyUploadedImages');
            if (!uploadedImagesContainer) {
                console.error('Contenedor de imágenes no encontrado');
                return;
            }
            
            // Limpiar mensaje de "no hay imágenes"
            const noImagesMsg = uploadedImagesContainer.querySelector('.text-muted');
            if (noImagesMsg) {
                noImagesMsg.style.display = 'none';
            }
            
            // Procesar cada archivo
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                // Validar que sea una imagen
                if (!file.type.startsWith('image/')) {
                    console.warn('Archivo no es una imagen:', file.name);
                    continue;
                }
                
                // Crear FormData para la subida
                const formData = new FormData();
                formData.append('file', file);
                formData.append('study_id', studyId);
                formData.append('file_type', 'image_upload');
                formData.append('created_by', currentUser.id);
                
                try {
                    // Subir archivo
                    const response = await fetch(`${this.apiBaseUrl}upload_antecedents_file.php`, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        console.log('Imagen subida exitosamente:', file.name);
                        
                        // Mostrar imagen en el contenedor
                        this.displayUploadedImage(file, result.data.file_path, uploadedImagesContainer);
                        
                    } else {
                        console.error('Error subiendo imagen:', result.message);
                        this.showBootstrapAlert(
                            'Error de Subida', 
                            `No se pudo subir ${file.name}: ${result.message}`, 
                            'error', 
                            3000
                        );
                    }
                    
                } catch (uploadError) {
                    console.error('Error subiendo imagen:', uploadError);
                    this.showBootstrapAlert(
                        'Error de Subida', 
                        `No se pudo subir ${file.name}. Inténtalo de nuevo.`, 
                        'error', 
                        3000
                    );
                }
            }
            
            // Mostrar mensaje de éxito si se subieron imágenes
            if (files.length > 0) {
                this.showBootstrapAlert(
                    'Imágenes Subidas', 
                    `Se procesaron ${files.length} imagen(es) correctamente.`, 
                    'success', 
                    2000
                );
                // Actualizar el contador en la lista principal
                setTimeout(async () => {
                    await this.loadAntecedentsStatus();
                }, 1000);
            }
            
        } catch (error) {
            console.error('Error procesando subida de imágenes:', error);
            this.showBootstrapAlert(
                'Error', 
                'No se pudieron procesar las imágenes. Inténtalo de nuevo.', 
                'error', 
                0
            );
        }
    }
    
    /**
     * Muestra una imagen subida en el contenedor
     */
    displayUploadedImage(file, filePath, container) {
        try {
            // Crear elemento de imagen
            const imageElement = document.createElement('div');
            imageElement.className = 'd-inline-block me-2 mb-2';
            imageElement.style.cssText = 'position: relative;';
            
            imageElement.innerHTML = `
                <div class="card" style="width: 120px;">
                    <img src="${this.correctFilePath(filePath)}" class="card-img-top" style="height: 80px; object-fit: cover;" alt="${file.name}">
                    <div class="card-body p-2">
                        <h6 class="card-title" style="font-size: 0.75rem; margin: 0;">${file.name.length > 15 ? file.name.substring(0, 15) + '...' : file.name}</h6>
                        <div class="d-flex justify-content-between mt-1">
                            <button class="btn btn-outline-primary btn-sm" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;" onclick="derivacionesManager.viewFile('${filePath}')">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-outline-danger btn-sm" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;" onclick="this.parentElement.parentElement.parentElement.remove()">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al contenedor
            container.appendChild(imageElement);
            
        } catch (error) {
            console.error('Error mostrando imagen subida:', error);
        }
    }
    
    /**
     * Maneja la subida de archivos generales desde el modal de emergencia
     */
    async handleEmergencyFileUpload(files, studyId) {
        try {
            console.log('Procesando subida de archivos:', files.length);
            
            const currentUser = this.getCurrentUser();
            if (!currentUser || !currentUser.id) {
                throw new Error('No hay usuario actual disponible');
            }
            
            const uploadedFilesContainer = document.getElementById('emergencyUploadedFiles');
            if (!uploadedFilesContainer) {
                console.error('Contenedor de archivos no encontrado');
                return;
            }
            
            // Limpiar mensaje de "no hay archivos"
            const noFilesMsg = uploadedFilesContainer.querySelector('.text-muted');
            if (noFilesMsg) {
                noFilesMsg.style.display = 'none';
            }
            
            // Procesar cada archivo
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                // Crear FormData para la subida
                const formData = new FormData();
                formData.append('file', file);
                formData.append('study_id', studyId);
                formData.append('file_type', 'file_upload');
                formData.append('created_by', currentUser.id);
                
                try {
                    // Subir archivo
                    const response = await fetch(`${this.apiBaseUrl}upload_antecedents_file.php`, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        console.log('Archivo subido exitosamente:', file.name);
                        
                        // Mostrar archivo en el contenedor
                        this.displayUploadedFile(file, result.data.file_path, uploadedFilesContainer);
                        
                    } else {
                        console.error('Error subiendo archivo:', result.message);
                        this.showBootstrapAlert(
                            'Error de Subida', 
                            `No se pudo subir ${file.name}: ${result.message}`, 
                            'error', 
                            3000
                        );
                    }
                    
                } catch (uploadError) {
                    console.error('Error subiendo archivo:', uploadError);
                    this.showBootstrapAlert(
                        'Error de Subida', 
                        `No se pudo subir ${file.name}. Inténtalo de nuevo.`, 
                        'error', 
                        3000
                    );
                }
            }
            
            // Mostrar mensaje de éxito si se subieron archivos
            if (files.length > 0) {
                this.showBootstrapAlert(
                    'Archivos Subidos', 
                    `Se procesaron ${files.length} archivo(s) correctamente.`, 
                    'success', 
                    2000
                );
                // Actualizar el contador en la lista principal
                setTimeout(async () => {
                    await this.loadAntecedentsStatus();
                }, 1000);
            }
            
        } catch (error) {
            console.error('Error procesando subida de archivos:', error);
            this.showBootstrapAlert(
                'Error', 
                'No se pudieron procesar los archivos. Inténtalo de nuevo.', 
                'error', 
                0
            );
        }
    }
    
    /**
     * Muestra un archivo subido en el contenedor
     */
    displayUploadedFile(file, filePath, container) {
        try {
            // Crear elemento de archivo
            const fileElement = document.createElement('div');
            fileElement.className = 'd-inline-block me-2 mb-2';
            fileElement.style.cssText = 'position: relative;';
            
            const fileIcon = this.getFileIcon(file.type);
            const fileSize = this.formatFileSize(file.size);
            
            fileElement.innerHTML = `
                <div class="card" style="width: 150px;">
                    <div class="card-body p-2 text-center">
                        <i class="fas fa-${fileIcon} fa-2x text-primary mb-2"></i>
                        <h6 class="card-title" style="font-size: 0.75rem; margin: 0;">${file.name.length > 20 ? file.name.substring(0, 20) + '...' : file.name}</h6>
                        <small class="text-muted">${fileSize}</small>
                        <div class="d-flex justify-content-between mt-2">
                            <button class="btn btn-outline-primary btn-sm" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;" onclick="derivacionesManager.viewFile('${filePath}')">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-outline-danger btn-sm" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;" onclick="this.parentElement.parentElement.parentElement.remove()">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al contenedor
            container.appendChild(fileElement);
            
        } catch (error) {
            console.error('Error mostrando archivo subido:', error);
        }
    }
    
    /**
     * Obtiene la URL base del servidor (puede ser localhost o URL externa)
     */
    getServerBaseUrl() {
        // Si estamos en localhost, usar la URL externa configurada
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.log('Detectado localhost, usando URL externa:', this.externalServerUrl);
            return this.externalServerUrl;
        }
        
        // Si no estamos en localhost, usar la URL actual
        const currentUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
        console.log('No es localhost, usando URL actual:', currentUrl);
        return currentUrl;
    }
    
    /**
     * Genera un código QR para captura móvil
     */
    async generateMobileQR(studyId) {
        try {
            console.log('=== INICIO generateMobileQR ===');
            console.log('studyId recibido:', studyId);
            console.log('this.apiBaseUrl:', this.apiBaseUrl);
            
            // Obtener datos del estudio actual
            const study = this.currentAntecedents;
            
            // Crear sesión móvil única con datos del estudio incluidos
            // Convertir fecha a formato MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
            const expiresDate = new Date(Date.now() + 24 * 60 * 60 * 1000); // 24 horas
            const expiresAtMySQL = expiresDate.toISOString().slice(0, 19).replace('T', ' '); // Formato: 2025-12-14 01:01:18
            
            const sessionData = {
                study_id: studyId,
                session_id: this.generateSessionId(),
                created_by: this.getCurrentUser()?.id || 1,
                expires_at: expiresAtMySQL, // Formato MySQL DATETIME
                created_at: new Date().toISOString(),
                // Datos del estudio para almacenar en BD
                patient_name: study.patient_name || 'N/A',
                patient_id: study.patient_id || 'N/A',
                modality: study.modality || 'N/A',
                study_date: study.date || 'N/A',
                study_description: study.study_description || ''
            };
            
            console.log('sessionData creado:', sessionData);
            
            // Guardar sesión en el servidor
            const sessionUrl = `${this.apiBaseUrl}mobile_session.php`;
            console.log('URL de sesión:', sessionUrl);
            
            const sessionResponse = await fetch(sessionUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sessionData)
            });
            
            console.log('Respuesta del servidor:', sessionResponse.status, sessionResponse.statusText);
            
            const sessionResult = await sessionResponse.json();
            console.log('sessionResult:', sessionResult);
            
            if (!sessionResult.success) {
                throw new Error(sessionResult.message || 'Error creando sesión móvil');
            }
            
            // Generar URL móvil corta (solo con token de sesión)
            // Los datos del estudio se obtienen desde la base de datos usando el token
            const mobileUrl = `${this.getServerBaseUrl()}/mobile-capture.php?session=${sessionData.session_id}`;
            console.log('URL base del servidor:', this.getServerBaseUrl());
            console.log('mobileUrl generada (corta):', mobileUrl);
            console.log('Longitud de URL:', mobileUrl.length, 'caracteres');
            
            // Generar QR usando una librería simple
            await this.renderQRCode(mobileUrl, sessionData.session_id);
            
            // Actualizar estado de sesión
            this.updateMobileSessionStatus(sessionData.session_id, 'active');
            
            console.log('QR móvil generado exitosamente');
            
            // Guardar ID de sesión y iniciar renovación automática
            this.mobileSessionId = sessionData.session_id;
            this.startSessionRenewal();
            
            console.log('=== FIN generateMobileQR ===');
            
        } catch (error) {
            console.error('=== ERROR en generateMobileQR ===');
            console.error('Error completo:', error);
            console.error('Error message:', error.message);
            console.error('Error stack:', error.stack);
            console.error('=== FIN ERROR ===');
            
            this.showBootstrapAlert(
                'Error', 
                'No se pudo generar el código QR. Inténtalo de nuevo.', 
                'error', 
                3000
            );
        }
    }
    
    /**
     * Refresca el código QR existente
     */
    async refreshMobileQR(studyId) {
        try {
            console.log('Refrescando QR móvil para estudio:', studyId);
            
            // Invalidar sesión anterior
            const currentSession = document.getElementById('qr-container').dataset.sessionId;
            if (currentSession) {
                await fetch(`${this.apiBaseUrl}mobile_session.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: currentSession })
                });
            }
            
            // Generar nuevo QR
            await this.generateMobileQR(studyId);
            
        } catch (error) {
            console.error('Error refrescando QR móvil:', error);
        }
    }
    
    /**
     * Genera un ID de sesión único
     */
    generateSessionId() {
        return 'mobile_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
    
    /**
     * Renderiza el código QR en el contenedor
     */
    async renderQRCode(url, sessionId) {
        try {
            console.log('=== INICIO renderQRCode ===');
            console.log('url recibida:', url);
            console.log('sessionId recibido:', sessionId);
            
            const container = document.getElementById('qr-container');
            console.log('container encontrado:', !!container);
            if (!container) {
                console.error('No se encontró el contenedor qr-container');
                return;
            }
            
            // Limpiar contenedor
            container.innerHTML = '';
            console.log('Contenedor limpiado');
            
            // Verificar si la librería QR está disponible
            console.log('Verificando librería QR...');
            console.log('typeof QRCode:', typeof QRCode);
            console.log('QRCode disponible:', typeof QRCode !== 'undefined');
            
            // Usar la librería QR local (API diferente)
            if (typeof QRCode !== 'undefined') {
                console.log('Generando QR con librería local...');
                
                try {
                    // Crear un div contenedor para el QR (la librería local usa div, no canvas)
                    const qrDiv = document.createElement('div');
                    qrDiv.id = 'qr-div';
                    qrDiv.style.width = '200px';
                    qrDiv.style.height = '200px';
                    qrDiv.style.border = '1px solid #ddd';
                    qrDiv.style.borderRadius = '8px';
                    qrDiv.style.margin = '0 auto';
                    qrDiv.style.display = 'flex';
                    qrDiv.style.alignItems = 'center';
                    qrDiv.style.justifyContent = 'center';
                    qrDiv.style.backgroundColor = '#ffffff';
                    
                    container.appendChild(qrDiv);
                    console.log('Contenedor QR creado');
                    
                    // Usar la API de la librería local: new QRCode(element, options)
                    const qr = new QRCode(qrDiv, {
                        text: url,
                        width: 180,
                        height: 180,
                        colorDark: '#000000',
                        colorLight: '#FFFFFF',
                        correctLevel: QRCode.CorrectLevel.H
                    });
                    
                    console.log('QR generado exitosamente con librería local');
                    container.dataset.sessionId = sessionId;
                    
                    // Agregar información de sesión y botón de copia
                    const sessionInfo = document.createElement('div');
                    sessionInfo.className = 'mt-3 text-center';
                    sessionInfo.innerHTML = `
                        <div class="mb-2">
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>
                                Sesión: ${sessionId.substring(0, 8)}...
                                <br>
                                <i class="fas fa-expire me-1"></i>
                                Sesión activa mientras el modal esté abierto
                            </small>
                        </div>
                        
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control form-control-sm" id="qr-url-input" 
                                   value="${url}" readonly style="font-size: 11px;">
                            <button class="btn btn-outline-secondary btn-sm" type="button" 
                                    onclick="copyQRUrl()" id="copy-qr-btn" title="Copiar URL">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        
                        <div class="d-grid gap-1">
                            <button class="btn btn-primary btn-sm" onclick="openQRUrl()" title="Abrir URL en nueva pestaña">
                                <i class="fas fa-external-link-alt me-1"></i>
                                Abrir en Nueva Pestaña
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="shareQRUrl()" title="Compartir por WhatsApp">
                                <i class="fas fa-share me-1"></i>
                                Compartir por WhatsApp
                            </button>
                        </div>
                    `;
                    container.appendChild(sessionInfo);
                    console.log('Información de sesión y botones de acción agregados');
                    
                    // Agregar funciones globales para los botones
                    this.setupQRUrlFunctions(url);
                    
                } catch (qrError) {
                    console.error('Error generando QR con librería local:', qrError);
                    this.showQRFallback(url, sessionId);
                }
                
            } else {
                console.warn('Librería QR no disponible, usando fallback');
                // Fallback si no hay librería QR
                this.showQRFallback(url, sessionId);
            }
            
            console.log('=== FIN renderQRCode ===');
            
        } catch (error) {
            console.error('=== ERROR en renderQRCode ===');
            console.error('Error completo:', error);
            console.error('Error message:', error.message);
            console.error('Error stack:', error.stack);
            console.error('=== FIN ERROR ===');
            this.showQRFallback(url, sessionId);
        }
    }
    
    /**
     * Muestra fallback cuando no se puede generar QR
     */
    showQRFallback(url, sessionId) {
        const container = document.getElementById('qr-container');
        if (!container) return;
        
        container.innerHTML = `
            <div class="text-center">
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Librería QR Local No Disponible</strong><br>
                    <small>No se pudo cargar la librería QR desde: js/qrcode/qrcode.min.js</small>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="fas fa-link me-2"></i>
                            URL para Acceso Móvil
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" id="mobile-url-input" value="${url}" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyMobileURL()">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary btn-sm" onclick="openMobileURL()">
                                <i class="fas fa-external-link-alt me-1"></i>
                                Abrir en Nueva Pestaña
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="sendMobileURL()">
                                <i class="fas fa-share me-1"></i>
                                Enviar por WhatsApp/Email
                            </button>
                        </div>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Instrucciones:</strong><br>
                                1. Copia la URL de arriba<br>
                                2. Envía la URL a tu dispositivo móvil<br>
                                3. Abre la URL en el navegador móvil<br>
                                4. Captura las imágenes necesarias
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i>
                        Sesión: ${sessionId.substring(0, 8)}...<br>
                        <i class="fas fa-expire me-1"></i>
                        Expira en 10 minutos
                    </small>
                </div>
            </div>
        `;
        container.dataset.sessionId = sessionId;
        
        // Agregar funciones globales para los botones
        window.copyMobileURL = function() {
            const input = document.getElementById('mobile-url-input');
            input.select();
            input.setSelectionRange(0, 99999); // Para móviles
            document.execCommand('copy');
            
            // Mostrar confirmación
            const button = event.target.closest('button');
            const originalHTML = button.innerHTML;
            button.innerHTML = '<i class="fas fa-check"></i> Copiado!';
            button.classList.remove('btn-outline-secondary');
            button.classList.add('btn-success');
            
            setTimeout(() => {
                button.innerHTML = originalHTML;
                button.classList.remove('btn-success');
                button.classList.add('btn-outline-secondary');
            }, 2000);
        };
        
        window.openMobileURL = function() {
            window.open(url, '_blank');
        };
        
        window.sendMobileURL = function() {
            const encodedURL = encodeURIComponent(url);
            const message = encodeURIComponent(`Captura móvil - Portal de Estudios Médicos\n\nURL: ${url}\n\nEsta sesión expira en 10 minutos.`);
            
            // Intentar WhatsApp primero
            const whatsappURL = `https://wa.me/?text=${message}`;
            window.open(whatsappURL, '_blank');
        };
    }
    
    /**
     * Actualiza el estado de la sesión móvil
     */
    updateMobileSessionStatus(sessionId, status) {
        const statusElement = document.getElementById('mobile-session-status');
        if (!statusElement) return;
        
        const statusText = {
            'active': 'Sesión móvil activa',
            'connected': 'Dispositivo conectado',
            'expired': 'Sesión expirada',
            'inactive': 'No hay sesión móvil activa'
        };
        
        const statusColor = {
            'active': 'text-success',
            'connected': 'text-primary',
            'expired': 'text-warning',
            'inactive': 'text-muted'
        };
        
        statusElement.innerHTML = `
            <div class="${statusColor[status] || 'text-muted'}">
                <i class="fas fa-${status === 'active' ? 'check-circle' : status === 'connected' ? 'mobile-alt' : 'clock'} me-1"></i>
                ${statusText[status] || 'Estado desconocido'}
                ${sessionId ? `<br><small>Sesión: ${sessionId.substring(0, 8)}...</small>` : ''}
            </div>
        `;
    }
    
    /**
     * Configura las funciones globales para manejar la URL del QR
     */
    setupQRUrlFunctions(url) {
        // Función para copiar URL al portapapeles
        window.copyQRUrl = () => {
            const input = document.getElementById('qr-url-input');
            const button = document.getElementById('copy-qr-btn');
            
            if (input && button) {
                try {
                    // Seleccionar y copiar el texto
                    input.select();
                    input.setSelectionRange(0, 99999); // Para móviles
                    
                    // Intentar usar la API moderna del portapapeles
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(url).then(() => {
                            this.showCopySuccess(button);
                        }).catch(() => {
                            // Fallback para navegadores que no soportan clipboard API
                            document.execCommand('copy');
                            this.showCopySuccess(button);
                        });
                    } else {
                        // Fallback para navegadores más antiguos
                        document.execCommand('copy');
                        this.showCopySuccess(button);
                    }
                    
                    console.log('URL del QR copiada al portapapeles:', url);
                    
                } catch (error) {
                    console.error('Error copiando URL:', error);
                    this.showCopyError(button);
                }
            }
        };
        
        // Función para abrir URL en nueva pestaña
        window.openQRUrl = () => {
            try {
                window.open(url, '_blank');
                console.log('URL del QR abierta en nueva pestaña:', url);
            } catch (error) {
                console.error('Error abriendo URL:', error);
            }
        };
        
        // Función para compartir por WhatsApp
        window.shareQRUrl = () => {
            try {
                const message = encodeURIComponent(
                    `📱 Captura Móvil - Portal de Estudios Médicos\n\n` +
                    `🔗 URL: ${url}\n\n` +
                    `⏰ Esta sesión permanece activa mientras el modal esté abierto.\n` +
                    `📸 Abre la URL en tu móvil para capturar imágenes.`
                );
                
                const whatsappURL = `https://wa.me/?text=${message}`;
                window.open(whatsappURL, '_blank');
                
                console.log('URL del QR compartida por WhatsApp:', url);
            } catch (error) {
                console.error('Error compartiendo por WhatsApp:', error);
            }
        };
        
        console.log('Funciones de URL del QR configuradas');
    }
    
    /**
     * Muestra confirmación visual de copia exitosa
     */
    showCopySuccess(button) {
        const originalHTML = button.innerHTML;
        const originalClasses = button.className;
        
        button.innerHTML = '<i class="fas fa-check"></i>';
        button.className = button.className.replace('btn-outline-secondary', 'btn-success');
        button.title = '¡Copiado!';
        
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.className = originalClasses;
            button.title = 'Copiar URL';
        }, 2000);
    }
    
    /**
     * Muestra error visual si falla la copia
     */
    showCopyError(button) {
        const originalHTML = button.innerHTML;
        const originalClasses = button.className;
        
        button.innerHTML = '<i class="fas fa-times"></i>';
        button.className = button.className.replace('btn-outline-secondary', 'btn-danger');
        button.title = 'Error al copiar';
        
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.className = originalClasses;
            button.title = 'Copiar URL';
        }, 2000);
    }
    
    /**
     * Inicia el timer de renovación automática de sesión móvil
     */
    startSessionRenewal() {
        if (this.sessionRenewalTimer) {
            clearInterval(this.sessionRenewalTimer);
        }
        
        // Renovar sesión cada 5 minutos
        this.sessionRenewalTimer = setInterval(() => {
            this.renewMobileSession();
        }, 5 * 60 * 1000); // 5 minutos
        
        console.log('Timer de renovación de sesión iniciado (cada 5 minutos)');
    }
    
    /**
     * Renueva la sesión móvil activa
     */
    async renewMobileSession() {
        if (!this.mobileSessionId) {
            console.log('No hay sesión móvil activa para renovar');
            return;
        }
        
        try {
            console.log('Renovando sesión móvil:', this.mobileSessionId);
            
            const response = await fetch(`${this.apiBaseUrl}mobile_session.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    session_id: this.mobileSessionId,
                    action: 'renew'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Sesión móvil renovada exitosamente');
            } else {
                console.warn('⚠️ Error renovando sesión móvil:', result.message);
            }
            
        } catch (error) {
            console.error('❌ Error renovando sesión móvil:', error);
        }
    }
    
    /**
     * Detiene el timer de renovación de sesión
     */
    stopSessionRenewal() {
        if (this.sessionRenewalTimer) {
            clearInterval(this.sessionRenewalTimer);
            this.sessionRenewalTimer = null;
            console.log('Timer de renovación de sesión detenido');
        }
    }
    
    /**
     * Limpia la sesión móvil al cerrar el modal
     */
    async cleanupMobileSession() {
        if (!this.mobileSessionId) {
            console.log('⚠️ No hay sesión móvil activa para limpiar');
            return;
        }
        
        try {
            console.log('🧹 Limpiando sesión móvil:', this.mobileSessionId);
            
            // 1. Detener polling de imágenes PRIMERO
            this.stopImagePolling();
            console.log('✅ Polling de imágenes detenido');
            
            // 2. Detener timer de renovación
            this.stopSessionRenewal();
            console.log('✅ Renovación de sesión detenida');
            
            // 3. Invalidar sesión en el servidor
            const response = await fetch(`${this.apiBaseUrl}mobile_session.php`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: this.mobileSessionId })
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Sesión móvil invalidada en servidor');
            } else {
                console.warn('⚠️ Error invalidando sesión móvil:', result.message);
            }
            
        } catch (error) {
            console.error('❌ Error limpiando sesión móvil:', error);
        } finally {
            // 4. Limpiar referencia local
            this.mobileSessionId = null;
            console.log('✅ Sesión móvil limpiada completamente');
        }
    }
    
    /**
     * Inicia el monitoreo de la pestaña QR Móvil
     */
    async startQRTabMonitoring() {
        console.log('=== INICIO startQRTabMonitoring ===');
        console.log('Iniciando monitoreo de pestaña QR Móvil');
        
        // Inicializar array de imágenes pendientes si no existe
        if (!this.pendingMobileImages) {
            this.pendingMobileImages = [];
        }
        
        // Generar QR automáticamente si no existe
        if (!this.mobileSessionId && this.currentAntecedents) {
            console.log('Generando QR automáticamente al mostrar pestaña');
            await this.generateMobileQR(this.currentAntecedents.id);
        }
        
        // Verificar que se haya generado el sessionId
        if (!this.mobileSessionId) {
            console.error('❌ No se pudo generar mobileSessionId, no se puede iniciar polling');
            return;
        }
        
        // Iniciar polling para consultar nuevas imágenes cada 3 segundos
        console.log('Iniciando polling de imágenes temporales...');
        console.log('mobileSessionId disponible:', this.mobileSessionId);
        this.startImagePolling();
        console.log('=== FIN startQRTabMonitoring ===');
    }
    
    /**
     * Detiene el monitoreo de la pestaña QR Móvil
     */
    stopQRTabMonitoring() {
        console.log('=== INICIO stopQRTabMonitoring ===');
        console.log('Deteniendo monitoreo de pestaña QR Móvil');
        
        // Limpiar sesión móvil (esto ya incluye detener polling)
        this.cleanupMobileSession();
        
        console.log('=== FIN stopQRTabMonitoring ===');
    }
    
    /**
     * Agrega imágenes a la lista de pendientes
     */
    addPendingImages(images) {
        try {
            console.log('=== INICIO addPendingImages ===');
            console.log('Imágenes recibidas:', images);
            console.log('Cantidad:', images.length);
            
            if (!this.pendingMobileImages) {
                console.log('Inicializando array de pendientes');
                this.pendingMobileImages = [];
            }
            
            // Limpiar array removiendo imágenes que ya fueron procesadas (status != 'pending')
            const initialLength = this.pendingMobileImages.length;
            this.pendingMobileImages = this.pendingMobileImages.filter(img => {
                // Mantener solo imágenes con status 'pending' o sin status (compatibilidad)
                const shouldKeep = !img.status || img.status === 'pending';
                if (!shouldKeep) {
                    console.log(`Removiendo imagen procesada del array (ID: ${img.id}, status: ${img.status})`);
                }
                return shouldKeep;
            });
            
            const removedCount = initialLength - this.pendingMobileImages.length;
            if (removedCount > 0) {
                console.log(`🧹 Limpieza: ${removedCount} imagen(es) procesada(s) removida(s) del array`);
            }
            
            // Filtrar imágenes que ya existen (evitar duplicados)
            const existingIds = this.pendingMobileImages.map(img => img.id);
            console.log('IDs ya existentes:', existingIds);
            
            // Filtrar imágenes recibidas: solo agregar las que tienen status 'pending'
            const pendingImages = images.filter(img => {
                const isPending = !img.status || img.status === 'pending';
                if (!isPending) {
                    console.log(`Imagen ${img.id} tiene status '${img.status}', no se agregará (ya procesada)`);
                }
                return isPending;
            });
            
            console.log(`Imágenes pendientes después de filtrar: ${pendingImages.length} de ${images.length}`);
            
            // Agregar solo imágenes nuevas y pendientes
            let addedCount = 0;
            pendingImages.forEach((image, index) => {
                // Verificar si la imagen ya existe por ID
                if (!existingIds.includes(image.id)) {
                    // Mantener el ID original de la base de datos
                    const pendingImage = { ...image };
                    // Asegurar que tenga status 'pending'
                    if (!pendingImage.status) {
                        pendingImage.status = 'pending';
                    }
                    console.log(`Agregando imagen ${index + 1}:`, pendingImage);
                    this.pendingMobileImages.push(pendingImage);
                    addedCount++;
                } else {
                    console.log(`Imagen ${index + 1} ya existe (ID: ${image.id}), omitiendo...`);
                }
            });
            
            console.log(`✅ ${addedCount} imágenes nuevas agregadas`);
            console.log(`Total imágenes pendientes: ${this.pendingMobileImages.length}`);
            
            // Actualizar UI si se agregaron imágenes nuevas o se removieron procesadas
            if (addedCount > 0 || removedCount > 0) {
                console.log('Llamando a renderPendingImages...');
                this.renderPendingImages();
            } else {
                console.log('No hay cambios para renderizar');
            }
            
            console.log('=== FIN addPendingImages ===');
            
        } catch (error) {
            console.error('Error agregando imágenes pendientes:', error);
        }
    }
    
    /**
     * Renderiza las imágenes pendientes en la UI
     */
    renderPendingImages() {
        try {
            console.log('=== INICIO renderPendingImages ===');
            console.log('Imágenes pendientes a renderizar:', this.pendingMobileImages);
            console.log('Cantidad:', this.pendingMobileImages?.length || 0);
            
            const container = document.getElementById('qr-pending-images-container');
            const list = document.getElementById('qr-pending-images-list');
            const count = document.getElementById('pending-images-count');
            const instructionsContainer = document.getElementById('qr-instructions-container');
            
            console.log('Elementos DOM encontrados:', {
                container: !!container,
                list: !!list,
                count: !!count,
                instructionsContainer: !!instructionsContainer
            });
            
            if (!container || !list) {
                console.warn('❌ Contenedores de imágenes pendientes no encontrados');
                return;
            }
            
            // Si hay imágenes pendientes, mostrar contenedor y ocultar instrucciones
            if (this.pendingMobileImages && this.pendingMobileImages.length > 0) {
                console.log('✅ Hay imágenes pendientes, mostrando contenedor...');
                container.style.display = 'block';
                if (instructionsContainer) {
                    instructionsContainer.style.display = 'none';
                }
                
                // Actualizar contador
                if (count) {
                    count.textContent = this.pendingMobileImages.length;
                }
                
                // Renderizar lista de imágenes
                list.innerHTML = this.pendingMobileImages.map((img, index) => {
                    // Usar temp_path para imágenes pendientes
                    const imagePath = this.correctFilePath(img.temp_path || img.url || img.file_path || '');
                    console.log(`Renderizando imagen ${index + 1}:`, {
                        id: img.id,
                        temp_path: img.temp_path,
                        imagePath: imagePath
                    });
                    return `
                        <div class="card mb-2" data-pending-id="${img.id}">
                            <div class="row g-0">
                                <div class="col-md-4">
                                    <img src="${imagePath}" class="img-fluid rounded-start" alt="Imagen ${index + 1}" style="max-height: 150px; object-fit: cover;">
                                </div>
                                <div class="col-md-8">
                                    <div class="card-body">
                                        <h6 class="card-title">Imagen ${index + 1}</h6>
                                        <p class="card-text">
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>Recibida: ${new Date(img.timestamp || Date.now()).toLocaleTimeString()}
                                            </small>
                                        </p>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-success" onclick="window.derivacionesManager.acceptPendingImage('${img.id}')">
                                                <i class="fas fa-check me-1"></i>Aceptar
                                            </button>
                                            <button class="btn btn-danger" onclick="window.derivacionesManager.rejectPendingImage('${img.id}')">
                                                <i class="fas fa-times me-1"></i>Rechazar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
                
            } else {
                // No hay imágenes pendientes, ocultar contenedor y mostrar instrucciones
                container.style.display = 'none';
                if (instructionsContainer) {
                    instructionsContainer.style.display = 'block';
                }
            }
            
            console.log('=== FIN renderPendingImages ===');
            
        } catch (error) {
            console.error('❌ Error renderizando imágenes pendientes:', error);
            console.error('Stack:', error.stack);
        }
    }
    
    /**
     * Acepta una imagen pendiente
     */
    async acceptPendingImage(imageId) {
        try {
            console.log('Aceptando imagen pendiente:', imageId);
            
            // Buscar imagen en el array
            const imageIndex = this.pendingMobileImages.findIndex(img => img.id == imageId);
            
            if (imageIndex === -1) {
                console.warn('Imagen no encontrada:', imageId);
                return;
            }
            
            const image = this.pendingMobileImages[imageIndex];
            
            // Validar estado de la imagen antes de procesar
            if (image.status && image.status !== 'pending') {
                console.warn(`Imagen ${imageId} ya fue procesada (status: ${image.status}), removiendo del array`);
                
                // Remover del array ya que no está pendiente
                this.pendingMobileImages.splice(imageIndex, 1);
                this.renderPendingImages();
                
                // Si ya fue aceptada, actualizar UI
                if (image.status === 'accepted') {
                    await this.loadExistingAntecedents();
                    await this.loadAntecedentsStatus();
                    console.log('Imagen ya estaba aceptada, UI actualizada');
                }
                
                return;
            }
            
            // Mover imagen de temporal a permanente
            console.log('Moviendo imagen de temporal a permanente:', image.temp_path);
            const response = await fetch(`${this.apiBaseUrl}accept_temp_mobile_image.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    temp_path: image.temp_path,
                    study_id: this.currentAntecedents.id,
                    created_by: this.getCurrentUser()?.id || 1
                })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                // Si el error indica que ya fue procesada, remover del array
                if (result.error && (
                    result.error.includes('ya fue aceptada') || 
                    result.error.includes('ya procesada') ||
                    result.error.includes('rechazada')
                )) {
                    console.warn('Imagen ya procesada según servidor, removiendo del array');
                    this.pendingMobileImages.splice(imageIndex, 1);
                    this.renderPendingImages();
                    
                    // Si fue aceptada, actualizar UI
                    if (result.error.includes('aceptada') || result.error.includes('procesada')) {
                        await this.loadExistingAntecedents();
                        await this.loadAntecedentsStatus();
                    }
                    
                    return;
                }
                
                throw new Error(result.error || 'Error aceptando imagen');
            }
            
            console.log('Imagen movida a permanente exitosamente:', result.data);
            
            // Remover de pendientes
            this.pendingMobileImages.splice(imageIndex, 1);
            
            // Actualizar UI
            this.renderPendingImages();
            
            // Actualizar pestaña Existentes
            await this.loadExistingAntecedents();
            await this.loadAntecedentsStatus();
            
            console.log('Imagen aceptada exitosamente');
            
        } catch (error) {
            console.error('Error aceptando imagen:', error);
            await this.showAlert('Error', 'Error aceptando imagen: ' + error.message, 'error');
        }
    }
    
    /**
     * Rechaza una imagen pendiente
     */
    async rejectPendingImage(imageId) {
        try {
            console.log('=== INICIO rejectPendingImage ===');
            console.log('Rechazando imagen pendiente:', imageId);
            
            // Buscar imagen en el array
            const imageIndex = this.pendingMobileImages.findIndex(img => img.id == imageId);
            
            if (imageIndex === -1) {
                console.warn('Imagen no encontrada:', imageId);
                return;
            }
            
            const image = this.pendingMobileImages[imageIndex];
            console.log('Imagen encontrada:', image);
            
            // Marcar como rechazada en la base de datos
            console.log('Marcando imagen como rechazada en BD...');
            const rejectResponse = await fetch(`${this.apiBaseUrl}reject_temp_mobile_image.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    image_id: imageId,
                    temp_path: image.temp_path 
                })
            });
            
            const rejectResult = await rejectResponse.json();
            console.log('Resultado de marcar como rechazada:', rejectResult);
            
            if (!rejectResult.success) {
                console.warn('No se pudo marcar como rechazada en BD:', rejectResult.error);
            }
            
            // Eliminar archivo temporal del servidor
            if (image.temp_path) {
                console.log('Eliminando archivo temporal:', image.temp_path);
                const deleteResponse = await fetch(`${this.apiBaseUrl}delete_antecedent_file.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ file_path: image.temp_path })
                });
                
                const deleteResult = await deleteResponse.json();
                console.log('Resultado de eliminar archivo:', deleteResult);
            }
            
            // Remover de pendientes
            this.pendingMobileImages.splice(imageIndex, 1);
            console.log('Imagen removida del array de pendientes');
            
            // Actualizar UI
            this.renderPendingImages();
            
            console.log('✅ Imagen rechazada exitosamente');
            console.log('=== FIN rejectPendingImage ===');
            
        } catch (error) {
            console.error('❌ Error rechazando imagen:', error);
            console.error('Stack:', error.stack);
            await this.showAlert('Error', 'Error rechazando imagen: ' + error.message, 'error');
        }
    }
    
    /**
     * Muestra un modal de alerta Bootstrap
     */
    showAlert(title, message, type = 'info') {
        return new Promise((resolve) => {
            // Crear modal
            const modalId = 'alertModal_' + Date.now();
            const iconClass = {
                'success': 'fa-check-circle text-success',
                'error': 'fa-exclamation-circle text-danger',
                'warning': 'fa-exclamation-triangle text-warning',
                'info': 'fa-info-circle text-info'
            }[type] || 'fa-info-circle text-info';
            
            const modalHtml = `
                <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true" style="z-index: 1070 !important;">
                    <div class="modal-dialog modal-dialog-centered" style="z-index: 1071 !important;">
                        <div class="modal-content" style="z-index: 1072 !important;">
                            <div class="modal-header border-0">
                                <h5 class="modal-title">
                                    <i class="fas ${iconClass} me-2"></i>${title}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                ${message}
                            </div>
                            <div class="modal-footer border-0">
                                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Aceptar</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modalElement = document.getElementById(modalId);
            
            // Forzar z-index del backdrop también
            modalElement.addEventListener('shown.bs.modal', () => {
                const backdrop = document.querySelector('.modal-backdrop.show');
                if (backdrop) {
                    backdrop.style.zIndex = '1069';
                }
            });
            
            // Mostrar modal
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            modal.show();
            
            // Limpiar al cerrar
            modalElement.addEventListener('hidden.bs.modal', () => {
                modalElement.remove();
                resolve();
            });
        });
    }
    
    /**
     * Muestra un modal de confirmación Bootstrap
     */
    showConfirm(title, message, confirmText = 'Confirmar', cancelText = 'Cancelar') {
        return new Promise((resolve) => {
            // Crear modal
            const modalId = 'confirmModal_' + Date.now();
            const modalHtml = `
                <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true" style="z-index: 1070 !important;">
                    <div class="modal-dialog modal-dialog-centered" style="z-index: 1071 !important;">
                        <div class="modal-content" style="z-index: 1072 !important;">
                            <div class="modal-header border-0">
                                <h5 class="modal-title">
                                    <i class="fas fa-question-circle text-warning me-2"></i>${title}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                ${message}
                            </div>
                            <div class="modal-footer border-0">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${cancelText}</button>
                                <button type="button" class="btn btn-primary" id="${modalId}_confirm">${confirmText}</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modalElement = document.getElementById(modalId);
            const confirmBtn = document.getElementById(`${modalId}_confirm`);
            
            // Forzar z-index del backdrop también
            modalElement.addEventListener('shown.bs.modal', () => {
                const backdrop = document.querySelector('.modal-backdrop.show');
                if (backdrop) {
                    backdrop.style.zIndex = '1069';
                }
            });
            
            // Mostrar modal
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            modal.show();
            
            // Manejar confirmación
            confirmBtn.addEventListener('click', () => {
                modal.hide();
                resolve(true);
            });
            
            // Limpiar al cerrar
            modalElement.addEventListener('hidden.bs.modal', () => {
                modalElement.remove();
                resolve(false);
            });
        });
    }
    
    /**
     * Acepta todas las imágenes pendientes
     */
    async acceptAllPendingImages() {
        try {
            console.log('=== INICIO acceptAllPendingImages ===');
            console.log('Aceptando todas las imágenes pendientes');
            
            if (!this.pendingMobileImages || this.pendingMobileImages.length === 0) {
                await this.showAlert('Sin imágenes', 'No hay imágenes pendientes para aceptar', 'info');
                return;
            }
            
            const totalImages = this.pendingMobileImages.length;
            console.log(`Total imágenes a procesar: ${totalImages}`);
            
            // Aceptar cada imagen individualmente, continuando aunque alguna falle
            const imagesToAccept = [...this.pendingMobileImages];
            let successCount = 0;
            let failedCount = 0;
            const failedImages = [];
            
            for (const image of imagesToAccept) {
                try {
                    console.log(`Procesando imagen ${image.id}...`);
                    await this.acceptPendingImage(image.id);
                    successCount++;
                    console.log(`✅ Imagen ${image.id} aceptada exitosamente`);
                } catch (error) {
                    failedCount++;
                    failedImages.push({
                        id: image.id,
                        fileName: image.file_name || image.temp_path,
                        error: error.message
                    });
                    console.error(`❌ Error aceptando imagen ${image.id}:`, error.message);
                    
                    // Si el error indica que ya fue procesada, no es un error real
                    if (error.message && (
                        error.message.includes('ya fue aceptada') ||
                        error.message.includes('ya procesada') ||
                        error.message.includes('rechazada')
                    )) {
                        // No contar como fallo real
                        failedCount--;
                        successCount++;
                        console.log(`ℹ️ Imagen ${image.id} ya procesada, contando como éxito`);
                    }
                }
            }
            
            console.log(`=== RESUMEN ===`);
            console.log(`Total: ${totalImages}`);
            console.log(`✅ Exitosas: ${successCount}`);
            console.log(`❌ Fallidas: ${failedCount}`);
            
            // Mostrar resumen al usuario
            if (failedCount === 0) {
                await this.showAlert(
                    'Éxito', 
                    `Todas las ${successCount} imagen(es) han sido aceptadas correctamente`, 
                    'success'
                );
            } else if (successCount > 0) {
                await this.showAlert(
                    'Proceso completado', 
                    `${successCount} imagen(es) aceptada(s) correctamente.\n${failedCount} imagen(es) fallaron.\n\nVer consola para más detalles.`, 
                    'warning'
                );
            } else {
                await this.showAlert(
                    'Error', 
                    `No se pudo aceptar ninguna imagen.\n${failedCount} imagen(es) fallaron.\n\nVer consola para más detalles.`, 
                    'error'
                );
            }
            
            // Log detallado de imágenes fallidas
            if (failedImages.length > 0) {
                console.error('Imágenes que fallaron:', failedImages);
            }
            
            console.log('=== FIN acceptAllPendingImages ===');
            
        } catch (error) {
            console.error('Error crítico en acceptAllPendingImages:', error);
            await this.showAlert('Error', 'Error crítico aceptando imágenes: ' + error.message, 'error');
        }
    }
    
    /**
     * Rechaza todas las imágenes pendientes
     */
    async rejectAllPendingImages() {
        try {
            console.log('=== INICIO rejectAllPendingImages ===');
            console.log('Rechazando todas las imágenes pendientes');
            
            if (!this.pendingMobileImages || this.pendingMobileImages.length === 0) {
                await this.showAlert('Sin imágenes', 'No hay imágenes pendientes para rechazar', 'info');
                return;
            }
            
            const confirmed = await this.showConfirm(
                'Confirmar rechazo',
                `¿Está seguro de que desea rechazar todas las imágenes pendientes (${this.pendingMobileImages.length})?<br><br><strong>Esta acción no se puede deshacer.</strong>`,
                'Rechazar Todas',
                'Cancelar'
            );
            
            if (!confirmed) {
                console.log('Rechazo cancelado por el usuario');
                return;
            }
            
            console.log(`Rechazando ${this.pendingMobileImages.length} imágenes...`);
            
            // Marcar como rechazadas y eliminar archivos
            for (const image of this.pendingMobileImages) {
                console.log(`Procesando imagen ${image.id}...`);
                
                // Marcar como rechazada en BD
                await fetch(`${this.apiBaseUrl}reject_temp_mobile_image.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        image_id: image.id,
                        temp_path: image.temp_path 
                    })
                });
                
                // Eliminar archivo temporal
                if (image.temp_path) {
                    console.log('Eliminando archivo temporal:', image.temp_path);
                    await fetch(`${this.apiBaseUrl}delete_antecedent_file.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ file_path: image.temp_path })
                    });
                }
            }
            
            // Limpiar array de pendientes
            this.pendingMobileImages = [];
            console.log('Array de pendientes limpiado');
            
            // Actualizar UI
            this.renderPendingImages();
            
            console.log('✅ Todas las imágenes rechazadas exitosamente');
            console.log('=== FIN rejectAllPendingImages ===');
            await this.showAlert('Éxito', 'Todas las imágenes han sido rechazadas', 'success');
            
        } catch (error) {
            console.error('❌ Error rechazando todas las imágenes:', error);
            console.error('Stack:', error.stack);
            await this.showAlert('Error', 'Error rechazando imágenes: ' + error.message, 'error');
        }
    }
    
    /**
     * Inicia el polling para consultar nuevas imágenes temporales
     */
    startImagePolling() {
        console.log('=== INICIO startImagePolling ===');
        console.log('Timestamp:', new Date().toISOString());
        
        // Detener polling anterior si existe
        this.stopImagePolling();
        
        // Verificar que exista sessionId
        if (!this.mobileSessionId) {
            console.error('❌ No hay mobileSessionId, no se puede iniciar polling');
            console.error('this.mobileSessionId:', this.mobileSessionId);
            return;
        }
        
        console.log('✅ mobileSessionId encontrado:', this.mobileSessionId);
        console.log('Iniciando polling cada 3 segundos...');
        
        // Consultar inmediatamente
        console.log('Ejecutando primera consulta inmediata...');
        this.checkForNewImages();
        
        // Configurar intervalo de polling
        console.log('Configurando setInterval...');
        this.imagePollingInterval = setInterval(() => {
            console.log('⏰ Intervalo ejecutado -', new Date().toLocaleTimeString());
            this.checkForNewImages();
        }, 3000); // Cada 3 segundos
        
        console.log('✅ Polling iniciado exitosamente');
        console.log('Intervalo ID:', this.imagePollingInterval);
        console.log('=== FIN startImagePolling ===');
    }
    
    /**
     * Detiene el polling de imágenes
     */
    stopImagePolling() {
        console.log('=== INICIO stopImagePolling ===');
        
        if (this.imagePollingInterval) {
            console.log('Deteniendo intervalo de polling');
            clearInterval(this.imagePollingInterval);
            this.imagePollingInterval = null;
            console.log('Intervalo detenido');
        } else {
            console.log('No hay intervalo activo para detener');
        }
        
        console.log('=== FIN stopImagePolling ===');
    }
    
    /**
     * Consulta al servidor si hay nuevas imágenes temporales
     */
    async checkForNewImages() {
        try {
            const timestamp = new Date().toLocaleTimeString();
            console.log(`🔍 [${timestamp}] Consultando nuevas imágenes...`);
            console.log(`   SessionId: ${this.mobileSessionId}`);
            
            if (!this.mobileSessionId) {
                console.warn('⚠️ No hay mobileSessionId - deteniendo polling');
                this.stopImagePolling();
                return;
            }
            
            // Consultar API
            const url = `${this.apiBaseUrl}get_temp_mobile_images.php?session_id=${this.mobileSessionId}`;
            console.log(`   URL: ${url}`);
            
            const response = await fetch(url);
            console.log(`   Status: ${response.status} ${response.statusText}`);
            
            const result = await response.json();
            console.log(`   Respuesta:`, result);
            
            if (!result.success) {
                console.error('❌ Error obteniendo imágenes:', result.error);
                return;
            }
            
            const newImages = result.data || [];
            console.log(`   📥 Imágenes en servidor: ${newImages.length}`);
            
            if (newImages.length > 0) {
                console.log('   ✅ Hay imágenes en el servidor!');
                console.log('   Imágenes:', newImages);
                
                // addPendingImages ya maneja la detección de duplicados internamente
                this.addPendingImages(newImages);
            } else {
                console.log('   ℹ️ No hay imágenes nuevas en el servidor');
            }
            
        } catch (error) {
            console.error('❌ Error consultando nuevas imágenes:', error);
            console.error('   Message:', error.message);
            console.error('   Stack:', error.stack);
        }
    }

    /**
     * Configura los eventos para la selección múltiple de archivos
     */
    setupMultipleSelectionEvents() {
        try {
            console.log('Configurando eventos de selección múltiple...');
            
            // Event listener para el checkbox "Seleccionar todo"
            const selectAllCheckbox = document.getElementById('selectAllFiles');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', (e) => {
                    const fileCheckboxes = document.querySelectorAll('.file-checkbox');
                    fileCheckboxes.forEach(checkbox => {
                        checkbox.checked = e.target.checked;
                    });
                    this.updateDeleteSelectedButton();
                });
            }

            // Event listeners para checkboxes individuales
            const fileCheckboxes = document.querySelectorAll('.file-checkbox');
            fileCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    this.updateDeleteSelectedButton();
                    this.updateSelectAllCheckbox();
                });
            });

            // Event listener para el botón "Eliminar seleccionados"
            const deleteSelectedBtn = document.getElementById('deleteSelectedFiles');
            if (deleteSelectedBtn) {
                // Remover event listeners existentes
                deleteSelectedBtn.replaceWith(deleteSelectedBtn.cloneNode(true));
                const newDeleteSelectedBtn = document.getElementById('deleteSelectedFiles');
                
                newDeleteSelectedBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('🔍 Debug - Botón eliminar seleccionados clickeado');
                    
                    const selectedFiles = document.querySelectorAll('.file-checkbox:checked');
                    console.log('🔍 Debug - Archivos seleccionados:', selectedFiles.length);
                    
                    if (selectedFiles.length > 0) {
                        const fileIds = Array.from(selectedFiles).map(cb => cb.dataset.fileId);
                        console.log('🔍 Debug - IDs de archivos a eliminar:', fileIds);
                        this.deleteMultipleFiles(fileIds);
                    } else {
                        console.log('🔍 Debug - No hay archivos seleccionados');
                    }
                });
                
                console.log('🔍 Debug - Event listener para eliminar seleccionados configurado');
            } else {
                console.warn('🔍 Debug - Botón deleteSelectedFiles no encontrado');
            }

            console.log('Eventos de selección múltiple configurados exitosamente');
            
        } catch (error) {
            console.error('Error configurando eventos de selección múltiple:', error);
        }
    }

    /**
     * Actualiza el estado del botón "Eliminar seleccionados"
     */
    updateDeleteSelectedButton() {
        const selectedFiles = document.querySelectorAll('.file-checkbox:checked');
        const deleteBtn = document.getElementById('deleteSelectedFiles');
        
        if (deleteBtn) {
            if (selectedFiles.length > 0) {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = `<i class="fas fa-trash me-1"></i>Eliminar seleccionados (${selectedFiles.length})`;
            } else {
                deleteBtn.disabled = true;
                deleteBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Eliminar seleccionados';
            }
        }
    }

    /**
     * Actualiza el estado del checkbox "Seleccionar todo"
     */
    updateSelectAllCheckbox() {
        const selectAllCheckbox = document.getElementById('selectAllFiles');
        const fileCheckboxes = document.querySelectorAll('.file-checkbox');
        const checkedBoxes = document.querySelectorAll('.file-checkbox:checked');
        
        if (selectAllCheckbox && fileCheckboxes.length > 0) {
            if (checkedBoxes.length === fileCheckboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedBoxes.length > 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            }
        }
    }
    
    /**
     * Maneja el z-index del backdrop de Bootstrap
     */
    fixModalBackdropZIndex() {
        // Buscar todos los backdrops existentes
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach((backdrop, index) => {
            backdrop.style.zIndex = (9998 - index).toString();
        });
        
        // Buscar todos los modales y ajustar su z-index
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach((modal, index) => {
            if (modal.id === 'confirmationModal') {
                modal.style.zIndex = '9999';
            } else {
                modal.style.zIndex = (1055 + index).toString();
            }
        });
    }

    /**
     * Limpia todos los backdrops residuales
     */
    cleanupModalBackdrops() {
        console.log('🔍 DEBUG: Limpiando backdrops residuales');
        
        // Remover todos los backdrops
        const backdrops = document.querySelectorAll('.modal-backdrop');
        console.log('🔍 DEBUG: Encontrados', backdrops.length, 'backdrops');
        
        backdrops.forEach((backdrop, index) => {
            console.log('🔍 DEBUG: Removiendo backdrop', index);
            backdrop.remove();
        });
        
        // Remover clase modal-open del body
        if (document.body.classList.contains('modal-open')) {
            document.body.classList.remove('modal-open');
            console.log('🔍 DEBUG: Clase modal-open removida del body');
        }
        
        // Limpiar estilos de overflow
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        
        // Verificar que no queden modales abiertos
        const openModals = document.querySelectorAll('.modal.show');
        console.log('🔍 DEBUG: Modales abiertos restantes:', openModals.length);
        
        console.log('🔍 DEBUG: Limpieza de backdrops completada');
    }

    /**
     * Crea un modal de confirmación personalizado
     */
    createCustomConfirmationModal(message, fileNames = [], callback) {
        const modalId = 'customConfirmationModal';
        
        // Remover modal existente si existe
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        // Crear el HTML del modal
        const filesListHtml = fileNames.length > 0 ? `
            <div class="files-list mt-3">
                <h6 class="text-muted mb-2">Archivos a eliminar:</h6>
                <div class="files-container border rounded p-2 bg-light" style="max-height: 200px; overflow-y: auto;">
                    ${fileNames.map(fileName => `
                        <div class="file-item d-flex align-items-center mb-1">
                            <i class="fas fa-file text-muted me-2"></i>
                            <span class="text-truncate">${fileName}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        ` : '';
        
        const modalHtml = `
            <div id="${modalId}" class="custom-modal-overlay" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.6);
                z-index: 99999;
                display: flex;
                justify-content: center;
                align-items: center;
            ">
                <div class="custom-modal" style="
                    background: white;
                    border-radius: 12px;
                    padding: 0;
                    max-width: 500px;
                    width: 90%;
                    max-height: 80vh;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                    overflow: hidden;
                ">
                    <div class="custom-modal-header" style="
                        background: linear-gradient(135deg, #dc3545, #c82333);
                        color: white;
                        padding: 20px;
                        display: flex;
                        align-items: center;
                        gap: 15px;
                    ">
                        <div style="font-size: 24px; opacity: 0.9;">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h4 style="margin: 0; font-size: 18px; font-weight: 600;">Confirmar Eliminación</h4>
                    </div>
                    
                    <div class="custom-modal-body" style="padding: 25px;">
                        <p style="font-size: 16px; color: #333; margin-bottom: 10px; font-weight: 500;">${message}</p>
                        <p style="font-size: 14px; color: #666; margin-bottom: 20px;">Esta acción eliminará tanto los archivos físicos como sus referencias en la base de datos.</p>
                        ${filesListHtml}
                    </div>
                    
                    <div class="custom-modal-footer" style="
                        padding: 20px 25px;
                        background: #f8f9fa;
                        display: flex;
                        justify-content: flex-end;
                        gap: 10px;
                    ">
                        <button type="button" class="btn btn-secondary" id="customCancelBtn" style="
                            padding: 8px 16px;
                            border-radius: 6px;
                            font-size: 14px;
                            font-weight: 500;
                            border: none;
                            cursor: pointer;
                            background: #6c757d;
                            color: white;
                        ">
                            <i class="fas fa-times me-1"></i>Cancelar
                        </button>
                        <button type="button" class="btn btn-danger" id="customConfirmBtn" style="
                            padding: 8px 16px;
                            border-radius: 6px;
                            font-size: 14px;
                            font-weight: 500;
                            border: none;
                            cursor: pointer;
                            background: #dc3545;
                            color: white;
                        ">
                            <i class="fas fa-trash me-1"></i>Eliminar
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Configurar eventos
        const modal = document.getElementById(modalId);
        const confirmBtn = document.getElementById('customConfirmBtn');
        const cancelBtn = document.getElementById('customCancelBtn');
        
        // Botón de confirmación
        confirmBtn.addEventListener('click', () => {
            modal.remove();
            if (callback) {
                callback();
            }
        });
        
        // Botón de cancelación
        cancelBtn.addEventListener('click', () => {
            modal.remove();
        });
        
        // Cerrar con ESC
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                modal.remove();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
        
        // Cerrar al hacer clic en el overlay
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
            }
        });
        
        console.log('🔍 DEBUG: Modal personalizado creado y mostrado');
    }

    /**
     * Crea un modal de información dinámico
     */
    createDynamicInfoModal(title, content) {
        try {
            console.log('🔍 DEBUG: Creando modal dinámico de información');
            
            // Limpiar estado de modales anteriores
            this.cleanupModalState();
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('dynamicInfoModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Esperar un momento para que se limpie completamente
            setTimeout(() => {
                // Crear modal dinámicamente
                const infoModal = document.createElement('div');
                infoModal.className = 'modal fade';
                infoModal.id = 'dynamicInfoModal';
                infoModal.setAttribute('data-bs-backdrop', 'true');
                infoModal.setAttribute('data-bs-keyboard', 'true');
                infoModal.style.zIndex = '10000';
                infoModal.style.position = 'fixed';
                
                infoModal.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="z-index: 10001; position: relative;">
                        <div class="modal-content" style="z-index: 10002; pointer-events: auto;">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-info-circle me-2"></i>${title}
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                ${content}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i>Cerrar
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                // Agregar al DOM
                document.body.appendChild(infoModal);
                
                // Verificar si hay una instancia previa y limpiarla
                const existingInstance = bootstrap.Modal.getInstance(infoModal);
                if (existingInstance) {
                    existingInstance.dispose();
                }
                
                // Crear instancia de Bootstrap Modal y mostrar
                const modalInstance = new bootstrap.Modal(infoModal, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                });
                
                modalInstance.show();
                
                // Configurar evento para limpiar el modal cuando se cierre
                const cleanupHandler = () => {
                    // Limpiar cualquier backdrop residual
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => {
                        if (backdrop.parentNode) {
                            backdrop.parentNode.removeChild(backdrop);
                        }
                    });
                    // Remover clase modal-open del body si no hay otros modales abiertos
                    if (document.querySelectorAll('.modal.show').length === 0) {
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                    }
                    
                    // Limpiar el modal
                    try {
                        modalInstance.dispose();
                        infoModal.remove();
                    } catch (e) {
                        console.warn('Error limpiando modal:', e);
                    }
                };
                
                // Remover listener anterior si existe
                infoModal.removeEventListener('hidden.bs.modal', cleanupHandler);
                // Agregar nuevo listener
                infoModal.addEventListener('hidden.bs.modal', cleanupHandler);
                
                console.log('✅ DEBUG: Modal de información creado y mostrado');
            }, 100);
            
        } catch (error) {
            console.error('❌ ERROR: Creando modal de información:', error);
            // Fallback: usar alert
            alert(title + '\n\n' + content.replace(/<[^>]*>/g, ''));
        }
    }

    /**
     * Crea un modal de confirmación dinámico usando la misma técnica que el visor de imágenes
     */
    createDynamicConfirmationModal(message, fileNames = [], callback) {
        try {
            console.log('🔍 DEBUG: Creando modal dinámico de confirmación');
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('dynamicConfirmationModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Crear modal dinámicamente (igual que el visor de imágenes)
            const confirmationModal = document.createElement('div');
            confirmationModal.className = 'modal fade';
            confirmationModal.id = 'dynamicConfirmationModal';
            confirmationModal.setAttribute('data-bs-backdrop', 'static');
            confirmationModal.setAttribute('data-bs-keyboard', 'true');
            confirmationModal.style.zIndex = '9999'; // Z-index muy alto para estar sobre el modal de antecedentes
            
            // Crear lista de archivos si hay múltiples
            const filesListHtml = fileNames.length > 0 ? `
                <div class="mt-3">
                    <h6 class="text-muted mb-2">Archivos a eliminar:</h6>
                    <div class="border rounded p-2 bg-light" style="max-height: 200px; overflow-y: auto;">
                        ${fileNames.map(fileName => `
                            <div class="d-flex align-items-center mb-1">
                                <i class="fas fa-file text-muted me-2"></i>
                                <span class="text-truncate">${fileName}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            ` : '';
            
            confirmationModal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered" style="z-index: 10000;">
                    <div class="modal-content" style="z-index: 10001;">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-trash-alt text-danger" style="font-size: 2rem;"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <p class="mb-1">${message}</p>
                                    <small class="text-muted">Esta acción eliminará tanto los archivos físicos como sus referencias en la base de datos.</small>
                                </div>
                            </div>
                            ${filesListHtml}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-danger" id="dynamicConfirmBtn">
                                <i class="fas fa-trash me-1"></i>Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar al DOM y mostrar (igual que el visor de imágenes)
            document.body.appendChild(confirmationModal);
            
            // Configurar evento de confirmación
            const confirmBtn = confirmationModal.querySelector('#dynamicConfirmBtn');
            confirmBtn.addEventListener('click', () => {
                const modalElement = confirmationModal;
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                if (modalInstance) {
                    modalInstance.hide();
                } else {
                    // Si no hay instancia, crear una nueva y cerrarla
                    const newModal = new bootstrap.Modal(modalElement);
                    newModal.hide();
                }
                
                // Limpiar cualquier backdrop residual después de un pequeño delay
                setTimeout(() => {
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => {
                        if (backdrop.parentNode) {
                            backdrop.parentNode.removeChild(backdrop);
                        }
                    });
                    // Remover clase modal-open del body si no hay otros modales abiertos
                    if (document.querySelectorAll('.modal.show').length === 0) {
                        document.body.classList.remove('modal-open');
                    }
                }, 300);
                
                if (callback) {
                    callback();
                }
            });
            
            // Mostrar el modal usando Bootstrap con configuración mejorada
            const bootstrapModal = new bootstrap.Modal(confirmationModal, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            bootstrapModal.show();
            
            // Limpiar backdrops cuando se cierre el modal por cualquier motivo
            confirmationModal.addEventListener('hidden.bs.modal', () => {
                // Limpiar cualquier backdrop residual
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                // Remover clase modal-open del body si no hay otros modales abiertos
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.body.classList.remove('modal-open');
                }
                // Remover el modal del DOM
                if (confirmationModal.parentNode) {
                    confirmationModal.parentNode.removeChild(confirmationModal);
                }
            });
            
            console.log('🔍 DEBUG: Modal dinámico de confirmación creado y mostrado');
            
        } catch (error) {
            console.error('🚨 ERROR creando modal dinámico:', error);
            // Fallback a confirm nativo
            if (confirm(message)) {
                if (callback) {
                    callback();
                }
            }
        }
    }

    /**
     * Muestra el modal de confirmación para un archivo individual
     */
    showConfirmationModal(fileName, callback) {
        console.log('🔍 DEBUG: Mostrando confirmación para:', fileName);
        
        const message = `¿Está seguro de que desea eliminar el archivo "${fileName}"?`;
        
        // Usar la misma técnica que el visor de imágenes
        this.createDynamicConfirmationModal(message, [], callback);
    }
    
    /**
     * Método de confirmación alternativo
     */
    fallbackConfirmation(message, callback) {
        console.log('🔄 FALLBACK: Usando confirmación alternativa');
        
        // Intentar crear un modal simple con JavaScript puro
        const fallbackModal = this.createFallbackModal(message, callback);
        if (fallbackModal) {
            console.log('✅ FALLBACK: Modal alternativo creado');
            return;
        }
        
        // Último recurso: confirm nativo del navegador
        console.log('🔄 FALLBACK: Usando confirm() nativo del navegador');
        if (confirm(message)) {
            if (typeof callback === 'function') {
                callback();
            }
        }
    }
    
    /**
     * Crear modal alternativo simple
     */
    createFallbackModal(message, callback) {
        try {
            // Crear modal dinámicamente
            const modalHtml = `
                <div id="fallbackModal" style="
                    position: fixed; 
                    top: 0; 
                    left: 0; 
                    width: 100%; 
                    height: 100%; 
                    background: rgba(0,0,0,0.7); 
                    z-index: 999999; 
                    display: flex; 
                    justify-content: center; 
                    align-items: center;
                ">
                    <div style="
                        background: white; 
                        padding: 30px; 
                        border-radius: 10px; 
                        max-width: 400px; 
                        width: 90%; 
                        text-align: center;
                        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                    ">
                        <h3 style="color: #dc3545; margin-bottom: 20px;">⚠️ Confirmar Eliminación</h3>
                        <p style="margin-bottom: 30px; color: #333;">${message}</p>
                        <div>
                            <button id="fallbackCancel" style="
                                padding: 10px 20px; 
                                margin-right: 10px; 
                                border: 1px solid #ccc; 
                                background: white; 
                                border-radius: 5px; 
                                cursor: pointer;
                            ">Cancelar</button>
                            <button id="fallbackConfirm" style="
                                padding: 10px 20px; 
                                border: none; 
                                background: #dc3545; 
                                color: white; 
                                border-radius: 5px; 
                                cursor: pointer;
                            ">Eliminar</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Insertar en el DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            const modal = document.getElementById('fallbackModal');
            const confirmBtn = document.getElementById('fallbackConfirm');
            const cancelBtn = document.getElementById('fallbackCancel');
            
            // Configurar eventos
            confirmBtn.onclick = () => {
                modal.remove();
                if (typeof callback === 'function') {
                    callback();
                }
            };
            
            cancelBtn.onclick = () => {
                modal.remove();
            };
            
            // Cerrar con ESC
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    modal.remove();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            
            return true;
        } catch (error) {
            console.error('🚨 ERROR creando modal alternativo:', error);
            return false;
        }
    }

    /**
     * Muestra el modal de confirmación para múltiples archivos
     */
    showMultipleFilesConfirmationModal(files, callback) {
        console.log('🔍 DEBUG: Mostrando confirmación para múltiples archivos:', files.length);
        
        const message = `¿Está seguro de que desea eliminar ${files.length} archivo(s)?`;
        const fileNames = files.map(file => file.name || file.file_name || 'Archivo sin nombre');
        
        // Usar la misma técnica que el visor de imágenes
        this.createDynamicConfirmationModal(message, fileNames, callback);
    }
}

// Instancia global
let derivacionesManager;

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔍 Debug - Inicializando DerivacionesManager...');
    derivacionesManager = new DerivacionesManager();
    window.derivacionesManager = derivacionesManager; // Asegurar disponibilidad global
    console.log('🔍 Debug - DerivacionesManager creado:', !!derivacionesManager);
    console.log('🔍 Debug - Disponible globalmente:', !!window.derivacionesManager);
    derivacionesManager.init();
    console.log('🔍 Debug - DerivacionesManager inicializado');
});

// Función global para búsqueda (compatibilidad)
function searchStudies() {
    if (derivacionesManager) {
        derivacionesManager.handleSearchClick();
    }
}

// Función global para guardar antecedentes desde modal de emergencia
async function emergencySaveAntecedents(studyId) {
    try {
        console.log('Guardando antecedentes desde modal de emergencia para estudio:', studyId);
        
        const notes = document.getElementById('emergencyNotes').value;
        console.log('Notas a guardar:', notes);
        
        // Obtener el manager de derivaciones
        let manager = window.derivacionesManager;
        if (!manager) {
            // Buscar en el DOM si no está disponible globalmente
            const managerElement = document.querySelector('[data-manager="derivaciones"]');
            if (managerElement && managerElement.derivacionesManager) {
                manager = managerElement.derivacionesManager;
            }
        }
        
        if (!manager) {
            console.error('Manager de derivaciones no disponible');
            alert('Error: No se pudo acceder al sistema. Recarga la página.');
            return;
        }
        
        // Guardar notas en la base de datos
        await manager.saveAntecedentsNotes(studyId, notes);
        console.log('Antecedentes guardados exitosamente desde modal de emergencia');

        // Habilitar copia solo después de guardar (evita copiar texto no persistido)
        manager.antecedentsSavedForCopy = true;
        if (typeof manager.updateCopyAntecedentsButtonVisibility === 'function') {
            manager.updateCopyAntecedentsButtonVisibility(
                (manager.studies || []).find((s) => String(s.id) === String(studyId)) || manager.selectedStudy
            );
        }
        
        manager.showBootstrapAlert(
            'Éxito', 
            'Los antecedentes médicos se han guardado correctamente.', 
            'success', 
            2000
        );
        
        // Mantener el contenido en pantalla para evitar guardados vacíos por doble click/segundo guardado.
        // La recarga posterior desde API re-sincroniza el valor real persistido.
        
        // Actualizar la pestaña "Existentes" para mostrar las notas recién guardadas
        setTimeout(async () => {
            await manager.loadExistingAntecedents();
            // También actualizar el contador en la lista principal
            await manager.loadAntecedentsStatus();
            // Sugerir copia a otros estudios del mismo paciente / misma fecha
            if (typeof manager.maybeSuggestCopyAntecedents === 'function') {
                await manager.maybeSuggestCopyAntecedents(studyId);
            }
        }, 1000);
        
        // NO cerrar el modal automáticamente para permitir continuar agregando contenido
        // closeEmergencyModal();
        
    } catch (error) {
        console.error('Error guardando antecedentes desde modal de emergencia:', error);
        console.error('Error guardando antecedentes:', error);
        // Intentar mostrar alerta usando el manager o fallback a alert nativo
        let manager = window.derivacionesManager;
        if (!manager) {
            const managerElement = document.querySelector('[data-manager="derivaciones"]');
            if (managerElement && managerElement.derivacionesManager) {
                manager = managerElement.derivacionesManager;
            }
        }
        
        if (manager && manager.showBootstrapAlert) {
            manager.showBootstrapAlert(
                'Error', 
                'No se pudieron guardar los antecedentes médicos. Inténtalo de nuevo.', 
                'error', 
                0
            );
        } else {
            alert('Error: No se pudieron guardar los antecedentes médicos. Inténtalo de nuevo.');
        }
    }
}

// Función global para cerrar modal de emergencia
function closeEmergencyModal() {
    try {
        console.log('Cerrando modal de emergencia...');
        
        // Remover modal de emergencia
        const emergencyModal = document.getElementById('emergencyAntecedentsModal');
        if (emergencyModal) {
            emergencyModal.remove();
        }
        
        // Remover modal original si existe
        const originalModal = document.getElementById('antecedentsModal');
        if (originalModal) {
            originalModal.remove();
        }
        
        // Remover backdrop personalizado
        const backdrop = document.getElementById('antecedentsModalBackdrop');
        if (backdrop) {
            backdrop.remove();
        }
        
        // Remover TODOS los backdrops de Bootstrap
        const allBackdrops = document.querySelectorAll('.modal-backdrop');
        allBackdrops.forEach(backdrop => {
            backdrop.remove();
        });
        
        // Limpiar clases del body
        document.body.classList.remove('modal-open');
        
        // Limpiar estilos del body si existen
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        
        // Forzar limpieza de cualquier modal de Bootstrap activo
        if (window.bootstrap && window.bootstrap.Modal) {
            const activeModals = document.querySelectorAll('.modal.show');
            activeModals.forEach(modal => {
                const modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) {
                    modalInstance.dispose();
                }
            });
        }
        
        // Remover event listener de Escape
        document.removeEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeEmergencyModal();
            }
        });
        
        console.log('Modal de emergencia cerrado exitosamente');
        
        // Limpiar sesión móvil si existe
        if (window.derivacionesManager && window.derivacionesManager.cleanupMobileSession) {
            window.derivacionesManager.cleanupMobileSession();
        }
        
        // Limpieza adicional después de un delay
        setTimeout(() => {
            // Verificar si aún hay elementos residuales
            const remainingBackdrops = document.querySelectorAll('.modal-backdrop');
            if (remainingBackdrops.length > 0) {
                console.log('Removiendo backdrops residuales:', remainingBackdrops.length);
                remainingBackdrops.forEach(backdrop => backdrop.remove());
            }
            
            // Verificar si el body aún tiene la clase modal-open
            if (document.body.classList.contains('modal-open')) {
                console.log('Removiendo clase modal-open residual');
                document.body.classList.remove('modal-open');
            }
            
            // Limpiar estilos residuales del body
            if (document.body.style.overflow === 'hidden') {
                document.body.style.overflow = '';
            }
            if (document.body.style.paddingRight) {
                document.body.style.paddingRight = '';
            }
        }, 100);
        
    } catch (error) {
        console.error('Error cerrando modal de emergencia:', error);
    }
}

// Función global para eliminar fotos capturadas del modal de emergencia
function removeEmergencyCapturedImage(imageId) {
    try {
        console.log('Eliminando foto capturada:', imageId);
        
        // Intentar múltiples formas de acceder al manager
        let manager = null;
        
        // Método 1: window.derivacionesManager
        if (window.derivacionesManager) {
            manager = window.derivacionesManager;
            console.log('Manager encontrado en window.derivacionesManager');
        }
        
        // Método 2: Buscar en el DOM
        if (!manager) {
            const managerElement = document.querySelector('[data-manager-instance]');
            if (managerElement) {
                manager = managerElement.managerInstance;
                console.log('Manager encontrado en DOM');
            }
        }
        
        // Método 3: Buscar por clase global
        if (!manager && window.DerivacionesManager) {
            // Buscar instancia global
            for (let key in window) {
                if (window[key] && window[key].constructor && window[key].constructor.name === 'DerivacionesManager') {
                    manager = window[key];
                    console.log('Manager encontrado por clase global');
                    break;
                }
            }
        }
        
        if (manager && manager.emergencyCapturedImages) {
            // Encontrar y eliminar la imagen
            const index = manager.emergencyCapturedImages.findIndex(img => img.id === imageId);
            if (index !== -1) {
                manager.emergencyCapturedImages.splice(index, 1);
                manager.displayEmergencyCapturedImages();
                console.log('Foto eliminada exitosamente');
            } else {
                console.warn('Foto no encontrada:', imageId);
            }
        } else {
            console.error('Manager de derivaciones no disponible o sin emergencyCapturedImages');
            console.log('Manager disponible:', !!manager);
            console.log('emergencyCapturedImages disponible:', manager ? !!manager.emergencyCapturedImages : false);
        }
        
    } catch (error) {
        console.error('Error eliminando foto capturada:', error);
    }
}

// ============================================
// FUNCIONES DE PRIORIDADES
// ============================================

/**
 * Crea el botón de prioridad para un estudio
 */
DerivacionesManager.prototype.getPriorityButton = function(study, orthancId) {
    const orthancIdValue = orthancId || study.id;
    
    // Buscar prioridad usando orthanc_id
    let prioridad = this.studyPriorities[orthancIdValue];
    
    // Si no está en studyPriorities, buscar en el objeto study
    if (!prioridad) {
        prioridad = study.prioridad || 
                   (study.informes_incompletos && study.informes_incompletos.prioridad) || 
                   'normal';
    }
    
    // Si encontramos una prioridad, guardarla en el cache
    if (prioridad && prioridad !== 'normal' && orthancIdValue) {
        this.studyPriorities[orthancIdValue] = prioridad;
    }
    
    // Verificar si tiene informes incompletos
    // Buscar en múltiples lugares: objeto study, cache de flags, etc.
    let informesIncompletos = study.informes_incompletos || null;
    
    // Si no está en el objeto study, intentar buscarlo en el cache de flags
    if (!informesIncompletos) {
        // Buscar en studyPriorities o en un cache de flags si existe
        // Por ahora, confiamos en que loadStudyPriorities ya lo agregó al objeto study
    }
    
    // Debug: Log para verificar qué datos tenemos
    if (orthancIdValue && (study.informes_incompletos || informesIncompletos)) {
        console.log('🔍 [getPriorityButton] Estudio con informes incompletos:', {
            studyId: orthancIdValue,
            informesIncompletos: study.informes_incompletos,
            informesIncompletosVar: informesIncompletos,
            study: study
        });
    }
    
    const hasInformesIncompletos = informesIncompletos && (
        informesIncompletos.informes_incompletos === true || 
        informesIncompletos.informes_incompletos === 1 ||
        informesIncompletos === true ||
        informesIncompletos === 1
    );
    const notaIncompletos = informesIncompletos && (informesIncompletos.nota || (typeof informesIncompletos === 'object' && informesIncompletos.nota)) ? informesIncompletos.nota : null;
    
    let btnClass = 'btn-secondary';
    let iconClass = 'fas fa-flag';
    let title = 'Establecer Prioridad';
    let buttonText = 'Prioridad';
    let additionalClasses = '';
    let styleAttr = '';
    
    if (prioridad === 'urgente') {
        btnClass = 'btn-danger';
        iconClass = 'fas fa-exclamation-circle';
        title = 'Prioridad: Urgente';
        buttonText = 'Urgente';
    } else if (prioridad === 'promesa') {
        btnClass = 'btn-warning';
        iconClass = 'fas fa-flag';
        title = 'Prioridad: Promesa';
        buttonText = 'Promesa';
    } else if (prioridad === 'pendiente') {
        btnClass = 'btn-secondary';
        iconClass = 'fas fa-hourglass-half';
        title = 'Prioridad: Pendiente';
        buttonText = 'Pendiente';
        styleAttr = 'style="background-color:#6f42c1 !important;border-color:#6f42c1 !important;color:#fff !important;"';
    }
    
    // Si tiene informes incompletos, agregar información al título pero NO cambiar el texto del botón
    // El botón de prioridad muestra: Normal, Promesa, Pendiente o Urgente
    if (hasInformesIncompletos) {
        console.log('✅ [getPriorityButton] Estudio con informes incompletos para:', orthancIdValue, {
            informesIncompletos: informesIncompletos,
            hasInformesIncompletos: hasInformesIncompletos,
            prioridad: prioridad
        });
        
        // Agregar información de incompletos al título, pero mantener el texto del botón según la prioridad
        if (notaIncompletos) {
            title = `${title} - Informes incompletos: ${notaIncompletos}`;
        } else {
            title = `${title} - Informes incompletos`;
        }
        
        // Agregar clase para indicar que tiene informes incompletos (para estilos CSS si se necesita)
        // Pero NO cambiar el buttonText ni la prioridad mostrada
        additionalClasses = ' has-informes-incompletos';
    }
    
    // Agregar clase de color al icono según la prioridad
    // NOTA: Los informes incompletos NO afectan el color del icono de prioridad
    let iconColorClass = '';
    if (prioridad === 'urgente') {
        iconColorClass = 'text-white';
    } else if (prioridad === 'promesa') {
        iconColorClass = 'text-dark';
    } else if (prioridad === 'pendiente') {
        iconColorClass = 'text-white';
    }
    
    // Verificar si tiene permiso para asignar prioridad
    const hasPermission = this.canAsignarPrioridad || this.currentUserLevel === 'root';
    const disabledClass = hasPermission ? '' : 'disabled';
    const disabledAttr = hasPermission ? '' : 'disabled';
    const tooltipText = hasPermission ? title : `${title} - No tienes permiso para cambiar`;
    
    // Asegurar que los colores se mantengan incluso cuando está deshabilitado
    if (!hasPermission && prioridad !== 'normal') {
        if (prioridad === 'pendiente') {
            styleAttr = 'style="background-color:#6f42c1 !important;border-color:#6f42c1 !important;color:#fff !important;opacity:0.9 !important;pointer-events:none;cursor:not-allowed;"';
        } else {
            styleAttr = 'style="opacity: 1 !important; pointer-events: none; cursor: not-allowed;"';
        }
    } else if (!hasPermission) {
        styleAttr = 'style="opacity: 1 !important; pointer-events: none; cursor: not-allowed;"';
    }
    
    const safePatientNameJs = this.escapeJs(study.patient_name || '');
    
    // Debug: Log final del botón generado
    if (hasInformesIncompletos) {
        console.log('🔍 [getPriorityButton] Botón generado (prioridad: ' + prioridad + ', tiene incompletos):', {
            studyId: orthancIdValue,
            btnClass: btnClass,
            additionalClasses: additionalClasses,
            styleAttr: styleAttr,
            buttonText: buttonText,
            prioridad: prioridad
        });
    }
    
    return `
        <button class="btn btn-sm ${btnClass} priority-btn${additionalClasses} ${disabledClass}" 
                title="${this.escapeHtml(tooltipText)}" 
                onclick="${hasPermission ? `derivacionesManager.openPriorityModal('${orthancIdValue}', '${safePatientNameJs}')` : 'return false;'}"
                data-study-id="${orthancIdValue}"
                data-orthanc-id="${orthancIdValue}"
                ${disabledAttr}
                ${styleAttr}>
            <i class="${iconClass} ${iconColorClass}"></i>
            <span class="d-none d-sm-inline ms-1 ${iconColorClass}">${buttonText}</span>
        </button>
    `;
};

/**
 * Abre el modal para establecer la prioridad de un estudio
 */
DerivacionesManager.prototype.openPriorityModal = async function(studyId, patientName) {
    try {
        // Verificar permiso
        if (!this.canAsignarPrioridad && this.currentUserLevel !== 'root') {
            this.showError('No tienes permiso para asignar prioridad a estudios');
            return;
        }
        
        const orthancId = studyId;
        
        // Obtener prioridad actual del estudio desde el cache
        let currentPriority = this.studyPriorities[orthancId];
        
        // Si no está en el cache, buscar en los estudios cargados
        if (!currentPriority) {
            const study = this.studies.find(s => s.id === orthancId);
            if (study) {
                currentPriority = study.prioridad || 
                                (study.informes_incompletos && study.informes_incompletos.prioridad) || 
                                'normal';
            }
        }
        
        // Consultar la API para obtener la prioridad más actualizada
        const apiPriority = await this.getCurrentPriority(orthancId);
        if (apiPriority !== null && apiPriority !== undefined) {
            currentPriority = apiPriority;
        } else if (!currentPriority) {
            currentPriority = 'normal';
        }
        
        // Guardar en el cache
        this.studyPriorities[orthancId] = currentPriority;
        
        // Crear o obtener el modal
        let modalElement = document.getElementById('priorityModal');
        
        if (!modalElement) {
            modalElement = document.createElement('div');
            modalElement.id = 'priorityModal';
            modalElement.className = 'modal fade';
            modalElement.setAttribute('tabindex', '-1');
            modalElement.setAttribute('aria-labelledby', 'priorityModalLabel');
            modalElement.setAttribute('aria-hidden', 'true');
            modalElement.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="priorityModalLabel">
                                <i class="fas fa-flag me-2"></i>Establecer Prioridad del Estudio
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <p class="mb-2"><strong>Paciente:</strong> <span id="priorityModalPatientName">-</span></p>
                                <p class="mb-0"><strong>Estudio ID:</strong> <span id="priorityModalStudyId">-</span></p>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Seleccione la prioridad:</label>
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-outline-secondary priority-option" data-priority="normal">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <i class="fas fa-circle me-2" style="color: #6c757d;"></i>
                                                <strong>Normal</strong>
                                            </div>
                                            <small class="text-muted">Sin prioridad especial</small>
                                        </div>
                                    </button>
                                    <button type="button" class="btn btn-outline-warning priority-option" data-priority="promesa">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <i class="fas fa-circle me-2" style="color: #ffc107;"></i>
                                                <strong>Promesa</strong>
                                            </div>
                                            <small class="text-muted">Compromiso de entrega</small>
                                        </div>
                                    </button>
                                    <button type="button" class="btn priority-option" data-priority="pendiente" style="border:2px solid #6f42c1;color:#6f42c1;background:transparent;">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <i class="fas fa-hourglass-half me-2" style="color: #6f42c1;"></i>
                                                <strong>Pendiente</strong>
                                            </div>
                                            <small class="text-muted">Pendiente de informe (atención del informante)</small>
                                        </div>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger priority-option" data-priority="urgente">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <i class="fas fa-exclamation-circle me-2" style="color: #dc3545;"></i>
                                                <strong>Urgente</strong>
                                            </div>
                                            <small class="text-muted">Requiere atención inmediata</small>
                                        </div>
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" id="priorityModalStudyIdHidden" value="">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-primary" id="savePriorityBtn">
                                <i class="fas fa-save me-2"></i>Guardar Prioridad
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modalElement);
        }
        
        // Configurar contenido del modal
        const patientNameEl = modalElement.querySelector('#priorityModalPatientName');
        const studyIdEl = modalElement.querySelector('#priorityModalStudyId');
        const studyIdHiddenEl = modalElement.querySelector('#priorityModalStudyIdHidden');
        
        if (patientNameEl) patientNameEl.textContent = patientName || 'N/A';
        if (studyIdEl) studyIdEl.textContent = studyId;
        if (studyIdHiddenEl) studyIdHiddenEl.value = studyId;
        
        // Resetear selección
        const priorityOptions = modalElement.querySelectorAll('.priority-option');
        priorityOptions.forEach(btn => {
            btn.classList.remove('active');
            btn.classList.remove('btn-warning', 'btn-danger', 'btn-secondary');
            const priority = btn.dataset.priority;
            if (priority === 'normal') {
                btn.classList.add('btn-outline-secondary');
            } else if (priority === 'promesa') {
                btn.classList.add('btn-outline-warning');
            } else if (priority === 'urgente') {
                btn.classList.add('btn-outline-danger');
            } else if (priority === 'pendiente') {
                btn.style.border = '2px solid #6f42c1';
                btn.style.color = '#6f42c1';
                btn.style.background = 'transparent';
            }
        });
        const selectedPriority = currentPriority || 'normal';
        const currentBtn = modalElement.querySelector(`.priority-option[data-priority="${selectedPriority}"]`);
        
        if (currentBtn) {
            priorityOptions.forEach(btn => {
                btn.classList.remove('active');
                btn.classList.remove('btn-warning', 'btn-danger', 'btn-secondary');
                const priority = btn.dataset.priority;
                if (priority === 'normal') {
                    btn.classList.add('btn-outline-secondary');
                } else if (priority === 'promesa') {
                    btn.classList.add('btn-outline-warning');
                } else if (priority === 'urgente') {
                    btn.classList.add('btn-outline-danger');
                } else if (priority === 'pendiente') {
                    btn.style.border = '2px solid #6f42c1';
                    btn.style.color = '#6f42c1';
                    btn.style.background = 'transparent';
                }
            });
            
            currentBtn.classList.add('active');
            if (selectedPriority === 'promesa') {
                currentBtn.classList.remove('btn-outline-warning');
                currentBtn.classList.add('btn-warning');
            } else if (selectedPriority === 'urgente') {
                currentBtn.classList.remove('btn-outline-danger');
                currentBtn.classList.add('btn-danger');
            } else if (selectedPriority === 'pendiente') {
                currentBtn.style.background = '#6f42c1';
                currentBtn.style.color = '#fff';
                currentBtn.style.border = '2px solid #6f42c1';
            } else {
                currentBtn.classList.remove('btn-outline-secondary');
                currentBtn.classList.add('btn-secondary');
            }
        }
        
        // Configurar event listeners
        this.setupPriorityModalListeners(modalElement);
        
        // Guardar orthanc_id en el modal
        modalElement.setAttribute('data-save-orthanc-id', orthancId);
        
        // Configurar botón guardar
        const saveBtn = modalElement.querySelector('#savePriorityBtn');
        if (saveBtn) {
            const newSaveBtn = saveBtn.cloneNode(true);
            saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
            
            newSaveBtn.addEventListener('click', () => {
                const selectedBtn = modalElement.querySelector('.priority-option.active');
                if (selectedBtn) {
                    const priority = selectedBtn.dataset.priority;
                    const saveOrthancId = modalElement.getAttribute('data-save-orthanc-id');
                    this.setPriority(saveOrthancId, priority);
                }
            });
        }
        
        // Limpiar cualquier backdrop residual antes de mostrar el modal
        const existingBackdrops = document.querySelectorAll('.modal-backdrop');
        existingBackdrops.forEach(backdrop => {
            backdrop.remove();
        });
        
        // Verificar si hay una instancia previa y limpiarla
        const existingInstance = bootstrap.Modal.getInstance(modalElement);
        if (existingInstance) {
            existingInstance.dispose();
        }
        
        // Mostrar modal usando Bootstrap
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        modal.show();
        
        // Asegurar z-index correcto después de mostrar
        setTimeout(() => {
            // Asegurar que el modal esté por encima del backdrop
            modalElement.style.zIndex = '1055';
            modalElement.style.display = 'block';
            modalElement.style.pointerEvents = 'auto';
            
            const dialog = modalElement.querySelector('.modal-dialog');
            if (dialog) {
                dialog.style.zIndex = '1056';
                dialog.style.position = 'relative';
                dialog.style.pointerEvents = 'auto';
            }
            
            const content = modalElement.querySelector('.modal-content');
            if (content) {
                content.style.zIndex = '1057';
                content.style.position = 'relative';
                content.style.pointerEvents = 'auto';
            }
            
            // Asegurar que el backdrop esté por debajo y no bloquee
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.style.zIndex = '1054';
                backdrop.style.pointerEvents = 'auto';
            }
            
            // Forzar que el modal tenga la clase show
            modalElement.classList.add('show');
            modalElement.setAttribute('aria-hidden', 'false');
            
            // Asegurar que los botones sean clickeables
            const priorityOptions = modalElement.querySelectorAll('.priority-option');
            priorityOptions.forEach(btn => {
                btn.style.pointerEvents = 'auto';
                btn.style.cursor = 'pointer';
            });
            
            const saveBtn = modalElement.querySelector('#savePriorityBtn');
            if (saveBtn) {
                saveBtn.style.pointerEvents = 'auto';
                saveBtn.style.cursor = 'pointer';
            }
        }, 10);
        
        // Configurar evento para limpiar el modal cuando se cierre
        const cleanupHandler = () => {
            // Limpiar cualquier backdrop residual
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            // Remover clase modal-open del body si no hay otros modales abiertos
            if (document.querySelectorAll('.modal.show').length === 0) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        };
        
        // Remover listener anterior si existe
        modalElement.removeEventListener('hidden.bs.modal', cleanupHandler);
        // Agregar nuevo listener
        modalElement.addEventListener('hidden.bs.modal', cleanupHandler);
        
    } catch (error) {
        console.error('Error abriendo modal de prioridad:', error);
        alert('Error al abrir el modal de prioridad');
    }
};

/**
 * Configura los event listeners del modal de prioridad
 */
DerivacionesManager.prototype.setupPriorityModalListeners = function(modalElement) {
    if (!modalElement) {
        modalElement = document.getElementById('priorityModal');
    }
    
    if (!modalElement) {
        console.error('Modal de prioridad no encontrado para configurar listeners');
        return;
    }
    
    // Remover listeners anteriores clonando los botones
    const priorityOptions = modalElement.querySelectorAll('.priority-option');
    priorityOptions.forEach(btn => {
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
    });
    
    // Agregar listeners a los botones de prioridad
    modalElement.querySelectorAll('.priority-option').forEach(btn => {
        btn.addEventListener('click', () => {
            // Remover selección anterior
            modalElement.querySelectorAll('.priority-option').forEach(b => {
                b.classList.remove('active');
                b.classList.remove('btn-warning', 'btn-danger', 'btn-secondary');
                const priority = b.dataset.priority;
                if (priority === 'normal') {
                    b.classList.add('btn-outline-secondary');
                } else if (priority === 'promesa') {
                    b.classList.add('btn-outline-warning');
                } else if (priority === 'urgente') {
                    b.classList.add('btn-outline-danger');
                } else if (priority === 'pendiente') {
                    b.style.border = '2px solid #6f42c1';
                    b.style.color = '#6f42c1';
                    b.style.background = 'transparent';
                }
            });
            
            // Marcar como seleccionado
            btn.classList.add('active');
            const priority = btn.dataset.priority;
            if (priority === 'promesa') {
                btn.classList.remove('btn-outline-warning');
                btn.classList.add('btn-warning');
            } else if (priority === 'urgente') {
                btn.classList.remove('btn-outline-danger');
                btn.classList.add('btn-danger');
            } else if (priority === 'pendiente') {
                btn.style.background = '#6f42c1';
                btn.style.color = '#fff';
                btn.style.border = '2px solid #6f42c1';
            } else {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-secondary');
            }
            
            // Habilitar botón guardar
            const saveBtn = modalElement.querySelector('#savePriorityBtn');
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        });
    });
};

/**
 * Obtiene la prioridad actual de un estudio
 */
DerivacionesManager.prototype.getCurrentPriority = async function(studyId) {
    try {
        const response = await fetch(`api/study-flags.php?study_id=${encodeURIComponent(studyId)}`);
        const result = await response.json();
        
        if (result.success && result.data && result.data.prioridad) {
            return result.data.prioridad;
        }
        
        return 'normal';
    } catch (error) {
        console.error('Error obteniendo prioridad actual:', error);
        return 'normal';
    }
};

/**
 * Establece la prioridad de un estudio
 */
DerivacionesManager.prototype.setPriority = async function(orthancId, prioridad) {
    try {
        // Buscar el estudio para obtener ambos IDs
        const study = this.studies.find(s => s.id === orthancId);
        
        // Normalizar IDs
        const studyInstanceUID = study ? (study.study_instance_uid || null) : null;
        
        const payload = {
            study_id: orthancId,
            orthanc_id: orthancId,
            prioridad: prioridad
        };
        
        if (studyInstanceUID) {
            payload.study_instance_uid = studyInstanceUID;
        }
        
        const response = await fetch('api/study-flags.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Cerrar modal
            const modalElement = document.getElementById('priorityModal');
            if (modalElement) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
            }
            
            // Mostrar mensaje de éxito
            this.showSuccess(`Prioridad establecida como: ${prioridad.charAt(0).toUpperCase() + prioridad.slice(1)}`);
            
            // Actualizar en el objeto study si existe
            const study = this.studies.find(s => s.id === orthancId);
            if (study) {
                study.prioridad = prioridad;
                if (prioridad === 'urgente' || prioridad === 'promesa' || prioridad === 'pendiente') {
                    study.is_urgent = true;
                } else {
                    study.is_urgent = false;
                }
                
                // Actualizar contador de urgentes en el header
                this.updateUrgentCounter();
            }
            
            // Actualizar badge visual en la fila
            this.updatePriorityBadge(orthancId, prioridad);
            
            // Recargar prioridades para asegurar sincronización
            await this.loadStudyPriorities();
            
            // Actualizar contador de urgentes en el header
            await this.updateUrgentCounter();
            
            // Guardar estado persistente
            this.savePersistentState();

            const studyForNotify = this.studies.find(s => s.id === orthancId);
            this.notifyStudyPriorityChanged(orthancId, prioridad, studyForNotify);
            
        } else {
            throw new Error(result.message || 'Error al establecer prioridad');
        }
    } catch (error) {
        console.error('Error estableciendo prioridad:', error);
        this.showError('Error al establecer prioridad: ' + error.message);
    }
};

/**
 * Notifica a otras pestañas/iframes/usuarios en la misma app que cambió la prioridad.
 */
DerivacionesManager.prototype.notifyStudyPriorityChanged = function(orthancId, prioridad, study) {
    const detail = {
        study_id: orthancId,
        orthanc_id: orthancId,
        study_instance_uid: (study && study.study_instance_uid) || '',
        prioridad: prioridad || 'normal',
        source: 'estudios-manager',
        ts: Date.now()
    };
    try {
        window.dispatchEvent(new CustomEvent('studyPriorityChanged', { detail }));
    } catch (e) { /* ignore */ }
    if (window.parent && window.parent !== window) {
        try {
            window.parent.dispatchEvent(new CustomEvent('studyPriorityChanged', { detail }));
        } catch (e) { /* ignore */ }
        try {
            window.parent.postMessage({ type: 'studyPriorityChanged', detail }, '*');
        } catch (e) { /* ignore */ }
    }
    try {
        localStorage.setItem('study_priority_changed_event', JSON.stringify(detail));
    } catch (e) {
        console.warn('No se pudo persistir study_priority_changed_event:', e);
    }
};

DerivacionesManager.prototype.matchesStudyPriorityDetail = function(study, detail) {
    if (!study || !detail) return false;
    return (
        (detail.study_id && (study.id === detail.study_id || study.orthanc_study_id === detail.study_id)) ||
        (detail.orthanc_id && (study.id === detail.orthanc_id || study.orthanc_study_id === detail.orthanc_id)) ||
        (detail.study_instance_uid && study.study_instance_uid === detail.study_instance_uid)
    );
};

/**
 * Aplica un cambio de prioridad recibido por evento (otra sesión o pestaña).
 */
DerivacionesManager.prototype.applyPriorityChangeFromDetail = async function(detail) {
    if (!detail || typeof detail !== 'object') return;

    const prioridad = detail.prioridad || 'normal';
    let touched = false;

    if (Array.isArray(this.studies)) {
        this.studies.filter(s => this.matchesStudyPriorityDetail(s, detail)).forEach(s => {
            s.prioridad = prioridad;
            s.is_urgent = emPrioridadAlta(prioridad);
            const id = s.id || detail.orthanc_id || detail.study_id;
            if (id) {
                this.studyPriorities[id] = prioridad;
                this.updatePriorityBadge(id, prioridad);
            }
            touched = true;
        });
    }

    if (touched) {
        this.studies = this.sortStudiesByPriority([...this.studies]);
        try {
            this.applyLocalFilters();
        } catch (e) {
            console.warn('Error aplicando filtros tras cambio de prioridad:', e);
        }
        await this.updateUrgentCounter();
    }
};

/**
 * Escucha cambios de prioridad (mismo patrón que informeFinalizado).
 */
DerivacionesManager.prototype.setupQaStatusChangeListener = function() {
    if (this._qaStatusListenersBound) return;
    this._qaStatusListenersBound = true;

    document.addEventListener('qa-status-changed', () => {
        if (this.isDataLoaded) {
            this.renderStudies();
        }
    });
};

DerivacionesManager.prototype.setupStudyStatusChangeListener = function() {
    if (this._studyStatusListenersBound) return;
    this._studyStatusListenersBound = true;

    const apply = (detail) => {
        this.applyPriorityChangeFromDetail(detail)
            .then(() => this.refreshPriorityStudiesGrid())
            .catch(err => {
                console.warn('Error aplicando cambio de prioridad remoto:', err);
            });
    };

    window.addEventListener('studyPriorityChanged', (e) => {
        try { apply(e && e.detail); } catch (err) { console.warn(err); }
    });

    window.addEventListener('message', (e) => {
        try {
            if (e && e.data && e.data.type === 'studyPriorityChanged') {
                apply(e.data.detail);
            }
        } catch (err) { console.warn(err); }
    });

    window.addEventListener('storage', (e) => {
        try {
            if (e && e.key === 'study_priority_changed_event' && e.newValue) {
                apply(JSON.parse(e.newValue));
            }
        } catch (err) { console.warn(err); }
    });
};

/**
 * Recarga prioridades de la grilla visible y repinta si hubo cambios.
 */
DerivacionesManager.prototype.refreshVisiblePriorities = async function() {
    if (!this.studies || this.studies.length === 0) {
        return;
    }

    const before = { ...this.studyPriorities };
    await this.loadStudyPriorities();

    let changed = false;
    const ids = new Set([...Object.keys(before), ...Object.keys(this.studyPriorities)]);
    ids.forEach((id) => {
        const prev = before[id] || 'normal';
        const next = this.studyPriorities[id] || 'normal';
        if (prev !== next) {
            changed = true;
            this.updatePriorityBadge(id, next);
        }
    });

    if (changed) {
        this.studies = this.sortStudiesByPriority([...this.studies]);
        try {
            this.applyLocalFilters();
        } catch (e) {
            console.warn('Error aplicando filtros tras refresh de prioridades:', e);
        }
        await this.updateUrgentCounter();
    }
};

/**
 * Recarga estudios con prioridad alta desde la API y los fusiona en la grilla (polling / eventos).
 */
DerivacionesManager.prototype.refreshPriorityStudiesGrid = async function() {
    try {
        const urgentStudies = await this.loadUrgentStudies();
        const normalStudies = (this.studies || []).filter(study => {
            const p = this.studyPriorities[study.id] || study.prioridad || 'normal';
            const isIncomplete = study.is_incomplete ||
                (study.informes_incompletos && study.informes_incompletos.informes_incompletos);
            return !emPrioridadAlta(p) && !isIncomplete;
        });
        const mergedCount = this.studies ? this.studies.length : 0;
        this.studies = this.mergeStudiesWithPriorities(urgentStudies, normalStudies);
        await this.loadStudyPriorities();
        this.studies = this.sortStudiesByPriority([...this.studies]);
        if (this.isDataLoaded) {
            try {
                this.applyLocalFilters();
            } catch (e) {
                console.warn('Error aplicando filtros tras refresh de estudios prioritarios:', e);
            }
        }
        await this.updateUrgentCounter();
        if (this.studies.length !== mergedCount || urgentStudies.length > 0) {
            console.log(`✅ Grilla prioritarios actualizada: ${urgentStudies.length} alta prioridad, ${this.studies.length} total`);
        }
    } catch (error) {
        console.error('Error refrescando estudios prioritarios en grilla:', error);
    }
};

DerivacionesManager.prototype.startPriorityAutoRefresh = function() {
    if (this.priorityRefreshInterval) {
        clearInterval(this.priorityRefreshInterval);
    }
    this.priorityRefreshInterval = setInterval(() => {
        this.refreshPriorityStudiesGrid().catch(err => {
            console.error('Error en actualización automática de prioridades:', err);
        });
    }, 30000);
};

DerivacionesManager.prototype.stopPriorityAutoRefresh = function() {
    if (this.priorityRefreshInterval) {
        clearInterval(this.priorityRefreshInterval);
        this.priorityRefreshInterval = null;
    }
};

/**
 * Actualiza el badge visual de prioridad en la fila del estudio
 */
DerivacionesManager.prototype.updatePriorityBadge = function(orthancId, prioridad) {
    // Actualizar en el objeto de prioridades
    this.studyPriorities[orthancId] = prioridad;
    
    // Buscar la fila
    const row = document.querySelector(`tr[data-study-id="${orthancId}"]`) ||
                document.querySelector(`tr[data-orthanc-id="${orthancId}"]`);
    
    if (row) {
        // Buscar botón de prioridad
        const priorityBtn = row.querySelector('.priority-btn');
        if (priorityBtn) {
            // Actualizar clases y icono según prioridad
            priorityBtn.classList.remove('btn-secondary', 'btn-warning', 'btn-danger');
            priorityBtn.removeAttribute('style');

            const icon = priorityBtn.querySelector('i');
            const textSpan = priorityBtn.querySelector('span.d-none.d-sm-inline');

            if (icon) {
                if (prioridad === 'urgente') {
                    icon.className = 'fas fa-exclamation-circle text-white';
                    priorityBtn.classList.add('btn-danger');
                    priorityBtn.setAttribute('title', 'Prioridad: Urgente');
                } else if (prioridad === 'promesa') {
                    icon.className = 'fas fa-flag text-dark';
                    priorityBtn.classList.add('btn-warning');
                    priorityBtn.setAttribute('title', 'Prioridad: Promesa');
                } else if (prioridad === 'pendiente') {
                    icon.className = 'fas fa-hourglass-half text-white';
                    priorityBtn.classList.add('btn-secondary');
                    priorityBtn.style.setProperty('background-color', '#6f42c1', 'important');
                    priorityBtn.style.setProperty('border-color', '#6f42c1', 'important');
                    priorityBtn.style.setProperty('color', '#fff', 'important');
                    priorityBtn.setAttribute('title', 'Prioridad: Pendiente');
                } else {
                    icon.className = 'fas fa-flag';
                    priorityBtn.classList.add('btn-secondary');
                    priorityBtn.setAttribute('title', 'Establecer Prioridad');
                }
            }

            if (textSpan) {
                if (prioridad === 'urgente') {
                    textSpan.textContent = 'Urgente';
                    textSpan.className = 'd-none d-sm-inline ms-1 text-white';
                } else if (prioridad === 'promesa') {
                    textSpan.textContent = 'Promesa';
                    textSpan.className = 'd-none d-sm-inline ms-1 text-dark';
                } else if (prioridad === 'pendiente') {
                    textSpan.textContent = 'Pendiente';
                    textSpan.className = 'd-none d-sm-inline ms-1 text-white';
                } else {
                    textSpan.textContent = 'Prioridad';
                    textSpan.className = 'd-none d-sm-inline ms-1';
                }
            }
        }
    }
};

/**
 * Verifica si hay estudios urgentes/prometidos disponibles
 */
DerivacionesManager.prototype.checkForUrgentStudies = async function() {
    try {
        const response = await fetch(`${this.apiBaseUrl}get_urgent_studies.php`);
        const result = await response.json();
        return result.success && result.data && result.data.urgent_study_ids && result.data.urgent_study_ids.length > 0;
    } catch (error) {
        console.error('Error verificando estudios urgentes:', error);
        return false;
    }
};
    
/**
 * Carga estudios urgentes/prometidos desde study_flags
 * Para PACS Query: Consulta Orthanc directamente por ID
 * Para usuarios sin PACS Query: Obtiene datos desde study_assignments
 */
DerivacionesManager.prototype.loadUrgentStudies = async function() {
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
        if (this.hasPacsQuery) {
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
        } else {
            // Usuario SIN PACS Query: obtener datos desde study_assignments
            console.log('📦 Usuario sin PACS Query: obteniendo estudios desde study_assignments');
            
            const assignedResponse = await fetch(`${this.apiBaseUrl}get_user_assigned_studies_fixed.php`);
            const assignedResult = await assignedResponse.json();
            
            if (!assignedResult.success) {
                throw new Error(assignedResult.error || 'Error obteniendo estudios asignados');
            }
            
            const assignedStudies = assignedResult.data.studies || [];
            
            // Filtrar solo los estudios que están en la lista de urgentes
            urgentStudies = assignedStudies.filter(study => {
                return urgentStudyIds.some(flag => 
                    flag.orthanc_id === study.id || 
                    flag.study_instance_uid === study.study_instance_uid ||
                    flag.orthanc_id === study.orthanc_study_id
                );
            });
            
            console.log(`📦 Estudios urgentes encontrados en asignados: ${urgentStudies.length}`);
        }
        
        // 3. Agregar información de prioridad e informes incompletos y marcarlos como urgentes
        urgentStudies.forEach(study => {
            // Buscar el flag correspondiente
            const flag = urgentStudyIds.find(f => 
                f.orthanc_id === study.id || 
                f.study_instance_uid === study.study_instance_uid ||
                f.orthanc_id === study.orthanc_study_id
            );
            
            if (flag) {
                study.prioridad = flag.prioridad;
                study.is_urgent = true;
                // Guardar en cache de prioridades
                this.studyPriorities[study.id] = flag.prioridad;
                
                // Agregar información de informes incompletos si existe
                // Nota: Los flags de get_urgent_studies.php pueden incluir informes_incompletos
                // pero necesitamos verificar si el flag tiene esa información
                // Por ahora, la información de informes_incompletos se cargará en loadStudyPriorities
            } else {
                // Si no se encuentra el flag, usar la prioridad que ya viene del estudio
                study.is_urgent = emPrioridadAlta(study.prioridad);
            }
        });
        
        // Orden: urgente > promesa > pendiente
        const rankP = (p) => {
            if (p === 'urgente') return 3;
            if (p === 'promesa') return 2;
            if (p === 'pendiente') return 1;
            return 0;
        };
        urgentStudies.sort((a, b) => rankP(b.prioridad) - rankP(a.prioridad));
        
        console.log(`✅ Estudios urgentes/prometidos cargados: ${urgentStudies.length}`);
        return urgentStudies;
        
    } catch (error) {
        console.error('Error cargando estudios urgentes:', error);
        // En caso de error, retornar array vacío para no bloquear la carga normal
        return [];
    }
};

/**
 * Ordena estudios por prioridad: Urgentes primero, luego Promesas, luego Normales
 * NOTA: Los informes incompletos NO son una prioridad, solo se usan para filtros visuales
 */
DerivacionesManager.prototype.sortStudiesByPriority = function(studies) {
    const priorityOrder = { 'urgente': 4, 'promesa': 3, 'pendiente': 2, 'normal': 1 };
    
    return studies.sort((a, b) => {
        // Determinar el grupo de prioridad de cada estudio
        const getPriorityGroup = (study) => {
            let prioridad = this.studyPriorities[study.id];
            
            // Si no está en el cache, buscar en el objeto study
            if (!prioridad) {
                prioridad = study.prioridad || 
                           (study.informes_incompletos && study.informes_incompletos.prioridad);
            }
            
            // Si aún no tenemos prioridad, asumir 'normal'
            if (!prioridad || prioridad === '') {
                prioridad = 'normal';
            }
            
            // Validar que la prioridad sea uno de los valores permitidos
            if (prioridad !== 'urgente' && prioridad !== 'promesa' && prioridad !== 'pendiente' && prioridad !== 'normal') {
                prioridad = 'normal';
            }
            
            return prioridad;
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
};

/**
 * Combina estudios urgentes y normales sin duplicados
 * Los urgentes van primero
 */
DerivacionesManager.prototype.fillSparseStudyMetadata = function(target, source) {
        if (!target || !source) {
            return target;
        }
        const fields = [
            'patient_name', 'patient_id', 'study_description', 'modality',
            'study_date', 'date', 'study_time', 'time', 'institution_name',
            'accession_number', 'referring_physician', 'patient_birth_date', 'patient_sex'
        ];
        fields.forEach((field) => {
            const current = String(target[field] ?? '').trim();
            const incoming = String(source[field] ?? '').trim();
            if (current === '' && incoming !== '') {
                target[field] = source[field];
            }
        });
        return target;
    };

DerivacionesManager.prototype.mergeStudiesWithPriorities = function(urgentStudies, normalStudies) {
        const merged = [];
        const indexById = new Map();
        const seenIds = new Set();

        const registerStudy = (study) => {
            const key = study && study.id != null ? String(study.id) : '';
            if (!key) {
                return;
            }
            if (indexById.has(key)) {
                this.fillSparseStudyMetadata(indexById.get(key), study);
            } else {
                merged.push(study);
                indexById.set(key, study);
            }
            seenIds.add(key);
            if (study.study_instance_uid) {
                seenIds.add(String(study.study_instance_uid));
            }
        };

        // Primero: urgentes/prometidos (prioridad en el listado)
        urgentStudies.forEach((study) => registerStudy(study));

        // Segundo: normales; si ya existe el id, completar metadata faltante (p. ej. patient_name)
        normalStudies.forEach((study) => {
            const key = study && study.id != null ? String(study.id) : '';
            if (!key) {
                return;
            }
            if (seenIds.has(key) || (study.study_instance_uid && seenIds.has(String(study.study_instance_uid)))) {
                const existing = indexById.get(key);
                if (existing) {
                    this.fillSparseStudyMetadata(existing, study);
                }
                return;
            }
            registerStudy(study);
        });

        return merged;
    }
    
    /**
     * Carga las prioridades de todos los estudios cargados
     */
    DerivacionesManager.prototype.loadStudyPriorities = async function() {
    if (!this.studies || this.studies.length === 0) {
        return;
    }
    
    try {
        // Obtener todos los orthanc_ids únicos
        const orthancIds = new Set();
        this.studies.forEach(study => {
            const orthancId = study.id;
            if (orthancId) {
                orthancIds.add(orthancId);
            }
        });
        
        const orthancIdsArray = Array.from(orthancIds);
        
        if (orthancIdsArray.length === 0) {
            return;
        }
        
        // Cargar flags (prioridades e informes incompletos) en batch
        // Usar POST si hay muchos IDs para evitar URLs demasiado largas
        let response;
        if (orthancIdsArray.length > 50) {
            // Usar POST para evitar límites de longitud de URL
            response = await fetch('api/study-flags/batch.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({ study_ids: orthancIdsArray })
            });
        } else {
            // Usar GET para pocos IDs
            response = await fetch(`api/study-flags/batch.php?study_ids=${encodeURIComponent(orthancIdsArray.join(','))}`, {
                credentials: 'include'
            });
        }
        const result = await response.json();
        
        console.log('📋 [loadStudyPriorities] Flags cargados:', result.success ? Object.keys(result.data || {}).length : 0, 'flags');
        
        if (result.success && result.data) {
            let incompleteCount = 0;
            Object.keys(result.data).forEach(orthancId => {
                const flag = result.data[orthancId];
                
                // Cargar prioridad (incluir todas las prioridades, no solo las no normales)
                if (flag && flag.prioridad) {
                    this.studyPriorities[orthancId] = flag.prioridad;
                    
                    // También actualizar la prioridad directamente en el objeto study
                    const studyIndex = this.studies.findIndex(s => 
                        s.id === orthancId || 
                        s.orthanc_study_id === orthancId ||
                        s.study_id === orthancId ||
                        s.study_instance_uid === orthancId
                    );
                    
                    if (studyIndex !== -1) {
                        this.studies[studyIndex].prioridad = flag.prioridad;
                    }
                }
                
                // Cargar información de informes incompletos y agregarla al estudio
                const isIncomplete = flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1);
                if (isIncomplete) {
                    incompleteCount++;
                    console.log('🔍 [loadStudyPriorities] Estudio incompleto encontrado:', orthancId, flag);
                    
                    // Buscar el estudio en el array usando múltiples identificadores
                    const studyIndex = this.studies.findIndex(s => 
                        s.id === orthancId || 
                        s.orthanc_study_id === orthancId ||
                        s.study_id === orthancId ||
                        s.study_instance_uid === orthancId
                    );
                    
                    if (studyIndex !== -1) {
                        this.studies[studyIndex].informes_incompletos = {
                            informes_incompletos: true,
                            nota: flag.nota || null,
                            prioridad: flag.prioridad || 'normal'
                        };
                        console.log('✅ [loadStudyPriorities] Información de incompletos agregada a estudio:', orthancId);
                    } else {
                        console.warn('⚠️ [loadStudyPriorities] Estudio no encontrado en array:', orthancId);
                    }
                    
                    // También actualizar en filteredStudies si existe
                    const filteredIndex = this.filteredStudies.findIndex(s => 
                        s.id === orthancId || 
                        s.orthanc_study_id === orthancId ||
                        s.study_id === orthancId ||
                        s.study_instance_uid === orthancId
                    );
                    if (filteredIndex !== -1) {
                        this.filteredStudies[filteredIndex].informes_incompletos = {
                            informes_incompletos: true,
                            nota: flag.nota || null,
                            prioridad: flag.prioridad || 'normal'
                        };
                        console.log('✅ [loadStudyPriorities] Información de incompletos agregada a filteredStudy:', orthancId);
                    }
                }
            });
            console.log(`📋 [loadStudyPriorities] Total estudios con informes incompletos: ${incompleteCount}`);
            
            // Reordenar estudios después de cargar las prioridades
            // Esto asegura que los estudios con prioridad estén al principio
            this.studies = this.sortStudiesByPriority([...this.studies]);
            
            // Si hay filteredStudies, también reordenarlos
            if (this.filteredStudies && this.filteredStudies.length > 0) {
                this.filteredStudies = this.sortStudiesByPriority([...this.filteredStudies]);
            }
        }
    } catch (error) {
        console.error('Error cargando prioridades:', error);
    }
};
