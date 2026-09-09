<?php
/**
 * Clase User simplificada para manejo de usuarios y autenticación
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once __DIR__ . '/../config/database.php';

class User {
    private $conn;
    private $table_name = "usuarios";
    private $sessions_table = "sesiones";

    /** Cache: columnas opcionales en sesiones (ip_login, fecha_cierre) tras instalar audit-manager */
    private $sesionesColumnsChecked = false;
    private $sesionesHasIpLogin = false;
    private $sesionesHasFechaCierre = false;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    private function refreshSesionesOptionalColumns() {
        if ($this->sesionesColumnsChecked) {
            return;
        }
        $this->sesionesColumnsChecked = true;
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->sessions_table);
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
            $this->sesionesHasIpLogin = in_array('ip_login', $cols, true);
            $this->sesionesHasFechaCierre = in_array('fecha_cierre', $cols, true);
        } catch (Exception $e) {
            error_log("User::refreshSesionesOptionalColumns: " . $e->getMessage());
        }
    }

    private static function getClientIpForSession() {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return null;
    }

    private function attachSessionClientMetadata($session_token) {
        $this->refreshSesionesOptionalColumns();
        if (!$this->sesionesHasIpLogin) {
            return;
        }
        try {
            $ip = self::getClientIpForSession();
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
            $q = "UPDATE " . $this->sessions_table . " SET ip_login = ?, user_agent_login = ? WHERE token_sesion = ?";
            $stmt = $this->conn->prepare($q);
            $stmt->execute([$ip, $ua, $session_token]);
        } catch (Exception $e) {
            error_log("User::attachSessionClientMetadata: " . $e->getMessage());
        }
    }
    
    /**
     * Autenticar usuario - Método simplificado
     */
    public function login($email, $password) {
        try {
            // Buscar usuario activo
            $query = "SELECT id, nombre, apellido, email, telefono, matricula_profesional, password_hash 
                     FROM " . $this->table_name . " 
                     WHERE email = ? AND activo = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([strtolower(trim($email))]);
            
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => 'Credenciales inválidas'];
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verificar contraseña
            if (!password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Credenciales inválidas'];
            }
            
            // Limpiar sesiones expiradas del usuario
            $this->cleanExpiredSessions($user['id']);
            
            // Crear nueva sesión
            $session_token = $this->createSession($user['id']);
            
            if (!$session_token) {
                return ['success' => false, 'message' => 'Error al crear sesión'];
            }
            
            return [
                'success' => true,
                'message' => 'Login exitoso',
                'user' => [
                    'id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'apellido' => $user['apellido'],
                    'email' => $user['email'],
                    'telefono' => $user['telefono'],
                    'matricula_profesional' => $user['matricula_profesional']
                ],
                'session_token' => $session_token
            ];
            
        } catch (Exception $e) {
            error_log("Error en login: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno del servidor'];
        }
    }
    
    /**
     * Validar sesión - Método simplificado
     */
    public function validateSession($session_token) {
        try {
            if (empty($session_token)) {
                return false;
            }
            
            // Buscar sesión activa y no expirada
            $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.telefono, u.matricula_profesional,
                            u.nivel, u.permisos, u.especialidad, s.fecha_expiracion
                     FROM " . $this->sessions_table . " s
                     JOIN " . $this->table_name . " u ON s.usuario_id = u.id
                     WHERE s.token_sesion = ? 
                     AND s.activa = 1 
                     AND u.activo = 1
                     AND s.fecha_expiracion > NOW()";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$session_token]);
            
            if ($stmt->rowCount() === 0) {
                // Limpiar token inválido si existe
                $this->invalidateSession($session_token);
                return false;
            }
            
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Actualizar última actividad
            $this->updateSessionActivity($session_token);
            
            return $user_data;
            
        } catch (Exception $e) {
            error_log("Error en validateSession: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener el timeout de sesión (en horas) para un usuario.
     * Jerarquía: usuario individual → configuración global → 24 h hardcoded.
     * Retorna 0 para sesión permanente (sin expiración).
     */
    private function getSessionTimeoutHours($user_id) {
        try {
            // 1. Verificar si el usuario tiene configuración individual
            $stmt = $this->conn->prepare(
                "SELECT session_timeout_hours FROM " . $this->table_name . " WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && $row['session_timeout_hours'] !== null) {
                return (int) $row['session_timeout_hours'];
            }

            // 2. Leer configuración global desde tabla configuracion
            $stmtCfg = $this->conn->prepare(
                "SELECT valor FROM configuracion WHERE clave = 'session_timeout_horas_default' LIMIT 1"
            );
            $stmtCfg->execute();
            $cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC);

            if ($cfg && is_numeric($cfg['valor'])) {
                return (int) $cfg['valor'];
            }
        } catch (Exception $e) {
            error_log("User::getSessionTimeoutHours: " . $e->getMessage());
        }

        // 3. Fallback: 24 horas
        return 24;
    }

    /**
     * Calcular fecha de expiración según timeout configurado.
     * Con timeout = 0 devuelve una fecha lejana (sesión permanente).
     */
    private function calcExpiration($hours) {
        if ($hours === 0) {
            return '2099-12-31 23:59:59';
        }
        return date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    }

    /**
     * Crear sesión con timeout dinámico por usuario
     */
    private function createSession($user_id) {
        try {
            $session_token = bin2hex(random_bytes(32));
            $hours      = $this->getSessionTimeoutHours($user_id);
            $expiration = $this->calcExpiration($hours);
            
            $query = "INSERT INTO " . $this->sessions_table . " 
                     (usuario_id, token_sesion, fecha_expiracion, fecha_creacion, activa) 
                     VALUES (?, ?, ?, NOW(), 1)";
            
            $stmt = $this->conn->prepare($query);
            
            if ($stmt->execute([$user_id, $session_token, $expiration])) {
                $this->attachSessionClientMetadata($session_token);
                return $session_token;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Error al crear sesión: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Cerrar sesión
     */
    public function logout($session_token) {
        try {
            $this->refreshSesionesOptionalColumns();
            if ($this->sesionesHasFechaCierre) {
                $query = "UPDATE " . $this->sessions_table . " 
                         SET activa = 0, fecha_cierre = NOW() 
                         WHERE token_sesion = ?";
            } else {
                $query = "UPDATE " . $this->sessions_table . " 
                         SET activa = 0 
                         WHERE token_sesion = ?";
            }
            
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([$session_token]);
            
        } catch (Exception $e) {
            error_log("Error en logout: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Limpiar sesiones expiradas
     */
    private function cleanExpiredSessions($user_id = null) {
        try {
            if ($user_id) {
                $query = "UPDATE " . $this->sessions_table . " 
                         SET activa = 0 
                         WHERE usuario_id = ? AND (fecha_expiracion < NOW() OR activa = 0)";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$user_id]);
            } else {
                $query = "UPDATE " . $this->sessions_table . " 
                         SET activa = 0 
                         WHERE fecha_expiracion < NOW()";
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
            }
        } catch (Exception $e) {
            error_log("Error limpiando sesiones: " . $e->getMessage());
        }
    }
    
    /**
     * Invalidar sesión específica
     */
    private function invalidateSession($session_token) {
        try {
            $this->refreshSesionesOptionalColumns();
            if ($this->sesionesHasFechaCierre) {
                $query = "UPDATE " . $this->sessions_table . " 
                         SET activa = 0, fecha_cierre = NOW() 
                         WHERE token_sesion = ?";
            } else {
                $query = "UPDATE " . $this->sessions_table . " 
                         SET activa = 0 
                         WHERE token_sesion = ?";
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$session_token]);
        } catch (Exception $e) {
            error_log("Error invalidando sesión: " . $e->getMessage());
        }
    }
    
    /**
     * Actualizar actividad de sesión con sliding expiration.
     * Si el usuario tiene timeout > 0, extiende fecha_expiracion desde NOW().
     * Si tiene timeout = 0 (permanente), solo actualiza ultima_actividad.
     */
    private function updateSessionActivity($session_token) {
        try {
            // Obtener usuario_id para conocer su timeout configurado
            $stmtUser = $this->conn->prepare(
                "SELECT usuario_id FROM " . $this->sessions_table . " WHERE token_sesion = ? LIMIT 1"
            );
            $stmtUser->execute([$session_token]);
            $row = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return;
            }

            $hours = $this->getSessionTimeoutHours((int) $row['usuario_id']);

            if ($hours === 0) {
                // Sesión permanente: solo actualizar actividad, no tocar fecha_expiracion
                $query = "UPDATE " . $this->sessions_table . "
                         SET ultima_actividad = NOW()
                         WHERE token_sesion = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$session_token]);
            } else {
                // Sesión deslizante: extender expiración y actualizar actividad
                $newExpiration = $this->calcExpiration($hours);
                $query = "UPDATE " . $this->sessions_table . "
                         SET ultima_actividad = NOW(), fecha_expiracion = ?
                         WHERE token_sesion = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$newExpiration, $session_token]);
            }
        } catch (Exception $e) {
            error_log("Error actualizando actividad: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar nuevo usuario (actualizado para jerarquías)
     */
    public function register() {
        try {
            // Verificar si el email ya existe
            if ($this->emailExists()) {
                return ['success' => false, 'message' => 'El email ya está registrado'];
            }
            
            // Verificar si la matrícula ya existe
            if ($this->matriculaExists()) {
                return ['success' => false, 'message' => 'La matrícula profesional ya está registrada'];
            }
            
            // Preparar permisos por defecto según nivel
            $permisos = $this->getDefaultPermissions($this->nivel ?? 'user');
            
            $query = "INSERT INTO " . $this->table_name . "
                    (nombre, apellido, email, telefono, matricula_profesional, password_hash, 
                     nivel, padre_id, especialidad, permisos, fecha_creacion, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)";
            
            $stmt = $this->conn->prepare($query);
            $password_hash = password_hash($this->password, PASSWORD_DEFAULT);
            
            if ($stmt->execute([
                $this->nombre,
                $this->apellido,
                strtolower(trim($this->email)),
                $this->telefono,
                $this->matricula_profesional,
                $password_hash,
                $this->nivel ?? 'user',
                $this->padre_id ?? null,
                $this->especialidad ?? null,
                json_encode($permisos)
            ])) {
                return ['success' => true, 'message' => 'Usuario registrado exitosamente'];
            }
            
            return ['success' => false, 'message' => 'Error al registrar usuario'];
            
        } catch (Exception $e) {
            error_log("Error en registro: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno del servidor'];
        }
    }
    
    /**
     * Obtener permisos por defecto según nivel
     */
    private function getDefaultPermissions($nivel) {
        switch ($nivel) {
            case 'root':
                return ['all'];
            case 'admin':
                return ['dashboard', 'estudios', 'pacs_query', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'];
            case 'user':
                return ['dashboard', 'informes', 'grabacion'];
            default:
                return ['dashboard'];
        }
    }
    
    /**
     * Verificar si email existe
     */
    private function emailExists() {
        $query = "SELECT id FROM " . $this->table_name . " WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([strtolower(trim($this->email))]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Verificar si matrícula existe
     */
    private function matriculaExists() {
        $query = "SELECT id FROM " . $this->table_name . " WHERE matricula_profesional = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$this->matricula_profesional]);
        return $stmt->rowCount() > 0;
    }
}
?>