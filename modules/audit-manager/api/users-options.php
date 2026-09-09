<?php
/**
 * Listado compacto de usuarios activos (desplegable en auditoría).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/_common.php';
auditManagerRequireAuditor();

$db = getDBConnection();
$limit = max(1, min(8000, (int) ($_GET['limit'] ?? 5000)));

$stmt = $db->prepare(
    'SELECT id, nombre, apellido, email FROM usuarios WHERE activo = 1 ORDER BY apellido ASC, nombre ASC LIMIT ' . (int) $limit
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$users = [];
foreach ($rows as $r) {
    $ap = trim((string) ($r['apellido'] ?? ''));
    $nm = trim((string) ($r['nombre'] ?? ''));
    $nom = $ap !== '' && $nm !== '' ? $ap . ', ' . $nm : ($ap !== '' ? $ap : $nm);
    $mail = $r['email'] ?? '';
    $label = $nom !== '' ? $nom : ('#' . $r['id']);
    if ($mail !== '') {
        $label .= ' · ' . $mail;
    }
    $users[] = [
        'id' => (int) $r['id'],
        'label' => $label,
        'nombre' => $r['nombre'],
        'apellido' => $r['apellido'],
        'email' => $mail,
    ];
}

echo json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
