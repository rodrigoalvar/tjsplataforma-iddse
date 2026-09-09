/**
 * Audio Transcription Module
 * Handles audio file upload, processing, and transcription
 */

class AudioTranscriptionModule {
    constructor() {
        this.fileQueue = [];
        this.isProcessing = false;
        this.supportedFormats = ['.mp3', '.wav', '.m4a', '.webm', '.mp4', '.ogg'];
        this.maxFileSize = 25 * 1024 * 1024; // 25MB
        this.apiKey = null;
        this.audioPlayer = null;
        this.currentAudioFile = null;
        this.isPlaying = false;
        this.isMuted = false;
        
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadApiKey();
        this.updateEngineVisibility();
        this.initAudioPlayer();
    }

    setupEventListeners() {
        // File input and dropzone
        const fileInput = document.getElementById('audioFileInput');
        const selectBtn = document.getElementById('selectAudioBtn');
        const dropzone = document.getElementById('audioDropzone');

        if (selectBtn && fileInput) {
            selectBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                fileInput.click();
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', (e) => this.handleFileSelect(e.target.files));
        }

        if (dropzone) {
            // Drag and drop events
            dropzone.addEventListener('dragover', (e) => this.handleDragOver(e));
            dropzone.addEventListener('dragleave', (e) => this.handleDragLeave(e));
            dropzone.addEventListener('drop', (e) => this.handleDrop(e));
            dropzone.addEventListener('click', (e) => {
                // Only trigger file input if clicking on dropzone directly, not on child elements
                if (e.target === dropzone || e.target.closest('.upload-content') && !e.target.closest('button')) {
                    fileInput?.click();
                }
            });
        }

        // Engine selection
        const engineSelect = document.getElementById('transcriptionEngine');
        if (engineSelect) {
            engineSelect.addEventListener('change', () => this.updateEngineVisibility());
        }

        // API Key toggle
        const toggleApiKey = document.getElementById('toggleApiKey');
        const apiKeyInput = document.getElementById('openaiApiKey');
        if (toggleApiKey && apiKeyInput) {
            toggleApiKey.addEventListener('click', () => this.toggleApiKeyVisibility());
            apiKeyInput.addEventListener('input', (e) => this.saveApiKey(e.target.value));
        }

        // Control buttons
        const startBtn = document.getElementById('startTranscriptionBtn');
        const clearBtn = document.getElementById('clearQueueBtn');
        
        if (startBtn) {
            startBtn.addEventListener('click', () => this.startTranscription());
        }
        
        if (clearBtn) {
            clearBtn.addEventListener('click', () => this.clearQueue());
        }

        // Web Speech controls
        const muteAudioCheckbox = document.getElementById('muteAudio');
        const muteAudioWarning = document.getElementById('muteAudioWarning');
        
        if (muteAudioCheckbox && muteAudioWarning) {
            muteAudioCheckbox.addEventListener('change', () => {
                // Show/hide warning based on mute status
                if (muteAudioCheckbox.checked) {
                    muteAudioWarning.style.display = 'block';
                } else {
                    muteAudioWarning.style.display = 'none';
                }
            });
            
            // Show warning initially if mute is checked by default
            if (muteAudioCheckbox.checked) {
                muteAudioWarning.style.display = 'block';
            }
        }
    }

    handleDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        const dropzone = document.getElementById('audioDropzone');
        if (dropzone) {
            dropzone.classList.add('dragover');
        }
    }

    handleDragLeave(e) {
        e.preventDefault();
        e.stopPropagation();
        const dropzone = document.getElementById('audioDropzone');
        if (dropzone && !dropzone.contains(e.relatedTarget)) {
            dropzone.classList.remove('dragover');
        }
    }

    handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        const dropzone = document.getElementById('audioDropzone');
        if (dropzone) {
            dropzone.classList.remove('dragover');
        }
        
        const files = Array.from(e.dataTransfer.files);
        this.handleFileSelect(files);
    }

    handleFileSelect(files) {
        const validFiles = Array.from(files).filter(file => this.validateFile(file));
        
        if (validFiles.length === 0) {
            this.showNotification('No se seleccionaron archivos válidos', 'warning');
            return;
        }

        validFiles.forEach(file => this.addFileToQueue(file));
        this.updateQueueDisplay();
        this.updateStartButton();
    }

    validateFile(file) {
        // Check file type
        const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
        if (!this.supportedFormats.includes(fileExtension)) {
            this.showNotification(`Formato no soportado: ${file.name}`, 'error');
            return false;
        }

        // Check file size
        if (file.size > this.maxFileSize) {
            this.showNotification(`Archivo muy grande: ${file.name} (máximo 25MB)`, 'error');
            return false;
        }

        // Check if already in queue
        if (this.fileQueue.some(item => item.file.name === file.name && item.file.size === file.size)) {
            this.showNotification(`Archivo ya en cola: ${file.name}`, 'warning');
            return false;
        }

        return true;
    }

    addFileToQueue(file) {
        const fileItem = {
            id: Date.now() + Math.random(),
            file: file,
            status: 'pending',
            progress: 0,
            result: null,
            error: null
        };
        
        this.fileQueue.push(fileItem);
        
        // Load first file in audio player if no file is currently loaded
        if (!this.currentAudioFile && this.fileQueue.length === 1) {
            this.loadAudioFile(file);
        }
    }

    updateQueueDisplay() {
        const queueSection = document.getElementById('fileQueueSection');
        const queueContainer = document.getElementById('fileQueue');
        
        if (!queueContainer) return;

        if (this.fileQueue.length === 0) {
            if (queueSection) queueSection.style.display = 'none';
            return;
        }

        if (queueSection) queueSection.style.display = 'block';
        
        queueContainer.innerHTML = this.fileQueue.map(item => this.renderFileItem(item)).join('');
        
        // Add event listeners for file actions
        this.fileQueue.forEach(item => {
            const removeBtn = document.getElementById(`remove-${item.id}`);
            const retryBtn = document.getElementById(`retry-${item.id}`);
            
            if (removeBtn) {
                removeBtn.addEventListener('click', () => this.removeFileFromQueue(item.id));
            }
            
            if (retryBtn) {
                retryBtn.addEventListener('click', () => this.retryFile(item.id));
            }
        });
    }

    renderFileItem(item) {
        const statusClass = item.status;
        const statusText = {
            'pending': 'Pendiente',
            'processing': 'Procesando',
            'completed': 'Completado',
            'error': 'Error'
        }[item.status] || 'Desconocido';

        const fileSize = this.formatFileSize(item.file.size);
        const progressBar = item.status === 'processing' ? 
            `<div class="file-progress"><div class="file-progress-bar" style="width: ${item.progress}%"></div></div>` : '';

        return `
            <div class="file-item" data-file-id="${item.id}">
                <div class="file-info" onclick="audioTranscription.loadAudioFromQueue(${item.id})" style="cursor: pointer;">
                    <div class="file-icon audio">
                        <i class="fas fa-file-audio"></i>
                    </div>
                    <div class="file-details">
                        <p class="file-name" title="${item.file.name}">${item.file.name}</p>
                        <p class="file-size">${fileSize}</p>
                        ${progressBar}
                    </div>
                </div>
                <div class="file-status">
                    <span class="status-badge ${statusClass}">${statusText}</span>
                </div>
                <div class="file-actions">
                    <button class="btn-file-action play" onclick="audioTranscription.loadAudioFromQueue(${item.id})" title="Cargar en reproductor">
                        <i class="fas fa-play-circle"></i>
                    </button>
                    ${item.status === 'error' ? 
                        `<button class="btn-file-action retry" id="retry-${item.id}" title="Reintentar">
                            <i class="fas fa-redo"></i>
                        </button>` : ''}
                    <button class="btn-file-action remove" id="remove-${item.id}" title="Eliminar">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `;
    }

    removeFileFromQueue(fileId) {
        this.fileQueue = this.fileQueue.filter(item => item.id !== fileId);
        this.updateQueueDisplay();
        this.updateStartButton();
    }

    retryFile(fileId) {
        const fileItem = this.fileQueue.find(item => item.id === fileId);
        if (fileItem) {
            fileItem.status = 'pending';
            fileItem.progress = 0;
            fileItem.error = null;
            this.updateQueueDisplay();
            this.updateStartButton();
        }
    }

    clearQueue() {
        if (this.isProcessing) {
            this.showNotification('No se puede limpiar la cola durante el procesamiento', 'warning');
            return;
        }
        
        this.fileQueue = [];
        this.updateQueueDisplay();
        this.updateStartButton();
        this.hideResults();
    }

    updateStartButton() {
        const startBtn = document.getElementById('startTranscriptionBtn');
        if (!startBtn) return;

        const hasPendingFiles = this.fileQueue.some(item => item.status === 'pending' || item.status === 'error');
        const engine = document.getElementById('transcriptionEngine')?.value;
        const hasApiKey = engine !== 'whisper-api' || this.apiKey;

        startBtn.disabled = !hasPendingFiles || this.isProcessing || !hasApiKey;
        
        if (this.isProcessing) {
            startBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando...';
        } else {
            startBtn.innerHTML = '<i class="fas fa-play me-2"></i>Iniciar Transcripción';
        }
    }

    async startTranscription() {
        if (this.isProcessing) return;
        
        const engine = document.getElementById('transcriptionEngine')?.value;
        if (engine === 'whisper-api' && !this.apiKey) {
            this.showNotification('Se requiere una API Key de OpenAI para usar Whisper API', 'error');
            return;
        }

        this.isProcessing = true;
        this.updateStartButton();

        const pendingFiles = this.fileQueue.filter(item => item.status === 'pending' || item.status === 'error');
        
        for (const fileItem of pendingFiles) {
            try {
                await this.transcribeFile(fileItem, engine);
            } catch (error) {
                console.error('Error transcribing file:', error);
                fileItem.status = 'error';
                fileItem.error = error.message;
            }
            this.updateQueueDisplay();
        }

        this.isProcessing = false;
        this.updateStartButton();
        this.showResults();
    }

    async transcribeFile(fileItem, engine) {
        fileItem.status = 'processing';
        fileItem.progress = 0;
        this.updateQueueDisplay();

        try {
            let result;
            
            switch (engine) {
                case 'whisper-api':
                    result = await this.transcribeWithWhisperAPI(fileItem);
                    break;
                case 'web-speech':
                    result = await this.transcribeWithWebSpeech(fileItem);
                    break;
                default:
                    throw new Error('Motor de transcripción no soportado');
            }

            fileItem.status = 'completed';
            fileItem.progress = 100;
            fileItem.result = result;
            
            // Auto-insert to editor if enabled
            const insertToEditor = document.getElementById('insertToEditor')?.checked;
            if (insertToEditor && result) {
                this.insertToEditor(result);
            }
            
        } catch (error) {
            fileItem.status = 'error';
            fileItem.error = error.message;
            throw error;
        }
    }

    async transcribeWithWhisperAPI(fileItem) {
        if (!this.apiKey) {
            throw new Error('API Key de OpenAI requerida');
        }

        const formData = new FormData();
        formData.append('file', fileItem.file);
        formData.append('model', 'whisper-1');
        
        const language = document.getElementById('audioLanguage')?.value;
        if (language && language !== 'auto') {
            formData.append('language', language);
        }
        
        const includeTimestamps = document.getElementById('includeTimestamps')?.checked;
        if (includeTimestamps) {
            formData.append('response_format', 'verbose_json');
            formData.append('timestamp_granularities[]', 'word');
        }

        const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.apiKey}`
            },
            body: formData
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({ error: { message: 'Error de red' } }));
            throw new Error(error.error?.message || `Error HTTP: ${response.status}`);
        }

        const result = await response.json();
        
        return {
            text: result.text,
            segments: result.segments || null,
            words: result.words || null,
            language: result.language || language,
            duration: result.duration || null
        };
    }

    async transcribeWithWebSpeech(fileItem) {
        return new Promise((resolve, reject) => {
            if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
                reject(new Error('Web Speech API no soportada en este navegador'));
                return;
            }

            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            const recognition = new SpeechRecognition();
            
            const language = document.getElementById('audioLanguage')?.value || 'es-ES';
            recognition.lang = language === 'es' ? 'es-ES' : language === 'en' ? 'en-US' : 'es-ES';
            recognition.continuous = true;
            recognition.interimResults = false;

            // Use existing audio player if file is already loaded, otherwise create new audio element
            let audio;
            let audioUrl = null;
            let useMainPlayer = false;
            
            if (this.currentAudioFile && this.currentAudioFile.name === fileItem.file.name && this.audioPlayer) {
                audio = this.audioPlayer;
                useMainPlayer = true;
            } else {
                audio = new Audio();
                audioUrl = URL.createObjectURL(fileItem.file);
                audio.src = audioUrl;
            }
            
            // Apply audio controls settings
            const muteAudio = document.getElementById('muteAudio')?.checked ?? true;
            const playbackSpeed = parseFloat(document.getElementById('playbackSpeed')?.value ?? '1');
            
            audio.muted = muteAudio;
            audio.playbackRate = playbackSpeed;

            let transcript = '';
            let startTime = Date.now();

            recognition.onstart = () => {
                fileItem.progress = 10;
                this.updateQueueDisplay();
            };

            recognition.onresult = (event) => {
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) {
                        transcript += event.results[i][0].transcript + ' ';
                    }
                }
                
                // Update progress based on audio progress if available
                if (audio.duration && audio.currentTime) {
                    fileItem.progress = Math.min(90, (audio.currentTime / audio.duration) * 90);
                } else {
                    fileItem.progress = Math.min(90, fileItem.progress + 10);
                }
                this.updateQueueDisplay();
            };

            recognition.onerror = (event) => {
                if (audioUrl) {
                    URL.revokeObjectURL(audioUrl);
                }
                
                // Handle specific error cases
                if (event.error === 'no-speech') {
                    if (muteAudio) {
                        reject(new Error('No se puede transcribir con audio silenciado. La API de Web Speech necesita escuchar el audio para funcionar. Desactiva la opción "Silenciar audio" o usa la API de Whisper.'));
                    } else {
                        reject(new Error('No se detectó voz en el audio. Verifica que el archivo contenga audio claro.'));
                    }
                } else if (event.error === 'audio-capture') {
                    reject(new Error('Error al capturar el audio. Verifica que el archivo sea válido.'));
                } else if (event.error === 'not-allowed') {
                    reject(new Error('Permisos de micrófono denegados. Permite el acceso al micrófono en tu navegador.'));
                } else {
                    reject(new Error(`Error de reconocimiento: ${event.error}`));
                }
            };

            recognition.onend = () => {
                if (audioUrl) {
                    URL.revokeObjectURL(audioUrl);
                }
                if (transcript.trim()) {
                    resolve({
                        text: transcript.trim(),
                        segments: null,
                        words: null,
                        language: recognition.lang,
                        duration: (Date.now() - startTime) / 1000
                    });
                } else {
                    reject(new Error('No se pudo transcribir el audio'));
                }
            };

            // Start recognition and play audio
            recognition.start();
            
            if (useMainPlayer) {
                // If using main player and it's not playing, start playback
                if (!this.isPlaying) {
                    this.togglePlayPause();
                }
            } else {
                // Use separate audio element
                audio.play().catch(error => {
                    recognition.stop();
                    reject(new Error('Error reproduciendo audio: ' + error.message));
                });
                
                // Stop recognition when audio ends
                audio.onended = () => {
                    setTimeout(() => recognition.stop(), 1000);
                };
            }
        });
    }

    insertToEditor(result) {
        // Try to insert into the main editor if available
        const editor = document.getElementById('editor');
        const transcriptionArea = document.getElementById('transcriptionText');
        
        if (editor && typeof editor.value !== 'undefined') {
            const currentText = editor.value;
            const newText = currentText ? currentText + '\n\n' + result.text : result.text;
            editor.value = newText;
            
            // Trigger change event
            const event = new Event('input', { bubbles: true });
            editor.dispatchEvent(event);
        } else if (transcriptionArea) {
            const currentText = transcriptionArea.value;
            const newText = currentText ? currentText + '\n\n' + result.text : result.text;
            transcriptionArea.value = newText;
            
            // Trigger change event
            const event = new Event('input', { bubbles: true });
            transcriptionArea.dispatchEvent(event);
        }
    }

    showResults() {
        const resultsSection = document.getElementById('transcriptionResults');
        const outputContainer = document.getElementById('transcriptionOutput');
        
        if (!resultsSection || !outputContainer) return;

        const completedFiles = this.fileQueue.filter(item => item.status === 'completed' && item.result);
        
        if (completedFiles.length === 0) {
            resultsSection.style.display = 'none';
            return;
        }

        resultsSection.style.display = 'block';
        outputContainer.innerHTML = completedFiles.map(item => this.renderTranscriptionResult(item)).join('');
        
        // Add event listeners for result actions
        completedFiles.forEach(item => {
            const copyBtn = document.getElementById(`copy-${item.id}`);
            const insertBtn = document.getElementById(`insert-${item.id}`);
            
            if (copyBtn) {
                copyBtn.addEventListener('click', () => this.copyToClipboard(item.result.text));
            }
            
            if (insertBtn) {
                insertBtn.addEventListener('click', () => this.insertToEditor(item.result));
            }
        });
    }

    renderTranscriptionResult(item) {
        const result = item.result;
        const duration = result.duration ? `${Math.round(result.duration)}s` : 'N/A';
        
        return `
            <div class="transcription-item">
                <div class="transcription-header">
                    <h6 class="transcription-filename">${item.file.name}</h6>
                    <div class="transcription-actions">
                        <button class="btn-transcription-action" id="copy-${item.id}" title="Copiar texto">
                            <i class="fas fa-copy"></i> Copiar
                        </button>
                        <button class="btn-transcription-action" id="insert-${item.id}" title="Insertar en editor">
                            <i class="fas fa-plus"></i> Insertar
                        </button>
                    </div>
                </div>
                <div class="transcription-text">
                    ${result.text}
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i>Duración: ${duration} | 
                        <i class="fas fa-language me-1"></i>Idioma: ${result.language || 'N/A'}
                    </small>
                </div>
            </div>
        `;
    }

    hideResults() {
        const resultsSection = document.getElementById('transcriptionResults');
        if (resultsSection) {
            resultsSection.style.display = 'none';
        }
    }

    updateEngineVisibility() {
        const engine = document.getElementById('transcriptionEngine')?.value;
        const apiKeySection = document.getElementById('apiKeySection');
        const webSpeechControls = document.getElementById('webSpeechControls');
        
        if (apiKeySection) {
            if (engine === 'whisper-api') {
                apiKeySection.style.display = 'block';
                apiKeySection.classList.remove('hidden');
            } else {
                apiKeySection.style.display = 'none';
                apiKeySection.classList.add('hidden');
            }
        }
        
        if (webSpeechControls) {
            if (engine === 'web-speech') {
                webSpeechControls.style.display = 'block';
            } else {
                webSpeechControls.style.display = 'none';
            }
        }
        
        this.updateStartButton();
    }

    toggleApiKeyVisibility() {
        const apiKeyInput = document.getElementById('openaiApiKey');
        const toggleBtn = document.getElementById('toggleApiKey');
        
        if (apiKeyInput && toggleBtn) {
            const isPassword = apiKeyInput.type === 'password';
            apiKeyInput.type = isPassword ? 'text' : 'password';
            toggleBtn.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        }
    }

    saveApiKey(apiKey) {
        this.apiKey = apiKey;
        if (apiKey) {
            localStorage.setItem('openai_api_key', apiKey);
        } else {
            localStorage.removeItem('openai_api_key');
        }
        this.updateStartButton();
    }

    loadApiKey() {
        const savedKey = localStorage.getItem('openai_api_key');
        if (savedKey) {
            this.apiKey = savedKey;
            const apiKeyInput = document.getElementById('openaiApiKey');
            if (apiKeyInput) {
                apiKeyInput.value = savedKey;
            }
        }
    }

    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showNotification('Texto copiado al portapapeles', 'success');
        } catch (error) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            this.showNotification('Texto copiado al portapapeles', 'success');
        }
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    // Audio Player Methods
    initAudioPlayer() {
        this.audioPlayer = document.getElementById('audioPlayer');
        if (!this.audioPlayer) return;

        // Get control elements
        const playPauseBtn = document.getElementById('playPauseBtn');
        const stopBtn = document.getElementById('stopBtn');
        const rewindBtn = document.getElementById('rewindBtn');
        const forwardBtn = document.getElementById('forwardBtn');
        const volumeControl = document.getElementById('volumeControl');
        const muteBtn = document.getElementById('muteBtn');
        const audioSeeker = document.getElementById('audioSeeker');

        // Set up event listeners
        if (playPauseBtn) {
            playPauseBtn.addEventListener('click', () => this.togglePlayPause());
        }
        if (stopBtn) {
            stopBtn.addEventListener('click', () => this.stopAudio());
        }
        if (rewindBtn) {
            rewindBtn.addEventListener('click', () => this.rewindAudio());
        }
        if (forwardBtn) {
            forwardBtn.addEventListener('click', () => this.forwardAudio());
        }
        if (volumeControl) {
            volumeControl.addEventListener('input', (e) => this.setVolume(e.target.value));
        }
        if (muteBtn) {
            muteBtn.addEventListener('click', () => this.toggleMute());
        }
        if (audioSeeker) {
            audioSeeker.addEventListener('input', (e) => this.seekAudio(e.target.value));
        }

        // Audio player event listeners
        this.audioPlayer.addEventListener('loadedmetadata', () => this.updateAudioInfo());
        this.audioPlayer.addEventListener('timeupdate', () => this.updateProgress());
        this.audioPlayer.addEventListener('ended', () => this.onAudioEnded());
        this.audioPlayer.addEventListener('error', (e) => this.onAudioError(e));

        // Set initial volume
        this.audioPlayer.volume = 0.5;
        
        // Speed control
        const playbackSpeedSelect = document.getElementById('playbackSpeedSelect');
        if (playbackSpeedSelect) {
            playbackSpeedSelect.addEventListener('change', (e) => this.setPlaybackSpeed(e.target.value));
        }
        
        // Hotkeys configuration button
        const configureHotkeysBtn = document.getElementById('configureHotkeysBtn');
        if (configureHotkeysBtn) {
            configureHotkeysBtn.addEventListener('click', () => this.showHotkeysConfig());
        }
        
        // Initialize hotkeys
        this.initHotkeys();
    }

    loadAudioFile(file) {
        if (!this.audioPlayer || !file) return;

        this.currentAudioFile = file;
        const audioUrl = URL.createObjectURL(file);
        this.audioPlayer.src = audioUrl;
        
        // Update UI
        const currentFileName = document.getElementById('currentFileName');
        const audioPlayerSection = document.getElementById('audioPlayerSection');
        
        if (currentFileName) {
            currentFileName.textContent = file.name;
        }
        if (audioPlayerSection) {
            audioPlayerSection.style.display = 'block';
        }
        
        this.enableAudioControls(true);
    }

    loadAudioFromQueue(fileId) {
        const fileItem = this.fileQueue.find(item => item.id === fileId);
        if (fileItem) {
            this.loadAudioFile(fileItem.file);
            this.showNotification(`Archivo cargado: ${fileItem.file.name}`, 'success');
        }
    }

    // Sync transcription with audio player progress
    syncTranscriptionWithAudio() {
        if (!this.audioPlayer || !this.currentAudioFile) return;
        
        // Find the file item being transcribed
        const currentFileItem = this.fileQueue.find(item => 
            item.file.name === this.currentAudioFile.name && 
            item.status === 'processing'
        );
        
        if (currentFileItem && this.audioPlayer.duration) {
            const progress = (this.audioPlayer.currentTime / this.audioPlayer.duration) * 90;
            currentFileItem.progress = Math.min(90, progress);
            this.updateQueueDisplay();
        }
    }

    togglePlayPause() {
        if (!this.audioPlayer || !this.currentAudioFile) return;

        const playPauseBtn = document.getElementById('playPauseBtn');
        const icon = playPauseBtn?.querySelector('i');

        if (this.isPlaying) {
            this.audioPlayer.pause();
            this.isPlaying = false;
            if (icon) {
                icon.className = 'fas fa-play';
            }
        } else {
            this.audioPlayer.play();
            this.isPlaying = true;
            if (icon) {
                icon.className = 'fas fa-pause';
            }
        }
    }

    stopAudio() {
        if (!this.audioPlayer) return;

        this.audioPlayer.pause();
        this.audioPlayer.currentTime = 0;
        this.isPlaying = false;
        
        const playPauseBtn = document.getElementById('playPauseBtn');
        const icon = playPauseBtn?.querySelector('i');
        if (icon) {
            icon.className = 'fas fa-play';
        }
        
        this.updateProgress();
    }

    rewindAudio() {
        if (!this.audioPlayer) return;
        this.audioPlayer.currentTime = Math.max(0, this.audioPlayer.currentTime - 10);
    }

    forwardAudio() {
        if (!this.audioPlayer) return;
        this.audioPlayer.currentTime = Math.min(this.audioPlayer.duration, this.audioPlayer.currentTime + 10);
    }

    setVolume(value) {
        if (!this.audioPlayer) return;
        this.audioPlayer.volume = value / 100;
        
        // Update mute button icon
        const muteBtn = document.getElementById('muteBtn');
        const icon = muteBtn?.querySelector('i');
        if (icon) {
            if (value == 0) {
                icon.className = 'fas fa-volume-mute';
            } else if (value < 50) {
                icon.className = 'fas fa-volume-down';
            } else {
                icon.className = 'fas fa-volume-up';
            }
        }
    }

    toggleMute() {
        if (!this.audioPlayer) return;
        
        const volumeControl = document.getElementById('volumeControl');
        const muteBtn = document.getElementById('muteBtn');
        const icon = muteBtn?.querySelector('i');
        
        if (this.isMuted) {
            this.audioPlayer.muted = false;
            this.isMuted = false;
            if (icon) {
                icon.className = this.audioPlayer.volume > 0.5 ? 'fas fa-volume-up' : 'fas fa-volume-down';
            }
        } else {
            this.audioPlayer.muted = true;
            this.isMuted = true;
            if (icon) {
                icon.className = 'fas fa-volume-mute';
            }
        }
    }

    seekAudio(value) {
        if (!this.audioPlayer || !this.audioPlayer.duration) return;
        const time = (value / 100) * this.audioPlayer.duration;
        this.audioPlayer.currentTime = time;
    }

    updateAudioInfo() {
        if (!this.audioPlayer) return;
        
        const totalTime = document.getElementById('totalTime');
        if (totalTime) {
            totalTime.textContent = this.formatTime(this.audioPlayer.duration);
        }
        
        const audioSeeker = document.getElementById('audioSeeker');
        if (audioSeeker) {
            audioSeeker.max = this.audioPlayer.duration;
        }
    }

    updateProgress() {
        if (!this.audioPlayer) return;
        
        const currentTime = document.getElementById('currentTime');
        const audioProgressBar = document.getElementById('audioProgressBar');
        const audioSeeker = document.getElementById('audioSeeker');
        
        const current = this.audioPlayer.currentTime;
        const duration = this.audioPlayer.duration || 0;
        const percentage = duration > 0 ? (current / duration) * 100 : 0;
        
        if (currentTime) {
            currentTime.textContent = this.formatTime(current);
        }
        if (audioProgressBar) {
            audioProgressBar.style.width = percentage + '%';
        }
        if (audioSeeker) {
            audioSeeker.value = current;
        }
        
        // Sync transcription progress if a file is being transcribed
        this.syncTranscriptionWithAudio();
    }

    onAudioEnded() {
        this.isPlaying = false;
        const playPauseBtn = document.getElementById('playPauseBtn');
        const icon = playPauseBtn?.querySelector('i');
        if (icon) {
            icon.className = 'fas fa-play';
        }
    }

    onAudioError(e) {
        console.error('Error en reproductor de audio:', e);
        this.showNotification('Error al cargar el archivo de audio', 'error');
    }

    enableAudioControls(enabled) {
        const controls = ['playPauseBtn', 'stopBtn', 'rewindBtn', 'forwardBtn', 'audioSeeker'];
        controls.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.disabled = !enabled;
            }
        });
    }

    formatTime(seconds) {
        if (isNaN(seconds)) return '0:00';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
    
    // Playback speed control
    setPlaybackSpeed(speed) {
        if (!this.audioPlayer) return;
        
        const speedValue = parseFloat(speed);
        this.audioPlayer.playbackRate = speedValue;
        
        this.showNotification(`Velocidad de reproducción: ${speedValue}x`, 'info');
    }
    
    // Hotkeys system
    initHotkeys() {
        // Default hotkeys configuration with modifier keys to avoid conflicts
        this.hotkeys = {
            'Ctrl+Space': { action: 'togglePlayPause', description: 'Play/Pause' },
            'Ctrl+ArrowLeft': { action: 'rewind', description: 'Retroceder 10s' },
            'Ctrl+ArrowRight': { action: 'forward', description: 'Avanzar 10s' },
            'Ctrl+ArrowUp': { action: 'volumeUp', description: 'Subir volumen' },
            'Ctrl+ArrowDown': { action: 'volumeDown', description: 'Bajar volumen' },
            'Ctrl+KeyM': { action: 'toggleMute', description: 'Silenciar/Activar' },
            'Ctrl+KeyS': { action: 'stop', description: 'Detener' },
            'Ctrl+Digit1': { action: 'setSpeed0.5', description: 'Velocidad 0.5x' },
            'Ctrl+Digit2': { action: 'setSpeed1', description: 'Velocidad 1x' },
            'Ctrl+Digit3': { action: 'setSpeed1.5', description: 'Velocidad 1.5x' },
            'Ctrl+Digit4': { action: 'setSpeed2', description: 'Velocidad 2x' }
        };
        
        // Load custom hotkeys from localStorage
        const savedHotkeys = localStorage.getItem('audioPlayerHotkeys');
        if (savedHotkeys) {
            try {
                this.hotkeys = { ...this.hotkeys, ...JSON.parse(savedHotkeys) };
            } catch (e) {
                console.warn('Error loading saved hotkeys:', e);
            }
        }
        
        // Add global keydown listener
        document.addEventListener('keydown', (e) => this.handleHotkey(e));
        
        // Add TinyMCE hotkey support
        this.setupTinyMCEHotkeys();
    }
    
    handleHotkey(e) {
        // Create hotkey combination string
        let hotkeyCombo = '';
        if (e.ctrlKey) hotkeyCombo += 'Ctrl+';
        if (e.altKey) hotkeyCombo += 'Alt+';
        if (e.shiftKey) hotkeyCombo += 'Shift+';
        hotkeyCombo += e.code;
        
        const hotkey = this.hotkeys[hotkeyCombo];
        if (!hotkey) return;
        
        // Always prevent default for our hotkeys to avoid conflicts
        e.preventDefault();
        e.stopPropagation();
        
        switch (hotkey.action) {
            case 'togglePlayPause':
                this.togglePlayPause();
                break;
            case 'rewind':
                this.rewindAudio();
                break;
            case 'forward':
                this.forwardAudio();
                break;
            case 'volumeUp':
                this.adjustVolume(10);
                break;
            case 'volumeDown':
                this.adjustVolume(-10);
                break;
            case 'toggleMute':
                this.toggleMute();
                break;
            case 'stop':
                this.stopAudio();
                break;
            case 'setSpeed0.5':
                this.setPlaybackSpeed(0.5);
                const speedSelect1 = document.getElementById('playbackSpeedSelect');
                if (speedSelect1) speedSelect1.value = '0.5';
                break;
            case 'setSpeed1':
                this.setPlaybackSpeed(1);
                const speedSelect2 = document.getElementById('playbackSpeedSelect');
                if (speedSelect2) speedSelect2.value = '1';
                break;
            case 'setSpeed1.5':
                this.setPlaybackSpeed(1.5);
                const speedSelect3 = document.getElementById('playbackSpeedSelect');
                if (speedSelect3) speedSelect3.value = '1.5';
                break;
            case 'setSpeed2':
                this.setPlaybackSpeed(2);
                const speedSelect4 = document.getElementById('playbackSpeedSelect');
                if (speedSelect4) speedSelect4.value = '2';
                break;
        }
    }
    
    adjustVolume(delta) {
        if (!this.audioPlayer) return;
        
        const volumeControl = document.getElementById('volumeControl');
        if (!volumeControl) return;
        
        const currentVolume = parseInt(volumeControl.value);
        const newVolume = Math.max(0, Math.min(100, currentVolume + delta));
        
        volumeControl.value = newVolume;
        this.setVolume(newVolume);
    }
    
    showHotkeysConfig() {
        // Create modal for hotkeys configuration
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-keyboard me-2"></i>
                            Configuración de Atajos de Teclado
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Acción</th>
                                        <th>Tecla Actual</th>
                                        <th>Nueva Tecla</th>
                                    </tr>
                                </thead>
                                <tbody id="hotkeysTableBody">
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Instrucciones:</strong> Haz clic en el campo "Nueva Tecla" y presiona la tecla que deseas asignar.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-success" id="saveHotkeysBtn">Guardar Cambios</button>
                        <button type="button" class="btn btn-outline-warning" id="resetHotkeysBtn">Restaurar Predeterminados</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Populate hotkeys table
        this.populateHotkeysTable();
        
        // Show modal
        const bootstrapModal = new bootstrap.Modal(modal);
        bootstrapModal.show();
        
        // Clean up when modal is hidden
        modal.addEventListener('hidden.bs.modal', () => {
            modal.remove();
        });
        
        // Save hotkeys button
        document.getElementById('saveHotkeysBtn').addEventListener('click', () => {
            this.saveHotkeysConfig();
            bootstrapModal.hide();
        });
        
        // Reset hotkeys button
        document.getElementById('resetHotkeysBtn').addEventListener('click', () => {
            this.resetHotkeysConfig();
            this.populateHotkeysTable();
        });
    }
    
    populateHotkeysTable() {
        const tbody = document.getElementById('hotkeysTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        Object.entries(this.hotkeys).forEach(([key, config]) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${config.description}</td>
                <td><code>${this.formatKeyName(key)}</code></td>
                <td>
                    <input type="text" class="form-control hotkey-input" 
                           data-action="${config.action}" 
                           data-current-key="${key}"
                           placeholder="Presiona Ctrl/Alt/Shift + tecla..."
                           readonly>
                </td>
            `;
            tbody.appendChild(row);
        });
        
        // Add event listeners for hotkey inputs
        document.querySelectorAll('.hotkey-input').forEach(input => {
            input.addEventListener('keydown', (e) => {
                e.preventDefault();
                
                // Create hotkey combination string
                let hotkeyCombo = '';
                if (e.ctrlKey) hotkeyCombo += 'Ctrl+';
                if (e.altKey) hotkeyCombo += 'Alt+';
                if (e.shiftKey) hotkeyCombo += 'Shift+';
                hotkeyCombo += e.code;
                
                input.value = this.formatKeyName(hotkeyCombo);
                input.dataset.newKey = hotkeyCombo;
            });
        });
    }
    
    formatKeyName(keyCombo) {
        const keyNames = {
            'Space': 'Espacio',
            'ArrowLeft': '← Izquierda',
            'ArrowRight': '→ Derecha',
            'ArrowUp': '↑ Arriba',
            'ArrowDown': '↓ Abajo',
            'KeyM': 'M',
            'KeyS': 'S',
            'Digit1': '1',
            'Digit2': '2',
            'Digit3': '3',
            'Digit4': '4'
        };
        
        // Handle combination keys
        if (keyCombo.includes('+')) {
            const parts = keyCombo.split('+');
            const modifiers = parts.slice(0, -1);
            const key = parts[parts.length - 1];
            const keyName = keyNames[key] || key;
            return modifiers.join('+') + '+' + keyName;
        }
        
        return keyNames[keyCombo] || keyCombo;
    }
    
    saveHotkeysConfig() {
        const newHotkeys = {};
        
        document.querySelectorAll('.hotkey-input').forEach(input => {
            const action = input.dataset.action;
            const currentKey = input.dataset.currentKey;
            const newKey = input.dataset.newKey || currentKey;
            
            newHotkeys[newKey] = {
                action: action,
                description: this.hotkeys[currentKey].description
            };
        });
        
        this.hotkeys = newHotkeys;
        localStorage.setItem('audioPlayerHotkeys', JSON.stringify(newHotkeys));
        
        this.showNotification('Configuración de atajos guardada exitosamente', 'success');
    }
    
    resetHotkeysConfig() {
        localStorage.removeItem('audioPlayerHotkeys');
        this.initHotkeys();
        this.showNotification('Atajos de teclado restaurados a valores predeterminados', 'info');
    }
    
    // Setup TinyMCE hotkeys integration
    setupTinyMCEHotkeys() {
        // Check if TinyMCE is available and setup hotkeys for it
        const checkTinyMCE = () => {
            if (window.tinymce && tinymce.editors) {
                // Setup hotkeys for existing editors
                tinymce.editors.forEach(editor => {
                    this.addHotkeysToTinyMCEEditor(editor);
                });
                
                // Setup hotkeys for future editors
                tinymce.on('AddEditor', (e) => {
                    this.addHotkeysToTinyMCEEditor(e.editor);
                });
                
                console.log('TinyMCE hotkeys configurados');
            } else {
                // Retry after 500ms if TinyMCE is not ready
                setTimeout(checkTinyMCE, 500);
            }
        };
        
        checkTinyMCE();
    }
    
    // Add hotkeys to a specific TinyMCE editor
    addHotkeysToTinyMCEEditor(editor) {
        editor.on('init', () => {
            // Add keydown listener to the editor's iframe document
            const editorDoc = editor.getDoc();
            if (editorDoc) {
                editorDoc.addEventListener('keydown', (e) => {
                    this.handleHotkey(e);
                });
                console.log(`Hotkeys agregados al editor TinyMCE: ${editor.id}`);
            }
        });
        
        // If editor is already initialized
        if (editor.initialized) {
            const editorDoc = editor.getDoc();
            if (editorDoc) {
                editorDoc.addEventListener('keydown', (e) => {
                    this.handleHotkey(e);
                });
                console.log(`Hotkeys agregados al editor TinyMCE ya inicializado: ${editor.id}`);
            }
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.audioTranscription = new AudioTranscriptionModule();
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AudioTranscriptionModule;
}