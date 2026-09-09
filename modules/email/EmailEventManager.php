<?php
/**
 * EmailEventManager - Gestor de Eventos Automáticos de Email
 * 
 * Maneja el envío automático de emails basado en eventos del sistema.
 * Permite configurar eventos y sus plantillas asociadas.
 * 
 * @package EmailModule
 * @version 1.0
 */

if (!class_exists('EmailEventManager')) {
    class EmailEventManager {
        private $eventsConfig = [];
        private $eventsFile;
        private $emailService;
        private $template;
        
        /**
         * Constructor
         * @param EmailService|null $emailService Instancia de EmailService
         * @param string|null $eventsFile Ruta al archivo de configuración de eventos
         */
        public function __construct($emailService = null, $eventsFile = null) {
            if ($eventsFile === null) {
                $eventsFile = __DIR__ . '/config/email_events.php';
            }
            $this->eventsFile = $eventsFile;
            $this->loadEvents();
            
            // Si no se proporciona EmailService, crear uno con configuración por defecto
            if ($emailService === null) {
                $config = EmailConfig::load();
                $this->emailService = new EmailService($config);
            } else {
                $this->emailService = $emailService;
            }
            
            $this->template = new EmailTemplate();
        }
        
        /**
         * Verificar permiso de envío de email
         * @return bool True si tiene permiso
         */
        private function checkEmailSendPermission() {
            // Para eventos automáticos, verificar si hay una sesión activa
            // Si no hay sesión (eventos automáticos del sistema), permitir por defecto
            // pero registrar que se está enviando sin verificación de usuario
            if (!isset($_SESSION) || session_status() === PHP_SESSION_NONE) {
                // Evento automático del sistema - permitir pero registrar
                $this->log('info', 'Evento automático: envío sin verificación de usuario');
                return true; // Los eventos automáticos del sistema pueden enviar
            }
            
            // Si hay sesión, verificar permisos del usuario
            try {
                require_once __DIR__ . '/../../config/database.php';
                $pdo = getDBConnection();
                
                if (!$pdo) {
                    return false;
                }
                
                $token = $_COOKIE['session_token'] ?? null;
                $user = null;
                
                if ($token) {
                    $query = "SELECT u.id, u.nivel, u.permisos, u.activo 
                              FROM usuarios u 
                              INNER JOIN user_sessions s ON u.id = s.user_id 
                              WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([$token]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif (isset($_SESSION['user_id'])) {
                    $query = "SELECT u.id, u.nivel, u.permisos, u.activo 
                              FROM usuarios u 
                              WHERE u.id = ? AND u.activo = 1";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([$_SESSION['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if (!$user) {
                    return false;
                }
                
                // ROOT tiene todos los permisos
                if ($user['nivel'] === 'root') {
                    return true;
                }
                
                // Verificar permiso específico
                $permissions = json_decode($user['permisos'], true) ?: [];
                return in_array('envios_email', $permissions) || in_array('all', $permissions);
                
            } catch (Exception $e) {
                $this->log('error', 'Error verificando permisos: ' . $e->getMessage());
                return false;
            }
        }
        
        /**
         * Cargar configuración de eventos
         */
        private function loadEvents() {
            if (!file_exists($this->eventsFile)) {
                $this->eventsConfig = $this->getDefaultEvents();
                $this->log('warning', 'Archivo de eventos no encontrado, usando configuración por defecto');
                return;
            }
            
            $loadedEvents = include $this->eventsFile;
            
            if (!is_array($loadedEvents)) {
                $this->eventsConfig = $this->getDefaultEvents();
                $this->log('error', 'Error al cargar eventos, formato inválido');
                return;
            }
            
            $this->eventsConfig = array_merge($this->getDefaultEvents(), $loadedEvents);
        }
        
        /**
         * Obtener eventos por defecto
         * @return array Eventos por defecto
         */
        private function getDefaultEvents() {
            return [
                'usuario_registrado' => [
                    'enabled' => false,
                    'template' => 'verification',
                    'recipient' => 'user_email',
                    'subject' => 'Verifica tu cuenta - {{app_name}}',
                    'description' => 'Se dispara cuando un usuario se registra en el sistema'
                ],
                'informe_completado' => [
                    'enabled' => false,
                    'template' => 'informe-completado',
                    'recipient' => 'paciente_email',
                    'subject' => 'Tu informe está listo - {{app_name}}',
                    'description' => 'Se dispara cuando se completa un informe médico'
                ],
                'estudio_asignado' => [
                    'enabled' => false,
                    'template' => 'asignacion-estudio',
                    'recipient' => 'medico_email',
                    'subject' => 'Nuevo estudio asignado - {{app_name}}',
                    'description' => 'Se dispara cuando se asigna un estudio a un médico'
                ],
                'notificacion_generica' => [
                    'enabled' => false,
                    'template' => 'notificacion-generica',
                    'recipient' => 'email',
                    'subject' => 'Notificación - {{app_name}}',
                    'description' => 'Notificación genérica del sistema'
                ]
            ];
        }
        
        /**
         * Disparar evento
         * @param string $eventName Nombre del evento
         * @param array $data Datos del evento (debe incluir el email del destinatario)
         * @return array Resultado del envío
         */
        public function trigger($eventName, $data = []) {
            // Verificar permiso de envío de email
            if (!$this->checkEmailSendPermission()) {
                $this->log('warning', "Intento de envío de email sin permiso: {$eventName}");
                return [
                    'success' => false,
                    'message' => 'No tienes permisos para enviar emails. Se requiere el permiso "Envios por email" (envios_email).'
                ];
            }
            
            // Verificar si el evento existe
            if (!isset($this->eventsConfig[$eventName])) {
                $this->log('warning', "Evento no encontrado: {$eventName}");
                return [
                    'success' => false,
                    'message' => "Evento no encontrado: {$eventName}"
                ];
            }
            
            $event = $this->eventsConfig[$eventName];
            
            // Verificar si el evento está habilitado
            if (!($event['enabled'] ?? false)) {
                $this->log('info', "Evento deshabilitado: {$eventName}");
                return [
                    'success' => false,
                    'message' => "Evento deshabilitado: {$eventName}",
                    'skipped' => true
                ];
            }
            
            // Obtener email destinatario
            $recipientKey = $event['recipient'] ?? 'email';
            $to = $data[$recipientKey] ?? null;
            
            if (empty($to)) {
                $this->log('error', "No se encontró email destinatario para evento {$eventName} (clave: {$recipientKey})");
                return [
                    'success' => false,
                    'message' => "Email destinatario no encontrado para evento: {$eventName}"
                ];
            }
            
            // Validar email
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $this->log('error', "Email inválido para evento {$eventName}: {$to}");
                return [
                    'success' => false,
                    'message' => "Email destinatario inválido: {$to}"
                ];
            }
            
            // Obtener plantilla
            $templateName = $event['template'] ?? 'notificacion-generica';
            
            if (!$this->template->exists($templateName)) {
                $this->log('error', "Plantilla no encontrada para evento {$eventName}: {$templateName}");
                return [
                    'success' => false,
                    'message' => "Plantilla no encontrada: {$templateName}"
                ];
            }
            
            // Obtener asunto
            $subject = $event['subject'] ?? 'Notificación - {{app_name}}';
            
            // Preparar variables para la plantilla
            // Incluir todas las variables de $data más las variables del sistema
            $variables = array_merge($data, $this->template->getDefaultVariables());
            
            // Enviar email
            $result = $this->emailService->sendTemplate($templateName, $to, $subject, $variables);
            
            if ($result['success']) {
                $this->log('info', "Evento {$eventName} disparado exitosamente - Email enviado a: {$to}");
            } else {
                $this->log('error', "Error al disparar evento {$eventName}: {$result['message']}");
            }
            
            return $result;
        }
        
        /**
         * Obtener lista de eventos disponibles
         * @return array Lista de eventos
         */
        public function getEvents() {
            return $this->eventsConfig;
        }
        
        /**
         * Obtener información de un evento específico
         * @param string $eventName Nombre del evento
         * @return array|null Información del evento
         */
        public function getEvent($eventName) {
            return $this->eventsConfig[$eventName] ?? null;
        }
        
        /**
         * Habilitar evento
         * @param string $eventName Nombre del evento
         * @return bool True si se habilitó correctamente
         */
        public function enableEvent($eventName) {
            if (isset($this->eventsConfig[$eventName])) {
                $this->eventsConfig[$eventName]['enabled'] = true;
                return true;
            }
            return false;
        }
        
        /**
         * Deshabilitar evento
         * @param string $eventName Nombre del evento
         * @return bool True si se deshabilitó correctamente
         */
        public function disableEvent($eventName) {
            if (isset($this->eventsConfig[$eventName])) {
                $this->eventsConfig[$eventName]['enabled'] = false;
                return true;
            }
            return false;
        }
        
        /**
         * Registrar nuevo evento
         * @param string $eventName Nombre del evento
         * @param array $config Configuración del evento
         * @return bool True si se registró correctamente
         */
        public function registerEvent($eventName, $config) {
            $required = ['template', 'recipient', 'subject'];
            
            foreach ($required as $key) {
                if (!isset($config[$key])) {
                    $this->log('error', "Configuración de evento incompleta: falta {$key}");
                    return false;
                }
            }
            
            $this->eventsConfig[$eventName] = array_merge([
                'enabled' => false,
                'description' => ''
            ], $config);
            
            return true;
        }
        
        /**
         * Log de mensajes
         * @param string $level Nivel (info, warning, error)
         * @param string $message Mensaje
         */
        private function log($level, $message) {
            $logFile = __DIR__ . '/logs/email.log';
            $logDir = dirname($logFile);
            
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[{$timestamp}] [{$level}] [EmailEventManager] {$message}\n";
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }
}

