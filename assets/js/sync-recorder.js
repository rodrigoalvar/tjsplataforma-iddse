/**
 * Módulo de Grabación Sincronizada
 * Permite grabar audio y transcribir simultáneamente para crear sincronización precisa
 */
class SyncRecorderModule {
    constructor() {
        this.isRecording = false;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.recognition = null;
        this.syncData = [];
        this.currentAudio = null;
        this.startTime = null;
        this.timerInterval = null;
        this.microphoneCoordinator = null;
        this.continuousPlay = true; // Modo de reproducción continua por defecto
        this.wordPlayTimeout = null; // Para controlar la reproducción de palabra individual
        
        // Variables de estado para el reconocimiento
        this.recognitionActive = false;
        this.recognitionStopping = false;
        this.speechModuleWasActive = false;
        
        // Variables para manejo de transcripciones intermedias
        this.lastInterimTranscript = '';
        this.lastInterimTime = 0;
        
        // Elementos del DOM
        this.syncButton = null;
        this.syncTimer = null;
        this.syncStatus = null;
        this.audioPlayer = null;
        this.transcriptionContainer = null;
        this.playModeButton = null;
        
        this.init();
    }

    init() {
        console.log('Inicializando SyncRecorderModule...');
        this.setupDOM();
        this.setupSpeechRecognition();
        this.setupEventListeners();
        
        // Obtener referencia al coordinador de micrófono
        if (window.microphoneCoordinator) {
            this.microphoneCoordinator = window.microphoneCoordinator;
            console.log('Coordinador de micrófono encontrado');
        } else {
            console.log('Coordinador de micrófono no encontrado');
        }
        
        console.log('SyncRecorderModule inicializado correctamente');
    }

    setupDOM() {
        this.syncButton = document.getElementById('sync-button');
        this.syncTimer = document.getElementById('sync-timer');
        this.syncStatus = document.getElementById('sync-status');
        this.audioPlayer = document.getElementById('sync-audio-player');
        this.transcriptionContainer = document.getElementById('sync-transcription');
        this.playModeButton = document.getElementById('play-mode-button');
        
        // Crear panel de debugging si no existe
        this.createDebugPanel();
        
        console.log('Elementos DOM encontrados:', {
            syncButton: !!this.syncButton,
            syncTimer: !!this.syncTimer,
            syncStatus: !!this.syncStatus,
            audioPlayer: !!this.audioPlayer,
            transcriptionContainer: !!this.transcriptionContainer
        });
    }

    setupSpeechRecognition() {
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            console.error('Speech Recognition no está soportado en este navegador');
            return;
        }

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        this.recognition = new SpeechRecognition();
        
        // Configuración optimizada para capturar más audio
        this.recognition.continuous = true;
        this.recognition.interimResults = true;
        this.recognition.lang = 'es-ES';
        this.recognition.maxAlternatives = 1;
        
        // Configuraciones adicionales para mejorar la captura
        if (this.recognition.serviceURI !== undefined) {
            // Configuraciones específicas para Chrome/WebKit
            this.recognition.grammars = null;
        }

        this.recognition.onstart = () => {
            console.log('Reconocimiento de voz iniciado - Configuración optimizada');
            this.recognitionActive = true;
            this.updateStatus('Reconocimiento activo - Hablando...');
        };

        this.recognition.onresult = (event) => {
            this.handleSpeechResult(event);
        };

        this.recognition.onerror = (event) => {
            console.error('Error en reconocimiento de voz:', event.error);
            this.recognitionActive = false;
            
            // Manejo mejorado de errores
            switch(event.error) {
                case 'aborted':
                    console.log('Reconocimiento abortado, no se reiniciará automáticamente');
                    return;
                case 'no-speech':
                    console.log('No se detectó habla, continuando...');
                    break;
                case 'audio-capture':
                    console.error('Error de captura de audio - verificar micrófono');
                    this.updateStatus('Error: Verificar permisos del micrófono');
                    return;
                case 'not-allowed':
                    console.error('Permisos de micrófono denegados');
                    this.updateStatus('Error: Permisos de micrófono requeridos');
                    return;
                default:
                    console.log(`Error de reconocimiento: ${event.error}`);
            }
        };

