<?php
/**
 * Cliente para interactuar con Ollama REST API
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Funcionalidades:
 * - Transcripción de audio con Whisper
 * - Generación de informes médicos con Medgemma
 */

class OllamaAiClient {
    private $baseUrl;
    private $timeout;
    private $whisperModel;
    private $medgemmaModel;
    private $customPrompt;
    
    public function __construct($config = []) {
        $this->baseUrl = $config['ollama_base_url'] ?? 'http://localhost:11434';
        // Timeout aumentado para generación de informes largos (30 minutos por defecto para modelos grandes)
        // Los modelos grandes como medgemma:27b pueden tardar 10-20 minutos en generar informes largos
        $this->timeout = $config['timeout'] ?? 1800;
        $this->whisperModel = $config['whisper_model'] ?? 'whisper';
        $this->medgemmaModel = $config['medgemma_model'] ?? 'medgemma';
        $this->customPrompt = $config['custom_prompt'] ?? $config['default_prompt'] ?? null;
        
        // Asegurar que la URL no termine en /
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }
    
    /**
     * Transcribir audio usando Whisper
     * 
     * @param string $audioFilePath Ruta al archivo de audio
     * @return array ['success' => bool, 'text' => string, 'error' => string]
     */
    public function transcribeAudio($audioFilePath) {
        if (!file_exists($audioFilePath)) {
            return [
                'success' => false,
                'error' => 'Archivo de audio no encontrado: ' . $audioFilePath
            ];
        }
        
        // Leer el archivo de audio y convertirlo a base64
        $audioData = file_get_contents($audioFilePath);
        $audioBase64 = base64_encode($audioData);
        
        // Preparar el prompt para Whisper
        $prompt = "Transcribe este audio médico al español. Devuelve solo el texto transcrito, sin comentarios adicionales.";
        
        // Construir el JSON para la API de Ollama
        $requestData = [
            'model' => $this->whisperModel,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.0,
                'num_predict' => 4000
            ],
            'images' => [] // Whisper no usa imágenes, pero algunos modelos pueden requerirlo
        ];
        
        // Para Whisper, necesitamos usar el endpoint de generate con el audio en base64
        // Nota: La implementación exacta depende de cómo Ollama maneje Whisper
        // Algunas implementaciones requieren enviar el audio como parte del prompt o en un campo especial
        
        // Intentar con el endpoint estándar de generate
        $url = $this->baseUrl . '/api/generate';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($requestData)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            // Mensajes de error más amigables para transcripción
            $errorMessage = $error;
            if (strpos($error, 'Connection refused') !== false) {
                $errorMessage = "No se pudo conectar a Ollama en {$this->baseUrl}. Verifica que:\n" .
                               "1. Ollama esté corriendo en el servidor\n" .
                               "2. Ollama esté configurado para escuchar en la red (no solo localhost)\n" .
                               "   → Configura: export OLLAMA_HOST=0.0.0.0:11434\n" .
                               "   → Luego reinicia Ollama\n" .
                               "3. La IP y puerto sean correctos ({$this->baseUrl})\n" .
                               "4. No haya un firewall bloqueando la conexión";
            } elseif (strpos($error, 'Connection timed out') !== false) {
                $errorMessage = "Timeout al conectar con Ollama en {$this->baseUrl}. El servidor puede estar sobrecargado o la red es lenta.";
            } elseif (strpos($error, 'Failed to connect') !== false) {
                $errorMessage = "No se pudo establecer conexión con Ollama en {$this->baseUrl}. Verifica la configuración en la pestaña 'AI Informes' de Configuración.";
            }
            
