<?php
/**
 * Cliente para WAHA (WhatsApp HTTP API)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * @package WhatsAppModule
 * @version 1.0.0
 */

if (!class_exists('WAHAAPI')) {
    class WAHAAPI {
        private $baseUrl;
        private $apiKey;
        private $timeout;
        private $sessionName;
        
        /**
         * Constructor
         * @param array $config Configuración de WAHA
         *   - base_url: URL base del servidor WAHA (ej: http://localhost:3000)
         *   - api_key: Clave API (opcional)
         *   - timeout: Timeout en segundos (por defecto: 30)
         *   - session_name: Nombre de la sesión a usar
         */
        public function __construct($config = []) {
            $this->baseUrl = rtrim($config['base_url'] ?? 'http://localhost:3000', '/');
            $this->apiKey = $config['api_key'] ?? '';
            $this->timeout = $config['timeout'] ?? 30;
            $this->sessionName = $config['session_name'] ?? 'default';
        }
        
        /**
         * Formatea un número de teléfono para WAHA
         * @param string $phone Número de teléfono
         * @return string Número formateado (ej: 5491123456789@c.us)
         */
        public function formatPhoneNumber($phone) {
            // Limpiar: solo números
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            if (empty($phone)) {
                return $phone;
            }
            
            // Si ya tiene sufijo, removerlo primero para procesar
            if (strpos($phone, '@c.us') !== false) {
                $phone = str_replace('@c.us', '', $phone);
            } elseif (strpos($phone, '@s.whatsapp.net') !== false) {
                $phone = str_replace('@s.whatsapp.net', '', $phone);
            }
            
            $phoneLength = strlen($phone);
            
            // Verificar si ya tiene código de país
            $hasCountryCode = false;
            $detectedCode = null;
            
            // Códigos de país comunes (ordenados de más largo a más corto para detectar correctamente)
            // Incluye códigos de América Latina, Europa y otros comunes
            $countryCodes = [
                // Códigos de 3 dígitos (más largos primero)
                '351', '598', '595', '591', '593', '502', '503', '504', '505', '506', '507', '508', '509',
                // Códigos de 2 dígitos
                '549', '54', '55', '56', '57', '52', '58', '51', '34', '39', '33', '49', '44', '1',
                // Códigos de 1 dígito
                '7' // Rusia/Kazajstán
            ];
            
            foreach ($countryCodes as $code) {
                if (substr($phone, 0, strlen($code)) === $code) {
                    $hasCountryCode = true;
                    $detectedCode = $code;
                    
                    // Si es Argentina (54) y no tiene el 9 siguiente, agregarlo para formar 549
                    if ($code === '54' && substr($phone, 0, 3) !== '549') {
                        // Tiene 54 pero no 549, agregar el 9 del móvil
                        $phone = '549' . substr($phone, 2);
                    }
                    break;
                }
            }
            
            // Si no tiene código de país detectado, verificar si podría tener uno
            // Si el número tiene más de 10 dígitos, probablemente ya tiene código de país
            if (!$hasCountryCode) {
                $phoneLength = strlen($phone);
                
                // Si tiene más de 10 dígitos, probablemente ya incluye código de país
                // No asumir Argentina automáticamente
                if ($phoneLength > 10) {
                    // El número parece tener código de país pero no fue reconocido
                    // Dejar el número como está y dejar que WAHA lo maneje
                    error_log('WAHA formatPhoneNumber: Número con posible código de país no reconocido: ' . $phone);
                } elseif ($phoneLength >= 10) {
                    // Número de 10 dígitos, podría ser argentino sin código
                    // Solo asumir Argentina si empieza con 9 (móvil argentino)
                    if (substr($phone, 0, 1) === '9') {
                        $phone = '549' . $phone;
                        error_log('WAHA formatPhoneNumber: Asumiendo número argentino, agregado 549: ' . $phone);
                    } else {
                        // Número de 10 dígitos que no empieza con 9, no asumir código de país
                        error_log('WAHA formatPhoneNumber: Número de 10 dígitos sin código de país reconocido: ' . $phone);
                    }
                } else {
                    // Número muy corto, probablemente inválido
                    error_log('WAHA formatPhoneNumber: Número muy corto, posiblemente inválido: ' . $phone);
                }
            } else {
                error_log('WAHA formatPhoneNumber: Código de país detectado (' . $detectedCode . '): ' . $phone);
            }
            
            // Formato WAHA: {number}@c.us
            return $phone . '@c.us';
        }
        
        /**
         * Realiza una petición HTTP a WAHA
         * Según documentación WAHA: todas las peticiones deben incluir X-Api-Key en el header
         * @param string $endpoint Endpoint relativo
         * @param string $method Método HTTP
         * @param array|null $data Datos a enviar
         * @param array|null $additionalHeaders Headers adicionales (ej: ['Accept: application/json'])
         * @return array Respuesta de WAHA
         * @throws Exception Si hay un error
         */
        private function makeRequest($endpoint, $method = 'GET', $data = null, $additionalHeaders = null) {
            $url = $this->baseUrl . $endpoint;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            
            $headers = ['Content-Type: application/json'];
            
            // Agregar headers adicionales si se proporcionan
            if (is_array($additionalHeaders)) {
                $headers = array_merge($headers, $additionalHeaders);
            }
            
            // Agregar autenticación si está configurada
            // Según documentación WAHA: "tu backend en PHP hace las llamadas HTTP firmadas con X-Api-Key"
            if (!empty($this->apiKey)) {
                $headers[] = 'X-Api-Key: ' . $this->apiKey;
                error_log('WAHA API - Enviando X-Api-Key header');
            } else {
                error_log('WAHA API - ADVERTENCIA: No se configuró API Key. WAHA puede requerir autenticación.');
            }
            
            // Log de headers para debugging
            error_log('WAHA API - Headers enviados: ' . json_encode($headers));
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            if ($method === 'POST' && $data !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } elseif ($method === 'PUT' && $data !== null) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Log de respuesta para debugging
            error_log('WAHA API - HTTP Code: ' . $httpCode);
            error_log('WAHA API - Content-Type: ' . ($contentType ?: 'no especificado'));
            error_log('WAHA API - Response size: ' . strlen($response) . ' bytes');
            
            if ($error) {
                error_log('WAHA API cURL Error: ' . $error);
                error_log('WAHA API URL: ' . $url);
                throw new Exception('Error de conexión: ' . $error);
            }
            
            // Si no hay respuesta y el código HTTP es 0, probablemente no se pudo conectar
            if (empty($response) && $httpCode == 0) {
                error_log('WAHA API: No se pudo conectar al servidor');
                error_log('WAHA API URL: ' . $url);
                throw new Exception('No se pudo conectar al servidor WAHA. Verifica que esté corriendo en ' . $this->baseUrl);
            }
            
            // Si la respuesta es una imagen PNG (content-type: image/png), convertirla a base64
            // Según Swagger: GET /api/{session}/auth/qr?format=image retorna image/png
            if ($httpCode == 200 && $contentType && strpos($contentType, 'image/png') !== false) {
                error_log('WAHA API: Respuesta es imagen PNG, convirtiendo a base64');
                error_log('WAHA API: Tamaño de imagen PNG recibida: ' . strlen($response) . ' bytes');
                
                // Verificar que la respuesta no esté vacía
                if (empty($response)) {
                    error_log('WAHA API: ERROR - Respuesta PNG está vacía');
                    throw new Exception('La respuesta de WAHA está vacía. El QR puede haber expirado o la sesión puede tener problemas.');
                }
                
                // Convertir imagen PNG a base64 para enviar al frontend
                $base64Image = base64_encode($response);
                $base64Size = strlen($base64Image);
                error_log('WAHA API: Tamaño de base64 generado: ' . $base64Size . ' bytes');
                
                // Verificar que el base64 sea razonable (un QR code típico en base64 debería ser de al menos 6000+ bytes)
                if ($base64Size < 1000) {
                    error_log('WAHA API: ADVERTENCIA - Base64 muy pequeño (' . $base64Size . ' bytes). El QR puede estar incompleto o corrupto.');
                }
                
                $qrData = [
                    'qr' => 'data:image/png;base64,' . $base64Image,
                    'format' => 'png'
                ];
                
                error_log('WAHA API: QR data preparado, tamaño total: ' . strlen($qrData['qr']) . ' bytes');
                
                return $qrData;
            }
            
            $decodedResponse = json_decode($response, true);
            
            // Si la respuesta no es JSON válido pero el código HTTP es 200, intentar parsear como array
            if ($decodedResponse === null && $httpCode == 200 && !empty($response)) {
                error_log('WAHA API: Respuesta no es JSON válido: ' . substr($response, 0, 200));
                // Intentar retornar la respuesta como texto
                return ['success' => true, 'response' => $response];
            }
            
            if ($httpCode >= 400) {
                $errorMessage = 'Error HTTP: ' . $httpCode;
                
                // Extraer mensaje de error de diferentes estructuras de respuesta de WAHA
                if (isset($decodedResponse['exception']['message'])) {
                    $errorMessage = $decodedResponse['exception']['message'];
                } elseif (isset($decodedResponse['error'])) {
                    $errorMessage = $decodedResponse['error'];
                } elseif (isset($decodedResponse['message'])) {
                    $errorMessage = $decodedResponse['message'];
                } elseif (!empty($response)) {
                    $errorMessage .= ' - ' . substr($response, 0, 200);
                }
                
                // Manejar específicamente el error 401 (Unauthorized)
                if ($httpCode == 401) {
                    error_log('WAHA API - Error 401 (Unauthorized):');
                    error_log('WAHA API - URL: ' . $url);
                    error_log('WAHA API - API Key configurada: ' . (!empty($this->apiKey) ? 'SÍ (' . substr($this->apiKey, 0, 10) . '...)' : 'NO'));
                    error_log('WAHA API - Response completa: ' . $response);
                    $errorMessage = 'Error de autenticación (401). Verifica que la API Key sea correcta y que WAHA esté configurado para aceptarla. Mensaje: ' . ($decodedResponse['message'] ?? 'Unauthorized');
                }
                
                // Incluir la respuesta completa en el error para debugging
                $fullError = 'Error HTTP: ' . $httpCode . ' - ' . substr($response, 0, 500);
                
                error_log('WAHA API Error: ' . $errorMessage);
                error_log('WAHA API URL: ' . $url);
                error_log('WAHA API Response: ' . substr($response, 0, 500));
                
                throw new Exception($fullError);
            }
            
            // Si la respuesta es un array vacío o null, retornar estructura estándar
            if ($decodedResponse === null && empty($response)) {
                return [];
            }
            
            return $decodedResponse ?: ['success' => true, 'response' => $response];
        }
        
        /**
         * Crea una nueva sesión
         * @param string $sessionName Nombre de la sesión
         * @param array $config Configuración adicional de la sesión
         * @return array Respuesta de WAHA
         */
        public function createSession($sessionName = null, $config = []) {
            $session = $sessionName ?? $this->sessionName;
            $endpoint = '/api/sessions';
            
            $data = array_merge([
                'name' => $session,
                'config' => [
                    'proxy' => $config['proxy'] ?? null,
                    'webhook' => $config['webhook'] ?? null
                ]
            ], $config);
            
            return $this->makeRequest($endpoint, 'POST', $data);
        }
        
        /**
         * Obtiene el QR code de una sesión para autenticación
         * @param string $sessionName Nombre de la sesión
         * @return array Respuesta con QR code
         */
        /**
         * Obtiene el QR code de una sesión para autenticación
         * Según documentación WAHA: GET /api/{session}/auth/qr?format=image
         * Con Accept: image/png para obtener imagen PNG directamente
         * @param string $sessionName Nombre de la sesión
         * @param string $format Formato de respuesta: 'image' (PNG binario) o 'json' (base64 en JSON)
         * @return array Respuesta con QR code
         */
        public function getQRCode($sessionName = null, $format = 'image') {
            $session = $sessionName ?? $this->sessionName;
            
            // Según Swagger de WAHA: /api/{session}/auth/qr?format=image
            // Nota: El endpoint es /api/{session}/auth/qr, NO /api/sessions/{session}/auth/qr
            $endpoint = "/api/{$session}/auth/qr";
            
            // Agregar query parameter format según Swagger
            if ($format === 'image') {
                $endpoint .= '?format=image';
            } elseif ($format === 'json') {
                $endpoint .= '?format=json';
            }
            
            // Log para verificar que se usa la URL base correcta
            error_log('WAHA - Obteniendo QR code:');
            error_log('Base URL (configurada): ' . $this->baseUrl);
            error_log('Session: ' . $session);
            error_log('Format: ' . $format);
            error_log('Endpoint: ' . $endpoint);
            error_log('Full URL: ' . $this->baseUrl . $endpoint);
            
            // Según Swagger: usar Accept: image/png para obtener imagen PNG directamente
            // O Accept: application/json para obtener base64 en JSON
            $acceptHeader = $format === 'image' ? 'Accept: image/png' : 'Accept: application/json';
            
            return $this->makeRequest($endpoint, 'GET', null, [$acceptHeader]);
        }
        
        /**
         * Obtiene el estado de una sesión
         * @param string $sessionName Nombre de la sesión
         * @return array Estado de la sesión
         */
        public function getSessionStatus($sessionName = null) {
            $session = $sessionName ?? $this->sessionName;
            $endpoint = "/api/sessions/{$session}";
            
            return $this->makeRequest($endpoint, 'GET');
        }
        
        /**
         * Lista todas las sesiones
         * @return array Lista de sesiones
         */
        public function listSessions() {
            $endpoint = '/api/sessions';
            return $this->makeRequest($endpoint, 'GET');
        }
        
        /**
         * Elimina una sesión
         * @param string $sessionName Nombre de la sesión
         * @return array Respuesta de WAHA
         */
        public function deleteSession($sessionName = null) {
            $session = $sessionName ?? $this->sessionName;
            $endpoint = "/api/sessions/{$session}";
            
            return $this->makeRequest($endpoint, 'DELETE');
        }
        
        /**
         * Marca un mensaje como visto (sendSeen)
         * @param string $chatId ID del chat
         * @param string $sessionName Nombre de la sesión
         * @return array Respuesta de WAHA
         */
        private function sendSeen($chatId, $sessionName = null) {
            $session = $sessionName ?? $this->sessionName;
            $endpoint = "/api/sendSeen";
            
            $data = [
                'chatId' => $chatId,
                'session' => $session
            ];
            
            try {
                error_log('WAHA - Enviando sendSeen para: ' . $chatId);
                $result = $this->makeRequest($endpoint, 'POST', $data);
                error_log('WAHA - sendSeen exitoso');
                return $result;
            } catch (Exception $e) {
                // No lanzar excepción, solo loggear el error
                // Si falla sendSeen, continuar con el proceso
                error_log('WAHA - Error en sendSeen (continuando): ' . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        /**
         * Inicia el indicador de "escribiendo" (startTyping)
         * @param string $chatId ID del chat
         * @param string $sessionName Nombre de la sesión
         * @return array Respuesta de WAHA
         */
        private function startTyping($chatId, $sessionName = null) {
            $session = $sessionName ?? $this->sessionName;
            $endpoint = "/api/startTyping";
            
            $data = [
                'chatId' => $chatId,
                'session' => $session
            ];
            
            try {
                error_log('WAHA - Iniciando typing para: ' . $chatId);
                $result = $this->makeRequest($endpoint, 'POST', $data);
                error_log('WAHA - startTyping exitoso');
                return $result;
            } catch (Exception $e) {
                // No lanzar excepción, solo loggear el error
                error_log('WAHA - Error en startTyping (continuando): ' . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        /**
         * Detiene el indicador de "escribiendo" (stopTyping)
         * @param string $chatId ID del chat
         * @param string $sessionName Nombre de la sesión
         * @return array Respuesta de WAHA
         */
        private function stopTyping($chatId, $sessionName = null) {
            $session = $sessionName ?? $this->sessionName;
            $endpoint = "/api/stopTyping";
            
            $data = [
                'chatId' => $chatId,
                'session' => $session
            ];
            
            try {
                error_log('WAHA - Deteniendo typing para: ' . $chatId);
                $result = $this->makeRequest($endpoint, 'POST', $data);
                error_log('WAHA - stopTyping exitoso');
                return $result;
            } catch (Exception $e) {
                // No lanzar excepción, solo loggear el error
                error_log('WAHA - Error en stopTyping (continuando): ' . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        /**
         * Calcula el tiempo de espera aleatorio basado en el tamaño del mensaje
         * @param string $message Mensaje
         * @return int Tiempo en segundos (mínimo 1, máximo 5)
         */
        private function calculateTypingDelay($message) {
            $messageLength = strlen($message);
            
            // Base: 1 segundo por cada 50 caracteres
            // Mínimo: 1 segundo, Máximo: 5 segundos
            $baseDelay = max(1, min(5, ceil($messageLength / 50)));
            
            // Agregar variación aleatoria de ±0.5 segundos
            $randomVariation = (mt_rand(-5, 5) / 10);
            $delay = max(1, $baseDelay + $randomVariation);
            
            error_log('WAHA - Tiempo de typing calculado: ' . $delay . ' segundos (mensaje: ' . $messageLength . ' caracteres)');
            
            return $delay;
        }
        
        /**
         * Envía un mensaje de texto siguiendo el flujo recomendado para evitar spam
         * Flujo: sendSeen -> startTyping -> esperar -> stopTyping -> sendText
         * @param string $to Número de destino
         * @param string $message Mensaje a enviar
         * @param string $sessionName Nombre de la sesión a usar
         * @return array Respuesta de WAHA
         */
        public function sendTextMessage($to, $message, $sessionName = null) {
            $session = $sessionName ?? $this->sessionName;
            $phone = $this->formatPhoneNumber($to);
            
            // Log para debugging
            error_log('WAHA - Iniciando envío de mensaje con flujo anti-spam:');
            error_log('Base URL (configurada): ' . $this->baseUrl);
            error_log('Session: ' . $session);
            error_log('Phone: ' . $phone);
            error_log('Message length: ' . strlen($message));
            
            try {
                // Paso 1: Enviar seen antes de procesar el mensaje
                error_log('WAHA - Paso 1: Enviando sendSeen...');
                $this->sendSeen($phone, $session);
                
                // Pequeña pausa después de sendSeen
                usleep(200000); // 0.2 segundos
                
                // Paso 2: Iniciar typing
                error_log('WAHA - Paso 2: Iniciando typing...');
                $this->startTyping($phone, $session);
                
                // Paso 3: Esperar un intervalo aleatorio según el tamaño del mensaje
                $typingDelay = $this->calculateTypingDelay($message);
                error_log('WAHA - Paso 3: Esperando ' . $typingDelay . ' segundos antes de enviar...');
                sleep($typingDelay);
                
                // Paso 4: Detener typing
                error_log('WAHA - Paso 4: Deteniendo typing...');
                $this->stopTyping($phone, $session);
                
                // Pequeña pausa después de stopTyping
                usleep(300000); // 0.3 segundos
                
                // Paso 5: Enviar el mensaje de texto
                error_log('WAHA - Paso 5: Enviando mensaje de texto...');
                $endpoint = "/api/sendText";
                
                $data = [
                    'chatId' => $phone,
                    'text' => $message,
                    'session' => $session
                ];
                
                error_log('WAHA - Endpoint: ' . $endpoint);
                error_log('WAHA - Full URL: ' . $this->baseUrl . $endpoint);
                error_log('WAHA - Data: ' . json_encode($data));
                
                $result = $this->makeRequest($endpoint, 'POST', $data);
                
                // WAHA retorna un objeto con id si se envía correctamente
                // Verificar si tiene id o si el status es ok
                $success = false;
                if (isset($result['id'])) {
                    $success = true;
                } elseif (isset($result['status']) && $result['status'] === 'ok') {
                    $success = true;
                } elseif (isset($result['_data']) && isset($result['_data']['id'])) {
                    $success = true;
                }
                
                error_log('WAHA - Mensaje enviado exitosamente: ' . ($success ? 'SÍ' : 'NO'));
                
                return [
                    'success' => $success,
                    'data' => $result,
                    'message_id' => $result['id'] ?? ($result['_data']['id'] ?? null)
                ];
            } catch (Exception $e) {
                error_log('WAHA sendTextMessage Error: ' . $e->getMessage());
                error_log('WAHA sendTextMessage URL: ' . $this->baseUrl . $endpoint);
                
                // Intentar detener typing en caso de error
                try {
                    $this->stopTyping($phone, $session);
                } catch (Exception $stopTypingError) {
                    error_log('WAHA - Error al detener typing después de error: ' . $stopTypingError->getMessage());
                }
                
                throw $e;
            }
        }
        
        /**
         * Verifica si un número está registrado en WhatsApp
         * @param string $phone Número de teléfono a verificar
         * @param string $sessionName Nombre de la sesión
         * @return array Resultado de la verificación
         */
        public function checkNumber($phone, $sessionName = null) {
            $session = $sessionName ?? $this->sessionName;
            $phoneFormatted = $this->formatPhoneNumber($phone);
            
            // Remover @c.us para la verificación
            $phoneClean = str_replace('@c.us', '', $phoneFormatted);
            
            $endpoint = "/api/checkNumber";
            
            $data = [
                'phone' => $phoneClean,
                'session' => $session
            ];
            
            try {
                return $this->makeRequest($endpoint, 'POST', $data);
            } catch (Exception $e) {
                error_log('WAHA checkNumber Error: ' . $e->getMessage());
                return ['exists' => false, 'error' => $e->getMessage()];
            }
        }
        
        /**
         * Verifica si una sesión está autenticada
         * @param string $sessionName Nombre de la sesión
         * @return bool True si está autenticada
         */
        public function isSessionAuthenticated($sessionName = null) {
            try {
                $status = $this->getSessionStatus($sessionName);
                
                // WAHA puede retornar diferentes estructuras según versión
                // Intentar obtener el estado de diferentes formas
                $state = null;
                
                // Formato 1: status directo
                if (isset($status['status'])) {
                    $state = $status['status'];
                }
                // Formato 2: state directo
                elseif (isset($status['state'])) {
                    $state = $status['state'];
                }
                // Formato 3: dentro de un objeto status
                elseif (isset($status['status']['state'])) {
                    $state = $status['status']['state'];
                }
                // Formato 4: dentro de un objeto state
                elseif (isset($status['state']['state'])) {
                    $state = $status['state']['state'];
                }
                // Formato 5: si es un array con un solo elemento
                elseif (is_array($status) && count($status) === 1 && isset($status[0]['state'])) {
                    $state = $status[0]['state'];
                }
                
                if ($state === null) {
                    error_log('WAHA: No se pudo determinar el estado de la sesión. Respuesta: ' . json_encode($status));
                    return false;
                }
                
                $stateLower = strtolower($state);
                $authenticated = in_array($stateLower, ['ready', 'authenticated', 'open', 'connected']);
                
                error_log('WAHA: Estado de sesión ' . ($sessionName ?? $this->sessionName) . ': ' . $state . ' (autenticada: ' . ($authenticated ? 'sí' : 'no') . ')');
                
                return $authenticated;
            } catch (Exception $e) {
                error_log('Error verificando estado de sesión: ' . $e->getMessage());
                return false;
            }
        }
    }
}
