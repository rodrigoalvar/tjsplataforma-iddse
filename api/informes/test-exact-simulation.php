<?php
// Simulación exacta del entorno de list.php
error_reporting(E_ALL);
ini_set("display_errors", 1);

// Headers como en list.php
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

echo "=== SIMULACIÓN EXACTA DE LIST.PHP ===\n\n";

// Simular cookie
$_COOKIE["session_token"] = "ba22f765b6b44fe4a76d65cf1c957dd8b1e59f98bcff611e39c8669adcd94e32";
$_GET["page"] = "1";

echo "Entorno simulado:\n";
echo "REQUEST_METHOD: " . $_SERVER["REQUEST_METHOD"] . "\n";
echo "GET[page]: " . $_GET["page"] . "\n";
echo "COOKIE[session_token]: " . substr($_COOKIE["session_token"], 0, 20) . "...\n\n";

try {
    echo "PASO 1: Incluir dependencias (como en list.php)\n";
    
    require_once __DIR__ . "/../../config/database.php";
    require_once __DIR__ . "/../../classes/User.php";
    require_once __DIR__ . "/../../middleware/auth.php";
    
    echo "✅ Dependencias incluidas\n\n";
    
    echo "PASO 2: Validar token de sesión (como en list.php)\n";
    
    $token = null;
    
    // Obtener token desde diferentes fuentes (como en list.php)
    if (function_exists("getallheaders")) {
        $headers = getallheaders();
        $token = $headers["Authorization"] ?? null;
    } elseif (isset($_SERVER["HTTP_AUTHORIZATION"])) {
        $token = $_SERVER["HTTP_AUTHORIZATION"];
    }
    
    // Fallback a parámetros GET o cookie (como en list.php)
    if (!$token) {
        $token = $_GET["token"] ?? $_COOKIE["session_token"] ?? null;
    }
    
    echo "Token obtenido: " . ($token ? substr($token, 0, 20) . "..." : "NO ENCONTRADO") . "\n";
    
    if (!$token) {
        echo "❌ Token de autorización requerido\n";
        exit;
    }
    
    // Remover "Bearer " si está presente (como en list.php)
    $token = str_replace("Bearer ", "", $token);
    
    echo "Token procesado: " . substr($token, 0, 20) . "...\n";
    
    if (!validateSessionToken($token)) {
        echo "❌ Token inválido o expirado\n";
        exit;
    }
    
    echo "✅ Token válido\n\n";
    
    echo "PASO 3: Conectar a la base de datos (como en list.php)\n";
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    echo "✅ Conexión a BD exitosa\n\n";
    
    echo "PASO 4: Probar query básica (como en list.php)\n";
    
    $query = "SELECT COUNT(*) as total FROM informes";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch();
    
    echo "✅ Query exitosa: " . $result["total"] . " informes\n\n";
    
    echo "🎯 RESULTADO:\n\n";
    echo "✅ SIMULACIÓN EXITOSA\n";
    echo "✅ Todos los componentes funcionan\n";
    echo "✅ list.php debería funcionar\n\n";
    
    echo "PROBLEMA IDENTIFICADO:\n";
    echo "Si este test funciona pero list.php no, el problema está en:\n";
    echo "1. Headers HTTP ya enviados\n";
    echo "2. Output buffer issues\n";
    echo "3. Configuración del servidor web\n";
    echo "4. Contexto de ejecución diferente\n\n";
    
    // Respuesta JSON como en list.php
    $response = [
        "success" => true,
        "message" => "Simulación exitosa",
        "data" => [
            "total_informes" => $result["total"],
            "token_valid" => true
        ]
    ];
    
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
?>