/**
 * Transcription Highlight Plugin
 * Plugin reutilizable para resaltado de palabras durante reproducción de audio
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Características:
 * - Resaltado sincronizado con audio usando rangos [start, end)
 * - Búsqueda binaria O(log n) para mejor rendimiento
 * - requestAnimationFrame para actualizaciones fluidas
 * - Configuración personalizable desde servidor
 * - Soporte para modal y panel flotante
 * - Palabras clicables para saltar a timestamps
 * - Scroll automático configurable
 */

class TranscriptionHighlightPlugin {
    /**
     * Constructor
     * @param {Object} options - Opciones de configuración
     * @param {HTMLElement|string} options.textContainer - Contenedor del texto (elemento o selector)
     * @param {HTMLElement|string} options.audioElement - Elemento de audio (elemento o selector)
     * @param {Object} options.transcription - Objeto con transcripción y segments
     * @param {string} options.mode - Modo: 'modal' | 'floating'
     * @param {Function} options.onWordClick - Callback cuando se hace click en palabra
     * @param {Function} options.onHighlightChange - Callback cuando cambia la palabra resaltada
     */
    constructor(options = {}) {
        // Validar opciones requeridas
        if (!options.textContainer || !options.audioElement) {
            throw new Error('textContainer y audioElement son requeridos');
        }

        // Obtener elementos del DOM
        this.textContainer = typeof options.textContainer === 'string' 
            ? document.querySelector(options.textContainer) 
            : options.textContainer;
        this.audioElement = typeof options.audioElement === 'string' 
            ? document.querySelector(options.audioElement) 
            : options.audioElement;

        if (!this.textContainer || !this.audioElement) {
            throw new Error('No se pudieron encontrar los elementos del DOM');
        }

        // Configuración
        this.mode = options.mode || 'modal';
        this.transcription = options.transcription || null;
        this.onWordClick = options.onWordClick || null;
        this.onHighlightChange = options.onHighlightChange || null;

        // Estado interno
        this.wordsWithTimestamps = [];
        this.currentWordIdx = -1;
        this.animationFrameId = null;
        this.isPlaying = false;
        this.lastUpdateTime = 0;
        this.config = null;
        this.isInitialized = false;

        // Configuración por defecto (se sobrescribirá al cargar desde servidor)
        this.defaultConfig = {
            throttleMs: 30,
            audioOffset: -0.1,
            scrollBehavior: 'smooth',
            enableAutoScroll: true,
            activeColor: '#ffeb3b',
            activeBg: '#ffeb3b',
            hoverColor: '#1976d2',
            hoverBg: '#e3f2fd',
            fontWeight: 600,
            transitionDuration: 0.2
        };

        // Aplicar configuración por defecto temporalmente
        this.throttleMs = this.defaultConfig.throttleMs;
        this.audioOffset = this.defaultConfig.audioOffset;
        this.scrollBehavior = this.defaultConfig.scrollBehavior;
        this.enableAutoScroll = this.defaultConfig.enableAutoScroll;
    }

    /**
     * Inicializar el plugin
     */
    async init() {
        if (this.isInitialized) {
            console.warn('Plugin ya inicializado');
            return;
        }

        try {
            // Cargar configuración desde servidor
            await this.loadConfig();

            // Validar transcripción
            if (!this.transcription || !this.transcription.segments || !this.transcription.segments.length) {
                console.warn('No hay transcripción con segments disponible');
                return;
            }

            // Mapear palabras a timestamps
            this.wordsWithTimestamps = this.mapWordsToTimestamps(
                this.transcription.transcription_text || this.transcription.transcription || '',
                this.transcription.segments
            );

            // Precalcular rangos start/end
            this.precalculateWordRanges(this.transcription.segments);

            // Generar HTML con palabras clicables
            this.generateWordsHTML();

            // Aplicar estilos CSS
            this.applyCustomStyles();

            // Configurar eventos
            this.setupEventListeners();

            this.isInitialized = true;
            console.log('✅ Transcription Highlight Plugin inicializado');
        } catch (error) {
            console.error('❌ Error inicializando plugin:', error);
            throw error;
        }
    }

