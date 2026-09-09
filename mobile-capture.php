<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Captura Móvil - Portal de Estudios Médicos</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .capture-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            margin: 20px;
            padding: 30px;
        }
        
        .camera-preview {
            width: 100%;
            max-width: 400px;
            height: 300px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px auto;
            position: relative;
            overflow: hidden;
        }
        
        .camera-preview video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 13px;
        }
        
        .capture-btn {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 4px solid white;
            background: #dc3545;
            color: white;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px auto;
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
            transition: all 0.3s ease;
        }
        
        .capture-btn:active {
            transform: scale(0.95);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.5);
        }
        
        .capture-btn:disabled {
            background: #6c757d;
            box-shadow: none;
        }
        
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 20px;
        }
        
        .gallery-item {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            aspect-ratio: 1;
        }
        
        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .gallery-item .delete-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.8);
            color: white;
            border: none;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 2000;
        }
        
        .study-info {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .btn-mobile {
            border-radius: 20px;
            padding: 8px 16px;
            font-weight: 500;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .capture-btn {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dc3545, #c82333);
            border: 4px solid #fff;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
            color: white;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            transition: all 0.3s ease;
        }
        
        .capture-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.6);
        }
        
        .capture-btn:active {
            transform: scale(0.95);
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner">
        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
            <span class="visually-hidden">Cargando...</span>
        </div>
    </div>
    
    <!-- Status Indicator -->
    <div class="status-indicator">
        <div id="connection-status" class="badge bg-success">
            <i class="fas fa-wifi me-1"></i>Conectado
        </div>
    </div>
    
    <div class="container-fluid">
        <div class="capture-container">
            <!-- Header -->
            <div class="text-center mb-4">
                <h2 class="text-primary">
                    <i class="fas fa-camera me-2"></i>
                    Captura Móvil
                </h2>
                <p class="text-muted">Portal de Estudios Médicos</p>
            </div>
            
            <!-- Study Info -->
            <div id="study-info" class="study-info" style="display: none;">
                <h5 class="mb-2">
                    <i class="fas fa-user-md me-2"></i>
                    Información del Estudio
                </h5>
                <div id="study-details"></div>
            </div>
            
            <!-- Session Error -->
            <div id="session-error" class="alert alert-danger" style="display: none;">
                <h5 class="alert-heading">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Sesión Inválida
                </h5>
                <p class="mb-0">La sesión ha expirado o no es válida. Por favor, escanea nuevamente el código QR.</p>
            </div>
            
            <!-- Camera Section -->
            <div id="camera-section">
                <div class="text-center">
                    <h5 class="mb-3">
                        <i class="fas fa-video me-2"></i>
                        Cámara
                    </h5>
                    
                    <!-- Camera Preview -->
                    <div class="camera-preview" id="camera-preview">
                        <div class="text-muted">
                            <i class="fas fa-camera fa-3x mb-2"></i><br>
                            <span id="camera-status">Iniciando cámara...</span>
                        </div>
                    </div>
                    
                    <!-- Camera Controls -->
                    <div class="d-flex justify-content-center gap-2 mb-2">
                        <button class="btn btn-success btn-mobile" id="start-camera-btn">
                            <i class="fas fa-play me-1"></i>Iniciar
                        </button>
                        <button class="btn btn-danger btn-mobile" id="stop-camera-btn" disabled>
                            <i class="fas fa-stop me-1"></i>Detener
                        </button>
                    </div>
                    
                    <!-- Capture Button -->
                    <div class="text-center mb-2">
                        <button class="capture-btn" id="capture-btn" disabled>
                            <i class="fas fa-camera"></i>
                        </button>
                    </div>
                    
                    <!-- Help Button (discrete, below capture) -->
                    <div class="text-center">
                        <button class="btn btn-outline-info btn-sm" id="camera-help-btn" style="display: none;">
                            <i class="fas fa-question-circle me-1"></i>Ayuda
                        </button>
                    </div>
                    
                    <p class="text-muted mt-1 small">
                        <i class="fas fa-info-circle me-1"></i>
                        Toca el botón rojo para capturar
                    </p>
                    
                    <!-- Botón de ayuda para instrucciones -->
                    <div class="text-center mb-3">
                        <button id="help-instructions-btn" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-question-circle me-1"></i>
                            Ayuda con Permisos de Cámara
                        </button>
                    </div>
                    
                    <!-- Información sobre permisos (oculta por defecto) -->
                    <div id="camera-instructions" class="alert alert-light border mt-3" style="display: none;">
                        <div class="d-flex justify-content-between align-items-start">
                            <h6 class="alert-heading mb-0">
                                <i class="fas fa-shield-alt me-2"></i>
                                Permisos de Cámara
                            </h6>
                            <button id="close-instructions-btn" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <p class="mb-2">
                            <strong>Primera vez:</strong> Tu navegador te pedirá permiso para acceder a la cámara.
                        </p>
                        <div class="row">
                            <div class="col-6">
                                <small class="text-success">
                                    <i class="fas fa-check me-1"></i>
                                    Selecciona "Permitir"
                                </small>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">
                                    <i class="fas fa-times me-1"></i>
                                    No selecciones "Bloquear"
                                </small>
                            </div>
                        </div>
                        <p class="mb-0 mt-2">
                            <small class="text-info">
                                <i class="fas fa-lock me-1"></i>
                                Solo usamos la cámara para capturar imágenes médicas
                            </small>
                        </p>
                    </div>
                </div>
                
                <!-- Gallery -->
                <div id="gallery-section" style="display: none;">
                    <h5 class="mb-3">
                        <i class="fas fa-images me-2"></i>
                        Imágenes Capturadas
                    </h5>
                    <div id="gallery" class="gallery"></div>
                    
                    <div class="text-center mt-3">
                        <button class="btn btn-primary btn-mobile" id="upload-btn" disabled>
                            <i class="fas fa-cloud-upload-alt me-1"></i>
                            Subir Imágenes (<span id="image-count">0</span>)
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Upload Success -->
            <div id="upload-success" class="alert alert-success text-center" style="display: none;">
                <h5 class="alert-heading">
                    <i class="fas fa-check-circle me-2"></i>
                    ¡Subida Exitosa!
                </h5>
                <p class="mb-0">Las imágenes se han subido correctamente al estudio médico.</p>
                <button class="btn btn-outline-success mt-2" onclick="location.reload()">
                    <i class="fas fa-refresh me-1"></i>Capturar Más
                </button>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        class MobileCapture {
            constructor() {
                this.sessionId = this.getSessionId();
                this.studyId = null;
                this.stream = null;
                this.capturedImages = [];
                this.isCameraActive = false;
                
                this.init();
            }
            
            async init() {
                console.log('=== INICIO init() ===');
                console.log('SessionId:', this.sessionId);
                
                if (!this.sessionId) {
                    console.log('ERROR: No hay sessionId');
                    this.showSessionError();
                    return;
                }
                
                console.log('Validando sesión...');
                await this.validateSession();
                
                console.log('Configurando event listeners...');
                this.setupEventListeners();
                
                console.log('Verificando permisos de cámara...');
                this.checkCameraPermissions();
                
                // Iniciar monitoreo de cambio de token
                console.log('Iniciando monitoreo de token...');
                this.startTokenMonitoring();
                
                console.log('=== FIN init() ===');
            }
            
            async checkCameraPermissions() {
                try {
                    // Verificar si getUserMedia está disponible
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        this.updateCameraStatus('Cámara no soportada');
                        return;
                    }
                    
                    // Verificar permisos si está disponible
                    if (navigator.permissions) {
                        const permission = await navigator.permissions.query({ name: 'camera' });
                        console.log('Estado de permisos de cámara:', permission.state);
                        
                        if (permission.state === 'denied') {
                            this.updateCameraStatus('Permisos denegados');
                            this.showPermissionInstructions();
                        } else if (permission.state === 'granted') {
                            this.updateCameraStatus('Permisos concedidos');
                        } else {
                            this.updateCameraStatus('Permisos pendientes');
                        }
                        
                        // Escuchar cambios en permisos
                        permission.addEventListener('change', () => {
                            console.log('Cambio en permisos:', permission.state);
                            this.updateCameraStatus('Permisos: ' + permission.state);
                        });
                    } else {
                        this.updateCameraStatus('Verificar permisos al iniciar');
                    }
                    
                } catch (error) {
                    console.warn('Error verificando permisos:', error);
                    this.updateCameraStatus('Verificar permisos al iniciar');
                }
            }
            
            getSessionId() {
                const urlParams = new URLSearchParams(window.location.search);
                return urlParams.get('session');
            }
            
            getBaseUrl() {
                return window.location.origin + window.location.pathname.replace('/mobile-capture.php', '');
            }
            
            startTokenMonitoring() {
                // Verificar el token cada 5 segundos
                this.tokenMonitorInterval = setInterval(async () => {
                    await this.checkTokenValidity();
                }, 5000);
                
                console.log('Monitoreo de token iniciado (cada 5 segundos)');
            }
            
            stopTokenMonitoring() {
                if (this.tokenMonitorInterval) {
                    clearInterval(this.tokenMonitorInterval);
                    this.tokenMonitorInterval = null;
                    console.log('Monitoreo de token detenido');
                }
            }
            
            async checkTokenValidity() {
                try {
                    const baseUrl = this.getBaseUrl();
                    const apiUrl = `${baseUrl}/api/mobile_session.php?session_id=${this.sessionId}`;
                    
                    const response = await fetch(apiUrl);
                    const result = await response.json();
                    
                    if (!result.success) {
                        // Token inválido o expirado
                        console.warn('⚠️ Token inválido o expirado');
                        this.handleTokenInvalidation();
                    } else {
                        // Token válido - verificar si cambió el study_id (indica nuevo token)
                        if (this.studyId && result.data.study_id !== this.studyId) {
                            console.warn('⚠️ Token cambió - study_id diferente');
                            this.handleTokenChange();
                        }
                    }
                } catch (error) {
                    console.error('Error verificando validez del token:', error);
                }
            }
            
            handleTokenInvalidation() {
                console.log('=== TOKEN INVALIDADO ===');
                
                // Detener monitoreo
                this.stopTokenMonitoring();
                
                // Detener cámara si está activa
                if (this.isCameraActive) {
                    this.stopCamera();
                }
                
                // Mostrar mensaje al usuario
                this.showAlert(
                    'Sesión Cerrada',
                    'La sesión ha sido cerrada desde el escritorio. Esta página ya no es válida.',
                    'warning'
                );
                
                // Deshabilitar todos los controles
                this.disableAllControls();
                
                // Recargar página después de 3 segundos
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            }
            
            handleTokenChange() {
                console.log('=== TOKEN CAMBIÓ ===');
                
                // Detener monitoreo
                this.stopTokenMonitoring();
                
                // Detener cámara si está activa
                if (this.isCameraActive) {
                    this.stopCamera();
                }
                
                // Mostrar mensaje al usuario
                this.showAlert(
                    'Nueva Sesión Iniciada',
                    'Se ha generado un nuevo código QR desde el escritorio. Esta sesión ya no es válida.',
                    'info'
                );
                
                // Deshabilitar todos los controles
                this.disableAllControls();
                
                // Recargar página después de 3 segundos
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            }
            
            disableAllControls() {
                // Deshabilitar todos los botones
                const buttons = document.querySelectorAll('button');
                buttons.forEach(btn => {
                    btn.disabled = true;
                    btn.style.opacity = '0.5';
                    btn.style.cursor = 'not-allowed';
                });
                
                console.log('Todos los controles deshabilitados');
            }
            
            async validateSession() {
                try {
                    console.log('=== INICIO validateSession ===');
                    console.log('Session ID:', this.sessionId);
                    
                    this.showLoading(true);
                    
                    // Construir URL absoluta basada en el dominio actual
                    const baseUrl = this.getBaseUrl();
                    const apiUrl = `${baseUrl}/api/mobile_session.php?session_id=${this.sessionId}`;
                    console.log('URL base:', baseUrl);
                    console.log('URL de validación:', apiUrl);
                    
                    const response = await fetch(apiUrl);
                    console.log('Respuesta del servidor:', response.status, response.statusText);
                    
                    const result = await response.json();
                    console.log('Resultado de validación:', result);
                    
                    if (result.success) {
                        console.log('✅ Sesión válida');
                        this.studyId = result.data.study_id;
                        console.log('Study ID obtenido:', this.studyId);
                        await this.loadStudyInfo();
                        this.showCameraSection();
                    } else {
                        console.error('❌ Sesión inválida:', result.error);
                        this.showSessionError();
                    }
                } catch (error) {
                    console.error('=== ERROR en validateSession ===');
                    console.error('Error completo:', error);
                    console.error('Error message:', error.message);
                    console.error('Error stack:', error.stack);
                    console.error('=== FIN ERROR ===');
                    this.showSessionError();
                } finally {
                    this.showLoading(false);
                }
            }
            
            async loadStudyInfo() {
                try {
                    console.log('=== INICIO loadStudyInfo ===');
                    
                    // Obtener datos del estudio desde la sesión (almacenados en BD)
                    console.log('Obteniendo datos del estudio desde sesión:', this.sessionId);
                    const baseUrl = this.getBaseUrl();
                    const apiUrl = `${baseUrl}/api/mobile_session.php?session_id=${this.sessionId}`;
                    
                    const response = await fetch(apiUrl);
                    const result = await response.json();
                    
                    console.log('Respuesta de sesión:', result);
                    
                    if (result.success && result.data) {
                        const sessionData = result.data;
                        
                        // Usar datos de la sesión
                        document.getElementById('study-details').innerHTML = `
                            <div class="row">
                                <div class="col-6">
                                    <strong>Paciente:</strong><br>
                                    ${sessionData.patient_name || 'N/A'}
                                </div>
                                <div class="col-6">
                                    <strong>ID Paciente:</strong><br>
                                    ${sessionData.patient_id || 'N/A'}
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-6">
                                    <strong>Modalidad:</strong><br>
                                    ${sessionData.modality || 'N/A'}
                                </div>
                                <div class="col-6">
                                    <strong>Fecha:</strong><br>
                                    ${sessionData.study_date || 'N/A'}
                                </div>
                            </div>
                            ${sessionData.study_description ? `
                            <div class="row mt-2">
                                <div class="col-12">
                                    <strong>Descripción:</strong><br>
                                    <small class="text-muted">${sessionData.study_description}</small>
                                </div>
                            </div>
                            ` : ''}
                        `;
                        document.getElementById('study-info').style.display = 'block';
                        console.log('Información del estudio cargada desde sesión');
                    } else {
                        console.error('Error obteniendo datos de sesión:', result.error);
                        document.getElementById('study-info').style.display = 'none';
                    }
                    
                    console.log('=== FIN loadStudyInfo ===');
                } catch (error) {
                    console.error('Error cargando información del estudio:', error);
                    document.getElementById('study-info').style.display = 'none';
                }
            }
            
            setupEventListeners() {
                console.log('=== CONFIGURANDO EVENT LISTENERS ===');
                
                const startBtn = document.getElementById('start-camera-btn');
                console.log('Botón Iniciar encontrado:', !!startBtn);
                
                if (startBtn) {
                    startBtn.onclick = () => {
                        console.log('CLICK en botón Iniciar detectado');
                        this.startCamera();
                    };
                    console.log('Event listener del botón Iniciar configurado');
                } else {
                    console.error('ERROR: No se encontró el botón start-camera-btn');
                }
                
                document.getElementById('stop-camera-btn').onclick = () => this.stopCamera();
                document.getElementById('capture-btn').onclick = () => this.capturePhoto();
                document.getElementById('upload-btn').onclick = () => this.uploadImages();
                
                // Botones de ayuda para instrucciones
                document.getElementById('help-instructions-btn').onclick = () => this.showInstructions();
                document.getElementById('close-instructions-btn').onclick = () => this.hideInstructions();
                
                // Botón de ayuda durante uso de cámara
                document.getElementById('camera-help-btn').onclick = () => this.showCameraHelp();
                
                console.log('=== EVENT LISTENERS CONFIGURADOS ===');
            }
            
            showInstructions() {
                const instructions = document.getElementById('camera-instructions');
                const helpBtn = document.getElementById('help-instructions-btn');
                
                if (instructions) {
                    instructions.style.display = 'block';
                    // Scroll suave hacia las instrucciones
                    instructions.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                
                if (helpBtn) {
                    helpBtn.innerHTML = '<i class="fas fa-eye-slash me-1"></i>Ocultar Ayuda';
                    helpBtn.onclick = () => this.hideInstructions();
                }
            }
            
            hideInstructions() {
                const instructions = document.getElementById('camera-instructions');
                const helpBtn = document.getElementById('help-instructions-btn');
                
                if (instructions) {
                    instructions.style.display = 'none';
                }
                
                if (helpBtn) {
                    helpBtn.innerHTML = '<i class="fas fa-question-circle me-1"></i>Ayuda con Permisos de Cámara';
                    helpBtn.onclick = () => this.showInstructions();
                }
            }
            
            showCameraHelp() {
                // Crear modal de ayuda para uso de cámara
                const helpModal = document.createElement('div');
                helpModal.className = 'modal fade';
                helpModal.id = 'camera-help-modal';
                helpModal.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-camera me-2"></i>
                                    Ayuda - Uso de Cámara
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-lightbulb me-2"></i>Instrucciones de Uso:</h6>
                                    <ul class="mb-0">
                                        <li><strong>Capturar:</strong> Toca el botón rojo circular para tomar una foto</li>
                                        <li><strong>Múltiples fotos:</strong> Puedes capturar varias imágenes antes de subirlas</li>
                                        <li><strong>Subir:</strong> Usa el botón "Subir Imágenes" para enviar todas las fotos al estudio</li>
                                        <li><strong>Detener:</strong> Usa el botón "Detener" para cerrar la cámara</li>
                                    </ul>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Consejos:</h6>
                                    <ul class="mb-0">
                                        <li>Asegúrate de tener buena iluminación</li>
                                        <li>Mantén el dispositivo estable al capturar</li>
                                        <li>Las imágenes se guardan automáticamente en el estudio médico</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i>Cerrar
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                // Agregar modal al body
                document.body.appendChild(helpModal);
                
                // Mostrar modal usando Bootstrap
                const modal = new bootstrap.Modal(helpModal);
                modal.show();
                
                // Limpiar modal cuando se cierre
                helpModal.addEventListener('hidden.bs.modal', () => {
                    helpModal.remove();
                });
            }
            
            async startCamera() {
                try {
                    console.log('=== INICIO startCamera ===');
                    console.log('Botón Iniciar presionado');
                    
                    // Ocultar instrucciones de cámara y botón de ayuda inicial
                    const instructions = document.getElementById('camera-instructions');
                    const helpBtn = document.getElementById('help-instructions-btn');
                    const cameraHelpBtn = document.getElementById('camera-help-btn');
                    
                    console.log('Elementos encontrados:', {
                        instructions: !!instructions,
                        helpBtn: !!helpBtn,
                        cameraHelpBtn: !!cameraHelpBtn
                    });
                    
                    if (instructions) {
                        instructions.style.display = 'none';
                    }
                    
                    if (helpBtn) {
                        helpBtn.style.display = 'none';
                    }
                    
                    this.updateCameraStatus('Verificando permisos...');
                    console.log('Estado de cámara actualizado a: Verificando permisos...');
                    
                    // Verificar si getUserMedia está disponible
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        throw new Error('getUserMedia no está soportado en este navegador');
                    }
                    
                    // Configuración de cámara con múltiples opciones
                    const constraints = {
                        video: {
                            facingMode: { ideal: 'environment' }, // Cámara trasera preferida
                            width: { ideal: 1280, max: 1920 },
                            height: { ideal: 720, max: 1080 },
                            frameRate: { ideal: 30, max: 60 }
                        },
                        audio: false // No necesitamos audio
                    };
                    
                    this.updateCameraStatus('Solicitando acceso a la cámara...');
                    
                    // Intentar con configuración preferida
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia(constraints);
                    } catch (error) {
                        console.warn('Error con configuración preferida, intentando configuración básica:', error);
                        
                        // Fallback con configuración más básica
                        const basicConstraints = {
                            video: true,
                            audio: false
                        };
                        
                        this.stream = await navigator.mediaDevices.getUserMedia(basicConstraints);
                    }
                    
                    this.updateCameraStatus('Configurando video...');
                    
                    const video = document.createElement('video');
                    video.srcObject = this.stream;
                    video.autoplay = true;
                    video.muted = true;
                    video.playsInline = true;
                    video.controls = false;
                    
                    // Event listeners para el video
                    video.addEventListener('loadedmetadata', () => {
                        console.log('Video metadata cargado:', video.videoWidth, 'x', video.videoHeight);
                        this.updateCameraStatus('Cámara lista');
                    });
                    
                    video.addEventListener('canplay', () => {
                        console.log('Video listo para reproducir');
                        this.updateCameraStatus('Cámara activa');
                    });
                    
                    video.addEventListener('playing', () => {
                        console.log('Video reproduciéndose');
                        this.updateCameraStatus('Cámara activa');
                    });
                    
                    video.addEventListener('error', (e) => {
                        console.error('Error en video:', e);
                        this.updateCameraStatus('Error en video');
                    });
                    
                    const preview = document.getElementById('camera-preview');
                    preview.innerHTML = '';
                    preview.appendChild(video);
                    
                    this.isCameraActive = true;
                    
                    // Actualizar botones
                    document.getElementById('start-camera-btn').disabled = true;
                    document.getElementById('stop-camera-btn').disabled = false;
                    document.getElementById('capture-btn').disabled = false;
                    
                    // Ocultar instrucciones de permisos cuando la cámara esté activa
                    this.hidePermissionInstructions();
                    
                    // Mostrar botón de ayuda de cámara cuando esté activa
                    if (cameraHelpBtn) {
                        cameraHelpBtn.style.display = 'inline-block';
                    }
                    
                } catch (error) {
                    console.error('Error iniciando cámara:', error);
                    this.handleCameraError(error);
                }
            }
            
            stopCamera() {
                if (this.stream) {
                    this.stream.getTracks().forEach(track => track.stop());
                    this.stream = null;
                }
                
                this.isCameraActive = false;
                this.updateCameraStatus('Cámara detenida');
                
                // Ocultar botón de ayuda de cámara
                const cameraHelpBtn = document.getElementById('camera-help-btn');
                if (cameraHelpBtn) {
                    cameraHelpBtn.style.display = 'none';
                }
                
                // Mostrar botón de ayuda inicial nuevamente (las instrucciones siguen ocultas por defecto)
                const helpBtn = document.getElementById('help-instructions-btn');
                if (helpBtn) {
                    helpBtn.style.display = 'block';
                    // Resetear el botón a su estado inicial
                    helpBtn.innerHTML = '<i class="fas fa-question-circle me-1"></i>Ayuda con Permisos de Cámara';
                    helpBtn.onclick = () => this.showInstructions();
                }
                
                // Restaurar preview
                const preview = document.getElementById('camera-preview');
                preview.innerHTML = `
                    <div class="text-muted">
                        <i class="fas fa-camera fa-3x mb-2"></i><br>
                        <span id="camera-status">Cámara detenida</span>
                    </div>
                `;
                
                // Actualizar botones
                document.getElementById('start-camera-btn').disabled = false;
                document.getElementById('stop-camera-btn').disabled = true;
                document.getElementById('capture-btn').disabled = true;
            }
            
            capturePhoto() {
                if (!this.isCameraActive) return;
                
                try {
                    const video = document.querySelector('#camera-preview video');
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    
                    ctx.drawImage(video, 0, 0);
                    
                    const imageData = canvas.toDataURL('image/jpeg', 0.8);
                    
                    this.capturedImages.push({
                        id: Date.now(),
                        data: imageData,
                        timestamp: new Date().toLocaleString()
                    });
                    
                    this.updateGallery();
                    this.showAlert('Éxito', 'Imagen capturada correctamente', 'success');
                    
                } catch (error) {
                    console.error('Error capturando foto:', error);
                    this.showAlert('Error', 'No se pudo capturar la imagen', 'danger');
                }
            }
            
            updateGallery() {
                const gallery = document.getElementById('gallery');
                const gallerySection = document.getElementById('gallery-section');
                const uploadBtn = document.getElementById('upload-btn');
                const imageCount = document.getElementById('image-count');
                
                gallery.innerHTML = '';
                
                this.capturedImages.forEach(image => {
                    const item = document.createElement('div');
                    item.className = 'gallery-item';
                    item.innerHTML = `
                        <img src="${image.data}" alt="Captura">
                        <button class="delete-btn" onclick="mobileCapture.deleteImage(${image.id})">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    gallery.appendChild(item);
                });
                
                gallerySection.style.display = this.capturedImages.length > 0 ? 'block' : 'none';
                uploadBtn.disabled = this.capturedImages.length === 0;
                imageCount.textContent = this.capturedImages.length;
            }
            
            deleteImage(imageId) {
                this.capturedImages = this.capturedImages.filter(img => img.id !== imageId);
                this.updateGallery();
            }
            
            /**
             * Convierte dataURL a Blob de forma robusta (compatible con móviles)
             */
            dataURLtoBlob(dataURL) {
                console.log('Convirtiendo dataURL a Blob (método robusto)...');
                
                try {
                    // Separar el header del data
                    const parts = dataURL.split(',');
                    if (parts.length !== 2) {
                        throw new Error('dataURL inválido');
                    }
                    
                    const header = parts[0];
                    const data = parts[1];
                    
                    // Extraer el tipo MIME
                    const mimeMatch = header.match(/:(.*?);/);
                    const mime = mimeMatch ? mimeMatch[1] : 'image/jpeg';
                    
                    console.log('MIME type detectado:', mime);
                    
                    // Decodificar base64
                    const byteString = atob(data);
                    console.log('Bytes decodificados:', byteString.length);
                    
                    // Crear array de bytes
                    const arrayBuffer = new ArrayBuffer(byteString.length);
                    const uint8Array = new Uint8Array(arrayBuffer);
                    
                    for (let i = 0; i < byteString.length; i++) {
                        uint8Array[i] = byteString.charCodeAt(i);
                    }
                    
                    // Crear Blob
                    const blob = new Blob([uint8Array], { type: mime });
                    console.log('✅ Blob creado exitosamente:', blob.size, 'bytes, tipo:', blob.type);
                    
                    return blob;
                    
                } catch (error) {
                    console.error('❌ Error convirtiendo dataURL a Blob:', error);
                    throw error;
                }
            }
            
            async uploadImages() {
                if (this.capturedImages.length === 0) return;
                
                try {
                    console.log('=== INICIO uploadImages ===');
                    console.log('Imágenes a subir:', this.capturedImages.length);
                    console.log('User Agent:', navigator.userAgent);
                    console.log('Es móvil:', /Mobile|Android|iPhone|iPad/i.test(navigator.userAgent));
                    
                    this.showLoading(true);
                    
                    const currentUser = this.getCurrentUser();
                    console.log('Usuario actual:', currentUser);
                    if (!currentUser) {
                        throw new Error('Usuario no identificado');
                    }
                    
                    console.log('Study ID:', this.studyId);
                    console.log('Session ID:', this.sessionId);
                    
                    for (let i = 0; i < this.capturedImages.length; i++) {
                        const image = this.capturedImages[i];
                        console.log(`\n=== Procesando imagen ${i + 1}/${this.capturedImages.length} ===`);
                        console.log('Image ID:', image.id);
                        console.log('DataURL length:', image.data.length);
                        console.log('DataURL preview:', image.data.substring(0, 50) + '...');
                        
                        // Convertir dataURL a Blob usando método robusto
                        const blob = this.dataURLtoBlob(image.data);
                        
                        if (!blob || blob.size === 0) {
                            throw new Error('Blob vacío o inválido');
                        }
                        
                        // Crear FormData para subida temporal
                        const formData = new FormData();
                        formData.append('file', blob, `mobile_capture_${image.id}.jpg`);
                        formData.append('study_id', this.studyId);
                        formData.append('session_id', this.sessionId);
                        
                        console.log('FormData creado con:', {
                            fileName: `mobile_capture_${image.id}.jpg`,
                            blobSize: blob.size,
                            blobType: blob.type,
                            studyId: this.studyId,
                            sessionId: this.sessionId
                        });
                        
                        // Verificar que FormData contiene el archivo
                        console.log('Verificando FormData...');
                        for (let pair of formData.entries()) {
                            if (pair[1] instanceof Blob) {
                                console.log(`  ${pair[0]}: Blob(${pair[1].size} bytes, ${pair[1].type})`);
                            } else {
                                console.log(`  ${pair[0]}: ${pair[1]}`);
                            }
                        }
                        
                        // Subir imagen a carpeta TEMPORAL (no permanente)
                        const uploadUrl = `${this.getBaseUrl()}/api/upload_temp_mobile_image.php`;
                        console.log('Subiendo a carpeta temporal, URL:', uploadUrl);
                        console.log('Iniciando fetch...');
                        
                        const uploadResponse = await fetch(uploadUrl, {
                            method: 'POST',
                            body: formData
                        });
                        
                        console.log('✅ Fetch completado');
                        console.log('Status:', uploadResponse.status, uploadResponse.statusText);
                        console.log('Headers:', Object.fromEntries(uploadResponse.headers.entries()));
                        
                        // Leer respuesta como texto primero para debugging
                        const responseText = await uploadResponse.text();
                        console.log('Response text (primeros 500 chars):', responseText.substring(0, 500));
                        
                        // Intentar parsear como JSON
                        let result;
                        try {
                            result = JSON.parse(responseText);
                            console.log('✅ JSON parseado exitosamente:', result);
                        } catch (parseError) {
                            console.error('❌ Error parseando JSON:', parseError);
                            console.error('Response completo:', responseText);
                            throw new Error('Respuesta del servidor no es JSON válido');
                        }
                        
                        if (!result.success) {
                            throw new Error(result.message || 'Error subiendo imagen');
                        }
                        
                        console.log(`Imagen ${i + 1} subida exitosamente`);
                        
                        // Notificar al modal padre sobre cada imagen subida (en tiempo real)
                        this.notifyParentOfImageCapture(result.data);
                    }
                    
                    console.log('Todas las imágenes subidas exitosamente');
                    
                    // Notificar al modal padre que se completó la subida
                    this.notifyParentOfUpload();
                    
                    this.showUploadSuccess();
                    
                } catch (error) {
                    console.error('=== ERROR en uploadImages ===');
                    console.error('Error completo:', error);
                    console.error('Error message:', error.message);
                    console.error('Error stack:', error.stack);
                    console.error('=== FIN ERROR ===');
                    
                    this.showAlert('Error', 'No se pudieron subir las imágenes: ' + error.message, 'danger');
                } finally {
                    this.showLoading(false);
                }
            }
            
            notifyParentOfImageCapture(imageData) {
                try {
                    console.log('=== NOTIFICANDO CAPTURA DE IMAGEN ===');
                    console.log('Datos de imagen recibidos:', imageData);
                    console.log('studyId:', this.studyId);
                    console.log('sessionId:', this.sessionId);
                    
                    const message = {
                        type: 'mobile_image_captured',
                        studyId: this.studyId,
                        sessionId: this.sessionId,
                        image: imageData,
                        timestamp: new Date().toISOString()
                    };
                    
                    console.log('Mensaje a enviar:', message);
                    
                    // Enviar mensaje al window padre si existe
                    if (window.opener) {
                        console.log('Enviando a window.opener...');
                        window.opener.postMessage(message, '*');
                        console.log('✅ Mensaje enviado a window.opener');
                    } else {
                        console.warn('⚠️ window.opener no está disponible');
                    }
                    
                    // También enviar mensaje al window principal
                    console.log('Enviando a window (self)...');
                    window.postMessage({
                        type: 'mobile_image_captured',
                        studyId: this.studyId,
                        sessionId: this.sessionId,
                        image: imageData,
                        timestamp: new Date().toISOString()
                    }, '*');
                    console.log('✅ Mensaje enviado a window (self)');
                    console.log('=== FIN NOTIFICACIÓN ===');
                    
                } catch (error) {
                    console.error('❌ Error notificando captura de imagen:', error);
                    console.error('Stack:', error.stack);
                }
            }
            
            notifyParentOfUpload() {
                try {
                    console.log('Notificando al modal padre sobre la subida de imágenes...');
                    
                    // Enviar mensaje al window padre si existe
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'mobile_images_uploaded',
                            studyId: this.studyId,
                            sessionId: this.sessionId,
                            timestamp: new Date().toISOString()
                        }, '*');
                        console.log('Mensaje enviado a window.opener');
                    }
                    
                    // También enviar mensaje al window principal
                    window.postMessage({
                        type: 'mobile_images_uploaded',
                        studyId: this.studyId,
                        sessionId: this.sessionId,
                        timestamp: new Date().toISOString()
                    }, '*');
                    console.log('Mensaje enviado a window principal');
                    
                } catch (error) {
                    console.error('Error notificando al padre:', error);
                }
            }
            
            getCurrentUser() {
                try {
                    // Intentar obtener usuario de la sesión móvil
                    if (this.sessionId) {
                        // Obtener información de la sesión desde el servidor
                        const sessionUrl = `${this.getBaseUrl()}/api/mobile_session.php?session_id=${this.sessionId}`;
                        
                        // Hacer una llamada síncrona para obtener el usuario
                        const xhr = new XMLHttpRequest();
                        xhr.open('GET', sessionUrl, false); // false = síncrono
                        xhr.send();
                        
                        if (xhr.status === 200) {
                            const result = JSON.parse(xhr.responseText);
                            if (result.success && result.data.created_by) {
                                console.log('Usuario obtenido de la sesión:', result.data.created_by);
                                return {
                                    id: result.data.created_by,
                                    name: 'Usuario de Sesión'
                                };
                            }
                        }
                    }
                    
                    // Fallback: usuario por defecto
                    console.log('Usando usuario por defecto');
                    return {
                        id: 1,
                        name: 'Usuario Móvil'
                    };
                    
                } catch (error) {
                    console.error('Error obteniendo usuario:', error);
                    return {
                        id: 1,
                        name: 'Usuario Móvil'
                    };
                }
            }
            
            updateCameraStatus(status) {
                const statusElement = document.getElementById('camera-status');
                if (statusElement) {
                    statusElement.textContent = status;
                }
            }
            
            showCameraSection() {
                document.getElementById('camera-section').style.display = 'block';
            }
            
            showSessionError() {
                console.log('Mostrando error de sesión');
                const sessionErrorDiv = document.getElementById('session-error');
                const cameraSectionDiv = document.getElementById('camera-section');
                
                if (sessionErrorDiv) {
                    sessionErrorDiv.style.display = 'block';
                    // Actualizar el mensaje con información específica
                    const errorMessage = sessionErrorDiv.querySelector('p');
                    if (errorMessage) {
                        errorMessage.innerHTML = `
                            La sesión ha expirado o no es válida.<br>
                            <small class="text-muted">
                                Session ID: ${this.sessionId}<br>
                                Tiempo: ${new Date().toLocaleString()}
                            </small><br>
                            Por favor, escanea nuevamente el código QR.
                        `;
                    }
                }
                
                if (cameraSectionDiv) {
                    cameraSectionDiv.style.display = 'none';
                }
            }
            
            showUploadSuccess() {
                document.getElementById('camera-section').style.display = 'none';
                document.getElementById('upload-success').style.display = 'block';
            }
            
            showLoading(show) {
                document.querySelector('.loading-spinner').style.display = show ? 'block' : 'none';
            }
            
            handleCameraError(error) {
                console.error('Error de cámara:', error);
                
                let errorMessage = 'Error desconocido';
                let errorType = 'danger';
                
                if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                    errorMessage = 'Permisos de cámara denegados. Por favor, permite el acceso a la cámara en la configuración de tu navegador.';
                    errorType = 'warning';
                } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
                    errorMessage = 'No se encontró ninguna cámara en este dispositivo.';
                    errorType = 'danger';
                } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
                    errorMessage = 'La cámara está siendo utilizada por otra aplicación. Cierra otras aplicaciones que usen la cámara.';
                    errorType = 'warning';
                } else if (error.name === 'OverconstrainedError' || error.name === 'ConstraintNotSatisfiedError') {
                    errorMessage = 'La configuración de cámara solicitada no es compatible con este dispositivo.';
                    errorType = 'warning';
                } else if (error.name === 'NotSupportedError') {
                    errorMessage = 'Este navegador no soporta acceso a la cámara.';
                    errorType = 'danger';
                } else if (error.name === 'SecurityError') {
                    errorMessage = 'Error de seguridad. Asegúrate de que estés usando HTTPS.';
                    errorType = 'danger';
                }
                
                this.updateCameraStatus('Error: ' + error.name);
                this.showAlert('Error de Cámara', errorMessage, errorType);
                
                // Mostrar instrucciones específicas para permisos
                if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                    this.showPermissionInstructions();
                }
            }
            
            showPermissionInstructions() {
                const instructions = document.createElement('div');
                instructions.id = 'permission-instructions';
                instructions.className = 'alert alert-info mt-3';
                instructions.innerHTML = `
                    <h6 class="alert-heading">
                        <i class="fas fa-camera me-2"></i>
                        Instrucciones para Permitir Cámara
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fab fa-chrome me-1"></i> Chrome/Edge:</h6>
                            <ol class="small">
                                <li>Toca el ícono de cámara en la barra de direcciones</li>
                                <li>Selecciona "Permitir"</li>
                                <li>Recarga la página</li>
                            </ol>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fab fa-safari me-1"></i> Safari:</h6>
                            <ol class="small">
                                <li>Ve a Configuración > Safari</li>
                                <li>Permisos de cámara > Permitir</li>
                                <li>Recarga la página</li>
                            </ol>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <h6><i class="fab fa-firefox me-1"></i> Firefox:</h6>
                            <ol class="small">
                                <li>Toca el ícono de escudo en la barra de direcciones</li>
                                <li>Permitir cámara</li>
                                <li>Recarga la página</li>
                            </ol>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-mobile-alt me-1"></i> General:</h6>
                            <ul class="small">
                                <li>Asegúrate de usar HTTPS</li>
                                <li>Cierra otras apps que usen la cámara</li>
                                <li>Reinicia el navegador si es necesario</li>
                            </ul>
                        </div>
                    </div>
                    <div class="text-center mt-3">
                        <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                            <i class="fas fa-refresh me-1"></i>Recargar Página
                        </button>
                    </div>
                `;
                
                // Insertar después del preview de cámara
                const cameraPreview = document.getElementById('camera-preview');
                if (cameraPreview && cameraPreview.parentNode) {
                    cameraPreview.parentNode.insertBefore(instructions, cameraPreview.nextSibling);
                }
            }
            
            hidePermissionInstructions() {
                const instructions = document.getElementById('permission-instructions');
                if (instructions) {
                    instructions.remove();
                    console.log('Instrucciones de permisos ocultadas');
                }
            }
            
            showAlert(title, message, type) {
                // Crear alerta temporal
                const alert = document.createElement('div');
                alert.className = `alert alert-${type} alert-dismissible fade show`;
                alert.style.position = 'fixed';
                alert.style.top = '20px';
                alert.style.left = '50%';
                alert.style.transform = 'translateX(-50%)';
                alert.style.zIndex = '3000';
                alert.innerHTML = `
                    <strong>${title}</strong> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                
                document.body.appendChild(alert);
                
                // Auto-remove después de 5 segundos para errores de permisos
                const timeout = type === 'warning' ? 5000 : 3000;
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.parentNode.removeChild(alert);
                    }
                }, timeout);
            }
        }
        
        // Inicializar cuando el DOM esté listo
        let mobileCapture;
        document.addEventListener('DOMContentLoaded', () => {
            mobileCapture = new MobileCapture();
        });
    </script>
</body>
</html>
