<?php
/**
 * Envío de informe a PACS (Orthanc) — lógica reutilizable.
 * Incluido desde send-to-pacs.php y desde recepción/vinculación de informes recibidos.
 */

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}
require_once __DIR__ . '/../OrthancPacsSender.php';

if (!function_exists('stps_sendInformeToPacsInternal')) {

if (!function_exists('informeHtmlEsSoloBloqueAdjuntoPdf')) {
function informeHtmlEsSoloBloqueAdjuntoPdf(string $html): bool
{
    $h = trim($html);
    if ($h === '') {
        return true;
    }
    if (strpos($h, 'informe-adjunto') === false || strpos($h, 'pdf-viewer-btn') === false) {
        return false;
    }
    if (strlen($h) > 8000) {
        return false;
    }

    return true;
}

}
if (!function_exists('generatePdfFromHtml')) {
function generatePdfFromHtml($htmlContent, $informeData) {
    error_log("generatePdfFromHtml: Iniciando generación de PDF");
    error_log("TCPDF disponible: " . (class_exists('TCPDF') ? 'SI' : 'NO'));
    error_log("DOMPDF disponible: " . (class_exists('Dompdf\Dompdf') ? 'SI' : 'NO'));
    
    // Usar TCPDF (más estable que DOMPDF, sin errores de CSS)
    if (class_exists('TCPDF')) {
        error_log("Usando TCPDF para generación de PDF");
        try {
            // Crear nuevo documento PDF
            $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Configurar información del documento
            $pdf->SetCreator('Portal de Estudios');
            $pdf->SetAuthor($informeData['referring_physician'] ?? 'Sistema');
            $pdf->SetTitle('Informe Médico');
            $pdf->SetSubject('Informe Médico - ' . ($informeData['patient_name'] ?? ''));
            
            // Configurar márgenes
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetHeaderMargin(5);
            $pdf->SetFooterMargin(10);
            
            // Deshabilitar header y footer predeterminados
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Agregar página
            $pdf->AddPage();
            
            // Configurar fuente
            $pdf->SetFont('helvetica', '', 11);
            
            // Procesar imágenes base64 antes de limpiar HTML
            // TCPDF puede manejar imágenes base64 directamente, pero necesitamos asegurar el formato correcto
            $processedHtml = $htmlContent;
            
            // Contar imágenes encontradas para logging
            $imageCount = preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $processedHtml, $imageMatches);
            error_log("[SEND_TO_PACS] Imágenes encontradas en HTML: {$imageCount}");
            
            // Buscar y procesar imágenes base64 (data:image/...)
            // TCPDF requiere que las imágenes base64 estén en formato: data:image/type;base64,data
            $processedHtml = preg_replace_callback(
                '/<img[^>]+src=["\']([^"\']+)["\'][^>]*(?:width=["\']([^"\']*)["\'])?(?:height=["\']([^"\']*)["\'])?[^>]*>/i',
                function($matches) {
                    $src = $matches[1];
                    $fullMatch = $matches[0];
                    $width = $matches[2] ?? '';
                    $height = $matches[3] ?? '';
                    
                    error_log("[SEND_TO_PACS] Procesando imagen - src: " . substr($src, 0, 50) . (strlen($src) > 50 ? '...' : ''));
                    
                    // Si ya es base64, verificar y corregir formato
                    if (strpos($src, 'data:image/') === 0) {
                        // Extraer tipo de imagen y datos
                        if (preg_match('/data:image\/([^;]+)(;base64)?,?(.+)/', $src, $base64Matches)) {
                            $imageType = $base64Matches[1] ?? 'png';
                            $base64Data = end($base64Matches);
                            
                            // Asegurar formato correcto: data:image/type;base64,data
                            $correctedSrc = 'data:image/' . $imageType . ';base64,' . $base64Data;
                            
                            error_log("[SEND_TO_PACS] Imagen base64 corregida - Tipo: {$imageType}, Tamaño datos: " . strlen($base64Data) . " bytes");
                            
                            // Reemplazar en el tag img, manteniendo atributos width/height si existen
                            $newImgTag = str_replace($src, $correctedSrc, $fullMatch);
                            
                            // Asegurar que el tag img tenga atributos de tamaño razonables para TCPDF
                            if (empty($width) && empty($height)) {
                                // Si no tiene tamaño, agregar un tamaño máximo para evitar problemas
                                $newImgTag = str_replace('<img', '<img style="max-width: 500px; max-height: 500px;"', $newImgTag);
                            }
                            
                            return $newImgTag;
                        } else {
                            error_log("[SEND_TO_PACS] Advertencia: Imagen base64 con formato incorrecto: " . substr($src, 0, 100));
                        }
                    }
                    
                    // Si es una URL relativa, convertir a absoluta si es necesario
                    if (strpos($src, 'http') !== 0 && strpos($src, 'data:') !== 0) {
                        // Es una ruta relativa, intentar convertir a base64 si el archivo existe
                        $basePath = realpath(__DIR__ . '/../../');
                        $possiblePaths = [
                            $basePath . '/' . ltrim($src, '/'),
                            $basePath . '/uploads/' . basename($src),
                            $basePath . '/uploads/informes/' . basename($src),
                            $basePath . '/uploads/temp_mobile/' . basename($src)
                        ];
                        
                        foreach ($possiblePaths as $filePath) {
                            if (file_exists($filePath) && is_file($filePath)) {
                                // Convertir archivo a base64
                                $imageData = file_get_contents($filePath);
                                $imageInfo = getimagesize($filePath);
                                
                                if ($imageInfo === false) {
                                    error_log("[SEND_TO_PACS] Advertencia: No se pudo obtener información de imagen: {$filePath}");
                                    continue;
                                }
                                
                                $mimeType = $imageInfo['mime'];
                                $base64 = base64_encode($imageData);
                                $correctedSrc = 'data:' . $mimeType . ';base64,' . $base64;
                                
                                error_log("[SEND_TO_PACS] Imagen convertida a base64: {$filePath} -> Tipo: {$mimeType}, Tamaño: " . strlen($base64) . " bytes");
                                
                                // Reemplazar src y mantener atributos
                                $newImgTag = str_replace($src, $correctedSrc, $fullMatch);
                                
                                // Agregar tamaño máximo si no tiene
                                if (empty($width) && empty($height)) {
                                    $newImgTag = str_replace('<img', '<img style="max-width: 500px; max-height: 500px;"', $newImgTag);
                                }
                                
                                return $newImgTag;
                            }
                        }
                        
                        error_log("[SEND_TO_PACS] Advertencia: No se encontró archivo de imagen en rutas posibles para: {$src}");
                    }
                    
                    // Si es una URL absoluta o no se pudo procesar, mantenerla
                    error_log("[SEND_TO_PACS] Manteniendo imagen sin cambios: " . substr($src, 0, 100));
                    return $fullMatch;
                },
                $processedHtml
            );
            
            // Limpiar HTML: mantener solo tags seguros, incluyendo <img>
            $cleanHtml = strip_tags(
                $processedHtml, 
                '<p><br><h1><h2><h3><h4><h5><h6><strong><b><em><i><u><ul><ol><li><table><tr><td><th><tbody><thead><tfoot><div><span><img>'
            );
            
            // Escribir HTML al PDF
            // El parámetro true permite imágenes base64
            $pdf->writeHTML($cleanHtml, true, false, true, false, '');
            
            // Obtener contenido del PDF
            $output = $pdf->Output('', 'S'); // 'S' = retornar como string
            
            error_log("PDF generado exitosamente con TCPDF (" . strlen($output) . " bytes)");
            return $output;
            
        } catch (Exception $e) {
            error_log("Error usando TCPDF: " . $e->getMessage());
            throw new Exception("Error generando PDF con TCPDF: " . $e->getMessage());
        }
    }
    
    // Si TCPDF no está disponible, lanzar error
    throw new Exception('No hay ninguna librería de generación de PDF disponible. TCPDF es requerido.');
    
    // Intentar usar wkhtmltopdf si está disponible
    if (class_exists('Knp\Snappy\Pdf')) {
        try {
            $snappy = new \Knp\Snappy\Pdf('/usr/local/bin/wkhtmltopdf'); // Ajustar ruta según instalación
            return $snappy->getOutputFromHtml($htmlContent, [
                'page-size' => 'A4',
                'orientation' => 'Portrait',
                'margin-top' => '20mm',
                'margin-bottom' => '20mm',
                'margin-left' => '20mm',
                'margin-right' => '20mm'
            ]);
        } catch (Exception $e) {
            error_log("Error usando wkhtmltopdf: " . $e->getMessage());
        }
    }
    
    // Método alternativo: Usar API externa o conversión básica
    // Esta es una implementación mínima que devuelve HTML limpio
    // En producción, debe usar una librería apropiada
    
    error_log("ADVERTENCIA: No se encontró librería de PDF. Se requiere instalar dompdf o wkhtmltopdf.");
    error_log("Instalar dompdf: composer require dompdf/dompdf");
    
    // Fallback: Retornar error
    return false;
}

}

