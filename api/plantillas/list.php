<?php
// API para listar plantillas de informes
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * Obtener todos los IDs de usuarios descendientes (hijos recursivos) de un usuario
 * @param PDO $db Conexión a la base de datos
 * @param int $userId ID del usuario padre
 * @return array Array de IDs de usuarios descendientes
 */
function getDescendantUserIds($db, $userId) {
    $descendantIds = [];
    
    // Obtener hijos directos
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE padre_id = ? AND activo = 1");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Agregar hijos directos
    $descendantIds = array_merge($descendantIds, $children);
    
    // Recursivamente obtener descendientes de cada hijo
    foreach ($children as $childId) {
        $grandchildren = getDescendantUserIds($db, $childId);
        $descendantIds = array_merge($descendantIds, $grandchildren);
    }
    
    return $descendantIds;
}

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/plantillas_common.php';
    
    $db = getDBConnection();
    plantillasEnsureTraceColumns($db);
    
    // Verificar sesión y permisos del usuario
    $user_id = null;
    $user_padre_id = null;
    $can_view_all = false;
    $has_plantillas_permission = false;
    
    // Verificar si hay token de autenticación
    $token = null;
    
    // Obtener headers (compatible con servidores que no tienen getallheaders())
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
    }
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    if ($token) {
        try {
            require_once __DIR__ . '/../../classes/User.php';
            $user = new User();
            $user_data = $user->validateSession($token);
            
            if ($user_data && is_array($user_data)) {
                $user_id = $user_data['id'];
                
                // Obtener padre_id del usuario desde la base de datos
                $stmt = $db->prepare("SELECT padre_id FROM usuarios WHERE id = ? AND activo = 1");
                $stmt->execute([$user_id]);
                $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
                $user_padre_id = $user_info ? $user_info['padre_id'] : null;
                
                // Verificar permisos del usuario
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                // Verificar permiso 'plantillas'
                $has_plantillas_permission = in_array('all', $user_permisos) || in_array('plantillas', $user_permisos);
                
                // Verificar permiso 'ver_todas_plantillas' o 'all'
                $can_view_all = in_array('all', $user_permisos) || in_array('ver_todas_plantillas', $user_permisos);
            }
        } catch (Exception $e) {
            error_log('[PLANTILLAS][LIST] Error validando sesión: ' . $e->getMessage());
        }
    }
    
    // Construir consulta según permisos
    $query = "SELECT p.id, p.template_id, p.nombre, p.contenido_html, p.usuario_id, p.creado_por, p.copiado_de,
                     p.activo, p.fecha_creacion, p.fecha_modificacion,
                     CONCAT(COALESCE(uc.nombre,''), ' ', COALESCE(uc.apellido,'')) AS creado_por_nombre,
                     CONCAT(COALESCE(uo.nombre,''), ' ', COALESCE(uo.apellido,'')) AS owner_nombre
              FROM plantillas p
              LEFT JOIN usuarios uc ON uc.id = p.creado_por
              LEFT JOIN usuarios uo ON uo.id = p.usuario_id
              WHERE p.activo = 1";
    
    $params = [];
    
    if ($can_view_all) {
        // Si tiene permiso 'ver_todas_plantillas' o 'all': mostrar TODAS las plantillas
        // No agregar filtro adicional
    } elseif ($has_plantillas_permission && $user_id) {
        // Si tiene permiso 'plantillas' pero NO 'ver_todas_plantillas': 
        // - Si es una cuenta padre: mostrar sus propias plantillas + las de SUS hijos directos solamente
        // - Si es una cuenta hija: mostrar sus propias plantillas + las de SU padre solamente
        
        // Obtener IDs de todos los descendientes directos (solo hijos directos, no descendientes de otros)
        $descendantIds = getDescendantUserIds($db, $user_id);
        
        // Construir lista de IDs permitidos
        $allowedUserIds = [$user_id];
        
        // Si el usuario tiene hijos, agregar solo SUS hijos (ya están en descendantIds)
        if (!empty($descendantIds)) {
            $allowedUserIds = array_merge($allowedUserIds, $descendantIds);
        }
        
        // Si el usuario tiene un padre, agregar SU padre (solo su padre directo)
        if ($user_padre_id) {
            $allowedUserIds[] = $user_padre_id;
        }
        
        // También incluir plantillas del sistema (usuario_id IS NULL)
        $placeholders = implode(',', array_fill(0, count($allowedUserIds), '?'));
        $query .= " AND (p.usuario_id IS NULL OR p.usuario_id IN ($placeholders))";
        
        foreach ($allowedUserIds as $allowedId) {
            $params[] = $allowedId;
        }
    } elseif ($user_id) {
        // Si NO tiene permiso 'plantillas' pero está autenticado:
        // solo mostrar sus propias plantillas + las del sistema
        $query .= " AND (p.usuario_id IS NULL OR p.usuario_id = ?)";
        $params[] = $user_id;
    } else {
        // Si no hay usuario autenticado, no mostrar ninguna plantilla
        $query .= " AND 1 = 0"; // Condición imposible
    }
    
    $query .= " ORDER BY p.usuario_id IS NULL DESC, p.nombre ASC";
    
    $stmt = $db->prepare($query);
    
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $plantillas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convertir a formato esperado por el frontend
    $resultado = [];
    foreach ($plantillas as $plantilla) {
        $creadoNombre = trim((string) ($plantilla['creado_por_nombre'] ?? ''));
        $ownerNombre = trim((string) ($plantilla['owner_nombre'] ?? ''));
        $resultado[$plantilla['template_id']] = [
            'id' => $plantilla['template_id'],
            'name' => $plantilla['nombre'],
            'content' => $plantilla['contenido_html'],
            'usuario_id' => $plantilla['usuario_id'],
            'owner_nombre' => $ownerNombre !== '' ? $ownerNombre : null,
            'creado_por' => $plantilla['creado_por'],
            'creado_por_nombre' => $creadoNombre !== '' ? $creadoNombre : null,
            'copiado_de' => $plantilla['copiado_de'],
            'is_own' => ($plantilla['usuario_id'] == $user_id), // Indica si es propia del usuario actual
            'fecha_creacion' => $plantilla['fecha_creacion'],
            'fecha_modificacion' => $plantilla['fecha_modificacion']
        ];
    }
    
    // Flags para UI de copia a dueños
    $userRol = '';
    if ($user_id) {
        try {
            $r = $db->prepare('SELECT rol FROM usuarios WHERE id = ? LIMIT 1');
            $r->execute([$user_id]);
            $userRol = strtolower(trim((string) ($r->fetchColumn() ?: '')));
        } catch (Exception $e) {
            $userRol = '';
        }
    }
    $can_copy_to_owners = $has_plantillas_permission && (
        $can_view_all || $userRol === 'transcriptor'
    );
    
    echo json_encode([
        'success' => true,
        'data' => $resultado,
        'count' => count($resultado),
        'can_view_all' => $can_view_all, // Indica si el usuario puede ver todas las plantillas
        'has_plantillas_permission' => $has_plantillas_permission, // Indica si tiene permiso de gestión de plantillas
        'can_copy_to_owners' => $can_copy_to_owners,
        'user_rol' => $userRol
    ]);
    
} catch (PDOException $e) {
    error_log('[PLANTILLAS][LIST] Error PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('[PLANTILLAS][LIST] Error general: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>

