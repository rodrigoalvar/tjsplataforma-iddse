<?php
/**
 * Configuración de Eventos Automáticos de Email
 * 
 * Este archivo define los eventos que pueden disparar envíos automáticos de email.
 * 
 * Para habilitar un evento, cambiar 'enabled' a true.
 * Para deshabilitar, cambiar 'enabled' a false.
 * 
 * @package EmailModule
 * @version 1.0
 */

return [
    /**
     * Evento: Usuario Registrado
     * Se dispara cuando un nuevo usuario se registra en el sistema
     */
    'usuario_registrado' => [
        'enabled' => false,                    // Habilitar/deshabilitar este evento
        'template' => 'verification',          // Nombre de la plantilla (sin extensión)
        'recipient' => 'user_email',           // Clave en $data que contiene el email destinatario
        'subject' => 'Verifica tu cuenta - {{app_name}}',
        'description' => 'Se dispara cuando un usuario se registra en el sistema'
    ],
    
    /**
     * Evento: Informe Completado
     * Se dispara cuando se completa un informe médico
     */
    'informe_completado' => [
        'enabled' => false,
        'template' => 'informe-completado',
        'recipient' => 'paciente_email',       // Email del paciente
        'subject' => 'Tu informe está listo - {{app_name}}',
        'description' => 'Se dispara cuando se completa un informe médico'
    ],
    
    /**
     * Evento: Estudio Asignado
     * Se dispara cuando se asigna un estudio a un médico
     */
    'estudio_asignado' => [
        'enabled' => false,
        'template' => 'asignacion-estudio',
        'recipient' => 'medico_email',         // Email del médico
        'subject' => 'Nuevo estudio asignado - {{app_name}}',
        'description' => 'Se dispara cuando se asigna un estudio a un médico'
    ],
    
    /**
     * Evento: Notificación Genérica
     * Para notificaciones generales del sistema
     */
    'notificacion_generica' => [
        'enabled' => false,
        'template' => 'notificacion-generica',
        'recipient' => 'email',                // Clave genérica 'email'
        'subject' => 'Notificación - {{app_name}}',
        'description' => 'Notificación genérica del sistema'
    ]
];

/**
 * CÓMO USAR EVENTOS:
 * 
 * 1. Habilitar el evento cambiando 'enabled' => true
 * 2. Asegurarse de que la plantilla existe en templates/
 * 3. En el código del sistema, disparar el evento:
 * 
 *    require_once 'modules/email/EmailEventManager.php';
 *    $emailManager = new EmailEventManager();
 *    
 *    $emailManager->trigger('usuario_registrado', [
 *        'user_email' => $user->email,
 *        'nombre_usuario' => $user->nombre,
 *        'token_verificacion' => $token
 *    ]);
 * 
 * 4. Las variables pasadas en el array estarán disponibles en la plantilla como {{variable}}
 */

