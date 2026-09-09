// Módulo de integración PACS DICOM
const DICOMModule = {
    // Configuración del PACS
    config: {
        pacsURL: 'https://losalisos.tanjousoft.com.ar/orthanc',
        viewerURL: 'https://losalisos.tanjousoft.com.ar/u-dicom-viewer/',
        viewerPacsName: 'LOSALISOS',
        apiEndpoint: '/api/studies'
    },

    // Estado de la conexión
    connectionState: {
        isConnected: false,
        lastError: null
    },

    // Inicializar conexión con PACS
    initConnection: async function() {
        // Deshabilitar conexión automática para evitar errores CORS en desarrollo
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.log('Conexión PACS deshabilitada en entorno de desarrollo');
            this.connectionState.isConnected = false;
            this.connectionState.lastError = 'Conexión deshabilitada en desarrollo';
            return false;
        }
        
        try {
            const response = await fetch(this.config.pacsURL + '/system');
            if (response.ok) {
                this.connectionState.isConnected = true;
                this.connectionState.lastError = null;
                return true;
            } else {
                throw new Error('Error en la conexión con PACS');
            }
        } catch (error) {
            this.connectionState.isConnected = false;
            this.connectionState.lastError = error.message;
            console.log('PACS no disponible:', error.message);
            return false;
        }
    },

    /**
     * Obtener token de sesión
     */
    getSessionToken: function() {
        // Intentar obtener token de múltiples fuentes
        if (window.AuthMiddleware && typeof window.AuthMiddleware.getToken === 'function') {
            return window.AuthMiddleware.getToken();
        }
        if (window.authMiddleware && typeof window.authMiddleware.getToken === 'function') {
            return window.authMiddleware.getToken();
        }
        // Fallback: obtener de localStorage o cookie
        return localStorage.getItem('session_token') || 
               document.cookie.split('; ').find(row => row.startsWith('session_token='))?.split('=')[1];
    },

    /**
     * Obtener URL base de la API
     */
    getApiBaseUrl: function() {
        const path = window.location.pathname;
        if (path.includes('/components/')) {
            return '../api/informes';
        }
        return 'api/informes';
    },

    /**
     * Enviar informe actual a Orthanc PACS
     */
    sendReport: async function() {
        try {
            // Obtener ID del informe desde la URL o el estado del editor
            const urlParams = new URLSearchParams(window.location.search);
            let informeId = urlParams.get('informe_id') || urlParams.get('id');
            
            // Si no está en la URL, intentar obtener del estado del editor
            if (!informeId && window.ReportsModule && window.ReportsModule.editorState && window.ReportsModule.editorState.currentReportId) {
                informeId = window.ReportsModule.editorState.currentReportId;
            }
            
            // Si aún no hay ID, intentar obtener del editor o pedir al usuario
            if (!informeId) {
                // Verificar si hay un informe guardado pero no cargado desde URL
                const savedData = localStorage.getItem('editor_current_report');
                if (savedData) {
                    try {
                        const parsed = JSON.parse(savedData);
                        informeId = parsed.id || parsed.informe_id;
                    } catch (e) {
                        console.warn('No se pudo parsear savedData:', e);
                    }
                }
            }
            
            if (!informeId) {
                alert('No se puede enviar el informe a PACS.\n\n' +
                      'El informe debe estar guardado primero. Por favor, guarda el informe antes de enviarlo a PACS.');
                return;
            }
            
            // Confirmar envío
            const confirmMsg = '¿Estás seguro de que deseas enviar este informe a Orthanc PACS?\n\n' +
                             'Esto convertirá el informe en un objeto DICOM y lo enviará al servidor PACS.\n\n' +
                             'Asegúrate de que el informe esté completo y correcto antes de enviarlo.';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Mostrar indicador de carga
            const statusMessage = document.getElementById('statusMessage');
            if (statusMessage) {
                statusMessage.className = 'alert alert-info mt-3';
                statusMessage.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Enviando informe a Orthanc PACS...';
                statusMessage.style.display = 'block';
            }
            
            const token = this.getSessionToken();
            const apiBaseUrl = this.getApiBaseUrl();
            const url = apiBaseUrl.replace('/informes', '') + '/informes/send-to-pacs.php';
            
            // Obtener formato configurado desde la API
            let formatoConfigurado = 'pdf'; // Valor por defecto
            
            try {
                const configUrl = apiBaseUrl.replace('/informes', '') + '/informes/config-formato-pacs.php';
                const configResponse = await fetch(configUrl, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    }
                });
                
                if (configResponse.ok) {
                    const configData = await configResponse.json();
                    if (configData.success && configData.formato) {
                        formatoConfigurado = configData.formato;
                        console.log('📄 Formato configurado obtenido:', formatoConfigurado);
                    }
                }
            } catch (configError) {
                console.warn('⚠️ No se pudo obtener formato configurado, usando PDF por defecto:', configError);
                formatoConfigurado = 'pdf';
            }
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                body: JSON.stringify({
                    informe_id: parseInt(informeId),
                    format: formatoConfigurado,
                    check_duplicates: true
                })
            });
            
            const result = await response.json();
            
            if (statusMessage) {
                if (result.success) {
                    const title = result.is_update ? 'Informe Actualizado' : '¡Éxito!';
                    const message = result.is_update 
                        ? 'Informe actualizado y reenviado exitosamente a Orthanc PACS.'
                        : 'Informe enviado exitosamente a Orthanc PACS.';
                    let details = '';
                    if (result.data) {
                        details = `Instance ID: ${result.data.instance_id}`;
                        if (result.is_update && result.old_instance_id) {
                            details += ` | Versión anterior eliminada: ${result.old_instance_id}`;
                        }
                    }
                    statusMessage.className = 'alert alert-success mt-3';
                    statusMessage.innerHTML = `<i class="fas fa-check-circle me-2"></i><strong>${title}</strong> ${message} ${details}`;
                } else {
                    if (result.duplicate) {
                        statusMessage.className = 'alert alert-warning mt-3';
                        statusMessage.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i><strong>Duplicado:</strong> ' +
                                                 'Ya existe un estudio con estos datos en Orthanc PACS. ' +
                                                 (result.study_id ? `Study ID: ${result.study_id}` : '');
                    } else {
                        statusMessage.className = 'alert alert-danger mt-3';
                        statusMessage.innerHTML = '<i class="fas fa-times-circle me-2"></i><strong>Error:</strong> ' +
                                                 (result.message || 'Error desconocido al enviar a PACS');
                    }
                }
            }
            
            // Mostrar mensaje adicional en consola
            if (result.success) {
                console.log('✅ Informe enviado exitosamente a Orthanc PACS:', result.data);
            } else {
                console.error('❌ Error enviando a PACS:', result);
            }
            
        } catch (error) {
            console.error('Error en sendReport:', error);
            const statusMessage = document.getElementById('statusMessage');
            if (statusMessage) {
                statusMessage.className = 'alert alert-danger mt-3';
                statusMessage.innerHTML = '<i class="fas fa-times-circle me-2"></i><strong>Error:</strong> ' +
                                         'Error al enviar informe a PACS: ' + error.message;
            }
            alert('Error al enviar informe a PACS:\n\n' + error.message);
        }
    },

    // Enviar informe al PACS (método alternativo con parámetros)
    sendReportWithParams: async function(studyId, reportContent) {
        try {
            if (!this.connectionState.isConnected) {
                await this.initConnection();
            }

            const response = await fetch(`${this.config.pacsURL}${this.config.apiEndpoint}/${studyId}/report`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    content: reportContent,
                    timestamp: new Date().toISOString(),
                    format: 'html'
                })
            });

            if (!response.ok) {
                throw new Error('Error al enviar informe al PACS');
            }

            return await response.json();
        } catch (error) {
            console.error('Error al enviar informe:', error);
            throw error;
        }
    },

    // Obtener visor DICOM para un estudio
    getViewer: function(studyId) {
        // Construir URL con parámetros: ?pacs=NOMBREPACS&studyId=ID
        const pacsName = encodeURIComponent(this.config.viewerPacsName || 'LOSALISOS');
        const studyIdEncoded = encodeURIComponent(studyId);
        return `${this.config.viewerURL}?pacs=${pacsName}&studyId=${studyIdEncoded}`;
    },

    // Obtener lista de estudios
    getStudies: async function(filters = {}) {
        try {
            if (!this.connectionState.isConnected) {
                await this.initConnection();
            }

            const queryParams = new URLSearchParams(filters).toString();
            const response = await fetch(`${this.config.pacsURL}${this.config.apiEndpoint}?${queryParams}`);

            if (!response.ok) {
                throw new Error('Error al obtener lista de estudios');
            }

            return await response.json();
        } catch (error) {
            console.error('Error al obtener estudios:', error);
            throw error;
        }
    },

    // Obtener detalles de un estudio específico
    getStudyDetails: async function(studyId) {
        try {
            if (!this.connectionState.isConnected) {
                await this.initConnection();
            }

            const response = await fetch(`${this.config.pacsURL}${this.config.apiEndpoint}/${studyId}`);

            if (!response.ok) {
                throw new Error('Error al obtener detalles del estudio');
            }

            return await response.json();
        } catch (error) {
            console.error('Error al obtener detalles del estudio:', error);
            throw error;
        }
    },

    // Verificar estado de conexión
    checkConnection: async function() {
        const status = await this.initConnection();
        return {
            connected: status,
            lastError: this.connectionState.lastError
        };
    }
};
