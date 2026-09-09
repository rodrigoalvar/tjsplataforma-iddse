/**
 * Módulo de Grabación Sincronizada para Editor
 * Adapta SyncRecorderModule para trabajar con TinyMCE
 */
class SyncEditorModule {
    constructor() {
        this.isRecording = false;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.recognition = null;
        this.syncData = [];
        this.currentAudio = null;
        this.startTime = null;
        this.timerInterval = null;
        this.continuousPlay = true;
        this.wordPlayTimeout = null;
        
        // Variables de estado para el reconocimiento
        this.recognitionActive = false;
        this.recognitionStopping = false;
        
        // Variables para manejo de transcripciones intermedias
        this.lastInterimTranscript = '';
        this.lastInterimTime = 0;
        this.pendingTinyMCEContent = '';
        
        // Elementos del DOM
        this.syncButton = null;
        this.syncTimer = null;
        this.syncStatus = null;
        this.audioPlayer = null;
        this.transcriptionContainer = null;
        this.playModeButton = null;
        
        // Referencia al editor TinyMCE
        this.tinyMCEEditor = null;
        
        this.init();
    }

    init() {
        if (this.initialized) {
            console.log('SyncEditorModule ya está inicializado');
            return;
        }
        
        console.log('Inicializando SyncEditorModule...');
        this.setupDOM();
        this.setupSpeechRecognition();
        this.setupEventListeners();
        this.setupTinyMCEIntegration();
        this.setupSyncAdjustmentControls();
        
        // Obtener referencia al coordinador de micrófono
        if (window.MicrophoneCoordinator) {
            this.microphoneCoordinator = window.MicrophoneCoordinator;
            console.log('Coordinador de micrófono encontrado');
        } else {
            console.warn('Coordinador de micrófono no encontrado');
        }
        
        this.initialized = true;
        console.log('SyncEditorModule inicializado correctamente');
    }

    setupDOM() {
        this.syncButton = document.getElementById('sync-button');
        this.syncTimer = document.getElementById('sync-timer');
        this.syncStatus = document.getElementById('sync-status');
        this.audioPlayer = document.getElementById('sync-audio-player');
        this.transcriptionContainer = document.getElementById('sync-transcription');
        this.playModeButton = document.getElementById('play-mode-button');
        this.downloadButton = document.getElementById('sync-download-button');
        
        // Crear panel de debugging si no existe
        this.createDebugPanel();
        
        console.log('Elementos DOM encontrados:', {
            syncButton: !!this.syncButton,
            syncTimer: !!this.syncTimer,
            syncStatus: !!this.syncStatus,
            audioPlayer: !!this.audioPlayer,
            transcriptionContainer: !!this.transcriptionContainer,
            downloadButton: !!this.downloadButton
        });
    }

    setupTinyMCEIntegration() {
        // Esperar a que TinyMCE esté listo
        const checkTinyMCE = () => {
            if (window.tinymce && tinymce.get('reportEditor')) {
                this.tinyMCEEditor = tinymce.get('reportEditor');
                console.log('TinyMCE editor encontrado y configurado');
                
                // Agregar botón personalizado para insertar transcripción SYNC
                this.tinyMCEEditor.ui.registry.addButton('insertsync', {
                    text: 'SYNC',
                    tooltip: 'Insertar transcripción sincronizada',
                    onAction: () => {
                        this.insertSyncTranscriptionIntoEditor();
                    }
                });
                
                // Actualizar la barra de herramientas para incluir el botón SYNC
                if (this.tinyMCEEditor.settings && this.tinyMCEEditor.settings.toolbar) {
                    const currentToolbar = this.tinyMCEEditor.settings.toolbar;
                    if (!currentToolbar.includes('insertsync')) {
                        this.tinyMCEEditor.settings.toolbar = currentToolbar + ' | insertsync';
                    }
                }
            } else {
                setTimeout(checkTinyMCE, 100);
            }
        };
        checkTinyMCE();
    }

    setupSpeechRecognition() {
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            console.error('Speech Recognition no está soportado en este navegador');
            return;
        }

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        this.recognition = new SpeechRecognition();
        
        this.recognition.continuous = true;
        this.recognition.interimResults = true;
        this.recognition.lang = 'es-ES';
        this.recognition.maxAlternatives = 1;

        this.recognition.onstart = () => {
            console.log('Reconocimiento de voz iniciado');
            this.recognitionActive = true;
            this.updateStatus('Reconocimiento activo - Hablando...');
        };

