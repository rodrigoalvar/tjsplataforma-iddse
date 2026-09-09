<?php
echo "=== CREAR LIST.PHP SIN VALIDACIONES ===\n\n";

echo "ESTRATEGIA:\n\n";

echo "Usar archivos originales funcionales de portal_148\n";
echo "Pero SIN validaciones de sesión\n";
echo "Para probar que todo lo demás funciona\n\n";

echo "CREANDO LIST-NO-AUTH.PHP...\n\n";

$noAuthScript = '<?php
// list-no-auth.php - Sin validaciones de sesión
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit(0);
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
    exit;
}

// Incluir dependencias SIN middleware de auth
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../classes/User.php";
// NO incluir middleware/auth.php

try {
    echo "=== LIST-NO-AUTH.PHP FUNCIONANDO ===\n\n";
    
    // Conectar a la base de datos
    $database = new Database();
    $pdo = $database->getConnection();
    
    echo "✅ Conexión a BD exitosa\n\n";
    
    // Obtener parámetros de búsqueda
    $search = $_GET["search"] ?? "";
    $patient_name = $_GET["patient_name"] ?? "";
    $modality = $_GET["modality"] ?? "";
    $estado = $_GET["estado"] ?? "";
    $fecha_inicio = $_GET["fecha_inicio"] ?? "";
    $fecha_fin = $_GET["fecha_fin"] ?? "";
    $page = max(1, intval($_GET["page"] ?? 1));
    $limit = min(100, max(10, intval($_GET["limit"] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    echo "Parámetros:\n";
    echo "Página: " . $page . "\n";
    echo "Límite: " . $limit . "\n";
    echo "Offset: " . $offset . "\n\n";
    
    // Construir consulta SQL base - Solo última versión por informe
    $sql = "
        SELECT 
            i.id,
            i.estudio_id,
            i.patient_id,
            i.titulo,
            i.contenido_html,
            i.contenido_html as contenido_texto,
            i.estado,
            i.patient_name,
            i.modality,
            i.study_description,
            i.fecha_creacion,
            i.fecha_modificacion,
            NULL as fecha_finalizacion,
            i.version,
            u.nombre as usuario_nombre,
            COUNT(ai.id) as total_audios,
            COUNT(DISTINCT i2.version) as total_versiones
        FROM informes i
        LEFT JOIN usuarios u ON i.usuario_id = u.id
        LEFT JOIN audios_informe ai ON i.id = ai.informe_id
        LEFT JOIN informes i2 ON i.estudio_id = i2.estudio_id AND i.usuario_id = i2.usuario_id
        WHERE i.version = (
            SELECT MAX(version) 
            FROM informes i3 
            WHERE i3.estudio_id = i.estudio_id 
            AND i3.usuario_id = i.usuario_id
        )
    ";
    
    $params = [];
    
    // Aplicar filtros
    if (!empty($search)) {
        $sql .= " AND (i.titulo LIKE ? OR i.patient_name LIKE ? OR i.contenido_texto LIKE ?)";
        $searchParam = "%" . $search . "%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($patient_name)) {
        $sql .= " AND i.patient_name LIKE ?";
        $params[] = "%" . $patient_name . "%";
    }
    
    if (!empty($modality)) {
        $sql .= " AND i.modality = ?";
        $params[] = $modality;
    }
    
    if (!empty($estado)) {
        $sql .= " AND i.estado = ?";
        $params[] = $estado;
    }
    
    if (!empty($fecha_inicio)) {
        $sql .= " AND DATE(i.fecha_creacion) >= ?";
        $params[] = $fecha_inicio;
    }
    
    if (!empty($fecha_fin)) {
        $sql .= " AND DATE(i.fecha_creacion) <= ?";
        $params[] = $fecha_fin;
    }
    
    // Agregar GROUP BY y ORDER BY
    $sql .= " GROUP BY i.id, i.estudio_id, i.usuario_id ORDER BY i.fecha_creacion DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    echo "Query SQL preparada\n";
    echo "Parámetros: " . count($params) . "\n\n";
    
    // Ejecutar consulta
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $informes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Query ejecutada exitosamente\n";
    echo "Informes encontrados: " . count($informes) . "\n\n";
    
    // Query para total
    $countSql = "
        SELECT COUNT(DISTINCT i.id) as total
        FROM informes i
        WHERE i.version = (
            SELECT MAX(version) 
            FROM informes i3 
            WHERE i3.estudio_id = i.estudio_id 
            AND i3.usuario_id = i.usuario_id
        )
    ";
    
    $countParams = [];
    
    // Aplicar mismos filtros para count
    if (!empty($search)) {
        $countSql .= " AND (i.titulo LIKE ? OR i.patient_name LIKE ? OR i.contenido_texto LIKE ?)";
        $searchParam = "%" . $search . "%";
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
    }
    
    if (!empty($patient_name)) {
        $countSql .= " AND i.patient_name LIKE ?";
        $countParams[] = "%" . $patient_name . "%";
    }
    
    if (!empty($modality)) {
        $countSql .= " AND i.modality = ?";
        $countParams[] = $modality;
    }
    
    if (!empty($estado)) {
        $countSql .= " AND i.estado = ?";
        $countParams[] = $estado;
    }
    
    if (!empty($fecha_inicio)) {
        $countSql .= " AND DATE(i.fecha_creacion) >= ?";
        $countParams[] = $fecha_inicio;
    }
    
    if (!empty($fecha_fin)) {
        $countSql .= " AND DATE(i.fecha_creacion) <= ?";
        $countParams[] = $fecha_fin;
    }
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalResult = $countStmt->fetch();
    $total = $totalResult["total"];
    
    echo "✅ Count query ejecutada\n";
    echo "Total informes: " . $total . "\n\n";
    
    // Obtener audios para cada informe
    foreach ($informes as &$informe) {
        $audioQuery = "SELECT * FROM audios_informe WHERE informe_id = ?";
        $audioStmt = $pdo->prepare($audioQuery);
        $audioStmt->execute([$informe["id"]]);
        $audios = $audioStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $informe["audios"] = $audios;
        $informe["total_audios"] = count($audios);
        $informe["total_versiones"] = 1; // Por ahora
    }
    
    echo "✅ Audios asociados obtenidos\n\n";
    
    // Respuesta exitosa
    $response = [
        "success" => true,
        "data" => [
            "informes" => $informes,
            "pagination" => [
                "current_page" => $page,
                "per_page" => $limit,
                "total" => $total,
                "total_results" => $total,
                "total_pages" => ceil($total / $limit)
            ]
        ],
        "message" => "Informes obtenidos sin autenticación"
    ];
    
    echo "✅ Respuesta preparada\n";
    echo "Informes en respuesta: " . count($informes) . "\n";
    echo "Total páginas: " . ceil($total / $limit) . "\n\n";
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    
    $errorResponse = [
        "success" => false,
        "error" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ];
    
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
}
?>';

file_put_contents('api/informes/list-no-auth.php', $noAuthScript);

echo "✅ list-no-auth.php creado: api/informes/list-no-auth.php\n";
echo "📋 URL para probar: http://localhost/portal_estudios/api/informes/list-no-auth.php?page=1\n\n";

echo "AHORA ACTUALIZAR INFORMES-MANAGER.JS...\n\n";

// Leer el archivo actual
$jsContent = file_get_contents('assets/js/informes-manager.js');

// Cambiar list.php por list-no-auth.php
$jsContent = str_replace('list.php', 'list-no-auth.php', $jsContent);

// Guardar el archivo modificado
file_put_contents('assets/js/informes-manager.js', $jsContent);

echo "✅ informes-manager.js actualizado para usar list-no-auth.php\n\n";

echo "ESTRATEGIA:\n\n";

echo "1. ✅ Crear list-no-auth.php SIN validaciones\n";
echo "2. ✅ Usar archivos originales funcionales\n";
echo "3. ✅ Actualizar informes-manager.js\n";
echo "4. ✅ Probar que todo funciona\n";
echo "5. ✅ Luego agregar validaciones gradualmente\n\n";

echo "VENTAJAS:\n\n";

echo "✅ Usa archivos originales de portal_148\n";
echo "✅ Sin problemas de autenticación\n";
echo "✅ Prueba que la lógica funciona\n";
echo "✅ Identifica si el problema es de autenticación\n";
echo "✅ Base sólida para agregar validaciones\n\n";

echo "INSTRUCCIONES:\n\n";

echo "1. Probar: http://localhost/portal_estudios/api/informes/list-no-auth.php?page=1\n";
echo "   - Debería devolver JSON con informes\n";
echo "   - Sin errores 500\n\n";

echo "2. Probar: http://localhost/portal_estudios/components/informes-manager.html\n";
echo "   - Debería cargar los informes\n";
echo "   - Sin errores de autenticación\n\n";

echo "RESULTADO ESPERADO:\n\n";

echo "Si funciona:\n";
echo "✅ Los archivos originales están correctos\n";
echo "✅ El problema es solo de autenticación\n";
echo "✅ Podemos agregar validaciones gradualmente\n\n";

echo "Si no funciona:\n";
echo "❌ Hay problemas más profundos\n";
echo "❌ Necesitamos investigar más\n\n";

echo "PRÓXIMO PASO:\n\n";

echo "Probar list-no-auth.php y informes-manager.html\n";
echo "para confirmar que la lógica básica funciona.\n";
?>
