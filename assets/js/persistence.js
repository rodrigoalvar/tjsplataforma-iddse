/**
 * Módulo de Persistencia para Editor
 * Guarda y restaura el estado del editor, audio y grabación sincronizada
 */
const PersistenceModule = {
    // Clave base para localStorage
    STORAGE_KEY: 'editor_persistence_',
    SESSION_KEY: 'editor_session_',
    autoSaveInterval: null,
    currentSessionUrl: null,
    hasUnsavedChanges: false,
    sessionStartTime: null,
    
    // Obtener clave única basada en parámetros de URL
    getStorageKey: function() {
        const urlParams = new URLSearchParams(window.location.search);
        const studyId = urlParams.get('studyId') || 'default';
        const patientId = urlParams.get('patientId') || 'default';
        return `${this.STORAGE_KEY}${studyId}_${patientId}`;
    },

    getSessionKey: function() {
        const urlParams = new URLSearchParams(window.location.search);
        const studyId = urlParams.get('studyId') || 'default';
        const patientId = urlParams.get('patientId') || 'default';
        return `${this.SESSION_KEY}${studyId}_${patientId}`;
    },

    getCurrentStudyUrl: function() {
        const urlParams = new URLSearchParams(window.location.search);
        const studyId = urlParams.get('studyId');
        const patientId = urlParams.get('patientId');
        const patientName = urlParams.get('patientName');
        const modality = urlParams.get('modality');
        const studyDescription = urlParams.get('studyDescription');
        const studyInstanceUID = urlParams.get('studyInstanceUID');
        
        if (studyId && patientId) {
            return {
                studyId,
                patientId,
                patientName,
                modality,
                studyDescription,
                studyInstanceUID,
                fullUrl: window.location.href
            };
        }
        return null;
    },

    initializeSession: function() {
        const currentUrl = this.getCurrentStudyUrl();
        if (currentUrl) {
            this.currentSessionUrl = currentUrl;
            this.sessionStartTime = Date.now();
            
            // Guardar información de sesión activa
            const sessionData = {
                url: currentUrl,
                startTime: this.sessionStartTime,
                lastActivity: Date.now()
            };
            localStorage.setItem(this.getSessionKey(), JSON.stringify(sessionData));
            
            console.log('Sesión inicializada para:', currentUrl.studyId);
        }
    },

    checkUrlChange: function() {
        const currentUrl = this.getCurrentStudyUrl();
        
        if (!this.currentSessionUrl && currentUrl) {
            // Primera carga
            this.initializeSession();
            return false;
        }
        
        if (this.currentSessionUrl && currentUrl) {
            // Verificar si cambió el estudio
            if (this.currentSessionUrl.studyId !== currentUrl.studyId || 
                this.currentSessionUrl.patientId !== currentUrl.patientId) {
                console.log('Cambio de contexto detectado:', {
                    anterior: this.currentSessionUrl,
                    actual: currentUrl
                });
                return true;
            }
        }
        
        return false;
    },

    markUnsavedChanges: function(hasChanges = true) {
        this.hasUnsavedChanges = hasChanges;
        
        // Si el usuario hace cambios nuevos, reactivar auto-save
        if (hasChanges && this._preventAutoSave === true) {
            console.log('✏️ Cambios nuevos detectados, reactivando auto-save...');
            this._preventAutoSave = false;
            
            // Si el auto-save estaba desactivado, reactivarlo
            if (!this.autoSaveInterval) {
                this.setupAutoSave();
            }
        }
        
        // Actualizar indicador visual en el título
        const title = document.title;
        if (hasChanges && !title.startsWith('* ')) {
            document.title = '* ' + title;
        } else if (!hasChanges && title.startsWith('* ')) {
            document.title = title.substring(2);
        }
        
        // Actualizar sesión activa
        this.updateSessionActivity();
    },

    updateSessionActivity: function() {
        const sessionKey = this.getSessionKey();
        const sessionData = localStorage.getItem(sessionKey);
        
        if (sessionData) {
            const session = JSON.parse(sessionData);
            session.lastActivity = Date.now();
            session.hasUnsavedChanges = this.hasUnsavedChanges;
            localStorage.setItem(sessionKey, JSON.stringify(session));
        }
    },

    clearSession: function() {
        const sessionKey = this.getSessionKey();
        localStorage.removeItem(sessionKey);
        this.currentSessionUrl = null;
        this.hasUnsavedChanges = false;
        this.sessionStartTime = null;
        
        // Limpiar indicador del título
        const title = document.title;
        if (title.startsWith('* ')) {
            document.title = title.substring(2);
        }
        
        // Actualizar estado del enlace de Informes en el dashboard
        if (typeof updateInformesNavState === 'function') {
            updateInformesNavState();
        }
        
        console.log('Sesión limpiada');
    },

    showUnsavedChangesWarning: function() {
        return new Promise((resolve) => {
            if (!this.hasUnsavedChanges) {
                resolve('continue');
                return;
            }

            const modal = document.createElement('div');
            modal.className = 'modal fade show';
            modal.style.display = 'block';
            modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                Datos sin guardar
                            </h5>
                        </div>
                        <div class="modal-body">
                            <p>Tienes cambios sin guardar en el informe actual.</p>
                            <p><strong>¿Qué deseas hacer?</strong></p>
                            <div class="alert alert-info">
                                <small>
                                    <i class="bi bi-info-circle me-1"></i>
                                    Si continúas sin guardar, los cambios se perderán permanentemente.
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-success" id="saveAndContinue">
                                <i class="bi bi-check-circle me-1"></i>
                                Guardar y continuar
                            </button>
                            <button type="button" class="btn btn-danger" id="discardChanges">
                                <i class="bi bi-trash me-1"></i>
                                Descartar cambios
                            </button>
                            <button type="button" class="btn btn-secondary" id="cancelNavigation">
                                <i class="bi bi-x-circle me-1"></i>
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            // Manejar botones
            modal.querySelector('#saveAndContinue').addEventListener('click', async () => {
                try {
                    await this.saveEditorState();
                    this.markUnsavedChanges(false);
                    document.body.removeChild(modal);
                    resolve('save');
                } catch (error) {
                    console.error('Error al guardar:', error);
                    alert('Error al guardar los datos. Inténtalo de nuevo.');
                }
            });

            modal.querySelector('#discardChanges').addEventListener('click', () => {
                this.discardChanges();
                document.body.removeChild(modal);
                resolve('discard');
            });

            modal.querySelector('#cancelNavigation').addEventListener('click', () => {
                this.cancelNavigation();
                document.body.removeChild(modal);
                resolve('cancel');
            });
        });
    },

    setupUrlChangeDetection: function() {
        // Detectar cambios de URL mediante popstate
        window.addEventListener('popstate', async (event) => {
            if (this.checkUrlChange()) {
                const action = await this.showUnsavedChangesWarning();
                if (action === 'cancel') {
                    // Restaurar URL anterior
                    history.pushState(null, '', this.currentSessionUrl.fullUrl);
                    return;
                }
                
                if (action === 'save' || action === 'discard') {
                    this.initializeSession();
                }
            }
        });

        // Detectar intentos de salir de la página
        window.addEventListener('beforeunload', (event) => {
            // Con el sistema de persistencia, no mostramos popup de advertencia
            // ya que los cambios se guardan automáticamente
            // Solo guardamos el estado actual antes de salir
            if (this.hasUnsavedChanges) {
                this.saveEditorStateSync();
            }
        });
    },

    // Guardar estado completo del editor
    saveEditorState: async function() {
        // Si el guardado automático está deshabilitado (después de guardar informe), no guardar
        if (this._preventAutoSave === true) {
            console.log('⏸️ Guardado automático deshabilitado (informe ya guardado)');
            return false;
        }
        
        try {
            const state = {
                timestamp: Date.now(),
                // Estado del editor TinyMCE
                tinymce: {
                    content: (window.tinymce && tinymce.activeEditor) ? tinymce.activeEditor.getContent() : '',
                    isDirty: (window.tinymce && tinymce.activeEditor) ? tinymce.activeEditor.isDirty() : false
                },
                // Estado del módulo de informes
                reports: (window.ReportsModule && window.ReportsModule.editorState) ? {
                    currentTemplate: ReportsModule.editorState.currentTemplate,
                    currentReportId: ReportsModule.editorState.currentReportId,
                    isDirty: ReportsModule.editorState.isDirty
                } : {
                    currentTemplate: null,
                    currentReportId: null,
                    isDirty: false
                },
                // Estado del módulo de audio
                audio: await this.getAudioState(),
                // Estado del módulo de grabación sincronizada
                syncEditor: await this.getSyncEditorState(),
                // Parámetros de URL para contexto
                urlParams: Object.fromEntries(new URLSearchParams(window.location.search)),
                // Flag explícito de cambios sin guardar
                hasUnsavedChanges: this.hasUnsavedChanges
            };

            localStorage.setItem(this.getStorageKey(), JSON.stringify(state));
            console.log('Estado del editor guardado en localStorage:', state);
            return true;
        } catch (error) {
            console.error('Error al guardar estado del editor:', error);
            return false;
        }
    },

    // Versión síncrona de saveEditorState para beforeunload
    saveEditorStateSync: function() {
        try {
            const state = {
                timestamp: Date.now(),
                // Estado del editor TinyMCE
                tinymce: {
                    content: (window.tinymce && tinymce.activeEditor) ? tinymce.activeEditor.getContent() : '',
                    isDirty: (window.tinymce && tinymce.activeEditor) ? tinymce.activeEditor.isDirty() : false
                },
                // Estado del módulo de informes
                reports: (window.ReportsModule && window.ReportsModule.editorState) ? {
                    currentTemplate: ReportsModule.editorState.currentTemplate,
                    currentReportId: ReportsModule.editorState.currentReportId,
                    isDirty: ReportsModule.editorState.isDirty
                } : {
                    currentTemplate: null,
                    currentReportId: null,
                    isDirty: false
                },
                // Estado del módulo de audio (sin conversión a base64 para versión síncrona)
                audio: (window.AudioModule && window.AudioModule.recordingState) ? {
                    hasRecording: AudioModule.recordingState.currentAudioBlob !== null,
                    isRecording: AudioModule.recordingState.isRecording,
                    timerSeconds: AudioModule.timer ? AudioModule.timer.seconds : 0,
                    audioData: null // No convertir a base64 en versión síncrona
                } : {
                    hasRecording: false,
                    isRecording: false,
                    timerSeconds: 0,
                    audioData: null
                },
                // Estado del módulo de grabación sincronizada (sin conversión a base64 para versión síncrona)
                syncEditor: window.syncEditorInstance ? {
                    isRecording: window.syncEditorInstance.isRecording,
                    hasAudio: window.syncEditorInstance.currentAudioBlob !== null,
                    syncData: window.syncEditorInstance.syncData || [],
                    timerSeconds: window.syncEditorInstance.timerSeconds || 0,
                    transcriptionContent: window.syncEditorInstance.transcriptionContainer ? 
                        window.syncEditorInstance.transcriptionContainer.innerHTML : '',
                    audioData: null // No convertir a base64 en versión síncrona
                } : null,
                // Parámetros de URL para contexto
                urlParams: Object.fromEntries(new URLSearchParams(window.location.search)),
                // Flag explícito de cambios sin guardar
                hasUnsavedChanges: this.hasUnsavedChanges
            };

            localStorage.setItem(this.getStorageKey(), JSON.stringify(state));
            console.log('Estado del editor guardado (versión síncrona):', state);
            return true;
        } catch (error) {
            console.error('Error al guardar estado del editor (versión síncrona):', error);
            return false;
        }
    },

    // Obtener estado del módulo de audio con conversión a base64
    getAudioState: async function() {
        if (!window.AudioModule || !window.AudioModule.recordingState) {
            return {
                hasRecording: false,
                isRecording: false,
                timerSeconds: 0,
                audioData: null
            };
        }

        const audioState = {
            hasRecording: AudioModule.recordingState.currentAudioBlob !== null,
            isRecording: AudioModule.recordingState.isRecording,
            timerSeconds: AudioModule.timer ? AudioModule.timer.seconds : 0,
            audioData: null
        };

        // Convertir el audio a base64 si existe
        if (AudioModule.recordingState.currentAudioBlob) {
            try {
                const base64Audio = await this.blobToBase64(AudioModule.recordingState.currentAudioBlob);
                audioState.audioData = base64Audio;
            } catch (error) {
                console.error('Error al convertir audio a base64:', error);
            }
        }

        return audioState;
    },

    // Convertir Blob a base64
    blobToBase64: function(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    },

    // Convertir base64 a Blob
    base64ToBlob: function(base64Data) {
        const byteCharacters = atob(base64Data.split(',')[1]);
        const byteNumbers = new Array(byteCharacters.length);
        for (let i = 0; i < byteCharacters.length; i++) {
            byteNumbers[i] = byteCharacters.charCodeAt(i);
        }
        const byteArray = new Uint8Array(byteNumbers);
        return new Blob([byteArray], { type: 'audio/wav' });
    },

    // Obtener estado del módulo de grabación sincronizada
    getSyncEditorState: async function() {
        if (typeof window.syncEditorInstance === 'undefined' || !window.syncEditorInstance) {
            return null;
        }

        const syncEditor = window.syncEditorInstance;
        const state = {
            isRecording: syncEditor.isRecording,
            hasAudio: syncEditor.currentAudioBlob !== null,
            syncData: syncEditor.syncData || [],
            timerSeconds: syncEditor.timerSeconds || 0,
            transcriptionContent: syncEditor.transcriptionContainer ? 
                syncEditor.transcriptionContainer.innerHTML : '',
            audioData: null
        };

        // Convertir el audio a base64 si existe
        if (syncEditor.currentAudioBlob) {
            try {
                const base64Audio = await this.blobToBase64(syncEditor.currentAudioBlob);
                state.audioData = base64Audio;
            } catch (error) {
                console.error('Error al convertir audio del sync editor a base64:', error);
            }
        }

        return state;
    },

    // Restaurar estado completo del editor
    restoreEditorState: function() {
        try {
            let storageKey = this.getStorageKey();
            let savedState = localStorage.getItem(storageKey);
            
            // Si no encuentra estado con la clave actual (con parámetros),
            // intentar con la clave por defecto (sin parámetros) SOLO si no es una nueva sesión
            if (!savedState) {
                const defaultKey = `${this.STORAGE_KEY}default_default`;
                
                // Verificar si es una nueva sesión después de login
                // Si el usuario acaba de hacer login, no restaurar datos de sesiones anteriores
                const isNewSession = this.isNewSession();
                
                if (!isNewSession) {
                    savedState = localStorage.getItem(defaultKey);
                    if (savedState) {
                        console.log('Estado restaurado desde clave por defecto:', defaultKey);
                        storageKey = defaultKey;
                    } else {
                        console.log('No hay estado guardado para restaurar');
                        return false;
                    }
                } else {
                    console.log('Nueva sesión detectada, no restaurando datos de sesiones anteriores');
                    return false;
                }
            } else {
                console.log('Estado restaurado desde clave específica:', storageKey);
            }

            const state = JSON.parse(savedState);
            
            // Verificar si el estado no ha expirado (24 horas)
            const now = Date.now();
            const stateAge = now - state.timestamp;
            const maxAge = 24 * 60 * 60 * 1000; // 24 horas en milisegundos
            
            if (stateAge > maxAge) {
                console.log('Estado guardado expirado, eliminando...');
                this.clearSavedState();
                return false;
            }

            console.log('Restaurando estado del editor...', state);

            // Esperar a que todos los módulos estén listos antes de restaurar
            const waitForModules = () => {
                let modulesReady = true;
                
                // Verificar TinyMCE
                if (state.tinymce && state.tinymce.content) {
                    const editor = window.tinymce && tinymce.get('reportEditor');
                    if (!editor || !editor.initialized) {
                        modulesReady = false;
                    }
                }
                
                // Verificar AudioModule si hay audio para restaurar
                if (state.audio && state.audio.hasRecording && state.audio.audioData) {
                    const audioModuleExists = !!window.AudioModule;
                    const displayRecordingExists = !!(window.AudioModule && window.AudioModule.displayRecording);
                    const audioPlayerExists = !!document.getElementById('audioPlayer');
                    const audioContainerExists = !!document.getElementById('audioContainer');
                    
                    console.log('Verificando módulos para restauración de audio:', {
                        audioModuleExists,
                        displayRecordingExists,
                        audioPlayerExists,
                        audioContainerExists
                    });
                    
                    if (!audioModuleExists || !displayRecordingExists || !audioPlayerExists || !audioContainerExists) {
                        modulesReady = false;
                    }
                }
                
                if (modulesReady) {
                    // Restaurar contenido de TinyMCE
                    if (state.tinymce && state.tinymce.content) {
                        this.restoreTinyMCEContent(state.tinymce.content);
                    }

                    // Restaurar estado de informes
                    if (state.reports) {
                        this.restoreReportsState(state.reports);
                    }

                    // Restaurar estado de audio
                    if (state.audio) {
                        this.restoreAudioState(state.audio);
                    }

                    // Restaurar estado de grabación sincronizada
                    if (state.syncEditor) {
                        this.restoreSyncEditorState(state.syncEditor);
                    }

                    // Mostrar notificación de restauración
                    this.showRestoreNotification();
                } else {
                    // Reintentar después de un breve delay
                    setTimeout(waitForModules, 300);
                }
            };
            
            // Iniciar el proceso de espera
            waitForModules();
            
            return true;
        } catch (error) {
            console.error('Error al restaurar estado del editor:', error);
            return false;
        }
    },

    // Restaurar contenido de TinyMCE
    restoreTinyMCEContent: function(content) {
        if (content && window.tinymce) {
            const checkEditor = setInterval(() => {
                const editor = tinymce.get('reportEditor');
                if (editor && editor.initialized) {
                    // Solo restaurar si el editor está vacío o tiene contenido por defecto
                    const currentContent = editor.getContent();
                    const isEmpty = !currentContent || currentContent.trim() === '' || 
                                  currentContent.includes('INFORME MÉDICO') && currentContent.includes('[Describir');
                    
                    if (isEmpty) {
                        editor.setContent(content);
                        editor.setDirty(false);
                        console.log('Contenido de TinyMCE restaurado desde persistencia');
                    }
                    clearInterval(checkEditor);
                }
            }, 200);
            
            // Timeout para evitar bucle infinito
            setTimeout(() => {
                clearInterval(checkEditor);
            }, 10000);
        }
    },

    // Restaurar estado del módulo de informes
    restoreReportsState: function(reportsState) {
        if (window.ReportsModule && window.ReportsModule.editorState) {
            ReportsModule.editorState.currentTemplate = reportsState.currentTemplate;
            ReportsModule.editorState.currentReportId = reportsState.currentReportId;
            ReportsModule.editorState.isDirty = reportsState.isDirty;
            console.log('Estado del módulo de informes restaurado');
        }
    },

    // Restaurar estado del módulo de audio
    restoreAudioState: function(audioState, retryCount = 0) {
        // Verificación más robusta de AudioModule
        if (!window.AudioModule || !window.AudioModule.recordingState || !window.AudioModule.updateUI) {
            if (retryCount < 5) {
                console.log(`AudioModule no completamente inicializado, intento ${retryCount + 1}/5...`);
                // Intentar restaurar más tarde cuando el módulo esté disponible
                setTimeout(() => {
                    this.restoreAudioState(audioState, retryCount + 1);
                }, 1000);
            } else {
                console.warn('AudioModule no disponible después de 5 intentos, omitiendo restauración de audio');
            }
            return;
        }

        // Restaurar timer
        if (AudioModule.timer) {
            AudioModule.timer.seconds = audioState.timerSeconds || 0;
            if (AudioModule.timer.display) {
                const minutes = Math.floor(AudioModule.timer.seconds / 60);
                const seconds = AudioModule.timer.seconds % 60;
                AudioModule.timer.display.textContent = 
                    `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }
        }

        // Restaurar audio si existe
        if (audioState.hasRecording && audioState.audioData) {
            console.log('Intentando restaurar audio...', {
                hasRecording: audioState.hasRecording,
                audioDataLength: audioState.audioData ? audioState.audioData.length : 0
            });
            
            try {
                const audioBlob = this.base64ToBlob(audioState.audioData);
                const audioUrl = URL.createObjectURL(audioBlob);
                
                console.log('Audio blob creado:', {
                    blobSize: audioBlob.size,
                    blobType: audioBlob.type,
                    audioUrl: audioUrl
                });
                
                // Restaurar el blob en el estado del módulo
                AudioModule.recordingState.currentAudioBlob = audioBlob;
                
                // Verificar elementos DOM antes de mostrar
                const audioPlayer = document.getElementById('audioPlayer');
                const audioContainer = document.getElementById('audioContainer');
                
                console.log('Elementos DOM encontrados:', {
                    audioPlayer: !!audioPlayer,
                    audioContainer: !!audioContainer
                });
                
                // Mostrar el reproductor de audio
                if (AudioModule.displayRecording) {
                    AudioModule.displayRecording(audioUrl);
                    console.log('displayRecording llamado');
                } else {
                    console.error('AudioModule.displayRecording no está disponible');
                }
                
                // Verificar que el contenedor esté visible
                setTimeout(() => {
                    const container = document.getElementById('audioContainer');
                    if (container) {
                        console.log('Estado del contenedor de audio:', {
                            display: container.style.display,
                            visible: container.offsetWidth > 0 && container.offsetHeight > 0
                        });
                    }
                }, 100);
                
                console.log('Audio restaurado correctamente');
            } catch (error) {
                console.error('Error al restaurar audio:', error);
            }
        } else {
            console.log('No hay audio para restaurar:', {
                hasRecording: audioState.hasRecording,
                hasAudioData: !!audioState.audioData
            });
        }

        // Actualizar UI
        if (AudioModule.updateUI) {
            AudioModule.updateUI();
        }

        console.log('Estado del módulo de audio restaurado');
    },

    // Restaurar estado del módulo de grabación sincronizada
    restoreSyncEditorState: function(syncState, retryCount = 0) {
        if (typeof window.syncEditorInstance === 'undefined' || !window.syncEditorInstance) {
            if (retryCount < 5) {
                console.warn(`SyncEditorModule no disponible, intento ${retryCount + 1}/5...`);
                // Intentar restaurar más tarde cuando el módulo esté disponible
                setTimeout(() => {
                    this.restoreSyncEditorState(syncState, retryCount + 1);
                }, 1000);
            } else {
                console.error('SyncEditorModule no disponible después de 5 intentos, cancelando restauración');
            }
            return;
        }

        const syncEditor = window.syncEditorInstance;
        
        if (syncState.syncData && syncState.syncData.length > 0) {
            syncEditor.syncData = syncState.syncData;
        }

        if (syncState.transcriptionContent && syncEditor.transcriptionContainer) {
            syncEditor.transcriptionContainer.innerHTML = syncState.transcriptionContent;
        }

        // Restaurar audio si existe
        if (syncState.hasAudio && syncState.audioData) {
            try {
                const audioBlob = this.base64ToBlob(syncState.audioData);
                syncEditor.currentAudioBlob = audioBlob;
                
                // Configurar reproductor de audio
                if (syncEditor.audioPlayer) {
                    const audioUrl = URL.createObjectURL(audioBlob);
                    syncEditor.audioPlayer.src = audioUrl;
                    syncEditor.audioPlayer.style.display = 'block';
                    syncEditor.currentAudio = syncEditor.audioPlayer;
                    syncEditor.currentAudioUrl = audioUrl;
                    
                    // Mostrar el contenedor de audio completo
                    const audioContainer = syncEditor.audioPlayer.closest('.sync-audio-container');
                    if (audioContainer) {
                        audioContainer.style.display = 'block';
                    }
                    
                    // Mostrar botón de descarga si existe
                    if (syncEditor.downloadButton) {
                        syncEditor.downloadButton.style.display = 'inline-block';
                    }
                    
                    // Mostrar botón de modo de reproducción si existe
                    if (syncEditor.playModeButton) {
                        syncEditor.playModeButton.style.display = 'inline-block';
                    }
                    
                    console.log('Audio del sync editor restaurado correctamente');
                }
            } catch (error) {
                console.error('Error al restaurar audio del sync editor:', error);
            }
        }

        console.log('Estado del módulo de grabación sincronizada restaurado');
    },

    // Mostrar notificación de restauración
    showRestoreNotification: function() {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 300px;';
        notification.innerHTML = `
            <i class="fas fa-info-circle me-2"></i>
            <strong>Estado restaurado</strong><br>
            Se ha restaurado el contenido previo del editor.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    },

    // Limpiar estado guardado
    clearSavedState: function() {
        try {
            localStorage.removeItem(this.getStorageKey());
            console.log('Estado guardado eliminado');
            return true;
        } catch (error) {
            console.error('Error al eliminar estado guardado:', error);
            return false;
        }
    },

    // Configurar guardado automático
    setupAutoSave: async function() {
        // Guardar estado antes de salir de la página
        window.addEventListener('beforeunload', (event) => {
            // beforeunload no puede ser asíncrono, usar versión síncrona
            this.saveEditorStateSync();
        });

        // Guardar estado periódicamente (cada 30 segundos)
        // Solo si no está deshabilitado (después de guardar informe)
        this.autoSaveInterval = setInterval(async () => {
            if (!this._preventAutoSave) {
                await this.saveEditorState();
            }
        }, 30000);

        // Configurar evento de cambio de TinyMCE cuando esté disponible
        this.setupTinyMCEChangeListener();

        console.log('Guardado automático configurado');
    },

    // Configurar listener de cambios de TinyMCE
    setupTinyMCEChangeListener: async function() {
        const maxAttempts = 20;
        let attempts = 0;

        const trySetupListener = () => {
            if (tinymce.activeEditor && tinymce.activeEditor.initialized) {
                tinymce.activeEditor.on('change', () => {
                    console.log('Cambio detectado en TinyMCE');
                    this.markUnsavedChanges(true);
                    // Debounce para evitar guardado excesivo
                    // Solo guardar si no está deshabilitado
                    if (!this._preventAutoSave) {
                        clearTimeout(this.saveTimeout);
                        this.saveTimeout = setTimeout(async () => {
                            await this.saveEditorState();
                        }, 2000);
                    }
                });
                
                tinymce.activeEditor.on('keyup', () => {
                    this.markUnsavedChanges(true);
                });
                
                tinymce.activeEditor.on('paste', () => {
                    this.markUnsavedChanges(true);
                });
                
                console.log('Listener de cambios de TinyMCE configurado');
            } else if (attempts < maxAttempts) {
                attempts++;
                setTimeout(trySetupListener, 500);
            } else {
                console.warn('No se pudo configurar el listener de TinyMCE: editor no disponible');
            }
        };

        trySetupListener();
    },

    // Limpiar recursos y listeners
    cleanup: function() {
        if (this.autoSaveInterval) {
            clearInterval(this.autoSaveInterval);
        }
        if (this.saveTimeout) {
            clearTimeout(this.saveTimeout);
        }
        console.log('Recursos de persistencia limpiados');
    },

    // Agregar botón de control de persistencia
    addPersistenceControls: function() {
        const actionButtonsContainer = document.querySelector('.d-flex.flex-wrap.gap-3.justify-content-center');
        if (actionButtonsContainer) {
            const persistenceButton = document.createElement('button');
            persistenceButton.type = 'button';
            persistenceButton.className = 'btn btn-outline-secondary';
            persistenceButton.innerHTML = '<i class="fas fa-broom me-2"></i>Limpiar Estado';
            persistenceButton.title = 'Limpiar estado guardado del editor';
            persistenceButton.style.display = 'none'; // Ocultar el botón
            
            persistenceButton.addEventListener('click', () => {
                if (confirm('¿Está seguro de que desea limpiar el estado guardado? Esta acción no se puede deshacer.')) {
                    this.clearSavedState();
                    this.showClearNotification();
                }
            });
            
            actionButtonsContainer.appendChild(persistenceButton);
        }
    },

    // Mostrar notificación de limpieza
    showClearNotification: function() {
        const notification = document.createElement('div');
        notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 300px;';
        notification.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>
            <strong>Estado limpiado</strong><br>
            El estado guardado ha sido eliminado.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remover después de 3 segundos
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 3000);
    },

    // Inicializar módulo de persistencia
    init: async function() {
        console.log('Inicializando módulo de persistencia...');
        
        try {
            // Inicializar sesión
            this.initializeSession();
            
            // Configurar detección de cambios de URL
            this.setupUrlChangeDetection();
            
            // Configurar guardado automático
            await this.setupAutoSave();
            
            // Configurar listener de cambios en TinyMCE
            await this.setupTinyMCEChangeListener();
            
            // Restaurar estado previo
            this.restoreEditorState();
            
            // Agregar controles de persistencia
            setTimeout(() => {
                this.addPersistenceControls();
            }, 2000);
            
            console.log('Módulo de persistencia inicializado correctamente');
        } catch (error) {
            console.error('Error al inicializar módulo de persistencia:', error);
        }
        
        // Limpiar recursos al salir
        window.addEventListener('beforeunload', (event) => {
            this.saveEditorStateSync(); // Guardar antes de salir (versión síncrona)
            this.cleanup();
        });
    },

    // Descartar cambios
    discardChanges: function() {
        this.clearSavedState();
        this.clearSession();
        this.markUnsavedChanges(false);
        
        // Limpiar contenido del editor
        if (window.tinymce && tinymce.get('reportEditor')) {
            tinymce.get('reportEditor').setContent('');
        }
        
        // Limpiar audio si existe
        if (window.AudioModule && typeof window.AudioModule.clearRecording === 'function') {
            window.AudioModule.clearRecording();
        }
    },

    // Guardar cambios
    saveChanges: async function() {
        if (window.ReportsModule && typeof window.ReportsModule.saveReport === 'function') {
                await window.ReportsModule.saveReport();
        } else {
            console.warn('ReportsModule no disponible para guardar');
        }
    },

    // Verificar si es una nueva sesión después del login
    isNewSession: function() {
        // Verificar si hay un indicador de nueva sesión en sessionStorage
        const newSessionFlag = sessionStorage.getItem('new_session_flag');
        
        if (newSessionFlag === 'true') {
            // Limpiar el flag después de usarlo
            sessionStorage.removeItem('new_session_flag');
            return true;
        }
        
        // También verificar si la sesión es muy reciente (menos de 5 segundos)
        const sessionStartTime = sessionStorage.getItem('session_start_time');
        if (sessionStartTime) {
            const now = Date.now();
            const sessionAge = now - parseInt(sessionStartTime);
            // Si la sesión tiene menos de 5 segundos, considerarla nueva
            return sessionAge < 5000;
        }
        
        return false;
    },

    // Cancelar navegación
    cancelNavigation: function() {
        // No hacer nada, simplemente cerrar el modal
        console.log('Navegación cancelada por el usuario');
    },

    // Limpiar TODA la persistencia (para logout)
    clearAllPersistence: function() {
        try {
            console.log('Limpiando toda la persistencia del editor...');
            
            // Limpiar todas las claves de persistencia del editor
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && (key.startsWith('editor_persistence_') || 
                           key.startsWith('editor_session_') ||
                           key.includes('_default') || // Incluir claves como 'default_default'
                           key.startsWith('tinymce_') ||
                           key.startsWith('audio_'))) {
                    keysToRemove.push(key);
                }
            }
            keysToRemove.forEach(key => {
                localStorage.removeItem(key);
                console.log('Clave eliminada:', key);
            });
            
            // Limpiar sessionStorage relacionado con el editor
            const sessionKeysToRemove = [];
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                if (key && (key.startsWith('editor_') || 
                           key.startsWith('tinymce_') ||
                           key.startsWith('audio_'))) {
                    sessionKeysToRemove.push(key);
                }
            }
            sessionKeysToRemove.forEach(key => {
                sessionStorage.removeItem(key);
                console.log('Clave de sesión eliminada:', key);
            });
            
            // Limpiar estado interno
            this.clearSession();
            this.hasUnsavedChanges = false;
            
            console.log('Toda la persistencia del editor ha sido limpiada');
            return true;
        } catch (error) {
            console.error('Error al limpiar toda la persistencia:', error);
            return false;
        }
    }
};

// Exponer módulo globalmente
window.PersistenceModule = PersistenceModule;