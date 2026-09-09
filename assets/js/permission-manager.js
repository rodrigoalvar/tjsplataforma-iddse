/**
 * Clase para Manejo Centralizado de Permisos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Reutilizable en todas las secciones del sistema
 */
class PermissionManager {
    constructor() {
        this.currentUser = null;
        this.userPermissions = [];
        this.apiBaseUrl = this.getApiBaseUrl();
    }
    
    /**
     * Obtiene la URL base de la API según la ubicación del archivo
     */
    getApiBaseUrl() {
        const pathname = window.location.pathname;
        if (pathname.includes('/components/')) {
            return '../api/';
        } else if (pathname.includes('/assets/')) {
            return '../api/';
        } else {
            return 'api/';
        }
    }
    
    /**
     * Verifica si el usuario tiene un permiso específico
     * @param {string} permission - Clave del permiso a verificar
     * @param {string} section - Sección opcional para contexto
     * @returns {Promise<Object>} Resultado de la verificación
     */
    async checkPermission(permission, section = null) {
        try {
            console.log(`🔍 Verificando permiso: ${permission}${section ? ` (${section})` : ''}`);
            
            const response = await fetch(`${this.apiBaseUrl}permissions/check.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    permission: permission,
                    section: section
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.currentUser = result.user;
                this.userPermissions = result.user.permisos || [];
                
                console.log('👤 Usuario:', result.user.nombre, result.user.apellido);
                console.log('🔐 Permisos:', this.userPermissions);
                console.log('✅ Verificación:', result.hasPermission ? 'PERMITIDO' : 'DENEGADO');
                
                return {
                    success: true,
                    hasPermission: result.hasPermission,
                    permission: result.permission,
                    section: result.section,
                    permissionInfo: result.permissionInfo,
                    user: result.user
                };
            } else {
                console.error('❌ Error verificando permisos:', result.error);
                return {
                    success: false,
                    error: result.error,
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
            permissionInfo,
            user
        } = permissionResult;
        
        const {
            title = 'Acceso Denegado',
            message = `No tienes permisos para acceder a <strong>${section || permissionInfo?.permission_name || permission}</strong>.`,
            redirectUrl = 'dashboard-unified.html',
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
                    <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='login.html'">
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
                                <strong>Permiso requerido:</strong> ${permissionInfo?.permission_name || permission}
                                ${permissionInfo?.description ? `<br><small>${permissionInfo.description}</small>` : ''}
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
        
        // Mostrar modal con configuración mejorada
        const modal = new bootstrap.Modal(document.getElementById(modalId), {
            backdrop: true,
            keyboard: false,
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
     * Verifica múltiples permisos
     * @param {Array} permissions - Array de permisos a verificar
     * @param {string} section - Sección para contexto
     * @returns {Promise<Object>} Resultado de todas las verificaciones
     */
    async checkMultiplePermissions(permissions, section = null) {
        const results = {};
        
        for (const permission of permissions) {
            results[permission] = await this.checkPermission(permission, section);
        }
        
        return results;
    }
    
    /**
     * Verifica si el usuario tiene al menos uno de los permisos especificados
     * @param {Array} permissions - Array de permisos a verificar
     * @param {string} section - Sección para contexto
     * @returns {Promise<boolean>} true si tiene al menos uno de los permisos
     */
    async hasAnyPermission(permissions, section = null) {
        const results = await this.checkMultiplePermissions(permissions, section);
        
        return Object.values(results).some(result => result.success && result.hasPermission);
    }
    
    /**
     * Verifica si el usuario tiene todos los permisos especificados
     * @param {Array} permissions - Array de permisos a verificar
     * @param {string} section - Sección para contexto
     * @returns {Promise<boolean>} true si tiene todos los permisos
     */
    async hasAllPermissions(permissions, section = null) {
        const results = await this.checkMultiplePermissions(permissions, section);
        
        return Object.values(results).every(result => result.success && result.hasPermission);
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
        return this.userPermissions.includes(permission) || this.userPermissions.includes('all');
    }
    
    /**
     * Obtiene información de todos los permisos del sistema
     * @returns {Promise<Object>} Información de permisos del sistema
     */
    async getSystemPermissions() {
        try {
            const response = await fetch(`${this.apiBaseUrl}users/permissions-simple.php`);
            const result = await response.json();
            
            if (result.success) {
                return result.data;
            } else {
                throw new Error(result.error || 'Error obteniendo permisos del sistema');
            }
        } catch (error) {
            console.error('Error obteniendo permisos del sistema:', error);
            return null;
        }
    }
}

// Instancia global
window.permissionManager = new PermissionManager();

// Función de conveniencia para verificación rápida
window.requirePermission = async function(permission, section = null, options = {}) {
    return await window.permissionManager.requirePermission(permission, section, options);
};

// Función de conveniencia para verificación simple
window.checkPermission = async function(permission, section = null) {
    return await window.permissionManager.checkPermission(permission, section);
};
