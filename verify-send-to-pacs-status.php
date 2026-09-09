<?php
/**
 * Script de verificación del estado de send-to-pacs.php
 * Ejecutar desde línea de comandos o navegador
 */

echo "=== VERIFICACIÓN DEL ESTADO DE SEND-TO-PACS ===\n\n";

// 1. Verificar Composer autoloader
echo "1. Composer Autoloader:\n";
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
    echo "   ✅ vendor/autoload.php existe y cargado\n";
} else {
    echo "   ❌ vendor/autoload.php NO EXISTE\n";
    exit(1);
}

// 2. Verificar TCPDF
echo "\n2. TCPDF:\n";
if (class_exists('TCPDF')) {
    echo "   ✅ TCPDF disponible\n";
    try {
        $pdf = new TCPDF();
        echo "   ✅ TCPDF instanciable\n";
    } catch (Exception $e) {
        echo "   ❌ Error instanciando TCPDF: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ❌ TCPDF NO disponible\n";
}

// 3. Verificar DOMPDF (debe estar desinstalado)
echo "\n3. DOMPDF (debe estar DESINSTALADO):\n";
if (class_exists('Dompdf\\Dompdf')) {
    echo "   ⚠️  DOMPDF TODAVÍA ESTÁ DISPONIBLE (debería estar desinstalado)\n";
    echo "   Ejecutar: composer remove dompdf/dompdf\n";
} else {
    echo "   ✅ DOMPDF correctamente desinstalado\n";
}

// 4. Verificar getDBConnection()
echo "\n4. Función getDBConnection():\n";
if (function_exists('getDBConnection')) {
    echo "   ✅ getDBConnection() disponible (cargada por Composer)\n";
    try {
        $db = getDBConnection();
        echo "   ✅ Conexión a BD exitosa\n";
    } catch (Exception $e) {
        echo "   ⚠️  Error conectando a BD: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ❌ getDBConnection() NO disponible\n";
}

// 5. Verificar User class
echo "\n5. Clase User:\n";
if (file_exists('classes/User.php')) {
    require_once 'classes/User.php';
    echo "   ✅ classes/User.php existe\n";
    if (class_exists('User')) {
        echo "   ✅ Clase User disponible\n";
    }
} else {
    echo "   ❌ classes/User.php NO EXISTE\n";
}

// 6. Verificar OrthancPacsSender
echo "\n6. Clase OrthancPacsSender:\n";
if (file_exists('api/OrthancPacsSender.php')) {
    require_once 'api/OrthancPacsSender.php';
    echo "   ✅ api/OrthancPacsSender.php existe\n";
    if (class_exists('OrthancPacsSender')) {
        echo "   ✅ Clase OrthancPacsSender disponible\n";
    }
} else {
    echo "   ❌ api/OrthancPacsSender.php NO EXISTE\n";
}

// 7. Test rápido de generación de PDF
echo "\n7. Test de generación de PDF:\n";
try {
    $pdf = new TCPDF();
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Write(0, 'Test de verificación');
    $output = $pdf->Output('', 'S');
    $size = strlen($output);
    echo "   ✅ PDF generado correctamente ($size bytes)\n";
} catch (Exception $e) {
    echo "   ❌ Error generando PDF: " . $e->getMessage() . "\n";
}

// 8. Verificar opcache
echo "\n8. OpCache:\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    if ($status && $status['opcache_enabled']) {
        echo "   ⚠️  OpCache ACTIVO\n";
        echo "   Caché puede estar interfiriendo con cambios recientes\n";
        echo "   Solución: Reiniciar servidor web (WAMP)\n";
    } else {
        echo "   ✅ OpCache desactivado o no disponible\n";
    }
} else {
    echo "   ℹ️  OpCache no disponible\n";
}

echo "\n=== FIN DE VERIFICACIÓN ===\n";
echo "\nSi todo está ✅ pero el error persiste:\n";
echo "→ Reinicia WAMP manualmente (icono verde → Reiniciar servicios)\n";
echo "→ Limpia caché del navegador (Ctrl+Shift+Del)\n";
echo "→ Verifica logs: C:\\wamp64\\logs\\php_error.log\n";

?>

