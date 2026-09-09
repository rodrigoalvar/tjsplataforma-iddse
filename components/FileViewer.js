/**
 * FileViewer - Módulo reutilizable para visualización de archivos
 * Soporta imágenes, documentos, PDFs con miniaturas y lightbox
 */
class FileViewer {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.options = {
            showThumbnails: true,
            thumbnailSize: 120,
            allowDownload: true,
            lightboxEnabled: true,
            gridColumns: 4,
            ...options
        };
        
        this.files = [];
        this.lightboxOpen = false;
        
        this.init();
    }
    
    init() {
        if (!this.container) {
            console.error('FileViewer: Container not found');
            return;
        }
        
        // Agregar estilos CSS si no existen
        this.addStyles();
        
        // Crear estructura HTML
        this.createHTML();
        
        // Configurar event listeners
        this.setupEventListeners();
    }
    
    addStyles() {
        if (document.getElementById('fileviewer-styles')) return;
        
        const styles = `
            <style id="fileviewer-styles">
                .file-viewer {
                    width: 100%;
                }
                
                .file-viewer-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                    gap: 15px;
                    padding: 15px 0;
                }
                
                .file-item {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    padding: 10px;
                    border: 2px solid #e9ecef;
                    border-radius: 8px;
                    background: #f8f9fa;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    position: relative;
                }
                
                .file-item:hover {
                    border-color: #007bff;
                    background: #e3f2fd;
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(0,123,255,0.15);
                }
                
                .file-thumbnail {
                    width: 80px;
                    height: 80px;
                    object-fit: cover;
                    border-radius: 4px;
                    margin-bottom: 8px;
                }
                
                .file-icon {
                    width: 80px;
                    height: 80px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 2.5rem;
                    margin-bottom: 8px;
                    border-radius: 4px;
                }
                
                .file-icon.pdf { background: #dc3545; color: white; }
                .file-icon.doc { background: #0d6efd; color: white; }
                .file-icon.txt { background: #6c757d; color: white; }
                .file-icon.zip { background: #fd7e14; color: white; }
                .file-icon.default { background: #6f42c1; color: white; }
                
                .file-name {
                    font-size: 0.75rem;
                    text-align: center;
                    word-break: break-word;
                    max-width: 100%;
                    line-height: 1.2;
                    color: #495057;
                    font-weight: 500;
                }
                
                .file-size {
                    font-size: 0.65rem;
                    color: #6c757d;
                    margin-top: 2px;
                }
                
                .file-actions {
                    position: absolute;
                    top: 5px;
                    right: 5px;
                    display: none;
                    gap: 5px;
                }
                
                .file-item:hover .file-actions {
                    display: flex;
                }
                
                .file-action-btn {
                    width: 24px;
                    height: 24px;
                    border: none;
                    border-radius: 50%;
                    background: rgba(0,0,0,0.7);
                    color: white;
                    font-size: 0.7rem;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .file-action-btn:hover {
                    background: rgba(0,0,0,0.9);
                }
                
                /* Lightbox */
                .file-lightbox {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.9);
                    z-index: 9999;
                    display: none;
                    align-items: center;
                    justify-content: center;
                }
                
                .file-lightbox.active {
                    display: flex;
                }
                
                .lightbox-content {
                    max-width: 90%;
                    max-height: 90%;
                    position: relative;
                }
                
                .lightbox-image {
                    max-width: 100%;
                    max-height: 100%;
                    object-fit: contain;
                }
                
                .lightbox-close {
                    position: absolute;
                    top: -40px;
                    right: 0;
                    background: none;
                    border: none;
                    color: white;
                    font-size: 2rem;
                    cursor: pointer;
                    width: 40px;
                    height: 40px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .lightbox-info {
                    position: absolute;
                    bottom: -60px;
                    left: 0;
                    right: 0;
                    color: white;
                    text-align: center;
                    font-size: 0.9rem;
                }
                
                .no-files-message {
                    text-align: center;
                    padding: 40px 20px;
                    color: #6c757d;
                    font-style: italic;
                }
                
                .no-files-message i {
                    font-size: 3rem;
                    margin-bottom: 15px;
                    display: block;
                }
                
                @media (max-width: 768px) {
                    .file-viewer-grid {
                        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                        gap: 10px;
                    }
                    
                    .file-thumbnail, .file-icon {
                        width: 60px;
                        height: 60px;
                    }
                    
                    .file-name {
                        font-size: 0.7rem;
                    }
                }
            </style>
        `;
        
        document.head.insertAdjacentHTML('beforeend', styles);
    }
    
    createHTML() {
        this.container.innerHTML = `
            <div class="file-viewer">
                <div class="file-viewer-grid" id="fileGrid">
                    <!-- Los archivos se cargarán aquí -->
                </div>
                
                <!-- Lightbox -->
                <div class="file-lightbox" id="fileLightbox">
                    <div class="lightbox-content">
                        <button class="lightbox-close" id="lightboxClose">×</button>
                        <img class="lightbox-image" id="lightboxImage" src="" alt="">
                        <div class="lightbox-info" id="lightboxInfo"></div>
                    </div>
                </div>
            </div>
        `;
        
        this.fileGrid = document.getElementById('fileGrid');
        this.lightbox = document.getElementById('fileLightbox');
        this.lightboxImage = document.getElementById('lightboxImage');
        this.lightboxInfo = document.getElementById('lightboxInfo');
        this.lightboxClose = document.getElementById('lightboxClose');
    }
    
    setupEventListeners() {
        // Cerrar lightbox
        this.lightboxClose.addEventListener('click', () => this.closeLightbox());
        this.lightbox.addEventListener('click', (e) => {
            if (e.target === this.lightbox) {
                this.closeLightbox();
            }
        });
        
        // Tecla ESC para cerrar lightbox
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.lightboxOpen) {
                this.closeLightbox();
            }
        });
    }
    
    loadFiles(files) {
        this.files = files || [];
        this.renderFiles();
    }
    
    renderFiles() {
        if (!this.files || this.files.length === 0) {
            this.fileGrid.innerHTML = `
                <div class="no-files-message" style="grid-column: 1 / -1;">
                    <i class="fas fa-folder-open"></i>
                    <div>No hay archivos adjuntos</div>
                </div>
            `;
            return;
        }
        
        this.fileGrid.innerHTML = '';
        
        this.files.forEach((file, index) => {
            const fileElement = this.createFileElement(file, index);
            this.fileGrid.appendChild(fileElement);
        });
    }
    
    createFileElement(file, index) {
        const fileDiv = document.createElement('div');
        fileDiv.className = 'file-item';
        fileDiv.setAttribute('data-index', index);
        
        const isImage = this.isImageFile(file.mime_type);
        const fileIcon = this.getFileIcon(file.mime_type);
        const fileSize = this.formatFileSize(file.file_size);
        
        fileDiv.innerHTML = `
            ${isImage ? 
                `<img class="file-thumbnail" src="${file.file_path}" alt="${file.file_name}" loading="lazy">` :
                `<div class="file-icon ${fileIcon.class}"><i class="${fileIcon.icon}"></i></div>`
            }
            <div class="file-name" title="${file.file_name}">${this.truncateFileName(file.file_name)}</div>
            <div class="file-size">${fileSize}</div>
            
            <div class="file-actions">
                ${isImage ? 
                    `<button class="file-action-btn" onclick="fileViewer.openLightbox(${index})" title="Ver imagen">
                        <i class="fas fa-eye"></i>
                    </button>` : ''
                }
                <button class="file-action-btn" onclick="fileViewer.downloadFile(${index})" title="Descargar">
                    <i class="fas fa-download"></i>
                </button>
            </div>
        `;
        
        // Event listener para click en el archivo
        fileDiv.addEventListener('click', (e) => {
            if (!e.target.closest('.file-actions')) {
                if (isImage) {
                    this.openLightbox(index);
                } else {
                    this.openFile(file);
                }
            }
        });
        
        return fileDiv;
    }
    
    isImageFile(mimeType) {
        return mimeType && mimeType.startsWith('image/');
    }
    
    getFileIcon(mimeType) {
        if (mimeType.includes('pdf')) {
            return { icon: 'fas fa-file-pdf', class: 'pdf' };
        } else if (mimeType.includes('word') || mimeType.includes('document')) {
            return { icon: 'fas fa-file-word', class: 'doc' };
        } else if (mimeType.includes('text')) {
            return { icon: 'fas fa-file-alt', class: 'txt' };
        } else if (mimeType.includes('zip') || mimeType.includes('rar')) {
            return { icon: 'fas fa-file-archive', class: 'zip' };
        } else {
            return { icon: 'fas fa-file', class: 'default' };
        }
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    
    truncateFileName(fileName, maxLength = 15) {
        if (fileName.length <= maxLength) return fileName;
        const extension = fileName.split('.').pop();
        const nameWithoutExt = fileName.substring(0, fileName.lastIndexOf('.'));
        const truncatedName = nameWithoutExt.substring(0, maxLength - extension.length - 4) + '...';
        return truncatedName + '.' + extension;
    }
    
    openLightbox(index) {
        const file = this.files[index];
        if (!this.isImageFile(file.mime_type)) return;
        
        this.lightboxImage.src = file.file_path;
        this.lightboxInfo.textContent = `${file.file_name} (${this.formatFileSize(file.file_size)})`;
        this.lightbox.classList.add('active');
        this.lightboxOpen = true;
        
        // Prevenir scroll del body
        document.body.style.overflow = 'hidden';
    }
    
    closeLightbox() {
        this.lightbox.classList.remove('active');
        this.lightboxOpen = false;
        
        // Restaurar scroll del body
        document.body.style.overflow = '';
    }
    
    downloadFile(index) {
        const file = this.files[index];
        const link = document.createElement('a');
        link.href = file.file_path;
        link.download = file.file_name;
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    openFile(file) {
        // Para PDFs y otros documentos, abrir en nueva pestaña
        window.open(file.file_path, '_blank');
    }
    
    // Método público para agregar archivos dinámicamente
    addFile(file) {
        this.files.push(file);
        this.renderFiles();
    }
    
    // Método público para limpiar archivos
    clearFiles() {
        this.files = [];
        this.renderFiles();
    }
    
    // Método público para obtener archivos
    getFiles() {
        return this.files;
    }
}

// Hacer disponible globalmente
window.FileViewer = FileViewer;