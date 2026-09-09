/**
 * JavaScript para Gestión de Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

class UserManagement {
    constructor() {
        this.users = [];
        this.hierarchy = [];
        this.currentView = 'list';
        this.filters = {
            search: '',
            level: '',
            rol: '',
            status: ''
        };
        this.currentUser = null;
        this.userToDelete = null;
            this.userToPermanentDelete = null;
            this.systemPermissions = [];
            this.allUsers = []; // Para gestión avanzada BD
            this.copyPermissionsSourceId = null; // Para copia de permisos
    }
    
    async init() {
        try {
            // Cargar usuario actual primero
            try {
                await this.loadCurrentUser();
            } catch (error) {
                console.error('Error cargando usuario actual:', error);
                // Si falla la carga del usuario, no continuar
                return;
            }
            
            // Cargar usuarios y permisos en paralelo para mejor rendimiento
            try {
                await Promise.all([
                    this.loadUsers(),
                    this.loadSystemPermissions()
                ]);
            } catch (error) {
                console.error('Error cargando datos:', error);
                this.showAlert('Error al cargar algunos datos. Algunas funciones pueden no estar disponibles.', 'warning');
            }
            
            this.setupEventListeners();
            this.updateStats();
        } catch (error) {
            console.error('Error inicializando gestión de usuarios:', error);
            this.showAlert('Error al inicializar el sistema', 'error');
        }
    }
    
    async loadCurrentUser() {
        try {
            // Cargar usuario desde sesión real
            const response = await fetch('api/auth/validate-session-simple.php');
            const result = await response.json();
            
            if (result.success && (result.data || result.user)) {
                // Soportar ambos formatos de respuesta
                this.currentUser = result.data || result.user;
                
                console.log('✅ Usuario actual cargado:', {
                    id: this.currentUser.id,
                    nombre: this.currentUser.nombre,
                    nivel: this.currentUser.nivel
                });
                
                this.updateUserInfo();
            } else {
                throw new Error('No se pudo validar la sesión');
            }
        } catch (error) {
            console.error('❌ Error cargando usuario actual:', error);
            this.showAlert('Error: No se pudo validar tu sesión. Por favor, inicia sesión nuevamente.', 'error');
            
            // Redirigir al login después de 2 segundos
            setTimeout(() => {
                window.location.href = '/portal_estudios/';
            }, 2000);
        }
    }
    
    updateUserInfo() {
        const userNameElements = document.querySelectorAll('.user-name');
        const userMatriculaElements = document.querySelectorAll('.user-matricula');
        
        userNameElements.forEach(el => {
            el.textContent = `${this.currentUser.nombre} ${this.currentUser.apellido}`;
        });
        
        userMatriculaElements.forEach(el => {
            el.textContent = this.currentUser.matricula_profesional || 'Sin matrícula';
        });
        
        // Mostrar botón de gestión avanzada BD solo para ROOT
        const dbManagementBtn = document.getElementById('dbManagementBtn');
        if (dbManagementBtn && this.currentUser.nivel === 'root') {
            dbManagementBtn.style.display = 'inline-block';
        }
    }
    
    async loadUsers() {
        try {
            this.showLoading(true);
            
            const response = await fetch('api/users/manage-real-complete.php', {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status} ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.users = result.data.users || [];
                this.hierarchy = result.data.hierarchy || [];
                this.renderUsers();
            } else {
                throw new Error(result.error || 'Error desconocido al cargar usuarios');
            }
        } catch (error) {
            console.error('Error cargando usuarios:', error);
            console.error('Stack trace:', error.stack);
            this.showAlert('Error al cargar usuarios: ' + (error.message || 'Error desconocido'), 'error');
        } finally {
            this.showLoading(false);
        }
    }
    
    async loadSystemPermissions() {
        try {
            console.log('🔍 Debug - Cargando permisos del sistema...');
            const response = await fetch('api/users/permissions-simple.php');
            const result = await response.json();
            
            console.log('🔍 Debug - Respuesta de permisos:', result);
            
            if (result.success) {
                this.systemPermissions = result.data;
                console.log('🔍 Debug - Permisos cargados:', this.systemPermissions);
                
                // Debug específico para la categoría estudios
                if (this.systemPermissions.estudios) {
                    console.log('🔍 Debug - Permisos de estudios:', this.systemPermissions.estudios);
                    console.log('🔍 Debug - Cantidad de permisos en estudios:', this.systemPermissions.estudios.length);
                } else {
                    console.warn('⚠️ Debug - No se encontró la categoría estudios');
                }
            } else {
                console.error('❌ Debug - Error en respuesta de permisos:', result.error);
            }
        } catch (error) {
            console.error('❌ Debug - Error cargando permisos del sistema:', error);
        }
    }
    
    setupEventListeners() {
        // Filtros
        document.getElementById('searchInput').addEventListener('input', (e) => {
            this.filters.search = e.target.value;
            this.applyFilters();
        });
        
        document.getElementById('levelFilter').addEventListener('change', (e) => {
            this.filters.level = e.target.value;
            this.applyFilters();
        });
        
        document.getElementById('rolFilter').addEventListener('change', (e) => {
            this.filters.rol = e.target.value;
            this.applyFilters();
        });
        
        document.getElementById('statusFilter').addEventListener('change', (e) => {
            this.filters.status = e.target.value;
            this.applyFilters();
        });
        
        // Modal events
        const userModal = document.getElementById('userModal');
        userModal.addEventListener('hidden.bs.modal', () => {
            this.resetUserForm();
            
            // Usar función global para restaurar estado del body
            restoreBodyState();
        });
    }
    
    renderUsers() {
        const filteredUsers = this.getFilteredUsers();
        const tbody = document.getElementById('usersTableBody');
        
        if (filteredUsers.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        <i class="bi bi-search me-2"></i>No se encontraron usuarios
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = filteredUsers.map(user => this.renderUserRow(user)).join('');
    }
    
    getHierarchyType(user) {
        if (user.padre_nombre) {
            return `${user.padre_nombre} ${user.padre_apellido}`;
        } else if (user.dependientes_count > 0) {
            return `Principal (${user.dependientes_count} dependiente${user.dependientes_count > 1 ? 's' : ''})`;
        } else {
            return 'Sin jerarquía';
        }
    }
    
    renderUserRow(user) {
        const levelClass = `level-${user.nivel}`;
        const lastAccess = user.ultimo_acceso ? 
            new Date(user.ultimo_acceso).toLocaleDateString() : 'Nunca';
        
        const hierarchyInfo = this.getHierarchyType(user);
        
        const permissionsHtml = this.renderUserPermissions(user.permisos);
        
        // Obtener etiqueta del rol
        const rolLabels = {
            'medico_informante': 'Médico Informante',
            'transcriptor': 'Transcriptor',
            'otro': 'Otro'
        };
        const rolLabel = user.rol ? rolLabels[user.rol] || user.rol : '';
        const rolBadge = rolLabel ? `<br><small class="badge bg-info text-white">${rolLabel}</small>` : '';
        
        return `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <i class="bi bi-person-circle fs-4 text-muted"></i>
                        </div>
                        <div>
                            <div class="fw-bold">${user.nombre} ${user.apellido}</div>
                            <small class="text-muted">${user.email}</small>
                            ${user.matricula_profesional ? `<br><small class="text-muted">${user.matricula_profesional}</small>` : ''}
                            ${rolBadge}
                        </div>
                    </div>
                </td>
                <td>
                    <span class="user-level ${levelClass}">${user.nivel.toUpperCase()}</span>
                </td>
                <td class="d-none d-md-table-cell">
                    <small>${hierarchyInfo}</small>
                    ${user.dependientes_count > 0 ? `<br><small class="text-success">${user.dependientes_count} dependiente(s)</small>` : ''}
                </td>
                <td>
                    <div class="permissions-container">
                        ${permissionsHtml}
                    </div>
                </td>
                <td class="d-none d-lg-table-cell">
                    <small>${lastAccess}</small>
                </td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-outline-primary" onclick="userManagement.editUser(${user.id})" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-outline-success" onclick="userManagement.duplicateUser(${user.id})" title="Duplicar">
                            <i class="bi bi-files"></i>
                        </button>
                        ${this.currentUser.nivel === 'root' ? `
                            <button class="btn btn-outline-info" onclick="userManagement.copyPermissionsFromUser(${user.id})" title="Copiar Permisos">
                                <i class="bi bi-shuffle"></i>
                            </button>
                        ` : ''}
                        <button class="btn btn-outline-info" onclick="userManagement.manageHierarchy(${user.id})" title="Jerarquía">
                            <i class="bi bi-diagram-3"></i>
                        </button>
                        <button class="btn btn-outline-warning" onclick="userManagement.resetPassword(${user.id})" title="Reset Password">
                            <i class="bi bi-key"></i>
                        </button>
                        ${this.canDeleteUser(user) ? `
                            <button class="btn btn-outline-danger" onclick="userManagement.deleteUser(${user.id})" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    }
    
    renderUserPermissions(permissions) {
        // Convertir permisos a array si es necesario
        let permissionsArray = permissions;
        
        if (typeof permissions === 'string') {
            try {
                permissionsArray = JSON.parse(permissions);
            } catch (e) {
                console.warn('Error parsing permissions:', e);
                permissionsArray = [];
            }
        }
        
        if (!permissionsArray || !Array.isArray(permissionsArray) || permissionsArray.length === 0) {
            return '<small class="text-muted">Sin permisos</small>';
        }
        
        // Crear badges uniformes con color verde suave
        const badges = permissionsArray.map(permission => {
            const displayText = permission.length > 12 ? permission.substring(0, 10) + '...' : permission;
            
            return `<span class="permission-badge-compact" title="${permission}">${displayText}</span>`;
        }).join('');
        
        return `
            <div class="permissions-container">
                <div class="d-flex flex-wrap">
                    ${badges}
                </div>
            </div>
        `;
    }
    
    getFilteredUsers() {
        return this.users.filter(user => {
            // Filtro de búsqueda
            if (this.filters.search) {
                const searchTerm = this.filters.search.toLowerCase();
                const searchableText = `${user.nombre} ${user.apellido} ${user.email} ${user.matricula_profesional || ''}`.toLowerCase();
                if (!searchableText.includes(searchTerm)) {
                    return false;
                }
            }
            
            // Filtro de nivel
            if (this.filters.level && user.nivel !== this.filters.level) {
                return false;
            }
            
            // Filtro de rol
            if (this.filters.rol) {
                if (this.filters.rol === 'sin_rol' && user.rol) {
                    return false;
                }
                if (this.filters.rol !== 'sin_rol' && user.rol !== this.filters.rol) {
                    return false;
                }
            }
            
            // Filtro de estado
            if (this.filters.status) {
                switch (this.filters.status) {
                    case 'with_hierarchy':
                        // Con jerarquía: tiene padre O dependientes
                        if (!user.padre_id && user.dependientes_count === 0) {
                            return false;
                        }
                        break;
                    case 'without_hierarchy':
                        // Sin jerarquía: no tiene padre NI dependientes
                        if (user.padre_id || user.dependientes_count > 0) {
                            return false;
                        }
                        break;
                    case 'principals':
                        // Principales: tienen dependientes (pueden o no tener padre)
                        if (user.dependientes_count === 0) {
                            return false;
                        }
                        break;
                    case 'dependents':
                        // Dependientes: tienen padre
                        if (!user.padre_id) {
                            return false;
                        }
                        break;
                    case 'independent':
                        // Independientes: no tienen padre NI dependientes
                        if (user.padre_id || user.dependientes_count > 0) {
                            return false;
                        }
                        break;
                }
            }
            
            return true;
        });
    }
    
    updateFilterInfo() {
        const activeFilters = [];
        
        if (this.filters.search) {
            activeFilters.push(`Búsqueda: "${this.filters.search}"`);
        }
        
        if (this.filters.level) {
            activeFilters.push(`Nivel: ${this.filters.level.toUpperCase()}`);
        }
        
        if (this.filters.status) {
            const statusLabels = {
                'with_hierarchy': 'Con Jerarquía',
                'without_hierarchy': 'Sin Jerarquía',
                'principals': 'Principales',
                'dependents': 'Dependientes',
                'independent': 'Independientes'
            };
            activeFilters.push(`Estado: ${statusLabels[this.filters.status]}`);
        }
        
        const filterInfoContainer = document.getElementById('filterInfoContainer');
        const activeFiltersInfo = document.getElementById('activeFiltersInfo');
        
        if (activeFilters.length > 0) {
            activeFiltersInfo.textContent = activeFilters.join(' • ');
            filterInfoContainer.style.display = 'block';
        } else {
            filterInfoContainer.style.display = 'none';
        }
    }
    
    applyFilters() {
        this.renderUsers();
        this.updateFilterInfo();
    }
    
    updateStats() {
        const totalUsers = this.users.length;
        const activeUsers = this.users.filter(u => u.activo).length;
        const adminUsers = this.users.filter(u => u.nivel === 'admin' || u.nivel === 'root').length;
        const usersWithoutHierarchy = this.users.filter(u => !u.padre_id && u.dependientes_count === 0).length;
        
        document.getElementById('totalUsers').textContent = totalUsers;
        document.getElementById('activeUsers').textContent = activeUsers;
        document.getElementById('adminUsers').textContent = adminUsers;
        document.getElementById('usersWithoutHierarchy').textContent = usersWithoutHierarchy;
    }
    
    toggleView(view) {
        this.currentView = view;
        
        // Actualizar botones
        document.getElementById('listViewBtn').classList.toggle('active', view === 'list');
        document.getElementById('hierarchyViewBtn').classList.toggle('active', view === 'hierarchy');
        
        // Mostrar/ocultar vistas
        document.getElementById('listView').style.display = view === 'list' ? 'block' : 'none';
        document.getElementById('hierarchyView').style.display = view === 'hierarchy' ? 'block' : 'none';
        
        if (view === 'hierarchy') {
            this.renderHierarchy();
        }
    }
    
    renderHierarchy() {
        const container = document.getElementById('hierarchyContainer');
        
        if (this.hierarchy.length === 0) {
            container.innerHTML = '<p class="text-muted text-center">No hay jerarquía disponible</p>';
            return;
        }
        
        container.innerHTML = this.hierarchy.map(root => this.renderHierarchyNode(root)).join('');
    }
    
    renderHierarchyNode(node, level = 0) {
        const levelClass = `level-${node.nivel}`;
        const indent = level * 20;
        
        let html = `
            <div class="hierarchy-item" style="margin-left: ${indent}px;">
                <div class="user-card">
                    <div class="user-header">
                        <div class="user-info">
                            <h6>${node.nombre} ${node.apellido}</h6>
                            <small class="text-muted">${node.email}</small>
                        </div>
                        <div>
                            <span class="user-level ${levelClass}">${node.nivel.toUpperCase()}</span>
                        </div>
                    </div>
                    <div class="user-actions">
                        <button class="btn btn-sm btn-outline-primary" onclick="userManagement.editUser(${node.id})">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-info" onclick="userManagement.manageHierarchy(${node.id})">
                            <i class="bi bi-diagram-3"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Renderizar hijos
        if (node.hijos && node.hijos.length > 0) {
            html += node.hijos.map(child => this.renderHierarchyNode(child, level + 1)).join('');
        }
        
        return html;
    }
    
    showCreateUserModal() {
        this.resetUserForm();
        document.getElementById('userModalTitle').textContent = 'Nuevo Usuario';
        document.getElementById('userId').value = '';
        document.getElementById('duplicateMode').value = 'false';
        
        // Hacer que la contraseña sea requerida para nuevos usuarios
        const passwordInput = document.getElementById('password');
        if (passwordInput) {
            passwordInput.required = true;
        }
        
        // Mostrar selector de cuenta base para duplicación
        const duplicateContainer = document.getElementById('duplicateBaseContainer');
        if (duplicateContainer) {
            duplicateContainer.style.display = 'block';
        }
        
        // Cargar usuarios en el selector de cuenta base
        this.loadUsersForDuplicate();
        
        // Cargar posibles padres
        this.loadPossibleParents();
        
        // Cargar permisos vacíos (sin datos DICOM)
        this.loadUserPermissions([], null);
        
        // Configurar listener para cuando se seleccione una cuenta base
        const duplicateBaseSelect = document.getElementById('duplicateBaseUser');
        if (duplicateBaseSelect) {
            // Remover listener anterior si existe
            const newSelect = duplicateBaseSelect.cloneNode(true);
            duplicateBaseSelect.parentNode.replaceChild(newSelect, duplicateBaseSelect);
            
            // Agregar nuevo listener
            document.getElementById('duplicateBaseUser').addEventListener('change', (e) => {
                this.onDuplicateBaseSelected(e.target.value);
            });
        }
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('userModal'));
        modal.show();
        
        // Configurar listener para instituciones después de que se muestre el modal
        const modalElement = document.getElementById('userModal');
        modalElement.addEventListener('shown.bs.modal', () => {
            setTimeout(() => {
                this.setupInstitucionesFieldToggle();
            }, 100);
        }, { once: true });
    }
    
    async duplicateUser(userId) {
        try {
            const baseUser = this.users.find(u => u.id == userId);
            if (!baseUser) {
                this.showAlert('Usuario no encontrado', 'error');
                return;
            }
            
            // Abrir modal en modo duplicación
            this.resetUserForm();
            document.getElementById('userModalTitle').textContent = 'Duplicar Usuario';
            document.getElementById('userId').value = '';
            document.getElementById('duplicateMode').value = 'true';
            
            // Hacer que la contraseña sea requerida para nuevos usuarios
            const passwordInput = document.getElementById('password');
            if (passwordInput) {
                passwordInput.required = true;
            }
            
            // Mostrar selector de cuenta base
            const duplicateContainer = document.getElementById('duplicateBaseContainer');
            if (duplicateContainer) {
                duplicateContainer.style.display = 'block';
            }
            
            // Cargar usuarios en el selector de cuenta base
            this.loadUsersForDuplicate();
            
            // Seleccionar el usuario actual como base por defecto
            const duplicateBaseSelect = document.getElementById('duplicateBaseUser');
            if (duplicateBaseSelect) {
                duplicateBaseSelect.value = userId;
                
                // Cargar configuración de la cuenta base
                await this.onDuplicateBaseSelected(userId);
            }
            
            // Cargar posibles padres
            await this.loadPossibleParents();
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('userModal'));
            modal.show();
            
            // Configurar listener para cuando se cambie la cuenta base
            const newSelect = duplicateBaseSelect.cloneNode(true);
            duplicateBaseSelect.parentNode.replaceChild(newSelect, duplicateBaseSelect);
            document.getElementById('duplicateBaseUser').addEventListener('change', (e) => {
                this.onDuplicateBaseSelected(e.target.value);
            });
            
            // Configurar listener para instituciones después de que se muestre el modal
            const modalElement = document.getElementById('userModal');
            modalElement.addEventListener('shown.bs.modal', () => {
                setTimeout(() => {
                    this.setupInstitucionesFieldToggle();
                }, 100);
            }, { once: true });
            
        } catch (error) {
            console.error('Error duplicando usuario:', error);
            this.showAlert('Error al duplicar usuario: ' + error.message, 'error');
        }
    }
    
    loadUsersForDuplicate() {
        const select = document.getElementById('duplicateBaseUser');
        if (!select) return;
        
        select.innerHTML = '<option value="">Seleccione una cuenta base...</option>';
        
        // Cargar todos los usuarios activos
        this.users.filter(u => u.activo).forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.nombre} ${user.apellido} (${user.nivel.toUpperCase()}) - ${user.email}`;
            select.appendChild(option);
        });
    }
    
    async onDuplicateBaseSelected(userId) {
        if (!userId) {
            // Si no hay selección, limpiar formulario
            this.resetUserForm();
            document.getElementById('duplicateMode').value = 'false';
            this.loadUserPermissions([], null);
            return;
        }
        
        const baseUser = this.users.find(u => u.id == userId);
        if (!baseUser) {
            this.showAlert('Usuario base no encontrado', 'error');
            return;
        }
        
        // Copiar configuración de la cuenta base
        // NO copiar: nombre, apellido, email, matrícula, teléfono, contraseña
        // SÍ copiar: nivel, padre_id, especialidad, permisos, instituciones_permitidas, datos DICOM
        
        // Establecer nivel
        document.getElementById('nivel').value = baseUser.nivel;
        
        // Cargar posibles padres y establecer padre_id
        await this.loadPossibleParents();
        document.getElementById('padre_id').value = baseUser.padre_id || '';
        
        // Establecer especialidad
        document.getElementById('especialidad').value = baseUser.especialidad || '';
        
        // Cargar permisos
        const dicomData = {
            dicom_aetitle: baseUser.dicom_aetitle || null,
            dicom_ip: baseUser.dicom_ip || null,
            dicom_puerto: baseUser.dicom_puerto || null,
            dicom_viewer: baseUser.dicom_viewer || 'UDV',
            study_routing_mode: baseUser.study_routing_mode || ''
        };
        this.loadUserPermissions(baseUser.permisos, dicomData);
        
        // Cargar instituciones permitidas después de un pequeño delay
        setTimeout(() => {
            const institucionesField = document.getElementById('instituciones_permitidas');
            if (institucionesField && baseUser.instituciones_permitidas) {
                try {
                    let instituciones = baseUser.instituciones_permitidas;
                    // Si es string, intentar parsearlo como JSON
                    if (typeof instituciones === 'string') {
                        instituciones = JSON.parse(instituciones);
                    }
                    // Si es array, unir con comas
                    if (Array.isArray(instituciones) && instituciones.length > 0) {
                        institucionesField.value = instituciones.join(', ');
                    } else {
                        institucionesField.value = '';
                    }
                } catch (e) {
                    console.warn('Error parseando instituciones_permitidas:', e);
                    institucionesField.value = '';
                }
            }
            
            // Configurar toggle de instituciones
            this.setupInstitucionesFieldToggle();
        }, 200);
        
        // Mostrar mensaje informativo
        this.showAlert(`Configuración copiada de: ${baseUser.nombre} ${baseUser.apellido}. Complete los datos propios de la cuenta.`, 'info');
    }
    
    async editUser(userId) {
        try {
            const user = this.users.find(u => u.id == userId);
            if (!user) {
                throw new Error('Usuario no encontrado');
            }
            
            // Verificar permisos
            if (!this.canModifyUser(user)) {
                this.showAlert('No tienes permisos para modificar este usuario', 'error');
                return;
            }
            
            this.resetUserForm();
            document.getElementById('userModalTitle').textContent = 'Editar Usuario';
            document.getElementById('duplicateMode').value = 'false';
            
            // La contraseña NO es requerida al editar
            const passwordInput = document.getElementById('password');
            if (passwordInput) {
                passwordInput.required = false;
            }
            this.setPasswordFieldsMode('edit');
            
            // Ocultar selector de cuenta base en modo edición
            const duplicateContainer = document.getElementById('duplicateBaseContainer');
            if (duplicateContainer) {
                duplicateContainer.style.display = 'none';
            }
            
            // Llenar formulario
            document.getElementById('userId').value = user.id;
            document.getElementById('nombre').value = user.nombre;
            document.getElementById('apellido').value = user.apellido;
            document.getElementById('email').value = user.email;
            document.getElementById('telefono').value = user.telefono || '';
            document.getElementById('matricula_profesional').value = user.matricula_profesional || '';
            document.getElementById('especialidad').value = user.especialidad || '';
            document.getElementById('rol').value = user.rol || '';
            document.getElementById('nivel').value = user.nivel;

            // Timeout de sesión: null → '' (usar default global), 0 → '0', N → 'N'
            const timeoutEl = document.getElementById('session_timeout_hours');
            if (timeoutEl) {
                timeoutEl.value = (user.session_timeout_hours !== null && user.session_timeout_hours !== undefined)
                    ? String(user.session_timeout_hours)
                    : '';
            }
            
            // Cargar posibles padres (simplificado)
            this.loadPossibleParentsSimple(user.id);
            document.getElementById('padre_id').value = user.padre_id || '';
            
            // Cargar permisos y datos DICOM
            const dicomData = {
                dicom_aetitle: user.dicom_aetitle || null,
                dicom_ip: user.dicom_ip || null,
                dicom_puerto: user.dicom_puerto || null,
                dicom_viewer: user.dicom_viewer || 'UDV',
                study_routing_mode: user.study_routing_mode || ''
            };
            this.loadUserPermissions(user.permisos, dicomData);
            
            // Cargar instituciones permitidas después de que se muestre el modal
            const modalElement = document.getElementById('userModal');
            const setupAfterModal = () => {
                setTimeout(() => {
                    // Configurar toggle
                    this.setupInstitucionesFieldToggle();
                    
                    // Cargar instituciones permitidas
                    const institucionesField = document.getElementById('instituciones_permitidas');
                    if (institucionesField) {
                        if (user.instituciones_permitidas) {
                            try {
                                let instituciones = user.instituciones_permitidas;
                                // Si es string, intentar parsearlo como JSON
                                if (typeof instituciones === 'string') {
                                    instituciones = JSON.parse(instituciones);
                                }
                                // Si es array, unir con comas
                                if (Array.isArray(instituciones) && instituciones.length > 0) {
                                    institucionesField.value = instituciones.join(', ');
                                } else {
                                    institucionesField.value = '';
                                }
                            } catch (e) {
                                console.warn('Error parseando instituciones_permitidas:', e);
                                institucionesField.value = '';
                            }
                        } else {
                            institucionesField.value = '';
                        }
                    }
                }, 100);
            };
            
            modalElement.addEventListener('shown.bs.modal', setupAfterModal, { once: true });
            
            // Verificar si hay un modal de jerarquía abierto
            const hierarchyModal = document.getElementById('hierarchyModal');
            const isHierarchyModalOpen = hierarchyModal && hierarchyModal.classList.contains('show');
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('userModal'));
            modal.show();
            
            // Si el modal de jerarquía está abierto, ajustar z-index
            if (isHierarchyModalOpen) {
                const userModalElement = document.getElementById('userModal');
                userModalElement.style.zIndex = '1060'; // Mayor que el modal de jerarquía (1055)
                
                // También ajustar el backdrop
                const backdrop = document.querySelector('.modal-backdrop:last-of-type');
                if (backdrop) {
                    backdrop.style.zIndex = '1059';
                }
                
                // Agregar event listener para limpiar z-index al cerrar
                userModalElement.addEventListener('hidden.bs.modal', function() {
                    userModalElement.style.zIndex = '';
                    const backdrop = document.querySelector('.modal-backdrop:last-of-type');
                    if (backdrop) {
                        backdrop.style.zIndex = '';
                    }
                    
                    // Usar función global para restaurar estado del body
                    restoreBodyState();
                }, { once: true }); // Solo una vez
            }
            
        } catch (error) {
            console.error('Error editando usuario:', error);
            this.showAlert('Error al cargar usuario: ' + error.message, 'error');
        }
    }
    
    loadPossibleParentsSimple(excludeUserId = null) {
        try {
            const select = document.getElementById('padre_id');
            select.innerHTML = '<option value="">Sin jerarquía</option>';
            
            // Usar datos de prueba directamente
            const possibleParents = [
                { id: 1, nombre: 'Root', apellido: 'Administrator', nivel: 'root' },
                { id: 2, nombre: 'Admin', apellido: 'Principal', nivel: 'admin' }
            ];
            
            possibleParents.forEach(user => {
                if (user.id != excludeUserId) {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = `${user.nombre} ${user.apellido} (${user.nivel.toUpperCase()})`;
                    select.appendChild(option);
                }
            });
        } catch (error) {
            console.error('Error cargando posibles padres:', error);
        }
    }
    
    async loadPossibleParents(excludeUserId = null) {
        try {
            const url = excludeUserId ? 
                `api/users/assignable.php?action=possible_parents&user_id=${excludeUserId}` :
                'api/users/assignable.php?action=possible_parents';
                
            const response = await fetch(url);
            const result = await response.json();
            
            const select = document.getElementById('padre_id');
            select.innerHTML = '<option value="">Sin jerarquía</option>';
            
            if (result.success) {
                result.data.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = `${user.nombre} ${user.apellido} (${user.nivel.toUpperCase()})`;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error cargando posibles padres:', error);
        }
    }
    
    loadUserPermissions(userPermissions, userDicomData = null) {
        console.log('🔍 Debug - loadUserPermissions ejecutada con:', userPermissions);
        console.log('🔍 Debug - systemPermissions disponible:', this.systemPermissions);
        console.log('🔍 Debug - userDicomData:', userDicomData);
        
        const container = document.getElementById('permissionsContainer');
        container.innerHTML = '';
        
        Object.keys(this.systemPermissions).forEach(category => {
            console.log(`🔍 Debug - Procesando categoría: ${category}`);
            
            const categoryDiv = document.createElement('div');
            categoryDiv.className = 'mb-3 permission-category';
            categoryDiv.setAttribute('data-category', category);
            categoryDiv.setAttribute('data-category-name', this.getCategoryName(category).toLowerCase());
            
            const categoryTitle = document.createElement('h6');
            categoryTitle.textContent = this.getCategoryName(category);
            categoryTitle.className = 'text-capitalize';
            categoryDiv.appendChild(categoryTitle);
            
            const permissionsDiv = document.createElement('div');
            permissionsDiv.className = 'd-flex flex-wrap gap-2';
            
            this.systemPermissions[category].forEach(permission => {
                console.log(`🔍 Debug - Procesando permiso: ${permission.permission_name} (${permission.permission_key})`);
                
                const checkbox = document.createElement('div');
                checkbox.className = 'form-check form-check-inline permission-item';
                checkbox.setAttribute('data-permission-key', permission.permission_key.toLowerCase());
                checkbox.setAttribute('data-permission-name', permission.permission_name.toLowerCase());
                checkbox.setAttribute('data-description', (permission.description || '').toLowerCase());
                
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.id = `perm_${permission.permission_key}`;
                input.value = permission.permission_key;
                input.checked = userPermissions.includes(permission.permission_key);
                
                // Deshabilitar el checkbox para "Botón Informe Visible" (gui_informe_button)
                if (permission.permission_key === 'gui_informe_button') {
                    input.disabled = true;
                    checkbox.style.opacity = '0.6';
                    checkbox.title = 'Este permiso está deshabilitado temporalmente';
                }
                
                const label = document.createElement('label');
                label.className = 'form-check-label';
                label.htmlFor = `perm_${permission.permission_key}`;
                label.textContent = permission.permission_name;
                
                checkbox.appendChild(input);
                checkbox.appendChild(label);
                permissionsDiv.appendChild(checkbox);
            });
            
            categoryDiv.appendChild(permissionsDiv);
            
            // Si es la categoría Visor, agregar selector de visor
            if (category === 'visor') {
                const viewerFieldsDiv = document.createElement('div');
                viewerFieldsDiv.className = 'mt-3';
                const srm = (userDicomData?.study_routing_mode || '').toLowerCase();
                viewerFieldsDiv.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="dicom_viewer" class="form-label">Visor DICOM (Desktop)</label>
                                <select class="form-select" id="dicom_viewer" name="dicom_viewer">
                                    <option value="UDV" ${(userDicomData?.dicom_viewer || 'UDV') === 'UDV' ? 'selected' : ''}>UDV</option>
                                    <option value="StoneViewer" ${userDicomData?.dicom_viewer === 'StoneViewer' ? 'selected' : ''}>StoneViewer</option>
                                    <option value="Oviyam" ${userDicomData?.dicom_viewer === 'Oviyam' ? 'selected' : ''}>Oviyam</option>
                                </select>
                                <div class="form-text">Selecciona el visor DICOM que se usará para ver estudios en versión Desktop</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="study_routing_mode" class="form-label">Study routing (por usuario)</label>
                                <select class="form-select" id="study_routing_mode" name="study_routing_mode">
                                    <option value="" ${!srm || srm === 'inherit' ? 'selected' : ''}>Heredar global</option>
                                    <option value="local" ${srm === 'local' ? 'selected' : ''}>Forzar PACS local</option>
                                    <option value="r2" ${srm === 'r2' ? 'selected' : ''}>Forzar R2 (requiere permiso study_routing)</option>
                                </select>
                                <div class="form-text">Solo aplica si el usuario tiene el permiso <code>study_routing</code>. La política global se define en Configuración → Study routing.</div>
                            </div>
                        </div>
                    </div>
                `;
                categoryDiv.appendChild(viewerFieldsDiv);
            }
            
            // Si es la categoría DICOM, agregar campos adicionales
            if (category === 'dicom') {
                const dicomFieldsDiv = document.createElement('div');
                dicomFieldsDiv.className = 'mt-3';
                dicomFieldsDiv.innerHTML = `
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="dicom_aetitle" class="form-label">AE Title</label>
                                <input type="text" class="form-control" id="dicom_aetitle" name="dicom_aetitle" 
                                       placeholder="Ej: CLIENTE001" maxlength="50"
                                       value="${userDicomData?.dicom_aetitle || ''}">
                                <div class="form-text">Application Entity Title para DICOM</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="dicom_ip" class="form-label">IP</label>
                                <input type="text" class="form-control" id="dicom_ip" name="dicom_ip" 
                                       placeholder="Ej: 192.168.1.100" maxlength="45"
                                       value="${userDicomData?.dicom_ip || ''}">
                                <div class="form-text">Dirección IP para conexión DICOM</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="dicom_puerto" class="form-label">Puerto</label>
                                <input type="number" class="form-control" id="dicom_puerto" name="dicom_puerto" 
                                       placeholder="Ej: 104" min="1" max="65535"
                                       value="${userDicomData?.dicom_puerto || ''}">
                                <div class="form-text">Puerto para conexión DICOM</div>
                            </div>
                        </div>
                    </div>
                `;
                categoryDiv.appendChild(dicomFieldsDiv);
            }
            
            container.appendChild(categoryDiv);
        });
        
        // Configurar toggle de instituciones después de cargar permisos
        setTimeout(() => {
            this.setupInstitucionesFieldToggle();
            this.setupPermissionsSearch();
        }, 200);
    }
    
    setupPermissionsSearch() {
        const searchInput = document.getElementById('permissionsSearch');
        const clearBtn = document.getElementById('clearPermissionsSearch');
        
        if (!searchInput) return;
        
        // Remover listener anterior si existe
        const newSearchInput = searchInput.cloneNode(true);
        searchInput.parentNode.replaceChild(newSearchInput, searchInput);
        
        // Agregar listener al nuevo input
        document.getElementById('permissionsSearch').addEventListener('input', (e) => {
            this.filterPermissions(e.target.value);
        });
        
        // Configurar botón de limpiar
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                document.getElementById('permissionsSearch').value = '';
                this.filterPermissions('');
            });
        }
    }
    
    filterPermissions(searchTerm) {
        const searchInput = document.getElementById('permissionsSearch');
        const clearBtn = document.getElementById('clearPermissionsSearch');
        const resultsText = document.getElementById('permissionsSearchResults');
        
        if (!searchInput) return;
        
        const term = searchTerm.toLowerCase().trim();
        
        // Mostrar/ocultar botón de limpiar
        if (clearBtn) {
            clearBtn.style.display = term ? 'block' : 'none';
        }
        
        // Si no hay término de búsqueda, mostrar todo
        if (!term) {
            document.querySelectorAll('.permission-category').forEach(category => {
                category.style.display = 'block';
            });
            document.querySelectorAll('.permission-item').forEach(item => {
                item.style.display = 'inline-block';
            });
            if (resultsText) {
                resultsText.textContent = '';
            }
            return;
        }
        
        // Filtrar permisos
        let visibleCount = 0;
        let visibleCategories = 0;
        
        document.querySelectorAll('.permission-category').forEach(category => {
            const categoryName = category.getAttribute('data-category-name') || '';
            let hasVisibleItems = false;
            
            category.querySelectorAll('.permission-item').forEach(item => {
                const permissionKey = item.getAttribute('data-permission-key') || '';
                const permissionName = item.getAttribute('data-permission-name') || '';
                const description = item.getAttribute('data-description') || '';
                
                const matches = permissionKey.includes(term) || 
                               permissionName.includes(term) || 
                               description.includes(term) ||
                               categoryName.includes(term);
                
                if (matches) {
                    item.style.display = 'inline-block';
                    hasVisibleItems = true;
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Mostrar categoría solo si tiene items visibles o si el término coincide con el nombre de la categoría
            if (hasVisibleItems || categoryName.includes(term)) {
                category.style.display = 'block';
                visibleCategories++;
            } else {
                category.style.display = 'none';
            }
        });
        
        // Actualizar texto de resultados
        if (resultsText) {
            if (visibleCount > 0) {
                resultsText.textContent = `${visibleCount} permiso(s) encontrado(s) en ${visibleCategories} categoría(s)`;
                resultsText.className = 'text-success';
            } else {
                resultsText.textContent = 'No se encontraron permisos que coincidan con la búsqueda';
                resultsText.className = 'text-warning';
            }
        }
    }
    
    clearPermissionsSearch() {
        const searchInput = document.getElementById('permissionsSearch');
        if (searchInput) {
            searchInput.value = '';
            this.filterPermissions('');
        }
    }
    
    setupInstitucionesFieldToggle() {
        const institucionesContainer = document.getElementById('institucionesContainer');
        const institucionesField = document.getElementById('instituciones_permitidas');
        
        if (!institucionesContainer || !institucionesField) {
            console.warn('Elementos no encontrados para toggle de instituciones:', {
                container: !!institucionesContainer,
                field: !!institucionesField
            });
            return;
        }
        
        // Función para mostrar/ocultar el campo
        const toggleInstitucionesField = (isChecked) => {
            console.log('Toggle instituciones field:', isChecked);
            if (isChecked) {
                institucionesContainer.style.display = 'block';
            } else {
                institucionesContainer.style.display = 'none';
                institucionesField.value = '';
            }
        };
        
        // Verificar estado inicial del checkbox
        const filterInstitutionsCheckbox = document.getElementById('perm_filter_institutions');
        if (filterInstitutionsCheckbox) {
            // Remover listener anterior si existe (usando una función nombrada)
            const oldHandler = filterInstitutionsCheckbox._institucionesToggleHandler;
            if (oldHandler) {
                filterInstitutionsCheckbox.removeEventListener('change', oldHandler);
            }
            
            // Crear nuevo handler
            const handler = function() {
                console.log('Checkbox filter_institutions cambiado:', this.checked);
                toggleInstitucionesField(this.checked);
            };
            
            // Guardar referencia para poder removerlo después
            filterInstitutionsCheckbox._institucionesToggleHandler = handler;
            
            // Agregar listener
            filterInstitutionsCheckbox.addEventListener('change', handler);
            
            // Aplicar estado inicial
            toggleInstitucionesField(filterInstitutionsCheckbox.checked);
        } else {
            console.warn('Checkbox perm_filter_institutions no encontrado');
        }
    }
    
    getCategoryName(category) {
        const names = {
            'general': 'Generales',
            'dashboard': 'Dashboard',
            'estudios': 'Estudios',
            'informes': 'Informes',
            'audio': 'Audio',
            'plantillas': 'Plantillas',
            'visor': 'Visor',
            'admin': 'Administración',
            'interfaz': 'Interfaz/GUI',
            'gui': 'Interfaz/GUI',
            'dicom': 'DICOM',
            'antecedentes': 'Antecedentes-Pestañas',
            'ai_informes': 'AI Informes'
        };
        return names[category] || category;
    }
    
    async saveUser(event) {
        if (event) {
            event.preventDefault();
        }
        
        try {
            console.log('💾 Iniciando guardado de usuario...');
            
            const form = document.getElementById('userForm');
            const formData = new FormData(form);
            
            // Validar contraseñas si es un nuevo usuario
            const userId = document.getElementById('userId').value;
            const isNewUser = !userId;
            
            if (isNewUser) {
                const password = formData.get('password');
                const passwordConfirm = document.getElementById('passwordConfirm').value;
                
                // Validar que se ingresó contraseña
                if (!password) {
                    this.showAlert('La contraseña es requerida para nuevos usuarios', 'error');
                    return;
                }
                
                // Validar que las contraseñas coinciden
                if (password !== passwordConfirm) {
                    this.showAlert('Las contraseñas no coinciden', 'error');
                    return;
                }
                
                // Validar longitud mínima de contraseña
                if (password.length < 6) {
                    this.showAlert('La contraseña debe tener al menos 6 caracteres', 'error');
                    return;
                }
                
                // Validar email antes de enviar
                const email = formData.get('email');
                if (email) {
                    const emailExists = this.users.some(u => 
                        u.email.toLowerCase() === email.toLowerCase().trim() && u.activo
                    );
                    
                    if (emailExists) {
                        this.showAlert(`El email ${email} ya está registrado en el sistema. Por favor, use un email diferente.`, 'error');
                        const emailInput = document.getElementById('email');
                        if (emailInput) {
                            emailInput.classList.add('is-invalid');
                            emailInput.focus();
                            setTimeout(() => {
                                emailInput.classList.remove('is-invalid');
                            }, 3000);
                        }
                        return;
                    }
                }
            } else {
                // Al editar: si se intenta cambiar la contraseña, exigir ambos campos, coincidencia y longitud
                const password = formData.get('password');
                const passwordConfirm = document.getElementById('passwordConfirm').value;
                if (password || passwordConfirm) {
                    if (!password || !passwordConfirm) {
                        this.showAlert(
                            'Para cambiar la contraseña complete ambos campos (contraseña y confirmación).',
                            'error'
                        );
                        return;
                    }
                    if (password !== passwordConfirm) {
                        this.showAlert('Las contraseñas no coinciden', 'error');
                        return;
                    }
                    if (password.length < 6) {
                        this.showAlert('La contraseña debe tener al menos 6 caracteres', 'error');
                        return;
                    }
                }
                
                // Al editar, validar que el email no esté duplicado con otro usuario
                const email = formData.get('email');
                if (email) {
                    const emailExists = this.users.some(u => 
                        u.id != userId && 
                        u.email.toLowerCase() === email.toLowerCase().trim() && 
                        u.activo
                    );
                    
                    if (emailExists) {
                        this.showAlert(`El email ${email} ya está registrado para otro usuario. Por favor, use un email diferente.`, 'error');
                        const emailInput = document.getElementById('email');
                        if (emailInput) {
                            emailInput.classList.add('is-invalid');
                            emailInput.focus();
                            setTimeout(() => {
                                emailInput.classList.remove('is-invalid');
                            }, 3000);
                        }
                        return;
                    }
                }
            }
            
            // Recopilar permisos seleccionados
            const permissions = [];
            const permissionInputs = form.querySelectorAll('input[type="checkbox"]:checked');
            permissionInputs.forEach(input => {
                permissions.push(input.value);
            });
            
            // Obtener dicom_viewer directamente del elemento select (por si FormData no lo captura)
            const dicomViewerSelect = document.getElementById('dicom_viewer');
            const dicomViewerValue = dicomViewerSelect ? dicomViewerSelect.value : (formData.get('dicom_viewer') || 'UDV');
            const studyRoutingSelect = document.getElementById('study_routing_mode');
            const studyRoutingMode = studyRoutingSelect ? studyRoutingSelect.value : (formData.get('study_routing_mode') || '');
            
            console.log('🔍 Debug - dicom_viewer:', {
                fromFormData: formData.get('dicom_viewer'),
                fromElement: dicomViewerSelect ? dicomViewerSelect.value : 'elemento no encontrado',
                finalValue: dicomViewerValue
            });
            
            // session_timeout_hours: '' → null (usar default global), '0' → 0 (sin timeout), N → N horas
            const sessionTimeoutRaw = formData.get('session_timeout_hours');
            const sessionTimeoutHours = (sessionTimeoutRaw === null || sessionTimeoutRaw === '')
                ? null
                : parseInt(sessionTimeoutRaw, 10);

            const userData = {
                nombre: formData.get('nombre'),
                apellido: formData.get('apellido'),
                email: formData.get('email'),
                telefono: formData.get('telefono'),
                matricula_profesional: formData.get('matricula_profesional'),
                especialidad: formData.get('especialidad'),
                rol: formData.get('rol') || null,
                nivel: formData.get('nivel'),
                padre_id: formData.get('padre_id') || null,
                permisos: permissions, // Enviar como array, el API lo convertirá a JSON
                session_timeout_hours: sessionTimeoutHours,
                dicom_aetitle: formData.get('dicom_aetitle') || null,
                dicom_ip: formData.get('dicom_ip') || null,
                dicom_puerto: formData.get('dicom_puerto') ? parseInt(formData.get('dicom_puerto')) : null,
                dicom_viewer: dicomViewerValue,
                study_routing_mode: studyRoutingMode || null
            };
            
            // Procesar instituciones permitidas si el permiso filter_institutions está activo
            if (permissions.includes('filter_institutions')) {
                const institucionesText = formData.get('instituciones_permitidas') || '';
                if (institucionesText.trim()) {
                    // Separar por comas, limpiar espacios y filtrar vacíos
                    const instituciones = institucionesText
                        .split(',')
                        .map(inst => inst.trim())
                        .filter(inst => inst.length > 0);
                    userData.instituciones_permitidas = JSON.stringify(instituciones);
                } else {
                    userData.instituciones_permitidas = JSON.stringify([]);
                }
            } else {
                // Si no tiene el permiso, limpiar las instituciones
                userData.instituciones_permitidas = null;
            }
            
            // Agregar contraseña si se proporciona
            const password = formData.get('password');
            if (password) {
                userData.password = password;
            }
            
            const userIdFromForm = formData.get('id');
            const isEdit = !!userIdFromForm;
            
            console.log(`📤 Enviando datos (${isEdit ? 'EDITAR' : 'CREAR'}):`, userData);
            
            this.showSaveLoading(true);
            
            const url = isEdit ? `api/users/manage-real-complete.php?id=${userIdFromForm}` : 'api/users/manage-real-complete.php';
            const method = isEdit ? 'PUT' : 'POST';
            
            console.log(`URL: ${url}, Método: ${method}`);
            
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(userData)
            });
            
            console.log('📥 Respuesta recibida:', response.status, response.statusText);
            
            const result = await response.json();
            console.log('📊 Datos parseados:', result);
            
            if (result.success) {
                console.log('✅ Guardado exitoso!');
                
                // Mostrar mensaje apropiado según si se reactivó o se creó
                if (result.data.reactivated) {
                    this.showAlert('Usuario reactivado exitosamente. Se ha actualizado la información y se ha generado una nueva contraseña.', 'success');
                } else if (isEdit && userData.password) {
                    this.showAlert(
                        (result.data.message || 'Usuario actualizado') + ' La contraseña fue cambiada.',
                        'success'
                    );
                } else {
                    this.showAlert(result.data.message || 'Usuario guardado exitosamente', 'success');
                }
                
                // Cerrar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('userModal'));
                if (modal) {
                    modal.hide();
                }
                
                // Recargar usuarios
                await this.loadUsers();
                
                // Mostrar contraseña temporal si es nuevo usuario o reactivado
                if (!isEdit && result.data.password) {
                    if (result.data.reactivated) {
                        this.showAlert(`Usuario reactivado. Nueva contraseña: ${result.data.password}`, 'info');
                    } else {
                        this.showAlert(`Usuario creado. Contraseña temporal: ${result.data.password}`, 'info');
                    }
                }
            } else {
                // Mejorar mensajes de error para el usuario
                let errorMessage = result.error || 'Error desconocido';
                
                // Detectar errores específicos y mostrar mensajes más amigables
                if (errorMessage.includes('Duplicate entry') && errorMessage.includes('email')) {
                    const emailMatch = errorMessage.match(/'([^']+)'/);
                    const email = emailMatch ? emailMatch[1] : 'este email';
                    errorMessage = `El email ${email} ya está registrado en el sistema. Por favor, use un email diferente.`;
                    
                    // Resaltar el campo de email
                    const emailInput = document.getElementById('email');
                    if (emailInput) {
                        emailInput.classList.add('is-invalid');
                        emailInput.focus();
                        
                        // Remover la clase después de 3 segundos
                        setTimeout(() => {
                            emailInput.classList.remove('is-invalid');
                        }, 3000);
                    }
                } else if (errorMessage.includes('Duplicate entry') && errorMessage.includes('matricula')) {
                    errorMessage = 'La matrícula profesional ya está registrada en el sistema. Por favor, verifique los datos.';
                    
                    // Resaltar el campo de matrícula
                    const matriculaInput = document.getElementById('matricula_profesional');
                    if (matriculaInput) {
                        matriculaInput.classList.add('is-invalid');
                        matriculaInput.focus();
                        
                        // Remover la clase después de 3 segundos
                        setTimeout(() => {
                            matriculaInput.classList.remove('is-invalid');
                        }, 3000);
                    }
                } else if (errorMessage.includes('Integrity constraint')) {
                    errorMessage = 'Error de validación: Los datos ingresados ya existen en el sistema. Por favor, verifique el email y la matrícula profesional.';
                }
                
                throw new Error(errorMessage);
            }
            
        } catch (error) {
            console.error('💥 Error guardando usuario:', error);
            this.showAlert('Error al guardar usuario: ' + error.message, 'error');
        } finally {
            this.showSaveLoading(false);
        }
    }
    
    async deleteUser(userId) {
        const user = this.users.find(u => u.id == userId);
        if (!user) {
            this.showAlert('Usuario no encontrado', 'error');
            return;
        }
        
        // Verificar permisos
        if (!this.canDeleteUser(user)) {
            this.showAlert('No tienes permisos para eliminar este usuario', 'error');
            return;
        }
        
        this.userToDelete = user;
        
        // Mostrar modal de confirmación
        const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }
    
    async confirmDelete() {
        if (!this.userToDelete) return;
        
        try {
            // Verificar si el usuario ya está inactivo antes de intentar eliminarlo
            // Esto puede pasar si se está viendo desde gestión avanzada o si el estado cambió
            if (this.userToDelete.activo == 0 || this.userToDelete.activo === 0) {
                this.showAlert('Este usuario ya está inactivo. Use la "Gestión Avanzada BD" para eliminarlo permanentemente si lo desea.', 'warning');
                
                // Cerrar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                if (modal) {
                    modal.hide();
                }
                return;
            }
            
            this.showDeleteLoading(true);
            
            const response = await fetch(`api/users/manage-real-complete.php?id=${this.userToDelete.id}`, {
                method: 'DELETE'
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showAlert(result.data.message, 'success');
                
                // Cerrar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                modal.hide();
                
                // Recargar usuarios
                await this.loadUsers();
            } else {
                // Mejorar mensaje de error
                let errorMessage = result.error || 'Error desconocido';
                if (errorMessage.includes('ya está inactivo')) {
                    errorMessage = 'Este usuario ya está inactivo. Use la "Gestión Avanzada BD" para eliminarlo permanentemente si lo desea.';
                }
                throw new Error(errorMessage);
            }
            
        } catch (error) {
            console.error('Error eliminando usuario:', error);
            this.showAlert('Error al eliminar usuario: ' + error.message, 'error');
        } finally {
            this.showDeleteLoading(false);
            this.userToDelete = null;
        }
    }
    
    async manageHierarchy(userId) {
        const user = this.users.find(u => u.id == userId);
        if (!user) {
            this.showAlert('Usuario no encontrado', 'error');
            return;
        }
        
        // Mostrar modal de gestión de jerarquía
        this.showHierarchyModal(user);
    }
    
    showHierarchyModal(user) {
        // Crear modal dinámicamente
        const modalHtml = `
            <div class="modal fade" id="hierarchyModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-diagram-3 me-2"></i>
                                Gestión de Jerarquía - ${user.nombre} ${user.apellido}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="bi bi-person me-2"></i>Información del Usuario</h6>
                                    <div class="card">
                                        <div class="card-body">
                                            <p><strong>Nombre:</strong> ${user.nombre} ${user.apellido}</p>
                                            <p><strong>Email:</strong> ${user.email}</p>
                                            <p><strong>Nivel:</strong> <span class="badge bg-${user.nivel === 'root' ? 'danger' : user.nivel === 'admin' ? 'warning' : 'info'}">${user.nivel.toUpperCase()}</span></p>
                                            <p><strong>Padre actual:</strong> ${user.padre_nombre ? `${user.padre_nombre} ${user.padre_apellido}` : 'Sin padre'}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="bi bi-people me-2"></i>Dependientes</h6>
                                    <div class="card">
                                        <div class="card-body">
                                            <p><strong>Cantidad:</strong> ${user.dependientes_count || 0} dependientes</p>
                                            ${user.dependientes_count > 0 ? '<p class="text-success"><i class="bi bi-check-circle me-1"></i>Tiene usuarios dependientes</p>' : '<p class="text-muted"><i class="bi bi-info-circle me-1"></i>No tiene dependientes</p>'}
                                            
                                            ${user.dependientes_count > 0 ? `
                                                <hr>
                                                <h6 class="text-primary">Lista de Dependientes:</h6>
                                                <div id="dependentsList" class="list-group list-group-flush">
                                                    <!-- Se cargará dinámicamente -->
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="bi bi-person-plus me-2"></i>Asignar Padre</h6>
                                    <div class="mb-3">
                                        <label class="form-label">Seleccionar nuevo padre:</label>
                                        <select class="form-select" id="newParentId">
                                            <option value="">Sin padre (usuario independiente)</option>
                                        </select>
                                        <small class="form-text text-muted">Selecciona un usuario que será el padre de este usuario</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="bi bi-diagram-3 me-2"></i>Vista de Jerarquía</h6>
                                    <div class="card">
                                        <div class="card-body">
                                            <div id="hierarchyPreview" class="text-muted">
                                                <i class="bi bi-diagram-3 me-2"></i>
                                                Vista previa de la jerarquía
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" onclick="userManagement.saveHierarchy(${user.id})">
                                <i class="bi bi-check-lg me-1"></i>Guardar Cambios
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal existente si existe y limpiar backdrop
        const existingModal = document.getElementById('hierarchyModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Limpiar backdrop de Bootstrap si existe
        const existingBackdrop = document.querySelector('.modal-backdrop');
        if (existingBackdrop) {
            existingBackdrop.remove();
        }
        
        // Remover clase modal-open del body
        document.body.classList.remove('modal-open');
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Cargar posibles padres
        this.loadPossibleParentsForHierarchy(user.id, user.padre_id);
        
        // Inicializar vista de jerarquía
        this.updateHierarchyPreview(user.id);
        
        // Cargar lista de dependientes si los tiene
        if (user.dependientes_count > 0) {
            this.loadDependentsList(user.id);
        }
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('hierarchyModal'));
        modal.show();
        
        // Agregar event listener para limpiar al cerrar
        const modalElement = document.getElementById('hierarchyModal');
        modalElement.addEventListener('hidden.bs.modal', function() {
            // Usar función global para restaurar estado del body
            restoreBodyState();
            
            // Remover modal del DOM
            modalElement.remove();
        });
        
        // Actualizar vista previa cuando cambie el padre
        document.getElementById('newParentId').addEventListener('change', () => {
            this.updateHierarchyPreview(user.id);
        });
    }
    
    loadPossibleParentsForHierarchy(excludeUserId, currentParentId = null) {
        const select = document.getElementById('newParentId');
        const possibleParents = this.users.filter(user => user.id != excludeUserId && user.activo);
        
        select.innerHTML = '<option value="">Sin padre (usuario independiente)</option>' +
            possibleParents.map(parent => 
                `<option value="${parent.id}">${parent.nombre} ${parent.apellido} (${parent.nivel.toUpperCase()})</option>`
            ).join('');
        
        // Establecer padre actual por defecto si existe
        if (currentParentId) {
            select.value = currentParentId;
        }
    }
    
    loadDependentsList(userId) {
        const dependentsList = document.getElementById('dependentsList');
        if (!dependentsList) return;
        
        // Buscar usuarios que tienen este usuario como padre
        const dependents = this.users.filter(user => user.padre_id == userId && user.activo);
        
        if (dependents.length === 0) {
            dependentsList.innerHTML = '<div class="text-muted text-center"><small>No se encontraron dependientes</small></div>';
            return;
        }
        
        dependentsList.innerHTML = dependents.map(dependent => `
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <strong>${dependent.nombre} ${dependent.apellido}</strong>
                    <br>
                    <small class="text-muted">${dependent.email}</small>
                    <br>
                    <span class="badge bg-${dependent.nivel === 'root' ? 'danger' : dependent.nivel === 'admin' ? 'warning' : 'info'}">${dependent.nivel.toUpperCase()}</span>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="userManagement.editUser(${dependent.id})" title="Editar">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-outline-info" onclick="userManagement.manageHierarchy(${dependent.id})" title="Jerarquía">
                        <i class="bi bi-diagram-3"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    updateHierarchyPreview(userId) {
        const newParentId = document.getElementById('newParentId').value;
        const preview = document.getElementById('hierarchyPreview');
        
        if (!preview) {
            console.error('Elemento hierarchyPreview no encontrado');
            return;
        }
        
        if (!newParentId) {
            preview.innerHTML = `
                <div class="hierarchy-preview">
                    <div class="parent-node text-center">
                        <i class="bi bi-person-fill me-2"></i>
                        <strong>Usuario independiente</strong>
                        <br>
                        <small class="text-muted">Sin padre asignado</small>
                    </div>
                </div>
            `;
            return;
        }
        
        const parent = this.users.find(u => u.id == newParentId);
        const currentUser = this.users.find(u => u.id == userId);
        
        if (parent && currentUser) {
            preview.innerHTML = `
                <div class="hierarchy-preview">
                    <div class="parent-node">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-person-fill me-2"></i>
                            <strong>${parent.nombre} ${parent.apellido}</strong>
                            <span class="badge bg-${parent.nivel === 'root' ? 'danger' : parent.nivel === 'admin' ? 'warning' : 'info'} ms-2">${parent.nivel.toUpperCase()}</span>
                        </div>
                        <small class="text-muted">${parent.email}</small>
                    </div>
                    
                    <div class="child-node ms-3 mt-2">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-arrow-right me-2"></i>
                            <i class="bi bi-person me-2"></i>
                            <strong>${currentUser.nombre} ${currentUser.apellido}</strong>
                            <span class="badge bg-${currentUser.nivel === 'root' ? 'danger' : currentUser.nivel === 'admin' ? 'warning' : 'info'} ms-2">${currentUser.nivel.toUpperCase()}</span>
                        </div>
                        <small class="text-muted ms-5">${currentUser.email}</small>
                    </div>
                    
                    ${currentUser.dependientes_count > 0 ? `
                        <div class="dependents-preview ms-5 mt-2">
                            <small class="text-info">
                                <i class="bi bi-people me-1"></i>
                                ${currentUser.dependientes_count} dependiente(s) también se moverán
                            </small>
                        </div>
                    ` : ''}
                </div>
            `;
        } else {
            console.error('No se encontraron padre o usuario actual');
            preview.innerHTML = `
                <div class="hierarchy-preview">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error al cargar la vista de jerarquía
                    </div>
                </div>
            `;
        }
    }
    
    async saveHierarchy(userId) {
        const newParentId = document.getElementById('newParentId').value || null;
        
        try {
            this.showAlert('Guardando cambios de jerarquía...', 'info');
            
            // Llamar a la API de jerarquía
            const response = await fetch('api/users/hierarchy.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: userId,
                    parent_id: newParentId
                })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error);
            }
            
            this.showAlert('Jerarquía actualizada exitosamente', 'success');
            
            // Cerrar modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('hierarchyModal'));
            modal.hide();
            
            // Recargar usuarios
            await this.loadUsers();
            
        } catch (error) {
            console.error('Error actualizando jerarquía:', error);
            this.showAlert('Error actualizando jerarquía: ' + error.message, 'error');
        }
    }
    
    async resetPassword(userId) {
        // Implementar reset de contraseña
        this.showAlert('Funcionalidad de reset de contraseña en desarrollo', 'info');
    }
    
    canModifyUser(user) {
        // ROOT puede modificar a todos
        if (this.currentUser.nivel === 'root') {
            return true;
        }
        
        // ADMIN no puede modificar ROOT
        if (this.currentUser.nivel === 'admin' && user.nivel === 'root') {
            return false;
        }
        
        // ADMIN no puede modificar a otro ADMIN
        if (this.currentUser.nivel === 'admin' && user.nivel === 'admin') {
            return false;
        }
        
        // ADMIN solo puede modificar usuarios nivel 'user'
        if (this.currentUser.nivel === 'admin' && user.nivel === 'user') {
            return true;
        }
        
        return false;
    }
    
    canDeleteUser(user) {
        // No se puede eliminar a sí mismo
        if (user.id == this.currentUser.id) {
            return false;
        }
        
        // No se puede eliminar si tiene dependientes
        if (user.dependientes_count > 0) {
            return false;
        }
        
        return this.canModifyUser(user);
    }
    
    resetUserForm() {
        document.getElementById('userForm').reset();
        document.getElementById('permissionsContainer').innerHTML = '';
        
        // Limpiar buscador de permisos
        const permissionsSearch = document.getElementById('permissionsSearch');
        if (permissionsSearch) {
            permissionsSearch.value = '';
        }
        const permissionsSearchResults = document.getElementById('permissionsSearchResults');
        if (permissionsSearchResults) {
            permissionsSearchResults.textContent = '';
        }
        const clearPermissionsSearchBtn = document.getElementById('clearPermissionsSearch');
        if (clearPermissionsSearchBtn) {
            clearPermissionsSearchBtn.style.display = 'none';
        }
        
        // Limpiar modo duplicación
        const duplicateMode = document.getElementById('duplicateMode');
        if (duplicateMode) {
            duplicateMode.value = 'false';
        }
        
        // Limpiar selector de cuenta base
        const duplicateBaseSelect = document.getElementById('duplicateBaseUser');
        if (duplicateBaseSelect) {
            duplicateBaseSelect.value = '';
        }
        
        // Limpiar campo de instituciones
        const institucionesField = document.getElementById('instituciones_permitidas');
        if (institucionesField) {
            institucionesField.value = '';
        }
        const institucionesContainer = document.getElementById('institucionesContainer');
        if (institucionesContainer) {
            institucionesContainer.style.display = 'none';
        }
        
        // Limpiar campos de contraseña específicamente
        const passwordInput = document.getElementById('password');
        const passwordConfirmInput = document.getElementById('passwordConfirm');
        
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.type = 'password';
        }
        
        if (passwordConfirmInput) {
            passwordConfirmInput.value = '';
            passwordConfirmInput.type = 'password';
        }
        
        // Resetear iconos de visibilidad
        const passwordToggleIcon = document.getElementById('passwordToggleIcon');
        const passwordConfirmToggleIcon = document.getElementById('passwordConfirmToggleIcon');
        
        if (passwordToggleIcon) {
            passwordToggleIcon.className = 'bi bi-eye';
        }
        
        if (passwordConfirmToggleIcon) {
            passwordConfirmToggleIcon.className = 'bi bi-eye';
        }
        
        // Ocultar mensajes de validación
        const errorMessage = document.getElementById('passwordMatchMessage');
        const successMessage = document.getElementById('passwordMatchSuccess');
        
        if (errorMessage) {
            errorMessage.style.display = 'none';
        }
        
        if (successMessage) {
            successMessage.style.display = 'none';
        }
        
        this.setPasswordFieldsMode('new');
    }
    
    /**
     * Textos y placeholders de contraseña: 'new' (alta/duplicar) o 'edit' (máscara visual, opcional cambio).
     */
    setPasswordFieldsMode(mode) {
        const passwordInput = document.getElementById('password');
        const passwordConfirmInput = document.getElementById('passwordConfirm');
        const hint1 = document.getElementById('passwordFieldHint');
        const hint2 = document.getElementById('passwordConfirmFieldHint');
        if (mode === 'edit') {
            if (passwordInput) {
                passwordInput.placeholder = '••••••••';
            }
            if (passwordConfirmInput) {
                passwordConfirmInput.placeholder = '••••••••';
            }
            if (hint1) {
                hint1.textContent =
                    'La cuenta tiene contraseña (no se muestra). Deje vacío para mantenerla; para cambiarla, escriba una nueva de al menos 6 caracteres en ambos campos.';
            }
            if (hint2) {
                hint2.textContent =
                    'Repita la nueva contraseña. Deje vacío si no cambia la contraseña.';
            }
        } else {
            if (passwordInput) {
                passwordInput.placeholder = 'Ingrese la contraseña';
            }
            if (passwordConfirmInput) {
                passwordConfirmInput.placeholder = 'Confirme la contraseña';
            }
            if (hint1) {
                hint1.textContent = 'Ingrese una contraseña segura para el nuevo usuario';
            }
            if (hint2) {
                hint2.textContent = 'Repita la contraseña para confirmar';
            }
        }
    }
    
    showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        overlay.style.display = show ? 'flex' : 'none';
    }
    
    showSaveLoading(show) {
        const btnText = document.getElementById('saveBtnText');
        const btnLoading = document.getElementById('saveBtnLoading');
        
        btnText.style.display = show ? 'none' : 'inline';
        btnLoading.style.display = show ? 'inline-block' : 'none';
    }
    
    showDeleteLoading(show) {
        const btnText = document.getElementById('deleteBtnText');
        const btnLoading = document.getElementById('deleteBtnLoading');
        
        btnText.style.display = show ? 'none' : 'inline';
        btnLoading.style.display = show ? 'inline-block' : 'none';
    }
    
    showAlert(message, type) {
        const container = document.getElementById('alertContainer');
        const alertClass = type === 'success' ? 'alert-success' : 
                          type === 'error' ? 'alert-danger' : 
                          type === 'info' ? 'alert-info' : 'alert-warning';
        
        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show alert-custom" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        container.innerHTML = alertHtml;
        
        // Auto-hide después de 5 segundos
        setTimeout(() => {
            const alert = container.querySelector('.alert');
            if (alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    }
    
    // ============================================
    // HELPERS PARA MODALES BOOTSTRAP
    // ============================================
    
    /**
     * Mostrar modal de confirmación con Bootstrap
     * @param {string} message - Mensaje a mostrar
     * @param {string} title - Título del modal (opcional)
     * @returns {Promise<boolean>} - Promise que resuelve a true si se confirma, false si se cancela
     */
    showConfirmModal(message, title = 'Confirmar') {
        return new Promise((resolve) => {
            const modal = document.getElementById('confirmModal');
            const modalTitle = document.getElementById('confirmModalTitle');
            const modalMessage = document.getElementById('confirmModalMessage');
            const okBtn = document.getElementById('confirmModalOkBtn');
            
            modalTitle.innerHTML = `<i class="bi bi-question-circle me-2"></i>${title}`;
            modalMessage.textContent = message;
            
            // Remover listeners anteriores
            const newOkBtn = okBtn.cloneNode(true);
            okBtn.parentNode.replaceChild(newOkBtn, okBtn);
            
            // Agregar listener al nuevo botón
            document.getElementById('confirmModalOkBtn').addEventListener('click', () => {
                const bsModal = bootstrap.Modal.getInstance(modal);
                bsModal.hide();
                resolve(true);
            }, { once: true });
            
            // Resolver false cuando se cierra el modal sin confirmar
            modal.addEventListener('hidden.bs.modal', () => {
                resolve(false);
            }, { once: true });
            
            // Mostrar modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        });
    }
    
    /**
     * Mostrar modal de mensaje/error con Bootstrap
     * @param {string} message - Mensaje a mostrar
     * @param {string} type - Tipo: 'info', 'success', 'warning', 'error'
     * @param {string} title - Título del modal (opcional)
     * @param {string|Array} details - Detalles adicionales (opcional)
     */
    showMessageModal(message, type = 'info', title = null, details = null) {
        const modal = document.getElementById('messageModal');
        const modalHeader = document.getElementById('messageModalHeader');
        const modalTitle = document.getElementById('messageModalTitle');
        const modalMessage = document.getElementById('messageModalMessage');
        const modalDetails = document.getElementById('messageModalDetails');
        const modalDetailsContent = document.getElementById('messageModalDetailsContent');
        
        // Configurar colores según tipo
        let headerClass = '';
        let icon = '';
        let defaultTitle = '';
        
        switch (type) {
            case 'success':
                headerClass = 'bg-success text-white';
                icon = 'bi-check-circle';
                defaultTitle = 'Éxito';
                break;
            case 'error':
            case 'danger':
                headerClass = 'bg-danger text-white';
                icon = 'bi-exclamation-triangle';
                defaultTitle = 'Error';
                break;
            case 'warning':
                headerClass = 'bg-warning text-dark';
                icon = 'bi-exclamation-triangle';
                defaultTitle = 'Advertencia';
                break;
            default:
                headerClass = 'bg-info text-white';
                icon = 'bi-info-circle';
                defaultTitle = 'Información';
        }
        
        modalHeader.className = `modal-header ${headerClass}`;
        modalTitle.innerHTML = `<i class="bi ${icon} me-2"></i>${title || defaultTitle}`;
        modalMessage.textContent = message;
        
        // Mostrar detalles si existen
        if (details) {
            const detailsText = Array.isArray(details) ? details.join('\n') : details;
            modalDetailsContent.textContent = detailsText;
            modalDetails.style.display = 'block';
        } else {
            modalDetails.style.display = 'none';
        }
        
        // Mostrar modal
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
    
    // ============================================
    // GESTIÓN AVANZADA DE BASE DE DATOS (ROOT)
    // ============================================
    
    async showDatabaseManagement() {
        // Verificar que es ROOT
        if (this.currentUser.nivel !== 'root') {
            this.showAlert('Solo usuarios ROOT pueden acceder a la gestión avanzada de base de datos', 'error');
            return;
        }
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('dbManagementModal'));
        modal.show();
        
        // Configurar listeners para filtros
        const modalElement = document.getElementById('dbManagementModal');
        modalElement.addEventListener('shown.bs.modal', () => {
            const searchInput = document.getElementById('dbSearchInput');
            const statusFilter = document.getElementById('dbStatusFilter');
            
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    this.applyDbFilters();
                });
            }
            
            if (statusFilter) {
                statusFilter.addEventListener('change', () => {
                    this.applyDbFilters();
                });
            }
        }, { once: true });
        
        // Cargar todos los usuarios (activos e inactivos)
        await this.loadAllUsers();
    }
    
    async loadAllUsers() {
        try {
            const tbody = document.getElementById('dbUsersTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </td>
                </tr>
            `;
            
            const response = await fetch('api/users/manage-real-complete.php?all=true', {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.allUsers = result.data.users || [];
                this.renderDbUsers();
            } else {
                throw new Error(result.error || 'Error desconocido');
            }
        } catch (error) {
            console.error('Error cargando todos los usuarios:', error);
            const tbody = document.getElementById('dbUsersTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Error al cargar usuarios: ${error.message}
                    </td>
                </tr>
            `;
        }
    }
    
    renderDbUsers() {
        const filteredUsers = this.getFilteredDbUsers();
        const tbody = document.getElementById('dbUsersTableBody');
        
        if (filteredUsers.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        <i class="bi bi-search me-2"></i>No se encontraron usuarios
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = filteredUsers.map(user => this.renderDbUserRow(user)).join('');
    }
    
    renderDbUserRow(user) {
        const levelClass = `level-${user.nivel}`;
        const statusBadge = user.activo == 1 
            ? '<span class="badge bg-success">Activo</span>'
            : '<span class="badge bg-danger">Inactivo</span>';
        
        const lastUpdate = user.updated_at 
            ? new Date(user.updated_at).toLocaleString('es-AR')
            : (user.created_at ? new Date(user.created_at).toLocaleString('es-AR') : 'N/A');
        
        return `
            <tr class="${user.activo == 0 ? 'table-secondary' : ''}">
                <td>${user.id}</td>
                <td>
                    <div class="fw-bold">${user.nombre} ${user.apellido}</div>
                    ${user.matricula_profesional ? `<small class="text-muted">${user.matricula_profesional}</small>` : ''}
                </td>
                <td>${user.email}</td>
                <td><span class="user-level ${levelClass}">${user.nivel.toUpperCase()}</span></td>
                <td>${statusBadge}</td>
                <td><small>${lastUpdate}</small></td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        ${user.activo == 0 ? `
                            <button class="btn btn-outline-success" onclick="userManagement.reactivateUser(${user.id})" title="Reactivar">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        ` : `
                            <button class="btn btn-outline-primary" onclick="userManagement.editUser(${user.id})" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </button>
                        `}
                        <button class="btn btn-outline-danger" onclick="userManagement.permanentDeleteUser(${user.id})" title="Eliminar Permanentemente">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }
    
    getFilteredDbUsers() {
        if (!this.allUsers) return [];
        
        const statusFilter = document.getElementById('dbStatusFilter')?.value || 'all';
        const searchTerm = (document.getElementById('dbSearchInput')?.value || '').toLowerCase();
        
        return this.allUsers.filter(user => {
            // Filtro de estado
            if (statusFilter === 'active' && user.activo != 1) return false;
            if (statusFilter === 'inactive' && user.activo == 1) return false;
            
            // Filtro de búsqueda
            if (searchTerm) {
                const searchableText = `${user.nombre} ${user.apellido} ${user.email} ${user.matricula_profesional || ''}`.toLowerCase();
                if (!searchableText.includes(searchTerm)) return false;
            }
            
            return true;
        });
    }
    
    applyDbFilters() {
        this.renderDbUsers();
    }
    
    async reactivateUser(userId) {
        try {
            const user = this.allUsers.find(u => u.id == userId);
            if (!user) {
                this.showAlert('Usuario no encontrado', 'error');
                return;
            }
            
            if (user.activo == 1) {
                this.showAlert('El usuario ya está activo', 'info');
                return;
            }
            
            // Usar modal de Bootstrap para confirmación
            const confirmed = await this.showConfirmModal(
                `¿Desea reactivar al usuario ${user.nombre} ${user.apellido} (${user.email})?`,
                'Reactivar Usuario'
            );
            
            if (!confirmed) {
                return;
            }
            
            // Llamar a la API para reactivar
            const response = await fetch(`api/users/manage-real-complete.php?id=${userId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    activo: 1
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showAlert('Usuario reactivado exitosamente', 'success');
                await this.loadAllUsers();
                // También recargar la lista normal de usuarios
                await this.loadUsers();
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            console.error('Error reactivando usuario:', error);
            this.showAlert('Error al reactivar usuario: ' + error.message, 'error');
        }
    }
    
    permanentDeleteUser(userId) {
        const user = this.allUsers.find(u => u.id == userId);
        if (!user) {
            this.showAlert('Usuario no encontrado', 'error');
            return;
        }
        
        this.userToPermanentDelete = user;
        
        // Mostrar información del usuario en el modal
        const userInfo = document.getElementById('permanentDeleteUserInfo');
        userInfo.innerHTML = `
            <div class="card">
                <div class="card-body">
                    <p><strong>ID:</strong> ${user.id}</p>
                    <p><strong>Nombre:</strong> ${user.nombre} ${user.apellido}</p>
                    <p><strong>Email:</strong> ${user.email}</p>
                    <p><strong>Nivel:</strong> ${user.nivel.toUpperCase()}</p>
                    <p><strong>Estado:</strong> ${user.activo == 1 ? 'Activo' : 'Inactivo'}</p>
                </div>
            </div>
        `;
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('permanentDeleteModal'));
        modal.show();
    }
    
    async confirmPermanentDelete() {
        if (!this.userToPermanentDelete) return;
        
        try {
            const userId = this.userToPermanentDelete.id;
            
            // Mostrar loading
            const btnText = document.getElementById('permanentDeleteBtnText');
            const btnLoading = document.getElementById('permanentDeleteBtnLoading');
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline-block';
            
            // Llamar a la API para eliminar permanentemente
            const response = await fetch(`api/users/manage-real-complete.php?id=${userId}&permanent=true`, {
                method: 'DELETE'
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showAlert('Usuario eliminado permanentemente de la base de datos', 'success');
                
                // Cerrar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('permanentDeleteModal'));
                modal.hide();
                
                // Recargar usuarios
                await this.loadAllUsers();
                await this.loadUsers();
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            console.error('Error eliminando usuario permanentemente:', error);
            this.showAlert('Error al eliminar usuario: ' + error.message, 'error');
        } finally {
            const btnText = document.getElementById('permanentDeleteBtnText');
            const btnLoading = document.getElementById('permanentDeleteBtnLoading');
            btnText.style.display = 'inline';
            btnLoading.style.display = 'none';
            this.userToPermanentDelete = null;
        }
    }
    
    // ============================================
    // COPIAR PERMISOS ENTRE CUENTAS
    // ============================================
    
    showCopyPermissionsModal() {
        // Verificar que es ROOT
        if (this.currentUser.nivel !== 'root') {
            this.showAlert('Solo usuarios ROOT pueden copiar permisos entre cuentas', 'error');
            return;
        }
        
        this.copyPermissionsSourceId = null;
        
        // Cargar usuarios en los selectores
        this.loadUsersForCopyPermissions();
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('copyPermissionsModal'));
        modal.show();
        
        // Configurar listeners
        const modalElement = document.getElementById('copyPermissionsModal');
        modalElement.addEventListener('shown.bs.modal', () => {
            const sourceSelect = document.getElementById('copyPermissionsSource');
            const targetSelect = document.getElementById('copyPermissionsTarget');
            
            if (sourceSelect) {
                sourceSelect.addEventListener('change', () => {
                    this.onCopyPermissionsSourceChanged();
                });
            }
            
            if (targetSelect) {
                targetSelect.addEventListener('change', () => {
                    this.onCopyPermissionsTargetChanged();
                });
            }
        }, { once: true });
    }
    
    copyPermissionsFromUser(userId) {
        // Verificar que es ROOT
        if (this.currentUser.nivel !== 'root') {
            this.showAlert('Solo usuarios ROOT pueden copiar permisos entre cuentas', 'error');
            return;
        }
        
        this.copyPermissionsSourceId = userId;
        
        // Cargar usuarios en los selectores
        this.loadUsersForCopyPermissions();
        
        // Pre-seleccionar el usuario origen
        const sourceSelect = document.getElementById('copyPermissionsSource');
        if (sourceSelect) {
            sourceSelect.value = userId;
            this.onCopyPermissionsSourceChanged();
        }
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('copyPermissionsModal'));
        modal.show();
        
        // Configurar listeners
        const modalElement = document.getElementById('copyPermissionsModal');
        modalElement.addEventListener('shown.bs.modal', () => {
            const targetSelect = document.getElementById('copyPermissionsTarget');
            if (targetSelect) {
                targetSelect.addEventListener('change', () => {
                    this.onCopyPermissionsTargetChanged();
                });
            }
        }, { once: true });
    }
    
    loadUsersForCopyPermissions() {
        const sourceSelect = document.getElementById('copyPermissionsSource');
        const targetSelect = document.getElementById('copyPermissionsTarget');
        
        if (!sourceSelect || !targetSelect) return;
        
        // Cargar usuarios activos para ambos selectores
        const activeUsers = this.users.filter(u => u.activo);
        
        // Llenar selector origen
        sourceSelect.innerHTML = '<option value="">Seleccione una cuenta origen...</option>';
        activeUsers.forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.nombre} ${user.apellido} (${user.email}) - ${user.nivel.toUpperCase()}`;
            sourceSelect.appendChild(option);
        });
        
        // Si hay un usuario pre-seleccionado, establecerlo
        if (this.copyPermissionsSourceId) {
            sourceSelect.value = this.copyPermissionsSourceId;
        }
        
        // Llenar selector destino (múltiple)
        targetSelect.innerHTML = '';
        activeUsers.forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.nombre} ${user.apellido} (${user.email}) - ${user.nivel.toUpperCase()}`;
            targetSelect.appendChild(option);
        });
    }
    
    onCopyPermissionsSourceChanged() {
        const sourceId = document.getElementById('copyPermissionsSource').value;
        const preview = document.getElementById('sourcePermissionsPreview');
        const permissionsList = document.getElementById('sourcePermissionsList');
        
        if (!sourceId) {
            preview.style.display = 'none';
            this.updateCopyPermissionsButton();
            return;
        }
        
        const sourceUser = this.users.find(u => u.id == sourceId);
        if (!sourceUser) {
            preview.style.display = 'none';
            return;
        }
        
        // Mostrar permisos del usuario origen
        const permissions = sourceUser.permisos || [];
        if (Array.isArray(permissions) && permissions.length > 0) {
            permissionsList.innerHTML = permissions.map(perm => 
                `<span class="badge bg-success">${perm}</span>`
            ).join('');
            preview.style.display = 'block';
        } else {
            permissionsList.innerHTML = '<span class="text-muted">Sin permisos</span>';
            preview.style.display = 'block';
        }
        
        this.updateCopyPermissionsPreview();
        this.updateCopyPermissionsButton();
    }
    
    onCopyPermissionsTargetChanged() {
        const targetSelect = document.getElementById('copyPermissionsTarget');
        const selectedCount = targetSelect.selectedOptions.length;
        document.getElementById('selectedTargetsCount').textContent = selectedCount;
        
        this.updateCopyPermissionsPreview();
        this.updateCopyPermissionsButton();
    }
    
    updateCopyPermissionsPreview() {
        const sourceId = document.getElementById('copyPermissionsSource').value;
        const targetSelect = document.getElementById('copyPermissionsTarget');
        const previewDiv = document.getElementById('copyPermissionsPreview');
        const previewContent = document.getElementById('copyPermissionsPreviewContent');
        
        if (!sourceId || !targetSelect.selectedOptions.length) {
            previewDiv.style.display = 'none';
            return;
        }
        
        const sourceUser = this.users.find(u => u.id == sourceId);
        if (!sourceUser) {
            previewDiv.style.display = 'none';
            return;
        }
        
        const selectedTargets = Array.from(targetSelect.selectedOptions).map(opt => {
            const userId = parseInt(opt.value);
            return this.users.find(u => u.id == userId);
        }).filter(u => u);
        
        const sourcePermissions = sourceUser.permisos || [];
        
        let previewHtml = `
            <p><strong>Origen:</strong> ${sourceUser.nombre} ${sourceUser.apellido} (${sourceUser.email})</p>
            <p><strong>Permisos a copiar:</strong> ${sourcePermissions.length > 0 ? sourcePermissions.join(', ') : 'Sin permisos'}</p>
            <p><strong>Destinos (${selectedTargets.length} cuenta(s)):</strong></p>
            <ul>
        `;
        
        selectedTargets.forEach(target => {
            const currentPerms = target.permisos || [];
            previewHtml += `
                <li>
                    <strong>${target.nombre} ${target.apellido}</strong> (${target.email})
                    <br>
                    <small class="text-muted">
                        Permisos actuales: ${currentPerms.length > 0 ? currentPerms.join(', ') : 'Sin permisos'}
                    </small>
                </li>
            `;
        });
        
        previewHtml += '</ul>';
        previewContent.innerHTML = previewHtml;
        previewDiv.style.display = 'block';
    }
    
    updateCopyPermissionsButton() {
        const sourceId = document.getElementById('copyPermissionsSource').value;
        const targetSelect = document.getElementById('copyPermissionsTarget');
        const copyBtn = document.getElementById('copyPermissionsBtn');
        
        if (copyBtn) {
            const hasSource = !!sourceId;
            const hasTargets = targetSelect.selectedOptions.length > 0;
            copyBtn.disabled = !(hasSource && hasTargets);
        }
    }
    
    async executeCopyPermissions() {
        const sourceId = document.getElementById('copyPermissionsSource').value;
        const targetSelect = document.getElementById('copyPermissionsTarget');
        
        if (!sourceId || !targetSelect.selectedOptions.length) {
            this.showAlert('Debe seleccionar una cuenta origen y al menos una cuenta destino', 'error');
            return;
        }
        
        const sourceUser = this.users.find(u => u.id == sourceId);
        if (!sourceUser) {
            this.showAlert('Usuario origen no encontrado', 'error');
            return;
        }
        
        const selectedTargetIds = Array.from(targetSelect.selectedOptions).map(opt => parseInt(opt.value));
        const selectedTargets = selectedTargetIds.map(id => this.users.find(u => u.id == id)).filter(u => u);
        
        if (selectedTargets.length === 0) {
            this.showAlert('No se encontraron usuarios destino válidos', 'error');
            return;
        }
        
        // Confirmar operación con modal de Bootstrap
        const confirmed = await this.showConfirmModal(
            `¿Está seguro de copiar los permisos de "${sourceUser.nombre} ${sourceUser.apellido}" a ${selectedTargets.length} cuenta(s)?\n\nEsta acción sobrescribirá los permisos actuales de las cuentas destino.`,
            'Copiar Permisos'
        );
        
        if (!confirmed) {
            return;
        }
        
        try {
            const btnText = document.getElementById('copyPermissionsBtnText');
            const btnLoading = document.getElementById('copyPermissionsBtnLoading');
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline-block';
            
            const sourcePermissions = sourceUser.permisos || [];
            let successCount = 0;
            let errorCount = 0;
            const errors = [];
            
            // Copiar permisos a cada cuenta destino
            for (const target of selectedTargets) {
                try {
                    const response = await fetch(`api/users/manage-real-complete.php?id=${target.id}`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            permisos: sourcePermissions
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        successCount++;
                    } else {
                        errorCount++;
                        errors.push(`${target.nombre} ${target.apellido}: ${result.error || 'Error desconocido'}`);
                    }
                } catch (error) {
                    errorCount++;
                    errors.push(`${target.nombre} ${target.apellido}: ${error.message}`);
                }
            }
            
            // Mostrar resultado
            if (successCount > 0) {
                this.showAlert(
                    `Permisos copiados exitosamente a ${successCount} cuenta(s)${errorCount > 0 ? `. ${errorCount} error(es).` : '.'}`,
                    errorCount > 0 ? 'warning' : 'success'
                );
                
                if (errorCount > 0 && errors.length > 0) {
                    console.error('Errores al copiar permisos:', errors);
                    // Usar modal de Bootstrap para mostrar errores
                    setTimeout(() => {
                        this.showMessageModal(
                            `Se encontraron ${errorCount} error(es) al copiar permisos a algunas cuentas.`,
                            'warning',
                            'Errores al Copiar Permisos',
                            errors
                        );
                    }, 500);
                }
                
                // Cerrar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('copyPermissionsModal'));
                modal.hide();
                
                // Recargar usuarios
                await this.loadUsers();
                if (this.allUsers && this.allUsers.length > 0) {
                    await this.loadAllUsers();
                }
            } else {
                throw new Error('No se pudo copiar permisos a ninguna cuenta. Errores: ' + errors.join('; '));
            }
            
        } catch (error) {
            console.error('Error copiando permisos:', error);
            this.showAlert('Error al copiar permisos: ' + error.message, 'error');
        } finally {
            const btnText = document.getElementById('copyPermissionsBtnText');
            const btnLoading = document.getElementById('copyPermissionsBtnLoading');
            btnText.style.display = 'inline';
            btnLoading.style.display = 'none';
        }
    }
}

