// Función actualizada para renderizar configuración de AI Informes
function renderAiInformesConfig(config) {
    const container = document.getElementById('aiInformesConfig');
    if (!container) return;
    container.innerHTML = '';
    
    // Campo URL Base
    const urlField = document.createElement('div');
    urlField.className = 'config-item';
    const urlValue = config.ollama_base_url || '';
    urlField.innerHTML = `
        <label class="config-label">
            URL Base de Ollama <span class="text-danger">*</span>
            <small class="text-muted d-block">URL base del servidor Ollama</small>
        </label>
        <div class="input-group">
            <input type="text" class="form-control config-input" id="ai_ollama_base_url" value="${urlValue}" placeholder="http://localhost:11434" required>
            <button class="btn btn-outline-secondary" type="button" onclick="loadOllamaModels()" title="Cargar modelos disponibles">
                <i class="fas fa-sync"></i> Cargar Modelos
            </button>
        </div>
    `;
    container.appendChild(urlField);
    
    // Campo Modelo Whisper (Select)
    const whisperField = document.createElement('div');
    whisperField.className = 'config-item';
    const whisperValue = config.whisper_model || '';
    whisperField.innerHTML = `
        <label class="config-label">
            Modelo para Transcripción (Whisper)
            <small class="text-muted d-block">Selecciona el modelo de Ollama para transcripción de audio</small>
        </label>
        <select class="form-control config-input" id="ai_whisper_model">
            <option value="">Cargando modelos...</option>
        </select>
    `;
    container.appendChild(whisperField);
    
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
    container.appendChild(medgemmaField);
    
    // Campos restantes
    const fields = [
        { key: 'timeout', label: 'Timeout (segundos)', type: 'number', required: false, placeholder: '300', help: 'Tiempo máximo de espera para las solicitudes a Ollama' },
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
        container.appendChild(fieldDiv);
    });
    
    // Guardar valores para restaurarlos después de cargar modelos
    const whisperSelect = document.getElementById('ai_whisper_model');
    const medgemmaSelect = document.getElementById('ai_medgemma_model');
    if (whisperSelect && whisperValue) {
        whisperSelect.setAttribute('data-saved-value', whisperValue);
    }
    if (medgemmaSelect && medgemmaValue) {
        medgemmaSelect.setAttribute('data-saved-value', medgemmaValue);
    }
    
    // Cargar modelos automáticamente
    if (typeof loadOllamaModels === 'function') {
        loadOllamaModels();
    }
}
