// Módulo para gestión de archivos temporales de audio
const TempAudioManager = {
    // Obtener URL base dinámicamente
    getBaseUrl: function() {
        const pathname = window.location.pathname;
        // Si estamos en /components/, usar ruta relativa
        if (pathname.includes('/components/')) {
            return 'temp-audio.php';
        }
        // Si estamos en /assets/, subir un nivel
        if (pathname.includes('/assets/')) {
            return '../components/temp-audio.php';
        }
        // Por defecto, usar ruta absoluta desde raíz
        // Intentar detectar si estamos en un subdirectorio
        const portalMatch = pathname.match(/\/(PORTAL_ESTUDIOS|portal_estudios)/i);
        if (portalMatch) {
            return `${portalMatch[0]}/components/temp-audio.php`;
        }
        // Fallback: ruta relativa
        return 'components/temp-audio.php';
    },
    
    // Obtener información de URLESTUDIO
    getStudyInfo: function() {
        if (window.URLESTUDIO && window.URLESTUDIO.studyId && window.URLESTUDIO.patientId) {
            return {
                studyId: window.URLESTUDIO.studyId,
                patientId: window.URLESTUDIO.patientId
            };
        }
        return null;
    },
    
    // Guardar archivo de audio temporal
    saveAudioFile: async function(audioBlob, audioType = 'regular') {
        const studyInfo = this.getStudyInfo();
        if (!studyInfo) {
            throw new Error('No hay información de estudio disponible (URLESTUDIO)');
        }
        
        // Determinar extensión correcta según el tipo MIME del blob
        let fileExtension = 'webm'; // Por defecto WebM (formato más común de MediaRecorder)
        if (audioBlob.type) {
            if (audioBlob.type.includes('webm')) {
                fileExtension = 'webm';
            } else if (audioBlob.type.includes('mp4')) {
                fileExtension = 'mp4';
            } else if (audioBlob.type.includes('ogg')) {
                fileExtension = 'ogg';
            } else if (audioBlob.type.includes('wav')) {
                fileExtension = 'wav';
            }
        }
        
        const formData = new FormData();
        formData.append('audio', audioBlob, `audio_${audioType}_${Date.now()}.${fileExtension}`);
        formData.append('studyId', studyInfo.studyId);
        formData.append('patientId', studyInfo.patientId);
        formData.append('audioType', audioType);
        
        try {
            const response = await fetch(this.getBaseUrl(), {
                method: 'POST',
                body: formData
            });
            
            // Verificar si la respuesta es exitosa
            if (!response.ok) {
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const result = await response.json();
                    throw new Error(result.message || `Error HTTP ${response.status}`);
                } else {
                    throw new Error(`Error HTTP ${response.status}: ${response.statusText}`);
                }
            }
            
            // Verificar que la respuesta sea JSON antes de parsear
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('La respuesta del servidor no es JSON válido');
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Error al guardar el archivo de audio');
            }
            
            console.log('Audio temporal guardado:', result.data);
            return result.data;
        } catch (error) {
            console.error('Error al guardar audio temporal:', error);
            throw error;
        }
    },
    
    // Recuperar archivos de audio temporales
    getAudioFiles: async function() {
        const studyInfo = this.getStudyInfo();
        if (!studyInfo) {
            return [];
        }
        
        try {
            const url = `${this.getBaseUrl()}?studyId=${encodeURIComponent(studyInfo.studyId)}&patientId=${encodeURIComponent(studyInfo.patientId)}`;
            const response = await fetch(url);
            
            // Verificar si la respuesta es exitosa
            if (!response.ok) {
                // Si es 404, simplemente retornar array vacío (no hay archivos)
                if (response.status === 404) {
                    console.log('No se encontraron archivos de audio temporales (404)');
                    return [];
                }
                // Para otros errores, intentar parsear JSON si es posible
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const result = await response.json();
                    throw new Error(result.message || `Error HTTP ${response.status}`);
                } else {
                    throw new Error(`Error HTTP ${response.status}: ${response.statusText}`);
                }
            }
            
            // Verificar que la respuesta sea JSON antes de parsear
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('La respuesta no es JSON, retornando array vacío');
                return [];
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Error al recuperar archivos de audio');
            }
            
            console.log('Archivos de audio temporales recuperados:', result.data);
            return result.data || [];
        } catch (error) {
            // Si el error es de parsing JSON, simplemente retornar array vacío
            if (error instanceof SyntaxError && error.message.includes('JSON')) {
                console.log('Error al parsear respuesta JSON (probablemente HTML de error 404), retornando array vacío');
                return [];
            }
            console.error('Error al recuperar archivos de audio temporales:', error);
            return [];
        }
    },
    
    // Eliminar todos los archivos de audio temporales
    clearAudioFiles: async function() {
        const studyInfo = this.getStudyInfo();
        if (!studyInfo) {
            return true;
        }
        
        try {
            const url = `${this.getBaseUrl()}?studyId=${encodeURIComponent(studyInfo.studyId)}&patientId=${encodeURIComponent(studyInfo.patientId)}`;
            const response = await fetch(url, {
                method: 'DELETE'
            });
            
            // Verificar si la respuesta es exitosa
            if (!response.ok) {
                // Si es 404, considerar que no hay archivos para eliminar (éxito)
                if (response.status === 404) {
                    console.log('No se encontraron archivos para eliminar (404)');
                    return true;
                }
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const result = await response.json();
                    throw new Error(result.message || `Error HTTP ${response.status}`);
                } else {
                    throw new Error(`Error HTTP ${response.status}: ${response.statusText}`);
                }
            }
            
            // Verificar que la respuesta sea JSON antes de parsear
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                // Si no es JSON pero el status es OK, considerar éxito
                console.log('Respuesta no es JSON pero status es OK, considerando éxito');
                return true;
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Error al eliminar archivos de audio');
            }
            
            console.log('Archivos de audio temporales eliminados');
            return true;
        } catch (error) {
            // Si el error es de parsing JSON, considerar éxito si el status era OK
            if (error instanceof SyntaxError && error.message.includes('JSON')) {
                console.log('Error al parsear respuesta JSON, pero la operación puede haber sido exitosa');
                return true;
            }
            console.error('Error al eliminar archivos de audio temporales:', error);
            return false;
        }
    },
    
    // Migrar audios temporales a permanentes cuando se guarda un informe
    migrateTempAudiosToPermanent: async function(informeId, estudioId, usuarioId) {
        try {
            console.log('=== INICIO MIGRACIÓN DE AUDIOS ===');
            console.log('Parámetros recibidos:', { informeId, estudioId, usuarioId });
            
            // Intentar obtener información del estudio desde diferentes fuentes
            let studyInfo = this.getStudyInfo();
            
            // Si no hay información desde URLESTUDIO, usar los parámetros proporcionados
            if (!studyInfo && estudioId) {
                studyInfo = {
                    studyId: estudioId,
                    patientId: 'unknown' // No tenemos patientId desde los parámetros
                };
                console.log('Usando parámetros proporcionados para studyInfo:', studyInfo);
            }
            
            if (!studyInfo) {
                console.log('No hay información de estudio para migrar audios');
                return { success: true, migrated: 0 };
            }
            
            // Obtener audios temporales
            const tempAudios = await this.getAudioFiles();
            console.log('Audios temporales encontrados:', tempAudios.length);
            
            if (tempAudios.length === 0) {
                console.log('No hay audios temporales para migrar');
                return { success: true, migrated: 0 };
            }
            
            console.log(`Migrando ${tempAudios.length} audios temporales a permanentes`);
            
            let migratedCount = 0;
            const sessionToken = localStorage.getItem('sessionToken');
            
            if (!sessionToken) {
                throw new Error('Token de sesión no encontrado');
            }
            
            console.log('Token de sesión encontrado, iniciando migración...');
            
            // Migrar cada audio temporal
            for (const tempAudio of tempAudios) {
                try {
                    console.log(`Migrando audio: ${tempAudio.fileName}`);
                    
                    // Descargar el archivo temporal
                    const response = await fetch(tempAudio.url);
                    const audioBlob = await response.blob();
                    
                    // Crear FormData para subir
                    const formData = new FormData();
                    formData.append('audio', audioBlob, tempAudio.fileName);
                    formData.append('informe_id', informeId);
                    formData.append('tipo_grabacion', tempAudio.audioType === 'sync' ? 'sincronizada' : 'simple');
                    formData.append('session_token', sessionToken);
                    
                    console.log('Enviando audio a API permanente...');
                    
                    // Subir a la API permanente
                    const uploadResponse = await fetch('../api/audios/upload.php', {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + sessionToken
                        },
                        body: formData
                    });
                    
                    console.log('Respuesta de upload:', uploadResponse.status);
                    
                    if (uploadResponse.ok) {
                        const result = await uploadResponse.json();
                        console.log('Resultado del upload:', result);
                        
                        if (result.success) {
                            migratedCount++;
                            console.log(`✅ Audio migrado: ${tempAudio.fileName} -> ${result.data.nombre_archivo}`);
                        } else {
                            console.error(`❌ Error migrando audio ${tempAudio.fileName}:`, result.message);
                        }
                    } else {
                        const errorText = await uploadResponse.text();
                        console.error(`❌ Error HTTP migrando audio ${tempAudio.fileName}:`, uploadResponse.status, errorText);
                    }
                    
                } catch (error) {
                    console.error(`❌ Error migrando audio ${tempAudio.fileName}:`, error);
                }
            }
            
            // Limpiar audios temporales después de migrar exitosamente
            if (migratedCount > 0) {
                console.log('Limpiando audios temporales...');
                await this.clearAudioFiles();
                console.log(`✅ Migración completada: ${migratedCount} audios migrados`);
            } else {
                console.log('⚠️ No se migró ningún audio');
            }
            
            console.log('=== FIN MIGRACIÓN DE AUDIOS ===');
            return { success: true, migrated: migratedCount };
            
        } catch (error) {
            console.error('❌ Error en migración de audios:', error);
            return { success: false, error: error.message, migrated: 0 };
        }
    },
    
    // Función específica para descartar informe completo (limpiar URLESTUDIO y eliminar audios)
    discardReport: async function(studyId = null, patientId = null) {
        try {
            // Si no se proporcionan parámetros, usar URLESTUDIO actual
            let targetStudyId = studyId;
            let targetPatientId = patientId;
            
            if (!targetStudyId || !targetPatientId) {
                const studyInfo = this.getStudyInfo();
                if (studyInfo) {
                    targetStudyId = studyInfo.studyId;
                    targetPatientId = studyInfo.patientId;
                }
            }
            
            if (!targetStudyId || !targetPatientId) {
                console.log('No hay información de estudio para descartar');
                return true;
            }
            
            console.log(`Descartando informe: ${targetStudyId} - ${targetPatientId}`);
            
            // Eliminar archivos de audio temporales del servidor
            const url = `${this.getBaseUrl()}?studyId=${encodeURIComponent(targetStudyId)}&patientId=${encodeURIComponent(targetPatientId)}`;
            const response = await fetch(url, {
                method: 'DELETE'
            });
            
            // Verificar si la respuesta es exitosa
            if (!response.ok) {
                // Si es 404, no hay archivos para eliminar (éxito)
                if (response.status === 404) {
                    console.log('No se encontraron archivos para eliminar (404)');
                } else {
                    console.warn(`Error HTTP ${response.status} al eliminar archivos de audio`);
                }
                return true;
            }
            
            // Verificar que la respuesta sea JSON antes de parsear
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const result = await response.json();
                if (result.success) {
                    console.log('Archivos de audio temporales eliminados del servidor');
                } else {
                    console.warn('Error al eliminar archivos de audio:', result.message);
                }
            } else {
                console.log('Archivos de audio temporales eliminados del servidor (respuesta no JSON)');
            }
            
            // Limpiar URLESTUDIO si coincide con el estudio descartado
            if (window.urlStudyManager && window.urlStudyManager.hasActiveStudy()) {
                const activeStudy = window.urlStudyManager.getActiveStudy();
                if (activeStudy && activeStudy.studyId === targetStudyId) {
                    window.urlStudyManager.clearActiveStudy();
                    console.log('URLESTUDIO limpiado');
                }
            }
            
            // Limpiar persistencia del editor para este estudio
            const persistenceKey = `editor_persistence_${targetStudyId}_${targetPatientId}`;
            localStorage.removeItem(persistenceKey);
            console.log('Persistencia del editor limpiada');
            
            // Limpiar estado global si existe
            if (window.globalStateManager) {
                window.globalStateManager.removeReport(targetStudyId);
                console.log('Estado global limpiado');
            }
            
            return true;
        } catch (error) {
            console.error('Error al descartar informe:', error);
            return false;
        }
    },
    
    // Restaurar audio en AudioModule
    restoreAudioInModule: async function(audioFile, module = 'AudioModule', retryCount = 0) {
        try {
            // Descargar el archivo de audio
            const response = await fetch(audioFile.url);
            const audioBlob = await response.blob();
            const audioUrl = URL.createObjectURL(audioBlob);
            
            // Determinar qué módulo usar
            let targetModule = null;
            if (module === 'AudioModule' && window.AudioModule) {
                targetModule = window.AudioModule;
            } else if (module === 'SyncEditorModule' && window.syncEditorInstance) {
                targetModule = window.syncEditorInstance;
            }
            
            if (!targetModule) {
                if (retryCount < 5) {
                    console.log(`Módulo ${module} no disponible, reintentando ${retryCount + 1}/5...`);
                    setTimeout(() => {
                        this.restoreAudioInModule(audioFile, module, retryCount + 1);
                    }, 1000);
                    return false;
                } else {
                    console.warn(`Módulo ${module} no disponible para restaurar audio después de 5 intentos`);
                    return false;
                }
            }
            
            // Verificación adicional para AudioModule
            if (module === 'AudioModule' && (!targetModule.recordingState || !targetModule.updateUI)) {
                if (retryCount < 5) {
                    console.log(`AudioModule no completamente inicializado, reintentando ${retryCount + 1}/5...`);
                    setTimeout(() => {
                        this.restoreAudioInModule(audioFile, module, retryCount + 1);
                    }, 1000);
                    return false;
                } else {
                    console.warn('AudioModule no está completamente inicializado después de 5 intentos');
                    return false;
                }
            }
            
            // Restaurar en AudioModule
            if (module === 'AudioModule') {
                targetModule.recordingState.currentAudioBlob = audioBlob;
                
                if (targetModule.displayRecording) {
                    targetModule.displayRecording(audioUrl);
                }
                
                console.log(`Audio restaurado en ${module}:`, {
                    fileName: audioFile.fileName,
                    audioType: audioFile.audioType,
                    size: audioFile.size
                });
            }
            // Restaurar en SyncEditorModule
            else if (module === 'SyncEditorModule') {
                targetModule.currentAudioBlob = audioBlob;
                
                // Configurar reproductor de audio
                const audioPlayer = document.getElementById('syncAudioPlayer');
                const audioContainer = document.getElementById('syncAudioContainer');
                
                if (audioPlayer && audioContainer) {
                    audioPlayer.src = audioUrl;
                    audioContainer.style.display = 'block';
                    
                    // Actualizar controles
                    const playButton = document.getElementById('syncPlayButton');
                    const downloadButton = document.getElementById('syncDownloadButton');
                    
                    if (playButton) playButton.style.display = 'inline-block';
                    if (downloadButton) downloadButton.style.display = 'inline-block';
                }
                
                console.log(`Audio restaurado en ${module}:`, {
                    fileName: audioFile.fileName,
                    audioType: audioFile.audioType,
                    size: audioFile.size
                });
            }
            
            return true;
        } catch (error) {
            console.error(`Error al restaurar audio en ${module}:`, error);
            return false;
        }
    },
    
    // Mostrar archivos de audio temporales como reproductores en las secciones correspondientes
    displayTempAudioFiles: async function() {
        try {
            const audioFiles = await this.getAudioFiles();
            
            if (audioFiles.length === 0) {
                console.log('No hay archivos de audio temporales para mostrar');
                return;
            }
            
            console.log(`Mostrando ${audioFiles.length} archivo(s) de audio temporal(es)`);
            
            // Separar archivos por tipo
            const regularAudios = audioFiles.filter(file => file.audioType === 'regular');
            const syncAudios = audioFiles.filter(file => file.audioType === 'sync');
            
            // Mostrar archivos regulares en la sección de Grabación de Audio
            if (regularAudios.length > 0) {
                this.displayAudioInSection(regularAudios, 'audioRecordingCollapse', 'Grabaciones Temporales');
            }
            
            // Mostrar archivos sincronizados en la sección de Grabación Sincronizada
            if (syncAudios.length > 0) {
                this.displayAudioInSection(syncAudios, 'syncRecordingCollapse', 'Grabaciones Sincronizadas Temporales');
            }
            
            console.log('Visualización de archivos de audio temporales completada');
            
        } catch (error) {
            console.error('Error al mostrar archivos de audio temporales:', error);
        }
    },

    // Mostrar archivos de audio en una sección específica
    displayAudioInSection: function(audioFiles, sectionId, title) {
        const section = document.getElementById(sectionId);
        if (!section) {
            console.warn(`Sección ${sectionId} no encontrada`);
            return;
        }
        
        // Buscar o crear contenedor para archivos temporales
        let tempContainer = section.querySelector('.temp-audio-container');
        if (!tempContainer) {
            tempContainer = document.createElement('div');
            tempContainer.className = 'temp-audio-container mt-3';
            tempContainer.innerHTML = `
                <div class="card border-info">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-history me-2"></i>
                            ${title}
                        </h6>
                    </div>
                    <div class="card-body temp-audio-list">
                        <!-- Los reproductores se agregarán aquí -->
                    </div>
                </div>
            `;
            section.querySelector('.card-body').appendChild(tempContainer);
        }
        
        const audioList = tempContainer.querySelector('.temp-audio-list');
        audioList.innerHTML = ''; // Limpiar contenido anterior
        
        // Crear reproductor para cada archivo
        audioFiles.forEach((audioFile, index) => {
            const audioItem = document.createElement('div');
            audioItem.className = 'temp-audio-item mb-3 p-3 border rounded';
            audioItem.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i>
                        ${new Date(audioFile.timestamp * 1000).toLocaleString()}
                    </small>
                    <button class="btn btn-outline-danger btn-sm" onclick="TempAudioManager.removeTempAudioItem('${audioFile.fileName}', this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <audio controls class="w-100" preload="metadata">
                    <source src="${audioFile.url}" type="audio/wav">
                    Tu navegador no soporta el elemento de audio.
                </audio>
            `;
            audioList.appendChild(audioItem);
        });
    },

    // Mostrar modal de confirmación Bootstrap
    showConfirmationModal: function(title, message, onConfirm) {
        const modalId = 'deleteAudioConfirmModal';
        
        // Remover modal existente si existe
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="${modalId}Label">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ${title}
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex align-items-center">
                                <div class="text-danger me-3">
                                    <i class="fas fa-trash-alt fa-2x"></i>
                                </div>
                                <div>
                                    <p class="mb-2">${message}</p>
                                    <p class="text-muted small mb-0">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Esta acción no se puede deshacer.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>
                                Cancelar
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                                <i class="fas fa-trash-alt me-2"></i>
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Configurar eventos
        const modal = new bootstrap.Modal(document.getElementById(modalId));
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        
        confirmBtn.addEventListener('click', () => {
            modal.hide();
            onConfirm();
        });
        
        // Limpiar modal cuando se cierre
        document.getElementById(modalId).addEventListener('hidden.bs.modal', function() {
            this.remove();
        });
        
        // Mostrar modal
        modal.show();
    },

    // Remover un elemento de audio temporal de la UI
    removeTempAudioItem: async function(fileName, buttonElement) {
        const title = 'Confirmar Eliminación';
        const message = `¿Estás seguro de que quieres eliminar el archivo de audio <strong>"${fileName}"</strong>?`;
        
        this.showConfirmationModal(title, message, async () => {
            try {
                const studyInfo = this.getStudyInfo();
                if (!studyInfo.studyId || !studyInfo.patientId) {
                    throw new Error('Información del estudio no disponible');
                }
                
                // Eliminar del servidor
                const response = await fetch(`${this.getBaseUrl()}?studyId=${studyInfo.studyId}&patientId=${studyInfo.patientId}&fileName=${encodeURIComponent(fileName)}`, {
                    method: 'DELETE'
                });
                
                // Verificar si la respuesta es exitosa
                if (!response.ok) {
                    // Si es 404, el archivo ya no existe, considerarlo éxito
                    if (response.status === 404) {
                        const audioItem = buttonElement.closest('.temp-audio-item');
                        if (audioItem) {
                            audioItem.remove();
                        }
                        console.log(`Archivo temporal ${fileName} ya no existe (404)`);
                        this.showSuccessMessage('Archivo eliminado correctamente');
                        return;
                    }
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        const result = await response.json();
                        throw new Error(result.message || `Error HTTP ${response.status}`);
                    } else {
                        throw new Error(`Error HTTP ${response.status}: ${response.statusText}`);
                    }
                }
                
                // Verificar que la respuesta sea JSON antes de parsear
                const contentType = response.headers.get('content-type');
                let result = { success: true };
                
                if (contentType && contentType.includes('application/json')) {
                    result = await response.json();
                } else {
                    // Si no es JSON pero el status es OK, considerar éxito
                    console.log('Respuesta no es JSON pero status es OK, considerando éxito');
                }
                
                if (result.success) {
                    // Remover del DOM
                    const audioItem = buttonElement.closest('.temp-audio-item');
                    if (audioItem) {
                        audioItem.remove();
                    }
                    console.log(`Archivo temporal ${fileName} eliminado correctamente`);
                    
                    // Mostrar mensaje de éxito
                    this.showSuccessMessage('Archivo eliminado correctamente');
                } else {
                    throw new Error(result.message || 'Error al eliminar el archivo');
                }
                
            } catch (error) {
                console.error('Error al eliminar archivo temporal:', error);
                this.showErrorMessage('Error al eliminar el archivo: ' + error.message);
            }
        });
    },

    // Mostrar mensaje de éxito
    showSuccessMessage: function(message) {
        this.showToast(message, 'success');
    },

    // Mostrar mensaje de error
    showErrorMessage: function(message) {
        this.showToast(message, 'error');
    },

    // Mostrar toast notification
    showToast: function(message, type = 'info') {
        const toastId = 'audioManagerToast_' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
        const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
        
        const toastHtml = `
            <div class="toast align-items-center text-white ${bgClass} border-0" id="${toastId}" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas ${icon} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        // Crear contenedor de toasts si no existe
        let toastContainer = document.getElementById('audioManagerToastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'audioManagerToastContainer';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        // Agregar toast
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        
        // Mostrar toast
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: type === 'error' ? 5000 : 3000
        });
        
        // Remover toast del DOM cuando se oculte
        toastElement.addEventListener('hidden.bs.toast', function() {
            this.remove();
        });
        
        toast.show();
    },

    // Restaurar todos los archivos de audio
    restoreAllAudio: async function(retryCount = 0) {
        // Evitar múltiples llamadas simultáneas
        if (this._restoreInProgress) {
            console.log('Restauración ya en progreso, esperando...');
            return new Promise((resolve) => {
                const checkInterval = setInterval(() => {
                    if (!this._restoreInProgress) {
                        clearInterval(checkInterval);
                        resolve({ success: true, audios: [] });
                    }
                }, 100);
            });
        }
        
        this._restoreInProgress = true;
        
        try {
            const audioFiles = await this.getAudioFiles();
            
            if (audioFiles.length === 0) {
                console.log('No hay archivos de audio temporales para restaurar');
                return { success: true, audios: [] };
            }
            
            console.log(`Restaurando ${audioFiles.length} archivo(s) de audio temporal(es)`);
            
            const restoredAudios = [];
            for (const audioFile of audioFiles) {
                const module = audioFile.audioType === 'sync' ? 'SyncEditorModule' : 'AudioModule';
                const restored = await this.restoreAudioInModule(audioFile, module, 0);
                if (restored) {
                    restoredAudios.push(audioFile);
                }
            }
            
            console.log('Restauración de archivos de audio completada');
            return { success: true, audios: restoredAudios };
        } catch (error) {
            console.error('Error al restaurar archivos de audio:', error);
            
            // Reintentar si es necesario
            if (retryCount < 3) {
                console.log(`Reintentando restauración (${retryCount + 1}/3)...`);
                await new Promise(resolve => setTimeout(resolve, 1000));
                return this.restoreAllAudio(retryCount + 1);
            }
            
            return { success: false, error: error.message, audios: [] };
        } finally {
            this._restoreInProgress = false;
        }
    },
    
    // Inicializar el gestor
    init: function() {
        console.log('TempAudioManager inicializado');
        
        // Restaurar archivos de audio al inicializar
        if (this.getStudyInfo()) {
            // Esperar un poco para que los módulos estén listos
            setTimeout(() => {
                this.restoreAllAudio();
            }, 1000);
        }
    }
};

// Hacer disponible globalmente
window.TempAudioManager = TempAudioManager;

// Auto-inicializar si el DOM está listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        TempAudioManager.init();
    });
} else {
    TempAudioManager.init();
}