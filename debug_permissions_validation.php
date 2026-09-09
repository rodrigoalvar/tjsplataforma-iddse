<?php
/**
 * Script para debuggear la validación de permisos
 */

require_once 'classes/User.php';

try {
    echo "=== Debug de validación de permisos ===\n";
    
    // Leer el token de sesión
    $token = trim(file_get_contents('root_session_token.txt'));
    
    // Validar sesión
    $user = new User();
    $userData = $user->validateSession($token);
    
    if (!$userData) {
        echo "Error: Sesión no válida\n";
        exit(1);
    }
    
    echo "Usuario validado:\n";
    echo "ID: {$userData['id']}\n";
    echo "Nombre: {$userData['nombre']}\n";
    echo "Email: {$userData['email']}\n";
    echo "Permisos raw: {$userData['permisos']}\n";
    
    // Decodificar permisos
    $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
    echo "Permisos decodificados: " . print_r($userPermissions, true) . "\n";
    
    // Verificar condiciones específicas
    $hasAll = in_array('all', $userPermissions);
    $hasGestionInformes = in_array('gestionInformes', $userPermissions);
    $canManageAllReports = $hasAll || $hasGestionInformes;
    
    echo "Tiene 'all': " . ($hasAll ? 'SÍ' : 'NO') . "\n";
    echo "Tiene 'gestionInformes': " . ($hasGestionInformes ? 'SÍ' : 'NO') . "\n";
    echo "Puede gestionar todos los informes: " . ($canManageAllReports ? 'SÍ' : 'NO') . "\n";
    
    // Verificar si el informe 35 existe
    $db = new PDO('mysql:host=localhost;dbname=TJSMEDICAL', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "\n=== Verificando informe ID 35 ===\n";
    
    if ($canManageAllReports) {
        echo "Usando query para administrador (sin restricción de usuario)\n";
        $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                  FROM informes i
                  LEFT JOIN usuarios u ON i.usuario_id = u.id
                  WHERE i.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([35]);
    } else {
        echo "Usando query para usuario normal (con restricción de usuario)\n";
        $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                  FROM informes i
                  LEFT JOIN usuarios u ON i.usuario_id = u.id
                  WHERE i.id = ? AND i.usuario_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([35, $userData['id']]);
    }
    
    $informe = $stmt->fetch();
    
    if ($informe) {
        echo "Informe encontrado:\n";
        echo "ID: {$informe['id']}\n";
        echo "Título: {$informe['titulo']}\n";
        echo "Usuario ID: {$informe['usuario_id']}\n";
        echo "Usuario nombre: {$informe['usuario_nombre']}\n";
    } else {
        echo "Informe NO encontrado\n";
        
        // Verificar si existe sin restricciones
        $query = "SELECT i.*, u.nombre as usuario_nombre FROM informes i LEFT JOIN usuarios u ON i.usuario_id = u.id WHERE i.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([35]);
        $informeGeneral = $stmt->fetch();
        
        if ($informeGeneral) {
            echo "El informe existe pero pertenece al usuario ID: {$informeGeneral['usuario_id']}\n";
            echo "Usuario actual ID: {$userData['id']}\n";
        } else {
            echo "El informe no existe en la base de datos\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>