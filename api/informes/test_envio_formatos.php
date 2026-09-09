<?php
/**
 * Script de Prueba - Envío de Informes a PACS en múltiples formatos
 * 
 * Este script de prueba simula el envío de un informe a PACS
 * en ambos formatos (PDF y JPG) para verificar que todo funcione correctamente.
 * 
 * USO:
 * 1. Ajustar las variables de configuración
 * 2. Ejecutar: php test_envio_formatos.php
 * 3. O acceder via web con parámetros GET
 * 
 * Parámetros GET (web):
 * - informe_id: ID del informe a enviar
 * - format: pdf o jpg
 * - token: Token de sesión
 */

// Configuración
$API_URL = 'http://localhost/api/informes/send-to-pacs.php'; // Ajustar según tu configuración
$INFORME_ID = 1; // Cambiar por un ID de informe real
$SESSION_TOKEN = 'tu_token_aqui'; // Cambiar por un token válido

// Si se ejecuta via web, obtener parámetros GET
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>\n";
    
    if (isset($_GET['informe_id'])) {
        $INFORME_ID = (int)$_GET['informe_id'];
    }
    if (isset($_GET['token'])) {
        $SESSION_TOKEN = $_GET['token'];
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║           TEST DE ENVÍO DE INFORMES A PACS                    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "⚙️  Configuración:\n";
echo "   • API URL: $API_URL\n";
echo "   • Informe ID: $INFORME_ID\n";
echo "   • Token: " . (strlen($SESSION_TOKEN) > 10 ? substr($SESSION_TOKEN, 0, 10) . "..." : $SESSION_TOKEN) . "\n\n";

// Verificar si se especificó un formato
$testFormat = $_GET['format'] ?? null;

if ($testFormat) {
    // Probar solo el formato especificado
    echo "🎯 Probando formato: " . strtoupper($testFormat) . "\n\n";
    testEnvioInforme($API_URL, $INFORME_ID, $SESSION_TOKEN, $testFormat);
} else {
    // Probar ambos formatos
    echo "🎯 Probando AMBOS formatos...\n\n";
    
    // Prueba 1: PDF
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "TEST 1: Envío como PDF\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    $resultPdf = testEnvioInforme($API_URL, $INFORME_ID, $SESSION_TOKEN, 'pdf');
    
    echo "\n\n";
    
    // Esperar 2 segundos entre pruebas
    sleep(2);
    
    // Prueba 2: JPG
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "TEST 2: Envío como JPG (Imagen)\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    $resultJpg = testEnvioInforme($API_URL, $INFORME_ID, $SESSION_TOKEN, 'jpg');
    
    // Resumen final
    echo "\n\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                      RESUMEN DE PRUEBAS                        ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    echo "📄 Formato PDF: " . ($resultPdf['success'] ? "✅ EXITOSO" : "❌ FALLIDO") . "\n";
    if ($resultPdf['success']) {
        echo "   • Instance ID: " . $resultPdf['instance_id'] . "\n";
        echo "   • Study ID: " . $resultPdf['study_id'] . "\n";
        echo "   • Tamaño: " . $resultPdf['file_size_mb'] . " MB\n";
    } else {
        echo "   • Error: " . $resultPdf['error'] . "\n";
    }
    
    echo "\n";
    
    echo "🖼️  Formato JPG: " . ($resultJpg['success'] ? "✅ EXITOSO" : "❌ FALLIDO") . "\n";
    if ($resultJpg['success']) {
        echo "   • Instance ID: " . $resultJpg['instance_id'] . "\n";
        echo "   • Study ID: " . $resultJpg['study_id'] . "\n";
        echo "   • Tamaño: " . $resultJpg['file_size_mb'] . " MB\n";
    } else {
        echo "   • Error: " . $resultJpg['error'] . "\n";
    }
    
    echo "\n";
    
    if ($resultPdf['success'] && $resultJpg['success']) {
        echo "✅ TODAS LAS PRUEBAS PASARON\n";
        echo "   Comparación de tamaños:\n";
        echo "   • PDF: " . $resultPdf['file_size_mb'] . " MB\n";
        echo "   • JPG: " . $resultJpg['file_size_mb'] . " MB\n";
        
        $ratio = $resultJpg['file_size_mb'] / $resultPdf['file_size_mb'];
        echo "   • Ratio JPG/PDF: " . number_format($ratio, 2) . "x\n";
        
        if ($ratio > 2) {
            echo "   ⚠️  La imagen JPG es significativamente más grande que el PDF\n";
        }
    } elseif (!$resultPdf['success'] && !$resultJpg['success']) {
        echo "❌ TODAS LAS PRUEBAS FALLARON\n";
        echo "   Verificar:\n";
        echo "   • Token de sesión válido\n";
        echo "   • Informe ID existe\n";
        echo "   • Permisos de usuario\n";
        echo "   • Librerías instaladas (TCPDF, Imagick)\n";
    } else {
        echo "⚠️  PRUEBAS PARCIALES\n";
        echo "   Una de las pruebas falló. Revisar logs.\n";
    }
}

echo "\n";

if (php_sapi_name() !== 'cli') {
    echo "</pre>\n";
}

/**
 * Función para probar envío de informe
 */
function testEnvioInforme($apiUrl, $informeId, $sessionToken, $format) {
    echo "📤 Enviando informe #$informeId como " . strtoupper($format) . "...\n\n";
    
    $startTime = microtime(true);
    
    // Preparar datos
    $data = [
        'informe_id' => $informeId,
        'format' => $format,
        'check_duplicates' => false // Desactivar para pruebas
    ];
    
    // Inicializar cURL
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $sessionToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 minutos timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo local
    
    // Ejecutar petición
    echo "⏳ Esperando respuesta...\n";
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    echo "⏱️  Tiempo de respuesta: {$duration}s\n";
    echo "🔢 HTTP Code: $httpCode\n\n";
    
    if ($curlError) {
        echo "❌ Error de cURL: $curlError\n";
        return [
            'success' => false,
            'error' => $curlError
        ];
    }
    
    // Decodificar respuesta
    $result = json_decode($response, true);
    
    if ($result === null) {
        echo "❌ Error decodificando respuesta JSON\n";
        echo "Respuesta raw:\n";
        echo substr($response, 0, 500) . "...\n";
        return [
            'success' => false,
            'error' => 'Invalid JSON response'
        ];
    }
    
    // Mostrar resultado
    if ($result['success']) {
        echo "✅ ÉXITO: " . $result['message'] . "\n\n";
        echo "📊 Detalles:\n";
        echo "   • Instance ID: " . ($result['data']['instance_id'] ?? 'N/A') . "\n";
        echo "   • Study ID: " . ($result['data']['study_id'] ?? 'N/A') . "\n";
        echo "   • Series ID: " . ($result['data']['series_id'] ?? 'N/A') . "\n";
        echo "   • Tamaño: " . ($result['data']['file_size_mb'] ?? 'N/A') . " MB\n";
        echo "   • Formato: " . strtoupper($result['format'] ?? $format) . "\n";
        
        if ($result['is_update']) {
            echo "   ⚠️  Es una actualización (reemplazó versión anterior)\n";
            echo "   • Serie anterior: " . ($result['old_series_id'] ?? 'N/A') . "\n";
        }
        
        return [
            'success' => true,
            'instance_id' => $result['data']['instance_id'] ?? null,
            'study_id' => $result['data']['study_id'] ?? null,
            'series_id' => $result['data']['series_id'] ?? null,
            'file_size_mb' => $result['data']['file_size_mb'] ?? 0
        ];
        
    } else {
        echo "❌ ERROR: " . $result['message'] . "\n";
        if (isset($result['error_code'])) {
            echo "   • Código de error: " . $result['error_code'] . "\n";
        }
        if (isset($result['error_details'])) {
            echo "   • Detalles: " . substr($result['error_details'], 0, 200) . "...\n";
        }
        
        return [
            'success' => false,
            'error' => $result['message']
        ];
    }
}

/**
 * Instrucciones de uso
 */
function mostrarInstrucciones() {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                    INSTRUCCIONES DE USO                        ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    echo "📋 LÍNEA DE COMANDOS:\n";
    echo "   php test_envio_formatos.php\n\n";
    
    echo "🌐 VIA WEB:\n";
    echo "   http://localhost/api/informes/test_envio_formatos.php\n";
    echo "   http://localhost/api/informes/test_envio_formatos.php?informe_id=123&format=pdf\n";
    echo "   http://localhost/api/informes/test_envio_formatos.php?informe_id=123&format=jpg&token=abc123\n\n";
    
    echo "📝 NOTAS:\n";
    echo "   • Editar variables de configuración en la parte superior del script\n";
    echo "   • Asegurarse de tener un token de sesión válido\n";
    echo "   • Verificar que el informe_id existe en la base de datos\n";
    echo "   • Revisar logs en /logs/php_errors.log si hay errores\n\n";
}

if (php_sapi_name() === 'cli' && in_array('--help', $argv)) {
    mostrarInstrucciones();
}
?>

