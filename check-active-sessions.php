<?php
// Verificar sesiones activas
echo "=== VERIFICACIÓN DE SESIONES ACTIVAS ===\n\n";

try {
    require_once 'config/database.php';
    $db = getDBConnection();
    
    echo "🔍 SESIONES ACTIVAS EN VERSIÓN ACTUAL:\n\n";
    
    // Buscar sesiones activas y no expiradas
    $query = "SELECT s.*, u.email, u.nombre 
              FROM sesiones s
              JOIN usuarios u ON s.usuario_id = u.id
              WHERE s.activa = 1 
              AND s.fecha_expiracion > NOW()
              ORDER BY s.fecha_creacion DESC
              LIMIT 5";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($activeSessions) > 0) {
        echo "✅ Sesiones activas encontradas: " . count($activeSessions) . "\n\n";
        
        foreach ($activeSessions as $session) {
            echo "📋 Sesión ID: " . $session['id'] . "\n";
            echo "   • Usuario: " . $session['nombre'] . " (" . $session['email'] . ")\n";
            echo "   • Token: " . substr($session['token_sesion'], 0, 20) . "...\n";
            echo "   • Creada: " . $session['fecha_creacion'] . "\n";
            echo "   • Expira: " . $session['fecha_expiracion'] . "\n";
            echo "   • Activa: " . ($session['activa'] ? 'SÍ' : 'NO') . "\n\n";
        }
    } else {
        echo "❌ NO hay sesiones activas\n\n";
        
        // Verificar sesiones recientes (últimas 24 horas)
        $query = "SELECT s.*, u.email, u.nombre 
                  FROM sesiones s
                  JOIN usuarios u ON s.usuario_id = u.id
                  WHERE s.fecha_creacion > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  ORDER BY s.fecha_creacion DESC
                  LIMIT 5";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($recentSessions) > 0) {
            echo "📋 Sesiones recientes (últimas 24h):\n";
            foreach ($recentSessions as $session) {
                echo "   • ID: " . $session['id'] . ", Usuario: " . $session['email'] . ", Activa: " . ($session['activa'] ? 'SÍ' : 'NO') . "\n";
            }
        } else {
            echo "❌ No hay sesiones recientes\n";
        }
    }
    
    echo "\n🔍 VERIFICANDO COOKIE DE SESIÓN...\n\n";
    
    if (isset($_COOKIE['session_token'])) {
        $cookieToken = $_COOKIE['session_token'];
        echo "✅ Cookie session_token encontrada: " . substr($cookieToken, 0, 20) . "...\n";
        
        // Verificar si este token existe en la BD
        $query = "SELECT s.*, u.email, u.nombre 
                  FROM sesiones s
                  JOIN usuarios u ON s.usuario_id = u.id
                  WHERE s.token_sesion = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$cookieToken]);
        $sessionData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sessionData) {
            echo "✅ Token encontrado en BD:\n";
            echo "   • Usuario: " . $sessionData['nombre'] . " (" . $sessionData['email'] . ")\n";
            echo "   • Activa: " . ($sessionData['activa'] ? 'SÍ' : 'NO') . "\n";
            echo "   • Expira: " . $sessionData['fecha_expiracion'] . "\n";
            echo "   • Válida: " . (strtotime($sessionData['fecha_expiracion']) > time() ? 'SÍ' : 'NO') . "\n";
        } else {
            echo "❌ Token NO encontrado en BD\n";
        }
    } else {
        echo "❌ No hay cookie session_token\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n🎯 DIAGNÓSTICO:\n\n";

echo "Si no hay sesiones activas, el problema es que:\n";
echo "1. ✅ Las sesiones expiraron\n";
echo "2. ✅ El login no está creando sesiones válidas\n";
echo "3. ✅ Las sesiones se están marcando como inactivas\n\n";

echo "📋 SOLUCIÓN:\n\n";

echo "1. ✅ Verificar proceso de login\n";
echo "2. ✅ Verificar creación de sesiones\n";
echo "3. ✅ Verificar expiración de sesiones\n";
echo "4. ✅ Crear sesión válida para testing\n\n";
?>
