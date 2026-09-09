<?php
/**
 * Configuración del Módulo de Email
 * Generado automáticamente por el administrador
 */

return array (
  'app_name' => 'INSTITUTO DEL DIAGNOSTICO - Portal de Estudios',
  'smtp' => 
  array (
    'host' => 'smtp.iddse.com.ar',
    'port' => 587,
    'secure' => 'tls',
    'username' => 'entregadigital@iddse.com.ar',
    'password' => 'iddse263',
    'from_email' => 'entregadigital@iddse.com.ar',
    'from_name' => 'INSTITUTO DEL DIAGNOSTICO',
  ),
  'options' => 
  array (
    'charset' => 'UTF-8',
    'debug' => false,
    'log_errors' => true,
    'log_file' => '/var/www/tjsiddse/modules/email/logs/email.log',
  ),
  'branding' => 
  array (
    'footer' => 
    array (
      'text' => '© 2025 TJS Medical - Portal de Estudios. Todos los derechos reservados.',
      'enabled' => true,
      'position' => 'bottom',
    ),
    'company' => 
    array (
      'name' => 'TJS Medical',
      'full_name' => 'TJS Medical - Portal de Estudios',
      'copyright_year' => '2025',
    ),
  ),
);
