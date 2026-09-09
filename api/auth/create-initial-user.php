<?php
/**
 * API para crear el usuario inicial (root) del sistema
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $nombre = trim($input['nombre'] ?? '');
    $apellido = trim($input['apellido'] ?? '');
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    
    // Validaciones
    if (empty($nombre) || empty($apellido) || empty($email) || empty($password)) {
        throw new Exception('Todos los campos son requeridos');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email inválido');
    }
    
    if (strlen($password) < 8) {
        throw new Exception('La contraseña debe tener al menos 8 caracteres');
    }
    
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Verificar que no haya usuarios existentes
    $stmt = $conn->query("SELECT COUNT(*) as count FROM usuarios WHERE activo = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $userCount = $result ? (int)$result['count'] : 0;
    
    if ($userCount > 0) {
        throw new Exception('Ya existen usuarios en el sistema. No se puede crear el usuario inicial.');
    }
    
    // Verificar que el email no esté en uso
    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        throw new Exception('Este email ya está registrado');
    }
    
    // Crear tabla usuarios si no existe (estructura básica, puede necesitar más campos según tu esquema)
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(100) NOT NULL,
                apellido VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                nivel ENUM('root', 'admin', 'medico', 'secretario', 'tecnico') DEFAULT 'medico',
                activo TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        // La tabla puede tener más campos, continuar
        error_log("Nota al crear tabla usuarios: " . $e->getMessage());
    }
    
    // Crear tabla sesiones si no existe
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sesiones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            token VARCHAR(255) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Hash de la contraseña
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insertar usuario root
    // Intentar con campos básicos primero
    try {
        $stmt = $conn->prepare("
            INSERT INTO usuarios (nombre, apellido, email, password_hash, nivel, activo) 
            VALUES (?, ?, ?, ?, 'root', 1)
        ");
        $stmt->execute([$nombre, $apellido, $email, $passwordHash]);
    } catch (PDOException $e) {
        // Si falla, puede ser que la tabla tenga una estructura diferente
        // Intentar con campos mínimos
        try {
            $stmt = $conn->prepare("
                INSERT INTO usuarios (nombre, apellido, email, password_hash, nivel) 
                VALUES (?, ?, ?, ?, 'root')
            ");
            $stmt->execute([$nombre, $apellido, $email, $passwordHash]);
        } catch (PDOException $e2) {
            throw new Exception('Error al insertar usuario: ' . $e2->getMessage());
        }
    }
    
    $userId = $conn->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Usuario root creado exitosamente',
        'user_id' => $userId
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
