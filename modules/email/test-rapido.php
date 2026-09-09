<?php
/**
 * Test Rápido del Módulo de Email
 * Ejecutar: php test-rapido.php
 */

echo "🧪 TEST RÁPIDO DEL MÓDULO DE EMAIL\n";
echo str_repeat("=", 50) . "\n\n";

// 1. Verificar configuración
echo "1️⃣ Verificando configuración...\n";
require_once 'EmailConfig.php';
$config = EmailConfig::load();
$validation = $config->validate();

if ($validation['valid']) {
    echo "   ✅ Configuración válida\n";
} else {
    echo "   ❌ Errores encontrados:\n";
    foreach ($validation['errors'] as $error) {
        echo "      - $error\n";
    }
    exit(1);
}

// 2. Probar conexión
echo "\n2️⃣ Probando conexión SMTP...\n";
require_once 'EmailService.php';
$emailService = new EmailService($config);
$connectionTest = $emailService->testConnection();

if ($connectionTest['success']) {
    echo "   ✅ Conexión SMTP exitosa\n";
    if (isset($connectionTest['server_info'])) {
        echo "      Host: " . $connectionTest['server_info']['host'] . "\n";
        echo "      Port: " . $connectionTest['server_info']['port'] . "\n";
    }
} else {
    echo "   ❌ Error de conexión: " . $connectionTest['message'] . "\n";
    exit(1);
}

// 3. Listar plantillas
echo "\n3️⃣ Plantillas disponibles:\n";
require_once 'EmailTemplate.php';
$template = new EmailTemplate();
$templates = $template->listTemplates();
echo "   ✅ " . count($templates) . " plantillas encontradas\n";
foreach ($templates as $tpl) {
    echo "      - " . $tpl['name'] . "\n";
}

// 4. Preguntar si enviar email
echo "\n4️⃣ ¿Desea enviar un email de prueba? (s/n): ";
$handle = fopen("php://stdin", "r");
$answer = trim(strtolower(fgets($handle)));
fclose($handle);

if ($answer === 's' || $answer === 'si' || $answer === 'y' || $answer === 'yes') {
    echo "\n   📧 Ingrese el email destinatario: ";
    $handle = fopen("php://stdin", "r");
    $email = trim(fgets($handle));
    fclose($handle);
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "\n   📤 Enviando email...\n";
        $result = $emailService->send([
            'to' => $email,
            'subject' => 'Test del Módulo de Email - ' . date('Y-m-d H:i:s'),
            'body' => '<h1>Email de Prueba</h1><p>Este es un email de prueba del módulo de email.</p><p>Fecha: ' . date('Y-m-d H:i:s') . '</p><p>Si recibes este email, el módulo está funcionando correctamente! ✅</p>',
            'body_type' => 'html'
        ]);
        
        if ($result['success']) {
            echo "   ✅ Email enviado exitosamente!\n";
            echo "      Message ID: " . ($result['message_id'] ?? 'N/A') . "\n";
            echo "      Revisa tu bandeja de entrada en: $email\n";
        } else {
            echo "   ❌ Error al enviar: " . $result['message'] . "\n";
        }
    } else {
        echo "   ❌ Email inválido: $email\n";
    }
} else {
    echo "\n   ⏭️  Email de prueba omitido\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "✨ Test completado!\n";
