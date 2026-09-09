/**
 * PACS Uploader - Módulo reutilizable para subir estudios DICOM a Orthanc
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Módulo independiente y reutilizable para subir archivos DICOM o ZIP a Orthanc
 * Puede ser usado como plugin en cualquier parte del sistema
 */

class PACSUploader {
    constructor(options = {}) {
        // Configuración por defecto
        this.apiBaseUrl = options.apiBaseUrl || 'api/pacs-manager/';
        this.maxFileSize = options.maxFileSize || 500 * 1024 * 1024; // 500MB por defecto
        this.allowedExtensions = options.allowedExtensions || ['.dcm', '.dicom', '.zip'];
        this.onUploadStart = options.onUploadStart || null;
        this.onUploadProgress = options.onUploadProgress || null;
        this.onUploadComplete = options.onUploadComplete || null;
        this.onUploadError = options.onUploadError || null;
        this.showNotifications = options.showNotifications !== false; // true por defecto
        
        // Estado interno
        this.uploading = false;
        this.currentUploads = [];
    }
    
    /**
     * Inicializa el módulo
     */
    init() {
        console.log('🔧 Inicializando PACS Uploader...');
    }
    
    /**
     * Sube uno o múltiples archivos DICOM a Orthanc
     * 
     * @param {File|FileList|File[]} files - Archivo(s) a subir
     * @returns {Promise<Object>} Resultado de la subida
     */
    async uploadFiles(files) {
        if (this.uploading) {
            throw new Error('Ya hay una subida en progreso');
        }
        
        // Normalizar a array
        const fileArray = files instanceof FileList 
            ? Array.from(files) 
            : Array.isArray(files) 
                ? files 
                : [files];
        
        if (fileArray.length === 0) {
            throw new Error('No se proporcionaron archivos');
        }
        
        // Validar archivos
        const validationErrors = this.validateFiles(fileArray);
        if (validationErrors.length > 0) {
            throw new Error('Errores de validación:\n' + validationErrors.join('\n'));
        }
        
        this.uploading = true;
        this.currentUploads = fileArray.map(f => ({
            file: f,
            status: 'pending',
            progress: 0
        }));
        
        try {
            // Callback de inicio
            if (this.onUploadStart) {
                this.onUploadStart(fileArray);
            }
            
            // Guardar referencia al toast de información para poder cerrarlo después
            let infoToast = null;
            if (this.showNotifications) {
                infoToast = this.showInfo(`Subiendo ${fileArray.length} archivo(s) a Orthanc...`);
            }
            
            // Preparar FormData
            // Para múltiples archivos, usar 'files[]' para que PHP los reciba como array
            const formData = new FormData();
            fileArray.forEach(file => {
                formData.append('files[]', file);
            });
            
            // Usar XMLHttpRequest para poder monitorear el progreso real
            const result = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                
                // Monitorear progreso de subida
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        // Callback de progreso
                        if (this.onUploadProgress) {
                            this.onUploadProgress(percentComplete, null);
                        }
                    }
                });
                
                // Manejar respuesta
                xhr.addEventListener('load', () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            const result = JSON.parse(xhr.responseText);
                            resolve(result);
                        } catch (e) {
                            console.error('❌ Error parseando respuesta:', e);
                            console.error('📄 Respuesta del servidor:', xhr.responseText.substring(0, 500));
                            
                            // Intentar extraer mensaje de error si es HTML
                            const errorMatch = xhr.responseText.match(/<title>(.*?)<\/title>/i) || 
                                             xhr.responseText.match(/Fatal error: (.*?)(?:<|$)/i) ||
                                             xhr.responseText.match(/Parse error: (.*?)(?:<|$)/i);
                            const errorMsg = errorMatch ? errorMatch[1] : 'Error del servidor (respuesta no válida)';
                            
                            reject(new Error(errorMsg + ' (HTTP ' + xhr.status + ')'));
                        }
                    } else {
                        try {
                            const result = JSON.parse(xhr.responseText);
                            if (!result.success) {
                                // Mejorar mensaje de error
                                let errorMsg = result.error || 'Error al subir archivos';
                                if (errorMsg.includes('database engine')) {
                                    errorMsg = 'Error en la base de datos de Orthanc. El archivo puede ser muy grande o el servidor está ocupado. Intenta nuevamente más tarde.';
                                } else if (errorMsg.includes('timeout')) {
                                    errorMsg = 'La subida tardó demasiado tiempo. El archivo puede ser muy grande. Intenta con un archivo más pequeño o espera unos minutos.';
                                }
                                reject(new Error(errorMsg));
                            } else {
                                resolve(result);
                            }
                        } catch (e) {
                            reject(new Error('Error del servidor (HTTP ' + xhr.status + ')'));
                        }
                    }
                });
                
                // Manejar errores
                xhr.addEventListener('error', () => {
                    reject(new Error('Error de red al subir archivos'));
                });
                
                xhr.addEventListener('abort', () => {
                    reject(new Error('Subida cancelada'));
                });
                
                // Iniciar subida
                xhr.open('POST', this.apiBaseUrl + 'upload.php');
                xhr.send(formData);
            });
            
            if (!result.success) {
                throw new Error(result.error || 'Error al subir archivos');
            }
            
            // Actualizar estado de subidas
            if (result.results) {
                result.results.forEach((fileResult, index) => {
                    if (index < this.currentUploads.length) {
                        this.currentUploads[index].status = fileResult.success ? 'success' : 'error';
                        this.currentUploads[index].result = fileResult;
                    }
                });
            }
            
            // Callback de progreso (100%) - ya se llamó en el evento 'load', pero asegurarse
            if (this.onUploadProgress) {
                this.onUploadProgress(100, result);
            }
            
            // Cerrar toast de información si existe
            if (infoToast) {
                // Si es un elemento DOM, intentar cerrarlo
                if (infoToast instanceof HTMLElement) {
                    // Si es un alert de Bootstrap, usar data-bs-dismiss
                    const closeBtn = infoToast.querySelector('.btn-close');
                    if (closeBtn) {
                        closeBtn.click();
                    } else {
                        // Si no tiene botón de cierre, remover directamente
                        infoToast.remove();
                    }
                } else if (typeof infoToast === 'function') {
                    // Si es una función (para cerrar), llamarla
                    infoToast();
                }
            }
            
            // Callback de completado
            if (this.onUploadComplete) {
                this.onUploadComplete(result);
            }
            
            if (this.showNotifications) {
                if (result.success) {
                    this.showSuccess(result.message || 'Archivos subidos exitosamente');
                } else {
                    this.showWarning(result.message || 'Subida completada con algunos errores');
                }
            }
            
            return result;
            
        } catch (error) {
            console.error('❌ Error al subir archivos:', error);
            
            // Cerrar toast de información si existe
            if (infoToast) {
                // Si es un elemento DOM, intentar cerrarlo
                if (infoToast instanceof HTMLElement) {
                    const closeBtn = infoToast.querySelector('.btn-close');
                    if (closeBtn) {
                        closeBtn.click();
                    } else {
                        infoToast.remove();
                    }
                } else if (typeof infoToast === 'function') {
                    infoToast();
                }
            }
            
            // Callback de error
            if (this.onUploadError) {
                this.onUploadError(error);
            }
            
            if (this.showNotifications) {
                this.showError('Error al subir archivos: ' + error.message);
            }
            
            throw error;
        } finally {
            this.uploading = false;
        }
    }
    
    /**
     * Valida los archivos antes de subirlos
     * 
     * @param {File[]} files - Archivos a validar
     * @returns {string[]} Array de mensajes de error (vacío si todo está bien)
     */
    validateFiles(files) {
        const errors = [];
        
        files.forEach((file, index) => {
            // Validar extensión
            const fileName = file.name.toLowerCase();
            const hasValidExtension = this.allowedExtensions.some(ext => 
                fileName.endsWith(ext.toLowerCase())
            );
            
            if (!hasValidExtension) {
                errors.push(`Archivo "${file.name}": Extensión no permitida. Permitidas: ${this.allowedExtensions.join(', ')}`);
            }
            
            // Validar tamaño
            if (file.size > this.maxFileSize) {
                const maxSizeMB = (this.maxFileSize / (1024 * 1024)).toFixed(2);
                errors.push(`Archivo "${file.name}": Tamaño excede el máximo de ${maxSizeMB}MB`);
            }
            
            // Validar que el archivo no esté vacío
            if (file.size === 0) {
                errors.push(`Archivo "${file.name}": El archivo está vacío`);
            }
        });
        
        return errors;
    }
    
    /**
     * Crea un input file y abre el diálogo de selección
     * 
     * @param {Object} options - Opciones para el input
     * @returns {Promise<FileList>} Archivos seleccionados
     */
    async selectFiles(options = {}) {
        return new Promise((resolve, reject) => {
            const input = document.createElement('input');
            input.type = 'file';
            input.multiple = options.multiple !== false; // true por defecto
            input.accept = options.accept || this.allowedExtensions.map(ext => 
                ext === '.dcm' || ext === '.dicom' ? 'application/dicom' : 
                ext === '.zip' ? 'application/zip' : 
                ext
            ).join(',');
            
            input.onchange = (e) => {
                const files = e.target.files;
                if (files.length > 0) {
                    resolve(files);
                } else {
                    reject(new Error('No se seleccionaron archivos'));
                }
            };
            
            input.oncancel = () => {
                reject(new Error('Selección de archivos cancelada'));
            };
            
            input.click();
        });
    }
    
    /**
     * Muestra notificación de información
     * @returns {HTMLElement|null} Referencia al elemento toast/alert para poder cerrarlo
     */
    showInfo(message) {
        // Intentar usar el sistema de notificaciones del sistema si existe
        if (typeof showInfo === 'function') {
            const result = showInfo(message);
            return result || null;
        } else if (typeof PACSManager !== 'undefined' && PACSManager.prototype.showInfo) {
            // Si PACSManager está disponible, usar su método
            // Intentar obtener la instancia global de PACSManager
            if (window.pacsManager && typeof window.pacsManager.showInfo === 'function') {
                return window.pacsManager.showInfo(message);
            } else {
                // Si no hay instancia global, crear una temporal (no ideal, pero funciona)
                const manager = new PACSManager();
                return manager.showInfo(message);
            }
        } else {
            // Fallback: alert simple
            console.log('ℹ️ ' + message);
            return null;
        }
    }
    
    /**
     * Muestra notificación de éxito
     */
    showSuccess(message) {
        if (typeof showSuccess === 'function') {
            showSuccess(message);
        } else if (typeof PACSManager !== 'undefined' && PACSManager.prototype.showSuccess) {
            const manager = new PACSManager();
            manager.showSuccess(message);
        } else {
            console.log('✅ ' + message);
        }
    }
    
    /**
     * Muestra notificación de advertencia
     */
    showWarning(message) {
        if (typeof showWarning === 'function') {
            showWarning(message);
        } else if (typeof PACSManager !== 'undefined' && PACSManager.prototype.showWarning) {
            const manager = new PACSManager();
            manager.showWarning(message);
        } else {
            console.warn('⚠️ ' + message);
        }
    }
    
    /**
     * Muestra notificación de error
     */
    showError(message) {
        if (typeof showError === 'function') {
            showError(message);
        } else if (typeof PACSManager !== 'undefined' && PACSManager.prototype.showError) {
            const manager = new PACSManager();
            manager.showError(message);
        } else {
            console.error('❌ ' + message);
        }
    }
    
    /**
     * Obtiene el estado actual de las subidas
     * 
     * @returns {Object} Estado actual
     */
    getStatus() {
        return {
            uploading: this.uploading,
            currentUploads: this.currentUploads.map(u => ({
                fileName: u.file.name,
                status: u.status,
                progress: u.progress,
                result: u.result
            }))
        };
    }
    
    /**
     * Cancela la subida actual (si es posible)
     */
    cancel() {
        // Nota: No se puede cancelar una petición fetch después de enviarse
        // Esto solo marca el estado como cancelado
        if (this.uploading) {
            this.uploading = false;
            console.log('⚠️ Subida cancelada (puede que los archivos ya se hayan subido)');
        }
    }
}

// Exportar para uso como módulo
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PACSUploader;
}