    /**
     * Cargar configuración desde el servidor
     */
    async loadConfig() {
        try {
            const response = await fetch('api/ai-informes-config.php');
            const data = await response.json();
            
            if (data.success && data.config) {
                this.config = data.config;
                this.applyConfig();
            } else {
                console.warn('No se pudo cargar configuración, usando valores por defecto');
                this.config = this.getDefaultConfig();
                this.applyConfig();
            }
        } catch (error) {
            console.warn('Error cargando configuración, usando valores por defecto:', error);
            this.config = this.getDefaultConfig();
            this.applyConfig();
        }
    }

    /**
     * Obtener configuración por defecto
     */
    getDefaultConfig() {
        return {
            transcription_highlight_throttle_ms: this.defaultConfig.throttleMs,
            transcription_highlight_audio_offset: this.defaultConfig.audioOffset,
            transcription_highlight_scroll_behavior: this.defaultConfig.scrollBehavior,
            transcription_highlight_enable_auto_scroll: this.defaultConfig.enableAutoScroll,
            transcription_highlight_active_color: this.defaultConfig.activeColor,
            transcription_highlight_active_bg: this.defaultConfig.activeBg,
            transcription_highlight_hover_color: this.defaultConfig.hoverColor,
            transcription_highlight_hover_bg: this.defaultConfig.hoverBg,
            transcription_highlight_font_weight: this.defaultConfig.fontWeight,
            transcription_highlight_transition_duration: this.defaultConfig.transitionDuration
        };
    }

    /**
     * Aplicar configuración cargada
     */
    applyConfig() {
        if (!this.config) {
            this.config = this.getDefaultConfig();
        }

        // Aplicar valores de configuración
        this.throttleMs = this.config.transcription_highlight_throttle_ms || this.defaultConfig.throttleMs;
        
        // Asegurar que audioOffset sea un número válido
        const configOffset = this.config.transcription_highlight_audio_offset;
        if (typeof configOffset === 'number' && isFinite(configOffset)) {
            this.audioOffset = configOffset;
        } else {
            this.audioOffset = this.defaultConfig.audioOffset;
        }
        
        this.scrollBehavior = this.config.transcription_highlight_scroll_behavior || this.defaultConfig.scrollBehavior;
        this.enableAutoScroll = this.config.transcription_highlight_enable_auto_scroll !== false;
    }

    /**
     * Mapear palabras del texto a timestamps usando los segments
     */
    mapWordsToTimestamps(fullText, segments) {
        if (!fullText || !segments || !segments.length) {
            return fullText.split(/(\s+)/).map(text => ({ text, timestamp: null }));
        }

        const words = fullText.split(/(\s+)/);
        const result = [];
        const normalizedFullText = fullText.replace(/\s+/g, ' ').trim();
        const fullWords = normalizedFullText.split(/\s+/).filter(w => w.length > 0);

        let fullTextWordIdx = 0;
        let currentSegmentIdx = 0;

        for (let wordIdx = 0; wordIdx < words.length; wordIdx++) {
            const word = words[wordIdx];

            if (!word.trim()) {
                result.push({ text: word, timestamp: null });
                continue;
            }

            const wordTrimmed = word.trim();
            let timestamp = null;

            if (fullTextWordIdx < fullWords.length && fullWords[fullTextWordIdx] === wordTrimmed) {
                let foundInSegment = false;

                for (let segIdx = currentSegmentIdx; segIdx < segments.length; segIdx++) {
                    const seg = segments[segIdx];
                    const segText = (seg.text || '').trim();

                    if (!segText) continue;

                    const segWords = segText.split(/\s+/).filter(w => w.length > 0);
                    const wordPosInSegment = segWords.findIndex(w => w === wordTrimmed);

                    if (wordPosInSegment >= 0) {
                        const totalWords = segWords.length;
                        const segmentDuration = seg.end - seg.start;

                        if (totalWords === 1) {
                            timestamp = seg.start;
                        } else if (wordPosInSegment === 0) {
                            timestamp = seg.start;
                        } else if (wordPosInSegment === totalWords - 1) {
                            timestamp = seg.end;
                        } else {
                            const progress = wordPosInSegment / (totalWords - 1);
                            timestamp = seg.start + segmentDuration * progress;
                        }

                        result.push({ text: word, timestamp });
                        currentSegmentIdx = segIdx;
                        foundInSegment = true;
                        fullTextWordIdx++;
                        break;
                    }
                }

                if (!foundInSegment) {
                    if (currentSegmentIdx < segments.length) {
                        timestamp = segments[currentSegmentIdx].start;
                        result.push({ text: word, timestamp });
                    } else {
                        result.push({ text: word, timestamp: null });
                    }
                    fullTextWordIdx++;
                }
            } else {
                if (currentSegmentIdx < segments.length) {
                    timestamp = segments[currentSegmentIdx].start;
                    result.push({ text: word, timestamp });
                } else {
                    result.push({ text: word, timestamp: null });
                }
            }
        }

        return result;
    }

