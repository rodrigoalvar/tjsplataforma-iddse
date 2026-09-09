<?php
/**
 * Cliente para Evolution API
 * Módulo reutilizable para enviar mensajes de WhatsApp usando Evolution API
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

if (!class_exists('EvolutionAPI')) {
    class EvolutionAPI {
        private $baseUrl;
        private $instanceName;
        private $apiKey;
        private $username;
        private $password;
        private $timeout;
        
        /**
         * Constructor
         * @param array $config Configuración de la API
         *   - base_url: URL base del servidor Evolution API (ej: http://192.168.10.155:9080)
         *   - instance_name: Nombre de la instancia (ej: micel)
         *   - api_key: Clave API (opcional, si requiere autenticación)
         *   - username: Usuario para autenticación básica (opcional)
         *   - password: Contraseña para autenticación básica (opcional)
         *   - timeout: Timeout en segundos (por defecto: 30)
         */
        public function __construct($config = []) {
            $this->baseUrl = rtrim($config['base_url'] ?? 'http://192.168.10.155:9080', '/');
            $this->instanceName = $config['instance_name'] ?? 'micel';
            $this->apiKey = $config['api_key'] ?? '';
            $this->username = $config['username'] ?? '';
            $this->password = $config['password'] ?? '';
            $this->timeout = $config['timeout'] ?? 30;
        }
        
        /**
         * Formatea un número de teléfono para WhatsApp
         * @param string $phone Número de teléfono
         * @return string Número formateado (ej: 5491123456789@s.whatsapp.net)
         */
        public function formatPhoneNumber($phone) {
            // Eliminar espacios, guiones, paréntesis y otros caracteres
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            // Si está vacío, retornar como está
            if (empty($phone)) {
                return $phone;
            }
            
            // Si ya tiene el sufijo @s.whatsapp.net, retornar tal cual
            if (strpos($phone, '@s.whatsapp.net') !== false) {
                return $phone;
            }
            
            // Agregar sufijo de WhatsApp
            return $phone . '@s.whatsapp.net';
        }
        
        /**
         * Realiza una petición HTTP a la Evolution API
         * @param string $endpoint Endpoint relativo (ej: /message/sendText/micel)
         * @param string $method Método HTTP (GET, POST, PUT, DELETE)
         * @param array|null $data Datos a enviar en el body
         * @return array Respuesta de la API
         * @throws Exception Si hay un error en la petición
         */
        private function makeRequest($endpoint, $method = 'GET', $data = null) {
            $url = $this->baseUrl . $endpoint;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            
            $headers = ['Content-Type: application/json'];
            
            // Agregar API Key si está configurada (header apikey)
            // Evolution API puede requerir autenticación con header 'apikey' o 'Authorization'
            if (!empty($this->apiKey)) {
                $headers[] = 'apikey: ' . $this->apiKey;
                // También intentar con Authorization Bearer si es necesario
                // $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            }
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            // Si hay credenciales (usuario/password), usar autenticación básica
            if (!empty($this->username) && !empty($this->password)) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
            }
            
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
            $error = curl_error($ch);
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            
            if ($error) {
                throw new Exception('Error de conexión: ' . $error);
            }
            
            $decodedResponse = json_decode($response, true);
            
            if ($httpCode >= 400) {
                $errorMessage = 'Error HTTP: ' . $httpCode;
                
                // Obtener más detalles del error si están disponibles
                if (isset($decodedResponse['message'])) {
                    $errorMessage = $decodedResponse['message'];
                } elseif (isset($decodedResponse['error'])) {
                    $errorMessage = $decodedResponse['error'];
                } elseif (isset($decodedResponse['text'])) {
                    $errorMessage = $decodedResponse['text'];
                } elseif (!empty($response)) {
                    // Si la respuesta no es JSON, mostrar los primeros caracteres
                    $errorMessage = 'Error HTTP: ' . $httpCode . ' - ' . substr($response, 0, 500);
                }
                
                // Log detallado para debugging
                error_log('Evolution API Error:');
                error_log('URL: ' . $url);
                error_log('Method: ' . $method);
                error_log('HTTP Code: ' . $httpCode);
                error_log('Response (raw): ' . $response);
                error_log('Response (decoded): ' . json_encode($decodedResponse));
                if ($data) {
                    error_log('Data sent: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
                }
                if (isset($headers)) {
                    error_log('Headers: ' . json_encode($headers));
                }
                
                // Incluir más detalles en el mensaje de error para debugging
                $detailedError = $errorMessage;
                if (!empty($response) && strlen($response) < 1000) {
                    // Incluir respuesta completa si es corta
                    $detailedError .= ' | Respuesta: ' . $response;
                } elseif (!empty($response)) {
                    // Si es larga, incluir primeros caracteres
                    $detailedError .= ' | Respuesta (primeros 500 chars): ' . substr($response, 0, 500);
                }
                
                throw new Exception($detailedError);
            }
            
            return $decodedResponse ?: ['success' => true, 'response' => $response];
        }
        
        /**
         * Envía un mensaje de texto
         * @param string $to Número de destino (formato: 5491123456789 o 5491123456789@s.whatsapp.net)
         * @param string $message Mensaje a enviar
         * @return array Respuesta de la API
         * @throws Exception Si hay un error
         */
        public function sendTextMessage($to, $message) {
            // Limpiar y formatear número: solo números, sin espacios ni caracteres especiales
            $phone = preg_replace('/[^0-9]/', '', $to);
            
            // Remover sufijo @s.whatsapp.net si existe
            $phone = str_replace('@s.whatsapp.net', '', $phone);
            
            if (empty($phone)) {
                throw new Exception('Número de teléfono inválido');
            }
            
            // Si el número no empieza con código de país, agregar código de Argentina (54)
            // Números argentinos típicamente tienen 10 dígitos sin código de país
            // Formato esperado: 5491123456789 (54 = código país, 9 = móvil, 1123456789 = número)
            $phoneLength = strlen($phone);
            $startsWith54 = substr($phone, 0, 2) === '54';
            
            if ($phoneLength == 10 && !$startsWith54) {
                // Es un número argentino sin código de país, agregar 54
                $phone = '54' . $phone;
                error_log('Número sin código de país detectado, agregando código 54: ' . $phone);
            } elseif ($phoneLength == 11 && substr($phone, 0, 1) === '9' && !$startsWith54) {
                // Número que empieza con 9 pero sin código de país (ej: 91123456789)
                $phone = '54' . $phone;
                error_log('Número sin código de país detectado, agregando código 54: ' . $phone);
            } elseif ($phoneLength < 10) {
                throw new Exception('El número de teléfono parece inválido (muy corto). Debe tener al menos 10 dígitos. Número recibido: ' . $to);
            }
            
            // Evolution API espera el formato según el ejemplo proporcionado:
            // { "to": "5491123456789@s.whatsapp.net", "text": "mensaje" }
            $data = [
                'to' => $phone . '@s.whatsapp.net',
                'text' => $message
            ];
            
            $endpoint = "/message/sendText/{$this->instanceName}";
            
            // Log para debugging
            error_log('Evolution API - Enviando mensaje:');
            error_log('Endpoint: ' . $endpoint);
            error_log('Number (original): ' . $to);
            error_log('Number (cleaned): ' . $phone);
            error_log('Number (for API): ' . $data['to']);
            error_log('Message length: ' . strlen($message));
            
            return $this->makeRequest($endpoint, 'POST', $data);
        }
        
        /**
         * Verifica el estado de la instancia
         * @return array Estado de la instancia
         * @throws Exception Si hay un error
         */
        public function getInstanceStatus() {
            $endpoint = "/instance/connectionState/{$this->instanceName}";
            return $this->makeRequest($endpoint, 'GET');
        }
        
        /**
         * Obtiene información de la instancia
         * @return array Información de la instancia
         * @throws Exception Si hay un error
         */
        public function getInstanceInfo() {
            $endpoint = "/instance/fetchInstances";
            $instances = $this->makeRequest($endpoint, 'GET');
            
            // Buscar nuestra instancia
            if (isset($instances[$this->instanceName])) {
                return $instances[$this->instanceName];
            }
            
            return null;
        }
    }
}

