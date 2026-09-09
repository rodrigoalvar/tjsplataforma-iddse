/**
 * JavaScript para la gestión de configuraciones del sistema
 */

let allConfigurations = {};

/**
 * Cargar todas las configuraciones
 */
async function loadConfigurations() {
    showLoading(true);
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/config/manage.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });

        const data = await response.json();

        if (data.success) {
            allConfigurations = data.configurations;
            renderConfigurations();
        } else {
            showAlert('error', 'Error al cargar configuraciones: ' + data.message);
        }
    } catch (error) {
        console.error('Error cargando configuraciones:', error);
        showAlert('error', 'Error al cargar configuraciones: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Renderizar configuraciones en las pestañas
 */
function renderConfigurations() {
    renderCategory('database', allConfigurations.database || {});
    renderCategory('pacs', allConfigurations.pacs || {});
    renderCategory('study_routing', allConfigurations.study_routing || {});
    renderCategory('portal_estudios', allConfigurations.portal_estudios || {});
    renderCategory('informes_recibidos', allConfigurations.informes_recibidos || {});
    renderCategory('estudios_recibidos', allConfigurations.estudios_recibidos || {});
    renderCategory('gasalud_envio', allConfigurations.gasalud_envio || {});
    renderCategory('urls', allConfigurations.urls || {});
    renderCategory('general', allConfigurations.general || {});
    if (typeof loadSlaPlantillas === 'function') {
        loadSlaPlantillas();
    }
    if (typeof updateSlaLuaSnippet === 'function') {
        updateSlaLuaSnippet();
    }
    if (typeof syncGasaludAuthFieldsVisibility === 'function') {
        syncGasaludAuthFieldsVisibility();
    }
}

/**
 * Renderizar una categoría de configuración
 */
function renderCategory(category, configs) {
    const container = document.getElementById(category + 'Config');
    if (!container) return;

    container.innerHTML = '';

    if (Object.keys(configs).length === 0) {
        container.innerHTML = '<p class="text-muted">No hay configuraciones disponibles para esta categoría.</p>';
        return;
    }

    // Para PACS, organizar en secciones
    if (category === 'pacs') {
        renderPacsConfig(configs, container);
        return;
    }
    
    // Para General, organizar en secciones
    if (category === 'general') {
        renderGeneralConfig(configs, container);
        return;
    }

    Object.keys(configs).forEach(key => {
        const config = configs[key];
        const configItem = createConfigItem(config);
        container.appendChild(configItem);
    });
}

/**
 * Renderizar configuraciones generales organizadas en secciones
 */
function renderGeneralConfig(configs, container) {
    // Sección: Personalización de la Aplicación
    const appKeys = ['app_titulo', 'app_logo'];
    
    // Siempre mostrar la sección de personalización, incluso si no hay configuraciones
    const appSection = document.createElement('div');
    appSection.className = 'config-section';
    appSection.innerHTML = '<h4 style="margin-top: 0; margin-bottom: 20px; color: #667eea; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-paint-brush"></i> Personalización de la Aplicación</h4>';
    container.appendChild(appSection);
    
    appKeys.forEach(key => {
        // Si existe la configuración, usarla; si no, crear una por defecto
        let config = configs[key];
        if (!config) {
            // Crear configuración por defecto para app_logo si no existe
            if (key === 'app_logo') {
                config = {
                    key: 'app_logo',
                    value: '',
                    description: 'Ruta del logo de la aplicación (opcional)',
                    category: 'general',
                    type: 'file',
                    readonly: false
                };
            } else if (key === 'app_titulo') {
                config = {
                    key: 'app_titulo',
                    value: 'GESTION DE ESTUDIOS',
                    description: 'Título de la aplicación que se muestra en la pantalla de inicio',
                    category: 'general',
                    type: 'text',
                    readonly: false
                };
            }
        }
        
        if (config) {
            const configItem = createConfigItem(config);
            appSection.appendChild(configItem);
        }
    });
    
    // Otras configuraciones generales
    const otherKeys = Object.keys(configs).filter(key => !appKeys.includes(key));
    if (otherKeys.length > 0) {
        const otherSection = document.createElement('div');
        otherSection.className = 'config-section';
        otherSection.innerHTML = '<h4 style="margin-top: 30px; margin-bottom: 20px; color: #333; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;"><i class="fas fa-cog"></i> Otras Configuraciones</h4>';
        container.appendChild(otherSection);
        
        otherKeys.forEach(key => {
            if (configs[key]) {
                const configItem = createConfigItem(configs[key]);
                otherSection.appendChild(configItem);
            }
        });
    }
}

/**
 * Renderizar configuraciones de PACS organizadas en secciones
 */
function renderPacsConfig(configs, container) {
    // Sección: Servidor
    const serverSection = document.createElement('div');
    serverSection.className = 'config-section';
    serverSection.innerHTML = '<h4 style="margin-top: 20px; margin-bottom: 15px; color: #2196F3; border-bottom: 2px solid #2196F3; padding-bottom: 8px;"><i class="fas fa-server"></i> Configuración del Servidor Orthanc</h4>';
    container.appendChild(serverSection);
    
    const serverKeys = ['host', 'port', 'protocol', 'username', 'password', 'timeout', 'connect_timeout', 'verify_ssl'];
    serverKeys.forEach(key => {
        if (configs[key]) {
            const configItem = createConfigItem(configs[key]);
            serverSection.appendChild(configItem);
        }
    });
    
    // Sección: PACS General
    if (configs.pacs_name) {
        const pacsSection = document.createElement('div');
        pacsSection.className = 'config-section';
        pacsSection.innerHTML = '<h4 style="margin-top: 30px; margin-bottom: 15px; color: #2196F3; border-bottom: 2px solid #2196F3; padding-bottom: 8px;"><i class="fas fa-tag"></i> Nombre del PACS</h4>';
        container.appendChild(pacsSection);
        const configItem = createConfigItem(configs.pacs_name);
        pacsSection.appendChild(configItem);
    }
    
    // Sección: Visor UDV
    const udvKeys = ['viewer_udv_url', 'viewer_udv_format', 'viewer_udv_study_id_param', 'viewer_udv_pacs_name'];
    if (udvKeys.some(key => configs[key])) {
        const udvSection = document.createElement('div');
        udvSection.className = 'config-section';
        udvSection.innerHTML = '<h4 style="margin-top: 30px; margin-bottom: 15px; color: #4CAF50; border-bottom: 2px solid #4CAF50; padding-bottom: 8px;"><i class="fas fa-eye"></i> Visor UDV (Universal DICOM Viewer)</h4>';
        container.appendChild(udvSection);
        udvKeys.forEach(key => {
            if (configs[key]) {
                const configItem = createConfigItem(configs[key]);
                udvSection.appendChild(configItem);
            }
        });
    }
    
    // Sección: Visor StoneViewer
    const stoneKeys = ['viewer_stoneviewer_url', 'viewer_stoneviewer_remote_url', 'viewer_stoneviewer_format', 'viewer_stoneviewer_study_id_param', 'viewer_stoneviewer_dicomweb_root'];
    if (stoneKeys.some(key => configs[key])) {
        const stoneSection = document.createElement('div');
        stoneSection.className = 'config-section';
        stoneSection.innerHTML = '<h4 style="margin-top: 30px; margin-bottom: 15px; color: #FF9800; border-bottom: 2px solid #FF9800; padding-bottom: 8px;"><i class="fas fa-eye"></i> Visor StoneViewer</h4>';
        container.appendChild(stoneSection);
        stoneKeys.forEach(key => {
            if (configs[key]) {
                const configItem = createConfigItem(configs[key]);
                stoneSection.appendChild(configItem);
            }
        });
    }

    const gatewayKeys = ['dicomweb_proxy_gateway_default', 'viewer_stoneviewer_remote_proxy_query_key'];
    if (gatewayKeys.some(key => configs[key])) {
        const gwSection = document.createElement('div');
        gwSection.className = 'config-section';
        gwSection.innerHTML = '<h4 style="margin-top: 30px; margin-bottom: 15px; color: #00695C; border-bottom: 2px solid #00695C; padding-bottom: 8px;"><i class="fas fa-network-wired"></i> Gateway DICOMweb (Stone remoto / Portal v2)</h4>' +
            '<p class="text-muted small mb-3">Para abrir estudios en nodos remotos con <strong>Stone</strong> vía proxy tipo <a href="https://github.com/knopkem/dicomweb-proxy" target="_blank" rel="noopener">knopkem/dicomweb-proxy</a>, defina la URL del servicio (p. ej. <code>http://host:5000/rs</code>). Por nodo puede sobreescribirse en <strong>Visores nodos PACS</strong> con <code>dicomweb_proxy_base</code>. El visor usa por defecto el parámetro <code>server=</code> en la URL de Stone.</p>';
        container.appendChild(gwSection);
        gatewayKeys.forEach(key => {
            if (configs[key]) {
                gwSection.appendChild(createConfigItem(configs[key]));
            }
        });
    }
    
    // Sección: Visor Oviyam
    const oviyamKeys = ['viewer_oviyam_url', 'viewer_oviyam_format', 'viewer_oviyam_study_id_param', 'viewer_oviyam_pacs_name'];
    if (oviyamKeys.some(key => configs[key])) {
        const oviyamSection = document.createElement('div');
        oviyamSection.className = 'config-section';
        oviyamSection.innerHTML = '<h4 style="margin-top: 30px; margin-bottom: 15px; color: #9C27B0; border-bottom: 2px solid #9C27B0; padding-bottom: 8px;"><i class="fas fa-eye"></i> Visor Oviyam</h4>';
        container.appendChild(oviyamSection);
        oviyamKeys.forEach(key => {
            if (configs[key]) {
                const configItem = createConfigItem(configs[key]);
                oviyamSection.appendChild(configItem);
            }
        });
    }
    
    // Sección: Descargas
    if (configs.download_url) {
        const downloadSection = document.createElement('div');
        downloadSection.className = 'config-section';
        downloadSection.innerHTML = '<h4 style="margin-top: 30px; margin-bottom: 15px; color: #F44336; border-bottom: 2px solid #F44336; padding-bottom: 8px;"><i class="fas fa-download"></i> Configuración de Descargas</h4>';
        container.appendChild(downloadSection);
        const configItem = createConfigItem(configs.download_url);
        downloadSection.appendChild(configItem);
    }
}

/**
 * Crear un elemento de configuración
 */
function createConfigItem(config) {
    const div = document.createElement('div');
    div.className = 'config-item';
    
    const label = document.createElement('label');
    label.textContent = config.key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    div.appendChild(label);
    
    if (config.description) {
        const desc = document.createElement('div');
        desc.className = 'description';
        desc.textContent = config.description;
        div.appendChild(desc);
    }
    
    let input;
    
    // Manejar tipo file (para logo)
    // Si es app_logo, forzar tipo file si no está definido
    if (config.key === 'app_logo') {
        if (!config.type || config.type !== 'file') {
            config.type = 'file';
        }
    }
    
    if (config.type === 'file' && config.key === 'app_logo') {
        const fileContainer = document.createElement('div');
        fileContainer.style.marginTop = '10px';
        
        // Vista previa del logo actual
        if (config.value) {
            const previewContainer = document.createElement('div');
            previewContainer.style.marginBottom = '15px';
            previewContainer.style.textAlign = 'center';
            
            const previewLabel = document.createElement('div');
            previewLabel.style.marginBottom = '10px';
            previewLabel.style.fontSize = '14px';
            previewLabel.style.color = '#666';
            previewLabel.style.fontWeight = '500';
            previewLabel.textContent = 'Logo actual:';
            previewContainer.appendChild(previewLabel);
            
            const previewImg = document.createElement('img');
            previewImg.src = config.value;
            previewImg.style.maxWidth = '200px';
            previewImg.style.maxHeight = '100px';
            previewImg.style.border = '1px solid #ddd';
            previewImg.style.borderRadius = '8px';
            previewImg.style.padding = '10px';
            previewImg.style.backgroundColor = '#f8f9fa';
            previewImg.style.display = 'block';
            previewImg.style.margin = '0 auto';
            previewImg.onerror = function() {
                previewContainer.innerHTML = '<p style="color: #999; font-size: 14px;">Logo actual no disponible</p>';
            };
            
            previewContainer.appendChild(previewImg);
            fileContainer.appendChild(previewContainer);
        }
        
        // Etiqueta para el input de archivo
        const fileLabel = document.createElement('div');
        fileLabel.style.marginBottom = '8px';
        fileLabel.style.fontSize = '14px';
        fileLabel.style.color = '#333';
        fileLabel.style.fontWeight = '500';
        fileLabel.textContent = config.value ? 'Cambiar logo (selecciona una nueva imagen):' : 'Seleccionar logo:';
        fileContainer.appendChild(fileLabel);
        
        // Input de archivo - SIEMPRE se muestra
        input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.id = 'config_' + config.key;
        input.dataset.key = config.key;
        input.dataset.category = config.category;
        input.style.width = '100%';
        input.style.padding = '10px';
        input.style.border = '2px solid #e0e0e0';
        input.style.borderRadius = '8px';
        input.style.cursor = 'pointer';
        fileContainer.appendChild(input);
        
        // Botón para eliminar logo (solo si hay logo actual)
        if (config.value) {
            const buttonContainer = document.createElement('div');
            buttonContainer.style.marginTop = '15px';
            buttonContainer.style.display = 'flex';
            buttonContainer.style.gap = '10px';
            buttonContainer.style.justifyContent = 'center';
            
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-outline-danger';
            removeBtn.innerHTML = '<i class="fas fa-trash"></i> Eliminar Logo';
            removeBtn.onclick = function() {
                if (confirm('¿Estás seguro de que deseas eliminar el logo?')) {
                    removeLogo(config.key);
                }
            };
            buttonContainer.appendChild(removeBtn);
            fileContainer.appendChild(buttonContainer);
        }
        
        div.appendChild(fileContainer);
        
        return div;
    }
    
    if (config.type === 'select' && config.options) {
        input = document.createElement('select');
        // Manejar opciones como array de strings o como objeto {valor: etiqueta}
        if (Array.isArray(config.options)) {
            config.options.forEach(option => {
                const optionEl = document.createElement('option');
                optionEl.value = option;
                optionEl.textContent = option;
                if (config.value === option || String(config.value) === String(option)) {
                    optionEl.selected = true;
                }
                input.appendChild(optionEl);
            });
        } else if (typeof config.options === 'object') {
            // Opciones como objeto: {'0': 'No', '1': 'Sí'}
            Object.keys(config.options).forEach(key => {
                const optionEl = document.createElement('option');
                optionEl.value = key;
                optionEl.textContent = config.options[key];
                if (config.value === key || String(config.value) === String(key)) {
                    optionEl.selected = true;
                }
                input.appendChild(optionEl);
            });
        }
    } else {
        input = document.createElement('input');
        input.type = config.type || 'text';
        input.value = config.value || '';
        
        if (config.masked) {
            input.placeholder = 'Contraseña oculta - deja en blanco para no cambiar';
        }
    }
    
    input.id = 'config_' + config.key;
    input.dataset.key = config.key;
    input.dataset.category = config.category;
    input.readOnly = config.readonly || false;

    if (config.key === 'gasalud_auth_mode') {
        input.addEventListener('change', syncGasaludAuthFieldsVisibility);
    }

    // Campos password: ícono ojo para mostrar/ocultar
    if ((config.type === 'password' || input.type === 'password') && input.tagName === 'INPUT') {
        const wrap = document.createElement('div');
        wrap.className = 'config-password-wrap';
        wrap.style.position = 'relative';
        wrap.style.display = 'block';
        input.style.paddingRight = '2.5rem';
        input.style.width = '100%';
        wrap.appendChild(input);

        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'btn btn-link config-password-toggle';
        toggleBtn.setAttribute('aria-label', 'Mostrar u ocultar contraseña');
        toggleBtn.title = 'Mostrar / ocultar';
        toggleBtn.innerHTML = '<i class="fas fa-eye" aria-hidden="true"></i>';
        toggleBtn.style.cssText = 'position:absolute;right:4px;top:50%;transform:translateY(-50%);padding:0.25rem 0.5rem;line-height:1;color:#6c757d;text-decoration:none;z-index:2;';
        toggleBtn.addEventListener('click', function () {
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                icon.className = showing ? 'fas fa-eye' : 'fas fa-eye-slash';
            }
            toggleBtn.title = showing ? 'Mostrar / ocultar' : 'Ocultar';
        });
        wrap.appendChild(toggleBtn);
        div.appendChild(wrap);
    } else {
        div.appendChild(input);
    }
    div.dataset.configKey = config.key;
    
    return div;
}

/**
 * Guardar configuraciones de una categoría
 */
async function saveConfigurations(category) {
    showLoading(true);
    
    try {
        const configs = allConfigurations[category] || {};
        const configurations = [];
        let logoFile = null;
        
        // Primero, verificar si hay un logo para subir
        const logoInput = document.getElementById('config_app_logo');
        if (logoInput && logoInput.files && logoInput.files.length > 0) {
            logoFile = logoInput.files[0];
        }
        
        // Si hay un logo para subir, subirlo primero
        if (logoFile) {
            try {
                const formData = new FormData();
                formData.append('logo', logoFile);
                
                const token = getAuthToken();
                const uploadResponse = await fetch('api/config/upload-logo.php', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token
                    },
                    body: formData
                });
                
                const uploadData = await uploadResponse.json();
                
                if (uploadData.success) {
                    // Agregar la configuración del logo con la nueva ruta
                    configurations.push({
                        key: 'app_logo',
                        value: uploadData.path,
                        description: 'Ruta del logo de la aplicación (opcional)'
                    });
                    showAlert('success', 'Logo subido exitosamente');
                } else {
                    showAlert('error', 'Error al subir el logo: ' + uploadData.message);
                    showLoading(false);
                    return;
                }
            } catch (error) {
                console.error('Error subiendo logo:', error);
                showAlert('error', 'Error al subir el logo: ' + error.message);
                showLoading(false);
                return;
            }
        }
        
        // Procesar otras configuraciones
        Object.keys(configs).forEach(key => {
            const config = configs[key];
            const input = document.getElementById('config_' + config.key);
            
            // Saltar el logo si ya se procesó arriba
            if (config.key === 'app_logo' && logoFile) {
                return;
            }
            
            if (input && !input.readOnly && input.type !== 'file') {
                let value = input.value;
                
                // Si es una contraseña enmascarada y está vacía, no actualizar
                if (config.masked && value === '' && input.placeholder) {
                    return; // Saltar esta configuración
                }
                
                // Si es una contraseña enmascarada y no cambió, no actualizar
                if (config.masked && value === '••••••••') {
                    return; // Saltar esta configuración
                }
                
                configurations.push({
                    key: config.key,
                    value: value,
                    description: config.description
                });
            }
        });
        
        if (configurations.length === 0 && !logoFile) {
            showAlert('info', 'No hay cambios para guardar');
            showLoading(false);
            return;
        }
        
        // Guardar configuraciones (si hay alguna además del logo)
        if (configurations.length > 0) {
            const token = getAuthToken();
            const response = await fetch('api/config/manage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify({
                    configurations: configurations
                })
            });

            const data = await response.json();

            if (data.success) {
                const message = data.message || `${data.saved?.length || configurations.length} configuración(es) guardada(s) exitosamente`;
                if (!logoFile) {
                    showAlert('success', message);
                }
                
                // Si hay errores, mostrarlos también
                if (data.errors && data.errors.length > 0) {
                    const errorMessages = data.errors.map(e => `${e.key}: ${e.error}`).join(', ');
                    showAlert('warning', `Algunas configuraciones no se pudieron guardar: ${errorMessages}`);
                }
            } else {
                const errorMsg = data.message || 'Error desconocido';
                showAlert('error', 'Error al guardar: ' + errorMsg);
                console.error('Error guardando configuraciones:', data);
            }
        }
        
        // Recargar configuraciones para obtener valores actualizados
        setTimeout(() => {
            loadConfigurations();
        }, 1000);
    } catch (error) {
        console.error('Error guardando configuraciones:', error);
        showAlert('error', 'Error al guardar configuraciones: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Eliminar logo de la aplicación
 */
async function removeLogo(key) {
    if (!confirm('¿Estás seguro de que deseas eliminar el logo?')) {
        return;
    }
    
    showLoading(true);
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/config/manage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                configurations: [{
                    key: key,
                    value: '',
                    description: 'Ruta del logo de la aplicación (opcional)'
                }]
            })
        });

        const data = await response.json();

        if (data.success) {
            showAlert('success', 'Logo eliminado exitosamente');
            setTimeout(() => {
                loadConfigurations();
            }, 500);
        } else {
            showAlert('error', 'Error al eliminar el logo: ' + data.message);
        }
    } catch (error) {
        console.error('Error eliminando logo:', error);
        showAlert('error', 'Error al eliminar el logo: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Probar conexión con PACS
 */
async function testPacsConnection() {
    const statusEl = document.getElementById('pacsConnectionStatus');
    statusEl.innerHTML = '<span class="connection-status">Probando...</span>';
    
    try {
        // Obtener valores actuales del formulario
        const host = document.getElementById('config_pacs_host')?.value || '';
        const port = document.getElementById('config_pacs_port')?.value || '';
        const protocol = document.getElementById('config_pacs_protocol')?.value || 'http';
        const username = document.getElementById('config_pacs_username')?.value || '';
        const password = document.getElementById('config_pacs_password')?.value || '';
        
        if (!host || !port) {
            statusEl.innerHTML = '<span class="connection-status error">Completa host y puerto</span>';
            return;
        }
        
        const url = `${protocol}://${host}:${port}/system`;
        
        // Hacer petición de prueba (usando fetch con autenticación básica)
        const response = await fetch(`api/pacs/test-connection.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getAuthToken()
            },
            body: JSON.stringify({
                url: url,
                username: username,
                password: password
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            statusEl.innerHTML = '<span class="connection-status success"><i class="fas fa-check"></i> Conexión exitosa</span>';
        } else {
            statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> ' + (data.message || 'Error de conexión') + '</span>';
        }
    } catch (error) {
        console.error('Error probando conexión:', error);
        statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> Error: ' + error.message + '</span>';
    }
}

/**
 * Mostrar alerta
 */
function showAlert(type, message) {
    const container = document.getElementById('alertContainer');
    if (!container) return;
    
    const alertClass = {
        'success': 'alert-success',
        'error': 'alert-danger',
        'warning': 'alert-warning',
        'info': 'alert-info'
    }[type] || 'alert-info';
    
    const alert = document.createElement('div');
    alert.className = `alert ${alertClass} alert-dismissible fade show`;
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    container.innerHTML = '';
    container.appendChild(alert);
    
    // Auto-dismiss después de 5 segundos
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 5000);
}

/**
 * Mostrar/ocultar loading
 */
function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        if (show) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }
}

/**
 * Obtener token de autenticación
 */
function getAuthToken() {
    return localStorage.getItem('session_token') || 
           sessionStorage.getItem('session_token') ||
           document.cookie.split('; ').find(row => row.startsWith('session_token='))?.split('=')[1] ||
           '';
}

/**
 * Cargar configuración de Worklist
 */
async function loadWorklistConfig() {
    showLoading(true);
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/worklist-config.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });

        const data = await response.json();

        if (data.success) {
            renderWorklistConfig(
                Object.assign({}, data.config || {}, { _pacs_nodes: data.pacs_nodes || [] })
            );
        } else {
            showAlert('error', 'Error al cargar configuración de Worklist: ' + data.message);
        }
    } catch (error) {
        console.error('Error cargando configuración de Worklist:', error);
        showAlert('error', 'Error al cargar configuración de Worklist: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Renderizar configuración de Worklist
 */
function renderWorklistConfig(config) {
    const container = document.getElementById('worklistConfig');
    if (!container) return;

    container.innerHTML = '';

    // Derivar modo de canal si no viene explícito (compat instalaciones previas)
    if (config.worklist_ingest_mode === undefined || config.worklist_ingest_mode === null || config.worklist_ingest_mode === '') {
        const t = Number(config.pull_enabled) === 1;
        const h = Number(config.hl7_enabled) === 1;
        if (t && h) config.worklist_ingest_mode = 'both';
        else if (h) config.worklist_ingest_mode = 'hl7';
        else if (t) config.worklist_ingest_mode = 'txt';
        else config.worklist_ingest_mode = 'none';
    }

    const fields = [
        {
            key: 'orthanc_worklist_mode',
            label: 'Modo de integración con Orthanc',
            type: 'select',
            required: true,
            options: [
                { value: 'filesystem', label: 'Filesystem (legacy, .wl en carpeta)' },
                { value: 'rest', label: 'REST API (plugin nuevo de Worklists)' }
            ],
            help: 'Define si el portal sincroniza por archivos .wl o por API REST del plugin nuevo'
        },
        {
            key: 'orthanc_worklist_path',
            label: 'Ruta de Worklist de Orthanc',
            type: 'text',
            required: false,
            placeholder: '/var/lib/orthanc/db/WorklistsDatabase',
            help: 'Ruta completa donde Orthanc busca los archivos .wl de worklist'
        },
        {
            key: 'orthanc_host',
            label: 'Host de Orthanc',
            type: 'text',
            required: false,
            placeholder: 'localhost',
            help: 'Dirección del servidor Orthanc'
        },
        {
            key: 'sftp_host',
            label: 'Host SFTP (Opcional)',
            type: 'text',
            required: false,
            placeholder: '',
            help: 'Servidor SFTP para transferencia remota'
        },
        {
            key: 'orthanc_rest_base_url',
            label: 'Orthanc REST Base URL',
            type: 'text',
            required: false,
            placeholder: 'http://127.0.0.1:8042',
            help: 'Se usa únicamente en modo REST'
        },
        {
            key: 'orthanc_rest_user',
            label: 'Orthanc REST Usuario',
            type: 'text',
            required: false,
            placeholder: 'orthanc',
            help: 'Usuario HTTP Basic para Orthanc REST'
        },
        {
            key: 'orthanc_rest_pass',
            label: 'Orthanc REST Contraseña',
            type: 'password',
            required: false,
            placeholder: '',
            help: 'Si no se completa, se conserva la contraseña actual'
        },
        {
            key: 'orthanc_rest_verify_ssl',
            label: 'Validar SSL (REST)',
            type: 'number',
            required: false,
            placeholder: '0',
            help: '0 = no validar certificado, 1 = validar'
        },
        {
            key: 'orthanc_rest_timeout',
            label: 'Timeout REST (segundos)',
            type: 'number',
            required: false,
            placeholder: '15',
            help: 'Timeout para llamadas REST a Orthanc'
        },
        {
            key: 'worklist_ingest_mode',
            label: 'Canal de ingesta Worklist',
            type: 'select',
            required: false,
            options: [
                { value: 'none', label: 'Ninguno (solo carga manual / API)' },
                { value: 'txt', label: 'Solo archivos .txt (PULL)' },
                { value: 'hl7', label: 'Solo HL7 / MLLP' },
                { value: 'both', label: 'Ambos (.txt y HL7)' }
            ],
            help: 'Misma worklist en todos los casos. Misma Accession = un solo ítem (se actualiza, no se duplica), aunque TXT y HL7 lleguen los dos.'
        },
        {
            key: 'pull_input_path',
            label: 'Carpeta de entrada PULL (.txt)',
            type: 'text',
            required: false,
            placeholder: '/ruta/inbox-worklist',
            help: 'Carpeta monitoreada por worker pull (solo si el canal incluye .txt)'
        },
        {
            key: 'pull_processed_path',
            label: 'Carpeta PULL procesados',
            type: 'text',
            required: false,
            placeholder: '/ruta/inbox-worklist/processed',
            help: 'Destino de archivos importados correctamente'
        },
        {
            key: 'pull_failed_path',
            label: 'Carpeta PULL fallidos',
            type: 'text',
            required: false,
            placeholder: '/ruta/inbox-worklist/failed',
            help: 'Destino de archivos con error de parse/proceso'
        },
        {
            key: 'hl7_port',
            label: 'HL7/MLLP: puerto TCP',
            type: 'number',
            required: false,
            placeholder: '2575',
            help: 'Puerto donde escucha el receptor (configurable; abrir en firewall solo al RIS). Activo solo si el canal es HL7 o Ambos.'
        },
        {
            key: 'hl7_bind_host',
            label: 'HL7/MLLP: bind host',
            type: 'text',
            required: false,
            placeholder: '0.0.0.0',
            help: '0.0.0.0 = todas las interfaces; o IP LAN específica'
        },
        {
            key: 'hl7_input_path',
            label: 'HL7: carpeta inbox (.hl7)',
            type: 'text',
            required: false,
            placeholder: '/var/www/tjsiddse/uploads/inbox-hl7',
            help: 'El listener guarda aquí los mensajes recibidos'
        },
        {
            key: 'hl7_processed_path',
            label: 'HL7: carpeta procesados',
            type: 'text',
            required: false,
            placeholder: '/var/www/tjsiddse/uploads/inbox-hl7/processed',
            help: 'Mensajes importados correctamente a worklist'
        },
        {
            key: 'hl7_failed_path',
            label: 'HL7: carpeta fallidos',
            type: 'text',
            required: false,
            placeholder: '/var/www/tjsiddse/uploads/inbox-hl7/failed',
            help: 'Mensajes con error de parseo o ingesta'
        },
        {
            key: 'hl7_prestador_field',
            label: 'HL7: campo Prestador (ID informante)',
            type: 'select',
            required: false,
            options: [
                { value: 'PV1-8', label: 'PV1-8 (Referring / médico derivante)' },
                { value: 'PV1-7', label: 'PV1-7 (Attending / médico tratante)' },
                { value: 'OBR-16', label: 'OBR-16 (Ordering provider)' },
                { value: 'NONE', label: 'Ninguno (no mapear)' }
            ],
            help: 'Primer componente del campo (ej. 2132^APELLIDO^NOMBRE → 2132) → worklist.referring_physician'
        },
        {
            key: 'hl7_spawn_worker_on_receive',
            label: 'HL7: procesar al recibir (spawn worker)',
            type: 'select',
            required: false,
            options: [
                { value: '1', label: 'Sí (recomendado)' },
                { value: '0', label: 'No (solo cron inbox-worker)' }
            ],
            help: 'Si Sí, el listener lanza hl7-process-one.php al guardar cada mensaje'
        },
        {
            key: 'push_enabled',
            label: 'Habilitar recepción automática PUSH API',
            type: 'number',
            required: false,
            placeholder: '0',
            help: '0 = deshabilitado, 1 = habilitado'
        },
        {
            key: 'push_allowed_ips',
            label: 'IPs permitidas para PUSH',
            type: 'text',
            required: false,
            placeholder: '192.168.1.10,192.168.1.11',
            help: 'Lista separada por coma. Vacío = sin restricción'
        },
        {
            key: 'sftp_port',
            label: 'Puerto SFTP',
            type: 'number',
            required: false,
            placeholder: '22',
            help: 'Puerto del servidor SFTP'
        },
        {
            key: 'sftp_user',
            label: 'Usuario SFTP',
            type: 'text',
            required: false,
            placeholder: '',
            help: 'Usuario para conexión SFTP'
        },
        {
            key: 'sftp_pass',
            label: 'Contraseña SFTP',
            type: 'password',
            required: false,
            placeholder: '',
            help: 'Contraseña para conexión SFTP'
        },
        {
            key: 'sync_interval',
            label: 'Intervalo de Sincronización (segundos)',
            type: 'number',
            required: false,
            placeholder: '300',
            help: 'Intervalo en segundos para sincronización automática'
        },
        {
            key: 'pacs_reconcile_on_list_load',
            label: 'Cotejar con PACS al cargar la lista de worklist',
            type: 'select',
            required: false,
            options: [
                { value: '0', label: 'No' },
                { value: '1', label: 'Sí' }
            ],
            help: 'Consulta el nodo elegido por AccessionNumber y actualiza fecha/hora de estudio en BD (sin cron)'
        },
        {
            key: 'pacs_reconcile_min_interval_minutes',
            label: 'PACS: intervalo mínimo entre reintentos (min)',
            type: 'number',
            required: false,
            placeholder: '30',
            help: 'Si no hay estudio, no volver a consultar el mismo turno hasta pasar este tiempo (mín. 5)'
        },
        {
            key: 'pacs_reconcile_max_per_request',
            label: 'PACS: máx. turnos a consultar por carga de lista',
            type: 'number',
            required: false,
            placeholder: '40',
            help: 'Limita C-FIND por petición (1–80)'
        },
        {
            key: 'pacs_reconcile_day_span_days',
            label: 'PACS: ventana de días (fecha programada)',
            type: 'number',
            required: false,
            placeholder: '45',
            help: 'Solo entran al cotejo los turnos con scheduled_date entre (hoy − N días) y (hoy + 2). Turnos más viejos no se consultan en PACS hasta que subas N o acerques la fecha. Rango 1–365.'
        }
    ];

    fields.forEach(field => {
        const fieldDiv = document.createElement('div');
        fieldDiv.className = 'config-item';
        
        let value = config[field.key];
        if (value === undefined || value === null) {
            value = '';
        }
        if (field.type === 'select') {
            value = String(value);
        }

        if (field.type === 'select') {
            const optionsHtml = (field.options || [])
                .map(opt => `<option value="${opt.value}" ${String(opt.value) === String(value) ? 'selected' : ''}>${opt.label}</option>`)
                .join('');
            fieldDiv.innerHTML = `
                <label class="config-label">
                    ${field.label} ${field.required ? '<span class="text-danger">*</span>' : ''}
                    ${field.help ? `<small class="text-muted d-block">${field.help}</small>` : ''}
                </label>
                <select class="form-control config-input" id="worklist_${field.key}" ${field.required ? 'required' : ''}>
                    ${optionsHtml}
                </select>
            `;
        } else {
            fieldDiv.innerHTML = `
                <label class="config-label">
                    ${field.label} ${field.required ? '<span class="text-danger">*</span>' : ''}
                    ${field.help ? `<small class="text-muted d-block">${field.help}</small>` : ''}
                </label>
                <input 
                    type="${field.type}" 
                    class="form-control config-input" 
                    id="worklist_${field.key}"
                    value="${value}"
                    placeholder="${field.placeholder || ''}"
                    ${field.required ? 'required' : ''}
                >
            `;
        }
        
        container.appendChild(fieldDiv);
    });

    const pacsNodes = config._pacs_nodes || [];
    const nodeVal =
        config.pacs_reconcile_node_id != null && config.pacs_reconcile_node_id !== ''
            ? String(config.pacs_reconcile_node_id)
            : '';
    const nodeDiv = document.createElement('div');
    nodeDiv.className = 'config-item';
    const escOpt = (s) =>
        String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    const nodeOptions = [
        '<option value="">— Sin nodo (cotejo desactivado si no hay ID) —</option>'
    ].concat(
        pacsNodes.map((n) => {
            const id = String(n.id);
            const label = (n.name || id) + (n.node_type ? ` (${n.node_type})` : '');
            return `<option value="${id}" ${id === nodeVal ? 'selected' : ''}>${escOpt(label)}</option>`;
        })
    );
    nodeDiv.innerHTML = `
        <label class="config-label">
            Nodo PACS para cotejar estudios
            <small class="text-muted d-block">Usa el mismo cliente C-FIND que PACS Nodes Manager (por AccessionNumber)</small>
        </label>
        <select class="form-control config-input" id="worklist_pacs_reconcile_node_id">${nodeOptions.join('')}</select>
    `;
    container.appendChild(nodeDiv);

    // Badge estado listener HL7
    const hl7StatusDiv = document.createElement('div');
    hl7StatusDiv.className = 'config-item';
    hl7StatusDiv.innerHTML = `
        <label class="config-label">Estado del listener HL7/MLLP</label>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span id="hl7ListenerStatusBadge" class="badge text-bg-secondary">Sin consultar</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testHl7ListenerStatus()">
                <i class="fas fa-heartbeat me-1"></i>Probar listener
            </button>
        </div>
        <small class="text-muted d-block mt-1">Consulta modules/hl7-worklist/api/status.php (requiere módulo instalado y servicio systemd).</small>
    `;
    container.appendChild(hl7StatusDiv);
}

/**
 * Consultar estado del listener MLLP del módulo hl7-worklist
 */
async function testHl7ListenerStatus() {
    const badge = document.getElementById('hl7ListenerStatusBadge');
    if (badge) {
        badge.className = 'badge text-bg-info';
        badge.textContent = 'Consultando…';
    }
    try {
        const token = getAuthToken();
        const response = await fetch('modules/hl7-worklist/api/status.php', {
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        const data = await response.json();
        if (!badge) return;
        if (!response.ok || !data.success) {
            badge.className = 'badge text-bg-danger';
            badge.textContent = data.message || 'Error';
            return;
        }
        const alive = !!data.listener_alive;
        const enabled = Number(data.hl7_enabled) === 1;
        if (!enabled) {
            badge.className = 'badge text-bg-secondary';
            badge.textContent = 'HL7 deshabilitado (puerto ' + (data.hl7_port || '?') + ')';
        } else if (alive) {
            badge.className = 'badge text-bg-success';
            badge.textContent = 'Escuchando :' + (data.hl7_port || '?') + ' — ' + (data.ingests_24h ?? 0) + ' ingestas/24h';
        } else {
            badge.className = 'badge text-bg-warning';
            badge.textContent = 'Configurado :' + (data.hl7_port || '?') + ' pero listener sin heartbeat';
        }
    } catch (e) {
        if (badge) {
            badge.className = 'badge text-bg-danger';
            badge.textContent = 'Error: ' + e.message;
        }
    }
}

/**
 * Guardar configuración de Worklist
 */
async function saveWorklistConfig() {
    showLoading(true);
    
    try {
        const config = {
            orthanc_worklist_mode: document.getElementById('worklist_orthanc_worklist_mode')?.value || 'filesystem',
            orthanc_worklist_path: document.getElementById('worklist_orthanc_worklist_path')?.value || '',
            orthanc_host: document.getElementById('worklist_orthanc_host')?.value || 'localhost',
            orthanc_rest_base_url: document.getElementById('worklist_orthanc_rest_base_url')?.value || '',
            orthanc_rest_user: document.getElementById('worklist_orthanc_rest_user')?.value || '',
            orthanc_rest_pass: document.getElementById('worklist_orthanc_rest_pass')?.value || '',
            orthanc_rest_verify_ssl: parseInt(document.getElementById('worklist_orthanc_rest_verify_ssl')?.value || '0'),
            orthanc_rest_timeout: parseInt(document.getElementById('worklist_orthanc_rest_timeout')?.value || '15'),
            worklist_ingest_mode: document.getElementById('worklist_worklist_ingest_mode')?.value || 'none',
            pull_input_path: document.getElementById('worklist_pull_input_path')?.value || '',
            pull_processed_path: document.getElementById('worklist_pull_processed_path')?.value || '',
            pull_failed_path: document.getElementById('worklist_pull_failed_path')?.value || '',
            hl7_port: parseInt(document.getElementById('worklist_hl7_port')?.value || '2575'),
            hl7_bind_host: document.getElementById('worklist_hl7_bind_host')?.value || '0.0.0.0',
            hl7_input_path: document.getElementById('worklist_hl7_input_path')?.value || '',
            hl7_processed_path: document.getElementById('worklist_hl7_processed_path')?.value || '',
            hl7_failed_path: document.getElementById('worklist_hl7_failed_path')?.value || '',
            hl7_prestador_field: document.getElementById('worklist_hl7_prestador_field')?.value || 'PV1-8',
            hl7_spawn_worker_on_receive: parseInt(document.getElementById('worklist_hl7_spawn_worker_on_receive')?.value || '1'),
            push_enabled: parseInt(document.getElementById('worklist_push_enabled')?.value || '0'),
            push_allowed_ips: document.getElementById('worklist_push_allowed_ips')?.value || '',
            sftp_host: document.getElementById('worklist_sftp_host')?.value || '',
            sftp_port: parseInt(document.getElementById('worklist_sftp_port')?.value || '22'),
            sftp_user: document.getElementById('worklist_sftp_user')?.value || '',
            sftp_pass: document.getElementById('worklist_sftp_pass')?.value || '',
            sync_interval: parseInt(document.getElementById('worklist_sync_interval')?.value || '300'),
            pacs_reconcile_on_list_load: parseInt(
                document.getElementById('worklist_pacs_reconcile_on_list_load')?.value || '0'
            ),
            pacs_reconcile_min_interval_minutes: parseInt(
                document.getElementById('worklist_pacs_reconcile_min_interval_minutes')?.value || '30'
            ),
            pacs_reconcile_max_per_request: parseInt(
                document.getElementById('worklist_pacs_reconcile_max_per_request')?.value || '40'
            ),
            pacs_reconcile_day_span_days: parseInt(
                document.getElementById('worklist_pacs_reconcile_day_span_days')?.value || '45'
            ),
            pacs_reconcile_node_id: (() => {
                const el = document.getElementById('worklist_pacs_reconcile_node_id');
                const v = el && el.value !== undefined ? el.value : '';
                if (v === '' || v === null) {
                    return null;
                }
                const n = parseInt(v, 10);
                return Number.isFinite(n) && n > 0 ? n : null;
            })()
        };

        // Canal único en UI → flags pull/hl7 (workers y listener los respetan)
        const mode = String(config.worklist_ingest_mode || 'none').toLowerCase();
        config.pull_enabled = (mode === 'txt' || mode === 'both') ? 1 : 0;
        config.hl7_enabled = (mode === 'hl7' || mode === 'both') ? 1 : 0;

        if (config.orthanc_worklist_mode === 'filesystem' && !config.orthanc_worklist_path) {
            showAlert('error', 'La ruta de Worklist de Orthanc es requerida en modo filesystem');
            showLoading(false);
            return;
        }
        if (config.orthanc_worklist_mode === 'rest' && !config.orthanc_rest_base_url) {
            showAlert('error', 'orthanc_rest_base_url es requerido en modo REST');
            showLoading(false);
            return;
        }

        const token = getAuthToken();
        const response = await fetch('api/worklist-config.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(config)
        });

        const data = await response.json();

        if (data.success) {
            showAlert('success', 'Configuración de Worklist guardada exitosamente');
        } else {
            showAlert('error', 'Error al guardar configuración: ' + data.message);
        }
    } catch (error) {
        console.error('Error guardando configuración de Worklist:', error);
        showAlert('error', 'Error al guardar configuración: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Probar conexión de Worklist/Orthanc según modo seleccionado
 */
async function testWorklistConnection() {
    const statusEl = document.getElementById('worklistConnectionStatus');
    if (statusEl) statusEl.innerHTML = '';
    showLoading(true);

    try {
        const payload = {
            action: 'test_connection',
            orthanc_worklist_mode: document.getElementById('worklist_orthanc_worklist_mode')?.value || 'filesystem',
            orthanc_worklist_path: document.getElementById('worklist_orthanc_worklist_path')?.value || '',
            orthanc_rest_base_url: document.getElementById('worklist_orthanc_rest_base_url')?.value || '',
            orthanc_rest_user: document.getElementById('worklist_orthanc_rest_user')?.value || '',
            orthanc_rest_pass: document.getElementById('worklist_orthanc_rest_pass')?.value || '',
            orthanc_rest_verify_ssl: parseInt(document.getElementById('worklist_orthanc_rest_verify_ssl')?.value || '0'),
            orthanc_rest_timeout: parseInt(document.getElementById('worklist_orthanc_rest_timeout')?.value || '15')
        };

        const token = getAuthToken();
        const response = await fetch('api/worklist-config.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (!statusEl) return;

        if (data.success) {
            statusEl.innerHTML = '<span class="connection-status success">Conexión exitosa</span>';
        } else {
            statusEl.innerHTML = '<span class="connection-status error">Error: ' + (data.message || 'desconocido') + '</span>';
        }
    } catch (error) {
        if (statusEl) {
            statusEl.innerHTML = '<span class="connection-status error">Error: ' + error.message + '</span>';
        }
    } finally {
        showLoading(false);
    }
}

// Cargar configuración de Worklist al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Cargar configuración de Worklist cuando se muestra la pestaña
    const worklistTab = document.getElementById('worklist-tab');
    if (worklistTab) {
        worklistTab.addEventListener('shown.bs.tab', function() {
            loadWorklistConfig();
        });
    }
});

// ===== CONFIGURACIÓN DE AI INFORMES =====

/**
 * Cargar configuración de AI Informes cuando se muestra la pestaña
 */
document.addEventListener('DOMContentLoaded', function() {
    const aiInformesTab = document.getElementById('ai-informes-tab');
    if (aiInformesTab) {
        aiInformesTab.addEventListener('shown.bs.tab', function() {
            loadAiInformesConfig();
        });
    }
});

/**
 * Cargar configuración de AI Informes
 */
async function loadAiInformesConfig() {
    showLoading(true);
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes-config.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        const data = await response.json();
        if (data.success) {
            renderAiInformesConfig(data.config || {});
        } else {
            showAlert('error', 'Error al cargar configuración de AI Informes: ' + data.message);
        }
    } catch (error) {
        console.error('Error cargando configuración de AI Informes:', error);
        showAlert('error', 'Error al cargar configuración de AI Informes: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Renderizar configuración de AI Informes
 */
function renderAiInformesConfig(config) {
    const container = document.getElementById('aiInformesConfig');
    if (!container) return;
    container.innerHTML = '';
    
    // ===== SECCIÓN: WHISPER.CPP (Transcripción) =====
    const whisperSection = document.createElement('div');
    whisperSection.className = 'config-section mb-4';
    whisperSection.innerHTML = '<h5 class="mb-3" style="color: #667eea; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-microphone me-2"></i>Configuración de Whisper.cpp (Transcripción)</h5>';
    container.appendChild(whisperSection);
    
    // Campo Método Whisper (NUEVO)
    const whisperMethodField = document.createElement('div');
    whisperMethodField.className = 'config-item';
    const whisperMethodValue = config.whisper_method || 'whisper-server';
    whisperMethodField.innerHTML = `
        <label class="config-label">
            Método de Transcripción <span class="text-danger">*</span>
            <small class="text-muted d-block">Selecciona el método a usar para transcripciones</small>
        </label>
        <select class="form-control config-input" id="ai_whisper_method" onchange="toggleWhisperMethodFields()">
            <option value="whisper-server" ${whisperMethodValue === 'whisper-server' ? 'selected' : ''}>whisper-server (API REST estándar)</option>
            <option value="whisper-cli" ${whisperMethodValue === 'whisper-cli' ? 'selected' : ''}>whisper-cli (API Node.js, optimizado RTX)</option>
        </select>
        <small class="text-muted d-block mt-1">
            <strong>whisper-server:</strong> API REST estándar de whisper.cpp<br>
            <strong>whisper-cli:</strong> API Node.js alternativa que evita cuelgues en RTX 5060 Ti
        </small>
    `;
    whisperSection.appendChild(whisperMethodField);
    
    // Campo URL API de Whisper Server
    const whisperUrlField = document.createElement('div');
    whisperUrlField.className = 'config-item';
    whisperUrlField.id = 'whisper-server-field';
    const whisperUrlValue = config.whisper_api_url || '';
    whisperUrlField.innerHTML = `
        <label class="config-label">
            URL API de Whisper Server <span class="text-danger">*</span>
            <small class="text-muted d-block">URL base del servidor whisper-server (ej: http://192.168.0.33:8080)</small>
        </label>
        <div class="input-group">
            <input type="text" class="form-control config-input" id="ai_whisper_api_url" value="${whisperUrlValue}" placeholder="http://localhost:8080" required>
            <button class="btn btn-outline-secondary" type="button" onclick="testWhisperConnection()" title="Probar conexión con whisper-server">
                <i class="fas fa-plug"></i> Probar
            </button>
        </div>
        <span id="whisperConnectionStatus" class="mt-1"></span>
    `;
    whisperSection.appendChild(whisperUrlField);
    
    // Campo URL API de Whisper CLI (NUEVO)
    const whisperCliUrlField = document.createElement('div');
    whisperCliUrlField.className = 'config-item';
    whisperCliUrlField.id = 'whisper-cli-field';
    const whisperCliUrlValue = config.whisper_cli_api_url || 'http://localhost:3001';
    whisperCliUrlField.innerHTML = `
        <label class="config-label">
            URL API de Whisper CLI <span class="text-danger">*</span>
            <small class="text-muted d-block">URL base del servidor whisper-cli API (ej: http://192.168.0.33:3001)</small>
        </label>
        <div class="input-group">
            <input type="text" class="form-control config-input" id="ai_whisper_cli_api_url" value="${whisperCliUrlValue}" placeholder="http://localhost:3001" required>
            <button class="btn btn-outline-secondary" type="button" onclick="testWhisperCliConnection()" title="Probar conexión con whisper-cli">
                <i class="fas fa-plug"></i> Probar
            </button>
        </div>
        <span id="whisperCliConnectionStatus" class="mt-1"></span>
    `;
    whisperSection.appendChild(whisperCliUrlField);
    
    // Campo Modelo Whisper
    const whisperModelField = document.createElement('div');
    whisperModelField.className = 'config-item';
    const whisperModelValue = config.whisper_model || 'base';
    whisperModelField.innerHTML = `
        <label class="config-label">
            Modelo Whisper
            <small class="text-muted d-block" id="whisper_model_help">Modelo de whisper.cpp a usar</small>
        </label>
        <div class="input-group">
            <select class="form-control config-input" id="ai_whisper_model">
                <option value="">Cargando modelos...</option>
            </select>
            <button class="btn btn-outline-secondary" type="button" onclick="loadWhisperModels()" title="Recargar modelos disponibles" id="btn_reload_whisper_models" style="display: none;">
                <i class="fas fa-sync"></i>
            </button>
        </div>
    `;
    whisperSection.appendChild(whisperModelField);
    
    // Campo Idioma Whisper
    const whisperLangField = document.createElement('div');
    whisperLangField.className = 'config-item';
    const whisperLangValue = config.whisper_language || 'es';
    whisperLangField.innerHTML = `
        <label class="config-label">
            Idioma
            <small class="text-muted d-block">Idioma del audio a transcribir (es, en, auto, etc.)</small>
        </label>
        <input type="text" class="form-control config-input" id="ai_whisper_language" value="${whisperLangValue}" placeholder="es">
    `;
    whisperSection.appendChild(whisperLangField);
    
    // Campo Timeout Whisper
    const whisperTimeoutField = document.createElement('div');
    whisperTimeoutField.className = 'config-item';
    const whisperTimeoutValue = config.whisper_timeout || 300;
    whisperTimeoutField.innerHTML = `
        <label class="config-label">
            Timeout Whisper (segundos)
            <small class="text-muted d-block">Tiempo máximo de espera para transcripciones</small>
        </label>
        <input type="number" class="form-control config-input" id="ai_whisper_timeout" value="${whisperTimeoutValue}" placeholder="300">
    `;
    whisperSection.appendChild(whisperTimeoutField);
    
    // ===== SECCIÓN: COLA DE TRANSCRIPCIONES =====
    const queueSection = document.createElement('div');
    queueSection.className = 'config-section mb-4';
    queueSection.innerHTML = '<h5 class="mb-3" style="color: #667eea; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-tasks me-2"></i>Cola de Transcripciones Automáticas</h5>';
    container.appendChild(queueSection);
    
    // Toggle para activar/desactivar transcripción automática
    const autoTranscribeField = document.createElement('div');
    autoTranscribeField.className = 'config-item';
    const autoTranscribeEnabled = config.auto_transcribe_enabled || false;
    autoTranscribeField.innerHTML = `
        <label class="config-label d-flex justify-content-between align-items-center">
            <span>
                Transcripción Automática
                <small class="text-muted d-block">Los audios nuevos se agregarán automáticamente a la cola de transcripción</small>
            </span>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="ai_auto_transcribe_enabled" ${autoTranscribeEnabled ? 'checked' : ''} onchange="checkCronJobStatus()">
            </div>
        </label>
        <div id="cronJobWarning" class="alert alert-warning mt-2" style="display: none;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Importante:</strong> Para que la transcripción automática funcione, debes configurar un cron job.
            <div id="cronJobDetails" class="mt-2"></div>
        </div>
    `;
    queueSection.appendChild(autoTranscribeField);
    
    // Campo para máximo de transcripciones concurrentes
    const maxConcurrentField = document.createElement('div');
    maxConcurrentField.className = 'config-item';
    const maxConcurrentValue = config.max_concurrent_transcriptions || 1;
    maxConcurrentField.innerHTML = `
        <label class="config-label">
            Máximo de Transcripciones Concurrentes
            <small class="text-muted d-block">Número máximo de transcripciones que se procesarán simultáneamente</small>
        </label>
        <input type="number" class="form-control config-input" id="ai_max_concurrent_transcriptions" value="${maxConcurrentValue}" min="1" max="10" placeholder="1">
    `;
    queueSection.appendChild(maxConcurrentField);
    
    // ===== SECCIÓN: FFMPEG REST (Conversión de Audio) =====
    const ffmpegRestSection = document.createElement('div');
    ffmpegRestSection.className = 'config-section mb-4';
    ffmpegRestSection.innerHTML = '<h5 class="mb-3" style="color: #f093fb; border-bottom: 2px solid #f093fb; padding-bottom: 10px;"><i class="fas fa-exchange-alt me-2"></i>Configuración de FFmpeg REST (Conversión de Audio)</h5>';
    container.appendChild(ffmpegRestSection);
    
    // Campo URL Base de FFmpeg REST
    const ffmpegRestUrlField = document.createElement('div');
    ffmpegRestUrlField.className = 'config-item';
    const ffmpegRestUrlValue = config.ffmpeg_rest_url || '';
    ffmpegRestUrlField.innerHTML = `
        <label class="config-label">
            URL Base de FFmpeg REST <span class="text-danger">*</span>
            <small class="text-muted d-block">URL base del servidor ffmpeg-rest para conversión de formatos no soportados (ej: http://192.168.0.33:3000)</small>
        </label>
        <div class="input-group">
            <input type="text" class="form-control config-input" id="ai_ffmpeg_rest_url" value="${ffmpegRestUrlValue}" placeholder="http://localhost:3000" required>
            <button class="btn btn-outline-secondary" type="button" onclick="testFfmpegRestConnection()" title="Probar conexión con ffmpeg-rest">
                <i class="fas fa-plug"></i> Probar
            </button>
        </div>
        <span id="ffmpegRestConnectionStatus" class="mt-1"></span>
        <small class="text-muted d-block mt-1">
            <strong>Nota:</strong> FFmpeg REST se usa para convertir archivos M4A, AAC, MP4 a MP3 antes de enviarlos a Whisper. 
            Debe estar instalado en el servidor donde está Whisper (con recursos RTX/i9).
        </small>
    `;
    ffmpegRestSection.appendChild(ffmpegRestUrlField);
    
    // ===== SECCIÓN: OLLAMA (Generación de Informes) =====
    const ollamaSection = document.createElement('div');
    ollamaSection.className = 'config-section mb-4';
    ollamaSection.innerHTML = '<h5 class="mb-3" style="color: #764ba2; border-bottom: 2px solid #764ba2; padding-bottom: 10px;"><i class="fas fa-robot me-2"></i>Configuración de Ollama (Generación de Informes)</h5>';
    container.appendChild(ollamaSection);
    
    // Campo URL Base de Ollama
    const ollamaUrlField = document.createElement('div');
    ollamaUrlField.className = 'config-item';
    const ollamaUrlValue = config.ollama_base_url || '';
    ollamaUrlField.innerHTML = `
        <label class="config-label">
            URL Base de Ollama <span class="text-danger">*</span>
            <small class="text-muted d-block">URL base del servidor Ollama (ej: http://192.168.0.33:11434)</small>
        </label>
        <div class="input-group">
            <input type="text" class="form-control config-input" id="ai_ollama_base_url" value="${ollamaUrlValue}" placeholder="http://localhost:11434" required>
            <button class="btn btn-outline-secondary" type="button" onclick="loadOllamaModels()" title="Cargar modelos disponibles">
                <i class="fas fa-sync"></i> Cargar Modelos
            </button>
        </div>
    `;
    ollamaSection.appendChild(ollamaUrlField);
    
    // Campo Modelo Medgemma (Select)
    const medgemmaField = document.createElement('div');
    medgemmaField.className = 'config-item';
    const medgemmaValue = config.medgemma_model || '';
    medgemmaField.innerHTML = `
        <label class="config-label">
            Modelo para Generación de Informes (Medgemma)
            <small class="text-muted d-block">Selecciona el modelo de Ollama para generación de informes médicos</small>
        </label>
        <select class="form-control config-input" id="ai_medgemma_model">
            <option value="">Cargando modelos...</option>
        </select>
    `;
    ollamaSection.appendChild(medgemmaField);
    
    // Guardar valor de medgemma para restaurarlo después
    const medgemmaSelect = document.getElementById('ai_medgemma_model');
    if (medgemmaSelect && medgemmaValue) {
        medgemmaSelect.setAttribute('data-saved-value', medgemmaValue);
    }
    
    // Campo Prompt Personalizado
    const customPromptField = document.createElement('div');
    customPromptField.className = 'config-item';
    const customPromptValue = config.default_prompt || config.custom_prompt || '';
    
    // Crear label
    const promptLabel = document.createElement('label');
    promptLabel.className = 'config-label';
    promptLabel.innerHTML = `
        Prompt Personalizado para Generación de Informes
        <small class="text-muted d-block">
            Prompt personalizado que se usará al generar informes. Puedes usar placeholders: 
            <code>{patient}</code>, <code>{study}</code>, <code>{transcription}</code>, <code>{template}</code>
            <br>Si está vacío, se usará el prompt por defecto.
        </small>
    `;
    customPromptField.appendChild(promptLabel);
    
    // Crear textarea directamente con DOM (más seguro y editable)
    const promptTextarea = document.createElement('textarea');
    promptTextarea.className = 'form-control config-input';
    promptTextarea.id = 'ai_custom_prompt';
    promptTextarea.rows = 10;
    promptTextarea.style.fontFamily = 'monospace';
    promptTextarea.style.fontSize = '0.9em';
    promptTextarea.placeholder = `Eres radiólogo argentino experto en informes médicos.

TRANSCRIPCIÓN WHISPER (texto literal):
{transcription}

PLANTILLA SELECCIONADA: {template}

Tarea: Genera informe médico FORMAL completo siguiendo estructura plantilla.

Formato salida SOLO:
INFORME {study}
DATOS CLÍNICOS: ...
TÉCNICA: ...
HALLAZGOS: ...
CONCLUSIÓN: ...`;
    promptTextarea.value = customPromptValue; // Usar .value para establecer el contenido (editable)
    customPromptField.appendChild(promptTextarea);
    
    // Crear ayuda
    const promptHelp = document.createElement('small');
    promptHelp.className = 'text-muted';
    promptHelp.innerHTML = `
        <strong>Placeholders disponibles:</strong><br>
        • <code>{patient}</code> - Nombre del paciente<br>
        • <code>{study}</code> - Descripción del estudio<br>
        • <code>{transcription}</code> - Texto de la transcripción de Whisper<br>
        • <code>{template}</code> - Contenido de la plantilla seleccionada
    `;
    customPromptField.appendChild(promptHelp);
    
    ollamaSection.appendChild(customPromptField);
    
    // ===== SECCIÓN: RESALTADO DE TRANSCRIPCIÓN =====
    if (typeof TranscriptionHighlightConfig !== 'undefined') {
        const highlightSectionContainer = document.createElement('div');
        highlightSectionContainer.className = 'config-section mb-4';
        highlightSectionContainer.id = 'transcriptionHighlightConfigSection';
        container.appendChild(highlightSectionContainer);
        
        // Renderizar usando el componente reutilizable
        TranscriptionHighlightConfig.render(config, 'transcriptionHighlightConfigSection', {
            showTitle: true,
            title: 'Resaltado de Transcripción',
            compactMode: false
        });
    }
    
    // ===== SECCIÓN: CONFIGURACIÓN GENERAL =====
    const generalSection = document.createElement('div');
    generalSection.className = 'config-section';
    generalSection.innerHTML = '<h5 class="mb-3" style="color: #198754; border-bottom: 2px solid #198754; padding-bottom: 10px;"><i class="fas fa-cog me-2"></i>Configuración General</h5>';
    container.appendChild(generalSection);
    
    // Campos restantes
    const fields = [
        { key: 'timeout', label: 'Timeout General (segundos)', type: 'number', required: false, placeholder: '300', help: 'Tiempo máximo de espera para las solicitudes a Ollama' },
        { key: 'max_audio_size_mb', label: 'Tamaño Máximo de Audio (MB)', type: 'number', required: false, placeholder: '25', help: 'Tamaño máximo permitido para archivos de audio (en MB)' }
    ];
    fields.forEach(field => {
        const fieldDiv = document.createElement('div');
        fieldDiv.className = 'config-item';
        const value = config[field.key] || '';
        fieldDiv.innerHTML = `
            <label class="config-label">
                ${field.label} ${field.required ? '<span class="text-danger">*</span>' : ''}
                ${field.help ? `<small class="text-muted d-block">${field.help}</small>` : ''}
            </label>
            <input type="${field.type}" class="form-control config-input" id="ai_${field.key}" value="${value}" placeholder="${field.placeholder || ''}" ${field.required ? 'required' : ''}>
        `;
        generalSection.appendChild(fieldDiv);
    });
    
    // Cargar modelos de Ollama automáticamente
    if (typeof loadOllamaModels === 'function') {
        loadOllamaModels();
    }
    
    // Guardar el valor actual del modelo antes de cargar
    const modelSelect = document.getElementById('ai_whisper_model');
    if (modelSelect && whisperModelValue) {
        modelSelect.setAttribute('data-current-value', whisperModelValue);
    }
    
    // Llamar a toggleWhisperMethodFields para mostrar/ocultar campos según el método seleccionado
    setTimeout(() => {
        toggleWhisperMethodFields();
        // Cargar modelos inicialmente
        loadWhisperModels();
    }, 100);
}

/**
 * Guardar configuración de AI Informes
 */
async function saveAiInformesConfig() {
    showLoading(true);
    try {
        const config = {
            // Configuración de Whisper.cpp
            whisper_method: document.getElementById('ai_whisper_method')?.value || 'whisper-server',
            whisper_api_url: document.getElementById('ai_whisper_api_url')?.value || 'http://localhost:8080',
            whisper_cli_api_url: document.getElementById('ai_whisper_cli_api_url')?.value || 'http://localhost:3001',
            whisper_model: document.getElementById('ai_whisper_model')?.value || 'base',
            whisper_language: document.getElementById('ai_whisper_language')?.value || 'es',
            whisper_timeout: parseInt(document.getElementById('ai_whisper_timeout')?.value || '300'),
            // Configuración de FFmpeg REST
            ffmpeg_rest_url: document.getElementById('ai_ffmpeg_rest_url')?.value || 'http://localhost:3000',
            // Configuración de Ollama
            ollama_base_url: document.getElementById('ai_ollama_base_url')?.value || 'http://localhost:11434',
            medgemma_model: document.getElementById('ai_medgemma_model')?.value || 'medgemma',
            custom_prompt: document.getElementById('ai_custom_prompt')?.value || null,
            // Configuración general
            timeout: parseInt(document.getElementById('ai_timeout')?.value || '300'),
            max_audio_size_mb: parseInt(document.getElementById('ai_max_audio_size_mb')?.value || '25'),
            // Configuración de cola de transcripciones
            auto_transcribe_enabled: document.getElementById('ai_auto_transcribe_enabled')?.checked || false,
            max_concurrent_transcriptions: parseInt(document.getElementById('ai_max_concurrent_transcriptions')?.value || '1'),
            // Configuración de resaltado de transcripción
            transcription_highlight_throttle_ms: parseInt(document.getElementById('transcription_highlight_throttle_ms')?.value || '30'),
            transcription_highlight_audio_offset: parseFloat(document.getElementById('transcription_highlight_audio_offset')?.value || '-0.1'),
            transcription_highlight_scroll_behavior: document.getElementById('transcription_highlight_scroll_behavior')?.value || 'smooth',
            transcription_highlight_enable_auto_scroll: document.getElementById('transcription_highlight_enable_auto_scroll')?.checked !== false,
            transcription_highlight_active_color: document.getElementById('transcription_highlight_active_color')?.value || '#ffeb3b',
            transcription_highlight_active_bg: document.getElementById('transcription_highlight_active_bg')?.value || '#ffeb3b',
            transcription_highlight_hover_color: document.getElementById('transcription_highlight_hover_color')?.value || '#1976d2',
            transcription_highlight_hover_bg: document.getElementById('transcription_highlight_hover_bg')?.value || '#e3f2fd',
            transcription_highlight_font_weight: parseInt(document.getElementById('transcription_highlight_font_weight')?.value || '600'),
            transcription_highlight_transition_duration: parseFloat(document.getElementById('transcription_highlight_transition_duration')?.value || '0.2'),
            transcription_highlight_config_ui_type: document.getElementById('transcription_highlight_config_ui_type')?.value || 'modal',
            transcription_highlight_enable_preview: document.getElementById('transcription_highlight_enable_preview')?.checked !== false
        };
        // Validaciones según el método seleccionado
        const method = config.whisper_method;
        if (method === 'whisper-server' && !config.whisper_api_url) {
            showAlert('error', 'La URL API de Whisper Server es requerida');
            showLoading(false);
            return;
        }
        if (method === 'whisper-cli' && !config.whisper_cli_api_url) {
            showAlert('error', 'La URL API de Whisper CLI es requerida');
            showLoading(false);
            return;
        }
        if (!config.ollama_base_url) {
            showAlert('error', 'La URL base de Ollama es requerida');
            showLoading(false);
            return;
        }
        const token = getAuthToken();
        const response = await fetch('api/ai-informes-config.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(config)
        });
        const data = await response.json();
        if (data.success) {
            showAlert('success', 'Configuración de AI Informes guardada exitosamente');
        } else {
            showAlert('error', 'Error al guardar configuración: ' + data.message);
        }
    } catch (error) {
        console.error('Error guardando configuración de AI Informes:', error);
        showAlert('error', 'Error al guardar configuración: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Cargar modelos de Whisper según el método seleccionado
 */
async function loadWhisperModels() {
    const methodSelect = document.getElementById('ai_whisper_method');
    const modelSelect = document.getElementById('ai_whisper_model');
    const helpText = document.getElementById('whisper_model_help');
    const reloadBtn = document.getElementById('btn_reload_whisper_models');
    
    if (!methodSelect || !modelSelect) return;
    
    const method = methodSelect.value;
    const currentValue = modelSelect.value || modelSelect.getAttribute('data-current-value') || '';
    
    // Guardar el valor actual antes de recargar
    if (modelSelect.value) {
        modelSelect.setAttribute('data-current-value', modelSelect.value);
    }
    
    modelSelect.innerHTML = '<option value="">Cargando modelos...</option>';
    
    if (method === 'whisper-cli') {
        // Cargar modelos desde la API de whisper-cli
        const cliApiUrl = document.getElementById('ai_whisper_cli_api_url')?.value || '';
        
        if (!cliApiUrl) {
            modelSelect.innerHTML = '<option value="">Configura la URL de whisper-cli primero</option>';
            if (helpText) helpText.textContent = 'Configura la URL de whisper-cli API para cargar los modelos disponibles';
            if (reloadBtn) reloadBtn.style.display = 'none';
            return;
        }
        
        if (helpText) helpText.textContent = 'Modelos disponibles en el servidor whisper-cli';
        if (reloadBtn) reloadBtn.style.display = 'inline-block';
        
        try {
            // Usar el backend PHP para evitar problemas de CORS
            const token = getAuthToken();
            const response = await fetch(`api/ai-informes-config.php?action=list-whisper-cli-models&whisper_cli_api_url=${encodeURIComponent(cliApiUrl)}`, {
                method: 'GET',
                headers: {
                    'Authorization': 'Bearer ' + token
                }
            });
            
            if (!response.ok) {
                throw new Error(`Error ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Error al obtener modelos');
            }
            
            const models = data.models || [];
            
            if (!Array.isArray(models) || models.length === 0) {
                modelSelect.innerHTML = '<option value="">No se encontraron modelos</option>';
                return;
            }
            
            // Ordenar modelos por tamaño (más pequeños primero) y luego por nombre
            models.sort((a, b) => {
                if (a.size !== b.size) return a.size - b.size;
                return a.name.localeCompare(b.name);
            });
            
            modelSelect.innerHTML = '';
            models.forEach(model => {
                const modelName = model.name;
                const sizeMB = parseFloat(model.size_mb || 0);
                const sizeText = sizeMB > 0 ? ` (${sizeMB} MB)` : '';
                const isSelected = currentValue === modelName || (!currentValue && modelName.includes('base') && !modelName.includes('large'));
                
                modelSelect.innerHTML += `<option value="${modelName}" ${isSelected ? 'selected' : ''}>${modelName}${sizeText}</option>`;
            });
            
            // Si no hay valor seleccionado, seleccionar el primero
            if (!currentValue && modelSelect.value === '') {
                modelSelect.selectedIndex = 0;
            }
            
        } catch (error) {
            console.error('Error cargando modelos de whisper-cli:', error);
            modelSelect.innerHTML = `<option value="">Error: ${error.message}</option>`;
            if (helpText) helpText.innerHTML = `<span class="text-danger">Error al cargar modelos: ${error.message}</span>`;
        }
    } else {
        // Modelos estándar para whisper-server
        const standardModels = [
            { value: 'tiny', label: 'tiny' },
            { value: 'base', label: 'base' },
            { value: 'small', label: 'small' },
            { value: 'medium', label: 'medium' },
            { value: 'large', label: 'large' },
            { value: 'large-v2', label: 'large-v2' },
            { value: 'large-v3', label: 'large-v3' }
        ];
        
        if (helpText) helpText.textContent = 'Modelo de whisper.cpp a usar (tiny, base, small, medium, large)';
        if (reloadBtn) reloadBtn.style.display = 'none';
        
        modelSelect.innerHTML = '';
        standardModels.forEach(model => {
            const isSelected = currentValue === model.value || (!currentValue && model.value === 'base');
            modelSelect.innerHTML += `<option value="${model.value}" ${isSelected ? 'selected' : ''}>${model.label}</option>`;
        });
    }
}

/**
 * Mostrar/ocultar campos según el método de Whisper seleccionado
 */
function toggleWhisperMethodFields() {
    const method = document.getElementById('ai_whisper_method')?.value || 'whisper-server';
    const serverField = document.getElementById('whisper-server-field');
    const cliField = document.getElementById('whisper-cli-field');
    
    if (method === 'whisper-server') {
        if (serverField) serverField.style.display = 'block';
        if (cliField) cliField.style.display = 'none';
    } else {
        if (serverField) serverField.style.display = 'none';
        if (cliField) cliField.style.display = 'block';
    }
    
    // Cargar modelos según el método seleccionado
    loadWhisperModels();
}

/**
 * Probar conexión con whisper-cli API
 */
async function testWhisperCliConnection() {
    const url = document.getElementById('ai_whisper_cli_api_url')?.value;
    if (!url) {
        showAlert('error', 'Ingresa la URL de whisper-cli API');
        return;
    }
    
    const statusSpan = document.getElementById('whisperCliConnectionStatus');
    if (statusSpan) {
        statusSpan.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Probando health...';
        statusSpan.className = 'mt-1 text-info';
    }
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes-config.php?action=test-whisper-cli', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                whisper_cli_api_url: url
            })
        });
        
        const data = await response.json();
        const status = (data.status || '').toLowerCase();
        
        if (statusSpan) {
            if (data.allow_enqueue || data.success) {
                if (status === 'degraded' || data.degraded) {
                    statusSpan.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Degradado: ' + (data.message || 'GPU/ffmpeg/cola');
                    statusSpan.className = 'mt-1 text-warning';
                } else {
                    statusSpan.innerHTML = '<i class="fas fa-check-circle me-2"></i>OK: ' + (data.message || 'Listo para transcribir');
                    statusSpan.className = 'mt-1 text-success';
                }
            } else {
                statusSpan.innerHTML = '<i class="fas fa-times-circle me-2"></i>Caído: ' + (data.message || 'No listo (ready=false)');
                statusSpan.className = 'mt-1 text-danger';
            }
        }
    } catch (error) {
        if (statusSpan) {
            statusSpan.innerHTML = '<i class="fas fa-times-circle me-2"></i>Error: ' + error.message;
            statusSpan.className = 'mt-1 text-danger';
        }
    }
}