        this.recognition.onend = () => {
            console.log('Reconocimiento de voz terminado');
            this.recognitionActive = false;
            
            // Procesar transcripciones intermedias pendientes antes de reiniciar
            if (this.isRecording) {
                this.forceProcessInterimResults();
            }
            
            // Dar tiempo para procesar resultados finales antes de reiniciar
            if (this.isRecording && !this.recognitionStopping) {
                setTimeout(() => {
                    if (this.isRecording && !this.recognitionActive && !this.recognitionStopping) {
                        try {
                            console.log('Reiniciando reconocimiento para continuidad...');
                            this.recognition.start();
                        } catch (error) {
                            console.error('Error al reiniciar reconocimiento:', error);
                            // Intentar una vez más después de un breve delay
                            setTimeout(() => {
                                if (this.isRecording && !this.recognitionActive && !this.recognitionStopping) {
                                    try {
                                        this.recognition.start();
                                    } catch (retryError) {
                                        console.error('Error en segundo intento de reinicio:', retryError);
                                    }
                                }
                            }, 1000);
                        }
                    }
                }, 300); // Aumentado a 300ms para permitir procesamiento de resultados finales
            }
        };
    }

    setupEventListeners() {
        if (this.syncButton) {
            this.syncButton.addEventListener('click', () => {
                if (this.isRecording) {
                    this.stopSync();
                } else {
                    this.startSync();
                }
            });
        }

        if (this.playModeButton) {
            this.playModeButton.addEventListener('click', () => {
                this.togglePlayMode();
            });
        }

        if (this.audioPlayer) {
            this.audioPlayer.addEventListener('timeupdate', () => {
                this.highlightCurrentWord();
            });
        }
        
        // Agregar atajo de teclado para mostrar/ocultar panel de debug
        document.addEventListener('keydown', (event) => {
            if (event.ctrlKey && event.shiftKey && event.key === 'D') {
                event.preventDefault();
                this.toggleDebugPanel();
            }
        });
    }

    async startSync() {
        // Evitar múltiples inicializaciones
        if (this.isRecording) {
            console.log('Ya hay una grabación en curso');
            return;
        }

        try {
            // Detener el reconocimiento de voz principal si está activo
            if (window.SpeechModule && window.SpeechModule.recognitionState.isListening) {
                console.log('Deteniendo reconocimiento de voz principal...');
                window.SpeechModule.stopListening();
                this.speechModuleWasActive = true;
                await new Promise(resolve => setTimeout(resolve, 300));
            }

            // Detener cualquier reconocimiento previo del sync
            if (this.recognitionActive) {
                this.recognitionStopping = true;
                this.recognition.stop();
                await new Promise(resolve => setTimeout(resolve, 200));
            }

            this.recognitionStopping = false;
            
            // Solicitar acceso al micrófono
            let stream;
            if (this.microphoneCoordinator) {
                await this.microphoneCoordinator.enableRecording();
                stream = this.microphoneCoordinator.getStream();
            } else {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            }

            // Configurar MediaRecorder
            this.setupMediaRecorder(stream);
            
            // Inicializar datos de sincronización
            this.syncData = [];
            this.audioChunks = [];
            this.startTime = Date.now();
            
            // Actualizar estado antes de iniciar
            this.isRecording = true;
            this.updateUI();
            this.startTimer();
            
            // La grabación ya se inició automáticamente en setupMediaRecorder
            console.log('MediaRecorder ya iniciado automáticamente');
            
            // Esperar un poco antes de iniciar el reconocimiento
            setTimeout(() => {
                if (this.isRecording && !this.recognitionActive) {
                    try {
                        this.recognition.start();
                    } catch (error) {
                        console.error('Error al iniciar reconocimiento:', error);
                    }
                }
            }, 100);
            
            console.log('Grabación sincronizada iniciada');
            
        } catch (error) {
            console.error('Error al iniciar grabación sincronizada:', error);
            this.updateStatus('Error al acceder al micrófono');
            this.isRecording = false;
            this.updateUI();
        }
    }

    setupMediaRecorder(stream) {
        // Configurar opciones de grabación para mejor calidad y sincronización
        const options = {
            mimeType: 'audio/webm;codecs=opus',
            audioBitsPerSecond: 128000
        };
        
        // Verificar soporte del formato, usar alternativo si es necesario
        if (!MediaRecorder.isTypeSupported(options.mimeType)) {
            options.mimeType = 'audio/webm';
            if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                options.mimeType = 'audio/mp4';
            }
        }
        
        this.mediaRecorder = new MediaRecorder(stream, options);
        console.log('MediaRecorder configurado con:', options);
        
        this.mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                this.audioChunks.push(event.data);
                console.log(`Chunk de audio recibido: ${event.data.size} bytes`);
            }
        };
        
        this.mediaRecorder.onstop = () => {
            console.log(`Grabación finalizada. Total chunks: ${this.audioChunks.length}`);
            this.processRecording();
        };
        
        this.mediaRecorder.onerror = (event) => {
            console.error('Error en MediaRecorder:', event.error);
        };
        
        // Iniciar grabación con intervalos más frecuentes para mejor sincronización
        this.mediaRecorder.start(100); // Capturar datos cada 100ms
    }

    stopSync() {
        if (!this.isRecording) return;
        
        console.log('Deteniendo grabación sincronizada...');
        
        this.isRecording = false;
        this.recognitionStopping = true;
        
        // Forzar procesamiento de transcripciones intermedias pendientes
        this.forceProcessInterimResults();
        
        // Detener grabación y reconocimiento
        if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
            this.mediaRecorder.stop();
        }
        
        if (this.recognition && this.recognitionActive) {
            this.recognition.stop();
        }
        
        // Liberar micrófono
        if (this.microphoneCoordinator) {
            this.microphoneCoordinator.disableRecording();
        }
        
        // Actualizar UI
        this.updateUI();
        this.stopTimer();
        
        // Reset flags
        setTimeout(() => {
            this.recognitionStopping = false;
            this.recognitionActive = false;
            
            // Restaurar el reconocimiento de voz principal si estaba activo
            if (this.speechModuleWasActive && window.SpeechModule) {
                console.log('Restaurando reconocimiento de voz principal...');
                setTimeout(() => {
                    window.SpeechModule.startListening();
                }, 500);
                this.speechModuleWasActive = false;
            }
        }, 500);
        
        console.log('Grabación sincronizada detenida');
    }

    handleSpeechResult(event) {
        if (!this.isRecording) return;
        
        const currentTime = (Date.now() - this.startTime) / 1000;
        let interimTranscript = '';
        let finalTranscript = '';
        
        // Procesar todos los resultados desde el índice actual
        for (let i = event.resultIndex; i < event.results.length; i++) {
            const transcript = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                finalTranscript += transcript;
                console.log(`Resultado FINAL detectado en índice ${i}:`, JSON.stringify(transcript));
            } else {
                interimTranscript += transcript;
            }
        }
        
        // Almacenar transcripción intermedia para procesamiento posterior si no hay finales
        if (interimTranscript.trim() && !finalTranscript.trim()) {
            this.lastInterimTranscript = interimTranscript.trim();
            this.lastInterimTime = currentTime;
        }
        
        // Procesar transcripción final si existe
        if (finalTranscript.trim()) {
            this.processTranscriptSegment(finalTranscript.trim(), currentTime, true);
            this.lastInterimTranscript = ''; // Limpiar interim después de procesar final
        }
        
        // Actualizar visualización en tiempo real
        this.updateLiveTranscription(finalTranscript, interimTranscript);
        
        // Log de progreso
        if (interimTranscript.trim()) {
            console.log('Transcripción intermedia:', JSON.stringify(interimTranscript));
        }
    }
    
    processTranscriptSegment(transcript, currentTime, isFinal = false) {
        console.log(`Procesando segmento ${isFinal ? 'FINAL' : 'INTERIM'}:`, JSON.stringify(transcript));
        
        // Dividir en palabras y agregar a datos de sincronización
        const words = transcript.split(/\s+/).filter(word => word.length > 0);
        
        if (words.length > 0) {
            // Calcular timing más preciso basado en velocidad de habla promedio
            // Velocidad promedio: 2.2 palabras por segundo (132 palabras por minuto) - más conservador
            const averageWordsPerSecond = 2.2;
            const estimatedSegmentDuration = words.length / averageWordsPerSecond;
            
            // Ajustar duración basada en longitud de palabras
            const totalCharacters = words.join('').length;
            const characterBasedDuration = totalCharacters * 0.09; // 90ms por carácter - más tiempo
            
            // Usar el mayor de los dos cálculos para mayor precisión
            const segmentDuration = Math.max(estimatedSegmentDuration, characterBasedDuration, 0.6);
            // Ajustar el tiempo de inicio del segmento con un buffer adicional
            const segmentStartTime = Math.max(0, currentTime - segmentDuration - 0.2);
            
            // Calcular tiempos acumulativos para evitar drift
            let cumulativeTime = 0;
            const wordDurations = words.map(word => Math.max(0.25, word.length * 0.09)); // Duración individual
            const totalCalculatedDuration = wordDurations.reduce((sum, dur) => sum + dur, 0);
            
            // Factor de escala para ajustar a la duración real del segmento
            const scaleFactor = segmentDuration / totalCalculatedDuration;
            
            words.forEach((word, index) => {
                const wordCharacters = word.length;
                const baseDuration = wordDurations[index] * scaleFactor;
                
                // Tiempo absoluto basado en acumulación, no en proporción
                const wordStartTime = segmentStartTime + cumulativeTime;
                const wordEndTime = wordStartTime + baseDuration;
                
                cumulativeTime += baseDuration; // Acumular para la siguiente palabra
                
                // Verificar duplicados con tolerancia ajustada
                const isDuplicate = this.syncData.some(item => 
                    item.word.toLowerCase().trim() === word.toLowerCase().trim() && 
                    Math.abs(item.startTime - wordStartTime) < 0.25
                );
                
                if (!isDuplicate) {
                    const wordData = {
                        word: word.trim(),
                        startTime: wordStartTime,
                        endTime: wordEndTime,
                        confidence: 0.9,
                        isFinal: isFinal,
                        timestamp: Date.now(),
                        segmentIndex: this.syncData.length,
                        wordLength: wordCharacters,
                        scaleFactor: scaleFactor.toFixed(3)
                    };
                    
                    this.syncData.push(wordData);
                    
                    // Log detallado de cada palabra
                    console.log(`  📍 "${word}" [${this.syncData.length}]: ${wordStartTime.toFixed(2)}s - ${wordEndTime.toFixed(2)}s (${baseDuration.toFixed(2)}s)`);
                    console.log(`Palabra sincronizada: "${word}" (${wordCharacters} chars) en ${wordStartTime.toFixed(2)}s-${wordEndTime.toFixed(2)}s (escala: ${scaleFactor.toFixed(3)}, ${isFinal ? 'FINAL' : 'INTERIM'})`);
                }
            });
            
            console.log(`📝 SEGMENTO: "${transcript}" | Tiempo: ${currentTime.toFixed(2)}s | Palabras: ${words.length} | Duración: ${segmentDuration.toFixed(2)}s | Inicio: ${segmentStartTime.toFixed(2)}s`);
            this.updateStatus(`Grabando... (${this.syncData.length} palabras capturadas)`);
        }
    }
    
    // Método para forzar el procesamiento de transcripciones intermedias como finales
    forceProcessInterimResults() {
        if (this.lastInterimTranscript && this.lastInterimTranscript.trim()) {
            console.log('Forzando procesamiento de transcripción intermedia como final:', this.lastInterimTranscript);
            this.processTranscriptSegment(this.lastInterimTranscript, this.lastInterimTime, true);
            this.lastInterimTranscript = '';
        }
    }
    
    updateLiveTranscription(finalTranscript, interimTranscript) {
        if (!this.transcriptionContainer) return;
        
        let displayContent = '';
        
        // Mostrar todas las palabras capturadas hasta ahora
        if (this.syncData.length > 0) {
            const capturedText = this.syncData
                .sort((a, b) => a.startTime - b.startTime)
                .map(item => item.word)
                .join(' ');
            displayContent += capturedText;
        }
        
        // Agregar transcripción final reciente si no está ya incluida
        if (finalTranscript.trim()) {
            if (displayContent && !displayContent.includes(finalTranscript.trim())) {
                displayContent += ' ' + finalTranscript.trim();
            } else if (!displayContent) {
                displayContent = finalTranscript.trim();
            }
        }
        
        // Agregar transcripción intermedia con estilo diferente
        if (interimTranscript.trim()) {
            if (displayContent) displayContent += ' ';
            displayContent += `<span style="color: #666; font-style: italic; background-color: #f8f9fa; padding: 2px 4px; border-radius: 3px;">${interimTranscript.trim()}</span>`;
        }
        
        // Actualizar contenedor
        if (displayContent.trim()) {
            this.transcriptionContainer.innerHTML = `
                <div style="padding: 10px; border: 1px solid #dee2e6; border-radius: 5px; background-color: #fff;">
                    <p style="margin: 0; line-height: 1.5;">${displayContent}</p>
                    <small style="color: #6c757d; margin-top: 5px; display: block;">
                        <i class="fas fa-microphone"></i> ${this.syncData.length} palabras capturadas
                        ${interimTranscript.trim() ? ' • Escuchando...' : ''}
                    </small>
                </div>
            `;
        }
    }

    processRecording() {
        if (this.audioChunks.length === 0) return;
        
        // Crear blob de audio
        const audioBlob = new Blob(this.audioChunks, { type: 'audio/wav' });
        const audioUrl = URL.createObjectURL(audioBlob);
        
        // Configurar reproductor de audio
        if (this.audioPlayer) {
            this.audioPlayer.src = audioUrl;
            this.audioPlayer.style.display = 'block';
        }
        
        // Mostrar botón de modo de reproducción
        if (this.playModeButton) {
            this.playModeButton.style.display = 'inline-block';
        }
        
        // Crear transcripción clickeable
        this.createClickableTranscription();
        
        // Actualizar estado
        this.updateStatus('Grabación completada - Listo para reproducir');
        
        console.log('Procesamiento completado. Datos de sincronización:', this.syncData);
    }

    createClickableTranscription() {
        if (!this.transcriptionContainer) return;
        
        if (this.syncData.length === 0) {
            // Si no hay datos de sincronización, mostrar mensaje informativo
            this.transcriptionContainer.innerHTML = '<p style="color: #666; font-style: italic;">No se capturó transcripción durante la grabación. Asegúrate de hablar claramente y verificar los permisos del micrófono.</p>';
            return;
        }
        
        // Ordenar datos por tiempo de inicio
        const sortedData = [...this.syncData].sort((a, b) => a.startTime - b.startTime);
        
        // Limpiar contenedor
        this.transcriptionContainer.innerHTML = '';
        
        // Crear párrafo para la transcripción
        const transcriptionParagraph = document.createElement('p');
        transcriptionParagraph.className = 'sync-transcription';
        
        sortedData.forEach((item, index) => {
            const wordSpan = document.createElement('span');
            wordSpan.textContent = item.word;
            wordSpan.className = 'sync-word';
            wordSpan.dataset.startTime = item.startTime;
            wordSpan.dataset.endTime = item.endTime;
            wordSpan.dataset.index = index;
            
            // Hacer clickeable
            wordSpan.addEventListener('click', () => {
                this.seekToTime(item.startTime);
            });
            
            transcriptionParagraph.appendChild(wordSpan);
            
            // Agregar espacio entre palabras
            if (index < sortedData.length - 1) {
                transcriptionParagraph.appendChild(document.createTextNode(' '));
            }
        });
        
        this.transcriptionContainer.appendChild(transcriptionParagraph);
        
        // Agregar información de estadísticas
        const statsDiv = document.createElement('div');
        statsDiv.className = 'sync-stats';
        statsDiv.innerHTML = `<small style="color: #666;">Transcripción: ${sortedData.length} palabras capturadas</small>`;
        this.transcriptionContainer.appendChild(statsDiv);
        
        console.log(`Transcripción creada con ${sortedData.length} palabras`);
    }

    seekToTime(time) {
        if (this.audioPlayer) {
            // Limpiar timeout previo si existe
            if (this.wordPlayTimeout) {
                clearTimeout(this.wordPlayTimeout);
                this.wordPlayTimeout = null;
            }

            // Calcular offset dinámico basado en la posición temporal
            // Offset más agresivo para palabras tempranas, menos para palabras tardías
            const totalDuration = this.audioPlayer.duration || 60; // Duración total estimada
            const timeRatio = time / totalDuration; // Ratio de posición (0-1)
            
            // Offset variable: 500ms al inicio, 300ms al final
            const dynamicOffset = 0.5 - (timeRatio * 0.2); // 0.5s -> 0.3s
            const adjustedTime = Math.max(0, time - dynamicOffset);
            
            console.log(`🎯 SEEK: ${time}s (${(timeRatio*100).toFixed(1)}%) -> ajustado: ${adjustedTime}s (offset: -${(dynamicOffset*1000).toFixed(0)}ms)`);
            
            this.audioPlayer.currentTime = adjustedTime;
            
            if (this.continuousPlay) {
                // Modo continuo: reproducir normalmente
                if (this.audioPlayer.paused) {
                    this.audioPlayer.play().catch(error => {
                        console.error('Error al reproducir audio:', error);
                    });
                }
            } else {
                // Modo palabra individual: reproducir solo la palabra
                const currentWord = this.findWordByTime(time);
                if (currentWord) {
                    const wordDuration = (currentWord.endTime - currentWord.startTime) * 1000; // Convertir a ms
                    
                    if (this.audioPlayer.paused) {
                        this.audioPlayer.play().catch(error => {
                            console.error('Error al reproducir audio:', error);
                        });
                    }
                    
                    // Pausar después de la duración de la palabra
                    this.wordPlayTimeout = setTimeout(() => {
                        this.audioPlayer.pause();
                        console.log(`Palabra "${currentWord.word}" reproducida individualmente`);
                    }, wordDuration + 100); // +100ms de buffer
                }
            }
            
            // Actualizar panel de debug inmediatamente
            setTimeout(() => this.updateDebugPanel(), 50);
        }
    }

    highlightCurrentWord() {
        if (!this.audioPlayer || !this.transcriptionContainer) return;
        
        const currentTime = this.audioPlayer.currentTime;
        const words = this.transcriptionContainer.querySelectorAll('.sync-word');
        
        words.forEach(word => {
            const startTime = parseFloat(word.dataset.startTime);
            const endTime = parseFloat(word.dataset.endTime);
            
            if (currentTime >= startTime && currentTime <= endTime) {
                word.classList.add('current-word');
            } else {
                word.classList.remove('current-word');
            }
        });
        
        // Actualizar panel de debug
        this.updateDebugPanel();
    }

    startTimer() {
        let seconds = 0;
        this.timerInterval = setInterval(() => {
            seconds++;
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            const timeString = `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
            
            if (this.syncTimer) {
                this.syncTimer.textContent = timeString;
            }
        }, 1000);
    }

    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }

    updateUI() {
        if (this.syncButton) {
            this.syncButton.textContent = this.isRecording ? 'DETENER SYNC' : 'INICIAR SYNC';
            this.syncButton.className = this.isRecording ? 'sync-button recording' : 'sync-button';
        }
        
        if (this.syncStatus) {
            this.syncStatus.textContent = this.isRecording ? 'Grabando y transcribiendo...' : 'Listo para grabar';
        }
        
        if (!this.isRecording && this.syncTimer) {
            this.syncTimer.textContent = '00:00';
        }
        
        // Ocultar botón de modo cuando no hay audio
        if (this.playModeButton && !this.isRecording && (!this.audioPlayer || !this.audioPlayer.src)) {
            this.playModeButton.style.display = 'none';
        }
    }

    updateStatus(message) {
        if (this.syncStatus) {
            this.syncStatus.textContent = message;
        }
        console.log('Estado:', message);
    }

    createDebugPanel() {
        // Crear panel de debugging si no existe
        let debugPanel = document.getElementById('sync-debug-panel');
        if (!debugPanel) {
            debugPanel = document.createElement('div');
            debugPanel.id = 'sync-debug-panel';
            debugPanel.style.cssText = `
                position: fixed;
                top: 10px;
                right: 10px;
                width: 300px;
                background: rgba(0,0,0,0.8);
                color: white;
                padding: 10px;
                border-radius: 5px;
                font-family: monospace;
                font-size: 12px;
                z-index: 9999;
                display: none;
            `;
            document.body.appendChild(debugPanel);
        }
        this.debugPanel = debugPanel;
        
        // Crear elementos de debug
         this.debugPanel.innerHTML = `
             <div><strong>🔍 Sync Debug Panel</strong> <small>(Ctrl+Shift+D)</small></div>
             <hr style="margin: 5px 0; border: 1px solid #444;">
             <div><strong>⏱️ Tiempos:</strong></div>
             <div>Audio: <span id="debug-audio-time">0.00s</span></div>
             <div>Grabación: <span id="debug-recording-time">0.00s</span></div>
             <div>Duración Total: <span id="debug-total-duration">0.00s</span></div>
             <hr style="margin: 5px 0; border: 1px solid #444;">
             <div><strong>📝 Palabra Actual:</strong></div>
             <div>Texto: <span id="debug-current-word">-</span></div>
             <div>Rango: <span id="debug-word-range">-</span></div>
             <div>Índice: <span id="debug-word-index">-</span></div>
             <hr style="margin: 5px 0; border: 1px solid #444;">
             <div><strong>📊 Sincronización:</strong></div>
             <div>Drift: <span id="debug-drift" style="font-weight: bold;">0.00s</span></div>
             <div>Offset Aplicado: <span id="debug-offset">0ms</span></div>
             <div>Total Palabras: <span id="debug-total-words">0</span></div>
             <div>Progreso: <span id="debug-progress">0%</span></div>
             <hr style="margin: 5px 0; border: 1px solid #444;">
             <div><strong>🎵 Reproducción:</strong></div>
             <div>Modo: <span id="debug-play-mode">Continuo</span></div>
         `;
    }

    updateDebugPanel() {
        if (!this.debugPanel || this.debugPanel.style.display === 'none') return;
        
        const audioTime = this.audioPlayer ? this.audioPlayer.currentTime : 0;
        const totalDuration = this.audioPlayer ? this.audioPlayer.duration : 0;
        const recordingTime = this.startTime ? (Date.now() - this.startTime) / 1000 : 0;
        const currentWord = this.findCurrentWord(audioTime);
        
        // Calcular offset dinámico actual
        const timeRatio = totalDuration > 0 ? audioTime / totalDuration : 0;
        const dynamicOffset = 0.5 - (timeRatio * 0.2);
        
        // Actualizar tiempos
        document.getElementById('debug-audio-time').textContent = `${audioTime.toFixed(2)}s`;
        document.getElementById('debug-recording-time').textContent = `${recordingTime.toFixed(2)}s`;
        document.getElementById('debug-total-duration').textContent = `${totalDuration.toFixed(2)}s`;
        document.getElementById('debug-total-words').textContent = this.syncData.length;
        document.getElementById('debug-progress').textContent = `${(timeRatio * 100).toFixed(1)}%`;
        document.getElementById('debug-offset').textContent = `${(dynamicOffset * 1000).toFixed(0)}ms`;
        document.getElementById('debug-play-mode').textContent = this.continuousPlay ? 'Continuo' : 'Palabra Individual';
        
        if (currentWord) {
            document.getElementById('debug-current-word').textContent = currentWord.word;
            document.getElementById('debug-word-range').textContent = `${currentWord.startTime.toFixed(2)}s - ${currentWord.endTime.toFixed(2)}s`;
            document.getElementById('debug-word-index').textContent = `${currentWord.index + 1}/${this.syncData.length}`;
            
            const expectedTime = (currentWord.startTime + currentWord.endTime) / 2;
            const drift = audioTime - expectedTime;
            const driftElement = document.getElementById('debug-drift');
            driftElement.textContent = `${drift > 0 ? '+' : ''}${drift.toFixed(2)}s`;
            
            // Colorear según el drift
            if (Math.abs(drift) > 1.0) {
                driftElement.style.color = '#ff4757'; // Rojo para drift alto
            } else if (Math.abs(drift) > 0.5) {
                driftElement.style.color = '#ffa502'; // Naranja para drift medio
            } else {
                driftElement.style.color = '#2ed573'; // Verde para drift bajo
            }
        } else {
            document.getElementById('debug-current-word').textContent = '-';
            document.getElementById('debug-word-range').textContent = '-';
            document.getElementById('debug-word-index').textContent = '-';
            document.getElementById('debug-drift').textContent = '0.00s';
            document.getElementById('debug-drift').style.color = '#666';
        }
    }

    findCurrentWord(audioTime) {
        return this.syncData.find(item => 
            audioTime >= item.startTime && audioTime <= item.endTime
        );
    }

    findWordByTime(time) {
        return this.syncData.find(item => 
            time >= item.startTime && time <= item.endTime
        );
    }

    togglePlayMode() {
        this.continuousPlay = !this.continuousPlay;
        
        // Limpiar timeout si existe
        if (this.wordPlayTimeout) {
            clearTimeout(this.wordPlayTimeout);
            this.wordPlayTimeout = null;
        }
        
        // Actualizar UI del botón
        if (this.playModeButton) {
            this.playModeButton.textContent = this.continuousPlay ? 'MODO: CONTINUO' : 'MODO: PALABRA';
            this.playModeButton.className = this.continuousPlay ? 'play-mode-button continuous' : 'play-mode-button individual';
        }
        
        // Actualizar estado
        const modeText = this.continuousPlay ? 'continua' : 'palabra individual';
        this.updateStatus(`Modo de reproducción: ${modeText}`);
        
        console.log(`Modo de reproducción cambiado a: ${modeText}`);
    }

    toggleDebugPanel() {
        if (this.debugPanel) {
            const isVisible = this.debugPanel.style.display !== 'none';
            this.debugPanel.style.display = isVisible ? 'none' : 'block';
            
            if (!isVisible) {
                // Iniciar actualización del panel
                this.debugUpdateInterval = setInterval(() => {
                    this.updateDebugPanel();
                }, 100);
            } else {
                // Detener actualización del panel
                if (this.debugUpdateInterval) {
                    clearInterval(this.debugUpdateInterval);
                    this.debugUpdateInterval = null;
                }
            }
        }
    }

    // Método para limpiar recursos
    cleanup() {
        this.stopSync();
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }
        if (this.debugUpdateInterval) {
            clearInterval(this.debugUpdateInterval);
        }
        if (this.wordPlayTimeout) {
            clearTimeout(this.wordPlayTimeout);
        }
        if (this.currentAudio) {
            URL.revokeObjectURL(this.currentAudio);
        }
    }
}

// Función para inicialización manual del módulo
window.initSyncRecorder = function() {
    if (!window.syncRecorder) {
        console.log('Inicializando SyncRecorderModule manualmente...');
        window.syncRecorder = new SyncRecorderModule();
    } else {
        console.log('SyncRecorderModule ya está inicializado');
    }
};

// Exportar para uso en otros módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SyncRecorderModule;
}