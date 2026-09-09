<?php
/**
 * Script de Prueba del Módulo de Email
 * 
 * Este script permite probar el funcionamiento del módulo de email.
 * 
 * Uso: php tests/test-email.php
 * 
 * @package EmailModule
 * @version 1.0
 */

require_once __DIR__ . '/../EmailConfig.php';
require_once __DIR__ . '/../EmailService.php';
require_once __DIR__ . '/../EmailTemplate.php';
require_once __DIR__ . '/../EmailEventManager.php';

echo "=== TEST DEL MÓDULO DE EMAIL ===\n\n";

// 1. Probar carga de configuración
echo "1. Probando carga de configuración...\n";
try {
    $config = EmailConfig::load();
    echo "   ✓ Configuración cargada\n";
    
    $validation = $config->validate();
    if ($validation['valid']) {
        echo "   ✓ Configuración válida\n";
    } else {
        echo "   ✗ Configuración inválida:\n";
        foreach ($validation['errors'] as $error) {
            echo "     - $error\n";
        }
        exit(1);
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Probar conexión SMTP
echo "\n2. Probando conexión SMTP...\n";
try {
    $emailService = new EmailService($config);
    $result = $emailService->testConnection();
    
    if ($result['success']) {
        echo "   ✓ Conexión SMTP exitosa\n";
        echo "   - Host: " . ($result['server_info']['host'] ?? 'N/A') . "\n";
        echo "   - Port: " . ($result['server_info']['port'] ?? 'N/A') . "\n";
    } else {
        echo "   ✗ Error de conexión: " . $result['message'] . "\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Probar carga de plantillas
echo "\n3. Probando carga de plantillas...\n";
try {
    $template = new EmailTemplate();
    $templates = $template->listTemplates();
    
    echo "   ✓ Plantillas encontradas: " . count($templates) . "\n";
    foreach ($templates as $tpl) {
        echo "     - " . $tpl['name'] . " (" . $tpl['file'] . ")\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 4. Probar procesamiento de plantilla
echo "\n4. Probando procesamiento de plantilla...\n";
try {
    $template = new EmailTemplate();
    if ($template->exists('notificacion-generica')) {
        $content = $template->load('notificacion-generica', [
            'nombre_usuario' => 'Usuario de Prueba',
            'mensaje' => 'Este es un mensaje de prueba'
        ]);
        echo "   ✓ Plantilla procesada correctamente\n";
        echo "   - Longitud: " . strlen($content) . " caracteres\n";
    } else {
        echo "   ⚠ Plantilla 'notificacion-generica' no encontrada\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 5. Probar EmailEventManager
echo "\n5. Probando EmailEventManager...\n";
try {
    $eventManager = new EmailEventManager($emailService);
    $events = $eventManager->getEvents();
    echo "   ✓ Eventos cargados: " . count($events) . "\n";
    foreach ($events as $name => $event) {
        $status = ($event['enabled'] ?? false) ? 'habilitado' : 'deshabilitado';
        echo "     - $name: $status\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 6. Preguntar si enviar email de prueba
echo "\n6. ¿Desea enviar un email de prueba? (s/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$answer = trim(strtolower($line));
fclose($handle);

if ($answer === 's' || $answer === 'si' || $answer === 'y' || $answer === 'yes') {
    echo "\n   Ingrese el email de destino: ";
    $handle = fopen("php://stdin", "r");
    $email = trim(fgets($handle));
    fclose($handle);
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "\n   Enviando email de prueba...\n";
        try {
            $result = $emailService->send([
                'to' => $email,
                'subject' => 'Test del Módulo de Email - ' . date('Y-m-d H:i:s'),
                'body' => '<h1>Email de Prueba</h1><p>Este es un email de prueba del módulo de email.</p><p>Fecha: ' . date('Y-m-d H:i:s') . '</p>',
                'body_type' => 'html'
            ]);
            
            if ($result['success']) {
                echo "   ✓ Email enviado exitosamente\n";
                echo "   - Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";
            } else {
                echo "   ✗ Error al enviar: " . $result['message'] . "\n";
            }
        } catch (Exception $e) {
            echo "   ✗ Error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ✗ Email inválido\n";
    }
} else {
    echo "\n   Email de prueba omitido\n";
}

echo "\n=== TEST COMPLETADO ===\n";

