<?php
/**
 * Proxy inteligente para endpoints DICOMweb
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Detecta automáticamente el tipo de nodo y enruta las peticiones
 */

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../PacsNodeClient.php';

// Verificar autenticación
$user = requirePacsNodesAuth('pacs_nodes_manager');

// Obtener conexión a BD
$db = getDBConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Obtener nodeId de la URL
// URL esperada: /api/pacs-nodes-manager/rs/{nodeId}/studies/...
$requestUri = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));

// Buscar 'rs' en la ruta
$rsIndex = array_search('rs', $pathParts);
if ($rsIndex === false || !isset($pathParts[$rsIndex + 1])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid DICOMweb URL format']);
    exit;
}

$nodeId = $pathParts[$rsIndex + 1];
$remainingPath = array_slice($pathParts, $rsIndex + 2); // Path después de /rs/{nodeId}/

// Obtener nodo
$stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
$stmt->execute([$nodeId]);
$node = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$node) {
    http_response_code(404);
    echo json_encode(['error' => 'Node not found']);
    exit;
}

if (!$node['is_active']) {
    http_response_code(400);
    echo json_encode(['error' => 'Node is inactive']);
    exit;
}

// Construir path DICOMweb
$dicomwebPath = implode('/', $remainingPath);
$queryString = $_SERVER['QUERY_STRING'] ?? '';

// Enrutar según tipo de nodo (no usar solo dicomweb_url: un DIMSE puede tener URL sin proxy directo)
$dicomwebUrl = isset($node['dicomweb_url']) ? trim((string)$node['dicomweb_url']) : '';
$useRemoteDicomweb = ($node['node_type'] === 'dicomweb')
    || ($node['node_type'] === 'hybrid' && $dicomwebUrl !== '');

if ($useRemoteDicomweb) {
    proxyToRemoteDicomweb($node, $dicomwebPath, $queryString);
} elseif ($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid') {
    dimseToDicomweb($db, $node, $dicomwebPath, $queryString);
} elseif ($node['node_type'] === 'local') {
    // Usar Orthanc nativo (soporta DICOMweb)
    orthancLocalWado($node, $dicomwebPath, $queryString);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported node type']);
    exit;
}

/**
 * Proxy directo a nodo DICOMweb remoto
 */
function proxyToRemoteDicomweb($node, $path, $queryString) {
    $baseUrl = rtrim($node['dicomweb_url'], '/');
    $fullUrl = $baseUrl . '/' . $path;
    
    if (!empty($queryString)) {
        $fullUrl .= '?' . $queryString;
    }
    
    // Preparar headers
    $headers = [];
    
    // Determinar Content-Type según el path
    if (strpos($path, '/frames/') !== false) {
        // WADO-RS frames: multipart
        $headers[] = 'Accept: multipart/related; type="application/octet-stream"';
    } elseif (strpos($path, '/instances/') !== false && !strpos($path, '/frames/')) {
        // WADO-RS instance: DICOM o imagen
        $format = $_GET['format'] ?? 'dicom';
        if ($format === 'jpeg' || $format === 'png') {
            $headers[] = 'Accept: image/' . $format;
        } else {
            $headers[] = 'Accept: application/dicom';
        }
    } else {
        // QIDO-RS: JSON
        $headers[] = 'Accept: application/dicom+json';
    }
    
    // Autenticación
    if ($node['dicomweb_auth_type'] === 'basic' && 
        !empty($node['dicomweb_username']) && 
        !empty($node['dicomweb_password'])) {
        // Nota: En producción, necesitaríamos desencriptar la contraseña
        // Por ahora asumimos que está en texto plano (no recomendado)
        $auth = base64_encode($node['dicomweb_username'] . ':' . $node['dicomweb_password']);
        $headers[] = 'Authorization: Basic ' . $auth;
    }
    
    // Hacer proxy
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    // Preservar método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($_POST)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($_POST));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        http_response_code(500);
        echo json_encode(['error' => 'Proxy error: ' . $error]);
        exit;
    }
    
    // Retornar respuesta con headers apropiados
    http_response_code($httpCode);
    if ($contentType) {
        header('Content-Type: ' . $contentType);
    }
    echo $response;
    exit;
}

/**
 * Traducir petición DIMSE a formato DICOMweb
 */
function dimseToDicomweb($db, $node, $path, $queryString) {
    // Parsear path DICOMweb
    $pathParts = explode('/', $path);
    
    if (empty($pathParts[0]) || $pathParts[0] !== 'studies') {
        http_response_code(400);
        echo json_encode(['error' => 'Only /studies endpoint is supported for DIMSE nodes']);
        exit;
    }
    
    // Si es /studies, hacer C-FIND y convertir a formato DICOMweb
    if (count($pathParts) === 1) {
        // QIDO-RS: Listar estudios
        parse_str($queryString, $params);
        
        $query = [
            'Level' => 'Study',
            'Query' => []
        ];
        
        if (isset($params['PatientID'])) {
            $query['Query']['PatientID'] = $params['PatientID'];
        }
        if (isset($params['PatientName'])) {
            $query['Query']['PatientName'] = $params['PatientName'];
        }
        if (isset($params['StudyDate'])) {
            $query['Query']['StudyDate'] = $params['StudyDate'];
        }
        if (isset($params['Modalities'])) {
            $query['Query']['ModalitiesInStudy'] = $params['Modalities'];
        }
        
        try {
            $client = new PacsNodeClient($db);
            $results = $client->executeCFind($node, $query);
            
            // Convertir a formato DICOMweb JSON
            $dicomwebResults = [];
            foreach ($results as $result) {
                $dicomwebResults[] = convertToDicomwebFormat($result);
            }
            
            header('Content-Type: application/dicom+json');
            echo json_encode($dicomwebResults);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    } else {
        http_response_code(501);
        echo json_encode(['error' => 'Nested paths not yet supported for DIMSE nodes']);
        exit;
    }
}

/**
 * Usar Orthanc nativo (soporta DICOMweb)
 */
function orthancLocalWado($node, $path, $queryString) {
    require_once __DIR__ . '/../../../api/config/orthanc_config.php';
    
    $orthancBaseUrl = OrthancConfig::getServerUrl();
    $credentials = OrthancConfig::getCredentials();
    
    $fullUrl = $orthancBaseUrl . '/dicom-web/' . $path;
    if (!empty($queryString)) {
        $fullUrl .= '?' . $queryString;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    http_response_code($httpCode);
    if ($contentType) {
        header('Content-Type: ' . $contentType);
    }
    echo $response;
    exit;
}

/**
 * Convierte resultado estándar a formato DICOMweb JSON
 */
function convertToDicomwebFormat($result) {
    return [
        '00080020' => ['Value' => [$result['StudyDate'] ?? '']],
        '00080030' => ['Value' => [$result['StudyTime'] ?? '']],
        '00100010' => ['Value' => [['Alphabetic' => $result['PatientName'] ?? '']]],
        '00100020' => ['Value' => [$result['PatientID'] ?? '']],
        '00100030' => ['Value' => [$result['PatientBirthDate'] ?? '']],
        '0020000D' => ['Value' => [$result['StudyInstanceUID'] ?? '']],
        '00081030' => ['Value' => [$result['StudyDescription'] ?? '']],
        '00080050' => ['Value' => [$result['AccessionNumber'] ?? '']],
        '00080061' => ['Value' => [$result['ModalitiesInStudy'] ?? '']],
        '00201206' => ['Value' => [$result['NumberOfStudyRelatedSeries'] ?? 0]],
        '00201208' => ['Value' => [$result['NumberOfStudyRelatedInstances'] ?? 0]]
    ];
}
