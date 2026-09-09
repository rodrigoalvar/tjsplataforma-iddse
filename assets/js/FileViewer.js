/**
 * FileViewer - Módulo reutilizable para visualización de archivos
 * Soporta imágenes, documentos, PDFs y otros tipos de archivos
 * Incluye thumbnails, lightbox con galería, navegación y zoom
 */

class FileViewer {
    constructor() {
        this.lightboxId = 'fileViewerLightbox';
        this.initializeLightbox();
    }

    /**
     * Inicializa el lightbox para imágenes con navegación y zoom
     */
    initializeLightbox() {
        if (document.getElementById(this.lightboxId)) {
            return;
        }

        const lightboxHtml = `
            <div class="modal fade" id="${this.lightboxId}" tabindex="-1" aria-hidden="true" style="z-index: 10000 !important;">
                <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 95vw; z-index: 10001 !important;">
                    <div class="modal-content bg-dark" style="z-index: 10002 !important;">
                        <div class="modal-header border-0 py-2 px-3">
                            <h6 class="modal-title text-white text-truncate flex-grow-1 me-3" id="lightboxTitle" style="max-width: calc(100% - 160px);">Vista de Imagen</h6>
                            <span class="text-white-50 small me-3 text-nowrap" id="lightboxCounter" style="min-width:50px;"></span>
                            <button type="button" class="btn-close btn-close-white flex-shrink-0" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-0" id="lightboxBody" style="position: relative; background: #111; min-height: 55vh; max-height: 72vh; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            <!-- Botón anterior -->
                            <button class="fv-lightbox-nav fv-lightbox-prev" id="lightboxPrev" onclick="FileViewer.navigateLightbox(-1)" title="Imagen anterior (←)">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <!-- Contenedor de imagen con zoom/pan -->
                            <div id="lightboxImageContainer" style="width:100%; height:100%; min-height:55vh; max-height:72vh; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;">
                                <img id="lightboxImage"
                                     alt="Imagen"
                                     style="max-width:100%; max-height:72vh; display:block; transform-origin:center center; user-select:none; -webkit-user-drag:none;">
                            </div>
                            <!-- Botón siguiente -->
                            <button class="fv-lightbox-nav fv-lightbox-next" id="lightboxNext" onclick="FileViewer.navigateLightbox(1)" title="Imagen siguiente (→)">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <!-- Indicador de zoom -->
                            <div id="lightboxZoomIndicator" style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.6);color:#fff;padding:3px 8px;border-radius:4px;font-size:12px;pointer-events:none;opacity:0;transition:opacity 0.4s;">100%</div>
                        </div>
                        <div class="modal-footer border-0 justify-content-between py-2 px-3 flex-wrap gap-2">
                            <div class="text-white-50 small" id="lightboxInfo" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <!-- Controles de zoom -->
                                <div class="btn-group btn-group-sm" role="group" aria-label="Controles de zoom">
                                    <button type="button" class="btn btn-outline-light" id="lightboxZoomOut" onclick="FileViewer.adjustZoom(-0.25)" title="Reducir zoom">
                                        <i class="fas fa-search-minus"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light" id="lightboxZoomLevel" onclick="FileViewer.resetZoom()" title="Restablecer zoom (doble clic en imagen)" style="min-width:58px;font-size:12px;">
                                        100%
                                    </button>
                                    <button type="button" class="btn btn-outline-light" id="lightboxZoomIn" onclick="FileViewer.adjustZoom(0.25)" title="Ampliar zoom">
                                        <i class="fas fa-search-plus"></i>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-outline-light btn-sm" id="lightboxDownload">
                                    <i class="fas fa-download me-1"></i>Descargar
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', lightboxHtml);
        FileViewer._bindLightboxEvents();
    }

    // ─── Estado de galería ───────────────────────────────────────────────────

    /** @type {Array<{path:string, name:string, size:string}>} */
    static _gallery = [];
    static _currentIndex = 0;

    // ─── Estado de zoom / pan ────────────────────────────────────────────────

    static _zoom = 1;
    static _minZoom = 0.5;
    static _maxZoom = 8;
    static _zoomStep = 0.25;
    static _translateX = 0;
    static _translateY = 0;
    static _isDragging = false;
    static _dragStartX = 0;
    static _dragStartY = 0;
    static _lastTranslateX = 0;
    static _lastTranslateY = 0;

    /**
     * Limpia la galería (llamar antes de crear un nuevo conjunto de cards)
     */
    static clearGallery() {
        FileViewer._gallery = [];
    }

    /**
     * Agrega una imagen a la galería y devuelve su índice
     */
    static _addToGallery(imagePath, fileName, fileSize) {
        FileViewer._gallery.push({ path: imagePath, name: fileName, size: fileSize });
        return FileViewer._gallery.length - 1;
    }

    // ─── Navegación ──────────────────────────────────────────────────────────

    /**
     * Navega al índice anterior (-1) o siguiente (+1) en la galería
     */
    static navigateLightbox(direction) {
        const total = FileViewer._gallery.length;
        if (total <= 1) return;
        FileViewer._currentIndex = (FileViewer._currentIndex + direction + total) % total;
        FileViewer._showGalleryItem(FileViewer._currentIndex);
    }

    /**
     * Abre el lightbox en el índice indicado de la galería
     */
    static _showGalleryItem(index) {
        const item = FileViewer._gallery[index];
        if (!item) return;

        const img = document.getElementById('lightboxImage');
        const title = document.getElementById('lightboxTitle');
        const info = document.getElementById('lightboxInfo');
        const counter = document.getElementById('lightboxCounter');
        const prevBtn = document.getElementById('lightboxPrev');
        const nextBtn = document.getElementById('lightboxNext');
        const downloadBtn = document.getElementById('lightboxDownload');

        if (!img) return;

        img.src = item.path;
        if (title) title.textContent = item.name;
        if (info) info.textContent = `Tamaño: ${item.size}`;

        const total = FileViewer._gallery.length;
        if (counter) {
            counter.textContent = total > 1 ? `${index + 1} / ${total}` : '';
        }

        // Mostrar/ocultar botones de navegación
        const showNav = total > 1;
        if (prevBtn) prevBtn.style.display = showNav ? 'flex' : 'none';
        if (nextBtn) nextBtn.style.display = showNav ? 'flex' : 'none';

        if (downloadBtn) {
            downloadBtn.onclick = () => FileViewer.downloadFile(item.path, item.name);
        }

        FileViewer.resetZoom();
    }

    // ─── Zoom ────────────────────────────────────────────────────────────────

    /**
     * Aplica el zoom indicado (clamped entre min y max)
     */
    static _applyZoom(scale, originX, originY) {
        const img = document.getElementById('lightboxImage');
        const container = document.getElementById('lightboxImageContainer');
        if (!img) return;

        FileViewer._zoom = Math.max(FileViewer._minZoom, Math.min(FileViewer._maxZoom, scale));

        // Actualizar translate limitado para no salir mucho del área
        if (FileViewer._zoom <= 1) {
            FileViewer._translateX = 0;
            FileViewer._translateY = 0;
        } else {
            // Limitar desplazamiento proporcional al zoom
            const maxX = img.naturalWidth  ? (img.offsetWidth  * (FileViewer._zoom - 1)) / 2 : 400;
            const maxY = img.naturalHeight ? (img.offsetHeight * (FileViewer._zoom - 1)) / 2 : 300;
            FileViewer._translateX = Math.max(-maxX, Math.min(maxX, FileViewer._translateX));
            FileViewer._translateY = Math.max(-maxY, Math.min(maxY, FileViewer._translateY));
        }

        img.style.transform = `translate(${FileViewer._translateX}px, ${FileViewer._translateY}px) scale(${FileViewer._zoom})`;
        img.style.cursor = FileViewer._zoom > 1 ? 'grab' : 'default';

        // Actualizar indicador de zoom
        const pct = Math.round(FileViewer._zoom * 100) + '%';
        const zoomLevel = document.getElementById('lightboxZoomLevel');
        if (zoomLevel) zoomLevel.textContent = pct;

        // Flash del indicador flotante
        const indicator = document.getElementById('lightboxZoomIndicator');
        if (indicator) {
            indicator.textContent = pct;
            indicator.style.opacity = '1';
            clearTimeout(FileViewer._zoomIndicatorTimeout);
            FileViewer._zoomIndicatorTimeout = setTimeout(() => {
                indicator.style.opacity = '0';
            }, 1000);
        }
    }

    static _zoomIndicatorTimeout = null;

    /**
     * Ajusta el zoom en un delta (ej: +0.25 o -0.25)
     */
    static adjustZoom(delta) {
        FileViewer._applyZoom(FileViewer._zoom + delta);
    }

    /**
     * Resetea el zoom a 1:1
     */
    static resetZoom() {
        FileViewer._zoom = 1;
        FileViewer._translateX = 0;
        FileViewer._translateY = 0;
        FileViewer._lastTranslateX = 0;
        FileViewer._lastTranslateY = 0;
        FileViewer._applyZoom(1);
    }

    // ─── Eventos del lightbox ────────────────────────────────────────────────

    static _bindLightboxEvents() {
        const img = document.getElementById('lightboxImage');
        const container = document.getElementById('lightboxImageContainer');
        const modal = document.getElementById('fileViewerLightbox');

        if (!img || !container || !modal) return;

        // ── Zoom con rueda del mouse ────────────────────────────────────────
        container.addEventListener('wheel', (e) => {
            e.preventDefault();
            const delta = e.deltaY < 0 ? FileViewer._zoomStep : -FileViewer._zoomStep;
            FileViewer._applyZoom(FileViewer._zoom + delta);
        }, { passive: false });

        // ── Click derecho = zoom in ─────────────────────────────────────────
        container.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            FileViewer._applyZoom(FileViewer._zoom + FileViewer._zoomStep);
        });

        // ── Doble clic = toggle zoom / reset ───────────────────────────────
        img.addEventListener('dblclick', (e) => {
            e.preventDefault();
            if (FileViewer._zoom > 1) {
                FileViewer.resetZoom();
            } else {
                FileViewer._applyZoom(2);
            }
        });

        // ── Drag / pan cuando está ampliado ────────────────────────────────
        img.addEventListener('mousedown', (e) => {
            if (FileViewer._zoom > 1 && e.button === 0) {
                FileViewer._isDragging = true;
                FileViewer._dragStartX = e.clientX - FileViewer._lastTranslateX;
                FileViewer._dragStartY = e.clientY - FileViewer._lastTranslateY;
                img.style.cursor = 'grabbing';
                e.preventDefault();
            }
        });

        document.addEventListener('mousemove', (e) => {
            if (!FileViewer._isDragging) return;
            FileViewer._translateX = e.clientX - FileViewer._dragStartX;
            FileViewer._translateY = e.clientY - FileViewer._dragStartY;
            const i = document.getElementById('lightboxImage');
            if (i) {
                i.style.transform = `translate(${FileViewer._translateX}px, ${FileViewer._translateY}px) scale(${FileViewer._zoom})`;
            }
        });

        document.addEventListener('mouseup', () => {
            if (FileViewer._isDragging) {
                FileViewer._isDragging = false;
                FileViewer._lastTranslateX = FileViewer._translateX;
                FileViewer._lastTranslateY = FileViewer._translateY;
                const i = document.getElementById('lightboxImage');
                if (i) i.style.cursor = FileViewer._zoom > 1 ? 'grab' : 'default';
            }
        });

        // ── Teclado: flechas para navegar, +/- para zoom, Esc ya lo maneja BS ──
        document.addEventListener('keydown', (e) => {
            const lb = document.getElementById('fileViewerLightbox');
            if (!lb || !lb.classList.contains('show')) return;
            if (e.key === 'ArrowLeft')  FileViewer.navigateLightbox(-1);
            if (e.key === 'ArrowRight') FileViewer.navigateLightbox(1);
            if (e.key === '+' || e.key === '=') FileViewer.adjustZoom(FileViewer._zoomStep);
            if (e.key === '-') FileViewer.adjustZoom(-FileViewer._zoomStep);
            if (e.key === '0') FileViewer.resetZoom();
        });

        // ── Al cerrar el modal, resetear estado ────────────────────────────
        modal.addEventListener('hidden.bs.modal', () => {
            FileViewer.resetZoom();
        });
    }

    // ─── API pública ─────────────────────────────────────────────────────────

    /**
     * Abre una imagen en el lightbox.
     * Si se proporciona index, navega dentro de la galería cargada.
     */
    static openImageLightbox(imagePath, fileName, fileSize, index = null) {
        // Asegurar que el lightbox existe
        if (!document.getElementById('fileViewerLightbox')) {
            new FileViewer();
        }

        if (index !== null && FileViewer._gallery.length > 0) {
            FileViewer._currentIndex = index;
        } else {
            // Imagen suelta sin galería – crear galería temporal de 1
            FileViewer._gallery = [{ path: imagePath, name: fileName, size: fileSize }];
            FileViewer._currentIndex = 0;
        }

        FileViewer._showGalleryItem(FileViewer._currentIndex);

        const lightbox = document.getElementById('fileViewerLightbox');
        const existing = bootstrap.Modal.getInstance(lightbox);
        if (existing) {
            existing.show();
        } else {
            new bootstrap.Modal(lightbox).show();
        }
    }

    // ─── Creación de cards ───────────────────────────────────────────────────

    /**
     * Crea una card para visualizar un archivo
     */
    static createFileCard(fileData) {
        const { mimeType } = fileData;

        const card = document.createElement('div');
        card.className = 'file-viewer-card';

        if (FileViewer.isImage(mimeType)) {
            card.innerHTML = FileViewer.createImageCard(fileData);
        } else if (FileViewer.isPDF(mimeType)) {
            card.innerHTML = FileViewer.createPDFCard(fileData);
        } else if (FileViewer.isDocument(mimeType)) {
            card.innerHTML = FileViewer.createDocumentCard(fileData);
        } else {
            card.innerHTML = FileViewer.createGenericCard(fileData);
        }

        FileViewer.injectStyles();

        return card;
    }

    /**
     * Verifica si el archivo es una imagen
     */
    static isImage(mimeType) {
        return mimeType && mimeType.startsWith('image/');
    }

    /**
     * Verifica si el archivo es un PDF
     */
    static isPDF(mimeType) {
        return mimeType === 'application/pdf';
    }

    /**
     * Verifica si el archivo es un documento
     */
    static isDocument(mimeType) {
        const documentTypes = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/rtf'
        ];
        return documentTypes.includes(mimeType);
    }

    /**
     * Crea una card para imágenes con thumbnail.
     * Registra la imagen en la galería para navegación.
     */
    static createImageCard(fileData) {
        const { fileName, filePath, fileSize, uploadDate } = fileData;
        const formattedSize = FileViewer.formatFileSize(fileSize);
        const formattedDate = FileViewer.formatDate(uploadDate);

        // Registrar en la galería y obtener índice
        const idx = FileViewer._addToGallery(filePath, fileName, formattedSize);

        return `
            <div class="card file-card image-card">
                <div class="file-thumbnail-container" onclick="FileViewer.openImageLightbox('${filePath}', '${fileName}', '${formattedSize}', ${idx})" style="cursor:pointer;" title="Clic para ampliar">
                    <img src="${filePath}"
                         class="file-thumbnail"
                         alt="${fileName}"
                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgdmlld0JveD0iMCAwIDEwMCAxMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiBmaWxsPSIjZjhmOWZhIi8+CjxwYXRoIGQ9Ik0zMCA3MEw0MCA1MEw1MCA2MEw3MCA0MEw4MCA3MEgzMFoiIGZpbGw9IiNkZWUyZTYiLz4KPGNpcmNsZSBjeD0iNDAiIGN5PSIzNSIgcj0iNSIgZmlsbD0iI2RlZTJlNiIvPgo8L3N2Zz4='; this.onerror=null;">
                    <div class="file-overlay">
                        <span class="fv-overlay-icon"><i class="fas fa-expand-alt"></i></span>
                    </div>
                </div>
                <div class="card-body">
                    <h6 class="card-title text-truncate" title="${fileName}">
                        <i class="fas fa-image me-1 text-primary"></i>
                        ${fileName}
                    </h6>
                    <div class="file-info">
                        <small class="text-muted d-block">${formattedSize}</small>
                        <small class="text-muted d-block">${formattedDate}</small>
                    </div>
                    <div class="file-actions mt-2">
                        <button class="btn btn-outline-primary btn-sm me-1" onclick="FileViewer.openImageLightbox('${filePath}', '${fileName}', '${formattedSize}', ${idx})">
                            <i class="fas fa-eye me-1"></i>Ver
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="FileViewer.downloadFile('${filePath}', '${fileName}')">
                            <i class="fas fa-download me-1"></i>Descargar
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Crea una card para PDFs
     */
    static createPDFCard(fileData) {
        const { fileName, filePath, fileSize, uploadDate } = fileData;
        const formattedSize = FileViewer.formatFileSize(fileSize);
        const formattedDate = FileViewer.formatDate(uploadDate);

        return `
            <div class="card file-card pdf-card">
                <div class="file-icon-container">
                    <i class="fas fa-file-pdf fa-3x text-danger"></i>
                </div>
                <div class="card-body">
                    <h6 class="card-title text-truncate" title="${fileName}">
                        <i class="fas fa-file-pdf me-1 text-danger"></i>
                        ${fileName}
                    </h6>
                    <div class="file-info">
                        <small class="text-muted d-block">${formattedSize}</small>
                        <small class="text-muted d-block">${formattedDate}</small>
                    </div>
                    <div class="file-actions mt-2">
                        <button class="btn btn-outline-danger btn-sm me-1" onclick="FileViewer.openPDF('${filePath}')">
                            <i class="fas fa-eye me-1"></i>Ver PDF
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="FileViewer.downloadFile('${filePath}', '${fileName}')">
                            <i class="fas fa-download me-1"></i>Descargar
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Crea una card para documentos
     */
    static createDocumentCard(fileData) {
        const { fileName, filePath, fileSize, uploadDate, mimeType } = fileData;
        const formattedSize = FileViewer.formatFileSize(fileSize);
        const formattedDate = FileViewer.formatDate(uploadDate);
        const icon = FileViewer.getDocumentIcon(mimeType);

        return `
            <div class="card file-card document-card">
                <div class="file-icon-container">
                    <i class="${icon} fa-3x text-info"></i>
                </div>
                <div class="card-body">
                    <h6 class="card-title text-truncate" title="${fileName}">
                        <i class="${icon} me-1 text-info"></i>
                        ${fileName}
                    </h6>
                    <div class="file-info">
                        <small class="text-muted d-block">${formattedSize}</small>
                        <small class="text-muted d-block">${formattedDate}</small>
                    </div>
                    <div class="file-actions mt-2">
                        <button class="btn btn-outline-info btn-sm me-1" onclick="FileViewer.openDocument('${filePath}')">
                            <i class="fas fa-external-link-alt me-1"></i>Abrir
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="FileViewer.downloadFile('${filePath}', '${fileName}')">
                            <i class="fas fa-download me-1"></i>Descargar
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Crea una card genérica para otros tipos de archivos
     */
    static createGenericCard(fileData) {
        const { fileName, filePath, fileSize, uploadDate } = fileData;
        const formattedSize = FileViewer.formatFileSize(fileSize);
        const formattedDate = FileViewer.formatDate(uploadDate);

        return `
            <div class="card file-card generic-card">
                <div class="file-icon-container">
                    <i class="fas fa-file fa-3x text-secondary"></i>
                </div>
                <div class="card-body">
                    <h6 class="card-title text-truncate" title="${fileName}">
                        <i class="fas fa-file me-1 text-secondary"></i>
                        ${fileName}
                    </h6>
                    <div class="file-info">
                        <small class="text-muted d-block">${formattedSize}</small>
                        <small class="text-muted d-block">${formattedDate}</small>
                    </div>
                    <div class="file-actions mt-2">
                        <button class="btn btn-outline-secondary btn-sm" onclick="FileViewer.downloadFile('${filePath}', '${fileName}')">
                            <i class="fas fa-download me-1"></i>Descargar
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    // ─── Utilidades ──────────────────────────────────────────────────────────

    static openPDF(pdfPath) {
        window.open(pdfPath, '_blank');
    }

    static openDocument(documentPath) {
        window.open(documentPath, '_blank');
    }

    static downloadFile(filePath, fileName) {
        const link = document.createElement('a');
        link.href = filePath;
        link.download = fileName;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    static getDocumentIcon(mimeType) {
        const iconMap = {
            'application/msword': 'fas fa-file-word',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'fas fa-file-word',
            'text/plain': 'fas fa-file-alt',
            'application/rtf': 'fas fa-file-alt'
        };
        return iconMap[mimeType] || 'fas fa-file';
    }

    static formatFileSize(bytes) {
        if (!bytes) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    static formatDate(dateString) {
        if (!dateString) return 'Fecha desconocida';
        const date = new Date(dateString);
        return date.toLocaleDateString('es-ES', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /**
     * Inyecta los estilos CSS necesarios (incluye estilos de lightbox mejorado)
     */
    static injectStyles() {
        if (document.getElementById('fileViewerStyles')) {
            return;
        }

        const styles = `
            <style id="fileViewerStyles">
                /* ─── Cards ──────────────────────────────── */
                .file-viewer-card {
                    height: 100%;
                }

                .file-card {
                    transition: transform 0.2s, box-shadow 0.2s;
                    height: 100%;
                }

                .file-card:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                }

                .file-thumbnail-container {
                    position: relative;
                    height: 150px;
                    overflow: hidden;
                    background: #f8f9fa;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .file-thumbnail {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    transition: transform 0.3s;
                }

                .file-thumbnail-container:hover .file-thumbnail {
                    transform: scale(1.05);
                }

                .file-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0,0,0,0.45);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    opacity: 0;
                    transition: opacity 0.25s;
                }

