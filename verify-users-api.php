<?php
echo "=== VERIFICACIÓN DE API DE USUARIOS ===\n\n";

echo "🔍 VERIFICANDO API DE GESTIÓN DE USUARIOS:\n\n";

// Verificar si el archivo existe
$apiFile = 'api/users/manage-real-complete.php';
if (file_exists($apiFile)) {
    echo "✅ Archivo API encontrado: $apiFile\n";
} else {
    echo "❌ Archivo API NO encontrado: $apiFile\n";
    echo "📁 Archivos disponibles en api/users/:\n";
    $files = glob('api/users/*.php');
    foreach ($files as $file) {
        echo "   - " . basename($file) . "\n";
    }
    echo "\n";
}

// Verificar configuración de base de datos
echo "🔍 VERIFICANDO CONFIGURACIÓN DE BASE DE DATOS:\n";
$configFile = 'config/database.php';
if (file_exists($configFile)) {
    echo "✅ Archivo de configuración encontrado: $configFile\n";
} else {
    echo "❌ Archivo de configuración NO encontrado: $configFile\n";
}

// Verificar conexión a base de datos
echo "\n🔍 VERIFICANDO CONEXIÓN A BASE DE DATOS:\n";
try {
    require_once 'config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();
    
    if ($pdo) {
        echo "✅ Conexión a base de datos exitosa\n";
        
        // Verificar tabla usuarios
        $stmt = $pdo->query("SHOW TABLES LIKE 'usuarios'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Tabla 'usuarios' existe\n";
            
            // Contar usuarios
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM usuarios WHERE activo = 1");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "📊 Usuarios activos en la base de datos: " . $result['count'] . "\n";
            
            // Mostrar algunos usuarios de ejemplo
            $stmt = $pdo->query("SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE activo = 1 LIMIT 5");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($users) > 0) {
                echo "👥 Usuarios de ejemplo:\n";
                foreach ($users as $user) {
                    echo "   - ID {$user['id']}: {$user['nombre']} {$user['apellido']} ({$user['nivel']}) - {$user['email']}\n";
                }
            }
        } else {
            echo "❌ Tabla 'usuarios' NO existe\n";
        }
    } else {
        echo "❌ No se pudo establecer conexión a base de datos\n";
    }
} catch (Exception $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
}

echo "\n🔍 VERIFICANDO API DIRECTAMENTE:\n";
$apiUrl = 'http://localhost/portal_estudios/api/users/manage-real-complete.php';
echo "🌐 URL de API: $apiUrl\n";

// Intentar hacer una petición HTTP a la API
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 5
    ]
]);

$response = @file_get_contents($apiUrl, false, $context);
if ($response !== false) {
    echo "✅ API responde correctamente\n";
    $data = json_decode($response, true);
    if ($data && isset($data['success']) && $data['success']) {
        echo "✅ API devuelve datos válidos\n";
        if (isset($data['data']['users'])) {
            echo "📊 Usuarios en respuesta API: " . count($data['data']['users']) . "\n";
        }
    } else {
        echo "⚠️ API devuelve respuesta inválida:\n";
        echo "   " . substr($response, 0, 200) . "...\n";
    }
} else {
    echo "❌ API no responde o hay error de conexión\n";
    echo "💡 Verificar que el servidor web esté ejecutándose\n";
}

echo "\n🔧 SOLUCIONES IMPLEMENTADAS:\n";
echo "1. ✅ Función loadUsersForReassign ahora es async\n";
echo "2. ✅ Carga automática desde API de gestión si no hay usuarios\n";
echo "3. ✅ Fallback con usuarios de ejemplo si la API falla\n";
echo "4. ✅ Debugging completo en cada paso\n";
echo "5. ✅ Verificación de elementos DOM\n";

echo "\n🚀 PARA PROBAR:\n";
echo "1. Abrir estudios-manager.html\n";
echo "2. Abrir consola del navegador (F12)\n";
echo "3. Buscar estudios con asignación\n";
echo "4. Hacer clic en botón '↔' amarillo\n";
echo "5. Revisar logs de debugging\n";

echo "\n🔍 LOGS ESPERADOS:\n";
echo "   • '🔍 Debug - loadUsersForReassign ejecutada'\n";
echo "   • '🔍 Debug - Usuarios no disponibles, cargando desde API...'\n";
echo "   • '✅ Debug - Usuarios cargados desde API de gestión: X'\n";
echo "   • '✅ Debug - Dropdown poblado con X usuarios'\n";

echo "\n⚠️ SI SIGUE SIN FUNCIONAR:\n";
echo "   • Verificar que el servidor web esté ejecutándose\n";
echo "   • Verificar que la API responda correctamente\n";
echo "   • Revisar errores en la consola del navegador\n";
echo "   • Los usuarios de ejemplo deberían aparecer como fallback\n";
?>
