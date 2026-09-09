<?php
/**
 * Script para crear una sesión válida para el usuario root
 */

require_once 'classes/User.php';

try {
    echo "=== Creando sesión para usuario root ===\n";
    
    // Crear instancia de User
    $user = new User();
    
    // Intentar login con credenciales del usuario root
    // Primero necesitamos obtener las credenciales del usuario root
    $db = new PDO('mysql:host=localhost;dbname=TJSMEDICAL', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buscar usuario root
    $query = "SELECT id, nombre, email, password_hash FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $rootUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rootUser) {
        echo "Error: No se encontró usuario root activo\n";
        exit(1);
    }
    
    echo "Usuario root encontrado: {$rootUser['email']}\n";
    
    // Para crear una sesión, necesitamos simular un login exitoso
    // Vamos a crear la sesión directamente usando el método privado
    
    // Crear sesión manualmente usando la lógica del método createSession
    $session_token = bin2hex(random_bytes(32));
    $expiration = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $query = "INSERT INTO sesiones (usuario_id, token_sesion, fecha_expiracion, fecha_creacion, activa) 
              VALUES (?, ?, ?, NOW(), 1)";
    $stmt = $db->prepare($query);
    
    if ($stmt->execute([$rootUser['id'], $session_token, $expiration])) {
        echo "Sesión creada exitosamente!\n";
        echo "Token de sesión: $session_token\n";
        echo "Expira: $expiration\n";
        
        // Validar que la sesión funciona
        $validationResult = $user->validateSession($session_token);
        
        if ($validationResult) {
            echo "\n=== Validación de sesión exitosa ===\n";
            echo "Usuario ID: {$validationResult['id']}\n";
            echo "Nombre: {$validationResult['nombre']}\n";
            echo "Email: {$validationResult['email']}\n";
            echo "Nivel: {$validationResult['nivel']}\n";
            echo "Permisos: {$validationResult['permisos']}\n";
            
            // Guardar el token en un archivo para uso posterior
            file_put_contents('root_session_token.txt', $session_token);
            echo "\nToken guardado en root_session_token.txt\n";
            
        } else {
            echo "Error: La sesión creada no se pudo validar\n";
        }
        
    } else {
        echo "Error: No se pudo crear la sesión\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>