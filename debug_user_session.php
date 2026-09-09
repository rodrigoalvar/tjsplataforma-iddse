<?php
header('Content-Type: application/json');
session_start();

// Verificar sesión
$response = [
    'session_active' => isset($_SESSION['user_id']),
    'session_data' => $_SESSION ?? [],
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? null,
    'user_permissions' => $_SESSION['user_permissions'] ?? [],
    'has_pacs_query' => false,
    'is_assigned_mode' => false
];

if ($response['session_active']) {
    $permissions = $response['user_permissions'];
    $response['has_pacs_query'] = in_array('pacs_query', $permissions) || in_array('all', $permissions);
    $response['is_assigned_mode'] = !$response['has_pacs_query'];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>