    /**
     * Precalcular rangos start/end para cada palabra
     */
    precalculateWordRanges(segments) {
        for (let i = 0; i < this.wordsWithTimestamps.length; i++) {
            const w = this.wordsWithTimestamps[i];

            if (w.timestamp === null) {
                w.start = null;
                w.end = null;
                continue;
            }

            w.start = w.timestamp;

            // Buscar siguiente palabra con timestamp
            const next = this.wordsWithTimestamps.slice(i + 1).find(x => x.timestamp !== null);

            if (next) {
                w.end = next.timestamp;
            } else {
                // Última palabra: usar el end del último segmento
                const lastSegment = segments[segments.length - 1];
                w.end = lastSegment ? lastSegment.end : (w.start + 0.5);
            }
        }
    }

    /**
     * Búsqueda binaria para encontrar palabra activa según tiempo
     */
    findWordIndexForTime(t) {
        let lo = 0;
        let hi = this.wordsWithTimestamps.length - 1;
        let result = -1;
        let bestMatch = null;
        let bestDistance = Infinity;

        while (lo <= hi) {
            const mid = Math.floor((lo + hi) / 2);
            const w = this.wordsWithTimestamps[mid];

            // Saltar palabras sin timestamp
            if (w.start == null || w.end == null) {
                // Buscar siguiente palabra válida hacia adelante
                let validIdx = mid + 1;
                while (validIdx < this.wordsWithTimestamps.length && 
                       (this.wordsWithTimestamps[validIdx].start == null || 
                        this.wordsWithTimestamps[validIdx].end == null)) {
                    validIdx++;
                }

                if (validIdx < this.wordsWithTimestamps.length) {
                    const validWord = this.wordsWithTimestamps[validIdx];
                    if (t >= validWord.start && t < validWord.end) {
                        result = validIdx;
                        break;
                    }
                    if (t < validWord.start) {
                        hi = mid - 1;
                    } else {
                        lo = validIdx + 1;
                    }
                } else {
                    // No hay más palabras válidas hacia adelante, buscar hacia atrás
                    validIdx = mid - 1;
                    while (validIdx >= 0 && 
                           (this.wordsWithTimestamps[validIdx].start == null || 
                            this.wordsWithTimestamps[validIdx].end == null)) {
                        validIdx--;
                    }
                    if (validIdx >= 0) {
                        const validWord = this.wordsWithTimestamps[validIdx];
                        if (t >= validWord.start && t < validWord.end) {
                            result = validIdx;
                            break;
                        }
                        if (t < validWord.start) {
                            hi = validIdx - 1;
                        } else {
                            lo = mid + 1;
                        }
                    } else {
                        hi = mid - 1;
                    }
                }
                continue;
            }

            if (t >= w.start && t < w.end) {
                result = mid;
                break;
            } else if (t < w.start) {
                hi = mid - 1;
            } else {
                lo = mid + 1;
            }
        }

        // Fallback: palabra más cercana en un rango pequeño
        if (result === -1) {
            for (let i = 0; i < this.wordsWithTimestamps.length; i++) {
                const w = this.wordsWithTimestamps[i];
                if (w.start == null || w.end == null) continue;

                if (t >= w.start - 0.1 && t < w.end + 0.1) {
                    const distance = t < w.start ? (w.start - t) :
                                    t >= w.end ? (t - w.end) : 0;

                    if (distance < bestDistance) {
                        bestDistance = distance;
                        bestMatch = i;
                    }
                }
            }
            result = bestMatch !== null ? bestMatch : -1;
        }

        return result;
    }

