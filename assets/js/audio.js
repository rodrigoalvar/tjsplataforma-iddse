// Módulo de grabación de audio
const AudioModule = {
    // Estado de la grabación
    recordingState: {
        mediaRecorder: null,
        audioChunks: [],
        isRecording: false,
        isPaused: false,
        stream: null,
        currentAudioBlob: null
    },

    // Inicializar el grabador
    initRecorder: function() {
        // Inicializar referencias del DOM (solo si existen, para compatibilidad con workspace)
        const timerEl = document.getElementById('timer');
        if (timerEl) {
            this.timer.display = timerEl;
        }
        
        // El stream será proporcionado por el coordinador cuando sea necesario
        console.log('Módulo de audio inicializado - usando stream compartido');
        
        // Solo actualizar UI si los elementos existen (para compatibilidad con workspace)
        const recordButton = document.getElementById('recordButton');
        const stopButton = document.getElementById('stopButton');
        if (recordButton && stopButton) {
            this.updateUI();
        } else {
            console.log('ℹ️ Elementos de UI del editor no encontrados (probablemente en workspace)');
        }
    },

    // Configurar MediaRecorder con el stream compartido
    setupMediaRecorder: function(stream) {
        try {
            this.recordingState.stream = stream;
            
            // Configurar opciones de grabación
            // Nota: MediaRecorder no puede grabar directamente en MP3 o WAV
            // Los navegadores solo soportan WebM, OGG, o MP4
            // Usaremos WebM con codec opus (mejor calidad y compatibilidad)
            const options = {
                mimeType: 'audio/webm;codecs=opus',
                audioBitsPerSecond: 128000
            };
            
            // Verificar soporte del formato, usar alternativo si es necesario
            if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                options.mimeType = 'audio/webm';
                if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                    options.mimeType = 'audio/mp4';
                    if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                        // Usar formato por defecto del navegador
                        options.mimeType = '';
                    }
                }
            }
            
            // Crear MediaRecorder con las opciones configuradas
            if (options.mimeType) {
                this.recordingState.mediaRecorder = new MediaRecorder(stream, options);
                console.log('MediaRecorder configurado con:', options.mimeType);
            } else {
                this.recordingState.mediaRecorder = new MediaRecorder(stream);
                console.log('MediaRecorder configurado con formato por defecto del navegador');
            }

            this.recordingState.mediaRecorder.ondataavailable = (event) => {
                this.recordingState.audioChunks.push(event.data);
            };

            this.recordingState.mediaRecorder.onstop = () => {
                // Obtener el tipo MIME real del MediaRecorder (puede ser webm, mp4, etc.)
                const actualMimeType = this.recordingState.mediaRecorder.mimeType || 'audio/webm';
                const audioBlob = new Blob(this.recordingState.audioChunks, { type: actualMimeType });
                const audioUrl = URL.createObjectURL(audioBlob);
                this.recordingState.currentAudioBlob = audioBlob; // Almacenar el blob
                this.recordingState.currentMimeType = actualMimeType; // Guardar el tipo MIME real
                this.displayRecording(audioUrl);
                this.recordingState.audioChunks = [];
                
                // Guardar como archivo temporal
                if (window.TempAudioManager) {
                    TempAudioManager.saveAudioFile(audioBlob, 'regular')
                        .then(result => {
                            console.log('Audio guardado como archivo temporal:', result);
                        })
                        .catch(error => {
                            console.error('Error al guardar audio temporal:', error);
                        });
                }
                
                // Marcar cambios no guardados
                if (window.PersistenceModule) {
                    window.PersistenceModule.markUnsavedChanges(true);
                }
            };

            return true;
        } catch (error) {
            console.error('Error al configurar MediaRecorder:', error);
            return false;
        }
    },

    // Iniciar grabación
    startRecording: function() {
        // Activar grabación a través del coordinador
        MicrophoneCoordinator.enableRecording((success) => {
            if (success) {
                const stream = MicrophoneCoordinator.getRecordingStream();
                if (stream && this.setupMediaRecorder(stream)) {
            this.recordingState.mediaRecorder.start();
            this.recordingState.isRecording = true;
            this.recordingState.isPaused = false;
            this.updateUI();
            this.startTimer();
            console.log('Grabación iniciada con stream compartido');
                } else {
                    console.error('No se pudo configurar la grabación');
                    MicrophoneCoordinator.disableRecording();
                }
            }
        });
    },

    // Pausar grabación
    pauseRecording: function() {
        if (this.recordingState.mediaRecorder && this.recordingState.isRecording && !this.recordingState.isPaused) {
            // Verificar si el MediaRecorder soporta pause
            if (typeof this.recordingState.mediaRecorder.pause === 'function') {
                this.recordingState.mediaRecorder.pause();
                this.recordingState.isPaused = true;
                this.updateUI();
                this.stopTimer(); // Pausar el timer
                console.log('Grabación pausada');
            } else {
                console.warn('MediaRecorder no soporta pausa en este navegador');
            }
        }
    },

    // Reanudar grabación
    resumeRecording: function() {
        if (this.recordingState.mediaRecorder && this.recordingState.isRecording && this.recordingState.isPaused) {
            // Verificar si el MediaRecorder soporta resume
            if (typeof this.recordingState.mediaRecorder.resume === 'function') {
                this.recordingState.mediaRecorder.resume();
                this.recordingState.isPaused = false;
                this.updateUI();
                this.startTimer(); // Reanudar el timer
                console.log('Grabación reanudada');
            } else {
                console.warn('MediaRecorder no soporta reanudación en este navegador');
            }
        }
    },

    // Detener grabación
    stopRecording: function() {
        if (this.recordingState.mediaRecorder && this.recordingState.isRecording) {
            this.recordingState.mediaRecorder.stop();
            this.recordingState.isRecording = false;
            this.recordingState.isPaused = false;
            // Desactivar grabación en el coordinador
            MicrophoneCoordinator.disableRecording();
            this.updateUI();
            this.stopTimer();
            console.log('Grabación detenida');
        }
    },

    // Mostrar grabación
    displayRecording: function(audioUrl) {
        console.log('🔊 AudioModule.displayRecording llamado con URL:', audioUrl);
        const audioPlayer = document.getElementById('audioPlayer');
        const audioContainer = document.getElementById('audioContainer');
        
        // Solo actualizar si los elementos existen (para compatibilidad con workspace)
        if (audioPlayer && audioContainer) {
            audioPlayer.src = audioUrl;
            audioContainer.style.display = 'block';
        } else {
            // Los elementos no existen, probablemente estamos en workspace
            // La extensión en workspace.html manejará esto
            console.log('ℹ️ Elementos de audio del editor no encontrados (probablemente en workspace)');
            
            // Intentar llamar a la extensión del workspace si existe
            if (window.parent && window.parent.WorkspaceManager && typeof window.parent.WorkspaceManager.addRecordingToList === 'function') {
                // Obtener el panelId activo
                const activePanelId = window.parent.WorkspaceManager.currentAudioPanelId || 
                                    (window.parent.workspaceAudioPanels ? Object.keys(window.parent.workspaceAudioPanels)[0] : null);
                if (activePanelId) {
                    console.log('🔗 Llamando directamente a addRecordingToList desde audio.js');
                    window.parent.WorkspaceManager.addRecordingToList(activePanelId, audioUrl);
                }
            }
        }
    },

    // Actualizar interfaz
    updateUI: function() {
        const recordButton = document.getElementById('recordButton');
        const stopButton = document.getElementById('stopButton');
        
        // Solo actualizar si los elementos existen (para compatibilidad con workspace)
        if (!recordButton || !stopButton) {
            return; // Los elementos no existen, probablemente estamos en workspace
        }
        
        if (this.recordingState.isRecording) {
            recordButton.disabled = true;
            stopButton.disabled = false;
            recordButton.textContent = 'Grabando...';
        } else {
            recordButton.disabled = false;
            stopButton.disabled = true;
            recordButton.textContent = 'Iniciar Grabación';
        }
    },

    // Timer para la grabación
    timer: {
        interval: null,
        seconds: 0,
        display: null
    },

    // Iniciar timer
    startTimer: function() {
        this.timer.seconds = 0;
        this.timer.interval = setInterval(() => {
            this.timer.seconds++;
            const minutes = Math.floor(this.timer.seconds / 60);
            const seconds = this.timer.seconds % 60;
            const timeString = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            // Actualizar display solo si existe (para compatibilidad con workspace)
            if (this.timer.display) {
                this.timer.display.textContent = timeString;
            }
        }, 1000);
    },

    // Detener timer
    stopTimer: function() {
        if (this.timer.interval) {
            clearInterval(this.timer.interval);
            this.timer.interval = null;
        }
    },

    // Limpiar audio actual
    clearCurrentAudio: function() {
        this.recordingState.currentAudioBlob = null;
        const audioContainer = document.getElementById('audioContainer');
        if (audioContainer) {
            audioContainer.style.display = 'none';
        }
        const audioPlayer = document.getElementById('audioPlayer');
        if (audioPlayer) {
            audioPlayer.src = '';
        }
    },
    
    // Limpiar recursos
    cleanup: function() {
        if (this.recordingState.stream) {
            this.recordingState.stream.getTracks().forEach(track => track.stop());
        }
        this.stopTimer();
        this.clearCurrentAudio();
    }
};