/**
 * TagManager - Gestión de tags personalizados para plantillas
 */
class TagManager {
    constructor() {
        this.predefinedTags = {
            'FECHA': {
                name: 'FECHA',
                description: 'Fecha actual del informe',
                dataSource: 'system_data',
                dataSourceValue: 'current_date',
                defaultValue: new Date().toLocaleDateString()
            },
            'NOMBRE_PACIENTE': {
                name: 'NOMBRE_PACIENTE',
                description: 'Nombre completo del paciente',
                dataSource: 'url_param',
                dataSourceValue: 'nombre',
                defaultValue: 'Paciente'
            },
            'ID_PACIENTE': {
                name: 'ID_PACIENTE',
                description: 'Identificador único del paciente',
                dataSource: 'url_param',
                dataSourceValue: 'id',
                defaultValue: '000000'
            },
            'EDAD_PACIENTE': {
                name: 'EDAD_PACIENTE',
                description: 'Edad del paciente',
                dataSource: 'url_param',
                dataSourceValue: 'edad',
                defaultValue: 'No especificado'
            },
            'SEXO_PACIENTE': {
                name: 'SEXO_PACIENTE',
                description: 'Sexo del paciente',
                dataSource: 'url_param',
                dataSourceValue: 'sexo',
                defaultValue: 'No especificado'
            },
            'MEDICO_SOLICITANTE': {
                name: 'MEDICO_SOLICITANTE',
                description: 'Médico que solicita el estudio',
                dataSource: 'url_param',
                dataSourceValue: 'medico',
                defaultValue: 'Dr. No especificado'
            },
            'FECHA_ESTUDIO': {
                name: 'FECHA_ESTUDIO',
                description: 'Fecha del estudio médico',
                dataSource: 'system_data',
                dataSourceValue: 'current_date',
                defaultValue: new Date().toLocaleDateString()
            },
            'TIPO_ESTUDIO': {
                name: 'TIPO_ESTUDIO',
                description: 'Tipo de estudio médico',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'Estudio médico'
            },
            'REGION_ANATOMICA': {
                name: 'REGION_ANATOMICA',
                description: 'Región anatómica estudiada',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'región anatómica'
            },
            'PROTOCOLO_ESTUDIO': {
                name: 'PROTOCOLO_ESTUDIO',
                description: 'Protocolo utilizado en el estudio',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'protocolo estándar'
            },
            'PROYECCIONES': {
                name: 'PROYECCIONES',
                description: 'Proyecciones radiográficas',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'AP y lateral'
            },
            'INTENSIDAD_CAMPO': {
                name: 'INTENSIDAD_CAMPO',
                description: 'Intensidad del campo magnético',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: '1.5'
            },
            'PLANOS': {
                name: 'PLANOS',
                description: 'Planos de adquisición',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'axial, coronal y sagital'
            },
            'SI_APLICA': {
                name: 'SI_APLICA',
                description: 'Indicador condicional',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'si aplica'
            },
            'OTRAS_SECUENCIAS': {
                name: 'OTRAS_SECUENCIAS',
                description: 'Otras secuencias específicas',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'secuencias adicionales'
            },
            'NOMBRE_MEDICO': {
                name: 'NOMBRE_MEDICO',
                description: 'Nombre del médico informante',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: 'Dr. [Nombre]'
            },
            'MATRICULA': {
                name: 'MATRICULA',
                description: 'Matrícula profesional del médico',
                dataSource: 'manual',
                dataSourceValue: '',
                defaultValue: '[Matrícula]'
            }
        };
        
        this.customTags = this.loadCustomTags();
        this.init();
    }
    
    init() {
        // Configurar eventos del modal de agregar tag
        const tagDataSource = document.getElementById('tagDataSource');
        if (tagDataSource) {
            tagDataSource.addEventListener('change', this.handleDataSourceChange.bind(this));
        }
        
        // Cargar tags al abrir el modal de plantillas
        const templateModal = document.getElementById('templateManagerModal');
        if (templateModal) {
            templateModal.addEventListener('shown.bs.modal', this.loadTagsUI.bind(this));
        }
    }
    
    loadCustomTags() {
        const saved = localStorage.getItem('customTags');
        return saved ? JSON.parse(saved) : {};
    }
    
    saveCustomTags() {
        localStorage.setItem('customTags', JSON.stringify(this.customTags));
    }
    
    showTagManager() {
        const modal = new bootstrap.Modal(document.getElementById('tagManagerModal'));
        modal.show();
        this.loadTagsUI();
    }

    loadTagsUI() {
        this.loadPredefinedTagsUI();
        this.loadCustomTagsUI();
    }

