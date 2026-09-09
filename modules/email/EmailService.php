<?php
/**
 * EmailService - Servicio Principal de Envío de Emails
 * 
 * Clase principal para enviar emails usando PHPMailer.
 * Maneja conexión SMTP, validación, logging y manejo de errores.
 * 
 * @package EmailModule
 * @version 1.0
 */

// Verificar si PHPMailer está disponible
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    // Intentar cargar desde Composer (múltiples rutas posibles)
    $composerAutoloads = [
        __DIR__ . '/vendor/autoload.php',           // En el módulo mismo
        __DIR__ . '/../../vendor/autoload.php',     // En el proyecto principal
        __DIR__ . '/../../../vendor/autoload.php'   // Una carpeta más arriba
    ];
    
    $loaded = false;
    foreach ($composerAutoloads as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;
            if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                $loaded = true;
                break;
            }
        }
    }
    
    // Si aún no está cargado, intentar cargar directamente
    if (!$loaded) {
        $phpmailerPaths = [
            __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
            __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php'
        ];
        
        foreach ($phpmailerPaths as $path) {
            if (file_exists($path)) {
                require_once dirname($path) . '/PHPMailer.php';
                require_once dirname($path) . '/SMTP.php';
                require_once dirname($path) . '/Exception.php';
                $loaded = true;
                break;
            }
        }
    }
    
    if (!$loaded) {
        throw new Exception('PHPMailer no está instalado. Ejecuta: composer require phpmailer/phpmailer');
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!class_exists('EmailService')) {
    class EmailService {
        private $config;
        private $mailer;
        private $lastError = null;
        private $logFile;
        
        /**
         * Constructor
         * @param EmailConfig|array $config Configuración de email
         */
        public function __construct($config) {
            // Si es array, crear EmailConfig
            if (is_array($config)) {
                $emailConfig = new EmailConfig();
                $emailConfig->config = array_merge_recursive($emailConfig->defaultConfig, $config);
                $this->config = $emailConfig;
            } else {
                $this->config = $config;
            }
            
            $this->logFile = $this->config->get('options.log_file', __DIR__ . '/logs/email.log');
            $this->initializeMailer();
        }
        
        /**
         * Inicializar PHPMailer
         */
        private function initializeMailer() {
            $this->mailer = new PHPMailer(true);
            
            // Configuración SMTP
            $smtpConfig = $this->config->getSmtpConfig();
            
            $this->mailer->isSMTP();
            $this->mailer->Host = $smtpConfig['host'] ?? 'smtp.gmail.com';
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $smtpConfig['username'] ?? '';
            $this->mailer->Password = $smtpConfig['password'] ?? '';
            $this->mailer->SMTPSecure = $smtpConfig['secure'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = $smtpConfig['port'] ?? 587;
            
            // Configurar timeouts más cortos para evitar bloqueos del servidor
            $this->mailer->Timeout = 15; // Timeout de conexión: 15 segundos (reducido de 60)
            $this->mailer->SMTPKeepAlive = false; // No mantener conexión abierta
            
            // Configurar timeouts de lectura/escritura
            if (property_exists($this->mailer, 'SMTPOptions')) {
                $this->mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                        'timeout' => 10
                    ]
                ];
            }
            
            // Configuración general
            $options = $this->config->getOptions();
            $this->mailer->CharSet = $options['charset'] ?? 'UTF-8';
            $this->mailer->setLanguage('es', __DIR__ . '/vendor/phpmailer/phpmailer/language/');
            
            // From
            $this->mailer->setFrom(
                $smtpConfig['from_email'] ?? 'noreply@tjsmedical.com',
                $smtpConfig['from_name'] ?? 'TJS Medical'
            );
            
            // Debug
            if ($options['debug'] ?? false) {
                $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
                $this->mailer->Debugoutput = function($str, $level) {
                    $this->log('debug', $str);
                };
            }
        }
        
        /**
         * Enviar email
         * @param array $data Datos del email
         *   - to: Email destinatario (requerido)
         *   - subject: Asunto (requerido)
         *   - body: Cuerpo del mensaje (requerido)
         *   - body_type: 'html' o 'text' (default: 'html')
         *   - cc: Array de emails en copia
         *   - bcc: Array de emails en copia oculta
         *   - reply_to: Email para respuesta
         *   - attachments: Array de archivos adjuntos
         * @return array ['success' => bool, 'message' => string, 'message_id' => string|null]
         */
        public function send($data) {
            try {
                // Validar datos requeridos
                if (empty($data['to'])) {
                    throw new Exception('Email destinatario (to) es requerido');
                }
                
                if (empty($data['subject'])) {
                    throw new Exception('Asunto (subject) es requerido');
                }
                
                if (empty($data['body'])) {
                    throw new Exception('Cuerpo del mensaje (body) es requerido');
                }
                
                // Validar email destinatario
                if (!filter_var($data['to'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Email destinatario inválido: ' . $data['to']);
                }
                
                // Limpiar mailer para nuevo envío
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->clearCustomHeaders();
                $this->mailer->clearReplyTos();
                
                // Destinatario
                $this->mailer->addAddress($data['to']);
                
                // Asunto y cuerpo
                $this->mailer->Subject = $data['subject'];
                $bodyType = $data['body_type'] ?? 'html';
                
                if ($bodyType === 'html') {
                    $this->mailer->isHTML(true);
                    $this->mailer->Body = $data['body'];
                    // Crear versión texto plano automáticamente
                    $this->mailer->AltBody = strip_tags($data['body']);
                } else {
                    $this->mailer->isHTML(false);
                    $this->mailer->Body = $data['body'];
                }
                
                // CC
                if (!empty($data['cc']) && is_array($data['cc'])) {
                    foreach ($data['cc'] as $cc) {
                        if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                            $this->mailer->addCC($cc);
                        }
                    }
                }
                
                // BCC
                if (!empty($data['bcc']) && is_array($data['bcc'])) {
                    foreach ($data['bcc'] as $bcc) {
                        if (filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                            $this->mailer->addBCC($bcc);
                        }
                    }
                }
                
                // Reply-To
                if (!empty($data['reply_to']) && filter_var($data['reply_to'], FILTER_VALIDATE_EMAIL)) {
                    $this->mailer->addReplyTo($data['reply_to']);
                }
                
                // Adjuntos
                if (!empty($data['attachments']) && is_array($data['attachments'])) {
                    foreach ($data['attachments'] as $attachment) {
                        if (is_string($attachment)) {
                            // Solo ruta
                            if (file_exists($attachment)) {
                                $this->mailer->addAttachment($attachment);
                            }
                        } elseif (is_array($attachment)) {
                            // Array con 'path' y opcionalmente 'name'
                            $path = $attachment['path'] ?? $attachment['file'] ?? null;
                            $name = $attachment['name'] ?? null;
                            
                            if ($path && file_exists($path)) {
                                $this->mailer->addAttachment($path, $name);
                            }
                        }
                    }
                }
                
                // Enviar
                $result = $this->mailer->send();
                
                if ($result) {
                    $messageId = $this->mailer->getLastMessageID();
                    $this->log('info', "Email enviado exitosamente a {$data['to']} - Subject: {$data['subject']} - Message ID: {$messageId}");
                    
                    return [
                        'success' => true,
                        'message' => 'Email enviado correctamente',
                        'message_id' => $messageId
                    ];
                } else {
                    throw new Exception('Error desconocido al enviar email');
                }
                
            } catch (PHPMailerException $e) {
                $error = $this->mailer->ErrorInfo;
                $this->lastError = $error;
                $this->log('error', "Error al enviar email a {$data['to']}: {$error}");
                
                return [
                    'success' => false,
                    'message' => 'Error al enviar email: ' . $error,
                    'error' => $error
                ];
                
            } catch (Exception $e) {
                $this->lastError = $e->getMessage();
                $this->log('error', "Error: {$e->getMessage()}");
                
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        /**
         * Enviar email usando plantilla
         * @param string $templateName Nombre de la plantilla
         * @param string $to Email destinatario
         * @param string $subject Asunto (puede contener variables)
         * @param array $variables Variables para la plantilla
         * @param array $options Opciones adicionales (cc, bcc, attachments, etc.)
         * @return array Resultado del envío
         */
        public function sendTemplate($templateName, $to, $subject, $variables = [], $options = []) {
            try {
                $template = new EmailTemplate();
                $body = $template->load($templateName, $variables);
                
                // Reemplazar variables en el asunto también
                foreach ($variables as $key => $value) {
                    $subject = str_replace('{{' . $key . '}}', $value, $subject);
                    $subject = str_replace('{{ ' . $key . ' }}', $value, $subject);
                }
                
                $data = array_merge([
                    'to' => $to,
                    'subject' => $subject,
                    'body' => $body,
                    'body_type' => 'html'
                ], $options);
                
                return $this->send($data);
                
            } catch (Exception $e) {
                $this->log('error', "Error al enviar template {$templateName}: {$e->getMessage()}");
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        /**
         * Probar conexión SMTP
         * @return array ['success' => bool, 'message' => string]
         */
        public function testConnection() {
            try {
                // Configurar timeout más corto para la prueba (evitar bloqueos)
                $originalTimeout = $this->mailer->Timeout;
                $this->mailer->Timeout = 10; // 10 segundos máximo para prueba de conexión
                
                // Intentar conectar sin enviar email
                $this->mailer->smtpConnect();
                
                // Verificar si la conexión fue exitosa
                // PHPMailer no tiene smtpConnected(), pero smtpConnect() lanza excepción si falla
                $this->mailer->smtpClose();
                $this->log('info', 'Conexión SMTP exitosa');
                
                // Restaurar timeout original
                $this->mailer->Timeout = $originalTimeout;
                
                return [
                    'success' => true,
                    'message' => 'Conexión SMTP exitosa',
                    'server_info' => [
                        'host' => $this->mailer->Host,
                        'port' => $this->mailer->Port,
                        'secure' => $this->mailer->SMTPSecure
                    ]
                ];
                
            } catch (PHPMailerException $e) {
                // Restaurar timeout original en caso de error
                if (isset($originalTimeout)) {
                    $this->mailer->Timeout = $originalTimeout;
                }
                
                $error = $this->mailer->ErrorInfo ?? $e->getMessage();
                $this->log('error', "Error al probar conexión SMTP: {$error}");
                
                return [
                    'success' => false,
                    'message' => 'Error de conexión: ' . $error,
                    'error' => $error
                ];
            } catch (Exception $e) {
                // Restaurar timeout original en caso de error
                if (isset($originalTimeout)) {
                    $this->mailer->Timeout = $originalTimeout;
                }
                
                $error = $e->getMessage();
                $this->log('error', "Error al probar conexión SMTP: {$error}");
                
                return [
                    'success' => false,
                    'message' => 'Error de conexión: ' . $error,
                    'error' => $error
                ];
            }
        }
        
        /**
         * Obtener último error
         * @return string|null Último error
         */
        public function getLastError() {
            return $this->lastError;
        }
        
        /**
         * Log de mensajes
         * @param string $level Nivel (info, warning, error, debug)
         * @param string $message Mensaje
         */
        private function log($level, $message) {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
            @file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        }
    }
}