                .file-thumbnail-container:hover .file-overlay {
                    opacity: 1;
                }

                .fv-overlay-icon {
                    color: #fff;
                    font-size: 1.6rem;
                    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
                }

                .file-icon-container {
                    height: 150px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #f8f9fa;
                }

                .file-info {
                    min-height: 40px;
                }

                .file-actions {
                    display: flex;
                    gap: 5px;
                    flex-wrap: wrap;
                }

                .file-actions .btn {
                    flex: 1;
                    min-width: 80px;
                }

                @media (max-width: 768px) {
                    .file-actions { flex-direction: column; }
                    .file-actions .btn { width: 100%; }
                }

                /* ─── Lightbox nav buttons ───────────────── */
                .fv-lightbox-nav {
                    position: absolute;
                    top: 50%;
                    transform: translateY(-50%);
                    z-index: 20;
                    background: rgba(0,0,0,0.55);
                    color: #fff;
                    border: none;
                    border-radius: 50%;
                    width: 48px;
                    height: 48px;
                    font-size: 1.1rem;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    transition: background 0.2s, transform 0.15s;
                    outline: none;
                }

                .fv-lightbox-nav:hover {
                    background: rgba(0,0,0,0.85);
                    transform: translateY(-50%) scale(1.1);
                }

                .fv-lightbox-prev { left: 12px; }
                .fv-lightbox-next { right: 12px; }

                #lightboxImageContainer {
                    position: relative;
                }

                #lightboxImage {
                    pointer-events: all;
                    transition: transform 0.08s ease-out;
                }
            </style>
        `;

        document.head.insertAdjacentHTML('beforeend', styles);
    }
}

// Instancia global e inicialización
window.FileViewer = FileViewer;

document.addEventListener('DOMContentLoaded', function() {
    new FileViewer();
});
