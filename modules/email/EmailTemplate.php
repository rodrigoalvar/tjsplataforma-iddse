<?php
/**
 * EmailTemplate - Gestor de Plantillas de Email
 * 
 * Carga y procesa plantillas HTML para emails.
 * Soporta variables personalizadas y variables del sistema.
 * 
 * @package EmailModule
 * @version 1.0
 */

if (!class_exists('EmailTemplate')) {
    class EmailTemplate {
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
                require_once __DIR__ . '/EmailConfig.php';
                $config = EmailConfig::load();
                // EmailConfig::load() devuelve un objeto, usar métodos para acceder
                // Intentar obtener app_name directamente
                $appName = $config->get('app_name', null);
                // Si no existe app_name, usar from_name como fallback
                if ($appName === null) {
                    $smtpConfig = $config->getSmtpConfig();
                    $appName = $smtpConfig['from_name'] ?? 'TJS Medical - Portal de Estudios';
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
            $path = dirname(dirname(dirname($script))); // Subir 3 niveles desde modules/email/
            return $protocol . $host . $path;
        }
        
        /**
         * Cargar plantilla
         * @param string $templateName Nombre de la plantilla (sin extensión)
         * @param array $variables Variables para reemplazar
         * @return string Contenido de la plantilla procesada
         * @throws Exception Si la plantilla no existe
         */
        public function load($templateName, $variables = []) {
            // Buscar plantilla con diferentes extensiones
            $extensions = ['.html', '.htm', '.php'];
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
            
            // Inyectar footer automático si está habilitado
            $processed = $this->injectFooter($processed);
            
            return $processed;
        }
        
        /**
         * Reemplazar variables en el contenido
         * @param string $content Contenido con variables
         * @param array $variables Variables a reemplazar
         * @return string Contenido procesado
         */
        private function replaceVariables($content, $variables) {
            // Primero procesar bloques condicionales {{#if variable}}...{{/if}}
            $content = $this->processConditionalBlocks($content, $variables);
            
            // Procesar variables con valores por defecto {{variable|default}} (ANTES de reemplazar variables simples)
            $content = $this->processDefaultValues($content, $variables);
            
            // Reemplazar variables simples en formato {{variable}} (solo las que no tienen | y no son #if)
            foreach ($variables as $key => $value) {
                // Convertir valor a string si no lo es
                $value = (string)$value;
                
                // Reemplazar {{variable}} (sin espacios) - excluir las que tienen | o #if
                $pattern = '/\{\{\s*' . preg_quote($key, '/') . '\s*(?![|#])\}\}/';
                $content = preg_replace($pattern, $value, $content);
            }
            
            // Limpiar cualquier variable sin reemplazar que quede (excluir #if y |)
            $content = preg_replace('/\{\{[^|}#\/]+\}\}/', '', $content);
            
            return $content;
        }
        
        /**
         * Procesar bloques condicionales {{#if variable}}...{{/if}}
         * @param string $content Contenido con bloques condicionales
         * @param array $variables Variables disponibles
         * @return string Contenido procesado
         */
        private function processConditionalBlocks($content, $variables) {
            // Patrón para encontrar bloques {{#if variable}}...{{/if}}
            // Usar modo no-greedy y multilínea
            $pattern = '/\{\{#if\s+([^}\s]+)\s*\}\}(.*?)\{\{\/if\}\}/s';
            
            $maxIterations = 10; // Prevenir loops infinitos
            $iteration = 0;
            
            while (preg_match($pattern, $content) && $iteration < $maxIterations) {
                $content = preg_replace_callback($pattern, function($matches) use ($variables) {
                    $variableName = trim($matches[1]);
                    $blockContent = $matches[2];
                    
                    // Verificar si la variable existe y tiene valor
                    $hasValue = false;
                    if (isset($variables[$variableName])) {
                        $value = $variables[$variableName];
                        $hasValue = !empty($value) && $value !== 'N/A' && $value !== '' && $value !== null;
                    }
                    
                    // Si la variable tiene valor, devolver el contenido del bloque
                    // Si no, devolver cadena vacía
                    return $hasValue ? $blockContent : '';
                }, $content);
                $iteration++;
            }
            
            return $content;
        }
        
        /**
         * Procesar variables con valores por defecto {{variable|default}}
         * @param string $content Contenido con variables con default
         * @param array $variables Variables disponibles
         * @return string Contenido procesado
         */
        private function processDefaultValues($content, $variables) {
            // Patrón para encontrar {{variable|default}} (puede tener espacios)
            $pattern = '/\{\{\s*([^|\s]+)\s*\|\s*([^}]+)\s*\}\}/';
            
            return preg_replace_callback($pattern, function($matches) use ($variables) {
                $variableName = trim($matches[1]);
                $defaultValue = trim($matches[2]);
                
                // Si la variable existe y tiene valor, usarla
                if (isset($variables[$variableName])) {
                    $value = $variables[$variableName];
                    // Considerar que tiene valor si no está vacío, no es 'N/A' y no es null
                    if (!empty($value) && $value !== 'N/A' && $value !== null) {
                        return (string)$value;
                    }
                }
                
                // Si no, usar el valor por defecto
                return $defaultValue;
            }, $content);
        }
        
        /**
         * Inyectar footer automático de marca
         * @param string $content Contenido de la plantilla
         * @return string Contenido con footer inyectado
         */
        private function injectFooter($content) {
            // Cargar configuración de branding desde email_config.php
            try {
                require_once __DIR__ . '/EmailConfig.php';
                $config = EmailConfig::load();
                $branding = $config->get('branding', null);
                
                if (!is_array($branding) || !isset($branding['footer']) || !($branding['footer']['enabled'] ?? true)) {
                    return $content;
                }
                
                $footerText = $branding['footer']['text'] ?? '';
            } catch (Exception $e) {
                // Si hay error, retornar contenido sin modificar
                return $content;
            }
            
            if (empty($footerText)) {
                return $content;
            }
            
            // Buscar si ya existe un footer en la plantilla
            // Buscar patrones comunes de footer
            $footerPatterns = [
                '/<div[^>]*class=["\']footer["\'][^>]*>.*?<\/div>/is',
                '/<footer[^>]*>.*?<\/footer>/is',
                '/<p[^>]*>.*?Todos los derechos reservados.*?<\/p>/is'
            ];
            
            $hasFooter = false;
            foreach ($footerPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $hasFooter = true;
                    break;
                }
            }
            
            // Si no hay footer, agregarlo antes del cierre de body o al final
            if (!$hasFooter) {
                // Buscar el cierre de </body> o </html>
                if (preg_match('/<\/body>/i', $content)) {
                    // Insertar antes de </body>
                    $footerHtml = "\n        <div class=\"footer\" style=\"margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; text-align: center;\">\n            <p>Este es un email automático, por favor no respondas a este mensaje.</p>\n            <p>" . htmlspecialchars($footerText) . "</p>\n        </div>\n    ";
                    $content = preg_replace('/<\/body>/i', $footerHtml . '</body>', $content);
                } elseif (preg_match('/<\/html>/i', $content)) {
                    // Insertar antes de </html>
                    $footerHtml = "\n        <div class=\"footer\" style=\"margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; text-align: center;\">\n            <p>Este es un email automático, por favor no respondas a este mensaje.</p>\n            <p>" . htmlspecialchars($footerText) . "</p>\n        </div>\n    ";
                    $content = preg_replace('/<\/html>/i', $footerHtml . '</html>', $content);
                } else {
                    // Agregar al final del contenido
                    $footerHtml = "\n<div class=\"footer\" style=\"margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; text-align: center;\">\n    <p>Este es un email automático, por favor no respondas a este mensaje.</p>\n    <p>" . htmlspecialchars($footerText) . "</p>\n</div>";
                    $content .= $footerHtml;
                }
            } else {
                // Si ya existe footer, reemplazar el texto de copyright si está presente
                // Buscar y reemplazar el texto de copyright en footers existentes
                $content = preg_replace(
                    '/&copy;\s*\{\{current_year\}\}\s*\{\{app_name\}\}\.\s*Todos los derechos reservados\./i',
                    htmlspecialchars($footerText),
                    $content
                );
                // También reemplazar si ya está fijo pero queremos actualizarlo desde branding
                $content = preg_replace(
                    '/&copy;\s*\d{4}\s*[^<]*Todos los derechos reservados\./i',
                    htmlspecialchars($footerText),
                    $content
                );
                // Reemplazar comentario de inyección automática con el footer real
                $content = preg_replace(
                    '/<!--\s*Footer inyectado automáticamente desde config\/branding\.php\s*-->/i',
                    '<p>' . htmlspecialchars($footerText) . '</p>',
                    $content
                );
            }
            
            return $content;
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
                if (is_file($path) && preg_match('/\.(html|htm|php)$/i', $file)) {
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
            $extensions = ['.html', '.htm', '.php'];
            
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

