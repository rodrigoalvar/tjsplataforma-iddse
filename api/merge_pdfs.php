<?php
/**
 * API para concatenar múltiples PDFs en uno solo
 * Recibe un array de rutas de PDFs y devuelve un PDF combinado
 */

@error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);

@header('Content-Type: application/pdf');
@header('Access-Control-Allow-Origin: *');
@header('Access-Control-Allow-Methods: POST, OPTIONS');
@header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    @http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    @http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!isset($data['pdf_paths']) || !is_array($data['pdf_paths']) || empty($data['pdf_paths'])) {
        @http_response_code(400);
        echo json_encode(['error' => 'Se requiere un array de rutas de PDFs']);
        exit();
    }
    
    $pdfPaths = $data['pdf_paths'];
    $basePath = realpath(__DIR__ . '/..');
    
    @error_log('[MERGE_PDFS] Base path: ' . $basePath);
    @error_log('[MERGE_PDFS] PDFs recibidos: ' . json_encode($pdfPaths));
    
    // Verificar que todos los PDFs existan
    $validPaths = [];
    foreach ($pdfPaths as $pdfPath) {
        // Limpiar la ruta para evitar directory traversal pero mantener la estructura
        $cleanPath = ltrim($pdfPath, '/\\');
        // Eliminar cualquier intento de directory traversal
        $cleanPath = str_replace('..', '', $cleanPath);
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $cleanPath;
        
        @error_log('[MERGE_PDFS] Verificando: ' . $fullPath);
        
        if (file_exists($fullPath) && is_file($fullPath)) {
            $validPaths[] = $fullPath;
            @error_log('[MERGE_PDFS] PDF válido encontrado: ' . $fullPath);
        } else {
            @error_log("[MERGE_PDFS] PDF no encontrado: $fullPath (existe: " . (file_exists($fullPath) ? 'si' : 'no') . ", es archivo: " . (is_file($fullPath) ? 'si' : 'no') . ")");
        }
    }
    
    if (empty($validPaths)) {
        @http_response_code(404);
        @header('Content-Type: application/json');
        echo json_encode(['error' => 'No se encontraron PDFs válidos', 'paths_checked' => $pdfPaths]);
        exit();
    }
    
    @error_log('[MERGE_PDFS] PDFs válidos encontrados: ' . count($validPaths));
    
    // Intentar usar pdftk si está disponible (más eficiente)
    $pdftkAvailable = shell_exec('which pdftk 2>/dev/null') || shell_exec('where pdftk 2>/dev/null');
    
    if ($pdftkAvailable) {
        @error_log('[MERGE_PDFS] Intentando usar pdftk');
        // Usar pdftk para concatenar
        $tempOutput = tempnam(sys_get_temp_dir(), 'merged_pdf_') . '.pdf';
        $inputFiles = implode(' ', array_map('escapeshellarg', $validPaths));
        $command = "pdftk $inputFiles cat output " . escapeshellarg($tempOutput) . " 2>&1";
        
        @error_log('[MERGE_PDFS] Comando pdftk: ' . $command);
        @exec($command, $output, $returnCode);
        @error_log('[MERGE_PDFS] pdftk return code: ' . $returnCode);
        @error_log('[MERGE_PDFS] pdftk output: ' . implode("\n", $output));
        
        if ($returnCode === 0 && file_exists($tempOutput)) {
            $mergedContent = @file_get_contents($tempOutput);
            $fileSize = filesize($tempOutput);
            @unlink($tempOutput);
            
            if ($mergedContent !== false && $fileSize > 0) {
                @error_log('[MERGE_PDFS] PDF combinado exitosamente con pdftk, tamaño: ' . $fileSize . ' bytes');
                @header('Content-Disposition: inline; filename="informes_combinados.pdf"');
                echo $mergedContent;
                exit();
            } else {
                @error_log('[MERGE_PDFS] Error: PDF combinado está vacío o no se pudo leer');
            }
        } else {
            @error_log('[MERGE_PDFS] Error: pdftk falló o no generó archivo');
        }
    }
    
    // Fallback: usar Ghostscript si está disponible
    $gsAvailable = shell_exec('which gs 2>/dev/null') || shell_exec('where gswin64c 2>/dev/null') || shell_exec('where gswin32c 2>/dev/null');
    
    if ($gsAvailable) {
        @error_log('[MERGE_PDFS] Intentando usar Ghostscript');
        $tempOutput = tempnam(sys_get_temp_dir(), 'merged_pdf_') . '.pdf';
        $inputFiles = implode(' ', array_map('escapeshellarg', $validPaths));
        
        // Determinar comando gs según sistema operativo
        $gsCmd = 'gs';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $gsCmd = shell_exec('where gswin64c 2>nul') ? 'gswin64c' : (shell_exec('where gswin32c 2>nul') ? 'gswin32c' : 'gs');
        }
        
        $command = escapeshellarg($gsCmd) . " -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=" . escapeshellarg($tempOutput) . " $inputFiles 2>&1";
        
        @error_log('[MERGE_PDFS] Comando Ghostscript: ' . $command);
        @exec($command, $output, $returnCode);
        @error_log('[MERGE_PDFS] Ghostscript return code: ' . $returnCode);
        @error_log('[MERGE_PDFS] Ghostscript output: ' . implode("\n", $output));
        
        if ($returnCode === 0 && file_exists($tempOutput)) {
            $mergedContent = @file_get_contents($tempOutput);
            $fileSize = filesize($tempOutput);
            @unlink($tempOutput);
            
            if ($mergedContent !== false && $fileSize > 0) {
                @error_log('[MERGE_PDFS] PDF combinado exitosamente con Ghostscript, tamaño: ' . $fileSize . ' bytes');
                @header('Content-Disposition: inline; filename="informes_combinados.pdf"');
                echo $mergedContent;
                exit();
            } else {
                @error_log('[MERGE_PDFS] Error: PDF combinado está vacío o no se pudo leer');
            }
        } else {
            @error_log('[MERGE_PDFS] Error: Ghostscript falló o no generó archivo');
        }
    }
    
    // Si no hay herramientas disponibles, devolver el primer PDF
    @error_log('[MERGE_PDFS] No se encontraron herramientas para concatenar PDFs (pdftk/gs), devolviendo primer PDF');
    $firstPdf = @file_get_contents($validPaths[0]);
    if ($firstPdf !== false && strlen($firstPdf) > 0) {
        @error_log('[MERGE_PDFS] Devolviendo primer PDF, tamaño: ' . strlen($firstPdf) . ' bytes');
        @header('Content-Disposition: inline; filename="informe.pdf"');
        echo $firstPdf;
        exit();
    }
    
    @http_response_code(500);
    @header('Content-Type: application/json');
    echo json_encode(['error' => 'No se pudo procesar los PDFs', 'details' => 'No hay herramientas disponibles y no se pudo leer el primer PDF']);
    
} catch (Exception $e) {
    @error_log('Error en merge_pdfs.php: ' . $e->getMessage());
    @http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
?>