// Funciones globales para compatibilidad
let userManagement;

function initializeUserManagement() {
    userManagement = new UserManagement();
    userManagement.init();
}

function showCreateUserModal() {
    userManagement.showCreateUserModal();
}

function applyFilters() {
    userManagement.applyFilters();
}

function toggleView(view) {
    userManagement.toggleView(view);
}

function saveUser() {
    userManagement.saveUser();
}

function confirmDelete() {
    userManagement.confirmDelete();
}

function handleLogout(event) {
    event.preventDefault();
    // Mostrar modal de confirmación en lugar de cerrar sesión directamente
    if (window.authManager && typeof window.authManager.showLogoutModal === 'function') {
        window.authManager.showLogoutModal();
    } else if (window.auth && typeof window.auth.showLogoutModal === 'function') {
        window.auth.showLogoutModal();
    }
}

// Funciones para manejo de contraseñas
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('passwordToggleIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.className = 'bi bi-eye-slash';
    } else {
        passwordInput.type = 'password';
        toggleIcon.className = 'bi bi-eye';
    }
}

function togglePasswordConfirmVisibility() {
    const passwordConfirmInput = document.getElementById('passwordConfirm');
    const toggleIcon = document.getElementById('passwordConfirmToggleIcon');
    
    if (passwordConfirmInput.type === 'password') {
        passwordConfirmInput.type = 'text';
        toggleIcon.className = 'bi bi-eye-slash';
    } else {
        passwordConfirmInput.type = 'password';
        toggleIcon.className = 'bi bi-eye';
    }
}

