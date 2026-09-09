/**
 * Clase Simplificada para Manejo de Permisos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Versión simplificada que usa validate-session-simple.php
 */
class SimplePermissionManager {
    constructor() {
        this.currentUser = null;
        this.userPermissions = [];
        this.apiBaseUrl = this.getApiBaseUrl();
    }
    
    /**
     * Prefijo relativo hacia la raíz de la app (login, dashboard, etc.)
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
     * Obtiene la URL base de la API según la ubicación del archivo
     */
    getApiBaseUrl() {
        const pathname = window.location.pathname || '';
        if (pathname.includes('/assets/')) {
            return '../api/';
        }
        return this.getAppRootPrefix() + 'api/';
    }
    
    /**
     * Verifica si el usuario tiene un permiso específico usando validate-session-simple.php
     * @param {string} permission - Clave del permiso a verificar
     * @param {string} section - Sección opcional para contexto
     * @returns {Promise<Object>} Resultado de la verificación
     */
    async checkPermission(permission, section = null) {
        try {
            console.log(`🔍 Verificando permiso: ${permission}${section ? ` (${section})` : ''}`);
            
            const response = await fetch(`${this.apiBaseUrl}auth/validate-session-simple.php`);
            const result = await response.json();
            
            if (result.success && result.user) {
                this.currentUser = result.user;
                this.userPermissions = result.user.permisos || [];
                
                // ROOT siempre tiene acceso total
                const isRoot = result.user.nivel === 'root';
                const hasPermission = isRoot || this.userPermissions.includes(permission) || this.userPermissions.includes('all');
                
                console.log('👤 Usuario:', result.user.nombre, result.user.apellido);
                console.log('🔐 Nivel:', result.user.nivel);
                console.log('🔐 Permisos:', this.userPermissions);
                console.log('✅ Verificación:', hasPermission ? 'PERMITIDO' : 'DENEGADO');
                if (isRoot) {
                    console.log('✅ Usuario ROOT - acceso automático permitido');
                }
                
                return {
                    success: true,
                    hasPermission: hasPermission,
                    permission: permission,
                    section: section,
                    user: result.user
                };
            } else {
                console.error('❌ Error verificando permisos:', result.error || 'No hay sesión activa');
                return {
                    success: false,
                    error: result.error || 'No hay sesión activa',
                    hasPermission: false
                };
            }
            
        } catch (error) {
            console.error('🚨 Error verificando permisos:', error);
            return {
                success: false,
                error: error.message,
                hasPermission: false
            };
        }
    }
    
    /**
     * Verifica permisos y muestra modal de acceso denegado si es necesario
     * @param {string} permission - Clave del permiso a verificar
     * @param {string} section - Sección para contexto
     * @param {Object} options - Opciones adicionales
     * @returns {Promise<boolean>} true si tiene permisos, false si no
     */
    async requirePermission(permission, section = null, options = {}) {
        const result = await this.checkPermission(permission, section);
        
        if (result.success && result.hasPermission) {
            return true;
        }
        
        // Mostrar modal de acceso denegado
        this.showPermissionDeniedModal(result, options);
        return false;
    }
    
    /**
     * Muestra el modal de acceso denegado
     * @param {Object} permissionResult - Resultado de la verificación de permisos
     * @param {Object} options - Opciones adicionales
     */
    showPermissionDeniedModal(permissionResult, options = {}) {
        const {
            permission,
            section,
            user
        } = permissionResult;
        
        const rootPrefix = this.getAppRootPrefix();
        const {
            title = 'Acceso Denegado',
            message = `No tienes permisos para acceder a <strong>${section || permission}</strong>.`,
            redirectUrl = rootPrefix + 'dashboard-unified.html',
            showLogoutButton = true,
            customButtons = []
        } = options;
        
        // Crear modal dinámicamente
        const modalId = 'permissionDeniedModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        // Crear botones personalizados o usar los por defecto
        let buttonsHtml = '';
        if (customButtons.length > 0) {
            buttonsHtml = customButtons.map(btn => `
                <button type="button" class="btn ${btn.class || 'btn-primary'}" onclick="${btn.onclick}">
                    <i class="${btn.icon || 'fas fa-arrow-left'} me-2"></i>
                    ${btn.text}
                </button>
            `).join('');
        } else {
            buttonsHtml = `
                <button type="button" class="btn btn-primary" onclick="window.location.href='${redirectUrl}'">
                    <i class="fas fa-arrow-left me-2"></i>
                    Volver al Dashboard
                </button>
                ${showLogoutButton ? `
                    <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='${rootPrefix}login.html'">
                        <i class="fas fa-sign-out-alt me-2"></i>
                        Cerrar Sesión
                    </button>
                ` : ''}
            `;
        }
        
        // Crear el modal HTML
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="permissionDeniedModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="permissionDeniedModalLabel">
                                <i class="fas fa-shield-alt me-2"></i>
                                ${title}
                            </h5>
                        </div>
                        <div class="modal-body text-center">
                            <div class="mb-3">
                                <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                            </div>
                            <h6 class="text-danger mb-3">Sin Permisos</h6>
                            <p class="text-muted mb-3">
                                ${message}
                            </p>
                            <div class="alert alert-warning mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Permiso requerido:</strong> ${permission}
                            </div>
                        </div>
                        <div class="modal-footer justify-content-center">
                            ${buttonsHtml}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Verificar que Bootstrap esté disponible antes de mostrar el modal
        try {
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                console.error('Bootstrap no está disponible. El modal de acceso denegado no se puede mostrar.');
                // Redirigir directamente sin mostrar alert
                if (redirectUrl) {
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 100);
                }
                return;
            }
            
            // Mostrar modal con configuración mejorada
            const modal = new bootstrap.Modal(document.getElementById(modalId), {
                backdrop: true,
                keyboard: false,
                focus: true
            });
            modal.show();
        } catch (error) {
            console.error('Error mostrando modal de acceso denegado:', error);
            // Redirigir directamente sin mostrar alert
            if (redirectUrl) {
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 100);
            }
        }
        
        // Limpiar modal cuando se cierre (solo si el modal existe)
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            modalElement.addEventListener('hidden.bs.modal', function() {
                try {
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
                    if (this.parentNode) {
                        this.remove();
                    }
                } catch (error) {
                    console.error('Error limpiando modal:', error);
                }
            });
        }
    }
    
    /**
     * Obtiene información del usuario actual
     * @returns {Object|null} Información del usuario o null si no está disponible
     */
    getCurrentUser() {
        return this.currentUser;
    }
    
    /**
     * Obtiene permisos del usuario actual
     * @returns {Array} Array de permisos del usuario
     */
    getUserPermissions() {
        return this.userPermissions;
    }
    
    /**
     * Verifica si el usuario tiene un permiso específico (método síncrono)
     * @param {string} permission - Clave del permiso
     * @returns {boolean} true si tiene el permiso
     */
    hasPermissionSync(permission) {
        // ROOT siempre tiene acceso total
        const isRoot = this.currentUser && this.currentUser.nivel === 'root';
        return isRoot || this.userPermissions.includes(permission) || this.userPermissions.includes('all');
    }
}

// Instancia global
window.simplePermissionManager = new SimplePermissionManager();

// Función de conveniencia para verificación rápida
window.requirePermissionSimple = async function(permission, section = null, options = {}) {
    return await window.simplePermissionManager.requirePermission(permission, section, options);
};

// Función de conveniencia para verificación simple
window.checkPermissionSimple = async function(permission, section = null) {
    return await window.simplePermissionManager.checkPermission(permission, section);
};
