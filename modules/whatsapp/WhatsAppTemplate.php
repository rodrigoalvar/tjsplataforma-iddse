<?php
/**
 * WhatsAppTemplate - Gestor de Plantillas de WhatsApp
 * 
 * Carga y procesa plantillas de texto para WhatsApp.
 * Soporta variables personalizadas y variables del sistema.
 * Las plantillas de WhatsApp son texto plano (sin HTML).
 * 
 * @package WhatsAppModule
 * @version 1.0
 */

if (!class_exists('WhatsAppTemplate')) {
    class WhatsAppTemplate {
        private $templatesDir;
        private $defaultVariables = [];
        
        /**
         * Constructor
         * @param string|null $templatesDir Directorio de plantillas
         */
        public function __construct($templatesDir = null) {
            if ($templatesDir === null) {
                $templatesDir = __DIR__ . '/templates';
            }
            $this->templatesDir = $templatesDir;
            $this->setDefaultVariables();
        }
        
        /**
         * Establecer variables por defecto del sistema
         */
        private function setDefaultVariables() {
            // Intentar cargar configuración para obtener app_name
            $appName = 'TJS Medical - Portal de Estudios'; // Valor por defecto
            try {
                require_once __DIR__ . '/WhatsAppConfig.php';
                $config = WhatsAppConfig::load();
                // Intentar obtener app_name si existe en la configuración
                if (method_exists($config, 'get')) {
                    $appName = $config->get('app_name', $appName);
                }
            } catch (Exception $e) {
                // Si no se puede cargar la configuración, usar valor por defecto
            }
            
            $this->defaultVariables = [
                'app_name' => $appName,
                'app_url' => $this->getAppUrl(),
                'current_year' => date('Y'),
                'current_date' => date('d/m/Y'),
                'current_datetime' => date('d/m/Y H:i:s')
            ];
        }
        
        /**
         * Obtener URL base de la aplicación
         * @return string URL base
         */
        private function getAppUrl() {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $path = dirname(dirname(dirname($script))); // Subir 3 niveles desde modules/whatsapp/
            return $protocol . $host . $path;
        }
        
        /**
         * Cargar plantilla
         * @param string $templateName Nombre de la plantilla (sin extensión)
         * @param array $variables Variables para reemplazar
         * @return string Contenido de la plantilla procesada (texto plano)
         * @throws Exception Si la plantilla no existe
         */
        public function load($templateName, $variables = []) {
            // Buscar plantilla con diferentes extensiones
            $extensions = ['.txt', '.text', '.html', '.htm'];
            $templatePath = null;
            
            foreach ($extensions as $ext) {
                $path = $this->templatesDir . '/' . $templateName . $ext;
                if (file_exists($path)) {
                    $templatePath = $path;
                    break;
                }
            }
            
            if (!$templatePath) {
                throw new Exception("Plantilla no encontrada: {$templateName}");
            }
            
            // Leer contenido de la plantilla
            $content = file_get_contents($templatePath);
            
            if ($content === false) {
                throw new Exception("Error al leer plantilla: {$templateName}");
            }
            
            // Fusionar variables (personalizadas tienen prioridad sobre las por defecto)
            $allVariables = array_merge($this->defaultVariables, $variables);
            
            // Reemplazar variables en la plantilla
            $processed = $this->replaceVariables($content, $allVariables);
            
            // Convertir HTML a texto plano si es necesario
            $processed = $this->htmlToPlainText($processed);
            
            return $processed;
        }
        
        /**
         * Reemplazar variables en el contenido
         * @param string $content Contenido con variables
         * @param array $variables Variables a reemplazar
         * @return string Contenido procesado
         */
        private function replaceVariables($content, $variables) {
            // Reemplazar variables en formato {{variable}}
            // Usar expresiones regulares para capturar todas las variaciones
            foreach ($variables as $key => $value) {
                // Convertir valor a string si no lo es
                $value = (string)$value;
                
                // Reemplazar {{variable}} (sin espacios)
                $content = str_replace('{{' . $key . '}}', $value, $content);
                
                // Reemplazar {{ variable }} (con espacios)
                $content = str_replace('{{ ' . $key . ' }}', $value, $content);
                
                // Reemplazar usando regex para capturar variaciones con espacios opcionales
                $pattern = '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/';
                $content = preg_replace($pattern, $value, $content);
            }
            
            // Log para debugging (solo si hay variables sin reemplazar)
            if (preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches)) {
                $unreplaced = array_unique($matches[1]);
                if (!empty($unreplaced)) {
                    error_log('Variables no reemplazadas en plantilla WhatsApp: ' . implode(', ', $unreplaced));
                }
            }
            
            return $content;
        }
        
        /**
         * Convertir HTML a texto plano para WhatsApp
         * @param string $html Contenido HTML
         * @return string Texto plano
         */
        private function htmlToPlainText($html) {
            if (empty($html)) {
                return '';
            }
            
            // Si no parece HTML, retornar tal cual
            if (strpos($html, '<') === false) {
                return $html;
            }
            
            // Reemplazar saltos de línea HTML
            $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
            $text = str_replace('</p>', "\n\n", $text);
            $text = str_replace('</div>', "\n", $text);
            $text = str_replace('</h1>', "\n\n", $text);
            $text = str_replace('</h2>', "\n\n", $text);
            $text = str_replace('</h3>', "\n\n", $text);
            $text = str_replace('</h4>', "\n\n", $text);
            $text = str_replace('</h5>', "\n\n", $text);
            $text = str_replace('</h6>', "\n\n", $text);
            
            // Remover todas las etiquetas HTML
            $text = strip_tags($text);
            
            // Decodificar entidades HTML
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Limpiar espacios múltiples y saltos de línea
            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);
            
            return trim($text);
        }
        
        /**
         * Listar plantillas disponibles
         * @return array Lista de plantillas disponibles
         */
        public function listTemplates() {
            $templates = [];
            
            if (!is_dir($this->templatesDir)) {
                return $templates;
            }
            
            $files = scandir($this->templatesDir);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $path = $this->templatesDir . '/' . $file;
                if (is_file($path) && preg_match('/\.(txt|text|html|htm)$/i', $file)) {
                    $name = pathinfo($file, PATHINFO_FILENAME);
                    $templates[] = [
                        'name' => $name,
                        'file' => $file,
                        'path' => $path,
                        'size' => filesize($path),
                        'modified' => filemtime($path)
                    ];
                }
            }
            
            return $templates;
        }
        
        /**
         * Verificar si una plantilla existe
         * @param string $templateName Nombre de la plantilla
         * @return bool True si existe
         */
        public function exists($templateName) {
            $extensions = ['.txt', '.text', '.html', '.htm'];
            
            foreach ($extensions as $ext) {
                $path = $this->templatesDir . '/' . $templateName . $ext;
                if (file_exists($path)) {
                    return true;
                }
            }
            
            return false;
        }
        
        /**
         * Obtener variables por defecto
         * @return array Variables por defecto
         */
        public function getDefaultVariables() {
            return $this->defaultVariables;
        }
        
        /**
         * Agregar variable por defecto
         * @param string $key Clave
         * @param mixed $value Valor
         */
        public function addDefaultVariable($key, $value) {
            $this->defaultVariables[$key] = $value;
        }
    }
}