function validatePasswordMatch() {
    const password = document.getElementById('password').value;
    const passwordConfirm = document.getElementById('passwordConfirm').value;
    const errorMessage = document.getElementById('passwordMatchMessage');
    const successMessage = document.getElementById('passwordMatchSuccess');
    
    // Ocultar ambos mensajes inicialmente
    errorMessage.style.display = 'none';
    successMessage.style.display = 'none';
    
    // Solo validar si ambos campos tienen contenido
    if (password && passwordConfirm) {
        if (password === passwordConfirm) {
            successMessage.style.display = 'block';
            return true;
        } else {
            errorMessage.style.display = 'block';
            return false;
        }
    }
    
    return true; // Si no hay contenido, no mostrar error
}

// Función global para restaurar el estado del body
function restoreBodyState() {
    // Limpiar todos los backdrops residuales
    const backdrops = document.querySelectorAll('.modal-backdrop');
    backdrops.forEach(backdrop => backdrop.remove());
    
    // Restaurar estado del body
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Solo remover atributo style si no hay modales abiertos
    if (!document.querySelector('.modal.show')) {
        document.body.removeAttribute('style');
    }
    
    // Forzar reflow
    document.body.offsetHeight;
}

// Agregar event listeners para validación en tiempo real
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const passwordConfirmInput = document.getElementById('passwordConfirm');
    
    if (passwordInput && passwordConfirmInput) {
        passwordInput.addEventListener('input', validatePasswordMatch);
        passwordConfirmInput.addEventListener('input', validatePasswordMatch);
    }
});

// Verificar permisos desde JavaScript
async function checkUserPermission(permission) {
    try {
        const response = await fetch('api/users/check-permission-simple.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ permission: permission })
        });
        
        const result = await response.json();
        return result.success && result.hasPermission;
    } catch (error) {
        console.error('Error verificando permisos:', error);
        return false;
    }
}