        this.recognition.onresult = (event) => {
            this.handleSpeechResult(event);
        };

        this.recognition.onerror = (event) => {
            console.error('Error en reconocimiento de voz:', event.error);
            this.recognitionActive = false;
            this.updateStatus('Error en reconocimiento: ' + event.error);
        };

        this.recognition.onend = () => {
            console.log('Reconocimiento de voz finalizado');
            this.recognitionActive = false;
            if (this.isRecording && !this.recognitionStopping) {
                console.log('Reiniciando reconocimiento automáticamente');
                setTimeout(() => {
                    if (this.isRecording) {
                        try {
                            this.recognition.start();
                        } catch (error) {
                            console.error('Error al reiniciar reconocimiento:', error);
                        }
                    }
                }, 100);
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

        if (this.downloadButton) {
            this.downloadButton.addEventListener('click', () => {
                this.downloadAudio();
            });
        }

        // Atajos de teclado
        document.addEventListener('keydown', (event) => {
            if (event.ctrlKey && event.shiftKey && event.key === 'D') {
                event.preventDefault();
                this.toggleDebugPanel();
            }
        });
    }

    async startSync() {
        if (this.isRecording) {
            console.log('Ya se está grabando');
            return;
        }

        try {
            // Solicitar acceso al micrófono a través del coordinador
            if (window.MicrophoneCoordinator) {
                const success = await new Promise((resolve) => {
                    MicrophoneCoordinator.enableSync(resolve);
                });
                
                if (!success) {
                    throw new Error('No se pudo acceder al micrófono');
                }
                
                const stream = MicrophoneCoordinator.getRecordingStream();
                if (!stream) {
                    throw new Error('No se pudo obtener el stream de audio');
                }
                
                this.setupMediaRecorder(stream);
            } else {
                // Fallback directo si no hay coordinador
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                this.setupMediaRecorder(stream);
            }

            // Inicializar datos de sincronización
            this.syncData = [];
            this.audioChunks = [];
            this.startTime = Date.now();
            this.lastInterimTranscript = '';
            this.lastInterimTime = 0;
            this.pendingTinyMCEContent = '';
            
            // Iniciar grabación
            this.mediaRecorder.start();
            this.isRecording = true;
            
            // Iniciar reconocimiento de voz
            if (!this.recognitionActive) {
                this.recognition.start();
            }
            
            // Iniciar timer
            this.startTimer();
            
            // Actualizar UI
            this.updateUI();
            this.updateStatus('Grabando y transcribiendo...');
            
            console.log('Sincronización iniciada');
            
        } catch (error) {
            console.error('Error al iniciar sincronización:', error);
            this.updateStatus('Error: ' + error.message);
        }
    }

    setupMediaRecorder(stream) {
        this.mediaRecorder = new MediaRecorder(stream);
        
        this.mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                this.audioChunks.push(event.data);
            }
        };
        
