<?php
/**
 * Script de prueba para simular la consulta de list.php para un usuario específico
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "=== TEST DE LIST.PHP PARA USUARIO ESPECÍFICO ===\n\n";

require_once __DIR__ . '/../../config/database.php';

$email = 'kirylukfranco@gmail.com';

try {
    $db = getDBConnection();
    
    if (!$db) {
        echo "❌ Error: No se pudo conectar a la base de datos\n";
        exit(1);
    }
    
    echo "✅ Conexión a BD exitosa\n\n";
    
    // Obtener información del usuario
    $stmt = $db->prepare("SELECT id, nombre, apellido, email, padre_id, activo, permisos FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "❌ Usuario no encontrado\n";
        exit(1);
    }
    
    $user_id = $user['id'];
    $user_padre_id = $user['padre_id'];
    
    echo "Usuario: {$user['nombre']} {$user['apellido']} (ID: $user_id)\n";
    echo "Padre ID: " . ($user_padre_id ?? 'NULL') . "\n\n";
    
    // Verificar permisos
    $user_permisos = json_decode($user['permisos'], true) ?: [];
    $can_view_all = in_array('all', $user_permisos) || in_array('verTodosInformes', $user_permisos);
    $has_gestion_informes = in_array('all', $user_permisos) || in_array('gestionInformes', $user_permisos);
    
    echo "Permisos:\n";
    echo "  - can_view_all: " . ($can_view_all ? 'Sí' : 'No') . "\n";
    echo "  - has_gestion_informes: " . ($has_gestion_informes ? 'Sí' : 'No') . "\n\n";
    
    // Cargar la función buildUserHierarchyFilter
    require_once __DIR__ . '/list.php';
    
    echo "=== CONSTRUYENDO FILTRO DE JERARQUÍA ===\n";
    $startTime = microtime(true);
    
    try {
        $hierarchyFilter = buildUserHierarchyFilter($db, $user_id, $user_padre_id, $can_view_all, $has_gestion_informes);
        $userFilterCondition = $hierarchyFilter['condition'];
        $params = $hierarchyFilter['params'];
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        echo "✅ Filtro construido en {$duration}ms\n";
        echo "Condición: " . ($userFilterCondition ?: '(vacía)') . "\n";
        echo "Parámetros: " . count($params) . "\n";
        echo "Detalles: " . json_encode($params, JSON_PRETTY_PRINT) . "\n\n";
    } catch (Exception $e) {
        echo "❌ Error construyendo filtro: " . $e->getMessage() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
        exit(1);
    }
    
    // Probar la consulta COUNT
    echo "=== PROBANDO CONSULTA COUNT ===\n";
    $countQuery = "SELECT COUNT(*) as total 
                   FROM informes i
                   WHERE 1=1";
    $countQuery .= $userFilterCondition;
    
    echo "Query: $countQuery\n";
    echo "Params: " . json_encode($params) . "\n\n";
    
    try {
        $startTime = microtime(true);
        $countStmt = $db->prepare($countQuery);
        
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $countStmt->bindValue($key, $value, $paramType);
        }
        
        $countStmt->execute();
        $totalResults = $countStmt->fetch()['total'];
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        echo "✅ COUNT ejecutado en {$duration}ms\n";
        echo "Total de resultados: $totalResults\n\n";
    } catch (Exception $e) {
        echo "❌ Error en COUNT: " . $e->getMessage() . "\n";
        echo "SQL State: " . $e->getCode() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
        exit(1);
    }
    
    // Probar la consulta DATA (limitada a 5 resultados)
    echo "=== PROBANDO CONSULTA DATA (limitada a 5) ===\n";
    
    $selectFields = [
        'i.id',
        'i.estudio_id',
        'i.study_instance_uid',
        'i.study_id',
        'i.patient_id',
        'i.patient_name',
        'i.titulo',
        'i.estado',
        'i.modality',
        'i.fecha_creacion',
        'i.usuario_id'
    ];
    
    $dataQuery = "SELECT " . implode(', ', $selectFields) . "
                  FROM informes i
                  WHERE 1=1";
    $dataQuery .= $userFilterCondition;
    $dataQuery .= " ORDER BY i.fecha_modificacion DESC LIMIT 5";
    
    echo "Query: $dataQuery\n";
    echo "Params: " . json_encode($params) . "\n\n";
    
    try {
        $startTime = microtime(true);
        $dataStmt = $db->prepare($dataQuery);
        
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $dataStmt->bindValue($key, $value, $paramType);
        }
        
        $dataStmt->execute();
        $informes = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        echo "✅ DATA ejecutado en {$duration}ms\n";
        echo "Informes encontrados: " . count($informes) . "\n";
        
        if (count($informes) > 0) {
            echo "\nPrimeros 3 informes:\n";
            foreach (array_slice($informes, 0, 3) as $informe) {
                echo "  - ID: {$informe['id']}, Título: {$informe['titulo']}, Paciente: {$informe['patient_name']}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error en DATA: " . $e->getMessage() . "\n";
        echo "SQL State: " . $e->getCode() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
        exit(1);
    }
    
    echo "\n=== TEST COMPLETADO EXITOSAMENTE ===\n";
    
} catch (Exception $e) {
    echo "❌ Error general: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>