    /**
     * Generar HTML con palabras clicables
     */
    generateWordsHTML() {
        const htmlWords = this.wordsWithTimestamps.map((word, idx) => {
            // Validar que timestamp sea un número finito válido
            const timestamp = (word.timestamp !== null && 
                              typeof word.timestamp === 'number' && 
                              isFinite(word.timestamp) && 
                              word.timestamp >= 0) ? word.timestamp : null;
            
            const className = timestamp !== null ? 'transcription-word clickable-word' : 'transcription-word';
            const dataAttr = timestamp !== null ? ` data-timestamp="${timestamp}"` : '';
            const title = timestamp !== null ? ` title="Ir a ${this.formatSecondsToWhisperTs(timestamp)}"` : '';
            return `<span class="${className}"${dataAttr}${title} data-word-idx="${idx}">${this.escapeHtml(word.text)}</span>`;
        });

        this.textContainer.innerHTML = htmlWords.join('');

        // Configurar eventos de click en palabras
        this.textContainer.querySelectorAll('.clickable-word').forEach(wordSpan => {
            wordSpan.addEventListener('click', () => {
                const timestampAttr = wordSpan.getAttribute('data-timestamp');
                if (!timestampAttr) {
                    console.warn('Palabra sin timestamp:', wordSpan);
                    return;
                }
                
                let timestamp = parseFloat(timestampAttr);
                
                // Validar que sea un número finito y válido
                if (isNaN(timestamp) || !isFinite(timestamp) || timestamp < 0) {
                    console.warn('Timestamp inválido:', timestampAttr, '→', timestamp);
                    return;
                }
                
                // Aplicar offset y asegurar que no sea negativo
                // Validar que audioOffset sea un número válido
                const audioOffset = (typeof this.audioOffset === 'number' && isFinite(this.audioOffset)) 
                    ? this.audioOffset 
                    : -0.1; // Valor por defecto
                
                timestamp = Math.max(0, timestamp + audioOffset);
                
                // Validar nuevamente después de aplicar offset
                if (!isFinite(timestamp) || timestamp < 0) {
                    console.warn('Timestamp inválido después de aplicar offset:', timestamp, 'offset aplicado:', audioOffset);
                    return;
                }

                // Establecer el tiempo primero
                try {
                    this.audioElement.currentTime = timestamp;
                    
                    // Forzar actualización inmediata del resaltado
                    this.lastUpdateTime = 0; // Reset para forzar actualización
                    this.updateHighlight(true); // Actualizar inmediatamente (forzado)
                    
                } catch (error) {
                    console.error('Error estableciendo currentTime:', error, 'timestamp:', timestamp);
                    return;
                }

                // Pequeño delay para asegurar que el tiempo se estableció correctamente
                setTimeout(() => {
                    try {
                        const currentTime = this.audioElement.currentTime;
                        if (Math.abs(currentTime - timestamp) > 0.1) {
                            this.audioElement.currentTime = timestamp;
                            // Forzar actualización nuevamente si hubo corrección
                            this.lastUpdateTime = 0;
                            this.updateHighlight(true);
                        }
                        
                        // Asegurar que el loop de resaltado esté activo
                        if (!this.isPlaying && !this.audioElement.paused) {
                            this.startHighlightLoop();
                        } else if (this.isPlaying) {
                            // Si ya está reproduciendo, reiniciar el loop para asegurar continuidad
                            this.lastUpdateTime = 0;
                        }
                        
                        this.audioElement.play().catch(e => console.log('Error al reproducir:', e));
                    } catch (error) {
                        console.error('Error en setTimeout de click:', error);
                    }
                }, 30);

                // Callback personalizado
                if (this.onWordClick) {
                    const wordIdx = parseInt(wordSpan.getAttribute('data-word-idx'));
                    if (!isNaN(wordIdx)) {
                        this.onWordClick(timestamp, wordIdx);
                    }
                }
            });
        });
    }

