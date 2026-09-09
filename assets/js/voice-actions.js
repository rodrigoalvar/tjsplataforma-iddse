/**
 * Sistema de Acciones de Comandos de Voz
 * Maneja la ejecución de acciones predefinidas activadas por comandos de voz
 */

const VoiceActions = {
    // Plantillas predefinidas
    templates: {
        'historia_clinica': `HISTORIA CLÍNICA

Paciente: 
Edad: 
Sexo: 
Fecha: 

MOTIVO DE CONSULTA:


ENFERMEDAD ACTUAL:


ANTECEDENTES:
- Personales: 
- Familiares: 
- Quirúrgicos: 
- Alérgicos: 

EXAMEN FÍSICO:
- Signos vitales: 
- Examen general: 
- Examen por sistemas: 

IMPRESIÓN DIAGNÓSTICA:


PLAN:
`,
        
        'nota_evolucion': `NOTA DE EVOLUCIÓN

Fecha: 
Hora: 

EVOLUCIÓN:


EXAMEN FÍSICO:


PLAN:
`,
        
        'receta_medica': `RECETA MÉDICA

Paciente: 
Fecha: 

MEDICACIÓN:
1. 
2. 
3. 

INDICACIONES:


Próxima cita: `,
        
        'informe_radiologico': `INFORME RADIOLÓGICO

Paciente: 
Estudio: 
Fecha: 

TÉCNICA:


HALLAZGOS:


IMPRESIÓN:


Recomendaciones: `,
        
        'epicrisis': `EPICRISIS

Paciente: 
Fecha de ingreso: 
Fecha de egreso: 

DIAGNÓSTICO DE INGRESO:


DIAGNÓSTICO DE EGRESO:


RESUMEN DE HOSPITALIZACIÓN:


TRATAMIENTO RECIBIDO:


ESTADO AL EGRESO:


RECOMENDACIONES:
`
    },
    
    // Ejecutar acción
    executeAction: function(actionType, parameters = []) {
        console.log(`Ejecutando acción: ${actionType}`, parameters);
        
        try {
            switch (actionType) {
                case 'insertTemplate':
                    if (!parameters[0]) {
                        console.error('Parámetro de plantilla faltante');
                        return false;
                    }
                    return this.insertTemplate(parameters[0]);
                
                case 'saveDocument':
                    return this.saveDocument();
                
                case 'newParagraph':
                    return this.newParagraph();
                
                case 'selectAll':
                    return this.selectAll();
                
                case 'copyText':
                    return this.copyText();
                
                case 'pasteText':
                    return this.pasteText();
                
                default:
                    console.warn(`Acción no reconocida: ${actionType}`);
                    return false;
            }
        } catch (error) {
            console.error(`Error ejecutando acción ${actionType}:`, error);
            return false;
        }
    },
    
    // Insertar plantilla
    insertTemplate: function(templateName) {
        if (!templateName) {
            console.error('Nombre de plantilla no especificado');
            return false;
        }
        
        // Limpiar espacios al inicio y final del nombre
        templateName = templateName.trim();
        
        console.log(`Buscando plantilla: "${templateName}"`);
        
        // Normalizar el nombre de la plantilla
        let normalizedName = templateName.toLowerCase().replace(/\s+/g, '_');
        
        // Obtener aliases dinámicos
        const templateAliases = this.getTemplateAliases();
        
        // Verificar si hay un alias
        if (templateAliases[normalizedName]) {
            normalizedName = templateAliases[normalizedName];
            console.log(`Usando alias: ${templateName} -> ${normalizedName}`);
        }
        
        const template = this.templates[normalizedName];
        
        if (!template) {
            console.error(`Plantilla no encontrada: ${templateName}`);
            this.showAvailableTemplates();
            return false;
        }
        
        // Insertar en TinyMCE si está disponible
        if (typeof tinymce !== 'undefined') {
            const editor = tinymce.get('reportEditor') || tinymce.activeEditor;
            if (editor && editor.getBody()) {
                editor.insertContent(template.replace(/\n/g, '<br>'));
                console.log(`Plantilla "${templateName}" insertada exitosamente en TinyMCE`);
                return true;
            }
        }
        
        // Insertar en textarea si está disponible
        const textarea = document.querySelector('#transcription, textarea, #reportEditor');
        if (textarea) {
            const cursorPos = textarea.selectionStart || 0;
            const textBefore = textarea.value.substring(0, cursorPos);
            const textAfter = textarea.value.substring(textarea.selectionEnd || cursorPos);
            
            textarea.value = textBefore + template + textAfter;
            if (textarea.setSelectionRange) {
                textarea.selectionStart = textarea.selectionEnd = cursorPos + template.length;
            }
            textarea.focus();
            
            console.log(`Plantilla "${templateName}" insertada exitosamente en textarea`);
            return true;
        }
        
        // Como último recurso, intentar insertar en cualquier elemento editable
        const editableElement = document.querySelector('[contenteditable="true"], .mce-content-body');
        if (editableElement) {
            editableElement.innerHTML += template.replace(/\n/g, '<br>');
            console.log(`Plantilla "${templateName}" insertada exitosamente en elemento editable`);
            return true;
        }
        
        console.error('No se encontró editor de texto activo');
        console.log('Plantillas disponibles:', Object.keys(this.templates).join(', '));
        return false;
    },
    
    // Mostrar plantillas disponibles
    showAvailableTemplates: function() {
        const templateNames = Object.keys(this.templates).join(', ');
        console.log(`Plantillas disponibles: ${templateNames}`);
        
        // Mostrar notificación visual si está disponible
        if (typeof showNotification === 'function') {
            showNotification(`Plantillas disponibles: ${templateNames}`, 'info');
        }
    },
    
    // Guardar documento
    saveDocument: function() {
        // Intentar usar Ctrl+S
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 's',
            ctrlKey: true,
            bubbles: true
        }));
        
        console.log('Comando de guardado ejecutado');
        return true;
    },
    
    // Nuevo párrafo
    newParagraph: function() {
        const newParagraphText = '\n\n';
        
        // Insertar en TinyMCE si está disponible
        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            tinymce.activeEditor.insertContent('<br><br>');
            return true;
        }
        
        // Insertar en textarea si está disponible
        const textarea = document.querySelector('#transcription, textarea');
        if (textarea) {
            const cursorPos = textarea.selectionStart;
            const textBefore = textarea.value.substring(0, cursorPos);
            const textAfter = textarea.value.substring(textarea.selectionEnd);
            
            textarea.value = textBefore + newParagraphText + textAfter;
            textarea.selectionStart = textarea.selectionEnd = cursorPos + newParagraphText.length;
            textarea.focus();
            
            return true;
        }
        
        return false;
    },
    
    // Seleccionar todo
    selectAll: function() {
        // Intentar usar Ctrl+A
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'a',
            ctrlKey: true,
            bubbles: true
        }));
        
        // También intentar seleccionar en textarea activo
        const textarea = document.querySelector('#transcription, textarea');
        if (textarea) {
            textarea.select();
        }
        
        console.log('Seleccionar todo ejecutado');
        return true;
    },
    
    // Copiar texto
    copyText: function() {
        // Intentar usar Ctrl+C
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'c',
            ctrlKey: true,
            bubbles: true
        }));
        
        console.log('Copiar texto ejecutado');
        return true;
    },
    
    // Pegar texto
    pasteText: function() {
        // Intentar usar Ctrl+V
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'v',
            ctrlKey: true,
            bubbles: true
        }));
        
        console.log('Pegar texto ejecutado');
        return true;
    },
    
    // Agregar nueva plantilla
    addTemplate: function(name, content) {
        const normalizedName = name.toLowerCase().replace(/\s+/g, '_');
        this.templates[normalizedName] = content;
        
        // Guardar en localStorage
        localStorage.setItem('voiceActionsTemplates', JSON.stringify(this.templates));
        
        console.log(`Plantilla "${name}" agregada exitosamente`);
        return true;
    },
    
    // Eliminar plantilla
    removeTemplate: function(name) {
        const normalizedName = name.toLowerCase().replace(/\s+/g, '_');
        
        if (this.templates[normalizedName]) {
            delete this.templates[normalizedName];
            
            // Guardar en localStorage
            localStorage.setItem('voiceActionsTemplates', JSON.stringify(this.templates));
            
            console.log(`Plantilla "${name}" eliminada exitosamente`);
            return true;
        }
        
        console.error(`Plantilla "${name}" no encontrada`);
        return false;
    },
    
    // Cargar plantillas personalizadas
    loadCustomTemplates: function() {
        try {
            const saved = localStorage.getItem('customTemplates');
            if (saved) {
                const customTemplates = JSON.parse(saved);
                Object.assign(this.templates, customTemplates);
                console.log('Plantillas personalizadas cargadas');
            }
        } catch (error) {
            console.error('Error cargando plantillas personalizadas:', error);
        }
    },
    
    // Obtener aliases de plantillas
    getTemplateAliases: function() {
        try {
            const saved = localStorage.getItem('templateAliases');
            if (saved) {
                return JSON.parse(saved);
            }
        } catch (error) {
            console.error('Error cargando aliases:', error);
        }
        
        // Aliases predeterminados si no hay guardados
        return {
            'resonancia_magnetica': 'informe_radiologico',
            'resonancia_magnética': 'informe_radiologico',
            'tomografia_computada': 'informe_radiologico',
            'tomografía_computada': 'informe_radiologico',
            'radiografia': 'informe_radiologico',
            'radiografía': 'informe_radiologico',
            'rx': 'informe_radiologico',
            'tc': 'informe_radiologico',
            'rm': 'informe_radiologico',
            'historia': 'historia_clinica',
            'evolucion': 'nota_evolucion',
            'evolución': 'nota_evolucion',
            'receta': 'receta_medica',
            'informe': 'informe_radiologico'
        };
    },
    
    // Actualizar aliases de plantillas
    updateAliases: function(newAliases) {
        try {
            localStorage.setItem('templateAliases', JSON.stringify(newAliases));
            console.log('Aliases actualizados');
        } catch (error) {
            console.error('Error guardando aliases:', error);
        }
    },
    
    // Obtener todas las plantillas con sus aliases
    getTemplatesWithAliases: function() {
        const aliases = this.getTemplateAliases();
        const result = {};
        
        // Agregar plantillas principales
        for (const templateName of Object.keys(this.templates)) {
            result[templateName] = {
                name: templateName,
                content: this.templates[templateName],
                aliases: []
            };
        }
        
        // Agregar aliases
        for (const [alias, templateName] of Object.entries(aliases)) {
            if (result[templateName]) {
                result[templateName].aliases.push(alias);
            }
        }
        
        return result;
    },
    
    // Exportar configuración completa
    exportConfiguration: function() {
        return {
            templates: this.templates,
            aliases: this.getTemplateAliases(),
            timestamp: new Date().toISOString()
        };
    },
    
    // Importar configuración completa
    importConfiguration: function(config) {
        try {
            if (config.templates) {
                this.templates = { ...this.templates, ...config.templates };
                localStorage.setItem('customTemplates', JSON.stringify(this.templates));
            }
            
            if (config.aliases) {
                this.updateAliases(config.aliases);
            }
            
            console.log('Configuración importada exitosamente');
            return true;
        } catch (error) {
            console.error('Error importando configuración:', error);
            return false;
        }
     },
     
     // Inicializar sistema de acciones
    init: function() {
        this.loadCustomTemplates();
        console.log('Sistema de acciones de voz inicializado');
        console.log(`Plantillas disponibles: ${Object.keys(this.templates).join(', ')}`);
    }
};

// Inicializar cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => VoiceActions.init());
} else {
    VoiceActions.init();
}