        this.mediaRecorder.onstop = () => {
            this.processRecording();
        };
    }

    stopSync() {
        if (!this.isRecording) {
            return;
        }

        console.log('Deteniendo sincronización...');
        this.isRecording = false;
        this.recognitionStopping = true;
        
        // Detener reconocimiento
        if (this.recognitionActive) {
            try {
                this.recognition.stop();
            } catch (error) {
                console.error('Error al detener reconocimiento:', error);
            }
        }
        
        // Procesar resultados intermedios finales
        this.forceProcessInterimResults();
        
        // Detener grabación
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }
        
        // Liberar micrófono
        if (window.MicrophoneCoordinator) {
            MicrophoneCoordinator.disableSync();
        }
        
        // Detener timer
        this.stopTimer();
        
        // Actualizar UI
        this.updateUI();
        this.updateStatus('Procesando grabación...');
        
        console.log('Sincronización detenida');
    }

    handleSpeechResult(event) {
        const currentTime = Date.now() - this.startTime;
        let interimTranscript = '';
        let finalTranscript = '';

        for (let i = event.resultIndex; i < event.results.length; i++) {
            const transcript = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                finalTranscript += transcript;
            } else {
                interimTranscript += transcript;
            }
        }

        // Procesar transcripción final
        if (finalTranscript) {
            console.log('handleSpeechResult - Procesando finalTranscript:', finalTranscript);
            this.processTranscriptSegment(finalTranscript, currentTime, true);
            // Agregar a pendingTinyMCEContent solo aquí para evitar duplicación
            if (!this.pendingTinyMCEContent) {
                this.pendingTinyMCEContent = '';
            }
            this.pendingTinyMCEContent += finalTranscript + ' ';
            console.log('handleSpeechResult - pendingTinyMCEContent actualizado:', this.pendingTinyMCEContent);
        }

        // Actualizar transcripción en vivo (solo para mostrar texto intermedio)
        this.updateLiveTranscription(finalTranscript, interimTranscript);
        this.updateDebugPanel();
    }

    processTranscriptSegment(transcript, currentTime, isFinal = false) {
        if (!transcript.trim()) return;

        const words = transcript.trim().split(/\s+/);
        const segmentDuration = currentTime - (this.syncData.length > 0 ? 
            this.syncData[this.syncData.length - 1].endTime : 0);
        const avgWordDuration = segmentDuration / words.length;
        
        console.log(`Procesando segmento: "${transcript}" - Tiempo: ${currentTime}ms - Palabras: ${words.length} - Duración: ${segmentDuration}ms`);
        
        let wordStartTime = this.syncData.length > 0 ? 
            this.syncData[this.syncData.length - 1].endTime : 0;
        
        words.forEach((word, index) => {
            const wordEndTime = wordStartTime + avgWordDuration;
            
            const wordData = {
                word: word,
                startTime: wordStartTime,
                endTime: wordEndTime,
                index: this.syncData.length,
                isFinal: isFinal
            };
            
            this.syncData.push(wordData);
            
            console.log(`Palabra procesada: "${word}" - Inicio: ${wordStartTime.toFixed(2)}ms - Fin: ${wordEndTime.toFixed(2)}ms - Duración: ${(wordEndTime - wordStartTime).toFixed(2)}ms`);
            
            wordStartTime = wordEndTime;
        });
    }

    forceProcessInterimResults() {
        if (this.lastInterimTranscript && this.lastInterimTime > 0) {
            console.log('Procesando resultados intermedios finales:', this.lastInterimTranscript);
            
            // Verificar si este texto ya fue procesado como final
            const alreadyProcessed = this.pendingTinyMCEContent && 
                this.pendingTinyMCEContent.includes(this.lastInterimTranscript.trim());
            
            if (!alreadyProcessed) {
                this.processTranscriptSegment(this.lastInterimTranscript, this.lastInterimTime, true);
                
                // Agregar el texto intermedio final a pendingTinyMCEContent
                if (!this.pendingTinyMCEContent) {
                    this.pendingTinyMCEContent = '';
                }
                this.pendingTinyMCEContent += this.lastInterimTranscript + ' ';
                console.log('forceProcessInterimResults - pendingTinyMCEContent actualizado:', this.pendingTinyMCEContent);
            } else {
                console.log('forceProcessInterimResults - Texto ya procesado, omitiendo duplicación');
            }
        }
    }

    updateLiveTranscription(finalTranscript, interimTranscript) {
        if (!this.transcriptionContainer) return;
        
        // Solo mostrar transcripción intermedia durante la grabación
        if (this.isRecording) {
            if (interimTranscript) {
                this.transcriptionContainer.innerHTML = '<span class="interim-text">' + interimTranscript + '</span>';
                this.lastInterimTranscript = interimTranscript;
                this.lastInterimTime = Date.now() - this.startTime;
            } else if (finalTranscript) {
                // Limpiar el contenedor cuando se finaliza un segmento
                this.transcriptionContainer.innerHTML = '';
            }
        }
        
        // NO agregar a pendingTinyMCEContent aquí - se hace en handleSpeechResult
    }

    processRecording() {
        if (this.audioChunks.length === 0) {
            console.log('No hay datos de audio para procesar');
            this.updateStatus('No se grabó audio');
            return;
        }

        const audioBlob = new Blob(this.audioChunks, { type: 'audio/wav' });
        this.currentAudioBlob = audioBlob; // Almacenar el blob para acceso posterior
        const audioUrl = URL.createObjectURL(audioBlob);
        
        // Guardar como archivo temporal
        if (window.TempAudioManager) {
            TempAudioManager.saveAudioFile(audioBlob, 'sync')
                .then(result => {
                    console.log('Audio sincronizado guardado como archivo temporal:', result);
                })
                .catch(error => {
                    console.error('Error al guardar audio sincronizado temporal:', error);
                });
        }
        
        // Configurar reproductor de audio
        if (this.audioPlayer) {
            this.audioPlayer.src = audioUrl;
            this.audioPlayer.style.display = 'block';
            
            // Mostrar el contenedor de audio completo
            const audioContainer = this.audioPlayer.closest('.sync-audio-container');
            if (audioContainer) {
                audioContainer.style.display = 'block';
            }
            
            // Agregar event listeners para el resaltado de palabras
            this.audioPlayer.addEventListener('timeupdate', () => {
                this.highlightCurrentWord();
            });
            
            this.audioPlayer.addEventListener('play', () => {
                this.highlightCurrentWord();
            });
            
            this.audioPlayer.addEventListener('pause', () => {
                // Mantener resaltado cuando se pausa
                this.highlightCurrentWord();
            });
            
            // Mostrar botón de modo de reproducción
            if (this.playModeButton) {
                this.playModeButton.style.display = 'inline-block';
            }
        }
        
        this.currentAudio = this.audioPlayer; // Referencia al elemento de audio HTML
        this.currentAudioUrl = audioUrl; // URL del audio
        this.currentAudioBlob = audioBlob;
        
        // Mostrar botón de descarga
        if (this.downloadButton) {
            this.downloadButton.style.display = 'inline-block';
        }
        
        // Insertar contenido acumulado en TinyMCE
        console.log('Verificando inserción en TinyMCE:');
        console.log('- pendingTinyMCEContent:', this.pendingTinyMCEContent);
        console.log('- tinyMCEEditor disponible:', !!this.tinyMCEEditor);
        
        if (this.pendingTinyMCEContent && this.tinyMCEEditor) {
            console.log('Insertando contenido en TinyMCE:', this.pendingTinyMCEContent);
            this.tinyMCEEditor.insertContent(this.pendingTinyMCEContent);
            this.pendingTinyMCEContent = ''; // Limpiar contenido pendiente
            console.log('Contenido insertado exitosamente');
        } else {
            console.log('No se pudo insertar contenido:', {
                hasPendingContent: !!this.pendingTinyMCEContent,
                hasTinyMCEEditor: !!this.tinyMCEEditor
            });
        }
        
        // Crear transcripción clickeable
        this.createClickableTranscription();
        
        this.updateStatus(`Grabación completada - ${this.syncData.length} palabras sincronizadas`);
        console.log('Procesamiento de grabación completado');
    }

    createClickableTranscription() {
        if (!this.transcriptionContainer || this.syncData.length === 0) return;
        
        let html = '<div class="clickable-transcription">';
        
        this.syncData.forEach((wordData, index) => {
            if (wordData.isFinal) {
                html += `<span class="sync-word" data-start="${wordData.startTime}" data-end="${wordData.endTime}" data-index="${index}">${wordData.word}</span> `;
            }
        });
        
        html += '</div>';
        this.transcriptionContainer.innerHTML = html;
        
        // Agregar event listeners a las palabras
        this.transcriptionContainer.querySelectorAll('.sync-word').forEach(wordElement => {
            wordElement.addEventListener('click', (e) => {
                const startTime = parseFloat(e.target.dataset.start) / 1000; // Convertir a segundos
                // Usar seekToTimeWithOffset si está disponible el offset
                if (this.timeOffset !== undefined) {
                    this.seekToTimeWithOffset(startTime);
                } else {
                    this.seekToTime(startTime);
                }
                this.updateDebugPanel();
            });
        });
    }

    seekToTime(time) {
        if (!this.audioPlayer || !this.currentAudio) return;
        
        // Limpiar timeout anterior
        if (this.wordPlayTimeout) {
            clearTimeout(this.wordPlayTimeout);
            this.wordPlayTimeout = null;
        }
        
        this.audioPlayer.currentTime = time;
        
        if (this.continuousPlay) {
            // Modo continuo: reproducir desde la posición
            this.audioPlayer.play();
        } else {
            // Modo individual: reproducir solo la palabra
            const currentWord = this.findWordByTime(time * 1000); // Convertir a ms
            if (currentWord) {
                const duration = (currentWord.endTime - currentWord.startTime) / 1000; // Convertir a segundos
                this.audioPlayer.play();
                
                // Pausar después de la duración de la palabra + buffer
                this.wordPlayTimeout = setTimeout(() => {
                    this.audioPlayer.pause();
                }, (duration + 0.1) * 1000); // 100ms de buffer
            }
        }
        
        this.highlightCurrentWord();
    }

    findWordByTime(time) {
        return this.syncData.find(word => 
            time >= word.startTime && time <= word.endTime
        );
    }

    togglePlayMode() {
        this.continuousPlay = !this.continuousPlay;
        
        if (this.playModeButton) {
            if (this.continuousPlay) {
                this.playModeButton.textContent = 'Continuo';
                this.playModeButton.className = 'play-mode-button continuous';
            } else {
                this.playModeButton.textContent = 'Individual';
                this.playModeButton.className = 'play-mode-button individual';
            }
        }
        
        this.updateDebugPanel();
        console.log('Modo de reproducción cambiado a:', this.continuousPlay ? 'Continuo' : 'Individual');
    }

    highlightCurrentWord() {
        if (!this.audioPlayer || !this.transcriptionContainer) return;
        
        const currentTime = this.audioPlayer.currentTime * 1000; // Convertir a ms
        const words = this.transcriptionContainer.querySelectorAll('.sync-word');
        
        words.forEach(word => {
            const startTime = parseFloat(word.dataset.start);
            const endTime = parseFloat(word.dataset.end);
            
            if (currentTime >= startTime && currentTime <= endTime) {
                word.classList.add('current-word');
            } else {
                word.classList.remove('current-word');
            }
        });
    }

    startTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }
        
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
        if (!this.syncButton) return;
        
        if (this.isRecording) {
            this.syncButton.innerHTML = '<i class="fas fa-stop me-2"></i>Detener SYNC';
            this.syncButton.className = 'btn btn-danger btn-lg';
        } else {
            this.syncButton.innerHTML = '<i class="fas fa-play me-2"></i>Iniciar SYNC';
            this.syncButton.className = 'btn btn-success btn-lg';
        }
    }

    updateStatus(message) {
        if (this.syncStatus) {
            this.syncStatus.textContent = message;
        }
        console.log('Status:', message);
    }

    createDebugPanel() {
        // Crear panel de debug si no existe
        if (document.getElementById('sync-debug-panel')) return;
        
        const debugPanel = document.createElement('div');
        debugPanel.id = 'sync-debug-panel';
        debugPanel.className = 'sync-debug-panel';
        debugPanel.style.display = 'none';
        debugPanel.innerHTML = `
            <div class="debug-header">
                <h6>Panel de Debug SYNC</h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="window.syncEditorModule.toggleDebugPanel()">×</button>
            </div>
            <div class="debug-content">
                <div id="debug-info">Información de debug aparecerá aquí...</div>
            </div>
        `;
        
        document.body.appendChild(debugPanel);
    }

    updateDebugPanel() {
        const debugInfo = document.getElementById('debug-info');
        if (!debugInfo) return;
        
        const audioTime = this.audioPlayer ? (this.audioPlayer.currentTime * 1000).toFixed(2) : '0.00';
        const recordingTime = this.startTime ? (Date.now() - this.startTime).toFixed(2) : '0.00';
        const totalDuration = this.audioPlayer ? (this.audioPlayer.duration * 1000).toFixed(2) : '0.00';
        const totalWords = this.syncData.length;
        const progress = this.audioPlayer && this.audioPlayer.duration ? 
            ((this.audioPlayer.currentTime / this.audioPlayer.duration) * 100).toFixed(1) : '0.0';
        
        const currentWord = this.audioPlayer ? 
            this.findWordByTime(this.audioPlayer.currentTime * 1000) : null;
        
        let debugHTML = `
            <strong>Tiempo Audio:</strong> ${audioTime}ms<br>
            <strong>Tiempo Grabación:</strong> ${recordingTime}ms<br>
            <strong>Duración Total:</strong> ${totalDuration}ms<br>
            <strong>Total Palabras:</strong> ${totalWords}<br>
            <strong>Progreso:</strong> ${progress}%<br>
            <strong>Modo Reproducción:</strong> ${this.continuousPlay ? 'Continuo' : 'Individual'}<br>
        `;
        
        if (currentWord) {
            debugHTML += `
                <strong>Palabra Actual:</strong> "${currentWord.word}"<br>
                <strong>Rango Palabra:</strong> ${currentWord.startTime.toFixed(2)}ms - ${currentWord.endTime.toFixed(2)}ms<br>
                <strong>Índice Palabra:</strong> ${currentWord.index}<br>
            `;
        }
        
        debugInfo.innerHTML = debugHTML;
    }

    toggleDebugPanel() {
        const debugPanel = document.getElementById('sync-debug-panel');
        if (debugPanel) {
            debugPanel.style.display = debugPanel.style.display === 'none' ? 'block' : 'none';
        }
    }

    // Insertar transcripción SYNC completa en el editor
    insertSyncTranscriptionIntoEditor() {
        if (!this.syncData || this.syncData.length === 0) {
            alert('No hay transcripción SYNC disponible. Primero graba con SYNC.');
            return;
        }
        
        if (!this.tinyMCEEditor) {
            alert('Editor TinyMCE no disponible.');
            return;
        }
        
        // Crear el contenido de la transcripción con palabras sincronizadas
        let syncContent = '<div class="sync-transcription-insert">';
        syncContent += '<h4>Transcripción Sincronizada</h4>';
        syncContent += '<p>';
        
        this.syncData.forEach((wordData, index) => {
            if (wordData.isFinal) {
                const timestamp = this.formatTime(wordData.startTime);
                syncContent += `<span class="sync-word" data-start="${wordData.startTime}" data-end="${wordData.endTime}" title="${timestamp}">${wordData.word}</span> `;
            }
        });
        
        syncContent += '</p></div><br>';
        
        // Insertar en la posición del cursor
        this.tinyMCEEditor.insertContent(syncContent);
        this.tinyMCEEditor.focus();
        
        // Mostrar confirmación
        this.updateStatus('Transcripción SYNC insertada en el editor');
    }

    // Formatear tiempo en formato legible
    formatTime(milliseconds) {
        const totalSeconds = Math.floor(milliseconds / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }

    downloadAudio() {
        if (!this.currentAudioBlob) {
            console.log('No hay audio disponible para descargar');
            this.updateStatus('No hay audio disponible para descargar');
            return;
        }

        try {
            // Crear un enlace de descarga
            const downloadUrl = URL.createObjectURL(this.currentAudioBlob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            
            // Generar nombre de archivo con timestamp
            const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5);
            link.download = `grabacion-sync-${timestamp}.wav`;
            
            // Agregar al DOM temporalmente y hacer click
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Limpiar URL después de un tiempo
            setTimeout(() => {
                URL.revokeObjectURL(downloadUrl);
            }, 1000);
            
            this.updateStatus('Audio descargado exitosamente');
            console.log('Audio descargado:', link.download);
            
        } catch (error) {
            console.error('Error al descargar audio:', error);
            this.updateStatus('Error al descargar el audio');
        }
    }

    cleanup() {
        console.log('Limpiando SyncEditorModule...');
        
        // Detener grabación si está activa
        if (this.isRecording) {
            this.stopSync();
        }
        
        // Limpiar reconocimiento de voz
        if (this.recognition) {
            this.recognition.stop();
            this.recognition = null;
        }
        
        // Limpiar audio
        if (this.currentAudio) {
            this.currentAudio.pause();
            this.currentAudio.src = '';
            this.currentAudio = null;
        }
        
        // Limpiar URL de audio
        if (this.currentAudioUrl) {
            URL.revokeObjectURL(this.currentAudioUrl);
            this.currentAudioUrl = null;
        }
        
        // Limpiar timers
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }
        
        if (this.wordPlayTimeout) {
            clearTimeout(this.wordPlayTimeout);
        }
        
        // Limpiar datos
        this.syncData = [];
        this.audioChunks = [];
        
        // Resetear estado
        this.isRecording = false;
        this.recognitionActive = false;
        this.recognitionStopping = false;
        
        console.log('SyncEditorModule limpiado');
    }
    
    // Limpiar audio actual
    clearCurrentAudio() {
        this.currentAudioBlob = null;
        this.audioChunks = [];
        
        if (this.audioPlayer) {
            this.audioPlayer.src = '';
            this.audioPlayer.style.display = 'none';
            
            const audioContainer = this.audioPlayer.closest('.sync-audio-container');
            if (audioContainer) {
                audioContainer.style.display = 'none';
            }
        }
        
        // Limpiar transcripción
        if (this.transcriptionContainer) {
            this.transcriptionContainer.innerHTML = '';
        }
        
        console.log('Audio sincronizado limpiado');
    }

    // Métodos para controles de sincronización manual
    setupSyncAdjustmentControls() {
        const timeOffsetSlider = document.getElementById('time-offset-slider');
        const timeOffsetValue = document.getElementById('time-offset-value');
        const playbackSpeedSlider = document.getElementById('playback-speed-slider');
        const playbackSpeedValue = document.getElementById('playback-speed-value');
        const resetSyncButton = document.getElementById('reset-sync-button');
        const applySyncButton = document.getElementById('apply-sync-button');
        const syncAdjustmentControls = document.getElementById('sync-adjustment-controls');

        // Variables para ajustes
        this.timeOffset = 0;
        this.playbackSpeed = 1.0;

        if (timeOffsetSlider && timeOffsetValue) {
            timeOffsetSlider.addEventListener('input', (e) => {
                this.timeOffset = parseFloat(e.target.value);
                timeOffsetValue.textContent = `${this.timeOffset.toFixed(1)}s`;
            });
        }

        if (playbackSpeedSlider && playbackSpeedValue) {
            playbackSpeedSlider.addEventListener('input', (e) => {
                this.playbackSpeed = parseFloat(e.target.value);
                playbackSpeedValue.textContent = `${this.playbackSpeed.toFixed(1)}x`;
                // Aplicar velocidad inmediatamente si hay audio reproduciéndose
                if (this.currentAudio) {
                    this.currentAudio.playbackRate = this.playbackSpeed;
                }
            });
        }

        if (resetSyncButton) {
            resetSyncButton.addEventListener('click', () => {
                this.resetSyncAdjustments();
            });
        }

        if (applySyncButton) {
            applySyncButton.addEventListener('click', () => {
                this.applySyncAdjustments();
            });
        }

        // Mostrar controles cuando hay transcripción
        if (syncAdjustmentControls) {
            const observer = new MutationObserver(() => {
                if (this.syncData && this.syncData.length > 0) {
                    syncAdjustmentControls.style.display = 'block';
                }
            });
            
            if (this.transcriptionContainer) {
                observer.observe(this.transcriptionContainer, { childList: true, subtree: true });
            }
        }
    }

    resetSyncAdjustments() {
        this.timeOffset = 0;
        this.playbackSpeed = 1.0;
        
        const timeOffsetSlider = document.getElementById('time-offset-slider');
        const timeOffsetValue = document.getElementById('time-offset-value');
        const playbackSpeedSlider = document.getElementById('playback-speed-slider');
        const playbackSpeedValue = document.getElementById('playback-speed-value');
        
        if (timeOffsetSlider) timeOffsetSlider.value = '0';
        if (timeOffsetValue) timeOffsetValue.textContent = '0.0s';
        if (playbackSpeedSlider) playbackSpeedSlider.value = '1';
        if (playbackSpeedValue) playbackSpeedValue.textContent = '1.0x';
        
        // Aplicar cambios inmediatamente
        this.applySyncAdjustments();
    }

    applySyncAdjustments() {
        // Aplicar velocidad de reproducción al audio
        if (this.currentAudio) {
            this.currentAudio.playbackRate = this.playbackSpeed;
        }
        
        // No es necesario recrear la transcripción, el offset se aplica en seekToTimeWithOffset
        // Solo mostrar mensaje de confirmación
        console.log(`Ajustes aplicados - Offset: ${this.timeOffset}s, Velocidad: ${this.playbackSpeed}x`);
        
        // Mostrar feedback visual al usuario
        const applySyncButton = document.getElementById('apply-sync-button');
        if (applySyncButton) {
            const originalText = applySyncButton.innerHTML;
            applySyncButton.innerHTML = '<i class="fas fa-check me-1"></i>Aplicado';
            applySyncButton.classList.add('btn-success');
            applySyncButton.classList.remove('btn-primary');
            
            setTimeout(() => {
                applySyncButton.innerHTML = originalText;
                applySyncButton.classList.remove('btn-success');
                applySyncButton.classList.add('btn-primary');
            }, 1500);
        }
    }

    // Modificar seekToTime para incluir el offset
    seekToTimeWithOffset(time) {
        const adjustedTime = Math.max(0, time + this.timeOffset);
        this.seekToTime(adjustedTime);
    }
}

// Inicialización global
window.initSyncEditor = function() {
    if (!window.syncEditorModule) {
        window.syncEditorModule = new SyncEditorModule();
        window.syncEditorModule.init();
        console.log('SyncEditorModule inicializado globalmente');
    }
    return window.syncEditorModule;
};

// Exportar para uso en módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SyncEditorModule;
}