/**
 * Verificar estado del cron job cuando se activa/desactiva la transcripción automática
 */
async function checkCronJobStatus() {
    const autoTranscribeEnabled = document.getElementById('ai_auto_transcribe_enabled')?.checked || false;
    const warningDiv = document.getElementById('cronJobWarning');
    const detailsDiv = document.getElementById('cronJobDetails');
    
    if (!autoTranscribeEnabled) {
        if (warningDiv) warningDiv.style.display = 'none';
        return;
    }
    
    // Mostrar advertencia mientras se verifica
    if (warningDiv) {
        warningDiv.style.display = 'block';
        detailsDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando cron job...';
    }
    
    try {
        const token = getAuthToken();
        const response = await fetch('api/ai-informes.php?action=check-cron', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        const data = await response.json();
        
        if (data.success) {
            if (data.cron_installed) {
                detailsDiv.innerHTML = `
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Cron job configurado correctamente.</strong>
                        <br><small>Cron: ${data.cron_line || 'N/A'}</small>
                    </div>
                `;
            } else {
                detailsDiv.innerHTML = `
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Cron job no está configurado.</strong>
                        <br><small>Para activar la transcripción automática, ejecuta:</small>
                        <br><code style="font-size: 0.9em;">crontab -e</code>
                        <br><small>Y agrega esta línea:</small>
                        <br><code style="font-size: 0.85em; display: block; margin-top: 5px; padding: 5px; background: #f8f9fa; border-radius: 3px;">${data.suggested_cron || '* * * * * php /var/www/tjsiddse/workers/transcription-queue-worker.php'}</code>
                    </div>
                `;
            }
        } else {
            detailsDiv.innerHTML = `
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No se pudo verificar el cron job: ${data.message || 'Error desconocido'}
                </div>
            `;
        }
    } catch (error) {
        console.error('Error verificando cron job:', error);
        detailsDiv.innerHTML = `
            <div class="alert alert-danger mb-0">
                <i class="fas fa-exclamation-circle me-2"></i>
                Error al verificar cron job: ${error.message}
            </div>
        `;
    }
}

/**
 * Probar conexión con ffmpeg-rest
 */
async function testFfmpegRestConnection() {
    const url = document.getElementById('ai_ffmpeg_rest_url')?.value;
    const statusSpan = document.getElementById('ffmpegRestConnectionStatus');
    
    if (!url) {
        if (statusSpan) {
            statusSpan.innerHTML = '<span class="text-danger">⚠ Ingresa una URL</span>';
        }
        return;
    }
    
    if (statusSpan) {
        statusSpan.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Probando conexión...</span>';
    }
    
    try {
        const token = getAuthToken();
        // Usar el backend PHP para evitar problemas de Mixed Content (HTTPS → HTTP)
        const response = await fetch('api/ai-informes-config.php?action=test-ffmpeg-rest', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ ffmpeg_rest_url: url })
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (statusSpan) {
                const message = data.endpoints 
                    ? `<i class="fas fa-check-circle"></i> ✓ ${data.message} (${data.endpoints})`
                    : `<i class="fas fa-check-circle"></i> ✓ ${data.message}`;
                statusSpan.innerHTML = `<span class="text-success">${message}</span>`;
            }
        } else {
            if (statusSpan) {
                statusSpan.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle"></i> ✗ ${data.message || 'Error de conexión'}</span>`;
            }
        }
    } catch (error) {
        if (statusSpan) {
            statusSpan.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle"></i> ✗ Error: ${error.message}</span>`;
        }
    }
}

