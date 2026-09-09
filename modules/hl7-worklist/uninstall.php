<?php
/**
 * Desinstalación del módulo (no borra worklist ni mensajes históricos).
 * Conserva columnas hl7_* por seguridad (pueden reutilizarse).
 * CLI: php uninstall.php
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "Desinstalación hl7-worklist\n";
echo "No se eliminan columnas worklist_config.hl7_* ni filas worklist.\n";
echo "Detener servicio: systemctl disable --now hl7-mllp-listener\n";
echo "Opcional: desactivar hl7_enabled=0 en Configuración → Worklist\n";
echo "OK\n";
