// Gestor de Comandos de Voz - Interfaz de Usuario
class VoiceCommandsManager {
    constructor() {
        this.currentEditingCommand = null;
        this.currentCategory = 'all';
        this.searchTerm = '';
        
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadCommands();
        this.updateStats();
        
        // Escuchar cambios en la configuración
        document.addEventListener('voiceCommandsChanged', () => {
            this.loadCommands();
            this.updateStats();
        });
    }
    
    bindEvents() {
        // Formulario de comandos
        document.getElementById('commandForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveCommand();
        });
        
        // Formulario de comandos de acción
        document.getElementById('actionCommandForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveActionCommand();
        });
        
        // Vista previa en tiempo real
        document.getElementById('commandInput').addEventListener('input', () => this.updatePreview());
        document.getElementById('replacementInput').addEventListener('input', () => this.updatePreview());
        
        // Vista previa para comandos de acción
        document.getElementById('actionCommandInput').addEventListener('input', () => this.updateActionPreview());
        document.getElementById('actionSelect').addEventListener('change', () => this.updateActionPreview());
        document.getElementById('actionDescriptionInput').addEventListener('input', () => this.updateActionPreview());
        
        // Botón cancelar
        document.getElementById('cancelBtn').addEventListener('click', () => this.cancelEdit());
        document.getElementById('cancelActionBtn').addEventListener('click', () => this.cancelActionEdit());
        
        // Filtros de categorías
        document.querySelectorAll('#categoryFilters button').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.switchCategory(e.target.dataset.category);
            });
        });
        
        // Búsqueda
        document.getElementById('searchInput').addEventListener('input', (e) => {
            this.searchTerm = e.target.value.toLowerCase();
            this.loadCommands();
        });
        
        // Acciones rápidas
        document.getElementById('exportBtn').addEventListener('click', () => this.exportConfig());
        document.getElementById('importBtn').addEventListener('click', () => this.importConfig());
        document.getElementById('resetBtn').addEventListener('click', () => this.resetConfig());
        document.getElementById('testBtn').addEventListener('click', () => this.testCommands());
        
        // Importar archivo
        document.getElementById('importFile').addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                this.handleImportFile(e.target.files[0]);
            }
        });
    }
    
    updatePreview() {
        const command = document.getElementById('commandInput').value;
        const replacement = document.getElementById('replacementInput').value;
        
        document.getElementById('previewCommand').textContent = command || '...';
        document.getElementById('previewReplacement').textContent = replacement || '...';
    }
    
    updateActionPreview() {
        const command = document.getElementById('actionCommandInput').value;
        const action = document.getElementById('actionSelect').value;
        const description = document.getElementById('actionDescriptionInput').value;
        
        document.getElementById('previewActionCommand').textContent = command || '...';
        document.getElementById('previewActionDescription').textContent = description || action || '...';
    }
    
    saveCommand() {
        const command = document.getElementById('commandInput').value.trim();
        const replacement = document.getElementById('replacementInput').value;
        
        if (!command || replacement === '') {
            this.showAlert('Por favor, completa todos los campos', 'warning');
            return;
        }
        
        try {
            if (this.currentEditingCommand) {
                // Editar comando existente
                VoiceCommandsConfig.editCommand(
                    this.currentEditingCommand,
                    command,
                    replacement
                );
                this.showAlert('Comando actualizado correctamente', 'success');
            } else {
                // Agregar nuevo comando
                VoiceCommandsConfig.addCustomCommand(command, replacement);
                this.showAlert('Comando agregado correctamente', 'success');
            }
            
            this.resetForm();
            this.loadCommands();
            this.updateStats();
            
        } catch (error) {
            this.showAlert('Error al guardar comando: ' + error.message, 'danger');
        }
    }
    
    saveActionCommand() {
        const command = document.getElementById('actionCommandInput').value.trim();
        const action = document.getElementById('actionSelect').value;
        const description = document.getElementById('actionDescriptionInput').value.trim();
        const pattern = document.getElementById('actionPatternInput').value.trim();
        
        if (!command || !action) {
            this.showAlert('Por favor completa todos los campos requeridos.', 'warning');
            return;
        }
        
        const actionConfig = {
            action: action,
            description: description || action
        };
        
        if (pattern) {
            try {
                actionConfig.pattern = new RegExp(pattern.replace(/^\/|\/$|[gi]*$/g, ''), 'i');
            } catch (e) {
                this.showAlert('El patrón de expresión regular no es válido.', 'error');
                return;
            }
        }
        
        if (this.currentEditingAction) {
            // Editar comando existente
            VoiceCommandsConfig.removeCustomAction(this.currentEditingAction);
            VoiceCommandsConfig.addCustomAction(command, actionConfig);
            this.showAlert('Comando de acción actualizado correctamente.', 'success');
        } else {
            // Agregar nuevo comando
            if (VoiceCommandsConfig.addCustomAction(command, actionConfig)) {
                this.showAlert('Comando de acción agregado correctamente.', 'success');
            } else {
                this.showAlert('Error al agregar el comando de acción.', 'error');
                return;
            }
        }
        
        this.resetActionForm();
        this.loadCommands();
        this.updateStats();
    }

    editCommand(command, replacement) {
        this.currentEditingCommand = command;
        
        document.getElementById('commandInput').value = command;
        document.getElementById('replacementInput').value = replacement;
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> Editar Comando';
        document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Actualizar Comando';
        document.getElementById('cancelBtn').style.display = 'block';
        
        this.updatePreview();
        
        // Scroll al formulario
        document.getElementById('commandForm').scrollIntoView({ behavior: 'smooth' });
    }
    
    deleteCommand(command) {
        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        document.getElementById('confirmMessage').innerHTML = 
            `¿Estás seguro de que deseas eliminar el comando "<strong>${command}</strong>"?`;
        
        document.getElementById('confirmAction').onclick = () => {
            if (VoiceCommandsConfig.removeCustomCommand(command)) {
                this.showAlert('Comando eliminado correctamente', 'success');
                this.loadCommands();
                this.updateStats();
            } else {
                this.showAlert('No se pudo eliminar el comando', 'danger');
            }
            modal.hide();
        };
        
        modal.show();
    }
    
    deleteActionCommand(command) {
        if (confirm(`¿Estás seguro de que quieres eliminar el comando de acción "${command}"?`)) {
            if (VoiceCommandsConfig.removeCustomAction(command)) {
                this.showAlert('Comando de acción eliminado correctamente.', 'success');
                this.loadCommands();
                this.updateStats();
            } else {
                this.showAlert('Error al eliminar el comando de acción.', 'error');
            }
        }
    }
    
    editActionCommand(command) {
        const allActions = VoiceCommandsConfig.getAllActions();
        const actionConfig = allActions[command] || {};
        
        this.currentEditingAction = command;
        
        document.getElementById('actionCommandInput').value = command;
        document.getElementById('actionSelect').value = actionConfig.action || '';
        document.getElementById('actionDescriptionInput').value = actionConfig.description || '';
        document.getElementById('actionPatternInput').value = actionConfig.pattern ? actionConfig.pattern.source : '';
        
        document.getElementById('actionFormTitle').innerHTML = '<i class="fas fa-edit"></i> Editar Comando de Acción';
        document.getElementById('submitActionBtn').innerHTML = '<i class="fas fa-save"></i> Actualizar Acción';
        document.getElementById('cancelActionBtn').style.display = 'block';
        
        this.updateActionPreview();
    }
    
    cancelEdit() {
        this.resetForm();
    }
    
    cancelActionEdit() {
        this.resetActionForm();
    }

    resetForm() {
        this.currentEditingCommand = null;
        document.getElementById('commandForm').reset();
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus"></i> Agregar Comando';
        document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Guardar Comando';
        document.getElementById('cancelBtn').style.display = 'none';
        this.updatePreview();
    }
    
    resetActionForm() {
        this.currentEditingAction = null;
        document.getElementById('actionCommandInput').value = '';
        document.getElementById('actionSelect').value = '';
        document.getElementById('actionDescriptionInput').value = '';
        document.getElementById('actionPatternInput').value = '';
        document.getElementById('actionFormTitle').innerHTML = '<i class="fas fa-bolt"></i> Agregar Comando de Acción';
        document.getElementById('submitActionBtn').innerHTML = '<i class="fas fa-bolt"></i> Guardar Acción';
        document.getElementById('cancelActionBtn').style.display = 'none';
        this.updateActionPreview();
    }
    
    switchCategory(category) {
        this.currentCategory = category;
        
        // Actualizar botones activos
        document.querySelectorAll('#categoryFilters button').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`#categoryFilters [data-category="${category}"]`).classList.add('active');
        
        this.loadCommands();
    }
    
    loadCommands() {
        const container = document.getElementById('commandsList');
        container.innerHTML = '';
        
        const allCommands = VoiceCommandsConfig.getAllCommands();
        const customCommands = VoiceCommandsConfig.getCommandsByCategory('custom');
        const allActions = VoiceCommandsConfig.getAllActions ? VoiceCommandsConfig.getAllActions() : {};
        
        let commandsToShow = [];
        
        // Filtrar por categoría
        if (this.currentCategory === 'all') {
            commandsToShow = Object.entries(allCommands);
            // Agregar comandos de acción a la vista 'all'
            Object.entries(allActions).forEach(([command, config]) => {
                commandsToShow.push([command, config.description || config.action]);
            });
        } else if (this.currentCategory === 'custom') {
            commandsToShow = Object.entries(customCommands);
        } else if (this.currentCategory === 'actions') {
            // Mostrar comandos de acción
            commandsToShow = Object.entries(allActions).map(([command, config]) => [
                command, 
                config.description || config.action
            ]);
        } else {
            const categoryCommands = VoiceCommandsConfig.getCommandsByCategory(this.currentCategory);
            commandsToShow = Object.entries(categoryCommands);
        }
        
        // Filtrar por búsqueda
        if (this.searchTerm) {
            commandsToShow = commandsToShow.filter(([command, replacement]) => 
                command.toLowerCase().includes(this.searchTerm) ||
                replacement.toString().toLowerCase().includes(this.searchTerm)
            );
        }
        
        // Ordenar alfabéticamente
        commandsToShow.sort(([a], [b]) => a.localeCompare(b));
        
        if (commandsToShow.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-search fa-3x mb-3"></i>
                    <p>No se encontraron comandos</p>
                </div>
            `;
            return;
        }
        
        commandsToShow.forEach(([command, replacement]) => {
            const isCustom = customCommands.hasOwnProperty(command);
            const isAction = allActions.hasOwnProperty(command);
            const commandElement = this.createCommandElement(command, replacement, isCustom, isAction);
            container.appendChild(commandElement);
        });
    }
    
    createCommandElement(command, replacement, isCustom, isAction = false) {
        const div = document.createElement('div');
        div.className = 'command-item';
        
        // Escapar caracteres especiales para mostrar
        let displayReplacement;
        if (typeof replacement === 'object' && replacement !== null) {
            displayReplacement = replacement.description || replacement.action || JSON.stringify(replacement);
        } else {
            displayReplacement = String(replacement);
        }
        displayReplacement = displayReplacement
            .replace(/\n/g, '\\n')
            .replace(/\t/g, '\\t');
        
        // Determinar el tipo de comando y su descripción
        let commandTypeLabel = '';
        let commandDescription = '';
        
        if (isAction) {
            commandTypeLabel = '<span class="badge bg-warning ms-2"><i class="fas fa-bolt me-1"></i>Acción</span>';
            commandDescription = `Ejecuta: <code>${displayReplacement}</code>`;
        } else if (isCustom) {
            commandTypeLabel = '<span class="badge bg-success ms-2">Personalizado</span>';
            commandDescription = `Reemplaza con: <code>${displayReplacement}</code>`;
        } else {
            commandTypeLabel = '<span class="badge bg-secondary ms-2">Predeterminado</span>';
            commandDescription = `Reemplaza con: <code>${displayReplacement}</code>`;
        }
        
        div.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center mb-2">
                        <strong class="text-primary">${command}</strong>
                        ${commandTypeLabel}
                    </div>
                    <div class="text-muted small">
                        ${commandDescription}
                    </div>
                </div>
                <div class="btn-group">
                    ${isAction ? `
                        <button class="btn btn-sm btn-outline-primary" onclick="voiceManager.editActionCommand('${command}')" 
                                title="Editar comando de acción">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="voiceManager.deleteActionCommand('${command}')" 
                                title="Eliminar comando de acción">
                            <i class="fas fa-trash"></i>
                        </button>
                    ` : isCustom ? `
                        <button class="btn btn-sm btn-outline-primary" onclick="voiceManager.editCommand('${command}', '${String(replacement).replace(/'/g, "\\'")}')" 
                                title="Editar comando">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="voiceManager.deleteCommand('${command}')" 
                                title="Eliminar comando">
                            <i class="fas fa-trash"></i>
                        </button>
                    ` : `
                        <button class="btn btn-sm btn-outline-secondary" disabled title="Comando predeterminado">
                            <i class="fas fa-lock"></i>
                        </button>
                    `}
                    ${!isAction ? `
                        <button class="btn btn-sm btn-outline-info" onclick="voiceManager.testSingleCommand('${command}', '${String(replacement).replace(/'/g, "\\'")}')" 
                                title="Probar comando">
                            <i class="fas fa-play"></i>
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
        
        return div;
    }
    
    updateStats() {
        const allCommands = VoiceCommandsConfig.getAllCommands();
        document.getElementById('totalCommands').textContent = Object.keys(allCommands).length;
    }
    
    exportConfig() {
        VoiceCommandsConfig.exportConfig();
        this.showAlert('Configuración exportada correctamente', 'success');
    }
    
    importConfig() {
        document.getElementById('importFile').click();
    }
    
    async handleImportFile(file) {
        try {
            await VoiceCommandsConfig.importConfig(file);
            this.showAlert('Configuración importada correctamente', 'success');
            this.loadCommands();
            this.updateStats();
        } catch (error) {
            this.showAlert('Error al importar configuración: ' + error.message, 'danger');
        }
    }
    
    resetConfig() {
        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        document.getElementById('confirmMessage').innerHTML = 
            '¿Estás seguro de que deseas restaurar todos los comandos a los valores predeterminados? <strong>Se perderán todos los comandos personalizados.</strong>';
        
        document.getElementById('confirmAction').onclick = () => {
            VoiceCommandsConfig.resetToDefaults();
            this.showAlert('Configuración restaurada a valores predeterminados', 'success');
            this.loadCommands();
            this.updateStats();
            modal.hide();
        };
        
        modal.show();
    }
    
    testCommands() {
        // Abrir el editor en una nueva ventana para probar
        window.open('editor.html', '_blank');
    }
    
    testSingleCommand(command, replacement) {
        // Mostrar una demostración del comando
        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        document.getElementById('confirmMessage').innerHTML = `
            <div class="text-center">
                <h5>Prueba de Comando</h5>
                <p>Comando: <strong>"${command}"</strong></p>
                <p>Resultado: <code>${replacement.toString().replace(/\n/g, '\\n').replace(/\t/g, '\\t')}</code></p>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Para probar este comando, ve al editor y usa el dictado por voz.
                </div>
            </div>
        `;
        
        document.getElementById('confirmAction').textContent = 'Ir al Editor';
        document.getElementById('confirmAction').className = 'btn btn-primary';
        document.getElementById('confirmAction').onclick = () => {
            window.open('editor.html', '_blank');
            modal.hide();
        };
        
        modal.show();
    }
    
    showAlert(message, type) {
        // Crear alerta temporal
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alertDiv);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
}

// Inicializar el gestor cuando se carga la página
let voiceManager;
document.addEventListener('DOMContentLoaded', () => {
    voiceManager = new VoiceCommandsManager();
});