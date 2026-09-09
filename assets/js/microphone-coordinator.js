// Coordinador avanzado de micrófono con soporte para uso simultáneo
const MicrophoneCoordinator = {
    // Estado del coordinador
    state: {
        audioStream: null,
        audioContext: null,
        mediaRecorder: null,
        recognition: null,
        isStreamActive: false,
        activeFeatures: {
            recording: false,
            speech: false,
            sync: false
        }
    },

    // Inicializar el coordinador
    init: async function() {
        console.log('Inicializando coordinador avanzado de micrófono...');
        try {
            await this.initializeAudioStream();
            this.updateUI();
            console.log('Coordinador de micrófono inicializado correctamente');
        } catch (error) {
            console.error('Error al inicializar coordinador:', error);
            this.showMessage('audioStatus', 'Error al acceder al micrófono. Verifique los permisos.', 'error');
            this.showMessage('speechStatus', 'Error al acceder al micrófono. Verifique los permisos.', 'error');
        }
    },

    // Inicializar stream de audio compartido
    initializeAudioStream: async function() {
        if (this.state.audioStream) {
            return this.state.audioStream;
        }

        try {
            // Solicitar acceso al micrófono
            this.state.audioStream = await navigator.mediaDevices.getUserMedia({ 
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                } 
            });

            // Crear contexto de audio
            this.state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            
            this.state.isStreamActive = true;
            console.log('Stream de audio compartido inicializado');
            
            return this.state.audioStream;
        } catch (error) {
            console.error('Error al inicializar stream de audio:', error);
            throw error;
        }
    },

    // Obtener stream para grabación
    getRecordingStream: function() {
        return this.state.audioStream;
    },

    // Obtener stream para reconocimiento de voz
    getSpeechStream: function() {
        return this.state.audioStream;
    },

    // Activar grabación
    enableRecording: function(callback) {
        if (!this.state.isStreamActive) {
            this.showMessage('audioStatus', 'Stream de audio no disponible', 'error');
            callback(false);
            return;
        }

        this.state.activeFeatures.recording = true;
        this.updateUI();
        console.log('Grabación activada');
        callback(true);
    },

    // Desactivar grabación
    disableRecording: function() {
        this.state.activeFeatures.recording = false;
        this.updateUI();
        console.log('Grabación desactivada');
    },

    // Activar reconocimiento de voz
    enableSpeech: function(callback) {
        if (!this.state.isStreamActive) {
            this.showMessage('speechStatus', 'Stream de audio no disponible', 'error');
            callback(false);
            return;
        }

        this.state.activeFeatures.speech = true;
        this.updateUI();
        console.log('Reconocimiento de voz activado');
        callback(true);
    },

    // Desactivar reconocimiento de voz
    disableSpeech: function() {
        this.state.activeFeatures.speech = false;
        this.updateUI();
        console.log('Reconocimiento de voz desactivado');
    },

    // Activar grabación SYNC
    enableSync: function(callback) {
        if (!this.state.isStreamActive) {
            this.showMessage('syncStatus', 'Stream de audio no disponible', 'error');
            callback(false);
            return;
        }

        this.state.activeFeatures.sync = true;
        this.updateUI();
        console.log('Grabación SYNC activada');
        callback(true);
    },

    // Desactivar grabación SYNC
    disableSync: function() {
        this.state.activeFeatures.sync = false;
        this.updateUI();
        console.log('Grabación SYNC desactivada');
    },

    // Verificar si una característica está activa
    isFeatureActive: function(feature) {
        return this.state.activeFeatures[feature] || false;
    },

    // Obtener estado del stream
    isStreamReady: function() {
        return this.state.isStreamActive && this.state.audioStream;
    },

    // Mostrar mensaje en un elemento específico
    showMessage: function(elementId, message, type = 'info') {
        const element = document.getElementById(elementId);
        if (element) {
            const alertClass = type === 'warning' ? 'alert-warning' : 
                              type === 'error' ? 'alert-danger' : 
                              type === 'success' ? 'alert-success' : 'alert-info';
            
            element.innerHTML = `<div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
        }
    },

    // Limpiar mensaje de un elemento específico
    clearMessage: function(elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            element.innerHTML = '';
        }
    },

    // Actualizar interfaz de usuario
    updateUI: function() {
        const audioButton = document.getElementById('recordButton');
        const speechButton = document.getElementById('dictateButton');
        
        if (audioButton && speechButton) {
            // Ambos botones están siempre habilitados si el stream está listo
            const streamReady = this.isStreamReady();
            
            audioButton.disabled = !streamReady;
            speechButton.disabled = !streamReady;
            
            if (!streamReady) {
                audioButton.title = 'Micrófono no disponible';
                speechButton.title = 'Micrófono no disponible';
            } else {
                audioButton.title = 'Grabación de audio disponible';
                speechButton.title = 'Reconocimiento de voz disponible';
            }
        }

        // Mostrar estado de características activas
        this.updateStatusIndicators();
    },

    // Actualizar indicadores de estado
    updateStatusIndicators: function() {
        const features = [];
        
        if (this.state.activeFeatures.recording) {
            features.push('🔴 Grabando');
        }
        
        if (this.state.activeFeatures.speech) {
            features.push('🎤 Dictando');
        }
        
        if (features.length > 0) {
            const statusMessage = `Activo: ${features.join(' | ')}`;
            this.showMessage('microphoneStatus', statusMessage, 'success');
        } else {
            this.clearMessage('microphoneStatus');
        }
    },

    // Limpiar recursos
    cleanup: function() {
        if (this.state.audioStream) {
            this.state.audioStream.getTracks().forEach(track => track.stop());
            this.state.audioStream = null;
        }
        
        if (this.state.audioContext) {
            this.state.audioContext.close();
            this.state.audioContext = null;
        }
        
        this.state.isStreamActive = false;
        this.state.activeFeatures = { recording: false, speech: false };
        
        console.log('Recursos del coordinador liberados');
    }
};