/**
 * Probar conexión con whisper.cpp
 */
async function testWhisperConnection() {
    const statusEl = document.getElementById('whisperConnectionStatus');
    if (statusEl) {
        statusEl.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Probando...</span>';
    }
    try {
        const whisperUrl = document.getElementById('ai_whisper_api_url')?.value || '';
        if (!whisperUrl) {
            if (statusEl) {
                statusEl.innerHTML = '<span class="text-danger">Completa la URL de whisper.cpp</span>';
            }
            return;
        }
        const token = getAuthToken();
        const response = await fetch('api/ai-informes-config.php?action=test-whisper', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ 
                whisper_api_url: whisperUrl,
                whisper_timeout: parseInt(document.getElementById('ai_whisper_timeout')?.value || '10')
            })
        });
        const data = await response.json();
        if (statusEl) {
            if (data.success) {
                statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check"></i> Conexión exitosa</span>';
            } else {
                statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times"></i> ' + (data.message || 'Error de conexión') + '</span>';
            }
        }
    } catch (error) {
        console.error('Error probando conexión con whisper.cpp:', error);
        if (statusEl) {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times"></i> Error: ' + error.message + '</span>';
        }
    }
}

/**
 * Probar conexión con Ollama
 */
async function testOllamaConnection() {
    const statusEl = document.getElementById('ollamaConnectionStatus');
    statusEl.innerHTML = '<span class="connection-status">Probando...</span>';
    try {
        const ollamaUrl = document.getElementById('ai_ollama_base_url')?.value || 'http://localhost:11434';
        if (!ollamaUrl) {
            statusEl.innerHTML = '<span class="connection-status error">Completa la URL de Ollama</span>';
            return;
        }
        const token = getAuthToken();
        const response = await fetch('api/ai-informes-config.php?action=test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ ollama_base_url: ollamaUrl })
        });
        const data = await response.json();
        if (data.success) {
            statusEl.innerHTML = '<span class="connection-status success"><i class="fas fa-check"></i> Conexión exitosa</span>';
            // Recargar modelos después de una conexión exitosa
            if (typeof loadOllamaModels === 'function') {
                loadOllamaModels();
            }
        } else {
            statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> ' + (data.message || 'Error de conexión') + '</span>';
        }
    } catch (error) {
        console.error('Error probando conexión:', error);
        statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> Error: ' + error.message + '</span>';
    }
}

