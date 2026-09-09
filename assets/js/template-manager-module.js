/**
 * Módulo de Gestión de Plantillas Reutilizable
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este módulo proporciona funcionalidad completa para gestionar plantillas de informes
 * y puede ser reutilizado en diferentes secciones del sistema.
 */

const TemplateManagerModule = {
    // Estado del módulo
    state: {
        currentTemplateId: null,
        templates: {},
        isInitialized: false,
        editorInstance: null,
        canCopyToOwners: false,
        userRol: ''
    },

    /**
     * Mostrar mensaje de éxito con toast Bootstrap
     */
    showSuccess(message) {
        this.showToast(message, 'success');
    },

    /**
     * Mostrar mensaje de error con toast Bootstrap
     */
    showError(message) {
        this.showToast(message, 'error');
    },

    /**
     * Mostrar mensaje de información con toast Bootstrap
     */
    showInfo(message) {
        this.showToast(message, 'info');
    },

    /**
     * Mostrar toast notification profesional
     */
    showToast(message, type = 'info') {
        // Crear contenedor de toasts si no existe
        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        const toastId = 'toast_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
        const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle';
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${icon} me-2"></i>
                        ${this.escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        
        const toastElement = document.getElementById(toastId);
        if (toastElement && typeof bootstrap !== 'undefined' && bootstrap.Toast) {
            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: type === 'error' ? 5000 : 3000
            });
            
            toast.show();
            
            // Limpiar después de ocultar
            toastElement.addEventListener('hidden.bs.toast', () => {
                toastElement.remove();
            });
        }
    },

    /**
     * Escapar HTML para prevenir XSS
     */
    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    },

    // Configuración por defecto
    config: {
        storageKey: 'editorTemplates', // Para caché local opcional
        apiBaseUrl: window.location.pathname.includes('/components/') ? 
                    '../api/plantillas' : 'api/plantillas',
        useLocalStorageCache: true, // Usar localStorage como caché
        defaultTemplates: {
            'rx': {
                id: 'rx',
                name: 'Radiografía Simple',
                content: `<h2>INFORME RADIOGRÁFICO</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>`
            },
            'ct': {
                id: 'ct',
                name: 'Tomografía Computada',
                content: `<h2>INFORME TOMOGRÁFICO</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>`
            },
            'mri': {
                id: 'mri',
                name: 'Resonancia Magnética',
                content: `<h2>INFORME DE RESONANCIA MAGNÉTICA</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>`
            },
            'us': {
                id: 'us',
                name: 'Ultrasonido',
                content: `<h2>INFORME ULTRASONOGRÁFICO</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>`
            }
        }
    },

    /**
     * Inicializar el módulo
     */
    init: async function() {
        if (this.state.isInitialized) {
            console.log('TemplateManagerModule ya está inicializado');
            return;
        }

        console.log('Inicializando TemplateManagerModule...');
        await this.loadTemplates();
        this.state.isInitialized = true;
        console.log('TemplateManagerModule inicializado correctamente');
    },

    /**
     * Obtener token de sesión para autenticación
     */
    getSessionToken: function() {
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
     * Cargar plantillas desde la API (con fallback a localStorage si falla)
     */
    loadTemplates: async function() {
        try {
            // Intentar cargar desde la API primero
            const token = this.getSessionToken();
            const url = `${this.config.apiBaseUrl}/list.php`;
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                credentials: 'include'
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.data) {
                    this.state.templates = data.data;
                    this.state.canCopyToOwners = !!data.can_copy_to_owners;
                    this.state.userRol = data.user_rol || '';
                    console.log('✅ Plantillas cargadas desde la base de datos:', Object.keys(this.state.templates).length);
                    
                    // Guardar en caché local si está habilitado
                    if (this.config.useLocalStorageCache) {
                        try {
                            localStorage.setItem(this.config.storageKey, JSON.stringify(this.state.templates));
                            console.log('✅ Plantillas guardadas en caché local');
                        } catch (cacheError) {
                            console.warn('⚠️ No se pudo guardar en caché local:', cacheError);
                        }
                    }
                    return;
                }
            } else {
                console.warn('⚠️ Error cargando desde API, intentando caché local...');
            }
        } catch (apiError) {
            console.warn('⚠️ Error cargando desde API, intentando caché local:', apiError);
        }
        
        // Fallback: cargar desde localStorage si la API falla
        try {
            const savedTemplates = localStorage.getItem(this.config.storageKey);
            if (savedTemplates) {
                this.state.templates = JSON.parse(savedTemplates);
                console.log('✅ Plantillas cargadas desde caché local:', Object.keys(this.state.templates).length);
                return;
            }
        } catch (cacheError) {
            console.warn('⚠️ Error cargando desde caché local:', cacheError);
        }
        
        // Último fallback: usar plantillas predeterminadas
        this.state.templates = { ...this.config.defaultTemplates };
        console.log('✅ Plantillas predeterminadas cargadas:', Object.keys(this.state.templates).length);
        
        // Guardar plantillas predeterminadas en caché
        if (this.config.useLocalStorageCache) {
            try {
                localStorage.setItem(this.config.storageKey, JSON.stringify(this.state.templates));
            } catch (e) {
                console.warn('⚠️ No se pudo guardar plantillas predeterminadas en caché:', e);
            }
        }
    },

    /**
     * Guardar todas las plantillas (sincronizar con API y caché local)
     * Nota: Este método ahora solo actualiza el caché local.
     * Para guardar una plantilla específica, usar saveTemplate()
     */
    saveTemplates: function() {
        // Solo actualizar caché local si está habilitado
        if (this.config.useLocalStorageCache) {
            try {
                localStorage.setItem(this.config.storageKey, JSON.stringify(this.state.templates));
                console.log('✅ Plantillas guardadas en caché local');
            } catch (error) {
                console.error('⚠️ Error guardando en caché local:', error);
            }
        }
    },

    /**
     * Obtener todas las plantillas
     */
    getTemplates: function() {
        return this.state.templates;
    },

    /**
     * Obtener una plantilla específica
     */
    getTemplate: function(templateId) {
        return this.state.templates[templateId] || null;
    },

    /**
     * Obtener contenido de una plantilla
     */
    getTemplateContent: function(templateId) {
        const template = this.getTemplate(templateId);
        return template ? template.content : '';
    },

    /**
     * Procesar tags dinámicos en el contenido de la plantilla
     */
    processTemplateContent: function(content, patientData = {}) {
        if (!content) return '';

        let processedContent = content;
        
        // Datos básicos del paciente
        const currentDate = new Date().toLocaleDateString('es-ES');
        processedContent = processedContent.replace(/\[FECHA\]/g, currentDate);
        processedContent = processedContent.replace(/\[NOMBRE_PACIENTE\]/g, patientData.patientName || '[NOMBRE_PACIENTE]');
        processedContent = processedContent.replace(/\[ID_PACIENTE\]/g, patientData.patientId || '[ID_PACIENTE]');
        processedContent = processedContent.replace(/\[MODALIDAD\]/g, patientData.modality || '[MODALIDAD]');
        processedContent = processedContent.replace(/\[ESTUDIO\]/g, patientData.studyDescription || '[ESTUDIO]');

        return processedContent;
    },

    /**
     * Cargar plantilla en un editor TinyMCE
     */
    loadTemplateInEditor: function(templateId, editorId, patientData = {}) {
        const content = this.getTemplateContent(templateId);
        if (!content) {
            console.warn('Plantilla no encontrada:', templateId);
            return false;
        }

        const processedContent = this.processTemplateContent(content, patientData);
        
        // Intentar con TinyMCE primero
        if (window.tinymce) {
            const editor = tinymce.get(editorId);
            if (editor) {
                // Verificar si el editor está listo de diferentes formas
                try {
                    editor.setContent(processedContent);
                    this.state.currentTemplateId = templateId;
                    console.log('Plantilla cargada en editor TinyMCE:', templateId);
                    return true;
                } catch (error) {
                    console.warn('Error al cargar plantilla en TinyMCE, intentando con evento init:', error);
                    // Si falla, esperar al evento init
                    editor.on('init', () => {
                        editor.setContent(processedContent);
                        this.state.currentTemplateId = templateId;
                        console.log('Plantilla cargada en editor TinyMCE (después de init):', templateId);
                    });
                    return true;
                }
            }
        }
        
        // Fallback para editores que no sean TinyMCE
        const editorElement = document.getElementById(editorId);
        if (editorElement) {
            editorElement.value = processedContent;
            this.state.currentTemplateId = templateId;
            console.log('Plantilla cargada en editor (fallback):', templateId);
            return true;
        }

        console.error('Editor no encontrado:', editorId);
        return false;
    },

    /**
     * Crear selector de plantillas HTML
     */
    createTemplateSelector: function(selectId = 'templateSelect', onLoadCallback = null) {
        const templates = this.getTemplates();
        let options = '<option value="">Seleccionar plantilla...</option>';
        
        Object.values(templates).forEach(template => {
            options += `<option value="${template.id}">${template.name}</option>`;
        });

        const html = `
            <div class="template-selector">
                <label for="${selectId}" class="form-label">
                    <i class="fas fa-file-alt me-2"></i>
                    Plantilla de Informe
                </label>
                <div class="input-group">
                    <select id="${selectId}" class="form-select">
                        ${options}
                    </select>
                    <button class="btn btn-primary" type="button" id="loadTemplateBtn">
                        <i class="fas fa-download me-1"></i>
                        Cargar
                    </button>
                    <button class="btn btn-outline-info" type="button" id="refreshTemplateBtn" title="Actualizar lista de plantillas">
                        <i class="fas fa-sync-alt me-1"></i>
                        Actualizar
                    </button>
                    <button class="btn btn-outline-secondary" type="button" id="manageTemplateBtn" style="display: none;" title="Requiere permiso 'Gestión de Plantillas'">
                        <i class="fas fa-cog me-1"></i>
                        Gestionar
                    </button>
                </div>
            </div>
        `;
        
        // Configurar event listeners después de que se inserte el HTML
        setTimeout(() => {
            const loadBtn = document.getElementById('loadTemplateBtn');
            if (loadBtn) {
                loadBtn.addEventListener('click', () => {
                    this.loadSelectedTemplate(selectId, onLoadCallback);
                });
            }
            
            // Manejar el botón "Actualizar" para refrescar el dropdown
            const refreshBtn = document.getElementById('refreshTemplateBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    this.refreshTemplateSelector(selectId);
                });
            }
            
            // Manejar el botón "Gestionar" para evitar conflictos con modales anidados
            const manageBtn = document.getElementById('manageTemplateBtn');
            if (manageBtn) {
                // Verificar permiso antes de mostrar el botón
                this.checkAndShowManageButton(manageBtn);
                
                manageBtn.addEventListener('click', () => {
                    this.openTemplateManagerFromModal();
                });
            }
        }, 100);
        
        return html;
    },

    /**
     * Actualizar el selector de plantillas sin recrear todo el HTML
     * Recarga desde la API para obtener las plantillas más recientes
     */
    refreshTemplateSelector: async function(selectId = 'informeTemplateSelect') {
        try {
            const selectElement = document.getElementById(selectId);
            if (!selectElement) {
                console.warn('Selector de plantillas no encontrado:', selectId);
                this.showError('Selector de plantillas no encontrado');
                return;
            }

            // Obtener el valor actualmente seleccionado
            const currentValue = selectElement.value;

            // Recargar plantillas desde la API
            await this.loadTemplates();

            // Obtener plantillas actualizadas
            const templates = this.getTemplates();

            // Limpiar opciones existentes (excepto la opción por defecto si existe)
            const firstOption = selectElement.querySelector('option[value=""]');
            selectElement.innerHTML = firstOption ? firstOption.outerHTML : '<option value="">Seleccionar plantilla...</option>';

            // Agregar opciones de plantillas
            Object.values(templates).forEach(template => {
                const option = document.createElement('option');
                option.value = template.id;
                option.textContent = template.name;
                selectElement.appendChild(option);
            });

            // Restaurar el valor seleccionado si todavía existe
            if (currentValue && templates[currentValue]) {
                selectElement.value = currentValue;
            } else {
                // Si la plantilla seleccionada ya no existe, limpiar la selección
                selectElement.value = '';
            }

            console.log('✅ Selector de plantillas actualizado desde la base de datos');
            this.showSuccess('Lista de plantillas actualizada');
        } catch (error) {
            console.error('Error actualizando selector de plantillas:', error);
            this.showError('Error al actualizar la lista de plantillas: ' + error.message);
        }
    },

    /**
     * Cargar plantilla seleccionada
     */
    loadSelectedTemplate: function(selectId, callback = null) {
        const select = document.getElementById(selectId);
        if (!select) {
            console.error('Selector no encontrado:', selectId);
            return;
        }

        const templateId = select.value;
        if (!templateId) {
            console.warn('No se seleccionó ninguna plantilla');
            return;
        }

        // Obtener datos del paciente del contexto actual
        const patientData = this.getCurrentPatientData();
        
        // Detectar automáticamente qué editor está disponible
        let editorId = null;
        let editorInstance = null;
        
        // 1. Intentar con reportEditor (editor.html)
        if (document.getElementById('reportEditor')) {
            editorId = 'reportEditor';
            if (window.tinymce) {
                editorInstance = tinymce.get(editorId);
            }
        }
        // 2. Intentar con reportContent (informes-manager.html modal)
        else if (document.getElementById('reportContent')) {
            editorId = 'reportContent';
            if (window.tinymce) {
                editorInstance = tinymce.get(editorId);
            }
            // También intentar obtener desde InformesManager.state
            if (!editorInstance && window.InformesManager && window.InformesManager.state && window.InformesManager.state.tinymceEditor) {
                editorInstance = window.InformesManager.state.tinymceEditor;
            }
        }
        
        if (!editorId) {
            console.error('❌ No se encontró ningún editor disponible (reportEditor o reportContent)');
            return;
        }
        
        console.log('🔍 Editor detectado:', editorId, editorInstance ? '✅' : '⏳ Esperando...');
        
        // Función auxiliar para cargar la plantilla
        const loadTemplate = () => {
            const content = this.getTemplateContent(templateId);
            if (!content) {
                console.warn('Plantilla no encontrada:', templateId);
                return false;
            }
            
            const processedContent = this.processTemplateContent(content, patientData);
            
            // Intentar con TinyMCE primero
            if (editorInstance) {
                try {
                    editorInstance.setContent(processedContent);
                    this.state.currentTemplateId = templateId;
                    console.log('✅ Plantilla cargada exitosamente en TinyMCE:', templateId);
                    if (callback && typeof callback === 'function') {
                        callback(templateId);
                    }
                    return true;
                } catch (error) {
                    console.warn('⚠️ Error al cargar plantilla en TinyMCE:', error);
                }
            }
            
            // Fallback: intentar con el textarea directamente
            const editorElement = document.getElementById(editorId);
            if (editorElement) {
                editorElement.value = processedContent;
                // Si TinyMCE está disponible, también actualizar el contenido del editor
                if (window.tinymce) {
                    setTimeout(() => {
                        const editor = tinymce.get(editorId) || editorInstance;
                        if (editor) {
                            try {
                                editor.setContent(processedContent);
                            } catch (e) {
                                console.warn('No se pudo actualizar TinyMCE después de cargar en textarea:', e);
                            }
                        }
                    }, 100);
                }
                this.state.currentTemplateId = templateId;
                console.log('✅ Plantilla cargada en textarea (fallback):', templateId);
                if (callback && typeof callback === 'function') {
                    callback(templateId);
                }
                return true;
            }
            
            return false;
        };
        
        // Intentar cargar inmediatamente si el editor está disponible
        if (editorInstance) {
            if (loadTemplate()) {
                return;
            }
        }
        
        // Si no funcionó, esperar a que TinyMCE esté disponible
        let attempts = 0;
        const maxAttempts = 100; // 10 segundos (100 * 100ms)
        
        const checkEditor = setInterval(() => {
            attempts++;
            
            // Actualizar referencia al editor
            if (window.tinymce) {
                const editor = tinymce.get(editorId);
                if (editor) {
                    editorInstance = editor;
                }
                // También intentar desde InformesManager si es reportContent
                if (editorId === 'reportContent' && window.InformesManager && window.InformesManager.state && window.InformesManager.state.tinymceEditor) {
                    editorInstance = window.InformesManager.state.tinymceEditor;
                }
            }
            
            // Verificar si TinyMCE está disponible
            if (editorInstance) {
                clearInterval(checkEditor);
                console.log('✅ TinyMCE detectado, cargando plantilla...');
                if (loadTemplate()) {
                    return;
                }
            }
            
            // También verificar el textarea como fallback
            const editorElement = document.getElementById(editorId);
            if (editorElement) {
                clearInterval(checkEditor);
                console.log('✅ Textarea detectado, cargando plantilla...');
                loadTemplate();
                return;
            }
            
            // Si excedemos los intentos, usar fallback final
            if (attempts >= maxAttempts) {
                clearInterval(checkEditor);
                console.warn('⚠️ Timeout esperando inicialización de TinyMCE. Intentando fallback final...');
                const editorElement = document.getElementById(editorId);
                if (editorElement) {
                    const content = this.getTemplateContent(templateId);
                    if (content) {
                        const processedContent = this.processTemplateContent(content, patientData);
                        editorElement.value = processedContent;
                        this.state.currentTemplateId = templateId;
                        console.log('✅ Plantilla cargada en textarea (fallback final):', templateId);
                        if (callback && typeof callback === 'function') {
                            callback(templateId);
                        }
                    }
                } else {
                    console.error('❌ No se pudo cargar la plantilla: editor no disponible');
                }
            }
        }, 100);
    },

    /**
     * Obtener datos del paciente del contexto actual
     */
    getCurrentPatientData: function() {
        // Intentar obtener datos de diferentes fuentes
        const patientData = {};

        // Desde URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        patientData.patientId = urlParams.get('patientId') || urlParams.get('patient_id');
        patientData.patientName = urlParams.get('patientName') || urlParams.get('patient_name');
        patientData.modality = urlParams.get('modality');
        patientData.studyDescription = urlParams.get('studyDescription') || urlParams.get('study_description');

        // Desde elementos del DOM
        const patientNameEl = document.getElementById('modalPatientName');
        const patientIdEl = document.getElementById('modalPatientId');
        const modalityEl = document.getElementById('modalModality');

        if (patientNameEl) patientData.patientName = patientNameEl.textContent;
        if (patientIdEl) patientData.patientId = patientIdEl.textContent;
        if (modalityEl) patientData.modality = modalityEl.textContent;

        // Desde sessionStorage
        if (window.urlStudyManager) {
            const activeStudy = window.urlStudyManager.getActiveStudy();
            if (activeStudy) {
                patientData.patientId = activeStudy.patientId || patientData.patientId;
                patientData.patientName = activeStudy.patientName || patientData.patientName;
                patientData.modality = activeStudy.modality || patientData.modality;
                patientData.studyDescription = activeStudy.studyDescription || patientData.studyDescription;
            }
        }

        return patientData;
    },

    /**
     * Crear modal de gestión de plantillas
     */
    createTemplateManagerModal: function() {
        return `
            <div class="modal fade" id="templateManagerModal" tabindex="-1" aria-labelledby="templateManagerModalLabel" aria-hidden="true" style="z-index: 1070;">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="templateManagerModalLabel">
                                <i class="fas fa-file-alt me-2"></i>
                                Gestión de Plantillas
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <!-- Lista de Plantillas -->
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-list me-2"></i>
                                                Plantillas
                                            </h6>
                                            <button class="btn btn-success btn-sm" onclick="TemplateManagerModule.addNewTemplate()">
                                                <i class="fas fa-plus me-1"></i>
                                                Nueva
                                            </button>
                                        </div>
                                        <div class="card-body p-0">
                                            <div id="templatesList" class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                                                <!-- Las plantillas se cargarán aquí dinámicamente -->
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Editor de Plantilla -->
                                <div class="col-md-8">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-edit me-2"></i>
                                                Editor de Plantilla
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <form id="templateForm">
                                                <div class="row mb-3">
                                                    <div class="col-md-6">
                                                        <label for="templateId" class="form-label">ID de Plantilla</label>
                                                        <input type="text" class="form-control" id="templateId" placeholder="ej: rx_torax" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label for="templateName" class="form-label">Nombre de Plantilla</label>
                                                        <input type="text" class="form-control" id="templateName" placeholder="ej: Radiografía de Tórax" required>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="templateContent" class="form-label">Contenido de la Plantilla</label>
                                                    <div id="templateEditorContainer" style="min-height: 300px;">
                                                        <textarea id="templateContent" placeholder="Contenido HTML de la plantilla..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="d-flex gap-2 flex-wrap">
                                                    <button type="button" class="btn btn-primary" onclick="TemplateManagerModule.saveTemplate()">
                                                        <i class="fas fa-save me-1"></i>
                                                        Guardar
                                                    </button>
                                                    <button type="button" class="btn btn-outline-info" id="copyTemplateToOwnersBtn"
                                                            onclick="TemplateManagerModule.openCopyToOwnersModal()"
                                                            style="display: none;"
                                                            title="Copiar esta plantilla a cuentas Médico Informante">
                                                        <i class="fas fa-user-plus me-1"></i>
                                                        Copiar a médicos…
                                                    </button>
                                                    <button type="button" class="btn btn-warning" onclick="TemplateManagerModule.previewTemplate()">
                                                        <i class="fas fa-eye me-1"></i>
                                                        Vista Previa
                                                    </button>
                                                    <button type="button" class="btn btn-danger" onclick="TemplateManagerModule.deleteTemplate()" id="deleteTemplateBtn" style="display: none;">
                                                        <i class="fas fa-trash me-1"></i>
                                                        Eliminar
                                                    </button>
                                                    <button type="button" class="btn btn-secondary" onclick="TemplateManagerModule.clearForm()">
                                                        <i class="fas fa-times me-1"></i>
                                                        Limpiar
                                                    </button>
                                                </div>
                                                <div id="templateMetaInfo" class="form-text mt-2" style="display:none;"></div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Vista Previa -->
                            <div class="row mt-3" id="templatePreviewRow" style="display: none;">
                                <div class="col-12">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-eye me-2"></i>
                                                Vista Previa
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="templatePreview" class="border p-3" style="min-height: 200px; background-color: #f8f9fa;">
                                                <!-- La vista previa se mostrará aquí -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-info" onclick="TemplateManagerModule.exportTemplates()">
                                    <i class="fas fa-download me-1"></i>
                                    Exportar
                                </button>
                                <button type="button" class="btn btn-warning" onclick="TemplateManagerModule.importTemplates()">
                                    <i class="fas fa-upload me-1"></i>
                                    Importar
                                </button>
                                <input type="file" id="importTemplateFile" accept=".json" style="display: none;">
                            </div>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    /**
     * Actualizar lista de plantillas en el modal
     */
    updateTemplatesList: function() {
        const templatesList = document.getElementById('templatesList');
        if (!templatesList) return;

        templatesList.innerHTML = '';
        const templates = this.getTemplates();

        Object.values(templates).forEach(template => {
            const item = document.createElement('div');
            item.className = 'list-group-item list-group-item-action';
            const creator = template.creado_por_nombre
                ? `<br><small class="text-muted">Creada por: ${this.escapeHtml(template.creado_por_nombre)}</small>`
                : '';
            const owner = template.owner_nombre
                ? `<br><small class="text-muted">Dueño: ${this.escapeHtml(template.owner_nombre)}</small>`
                : '';
            const copyBadge = template.copiado_de
                ? ` <span class="badge bg-secondary">copia</span>`
                : '';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${this.escapeHtml(template.name)}</strong>${copyBadge}
                        <br>
                        <small class="text-muted">${this.escapeHtml(template.id)}</small>
                        ${creator}${owner}
                    </div>
                    <button class="btn btn-outline-primary btn-sm" onclick="TemplateManagerModule.editTemplate('${String(template.id).replace(/'/g, "\\'")}')">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
            `;
            templatesList.appendChild(item);
        });
    },

    /**
     * Agregar nueva plantilla
     */
    addNewTemplate: function() {
        this.state.currentTemplateId = null;
        document.getElementById('templateId').value = '';
        document.getElementById('templateName').value = '';
        
        if (window.tinymce) {
            const editor = tinymce.get('templateContent');
            if (editor) {
                editor.setContent('');
            } else {
                document.getElementById('templateContent').value = '';
            }
        } else {
            document.getElementById('templateContent').value = '';
        }
        
        document.getElementById('deleteTemplateBtn').style.display = 'none';
        this.updateCopyToOwnersButton(null);
        this.updateTemplateMetaInfo(null);
        document.getElementById('templateId').focus();
    },

    /**
     * Editar plantilla existente
     */
    editTemplate: function(templateId) {
        const template = this.getTemplate(templateId);
        if (!template) return;

        this.state.currentTemplateId = templateId;
        document.getElementById('templateId').value = template.id;
        document.getElementById('templateName').value = template.name;
        
        if (window.tinymce) {
            const editor = tinymce.get('templateContent');
            if (editor) {
                editor.setContent(template.content);
            } else {
                document.getElementById('templateContent').value = template.content;
            }
        } else {
            document.getElementById('templateContent').value = template.content;
        }
        
        document.getElementById('deleteTemplateBtn').style.display = 'inline-block';
        this.updateCopyToOwnersButton(template);
        this.updateTemplateMetaInfo(template);
    },

    updateTemplateMetaInfo: function(template) {
        const el = document.getElementById('templateMetaInfo');
        if (!el) return;
        if (!template) {
            el.style.display = 'none';
            el.textContent = '';
            return;
        }
        const parts = [];
        if (template.creado_por_nombre) {
            parts.push('Creada por: ' + template.creado_por_nombre);
        }
        if (template.owner_nombre) {
            parts.push('Dueño: ' + template.owner_nombre);
        }
        if (template.copiado_de) {
            parts.push('Es una copia asignada');
        }
        if (parts.length) {
            el.style.display = '';
            el.textContent = parts.join(' · ');
        } else {
            el.style.display = 'none';
            el.textContent = '';
        }
    },

    updateCopyToOwnersButton: function(template) {
        const btn = document.getElementById('copyTemplateToOwnersBtn');
        if (!btn) return;
        const show = !!this.state.canCopyToOwners && !!template && !!template.id;
        btn.style.display = show ? '' : 'none';
        btn.disabled = !show;
    },

    /**
     * Modal para copiar plantilla actual a Médicos Informantes.
     */
    openCopyToOwnersModal: async function() {
        if (!this.state.canCopyToOwners) {
            this.showError('No tiene permiso para copiar plantillas a médicos.');
            return;
        }
        const tid = (document.getElementById('templateId')?.value || this.state.currentTemplateId || '').trim();
        if (!tid) {
            this.showError('Seleccione o guarde una plantilla primero.');
            return;
        }
        const template = this.getTemplate(tid);
        if (!template) {
            this.showError('La plantilla debe estar guardada antes de copiarla. Pulse Guardar e inténtelo de nuevo.');
            return;
        }

        const token = this.getSessionToken();
        let medicos = [];
        try {
            const resp = await fetch(`${this.config.apiBaseUrl}/copy-to-users.php?action=list-medicos`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                credentials: 'include'
            });
            const data = await resp.json();
            if (!data.success) {
                throw new Error(data.error || 'No se pudo listar médicos');
            }
            medicos = data.data || [];
        } catch (e) {
            this.showError(e.message || 'Error listando médicos informantes');
            return;
        }

        if (!medicos.length) {
            this.showInfo('No hay cuentas con rol Médico Informante activas.');
            return;
        }

        const existing = document.getElementById('copyTemplateOwnersModal');
        if (existing) existing.remove();

        const rows = medicos.map((m) => {
            const label = `${(m.apellido || '').trim()} ${(m.nombre || '').trim()}`.trim() || m.email || ('ID ' + m.id);
            return `
                <label class="list-group-item list-group-item-action">
                    <input class="form-check-input me-2 copy-tpl-owner" type="checkbox" value="${m.id}">
                    ${this.escapeHtml(label)}
                    <small class="text-muted ms-2">${this.escapeHtml(m.email || '')}</small>
                </label>`;
        }).join('');

        const modal = document.createElement('div');
        modal.id = 'copyTemplateOwnersModal';
        modal.className = 'modal fade show';
        modal.style.cssText = 'display:block;position:fixed;inset:0;z-index:1080;background:rgba(0,0,0,.45);';
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Copiar plantilla a médicos</h5>
                        <button type="button" class="btn-close btn-close-white" onclick="document.getElementById('copyTemplateOwnersModal').remove()"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Plantilla: <strong>${this.escapeHtml(template.name)}</strong>
                        <code class="ms-1">${this.escapeHtml(template.id)}</code></p>
                        <p class="text-muted small">Se creará una <strong>copia</strong> para cada médico seleccionado (dueño = su cuenta). Si ya tiene una copia de esta plantilla, se omite.</p>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="copyTplSelectAll">Seleccionar todos</button>
                        </div>
                        <div class="list-group" style="max-height:320px;overflow:auto;">${rows}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('copyTemplateOwnersModal').remove()">Cancelar</button>
                        <button type="button" class="btn btn-info" id="confirmCopyTplOwnersBtn">
                            <i class="fas fa-copy me-1"></i>Copiar a seleccionados
                        </button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modal);

        const selectAll = document.getElementById('copyTplSelectAll');
        if (selectAll) {
            selectAll.addEventListener('click', () => {
                const boxes = document.querySelectorAll('.copy-tpl-owner');
                const allChecked = Array.from(boxes).every((b) => b.checked);
                boxes.forEach((b) => { b.checked = !allChecked; });
            });
        }

        const confirmBtn = document.getElementById('confirmCopyTplOwnersBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', async () => {
                const ids = Array.from(document.querySelectorAll('.copy-tpl-owner:checked'))
                    .map((cb) => parseInt(cb.value, 10))
                    .filter(Boolean);
                if (!ids.length) {
                    this.showError('Seleccione al menos un médico informante.');
                    return;
                }
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Copiando...';
                try {
                    const response = await fetch(`${this.config.apiBaseUrl}/copy-to-users.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            source_template_id: tid,
                            user_ids: ids,
                            skip_if_exists: true
                        })
                    });
                    const result = await response.json();
                    if (!result.success) {
                        throw new Error(result.error || 'Error al copiar');
                    }
                    document.getElementById('copyTemplateOwnersModal')?.remove();
                    this.showSuccess(result.message || `Copiada a ${result.copied || 0} médico(s)`);
                    await this.loadTemplates();
                    this.updateTemplatesList();
                } catch (err) {
                    this.showError(err.message || 'No se pudo copiar la plantilla');
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copiar a seleccionados';
                }
            });
        }
    },

    /**
     * Guardar plantilla en la base de datos
     */
    saveTemplate: async function() {
        const id = document.getElementById('templateId').value.trim();
        const name = document.getElementById('templateName').value.trim();
        
        if (!id || !name) {
            this.showError('Por favor complete todos los campos requeridos.');
            return;
        }

        let content = '';
        if (window.tinymce) {
            const editor = tinymce.get('templateContent');
            content = editor ? editor.getContent().trim() : document.getElementById('templateContent').value.trim();
        } else {
            content = document.getElementById('templateContent').value.trim();
        }

        if (!content) {
            this.showError('Por favor ingrese el contenido de la plantilla.');
            return;
        }

        // Validar ID único (solo si es una plantilla nueva)
        if (!this.state.currentTemplateId && this.state.templates[id]) {
            this.showError('Ya existe una plantilla con ese ID. Por favor, use un ID diferente.');
            return;
        }

        try {
            // Guardar en la base de datos
            const token = this.getSessionToken();
            const url = `${this.config.apiBaseUrl}/save.php`;
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                credentials: 'include',
                body: JSON.stringify({
                    id: id,
                    name: name,
                    content: content
                })
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || `Error HTTP: ${response.status}`);
            }
            
            if (data.success) {
                // Si estamos editando y el ID cambió, eliminar la plantilla anterior del estado local
                if (this.state.currentTemplateId && this.state.currentTemplateId !== id) {
                    delete this.state.templates[this.state.currentTemplateId];
                }

                // Actualizar estado local
                this.state.templates[id] = { id, name, content };
                
                // Actualizar caché local
                this.saveTemplates();
                
                // Recargar plantillas desde la API para asegurar sincronización
                await this.loadTemplates();
                
                // Actualizar lista en el modal
                this.updateTemplatesList();

                this.state.currentTemplateId = id;
                const saved = this.getTemplate(id);
                this.updateCopyToOwnersButton(saved || { id });
                this.updateTemplateMetaInfo(saved);

                this.showSuccess(data.message || 'Plantilla guardada exitosamente.');
            } else {
                throw new Error(data.error || 'Error al guardar la plantilla');
            }
        } catch (error) {
            console.error('Error guardando plantilla:', error);
            this.showError('Error al guardar la plantilla: ' + error.message);
        }
    },

    /**
     * Mostrar modal de confirmación profesional
     */
    showConfirmModal(title, message, onConfirm, onCancel = null) {
        // Verificar si ya existe un modal de confirmación
        let modalElement = document.getElementById('templateManagerConfirmModal');
        
        if (!modalElement) {
            // Crear el modal si no existe
            modalElement = document.createElement('div');
            modalElement.id = 'templateManagerConfirmModal';
            modalElement.className = 'modal fade';
            modalElement.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <span id="templateConfirmModalTitle">${title}</span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p id="templateConfirmModalMessage">${message}</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="templateConfirmModalCancel">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-danger" id="templateConfirmModalConfirm">
                                <i class="fas fa-check me-2"></i>Confirmar
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modalElement);
        }
        
        // Actualizar contenido
        const titleElement = modalElement.querySelector('#templateConfirmModalTitle');
        const messageElement = modalElement.querySelector('#templateConfirmModalMessage');
        if (titleElement) titleElement.textContent = title;
        if (messageElement) messageElement.textContent = message;
        
        // Limpiar listeners anteriores
        const confirmBtn = modalElement.querySelector('#templateConfirmModalConfirm');
        const cancelBtn = modalElement.querySelector('#templateConfirmModalCancel');
        
        // Crear nuevos listeners
        const confirmHandler = () => {
            if (onConfirm) {
                onConfirm();
            }
            const modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (modalInstance) {
                modalInstance.hide();
            }
            confirmBtn.removeEventListener('click', confirmHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
        };
        
        const cancelHandler = () => {
            if (onCancel) {
                onCancel();
            }
            const modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (modalInstance) {
                modalInstance.hide();
            }
            confirmBtn.removeEventListener('click', confirmHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
        };
        
        confirmBtn.addEventListener('click', confirmHandler, { once: true });
        cancelBtn.addEventListener('click', cancelHandler, { once: true });
        
        // Mostrar modal
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        modal.show();
    },

    /**
     * Eliminar plantilla de la base de datos
     */
    deleteTemplate: function() {
        if (!this.state.currentTemplateId) return;

        const templateId = this.state.currentTemplateId;
        const template = this.getTemplate(templateId);
        
        if (!template) {
            this.showError('Plantilla no encontrada.');
            return;
        }

        this.showConfirmModal(
            'Confirmar Eliminación',
            `¿Está seguro de que desea eliminar la plantilla "${template.name}"? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    // Eliminar de la base de datos
                    const token = this.getSessionToken();
                    const url = `${this.config.apiBaseUrl}/delete.php`;
                    
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            id: templateId
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (!response.ok) {
                        throw new Error(data.error || `Error HTTP: ${response.status}`);
                    }
                    
                    if (data.success) {
                        // Eliminar del estado local
                        delete this.state.templates[templateId];
                        
                        // Actualizar caché local
                        this.saveTemplates();
                        
                        // Recargar plantillas desde la API para asegurar sincronización
                        await this.loadTemplates();
                        
                        // Actualizar lista en el modal
                        this.updateTemplatesList();
                        
                        // Limpiar formulario
                        this.clearForm();
                        
                        this.showSuccess('Plantilla eliminada exitosamente.');
                    } else {
                        throw new Error(data.error || 'Error al eliminar la plantilla');
                    }
                } catch (error) {
                    console.error('Error eliminando plantilla:', error);
                    this.showError('Error al eliminar la plantilla: ' + error.message);
                }
            }
        );
    },

    /**
     * Vista previa de plantilla
     */
    previewTemplate: function() {
        let content = '';
        if (window.tinymce) {
            const editor = tinymce.get('templateContent');
            content = editor ? editor.getContent().trim() : document.getElementById('templateContent').value.trim();
        } else {
            content = document.getElementById('templateContent').value.trim();
        }

        if (!content) {
            this.showError('No hay contenido para mostrar en la vista previa.');
            return;
        }

        const processedContent = this.processTemplateContent(content, this.getCurrentPatientData());
        document.getElementById('templatePreview').innerHTML = processedContent;
        document.getElementById('templatePreviewRow').style.display = 'block';
    },

    /**
     * Limpiar formulario
     */
    clearForm: function() {
        this.state.currentTemplateId = null;
        document.getElementById('templateId').value = '';
        document.getElementById('templateName').value = '';
        
        if (window.tinymce) {
            const editor = tinymce.get('templateContent');
            if (editor) {
                editor.setContent('');
            } else {
                document.getElementById('templateContent').value = '';
            }
        } else {
            document.getElementById('templateContent').value = '';
        }
        
        document.getElementById('deleteTemplateBtn').style.display = 'none';
        document.getElementById('templatePreviewRow').style.display = 'none';
        this.updateCopyToOwnersButton(null);
        this.updateTemplateMetaInfo(null);
    },

    /**
     * Exportar plantillas
     */
    exportTemplates: function() {
        const templates = this.getTemplates();
        const dataStr = JSON.stringify(templates, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        
        const link = document.createElement('a');
        link.href = URL.createObjectURL(dataBlob);
        link.download = 'plantillas_informes.json';
        link.click();
    },

    /**
     * Importar plantillas
     */
    importTemplates: function() {
        document.getElementById('importTemplateFile').click();
    },

    /**
     * Inicializar editor TinyMCE para plantillas
     */
    initTemplateEditor: function() {
        if (!window.tinymce) {
            console.warn('TinyMCE no está disponible');
            return;
        }

        tinymce.init({
            selector: '#templateContent',
            height: 300,
            language: 'es', // Usar español
            language_url: '../js/tinymce/langs/es.js', // Ruta al archivo local de idioma
            menubar: true,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount'
            ],
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | help',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, San Francisco, Segoe UI, Roboto, Helvetica Neue, sans-serif; font-size: 14px; }',
            setup: function(editor) {
                editor.on('change', function() {
                    editor.save();
                });
            },
            // Configuración adicional para evitar errores
            init_instance_callback: function(editor) {
                console.log('Editor TinyMCE inicializado correctamente en español');
            }
        });
    },

    /**
     * Configurar event listeners para el modal
     */
    setupModalEventListeners: function() {
        // Event listener para importar plantillas
        const importFile = document.getElementById('importTemplateFile');
        if (importFile) {
            importFile.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        try {
                            const templates = JSON.parse(event.target.result);
                            this.importTemplatesData(templates);
                        } catch (error) {
                            TemplateManagerModule.showError('Error al importar plantillas: ' + error.message);
                        }
                    };
                    reader.readAsText(file);
                }
            });
        }

        // Event listener para mostrar/ocultar modal
        const modal = document.getElementById('templateManagerModal');
        if (modal) {
            modal.addEventListener('shown.bs.modal', () => {
                console.log('Modal de plantillas mostrado, inicializando...');
                this.updateTemplatesList();
                
                // Inicializar editor con un pequeño delay para asegurar que el DOM esté listo
                setTimeout(() => {
                    this.initTemplateEditor();
                }, 100);
            });
            
            // Limpiar editor cuando se cierre el modal
            modal.addEventListener('hidden.bs.modal', () => {
                console.log('Modal de plantillas cerrado, limpiando editor...');
                if (window.tinymce) {
                    const editor = tinymce.get('templateContent');
                    if (editor) {
                        editor.destroy();
                    }
                }
            });
        }
    },

    /**
     * Importar datos de plantillas
     */
    importTemplatesData: function(templates) {
        if (typeof templates === 'object' && templates !== null) {
            this.state.templates = { ...this.state.templates, ...templates };
            this.saveTemplates();
            this.updateTemplatesList();
            this.showSuccess('Plantillas importadas exitosamente.');
        } else {
            this.showError('Formato de archivo inválido.');
        }
    },
    
    /**
     * Abrir gestor de plantillas desde dentro de otro modal
     * Maneja correctamente la transición entre modales
     */
    openTemplateManagerFromModal: function() {
        try {
            console.log('🔍 Abriendo gestor de plantillas desde modal...');
            
            // Buscar el modal actual (Ver Informe o Editar Informe)
            const currentModal = document.querySelector('.modal.show');
            
            if (currentModal) {
                console.log('📋 Modal actual encontrado:', currentModal.id);
                
                // NO cerrar el modal actual - abrir el modal de plantillas encima de él
                // Esto permite que ambos modales estén abiertos simultáneamente
                console.log('✅ Manteniendo modal de edición abierto - abriendo modal de plantillas encima');
                
                // Abrir directamente el gestor de plantillas (se encargará de configurar z-index)
                if (window.InformesManager && typeof window.InformesManager.openTemplateManager === 'function') {
                    window.InformesManager.openTemplateManager();
                } else {
                    console.error('❌ InformesManager.openTemplateManager no está disponible');
                    this.showError('Error: No se pudo abrir el gestor de plantillas');
                }
            } else {
                // Si no hay modal abierto, abrir normalmente
                console.log('ℹ️ No hay modal abierto - abriendo gestor de plantillas normalmente');
                if (window.InformesManager && typeof window.InformesManager.openTemplateManager === 'function') {
                    window.InformesManager.openTemplateManager();
                } else {
                    console.error('❌ InformesManager.openTemplateManager no está disponible');
                    this.showError('Error: No se pudo abrir el gestor de plantillas');
                }
            }
        } catch (error) {
            console.error('❌ Error abriendo gestor de plantillas desde modal:', error);
            this.showError('Error al abrir el gestor de plantillas: ' + error.message);
        }
    },

    /**
     * Verificar permiso y mostrar/ocultar botón "Gestionar"
     */
    checkAndShowManageButton: async function(buttonElement) {
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
                    
                    if (hasPermission && buttonElement) {
                        buttonElement.style.display = 'inline-block';
                        console.log('✅ Botón "Gestionar" mostrado - usuario tiene permiso "Gestión de Plantillas"');
                    } else if (buttonElement) {
                        buttonElement.style.display = 'none';
                        console.log('⚠️ Botón "Gestionar" ocultado - usuario NO tiene permiso "Gestión de Plantillas"');
                    }
                    
                    return hasPermission;
                }
            }
        } catch (error) {
            console.error('Error verificando permiso para mostrar botón "Gestionar":', error);
        }
        
        if (buttonElement) {
            buttonElement.style.display = 'none';
        }
        return false;
    }
};

// Hacer el módulo disponible globalmente
window.TemplateManagerModule = TemplateManagerModule;
