// Configuración de comandos de voz
const VoiceCommandsConfig = {
    // Configuración por defecto de comandos
    defaultCommands: {
        // Comandos básicos de puntuación
        punctuation: {
            'punto': '.',
            'coma': ',',
            'dos puntos': ':',
            'punto y coma': ';',
            'signo de interrogación': '?',
            'signo de exclamación': '!',
            'comillas': '"',
            'paréntesis abierto': '(',
            'paréntesis cerrado': ')',
            'guión': '-',
            'guión largo': '—',
            'barra': '/',
            'porcentaje': '%',
            'grado': '°'
        },
        
        // Comandos especiales de formato
        formatting: {
            'punto aparte': '.\n\n',
            'nueva línea': '\n',
            'salto de línea': '\n',
            'párrafo nuevo': '\n\n',
            'espacio': ' ',
            'tabulación': '\t'
        },
        
        // Variaciones comunes
        variations: {
            'punto final': '.',
            'punto seguido': '. ',
            'interrogación': '?',
            'exclamación': '!',
            'abrir paréntesis': '(',
            'cerrar paréntesis': ')',
            'abrir comillas': '"',
            'cerrar comillas': '"'
        },
        
        // Comandos médicos específicos
        medical: {
            'milímetros': 'mm',
            'centímetros': 'cm',
            'metros': 'm',
            'kilogramos': 'kg',
            'gramos': 'g',
            'miligramos': 'mg',
            'litros': 'L',
            'mililitros': 'ml',
            'grados celsius': '°C',
            'por ciento': '%',
            'más menos': '±'
        },
        
        // Comandos de edición especiales
        editing: {
            'mayúscula': 'SPECIAL_UPPERCASE',
            'minúscula': 'SPECIAL_LOWERCASE',
            'borrar': 'SPECIAL_DELETE',
            'deshacer': 'SPECIAL_UNDO'
        },
        
        // Comandos de acción (ejecutan funciones)
        actions: {
            'insertar modelo': {
                type: 'action',
                pattern: /^insertar\s+modelo\s+(.+)$/i,
                action: 'insertTemplate',
                description: 'Inserta una plantilla predefinida'
            },
            'insertar plantilla': {
                type: 'action',
                pattern: /^insertar\s+plantilla\s+(.+)$/i,
                action: 'insertTemplate',
                description: 'Inserta una plantilla predefinida (alias)'
            },
            'cargar modelo': {
                type: 'action',
                pattern: /^cargar\s+modelo\s+(.+)$/i,
                action: 'insertTemplate',
                description: 'Carga una plantilla predefinida (alias)'
            },
            'guardar documento': {
                type: 'action',
                action: 'saveDocument',
                description: 'Guarda el documento actual'
            },
            'nuevo párrafo': {
                type: 'action',
                action: 'newParagraph',
                description: 'Crea un nuevo párrafo'
            },
            'seleccionar todo': {
                type: 'action',
                action: 'selectAll',
                description: 'Selecciona todo el texto'
            },
            'copiar texto': {
                type: 'action',
                action: 'copyText',
                description: 'Copia el texto seleccionado'
            },
            'pegar texto': {
                type: 'action',
                action: 'pasteText',
                description: 'Pega el texto del portapapeles'
            },
            // Comandos específicos para cada plantilla
            'insertar historia clínica': {
                type: 'action',
                action: 'insertTemplate',
                parameters: ['historia_clinica'],
                description: 'Inserta la plantilla de historia clínica'
            },
            'insertar nota de evolución': {
                type: 'action',
                action: 'insertTemplate',
                parameters: ['nota_evolucion'],
                description: 'Inserta la plantilla de nota de evolución'
            },
            'insertar receta médica': {
                type: 'action',
                action: 'insertTemplate',
                parameters: ['receta_medica'],
                description: 'Inserta la plantilla de receta médica'
            },
            'insertar informe radiológico': {
                type: 'action',
                action: 'insertTemplate',
                parameters: ['informe_radiologico'],
                description: 'Inserta la plantilla de informe radiológico'
            },
            'insertar epicrisis': {
                type: 'action',
                action: 'insertTemplate',
                parameters: ['epicrisis'],
                description: 'Inserta la plantilla de epicrisis'
            }
        }
    },
    
    // Comandos personalizados del usuario
    customCommands: {},
    
    // Comandos de acción personalizados
    customActions: {},
    
    // Cargar configuración desde localStorage
    loadConfig: function() {
        try {
            const saved = localStorage.getItem('voiceCommandsConfig');
            if (saved) {
                this.customCommands = JSON.parse(saved);
                console.log('Configuración de comandos de voz cargada');
            }
            
            const savedActions = localStorage.getItem('voiceActionsConfig');
            if (savedActions) {
                this.customActions = JSON.parse(savedActions);
                console.log('Configuración de acciones de voz cargada');
            }
        } catch (error) {
            console.error('Error al cargar configuración de comandos:', error);
            this.customCommands = {};
            this.customActions = {};
        }
    },
    
    // Guardar configuración en localStorage
    saveConfig: function() {
        try {
            localStorage.setItem('voiceCommandsConfig', JSON.stringify(this.customCommands));
            localStorage.setItem('voiceActionsConfig', JSON.stringify(this.customActions));
            console.log('Configuración de comandos de voz guardada');
            return true;
        } catch (error) {
            console.error('Error al guardar configuración de comandos:', error);
            return false;
        }
    },
    
    // Obtener todos los comandos (por defecto + personalizados)
    getAllCommands: function() {
        const allCommands = {};
        
        // Combinar todos los comandos por defecto
        Object.values(this.defaultCommands).forEach(category => {
            Object.assign(allCommands, category);
        });
        
        // Agregar comandos personalizados (sobrescriben los por defecto si hay conflicto)
        Object.assign(allCommands, this.customCommands);
        
        return allCommands;
    },
    
    // Agregar comando personalizado
    addCustomCommand: function(command, replacement, category = 'custom') {
        if (!command || replacement === undefined) {
            throw new Error('Comando y reemplazo son requeridos');
        }
        
        this.customCommands[command.toLowerCase()] = replacement;
        this.saveConfig();
        
        // Notificar cambio
        this.notifyCommandsChanged();
        
        return true;
    },
    
    // Eliminar comando personalizado
    removeCustomCommand: function(command) {
        if (this.customCommands.hasOwnProperty(command.toLowerCase())) {
            delete this.customCommands[command.toLowerCase()];
            this.saveConfig();
            
            // Notificar cambio
            this.notifyCommandsChanged();
            
            return true;
        }
        return false;
    },
    
    // Editar comando existente
    editCommand: function(oldCommand, newCommand, newReplacement) {
        if (this.customCommands.hasOwnProperty(oldCommand.toLowerCase())) {
            // Eliminar comando anterior
            delete this.customCommands[oldCommand.toLowerCase()];
            
            // Agregar comando nuevo
            this.customCommands[newCommand.toLowerCase()] = newReplacement;
            
            this.saveConfig();
            this.notifyCommandsChanged();
            
            return true;
        }
        return false;
    },
    
    // Obtener comandos por categoría
    getCommandsByCategory: function(category) {
        if (category === 'custom') {
            return this.customCommands;
        }
        return this.defaultCommands[category] || {};
    },
    
    // Resetear a comandos por defecto
    resetToDefaults: function() {
        this.customCommands = {};
        localStorage.removeItem('voiceCommandsConfig');
        this.notifyCommandsChanged();
        return true;
    },
    
    // Exportar configuración
    exportConfig: function() {
        const config = {
            customCommands: this.customCommands,
            exportDate: new Date().toISOString(),
            version: '1.0'
        };
        
        const blob = new Blob([JSON.stringify(config, null, 2)], {
            type: 'application/json'
        });
        
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'voice-commands-config.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    },
    
    // Importar configuración
    importConfig: function(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            
            reader.onload = (e) => {
                try {
                    const config = JSON.parse(e.target.result);
                    
                    if (config.customCommands) {
                        this.customCommands = config.customCommands;
                        this.saveConfig();
                        this.notifyCommandsChanged();
                        resolve(true);
                    } else {
                        reject(new Error('Formato de archivo inválido'));
                    }
                } catch (error) {
                    reject(error);
                }
            };
            
            reader.onerror = () => reject(new Error('Error al leer archivo'));
            reader.readAsText(file);
        });
    },
    
    // Obtener todos los comandos de acción (por defecto + personalizados)
    getAllActions: function() {
        const allActions = {};
        
        // Agregar comandos de acción por defecto
        Object.assign(allActions, this.defaultCommands.actions);
        
        // Agregar comandos de acción personalizados
        Object.assign(allActions, this.customActions);
        
        // Agregar comandos dinámicos para plantillas disponibles
        if (typeof VoiceActions !== 'undefined' && VoiceActions.templates) {
            for (const templateName of Object.keys(VoiceActions.templates)) {
                const commandName = `insertar ${templateName.replace(/_/g, ' ')}`;
                if (!allActions[commandName]) {
                    allActions[commandName] = {
                        type: 'action',
                        action: 'insertTemplate',
                        parameters: [templateName],
                        description: `Inserta la plantilla de ${templateName.replace(/_/g, ' ')}`
                    };
                }
            }
        }
        
        return allActions;
    },
    
    // Agregar comando de acción personalizado
    addCustomAction: function(command, actionConfig) {
        this.customActions[command] = {
            type: 'action',
            action: actionConfig.action,
            description: actionConfig.description || '',
            pattern: actionConfig.pattern || null
        };
        
        this.saveConfig();
        this.notifyCommandsChanged();
        return true;
    },
    
    // Eliminar comando de acción personalizado
    removeCustomAction: function(command) {
        if (this.customActions.hasOwnProperty(command)) {
            delete this.customActions[command];
            this.saveConfig();
            this.notifyCommandsChanged();
            return true;
        }
        return false;
    },
    
    // Procesar comando de acción
    processActionCommand: function(text) {
        const allActions = this.getAllActions();
        
        for (const [command, config] of Object.entries(allActions)) {
            if (config.pattern) {
                // Comando con patrón (ej: "insertar modelo [nombre]")
                const match = text.match(config.pattern);
                if (match) {
                    return {
                        type: 'action',
                        action: config.action,
                        parameters: match.slice(1), // Parámetros capturados
                        description: config.description
                    };
                }
            } else {
                // Comando exacto
                if (text.toLowerCase() === command.toLowerCase()) {
                    return {
                        type: 'action',
                        action: config.action,
                        parameters: config.parameters || [], // Usar parámetros predefinidos si existen
                        description: config.description
                    };
                }
            }
        }
        
        return null; // No se encontró comando de acción
    },
    
    // Notificar cambios en los comandos
    notifyCommandsChanged: function() {
        // Disparar evento personalizado
        const event = new CustomEvent('voiceCommandsChanged', {
            detail: { commands: this.getAllCommands() }
        });
        document.dispatchEvent(event);
    },
    
    // Inicializar configuración
    init: function() {
        this.loadConfig();
        console.log('VoiceCommandsConfig inicializado');
    }
};

// Inicializar cuando se carga el script
VoiceCommandsConfig.init();