    /**
     * Aplicar estilos CSS personalizados
     */
    applyCustomStyles() {
        const styleId = 'transcription-highlight-plugin-styles';
        let style = document.getElementById(styleId);
        if (!style) {
            style = document.createElement('style');
            style.id = styleId;
            document.head.appendChild(style);
        }

        const activeColor = this.config?.transcription_highlight_active_color || this.defaultConfig.activeColor;
        const activeBg = this.config?.transcription_highlight_active_bg || this.defaultConfig.activeBg;
        const hoverColor = this.config?.transcription_highlight_hover_color || this.defaultConfig.hoverColor;
        const hoverBg = this.config?.transcription_highlight_hover_bg || this.defaultConfig.hoverBg;
        const fontWeight = this.config?.transcription_highlight_font_weight || this.defaultConfig.fontWeight;
        const transitionDuration = this.config?.transcription_highlight_transition_duration || this.defaultConfig.transitionDuration;

        style.textContent = `
            .transcription-word {
                transition: background-color ${transitionDuration}s, color ${transitionDuration}s;
                padding: 2px 1px;
                border-radius: 2px;
            }
            .clickable-word {
                cursor: pointer;
            }
            .clickable-word:hover {
                background-color: ${hoverBg};
                color: ${hoverColor};
            }
            .transcription-word.active {
                background-color: ${activeBg} !important;
                color: ${activeColor} !important;
                font-weight: ${fontWeight};
            }
        `;
    }

    /**
     * Actualizar resaltado durante reproducción
     * @param {boolean} force - Forzar actualización incluso si está pausado
     */
    updateHighlight(force = false) {
        // Si no está forzado y no está reproduciendo, salir
        if (!force && (!this.isPlaying || this.audioElement.paused)) {
            this.animationFrameId = null;
            return;
        }

        const currentTime = this.audioElement.currentTime;

        // Throttle: actualizar solo si ha pasado suficiente tiempo
        if (this.lastUpdateTime > 0 && currentTime - this.lastUpdateTime < (this.throttleMs / 1000)) {
            this.animationFrameId = requestAnimationFrame(() => this.updateHighlight());
            return;
        }
        this.lastUpdateTime = currentTime;

        // Buscar palabra activa usando búsqueda binaria
        const newWordIdx = this.findWordIndexForTime(currentTime);

        // Actualizar resaltado solo si cambió la palabra
        if (newWordIdx !== this.currentWordIdx && newWordIdx >= 0) {
            // Remover resaltado anterior
            if (this.currentWordIdx >= 0) {
                const prevWord = this.textContainer.querySelector(`[data-word-idx="${this.currentWordIdx}"]`);
                if (prevWord) prevWord.classList.remove('active');
            }

            // Agregar resaltado a la nueva palabra
            const newWord = this.textContainer.querySelector(`[data-word-idx="${newWordIdx}"]`);
            if (newWord) {
                newWord.classList.add('active');

                // Scroll automático si está habilitado
                if (this.enableAutoScroll) {
                    const rect = newWord.getBoundingClientRect();
                    const containerRect = this.textContainer.getBoundingClientRect();
                    if (rect.top < containerRect.top || rect.bottom > containerRect.bottom) {
                        newWord.scrollIntoView({ 
                            behavior: this.scrollBehavior, 
                            block: 'center' 
                        });
                    }
                }
            }

            // Callback personalizado
            if (this.onHighlightChange) {
                this.onHighlightChange(newWordIdx, this.wordsWithTimestamps[newWordIdx]);
            }

            this.currentWordIdx = newWordIdx;
        }

        // Continuar el loop de animación
        this.animationFrameId = requestAnimationFrame(() => this.updateHighlight());
    }