    loadPredefinedTagsUI() {
        const container = document.getElementById('predefinedTagsList');
        if (!container) return;

        container.innerHTML = '';
        Object.values(this.predefinedTags).forEach(tag => {
            const tagElement = document.createElement('div');
            tagElement.className = 'mb-2';
            tagElement.innerHTML = `
                <button type="button" class="btn btn-outline-primary btn-sm w-100 text-start" 
                        onclick="window.TagManager.insertTag('${tag.name}')" 
                        title="${tag.description}">
                    <i class="fas fa-tag me-2"></i>
                    ${tag.name}
                    <small class="text-muted d-block">${tag.description}</small>
                </button>
            `;
            container.appendChild(tagElement);
        });
    }

    loadCustomTagsUI() {
        const container = document.getElementById('customTagsList');
        if (!container) return;

        container.innerHTML = '';
        const customTags = this.getCustomTags();
        
        if (customTags.length === 0) {
            container.innerHTML = '<p class="text-muted text-center">No hay tags personalizados</p>';
            return;
        }

        customTags.forEach(tag => {
            const tagElement = document.createElement('div');
            tagElement.className = 'mb-2 d-flex';
            tagElement.innerHTML = `
                <button type="button" class="btn btn-outline-success btn-sm flex-grow-1 text-start me-2" 
                        onclick="window.TagManager.insertTag('${tag.name}')" 
                        title="${tag.description || 'Tag personalizado'}">
                    <i class="fas fa-user-tag me-2"></i>
                    ${tag.name}
                    ${tag.description ? `<small class="text-muted d-block">${tag.description}</small>` : ''}
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" 
                        onclick="window.TagManager.deleteCustomTag('${tag.name}')" 
                        title="Eliminar tag">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(tagElement);
        });
    }

    getCustomTags() {
        const saved = localStorage.getItem('customTags');
        return saved ? JSON.parse(saved) : [];
    }

    insertTag(tagName) {
        // Obtener el editor TinyMCE
        const editor = tinymce.get('templateContent');
        if (editor) {
            const tagText = `{{${tagName}}}`;
            editor.insertContent(tagText);
            editor.focus();
        }
        
        // Cerrar el modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('tagManagerModal'));
        if (modal) {
            modal.hide();
        }
    }

    deleteCustomTag(tagName) {
        if (confirm(`¿Estás seguro de que deseas eliminar el tag "${tagName}"?`)) {
            let customTags = this.getCustomTags();
            customTags = customTags.filter(tag => tag.name !== tagName);
            localStorage.setItem('customTags', JSON.stringify(customTags));
            this.loadCustomTagsUI();
        }
    }

    showAddTagModal() {
        // Limpiar formulario
        document.getElementById('tagForm').reset();
        document.getElementById('dataSourceConfig').style.display = 'none';
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('addTagModal'));
        modal.show();
    }
    
    handleDataSourceChange() {
        const dataSource = document.getElementById('tagDataSource').value;
        const configDiv = document.getElementById('dataSourceConfig');
        const helpText = document.getElementById('dataSourceHelp');
        const valueInput = document.getElementById('dataSourceValue');
        
        if (dataSource) {
            configDiv.style.display = 'block';
            
            switch (dataSource) {
                case 'url_param':
                    helpText.textContent = 'Nombre del parámetro en la URL (ej: edad, sexo, medico)';
                    valueInput.placeholder = 'nombre_parametro';
                    break;
                case 'user_input':
                    helpText.textContent = 'El usuario ingresará este valor manualmente';
                    valueInput.placeholder = 'No requiere configuración';
                    valueInput.disabled = true;
                    break;
                case 'system_data':
                    helpText.textContent = 'Datos del sistema (ej: current_date, current_time, user_name)';
                    valueInput.placeholder = 'current_date';
                    valueInput.disabled = false;
                    break;
                case 'calculated':
                    helpText.textContent = 'Valor calculado basado en otros datos';
                    valueInput.placeholder = 'función_calculo';
                    valueInput.disabled = false;
                    break;
            }
        } else {
            configDiv.style.display = 'none';
        }
    }
    
    saveTag() {
        const form = document.getElementById('tagForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        
        const tagName = document.getElementById('tagName').value.toUpperCase();
        const tagDescription = document.getElementById('tagDescription').value;
        const tagDataSource = document.getElementById('tagDataSource').value;
        const tagDataSourceValue = document.getElementById('dataSourceValue').value;
        const tagDefaultValue = document.getElementById('tagDefaultValue').value;
        
        // Verificar que el tag no exista ya
        const existingPredefined = this.predefinedTags.find(tag => tag.name === tagName);
        const existingCustom = this.getCustomTags().find(tag => tag.name === tagName);
        
        if (existingPredefined || existingCustom) {
            alert('Ya existe un tag con ese nombre. Por favor, elija otro nombre.');
            return;
        }
        
        // Crear el nuevo tag
        const newTag = {
            name: tagName,
            description: tagDescription,
            dataSource: tagDataSource,
            dataSourceValue: tagDataSourceValue,
            defaultValue: tagDefaultValue || 'No especificado'
        };
        
        // Guardar en tags personalizados
        const customTags = this.getCustomTags();
        customTags.push(newTag);
        localStorage.setItem('customTags', JSON.stringify(customTags));
        
        // Actualizar UI
        this.loadTagsUI();
        
        // Cerrar modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('addTagModal'));
        modal.hide();
        
        // Mostrar mensaje de éxito
        this.showSuccessMessage(`Tag "${tagName}" creado exitosamente`);
    }
    
    createTagElement(tag, isCustom) {
        const div = document.createElement('div');
        div.className = 'tag-item mb-2 p-2 border rounded';
        div.style.cursor = 'pointer';
        div.style.transition = 'all 0.2s';
        
        div.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div class="flex-grow-1" onclick="window.TagManager.insertTag('${tag.name}')">
                    <strong class="text-primary">[${tag.name}]</strong>
                    <br>
                    <small class="text-muted">${tag.description}</small>
                </div>
                ${isCustom ? `
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-warning btn-sm" onclick="window.TagManager.editTag('${tag.name}')" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="window.TagManager.deleteTag('${tag.name}')" title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                ` : ''}
            </div>
        `;
        
        // Efectos hover
        div.addEventListener('mouseenter', () => {
            div.style.backgroundColor = '#f8f9fa';
            div.style.borderColor = '#007bff';
        });
        
        div.addEventListener('mouseleave', () => {
            div.style.backgroundColor = '';
            div.style.borderColor = '';
        });
        
        return div;
    }
    
    editTag(tagName) {
        const tag = this.customTags[tagName];
        if (!tag) return;
        
        // Llenar formulario con datos del tag
        document.getElementById('tagName').value = tag.name;
        document.getElementById('tagDescription').value = tag.description;
        document.getElementById('tagDataSource').value = tag.dataSource;
        document.getElementById('dataSourceValue').value = tag.dataSourceValue || '';
        document.getElementById('tagDefaultValue').value = tag.defaultValue || '';
        
        // Deshabilitar edición del nombre
        document.getElementById('tagName').disabled = true;
        
        // Configurar fuente de datos
        this.handleDataSourceChange();
        
        // Cambiar título del modal
        document.getElementById('addTagModalLabel').innerHTML = '<i class="fas fa-edit me-2"></i>Editar Tag';
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('addTagModal'));
        modal.show();
    }
    
    deleteTag(tagName) {
        if (confirm(`¿Está seguro de que desea eliminar el tag "${tagName}"?`)) {
            delete this.customTags[tagName];
            this.saveCustomTags();
            this.loadTagsUI();
            this.showSuccessMessage(`Tag "${tagName}" eliminado exitosamente`);
        }
    }
    
    getAllTags() {
        return { ...this.predefinedTags, ...this.customTags };
    }
    
    getTagValue(tagName, urlParams = {}) {
        const allTags = this.getAllTags();
        const tag = allTags[tagName];
        
        if (!tag) {
            return `[${tagName}]`; // Retornar el tag sin procesar si no existe
        }
        
        switch (tag.dataSource) {
            case 'url_param':
                return urlParams[tag.dataSourceValue] || tag.defaultValue;
            case 'system_data':
                return this.getSystemData(tag.dataSourceValue) || tag.defaultValue;
            case 'user_input':
                return tag.defaultValue; // Por ahora retorna el valor por defecto
            case 'calculated':
                return this.calculateValue(tag.dataSourceValue, urlParams) || tag.defaultValue;
            default:
                return tag.defaultValue;
        }
    }
    
    getSystemData(dataType) {
        switch (dataType) {
            case 'current_date':
                return new Date().toLocaleDateString();
            case 'current_time':
                return new Date().toLocaleTimeString();
            case 'current_datetime':
                return new Date().toLocaleString();
            default:
                return null;
        }
    }
    
    calculateValue(calculation, urlParams) {
        // Implementar cálculos personalizados según sea necesario
        // Por ahora retorna null
        return null;
    }
    
    showSuccessMessage(message) {
        // Crear y mostrar mensaje de éxito temporal
        const alert = document.createElement('div');
        alert.className = 'alert alert-success alert-dismissible fade show position-fixed';
        alert.style.top = '20px';
        alert.style.right = '20px';
        alert.style.zIndex = '9999';
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alert);
        
        // Auto-remover después de 3 segundos
        setTimeout(() => {
            if (alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        }, 3000);
    }
}

// Instanciar TagManager globalmente
window.TagManager = new TagManager();