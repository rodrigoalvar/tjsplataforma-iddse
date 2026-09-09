<?php
/**
 * Cliente FTP para envío de archivos a servidor FTP
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * @package    PORTAL_ESTUDIOS
 * @subpackage Classes
 * @author     Sistema TJSMEDICAL
 * @version    1.0.0
 */

class FtpClient {
    
    /** @var resource|null Conexión FTP */
    private $connection = null;
    
    /** @var array Configuración FTP */
    private $config = [];
    
    /** @var bool Estado de conexión */
    private $isConnected = false;
    
    /**
     * Constructor
     * 
     * @param array $config Configuración FTP:
     *   - host: Servidor FTP
     *   - port: Puerto (default: 21)
     *   - username: Usuario
     *   - password: Contraseña
     *   - remote_path: Ruta remota
     *   - passive_mode: Modo pasivo (default: true)
     *   - timeout: Timeout en segundos (default: 30)
     */
    public function __construct($config) {
        $this->config = array_merge([
            'host' => '',
            'port' => 21,
            'username' => '',
            'password' => '',
            'remote_path' => '/',
            'passive_mode' => true,
            'timeout' => 30
        ], $config);
        
        // Validar configuración requerida
        if (empty($this->config['host'])) {
            throw new Exception('Host FTP no especificado');
        }
        if (empty($this->config['username'])) {
            throw new Exception('Usuario FTP no especificado');
        }
    }
    
    /**
     * Conectar al servidor FTP
     * 
     * @return bool True si la conexión fue exitosa
     * @throws Exception Si hay error al conectar
     */
    public function connect() {
        if ($this->isConnected) {
            return true;
        }
        
        // Verificar que la extensión FTP esté disponible
        if (!function_exists('ftp_connect')) {
            throw new Exception('Extensión FTP de PHP no está disponible. Instale php-ftp');
        }
        
        // Conectar al servidor
        $this->connection = @ftp_connect($this->config['host'], $this->config['port'], $this->config['timeout']);
        
        if (!$this->connection) {
            throw new Exception('No se pudo conectar al servidor FTP: ' . $this->config['host'] . ':' . $this->config['port']);
        }
        
        // Iniciar sesión
        $login = @ftp_login($this->connection, $this->config['username'], $this->config['password']);
        
        if (!$login) {
            $this->disconnect();
            throw new Exception('Error al autenticarse en el servidor FTP. Verifique usuario y contraseña.');
        }
        
        // Configurar modo pasivo si está habilitado
        if ($this->config['passive_mode']) {
            @ftp_pasv($this->connection, true);
        }
        
        $this->isConnected = true;
        return true;
    }
    
    /**
     * Desconectar del servidor FTP
     */
    public function disconnect() {
        if ($this->connection && $this->isConnected) {
            @ftp_close($this->connection);
            $this->connection = null;
            $this->isConnected = false;
        }
    }
    
    /**
     * Subir archivo al servidor FTP
     * 
     * @param string $localPath Ruta local del archivo
     * @param string $remoteFileName Nombre del archivo remoto
     * @return bool True si el envío fue exitoso
     * @throws Exception Si hay error al subir
     */
    public function uploadFile($localPath, $remoteFileName) {
        if (!$this->isConnected) {
            $this->connect();
        }
        
        if (!file_exists($localPath)) {
            throw new Exception('El archivo local no existe: ' . $localPath);
        }
        
        // Normalizar ruta remota
        $remotePath = rtrim($this->config['remote_path'], '/') . '/';
        $remoteFilePath = $remotePath . $remoteFileName;
        
        // Asegurar que el directorio remoto existe
        $this->ensureRemoteDirectory($remotePath);
        
        // Subir archivo (modo binario para archivos de audio)
        $upload = @ftp_put($this->connection, $remoteFilePath, $localPath, FTP_BINARY);
        
        if (!$upload) {
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Error desconocido al subir archivo';
            throw new Exception('Error al subir archivo a FTP: ' . $errorMsg);
        }
        
        return true;
    }
    
    /**
     * Asegurar que el directorio remoto existe
     * 
     * @param string $remotePath Ruta remota
     * @throws Exception Si no se puede crear el directorio
     */
    private function ensureRemoteDirectory($remotePath) {
        // Normalizar ruta (eliminar barra inicial si existe)
        $path = ltrim($remotePath, '/');
        $parts = explode('/', $path);
        
        $currentPath = '';
        foreach ($parts as $part) {
            if (empty($part)) continue;
            
            $currentPath .= '/' . $part;
            
            // Intentar cambiar al directorio
            if (@ftp_chdir($this->connection, $currentPath) === false) {
                // Si no existe, crearlo
                if (@ftp_mkdir($this->connection, $currentPath) === false) {
                    throw new Exception('No se pudo crear el directorio remoto: ' . $currentPath);
                }
                // Cambiar al directorio recién creado
                @ftp_chdir($this->connection, $currentPath);
            }
        }
    }
    
    /**
     * Probar conexión al servidor FTP
     * 
     * @return array Resultado de la prueba
     */
    public function testConnection() {
        try {
            $this->connect();
            
            // Intentar obtener el directorio actual para verificar que la conexión funciona
            $pwd = @ftp_pwd($this->connection);
            
            if ($pwd === false) {
                throw new Exception('No se pudo obtener el directorio actual del servidor FTP');
            }
            
            $this->disconnect();
            
            return [
                'success' => true,
                'message' => 'Conexión exitosa al servidor FTP',
                'current_directory' => $pwd
            ];
        } catch (Exception $e) {
            if ($this->isConnected) {
                $this->disconnect();
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtener información del archivo remoto
     * 
     * @param string $remoteFileName Nombre del archivo remoto
     * @return array|false Información del archivo o false si no existe
     */
    public function getFileInfo($remoteFileName) {
        if (!$this->isConnected) {
            $this->connect();
        }
        
        $remotePath = rtrim($this->config['remote_path'], '/') . '/';
        $remoteFilePath = $remotePath . $remoteFileName;
        
        $size = @ftp_size($this->connection, $remoteFilePath);
        
        if ($size === -1) {
            return false; // Archivo no existe
        }
        
        return [
            'size' => $size,
            'exists' => true
        ];
    }
    
    /**
     * Verificar si está conectado
     * 
     * @return bool
     */
    public function isConnected() {
        return $this->isConnected;
    }
    
    /**
     * Destructor - asegurar que la conexión se cierre
     */
    public function __destruct() {
        $this->disconnect();
    }
}
