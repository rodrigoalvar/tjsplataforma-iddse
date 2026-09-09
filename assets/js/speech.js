// Módulo de reconocimiento de voz
const SpeechModule = {
    // Estado del reconocimiento
    recognitionState: {
        isListening: false,
        recognition: null,
        language: 'es-ES',
        interimResults: true
    },

    // Inicializar reconocimiento de voz
    initRecognition: function() {
        try {
            // Verificar soporte del navegador
            if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
                console.warn('Reconocimiento de voz no soportado en este navegador');
                this.showUnsupportedMessage();
                return false;
            }

            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            this.recognitionState.recognition = new SpeechRecognition();

            // Configurar reconocimiento
            this.recognitionState.recognition.lang = this.recognitionState.language;
            this.recognitionState.recognition.continuous = true;
            this.recognitionState.recognition.interimResults = this.recognitionState.interimResults;

            // Configurar eventos
            this.setupRecognitionEvents();

            console.log('Reconocimiento de voz inicializado correctamente');
            return true;
        } catch (error) {
            console.error('Error al inicializar reconocimiento de voz:', error);
            this.showUnsupportedMessage();
            return false;
        }
    },

    // Configurar eventos del reconocimiento
    setupRecognitionEvents: function() {
        if (!this.recognitionState.recognition) return;

        this.recognitionState.recognition.onstart = () => {
            this.recognitionState.isListening = true;
            this.updateUI();
            console.log('Reconocimiento de voz iniciado');
        };

        this.recognitionState.recognition.onend = () => {
            this.recognitionState.isListening = false;
            this.updateUI();
            console.log('Reconocimiento de voz finalizado');
            // Desactivar reconocimiento de voz en el coordinador
            MicrophoneCoordinator.disableSpeech();
        };

        this.recognitionState.recognition.onerror = (event) => {
            console.error('Error en reconocimiento de voz:', event.error);
            this.recognitionState.isListening = false;
            this.updateUI();
            
            // Desactivar reconocimiento de voz en caso de error
            MicrophoneCoordinator.disableSpeech();
            
            // Manejar diferentes tipos de errores
            this.handleRecognitionError(event.error);
        };

        this.recognitionState.recognition.onresult = (event) => {
            let interimTranscript = '';
            let finalTranscript = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript;
                if (event.results[i].isFinal) {
                    finalTranscript += transcript;
                } else {
                    interimTranscript += transcript;
                }
            }

            this.updateTranscription(finalTranscript, interimTranscript);
        };
    },

    // Iniciar reconocimiento
    startListening: function(isRetry = false) {
        if (!this.recognitionState.recognition) {
            if (!this.initRecognition()) {
                alert('El reconocimiento de voz no está disponible en este navegador');
                return;
            }
        }

        if (!this.recognitionState.isListening) {
            // Activar reconocimiento de voz a través del coordinador
            MicrophoneCoordinator.enableSpeech((success) => {
                if (!success) {
                    return; // El coordinador ya mostró el mensaje de error
                }

                try {
                    // Resetear contador de reintentos solo al iniciar manualmente (no en reintentos)
                    if (!isRetry) {
                        this.recognitionState.retryCount = 0;
                    }
                    
                    // Limpiar mensajes de error previos
                    const statusElement = document.getElementById('speechStatus');
                    if (statusElement) {
                        statusElement.innerHTML = '';
                    }
                    
                    this.recognitionState.recognition.start();
                } catch (error) {
                    console.error('Error al iniciar reconocimiento:', error);
                    this.showErrorMessage('Error al iniciar el reconocimiento de voz. Intenta nuevamente.');
                    // Desactivar en caso de error
                    MicrophoneCoordinator.disableSpeech();
                }
            });
        }
    },

    // Detener reconocimiento
    stopListening: function() {
        if (this.recognitionState.recognition && this.recognitionState.isListening) {
            this.recognitionState.recognition.stop();
            // Desactivar reconocimiento de voz en el coordinador
            MicrophoneCoordinator.disableSpeech();
        }
    },

    // Actualizar transcripción en el editor
    updateTranscription: function(finalTranscript, interimTranscript) {
        const editor = tinymce.get('reportEditor');
        if (!editor) return;

        if (finalTranscript) {
            console.log('Final transcript received:', JSON.stringify(finalTranscript));
            // Procesar comandos de puntuación antes de insertar
            const processedText = this.processPunctuationCommands(finalTranscript);
            console.log('Processed text result:', JSON.stringify(processedText), 'Type:', typeof processedText);
            // Asegurar que processedText sea una cadena
            const textToInsert = typeof processedText === 'string' ? processedText : '';
            console.log('Text to insert:', JSON.stringify(textToInsert));
            // Solo insertar si hay texto para insertar
            if (textToInsert.length > 0) {
                console.log('Inserting text into editor:', JSON.stringify(textToInsert));
                editor.execCommand('mceInsertContent', false, textToInsert);
            } else {
                console.log('No text to insert (empty or action command)');
            }
        }

        // Mostrar texto intermedio en un elemento temporal
        const interimElement = document.getElementById('interimText');
        if (interimElement) {
            interimElement.textContent = interimTranscript;
        }
    },

    // Procesar comandos de puntuación
    processPunctuationCommands: function(text) {
        if (!text) return text;

        // Primero verificar si es un comando de acción
        if (typeof VoiceCommandsConfig !== 'undefined') {
            const actionResult = VoiceCommandsConfig.processActionCommand(text);
            if (actionResult) {
                console.log(`Comando de acción detectado: ${text}`);
                // Ejecutar la acción si VoiceActions está disponible
                if (typeof VoiceActions !== 'undefined') {
                    try {
                        const success = VoiceActions.executeAction(actionResult.action, actionResult.parameters);
                        if (success) {
                            console.log(`Acción ejecutada exitosamente: ${actionResult.action}`);
                        } else {
                            console.error(`Error ejecutando acción: ${actionResult.action}`);
                        }
                    } catch (error) {
                        console.error(`Excepción ejecutando acción ${actionResult.action}:`, error);
                    }
                } else {
                    console.warn('VoiceActions no está disponible');
                }
                // SIEMPRE retornar cadena vacía para comandos de acción
                return '';
            }
        }

        // Obtener comandos desde la configuración dinámica
        let punctuationCommands = {};
        
        // Verificar si VoiceCommandsConfig está disponible
        if (typeof VoiceCommandsConfig !== 'undefined') {
            punctuationCommands = VoiceCommandsConfig.getAllCommands();
        } else {
            // Comandos de respaldo si no está disponible la configuración
            punctuationCommands = {
                // Comandos básicos
                'punto': '.',
                'coma': ',',
                'dos puntos': ':',
                'punto y coma': ';',
                'signo de interrogación': '?',
                'signo de exclamación': '!',
                'comillas': '"',
                'paréntesis abierto': '(',
                'paréntesis cerrado': ')',
                'guión': '-',
                'guión largo': '—',
                'barra': '/',
                'porcentaje': '%',
                'grado': '°',
                
                // Comandos especiales
                'punto aparte': '.\n\n',
                'nueva línea': '\n',
                'salto de línea': '\n',
                'párrafo nuevo': '\n\n',
                'espacio': ' ',
                'tabulación': '\t',
                
                // Variaciones comunes
                'punto final': '.',
                'punto seguido': '. ',
                'interrogación': '?',
                'exclamación': '!',
                'abrir paréntesis': '(',
                'cerrar paréntesis': ')',
                'abrir comillas': '"',
                'cerrar comillas': '"',
                
                // Comandos médicos específicos
                'milímetros': 'mm',
                'centímetros': 'cm',
                'metros': 'm',
                'kilogramos': 'kg',
                'gramos': 'g',
                'miligramos': 'mg',
                'litros': 'L',
                'mililitros': 'ml',
                'grados celsius': '°C',
                'por ciento': '%',
                'más menos': '±',
                
                // Comandos de formato
                'mayúscula': '', // Se procesará especialmente
                'minúscula': '', // Se procesará especialmente
                'borrar': '', // Se procesará especialmente
                'deshacer': '' // Se procesará especialmente
            };
        }

        let processedText = text;

        // Procesar comandos especiales primero
        processedText = this.processSpecialCommands(processedText);

        // Procesar cada comando de puntuación
        Object.keys(punctuationCommands).forEach(command => {
            const punctuation = punctuationCommands[command];
            
            // Saltar comandos especiales que ya se procesaron
            if (['mayúscula', 'minúscula', 'borrar', 'deshacer'].includes(command)) {
                return;
            }
            
            // Crear expresiones regulares para diferentes variaciones
            const patterns = [
                new RegExp(`\\b${command}\\b`, 'gi'),
                new RegExp(`\\b${command}s\\b`, 'gi'), // plural
                new RegExp(`\\bpon ${command}\\b`, 'gi'), // "pon punto"
                new RegExp(`\\bagrega ${command}\\b`, 'gi'), // "agrega coma"
                new RegExp(`\\bescribe ${command}\\b`, 'gi') // "escribe punto"
            ];

            patterns.forEach(pattern => {
                processedText = processedText.replace(pattern, punctuation);
            });
        });

        // Limpiar espacios extra alrededor de la puntuación
        processedText = processedText
            .replace(/\s+([.,;:!?])/g, '$1') // Quitar espacios antes de puntuación
            .replace(/([.,;:!?])\s*([.,;:!?])/g, '$1$2') // Quitar espacios entre puntuación consecutiva
            .replace(/\s+/g, ' ') // Normalizar espacios múltiples
            .trim();

        // Agregar espacio después de puntuación si no hay salto de línea
        processedText = processedText.replace(/([.,;:!?])(?![\s\n])/g, '$1 ');

        // Asegurar que siempre devolvemos una cadena
        return typeof processedText === 'string' ? processedText : '';
    },

    // Procesar comandos especiales de formato
    processSpecialCommands: function(text) {
        let processedText = text;
        
        // Comando para convertir a mayúsculas
        const uppercasePattern = /\b(mayúscula|mayúsculas|en mayúscula|todo mayúscula)\s+([\w\s]+?)(?=\s+(punto|coma|dos puntos|nueva línea|párrafo|$))/gi;
        processedText = processedText.replace(uppercasePattern, (match, command, content) => {
            return content.toUpperCase();
        });
        
        // Comando para convertir a minúsculas
        const lowercasePattern = /\b(minúscula|minúsculas|en minúscula|todo minúscula)\s+([\w\s]+?)(?=\s+(punto|coma|dos puntos|nueva línea|párrafo|$))/gi;
        processedText = processedText.replace(lowercasePattern, (match, command, content) => {
            return content.toLowerCase();
        });
        
        // Comando para borrar (eliminar comandos de borrado)
        processedText = processedText.replace(/\b(borrar|borra|eliminar|elimina|quitar|quita)\b/gi, '');
        
        // Comando para deshacer (se manejará a nivel de editor)
        if (/\b(deshacer|deshaz|ctrl z|control z)\b/gi.test(processedText)) {
            // Ejecutar deshacer en el editor
            const editor = tinymce.get('reportEditor');
            if (editor) {
                editor.execCommand('Undo');
            }
            // Remover el comando del texto
            processedText = processedText.replace(/\b(deshacer|deshaz|ctrl z|control z)\b/gi, '');
        }
        
        return processedText;
    },

    // Actualizar interfaz
    updateUI: function() {
        const button = document.getElementById('dictateButton');
        const status = document.getElementById('dictationStatus');
        
        // No actualizar UI si el reconocimiento no está disponible
        if (!this.recognitionState.recognition) {
            return;
        }
        
        if (button && status) {
            if (this.recognitionState.isListening) {
                button.innerHTML = '<i class="fas fa-stop me-2"></i>Detener Dictado';
                button.className = 'btn btn-danger btn-lg';
                button.onclick = () => this.stopListening();
                button.disabled = false;
                status.textContent = 'Dictado activo';
                status.className = 'badge bg-success';
            } else {
                button.innerHTML = '<i class="fas fa-microphone-alt me-2"></i>Iniciar Dictado';
                button.className = 'btn btn-success btn-lg';
                button.onclick = () => this.startListening();
                button.disabled = false;
                status.textContent = 'Dictado detenido';
                status.className = 'badge bg-secondary';
            }
        }
    },

    // Cambiar idioma
    setLanguage: function(language) {
        this.recognitionState.language = language;
        if (this.recognitionState.recognition) {
            this.recognitionState.recognition.lang = language;
        }
    },

    // Manejar errores de reconocimiento
    handleRecognitionError: function(error) {
        let errorMessage = '';
        let shouldRetry = false;
        
        switch(error) {
            case 'network':
                errorMessage = 'Error de conexión. Verifica tu conexión a internet.';
                shouldRetry = true;
                break;
            case 'not-allowed':
                errorMessage = 'Acceso al micrófono denegado. Por favor, permite el acceso al micrófono.';
                break;
            case 'no-speech':
                errorMessage = 'No se detectó voz. Intenta hablar más cerca del micrófono.';
                shouldRetry = true;
                break;
            case 'audio-capture':
                errorMessage = 'Error al capturar audio. Verifica que el micrófono esté funcionando.';
                break;
            case 'service-not-allowed':
                errorMessage = 'Servicio de reconocimiento de voz no disponible.';
                break;
            default:
                errorMessage = `Error desconocido: ${error}`;
                break;
        }
        
        // Mostrar mensaje de error al usuario
        this.showErrorMessage(errorMessage);
        
        // Reintentar automáticamente para ciertos errores
        if (shouldRetry) {
            // Inicializar contador si no existe
            if (!this.recognitionState.retryCount) {
                this.recognitionState.retryCount = 0;
            }
            
            if (this.recognitionState.retryCount < 3) {
                this.recognitionState.retryCount++;
                setTimeout(() => {
                    console.log(`Reintentando reconocimiento de voz (intento ${this.recognitionState.retryCount})`);
                    this.startListening(true); // Pasar true para indicar que es un reintento
                }, 2000);
            } else {
                console.log('Máximo de reintentos alcanzado. Deteniendo reconocimiento de voz.');
                this.showErrorMessage('No se pudo establecer conexión para el reconocimiento de voz. Intenta más tarde.');
                this.recognitionState.retryCount = 0;
            }
        }
    },
    
    showErrorMessage: function(message) {
        // Buscar elemento de estado o crear uno temporal
        let statusElement = document.getElementById('speechStatus');
        if (!statusElement) {
            statusElement = document.getElementById('dictationStatus');
        }
        
        if (statusElement) {
            statusElement.innerHTML = `<div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
            
            // Auto-ocultar después de 5 segundos
            setTimeout(() => {
                const alert = statusElement.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        } else {
            // Fallback: mostrar en consola si no hay elemento de estado
            console.warn('Mensaje para el usuario:', message);
        }
    },

    showUnsupportedMessage: function() {
        const statusElement = document.getElementById('speechStatus');
        const dictateButton = document.getElementById('dictateButton');
        
        if (statusElement) {
            statusElement.innerHTML = `
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    El reconocimiento de voz no está disponible en este navegador. 
                    Recomendamos usar Chrome, Edge o Safari para esta funcionalidad.
                </div>
            `;
        }
        
        if (dictateButton) {
            dictateButton.disabled = true;
            dictateButton.innerHTML = '<i class="fas fa-microphone-slash me-2"></i>No Disponible';
            dictateButton.classList.remove('btn-success');
            dictateButton.classList.add('btn-secondary');
        }
    },

    // Limpiar recursos
    cleanup: function() {
        this.stopListening();
        this.recognitionState.recognition = null;
        this.recognitionState.retryCount = 0;
    }
};