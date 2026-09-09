<?php
/**
 * Listado y consulta de informes (debug)
 * Permite listar todos los informes o filtrar por IDs: ?ids=12,13,20
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
header('Content-Type: text/html; charset=utf-8');

echo "<h2>Listado de informes - Debug</h2>";

try {
    // 1) Conexión a BD
    require_once 'config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();
    echo "<p style='color: green;'>✓ Conexión a base de datos exitosa</p>";

    // 2) Parseo de parámetros
    $idsParam = isset($_GET['ids']) ? trim($_GET['ids']) : '';
    $idsList = [];
    if ($idsParam !== '') {
        foreach (explode(',', $idsParam) as $idStr) {
            $id = (int) trim($idStr);
            if ($id > 0) { $idsList[] = $id; }
        }
    }

    // 3) Consulta principal
    if (!empty($idsList)) {
        $placeholders = implode(',', array_fill(0, count($idsList), '?'));
        $sql = "SELECT id, titulo, patient_name, estado, fecha_creacion, fecha_modificacion, usuario_id FROM informes WHERE id IN ($placeholders) ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        foreach ($idsList as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        echo "<p>Filtro por IDs: " . htmlspecialchars($idsParam) . "</p>";
    } else {
        $stmt = $pdo->prepare("SELECT id, titulo, patient_name, estado, fecha_creacion, fecha_modificacion, usuario_id FROM informes ORDER BY id ASC");
        $stmt->execute();
        echo "<p>Mostrando todos los informes</p>";
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4) Contar total de informes en la tabla
    $count_stmt = $pdo->query("SELECT COUNT(*) as total FROM informes");
    $count = (int) $count_stmt->fetch()['total'];
    echo "<p>Total de informes en la tabla: {$count}</p>";

    // 5) Render de tabla
    if (empty($rows)) {
        echo "<p>No hay registros para el criterio indicado.</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Título</th><th>Paciente</th><th>Estado</th><th>Fecha creación</th><th>Fecha modificación</th><th>Usuario ID</th><th>¿Se puede eliminar?</th></tr>";
        foreach ($rows as $row) {
            $fecha_creacion = new DateTime($row['fecha_creacion']);
            $ahora = new DateTime();
            $diff = $ahora->diff($fecha_creacion);
            $diferencia_horas = $diff->h + ($diff->days * 24);
            $puede_eliminar = ($row['estado'] === 'borrador') || ($diferencia_horas < 24 && $row['estado'] !== 'finalizado');
            $badge = $puede_eliminar ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>";
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['titulo'] ?? 'Sin título') . "</td>";
            echo "<td>" . htmlspecialchars($row['patient_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['estado'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['fecha_creacion']) . "</td>";
            echo "<td>" . htmlspecialchars($row['fecha_modificacion']) . "</td>";
            echo "<td>" . htmlspecialchars($row['usuario_id']) . "</td>";
            echo "<td>{$badge}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // 6) JSON para copia rápida
    echo "<h3>JSON</h3>";
    echo "<pre>" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>