/**
 * Cargar modelos de Ollama
 */
async function loadOllamaModels() {
    const ollamaUrl = document.getElementById('ai_ollama_base_url')?.value;
    const medgemmaSelect = document.getElementById('ai_medgemma_model');
    const statusEl = document.getElementById('ollamaConnectionStatus');

    if (!ollamaUrl) {
        if (statusEl) {
            statusEl.innerHTML = '<span class="connection-status error">URL de Ollama no configurada.</span>';
        }
        return;
    }

    if (statusEl) {
        statusEl.innerHTML = '<span class="connection-status">Cargando modelos...</span>';
    }
    try {
        const token = getAuthToken();
        const response = await fetch(`api/ai-informes-config.php?action=list-models&ollama_url=${encodeURIComponent(ollamaUrl)}`, {
            method: 'GET',
            headers: { 'Authorization': 'Bearer ' + token }
        });
        const data = await response.json();

        if (data.success && data.models) {
            const currentMedgemmaModel = medgemmaSelect ? medgemmaSelect.value : '';

            if (medgemmaSelect) {
                medgemmaSelect.innerHTML = '';
                data.models.forEach(model => {
                    const option = document.createElement('option');
                    option.value = model; // Usar el nombre completo con tag
                    // Mostrar un nombre más legible en el texto
                    const displayName = model.includes(':') ? model : model + ' (latest)';
                    option.textContent = displayName;
                    medgemmaSelect.appendChild(option);
                });

                // Restaurar selección guardada
                // Intentar hacer match con el valor guardado (puede ser sin tag)
                const savedMedgemma = medgemmaSelect.getAttribute('data-saved-value');
                if (savedMedgemma) {
                    // Buscar modelo que coincida (con o sin tag)
                    const matchingModel = Array.from(medgemmaSelect.options).find(opt => 
                        opt.value === savedMedgemma || 
                        opt.value.startsWith(savedMedgemma + ':') ||
                        opt.value.replace(/:[^:]+$/, '') === savedMedgemma
                    );
                    if (matchingModel) {
                        medgemmaSelect.value = matchingModel.value;
                    } else {
                        medgemmaSelect.value = savedMedgemma; // Intentar con el valor original
                    }
                } else if (currentMedgemmaModel) {
                    // Mismo proceso para el valor actual
                    const matchingModel = Array.from(medgemmaSelect.options).find(opt => 
                        opt.value === currentMedgemmaModel || 
                        opt.value.startsWith(currentMedgemmaModel + ':') ||
                        opt.value.replace(/:[^:]+$/, '') === currentMedgemmaModel
                    );
                    if (matchingModel) {
                        medgemmaSelect.value = matchingModel.value;
                    } else {
                        medgemmaSelect.value = currentMedgemmaModel;
                    }
                }
            }

            if (statusEl) {
                statusEl.innerHTML = '<span class="connection-status success"><i class="fas fa-check"></i> Modelos cargados</span>';
            }
        } else {
            if (statusEl) {
                statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> Error al cargar modelos: ' + (data.message || 'Error desconocido') + '</span>';
            }
            if (medgemmaSelect) {
                medgemmaSelect.innerHTML = '<option value="">Error al cargar modelos</option>';
            }
        }
    } catch (error) {
        console.error('Error cargando modelos de Ollama:', error);
        if (statusEl) {
            statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> Error: ' + error.message + '</span>';
        }
        if (medgemmaSelect) {
            medgemmaSelect.innerHTML = '<option value="">Error al cargar modelos</option>';
        }
    }
}

