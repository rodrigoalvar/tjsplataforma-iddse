<?php
/**
 * Funciones compartidas para listado/export de informes (filtro por jerarquía de usuarios).
 * Incluir desde list.php, export-planilla-cobranza.php, etc.
 */

if (!function_exists('getDescendantUserIds')) {
    /**
     * Obtener todos los IDs de usuarios descendientes (hijos recursivos) de un usuario
     */
    function getDescendantUserIds($db, $userId, $visitedIds = [], $maxDepth = 10, &$totalProcessed = 0, $maxTotal = 1000) {
        if (in_array($userId, $visitedIds)) {
            error_log('[LIST_INFORMES] ⚠️ Loop detectado: usuario ' . $userId . ' ya fue visitado');
            return [];
        }
        if ($maxDepth <= 0) {
            error_log('[LIST_INFORMES] ⚠️ Profundidad máxima alcanzada para usuario ' . $userId);
            return [];
        }
        if ($totalProcessed >= $maxTotal) {
            error_log('[LIST_INFORMES] ⚠️ Límite de descendientes alcanzado (' . $maxTotal . ') para usuario ' . $userId);
            return [];
        }
        $descendantIds = [];
        $visitedIds[] = $userId;
        try {
            $stmt = $db->prepare("SELECT id FROM usuarios WHERE padre_id = ? AND activo = 1 LIMIT 100");
            $stmt->execute([$userId]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $totalProcessed += count($children);
            $descendantIds = array_merge($descendantIds, $children);
            foreach ($children as $childId) {
                if ($totalProcessed >= $maxTotal) {
                    break;
                }
                $grandchildren = getDescendantUserIds($db, $childId, $visitedIds, $maxDepth - 1, $totalProcessed, $maxTotal);
                $descendantIds = array_merge($descendantIds, $grandchildren);
            }
        } catch (Exception $e) {
            error_log('[LIST_INFORMES] ❌ Error en getDescendantUserIds para userId ' . $userId . ': ' . $e->getMessage());
        }
        return $descendantIds;
    }
}

if (!function_exists('listInformes_userHasPermission')) {
    /**
     * Comprueba un permiso en el JSON de usuarios (lista ["a","b"] o mapa {"a":true}).
     */
    function listInformes_userHasPermission($raw, $permissionKey) {
        if ($raw === null || $raw === '') {
            return false;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return false;
        }
        if (array_key_exists('all', $raw) && $raw['all']) {
            return true;
        }
        if (array_key_exists($permissionKey, $raw) && $raw[$permissionKey]) {
            return true;
        }
        return in_array('all', $raw, true) || in_array($permissionKey, $raw, true);
    }
}

if (!function_exists('buildUserHierarchyFilter')) {
    /**
     * @return array{condition: string, params: array}
     */
    function buildUserHierarchyFilter($db, $user_id, $user_padre_id, $can_view_all) {
        $condition = '';
        $params = [];
        if (!$db) {
            error_log('[LIST_INFORMES] Error: Conexión a BD inválida en buildUserHierarchyFilter');
            return ['condition' => '', 'params' => []];
        }
        if ($user_id && $can_view_all) {
            return ['condition' => '', 'params' => []];
        }
        if ($user_id && !$can_view_all) {
            if (!$user_padre_id) {
                try {
                    $totalProcessed = 0;
                    $descendantIds = getDescendantUserIds($db, $user_id, [], 10, $totalProcessed, 1000);
                    $allowedUserIds = array_values(array_unique(array_map('intval', array_merge([$user_id], $descendantIds))));
                    $placeholders = [];
                    foreach ($allowedUserIds as $index => $allowedId) {
                        $paramKey = ':tree_uid_' . $index;
                        $placeholders[] = $paramKey;
                        $params[$paramKey] = $allowedId;
                    }
                    $condition = " AND i.usuario_id IN (" . implode(', ', $placeholders) . ")";
                } catch (Exception $e) {
                    error_log('[LIST_INFORMES] Error obteniendo árbol de cuenta-padre: ' . $e->getMessage());
                    $condition = " AND i.usuario_id = :user_id";
                    $params[':user_id'] = $user_id;
                }
            } else {
                $condition = " AND i.usuario_id = :user_id";
                $params[':user_id'] = $user_id;
            }
        }
        return ['condition' => $condition, 'params' => $params];
    }
}

if (!function_exists('listInformes_canUserAccessInformeById')) {
    /**
     * Comprueba si el usuario puede ver/descargar datos de un informe concreto (misma lógica de jerarquía que list.php).
     *
     * @param PDO $db
     * @param int|null $user_id
     * @param int|null $user_padre_id
     * @param mixed $raw_permisos JSON/array de permisos del usuario
     * @param int $informe_id
     */
    function listInformes_canUserAccessInformeById($db, $user_id, $user_padre_id, $raw_permisos, $informe_id) {
        if (!$db || !$user_id || !$informe_id) {
            return false;
        }
        $informe_id = (int) $informe_id;
        if ($informe_id <= 0) {
            return false;
        }
        $can_view_all = listInformes_userHasPermission($raw_permisos, 'verTodosInformes');
        $hierarchy = buildUserHierarchyFilter($db, (int) $user_id, $user_padre_id, $can_view_all);
        $condition = $hierarchy['condition'];
        $params = array_merge([':informe_id' => $informe_id], $hierarchy['params']);
        $sql = 'SELECT 1 FROM informes i WHERE i.id = :informe_id' . $condition . ' LIMIT 1';
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('[LIST_INFORMES] listInformes_canUserAccessInformeById: ' . $e->getMessage());
            return false;
        }
    }
}
