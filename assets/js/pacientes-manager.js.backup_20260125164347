/**
 * Gestor de Pacientes
 * Maneja el CRUD completo de pacientes del sistema
 */
class PacientesManager {
    constructor() {
        this.apiBaseUrl = 'api/pacientes/';
        this.currentPage = 1;
        this.limit = 20;
        this.totalPages = 1;
        this.totalPacientes = 0;
        this.currentPaciente = null;
        this.searchTimeout = null;
        this.hasEmailSendPermission = false; // Permiso para enviar emails
        this.hasWhatsAppSendPermission = false; // Permiso para enviar WhatsApp
        this.emailsEnviados = new Set(); // Rastrea IDs de pacientes a los que se les envió email
    }
    
    /**
     * Inicializa el gestor de pacientes
     */
    async init() {
        try {
            // Verificar permisos de envío
            await this.checkEmailSendPermission();
            await this.checkWhatsAppSendPermission();
            
            this.setupEventListeners();
            await this.loadConfigBusqueda(); // Cargar configuración de búsqueda
            await this.loadPacientes();
            
            // Cargar plantillas por defecto y configurar selectores
            if (this.hasEmailSendPermission) {
                await this.initDefaultEmailTemplatePaciente();
                await this.initDefaultEmailTemplateMedico();
            }
            // Cargar plantillas por defecto de WhatsApp
            if (this.hasWhatsAppSendPermission) {
                await this.initDefaultWhatsAppTemplatePaciente();
                await this.initDefaultWhatsAppTemplateMedico();
            }
        } catch (error) {
            console.error('Error inicializando gestor de pacientes:', error);
            this.showError('Error inicializando gestor de pacientes');
        }
    }
    
    /**
     * Verifica si el usuario tiene permiso para enviar emails
     */
    async checkEmailSendPermission() {
        try {
            if (typeof checkPermissionSimple === 'function') {
                const result = await checkPermissionSimple('envios_email', 'Envíos por email');
                this.hasEmailSendPermission = result.success && result.hasPermission;
            } else if (typeof window.simplePermissionManager !== 'undefined') {
                const result = await window.simplePermissionManager.checkPermission('envios_email', 'Envíos por email');
                this.hasEmailSendPermission = result.success && result.hasPermission;
            } else {
                // Si no hay sistema de permisos, permitir por defecto (para compatibilidad)
                this.hasEmailSendPermission = true;
            }
        } catch (error) {
            console.error('Error verificando permiso de envío de email:', error);
            // Por defecto, no permitir si hay error
            this.hasEmailSendPermission = false;
        }
    }
    
    /**
     * Verifica si el usuario tiene permiso para enviar mensajes de WhatsApp
     */
    async checkWhatsAppSendPermission() {
        try {
            if (typeof checkPermissionSimple === 'function') {
                const result = await checkPermissionSimple('envios_whatsapp', 'Envíos por WhatsApp');
                this.hasWhatsAppSendPermission = result.success && result.hasPermission;
            } else if (typeof window.simplePermissionManager !== 'undefined') {
                const result = await window.simplePermissionManager.checkPermission('envios_whatsapp', 'Envíos por WhatsApp');
                this.hasWhatsAppSendPermission = result.success && result.hasPermission;
            } else {
                // Si no hay sistema de permisos, permitir por defecto (para compatibilidad)
                this.hasWhatsAppSendPermission = true;
            }
        } catch (error) {
            console.error('Error verificando permiso de envío de WhatsApp:', error);
            // Por defecto, no permitir si hay error
            this.hasWhatsAppSendPermission = false;
        }
    }
    
