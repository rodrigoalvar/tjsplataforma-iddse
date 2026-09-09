<?php
/**
 * Instalador de Módulos - Migración desde tjsidimagenes a tjsiddse
 * 
 * Este script migra los siguientes módulos:
 * - Módulo de Email (/modules/email/)
 * - Funcionalidades avanzadas del Gestor de Pacientes
 * 
 * @package ModulesMigration
 * @version 1.0
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Definir rutas
define('SOURCE_PATH', '/var/www/tjsidimagenes');
define('TARGET_PATH', '/var/www/tjsiddse');

// Clase para manejar la migración
class ModuleMigrator {
    private $sourceRoot;
    private $targetRoot;
    private $logs = [];
    private $errors = [];
    private $warnings = [];
    
    public function __construct($source, $target) {
        $this->sourceRoot = rtrim($source, '/');
        $this->targetRoot = rtrim($target, '/');
    }
    
    /**
     * Log de mensajes
     */
    public function log($message, $type = 'info') {
        $entry = ['message' => $message, 'type' => $type, 'time' => date('Y-m-d H:i:s')];
        $this->logs[] = $entry;
        
        if ($type === 'error') {
            $this->errors[] = $message;
        } elseif ($type === 'warning') {
            $this->warnings[] = $message;
        }
        
        return $entry;
    }
    
    /**
     * Verificar requisitos
     */
    public function checkRequirements() {
        $requirements = [];
        
        // Verificar que existe el directorio origen
        if (is_dir($this->sourceRoot)) {
            $requirements['source_exists'] = ['status' => true, 'message' => 'Directorio origen existe'];
        } else {
            $requirements['source_exists'] = ['status' => false, 'message' => 'Directorio origen no existe: ' . $this->sourceRoot];
            $this->log('Directorio origen no existe', 'error');
        }
        
        // Verificar que existe el directorio destino
        if (is_dir($this->targetRoot)) {
            $requirements['target_exists'] = ['status' => true, 'message' => 'Directorio destino existe'];
        } else {
            $requirements['target_exists'] = ['status' => false, 'message' => 'Directorio destino no existe: ' . $this->targetRoot];
            $this->log('Directorio destino no existe', 'error');
        }
        
        // Verificar módulo de email en origen
        $emailModulePath = $this->sourceRoot . '/modules/email';
        if (is_dir($emailModulePath)) {
            $requirements['email_module'] = ['status' => true, 'message' => 'Módulo de email encontrado en origen'];
        } else {
            $requirements['email_module'] = ['status' => false, 'message' => 'Módulo de email no encontrado en origen'];
            $this->log('Módulo de email no encontrado en origen', 'error');
        }
        
        // Verificar pacientes-manager en origen
        $pacientesHtml = $this->sourceRoot . '/pacientes-manager.html';
        if (file_exists($pacientesHtml)) {
            $requirements['pacientes_html'] = ['status' => true, 'message' => 'pacientes-manager.html encontrado en origen'];
        } else {
            $requirements['pacientes_html'] = ['status' => false, 'message' => 'pacientes-manager.html no encontrado en origen'];
        }
        
        // Verificar pacientes-manager.js en origen
        $pacientesJs = $this->sourceRoot . '/assets/js/pacientes-manager.js';
        if (file_exists($pacientesJs)) {
            $requirements['pacientes_js'] = ['status' => true, 'message' => 'pacientes-manager.js encontrado en origen'];
        } else {
            $requirements['pacientes_js'] = ['status' => false, 'message' => 'pacientes-manager.js no encontrado en origen'];
        }
        
        // Verificar permisos de escritura
        if (is_writable($this->targetRoot)) {
            $requirements['target_writable'] = ['status' => true, 'message' => 'Directorio destino es escribible'];
        } else {
            $requirements['target_writable'] = ['status' => false, 'message' => 'Directorio destino no es escribible'];
            $this->log('Directorio destino no es escribible', 'error');
        }
        
        return $requirements;
    }
    
    /**
     * Copiar directorio recursivamente
     */
    public function copyDirectory($source, $target) {
        if (!is_dir($source)) {
            $this->log("Directorio origen no existe: $source", 'error');
            return false;
        }
        
        if (!is_dir($target)) {
            if (!mkdir($target, 0755, true)) {
                $this->log("No se pudo crear directorio: $target", 'error');
                return false;
            }
            $this->log("Directorio creado: $target", 'success');
        }
        
        $dir = opendir($source);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $srcPath = $source . '/' . $file;
            $tgtPath = $target . '/' . $file;
            
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $tgtPath);
            } else {
                if (copy($srcPath, $tgtPath)) {
                    $this->log("Copiado: $file", 'success');
                } else {
                    $this->log("Error copiando: $srcPath -> $tgtPath", 'error');
                }
            }
        }
        closedir($dir);
        
        return true;
    }
    
    /**
     * Copiar archivo individual
     */
    public function copyFile($source, $target, $createBackup = true) {
        if (!file_exists($source)) {
            $this->log("Archivo origen no existe: $source", 'error');
            return false;
        }
        
        // Crear backup si el archivo destino existe
        if ($createBackup && file_exists($target)) {
            $backupPath = $target . '.backup_' . date('YmdHis');
            if (copy($target, $backupPath)) {
                $this->log("Backup creado: " . basename($backupPath), 'info');
            }
        }
        
        // Asegurar que el directorio destino existe
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        if (copy($source, $target)) {
            $this->log("Archivo copiado: " . basename($target), 'success');
            return true;
        } else {
            $this->log("Error copiando archivo: " . basename($source), 'error');
            return false;
        }
    }
    
    /**
     * Migrar módulo de email completo
     */
    public function migrateEmailModule() {
        $this->log('=== INICIANDO MIGRACIÓN DEL MÓDULO DE EMAIL ===', 'info');
        
        $sourcePath = $this->sourceRoot . '/modules/email';
        $targetPath = $this->targetRoot . '/modules/email';
        
        // Crear directorio modules si no existe
        $modulesDir = $this->targetRoot . '/modules';
        if (!is_dir($modulesDir)) {
            if (mkdir($modulesDir, 0755, true)) {
                $this->log('Directorio modules creado', 'success');
            } else {
                $this->log('Error creando directorio modules', 'error');
                return false;
            }
        }
        
        // Copiar todo el módulo de email
        if ($this->copyDirectory($sourcePath, $targetPath)) {
            $this->log('Módulo de email copiado exitosamente', 'success');
            
            // Ajustar permisos
            $this->setPermissions($targetPath . '/logs', 0755);
            $this->setPermissions($targetPath . '/config', 0755);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Migrar pacientes-manager.html
     */
    public function migratePacientesManager() {
        $this->log('=== INICIANDO MIGRACIÓN DE PACIENTES-MANAGER ===', 'info');
        
        // Copiar HTML
        $sourceHtml = $this->sourceRoot . '/pacientes-manager.html';
        $targetHtml = $this->targetRoot . '/pacientes-manager.html';
        
        $this->copyFile($sourceHtml, $targetHtml);
        
        // Copiar JS
        $sourceJs = $this->sourceRoot . '/assets/js/pacientes-manager.js';
        $targetJs = $this->targetRoot . '/assets/js/pacientes-manager.js';
        
        $this->copyFile($sourceJs, $targetJs);
        
        $this->log('Pacientes-manager migrado exitosamente', 'success');
        return true;
    }
    
    /**
     * Ajustar permisos de directorios
     */
    public function setPermissions($path, $mode = 0755) {
        if (is_dir($path)) {
            chmod($path, $mode);
            $this->log("Permisos ajustados para: " . basename($path), 'info');
        }
    }
    
    /**
     * Crear tablas en base de datos
     */
    public function createDatabaseTables() {
        $this->log('=== CREANDO TABLAS EN BASE DE DATOS ===', 'info');
        
        try {
            require_once $this->targetRoot . '/config/database.php';
            $pdo = getDBConnection();
            
            if (!$pdo) {
                $this->log('No se pudo conectar a la base de datos', 'error');
                return false;
            }
            
            // Tabla user_smtp_config
            $sql1 = "CREATE TABLE IF NOT EXISTS `user_smtp_config` (
                `id` int NOT NULL AUTO_INCREMENT,
                `usuario_id` int NOT NULL,
                `smtp_host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `smtp_port` int NOT NULL DEFAULT 587,
                `smtp_secure` enum('tls','ssl') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tls',
                `smtp_username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `smtp_password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `from_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `activo` tinyint(1) DEFAULT '1',
                `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `usuario_id` (`usuario_id`),
                KEY `idx_usuario_activo` (`usuario_id`, `activo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($sql1);
            $this->log('Tabla user_smtp_config creada/verificada', 'success');
            
            // Tabla user_email_preferences
            $sql2 = "CREATE TABLE IF NOT EXISTS `user_email_preferences` (
                `id` int NOT NULL AUTO_INCREMENT,
                `usuario_id` int NOT NULL,
                `default_template` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `default_template_medico` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `usuario_id` (`usuario_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($sql2);
            $this->log('Tabla user_email_preferences creada/verificada', 'success');
            
            // Agregar campos médico referente a tabla pacientes si no existen
            $this->addColumnsIfNotExist($pdo, 'pacientes', [
                'medico_referente_nombre' => "ALTER TABLE `pacientes` ADD COLUMN `medico_referente_nombre` varchar(255) DEFAULT NULL",
                'medico_referente_matricula' => "ALTER TABLE `pacientes` ADD COLUMN `medico_referente_matricula` varchar(100) DEFAULT NULL",
                'medico_referente_telefono' => "ALTER TABLE `pacientes` ADD COLUMN `medico_referente_telefono` varchar(50) DEFAULT NULL",
                'medico_referente_email' => "ALTER TABLE `pacientes` ADD COLUMN `medico_referente_email` varchar(255) DEFAULT NULL"
            ]);
            
            // Agregar permisos de funcionalidad del módulo de email
            $this->addPermissionIfNotExist($pdo, 'administracion_email', 'Administración de Email', 'mensajeria');
            $this->addPermissionIfNotExist($pdo, 'envios_email', 'Envíos por Email', 'mensajeria');
            $this->addPermissionIfNotExist($pdo, 'envios_whatsapp', 'Envíos por WhatsApp', 'mensajeria');
            
            // Agregar permiso de interfaz (GUI) para mostrar en el sidebar
            $this->addPermissionIfNotExist($pdo, 'gui_gestion_mensajes', 'Gestión Mensajes Visible', 'interfaz');
            
            return true;
            
        } catch (Exception $e) {
            $this->log('Error en base de datos: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Agregar columnas si no existen
     */
    private function addColumnsIfNotExist($pdo, $table, $columns) {
        foreach ($columns as $column => $alterSql) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                if ($check->rowCount() == 0) {
                    $pdo->exec($alterSql);
                    $this->log("Columna '$column' agregada a tabla '$table'", 'success');
                } else {
                    $this->log("Columna '$column' ya existe en '$table'", 'info');
                }
            } catch (Exception $e) {
                $this->log("Error agregando columna '$column': " . $e->getMessage(), 'warning');
            }
        }
    }
    
    /**
     * Agregar permiso si no existe
     */
    private function addPermissionIfNotExist($pdo, $permiso, $descripcion, $categoria) {
        try {
            // Verificar si la tabla system_permissions existe
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'system_permissions'");
            if ($tableCheck->rowCount() > 0) {
                $check = $pdo->prepare("SELECT id FROM system_permissions WHERE permission_key = ?");
                $check->execute([$permiso]);
                
                if ($check->rowCount() == 0) {
                    // Usar la estructura correcta de la tabla: permission_key, permission_name, description, category
                    $insert = $pdo->prepare("INSERT INTO system_permissions (permission_key, permission_name, description, category) VALUES (?, ?, ?, ?)");
                    $insert->execute([$permiso, $descripcion, $descripcion, $categoria]);
                    $this->log("Permiso '$permiso' agregado al sistema", 'success');
                } else {
                    $this->log("Permiso '$permiso' ya existe", 'info');
                }
            } else {
                $this->log("Tabla system_permissions no existe, omitiendo permisos", 'warning');
            }
        } catch (Exception $e) {
            $this->log("Error agregando permiso '$permiso': " . $e->getMessage(), 'warning');
        }
    }
    
    /**
     * Ejecutar migración completa
     */
    public function runFullMigration() {
        $results = [];
        
        // 1. Verificar requisitos
        $results['requirements'] = $this->checkRequirements();
        
        // Verificar si hay errores críticos
        $hasErrors = false;
        foreach ($results['requirements'] as $req) {
            if (!$req['status'] && strpos($req['message'], 'origen') !== false) {
                $hasErrors = true;
                break;
            }
        }
        
        if ($hasErrors) {
            $this->log('Migración abortada: requisitos no cumplidos', 'error');
            return ['success' => false, 'results' => $results, 'logs' => $this->logs, 'errors' => $this->errors];
        }
        
        // 2. Migrar módulo de email
        $results['email_module'] = $this->migrateEmailModule();
        
        // 3. Migrar pacientes-manager
        $results['pacientes_manager'] = $this->migratePacientesManager();
        
        // 4. Crear tablas en BD
        $results['database'] = $this->createDatabaseTables();
        
        // 5. Resumen
        $this->log('=== MIGRACIÓN COMPLETADA ===', 'success');
        
        return [
            'success' => empty($this->errors),
            'results' => $results,
            'logs' => $this->logs,
            'errors' => $this->errors,
            'warnings' => $this->warnings
        ];
    }
    
    public function getLogs() { return $this->logs; }
    public function getErrors() { return $this->errors; }
    public function getWarnings() { return $this->warnings; }
}

// HTML Interface
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador de Módulos - Migración</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #8B0D32 0%, #A91B47 50%, #667eea 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #8B0D32 0%, #A91B47 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 1.8em; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        .content { padding: 30px; }
        .section {
            margin: 20px 0;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .section h3 { margin-bottom: 15px; color: #333; }
        .step {
            padding: 12px 15px;
            margin: 10px 0;
            border-left: 4px solid #ddd;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .step.success { border-left-color: #28a745; background: #d4edda; }
        .step.error { border-left-color: #dc3545; background: #f8d7da; }
        .step.warning { border-left-color: #ffc107; background: #fff3cd; }
        .step.info { border-left-color: #17a2b8; background: #d1ecf1; }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #8B0D32;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
            transition: background 0.3s;
        }
        .btn:hover { background: #A91B47; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-secondary { background: #6c757d; }
        .modules-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .module-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            transition: border-color 0.3s;
        }
        .module-card:hover { border-color: #8B0D32; }
        .module-card h4 { color: #8B0D32; margin-bottom: 10px; }
        .module-card p { font-size: 0.9em; color: #666; }
        .module-card ul { margin: 10px 0; padding-left: 20px; font-size: 0.85em; }
        .summary-box {
            background: linear-gradient(135deg, #e7f3ff 0%, #f0e7ff 100%);
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .log-container {
            max-height: 400px;
            overflow-y: auto;
            background: #1a1a2e;
            color: #eee;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
        }
        .log-entry { padding: 3px 0; }
        .log-success { color: #28a745; }
        .log-error { color: #dc3545; }
        .log-warning { color: #ffc107; }
        .log-info { color: #17a2b8; }
        .checkbox-group { margin: 15px 0; }
        .checkbox-group label {
            display: block;
            padding: 8px 0;
            cursor: pointer;
        }
        .checkbox-group input[type="checkbox"] {
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Instalador de Módulos</h1>
            <p>Migración de funcionalidades desde tjsidimagenes a tjsiddse</p>
        </div>
        <div class="content">
            <?php
            $step = isset($_GET['step']) ? $_GET['step'] : 'intro';
            $migrator = new ModuleMigrator(SOURCE_PATH, TARGET_PATH);
            
            if ($step === 'intro') {
                ?>
                <div class="section">
                    <h3>📋 Descripción de la Migración</h3>
                    <p>Este instalador migrará los siguientes módulos y funcionalidades:</p>
                    
                    <div class="modules-list">
                        <div class="module-card">
                            <h4>📧 Módulo de Email</h4>
                            <p>Sistema completo de gestión de emails</p>
                            <ul>
                                <li>Panel de administración</li>
                                <li>Configuración SMTP por usuario</li>
                                <li>Plantillas de email</li>
                                <li>API de envíos</li>
                                <li>Sistema de logs</li>
                            </ul>
                        </div>
                        
                        <div class="module-card">
                            <h4>👥 Gestor de Pacientes Avanzado</h4>
                            <p>Funcionalidades adicionales</p>
                            <ul>
                                <li>Envío de emails a pacientes</li>
                                <li>Envío de WhatsApp</li>
                                <li>Médico referente</li>
                                <li>Plantillas configurables</li>
                                <li>Búsqueda de estudios mejorada</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <h3>⚙️ Verificación de Requisitos</h3>
                    <?php
                    $requirements = $migrator->checkRequirements();
                    foreach ($requirements as $key => $req) {
                        $class = $req['status'] ? 'success' : 'error';
                        $icon = $req['status'] ? '✓' : '✗';
                        echo "<div class='step $class'>$icon {$req['message']}</div>";
                    }
                    
                    $canProceed = true;
                    foreach ($requirements as $req) {
                        if (!$req['status']) {
                            $canProceed = false;
                            break;
                        }
                    }
                    ?>
                </div>
                
                <?php if ($canProceed): ?>
                <div class="summary-box">
                    <h3>✅ Todo listo para la migración</h3>
                    <p>Los requisitos están cumplidos. Puedes proceder con la instalación.</p>
                </div>
                <div style="text-align: center;">
                    <a href="?step=confirm" class="btn btn-success">Continuar con la Migración →</a>
                </div>
                <?php else: ?>
                <div class="step error">
                    <strong>⚠️ Requisitos no cumplidos</strong>
                    <p>Por favor, corrige los errores antes de continuar.</p>
                </div>
                <?php endif; ?>
                
                <?php
            } elseif ($step === 'confirm') {
                ?>
                <div class="section">
                    <h3>⚠️ Confirmación</h3>
                    <p>Estás a punto de ejecutar la migración. Esta acción:</p>
                    <ul style="margin: 15px 0; padding-left: 20px;">
                        <li>Creará el directorio <code>/modules/email/</code> y copiará todos los archivos</li>
                        <li>Reemplazará <code>pacientes-manager.html</code> y <code>pacientes-manager.js</code> (se creará backup)</li>
                        <li>Creará tablas necesarias en la base de datos</li>
                        <li>Agregará nuevos permisos al sistema</li>
                    </ul>
                    
                    <div class="step warning">
                        <strong>Nota:</strong> Se crearán backups automáticos de los archivos existentes.
                    </div>
                </div>
                
                <div style="text-align: center;">
                    <a href="?step=intro" class="btn btn-secondary">← Volver</a>
                    <a href="?step=execute" class="btn btn-success">🚀 Ejecutar Migración</a>
                </div>
                <?php
            } elseif ($step === 'execute') {
                ?>
                <div class="section">
                    <h3>🔄 Ejecutando Migración...</h3>
                    <?php
                    $result = $migrator->runFullMigration();
                    
                    echo '<div class="log-container">';
                    foreach ($result['logs'] as $log) {
                        $class = 'log-' . $log['type'];
                        echo "<div class='log-entry $class'>[{$log['time']}] {$log['message']}</div>";
                    }
                    echo '</div>';
                    
                    if ($result['success']) {
                        ?>
                        <div class="summary-box" style="background: #d4edda; margin-top: 20px;">
                            <h3 style="color: #155724;">✅ Migración Completada Exitosamente</h3>
                            <p>Todos los módulos han sido instalados correctamente.</p>
                        </div>
                        
                        <div class="section">
                            <h3>📋 Próximos Pasos</h3>
                            <ol style="padding-left: 20px; line-height: 2;">
                                <li>Accede al <a href="modules/email/admin.php" target="_blank">Panel de Administración de Email</a></li>
                                <li>Configura tu servidor SMTP</li>
                                <li>Crea plantillas de email según tus necesidades</li>
                                <li>Prueba el envío de emails desde <a href="pacientes-manager.html" target="_blank">Gestión de Pacientes</a></li>
                                <li>Asigna permisos a los usuarios desde Gestión de Usuarios</li>
                            </ol>
                        </div>
                        
                        <div style="text-align: center;">
                            <a href="modules/email/admin.php" class="btn btn-success">📧 Ir a Email Admin</a>
                            <a href="pacientes-manager.html" class="btn">👥 Ir a Pacientes</a>
                            <a href="dashboard-unified.html" class="btn btn-secondary">🏠 Dashboard</a>
                        </div>
                        <?php
                    } else {
                        ?>
                        <div class="step error" style="margin-top: 20px;">
                            <h3>❌ Errores durante la migración</h3>
                            <ul>
                                <?php foreach ($result['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        
                        <div style="text-align: center;">
                            <a href="?step=intro" class="btn btn-warning">← Intentar de Nuevo</a>
                        </div>
                        <?php
                    }
                    ?>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</body>
</html>
