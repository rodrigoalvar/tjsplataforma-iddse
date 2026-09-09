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
        this.systemPermissions = [];
    }
    
    async init() {
        try {
            await this.loadCurrentUser();
            await this.loadUsers();
            await this.loadSystemPermissions();
            this.setupEventListeners();
            this.updateStats();
        } catch (error) {
            console.error('Error inicializando gestión de usuarios:', error);
            this.showAlert('Error al cargar los datos', 'error');
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
    }
    
    async loadUsers() {
        try {
            this.showLoading(true);
            
            const response = await fetch('api/users/manage-robust.php');
            const result = await response.json();
            
            if (result.success) {
                this.users = result.data.users;
                this.hierarchy = result.data.hierarchy;
                this.renderUsers();
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            console.error('Error cargando usuarios:', error);
            this.showAlert('Error al cargar usuarios: ' + error.message, 'error');
        } finally {
            this.showLoading(false);
        }
    }
    
    async loadSystemPermissions() {
        try {
            const response = await fetch('api/users/permissions-simple.php');
            const result = await response.json();
            
            if (result.success) {
                this.systemPermissions = result.data;
                // Log para depuración
                console.log('Permisos cargados:', this.systemPermissions);
                if (this.systemPermissions.interfaz) {
                    console.log('Permisos de interfaz:', this.systemPermissions.interfaz);
                    const guiAntecedentes = this.systemPermissions.interfaz.find(p => p.permission_key === 'gui_antecedentes');
                    if (guiAntecedentes) {
                        console.log('✅ Permiso gui_antecedentes encontrado:', guiAntecedentes);
                    } else {
                        console.warn('❌ Permiso gui_antecedentes NO encontrado en permisos de interfaz');
                    }
                } else {
                    console.warn('❌ Categoría interfaz no encontrada en permisos');
                }
            }
        } catch (error) {
            console.error('Error cargando permisos del sistema:', error);
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
    
    renderUserRow(user) {
        const levelClass = `level-${user.nivel}`;
        const lastAccess = user.ultimo_acceso ? 
            new Date(user.ultimo_acceso).toLocaleDateString() : 'Nunca';
        
        const hierarchyInfo = user.padre_nombre ? 
            `${user.padre_nombre} ${user.padre_apellido}` : 'Sin jerarquía';
        
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
                <td>
                    <small>${hierarchyInfo}</small>
                    ${user.hijos_count > 0 ? `<br><small class="text-success">${user.hijos_count} hijo(s)</small>` : ''}
                </td>
                <td>
                    <div class="permissions-container">
                        ${permissionsHtml}
                    </div>
                </td>
                <td>
                    <small>${lastAccess}</small>
                </td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-outline-primary" onclick="userManagement.editUser(${user.id})" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
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
        
        return permissionsArray.map(permission => 
            `<span class="permission-badge active">${permission}</span>`
        ).join('');
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
                if (this.filters.status === 'with_hierarchy' && !user.padre_id) {
                    return false;
                }
                if (this.filters.status === 'without_hierarchy' && user.padre_id) {
                    return false;
                }
            }
            
            return true;
        });
    }
    
    applyFilters() {
        this.renderUsers();
    }
    
    updateStats() {
        const totalUsers = this.users.length;
        const activeUsers = this.users.filter(u => u.activo).length;
        const adminUsers = this.users.filter(u => u.nivel === 'admin' || u.nivel === 'root').length;
        const usersWithoutHierarchy = this.users.filter(u => !u.padre_id && u.nivel === 'user').length;
        
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
        
        // Cargar posibles padres
        this.loadPossibleParents();
        
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
            
            // Cargar posibles padres
            await this.loadPossibleParents(user.id);
            document.getElementById('padre_id').value = user.padre_id || '';
            
            // Cargar permisos
            this.loadUserPermissions(user.permisos);
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('userModal'));
            modal.show();
            
            // Configurar listener y cargar instituciones después de que se muestre el modal
            const modalElement = document.getElementById('userModal');
            const setupAfterModal = () => {
                setTimeout(() => {
                    // Configurar toggle
                    this.setupInstitucionesFieldToggle();
                    
                    // Cargar instituciones permitidas
                    const institucionesField = document.getElementById('instituciones_permitidas');
                    if (user.instituciones_permitidas && institucionesField) {
                        const instituciones = typeof user.instituciones_permitidas === 'string' 
                            ? JSON.parse(user.instituciones_permitidas) 
                            : user.instituciones_permitidas;
                        institucionesField.value = Array.isArray(instituciones) 
                            ? instituciones.join(', ') 
                            : '';
                    } else if (institucionesField) {
                        institucionesField.value = '';
                    }
                }, 100);
            };
            
            modalElement.addEventListener('shown.bs.modal', setupAfterModal, { once: true });
            
        } catch (error) {
            console.error('Error editando usuario:', error);
            this.showAlert('Error al cargar usuario: ' + error.message, 'error');
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
    
    loadUserPermissions(userPermissions) {
        const container = document.getElementById('permissionsContainer');
        container.innerHTML = '';
        
        Object.keys(this.systemPermissions).forEach(category => {
            const categoryDiv = document.createElement('div');
            categoryDiv.className = 'mb-3';
            
            const categoryTitle = document.createElement('h6');
            const categoryName = this.getCategoryName(category);
            // Log para depuración de categoría antecedentes
            if (category === 'antecedentes') {
                console.log('🔍 Renderizando categoría antecedentes:', categoryName);
            }
            categoryTitle.textContent = categoryName;
            // No usar text-capitalize para categorías que ya tienen formato específico
            if (!categoryName.includes('-') && !categoryName.includes('/')) {
                categoryTitle.className = 'text-capitalize';
            }
            categoryDiv.appendChild(categoryTitle);
            
            const permissionsDiv = document.createElement('div');
            permissionsDiv.className = 'd-flex flex-wrap gap-2';
            
            this.systemPermissions[category].forEach(permission => {
                const checkbox = document.createElement('div');
                checkbox.className = 'form-check form-check-inline';
                
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.id = `perm_${permission.permission_key}`;
                input.value = permission.permission_key;
                input.checked = userPermissions.includes(permission.permission_key);
                
                const label = document.createElement('label');
                label.className = 'form-check-label';
                label.htmlFor = `perm_${permission.permission_key}`;
                label.textContent = permission.permission_name;
                
                checkbox.appendChild(input);
                checkbox.appendChild(label);
                permissionsDiv.appendChild(checkbox);
            });
            
            categoryDiv.appendChild(permissionsDiv);
            container.appendChild(categoryDiv);
        });
        
        // Agregar listener para mostrar/ocultar campo de instituciones
        // Esperar a que el DOM esté completamente actualizado
        setTimeout(() => {
            this.setupInstitucionesFieldToggle();
        }, 200);
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
            'interfaz': 'Interfaz/GUI',
            'gui': 'Interfaz/GUI',
            'antecedentes': 'Antecedentes-Pestañas',
            'ai_informes': 'AI Informes',
            'admin': 'Administración'
        };
        const categoryName = names[category] || category;
        // Log para depuración
        if (category === 'antecedentes') {
            console.log('🔍 Categoría antecedentes mapeada a:', categoryName);
        }
        return categoryName;
    }
    
    async saveUser() {
        try {
            const form = document.getElementById('userForm');
            const formData = new FormData(form);
            
            // Recopilar permisos seleccionados
            const permissions = [];
            const permissionInputs = form.querySelectorAll('input[type="checkbox"]:checked');
            permissionInputs.forEach(input => {
                permissions.push(input.value);
            });
            
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
                permisos: permissions
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
            
            const userId = formData.get('id');
            const isEdit = !!userId;
            
            this.showSaveLoading(true);
            
            const url = isEdit ? `api/users/manage-robust.php?id=${userId}` : 'api/users/manage-robust.php';
            const method = isEdit ? 'PUT' : 'POST';
            
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(userData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showAlert(result.data.message, 'success');
                
                // Cerrar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('userModal'));
                modal.hide();
                
                // Recargar usuarios
                await this.loadUsers();
                
                // Mostrar contraseña temporal si es nuevo usuario
                if (!isEdit && result.data.password) {
                    this.showAlert(`Usuario creado. Contraseña temporal: ${result.data.password}`, 'info');
                }
            } else {
                throw new Error(result.error);
            }
            
        } catch (error) {
            console.error('Error guardando usuario:', error);
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
            this.showDeleteLoading(true);
            
            const response = await fetch(`api/users/manage-robust.php?id=${this.userToDelete.id}`, {
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
                throw new Error(result.error);
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
        // Implementar gestión de jerarquía
        this.showAlert('Funcionalidad de gestión de jerarquía en desarrollo', 'info');
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
        
        // No se puede eliminar si tiene hijos
        if (user.hijos_count > 0) {
            return false;
        }
        
        return this.canModifyUser(user);
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
    
    resetUserForm() {
        document.getElementById('userForm').reset();
        document.getElementById('permissionsContainer').innerHTML = '';
        const institucionesField = document.getElementById('instituciones_permitidas');
        if (institucionesField) {
            institucionesField.value = '';
        }
        const institucionesContainer = document.getElementById('institucionesContainer');
        if (institucionesContainer) {
            institucionesContainer.style.display = 'none';
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
