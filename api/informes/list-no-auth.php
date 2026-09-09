<?php
// list-no-auth.php - Versión ultra simplificada
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit(0);
}

try {
    // Incluir dependencias SIN middleware de auth
    require_once __DIR__ . "/../../config/database.php";
    
    // Conectar a la base de datos
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Obtener parámetros de búsqueda
    $page = max(1, intval($_GET["page"] ?? 1));
    $limit = min(100, max(10, intval($_GET["limit"] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    // Consulta SQL simplificada - Solo campos esenciales
    $sql = "SELECT 
                id, 
                estudio_id, 
                usuario_id, 
                titulo, 
                contenido, 
                estado, 
                patient_id, 
                patient_name, 
                modality, 
                fecha_creacion 
            FROM informes 
            ORDER BY fecha_creacion DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit, $offset]);
    $informes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Consulta para total
    $countSql = "SELECT COUNT(*) as total FROM informes";
    $countStmt = $pdo->query($countSql);
    $total = $countStmt->fetch()["total"];
    
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
        "message" => "Informes obtenidos correctamente"
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>