// ===== CONFIGURACIÓN DE FTP =====

/**
 * Cargar configuración de FTP cuando se muestra la pestaña
 */
document.addEventListener('DOMContentLoaded', function() {
    const ftpTab = document.getElementById('ftp-tab');
    if (ftpTab) {
        ftpTab.addEventListener('shown.bs.tab', function() {
            loadFtpConfig();
        });
    }
});

/**
 * Cargar configuración de FTP
 */
async function loadFtpConfig() {
    showLoading(true);
    try {
        const token = getAuthToken();
        const response = await fetch('api/config/ftp-config.php?action=list', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        const data = await response.json();
        if (data.success) {
            await renderFtpConfig(data.configs || []);
        } else {
            showAlert('error', 'Error al cargar configuración FTP: ' + data.message);
        }
    } catch (error) {
        console.error('Error cargando configuración FTP:', error);
        showAlert('error', 'Error al cargar configuración FTP: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Renderizar configuración de FTP - Lista de usuarios con sus configuraciones
 */
async function renderFtpConfig(configs) {
    const container = document.getElementById('ftpConfig');
    if (!container) return;
    container.innerHTML = '';
    
    // Botón para crear nueva configuración
    const headerDiv = document.createElement('div');
    headerDiv.className = 'd-flex justify-content-between align-items-center mb-3';
    headerDiv.innerHTML = `
        <h5 class="mb-0">Configuraciones FTP por Usuario</h5>
        <button class="btn btn-primary" onclick="showFtpConfigModal()">
            <i class="fas fa-plus"></i> Nueva Configuración FTP
        </button>
    `;
    container.appendChild(headerDiv);
    
    if (!configs || configs.length === 0) {
        container.innerHTML += `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                No hay configuraciones FTP configuradas. Haz clic en "Nueva Configuración FTP" para crear una.
            </div>
        `;
        return;
    }
    
    // Tabla de configuraciones
    const tableDiv = document.createElement('div');
    tableDiv.className = 'table-responsive';
    tableDiv.innerHTML = `
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Nombre Config</th>
                    <th>Servidor FTP</th>
                    <th>Usuario FTP</th>
                    <th>Ruta Remota</th>
                    <th>Auto Envío</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="ftpConfigsTableBody">
            </tbody>
        </table>
    `;
    container.appendChild(tableDiv);
    
    const tbody = document.getElementById('ftpConfigsTableBody');
    
    configs.forEach(config => {
        const row = document.createElement('tr');
        const userName = config.nombre && config.apellido 
            ? `${config.nombre} ${config.apellido}` 
            : config.email || 'N/A';
        const autoSend = config.ftp_auto_send == 1 ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>';
        const activo = config.activo == 1 ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-danger">Inactivo</span>';
        
        row.innerHTML = `
            <td>${userName}</td>
            <td>${config.nombre_config || 'N/A'}</td>
            <td>${config.ftp_host}:${config.ftp_port}</td>
            <td>${config.ftp_username}</td>
            <td>${config.ftp_remote_path || '/audios/'}</td>
            <td>${autoSend}</td>
            <td>${activo}</td>
            <td>
                <button class="btn btn-sm btn-info me-1" onclick="editFtpConfig(${config.id})" title="Editar">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-success me-1" onclick="testFtpConfigConnection(${config.id})" title="Probar Conexión">
                    <i class="fas fa-plug"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteFtpConfig(${config.id})" title="Eliminar">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

/**
 * Mostrar modal para crear/editar configuración FTP
 */
async function showFtpConfigModal(configId = null) {
    // Cargar usuarios disponibles
    const token = getAuthToken();
    const usersResponse = await fetch('api/config/ftp-config.php?action=users', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token
        }
    });
    const usersData = await usersResponse.json();
    
    if (!usersData.success) {
        showAlert('error', 'Error al cargar usuarios: ' + usersData.message);
        return;
    }
    
    let config = null;
    if (configId) {
        const configResponse = await fetch(`api/config/ftp-config.php?action=get&id=${configId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        const configData = await configResponse.json();
        if (configData.success) {
            config = configData.config;
            // Asegurar que has_password esté definido
            if (config.ftp_password === '••••••••' || config.has_password) {
                config.has_password = true;
            } else {
                config.has_password = false;
            }
            console.log('Configuración cargada:', { ...config, ftp_password: '***' });
        }
    }
    
    // Crear modal
    const modalHtml = `
        <div class="modal fade" id="ftpConfigModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${configId ? 'Editar' : 'Nueva'} Configuración FTP</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="ftpConfigForm">
                            <input type="hidden" id="ftp_config_id" value="${configId || ''}">
                            
                            <div class="mb-3">
                                <label class="form-label">Usuario del Sistema <span class="text-danger">*</span></label>
                                <select class="form-select" id="ftp_usuario_id" required>
                                    <option value="">Seleccione un usuario</option>
                                    ${usersData.users.map(u => `
                                        <option value="${u.id}" ${config && config.usuario_id == u.id ? 'selected' : ''}>
                                            ${u.nombre} ${u.apellido} (${u.email})
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Nombre de la Configuración <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ftp_nombre_config" 
                                       value="${config ? config.nombre_config : ''}" 
                                       placeholder="Ej: Servidor Principal" required>
                                <small class="text-muted">Nombre descriptivo para identificar esta configuración</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Servidor FTP (Host) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="ftp_host" 
                                           value="${config ? config.ftp_host : ''}" 
                                           placeholder="ftp.ejemplo.com" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Puerto</label>
                                    <input type="number" class="form-control" id="ftp_port" 
                                           value="${config ? config.ftp_port : '21'}" 
                                           min="1" max="65535">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Usuario FTP <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ftp_username" 
                                       value="${config ? config.ftp_username : ''}" 
                                       placeholder="usuario_ftp" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Contraseña FTP <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="ftp_password" 
                                           value="${config && (config.has_password === true || config.ftp_password === '••••••••') ? '••••••••' : ''}"
                                           placeholder="${config && (config.has_password === true || config.ftp_password === '••••••••') ? '•••••••• (contraseña guardada)' : 'Ingresa la contraseña FTP'}" 
                                           ${!config ? 'required' : ''}>
                                    ${config && (config.has_password === true || config.ftp_password === '••••••••') ? `
                                        <button class="btn btn-outline-secondary" type="button" onclick="clearFtpPasswordField()" title="Limpiar campo para ingresar nueva contraseña">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    ` : ''}
                                </div>
                                <small class="text-muted">${config && (config.has_password === true || config.ftp_password === '••••••••') ? 'Contraseña guardada. Haz clic en el ícono de editar para ingresar una nueva contraseña' : 'Ingresa la contraseña para el servidor FTP'}</small>
                                ${config && (config.has_password === true || config.ftp_password === '••••••••') ? '<input type="hidden" id="ftp_password_saved" value="1">' : ''}
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Ruta Remota</label>
                                <input type="text" class="form-control" id="ftp_remote_path" 
                                       value="${config ? config.ftp_remote_path : '/audios/'}" 
                                       placeholder="/audios/">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="ftp_passive_mode" 
                                               ${config && config.ftp_passive_mode == 1 ? 'checked' : ''}>
                                        <label class="form-check-label" for="ftp_passive_mode">Modo Pasivo</label>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Timeout (seg)</label>
                                    <input type="number" class="form-control" id="ftp_timeout" 
                                           value="${config ? config.ftp_timeout : '30'}" 
                                           min="5" max="300">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Reintentos</label>
                                    <input type="number" class="form-control" id="ftp_retry_attempts" 
                                           value="${config ? config.ftp_retry_attempts : '3'}" 
                                           min="0" max="10">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="ftp_auto_send" 
                                           ${config && config.ftp_auto_send == 1 ? 'checked' : ''}>
                                    <label class="form-check-label" for="ftp_auto_send">Envío Automático</label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="ftp_activo" 
                                           ${config && config.activo == 1 ? 'checked' : ''}>
                                    <label class="form-check-label" for="ftp_activo">Activo</label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-info" onclick="testFtpConnectionFromModal()">
                            <i class="fas fa-plug"></i> Probar Conexión
                        </button>
                        <span id="ftpModalConnectionStatus" class="ms-2"></span>
                        <div class="flex-grow-1"></div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary" onclick="saveFtpConfigFromModal()">Guardar</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remover modal anterior si existe
    const existingModal = document.getElementById('ftpConfigModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Agregar modal al body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Mostrar modal
    const modal = new bootstrap.Modal(document.getElementById('ftpConfigModal'));
    modal.show();
    
    // Limpiar al cerrar
    document.getElementById('ftpConfigModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

/**
 * Guardar configuración FTP desde el modal
 */
async function saveFtpConfigFromModal() {
    const form = document.getElementById('ftpConfigForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const configId = document.getElementById('ftp_config_id').value;
    const passwordInput = document.getElementById('ftp_password');
    let ftpPassword = passwordInput?.value || '';
    const hasSavedPassword = document.getElementById('ftp_password_saved')?.value === '1';
    
    // Si la contraseña es la máscara o está vacía y hay contraseña guardada, no incluirla (mantener la guardada)
    if ((ftpPassword === '••••••••' || ftpPassword === '') && hasSavedPassword && configId) {
        ftpPassword = null; // No cambiar la contraseña
    }
    
    const config = {
        usuario_id: parseInt(document.getElementById('ftp_usuario_id').value),
        nombre_config: document.getElementById('ftp_nombre_config').value,
        ftp_host: document.getElementById('ftp_host').value,
        ftp_port: parseInt(document.getElementById('ftp_port').value || '21'),
        ftp_username: document.getElementById('ftp_username').value,
        ftp_remote_path: document.getElementById('ftp_remote_path').value || '/audios/',
        ftp_passive_mode: document.getElementById('ftp_passive_mode').checked ? 1 : 0,
        ftp_timeout: parseInt(document.getElementById('ftp_timeout').value || '30'),
        ftp_retry_attempts: parseInt(document.getElementById('ftp_retry_attempts').value || '3'),
        ftp_auto_send: document.getElementById('ftp_auto_send').checked ? 1 : 0,
        activo: document.getElementById('ftp_activo').checked ? 1 : 0
    };
    
    // Manejar contraseña según el caso
    if (!configId) {
        // Nueva configuración: la contraseña es obligatoria
        if (!ftpPassword || ftpPassword.trim() === '' || ftpPassword === '••••••••') {
            showAlert('error', 'La contraseña FTP es requerida para crear una nueva configuración');
            return;
        }
        config.ftp_password = ftpPassword.trim();
    } else {
        // Editar configuración: solo incluir contraseña si se proporcionó una nueva
        if (ftpPassword && ftpPassword !== '••••••••' && ftpPassword.trim() !== '') {
            config.ftp_password = ftpPassword.trim();
        }
        // Si no se proporciona contraseña nueva, no se incluye en el objeto (se mantiene la guardada)
    }
    
    // Debug: verificar que la contraseña esté incluida para nuevas configuraciones
    if (!configId && !config.ftp_password) {
        console.error('Error: No se proporcionó contraseña para nueva configuración', config);
        showAlert('error', 'Error: La contraseña FTP es requerida');
        return;
    }
    
    // Debug: no mostrar contraseña en logs
    const configForLog = { ...config };
    if (configForLog.ftp_password) {
        configForLog.ftp_password = '***';
    }
    console.log('Guardando configuración FTP:', configForLog);
    
    showLoading(true);
    try {
        const token = getAuthToken();
        const url = configId 
            ? `api/config/ftp-config.php?id=${configId}`
            : 'api/config/ftp-config.php';
        const method = configId ? 'PUT' : 'POST';
        
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(config)
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('success', configId ? 'Configuración FTP actualizada exitosamente' : 'Configuración FTP creada exitosamente');
            bootstrap.Modal.getInstance(document.getElementById('ftpConfigModal')).hide();
            loadFtpConfig(); // Recargar lista
        } else {
            showAlert('error', 'Error al guardar: ' + data.message);
        }
    } catch (error) {
        console.error('Error guardando configuración FTP:', error);
        showAlert('error', 'Error al guardar: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Editar configuración FTP
 */
async function editFtpConfig(configId) {
    await showFtpConfigModal(configId);
}

/**
 * Eliminar configuración FTP
 */
async function deleteFtpConfig(configId) {
    if (!confirm('¿Estás seguro de que deseas eliminar esta configuración FTP?')) {
        return;
    }
    
    showLoading(true);
    try {
        const token = getAuthToken();
        const response = await fetch(`api/config/ftp-config.php?id=${configId}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('success', 'Configuración FTP eliminada exitosamente');
            loadFtpConfig(); // Recargar lista
        } else {
            showAlert('error', 'Error al eliminar: ' + data.message);
        }
    } catch (error) {
        console.error('Error eliminando configuración FTP:', error);
        showAlert('error', 'Error al eliminar: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Probar conexión de una configuración FTP específica
 */
async function testFtpConfigConnection(configId) {
    const statusEl = document.getElementById('ftpConnectionStatus');
    if (statusEl) {
        statusEl.innerHTML = '<span class="connection-status">Cargando configuración...</span>';
    }
    
    try {
        const token = getAuthToken();
        
        // Obtener configuración
        const configResponse = await fetch(`api/config/ftp-config.php?action=get&id=${configId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        
        const configData = await configResponse.json();
        
        if (!configData.success) {
            if (statusEl) {
                statusEl.innerHTML = '<span class="connection-status error">Error al cargar configuración</span>';
            }
            return;
        }
        
        const config = configData.config;
        
        // Obtener contraseña guardada automáticamente
        if (statusEl) {
            statusEl.innerHTML = '<span class="connection-status">Obteniendo contraseña guardada...</span>';
        }
        
        const passwordResponse = await fetch(`api/config/ftp-config.php?action=get-password&id=${configId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        
        let password = null;
        if (passwordResponse.ok) {
            const passwordData = await passwordResponse.json();
            if (passwordData.success) {
                password = passwordData.password;
            }
        }
        
        if (!password) {
            if (statusEl) {
                statusEl.innerHTML = '<span class="connection-status error">No se pudo obtener la contraseña guardada</span>';
            }
            return;
        }
        
        if (statusEl) {
            statusEl.innerHTML = '<span class="connection-status">Probando conexión...</span>';
        }
        
        // Probar conexión
        const testResponse = await fetch('api/config/ftp-config.php?action=test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                ftp_host: config.ftp_host,
                ftp_port: config.ftp_port,
                ftp_username: config.ftp_username,
                ftp_password: password,
                ftp_passive_mode: config.ftp_passive_mode,
                ftp_timeout: config.ftp_timeout,
                config_id: configId
            })
        });
        
        const testData = await testResponse.json();
        
        if (statusEl) {
            if (testData.success) {
                statusEl.innerHTML = '<span class="connection-status success"><i class="fas fa-check"></i> ' + (testData.message || 'Conexión exitosa') + '</span>';
            } else {
                statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> ' + (testData.message || 'Error de conexión') + '</span>';
            }
        }
    } catch (error) {
        console.error('Error probando conexión FTP:', error);
        if (statusEl) {
            statusEl.innerHTML = '<span class="connection-status error"><i class="fas fa-times"></i> Error: ' + error.message + '</span>';
        }
    }
}

/**
 * Limpiar campo de contraseña para ingresar nueva
 */
function clearFtpPasswordField() {
    const passwordInput = document.getElementById('ftp_password');
    if (passwordInput) {
        passwordInput.value = '';
        passwordInput.placeholder = 'Ingresa la nueva contraseña FTP';
        passwordInput.focus();
    }
}

/**
 * Obtener contraseña guardada para pruebas (solo si es necesario)
 */
async function getSavedFtpPassword(configId) {
    if (!configId) return null;
    
    try {
        const token = getAuthToken();
        const response = await fetch(`api/config/ftp-config.php?action=get-password&id=${configId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        
        if (!response.ok) {
            return null;
        }
        
        const data = await response.json();
        return data.success ? data.password : null;
    } catch (error) {
        console.error('Error obteniendo contraseña guardada:', error);
        return null;
    }
}

/**
 * Probar conexión FTP desde el modal
 */
async function testFtpConnectionFromModal() {
    const statusEl = document.getElementById('ftpModalConnectionStatus');
    if (!statusEl) return;
    
    // Validar campos requeridos
    const ftpHost = document.getElementById('ftp_host')?.value;
    const ftpUsername = document.getElementById('ftp_username')?.value;
    const ftpPasswordInput = document.getElementById('ftp_password');
    let ftpPassword = ftpPasswordInput?.value || '';
    const ftpPort = document.getElementById('ftp_port')?.value || '21';
    const configId = document.getElementById('ftp_config_id')?.value;
    const hasSavedPassword = document.getElementById('ftp_password_saved')?.value === '1';
    
    if (!ftpHost || !ftpUsername) {
        statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> Completa servidor y usuario</span>';
        return;
    }
    
    // Si la contraseña ingresada es la máscara o está vacía y hay contraseña guardada, usar la guardada
    if ((ftpPassword === '••••••••' || ftpPassword === '' || ftpPassword.trim() === '') && hasSavedPassword && configId) {
        // Intentar obtener la contraseña guardada para la prueba
        statusEl.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Obteniendo contraseña guardada...</span>';
        ftpPassword = await getSavedFtpPassword(configId);
        if (!ftpPassword || ftpPassword.trim() === '') {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> No se pudo obtener la contraseña guardada. Haz clic en el ícono de editar e ingresa la contraseña manualmente.</span>';
            return;
        }
    } else if (!ftpPassword || ftpPassword.trim() === '' || ftpPassword === '••••••••') {
        // Si no hay contraseña ingresada, intentar usar la guardada si existe
        if (hasSavedPassword && configId) {
            statusEl.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Obteniendo contraseña guardada...</span>';
            ftpPassword = await getSavedFtpPassword(configId);
            if (!ftpPassword || ftpPassword.trim() === '') {
                statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> No se pudo obtener la contraseña guardada. Haz clic en el ícono de editar e ingresa la contraseña manualmente.</span>';
                return;
            }
        } else {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> Ingresa la contraseña para probar</span>';
            return;
        }
    }
    
    statusEl.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Probando conexión...</span>';
    
    try {
        const config = {
            ftp_host: ftpHost.trim(),
            ftp_port: parseInt(ftpPort) || 21,
            ftp_username: ftpUsername.trim(),
            ftp_password: ftpPassword,
            ftp_passive_mode: document.getElementById('ftp_passive_mode')?.checked ? 1 : 0,
            ftp_timeout: parseInt(document.getElementById('ftp_timeout')?.value || '30'),
            config_id: configId || null
        };
        
        // Validar que los campos no estén vacíos
        if (!config.ftp_host || !config.ftp_username || !config.ftp_password) {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> Completa todos los campos requeridos</span>';
            return;
        }
        
        console.log('Enviando prueba de conexión FTP:', { ...config, ftp_password: '***' });
        
        const token = getAuthToken();
        if (!token) {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> No hay token de autenticación</span>';
            return;
        }
        
        const response = await fetch('api/config/ftp-config.php?action=test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(config)
        });
        
        const responseText = await response.text();
        console.log('Respuesta del servidor:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            throw new Error('Error al parsear respuesta del servidor: ' + responseText);
        }
        
        if (!response.ok) {
            throw new Error(data.message || `Error HTTP ${response.status}`);
        }
        
        if (data.success) {
            statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> ' + (data.message || 'Conexión exitosa') + '</span>';
            if (data.current_directory) {
                statusEl.innerHTML += '<br><small class="text-muted">Directorio actual: ' + data.current_directory + '</small>';
            }
        } else {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> ' + (data.message || 'Error de conexión') + '</span>';
        }
    } catch (error) {
        console.error('Error probando conexión FTP:', error);
        statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> Error: ' + error.message + '</span>';
    }
}

/**
 * Probar conexión FTP (función antigua - mantener para compatibilidad)
 */
async function testFtpConnection() {
    // Esta función ya no se usa, pero la mantenemos para compatibilidad
    showAlert('info', 'Selecciona una configuración FTP y haz clic en "Probar Conexión" para probar');
}

// ===== URLs WADO / proxy DICOMweb por nodo (Historial de estudios) =====

async function loadPacsNodesStudyHistoryUrls() {
    const container = document.getElementById('pacsStudyHistoryConfig');
    const migrationAlert = document.getElementById('pacsStudyHistoryMigrationAlert');
    if (!container) return;

    showLoading(true);
    try {
        const token = getAuthToken();
        const response = await fetch('api/config/pacs-nodes-study-history-urls.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        });
        const data = await response.json();

        if (!data.success) {
            showAlert('error', data.message || 'Error al cargar nodos');
            container.innerHTML = '';
            return;
        }

        if (!data.columns_present && migrationAlert) {
            migrationAlert.classList.remove('d-none');
            const missing = [];
            if (!data.has_wado_uri_base) missing.push('wado_uri_base');
            if (!data.has_dicomweb_proxy_base) missing.push('dicomweb_proxy_base');
            migrationAlert.innerHTML =
                '<strong>Faltan columnas en la base de datos:</strong> ' + missing.join(', ') + '. ' +
                'Ejecute el script SQL <code>modules/study-history-manager/database/migration_add_node_viewer_bases.sql</code> y vuelva a abrir esta pestaña.';
        } else if (migrationAlert) {
            migrationAlert.classList.add('d-none');
            migrationAlert.innerHTML = '';
            if (!data.has_remote_open_mode) {
                migrationAlert.classList.remove('d-none');
                migrationAlert.innerHTML =
                    '<strong>Manifest UDV (DCM4CHEE legacy):</strong> ejecute <code>modules/study-history-manager/database/migration_remote_open_mode.sql</code> para elegir por nodo entre WADO a nivel estudio y manifest por instancia (DIMSE). ' +
                    'El tipo de nodo (DIMSE / DICOMweb / híbrido) se define en <strong>PACS Nodes Manager</strong> al editar el nodo; aquí solo se configuran URLs de visor y el modo de apertura de UDV.';
            }
        }

        const nodes = data.nodes || [];
        if (nodes.length === 0) {
            container.innerHTML = '<p class="text-muted">No hay nodos en <code>pacs_nodes</code>. Cree nodos en PACS Nodes Manager.</p>';
            return;
        }

        let html = '<p class="text-muted small mb-2">El <strong>tipo de nodo</strong> (dimse, dicomweb, hybrid, local) y el modo de búsqueda híbrido se configuran en <a href="pacs-nodes-manager.html">PACS Nodes Manager</a> → Editar Nodo. Esta tabla solo ajusta bases WADO/proxy Stone y cómo el <strong>UDV</strong> abre estudios <em>remotos</em> desde el historial. Si el visor está en otro dominio y <code>manifest.php</code> devuelve 404, en <strong>Configuración → URLs</strong> defina <code>url_study_history_api</code> con la URL de este servidor donde está <code>/modules/study-history-manager/api/</code> (p. ej. <code>https://plataforma.iddse.com.ar</code>).</p>';
        html += '<div class="table-responsive"><table class="table table-bordered table-sm align-middle">';
        html += '<thead class="table-light"><tr>';
        html += '<th>Nodo</th><th>Tipo</th><th>Activo</th>';
        html += '<th>wado_uri_base <small class="text-muted fw-normal">(WADO-URI legacy)</small></th>';
        html += '<th>dicomweb_proxy_base <small class="text-muted fw-normal">(proxy Stone/DICOMweb)</small></th>';
        html += '<th>UDV en remoto <small class="text-muted fw-normal">(apertura)</small></th>';
        html += '</tr></thead><tbody>';

        nodes.forEach(function (n) {
            const id = n.id;
            const wado = (n.wado_uri_base != null && n.wado_uri_base !== undefined) ? String(n.wado_uri_base) : '';
            const proxy = (n.dicomweb_proxy_base != null && n.dicomweb_proxy_base !== undefined) ? String(n.dicomweb_proxy_base) : '';
            const activeBadge = parseInt(n.is_active, 10) === 1
                ? '<span class="badge bg-success">Sí</span>'
                : '<span class="badge bg-secondary">No</span>';
            html += '<tr>';
            html += '<td><strong>' + escapeHtmlConfig(n.name || '') + '</strong><br><small class="text-muted">id ' + id + '</small></td>';
            html += '<td><code>' + escapeHtmlConfig(n.node_type || '') + '</code></td>';
            html += '<td>' + activeBadge + '</td>';
            if (data.columns_present) {
                html += '<td><input type="text" class="form-control form-control-sm shm-wado" data-node-id="' + id + '" value="' + escapeAttrConfig(wado) + '" placeholder="https://pacs/ej/wado"></td>';
                html += '<td><input type="text" class="form-control form-control-sm shm-proxy" data-node-id="' + id + '" value="' + escapeAttrConfig(proxy) + '" placeholder="https://proxy/dicomweb"></td>';
            } else {
                html += '<td colspan="2" class="text-muted small">Ejecute la migración SQL para habilitar estos campos.</td>';
            }
            const nt = String(n.node_type || '').toLowerCase();
            const manifestNotApplicable = nt === 'dicomweb';
            let rom = (n.remote_open_mode === 'wado_manifest') ? 'wado_manifest' : 'dicomweb';
            if (manifestNotApplicable) {
                rom = 'dicomweb';
            }
            if (data.columns_present && data.has_remote_open_mode) {
                const titleManifest = 'Solo aplica a nodos con C-FIND DIMSE (dimse o hybrid). Los nodos solo DICOMweb ya están definidos en PACS Nodes Manager.';
                html += '<td><select class="form-select form-select-sm shm-rom" data-node-id="' + id + '" data-node-type="' + escapeAttrConfig(n.node_type || '') + '"';
                if (manifestNotApplicable) {
                    html += ' title="' + escapeAttrConfig('Nodo DICOMweb: el inventario del manifest requiere DIMSE. Cambie el tipo de nodo en PACS Nodes Manager si corresponde.') + '"';
                }
                html += '>';
                html += '<option value="dicomweb"' + (rom === 'dicomweb' ? ' selected' : '') + '>WADO estudio</option>';
                html += '<option value="wado_manifest"' + (rom === 'wado_manifest' ? ' selected' : '') + (manifestNotApplicable ? ' disabled' : '') + ' title="' + escapeAttrConfig(titleManifest) + '">Manifest DIMSE</option>';
                html += '</select></td>';
            } else if (data.columns_present) {
                html += '<td class="text-muted small">Migración <code>migration_remote_open_mode.sql</code></td>';
            } else {
                html += '<td class="text-muted small">—</td>';
            }
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (e) {
        console.error(e);
        showAlert('error', 'Error al cargar visores por nodo: ' + e.message);
    } finally {
        showLoading(false);
    }
}

function escapeHtmlConfig(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function escapeAttrConfig(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

async function savePacsNodesStudyHistoryUrls() {
    const container = document.getElementById('pacsStudyHistoryConfig');
    if (!container) return;

    const rows = [];
    container.querySelectorAll('tr').forEach(function (tr) {
        const wadoIn = tr.querySelector('.shm-wado');
        const proxyIn = tr.querySelector('.shm-proxy');
        if (!wadoIn || !proxyIn) return;
        const id = parseInt(wadoIn.getAttribute('data-node-id'), 10);
        if (!id) return;
        const romEl = tr.querySelector('.shm-rom');
        const row = {
            id: id,
            wado_uri_base: wadoIn.value.trim(),
            dicomweb_proxy_base: proxyIn.value.trim()
        };
        if (romEl) {
            const nt = String(romEl.getAttribute('data-node-type') || '').toLowerCase();
            row.remote_open_mode = nt === 'dicomweb' ? 'dicomweb' : romEl.value;
        }
        rows.push(row);
    });

    if (rows.length === 0) {
        showAlert('warning', 'No hay filas para guardar. ¿Ejecutó la migración SQL?');
        return;
    }

    showLoading(true);
    try {
        const token = getAuthToken();
        const response = await fetch('api/config/pacs-nodes-study-history-urls.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ nodes: rows })
        });
        const data = await response.json();
        if (data.success) {
            showAlert('success', data.message || 'URLs por nodo guardadas');
            await loadPacsNodesStudyHistoryUrls();
        } else {
            showAlert('error', data.message || 'Error al guardar');
        }
    } catch (e) {
        showAlert('error', e.message || String(e));
    } finally {
        showLoading(false);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const tab = document.getElementById('pacs-study-history-tab');
    if (tab) {
        tab.addEventListener('shown.bs.tab', function () {
            loadPacsNodesStudyHistoryUrls();
        });
    }
    const slaTab = document.getElementById('estudios-recibidos-tab');
    if (slaTab) {
        slaTab.addEventListener('shown.bs.tab', function () {
            loadSlaPlantillas();
            updateSlaLuaSnippet();
        });
    }
    const gasaludTab = document.getElementById('gasalud-envio-tab');
    if (gasaludTab) {
        gasaludTab.addEventListener('shown.bs.tab', function () {
            syncGasaludAuthFieldsVisibility();
        });
    }
    const logsTab = document.getElementById('logs-tab');
    if (logsTab) {
        logsTab.addEventListener('shown.bs.tab', function () {
            loadHl7LogsPanel();
            loadHl7MessagesList();
        });
    }
    document.getElementById('hl7LogRefreshBtn')?.addEventListener('click', () => {
        loadHl7LogsPanel();
        loadHl7MessagesList();
    });
    document.getElementById('hl7LogAutoRefresh')?.addEventListener('change', (e) => {
        if (e.target.checked) {
            window._hl7LogTimer = setInterval(() => {
                loadHl7LogsPanel(true);
                loadHl7MessagesList(true);
            }, 5000);
        } else if (window._hl7LogTimer) {
            clearInterval(window._hl7LogTimer);
            window._hl7LogTimer = null;
        }
    });
    document.getElementById('hl7MsgRefreshBtn')?.addEventListener('click', () => loadHl7MessagesList());
    document.getElementById('hl7MsgFolder')?.addEventListener('change', () => loadHl7MessagesList());
    document.getElementById('hl7MsgSelect')?.addEventListener('change', () => loadHl7MessageContent());
    const addBtn = document.getElementById('slaTplAddBtn');
    if (addBtn) {
        addBtn.addEventListener('click', saveSlaPlantilla);
    }
});

async function loadHl7LogsPanel(silent) {
    const viewer = document.getElementById('hl7LogViewer');
    const meta = document.getElementById('hl7LogMeta');
    const badge = document.getElementById('hl7LogAliveBadge');
    const statusText = document.getElementById('hl7LogStatusText');
    const lines = document.getElementById('hl7LogLines')?.value || '300';
    const token = typeof getAuthToken === 'function' ? getAuthToken() : '';
    const headers = token ? { Authorization: 'Bearer ' + token } : {};
    try {
        if (!silent && viewer) viewer.textContent = 'Cargando…';
        const [stResp, logResp] = await Promise.all([
            fetch('modules/hl7-worklist/api/logs.php?action=status', { headers }),
            fetch('modules/hl7-worklist/api/logs.php?file=listener.log&lines=' + encodeURIComponent(lines), { headers })
        ]);
        const st = await stResp.json();
        const lg = await logResp.json();
        if (st.success && st.data) {
            const alive = !!st.data.listener_alive;
            if (badge) {
                badge.textContent = alive ? 'Listener vivo' : 'Sin heartbeat';
                badge.className = 'badge ms-2 ' + (alive ? 'bg-success' : 'bg-danger');
            }
            if (statusText) {
                statusText.textContent = 'modo=' + (st.data.worklist_ingest_mode || '—')
                    + ' · ' + (st.data.hl7_bind_host || '') + ':' + (st.data.hl7_port || '')
                    + ' · hl7_enabled=' + st.data.hl7_enabled;
            }
        }
        if (!lg.success) {
            if (viewer) viewer.textContent = lg.message || 'No se pudo leer el log';
            return;
        }
        if (viewer) {
            viewer.textContent = (lg.data && lg.data.content) ? lg.data.content : '(vacío)';
            viewer.scrollTop = viewer.scrollHeight;
        }
        if (meta && lg.data) {
            meta.textContent = 'Archivo: ' + (lg.data.file || 'listener.log')
                + ' · ' + (lg.data.size_bytes || 0) + ' bytes'
                + (lg.data.modified_at ? ' · mod ' + lg.data.modified_at : '');
        }
    } catch (e) {
        if (viewer) viewer.textContent = e.message || String(e);
    }
}

function hl7AuthHeaders() {
    const token = typeof getAuthToken === 'function' ? getAuthToken() : '';
    return token ? { Authorization: 'Bearer ' + token } : {};
}

async function loadHl7MessagesList(silent) {
    const select = document.getElementById('hl7MsgSelect');
    const viewer = document.getElementById('hl7MsgViewer');
    const meta = document.getElementById('hl7MsgMeta');
    const folder = document.getElementById('hl7MsgFolder')?.value || '';
    if (!select) return;
    const prev = select.value;
    try {
        if (!silent && viewer) viewer.textContent = 'Listando mensajes…';
        let url = 'modules/hl7-worklist/api/logs.php?action=messages&limit=50';
        if (folder) url += '&folder=' + encodeURIComponent(folder);
        const resp = await fetch(url, { headers: hl7AuthHeaders() });
        const data = await resp.json();
        if (!data.success) {
            select.innerHTML = '<option value="">(error)</option>';
            if (viewer) viewer.textContent = data.message || 'No se pudo listar';
            return;
        }
        const msgs = (data.data && data.data.messages) || [];
        if (!msgs.length) {
            select.innerHTML = '<option value="">(sin mensajes .hl7)</option>';
            if (viewer) viewer.textContent = 'No hay archivos .hl7 en la carpeta seleccionada.';
            if (meta) meta.textContent = 'Sin mensajes';
            return;
        }
        select.innerHTML = msgs.map((m) => {
            const val = m.folder + '|' + m.name;
            const label = '[' + m.folder + '] ' + m.name + ' (' + m.size_bytes + ' B)';
            return '<option value="' + val.replace(/"/g, '&quot;') + '">' + label.replace(/</g, '&lt;') + '</option>';
        }).join('');
        if (prev && [...select.options].some((o) => o.value === prev)) {
            select.value = prev;
        } else {
            select.selectedIndex = 0;
        }
        await loadHl7MessageContent();
    } catch (e) {
        if (viewer) viewer.textContent = e.message || String(e);
    }
}

async function loadHl7MessageContent() {
    const select = document.getElementById('hl7MsgSelect');
    const viewer = document.getElementById('hl7MsgViewer');
    const meta = document.getElementById('hl7MsgMeta');
    if (!select || !viewer) return;
    const raw = select.value || '';
    if (!raw || raw.indexOf('|') < 0) {
        viewer.textContent = 'Seleccioná un mensaje de la lista…';
        return;
    }
    const pipe = raw.indexOf('|');
    const folder = raw.slice(0, pipe);
    const name = raw.slice(pipe + 1);
    try {
        viewer.textContent = 'Cargando…';
        const url = 'modules/hl7-worklist/api/logs.php?action=message'
            + '&folder=' + encodeURIComponent(folder)
            + '&name=' + encodeURIComponent(name);
        const resp = await fetch(url, { headers: hl7AuthHeaders() });
        const data = await resp.json();
        if (!data.success) {
            viewer.textContent = data.message || 'No se pudo leer el mensaje';
            return;
        }
        const d = data.data || {};
        viewer.textContent = d.content || '(vacío)';
        if (meta) {
            meta.textContent = d.folder + '/' + d.name
                + ' · ' + (d.size_bytes || 0) + ' bytes'
                + (d.modified_at ? ' · mod ' + d.modified_at : '')
                + (d.truncated ? ' · (truncado a 256 KiB)' : '');
        }
    } catch (e) {
        viewer.textContent = e.message || String(e);
    }
}

function slaConfigAuthHeaders() {
    const token = getAuthToken();
    return {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: 'Bearer ' + token } : {})
    };
}

function updateSlaLuaSnippet() {
    const hint = document.getElementById('slaWebhookUrlHint');
    const pre = document.getElementById('slaLuaSnippet');
    const base = window.location.origin + '/api/estudios/local-arrived-webhook.php';
    if (hint) hint.textContent = base;
    const secretCfg = (allConfigurations.estudios_recibidos || {}).sla_webhook_secret;
    const hasSecret = secretCfg && secretCfg.value && secretCfg.value !== '';
    if (pre) {
        pre.textContent = [
            'function OnStableStudy(studyId, tags, metadata, origin)',
            '  local url = "' + base + '"',
            '  local token = "REEMPLAZAR_CON_sla_webhook_secret"',
            '  local payload = string.format(',
            '    \'{"orthanc_study_id":"%s","modality":"%s"}\',',
            '    studyId, tostring(tags["ModalitiesInStudy"] or tags["Modality"] or "")',
            '  )',
            '  local headers = { ["Authorization"] = "Bearer " .. token, ["Content-Type"] = "application/json" }',
            '  local ok, status = pcall(function()',
            '    return HttpPost(url, payload, headers)',
            '  end)',
            '  if not ok then print("[SLA] HttpPost error: " .. tostring(status)) end',
            'end',
            hasSecret ? '' : '-- Configure sla_webhook_secret en esta pestaña antes de usar el webhook.'
        ].join('\n');
    }
}

async function loadSlaPlantillas() {
    const panel = document.getElementById('slaPlantillasPanel');
    if (!panel) return;
    try {
        const resp = await fetch('api/estudios/sla-templates.php', { headers: slaConfigAuthHeaders() });
        const data = await resp.json();
        if (!data.success) {
            panel.innerHTML = '<p class="text-muted small">' + (data.message || 'No se pudieron cargar plantillas') + '</p>';
            return;
        }
        const items = data.items || [];
        if (!items.length) {
            panel.innerHTML = '<p class="text-muted small mb-0">Sin plantillas. Se usa el default de horas.</p>';
            return;
        }
        panel.innerHTML = '<table class="table table-sm"><thead><tr><th>Nombre</th><th>Horas</th><th>Modalidades</th><th>Prioridad</th><th>Activo</th><th></th></tr></thead><tbody>' +
            items.map(function (it) {
                return '<tr><td>' + escapeHtmlConfig(it.nombre) + '</td><td>' + it.horas + '</td><td>' +
                    escapeHtmlConfig(it.modalidades || '(todas)') + '</td><td>' + it.prioridad + '</td><td>' +
                    (Number(it.activo) ? 'Sí' : 'No') +
                    '</td><td><button type="button" class="btn btn-sm btn-outline-danger" data-sla-del="' + it.id + '">Eliminar</button></td></tr>';
            }).join('') + '</tbody></table>';
        panel.querySelectorAll('[data-sla-del]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                deleteSlaPlantilla(btn.getAttribute('data-sla-del'));
            });
        });
    } catch (e) {
        panel.innerHTML = '<p class="text-danger small">' + (e.message || String(e)) + '</p>';
    }
}

function escapeHtmlConfig(t) {
    const d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
}

async function saveSlaPlantilla() {
    const nombre = (document.getElementById('slaTplNombre') || {}).value || '';
    const horas = parseInt((document.getElementById('slaTplHoras') || {}).value || '72', 10);
    const modalidades = (document.getElementById('slaTplMods') || {}).value || '';
    const prioridad = parseInt((document.getElementById('slaTplPrio') || {}).value || '100', 10);
    try {
        const resp = await fetch('api/estudios/sla-templates.php', {
            method: 'POST',
            headers: slaConfigAuthHeaders(),
            body: JSON.stringify({ nombre: nombre.trim(), horas: horas, modalidades: modalidades.trim(), prioridad: prioridad, activo: 1 })
        });
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'Error');
        showAlert('success', 'Plantilla guardada');
        if (document.getElementById('slaTplNombre')) document.getElementById('slaTplNombre').value = '';
        loadSlaPlantillas();
    } catch (e) {
        showAlert('error', e.message || String(e));
    }
}

async function deleteSlaPlantilla(id) {
    if (!confirm('¿Eliminar plantilla?')) return;
    try {
        const resp = await fetch('api/estudios/sla-templates.php?id=' + encodeURIComponent(id), {
            method: 'DELETE',
            headers: slaConfigAuthHeaders()
        });
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'Error');
        loadSlaPlantillas();
    } catch (e) {
        showAlert('error', e.message || String(e));
    }
}

/**
 * Mostrar/ocultar campos de auth Gasalud según gasalud_auth_mode.
 */
function syncGasaludAuthFieldsVisibility() {
    const modeEl = document.getElementById('config_gasalud_auth_mode');
    const mode = modeEl ? modeEl.value : 'none';
    const show = {
        gasalud_login_url: mode === 'login',
        gasalud_auth_token: mode === 'bearer' || mode === 'api_key' || mode === 'login',
        gasalud_token_expires_at: mode === 'login',
        gasalud_api_key_header: mode === 'api_key',
        gasalud_auth_username: mode === 'basic' || mode === 'login',
        gasalud_auth_password: mode === 'basic' || mode === 'login',
    };
    Object.keys(show).forEach(function (key) {
        const input = document.getElementById('config_' + key);
        if (!input) return;
        const item = input.closest('.config-item');
        if (item) item.style.display = show[key] ? '' : 'none';
    });
}

/**
 * Valida config Gasalud; con testLogin=true prueba Usuarios/Login.
 */
async function testGasaludEndpoint(testLogin) {
    const status = document.getElementById('gasaludTestStatus');
    if (status) status.textContent = testLogin ? 'Probando login…' : 'Validando…';
    try {
        const token = getAuthToken();
        const resp = await fetch('api/informes/gasalud-test.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(token ? { Authorization: 'Bearer ' + token } : {})
            },
            body: JSON.stringify({
                dry_run: !testLogin,
                test_login: !!testLogin
            })
        });
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'Fallo la prueba');
        if (status) {
            status.textContent = data.message || 'OK';
            status.className = 'small text-success';
        }
        showAlert('success', data.message || 'Prueba OK');
        if (testLogin) {
            // refrescar token/expiración en el formulario
            loadConfigurations();
        }
    } catch (e) {
        if (status) {
            status.textContent = e.message || String(e);
            status.className = 'small text-danger';
        }
        showAlert('error', e.message || String(e));
    }
}
