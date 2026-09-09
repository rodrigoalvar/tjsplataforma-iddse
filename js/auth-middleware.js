/**
 * Middleware de autenticación para TJSMEDICAL Portal
 * Maneja la validación de sesiones y redirecciones
 */

class AuthMiddleware {
    constructor() {
        this.currentUser = null;
        this.sessionToken = null;
        this.init();
    }
    
    /**
     * Prefijo relativo hacia la raíz de la app según la URL actual.
     * Ej: /components/foo -> ../  |  /modules/qa-publicacion/bar -> ../../
     */
    getAppRootPrefix() {
        const pathname = window.location.pathname || '';
        if (pathname.includes('/modules/')) {
            const modulesIndex = pathname.indexOf('/modules/');
            const afterModules = pathname.substring(modulesIndex + 9);
            const slashCount = (afterModules.match(/\//g) || []).length;
            return slashCount >= 1 ? '../../' : '../';
        }
        if (pathname.includes('/components/')) {
            return '../';
        }
        return '';
    }

    /**
     * Obtener la ruta correcta al login basándose en la ubicación actual
     */
    getLoginPath() {
        return this.getAppRootPrefix() + 'login.html';
    }
    
    /**
     * Obtener la ruta correcta a index.html basándose en la ubicación actual
     */
    getIndexPath() {
        return this.getAppRootPrefix() + 'index.html';
    }
    
    /**
     * Obtener la ruta correcta a la API basándose en la ubicación actual
     */
    getApiPath(endpoint) {
        return this.getAppRootPrefix() + 'api/' + endpoint;
    }

    /**
     * Carga una sola vez el reporter de errores JS hacia audit-manager (record.php).
     */
    ensureAuditClientErrorsScript() {
        if (window.__auditClientErrorsStarted || window.__auditClientErrorsScriptPending) {
            return;
        }
        window.__auditClientErrorsScriptPending = true;
        const prefix = this.getAppRootPrefix();
        const src = prefix + 'modules/audit-manager/assets/js/audit-client-errors.js';
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        const done = () => {
            window.__auditClientErrorsScriptPending = false;
        };
        s.onload = done;
        s.onerror = done;
        (document.head || document.documentElement).appendChild(s);
    }

    /**
     * Carga una sola vez el acumulador de tiempo activo de sesión (activity-heartbeat.php).
     */
    ensureAuditSessionActivityScript() {
        if (window.__auditSessionActivityScriptAppended || window.__auditSessionActivityScriptPending) {
            return;
        }
        window.__auditSessionActivityScriptPending = true;
        const prefix = this.getAppRootPrefix();
        const src = prefix + 'modules/audit-manager/assets/js/audit-session-activity.js';
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        const done = () => {
            window.__auditSessionActivityScriptPending = false;
            window.__auditSessionActivityScriptAppended = true;
        };
        s.onload = done;
        s.onerror = done;
        (document.head || document.documentElement).appendChild(s);
    }
    
    init() {
        // Obtener token de sesión de cookie
        this.sessionToken = this.getCookie('session_token');
        
        // Obtener datos de usuario de localStorage
        const userData = localStorage.getItem('user');
        if (userData) {
            try {
                this.currentUser = JSON.parse(userData);
            } catch (e) {
                localStorage.removeItem('user');
            }
        }
    }
    
    /**
     * Verificar si el usuario está autenticado
     */
    async isAuthenticated() {
        if (!this.sessionToken) {
            return false;
        }
        
        try {
            const response = await fetch(this.getApiPath('auth/validate-session.php'), {
                method: 'GET',
                credentials: 'include', // Incluir cookies automáticamente
                headers: {
                    'Authorization': `Bearer ${this.sessionToken}`
                }
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.currentUser = result.user;
                localStorage.setItem('user', JSON.stringify(result.user));
                return true;
            } else {
                await this.clearSession();
                return false;
            }
        } catch (error) {
            console.error('Error validating session:', error);
            return false;
        }
    }
    
    /**
     * Proteger página - requiere autenticación
     */
    async requireAuth() {
        const isAuth = await this.isAuthenticated();
        
        if (!isAuth) {
            // Guardar la URL actual para redirigir después del login
            sessionStorage.setItem('redirectAfterLogin', window.location.href);
            
            // Redirigir al login
            window.location.href = this.getLoginPath() + '?message=' + encodeURIComponent('Debes iniciar sesión para acceder a esta página') + '&type=error';
            return false;
        }

        this.ensureAuditClientErrorsScript();
        this.ensureAuditSessionActivityScript();
        return true;
    }
    
    /**
     * Proteger página - solo para usuarios no autenticados
     */
    async requireGuest() {
        const isAuth = await this.isAuthenticated();
        
        if (isAuth) {
            // Usuario ya autenticado, redirigir al dashboard
            window.location.href = 'dashboard-unified.html';
            return false;
        }
        
        return true;
    }
    
    /**
     * Cerrar sesión
     */
    async logout() {
        try {
            // Marcar como cerrando sesión para evitar que beforeunload re-guarde estados del workspace
            this._loggingOut = true;
            // Notificar al WorkspaceManager si está disponible en el mismo contexto
            if (window.WorkspaceManager) {
                window.WorkspaceManager._isLoggingOut = true;
            }
            // También intentar notificar al iframe del workspace si existe en el DOM padre
            try {
                const wsFrame = document.getElementById('workspace-frame') ||
                                (window.AppContainer && window.AppContainer.workspaceFrame);
                if (wsFrame && wsFrame.contentWindow && wsFrame.contentWindow.WorkspaceManager) {
                    wsFrame.contentWindow.WorkspaceManager._isLoggingOut = true;
                }
            } catch (e) { /* ignorar errores de cross-origin o referencia */ }

            if (this.sessionToken) {
                await fetch(this.getApiPath('auth/logout.php'), {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.sessionToken}`
                    }
                });
            }
        } catch (error) {
            console.error('Error during logout:', error);
        } finally {
            await this.clearSession();
            // Pequeño delay para asegurar que la limpieza se complete
            setTimeout(() => {
                window.location.href = this.getIndexPath() + '?message=' + encodeURIComponent('Sesión cerrada exitosamente') + '&type=success';
            }, 100);
        }
    }
    
    /**
     * Limpiar datos de sesión
     */
    async clearSession() {
        this.currentUser = null;
        this.sessionToken = null;
        localStorage.removeItem('user');
        
        // Limpiar todos los datos de persistencia del editor y dashboard
        const keysToRemove = [];
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && (key.startsWith('editor_persistence_') || 
                       key.startsWith('editor_session_') ||
                       key.startsWith('orthanc_dashboard_') ||
                       key === 'dashboard_orthanc_state' || // Clave específica del dashboard
                       key === 'informes_manager_studies' || // Clave específica de informes manager
                       key === 'derivaciones_manager_state' || // Clave específica de estudios-manager
                       key === 'userData' || // Clave específica de datos de usuario
                       key.startsWith('dashboard_') || // Cualquier clave de dashboard
                       key.startsWith('informes_') || // Cualquier clave de informes
                       key.startsWith('derivaciones_') || // Cualquier clave de derivaciones/estudios
                       key.includes('_default') || // Incluir claves como 'default_default'
                       key.startsWith('tinymce_') ||
                       key.startsWith('audio_') ||
                       key.startsWith('workspace_') || // Claves del workspace con guión bajo
                       key.startsWith('workspace-') || // Claves del workspace con guión (ej: workspace-layout)
                       key === 'workspace-layout' || // Clave específica del workspace
                       key === 'pacs_nodes_manager_state' || // Clave de PACS Nodes Manager
                       key.startsWith('pacs_nodes_manager_') || // Cualquier clave de PACS Nodes Manager
                       key === 'pacs_manager_state' || // Clave de PACS Manager
                       key.startsWith('pacs_manager_'))) { // Cualquier clave de PACS Manager
                keysToRemove.push(key);
            }
        }
        keysToRemove.forEach(key => localStorage.removeItem(key));
        
        // Limpiar sessionStorage también (incluye workspace-state)
        sessionStorage.clear();
        
        // Limpiar URLESTUDIO y audios temporales si existe
        if (window.TempAudioManager) {
            await window.TempAudioManager.discardReport();
        } else if (window.urlStudyManager) {
            window.urlStudyManager.clearActiveStudy();
        }
        
        // Limpiar toda la persistencia del editor si está disponible
        if (window.PersistenceModule && typeof window.PersistenceModule.clearAllPersistence === 'function') {
            window.PersistenceModule.clearAllPersistence();
        }
        
        // Limpiar caché específico de estudios-manager si está disponible
        this.clearEstudiosManagerCache();
    }
    
    /**
     * Limpiar caché específico de estudios-manager
     */
    clearEstudiosManagerCache() {
        try {
            // Limpiar estado persistente de estudios-manager
            if (window.derivacionesManager && typeof window.derivacionesManager.clearPersistentState === 'function') {
                window.derivacionesManager.clearPersistentState();
                console.log('✅ Caché de estudios-manager limpiado');
            }
            
            // Limpiar datos de usuario específicos
            sessionStorage.removeItem('userData');
            localStorage.removeItem('userData');
            
            // Limpiar cualquier otra clave relacionada con estudios-manager
            const estudiosKeys = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && (key.includes('estudios') || 
                           key.includes('derivaciones') || 
                           key.includes('assignment') ||
                           key.includes('study'))) {
                    estudiosKeys.push(key);
                }
            }
            estudiosKeys.forEach(key => localStorage.removeItem(key));
            
            console.log('✅ Limpieza completa de caché de estudios-manager realizada');
        } catch (error) {
            console.warn('Error limpiando caché de estudios-manager:', error);
        }
        
        // Eliminar cookie con múltiples paths para asegurar eliminación completa
        document.cookie = 'session_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
        document.cookie = 'session_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/PORTAL_ESTUDIOS/';
        document.cookie = 'session_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
        
        console.log('Sesión y datos de persistencia limpiados completamente');
    }
    
    /**
     * Obtener usuario actual
     */
    getCurrentUser() {
        return this.currentUser;
    }
    
    /**
     * Obtener cookie por nombre
     */
    getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }
    
    /**
     * Actualizar información del usuario en la UI
     */
    updateUserUI() {
        if (!this.currentUser) return;
        
        // Actualizar elementos con clase 'user-name'
        const userNameElements = document.querySelectorAll('.user-name');
        userNameElements.forEach(element => {
            element.textContent = `${this.currentUser.nombre} ${this.currentUser.apellido}`;
        });
        
        // Actualizar elementos con clase 'user-email'
        const userEmailElements = document.querySelectorAll('.user-email');
        userEmailElements.forEach(element => {
            element.textContent = this.currentUser.email;
        });
        
        // Actualizar elementos con clase 'user-matricula'
        const userMatriculaElements = document.querySelectorAll('.user-matricula');
        userMatriculaElements.forEach(element => {
            element.textContent = this.currentUser.matricula_profesional;
        });
    }
    
    /**
     * Configurar botones de logout
     */
    setupLogoutButtons() {
        // Crear modal de confirmación si no existe
        this.createLogoutModal();
        
        const logoutButtons = document.querySelectorAll('.logout-btn, [data-action="logout"]');
        logoutButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                this.showLogoutModal();
            });
        });
    }
    
    /**
     * Crear modal de confirmación de logout
     */
    createLogoutModal() {
        // Verificar si el modal ya existe
        if (document.getElementById('logoutConfirmModal')) {
            return;
        }
        
        const modalHTML = `
            <div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header border-0 pb-0">
                            <h5 class="modal-title" id="logoutConfirmModalLabel">
                                <i class="bi bi-box-arrow-right text-warning me-2"></i>
                                Cerrar Sesión
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body pt-2">
                            <p class="mb-0">¿Estás seguro que deseas cerrar sesión?</p>
                            <small class="text-muted">Se cerrará tu sesión actual y serás redirigido a la página de inicio.</small>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle me-1"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmLogoutBtn">
                                <i class="bi bi-box-arrow-right me-1"></i>Cerrar Sesión
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar modal al body
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Configurar evento del botón de confirmación
        document.getElementById('confirmLogoutBtn').addEventListener('click', async () => {
            // Descartar informe completo (limpiar URLESTUDIO y eliminar audios temporales)
            if (window.TempAudioManager) {
                await window.TempAudioManager.discardReport();
            } else if (window.urlStudyManager) {
                // Fallback: solo limpiar URLESTUDIO
                window.urlStudyManager.clearActiveStudy();
            }
            
            const modalElement = document.getElementById('logoutConfirmModal');
            if (modalElement && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                } else {
                    // Si no hay instancia, crear una nueva y cerrarla
                    const newModal = new bootstrap.Modal(modalElement);
                    newModal.hide();
                }
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
            
            this.logout();
        });
    }
    
    /**
     * Cerrar todos los modales abiertos y eliminar todos los backdrops
     * Esto evita que queden backdrops residuales cuando se muestra el modal de logout
     */
    closeAllModals() {
        try {
            // Cerrar todos los modales de Bootstrap que estén abiertos
            const allModals = document.querySelectorAll('.modal.show, .modal[class*="show"]');
            allModals.forEach(modalElement => {
                // Evitar cerrar el modal de logout si ya existe
                if (modalElement.id === 'logoutConfirmModal') {
                    return;
                }
                
                try {
                    const modalInstance = bootstrap.Modal.getInstance(modalElement);
                    if (modalInstance) {
                        modalInstance.hide();
                    } else {
                        // Si no hay instancia, cerrar manualmente
                        modalElement.classList.remove('show');
                        modalElement.style.display = 'none';
                        modalElement.setAttribute('aria-hidden', 'true');
                        modalElement.removeAttribute('aria-modal');
                    }
                } catch (error) {
                    console.warn('Error cerrando modal:', error);
                    // Forzar cierre manual
                    modalElement.classList.remove('show');
                    modalElement.style.display = 'none';
                    modalElement.setAttribute('aria-hidden', 'true');
                }
            });
            
            // Eliminar TODOS los backdrops
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                try {
                    backdrop.remove();
                } catch (error) {
                    console.warn('Error removiendo backdrop:', error);
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                }
            });
            
            // Limpiar clases y estilos del body
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Pequeño delay para asegurar que todo se haya cerrado
            return new Promise(resolve => setTimeout(resolve, 100));
        } catch (error) {
            console.error('Error cerrando modales:', error);
            return Promise.resolve();
        }
    }
    
    /**
     * Mostrar modal de confirmación de logout
     */
    async showLogoutModal() {
        // Si estamos en un iframe, intentar usar el modal del padre (app-container)
        if (window.self !== window.top) {
            try {
                // Intentar comunicar al padre para que muestre el modal
                if (window.parent && window.parent.authManager && typeof window.parent.authManager.showLogoutModal === 'function') {
                    console.log('📡 Comunicando logout al contexto padre (app-container)');
                    window.parent.authManager.showLogoutModal();
                    return;
                }
                // Fallback: intentar usar window.parent.auth si existe
                if (window.parent && window.parent.auth && typeof window.parent.auth.showLogoutModal === 'function') {
                    console.log('📡 Comunicando logout al contexto padre (auth)');
                    window.parent.auth.showLogoutModal();
                    return;
                }
                // Fallback adicional: verificar si el padre tiene Bootstrap y crear modal allí
                if (window.parent && typeof window.parent.bootstrap !== 'undefined' && window.parent.bootstrap.Modal) {
                    console.log('📡 Bootstrap disponible en padre, creando modal allí');
                    this.createLogoutModalInParent();
                    return;
                }
            } catch (e) {
                console.warn('⚠️ No se pudo acceder al contexto padre para logout:', e);
                // Continuar con el flujo normal en el iframe
            }
        }
        
        // Verificar si hay datos no guardados en URLESTUDIO
        if (window.urlStudyManager && window.urlStudyManager.hasActiveStudy()) {
            const activeStudy = window.urlStudyManager.getActiveStudy();
            const hasPersistedContent = window.urlStudyManager.hasPersistedContent();
            
            if (hasPersistedContent) {
                // Mostrar advertencia específica para datos no guardados
                this.showUnsavedDataWarning(activeStudy);
                return;
            }
        }
        
        // Verificar si hay datos de persistencia sin estudio activo (modo no persistente)
        const hasGeneralPersistedContent = this.checkGeneralPersistedContent();
        if (hasGeneralPersistedContent) {
            this.showUnsavedDataWarning(null); // null indica modo no persistente
            return;
        }
        
        // Mostrar modal normal de logout
        // Verificar que Bootstrap esté disponible
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            // Si estamos en iframe, intentar usar el modal del padre primero
            if (window.self !== window.top) {
                console.log('🔄 Bootstrap no disponible en iframe, intentando usar modal del padre...');
                try {
                    // Intentar crear y mostrar modal en el padre
                    this.createLogoutModalInParent();
                    return;
                } catch (e) {
                    console.warn('⚠️ No se pudo usar modal del padre:', e);
                }
            }
            
            console.error('Bootstrap no está disponible. Intentando cargar desde CDN...');
            
            // Intentar cargar Bootstrap dinámicamente si no está disponible
            if (!document.querySelector('script[src*="bootstrap"]')) {
                const bootstrapScript = document.createElement('script');
                bootstrapScript.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
                bootstrapScript.onload = () => {
                    console.log('✅ Bootstrap cargado dinámicamente');
                    this.showLogoutModal(); // Reintentar después de cargar
                };
                bootstrapScript.onerror = () => {
                    console.error('❌ Error cargando Bootstrap, usando confirm nativo');
                    if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
                        this.logout();
                    }
                };
                document.head.appendChild(bootstrapScript);
                return;
            }
            
            // Si ya existe el script pero Bootstrap no está disponible, esperar un poco
            let waitAttempts = 0;
            const maxWaitAttempts = 5;
            const waitForBootstrap = () => {
                waitAttempts++;
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    console.log('✅ Bootstrap disponible después de esperar');
                    this.showLogoutModal(); // Reintentar
                } else if (waitAttempts < maxWaitAttempts) {
                    setTimeout(waitForBootstrap, 200);
                } else {
                    console.error('Bootstrap no disponible después de esperar. Usando confirm nativo.');
                    if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
                        this.logout();
                    }
                }
            };
            setTimeout(waitForBootstrap, 200);
            return;
        }
        
        // IMPORTANTE: Cerrar TODOS los modales abiertos antes de mostrar el modal de logout
        // Esto evita que queden backdrops residuales
        await this.closeAllModals();
        
        // Asegurar que el modal existe antes de intentar mostrarlo
        if (!document.getElementById('logoutConfirmModal')) {
            this.createLogoutModal();
        }
        
        const modalElement = document.getElementById('logoutConfirmModal');
        if (!modalElement) {
            console.error('Modal de logout no encontrado después de intentar crearlo');
            if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
                this.logout();
            }
            return;
        }
        
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: false,  // Desactivar backdrop para evitar que aparezca por encima del modal
            keyboard: true,
            focus: true
        });
        modal.show();
    }
    
    /**
     * Crear modal de logout en el contexto padre (cuando estamos en iframe)
     */
    createLogoutModalInParent() {
        try {
            const parent = window.parent;
            if (!parent) {
                throw new Error('No se puede acceder al contexto padre');
            }
            
            // Verificar si Bootstrap está disponible en el padre
            if (typeof parent.bootstrap === 'undefined' || !parent.bootstrap.Modal) {
                console.warn('⚠️ Bootstrap no disponible en el padre');
                // Intentar usar authManager del padre que debería manejar esto
                const parentAuth = parent.authManager || parent.auth;
                if (parentAuth && typeof parentAuth.showLogoutModal === 'function') {
                    console.log('📡 Delegando logout al authManager del padre');
                    parentAuth.showLogoutModal();
                    return;
                }
                throw new Error('Bootstrap no disponible en el padre');
            }
            
            // Verificar si el modal ya existe en el padre
            let modalElement = null;
            try {
                modalElement = parent.document.getElementById('logoutConfirmModal');
            } catch (e) {
                // Error de acceso cruzado, intentar otra forma
                console.warn('⚠️ No se puede acceder directamente al documento del padre:', e);
            }
            
            if (modalElement) {
                // El modal ya existe, solo mostrarlo
                try {
                    const modal = new parent.bootstrap.Modal(modalElement, {
                        backdrop: true,
                        keyboard: true,
                        focus: true
                    });
                    modal.show();
                    console.log('✅ Modal del padre mostrado');
                    return;
                } catch (e) {
                    console.warn('⚠️ Error mostrando modal del padre:', e);
                }
            }
            
            // Crear el modal en el padre usando authManager
            const parentAuth = parent.authManager || parent.auth;
            if (parentAuth && typeof parentAuth.createLogoutModal === 'function') {
                console.log('📡 Creando modal en el padre mediante authManager');
                parentAuth.createLogoutModal();
                // Esperar un poco y luego mostrarlo
                setTimeout(() => {
                    try {
                        modalElement = parent.document.getElementById('logoutConfirmModal');
                        if (modalElement && parent.bootstrap && parent.bootstrap.Modal) {
                            const modal = new parent.bootstrap.Modal(modalElement, {
                                backdrop: true,
                                keyboard: true,
                                focus: true
                            });
                            modal.show();
                            console.log('✅ Modal creado y mostrado en el padre');
                        } else {
                            // Si no se pudo crear, usar showLogoutModal del padre
                            if (parentAuth && typeof parentAuth.showLogoutModal === 'function') {
                                parentAuth.showLogoutModal();
                            }
                        }
                    } catch (e) {
                        console.error('Error mostrando modal en padre:', e);
                        // Fallback: usar showLogoutModal del padre
                        if (parentAuth && typeof parentAuth.showLogoutModal === 'function') {
                            parentAuth.showLogoutModal();
                        }
                    }
                }, 150);
            } else {
                throw new Error('authManager no disponible en el padre');
            }
        } catch (e) {
            console.error('Error creando modal en padre:', e);
            // Fallback: intentar usar showLogoutModal del padre directamente
            try {
                const parent = window.parent;
                const parentAuth = parent.authManager || parent.auth;
                if (parentAuth && typeof parentAuth.showLogoutModal === 'function') {
                    console.log('📡 Usando showLogoutModal del padre como fallback');
                    parentAuth.showLogoutModal();
                    return;
                }
            } catch (e2) {
                console.error('Error en fallback:', e2);
            }
            // Último fallback: usar confirm nativo
            if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
                this.logout();
            }
        }
    }
    
    /**
     * Mostrar advertencia de datos no guardados antes del logout
     */
    showUnsavedDataWarning(activeStudy) {
        // Crear modal de advertencia si no existe
        if (!document.getElementById('unsavedDataWarningModal')) {
            this.createUnsavedDataWarningModal();
        }
        
        // Actualizar contenido del modal con información del estudio
        const modalBody = document.querySelector('#unsavedDataWarningModal .modal-body');
        
        if (activeStudy) {
            // Modo con estudio activo
            modalBody.innerHTML = `
                <div class="alert alert-warning d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        <strong>¡Atención!</strong> Tienes cambios sin guardar en un informe.
                    </div>
                </div>
                <div class="mb-3">
                    <h6 class="mb-2">Informe en progreso:</h6>
                    <ul class="list-unstyled mb-0">
                        <li><strong>Paciente:</strong> ${activeStudy.patientName}</li>
                        <li><strong>ID Paciente:</strong> ${activeStudy.patientId}</li>
                        <li><strong>Modalidad:</strong> ${activeStudy.modality}</li>
                        <li><strong>Descripción:</strong> ${activeStudy.studyDescription}</li>
                    </ul>
                </div>
                <p class="mb-0">Si cierras sesión ahora, <strong>perderás todos los cambios no guardados</strong>.</p>
                <p class="text-muted small mb-0">Te recomendamos guardar el informe antes de cerrar sesión.</p>
            `;
        } else {
            // Modo no persistente (sin estudio activo)
            modalBody.innerHTML = `
                <div class="alert alert-warning d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        <strong>¡Atención!</strong> Tienes cambios sin guardar en el editor.
                    </div>
                </div>
                <div class="mb-3">
                    <p class="mb-2">Estás trabajando en <strong>modo no persistente</strong> y tienes contenido sin guardar en el editor.</p>
                </div>
                <p class="mb-0">Si cierras sesión ahora, <strong>perderás todos los cambios no guardados</strong>.</p>
                <p class="text-muted small mb-0">Te recomendamos descargar o copiar el contenido antes de cerrar sesión.</p>
            `;
        }
        
        const modal = new bootstrap.Modal(document.getElementById('unsavedDataWarningModal'), {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        modal.show();
    }
     
     /**
      * Crear modal de advertencia de datos no guardados
      */
     createUnsavedDataWarningModal() {
         const modalHTML = `
             <div class="modal fade" id="unsavedDataWarningModal" tabindex="-1" aria-labelledby="unsavedDataWarningModalLabel" aria-hidden="true">
                 <div class="modal-dialog modal-dialog-centered">
                     <div class="modal-content">
                         <div class="modal-header border-0 pb-0">
                             <h5 class="modal-title" id="unsavedDataWarningModalLabel">
                                 <i class="bi bi-exclamation-triangle text-warning me-2"></i>
                                 Datos No Guardados
                             </h5>
                             <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                         </div>
                         <div class="modal-body pt-2">
                             <!-- Contenido dinámico se insertará aquí -->
                         </div>
                         <div class="modal-footer border-0 pt-0">
                             <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                 <i class="bi bi-x-circle me-1"></i>Cancelar
                             </button>
                             <button type="button" class="btn btn-primary" id="continueEditingBtn">
                                 <i class="bi bi-pencil-square me-1"></i>Continuar Editando
                             </button>
                             <button type="button" class="btn btn-danger" id="forceLogoutBtn">
                                 <i class="bi bi-box-arrow-right me-1"></i>Cerrar Sesión (Perder Cambios)
                             </button>
                         </div>
                     </div>
                 </div>
             </div>
         `;
         
         // Agregar modal al body
         document.body.insertAdjacentHTML('beforeend', modalHTML);
         
         // Configurar eventos de los botones
        document.getElementById('continueEditingBtn').addEventListener('click', () => {
            const modalElement = document.getElementById('unsavedDataWarningModal');
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
            
            // Navegar al editor para continuar editando
             if (window.urlStudyManager && window.urlStudyManager.hasActiveStudy()) {
                 window.location.href = window.urlStudyManager.getEditorURL();
             } else {
                 // Si no hay estudio activo, ir al editor directamente
                 const currentPath = window.location.pathname;
                 if (currentPath.includes('/components/')) {
                     // Ya estamos en la carpeta components
                     window.location.href = 'editor.html';
                 } else {
                     // Estamos en la raíz del proyecto
                     window.location.href = 'components/editor.html';
                 }
             }
         });
         
        document.getElementById('forceLogoutBtn').addEventListener('click', async () => {
            const modalElement = document.getElementById('unsavedDataWarningModal');
            if (modalElement && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                } else {
                    // Si no hay instancia, crear una nueva y cerrarla
                    const newModal = new bootstrap.Modal(modalElement);
                    newModal.hide();
                }
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
            
            // Limpiar URLESTUDIO y audios temporales, luego proceder con logout
             if (window.TempAudioManager) {
                 await window.TempAudioManager.discardReport();
             } else if (window.urlStudyManager) {
                 window.urlStudyManager.clearActiveStudy();
             }
             
             // Limpiar también datos de persistencia generales
             this.clearGeneralPersistedContent();
             
             await this.logout();
         });
     }
     
     /**
      * Verificar si hay datos de persistencia generales (sin estudio activo)
      * IMPORTANTE: Ignora contenido si hasUnsavedChanges es false (informe ya guardado)
      */
     checkGeneralPersistedContent() {
         // Verificar datos de persistencia en localStorage
         for (let i = 0; i < localStorage.length; i++) {
             const key = localStorage.key(i);
             if (key && key.startsWith('editor_persistence_')) {
                 const data = localStorage.getItem(key);
                 if (data) {
                     try {
                         const parsed = JSON.parse(data);
                         // Si hasUnsavedChanges es explícitamente false, ignorar (informe ya guardado)
                         if (parsed.hasUnsavedChanges === false) {
                             continue; // Ignorar este contenido
                         }
                         // Solo considerar como "no guardado" si:
                         // 1. Tiene contenido significativo (> 200 chars) Y tiene cambios sin guardar (true o undefined)
                         // 2. Tiene audio sin guardar
                         // 3. hasUnsavedChanges es explícitamente true
                         if ((parsed.tinymce && parsed.tinymce.content && parsed.tinymce.content.trim().length > 200 && 
                              (parsed.hasUnsavedChanges === true || parsed.hasUnsavedChanges === undefined)) ||
                             (parsed.audio && (parsed.hasUnsavedChanges === true || parsed.hasUnsavedChanges === undefined)) ||
                             parsed.hasUnsavedChanges === true) {
                             return true;
                         }
                     } catch (e) {
                         // Ignorar errores de parsing
                     }
                 }
             }
         }
         
         // Verificar datos de sesión en sessionStorage
         for (let i = 0; i < sessionStorage.length; i++) {
             const key = sessionStorage.key(i);
             if (key && key.startsWith('editor_session_')) {
                 const data = sessionStorage.getItem(key);
                 if (data) {
                     try {
                         const parsed = JSON.parse(data);
                         // Si hasUnsavedChanges es explícitamente false, ignorar (informe ya guardado)
                         if (parsed.hasUnsavedChanges === false) {
                             continue; // Ignorar este contenido
                         }
                         // Solo considerar como "no guardado" si tiene cambios reales
                         if ((parsed.tinymce && parsed.tinymce.content && parsed.tinymce.content.trim().length > 200 && 
                              (parsed.hasUnsavedChanges === true || parsed.hasUnsavedChanges === undefined)) ||
                             (parsed.audio && (parsed.hasUnsavedChanges === true || parsed.hasUnsavedChanges === undefined)) ||
                             parsed.hasUnsavedChanges === true) {
                             return true;
                         }
                     } catch (e) {
                         // Ignorar errores de parsing
                     }
                 }
             }
         }
         
         return false;
     }
     
     /**
      * Limpiar datos de persistencia generales
      */
     clearGeneralPersistedContent() {
         // Limpiar localStorage
         const keysToRemove = [];
         for (let i = 0; i < localStorage.length; i++) {
             const key = localStorage.key(i);
             if (key && (key.startsWith('editor_persistence_') || 
                        key.startsWith('editor_session_') ||
                        key.includes('_default') || // Incluir claves como 'default_default'
                        key.startsWith('tinymce_') ||
                        key.startsWith('audio_'))) {
                 keysToRemove.push(key);
             }
         }
         keysToRemove.forEach(key => localStorage.removeItem(key));
         
         // Limpiar sessionStorage
         const sessionKeysToRemove = [];
         for (let i = 0; i < sessionStorage.length; i++) {
             const key = sessionStorage.key(i);
             if (key && key.startsWith('editor_session_')) {
                 sessionKeysToRemove.push(key);
             }
         }
         sessionKeysToRemove.forEach(key => sessionStorage.removeItem(key));
     }
     
     /**
      * Manejar redirección después del login
      */
    handlePostLoginRedirect() {
        const redirectUrl = sessionStorage.getItem('redirectAfterLogin');
        if (redirectUrl) {
            sessionStorage.removeItem('redirectAfterLogin');
            window.location.href = redirectUrl;
        }
    }
}

// Crear instancia global
const auth = new AuthMiddleware();

// Exportar también como authManager para compatibilidad
window.authManager = auth;
window.auth = auth;

// Funciones de conveniencia globales
window.requireAuth = () => auth.requireAuth();
window.requireGuest = () => auth.requireGuest();
window.logout = () => auth.logout();
window.getCurrentUser = () => auth.getCurrentUser();

// Auto-configurar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    auth.updateUserUI();
    auth.setupLogoutButtons();
});

// Exportar para uso en módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AuthMiddleware;
}