    /**
     * Iniciar loop de resaltado
     */
    startHighlightLoop() {
        if (this.animationFrameId) return;
        this.isPlaying = true;
        this.lastUpdateTime = 0; // Reset para forzar primera actualización
        this.animationFrameId = requestAnimationFrame(() => this.updateHighlight());
    }

    /**
     * Detener loop de resaltado
     */
    stopHighlightLoop() {
        this.isPlaying = false;
        if (this.animationFrameId) {
            cancelAnimationFrame(this.animationFrameId);
            this.animationFrameId = null;
        }
    }

    /**
     * Configurar event listeners
     */
    setupEventListeners() {
        // Event listeners del audio
        this.audioElement.addEventListener('play', () => {
            this.startHighlightLoop();
        });

        this.audioElement.addEventListener('pause', () => {
            this.stopHighlightLoop();
            if (this.currentWordIdx >= 0) {
                const word = this.textContainer.querySelector(`[data-word-idx="${this.currentWordIdx}"]`);
                if (word) word.classList.remove('active');
            }
        });

        this.audioElement.addEventListener('ended', () => {
            this.stopHighlightLoop();
            if (this.currentWordIdx >= 0) {
                const word = this.textContainer.querySelector(`[data-word-idx="${this.currentWordIdx}"]`);
                if (word) word.classList.remove('active');
            }
            this.currentWordIdx = -1;
        });

        // timeupdate como respaldo
        this.audioElement.addEventListener('timeupdate', () => {
            if (!this.isPlaying && !this.audioElement.paused) {
                this.startHighlightLoop();
            }
        });

        // seeked: forzar actualización cuando se cambia el tiempo manualmente
        this.audioElement.addEventListener('seeked', () => {
            this.lastUpdateTime = 0; // Reset para forzar actualización
            this.updateHighlight(true); // Actualizar inmediatamente (forzado)
            
            // Asegurar que el loop esté activo si está reproduciendo
            if (!this.isPlaying && !this.audioElement.paused) {
                this.startHighlightLoop();
            }
        });
    }

    /**
     * Actualizar transcripción (útil cuando cambia el audio)
     */
    updateTranscription(transcription) {
        this.transcription = transcription;
        this.currentWordIdx = -1;
        this.stopHighlightLoop();

        if (!transcription || !transcription.segments || !transcription.segments.length) {
            this.textContainer.innerHTML = transcription?.transcription_text || transcription?.transcription || 'Sin transcripción disponible';
            return;
        }

        // Re-mapear palabras
        this.wordsWithTimestamps = this.mapWordsToTimestamps(
            transcription.transcription_text || transcription.transcription || '',
            transcription.segments
        );

        // Precalcular rangos
        this.precalculateWordRanges(transcription.segments);

        // Regenerar HTML
        this.generateWordsHTML();
    }

    /**
     * Recargar configuración desde servidor
     */
    async reloadConfig() {
        await this.loadConfig();
        this.applyCustomStyles();
    }

    /**
     * Destruir instancia y limpiar recursos
     */
    destroy() {
        this.stopHighlightLoop();
        
        // Remover event listeners (los eventos se limpiarán automáticamente al remover elementos)
        // No hay necesidad de remover listeners manualmente ya que los elementos pueden ser removidos del DOM
        
        // Limpiar estado
        this.isInitialized = false;
        this.currentWordIdx = -1;
        this.wordsWithTimestamps = [];
        
        console.log('✅ Transcription Highlight Plugin destruido');
    }

    /**
     * Formatear segundos a HH:MM:SS.mmm
     */
    formatSecondsToWhisperTs(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        const ms = Math.round((seconds - Math.floor(seconds)) * 1000);
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${String(ms).padStart(3, '0')}`;
    }

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
    }
}

// Exportar para uso global
if (typeof window !== 'undefined') {
    window.TranscriptionHighlightPlugin = TranscriptionHighlightPlugin;
}
