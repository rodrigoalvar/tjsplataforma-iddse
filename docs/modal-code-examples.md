# Ejemplos de Código - Correcciones de Modales

## Archivo: `estudios-manager.html` - CSS para Modales

```css
/* ===== MODALES DE REASIGNACIÓN Y DESASIGNACIÓN ===== */

/* CSS específico para el modal de reasignación */
#reassignModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#reassignModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#reassignModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de reasignación debe aparecer sobre otros elementos */
#reassignModal.show {
    display: block !important;
}

#reassignModal.modal.show {
    display: block !important;
}

/* CSS específico para el modal de desasignación */
#unassignConfirmModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#unassignConfirmModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#unassignConfirmModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de desasignación debe aparecer sobre otros elementos */
#unassignConfirmModal.show {
    display: block !important;
}

#unassignConfirmModal.modal.show {
    display: block !important;
}

/* ===== MODALES DE INFORMACIÓN Y CONFIRMACIÓN ===== */

/* CSS específico para el modal de información del estudio */
#studyInfoModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#studyInfoModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#studyInfoModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de información debe aparecer sobre otros elementos */
#studyInfoModal.show {
    display: block !important;
}

#studyInfoModal.modal.show {
    display: block !important;
}

/* CSS específico para el modal de confirmación de eliminación */
#confirmationModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#confirmationModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#confirmationModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de confirmación debe aparecer sobre otros elementos */
#confirmationModal.show {
    display: block !important;
}

#confirmationModal.modal.show {
    display: block !important;
}

/* CSS específico para el modal de confirmación múltiple */
#multipleFilesConfirmationModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#multipleFilesConfirmationModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#multipleFilesConfirmationModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de confirmación múltiple debe aparecer sobre otros elementos */
#multipleFilesConfirmationModal.show {
    display: block !important;
}

#multipleFilesConfirmationModal.modal.show {
    display: block !important;
}

/* ===== MODALES DE AUTENTICACIÓN ===== */

/* CSS específico para el modal de cerrar sesión */
#logoutConfirmModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#logoutConfirmModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#logoutConfirmModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de cerrar sesión debe aparecer sobre otros elementos */
#logoutConfirmModal.show {
    display: block !important;
}

#logoutConfirmModal.modal.show {
    display: block !important;
}

/* CSS específico para el modal de advertencia de datos no guardados */
#unsavedDataWarningModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#unsavedDataWarningModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#unsavedDataWarningModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal de advertencia debe aparecer sobre otros elementos */
#unsavedDataWarningModal.show {
    display: block !important;
}

#unsavedDataWarningModal.modal.show {
    display: block !important;
}

/* ===== MODALES DINÁMICOS ===== */

/* CSS específico para el modal dinámico de confirmación de eliminación */
#dynamicConfirmationModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#dynamicConfirmationModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#dynamicConfirmationModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal dinámico de confirmación debe aparecer sobre otros elementos */
#dynamicConfirmationModal.show {
    display: block !important;
}

#dynamicConfirmationModal.modal.show {
    display: block !important;
}
```

## Archivo: `assets/js/estudios-manager.js` - Función de Reasignación

```javascript
/**
 * Muestra el modal de reasignación con configuración mejorada
 */
showReassignModal(studyId, currentUserId) {
    try {
        console.log('🔍 Debug - Mostrando modal de reasignación para estudio:', studyId);
        
        // Obtener datos del estudio
        const study = this.studies.find(s => s.id === studyId);
        if (!study) {
            console.error('Estudio no encontrado:', studyId);
            return;
        }
        
        // Crear modal dinámicamente
        const modalId = 'reassignModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        // Crear el modal HTML
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="reassignModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="reassignModalLabel">
                                <i class="fas fa-user-plus me-2"></i>Cambiar Asignación
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="reassignUserSelect" class="form-label">Asignar a:</label>
                                <select class="form-select" id="reassignUserSelect">
                                    <option value="">Seleccionar usuario...</option>
                                </select>
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Estudio:</strong> ${study.patient_name || 'Sin nombre'} - ${study.study_date || 'Sin fecha'}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-primary" id="confirmReassignBtn">
                                <i class="fas fa-check me-1"></i>Confirmar Asignación
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Cargar usuarios disponibles
        this.loadUsersForReassignment(studyId, currentUserId);
        
        // Configurar eventos
        this.setupReassignModalEvents(studyId);
        
        // Mostrar modal con configuración mejorada
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
        
    } catch (error) {
        console.error('Error mostrando modal de reasignación:', error);
        this.showBootstrapAlert(
            'Error', 
            'No se pudo abrir el modal de reasignación. Inténtalo de nuevo.', 
            'error', 
            0
        );
    }
}
```