    /**
     * Configura los event listeners
     */
    setupEventListeners() {
        // Botón nuevo paciente
        const btnNuevoPaciente = document.getElementById('btnNuevoPaciente');
        if (btnNuevoPaciente) {
            btnNuevoPaciente.addEventListener('click', () => this.openModal());
        }
        
        // Botón guardar paciente
        const btnGuardarPaciente = document.getElementById('btnGuardarPaciente');
        if (btnGuardarPaciente) {
            btnGuardarPaciente.addEventListener('click', () => this.savePaciente());
        }
        
        // Botón buscar por ID PACS - ahora abre modal de búsqueda de estudios
        const btnBuscarIdPacs = document.getElementById('btnBuscarIdPacs');
        if (btnBuscarIdPacs) {
            btnBuscarIdPacs.addEventListener('click', () => this.abrirModalBuscarEstudios());
        }
        
        // Event listeners para el modal de búsqueda de estudios
        const btnBuscarEstudios = document.getElementById('btnBuscarEstudios');
        if (btnBuscarEstudios) {
            btnBuscarEstudios.addEventListener('click', () => this.buscarEstudios());
        }
        
        const btnLimpiarFiltrosEstudios = document.getElementById('btnLimpiarFiltrosEstudios');
        if (btnLimpiarFiltrosEstudios) {
            btnLimpiarFiltrosEstudios.addEventListener('click', () => this.limpiarFiltrosEstudios());
        }
        
        // Permitir Enter en los campos de filtro para buscar
        const estudioDateFrom = document.getElementById('estudioDateFrom');
        const estudioDateTo = document.getElementById('estudioDateTo');
        const estudioPatientId = document.getElementById('estudioPatientId');
        
        [estudioDateFrom, estudioDateTo, estudioPatientId].forEach(input => {
            if (input) {
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.buscarEstudios();
                    }
                });
            }
        });
        
        // Auto-buscar cuando se ingresa ID PACS y se presiona Enter o se pierde el foco
        const idpacienteInput = document.getElementById('idpaciente');
        if (idpacienteInput) {
            idpacienteInput.addEventListener('blur', () => {
                const idpaciente = idpacienteInput.value.trim();
                if (idpaciente && !document.getElementById('pacienteId').value) {
                    // Solo buscar si es creación (no edición)
                    this.buscarPorIdPacs();
                }
            });
            
            idpacienteInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.buscarPorIdPacs();
                }
            });
        }
        
        // Búsqueda con debounce
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.currentPage = 1;
                    this.loadPacientes();
                }, 500);
            });
        }
        
        // Filtro de estado
        const filterActivo = document.getElementById('filterActivo');
        if (filterActivo) {
            filterActivo.addEventListener('change', () => {
                this.currentPage = 1;
                this.loadPacientes();
            });
        }
        
        // Botón limpiar
        const btnLimpiar = document.getElementById('btnLimpiar');
        if (btnLimpiar) {
            btnLimpiar.addEventListener('click', () => this.clearFilters());
        }
        
        // Botón guardar configuración de búsqueda
        const btnGuardarConfigBusqueda = document.getElementById('btnGuardarConfigBusqueda');
        if (btnGuardarConfigBusqueda) {
            btnGuardarConfigBusqueda.addEventListener('click', () => this.saveConfigBusqueda());
        }
        
        // Modal events
        const pacienteModal = document.getElementById('pacienteModal');
        if (pacienteModal) {
            pacienteModal.addEventListener('hidden.bs.modal', () => {
                this.resetForm();
            });
        }
        
        // Event listener para restaurar estilos del body cuando se cierra el modal de email
        const confirmEmailModal = document.getElementById('confirmEmailModal');
        if (confirmEmailModal) {
            confirmEmailModal.addEventListener('hidden.bs.modal', () => {
                // Usar setTimeout para asegurar que se ejecute después de que Bootstrap termine
                setTimeout(() => {
                    // Restaurar estilos del body que Bootstrap puede haber modificado
                    document.body.style.paddingRight = '';
                    document.body.style.overflow = '';
                    document.body.style.width = '';
                    document.body.classList.remove('modal-open');
                    
                    // Remover cualquier backdrop que pueda quedar
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    
                    // Forzar reflow para asegurar que los cambios se apliquen
                    void document.body.offsetHeight;
                }, 100);
            });
        }
        
        // Event listener para restaurar estilos del body cuando se cierra el modal de WhatsApp
        const confirmWhatsAppModal = document.getElementById('confirmWhatsAppModal');
        if (confirmWhatsAppModal) {
            confirmWhatsAppModal.addEventListener('hidden.bs.modal', () => {
                // Usar setTimeout para asegurar que se ejecute después de que Bootstrap termine
                setTimeout(() => {
                    // Restaurar estilos del body que Bootstrap puede haber modificado
                    document.body.style.paddingRight = '';
                    document.body.style.overflow = '';
                    document.body.style.width = '';
                    document.body.classList.remove('modal-open');
                    
                    // Remover cualquier backdrop que pueda quedar
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    
                    // Forzar reflow para asegurar que los cambios se apliquen
                    void document.body.offsetHeight;
                }, 100);
            });
        }
    }
    
    /**
     * Carga la configuración de búsqueda desde la API
     */
    async loadConfigBusqueda() {
        try {
            const response = await fetch(`${this.apiBaseUrl}config-search-type.php`);
            const result = await response.json();
            
            if (result.success && result.search_type) {
                const searchType = result.search_type;
                // Seleccionar el radio button correspondiente
                if (searchType === 'id_interno') {
                    document.getElementById('globalSearchIdInterno').checked = true;
                } else {
                    document.getElementById('globalSearchIdPaciente').checked = true;
                }
            }
        } catch (error) {
            console.warn('No se pudo cargar configuración de búsqueda:', error);
            // Usar valor por defecto (idpaciente)
            document.getElementById('globalSearchIdPaciente').checked = true;
        }
    }
    
    /**
     * Guarda la configuración de búsqueda en la BD
     */
    async saveConfigBusqueda() {
        try {
            const selectedType = document.querySelector('input[name="globalSearchType"]:checked');
            if (!selectedType) {
                this.showError('Por favor selecciona un tipo de búsqueda');
                return;
            }
            
            const searchType = selectedType.value;
            const messageDiv = document.getElementById('configBusquedaMessage');
            
            // Mostrar loading
            if (messageDiv) {
                messageDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin me-2"></i>Guardando configuración...</div>';
            }
            
            const response = await fetch(`${this.apiBaseUrl}config-search-type.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ search_type: searchType })
            });
            
            const result = await response.json();
            
            if (result.success) {
                if (messageDiv) {
                    messageDiv.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>${result.message}</div>`;
                }
                this.showSuccess('Configuración guardada correctamente');
            } else {
                throw new Error(result.message || 'Error al guardar configuración');
            }
        } catch (error) {
            console.error('Error guardando configuración de búsqueda:', error);
            this.showError('Error al guardar configuración: ' + error.message);
            const messageDiv = document.getElementById('configBusquedaMessage');
            if (messageDiv) {
                messageDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Error: ${error.message}</div>`;
            }
        }
    }
    
    /**
     * Obtiene el método de búsqueda configurado
     * @returns {Promise<string>} 'id_interno' o 'idpaciente'/'documento'
     */
    async getSearchMethod() {
        try {
            const response = await fetch(`${this.apiBaseUrl}config-search-type.php`);
            const result = await response.json();
            
            if (result.success && result.search_type) {
                return result.search_type;
            }
            // Valor por defecto: idpaciente
            return 'idpaciente';
        } catch (error) {
            console.warn('No se pudo obtener método de búsqueda, usando por defecto:', error);
            // Valor por defecto: idpaciente
            return 'idpaciente';
        }
    }
    
    /**
     * Construye la URL del portal con el parámetro correcto según el método de búsqueda
     * @param {string} idInterno - ID interno del paciente
     * @param {string} idpaciente - ID PACS del paciente
     * @param {string} searchMethod - Método de búsqueda ('id_interno' o 'idpaciente'/'documento')
     * @returns {string} URL completa con el parámetro correspondiente
     */
    buildPortalUrlWithAccess(idInterno, idpaciente, searchMethod) {
        const portalUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
        
        // Si el método es id_interno, usar id_interno
        if (searchMethod === 'id_interno') {
            return idInterno ? `${portalUrl}?id_interno=${encodeURIComponent(idInterno)}` : portalUrl;
        }
        
        // Si el método es idpaciente o documento, usar doc con idpaciente
        if (searchMethod === 'idpaciente' || searchMethod === 'documento') {
            return idpaciente ? `${portalUrl}?doc=${encodeURIComponent(idpaciente)}` : portalUrl;
        }
        
        // Por defecto, usar id_interno si está disponible
        return idInterno ? `${portalUrl}?id_interno=${encodeURIComponent(idInterno)}` : portalUrl;
    }
    
    /**
     * Obtiene el token de autenticación
     */
    async getAuthToken() {
        try {
            // Intentar obtener el token desde la cookie de sesión
            const response = await fetch('api/auth/validate-session-simple.php', {
                credentials: 'include'
            });
            const result = await response.json();
            
            if (result.success && result.data) {
                // El token generalmente está en la cookie, pero podemos obtenerlo del response
                return result.data.token_sesion || null;
            }
            
            return null;
        } catch (error) {
            console.error('Error obteniendo token:', error);
            return null;
        }
    }
    
    /**
     * Realiza una petición fetch con autenticación
     */
    async fetchWithAuth(url, options = {}) {
        const token = await this.getAuthToken();
        
        const headers = {
            'Content-Type': 'application/json',
            ...options.headers
        };
        
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }
        
        return fetch(url, {
            ...options,
            headers,
            credentials: 'include'
        });
    }
    
    /**
     * Carga la lista de pacientes
     */
    async loadPacientes() {
        try {
            this.showLoading(true);
            
            const search = document.getElementById('searchInput')?.value || '';
            const activo = document.getElementById('filterActivo')?.value || '';
            
            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.limit
            });
            
            if (search) {
                params.append('search', search);
            }
            
            if (activo !== '') {
                params.append('activo', activo);
            }
            
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}list.php?${params}`);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.totalPacientes = result.meta?.total || 0;
                this.totalPages = result.meta?.total_pages || 1;
                this.renderPacientes(result.data || []);
                this.updatePagination();
                this.updateTotalPacientes();
                this.initTooltips();
            } else {
                // Si es error de permisos, no mostrar alert, solo loguear y renderizar error
                const errorMessage = result.error || 'Error al cargar pacientes';
                if (errorMessage.includes('permisos') || errorMessage.includes('permiso')) {
                    console.warn('Error de permisos al cargar pacientes:', errorMessage);
                    this.renderError();
                    // No mostrar alert, el modal de permisos ya debería haberse mostrado
                    return;
                }
                throw new Error(errorMessage);
            }
        } catch (error) {
            console.error('Error cargando pacientes:', error);
            // Si es error de permisos, no mostrar alert
            if (error.message && (error.message.includes('permisos') || error.message.includes('permiso'))) {
                console.warn('Error de permisos detectado:', error.message);
                this.renderError();
                return;
            }
            this.showError('Error al cargar pacientes: ' + error.message);
            this.renderError();
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Renderiza la tabla de pacientes
     */
    renderPacientes(pacientes) {
        const tbody = document.getElementById('pacientesTableBody');
        if (!tbody) return;
        
        if (pacientes.length === 0) {
            tbody.innerHTML = `
                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No se encontraron pacientes</p>
                                    </td>
                </tr>
            `;
            return;
        }
        
        // Cargar estado de emails enviados desde la BD
        pacientes.forEach(paciente => {
            if (paciente.fecha_ultimo_email_enviado) {
                this.emailsEnviados.add(paciente.id);
            }
        });
        
        tbody.innerHTML = pacientes.map(paciente => {
            const estadoBadge = paciente.activo 
                ? '<span class="badge bg-success">Activo</span>' 
                : '<span class="badge bg-danger">Inactivo</span>';
            
            return `
                <tr>
                    <td class="d-none d-sm-table-cell">${paciente.paciente_id || paciente.id_interno || '-'}</td>
                    <td>
                        <strong>${paciente.nombre || '-'}</strong>
                        ${paciente.idpaciente ? `<br><small class="text-muted d-lg-none">ID PACS: ${paciente.idpaciente}</small>` : ''}
                    </td>
                    <td class="d-none d-lg-table-cell">${paciente.idpaciente || '-'}</td>
                    <td class="d-none d-md-table-cell">${paciente.telefono || '-'}</td>
                    <td class="d-none d-xl-table-cell">${paciente.email || '-'}</td>
                    <td class="d-none d-xl-table-cell">${paciente.direccion || paciente.domicilio || '-'}</td>
                    <td class="d-none d-sm-table-cell">${estadoBadge}</td>
                    <td class="text-end table-actions">
                        <div class="btn-group" role="group">
                            ${paciente.telefono ? `
                                ${!this.hasWhatsAppSendPermission ? `
                                    <span data-bs-toggle="tooltip" 
                                          data-bs-title="No tienes permiso para enviar WhatsApp. Contacta al administrador."
                                          style="display: inline-block;">
                                        <button class="btn btn-sm btn-outline-success disabled" 
                                                onclick="pacientesManager.showNoPermissionMessage('whatsapp')"
                                                style="opacity: 0.5; cursor: not-allowed; pointer-events: none;">
                                            <i class="fab fa-whatsapp"></i>
                                        </button>
                                    </span>
                                ` : `
                                    <button class="btn btn-sm btn-outline-success" 
                                            onclick="pacientesManager.sendWhatsApp(${paciente.id}, '${(paciente.telefono || '').replace(/'/g, "\\'")}', '${(paciente.nombre || '').replace(/'/g, "\\'")}', '${(paciente.paciente_id || paciente.id_interno || '').replace(/'/g, "\\'")}')"
                                            data-bs-toggle="tooltip" 
                                            data-bs-title="Enviar WhatsApp">
                                        <i class="fab fa-whatsapp"></i>
                                    </button>
                                `}
                            ` : ''}
                            ${paciente.email ? `
                                ${!this.hasEmailSendPermission ? `
                                    <span data-bs-toggle="tooltip" 
                                          data-bs-title="No tienes permiso para enviar emails. Contacta al administrador."
                                          style="display: inline-block;">
                                        <button class="btn btn-sm btn-outline-info disabled" 
                                                onclick="pacientesManager.showNoPermissionMessage('email')"
                                                style="opacity: 0.5; cursor: not-allowed; pointer-events: none;">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                    </span>
                                ` : `
                                    <button class="btn btn-sm ${(this.emailsEnviados.has(paciente.id) || paciente.fecha_ultimo_email_enviado) ? 'btn-success' : 'btn-outline-info'}" 
                                            id="btnEmail_${paciente.id}"
                                            onclick="pacientesManager.sendEmail(${paciente.id}, '${(paciente.email || '').replace(/'/g, "\\'")}', '${(paciente.nombre || '').replace(/'/g, "\\'")}', '${(paciente.paciente_id || paciente.id_interno || '').replace(/'/g, "\\'")}')"
                                            data-bs-toggle="tooltip" 
                                            data-bs-title="${(this.emailsEnviados.has(paciente.id) || paciente.fecha_ultimo_email_enviado) ? 'Email enviado. Click para reenviar' : 'Enviar Email'}">
                                        <i class="fas fa-envelope"></i>
                                        ${(this.emailsEnviados.has(paciente.id) || paciente.fecha_ultimo_email_enviado) ? '<span class="badge bg-light text-success ms-1"><i class="fas fa-check"></i></span>' : ''}
                                    </button>
                                `}
                            ` : ''}
                            <button class="btn btn-sm btn-outline-primary" 
                                    onclick="pacientesManager.editPaciente(${paciente.id})" 
                                    data-bs-toggle="tooltip" 
                                    data-bs-title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-info" 
                                    onclick="pacientesManager.viewPaciente(${paciente.id})" 
                                    data-bs-toggle="tooltip" 
                                    data-bs-title="Ver detalles">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" 
                                    onclick="pacientesManager.confirmDelete(${paciente.id})" 
                                    data-bs-toggle="tooltip" 
                                    data-bs-title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }
    
    /**
     * Actualiza visualmente el botón de email después de enviar
     * @param {number} pacienteId - ID del paciente
     */
    actualizarBotonEmail(pacienteId) {
        const btnEmail = document.getElementById(`btnEmail_${pacienteId}`);
        if (!btnEmail) return;
        
        // Cambiar clases del botón
        btnEmail.classList.remove('btn-outline-info');
        btnEmail.classList.add('btn-success');
        
        // Actualizar tooltip
        const tooltipInstance = bootstrap.Tooltip.getInstance(btnEmail);
        if (tooltipInstance) {
            tooltipInstance.setContent({ '.tooltip-inner': 'Email enviado. Click para reenviar' });
        } else {
            btnEmail.setAttribute('data-bs-title', 'Email enviado. Click para reenviar');
        }
        
        // Agregar badge de confirmación si no existe
        if (!btnEmail.querySelector('.badge')) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-light text-success ms-1';
            badge.innerHTML = '<i class="fas fa-check"></i>';
            btnEmail.appendChild(badge);
        }
    }
    
    /**
     * Actualiza la paginación
     */
    updatePagination() {
        const pagination = document.getElementById('pagination');
        if (!pagination) return;
        
        if (this.totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }
        
        let html = '';
        
        // Botón anterior
        html += `
            <li class="page-item ${this.currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="pacientesManager.changePage(${this.currentPage - 1}); return false;">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
        `;
        
        // Páginas
        const startPage = Math.max(1, this.currentPage - 2);
        const endPage = Math.min(this.totalPages, this.currentPage + 2);
        
        if (startPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="pacientesManager.changePage(1); return false;">1</a></li>`;
            if (startPage > 2) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            html += `
                <li class="page-item ${i === this.currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="pacientesManager.changePage(${i}); return false;">${i}</a>
                </li>
            `;
        }
        
        if (endPage < this.totalPages) {
            if (endPage < this.totalPages - 1) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            html += `<li class="page-item"><a class="page-link" href="#" onclick="pacientesManager.changePage(${this.totalPages}); return false;">${this.totalPages}</a></li>`;
        }
        
        // Botón siguiente
        html += `
            <li class="page-item ${this.currentPage === this.totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="pacientesManager.changePage(${this.currentPage + 1}); return false;">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
        `;
        
        pagination.innerHTML = html;
    }
    
    /**
     * Cambia de página
     */
    changePage(page) {
        if (page < 1 || page > this.totalPages) return;
        this.currentPage = page;
        this.loadPacientes();
    }
    
    /**
     * Actualiza el contador total de pacientes
     */
    updateTotalPacientes() {
        const totalElement = document.getElementById('totalPacientes');
        if (totalElement) {
            totalElement.textContent = `${this.totalPacientes} paciente${this.totalPacientes !== 1 ? 's' : ''}`;
        }
    }
    
    /**
     * Inicializa los tooltips de Bootstrap para los botones de acción
     */
    initTooltips() {
        // Destruir tooltips existentes para evitar duplicados
        const existingTooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        existingTooltips.forEach(element => {
            const tooltipInstance = bootstrap.Tooltip.getInstance(element);
            if (tooltipInstance) {
                tooltipInstance.dispose();
            }
        });
        
        // Inicializar nuevos tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(tooltipTriggerEl => {
            new bootstrap.Tooltip(tooltipTriggerEl, {
                trigger: 'hover focus',
                placement: 'top'
            });
        });
    }
    
    /**
     * Limpia los filtros
     */
    clearFilters() {
        const searchInput = document.getElementById('searchInput');
        const filterActivo = document.getElementById('filterActivo');
        
        if (searchInput) searchInput.value = '';
        if (filterActivo) filterActivo.value = '';
        
        this.currentPage = 1;
        this.loadPacientes();
    }
    
    /**
     * Abre el modal para crear/editar paciente
     */
    openModal(pacienteId = null) {
        const modal = new bootstrap.Modal(document.getElementById('pacienteModal'));
        const modalTitle = document.getElementById('modalTitle');
        
        if (pacienteId) {
            modalTitle.textContent = 'Editar Paciente';
            this.loadPacienteData(pacienteId);
        } else {
            modalTitle.textContent = 'Nuevo Paciente';
            this.resetForm();
        }
        
        modal.show();
    }
    
    /**
     * Carga los datos de un paciente para editar
     */
    async loadPacienteData(id) {
        try {
            this.showLoading(true);
            
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}get.php?id=${id}`);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.fillForm(result.data);
                this.currentPaciente = result.data;
            } else {
                throw new Error(result.error || 'Error al cargar paciente');
            }
        } catch (error) {
            console.error('Error cargando paciente:', error);
            this.showError('Error al cargar paciente: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Resetea el formulario
     */
    /**
     * Limpia el número de teléfono removiendo sufijos de WhatsApp y caracteres no numéricos
     * @param {string} phoneNumber - Número de teléfono completo
     * @returns {string} Número limpio (solo dígitos)
     */
    cleanPhoneNumber(phoneNumber) {
        if (!phoneNumber) {
            return '';
        }
        
        // Remover sufijos de WhatsApp
        let clean = phoneNumber.replace(/@c\.us|@s\.whatsapp\.net/gi, '').trim();
        
        // Remover caracteres no numéricos excepto el + inicial
        clean = clean.replace(/[^\d+]/g, '');
        
        // Si empieza con +, removerlo
        if (clean.startsWith('+')) {
            clean = clean.substring(1);
        }
        
        return clean;
    }
    
    resetForm() {
        const form = document.getElementById('pacienteForm');
        if (form) {
            form.reset();
            document.getElementById('pacienteId').value = '';
            document.getElementById('activo').checked = true;
            document.getElementById('pacienteIdInput').disabled = false;
            document.getElementById('pacienteIdInput').readOnly = true; // Siempre readonly
            document.getElementById('pacienteIdInput').value = ''; // Limpiar ID interno
            
        }
        
        // Limpiar mensaje de búsqueda
        const statusDiv = document.getElementById('idpacienteBusquedaStatus');
        if (statusDiv) {
            statusDiv.innerHTML = '';
        }
        
        this.currentPaciente = null;
    }
    
    /**
     * Rellena el formulario con los datos del paciente
     */
    fillForm(paciente) {
        document.getElementById('pacienteId').value = paciente.id || '';
        document.getElementById('pacienteIdInput').value = paciente.paciente_id || paciente.id_interno || '';
        document.getElementById('nombre').value = paciente.nombre || '';
        document.getElementById('idpaciente').value = paciente.idpaciente || '';
        
        // Limpiar y asignar número de teléfono completo
        const telefonoCompleto = paciente.telefono || '';
        const telefonoLimpio = this.cleanPhoneNumber(telefonoCompleto);
        document.getElementById('telefono').value = telefonoLimpio;
        
        document.getElementById('email').value = paciente.email || '';
        document.getElementById('direccion').value = paciente.direccion || paciente.domicilio || '';
        document.getElementById('activo').checked = paciente.activo !== undefined ? paciente.activo : true;
        
        // Campos de médico referente
        document.getElementById('medicoReferenteNombre').value = paciente.medico_referente_nombre || '';
        document.getElementById('medicoReferenteMatricula').value = paciente.medico_referente_matricula || '';
        
        // Limpiar y asignar número de teléfono del médico completo
        const medicoTelefonoCompleto = paciente.medico_referente_telefono || '';
        const medicoTelefonoLimpio = this.cleanPhoneNumber(medicoTelefonoCompleto);
        document.getElementById('medicoReferenteTelefono').value = medicoTelefonoLimpio;
        
        document.getElementById('medicoReferenteEmail').value = paciente.medico_referente_email || '';
        
        // En edición, deshabilitar campo ID interno y mantener readonly
        const pacienteIdInput = document.getElementById('pacienteIdInput');
        if (paciente.id) {
            pacienteIdInput.disabled = true;
            pacienteIdInput.readOnly = true;
        } else {
            pacienteIdInput.disabled = false;
            pacienteIdInput.readOnly = true; // Siempre readonly porque se autogenera
        }
        
        // Limpiar mensaje de búsqueda en edición
        const statusDiv = document.getElementById('idpacienteBusquedaStatus');
        if (statusDiv) {
            statusDiv.innerHTML = '';
        }
    }
    
    /**
     * Busca paciente por ID PACS (en BD o PACS)
     */
    async buscarPorIdPacs() {
        const idpacienteInput = document.getElementById('idpaciente');
        const statusDiv = document.getElementById('idpacienteBusquedaStatus');
        
        if (!idpacienteInput) return;
        
        const idpaciente = idpacienteInput.value.trim();
        
        if (!idpaciente) {
            if (statusDiv) {
                statusDiv.innerHTML = '<div class="alert alert-warning alert-sm py-1 px-2 mb-0"><small><i class="fas fa-info-circle me-1"></i>Ingrese un ID PACS para buscar</small></div>';
            }
            return;
        }
        
        // Solo buscar si es creación (no edición)
        const pacienteId = document.getElementById('pacienteId').value;
        if (pacienteId) {
            // Es edición, no buscar automáticamente
            return;
        }
        
        try {
            if (statusDiv) {
                statusDiv.innerHTML = '<div class="alert alert-info alert-sm py-1 px-2 mb-0"><small><i class="fas fa-spinner fa-spin me-1"></i>Buscando...</small></div>';
            }
            
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}search-by-idpacs.php?idpaciente=${encodeURIComponent(idpaciente)}`);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                if (result.data.found_in_db) {
                    // Encontrado en BD: usar datos existentes pero generar nuevo ID interno
                    const paciente = result.data.paciente;
                    
                    // Llenar formulario con datos existentes
                    document.getElementById('nombre').value = paciente.nombre || '';
                    
                    // Limpiar y asignar número de teléfono completo
                    const telefonoCompleto = paciente.telefono || '';
                    const telefonoLimpio = this.cleanPhoneNumber(telefonoCompleto);
                    document.getElementById('telefono').value = telefonoLimpio;
                    
                    document.getElementById('email').value = paciente.email || '';
                    document.getElementById('direccion').value = paciente.direccion || paciente.domicilio || '';
                    
                    // Limpiar ID interno (se generará nuevo automáticamente)
                    document.getElementById('pacienteIdInput').value = '';
                    
                    if (statusDiv) {
                        statusDiv.innerHTML = `<div class="alert alert-success alert-sm py-1 px-2 mb-0"><small><i class="fas fa-check-circle me-1"></i>Se encontraron datos anteriores. Se generará un nuevo ID interno.</small></div>`;
                    }
                    
                    this.showSuccess('Datos del paciente cargados. Se generará un nuevo ID interno automáticamente.');
                } else if (result.data.found_in_pacs) {
                    // Encontrado en PACS pero no en BD: obtener nombre
                    const nombre = result.data.nombre || '';
                    
                    document.getElementById('nombre').value = nombre;
                    
                    if (statusDiv) {
                        if (nombre) {
                            statusDiv.innerHTML = `<div class="alert alert-success alert-sm py-1 px-2 mb-0"><small><i class="fas fa-check-circle me-1"></i>Paciente encontrado en PACS. Complete teléfono, email y dirección.</small></div>`;
                        } else {
                            statusDiv.innerHTML = `<div class="alert alert-warning alert-sm py-1 px-2 mb-0"><small><i class="fas fa-exclamation-triangle me-1"></i>Paciente encontrado en PACS pero sin nombre. Complete todos los datos.</small></div>`;
                        }
                    }
                    
                    if (nombre) {
                        this.showSuccess('Paciente encontrado en PACS. Complete teléfono, email y dirección.');
                    } else {
                        this.showSuccess('Paciente encontrado en PACS. Complete todos los datos.');
                    }
                } else {
                    // No encontrado en ningún lado
                    if (statusDiv) {
                        statusDiv.innerHTML = `<div class="alert alert-warning alert-sm py-1 px-2 mb-0"><small><i class="fas fa-exclamation-triangle me-1"></i>No encontrado en BD ni PACS. Complete todos los datos manualmente.</small></div>`;
                    }
                }
            } else {
                throw new Error(result.error || 'Error al buscar paciente');
            }
        } catch (error) {
            console.error('Error buscando paciente por ID PACS:', error);
            if (statusDiv) {
                statusDiv.innerHTML = `<div class="alert alert-danger alert-sm py-1 px-2 mb-0"><small><i class="fas fa-times-circle me-1"></i>Error: ${error.message}</small></div>`;
            }
            this.showError('Error al buscar paciente: ' + error.message);
        }
    }
    
    /**
     * Guarda un paciente (crear o actualizar)
     */
    async savePaciente() {
        try {
            const form = document.getElementById('pacienteForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            const pacienteData = {};
            
            const isUpdate = document.getElementById('pacienteId').value && document.getElementById('pacienteId').value !== '';
            
            // Obtener todos los campos del formulario directamente
            const formElements = form.elements;
            
            // Campos que siempre deben incluirse
            const camposRequeridos = ['nombre', 'idpaciente', 'telefono', 'email', 'direccion', 'activo'];
            
            for (let i = 0; i < formElements.length; i++) {
                const element = formElements[i];
                const name = element.name;
                
                if (!name || name === 'id') {
                    continue; // Saltar campos sin nombre o el campo id (se maneja después)
                }
                
                if (name === 'activo') {
                    // Se manejará después
                    continue;
                }
                
                if (name === 'paciente_id' || name === 'id_interno') {
                    // En creación, no enviar id_interno (se generará automáticamente)
                    if (!isUpdate) {
                        continue;
                    } else {
                        // En edición, enviar si existe (mapear a id_interno)
                        if (element.value && element.value.trim() !== '') {
                            pacienteData['id_interno'] = element.value.trim();
                        }
                    }
                    continue;
                }
                
                // Manejar teléfono: limpiar y guardar número completo
                if (name === 'telefono') {
                    const phoneNumber = element.value || '';
                    pacienteData[name] = this.cleanPhoneNumber(phoneNumber);
                    continue;
                }
                
                // Manejar teléfono del médico referente: limpiar y guardar número completo
                if (name === 'medico_referente_telefono') {
                    const phoneNumber = element.value || '';
                    pacienteData[name] = this.cleanPhoneNumber(phoneNumber);
                    continue;
                }
                
                // Para todos los demás campos, incluir el valor (incluso si está vacío)
                if (element.type === 'checkbox') {
                    pacienteData[name] = element.checked;
                } else {
                    pacienteData[name] = element.value || '';
                }
            }
            
            // Manejar checkbox activo
            pacienteData.activo = document.getElementById('activo') ? document.getElementById('activo').checked : true;
            
            // ID solo en actualización
            if (isUpdate) {
                pacienteData.id = document.getElementById('pacienteId').value;
            }
            
            this.showLoading(true);
            
            const url = `${this.apiBaseUrl}save.php`;
            const method = isUpdate ? 'PUT' : 'POST';
            
            const response = await this.fetchWithAuth(url, {
                method: method,
                body: JSON.stringify(pacienteData)
            });
            
            // Intentar parsear respuesta incluso si hay error HTTP
            let result;
            try {
                const responseText = await response.text();
                console.log('Respuesta del servidor:', responseText);
                result = JSON.parse(responseText);
            } catch (e) {
                // Si no se puede parsear JSON, crear un error genérico
                console.error('Error parseando respuesta:', e);
                const responseText = await response.text().catch(() => 'No se pudo leer la respuesta');
                throw new Error(`Error HTTP: ${response.status} - ${response.statusText}. Respuesta: ${responseText.substring(0, 200)}`);
            }
            
            console.log('Resultado parseado:', result);
            
            if (!response.ok) {
                const errorMsg = result.error || `Error HTTP: ${response.status}`;
                console.error('Error guardando paciente:', errorMsg);
                console.error('Datos enviados:', pacienteData);
                throw new Error(errorMsg);
            }
            
            if (result.success) {
                this.showSuccess(isUpdate ? 'Paciente actualizado correctamente' : 'Paciente creado correctamente');
                const modal = bootstrap.Modal.getInstance(document.getElementById('pacienteModal'));
                if (modal) {
                    modal.hide();
                }
                this.loadPacientes();
            } else {
                const errorMsg = result.error || 'Error al guardar paciente';
                console.error('Error guardando paciente:', errorMsg);
                console.error('Datos enviados:', pacienteData);
                throw new Error(errorMsg);
            }
        } catch (error) {
            console.error('Error guardando paciente:', error);
            this.showError('Error al guardar paciente: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Envía un mensaje de WhatsApp al paciente
     * @param {number} pacienteId - ID del paciente
     * @param {string} telefono - Número de teléfono
     * @param {string} nombre - Nombre del paciente
     * @param {string} idInterno - ID interno del paciente
     */
    async sendWhatsApp(pacienteId, telefono, nombre, idInterno) {
        try {
            // Verificar permiso de envío de WhatsApp
            if (!this.hasWhatsAppSendPermission) {
                // Verificar nuevamente por si cambió
                await this.checkWhatsAppSendPermission();
                if (!this.hasWhatsAppSendPermission) {
                    this.showError('Tu cuenta no está autorizada para enviar mensajes por WhatsApp. Se requiere el permiso "Envíos por WhatsApp" (envios_whatsapp). Contacta al administrador para solicitar este permiso.');
                    return;
                }
            }
            
            if (!telefono || telefono.trim() === '') {
                this.showError('El paciente no tiene número de teléfono registrado');
                return;
            }
            
            // Cargar datos completos del paciente para obtener información del médico referente
            let pacienteCompleto = null;
            try {
                const response = await this.fetchWithAuth(`${this.apiBaseUrl}get.php?id=${pacienteId}`);
                if (response.ok) {
                    const result = await response.json();
                    if (result.success) {
                        pacienteCompleto = result.data;
                    }
                }
            } catch (error) {
                console.warn('No se pudieron cargar datos completos del paciente:', error);
            }
            
            // Guardar datos para el modal de confirmación
            this.whatsappData = {
                pacienteId: pacienteId,
                telefono: telefono,
                nombre: nombre,
                idInterno: idInterno,
                idpaciente: pacienteCompleto?.idpaciente || null,
                medicoReferente: pacienteCompleto ? {
                    nombre: pacienteCompleto.medico_referente_nombre || null,
                    matricula: pacienteCompleto.medico_referente_matricula || null,
                    telefono: pacienteCompleto.medico_referente_telefono || null,
                    email: pacienteCompleto.medico_referente_email || null
                } : null
            };
            
            // Obtener URL del portal
            const portalUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
            
            // Llenar información en el modal
            document.getElementById('whatsappPacienteNombre').textContent = nombre || '-';
            document.getElementById('whatsappPacienteTelefono').textContent = telefono || '-';
            document.getElementById('whatsappPacienteIdInterno').textContent = idInterno || '-';
            
            // Cargar plantillas disponibles
            await this.cargarPlantillasWhatsApp();
            
            // Aplicar plantillas por defecto si existen
            const defaultTemplatePaciente = await this.getDefaultWhatsAppTemplate('paciente');
            const templateSelectPaciente = document.getElementById('whatsappTemplateSelectPaciente');
            if (templateSelectPaciente && defaultTemplatePaciente) {
                templateSelectPaciente.value = defaultTemplatePaciente;
            }
            
            const defaultTemplateMedico = await this.getDefaultWhatsAppTemplate('medico');
            const templateSelectMedico = document.getElementById('whatsappTemplateSelectMedico');
            if (templateSelectMedico && defaultTemplateMedico) {
                templateSelectMedico.value = defaultTemplateMedico;
            }
            
            // Mostrar información del médico referente si existe
            const medicoInfo = document.getElementById('whatsappMedicoInfo');
            if (this.whatsappData.medicoReferente && this.whatsappData.medicoReferente.telefono) {
                document.getElementById('whatsappMedicoNombre').textContent = this.whatsappData.medicoReferente.nombre || '-';
                document.getElementById('whatsappMedicoMatricula').textContent = this.whatsappData.medicoReferente.matricula || '-';
                document.getElementById('whatsappMedicoTelefono').textContent = this.whatsappData.medicoReferente.telefono || '-';
                if (medicoInfo) medicoInfo.style.display = 'block';
            } else {
                if (medicoInfo) medicoInfo.style.display = 'none';
            }
            
            // Configurar listeners para cambio de plantilla
            if (templateSelectPaciente) {
                const hasListener = templateSelectPaciente.getAttribute('data-preview-listener');
                if (!hasListener) {
                    templateSelectPaciente.setAttribute('data-preview-listener', 'true');
                    templateSelectPaciente.addEventListener('change', async () => {
                        await this.actualizarPreviewWhatsApp();
                    });
                }
            }
            
            if (templateSelectMedico) {
                const hasListener = templateSelectMedico.getAttribute('data-preview-listener');
                if (!hasListener) {
                    templateSelectMedico.setAttribute('data-preview-listener', 'true');
                    templateSelectMedico.addEventListener('change', async () => {
                        await this.actualizarPreviewWhatsApp();
                    });
                }
            }
            
            // Actualizar preview inicial
            await this.actualizarPreviewWhatsApp();
            
            // Configurar el botón de confirmación
            const btnConfirmar = document.getElementById('btnConfirmarEnviarWhatsApp');
            if (btnConfirmar) {
                // Remover listeners anteriores
                const newBtn = btnConfirmar.cloneNode(true);
                btnConfirmar.parentNode.replaceChild(newBtn, btnConfirmar);
                
                // Agregar nuevo listener
                newBtn.addEventListener('click', () => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmWhatsAppModal'));
                    if (modal) {
                        modal.hide();
                    }
                    this.confirmarEnviarWhatsApp();
                });
            }
            
            // Mostrar el modal
            const modal = new bootstrap.Modal(document.getElementById('confirmWhatsAppModal'));
            modal.show();
            
        } catch (error) {
            console.error('Error preparando envío WhatsApp:', error);
            this.showError('Error al preparar envío de WhatsApp: ' + error.message);
        }
    }
    
    /**
     * Envía un email al paciente
     * @param {number} pacienteId - ID del paciente
     * @param {string} email - Email del paciente
     * @param {string} nombre - Nombre del paciente
     * @param {string} idInterno - ID interno del paciente
     */
    async sendEmail(pacienteId, email, nombre, idInterno) {
        try {
            // Verificar permiso de envío de email
            if (!this.hasEmailSendPermission) {
                // Verificar nuevamente por si cambió
                await this.checkEmailSendPermission();
                if (!this.hasEmailSendPermission) {
                    this.showError('Tu cuenta no está autorizada para enviar emails. Se requiere el permiso "Envíos por email" (envios_email). Contacta al administrador para solicitar este permiso.');
                    return;
                }
            }
            
            if (!email || email.trim() === '') {
                this.showError('El paciente no tiene email registrado');
                return;
            }
            
            // Cargar datos completos del paciente para obtener información del médico referente
            let pacienteCompleto = null;
            try {
                const response = await this.fetchWithAuth(`${this.apiBaseUrl}get.php?id=${pacienteId}`);
                if (response.ok) {
                    const result = await response.json();
                    if (result.success) {
                        pacienteCompleto = result.data;
                    }
                }
            } catch (error) {
                console.warn('No se pudieron cargar datos completos del paciente:', error);
            }
            
            // Guardar datos para el modal de confirmación
            this.emailData = {
                pacienteId: pacienteId,
                email: email,
                nombre: nombre,
                idInterno: idInterno,
                idpaciente: pacienteCompleto?.idpaciente || null,
                medicoReferente: pacienteCompleto ? {
                    nombre: pacienteCompleto.medico_referente_nombre || null,
                    matricula: pacienteCompleto.medico_referente_matricula || null,
                    telefono: pacienteCompleto.medico_referente_telefono || null,
                    email: pacienteCompleto.medico_referente_email || null
                } : null
            };
            
            // Obtener URL del portal
            const portalUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
            
            // Llenar información en el modal
            document.getElementById('emailPacienteNombre').textContent = nombre || '-';
            document.getElementById('emailPacienteEmail').textContent = email || '-';
            document.getElementById('emailPacienteIdInterno').textContent = idInterno || '-';
            
            // Mostrar información del médico referente si existe
            const medicoInfo = document.getElementById('emailMedicoInfo');
            if (this.emailData.medicoReferente && this.emailData.medicoReferente.email) {
                document.getElementById('emailMedicoNombre').textContent = this.emailData.medicoReferente.nombre || '-';
                document.getElementById('emailMedicoMatricula').textContent = this.emailData.medicoReferente.matricula || '-';
                document.getElementById('emailMedicoEmail').textContent = this.emailData.medicoReferente.email || '-';
                medicoInfo.style.display = 'block';
            } else {
                medicoInfo.style.display = 'none';
            }
            
            // Cargar plantillas disponibles
            await this.cargarPlantillasEmail();
            
            // Aplicar plantillas por defecto si existen
            const defaultTemplatePaciente = await this.getDefaultEmailTemplate('paciente');
            const templateSelectPaciente = document.getElementById('emailTemplateSelectPaciente');
            if (templateSelectPaciente && defaultTemplatePaciente) {
                templateSelectPaciente.value = defaultTemplatePaciente;
            }
            
            const defaultTemplateMedico = await this.getDefaultEmailTemplate('medico');
            const templateSelectMedico = document.getElementById('emailTemplateSelectMedico');
            if (templateSelectMedico && defaultTemplateMedico && this.emailData.medicoReferente && this.emailData.medicoReferente.email) {
                templateSelectMedico.value = defaultTemplateMedico;
            }
            
            // Actualizar preview inicial (con plantilla por defecto si existe, o mensaje estándar)
            await this.actualizarPreviewEmail();
            
            // Configurar listeners para cambio de plantilla
            if (templateSelectPaciente) {
                const hasListener = templateSelectPaciente.getAttribute('data-preview-listener');
                if (!hasListener) {
                    templateSelectPaciente.setAttribute('data-preview-listener', 'true');
                    templateSelectPaciente.addEventListener('change', async () => {
                        await this.actualizarPreviewEmail();
                    });
                }
            }
            
            if (templateSelectMedico) {
                const hasListener = templateSelectMedico.getAttribute('data-preview-listener');
                if (!hasListener) {
                    templateSelectMedico.setAttribute('data-preview-listener', 'true');
                    templateSelectMedico.addEventListener('change', async () => {
                        await this.actualizarPreviewEmail();
                    });
                }
            }
            
            // Configurar el botón de confirmación
            const btnConfirmar = document.getElementById('btnConfirmarEnviarEmail');
            if (btnConfirmar) {
                // Remover listeners anteriores
                const newBtn = btnConfirmar.cloneNode(true);
                btnConfirmar.parentNode.replaceChild(newBtn, btnConfirmar);
                
                // Agregar nuevo listener
                newBtn.addEventListener('click', () => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmEmailModal'));
                    if (modal) {
                        modal.hide();
                    }
                    this.confirmarEnviarEmail();
                });
            }
            
            // Mostrar el modal
            const modal = new bootstrap.Modal(document.getElementById('confirmEmailModal'));
            modal.show();
            
        } catch (error) {
            console.error('Error preparando envío Email:', error);
            this.showError('Error al preparar envío de Email: ' + error.message);
        }
    }
    
    /**
     * Inicializa el selector de plantilla por defecto de email para paciente
     */
    async initDefaultEmailTemplatePaciente() {
        try {
            const token = await this.getAuthToken();
            
            // Cargar plantillas disponibles
            const templatesResponse = await fetch('modules/email/api/list-templates.php', {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const templatesResult = await templatesResponse.json();
            const select = document.getElementById('defaultEmailTemplatePaciente');
            
            if (!select) return;
            
            if (templatesResult.success && templatesResult.data && templatesResult.data.templates) {
                // Limpiar opciones anteriores
                select.innerHTML = '<option value="">Sin plantilla por defecto</option>';
                
                // Agregar plantillas
                templatesResult.data.templates.forEach(template => {
                    const option = document.createElement('option');
                    option.value = template.name;
                    option.textContent = template.name;
                    select.appendChild(option);
                });
                
                // Cargar plantilla por defecto guardada desde el servidor
                const defaultTemplate = await this.getDefaultEmailTemplate('paciente');
                if (defaultTemplate) {
                    select.value = defaultTemplate;
                }
                
                // Configurar listener para guardar cuando cambie (solo una vez)
                const hasListener = select.getAttribute('data-save-listener');
                if (!hasListener) {
                    select.setAttribute('data-save-listener', 'true');
                    select.addEventListener('change', async () => {
                        await this.saveDefaultEmailTemplate('paciente', select.value);
                    });
                }
            } else {
                select.innerHTML = '<option value="">Error al cargar plantillas</option>';
            }
        } catch (error) {
            console.error('Error inicializando plantilla por defecto de email paciente:', error);
            const select = document.getElementById('defaultEmailTemplatePaciente');
            if (select) {
                select.innerHTML = '<option value="">Error al cargar</option>';
            }
        }
    }
    
    /**
     * Inicializa el selector de plantilla por defecto de email para médico
     */
    async initDefaultEmailTemplateMedico() {
        try {
            const token = await this.getAuthToken();
            
            // Cargar plantillas disponibles
            const templatesResponse = await fetch('modules/email/api/list-templates.php', {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const templatesResult = await templatesResponse.json();
            const select = document.getElementById('defaultEmailTemplateMedico');
            
            if (!select) return;
            
            if (templatesResult.success && templatesResult.data && templatesResult.data.templates) {
                // Limpiar opciones anteriores
                select.innerHTML = '<option value="">Sin plantilla por defecto</option>';
                
                // Agregar plantillas
                templatesResult.data.templates.forEach(template => {
                    const option = document.createElement('option');
                    option.value = template.name;
                    option.textContent = template.name;
                    select.appendChild(option);
                });
                
                // Cargar plantilla por defecto guardada desde el servidor
                const defaultTemplate = await this.getDefaultEmailTemplate('medico');
                if (defaultTemplate) {
                    select.value = defaultTemplate;
                }
                
                // Configurar listener para guardar cuando cambie (solo una vez)
                const hasListener = select.getAttribute('data-save-listener');
                if (!hasListener) {
                    select.setAttribute('data-save-listener', 'true');
                    select.addEventListener('change', async () => {
                        await this.saveDefaultEmailTemplate('medico', select.value);
                    });
                }
            } else {
                select.innerHTML = '<option value="">Error al cargar plantillas</option>';
            }
        } catch (error) {
            console.error('Error inicializando plantilla por defecto de email médico:', error);
            const select = document.getElementById('defaultEmailTemplateMedico');
            if (select) {
                select.innerHTML = '<option value="">Error al cargar</option>';
            }
        }
    }
    
    /**
     * Carga las plantillas de email disponibles (para el modal)
     */
    async cargarPlantillasEmail() {
        try {
            const token = await this.getAuthToken();
            const response = await fetch('modules/email/api/list-templates.php', {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const result = await response.json();
            const selectPaciente = document.getElementById('emailTemplateSelectPaciente');
            const selectMedico = document.getElementById('emailTemplateSelectMedico');
            
            if (result.success && result.data && result.data.templates) {
                const defaultOption = '<option value="">Sin plantilla (mensaje personalizado)</option>';
                
                // Cargar plantillas en selector de paciente
                if (selectPaciente) {
                    selectPaciente.innerHTML = defaultOption;
                    result.data.templates.forEach(template => {
                        const option = document.createElement('option');
                        option.value = template.name;
                        option.textContent = template.name;
                        selectPaciente.appendChild(option);
                    });
                }
                
                // Cargar plantillas en selector de médico
                if (selectMedico) {
                    selectMedico.innerHTML = defaultOption;
                    result.data.templates.forEach(template => {
                        const option = document.createElement('option');
                        option.value = template.name;
                        option.textContent = template.name;
                        selectMedico.appendChild(option);
                    });
                }
            } else {
                if (selectPaciente) selectPaciente.innerHTML = '<option value="">Error al cargar plantillas</option>';
                if (selectMedico) selectMedico.innerHTML = '<option value="">Error al cargar plantillas</option>';
            }
        } catch (error) {
            console.error('Error cargando plantillas:', error);
            const selectPaciente = document.getElementById('emailTemplateSelectPaciente');
            const selectMedico = document.getElementById('emailTemplateSelectMedico');
            if (selectPaciente) selectPaciente.innerHTML = '<option value="">Error al cargar plantillas</option>';
            if (selectMedico) selectMedico.innerHTML = '<option value="">Error al cargar plantillas</option>';
        }
    }
    
    /**
     * Guarda la plantilla por defecto en el servidor
     * @param {string} tipo - 'paciente' o 'medico'
     * @param {string} templateName - Nombre de la plantilla
     */
    async saveDefaultEmailTemplate(tipo, templateName) {
        try {
            const token = await this.getAuthToken();
            const response = await fetch('modules/email/api/save-default-template.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include',
                body: JSON.stringify({
                    template: templateName || '',
                    tipo: tipo || 'paciente'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log(`Plantilla por defecto de email ${tipo} guardada:`, templateName || 'ninguna');
            } else {
                throw new Error(result.error || 'Error al guardar plantilla por defecto');
            }
        } catch (error) {
            console.error('Error guardando plantilla por defecto:', error);
        }
    }
    
    /**
     * Obtiene la plantilla por defecto desde el servidor
     * @param {string} tipo - 'paciente' o 'medico'
     */
    async getDefaultEmailTemplate(tipo = 'paciente') {
        try {
            const token = await this.getAuthToken();
            const response = await fetch(`modules/email/api/get-default-template.php?tipo=${tipo}`, {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const result = await response.json();
            
            if (result.success && result.data && result.data.template) {
                return result.data.template;
            }
            return '';
        } catch (error) {
            console.error('Error obteniendo plantilla por defecto:', error);
            return '';
        }
    }
    
    /**
     * Inicializa el selector de plantilla por defecto de WhatsApp para paciente
     */
    async initDefaultWhatsAppTemplatePaciente() {
        try {
            const token = await this.getAuthToken();
            
            // Cargar plantillas disponibles
            const templatesResponse = await fetch('modules/email/api/manage-template-whatsapp.php?action=list', {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const templatesResult = await templatesResponse.json();
            const select = document.getElementById('defaultWhatsAppTemplatePaciente');
            
            if (!select) return;
            
            if (templatesResult.success && templatesResult.data && Array.isArray(templatesResult.data)) {
                // Limpiar opciones anteriores
                select.innerHTML = '<option value="">Sin plantilla por defecto</option>';
                
                // Agregar plantillas
                templatesResult.data.forEach(template => {
                    const option = document.createElement('option');
                    option.value = template.name;
                    option.textContent = template.name;
                    select.appendChild(option);
                });
                
                // Cargar plantilla por defecto guardada desde el servidor
                const defaultTemplate = await this.getDefaultWhatsAppTemplate('paciente');
                if (defaultTemplate) {
                    select.value = defaultTemplate;
                }
                
                // Configurar listener para guardar cuando cambie (solo una vez)
                const hasListener = select.getAttribute('data-save-listener');
                if (!hasListener) {
                    select.setAttribute('data-save-listener', 'true');
                    select.addEventListener('change', async () => {
                        await this.saveDefaultWhatsAppTemplate('paciente', select.value);
                    });
                }
            } else {
                select.innerHTML = '<option value="">Error al cargar plantillas</option>';
            }
        } catch (error) {
            console.error('Error inicializando plantilla por defecto de WhatsApp paciente:', error);
            const select = document.getElementById('defaultWhatsAppTemplatePaciente');
            if (select) {
                select.innerHTML = '<option value="">Error al cargar</option>';
            }
        }
    }
    
    /**
     * Inicializa el selector de plantilla por defecto de WhatsApp para médico
     */
    async initDefaultWhatsAppTemplateMedico() {
        try {
            const token = await this.getAuthToken();
            
            // Cargar plantillas disponibles
            const templatesResponse = await fetch('modules/email/api/manage-template-whatsapp.php?action=list', {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const templatesResult = await templatesResponse.json();
            const select = document.getElementById('defaultWhatsAppTemplateMedico');
            
            if (!select) return;
            
            if (templatesResult.success && templatesResult.data && Array.isArray(templatesResult.data)) {
                // Limpiar opciones anteriores
                select.innerHTML = '<option value="">Sin plantilla por defecto</option>';
                
                // Agregar plantillas
                templatesResult.data.forEach(template => {
                    const option = document.createElement('option');
                    option.value = template.name;
                    option.textContent = template.name;
                    select.appendChild(option);
                });
                
                // Cargar plantilla por defecto guardada desde el servidor
                const defaultTemplate = await this.getDefaultWhatsAppTemplate('medico');
                if (defaultTemplate) {
                    select.value = defaultTemplate;
                }
                
                // Configurar listener para guardar cuando cambie (solo una vez)
                const hasListener = select.getAttribute('data-save-listener');
                if (!hasListener) {
                    select.setAttribute('data-save-listener', 'true');
                    select.addEventListener('change', async () => {
                        await this.saveDefaultWhatsAppTemplate('medico', select.value);
                    });
                }
            } else {
                select.innerHTML = '<option value="">Error al cargar plantillas</option>';
            }
        } catch (error) {
            console.error('Error inicializando plantilla por defecto de WhatsApp médico:', error);
            const select = document.getElementById('defaultWhatsAppTemplateMedico');
            if (select) {
                select.innerHTML = '<option value="">Error al cargar</option>';
            }
        }
    }
    
    /**
     * Guarda la plantilla por defecto de WhatsApp en el servidor
     * @param {string} tipo - 'paciente' o 'medico'
     * @param {string} templateName - Nombre de la plantilla
     */
    async saveDefaultWhatsAppTemplate(tipo, templateName) {
        try {
            const token = await this.getAuthToken();
            const response = await fetch('modules/email/api/save-default-whatsapp-template.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include',
                body: JSON.stringify({
                    template: templateName || '',
                    tipo: tipo || 'paciente'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log(`Plantilla por defecto de WhatsApp ${tipo} guardada:`, templateName || 'ninguna');
            } else {
                throw new Error(result.error || 'Error al guardar plantilla por defecto de WhatsApp');
            }
        } catch (error) {
            console.error('Error guardando plantilla por defecto de WhatsApp:', error);
        }
    }
    
    /**
     * Obtiene la plantilla por defecto de WhatsApp desde el servidor
     */
    /**
     * Obtiene la plantilla por defecto de WhatsApp desde el servidor
     * @param {string} tipo - 'paciente' o 'medico'
     */
    async getDefaultWhatsAppTemplate(tipo = 'paciente') {
        try {
            const token = await this.getAuthToken();
            const response = await fetch(`modules/email/api/get-default-whatsapp-template.php?tipo=${tipo}`, {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const result = await response.json();
            
            if (result.success && result.data && result.data.template) {
                return result.data.template;
            }
            return '';
        } catch (error) {
            console.error('Error obteniendo plantilla por defecto de WhatsApp:', error);
            return '';
        }
    }
    
    /**
     * Actualiza el preview del mensaje según la plantilla seleccionada
     */
    async actualizarPreviewEmail() {
        if (!this.emailData) return;
        
        const { nombre, idInterno, idpaciente, medicoReferente } = this.emailData;
        const portalUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
        // Obtener método de búsqueda y construir URL con el parámetro correcto
        const searchMethod = await this.getSearchMethod();
        const portalUrlConAcceso = this.buildPortalUrlWithAccess(idInterno, idpaciente, searchMethod);
        
        // Obtener plantillas seleccionadas
        const templateSelectPaciente = document.getElementById('emailTemplateSelectPaciente');
        const templateSelectMedico = document.getElementById('emailTemplateSelectMedico');
        const selectedTemplatePaciente = templateSelectPaciente ? templateSelectPaciente.value : '';
        const selectedTemplateMedico = templateSelectMedico ? templateSelectMedico.value : '';
        
        const preview = document.getElementById('emailMensajePreview');
        if (!preview) return;
        
        // Preparar variables base para las plantillas
        const variablesBase = {
            // Variables principales
            paciente_nombre: nombre || 'Paciente',
            nombre_usuario: nombre || 'Paciente',
            nombre: nombre || 'Paciente',
            id_interno: idInterno || 'N/A',
            codigo_acceso: idInterno || 'N/A',
            // Variables de URL y acción
            url_accion: portalUrlConAcceso,
            url_portal: portalUrl,
            url_portal_con_acceso: portalUrlConAcceso,
            portal_url: portalUrl,
            texto_accion: 'Acceder al Portal',
            // Variables de mensaje
            mensaje: `Puede ingresar usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad.`,
            titulo: 'Acceso a sus estudios médicos',
            medico_referente: medicoReferente ? (medicoReferente.nombre || '') : ''
        };
        
        let previewHTML = '<div class="mb-3"><strong>📧 Email para Paciente:</strong><br>';
        
        // Preview para paciente
        if (selectedTemplatePaciente) {
            const contenidoPaciente = await this.obtenerContenidoPlantillaEmail(selectedTemplatePaciente, variablesBase);
            
            if (contenidoPaciente) {
                // Verificar si quedan variables sin procesar
                const unprocessed = contenidoPaciente.match(/\{\{[^}]+\}\}/g);
                if (unprocessed) {
                    console.warn('⚠️ Variables sin procesar en preview paciente:', unprocessed);
                }
                // Sanitizar el HTML antes de insertarlo
                const contenidoSanitizado = this.sanitizeHtmlForPreview(contenidoPaciente);
                previewHTML += `<div class="mt-2 mb-0 border rounded p-2" style="background: white; max-height: 300px; overflow-y: auto; isolation: isolate;">${contenidoSanitizado}</div>`;
            } else {
                previewHTML += `<div class="alert alert-warning mt-2 mb-0"><small>Plantilla: ${this.escapeHtml(selectedTemplatePaciente)}<br>No se pudo cargar el contenido de la plantilla.</small></div>`;
            }
        } else {
            const mensajePaciente = `Estimado/a ${nombre || 'paciente'}, puede ingresar a ${portalUrlConAcceso} usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad. TJS MEDICAL`;
            previewHTML += `<div class="alert alert-light mt-2 mb-0" style="white-space: pre-wrap;">${this.escapeHtml(mensajePaciente)}</div>`;
        }
        
        previewHTML += '</div>';
        
        // Preview para médico si existe
        if (medicoReferente && medicoReferente.email) {
            previewHTML += '<div class="mb-0"><strong>👨‍⚕️ Email para Médico Referente:</strong><br>';
            
            if (selectedTemplateMedico) {
                // Variables específicas para médico
                const variablesMedico = {
                    ...variablesBase,
                    nombre: medicoReferente.nombre || 'Médico Referente',
                    paciente_nombre: nombre || 'Paciente',
                    medico_nombre: medicoReferente.nombre || 'Médico Referente',
                    medico_matricula: medicoReferente.matricula || ''
                };
                
                const contenidoMedico = await this.obtenerContenidoPlantillaEmail(selectedTemplateMedico, variablesMedico);
                
                if (contenidoMedico) {
                    // Verificar si quedan variables sin procesar
                    const unprocessed = contenidoMedico.match(/\{\{[^}]+\}\}/g);
                    if (unprocessed) {
                        console.warn('⚠️ Variables sin procesar en preview médico:', unprocessed);
                    }
                    // Sanitizar el HTML antes de insertarlo
                    const contenidoSanitizado = this.sanitizeHtmlForPreview(contenidoMedico);
                    previewHTML += `<div class="mt-2 mb-0 border rounded p-2" style="background: white; max-height: 300px; overflow-y: auto; isolation: isolate;">${contenidoSanitizado}</div>`;
                } else {
                    previewHTML += `<div class="alert alert-warning mt-2 mb-0"><small>Plantilla: ${this.escapeHtml(selectedTemplateMedico)}<br>No se pudo cargar el contenido de la plantilla.</small></div>`;
                }
            } else {
                const mensajeMedico = `Estimado/a ${medicoReferente.nombre || 'Médico'}, se ha notificado al paciente ${nombre || 'Paciente'} sobre sus estudios médicos. Puede acceder al portal en ${portalUrl}. TJS MEDICAL`;
                previewHTML += `<div class="alert alert-info mt-2 mb-0" style="white-space: pre-wrap;">${this.escapeHtml(mensajeMedico)}</div>`;
            }
            
            previewHTML += '</div>';
        }
        
        preview.innerHTML = previewHTML;
        
        // Asegurar que el body no se vea afectado después de insertar el HTML
        setTimeout(() => {
            // Restaurar estilos del body por si acaso
            if (!document.body.classList.contains('modal-open')) {
                document.body.style.paddingRight = '';
                document.body.style.overflow = '';
                document.body.style.width = '';
            }
        }, 50);
    }
    
    /**
     * Obtiene el contenido de una plantilla de email y lo procesa con variables
     */
    async obtenerContenidoPlantillaEmail(templateName, variables) {
        try {
            const token = await this.getAuthToken();
            
            // Usar el endpoint de procesamiento que usa EmailTemplate en el backend
            const response = await fetch('modules/email/api/process-email-template.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include',
                body: JSON.stringify({
                    template: templateName,
                    variables: variables
                })
            });
            
            if (!response.ok) {
                console.error('Error HTTP al procesar plantilla:', response.status, response.statusText);
                const errorText = await response.text();
                console.error('Respuesta del servidor:', errorText);
                throw new Error(`Error HTTP ${response.status}: ${errorText}`);
            }
            
            const result = await response.json();
            
            console.log('Resultado del endpoint process-email-template:', {
                success: result.success,
                hasContent: !!(result.data && result.data.content),
                contentLength: result.data?.content?.length || 0,
                error: result.error
            });
            
            if (result.success && result.data && result.data.content) {
                // Verificar si quedan variables sin procesar
                const unprocessedVars = result.data.content.match(/\{\{[^}]+\}\}/g);
                if (unprocessedVars) {
                    console.warn('⚠️ Variables sin procesar en plantilla:', unprocessedVars);
                }
                return result.data.content;
            }
            
            // Si hay error, loguearlo
            if (!result.success) {
                console.error('Error procesando plantilla email:', result.error);
            }
            
            // Fallback: si el endpoint no funciona, intentar cargar directamente
            const fallbackResponse = await fetch(`modules/email/api/manage-template.php?action=get&name=${encodeURIComponent(templateName)}`, {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const fallbackResult = await fallbackResponse.json();
            
            if (fallbackResult.success && fallbackResult.data && fallbackResult.data.content) {
                let content = fallbackResult.data.content;
                
                // Agregar variables del sistema
                const allVariables = {
                    app_name: 'TJS Medical - Portal de Estudios',
                    app_url: window.location.origin,
                    current_year: new Date().getFullYear(),
                    current_date: new Date().toLocaleDateString('es-AR'),
                    current_datetime: new Date().toLocaleString('es-AR'),
                    ...variables
                };
                
                // Procesar bloques condicionales primero
                content = content.replace(/\{\{#if\s+([^}\s]+)\s*\}\}(.*?)\{\{\/if\}\}/gs, (match, varName, blockContent) => {
                    const varValue = allVariables[varName.trim()];
                    if (varValue && varValue !== 'N/A' && varValue !== '') {
                        return blockContent;
                    }
                    return '';
                });
                
                // Procesar variables con default
                content = content.replace(/\{\{([^|]+)\|([^}]+)\}\}/g, (match, varName, defaultValue) => {
                    const varValue = allVariables[varName.trim()];
                    if (varValue && varValue !== 'N/A' && varValue !== '') {
                        return varValue;
                    }
                    return defaultValue.trim();
                });
                
                // Reemplazar variables simples
                Object.keys(allVariables).forEach(key => {
                    const regex = new RegExp(`\\{\\{\\s*${key}\\s*\\}\\}`, 'g');
                    content = content.replace(regex, allVariables[key] || '');
                });
                
                // Limpiar cualquier variable sin reemplazar que quede
                content = content.replace(/\{\{[^}]+\}\}/g, '');
                
                return content;
            }
            
            return null;
        } catch (error) {
            console.error('Error obteniendo plantilla email:', error);
            return null;
        }
    }
    
    /**
     * Confirma y envía el email
     */
    async confirmarEnviarEmail() {
        try {
            // Verificar permiso antes de enviar
            if (!this.hasEmailSendPermission) {
                // Verificar nuevamente por si cambió
                await this.checkEmailSendPermission();
                if (!this.hasEmailSendPermission) {
                    this.showError('No tienes permisos para enviar emails. Se requiere el permiso "Envios por email" (envios_email). Contacta al administrador para solicitar este permiso.');
                    return;
                }
            }
            
            if (!this.emailData) {
                this.showError('No hay datos de email para enviar');
                return;
            }
            
            const { email, nombre, idInterno, idpaciente, medicoReferente } = this.emailData;
            
            // Preparar lista de destinatarios (paciente + médico referente si tiene email)
            const destinatarios = [email];
            if (medicoReferente && medicoReferente.email) {
                destinatarios.push(medicoReferente.email);
            }
            
            // Obtener URL del portal
            const portalUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
            // Obtener método de búsqueda y construir URL con el parámetro correcto
            const searchMethod = await this.getSearchMethod();
            const portalUrlConAcceso = this.buildPortalUrlWithAccess(idInterno, idpaciente, searchMethod);
            
            // Obtener plantillas seleccionadas
            const templateSelectPaciente = document.getElementById('emailTemplateSelectPaciente');
            const templateSelectMedico = document.getElementById('emailTemplateSelectMedico');
            const selectedTemplatePaciente = templateSelectPaciente ? templateSelectPaciente.value : '';
            const selectedTemplateMedico = templateSelectMedico ? templateSelectMedico.value : '';
            
            this.showLoading(true);
            
            // Obtener token de autenticación
            const token = await this.getAuthToken();
            
            // Enviar emails a todos los destinatarios con sus plantillas correspondientes
            const resultados = [];
            let todosExitosos = true;
            let mensajeError = '';
            
            for (const destinatario of destinatarios) {
                let response;
                
                // Determinar si es paciente o médico y usar la plantilla correspondiente
                const esPaciente = destinatario === email;
                const selectedTemplate = esPaciente ? selectedTemplatePaciente : selectedTemplateMedico;
                
                // Preparar variables según el destinatario
                const variables = {
                    // Variables principales
                    paciente_nombre: nombre || 'Paciente',
                    nombre_usuario: esPaciente ? (nombre || 'Paciente') : (medicoReferente?.nombre || 'Médico Referente'),
                    nombre: esPaciente ? (nombre || 'Paciente') : (medicoReferente?.nombre || 'Médico Referente'),
                    id_interno: idInterno || 'N/A',
                    codigo_acceso: idInterno || 'N/A',
                    // Variables de URL y acción
                    url_accion: portalUrlConAcceso,
                    url_portal: portalUrl,
                    url_portal_con_acceso: portalUrlConAcceso,
                    portal_url: portalUrl,
                    texto_accion: 'Acceder al Portal',
                    // Variables de mensaje
                    mensaje: `Puede ingresar usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad.`,
                    titulo: 'Acceso a sus estudios médicos',
                    // Variables adicionales
                    medico_referente: medicoReferente ? (medicoReferente.nombre || '') : '',
                    medico_nombre: medicoReferente ? (medicoReferente.nombre || '') : '',
                    medico_matricula: medicoReferente ? (medicoReferente.matricula || '') : ''
                };
                
                // Si hay plantilla seleccionada, usar send-template.php
                if (selectedTemplate) {
                    response = await fetch('modules/email/api/send-template.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': token ? `Bearer ${token}` : ''
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            template: selectedTemplate,
                            to: destinatario,
                            subject: `Acceso a sus estudios médicos - ${esPaciente ? (nombre || 'Paciente') : (medicoReferente?.nombre || 'Médico')}`,
                            variables: variables
                        })
                    });
                } else {
                    // Sin plantilla, usar mensaje personalizado
                    const nombreDestinatario = esPaciente ? (nombre || 'paciente') : (medicoReferente?.nombre || 'Médico');
                    const mensaje = esPaciente 
                        ? `Estimado/a ${nombre || 'paciente'}, puede ingresar a ${portalUrlConAcceso} usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad. TJS MEDICAL`
                        : `Estimado/a ${medicoReferente?.nombre || 'Médico'}, se ha notificado al paciente ${nombre || 'Paciente'} sobre sus estudios médicos. Puede acceder al portal en ${portalUrl}. TJS MEDICAL`;
                    
                    response = await fetch('modules/email/api/send.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': token ? `Bearer ${token}` : ''
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            to: destinatario,
                            subject: `Acceso a sus estudios médicos - ${nombreDestinatario}`,
                            body: esPaciente
                                ? `<h2>Estimado/a ${nombre || 'paciente'}</h2>
                                   <p>Puede ingresar a <a href="${portalUrlConAcceso}">${portalUrlConAcceso}</a> usando su Documento o ID Interno (<strong>${idInterno || 'N/A'}</strong>) para visualizar sus estudios de imágenes e informes de estudio.</p>
                                   <p>Gracias por confiar en nuestra experiencia y profesionalidad.</p>
                                   <p><strong>TJS MEDICAL</strong></p>`
                                : `<h2>Estimado/a ${medicoReferente?.nombre || 'Médico'}</h2>
                                   <p>Se ha notificado al paciente <strong>${nombre || 'Paciente'}</strong> sobre sus estudios médicos.</p>
                                   <p>Puede acceder al portal en <a href="${portalUrl}">${portalUrl}</a>.</p>
                                   <p><strong>TJS MEDICAL</strong></p>`,
                            body_type: 'html'
                        })
                    });
                }
                
                const result = await response.json();
                resultados.push({ destinatario, success: result.success, error: result.error });
                
                if (!result.success) {
                    todosExitosos = false;
                    mensajeError += `${destinatario}: ${result.error || 'Error desconocido'}. `;
                }
            }
            
            if (todosExitosos) {
                const destinatariosTexto = destinatarios.length > 1 
                    ? `a ${nombre || 'el paciente'} y al médico referente`
                    : `a ${nombre || 'el paciente'}`;
                this.showSuccess(`Email enviado correctamente ${destinatariosTexto}`);
                
                // Marcar email como enviado y actualizar botón visualmente
                if (this.emailData && this.emailData.pacienteId) {
                    this.emailsEnviados.add(this.emailData.pacienteId);
                    this.actualizarBotonEmail(this.emailData.pacienteId);
                    
                    // Guardar fecha en la base de datos para persistencia
                    try {
                        const updateResponse = await fetch(`${this.apiBaseUrl}update-email-sent.php`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Authorization': token ? `Bearer ${token}` : ''
                            },
                            credentials: 'include',
                            body: JSON.stringify({
                                paciente_id: this.emailData.pacienteId
                            })
                        });
                        
                        if (updateResponse.ok) {
                            const updateResult = await updateResponse.json();
                            if (updateResult.success) {
                                console.log('Fecha de email enviado actualizada en BD');
                            }
                        }
                    } catch (error) {
                        console.warn('No se pudo actualizar fecha de email en BD:', error);
                        // No fallar el proceso si esto falla
                    }
                }
            } else {
                throw new Error(mensajeError || 'Error al enviar algunos emails');
            }
            
            // Limpiar datos
            this.emailData = null;
            
        } catch (error) {
            console.error('Error enviando Email:', error);
            this.showError('Error al enviar email: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Carga las plantillas de WhatsApp disponibles (para el modal)
     */
    async cargarPlantillasWhatsApp() {
        try {
            const token = await this.getAuthToken();
            const response = await fetch('modules/email/api/manage-template-whatsapp.php?action=list', {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const result = await response.json();
            const selectPaciente = document.getElementById('whatsappTemplateSelectPaciente');
            const selectMedico = document.getElementById('whatsappTemplateSelectMedico');
            
            if (result.success && result.data && Array.isArray(result.data)) {
                const defaultOption = '<option value="">Sin plantilla (mensaje personalizado)</option>';
                
                // Cargar plantillas en selector de paciente
                if (selectPaciente) {
                    selectPaciente.innerHTML = defaultOption;
                    result.data.forEach(template => {
                        const option = document.createElement('option');
                        option.value = template.name;
                        option.textContent = template.name;
                        selectPaciente.appendChild(option);
                    });
                }
                
                // Cargar plantillas en selector de médico
                if (selectMedico) {
                    selectMedico.innerHTML = defaultOption;
                    result.data.forEach(template => {
                        const option = document.createElement('option');
                        option.value = template.name;
                        option.textContent = template.name;
                        selectMedico.appendChild(option);
                    });
                }
            } else {
                if (selectPaciente) selectPaciente.innerHTML = '<option value="">Sin plantillas disponibles</option>';
                if (selectMedico) selectMedico.innerHTML = '<option value="">Sin plantillas disponibles</option>';
            }
        } catch (error) {
            console.error('Error cargando plantillas WhatsApp:', error);
            const selectPaciente = document.getElementById('whatsappTemplateSelectPaciente');
            const selectMedico = document.getElementById('whatsappTemplateSelectMedico');
            if (selectPaciente) selectPaciente.innerHTML = '<option value="">Error al cargar plantillas</option>';
            if (selectMedico) selectMedico.innerHTML = '<option value="">Error al cargar plantillas</option>';
        }
    }
    
    /**
     * Muestra un mensaje cuando el usuario intenta enviar pero no tiene permiso
     * @param {string} tipo - 'whatsapp' o 'email'
     */
    showNoPermissionMessage(tipo) {
        const tipoNombre = tipo === 'whatsapp' ? 'WhatsApp' : 'Email';
        const permisoNombre = tipo === 'whatsapp' ? 'envios_whatsapp' : 'envios_email';
        const permisoTexto = tipo === 'whatsapp' ? 'Envíos por WhatsApp' : 'Envíos por email';
        
        // Crear modal de advertencia
        const modalHTML = `
            <div class="modal fade" id="noPermissionModal" tabindex="-1" aria-labelledby="noPermissionModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title" id="noPermissionModalLabel">
                                <i class="fas fa-exclamation-triangle me-2"></i>Cuenta No Autorizada
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning mb-0">
                                <p class="mb-2"><strong>Tu cuenta no está autorizada para enviar mensajes por ${tipoNombre}.</strong></p>
                                <p class="mb-0">Se requiere el permiso <strong>"${permisoTexto}"</strong> (<code>${permisoNombre}</code>).</p>
                                <p class="mt-2 mb-0"><small>Contacta al administrador del sistema para solicitar este permiso.</small></p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal anterior si existe
        const existingModal = document.getElementById('noPermissionModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Mostrar modal
        const modalElement = document.getElementById('noPermissionModal');
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
        
        // Limpiar modal cuando se cierre
        modalElement.addEventListener('hidden.bs.modal', () => {
            modalElement.remove();
        });
    }
    
    /**
     * Convierte HTML a texto plano para WhatsApp
     */
    htmlToPlainText(html) {
        if (!html) return '';
        
        // Crear un elemento temporal para parsear HTML
        const temp = document.createElement('div');
        temp.innerHTML = html;
        
        // Reemplazar etiquetas comunes por formato de texto
        let text = temp.textContent || temp.innerText || '';
        
        // Limpiar espacios múltiples
        text = text.replace(/\s+/g, ' ').trim();
        
        return text;
    }
    
    /**
     * Obtiene el contenido de una plantilla de WhatsApp y lo procesa con variables
     */
    async obtenerContenidoPlantillaWhatsApp(templateName, variables) {
        try {
            const token = await this.getAuthToken();
            
            // Usar el endpoint de procesamiento que usa WhatsAppTemplate en el backend
            const response = await fetch('modules/email/api/process-whatsapp-template.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include',
                body: JSON.stringify({
                    template: templateName,
                    variables: variables
                })
            });
            
            const result = await response.json();
            
            if (result.success && result.data && result.data.content) {
                return result.data.content;
            }
            
            // Fallback: si el endpoint no funciona, intentar cargar directamente
            const fallbackResponse = await fetch(`modules/email/api/manage-template-whatsapp.php?action=get&name=${encodeURIComponent(templateName)}`, {
                method: 'GET',
                headers: {
                    'Authorization': token ? `Bearer ${token}` : ''
                },
                credentials: 'include'
            });
            
            const fallbackResult = await fallbackResponse.json();
            
            if (fallbackResult.success && fallbackResult.data && fallbackResult.data.content) {
                let content = fallbackResult.data.content;
                
                // Agregar variables del sistema
                const allVariables = {
                    app_name: 'TJS Medical - Portal de Estudios',
                    app_url: window.location.origin,
                    current_year: new Date().getFullYear(),
                    current_date: new Date().toLocaleDateString('es-AR'),
                    current_datetime: new Date().toLocaleString('es-AR'),
                    ...variables
                };
                
                // Reemplazar variables en la plantilla (solo formato {{variable}}, no Handlebars)
                Object.keys(allVariables).forEach(key => {
                    const regex = new RegExp(`\\{\\{${key}\\}\\}`, 'g');
                    content = content.replace(regex, allVariables[key] || '');
                });
                
                // Limpiar cualquier sintaxis Handlebars que pueda quedar ({{#if}}, {{/if}}, etc.)
                content = content.replace(/\{\{#if\s+[^}]+\}\}/g, '');
                content = content.replace(/\{\{\/if\}\}/g, '');
                content = content.replace(/\{\{[^}]+\|([^}]+)\}\}/g, '$1'); // {{variable|default}} -> default
                
                // Limpiar espacios múltiples y saltos de línea excesivos
                content = content.replace(/\n{3,}/g, '\n\n');
                content = content.replace(/[ \t]+/g, ' ');
                
                return content.trim();
            }
            
            return null;
        } catch (error) {
            console.error('Error obteniendo plantilla WhatsApp:', error);
            return null;
        }
    }
    
    /**
     * Actualiza el preview del mensaje según la plantilla seleccionada
     */
    async actualizarPreviewWhatsApp() {
        if (!this.whatsappData) return;
        
        const { nombre, idInterno, idpaciente, medicoReferente } = this.whatsappData;
        const portalUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
        // Obtener método de búsqueda y construir URL con el parámetro correcto
        const searchMethod = await this.getSearchMethod();
        const portalUrlConAcceso = this.buildPortalUrlWithAccess(idInterno, idpaciente, searchMethod);
        
        // Obtener plantillas seleccionadas
        const templateSelectPaciente = document.getElementById('whatsappTemplateSelectPaciente');
        const templateSelectMedico = document.getElementById('whatsappTemplateSelectMedico');
        const selectedTemplatePaciente = templateSelectPaciente ? templateSelectPaciente.value : '';
        const selectedTemplateMedico = templateSelectMedico ? templateSelectMedico.value : '';
        
        const preview = document.getElementById('whatsappMensajePreview');
        if (!preview) return;
        
        // Preparar variables base para las plantillas
        const variablesBase = {
            // Variables principales
            paciente_nombre: nombre || 'Paciente',
            nombre_usuario: nombre || 'Paciente',
            nombre: nombre || 'Paciente',
            id_interno: idInterno || 'N/A',
            codigo_acceso: idInterno || 'N/A',
            // Variables de URL y acción
            url_accion: portalUrlConAcceso,
            url_portal: portalUrl,
            url_portal_con_acceso: portalUrlConAcceso,
            portal_url: portalUrl,
            texto_accion: 'Acceder al Portal',
            // Variables de mensaje
            mensaje: `Puede ingresar usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad.`,
            titulo: 'Acceso a sus estudios médicos',
            medico_referente: medicoReferente ? (medicoReferente.nombre || '') : ''
        };
        
        let previewHTML = '<div class="mb-3"><strong>📱 Mensaje para Paciente:</strong><br>';
        
        // Preview para paciente
        if (selectedTemplatePaciente) {
            const contenidoPaciente = await this.obtenerContenidoPlantillaWhatsApp(selectedTemplatePaciente, variablesBase);
            if (contenidoPaciente) {
                previewHTML += `<div class="alert alert-light mt-2 mb-0" style="white-space: pre-wrap;">${this.escapeHtml(contenidoPaciente)}</div>`;
            } else {
                previewHTML += `<div class="alert alert-warning mt-2 mb-0"><small>Plantilla: ${this.escapeHtml(selectedTemplatePaciente)}<br>No se pudo cargar el contenido de la plantilla.</small></div>`;
            }
        } else {
            const mensajePaciente = `Estimado/a ${nombre || 'paciente'}, puede ingresar a ${portalUrlConAcceso} usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad. TJS MEDICAL`;
            previewHTML += `<div class="alert alert-light mt-2 mb-0" style="white-space: pre-wrap;">${this.escapeHtml(mensajePaciente)}</div>`;
        }
        
        previewHTML += '</div>';
        
        // Preview para médico si existe
        if (medicoReferente && medicoReferente.telefono) {
            previewHTML += '<div class="mb-0"><strong>👨‍⚕️ Mensaje para Médico Referente:</strong><br>';
            
            if (selectedTemplateMedico) {
                // Variables específicas para médico
                const variablesMedico = {
                    ...variablesBase,
                    nombre: medicoReferente.nombre || 'Médico Referente',
                    paciente_nombre: nombre || 'Paciente',
                    medico_nombre: medicoReferente.nombre || 'Médico Referente',
                    medico_matricula: medicoReferente.matricula || ''
                };
                
                const contenidoMedico = await this.obtenerContenidoPlantillaWhatsApp(selectedTemplateMedico, variablesMedico);
                if (contenidoMedico) {
                    previewHTML += `<div class="alert alert-info mt-2 mb-0" style="white-space: pre-wrap;">${this.escapeHtml(contenidoMedico)}</div>`;
                } else {
                    previewHTML += `<div class="alert alert-warning mt-2 mb-0"><small>Plantilla: ${this.escapeHtml(selectedTemplateMedico)}<br>No se pudo cargar el contenido de la plantilla.</small></div>`;
                }
            } else {
                const mensajeMedico = `Estimado/a ${medicoReferente.nombre || 'Médico'}, se ha notificado al paciente ${nombre || 'Paciente'} sobre sus estudios médicos. Puede acceder al portal en ${portalUrl}. TJS MEDICAL`;
                previewHTML += `<div class="alert alert-info mt-2 mb-0" style="white-space: pre-wrap;">${this.escapeHtml(mensajeMedico)}</div>`;
            }
            
            previewHTML += '</div>';
        }
        
        preview.innerHTML = previewHTML;
    }
    
    /**
     * Confirma y envía el mensaje de WhatsApp
     */
    async confirmarEnviarWhatsApp() {
        try {
            if (!this.whatsappData) {
                this.showError('No hay datos de WhatsApp para enviar');
                return;
            }
            
            const { telefono, nombre, idInterno, idpaciente, medicoReferente } = this.whatsappData;
            
            // Preparar lista de destinatarios (paciente + médico referente si tiene teléfono)
            const destinatarios = [{ telefono, nombre: nombre || 'Paciente' }];
            if (medicoReferente && medicoReferente.telefono) {
                destinatarios.push({
                    telefono: medicoReferente.telefono,
                    nombre: medicoReferente.nombre || 'Médico Referente'
                });
            }
            
            // Obtener URL del portal
            const portalUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
            // Obtener método de búsqueda y construir URL con el parámetro correcto
            const searchMethod = await this.getSearchMethod();
            const portalUrlConAcceso = this.buildPortalUrlWithAccess(idInterno, idpaciente, searchMethod);
            
            // Obtener plantillas seleccionadas
            const templateSelectPaciente = document.getElementById('whatsappTemplateSelectPaciente');
            const templateSelectMedico = document.getElementById('whatsappTemplateSelectMedico');
            const selectedTemplatePaciente = templateSelectPaciente ? templateSelectPaciente.value : '';
            const selectedTemplateMedico = templateSelectMedico ? templateSelectMedico.value : '';
            
            this.showLoading(true);
            
            // Enviar mensajes a todos los destinatarios con sus plantillas correspondientes
            let exitosos = [];
            let fallidos = [];
            
            for (const destinatario of destinatarios) {
                try {
                    // Determinar si es paciente o médico y usar la plantilla correspondiente
                    const esPaciente = destinatario.telefono === telefono;
                    const selectedTemplate = esPaciente ? selectedTemplatePaciente : selectedTemplateMedico;
                    
                    // Preparar variables según el destinatario
                    const variables = {
                        // Variables principales
                        paciente_nombre: nombre || 'Paciente',
                        nombre_usuario: esPaciente ? (nombre || 'Paciente') : (medicoReferente?.nombre || 'Médico Referente'),
                        nombre: esPaciente ? (nombre || 'Paciente') : (medicoReferente?.nombre || 'Médico Referente'),
                        id_interno: idInterno || 'N/A',
                        codigo_acceso: idInterno || 'N/A',
                        // Variables de URL y acción
                        url_accion: portalUrlConAcceso,
                        url_portal: portalUrl,
                        url_portal_con_acceso: portalUrlConAcceso,
                        portal_url: portalUrl,
                        texto_accion: 'Acceder al Portal',
                        // Variables de mensaje
                        mensaje: `Puede ingresar usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad.`,
                        titulo: 'Acceso a sus estudios médicos',
                        // Variables adicionales
                        telefono: destinatario.telefono || '',
                        medico_referente: medicoReferente ? (medicoReferente.nombre || '') : '',
                        medico_nombre: medicoReferente ? (medicoReferente.nombre || '') : '',
                        medico_matricula: medicoReferente ? (medicoReferente.matricula || '') : ''
                    };
                    
                    // Determinar el mensaje a enviar
                    let mensaje = '';
                    
                    if (selectedTemplate) {
                        // Obtener contenido de la plantilla
                        const contenido = await this.obtenerContenidoPlantillaWhatsApp(selectedTemplate, variables);
                        
                        if (contenido) {
                            mensaje = contenido;
                        } else {
                            // Fallback a mensaje estándar si no se puede cargar la plantilla
                            if (esPaciente) {
                                mensaje = `Estimado/a ${nombre || 'paciente'}, puede ingresar a ${portalUrlConAcceso} usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad. TJS MEDICAL`;
                            } else {
                                mensaje = `Estimado/a ${medicoReferente?.nombre || 'Médico'}, se ha notificado al paciente ${nombre || 'Paciente'} sobre sus estudios médicos. Puede acceder al portal en ${portalUrl}. TJS MEDICAL`;
                            }
                        }
                    } else {
                        // Mensaje estándar sin plantilla
                        if (esPaciente) {
                            mensaje = `Estimado/a ${nombre || 'paciente'}, puede ingresar a ${portalUrlConAcceso} usando su Documento o ID Interno (${idInterno || 'N/A'}) para visualizar sus estudios de imágenes e informes de estudio. Gracias por confiar en nuestra experiencia y profesionalidad. TJS MEDICAL`;
                        } else {
                            mensaje = `Estimado/a ${medicoReferente?.nombre || 'Médico'}, se ha notificado al paciente ${nombre || 'Paciente'} sobre sus estudios médicos. Puede acceder al portal en ${portalUrl}. TJS MEDICAL`;
                        }
                    }
                    
                    const result = await evolutionAPI.sendTextMessage(destinatario.telefono, mensaje);
                    
                    if (!result.success) {
                        fallidos.push({
                            nombre: destinatario.nombre,
                            telefono: destinatario.telefono,
                            error: result.error || 'Error desconocido'
                        });
                    } else {
                        exitosos.push(destinatario);
                    }
                } catch (error) {
                    // Detectar tipo de error para mensaje más específico
                    let errorMessage = error.message || 'Error desconocido';
                    
                    // Si el error menciona que el número no está registrado o no está en contactos
                    if (errorMessage.includes('no está registrado') || 
                        errorMessage.includes('no estar en tus contactos') ||
                        errorMessage.includes('requerir que el destinatario')) {
                        errorMessage = `El número ${destinatario.telefono} no está registrado en WhatsApp, no está en tus contactos, o requiere que el destinatario te haya enviado un mensaje primero. Verifica que el número sea correcto y que el destinatario tenga WhatsApp activo.`;
                    }
                    
                    fallidos.push({
                        nombre: destinatario.nombre,
                        telefono: destinatario.telefono,
                        error: errorMessage
                    });
                }
            }
            
            // Mostrar mensajes según resultados
            if (exitosos.length === destinatarios.length) {
                // Todos exitosos
                const destinatariosTexto = destinatarios.length > 1 
                    ? `a ${nombre || 'el paciente'} y al médico referente`
                    : `a ${nombre || 'el paciente'}`;
                this.showSuccess(`Mensaje de WhatsApp enviado correctamente ${destinatariosTexto}`);
            } else if (exitosos.length > 0) {
                // Algunos exitosos, algunos fallidos
                let mensajeParcial = `Mensaje enviado a ${exitosos.length} de ${destinatarios.length} destinatario(s).`;
                if (fallidos.length > 0) {
                    mensajeParcial += '\n\nNo se pudo enviar a:';
                    fallidos.forEach(f => {
                        mensajeParcial += `\n• ${f.nombre} (${f.telefono}): ${f.error}`;
                    });
                }
                this.showError(mensajeParcial);
            } else {
                // Todos fallidos
                let mensajeError = 'No se pudo enviar el mensaje a ningún destinatario:\n\n';
                fallidos.forEach(f => {
                    mensajeError += `• ${f.nombre} (${f.telefono}): ${f.error}\n`;
                });
                throw new Error(mensajeError.trim());
            }
            
            // Limpiar datos
            this.whatsappData = null;
            
        } catch (error) {
            console.error('Error enviando WhatsApp:', error);
            this.showError('Error al enviar mensaje de WhatsApp: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Edita un paciente
     */
    editPaciente(id) {
        this.openModal(id);
    }
    
    /**
     * Ver detalles de un paciente
     */
    async viewPaciente(id) {
        try {
            this.showLoading(true);
            
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}get.php?id=${id}`);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                const paciente = result.data;
                this.showDetallesModal(paciente);
            } else {
                throw new Error(result.error || 'Error al cargar paciente');
            }
        } catch (error) {
            console.error('Error viendo paciente:', error);
            this.showError('Error al cargar paciente: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Muestra el modal con los detalles del paciente
     */
    showDetallesModal(paciente) {
        const modalBody = document.getElementById('detallesPacienteBody');
        const modalTitle = document.getElementById('detallesPacienteModalLabel');
        const btnEditar = document.getElementById('btnEditarDesdeDetalles');
        
        if (!modalBody) {
            console.error('Modal de detalles no encontrado');
            return;
        }
        
        // Actualizar título del modal
        if (modalTitle) {
            modalTitle.innerHTML = `<i class="fas fa-user-injured me-2"></i>Detalles del Paciente - ${paciente.nombre || 'Sin nombre'}`;
        }
        
        // Configurar botón editar
        if (btnEditar) {
            btnEditar.onclick = () => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('detallesPacienteModal'));
                if (modal) {
                    modal.hide();
                }
                setTimeout(() => {
                    this.editPaciente(paciente.id);
                }, 300);
            };
        }
        
        // Formatear fecha de creación
        let fechaCreacion = 'N/A';
        if (paciente.fecha_creacion) {
            try {
                const fecha = new Date(paciente.fecha_creacion);
                fechaCreacion = fecha.toLocaleDateString('es-ES', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                fechaCreacion = paciente.fecha_creacion;
            }
        }
        
        // Formatear fecha de actualización
        let fechaActualizacion = 'N/A';
        if (paciente.fecha_actualizacion) {
            try {
                const fecha = new Date(paciente.fecha_actualizacion);
                fechaActualizacion = fecha.toLocaleDateString('es-ES', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                fechaActualizacion = paciente.fecha_actualizacion;
            }
        }
        
        // Estado badge
        const estadoBadge = paciente.activo
            ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Activo</span>'
            : '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Inactivo</span>';
        
        // Construir HTML del modal
        const detallesHTML = `
            <div class="row">
                <div class="col-12 mb-4">
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-user-circle fa-4x text-info"></i>
                        </div>
                        <h4 class="mb-2">${paciente.nombre || 'Sin nombre'}</h4>
                        <div class="mb-3">${estadoBadge}</div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-3">
                                <i class="fas fa-id-card me-2"></i>Información de Identificación
                            </h6>
                            <div class="mb-2">
                                <strong>ID Interno:</strong>
                                <div class="text-break">${paciente.id_interno || paciente.paciente_id || 'N/A'}</div>
                            </div>
                            ${paciente.idpaciente ? `
                            <div class="mb-2">
                                <strong>ID PACS:</strong>
                                <div class="text-break">${paciente.idpaciente}</div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-3">
                                <i class="fas fa-address-book me-2"></i>Información de Contacto
                            </h6>
                            ${paciente.telefono ? `
                            <div class="mb-2">
                                <strong><i class="fas fa-phone me-2"></i>Teléfono:</strong>
                                <div><a href="tel:${paciente.telefono}">${paciente.telefono}</a></div>
                            </div>
                            ` : '<div class="mb-2 text-muted"><i class="fas fa-phone me-2"></i>Teléfono: No registrado</div>'}
                            ${paciente.email ? `
                            <div class="mb-2">
                                <strong><i class="fas fa-envelope me-2"></i>Email:</strong>
                                <div><a href="mailto:${paciente.email}">${paciente.email}</a></div>
                            </div>
                            ` : '<div class="mb-2 text-muted"><i class="fas fa-envelope me-2"></i>Email: No registrado</div>'}
                        </div>
                    </div>
                </div>
                
                ${paciente.domicilio || paciente.direccion ? `
                <div class="col-12 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-3">
                                <i class="fas fa-map-marker-alt me-2"></i>Dirección
                            </h6>
                            <div class="text-break">${paciente.domicilio || paciente.direccion}</div>
                        </div>
                    </div>
                </div>
                ` : ''}
                
                <div class="col-md-6 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-3">
                                <i class="fas fa-calendar-plus me-2"></i>Fecha de Creación
                            </h6>
                            <div>${fechaCreacion}</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-3">
                                <i class="fas fa-calendar-edit me-2"></i>Última Actualización
                            </h6>
                            <div>${fechaActualizacion}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        modalBody.innerHTML = detallesHTML;
        
        // Mostrar el modal
        const modal = new bootstrap.Modal(document.getElementById('detallesPacienteModal'));
        modal.show();
    }
    
    /**
     * Confirma la eliminación de un paciente usando modal Bootstrap
     */
    async confirmDelete(id) {
        try {
            // Cargar datos del paciente para mostrar en el modal
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}get.php?id=${id}`);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                const paciente = result.data;
                
                // Llenar información del paciente en el modal
                document.getElementById('deletePacienteId').textContent = paciente.id_interno || paciente.paciente_id || '-';
                document.getElementById('deletePacienteNombre').textContent = paciente.nombre || '-';
                document.getElementById('deletePacienteIdPacs').textContent = paciente.idpaciente || '-';
                
                // Configurar el botón de confirmación
                const btnConfirmar = document.getElementById('btnConfirmarEliminarPaciente');
                if (btnConfirmar) {
                    // Remover listeners anteriores
                    const newBtn = btnConfirmar.cloneNode(true);
                    btnConfirmar.parentNode.replaceChild(newBtn, btnConfirmar);
                    
                    // Agregar nuevo listener
                    newBtn.addEventListener('click', () => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal'));
                        if (modal) {
                            modal.hide();
                        }
                        this.deletePaciente(id);
                    });
                }
                
                // Mostrar el modal
                const modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
                modal.show();
            } else {
                throw new Error(result.error || 'Error al cargar paciente');
            }
        } catch (error) {
            console.error('Error cargando paciente para eliminar:', error);
            this.showError('Error al cargar información del paciente: ' + error.message);
        }
    }
    
    /**
     * Elimina un paciente
     */
    async deletePaciente(id) {
        try {
            this.showLoading(true);
            
            // Usar POST en lugar de DELETE para mayor compatibilidad
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}delete.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-HTTP-Method-Override': 'DELETE'
                },
                body: JSON.stringify({ id: id })
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error response:', errorText);
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            console.log('Resultado eliminación:', result);
            
            if (result.success) {
                this.showSuccess('Paciente eliminado correctamente');
                // Recargar la lista después de un pequeño delay para asegurar que la BD se actualizó
                setTimeout(() => {
                    this.loadPacientes();
                }, 300);
            } else {
                throw new Error(result.error || 'Error al eliminar paciente');
            }
        } catch (error) {
            console.error('Error eliminando paciente:', error);
            this.showError('Error al eliminar paciente: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Muestra/oculta el loading overlay
     */
    showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = show ? 'flex' : 'none';
        }
    }
    
    /**
     * Muestra un mensaje de error usando Bootstrap Toast
     */
    showError(message) {
        // Si es error de permisos, no mostrar nada (ya se mostró el modal)
        if (message && (message.includes('permisos') || message.includes('permiso'))) {
            console.warn('Error de permisos:', message);
            return;
        }
        
        // Crear toast de Bootstrap para mostrar el error
        const toastId = 'errorToast_' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 5000
        });
        toast.show();
        
        // Remover el toast del DOM después de que se oculte
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
    
    /**
     * Muestra un mensaje de éxito usando Bootstrap Toast
     */
    showSuccess(message) {
        // Crear toast de Bootstrap para mostrar el éxito
        const toastId = 'successToast_' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-check-circle me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 3000
        });
        toast.show();
        
        // Remover el toast del DOM después de que se oculte
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
    
    /**
     * Renderiza estado de error
     */
    renderError() {
        const tbody = document.getElementById('pacientesTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                                        <p class="text-danger">Error al cargar pacientes</p>
                                    </td>
                </tr>
            `;
        }
    }
    
    /**
     * Abre el modal de búsqueda de estudios
     */
    abrirModalBuscarEstudios() {
        const modal = new bootstrap.Modal(document.getElementById('buscarEstudiosModal'));
        
        // Limpiar resultados anteriores
        const resultadosContainer = document.getElementById('estudiosResultadosContainer');
        if (resultadosContainer) {
            resultadosContainer.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Selecciona un rango de fechas y presiona "Buscar Estudios" para ver los estudios disponibles.
                </div>
            `;
        }
        
        // Establecer fechas por defecto (últimos 30 días)
        const hoy = new Date();
        const hace30Dias = new Date();
        hace30Dias.setDate(hace30Dias.getDate() - 30);
        
        const dateFromInput = document.getElementById('estudioDateFrom');
        const dateToInput = document.getElementById('estudioDateTo');
        
        if (dateFromInput && !dateFromInput.value) {
            dateFromInput.value = hace30Dias.toISOString().split('T')[0];
        }
        if (dateToInput && !dateToInput.value) {
            dateToInput.value = hoy.toISOString().split('T')[0];
        }
        
        modal.show();
    }
    
    /**
     * Busca estudios con los filtros especificados
     */
    async buscarEstudios() {
        const dateFrom = document.getElementById('estudioDateFrom')?.value || '';
        const dateTo = document.getElementById('estudioDateTo')?.value || '';
        const patientId = document.getElementById('estudioPatientId')?.value.trim() || '';
        const resultadosContainer = document.getElementById('estudiosResultadosContainer');
        const btnBuscarEstudios = document.getElementById('btnBuscarEstudios');
        
        // Validar que al menos se seleccione un rango de fechas
        if (!dateFrom && !dateTo) {
            this.showError('Por favor selecciona al menos una fecha (desde o hasta) para buscar estudios');
            return;
        }
        
        try {
            // Mostrar loading
            if (btnBuscarEstudios) {
                btnBuscarEstudios.disabled = true;
                const originalContent = btnBuscarEstudios.innerHTML;
                btnBuscarEstudios.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Buscando...';
                
                // Restaurar después de un tiempo máximo
                setTimeout(() => {
                    btnBuscarEstudios.disabled = false;
                    btnBuscarEstudios.innerHTML = originalContent;
                }, 30000);
            }
            
            if (resultadosContainer) {
                resultadosContainer.innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Buscando estudios...</span>
                        </div>
                        <p class="mt-2">Buscando estudios...</p>
                    </div>
                `;
            }
            
            // Construir parámetros
            const params = new URLSearchParams();
            if (dateFrom) params.append('dateFrom', dateFrom);
            if (dateTo) params.append('dateTo', dateTo);
            if (patientId) params.append('patientId', patientId);
            
            const url = 'api/get_all_studies.php?' + params.toString();
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Error al buscar estudios');
            }
            
            const estudios = result.data || [];
            
            // Verificar qué pacientes ya tienen ID Interno
            const pacientesConIdInterno = await this.verificarPacientesConIdInterno(estudios);
            
            // Renderizar resultados
            this.renderizarEstudios(estudios, pacientesConIdInterno);
            
        } catch (error) {
            console.error('Error buscando estudios:', error);
            if (resultadosContainer) {
                resultadosContainer.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Error:</strong> ${error.message}
                    </div>
                `;
            }
            this.showError('Error al buscar estudios: ' + error.message);
        } finally {
            if (btnBuscarEstudios) {
                btnBuscarEstudios.disabled = false;
                btnBuscarEstudios.innerHTML = '<i class="fas fa-search me-2"></i>Buscar Estudios';
            }
        }
    }
    
    /**
     * Verifica qué pacientes (por ID PACS) ya tienen ID Interno en la base de datos
     */
    async verificarPacientesConIdInterno(estudios) {
        if (!estudios || estudios.length === 0) {
            return new Set();
        }
        
        // Extraer IDs PACS únicos
        const idsPacs = [...new Set(estudios
            .map(e => e.patient_id)
            .filter(id => id && id !== '-'))];
        
        if (idsPacs.length === 0) {
            return new Set();
        }
        
        try {
            // Consultar la API para verificar qué pacientes tienen ID Interno
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}check-pacientes-id-interno.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ idsPacs: idsPacs })
            });
            
            if (!response.ok) {
                console.warn('Error verificando pacientes con ID Interno, continuando sin marcar filas');
                return new Set();
            }
            
            const result = await response.json();
            
            if (result.success && result.data) {
                return new Set(result.data);
            }
            
            return new Set();
        } catch (error) {
            console.warn('Error verificando pacientes con ID Interno:', error);
            return new Set();
        }
    }
    
    /**
     * Escapa HTML para prevenir XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Sanitiza HTML para el preview, removiendo estilos que puedan afectar el layout global
     */
    sanitizeHtmlForPreview(html) {
        if (!html) return '';
        
        // Crear un elemento temporal para parsear el HTML
        const temp = document.createElement('div');
        temp.innerHTML = html;
        
        // Remover todos los tags <style>
        const styleTags = temp.querySelectorAll('style');
        styleTags.forEach(style => style.remove());
        
        // Remover todos los tags <script>
        const scriptTags = temp.querySelectorAll('script');
        scriptTags.forEach(script => script.remove());
        
        // Remover estilos inline que puedan afectar el body o elementos globales
        // Buscar elementos con estilos que puedan ser problemáticos
        const allElements = temp.querySelectorAll('*');
        allElements.forEach(el => {
            // Remover estilos inline que puedan afectar el layout global
            if (el.style) {
                // Remover propiedades que puedan afectar el layout global
                const problematicStyles = ['position', 'z-index', 'width', 'max-width', 'min-width', 'height', 'overflow', 'display'];
                problematicStyles.forEach(prop => {
                    if (el.style[prop]) {
                        el.style[prop] = '';
                    }
                });
                
                // Si el elemento tiene position: fixed o absolute, cambiarlo a relative
                if (el.style.position === 'fixed' || el.style.position === 'absolute') {
                    el.style.position = 'relative';
                }
            }
            
            // Remover atributos que puedan causar problemas
            el.removeAttribute('onload');
            el.removeAttribute('onerror');
            el.removeAttribute('onclick');
        });
        
        // Obtener el HTML sanitizado
        let sanitized = temp.innerHTML;
        
        // Remover cualquier tag <style> que pueda quedar en el string
        sanitized = sanitized.replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '');
        
        // Remover cualquier tag <script> que pueda quedar
        sanitized = sanitized.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
        
        return sanitized;
    }
    
    /**
     * Renderiza la lista de estudios en una tabla
     * @param {Array} estudios - Lista de estudios
     * @param {Set} pacientesConIdInterno - Set de IDs PACS que ya tienen ID Interno
     */
    renderizarEstudios(estudios, pacientesConIdInterno = new Set()) {
        const resultadosContainer = document.getElementById('estudiosResultadosContainer');
        
        if (!resultadosContainer) return;
        
        if (estudios.length === 0) {
            resultadosContainer.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    No se encontraron estudios con los filtros especificados.
                </div>
            `;
            return;
        }
        
        // Formatear fecha para mostrar
        const formatearFecha = (fechaStr) => {
            if (!fechaStr) return '-';
            // Formato YYYYMMDD a DD/MM/YYYY
            if (fechaStr.length === 8) {
                return `${fechaStr.substring(6, 8)}/${fechaStr.substring(4, 6)}/${fechaStr.substring(0, 4)}`;
            }
            // Si ya está en formato con guiones
            if (fechaStr.includes('-')) {
                const partes = fechaStr.split('-');
                return `${partes[2]}/${partes[1]}/${partes[0]}`;
            }
            return fechaStr;
        };
        
        let html = `
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Paciente</th>
                            <th>ID PACS</th>
                            <th>Modalidad</th>
                            <th>Descripción</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        estudios.forEach(estudio => {
            const fechaFormateada = formatearFecha(estudio.date);
            const nombrePaciente = this.escapeHtml(estudio.patient_name || '-');
            const idPacs = this.escapeHtml(estudio.patient_id || '-');
            const modalidad = this.escapeHtml(estudio.modality || '-');
            const descripcion = this.escapeHtml(estudio.study_description || '-');
            
            // Verificar si el paciente ya tiene ID Interno
            const tieneIdInterno = idPacs !== '-' && pacientesConIdInterno.has(estudio.patient_id);
            const claseFila = tieneIdInterno ? 'paciente-con-id-interno' : '';
            const iconoInfo = tieneIdInterno ? '<i class="fas fa-info-circle text-info me-1" title="Este paciente ya tiene ID Interno asignado"></i>' : '';
            
            // Usar data attributes para evitar problemas con caracteres especiales
            html += `
                <tr class="${claseFila}">
                    <td>${fechaFormateada}</td>
                    <td>${iconoInfo}${nombrePaciente}</td>
                    <td><code>${idPacs}</code></td>
                    <td>${modalidad}</td>
                    <td>${descripcion}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-primary btn-seleccionar-estudio" 
                                data-id-pacs="${idPacs}" 
                                data-nombre-paciente="${nombrePaciente}">
                            <i class="fas fa-check me-1"></i>Seleccionar
                        </button>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
            <div class="mt-2">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>Se encontraron ${estudios.length} estudio(s)
                </small>
                ${pacientesConIdInterno.size > 0 ? `
                <br><small class="text-info">
                    <i class="fas fa-info-circle me-1"></i>
                    Las filas marcadas en azul corresponden a pacientes que ya tienen ID Interno asignado. 
                    Al seleccionarlos, se generará un nuevo ID Interno adicional.
                </small>
                ` : ''}
            </div>
        `;
        
        resultadosContainer.innerHTML = html;
        
        // Agregar event listeners a los botones de selección
        const botonesSeleccionar = resultadosContainer.querySelectorAll('.btn-seleccionar-estudio');
        botonesSeleccionar.forEach(boton => {
            boton.addEventListener('click', () => {
                const idPacs = boton.getAttribute('data-id-pacs');
                const nombrePaciente = boton.getAttribute('data-nombre-paciente');
                this.seleccionarEstudio(idPacs, nombrePaciente);
            });
        });
    }
    
    /**
     * Selecciona un estudio y carga el ID PACS en el formulario
     */
    seleccionarEstudio(idPacs, nombrePaciente) {
        // Cerrar el modal de búsqueda
        const modal = bootstrap.Modal.getInstance(document.getElementById('buscarEstudiosModal'));
        if (modal) {
            modal.hide();
        }
        
        // Cargar el ID PACS en el campo
        const idpacienteInput = document.getElementById('idpaciente');
        if (idpacienteInput) {
            idpacienteInput.value = idPacs;
            
            // Si hay nombre de paciente, cargarlo también
            if (nombrePaciente && nombrePaciente !== '-') {
                const nombreInput = document.getElementById('nombre');
                if (nombreInput && !nombreInput.value) {
                    nombreInput.value = nombrePaciente;
                }
            }
            
            // Disparar el evento de búsqueda automática para cargar datos adicionales
            // Solo si es creación (no edición)
            const pacienteId = document.getElementById('pacienteId').value;
            if (!pacienteId) {
                // Esperar un momento para que el modal se cierre y luego buscar
                setTimeout(() => {
                    this.buscarPorIdPacs();
                }, 300);
            } else {
                // Si es edición, solo mostrar mensaje
                this.showSuccess(`ID PACS "${idPacs}" cargado correctamente`);
            }
        }
    }
    
    /**
     * Limpia los filtros de búsqueda de estudios
     */
    limpiarFiltrosEstudios() {
        document.getElementById('estudioDateFrom').value = '';
        document.getElementById('estudioDateTo').value = '';
        document.getElementById('estudioPatientId').value = '';
        
        const resultadosContainer = document.getElementById('estudiosResultadosContainer');
        if (resultadosContainer) {
            resultadosContainer.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Selecciona un rango de fechas y presiona "Buscar Estudios" para ver los estudios disponibles.
                </div>
            `;
        }
    }
}

// Inicializar cuando el DOM esté listo y el usuario tenga permisos
let pacientesManager;
let pacientesManagerInitialized = false;

// Función para inicializar el manager (solo si tiene permisos)
window.initPacientesManager = function() {
    if (!pacientesManagerInitialized) {
        pacientesManager = new PacientesManager();
        pacientesManager.init();
        pacientesManagerInitialized = true;
    }
};

// NO inicializar automáticamente - esperar a que se verifique permisos
// El código en pacientes-manager.html llamará a initPacientesManager() después de verificar permisos