if (!function_exists('convertPdfToPng')) {

/**
 * Convierte un archivo PDF a imagen PNG
 * 
 * Utiliza Imagick para convertir todas las páginas del PDF en una sola imagen PNG.
 * Cada página se apila verticalmente para crear una imagen única.
 * 
 * @param string $pdfPath Ruta al archivo PDF
 * @param int $informeId ID del informe (para nombrar archivo)
 * @param string $timestamp Timestamp para nombrar archivo
 * @return string|false Ruta al archivo PNG generado o false en caso de error
 */
function convertPdfToPng($pdfPath, $informeId, $timestamp) {
    error_log("convertPdfToPng: Iniciando conversión de PDF a PNG");
    
    // Verificaciones múltiples de Imagick
    $imagickAvailable = false;
    if (extension_loaded('imagick')) {
        $imagickAvailable = true;
        error_log("Imagick: Extensión cargada (extension_loaded)");
    } elseif (class_exists('Imagick')) {
        $imagickAvailable = true;
        error_log("Imagick: Clase disponible (class_exists)");
    } else {
        error_log("Imagick: NO disponible");
    }
    
    // Verificar que el archivo PDF existe
    if (!file_exists($pdfPath)) {
        throw new Exception("El archivo PDF no existe: $pdfPath");
    }
    
    // Opción 1: Usar Imagick (recomendado)
    if ($imagickAvailable) {
        try {
            error_log("Usando Imagick para convertir PDF a PNG");
            
            // Crear instancia de Imagick
            $imagick = new \Imagick();
            
            // Configurar resolución ANTES de leer el PDF (importante para calidad)
            $imagick->setResolution(150, 150); // 150 DPI es buen balance calidad/tamaño
            
            // Leer el PDF
            $imagick->readImage($pdfPath);
            
            // Convertir todas las páginas a PNG y apilarlas verticalmente
            $imagick = $imagick->appendImages(true); // true = apilar verticalmente
            
            // Establecer formato PNG
            $imagick->setImageFormat('png');
            
            // Optimizar compresión
            $imagick->setImageCompressionQuality(95);
            
            // Crear directorio para imágenes si no existe
            $uploadsBase = realpath(dirname($pdfPath) . '/../');
            $pngDir = $uploadsBase . DIRECTORY_SEPARATOR . 'png_informes';
            if (!is_dir($pngDir)) {
                if (!@mkdir($pngDir, 0775, true) && !is_dir($pngDir)) {
                    throw new Exception('No se pudo crear el directorio de imágenes PNG: ' . $pngDir);
                }
            }
            
            // Guardar imagen PNG
            $pngFilename = 'informe_' . $informeId . '_' . $timestamp . '.png';
            $pngPath = $pngDir . DIRECTORY_SEPARATOR . $pngFilename;
            
            if (!$imagick->writeImage($pngPath)) {
                throw new Exception('No se pudo guardar la imagen PNG');
            }
            
            // Liberar recursos
            $imagick->clear();
            $imagick->destroy();
            
            error_log("PNG generado exitosamente con Imagick: $pngPath (" . filesize($pngPath) . " bytes)");
            return $pngPath;
            
        } catch (\ImagickException $e) {
            error_log("Error ImagickException: " . $e->getMessage());
            error_log("Código de error: " . $e->getCode());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw new Exception("Error convirtiendo PDF a PNG con Imagick: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Error genérico usando Imagick: " . $e->getMessage());
            error_log("Tipo de error: " . get_class($e));
            error_log("Stack trace: " . $e->getTraceAsString());
            throw new Exception("Error convirtiendo PDF a PNG con Imagick: " . $e->getMessage());
        }
    }
    
    // Opción 2: Usar GhostScript (si está instalado)
    if (function_exists('exec')) {
        try {
            error_log("Intentando usar GhostScript para convertir PDF a PNG");
            
            $uploadsBase = realpath(dirname($pdfPath) . '/../');
            $pngDir = $uploadsBase . DIRECTORY_SEPARATOR . 'png_informes';
            if (!is_dir($pngDir)) {
                if (!@mkdir($pngDir, 0775, true) && !is_dir($pngDir)) {
                    throw new Exception('No se pudo crear el directorio de imágenes PNG: ' . $pngDir);
                }
            }
            
            $pngFilename = 'informe_' . $informeId . '_' . $timestamp . '.png';
            $pngPath = $pngDir . DIRECTORY_SEPARATOR . $pngFilename;
            
            // Comando GhostScript
            $cmd = sprintf(
                'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r150 -sOutputFile=%s %s 2>&1',
                escapeshellarg($pngPath),
                escapeshellarg($pdfPath)
            );
            
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($pngPath)) {
                error_log("PNG generado exitosamente con GhostScript: $pngPath");
                return $pngPath;
            } else {
                error_log("GhostScript falló o no está instalado. Return code: $returnCode");
                error_log("Output: " . implode("\n", $output));
            }
            
        } catch (Exception $e) {
            error_log("Error usando GhostScript: " . $e->getMessage());
        }
    }
    
    // Si no hay ninguna librería disponible, lanzar error
    throw new Exception('No hay ninguna librería de conversión PDF a PNG disponible. Se requiere Imagick o GhostScript.');
}

}