## Archivo: `assets/js/estudios-manager.js` - Modal Dinámico de Confirmación

```javascript
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
```

## Archivo: `js/auth-middleware.js` - Modal de Cerrar Sesión

```javascript
/**
 * Muestra el modal de confirmación de cerrar sesión
 */
showLogoutConfirmation() {
    try {
        console.log('🔍 Debug - Mostrando modal de confirmación de cerrar sesión');
        
        // Crear modal dinámicamente
        const modalId = 'logoutConfirmModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        // Crear el modal HTML
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title" id="logoutConfirmModalLabel">
                                <i class="fas fa-sign-out-alt me-2"></i>Confirmar Cerrar Sesión
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-question-circle text-warning" style="font-size: 2rem;"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <p class="mb-1">¿Está seguro de que desea cerrar la sesión?</p>
                                    <small class="text-muted">Se perderán todos los datos no guardados.</small>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-warning" id="confirmLogoutBtn">
                                <i class="fas fa-sign-out-alt me-1"></i>Cerrar Sesión
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Configurar eventos
        document.getElementById('confirmLogoutBtn').addEventListener('click', () => {
            console.log('🔍 Debug - Confirmación de cerrar sesión recibida');
            this.logout();
        });
        
        // Mostrar modal con configuración mejorada
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
        
    } catch (error) {
        console.error('Error mostrando modal de cerrar sesión:', error);
        // Fallback a confirm nativo
        if (confirm('¿Está seguro de que desea cerrar la sesión?')) {
            this.logout();
        }
    }
}
```

## Plantilla para Nuevos Modales

### HTML (CSS)
```css
/* CSS específico para el nuevo modal */
#nuevoModal {
    z-index: 10000 !important;
    position: fixed !important;
}

#nuevoModal .modal-dialog {
    z-index: 10001 !important;
    position: relative !important;
}

#nuevoModal .modal-content {
    z-index: 10002 !important;
    position: relative !important;
    pointer-events: auto !important;
}

/* Modal debe aparecer sobre otros elementos */
#nuevoModal.show {
    display: block !important;
}

#nuevoModal.modal.show {
    display: block !important;
}
```

### JavaScript
```javascript
/**
 * Muestra el nuevo modal con configuración mejorada
 */
showNuevoModal() {
    try {
        console.log('🔍 Debug - Mostrando nuevo modal');
        
        // Crear modal dinámicamente
        const modalId = 'nuevoModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        // Crear el modal HTML
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="nuevoModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="nuevoModalLabel">
                                <i class="fas fa-icon me-2"></i>Título del Modal
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Contenido del modal -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-primary" id="confirmNuevoBtn">
                                <i class="fas fa-check me-1"></i>Confirmar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Configurar eventos
        document.getElementById('confirmNuevoBtn').addEventListener('click', () => {
            // Lógica de confirmación
            const modalElement = document.getElementById(modalId);
            const modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (modalInstance) {
                modalInstance.hide();
            }
        });
        
        // Mostrar modal con configuración mejorada
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
        
    } catch (error) {
        console.error('Error mostrando nuevo modal:', error);
        this.showBootstrapAlert(
            'Error', 
            'No se pudo abrir el modal. Inténtalo de nuevo.', 
            'error', 
            0
        );
    }
}
```

---

**Nota**: Estos ejemplos muestran el patrón completo implementado en el sistema. Para nuevos modales, seguir exactamente esta estructura para garantizar la consistencia y funcionalidad correcta.
