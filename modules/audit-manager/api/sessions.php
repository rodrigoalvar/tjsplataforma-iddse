<?php
/**
 * Compatibilidad con clientes antiguos: equivalente a connections.php?active_only=1 sin filtro de fechas.
 */
$_GET['active_only'] = '1';
unset($_GET['from'], $_GET['to']);
if (!isset($_GET['limit'])) {
    $_GET['limit'] = '2000';
}
require __DIR__ . '/connections.php';
