/**
 * Transcription Highlight Config Component
 * Componente reutilizable para renderizar y gestionar configuración de resaltado
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Uso:
 * - En configuracion.html (sección completa)
 * - En modal/offcanvas de configuración rápida
 */

const TranscriptionHighlightConfig = {
    /**
     * Renderizar campos de configuración
     * @param {Object} config - Configuración actual
     * @param {string} containerId - ID del contenedor donde renderizar
     * @param {Object} options - Opciones adicionales
     */
    render: function(config, containerId, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.error(`Contenedor ${containerId} no encontrado`);
            return;
        }

        // Limpiar contenedor
        container.innerHTML = '';

        // Opciones
        const showTitle = options.showTitle !== false;
        const title = options.title || 'Resaltado de Transcripción';
        const compactMode = options.compactMode || false;

        // Título de sección
        if (showTitle) {
            const titleElement = document.createElement('h5');
            titleElement.className = compactMode ? 'mb-3' : 'mb-3';
            titleElement.style.cssText = compactMode 
                ? 'color: #ff9800; border-bottom: 2px solid #ff9800; padding-bottom: 10px; font-size: 1.1rem;'
                : 'color: #ff9800; border-bottom: 2px solid #ff9800; padding-bottom: 10px;';
            titleElement.innerHTML = `<i class="fas fa-highlighter me-2"></i>${title}`;
            container.appendChild(titleElement);
        }

        // Campo Throttle
        this.renderThrottleField(container, config, compactMode);

        // Campo Offset de Audio
        this.renderOffsetField(container, config, compactMode);

        // Campo Comportamiento de Scroll
        this.renderScrollBehaviorField(container, config, compactMode);

        // Campo Activar Scroll Automático
        this.renderAutoScrollField(container, config, compactMode);

        // Campo Color de Resaltado Activo
        this.renderActiveColorField(container, config, compactMode);

        // Campo Fondo de Resaltado Activo
        this.renderActiveBgField(container, config, compactMode);

        // Campo Color Hover
        this.renderHoverColorField(container, config, compactMode);

        // Campo Fondo Hover
        this.renderHoverBgField(container, config, compactMode);

        // Campo Peso de Fuente
        this.renderFontWeightField(container, config, compactMode);

        // Campo Duración de Transición
        this.renderTransitionDurationField(container, config, compactMode);

        // Campo Tipo de UI Config
        this.renderConfigUITypeField(container, config, compactMode);

        // Campo Activar Preview
        this.renderEnablePreviewField(container, config, compactMode);

        // Configurar sincronización de inputs de color
        this.setupColorInputSync();
    },

    /**
     * Renderizar campo Throttle
     */
    renderThrottleField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const throttleValue = config.transcription_highlight_throttle_ms || 30;
        field.innerHTML = `
            <label class="config-label">
                Throttle de Actualización (ms)
                <small class="text-muted d-block">Tiempo mínimo entre actualizaciones del resaltado (10-100ms). Valores más bajos = más fluido pero más carga. Recomendado: 30ms</small>
            </label>
            <input type="number" class="form-control config-input" id="transcription_highlight_throttle_ms" 
                   value="${throttleValue}" min="10" max="100" step="5" placeholder="30">
            <small class="text-muted d-block mt-1">
                <strong>10-20ms:</strong> Muy fluido (puede ser pesado en dispositivos lentos)<br>
                <strong>30ms:</strong> Balanceado (recomendado)<br>
                <strong>50-100ms:</strong> Más conservador (mejor para dispositivos lentos)
            </small>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Offset de Audio
     */
    renderOffsetField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const offsetValue = config.transcription_highlight_audio_offset || -0.1;
        field.innerHTML = `
            <label class="config-label">
                Offset de Audio (segundos)
                <small class="text-muted d-block">Compensación de delay entre el audio y el resaltado (-0.5 a 0.5). Valores negativos = reproducir antes, positivos = después</small>
            </label>
            <input type="number" class="form-control config-input" id="transcription_highlight_audio_offset" 
                   value="${offsetValue}" min="-0.5" max="0.5" step="0.05" placeholder="-0.1">
            <small class="text-muted d-block mt-1">
                <strong>Recomendado:</strong> -0.1 (100ms antes) para compensar delay de reproducción
            </small>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Comportamiento de Scroll
     */
    renderScrollBehaviorField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const scrollBehaviorValue = config.transcription_highlight_scroll_behavior || 'smooth';
        field.innerHTML = `
            <label class="config-label">
                Comportamiento de Scroll
                <small class="text-muted d-block">Cómo se desplaza automáticamente a la palabra activa</small>
            </label>
            <select class="form-control config-input" id="transcription_highlight_scroll_behavior">
                <option value="smooth" ${scrollBehaviorValue === 'smooth' ? 'selected' : ''}>Suave (smooth)</option>
                <option value="auto" ${scrollBehaviorValue === 'auto' ? 'selected' : ''}>Automático (auto)</option>
                <option value="instant" ${scrollBehaviorValue === 'instant' ? 'selected' : ''}>Instantáneo (instant)</option>
            </select>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Activar Scroll Automático
     */
    renderAutoScrollField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const autoScrollValue = config.transcription_highlight_enable_auto_scroll !== false;
        field.innerHTML = `
            <label class="config-label d-flex justify-content-between align-items-center">
                <span>
                    Activar Scroll Automático
                    <small class="text-muted d-block">Desplazarse automáticamente a la palabra activa cuando está fuera de la vista</small>
                </span>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="transcription_highlight_enable_auto_scroll" ${autoScrollValue ? 'checked' : ''}>
                </div>
            </label>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Color de Resaltado Activo
     */
    renderActiveColorField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const activeColorValue = config.transcription_highlight_active_color || '#ffeb3b';
        field.innerHTML = `
            <label class="config-label">
                Color de Resaltado Activo
                <small class="text-muted d-block">Color del texto cuando la palabra está activa</small>
            </label>
            <div class="input-group">
                <input type="color" class="form-control form-control-color" id="transcription_highlight_active_color" 
                       value="${activeColorValue}" title="Elige color">
                <input type="text" class="form-control config-input" id="transcription_highlight_active_color_text" 
                       value="${activeColorValue}" placeholder="#ffeb3b" pattern="#[0-9A-Fa-f]{6}">
            </div>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Fondo de Resaltado Activo
     */
    renderActiveBgField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const activeBgValue = config.transcription_highlight_active_bg || '#ffeb3b';
        field.innerHTML = `
            <label class="config-label">
                Fondo de Resaltado Activo
                <small class="text-muted d-block">Color de fondo cuando la palabra está activa</small>
            </label>
            <div class="input-group">
                <input type="color" class="form-control form-control-color" id="transcription_highlight_active_bg" 
                       value="${activeBgValue}" title="Elige color">
                <input type="text" class="form-control config-input" id="transcription_highlight_active_bg_text" 
                       value="${activeBgValue}" placeholder="#ffeb3b" pattern="#[0-9A-Fa-f]{6}">
            </div>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Color Hover
     */
    renderHoverColorField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const hoverColorValue = config.transcription_highlight_hover_color || '#1976d2';
        field.innerHTML = `
            <label class="config-label">
                Color Hover
                <small class="text-muted d-block">Color del texto al pasar el mouse sobre palabras clicables</small>
            </label>
            <div class="input-group">
                <input type="color" class="form-control form-control-color" id="transcription_highlight_hover_color" 
                       value="${hoverColorValue}" title="Elige color">
                <input type="text" class="form-control config-input" id="transcription_highlight_hover_color_text" 
                       value="${hoverColorValue}" placeholder="#1976d2" pattern="#[0-9A-Fa-f]{6}">
            </div>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Fondo Hover
     */
    renderHoverBgField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const hoverBgValue = config.transcription_highlight_hover_bg || '#e3f2fd';
        field.innerHTML = `
            <label class="config-label">
                Fondo Hover
                <small class="text-muted d-block">Color de fondo al pasar el mouse sobre palabras clicables</small>
            </label>
            <div class="input-group">
                <input type="color" class="form-control form-control-color" id="transcription_highlight_hover_bg" 
                       value="${hoverBgValue}" title="Elige color">
                <input type="text" class="form-control config-input" id="transcription_highlight_hover_bg_text" 
                       value="${hoverBgValue}" placeholder="#e3f2fd" pattern="#[0-9A-Fa-f]{6}">
            </div>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Peso de Fuente
     */
    renderFontWeightField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const fontWeightValue = config.transcription_highlight_font_weight || 600;
        field.innerHTML = `
            <label class="config-label">
                Peso de Fuente
                <small class="text-muted d-block">Peso de la fuente para palabras activas (400-900)</small>
            </label>
            <input type="number" class="form-control config-input" id="transcription_highlight_font_weight" 
                   value="${fontWeightValue}" min="400" max="900" step="100" placeholder="600">
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Duración de Transición
     */
    renderTransitionDurationField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const transitionValue = config.transcription_highlight_transition_duration || 0.2;
        field.innerHTML = `
            <label class="config-label">
                Duración de Transición (segundos)
                <small class="text-muted d-block">Duración de la animación de cambio de resaltado (0-1s)</small>
            </label>
            <input type="number" class="form-control config-input" id="transcription_highlight_transition_duration" 
                   value="${transitionValue}" min="0" max="1" step="0.1" placeholder="0.2">
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Tipo de UI Config
     */
    renderConfigUITypeField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const uiTypeValue = config.transcription_highlight_config_ui_type || 'modal';
        field.innerHTML = `
            <label class="config-label">
                Tipo de UI para Configuración Rápida
                <small class="text-muted d-block">Tipo de interfaz para el panel de configuración rápida en el modal de transcripción</small>
            </label>
            <select class="form-control config-input" id="transcription_highlight_config_ui_type">
                <option value="modal" ${uiTypeValue === 'modal' ? 'selected' : ''}>Modal</option>
                <option value="offcanvas" ${uiTypeValue === 'offcanvas' ? 'selected' : ''}>Offcanvas (Panel lateral)</option>
            </select>
            <small class="text-muted d-block mt-1">
                <strong>Modal:</strong> Ventana emergente centrada<br>
                <strong>Offcanvas:</strong> Panel lateral deslizable (más ligero)
            </small>
        `;
        container.appendChild(field);
    },

    /**
     * Renderizar campo Activar Preview
     */
    renderEnablePreviewField: function(container, config, compactMode) {
        const field = document.createElement('div');
        field.className = 'config-item';
        const previewValue = config.transcription_highlight_enable_preview !== false;
        field.innerHTML = `
            <label class="config-label d-flex justify-content-between align-items-center">
                <span>
                    Activar Preview en Tiempo Real
                    <small class="text-muted d-block">Permitir ver cambios de configuración antes de guardar (útil para probar colores y parámetros)</small>
                </span>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="transcription_highlight_enable_preview" ${previewValue ? 'checked' : ''}>
                </div>
            </label>
        `;
        container.appendChild(field);
    },

    /**
     * Configurar sincronización de inputs de color
     */
    setupColorInputSync: function() {
        const colorFields = [
            'active_color', 'active_bg', 'hover_color', 'hover_bg'
        ];

        colorFields.forEach(suffix => {
            const colorInput = document.getElementById(`transcription_highlight_${suffix}`);
            const textInput = document.getElementById(`transcription_highlight_${suffix}_text`);
            
            if (colorInput && textInput) {
                colorInput.addEventListener('input', () => {
                    textInput.value = colorInput.value;
                });
                
                textInput.addEventListener('input', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(textInput.value)) {
                        colorInput.value = textInput.value;
                    }
                });
            }
        });
    },

    /**
     * Obtener valores de configuración desde el formulario
     * @returns {Object} Objeto con los valores de configuración
     */
    getValues: function() {
        return {
            transcription_highlight_throttle_ms: parseInt(
                document.getElementById('transcription_highlight_throttle_ms')?.value || '30'
            ),
            transcription_highlight_audio_offset: parseFloat(
                document.getElementById('transcription_highlight_audio_offset')?.value || '-0.1'
            ),
            transcription_highlight_scroll_behavior: 
                document.getElementById('transcription_highlight_scroll_behavior')?.value || 'smooth',
            transcription_highlight_enable_auto_scroll: 
                document.getElementById('transcription_highlight_enable_auto_scroll')?.checked !== false,
            transcription_highlight_active_color: 
                document.getElementById('transcription_highlight_active_color')?.value || '#ffeb3b',
            transcription_highlight_active_bg: 
                document.getElementById('transcription_highlight_active_bg')?.value || '#ffeb3b',
            transcription_highlight_hover_color: 
                document.getElementById('transcription_highlight_hover_color')?.value || '#1976d2',
            transcription_highlight_hover_bg: 
                document.getElementById('transcription_highlight_hover_bg')?.value || '#e3f2fd',
            transcription_highlight_font_weight: parseInt(
                document.getElementById('transcription_highlight_font_weight')?.value || '600'
            ),
            transcription_highlight_transition_duration: parseFloat(
                document.getElementById('transcription_highlight_transition_duration')?.value || '0.2'
            ),
            transcription_highlight_config_ui_type: 
                document.getElementById('transcription_highlight_config_ui_type')?.value || 'modal',
            transcription_highlight_enable_preview: 
                document.getElementById('transcription_highlight_enable_preview')?.checked !== false
        };
    },

    /**
     * Validar valores de configuración
     * @returns {Object} { valid: boolean, errors: string[] }
     */
    validate: function() {
        const errors = [];
        const values = this.getValues();

        // Validar throttle
        if (values.transcription_highlight_throttle_ms < 10 || values.transcription_highlight_throttle_ms > 100) {
            errors.push('Throttle debe estar entre 10 y 100ms');
        }

        // Validar offset
        if (values.transcription_highlight_audio_offset < -0.5 || values.transcription_highlight_audio_offset > 0.5) {
            errors.push('Offset de audio debe estar entre -0.5 y 0.5 segundos');
        }

        // Validar colores
        const colorRegex = /^#[0-9A-Fa-f]{6}$/;
        if (!colorRegex.test(values.transcription_highlight_active_color)) {
            errors.push('Color activo debe ser un código hexadecimal válido (ej: #ffeb3b)');
        }
        if (!colorRegex.test(values.transcription_highlight_active_bg)) {
            errors.push('Fondo activo debe ser un código hexadecimal válido');
        }
        if (!colorRegex.test(values.transcription_highlight_hover_color)) {
            errors.push('Color hover debe ser un código hexadecimal válido');
        }
        if (!colorRegex.test(values.transcription_highlight_hover_bg)) {
            errors.push('Fondo hover debe ser un código hexadecimal válido');
        }

        // Validar font weight
        if (values.transcription_highlight_font_weight < 400 || values.transcription_highlight_font_weight > 900) {
            errors.push('Peso de fuente debe estar entre 400 y 900');
        }

        // Validar transition duration
        if (values.transcription_highlight_transition_duration < 0 || values.transcription_highlight_transition_duration > 1) {
            errors.push('Duración de transición debe estar entre 0 y 1 segundo');
        }

        return {
            valid: errors.length === 0,
            errors: errors
        };
    }
};

// Exportar para uso global
if (typeof window !== 'undefined') {
    window.TranscriptionHighlightConfig = TranscriptionHighlightConfig;
}
