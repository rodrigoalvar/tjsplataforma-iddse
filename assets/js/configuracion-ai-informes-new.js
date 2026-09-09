function renderAiInformesConfig(config) {
    const container = document.getElementById('aiInformesConfig');
    if (!container) return;
    container.innerHTML = '';
    
    // ===== SECCIÓN: WHISPER.CPP (Transcripción) =====
    const whisperSection = document.createElement('div');
    whisperSection.className = 'config-section mb-4';
    whisperSection.innerHTML = '<h5 class="mb-3" style="color: #667eea; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-microphone me-2"></i>Configuración de Whisper.cpp (Transcripción)</h5>';
    container.appendChild(whisperSection);
    
    // Campo URL API de Whisper
    const whisperUrlField = document.createElement('div');
    whisperUrlField.className = 'config-item';
    const whisperUrlValue = config.whisper_api_url || '';
    whisperUrlField.innerHTML = `
        <label class="config-label">
            URL API de Whisper.cpp <span class="text-danger">*</span>
            <small class="text-muted d-block">URL base del servidor whisper.cpp (ej: http://192.168.0.33:8080)</small>
        </label>
        <div class="input-group">
            <input type="text" class="form-control config-input" id="ai_whisper_api_url" value="${whisperUrlValue}" placeholder="http://localhost:8080" required>
            <button class="btn btn-outline-secondary" type="button" onclick="testWhisperConnection()" title="Probar conexión con whisper.cpp">
                <i class="fas fa-plug"></i> Probar
            </button>
        </div>
        <span id="whisperConnectionStatus" class="mt-1"></span>
    `;
    whisperSection.appendChild(whisperUrlField);
    
    // Campo Modelo Whisper
    const whisperModelField = document.createElement('div');
    whisperModelField.className = 'config-item';
    const whisperModelValue = config.whisper_model || 'base';
    whisperModelField.innerHTML = `
        <label class="config-label">
            Modelo Whisper
            <small class="text-muted d-block">Modelo de whisper.cpp a usar (tiny, base, small, medium, large)</small>
        </label>
        <select class="form-control config-input" id="ai_whisper_model">
            <option value="tiny" ${whisperModelValue === 'tiny' ? 'selected' : ''}>tiny</option>
            <option value="base" ${whisperModelValue === 'base' ? 'selected' : ''}>base</option>
            <option value="small" ${whisperModelValue === 'small' ? 'selected' : ''}>small</option>
            <option value="medium" ${whisperModelValue === 'medium' ? 'selected' : ''}>medium</option>
            <option value="large" ${whisperModelValue === 'large' ? 'selected' : ''}>large</option>
            <option value="large-v2" ${whisperModelValue === 'large-v2' ? 'selected' : ''}>large-v2</option>
            <option value="large-v3" ${whisperModelValue === 'large-v3' ? 'selected' : ''}>large-v3</option>
        </select>
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
}