if (!function_exists('stps_informeYaEnPacs')) {
    /**
     * Indica si el informe ya tiene trazas de envío a PACS en BD (evita doble envío auto + manual).
     */
    function stps_informeYaEnPacs(PDO $db, int $informeId): bool
    {
        if ($informeId <= 0) {
            return false;
        }
        static $cachedConditions = null;
        try {
            if ($cachedConditions === null) {
                $colStmt = $db->query("SHOW COLUMNS FROM informes");
                $existingCols = $colStmt ? array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $conds = [];
                foreach (['pacs_series_id', 'pacs_instance_id', 'pacs_study_id'] as $col) {
                    if (in_array($col, $existingCols, true)) {
                        $conds[] = "({$col} IS NOT NULL AND TRIM(COALESCE({$col}, '')) <> '')";
                    }
                }
                if (in_array('fecha_enviado_pacs', $existingCols, true)) {
                    $conds[] = "(fecha_enviado_pacs IS NOT NULL)";
                }
                $cachedConditions = $conds;
            }
            if (empty($cachedConditions)) {
                return false;
            }
            $st = $db->prepare("SELECT 1 FROM informes WHERE id = ? AND (" . implode(' OR ', $cachedConditions) . ") LIMIT 1");
            $st->execute([$informeId]);

            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

function stps_sendInformeToPacsInternal(PDO $db, array $informe, array $input): array
{
    $informeId = (int)($informe['id'] ?? 0);
    if ($informeId <= 0) {
        throw new Exception('ID de informe inválido');
    }
    $format = strtolower((string)($input['format'] ?? 'pdf'));
    if (!in_array($format, ['pdf', 'jpg'], true)) {
        throw new Exception('Formato inválido. Use "pdf" o "jpg"');
    }
    $checkDuplicates = array_key_exists('check_duplicates', $input) ? (bool)$input['check_duplicates'] : true;

    $lockName = 'pacs_if_' . $informeId;
    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 45) AS lk');
    $lockStmt->execute([$lockName]);
    $lockGot = (int)$lockStmt->fetchColumn();
    $lockAcquired = ($lockGot === 1);

    if (!$lockAcquired) {
        if (stps_informeYaEnPacs($db, $informeId)) {
            $row = null;
            try {
                $q = $db->prepare('SELECT pacs_instance_id, pacs_study_id, pacs_series_id FROM informes WHERE id = ? LIMIT 1');
                $q->execute([$informeId]);
                $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $_e) {
                $row = [];
            }

            return [
                'success' => true,
                'message' => 'El informe ya constaba enviado a PACS (otro proceso completó el envío).',
                'skipped_concurrent' => true,
                'format' => $format,
                'is_update' => false,
                'data' => [
                    'instance_id' => $row['pacs_instance_id'] ?? null,
                    'study_id' => $row['pacs_study_id'] ?? null,
                    'series_id' => $row['pacs_series_id'] ?? null,
                ],
            ];
        }
        throw new Exception('Otro envío a PACS está en curso para este informe. Intente de nuevo en unos segundos.');
    }

    try {
        try {
            $refreshStmt = $db->prepare('SELECT pacs_series_id, pacs_instance_id, pacs_study_id, study_instance_uid, estudio_id, study_id FROM informes WHERE id = ? LIMIT 1');
            $refreshStmt->execute([$informeId]);
            $freshRow = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($freshRow)) {
                foreach ($freshRow as $k => $v) {
                    $informe[$k] = $v;
                }
            }
        } catch (Throwable $_rf) {
        }

    // Verificar que el informe esté en estado "Finalizado"
    $estado = strtolower(trim($informe['estado'] ?? ''));
    if ($estado !== 'finalizado') {
        throw new Exception('Solo se pueden enviar a PACS los informes con estado "Finalizado". Estado actual: ' . ($informe['estado'] ?? 'No definido'));
    }
    
    // Obtener información del estudio desde Orthanc si está disponible
    $studyData = null;
    $originalStudyInstanceUID = null;
    
    // Prioridad 1: Obtener StudyInstanceUID desde BD (si existe columna)
    $originalStudyInstanceUID = $informe['study_instance_uid'] ?? null;

    // Prioridad 1.5: Si study_instance_uid está vacío, revisar study_id y estudio_id.
    // En algunas instalaciones orthanc_study_id almacena el DICOM UID directamente
    // (comienza con dígito y contiene puntos p.ej. "1.3.12...").
    if (empty($originalStudyInstanceUID)) {
        foreach (['study_id', 'estudio_id'] as $dicomField) {
            $candidate = trim((string)($informe[$dicomField] ?? ''));
            if ($candidate !== '' && preg_match('/^\d+(\.\d+){3,}$/', $candidate)) {
                $originalStudyInstanceUID = $candidate;
                error_log('[SEND_TO_PACS] StudyInstanceUID derivado de informes.' . $dicomField . ': ' . $candidate);
                // Persistir para futuras llamadas
                try {
                    $db->prepare('UPDATE informes SET study_instance_uid = ? WHERE id = ? AND (study_instance_uid IS NULL OR study_instance_uid = \'\')')->execute([$candidate, $informeId]);
                } catch (Throwable $_e) {}
                break;
            }
        }
    }
    
    // Prioridad 2: Si no está en BD, obtener desde Orthanc
    if (empty($originalStudyInstanceUID) && !empty($informe['estudio_id'])) {
        try {
            require_once '../OrthancClient.php';
            $orthancClient = new OrthancClient();
            $studyData = $orthancClient->getStudyDetails($informe['estudio_id']);
            
            if ($studyData && !empty($studyData['study_instance_uid'])) {
                $originalStudyInstanceUID = $studyData['study_instance_uid'];
                error_log("StudyInstanceUID obtenido desde Orthanc: {$originalStudyInstanceUID}");
            }
        } catch (Exception $e) {
            error_log("No se pudo obtener información del estudio desde Orthanc: " . $e->getMessage());
            // Continuar sin información del estudio
        }
    }
    
    // Si se obtuvo StudyInstanceUID desde Orthanc pero no está en BD, actualizar BD
    if (!empty($originalStudyInstanceUID) && empty($informe['study_instance_uid'])) {
        try {
            $updateUIDQuery = "UPDATE informes SET study_instance_uid = ? WHERE id = ?";
            $updateUIDStmt = $db->prepare($updateUIDQuery);
            $updateUIDStmt->execute([$originalStudyInstanceUID, $informeId]);
            error_log("StudyInstanceUID actualizado en BD para informe #{$informeId}: {$originalStudyInstanceUID}");
        } catch (Exception $e) {
            error_log("No se pudo actualizar study_instance_uid en BD: " . $e->getMessage());
            // No es crítico, continuar
        }
    }
    
    // Advertencia si no se puede obtener StudyInstanceUID del estudio original
    if (empty($originalStudyInstanceUID) && !empty($informe['estudio_id'])) {
        error_log("ADVERTENCIA: No se pudo obtener StudyInstanceUID del estudio {$informe['estudio_id']}. " .
                 "El informe se creará como estudio independiente en PACS.");
    }
    
    // Preparar datos para generar tags DICOM
    // Si tenemos studyData de Orthanc, usarlo como fuente principal
    $informeData = [
        'patient_id' => $informe['patient_id'] ?? ($studyData['patient_id'] ?? ''),
        'patient_name' => $informe['patient_name'] ?? ($studyData['patient_name'] ?? 'PACIENTE DESCONOCIDO'),
        'patient_birth_date' => $studyData['patient_birth_date'] ?? null,
        'patient_sex' => $studyData['patient_sex'] ?? null,
        'study_date' => !empty($informe['fecha_modificacion']) ? strtotime($informe['fecha_modificacion']) : time(),
        'modality' => 'DOC', // Los informes siempre tienen Modality DOC
        'accession_number' => $informe['accession_number'] ?? ($studyData['accession_number'] ?? $informe['estudio_id'] ?? ''),
        // CRÍTICO: Usar StudyInstanceUID del estudio original para vincular correctamente
        'study_instance_uid' => $originalStudyInstanceUID,
        'study_description' => $informe['study_description'] ?? ($informe['titulo'] ?? 'Informe Médico'),
        'institution_name' => 'HOSPITAL DIGITAL', // TODO: Obtener de configuración
        'referring_physician' => trim(($informe['usuario_nombre'] ?? '') . ' ' . ($informe['usuario_apellido'] ?? '')) ?: 'AUTOMATIZADO'
    ];
    
    // Si tenemos información del estudio original, usar sus fechas para mejor vinculación
    if ($studyData && !empty($studyData['study_date'])) {
        // Intentar usar la fecha del estudio original
        $originalStudyDate = $studyData['study_date'];
        // Formato puede ser YYYYMMDD o timestamp
        if (strlen($originalStudyDate) === 8) {
            // Ya está en YYYYMMDD
            $informeData['study_date'] = strtotime($originalStudyDate);
        } elseif (is_numeric($originalStudyDate)) {
            $informeData['study_date'] = $originalStudyDate;
        }
    }
    
    // Generar tags DICOM
    $dicomTags = OrthancPacsSender::generateDicomTags($informeData);
    
    // Verificar si el informe ya fue enviado a PACS anteriormente
    $existingInstanceId = $informe['pacs_instance_id'] ?? null;
    $existingStudyId = $informe['pacs_study_id'] ?? null;
    $existingSeriesId = $informe['pacs_series_id'] ?? null; // SeriesID según flujo propuesto
    $isUpdate = !empty($existingSeriesId) || !empty($existingInstanceId); // Prioridad a SeriesID
    
    $pacsSender = new OrthancPacsSender();
    
    // Si es una actualización, eliminar la serie anterior usando SeriesID (flujo propuesto)
    if ($isUpdate) {
        $deletedSuccessfully = false;
        
        // Prioridad 1: Eliminar por SeriesID (flujo propuesto - más robusto)
        if (!empty($existingSeriesId)) {
            error_log("Informe #{$informeId} ya fue enviado a PACS. Eliminando serie anterior (SeriesID: {$existingSeriesId})");
            
            $deleteResult = $pacsSender->deleteSeries($existingSeriesId);
            
            if ($deleteResult['success']) {
                $deletedSuccessfully = true;
                error_log("Serie anterior eliminada exitosamente. SeriesID: {$existingSeriesId}");
            } else {
                error_log("Advertencia: No se pudo eliminar serie anterior por SeriesID: " . ($deleteResult['error'] ?? 'Error desconocido'));
            }
        }
        
        // Fallback: Si no hay SeriesID o falló, intentar eliminar por InstanceID (compatibilidad)
        if (!$deletedSuccessfully && !empty($existingInstanceId)) {
            error_log("Intentando eliminar instancia anterior como fallback (InstanceID: {$existingInstanceId})");
            
            $deleteResult = $pacsSender->deleteInstance($existingInstanceId);
            
            if ($deleteResult['success']) {
                error_log("Instancia anterior eliminada exitosamente (fallback). InstanceID: {$existingInstanceId}");
            } else {
                error_log("Advertencia: No se pudo eliminar instancia anterior: " . ($deleteResult['error'] ?? 'Error desconocido'));
                // Continuar con el envío de la nueva versión (no crítico si ya fue eliminado manualmente)
            }
        }
    }
    
    // Verificar duplicados solo si NO es una actualización del mismo informe
    // Si es actualización, ya eliminamos la versión anterior, así que no hay duplicado
    if ($checkDuplicates && !$isUpdate) {

        // --- ANTI-DUPLICADO DIRECTO: Si el informe ya tiene un study_id de Orthanc en BD
        // (puede ocurrir cuando el AccessionNumber está vacío en los tags DICOM del estudio
        //  original, haciendo que checkDuplicate() no lo encuentre).
        $knownOrthancStudyId = trim((string)($informe['study_id'] ?? ''));
        if ($knownOrthancStudyId !== '' && !preg_match('/^\d+(\.\d+){3,}$/', $knownOrthancStudyId)) {
            try {
                $existingDocDirect = $pacsSender->getDocSeriesInStudy($knownOrthancStudyId, $informeData['accession_number'] ?? null);
                if ($existingDocDirect !== null) {
                    // Verificar que la serie encontrada no pertenezca ya a OTRO informe en BD.
                    // Si pertenece a otro informe (mismo estudio, paciente con múltiples informes),
                    // no es un duplicado nuestro: hay que subir una nueva serie.
                    $seriesOwnedByOther = false;
                    try {
                        $ownerStmt = $db->prepare('SELECT id FROM informes WHERE pacs_series_id = ? AND id != ? LIMIT 1');
                        $ownerStmt->execute([$existingDocDirect['series_id'], $informeId]);
                        $seriesOwnedByOther = (bool)$ownerStmt->fetchColumn();
                    } catch (Throwable $_e) {}

                    if ($seriesOwnedByOther) {
                        error_log('[SEND_TO_PACS] Serie DOC ' . $existingDocDirect['series_id'] . ' pertenece a otro informe → subiendo nueva serie para informe #' . $informeId);
                    } else {
                        error_log('[SEND_TO_PACS] ⛔ (study_id directo) Serie DOC ya existe en Orthanc '
                            . '(series=' . $existingDocDirect['series_id'] . '). Sincronizando BD.');
                        try {
                            $syncFieldsD = ['pacs_series_id = ?', 'pacs_study_id = ?'];
                            $syncValuesD = [$existingDocDirect['series_id'], $knownOrthancStudyId];
                            if ($existingDocDirect['instance_id'] !== null) {
                                $syncFieldsD[] = 'pacs_instance_id = ?';
                                $syncValuesD[] = $existingDocDirect['instance_id'];
                            }
                            $syncFieldsD[] = 'fecha_enviado_pacs = COALESCE(fecha_enviado_pacs, NOW())';
                            $syncValuesD[] = $informeId;
                            $db->prepare('UPDATE informes SET ' . implode(', ', $syncFieldsD) . ' WHERE id = ?')
                               ->execute($syncValuesD);
                            error_log('[SEND_TO_PACS] BD sincronizada (directo): pacs_series_id=' . $existingDocDirect['series_id']);
                        } catch (Throwable $dbExD) {
                            error_log('[SEND_TO_PACS] Error sincronizando BD (directo, no crítico): ' . $dbExD->getMessage());
                        }
                        return [
                            'success'               => true,
                            'message'               => 'Informe ya existe en PACS (serie DOC detectada vía study_id). BD sincronizada.',
                            'skipped_duplicate_doc' => true,
                            'format'                => $format,
                            'is_update'             => false,
                            'data'                  => [
                                'instance_id' => $existingDocDirect['instance_id'],
                                'study_id'    => $knownOrthancStudyId,
                                'series_id'   => $existingDocDirect['series_id'],
                            ],
                        ];
                    }
                }
            } catch (Exception $docExD) {
                error_log('[SEND_TO_PACS] ⚠️ Error comprobando serie DOC vía study_id directo: ' . $docExD->getMessage());
            }
        }

        $patientNameDicom = null;
        $nameParts = explode(' ', $informeData['patient_name']);
        if (count($nameParts) >= 2) {
            $lastName = array_pop($nameParts);
            $firstName = implode(' ', $nameParts);
            $patientNameDicom = "$lastName^$firstName";
        }
        
        $duplicateCheck = $pacsSender->checkDuplicate([
            'accession_number' => $informeData['accession_number'] ?: null,
            'patient_id' => $informeData['patient_id'] ?: null,
            'patient_name' => $patientNameDicom,
            'patient_name_natural' => $informeData['patient_name'],
            'study_date' => date('Ymd', $informeData['study_date'])
        ]);
        
        if ($duplicateCheck['exists']) {
            error_log('[SEND_TO_PACS] Estudio existente encontrado. Study ID: ' . $duplicateCheck['study_id']);

            // ANTI-DUPLICADO: Comprobar si el estudio ya tiene una serie DOC (informe).
            // Escenario: el primer intento subió el PDF a Orthanc pero PHP murió antes
            // de que la BD local fuera actualizada (pacs_series_id quedó NULL).
            // Sin esta comprobación el segundo intento subiría una segunda copia.
            try {
                $existingDoc = $pacsSender->getDocSeriesInStudy($duplicateCheck['study_id'], $informeData['accession_number'] ?? null);
                if ($existingDoc !== null) {
                    // Verificar que la serie encontrada no pertenezca ya a OTRO informe en BD.
                    $docOwnedByOther = false;
                    try {
                        $docOwnerStmt = $db->prepare('SELECT id FROM informes WHERE pacs_series_id = ? AND id != ? LIMIT 1');
                        $docOwnerStmt->execute([$existingDoc['series_id'], $informeId]);
                        $docOwnedByOther = (bool)$docOwnerStmt->fetchColumn();
                    } catch (Throwable $_e) {}

                    if ($docOwnedByOther) {
                        error_log('[SEND_TO_PACS] Serie DOC ' . $existingDoc['series_id'] . ' pertenece a otro informe → subiendo nueva serie para informe #' . $informeId);
                    } else {
                    error_log('[SEND_TO_PACS] ⛔ Serie DOC ya existe en Orthanc (series=' . $existingDoc['series_id'] . '). Omitiendo envío duplicado — sincronizando BD.');
                    // Sincronizar BD con los IDs ya existentes en Orthanc
                    try {
                        $syncFields = ['pacs_series_id = ?', 'pacs_study_id = ?'];
                        $syncValues = [$existingDoc['series_id'], $duplicateCheck['study_id']];
                        if ($existingDoc['instance_id'] !== null) {
                            $syncFields[] = 'pacs_instance_id = ?';
                            $syncValues[] = $existingDoc['instance_id'];
                        }
                        // Solo setear fecha_enviado_pacs si no estaba ya seteada
                        $syncFields[] = 'fecha_enviado_pacs = COALESCE(fecha_enviado_pacs, NOW())';
                        $syncValues[] = $informeId;
                        $db->prepare('UPDATE informes SET ' . implode(', ', $syncFields) . ' WHERE id = ?')
                           ->execute($syncValues);
                        error_log('[SEND_TO_PACS] BD sincronizada: pacs_series_id=' . $existingDoc['series_id']);
                    } catch (Throwable $dbEx) {
                        error_log('[SEND_TO_PACS] Error sincronizando BD (no crítico): ' . $dbEx->getMessage());
                    }
                    return [
                        'success'               => true,
                        'message'               => 'Informe ya existe en PACS (serie DOC previa detectada). BD sincronizada.',
                        'skipped_duplicate_doc' => true,
                        'format'                => $format,
                        'is_update'             => false,
                        'data'                  => [
                            'instance_id' => $existingDoc['instance_id'],
                            'study_id'    => $duplicateCheck['study_id'],
                            'series_id'   => $existingDoc['series_id'],
                        ],
                    ];
                    } // end else (serie no pertenece a otro informe)
                }
            } catch (Exception $docEx) {
                // Si falla la comprobación, seguir adelante; Orthanc no duplicará por SOP UID.
                error_log('[SEND_TO_PACS] ⚠️ Error comprobando serie DOC previa: ' . $docEx->getMessage());
            }

            try {
                // Obtener StudyInstanceUID del estudio existente desde Orthanc
                $existingStudyInstanceUID = $pacsSender->getStudyInstanceUID($duplicateCheck['study_id']);
                
                if ($existingStudyInstanceUID) {
                    error_log('[SEND_TO_PACS] StudyInstanceUID del estudio existente obtenido: ' . $existingStudyInstanceUID);
                    $originalStudyInstanceUID = $existingStudyInstanceUID;
                    error_log('[SEND_TO_PACS] El informe se agregará como nueva serie al estudio existente');
                } else {
                    error_log('[SEND_TO_PACS] ⚠️ No se pudo obtener StudyInstanceUID del estudio existente. Se creará estudio nuevo.');
                }
            } catch (Exception $e) {
                error_log('[SEND_TO_PACS] ⚠️ Error obteniendo StudyInstanceUID del estudio existente: ' . $e->getMessage());
            }
        }
    }
    
    // Para informes externos (sin HTML), reutilizar PDF existente si está disponible.
    // __DIR__/../../ = raíz del proyecto (donde están uploads/pdf_informes, uploads/informes_recibidos, …).
    $uploadsBase = realpath(__DIR__ . '/../../');
    if ($uploadsBase === false) {
        throw new Exception('No se pudo resolver la ruta base de uploads');
    }
    $projectBase = $uploadsBase;
    $existingPdfPath = null;
    if (!empty($informe['pdf_path'])) {
        $relative = ltrim(str_replace('\\', '/', (string)$informe['pdf_path']), '/');
        $candidatePath = $projectBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($candidatePath)) {
            $existingPdfPath = $candidatePath;
        }
    }

    $contenidoHtml = trim((string)($informe['contenido_html'] ?? ''));
    $shouldUseExistingPdf = ($existingPdfPath !== null
        && ($contenidoHtml === '' || informeHtmlEsSoloBloqueAdjuntoPdf($contenidoHtml)));

    if ($shouldUseExistingPdf) {
        error_log('[SEND_TO_PACS] Usando PDF en disco (externo / API / adjunto), no generación desde HTML: ' . $existingPdfPath);
        $savedPdfPath = $existingPdfPath;
    } else {
        // Flujo actual: generar PDF desde HTML
        error_log("=== INICIO GENERACIÓN PDF ===");
        error_log("Longitud HTML: " . strlen($informe['contenido_html'] ?? ''));
        
        try {
            $pdfContent = generatePdfFromHtml($informe['contenido_html'] ?? '', $informe);
            error_log("PDF generado exitosamente, tamaño: " . strlen($pdfContent) . " bytes");
        } catch (Exception $pdfEx) {
            error_log("EXCEPCIÓN EN GENERACIÓN PDF: " . $pdfEx->getMessage());
            error_log("Trace: " . $pdfEx->getTraceAsString());
            throw $pdfEx;
        }
        
        if (!$pdfContent) {
            throw new Exception('No se pudo generar el PDF desde el contenido HTML');
        }
        
        error_log("=== FIN GENERACIÓN PDF ===");

        // Guardar PDF de informe de forma persistente en /uploads/pdf_informes
        $pdfDir = $uploadsBase . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pdf_informes';
        if (!is_dir($pdfDir)) {
            if (!@mkdir($pdfDir, 0775, true) && !is_dir($pdfDir)) {
                $error = error_get_last();
                $errorMsg = $error ? $error['message'] : 'Error desconocido';
                throw new Exception('No se pudo crear el directorio de PDFs: ' . $pdfDir . '. Error: ' . $errorMsg);
            }
            // Intentar establecer permisos después de crear el directorio
            @chmod($pdfDir, 0775);
        }
        
        // Verificar que el directorio es escribible
        if (!is_writable($pdfDir)) {
            $currentPerms = substr(sprintf('%o', fileperms($pdfDir)), -4);
            $owner = fileowner($pdfDir);
            $group = filegroup($pdfDir);
            throw new Exception('El directorio de PDFs no es escribible: ' . $pdfDir . 
                              '. Permisos actuales: ' . $currentPerms . 
                              '. Propietario: ' . $owner . 
                              '. Grupo: ' . $group . 
                              '. El servidor web necesita permisos de escritura en este directorio.');
        }
        
        // Nombre de archivo: informe_{id}_{YYYYMMDD_HHMMSS}.pdf
        $timestamp = date('Ymd_His');
        $pdfFilename = 'informe_' . $informeId . '_' . $timestamp . '.pdf';
        $savedPdfPath = $pdfDir . DIRECTORY_SEPARATOR . $pdfFilename;
        
        // Intentar escribir el archivo
        $writeResult = @file_put_contents($savedPdfPath, $pdfContent);
        if ($writeResult === false) {
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Error desconocido al escribir archivo';
            $currentPerms = is_dir($pdfDir) ? substr(sprintf('%o', fileperms($pdfDir)), -4) : 'N/A';
            throw new Exception('No se pudo guardar el PDF en disco: ' . $savedPdfPath . 
                              '. Error: ' . $errorMsg . 
                              '. Permisos del directorio: ' . $currentPerms . 
                              '. Verifique que el servidor web tiene permisos de escritura.');
        }
    }
    
    $relativePdfPathForUpdate = null;
    if (strpos($savedPdfPath, $projectBase . DIRECTORY_SEPARATOR) === 0) {
        $relativePdfPathForUpdate = str_replace(DIRECTORY_SEPARATOR, '/', substr($savedPdfPath, strlen($projectBase . DIRECTORY_SEPARATOR)));
    } elseif (!empty($pdfFilename)) {
        $relativePdfPathForUpdate = 'uploads/pdf_informes/' . $pdfFilename;
    }

    // Si el formato es JPG, el flujo será diferente: PDF -> JPGs múltiples -> DICOMs -> Orthanc
    // No necesitamos convertir aquí, se hará en sendImageAsDicom()
    $fileToSend = $savedPdfPath;
    
    try {
        // Enviar a Orthanc
        $pacsSender = new OrthancPacsSender();
        // Log de tags críticos
        error_log('[SEND_TO_PACS] Enviando a Orthanc. Formato: ' . strtoupper($format) . ', Archivo: ' . $fileToSend);
        error_log('[SEND_TO_PACS] DICOM Tags clave: ' . json_encode([
            'PatientID' => $dicomTags['PatientID'] ?? null,
            'PatientName' => $dicomTags['PatientName'] ?? null,
            'StudyInstanceUID' => $dicomTags['StudyInstanceUID'] ?? null,
            'AccessionNumber' => $dicomTags['AccessionNumber'] ?? null,
            'SOPClassUID' => $dicomTags['SOPClassUID'] ?? null
        ]));
        // Determinar Parent (StudyInstanceUID) si está disponible
        $parentStudyInstanceUID = $originalStudyInstanceUID ?: null;
        if ($parentStudyInstanceUID) {
            error_log('[SEND_TO_PACS] Parent (StudyInstanceUID) establecido: ' . $parentStudyInstanceUID);
        } else {
            error_log('[SEND_TO_PACS] Parent no establecido (se creará estudio nuevo)');
        }
        // Enviar archivo según formato
        if ($format === 'jpg') {
            $result = $pacsSender->sendImageAsDicom($fileToSend, $dicomTags, $parentStudyInstanceUID);
        } else {
            $result = $pacsSender->sendPdfAsDicom($fileToSend, $dicomTags, $parentStudyInstanceUID);
        }
        
        if ($result['success']) {
            // Obtener SeriesID de la respuesta de Orthanc (ParentSeries)
            // CRÍTICO: Este es el series_id (Series ID de Orthanc) que se debe guardar en pacs_series_id
            $seriesId = null;
            
            // Método 1: Del campo directo del resultado
            if (!empty($result['series_id'])) {
                $seriesId = $result['series_id'];
                error_log('[SEND_TO_PACS] SeriesID obtenido de result[series_id]: ' . $seriesId);
            }
            
            // Método 2: Del data ParentSeries
            if (empty($seriesId) && !empty($result['data']['ParentSeries'])) {
                $seriesId = $result['data']['ParentSeries'];
                error_log('[SEND_TO_PACS] SeriesID obtenido de result[data][ParentSeries]: ' . $seriesId);
            }
            
            // Método 3: Buscar en toda la estructura de data
            if (empty($seriesId) && isset($result['data']) && is_array($result['data'])) {
                if (isset($result['data']['Series'])) {
                    $seriesId = $result['data']['Series'];
                    error_log('[SEND_TO_PACS] SeriesID obtenido de result[data][Series]: ' . $seriesId);
                }
            }
            
            // Método 4: Si aún no tenemos series_id, obtenerlo desde los detalles de la instancia
            // NOTA: OrthancPacsSender ya intenta obtenerlo desde la instancia si no viene en respuesta inicial
            // Pero por si acaso, intentamos nuevamente aquí como última opción
            if (empty($seriesId) && !empty($result['instance_id'])) {
                // El código en OrthancPacsSender ya intentó obtenerlo, confiar en eso
                // Si aún no lo tenemos, puede ser que Orthanc no lo devuelva
                error_log('[SEND_TO_PACS] ⚠️ SeriesID no disponible aún después de todos los intentos');
            }
            
            // Log detallado de la respuesta de Orthanc para debugging
            error_log('[SEND_TO_PACS] ===== RESPUESTA DE ORTHANC =====');
            error_log('[SEND_TO_PACS] Respuesta completa: ' . json_encode($result, JSON_PRETTY_PRINT));
            error_log('[SEND_TO_PACS] SeriesID final extraído: ' . ($seriesId ?? 'NULL'));
            error_log('[SEND_TO_PACS] Instance ID: ' . ($result['instance_id'] ?? 'NULL'));
            error_log('[SEND_TO_PACS] Study ID: ' . ($result['study_id'] ?? 'NULL'));
            
            if (empty($seriesId)) {
                error_log('[SEND_TO_PACS] ⚠️ ADVERTENCIA: No se pudo extraer SeriesID de la respuesta de Orthanc');
                error_log('[SEND_TO_PACS] Estructura completa de result: ' . print_r($result, true));
            } else {
                error_log('[SEND_TO_PACS] ✅ SeriesID extraído correctamente: ' . $seriesId);
            }
            
            // Guardar referencias en BD (incluyendo SeriesID según flujo propuesto)
            // CRÍTICO: Este series_id DEBE guardarse en pacs_series_id para poder eliminarlo en futuros reenvíos
            try {
                error_log('[SEND_TO_PACS][GUARDADO_BD] ===== INICIANDO GUARDADO EN BD =====');
                error_log('[SEND_TO_PACS][GUARDADO_BD] Informe ID: ' . $informeId);
                error_log('[SEND_TO_PACS][GUARDADO_BD] Instance ID a guardar: ' . ($result['instance_id'] ?? 'NULL'));
                error_log('[SEND_TO_PACS][GUARDADO_BD] Study ID a guardar: ' . ($result['study_id'] ?? 'NULL'));
                error_log('[SEND_TO_PACS][GUARDADO_BD] Series ID a guardar: ' . ($seriesId ?? 'NULL'));
                
                // Verificar qué columnas existen
                $hasFechaEnviadoPacs = false;
                $hasPacsInstanceId = false;
                $hasPacsStudyId = false;
                $hasPacsSeriesId = false;
                $hasPdfPath = false;
                try {
                    $checkColumnsQuery = "SHOW COLUMNS FROM informes";
                    $checkColumnsStmt = $db->query($checkColumnsQuery);
                    $columns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $hasFechaEnviadoPacs = in_array('fecha_enviado_pacs', $columns);
                    $hasPacsInstanceId = in_array('pacs_instance_id', $columns);
                    $hasPacsStudyId = in_array('pacs_study_id', $columns);
                    $hasPacsSeriesId = in_array('pacs_series_id', $columns);
                    $hasPdfPath = in_array('pdf_path', $columns);
                    
                    error_log('[SEND_TO_PACS][GUARDADO_BD] Verificación de columnas:');
                    error_log('[SEND_TO_PACS][GUARDADO_BD]   - fecha_enviado_pacs: ' . ($hasFechaEnviadoPacs ? 'SÍ' : 'NO'));
                    error_log('[SEND_TO_PACS][GUARDADO_BD]   - pacs_instance_id: ' . ($hasPacsInstanceId ? 'SÍ' : 'NO'));
                    error_log('[SEND_TO_PACS][GUARDADO_BD]   - pacs_study_id: ' . ($hasPacsStudyId ? 'SÍ' : 'NO'));
                    error_log('[SEND_TO_PACS][GUARDADO_BD]   - pacs_series_id: ' . ($hasPacsSeriesId ? 'SÍ' : 'NO'));
                    error_log('[SEND_TO_PACS][GUARDADO_BD]   - pdf_path: ' . ($hasPdfPath ? 'SÍ' : 'NO'));
                    
                    // Verificar columnas críticas
                    if (!$hasPacsInstanceId || !$hasPacsStudyId) {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ❌ PROBLEMA: Faltan columnas PACS críticas');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Ejecutar script: database/crear_todas_columnas_pacs.sql');
                    }
                } catch (Exception $e) {
                    error_log('[SEND_TO_PACS][GUARDADO_BD] Error verificando columnas: ' . $e->getMessage());
                }
                
                // Construir UPDATE dinámicamente según columnas disponibles
                try {
                    $updateFields = [];
                    $updateValues = [];
                    
                    // Agregar fecha_enviado_pacs solo si existe
                    if ($hasFechaEnviadoPacs) {
                        $updateFields[] = "fecha_enviado_pacs = NOW()";
                    }
                    
                    // Agregar pacs_instance_id solo si existe
                    if ($hasPacsInstanceId) {
                        $updateFields[] = "pacs_instance_id = ?";
                        $updateValues[] = $result['instance_id'] ?? null;
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Incluyendo pacs_instance_id en UPDATE: ' . ($result['instance_id'] ?? 'NULL'));
                    } else {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ❌ PROBLEMA: Columna pacs_instance_id NO existe - no se incluirá en UPDATE');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Ejecutar: ALTER TABLE informes ADD COLUMN pacs_instance_id VARCHAR(255) DEFAULT NULL;');
                    }
                    
                    // Agregar pacs_study_id solo si existe
                    if ($hasPacsStudyId) {
                        $updateFields[] = "pacs_study_id = ?";
                        $updateValues[] = $result['study_id'] ?? null;
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Incluyendo pacs_study_id en UPDATE: ' . ($result['study_id'] ?? 'NULL'));
                    } else {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ❌ PROBLEMA: Columna pacs_study_id NO existe - no se incluirá en UPDATE');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Ejecutar: ALTER TABLE informes ADD COLUMN pacs_study_id VARCHAR(255) DEFAULT NULL;');
                    }
                    
                    // Agregar pacs_series_id solo si existe
                    if ($hasPacsSeriesId) {
                        $updateFields[] = "pacs_series_id = ?";
                        $updateValues[] = $seriesId; // Puede ser NULL, pero se guardará para marcar que se intentó
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Incluyendo pacs_series_id en UPDATE');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Valor seriesId a guardar: ' . ($seriesId ?? 'NULL') . ' (tipo: ' . gettype($seriesId) . ')');
                        if (empty($seriesId)) {
                            error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ ADVERTENCIA: seriesId está vacío/NULL - se guardará NULL en pacs_series_id');
                            error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ Esto puede indicar que Orthanc no devolvió ParentSeries en la respuesta');
                        }
                    } else {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ❌ PROBLEMA: Columna pacs_series_id NO existe - no se incluirá en UPDATE');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ El Series ID NO se guardará porque la columna no existe');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Para crear columna, ejecutar:');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ALTER TABLE informes ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL AFTER pacs_study_id;');
                    }
                    
                    // Agregar pdf_path solo si existe (ruta relativa al PDF generado)
                    if ($hasPdfPath) {
                        $relativePdfPath = $relativePdfPathForUpdate;
                        $updateFields[] = "pdf_path = ?";
                        $updateValues[] = $relativePdfPath;
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Incluyendo pdf_path en UPDATE: ' . $relativePdfPath);
                    } else {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ Columna pdf_path NO existe - no se incluirá en UPDATE');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Para crear columna, ejecutar:');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ALTER TABLE informes ADD COLUMN pdf_path VARCHAR(500) DEFAULT NULL COMMENT "Ruta relativa del PDF del informe para visualización/descarga";');
                    }
                    
                    // Agregar WHERE
                    $updateValues[] = $informeId;
                    
                    // Construir query final
                    $updateQuery = "UPDATE informes SET " . implode(', ', $updateFields) . " WHERE id = ?";
                    error_log('[SEND_TO_PACS][GUARDADO_BD] Query construido: ' . $updateQuery);
                    
                    $updateStmt = $db->prepare($updateQuery);
                    $updateResult = $updateStmt->execute($updateValues);
                    
                    $rowsAffected = $updateStmt->rowCount();
                    error_log('[SEND_TO_PACS][GUARDADO_BD] ✅ UPDATE ejecutado exitosamente');
                    error_log('[SEND_TO_PACS][GUARDADO_BD] Filas afectadas: ' . $rowsAffected);

                    try {
                        require_once __DIR__ . '/../estudios/sla_helper.php';
                        $infRow = $db->prepare('SELECT id, estudio_id, study_id, study_instance_uid, fecha_enviado_pacs FROM informes WHERE id = ?');
                        $infRow->execute([$informeId]);
                        $infData = $infRow->fetch(PDO::FETCH_ASSOC);
                        if ($infData) {
                            sla_mark_publicado_for_informe($db, $infData);
                        }
                    } catch (Throwable $slaEx) {
                        error_log('[SEND_TO_PACS] sla publicado: ' . $slaEx->getMessage());
                    }

                    // Envío PDF a Gasalud (solo plataforma; no externo/API recibidos)
                    try {
                        require_once __DIR__ . '/gasalud_envio_helper.php';
                        $origenChk = $db->prepare('SELECT origen FROM informes WHERE id = ?');
                        $origenChk->execute([$informeId]);
                        $origenVal = strtolower((string)($origenChk->fetchColumn() ?: 'plataforma'));
                        if ($origenVal !== 'externo') {
                            $g = gasalud_try_send_informe($db, (int)$informeId, 'al_pacs');
                            error_log('[SEND_TO_PACS][GASALUD] ' . ($g['message'] ?? json_encode($g)));
                        } else {
                            error_log('[SEND_TO_PACS][GASALUD] omitido origen=externo');
                        }
                    } catch (Throwable $gEx) {
                        error_log('[SEND_TO_PACS][GASALUD] error: ' . $gEx->getMessage());
                    }
                    
                    if ($hasPacsSeriesId) {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] Series ID guardado en pacs_series_id: ' . ($seriesId ?? 'NULL'));
                    } else {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ Series ID NO se guardó porque la columna pacs_series_id no existe');
                    }
                    
                    // Verificar que se guardó correctamente (solo si la columna existe)
                    if ($hasPacsSeriesId && !empty($seriesId)) {
                        $verifyQuery = "SELECT pacs_series_id FROM informes WHERE id = ?";
                        $verifyStmt = $db->prepare($verifyQuery);
                        $verifyStmt->execute([$informeId]);
                        $verifyResult = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                        $savedSeriesId = $verifyResult['pacs_series_id'] ?? null;
                        
                        if ($savedSeriesId === $seriesId) {
                            error_log('[SEND_TO_PACS][GUARDADO_BD] ✅ VERIFICACIÓN: Series ID guardado correctamente en BD: ' . $savedSeriesId);
                        } else {
                            error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ VERIFICACIÓN: Series ID NO coincide - Esperado: ' . $seriesId . ', Guardado: ' . ($savedSeriesId ?? 'NULL'));
                        }
                    }
                    
                } catch (PDOException $pdoError) {
                    error_log('[SEND_TO_PACS][GUARDADO_BD] ❌ Error en UPDATE: ' . $pdoError->getMessage());
                    error_log('[SEND_TO_PACS][GUARDADO_BD] Código de error: ' . $pdoError->getCode());
                    error_log('[SEND_TO_PACS][GUARDADO_BD] Stack trace: ' . $pdoError->getTraceAsString());
                    
                    // Si es un error de columna no encontrada, registrar pero no lanzar excepción
                    if (strpos($pdoError->getMessage(), 'Unknown column') !== false) {
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ Error de columna no encontrada - esto no debería pasar ya que verificamos columnas antes');
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ⚠️ Puede haber un problema con la verificación de columnas');
                    } else {
                        // Re-lanzar si es otro tipo de error crítico
                        error_log('[SEND_TO_PACS][GUARDADO_BD] ❌ Error crítico en UPDATE: ' . $pdoError->getMessage());
                        throw $pdoError;
                    }
                }
            } catch (Exception $e) {
                error_log('[SEND_TO_PACS][GUARDADO_BD] ❌ Excepción en guardado de referencias PACS: ' . $e->getMessage());
                error_log('[SEND_TO_PACS][GUARDADO_BD] Stack trace: ' . $e->getTraceAsString());
                // No es crítico, continuar - el informe ya se envió exitosamente a PACS
                // Pero es importante registrar el error para debugging
            }
            
            // Verificar si hubo fallback a PDF (si se intentó PNG pero se envió PDF)
            $attemptedFormat = strtolower($input['format'] ?? 'pdf');
            $hadFallback = ($attemptedFormat === 'jpg' && $format === 'pdf');
            
            $formatText = $format === 'jpg' ? 'como imagen JPG' : 'como PDF';
            $message = $isUpdate 
                ? "Informe actualizado y reenviado exitosamente a PORTAL ESTUDIOS $formatText" 
                : "Informe enviado exitosamente a PORTAL ESTUDIOS $formatText";
            
            // Si hubo fallback, agregar advertencia
            if ($hadFallback) {
                $message .= " (⚠️ Se intentó enviar como PNG pero se envió como PDF debido a que Imagick no está disponible en el servidor web)";
            }

            // Limpiar buffer antes de enviar respuesta exitosa
            // Preparar respuesta - incluir toda la información de result.data si existe (formato JPG)
            $responseData = [
                'success' => true,
                'message' => $message,
                'format' => $format,
                'format_attempted' => $attemptedFormat, // Formato que se intentó
                'had_fallback' => $hadFallback, // Si hubo fallback a PDF
                'is_update' => $isUpdate,
                'old_series_id' => $isUpdate ? $existingSeriesId : null,
                'old_instance_id' => $isUpdate ? $existingInstanceId : null,
                'data' => [
                    'instance_id' => $result['instance_id'] ?? null,
                    'study_id' => $result['study_id'] ?? null,
                    'series_id' => $seriesId ?? null, // Incluir SeriesID en respuesta
                    'file_size_mb' => $result['file_size_mb'] ?? null
                ]
            ];
            
            // Si el formato es JPG, incluir toda la información adicional del método de conversión
            if ($format === 'jpg' && isset($result['data']) && is_array($result['data'])) {
                // Fusionar toda la información de result.data en responseData.data
                $responseData['data'] = array_merge($responseData['data'], $result['data']);
                
                // Agregar información del método de conversión en el nivel raíz también
                if (isset($result['conversion_method'])) {
                    $responseData['conversion_method'] = $result['conversion_method'];
                }
                if (isset($result['data']['conversion_method'])) {
                    $responseData['conversion_method'] = $result['data']['conversion_method'];
                }
                if (isset($result['data']['conversion_method_name'])) {
                    $responseData['conversion_method_name'] = $result['data']['conversion_method_name'];
                }
                
                // Log del método usado
                $methodUsed = $responseData['conversion_method'] ?? 'NO DETECTADO';
                $methodName = $responseData['conversion_method_name'] ?? 'Método desconocido';
                error_log('[SEND_TO_PACS] ===== MÉTODO DE CONVERSIÓN DICOM =====');
                error_log('[SEND_TO_PACS] Método código: ' . $methodUsed);
                error_log('[SEND_TO_PACS] Método nombre: ' . $methodName);
                if (isset($result['data']['pages_count'])) {
                    error_log('[SEND_TO_PACS] Páginas procesadas: ' . $result['data']['pages_count']);
                }
                if (isset($result['data']['dicom_files_count'])) {
                    error_log('[SEND_TO_PACS] Archivos DICOM generados: ' . $result['data']['dicom_files_count']);
                }
                error_log('[SEND_TO_PACS] ===== FIN MÉTODO DE CONVERSIÓN =====');
            }
            
            return $responseData;

        } else {
            error_log('[SEND_TO_PACS][ORTHANC_ERROR] Respuesta completa: ' . json_encode($result));
            $errMsg = $result['error'] ?? 'Error desconocido al enviar a Orthanc';
            throw new Exception($errMsg);
        }

    } catch (Exception $e) {
        throw $e;
    }
    } finally {
        if ($lockAcquired) {
            try {
                $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            } catch (Throwable $_rl) {
            }
        }
    }
}


} // function_exists stps_sendInformeToPacsInternal

