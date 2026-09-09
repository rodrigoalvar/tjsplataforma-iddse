<?php
/**
 * Script para corregir permisos de usuarios en la base de datos
 * Asigna permisos por defecto según el nivel del usuario
 */

// Configuración de base de datos
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== CORRECCIÓN DE PERMISOS DE USUARIOS ===\n\n";
    
    // Obtener todos los usuarios
    $stmt = $pdo->query("SELECT id, nombre, apellido, nivel, permisos FROM usuarios ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "👥 Usuarios encontrados: " . count($users) . "\n\n";
    
    $updated = 0;
    
    foreach ($users as $user) {
        echo "👤 Procesando: {$user['nombre']} {$user['apellido']} ({$user['nivel']})\n";
        
        // Verificar si tiene permisos
        $permissions = [];
        if (!empty($user['permisos'])) {
            $permissions = json_decode($user['permisos'], true) ?: [];
        }
        
        if (empty($permissions)) {
            echo "⚠️ Sin permisos asignados, asignando permisos por defecto...\n";
            
            // Asignar permisos según el nivel
            switch ($user['nivel']) {
                case 'root':
                    $permissions = ['all'];
                    break;
                case 'admin':
                    $permissions = ['dashboard', 'estudios', 'pacs_query', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'];
                    break;
                case 'user':
                    $permissions = ['dashboard', 'informes', 'grabacion'];
                    break;
                default:
                    $permissions = ['dashboard'];
            }
            
            // Actualizar en la base de datos
            $updateStmt = $pdo->prepare("UPDATE usuarios SET permisos = ? WHERE id = ?");
            $updateStmt->execute([json_encode($permissions), $user['id']]);
            
            echo "✅ Permisos actualizados: " . implode(', ', $permissions) . "\n";
            $updated++;
        } else {
            echo "✅ Ya tiene permisos: " . implode(', ', $permissions) . "\n";
        }
        
        echo "\n";
    }
    
    echo "📊 RESUMEN:\n";
    echo "   • Total de usuarios: " . count($users) . "\n";
    echo "   • Usuarios actualizados: $updated\n";
    echo "   • Usuarios ya con permisos: " . (count($users) - $updated) . "\n\n";
    
    if ($updated > 0) {
        echo "✅ Corrección completada exitosamente\n";
        echo "   Todos los usuarios ahora tienen permisos asignados.\n";
    } else {
        echo "ℹ️ No se requirieron actualizaciones\n";
        echo "   Todos los usuarios ya tenían permisos asignados.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
