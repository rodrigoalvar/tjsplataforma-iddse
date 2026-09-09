const INFORMES_BASE_PREFIX = 'http://localhost:80';

const InformesManager = {
    // Configuración del módulo
    config: {
        apiBaseUrl: window.location.pathname.includes('/components/') ? '../api/informes' : 'api/informes',
        authMiddleware: true,
        attachStudiesSessionKey: 'tjsiddse_informes_attach_studies_v1',
        attachStudyMaxRangeDays: 120
    },

    // Estado interno
    state: {
        currentPage: 1,
        totalPages: 1,
        perPage: 25,
        totalResults: 0,
        totalResultsWithoutFilters: 0, // Total de informes sin filtros aplicados
        currentFilters: {},
        isInitialLoad: true, // Flag para saber si es la primera carga
        sortBy: null, // Columna por la que se ordena
        sortOrder: 'desc', // Orden: 'asc' o 'desc'
        isLoading: false,
        tinymceEditor: null,
        selectedReport: null,
        audioPlayer: null,
        searchTimeout: null, // Para debounce de búsqueda
        hasUnsavedChanges: false, // Para controlar advertencias del navegador
        originalReportData: null, // Para comparar cambios
        reports: [], // Array de informes cargados
        currentAudios: [], // Array de audios del modal actual
        currentAudioFile: null, // Archivo de audio actual
        currentAudioIndex: null, // Índice del audio actual
        isAudioPlaying: false, // Estado de reproducción
        canViewAll: false, // Permiso para ver todos los informes
        canSendToPacs: false, // Permiso para enviar informes a PACS
        /** Permiso interfaz gui_toggle_formato_pacs: ver y cambiar el toggle PDF/IMG */
        canTogglePacsFormat: false,
        canCreateReports: false, // Permiso para crear informes
        canMarcarIncompletos: false, // Permiso para marcar informes como incompletos
        canDownloadInformeAudios: false, // descargar_audios_informe o all (descarga MP3 desde la grilla)
        canAttachReports: false, // adjuntar_informe_estudio, adjuntarInformes o all (no inferir desde gestionInformes)
        canViewInformesRecibidos: false, // informes_recibidos o all (no inferir desde gestionInformes)
        canViewInformesCarpeta: false, // informes_carpeta o informes_recibidos o all
        canDatosCobranza: false,
        cobranzaColumnsInstalled: true,
        currentUserId: null,
        attachSelectedStudy: null,
        attachStudySearchResults: [],
        attachStudiesAllResults: [],
        attachStudiesListSource: null,
        attachStudySortConfig: { column: null, direction: 'asc' },
        attachStudyModalityFilters: [],
        attachStudyFilterDebounce: null,
        studyFlags: {}, // Mapa de study_id -> flag para filtrado de informes incompletos
        currentUserName: '', // Nombre del usuario actual
        currentUserLastName: '', // Apellido del usuario actual
        autoSaveTimeout: null, // Timeout para autosave con debounce
        autoSaveInterval: null, // Intervalo periódico de autosave (no usado actualmente, solo para cleanup)
        pendingModalClose: false, // Flag para indicar que hay un cierre de modal pendiente después de confirmación
        allowModalClose: false, // Flag para permitir cierre libre del modal (después de guardar)
        markModifiedTimeout: null, // Timeout para debounce de verificación de cambios reales
        warningModalShowing: false, // Flag para prevenir múltiples modales de advertencia simultáneos
        eventListenersSetup: false, // Flag para evitar configurar event listeners múltiples veces
        perPageListenerAdded: false, // Flag para evitar agregar múltiples listeners del selector perPage
        perPageUserSet: false, // Flag para indicar si el usuario cambió explícitamente el perPage
        removingFromPacs: new Set(), // Set de IDs de informes que se están eliminando de PACS
        pacsVerificationPending: new Set(), // IDs de informes que necesitan verificación persistente (sobrevive a recargas)
        pacsVerificationInterval: null, // Intervalo de verificación periódica
        
        pacsConfirmedRemoved: new Set(), // Set de IDs de informes confirmados como eliminados de PACS (caché local)
        
        sendingToPacs: new Set(), // Set de IDs de informes que se están enviando a PACS
        pacsSendingPending: new Set(), // IDs de informes que necesitan verificación de envío (sobrevive a recargas)
        pacsSendingInterval: null, // Intervalo de verificación periódica para envíos
        txStatusPending: new Set(), // Informes con transcripción pendiente/colgada
        txStatusInterval: null, // Polling de estado TX
        requeuingAudioIds: new Set(), // Audios con reintento TX en vuelo (anti doble-clic)
        requeueCooldownUntil: {}, // audioId -> timestamp ms hasta el cual el botón sigue bloqueado
        requeueCooldownTimers: {}, // audioId -> timeoutId para reactivar botón
        cobranzaPlanillaDirty: false, // Cambios sin guardar en el modal Planilla de Códigos
        /** id (string) -> { id, nombre, apellido, rol } para el desplegable de filtro por autor */
        informeUsuarioFilterById: {},
        informesQuickDateButtonsBound: false
    },

    /**
     * URL del PDF para iframe o ventana (misma regla que antes con window.open).
     */
    resolvePdfViewerUrl(path) {
        const raw = (path || '').trim();
        if (!raw) return '';
        if (/^https?:\/\//i.test(raw)) return raw;
        const prefix = window.location.pathname.includes('/components/') ? '../' : '';
        return prefix + raw;
    },

    /**
     * Muestra el PDF en un modal con iframe (por encima del modal del informe).
     */
    openPdfViewerModal(path, title) {
        const raw = (path || '').trim();
        if (!raw) {
            this.showToast('Ruta de PDF inválida', 'warning');
            return;
        }
        const url = this.resolvePdfViewerUrl(raw);
        const modalEl = document.getElementById('informePdfViewerModal');
        const frame = document.getElementById('informePdfViewerFrame');
        const titleEl = document.getElementById('informePdfViewerTitle');
        if (!modalEl || !frame || typeof bootstrap === 'undefined') {
            window.open(url, '_blank');
            return;
        }
        if (titleEl) {
            const t = (title || '').trim();
            titleEl.textContent = t || 'PDF del informe';
        }
        frame.setAttribute('src', url);
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: true, keyboard: true });
        const onHidden = () => {
            frame.removeAttribute('src');
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
        };
        modalEl.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    },

    /**
     * En modo solo lectura TinyMCE no entrega clics al handler del editor; delegamos en el documento del iframe (fase captura).
     */
    bindTinyMcePdfViewerClicks(editor) {
        if (!editor || typeof editor.getDoc !== 'function') return;
        const doc = editor.getDoc();
        if (!doc || !doc.documentElement) return;
        if (doc.documentElement.dataset.informesPdfCapture === '1') return;
        doc.documentElement.dataset.informesPdfCapture = '1';
        doc.addEventListener('click', (ev) => {
            let el = ev.target;
            if (el && el.nodeType !== 1) el = el.parentElement;
            if (!el || typeof el.closest !== 'function') return;
            const pvBtn = el.closest('.pdf-viewer-btn');
            if (!pvBtn) return;
            ev.preventDefault();
            ev.stopPropagation();
            const pdfPath = pvBtn.getAttribute('data-pdf-path');
            const pdfTitle = pvBtn.getAttribute('data-pdf-title') || '';
            if (pdfPath) {
                this.openPdfViewerModal(pdfPath, pdfTitle);
            }
        }, true);
    },

    /**
     * Informe efectivamente en PACS: requiere serie o instancia DICOM (DOC).
     * pacs_study_id solo no indica que el PDF exista en el estudio (p. ej. tras migración UID).
     */
    informeHasPacsInOrthanc(informe) {
        if (!informe) return false;
        return (informe.pacs_series_id && String(informe.pacs_series_id).trim() !== '') ||
               (informe.pacs_instance_id && String(informe.pacs_instance_id).trim() !== '');
    },

    /**
     * Inicializar informes
     */
    init: async function() {
        try {
            this.setSearchBtnLoading(true);
            
            // Cargar perPage desde localStorage si existe
            this.loadPerPageFromStorage();
            
            // Aplicar filtro por defecto de los últimos 7 días en la primera carga
            if (this.state.isInitialLoad) {
                this.applyDefaultDateFilter();
            }
            
            // Cargar informes pendientes de verificación PACS
            this.loadPendingPacsVerification();
            
            // Si hay informes pendientes, iniciar polling
            if (this.state.pacsVerificationPending.size > 0) {
                console.log('🔄 [INIT] Hay', this.state.pacsVerificationPending.size, 'informes pendientes de verificación PACS al iniciar');
                console.log('📋 [INIT] IDs pendientes:', Array.from(this.state.pacsVerificationPending));
                this.startPacsVerificationPolling();
            } else {
                console.log('ℹ️ [INIT] No hay informes pendientes de verificación PACS al iniciar');
            }

            // Configurar event listeners para botones del modal
            this.setupEventListeners();

            // Badge estado Whisper (OK / Degradado / Caído)
            this.refreshTranscriptionHealthBadge();
            if (!this._txHealthBadgeTimer) {
                this._txHealthBadgeTimer = setInterval(() => {
                    this.refreshTranscriptionHealthBadge();
                }, 60000);
            }

            // Si hay middleware de auth activo, usar token si está disponible
            const token = this.getSessionToken();
            const fetchListWithRetry = async (url, options, maxRetries = 1) => {
                let lastResponse = null;
                for (let attempt = 0; attempt <= maxRetries; attempt++) {
                    const requestUrl = attempt === 0
                        ? url
                        : `${url}${url.includes('?') ? '&' : '?'}_rt=${Date.now()}`;
                    const response = await fetch(requestUrl, options);
                    lastResponse = response;
                    if (response.status !== 502) {
                        return response;
                    }
                    if (attempt < maxRetries) {
                        await new Promise(resolve => setTimeout(resolve, 350));
                    }
                }
                return lastResponse;
            };

            // Construir parámetros iniciales de consulta
            const params = new URLSearchParams({
                page: this.state.currentPage,
                per_page: this.state.perPage,
                ...this.state.currentFilters
            });
            
            // Agregar parámetros de ordenamiento si existen
            if (this.state.sortBy) {
                params.append('sort_by', this.state.sortBy);
                params.append('sort_order', this.state.sortOrder || 'desc');
            }
            
            const paramsString = params.toString();

            const response = await fetchListWithRetry(`${this.config.apiBaseUrl}/list.php?${paramsString}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            }, 1);

            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                // Procesar datos antes del renderizado
                const processedInformes = data.data.informes.map(informe => {
                    return {
                        ...informe,
                        // Formatear fechas
                        fecha_creacion_formatted: this.formatDate(informe.fecha_creacion),
                        fecha_modificacion_formatted: this.formatDate(informe.fecha_modificacion),
                        // Determinar color del badge según el estado
                        estado_badge: this.getEstadoBadgeColor(informe.estado)
                    };
                });

                // Guardar permisos del usuario
                if (data.data.user_permissions) {
                    this.state.canViewAll = data.data.user_permissions.can_view_all || false;
                    this.state.canSendToPacs = data.data.user_permissions.can_send_to_pacs || false;
                    this.state.canTogglePacsFormat = !!data.data.user_permissions.can_toggle_pacs_format;
                    this.state.currentUserId = data.data.user_permissions.user_id != null ? data.data.user_permissions.user_id : this.state.currentUserId;
                    this.state.canDatosCobranza = !!data.data.user_permissions.can_datos_cobranza;
                    this.state.cobranzaColumnsInstalled = data.data.user_permissions.cobranza_columns_installed !== false;
                    this.updateCobranzaToolbarVisibility();
                }
                
                // Verificar permiso de creación de informes desde la sesión
                await this.checkCreateReportsPermission();
                
                // Verificar permiso para enviar a PACS (asegurar que se verifica correctamente)
                await this.checkSendToPacsPermission();
                await this.checkGuiToggleFormatoPacsPermission();
                
                // Verificar permiso para marcar informes incompletos
                await this.checkMarcarIncompletosPermission();
                
                await this.checkAttachReportsPermission();
                await this.checkInformesRecibidosPermission();
                await this.checkInformesCarpetaPermission();
                await this.checkAttachAudiosPermission();
                await this.checkDownloadInformeAudiosPermission();
                
                // Ocultar/mostrar botones según permisos
                this.updateCreateReportButtonsVisibility();
                this.updateAttachReportButtonVisibility();
                this.updateInformesRecibidosButtonVisibility();
                this.updateInformesCarpetaButtonVisibility();
                this.updateAdjuntarGeneralButtonVisibility();
                this.scheduleInformesCarpetaBadgeRefresh();
                
                // Obtener información del usuario actual si no está disponible
                if (!this.state.currentUserName && data.data.informes && data.data.informes.length > 0) {
                    // Intentar obtener desde el primer informe (si el usuario solo ve sus propios informes)
                    const firstInforme = data.data.informes[0];
                    if (firstInforme && firstInforme.usuario_nombre) {
                        this.state.currentUserName = firstInforme.usuario_nombre;
                        this.state.currentUserLastName = firstInforme.usuario_apellido || '';
                    }
                }
                
                // Si aún no tenemos el nombre del usuario, obtenerlo desde la sesión
                if (!this.state.currentUserName) {
                    await this.loadCurrentUserInfo();
                }

                // SIEMPRE cargar flags antes de renderizar para poder mostrar el destello en los botones
                console.log('🔍 [init] Iniciando carga de flags antes de renderizar...');
                const studyIds = new Set();
                const studyIdMap = {}; // Mapa para guardar todos los IDs posibles de cada informe
                
                processedInformes.forEach(informe => {
                    const studyId = informe.study_id || informe.study_instance_uid || informe.estudio_id;
                    if (studyId) {
                        studyIds.add(studyId);
                        // Guardar todos los IDs posibles para este informe
                        studyIdMap[studyId] = {
                            study_id: informe.study_id,
                            study_instance_uid: informe.study_instance_uid,
                            orthanc_id: informe.orthanc_id,
                            estudio_id: informe.estudio_id
                        };
                    }
                });

                this.mergeInformeUsuarioFilterFromResults(processedInformes);
                this.rebuildInformeUsuarioFilterSelect();
                this.updateInformeUsuarioFilterWrapVisibility();
                
                console.log('🔍 [init] Study IDs encontrados:', Array.from(studyIds));
                
                if (studyIds.size > 0) {
                    console.log('🔍 [init] Llamando a loadStudyFlagsForFilter...');
                    try {
                        await this.loadStudyFlagsForFilter(Array.from(studyIds), studyIdMap);
                        console.log('✅ [init] loadStudyFlagsForFilter completado');
                    } catch (error) {
                        console.error('❌ [init] Error en loadStudyFlagsForFilter:', error);
                    }
                    
                    // Cargar flags individuales por informe después de cargar flags generales
                    console.log('🔍 [init] Cargando flags individuales por informe...');
                    try {
                        await this.loadIndividualReportFlags(processedInformes);
                        console.log('✅ [init] Flags individuales cargados');
                    } catch (error) {
                        console.error('❌ [init] Error cargando flags individuales:', error);
                    }
                    
                    console.log('📋 [init] Flags cargados antes de renderizar:', Object.keys(this.state.studyFlags).length, 'flags');
                    console.log('📋 [init] Claves de flags:', Object.keys(this.state.studyFlags));
                } else {
                    console.warn('⚠️ [init] No se encontraron study IDs para cargar flags');
                }

                this.renderReports(processedInformes);
                this.renderPagination(data.data.pagination);
                this.state.currentPage = data.data.pagination.current_page;
                this.state.totalPages = data.data.pagination.total_pages;
                // NO sobrescribir perPage con el valor del servidor en la primera carga
                // Mantener el valor por defecto (25) o el que el usuario haya seleccionado
                // Solo actualizar si el usuario explícitamente cambió el selector
                if (!this.state.perPageUserSet) {
                    // En la primera carga, mantener el valor por defecto
                    // No hacer nada, mantener this.state.perPage como está (25)
                } else {
                    // Si el usuario ya cambió el selector, usar el valor del servidor como referencia
                    // pero mantener el valor que el usuario seleccionó
                }
                this.state.totalResults = data.data.pagination.total_results || this.state.totalResults;
                
                // Actualizar iconos de ordenamiento después de renderizar
                this.updateSortIcons();
                
                // Si no hay filtros aplicados, actualizar el total sin filtros
                const hasFilters = Object.keys(this.state.currentFilters).some(key => {
                    const value = this.state.currentFilters[key];
                    return value !== null && value !== undefined && value !== '';
                });
                if (!hasFilters) {
                    this.state.totalResultsWithoutFilters = this.state.totalResults;
                }
                
                // Actualizar selector de elementos por página
                const perPageSelect = document.getElementById('perPageSelect');
                if (perPageSelect) {
                    perPageSelect.value = this.state.perPage;
                }
                
                // Guardar informes en el estado para acceso posterior
                this.state.reports = processedInformes;
                
                this.updateResultsCounter();
                
                // Cargar estadísticas del banner
                this.loadBannerStats();
                this.loadSlaBannerCounts();
                
                // Cargar total sin filtros si aún no se ha cargado
                if (this.state.totalResultsWithoutFilters === 0) {
                    this.loadTotalWithoutFilters();
                }
                
                // Toggle PDF/IMG: enviar_pacs + permiso interfaz gui_toggle_formato_pacs
                if (this.state.canSendToPacs && this.state.canTogglePacsFormat) {
                    this.initPacsFormatToggles();
                }
                
                // Inicializar dashboardOrthanc para acceso a estudios
                this.initDashboardOrthanc();
                
                // Inicializar eventos del reproductor de audio
                this.initAudioPlayerEvents();
                
                // Inicializar eventos del reproductor de edición
                this.initEditAudioPlayerEvents();
                
                // Cargar hotkeys al inicio (sin inicializar listeners aún, se hará al abrir el modal)
                this.loadEditAudioHotkeys().catch(err => {
                    console.error('Error cargando hotkeys al iniciar:', err);
                });

                // Deep-link desde dashboard: ?informe_id=&mode=view|edit|sign
                await this.handleUrlDeepLink();
            } else {
                throw new Error(data.error || 'Error desconocido');
            }
        } catch (error) {
            console.error('Error cargando informes:', error);
            this.showError('Error al cargar los informes: ' + error.message);
        } finally {
            this.state.isLoading = false;
            this.setSearchBtnLoading(false);
        }
    },

    /**
     * Abrir informe desde query string (dashboard PARA FIRMAR → Ver/Editar).
     */
    handleUrlDeepLink: async function() {
        try {
            const params = new URLSearchParams(window.location.search || '');
            const informeId = parseInt(params.get('informe_id') || params.get('id') || '0', 10);
            if (!informeId) return;
            const mode = String(params.get('mode') || 'view').toLowerCase();
            const readOnly = mode !== 'edit';
            await this.openReportModal(informeId, readOnly);
            if (mode === 'sign') {
                // Mostrar botón firmar y opcionalmente disparar tras breve delay
                const firmarBtn = document.getElementById('btnFirmarInforme');
                if (firmarBtn) {
                    firmarBtn.style.display = 'inline-block';
                    firmarBtn.disabled = false;
                }
            }
            // Limpiar query para no reabrir al refrescar filtros
            try {
                const url = new URL(window.location.href);
                url.searchParams.delete('informe_id');
                url.searchParams.delete('id');
                url.searchParams.delete('mode');
                window.history.replaceState({}, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
            } catch (e) {}
        } catch (e) {
            console.warn('Deep-link informe:', e);
            this.showError(e.message || 'No se pudo abrir el informe');
        }
    },

    /**
     * Inicializar dashboardOrthanc para acceso a estudios (ya no necesario para carga independiente)
     */
    initDashboardOrthanc: function() {
        console.log('InformesManager ahora carga estudios independientemente de dashboardOrthanc');
        // Ya no necesitamos inicializar dashboardOrthanc para cargar estudios
        // La función loadAvailableStudies ahora es completamente independiente
    },

    /**
     * Cargar información del usuario actual desde la sesión
     */
    loadCurrentUserInfo: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/') ? 
                             '../api/auth' : 'api/auth';
            
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    this.state.currentUserName = result.user.nombre || '';
                    this.state.currentUserLastName = result.user.apellido || '';
                    return true;
                }
            }
        } catch (error) {
            console.error('Error cargando información del usuario:', error);
        }
        return false;
    },

    /**
     * Verificar permiso para crear informes
     */
    checkCreateReportsPermission: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/') ? 
                             '../api/auth' : 'api/auth';
            
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    
                    // Convertir permisos a array si es necesario
                    if (typeof permisos === 'string') {
                        try {
                            permisos = JSON.parse(permisos);
                        } catch (e) {
                            permisos = [permisos];
                        }
                    }
                    
                    // Verificar permiso 'informes' o 'all'
                    this.state.canCreateReports = permisos.includes('informes') || 
                                                  permisos.includes('all');
                    
                    console.log('✅ Permiso "Creación de Informes" verificado:', this.state.canCreateReports);
                    return this.state.canCreateReports;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso "Creación de Informes":', error);
        }
        
        this.state.canCreateReports = false;
        return false;
    },

    /**
     * Actualizar visibilidad de botones de creación de informes
     */
    updateCreateReportButtonsVisibility: function() {
        const canCreate = this.state.canCreateReports || false;
        
        // Botón principal "Nuevo Informe"
        const btnNuevoInforme = document.getElementById('btnNuevoInforme');
        if (btnNuevoInforme) {
            btnNuevoInforme.style.display = canCreate ? '' : 'none';
        }
        
        // Botón "Nuevo Informe" en el modal de edición
        const btnNewReportFromCurrent = document.getElementById('btnNewReportFromCurrent');
        if (btnNewReportFromCurrent) {
            btnNewReportFromCurrent.style.display = canCreate ? '' : 'none';
        }
    },

    /**
     * Verificar permiso para adjuntar informes PDF a estudio (solo claves explícitas o all; gestionInformes no basta).
     */
    checkAttachReportsPermission: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/') ?
                '../api/auth' : 'api/auth';

            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });

            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    if (typeof permisos === 'string') {
                        try {
                            permisos = JSON.parse(permisos);
                        } catch (e) {
                            permisos = [permisos];
                        }
                    }
                    this.state.canAttachReports = permisos.includes('adjuntar_informe_estudio') ||
                        permisos.includes('adjuntarInformes') ||
                        permisos.includes('all');
                    return this.state.canAttachReports;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso adjuntar informes:', error);
        }
        this.state.canAttachReports = false;
        return false;
    },

    updateAttachReportButtonVisibility: function() {
        const btn = document.getElementById('btnAdjuntarInforme');
        if (!btn) return;
        btn.style.display = this.state.canAttachReports ? '' : 'none';
    },

    /**
     * Ver permiso para botón Informes recibidos (API) en Gestión de Informes
     */
    checkInformesRecibidosPermission: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/') ?
                '../api/auth' : 'api/auth';

            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });

            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    if (typeof permisos === 'string') {
                        try {
                            permisos = JSON.parse(permisos);
                        } catch (e) {
                            permisos = [permisos];
                        }
                    }
                    this.state.canViewInformesRecibidos = permisos.includes('informes_recibidos') ||
                        permisos.includes('all');
                    return this.state.canViewInformesRecibidos;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso informes recibidos:', error);
        }
        this.state.canViewInformesRecibidos = false;
        return false;
    },

    updateInformesRecibidosButtonVisibility: function() {
        const btn = document.getElementById('btnInformesRecibidos');
        if (!btn) return;
        btn.style.display = this.state.canViewInformesRecibidos ? '' : 'none';
    },

    /**
     * Informes desde carpetas SMB (cola / trazabilidad)
     */
    checkInformesCarpetaPermission: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/') ?
                '../api/auth' : 'api/auth';

            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });

            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    if (typeof permisos === 'string') {
                        try {
                            permisos = JSON.parse(permisos);
                        } catch (e) {
                            permisos = [permisos];
                        }
                    }
                    this.state.canViewInformesCarpeta = permisos.includes('informes_carpeta') ||
                        permisos.includes('informes_recibidos') ||
                        permisos.includes('all');
                    return this.state.canViewInformesCarpeta;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso informes carpeta:', error);
        }
        this.state.canViewInformesCarpeta = false;
        return false;
    },

    updateInformesCarpetaButtonVisibility: function() {
        const btn = document.getElementById('btnInformesCarpeta');
        if (!btn) return;
        btn.style.display = this.state.canViewInformesCarpeta ? '' : 'none';
    },

    /**
     * Actualiza el badge del botón Informes carpeta (script cargado después que este archivo).
     */
    scheduleInformesCarpetaBadgeRefresh: function() {
        if (!this.state.canViewInformesCarpeta) {
            const b = document.getElementById('icBtnBadge');
            if (b) {
                b.textContent = '0';
                b.classList.add('d-none');
            }
            return;
        }
        const run = () => {
            if (window.InformesCarpetaModal && typeof window.InformesCarpetaModal.refreshAttentionBadge === 'function') {
                window.InformesCarpetaModal.refreshAttentionBadge({ force: true }).catch(() => {});
            }
        };
        [0, 250, 900].forEach((ms) => setTimeout(run, ms));
    },

    /**
     * Comprueba un permiso en el JSON de usuario (lista, mapa o clave «all»), alineado con list.php / user-management.
     */
    userHasInformesPermissionKey(permisos, permissionKey) {
        if (permisos == null || permisos === '') {
            return false;
        }
        let raw = permisos;
        if (typeof raw === 'string') {
            try {
                raw = JSON.parse(raw);
            } catch (e) {
                raw = [raw];
            }
        }
        if (Array.isArray(raw)) {
            return raw.includes('all') || raw.includes(permissionKey);
        }
        if (typeof raw === 'object') {
            if (raw.all) return true;
            return !!(raw[permissionKey]);
        }
        return false;
    },

    /**
     * Muestra u oculta el toggle global PDF/IMG según permiso enviar_pacs (Gestión de usuarios).
     */
    updatePacsFormatToggleVisibility: function() {
        const wrap = document.getElementById('pacsFormatToggleWrap');
        const sw = document.getElementById('pacs-format-global');
        if (!wrap) return;
        const show = !!(this.state.canSendToPacs && this.state.canTogglePacsFormat);
        if (show) {
            wrap.style.display = '';
            wrap.removeAttribute('hidden');
            if (sw) sw.disabled = false;
        } else {
            wrap.style.display = 'none';
            wrap.setAttribute('hidden', 'hidden');
            if (sw) {
                sw.checked = false;
                sw.disabled = true;
            }
        }
    },

    /**
     * Verificar permiso para enviar a PACS
     */
    checkSendToPacsPermission: async function() {
        try {
            // Detectar si estamos en components/ y ajustar la URL base
            const apiBaseUrl = window.location.pathname.includes('/components/') ? 
                             '../api/auth' : 'api/auth';
            
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    const permisos = result.user.permisos || [];
                    this.state.canSendToPacs = this.userHasInformesPermissionKey(permisos, 'enviar_pacs');
                    console.log('✅ Permiso "Enviar a PACS" verificado:', this.state.canSendToPacs);
                    this.updatePacsFormatToggleVisibility();
                    return this.state.canSendToPacs;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso "Enviar a PACS":', error);
        }

        // Si la sesión no respondió, conservar canSendToPacs ya fijado por list.php
        this.updatePacsFormatToggleVisibility();
        return this.state.canSendToPacs;
    },

    /**
     * Permiso interfaz gui_toggle_formato_pacs (Gestión de usuarios → Interfaz).
     */
    checkGuiToggleFormatoPacsPermission: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/') ?
                '../api/auth' : 'api/auth';
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    const permisos = result.user.permisos || [];
                    this.state.canTogglePacsFormat = this.userHasInformesPermissionKey(permisos, 'gui_toggle_formato_pacs');
                    this.updatePacsFormatToggleVisibility();
                    return this.state.canTogglePacsFormat;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso gui_toggle_formato_pacs:', error);
        }
        this.updatePacsFormatToggleVisibility();
        return this.state.canTogglePacsFormat;
    },

    /**
     * Verificar permiso para marcar informes como incompletos
     */
    checkMarcarIncompletosPermission: async function() {
        try {
            // Detectar si estamos en components/ y ajustar la URL base
            const apiBaseUrl = window.location.pathname.includes('/components/') ? 
                             '../api/auth' : 'api/auth';
            
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    
                    // Convertir permisos a array si es necesario
                    if (typeof permisos === 'string') {
                        try {
                            permisos = JSON.parse(permisos);
                        } catch (e) {
                            permisos = [permisos];
                        }
                    }
                    
                    // Verificar permiso 'marcar_incompletos' o 'all'
                    this.state.canMarcarIncompletos = permisos.includes('marcar_incompletos') || 
                                                      permisos.includes('all');
                    
                    console.log('✅ Permiso "Marcar Incompletos" verificado:', this.state.canMarcarIncompletos);
                    return this.state.canMarcarIncompletos;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso "Marcar Incompletos":', error);
        }
        
        this.state.canMarcarIncompletos = false;
        return false;
    },

    /**
     * Verificar permiso para gestionar plantillas
     */
    checkPlantillasPermission: async function() {
        try {
            // Detectar si estamos en components/ y ajustar la URL base
            const apiBaseUrl = window.location.pathname.includes('/components/') ? 
                             '../api/auth' : 'api/auth';
            
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    
                    // Convertir permisos a array si es necesario
                    if (typeof permisos === 'string') {
                        try {
                            permisos = JSON.parse(permisos);
                        } catch (e) {
                            permisos = [permisos];
                        }
                    }
                    
                    // Verificar permiso 'plantillas' o 'all'
                    const hasPermission = permisos.includes('plantillas') || 
                                        permisos.includes('all');
                    
                    console.log('✅ Permiso "Gestión de Plantillas" verificado:', hasPermission);
                    return hasPermission;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso "Gestión de Plantillas":', error);
        }
        
        return false;
    },

    // Utilidad para obtener token de sesión
    getSessionToken() {
        try {
            const lsToken = localStorage.getItem('session_token') || localStorage.getItem('auth_token');
            const ssToken = sessionStorage.getItem('session_token') || sessionStorage.getItem('auth_token');
            if (lsToken) return lsToken;
            if (ssToken) return ssToken;
            const match = document.cookie.match(/(?:^|; )session_token=([^;]+)/);
            if (match) return decodeURIComponent(match[1]);
            const match2 = document.cookie.match(/(?:^|; )token=([^;]+)/);
            if (match2) return decodeURIComponent(match2[1]);
            return null;
        } catch (e) {
            return null;
        }
    },

    /**
     * Configurar event listeners para botones del modal y búsqueda
     */
    setupEventListeners: function() {
        // Verificar si ya se configuraron los event listeners para evitar duplicados
        if (this.state.eventListenersSetup) {
            return;
        }
        this.state.eventListenersSetup = true;

        document.addEventListener('qa-status-changed', (e) => {
            if (e.detail && e.detail.type === 'informe' && this.state.reports && this.state.reports.length) {
                this.renderReports(this.state.reports);
            }
        });
        
        // Event listener para los botones del modal y búsqueda
        document.addEventListener('click', (e) => {
            if (e.target && e.target.id === 'btnSaveReport') {
                e.preventDefault();
                this.saveReport();
            } else if (e.target && (e.target.id === 'btnFirmarInforme' || e.target.closest('#btnFirmarInforme'))) {
                e.preventDefault();
                this.firmarInformeActual();
            } else if (e.target && (e.target.id === 'btnMiFirma' || e.target.closest('#btnMiFirma'))) {
                e.preventDefault();
                this.openMiFirmaModal();
            } else if (e.target && e.target.id === 'btnGuardarMiFirma') {
                e.preventDefault();
                this.guardarMiFirma();
            } else if (e.target && e.target.id === 'btnMiFirmaQr') {
                e.preventDefault();
                this.iniciarCapturaFirmaQr();
            } else if (e.target && e.target.id === 'btnPreviewPDF') {
                e.preventDefault();
                this.previewPDF();
            } else if (e.target && e.target.id === 'btnExportPDF') {
                e.preventDefault();
                this.exportToPDF();
            } else if (e.target && e.target.id === 'btnNewReportFromCurrent') {
                e.preventDefault();
                this.createNewReportFromCurrent();
            } else if (e.target && e.target.closest && e.target.closest('#searchBtn')) {
                e.preventDefault();
                this.performSearch();
            } else if (e.target && e.target.closest && e.target.closest('#clearFiltersBtn')) {
                e.preventDefault();
                this.clearFilters();
            } else if (e.target && e.target.closest && e.target.closest('#btnCobranzaPlanilla')) {
                e.preventDefault();
                this.openCobranzaPlanillaModal();
            } else if (e.target && e.target.closest && e.target.closest('#cobranzaPlanillaRefreshBtn')) {
                e.preventDefault();
                this.refreshCobranzaPlanillaModal();
            } else if (e.target && e.target.closest && e.target.closest('#cobranzaPlanillaCsvBtn')) {
                e.preventDefault();
                this.downloadCobranzaCsv();
            } else if (e.target && e.target.closest && e.target.closest('#cobranzaPlanillaExcelBtn')) {
                e.preventDefault();
                this.downloadCobranzaExcel();
            } else if (e.target && e.target.id === 'cobranzaEditSaveBtn') {
                e.preventDefault();
                this.saveCobranzaEdit();
            } else if (e.target && e.target.closest && e.target.closest('#cobranzaPlanillaGuardarBtn')) {
                e.preventDefault();
                this.saveCobranzaPlanillaModalAll();
            } else if (e.target && e.target.id === 'btnNuevoInforme') {
                e.preventDefault();
                this.createNewReport();
            } else if (e.target && e.target.id === 'btnTemplateManager') {
                e.preventDefault();
                this.openTemplateManager();
            } else if (e.target && e.target.id === 'btnAdjuntarInforme') {
                e.preventDefault();
                this.showAttachPdfModal();
            } else if (e.target && e.target.id === 'attachStudySearchButton') {
                e.preventDefault();
                this.searchStudiesForAttach();
            } else if (e.target && e.target.id === 'btnConfirmAttachPdf') {
                e.preventDefault();
                this.confirmAttachPdf();
            } else if (e.target.closest && e.target.closest('.select-study-for-attach')) {
                e.preventDefault();
                const selBtn = e.target.closest('.select-study-for-attach');
                const idx = parseInt(selBtn.getAttribute('data-attach-index') || '-1', 10);
                const study = this.state.attachStudySearchResults && this.state.attachStudySearchResults[idx];
                if (study) {
                    this.selectStudyForAttach(study);
                }
            } else if (e.target.closest && e.target.closest('.pdf-viewer-btn')) {
                const pvBtn = e.target.closest('.pdf-viewer-btn');
                const path = pvBtn.getAttribute('data-pdf-path');
                const pdfTitle = pvBtn.getAttribute('data-pdf-title') || '';
                if (path) {
                    this.openPdfViewerModal(path, pdfTitle);
                }
            } else if (e.target.closest && e.target.closest('#attachPdfModal .attach-modality-btn')) {
                e.preventDefault();
                const mbtn = e.target.closest('#attachPdfModal .attach-modality-btn');
                this.handleAttachStudyModalityButtonClick(mbtn);
            } else if (e.target.closest && e.target.closest('#attachPdfModal th.attach-sortable')) {
                e.preventDefault();
                const th = e.target.closest('#attachPdfModal th.attach-sortable');
                const col = th.getAttribute('data-attach-sort');
                if (col) {
                    this.sortAttachStudiesByColumn(col);
                }
            }
        });

        const cobranzaSoloListosEl = document.getElementById('cobranzaSoloListos');
        if (cobranzaSoloListosEl && !cobranzaSoloListosEl.dataset.boundCobranza) {
            cobranzaSoloListosEl.dataset.boundCobranza = '1';
            cobranzaSoloListosEl.addEventListener('change', () => {
                this.refreshCobranzaPlanillaModal();
            });
        }

        if (!document.body.dataset.cobranzaPlanillaInputBound) {
            document.body.dataset.cobranzaPlanillaInputBound = '1';
            document.addEventListener('input', (e) => {
                const el = e.target;
                if (!el || !el.closest || !el.closest('#cobranzaPlanillaTbody')) return;
                if (el.classList && (el.classList.contains('cobranza-planilla-estudio') || el.classList.contains('cobranza-planilla-codigos'))) {
                    InformesManager.markCobranzaPlanillaDirty(true);
                }
            });
        }

        const attachPdfFileEl = document.getElementById('attachPdfFile');
        if (attachPdfFileEl && !attachPdfFileEl.dataset.boundInformesAttach) {
            attachPdfFileEl.dataset.boundInformesAttach = '1';
            attachPdfFileEl.addEventListener('change', (ev) => {
                this.handleAttachPdfFileSelect(ev.target.files[0]);
            });
        }

        const attachStudyFilterInput = document.getElementById('attachStudySearchFilter');
        if (attachStudyFilterInput && !attachStudyFilterInput.dataset.boundAttachFilter) {
            attachStudyFilterInput.dataset.boundAttachFilter = '1';
            attachStudyFilterInput.addEventListener('input', () => {
                if (this.state.attachStudyFilterDebounce) {
                    clearTimeout(this.state.attachStudyFilterDebounce);
                }
                this.state.attachStudyFilterDebounce = setTimeout(() => {
                    this.state.attachStudyFilterDebounce = null;
                    this.filterAttachStudiesResultsInPlace();
                }, 220);
            });
        }

        // Event listener para búsqueda con Enter
        document.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && (e.target.id === 'searchInput' || e.target.id === 'patientNameFilter')) {
                e.preventDefault();
                // Cancelar cualquier timeout pendiente de búsqueda en tiempo real
                if (this.state.searchTimeout) {
                    clearTimeout(this.state.searchTimeout);
                    this.state.searchTimeout = null;
                }
                // Hacer búsqueda inmediata en servidor
                this.performSearch();
            }
        });

        // Event listeners para ordenamiento de columnas
        // Usar delegación de eventos en la tabla específica
        const reportsTable = document.getElementById('reportsTable');
        if (reportsTable) {
            reportsTable.addEventListener('click', (e) => {
                // Buscar el elemento sortable más cercano (puede ser el th o un elemento dentro de él)
                const sortableHeader = e.target.closest('th.sortable');
                if (sortableHeader) {
                    e.preventDefault();
                    e.stopPropagation();
                    const sortBy = sortableHeader.getAttribute('data-sort');
                    if (sortBy) {
                        console.log('🔄 [Sort] Ordenando por:', sortBy);
                        this.handleSort(sortBy);
                    } else {
                        console.warn('⚠️ [Sort] No se encontró atributo data-sort en:', sortableHeader);
                    }
                }
            });
        } else {
            // Fallback: usar delegación en document si la tabla no existe aún
            document.addEventListener('click', (e) => {
                const sortableHeader = e.target.closest('th.sortable');
                if (sortableHeader && sortableHeader.closest('#reportsTable')) {
                    e.preventDefault();
                    e.stopPropagation();
                    const sortBy = sortableHeader.getAttribute('data-sort');
                    if (sortBy) {
                        console.log('🔄 [Sort] Ordenando por:', sortBy);
                        this.handleSort(sortBy);
                    }
                }
            });
        }

        // Event listener para búsqueda en tiempo real en el campo general
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.handleRealtimeSearch(e.target.value);
            });
        }

        const filterUsuarioInforme = document.getElementById('filterUsuarioInforme');
        if (filterUsuarioInforme && !filterUsuarioInforme.dataset.boundInformeUsuario) {
            filterUsuarioInforme.dataset.boundInformeUsuario = '1';
            filterUsuarioInforme.addEventListener('change', () => {
                const v = (filterUsuarioInforme.value || '').trim();
                if (v) {
                    this.state.currentFilters.filter_usuario_id = v;
                } else {
                    delete this.state.currentFilters.filter_usuario_id;
                }
                this.loadReports(1);
            });
        }

        // NO usar beforeunload - solo modales profesionales de Bootstrap
        // Los popups del navegador están deshabilitados completamente

        // Detectar cambios en los campos del modal
        this.setupModalChangeDetection();
        
        // Detectar cuando se cierra el modal para limpiar estado
        this.setupModalCloseDetection();
        
        // Configurar detección de navegación por sidebar
        this.setupSidebarNavigationDetection();
        
        // Configurar modal de confirmación de navegación
        this.setupNavigationConfirmModal();
        
        // Inicializar módulo de gestión de plantillas
        this.initTemplateManager();
        
        // Event listener para el selector de elementos por página
        // Usar delegación de eventos para asegurar que funcione incluso si el elemento se carga después
        // Solo agregar el listener una vez
        if (!this.state.perPageListenerAdded) {
            const self = this;
            const perPageChangeHandler = function(e) {
                if (e.target && e.target.id === 'perPageSelect') {
                    e.preventDefault();
                    e.stopPropagation();
                    const newValue = parseInt(e.target.value);
                    if (newValue && newValue > 0) {
                        console.log('Cambiando elementos por página de', self.state.perPage, 'a', newValue);
                        self.changePerPage(newValue);
                    }
                }
            };
            document.addEventListener('change', perPageChangeHandler);
            this.state.perPageListenerAdded = true;
            this.state.perPageChangeHandler = perPageChangeHandler;
        }
        
        // También establecer el valor inicial cuando el elemento esté disponible
        const setInitialPerPageValue = () => {
            const perPageSelect = document.getElementById('perPageSelect');
            if (perPageSelect) {
                perPageSelect.value = this.state.perPage;
                console.log('Selector perPage inicializado con valor:', this.state.perPage);
            } else {
                // Reintentar después de un breve delay
                setTimeout(setInitialPerPageValue, 100);
            }
        };
        setInitialPerPageValue();

        this.setupInformesQuickDatePresetButtons();
    },

    /**
     * Configurar detección de cierre del modal
     */
    setupModalCloseDetection() {
        const modalElement = document.getElementById('reportEditorModal');
        if (!modalElement) {
            // Si el modal no existe aún, configurar después de que se cree
            setTimeout(() => this.setupModalCloseDetection(), 100);
            return;
        }

        // Verificar si ya hay listeners (evitar duplicados)
        if (modalElement.dataset.closeDetectionSetup === 'true') {
            return;
        }
        modalElement.dataset.closeDetectionSetup = 'true';

        // Interceptar intento de cierre del modal (cuando se presiona X o ESC)
        // NOTA: Con backdrop: 'static', el click fuera NO dispara este evento
        const hideHandler = (e) => {
            console.log('🔍 Evento hide.bs.modal disparado');
            
            // Si se permite cierre libre (después de guardar), no interceptar
            if (this.state.allowModalClose) {
                console.log('✅ Cierre permitido (después de guardar)');
                return; // Permitir cierre normal
            }

            // Verificar si estamos en modo edición (no solo lectura)
            const titleEl = modalElement.querySelector('.modal-title');
            const isReadOnly = titleEl && titleEl.textContent.includes('Ver Informe');
            
            // También verificar si el botón de guardar está visible
            const saveBtn = document.getElementById('btnSaveReport');
            const isEditMode = saveBtn && saveBtn.style.display !== 'none';
            
            console.log('🔍 Estado del modal:', {
                isReadOnly,
                isEditMode,
                hasUnsavedChanges: this.state.hasUnsavedChanges,
                originalDataExists: !!this.state.originalReportData
            });
            
            // CRÍTICO: Si allowModalClose es true, permitir cierre SIN verificar cambios
            // Esto evita que se muestre el modal de advertencia cuando el usuario ya confirmó desde el modal de advertencia
            if (this.state.allowModalClose) {
                console.log('✅ Cierre permitido (allowModalClose = true) - NO interceptar');
                this.state.pendingModalClose = false;
                return; // Permitir cierre normal sin verificar cambios
            }
            
            // Solo interceptar si estamos en modo edición
            if (isEditMode && !isReadOnly) {
                // Si hay un timeout pendiente de verificación de cambios, cancelarlo
                if (this.state.markModifiedTimeout) {
                    console.log('⏱️ Timeout pendiente detectado - cancelando');
                    clearTimeout(this.state.markModifiedTimeout);
                    this.state.markModifiedTimeout = null;
                }
                
                // Verificar si realmente hay cambios (comparación con datos originales)
                // IMPORTANTE: Solo verificar hasRealChanges() - no confiar solo en el flag
                const hasRealChangesCheck = this.hasRealChanges();
                
                console.log('🔍 Verificando si interceptar cierre:', {
                    allowModalClose: this.state.allowModalClose,
                    hasRealChanges: hasRealChangesCheck,
                    originalDataExists: !!this.state.originalReportData,
                    originalDataId: this.state.originalReportData?.id
                });
                
                // CRÍTICO: Solo interceptar si REALMENTE hay cambios detectados por hasRealChanges()
                // No confiar en el flag hasUnsavedChanges que puede ser incorrecto
                if (hasRealChangesCheck && !this.state.allowModalClose) {
                    console.log('⚠️ INTENTANDO CERRAR MODAL CON CAMBIOS NO GUARDADOS - CANCELANDO CIERRE');
                    console.log('   - hasRealChanges (comparación):', hasRealChangesCheck);
                    
                    // Prevenir el cierre del modal
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    
                    // Guardar referencia para que podamos cerrar el modal después si el usuario confirma
                    this.state.pendingModalClose = true;
                    
                    // Mostrar modal de advertencia (solo si no está ya mostrándose)
                    if (!this.state.warningModalShowing) {
                        this.showUnsavedChangesWarning();
                    } else {
                        console.log('⚠️ Modal de advertencia ya está mostrándose - NO crear otro');
                    }
                    
                    return false; // Retornar false también previene el cierre
                } else {
                    // No hay cambios, permitir cierre normal
                    console.log('✅ Modal cerrado normalmente - sin cambios detectados');
                    console.log('   - hasRealChanges():', hasRealChangesCheck);
                    this.state.pendingModalClose = false;
                    // Limpiar flag incorrecto si existe
                    this.state.hasUnsavedChanges = false;
                }
            } else {
                // Modo lectura o no es modo edición, permitir cierre normal
                console.log('✅ Modal cerrado normalmente - modo lectura o no edición');
                this.state.pendingModalClose = false;
                // Limpiar flag incorrecto si existe
                this.state.hasUnsavedChanges = false;
            }
        };
        
        // Agregar el listener con capture para interceptar antes que otros listeners
        modalElement.addEventListener('hide.bs.modal', hideHandler, { capture: true });
        
        // También interceptar directamente el clic en el botón X
        // Esto previene el cierre ANTES de que se dispare hide.bs.modal
        const closeButton = modalElement.querySelector('.btn-close, [data-bs-dismiss="modal"]');
        if (closeButton) {
            closeButton.addEventListener('click', (e) => {
                console.log('🔍 Click en botón cerrar detectado');
                
                // Si NO se permite cierre libre Y hay cambios, prevenir el cierre
                if (!this.state.allowModalClose) {
                    // Verificar modo edición
                    const titleEl = modalElement.querySelector('.modal-title');
                    const isReadOnly = titleEl && titleEl.textContent.includes('Ver Informe');
                    const saveBtn = document.getElementById('btnSaveReport');
                    const isEditMode = saveBtn && saveBtn.style.display !== 'none';
                    
                    if (isEditMode && !isReadOnly) {
                        // Si hay un timeout pendiente, cancelarlo
                        if (this.state.markModifiedTimeout) {
                            console.log('⏱️ Timeout pendiente (botón X) - cancelando');
                            clearTimeout(this.state.markModifiedTimeout);
                            this.state.markModifiedTimeout = null;
                        }
                        
                        // Solo verificar hasRealChanges() - no confiar en el flag
                        const hasChanges = this.hasRealChanges();
                        
                        console.log('🔍 Verificación botón X:', {
                            hasRealChanges: hasChanges,
                            originalDataExists: !!this.state.originalReportData
                        });
                        
                        if (hasChanges) {
                            console.log('⚠️ Click en X con cambios - PREVINIENDO cierre');
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            
                            // Mostrar advertencia directamente
                            this.showUnsavedChangesWarning();
                            
                            return false;
                        } else {
                            // No hay cambios, limpiar flag incorrecto si existe
                            this.state.hasUnsavedChanges = false;
                        }
                    }
                }
            }, { capture: true }); // Capturar antes que Bootstrap maneje el evento
        }
        
        // Interceptar tecla ESC también - prevenir ANTES de que Bootstrap la maneje
        const escHandler = (e) => {
            if (e.key === 'Escape' && modalElement.classList.contains('show')) {
                console.log('🔍 Tecla ESC presionada en modal');
                
                // Si NO se permite cierre libre Y hay cambios, prevenir el cierre
                if (!this.state.allowModalClose) {
                    // Verificar modo edición
                    const titleEl = modalElement.querySelector('.modal-title');
                    const isReadOnly = titleEl && titleEl.textContent.includes('Ver Informe');
                    const saveBtn = document.getElementById('btnSaveReport');
                    const isEditMode = saveBtn && saveBtn.style.display !== 'none';
                    
                    if (isEditMode && !isReadOnly) {
                        // Si hay un timeout pendiente, cancelarlo
                        if (this.state.markModifiedTimeout) {
                            console.log('⏱️ Timeout pendiente (ESC) - cancelando');
                            clearTimeout(this.state.markModifiedTimeout);
                            this.state.markModifiedTimeout = null;
                        }
                        
                        // Solo verificar hasRealChanges() - no confiar en el flag
                        const hasChanges = this.hasRealChanges();
                        
                        console.log('🔍 Verificación ESC:', {
                            hasRealChanges: hasChanges,
                            originalDataExists: !!this.state.originalReportData
                        });
                        
                        if (hasChanges) {
                            console.log('⚠️ ESC con cambios - PREVINIENDO cierre');
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            
                            // Mostrar advertencia directamente
                            this.showUnsavedChangesWarning();
                            
                            return false;
                        } else {
                            // No hay cambios, limpiar flag incorrecto si existe
                            this.state.hasUnsavedChanges = false;
                        }
                    }
                }
            }
        };
        document.addEventListener('keydown', escHandler, { capture: true }); // Capturar antes que Bootstrap
        
        // Limpiar el listener de ESC cuando el modal se cierre
        modalElement.addEventListener('hidden.bs.modal', () => {
            document.removeEventListener('keydown', escHandler, { capture: true });
        }, { once: true });

        // Limpiar estado cuando el modal se cierra completamente
        modalElement.addEventListener('hidden.bs.modal', (e) => {
            if (e.target.id === 'reportEditorModal') {
                console.log('Modal cerrado completamente - limpiando estado de cambios');
                
                // CRÍTICO: Limpiar TODOS los flags y estados relacionados con cambios
                // Esto previene que aparezcan popups del navegador
                this.clearChangesState();
                this.state.allowModalClose = true; // Permitir cierre libre al abrir nuevamente
                this.state.hasUnsavedChanges = false; // Asegurar que está en false
                this.state.warningModalShowing = false;
                this.state.pendingModalClose = false;
                this.state.originalReportData = null;
                
                // Detener autosave si estaba activo
                if (this.state.autoSaveTimeout) {
                    clearTimeout(this.state.autoSaveTimeout);
                    this.state.autoSaveTimeout = null;
                }
                if (this.state.autoSaveInterval) {
                    clearInterval(this.state.autoSaveInterval);
                    this.state.autoSaveInterval = null;
                }
                
                // Limpiar timeout de verificación de cambios si existe
                if (this.state.markModifiedTimeout) {
                    clearTimeout(this.state.markModifiedTimeout);
                    this.state.markModifiedTimeout = null;
                }
                
                // Ocultar panel flotante de transcripción
                this.hideFloatingTranscriptionPanel();
                
                console.log('✅ Estado completamente limpiado - no habrá popups del navegador');
            }
        });
    },

    /**
     * Configurar detección de navegación por sidebar
     */
    setupSidebarNavigationDetection() {
        // Detectar clics en enlaces del sidebar
        document.addEventListener('click', (e) => {
            // Verificar si es un enlace del sidebar
            if (e.target.closest('.sidebar .nav-link')) {
                const link = e.target.closest('.nav-link');
                const href = link.getAttribute('href');
                
                // Si es un enlace interno (no externo), verificar cambios
                if (href && !href.startsWith('http') && !href.startsWith('#')) {
                    // Verificar si el modal de editar informe está abierto
                    const modalEl = document.getElementById('reportEditorModal');
                    const isModalOpen = modalEl && modalEl.classList.contains('show');
                    
                    console.log('🔍 [SIDEBAR NAVIGATION] Click en enlace del sidebar:', {
                        href: href,
                        isModalOpen: isModalOpen,
                        allowModalClose: this.state.allowModalClose,
                        hasUnsavedChanges: this.state.hasUnsavedChanges,
                        hasRealChanges: isModalOpen ? this.hasRealChanges() : false
                    });
                    
                    // CRÍTICO: Si allowModalClose es true, significa que se acaba de guardar
                    // NO mostrar advertencia en ese caso, permitir navegación libre
                    if (this.state.allowModalClose) {
                        console.log('✅ allowModalClose=true - Navegación libre permitida');
                        this.clearChangesState();
                        return; // Permitir navegación sin advertencia
                    }
                    
                    // Si el modal NO está abierto, permitir navegación sin advertencia
                    if (!isModalOpen) {
                        console.log('✅ Modal NO está abierto - Navegación libre permitida');
                        this.clearChangesState();
                        return; // Permitir navegación sin advertencia
                    }
                    
                    // Solo verificar cambios si el modal está abierto Y realmente hay cambios
                    if (isModalOpen && this.state.hasUnsavedChanges && this.hasRealChanges()) {
                        console.log('⚠️ Modal abierto CON cambios reales - Mostrando confirmación');
                        e.preventDefault();
                        this.state.pendingNavigationUrl = href;
                        this.showNavigationConfirmModal();
                    } else {
                        // Si no hay cambios o el modal no está abierto, limpiar estado y navegar normalmente
                        console.log('✅ No hay cambios reales - Navegación libre permitida');
                        this.clearChangesState();
                    }
                }
            }
        });
        
        // También detectar cuando se cambia de página usando el historial del navegador
        window.addEventListener('popstate', () => {
            console.log('Navegación por historial detectada - limpiando estado de cambios');
            this.clearChangesState();
        });
    },

    /**
     * Configurar modal de confirmación de navegación
     */
    setupNavigationConfirmModal() {
        // Variables para almacenar la URL de destino
        this.state.pendingNavigationUrl = null;
        
        // Configurar eventos del modal
        document.getElementById('stayOnPageBtn').addEventListener('click', () => {
            this.handleStayOnPage();
        });
        
        document.getElementById('saveAndLeaveBtn').addEventListener('click', () => {
            this.handleSaveAndLeave();
        });
        
        document.getElementById('leaveWithoutSavingBtn').addEventListener('click', () => {
            this.handleLeaveWithoutSaving();
        });
    },

    /**
     * Inicializar módulo de gestión de plantillas
     */
    initTemplateManager: function() {
        try {
            // Inicializar el módulo de plantillas
            if (window.TemplateManagerModule) {
                window.TemplateManagerModule.init();
                
                // Crear el selector de plantillas
                this.createTemplateSelector();
                
                // NO crear el modal aquí - se creará solo cuando se necesite
                console.log('Módulo de gestión de plantillas inicializado correctamente');
            } else {
                console.warn('TemplateManagerModule no está disponible');
            }
        } catch (error) {
            console.error('Error inicializando módulo de plantillas:', error);
        }
    },

    /**
     * Crear selector de plantillas
     */
    createTemplateSelector: function() {
        const container = document.getElementById('templateSelectorContainer');
        if (!container) return;

        if (window.TemplateManagerModule) {
            const selectorHtml = window.TemplateManagerModule.createTemplateSelector(
                'informeTemplateSelect',
                (templateId) => {
                    console.log('Plantilla cargada:', templateId);
                    // NO marcar como modificado cuando se carga una plantilla
                    // La plantilla es contenido inicial, no un cambio del usuario
                    console.log('Plantilla cargada - no marcando como modificado');
                }
            );
            container.innerHTML = selectorHtml;
        }
    },

    /**
     * Actualizar selector de plantillas (refrescar dropdown)
     */
    refreshTemplateSelector: function() {
        if (window.TemplateManagerModule && typeof window.TemplateManagerModule.refreshTemplateSelector === 'function') {
            window.TemplateManagerModule.refreshTemplateSelector('informeTemplateSelect');
        } else {
            console.warn('TemplateManagerModule.refreshTemplateSelector no está disponible, recreando selector');
            this.createTemplateSelector();
        }
    },

    /**
     * Abrir gestor de plantillas
     */
    openTemplateManager: async function() {
        try {
            console.log('Abriendo gestor de plantillas...');
            
            // Verificar que el módulo de plantillas esté disponible
            if (!window.TemplateManagerModule) {
                this.showError('El módulo de gestión de plantillas no está disponible');
                return;
            }
            
            // Verificar permiso "plantillas" o "all" antes de abrir el gestor
            const hasPermission = await this.checkPlantillasPermission();
            if (!hasPermission) {
                this.showError('No tiene permisos para gestionar plantillas. Se requiere el permiso "Gestión de Plantillas".');
                return;
            }
            
            // Verificar si el modal de edición está abierto
            const reportEditorModal = document.getElementById('reportEditorModal');
            const isReportModalOpen = reportEditorModal && reportEditorModal.classList.contains('show');
            
            if (isReportModalOpen) {
                console.log('✅ Modal de edición está abierto - el modal de plantillas se abrirá encima');
            }
            
            // Verificar si el modal ya existe
            let modalElement = document.getElementById('templateManagerModal');
            if (!modalElement) {
                console.log('Creando modal de gestión de plantillas...');
                
                // NO limpiar el estado del modal de edición si está abierto
                // Solo limpiar backdrops específicos del modal de plantillas si es necesario
                if (!isReportModalOpen) {
                this.cleanupModalState();
                }
                
                this.createTemplateManagerModal();
                
                // Esperar un momento para que el modal se cree completamente
                await new Promise(resolve => setTimeout(resolve, 150));
                modalElement = document.getElementById('templateManagerModal');
            } else {
                // Si el modal ya existe, solo limpiar backdrops residuales si no hay otro modal abierto
                if (!isReportModalOpen) {
                console.log('Modal ya existe, limpiando backdrops residuales...');
                const backdrops = document.querySelectorAll('.modal-backdrop');
                    // Solo eliminar backdrops que no sean del modal de edición
                backdrops.forEach(backdrop => {
                        if (!backdrop.classList.contains('report-editor-backdrop')) {
                    try {
                        backdrop.remove();
                    } catch (removeError) {
                        console.warn('Error al remover backdrop residual:', removeError);
                            }
                    }
                });
                }
            }
            
            if (!modalElement) {
                this.showError('No se pudo crear el modal de gestión de plantillas');
                return;
            }
            
            // Configurar z-index más alto si hay un modal abierto debajo
            if (isReportModalOpen) {
                // Asegurar que el modal de plantillas tenga z-index más alto
                modalElement.style.zIndex = '1055'; // Bootstrap default es 1055, pero lo aumentamos si hay otro modal
                const reportModalZIndex = window.getComputedStyle(reportEditorModal).zIndex;
                if (reportModalZIndex && !isNaN(parseInt(reportModalZIndex))) {
                    modalElement.style.zIndex = (parseInt(reportModalZIndex) + 10).toString();
                }
                console.log('✅ Configurando z-index del modal de plantillas:', modalElement.style.zIndex);
            }
            
            // Verificar que el elemento modal existe y tiene las propiedades necesarias
            if (!modalElement || !modalElement.classList.contains('modal')) {
                console.error('Elemento modal no válido:', modalElement);
                this.showError('Error al crear el modal de gestión de plantillas');
                return;
            }
            
            // Limpiar cualquier instancia previa del modal específico
            const existingModal = bootstrap.Modal.getInstance(modalElement);
            if (existingModal) {
                console.log('Limpiando instancia previa del modal de plantillas...');
                try {
                    existingModal.dispose();
                } catch (disposeError) {
                    console.warn('Error al limpiar modal previo:', disposeError);
                }
            }
            
            // Mostrar el modal
            try {
                // Verificar que Bootstrap está disponible
                if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                    throw new Error('Bootstrap Modal no está disponible');
                }
                
                // Verificar que el elemento modal está en el DOM
                if (!document.body.contains(modalElement)) {
                    throw new Error('El elemento modal no está en el DOM');
                }
                
                // Esperar un momento para asegurar que el DOM esté completamente listo
                await new Promise(resolve => setTimeout(resolve, 50));
                
                // Limpiar TODOS los backdrops antes de mostrar el modal
                const allBackdrops = document.querySelectorAll('.modal-backdrop');
                allBackdrops.forEach(backdrop => backdrop.remove());
                
                // Determinar z-index apropiado
                let modalZIndex = 1070; // z-index por defecto del modal de plantillas
                if (isReportModalOpen) {
                    const reportModalZIndex = window.getComputedStyle(reportEditorModal).zIndex;
                    if (reportModalZIndex && !isNaN(parseInt(reportModalZIndex))) {
                        modalZIndex = parseInt(reportModalZIndex) + 10;
                    }
                }
                modalElement.style.zIndex = modalZIndex.toString();
                
                // Mostrar el modal usando la función segura con backdrop adecuado
                setTimeout(() => {
                    const success = this.showModalSafely(modalElement, isReportModalOpen);
                    if (success) {
                        console.log('✅ Gestor de plantillas abierto correctamente (encima del modal de edición)');
                        
                        // Asegurar z-index correcto después de que el modal se muestre
                        modalElement.addEventListener('shown.bs.modal', () => {
                            this.ensureModalZIndex(modalElement, modalZIndex);
                        }, { once: true });
                        
                        // También aplicar fix inmediatamente
                        this.ensureModalZIndex(modalElement, modalZIndex);
                    } else {
                        this.showError('Error al abrir el gestor de plantillas');
                    }
                }, 100);
                
            } catch (error) {
                console.error('Error al mostrar el modal:', error);
                this.showError('Error al abrir el gestor de plantillas: ' + error.message);
            }
        } catch (error) {
            console.error('Error abriendo gestor de plantillas:', error);
            this.showError('Error al abrir el gestor de plantillas: ' + error.message);
        }
    },

    /**
     * Limpiar completamente el estado del modal
     */
    cleanupModalState: function() {
        try {
            // Limpiar específicamente el modal de plantillas
            const templateModal = document.getElementById('templateManagerModal');
            if (templateModal) {
                const instance = bootstrap.Modal.getInstance(templateModal);
                if (instance) {
                    try {
                        instance.dispose();
                    } catch (disposeError) {
                        console.warn('Error al limpiar instancia de modal de plantillas:', disposeError);
                    }
                }
            }
            
            // Limpiar todos los backdrops específicamente
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                try {
                    backdrop.remove();
                } catch (removeError) {
                    console.warn('Error al remover backdrop:', removeError);
                }
            });
            
            // Remover clases del body de manera segura
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Limpiar cualquier elemento modal huérfano
            const orphanModals = document.querySelectorAll('.modal.show');
            orphanModals.forEach(modal => {
                modal.classList.remove('show');
                modal.style.display = 'none';
            });
            
            console.log('Estado del modal limpiado completamente');
        } catch (error) {
            console.error('Error limpiando estado del modal:', error);
        }
    },

    /**
     * Configurar limpieza automática cuando se cierra el modal
     */
    setupModalCleanup: function(modalElement) {
        if (!modalElement) return;
        
        // Limpiar cuando se oculta el modal (solo una vez)
        modalElement.addEventListener('hidden.bs.modal', () => {
            console.log('Modal cerrado, limpiando estado...');
            setTimeout(() => {
                // Solo limpiar si no hay otros modales abiertos
                const openModals = document.querySelectorAll('.modal.show');
                if (openModals.length === 0) {
                    this.cleanupModalState();
                }
            }, 200);
        }, { once: true });
        
        // Verificar estado cuando se muestra el modal (solo verificar, no limpiar)
        modalElement.addEventListener('show.bs.modal', () => {
            console.log('Modal abriéndose, verificando estado...');
            // Solo limpiar backdrops residuales, no instancias
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 0) {
                console.log('Removiendo backdrops residuales...');
                backdrops.forEach(backdrop => {
                    try {
                        backdrop.remove();
                    } catch (removeError) {
                        console.warn('Error al remover backdrop residual:', removeError);
                    }
                });
            }
        }, { once: true });
    },

    /**
     * Mostrar modal de manera segura usando jQuery si está disponible
     */
    showModalSafely: function(modalElement, stackOnTop = false) {
        try {
            // Si hay otro modal abierto, usar backdrop: true (no static) para permitir stacking
            const modalOptions = stackOnTop ? {
                backdrop: true, // Permite que se apile encima de otro modal
                keyboard: true, // Permitir cerrar con ESC
                focus: true
            } : {
                    backdrop: 'static',
                    keyboard: false,
                focus: true
            };
            
            // Fallback a Bootstrap nativo
            console.log('Usando Bootstrap nativo para mostrar modal...', modalOptions);
            const modal = new bootstrap.Modal(modalElement, modalOptions);
            modal.show();
            return true;
            
        } catch (error) {
            console.error('Error mostrando modal:', error);
            return false;
        }
    },

    /**
     * Crear modal de gestión de plantillas
     */
    createTemplateManagerModal: function() {
        const container = document.getElementById('templateManagerModalContainer');
        if (!container) {
            console.error('Container templateManagerModalContainer no encontrado');
            return;
        }

        // Verificar si el modal ya existe
        if (document.getElementById('templateManagerModal')) {
            console.log('Modal de gestión de plantillas ya existe, limpiando antes de recrear...');
            
            // Limpiar instancia existente
            const existingModal = bootstrap.Modal.getInstance(document.getElementById('templateManagerModal'));
            if (existingModal) {
                try {
                    existingModal.dispose();
                } catch (disposeError) {
                    console.warn('Error al limpiar modal existente:', disposeError);
                }
            }
            
            // Remover el modal del DOM
            const existingModalElement = document.getElementById('templateManagerModal');
            if (existingModalElement) {
                existingModalElement.remove();
            }
            
            // Limpiar backdrops residuales
            const existingBackdrops = document.querySelectorAll('.modal-backdrop');
            existingBackdrops.forEach(backdrop => backdrop.remove());
            
            // Remover clase modal-open del body
            document.body.classList.remove('modal-open');
        }

        if (window.TemplateManagerModule) {
            try {
            const modalHtml = window.TemplateManagerModule.createTemplateManagerModal();
            container.innerHTML = modalHtml;
            
                // Esperar un momento para que el DOM se actualice
                setTimeout(() => {
            // Configurar event listeners
            window.TemplateManagerModule.setupModalEventListeners();
                    
                    // Configurar limpieza automática
                    const modalElement = document.getElementById('templateManagerModal');
                    if (modalElement) {
                        this.setupModalCleanup(modalElement);
                    }
            
            console.log('Modal de gestión de plantillas creado correctamente');
                }, 50);
            } catch (error) {
                console.error('Error creando modal de plantillas:', error);
                this.showError('Error al crear el modal de gestión de plantillas: ' + error.message);
            }
        } else {
            console.error('TemplateManagerModule no está disponible');
            this.showError('Módulo de gestión de plantillas no está disponible');
        }
    },

    /**
     * Mostrar modal de confirmación de navegación
     */
    showNavigationConfirmModal() {
        const modal = new bootstrap.Modal(document.getElementById('navigationConfirmModal'));
        modal.show();
    },

    /**
     * Manejar "Permanecer en la página"
     */
    handleStayOnPage() {
        this.state.pendingNavigationUrl = null;
        const modal = bootstrap.Modal.getInstance(document.getElementById('navigationConfirmModal'));
        modal.hide();
        console.log('Usuario decidió permanecer en la página');
    },

    /**
     * Manejar "Guardar y salir"
     */
    async handleSaveAndLeave() {
        try {
            // Guardar el informe primero
            await this.saveReport();
            
            // Limpiar estado
            this.clearChangesState();
            
            // Cerrar modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('navigationConfirmModal'));
            modal.hide();
            
            // Navegar a la URL pendiente
            if (this.state.pendingNavigationUrl) {
                window.location.href = this.state.pendingNavigationUrl;
            }
            
            console.log('Informe guardado y navegando...');
        } catch (error) {
            console.error('Error guardando informe:', error);
            this.showError('Error al guardar el informe. No se puede navegar.');
        }
    },

    /**
     * Manejar "Salir sin guardar"
     */
    handleLeaveWithoutSaving() {
        // Limpiar estado
        this.clearChangesState();
        
        // Cerrar modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('navigationConfirmModal'));
        modal.hide();
        
        // Navegar a la URL pendiente
        if (this.state.pendingNavigationUrl) {
            window.location.href = this.state.pendingNavigationUrl;
        }
        
        console.log('Usuario decidió salir sin guardar');
    },

    /**
     * Configurar detección de cambios en el modal
     */
    setupModalChangeDetection() {
        // Detectar cambios en TinyMCE (solo contenido del informe)
        if (this.state.tinymceEditor) {
            this.state.tinymceEditor.on('change', () => {
                this.markAsModified();
                // Activar autosave después de cambios
                this.startAutoSave();
            });

            // También detectar cambios en título del informe
            const titleField = document.getElementById('reportTitle');
            if (titleField) {
                titleField.addEventListener('input', () => {
                    this.markAsModified();
                    this.startAutoSave();
                });
            }
        }

        // Los campos de estado y notas NO deben activar la advertencia de navegación
        // ya que estos cambios se guardan automáticamente y no requieren confirmación
        const modalFields = ['reportStatus', 'reportNotes'];
        modalFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', () => {
                    // Solo marcar como modificado si hay cambios en el contenido principal
                    // Los cambios de estado/notas se guardan automáticamente
                    console.log(`Campo ${fieldId} cambiado - no activando advertencia de navegación`);
                });
            }
        });
    },

    /**
     * Marcar como modificado (con debounce)
     * Solo marca si realmente hay cambios después de verificar con los datos originales
     * Usa debounce para evitar marcar en cada evento de teclado
     */
    markAsModified() {
        // Limpiar timeout anterior si existe
        if (this.state.markModifiedTimeout) {
            clearTimeout(this.state.markModifiedTimeout);
        }
        
        // Verificar cambios reales después de un pequeño delay (debounce)
        // Esto evita marcar como modificado solo por hacer clic o mover el cursor
        this.state.markModifiedTimeout = setTimeout(() => {
            // IMPORTANTE: Solo marcar hasUnsavedChanges si REALMENTE hay cambios
            // hasRealChanges() compara el contenido actual con el original
            const hasChanges = this.hasRealChanges();
            
            if (hasChanges) {
                this.state.hasUnsavedChanges = true;
                console.log('✅ Cambio REAL detectado - marcando como modificado (hasUnsavedChanges = true)');
            } else {
                // Si no hay cambios reales, NO marcar el flag
                // Esto previene falsos positivos cuando el usuario solo hace click o mueve el cursor
                if (this.state.hasUnsavedChanges) {
                    console.log('⚠️ Cambio detectado pero no hay cambios reales - limpiando flag');
                    this.state.hasUnsavedChanges = false;
                } else {
                    console.log('ℹ️ Cambio detectado pero no hay cambios reales - no se marca flag');
                }
            }
        }, 300); // 300ms de debounce para verificar cambios reales (reducido de 500ms para respuesta más rápida)
    },

    /**
     * Verificar si hay cambios reales en el contenido del informe
     */
    hasRealChanges() {
        // Si originalReportData no existe pero sí existe selectedReport, recrearlo
        if (!this.state.originalReportData && this.state.selectedReport) {
            console.warn('⚠️ hasRealChanges(): originalReportData no existe, recreando desde selectedReport');
            this.state.originalReportData = JSON.parse(JSON.stringify(this.state.selectedReport));
            
            // Obtener el contenido actual de TinyMCE y título para comparación correcta
            if (this.state.tinymceEditor) {
                this.state.originalReportData.contenido_html = this.state.tinymceEditor.getContent();
            }
            const titleField = document.getElementById('reportTitle');
            if (titleField) {
                this.state.originalReportData.titulo = titleField.value || this.state.selectedReport.titulo || '';
            }
            
            console.log('📋 originalReportData recreado:', {
                id: this.state.originalReportData?.id,
                titulo: this.state.originalReportData?.titulo,
                contenidoLength: (this.state.originalReportData?.contenido_html || '').length
            });
        }
        
        if (!this.state.originalReportData) {
            console.warn('⚠️ hasRealChanges(): originalReportData no existe y no se pudo recrear', {
                selectedReport: !!this.state.selectedReport,
                tinymceEditor: !!this.state.tinymceEditor
            });
            return false;
        }

        // Verificar cambios en contenido HTML
        let hasContentChanges = false;
        if (this.state.tinymceEditor) {
            const currentContent = this.state.tinymceEditor.getContent();
            const originalContent = this.state.originalReportData.contenido_html || '';
            
            // Normalizar contenido para comparación más precisa
            const normalizeContent = (content) => {
                return content
                    .replace(/\s+/g, ' ') // Normalizar espacios
                    .replace(/<p><br\s*\/?><\/p>/g, '') // Eliminar párrafos vacíos con br
                    .replace(/<p>\s*<\/p>/g, '') // Eliminar párrafos completamente vacíos
                    .replace(/<p>&nbsp;<\/p>/g, '') // Eliminar párrafos con solo &nbsp;
                    .replace(/<div><br\s*\/?><\/div>/g, '') // Eliminar divs vacíos con br
                    .replace(/<div>\s*<\/div>/g, '') // Eliminar divs completamente vacíos
                    .replace(/<div>&nbsp;<\/div>/g, '') // Eliminar divs con solo &nbsp;
                    .replace(/^\s*$/, '') // Eliminar contenido que solo sean espacios
                    .trim();
            };
            
            const normalizedCurrent = normalizeContent(currentContent);
            const normalizedOriginal = normalizeContent(originalContent);
            
            hasContentChanges = normalizedCurrent !== normalizedOriginal;
            
            if (hasContentChanges) {
                console.log('🔍 Cambios de contenido detectados:', {
                    currentLength: normalizedCurrent.length,
                    originalLength: normalizedOriginal.length,
                    currentPreview: normalizedCurrent.substring(0, 100),
                    originalPreview: normalizedOriginal.substring(0, 100)
                });
            }
        } else {
            console.warn('⚠️ hasRealChanges(): TinyMCE editor no disponible');
        }
        
        // Verificar cambios en título
        const titleField = document.getElementById('reportTitle');
        const currentTitle = titleField ? titleField.value.trim() : '';
        const originalTitle = (this.state.originalReportData.titulo || '').trim();
        const hasTitleChanges = currentTitle !== originalTitle;
        
        if (hasTitleChanges) {
            console.log('🔍 Cambios de título detectados:', {
                current: currentTitle,
                original: originalTitle
            });
        }
        
        const hasChanges = hasContentChanges || hasTitleChanges;
        
        if (hasChanges) {
            console.log('✅ Cambios REALES detectados en informe:', {
                contenido: hasContentChanges,
                titulo: hasTitleChanges
            });
        }
        
        return hasChanges;
    },

    /**
     * Marcar como guardado
     */
    markAsSaved() {
        this.state.hasUnsavedChanges = false;
        // Actualizar datos originales con los datos actuales para que no se detecten como cambios
        if (this.state.selectedReport && this.state.tinymceEditor) {
            // Usar una copia del selectedReport actualizado (que ya tiene los datos guardados)
            this.state.originalReportData = JSON.parse(JSON.stringify(this.state.selectedReport));
            // Asegurar que el contenido HTML coincida exactamente con lo que TinyMCE tiene
            this.state.originalReportData.contenido_html = this.state.tinymceEditor.getContent();
            
            // También actualizar el título original desde el campo (puede haber sido actualizado)
            const titleField = document.getElementById('reportTitle');
            if (titleField) {
                this.state.originalReportData.titulo = titleField.value || this.state.selectedReport.titulo || '';
            }
            
            console.log('📋 [markAsSaved] Estado actualizado:', {
                id: this.state.originalReportData.id,
                titulo: this.state.originalReportData.titulo,
                contenidoLength: this.state.originalReportData.contenido_html.length,
                hasUnsavedChanges: this.state.hasUnsavedChanges,
                allowModalClose: this.state.allowModalClose
            });
        }
        // Cancelar autosave pendiente (aunque está desactivado, mantener limpieza)
        if (this.state.autoSaveTimeout) {
            clearTimeout(this.state.autoSaveTimeout);
            this.state.autoSaveTimeout = null;
        }
        // Limpiar timeout de verificación de cambios
        if (this.state.markModifiedTimeout) {
            clearTimeout(this.state.markModifiedTimeout);
            this.state.markModifiedTimeout = null;
        }
        console.log('✅ [markAsSaved] Informe marcado como guardado - navegación libre');
    },

    /**
     * Limpiar estado de cambios
     */
    clearChangesState() {
        this.state.hasUnsavedChanges = false;
        this.state.originalReportData = null;
        // Detener autosave si estaba activo
        if (this.state.autoSaveTimeout) {
            clearTimeout(this.state.autoSaveTimeout);
            this.state.autoSaveTimeout = null;
        }
        if (this.state.autoSaveInterval) {
            clearInterval(this.state.autoSaveInterval);
            this.state.autoSaveInterval = null;
        }
        // Limpiar timeout de verificación de cambios
        if (this.state.markModifiedTimeout) {
            clearTimeout(this.state.markModifiedTimeout);
            this.state.markModifiedTimeout = null;
        }
        console.log('Estado de cambios limpiado - navegación libre');
    },

    /**
     * Iniciar autosave del informe (debounce)
     * DESACTIVADO: El guardado solo se realiza al hacer click en "Guardar Cambios"
     */
    startAutoSave() {
        // DESACTIVADO: No se guarda automáticamente
        // El guardado solo ocurre cuando el usuario hace click en el botón "Guardar Cambios"
        console.log('⚠️ Autosave desactivado - solo se guarda al hacer click en "Guardar Cambios"');
        return;
        
        // Código original comentado (por si se necesita reactivar en el futuro):
        /*
        // Si ya hay un timeout de autosave pendiente, cancelarlo
        if (this.state.autoSaveTimeout) {
            clearTimeout(this.state.autoSaveTimeout);
        }

        // Configurar nuevo autosave después de 3 segundos sin cambios
        this.state.autoSaveTimeout = setTimeout(async () => {
            if (this.state.hasUnsavedChanges && this.hasRealChanges()) {
                console.log('💾 Autosave: Guardando informe automáticamente...');
                try {
                    await this.autoSaveReport();
                } catch (error) {
                    console.error('Error en autosave:', error);
                    // No mostrar error al usuario, solo loggear
                }
            }
        }, 3000); // 3 segundos de inactividad antes de guardar
        */
    },

    /**
     * Guardar informe automáticamente (sin cerrar modal)
     * DESACTIVADO: El guardado solo se realiza al hacer click en "Guardar Cambios"
     */
    autoSaveReport: async function() {
        // DESACTIVADO: No se guarda automáticamente
        // El guardado solo ocurre cuando el usuario hace click en el botón "Guardar Cambios"
        console.log('⚠️ Autosave desactivado - solo se guarda al hacer click en "Guardar Cambios"');
        return;
        
        // Código original comentado (por si se necesita reactivar en el futuro):
        /*
        if (!this.state.selectedReport || !this.state.tinymceEditor) {
            return;
        }
        
        try {
            const contenido_html = this.state.tinymceEditor.getContent();
            
            // Capturar valores actuales del formulario
            const reportStatus = document.getElementById('reportStatus')?.value || this.state.selectedReport.estado;
            const reportNotes = document.getElementById('reportNotes')?.value || this.state.selectedReport.notas_revision;
            const reportTitle = document.getElementById('reportTitle')?.value || this.state.selectedReport.titulo;
            
            const reportData = {
                id: this.state.selectedReport.id,
                estudio_id: this.state.selectedReport.estudio_id,
                study_instance_uid: this.state.selectedReport.study_instance_uid || this.state.selectedReport.estudio_id,
                study_id: this.state.selectedReport.study_id || '',
                patient_id: this.state.selectedReport.patient_id,
                patient_name: this.state.selectedReport.patient_name,
                modality: this.state.selectedReport.modality,
                study_description: this.state.selectedReport.study_description,
                contenido_html: contenido_html,
                titulo: reportTitle,
                estado: reportStatus,
                notas_revision: reportNotes
            };
            
            const token = this.getSessionToken();
            reportData.session_token = token;
            const response = await fetch(`${this.config.apiBaseUrl}/save.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify(reportData)
            });
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                // Actualizar datos originales para que ya no se considere como "cambios no guardados"
                this.state.originalReportData = JSON.parse(JSON.stringify(reportData));
                this.state.originalReportData.contenido_html = contenido_html;
                this.markAsSaved();
                console.log('✅ Autosave: Informe guardado automáticamente');
            } else {
                throw new Error(data.error || 'Error al guardar el informe');
            }
            
        } catch (error) {
            console.error('Error en autosave:', error);
            // No mostrar error al usuario durante autosave
        }
        */
    },

    /**
     * Mostrar advertencia de cambios no guardados al cerrar modal
     */
    showUnsavedChangesWarning() {
        // CRÍTICO: Si ya hay un modal de advertencia mostrándose, NO crear otro
        if (this.state.warningModalShowing) {
            console.log('⚠️ Modal de advertencia ya está mostrándose - ignorando llamada duplicada');
            return;
        }
        
        // Si ya existe un modal de advertencia en el DOM pero no está mostrándose, limpiarlo
        const existingModal = document.getElementById('unsavedChangesModal');
        if (existingModal) {
            // Verificar si está visible
            if (existingModal.classList.contains('show')) {
                console.log('⚠️ Modal de advertencia ya está visible - ignorando llamada duplicada');
                return;
            }
            // Si no está visible, limpiarlo
            const existingInstance = bootstrap.Modal.getInstance(existingModal);
            if (existingInstance) {
                existingInstance.dispose();
            }
            existingModal.remove();
        }
        
        // Marcar que el modal de advertencia se está mostrando
        this.state.warningModalShowing = true;
        
        // Limpiar backdrops residuales antes de crear el nuevo modal
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => {
            if (backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
        });
        
        // Crear modal de advertencia
        const modalHTML = `
            <div class="modal fade" id="unsavedChangesModal" tabindex="-1" aria-labelledby="unsavedChangesModalLabel" aria-hidden="true" 
                 style="z-index: 10010 !important; position: fixed !important;">
                <div class="modal-dialog modal-dialog-centered" style="z-index: 10011 !important; position: relative;">
                    <div class="modal-content" style="z-index: 10012 !important; pointer-events: auto !important;">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title" id="unsavedChangesModalLabel">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Cambios No Guardados
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Has realizado cambios en el informe que no han sido guardados.</p>
                            <p class="mb-0"><strong>¿Qué deseas hacer?</strong></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" id="cancelCloseModalBtn">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-danger" id="closeWithoutSavingBtn">
                                <i class="fas fa-trash-alt me-2"></i>Cerrar Sin Guardar
                            </button>
                            <button type="button" class="btn btn-primary" id="saveAndCloseBtn">
                                <i class="fas fa-save me-2"></i>Guardar y Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        const warningModal = document.getElementById('unsavedChangesModal');
        
        // Función de limpieza de backdrops
        const cleanupBackdrops = () => {
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
        
        // Configurar eventos de los botones
        document.getElementById('cancelCloseModalBtn').addEventListener('click', () => {
            const modal = bootstrap.Modal.getInstance(warningModal);
            if (modal) {
                // Limpiar flag ANTES de cerrar
                this.state.warningModalShowing = false;
                // Solo cerrar el modal de advertencia, NO el modal del informe
                // El usuario puede volver a editar o intentar cerrar de nuevo
                modal.hide();
            }
            // Limpiar backdrops del modal de advertencia
            setTimeout(cleanupBackdrops, 100);
        });
        
        document.getElementById('closeWithoutSavingBtn').addEventListener('click', async () => {
            const warningModalInstance = bootstrap.Modal.getInstance(warningModal);
            if (warningModalInstance) {
                // Limpiar flag ANTES de cerrar
                this.state.warningModalShowing = false;
                warningModalInstance.hide();
            }
            
            // Limpiar backdrops del modal de advertencia
            setTimeout(cleanupBackdrops, 100);
            
            // IMPORTANTE: Establecer flags ANTES de limpiar estado para permitir cierre sin advertencia
            this.state.allowModalClose = true; // Permitir cierre libre del modal del informe
            this.state.pendingModalClose = false; // No hay cierre pendiente
            
            // Limpiar estado antes de cerrar
            this.clearChangesState();
            
            // Pequeño delay para asegurar que el modal de advertencia se haya cerrado completamente
            setTimeout(() => {
                const reportModalEl = document.getElementById('reportEditorModal');
                if (reportModalEl) {
                    // Verificar que el modal del informe aún esté abierto
                    if (reportModalEl.classList.contains('show')) {
                        const reportModalInstance = bootstrap.Modal.getInstance(reportModalEl);
                        if (reportModalInstance) {
                            // Cerrar el modal del informe - NO debería disparar la advertencia porque allowModalClose = true
                            reportModalInstance.hide();
                        } else {
                            // Si no hay instancia, crear una nueva y cerrarla
                            const newModal = new bootstrap.Modal(reportModalEl);
                            newModal.hide();
                        }
                    }
                }
                // Limpiar backdrops después de cerrar el modal del informe
                setTimeout(cleanupBackdrops, 100);
            }, 300); // Aumentar delay para asegurar que todo se haya procesado
        });
        
        document.getElementById('saveAndCloseBtn').addEventListener('click', async () => {
            const modal = bootstrap.Modal.getInstance(warningModal);
            if (modal) {
                // Limpiar flag ANTES de cerrar
                this.state.warningModalShowing = false;
                modal.hide();
            }
            // Limpiar backdrops del modal de advertencia
            setTimeout(cleanupBackdrops, 100);
            
            // IMPORTANTE: Establecer flags ANTES de guardar para permitir cierre sin advertencia
            this.state.allowModalClose = true; // Permitir cierre libre después de guardar
            this.state.pendingModalClose = false; // No hay cierre pendiente
            
            // Guardar y cerrar (saveReport() también establece allowModalClose = true, pero lo hacemos aquí por seguridad)
            await this.saveReport();
        });
        
        // Configurar cleanup cuando el modal se cierre
        warningModal.addEventListener('hidden.bs.modal', () => {
            cleanupBackdrops();
            // Limpiar instancia del modal
            try {
                const instance = bootstrap.Modal.getInstance(warningModal);
                if (instance) {
                    instance.dispose();
                }
            } catch (e) {
                console.warn('Error limpiando instancia del modal de advertencia:', e);
            }
            // CRÍTICO: Limpiar el flag para permitir que se muestre nuevamente en el futuro
            this.state.warningModalShowing = false;
            console.log('✅ Flag warningModalShowing limpiado - modal de advertencia cerrado');
        }, { once: true });
        
        // Esperar un momento para asegurar que el DOM esté listo
        setTimeout(() => {
            // Mostrar el modal de advertencia
            const modal = new bootstrap.Modal(warningModal, {
                backdrop: true, // Permitir backdrop pero con z-index correcto
                keyboard: false // No cerrar con ESC
            });
            modal.show();
            
            // Asegurar que el backdrop del modal de advertencia tenga z-index correcto
            setTimeout(() => {
                const warningBackdrop = document.querySelector('.modal-backdrop:last-child');
                if (warningBackdrop && !warningBackdrop.classList.contains('unsaved-changes-backdrop')) {
                    warningBackdrop.style.zIndex = '10009'; // Debajo del modal pero encima de otros
                    warningBackdrop.classList.add('unsaved-changes-backdrop');
                }
            }, 50);
        }, 100);
    },

    // Inicializar TinyMCE editor
    initTinyMCE: async function() {
        try {
            // Verificar disponibilidad de TinyMCE
            if (!window.tinymce) {
                throw new Error('TinyMCE no disponible');
            }

            // Si ya tenemos el editor, devolverlo
            if (this.state.tinymceEditor && this.state.tinymceEditor.initialized) {
                this.bindTinyMcePdfViewerClicks(this.state.tinymceEditor);
                return this.state.tinymceEditor;
            }

            // Si ya existe un editor asociado al textarea, reutilizarlo
            const existing = tinymce.get('reportContent');
            if (existing) {
                this.state.tinymceEditor = existing;
                this.bindTinyMcePdfViewerClicks(existing);
                return existing;
            }

            // Inicializar TinyMCE
            const editors = await tinymce.init({
                selector: '#reportContent',
                height: 500,
                language: 'es', // Usar español
                language_url: '../js/tinymce/langs/es.js', // Ruta al archivo local de idioma
                menubar: 'file edit view insert format tools table help',
                plugins: 'lists link table code autosave fullscreen', // Agregar fullscreen
                toolbar: 'undo redo | bold italic underline | alignleft aligncenter alignright | bullist numlist | link table | code | fullscreen', // Agregar botón fullscreen
                autosave_interval: '20s',
                autosave_restore_when_empty: true,
                autosave_ask_before_unload: false, // CRÍTICO: Desactivar popup de beforeunload de TinyMCE
                branding: false,
                setup: (editor) => {
                    editor.on('init', () => {
                        // Guardar referencia al editor
                        this.state.tinymceEditor = editor;
                        console.log('Editor TinyMCE inicializado correctamente en español');
                        
                        // Configurar hotkeys del reproductor en TinyMCE
                        this.configureTinyMCEHotkeys(editor);
                        // Ver PDF en iframe del editor (incluye modo solo lectura; ver bindTinyMcePdfViewerClicks)
                        this.bindTinyMcePdfViewerClicks(editor);
                    });
                }
            });

            if (editors && editors.length > 0) {
                // Asegurar referencia en estado
                this.state.tinymceEditor = editors[0];
                this.bindTinyMcePdfViewerClicks(editors[0]);
                return editors[0];
            }

            throw new Error('No se pudo inicializar TinyMCE');
        } catch (err) {
            console.error('Error inicializando TinyMCE:', err);
            this.showError('Error inicializando editor: ' + (err && err.message ? err.message : err));
            throw err;
        }
    },

    // Cargar informes con paginación y filtros
    loadReports: async function(page = 1) {
        try {
            this.state.isLoading = true;
            this.setSearchBtnLoading(true);

            this.state.currentPage = page;

            const token = this.getSessionToken();
            const fetchListWithRetry = async (url, options, maxRetries = 1) => {
                let lastResponse = null;
                for (let attempt = 0; attempt <= maxRetries; attempt++) {
                    const requestUrl = attempt === 0
                        ? url
                        : `${url}${url.includes('?') ? '&' : '?'}_rt=${Date.now()}`;
                    const response = await fetch(requestUrl, options);
                    lastResponse = response;
                    // Reintento puntual para 502 (intermitencia de gateway/upstream)
                    if (response.status !== 502) {
                        return response;
                    }
                    if (attempt < maxRetries) {
                        await new Promise(resolve => setTimeout(resolve, 350));
                    }
                }
                return lastResponse;
            };
            
            // Cargar informes incompletos con los mismos filtros de fecha que los normales
            // Esto asegura que se respete el filtro de fecha por defecto de los últimos 7 días
            let incompleteInformes = [];
            const hasDateFilters = this.state.currentFilters.fecha_inicio || this.state.currentFilters.fecha_fin;
            
            if (hasDateFilters) {
                try {
                    // Cargar informes incompletos CON los mismos filtros de fecha
                    const incompleteParams = new URLSearchParams({
                        page: 1,
                        per_page: 1000, // Cargar muchos para asegurar que se obtengan todos los incompletos
                        estado: 'incompletos'
                    });
                    
                    // Incluir filtros de fecha para que se respete el filtro por defecto
                    if (this.state.currentFilters.fecha_inicio) {
                        incompleteParams.append('fecha_inicio', this.state.currentFilters.fecha_inicio);
                    }
                    if (this.state.currentFilters.fecha_fin) {
                        incompleteParams.append('fecha_fin', this.state.currentFilters.fecha_fin);
                    }
                    
                    // Incluir otros filtros que no sean de fecha
                    if (this.state.currentFilters.search) {
                        incompleteParams.append('search', this.state.currentFilters.search);
                    }
                    if (this.state.currentFilters.patient_name) {
                        incompleteParams.append('patient_name', this.state.currentFilters.patient_name);
                    }
                    if (this.state.currentFilters.modality) {
                        incompleteParams.append('modality', this.state.currentFilters.modality);
                    }
                    if (this.state.currentFilters.filter_usuario_id) {
                        incompleteParams.append('filter_usuario_id', this.state.currentFilters.filter_usuario_id);
                    }
                    
                    // Incluir parámetros de ordenamiento si existen
                    if (this.state.sortBy) {
                        incompleteParams.append('sort_by', this.state.sortBy);
                        incompleteParams.append('sort_order', this.state.sortOrder || 'desc');
                    }
                    
                    const incompleteResponse = await fetchListWithRetry(`${this.config.apiBaseUrl}/list.php?${incompleteParams.toString()}`, {
                        method: 'GET',
                        headers: {
                            ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                            'Content-Type': 'application/json'
                        }
                    }, 1);
                    
                    if (incompleteResponse.ok) {
                        const incompleteData = await incompleteResponse.json();
                        if (incompleteData.success && incompleteData.data && incompleteData.data.informes) {
                            incompleteInformes = incompleteData.data.informes.map(informe => {
                                return {
                                    ...informe,
                                    fecha_creacion_formatted: this.formatDate(informe.fecha_creacion),
                                    fecha_modificacion_formatted: this.formatDate(informe.fecha_modificacion),
                                    estado_badge: this.getEstadoBadgeColor(informe.estado),
                                    is_incomplete: true // Marcar como incompleto
                                };
                            });
                            
                            console.log(`📋 Informes incompletos cargados: ${incompleteInformes.length}`);
                        }
                    }
                } catch (error) {
                    console.warn('Error cargando informes incompletos:', error);
                    // Continuar con la carga normal si falla
                }
            }
            
            // Cargar informes normales con todos los filtros (incluyendo fechas)
            const params = new URLSearchParams({
                page: this.state.currentPage,
                per_page: this.state.perPage,
                ...this.state.currentFilters
            });
            
            // Agregar parámetros de ordenamiento si existen
            if (this.state.sortBy) {
                params.append('sort_by', this.state.sortBy);
                params.append('sort_order', this.state.sortOrder || 'desc');
                console.log('🔄 [loadReports] Parámetros de ordenamiento agregados:', {
                    sort_by: this.state.sortBy,
                    sort_order: this.state.sortOrder || 'desc'
                });
            } else {
                console.log('⚠️ [loadReports] No hay parámetros de ordenamiento (sortBy es null)');
            }
            
            const paramsString = params.toString();
            console.log('🔄 [loadReports] URL completa de la petición:', `${this.config.apiBaseUrl}/list.php?${paramsString}`);

            const response = await fetchListWithRetry(`${this.config.apiBaseUrl}/list.php?${paramsString}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            }, 1);

            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Error al cargar los informes');
            }

                // Procesar datos antes del renderizado
                let processedInformes = data.data.informes.map(informe => {
                    return {
                        ...informe,
                        // Formatear fechas
                        fecha_creacion_formatted: this.formatDate(informe.fecha_creacion),
                        fecha_modificacion_formatted: this.formatDate(informe.fecha_modificacion),
                        // Determinar color del badge según el estado
                        estado_badge: this.getEstadoBadgeColor(informe.estado)
                    };
                });
                
                // Si hay informes incompletos cargados, combinarlos con los normales (sin duplicados)
                if (incompleteInformes.length > 0) {
                    const normalStudyIds = new Set();
                    processedInformes.forEach(informe => {
                        const studyId = informe.study_id || informe.study_instance_uid || informe.estudio_id;
                        if (studyId) {
                            normalStudyIds.add(studyId);
                        }
                    });
                    
                    // Agregar solo los incompletos que no están ya en los normales
                    incompleteInformes.forEach(informe => {
                        const studyId = informe.study_id || informe.study_instance_uid || informe.estudio_id;
                        if (!normalStudyIds.has(studyId)) {
                            processedInformes.push(informe);
                        }
                    });
                    
                    console.log(`📋 Total informes después de combinar: ${processedInformes.length} (${incompleteInformes.length} incompletos + ${data.data.informes.length} normales)`);
                    
                    // Aplicar ordenamiento después de combinar los informes
                    if (this.state.sortBy) {
                        this.sortProcessedInformes(processedInformes);
                    }
                }

                // Guardar permiso canViewAll del usuario
                if (data.data.user_permissions) {
                    this.state.canViewAll = data.data.user_permissions.can_view_all || false;
                    this.state.canSendToPacs = data.data.user_permissions.can_send_to_pacs || false;
                    this.state.canTogglePacsFormat = !!data.data.user_permissions.can_toggle_pacs_format;
                    this.state.currentUserId = data.data.user_permissions.user_id != null ? data.data.user_permissions.user_id : this.state.currentUserId;
                    this.state.canDatosCobranza = !!data.data.user_permissions.can_datos_cobranza;
                    this.state.cobranzaColumnsInstalled = data.data.user_permissions.cobranza_columns_installed !== false;
                    this.updateCobranzaToolbarVisibility();
                }

                this.mergeInformeUsuarioFilterFromResults(processedInformes);
                this.rebuildInformeUsuarioFilterSelect();
                this.updateInformeUsuarioFilterWrapVisibility();
                this.updatePacsFormatToggleVisibility();

                // SIEMPRE cargar flags antes de renderizar para poder ordenar y filtrar correctamente
                // Obtener study_ids de los informes para cargar flags
                console.log('🔍 [loadReports] Iniciando carga de flags antes de renderizar...');
                const studyIds = new Set();
                const studyIdMap = {}; // Mapa para guardar todos los IDs posibles de cada informe
                
                processedInformes.forEach(informe => {
                    const studyId = informe.study_id || informe.study_instance_uid || informe.estudio_id;
                    if (studyId) {
                        studyIds.add(studyId);
                        // Guardar todos los IDs posibles para este informe
                        studyIdMap[studyId] = {
                            study_id: informe.study_id,
                            study_instance_uid: informe.study_instance_uid,
                            orthanc_id: informe.orthanc_id,
                            estudio_id: informe.estudio_id
                        };
                    }
                });
                
                console.log('🔍 [loadReports] Study IDs encontrados:', Array.from(studyIds));
                console.log('🔍 [loadReports] Study ID Map:', studyIdMap);
                
                if (studyIds.size > 0) {
                    console.log('🔍 [loadReports] Llamando a loadStudyFlagsForFilter...');
                    try {
                        await this.loadStudyFlagsForFilter(Array.from(studyIds), studyIdMap);
                        console.log('✅ [loadReports] loadStudyFlagsForFilter completado');
                    } catch (error) {
                        console.error('❌ [loadReports] Error en loadStudyFlagsForFilter:', error);
                    }
                    
                    // Cargar flags individuales por informe después de cargar flags generales
                    console.log('🔍 [loadReports] Cargando flags individuales por informe...');
                    try {
                        await this.loadIndividualReportFlags(processedInformes);
                        console.log('✅ [loadReports] Flags individuales cargados');
                    } catch (error) {
                        console.error('❌ [loadReports] Error cargando flags individuales:', error);
                    }
                    
                    console.log('📋 [loadReports] Flags cargados antes de renderizar:', Object.keys(this.state.studyFlags).length, 'flags');
                    console.log('📋 [loadReports] Claves de flags:', Object.keys(this.state.studyFlags));
                    console.log('📋 [loadReports] Study IDs buscados:', Array.from(studyIds));
                } else {
                    console.warn('⚠️ [loadReports] No se encontraron study IDs para cargar flags');
                }
                
                // Limpiar estado de procesamiento de informes que no están en la lista actual
                // Obtener IDs de informes cargados
                const loadedInformeIds = new Set(processedInformes.map(inf => inf.id));
                const informeIdsToRemove = [];
                this.state.removingFromPacs.forEach(id => {
                    if (!loadedInformeIds.has(id)) {
                        informeIdsToRemove.push(id);
                    }
                });
                informeIdsToRemove.forEach(id => {
                    this.state.removingFromPacs.delete(id);
                    console.log('🧹 Removido informe', id, 'del estado de procesamiento (no está en la lista actual)');
                });

                if (typeof QaQuickAction !== 'undefined' && QaQuickAction.enabled) {
                    await QaQuickAction.refreshInformeStatuses(processedInformes);
                }

                this.renderReports(processedInformes);
            this.renderPagination(data.data.pagination);
            
            // Cargar flags de informes incompletos para actualizar estilos de botones después de renderizar
            // Esto asegura que los botones se actualicen incluso si los flags se cargaron después del renderizado inicial
            await this.loadStudyFlags();
            
            console.log('📋 [loadReports] Flags finales después de cargar:', Object.keys(this.state.studyFlags).length, 'flags');
            console.log('📋 [loadReports] Claves de flags finales:', Object.keys(this.state.studyFlags));

            this.state.currentPage = data.data.pagination.current_page;
            this.state.totalPages = data.data.pagination.total_pages;
            // NO sobrescribir perPage con el valor del servidor
            // Mantener el valor por defecto (25) o el que el usuario haya seleccionado
            // Solo actualizar si el usuario explícitamente cambió el selector
            if (!this.state.perPageUserSet) {
                // En la primera carga, mantener el valor por defecto
                // No hacer nada, mantener this.state.perPage como está (25)
            } else {
                // Si el usuario ya cambió el selector, usar el valor del servidor como referencia
                // pero mantener el valor que el usuario seleccionó
            }
            this.state.totalResults = data.data.pagination.total_results || this.state.totalResults;
            
            // Actualizar iconos de ordenamiento después de renderizar
            this.updateSortIcons();
            
            // Si no hay filtros aplicados, actualizar el total sin filtros
            const hasFilters = Object.keys(this.state.currentFilters).some(key => {
                const value = this.state.currentFilters[key];
                return value !== null && value !== undefined && value !== '';
            });
            if (!hasFilters) {
                this.state.totalResultsWithoutFilters = this.state.totalResults;
            }
            
            // Actualizar selector de elementos por página
            const perPageSelect = document.getElementById('perPageSelect');
            if (perPageSelect) {
                perPageSelect.value = this.state.perPage;
            }
            
            // Guardar informes en el estado para acceso posterior
            this.state.reports = processedInformes;
            
            this.updateResultsCounter();
            
            // Actualizar estadísticas del banner
            this.updateBannerCounters();
            
            // Cargar total sin filtros si aún no se ha cargado
            if (this.state.totalResultsWithoutFilters === 0) {
                this.loadTotalWithoutFilters();
            }
        } catch (error) {
            console.error('Error cargando informes:', error);
            this.showError('Error al cargar los informes: ' + error.message);
        } finally {
            this.state.isLoading = false;
            this.setSearchBtnLoading(false);
        }
    },

    /**
     * Manejar búsqueda en tiempo real con debounce
     * Busca en el servidor para encontrar resultados en todas las páginas
     */
    handleRealtimeSearch(searchValue) {
        // Limpiar timeout anterior
        if (this.state.searchTimeout) {
            clearTimeout(this.state.searchTimeout);
        }

        const searchTerm = searchValue.trim();
        
        // Si el campo está vacío, recargar sin filtro de búsqueda
        if (!searchTerm) {
            // Si hay otros filtros aplicados, recargar desde servidor
            const hasOtherFilters = this.state.currentFilters.patient_name || 
                                   this.state.currentFilters.modality || 
                                   this.state.currentFilters.estado ||
                                   this.state.currentFilters.fecha_inicio ||
                                   this.state.currentFilters.fecha_fin ||
                                   this.state.currentFilters.filter_usuario_id;
            
            if (hasOtherFilters) {
                // Si hay otros filtros, recargar desde servidor
                this.state.currentFilters.search = '';
                this.performSearch();
            } else {
                // Limpiar filtro de búsqueda y recargar
                this.state.currentFilters.search = '';
                this.loadReports(1);
            }
            return;
        }

        // Configurar nuevo timeout para búsqueda en servidor con debounce (500ms)
        // Esto permite que el usuario termine de escribir antes de hacer la petición
        this.state.searchTimeout = setTimeout(() => {
            // Actualizar filtro de búsqueda y hacer búsqueda en servidor
            // Esto buscará en TODOS los registros, no solo en la página actual
            this.state.currentFilters.search = searchTerm;
            this.performSearch();
        }, 500);
    },

    /**
     * Filtrar informes localmente sin hacer peticiones al servidor
     */
    filterReportsLocally(searchTerm) {
        // Si no hay informes cargados, hacer búsqueda en servidor
        if (!this.state.reports || this.state.reports.length === 0) {
            this.state.currentFilters.search = searchTerm;
            this.performSearch();
            return;
        }

        // Filtrar localmente
        const filteredReports = this.state.reports.filter(informe => {
            if (!searchTerm) {
                return true; // Sin filtro, mostrar todos
            }

            const searchLower = searchTerm.toLowerCase();
            
            // Buscar en múltiples campos
            const searchableText = [
                informe.titulo || '',
                informe.patient_name || '',
                informe.patient_id || '',
                informe.estudio_id || '',
                informe.usuario_nombre || '',
                informe.usuario_apellido || ''
            ].join(' ').toLowerCase();

            return searchableText.includes(searchLower);
        });

        // Renderizar resultados filtrados
        this.renderReports(filteredReports);
        
        // Actualizar contador de resultados
        this.updateLocalResultsCounter(filteredReports.length, this.state.reports.length);
        
        // Actualizar contadores del banner (usar totales del servidor, no filtrados)
        this.updateBannerCounters();
    },

    /**
     * Actualizar contador de resultados para filtrado local
     */
    updateLocalResultsCounter(filteredCount, totalCount) {
        const counterElement = document.getElementById('resultsCounter');
        if (counterElement) {
            if (filteredCount < totalCount) {
                counterElement.textContent = `Mostrando ${filteredCount} de ${totalCount} informes (filtrado)`;
            } else {
                counterElement.textContent = `Mostrando ${totalCount} informes`;
            }
        }
    },

    /**
     * Texto corto del rol de usuario/informante (misma lógica que en la tabla).
     */
    formatInformeUsuarioRolShort(rol) {
        if (!rol) return '';
        if (rol === 'medico_informante') return 'Médico Informante';
        if (rol === 'transcriptor') return 'Transcriptor';
        if (rol === 'otro') return 'Otro';
        return String(rol);
    },

    /**
     * Acumula autores (usuario_id) vistos en los informes cargados para el desplegable.
     */
    mergeInformeUsuarioFilterFromResults(informes) {
        if (!informes || !informes.length) return;
        const map = this.state.informeUsuarioFilterById || {};
        informes.forEach(informe => {
            const uid = informe.usuario_id;
            if (uid === null || uid === undefined || uid === '') return;
            const key = String(uid);
            if (map[key]) return;
            const rolRaw = informe.usuario_rol || informe.medico_informante_rol || '';
            map[key] = {
                id: uid,
                nombre: (informe.usuario_nombre || '').trim(),
                apellido: (informe.usuario_apellido || '').trim(),
                rol: rolRaw
            };
        });
        this.state.informeUsuarioFilterById = map;
    },

    rebuildInformeUsuarioFilterSelect() {
        const sel = document.getElementById('filterUsuarioInforme');
        if (!sel) return;
        const prev = sel.value || '';
        const entries = Object.values(this.state.informeUsuarioFilterById || {});
        entries.sort((a, b) => {
            const la = `${a.apellido} ${a.nombre}`.trim().toLowerCase();
            const lb = `${b.apellido} ${b.nombre}`.trim().toLowerCase();
            return la.localeCompare(lb, 'es', { sensitivity: 'base' });
        });
        sel.innerHTML = '<option value="">Todos los autores</option>';
        entries.forEach(u => {
            const opt = document.createElement('option');
            opt.value = String(u.id);
            const namePart = [u.apellido, u.nombre].filter(Boolean).join(', ') || `Usuario #${u.id}`;
            const rolPart = this.formatInformeUsuarioRolShort(u.rol);
            opt.textContent = rolPart ? `${namePart} — ${rolPart}` : namePart;
            sel.appendChild(opt);
        });
        const still = prev && this.state.informeUsuarioFilterById[String(prev)];
        if (still) {
            sel.value = prev;
        } else {
            sel.value = '';
            if (this.state.currentFilters.filter_usuario_id) {
                delete this.state.currentFilters.filter_usuario_id;
            }
        }
    },

    updateInformeUsuarioFilterWrapVisibility() {
        const wrap = document.getElementById('filterUsuarioInformeWrap');
        if (!wrap) return;
        const n = Object.keys(this.state.informeUsuarioFilterById || {}).length;
        const filtroActivo = !!(this.state.currentFilters && this.state.currentFilters.filter_usuario_id);
        wrap.style.display = (this.state.canViewAll || n > 1 || filtroActivo) ? '' : 'none';
    },

    /**
     * Fecha local YYYY-MM-DD (misma idea que dashboard-unified / estudios-manager).
     */
    formatLocalYMD(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    },

    clearInformesQuickDateButtonStates() {
        document.querySelectorAll('.informes-quick-date').forEach((btn) => btn.classList.remove('active'));
    },

    setInformesQuickDateButtonActive(preset) {
        this.clearInformesQuickDateButtonStates();
        const map = {
            today: 'informesQuickDateToday',
            yesterday: 'informesQuickDateYesterday',
            last7: 'informesQuickDateLast7',
            last30: 'informesQuickDateLast30'
        };
        const id = preset ? map[preset] : null;
        if (id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('active');
        }
    },

    getInformesQuickDateRangeForPreset(preset) {
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
        } else if (preset === 'last30') {
            const end = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const start = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 29);
            fromStr = this.formatLocalYMD(start);
            toStr = this.formatLocalYMD(end);
        } else {
            return null;
        }
        return { fromStr, toStr };
    },

    /**
     * Lee el formulario de búsqueda al estado (sin vaciar el mapa de autores del desplegable).
     */
    syncCurrentFiltersFromSearchFormPreserveAuthors() {
        const g = (id) => (document.getElementById(id)?.value ?? '').trim();
        this.state.currentFilters = this.state.currentFilters || {};
        this.state.currentFilters.search = g('searchInput');
        this.state.currentFilters.patient_name = g('patientNameFilter');
        this.state.currentFilters.modality = g('modalityFilter');
        this.state.currentFilters.estado = g('estadoFilter');
        this.state.currentFilters.fecha_inicio = g('fechaInicio');
        this.state.currentFilters.fecha_fin = g('fechaFin');
        const usuarioSel = document.getElementById('filterUsuarioInforme');
        if (usuarioSel && usuarioSel.value) {
            this.state.currentFilters.filter_usuario_id = usuarioSel.value;
        } else {
            delete this.state.currentFilters.filter_usuario_id;
        }
        Object.keys(this.state.currentFilters).forEach((key) => {
            const v = this.state.currentFilters[key];
            if (v === '' || v === null || v === undefined) {
                delete this.state.currentFilters[key];
            }
        });
    },

    applyInformesQuickDatePreset(preset) {
        const range = this.getInformesQuickDateRangeForPreset(preset);
        if (!range) return;
        const fi = document.getElementById('fechaInicio');
        const ff = document.getElementById('fechaFin');
        if (fi) fi.value = range.fromStr;
        if (ff) ff.value = range.toStr;
        this.syncCurrentFiltersFromSearchFormPreserveAuthors();
        this.state.currentFilters.fecha_inicio = range.fromStr;
        this.state.currentFilters.fecha_fin = range.toStr;
        this.setInformesQuickDateButtonActive(preset);
        this.state.isInitialLoad = false;
        if (this.state.searchTimeout) {
            clearTimeout(this.state.searchTimeout);
            this.state.searchTimeout = null;
        }
        this.loadReports(1);
    },

    setupInformesQuickDatePresetButtons() {
        if (this.state.informesQuickDateButtonsBound) return;
        this.state.informesQuickDateButtonsBound = true;
        document.querySelectorAll('.informes-quick-date[data-informes-preset]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const preset = btn.getAttribute('data-informes-preset');
                if (preset) {
                    this.applyInformesQuickDatePreset(preset);
                }
            });
        });
        const clearActive = () => this.clearInformesQuickDateButtonStates();
        const fi = document.getElementById('fechaInicio');
        const ff = document.getElementById('fechaFin');
        if (fi && !fi.dataset.informesQuickDateClear) {
            fi.dataset.informesQuickDateClear = '1';
            fi.addEventListener('change', clearActive);
        }
        if (ff && !ff.dataset.informesQuickDateClear) {
            ff.dataset.informesQuickDateClear = '1';
            ff.addEventListener('change', clearActive);
        }
    },

    /**
     * Realizar búsqueda con filtros actuales
     */
    performSearch() {
        this.clearInformesQuickDateButtonStates();
        this.state.informeUsuarioFilterById = {};
        const usuarioSel = document.getElementById('filterUsuarioInforme');
        if (usuarioSel) {
            usuarioSel.innerHTML = '<option value="">Todos los autores</option>';
            usuarioSel.value = '';
        }
        this.updateInformeUsuarioFilterWrapVisibility();

        // Recopilar filtros del formulario
        this.state.currentFilters = {
            search: document.getElementById('searchInput')?.value || '',
            patient_name: document.getElementById('patientNameFilter')?.value || '',
            modality: document.getElementById('modalityFilter')?.value || '',
            estado: document.getElementById('estadoFilter')?.value || '',
            fecha_inicio: document.getElementById('fechaInicio')?.value || '',
            fecha_fin: document.getElementById('fechaFin')?.value || ''
        };
        
        // Filtrar valores vacíos
        Object.keys(this.state.currentFilters).forEach(key => {
            if (!this.state.currentFilters[key]) {
                delete this.state.currentFilters[key];
            }
        });
        
        this.updateInformeUsuarioFilterWrapVisibility();
        
        // Marcar que ya no es la carga inicial (el usuario está haciendo una búsqueda manual)
        this.state.isInitialLoad = false;
        
        // Cargar primera página con nuevos filtros
        this.loadReports(1);
    },

    /**
     * Aplicar filtro por defecto de los últimos 7 días en la carga inicial
     */
    applyDefaultDateFilter() {
        // Misma ventana que el atajo «7 días» (dashboard-unified): hoy y 6 días anteriores, fechas locales
        const end = new Date();
        const start = new Date(end.getFullYear(), end.getMonth(), end.getDate() - 6);
        const fechaFin = this.formatLocalYMD(end);
        const fechaInicio = this.formatLocalYMD(start);

        const fechaInicioInput = document.getElementById('fechaInicio');
        const fechaFinInput = document.getElementById('fechaFin');

        if (fechaInicioInput) {
            fechaInicioInput.value = fechaInicio;
        }
        if (fechaFinInput) {
            fechaFinInput.value = fechaFin;
        }

        this.state.currentFilters.fecha_inicio = fechaInicio;
        this.state.currentFilters.fecha_fin = fechaFin;

        this.setInformesQuickDateButtonActive('last7');

        console.log('📅 [applyDefaultDateFilter] Filtro por defecto: últimos 7 días (inclusive)', {
            fecha_inicio: fechaInicio,
            fecha_fin: fechaFin
        });

        this.state.isInitialLoad = false;
    },

    /**
     * Limpiar todos los filtros
     */
    clearFilters() {
        // Limpiar timeout de búsqueda si existe
        if (this.state.searchTimeout) {
            clearTimeout(this.state.searchTimeout);
            this.state.searchTimeout = null;
        }

        // Limpiar campos del formulario
        document.getElementById('searchInput').value = '';
        document.getElementById('patientNameFilter').value = '';
        document.getElementById('modalityFilter').value = '';
        document.getElementById('estadoFilter').value = '';
        document.getElementById('fechaInicio').value = '';
        document.getElementById('fechaFin').value = '';
        this.state.informeUsuarioFilterById = {};
        const usuarioSel = document.getElementById('filterUsuarioInforme');
        if (usuarioSel) {
            usuarioSel.innerHTML = '<option value="">Todos los autores</option>';
            usuarioSel.value = '';
        }
        this.updateInformeUsuarioFilterWrapVisibility();
        
        // Limpiar filtros del estado
        this.state.currentFilters = {};
        
        // Marcar que ya no es la carga inicial (para que no se vuelva a aplicar el filtro por defecto)
        this.state.isInitialLoad = false;

        this.clearInformesQuickDateButtonStates();
        
        // Recargar informes sin filtros
        this.loadReports(1);
    },

    /**
     * Actualizar contador de resultados
     */
    updateResultsCounter() {
        const resultsCountEl = document.getElementById('resultsCount');
        if (resultsCountEl) {
            // Contar las filas reales que se están mostrando en la tabla
            const tbody = document.getElementById('reportsTableBody');
            if (tbody) {
                // Contar solo las filas que no son el estado vacío ni encabezados de grupo
                const rows = tbody.querySelectorAll('tr');
                const actualRows = Array.from(rows).filter(row => {
                    // Excluir la fila de estado vacío
                    const colspan = row.querySelector('td[colspan]');
                    if (colspan) return false;
                    
                    // Excluir filas de encabezado de grupo
                    if (row.classList.contains('study-group-header')) return false;
                    
                    return true;
                }).length;
                
                // Contar estudios únicos (encabezados de grupo + filas individuales sin grupo)
                const groupHeaders = tbody.querySelectorAll('tr.study-group-header');
                const individualRows = Array.from(rows).filter(row => {
                    const colspan = row.querySelector('td[colspan]');
                    if (colspan) return false;
                    if (row.classList.contains('study-group-header')) return false;
                    // Verificar si tiene algún atributo data-group-id que indique que pertenece a un grupo
                    const hasGroupId = row.hasAttribute('data-group-id');
                    if (hasGroupId) return false;
                    // Verificar si tiene clase que empiece con study-group-row- (pertenece a un grupo)
                    const classList = Array.from(row.classList);
                    const belongsToGroup = classList.some(cls => cls.startsWith('study-group-row-'));
                    if (belongsToGroup) return false;
                    return true;
                }).length;
                
                // Estudios únicos = encabezados de grupo + filas individuales sin grupo
                const uniqueStudies = groupHeaders.length + individualRows;
                
                // Actualizar el contador con ambos valores
                resultsCountEl.innerHTML = `
                    <span class="badge bg-info me-2">${actualRows} informe${actualRows !== 1 ? 's' : ''} encontrado${actualRows !== 1 ? 's' : ''}</span>
                    <span class="badge bg-secondary">${uniqueStudies} estudio${uniqueStudies !== 1 ? 's' : ''} con informe${uniqueStudies !== 1 ? 's' : ''}</span>
                `;
            } else {
                // Fallback: usar la cantidad de informes en el estado si está disponible
                const count = this.state.reports ? this.state.reports.length : 0;
                resultsCountEl.textContent = `${count} informe${count !== 1 ? 's' : ''} encontrado${count !== 1 ? 's' : ''}`;
            }
        }
        
        // Actualizar contadores del banner
        this.updateBannerCounters();
    },

    /**
     * Actualizar contadores del banner (Informes Hoy y Total Informes)
     */
    updateBannerCounters() {
        // Contador de Total Informes (usar total sin filtros)
        const totalInformesEl = document.getElementById('totalInformes');
        if (totalInformesEl) {
            // Usar totalResultsWithoutFilters si está disponible, sino totalResults
            const totalToShow = this.state.totalResultsWithoutFilters > 0 
                ? this.state.totalResultsWithoutFilters 
                : this.state.totalResults;
            totalInformesEl.textContent = totalToShow || 0;
        }
        
        // Contador de Informes Hoy
        const informesHoyEl = document.getElementById('informesHoy');
        if (informesHoyEl && this.state.reports) {
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            
            const informesHoy = this.state.reports.filter(informe => {
                if (!informe.fecha_creacion) return false;
                const fechaCreacion = new Date(informe.fecha_creacion);
                fechaCreacion.setHours(0, 0, 0, 0);
                return fechaCreacion.getTime() === hoy.getTime();
            }).length;
            
            informesHoyEl.textContent = informesHoy;
        } else if (informesHoyEl) {
            // Si no hay informes cargados pero tenemos totalResults, intentar obtenerlo desde el servidor
            this.loadBannerStats();
        }

        if (this.state.canViewInformesRecibidos &&
            window.InformesRecibidosModal && typeof window.InformesRecibidosModal.refreshPendientesCount === 'function') {
            window.InformesRecibidosModal.refreshPendientesCount();
        }
    },

    /**
     * Cargar estadísticas del banner desde el servidor
     */
    async loadBannerStats() {
        try {
            const token = this.getSessionToken();
            const hoy = new Date().toISOString().split('T')[0];
            
            const params = new URLSearchParams({
                fecha_inicio: hoy,
                fecha_fin: hoy,
                page: 1,
                per_page: 1 // Solo necesitamos el contador
            }).toString();
            
            const response = await fetch(`${this.config.apiBaseUrl}/list.php?${params}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.data.pagination) {
                    const informesHoyEl = document.getElementById('informesHoy');
                    if (informesHoyEl) {
                        informesHoyEl.textContent = data.data.pagination.total_results || 0;
                    }
                }
            }
        } catch (error) {
            console.warn('Error cargando estadísticas del banner:', error);
        }
    },

    /**
     * Cargar el total de informes sin filtros aplicados
     */
    async loadTotalWithoutFilters() {
        try {
            const token = this.getSessionToken();
            // Hacer una consulta sin filtros adicionales (solo los filtros de usuario se aplican automáticamente)
            const params = new URLSearchParams({
                page: 1,
                per_page: 1 // Solo necesitamos el contador
            }).toString();
            
            const response = await fetch(`${this.config.apiBaseUrl}/list.php?${params}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.data.pagination) {
                    const totalFromServer = data.data.pagination.total_results || 0;
                    this.state.totalResultsWithoutFilters = totalFromServer;
                    // Actualizar el contador
                    const totalInformesEl = document.getElementById('totalInformes');
                    if (totalInformesEl) {
                        totalInformesEl.textContent = totalFromServer;
                    }
                    console.log('[InformesManager] Total sin filtros cargado:', totalFromServer);
                }
            } else {
                console.warn('[InformesManager] Error en respuesta al cargar total sin filtros:', response.status);
            }
        } catch (error) {
            console.warn('[InformesManager] Error cargando total sin filtros:', error);
        }
    },

    /**
     * Manejar ordenamiento de columnas
     */
    handleSort(sortBy) {
        console.log('🔄 [handleSort] Iniciando ordenamiento. Columna actual:', this.state.sortBy, 'Nueva columna:', sortBy);
        console.log('🔄 [handleSort] Orden actual:', this.state.sortOrder);
        
        // Si se hace clic en la misma columna, alternar el orden
        if (this.state.sortBy === sortBy) {
            this.state.sortOrder = this.state.sortOrder === 'asc' ? 'desc' : 'asc';
            console.log('🔄 [handleSort] Misma columna, alternando orden a:', this.state.sortOrder);
        } else {
            // Nueva columna, ordenar descendente por defecto
            this.state.sortBy = sortBy;
            this.state.sortOrder = 'desc';
            console.log('🔄 [handleSort] Nueva columna, orden descendente por defecto');
        }
        
        console.log('🔄 [handleSort] Estado final - sortBy:', this.state.sortBy, 'sortOrder:', this.state.sortOrder);
        
        // Actualizar iconos visuales
        this.updateSortIcons();
        
        // Recargar informes con el nuevo ordenamiento
        this.loadReports(1);
    },

    /**
     * Ordenar informes procesados localmente (cuando se combinan con incompletos)
     */
    sortProcessedInformes(informes) {
        if (!this.state.sortBy || !informes || informes.length === 0) {
            return;
        }
        
        const sortBy = this.state.sortBy;
        const sortOrder = this.state.sortOrder || 'desc';
        const multiplier = sortOrder === 'asc' ? 1 : -1;
        
        console.log('🔄 [sortProcessedInformes] Ordenando', informes.length, 'informes por', sortBy, 'en orden', sortOrder);
        
        informes.sort((a, b) => {
            let valueA, valueB;
            
            switch (sortBy) {
                case 'patient_name':
                    valueA = (a.patient_name || '').toLowerCase();
                    valueB = (b.patient_name || '').toLowerCase();
                    break;
                case 'modality':
                    valueA = (a.modality || '').toLowerCase();
                    valueB = (b.modality || '').toLowerCase();
                    break;
                case 'estado':
                    valueA = (a.estado || '').toLowerCase();
                    valueB = (b.estado || '').toLowerCase();
                    break;
                case 'usuario_nombre':
                    valueA = (a.usuario_nombre || '').toLowerCase();
                    valueB = (b.usuario_nombre || '').toLowerCase();
                    break;
                case 'fecha_creacion':
                    valueA = new Date(a.fecha_creacion || 0).getTime();
                    valueB = new Date(b.fecha_creacion || 0).getTime();
                    break;
                case 'fecha_modificacion':
                    valueA = new Date(a.fecha_modificacion || 0).getTime();
                    valueB = new Date(b.fecha_modificacion || 0).getTime();
                    break;
                default:
                    return 0;
            }
            
            if (valueA < valueB) return -1 * multiplier;
            if (valueA > valueB) return 1 * multiplier;
            return 0;
        });
        
        console.log('✅ [sortProcessedInformes] Informes ordenados');
    },

    /**
     * Actualizar iconos de ordenamiento en los encabezados de columna
     */
    updateSortIcons() {
        // Remover todos los iconos de ordenamiento activos
        document.querySelectorAll('.sortable .sort-icon').forEach(icon => {
            icon.className = 'fas fa-sort sort-icon';
        });
        
        // Si hay una columna ordenada, mostrar el icono correspondiente
        if (this.state.sortBy) {
            const activeHeader = document.querySelector(`.sortable[data-sort="${this.state.sortBy}"]`);
            if (activeHeader) {
                const icon = activeHeader.querySelector('.sort-icon');
                if (icon) {
                    if (this.state.sortOrder === 'asc') {
                        icon.className = 'fas fa-sort-up sort-icon';
                    } else {
                        icon.className = 'fas fa-sort-down sort-icon';
                    }
                }
            }
        }
    },

    /**
     * Renderizar lista de informes agrupados por estudio
     */
    renderReports(informes) {
        const tbody = document.getElementById('reportsTableBody');
        if (!tbody) return;
        
        // Verificar si el usuario tiene permiso para ver todos los informes
        const canViewAll = this.state.canViewAll || false;
        
        // Mostrar siempre la columna INFORMANTE (pero con diferentes datos según permisos)
        const informanteColumn = document.getElementById('informanteColumn');
        if (informanteColumn) {
            informanteColumn.style.display = ''; // Siempre visible
        }
        
        // Determinar si debemos mostrar el nombre del usuario de cada informe o el nombre del usuario actual
        // Si canViewAll es true: siempre mostrar el nombre del usuario que creó el informe
        // Si canViewAll es false pero el informe tiene usuario_nombre: mostrar el nombre del usuario que creó el informe
        // Si canViewAll es false y el informe NO tiene usuario_nombre: mostrar el nombre del usuario actual (fallback)
        // Esto permite que cuentas padre con "Gestión Informes" vean correctamente quién creó cada informe
        
        if (informes.length === 0) {
            const colspan = 8; // Siempre 8 columnas (incluyendo Informante)
            tbody.innerHTML = `
                <tr>
                    <td colspan="${colspan}" class="text-center py-4">
                        <i class="fas fa-search text-muted mb-2" style="font-size: 2rem;"></i>
                        <p class="text-muted mb-0">No se encontraron informes con los criterios especificados</p>
                    </td>
                </tr>
            `;
            // Actualizar contador a 0 cuando no hay resultados
            this.updateResultsCounter();
            return;
        }
        
        // Filtrar para mostrar solo la última versión de cada informe único (por ID)
        // El servidor puede enviar múltiples versiones del mismo informe, necesitamos solo la última
        const uniqueInformesMap = new Map();
        informes.forEach(informe => {
            const informeId = informe.id;
            const existingInforme = uniqueInformesMap.get(informeId);
            
            // Si no existe, agregarlo directamente
            if (!existingInforme) {
                uniqueInformesMap.set(informeId, informe);
                return;
            }
            
            // Comparar versiones: si ambos tienen versión, usar la mayor
            // Si solo uno tiene versión, preferir el que tiene versión
            // Si ninguno tiene versión, usar fecha_modificacion como fallback
            let shouldReplace = false;
            
            if (informe.version && existingInforme.version) {
                // Ambos tienen versión: usar la mayor
                shouldReplace = parseInt(informe.version) > parseInt(existingInforme.version);
            } else if (informe.version && !existingInforme.version) {
                // Solo el nuevo tiene versión: preferirlo
                shouldReplace = true;
            } else if (!informe.version && existingInforme.version) {
                // Solo el existente tiene versión: mantenerlo
                shouldReplace = false;
            } else {
                // Ninguno tiene versión: usar fecha_modificacion como fallback
                if (informe.fecha_modificacion && existingInforme.fecha_modificacion) {
                    shouldReplace = new Date(informe.fecha_modificacion) > new Date(existingInforme.fecha_modificacion);
                } else if (informe.fecha_modificacion && !existingInforme.fecha_modificacion) {
                    shouldReplace = true;
                }
            }
            
            if (shouldReplace) {
                uniqueInformesMap.set(informeId, informe);
            }
        });
        
        // Convertir el Map a array de informes únicos (solo últimas versiones)
        const uniqueInformes = Array.from(uniqueInformesMap.values());
        
        // Ordenar informes únicos según el ordenamiento seleccionado por el usuario
        if (this.state.sortBy) {
            // Usar el ordenamiento seleccionado por el usuario
            console.log('🔄 [renderReports] Aplicando ordenamiento del usuario:', this.state.sortBy, this.state.sortOrder);
            this.sortProcessedInformes(uniqueInformes);
        } else {
            // Ordenamiento por defecto: primero los incompletos, luego por study_id para agrupar, luego por fecha de modificación descendente
            console.log('🔄 [renderReports] Aplicando ordenamiento por defecto');
            uniqueInformes.sort((a, b) => {
                // PRIMERO: Ordenar por incompletos (los incompletos van primero)
                const studyKeyA = a.study_id || a.study_instance_uid || a.estudio_id || 'sin-estudio';
                const studyKeyB = b.study_id || b.study_instance_uid || b.estudio_id || 'sin-estudio';
                
                const flagA = this.state.studyFlags[studyKeyA];
                const flagB = this.state.studyFlags[studyKeyB];
                
                const isIncompleteA = flagA && flagA.informes_incompletos === true;
                const isIncompleteB = flagB && flagB.informes_incompletos === true;
                
                // Si uno es incompleto y el otro no, el incompleto va primero
                if (isIncompleteA && !isIncompleteB) {
                    return -1;
                }
                if (!isIncompleteA && isIncompleteB) {
                    return 1;
                }
                
                // Si ambos son incompletos o ambos no lo son, continuar con el ordenamiento normal
                // Ordenar por study_id (para agrupar estudios)
                const studyIdA = studyKeyA;
                const studyIdB = studyKeyB;
                
                if (studyIdA !== studyIdB) {
                    return studyIdA.localeCompare(studyIdB);
                }
                
                // Si tienen el mismo study_id, ordenar por fecha de modificación descendente
                const fechaA = new Date(a.fecha_modificacion || a.fecha_creacion || 0);
                const fechaB = new Date(b.fecha_modificacion || b.fecha_creacion || 0);
                
                if (fechaB.getTime() !== fechaA.getTime()) {
                    return fechaB.getTime() - fechaA.getTime();
                }
                
                // Si tienen la misma fecha, ordenar por ID descendente
                return (b.id || 0) - (a.id || 0);
            });
        }
        
        // Aplicar filtro de incompletos si está activo
        // IMPORTANTE: Los informes incompletos SIEMPRE se muestran, incluso con filtros de fecha
        let filteredInformes = uniqueInformes;
        const filterEstado = this.state.currentFilters.estado;
        if (filterEstado === 'incompletos') {
            // Filtrar solo informes de estudios que tienen el flag de incompletos
            filteredInformes = uniqueInformes.filter(informe => {
                const studyKey = informe.study_id || informe.study_instance_uid || informe.estudio_id || 'sin-estudio';
                const flag = this.state.studyFlags[studyKey];
                return flag && flag.informes_incompletos === true;
            });
        } else {
            // Si NO hay filtro de incompletos activo, separar incompletos y normales
            // Los incompletos siempre se muestran (sin filtros de fecha)
            // Los normales se filtran por fecha si hay filtros aplicados
            const hasDateFilters = this.state.currentFilters.fecha_inicio || this.state.currentFilters.fecha_fin;
            
            if (hasDateFilters) {
                const incompleteInformes = [];
                const normalInformes = [];
                
                uniqueInformes.forEach(informe => {
                    const studyKey = informe.study_id || informe.study_instance_uid || informe.estudio_id || 'sin-estudio';
                    const flag = this.state.studyFlags[studyKey];
                    const isIncomplete = flag && flag.informes_incompletos === true;
                    
                    if (isIncomplete) {
                        // Los incompletos siempre se incluyen (sin filtro de fecha)
                        incompleteInformes.push(informe);
                    } else {
                        // Los normales se filtran por fecha
                        normalInformes.push(informe);
                    }
                });
                
                // Combinar: incompletos primero, luego normales filtrados
                filteredInformes = [...incompleteInformes, ...normalInformes];
            }
        }
        
        // Agrupar informes únicos por estudio (study_id o estudio_id)
        // Usar un Set adicional para asegurar que no haya duplicados por ID dentro del mismo grupo
        const groupedByStudy = {};
        filteredInformes.forEach(informe => {
            // Usar study_id como clave principal para agrupar informes del mismo estudio
            // Prioridad: study_id > study_instance_uid > estudio_id
            const studyKey = informe.study_id || informe.study_instance_uid || informe.estudio_id || 'sin-estudio';
            const patientKey = informe.patient_id || informe.paciente_id || 'sin-paciente';
            // Agrupar por study_id (no por patient_id + study_id, ya que múltiples informes pueden tener el mismo study_id)
            const groupKey = `${studyKey}`;
            
            if (!groupedByStudy[groupKey]) {
                groupedByStudy[groupKey] = {
                    patient_id: informe.patient_id || informe.paciente_id || '',
                    patient_name: informe.patient_name || informe.nombre_paciente || 'N/A',
                    study_id: studyKey,
                    study_instance_uid: informe.study_instance_uid || informe.estudio_id || '',
                    modality: informe.modality || informe.modalidad || 'N/A',
                    study_description: informe.study_description || informe.descripcion_estudio || '',
                    informes: [], // Solo guardar informes únicos (ya filtrados por versión)
                    informeIds: new Set() // Set para verificar que no haya duplicados por ID dentro del grupo
                };
            }
            
            // Verificar que no haya duplicados por ID dentro del grupo
            const informeId = informe.id;
            if (!groupedByStudy[groupKey].informeIds.has(informeId)) {
                groupedByStudy[groupKey].informeIds.add(informeId);
                groupedByStudy[groupKey].informes.push(informe);
            }
        });
        
        // Generar HTML con estructura de árbol
        // Convertir el objeto a un array y ordenarlo según el ordenamiento del usuario
        let groupsArray = Object.values(groupedByStudy);
        
        // Si hay ordenamiento del usuario, ordenar los grupos según el primer informe de cada grupo
        if (this.state.sortBy && groupsArray.length > 0) {
            const sortBy = this.state.sortBy;
            const sortOrder = this.state.sortOrder || 'desc';
            const multiplier = sortOrder === 'asc' ? 1 : -1;
            
            groupsArray.sort((groupA, groupB) => {
                // Usar el primer informe de cada grupo para comparar
                const informeA = groupA.informes[0];
                const informeB = groupB.informes[0];
                
                if (!informeA || !informeB) return 0;
                
                let valueA, valueB;
                
                switch (sortBy) {
                    case 'patient_name':
                        valueA = (informeA.patient_name || '').toLowerCase();
                        valueB = (informeB.patient_name || '').toLowerCase();
                        break;
                    case 'modality':
                        valueA = (informeA.modality || '').toLowerCase();
                        valueB = (informeB.modality || '').toLowerCase();
                        break;
                    case 'estado':
                        valueA = (informeA.estado || '').toLowerCase();
                        valueB = (informeB.estado || '').toLowerCase();
                        break;
                    case 'usuario_nombre':
                        valueA = (informeA.usuario_nombre || '').toLowerCase();
                        valueB = (informeB.usuario_nombre || '').toLowerCase();
                        break;
                    case 'fecha_creacion':
                        valueA = new Date(informeA.fecha_creacion || 0).getTime();
                        valueB = new Date(informeB.fecha_creacion || 0).getTime();
                        break;
                    case 'fecha_modificacion':
                        valueA = new Date(informeA.fecha_modificacion || 0).getTime();
                        valueB = new Date(informeB.fecha_modificacion || 0).getTime();
                        break;
                    default:
                        return 0;
                }
                
                if (valueA < valueB) return -1 * multiplier;
                if (valueA > valueB) return 1 * multiplier;
                return 0;
            });
        }
        
        let html = '';
        let groupIndex = 0;
        
        groupsArray.forEach(group => {
            const groupId = `study-group-${groupIndex}`;
            // Contar informes únicos directamente desde el array (ya filtrado para excluir versiones)
            // Cada elemento en group.informes es un informe único (última versión)
            const uniqueReportsCount = group.informes.length;
            const hasMultipleReports = uniqueReportsCount > 1;
            const collapseId = hasMultipleReports ? `collapse-${groupId}` : '';
            
            // Si solo hay un informe, mostrar directamente sin encabezado de grupo
            if (!hasMultipleReports) {
                // Mostrar solo la fila del informe directamente (sin agrupación)
                const informe = group.informes[0];
                // Construir studyKeyForRow de la misma manera que se construye group.study_id
                const studyKeyForRow = informe.study_id || informe.study_instance_uid || informe.estudio_id || 'sin-estudio';
                html += `
                    <tr data-informe-id="${informe.id}">
                        <td>
                            <div>
                                <div class="fw-medium">${this.escapeHtml(informe.patient_name || 'N/A')}</div>
                                <small class="text-muted">ID: ${this.escapeHtml(informe.patient_id || 'N/A')}</small>
                            </div>
                        </td>
                        <td class="d-none d-sm-table-cell">
                            <span class="badge bg-info">${this.escapeHtml(informe.modality || 'N/A')}</span>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <div>
                                <span class="badge bg-${informe.estado_badge}">
                                    ${this.escapeHtml(informe.estado || 'N/A')}
                                </span>
                                ${(typeof QaQuickAction !== 'undefined' && QaQuickAction.enabled) ? QaQuickAction.renderInformeBadge(informe) : ''}
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <span>#${informe.id}</span>
                                        ${informe.total_versiones > 1 ? `
                                            <span class="ms-1">
                                                <i class="fas fa-code-branch" style="font-size: 0.7rem;"></i>
                                                v${informe.version}/${informe.total_versiones}
                                            </span>
                                        ` : `
                                            <span class="ms-1">v${informe.version}</span>
                                        `}
                                    </small>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-lg-table-cell">
                            <div class="d-flex align-items-center">
                                ${canViewAll ? `
                                    <i class="fas fa-user-circle text-primary me-1" style="cursor: pointer;" onclick="InformesManager.showUserInfo(${JSON.stringify(informe).replace(/"/g, '&quot;')})" title="Ver información del usuario"></i>
                                ` : ''}
                                <div>
                                    <div class="fw-medium">${this.escapeHtml(informe.medico_informante_nombre || informe.usuario_nombre || this.state.currentUserName || 'N/A')}</div>
                                    <small class="text-muted">${this.escapeHtml(informe.medico_informante_apellido || informe.usuario_apellido || this.state.currentUserLastName || '')}</small>
                                    ${informe.origen === 'externo' ? `<div class="mt-1"><small class="badge bg-secondary">PDF externo</small></div>` : ''}
                                    ${informe.sin_medico_asignado ? `<div class="mt-1"><small class="badge bg-warning text-dark">Sin médico asignado</small></div>` : ''}
                                    ${informe.medico_informante_rol || informe.usuario_rol ? `
                                        <div class="mt-1">
                                            <small class="badge bg-info text-white">
                                                ${(informe.medico_informante_rol || informe.usuario_rol) === 'medico_informante' ? 'Médico Informante' : 
                                                  (informe.medico_informante_rol || informe.usuario_rol) === 'transcriptor' ? 'Transcriptor' : 
                                                  (informe.medico_informante_rol || informe.usuario_rol) === 'otro' ? 'Otro' : (informe.medico_informante_rol || informe.usuario_rol)}
                                            </small>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-lg-table-cell">
                            <small>${informe.fecha_creacion_formatted}</small>
                        </td>
                        <td class="d-none d-xl-table-cell">
                            <small>${informe.fecha_modificacion_formatted}</small>
                        </td>
                        <td class="d-none d-md-table-cell">
                            ${this.audiosColumnHtml(informe)}
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                ${((informe.pacs_series_id && String(informe.pacs_series_id).trim() !== '') || (informe.pacs_instance_id && String(informe.pacs_instance_id).trim() !== '')) && !this.state.pacsVerificationPending.has(informe.id) && !this.state.pacsConfirmedRemoved.has(informe.id) ? `
                                    <span class="badge bg-success" title="Enviado a PACS${informe.fecha_enviado_pacs ? ': ' + new Date(informe.fecha_enviado_pacs).toLocaleString('es-AR') : ''}">
                                        <i class="fas fa-check-circle me-1"></i>En PACS
                                    </span>
                                ` : ''}
                                ${this.state.pacsVerificationPending.has(informe.id) ? `
                                    <span class="badge bg-secondary" title="Verificando eliminación...">
                                        <i class="fas fa-spinner fa-spin me-1"></i>Verificando...
                                    </span>
                                ` : ''}
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="InformesManager.viewReport(${informe.id})" title="Ver">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-outline-secondary" onclick="InformesManager.editReport(${informe.id})" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>
                                ${this.cobranzaEditButtonHtml(informe)}
                                ${this.state.canCreateReports ? `
                                <button class="btn btn-outline-success" onclick="InformesManager.createNewReportFromExisting(${informe.id})" title="Crear nuevo informe para el mismo estudio">
                                    <i class="fas fa-plus"></i>
                                </button>
                                ` : ''}
                                ${informe.total_versiones > 1 ? `
                                    <button class="btn btn-outline-info" onclick="InformesManager.showVersionHistory(${informe.id})" title="Ver Historial de Versiones">
                                        <i class="fas fa-code-branch"></i>
                                    </button>
                                ` : ''}
                                    ${(() => {
                                        // PRIORIDAD 1: Si está pendiente de verificación, mostrar spinner
                                        if (this.state.pacsVerificationPending.has(informe.id)) {
                                            console.log('🔄 [RENDER] Informe', informe.id, 'está pendiente, mostrando spinner');
                                            return `
                                                <button id="btnRemovePacs-${informe.id}" class="btn btn-secondary" disabled title="Verificando eliminación...">
                                                    <i class="fas fa-spinner fa-spin"></i>
                                                </button>
                                            `;
                                        }
                                        
                                        // Verificar si tiene datos PACS (pero NO si fue confirmado como eliminado localmente)
                                        const hasPacsData = this.informeHasPacsInOrthanc(informe);
                                        const wasConfirmedRemoved = this.state.pacsConfirmedRemoved.has(informe.id);
                                        
                                        console.log('🔍 [RENDER] Informe', informe.id, 'PACS state:', {
                                            hasPacsData,
                                            wasConfirmedRemoved,
                                            pacs_series_id: informe.pacs_series_id,
                                            pacs_instance_id: informe.pacs_instance_id,
                                            inConfirmedRemoved: this.state.pacsConfirmedRemoved.has(informe.id)
                                        });
                                        
                                        // PRIORIDAD 2: Si tiene datos PACS Y NO fue confirmado como eliminado, mostrar botón amarillo (eliminar)
                                        if (hasPacsData && !wasConfirmedRemoved && this.state.canSendToPacs) {
                                            console.log('🟡 [RENDER] Informe', informe.id, 'mostrando botón amarillo (eliminar)');
                                            return `
                                                <button id="btnRemovePacs-${informe.id}" class="btn btn-outline-warning" onclick="InformesManager.removeFromPacs(${informe.id})" title="Eliminar de PACS" ${this.state.removingFromPacs.has(informe.id) ? 'disabled' : ''}>
                                                    ${this.state.removingFromPacs.has(informe.id) ? '<i class="fas fa-spinner fa-spin"></i>' : '<i class="fas fa-cloud-download-alt"></i>'}
                                                </button>
                                            `;
                                        }
                                        
                                        // Si fue confirmado como eliminado, NO mostrar botón amarillo (continuar a botón verde)
                                        if (wasConfirmedRemoved) {
                                            console.log('✅ [RENDER] Informe', informe.id, 'fue confirmado como eliminado, NO mostrando botón amarillo');
                                        }
                                        
                                        // PRIORIDAD 3: Si NO tiene datos PACS, verificar si puede enviar a PACS
                                        // Verificar si el informe está marcado como incompleto
                                        const compositeKey = `${studyKeyForRow}_informe_${informe.id}`;
                                        let flag = this.state.studyFlags[compositeKey];
                                        
                                        if (!flag) {
                                            flag = { informes_incompletos: false, nota: null };
                                        }
                                        
                                        const isIncomplete = flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1);
                                        
                                        // PRIORIDAD: Si está pendiente de envío Y NO tiene datos PACS, mostrar spinner
                                        // Si ya tiene datos PACS, el botón amarillo ya se mostró arriba
                                        if (this.state.pacsSendingPending.has(informe.id) && !hasPacsData) {
                                            console.log('🔄 [RENDER] Informe', informe.id, 'está pendiente de envío y NO tiene datos PACS, mostrando spinner');
                                            return `
                                                <button id="btnSendPacs-${informe.id}" class="btn btn-secondary" disabled title="Verificando envío...">
                                                    <i class="fas fa-spinner fa-spin"></i>
                                                </button>
                                            `;
                                        }
                                        
                                        // Si está pendiente pero ya tiene datos PACS, remover de pendientes (ya se confirmó)
                                        if (this.state.pacsSendingPending.has(informe.id) && hasPacsData) {
                                            console.log('✅ [RENDER] Informe', informe.id, 'está pendiente pero ya tiene datos PACS, removiendo de pendientes');
                                            this.removePendingPacsSending(informe.id);
                                        }
                                        
                                        // Mostrar botón verde si está finalizado Y NO está incompleto
                                        // O si fue confirmado como eliminado (forzar botón verde)
                                        const shouldShowGreenButton = (this.state.canSendToPacs && informe.estado === 'finalizado' && !isIncomplete) || wasConfirmedRemoved;
                                        
                                        if (shouldShowGreenButton) {
                                            console.log('🟢 [RENDER] Informe', informe.id, 'mostrando botón verde (enviar)', {
                                                canSendToPacs: this.state.canSendToPacs,
                                                estado: informe.estado,
                                                isIncomplete,
                                                wasConfirmedRemoved
                                            });
                                            return `
                                                <button id="btnSendPacs-${informe.id}" class="btn btn-outline-success" onclick="InformesManager.sendToPacs(${informe.id})" title="Enviar a PACS" ${this.state.sendingToPacs.has(informe.id) ? 'disabled' : ''}>
                                                    ${this.state.sendingToPacs.has(informe.id) ? '<i class="fas fa-spinner fa-spin"></i>' : '<i class="fas fa-cloud-upload-alt"></i>'}
                                                </button>
                                            `;
                                        } else if (this.state.canSendToPacs && informe.estado === 'finalizado' && isIncomplete) {
                                            return `
                                <button class="btn btn-outline-success" disabled title="No se puede enviar a PACS: informe marcado como incompleto">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </button>
                                    `;
                                        }
                                        return '';
                                    })()}
                                ${(typeof QaQuickAction !== 'undefined' && QaQuickAction.enabled) ? QaQuickAction.renderInformeButton(informe) : ''}
                                <button class="btn btn-outline-danger" onclick="InformesManager.deleteReport(${informe.id})" title="${((informe.pacs_series_id && String(informe.pacs_series_id).trim() !== '') || (informe.pacs_instance_id && String(informe.pacs_instance_id).trim() !== '')) ? 'No se puede eliminar: informe en PACS' : 'Eliminar'}" ${((informe.pacs_series_id && String(informe.pacs_series_id).trim() !== '') || (informe.pacs_instance_id && String(informe.pacs_instance_id).trim() !== '')) ? 'disabled' : ''}>
                                    <i class="fas fa-trash"></i>
                                </button>
                                ${this.state.canMarcarIncompletos ? (() => {
                                    // IMPORTANTE: Para informes individuales, buscar SOLO con clave compuesta
                                    // NO usar fallback a study_id porque eso puede traer el flag general del estudio
                                    const compositeKey = `${studyKeyForRow}_informe_${informe.id}`;
                                    let flag = this.state.studyFlags[compositeKey];
                                    
                                    console.log('🔍 [RENDER] Buscando flag para informe individual:', informe.id, 'studyKey:', studyKeyForRow, 'clave compuesta:', compositeKey, 'flag encontrado:', flag);
                                    
                                    // Si no se encuentra con la clave compuesta, considerar que NO está marcado
                                    // NO buscar con study_id solo porque eso traería el flag general del estudio
                                    // que puede estar marcado por otros informes
                                    if (!flag) {
                                        console.log('⚠️ [RENDER] No se encontró flag individual para informe:', informe.id, '- considerando como NO marcado');
                                        flag = { informes_incompletos: false, nota: null };
                                    }
                                    
                                    const isIncomplete = flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1);
                                    const btnClass = isIncomplete ? 'btn-warning btn-informes-incompletos' : 'btn-outline-warning';
                                    const title = isIncomplete 
                                        ? (flag.nota ? `Informes incompletos: ${flag.nota}` : 'Informes incompletos (marcado)')
                                        : 'Marcar/Desmarcar informes incompletos';
                                    
                                    // IMPORTANTE: Usar informe.id en el ID del botón para que sea único por informe
                                    const btnId = `btnIncompletos-${informe.id}`;
                                    return `
                                <button class="btn btn-sm ${btnClass}" 
                                        onclick="InformesManager.toggleInformesIncompletos('${studyKeyForRow}', '${this.escapeHtml(informe.patient_name || 'N/A')}', '${informe.id}', '${btnId}')" 
                                        title="${this.escapeHtml(title)}" 
                                        id="${btnId}"
                                        data-study-id="${studyKeyForRow}"
                                        data-informe-id="${informe.id}">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </button>
                                `;
                                })() : ''}
                                </div>
                            </div>
                        </td>
                    </tr>
                `;
            } else {
                // Si hay múltiples informes, mostrar encabezado del grupo y luego los informes anidados
                // Fila principal del grupo (siempre visible cuando hay múltiples)
                html += `
                    <tr class="study-group-header" data-group-id="${groupId}" style="background-color: #f8f9fa; cursor: pointer;" onclick="InformesManager.toggleStudyGroup('${groupId}')">
                        <td>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-chevron-right me-2 collapse-icon-${groupId}" style="transition: transform 0.2s;"></i>
                                <div>
                                    <div class="fw-medium">${this.escapeHtml(group.patient_name)}</div>
                                    <small class="text-muted">ID: ${this.escapeHtml(group.patient_id || 'N/A')}</small>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-sm-table-cell">
                            <span class="badge bg-info">${this.escapeHtml(group.modality)}</span>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <div>
                                <span class="badge bg-secondary">
                                    ${uniqueReportsCount} informes
                                </span>
                                <div class="mt-1">
                                    <small class="text-muted">Click para expandir</small>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-lg-table-cell"></td>
                        <td class="d-none d-lg-table-cell"></td>
                        <td class="d-none d-xl-table-cell"></td>
                        <td class="d-none d-md-table-cell"></td>
                        <td>
                            <div class="d-flex gap-1">
                                ${this.state.canMarcarIncompletos ? (() => {
                                    // Para el encabezado del grupo, verificar si CUALQUIER informe del grupo está marcado como incompleto
                                    const firstInformeId = group.informes && group.informes.length > 0 ? group.informes[0].id : null;
                                    
                                    // Buscar flags de TODOS los informes del grupo para ver si alguno está incompleto
                                    let hasAnyIncomplete = false;
                                    let incompleteNotes = [];
                                    
                                    if (group.informes && group.informes.length > 0) {
                                        for (const informe of group.informes) {
                                            const compositeKey = `${group.study_id}_informe_${informe.id}`;
                                            const informeFlag = this.state.studyFlags[compositeKey];
                                            if (informeFlag && (informeFlag.informes_incompletos === true || informeFlag.informes_incompletos === 1)) {
                                                hasAnyIncomplete = true;
                                                if (informeFlag.nota) {
                                                    incompleteNotes.push(`#${informe.id}: ${informeFlag.nota}`);
                                                }
                                            }
                                        }
                                    }
                                    
                                    // Si no hay informes incompletos individuales, buscar por study_id (compatibilidad)
                                    if (!hasAnyIncomplete) {
                                        const flag = this.state.studyFlags[group.study_id];
                                        if (flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1)) {
                                            hasAnyIncomplete = true;
                                            if (flag.nota) {
                                                incompleteNotes.push(flag.nota);
                                            }
                                        }
                                    }
                                    
                                    const isIncomplete = hasAnyIncomplete;
                                    const btnClass = isIncomplete ? 'btn-warning btn-informes-incompletos' : 'btn-outline-warning';
                                    const title = isIncomplete 
                                        ? (incompleteNotes.length > 0 ? `Informes incompletos:\n${incompleteNotes.join('\n')}` : 'Informes incompletos (uno o más informes marcados)')
                                        : 'Marcar/Desmarcar informes incompletos';
                                    
                                    // IMPORTANTE: El botón del grupo debe tener un ID único que NO coincida con ningún botón de informe individual
                                    // Usamos un prefijo "group-" para distinguirlo
                                    const btnId = `btnIncompletos-group-${group.study_id.replace(/[^a-zA-Z0-9]/g, '_')}`;
                                    
                                    // El botón del grupo debe abrir un modal especial que muestre todos los informes marcados
                                    const informeIds = group.informes.map(inf => inf.id).join(',');
                                    
                                    return `
                                <button class="btn btn-sm ${btnClass}" 
                                        onclick="event.stopPropagation(); InformesManager.showGroupIncompleteModal('${group.study_id}', '${this.escapeHtml(group.patient_name)}', '${informeIds}', '${btnId}')" 
                                        title="${this.escapeHtml(title)}" 
                                        id="${btnId}"
                                        data-study-id="${group.study_id}"
                                        data-is-group-button="true">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </button>
                                `;
                                })() : ''}
                                ${this.state.canCreateReports ? `
                                <button class="btn btn-sm btn-outline-success" onclick="event.stopPropagation(); InformesManager.createNewReportFromStudy('${group.study_id}', '${group.patient_id}', '${this.escapeHtml(group.patient_name)}', '${group.modality}', '${this.escapeHtml(group.study_description)}', '${group.study_instance_uid}')" title="Crear nuevo informe para este estudio">
                                    <i class="fas fa-plus"></i>
                                </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
                
                // Filas de informes (colapsables cuando hay múltiples)
                group.informes.forEach((informe, index) => {
                    const rowClass = `collapse study-group-row-${groupId}`;
                    const rowStyle = 'padding-left: 2rem; background-color: #ffffff;';
                
                    html += `
                        <tr class="${rowClass}" data-group-id="${groupId}" data-informe-id="${informe.id}" style="${rowStyle}">
                            <td>
                                <div>
                                    <i class="fas fa-file-alt me-2 text-muted"></i>
                                </div>
                            </td>
                            <td class="d-none d-sm-table-cell">
                                <span class="badge bg-info">${this.escapeHtml(informe.modality || 'N/A')}</span>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <div>
                                    <span class="badge bg-${informe.estado_badge}">
                                        ${this.escapeHtml(informe.estado || 'N/A')}
                                    </span>
                                    ${(typeof QaQuickAction !== 'undefined' && QaQuickAction.enabled) ? QaQuickAction.renderInformeBadge(informe) : ''}
                                    <div class="mt-1">
                                        <small class="text-muted">
                                            <span>#${informe.id}</span>
                                            ${informe.total_versiones > 1 ? `
                                                <span class="ms-1">
                                                    <i class="fas fa-code-branch" style="font-size: 0.7rem;"></i>
                                                    v${informe.version}/${informe.total_versiones}
                                                </span>
                                            ` : `
                                                <span class="ms-1">v${informe.version}</span>
                                            `}
                                        </small>
                                    </div>
                                </div>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <div class="d-flex align-items-center">
                                    ${canViewAll ? `
                                        <i class="fas fa-user-circle text-primary me-1" style="cursor: pointer;" onclick="InformesManager.showUserInfo(${JSON.stringify(informe).replace(/"/g, '&quot;')})" title="Ver información del usuario"></i>
                                    ` : ''}
                                    <div>
                                        <div class="fw-medium">${this.escapeHtml(informe.usuario_nombre || this.state.currentUserName || 'N/A')}</div>
                                        <small class="text-muted">${this.escapeHtml(informe.usuario_apellido || this.state.currentUserLastName || '')}</small>
                                    </div>
                                </div>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <small>${informe.fecha_creacion_formatted}</small>
                            </td>
                            <td class="d-none d-xl-table-cell">
                                <small>${informe.fecha_modificacion_formatted}</small>
                            </td>
                            <td class="d-none d-md-table-cell">
                                ${this.audiosColumnHtml(informe)}
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                ${((informe.pacs_series_id && String(informe.pacs_series_id).trim() !== '') || (informe.pacs_instance_id && String(informe.pacs_instance_id).trim() !== '')) && !this.state.pacsVerificationPending.has(informe.id) && !this.state.pacsConfirmedRemoved.has(informe.id) ? `
                                    <span class="badge bg-success" title="Enviado a PACS${informe.fecha_enviado_pacs ? ': ' + new Date(informe.fecha_enviado_pacs).toLocaleString('es-AR') : ''}">
                                        <i class="fas fa-check-circle me-1"></i>En PACS
                                    </span>
                                ` : ''}
                                ${this.state.pacsVerificationPending.has(informe.id) ? `
                                    <span class="badge bg-secondary" title="Verificando eliminación...">
                                        <i class="fas fa-spinner fa-spin me-1"></i>Verificando...
                                    </span>
                                ` : ''}
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="InformesManager.viewReport(${informe.id})" title="Ver">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" onclick="InformesManager.editReport(${informe.id})" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    ${this.cobranzaEditButtonHtml(informe)}
                                    ${this.state.canCreateReports ? `
                                    <button class="btn btn-outline-success" onclick="InformesManager.createNewReportFromExisting(${informe.id})" title="Crear nuevo informe para el mismo estudio">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    ` : ''}
                                    ${informe.total_versiones > 1 ? `
                                        <button class="btn btn-outline-info" onclick="InformesManager.showVersionHistory(${informe.id})" title="Ver Historial de Versiones">
                                            <i class="fas fa-code-branch"></i>
                                        </button>
                                    ` : ''}
                                        ${(() => {
                                            // PRIORIDAD 1: Si está pendiente de verificación, mostrar spinner
                                            if (this.state.pacsVerificationPending.has(informe.id)) {
                                                return `
                                                    <button id="btnRemovePacs-${informe.id}" class="btn btn-secondary" disabled title="Verificando eliminación...">
                                                        <i class="fas fa-spinner fa-spin"></i>
                                                    </button>
                                                `;
                                            }
                                            
                                            // Verificar si tiene datos PACS (pero NO si fue confirmado como eliminado localmente)
                                            const hasPacsData = this.informeHasPacsInOrthanc(informe);
                                            const wasConfirmedRemoved = this.state.pacsConfirmedRemoved.has(informe.id);
                                            
                                            // PRIORIDAD 2: Si tiene datos PACS Y NO fue confirmado como eliminado, mostrar botón amarillo (eliminar)
                                            if (hasPacsData && !wasConfirmedRemoved && this.state.canSendToPacs) {
                                                return `
                                                    <button id="btnRemovePacs-${informe.id}" class="btn btn-outline-warning" onclick="InformesManager.removeFromPacs(${informe.id})" title="Eliminar de PACS" ${this.state.removingFromPacs.has(informe.id) ? 'disabled' : ''}>
                                                        ${this.state.removingFromPacs.has(informe.id) ? '<i class="fas fa-spinner fa-spin"></i>' : '<i class="fas fa-cloud-upload-alt"></i>'}
                                                    </button>
                                                `;
                                            }
                                            
                                            // PRIORIDAD 3: Si NO tiene datos PACS, verificar si puede enviar a PACS
                                            const informeStudyId = informe.study_id || informe.study_instance_uid || informe.estudio_id || group.study_id;
                                            const compositeKey = `${informeStudyId}_informe_${informe.id}`;
                                            let flag = this.state.studyFlags[compositeKey];
                                            
                                            if (!flag) {
                                                flag = { informes_incompletos: false, nota: null };
                                            }
                                            const isIncomplete = flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1);
                                            
                                            // PRIORIDAD: Si está pendiente de envío Y NO tiene datos PACS, mostrar spinner
                                            // Si ya tiene datos PACS, el botón amarillo ya se mostró arriba
                                            if (this.state.pacsSendingPending.has(informe.id) && !hasPacsData) {
                                                console.log('🔄 [RENDER-GROUP] Informe', informe.id, 'está pendiente de envío y NO tiene datos PACS, mostrando spinner');
                                                return `
                                                    <button id="btnSendPacs-${informe.id}" class="btn btn-secondary" disabled title="Verificando envío...">
                                                        <i class="fas fa-spinner fa-spin"></i>
                                                    </button>
                                                `;
                                            }
                                            
                                            // Si está pendiente pero ya tiene datos PACS, remover de pendientes (ya se confirmó)
                                            if (this.state.pacsSendingPending.has(informe.id) && hasPacsData) {
                                                console.log('✅ [RENDER-GROUP] Informe', informe.id, 'está pendiente pero ya tiene datos PACS, removiendo de pendientes');
                                                this.removePendingPacsSending(informe.id);
                                            }
                                            
                                            // Mostrar botón verde si está finalizado Y NO está incompleto
                                            // O si fue confirmado como eliminado (forzar botón verde)
                                            const shouldShowGreenButton = (this.state.canSendToPacs && informe.estado === 'finalizado' && !isIncomplete) || wasConfirmedRemoved;
                                            
                                            if (shouldShowGreenButton) {
                                                console.log('🟢 [RENDER-GROUP] Informe', informe.id, 'mostrando botón verde (enviar)', {
                                                    canSendToPacs: this.state.canSendToPacs,
                                                    estado: informe.estado,
                                                    isIncomplete,
                                                    wasConfirmedRemoved
                                                });
                                                return `
                                                    <button id="btnSendPacs-${informe.id}" class="btn btn-outline-success" onclick="InformesManager.sendToPacs(${informe.id})" title="Enviar a PACS" ${this.state.sendingToPacs.has(informe.id) ? 'disabled' : ''}>
                                                        ${this.state.sendingToPacs.has(informe.id) ? '<i class="fas fa-spinner fa-spin"></i>' : '<i class="fas fa-cloud-upload-alt"></i>'}
                                                    </button>
                                                `;
                                            } else if (this.state.canSendToPacs && informe.estado === 'finalizado' && isIncomplete) {
                                                return `
                                                    <button class="btn btn-outline-success" disabled title="No se puede enviar a PACS: informe marcado como incompleto">
                                                        <i class="fas fa-cloud-upload-alt"></i>
                                                    </button>
                                                `;
                                            }
                                            return '';
                                        })()}
                                    ${(typeof QaQuickAction !== 'undefined' && QaQuickAction.enabled) ? QaQuickAction.renderInformeButton(informe) : ''}
                                    <button class="btn btn-outline-danger" onclick="InformesManager.deleteReport(${informe.id})" title="${((informe.pacs_series_id && String(informe.pacs_series_id).trim() !== '') || (informe.pacs_instance_id && String(informe.pacs_instance_id).trim() !== '')) ? 'No se puede eliminar: informe en PACS' : 'Eliminar'}" ${((informe.pacs_series_id && String(informe.pacs_series_id).trim() !== '') || (informe.pacs_instance_id && String(informe.pacs_instance_id).trim() !== '')) ? 'disabled' : ''}>
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    ${this.state.canMarcarIncompletos ? (() => {
                                        // IMPORTANTE: Usar el study_id del informe individual, no del grupo
                                        // Cada informe puede tener su propio estudio y flag
                                        // IMPORTANTE: Buscar SOLO con clave compuesta (informe individual)
                                        // NO usar fallback a study_id porque eso traería el flag general del estudio
                                        const informeStudyId = informe.study_id || informe.study_instance_uid || informe.estudio_id || group.study_id;
                                        const compositeKey = `${informeStudyId}_informe_${informe.id}`;
                                        let flag = this.state.studyFlags[compositeKey];
                                        
                                        // Si no se encuentra, considerar como NO marcado
                                        if (!flag) {
                                            flag = { informes_incompletos: false, nota: null };
                                        }
                                        const isIncomplete = flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1);
                                        const btnClass = isIncomplete ? 'btn-warning btn-informes-incompletos' : 'btn-outline-warning';
                                        const title = isIncomplete 
                                            ? (flag.nota ? `Informes incompletos: ${flag.nota}` : 'Informes incompletos (marcado)')
                                            : 'Marcar/Desmarcar informes incompletos';
                                        // IMPORTANTE: Usar informe.id en el ID del botón para que sea único por informe
                                        const btnId = `btnIncompletos-${informe.id}`;
                                        return `
                                    <button class="btn btn-sm ${btnClass}" 
                                            onclick="event.stopPropagation(); InformesManager.toggleInformesIncompletos('${informeStudyId}', '${this.escapeHtml(group.patient_name)}', '${informe.id}', '${btnId}')" 
                                            title="${this.escapeHtml(title)}" 
                                            id="${btnId}"
                                            data-study-id="${informeStudyId}"
                                            data-informe-id="${informe.id}">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </button>
                                    `;
                                    })() : ''}
                                    </div>
                                </div>
                            </td>
                        </tr>
                    `;
                });
            }
            
            groupIndex++;
        });
        
        tbody.innerHTML = html;
        
        // Actualizar contador después de renderizar las filas
        this.updateResultsCounter();
        
        // Actualizar estado de botones de grupo después de renderizar
        Object.values(groupedByStudy).forEach(group => {
            const groupBtnId = `btnIncompletos-group-${group.study_id.replace(/[^a-zA-Z0-9]/g, '_')}`;
            this.updateGroupButtonState(group.study_id, groupBtnId);
        });

        this.syncTranscriptionStatusPending(informes);
    },

    /**
     * Renderizar paginación
     */
    renderPagination(pagination) {
        const container = document.getElementById('paginationContainer');
        if (!container) return;
        
        // Contar las filas reales que se están mostrando
        const tbody = document.getElementById('reportsTableBody');
        let actualRowsCount = 0;
        if (tbody) {
            const rows = tbody.querySelectorAll('tr');
            actualRowsCount = Array.from(rows).filter(row => {
                const colspan = row.querySelector('td[colspan]');
                return !colspan;
            }).length;
        }
        
        // Usar las filas reales de la tabla para calcular el total
        // El servidor puede estar contando versiones, pero nosotros contamos filas reales
        const currentPage = pagination ? pagination.current_page : this.state.currentPage || 1;
        const perPage = pagination ? pagination.per_page : this.state.perPage || 25;
        
        // Calcular total basado SOLO en las filas reales mostradas
        // El servidor puede estar contando versiones, pero nosotros contamos filas reales
        // SIEMPRE calcular totalPages basado en las filas reales, ignorando pagination.total_pages del servidor
        let totalUniqueReports = actualRowsCount;
        
        // Calcular totalPages basado SOLO en las filas reales que tenemos
        // Si hay 6 filas y perPage=10, entonces totalPages = Math.ceil(6/10) = 1
        let totalPages = Math.ceil(actualRowsCount / perPage);
        
        // Si estamos en la última página según el servidor, calcular el total exacto
        if (pagination && pagination.total_pages && currentPage === pagination.total_pages) {
            // Estamos en la última página, el total es exacto
            totalUniqueReports = ((currentPage - 1) * perPage) + actualRowsCount;
            // Pero aún así usar nuestro cálculo de totalPages basado en filas reales
            totalPages = Math.ceil(totalUniqueReports / perPage);
        } else {
            // No estamos en la última página o no hay paginación del servidor
            // Usar solo las filas actuales
            totalUniqueReports = actualRowsCount;
            totalPages = Math.ceil(actualRowsCount / perPage);
        }
        
        // Si solo hay una página o menos, no mostrar paginación
        if (totalPages <= 1 && actualRowsCount <= perPage) {
            container.innerHTML = '';
            // Actualizar información de paginación incluso si no hay paginación visible
            this.updatePaginationInfo(actualRowsCount, totalUniqueReports, currentPage, perPage);
            return;
        }
        
        let paginationHtml = '<nav><ul class="pagination justify-content-center">';
        
        // Botón anterior
        paginationHtml += `
            <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="InformesManager.goToPage(${currentPage - 1}); return false;">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
        `;
        
        // Páginas
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="InformesManager.goToPage(1); return false;">1</a></li>`;
            if (startPage > 2) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="InformesManager.goToPage(${i}); return false;">${i}</a>
                </li>
            `;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="InformesManager.goToPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }
        
        // Botón siguiente
        paginationHtml += `
            <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="InformesManager.goToPage(${currentPage + 1}); return false;">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
        `;
        
        paginationHtml += '</ul></nav>';
        
        container.innerHTML = paginationHtml;
        
        // Actualizar información de paginación
        this.updatePaginationInfo(actualRowsCount, totalUniqueReports, currentPage, perPage);
    },
    
    /**
     * Actualizar información de paginación (contador)
     */
    updatePaginationInfo(actualRowsCount, totalUniqueReports, currentPage, perPage) {
        const paginationInfoEl = document.getElementById('paginationInfo');
        if (!paginationInfoEl) return;
        
        if (actualRowsCount === 0) {
            paginationInfoEl.textContent = 'No hay resultados';
            return;
        }
        
        // Usar las filas reales de la tabla para el contador
        // El total debe ser calculado basado SOLO en las filas reales mostradas
        const start = ((currentPage - 1) * perPage) + 1;
        const end = start + actualRowsCount - 1;
        
        // Para el total, usar el cálculo basado en las filas reales
        // Si estamos en la última página, el total es exacto
        // Si no, usamos una estimación conservadora
        let total = totalUniqueReports;
        
        // Si tenemos información de paginación y estamos en la última página,
        // usar el cálculo exacto basado en las filas actuales
        if (this.state.totalPages && currentPage === this.state.totalPages) {
            total = start + actualRowsCount - 1;
        } else if (this.state.totalPages && currentPage < this.state.totalPages) {
            // Si no estamos en la última página, usar estimación conservadora
            // Mínimo: filas mostradas hasta ahora
            total = start + actualRowsCount - 1;
        } else {
            // Si no hay información de paginación, usar las filas actuales
            total = actualRowsCount;
        }
        
        paginationInfoEl.textContent = `Mostrando ${start} - ${end} de ${total} resultado${total !== 1 ? 's' : ''}`;
    },

    /**
     * Cargar perPage desde localStorage
     */
    loadPerPageFromStorage() {
        try {
            const savedPerPage = localStorage.getItem('informes_manager_perPage');
            if (savedPerPage) {
                const perPageInt = parseInt(savedPerPage);
                if (perPageInt && perPageInt > 0 && [10, 25, 50, 100].includes(perPageInt)) {
                    this.state.perPage = perPageInt;
                    this.state.perPageUserSet = true;
                    console.log('perPage restaurado desde localStorage:', perPageInt);
                }
            }
        } catch (error) {
            console.warn('Error cargando perPage desde localStorage:', error);
        }
    },
    
    /**
     * Guardar perPage en localStorage
     */
    savePerPageToStorage() {
        try {
            localStorage.setItem('informes_manager_perPage', this.state.perPage.toString());
            console.log('perPage guardado en localStorage:', this.state.perPage);
        } catch (error) {
            console.warn('Error guardando perPage en localStorage:', error);
        }
    },
    
    /**
     * Cargar informes pendientes de verificación desde localStorage
     */
    loadPendingPacsVerification: function() {
        try {
            const stored = localStorage.getItem('informes_manager_pending_pacs_verification');
            if (stored) {
                const ids = JSON.parse(stored);
                this.state.pacsVerificationPending = new Set(ids);
                console.log('📋 Informes pendientes de verificación PACS cargados desde localStorage:', Array.from(this.state.pacsVerificationPending));
                console.log('📋 Total de informes pendientes:', this.state.pacsVerificationPending.size);
            } else {
                console.log('📋 No hay informes pendientes de verificación PACS en localStorage');
                this.state.pacsVerificationPending = new Set();
            }
        } catch (error) {
            console.warn('Error cargando informes pendientes de verificación PACS:', error);
            this.state.pacsVerificationPending = new Set();
        }
    },

    /**
     * Guardar informes pendientes de verificación en localStorage
     */
    savePendingPacsVerification: function() {
        try {
            const ids = Array.from(this.state.pacsVerificationPending);
            localStorage.setItem('informes_manager_pending_pacs_verification', JSON.stringify(ids));
        } catch (error) {
            console.warn('Error guardando informes pendientes de verificación PACS:', error);
        }
    },

    /**
     * Agregar informe a la lista de verificación pendiente
     */
    addPendingPacsVerification: function(informeId) {
        this.state.pacsVerificationPending.add(informeId);
        this.savePendingPacsVerification();
        console.log('➕ Informe', informeId, 'agregado a verificación pendiente PACS');
        console.log('📋 Total pendientes después de agregar:', this.state.pacsVerificationPending.size);
        console.log('📋 IDs pendientes:', Array.from(this.state.pacsVerificationPending));
        this.startPacsVerificationPolling();
    },

    /**
     * Remover informe de la lista de verificación pendiente
     */
    removePendingPacsVerification: function(informeId) {
        this.state.pacsVerificationPending.delete(informeId);
        this.savePendingPacsVerification();
        console.log('➖ Informe', informeId, 'removido de verificación pendiente PACS');
        console.log('📋 Total pendientes después de remover:', this.state.pacsVerificationPending.size);
        console.log('📋 IDs pendientes:', Array.from(this.state.pacsVerificationPending));
        
        if (this.state.pacsVerificationPending.size === 0) {
            console.log('⏹️ No quedan informes pendientes, deteniendo polling');
            this.stopPacsVerificationPolling();
        }
    },

    /**
     * Iniciar polling de verificación de PACS
     */
    startPacsVerificationPolling: function() {
        // Si ya hay un intervalo activo, no crear otro
        if (this.state.pacsVerificationInterval) {
            console.log('⚠️ Polling de verificación PACS ya está activo, no se creará otro');
            return;
        }
        
        // Si no hay informes pendientes, no iniciar polling
        if (this.state.pacsVerificationPending.size === 0) {
            console.log('ℹ️ No hay informes pendientes, no se iniciará polling');
            return;
        }
        
        console.log('🔄 Iniciando polling de verificación PACS para', this.state.pacsVerificationPending.size, 'informes');
        console.log('📋 IDs a verificar:', Array.from(this.state.pacsVerificationPending));
        
        this.state.pacsVerificationInterval = setInterval(async () => {
            const pendingCount = this.state.pacsVerificationPending.size;
            
            if (pendingCount === 0) {
                console.log('⏹️ No hay informes pendientes, deteniendo polling');
                this.stopPacsVerificationPolling();
                return;
            }
            
            // Verificar cada informe pendiente
            const pendingIds = Array.from(this.state.pacsVerificationPending);
            console.log('');
            console.log('🔍 [POLLING] ===== INICIO VERIFICACIÓN =====');
            console.log('🔍 [POLLING] Verificando', pendingIds.length, 'informes pendientes de verificación PACS:', pendingIds);
            console.log('🔍 [POLLING] Timestamp:', new Date().toISOString());
            
            for (const informeId of pendingIds) {
                await this.verifyAndUpdatePacsState(informeId);
            }
            
            console.log('🔍 [POLLING] ===== FIN VERIFICACIÓN =====');
            console.log('');
        }, 5000); // Verificar cada 5 segundos (aumentado para dar más tiempo al servidor)
    },

    /**
     * Detener polling de verificación de PACS
     */
    stopPacsVerificationPolling: function() {
        if (this.state.pacsVerificationInterval) {
            clearInterval(this.state.pacsVerificationInterval);
            this.state.pacsVerificationInterval = null;
            console.log('⏹️ Polling de verificación PACS detenido');
        }
    },

    /**
     * Verificar y actualizar estado PACS de un informe específico
     */
    verifyAndUpdatePacsState: async function(informeId) {
        try {
            console.log('🔍 [verifyAndUpdatePacsState] Verificando informe', informeId);
            
            const token = this.getSessionToken();
            const response = await fetch(`${this.config.apiBaseUrl}/get.php?informe_id=${informeId}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                console.warn('⚠️ No se pudo verificar estado del informe:', informeId);
                return;
            }

            const data = await response.json();
            // get.php?informe_id=X devuelve { data: informe } directamente
            const infRaw0 = (data.data && data.data.informes) ? data.data.informes[0] : data.data;
            console.log('📋 [verifyAndUpdatePacsState] Respuesta del servidor para informe', informeId, ':', {
                success: data.success,
                pacs_series_id: infRaw0?.pacs_series_id,
                pacs_instance_id: infRaw0?.pacs_instance_id
            });
            
            if (data.success && infRaw0 && infRaw0.id) {
                const informe = infRaw0;
                const hasPacsData = this.informeHasPacsInOrthanc(informe);

                console.log('🔍 [verifyAndUpdatePacsState] Informe', informeId, 'tiene datos PACS:', hasPacsData, {
                    series_id: informe.pacs_series_id,
                    instance_id: informe.pacs_instance_id
                });

                // Si el informe ya NO tiene datos PACS (fue eliminado exitosamente)
                if (!hasPacsData) {
                    console.log('✅ Informe', informeId, 'confirmado: ya no está en PACS (campos vacíos en BD)');
                    
                    // Verificar en la lista actual solo para detectar si aún aparece con datos PACS.
                    // Si el informe NO está en la página actual (filtros/paginación), confiamos en get.php.
                    const currentListResponse = await fetch(`${this.config.apiBaseUrl}/list.php?page=${this.state.currentPage || 1}&per_page=${this.state.perPage}`, {
                        method: 'GET',
                        headers: {
                            ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                            'Content-Type': 'application/json'
                        }
                    });
                    
                    // Por defecto confirmado (get.php ya lo verificó); solo anular si la lista
                    // muestra explícitamente que AÚN tiene datos PACS.
                    let confirmedInList = true;
                    if (currentListResponse.ok) {
                        const listData = await currentListResponse.json();
                        if (listData.success && listData.data && listData.data.informes) {
                            const informeInList = listData.data.informes.find(inf => inf.id === informeId);
                            if (informeInList) {
                                const hasPacsInList = (informeInList.pacs_series_id && String(informeInList.pacs_series_id).trim() !== '') || 
                                                    (informeInList.pacs_instance_id && String(informeInList.pacs_instance_id).trim() !== '');
                                // Solo rechazar si la lista TODAVÍA muestra datos PACS
                                confirmedInList = !hasPacsInList;
                                console.log('🔍 [verifyAndUpdatePacsState] Verificación en lista:', {
                                    found: true,
                                    hasPacsInList,
                                    confirmedInList
                                });
                            } else {
                                // No está en la página actual (otra página o filtro activo):
                                // get.php ya confirmó campos vacíos → se acepta como confirmado
                                console.log('🔍 [verifyAndUpdatePacsState] Informe', informeId, 'no encontrado en página actual, confiando en get.php');
                            }
                        }
                    }
                    
                    if (confirmedInList) {
                        console.log('✅ Informe', informeId, 'eliminación de PACS confirmada');
                        
                        // Agregar a la caché de confirmados como eliminados
                        this.state.pacsConfirmedRemoved.add(informeId);
                        console.log('📌 Informe', informeId, 'agregado a caché de confirmados como eliminados');
                        
                        // Remover de verificación pendiente
                        this.removePendingPacsVerification(informeId);
                        
                        // Remover del set de procesamiento
                        this.state.removingFromPacs.delete(informeId);
                        
                        // Verificar si el botón/badge aún existe en la UI
                        const removeButton = document.getElementById(`btnRemovePacs-${informeId}`);
                        const rowElement = document.querySelector(`tr[data-informe-id="${informeId}"]`);
                        const badge = rowElement ? rowElement.querySelector('.badge.bg-success[title="Enviado a PACS"]') : null;
                        
                        console.log('🔍 [verifyAndUpdatePacsState] Elementos UI encontrados:', {
                            removeButton: !!removeButton,
                            rowElement: !!rowElement,
                            badge: !!badge
                        });
                        
                        // Si aún hay elementos que indican que está en PACS, forzar recarga
                        if (removeButton || badge) {
                            console.log('🔄 Forzando actualización de UI para informe', informeId);
                            await this.loadReports(this.state.currentPage || 1);
                        } else {
                            console.log('✅ UI ya actualizada correctamente para informe', informeId);
                        }
                    } else {
                        console.log('⏳ Informe', informeId, 'campos vacíos en get.php pero la página actual aún muestra datos PACS, esperando más tiempo...');
                    }
                } else {
                    // Aún tiene datos PACS, seguir verificando
                    console.log('⏳ Informe', informeId, 'aún tiene datos PACS en BD, continuando verificación...');
                }
            } else {
                console.warn('⚠️ [verifyAndUpdatePacsState] Respuesta inesperada del servidor para informe', informeId);
            }
        } catch (error) {
            console.error('❌ Error verificando estado del informe', informeId, ':', error);
        }
    },

    /**
     * Cargar informes pendientes de envío desde localStorage
     */
    loadPendingPacsSending: function() {
        try {
            const stored = localStorage.getItem('informes_manager_pending_pacs_sending');
            if (stored) {
                const ids = JSON.parse(stored);
                this.state.pacsSendingPending = new Set(ids);
                console.log('📋 Informes pendientes de verificación de envío PACS cargados desde localStorage:', Array.from(this.state.pacsSendingPending));
            } else {
                this.state.pacsSendingPending = new Set();
            }
        } catch (error) {
            console.warn('Error cargando informes pendientes de envío PACS:', error);
            this.state.pacsSendingPending = new Set();
        }
    },

    /**
     * Guardar informes pendientes de envío en localStorage
     */
    savePendingPacsSending: function() {
        try {
            const ids = Array.from(this.state.pacsSendingPending);
            localStorage.setItem('informes_manager_pending_pacs_sending', JSON.stringify(ids));
        } catch (error) {
            console.warn('Error guardando informes pendientes de envío PACS:', error);
        }
    },

    /**
     * Agregar informe a la lista de verificación pendiente de envío
     */
    addPendingPacsSending: function(informeId) {
        this.state.pacsSendingPending.add(informeId);
        this.savePendingPacsSending();
        console.log('➕ Informe', informeId, 'agregado a verificación pendiente de envío PACS');
        console.log('📋 Total pendientes después de agregar:', this.state.pacsSendingPending.size);
        this.startPacsSendingPolling();
    },

    /**
     * Remover informe de la lista de verificación pendiente de envío
     */
    removePendingPacsSending: function(informeId) {
        this.state.pacsSendingPending.delete(informeId);
        this.savePendingPacsSending();
        console.log('➖ Informe', informeId, 'removido de verificación pendiente de envío PACS');
        if (this.state.pacsSendingPending.size === 0) {
            this.stopPacsSendingPolling();
        }
    },

    /**
     * Iniciar polling de verificación de envío a PACS
     */
    startPacsSendingPolling: function() {
        if (this.state.pacsSendingInterval) {
            console.log('⚠️ Polling de verificación de envío PACS ya está activo');
            return;
        }
        
        if (this.state.pacsSendingPending.size === 0) {
            return;
        }
        
        console.log('🔄 Iniciando polling de verificación de envío PACS para', this.state.pacsSendingPending.size, 'informes');
        
        this.state.pacsSendingInterval = setInterval(async () => {
            if (this.state.pacsSendingPending.size === 0) {
                this.stopPacsSendingPolling();
                return;
            }
            
            const pendingIds = Array.from(this.state.pacsSendingPending);
            console.log('🔍 [POLLING-SEND] Verificando', pendingIds.length, 'informes pendientes de verificación de envío:', pendingIds);
            
            for (const informeId of pendingIds) {
                await this.verifyAndUpdatePacsSendingState(informeId);
            }
        }, 5000);
    },

    /**
     * Detener polling de verificación de envío a PACS
     */
    stopPacsSendingPolling: function() {
        if (this.state.pacsSendingInterval) {
            clearInterval(this.state.pacsSendingInterval);
            this.state.pacsSendingInterval = null;
            console.log('⏹️ Polling de verificación de envío PACS detenido');
        }
    },

    /**
     * Verificar y actualizar estado de envío PACS de un informe específico
     */
    verifyAndUpdatePacsSendingState: async function(informeId) {
        try {
            console.log('🔍 [verifyAndUpdatePacsSendingState] Verificando envío del informe', informeId);
            
            const token = this.getSessionToken();
            const response = await fetch(`${this.config.apiBaseUrl}/get.php?informe_id=${informeId}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                console.warn('⚠️ No se pudo verificar estado del envío del informe:', informeId);
                return;
            }

            const data = await response.json();
            // get.php?informe_id=X devuelve { data: informe } directamente;
            // get.php?estudio_id=X devuelve { data: { informes: [...] } }
            const informeRaw = (data.data && data.data.informes) ? data.data.informes[0] : data.data;
            if (data.success && informeRaw && informeRaw.id) {
                const informe = informeRaw;
                const hasPacsData = this.informeHasPacsInOrthanc(informe);

                console.log('🔍 [verifyAndUpdatePacsSendingState] Informe', informeId, 'tiene datos PACS:', hasPacsData);

                // Si el informe YA tiene datos PACS (fue enviado exitosamente)
                if (hasPacsData) {
                    console.log('✅ Informe', informeId, 'confirmado: ya está en PACS (envío exitoso)');
                    
                    // Remover de verificación pendiente
                    this.removePendingPacsSending(informeId);
                    
                    // Remover del set de procesamiento
                    this.state.sendingToPacs.delete(informeId);
                    
                    // Si el modal de vinculación está abierto, no recargar la lista completa para no
                    // interrumpir (499) la petición de candidatos en vuelo. El botón se actualizará
                    // al cerrarse el modal (el próximo loadReports natural lo mostrará en amarillo).
                    const vincularModalEl = document.getElementById('irVincularModal');
                    const isVincularModalOpen = vincularModalEl && vincularModalEl.classList.contains('show');
                    if (!isVincularModalOpen) {
                        console.log('🔄 Forzando actualización de UI para informe', informeId);
                        await this.loadReports(this.state.currentPage || 1);
                    } else {
                        console.log('⏸️ Modal de vinculación abierto — omitiendo loadReports para no interrumpir candidatos');
                    }
                } else {
                    // Aún no tiene datos PACS, seguir verificando
                    console.log('⏳ Informe', informeId, 'aún no tiene datos PACS, continuando verificación...');
                }
            }
        } catch (error) {
            console.error('❌ Error verificando estado del envío del informe', informeId, ':', error);
        }
    },

    /**
     * Verificar inmediatamente si el informe tiene datos PACS
     */
    verifyPacsDataImmediately: async function(informeId) {
        try {
            console.log('🔍 [verifyPacsDataImmediately] Verificando informe', informeId);
            const token = this.getSessionToken();
            const response = await fetch(`${this.config.apiBaseUrl}/get.php?informe_id=${informeId}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            });

            if (response.ok) {
                const data = await response.json();
                // get.php?informe_id=X devuelve { data: informe } directamente
                const informeRaw2 = (data.data && data.data.informes) ? data.data.informes[0] : data.data;
                if (data.success && informeRaw2 && informeRaw2.id) {
                    const informe = informeRaw2;
                    const hasPacsData = this.informeHasPacsInOrthanc(informe);
                    
                    console.log('🔍 [verifyPacsDataImmediately] Informe', informeId, 'tiene datos PACS:', hasPacsData, {
                        series_id: informe.pacs_series_id,
                        instance_id: informe.pacs_instance_id
                    });
                    return { hasPacsData, informe };
                }
            }
            console.log('⚠️ [verifyPacsDataImmediately] No se pudo obtener datos del informe', informeId);
            return { hasPacsData: false, informe: null };
        } catch (error) {
            console.error('❌ Error verificando datos PACS inmediatamente:', error);
            return { hasPacsData: false, informe: null };
        }
    },

    /**
     * Actualizar estado visual del botón de enviar PACS
     */
    updateSendPacsButtonState: function(informeId, isProcessing) {
        const button = document.querySelector(`[onclick*="sendToPacs(${informeId})"]`);
        if (!button) return;
        
        // PRIORIDAD: Si está en verificación pendiente de envío, SIEMPRE mostrar spinner
        if (this.state.pacsSendingPending.has(informeId)) {
            button.disabled = true;
            button.classList.remove('btn-outline-success');
            button.classList.add('btn-secondary');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            button.title = 'Verificando envío...';
            return;
        }
        
        if (isProcessing) {
            button.disabled = true;
            button.classList.remove('btn-outline-success');
            button.classList.add('btn-secondary');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            button.title = 'Enviando a PACS...';
        } else {
            button.disabled = false;
            button.classList.remove('btn-secondary');
            button.classList.add('btn-outline-success');
            button.innerHTML = '<i class="fas fa-cloud-upload-alt"></i>';
            button.title = 'Enviar a PACS';
        }
    },

    /**
     * Cambiar elementos por página
     */
    changePerPage(newPerPage) {
        const perPageInt = parseInt(newPerPage);
        console.log('changePerPage llamado con:', perPageInt, 'actual:', this.state.perPage);
        if (perPageInt && perPageInt > 0) {
            if (perPageInt !== this.state.perPage) {
                console.log('Cambiando perPage de', this.state.perPage, 'a', perPageInt);
                this.state.perPage = perPageInt;
                this.state.perPageUserSet = true; // Marcar que el usuario cambió el valor
                // Guardar en localStorage
                this.savePerPageToStorage();
                // Volver a la primera página cuando se cambia el per_page
                this.state.currentPage = 1;
                this.loadReports(1);
            } else {
                console.log('El valor es el mismo, no se hace cambio');
            }
        } else {
            console.warn('Valor inválido para perPage:', newPerPage);
        }
    },
    
    /**
     * Ir a página específica
     */
    goToPage(page) {
        if (page < 1 || page > this.state.totalPages || page === this.state.currentPage) {
            return;
        }
        this.loadReports(page);
    },

    /**
     * Ver informe (solo lectura)
     */
    viewReport: async function(reportId) {
        await this.openReportModal(reportId, true);
    },

    /**
     * Editar informe
     */
    editReport: async function(reportId) {
        await this.openReportModal(reportId, false);
    },

    /**
     * Abrir modal con informe
     */
    openReportModal: async function(reportId, readOnly = false, tempReport = null) {
        try {
            this.showLoading(true);
            
            let informe;
            
            if (tempReport) {
                // Usar informe temporal (para nuevos informes)
                informe = tempReport;
                console.log('Usando informe temporal:', informe);
            } else {
                // Cargar datos del informe desde la API
                const token = this.getSessionToken();
                const response = await fetch(`${this.config.apiBaseUrl}/get.php?informe_id=${reportId}`, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                
                if (!response.ok) {
                    throw new Error(`Error HTTP: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Error al cargar el informe');
                }
                
                informe = data.data;
            }
            
            this.state.selectedReport = informe;
            this.state.originalReportData = JSON.parse(JSON.stringify(informe)); // Copia profunda
            this.state.isReadOnly = readOnly; // Guardar estado de solo lectura
            
            // Si es un nuevo informe (tempReport), asegurar que no tenga información del usuario anterior
            if (tempReport && !tempReport.id) {
                // Limpiar cualquier información del usuario que pueda haber sido copiada
                delete this.state.selectedReport.usuario_id;
                delete this.state.selectedReport.usuario_nombre;
                delete this.state.selectedReport.usuario_apellido;
                delete this.state.selectedReport.usuario_email;
                // Asignar información del usuario actual
                this.state.selectedReport.usuario_nombre = this.state.currentUserName || '';
                this.state.selectedReport.usuario_apellido = this.state.currentUserLastName || '';
            }
            
            // Limpiar solo el flag de cambios, pero MANTENER originalReportData
            this.state.hasUnsavedChanges = false;
            // Detener autosave si estaba activo
            if (this.state.autoSaveTimeout) {
                clearTimeout(this.state.autoSaveTimeout);
                this.state.autoSaveTimeout = null;
            }
            if (this.state.autoSaveInterval) {
                clearInterval(this.state.autoSaveInterval);
                this.state.autoSaveInterval = null;
            }
            // Limpiar timeout de verificación de cambios
            if (this.state.markModifiedTimeout) {
                clearTimeout(this.state.markModifiedTimeout);
                this.state.markModifiedTimeout = null;
            }
            
            // Resetear flag de cierre permitido (solo se permitirá después de guardar)
            this.state.allowModalClose = false;
            
            console.log('📋 Datos originales guardados al abrir modal:', {
                id: this.state.originalReportData?.id,
                titulo: this.state.originalReportData?.titulo,
                contenidoLength: (this.state.originalReportData?.contenido_html || '').length
            });
            
            // Actualizar título del modal
            const titleEl = document.getElementById('reportEditorModalLabel');
            if (titleEl) {
                const title = tempReport ? 'Nuevo Informe' : (informe.titulo || 'Sin título');
                titleEl.textContent = (readOnly ? 'Ver Informe: ' : 'Editar Informe: ') + title;
            }
            
            // Actualizar información del paciente y estado
            const patientNameEl = document.getElementById('modalPatientName');
            const patientIdEl = document.getElementById('modalPatientId');
            const modalityEl = document.getElementById('modalModality');
            const statusEl = document.getElementById('modalStatus');
            const versionEl = document.getElementById('modalVersion');
            const lastModEl = document.getElementById('modalLastModified');
            
            if (patientNameEl) patientNameEl.textContent = informe.nombre_paciente || informe.patient_name || 'N/A';
            if (patientIdEl) patientIdEl.textContent = informe.paciente_id || informe.patient_id || 'N/A';
            if (modalityEl) modalityEl.textContent = informe.modalidad || informe.modality || 'N/A';
            if (statusEl) statusEl.innerHTML = `<span class="badge bg-${informe.estado_badge || 'secondary'}">${this.escapeHtml(informe.estado || 'N/A')}</span>`;
            if (versionEl) versionEl.textContent = (informe.version || 'N/A');
            if (lastModEl) lastModEl.textContent = (informe.fecha_modificacion_formatted || 'N/A');
            
            // Inicializar campos del formulario
            const reportStatusEl = document.getElementById('reportStatus');
            const reportNotesEl = document.getElementById('reportNotes');
            const reportTitleEl = document.getElementById('reportTitle');
            
            if (reportStatusEl) {
                reportStatusEl.value = informe.estado || 'borrador';
            }
            if (reportNotesEl) {
                reportNotesEl.value = informe.notas_revision || '';
            }
            if (reportTitleEl) {
                reportTitleEl.value = informe.titulo || '';
            }
            
            // Asegurar inicialización de TinyMCE antes de usarlo
            if (!this.state.tinymceEditor) {
                await this.initTinyMCE();
            }

            // Cargar contenido en TinyMCE
            if (this.state.tinymceEditor) {
                const contenidoInicial = informe.contenido_html || '';
                
                // Asegurar que originalReportData tenga el contenido inicial ANTES de cargar en TinyMCE
                // Esto garantiza que siempre exista para comparación
                if (this.state.originalReportData) {
                    this.state.originalReportData.contenido_html = contenidoInicial;
                    const titleField = document.getElementById('reportTitle');
                    if (titleField) {
                        this.state.originalReportData.titulo = titleField.value || informe.titulo || '';
                    }
                    
                    console.log('📋 Datos originales guardados (ANTES de TinyMCE):', {
                        id: this.state.originalReportData?.id,
                        titulo: this.state.originalReportData?.titulo,
                        contenidoLength: (this.state.originalReportData?.contenido_html || '').length
                    });
                }
                
                // Cargar el contenido en TinyMCE
                this.state.tinymceEditor.setContent(contenidoInicial);
                
                // Actualizar originalReportData con el contenido que TinyMCE retorna (después de normalización)
                // Usar múltiples métodos para asegurar que se actualice
                const updateOriginalData = () => {
                    const contenidoTinyMCE = this.state.tinymceEditor.getContent();
                    if (this.state.originalReportData) {
                        this.state.originalReportData.contenido_html = contenidoTinyMCE;
                        const titleField = document.getElementById('reportTitle');
                        if (titleField) {
                            this.state.originalReportData.titulo = titleField.value || informe.titulo || '';
                        }
                        
                        console.log('📋 Datos originales actualizados (después de TinyMCE):', {
                            id: this.state.originalReportData?.id,
                            titulo: this.state.originalReportData?.titulo,
                            contenidoLength: (this.state.originalReportData?.contenido_html || '').length,
                            contenidoOriginalLength: contenidoInicial.length,
                            contenidoTinyMCELength: contenidoTinyMCE.length
                        });
                    }
                };
                
                // Actualizar inmediatamente después de cargar
                setTimeout(updateOriginalData, 50);
                
                // También escuchar eventos de TinyMCE por si acaso
                this.state.tinymceEditor.once('SetContent', updateOriginalData);
                
                // En TinyMCE 6, usar mode.set() en lugar de setMode()
                if (readOnly) {
                    this.state.tinymceEditor.mode.set('readonly');
                } else {
                    this.state.tinymceEditor.mode.set('design');
                }
                
                // Configurar detección de cambios en TinyMCE (si no es solo lectura)
                if (!readOnly) {
                    // Remover listeners anteriores si existen para evitar duplicados
                    this.state.tinymceEditor.off('Change');
                    this.state.tinymceEditor.off('change');
                    this.state.tinymceEditor.off('paste');
                    
                    // IMPORTANTE: NO escuchar 'NodeChange' porque se dispara al mover el cursor/selección
                    // sin cambios reales de contenido. Solo genera falsos positivos.
                    // NO escuchar 'keyup' porque el evento 'change' ya captura todos los cambios de contenido.
                    
                    // Evento 'Change' con mayúscula - se dispara solo cuando HAY cambios reales de contenido
                    this.state.tinymceEditor.on('Change', () => {
                        console.log('📝 TinyMCE Change detectado (cambio de contenido real)');
                        this.markAsModified();
                        // NO activar autosave - solo se guarda al hacer click en "Guardar Cambios"
                    });
                    
                    // También escuchar 'change' con minúscula por compatibilidad
                    this.state.tinymceEditor.on('change', () => {
                        console.log('📝 TinyMCE change detectado (cambio de contenido real)');
                        this.markAsModified();
                        // NO activar autosave - solo se guarda al hacer click en "Guardar Cambios"
                    });
                    
                    // Escuchar paste para capturar cambios al pegar contenido
                    this.state.tinymceEditor.on('paste', () => {
                        console.log('📋 TinyMCE paste detectado (cambio de contenido al pegar)');
                        this.markAsModified();
                        // NO activar autosave - solo se guarda al hacer click en "Guardar Cambios"
                    });
                    
                    console.log('✅ Listeners de TinyMCE configurados correctamente (sin NodeChange ni keyup)');
                    
                    // También detectar cambios en título
                    const titleField = document.getElementById('reportTitle');
                    if (titleField) {
                        // Remover listener anterior si existe
                        if (this._titleChangeHandler) {
                            titleField.removeEventListener('input', this._titleChangeHandler);
                        }
                        this._titleChangeHandler = () => {
                            console.log('📝 Cambio en título detectado');
                            this.markAsModified();
                            // NO activar autosave - solo se guarda al hacer click en "Guardar Cambios"
                        };
                        titleField.addEventListener('input', this._titleChangeHandler);
                        console.log('✅ Listener de título configurado correctamente');
                    }
                }

                this.bindTinyMcePdfViewerClicks(this.state.tinymceEditor);
                this.updateFloatingTranscriptionInsertButton();
            }
            
            // Mostrar/ocultar botones según el modo
            const saveBtn = document.getElementById('btnSaveReport');
            const previewPDFBtn = document.getElementById('btnPreviewPDF');
            const exportPDFBtn = document.getElementById('btnExportPDF');
            const firmarBtn = document.getElementById('btnFirmarInforme');

            const isExterno = (informe.origen === 'externo') || (informe.editable === false);
            if (isExterno && !readOnly) {
                // Forzar solo lectura de contenido para externos
                readOnly = true;
                this.state.isReadOnly = true;
                if (titleEl) {
                    titleEl.textContent = 'Ver Informe (PDF externo): ' + (informe.titulo || 'Sin título');
                }
                if (this.state.tinymceEditor) {
                    try { this.state.tinymceEditor.mode.set('readonly'); } catch (e) {}
                }
            }
            
            if (saveBtn) {
                saveBtn.style.display = (readOnly || isExterno) ? 'none' : 'inline-block';
                saveBtn.disabled = readOnly || isExterno;
            }

            if (firmarBtn) {
                const canFirmar = (String(informe.estado || '').toLowerCase() === 'transcripto')
                    && (this.state.canFirmarInformes !== false);
                firmarBtn.style.display = canFirmar ? 'inline-block' : 'none';
                firmarBtn.disabled = !canFirmar;
            }
            
            // Los botones PDF están disponibles tanto en modo lectura como edición
            if (previewPDFBtn) {
                previewPDFBtn.style.display = 'inline-block';
                previewPDFBtn.disabled = false;
            }
            
            if (exportPDFBtn) {
                exportPDFBtn.style.display = 'inline-block';
                exportPDFBtn.disabled = false;
            }
            
            // Cargar audios si existen
            // 1) Preferir audios ya incluidos en la respuesta de api/informes/get.php (campo informe.audios)
            //    porque usan la misma lógica de conteo que la columna "Audios" de la grilla.
            // 2) Si no vienen embebidos, usar API de audios como respaldo (informe_id y/o estudio_id).
            const hasInlineAudios = Array.isArray(informe.audios) && informe.audios.length > 0;
            if (hasInlineAudios) {
                console.log('🎧 Usando audios embebidos en el informe (informe.audios):', {
                    total: informe.audios.length,
                    ids: informe.audios.map(a => a.id)
                });
                this.populateEditAudioList(informe.audios);
                this.showEditAudioSection(true);
            } else if (reportId && reportId !== 'null' && reportId !== null) {
                // Informe existente: usar informe_id Y estudio_id como respaldo
                const estudioId = informe.estudio_id || informe.study_id || informe.study_instance_uid || null;
                await this.loadEditModalAudios(reportId, estudioId);
            } else if (informe && (informe.estudio_id || informe.study_id || informe.study_instance_uid)) {
                // Informe nuevo (sin ID todavía): cargar audios del estudio
                const estudioId = informe.estudio_id || informe.study_id || informe.study_instance_uid;
                await this.loadEditModalAudios(null, estudioId);
            } else {
                // No hay información suficiente, ocultar sección
                this.showEditAudioSection(false);
            }
            
            // Inicializar sistema de hotkeys para el reproductor de audio
            // Usar then para manejar la promesa async sin bloquear
            this.initEditAudioHotkeys().catch(err => {
                console.error('Error inicializando hotkeys:', err);
            });
            
            // Limpiar completamente el estado del modal antes de abrirlo
            const existingBackdrops = document.querySelectorAll('.modal-backdrop');
            existingBackdrops.forEach(backdrop => {
                try {
                    backdrop.remove();
                } catch (e) {
                    console.warn('Error removiendo backdrop:', e);
                }
            });
            
            // Remover clase modal-open del body
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Mostrar modal (ID correcto)
            const modalEl = document.getElementById('reportEditorModal');
            if (modalEl) {
                // Eliminar cualquier instancia previa del modal
                const existingModalInstance = bootstrap.Modal.getInstance(modalEl);
                if (existingModalInstance) {
                    try {
                        existingModalInstance.dispose();
                    } catch (e) {
                        console.warn('Error eliminando instancia previa del modal:', e);
                    }
                }
                
                // Asegurar que el modal no tenga clases residuales
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
                modalEl.setAttribute('aria-hidden', 'true');
                modalEl.removeAttribute('aria-modal');
                
                // Configurar modal SIN backdrop para evitar que bloquee la interacción
                // La prevención de cierre se manejará con eventos
                const modal = new bootstrap.Modal(modalEl, {
                    backdrop: false, // Sin backdrop para evitar bloqueos
                    keyboard: false  // No permitir cerrar con ESC (lo manejaremos manualmente)
                });
                
                // Configurar detección de cierre del modal ANTES de mostrarlo
                this.setupModalCloseDetection();
                
                // Modal mostrado - sin backdrop
                modalEl.addEventListener('shown.bs.modal', () => {
                    // Agregar clase modal-open al body para mantener el scroll
                    document.body.classList.add('modal-open');
                    
                    // Asegurar que el modal esté visible
                    modalEl.style.zIndex = '1060';
                    console.log('Modal abierto sin backdrop');
                }, { once: true });
                
                // Configurar botón de pantalla completa del modal
                const toggleFullscreenBtn = document.getElementById('toggleFullscreenModal');
                const fullscreenIcon = document.getElementById('fullscreenModalIcon');
                const modalDialog = modalEl.querySelector('.modal-dialog');
                let isFullscreen = false;
                
                if (toggleFullscreenBtn && modalDialog) {
                    toggleFullscreenBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        isFullscreen = !isFullscreen;
                        
                        if (isFullscreen) {
                            // Activar pantalla completa
                            modalDialog.classList.add('modal-fullscreen');
                            modalEl.classList.add('modal-fullscreen');
                            if (fullscreenIcon) {
                                fullscreenIcon.classList.remove('fa-expand');
                                fullscreenIcon.classList.add('fa-compress');
                            }
                            toggleFullscreenBtn.setAttribute('title', 'Salir de pantalla completa');
                        } else {
                            // Desactivar pantalla completa
                            modalDialog.classList.remove('modal-fullscreen');
                            modalEl.classList.remove('modal-fullscreen');
                            if (fullscreenIcon) {
                                fullscreenIcon.classList.remove('fa-compress');
                                fullscreenIcon.classList.add('fa-expand');
                            }
                            toggleFullscreenBtn.setAttribute('title', 'Pantalla completa');
                        }
                    });
                    
                    // Resetear pantalla completa al cerrar el modal
                    modalEl.addEventListener('hidden.bs.modal', () => {
                        if (isFullscreen) {
                            modalDialog.classList.remove('modal-fullscreen');
                            modalEl.classList.remove('modal-fullscreen');
                            if (fullscreenIcon) {
                                fullscreenIcon.classList.remove('fa-compress');
                                fullscreenIcon.classList.add('fa-expand');
                            }
                            toggleFullscreenBtn.setAttribute('title', 'Pantalla completa');
                            isFullscreen = false;
                        }
                    });
                }
                
                // Mostrar el modal
                modal.show();
                
                // Limpiar cuando se cierre el modal
                modalEl.addEventListener('hidden.bs.modal', () => {
                    // Detener y hacer unload del audio si está reproduciéndose
                    this.cleanupEditAudio();
                    
                    // Limpiar hotkeys
                    this.cleanupEditAudioHotkeys();
                    
                    // Limpiar clase modal-open del body si no hay otros modales
                    const openModals = document.querySelectorAll('.modal.show');
                    if (openModals.length === 0) {
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                    }
                }, { once: true });
                
                // Actualizar visibilidad de botones según permisos
                this.updateCreateReportButtonsVisibility();
                this.updateAttachReportButtonVisibility();
            }
            
        } catch (error) {
            console.error('Error abriendo modal:', error);
            this.showError('Error al cargar el informe: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    },

    /**
     * Lista de audios desde get-audios-root.php: data es { audios: [], total, informe_id } o, en legacy, un array.
     */
    parseAudiosFromGetAudiosRootResponse: function(responseJson) {
        const raw = responseJson && responseJson.data !== undefined ? responseJson.data : null;
        if (Array.isArray(raw)) {
            return raw;
        }
        if (raw && Array.isArray(raw.audios)) {
            return raw.audios;
        }
        return [];
    },

    /**
     * Cargar audios del informe
     */
    loadReportAudios: async function(reportId) {
        try {
            const token = this.getSessionToken();
            if (!token) {
                throw new Error('Token de sesión no encontrado');
            }

            const response = await fetch(`../get-audios-root.php?informe_id=${reportId}`, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Error al cargar audios');
            }
            
            const audios = this.parseAudiosFromGetAudiosRootResponse(data);
            this.renderAudioControls(audios);
        } catch (error) {
            console.error('Error cargando audios:', error);
            this.renderNoAudioMessage();
        }
    },

    /**
     * Renderizar controles de audio
     */
    renderAudioControls(audios) {
        const container = document.getElementById('modalAudioControls');
        if (!container) return;
        
        if (audios.length === 0) {
            this.renderNoAudioMessage();
            return;
        }
        
        container.innerHTML = `
            <div class="audio-controls mb-3">
                <h6><i class="fas fa-microphone text-primary"></i> Audios del Informe (${audios.length})</h6>
                <div class="audio-list mb-3">
                    ${audios.map((audio, index) => {
                        const fileName = audio.ruta_archivo ? audio.ruta_archivo.split('/').pop() : `Audio ${index + 1}`;
                        const duration = audio.duracion_formatted || '00:00';
                        const size = audio.tamaño_formatted || 'N/A';
                        const isAvailable = audio.archivo_existe !== false;
                        
                        return `
                            <div class="audio-item card mb-2">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="audio-info flex-grow-1">
                                            <div class="fw-bold d-flex align-items-center">
                                                <i class="fas fa-file-audio me-2 text-primary"></i>
                                                ${fileName}
                                                ${!isAvailable ? '<span class="badge bg-danger ms-2">No disponible</span>' : ''}
                                            </div>
                                            <div class="mt-1">
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>Duración: ${duration}
                                                    <span class="mx-2">|</span>
                                                    <i class="fas fa-hdd me-1"></i>Tamaño: ${size}
                                                </small>
                                            </div>
                                            ${audio.fecha_creacion_formatted ? `
                                                <small class="text-muted d-block mt-1">
                                                    <i class="fas fa-calendar me-1"></i>Creado: ${audio.fecha_creacion_formatted}
                                                </small>
                                            ` : ''}
                                        </div>
                                        <div class="audio-controls">
                                            ${isAvailable ? `
                                                <button class="btn btn-sm btn-primary" 
                                                        onclick="InformesManager.loadAndPlayAudio('${audio.ruta_archivo}', ${JSON.stringify(audio).replace(/"/g, '&quot;')})" 
                                                        title="Reproducir audio">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                            ` : `
                                                <button class="btn btn-sm btn-secondary" disabled title="Archivo no disponible">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </button>
                                            `}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    },

    /**
     * Renderizar mensaje de sin audio
     */
    renderNoAudioMessage() {
        const container = document.getElementById('modalAudioControls');
        if (!container) return;
        
        container.innerHTML = `
            <div class="text-center text-muted py-3">
                <i class="fas fa-microphone-slash mb-2" style="font-size: 1.5rem;"></i>
                <p class="mb-0">No hay audios asociados a este informe</p>
            </div>
        `;
    },

    /**
     * Reproducir archivo de audio
     */
    playAudioFile(audioPath, index) {
        // Detener audio actual si existe
        if (this.state.audioPlayer) {
            this.state.audioPlayer.pause();
            this.state.audioPlayer.currentTime = 0;
            this.state.audioPlayer = null;
        }
        
        // Resetear todos los iconos
        document.querySelectorAll('[id^="playIcon"]').forEach(icon => {
            icon.className = 'fas fa-play';
        });
        
        const playIcon = document.getElementById(`playIcon${index}`);
        
        try {
            this.state.audioPlayer = new Audio();
            this.state.audioPlayer.src = audioPath;
            this.state.audioPlayer.preload = 'metadata';
            
            this.state.audioPlayer.addEventListener('loadedmetadata', () => {
                console.log('Audio loaded, duration:', this.state.audioPlayer.duration);
            });
            
            this.state.audioPlayer.addEventListener('play', () => {
                playIcon.className = 'fas fa-pause';
            });
            
            this.state.audioPlayer.addEventListener('pause', () => {
                playIcon.className = 'fas fa-play';
            });
            
            this.state.audioPlayer.addEventListener('ended', () => {
                playIcon.className = 'fas fa-play';
                this.state.audioPlayer = null;
                this.resetAudioControls();
            });
            
            this.state.audioPlayer.addEventListener('timeupdate', () => {
                this.updateAudioProgress(this.state.audioPlayer);
            });
            
            this.state.audioPlayer.addEventListener('error', (e) => {
                console.error('Error reproduciendo audio:', e);
                this.showError('Error al reproducir el audio');
                playIcon.className = 'fas fa-play';
                this.state.audioPlayer = null;
            });
            
            // Alternar reproducción/pausa
            if (this.state.audioPlayer.paused) {
                this.state.audioPlayer.play();
                this.showToast('Reproduciendo audio', 'success');
            } else {
                this.state.audioPlayer.pause();
            }
        } catch (error) {
            console.error('Error creando reproductor de audio:', error);
            this.showError('Error al inicializar el reproductor de audio');
        }
    },

    /**
     * Reproducir archivo de audio
     */
    playAudioFile(audioPath, index) {
        // Detener audio actual si existe
        if (this.state.audioPlayer) {
            this.state.audioPlayer.pause();
            this.state.audioPlayer.currentTime = 0;
            this.state.audioPlayer = null;
        }
        
        // Resetear todos los iconos
        document.querySelectorAll('[id^="playIcon"]').forEach(icon => {
            icon.className = 'fas fa-play';
        });
        
        const playIcon = document.getElementById(`playIcon${index}`);
        
        try {
            this.state.audioPlayer = new Audio();
            this.state.audioPlayer.src = audioPath;
            this.state.audioPlayer.preload = 'metadata';
            
            this.state.audioPlayer.addEventListener('loadedmetadata', () => {
                console.log('Audio loaded, duration:', this.state.audioPlayer.duration);
            });
            
            this.state.audioPlayer.addEventListener('play', () => {
                if (playIcon) playIcon.className = 'fas fa-pause';
            });
            
            this.state.audioPlayer.addEventListener('pause', () => {
                if (playIcon) playIcon.className = 'fas fa-play';
            });
            
            this.state.audioPlayer.addEventListener('ended', () => {
                if (playIcon) playIcon.className = 'fas fa-play';
                this.state.audioPlayer = null;
            });
            
            this.state.audioPlayer.addEventListener('error', (e) => {
                console.error('Error reproduciendo audio:', e);
                this.showError('Error al reproducir el audio');
                if (playIcon) playIcon.className = 'fas fa-play';
            });
            
            // Reproducir el audio
            this.state.audioPlayer.play().catch(error => {
                console.error('Error iniciando reproducción:', error);
                this.showError('Error al reproducir el audio');
                if (playIcon) playIcon.className = 'fas fa-play';
            });
            
        } catch (error) {
            console.error('Error creando reproductor de audio:', error);
            this.showError('Error al inicializar el reproductor de audio');
        }
    },

    /**
     * Obtener cookie por nombre
     */
    getCookie: function(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    },

    /**
     * Reproducir audio (función pública para botones de tabla)
     */
    playAudio: async function(reportId) {
        try {
            console.log('playAudio llamado con reportId:', reportId);
            
            // Obtener información del informe para el modal
            const reportData = this.state.reports.find(r => r.id == reportId);
            if (!reportData) {
                this.showError('No se encontró información del informe');
                return;
            }
            
            // Abrir modal de reproductor de audios
            this.openAudioPlayerModal(reportData);
            
        } catch (error) {
            console.error('Error abriendo reproductor de audios:', error);
            this.showError('Error al abrir el reproductor de audios');
        }
    },

    /**
     * Abrir modal de reproductor de audios
     */
    openAudioPlayerModal: async function(reportData) {
        try {
            console.log('=== DEBUG: openAudioPlayerModal ===');
            console.log('reportData recibido:', reportData);
            
            // Actualizar información del modal
            document.getElementById('audioModalPatientName').textContent = reportData.patient_name || '-';
            document.getElementById('audioModalPatientId').textContent = reportData.patient_id || '-';
            document.getElementById('audioModalModality').textContent = reportData.modality || '-';
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('audioPlayerModal'));
            modal.show();
            
            console.log('Modal mostrado, cargando audios para reportId:', reportData.id);
            
            // Cargar audios del estudio
            await this.loadAudiosForModal(reportData.id, reportData.estudio_id, reportData.patient_id);
            
        } catch (error) {
            console.error('Error abriendo modal de audio:', error);
            this.showError('Error al abrir el reproductor de audios');
        }
    },

    /**
     * Cargar audios para el modal (usando la misma lógica que el reproductor funcional)
     */
    loadAudiosForModal: async function(reportId, estudioId, patientId) {
        try {
            console.log('=== DEBUG: loadAudiosForModal ===');
            console.log('Parámetros:', { reportId, estudioId, patientId });
            
            const token = this.getSessionToken();
            if (!token) {
                throw new Error('Token de sesión no encontrado');
            }

            console.log('Token obtenido:', token ? 'OK' : 'FALTA');
            
            // Usar la misma API que el reproductor funcional
            const url = `../get-audios-root.php?informe_id=${reportId}`;
            console.log('URL de la API:', url);
            
            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            console.log('Respuesta de la API:', response.status, response.statusText);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Datos recibidos de la API:', data);
            
            if (!data.success) {
                throw new Error(data.error || 'Error al cargar audios');
            }
            
            const audios = this.parseAudiosFromGetAudiosRootResponse(data);
            console.log('Audios extraídos:', audios);
            console.log('Cantidad de audios:', audios.length);
            
            this.renderAudioListModal(audios);
            document.getElementById('audioModalTotalCount').textContent = audios.length;
        } catch (error) {
            console.error('Error cargando audios:', error);
            this.renderNoAudioMessageModal();
        }
    },

    /**
     * Renderizar lista de audios en el modal (replicando la lógica funcional)
     */
    renderAudioListModal: function(audios) {
        const container = document.getElementById('audioListContainer');
        
        if (!audios || audios.length === 0) {
            this.renderNoAudioMessageModal();
            return;
        }
        
        container.innerHTML = `
            <div class="audio-controls mb-3">
                <h6><i class="fas fa-microphone text-primary"></i> Audios del Informe (${audios.length})</h6>
                <div class="audio-list mb-3">
                    ${audios.map((audio, index) => {
                        const fileName = audio.ruta_archivo ? audio.ruta_archivo.split('/').pop() : `Audio ${index + 1}`;
                        const duration = audio.duracion_formatted || '00:00';
                        const size = audio.tamaño_formatted || 'N/A';
                        const isAvailable = audio.archivo_existe !== false;
                        
                        return `
                            <div class="audio-item card mb-2">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="audio-info flex-grow-1">
                                            <div class="fw-bold d-flex align-items-center">
                                                <i class="fas fa-file-audio me-2 text-primary"></i>
                                                ${fileName}
                                                ${!isAvailable ? '<span class="badge bg-danger ms-2">No disponible</span>' : ''}
                                            </div>
                                            <div class="mt-1">
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>Duración: ${duration}
                                                    <span class="mx-2">|</span>
                                                    <i class="fas fa-hdd me-1"></i>Tamaño: ${size}
                                                </small>
                                            </div>
                                            ${audio.fecha_creacion_formatted ? `
                                                <small class="text-muted d-block mt-1">
                                                    <i class="fas fa-calendar me-1"></i>Creado: ${audio.fecha_creacion_formatted}
                                                </small>
                                            ` : ''}
                                        </div>
                                        <div class="audio-controls">
                                            ${isAvailable ? `
                                                <button class="btn btn-sm btn-primary" 
                                                        onclick="InformesManager.loadAndPlayAudioModal('${audio.ruta_archivo}', ${JSON.stringify(audio).replace(/"/g, '&quot;')})" 
                                                        title="Reproducir audio">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                            ` : `
                                                <button class="btn btn-sm btn-secondary" disabled title="Archivo no disponible">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </button>
                                            `}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
        
        // Guardar audios en el estado
        this.state.currentAudios = audios;
    },

    /**
     * Renderizar mensaje de sin audio para el modal
     */
    renderNoAudioMessageModal: function() {
        const container = document.getElementById('audioListContainer');
        if (!container) return;
        
        container.innerHTML = `
            <div class="text-center text-muted py-3">
                <i class="fas fa-microphone-slash mb-2" style="font-size: 1.5rem;"></i>
                <p class="mb-0">No hay audios asociados a este informe</p>
            </div>
        `;
    },

    /**
     * Cargar y reproducir audio en el modal (replicando la lógica funcional)
     */
    loadAndPlayAudioModal: function(audioPath, audioData) {
        if (!audioPath) {
            this.showToast('Error: Ruta de audio no válida', 'error');
            return;
        }

        // Detener audio actual si está reproduciéndose
        if (this.state.currentAudio && !this.state.currentAudio.paused) {
            this.state.currentAudio.pause();
            this.state.currentAudio.currentTime = 0;
        }

        // Construir la URL completa del audio
        const fullAudioUrl = audioPath.startsWith('http') ? audioPath : `../audios/${audioPath}`;
        
        // Obtener el elemento de audio del modal
        const audioElement = document.getElementById('currentAudioPlayer');
        if (!audioElement) {
            this.showToast('Error: Elemento de audio no encontrado', 'error');
            return;
        }

        // Mostrar sección del reproductor
        this.showCurrentAudioPlayerModal();

        // Configurar el nuevo audio
        audioElement.src = fullAudioUrl;
        this.state.currentAudio = audioElement;
        this.state.currentAudioData = audioData;

        // Configurar eventos del audio
        this.setupAudioEventsModal(audioElement, audioData);

        // Actualizar información del audio actual
        this.updateCurrentAudioInfoModal(audioData);

        // Intentar cargar y reproducir
        audioElement.load();
        
        const playPromise = audioElement.play();
        if (playPromise !== undefined) {
            playPromise.then(() => {
                this.updatePlayPauseButtonModal('playing');
                this.updateAudioStatusModal('Reproduciendo...', 'success');
                this.showToast(`Reproduciendo: ${audioData.nombre_original || 'Audio'}`, 'success');
            }).catch(error => {
                console.error('Error al reproducir audio:', error);
                this.updatePlayPauseButtonModal('paused');
                this.updateAudioStatusModal('Error al reproducir', 'danger');
                this.showToast('Error al reproducir el audio', 'error');
            });
        }
    },

    /**
     * Configurar eventos del audio para el modal
     */
    setupAudioEventsModal: function(audioElement, audioData) {
        // Limpiar eventos anteriores
        audioElement.removeEventListener('timeupdate', this.handleTimeUpdateModal);
        audioElement.removeEventListener('ended', this.handleAudioEndedModal);
        audioElement.removeEventListener('error', this.handleAudioErrorModal);
        audioElement.removeEventListener('loadstart', this.handleLoadStartModal);
        audioElement.removeEventListener('canplay', this.handleCanPlayModal);

        // Configurar nuevos eventos
        this.handleTimeUpdateModal = () => {
            const progressBar = document.getElementById('audioProgressBar');
            if (progressBar && audioElement.duration) {
                const progress = (audioElement.currentTime / audioElement.duration) * 100;
                progressBar.style.width = progress + '%';
                progressBar.setAttribute('aria-valuenow', progress);
            }

            const currentTimeSpan = document.getElementById('currentTime');
            if (currentTimeSpan) {
                currentTimeSpan.textContent = this.formatTime(audioElement.currentTime);
            }
        };

        this.handleAudioEndedModal = () => {
            this.updatePlayPauseButtonModal('finished');
            this.updateAudioStatusModal('Reproducción finalizada', 'success');
            const progressBar = document.getElementById('audioProgressBar');
            if (progressBar) {
                progressBar.style.width = '100%';
                progressBar.setAttribute('aria-valuenow', 100);
            }
        };

        this.handleAudioErrorModal = (e) => {
            console.error('Error en audio:', e);
            this.updatePlayPauseButtonModal('error');
            this.updateAudioStatusModal('Error al cargar audio', 'danger');
            this.showToast('Error al cargar el archivo de audio', 'error');
        };

        this.handleLoadStartModal = () => {
            this.updateAudioStatusModal('Cargando audio...', 'warning');
        };

        this.handleCanPlayModal = () => {
            const durationSpan = document.getElementById('totalDuration');
            if (durationSpan && audioElement.duration) {
                durationSpan.textContent = this.formatTime(audioElement.duration);
            }
        };

        // Agregar eventos
        audioElement.addEventListener('timeupdate', this.handleTimeUpdateModal);
        audioElement.addEventListener('ended', this.handleAudioEndedModal);
        audioElement.addEventListener('error', this.handleAudioErrorModal);
        audioElement.addEventListener('loadstart', this.handleLoadStartModal);
        audioElement.addEventListener('canplay', this.handleCanPlayModal);
    },

    /**
     * Actualizar información del audio actual en el modal
     */
    updateCurrentAudioInfoModal: function(audioData) {
        const audioTitle = document.getElementById('currentAudioTitle');
        const audioInfo = document.getElementById('currentAudioInfo');
        
        if (audioTitle) {
            const fileName = audioData.ruta_archivo ? audioData.ruta_archivo.split('/').pop() : 'Audio';
            audioTitle.textContent = fileName;
        }
        
        if (audioInfo) {
            const duration = audioData.duracion_formatted || '00:00';
            const size = audioData.tamaño_formatted || 'N/A';
            audioInfo.innerHTML = `
                <small class="text-muted">
                    <i class="fas fa-clock me-1"></i>Duración: ${duration}
                    <span class="mx-2">|</span>
                    <i class="fas fa-hdd me-1"></i>Tamaño: ${size}
                </small>
            `;
        }
    },

    /**
     * Actualizar botón play/pause del modal
     */
    updatePlayPauseButtonModal: function(state) {
        const playPauseBtn = document.getElementById('playPauseBtn');
        if (!playPauseBtn) return;

        const icon = playPauseBtn.querySelector('i');
        if (!icon) return;

        switch (state) {
            case 'playing':
                icon.className = 'fas fa-pause';
                playPauseBtn.title = 'Pausar';
                playPauseBtn.disabled = false;
                break;
            case 'paused':
            case 'stopped':
                icon.className = 'fas fa-play';
                playPauseBtn.title = 'Reproducir';
                playPauseBtn.disabled = false;
                break;
            case 'finished':
                icon.className = 'fas fa-redo';
                playPauseBtn.title = 'Reproducir de nuevo';
                playPauseBtn.disabled = false;
                break;
            case 'error':
                icon.className = 'fas fa-exclamation-triangle';
                playPauseBtn.title = 'Error';
                playPauseBtn.disabled = true;
                break;
        }
    },

    /**
     * Actualizar estado del audio en el modal
     */
    updateAudioStatusModal: function(message, type) {
        const statusElement = document.getElementById('audioStatus');
        if (!statusElement) return;

        statusElement.textContent = message;
        statusElement.className = `text-${type === 'success' ? 'success' : type === 'warning' ? 'warning' : type === 'danger' ? 'danger' : 'muted'}`;
    },
    /**
     * Mostrar sección del reproductor de audio
     */
    showCurrentAudioPlayerModal: function() {
        const playerSection = document.getElementById('currentAudioPlayerSection');
        if (playerSection) {
            playerSection.style.display = 'block';
        }
    },

    /**
     * Ocultar sección del reproductor de audio
     */
    hideCurrentAudioPlayerModal: function() {
        const playerSection = document.getElementById('currentAudioPlayerSection');
        if (playerSection) {
            playerSection.style.display = 'none';
        }
    },

    /**
     * Toggle play/pause para el modal
     */
    toggleAudioPlayPauseModal: function() {
        const audioElement = document.getElementById('currentAudioPlayer');
        if (!audioElement || !audioElement.src) return;

        if (audioElement.paused) {
            const playPromise = audioElement.play();
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    this.updatePlayPauseButtonModal('playing');
                    this.updateAudioStatusModal('Reproduciendo...', 'success');
                }).catch(error => {
                    console.error('Error al reproducir:', error);
                    this.updatePlayPauseButtonModal('error');
                    this.updateAudioStatusModal('Error al reproducir', 'danger');
                });
            }
        } else {
            audioElement.pause();
            this.updatePlayPauseButtonModal('paused');
            this.updateAudioStatusModal('Pausado', 'warning');
        }
    },

    /**
     * Detener audio del modal
     */
    stopAudioModal: function() {
        const audioElement = document.getElementById('currentAudioPlayer');
        if (!audioElement) return;

        audioElement.pause();
        audioElement.currentTime = 0;
        this.updatePlayPauseButtonModal('stopped');
        this.updateAudioStatusModal('Detenido', 'muted');
        
        // Resetear barra de progreso
        const progressBar = document.getElementById('audioProgressBar');
        if (progressBar) {
            progressBar.style.width = '0%';
            progressBar.setAttribute('aria-valuenow', 0);
        }
        
        const currentTime = document.getElementById('currentTime');
        if (currentTime) {
            currentTime.textContent = '00:00';
        }
    },

    /**
     * Buscar posición en el audio del modal
     */
    seekAudioModal: function(event) {
        const audioElement = document.getElementById('currentAudioPlayer');
        if (!audioElement || !audioElement.duration) return;

        const progressContainer = event.currentTarget;
        const rect = progressContainer.getBoundingClientRect();
        const clickX = event.clientX - rect.left;
        const width = rect.width;
        const percentage = clickX / width;
        const newTime = percentage * audioElement.duration;
        
        audioElement.currentTime = newTime;
    },

    /**
     * Reproducir audio seleccionado (función original)
     */
    playSelectedAudio: function(audioIndex) {
        try {
            const audio = this.state.currentAudios[audioIndex];
            if (!audio) {
                this.showError('Audio no encontrado');
                return;
            }
            
            // Si es el mismo audio y está reproduciéndose, pausar
            if (this.state.currentAudioIndex === audioIndex && this.state.isAudioPlaying) {
                this.toggleAudioPlayPause();
                return;
            }
            
            // Si es el mismo audio pero pausado, reanudar
            if (this.state.currentAudioIndex === audioIndex && !this.state.isAudioPlaying) {
                this.toggleAudioPlayPause();
                return;
            }
            
            // Cargar nuevo audio
            this.loadAudioFile(audio, audioIndex);
            
        } catch (error) {
            console.error('Error en playSelectedAudio:', error);
            this.showError('Error al reproducir el audio');
        }
    },

    /**
     * Cargar archivo de audio
     */
    loadAudioFile: function(audioData, audioIndex) {
        if (!audioData) return;

        const audioElement = document.getElementById('mainAudioElement');
        if (!audioElement) return;

        this.state.currentAudioFile = audioData;
        this.state.currentAudioIndex = audioIndex;
        
        // Pausar cualquier audio que esté reproduciéndose
        audioElement.pause();
        audioElement.currentTime = 0;
        
        // Configurar nueva fuente
        audioElement.src = '../' + audioData.url_completa;
        
        // Actualizar información del reproductor
        document.getElementById('currentAudioTitle').textContent = audioData.nombre_original || audioData.nombre_archivo;
        document.getElementById('currentAudioInfo').textContent = 
            `Duración: ${this.formatDuration(audioData.duracion_segundos)} | Tamaño: ${this.formatFileSize(audioData.tamano_bytes)} | Creado: ${new Date(audioData.fecha_creacion).toLocaleString()}`;
        
        // Mostrar reproductor
        document.getElementById('currentAudioPlayer').style.display = 'block';
        
        // Actualizar estado visual de la lista
        this.updateAudioListState(audioIndex);
        
        // Cargar el audio
        audioElement.load();
        
        // Reproducir automáticamente
        const playPromise = audioElement.play();
        if (playPromise !== undefined) {
            playPromise.then(() => {
                this.state.isAudioPlaying = true;
                this.updateAudioListState(audioIndex);
                console.log('Audio iniciado correctamente');
            }).catch(error => {
                console.error('Error reproduciendo audio:', error);
                this.showError('Error al reproducir el audio: ' + error.message);
                this.state.isAudioPlaying = false;
                this.updateAudioListState(audioIndex);
            });
        }
    },

    /**
     * Actualizar estado visual de la lista de audios
     */
    updateAudioListState: function(activeIndex) {
        document.querySelectorAll('.audio-player').forEach((player, index) => {
            player.classList.remove('active');
            const playBtn = player.querySelector('.play-btn');
            if (playBtn) {
                if (index === activeIndex) {
                    player.classList.add('active');
                    playBtn.innerHTML = this.state.isAudioPlaying ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
                } else {
                    playBtn.innerHTML = '<i class="fas fa-play"></i>';
                }
            }
        });
    },

    /**
     * Reproducir/pausar audio
     */
    toggleAudioPlayPause: function() {
        const audioElement = document.getElementById('mainAudioElement');
        if (!audioElement || !this.state.currentAudioFile) return;

        if (this.state.isAudioPlaying) {
            audioElement.pause();
            this.state.isAudioPlaying = false;
        } else {
            const playPromise = audioElement.play();
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    this.state.isAudioPlaying = true;
                    this.updateAudioListState(this.state.currentAudioIndex);
                    console.log('Audio reanudado correctamente');
                }).catch(error => {
                    console.error('Error reproduciendo audio:', error);
                    this.showError('Error al reproducir el audio: ' + error.message);
                    this.state.isAudioPlaying = false;
                    this.updateAudioListState(this.state.currentAudioIndex);
                });
            }
        }
        this.updateAudioListState(this.state.currentAudioIndex);
    },

    /**
     * Formatear duración en segundos a mm:ss
     */
    formatDuration: function(seconds) {
        if (!seconds || isNaN(seconds)) return '0:00';
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = Math.floor(seconds % 60);
        return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
    },

    /**
     * Formatear tamaño de archivo
     */
    formatFileSize: function(bytes) {
        if (!bytes || isNaN(bytes)) return '0 B';
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
    },

    /**
     * Sincronizar audio con texto
     */
    syncAudioWithText(currentTime, syncData) {
        if (!syncData || !Array.isArray(syncData)) return;
        
        // Encontrar el segmento de texto correspondiente al tiempo actual
        const currentSegment = syncData.find(segment => 
            currentTime >= segment.start && currentTime <= segment.end
        );
        
        if (currentSegment && this.state.tinymceEditor) {
            // Resaltar texto en TinyMCE
            const editor = this.state.tinymceEditor;
            
            try {
                // Remover resaltados anteriores
                let content = editor.getContent();
                content = content.replace(/<span class="audio-highlight"[^>]*>/g, '').replace(/<\/span>/g, '');
                
                // Agregar nuevo resaltado si hay texto específico
                if (currentSegment.text) {
                    const highlightedText = `<span class="audio-highlight" style="background-color: #ffeb3b; padding: 2px;">${currentSegment.text}</span>`;
                    content = content.replace(currentSegment.text, highlightedText);
                    editor.setContent(content);
                }
                
                console.log('Highlighting text segment:', currentSegment);
            } catch (error) {
                console.error('Error syncing audio with text:', error);
            }
        }
    },

    /**
     * Actualizar controles de audio
     */
    updateAudioControls(audio, audioData) {
        const audioControls = document.querySelector('.audio-controls');
        if (audioControls) {
            const duration = audioData.duracion_formatted || '00:00';
            audioControls.innerHTML = `
                <div class="audio-info">
                    <span class="audio-title">Reproduciendo: ${audioData.ruta_archivo.split('/').pop()}</span>
                    <span class="audio-duration">${duration}</span>
                </div>
                <div class="audio-progress">
                    <div class="progress-bar" style="width: 0%"></div>
                </div>
                <div class="audio-buttons">
                    <button class="btn btn-sm btn-secondary" onclick="InformesManager.pauseAudio()">Pausar</button>
                    <button class="btn btn-sm btn-danger" onclick="InformesManager.stopAudio()">Detener</button>
                </div>
            `;
        }
    },

    /**
     * Actualizar progreso del audio
     */
    updateAudioProgress(audio) {
        const progressBar = document.querySelector('.progress-bar');
        if (progressBar && audio.duration) {
            const progress = (audio.currentTime / audio.duration) * 100;
            progressBar.style.width = `${progress}%`;
        }
    },

    /**
     * Resetear controles de audio
     */
    resetAudioControls() {
        const audioControls = document.querySelector('.audio-controls');
        if (audioControls) {
            audioControls.innerHTML = '<p class="text-muted">No hay audio reproduciéndose</p>';
        }
    },

    /**
      * Toggle play/pause audio
      */
     togglePlayPause() {
         if (!this.state.audioPlayer) return;
         
         if (this.state.audioPlayer.paused) {
             this.state.audioPlayer.play();
             this.updatePlayPauseButton(true);
             this.updateAudioStatus('Reproduciendo');
         } else {
             this.state.audioPlayer.pause();
             this.updatePlayPauseButton(false);
             this.updateAudioStatus('Pausado');
         }
     },

     /**
      * Pausar audio
      */
     pauseAudio() {
         if (this.state.audioPlayer && !this.state.audioPlayer.paused) {
             this.state.audioPlayer.pause();
             this.updatePlayPauseButton(false);
             this.updateAudioStatus('Pausado');
             this.showToast('Audio pausado', 'info');
         }
     },

     /**
      * Detener audio
      */
     stopAudio() {
         if (this.state.audioPlayer) {
             this.state.audioPlayer.pause();
             this.state.audioPlayer.currentTime = 0;
             this.state.audioPlayer = null;
             this.updatePlayPauseButton(false);
             this.updateAudioStatus('Detenido');
             this.resetAudioProgress();
             this.showToast('Audio detenido', 'info');
         }
     },

     /**
      * Actualizar botón play/pause
      */
     updatePlayPauseButton(isPlaying) {
         const playPauseIcon = document.getElementById('playPauseIcon');
         if (playPauseIcon) {
             playPauseIcon.className = isPlaying ? 'fas fa-pause' : 'fas fa-play';
         }
     },

     /**
      * Actualizar estado del audio
      */
     updateAudioStatus(status) {
         const audioStatus = document.getElementById('audioStatus');
         if (audioStatus) {
             audioStatus.textContent = status;
             audioStatus.className = `badge ${
                 status === 'Reproduciendo' ? 'bg-success' :
                 status === 'Pausado' ? 'bg-warning' :
                 'bg-secondary'
             }`;
         }
     },

     /**
      * Resetear progreso del audio
      */
     resetAudioProgress() {
         const audioProgress = document.getElementById('audioProgress');
         const currentTime = document.getElementById('currentTime');
         const totalTime = document.getElementById('totalTime');
         
         if (audioProgress) audioProgress.style.width = '0%';
         if (currentTime) currentTime.textContent = '00:00';
         if (totalTime) totalTime.textContent = '00:00';
     },

     /**
      * Formatear tiempo en MM:SS
      */
     formatTime(seconds) {
         const minutes = Math.floor(seconds / 60);
         const remainingSeconds = Math.floor(seconds % 60);
         return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
     },

     /**
      * Configurar eventos del reproductor de audio
      */
     setupAudioEvents(audio, audioData) {
         // Evento cuando se carga la metadata
         audio.addEventListener('loadedmetadata', () => {
             const totalTime = document.getElementById('totalTime');
             if (totalTime) {
                 totalTime.textContent = this.formatTime(audio.duration);
             }
             
             const audioFileName = document.getElementById('audioFileName');
             if (audioFileName && audioData) {
                 audioFileName.textContent = audioData.ruta_archivo.split('/').pop();
             }
         });

         // Evento de actualización de tiempo
         audio.addEventListener('timeupdate', () => {
             const currentTime = audio.currentTime;
             const duration = audio.duration;
             
             // Actualizar tiempo actual
             const currentTimeElement = document.getElementById('currentTime');
             if (currentTimeElement) {
                 currentTimeElement.textContent = this.formatTime(currentTime);
             }
             
             // Actualizar barra de progreso
             const progressBar = document.getElementById('audioProgressBar');
             if (progressBar && duration > 0) {
                 const percentage = (currentTime / duration) * 100;
                 progressBar.style.width = `${percentage}%`;
             }
             
             // Sincronización con texto si está habilitada
             const enableSync = document.getElementById('syncAudioText')?.checked;
             if (enableSync && this.state.tinymceEditor) {
                 this.syncAudioWithText(currentTime, audioData);
             }
         });

         // Evento cuando termina la reproducción
         audio.addEventListener('ended', () => {
             this.updatePlayPauseButton(false);
             this.updateAudioStatus('Finalizado');
             this.resetAudioProgress();
         });

         // Evento de error
         audio.addEventListener('error', (e) => {
             console.error('Error en el audio:', e);
             this.showToast('Error al reproducir el audio', 'error');
             this.updateAudioStatus('Error');
         });
     },

     /**
      * Configurar controles de volumen y progreso
      */
     setupAudioControls() {
         // Control de volumen
         const volumeSlider = document.getElementById('volumeSlider');
         if (volumeSlider) {
             volumeSlider.addEventListener('input', (e) => {
                 if (this.state.audioPlayer) {
                     this.state.audioPlayer.volume = e.target.value / 100;
                 }
             });
         }

         // Control de progreso (click para saltar)
         const audioProgressBar = document.getElementById('audioProgressBar');
         if (audioProgressBar) {
             audioProgressBar.addEventListener('click', (e) => {
                 if (this.state.audioPlayer && this.state.audioPlayer.duration) {
                     const rect = audioProgressBar.getBoundingClientRect();
                     const clickX = e.clientX - rect.left;
                     const width = rect.width;
                     const percentage = clickX / width;
                     const newTime = percentage * this.state.audioPlayer.duration;
                     this.state.audioPlayer.currentTime = newTime;
                 }
             });
         }
     },

    /**
     * Guardar informe editado
     */
    saveReport: async function() {
        if (!this.state.selectedReport || !this.state.tinymceEditor) {
            this.showError('No hay informe seleccionado para guardar');
            return;
        }
        
        try {
            this.showLoading(true);
            
            const contenido_html = this.state.tinymceEditor.getContent();
            
            // Capturar valores actuales del formulario
            const reportStatus = document.getElementById('reportStatus')?.value || this.state.selectedReport.estado;
            const reportNotes = document.getElementById('reportNotes')?.value || this.state.selectedReport.notas_revision;
            const reportTitle = document.getElementById('reportTitle')?.value || this.state.selectedReport.titulo || '';
            
            // Validar datos antes de enviar
            if (!this.state.selectedReport.estudio_id) {
                this.showError('Error: El estudio ID es requerido para guardar el informe');
                return;
            }
            
            if (!contenido_html || contenido_html.trim() === '') {
                this.showError('Error: El contenido del informe no puede estar vacío');
                return;
            }
            
            const reportData = {
                // Solo incluir id si existe (no null ni undefined)
                ...(this.state.selectedReport.id && { id: this.state.selectedReport.id }),
                estudio_id: this.state.selectedReport.estudio_id,
                study_instance_uid: this.state.selectedReport.study_instance_uid || this.state.selectedReport.estudio_id,
                study_id: this.state.selectedReport.study_id || '',
                // Normalizar nombres de campos: aceptar tanto español como inglés
                patient_id: this.state.selectedReport.patient_id || this.state.selectedReport.paciente_id || '',
                patient_name: this.state.selectedReport.patient_name || this.state.selectedReport.nombre_paciente || '',
                modality: this.state.selectedReport.modality || this.state.selectedReport.modalidad || '',
                study_description: this.state.selectedReport.study_description || this.state.selectedReport.descripcion_estudio || '',
                contenido_html: contenido_html,
                titulo: reportTitle, // Capturar título actual del campo
                estado: reportStatus,
                notas_revision: reportNotes
            };

            // Capturar si era CREATE antes de mutar selectedReport con la respuesta.
            // Sólo create cambia total_informes; las ediciones no se notifican al dashboard.
            const wasCreate = !this.state.selectedReport.id;

            const token = this.getSessionToken();
            // Incluir token también en el cuerpo por compatibilidad
            reportData.session_token = token;
            
            console.log('📤 Enviando datos para guardar informe:', {
                id: reportData.id,
                estudio_id: reportData.estudio_id,
                contenido_html_length: reportData.contenido_html?.length || 0
            });
            
            const response = await fetch(`${this.config.apiBaseUrl}/save.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify(reportData)
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                // Intentar obtener el mensaje de error del servidor
                const errorMessage = data.message || data.error || `Error HTTP: ${response.status}`;
                throw new Error(errorMessage);
            }
            
            if (data.success) {
                // Actualizar selectedReport con los datos guardados del servidor
                this.state.selectedReport = {
                    ...this.state.selectedReport,
                    // Actualizar con datos del servidor si están disponibles
                    id: data.data?.id || data.data?.informe_id || this.state.selectedReport.id,
                    contenido_html: contenido_html,
                    titulo: reportTitle,
                    estado: reportStatus,
                    notas_revision: reportNotes,
                    // Asegurar que los datos del paciente estén presentes
                    patient_id: data.data?.patient_id || this.state.selectedReport.patient_id || this.state.selectedReport.paciente_id || '',
                    patient_name: data.data?.patient_name || this.state.selectedReport.patient_name || this.state.selectedReport.nombre_paciente || '',
                    modality: data.data?.modality || this.state.selectedReport.modality || this.state.selectedReport.modalidad || '',
                    study_description: data.data?.study_description || this.state.selectedReport.study_description || this.state.selectedReport.descripcion_estudio || '',
                    // También mantener formato español para compatibilidad con UI
                    nombre_paciente: data.data?.patient_name || this.state.selectedReport.patient_name || this.state.selectedReport.nombre_paciente || '',
                    paciente_id: data.data?.patient_id || this.state.selectedReport.patient_id || this.state.selectedReport.paciente_id || '',
                    modalidad: data.data?.modality || this.state.selectedReport.modality || this.state.selectedReport.modalidad || '',
                    descripcion_estudio: data.data?.study_description || this.state.selectedReport.study_description || this.state.selectedReport.descripcion_estudio || '',
                    // Actualizar versión y fecha si vienen en la respuesta
                    version: data.data?.version || this.state.selectedReport.version,
                    fecha_modificacion: data.data?.fecha_modificacion || this.state.selectedReport.fecha_modificacion,
                    fecha_modificacion_formatted: data.data?.fecha_modificacion_formatted || this.state.selectedReport.fecha_modificacion_formatted,
                    fecha_creacion: data.data?.fecha_creacion || this.state.selectedReport.fecha_creacion,
                    fecha_creacion_formatted: data.data?.fecha_creacion_formatted || this.state.selectedReport.fecha_creacion_formatted,
                    // Actualizar información del usuario con los datos del servidor
                    usuario_nombre: data.data?.usuario_nombre || this.state.currentUserName || '',
                    usuario_apellido: data.data?.usuario_apellido || this.state.currentUserLastName || ''
                };
                
                console.log('📋 Informe guardado - datos actualizados:', {
                    id: this.state.selectedReport.id,
                    patient_id: this.state.selectedReport.patient_id,
                    patient_name: this.state.selectedReport.patient_name,
                    modality: this.state.selectedReport.modality
                });
                
                this.showSuccess('Informe guardado correctamente');
                
                // CRÍTICO: Primero marcar como guardado para actualizar originalReportData
                this.markAsSaved(); // Marcar como guardado (esto también actualiza originalReportData)
                
                // Luego, forzar flags para permitir cierre y navegación libre
                this.state.allowModalClose = true;
                this.state.hasUnsavedChanges = false;
                this.state.pendingModalClose = false;
                this.state.warningModalShowing = false;
                this.state.pendingNavigationUrl = null;
                
                // Log para debugging
                console.log('✅ Informe guardado exitosamente - advertencias de navegación desactivadas');
                console.log('   - hasUnsavedChanges:', this.state.hasUnsavedChanges);
                console.log('   - allowModalClose:', this.state.allowModalClose);
                console.log('   - hasRealChanges():', this.hasRealChanges());

                // Notificar al dashboard si fue CREATE (las ediciones no alteran total_informes).
                // Como informes-manager se carga típicamente como sección hermana del dashboard
                // dentro de app-container.html, el storage event llega en vivo al dashboard
                // oculto y dispara el update incremental sin necesidad de refresh.
                if (wasCreate) {
                    try {
                        const sr = this.state.selectedReport || {};
                        const detail = {
                            informe_id: sr.id || (data.data && (data.data.id || data.data.informe_id)) || null,
                            study_id: sr.study_id || '',
                            study_instance_uid: sr.study_instance_uid || sr.estudio_id || '',
                            orthanc_id: sr.study_id || sr.estudio_id || '',
                            action: 'create',
                            source: 'informes-manager',
                            ts: Date.now()
                        };
                        try { window.dispatchEvent(new CustomEvent('informeFinalizado', { detail })); } catch (e) {}
                        if (window.parent && window.parent !== window) {
                            try { window.parent.dispatchEvent(new CustomEvent('informeFinalizado', { detail })); } catch (e) {}
                            try { window.parent.postMessage({ type: 'informeFinalizado', detail }, '*'); } catch (e) {}
                        }
                        try { localStorage.setItem('informe_finalizado_event', JSON.stringify(detail)); } catch (e) {}
                        console.log('📣 informeFinalizado notificado al dashboard:', detail);
                    } catch (e) {
                        console.warn('No se pudo notificar informeFinalizado:', e);
                    }
                }

                // NO cerrar el modal - permitir que el usuario siga editando
                // this.closeModal();
                this.loadReports(this.state.currentPage); // Recargar lista para mostrar cambios
            } else {
                throw new Error(data.error || 'Error al guardar el informe');
            }
            
        } catch (error) {
            console.error('Error guardando informe:', error);
            console.error('Detalles del error:', {
                message: error.message,
                stack: error.stack,
                selectedReport: this.state.selectedReport
            });
            
            // Mostrar mensaje de error más descriptivo
            let errorMessage = 'Error al guardar el informe: ';
            if (error.message) {
                errorMessage += error.message;
            } else {
                errorMessage += 'Error desconocido. Verifique la consola para más detalles.';
            }
            this.showError(errorMessage);
        } finally {
            this.showLoading(false);
        }
    },

    /**
     * Eliminar informe
     */
    showDeleteConfirmation: function(reportId, reportData) {
        // Validar si el informe está en PACS (verificar que los valores no sean null, undefined o strings vacíos)
        const hasPacsSeriesId = reportData.pacs_series_id && String(reportData.pacs_series_id).trim() !== '';
        const hasPacsInstanceId = reportData.pacs_instance_id && String(reportData.pacs_instance_id).trim() !== '';
        const hasPacsStudyId = reportData.pacs_study_id && String(reportData.pacs_study_id).trim() !== '';
        const isInPacs = hasPacsSeriesId || hasPacsInstanceId || hasPacsStudyId;
        
        if (isInPacs) {
            // Mostrar modal de error indicando que no se puede eliminar porque está en PACS
            this.showError('No se puede eliminar este informe porque está enviado a PACS. Primero debe eliminarlo de PACS antes de poder eliminarlo del sistema.');
            return;
        }
        
        // Poblar información del informe en el modal
        document.getElementById('deletePatientName').textContent = reportData.patient_name || '-';
        document.getElementById('deleteModality').textContent = reportData.modality || '-';
        document.getElementById('deleteStatus').textContent = reportData.estado || '-';
        document.getElementById('deleteDate').textContent = reportData.fecha_modificacion_formatted || reportData.fecha_modificacion || '-';
        
        // Configurar el botón de confirmación
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        confirmBtn.onclick = () => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
            modal.hide();
            this.executeDelete(reportId);
        };
        
        // Mostrar el modal sin backdrop de Bootstrap
        const modalElement = document.getElementById('deleteConfirmModal');
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: false,
            keyboard: true
        });
        
        // Agregar clase modal-open al body manualmente
        document.body.classList.add('modal-open');
        
        // Limpiar al cerrar
        const cleanupModalOpen = () => {
            // Remover backdrops residuales
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            
            // Remover clase modal-open si no hay otros modales abiertos
            const openModals = document.querySelectorAll('.modal.show');
            if (openModals.length === 0) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        };
        modalElement.addEventListener('hidden.bs.modal', cleanupModalOpen, { once: true });
        
        modal.show();
    },

    deleteReport: async function(reportId) {
        try {
            // Obtener datos del informe para mostrar en el modal de confirmación
            const token = this.getSessionToken();
            const url = `${this.config.apiBaseUrl}/get.php?informe_id=${reportId}${token ? `&token=${encodeURIComponent(token)}` : ''}`;
            const response = await fetch(url, {
                headers: token ? { 'Authorization': `Bearer ${token}` } : {}
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.data) {
                    this.showDeleteConfirmation(reportId, data.data);
                } else {
                    // Si no se puede obtener la info, mostrar modal básico
                    this.showDeleteConfirmation(reportId, {});
                }
            } else {
                if (response.status === 401) {
                    this.showError('No autorizado. Por favor, inicia sesión nuevamente.');
                }
                // Si no se puede obtener la info, mostrar modal básico
                this.showDeleteConfirmation(reportId, {});
            }
        } catch (error) {
            console.error('Error obteniendo datos del informe:', error);
            // Si hay error, mostrar modal básico
            this.showDeleteConfirmation(reportId, {});
        }
    },

    /**
     * Inicializar toggle switch global de formato PACS
     * Carga la configuración global y la aplica al toggle del encabezado
     */
    initPacsFormatToggles: async function() {
        if (!this.state.canSendToPacs || !this.state.canTogglePacsFormat) {
            return;
        }
        try {
            const baseUrl = this.config.apiBaseUrl.replace('/informes', '');
            const configUrl = `${baseUrl}/informes/config-formato-pacs.php`;
            const token = this.getSessionToken();
            
            const configResponse = await fetch(configUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                }
            });
            
            if (configResponse.ok) {
                const configData = await configResponse.json();
                if (configData.success && configData.formato) {
                    const formatoGlobal = configData.formato;
                    
                    // Aplicar al toggle switch global del encabezado
                    const toggleGlobal = document.getElementById('pacs-format-global');
                    if (toggleGlobal) {
                        // Si formato global es PNG/JPG, marcar como checked
                        toggleGlobal.checked = (formatoGlobal === 'jpg');
                        console.log('✅ Toggle switch global inicializado con formato:', formatoGlobal);
                    }
                }
            }
        } catch (error) {
            console.warn('⚠️ No se pudo cargar configuración global para toggle:', error);
            // Si falla, el toggle queda en PDF por defecto (no checked)
        }
    },

    /**
     * Enviar informe a Orthanc PACS
     */
    sendToPacs: async function(informeId) {
        // Verificar permiso antes de proceder
        if (!this.state.canSendToPacs) {
            this.showError('No tienes permiso para enviar informes a PACS');
            return;
        }
        
        // Verificar si el informe está marcado como incompleto
        // Buscar el informe en la lista para obtener su study_id
        const rows = document.querySelectorAll('[data-study-id]');
        let informeStudyId = null;
        let informeData = null;
        
        for (const row of rows) {
            const buttons = row.querySelectorAll(`[onclick*="sendToPacs(${informeId})"]`);
            if (buttons.length > 0) {
                informeStudyId = row.getAttribute('data-study-id');
                // Intentar obtener más información del informe desde el DOM o estado
                break;
            }
        }
        
        // Si no encontramos el study_id del DOM, buscar en los informes cargados
        if (!informeStudyId && this.state.reports) {
            const informe = this.state.reports.find(r => r.id === informeId);
            if (informe) {
                informeStudyId = informe.study_id || informe.study_instance_uid || informe.estudio_id;
                informeData = informe;
            }
        }
        
        // Verificar flag de incompletos
        if (informeStudyId) {
            let flag = this.state.studyFlags[informeStudyId];
            if (!flag && informeData) {
                // Buscar con claves alternativas
                const alternativeKeys = [
                    informeData.study_id,
                    informeData.study_instance_uid,
                    informeData.orthanc_id,
                    informeData.estudio_id
                ].filter(k => k && k !== informeStudyId);
                
                for (const altKey of alternativeKeys) {
                    if (this.state.studyFlags[altKey]) {
                        flag = this.state.studyFlags[altKey];
                        break;
                    }
                }
            }
            
            const isIncomplete = flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1);
            
            if (isIncomplete) {
                this.showToast('❌ No se puede enviar a PACS: el informe está marcado como incompleto', 'error');
                return;
            }
        }
        
        // Definir variables fuera del try para que estén disponibles en el catch
        const token = this.getSessionToken();
        const baseUrl = this.config.apiBaseUrl.replace('/informes', '');
        const url = `${baseUrl}/informes/send-to-pacs.php`;
        
        try {
            // Obtener formato del toggle switch global (en el encabezado de la tabla)
            let formatoSeleccionado = 'pdf'; // Valor por defecto
            
            // Buscar el toggle switch global del encabezado
            const toggleSwitch = document.getElementById('pacs-format-global');
            console.log('🔍 Toggle switch encontrado:', toggleSwitch);
            if (toggleSwitch) {
                // Si está checked = PNG, si no está checked = PDF
                console.log('🔍 Estado del toggle (checked):', toggleSwitch.checked);
                formatoSeleccionado = toggleSwitch.checked ? 'jpg' : 'pdf';
                console.log('📄 Formato seleccionado desde toggle global:', formatoSeleccionado);
                console.log('📄 Toggle está en:', toggleSwitch.checked ? 'PNG (derecha/naranja)' : 'PDF (izquierda/verde)');
            } else {
                // Fallback: obtener formato configurado desde la API si no hay toggle
                console.log('⚠️ Toggle switch global no encontrado, usando configuración global');
                try {
                    const configUrl = `${baseUrl}/informes/config-formato-pacs.php`;
                    const configResponse = await fetch(configUrl, {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                        }
                    });
                    
                    if (configResponse.ok) {
                        const configData = await configResponse.json();
                        if (configData.success && configData.formato) {
                            formatoSeleccionado = configData.formato;
                            console.log('📄 Formato configurado obtenido desde API:', formatoSeleccionado);
                        }
                    }
                } catch (configError) {
                    console.warn('⚠️ No se pudo obtener formato configurado, usando PDF por defecto:', configError);
                    formatoSeleccionado = 'pdf';
                }
            }
            
            // Mostrar modal de confirmación
            const formatoNombre = formatoSeleccionado === 'pdf' ? 'PDF' : 'PNG (Imagen)';
            const confirmed = await this.showConfirmSendToPacsModal(informeId, formatoNombre);
            
            if (!confirmed) {
                return;
            }
            
            // Marcar como en proceso de envío inmediatamente
            this.state.sendingToPacs.add(informeId);
            this.updateSendPacsButtonState(informeId, true);
            
            // Log detallado antes de enviar
            console.log('📤 Preparando envío a PACS:');
            console.log('  - Informe ID:', informeId);
            console.log('  - Formato seleccionado:', formatoSeleccionado);
            console.log('  - URL:', url);
            
            this.showLoading(true);
            
            const requestBody = {
                informe_id: informeId,
                format: formatoSeleccionado,  // 👈 Incluir formato del toggle switch
                check_duplicates: true
            };
            
            console.log('📤 Body de la petición:', JSON.stringify(requestBody, null, 2));
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                body: JSON.stringify(requestBody)
            });
            
            // Leer el texto de la respuesta una sola vez
            const responseText = await response.text();
            
            // Verificar que la respuesta es JSON válido antes de parsear
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                console.error('❌ Respuesta no es JSON. Content-Type:', contentType);
                console.error('Status HTTP:', response.status);
                console.error('Respuesta recibida (primeros 500 caracteres):', responseText.substring(0, 500));
                throw new Error(`El servidor respondió con un formato no JSON (Status: ${response.status}). Probablemente hay un error PHP en el servidor.`);
            }
            
            // Verificar status HTTP
            if (!response.ok) {
                let errorMessage = `Error HTTP ${response.status}`;
                
                // Mostrar la respuesta completa en consola para debugging
                console.error('❌ Respuesta del servidor con error:', responseText.substring(0, 1000));
                
                try {
                    const errorJson = JSON.parse(responseText);
                    
                    // Si es un error fatal, mostrar detalles completos
                    if (errorJson.error_code === 'FATAL_ERROR' && errorJson.error_details) {
                        console.error('❌ ERROR FATAL detectado:', errorJson.error_details);
                        console.error('   Tipo:', errorJson.error_details.type);
                        console.error('   Archivo:', errorJson.error_details.file);
                        console.error('   Línea:', errorJson.error_details.line);
                        console.error('   Mensaje:', errorJson.error_details.message);
                    }
                    
                    errorMessage = errorJson.message || errorMessage;
                    
                    // Mostrar el objeto completo para debugging
                    console.error('Objeto de error completo:', errorJson);
                } catch (e) {
                    // No es JSON, usar el texto directamente
                    errorMessage += ': ' + responseText.substring(0, 200);
                }
                throw new Error(errorMessage);
            }
            
            // Parsear JSON ahora que sabemos que es JSON válido
            const result = JSON.parse(responseText);
            
            // Debug: Mostrar respuesta completa en consola
            console.log('📦 Respuesta completa del servidor:', result);
            console.log('📋 Estructura de datos:', {
                'result.data': result.data,
                'result.conversion_method': result.conversion_method,
                'result.data?.conversion_method': result.data?.conversion_method,
                'result.data?.conversion_method_name': result.data?.conversion_method_name
            });
            
            if (result.success) {
                // Remover del set de procesamiento inmediato
                this.state.sendingToPacs.delete(informeId);

                if (result.skipped_concurrent) {
                    this.showToast(result.message || 'El informe ya constaba en PACS (envío concurrente).', 'success');
                    this.removePendingPacsSending(informeId);
                    this.updateSendPacsButtonState(informeId, false);
                    await this.loadReports(this.state.currentPage || 1);
                    return;
                }
                
                const message = result.is_update 
                    ? '✅ Informe actualizado y reenviado exitosamente a PORTAL ESTUDIOS'
                    : '✅ Informe enviado exitosamente a PORTAL ESTUDIOS';
                this.showToast(message, 'success');
                
                // PRIORIDAD 1: Verificar si la respuesta del servidor ya contiene datos PACS
                const responseHasPacsData = (result.data && result.data.series_id && String(result.data.series_id).trim() !== '') ||
                                           (result.data && result.data.instance_id && String(result.data.instance_id).trim() !== '');
                
                console.log('🔍 [sendToPacs] Respuesta del servidor contiene datos PACS:', responseHasPacsData, {
                    series_id: result.data?.series_id,
                    instance_id: result.data?.instance_id
                });
                
                if (responseHasPacsData) {
                    // Si la respuesta ya tiene datos PACS, actualizar UI inmediatamente
                    console.log('✅ [sendToPacs] Respuesta del servidor confirma envío exitoso, actualizando UI inmediatamente');
                    this.state.sendingToPacs.delete(informeId);
                    // Remover de pendientes si estaba ahí
                    this.removePendingPacsSending(informeId);
                    // NO agregar a pacsSendingPending porque ya está confirmado
                    // Esperar un momento para que el servidor actualice la BD
                    setTimeout(async () => {
                        await this.loadReports(this.state.currentPage || 1);
                    }, 500);
                } else {
                    // Si la respuesta no tiene datos PACS, verificar en el servidor
                    console.log('🔍 [sendToPacs] Respuesta no contiene datos PACS, verificando en servidor...');
                    
                    // Esperar un momento para que el servidor actualice la BD
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    const immediateCheck = await this.verifyPacsDataImmediately(informeId);
                    
                    if (immediateCheck.hasPacsData) {
                        // Si ya tiene datos PACS, actualizar UI inmediatamente
                        console.log('✅ [sendToPacs] Informe', informeId, 'ya tiene datos PACS en BD, actualizando UI inmediatamente');
                        this.state.sendingToPacs.delete(informeId);
                        // Remover de pendientes si estaba ahí
                        this.removePendingPacsSending(informeId);
                        // NO agregar a pacsSendingPending porque ya está confirmado
                        await this.loadReports(this.state.currentPage || 1);
                    } else {
                        // Si aún no tiene datos PACS, agregar a verificación pendiente para polling
                        console.log('⏳ [sendToPacs] Informe', informeId, 'aún no tiene datos PACS en BD, agregando a verificación pendiente');
                        this.addPendingPacsSending(informeId);
                    }
                }
                
                if (result.data) {
                    const logData = {
                        instance_id: result.data.instance_id,
                        study_id: result.data.study_id,
                        series_id: result.data.series_id || result.series_id || null,
                        file_size_mb: result.data.file_size_mb,
                        old_instance_id: result.old_instance_id || null
                    };
                    
                    // Agregar información del método de conversión si está disponible (formato JPG)
                    const method = result.data?.conversion_method || result.conversion_method;
                    if (method) {
                        const methodName = result.data?.conversion_method_name || 
                            (method === 'img2dcm_local' ? 'img2dcm (conversión local)' : 'Orthanc API Directa (conversión en servidor)');
                        
                        logData.conversion_method = method;
                        logData.conversion_method_name = methodName;
                        
                        // Log específico del método usado con más detalle
                        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                        console.log('📋 MÉTODO DE CONVERSIÓN DICOM');
                        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                        console.log('   🔧 Método usado:', methodName);
                        console.log('   📝 Código del método:', method);
                        
                        if (method === 'img2dcm_local') {
                            console.log('   ✅ Conversión LOCAL con img2dcm');
                            console.log('   📝 Flujo: PDF → JPGs → DICOMs (local) → Orthanc');
                            if (result.data.dicom_files_count) {
                                console.log('   📁 Archivos DICOM generados localmente:', result.data.dicom_files_count);
                            }
                        } else if (method === 'orthanc_api_direct') {
                            console.log('   ⚠️ Conversión en SERVIDOR (Fallback)');
                            console.log('   📝 Flujo: PDF → JPGs → Orthanc API (Orthanc crea DICOMs)');
                            console.log('   ⚠️ ADVERTENCIA: Este método puede no ser adecuado para Orthanc');
                        } else {
                            console.log('   ❓ Método desconocido:', method);
                        }
                        
                        if (result.data.pages_count) {
                            console.log('   📄 Páginas procesadas:', result.data.pages_count);
                        }
                        if (result.data.instance_ids) {
                            console.log('   🆔 Instancias creadas:', result.data.instance_ids.length);
                        }
                        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                    } else {
                        console.warn('⚠️ No se detectó información del método de conversión en la respuesta');
                        console.warn('   Esto podría indicar que se usó el formato PDF o hay un problema en el backend');
                    }
                    
                    console.log(result.is_update ? '✅ Informe actualizado en PACS:' : '✅ Informe enviado a PACS:', logData);
                    
                    if (result.is_update && result.old_instance_id) {
                        console.log('🗑️ Versión anterior eliminada:', result.old_instance_id);
                    }
                }
                
                // NO recargar la lista inmediatamente - el polling se encargará de verificar y actualizar
                // Solo actualizar el botón visualmente para mostrar que está en verificación
                this.updateSendPacsButtonState(informeId, true); // Mantener spinner
            } else {
                // Si es un error fatal, mostrar detalles completos en consola
                if (result.error_code === 'FATAL_ERROR' && result.error_details) {
                    console.error('❌ ERROR FATAL en el servidor:', result.error_details);
                    console.error('   Archivo:', result.error_details.file);
                    console.error('   Línea:', result.error_details.line);
                    console.error('   Mensaje:', result.error_details.message);
                    this.showToast('❌ Error fatal en el servidor. Ver consola para detalles.', 'error');
                } else if (result.duplicate) {
                    this.showToast('⚠️ Ya existe un estudio con estos datos en PORTAL ESTUDIOS', 'warning');
                    console.warn('Estudio duplicado encontrado:', {
                        study_id: result.study_id,
                        method: result.method
                    });
                } else {
                    // Remover del set de procesamiento en caso de error
                    this.state.sendingToPacs.delete(informeId);
                    this.removePendingPacsSending(informeId);
                    this.updateSendPacsButtonState(informeId, false);
                    
                    this.showToast('❌ Error al enviar informe a PORTAL ESTUDIOS: ' + (result.message || 'Error desconocido'), 'error');
                    console.error('Error enviando a PACS:', result);
                }
            }
        } catch (error) {
            // Remover del set de procesamiento en caso de error
            this.state.sendingToPacs.delete(informeId);
            this.removePendingPacsSending(informeId);
            this.updateSendPacsButtonState(informeId, false);
            
            console.error('Error en sendToPacs:', error);
            
            // Si el error es un SyntaxError, probablemente la respuesta no es JSON
            if (error instanceof SyntaxError || error.message.includes('JSON') || error.message.includes('Unexpected token')) {
                console.error('La respuesta del servidor no es JSON válido. Probablemente hay un error PHP.');
                this.showToast('❌ Error: El servidor respondió con un formato inválido. Revisa los logs del servidor.', 'error');
                
                // Intentar mostrar la respuesta en consola para debugging
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                        },
                        body: JSON.stringify({
                            informe_id: informeId,
                            check_duplicates: true
                        })
                    });
                    const text = await response.text();
                    console.error('Respuesta del servidor (texto):', text.substring(0, 500)); // Primeros 500 caracteres
                } catch (e) {
                    console.error('No se pudo obtener la respuesta del servidor:', e);
                }
            } else {
                this.showToast('❌ Error al enviar informe a PORTAL ESTUDIOS: ' + error.message, 'error');
            }
        } finally {
            this.showLoading(false);
        }
    },

    /**
     * Actualizar estado visual del botón de eliminar PACS
     */
    updateRemovePacsButtonState: function(informeId, isProcessing) {
        const button = document.getElementById(`btnRemovePacs-${informeId}`);
        if (!button) return;
        
        // PRIORIDAD: Si está en verificación pendiente, SIEMPRE mostrar spinner
        if (this.state.pacsVerificationPending.has(informeId)) {
            button.disabled = true;
            button.classList.remove('btn-outline-warning');
            button.classList.add('btn-secondary');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            button.title = 'Verificando eliminación...';
            return;
        }
        
        if (isProcessing) {
            button.disabled = true;
            button.classList.remove('btn-outline-warning');
            button.classList.add('btn-secondary');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            button.title = 'Eliminando de PACS...';
        } else {
            button.disabled = false;
            button.classList.remove('btn-secondary');
            button.classList.add('btn-outline-warning');
            button.innerHTML = '<i class="fas fa-cloud-download-alt"></i>';
            button.title = 'Eliminar de PACS';
        }
    },

    /**
     * Verificar el estado real del informe en PACS desde el servidor
     * y actualizar la UI si es necesario
     */
    verifyInformePacsState: async function(informeId) {
        try {
            const token = this.getSessionToken();
            const response = await fetch(`${this.config.apiBaseUrl}/get.php?informe_id=${informeId}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                console.warn('⚠️ No se pudo verificar estado del informe:', informeId);
                return;
            }

            const data = await response.json();
            // get.php?informe_id=X devuelve { data: informe } directamente
            const infRaw7 = (data.data && data.data.informes) ? data.data.informes[0] : data.data;
            if (data.success && infRaw7 && infRaw7.id) {
                const informe = infRaw7;
                const hasPacsData = this.informeHasPacsInOrthanc(informe);

                // Si el informe ya no tiene datos PACS, forzar actualización del botón
                if (!hasPacsData) {
                    // Remover del set de procesamiento si aún está ahí
                    this.state.removingFromPacs.delete(informeId);
                    
                    // Buscar el botón y el badge para verificar si necesitan actualización
                    const removeButton = document.getElementById(`btnRemovePacs-${informeId}`);
                    const rowElement = document.querySelector(`tr[data-informe-id="${informeId}"]`);
                    const badge = rowElement ? rowElement.querySelector('.badge.bg-success[title="Enviado a PACS"]') : null;
                    
                    // Si el botón de eliminar aún existe o el badge aún existe, forzar recarga
                    if (removeButton || badge) {
                        console.log('🔄 Informe', informeId, 'ya no tiene datos PACS pero la UI aún muestra elementos antiguos, forzando recarga completa');
                        // Forzar recarga completa de la página actual
                        await this.loadReports(this.state.currentPage || 1);
                    } else {
                        console.log('✅ Informe', informeId, 'ya está actualizado correctamente en la UI');
                    }
                } else {
                    console.log('⚠️ Informe', informeId, 'aún tiene datos PACS, no se actualizará');
                }
            }
        } catch (error) {
            console.error('❌ Error verificando estado del informe:', error);
        }
    },

    /**
     * Eliminar informe de Orthanc PACS
     * Elimina la serie del PACS y limpia los campos relacionados
     */
    removeFromPacs: async function(informeId) {
        const token = this.getSessionToken();
        const baseUrl = this.config.apiBaseUrl.replace('/informes', '');
        const url = `${baseUrl}/informes/remove-from-pacs.php`;
        
        try {
            // Mostrar modal de confirmación
            const confirmed = await this.showConfirmRemoveFromPacsModal(informeId);
            
            if (!confirmed) {
                return;
            }
            
            // Marcar como en proceso inmediatamente
            this.state.removingFromPacs.add(informeId);
            this.updateRemovePacsButtonState(informeId, true);
            
            // AGREGAR: Marcar como pendiente de verificación persistente
            this.addPendingPacsVerification(informeId);
            
            console.log('🗑️ Eliminando informe de PACS:', informeId);
            
            // Hacer la petición en background (sin mostrar loading global)
            let response;
            let responseText;
            
            try {
                response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    },
                    body: JSON.stringify({
                        informe_id: informeId
                    })
                });
                
                responseText = await response.text();
            } catch (fetchError) {
                // Manejar errores de red
                if (fetchError.name === 'TypeError' && fetchError.message.includes('Failed to fetch')) {
                    throw new Error('Error de conexión con el servidor. Verifica tu conexión a internet.');
                } else {
                    throw fetchError;
                }
            }
            
            // Verificar si la respuesta es HTML (error del servidor/proxy como 504)
            const contentType = response.headers.get('content-type') || '';
            const isHtmlResponse = responseText.trim().startsWith('<!DOCTYPE') || 
                                  responseText.trim().startsWith('<html') || 
                                  responseText.includes('Gateway Time-out') || 
                                  responseText.includes('Gateway Timeout') ||
                                  responseText.includes('<title>504') ||
                                  (!contentType.includes('application/json') && contentType.includes('text/html'));
            
            if (isHtmlResponse || response.status === 504) {
                // Es una respuesta HTML de error (probablemente 504 Gateway Timeout)
                console.warn('⚠️ Respuesta HTML recibida (probablemente 504):', responseText.substring(0, 300));
                throw new Error('El servidor tardó demasiado en responder (504 Gateway Timeout). La eliminación puede haberse completado en el servidor. El sistema verificará el estado automáticamente.');
            }
            
            let result;
            
            try {
                result = JSON.parse(responseText);
            } catch (e) {
                // Si no es JSON, puede ser un error del servidor
                console.error('❌ Error parseando respuesta JSON:', e);
                console.error('Respuesta recibida:', responseText.substring(0, 500));
                
                if (response.status >= 500) {
                    throw new Error(`Error del servidor (${response.status}). La eliminación puede haberse completado. El sistema verificará el estado automáticamente.`);
                } else {
                    throw new Error('Respuesta inválida del servidor: ' + responseText.substring(0, 200));
                }
            }
            
            if (response.ok && result.success) {
                console.log('✅ Informe eliminado de PACS:', result);
                
                // El API confirmó éxito: limpiar estado inmediatamente
                this.state.removingFromPacs.delete(informeId);
                this.removePendingPacsVerification(informeId);
                this.state.pacsConfirmedRemoved.add(informeId);
                
                // Mostrar toast de éxito
                this.showToast('✅ Informe eliminado exitosamente de PORTAL ESTUDIOS', 'success');
                
                // Recargar la tabla para quitar el badge "En PACS" y mostrar el botón de envío
                await this.loadReports(this.state.currentPage || 1);
            } else {
                // Remover del set de procesamiento en caso de error
                this.state.removingFromPacs.delete(informeId);
                // También remover de verificación pendiente en caso de error
                this.removePendingPacsVerification(informeId);
                
                const errorMsg = result.message || 'Error desconocido al eliminar de PORTAL ESTUDIOS';
                this.showToast('❌ Error al eliminar informe de PORTAL ESTUDIOS: ' + errorMsg, 'error');
                console.error('❌ Error eliminando de PACS:', result);
                
                // Recargar la lista para restaurar el estado correcto del botón
                console.log('🔄 Recargando lista para restaurar estado después de error');
                await this.loadReports(this.state.currentPage || 1);
            }
        } catch (error) {
            // Remover del set de procesamiento en caso de error
            this.state.removingFromPacs.delete(informeId);
            
            console.error('❌ Error en removeFromPacs:', error);
            
            // Determinar el mensaje de error apropiado
            let errorMessage = error.message;
            const isTimeoutError = errorMessage.includes('504') || 
                                  errorMessage.includes('Gateway Timeout') || 
                                  errorMessage.includes('Gateway Time-out') ||
                                  errorMessage.includes('tardó demasiado');
            
            if (isTimeoutError) {
                // En caso de timeout, mantener en verificación pendiente porque la eliminación puede haberse completado
                console.log('⏳ Error de timeout detectado, manteniendo informe en verificación pendiente');
                // NO remover de pacsVerificationPending - dejar que el polling verifique
                // El polling continuará verificando hasta confirmar o timeout
                errorMessage = 'El servidor tardó demasiado en responder. El sistema verificará automáticamente si la eliminación se completó.';
                this.showToast('⚠️ ' + errorMessage, 'warning');
            } else {
                // Para otros errores, remover de verificación pendiente
                this.removePendingPacsVerification(informeId);
                this.showToast('❌ Error al eliminar informe de PORTAL ESTUDIOS: ' + errorMessage, 'error');
                
                // Recargar la lista para restaurar el estado correcto del botón
                console.log('🔄 Recargando lista para restaurar estado después de error');
                try {
                    await this.loadReports(this.state.currentPage || 1);
                } catch (reloadError) {
                    console.error('❌ Error al recargar lista después de error:', reloadError);
                }
            }
        }
    },

    /**
     * Mostrar modal de confirmación para enviar a PACS
     * Retorna una Promise que se resuelve con true si el usuario confirma, false si cancela
     */
    showConfirmSendToPacsModal: function(informeId, formatoNombre) {
        return new Promise((resolve) => {
            // Actualizar el formato en el modal
            const formatoBadge = document.getElementById('modalConfirmarEnvioPacsFormato');
            if (formatoBadge) {
                formatoBadge.textContent = formatoNombre;
                formatoBadge.className = 'badge ms-2 ' + (formatoNombre === 'PDF' ? 'bg-secondary' : 'bg-info');
            }

            // Obtener los elementos del modal
            const modalElement = document.getElementById('modalConfirmarEnvioPacs');
            const confirmButton = document.getElementById('btnConfirmarEnvioPacs');
            
            if (!modalElement || !confirmButton) {
                console.error('Modal de confirmación de envío a PACS no encontrado');
                resolve(false);
                return;
            }

            // Limpiar listeners anteriores
            const newConfirmButton = confirmButton.cloneNode(true);
            confirmButton.parentNode.replaceChild(newConfirmButton, confirmButton);

            // Configurar listener para confirmar
            newConfirmButton.addEventListener('click', () => {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
                resolve(true);
            });

            // Configurar listener para cancelar (cuando se cierra el modal)
            const handleHidden = () => {
                modalElement.removeEventListener('hidden.bs.modal', handleHidden);
                resolve(false);
            };
            modalElement.addEventListener('hidden.bs.modal', handleHidden);

            // Mostrar modal
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: 'static',
                keyboard: false
            });
            
            // Asegurar z-index correcto antes de mostrar
            modalElement.style.zIndex = '10000';
            
            // Configurar el backdrop después de que se muestre
            modalElement.addEventListener('shown.bs.modal', () => {
                // Asegurar que el modal tenga el z-index correcto
                modalElement.style.zIndex = '10000';
                
                // Ajustar z-index del backdrop si existe
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach((backdrop, index) => {
                    // El último backdrop (del modal de PACS) debe estar por encima de otros pero debajo del modal
                    if (index === backdrops.length - 1) {
                        backdrop.style.zIndex = '9999';
                    }
                });
                
                // Asegurar que el contenido del modal sea clickeable
                const modalContent = modalElement.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.style.zIndex = '10001';
                    modalContent.style.position = 'relative';
                }
            }, { once: true });
            
            modal.show();
        });
    },

    /**
     * Mostrar modal de confirmación para eliminar de PACS
     * Retorna una Promise que se resuelve con true si el usuario confirma, false si cancela
     */
    showConfirmRemoveFromPacsModal: function(informeId) {
        return new Promise((resolve) => {
            // Obtener los elementos del modal
            const modalElement = document.getElementById('modalConfirmarEliminarPacs');
            const confirmButton = document.getElementById('btnConfirmarEliminarPacs');
            
            if (!modalElement || !confirmButton) {
                console.error('Modal de confirmación de eliminación de PACS no encontrado');
                resolve(false);
                return;
            }

            // Limpiar listeners anteriores
            const newConfirmButton = confirmButton.cloneNode(true);
            confirmButton.parentNode.replaceChild(newConfirmButton, confirmButton);

            // Configurar listener para confirmar
            newConfirmButton.addEventListener('click', () => {
                // Cerrar el modal primero
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
                // Resolver la promesa después de un pequeño delay para asegurar que el modal se cierre completamente
                setTimeout(() => {
                    resolve(true);
                }, 150);
            });

            // Configurar listener para cancelar (cuando se cierra el modal)
            const handleHidden = () => {
                modalElement.removeEventListener('hidden.bs.modal', handleHidden);
                resolve(false);
            };
            modalElement.addEventListener('hidden.bs.modal', handleHidden);

            // Mostrar modal
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: 'static',
                keyboard: false
            });
            
            // Asegurar z-index correcto antes de mostrar
            modalElement.style.zIndex = '10000';
            
            // Configurar el backdrop después de que se muestre
            modalElement.addEventListener('shown.bs.modal', () => {
                // Asegurar que el modal tenga el z-index correcto
                modalElement.style.zIndex = '10000';
                
                // Ajustar z-index del backdrop si existe
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach((backdrop, index) => {
                    // El último backdrop (del modal de eliminar PACS) debe estar por encima de otros pero debajo del modal
                    if (index === backdrops.length - 1) {
                        backdrop.style.zIndex = '9999';
                    }
                });
                
                // Asegurar que el contenido del modal sea clickeable
                const modalContent = modalElement.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.style.zIndex = '10001';
                    modalContent.style.position = 'relative';
                }
            }, { once: true });
            
            modal.show();
        });
    },

    executeDelete: async function(reportId) {
        try {
            this.showLoading(true);
            
            const token = this.getSessionToken();
            const url = `${this.config.apiBaseUrl}/delete.php?id=${reportId}${token ? `&token=${encodeURIComponent(token)}` : ''}`;
            const response = await fetch(url, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                body: JSON.stringify({ id: reportId })
            });
            
            if (!response.ok) {
                let detailMsg = '';
                try {
                    const errJson = await response.json();
                    if (errJson) {
                        detailMsg = [errJson.error, errJson.details, errJson.message].filter(Boolean).join(' - ');
                    }
                } catch (e) {
                    try {
                        const errText = await response.text();
                        if (errText) detailMsg = errText;
                    } catch (_) {}
                }
                
                if (response.status === 401) {
                    this.showError('No autorizado. Por favor, inicia sesión nuevamente.' + (detailMsg ? ` (${detailMsg})` : ''));
                } else if (response.status === 403) {
                    const msg = detailMsg || 'No se puede eliminar este informe. Solo se pueden eliminar informes en borrador o creados hace menos de 24 horas que no estén finalizados.';
                    this.showError(msg);
                } else {
                    this.showError(`Error al eliminar (HTTP ${response.status})` + (detailMsg ? `: ${detailMsg}` : ''));
                }
                this.showLoading(false);
                return;
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Informe eliminado correctamente');
                this.loadReports(this.state.currentPage);
                
                // Notificar al dashboard para que se actualice si está abierto
                // Esto actualizará el estado de "Incompleto" y el botón de informe
                if (data.data && (data.data.study_id || data.data.study_instance_uid || data.data.orthanc_id)) {
                    // Disparar evento personalizado para que el dashboard lo escuche
                    const updateEvent = new CustomEvent('informeEliminado', {
                        detail: {
                            study_id: data.data.study_id,
                            study_instance_uid: data.data.study_instance_uid,
                            orthanc_id: data.data.orthanc_id
                        }
                    });
                    window.dispatchEvent(updateEvent);
                    
                    // También intentar actualizar directamente si el dashboard está disponible
                    if (window.dashboardWithPermissions && typeof window.dashboardWithPermissions.loadStudies === 'function') {
                        console.log('🔄 Actualizando dashboard después de eliminar informe...');
                        window.dashboardWithPermissions.loadStudies().catch(err => {
                            console.warn('⚠️ Error actualizando dashboard:', err);
                        });
                    }

                    // Notificación cross-iframe: en la arquitectura de app-container.html,
                    // el dashboard vive en otro iframe hermano. dispatchEvent local no llega
                    // y window.dashboardWithPermissions tampoco está disponible. Replicamos
                    // el patrón multi-canal que ya usamos para informeFinalizado:
                    //  - postMessage al window padre (app-container) y a sus iframes
                    //  - localStorage 'informe_eliminado_event' (storage event en otros iframes)
                    // Cada paso en su propio try/catch para que un fallo aislado no rompa el flujo.
                    try {
                        const detailCross = {
                            informe_id: reportId,
                            study_id: data.data.study_id || '',
                            study_instance_uid: data.data.study_instance_uid || '',
                            orthanc_id: data.data.orthanc_id || '',
                            action: 'delete',
                            source: 'informes-manager',
                            ts: Date.now()
                        };
                        if (window.parent && window.parent !== window) {
                            try { window.parent.dispatchEvent(new CustomEvent('informeEliminado', { detail: detailCross })); } catch (eParent) {}
                            try { window.parent.postMessage({ type: 'informeEliminado', detail: detailCross }, '*'); } catch (ePost) {}
                        }
                        try { localStorage.setItem('informe_eliminado_event', JSON.stringify(detailCross)); } catch (eLs) {}
                        console.log('📣 informeEliminado notificado al dashboard (cross-iframe):', detailCross);
                    } catch (eNotif) {
                        console.warn('No se pudo notificar informeEliminado cross-iframe:', eNotif);
                    }
                }
            } else {
                throw new Error(data.error || 'Error al eliminar el informe');
            }
            
        } catch (error) {
            console.error('Error eliminando informe:', error);
            this.showError('Error al eliminar el informe: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    },

    /**
     * Mostrar modal de confirmación de Bootstrap
     */
    showConfirmModal(title, message, onConfirm, onCancel = null) {
        // Crear modal dinámicamente si no existe
        let confirmModalEl = document.getElementById('genericConfirmModal');
        if (!confirmModalEl) {
            const modalHtml = `
                <div class="modal fade" id="genericConfirmModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="genericConfirmModalTitle"></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body" id="genericConfirmModalBody"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="genericConfirmModalCancel">
                                    <i class="fas fa-times me-2"></i>Cancelar
                                </button>
                                <button type="button" class="btn btn-primary" id="genericConfirmModalConfirm">
                                    <i class="fas fa-check me-2"></i>Confirmar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            confirmModalEl = document.getElementById('genericConfirmModal');
        }
        
        // Actualizar contenido
        document.getElementById('genericConfirmModalTitle').textContent = title;
        document.getElementById('genericConfirmModalBody').textContent = message;
        
        // Configurar botones
        const confirmBtn = document.getElementById('genericConfirmModalConfirm');
        const cancelBtn = document.getElementById('genericConfirmModalCancel');
        
        // Limpiar listeners previos
        const newConfirmBtn = confirmBtn.cloneNode(true);
        const newCancelBtn = cancelBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
        
        // Agregar nuevos listeners
        document.getElementById('genericConfirmModalConfirm').addEventListener('click', () => {
            const modal = bootstrap.Modal.getInstance(confirmModalEl);
            if (modal) modal.hide();
            if (onConfirm) onConfirm();
        }, { once: true });
        
        if (onCancel) {
            document.getElementById('genericConfirmModalCancel').addEventListener('click', () => {
                const modal = bootstrap.Modal.getInstance(confirmModalEl);
                if (modal) modal.hide();
                onCancel();
            }, { once: true });
        }
        
        // Mostrar modal
        const modal = new bootstrap.Modal(confirmModalEl);
        modal.show();
    },

    /**
     * Cerrar modal
     */
    closeModal() {
        // CRÍTICO: Siempre verificar cambios REALES antes de mostrar advertencia
        // Si el usuario hizo click en "Cancelar", verificar si realmente hay cambios
        const hasRealChangesCheck = this.hasRealChanges();
        
        console.log('🔍 closeModal() llamado:', {
            allowModalClose: this.state.allowModalClose,
            hasUnsavedChanges: this.state.hasUnsavedChanges,
            hasRealChanges: hasRealChangesCheck
        });
        
        // Si NO hay cambios reales, permitir cierre libre inmediatamente
        if (!hasRealChangesCheck) {
            console.log('✅ No hay cambios reales - permitiendo cierre libre');
            this.state.allowModalClose = true;
            this.state.hasUnsavedChanges = false;
        }
        
        // Si NO se permite cierre libre Y hay cambios REALES, NO cerrar directamente
        // Dejar que el listener hide.bs.modal maneje la verificación
        if (!this.state.allowModalClose && hasRealChangesCheck) {
            console.log('⚠️ closeModal() llamado pero hay cambios REALES - delegando a hide.bs.modal');
            // Simular clic en botón cerrar para que se dispare el evento hide.bs.modal
            const modalElement = document.getElementById('reportEditorModal');
            if (modalElement) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    // Llamar hide() que disparará el evento hide.bs.modal
                    modal.hide();
                    return; // No limpiar estado aún, el evento manejará todo
                }
            }
        }
        
        // Si se permite cierre libre (después de guardar) o no hay cambios, cerrar normalmente
        const modal = bootstrap.Modal.getInstance(document.getElementById('reportEditorModal'));
        if (modal) {
            modal.hide();
        }
        
        // Limpiar estado solo si realmente se cerró
        // (el listener hidden.bs.modal también limpiará, pero esto es por seguridad)
        setTimeout(() => {
            if (!document.getElementById('reportEditorModal').classList.contains('show')) {
        this.state.selectedReport = null;
        this.state.originalReportData = null;
        this.state.hasUnsavedChanges = false;
        this.state.allowModalClose = true; // Permitir cierre libre para próxima apertura
            }
        }, 100);
        
        // Detener audio si está reproduciéndose
        if (this.state.audioPlayer) {
            this.state.audioPlayer.pause();
            this.state.audioPlayer = null;
        }
        
        // Limpiar TinyMCE
        if (this.state.tinymceEditor) {
            this.state.tinymceEditor.setContent('');
        }
    },

    /**
     * Spinner en el botón Buscar (listado, filtros, paginación) sin overlay de pantalla completa.
     */
    setSearchBtnLoading(show) {
        if (typeof this._searchBtnLoadingCount !== 'number') {
            this._searchBtnLoadingCount = 0;
        }
        if (show) {
            this._searchBtnLoadingCount += 1;
        } else {
            this._searchBtnLoadingCount = Math.max(0, this._searchBtnLoadingCount - 1);
        }
        const btn = document.getElementById('searchBtn');
        if (!btn) return;

        const active = this._searchBtnLoadingCount > 0;
        if (active) {
            if (btn.dataset.searchLoading === '1') return;
            btn.dataset.searchLoading = '1';
            if (!this._searchBtnOriginalHtml) {
                this._searchBtnOriginalHtml = btn.innerHTML;
            }
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML =
                '<span class="spinner-border spinner-border-sm me-sm-1" role="status" aria-hidden="true"></span>' +
                '<span class="visually-hidden">Buscando</span>' +
                '<span class="d-none d-sm-inline">Buscando…</span>';
        } else {
            if (btn.dataset.searchLoading !== '1') return;
            btn.dataset.searchLoading = '0';
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            if (this._searchBtnOriginalHtml) {
                btn.innerHTML = this._searchBtnOriginalHtml;
            }
        }
    },

    /**
     * Overlay de pantalla completa (guardar informe, PDF, adjuntos, etc.)
     */
    showLoading(show) {
        const loader = document.getElementById('loadingOverlay') || document.getElementById('loadingIndicator');
        if (loader) {
            const useFlex = loader.classList.contains('loading-overlay');
            loader.style.display = show ? (useFlex ? 'flex' : 'block') : 'none';
        }
    },

    /**
     * Asegurar que el modal y su backdrop tengan z-index correctos
     * Esta función debe llamarse después de que Bootstrap muestre el modal
     */
    ensureModalZIndex: function(modalElement, modalZIndex = 1055) {
        if (!modalElement) return;
        
        // Esperar un momento para que Bootstrap cree el backdrop
        setTimeout(() => {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            
            // Asegurar que todos los backdrops estén por debajo del modal
            backdrops.forEach((backdrop, index) => {
                if (index === backdrops.length - 1) {
                    // El último backdrop es del modal actual
                    backdrop.style.zIndex = (modalZIndex - 1).toString();
                } else {
                    // Otros backdrops deben estar por debajo
                    backdrop.style.zIndex = (modalZIndex - 2).toString();
                }
            });
            
            // Asegurar que el modal esté por encima de todos los backdrops
            modalElement.style.zIndex = modalZIndex.toString();
            modalElement.style.display = 'block';
        }, 50);
    },

    /**
     * Mostrar mensaje de éxito
     */
    showSuccess(message) {
        this.showToast(message, 'success');
    },

    /**
     * Mostrar mensaje de error
     */
    showError(message) {
        this.showToast(message, 'error');
    },

    /**
     * Mostrar toast notification para el reproductor de audio (siempre en la misma posición, reemplaza el anterior)
     */
    showAudioPlayerToast(message, type = 'info') {
        // Contenedor específico para toasts del reproductor
        let toastContainer = document.getElementById('audioPlayerToastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'audioPlayerToastContainer';
            // Posición fija en la parte inferior derecha para no interferir con el editor
            toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            toastContainer.style.zIndex = '10000';
            document.body.appendChild(toastContainer);
        }
        
        // Eliminar toast anterior si existe
        const existingToast = toastContainer.querySelector('.toast');
        if (existingToast) {
            const existingToastInstance = bootstrap.Toast.getInstance(existingToast);
            if (existingToastInstance) {
                existingToastInstance.hide();
            }
            existingToast.remove();
        }
        
        const toastId = 'audioPlayerToast_' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
        const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle';
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${icon} me-2"></i>
                        ${this.escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 2000 // Más corto para no interrumpir
        });
        
        toast.show();
        
        // Limpiar después de ocultar
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    },

    /**
     * Mostrar toast notification
     */
    showToast(message, type = 'info') {
        // Crear toast si no existe
        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        const toastId = 'toast_' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
        const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle';
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${icon} me-2"></i>
                        ${this.escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: type === 'error' ? 5000 : 3000
        });
        
        toast.show();
        
        // Limpiar después de ocultar
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    },

    /**
     * Configurar controles de audio avanzados
     */
    setupAudioControls() {
        // Configurar botón play/pause
        const playPauseBtn = document.getElementById('playPauseBtn');
        if (playPauseBtn) {
            playPauseBtn.addEventListener('click', () => this.togglePlayPause());
        }
        
        // Configurar botón stop
        const stopBtn = document.getElementById('stopBtn');
        if (stopBtn) {
            stopBtn.addEventListener('click', () => this.stopAudio());
        }
        
        // Configurar control de volumen
        const volumeSlider = document.getElementById('volumeSlider');
        if (volumeSlider) {
            volumeSlider.addEventListener('input', (e) => {
                if (this.state.audioPlayer) {
                    this.state.audioPlayer.volume = e.target.value / 100;
                }
            });
        }
        
        // Configurar barra de progreso para seeking
        const progressBar = document.getElementById('audioProgressBar');
        if (progressBar) {
            progressBar.addEventListener('click', (e) => {
                if (this.state.audioPlayer && this.state.audioPlayer.duration) {
                    const rect = progressBar.getBoundingClientRect();
                    const clickX = e.clientX - rect.left;
                    const width = rect.width;
                    const percentage = clickX / width;
                    const newTime = percentage * this.state.audioPlayer.duration;
                    this.state.audioPlayer.currentTime = newTime;
                }
            });
        }
    },

    /**
     * Alternar reproducción/pausa
     */
    togglePlayPause() {
        if (!this.state.audioPlayer) return;
        
        if (this.state.audioPlayer.paused) {
            this.state.audioPlayer.play();
            this.updatePlayPauseButton(true);
            this.updateAudioStatus('Reproduciendo');
        } else {
            this.state.audioPlayer.pause();
            this.updatePlayPauseButton(false);
            this.updateAudioStatus('Pausado');
        }
    },

    /**
     * Detener reproducción de audio
     */
    stopAudio() {
        if (this.state.audioPlayer) {
            this.state.audioPlayer.pause();
            this.state.audioPlayer.currentTime = 0;
            this.updatePlayPauseButton(false);
            this.updateAudioStatus('Detenido');
            this.resetAudioProgress();
        }
    },

    /**
     * Pausar reproducción de audio
     */
    pauseAudio() {
        if (this.state.audioPlayer && !this.state.audioPlayer.paused) {
            this.state.audioPlayer.pause();
            this.updatePlayPauseButton(false);
            this.updateAudioStatus('Pausado');
        }
    },

    /**
     * Actualizar botón play/pause
     */
    updatePlayPauseButton(isPlaying) {
        const playPauseBtn = document.getElementById('playPauseBtn');
        const icon = playPauseBtn?.querySelector('i');
        
        if (icon) {
            if (isPlaying) {
                icon.className = 'fas fa-pause';
                playPauseBtn.title = 'Pausar';
            } else {
                icon.className = 'fas fa-play';
                playPauseBtn.title = 'Reproducir';
            }
        }
    },

    /**
     * Actualizar estado del audio
     */
    updateAudioStatus(status) {
        const statusElement = document.getElementById('audioStatus');
        if (statusElement) {
            statusElement.textContent = status;
            
            // Agregar clase CSS según el estado
            statusElement.className = 'audio-status';
            if (status === 'Reproduciendo') {
                statusElement.classList.add('text-success');
            } else if (status === 'Pausado') {
                statusElement.classList.add('text-warning');
            } else if (status === 'Error') {
                statusElement.classList.add('text-danger');
            } else {
                statusElement.classList.add('text-muted');
            }
        }
    },

    /**
     * Resetear progreso del audio
     */
    resetAudioProgress() {
        const progressBar = document.getElementById('audioProgressBar');
        const currentTime = document.getElementById('currentTime');
        const totalTime = document.getElementById('totalTime');
        
        if (progressBar) progressBar.style.width = '0%';
        if (currentTime) currentTime.textContent = '00:00';
        if (totalTime) totalTime.textContent = '00:00';
    },

    /**
     * Formatear tiempo en formato MM:SS
     */
    formatTime(seconds) {
        if (isNaN(seconds) || seconds < 0) return '00:00';
        
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = Math.floor(seconds % 60);
        
        return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
    },

    /**
     * Configurar eventos del reproductor de audio
     */
    setupAudioEvents(audioPlayer, audioData) {
        audioPlayer.addEventListener('loadedmetadata', () => {
            const totalTimeElement = document.getElementById('totalTime');
            if (totalTimeElement) {
                totalTimeElement.textContent = this.formatTime(audioPlayer.duration);
            }
            console.log('Audio cargado, duración:', audioPlayer.duration);
        });
        
        audioPlayer.addEventListener('timeupdate', () => {
            const currentTime = audioPlayer.currentTime;
            const duration = audioPlayer.duration;
            
            // Actualizar tiempo actual
            const currentTimeElement = document.getElementById('currentTime');
            if (currentTimeElement) {
                currentTimeElement.textContent = this.formatTime(currentTime);
            }
            
            // Actualizar barra de progreso
            const progressBar = document.getElementById('audioProgressBar');
            if (progressBar && duration > 0) {
                const percentage = (currentTime / duration) * 100;
                progressBar.style.width = `${percentage}%`;
            }
            
            // Sincronizar con texto si está habilitado
            const enableSync = document.getElementById('syncAudioText')?.checked;
            if (enableSync && this.state.tinymceEditor) {
                this.syncAudioWithText(currentTime, audioData);
            }
        });
        
        audioPlayer.addEventListener('ended', () => {
            this.updatePlayPauseButton(false);
            this.updateAudioStatus('Finalizado');
            this.resetAudioProgress();
        });
        
        audioPlayer.addEventListener('error', (e) => {
            console.error('Error en reproductor de audio:', e);
            this.updateAudioStatus('Error');
            this.showToast('Error al reproducir audio', 'error');
        });
    },

    /**
     * Sincronizar audio con texto
     */
    syncAudioWithText(currentTime, audioData) {
        if (!this.state.tinymceEditor) return;
        
        // Remover highlights anteriores
        const editor = this.state.tinymceEditor;
        const content = editor.getContent();
        const cleanContent = content.replace(/<span class="audio-highlight"[^>]*>/g, '').replace(/<\/span>/g, '');
        
        // Aquí se podría implementar la lógica de sincronización
        // basada en timestamps o marcadores en el texto
        // Por ahora, solo se mantiene la funcionalidad básica
    },

    /**
     * Cargar y reproducir audio específico
     */
    loadAndPlayAudio(audioPath, audioData) {
        // Detener audio actual si existe
        this.stopAudio();
        
        try {
            // Usar la ruta tal como viene de la base de datos (ya es relativa)
            let fullAudioUrl = audioPath;
            if (audioPath && !audioPath.startsWith('http') && !audioPath.startsWith('../')) {
                fullAudioUrl = `../${audioPath.replace(/^\//, '')}`;
            }
            
            this.state.audioPlayer = new Audio();
            this.state.audioPlayer.src = fullAudioUrl;
            this.state.audioPlayer.preload = 'metadata';
            
            // Configurar eventos del reproductor
            this.setupAudioEvents(this.state.audioPlayer, audioData);
            
            // Iniciar reproducción
            this.state.audioPlayer.play().then(() => {
                this.updatePlayPauseButton(true);
                this.updateAudioStatus('Reproduciendo');
                this.showToast('Reproduciendo audio', 'success');
            }).catch(error => {
                console.error('Error al iniciar reproducción:', error);
                this.showToast('Error al reproducir audio', 'error');
                this.updateAudioStatus('Error');
            });
            
        } catch (error) {
            console.error('Error loading audio:', error);
            this.showToast('Error al cargar audio', 'error');
            this.updateAudioStatus('Error');
        }
    },

    updateCobranzaToolbarVisibility: function() {
        const b = document.getElementById('btnCobranzaPlanilla');
        if (!b) return;
        if (this.state.canDatosCobranza) {
            b.style.display = '';
            b.disabled = !this.state.cobranzaColumnsInstalled;
            b.title = this.state.cobranzaColumnsInstalled
                ? 'Listado provisorio y exportación CSV/Excel — planilla de códigos'
                : 'Instale las columnas de planilla de códigos (migrate-informes-cobranza-columns.php)';
        } else {
            b.style.display = 'none';
        }
    },

    cobranzaEditButtonHtml: function(informe) {
        if (!this.state.canDatosCobranza || !this.state.cobranzaColumnsInstalled) {
            return '';
        }
        const uid = this.state.currentUserId != null ? Number(this.state.currentUserId) : NaN;
        const owner = informe.usuario_id != null ? Number(informe.usuario_id) : NaN;
        if (!Number.isFinite(uid) || !Number.isFinite(owner) || uid !== owner) {
            return '';
        }
        return `<button type="button" class="btn btn-outline-dark btn-sm" onclick="InformesManager.openCobranzaEditModal(${informe.id})" title="Planilla de Códigos"><i class="fas fa-file-invoice-dollar"></i></button>`;
    },

    markCobranzaPlanillaDirty: function(dirty) {
        this.state.cobranzaPlanillaDirty = !!dirty;
        const btn = document.getElementById('cobranzaPlanillaGuardarBtn');
        if (btn) {
            btn.disabled = !dirty;
        }
    },

    /** Guarda valor inicial de planilla/códigos por fila (solo filas editables) para detectar cambios parciales. */
    snapshotCobranzaPlanillaEditableRows: function(tbody) {
        if (!tbody) return;
        tbody.querySelectorAll('tr[data-cobranza-informe-id]').forEach((tr) => {
            const ta = tr.querySelector('textarea.cobranza-planilla-estudio');
            const inp = tr.querySelector('input.cobranza-planilla-codigos');
            if (ta) ta.dataset.cobranzaInitial = ta.value;
            if (inp) inp.dataset.cobranzaInitial = inp.value;
        });
    },

    saveCobranzaPlanillaModalAll: async function() {
        if (!this.state.cobranzaPlanillaDirty) {
            return;
        }
        const tbody = document.getElementById('cobranzaPlanillaTbody');
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr[data-cobranza-informe-id]');
        const toSave = [];
        for (const tr of rows) {
            const id = parseInt(tr.getAttribute('data-cobranza-informe-id') || '0', 10);
            if (!id) continue;
            const ta = tr.querySelector('textarea.cobranza-planilla-estudio');
            const inp = tr.querySelector('input.cobranza-planilla-codigos');
            if (!ta || !inp) continue;
            const initPlan = ta.dataset.cobranzaInitial !== undefined ? ta.dataset.cobranzaInitial : '';
            const initReg = inp.dataset.cobranzaInitial !== undefined ? inp.dataset.cobranzaInitial : '';
            const planChanged = ta.value !== initPlan;
            const regChanged = inp.value.trim() !== String(initReg).trim();
            if (!planChanged && !regChanged) {
                continue;
            }
            const regionesRaw = inp.value.trim();
            let regiones;
            if (regionesRaw === '') {
                regiones = null;
            } else {
                regiones = parseInt(regionesRaw, 10);
                if (!Number.isFinite(regiones) || regiones < 0) {
                    this.showToast('Regiones inválidas (informe #' + id + ')', 'warning');
                    return;
                }
            }
            toSave.push({ id, planilla: ta.value, regiones });
        }
        if (toSave.length === 0) {
            this.markCobranzaPlanillaDirty(false);
            return;
        }
        const guardarBtn = document.getElementById('cobranzaPlanillaGuardarBtn');
        if (guardarBtn) guardarBtn.disabled = true;
        try {
            for (const row of toSave) {
                await this.saveCobranzaApi(row.id, row.regiones, row.planilla);
            }
            this.showToast('Planilla de códigos guardada', 'success');
            this.markCobranzaPlanillaDirty(false);
            await this.loadReports();
            await this.refreshCobranzaPlanillaModal();
        } catch (err) {
            console.error(err);
            this.showToast(err.message || 'Error al guardar', 'error');
            this.markCobranzaPlanillaDirty(true);
        }
    },

    openCobranzaPlanillaModal: async function() {
        if (!this.state.canDatosCobranza) {
            this.showToast('Sin permiso para planilla de códigos', 'warning');
            return;
        }
        const el = document.getElementById('cobranzaPlanillaModal');
        if (!el || typeof bootstrap === 'undefined') return;
        await this.refreshCobranzaPlanillaModal();
        bootstrap.Modal.getOrCreateInstance(el, { backdrop: true, keyboard: true }).show();
    },

    _cobranzaPlanillaQueryParams: function() {
        const fi = document.getElementById('fechaInicio')?.value || '';
        const ff = document.getElementById('fechaFin')?.value || '';
        const solo = document.getElementById('cobranzaSoloListos')?.checked ? 1 : 0;
        const p = new URLSearchParams();
        if (fi) p.set('fecha_inicio', fi);
        if (ff) p.set('fecha_fin', ff);
        p.set('solo_habilitados', String(solo));
        return p.toString();
    },

    refreshCobranzaPlanillaModal: async function() {
        this.markCobranzaPlanillaDirty(false);
        const token = this.getSessionToken();
        const qs = this._cobranzaPlanillaQueryParams();
        const url = `${this.config.apiBaseUrl}/export-planilla-cobranza.php?format=json&${qs}`;
        try {
            const res = await fetch(url, {
                method: 'GET',
                headers: {
                    ...(token ? { Authorization: 'Bearer ' + token } : {}),
                    Accept: 'application/json'
                }
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || ('HTTP ' + res.status));
            }
            this.renderCobranzaPlanillaTable(data.data);
        } catch (err) {
            console.error(err);
            this.showToast('Error al cargar planilla de códigos: ' + (err.message || err), 'error');
        }
    },

    renderCobranzaPlanillaTable: function(payload) {
        const modalities = payload.modalities || [];
        const rows = payload.rows || [];
        const thead = document.getElementById('cobranzaPlanillaThead');
        const tbody = document.getElementById('cobranzaPlanillaTbody');
        if (!thead || !tbody) return;

        const modHeads = modalities.map((m) => this.escapeHtml('CODIGOS ' + m));
        thead.innerHTML = `<tr>
            <th>Estado</th>
            <th>Fecha</th>
            <th>Paciente</th>
            <th>Mod.</th>
            <th>Estudio PACS</th>
            <th style="min-width: 200px;">Estudio planilla</th>
            ${modHeads.map((h) => '<th>' + h + '</th>').join('')}
        </tr>`;

        const uid = this.state.currentUserId != null ? Number(this.state.currentUserId) : NaN;
        let body = '';
        rows.forEach((r) => {
            const f = r.flags || {};
            const rowClass = f.listo_export ? 'table-success' : '';
            const estadoLabel = f.listo_export ? 'Listo' : 'Pendiente';
            const badges = [];
            if (!f.finalizado) badges.push('<span class="badge bg-secondary">No finalizado</span>');
            if (!f.datos_cobranza_ok) badges.push('<span class="badge bg-warning text-dark">Sin códigos cargados</span>');
            if (!f.pacs_ok) badges.push('<span class="badge bg-info text-dark">Sin PACS</span>');
            if (f.informe_incompleto || f.estudio_incompleto) badges.push('<span class="badge bg-danger">Incompleto</span>');

            const ownerId = r.usuario_id != null ? Number(r.usuario_id) : NaN;
            const editable = Number.isFinite(uid) && Number.isFinite(ownerId) && uid === ownerId;
            const planVal = r.estudio_planilla != null ? String(r.estudio_planilla) : '';
            const regVal = r.cobranza_regiones != null && r.cobranza_regiones !== '' ? String(r.cobranza_regiones) : '';
            const estudioCell = editable
                ? `<textarea class="form-control form-control-sm cobranza-planilla-estudio" data-informe-id="${r.id}" rows="2" maxlength="8000">${this.escapeHtml(planVal)}</textarea>`
                : `<small class="text-muted">${this.escapeHtml(planVal || '—')}</small>`;

            const rowMod = String(r.modality || '').trim().toUpperCase();
            let codigoInputMod = rowMod;
            if (editable && !codigoInputMod && modalities.length > 0) {
                codigoInputMod = modalities[0];
            }
            if (editable && codigoInputMod && modalities.indexOf(codigoInputMod) === -1 && modalities.length > 0) {
                codigoInputMod = modalities[0];
            }
            const codCells = modalities.map((m) => {
                const v = (r.codigos_por_modalidad && r.codigos_por_modalidad[m]) ? r.codigos_por_modalidad[m] : 0;
                if (editable && m === codigoInputMod) {
                    return `<td><input type="number" min="0" step="1" class="form-control form-control-sm cobranza-planilla-codigos" data-informe-id="${r.id}" value="${this.escapeHtml(regVal)}" placeholder="0" title="Regiones informadas (${this.escapeHtml(m)})"></td>`;
                }
                return `<td>${v}</td>`;
            }).join('');

            body += `<tr class="${rowClass}" data-cobranza-informe-id="${r.id}">
                <td><span class="badge ${f.listo_export ? 'bg-success' : 'bg-warning text-dark'}">${this.escapeHtml(estadoLabel)}</span><div class="mt-1 small">${badges.join(' ')}</div></td>
                <td><small>${this.escapeHtml(String(r.fecha || ''))}</small></td>
                <td>${this.escapeHtml(String(r.patient_name || ''))}</td>
                <td>${this.escapeHtml(String(r.modality || ''))}</td>
                <td><small>${this.escapeHtml(String(r.study_description_pacs || ''))}</small></td>
                <td>${estudioCell}</td>
                ${codCells}
            </tr>`;
        });

        const totals = payload.totals_by_modality || {};
        const sumCells = modalities.map((m) => `<td><strong>${this.escapeHtml(String(totals[m] ?? 0))}</strong></td>`).join('');
        body += `<tr class="table-light"><td colspan="6"><strong>TOTAL</strong></td>${sumCells}</tr>`;

        tbody.innerHTML = body;
        this.snapshotCobranzaPlanillaEditableRows(tbody);
        this.markCobranzaPlanillaDirty(false);
    },

    downloadCobranzaCsv: async function() {
        if (!this.state.canDatosCobranza) return;
        const token = this.getSessionToken();
        const qs = this._cobranzaPlanillaQueryParams();
        const url = `${this.config.apiBaseUrl}/export-planilla-cobranza.php?format=csv&${qs}`;
        try {
            const res = await fetch(url, {
                method: 'GET',
                headers: {
                    ...(token ? { Authorization: 'Bearer ' + token } : {})
                }
            });
            if (!res.ok) {
                const t = await res.text();
                throw new Error(t || ('HTTP ' + res.status));
            }
            const blob = await res.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = this.getDownloadFilenameFromResponse(res, 'planilla_codigos_' + new Date().toISOString().slice(0, 10) + '.csv');
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(a.href);
            this.showToast('CSV descargado', 'success');
        } catch (err) {
            console.error(err);
            this.showToast('Error al descargar CSV', 'error');
        }
    },

    downloadCobranzaExcel: async function() {
        if (!this.state.canDatosCobranza) return;
        const token = this.getSessionToken();
        const qs = this._cobranzaPlanillaQueryParams();
        const url = `${this.config.apiBaseUrl}/export-planilla-cobranza.php?format=excel&${qs}`;
        try {
            const res = await fetch(url, {
                method: 'GET',
                headers: {
                    ...(token ? { Authorization: 'Bearer ' + token } : {})
                }
            });
            if (!res.ok) {
                const t = await res.text();
                throw new Error(t || ('HTTP ' + res.status));
            }
            const blob = await res.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = this.getDownloadFilenameFromResponse(res, 'planilla_codigos_' + new Date().toISOString().slice(0, 10) + '.xls');
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(a.href);
            this.showToast('Excel descargado', 'success');
        } catch (err) {
            console.error(err);
            this.showToast('Error al descargar Excel', 'error');
        }
    },

    getDownloadFilenameFromResponse: function(res, fallbackName) {
        try {
            const cd = (res && res.headers && typeof res.headers.get === 'function')
                ? (res.headers.get('content-disposition') || '')
                : '';
            if (!cd) return fallbackName;
            const utf8Match = cd.match(/filename\*\s*=\s*UTF-8''([^;]+)/i);
            if (utf8Match && utf8Match[1]) {
                return decodeURIComponent(utf8Match[1].trim());
            }
            const plainMatch = cd.match(/filename\s*=\s*\"?([^\";]+)\"?/i);
            if (plainMatch && plainMatch[1]) {
                return plainMatch[1].trim();
            }
        } catch (e) {
            // fallback silencioso
        }
        return fallbackName;
    },

    openCobranzaEditModal: async function(informeId) {
        if (!this.state.canDatosCobranza || !this.state.cobranzaColumnsInstalled) {
            this.showToast('Planilla de códigos no disponible', 'warning');
            return;
        }
        const token = this.getSessionToken();
        const prefix = window.location.pathname.includes('/components/') ? '../' : '';
        const url = `${prefix}api/informes/get.php?informe_id=${encodeURIComponent(informeId)}`;
        try {
            const res = await fetch(url, {
                headers: {
                    ...(token ? { Authorization: 'Bearer ' + token } : {}),
                    Accept: 'application/json'
                }
            });
            const data = await res.json();
            if (!res.ok || !data.success || !data.data) {
                throw new Error(data.message || 'No se pudo cargar el informe');
            }
            const inf = Array.isArray(data.data.informes) ? data.data.informes[0] : data.data;
            if (!inf || !inf.id) {
                throw new Error('Respuesta inválida');
            }
            const owner = inf.usuario_id != null ? Number(inf.usuario_id) : NaN;
            const uid = this.state.currentUserId != null ? Number(this.state.currentUserId) : NaN;
            if (!Number.isFinite(owner) || !Number.isFinite(uid) || owner !== uid) {
                this.showToast('Solo el autor del informe puede editar la planilla de códigos', 'error');
                return;
            }
            document.getElementById('cobranzaEditInformeId').value = String(inf.id);
            document.getElementById('cobranzaEditEstudioPacs').value = inf.study_description || '';
            document.getElementById('cobranzaEditEstudioPlanilla').value = inf.cobranza_estudio_planilla || '';
            document.getElementById('cobranzaEditRegiones').value = inf.cobranza_regiones != null && inf.cobranza_regiones !== '' ? String(inf.cobranza_regiones) : '';
            const modalEl = document.getElementById('cobranzaEditModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: true, keyboard: true }).show();
            }
        } catch (err) {
            console.error(err);
            this.showToast(err.message || 'Error al abrir planilla de códigos', 'error');
        }
    },

    saveCobranzaApi: async function(informeId, regiones, planilla) {
        const token = this.getSessionToken();
        const res = await fetch(`${this.config.apiBaseUrl}/save-cobranza.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(token ? { Authorization: 'Bearer ' + token } : {})
            },
            body: JSON.stringify({
                informe_id: informeId,
                cobranza_regiones: regiones,
                cobranza_estudio_planilla: planilla,
                session_token: token || undefined
            })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || ('HTTP ' + res.status));
        }
        return data;
    },

    saveCobranzaEdit: async function() {
        const informeId = parseInt(document.getElementById('cobranzaEditInformeId').value, 10);
        const regionesRaw = document.getElementById('cobranzaEditRegiones').value;
        const planilla = document.getElementById('cobranzaEditEstudioPlanilla').value;
        if (!informeId) return;
        if (regionesRaw === '' || regionesRaw === null) {
            this.showToast('Indique la cantidad de regiones', 'warning');
            return;
        }
        const regiones = parseInt(regionesRaw, 10);
        if (!Number.isFinite(regiones) || regiones < 0) {
            this.showToast('Regiones inválidas', 'warning');
            return;
        }
        try {
            await this.saveCobranzaApi(informeId, regiones, planilla);
            this.showToast('Planilla de códigos guardada', 'success');
            const modalEl = document.getElementById('cobranzaEditModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getInstance(modalEl)?.hide();
            }
            await this.loadReports();
            if (document.getElementById('cobranzaPlanillaModal')?.classList.contains('show')) {
                await this.refreshCobranzaPlanillaModal();
            }
        } catch (err) {
            console.error(err);
            this.showToast(err.message || 'Error al guardar', 'error');
        }
    },

    /**
     * Exportar informe a PDF
     */
    exportToPDF: async function() {
        if (!this.state.selectedReport) {
            this.showToast('No hay informe seleccionado para exportar', 'error');
            return;
        }

        try {
            this.showLoading(true);
            
            // Verificar que PDFModule esté disponible
            if (typeof PDFModule === 'undefined') {
                throw new Error('Módulo PDF no disponible');
            }

            // Obtener contenido del editor TinyMCE
            let content = '';
            if (this.state.tinymceEditor) {
                content = this.state.tinymceEditor.getContent();
            } else {
                content = this.state.selectedReport.contenido || '';
            }

            if (!content.trim()) {
                this.showToast('El informe está vacío', 'warning');
                return;
            }

            // Preparar datos del informe para el PDF
            const reportData = this.state.selectedReport;
            const patientName = reportData.nombre_paciente || 'Paciente';
            const studyDate = reportData.fecha_estudio_formatted || 'Fecha no disponible';
            const modality = reportData.modalidad || 'N/A';
            const reportDate = new Date().toLocaleDateString('es-ES');

            // Crear contenido HTML estructurado para el PDF
            const htmlContent = `
                <div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px;">
                    <!-- Encabezado -->
                    <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px;">
                        <h1 style="color: #007bff; margin: 0; font-size: 24px;">INFORME MÉDICO</h1>
                        <p style="margin: 5px 0; color: #666; font-size: 14px;">TJS Medical - Sistema de Gestión de Informes</p>
                    </div>

                    <!-- Contenido del Informe -->
                    <div style="margin-bottom: 25px;">
                        <div style="line-height: 1.6; font-size: 14px;">
                            ${content}
                        </div>
                    </div>

                    <!-- Pie de página -->
                    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #666;">
                        <p>Informe generado el ${reportDate} - TJS Medical</p>
                        <p>ID del Informe: ${reportData.id || 'N/A'}</p>
                    </div>
                </div>
            `;

            // Configurar opciones del PDF
            const pdfOptions = {
                filename: `informe_${patientName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`,
                format: 'a4',
                margin: [20, 20, 20, 20]
            };

            // Generar PDF
            await PDFModule.generateFromHTML(htmlContent, pdfOptions);
            
            this.showToast('PDF exportado exitosamente', 'success');
            
        } catch (error) {
            console.error('Error al exportar PDF:', error);
            this.showToast('Error al exportar PDF: ' + error.message, 'error');
        } finally {
            this.showLoading(false);
        }
    },

    /**
     * Previsualizar informe antes de exportar
     */
    previewPDF: async function() {
        if (!this.state.selectedReport) {
            this.showToast('No hay informe seleccionado para previsualizar', 'error');
            return;
        }

        try {
            // Verificar que PDFModule esté disponible
            if (typeof PDFModule === 'undefined') {
                throw new Error('Módulo PDF no disponible');
            }

            // Obtener contenido del editor TinyMCE
            let content = '';
            if (this.state.tinymceEditor) {
                content = this.state.tinymceEditor.getContent();
            } else {
                content = this.state.selectedReport.contenido || '';
            }

            if (!content.trim()) {
                this.showToast('El informe está vacío', 'warning');
                return;
            }

            // Preparar datos del informe
            const reportData = this.state.selectedReport;
            const patientName = reportData.nombre_paciente || 'Paciente';
            const studyDate = reportData.fecha_estudio_formatted || 'Fecha no disponible';
            const modality = reportData.modalidad || 'N/A';
            const reportDate = new Date().toLocaleDateString('es-ES');

            // Crear contenido HTML para previsualización
            const htmlContent = `
                <div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px;">
                    <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px;">
                        <h1 style="color: #007bff; margin: 0;">INFORME MÉDICO</h1>
                        <p style="margin: 5px 0; color: #666;">TJS Medical - Sistema de Gestión de Informes</p>
                    </div>
                    <div style="margin-bottom: 25px;">
                        <div style="line-height: 1.6;">${content}</div>
                    </div>
                    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #666;">
                        <p>Informe generado el ${reportDate} - TJS Medical</p>
                    </div>
                </div>
            `;

            // Mostrar previsualización
            await PDFModule.previewPDF(htmlContent);
            
        } catch (error) {
            console.error('Error al previsualizar PDF:', error);
            this.showToast('Error al previsualizar PDF: ' + error.message, 'error');
        }
    },

    /**
     * Mostrar historial de versiones
     */
    showVersionHistory: async function(informeId) {
        console.log('📚 Cargando historial de versiones para informe ID:', informeId);
        
        try {
            this.showLoading(true);
            
            const token = this.getSessionToken();
            const url = `${this.config.apiBaseUrl}/history.php?informe_id=${informeId}`;
            console.log('📡 URL del historial:', url);
            
            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('❌ Error HTTP:', response.status, errorText);
                throw new Error(`Error HTTP: ${response.status} - ${errorText}`);
            }
            
            const data = await response.json();
            
            console.log('📥 Respuesta completa del historial:', {
                success: data.success,
                hasData: !!data.data,
                informe_actual: !!data.data?.informe_actual,
                historial_length: data.data?.historial ? data.data.historial.length : 0,
                data: data.data
            });
            
            if (!data.success) {
                console.error('❌ Error en respuesta:', data);
                throw new Error(data.error || 'Error al cargar el historial');
            }
            
            if (!data.data) {
                throw new Error('No se recibieron datos del historial');
            }
            
            // Validar estructura de datos
            if (!data.data.historial && !data.data.informe_actual) {
                console.warn('⚠️ Advertencia: No hay historial ni versión actual en la respuesta');
            }
            
            this.renderVersionHistoryModal(data.data);
            
        } catch (error) {
            console.error('❌ Error cargando historial:', error);
            this.showError('Error al cargar el historial de versiones: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    },

    /**
     * Renderizar modal de historial de versiones
     */
    renderVersionHistoryModal(data) {
        console.log('📋 Datos recibidos para historial:', {
            informe_actual: !!data.informe_actual,
            historial_length: data.historial ? data.historial.length : 0,
            historial: data.historial,
            data_completo: data
        });
        
        // Asegurar que historial sea un array
        const historial = Array.isArray(data.historial) ? data.historial : [];
        
        // Contar versiones totales
        const totalVersiones = (data.informe_actual ? 1 : 0) + historial.length;
        
        console.log(`📊 Total de versiones a mostrar: ${totalVersiones} (actual: ${data.informe_actual ? 1 : 0}, historial: ${historial.length})`);
        
        // Función para obtener color del badge de estado
        const getEstadoBadge = (estado) => {
            const estados = {
                'borrador': 'secondary',
                'pendiente': 'warning',
                'revision': 'info',
                'aprobado': 'success',
                'rechazado': 'danger'
            };
            return estados[estado] || 'secondary';
        };
        
        // Función para generar HTML de una versión
        const renderVersionCard = (version, isActual = false) => {
            const preview = version.contenido_preview || version.contenido_html?.substring(0, 150) || 'Sin contenido';
            const previewText = preview.length > 150 ? preview.substring(0, 150) + '...' : preview;
            
            // Obtener etiquetas de rol
            const rolLabels = {
                'medico_informante': 'Médico Informante',
                'transcriptor': 'Transcriptor',
                'otro': 'Otro'
            };
            
            const medicoInformanteNombre = version.medico_informante_completo || 
                                          (version.medico_informante_nombre && version.medico_informante_apellido ? 
                                           `${version.medico_informante_nombre} ${version.medico_informante_apellido}` : 
                                           version.medico_informante_nombre || 'N/A');
            const medicoInformanteRol = version.medico_informante_rol_actual || version.medico_informante_rol;
            const medicoInformanteRolLabel = medicoInformanteRol ? rolLabels[medicoInformanteRol] || medicoInformanteRol : '';
            
            const transcriptorNombre = version.transcriptor_completo || 
                                      (version.transcriptor_nombre && version.transcriptor_apellido ? 
                                       `${version.transcriptor_nombre} ${version.transcriptor_apellido}` : 
                                       version.transcriptor_nombre || 'N/A');
            const transcriptorRol = version.transcriptor_rol;
            const transcriptorRolLabel = transcriptorRol ? rolLabels[transcriptorRol] || transcriptorRol : '';
            
            return `
                <div class="card mb-2 ${isActual ? 'border-primary' : ''}">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center mb-2">
                                    <strong class="me-2">Versión ${version.version_numero || version.version || 'N/A'}</strong>
                                    ${isActual ? '<span class="badge bg-primary me-2">Actual</span>' : ''}
                                    ${version.estado ? `<span class="badge bg-${getEstadoBadge(version.estado)} me-2">${version.estado}</span>` : ''}
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted">${previewText.replace(/<[^>]*>/g, '')}</small>
                                </div>
                                <div class="d-flex flex-column gap-1">
                                    ${version.fecha_cambio_formatted || version.fecha_modificacion_formatted || version.fecha_modificacion ? 
                                        `<small class="text-muted"><i class="fas fa-calendar me-1"></i>${version.fecha_cambio_formatted || version.fecha_modificacion_formatted || version.fecha_modificacion}</small>` : ''}
                                    <div class="d-flex flex-wrap gap-2">
                                        <div>
                                            <small class="text-muted d-block"><i class="fas fa-user-md me-1"></i><strong>Médico Informante:</strong></small>
                                            <small class="text-dark">${medicoInformanteNombre}</small>
                                            ${medicoInformanteRolLabel ? `<span class="badge bg-info text-white ms-1">${medicoInformanteRolLabel}</span>` : ''}
                                        </div>
                                        ${transcriptorNombre !== 'N/A' && transcriptorNombre !== medicoInformanteNombre ? `
                                            <div>
                                                <small class="text-muted d-block"><i class="fas fa-user-edit me-1"></i><strong>Transcriptor:</strong></small>
                                                <small class="text-dark">${transcriptorNombre}</small>
                                                ${transcriptorRolLabel ? `<span class="badge bg-secondary text-white ms-1">${transcriptorRolLabel}</span>` : ''}
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="ms-3">
                                <button class="btn btn-sm ${isActual ? 'btn-primary' : 'btn-outline-primary'}" 
                                        onclick="InformesManager.viewVersion(${version.id}, ${version.version_numero || version.version}, '${isActual ? 'actual' : 'historial'}')"
                                        title="Ver versión ${version.version_numero || version.version}">
                                    <i class="fas fa-eye me-1"></i>Ver
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        };
        
        const modalHtml = `
            <div class="modal fade" id="versionHistoryModal" tabindex="-1" aria-labelledby="versionHistoryModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="versionHistoryModalLabel">
                                <i class="fas fa-code-branch me-2"></i>
                                Historial de Versiones
                                <span class="badge bg-secondary ms-2">${totalVersiones} versión${totalVersiones !== 1 ? 'es' : ''}</span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            ${totalVersiones === 0 ? 
                                '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No hay versiones disponibles para este informe.</div>' :
                                '<div class="version-list">' +
                                    // Mostrar versión actual primero si existe
                                    (data.informe_actual ? renderVersionCard(data.informe_actual, true) : '') +
                                    // Mostrar todas las versiones del historial
                                    (historial.length > 0 ? historial.map(version => renderVersionCard(version, false)).join('') : '') +
                                '</div>'
                            }
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal existente si existe
        const existingModal = document.getElementById('versionHistoryModal');
        if (existingModal) {
            const existingInstance = bootstrap.Modal.getInstance(existingModal);
            if (existingInstance) {
                existingInstance.dispose();
            }
            existingModal.remove();
        }
        
        // Limpiar backdrops residuales
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => {
            if (backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
        });
        
        // Agregar nuevo modal
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Mostrar modal sin backdrop
        const modalElement = document.getElementById('versionHistoryModal');
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: false, // Sin backdrop para evitar bloqueos
            keyboard: true   // Permitir cerrar con ESC
        });
        modal.show();
        
        // Agregar clase modal-open al body para mantener el scroll
        document.body.classList.add('modal-open');
        
        // Limpiar modal al cerrar
        modalElement.addEventListener('hidden.bs.modal', function() {
            try {
                const instance = bootstrap.Modal.getInstance(this);
                if (instance) {
                    instance.dispose();
                }
            } catch (e) {
                console.warn('Error limpiando instancia del modal:', e);
            }
            
            // Limpiar backdrops
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            
            // Remover clase modal-open si no hay otros modales
            if (document.querySelectorAll('.modal.show').length === 0) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
            
            this.remove();
        }, { once: true });
    },

    /**
     * Ver una versión específica del informe
     */
    viewVersion: async function(informeId, versionNumber, tipoVersion) {
        console.log('🔍 viewVersion llamado con:', { informeId, versionNumber, tipoVersion });
        
        try {
            this.showLoading(true);
            
            const token = this.getSessionToken();
            let url;
            
            if (tipoVersion === 'actual') {
                // Para la versión actual, usar el endpoint normal
                url = `${this.config.apiBaseUrl}/get.php?informe_id=${informeId}`;
                console.log('📡 Cargando versión actual desde:', url);
            } else {
                // Para versiones del historial, usar endpoint específico con la ruta base correcta
                // Intentar ambas rutas posibles
                url = `${this.config.apiBaseUrl}/get-version.php?informe_id=${informeId}&version=${versionNumber}`;
                console.log('📡 Cargando versión histórica desde:', url);
            }
            
            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('❌ Error HTTP:', response.status, errorText);
                
                // Si es 404 y es una versión histórica, intentar con ruta alternativa
                if (response.status === 404 && tipoVersion !== 'actual') {
                    const altUrl = `../api/informes/get-version.php?informe_id=${informeId}&version=${versionNumber}`;
                    console.log('⚠️ Intentando ruta alternativa:', altUrl);
                    const altResponse = await fetch(altUrl, {
                        headers: {
                            'Authorization': `Bearer ${token}`
                        }
                    });
                    
                    if (altResponse.ok) {
                        const altData = await altResponse.json();
                        if (altData.success) {
                            console.log('✅ Versión cargada desde ruta alternativa');
                            this.renderVersionViewerModal(altData.data, versionNumber, tipoVersion);
                            return;
                        }
                    }
                }
                
                throw new Error(`Error HTTP: ${response.status} - ${errorText}`);
            }
            
            const data = await response.json();
            
            console.log('✅ Datos recibidos:', {
                success: data.success,
                hasData: !!data.data,
                versionNumber: versionNumber
            });
            
            if (!data.success) {
                console.error('❌ Error en respuesta:', data);
                throw new Error(data.error || 'Error al cargar la versión');
            }
            
            if (!data.data) {
                throw new Error('No se recibieron datos de la versión');
            }
            
            this.renderVersionViewerModal(data.data, versionNumber, tipoVersion);
            
        } catch (error) {
            console.error('❌ Error cargando versión:', error);
            this.showError('Error al cargar la versión: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    },

    /**
     * Renderizar modal para ver una versión específica
     */
    renderVersionViewerModal(informeData, versionNumber, tipoVersion) {
        const modalHtml = `
            <style>
                #versionViewerModal .version-content-wrapper {
                    max-height: 70vh;
                    overflow-y: auto;
                    overflow-x: hidden;
                    padding: 15px;
                    background-color: #fff;
                    border: 1px solid #dee2e6;
                    border-radius: 0.375rem;
                }
                
                #versionViewerModal .version-content {
                    font-size: 14px !important;
                    line-height: 1.6 !important;
                    color: #212529 !important;
                }
                
                #versionViewerModal .version-content * {
                    font-size: inherit !important;
                }
                
                #versionViewerModal .version-content h1 { font-size: 1.75rem !important; }
                #versionViewerModal .version-content h2 { font-size: 1.5rem !important; }
                #versionViewerModal .version-content h3 { font-size: 1.25rem !important; }
                #versionViewerModal .version-content h4 { font-size: 1.1rem !important; }
                #versionViewerModal .version-content h5 { font-size: 1rem !important; }
                #versionViewerModal .version-content h6 { font-size: 0.9rem !important; }
                #versionViewerModal .version-content p { font-size: 14px !important; margin-bottom: 1rem !important; }
                #versionViewerModal .version-content ul, 
                #versionViewerModal .version-content ol { font-size: 14px !important; }
                #versionViewerModal .version-content table { font-size: 14px !important; width: 100% !important; }
                #versionViewerModal .version-content img { max-width: 100% !important; height: auto !important; }
                
                /* Personalizar scrollbar */
                #versionViewerModal .version-content-wrapper::-webkit-scrollbar {
                    width: 8px;
                }
                
                #versionViewerModal .version-content-wrapper::-webkit-scrollbar-track {
                    background: #f1f1f1;
                    border-radius: 4px;
                }
                
                #versionViewerModal .version-content-wrapper::-webkit-scrollbar-thumb {
                    background: #888;
                    border-radius: 4px;
                }
                
                #versionViewerModal .version-content-wrapper::-webkit-scrollbar-thumb:hover {
                    background: #555;
                }
            </style>
            <div class="modal fade" id="versionViewerModal" tabindex="-1" aria-labelledby="versionViewerModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="versionViewerModalLabel">
                                <i class="fas fa-file-alt me-2"></i>
                                Versión ${versionNumber} - ${tipoVersion === 'actual' ? 'Actual' : 'Historial'}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">Contenido del Informe</h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="version-content-wrapper">
                                                <div class="version-content">
                                                    ${informeData.contenido_html || '<p class="text-muted">Sin contenido</p>'}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">Información de la Versión</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <strong>Versión:</strong> ${versionNumber}
                                            </div>
                                            <div class="mb-3">
                                                <strong>Estado:</strong> 
                                                <span class="badge bg-${this.getEstadoBadge(informeData.estado)}">${informeData.estado}</span>
                                            </div>
                                            <div class="mb-3">
                                                <strong>Fecha:</strong> ${informeData.fecha_modificacion || informeData.fecha_cambio}
                                            </div>
                                            <div class="mb-3">
                                                <strong>Paciente:</strong> ${informeData.patient_name || 'N/A'}
                                            </div>
                                            <div class="mb-3">
                                                <strong>Modalidad:</strong> ${informeData.modality || 'N/A'}
                                            </div>
                                            ${tipoVersion === 'historial' ? `
                                                <div class="mb-3">
                                                    <strong>Motivo:</strong> ${informeData.motivo_cambio || 'N/A'}
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    
                                    <div class="card mt-3">
                                        <div class="card-header">
                                            <h6 class="mb-0">Acciones</h6>
                                        </div>
                                        <div class="card-body">
                                            <button class="btn btn-primary w-100 mb-2" onclick="InformesManager.exportVersionToPDF(${informeData.id}, ${versionNumber}, '${tipoVersion}')">
                                                <i class="fas fa-file-pdf me-1"></i>Exportar PDF
                                            </button>
                                            <button class="btn btn-outline-secondary w-100" onclick="InformesManager.printVersion(${informeData.id}, ${versionNumber})">
                                                <i class="fas fa-print me-1"></i>Imprimir
                                            </button>
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
        const existingModal = document.getElementById('versionViewerModal');
        if (existingModal) {
            const existingInstance = bootstrap.Modal.getInstance(existingModal);
            if (existingInstance) {
                existingInstance.dispose();
            }
            existingModal.remove();
        }
        
        // Limpiar backdrops residuales
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => {
            if (backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
        });
        
        // Agregar nuevo modal
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Mostrar modal
        const modalElement = document.getElementById('versionViewerModal');
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
        
        // Limpiar modal al cerrar
        modalElement.addEventListener('hidden.bs.modal', function() {
            try {
                const instance = bootstrap.Modal.getInstance(this);
                if (instance) {
                    instance.dispose();
                }
            } catch (e) {
                console.warn('Error limpiando instancia del modal:', e);
            }
            
            // Limpiar backdrops
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            
            // Remover clase modal-open si no hay otros modales
            if (document.querySelectorAll('.modal.show').length === 0) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
            
            this.remove();
        }, { once: true });
    },

    /**
     * Exportar versión específica a PDF
     */
    exportVersionToPDF: function(informeId, versionNumber, tipoVersion) {
        // Implementar exportación de versión específica
        this.showToast(`Exportando versión ${versionNumber} a PDF...`, 'info');
        // TODO: Implementar exportación específica de versión
    },

    /**
     * Imprimir versión específica
     */
    printVersion: function(informeId, versionNumber) {
        const printWindow = window.open('', '_blank');
        const versionContent = document.querySelector('#versionViewerModal .version-content').innerHTML;
        
        printWindow.document.write(`
            <html>
                <head>
                    <title>Versión ${versionNumber} - Informe</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .version-header { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
                    </style>
                </head>
                <body>
                    <div class="version-header">
                        <h2>Versión ${versionNumber} del Informe</h2>
                    </div>
                    ${versionContent}
                </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.print();
    },

    /**
     * Obtener clase CSS del badge según el estado
     */
    getEstadoBadge(estado) {
        const badges = {
            'borrador': 'secondary',
            'revision': 'warning',
            'revisado': 'warning',
            'finalizado': 'success',
            'firmado': 'primary'
        };
        return badges[estado] || 'secondary';
    },

    /**
     * Crear nuevo informe
     */
    createNewReport: async function() {
        try {
            this.showLoading(true);
            
            // Verificar si hay un estudio activo en URLESTUDIO
            if (window.urlStudyManager && window.urlStudyManager.hasActiveStudy()) {
                const activeStudy = window.urlStudyManager.getActiveStudy();
                
                // Ocultar loading antes de mostrar modal
                this.showLoading(false);
                
                // Limpiar TODOS los backdrops antes de mostrar el modal
                const allBackdrops = document.querySelectorAll('.modal-backdrop');
                allBackdrops.forEach(backdrop => backdrop.remove());
                
                // Mostrar modal de confirmación para continuar con estudio activo
                this.showActiveStudyModal(activeStudy);
            } else {
                // No hay estudio activo, mostrar modal para seleccionar estudio
                await this.showStudySelectionModal();
            }
            
        } catch (error) {
            console.error('Error creando nuevo informe:', error);
            this.showError('Error al crear nuevo informe: ' + error.message);
            this.showLoading(false);
        }
    },

    /**
     * Mostrar modal para estudio activo
     */
    showActiveStudyModal: function(activeStudy) {
        const modalHtml = `
            <div class="modal fade" id="activeStudyModal" tabindex="-1" aria-labelledby="activeStudyModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="activeStudyModalLabel">
                                <i class="fas fa-file-medical me-2"></i>
                                Estudio Activo Encontrado
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Ya tienes un estudio activo en curso.
                            </div>
                            
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Información del Estudio Activo:</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Paciente:</strong> ${activeStudy.patientName}<br>
                                            <strong>ID Paciente:</strong> ${activeStudy.patientId}<br>
                                            <strong>Modalidad:</strong> ${activeStudy.modality}
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Estudio:</strong> ${activeStudy.studyDescription}<br>
                                            <strong>ID Estudio:</strong> ${activeStudy.studyId}<br>
                                            <strong>Iniciado:</strong> ${new Date(activeStudy.startedAt).toLocaleString()}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <strong>¿Qué deseas hacer?</strong>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-warning" onclick="InformesManager.clearActiveStudyAndCreateNew()">
                                <i class="fas fa-trash me-1"></i>Descartar y Crear Nuevo
                            </button>
                            <button type="button" class="btn btn-primary" onclick="InformesManager.continueWithActiveStudy()">
                                <i class="fas fa-play me-1"></i>Continuar con Este Estudio
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal existente si existe
        const existingModal = document.getElementById('activeStudyModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Agregar nuevo modal
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Obtener elemento del modal
        const modalElement = document.getElementById('activeStudyModal');
        
        // Limpiar TODOS los backdrops antes de mostrar el modal
        const allBackdrops = document.querySelectorAll('.modal-backdrop');
        allBackdrops.forEach(backdrop => backdrop.remove());
        
        // Mostrar modal
        const modal = new bootstrap.Modal(modalElement);
        
        // Asegurar z-index correcto después de que el modal se muestre
        modalElement.addEventListener('shown.bs.modal', () => {
            this.ensureModalZIndex(modalElement, 1055);
        }, { once: true });
        
        modal.show();
        
        // También aplicar fix inmediatamente
        this.ensureModalZIndex(modalElement, 1055);
        
        // Limpiar modal al cerrar
        modalElement.addEventListener('hidden.bs.modal', function() {
            this.remove();
        });
    },

    /**
     * Continuar con estudio activo
     */
    continueWithActiveStudy: function() {
        if (window.urlStudyManager && window.urlStudyManager.hasActiveStudy()) {
            const activeStudy = window.urlStudyManager.getActiveStudy();
            
            // Crear un informe temporal para el modal de edición
            const tempReport = {
                id: null, // Nuevo informe
                estudio_id: activeStudy.studyInstanceUID,
                nombre_paciente: activeStudy.patientName,
                paciente_id: activeStudy.patientId,
                modalidad: activeStudy.modality,
                descripcion_estudio: activeStudy.studyDescription,
                contenido_html: '',
                estado: 'borrador',
                version: 1,
                fecha_creacion: new Date().toISOString(),
                fecha_modificacion: new Date().toISOString()
            };
            
            // Cerrar modal de estudio activo
            const modal = bootstrap.Modal.getInstance(document.getElementById('activeStudyModal'));
            if (modal) {
                modal.hide();
            }
            
            // Abrir modal de edición con el informe temporal
            this.openReportModal(null, false, tempReport);
            
            console.log('Modal de edición abierto para estudio activo');
        }
    },

    /**
     * Descartar estudio activo y crear nuevo
     */
    clearActiveStudyAndCreateNew: async function() {
        try {
            // Limpiar estudio activo
            if (window.urlStudyManager) {
                window.urlStudyManager.clearActiveStudy();
            }
            
            // Cerrar modal actual
            const modal = bootstrap.Modal.getInstance(document.getElementById('activeStudyModal'));
            if (modal) {
                modal.hide();
            }
            
            // Mostrar modal de selección de estudios
            await this.showStudySelectionModal();
            
        } catch (error) {
            console.error('Error descartando estudio activo:', error);
            this.showError('Error al descartar estudio activo: ' + error.message);
        }
    },

    /**
     * Mostrar modal de selección de estudios
     */
    showStudySelectionModal: async function() {
        try {
            this.showLoading(true);
            
            // Mostrar modal vacío primero, sin cargar estudios
            console.log('Mostrando modal de selección de estudios (vacío inicialmente)');
            
            // Ocultar loading antes de mostrar el modal
            this.showLoading(false);
            
            // Limpiar backdrops duplicados que puedan existir
            const existingBackdrops = document.querySelectorAll('.modal-backdrop');
            existingBackdrops.forEach(backdrop => backdrop.remove());
            
            const modalHtml = `
                <div class="modal fade" id="studySelectionModal" tabindex="-1" aria-labelledby="studySelectionModalLabel" aria-hidden="true" data-bs-backdrop="static" style="z-index: 1055;">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="studySelectionModalLabel">
                                    <i class="fas fa-list me-2"></i>
                                    Seleccionar Estudio para Nuevo Informe
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Instrucciones:</strong> Selecciona las fechas y otros filtros para buscar estudios disponibles.
                                </div>
                                
                                <!-- Search Filters -->
                                <div class="search-filters mb-4">
                                    <h6 class="filter-title mb-3"><i class="fas fa-search me-2"></i>Filtros de Búsqueda</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                <input type="text" class="form-control" id="studySearchFilter" placeholder="Buscar en estudios..." title="Buscar por paciente, ID, modalidad o estudio">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                                <input type="date" class="form-control" id="studyDateFrom" placeholder="Desde">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                                <input type="date" class="form-control" id="studyDateTo" placeholder="Hasta">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                                <input type="text" class="form-control" id="studyPatientId" placeholder="ID del Paciente">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <button class="btn btn-primary w-100" id="studySearchButton">
                                                <i class="fas fa-search me-2"></i>Buscar Estudios
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modality Filters -->
                                <div class="modality-filters mb-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="filter-label mb-0">Filtrar por Modalidad: <span id="studyModalityCounter" class="badge bg-secondary ms-2" style="display: none;"></span></h6>
                                        <button class="btn btn-sm btn-outline-secondary" id="clearStudyFilters">
                                            <i class="fas fa-times me-1"></i>Limpiar Filtros
                                        </button>
                                        <button class="btn btn-sm btn-outline-warning" id="clearStudiesCache" title="Limpiar caché de estudios">
                                            <i class="fas fa-trash me-1"></i>Limpiar Caché
                                        </button>
                                    </div>
                                    <div class="modality-buttons mt-2">
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="CT">CT</button>
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="MR">MR</button>
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="DX">DX</button>
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="US">US</button>
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="NM">NM</button>
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="PT">PT</button>
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="CR">CR</button>
                                        <button class="btn btn-outline-primary btn-sm modality-btn" data-modality="MG">MG</button>
                                    </div>
                                </div>
                                
                                <!-- Studies Table -->
                                <div class="studies-section">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="section-title mb-0">
                                            <i class="fas fa-list me-2"></i>
                                            Lista de Estudios
                                        </h6>
                                        <span class="badge bg-primary" id="studyCount">0 estudios</span>
                                    </div>
                                    
                                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                        <table class="table table-hover">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th>Paciente</th>
                                                <th class="d-none d-md-table-cell">ID</th>
                                                <th>Modalidad</th>
                                                <th class="d-none d-xl-table-cell">Estudio</th>
                                                <th class="d-none d-lg-table-cell">Fecha</th>
                                                <th>Estado Informe</th>
                                                <th>Audios</th>
                                                <th style="min-width: 200px;">Acción</th>
                                            </tr>
                                        </thead>
                                            <tbody id="studiesTableBody">
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted py-4">
                                                        <i class="fas fa-search fa-2x mb-2"></i>
                                                        <div>Selecciona filtros y haz clic en "Buscar Estudios" para ver los resultados</div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('studySelectionModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Agregar nuevo modal
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Inicializar datos y configurar filtros
            this.setupStudySearch();
            
            // Inicializar botones de modalidad en estado inactivo
            this.initializeModalityButtons();
            
            // Intentar restaurar estudios desde caché
            const hasCachedStudies = this.restoreStudiesState();
            
            if (hasCachedStudies) {
                // Si hay estudios en caché, mostrarlos inmediatamente
                this.renderFilteredStudies();
                this.updateStudyCount();
                this.updateAvailableModalities();
                
                // Restaurar filtros en el modal
                this.restoreFiltersInModal();
                
                console.log('Modal abierto con estudios del caché:', this.allStudies.length);
            } else {
                // Si no hay estudios en caché, inicializar vacío
                this.allStudies = [];
                this.filteredStudies = [];
                console.log('Modal abierto sin estudios en caché');
            }
            
            // Mostrar modal
            const modalElement = document.getElementById('studySelectionModal');
            
            // Limpiar TODOS los backdrops antes de mostrar el modal
            const allBackdrops = document.querySelectorAll('.modal-backdrop');
            allBackdrops.forEach(backdrop => backdrop.remove());
            
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true
            });
            
            // Asegurar z-index correcto después de que el modal se muestre
            modalElement.addEventListener('shown.bs.modal', () => {
                this.ensureModalZIndex(modalElement, 1055);
            }, { once: true });
            
            modal.show();
            
            // También aplicar fix inmediatamente después de show()
            this.ensureModalZIndex(modalElement, 1055);
            
            // Limpiar modal al cerrar
            modalElement.addEventListener('hidden.bs.modal', function() {
                this.remove();
            });
            
        } catch (error) {
            console.error('Error mostrando modal de selección:', error);
            this.showError('Error al cargar estudios: ' + error.message);
            this.showLoading(false);
        }
    },

    /**
     * Cargar estudios desde Orthanc basándose en los filtros seleccionados
     */
    loadStudiesFromOrthanc: async function() {
        try {
            console.log('=== Cargando estudios desde Orthanc con filtros ===');
            
            // Obtener filtros del modal
            const dateFrom = document.getElementById('studyDateFrom')?.value || '';
            const dateTo = document.getElementById('studyDateTo')?.value || '';
            const patientId = document.getElementById('studyPatientId')?.value || '';
            
            // Validar que al menos se seleccione un rango de fechas
            if (!dateFrom && !dateTo) {
                this.showError('Por favor selecciona al menos una fecha (desde o hasta) para buscar estudios');
                return;
            }
            
            // Mostrar loading
            const searchButton = document.getElementById('studySearchButton');
            if (searchButton) {
                searchButton.disabled = true;
                searchButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Buscando...';
            }
            
            // Construir parámetros
            const params = new URLSearchParams();
            if (dateFrom) params.append('dateFrom', dateFrom);
            if (dateTo) params.append('dateTo', dateTo);
            if (patientId) params.append('patientId', patientId);
            
            const url = '../api/get_all_studies.php?' + params.toString();
            
            console.log('Cargando estudios desde:', url);
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            console.log('Respuesta del servidor:', result);
            
            if (!result.success) {
                throw new Error(result.error || 'Error al cargar estudios');
            }
            
            // Si no hay estudios, mostrar mensaje
            if (!result.data || result.data.length === 0) {
                console.log('No hay estudios disponibles con los filtros seleccionados');
                this.showError('No se encontraron estudios con los filtros seleccionados. Intenta con un rango de fechas diferente.');
                return;
            }
            
            // Cargar estudios en el modal
            this.allStudies = result.data;
            this.filteredStudies = [...result.data];
            this.isStudiesDataLoaded = true;
            
            // Guardar estudios en caché
            this.saveStudiesToCache();
            
            this.renderFilteredStudies();
            this.updateStudyCount();
            this.updateAvailableModalities();
            
            console.log(`Estudios cargados desde Orthanc: ${result.data.length}`);
            
        } catch (error) {
            console.error('Error cargando estudios desde Orthanc:', error);
            this.showError('Error al cargar estudios: ' + error.message);
        } finally {
            // Restaurar botón
            const searchButton = document.getElementById('studySearchButton');
            if (searchButton) {
                searchButton.disabled = false;
                searchButton.innerHTML = '<i class="fas fa-search me-2"></i>Buscar Estudios';
            }
        }
    },

    /**
     * Cargar estudios disponibles desde Orthanc (independiente de dashboard-unified)
     */
    loadAvailableStudies: async function() {
        try {
            console.log('=== INICIO loadAvailableStudies (INDEPENDIENTE) ===');
            
            // Siempre usar consulta directa al API, independiente de dashboardOrthanc
            console.log('Cargando estudios directamente desde API (independiente de dashboard-unified)');
            
            // Construir parámetros de fecha para obtener estudios recientes
            const today = new Date();
            const sixMonthsAgo = new Date(today.getFullYear(), today.getMonth() - 6, 1);
            
            const params = new URLSearchParams({
                dateFrom: sixMonthsAgo.toISOString().split('T')[0], // Últimos 6 meses
                dateTo: today.toISOString().split('T')[0]
            });
            
            const url = '../api/get_all_studies.php?' + params.toString();
            
            console.log('Cargando estudios desde:', url);
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            console.log('Respuesta del servidor:', result);
            
            if (!result.success) {
                throw new Error(result.error || 'Error al cargar estudios');
            }
            
            // Si no hay estudios, mostrar estudios de ejemplo
            if (!result.data || result.data.length === 0) {
                console.log('No hay estudios disponibles en Orthanc, mostrando estudios de ejemplo');
                return this.getExampleStudies();
            }
            
            console.log(`Estudios cargados desde Orthanc (INDEPENDIENTE): ${result.data.length}`);
            console.log('Primer estudio real:', result.data[0]);
            return result.data || [];
            
        } catch (error) {
            console.error('Error cargando estudios desde Orthanc:', error);
            console.log('Usando estudios de ejemplo debido al error');
            return this.getExampleStudies();
        }
    },

    /**
     * Obtener estudios de ejemplo cuando no hay datos reales
     */
    getExampleStudies: function() {
        return [
            {
                id: 'example-1',
                study_id: 'example-1',
                patient_name: 'Juan Pérez',
                patient_id: '12345678',
                modality: 'CT',
                study_description: 'Tomografía de Tórax',
                study_instance_uid: 'example-uid-1',
                date: '2025-10-16',
                time: '10:30:00',
                status: 'Completado'
            },
            {
                id: 'example-2',
                study_id: 'example-2',
                patient_name: 'María González',
                patient_id: '87654321',
                modality: 'MR',
                study_description: 'Resonancia Magnética de Cráneo',
                study_instance_uid: 'example-uid-2',
                date: '2025-10-15',
                time: '14:15:00',
                status: 'Completado'
            },
            {
                id: 'example-3',
                study_id: 'example-3',
                patient_name: 'Carlos López',
                patient_id: '11223344',
                modality: 'DX',
                study_description: 'Radiografía de Abdomen',
                study_instance_uid: 'example-uid-3',
                date: '2025-10-14',
                time: '09:45:00',
                status: 'Completado'
            },
            {
                id: 'example-4',
                study_id: 'example-4',
                patient_name: 'Ana Martínez',
                patient_id: '55667788',
                modality: 'US',
                study_description: 'Ecografía Abdominal',
                study_instance_uid: 'example-uid-4',
                date: '2025-10-13',
                time: '16:20:00',
                status: 'Completado'
            },
            {
                id: 'example-5',
                study_id: 'example-5',
                patient_name: 'Roberto Silva',
                patient_id: '99887766',
                modality: 'CT',
                study_description: 'Tomografía de Abdomen',
                study_instance_uid: 'example-uid-5',
                date: '2025-10-12',
                time: '11:10:00',
                status: 'Completado'
            }
        ];
    },

    /**
     * Configurar búsqueda y filtros de estudios
     */
    setupStudySearch: function() {
        // Variables para almacenar datos y filtros
        this.allStudies = [];
        this.filteredStudies = [];
        this.currentStudyFilters = {
            search: '',
            dateFrom: '',
            dateTo: '',
            patientId: '',
            modalities: []
        };
        
        // Persistencia de estudios
        this.studiesStorageKey = 'informes_manager_studies';
        this.isStudiesDataLoaded = false;
        
        // Configurar event listeners
        this.setupStudyEventListeners();
    },

    /**
     * Configurar event listeners para filtros de estudios
     */
    setupStudyEventListeners: function() {
        // Filtro de búsqueda general
        const searchFilter = document.getElementById('studySearchFilter');
        if (searchFilter) {
            searchFilter.addEventListener('input', (e) => {
                this.currentStudyFilters.search = e.target.value;
                this.applyStudyFilters();
            });
        }
        
        // Filtros de fecha
        const dateFrom = document.getElementById('studyDateFrom');
        if (dateFrom) {
            dateFrom.addEventListener('change', (e) => {
                this.currentStudyFilters.dateFrom = e.target.value;
                this.applyStudyFilters();
            });
        }
        
        const dateTo = document.getElementById('studyDateTo');
        if (dateTo) {
            dateTo.addEventListener('change', (e) => {
                this.currentStudyFilters.dateTo = e.target.value;
                this.applyStudyFilters();
            });
        }
        
        // Filtro de ID de paciente
        const patientId = document.getElementById('studyPatientId');
        if (patientId) {
            patientId.addEventListener('input', (e) => {
                this.currentStudyFilters.patientId = e.target.value;
                this.applyStudyFilters();
            });
        }
        
        // Botón de búsqueda
        const searchButton = document.getElementById('studySearchButton');
        if (searchButton) {
            searchButton.addEventListener('click', async () => {
                await this.loadStudiesFromOrthanc();
            });
        }
        
        // Botones de modalidad
        const modalityButtons = document.querySelectorAll('.modality-btn');
        console.log('Botones de modalidad encontrados:', modalityButtons.length);
        console.log('Botones encontrados:', modalityButtons);
        
        modalityButtons.forEach((btn, index) => {
            const modality = btn.getAttribute('data-modality');
            console.log(`Configurando event listener ${index + 1} para modalidad:`, modality, 'Botón:', btn);
            
            // Verificar estado inicial del botón
            console.log('Estado inicial del botón:', btn.className);
            
            btn.addEventListener('click', (e) => {
                console.log('=== EVENTO CLICK DETECTADO ===');
                console.log('Evento:', e);
                console.log('Target:', e.target);
                console.log('CurrentTarget:', e.currentTarget);
                const modality = e.target.getAttribute('data-modality');
                console.log('Modalidad del click:', modality);
                console.log('Antes de toggleModalityFilter - Clases:', e.target.className);
                this.toggleModalityFilter(modality);
                console.log('Después de toggleModalityFilter - Clases:', e.target.className);
                console.log('=== FIN EVENTO CLICK ===');
            });
        });
        
        // Botón limpiar filtros
        const clearFilters = document.getElementById('clearStudyFilters');
        if (clearFilters) {
            clearFilters.addEventListener('click', () => {
                this.clearStudyFilters();
            });
        }
        
        // Botón limpiar caché
        const clearCache = document.getElementById('clearStudiesCache');
        if (clearCache) {
            clearCache.addEventListener('click', () => {
                this.clearStudiesCache();
                this.allStudies = [];
                this.filteredStudies = [];
                this.isStudiesDataLoaded = false;
                this.renderFilteredStudies();
                this.updateStudyCount();
                this.showToast('Caché de estudios limpiado', 'success');
            });
        }
    },

    /**
     * Inicializar botones de modalidad en estado inactivo
     */
    initializeModalityButtons: function() {
        console.log('=== INICIO initializeModalityButtons ===');
        const modalityButtons = document.querySelectorAll('.modality-btn');
        console.log('Botones encontrados para inicializar:', modalityButtons.length);
        
        modalityButtons.forEach((btn, index) => {
            const modality = btn.getAttribute('data-modality');
            console.log(`Inicializando botón ${index + 1} (${modality}):`, btn);
            console.log('Clases ANTES de inicializar:', btn.className);
            
            // Asegurar que todos los botones empiecen en estado inactivo
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-outline-primary');
            
            // Aplicar estilos inline para estado inactivo
            btn.style.backgroundColor = 'transparent';
            btn.style.borderColor = '#0d6efd';
            btn.style.color = '#0d6efd';
            
            console.log('Clases DESPUÉS de inicializar:', btn.className);
        });
        console.log('Botones de modalidad inicializados en estado inactivo');
        console.log('=== FIN initializeModalityButtons ===');
    },

    /**
     * Alternar filtro de modalidad
     */
    toggleModalityFilter: function(modality) {
        console.log('=== INICIO toggleModalityFilter ===');
        console.log('Modalidad recibida:', modality);
        
        const btn = document.querySelector(`[data-modality="${modality}"]`);
        console.log('Botón encontrado:', btn);
        console.log('HTML del botón:', btn ? btn.outerHTML : 'NO ENCONTRADO');
        
        if (!btn) {
            console.error('No se encontró el botón para la modalidad:', modality);
            return;
        }
        
        // Verificar si el botón está actualmente activo (btn-primary) o inactivo (btn-outline-primary)
        const isCurrentlyActive = btn.classList.contains('btn-primary');
        const hasOutlinePrimary = btn.classList.contains('btn-outline-primary');
        console.log('Botón actualmente activo (btn-primary):', isCurrentlyActive);
        console.log('Botón tiene btn-outline-primary:', hasOutlinePrimary);
        console.log('Todas las clases del botón:', btn.className);
        console.log('Lista de clases:', Array.from(btn.classList));
        
        if (isCurrentlyActive) {
            // Desactivar modalidad
            console.log('DESACTIVANDO modalidad:', modality);
            const index = this.currentStudyFilters.modalities.indexOf(modality);
            if (index > -1) {
                this.currentStudyFilters.modalities.splice(index, 1);
                console.log('Modalidad removida del array en índice:', index);
            }
            
            console.log('Removiendo btn-primary...');
            btn.classList.remove('btn-primary');
            console.log('Agregando btn-outline-primary...');
            btn.classList.add('btn-outline-primary');
            
            // Aplicar estilos inline para estado inactivo
            btn.style.backgroundColor = 'transparent';
            btn.style.borderColor = '#0d6efd';
            btn.style.color = '#0d6efd';
            
            console.log('Modalidad desactivada:', modality);
        } else {
            // Activar modalidad
            console.log('ACTIVANDO modalidad:', modality);
            if (!this.currentStudyFilters.modalities.includes(modality)) {
                this.currentStudyFilters.modalities.push(modality);
                console.log('Modalidad agregada al array');
            }
            
            console.log('Removiendo btn-outline-primary...');
            btn.classList.remove('btn-outline-primary');
            console.log('Agregando btn-primary...');
            btn.classList.add('btn-primary');
            
            // Aplicar estilos inline para estado activo
            btn.style.backgroundColor = '#0d6efd';
            btn.style.borderColor = '#0d6efd';
            btn.style.color = 'white';
            
            console.log('Modalidad activada:', modality);
        }
        
        console.log('Filtros de modalidad actuales:', this.currentStudyFilters.modalities);
        console.log('Clases del botón después del cambio:', btn.className);
        console.log('Lista de clases después del cambio:', Array.from(btn.classList));
        
        // Verificar estado visual final
        setTimeout(() => {
            console.log('Estado visual final del botón:', btn.className);
            console.log('¿Tiene btn-primary?', btn.classList.contains('btn-primary'));
            console.log('¿Tiene btn-outline-primary?', btn.classList.contains('btn-outline-primary'));
        }, 10);
        
        this.updateModalityCounter();
        this.applyStudyFilters();
        console.log('=== FIN toggleModalityFilter ===');
    },

    /**
     * Actualizar contador de modalidades
     */
    updateModalityCounter: function() {
        const counter = document.getElementById('studyModalityCounter');
        if (counter) {
            if (this.currentStudyFilters.modalities.length > 0) {
                counter.textContent = this.currentStudyFilters.modalities.length;
                counter.style.display = 'inline';
            } else {
                counter.style.display = 'none';
            }
        }
    },

    /**
     * Limpiar todos los filtros
     */
    clearStudyFilters: function() {
        // Limpiar campos de entrada
        const searchFilter = document.getElementById('studySearchFilter');
        const dateFrom = document.getElementById('studyDateFrom');
        const dateTo = document.getElementById('studyDateTo');
        const patientId = document.getElementById('studyPatientId');
        
        if (searchFilter) searchFilter.value = '';
        if (dateFrom) dateFrom.value = '';
        if (dateTo) dateTo.value = '';
        if (patientId) patientId.value = '';
        
        // Limpiar filtros de modalidad
        const modalityButtons = document.querySelectorAll('.modality-btn');
        modalityButtons.forEach(btn => {
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-outline-primary');
            
            // Aplicar estilos inline para estado inactivo
            btn.style.backgroundColor = 'transparent';
            btn.style.borderColor = '#0d6efd';
            btn.style.color = '#0d6efd';
        });
        
        // Resetear filtros
        this.currentStudyFilters = {
            search: '',
            dateFrom: '',
            dateTo: '',
            patientId: '',
            modalities: []
        };
        
        this.updateModalityCounter();
        this.applyStudyFilters();
    },

    /**
     * Aplicar filtros a los estudios
     */
    applyStudyFilters: function() {
        if (!this.allStudies || this.allStudies.length === 0) {
            return;
        }
        
        this.filteredStudies = this.allStudies.filter(study => {
            // Filtro de búsqueda general
            if (this.currentStudyFilters.search) {
                const searchTerm = this.currentStudyFilters.search.toLowerCase();
                const searchText = [
                    study.patient_name || study.patientName || '',
                    study.patient_id || study.patientId || '',
                    study.modality || '',
                    study.study_description || study.studyDescription || '',
                    study.accession_number || '',
                    study.referring_physician || ''
                ].join(' ').toLowerCase();
                
                if (!searchText.includes(searchTerm)) {
                    return false;
                }
            }
            
            // Filtro de fecha desde
            if (this.currentStudyFilters.dateFrom) {
                const studyDate = study.date || study.studyDate || '';
                const filterDate = this.currentStudyFilters.dateFrom.replace(/-/g, '');
                if (studyDate < filterDate) {
                    return false;
                }
            }
            
            // Filtro de fecha hasta
            if (this.currentStudyFilters.dateTo) {
                const studyDate = study.date || study.studyDate || '';
                const filterDate = this.currentStudyFilters.dateTo.replace(/-/g, '');
                if (studyDate > filterDate) {
                    return false;
                }
            }
            
            // Filtro de ID de paciente
            if (this.currentStudyFilters.patientId && this.currentStudyFilters.patientId.trim() !== '') {
                const patientId = (study.patient_id || study.patientId || '').toLowerCase();
                const filterPatientId = this.currentStudyFilters.patientId.toLowerCase();
                if (!patientId.includes(filterPatientId)) {
                    return false;
                }
            }
            
            // Filtro de modalidades
            if (this.currentStudyFilters.modalities.length > 0) {
                const studyModality = study.modality || '';
                if (!this.currentStudyFilters.modalities.includes(studyModality)) {
                    return false;
                }
            }
            
            return true;
        });
        
        this.renderFilteredStudies();
        this.updateStudyCount();
        
        // Guardar filtros en caché si hay estudios cargados
        if (this.allStudies.length > 0) {
            this.saveStudiesToCache();
        }
    },

    /**
     * Renderizar estudios filtrados
     */
    renderFilteredStudies: function() {
        const tbody = document.getElementById('studiesTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = this.filteredStudies.map(study => {
            const hasReport = study.has_report || false;
            const reportInfo = study.report_info || {};
            const reportStatus = reportInfo.ultimo_estado || 'Sin informe';
            const totalReports = reportInfo.total_informes || 0;
            const totalAudios = reportInfo.total_audios || 0;
            
            // Determinar el color del badge según el estado
            let statusBadgeClass = 'bg-secondary';
            let statusText = 'Sin informe';
            let statusIcon = 'fas fa-file-medical';
            
            if (hasReport) {
                switch (reportStatus) {
                    case 'borrador':
                        statusBadgeClass = 'bg-warning';
                        statusText = 'Borrador';
                        statusIcon = 'fas fa-edit';
                        break;
                    case 'finalizado':
                        statusBadgeClass = 'bg-success';
                        statusText = 'Finalizado';
                        statusIcon = 'fas fa-check';
                        break;
                    case 'revisado':
                        statusBadgeClass = 'bg-info';
                        statusText = 'Revisado';
                        statusIcon = 'fas fa-eye';
                        break;
                    case 'firmado':
                        statusBadgeClass = 'bg-primary';
                        statusText = 'Firmado';
                        statusIcon = 'fas fa-signature';
                        break;
                    default:
                        statusBadgeClass = 'bg-secondary';
                        statusText = 'Informe';
                        statusIcon = 'fas fa-file-alt';
                }
                
                if (totalReports > 1) {
                    statusText += ` (${totalReports})`;
                }
            }
            
            return `
                <tr>
                    <td>
                        <div class="fw-medium">${this.escapeHtml(study.patient_name || study.patientName || 'N/A')}</div>
                        <small class="text-muted d-md-none">ID: ${this.escapeHtml(study.patient_id || study.patientId || 'N/A')}</small>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <span class="text-muted">${this.escapeHtml(study.patient_id || study.patientId || 'N/A')}</span>
                    </td>
                    <td>
                        <span class="badge bg-info">${this.escapeHtml(study.modality || 'N/A')}</span>
                    </td>
                    <td class="d-none d-xl-table-cell">
                        <div class="text-truncate" style="max-width: 200px;" title="${this.escapeHtml(study.study_description || study.studyDescription || 'N/A')}">
                            ${this.escapeHtml(study.study_description || study.studyDescription || 'N/A')}
                        </div>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <small>${this.formatStudyDate(study.date || study.studyDate)}</small>
                    </td>
                    <td>
                        <span class="badge ${statusBadgeClass}" title="${hasReport ? 'Informe disponible' : 'Sin informe'}">
                            <i class="${statusIcon} me-1"></i>${statusText}
                        </span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-microphone text-primary me-1"></i>
                            <span class="badge ${totalAudios > 0 ? 'bg-success' : 'bg-secondary'}">
                                ${totalAudios} audio${totalAudios !== 1 ? 's' : ''}
                            </span>
                        </div>
                    </td>
                    <td>
                        <div class="btn-group" role="group">
                            ${hasReport ? 
                                `<button class="btn btn-sm btn-outline-primary" onclick="InformesManager.viewExistingReport('${study.study_id || study.studyId}')" title="Ver informe existente">
                                    <i class="fas fa-eye me-1"></i>Ver Informe
                                </button>
                                ${this.state.canCreateReports ? `
                                <button class="btn btn-sm btn-primary" onclick="InformesManager.selectStudyForNewReport('${study.study_id || study.studyId}', '${study.patient_id || study.patientId}', '${this.escapeHtml(study.patient_name || study.patientName)}', '${study.modality}', '${this.escapeHtml(study.study_description || study.studyDescription)}', '${study.study_instance_uid || study.studyInstanceUID || study.study_id || study.studyId}', '${study.date || study.study_date || study.studyDate || ''}')" title="Crear nuevo informe para otra región">
                                    <i class="fas fa-plus me-1"></i>Nuevo Informe
                                </button>
                                ` : ''}` :
                                `${this.state.canCreateReports ? `
                                <button class="btn btn-sm btn-primary" onclick="InformesManager.selectStudyForNewReport('${study.study_id || study.studyId}', '${study.patient_id || study.patientId}', '${this.escapeHtml(study.patient_name || study.patientName)}', '${study.modality}', '${this.escapeHtml(study.study_description || study.studyDescription)}', '${study.study_instance_uid || study.studyInstanceUID || study.study_id || study.studyId}', '${study.date || study.study_date || study.studyDate || ''}')">
                                    <i class="fas fa-plus me-1"></i>Crear Informe
                                </button>
                                ` : ''}`
                            }
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    },

    /**
     * Formatear fecha del estudio
     */
    formatStudyDate: function(dateStr) {
        if (!dateStr) return 'N/A';
        
        try {
            // Si es formato YYYYMMDD (de Orthanc)
            if (dateStr.length === 8 && /^\d{8}$/.test(dateStr)) {
                const year = dateStr.substring(0, 4);
                const month = dateStr.substring(4, 6);
                const day = dateStr.substring(6, 8);
                const date = new Date(year, month - 1, day);
                return date.toLocaleDateString();
            }
            
            // Si es formato ISO o estándar
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) {
                return 'N/A';
            }
            return date.toLocaleDateString();
        } catch (error) {
            console.error('Error formateando fecha:', dateStr, error);
            return 'N/A';
        }
    },

    /**
     * Actualizar contador de estudios
     */
    updateStudyCount: function() {
        const countElement = document.getElementById('studyCount');
        if (countElement) {
            countElement.textContent = `${this.filteredStudies.length} estudios`;
        }
    },

    /**
     * Actualizar modalidades disponibles basadas en los estudios
     */
    updateAvailableModalities: function() {
        if (!this.allStudies || this.allStudies.length === 0) return;
        
        // Obtener modalidades únicas de los estudios
        const availableModalities = [...new Set(this.allStudies.map(study => study.modality).filter(Boolean))];
        
        // Obtener todos los botones de modalidad
        const modalityButtons = document.querySelectorAll('.modality-btn');
        
        modalityButtons.forEach(btn => {
            const modality = btn.getAttribute('data-modality');
            const isAvailable = availableModalities.includes(modality);
            
            if (isAvailable) {
                btn.style.display = 'inline-block';
                btn.disabled = false;
            } else {
                btn.style.display = 'none';
                btn.disabled = true;
            }
        });
        
        console.log('Modalidades disponibles:', availableModalities);
    },

    /**
     * Restaurar filtros en los campos del modal
     */
    restoreFiltersInModal: function() {
        try {
            const dateFrom = document.getElementById('studyDateFrom');
            const dateTo = document.getElementById('studyDateTo');
            const patientId = document.getElementById('studyPatientId');
            const searchFilter = document.getElementById('studySearchFilter');
            
            if (dateFrom && this.currentStudyFilters.dateFrom) {
                dateFrom.value = this.currentStudyFilters.dateFrom;
            }
            if (dateTo && this.currentStudyFilters.dateTo) {
                dateTo.value = this.currentStudyFilters.dateTo;
            }
            if (patientId && this.currentStudyFilters.patientId) {
                patientId.value = this.currentStudyFilters.patientId;
            }
            if (searchFilter && this.currentStudyFilters.search) {
                searchFilter.value = this.currentStudyFilters.search;
            }
            
            // Restaurar modalidades seleccionadas
            if (this.currentStudyFilters.modalities && this.currentStudyFilters.modalities.length > 0) {
                console.log('Restaurando modalidades:', this.currentStudyFilters.modalities);
                this.currentStudyFilters.modalities.forEach(modality => {
                    const btn = document.querySelector(`[data-modality="${modality}"]`);
                    console.log('Restaurando modalidad:', modality, 'Botón encontrado:', btn);
                    if (btn) {
                        btn.classList.remove('btn-outline-primary');
                        btn.classList.add('btn-primary');
                        
                        // Aplicar estilos inline para estado activo
                        btn.style.backgroundColor = '#0d6efd';
                        btn.style.borderColor = '#0d6efd';
                        btn.style.color = 'white';
                        
                        console.log('Clases del botón después de restaurar:', btn.className);
                    }
                });
                this.updateModalityCounter();
            }
            
            console.log('Filtros restaurados en el modal');
        } catch (error) {
            console.error('Error restaurando filtros en el modal:', error);
        }
    },

    /**
     * Guardar estudios en localStorage
     */
    saveStudiesToCache: function() {
        try {
            const state = {
                studies: this.allStudies,
                filters: this.currentStudyFilters,
                isDataLoaded: this.isStudiesDataLoaded,
                timestamp: Date.now()
            };
            localStorage.setItem(this.studiesStorageKey, JSON.stringify(state));
            console.log('Estudios guardados en caché:', this.allStudies.length);
        } catch (error) {
            console.error('Error guardando estudios en caché:', error);
        }
    },

    /**
     * Cargar estudios desde localStorage
     */
    loadStudiesFromCache: function() {
        try {
            const stored = localStorage.getItem(this.studiesStorageKey);
            if (!stored) return null;
            
            const state = JSON.parse(stored);
            
            // Verificar que el estado no sea muy antiguo (24 horas)
            const maxAge = 24 * 60 * 60 * 1000; // 24 horas en milisegundos
            if (Date.now() - state.timestamp > maxAge) {
                console.log('Caché de estudios expirado, limpiando...');
                this.clearStudiesCache();
                return null;
            }
            
            console.log('Estudios cargados desde caché:', state.studies?.length || 0);
            return state;
        } catch (error) {
            console.error('Error cargando estudios desde caché:', error);
            return null;
        }
    },

    /**
     * Limpiar caché de estudios
     */
    clearStudiesCache: function() {
        try {
            localStorage.removeItem(this.studiesStorageKey);
            console.log('Caché de estudios limpiado');
        } catch (error) {
            console.error('Error limpiando caché de estudios:', error);
        }
    },

    /**
     * Restaurar estado de estudios desde caché
     */
    restoreStudiesState: function() {
        const cachedState = this.loadStudiesFromCache();
        if (cachedState && cachedState.studies && cachedState.studies.length > 0) {
            this.allStudies = cachedState.studies;
            this.filteredStudies = [...cachedState.studies];
            this.currentStudyFilters = cachedState.filters || this.currentStudyFilters;
            this.isStudiesDataLoaded = cachedState.isDataLoaded || false;
            
            console.log('Estado de estudios restaurado desde caché:', this.allStudies.length, 'estudios');
            return true;
        }
        return false;
    },

    /**
     * Ver informe existente
     */
    viewExistingReport: function(studyId) {
        try {
            console.log('Ver informe existente para estudio:', studyId);
            
            // Buscar el informe más reciente para este estudio
            const study = this.allStudies.find(s => (s.study_id || s.studyId) === studyId);
            if (!study) {
                this.showError('No se encontró el estudio');
                return;
            }
            
            const reportInfo = study.report_info || {};
            if (!reportInfo.has_report) {
                this.showError('No hay informe disponible para este estudio');
                return;
            }
            
            // Mostrar información del informe
            const reportStatus = reportInfo.ultimo_estado || 'Desconocido';
            const totalReports = reportInfo.total_informes || 0;
            const lastReportDate = reportInfo.ultima_fecha_informe || 'Desconocida';
            
            const message = `
                <strong>Informe disponible para:</strong><br>
                <strong>Paciente:</strong> ${study.patient_name || study.patientName}<br>
                <strong>Estudio:</strong> ${study.study_description || study.studyDescription}<br>
                <strong>Estado:</strong> ${reportStatus}<br>
                <strong>Total informes:</strong> ${totalReports}<br>
                <strong>Última fecha:</strong> ${lastReportDate}<br><br>
                <strong>¿Qué deseas hacer?</strong><br>
                <small class="text-muted">Nota: Puedes crear múltiples informes para diferentes regiones del mismo estudio.</small>
            `;
            
            // Crear modal de opciones
            const modalHtml = `
                <div class="modal fade" id="existingReportModal" tabindex="-1" aria-labelledby="existingReportModalLabel" aria-hidden="true" style="z-index: 1058;">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="existingReportModalLabel">
                                    <i class="fas fa-file-alt me-2"></i>
                                    Informe Existente
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    ${message}
                                </div>
                                <div class="d-grid gap-2">
                                    <button class="btn btn-primary" onclick="InformesManager.openReportEditor('${studyId}')">
                                        <i class="fas fa-edit me-2"></i>Editar Informe Existente
                                    </button>
                                    <button class="btn btn-outline-primary" onclick="InformesManager.createNewReportVersion('${studyId}')">
                                        <i class="fas fa-plus me-2"></i>Crear Informe para Nueva Región
                                    </button>
                                    <button class="btn btn-outline-secondary" onclick="InformesManager.viewReportHistory('${studyId}')">
                                        <i class="fas fa-history me-2"></i>Ver Historial de Versiones
                                    </button>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('existingReportModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Agregar nuevo modal
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('existingReportModal'));
            modal.show();
            
        } catch (error) {
            console.error('Error viendo informe existente:', error);
            this.showError('Error al acceder al informe: ' + error.message);
        }
    },

    /**
     * Abrir editor de informe existente
     */
    openReportEditor: async function(studyId) {
        try {
            console.log('Abriendo modal de edición para estudio:', studyId);
            
            // Buscar el estudio
            const study = this.allStudies.find(s => (s.study_id || s.studyId) === studyId);
            if (!study) {
                this.showError('No se encontró el estudio');
                return;
            }
            
            const studyInstanceUID = study.study_instance_uid || study.studyInstanceUID || studyId;
            
            // Buscar el informe más reciente para este estudio
            const token = this.getSessionToken();
            const response = await fetch(`${this.config.apiBaseUrl}/get.php?study_instance_uid=${encodeURIComponent(studyInstanceUID)}`, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const data = await response.json();
            
            console.log('Respuesta del API:', data);
            
            if (!data.success) {
                this.showError('No se encontró informe para este estudio');
                return;
            }
            
            let informe;
            
            // Manejar diferentes estructuras de respuesta del API
            if (data.data && data.data.id) {
                // Respuesta con informe único
                informe = data.data;
                console.log('Informe único:', informe);
            } else if (data.data && data.data.informes) {
                // Respuesta con múltiples informes (búsqueda por estudio)
                const informes = data.data.informes;
                console.log('Informes encontrados:', informes);
                if (informes.length === 0) {
                    this.showError('No se encontró informe para este estudio');
                    return;
                }
                // Tomar el primer informe (más reciente)
                informe = informes[0];
                console.log('Informe seleccionado:', informe);
            } else {
                console.error('Estructura de datos no reconocida:', data);
                this.showError('No se encontró informe para este estudio');
                return;
            }
            
            // Cerrar el modal de opciones
            const existingModal = bootstrap.Modal.getInstance(document.getElementById('existingReportModal'));
            if (existingModal) {
                existingModal.hide();
            }
            
            // Abrir el modal de edición
            await this.openReportModal(informe.id, false);
            
        } catch (error) {
            console.error('Error abriendo editor:', error);
            this.showError('Error al abrir el editor: ' + error.message);
        }
    },

    /**
     * Alternar visibilidad de un grupo de estudios
     */
    toggleStudyGroup: function(groupId) {
        // Obtener todas las filas del grupo usando la clase específica
        const groupRows = document.querySelectorAll(`tr.study-group-row-${groupId}`);
        const iconElement = document.querySelector(`.collapse-icon-${groupId}`);
        
        if (!groupRows || groupRows.length === 0) return;
        
        // Verificar si el grupo está expandido o colapsado
        const firstRow = groupRows[0];
        const isCollapsed = firstRow.classList.contains('collapse') && !firstRow.classList.contains('show');
        
        // Alternar visibilidad de todas las filas del grupo
        groupRows.forEach(row => {
            if (isCollapsed) {
                // Expandir: remover clase collapse y agregar show
                row.classList.remove('collapse');
                row.classList.add('show');
                row.style.display = '';
            } else {
                // Colapsar: agregar clase collapse y remover show
                row.classList.add('collapse');
                row.classList.remove('show');
                row.style.display = 'none';
            }
        });
        
        // Rotar icono según el estado
        if (iconElement) {
            if (isCollapsed) {
                iconElement.style.transform = 'rotate(90deg)';
            } else {
                iconElement.style.transform = 'rotate(0deg)';
            }
        }
    },

    /**
     * Crear nuevo informe desde un grupo de estudios
     */
    createNewReportFromStudy: async function(studyId, patientId, patientName, modality, studyDescription, studyInstanceUID) {
        try {
            // Cerrar modal actual si está abierto
            const currentModal = bootstrap.Modal.getInstance(document.getElementById('reportEditorModal'));
            if (currentModal) {
                currentModal.hide();
            }
            
            // Crear nuevo informe con los datos del estudio
            const tempReport = {
                id: null, // Nuevo informe
                estudio_id: studyInstanceUID || studyId,
                study_instance_uid: studyInstanceUID || studyId,
                study_id: studyId,
                // Usar datos del estudio
                patient_id: patientId || '',
                patient_name: patientName || '',
                modality: modality || '',
                study_description: studyDescription || '',
                // También incluir nombres en español para compatibilidad con la UI
                nombre_paciente: patientName || '',
                paciente_id: patientId || '',
                modalidad: modality || '',
                descripcion_estudio: studyDescription || '',
                contenido_html: '',
                estado: 'borrador',
                version: 1,
                fecha_creacion: new Date().toISOString(),
                fecha_modificacion: new Date().toISOString()
            };
            
            // Abrir modal de edición con el nuevo informe temporal
            await this.openReportModal(null, false, tempReport);
            
            console.log('Nuevo informe creado desde grupo de estudios');
            
        } catch (error) {
            console.error('Error creando nuevo informe desde grupo:', error);
            this.showError('Error al crear nuevo informe: ' + error.message);
        }
    },

    /**
     * Crear nuevo informe desde un informe existente (mismo estudio)
     */
    createNewReportFromExisting: async function(reportId) {
        try {
            // Cargar datos del informe existente
            const token = this.getSessionToken();
            const response = await fetch(`${this.config.apiBaseUrl}/get.php?informe_id=${reportId}`, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (!data.success || !data.data) {
                throw new Error(data.error || 'Error al cargar el informe');
            }
            
            const existingReport = data.data;
            
            // Crear nuevo informe con los mismos datos del estudio
            const tempReport = {
                id: null, // Nuevo informe
                estudio_id: existingReport.estudio_id || existingReport.study_instance_uid,
                study_instance_uid: existingReport.study_instance_uid || existingReport.estudio_id,
                study_id: existingReport.study_id || '',
                // Usar datos del informe existente (solo datos del estudio, NO del usuario)
                patient_id: existingReport.patient_id || existingReport.paciente_id || '',
                patient_name: existingReport.patient_name || existingReport.nombre_paciente || '',
                modality: existingReport.modality || existingReport.modalidad || '',
                study_description: existingReport.study_description || existingReport.descripcion_estudio || '',
                // También incluir nombres en español para compatibilidad con la UI
                nombre_paciente: existingReport.patient_name || existingReport.nombre_paciente || '',
                paciente_id: existingReport.patient_id || existingReport.paciente_id || '',
                modalidad: existingReport.modality || existingReport.modalidad || '',
                descripcion_estudio: existingReport.study_description || existingReport.descripcion_estudio || '',
                contenido_html: '',
                estado: 'borrador',
                version: 1,
                fecha_creacion: new Date().toISOString(),
                fecha_modificacion: new Date().toISOString()
                // NO incluir usuario_id, usuario_nombre, usuario_apellido - se asignará al guardar
            };
            
            // Cerrar modal actual si está abierto
            const currentModal = bootstrap.Modal.getInstance(document.getElementById('reportEditorModal'));
            if (currentModal) {
                currentModal.hide();
            }
            
            // Abrir modal de edición con el nuevo informe temporal
            await this.openReportModal(null, false, tempReport);
            
            console.log('Nuevo informe creado desde informe existente:', reportId);
            
        } catch (error) {
            console.error('Error creando nuevo informe desde existente:', error);
            this.showError('Error al crear nuevo informe: ' + error.message);
        }
    },

    /**
     * Crear nuevo informe desde el informe actual en el modal
     */
    createNewReportFromCurrent: async function() {
        try {
            if (!this.state.selectedReport) {
                this.showError('No hay informe seleccionado');
                return;
            }
            
            const currentReport = this.state.selectedReport;
            
            // Si hay cambios sin guardar, mostrar advertencia con modal de Bootstrap
            if (this.state.hasUnsavedChanges && this.hasRealChanges()) {
                this.showConfirmModal(
                    'Cambios sin guardar',
                    'Tienes cambios sin guardar. ¿Deseas crear un nuevo informe de todos modos? Los cambios no guardados se perderán.',
                    async () => {
                        // Usuario confirmó, continuar con la creación
                        this.clearChangesState();
                        // Llamar recursivamente sin el flag de cambios
                        await this.createNewReportFromCurrent();
                    },
                    () => {
                        // Usuario canceló, no hacer nada
                        console.log('Usuario canceló creación de nuevo informe');
                    }
                );
                return;
            }
            
            // Crear nuevo informe con los mismos datos del estudio
            const tempReport = {
                id: null, // Nuevo informe
                estudio_id: currentReport.estudio_id || currentReport.study_instance_uid,
                study_instance_uid: currentReport.study_instance_uid || currentReport.estudio_id,
                study_id: currentReport.study_id || '',
                // Usar datos del informe actual (solo datos del estudio, NO del usuario)
                patient_id: currentReport.patient_id || currentReport.paciente_id || '',
                patient_name: currentReport.patient_name || currentReport.nombre_paciente || '',
                modality: currentReport.modality || currentReport.modalidad || '',
                study_description: currentReport.study_description || currentReport.descripcion_estudio || '',
                // También incluir nombres en español para compatibilidad con la UI
                nombre_paciente: currentReport.patient_name || currentReport.nombre_paciente || '',
                paciente_id: currentReport.patient_id || currentReport.paciente_id || '',
                modalidad: currentReport.modality || currentReport.modalidad || '',
                descripcion_estudio: currentReport.study_description || currentReport.descripcion_estudio || '',
                contenido_html: '',
                estado: 'borrador',
                version: 1,
                fecha_creacion: new Date().toISOString(),
                fecha_modificacion: new Date().toISOString()
                // NO incluir usuario_id, usuario_nombre, usuario_apellido - se asignará al guardar
            };
            
            // Cerrar modal actual
            const currentModal = bootstrap.Modal.getInstance(document.getElementById('reportEditorModal'));
            if (currentModal) {
                currentModal.hide();
            }
            
            // Abrir modal de edición con el nuevo informe temporal
            await this.openReportModal(null, false, tempReport);
            
            console.log('Nuevo informe creado desde informe actual');
            
        } catch (error) {
            console.error('Error creando nuevo informe desde actual:', error);
            this.showError('Error al crear nuevo informe: ' + error.message);
        }
    },

    /**
     * Crear nueva versión del informe
     */
    createNewReportVersion: function(studyId) {
        // Por ahora, redirigir al editor como si fuera un nuevo informe
        this.selectStudyForNewReport(studyId, '', '', '', '', studyId);
    },

    /**
     * Ver historial de versiones del informe
     */
    viewReportHistory: function(studyId) {
        // Implementar vista de historial de versiones
        this.showToast('Función de historial en desarrollo', 'info');
    },

    /**
     * Seleccionar estudio para nuevo informe
     */
    selectStudyForNewReport: function(studyId, patientId, patientName, modality, studyDescription, studyInstanceUID, studyDate) {
        try {
            console.log('Seleccionando estudio para nuevo informe:', { studyId, patientId, patientName, modality, studyDescription, studyInstanceUID, studyDate });
            
            // Establecer estudio activo
            if (window.urlStudyManager) {
                window.urlStudyManager.setActiveStudy({
                    patientId: patientId,
                    patientName: patientName,
                    modality: modality,
                    studyDescription: studyDescription,
                    studyId: studyId,
                    studyInstanceUID: studyInstanceUID,
                    studyDate: studyDate || ''
                });
            }
            
            // Cerrar modal de selección de estudios
            const modal = bootstrap.Modal.getInstance(document.getElementById('studySelectionModal'));
            if (modal) {
                modal.hide();
            }
            
            // Crear un informe temporal para el modal de edición
            const tempReport = {
                id: null, // Nuevo informe
                estudio_id: studyInstanceUID,
                study_instance_uid: studyInstanceUID,
                study_id: studyId,
                // Usar nombres en inglés para consistencia con la API
                patient_id: patientId,
                patient_name: patientName,
                modality: modality,
                study_description: studyDescription,
                // También incluir nombres en español para compatibilidad con la UI
                nombre_paciente: patientName,
                paciente_id: patientId,
                modalidad: modality,
                descripcion_estudio: studyDescription,
                contenido_html: '',
                estado: 'borrador',
                version: 1,
                fecha_creacion: new Date().toISOString(),
                fecha_modificacion: new Date().toISOString()
            };
            
            // Abrir modal de edición con el informe temporal
            this.openReportModal(null, false, tempReport);
            
            console.log('Modal de edición abierto para nuevo informe');
            
        } catch (error) {
            console.error('Error seleccionando estudio:', error);
            this.showError('Error al seleccionar estudio: ' + error.message);
        }
    },

    /**
     * Escapar HTML para prevenir XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },



    /**
     * Resetear controles del reproductor
     */
    resetAudioPlayerControls: function() {
        document.getElementById('audioPlayerStatus').textContent = 'Detenido';
        document.getElementById('audioPlayerDuration').textContent = '00:00';
        document.getElementById('audioPlayerProgress').textContent = '0%';
        document.getElementById('audioPlayerCurrentTime').textContent = '00:00';
        document.getElementById('audioPlayerTotalTime').textContent = '00:00';
        document.getElementById('audioPlayerProgressBarFill').style.width = '0%';
        document.getElementById('audioPlayerPlayPauseIcon').className = 'fas fa-play';
    },

    /**
     * Alternar reproducción/pausa del reproductor
     */
    toggleAudioPlayerPlayPause: function() {
        const audioElement = document.getElementById('audioPlayerElement');
        const playPauseIcon = document.getElementById('audioPlayerPlayPauseIcon');
        const statusElement = document.getElementById('audioPlayerStatus');
        
        if (!audioElement.src) {
            this.showError('No hay audio cargado para reproducir');
            return;
        }
        
        if (audioElement.paused) {
            const playPromise = audioElement.play();
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    playPauseIcon.className = 'fas fa-pause';
                    statusElement.textContent = 'Reproduciendo';
                }).catch(error => {
                    console.error('Error reproduciendo audio:', error);
                    this.showError('Error al reproducir el audio: ' + error.message);
                });
            }
        } else {
            audioElement.pause();
            playPauseIcon.className = 'fas fa-play';
            statusElement.textContent = 'Pausado';
        }
    },

    /**
     * Detener reproducción del reproductor
     */
    stopAudioPlayer: function() {
        const audioElement = document.getElementById('audioPlayerElement');
        const playPauseIcon = document.getElementById('audioPlayerPlayPauseIcon');
        const statusElement = document.getElementById('audioPlayerStatus');
        
        audioElement.pause();
        audioElement.currentTime = 0;
        playPauseIcon.className = 'fas fa-play';
        statusElement.textContent = 'Detenido';
        
        // Resetear progreso
        document.getElementById('audioPlayerCurrentTime').textContent = '00:00';
        document.getElementById('audioPlayerProgressBarFill').style.width = '0%';
        document.getElementById('audioPlayerProgress').textContent = '0%';
    },

    /**
     * Establecer volumen del reproductor
     */
    setAudioPlayerVolume: function(volume) {
        const audioElement = document.getElementById('audioPlayerElement');
        audioElement.volume = volume / 100;
    },

    /**
     * Buscar posición en el audio
     */
    seekAudio: function(event) {
        const audioElement = document.getElementById('audioPlayerElement');
        const progressBar = document.getElementById('audioPlayerProgressBar');
        const rect = progressBar.getBoundingClientRect();
        const clickX = event.clientX - rect.left;
        const percentage = clickX / rect.width;
        const newTime = percentage * audioElement.duration;
        
        audioElement.currentTime = newTime;
    },

    /**
     * Formatear tamaño de archivo
     */
    formatFileSize: function(bytes) {
        if (!bytes) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    /**
     * Formatear fecha
     */
    formatDate: function(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    },

    /**
     * Formatear tiempo en segundos a MM:SS
     */
    formatTime: function(seconds) {
        // Validar que sea un número válido y finito
        if (!seconds || isNaN(seconds) || !isFinite(seconds) || seconds < 0) {
            return '00:00';
        }
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    },

    /**
     * Obtener color del badge según el estado
     */
    getEstadoBadgeColor: function(estado) {
        if (!estado) return 'secondary';
        
        const estadoLower = estado.toLowerCase();
        switch (estadoLower) {
            case 'borrador':
                return 'secondary';
            case 'transcripto':
                return 'info';
            case 'firmado':
                return 'primary';
            case 'finalizado':
            case 'revisado':
            case 'completado':
                return 'success';
            case 'pendiente':
                return 'info';
            case 'rechazado':
            case 'cancelado':
                return 'danger';
            case 'en_revision':
            case 'en revision':
                return 'warning';
            default:
                return 'secondary';
        }
    },

    /**
     * Inicializar eventos del reproductor de audio
     */
    initAudioPlayerEvents: function() {
        const audioElement = document.getElementById('mainAudioElement');
        
        if (!audioElement) return;
        
        // Evento cuando se carga metadata
        audioElement.addEventListener('loadedmetadata', () => {
            console.log('Audio metadata loaded');
        });
        
        // Evento de actualización de tiempo
        audioElement.addEventListener('timeupdate', () => {
            // El elemento audio nativo maneja la visualización del progreso
        });
        
        // Evento cuando termina la reproducción
        audioElement.addEventListener('ended', () => {
            this.state.isAudioPlaying = false;
            this.updateAudioListState(this.state.currentAudioIndex);
        });
        
        // Evento cuando se reproduce
        audioElement.addEventListener('play', () => {
            this.state.isAudioPlaying = true;
            this.updateAudioListState(this.state.currentAudioIndex);
        });
        
        // Evento cuando se pausa
        audioElement.addEventListener('pause', () => {
            this.state.isAudioPlaying = false;
            this.updateAudioListState(this.state.currentAudioIndex);
        });
        
        // Evento de error
        audioElement.addEventListener('error', (e) => {
            console.error('Error en reproductor de audio:', e);
            this.showError('Error al cargar el archivo de audio');
            this.state.isAudioPlaying = false;
            this.updateAudioListState(this.state.currentAudioIndex);
        });
    },

    /**
     * Cargar audios del informe y/o estudio en el modal de edición.
     * - Si hay informeId: carga audios por informe_id.
     * - Si además hay estudioId: también carga por estudio_id y fusiona resultados (sin duplicar IDs).
     * - Si solo hay estudioId (informe nuevo): carga solo por estudio.
     * @param {number|null} informeId - ID del informe (opcional)
     * @param {string|null} estudioId - ID del estudio (opcional)
     */
    loadEditModalAudios: async function(informeId, estudioId = null) {
        try {
            const token = this.getCookie('session_token') || localStorage.getItem('sessionToken');
            if (!token) {
                console.warn('⚠️ Sin token de sesión para cargar audios del modal de edición');
            }

            const allAudios = [];
            const knownIds = new Set();

            // Helper para hacer fetch y fusionar resultados
            const fetchAndMerge = async (url, label) => {
                console.log(`🔄 Cargando audios (${label}):`, url);
                const resp = await fetch(url, {
                    headers: token ? { 'Authorization': `Bearer ${token}` } : {}
                });
                if (!resp.ok) {
                    console.warn(`⚠️ Error HTTP al cargar audios (${label}):`, resp.status);
                    return;
                }
                const data = await resp.json();
                console.log(`📥 Respuesta audios (${label}):`, data);
                if (!data.success || !Array.isArray(data.data)) return;
                for (const audio of data.data) {
                    if (!knownIds.has(audio.id)) {
                        knownIds.add(audio.id);
                        allAudios.push(audio);
                    }
                }
            };

            // 1) Audios ligados directamente al informe (incluye móviles ya vinculados con link.php)
            if (informeId && informeId !== 'null' && informeId !== null) {
                const urlInforme = `../api/audios/get.php?informe_id=${encodeURIComponent(informeId)}`;
                await fetchAndMerge(urlInforme, `informe ${informeId}`);
            }

            // 2) Audios del estudio como respaldo (por si hay audios móviles temporales aún sin informe_id)
            if (estudioId) {
                const urlEstudio = `../api/audios/get.php?estudio_id=${encodeURIComponent(estudioId)}`;
                await fetchAndMerge(urlEstudio, `estudio ${estudioId}`);
            }

            console.log('📊 Audios fusionados para modal de edición:', {
                informeId,
                estudioId,
                total: allAudios.length,
                ids: allAudios.map(a => a.id)
            });

            if (allAudios.length > 0) {
                this.populateEditAudioList(allAudios);
                this.showEditAudioSection(true);
            } else {
                this.showEditAudioSection(false);
            }
        } catch (error) {
            console.error('Error cargando audios en modal de edición:', error);
            this.showEditAudioSection(false);
        }
    },

    /**
     * Poblar lista de audios en el modal de edición
     */
    populateEditAudioList: function(audios) {
        const audioList = document.getElementById('editAudioList');
        const audioSelect = document.getElementById('editAudioSelect');
        
        // Almacenar audios para uso posterior
        this.state.editModalAudios = audios;
        
        // Limpiar opciones existentes
        audioSelect.innerHTML = '<option value="">Selecciona un audio...</option>';
        
        // Crear lista visual de audios
        let audioListHtml = '<div class="row">';
        audios.forEach((audio, index) => {
            const hasTranscription = audio.transcripcion && audio.transcripcion.trim() !== '';
            const transcriptionBtn = hasTranscription 
                ? `<button class="btn btn-sm btn-outline-success ms-1" onclick="InformesManager.showTranscription(${audio.id})" title="Ver transcripción">
                    <i class="fas fa-file-alt"></i>
                </button>`
                : '';

            // Ya transcripto: no ofrecer reenvío. Solo stuck/failed sin texto.
            const canRequeueTx = !hasTranscription && (audio.tx_is_stuck || audio.tx_is_failed);
            const requeueLocked = this.isAudioRequeueLocked(audio.id);
            let txStatusHtml = '';
            if (hasTranscription && !audio.tx_is_stuck && !audio.tx_is_failed) {
                // Estado OK implícito vía botón ver transcripción; sin badge de reintento
            } else if (audio.tx_is_stuck && !hasTranscription) {
                const mins = parseInt(audio.tx_minutes_waiting, 10) || 0;
                txStatusHtml = `
                    <div class="mt-1">
                        <span class="badge bg-danger" title="Posible servidor de transcripción colgado">
                            <i class="fas fa-exclamation-triangle me-1"></i>TX colgada${mins > 0 ? ` (${mins} min)` : ''}
                        </span>
                        ${this.requeueTxButtonHtml(audio.id, requeueLocked)}
                    </div>`;
            } else if (audio.tx_is_failed && !hasTranscription) {
                txStatusHtml = `
                    <div class="mt-1">
                        <span class="badge bg-danger"><i class="fas fa-times me-1"></i>TX fallida</span>
                        ${this.requeueTxButtonHtml(audio.id, requeueLocked)}
                    </div>`;
            } else if (audio.tx_is_pending || (requeueLocked && canRequeueTx)) {
                txStatusHtml = `
                    <div class="mt-1">
                        <span class="badge bg-warning text-dark">
                            <i class="fas fa-spinner fa-spin me-1"></i>Transcribiendo...
                        </span>
                    </div>`;
            } else if (canRequeueTx) {
                // fallback por si flags llegan incompletos
                txStatusHtml = `
                    <div class="mt-1">
                        ${this.requeueTxButtonHtml(audio.id, requeueLocked)}
                    </div>`;
            }
            
            audioListHtml += `
                <div class="col-md-6 mb-2">
                    <div class="card border">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Audio ${index + 1}</h6>
                                    <small class="text-muted">${audio.nombre_original || audio.nombre_archivo}</small>
                                    <br>
                                    <small class="text-muted">${this.formatFileSize(audio.tamano_bytes)} - ${this.formatDate(audio.fecha_creacion)}</small>
                                    ${txStatusHtml}
                                </div>
                                <div class="d-flex">
                                    <button class="btn btn-sm btn-outline-primary" onclick="InformesManager.selectEditAudio('${audio.id}')">
                                        <i class="fas fa-play"></i>
                                    </button>
                                    ${transcriptionBtn}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar opción al select
            const option = document.createElement('option');
            option.value = audio.id;
            option.textContent = `Audio ${index + 1}: ${audio.nombre_original || audio.nombre_archivo}`;
            audioSelect.appendChild(option);
        });
        audioListHtml += '</div>';
        
        audioList.innerHTML = audioListHtml;
        
        // Agregar listener al select para cargar audio cuando se seleccione
        audioSelect.addEventListener('change', (e) => {
            const selectedAudioId = e.target.value;
            if (selectedAudioId) {
                this.selectEditAudio(selectedAudioId);
            } else {
                this.resetEditAudioControls();
            }
        });
        
        // Si hay un solo audio, seleccionarlo automáticamente
        if (audios.length === 1) {
            audioSelect.value = audios[0].id;
            this.selectEditAudio(audios[0].id);
        }
    },

    /**
     * Mostrar/ocultar sección de audio en modal de edición
     */
    showEditAudioSection: function(show) {
        const audioSection = document.getElementById('audioSection');
        const audioPlayerControls = document.getElementById('editAudioPlayerControls');
        
        if (show) {
            audioSection.style.display = 'block';
            audioPlayerControls.style.display = 'block';
        } else {
            audioSection.style.display = 'none';
            audioPlayerControls.style.display = 'none';
        }
    },

    /**
     * Seleccionar audio en el modal de edición
     */
    selectEditAudio: function(audioId) {
        if (!audioId) {
            this.resetEditAudioControls();
            return;
        }
        
        // Buscar datos del audio seleccionado
        const audio = this.state.editModalAudios?.find(a => a.id == audioId || a.id == parseInt(audioId));
        if (!audio) {
            console.error('Audio no encontrado:', audioId, 'Audios disponibles:', this.state.editModalAudios);
            return;
        }
        
        // Debug: mostrar datos del audio
        console.log('🎵 Cargando audio:', {
            id: audio.id,
            nombre_original: audio.nombre_original,
            nombre_archivo: audio.nombre_archivo,
            tamano_bytes: audio.tamano_bytes,
            fecha_creacion: audio.fecha_creacion,
            audio_completo: audio
        });
        
        // Actualizar select
        const audioSelect = document.getElementById('editAudioSelect');
        if (audioSelect) {
            audioSelect.value = audioId;
        }
        
        // Construir URL del audio - manejar ambos casos: url_completa o ruta_archivo
        let audioUrl = '';
        if (audio.url_completa) {
            // Si viene de api/audios/get.php, tiene url_completa
            audioUrl = audio.url_completa.startsWith('uploads/') 
                ? '../' + audio.url_completa 
                : audio.url_completa;
        } else if (audio.ruta_archivo) {
            // Si viene de informe.audios (directo de BD), tiene ruta_archivo
            // Construir la URL completa basada en ruta_archivo
            if (audio.ruta_archivo.startsWith('uploads/')) {
                audioUrl = '../' + audio.ruta_archivo;
            } else if (audio.ruta_archivo.startsWith('../')) {
                audioUrl = audio.ruta_archivo;
            } else {
                // Si no tiene prefijo, asumir que está en uploads/audios/
                audioUrl = '../uploads/audios/' + audio.ruta_archivo;
            }
        } else {
            console.error('Audio no tiene url_completa ni ruta_archivo:', audio);
            this.showError('Error: No se pudo determinar la ruta del audio');
            return;
        }
        
        // Configurar el elemento de audio
        const audioElement = document.getElementById('editAudioElement');
        if (audioElement) {
            // Limpiar eventos anteriores clonando el elemento
            const newAudioElement = audioElement.cloneNode(true);
            audioElement.parentNode.replaceChild(newAudioElement, audioElement);
            
            // Obtener referencia al nuevo elemento limpio
            const cleanElement = document.getElementById('editAudioElement');
            
            // Configurar src y forzar carga de metadatos
            cleanElement.src = audioUrl;
            cleanElement.preload = 'metadata';
            
            // Inicializar eventos del reproductor ANTES de cargar
            this.initEditAudioPlayerEvents();
            
            // Cargar el audio después de inicializar eventos
            cleanElement.load();
        } else {
            console.error('Elemento editAudioElement no encontrado');
            return;
        }
        
        // Inicializar indicadores de velocidad y volumen
        const speedStatus = document.getElementById('editSpeedStatus');
        const volumeStatus = document.getElementById('editVolumeStatus');
        const volumeSlider = document.getElementById('editVolumeSlider');
        
        if (speedStatus) speedStatus.textContent = '1x';
        if (volumeStatus) volumeStatus.textContent = '100%';
        if (volumeSlider) {
            volumeSlider.value = '100';
            // Asegurar que el volumen inicial sea 100%
            const audioEl = document.getElementById('editAudioElement');
            if (audioEl) {
                audioEl.volume = 1.0;
            }
        }
        
        // Resetear controles
        this.resetEditAudioControls();
        
        // Inicializar panel flotante de transcripción si tiene segments
        // Solo mostrar si el modal está abierto
        const modalElement = document.getElementById('reportEditorModal');
        if (modalElement && modalElement.classList.contains('show')) {
            if (audio.segments && audio.segments.length > 0 && audio.transcripcion) {
                this.initFloatingTranscriptionPanel(audio);
            } else {
                this.hideFloatingTranscriptionPanel();
            }
        }
        
        console.log('Audio seleccionado:', audio.nombre_original || audio.nombre_archivo, 'URL:', audioUrl);
    },

    /**
     * Alternar reproducción/pausa en modal de edición
     */
    toggleEditAudioPlayPause: function() {
        const audioElement = document.getElementById('editAudioElement');
        const playPauseIcon = document.getElementById('editPlayPauseIcon');
        const statusElement = document.getElementById('editAudioStatus');
        
        if (!audioElement || !audioElement.src) {
            this.showError('No hay audio cargado');
            return;
        }
        
        if (audioElement.paused) {
            // Si el audio no está listo, esperar a que se cargue
            if (audioElement.readyState < 2) {
                statusElement.textContent = 'Cargando audio...';
                playPauseIcon.className = 'fas fa-spinner fa-spin';
                
                // Esperar a que el audio esté listo
                const onCanPlay = () => {
                    audioElement.play().then(() => {
                        playPauseIcon.className = 'fas fa-pause';
                        statusElement.textContent = 'Reproduciendo';
                    }).catch(error => {
                        console.error('Error reproduciendo audio:', error);
                        this.showError('Error al reproducir el audio');
                        playPauseIcon.className = 'fas fa-play';
                        statusElement.textContent = 'Error';
                    });
                    audioElement.removeEventListener('canplay', onCanPlay);
                };
                
                audioElement.addEventListener('canplay', onCanPlay, { once: true });
                
                // Timeout de 5 segundos
                setTimeout(() => {
                    if (audioElement.readyState < 2) {
                        audioElement.removeEventListener('canplay', onCanPlay);
                        playPauseIcon.className = 'fas fa-play';
                        statusElement.textContent = 'Error al cargar';
                        this.showError('Timeout: El audio tardó demasiado en cargar');
                    }
                }, 5000);
            } else {
                // El audio ya está listo, reproducir directamente
                audioElement.play().then(() => {
                    playPauseIcon.className = 'fas fa-pause';
                    statusElement.textContent = 'Reproduciendo';
                }).catch(error => {
                    console.error('Error reproduciendo audio:', error);
                    this.showError('Error al reproducir el audio');
                    playPauseIcon.className = 'fas fa-play';
                    statusElement.textContent = 'Error';
                });
            }
        } else {
            audioElement.pause();
            playPauseIcon.className = 'fas fa-play';
            statusElement.textContent = 'Pausado';
        }
    },

    /**
     * Detener audio en modal de edición
     */
    stopEditAudio: function() {
        const audioElement = document.getElementById('editAudioElement');
        const playPauseIcon = document.getElementById('editPlayPauseIcon');
        const statusElement = document.getElementById('editAudioStatus');
        
        if (!audioElement) return;
        
        audioElement.pause();
        audioElement.currentTime = 0;
        playPauseIcon.className = 'fas fa-play';
        statusElement.textContent = 'Detenido';
        
        // Resetear progreso
        document.getElementById('editCurrentTime').textContent = '00:00';
        document.getElementById('editAudioProgressBarFill').style.width = '0%';
    },

    /**
     * Detener y hacer unload del audio cuando se cierra el modal
     */
    cleanupEditAudio: function() {
        const audioElement = document.getElementById('editAudioElement');
        
        if (!audioElement) {
            return;
        }
        
        try {
            // Detener reproducción si está activa
            if (!audioElement.paused) {
                audioElement.pause();
            }
            
            // Resetear tiempo solo si es válido
            try {
                if (isFinite(audioElement.currentTime)) {
                    audioElement.currentTime = 0;
                }
            } catch (e) {
                // Ignorar errores al resetear currentTime
            }
            
            // No limpiar el src aquí porque puede causar errores con los event listeners activos
            // El elemento se limpiará cuando el modal se destruya completamente
            // Solo pausamos y reseteamos para detener la reproducción
            
            // Resetear controles visuales solo si los elementos existen y el modal aún está visible
            const modal = document.getElementById('reportEditorModal');
            if (modal && modal.classList.contains('show')) {
                const statusEl = document.getElementById('editAudioStatus');
                const currentTimeEl = document.getElementById('editCurrentTime');
                const totalTimeEl = document.getElementById('editTotalTime');
                const progressBarFill = document.getElementById('editAudioProgressBarFill');
                const playPauseIcon = document.getElementById('editPlayPauseIcon');
                const speedStatus = document.getElementById('editSpeedStatus');
                const volumeStatus = document.getElementById('editVolumeStatus');
                
                if (statusEl) statusEl.textContent = 'Detenido';
                if (currentTimeEl) currentTimeEl.textContent = '00:00';
                if (totalTimeEl) totalTimeEl.textContent = '00:00';
                if (progressBarFill) progressBarFill.style.width = '0%';
                if (playPauseIcon) playPauseIcon.className = 'fas fa-play';
                if (speedStatus) speedStatus.textContent = '1x';
                if (volumeStatus) volumeStatus.textContent = '100%';
            }
            
            console.log('✅ Audio detenido y liberado al cerrar el modal');
        } catch (error) {
            // Ignorar errores silenciosamente ya que el modal se está cerrando
            console.debug('Audio limpiado (modal cerrado):', error.message);
        }
    },

    /**
     * Establecer velocidad de reproducción en modal de edición
     */
    setEditAudioSpeed: function(speed) {
        const audioElement = document.getElementById('editAudioElement');
        const speedStatus = document.getElementById('editSpeedStatus');
        if (audioElement) {
            audioElement.playbackRate = parseFloat(speed);
            if (speedStatus) {
                speedStatus.textContent = speed + 'x';
            }
            console.log('Velocidad establecida:', speed);
        }
    },

    /**
     * Avanzar audio en modal de edición
     */
    forwardEditAudio: function(seconds = 10) {
        const audioElement = document.getElementById('editAudioElement');
        if (audioElement && audioElement.duration) {
            audioElement.currentTime = Math.min(audioElement.currentTime + seconds, audioElement.duration);
        }
    },

    /**
     * Retroceder audio en modal de edición
     */
    rewindEditAudio: function(seconds = 10) {
        const audioElement = document.getElementById('editAudioElement');
        if (audioElement) {
            audioElement.currentTime = Math.max(audioElement.currentTime - seconds, 0);
        }
    },

    /**
     * Establecer volumen en modal de edición
     */
    setEditAudioVolume: function(volume) {
        const audioElement = document.getElementById('editAudioElement');
        const volumeStatus = document.getElementById('editVolumeStatus');
        if (audioElement) {
            // Convertir porcentaje (0-100) a rango de audio (0.0-1.0)
            audioElement.volume = Math.max(0, Math.min(1, volume / 100));
            if (volumeStatus) {
                volumeStatus.textContent = Math.round(volume) + '%';
            }
        }
    },

    /**
     * Subir volumen en modal de edición
     */
    increaseEditAudioVolume: function(step = 10) {
        const audioElement = document.getElementById('editAudioElement');
        const volumeSlider = document.getElementById('editVolumeSlider');
        if (audioElement && volumeSlider) {
            const currentVolume = parseFloat(volumeSlider.value);
            const newVolume = Math.min(currentVolume + step, 100);
            volumeSlider.value = newVolume;
            this.setEditAudioVolume(newVolume);
        }
    },

    /**
     * Bajar volumen en modal de edición
     */
    decreaseEditAudioVolume: function(step = 10) {
        const audioElement = document.getElementById('editAudioElement');
        const volumeSlider = document.getElementById('editVolumeSlider');
        if (audioElement && volumeSlider) {
            const currentVolume = parseFloat(volumeSlider.value);
            const newVolume = Math.max(currentVolume - step, 0);
            volumeSlider.value = newVolume;
            this.setEditAudioVolume(newVolume);
        }
    },

    /**
     * Alternar silencio en modal de edición
     */
    toggleEditAudioMute: function() {
        const audioElement = document.getElementById('editAudioElement');
        if (audioElement) {
            audioElement.muted = !audioElement.muted;
        }
    },

    /**
     * Buscar posición en audio del modal de edición
     */
    seekEditAudio: function(event) {
        const audioElement = document.getElementById('editAudioElement');
        const progressBar = document.getElementById('editAudioProgressBar');
        
        if (!audioElement || !progressBar) {
            return;
        }
        
        // Validar que el audio tenga una duración válida y finita
        const duration = audioElement.duration;
        if (!duration || !isFinite(duration) || duration <= 0) {
            console.warn('No se puede hacer seek: duración del audio no válida', duration);
            return;
        }
        
        const rect = progressBar.getBoundingClientRect();
        const clickX = event.clientX - rect.left;
        const percentage = Math.max(0, Math.min(1, clickX / rect.width));
        const newTime = percentage * duration;
        
        // Validar que el nuevo tiempo sea finito antes de establecerlo
        if (isFinite(newTime) && newTime >= 0 && newTime <= duration) {
            audioElement.currentTime = newTime;
        } else {
            console.warn('No se puede hacer seek: tiempo calculado no válido', newTime);
        }
    },

    /**
     * Resetear controles del modal de edición
     */
    resetEditAudioControls: function() {
        const statusEl = document.getElementById('editAudioStatus');
        const currentTimeEl = document.getElementById('editCurrentTime');
        const totalTimeEl = document.getElementById('editTotalTime');
        const progressBarFill = document.getElementById('editAudioProgressBarFill');
        const playPauseIcon = document.getElementById('editPlayPauseIcon');
        const speedStatus = document.getElementById('editSpeedStatus');
        const volumeStatus = document.getElementById('editVolumeStatus');
        const volumeSlider = document.getElementById('editVolumeSlider');
        
        if (statusEl) statusEl.textContent = 'Detenido';
        if (currentTimeEl) currentTimeEl.textContent = '00:00';
        if (totalTimeEl) totalTimeEl.textContent = '00:00';
        if (progressBarFill) progressBarFill.style.width = '0%';
        if (playPauseIcon) playPauseIcon.className = 'fas fa-play';
        if (speedStatus) speedStatus.textContent = '1x';
        if (volumeStatus) volumeStatus.textContent = '100%';
        if (volumeSlider) volumeSlider.value = '100';
    },

    /**
     * Inicializar eventos del reproductor de edición
     */
    initEditAudioPlayerEvents: function() {
        const audioElement = document.getElementById('editAudioElement');
        
        if (!audioElement) return;
        
        // Configurar preload para asegurar que se carguen los metadatos
        audioElement.preload = 'metadata';
        
        // Función para actualizar duración
        const updateDuration = () => {
            const duration = audioElement.duration;
            const totalTimeEl = document.getElementById('editTotalTime');
            
            console.log('📊 Actualizando duración:', duration, 'readyState:', audioElement.readyState);
            
            // Validar que duration sea un número válido y finito
            if (duration && isFinite(duration) && duration > 0) {
                if (totalTimeEl) {
                    totalTimeEl.textContent = this.formatTime(duration);
                }
            } else {
                if (totalTimeEl) {
                    totalTimeEl.textContent = '00:00';
                }
            }
        };
        
        // Evento cuando se carga metadata
        audioElement.addEventListener('loadedmetadata', updateDuration);
        
        // Evento cuando el audio puede reproducirse
        audioElement.addEventListener('canplay', updateDuration);
        
        // Evento cuando se puede reproducir a través de todo el archivo
        audioElement.addEventListener('canplaythrough', updateDuration);
        
        // Evento de actualización de tiempo
        audioElement.addEventListener('timeupdate', () => {
            const currentTime = audioElement.currentTime;
            const duration = audioElement.duration;
            
            // Formatear tiempo actual
            const currentTimeEl = document.getElementById('editCurrentTime');
            if (currentTimeEl) {
                currentTimeEl.textContent = this.formatTime(currentTime);
            }
            
            // Calcular y actualizar progreso solo si la duración es válida
            const progressBarFill = document.getElementById('editAudioProgressBarFill');
            if (progressBarFill) {
                if (duration && isFinite(duration) && duration > 0 && isFinite(currentTime)) {
                    const progress = (currentTime / duration) * 100;
                    progressBarFill.style.width = Math.max(0, Math.min(100, progress)) + '%';
                } else {
                    // Si no hay duración válida, no actualizar el progreso
                    // (mantener el último valor válido o 0%)
                }
            }
        });
        
        // Evento cuando termina la reproducción
        audioElement.addEventListener('ended', () => {
            this.stopEditAudio();
        });
        
        // Evento de error
        audioElement.addEventListener('error', (e) => {
            // Solo mostrar error si el modal está abierto y el src no está vacío
            // Ignorar errores cuando el src está vacío (durante limpieza)
            if (audioElement.src && audioElement.src !== '' && audioElement.src !== window.location.href) {
                console.error('Error en reproductor de edición:', e, audioElement.error);
                const modal = document.getElementById('reportEditorModal');
                if (modal && modal.classList.contains('show')) {
                    this.showError('Error al cargar el archivo de audio');
                }
            }
        });
    },

    /**
     * Inicializar sistema de hotkeys para el reproductor de edición
     */
    initEditAudioHotkeys: async function() {
        // Cargar hotkeys guardados o usar predeterminados
        await this.loadEditAudioHotkeys();
        
        // Agregar listener de teclado con captura para interceptar antes de TinyMCE
        if (!this.editAudioHotkeyListener) {
            this.editAudioHotkeyListener = (e) => this.handleEditAudioHotkey(e);
            // Usar captura (true) para interceptar eventos antes de que TinyMCE los procese
            document.addEventListener('keydown', this.editAudioHotkeyListener, true);
        }
        
        // Configurar TinyMCE para permitir nuestros hotkeys
        this.setupTinyMCEHotkeys();
        
        // Actualizar información de hotkeys
        this.updateEditAudioHotkeysInfo();
    },

    /**
     * Configurar TinyMCE para permitir hotkeys del reproductor
     */
    setupTinyMCEHotkeys: function() {
        // Esta función se llamará cuando TinyMCE esté inicializado
        // Configuramos los hotkeys en el setup del editor
        const self = this;
        
        // Si el editor ya existe, configurarlo ahora
        if (this.state.tinymceEditor && this.state.tinymceEditor.initialized) {
            this.configureTinyMCEHotkeys(this.state.tinymceEditor);
        }
        
        // También configurar cuando se inicialice el editor
        // Esto se manejará en initTinyMCE
    },

    /**
     * Configurar hotkeys en una instancia específica de TinyMCE
     */
    configureTinyMCEHotkeys: function(editor) {
        const self = this;
        
        // Limpiar listener anterior si existe
        if (this.tinymceIframeHotkeyListener) {
            const oldIframe = this.tinymceIframeElement;
            if (oldIframe && oldIframe.contentDocument) {
                oldIframe.contentDocument.removeEventListener('keydown', this.tinymceIframeHotkeyListener, true);
            }
            this.tinymceIframeHotkeyListener = null;
            this.tinymceIframeElement = null;
        }
        
        // Obtener el iframe del editor TinyMCE
        const iframe = editor.getContentAreaContainer().querySelector('iframe');
        if (!iframe) {
            console.warn('No se pudo encontrar el iframe de TinyMCE');
            return;
        }
        
        // Crear el listener y guardar referencia
        const hotkeyListener = function(e) {
            if (self.isHotkeysConfigModalOpen()) {
                return;
            }

            const modal = document.getElementById('reportEditorModal');
            if (!modal || !modal.classList.contains('show')) {
                return;
            }

            const hotkeyCombo = self.buildEditAudioHotkeyCombo(e);
            if (!hotkeyCombo) {
                return;
            }

            const hotkey = self.editAudioHotkeys && self.editAudioHotkeys[hotkeyCombo];
            if (!hotkey) {
                return;
            }

            const audioControls = document.getElementById('editAudioPlayerControls');
            const audioControlsVisible = audioControls && audioControls.style.display !== 'none';
            if (!audioControlsVisible && hotkey.action !== 'toggleTranscriptionPanel') {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            self.executeEditAudioHotkeyAction(hotkey.action, hotkey.description, hotkeyCombo);
            return false;
        };
        
        // Guardar referencias para poder limpiarlas después
        this.tinymceIframeHotkeyListener = hotkeyListener;
        this.tinymceIframeElement = iframe;
        
        // Esperar a que el iframe esté cargado
        const setupHotkeys = () => {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) {
                setTimeout(setupHotkeys, 100);
                return;
            }
            
            // Agregar listener con captura en el documento del iframe
            iframeDoc.addEventListener('keydown', hotkeyListener, true);
        };
        
        // Intentar configurar inmediatamente o esperar a que el iframe esté listo
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
            setupHotkeys();
        } else {
            iframe.addEventListener('load', setupHotkeys);
            // También intentar después de un breve delay por si acaso
            setTimeout(setupHotkeys, 500);
        }
    },

    /**
     * Cargar hotkeys desde la API del servidor o localStorage (fallback)
     */
    loadEditAudioHotkeys: async function() {
        const defaultHotkeys = {
            'Ctrl+Space': { action: 'togglePlayPause', description: 'Play/Pause' },
            'Ctrl+ArrowLeft': { action: 'rewind', description: 'Retroceder 10s' },
            'Ctrl+ArrowRight': { action: 'forward', description: 'Avanzar 10s' },
            'Ctrl+ArrowUp': { action: 'volumeUp', description: 'Subir volumen' },
            'Ctrl+ArrowDown': { action: 'volumeDown', description: 'Bajar volumen' },
            'Ctrl+KeyM': { action: 'toggleMute', description: 'Silenciar/Activar' },
            'Ctrl+KeyS': { action: 'stop', description: 'Detener' },
            'Ctrl+Digit1': { action: 'setSpeed0.5', description: 'Velocidad 0.5x' },
            'Ctrl+Digit2': { action: 'setSpeed1', description: 'Velocidad 1x' },
            'Ctrl+Digit3': { action: 'setSpeed1.5', description: 'Velocidad 1.5x' },
            'Ctrl+Digit4': { action: 'setSpeed2', description: 'Velocidad 2x' },
            'Ctrl+Shift+KeyY': { action: 'toggleTranscriptionPanel', description: 'Mostrar/ocultar transcripción (timestamps)' }
        };
        
        // Intentar cargar desde la API del servidor
        try {
            // Determinar ruta base de la API
            const apiBase = window.location.pathname.includes('/components/') ? '../api/users' : 'api/users';
            const response = await fetch(`${apiBase}/get-hotkeys.php`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.data && Object.keys(result.data).length > 0) {
                    // Usar hotkeys del servidor, combinando con defaults para nuevas acciones
                    this.editAudioHotkeys = { ...defaultHotkeys, ...result.data };
                    this.sanitizeEditAudioHotkeysMap();
                    console.log('✅ Hotkeys cargados desde el servidor');
                    return;
                }
            }
        } catch (error) {
            console.warn('Error cargando hotkeys desde el servidor, usando localStorage:', error);
        }
        
        // Fallback a localStorage si la API falla
        const savedHotkeys = localStorage.getItem('informesManagerAudioHotkeys');
        if (savedHotkeys) {
            try {
                this.editAudioHotkeys = { ...defaultHotkeys, ...JSON.parse(savedHotkeys) };
                console.log('✅ Hotkeys cargados desde localStorage');
            } catch (e) {
                console.warn('Error cargando hotkeys desde localStorage:', e);
                this.editAudioHotkeys = defaultHotkeys;
            }
        } else {
            this.editAudioHotkeys = defaultHotkeys;
        }

        this.sanitizeEditAudioHotkeysMap();
    },

    /**
     * Elimina combinaciones inválidas (p. ej. terminan en Shift sin tecla real) que podían activarse al pulsar solo modificadores.
     */
    sanitizeEditAudioHotkeysMap: function() {
        if (!this.editAudioHotkeys || typeof this.editAudioHotkeys !== 'object') {
            return;
        }
        const modOnly = new Set(['Ctrl', 'Shift', 'Alt', 'Meta']);
        const cleaned = {};
        for (const [combo, meta] of Object.entries(this.editAudioHotkeys)) {
            if (!combo || typeof combo !== 'string') {
                continue;
            }
            const segs = combo.split('+');
            if (segs.length < 2) {
                continue;
            }
            const last = segs[segs.length - 1];
            if (modOnly.has(last)) {
                continue;
            }
            cleaned[combo] = meta;
        }
        this.editAudioHotkeys = cleaned;
    },

    /**
     * Guardar hotkeys en el servidor y localStorage (como backup)
     */
    saveEditAudioHotkeys: async function() {
        // Guardar en localStorage como backup inmediato
        try {
            localStorage.setItem('informesManagerAudioHotkeys', JSON.stringify(this.editAudioHotkeys));
        } catch (e) {
            console.error('Error guardando hotkeys en localStorage:', e);
        }
        
        // Intentar guardar en el servidor
        try {
            // Determinar ruta base de la API
            const apiBase = window.location.pathname.includes('/components/') ? '../api/users' : 'api/users';
            const response = await fetch(`${apiBase}/save-hotkeys.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    hotkeys: this.editAudioHotkeys
                })
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success) {
                    console.log('✅ Hotkeys guardados en el servidor');
                    return true;
                } else {
                    console.warn('Error guardando hotkeys en el servidor:', result.message);
                    return false;
                }
            } else {
                const errorText = await response.text();
                console.warn('Error de respuesta al guardar hotkeys:', response.status, errorText);
                return false;
            }
        } catch (error) {
            console.error('Error guardando hotkeys en el servidor:', error);
            return false;
        }
        
        return false;
    },

    /**
     * Modal de configuración de hotkeys abierto: no interceptar atajos del informe
     * (el listener del documento va en captura y bloqueaba la captura de nuevas teclas).
     */
    isHotkeysConfigModalOpen: function() {
        const el = document.getElementById('hotkeysConfigModal');
        return !!(el && el.classList.contains('show'));
    },

    /**
     * Construye la cadena de combinación (p. ej. Ctrl+Shift+KeyY) de forma estable.
     * Usa e.code para letras/números físicos para que coincida con Ctrl+Shift+Y y no dependa de carácter con Ctrl.
     * @returns {string|null} null si aún no hay tecla principal (solo modificadores).
     */
    buildEditAudioHotkeyCombo: function(e) {
        if (!e) return null;
        if (e.key === 'Control' || e.key === 'Meta' || e.key === 'Alt' || e.key === 'Shift') {
            return null;
        }
        const parts = [];
        if (e.ctrlKey || e.metaKey) parts.push('Ctrl');
        if (e.shiftKey) parts.push('Shift');
        if (e.altKey) parts.push('Alt');

        let main = null;
        if (e.code === 'Space' || e.key === ' ') {
            main = 'Space';
        } else if (e.key.startsWith('Arrow')) {
            main = e.key;
        } else if (e.code && /^Digit[0-9]$/.test(e.code)) {
            main = e.code;
        } else if (e.code && /^Key[A-Z]$/.test(e.code)) {
            main = e.code;
        } else if (e.key.length === 1 && /[0-9]/.test(e.key)) {
            main = 'Digit' + e.key;
        } else if (e.key.length === 1) {
            main = 'Key' + e.key.toUpperCase();
        } else {
            main = e.key;
        }

        if (!main || main === 'Control' || main === 'Shift' || main === 'Alt' || main === 'Meta') {
            return null;
        }
        parts.push(main);
        return parts.join('+');
    },

    /**
     * Manejar tecla presionada
     */
    handleEditAudioHotkey: function(e) {
        if (this.isHotkeysConfigModalOpen()) {
            return;
        }

        const modal = document.getElementById('reportEditorModal');
        if (!modal || !modal.classList.contains('show')) {
            return;
        }

        const hotkeyCombo = this.buildEditAudioHotkeyCombo(e);
        if (!hotkeyCombo) {
            return;
        }

        const hotkey = this.editAudioHotkeys[hotkeyCombo];

        const audioControls = document.getElementById('editAudioPlayerControls');
        const audioControlsVisible = audioControls && audioControls.style.display !== 'none';

        if (!hotkey) {
            const activeElement = document.activeElement;
            if (activeElement && (
                activeElement.tagName === 'INPUT' ||
                activeElement.tagName === 'TEXTAREA'
            )) {
                if (activeElement.id === 'reportTitle') {
                    return;
                }
            }
            return;
        }

        if (!audioControlsVisible && hotkey.action !== 'toggleTranscriptionPanel') {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        this.executeEditAudioHotkeyAction(hotkey.action, hotkey.description, hotkeyCombo);
    },

    /**
     * Ejecutar acción del hotkey
     */
    executeEditAudioHotkeyAction: function(action, description, hotkeyCombo) {
        let toastMessage = '';
        let toastType = 'info';
        
        switch (action) {
            case 'togglePlayPause':
                // Determinar el estado ANTES de toggle para mostrar el mensaje correcto
                const audioElement = document.getElementById('editAudioElement');
                const willBePlaying = audioElement && audioElement.paused; // Si está pausado, se reproducirá
                this.toggleEditAudioPlayPause();
                toastMessage = willBePlaying ? '▶️ Reproduciendo' : '⏸️ Pausado';
                break;
            case 'rewind':
                this.rewindEditAudio(10);
                toastMessage = '⏪ Retrocedido 10s';
                break;
            case 'forward':
                this.forwardEditAudio(10);
                toastMessage = '⏩ Avanzado 10s';
                break;
            case 'volumeUp':
                this.increaseEditAudioVolume(10);
                const volumeStatusUp = document.getElementById('editVolumeStatus');
                const currentVolume = volumeStatusUp ? volumeStatusUp.textContent : '100%';
                toastMessage = `🔊 Volumen: ${currentVolume}`;
                break;
            case 'volumeDown':
                this.decreaseEditAudioVolume(10);
                const volumeStatusDown = document.getElementById('editVolumeStatus');
                const currentVolumeDown = volumeStatusDown ? volumeStatusDown.textContent : '100%';
                toastMessage = `🔉 Volumen: ${currentVolumeDown}`;
                break;
            case 'toggleMute':
                // Determinar el estado ANTES de toggle
                const audioEl = document.getElementById('editAudioElement');
                const willBeMuted = audioEl && !audioEl.muted; // Si no está silenciado, se silenciará
                this.toggleEditAudioMute();
                toastMessage = willBeMuted ? '🔇 Silenciado' : '🔊 Sonido activado';
                break;
            case 'stop':
                this.stopEditAudio();
                toastMessage = '⏹️ Detenido';
                break;
            case 'setSpeed0.5':
                this.setEditAudioSpeed(0.5);
                toastMessage = '⚡ Velocidad: 0.5x';
                break;
            case 'setSpeed1':
                this.setEditAudioSpeed(1);
                toastMessage = '⚡ Velocidad: 1x';
                break;
            case 'setSpeed1.5':
                this.setEditAudioSpeed(1.5);
                toastMessage = '⚡ Velocidad: 1.5x';
                break;
            case 'setSpeed2':
                this.setEditAudioSpeed(2);
                toastMessage = '⚡ Velocidad: 2x';
                break;
            case 'toggleTranscriptionPanel': {
                const tr = this.toggleFloatingTranscriptionPanel();
                if (tr === 'shown') {
                    toastMessage = '📝 Transcripción con timestamps visible';
                } else if (tr === 'hidden') {
                    toastMessage = '📝 Transcripción con timestamps oculta';
                } else if (tr === 'no-audio') {
                    toastMessage = 'Selecciona un audio del informe para la transcripción con timestamps';
                    toastType = 'warning';
                } else if (tr === 'no-transcription') {
                    toastMessage = 'Este audio no tiene transcripción con timestamps';
                    toastType = 'warning';
                }
                break;
            }
        }
        
        // Mostrar toast con la acción ejecutada (usar función específica del reproductor)
        if (toastMessage) {
            // Agregar la combinación de teclas si está disponible
            const fullMessage = hotkeyCombo ? `${toastMessage} (${hotkeyCombo})` : toastMessage;
            this.showAudioPlayerToast(fullMessage, toastType);
        }
    },

    /**
     * Actualizar información de hotkeys en la UI
     */
    updateEditAudioHotkeysInfo: function() {
        const infoElement = document.getElementById('editHotkeysInfoText');
        if (!infoElement) return;
        
        const playPause = this.getHotkeyDescription('togglePlayPause');
        const rewind = this.getHotkeyDescription('rewind');
        const forward = this.getHotkeyDescription('forward');
        const transcription = this.getHotkeyDescription('toggleTranscriptionPanel');

        infoElement.textContent = [playPause, rewind, forward, transcription].filter(Boolean).join(' · ');
    },

    /**
     * Obtener descripción de hotkey por acción
     */
    getHotkeyDescription: function(action) {
        for (const [key, value] of Object.entries(this.editAudioHotkeys || {})) {
            if (value.action === action) {
                return `${key}: ${value.description}`;
            }
        }
        return '';
    },

    /**
     * Mostrar modal de configuración de hotkeys
     */
    showHotkeysConfigModal: async function() {
        // Asegurar que los hotkeys estén cargados antes de mostrar el modal
        if (!this.editAudioHotkeys || Object.keys(this.editAudioHotkeys).length === 0) {
            await this.loadEditAudioHotkeys();
        }
        
        const modal = new bootstrap.Modal(document.getElementById('hotkeysConfigModal'), {
            backdrop: true,
            keyboard: true
        });
        this.renderHotkeysConfigTable();
        modal.show();
        
        // Asegurar que el modal aparezca por encima del modal de editar informe
        const hotkeysModal = document.getElementById('hotkeysConfigModal');
        if (hotkeysModal) {
            hotkeysModal.style.zIndex = '1080';
            // Asegurar que el backdrop también tenga el z-index correcto
            setTimeout(() => {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    // Si hay múltiples backdrops, el último (del modal de hotkeys) debe estar por encima
                    backdrops[backdrops.length - 1].style.zIndex = '1079';
                }
            }, 100);
        }
    },

    /**
     * Renderizar tabla de configuración de hotkeys
     */
    renderHotkeysConfigTable: function() {
        const tbody = document.getElementById('hotkeysConfigTableBody');
        if (!tbody) return;
        
        const actions = [
            { action: 'togglePlayPause', description: 'Play/Pause' },
            { action: 'rewind', description: 'Retroceder 10s' },
            { action: 'forward', description: 'Avanzar 10s' },
            { action: 'volumeUp', description: 'Subir volumen' },
            { action: 'volumeDown', description: 'Bajar volumen' },
            { action: 'toggleMute', description: 'Silenciar/Activar' },
            { action: 'stop', description: 'Detener' },
            { action: 'setSpeed0.5', description: 'Velocidad 0.5x' },
            { action: 'setSpeed1', description: 'Velocidad 1x' },
            { action: 'setSpeed1.5', description: 'Velocidad 1.5x' },
            { action: 'setSpeed2', description: 'Velocidad 2x' },
            { action: 'toggleTranscriptionPanel', description: 'Mostrar/ocultar transcripción (timestamps)' }
        ];
        
        tbody.innerHTML = actions.map(action => {
            const currentHotkey = this.getHotkeyForAction(action.action);
            return `
                <tr>
                    <td>${action.description}</td>
                    <td>
                        <code id="hotkey-${action.action}">${currentHotkey || 'Sin asignar'}</code>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" 
                                onclick="InformesManager.configureHotkey('${action.action}', event)"
                                data-action="${action.action}">
                            <i class="fas fa-edit me-1"></i>Configurar
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    },

    /**
     * Obtener hotkey asignado a una acción
     */
    getHotkeyForAction: function(action) {
        for (const [key, value] of Object.entries(this.editAudioHotkeys || {})) {
            if (value.action === action) {
                return key;
            }
        }
        return null;
    },

    /**
     * Configurar hotkey para una acción
     */
    configureHotkey: async function(action, event) {
        const button = (event || window.event).target.closest('button');
        const codeElement = document.getElementById(`hotkey-${action}`);
        
        if (!button || !codeElement) return;
        
        button.disabled = true;
        codeElement.textContent = 'Presiona la combinación de teclas...';
        codeElement.className = 'text-warning';

        const previousCombo = this.getHotkeyForAction(action);

        const captureHotkey = async (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                document.removeEventListener('keydown', captureHotkey);
                codeElement.textContent = previousCombo || 'Sin asignar';
                codeElement.className = '';
                button.disabled = false;
                return;
            }

            if (e.key === 'Control' || e.key === 'Meta' || e.key === 'Alt' || e.key === 'Shift') {
                return;
            }

            const hotkeyCombo = this.buildEditAudioHotkeyCombo(e);
            if (!hotkeyCombo) {
                return;
            }

            const modifierCount = (e.ctrlKey || e.metaKey ? 1 : 0) + (e.shiftKey ? 1 : 0) + (e.altKey ? 1 : 0);
            if (modifierCount === 0) {
                codeElement.textContent = 'Debe incluir Ctrl, Alt o Shift';
                codeElement.className = 'text-danger';
                setTimeout(() => {
                    codeElement.textContent = 'Presiona la combinación de teclas...';
                    codeElement.className = 'text-warning';
                }, 2000);
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            
            // Verificar si ya está asignado a otra acción
            const existingAction = this.getHotkeyForAction(action);
            if (this.editAudioHotkeys[hotkeyCombo] && this.editAudioHotkeys[hotkeyCombo].action !== action) {
                codeElement.textContent = 'Ya está asignado a otra acción';
                codeElement.className = 'text-danger';
                setTimeout(() => {
                    codeElement.textContent = existingAction || 'Sin asignar';
                    codeElement.className = '';
                    button.disabled = false;
                }, 2000);
                document.removeEventListener('keydown', captureHotkey);
                return;
            }
            
            // Asignar nuevo hotkey
            this.editAudioHotkeys[hotkeyCombo] = {
                action: action,
                description: this.getActionDescription(action)
            };
            
            // Eliminar asignación anterior si existía
            if (existingAction && existingAction !== hotkeyCombo) {
                delete this.editAudioHotkeys[existingAction];
            }
            
            codeElement.textContent = hotkeyCombo;
            codeElement.className = '';
            button.disabled = false;
            
            // Guardar hotkeys y mostrar mensaje
            const saved = await this.saveEditAudioHotkeys();
            if (saved) {
                this.showToast('Hotkey guardado correctamente', 'success');
            } else {
                // Aún se guardó en localStorage, pero no en el servidor
                this.showToast('Hotkey guardado localmente (no se pudo guardar en el servidor)', 'warning');
            }
            this.updateEditAudioHotkeysInfo();
            
            document.removeEventListener('keydown', captureHotkey);
        };
        
        // Usar { once: false } para permitir múltiples intentos hasta que se capture correctamente
        document.addEventListener('keydown', captureHotkey);
    },

    /**
     * Obtener descripción de una acción
     */
    getActionDescription: function(action) {
        const descriptions = {
            'togglePlayPause': 'Play/Pause',
            'rewind': 'Retroceder 10s',
            'forward': 'Avanzar 10s',
            'volumeUp': 'Subir volumen',
            'volumeDown': 'Bajar volumen',
            'toggleMute': 'Silenciar/Activar',
            'stop': 'Detener',
            'setSpeed0.5': 'Velocidad 0.5x',
            'setSpeed1': 'Velocidad 1x',
            'setSpeed1.5': 'Velocidad 1.5x',
            'setSpeed2': 'Velocidad 2x',
            'toggleTranscriptionPanel': 'Mostrar/ocultar transcripción (timestamps)'
        };
        return descriptions[action] || action;
    },

    /**
     * Restaurar hotkeys predeterminados
     */
    resetHotkeysToDefault: async function() {
        this.showConfirmModal(
            'Restaurar atajos predeterminados',
            '¿Restaurar los atajos de teclado predeterminados? Esto eliminará tus configuraciones personalizadas.',
            async () => {
                // Eliminar de localStorage
                localStorage.removeItem('informesManagerAudioHotkeys');
                
                // Cargar defaults
                await this.loadEditAudioHotkeys();
                
                // Guardar defaults en el servidor
                await this.saveEditAudioHotkeys();
                
                this.renderHotkeysConfigTable();
                this.updateEditAudioHotkeysInfo();
                this.showToast('Hotkeys restaurados a los valores predeterminados', 'success');
            }
        );
    },

    /**
     * Limpiar listener de hotkeys
     */
    cleanupEditAudioHotkeys: function() {
        // Limpiar listener del documento principal
        if (this.editAudioHotkeyListener) {
            document.removeEventListener('keydown', this.editAudioHotkeyListener, true);
            this.editAudioHotkeyListener = null;
        }
        
        // Limpiar listener del iframe de TinyMCE
        if (this.tinymceIframeHotkeyListener && this.tinymceIframeElement) {
            try {
                const iframeDoc = this.tinymceIframeElement.contentDocument || this.tinymceIframeElement.contentWindow.document;
                if (iframeDoc) {
                    iframeDoc.removeEventListener('keydown', this.tinymceIframeHotkeyListener, true);
                }
            } catch (e) {
                // Ignorar errores de cross-origin si el iframe ya no es accesible
                console.warn('No se pudo limpiar listener del iframe de TinyMCE:', e);
            }
            this.tinymceIframeHotkeyListener = null;
            this.tinymceIframeElement = null;
        }
    },

    /**
     * Mostrar información del usuario en modal
     */
    showUserInfo: function(informe) {
        try {
            // Llenar datos del modal
            document.getElementById('userInfoNombre').textContent = informe.usuario_nombre || '-';
            document.getElementById('userInfoApellido').textContent = informe.usuario_apellido || '-';
            document.getElementById('userInfoEmail').textContent = informe.usuario_email || '-';
            document.getElementById('userInfoTelefono').textContent = informe.usuario_telefono || '-';
            document.getElementById('userInfoMatricula').textContent = informe.usuario_matricula || '-';
            document.getElementById('userInfoEspecialidad').textContent = informe.usuario_especialidad || '-';
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('userInfoModal'));
            modal.show();
        } catch (error) {
            console.error('Error mostrando información del usuario:', error);
            this.showError('Error al cargar la información del usuario');
        }
    },

    /**
     * Cargar flags individuales por informe
     */
    loadIndividualReportFlags: async function(informes) {
        try {
            console.log('🔍 [loadIndividualReportFlags] Iniciando carga de flags para', informes.length, 'informes');
            const token = this.getSessionToken();
            const baseUrl = this.config.apiBaseUrl.replace('/informes', '');
            
            // Cargar flags para cada informe individual
            // IMPORTANTE: La API ahora devuelve el flag actual del informe sin filtrar por user_id
            // Esto permite que cualquier usuario con permiso marcar_incompletos vea el estado del flag
            const flagPromises = informes.map(async (informe) => {
                const studyId = informe.study_id || informe.study_instance_uid || informe.estudio_id;
                const informeId = informe.id;
                
                if (!studyId || !informeId) {
                    console.warn('⚠️ [loadIndividualReportFlags] Informe sin studyId o informeId:', informe);
                    return;
                }
                
                try {
                    // IMPORTANTE: No incluir user_id en la consulta para obtener el flag actual
                    // independientemente de quién lo creó
                    const url = `${baseUrl}/study-flags.php?study_id=${encodeURIComponent(studyId)}&informe_id=${encodeURIComponent(informeId)}`;
                    console.log('🔍 [loadIndividualReportFlags] Consultando API:', url);
                    
                    const response = await fetch(url, {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                        },
                        credentials: 'include'
                    });
                    
                    if (response.ok) {
                        const result = await response.json();
                        console.log('🔍 [loadIndividualReportFlags] Respuesta API para informe', informeId, ':', result);
                        
                        if (result.success && result.data) {
                            // Guardar flag usando clave compuesta
                            // IMPORTANTE: Guardar siempre, incluso si informes_incompletos es false
                            // Esto permite que el botón y el modal muestren el estado correcto
                            const compositeKey = `${studyId}_informe_${informeId}`;
                            this.state.studyFlags[compositeKey] = {
                                informes_incompletos: Boolean(result.data.informes_incompletos),
                                nota: result.data.nota || null,
                                prioridad: result.data.prioridad || 'normal',
                                informeId: informeId,
                                studyId: studyId
                            };
                            console.log('✅ [loadIndividualReportFlags] Flag guardado con clave:', compositeKey, 'valor:', this.state.studyFlags[compositeKey]);
                        } else {
                            // Si no hay flag, crear uno por defecto (no marcado)
                            const compositeKey = `${studyId}_informe_${informeId}`;
                            this.state.studyFlags[compositeKey] = {
                                informes_incompletos: false,
                                nota: null,
                                prioridad: 'normal',
                                informeId: informeId,
                                studyId: studyId
                            };
                            console.log('⚠️ [loadIndividualReportFlags] No se encontró flag en API para informe:', informeId, '- creando flag por defecto');
                        }
                    } else {
                        console.warn('⚠️ [loadIndividualReportFlags] Error HTTP', response.status, 'para informe:', informeId);
                    }
                } catch (error) {
                    console.warn('⚠️ [loadIndividualReportFlags] Error cargando flag para informe:', informeId, error);
                }
            });
            
            await Promise.all(flagPromises);
            console.log('✅ [loadIndividualReportFlags] Carga completada. Total flags en estado:', Object.keys(this.state.studyFlags).length);
            console.log('✅ [loadIndividualReportFlags] Claves de flags:', Object.keys(this.state.studyFlags));
        } catch (error) {
            console.error('Error cargando flags individuales:', error);
        }
    },

    /**
     * Cargar flags de informes incompletos para filtrado (sin actualizar botones)
     */
    loadStudyFlagsForFilter: async function(studyIds, studyIdMap = {}) {
        console.log('🔍 [loadStudyFlagsForFilter] INICIANDO con studyIds:', studyIds, 'studyIdMap:', studyIdMap);
        try {
            if (!studyIds || studyIds.length === 0) {
                console.warn('⚠️ [loadStudyFlagsForFilter] No hay studyIds para cargar');
                return;
            }
            
            const token = this.getSessionToken();
            const baseUrl = this.config.apiBaseUrl.replace('/informes', '');
            
            // Usar POST si hay muchos IDs para evitar URLs demasiado largas
            let response;
            if (studyIds.length > 50) {
                // Usar POST para evitar límites de longitud de URL
                response = await fetch(`${baseUrl}/study-flags/batch.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    },
                    credentials: 'include',
                    body: JSON.stringify({ study_ids: studyIds })
                });
            } else {
                // Usar GET para pocos IDs
                const url = `${baseUrl}/study-flags/batch.php?study_ids=${encodeURIComponent(studyIds.join(','))}`;
                console.log('🔍 [loadStudyFlagsForFilter] URL:', url);
                response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    },
                    credentials: 'include'
                });
            }
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            console.log('📋 [loadStudyFlagsForFilter] Respuesta del API:', {
                success: result.success,
                hasData: !!result.data,
                dataType: Array.isArray(result.data) ? 'array' : typeof result.data,
                dataKeys: result.data ? Object.keys(result.data) : []
            });
            
            if (result.success && result.data) {
                const flagsData = Array.isArray(result.data) ? {} : result.data;
                
                console.log('📋 [loadStudyFlagsForFilter] Flags recibidos del API:', flagsData);
                console.log('📋 [loadStudyFlagsForFilter] Study IDs buscados:', studyIds);
                
                // Normalizar flags: almacenar con TODAS las posibles claves para cada estudio
                // El API devuelve flags bajo diferentes claves (study_id, orthanc_id, study_instance_uid)
                // Necesitamos guardar cada flag bajo TODAS las claves posibles para encontrarlo sin importar cuál se use
                const normalizedFlags = {};
                
                // Primero, guardar los flags con sus claves originales
                Object.keys(flagsData).forEach(key => {
                    const flag = flagsData[key];
                    normalizedFlags[key] = flag;
                    console.log('📋 [loadStudyFlagsForFilter] Flag guardado con clave:', key, 'flag:', flag);
                });
                
                // Luego, para cada studyId que pasamos, buscar si hay un flag y guardarlo también bajo ese ID
                // Y también guardarlo bajo TODOS los IDs alternativos del informe
                studyIds.forEach(studyId => {
                    // Buscar el flag en todas las claves posibles
                    let foundFlag = null;
                    let foundKey = null;
                    
                    for (const key in flagsData) {
                        // Si la clave coincide con el studyId, usar ese flag
                        if (key === studyId) {
                            foundFlag = flagsData[key];
                            foundKey = key;
                            console.log('📋 [loadStudyFlagsForFilter] Flag encontrado para studyId:', studyId, 'con clave:', key);
                            break;
                        }
                    }
                    
                    // Si no se encontró con el studyId exacto, buscar en todas las claves
                    // Comparar con todos los IDs alternativos del informe
                    if (!foundFlag && studyIdMap[studyId]) {
                        const altIds = studyIdMap[studyId];
                        const allPossibleIds = [
                            studyId,
                            altIds.study_id,
                            altIds.study_instance_uid,
                            altIds.orthanc_id,
                            altIds.estudio_id
                        ].filter(id => id);
                        
                        // Buscar el flag usando cualquiera de los IDs posibles
                        for (const possibleId of allPossibleIds) {
                            if (flagsData[possibleId]) {
                                foundFlag = flagsData[possibleId];
                                foundKey = possibleId;
                                console.log('📋 [loadStudyFlagsForFilter] Flag encontrado con ID alternativo:', possibleId, 'para studyId:', studyId);
                                break;
                            }
                        }
                    }
                    
                    // Si aún no se encontró, tomar el primer flag disponible (por si acaso)
                    if (!foundFlag && Object.keys(flagsData).length > 0) {
                        const firstKey = Object.keys(flagsData)[0];
                        foundFlag = flagsData[firstKey];
                        foundKey = firstKey;
                        console.log('📋 [loadStudyFlagsForFilter] Usando primer flag disponible:', firstKey, 'para studyId:', studyId);
                    }
                    
                    // Si encontramos un flag, guardarlo bajo el studyId y TODOS sus IDs alternativos
                    if (foundFlag) {
                        // Guardar bajo el studyId principal
                        if (!normalizedFlags[studyId]) {
                            normalizedFlags[studyId] = foundFlag;
                            console.log('📋 [loadStudyFlagsForFilter] Flag guardado bajo studyId:', studyId);
                        }
                        
                        // Guardar también bajo todos los IDs alternativos del informe
                        if (studyIdMap[studyId]) {
                            const altIds = studyIdMap[studyId];
                            Object.values(altIds).forEach(altId => {
                                if (altId && altId !== studyId && !normalizedFlags[altId]) {
                                    normalizedFlags[altId] = foundFlag;
                                    console.log('📋 [loadStudyFlagsForFilter] Flag guardado también bajo ID alternativo:', altId);
                                }
                            });
                        }
                    } else {
                        console.log('⚠️ [loadStudyFlagsForFilter] No se encontró flag para studyId:', studyId);
                    }
                });
                
                console.log('📋 [loadStudyFlagsForFilter] Flags normalizados:', Object.keys(normalizedFlags).length, 'claves');
                console.log('📋 [loadStudyFlagsForFilter] Claves normalizadas:', Object.keys(normalizedFlags));
                
                // Guardar flags en el estado para uso en filtros
                const previousFlagsCount = Object.keys(this.state.studyFlags || {}).length;
                this.state.studyFlags = { ...this.state.studyFlags, ...normalizedFlags };
                const newFlagsCount = Object.keys(this.state.studyFlags).length;
                
                console.log('📋 [loadStudyFlagsForFilter] Flags guardados en estado:', {
                    previousCount: previousFlagsCount,
                    newCount: newFlagsCount,
                    added: newFlagsCount - previousFlagsCount,
                    allKeys: Object.keys(this.state.studyFlags)
                });
            } else {
                console.warn('⚠️ [loadStudyFlagsForFilter] No se recibieron flags del API:', result);
            }
        } catch (error) {
            console.error('Error cargando flags para filtro:', error);
        }
    },

    /**
     * Cargar flags de informes incompletos para los estudios mostrados
     */
    loadStudyFlags: async function() {
        try {
            // Obtener todos los study_ids únicos de las filas renderizadas
            const studyIds = new Set();
            const rows = document.querySelectorAll('[data-study-id]');
            rows.forEach(row => {
                const studyId = row.getAttribute('data-study-id');
                if (studyId && studyId !== 'sin-estudio') {
                    studyIds.add(studyId);
                }
            });
            
            // También buscar en los botones de incompletos
            const buttons = document.querySelectorAll('[id^="btnIncompletos-"]');
            buttons.forEach(btn => {
                const studyId = btn.getAttribute('data-study-id');
                if (studyId) {
                    studyIds.add(studyId);
                }
            });
            
            if (studyIds.size === 0) {
                return;
            }
            
            const token = this.getSessionToken();
            const baseUrl = this.config.apiBaseUrl.replace('/informes', '');
            const studyIdsArray = Array.from(studyIds);
            
            // Usar POST si hay muchos IDs para evitar URLs demasiado largas
            let response;
            if (studyIdsArray.length > 50) {
                // Usar POST para evitar límites de longitud de URL
                response = await fetch(`${baseUrl}/study-flags/batch.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    },
                    credentials: 'include',
                    body: JSON.stringify({ study_ids: studyIdsArray })
                });
            } else {
                // Usar GET para pocos IDs
                const url = `${baseUrl}/study-flags/batch.php?study_ids=${encodeURIComponent(studyIdsArray.join(','))}`;
                response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    },
                    credentials: 'include'
                });
            }
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success && result.data) {
                const flagsData = Array.isArray(result.data) ? {} : result.data;
                
                // Normalizar flags: guardar con todas las claves posibles
                const normalizedFlags = {};
                Object.keys(flagsData).forEach(key => {
                    normalizedFlags[key] = flagsData[key];
                });
                
                // Para cada studyId, buscar si hay un flag y guardarlo también bajo ese ID
                studyIdsArray.forEach(studyId => {
                    // Buscar el flag en todas las claves posibles
                    let foundFlag = null;
                    for (const key in flagsData) {
                        if (key === studyId) {
                            foundFlag = flagsData[key];
                            break;
                        }
                    }
                    
                    // Si encontramos un flag, guardarlo también bajo el studyId original
                    if (foundFlag && !normalizedFlags[studyId]) {
                        normalizedFlags[studyId] = foundFlag;
                    }
                });
                
                // Guardar flags en el estado para uso en filtros
                // IMPORTANTE: Solo guardar flags que realmente tienen informes_incompletos activo
                // para evitar que se apliquen animaciones incorrectamente
                // ADEMÁS: NO guardar flags de solo study_id si ya existen flags con informe_id para ese estudio
                const validFlags = {};
                Object.keys(normalizedFlags).forEach(key => {
                    const flag = normalizedFlags[key];
                    if (flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1 || flag.informes_incompletos === '1')) {
                        // Si la clave contiene "_informe_", es un flag de informe individual - siempre guardarlo
                        if (key.includes('_informe_')) {
                            validFlags[key] = flag;
                        } else {
                            // Si es un flag de solo study_id, verificar que no existan flags individuales para ese estudio
                            const hasIndividualFlags = Object.keys(normalizedFlags).some(k => k.startsWith(key + '_informe_'));
                            if (!hasIndividualFlags) {
                                // Solo guardar si no hay flags individuales para este estudio
                                validFlags[key] = flag;
                            } else {
                                console.log('🚫 [loadStudyFlagsForFilter] Ignorando flag de estudio general porque existen flags individuales:', key);
                            }
                        }
                    }
                });
                
                // Actualizar estado: mantener flags existentes que no están en la respuesta actual,
                // pero actualizar/agregar solo los flags válidos de la respuesta
                Object.keys(validFlags).forEach(key => {
                    this.state.studyFlags[key] = validFlags[key];
                });
                
                // Eliminar flags que ya no existen en la respuesta (si el estudio está en la lista)
                studyIdsArray.forEach(studyId => {
                    if (!validFlags[studyId] && !normalizedFlags[studyId]) {
                        // Si el estudio está en la lista pero no tiene flag válido, eliminar del estado
                        delete this.state.studyFlags[studyId];
                    }
                });
                
                console.log('🔄 Actualizando botones después de cargar flags. Total flags:', Object.keys(normalizedFlags).length);
                console.log('🔄 Flags normalizados:', normalizedFlags);
                console.log('🔄 Study IDs a buscar:', studyIdsArray);
                
                // Actualizar botones según flags
                // IMPORTANTE: Buscar TODOS los botones y usar su data-study-id específico
                const allButtons = document.querySelectorAll('[id^="btnIncompletos-"]');
                allButtons.forEach(btn => {
                    // Obtener el study_id del botón desde su atributo data-study-id
                    const btnStudyId = btn.getAttribute('data-study-id');
                    const btnInformeId = btn.getAttribute('data-informe-id');
                    
                    if (!btnStudyId) {
                        console.warn('⚠️ Botón sin data-study-id:', btn.id);
                        return;
                    }
                    
                    // Buscar flag usando clave compuesta primero (si hay informeId)
                    let flag = null;
                    if (btnInformeId) {
                        const compositeKey = `${btnStudyId}_informe_${btnInformeId}`;
                        flag = this.state.studyFlags[compositeKey];
                    }
                    
                    // Si no se encontró con clave compuesta, buscar por study_id solamente (compatibilidad)
                    if (!flag) {
                        flag = this.state.studyFlags[btnStudyId] || flagsData[btnStudyId] || normalizedFlags[btnStudyId];
                    }
                    
                    // Limpiar clases previas SIEMPRE
                    btn.classList.remove('btn-warning', 'btn-outline-warning', 'btn-informes-incompletos');
                    
                    // Solo aplicar animación si el flag existe Y tiene informes_incompletos activo
                    const hasIncompletos = flag && (
                        flag.informes_incompletos === true || 
                        flag.informes_incompletos === 1 || 
                        flag.informes_incompletos === '1'
                    );
                    
                    if (hasIncompletos) {
                        // Si está marcado, usar btn-warning sólido con animación
                        btn.classList.add('btn-warning', 'btn-informes-incompletos');
                        if (flag.nota) {
                            btn.setAttribute('title', `Informes incompletos: ${flag.nota}`);
                        } else {
                            btn.setAttribute('title', 'Informes incompletos (marcado)');
                        }
                        console.log('✅ Botón actualizado con destello para:', btnStudyId, 'informeId:', btnInformeId, {
                            btnId: btn.id,
                            flag: flag,
                            informes_incompletos: flag.informes_incompletos,
                            classes: btn.className
                        });
                    } else {
                        // Si no está marcado, usar outline-warning SIN animación
                        btn.classList.add('btn-outline-warning');
                        btn.setAttribute('title', 'Marcar/Desmarcar informes incompletos');
                        console.log('ℹ️ Botón sin destello para:', btnStudyId, 'informeId:', btnInformeId, 'btnId:', btn.id, 'flag encontrado:', flag ? 'sí' : 'no', 'informes_incompletos:', flag ? flag.informes_incompletos : 'N/A');
                    }
                });
            }
        } catch (error) {
            console.error('Error cargando flags de estudios:', error);
        }
    },

    /**
     * Actualizar el estado visual del botón del grupo basándose en los informes del grupo
     */
    updateGroupButtonState: function(studyId, groupBtnId) {
        console.log('🔍 [updateGroupButtonState] Actualizando botón del grupo:', groupBtnId, 'studyId:', studyId);
        
        const groupButton = document.getElementById(groupBtnId);
        if (!groupButton) {
            // Silencioso: es normal que no exista en vista individual
            // Solo loguear en modo debug si es necesario
            return;
        }
        
        console.log('🔍 [updateGroupButtonState] Todas las claves en studyFlags:', Object.keys(this.state.studyFlags));
        
        // Buscar TODOS los flags que correspondan a este estudio
        let hasAnyIncomplete = false;
        let incompleteNotes = [];
        
        for (const key in this.state.studyFlags) {
            // Verificar si esta clave corresponde a este estudio
            if (key.startsWith(studyId + '_informe_')) {
                const flag = this.state.studyFlags[key];
                console.log('🔍 [updateGroupButtonState] Revisando flag:', key, 'flag:', flag);
                
                if (flag && (flag.informes_incompletos === true || flag.informes_incompletos === 1)) {
                    hasAnyIncomplete = true;
                    
                    // Extraer el informeId de la clave
                    const informeIdMatch = key.match(/_informe_(\d+)$/);
                    const informeId = informeIdMatch ? informeIdMatch[1] : null;
                    
                    if (flag.nota && informeId) {
                        incompleteNotes.push(`#${informeId}: ${flag.nota}`);
                    }
                }
            }
        }
        
        console.log('🔍 [updateGroupButtonState] hasAnyIncomplete:', hasAnyIncomplete, 'notes:', incompleteNotes);
        
        // Actualizar clases y tooltip del botón del grupo
        groupButton.classList.remove('btn-warning', 'btn-outline-warning', 'btn-informes-incompletos');
        
        if (hasAnyIncomplete) {
            groupButton.classList.add('btn-warning', 'btn-informes-incompletos');
            const title = incompleteNotes.length > 0 
                ? `Informes incompletos:\n${incompleteNotes.join('\n')}` 
                : 'Informes incompletos (uno o más informes marcados)';
            groupButton.setAttribute('title', title);
            console.log('✅ [updateGroupButtonState] Botón actualizado con destello');
        } else {
            groupButton.classList.add('btn-outline-warning');
            groupButton.setAttribute('title', 'Marcar/Desmarcar informes incompletos');
            console.log('✅ [updateGroupButtonState] Botón actualizado sin destello');
        }
    },
    
    /**
     * Abrir modal para marcar/desmarcar informes incompletos
     */
    toggleInformesIncompletos: async function(studyId, patientName, informeId = null, btnId = null) {
        try {
            // Verificar permiso
            if (!this.state.canMarcarIncompletos) {
                this.showToast('❌ No tienes permiso para marcar informes como incompletos', 'error');
                return;
            }
            
            const token = this.getSessionToken();
            const baseUrl = this.config.apiBaseUrl.replace('/informes', '');
            
            // Obtener flag actual
            // IMPORTANTE: Si hay informeId, SOLO buscar con clave compuesta específica
            // NO usar fallback a study_id porque eso puede traer el flag de otro informe del mismo estudio
            let currentFlag = null;
            
            if (informeId) {
                // Buscar flag usando clave compuesta en el estado local
                const compositeKey = `${studyId}_informe_${informeId}`;
                currentFlag = this.state.studyFlags[compositeKey];
                console.log('🔍 [toggleInformesIncompletos] Buscando flag local con clave compuesta:', compositeKey);
                console.log('🔍 [toggleInformesIncompletos] Flag encontrado:', currentFlag);
                console.log('🔍 [toggleInformesIncompletos] Todas las claves en studyFlags:', Object.keys(this.state.studyFlags));
                console.log('🔍 [toggleInformesIncompletos] Todos los flags:', JSON.stringify(this.state.studyFlags, null, 2));
                
                // Si no se encontró en el estado local, consultar la API con informe_id
                if (!currentFlag) {
                    try {
                        const url = `${baseUrl}/study-flags.php?study_id=${encodeURIComponent(studyId)}&informe_id=${encodeURIComponent(informeId)}`;
                        console.log('🔍 [toggleInformesIncompletos] Consultando API con informe_id:', url);
                        
                        const response = await fetch(url, {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                                ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                            },
                            credentials: 'include'
                        });
                        
                        if (response.ok) {
                            const result = await response.json();
                            if (result.success && result.data) {
                                currentFlag = result.data;
                                console.log('🔍 [toggleInformesIncompletos] Flag obtenido de API:', currentFlag, 'para informeId:', informeId);
                                
                                // Guardar en el estado local para futuras referencias
                                const compositeKey = `${studyId}_informe_${informeId}`;
                                this.state.studyFlags[compositeKey] = {
                                    informes_incompletos: currentFlag.informes_incompletos || false,
                                    nota: currentFlag.nota || null,
                                    prioridad: currentFlag.prioridad || 'normal',
                                    informeId: informeId,
                                    studyId: studyId
                                };
                                console.log('✅ [toggleInformesIncompletos] Flag guardado en estado local con clave:', compositeKey);
                            } else {
                                console.log('🔍 [toggleInformesIncompletos] No se encontró flag en API para informeId:', informeId);
                            }
                        }
                    } catch (e) {
                        console.error('Error obteniendo flag:', e);
                    }
                }
            } else {
                // Si NO hay informeId, buscar por study_id solamente (comportamiento para informes únicos)
                currentFlag = this.state.studyFlags[studyId];
                console.log('🔍 [toggleInformesIncompletos] Buscando flag local con study_id:', studyId, 'flag encontrado:', currentFlag);
                
                // Si no se encontró, consultar la API sin informe_id
                if (!currentFlag) {
                    try {
                        const url = `${baseUrl}/study-flags.php?study_id=${encodeURIComponent(studyId)}`;
                        console.log('🔍 [toggleInformesIncompletos] Consultando API sin informe_id:', url);
                        
                        const response = await fetch(url, {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                                ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                            },
                            credentials: 'include'
                        });
                        
                        if (response.ok) {
                            const result = await response.json();
                            if (result.success && result.data) {
                                currentFlag = result.data;
                                console.log('🔍 [toggleInformesIncompletos] Flag obtenido de API:', currentFlag);
                            }
                        }
                    } catch (e) {
                        console.error('Error obteniendo flag:', e);
                    }
                }
            }
            
            const isCurrentlyMarked = currentFlag ? Boolean(currentFlag.informes_incompletos) : false;
            const currentNota = currentFlag && currentFlag.nota ? currentFlag.nota : '';
            
            console.log('🔍 [toggleInformesIncompletos] Estado actual del flag:', {
                studyId: studyId,
                informeId: informeId,
                isCurrentlyMarked: isCurrentlyMarked,
                currentNota: currentNota,
                flag: currentFlag
            });
            
            // Mostrar modal
            console.log('🔍 [toggleInformesIncompletos] Mostrando modal para:', studyId, 'informeId:', informeId);
            const confirmed = await this.showInformesIncompletosModal(studyId, patientName, isCurrentlyMarked, currentNota, informeId);
            
            console.log('🔍 [toggleInformesIncompletos] Respuesta del modal:', confirmed);
            
            if (confirmed === null || confirmed === undefined) {
                console.log('❌ [toggleInformesIncompletos] Usuario canceló o modal retornó null');
                return; // Usuario canceló
            }
            
            const { informes_incompletos, nota } = confirmed;
            console.log('🔍 [toggleInformesIncompletos] Datos a guardar:', { informes_incompletos, nota });
            
            // Detectar IDs automáticamente
            let orthancId = null;
            let studyInstanceUID = null;
            
            if (studyId.includes('-')) {
                orthancId = studyId;
            } else if (studyId.includes('.')) {
                studyInstanceUID = studyId;
            } else {
                // Buscar en los estudios cargados si están disponibles
                orthancId = studyId;
            }
            
            // Preparar datos del flag
            // IMPORTANTE: Incluir informe_id si está disponible para guardar flags individuales por informe
            const flagData = {
                study_id: studyId,
                orthanc_id: orthancId,
                study_instance_uid: studyInstanceUID,
                informes_incompletos: informes_incompletos,
                nota: nota || null
            };
            
            // Si hay informeId, agregarlo para guardar flag específico del informe
            if (informeId) {
                flagData.informe_id = parseInt(informeId);
                console.log('🔍 [toggleInformesIncompletos] Incluyendo informe_id en flagData:', flagData.informe_id);
            }
            
            console.log('🔍 [toggleInformesIncompletos] Enviando datos a API:', flagData);
            console.log('🔍 [toggleInformesIncompletos] URL:', `${baseUrl}/study-flags.php`);
            
            const response = await fetch(`${baseUrl}/study-flags.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                credentials: 'include',
                body: JSON.stringify(flagData)
            });
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            console.log('🔍 [toggleInformesIncompletos] Respuesta del servidor:', result);
            
            if (result.success) {
                this.showToast(
                    informes_incompletos 
                        ? '✅ Estudio marcado como informes incompletos' 
                        : '✅ Flag de informes incompletos removido',
                    'success'
                );
                
                // Actualizar estado de flags usando una clave única por informe
                // Si hay informeId, usar clave compuesta para que cada informe tenga su propio flag
                // Si no hay informeId, usar solo studyId (comportamiento anterior)
                const flagKey = informeId ? `${studyId}_informe_${informeId}` : studyId;
                
                if (informes_incompletos) {
                    this.state.studyFlags[flagKey] = {
                        informes_incompletos: true,
                        nota: nota || null,
                        prioridad: 'normal',
                        informeId: informeId, // Guardar el informeId para referencia
                        studyId: studyId // Guardar el studyId para referencia
                    };
                    console.log('✅ [toggleInformesIncompletos] Estado actualizado - marcado como incompleto:', flagKey);
                    console.log('✅ [toggleInformesIncompletos] Datos guardados:', JSON.stringify(this.state.studyFlags[flagKey], null, 2));
                    console.log('✅ [toggleInformesIncompletos] Todas las claves después de guardar:', Object.keys(this.state.studyFlags));
                } else {
                    delete this.state.studyFlags[flagKey];
                    console.log('✅ [toggleInformesIncompletos] Estado actualizado - removido flag:', flagKey, 'informeId:', informeId);
                }
                
                // Actualizar SOLO el botón específico que se clickeó
                let targetButton = null;
                
                // PRIORIDAD 1: Si se proporcionó informeId, buscar por data-informe-id (más específico)
                if (informeId) {
                    // Buscar primero por ID del botón si se proporcionó
                    if (btnId) {
                        targetButton = document.getElementById(btnId);
                        if (targetButton) {
                            console.log('🔍 [toggleInformesIncompletos] Botón encontrado por ID:', btnId);
                        }
                    }
                    // Si no se encontró por ID, buscar por data-informe-id
                    if (!targetButton) {
                        targetButton = document.querySelector(`button[data-informe-id="${informeId}"][data-study-id="${studyId}"]`);
                        console.log('🔍 [toggleInformesIncompletos] Buscando botón por data-informe-id:', informeId, 'data-study-id:', studyId, 'encontrado:', targetButton ? 'sí' : 'no');
                    }
                }
                
                // PRIORIDAD 2: Si no se encontró y se proporcionó btnId, buscar por ID
                if (!targetButton && btnId) {
                    targetButton = document.getElementById(btnId);
                    console.log('🔍 [toggleInformesIncompletos] Buscando botón por ID:', btnId, 'encontrado:', targetButton ? 'sí' : 'no');
                }
                
                // PRIORIDAD 3: Si aún no se encontró, buscar el primer botón con este study_id (fallback solo para informes sin agrupar)
                if (!targetButton && !informeId) {
                    const buttons = document.querySelectorAll(`button[data-study-id="${studyId}"]`);
                    if (buttons.length === 1) {
                        targetButton = buttons[0];
                        console.log('🔍 [toggleInformesIncompletos] Usando único botón encontrado para study_id:', studyId);
                    } else if (buttons.length > 1) {
                        console.warn('⚠️ [toggleInformesIncompletos] Múltiples botones encontrados para study_id:', studyId, 'sin informeId, usando el primero');
                        targetButton = buttons[0];
                    }
                }
                
                if (targetButton) {
                    // Limpiar todas las clases relacionadas
                    targetButton.classList.remove('btn-warning', 'btn-outline-warning', 'btn-informes-incompletos');
                    
                    if (informes_incompletos) {
                        // Si está marcado, usar btn-warning sólido con animación
                        targetButton.classList.add('btn-warning', 'btn-informes-incompletos');
                        if (nota) {
                            targetButton.setAttribute('title', `Informes incompletos: ${nota}`);
                        } else {
                            targetButton.setAttribute('title', 'Informes incompletos (marcado)');
                        }
                        console.log('✅ Botón actualizado inmediatamente con destello para:', {
                            studyId: studyId,
                            informeId: informeId,
                            btnId: targetButton.id,
                            classes: targetButton.className,
                            hasWarning: targetButton.classList.contains('btn-warning'),
                            hasIncompletos: targetButton.classList.contains('btn-informes-incompletos'),
                            computedStyle: window.getComputedStyle(targetButton).animation
                        });
                    } else {
                        // Si no está marcado, usar outline-warning
                        targetButton.classList.add('btn-outline-warning');
                        targetButton.setAttribute('title', 'Marcar/Desmarcar informes incompletos');
                        console.log('✅ Botón actualizado - removido flag para:', {
                            studyId: studyId,
                            informeId: informeId,
                            btnId: targetButton.id
                        });
                    }
                } else {
                    console.warn('⚠️ [toggleInformesIncompletos] No se encontró el botón específico para:', {
                        studyId: studyId,
                        informeId: informeId,
                        btnId: btnId
                    });
                }
                
                // Actualizar también el botón de "Enviar a PACS" SOLO del informe específico que se marcó
                // Solo si se proporcionó informeId (para informes anidados)
                if (informeId) {
                    // Buscar la fila que contiene el botón de incompletos con este informeId específico
                    const targetIncompletosBtn = document.querySelector(`[data-informe-id="${informeId}"][data-study-id="${studyId}"]`);
                    if (targetIncompletosBtn) {
                        // Encontrar la fila (tr) que contiene este botón
                        const targetRow = targetIncompletosBtn.closest('tr');
                        if (targetRow) {
                            // Buscar el botón de "Enviar a PACS" específico de este informe (usando el informeId en el onclick)
                            const sendToPacsButton = targetRow.querySelector(`button[onclick*="sendToPacs(${informeId})"]`);
                            if (sendToPacsButton) {
                                // Verificar si el botón es de "Enviar a PACS" (tiene el icono de cloud-upload-alt)
                                const icon = sendToPacsButton.querySelector('i.fa-cloud-upload-alt');
                                if (icon) {
                                    if (informes_incompletos) {
                                        // Si está incompleto, deshabilitar el botón
                                        sendToPacsButton.disabled = true;
                                        sendToPacsButton.classList.remove('btn-outline-success');
                                        sendToPacsButton.classList.add('btn-outline-secondary');
                                        sendToPacsButton.setAttribute('title', 'No se puede enviar a PACS: informe marcado como incompleto');
                                        console.log('✅ Botón "Enviar a PACS" deshabilitado para informe incompleto:', informeId, 'studyId:', studyId);
                                    } else {
                                        // Si no está incompleto, verificar si el informe está finalizado para habilitarlo
                                        // Buscar el badge de estado en la fila
                                        const estadoBadges = targetRow.querySelectorAll('.badge');
                                        let isFinalizado = false;
                                        estadoBadges.forEach(badge => {
                                            const estadoText = badge.textContent.trim().toLowerCase();
                                            if (estadoText === 'finalizado' || badge.classList.contains('bg-success')) {
                                                isFinalizado = true;
                                            }
                                        });
                                        
                                        // Solo habilitar si está finalizado
                                        if (isFinalizado) {
                                            sendToPacsButton.disabled = false;
                                            sendToPacsButton.classList.remove('btn-outline-secondary');
                                            sendToPacsButton.classList.add('btn-outline-success');
                                            sendToPacsButton.setAttribute('title', 'Enviar a PACS');
                                            console.log('✅ Botón "Enviar a PACS" habilitado para informe:', informeId, 'studyId:', studyId);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                // IMPORTANTE: Actualizar también el botón del grupo (si existe)
                if (informeId) {
                    const groupBtnId = `btnIncompletos-group-${studyId.replace(/[^a-zA-Z0-9]/g, '_')}`;
                    this.updateGroupButtonState(studyId, groupBtnId);
                }
                
                // NO recargar toda la lista ni actualizar todos los botones
                // Solo actualizamos el botón específico que cambió
                console.log('✅ [toggleInformesIncompletos] Proceso completado exitosamente (sin recargar lista)');
            } else {
                throw new Error(result.message || 'Error al guardar flag');
            }
        } catch (error) {
            console.error('Error en toggleInformesIncompletos:', error);
            this.showToast('❌ Error al marcar/desmarcar informes incompletos: ' + error.message, 'error');
        }
    },

    /**
     * Mostrar modal especial para grupo de informes
     * Permite ver y gestionar el estado de incompleto de cada informe individualmente
     */
    showGroupIncompleteModal: async function(studyId, patientName, informeIdsStr, btnId) {
        const informeIds = informeIdsStr.split(',').map(id => parseInt(id.trim()));
        
        // Obtener información de cada informe
        const informesData = [];
        for (const informeId of informeIds) {
            const compositeKey = `${studyId}_informe_${informeId}`;
            const flag = this.state.studyFlags[compositeKey];
            const isMarked = flag ? Boolean(flag.informes_incompletos) : false;
            const nota = flag && flag.nota ? flag.nota : '';
            
            // Buscar el informe en la lista para obtener su título
            let informeTitulo = `Informe #${informeId}`;
            const allInformes = this.state.informes || [];
            const informe = allInformes.find(inf => inf.id === informeId);
            if (informe && informe.titulo) {
                informeTitulo = informe.titulo;
            }
            
            informesData.push({
                id: informeId,
                titulo: informeTitulo,
                isMarked: isMarked,
                nota: nota
            });
        }
        
        // Crear modal
        const modalId = 'groupIncompleteModal';
        let modalElement = document.getElementById(modalId);
        
        if (modalElement) {
            modalElement.remove();
        }
        
        const modalHTML = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="${modalId}Label">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                Gestionar Informes Incompletos - ${this.escapeHtml(patientName)}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">
                                <i class="fas fa-info-circle me-1"></i>
                                Haz clic en cada informe para marcar/desmarcar como incompleto y agregar notas individuales.
                            </p>
                            <div class="list-group" id="groupIncompleteList">
                                ${informesData.map(informe => `
                                    <div class="list-group-item list-group-item-action" 
                                         style="cursor: pointer;"
                                         data-informe-id="${informe.id}">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1">
                                                    ${informe.isMarked ? '<i class="fas fa-exclamation-triangle text-warning me-2"></i>' : '<i class="far fa-circle text-muted me-2"></i>'}
                                                    ${this.escapeHtml(informe.titulo)}
                                                </h6>
                                                ${informe.nota ? `<small class="text-muted"><strong>Nota:</strong> ${this.escapeHtml(informe.nota)}</small>` : ''}
                                            </div>
                                            <div>
                                                <span class="badge ${informe.isMarked ? 'bg-warning text-dark' : 'bg-secondary'}">
                                                    ${informe.isMarked ? 'Marcado' : 'Sin marcar'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        modalElement = document.getElementById(modalId);
        
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: false,
            keyboard: true
        });
        
        // Agregar clase modal-open al body manualmente
        document.body.classList.add('modal-open');
        
        // Limpiar al cerrar
        const cleanupModalOpen = () => {
            // Remover backdrops residuales
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            
            // Remover clase modal-open si no hay otros modales abiertos
            const openModals = document.querySelectorAll('.modal.show');
            if (openModals.length === 0) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        };
        modalElement.addEventListener('hidden.bs.modal', cleanupModalOpen, { once: true });
        
        modal.show();
        
        // Agregar event listeners a los items de la lista
        const listItems = modalElement.querySelectorAll('.list-group-item[data-informe-id]');
        listItems.forEach(item => {
            item.addEventListener('click', async (e) => {
                e.preventDefault();
                const informeId = item.getAttribute('data-informe-id');
                
                // Cerrar el modal del grupo primero
                modal.hide();
                
                // Esperar a que el modal se cierre completamente
                await new Promise(resolve => {
                    modalElement.addEventListener('hidden.bs.modal', resolve, { once: true });
                });
                
                // Abrir el modal individual
                await this.toggleInformesIncompletos(studyId, patientName, informeId, `btnIncompletos-${informeId}`);
                
                // Actualizar el botón del grupo después de cerrar el modal individual
                this.updateGroupButtonState(studyId, btnId);
            });
        });
        
        // Limpiar modal al cerrar
        modalElement.addEventListener('hidden.bs.modal', () => {
            modalElement.remove();
            // Actualizar el estado del botón del grupo
            this.updateGroupButtonState(studyId, btnId);
        }, { once: true });
    },
    
    /**
     * Mostrar modal para marcar/desmarcar informes incompletos
     */
    showInformesIncompletosModal: function(studyId, patientName, isCurrentlyMarked, currentNota, informeId = null) {
        return new Promise((resolve) => {
            console.log('🔍 [showInformesIncompletosModal] Abriendo modal con:', { studyId, patientName, isCurrentlyMarked, currentNota, informeId });
            
            // Crear modal dinámicamente si no existe
            let modalElement = document.getElementById('modalInformesIncompletos');
            
            if (!modalElement) {
                modalElement = document.createElement('div');
                modalElement.id = 'modalInformesIncompletos';
                modalElement.className = 'modal fade';
                document.body.appendChild(modalElement);
            }
            
            modalElement.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header ${isCurrentlyMarked ? 'bg-warning' : 'bg-primary'} text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ${isCurrentlyMarked ? 'Desmarcar Informes Incompletos' : 'Marcar Informes Incompletos'}
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <p><strong>Paciente:</strong> ${this.escapeHtml(patientName)}</p>
                                <p><strong>Estudio ID:</strong> <code>${this.escapeHtml(studyId)}</code></p>
                                ${informeId ? `<p><strong>Informe ID:</strong> <code>${this.escapeHtml(informeId)}</code></p>` : ''}
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="checkInformesIncompletos" ${isCurrentlyMarked ? 'checked' : ''}>
                                <label class="form-check-label" for="checkInformesIncompletos">
                                    <strong>Marcar como informes incompletos</strong>
                                </label>
                            </div>
                            <div class="mb-3">
                                <label for="notaInformesIncompletos" class="form-label">Nota (opcional)</label>
                                <textarea class="form-control" id="notaInformesIncompletos" rows="3" placeholder="Agregar una nota sobre los informes incompletos...">${this.escapeHtml(currentNota || '')}</textarea>
                                <small class="form-text text-muted">Esta nota se mostrará como tooltip en el botón.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-primary" id="btnConfirmarInformesIncompletos">
                                <i class="fas fa-check me-2"></i>Guardar
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Configurar listener para confirmar
            const confirmButton = modalElement.querySelector('#btnConfirmarInformesIncompletos');
            const checkBox = modalElement.querySelector('#checkInformesIncompletos');
            const notaField = modalElement.querySelector('#notaInformesIncompletos');
            
            let isConfirmed = false; // Flag para evitar que el listener de hidden resuelva con null
            
            // Configurar listener para cancelar (debe estar definido antes de usarse)
            const handleHidden = () => {
                console.log('🔍 [showInformesIncompletosModal] Evento hidden.bs.modal disparado, isConfirmed:', isConfirmed);
                // Solo resolver con null si no fue confirmado
                if (!isConfirmed) {
                    console.log('🔍 [showInformesIncompletosModal] Resolviendo con null (cancelado)');
                    modalElement.removeEventListener('hidden.bs.modal', handleHidden);
                    resolve(null);
                } else {
                    console.log('🔍 [showInformesIncompletosModal] Ignorando evento hidden porque ya fue confirmado');
                }
            };
            modalElement.addEventListener('hidden.bs.modal', handleHidden);
            
            confirmButton.addEventListener('click', () => {
                console.log('🔍 [showInformesIncompletosModal] Botón Guardar clickeado');
                console.log('🔍 [showInformesIncompletosModal] checkBox:', checkBox);
                console.log('🔍 [showInformesIncompletosModal] notaField:', notaField);
                
                const informes_incompletos = checkBox ? checkBox.checked : false;
                const nota = notaField ? notaField.value.trim() : '';
                
                console.log('🔍 [showInformesIncompletosModal] Valores capturados:', { informes_incompletos, nota, informeId });
                
                isConfirmed = true; // Marcar como confirmado antes de cerrar
                
                // Remover el listener de hidden antes de cerrar el modal
                modalElement.removeEventListener('hidden.bs.modal', handleHidden);
                
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    console.log('🔍 [showInformesIncompletosModal] Cerrando modal...');
                    modal.hide();
                }
                
                // Esperar un momento para que el modal se cierre completamente antes de resolver
                setTimeout(() => {
                    // Resolver la promesa con los datos
                    console.log('🔍 [showInformesIncompletosModal] Resolviendo promesa con:', { informes_incompletos, nota: nota || null });
                    resolve({
                        informes_incompletos: informes_incompletos,
                        nota: nota || null
                    });
                }, 100);
            }, { once: true }); // Usar { once: true } para evitar múltiples listeners
            
            // Mostrar modal sin backdrop de Bootstrap
            let modal = bootstrap.Modal.getInstance(modalElement);
            if (!modal) {
                modal = new bootstrap.Modal(modalElement, {
                    backdrop: false,
                    keyboard: true
                });
            }
            
            // Agregar clase modal-open al body manualmente
            document.body.classList.add('modal-open');
            
            // Limpiar backdrops residuales al cerrar (además del listener existente)
            const cleanupBackdropsOnClose = () => {
                // Remover backdrops residuales
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                
                // Remover clase modal-open si no hay otros modales abiertos
                const openModals = document.querySelectorAll('.modal.show');
                if (openModals.length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            };
            
            // Agregar listener adicional para limpieza de backdrops
            modalElement.addEventListener('hidden.bs.modal', cleanupBackdropsOnClose, { once: true });
            
            modal.show();
        });
    },

    /**
     * Mostrar transcripción del audio en un modal
     * @param {number} audioId - ID del audio
     */
    showTranscription: function(audioId) {
        // Buscar el audio en la lista de audios del modal
        const audio = this.state.editModalAudios?.find(a => a.id == audioId);
        if (!audio) {
            this.showError('Audio no encontrado');
            return;
        }

        // Verificar si tiene transcripción
        const transcription = audio.transcripcion || audio.transcripcion_texto || '';
        if (!transcription || transcription.trim() === '') {
            this.showError('Este audio no tiene transcripción disponible');
            return;
        }

        // Actualizar el modal con la información
        const audioNameEl = document.getElementById('transcriptionAudioName');
        const transcriptionTextEl = document.getElementById('transcriptionText');
        
        if (audioNameEl) {
            audioNameEl.textContent = audio.nombre_original || audio.nombre_archivo || 'Audio';
        }
        
        if (transcriptionTextEl) {
            transcriptionTextEl.value = transcription;
        }

        // Guardar el audioId actual para poder insertarlo después
        this.state.currentTranscriptionAudioId = audioId;
        
        // Guardar la transcripción en el estado para poder copiarla
        this.state.currentTranscription = transcription;

        // Configurar el botón de insertar
        const insertBtn = document.getElementById('btnInsertTranscription');
        if (insertBtn) {
            // Verificar si estamos en modo lectura (Ver Informe) o edición (Editar Informe)
            const isReadOnly = this.state.isReadOnly || false;
            
            if (isReadOnly) {
                // En modo lectura: deshabilitar el botón
                insertBtn.disabled = true;
                insertBtn.classList.add('disabled');
                insertBtn.title = 'El botón de insertar solo está disponible en modo edición';
            } else {
                // En modo edición: habilitar el botón y configurar listener
                insertBtn.disabled = false;
                insertBtn.classList.remove('disabled');
                insertBtn.title = 'Insertar transcripción en el editor';
                
                // Remover listeners anteriores para evitar duplicados
                const newInsertBtn = insertBtn.cloneNode(true);
                insertBtn.parentNode.replaceChild(newInsertBtn, insertBtn);
                
                // Agregar nuevo listener
                newInsertBtn.addEventListener('click', () => {
                    this.insertTranscriptionToEditor();
                });
            }
        }

        // Mostrar el modal
        const modalElement = document.getElementById('transcriptionModal');
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
    },

    /**
     * Insertar la transcripción en el editor TinyMCE
     * Si hay texto previo, hace append. Si no, inserta.
     * @param {string} [plainTextOverride] Texto plano (p. ej. desde el panel flotante). Si se omite, se usa #transcriptionText.
     * @param {{ skipCloseModal?: boolean }} [options] skipCloseModal: no cerrar el modal de transcripción (panel flotante).
     */
    insertTranscriptionToEditor: function(plainTextOverride, options) {
        options = options || {};
        if (!this.state.tinymceEditor) {
            this.showError('El editor no está disponible');
            return;
        }

        let transcription = '';
        if (plainTextOverride != null && String(plainTextOverride).trim() !== '') {
            transcription = String(plainTextOverride).trim();
        } else {
            const transcriptionTextEl = document.getElementById('transcriptionText');
            if (!transcriptionTextEl) {
                this.showError('No se encontró la transcripción');
                return;
            }
            transcription = transcriptionTextEl.value.trim();
        }

        if (!transcription) {
            this.showError('La transcripción está vacía');
            return;
        }

        // Obtener el contenido actual del editor
        const currentContent = this.state.tinymceEditor.getContent();
        const trimmedContent = currentContent ? currentContent.trim() : '';
        const hasExistingContent = trimmedContent !== '' && trimmedContent !== '<p></p>' && trimmedContent !== '<p><br></p>';

        // Preparar el texto a insertar (convertir saltos de línea a párrafos HTML)
        const transcriptionHtml = transcription
            .split('\n')
            .filter(line => line.trim() !== '')
            .map(line => `<p>${this.escapeHtml(line.trim())}</p>`)
            .join('');

        if (hasExistingContent) {
            // Hay texto previo: hacer append
            // Limpiar el contenido actual eliminando párrafos vacíos al final
            let cleanContent = trimmedContent;
            // Remover párrafos vacíos al final
            cleanContent = cleanContent.replace(/(<p><\/p>|<p><br><\/p>)+$/gi, '');
            const newContent = cleanContent + transcriptionHtml;
            this.state.tinymceEditor.setContent(newContent);
            this.showSuccess('Transcripción agregada al final del contenido');
        } else {
            // No hay texto previo: insertar directamente
            this.state.tinymceEditor.setContent(transcriptionHtml);
            this.showSuccess('Transcripción insertada en el editor');
        }

        // Marcar como modificado
        this.markAsModified();

        if (!options.skipCloseModal) {
            const modalElement = document.getElementById('transcriptionModal');
            if (modalElement) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
            }
        }
    },

    /**
     * Copiar transcripción del panel flotante (timestamps) al portapapeles
     */
    copyFloatingTranscriptionToClipboard: function() {
        const fromState = (this.state.floatingTranscriptionPlainText || '').trim();
        const textContainer = document.getElementById('floatingTranscriptionText');
        const fromDom = textContainer ? textContainer.innerText.trim() : '';
        const text = fromState || fromDom;
        if (!text) {
            this.showError('No hay transcripción para copiar');
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                this.showSuccess('Transcripción copiada al portapapeles');
            }).catch(() => {
                this.fallbackCopyPlainTextToClipboard(text);
            });
        } else {
            this.fallbackCopyPlainTextToClipboard(text);
        }
    },

    /**
     * Portapapeles para texto plano sin depender del textarea del modal de transcripción
     */
    fallbackCopyPlainTextToClipboard: function(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, text.length);
        try {
            const ok = document.execCommand('copy');
            if (ok) {
                this.showSuccess('Transcripción copiada al portapapeles');
            } else {
                this.showError('No se pudo copiar. Selecciona el texto en el panel y copia manualmente.');
            }
        } catch (e) {
            console.error('Error al copiar:', e);
            this.showError('No se pudo copiar. Selecciona el texto en el panel y copia manualmente.');
        }
        document.body.removeChild(ta);
    },

    /**
     * Insertar en el editor el texto del panel flotante (misma lógica que el modal de transcripción)
     */
    insertFloatingTranscriptionToEditor: function() {
        const text = (this.state.floatingTranscriptionPlainText || '').trim();
        if (!text) {
            this.showError('No hay transcripción para insertar');
            return;
        }
        if (this.state.isReadOnly) {
            this.showError('El informe está en solo lectura');
            return;
        }
        if (!this.state.tinymceEditor || !this.state.tinymceEditor.initialized) {
            this.showError('El editor no está disponible');
            return;
        }
        this.insertTranscriptionToEditor(text, { skipCloseModal: true });
    },

    /**
     * Habilita o deshabilita el botón de insertar del panel flotante según editor y modo edición
     */
    updateFloatingTranscriptionInsertButton: function() {
        const btn = document.getElementById('floatingTranscriptionInsert');
        if (!btn) {
            return;
        }
        const editorOk = !!(this.state.tinymceEditor && this.state.tinymceEditor.initialized);
        const editable = editorOk && !this.state.isReadOnly;
        btn.disabled = !editable;
        btn.classList.toggle('disabled', !editable);
        if (!editorOk) {
            btn.title = 'El editor no está disponible';
        } else if (this.state.isReadOnly) {
            btn.title = 'Solo disponible al editar el informe (no en solo lectura)';
        } else {
            btn.title = 'Insertar transcripción en el editor del informe';
        }
    },

    /**
     * Copiar transcripción al portapapeles
     */
    copyTranscriptionToClipboard: function() {
        const transcriptionTextEl = document.getElementById('transcriptionText');
        const transcription = this.state.currentTranscription || (transcriptionTextEl ? transcriptionTextEl.value : '');
        
        if (!transcription || transcription.trim() === '') {
            this.showError('No hay transcripción para copiar');
            return;
        }
        
        // Intentar usar la API moderna del portapapeles
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(transcription).then(() => {
                this.showSuccess('Transcripción copiada al portapapeles');
            }).catch(err => {
                console.error('Error al copiar:', err);
                // Fallback al método antiguo
                this.fallbackCopyToClipboard(transcription);
            });
        } else {
            // Fallback para navegadores que no soportan la API moderna
            this.fallbackCopyToClipboard(transcription);
        }
    },

    /**
     * Método alternativo para copiar al portapapeles (fallback)
     */
    fallbackCopyToClipboard: function(text) {
        const transcriptionTextEl = document.getElementById('transcriptionText');
        
        if (transcriptionTextEl) {
            // Hacer el textarea temporalmente editable y seleccionable
            transcriptionTextEl.readOnly = false;
            transcriptionTextEl.select();
            transcriptionTextEl.setSelectionRange(0, 99999); // Para dispositivos móviles
            
            try {
                const successful = document.execCommand('copy');
                transcriptionTextEl.readOnly = true;
                
                if (successful) {
                    this.showSuccess('Transcripción copiada al portapapeles');
                } else {
                    this.showError('No se pudo copiar la transcripción. Por favor, selecciona y copia manualmente.');
                }
            } catch (err) {
                console.error('Error al copiar:', err);
                transcriptionTextEl.readOnly = true;
                this.showError('No se pudo copiar la transcripción. Por favor, selecciona y copia manualmente.');
            }
        } else {
            this.showError('No se encontró el elemento de transcripción');
        }
    },

    /**
     * Inicializar panel flotante de transcripción con timestamps
     */
    initFloatingTranscriptionPanel: function(audio) {
        const panel = document.getElementById('floatingTranscriptionPanel');
        const textContainer = document.getElementById('floatingTranscriptionText');
        const audioElement = document.getElementById('editAudioElement');
        
        if (!panel || !textContainer || !audioElement) {
            console.warn('Elementos del panel flotante no encontrados');
            return;
        }
        
        if (!audio.transcripcion || !audio.segments || !audio.segments.length) {
            this.hideFloatingTranscriptionPanel();
            return;
        }

        this.state.floatingTranscriptionPlainText = (audio.transcripcion || '').trim();

        // Mostrar panel
        panel.style.display = 'flex';
        
        // Mapear palabras a timestamps
        const wordsWithTimestamps = this.mapWordsToTimestamps(audio.transcripcion, audio.segments);
        
        // Reconstruir el HTML con palabras clicables
        const htmlWords = wordsWithTimestamps.map((word, idx) => {
            const timestamp = word.timestamp !== null ? word.timestamp : null;
            const className = timestamp !== null ? 'transcription-word clickable-word' : 'transcription-word';
            const dataAttr = timestamp !== null ? ` data-timestamp="${timestamp}"` : '';
            const title = timestamp !== null ? ` title="Ir a ${this.formatSecondsToWhisperTs(timestamp)}"` : '';
            return `<span class="${className}"${dataAttr}${title} data-word-idx="${idx}">${this.escapeHtml(word.text)}</span>`;
        });
        
        textContainer.innerHTML = htmlWords.join('');
        
        // Inicializar resaltado durante reproducción (antes de los clicks para tener acceso a updateHighlight)
        const highlightData = this.initFloatingTranscriptionHighlight(textContainer, audioElement, wordsWithTimestamps);
        
        // Configurar eventos de click en palabras
        const AUDIO_OFFSET = -0.1; // 100ms antes para compensar delay
        
        textContainer.querySelectorAll('.clickable-word').forEach(wordSpan => {
            wordSpan.addEventListener('click', () => {
                let timestamp = parseFloat(wordSpan.getAttribute('data-timestamp'));
                if (!isNaN(timestamp) && timestamp >= 0 && audioElement) {
                    timestamp = Math.max(0, timestamp + AUDIO_OFFSET);
                    audioElement.currentTime = timestamp;
                    
                    // Forzar actualización inmediata del resaltado después de un pequeño delay
                    // para asegurar que el currentTime se haya actualizado
                    setTimeout(() => {
                        if (highlightData && highlightData.updateHighlight) {
                            // Resetear lastUpdateTime para permitir actualización inmediata
                            if (highlightData.resetLastUpdateTime) {
                                highlightData.resetLastUpdateTime();
                            }
                            // Forzar actualización
                            highlightData.updateHighlight();
                        }
                    }, 50);
                    
                    setTimeout(() => {
                        if (Math.abs(audioElement.currentTime - timestamp) > 0.1) {
                            audioElement.currentTime = timestamp;
                        }
                        audioElement.play().catch(e => console.log('Error al reproducir:', e));
                    }, 30);
                }
            });
        });
        
        // Configurar eventos del panel (foco, minimizar, cerrar, arrastre)
        this.setupFloatingTranscriptionPanelEvents(panel);
        this.updateFloatingTranscriptionInsertButton();
    },

    /**
     * Mapear palabras del texto a timestamps usando los segments
     */
    mapWordsToTimestamps: function(fullText, segments) {
        if (!fullText || !segments || !segments.length) {
            return fullText.split(/(\s+)/).map(text => ({ text, timestamp: null }));
        }
        
        const words = fullText.split(/(\s+)/);
        const result = [];
        const normalizedFullText = fullText.replace(/\s+/g, ' ').trim();
        const fullWords = normalizedFullText.split(/\s+/).filter(w => w.length > 0);
        
        let fullTextWordIdx = 0;
        let currentSegmentIdx = 0;
        
        for (let wordIdx = 0; wordIdx < words.length; wordIdx++) {
            const word = words[wordIdx];
            
            if (!word.trim()) {
                result.push({ text: word, timestamp: null });
                continue;
            }
            
            const wordTrimmed = word.trim();
            let timestamp = null;
            
            if (fullTextWordIdx < fullWords.length && fullWords[fullTextWordIdx] === wordTrimmed) {
                let foundInSegment = false;
                
                for (let segIdx = currentSegmentIdx; segIdx < segments.length; segIdx++) {
                    const seg = segments[segIdx];
                    const segText = (seg.text || '').trim();
                    
                    if (!segText) continue;
                    
                    const segWords = segText.split(/\s+/).filter(w => w.length > 0);
                    const wordPosInSegment = segWords.findIndex(w => w === wordTrimmed);
                    
                    if (wordPosInSegment >= 0) {
                        const totalWords = segWords.length;
                        const segmentDuration = seg.end - seg.start;
                        
                        if (totalWords === 1) {
                            timestamp = seg.start;
                        } else if (wordPosInSegment === 0) {
                            timestamp = seg.start;
                        } else if (wordPosInSegment === totalWords - 1) {
                            timestamp = seg.end;
                        } else {
                            const progress = wordPosInSegment / (totalWords - 1);
                            timestamp = seg.start + segmentDuration * progress;
                        }
                        
                        result.push({ text: word, timestamp });
                        currentSegmentIdx = segIdx;
                        foundInSegment = true;
                        fullTextWordIdx++;
                        break;
                    }
                }
                
                if (!foundInSegment) {
                    if (currentSegmentIdx < segments.length) {
                        timestamp = segments[currentSegmentIdx].start;
                        result.push({ text: word, timestamp });
                    } else {
                        result.push({ text: word, timestamp: null });
                    }
                    fullTextWordIdx++;
                }
            } else {
                if (currentSegmentIdx < segments.length) {
                    timestamp = segments[currentSegmentIdx].start;
                    result.push({ text: word, timestamp });
                } else {
                    result.push({ text: word, timestamp: null });
                }
            }
        }
        
        return result;
    },

    /**
     * Formatea segundos a HH:MM:SS.mmm
     */
    formatSecondsToWhisperTs: function(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        const ms = Math.round((seconds - Math.floor(seconds)) * 1000);
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${String(ms).padStart(3, '0')}`;
    },

    /**
     * Inicializar resaltado de palabras durante reproducción
     * Retorna un objeto con funciones y datos para permitir actualización manual
     */
    initFloatingTranscriptionHighlight: function(textContainer, audioElement, wordsWithTimestamps) {
        if (!textContainer || !audioElement || !wordsWithTimestamps) return null;
        
        let currentWordIdx = -1;
        let lastUpdateTime = 0;
        
        const updateHighlight = () => {
            const currentTime = audioElement.currentTime;
            
            // Permitir actualización si ha pasado suficiente tiempo O si se fuerza (lastUpdateTime === 0)
            if (lastUpdateTime > 0 && currentTime - lastUpdateTime < 0.1) return;
            lastUpdateTime = currentTime;
            
            let newWordIdx = -1;
            let bestDistance = Infinity;
            
            for (let i = 0; i < wordsWithTimestamps.length; i++) {
                const word = wordsWithTimestamps[i];
                if (word.timestamp === null) continue;
                
                const distance = Math.abs(currentTime - word.timestamp);
                
                if (currentTime >= word.timestamp) {
                    let nextWordTimestamp = null;
                    for (let j = i + 1; j < wordsWithTimestamps.length; j++) {
                        if (wordsWithTimestamps[j].timestamp !== null) {
                            nextWordTimestamp = wordsWithTimestamps[j].timestamp;
                            break;
                        }
                    }
                    
                    if (nextWordTimestamp === null || currentTime < nextWordTimestamp) {
                        if (distance < bestDistance) {
                            bestDistance = distance;
                            newWordIdx = i;
                        }
                    }
                }
            }
            
            if (newWordIdx === -1) {
                for (let i = 0; i < wordsWithTimestamps.length; i++) {
                    const word = wordsWithTimestamps[i];
                    if (word.timestamp === null) continue;
                    const distance = Math.abs(currentTime - word.timestamp);
                    if (distance < bestDistance) {
                        bestDistance = distance;
                        newWordIdx = i;
                    }
                }
            }
            
            if (newWordIdx !== currentWordIdx && newWordIdx >= 0) {
                if (currentWordIdx >= 0) {
                    const prevWord = textContainer.querySelector(`[data-word-idx="${currentWordIdx}"]`);
                    if (prevWord) prevWord.classList.remove('active');
                }
                
                const newWord = textContainer.querySelector(`[data-word-idx="${newWordIdx}"]`);
                if (newWord) {
                    newWord.classList.add('active');
                    const rect = newWord.getBoundingClientRect();
                    const containerRect = textContainer.getBoundingClientRect();
                    if (rect.top < containerRect.top || rect.bottom > containerRect.bottom) {
                        newWord.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
                
                currentWordIdx = newWordIdx;
            }
        };
        
        // Agregar listener para actualización automática durante reproducción
        audioElement.addEventListener('timeupdate', updateHighlight);
        
        // Listener para cuando el tiempo cambia (incluyendo cuando se hace click)
        audioElement.addEventListener('seeked', () => {
            // Forzar actualización cuando se cambia el tiempo manualmente
            lastUpdateTime = 0;
            updateHighlight();
        });
        
        audioElement.addEventListener('pause', () => {
            if (currentWordIdx >= 0) {
                const word = textContainer.querySelector(`[data-word-idx="${currentWordIdx}"]`);
                if (word) word.classList.remove('active');
            }
        });
        
        audioElement.addEventListener('ended', () => {
            if (currentWordIdx >= 0) {
                const word = textContainer.querySelector(`[data-word-idx="${currentWordIdx}"]`);
                if (word) word.classList.remove('active');
            }
            currentWordIdx = -1;
        });
        
        // Retornar objeto con funciones y datos para permitir actualización manual
        return {
            updateHighlight: updateHighlight,
            resetLastUpdateTime: () => { lastUpdateTime = 0; }
        };
    },

    /**
     * Configurar eventos del panel flotante (foco, minimizar, cerrar, arrastre)
     */
    setupFloatingTranscriptionPanelEvents: function(panel) {
        if (!panel) return;
        
        // Manejar foco
        panel.addEventListener('mouseenter', () => {
            panel.classList.add('focused');
        });
        
        panel.addEventListener('mouseleave', () => {
            panel.classList.remove('focused');
        });
        
        // Botón minimizar/maximizar
        const toggleBtn = document.getElementById('floatingTranscriptionToggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.toggle('minimized');
                const icon = document.getElementById('floatingTranscriptionToggleIcon');
                if (icon) {
                    icon.className = panel.classList.contains('minimized') 
                        ? 'fas fa-chevron-up' 
                        : 'fas fa-chevron-down';
                }
            });
        }
        
        // Botón cerrar
        const closeBtn = document.getElementById('floatingTranscriptionClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.hideFloatingTranscriptionPanel();
            });
        }
        
        // Funcionalidad de arrastre desde el header
        this.setupFloatingTranscriptionPanelDrag(panel);
    },

    /**
     * Configurar funcionalidad de arrastre para el panel flotante
     */
    setupFloatingTranscriptionPanelDrag: function(panel) {
        if (!panel) return;
        
        const header = panel.querySelector('.floating-transcription-header');
        if (!header) return;
        
        // Evitar múltiples listeners - remover listeners anteriores si existen
        if (panel._dragHandlers) {
            header.removeEventListener('mousedown', panel._dragHandlers.dragStart);
            document.removeEventListener('mousemove', panel._dragHandlers.drag);
            document.removeEventListener('mouseup', panel._dragHandlers.dragEnd);
        }
        
        let isDragging = false;
        let currentX;
        let currentY;
        let initialX;
        let initialY;
        let xOffset = 0;
        let yOffset = 0;
        
        // Obtener posición guardada del localStorage si existe
        const savedPosition = localStorage.getItem('floatingTranscriptionPanelPosition');
        if (savedPosition) {
            try {
                const pos = JSON.parse(savedPosition);
                xOffset = pos.x || 0;
                yOffset = pos.y || 0;
                panel.style.left = `${pos.x}px`;
                panel.style.right = 'auto';
                panel.style.bottom = 'auto';
                panel.style.top = `${pos.y}px`;
            } catch (e) {
                console.warn('Error al cargar posición guardada del panel:', e);
                // Si hay error, usar posición por defecto
                panel.style.left = 'auto';
                panel.style.right = '20px';
                panel.style.bottom = '20px';
                panel.style.top = 'auto';
            }
        } else {
            // Posición por defecto si no hay posición guardada
            panel.style.left = 'auto';
            panel.style.right = '20px';
            panel.style.bottom = '20px';
            panel.style.top = 'auto';
        }
        
        const dragStart = (e) => {
            // No iniciar arrastre si se hace click en los botones
            if (e.target.closest('.floating-transcription-controls')) {
                return;
            }
            
            initialX = e.clientX - xOffset;
            initialY = e.clientY - yOffset;
            
            if (e.button === 0) { // Solo botón izquierdo
                isDragging = true;
                header.style.cursor = 'grabbing';
                panel.style.transition = 'none'; // Desactivar transiciones durante el arrastre
            }
        };
        
        const drag = (e) => {
            if (isDragging) {
                e.preventDefault();
                
                currentX = e.clientX - initialX;
                currentY = e.clientY - initialY;
                
                // Calcular límites para mantener el panel dentro de la ventana
                const panelRect = panel.getBoundingClientRect();
                const maxX = window.innerWidth - panelRect.width;
                const maxY = window.innerHeight - panelRect.height;
                
                // Aplicar límites
                currentX = Math.max(0, Math.min(currentX, maxX));
                currentY = Math.max(0, Math.min(currentY, maxY));
                
                xOffset = currentX;
                yOffset = currentY;
                
                setTranslate(currentX, currentY);
            }
        };
        
        const dragEnd = (e) => {
            if (isDragging) {
                initialX = currentX;
                initialY = currentY;
                
                isDragging = false;
                header.style.cursor = 'grab';
                panel.style.transition = ''; // Reactivar transiciones
                
                // Guardar posición en localStorage
                try {
                    localStorage.setItem('floatingTranscriptionPanelPosition', JSON.stringify({
                        x: xOffset,
                        y: yOffset
                    }));
                } catch (e) {
                    console.warn('Error al guardar posición del panel:', e);
                }
            }
        };
        
        const setTranslate = (xPos, yPos) => {
            panel.style.left = `${xPos}px`;
            panel.style.right = 'auto';
            panel.style.bottom = 'auto';
            panel.style.top = `${yPos}px`;
        };
        
        // Guardar referencias a los handlers para poder removerlos después
        panel._dragHandlers = { dragStart, drag, dragEnd };
        
        header.addEventListener('mousedown', dragStart);
        document.addEventListener('mousemove', drag);
        document.addEventListener('mouseup', dragEnd);
    },

    /**
     * Ocultar panel flotante
     */
    hideFloatingTranscriptionPanel: function() {
        const panel = document.getElementById('floatingTranscriptionPanel');
        if (panel) {
            panel.style.display = 'none';
        }
        this.state.floatingTranscriptionPlainText = '';
    },

    /**
     * Alternar visibilidad del panel flotante de transcripción
     * @returns {'shown'|'hidden'|'no-modal'|'no-audio'|'no-transcription'}
     */
    toggleFloatingTranscriptionPanel: function() {
        const panel = document.getElementById('floatingTranscriptionPanel');
        if (!panel) {
            return 'no-modal';
        }

        const modal = document.getElementById('reportEditorModal');
        if (!modal || !modal.classList.contains('show')) {
            return 'no-modal';
        }

        const audioSelect = document.getElementById('editAudioSelect');
        if (!audioSelect || !audioSelect.value) {
            return 'no-audio';
        }

        const audioId = audioSelect.value;
        const audio = this.state.editModalAudios?.find(a => a.id == audioId || a.id == parseInt(audioId));

        if (!audio || !audio.segments || !audio.segments.length || !audio.transcripcion) {
            return 'no-transcription';
        }

        const isHidden = panel.style.display === 'none' || !panel.style.display;
        if (isHidden) {
            this.initFloatingTranscriptionPanel(audio);
            return 'shown';
        }
        this.hideFloatingTranscriptionPanel();
        return 'hidden';
    },

    showAttachPdfModal: function() {
        const modalElement = document.getElementById('attachPdfModal');
        if (!modalElement) {
            this.showError('No se encontró el modal de adjuntar informe');
            return;
        }
        if (typeof bootstrap === 'undefined') {
            this.showError('Bootstrap no está disponible');
            return;
        }
        this.state.attachSelectedStudy = null;
        this.resetAttachPdfModalFields();

        const maxLbl = document.getElementById('attachStudyMaxRangeLabel');
        if (maxLbl) maxLbl.textContent = String(this.config.attachStudyMaxRangeDays || 120);

        const pack = this.loadPersistedAttachStudiesList();
        if (pack && pack.studies && pack.studies.length > 0) {
            this.state.attachStudiesListSource = 'persisted';
            this.state.attachStudiesAllResults = pack.studies;
            this.state.attachStudySortConfig = pack.sortConfig && typeof pack.sortConfig === 'object'
                ? { column: pack.sortConfig.column ?? null, direction: pack.sortConfig.direction || 'asc' }
                : { column: null, direction: 'asc' };
            this.state.attachStudyModalityFilters = Array.isArray(pack.modalityFilters)
                ? pack.modalityFilters.slice()
                : [];
            this.updateAttachModalityButtons();
            this.applyAttachStudiesPipeline();
        } else {
            this.state.attachStudiesListSource = null;
            this.state.attachStudiesAllResults = [];
            this.state.attachStudySearchResults = [];
            this.state.attachStudySortConfig = { column: null, direction: 'asc' };
            this.state.attachStudyModalityFilters = [];
            this.updateAttachModalityButtons();
            this.updateAttachSortIcons();
            this.resetAttachStudiesTableToInitialMessage();
        }

        let modal = bootstrap.Modal.getInstance(modalElement);
        if (!modal) {
            modal = new bootstrap.Modal(modalElement);
        }
        modal.show();
    },

    resetAttachPdfModalFields: function() {
        const fileEl = document.getElementById('attachPdfFile');
        const titleEl = document.getElementById('attachPdfTitle');
        const preview = document.getElementById('attachPdfPreview');
        const info = document.getElementById('attachSelectedStudyInfo');
        if (fileEl) fileEl.value = '';
        if (titleEl) titleEl.value = '';
        if (preview) preview.style.display = 'none';
        if (info) info.style.display = 'none';
        if (this.state.attachStudyFilterDebounce) {
            clearTimeout(this.state.attachStudyFilterDebounce);
            this.state.attachStudyFilterDebounce = null;
        }
        this.setAttachStudySearchButtonLoading(false);
        this.updateAttachPdfButtonState();
    },

    resetAttachStudiesTableToInitialMessage: function() {
        const tbody = document.getElementById('attachStudiesTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        Use filtros y pulse <strong>Buscar estudios</strong>.
                    </td>
                </tr>
            `;
        }
        const cnt = document.getElementById('attachStudyCount');
        if (cnt) cnt.textContent = '0 estudios';
    },

    loadPersistedAttachStudiesList: function() {
        try {
            const raw = sessionStorage.getItem(this.config.attachStudiesSessionKey);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || !Array.isArray(parsed.studies)) {
                sessionStorage.removeItem(this.config.attachStudiesSessionKey);
                return null;
            }
            const ver = parsed.v === 2 ? 2 : 1;
            return {
                studies: parsed.studies,
                sortConfig: ver >= 2 && parsed.sortConfig && typeof parsed.sortConfig === 'object'
                    ? { column: parsed.sortConfig.column ?? null, direction: parsed.sortConfig.direction || 'asc' }
                    : { column: null, direction: 'asc' },
                modalityFilters: ver >= 2 && Array.isArray(parsed.modalityFilters)
                    ? parsed.modalityFilters.filter(Boolean)
                    : []
            };
        } catch (e) {
            try {
                sessionStorage.removeItem(this.config.attachStudiesSessionKey);
            } catch (e2) { /* ignore */ }
            return null;
        }
    },

    persistAttachStudiesList: function() {
        try {
            const studies = this.state.attachStudiesAllResults;
            if (!Array.isArray(studies) || studies.length === 0) {
                sessionStorage.removeItem(this.config.attachStudiesSessionKey);
                return;
            }
            const payload = JSON.stringify({
                v: 2,
                savedAt: Date.now(),
                studies: studies,
                sortConfig: {
                    column: this.state.attachStudySortConfig.column,
                    direction: this.state.attachStudySortConfig.direction || 'asc'
                },
                modalityFilters: Array.isArray(this.state.attachStudyModalityFilters)
                    ? this.state.attachStudyModalityFilters.slice()
                    : []
            });
            sessionStorage.setItem(this.config.attachStudiesSessionKey, payload);
        } catch (e) {
            console.warn('Persistencia lista adjuntar informe:', e);
            if (e && e.name === 'QuotaExceededError') {
                this.showError('La lista es demasiado grande para guardarla en el navegador. Acote fechas o ID de paciente y vuelva a buscar.');
            }
        }
    },

    validateAttachStudySearchParams: function() {
        const maxDays = this.config.attachStudyMaxRangeDays || 120;
        const dateFromIn = document.getElementById('attachStudyDateFrom')?.value || '';
        const dateToIn = document.getElementById('attachStudyDateTo')?.value || '';
        const patientId = (document.getElementById('attachStudyPatientId')?.value || '').trim();

        let dateFrom = dateFromIn;
        let dateTo = dateToIn;

        if (!dateFrom && !dateTo && !patientId) {
            return {
                ok: false,
                message: 'Indique un rango de fechas o un ID de paciente para acotar la búsqueda y no saturar el servidor.'
            };
        }

        if (dateFrom && !dateTo) {
            dateTo = dateFrom;
        }
        if (!dateFrom && dateTo) {
            dateFrom = dateTo;
        }

        if (dateFrom && dateTo) {
            const d0 = new Date(dateFrom + 'T12:00:00');
            const d1 = new Date(dateTo + 'T12:00:00');
            if (isNaN(d0.getTime()) || isNaN(d1.getTime())) {
                return { ok: false, message: 'Las fechas no son válidas.' };
            }
            if (d0 > d1) {
                return { ok: false, message: 'La fecha «desde» no puede ser posterior a la fecha «hasta».' };
            }
            const msPerDay = 86400000;
            const inclusiveDays = Math.floor((d1 - d0) / msPerDay) + 1;
            if (inclusiveDays > maxDays) {
                return {
                    ok: false,
                    message: `El rango no puede superar ${maxDays} días (inclusive). Reduzca el intervalo entre desde y hasta.`
                };
            }
            return { ok: true, dateFrom, dateTo, patientId };
        }

        return { ok: true, dateFrom: '', dateTo: '', patientId };
    },

    handleAttachPdfFileSelect: function(file) {
        const preview = document.getElementById('attachPdfPreview');
        const input = document.getElementById('attachPdfFile');
        if (!file) {
            if (preview) preview.style.display = 'none';
            this.updateAttachPdfButtonState();
            return;
        }
        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            this.showError('El archivo debe ser un PDF');
            if (input) input.value = '';
            return;
        }
        const maxSize = 50 * 1024 * 1024;
        if (file.size > maxSize) {
            this.showError('El archivo es demasiado grande. Máximo permitido: 50MB');
            if (input) input.value = '';
            return;
        }
        if (preview) {
            const fn = document.getElementById('attachPdfFileName');
            const fs = document.getElementById('attachPdfFileSize');
            if (fn) fn.textContent = file.name;
            if (fs) fs.textContent = this.formatFileSize(file.size);
            preview.style.display = 'block';
        }
        this.updateAttachPdfButtonState();
    },

    setAttachStudySearchButtonLoading: function(loading) {
        const btn = document.getElementById('attachStudySearchButton');
        const spin = document.getElementById('attachStudySearchSpinner');
        const icon = btn && btn.querySelector('.attach-study-search-btn-icon');
        if (!btn) return;
        btn.disabled = !!loading;
        if (spin) spin.classList.toggle('d-none', !loading);
        if (icon) icon.classList.toggle('d-none', !!loading);
    },

    getAvailableAttachModalities: function() {
        const modalitiesSet = new Set();
        (this.state.attachStudiesAllResults || []).forEach(study => {
            const modality = study.modality;
            if (modality && String(modality).trim() !== '' && String(modality).trim() !== 'N/A') {
                String(modality).split(',').map(m => m.trim().toUpperCase()).filter(Boolean).forEach(m => {
                    modalitiesSet.add(m);
                });
            }
        });
        const standardOrder = ['RX', 'DX', 'CT', 'MR', 'US', 'MG', 'OT', 'XA', 'NM', 'PT', 'RF', 'DOC', 'CR', 'ES', 'SC'];
        return Array.from(modalitiesSet).sort((a, b) => {
            const indexA = standardOrder.indexOf(a);
            const indexB = standardOrder.indexOf(b);
            if (indexA !== -1 && indexB !== -1) return indexA - indexB;
            if (indexA !== -1) return -1;
            if (indexB !== -1) return 1;
            return a.localeCompare(b);
        });
    },

    updateAttachModalityCounter: function() {
        const counter = document.getElementById('attachStudyModalityCounter');
        if (!counter) return;
        const n = (this.state.attachStudyModalityFilters || []).length;
        if (n > 0) {
            counter.textContent = String(n);
            counter.style.display = 'inline-block';
        } else {
            counter.style.display = 'none';
        }
    },

    updateAttachModalityButtons: function() {
        const container = document.getElementById('attachStudyModalityButtonsContainer');
        if (!container) return;

        const available = this.getAvailableAttachModalities();
        let active = (this.state.attachStudyModalityFilters || []).slice().filter(m => available.includes(m));
        this.state.attachStudyModalityFilters = active;

        const wasAll = active.length === 0;
        container.innerHTML = '';

        const allBtn = document.createElement('button');
        allBtn.type = 'button';
        allBtn.className = 'btn attach-modality-btn' + (wasAll ? ' active' : '');
        allBtn.setAttribute('data-modality', 'all');
        allBtn.textContent = 'Todas';
        container.appendChild(allBtn);

        available.forEach(modality => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn attach-modality-btn';
            if (active.includes(modality)) btn.classList.add('active');
            btn.setAttribute('data-modality', modality);
            btn.textContent = modality;
            container.appendChild(btn);
        });

        this.updateAttachModalityCounter();
    },

    handleAttachStudyModalityButtonClick: function(btn) {
        if (!btn) return;
        const modality = btn.getAttribute('data-modality');
        const container = document.getElementById('attachStudyModalityButtonsContainer');
        if (!container || !modality) return;

        if (modality === 'all') {
            this.state.attachStudyModalityFilters = [];
            container.querySelectorAll('.attach-modality-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        } else {
            const allBtn = container.querySelector('.attach-modality-btn[data-modality="all"]');
            if (allBtn) allBtn.classList.remove('active');

            if (btn.classList.contains('active')) {
                btn.classList.remove('active');
                this.state.attachStudyModalityFilters = this.state.attachStudyModalityFilters.filter(m => m !== modality);
            } else {
                btn.classList.add('active');
                if (!this.state.attachStudyModalityFilters.includes(modality)) {
                    this.state.attachStudyModalityFilters.push(modality);
                }
            }
            if (this.state.attachStudyModalityFilters.length === 0 && allBtn) {
                allBtn.classList.add('active');
            }
        }

        this.updateAttachModalityCounter();
        this.applyAttachStudiesPipeline();
        this.persistAttachStudiesList();
    },

    updateAttachSortIcons: function() {
        document.querySelectorAll('#attachPdfModal th.attach-sortable').forEach(header => {
            const icon = header.querySelector('.attach-sort-icon');
            if (!icon) return;
            const column = header.getAttribute('data-attach-sort');
            if (this.state.attachStudySortConfig.column === column) {
                icon.className = this.state.attachStudySortConfig.direction === 'asc'
                    ? 'fas fa-sort-up attach-sort-icon ms-1'
                    : 'fas fa-sort-down attach-sort-icon ms-1';
            } else {
                icon.className = 'fas fa-sort attach-sort-icon ms-1 text-muted';
            }
        });
    },

    sortAttachStudiesByColumn: function(column) {
        if (!column) return;
        if (this.state.attachStudySortConfig.column === column) {
            this.state.attachStudySortConfig.direction =
                this.state.attachStudySortConfig.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.state.attachStudySortConfig.column = column;
            this.state.attachStudySortConfig.direction = 'asc';
        }
        this.updateAttachSortIcons();
        this.applyAttachStudiesPipeline();
        this.persistAttachStudiesList();
    },

    sortAttachStudiesResults: function(studies) {
        const arr = Array.isArray(studies) ? studies.slice() : [];
        const col = this.state.attachStudySortConfig.column;
        if (!col) return arr;

        return arr.sort((a, b) => {
            let valueA;
            let valueB;
            switch (col) {
                case 'patient_name':
                    valueA = (a.patient_name || a.patientName || '').toLowerCase();
                    valueB = (b.patient_name || b.patientName || '').toLowerCase();
                    break;
                case 'patient_id':
                    valueA = String(a.patient_id || a.patientId || '').toLowerCase();
                    valueB = String(b.patient_id || b.patientId || '').toLowerCase();
                    break;
                case 'modality':
                    valueA = (a.modality || '').toLowerCase();
                    valueB = (b.modality || '').toLowerCase();
                    break;
                case 'date':
                    valueA = a.date || a.study_date || a.studyDate || '';
                    valueB = b.date || b.study_date || b.studyDate || '';
                    break;
                default:
                    return 0;
            }
            if (valueA < valueB) {
                return this.state.attachStudySortConfig.direction === 'asc' ? -1 : 1;
            }
            if (valueA > valueB) {
                return this.state.attachStudySortConfig.direction === 'asc' ? 1 : -1;
            }
            return 0;
        });
    },

    applyAttachModalityFilter: function(studies) {
        const filters = this.state.attachStudyModalityFilters;
        if (!filters || filters.length === 0) {
            return Array.isArray(studies) ? studies.slice() : [];
        }
        return (Array.isArray(studies) ? studies : []).filter(study => {
            const studyModality = (study.modality || '').toUpperCase();
            return filters.some(filterModality => {
                const studyModalities = studyModality.split(',').map(m => m.trim()).filter(Boolean);
                return studyModalities.some(mod => mod === String(filterModality).toUpperCase());
            });
        });
    },

    applyAttachStudiesPipeline: function() {
        if (!this.state.attachStudiesAllResults || this.state.attachStudiesAllResults.length === 0) {
            return;
        }
        let list = this.state.attachStudiesAllResults.slice();
        list = this.applyAttachStudyTextFilter(list);
        list = this.applyAttachModalityFilter(list);
        list = this.sortAttachStudiesResults(list);
        this.renderAttachStudiesTable(list);
        this.updateAttachSortIcons();
    },

    applyAttachStudyTextFilter: function(studies) {
        const raw = (document.getElementById('attachStudySearchFilter')?.value || '').trim().toLowerCase();
        if (!raw) {
            return Array.isArray(studies) ? studies.slice() : [];
        }
        return (Array.isArray(studies) ? studies : []).filter(study => {
            const patientName = study.patient_name || study.patientName || '';
            const pid = study.patient_id || study.patientId || '';
            const modality = study.modality || '';
            const studyDescription = study.study_description || study.studyDescription || '';
            const acc = study.accession_number || study.accessionNumber || '';
            return (
                patientName.toLowerCase().includes(raw) ||
                String(pid).toLowerCase().includes(raw) ||
                modality.toLowerCase().includes(raw) ||
                studyDescription.toLowerCase().includes(raw) ||
                String(acc).toLowerCase().includes(raw)
            );
        });
    },

    filterAttachStudiesResultsInPlace: function() {
        if (!this.state.attachStudiesAllResults || this.state.attachStudiesAllResults.length === 0) {
            return;
        }
        this.applyAttachStudiesPipeline();
    },

    searchStudiesForAttach: async function() {
        const validated = this.validateAttachStudySearchParams();
        if (!validated.ok) {
            this.showError(validated.message);
            return;
        }

        try {
            this.setAttachStudySearchButtonLoading(true);

            const params = new URLSearchParams();
            if (validated.dateFrom) params.append('dateFrom', validated.dateFrom);
            if (validated.dateTo) params.append('dateTo', validated.dateTo);
            if (validated.patientId) params.append('patientId', validated.patientId);
            params.append('loadModalities', '1');

            const apiBaseUrl = window.location.pathname.includes('/components/') ? '../api' : 'api';
            const response = await fetch(`${apiBaseUrl}/get_all_studies.php?${params.toString()}`, {
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error('Error al buscar estudios');
            }

            const result = await response.json();
            if (!result.success) {
                throw new Error(result.error || 'Error al buscar estudios');
            }

            this.state.attachStudiesAllResults = result.data || [];
            this.state.attachStudiesListSource = 'fetched';
            this.state.attachStudyModalityFilters = [];
            this.state.attachStudySortConfig = { column: null, direction: 'asc' };
            this.updateAttachModalityButtons();
            this.applyAttachStudiesPipeline();
            this.persistAttachStudiesList();
        } catch (error) {
            console.error('Error buscando estudios:', error);
            this.showError('Error al buscar estudios: ' + error.message);
        } finally {
            this.setAttachStudySearchButtonLoading(false);
        }
    },

    renderAttachStudiesTable: function(studies) {
        const tbody = document.getElementById('attachStudiesTableBody');
        if (!tbody) return;

        this.state.attachStudySearchResults = Array.isArray(studies) ? studies.slice() : [];

        if (this.state.attachStudySearchResults.length === 0) {
            const totalAll = this.state.attachStudiesAllResults.length;
            const src = this.state.attachStudiesListSource;
            let emptyMsg;
            if (totalAll === 0) {
                if (src === 'fetched') {
                    emptyMsg = '<div>No se encontraron estudios en el PACS con los criterios indicados.</div>';
                } else {
                    emptyMsg = '<div>Use filtros y pulse <strong>Buscar estudios</strong>.</div>';
                }
            } else {
                emptyMsg = '<i class="fas fa-filter fa-2x mb-2 d-block"></i><div>Ningún estudio coincide con los filtros (texto o modalidad).</div>';
            }
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        ${emptyMsg}
                    </td>
                </tr>
            `;
            const cnt = document.getElementById('attachStudyCount');
            if (cnt) {
                cnt.textContent = totalAll > 0 ? `0 de ${totalAll} estudios` : '0 estudios';
            }
            return;
        }

        const cntEl = document.getElementById('attachStudyCount');
        const totalAll = this.state.attachStudiesAllResults.length;
        const shown = this.state.attachStudySearchResults.length;
        if (cntEl) {
            cntEl.textContent = (totalAll > shown)
                ? `${shown} de ${totalAll} estudios`
                : `${shown} estudios`;
        }

        const escAttr = (v) => String(v ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        tbody.innerHTML = this.state.attachStudySearchResults.map((study, index) => {
            const patientName = study.patient_name || study.patientName || 'N/A';
            const patientIdVal = study.patient_id || study.patientId || 'N/A';
            const modality = study.modality || 'N/A';
            const studyDate = study.date || study.study_date || study.studyDate || null;

            let formattedDate = '-';
            if (studyDate) {
                try {
                    if (String(studyDate).length === 8 && /^\d{8}$/.test(String(studyDate))) {
                        const y = String(studyDate).substring(0, 4);
                        const m = String(studyDate).substring(4, 6);
                        const d = String(studyDate).substring(6, 8);
                        formattedDate = `${d}/${m}/${y}`;
                    } else {
                        const dateObj = new Date(studyDate);
                        if (!isNaN(dateObj.getTime())) {
                            formattedDate = dateObj.toLocaleDateString('es-ES');
                        }
                    }
                } catch (err) {
                    console.warn('Error formateando fecha:', err);
                }
            }

            const safeName = this.escapeHtml(patientName);
            const safePid = this.escapeHtml(String(patientIdVal));
            const safeMod = this.escapeHtml(modality);
            const safeDate = this.escapeHtml(formattedDate);
            return `
                <tr>
                    <td style="min-width:0"><span class="d-inline-block text-truncate w-100" title="${escAttr(patientName)}">${safeName}</span></td>
                    <td class="d-none d-md-table-cell" style="min-width:0"><span class="d-inline-block text-truncate w-100" title="${escAttr(String(patientIdVal))}">${safePid}</span></td>
                    <td class="text-center align-middle"><span class="badge bg-info text-wrap text-start" style="max-width: 100%; white-space: normal;">${safeMod}</span></td>
                    <td class="d-none d-lg-table-cell text-nowrap">${safeDate}</td>
                    <td class="text-center pe-1">
                        <button type="button" class="btn btn-sm btn-primary select-study-for-attach text-nowrap" data-attach-index="${index}">
                            <i class="fas fa-check me-1"></i>Seleccionar
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    },

    selectStudyForAttach: function(study) {
        const patientName = study.patient_name || study.patientName || '-';
        const patientIdVal = study.patient_id || study.patientId || '-';
        const modality = study.modality || '-';
        const studyDescription = study.study_description || study.studyDescription || '-';

        this.state.attachSelectedStudy = study;

        const elName = document.getElementById('attachSelectedPatientName');
        const elId = document.getElementById('attachSelectedPatientId');
        const elMod = document.getElementById('attachSelectedModality');
        const elDesc = document.getElementById('attachSelectedStudyDescription');
        const elInfo = document.getElementById('attachSelectedStudyInfo');
        if (elName) elName.textContent = patientName;
        if (elId) elId.textContent = patientIdVal;
        if (elMod) elMod.textContent = modality;
        if (elDesc) elDesc.textContent = studyDescription;
        if (elInfo) elInfo.style.display = 'block';

        document.querySelectorAll('#attachStudiesTableBody tr').forEach(row => {
            row.classList.remove('table-active');
        });
        this.updateAttachPdfButtonState();
    },

    updateAttachPdfButtonState: function() {
        const btn = document.getElementById('btnConfirmAttachPdf');
        if (!btn) return;
        const hasStudy = !!this.state.attachSelectedStudy;
        const hasFile = document.getElementById('attachPdfFile')?.files?.length > 0;
        btn.disabled = !(hasStudy && hasFile);
    },

    confirmAttachPdf: async function() {
        const study = this.state.attachSelectedStudy;
        const fileInput = document.getElementById('attachPdfFile');
        const title = document.getElementById('attachPdfTitle')?.value || '';

        if (!study) {
            this.showError('Debes seleccionar un estudio');
            return;
        }
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            this.showError('Debes seleccionar un archivo PDF');
            return;
        }

        try {
            this.showLoading(true);

            const formData = new FormData();
            formData.append('pdf_file', fileInput.files[0]);

            const estudioId = study.study_instance_uid || study.studyInstanceUID || study.study_id || study.studyId || study.id;
            const patientId = study.patient_id || study.patientId || '';
            const patientName = study.patient_name || study.patientName || '';
            const modality = study.modality || '';
            const studyDescription = study.study_description || study.studyDescription || '';
            const studyInstanceUID = study.study_instance_uid || study.studyInstanceUID || '';
            const studyIdValue = study.study_id || study.studyId || study.id || '';
            const accessionNumber = study.accession_number || study.accessionNumber || '';

            formData.append('estudio_id', estudioId);
            formData.append('patient_id', patientId);
            formData.append('patient_name', patientName);
            formData.append('modality', modality);
            formData.append('study_description', studyDescription);
            formData.append('study_instance_uid', studyInstanceUID);
            formData.append('study_id', studyIdValue);
            formData.append('accession_number', accessionNumber);
            if (title) {
                formData.append('titulo', title);
            }

            const sessionToken = this.getSessionToken();
            if (!sessionToken) {
                throw new Error('No se encontró token de sesión');
            }

            const response = await fetch(`${this.config.apiBaseUrl}/attach-pdf.php`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${sessionToken}`
                },
                body: formData
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: 'Error desconocido' }));
                throw new Error(errorData.message || 'Error al adjuntar el informe');
            }

            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Error al adjuntar el informe');
            }

            this.showSuccess('Informe PDF adjuntado exitosamente');

            const modalEl = document.getElementById('attachPdfModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            await this.loadReports(this.state.currentPage || 1);
            if (this.state.canViewInformesRecibidos &&
                typeof window.InformesRecibidosModal !== 'undefined' &&
                typeof window.InformesRecibidosModal.refreshPendientesCount === 'function') {
                window.InformesRecibidosModal.refreshPendientesCount();
            }
        } catch (error) {
            console.error('Error adjuntando PDF:', error);
            this.showError('Error al adjuntar el informe: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    },

    clearAttachPdfForm: function() {
        this.state.attachSelectedStudy = null;
        this.state.attachStudySearchResults = [];
        this.state.attachStudiesAllResults = [];
        this.state.attachStudiesListSource = null;
        this.state.attachStudySortConfig = { column: null, direction: 'asc' };
        this.state.attachStudyModalityFilters = [];
        try {
            sessionStorage.removeItem(this.config.attachStudiesSessionKey);
        } catch (e) { /* ignore */ }
        this.resetAttachPdfModalFields();
        this.updateAttachModalityButtons();
        this.updateAttachSortIcons();
        this.resetAttachStudiesTableToInitialMessage();
    },

    // ═══════════════════════════════════════════════════════════════════════
    // ADJUNTAR GENERAL (audio/informe) — funciones nuevas
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Verifica si el usuario tiene permiso adjuntar_audios o all.
     */
    checkAttachAudiosPermission: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/')
                ? '../api/auth' : 'api/auth';
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    if (typeof permisos === 'string') {
                        try { permisos = JSON.parse(permisos); } catch(e) { permisos = [permisos]; }
                    }
                    this.state.canAttachAudios = permisos.includes('adjuntar_audios') || permisos.includes('all');
                    return this.state.canAttachAudios;
                }
            }
        } catch (e) {
            console.error('Error verificando permiso adjuntar_audios:', e);
        }
        this.state.canAttachAudios = false;
        return false;
    },

    getAiInformesApiUrl: function() {
        return window.location.pathname.includes('/components/') ? '../api/ai-informes.php' : 'api/ai-informes.php';
    },

    getAudiosApiUrl: function() {
        return window.location.pathname.includes('/components/') ? '../api/audios' : 'api/audios';
    },

    audiosColumnStatusBadgeHtml: function(informe) {
        const n = parseInt(informe.total_audios, 10) || 0;
        if (n <= 0) {
            return '';
        }

        const status = informe.audios_tx_status || 'ok';
        if (status === 'stuck') {
            const stuckCount = parseInt(informe.audios_tx_stuck, 10) || 1;
            return `<span class="badge bg-danger" title="Posible servidor de transcripción colgado (${stuckCount} audio${stuckCount !== 1 ? 's' : ''})">
                <i class="fas fa-exclamation-triangle me-1"></i>TX colgada
            </span>`;
        }
        if (status === 'failed') {
            return `<span class="badge bg-danger" title="Transcripción fallida">
                <i class="fas fa-times me-1"></i>TX fallida
            </span>`;
        }
        if (status === 'pending') {
            return `<span class="badge bg-warning text-dark" title="Transcripción en curso">
                <i class="fas fa-spinner fa-spin me-1"></i>Transcribiendo
            </span>`;
        }
        if (status === 'partial') {
            const transcribed = parseInt(informe.audios_transcribed, 10) || 0;
            return `<span class="badge bg-warning text-dark" title="${transcribed} de ${n} audio(s) transcrito(s)">
                ${transcribed}/${n} TX
            </span>`;
        }
        return '';
    },

    audiosColumnHtml: function(informe) {
        const total = parseInt(informe.total_audios, 10) || 0;
        return `
            <div class="d-flex align-items-center flex-wrap gap-1" data-tx-audios-cell="${informe.id}">
                <i class="fas fa-microphone text-primary me-1"></i>
                <span class="badge ${total > 0 ? 'bg-success' : 'bg-secondary'}">
                    ${total} audio${total !== 1 ? 's' : ''}
                </span>
                ${this.audiosColumnStatusBadgeHtml(informe)}
                ${this.audiosColumnDownloadButtonHtml(informe)}
            </div>`;
    },

    syncTranscriptionStatusPending: function(informes) {
        const next = new Set();
        (informes || []).forEach((inf) => {
            const status = inf.audios_tx_status;
            if (status === 'pending' || status === 'stuck' || status === 'failed') {
                next.add(inf.id);
            }
        });
        this.state.txStatusPending = next;
        if (next.size > 0) {
            this.startTranscriptionStatusPolling();
        } else {
            this.stopTranscriptionStatusPolling();
        }
    },

    startTranscriptionStatusPolling: function() {
        if (this.state.txStatusInterval) {
            return;
        }
        if (this.state.txStatusPending.size === 0) {
            return;
        }
        this.state.txStatusInterval = setInterval(() => {
            this.pollTranscriptionStatus();
        }, 30000);
        this.pollTranscriptionStatus();
    },

    stopTranscriptionStatusPolling: function() {
        if (this.state.txStatusInterval) {
            clearInterval(this.state.txStatusInterval);
            this.state.txStatusInterval = null;
        }
    },

    pollTranscriptionStatus: async function() {
        if (this.state.txStatusPending.size === 0) {
            this.stopTranscriptionStatusPolling();
            return;
        }

        const ids = Array.from(this.state.txStatusPending).slice(0, 50);
        const token = this.getSessionToken();

        try {
            const response = await fetch(`${this.getAiInformesApiUrl()}?action=informe-transcription-status&informe_ids=${ids.join(',')}`, {
                method: 'GET',
                headers: {
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                return;
            }

            const result = await response.json();
            if (!result.success || !result.status_by_informe) {
                return;
            }

            Object.keys(result.status_by_informe).forEach((informeIdKey) => {
                const txData = result.status_by_informe[informeIdKey];
                const informeId = parseInt(informeIdKey, 10);
                if (!informeId) {
                    return;
                }

                const informe = (this.state.reports || []).find((r) => parseInt(r.id, 10) === informeId);
                if (informe) {
                    informe.audios_transcribed = txData.audios_transcribed;
                    informe.audios_tx_pending = txData.audios_tx_pending;
                    informe.audios_tx_stuck = txData.audios_tx_stuck;
                    informe.audios_tx_failed = txData.audios_tx_failed;
                    informe.audios_tx_status = txData.audios_tx_status;
                }

                this.updateInformeTxStatusBadge(informeId, txData);

                const status = txData.audios_tx_status;
                if (status === 'ok' || status === 'partial' || status === 'unknown') {
                    this.state.txStatusPending.delete(informeId);
                }
            });

            if (this.state.txStatusPending.size === 0) {
                this.stopTranscriptionStatusPolling();
            }
        } catch (error) {
            console.warn('Error en polling de transcripción:', error);
        }
    },

    updateInformeTxStatusBadge: function(informeId, txData) {
        const informe = (this.state.reports || []).find((r) => parseInt(r.id, 10) === parseInt(informeId, 10));
        if (informe && txData) {
            Object.assign(informe, txData);
        }
        if (!informe) {
            return;
        }

        const cells = document.querySelectorAll(`[data-tx-audios-cell="${informeId}"]`);
        cells.forEach((cell) => {
            cell.outerHTML = this.audiosColumnHtml(informe).trim();
        });
    },

    requeueTxButtonHtml: function(audioId, locked) {
        const id = parseInt(audioId, 10) || 0;
        const isBusy = !!locked || this.state.requeuingAudioIds.has(id);
        const title = isBusy
            ? 'Esperá unos segundos antes de reintentar'
            : 'Reintentar transcripción';
        const icon = isBusy
            ? '<i class="fas fa-spinner fa-spin"></i>'
            : '<i class="fas fa-rotate-right"></i>';
        return `<button type="button" id="btn-requeue-tx-${id}" class="btn btn-sm btn-outline-warning ms-1"
                    onclick="InformesManager.requeueAudioTranscription(${id})"
                    title="${title}" ${isBusy ? 'disabled' : ''}>${icon}</button>`;
    },

    isAudioRequeueLocked: function(audioId) {
        const id = parseInt(audioId, 10);
        if (!id) {
            return false;
        }
        if (this.state.requeuingAudioIds.has(id)) {
            return true;
        }
        const until = this.state.requeueCooldownUntil[id] || 0;
        return until > Date.now();
    },

    /**
     * Bloquea el botón de reintento (en vuelo + cooldown).
     * @param {number} audioId
     * @param {number} cooldownMs default 45s — reactiva si sigue sin TX
     */
    lockAudioRequeueButton: function(audioId, cooldownMs) {
        const id = parseInt(audioId, 10);
        if (!id) {
            return;
        }
        const ms = typeof cooldownMs === 'number' ? cooldownMs : 45000;
        this.state.requeuingAudioIds.add(id);
        this.state.requeueCooldownUntil[id] = Date.now() + ms;
        this.setAudioRequeueButtonBusy(id, true);

        if (this.state.requeueCooldownTimers[id]) {
            clearTimeout(this.state.requeueCooldownTimers[id]);
        }
        this.state.requeueCooldownTimers[id] = setTimeout(() => {
            this.unlockAudioRequeueButton(id, true);
        }, ms);
    },

    /** Tras respuesta OK: sale del “en vuelo” pero mantiene cooldown anti-spam. */
    finishAudioRequeueInFlight: function(audioId) {
        const id = parseInt(audioId, 10);
        if (!id) {
            return;
        }
        this.state.requeuingAudioIds.delete(id);
        if (this.isAudioRequeueLocked(id)) {
            this.setAudioRequeueButtonBusy(id, true);
        }
    },

    /**
     * @param {number} audioId
     * @param {boolean} onlyIfStillUntranscribed si true y ya hay texto, no reactivar
     */
    unlockAudioRequeueButton: function(audioId, onlyIfStillUntranscribed) {
        const id = parseInt(audioId, 10);
        if (!id) {
            return;
        }
        this.state.requeuingAudioIds.delete(id);
        delete this.state.requeueCooldownUntil[id];
        if (this.state.requeueCooldownTimers[id]) {
            clearTimeout(this.state.requeueCooldownTimers[id]);
            delete this.state.requeueCooldownTimers[id];
        }

        const audio = (this.state.editModalAudios || []).find(a => parseInt(a.id, 10) === id);
        const hasTx = !!(audio && audio.transcripcion && String(audio.transcripcion).trim() !== '');
        if (onlyIfStillUntranscribed && hasTx) {
            return;
        }
        this.setAudioRequeueButtonBusy(id, false);
    },

    setAudioRequeueButtonBusy: function(audioId, busy) {
        const btn = document.getElementById(`btn-requeue-tx-${audioId}`);
        if (!btn) {
            return;
        }
        btn.disabled = !!busy;
        btn.title = busy
            ? 'Esperá unos segundos antes de reintentar'
            : 'Reintentar transcripción';
        btn.innerHTML = busy
            ? '<i class="fas fa-spinner fa-spin"></i>'
            : '<i class="fas fa-rotate-right"></i>';
    },

    audioAlreadyTranscribed: function(audioId) {
        const id = parseInt(audioId, 10);
        const audio = (this.state.editModalAudios || []).find(a => parseInt(a.id, 10) === id);
        if (!audio) {
            return false;
        }
        if (audio.transcripcion && String(audio.transcripcion).trim() !== '') {
            return true;
        }
        if (audio.tx_status === 'completed' || audio.transcription_status === 'completed') {
            return true;
        }
        return false;
    },

    requeueAudioTranscription: async function(audioId) {
        const id = parseInt(audioId, 10);
        if (!id) {
            return;
        }

        // Ya transcripto: no reenviar
        if (this.audioAlreadyTranscribed(id)) {
            this.showToast('Este audio ya tiene transcripción; no se vuelve a enviar', 'info');
            return;
        }

        if (this.isAudioRequeueLocked(id)) {
            this.showToast('Reintento en curso. Esperá unos segundos…', 'info');
            return;
        }

        const token = this.getSessionToken();
        this.lockAudioRequeueButton(id, 45000);

        try {
            const response = await fetch(`${this.getAudiosApiUrl()}/requeue-transcription.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                body: JSON.stringify({ audio_ids: [id] })
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.error || 'No se pudo reencolar la transcripción');
            }

            const details = Array.isArray(result.details) ? result.details : [];
            const detail = details.find(d => parseInt(d.audio_id, 10) === id) || details[0];
            const detailResult = detail?.result || '';

            if (detailResult === 'skipped_completed') {
                this.unlockAudioRequeueButton(id, false);
                this.showToast('Este audio ya tiene transcripción; no se vuelve a enviar', 'info');
                return;
            }

            if ((result.requeued || 0) === 0) {
                // Ya en cola: mantener cooldown breve para no spamear
                this.finishAudioRequeueInFlight(id);
                this.showToast('La transcripción ya está en cola', 'info');
                return;
            }
            if (result.warning) {
                this.showToast(result.warning, 'warning');
            }
            this.showToast('Audio reencolado para transcripción', 'success');
            this.finishAudioRequeueInFlight(id);

            const reportId = this.state.currentReportId || this.state.currentReport?.id;
            if (reportId) {
                const estudioId = this.state.currentReport?.estudio_id || this.state.currentReport?.study_id || null;
                await this.loadEditModalAudios(reportId, estudioId);
            }
            if (this.state.reports && this.state.reports.length) {
                this.syncTranscriptionStatusPending(this.state.reports);
            }
            // El cooldown (45s) reactivará el botón si sigue sin TX
        } catch (error) {
            console.error('Error reencolando transcripción:', error);
            this.unlockAudioRequeueButton(id, false);
            this.showToast(error.message || 'Error al reencolar transcripción', 'danger');
            this.refreshTranscriptionHealthBadge();
        }
    },

    ensureTranscriptionHealthBadge: function() {
        let el = document.getElementById('transcriptionHealthBadge');
        if (el) {
            return el;
        }
        const host = document.getElementById('clearFiltersBtn')?.closest('.d-flex')
            || document.getElementById('clearFiltersBtn')?.parentElement
            || document.querySelector('.informes-filters-collapse-btn')?.parentElement;
        if (!host) {
            return null;
        }
        el = document.createElement('span');
        el.id = 'transcriptionHealthBadge';
        el.className = 'badge text-bg-secondary ms-2 align-middle';
        el.style.fontWeight = '500';
        el.title = 'Estado del servidor de transcripción (Whisper)';
        el.textContent = 'TX…';
        host.appendChild(el);
        return el;
    },

    refreshTranscriptionHealthBadge: async function() {
        const el = this.ensureTranscriptionHealthBadge();
        if (!el) {
            return;
        }
        try {
            let health;
            if (window.TranscriptionHealth && typeof window.TranscriptionHealth.fetch === 'function') {
                health = await window.TranscriptionHealth.fetch({ token: this.getSessionToken() });
            } else {
                const apiBase = window.location.pathname.includes('/components/')
                    ? '../api/ai-informes.php'
                    : 'api/ai-informes.php';
                const token = this.getSessionToken();
                const resp = await fetch(`${apiBase}?action=transcription-health`, {
                    headers: {
                        Accept: 'application/json',
                        ...(token ? { Authorization: `Bearer ${token}` } : {})
                    }
                });
                health = await resp.json();
                health.allow_enqueue = health.allow_enqueue === true
                    || (health.ready === true && ['ok', 'degraded'].includes(String(health.status || '').toLowerCase()));
                health.degraded = String(health.status || '').toLowerCase() === 'degraded' || !!health.degraded;
            }
            const status = String(health.status || 'down').toLowerCase();
            if (health.allow_enqueue && status === 'degraded') {
                el.className = 'badge text-bg-warning ms-2 align-middle';
                el.textContent = 'TX degradado';
            } else if (health.allow_enqueue) {
                el.className = 'badge text-bg-success ms-2 align-middle';
                el.textContent = 'TX OK';
            } else {
                el.className = 'badge text-bg-danger ms-2 align-middle';
                el.textContent = 'TX caído';
            }
            el.title = health.message || el.textContent;
        } catch (e) {
            el.className = 'badge text-bg-secondary ms-2 align-middle';
            el.textContent = 'TX ?';
            el.title = e.message || 'No se pudo consultar health';
        }
    },

    /**
     * HTML del botón para descargar audios adjuntos del informe en MP3 (columna Audios).
     */
    audiosColumnDownloadButtonHtml: function(informe) {
        const n = parseInt(informe.total_audios, 10) || 0;
        if (!this.state.canDownloadInformeAudios || n <= 0 || !informe.id) {
            return '';
        }
        const rid = parseInt(informe.id, 10);
        if (!rid) {
            return '';
        }
        const pidEnc = encodeURIComponent(String(informe.patient_id || informe.paciente_id || '').trim());
        return `
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Descargar audios adjuntos (MP3)"
                    onclick='event.stopPropagation(); InformesManager.downloadInformeAudiosAsMp3FromGrid(${rid}, decodeURIComponent("${pidEnc}"));' aria-label="Descargar audios MP3">
                <i class="fas fa-download" style="font-size: 0.75rem;"></i>
            </button>`;
    },

    /**
     * Permiso descargar_audios_informe o all (root suele tener all).
     */
    checkDownloadInformeAudiosPermission: async function() {
        try {
            const apiBaseUrl = window.location.pathname.includes('/components/')
                ? '../api/auth' : 'api/auth';
            const response = await fetch(`${apiBaseUrl}/validate-session-simple.php`, {
                credentials: 'include'
            });
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.user) {
                    let permisos = result.user.permisos || [];
                    if (typeof permisos === 'string') {
                        try { permisos = JSON.parse(permisos); } catch (e) { permisos = [permisos]; }
                    }
                    const nivel = String(result.user.nivel || '').toLowerCase();
                    this.state.canDownloadInformeAudios = nivel === 'root'
                        || permisos.includes('descargar_audios_informe')
                        || permisos.includes('all');
                    return this.state.canDownloadInformeAudios;
                }
            }
        } catch (e) {
            console.error('Error verificando permiso descargar_audios_informe:', e);
        }
        this.state.canDownloadInformeAudios = false;
        return false;
    },

    /**
     * Nombre sugerido para descarga MP3: si hay ID paciente, sustituye el tramo tras el último "_" (p. ej. timestamp) por ese ID.
     */
    buildMp3DownloadFilenameFromAudio: function(originalBase, patientIdOpt) {
        const raw = (originalBase || 'audio').replace(/[/\\\\?%*:|"<>]/g, '_');
        const stem = raw.replace(/\.[^.]+$/i, '');
        const pid = String(patientIdOpt || '').trim().replace(/[/\\\\?%*:|"<>\\s]/g, '_');
        if (pid) {
            const u = stem.lastIndexOf('_');
            const newStem = u >= 0 ? stem.slice(0, u + 1) + pid : stem + '_' + pid;
            return newStem + '.mp3';
        }
        return raw.toLowerCase().endsWith('.mp3') ? raw : stem + '.mp3';
    },

    /**
     * Descarga cada audio adjunto del informe vía API (MP3 o conversión con ffmpeg-rest).
     * @param {number} informeId
     * @param {string} [patientIdOpt] ID paciente desde la grilla (paciente_id / patient_id)
     */
    downloadInformeAudiosAsMp3FromGrid: async function(informeId, patientIdOpt) {
        if (!this.state.canDownloadInformeAudios) {
            this.showToast('No tiene permiso para descargar audios de informes', 'warning');
            return;
        }
        const token = this.getSessionToken();
        if (!token) {
            this.showToast('Sesión no válida', 'error');
            return;
        }
        const prefix = window.location.pathname.includes('/components/') ? '../' : '';
        const listUrl = `${prefix}get-audios-root.php?informe_id=${encodeURIComponent(informeId)}`;
        try {
            this.showToast('Obteniendo lista de audios…', 'info');
            const listRes = await fetch(listUrl, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            if (!listRes.ok) {
                throw new Error(`Error ${listRes.status} al listar audios`);
            }
            const listData = await listRes.json();
            if (!listData.success) {
                throw new Error(listData.message || listData.error || 'No se pudieron listar los audios');
            }
            const audios = this.parseAudiosFromGetAudiosRootResponse(listData);
            const usable = audios.filter(a => a && a.archivo_existe !== false && a.id);
            if (usable.length === 0) {
                this.showToast('No hay archivos de audio disponibles para este informe', 'warning');
                return;
            }
            const apiUrl = `${prefix}api/ai-informes.php?action=download-audio-mp3`;
            let okCount = 0;
            for (let i = 0; i < usable.length; i++) {
                const a = usable[i];
                const audioId = parseInt(a.id, 10);
                const dlRes = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ audio_id: audioId })
                });
                const ct = (dlRes.headers.get('content-type') || '').toLowerCase();
                if (!dlRes.ok) {
                    let msg = `No se pudo descargar el audio #${audioId}`;
                    try {
                        const errJson = await dlRes.json();
                        msg = errJson.message || errJson.error || msg;
                    } catch (e) { /* ignore */ }
                    this.showToast(msg, 'error');
                    continue;
                }
                if (ct.includes('application/json')) {
                    try {
                        const errJson = await dlRes.json();
                        this.showToast(errJson.message || errJson.error || 'Error al generar MP3', 'error');
                    } catch (e) {
                        this.showToast('Error al generar MP3', 'error');
                    }
                    continue;
                }
                const blob = await dlRes.blob();
                const baseName = (a.nombre_original || a.nombre_archivo || `informe_${informeId}_audio_${audioId}`).replace(/[/\\\\?%*:|"<>]/g, '_');
                const fileName = this.buildMp3DownloadFilenameFromAudio(baseName, patientIdOpt);
                const url = window.URL.createObjectURL(blob);
                const anchor = document.createElement('a');
                anchor.href = url;
                anchor.download = fileName;
                document.body.appendChild(anchor);
                anchor.click();
                document.body.removeChild(anchor);
                window.URL.revokeObjectURL(url);
                okCount++;
                if (i < usable.length - 1) {
                    await new Promise(r => setTimeout(r, 450));
                }
            }
            if (okCount > 0) {
                this.showToast(okCount === usable.length
                    ? `Descarga completada (${okCount} archivo${okCount !== 1 ? 's' : ''})`
                    : `Descargados ${okCount} de ${usable.length} archivo(s)`, okCount === usable.length ? 'success' : 'warning');
            }
        } catch (err) {
            console.error('downloadInformeAudiosAsMp3FromGrid:', err);
            this.showToast(err.message || 'Error al descargar audios', 'error');
        }
    },

    /**
     * Muestra u oculta el botón "Adjuntar audio/informe" según permisos.
     * El botón se muestra si el usuario puede adjuntar audios O informes PDF.
     */
    updateAdjuntarGeneralButtonVisibility: function() {
        const btn = document.getElementById('btnAdjuntarGeneral');
        if (!btn) return;
        const canShow = !!(this.state.canAttachAudios || this.state.canAttachReports);
        btn.style.display = canShow ? '' : 'none';
    },

    /**
     * Abre el modal unificado de adjuntar audio/informe.
     * Enlaza eventos la primera vez (lazy binding).
     */
    showAdjuntarGeneralModal: function() {
        const modalEl = document.getElementById('adjuntarGeneralModal');
        if (!modalEl) { this.showError('Modal de adjuntar no encontrado'); return; }
        if (typeof bootstrap === 'undefined') { this.showError('Bootstrap no disponible'); return; }

        // Inicializar estado si es la primera vez
        if (!('adjModalType' in this.state)) {
            this.state.adjModalType = 'audio';
            this.state.adjModalDestTab = 'informe';
            this.state.adjModalSelectedInforme = null;
            this.state.adjModalSelectedStudy = null;
            this.state.adjModalPacsStudies = [];

            // Lazy bind: file input change
            const fileInp = document.getElementById('adjModalFileInput');
            if (fileInp) {
                fileInp.addEventListener('change', (ev) => {
                    InformesManager.adjModalHandleFileSelect(ev.target.files[0] || null);
                });
            }
            // Lazy bind: tipo radio change
            document.querySelectorAll('input[name="adjModalType"]').forEach(inp => {
                inp.addEventListener('change', () => InformesManager.adjModalSwitchType(inp.value));
            });
            // Lazy bind: buscar informes con Enter
            const inforPatient = document.getElementById('adjModalInformePatient');
            if (inforPatient) {
                inforPatient.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter') { ev.preventDefault(); InformesManager.adjModalSearchInformes(); }
                });
            }
            // Lazy bind: buscar PACS con Enter en patientId
            const pacsPatient = document.getElementById('adjModalPacsPatientId');
            if (pacsPatient) {
                pacsPatient.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter') { ev.preventDefault(); InformesManager.adjModalSearchPacs(); }
                });
            }
        }

        this.adjModalReset();

        let modal = bootstrap.Modal.getInstance(modalEl);
        if (!modal) modal = new bootstrap.Modal(modalEl);
        modal.show();
    },

    /**
     * Resetea todos los campos del modal a su estado inicial.
     */
    adjModalReset: function() {
        this.state.adjModalType = 'audio';
        this.state.adjModalDestTab = 'informe';
        this.state.adjModalSelectedInforme = null;
        this.state.adjModalSelectedStudy = null;
        this.state.adjModalPacsStudies = [];

        // Radio: Audio por defecto
        const radAudio = document.getElementById('adjModalTypeAudio');
        if (radAudio) radAudio.checked = true;

        // Limpiar archivo
        const fi = document.getElementById('adjModalFileInput');
        if (fi) fi.value = '';
        const prev = document.getElementById('adjModalFilePreview');
        if (prev) prev.style.display = 'none';
        const titleInp = document.getElementById('adjModalTitle');
        if (titleInp) titleInp.value = '';

        // Resetear labels de audio
        this._adjModalApplyTypeUI('audio');

        // Resetear tab a Informe
        this._adjModalShowTab('informe');

        // Limpiar campos de búsqueda de informes
        ['adjModalInformeDateFrom','adjModalInformeDateTo','adjModalInformePatient'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const tbody = document.getElementById('adjModalInformesTableBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3"><i class="fas fa-search me-1"></i>Ingrese criterios y pulse Buscar.</td></tr>';

        // Limpiar campos de búsqueda PACS
        ['adjModalPacsDateFrom','adjModalPacsDateTo','adjModalPacsPatientId'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const ptbody = document.getElementById('adjModalPacsTableBody');
        if (ptbody) ptbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-search me-1"></i>Use filtros y pulse Buscar estudios.</td></tr>';
        const pcnt = document.getElementById('adjModalPacsCount');
        if (pcnt) pcnt.textContent = '0 estudios';

        // Ocultar info de destino
        const dInfo = document.getElementById('adjModalDestInfo');
        if (dInfo) dInfo.style.display = 'none';

        // Deshabilitar confirmar
        this.adjModalUpdateConfirmButton();
    },

    /**
     * Cambia el tipo de adjunto (audio | pdf) y actualiza la UI.
     */
    adjModalSwitchType: function(type) {
        this.state.adjModalType = type;
        this.state.adjModalSelectedInforme = null;
        this.state.adjModalSelectedStudy = null;
        this._adjModalApplyTypeUI(type);
        this.adjModalUpdateConfirmButton();

        // Ambos tipos soportan las dos pestañas
        const tabInformeItem = document.getElementById('adjModalTabInformeItem');
        if (tabInformeItem) tabInformeItem.style.display = '';

        // Limpiar info destino al cambiar tipo
        const dInfo = document.getElementById('adjModalDestInfo');
        if (dInfo) dInfo.style.display = 'none';
    },

    _adjModalApplyTypeUI: function(type) {
        const isAudio = (type === 'audio');
        const cardTitle  = document.getElementById('adjModalFileCardTitle');
        const fileLabel  = document.getElementById('adjModalFileLabel');
        const fileHint   = document.getElementById('adjModalFileHint');
        const fileIcon   = document.getElementById('adjModalFileIcon');
        const typeBadge  = document.getElementById('adjModalTypeBadge');
        const fileInp    = document.getElementById('adjModalFileInput');

        if (isAudio) {
            if (cardTitle) cardTitle.textContent = 'Paso 1: Seleccionar audio';
            if (fileLabel) fileLabel.textContent = 'Archivo de audio';
            if (fileHint)  fileHint.textContent  = 'Formatos: MP3, WAV, M4A, WebM, OGG, FLAC, AAC. Máximo 50 MB.';
            if (fileIcon)  { fileIcon.className = ''; fileIcon.classList.add('fas','fa-music','me-1'); }
            if (typeBadge) typeBadge.textContent = 'Audio: MP3, WAV, M4A, WebM, OGG, FLAC · Máx. 50 MB';
            if (fileInp)   fileInp.accept = 'audio/*,.mp3,.wav,.m4a,.webm,.ogg,.flac,.aac';
        } else {
            if (cardTitle) cardTitle.textContent = 'Paso 1: Seleccionar PDF';
            if (fileLabel) fileLabel.textContent = 'Archivo PDF';
            if (fileHint)  fileHint.textContent  = 'Solo archivos PDF. Máximo 50 MB.';
            if (fileIcon)  { fileIcon.className = ''; fileIcon.classList.add('fas','fa-file-pdf','me-1','text-danger'); }
            if (typeBadge) typeBadge.textContent = 'PDF: máx. 50 MB';
            if (fileInp)   fileInp.accept = '.pdf,application/pdf';
        }

        // Limpiar file input y preview al cambiar tipo
        if (fileInp) fileInp.value = '';
        const prev = document.getElementById('adjModalFilePreview');
        if (prev) prev.style.display = 'none';
    },

    /**
     * Cambia entre tab "informe" y "pacs".
     */
    adjModalSwitchTab: function(tab) {
        this.state.adjModalDestTab = tab;
        this._adjModalShowTab(tab);
        this.state.adjModalSelectedInforme = null;
        this.state.adjModalSelectedStudy = null;
        const dInfo = document.getElementById('adjModalDestInfo');
        if (dInfo) dInfo.style.display = 'none';
        this.adjModalUpdateConfirmButton();
    },

    _adjModalShowTab: function(tab) {
        const panelInforme = document.getElementById('adjModalTabInforme');
        const panelPacs    = document.getElementById('adjModalTabPacs');
        const btnInforme   = document.getElementById('adjModalTabInformeBtn');
        const btnPacs      = document.getElementById('adjModalTabPacsBtn');

        const isInforme = (tab === 'informe');
        if (panelInforme) { panelInforme.classList.toggle('d-none', !isInforme); }
        if (panelPacs)    { panelPacs.classList.toggle('d-none',  isInforme); }
        if (btnInforme)   { btnInforme.classList.toggle('active', isInforme); }
        if (btnPacs)      { btnPacs.classList.toggle('active',    !isInforme); }
    },

    /**
     * Maneja la selección de archivo (audio o PDF).
     */
    adjModalHandleFileSelect: function(file) {
        const prev = document.getElementById('adjModalFilePreview');
        const inp  = document.getElementById('adjModalFileInput');
        if (!file) {
            if (prev) prev.style.display = 'none';
            this.adjModalUpdateConfirmButton();
            return;
        }

        const type = this.state.adjModalType || 'audio';
        const maxSize = 50 * 1024 * 1024;

        if (type === 'audio') {
            const allowedExt = ['mp3','wav','m4a','webm','mp4','ogg','aac','flac'];
            const ext = file.name.split('.').pop().toLowerCase();
            if (!file.type.startsWith('audio/') && !allowedExt.includes(ext)) {
                this.showError('El archivo debe ser un audio (MP3, WAV, M4A, WebM, OGG, FLAC, AAC)');
                if (inp) inp.value = '';
                this.adjModalUpdateConfirmButton();
                return;
            }
        } else {
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                this.showError('El archivo debe ser un PDF');
                if (inp) inp.value = '';
                this.adjModalUpdateConfirmButton();
                return;
            }
        }

        if (file.size > maxSize) {
            this.showError('El archivo es demasiado grande. Máximo 50 MB');
            if (inp) inp.value = '';
            this.adjModalUpdateConfirmButton();
            return;
        }

        // Mostrar preview
        if (prev) {
            const nameEl = document.getElementById('adjModalFileName');
            const sizeEl = document.getElementById('adjModalFileSize');
            if (nameEl) nameEl.textContent = file.name;
            if (sizeEl) sizeEl.textContent = this._adjFormatBytes(file.size);
            prev.style.display = '';
        }

        this.adjModalUpdateConfirmButton();
    },

    _adjFormatBytes: function(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    },

    /**
     * Actualiza el estado habilitado/deshabilitado del botón confirmar.
     */
    adjModalUpdateConfirmButton: function() {
        const btn        = document.getElementById('adjModalConfirmBtn');
        const labelEl    = document.getElementById('adjModalConfirmLabel');
        const fileInp    = document.getElementById('adjModalFileInput');
        if (!btn) return;

        const hasFile  = !!(fileInp && fileInp.files && fileInp.files.length > 0);
        const hasDest  = !!(this.state.adjModalSelectedInforme || this.state.adjModalSelectedStudy);
        btn.disabled   = !(hasFile && hasDest);

        const type = this.state.adjModalType || 'audio';
        if (labelEl) {
            labelEl.textContent = (type === 'pdf') ? 'Adjuntar informe PDF' : 'Adjuntar audio';
        }
        const iconEl = document.getElementById('adjModalConfirmIcon');
        if (iconEl) {
            iconEl.className = (type === 'pdf') ? 'fas fa-file-pdf me-1' : 'fas fa-music me-1';
        }
    },

    // ─── Búsqueda de informes existentes (Tab A) ─────────────────────────────

    adjModalSearchInformes: async function() {
        const dateFrom  = (document.getElementById('adjModalInformeDateFrom')?.value || '').trim();
        const dateTo    = (document.getElementById('adjModalInformeDateTo')?.value   || '').trim();
        const patient   = (document.getElementById('adjModalInformePatient')?.value  || '').trim();

        if (!dateFrom && !dateTo && !patient) {
            this.showError('Ingrese al menos un criterio: fechas o nombre/ID de paciente');
            return;
        }

        const spinEl = document.getElementById('adjModalInformeSearchSpinner');
        const iconEl = document.getElementById('adjModalInformeSearchIcon');
        const btnEl  = document.getElementById('adjModalInformeSearchBtn');
        if (spinEl) spinEl.classList.remove('d-none');
        if (iconEl) iconEl.style.display = 'none';
        if (btnEl)  btnEl.disabled = true;

        try {
            const params = new URLSearchParams({ page: 1, per_page: 100 });
            if (patient)  params.append('search', patient);
            if (dateFrom) params.append('fecha_inicio', dateFrom);
            if (dateTo)   params.append('fecha_fin', dateTo);

            const token = this.getSessionToken();
            const apiBase = window.location.pathname.includes('/components/') ? '../api/informes' : 'api/informes';
            const resp = await fetch(`${apiBase}/list.php?${params.toString()}`, {
                headers: token ? { 'Authorization': 'Bearer ' + token } : {},
                credentials: 'include'
            });

            if (!resp.ok) throw new Error('Error HTTP ' + resp.status);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Error al buscar informes');

            const informes = (data.data && data.data.informes) ? data.data.informes : [];
            this.adjModalRenderInformesTable(informes);
        } catch (e) {
            console.error('adjModalSearchInformes:', e);
            this.showError('Error al buscar informes: ' + e.message);
        } finally {
            if (spinEl) spinEl.classList.add('d-none');
            if (iconEl) iconEl.style.display = '';
            if (btnEl)  btnEl.disabled = false;
        }
    },

    adjModalRenderInformesTable: function(informes) {
        const tbody = document.getElementById('adjModalInformesTableBody');
        if (!tbody) return;

        if (!informes || informes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No se encontraron informes con esos criterios.</td></tr>';
            return;
        }

        const estadoBadge = (estado) => {
            const map = {
                'borrador':     'secondary',
                'en_revision':  'warning',
                'finalizado':   'success',
                'cancelado':    'danger',
                'incompleto':   'warning',
            };
            const color = map[estado] || 'secondary';
            return `<span class="badge bg-${color}">${estado || '-'}</span>`;
        };

        tbody.innerHTML = informes.map(inf => {
            const id          = inf.id || '-';
            const patient     = this._adjEsc(inf.patient_name || inf.patientName || '-');
            const desc        = this._adjEsc(inf.study_description || inf.studyDescription || inf.titulo || '-');
            const fecha       = inf.fecha_creacion ? inf.fecha_creacion.split(' ')[0] : '-';
            const estado      = estadoBadge(inf.estado);
            return `<tr>
                <td class="text-muted small">#${id}</td>
                <td class="text-truncate" title="${patient}">${patient}</td>
                <td class="text-truncate small" title="${desc}">${desc}</td>
                <td class="small text-nowrap">${fecha}</td>
                <td>${estado}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2"
                        onclick="InformesManager.adjModalSelectInforme(${JSON.stringify(inf).replace(/"/g,'&quot;')})">
                        <i class="fas fa-check me-1"></i>Elegir
                    </button>
                </td>
            </tr>`;
        }).join('');
    },

    adjModalSelectInforme: function(informe) {
        this.state.adjModalSelectedInforme = informe;
        this.state.adjModalSelectedStudy   = null;

        const patient = informe.patient_name || informe.patientName || '-';
        const desc    = informe.study_description || informe.studyDescription || informe.titulo || '-';
        const id      = informe.id || '-';

        const titleEl   = document.getElementById('adjModalDestTitle');
        const detailsEl = document.getElementById('adjModalDestDetails');
        const dInfo     = document.getElementById('adjModalDestInfo');

        if (titleEl)   titleEl.textContent = 'Informe existente seleccionado';
        if (detailsEl) detailsEl.innerHTML =
            `<strong>Informe #${id}</strong> · Paciente: ${this._adjEsc(patient)} · ${this._adjEsc(desc)}`;
        if (dInfo) dInfo.style.display = '';

        this.adjModalUpdateConfirmButton();
    },

    // ─── Búsqueda de estudios PACS (Tab B) ────────────────────────────────────

    adjModalSearchPacs: async function() {
        const dateFrom  = (document.getElementById('adjModalPacsDateFrom')?.value  || '').trim();
        const dateTo    = (document.getElementById('adjModalPacsDateTo')?.value    || '').trim();
        const patientId = (document.getElementById('adjModalPacsPatientId')?.value || '').trim();

        if (!dateFrom && !dateTo && !patientId) {
            this.showError('Indique un rango de fechas o un ID de paciente para buscar estudios');
            return;
        }

        // Validar rango de fechas (máx. 120 días)
        if (dateFrom && dateTo) {
            const d0 = new Date(dateFrom + 'T12:00:00');
            const d1 = new Date(dateTo + 'T12:00:00');
            if (d0 > d1) {
                this.showError('La fecha "Desde" no puede ser posterior a "Hasta"');
                return;
            }
            const days = Math.floor((d1 - d0) / 86400000) + 1;
            if (days > 120) {
                this.showError('El rango no puede superar 120 días. Reduzca el intervalo.');
                return;
            }
        }

        const spinEl = document.getElementById('adjModalPacsSearchSpinner');
        const iconEl = document.getElementById('adjModalPacsSearchIcon');
        const btnEl  = document.getElementById('adjModalPacsSearchBtn');
        if (spinEl) spinEl.classList.remove('d-none');
        if (iconEl) iconEl.style.display = 'none';
        if (btnEl)  btnEl.disabled = true;

        try {
            const params = new URLSearchParams({ loadModalities: '1' });
            if (dateFrom)  params.append('dateFrom', dateFrom);
            if (dateTo)    params.append('dateTo', dateTo);
            if (patientId) params.append('patientId', patientId);

            const apiBase = window.location.pathname.includes('/components/') ? '../api' : 'api';
            const resp = await fetch(`${apiBase}/get_all_studies.php?${params.toString()}`, {
                credentials: 'include'
            });

            if (!resp.ok) throw new Error('Error HTTP ' + resp.status);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Error al buscar estudios');

            const studies = data.data || [];
            this.state.adjModalPacsStudies = studies;
            this.adjModalRenderPacsTable(studies);
        } catch (e) {
            console.error('adjModalSearchPacs:', e);
            this.showError('Error al buscar estudios PACS: ' + e.message);
        } finally {
            if (spinEl) spinEl.classList.add('d-none');
            if (iconEl) iconEl.style.display = '';
            if (btnEl)  btnEl.disabled = false;
        }
    },

    adjModalRenderPacsTable: function(studies) {
        const tbody  = document.getElementById('adjModalPacsTableBody');
        const cntEl  = document.getElementById('adjModalPacsCount');
        if (!tbody) return;
        if (cntEl) cntEl.textContent = studies.length + ' estudio' + (studies.length !== 1 ? 's' : '');

        if (!studies || studies.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No se encontraron estudios.</td></tr>';
            return;
        }

        tbody.innerHTML = studies.map((st, idx) => {
            const patient  = this._adjEsc(st.patient_name  || st.patientName  || '-');
            const patId    = this._adjEsc(st.patient_id    || st.patientId    || '-');
            const modality = this._adjEsc(st.modality      || '-');
            const date     = st.date || st.study_date || st.studyDate || '-';
            return `<tr>
                <td class="text-truncate" title="${patient}">${patient}</td>
                <td class="text-truncate small" title="${patId}">${patId}</td>
                <td class="small">${modality}</td>
                <td class="small text-nowrap">${date}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2"
                        data-adj-pacs-idx="${idx}"
                        onclick="InformesManager.adjModalSelectStudy(${idx})">
                        <i class="fas fa-check me-1"></i>Elegir
                    </button>
                </td>
            </tr>`;
        }).join('');
    },

    adjModalSelectStudy: function(idx) {
        const studies = this.state.adjModalPacsStudies || [];
        const study   = studies[idx];
        if (!study) return;

        this.state.adjModalSelectedStudy   = study;
        this.state.adjModalSelectedInforme = null;

        const patient = study.patient_name || study.patientName || '-';
        const patId   = study.patient_id   || study.patientId   || '-';
        const mod     = study.modality || '-';
        const date    = study.date || study.study_date || study.studyDate || '-';

        const titleEl   = document.getElementById('adjModalDestTitle');
        const detailsEl = document.getElementById('adjModalDestDetails');
        const dInfo     = document.getElementById('adjModalDestInfo');

        if (titleEl)   titleEl.textContent = 'Estudio PACS seleccionado (se creará nuevo informe)';
        if (detailsEl) detailsEl.innerHTML =
            `Paciente: <strong>${this._adjEsc(patient)}</strong> · ID: ${this._adjEsc(patId)} · ${this._adjEsc(mod)} · ${date}`;
        if (dInfo) dInfo.style.display = '';

        this.adjModalUpdateConfirmButton();
    },

    // ─── Confirmar adjuntar ───────────────────────────────────────────────────

    adjModalConfirm: async function() {
        const type    = this.state.adjModalType || 'audio';
        const fileInp = document.getElementById('adjModalFileInput');
        const title   = (document.getElementById('adjModalTitle')?.value || '').trim();

        if (!fileInp || !fileInp.files || fileInp.files.length === 0) {
            this.showError('Selecciona un archivo primero');
            return;
        }
        if (!this.state.adjModalSelectedInforme && !this.state.adjModalSelectedStudy) {
            this.showError('Selecciona un destino (informe o estudio PACS)');
            return;
        }

        const spinEl  = document.getElementById('adjModalConfirmSpinner');
        const iconEl  = document.getElementById('adjModalConfirmIcon');
        const btnEl   = document.getElementById('adjModalConfirmBtn');
        if (spinEl) spinEl.classList.remove('d-none');
        if (iconEl) iconEl.style.display = 'none';
        if (btnEl)  btnEl.disabled = true;

        try {
            const token = this.getSessionToken();
            if (!token) throw new Error('No se encontró token de sesión');

            const formData = new FormData();
            if (title) formData.append('titulo', title);

            // ── Audio ──────────────────────────────────────────────────────
            if (type === 'audio') {
                formData.append('audio_file', fileInp.files[0]);

                if (this.state.adjModalSelectedInforme) {
                    formData.append('informe_id', String(this.state.adjModalSelectedInforme.id));
                } else {
                    const st = this.state.adjModalSelectedStudy;
                    formData.append('estudio_id',         st.study_instance_uid || st.studyInstanceUID || st.study_id || st.studyId || st.id || '');
                    formData.append('patient_id',         st.patient_id    || st.patientId    || '');
                    formData.append('patient_name',       st.patient_name  || st.patientName  || '');
                    formData.append('modality',           st.modality      || '');
                    formData.append('study_description',  st.study_description || st.studyDescription || '');
                    formData.append('study_instance_uid', st.study_instance_uid || st.studyInstanceUID || '');
                    formData.append('study_id',           st.study_id      || st.studyId      || '');
                    formData.append('accession_number',   st.accession_number || st.accessionNumber || '');
                }

                const apiBase = window.location.pathname.includes('/components/') ? '../api/informes' : 'api/informes';
                const resp = await fetch(`${apiBase}/attach-audio.php`, {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token },
                    body: formData
                });

                const result = await resp.json().catch(() => ({ success: false, message: 'Respuesta inválida del servidor' }));
                if (!resp.ok || !result.success) throw new Error(result.message || 'Error al adjuntar el audio');

                const msg = result.message || 'Audio adjuntado exitosamente';
                const enqMsg = result.data?.enqueued
                    ? ' · Encolado para transcripción ✓'
                    : (result.data?.enqueue_reason ? ` · (transcripción: ${result.data.enqueue_reason})` : '');
                this.showSuccess(msg + enqMsg);
            }

            // ── PDF ────────────────────────────────────────────────────────
            else {
                formData.append('pdf_file', fileInp.files[0]);

                if (this.state.adjModalSelectedInforme) {
                    // Variante A: adjuntar a informe existente
                    formData.append('informe_id', String(this.state.adjModalSelectedInforme.id));
                } else {
                    // Variante B: crear nuevo informe desde estudio PACS
                    const st = this.state.adjModalSelectedStudy;
                    if (!st) throw new Error('Selecciona un informe existente o un estudio PACS');
                    formData.append('estudio_id',         st.study_instance_uid || st.studyInstanceUID || st.study_id || st.studyId || st.id || '');
                    formData.append('patient_id',         st.patient_id    || st.patientId    || '');
                    formData.append('patient_name',       st.patient_name  || st.patientName  || '');
                    formData.append('modality',           st.modality      || '');
                    formData.append('study_description',  st.study_description || st.studyDescription || '');
                    formData.append('study_instance_uid', st.study_instance_uid || st.studyInstanceUID || '');
                    formData.append('study_id',           st.study_id      || st.studyId      || '');
                    formData.append('accession_number',   st.accession_number || st.accessionNumber || '');
                }

                const apiBase = window.location.pathname.includes('/components/') ? '../api/informes' : 'api/informes';
                const resp = await fetch(`${apiBase}/attach-pdf.php`, {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token },
                    body: formData
                });

                const result = await resp.json().catch(() => ({ success: false, message: 'Respuesta inválida del servidor' }));
                if (!resp.ok || !result.success) throw new Error(result.message || 'Error al adjuntar el PDF');
                this.showSuccess(result.message || 'Informe PDF adjuntado exitosamente');
            }

            // Si el flujo fue "crear informe nuevo desde estudio PACS" (no había informe
            // seleccionado, sí estudio), notificar al dashboard.
            if (!this.state.adjModalSelectedInforme && this.state.adjModalSelectedStudy) {
                try {
                    const st = this.state.adjModalSelectedStudy || {};
                    const detail = {
                        informe_id: null,
                        study_id: st.study_id || st.studyId || '',
                        study_instance_uid: st.study_instance_uid || st.studyInstanceUID || '',
                        orthanc_id: st.study_id || st.studyId || st.id || '',
                        action: 'create',
                        source: 'informes-manager-attach',
                        ts: Date.now()
                    };
                    try { window.dispatchEvent(new CustomEvent('informeFinalizado', { detail })); } catch (e) {}
                    if (window.parent && window.parent !== window) {
                        try { window.parent.dispatchEvent(new CustomEvent('informeFinalizado', { detail })); } catch (e) {}
                        try { window.parent.postMessage({ type: 'informeFinalizado', detail }, '*'); } catch (e) {}
                    }
                    try { localStorage.setItem('informe_finalizado_event', JSON.stringify(detail)); } catch (e) {}
                    console.log('📣 informeFinalizado notificado al dashboard (adjuntar→crear):', detail);
                } catch (e) {
                    console.warn('No se pudo notificar informeFinalizado tras adjuntar:', e);
                }
            }

            // Cerrar modal y recargar lista
            const modalEl = document.getElementById('adjuntarGeneralModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                const m = bootstrap.Modal.getInstance(modalEl);
                if (m) m.hide();
            }
            await this.loadReports(this.state.currentPage || 1);

        } catch (e) {
            console.error('adjModalConfirm error:', e);
            this.showError('Error al adjuntar: ' + e.message);
        } finally {
            if (spinEl) spinEl.classList.add('d-none');
            if (iconEl) iconEl.style.display = '';
            if (btnEl)  btnEl.disabled = false;
            this.adjModalUpdateConfirmButton();
        }
    },

    /** Escapa HTML para mostrar en tabla */
    _adjEsc: function(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },

    firmarInformeActual: async function() {
        const id = this.state.selectedReport?.id;
        if (!id) {
            this.showError('No hay informe seleccionado');
            return;
        }
        if (!confirm('¿Firmar este informe? Se agregará su rúbrica/sello.')) {
            return;
        }
        try {
            const token = this.getSessionToken();
            const resp = await fetch(`${this.config.apiBaseUrl}/sign.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { Authorization: `Bearer ${token}` } : {})
                },
                body: JSON.stringify({ id, session_token: token })
            });
            const data = await resp.json();
            if (!data.success) {
                throw new Error(data.message || 'No se pudo firmar');
            }
            this.showSuccess(data.message || 'Informe firmado');
            try {
                const detail = {
                    informe_id: id,
                    estado: 'firmado',
                    study_id: data.data?.study_id || data.data?.estudio_id || '',
                    source: 'informes-manager-sign',
                    ts: Date.now()
                };
                window.dispatchEvent(new CustomEvent('informeEstadoCambiado', { detail }));
                if (window.parent && window.parent !== window) {
                    window.parent.dispatchEvent(new CustomEvent('informeEstadoCambiado', { detail }));
                    window.parent.postMessage({ type: 'informeEstadoCambiado', detail }, '*');
                }
                localStorage.setItem('informe_estado_cambiado_event', JSON.stringify(detail));
            } catch (e) {}
            const modalEl = document.getElementById('reportModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                const m = bootstrap.Modal.getInstance(modalEl);
                if (m) m.hide();
            }
            await this.loadReports(this.state.currentPage || 1);
        } catch (e) {
            this.showError(e.message || 'Error al firmar');
        }
    },

    openMiFirmaModal: async function() {
        const modalEl = document.getElementById('miFirmaModal');
        if (!modalEl) return;
        try {
            const token = this.getSessionToken();
            const resp = await fetch(`../api/users/firma/index.php`, {
                headers: token ? { Authorization: `Bearer ${token}` } : {}
            });
            const data = await resp.json();
            const perfil = data.data || {};
            const ta = document.getElementById('miFirmaSelloTexto');
            if (ta) ta.value = perfil.sello_texto || '';
            const img = document.getElementById('miFirmaPreview');
            const empty = document.getElementById('miFirmaPreviewEmpty');
            if (perfil.imagen_path && img) {
                img.src = '../' + perfil.imagen_path + '?t=' + Date.now();
                img.style.display = 'inline-block';
                if (empty) empty.style.display = 'none';
            } else if (img) {
                img.style.display = 'none';
                if (empty) empty.style.display = '';
            }
        } catch (e) {
            console.warn('Mi firma load:', e);
        }
        if (typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    },

    guardarMiFirma: async function() {
        try {
            const token = this.getSessionToken();
            const fd = new FormData();
            if (token) fd.append('session_token', token);
            const ta = document.getElementById('miFirmaSelloTexto');
            if (ta) fd.append('sello_texto', ta.value || '');
            const fileInput = document.getElementById('miFirmaFile');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                fd.append('imagen', fileInput.files[0]);
            }
            const resp = await fetch('../api/users/firma/index.php', {
                method: 'POST',
                headers: token ? { Authorization: `Bearer ${token}` } : {},
                body: fd
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.message || 'Error al guardar');
            this.showSuccess('Firma guardada');
            await this.openMiFirmaModal();
        } catch (e) {
            this.showError(e.message || 'No se pudo guardar la firma');
        }
    },

    iniciarCapturaFirmaQr: async function() {
        try {
            const token = this.getSessionToken();
            const resp = await fetch('../api/mobile_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { Authorization: `Bearer ${token}` } : {})
                },
                body: JSON.stringify({
                    action: 'create',
                    session_type: 'firma',
                    session_token: token
                })
            });
            const data = await resp.json();
            if (!data.success && !data.session_id) {
                throw new Error(data.error || data.message || 'No se pudo crear sesión QR');
            }
            const sid = data.session_id || data.data?.session_id;
            const box = document.getElementById('miFirmaQrBox');
            if (!box || !sid) return;
            const url = `${window.location.origin}${window.location.pathname.replace(/components\/informes-manager\.html.*/, '')}mobile-firma.html?session_id=${encodeURIComponent(sid)}`;
            box.classList.remove('d-none');
            box.innerHTML = `<div id="miFirmaQrCanvas"></div><p class="small text-muted mt-2">Escanee con el teléfono para dibujar la firma.<br><code class="small">${this.escapeHtml(url)}</code></p>`;
            if (typeof QRCode !== 'undefined') {
                box.querySelector('#miFirmaQrCanvas').innerHTML = '';
                new QRCode(box.querySelector('#miFirmaQrCanvas'), { text: url, width: 180, height: 180 });
            } else if (window.qrcode) {
                // fallback lib
            }
            // Poll perfil
            if (this._firmaQrPoll) clearInterval(this._firmaQrPoll);
            this._firmaQrPoll = setInterval(async () => {
                try {
                    const r = await fetch('../api/users/firma/index.php', {
                        headers: token ? { Authorization: `Bearer ${token}` } : {}
                    });
                    const d = await r.json();
                    if (d.data && d.data.imagen_path) {
                        clearInterval(this._firmaQrPoll);
                        this._firmaQrPoll = null;
                        await this.openMiFirmaModal();
                        this.showSuccess('Firma recibida desde el teléfono');
                    }
                } catch (e) {}
            }, 3000);
        } catch (e) {
            this.showError(e.message || 'Error QR firma');
        }
    },

    _slaApiBase() {
        return window.location.pathname.includes('/components/') ? '../api/estudios' : 'api/estudios';
    },

    async loadSlaBannerCounts() {
        try {
            const token = this.getSessionToken();
            const resp = await fetch(this._slaApiBase() + '/sla-pending.php?count_only=1', {
                headers: token ? { Authorization: 'Bearer ' + token } : {}
            });
            const data = await resp.json();
            const wrapV = document.getElementById('slaVencidosStat');
            const wrapP = document.getElementById('slaPorVencerStat');
            const filterWrap = document.getElementById('slaFilterWrap');
            const hide = () => {
                if (wrapV) wrapV.classList.add('d-none');
                if (wrapP) wrapP.classList.add('d-none');
                if (filterWrap) filterWrap.classList.add('d-none');
            };
            if (!data.success || data.message === 'Sin permiso' || data.activo === false) {
                hide();
                return;
            }
            if (wrapV) wrapV.classList.remove('d-none');
            if (wrapP) wrapP.classList.remove('d-none');
            if (filterWrap) filterWrap.classList.remove('d-none');
            const elV = document.getElementById('slaVencidosCount');
            const elP = document.getElementById('slaPorVencerCount');
            if (elV) elV.textContent = String(data.vencidos || 0);
            if (elP) elP.textContent = String(data.por_vencer || 0);
            this._bindSlaUiOnce();
        } catch (e) {
            console.warn('SLA counts:', e);
        }
    },

    _bindSlaUiOnce() {
        if (this._slaUiBound) return;
        this._slaUiBound = true;
        const open = (bucket) => {
            const sel = document.getElementById('slaModalBucket');
            if (sel && bucket) sel.value = bucket;
            this.openSlaEstudiosModal();
        };
        document.getElementById('slaVencidosStat')?.addEventListener('click', () => open('vencido'));
        document.getElementById('slaPorVencerStat')?.addEventListener('click', () => open('por_vencer'));
        document.getElementById('slaModalFilterBtn')?.addEventListener('click', () => this.loadSlaModalList());
        document.getElementById('slaModalRefreshBtn')?.addEventListener('click', () => this.loadSlaModalList());
        document.getElementById('slaFilter')?.addEventListener('change', (e) => {
            const v = e.target.value;
            if (!v) return;
            open(v);
        });
        document.getElementById('slaModalTbody')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-sla-action]');
            if (!btn) return;
            const id = parseInt(btn.getAttribute('data-sla-id') || '0', 10);
            const action = btn.getAttribute('data-sla-action');
            const informeId = parseInt(btn.getAttribute('data-informe-id') || '0', 10);
            if (action === 'exclude' && id) {
                this.slaExcludeEstudio(id);
            } else if (action === 'open' && informeId) {
                this.openReportModal(informeId, true).catch(() => {});
            } else if (action === 'override' && id) {
                const h = prompt('Horas SLA para este estudio:', '72');
                if (h && parseInt(h, 10) > 0) this.slaOverrideEstudio(id, parseInt(h, 10));
            }
        });
    },

    openSlaEstudiosModal() {
        const el = document.getElementById('slaEstudiosModal');
        if (!el) return;
        if (typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(el).show();
        }
        this.loadSlaModalList();
    },

    async loadSlaModalList() {
        const loading = document.getElementById('slaModalLoading');
        const empty = document.getElementById('slaModalEmpty');
        const tbody = document.getElementById('slaModalTbody');
        if (loading) loading.classList.remove('d-none');
        if (empty) empty.classList.add('d-none');
        if (tbody) tbody.innerHTML = '';
        try {
            const token = this.getSessionToken();
            const params = new URLSearchParams({ limit: '100' });
            const fi = document.getElementById('slaModalFechaInicio')?.value || '';
            const ff = document.getElementById('slaModalFechaFin')?.value || '';
            const bucket = document.getElementById('slaModalBucket')?.value || 'all';
            if (fi) params.set('fecha_inicio', fi);
            if (ff) params.set('fecha_fin', ff);
            if (bucket) params.set('bucket', bucket);
            const resp = await fetch(this._slaApiBase() + '/sla-pending.php?' + params.toString(), {
                headers: token ? { Authorization: 'Bearer ' + token } : {}
            });
            const data = await resp.json();
            const bV = document.getElementById('slaModalVencidosBadge');
            const bP = document.getElementById('slaModalPorVencerBadge');
            if (bV) bV.textContent = String(data.vencidos || 0);
            if (bP) bP.textContent = String(data.por_vencer || 0);
            if (document.getElementById('slaVencidosCount')) {
                document.getElementById('slaVencidosCount').textContent = String(data.vencidos || 0);
            }
            if (document.getElementById('slaPorVencerCount')) {
                document.getElementById('slaPorVencerCount').textContent = String(data.por_vencer || 0);
            }
            if (!data.success) throw new Error(data.message || 'Error');
            if (!data.activo) {
                if (empty) {
                    empty.classList.remove('d-none');
                    empty.textContent = 'SLA desactivado (Configuración → Estudios Recibidos → sla_activo).';
                }
                return;
            }
            const items = data.items || [];
            if (!items.length) {
                if (empty) empty.classList.remove('d-none');
                return;
            }
            if (!tbody) return;
            tbody.innerHTML = items.map((it) => {
                const secs = Number(it.seconds_remaining || 0);
                const hrs = Math.round(secs / 3600);
                const restLabel = secs < 0
                    ? `<span class="text-danger">Vencido ${Math.abs(hrs)} h</span>`
                    : `<span class="text-warning">${hrs} h</span>`;
                const estLabel = it.sin_informe
                    ? '<span class="badge bg-secondary">Sin informe</span>'
                    : `<span class="badge bg-info text-dark">${this.escapeHtml(it.informe_estado || '—')}</span>`;
                const openBtn = it.informe_id
                    ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-sla-action="open" data-informe-id="${it.informe_id}" title="Ver informe"><i class="fas fa-eye"></i></button>`
                    : '';
                return `<tr class="${it.bucket === 'vencido' ? 'table-danger' : (it.bucket === 'por_vencer' ? 'table-warning' : '')}">
                    <td><small>${this.escapeHtml(it.local_arrived_at || '')}</small></td>
                    <td>
                        <div class="fw-medium">${this.escapeHtml(it.patient_name || '—')}</div>
                        <small class="text-muted">${this.escapeHtml(it.patient_id || '')}</small>
                    </td>
                    <td>${this.escapeHtml(it.modality || '—')}</td>
                    <td>${estLabel}</td>
                    <td><small>${it.sla_horas}h <span class="text-muted">(${this.escapeHtml(it.sla_fuente || '')})</span></small></td>
                    <td>${restLabel}</td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group btn-group-sm">
                            ${openBtn}
                            <button type="button" class="btn btn-outline-primary" data-sla-action="override" data-sla-id="${it.estudios_id}" title="Override horas"><i class="fas fa-hourglass-half"></i></button>
                            <button type="button" class="btn btn-outline-danger" data-sla-action="exclude" data-sla-id="${it.estudios_id}" title="Excluir del SLA"><i class="fas fa-ban"></i></button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        } catch (e) {
            if (empty) {
                empty.classList.remove('d-none');
                empty.textContent = e.message || 'Error al cargar';
            }
        } finally {
            if (loading) loading.classList.add('d-none');
        }
    },

    async slaExcludeEstudio(estudiosId) {
        const motivo = prompt('Motivo de exclusión del SLA:', 'Excluido manualmente');
        if (motivo === null) return;
        try {
            const token = this.getSessionToken();
            const resp = await fetch(this._slaApiBase() + '/sla-override.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { Authorization: 'Bearer ' + token } : {})
                },
                body: JSON.stringify({ estudios_id: estudiosId, action: 'exclude', motivo })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.message || 'Error');
            this.showSuccess('Estudio excluido del SLA');
            await this.loadSlaModalList();
            await this.loadSlaBannerCounts();
        } catch (e) {
            this.showError(e.message || String(e));
        }
    },

    async slaOverrideEstudio(estudiosId, horas) {
        try {
            const token = this.getSessionToken();
            const resp = await fetch(this._slaApiBase() + '/sla-override.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { Authorization: 'Bearer ' + token } : {})
                },
                body: JSON.stringify({ estudios_id: estudiosId, action: 'override', sla_override_horas: horas })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.message || 'Error');
            this.showSuccess('Override SLA guardado');
            await this.loadSlaModalList();
            await this.loadSlaBannerCounts();
        } catch (e) {
            this.showError(e.message || String(e));
        }
    },

};

// Inicializar cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => InformesManager.init());
} else {
    InformesManager.init();
}

// Exponer globalmente para uso en onclick
window.InformesManager = InformesManager;