            return [
                'success' => false,
                'error' => $errorMessage
            ];
        }
        
        if ($httpCode !== 200) {
            $errorMsg = 'Error HTTP ' . $httpCode;
            $responseData = json_decode($response, true);
            $modelError = '';
            
            if (isset($responseData['error'])) {
                $modelError = $responseData['error'];
                // Detectar si es un error de modelo no encontrado
                if (strpos(strtolower($modelError), 'not found') !== false || 
                    strpos(strtolower($modelError), 'model') !== false) {
                    $errorMsg = "Modelo no encontrado en Ollama: '{$this->medgemmaModel}'. " .
                               "Verifica que el modelo esté instalado ejecutando: " .
                               "ollama pull {$this->medgemmaModel}";
                } else {
                    $errorMsg = "Error de Ollama: " . $modelError;
                }
            } elseif ($httpCode === 404) {
                $errorMsg = "Endpoint no encontrado en Ollama. Verifica que el modelo '{$this->medgemmaModel}' esté instalado. " .
                           "Ejecuta: ollama pull {$this->medgemmaModel}";
            } elseif ($httpCode === 500) {
                $errorMsg = "Error interno en Ollama. Verifica los logs de Ollama para más detalles.";
            }
            
            return [
                'success' => false,
                'error' => $errorMsg . ($modelError ? ' (Detalle: ' . substr($modelError, 0, 100) . ')' : '')
            ];
        }
        
        $result = json_decode($response, true);
        
        if (!$result || !isset($result['response'])) {
            return [
                'success' => false,
                'error' => 'Respuesta inválida de Ollama: ' . substr($response, 0, 200)
            ];
        }
        
        return [
            'success' => true,
            'text' => trim($result['response']),
            'model' => $this->whisperModel,
            'raw_response' => $result
        ];
    }
    
    /**
     * Generar informe médico usando Medgemma
     * 
     * @param array $data Datos del paciente, estudio, transcripción y plantilla
     * @return array ['success' => bool, 'content' => string, 'error' => string]
     */
    public function generateReport($data) {
        // Construir el prompt completo
        $prompt = $this->buildPrompt($data);
        
        // Preparar la solicitud
        $requestData = [
            'model' => $this->medgemmaModel,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.7,
                'num_predict' => 2000,
                'top_p' => 0.9
            ]
        ];
        
        $url = $this->baseUrl . '/api/generate';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 30, // Timeout de conexión más corto
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($requestData),
            // Permitir que la conexión permanezca abierta durante el procesamiento
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 60,
            CURLOPT_TCP_KEEPINTVL => 10
        ]);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $processingTime = microtime(true) - $startTime;
        curl_close($ch);
        
        if ($error) {
            // Mensajes de error más amigables para generación de informe
            $errorMessage = $error;
            if (strpos($error, 'Connection refused') !== false) {
                $errorMessage = "No se pudo conectar a Ollama en {$this->baseUrl}. Verifica que:\n" .
                               "1. Ollama esté corriendo en el servidor\n" .
                               "2. Ollama esté configurado para escuchar en la red (no solo localhost)\n" .
                               "   → Configura: export OLLAMA_HOST=0.0.0.0:11434\n" .
                               "   → Luego reinicia Ollama\n" .
                               "3. La IP y puerto sean correctos ({$this->baseUrl})\n" .
                               "4. No haya un firewall bloqueando la conexión";
            } elseif (strpos($error, 'Connection timed out') !== false) {
                $errorMessage = "Timeout al conectar con Ollama en {$this->baseUrl}. El servidor puede estar sobrecargado o la red es lenta.";
            } elseif (strpos($error, 'Failed to connect') !== false) {
                $errorMessage = "No se pudo establecer conexión con Ollama en {$this->baseUrl}. Verifica la configuración en la pestaña 'AI Informes' de Configuración.";
            }
            
            return [
                'success' => false,
                'error' => $errorMessage
            ];
        }
        
        if ($httpCode !== 200) {
            $errorMsg = 'Error HTTP ' . $httpCode;
            $responseData = json_decode($response, true);
            $modelError = '';
            
            if (isset($responseData['error'])) {
                $modelError = $responseData['error'];
                // Detectar si es un error de modelo no encontrado
                if (strpos(strtolower($modelError), 'not found') !== false || 
                    strpos(strtolower($modelError), 'model') !== false) {
                    $errorMsg = "Modelo no encontrado en Ollama: '{$this->medgemmaModel}'. " .
                               "Para instalar el modelo, ejecuta en el servidor de Ollama: " .
                               "ollama pull {$this->medgemmaModel}";
                } else {
                    $errorMsg = "Error de Ollama: " . $modelError;
                }
            } elseif ($httpCode === 404) {
                $errorMsg = "Endpoint no encontrado en Ollama. Verifica que el modelo '{$this->medgemmaModel}' esté instalado. " .
                           "Para instalar el modelo, ejecuta en el servidor de Ollama: ollama pull {$this->medgemmaModel}";
            } elseif ($httpCode === 500) {
                $errorMsg = "Error interno en Ollama. Verifica los logs de Ollama para más detalles.";
            }
            
            return [
                'success' => false,
                'error' => $errorMsg . ($modelError && strpos($errorMsg, $modelError) === false ? ' (Detalle: ' . substr($modelError, 0, 100) . ')' : '')
            ];
        }
        
        $result = json_decode($response, true);
        
        if (!$result || !isset($result['response'])) {
            return [
                'success' => false,
                'error' => 'Respuesta inválida de Ollama: ' . $response
            ];
        }
        
        return [
            'success' => true,
            'content' => trim($result['response']),
            'model' => $this->medgemmaModel,
            'processing_time' => round($processingTime, 2),
            'prompt_used' => $prompt,
            'raw_response' => $result
        ];
    }
    
    /**
     * Construir el prompt para Medgemma
     */
    private function buildPrompt($data) {
        $patient = $data['patient'] ?? 'No especificado';
        $study = $data['study'] ?? 'No especificado';
        $transcription = $data['transcription'] ?? '';
        $template = $data['template'] ?? '';
        
        // Si hay un prompt personalizado configurado, usarlo
        if (!empty($this->customPrompt)) {
            // Reemplazar placeholders en el prompt personalizado
            $prompt = $this->customPrompt;
            $prompt = str_replace('{patient}', $patient, $prompt);
            $prompt = str_replace('{study}', $study, $prompt);
            $prompt = str_replace('{transcription}', $transcription, $prompt);
            $prompt = str_replace('{template}', $template ?: 'Sin plantilla', $prompt);
            
            // También soportar formato con mayúsculas
            $prompt = str_replace('{PATIENT}', $patient, $prompt);
            $prompt = str_replace('{STUDY}', $study, $prompt);
            $prompt = str_replace('{TRANSCRIPTION}', $transcription, $prompt);
            $prompt = str_replace('{TEMPLATE}', $template ?: 'Sin plantilla', $prompt);
            
            return $prompt;
        }
        
        // Prompt por defecto si no hay prompt personalizado
        $templateSection = '';
        if (!empty($template)) {
            $templateSection = "\n\nPLANTILLA DE REFERENCIA:\n" . $template;
        }
        
        $prompt = "ROL: Radiólogo.\n\n";
        $prompt .= "PACIENTE: {$patient}\n";
        $prompt .= "ESTUDIO: {$study}\n";
        $prompt .= "TRANSCRIPCIÓN: {$transcription}\n";
        $prompt .= $templateSection;
        $prompt .= "\n\nGenera un informe médico completo y profesional listo para firmar, basado en la transcripción proporcionada. ";
        $prompt .= "El informe debe seguir el formato médico estándar con secciones de TÉCNICA, HALLAZGOS e IMPRESIÓN. ";
        $prompt .= "Asegúrate de que el informe sea claro, preciso y profesional.";
        
        return $prompt;
    }
    
    /**
     * Verificar conexión con Ollama
     * 
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection() {
        $url = $this->baseUrl . '/api/tags';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'Error de conexión: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'message' => 'Error HTTP ' . $httpCode
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Conexión exitosa con Ollama'
        ];
    }
    
    /**
     * Listar modelos disponibles en Ollama
     * 
     * @return array ['success' => bool, 'models' => array, 'error' => string]
     */
    public function listModels() {
        $url = $this->baseUrl . '/api/tags';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'Error de conexión: ' . $error,
                'models' => []
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'Error HTTP ' . $httpCode,
                'models' => []
            ];
        }
        
        $result = json_decode($response, true);
        
        if (!$result || !isset($result['models'])) {
            return [
                'success' => false,
                'error' => 'Respuesta inválida de Ollama',
                'models' => []
            ];
        }
        
        // Extraer nombres de modelos (mantener el nombre completo con tag)
        $models = [];
        foreach ($result['models'] as $model) {
            // Usar 'name' o 'model', ambos contienen el nombre completo con tag
            $modelName = $model['name'] ?? $model['model'] ?? '';
            
            // Mantener el nombre completo con tag (ej: "alibayram/medgemma:27b")
            // Ollama necesita el nombre completo para usar el modelo
            if (!empty($modelName) && !in_array($modelName, $models)) {
                $models[] = $modelName;
            }
        }
        
        // Ordenar alfabéticamente
        sort($models);
        
        return [
            'success' => true,
            'models' => $models,
            'raw_response' => $result
        ];
    }
}
