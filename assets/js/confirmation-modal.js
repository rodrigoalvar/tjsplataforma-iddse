/**
 * Modal de Confirmación Alternativo
 * Solución robusta para confirmaciones de eliminación
 */
class ConfirmationModal {
    constructor() {
        this.modalId = 'altConfirmationModal';
        this.isShowing = false;
        this.currentCallback = null;
    }

    /**
     * Muestra el modal de confirmación para un archivo individual
     */
    showSingleFileConfirmation(fileName, callback) {
        const message = `¿Está seguro de que desea eliminar el archivo "${fileName}"?`;
        const details = 'Esta acción eliminará tanto el archivo físico como su referencia en la base de datos.';
        
        this.showConfirmation(message, details, [], callback);
    }

    /**
     * Muestra el modal de confirmación para múltiples archivos
     */
    showMultipleFilesConfirmation(files, callback) {
        const message = `¿Está seguro de que desea eliminar ${files.length} archivo(s)?`;
        const details = 'Esta acción eliminará tanto los archivos físicos como sus referencias en la base de datos.';
        const fileNames = files.map(file => file.name || file.file_name || 'Archivo sin nombre');
        
        this.showConfirmation(message, details, fileNames, callback);
    }

    /**
     * Muestra el modal de confirmación
     */
    showConfirmation(message, details, fileNames = [], callback) {
        // Evitar múltiples modales simultáneos
        if (this.isShowing) {
            console.warn('Modal de confirmación ya está mostrándose');
            return;
        }

        this.currentCallback = callback;
        this.isShowing = true;

        // Crear el modal HTML
        const modalHtml = this.createModalHtml(message, details, fileNames);
        
        // Remover modal anterior si existe
        const existingModal = document.getElementById(this.modalId);
        if (existingModal) {
            existingModal.remove();
        }

        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Configurar eventos
        this.setupEventListeners();

        // Mostrar modal con animación
        setTimeout(() => {
            const modal = document.getElementById(this.modalId);
            if (modal) {
                modal.style.opacity = '1';
                modal.style.transform = 'scale(1)';
            }
        }, 10);

        console.log('✅ Modal de confirmación mostrado correctamente');
    }

    /**
     * Crea el HTML del modal
     */
    createModalHtml(message, details, fileNames) {
        const filesListHtml = fileNames.length > 0 ? `
            <div class="files-list">
                <h6 class="text-muted mb-2">Archivos a eliminar:</h6>
                <div class="files-container">
                    ${fileNames.map(fileName => `
                        <div class="file-item">
                            <i class="fas fa-file text-muted me-2"></i>
                            <span class="text-truncate">${fileName}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        ` : '';

        return `
            <div id="${this.modalId}" class="confirmation-modal-overlay">
                <div class="confirmation-modal">
                    <div class="confirmation-modal-header">
                        <div class="confirmation-modal-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h4 class="confirmation-modal-title">Confirmar Eliminación</h4>
                    </div>
                    
                    <div class="confirmation-modal-body">
                        <p class="confirmation-message">${message}</p>
                        <p class="confirmation-details">${details}</p>
                        ${filesListHtml}
                    </div>
                    
                    <div class="confirmation-modal-footer">
                        <button type="button" class="btn btn-secondary" id="altCancelBtn">
                            <i class="fas fa-times me-1"></i>Cancelar
                        </button>
                        <button type="button" class="btn btn-danger" id="altConfirmBtn">
                            <i class="fas fa-trash me-1"></i>Eliminar
                        </button>
                    </div>
                </div>
            </div>
            
            <style>
                .confirmation-modal-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0, 0, 0, 0.6);
                    z-index: 99999 !important;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                }
                
                .confirmation-modal {
                    background: white;
                    border-radius: 12px;
                    padding: 0;
                    max-width: 500px;
                    width: 90%;
                    max-height: 80vh;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                    transform: scale(0.9);
                    transition: transform 0.3s ease;
                    overflow: hidden;
                }
                
                .confirmation-modal-header {
                    background: linear-gradient(135deg, #dc3545, #c82333);
                    color: white;
                    padding: 20px;
                    display: flex;
                    align-items: center;
                    gap: 15px;
                }
                
                .confirmation-modal-icon {
                    font-size: 24px;
                    opacity: 0.9;
                }
                
                .confirmation-modal-title {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                }
                
                .confirmation-modal-body {
                    padding: 25px;
                }
                
                .confirmation-message {
                    font-size: 16px;
                    color: #333;
                    margin-bottom: 10px;
                    font-weight: 500;
                }
                
                .confirmation-details {
                    font-size: 14px;
                    color: #666;
                    margin-bottom: 20px;
                }
                
                .files-list {
                    margin-top: 15px;
                }
                
                .files-container {
                    max-height: 200px;
                    overflow-y: auto;
                    border: 1px solid #e9ecef;
                    border-radius: 6px;
                    padding: 10px;
                    background: #f8f9fa;
                }
                
                .file-item {
                    display: flex;
                    align-items: center;
                    padding: 5px 0;
                    border-bottom: 1px solid #e9ecef;
                }
                
                .file-item:last-child {
                    border-bottom: none;
                }
                
                .confirmation-modal-footer {
                    padding: 20px 25px;
                    background: #f8f9fa;
                    display: flex;
                    justify-content: flex-end;
                    gap: 10px;
                }
                
                .btn {
                    padding: 8px 16px;
                    border-radius: 6px;
                    font-size: 14px;
                    font-weight: 500;
                    border: none;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }
                
                .btn-secondary {
                    background: #6c757d;
                    color: white;
                }
                
                .btn-secondary:hover {
                    background: #5a6268;
                }
                
                .btn-danger {
                    background: #dc3545;
                    color: white;
                }
                
                .btn-danger:hover {
                    background: #c82333;
                }
                
                @media (max-width: 576px) {
                    .confirmation-modal {
                        width: 95%;
                        margin: 10px;
                    }
                    
                    .confirmation-modal-header,
                    .confirmation-modal-body,
                    .confirmation-modal-footer {
                        padding: 15px;
                    }
                }
            </style>
        `;
    }

    /**
     * Configura los event listeners del modal
     */
    setupEventListeners() {
        const modal = document.getElementById(this.modalId);
        const confirmBtn = document.getElementById('altConfirmBtn');
        const cancelBtn = document.getElementById('altCancelBtn');

        if (!modal || !confirmBtn || !cancelBtn) {
            console.error('❌ ERROR: Elementos del modal no encontrados');
            this.hide();
            return;
        }

        // Botón de confirmación
        confirmBtn.addEventListener('click', () => {
            this.hide();
            if (this.currentCallback) {
                this.currentCallback();
            }
        });

        // Botón de cancelación
        cancelBtn.addEventListener('click', () => {
            this.hide();
        });

        // Cerrar con ESC
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                this.hide();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);

        // Cerrar al hacer clic en el overlay
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.hide();
            }
        });
    }

    /**
     * Oculta el modal
     */
    hide() {
        const modal = document.getElementById(this.modalId);
        if (modal) {
            modal.style.opacity = '0';
            modal.style.transform = 'scale(0.9)';
            
            setTimeout(() => {
                modal.remove();
                this.isShowing = false;
                this.currentCallback = null;
            }, 300);
        }
    }
}

// Instancia global
window.AltConfirmationModal = new ConfirmationModal();
